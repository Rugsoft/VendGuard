<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\TechnicianRefundInspectionDTO;
use VendGuard\Core\Domain\Exception\InvalidRecoveredAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\UnclaimedCashFindingRepositoryInterface;

/**
 * TechnicianRefundService
 *
 * Processes the on-site verdict the field technician files while resolving an
 * incident that carries a consumer refund claim, and registers cash recovered
 * by the technician when no consumer ever claimed it (RF-REF-04).
 *
 * ## Why the verdict is mandatory
 * Deciding what happened to the money is not paperwork attached to a repair: it
 * is the only record that decides who gets money back. A case that leaves the
 * field without a verdict would sit in `PENDING_INSPECTION` forever while the
 * machine is already back in service, so the verdict is required whenever a
 * claim is attached to the incident.
 *
 * ## Custody: reject, then force
 * The specification asks for two things that read as contradictory: "the system
 * must NOT let the technician choose the reception desk" and "it must fix custody
 * to the central safe". They are resolved by where the disagreement comes from:
 *
 *  - If the technician EXPLICITLY selects `LEFT_AT_RECEPTION` for a digital
 *    channel or for more than 10,00 €, the attempt is refused with
 *    `ReceptionDeliveryNotAllowedException`. Silently rewriting the technician's
 *    choice would leave an audit trail that says they decided something they did
 *    not decide, which is exactly what Art. III.3 forbids.
 *  - If the technician EXPLICITLY selects `LEFT_AT_RECEPTION` at a site that
 *    does not declare a physical reception, the attempt is refused for the
 *    same reason: the case would sit in `DEPOSITED_AT_RECEPTION` with an
 *    envelope nobody is able to take in.
 *  - If the technician leaves the custody unset, the rules decide it, and the
 *    central safe is forced for digital channels and elevated amounts.
 *
 * ## Multiple claims (RF-REF-08)
 * One machine can have several claimants but only one pile of coins in the coin
 * hopper. When the recovered cash does not cover the sum claimed, every case is
 * escalated to `REQUIRES_COORDINATOR_APPROVAL` and the returned discrepancy is
 * reported to the caller. Nothing is auto-distributed: deciding who receives
 * what is an administrative act reserved to Coordination.
 *
 * No row is ever deleted here; cases only change state and every verdict is
 * appended to the audit log (Art. III).
 */
final class TechnicianRefundService
{
    /** Cash above this amount may never stay at the reception desk (RF-REF-05). */
    public const RECEPTION_DELIVERY_LIMIT = RefundRequest::RECEPTION_DELIVERY_LIMIT;

    private RefundRequestRepositoryInterface $refundRepo;
    private UnclaimedCashFindingRepositoryInterface $findingRepo;
    private RefundManagementService $managementService;
    private AuditLogger $auditLogger;

    /**
     * Optional on purpose: the reception rule is only enforceable when somebody
     * can answer "does this site have a desk?". Callers that cannot (the
     * in-memory suites) keep the previous behaviour instead of failing to build.
     */
    private ?LocationRepositoryInterface $locationRepo;

    public function __construct(
        RefundRequestRepositoryInterface $refundRepo,
        UnclaimedCashFindingRepositoryInterface $findingRepo,
        RefundManagementService $managementService,
        ?AuditLogger $auditLogger = null,
        ?LocationRepositoryInterface $locationRepo = null
    ) {
        $this->refundRepo = $refundRepo;
        $this->findingRepo = $findingRepo;
        $this->managementService = $managementService;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->locationRepo = $locationRepo;
    }

