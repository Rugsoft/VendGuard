<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use VendGuard\Application\DTO\CoordinatorIncidentDetailDto;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * Assembles the coordinator's integral incident detail (module 09).
 *
 * This service is the only place where the raw rows of `findEnrichedDetailById()`
 * become the ten blocks of the modal contract (plan §2.1). It owns three rules:
 *
 * - The cold-chain SLA (RF-03, Art. II): a live countdown while the ticket is
 *   active on a perishable-food machine, the same countdown **frozen at the pause
 *   instant** while the ticket waits for the client site (RF-03.2), and the formal
 *   historical balance once it is resolved, closed or discarded.
 * - The contractual pause block (module 11, RF-03.2 and RF-04.2): the frozen
 *   deadline, the recalculated one after shifting the accumulated pause in business
 *   hours, the prolonged-wait flag beyond 72 business hours and the two supervised
 *   actions (resume the intervention, cancel by inactivity).
 * - Server-side masking of refund contact and payment data (RF-06, Art. V.4): the
 *   full phone and IBAN never leave this layer.
 * - The action matrix of the incident state machine (RF-07), including the
 *   48-hour comment window on resolved tickets.
 *
 * It performs no I/O beyond the repository read and writes nothing: viewing a case
 * must never mutate it (Art. III, RNF-04).
 */
final class CoordinatorIncidentDetailService
{
    /** Cold-chain SLA for perishable-food machines (Art. II, RF-03.2). */
    private const COLD_CHAIN_SLA_HOURS = 4;

    /** Comment window on a resolved ticket (Art. V.6, RF-07.2). */
    private const COMMENT_GUARANTEE_HOURS = 48;

    /**
     * States in which the ticket is still part of the active operational flow.
     *
     * The functional spec names the first one REPORTED; the implementation calls it
     * REGISTERED, and REOPENED re-enters the flow after a warranty reopening. *   PENDING_INFO belongs here too: the pause does not close the ticket (the engine
     * keeps `is_active_ticket = 1`), so cancelling after the 72-hour silence (RF-04.3)
     * and replying from the site portal (RF-05.1) must remain available.
     */
    private const ACTIVE_STATUSES = [
        IncidentStatus::REGISTERED,
        IncidentStatus::REOPENED,
        IncidentStatus::ASSIGNED,
        IncidentStatus::IN_PROGRESS,
        IncidentStatus::PENDING_PARTS,
        IncidentStatus::PENDING_INFO,
    ];

    /** States whose lifecycle closes with a formal historical SLA balance. */
    private const SETTLED_STATUSES = [
        IncidentStatus::RESOLVED,
        IncidentStatus::CLOSED,
        IncidentStatus::CANCELLED,
    ];

    /**
     * Canonical markers of the immutable reassignment note written by the repository
     * (`PdoIncidentRepository::assign()`, RF-07.3). The justified motive always closes
     * the note, so everything after the marker is the motive the modal must display.
     */
    private const REASSIGNMENT_NOTE_MARKER = 'Reasignación técnica:';
    private const REASSIGNMENT_MOTIVE_MARKER = ' Motivo: ';

    /** States that show the documented technical resolution block (RF-04.3). */
    private const RESOLUTION_STATUSES = [
        IncidentStatus::RESOLVED,
        IncidentStatus::CLOSED,
    ];

    /**
     * Servicio del ciclo de pausa (módulo 11), resuelto de forma perezosa.
     *
     * Se inyecta desde el controlador y, si no llega, se construye sin colaboradores
     * de persistencia: el servicio de pausa admite esa forma reducida y aquí sólo se
     * usan sus dos reglas puras (umbral de 72 h hábiles y desplazamiento comercial de
     * SLA), que no tocan la base de datos.
     */
    private ?IncidentPauseService $pauseService = null;

    public function __construct(
        private readonly IncidentRepositoryInterface $incidentRepo,
        ?IncidentPauseService $pauseService = null
    ) {
        $this->pauseService = $pauseService;
    }

    /**
     * Calculadora de calendario del módulo 11: umbral de 72 h hábiles y desplazamiento
     * comercial del vencimiento contractual.
     */
    private function pauses(): IncidentPauseService
    {
        return $this->pauseService ??= new IncidentPauseService();
    }

