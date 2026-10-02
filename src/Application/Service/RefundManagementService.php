<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\CoordinatorApprovalDTO;
use VendGuard\Application\DTO\CoordinatorPaymentDTO;
use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Core\Domain\Exception\InvalidPickupPinException;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CoordinatorDecision;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;

/**
 * RefundManagementService
 *
 * Orchestrates the lifecycle of a consumer refund case: opening it from the QR
 * report or the site portal, moving it along the legal state machine, releasing
 * the cash at the reception desk behind the pickup PIN, and recording the
 * coordinator sign-off and the digital settlement.
 *
 * The case lifecycle is deliberately independent from the incident one, so a
 * broken machine returns to service without waiting for the claimant to be paid
 * (Constitution Art. II). Nothing here ever removes a row: a case is cancelled
 * logically and the audit trail is append-only (Art. III).
 *
 * ## State machine
 * `LEGAL_TRANSITIONS` mirrors the graph of `specs/technical/refunds_contracts.md`
 * §1.1 edge for edge. It is the single authority on which moves exist; every
 * write below goes through `transitionCase()`, so no caller can skip the check.
 * The `REQUIRES_COORDINATOR_APPROVAL -> REJECTED` edge is already declared here
 * even though the reject operation lands with the coordination controller in
 * T-REF-12, so the table stays a faithful mirror of the contract.
 *
 * ## Financial secrecy (Art. V.4)
 * Audit payloads are assembled field by field and never spread the case, so the
 * IBAN, the Bizum phone and the pickup PIN can never reach the audit log.
 */
final class RefundManagementService
{
    /** Lower bound of the generated pickup PIN (RF-REF-02). */
    public const PICKUP_PIN_MIN = 1000;

    /** Upper bound of the generated pickup PIN (RF-REF-02). */
    public const PICKUP_PIN_MAX = 9999;

    /** Random bytes behind the tracking token: 32 bytes render as 64 hex chars. */
    public const TRACKING_TOKEN_BYTES = 32;

    /**
     * Legal edges of the refund lifecycle, exactly as contracted.
     *
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        'PENDING_INSPECTION' => [
            'DEPOSITED_AT_RECEPTION',
            'VERIFIED_PENDING_PAYMENT',
            'REQUIRES_COORDINATOR_APPROVAL',
        ],
        'DEPOSITED_AT_RECEPTION' => ['REFUNDED_IN_HAND'],
        'VERIFIED_PENDING_PAYMENT' => ['PAID_DIGITAL', 'PENDING_CONTACT'],
        'REQUIRES_COORDINATOR_APPROVAL' => ['VERIFIED_PENDING_PAYMENT', 'REJECTED'],
        'PENDING_CONTACT' => ['VERIFIED_PENDING_PAYMENT'],
        'PAID_DIGITAL' => [],
        'REFUNDED_IN_HAND' => [],
        'REJECTED' => [],
    ];

    private RefundRequestRepositoryInterface $refundRepo;
    private IbanValidationService $ibanValidator;
    private AuditLogger $auditLogger;

    public function __construct(
        RefundRequestRepositoryInterface $refundRepo,
        ?IbanValidationService $ibanValidator = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->refundRepo = $refundRepo;
        $this->ibanValidator = $ibanValidator ?? new IbanValidationService();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Secrets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cryptographically secure four-digit pickup PIN (RF-REF-02).
     *
     * `random_int` is used instead of `rand` so the secret cannot be predicted
     * from a previous draw.
     */
    public function generatePickupPin(): string
    {
        return (string)random_int(self::PICKUP_PIN_MIN, self::PICKUP_PIN_MAX);
    }

    /**
     * Cryptographically secure tracking token: 32 random bytes as 64 hex chars.
     */
    public function generateTrackingToken(): string
    {
        return bin2hex(random_bytes(self::TRACKING_TOKEN_BYTES));
    }