    /**
     * Files the technician verdict against every claim attached to an incident.
     *
     * An incident with no claim is not an error: RF-REF-04 only makes the
     * verdict mandatory when a refund request exists, and the on-site cash
     * finding is an independent, optional act handled by
     * `registerUnclaimedCash()`.
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor Field technician.
     * @return array{processed: int, recovered_total: float, claimed_total: float,
     *   discrepancy: bool, statuses: list<string>}
     * @throws ReceptionDeliveryNotAllowedException When the technician tries to
     *   leave digital or elevated cash at the reception desk.
     * @throws JustificationTooShortException When an `UNVERIFIED_NO_CASH` verdict
     *   arrives without a written justification of at least 20 characters.
     * @throws RefundNotFoundException When a referenced case has been archived.
     */
    public function inspectBalance(
        int $incidentId,
        TechnicianRefundInspectionDTO $dto,
        int $machineId,
        array $actor = []
    ): array {
        $this->assertVerdictIsJustified($dto);

        $claims = $this->refundRepo->findRestrictedByIncident($incidentId);
        if ($claims === []) {
            return [
                'processed' => 0,
                'recovered_total' => 0.0,
                'claimed_total' => 0.0,
                'discrepancy' => false,
                'statuses' => [],
            ];
        }

        $recovered = $dto->recoveredAmount ?? 0.0;
        $claimedTotal = array_sum(array_map(
            static fn (RefundRequest $case): float => $case->getClaimedAmount(),
            $claims
        ));

        // RF-REF-03/04: a single pile of coins cannot cover more than was claimed,
        // and recovering far MORE than was owed is not a bigger reimbursement,
        // it is a different fact. `recovered_amount` reconciles the claims of
        // THIS incident, so anything above their sum is surplus, and surplus has
        // its own path: `registerUnclaimedCash()`. Accepting it here persisted a
        // figure that simply could not be true (999,00 € recovered against a 2,00 €
        // claim) straight into the cash reconciliation and the audit trail.
        if ($recovered > $claimedTotal) {
            throw new InvalidRecoveredAmountException(
                attemptedAmount: $recovered,
                maximumAllowed: $claimedTotal,
                incidentId: $incidentId
            );
        }

        // RF-REF-08: a single pile of coins cannot satisfy several claimants.
        // The shortfall escalates every case instead of splitting the cash.
        $shortfall = $claims === [] ? false : ($recovered < $claimedTotal);

        $statuses = [];
        foreach ($claims as $case) {
            $statuses[] = $this->applyVerdict($case, $dto, $recovered, $shortfall, $machineId, $actor)->value;
        }

        return [
            'processed' => count($claims),
            'recovered_total' => $recovered,
            'claimed_total' => $claimedTotal,
            'discrepancy' => $shortfall,
            'statuses' => $statuses,
        ];
    }