    /**
     * Builds the full detail projection for one incident, or null when it does not exist.
     *
     * The optional `$now` exists so the SLA countdown, the permissions matrix and the
     * 48-hour comment window are deterministic in tests.
     */
    public function buildDetail(int|string $identifier, ?DateTimeImmutable $now = null): ?CoordinatorIncidentDetailDto
    {
        $raw = $this->incidentRepo->findEnrichedDetailById($identifier);
        if ($raw === null) {
            return null;
        }

        $now ??= new DateTimeImmutable('now');
        $incident = $raw['incident'];
        $machine = $raw['machine'];
        $history = $raw['history'];
        $timeline = $this->buildTimelineBlock($incident, $history, $now);
        // El bloque de SLA se deriva antes de la intervención técnica porque la pausa
        // contractual publica su vencimiento congelado y el recalculado (RF-03.2).
        $sla = $this->computeSlaStatus($incident, $machine, $timeline, $now);

        return new CoordinatorIncidentDetailDto(
            incident: $this->buildIncidentBlock($incident, $history),
            location: $this->buildLocationBlock($incident, $raw['location'], $machine),
            machine: $this->buildMachineBlock($incident, $machine),
            technician: $this->buildTechnicianBlock($incident, $raw['technician'], $history),
            timeline: $timeline,
            sla: $sla,
            technicalIntervention: $this->buildTechnicalInterventionBlock(
                $incident,
                $raw['requested_parts'],
                $raw['replaced_parts'],
                $history,
                $sla,
                $now
            ),
            comments: $this->buildCommentsBlock($raw['comments']),
            refund: $this->buildRefundBlock($raw['refund']),
            permissions: $this->computePermissions($incident, $now)
        );
    }