    // ─────────────────────────────────────────────────────────────────────
    // State machine
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Whether the lifecycle graph allows moving a case between two states.
     */
    public function canTransition(RefundStatus $from, RefundStatus $to): bool
    {
        return in_array($to->value, self::LEGAL_TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Every legal edge of the lifecycle, as a transition map.
     *
     * @return array<string, list<string>>
     */
    public function legalTransitions(): array
    {
        return self::LEGAL_TRANSITIONS;
    }

    /**
     * @throws InvalidRefundStateTransitionException When the edge does not exist.
     */
    public function assertTransitionAllowed(
        RefundStatus $from,
        RefundStatus $to,
        ?string $attemptedAction = null
    ): void {
        if (!$this->canTransition($from, $to)) {
            throw new InvalidRefundStateTransitionException(
                fromStatus: $from,
                toStatus: $to,
                attemptedAction: $attemptedAction
            );
        }
    }

    /**
     * Decides the state a case lands in once the technician has filed a verdict.
     *
     * Implements the classification of `plan.md` §3.3 verbatim: cash already
     * waiting at the reception desk stops there; anything above 10,00 €, with a
     * material discrepancy, or with an unverified absence of cash escalates to
     * Coordination instead of going straight to payment.
     *
     * @throws InvalidRefundStateTransitionException When the case is not awaiting
     *   its inspection any more, so a second verdict cannot overwrite the first.
     * @throws ReceptionDeliveryNotAllowedException When the cash backing a
     *   digital channel, or more than 10,00 €, is claimed to stay at reception.
     */
    public function classifyInspectionOutcome(RefundRequest $case): RefundStatus
    {
        if (!$case->awaitsInspection()) {
            throw new InvalidRefundStateTransitionException(
                fromStatus: $case->getStatus(),
                toStatus: RefundStatus::VERIFIED_PENDING_PAYMENT,
                attemptedAction: 'INSPECT_INCIDENT'
            );
        }

        $recovered = $case->getRecoveredAmount() ?? 0.0;
        $custody = $case->getCashCustodyAction();
        $isDigital = $case->getCompensationMethod()->isDigital();

        if ($case->getTechnicianFinding() === TechnicianFinding::FOUND_PHYSICAL) {
            // RF-REF-05: elevated amounts and digital channels must go to the
            // central safe; leaving them at the desk is refused outright.
            if (($recovered > RefundRequest::RECEPTION_DELIVERY_LIMIT || $isDigital)
                && $custody !== CashCustodyAction::HELD_FOR_CENTRAL) {
                throw new ReceptionDeliveryNotAllowedException(
                    claimedAmount: $case->getClaimedAmount(),
                    compensationMethod: $case->getCompensationMethod(),
                    receptionLimit: RefundRequest::RECEPTION_DELIVERY_LIMIT
                );
            }

            if ($custody === CashCustodyAction::LEFT_AT_RECEPTION) {
                return RefundStatus::DEPOSITED_AT_RECEPTION;
            }
        }

        if ($case->requiresSpecialSupervision()) {
            return RefundStatus::REQUIRES_COORDINATOR_APPROVAL;
        }

        return RefundStatus::VERIFIED_PENDING_PAYMENT;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case creation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Opens a refund case with a fresh PIN and tracking token (RF-REF-01/02).
     *
     * The pickup PIN is only issued for `EN_MANO_SEDE`: a Bizum or bank transfer
     * is settled by the central office and the claimant never presents a secret
     * at the reception desk.
     *
     * @throws InvalidRefundAmountException When the claim leaves the antifraud
     *   range of (0,00 €, 50,00 €].
     * @throws \VendGuard\Core\Domain\Exception\InvalidBizumPhoneException
     * @throws \VendGuard\Core\Domain\Exception\InvalidIbanFormatException
     */
    public function createCase(CreateRefundRequestDTO $dto): RefundRequest
    {
        // RF-REF-03: the 50.00 EUR ceiling is blocking, not advisory.
        if ($dto->claimedAmount <= 0.0 || $dto->claimedAmount > RefundRequest::MAX_CLAIMED_AMOUNT) {
            throw new InvalidRefundAmountException(
                attemptedAmount: $dto->claimedAmount,
                maximumAllowed: RefundRequest::MAX_CLAIMED_AMOUNT
            );
        }

        $bizumPhone = $dto->compensationMethod->requiresBizumPhone()
            ? $this->ibanValidator->assertValidBizumPhone((string)$dto->bizumPhone)
            : null;

        $iban = $dto->compensationMethod->requiresIban()
            ? $this->ibanValidator->assertValidIban((string)$dto->iban)
            : null;

        $pickupPin = $dto->compensationMethod->isDigital()
            ? null
            : $this->generatePickupPin();

        $case = new RefundRequest(
            id: null,
            incidentId: $dto->incidentId,
            machineId: $dto->machineId,
            locationId: $dto->locationId,
            claimantName: trim($dto->claimantName),
            claimantContact: trim($dto->claimantContact),
            claimedAmount: $dto->claimedAmount,
            productAttempted: $dto->productAttempted,
            compensationMethod: $dto->compensationMethod,
            bizumPhone: $bizumPhone,
            iban: $iban,
            pickupPin: $pickupPin,
            trackingToken: $this->generateTrackingToken(),
            status: RefundStatus::PENDING_INSPECTION
        );

        $caseId = $this->refundRepo->insert($case);

        // `RefundRequest` is immutable and was built with id 0, because the
        // identifier only exists once the row exists. Reloading returns the
        // persisted case, so callers never handle a zero identifier.
        $persisted = $this->loadCase($caseId);

        $this->auditLogger->logRefundEvent(
            $caseId,
            'REFUND_CASE_CREATED',
            ['id' => null, 'role' => 'PUBLIC', 'name' => 'Consumidor final'],
            null,
            [
                'status' => RefundStatus::PENDING_INSPECTION->value,
                'claimed_amount' => $case->getClaimedAmount(),
                'compensation_method' => $case->getCompensationMethod()->value,
            ],
            ['incident_id' => $case->getIncidentId()]
        );

        return $persisted;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Operations
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Releases the cash to the claimant at the reception desk (RF-REF-06).
     *
     * The PIN is compared in constant time through the entity, and a wrong PIN
     * leaves the case untouched so the attempt can be retried.
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor Receptionist.
     * @throws RefundNotFoundException
     * @throws InvalidPickupPinException
     * @throws InvalidRefundStateTransitionException
     */
    public function deliverInHand(int $id, string $pin, array $actor = []): RefundRequest
    {
        $case = $this->loadCase($id);

        if (!$case->verifyPickupPin($pin)) {
            throw new InvalidPickupPinException();
        }

        $this->transitionCase($case, RefundStatus::REFUNDED_IN_HAND, 'DELIVER_IN_HAND', [
            'hand_delivered_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->logRefundEvent(
            $id,
            'REFUND_DELIVERED_IN_HAND',
            $this->normalizeActor($actor, 'LOCATION_MANAGER', 'Conserjería'),
            ['status' => $case->getStatus()->value],
            ['status' => RefundStatus::REFUNDED_IN_HAND->value]
        );

        return $this->loadCase($id);
    }

    /**
     * Records the formal coordinator sign-off of the final amount (RF-REF-03).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @throws RefundNotFoundException
     * @throws InvalidRefundStateTransitionException
     */
    public function approveCase(int $id, CoordinatorApprovalDTO $dto, array $actor = []): RefundRequest
    {
        // RF-REF-03: el techo de 50,00 € por reclamación también aplica al
        // visto bueno. `createCase()` lo respeta al abrir el expediente, pero
        // sin este segundo control una firma podría convalidar con el botón
        // de aprobación un importe que el formulario de entrada habría rechazado.
        if ($dto->approvedAmount <= 0.0 || $dto->approvedAmount > RefundRequest::MAX_CLAIMED_AMOUNT) {
            throw new InvalidRefundAmountException(
                attemptedAmount: $dto->approvedAmount,
                maximumAllowed: RefundRequest::MAX_CLAIMED_AMOUNT
            );
        }

        $case = $this->loadCase($id);

        // El grafo legal admite PENDING_INSPECTION -> VERIFIED_PENDING_PAYMENT
        // porque es la vía del técnico cuando lleva el efectivo a caja central.
        // El visto bueno de Coordinación, en cambio, sólo existe para resolver
        // un expediente que la clasificación automática ya marcó como
        // REQUIRES_COORDINATOR_APPROVAL. Sin esta precondición, el endpoint
        // serviría para saltarse la doble autorización del RF-REF-03.
        if ($case->getStatus() !== RefundStatus::REQUIRES_COORDINATOR_APPROVAL) {
            throw new InvalidRefundStateTransitionException(
                fromStatus: $case->getStatus(),
                toStatus: RefundStatus::VERIFIED_PENDING_PAYMENT,
                attemptedAction: 'APPROVE'
            );
        }

        $this->transitionCase($case, RefundStatus::VERIFIED_PENDING_PAYMENT, 'APPROVE', [
            'approved_amount' => $dto->approvedAmount,
            'coordinator_decision' => CoordinatorDecision::APPROVED->value,
            'coordinator_justification' => $dto->justification !== '' ? $dto->justification : null,
            'coordinator_id' => $actor['id'] ?? null,
        ]);

        $this->auditLogger->logRefundEvent(
            $id,
            'REFUND_APPROVED',
            $this->normalizeActor($actor, 'COORDINATOR', 'Coordinación'),
            ['status' => $case->getStatus()->value],
            [
                'status' => RefundStatus::VERIFIED_PENDING_PAYMENT->value,
                'approved_amount' => $dto->approvedAmount,
            ]
        );

        return $this->loadCase($id);
    }

    /**
     * Registers a digital settlement with its banking reference (RF-REF-07).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @throws RefundNotFoundException
     * @throws InvalidRefundStateTransitionException
     */
    public function registerDigitalPayment(int $id, CoordinatorPaymentDTO $dto, array $actor = []): RefundRequest
    {
        if ($dto->paidAmount <= 0.0) {
            throw new InvalidRefundAmountException(
                attemptedAmount: $dto->paidAmount,
                maximumAllowed: RefundRequest::MAX_CLAIMED_AMOUNT
            );
        }

        $case = $this->loadCase($id);

        $this->transitionCase($case, RefundStatus::PAID_DIGITAL, 'PAY', [
            'payment_reference' => trim($dto->paymentReference),
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->logRefundEvent(
            $id,
            'REFUND_PAID_DIGITAL',
            $this->normalizeActor($actor, 'COORDINATOR', 'Coordinación'),
            ['status' => $case->getStatus()->value],
            [
                'status' => RefundStatus::PAID_DIGITAL->value,
                'payment_reference' => trim($dto->paymentReference),
            ]
        );

        return $this->loadCase($id);
    }

    /**
     * Records the formal coordinator dismissal of a claim (RF-REF-08).
     *
     * It lives here, next to `approveCase()`, and not in the coordination
     * controller on purpose: `LEGAL_TRANSITIONS` is documented as the single
     * authority on which moves exist, and `transitionCase()` as the single
     * guarded write path. A controller writing straight to the repository would
     * be the one place in the module able to change a state without asking the
     * graph, which is exactly how a case ends up paid and rejected at once.
     *
     * A dismissal is terminal but never destructive: the case keeps its full
     * financial history for the audit trail (Art. III).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @throws JustificationTooShortException When the written reason is shorter
     *   than the 20 descriptive characters the contract demands.
     * @throws RefundNotFoundException When the case does not exist or was archived.
     * @throws InvalidRefundStateTransitionException When the current status has
     *   no edge to `REJECTED`.
     */
    public function rejectCase(int $id, string $justification, array $actor = []): RefundRequest
    {
        $reason = trim($justification);
        if (mb_strlen($reason) < JustificationTooShortException::MINIMUM_LENGTH) {
            throw new JustificationTooShortException($reason);
        }

        $case = $this->loadCase($id);

        $this->transitionCase($case, RefundStatus::REJECTED, 'REJECT_REFUND', [
            'coordinator_decision' => CoordinatorDecision::REJECTED->value,
            'coordinator_justification' => $reason,
            'coordinator_id' => $actor['id'] ?? null,
        ]);

        $this->auditLogger->logRefundEvent(
            $id,
            'REFUND_REJECTED',
            $this->normalizeActor($actor, 'COORDINATOR', 'Coordinación'),
            ['status' => $case->getStatus()->value],
            [
                'status' => RefundStatus::REJECTED->value,
                'claimed_amount' => $case->getClaimedAmount(),
            ]
        );

        return $this->loadCase($id);
    }

    /**
     * Rectifies the claimant payment details after a contact failure
     * (RF-REF-07) and returns the case to the payment lane.
     *
     * @throws RefundNotFoundException
     * @throws InvalidRefundStateTransitionException
     */
    public function rectifyContactDetails(int $id, ?string $bizumPhone = null, ?string $iban = null): RefundRequest
    {
        $case = $this->loadCase($id);

        $validatedPhone = $bizumPhone !== null
            ? $this->ibanValidator->assertValidBizumPhone($bizumPhone)
            : null;

        $validatedIban = $iban !== null
            ? $this->ibanValidator->assertValidIban($iban)
            : null;

        $this->assertTransitionAllowed($case->getStatus(), RefundStatus::VERIFIED_PENDING_PAYMENT, 'RECTIFY_CONTACT');

        if ($case->getCompensationMethod()->requiresBizumPhone() && $validatedPhone === null) {
            $validatedPhone = $this->ibanValidator->assertValidBizumPhone((string)$case->getBizumPhone());
        }

        if ($case->getCompensationMethod()->requiresIban() && $validatedIban === null) {
            $validatedIban = $this->ibanValidator->assertValidIban((string)$case->getIban());
        }

        $this->refundRepo->updateContactDetails($id, $validatedPhone, $validatedIban);

        $updated = $this->loadCase($id);

        $this->transitionCase($updated, RefundStatus::VERIFIED_PENDING_PAYMENT, 'RECTIFY_CONTACT');

        // The payload carries no financial detail on purpose (Art. V.4).
        $this->auditLogger->logRefundEvent(
            $id,
            'REFUND_CONTACT_RECTIFIED',
            ['id' => null, 'role' => 'PUBLIC', 'name' => 'Consumidor final'],
            ['status' => RefundStatus::PENDING_CONTACT->value],
            ['status' => RefundStatus::VERIFIED_PENDING_PAYMENT->value]
        );

        return $this->loadCase($id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @throws RefundNotFoundException When the case does not exist or was archived.
     */
    private function loadCase(int $id): RefundRequest
    {
        $case = $this->refundRepo->findById($id);

        if ($case === null || !$case->isActive()) {
            throw new RefundNotFoundException($id);
        }

        return $case;
    }

    /**
     * The single guarded write path: the edge must be legal and the repository
     * must confirm the optimistic update, so a concurrent request cannot settle
     * a case twice.
     *
     * @param array<string, mixed> $fields
     * @throws InvalidRefundStateTransitionException
     */
    private function transitionCase(
        RefundRequest $case,
        RefundStatus $to,
        string $action,
        array $fields = []
    ): void {
        $from = $case->getStatus();

        $this->assertTransitionAllowed($from, $to, $action);

        if (!$this->refundRepo->transitionStatus((int) $case->getId(), $from, $to, $fields)) {
            // Another request moved the case first; report it as a conflict
            // rather than silently overwriting the winner.
            throw new InvalidRefundStateTransitionException(
                fromStatus: $from,
                toStatus: $to,
                attemptedAction: $action
            );
        }
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return array{id: int|null, role: string, name: string}
     */
    private function normalizeActor(array $actor, string $defaultRole, string $defaultName): array
    {
        return [
            'id' => $actor['id'] ?? null,
            'role' => $actor['role'] ?? $defaultRole,
            'name' => $actor['name'] ?? $defaultName,
        ];
    }
}