    /**
     * Registers cash recovered by the technician with no prior consumer claim
     * (RF-REF-04).
     *
     * The money is unclaimed surplus destined for the central safe, so it is
     * only ever recorded, never attached to a refund case.
     *
     * The amount is bounded by the same 50,00 EUR block the consumer claims use,
     * and for the same reason: a single till deposit that outgrows the antifraud
     * ceiling is not a till deposit, and with `DECIMAL(10,2)` and no strict
     * `sql_mode` the database would have stored whatever it was handed.
     *
     * ## Why the ceiling is per MACHINE and not per finding
     * Bounding each finding at 50,00 EUR bounds nothing in practice: reopening an
     * incident and attending it again files a second finding for the same machine
     * and the ceiling starts over. Two findings of 50,00 EUR were measured on one
     * machine, and nothing stopped a third. The old error message even advised
     * dividing the amount, which is the multiplication spelled out. The aggregate
     * is therefore counted over the machine, using the same figure the module
     * already uses for money, so no new constant is invented for it.
     *
     * ## Why reconciled claims close the door
     * The money a technician reconciles against a live claim and the money they
     * book as surplus are the SAME coins, and `resolve` accepts both blocks in a
     * single request. One call therefore left 8,00 EUR reconciled against a claim
     * AND 8,00 EUR booked as surplus, and the coordinator then paid the claim:
     * the same euro twice in the books. Once a claim of the incident has been
     * reconciled, that cash already has an owner and cannot be surplus as well.
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @throws InvalidRecoveredAmountException When the amount is out of range, or
     *   the money already has an owner, or the machine's aggregate is exhausted.
     */
    public function registerUnclaimedCash(
        int $incidentId,
        int $machineId,
        int $technicianId,
        float $amount,
        string $notes = '',
        array $actor = []
    ): UnclaimedCashFinding {
        if (!is_finite($amount) || $amount <= 0.0) {
            throw new InvalidRecoveredAmountException(
                attemptedAmount: $amount,
                maximumAllowed: RefundRequest::MAX_CLAIMED_AMOUNT
            );
        }

        if ($amount > RefundRequest::MAX_CLAIMED_AMOUNT) {
            throw new InvalidRecoveredAmountException(
                attemptedAmount: $amount,
                maximumAllowed: RefundRequest::MAX_CLAIMED_AMOUNT,
                incidentId: $incidentId,
                message: 'El efectivo no reclamado no puede superar el tope máximo de 50,00 € por hallazgo.'
            );
        }

        // El dinero conciliado contra una reclamación ya tiene dueño. Abonarlo
        // además como sobrante es contar dos veces las mismas monedas, y el
        // `resolve` del técnico acepta ambos bloques en la misma llamada.
        //
        // El bloqueo mira el dictamen, no el estado: una reclamación que el
        // consumidor cerró sin que ningún técnico la inspeccionase no consumió
        // ningún euro, así que no genera doble asiento y no debe impedir
        // declarar el sobrante. En cambio, en cuanto existe un dictamen el
        // dinero de esa máquina ya está adjudicado, hayaseit para el
        // consumidor o para nadie, y quien lo reclame a partir de ahí es
        // Coordinación, no el técnico de campo.
        //
        // `findRestrictedByIncident()` es la proyección restringida: para decidir
        // esto solo hacen falta el estado y el dictamen, y así el dinero del
        // consumidor ni siquiera se carga en memoria aquí.
        foreach ($this->refundRepo->findRestrictedByIncident($incidentId) as $case) {
            if (!$case->awaitsInspection() && $case->getTechnicianFinding() !== null) {
                throw new InvalidRecoveredAmountException(
                    attemptedAmount: $amount,
                    maximumAllowed: 0.0,
                    incidentId: $incidentId,
                    message: 'Esta avería tiene una reclamación de reintegro ya inspeccionada por el técnico: su efectivo está adjudicado y no puede abonarse además como efectivo no reclamado. Si el sobrante es real, debe abrirlo Coordinación.'
                );
            }
        }

        // El tope es por MÁQUINA, no por hallazgo (ver el docblock), y se cuenta en
        // CÉNTIMOS. Restar euros en coma flotante no es restar dinero: con 49,99
        // registrados el hueco salía en 0,00999999999999801 € y un hallazgo de
        // 0,01 € que cierra el agregado en 50,00 € exactos se rechazaba. Un techo
        // que no alcanza su propio límite no es un techo.
        $alreadyBooked = $this->findingRepo->sumAmountByMachine($machineId);
        $machineCeilingCents = self::toCents(RefundRequest::MAX_CLAIMED_AMOUNT) - self::toCents($alreadyBooked);

        if (self::toCents($amount) > $machineCeilingCents) {
            throw new InvalidRecoveredAmountException(
                attemptedAmount: $amount,
                maximumAllowed: max(0.0, $machineCeilingCents / 100),
                incidentId: $incidentId,
                message: sprintf(
                    'Esta máquina ya tiene %.2f € registrados como efectivo no reclamado y el tope por máquina es de %.2f €.',
                    $alreadyBooked,
                    RefundRequest::MAX_CLAIMED_AMOUNT
                )
            );
        }

        $finding = new UnclaimedCashFinding(
            id: null,
            incidentId: $incidentId,
            machineId: $machineId,
            technicianId: $technicianId,
            amount: $amount,
            notes: trim($notes),
            createdAt: date('Y-m-d H:i:s')
        );

        $findingId = $this->findingRepo->insert($finding);

        $this->auditLogger->logUnclaimedCashEvent(
            $findingId,
            'UNCLAIMED_CASH_RECORDED',
            [
                'id' => $actor['id'] ?? $technicianId,
                'role' => $actor['role'] ?? 'TECHNICIAN',
                'name' => $actor['name'] ?? 'Técnico de campo',
            ],
            [
                'amount' => $amount,
                'incident_id' => $incidentId,
                'machine_id' => $machineId,
            ]
        );

        // `UnclaimedCashFinding` es inmutable y se construyó con id nulo, porque
        // el identificador sólo existe tras el INSERT. Se recarga para devolver
        // el hallazgo persistido.
        return $this->findingRepo->findById($findingId) ?? $finding;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convierte euros a céntimos enteros sin perder el último.
     *
     * Todo importe que llega por HTTP pasa por `isCentExact()` en el servicio de
     * gestión, así que llegar aquí con más de dos decimales ya es un contractual
     * incumplido; `round()` sólo protege el agregado, que se calcula restando
     * dos decimales que NO han pasado por ese filtro.
     */
    private static function toCents(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * `UNVERIFIED_NO_CASH` is the verdict that most deserves a second look, so
     * the specification demands a written explanation for it (RF-REF-04).
     *
     * @throws JustificationTooShortException
     */
    private function assertVerdictIsJustified(TechnicianRefundInspectionDTO $dto): void
    {
        if ($dto->finding !== TechnicianFinding::UNVERIFIED_NO_CASH) {
            return;
        }

        $justification = $dto->justificationOrNull() ?? '';
        if (mb_strlen($justification) < JustificationTooShortException::MINIMUM_LENGTH) {
            throw new JustificationTooShortException($justification);
        }
    }

    /**
     * Applies one verdict to one case and returns the state it landed in.
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @throws ReceptionDeliveryNotAllowedException
     * @throws RefundNotFoundException
     */
    private function applyVerdict(
        RefundRequest $case,
        TechnicianRefundInspectionDTO $dto,
        float $recovered,
        bool $shortfall,
        int $machineId,
        array $actor
    ): RefundStatus {
        $custody = $this->resolveCustody($case, $dto, $recovered);

        $inspected = $case->withInspection(
            $dto->finding,
            $recovered,
            $custody,
            $dto->receptionistNameOrNull(),
            $dto->justificationOrNull()
        );

        // RF-REF-08 short-circuits the per-case classification: every claimant
        // goes to Coordination when the hopper cannot cover all of them.
        $target = $shortfall
            ? RefundStatus::REQUIRES_COORDINATOR_APPROVAL
            : $this->managementService->classifyInspectionOutcome($inspected);

        $fields = [
            'technician_finding' => $dto->finding->value,
            'recovered_amount' => $recovered,
            'cash_custody_action' => $custody?->value,
            'receptionist_name' => $dto->receptionistNameOrNull(),
            'technician_justification' => $dto->justificationOrNull(),
            'technician_id' => $actor['id'] ?? null,
            'technician_inspected_at' => date('Y-m-d H:i:s'),
        ];

        $this->managementService->assertTransitionAllowed($case->getStatus(), $target, 'INSPECT_INCIDENT');

        if (!$this->refundRepo->transitionStatus(
            (int)$case->getId(),
            $case->getStatus(),
            $target,
            $fields
        )) {
            // Otra petición se adelantó antes; se informa como conflicto
            // en lugar de pisar el resultado de la vencedora.
            throw new InvalidRefundStateTransitionException(
                fromStatus: $case->getStatus(),
                toStatus: $target,
                attemptedAction: 'INSPECT_INCIDENT'
            );
        }

        $this->auditLogger->logRefundEvent(
            (int)$case->getId(),
            'REFUND_INSPECTED',
            [
                'id' => $actor['id'] ?? null,
                'role' => $actor['role'] ?? 'TECHNICIAN',
                'name' => $actor['name'] ?? 'Técnico de campo',
            ],
            ['status' => $case->getStatus()->value],
            [
                'status' => $target->value,
                'technician_finding' => $dto->finding->value,
                'recovered_amount' => $recovered,
                'cash_custody_action' => $custody?->value,
            ],
            ['incident_id' => $case->getIncidentId(), 'machine_id' => $machineId]
        );

        return $target;
    }

    /**
     * Decides where the recovered cash physically goes.
     *
     * An explicit `LEFT_AT_RECEPTION` for a digital channel or an amount above
     * the limit is refused rather than silently rewritten; an unset custody is
     * decided by the rules, which force the central safe in both cases.
     *
     * @throws ReceptionDeliveryNotAllowedException
     */
    private function resolveCustody(
        RefundRequest $case,
        TechnicianRefundInspectionDTO $dto,
        float $recovered
    ): ?CashCustodyAction {
        $mustGoCentral = $case->getCompensationMethod()->isDigital()
            || $recovered > self::RECEPTION_DELIVERY_LIMIT;

        if ($dto->cashCustodyAction === CashCustodyAction::LEFT_AT_RECEPTION) {
            if ($mustGoCentral) {
                throw new ReceptionDeliveryNotAllowedException(
                    claimedAmount: $case->getClaimedAmount(),
                    compensationMethod: $case->getCompensationMethod(),
                    receptionLimit: self::RECEPTION_DELIVERY_LIMIT
                );
            }

            $this->assertSiteCanReceiveEnvelope($case);

            return CashCustodyAction::LEFT_AT_RECEPTION;
        }

        if ($dto->finding->recoveredCash()) {
            return $mustGoCentral
                ? CashCustodyAction::HELD_FOR_CENTRAL
                : ($dto->cashCustodyAction ?? CashCustodyAction::HELD_FOR_CENTRAL);
        }

        return $dto->cashCustodyAction;
    }

    /**
     * Refuses a reception deposit at a site with no reception desk (RF-REF-05).
     *
     * `locations.has_physical_reception` used to be decorative: only the public
     * QR report ever read it, so a technician could leave an envelope at a site
     * that cannot take one and the case would wait forever in
     * `DEPOSITED_AT_RECEPTION` with nobody holding the money. The flag is the
     * site telling us it cannot receive cash, and the module ignored it.
     *
     * Two situations deliberately do NOT block, and both are documented rather
     * than hidden: no repository was injected (an in-memory caller cannot know
     * the sites), and the site row cannot be loaded (a missing location is a
     * data integrity fault behind a foreign key, not a reception decision).
     *
     * @throws ReceptionDeliveryNotAllowedException
     */
    private function assertSiteCanReceiveEnvelope(RefundRequest $case): void
    {
        if ($this->locationRepo === null) {
            return;
        }

        $location = $this->locationRepo->findById($case->getLocationId());
        if ($location === null || $location->hasPhysicalReception()) {
            return;
        }

        throw new ReceptionDeliveryNotAllowedException(
            claimedAmount: $case->getClaimedAmount(),
            compensationMethod: $case->getCompensationMethod(),
            receptionLimit: self::RECEPTION_DELIVERY_LIMIT,
            message: 'La sede asociada no dispone de conserjería física: el efectivo debe custodiarse en caja central (HELD_FOR_CENTRAL).'
        );
    }
}