    /**
     * Cold-chain SLA evaluation of one incident (plan §3.1, RF-03).
     *
     * Perishable-food machines carry the mandatory 4-hour objective; any other machine
     * publishes `has_sla_limit: false` and the frontend hides the monitor. Settled
     * tickets get the immutable formal balance instead of a countdown.
     *
     * La pausa contractual (RF-03.2) no detiene la lectura: la **congela**. Un expediente
     * en `PENDING_INFO` con pausa viva publica `is_frozen: true`, el instante congelado en
     * `frozen_at` y la cuenta atrás tal y como estaba al pausar, para que el monitor deje de
     * consumir el objetivo de cadena de frío mientras la culpa es de la sede.
     *
     * @param array<string, mixed> $incident Raw incident row.
     * @param array<string, mixed>|null $machine Raw machine row.
     * @param array<string, mixed> $timeline Timeline block previously derived from the incident.
     * @return array<string, mixed>
     */
    public function computeSlaStatus(
        array $incident,
        ?array $machine,
        array $timeline,
        ?DateTimeImmutable $now = null
    ): array {
        if ($this->resolveMachineType($incident, $machine) !== MachineType::PERISHABLE_FOOD) {
            return ['has_sla_limit' => false];
        }

        $createdAt = $this->parseDateTime($timeline['created_at'] ?? $incident['created_at'] ?? null);
        if ($createdAt === null) {
            return ['has_sla_limit' => false];
        }

        $now ??= new DateTimeImmutable('now');
        $targetAt = $createdAt->modify('+' . self::COLD_CHAIN_SLA_HOURS . ' hours');
        $status = IncidentStatus::tryFrom((string)($incident['status'] ?? ''));

        if ($status !== null && in_array($status, self::SETTLED_STATUSES, true)) {
            $effectiveEnd = $this->parseDateTime($timeline['resolved_at'] ?? null)
                ?? $this->parseDateTime($incident['resolved_at'] ?? null)
                ?? $this->parseDateTime($incident['closed_at'] ?? null)
                ?? $this->parseDateTime($incident['updated_at'] ?? null)
                ?? $now;

            $secondsToTarget = $effectiveEnd->getTimestamp() - $targetAt->getTimestamp();
            $elapsedMinutes = (int)round(($effectiveEnd->getTimestamp() - $createdAt->getTimestamp()) / 60);

            if ($secondsToTarget <= 0) {
                $balanceText = 'Cumplido en ' . $this->formatHoursMinutes($elapsedMinutes);
                $isBreached = false;
            } else {
                $balanceText = 'Incumplido por ' . $this->formatHoursMinutes((int)round($secondsToTarget / 60));
                $isBreached = true;
            }

            return [
                'has_sla_limit' => true,
                'sla_limit_hours' => (float)self::COLD_CHAIN_SLA_HOURS,
                'is_active_countdown' => false,
                'is_frozen' => false,
                'frozen_at' => null,
                'is_breached' => $isBreached,
                'minutes_remaining' => 0,
                'historical_balance' => $balanceText,
                'sla_target_at' => $targetAt->format('Y-m-d H:i:s'),
            ];
        }

        // Doble reloj (RF-03.2): mientras el expediente espera a la sede, el reloj
        // contractual se congela en el instante de la pausa y no sigue consumiendo el
        // objetivo de cadena de frío. El reloj biológico sanitario es otra lectura.
        $isSlaPaused = $status === IncidentStatus::PENDING_INFO
            && $this->parseDateTime($incident['paused_at'] ?? null) !== null;
        $frozenAt = $isSlaPaused ? $this->parseDateTime($incident['paused_at'] ?? null) : null;
        $evaluationInstant = $frozenAt ?? $now;

        $secondsToTarget = $targetAt->getTimestamp() - $evaluationInstant->getTimestamp();
        $remainingMinutes = (int)round($secondsToTarget / 60);

        if ($secondsToTarget >= 0) {
            $balanceText = 'Tiempo restante: ' . $this->formatHoursMinutes($remainingMinutes);
            $isBreached = false;
        } else {
            $balanceText = 'SLA superado hace ' . $this->formatHoursMinutes(abs($remainingMinutes));
            $isBreached = true;
        }

        return [
            'has_sla_limit' => true,
            'sla_limit_hours' => (float)self::COLD_CHAIN_SLA_HOURS,
            'is_active_countdown' => true,
            'is_frozen' => $isSlaPaused,
            'frozen_at' => $frozenAt?->format('Y-m-d H:i:s'),
            'is_breached' => $isBreached,
            'minutes_remaining' => $remainingMinutes,
            'historical_balance' => $balanceText,
            'sla_target_at' => $targetAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Direct actions allowed by the state machine of the ticket (RF-07).
     *
     * Active tickets can be assigned, reassigned and discarded; settled ones switch the
     * modal into consultation mode. The only exception is the addition of comments on a
     * RESOLVED ticket during its 48-hour warranty window (Art. V.6); CLOSED and
     * CANCELLED tickets are sealed for good.
     *
     * @param array<string, mixed> $incident Raw incident row.
     * @return array{can_assign: bool, can_reassign: bool, can_cancel: bool, can_add_comment: bool}
     */
    public function computePermissions(array $incident, ?DateTimeImmutable $now = null): array
    {
        $status = IncidentStatus::tryFrom((string)($incident['status'] ?? ''));
        if ($status === null) {
            return [
                'can_assign' => false,
                'can_reassign' => false,
                'can_cancel' => false,
                'can_add_comment' => false,
            ];
        }

        $now ??= new DateTimeImmutable('now');
        $isActive = in_array($status, self::ACTIVE_STATUSES, true);

        return [
            'can_assign' => $status === IncidentStatus::REGISTERED || $status === IncidentStatus::REOPENED,
            'can_reassign' => in_array(
                $status,
                [IncidentStatus::ASSIGNED, IncidentStatus::IN_PROGRESS, IncidentStatus::PENDING_PARTS],
                true
            ),
            'can_cancel' => $isActive,
            'can_add_comment' => $isActive || $this->isWithinCommentWindow($status, $incident, $now),
        ];
    }

    /**
     * Masks the contact and payment data of a refund case (plan §3.2, Art. V.4).
     *
     * The full Bizum phone and the IBAN are never part of the modal payload: the phone
     * keeps its first digit and last three, and the IBAN keeps the country and last four
     * characters. The claim code is derived for display because the schema does not store
     * one (RF-06.1).
     *
     * @param array<string, mixed>|null $refundRecord Raw refund row, or null when the incident has none.
     * @return array<string, mixed>|null
     */
    public function maskFinancialAndContactData(?array $refundRecord): ?array
    {
        if ($refundRecord === null) {
            return null;
        }

        return [
            'claim_code' => $this->deriveClaimCode($refundRecord),
            'amount' => isset($refundRecord['claimed_amount']) ? (float)$refundRecord['claimed_amount'] : null,
            'compensation_method' => isset($refundRecord['compensation_method'])
                ? (string)$refundRecord['compensation_method']
                : null,
            'contact_phone_masked' => $this->maskPhone(
                isset($refundRecord['bizum_phone']) ? (string)$refundRecord['bizum_phone'] : null
            ),
            'iban_masked' => $this->maskIban(
                isset($refundRecord['iban']) ? (string)$refundRecord['iban'] : null
            ),
            'status' => isset($refundRecord['status']) ? (string)$refundRecord['status'] : null,
            'technician_finding' => isset($refundRecord['technician_finding'])
                ? (string)$refundRecord['technician_finding']
                : null,
            'cash_custody_action' => isset($refundRecord['cash_custody_action'])
                ? (string)$refundRecord['cash_custody_action']
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Block builders
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $incident
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function buildIncidentBlock(array $incident, array $history): array
    {
        $status = IncidentStatus::tryFrom((string)($incident['status'] ?? ''));
        $urgency = UrgencyLevel::tryFrom((string)($incident['urgency'] ?? ''));

        return [
            'id' => (int)($incident['id'] ?? 0),
            'ticket_code' => (string)($incident['ticket_code'] ?? ''),
            'status' => (string)($incident['status'] ?? ''),
            'status_label' => $status?->label() ?? (string)($incident['status'] ?? ''),
            'urgency' => (string)($incident['urgency'] ?? ''),
            'urgency_label' => $urgency?->label() ?? (string)($incident['urgency'] ?? ''),
            'is_reopened' => ($incident['reopened_at'] ?? null) !== null,
            'reopened_at' => $incident['reopened_at'] ?? null,
            'reopened_reason' => $incident['reopen_reason'] ?? null,
            'description' => (string)($incident['description'] ?? ''),
            'report_channel' => $this->detectReportChannel($history),
            'photo_url' => $incident['photo_path'] ?? null,
            'created_at' => (string)($incident['created_at'] ?? ''),
            'updated_at' => (string)($incident['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed>|null $location
     * @param array<string, mixed>|null $machine
     * @return array<string, mixed>
     */
    private function buildLocationBlock(array $incident, ?array $location, ?array $machine): array
    {
        return [
            'id' => (int)($location['id'] ?? $incident['location_id'] ?? 0),
            'name' => (string)($location['name'] ?? ''),
            'code' => (string)($location['site_code'] ?? ''),
            'address' => $location['address'] ?? null,
            // The site table has no zone column: the physical location of the machine
            // (plant, wing, room) is its `floor_wing`.
            'floor_zone' => $machine['floor_wing'] ?? null,
            'has_physical_reception' => (bool)($location['has_physical_reception'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed>|null $machine
     * @return array<string, mixed>
     */
    private function buildMachineBlock(array $incident, ?array $machine): array
    {
        $machineType = $this->resolveMachineType($incident, $machine);

        return [
            'id' => (int)($machine['id'] ?? $incident['machine_id'] ?? 0),
            'code' => (string)($machine['code'] ?? ''),
            'model' => (string)($machine['model'] ?? ''),
            // The machine table does not carry a manufacturer column; the key is kept
            // (null) so the block shape stays stable until the schema grows one.
            'manufacturer' => $machine['manufacturer'] ?? null,
            'type' => $machineType?->value ?? (string)($incident['machine_type_snapshot'] ?? ''),
            'type_label' => $machineType?->label() ?? (string)($incident['machine_type_snapshot'] ?? ''),
            'has_perishables' => $machineType === MachineType::PERISHABLE_FOOD,
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed>|null $technician
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function buildTechnicianBlock(array $incident, ?array $technician, array $history): array
    {
        return [
            'assigned' => $technician !== null,
            'technician_id' => $technician !== null ? (int)($technician['id'] ?? 0) : null,
            'name' => $technician['name'] ?? null,
            'operator_code' => $technician['operator_code'] ?? null,
            'assigned_at' => $incident['assigned_at'] ?? null,
            'assigned_by_name' => $this->findLastActorName($history, IncidentStatus::ASSIGNED->value),
            // The reassignment motive (RF-07.3) lives in the immutable history written by
            // the repository; the latest reassignment wins, so the modal shows the current one.
            'reassignment_reason' => $this->findReassignmentReason($history),
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function buildTimelineBlock(array $incident, array $history, DateTimeImmutable $now): array
    {
        $createdAt = $this->parseDateTime($incident['created_at'] ?? null);
        $assignedAt = $this->parseDateTime($incident['assigned_at'] ?? null);
        $startedAt = $this->parseDateTime($incident['started_at'] ?? null);
        $resolvedAt = $this->parseDateTime($incident['resolved_at'] ?? null);
        $closedAt = $this->parseDateTime($incident['closed_at'] ?? null);
        $cancelledAt = $this->parseDateTime($incident['cancelled_at'] ?? null);

        // The pause milestone lives only in the immutable history (RF-03.1).
        $pausedAt = null;
        foreach ($history as $row) {
            if (($row['to_status'] ?? null) === IncidentStatus::PENDING_PARTS->value) {
                $pausedAt = $row['created_at'] ?? null;
            }
        }

        $effectiveEnd = $resolvedAt ?? $closedAt ?? $cancelledAt ?? $now;

        return [
            'created_at' => (string)($incident['created_at'] ?? ''),
            'assigned_at' => $incident['assigned_at'] ?? null,
            'started_at' => $incident['started_at'] ?? null,
            'paused_at' => $pausedAt,
            'resolved_at' => $incident['resolved_at'] ?? null,
            'closed_at' => $incident['closed_at'] ?? null,
            'time_to_assign_minutes' => $this->diffInMinutes($createdAt, $assignedAt),
            'time_to_first_response_minutes' => $this->diffInMinutes($createdAt, $startedAt),
            'total_elapsed_minutes' => $this->diffInMinutes($createdAt, $effectiveEnd) ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param list<array<string, mixed>> $requestedParts
     * @param list<array<string, mixed>> $replacedParts
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $sla Already derived cold-chain SLA block.
     * @return array<string, mixed>
     */
    private function buildTechnicalInterventionBlock(
        array $incident,
        array $requestedParts,
        array $replacedParts,
        array $history,
        array $sla,
        ?DateTimeImmutable $now = null
    ): array {
        $status = (string)($incident['status'] ?? '');

        return [
            'pause' => $this->buildPauseBlock($incident, $requestedParts, $sla, $now),
            'resolution' => [
                'is_resolved' => in_array(
                    IncidentStatus::tryFrom($status),
                    self::RESOLUTION_STATUSES,
                    true
                ),
                'diagnosis' => $incident['resolution_diagnosis'] ?? null,
                'corrective_action' => $incident['resolution_action'] ?? null,
                'replaced_parts_declared' => $replacedParts !== [],
                'replaced_parts' => array_map(
                    fn(array $row): array => $this->mapReplacedPart($row),
                    $replacedParts
                ),
                'total_parts_cost' => round(array_sum(array_map(
                    static fn(array $row): float => (float)($row['total_cost_snapshot'] ?? 0.0),
                    $replacedParts
                )), 2),
            ],
            'cancellation' => [
                'is_cancelled' => $status === IncidentStatus::CANCELLED->value
                    || ($incident['cancelled_at'] ?? null) !== null,
                'cancelled_at' => $incident['cancelled_at'] ?? null,
                'cancelled_by_name' => $this->findLastActorName($history, IncidentStatus::CANCELLED->value),
                'reason' => $incident['cancellation_reason'] ?? null,
            ],
        ];
    }

    /**
     * Bloque de pausa del expediente: espera de repuestos (RF-04.2) y espera de sede
     * (RF-03.1, RF-03.2, RF-04.2, RF-04.3).
     *
     * Las dos pausas detienen la operación, pero sólo la de `PENDING_INFO` congela el reloj
     * contractual: la espera de repuestos es una parada técnica imputable a la operación y
     * sigue corriendo contra el SLA de cadena de frío. El bloque publica por eso los dos
     * estados por separado (`is_paused` para la tarjeta de intervención, `is_sla_paused`
     * para el monitor) y, cuando la pausa es de sede, todo lo que la ficha necesita para
     * pintarla sin volver a preguntar al servidor: instante de inicio, minutos vivos y
     * acumulados, umbral de inactividad, vencimiento contractual congelado, vencimiento
     * recalculado en horario comercial de la sede y las dos acciones supervisadas.
     *
     * El vencimiento **congelado** es la fecha límite vigente cuando el técnico se topó con
     * la puerta cerrada; el **recalculado** proyecta ese vencimiento desplazándolo por los
     * segundos hábiles ya consumidos en pausa (Algoritmo 3), que es exactamente lo que hará
     * la reanudación real al cerrar el intervalo (RF-03.3, RNF-01).
     *
     * @param array<string, mixed> $incident Raw incident row.
     * @param list<array<string, mixed>> $requestedParts Requested spare parts of the pause.
     * @param array<string, mixed> $sla Already derived cold-chain SLA block.
     * @param DateTimeImmutable|null $now Reference instant for the live pause interval.
     * @return array<string, mixed>
     */
    private function buildPauseBlock(
        array $incident,
        array $requestedParts,
        array $sla,
        ?DateTimeImmutable $now = null
    ): array {
        $status = IncidentStatus::tryFrom((string)($incident['status'] ?? ''));
        $pausedAtRaw = $incident['paused_at'] ?? null;
        $pausedAt = $this->parseDateTime(is_string($pausedAtRaw) ? $pausedAtRaw : null);

        // Pausa contractual viva: el expediente espera a la sede y el reloj está congelado.
        $isSlaPaused = $status === IncidentStatus::PENDING_INFO && $pausedAt !== null;
        $accumulatedSeconds = max(0, (int)($incident['total_pending_info_seconds'] ?? 0));
        $liveSeconds = $isSlaPaused
            ? max(0, ($now ?? new DateTimeImmutable('now'))->getTimestamp() - $pausedAt->getTimestamp())
            : 0;

        $categoryValue = $incident['pending_info_reason_category'] ?? null;
        $category = is_string($categoryValue) && trim($categoryValue) !== ''
            ? IncidentPauseReasonCategory::tryFrom(strtoupper(trim($categoryValue)))
            : null;

        return [
            'is_paused' => $status === IncidentStatus::PENDING_PARTS || $isSlaPaused,
            'is_sla_paused' => $isSlaPaused,
            'paused_at' => $isSlaPaused ? (string)$pausedAtRaw : null,
            'paused_minutes' => $isSlaPaused ? (int)round($liveSeconds / 60) : null,
            'accumulated_pause_minutes' => (int)round($accumulatedSeconds / 60),
            'is_prolonged_inactivity' => $isSlaPaused && $this->pauses()->isProlongedInactivitySince(
                is_string($pausedAtRaw) ? $pausedAtRaw : null,
                $isSlaPaused,
                $now
            ),
            'inactivity_threshold_business_hours' => IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS,
            'sla_target_frozen_at' => $isSlaPaused ? ($sla['sla_target_at'] ?? null) : null,
            'sla_target_recalculated_at' => $this->recalculatedSlaTargetAt(
                $incident,
                $sla,
                $accumulatedSeconds + $liveSeconds,
                $isSlaPaused
            ),
            // Acciones supervisadas de coordinación (RF-04.3, RF-06.2): sólo con pausa viva.
            'can_resume_pause' => $isSlaPaused,
            'can_cancel_inactivity' => $isSlaPaused,
            'reason_category' => $category?->value ?? (is_string($categoryValue) ? $categoryValue : null),
            'reason_category_label' => $category?->label(),
            'reason_text' => $incident['pending_info_reason_text'] ?? null,
            // Motivo de la pausa por repuestos, que es otra causa distinta (RF-04.2).
            'reason' => $incident['pending_parts_reason'] ?? null,
            'requested_parts' => array_map(
                fn(array $row): array => $this->mapRequestedPart($row),
                $requestedParts
            ),
        ];
    }

    /**
     * Vencimiento contractual recalculado tras descontar la pausa de sede (RF-03.3).
     *
     * Devuelve `null` cuando no hay pausa contractual viva, cuando el expediente no tiene
     * objetivo de cadena de frío derivado (máquina no perecedera) o cuando la fila no trae
     * sede: sin calendario comercial no se inventa una fecha. El desplazamiento lo calcula
     * el servicio del módulo 11, que es la única autoridad del horario hábil 08:00-18:00.
     *
     * @param array<string, mixed> $incident Raw incident row.
     * @param array<string, mixed> $sla Already derived cold-chain SLA block.
     * @param int $pauseSeconds Segundos de pausa acumulados más el intervalo vivo.
     * @param bool $isSlaPaused Whether the contractual clock is actually frozen.
     * @return string|null New contractual deadline in database format, or null.
     */
    private function recalculatedSlaTargetAt(
        array $incident,
        array $sla,
        int $pauseSeconds,
        bool $isSlaPaused
    ): ?string {
        if (!$isSlaPaused) {
            return null;
        }

        $targetAt = $this->parseDateTime(
            isset($sla['sla_target_at']) && is_string($sla['sla_target_at']) ? $sla['sla_target_at'] : null
        );
        $locationId = (int)($incident['location_id'] ?? 0);
        if ($targetAt === null || $locationId < 1) {
            return null;
        }

        return $this->pauses()
            ->shiftSlaTargetInBusinessHours($targetAt, $pauseSeconds, $locationId)
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function buildCommentsBlock(array $comments): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int)($row['id'] ?? 0),
            'author_type' => (string)($row['author_type'] ?? ''),
            'author_name' => (string)($row['author_name'] ?? ''),
            'comment_text' => (string)($row['comment_text'] ?? ''),
            'is_internal' => (bool)($row['is_internal'] ?? false),
            'created_at' => (string)($row['created_at'] ?? ''),
        ], $comments);
    }

    /**
     * @param array<string, mixed>|null $refund
     * @return array<string, mixed>
     */
    private function buildRefundBlock(?array $refund): array
    {
        if ($refund === null) {
            return [
                'has_refund' => false,
                'refund_id' => null,
                'claim_code' => null,
                'amount' => null,
                'compensation_method' => null,
                'compensation_method_label' => null,
                'status' => null,
                'status_label' => null,
                'contact_phone_masked' => null,
                'iban_masked' => null,
                'technician_finding' => null,
                'cash_custody_action' => null,
                'technician_notes' => null,
                'refund_tab_url' => null,
            ];
        }

        $masked = $this->maskFinancialAndContactData($refund) ?? [];
        $method = CompensationMethod::tryFrom((string)($refund['compensation_method'] ?? ''));
        $status = RefundStatus::tryFrom((string)($refund['status'] ?? ''));

        return [
            'has_refund' => true,
            'refund_id' => (int)($refund['id'] ?? 0),
            'claim_code' => $masked['claim_code'] ?? null,
            'amount' => $masked['amount'] ?? null,
            'compensation_method' => $masked['compensation_method'] ?? null,
            'compensation_method_label' => $this->compensationMethodLabel($method),
            'status' => $masked['status'] ?? null,
            'status_label' => $status?->label() ?? (string)($refund['status'] ?? ''),
            'contact_phone_masked' => $masked['contact_phone_masked'] ?? null,
            'iban_masked' => $masked['iban_masked'] ?? null,
            'technician_finding' => $masked['technician_finding'] ?? null,
            'cash_custody_action' => $masked['cash_custody_action'] ?? null,
            'technician_notes' => $refund['technician_justification'] ?? null,
            'refund_tab_url' => '#refunds?id=' . (int)($refund['id'] ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Row mapping helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRequestedPart(array $row): array
    {
        $isOutOfCatalog = (bool)($row['is_out_of_catalog'] ?? false);
        $customDescription = $row['custom_part_description'] ?? null;

        return [
            'spare_part_id' => ($row['spare_part_id'] ?? null) !== null ? (int)$row['spare_part_id'] : null,
            'part_code' => (string)($row['part_code'] ?? ($isOutOfCatalog ? 'OUT_OF_CATALOG' : '')),
            'description' => (string)($row['part_name'] ?? $customDescription ?? ''),
            'quantity' => (int)($row['quantity'] ?? 0),
            'is_out_of_catalog' => $isOutOfCatalog,
            // The schema stores a single mandatory ≥20-char text for special parts, so
            // it is exposed as both the detailed description and the technical
            // justification instead of inventing a second one (RF-04.2).
            'justification' => $isOutOfCatalog ? $customDescription : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapReplacedPart(array $row): array
    {
        $isOutOfCatalog = (bool)($row['is_out_of_catalog'] ?? false);
        $destinationValue = (string)($row['old_part_destination'] ?? '');
        $destination = OldPartDestination::tryFrom($destinationValue);

        return [
            'spare_part_id' => ($row['spare_part_id'] ?? null) !== null ? (int)$row['spare_part_id'] : null,
            'part_code' => (string)($row['part_code'] ?? ($isOutOfCatalog ? 'OUT_OF_CATALOG' : '')),
            'description' => (string)($row['part_name'] ?? $row['custom_part_name'] ?? ''),
            'quantity' => (int)($row['quantity'] ?? 0),
            'is_out_of_catalog' => $isOutOfCatalog,
            'unit_cost_snapshot' => (float)($row['unit_cost_snapshot'] ?? 0.0),
            'total_cost_snapshot' => (float)($row['total_cost_snapshot'] ?? 0.0),
            'old_part_destination' => $destinationValue,
            'destination_label' => $destination?->label() ?? $destinationValue,
            'notes' => $row['notes'] ?? null,
        ];
    }

    /**
     * The schema carries no channel column and the creation event note is the only
     * trace: QR citizen reports write "Aviso registrado mediante código QR" while the
     * site portal writes "Aviso registrado desde el portal de sede".
     *
     * @param list<array<string, mixed>> $history
     */
    private function detectReportChannel(array $history): string
    {
        $creationNote = strtolower((string)($history[0]['action_note'] ?? ''));

        return str_contains($creationNote, 'qr') ? 'QR_CODE' : 'LOCATION_PORTAL';
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed>|null $machine
     */
    private function resolveMachineType(array $incident, ?array $machine): ?MachineType
    {
        $rawType = $machine['machine_type'] ?? $incident['machine_type_snapshot'] ?? null;
        if ($rawType === null || $rawType === '') {
            return null;
        }

        return MachineType::tryFrom((string)$rawType);
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function findLastActorName(array $history, string $toStatus): ?string
    {
        $actorName = null;
        foreach ($history as $row) {
            if (($row['to_status'] ?? null) === $toStatus && ($row['user_name'] ?? null) !== null) {
                $actorName = (string)$row['user_name'];
            }
        }

        return $actorName;
    }

    /**
     * Latest justified motive of a technical reassignment, read from the immutable
     * history (RF-07.3, Art. III.1). Returns null while the ticket keeps its first
     * technician, which is the case of every ticket outside the reassignment flow.
     *
     * @param list<array<string, mixed>> $history Chronologically ordered history rows.
     */
    private function findReassignmentReason(array $history): ?string
    {
        $reason = null;

        foreach ($history as $row) {
            $note = (string)($row['action_note'] ?? '');
            if (!str_contains($note, self::REASSIGNMENT_NOTE_MARKER)) {
                continue;
            }

            $markerAt = strpos($note, self::REASSIGNMENT_MOTIVE_MARKER);
            if ($markerAt === false) {
                continue;
            }

            $motive = trim(substr($note, $markerAt + strlen(self::REASSIGNMENT_MOTIVE_MARKER)));
            if ($motive !== '') {
                $reason = $motive;
            }
        }

        return $reason;
    }

    /**
     * @param array<string, mixed> $incident
     */
    private function isWithinCommentWindow(IncidentStatus $status, array $incident, DateTimeImmutable $now): bool
    {
        if ($status !== IncidentStatus::RESOLVED) {
            return false;
        }

        $resolvedAt = $this->parseDateTime($incident['resolved_at'] ?? null)
            ?? $this->parseDateTime($incident['updated_at'] ?? null);
        if ($resolvedAt === null) {
            return false;
        }

        return $now <= $resolvedAt->modify('+' . self::COMMENT_GUARANTEE_HOURS . ' hours');
    }

    private function compensationMethodLabel(?CompensationMethod $method): ?string
    {
        return match ($method) {
            CompensationMethod::EN_MANO_SEDE => 'En mano en sede',
            CompensationMethod::BIZUM => 'Bizum',
            CompensationMethod::TRANSFERENCIA_BANCARIA => 'Transferencia bancaria',
            null => null,
        };
    }

    private function maskPhone(?string $phone): ?string
    {
        $clean = $phone !== null ? trim($phone) : '';
        if ($clean === '') {
            return null;
        }

        if (strlen($clean) < 9) {
            return '*** *** ***';
        }

        return substr($clean, 0, 1) . '** *** ' . substr($clean, -3);
    }

    private function maskIban(?string $iban): ?string
    {
        $clean = $iban !== null ? str_replace(' ', '', trim($iban)) : '';
        if ($clean === '') {
            return null;
        }

        if (strlen($clean) < 8) {
            return '** **** **** **** ****';
        }

        $country = substr($clean, 0, 2);
        $lastFour = substr($clean, -4);

        return $country . '** **** **** **** **' . substr($lastFour, 0, 2) . ' ' . substr($lastFour, 2, 2);
    }

    /**
     * @param array<string, mixed> $refundRecord
     */
    private function deriveClaimCode(array $refundRecord): ?string
    {
        $id = isset($refundRecord['id']) ? (int)$refundRecord['id'] : 0;
        if ($id < 1) {
            return null;
        }

        $createdAt = $this->parseDateTime($refundRecord['created_at'] ?? null);
        $year = $createdAt?->format('Y') ?? date('Y');

        return sprintf('REF-%s-%05d', $year, $id);
    }

    private function formatHoursMinutes(int $minutes): string
    {
        $minutes = abs($minutes);
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} h" : "{$hours} h {$rest} min";
    }

    private function diffInMinutes(?DateTimeImmutable $from, ?DateTimeImmutable $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, (int)round(($to->getTimestamp() - $from->getTimestamp()) / 60));
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
