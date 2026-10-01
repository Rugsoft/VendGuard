<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable consumer refund case raised against an incident.
 *
 * The case owns its own lifecycle instead of mirroring the incident one, so the
 * machine can be declared resolved while the money is still being refunded
 * (Constitution Art. II). Cancellation is always logical (Art. III): there is no
 * state or setter that erases the record.
 */
final readonly class RefundRequest implements JsonSerializable
{
    /** Blocking antifraud ceiling per single vending claim (RF-REF-03). */
    public const MAX_CLAIMED_AMOUNT = 50.00;

    /** Above this amount the coordinator must formally approve (RF-REF-03). */
    public const COORDINATOR_APPROVAL_THRESHOLD = 10.00;

    /** Cash above this amount may never be left at the reception desk (RF-REF-05). */
    public const RECEPTION_DELIVERY_LIMIT = 10.00;

    /** Relative gap between claimed and recovered cash that forces escalation. */
    public const DISCREPANCY_TOLERANCE = 0.20;

    public function __construct(
        private int $id,
        private int $incidentId,
        private int $machineId,
        private int $locationId,
        private string $claimantName,
        private string $claimantContact,
        private float $claimedAmount,
        private string $productAttempted,
        private CompensationMethod $compensationMethod,
        private ?string $bizumPhone,
        private ?string $iban,
        private ?string $pickupPin,
        private string $trackingToken,
        private RefundStatus $status,
        private ?TechnicianFinding $technicianFinding = null,
        private ?float $recoveredAmount = null,
        private ?CashCustodyAction $cashCustodyAction = null,
        private ?string $receptionistName = null,
        private ?string $technicianJustification = null,
        private ?float $approvedAmount = null,
        private ?string $paymentReference = null,
        private bool $isActive = true,
        private ?string $createdAt = null,
        private ?string $updatedAt = null
    ) {
        if ($id < 1 || $incidentId < 1 || $machineId < 1 || $locationId < 1) {
            throw new InvalidArgumentException('Los identificadores del expediente deben ser enteros positivos.');
        }

        if (trim($claimantName) === '') {
            throw new InvalidArgumentException('El expediente requiere el nombre del reclamante.');
        }

        if (trim($claimantContact) === '') {
            throw new InvalidArgumentException('El expediente requiere un medio de contacto del reclamante.');
        }

        if (!is_finite($claimedAmount)) {
            throw new InvalidArgumentException('El importe reclamado debe ser un valor finito.');
        }

        // RF-REF-03: the 50.00 EUR ceiling is blocking, not advisory.
        if ($claimedAmount <= 0.0) {
            throw new InvalidArgumentException('El importe reclamado debe ser estrictamente mayor que 0,00 €.');
        }

        if ($claimedAmount > self::MAX_CLAIMED_AMOUNT) {
            throw new InvalidArgumentException(
                'El importe reclamado supera el tope máximo de 50,00 € por reclamación.'
            );
        }

        if (trim($trackingToken) === '') {
            throw new InvalidArgumentException('El expediente requiere un token de seguimiento seguro.');
        }

        if ($pickupPin !== null && !preg_match('/^[0-9]{4}$/', $pickupPin)) {
            throw new InvalidArgumentException('El PIN de recogida debe tener exactamente 4 dígitos.');
        }

        if ($recoveredAmount !== null && (!is_finite($recoveredAmount) || $recoveredAmount < 0.0)) {
            throw new InvalidArgumentException('El importe recuperado no puede ser negativo ni no finito.');
        }

        if ($approvedAmount !== null && (!is_finite($approvedAmount) || $approvedAmount < 0.0)) {
            throw new InvalidArgumentException('El importe aprobado no puede ser negativo ni no finito.');
        }

        // RF-REF-05: digital channels are settled by the central office, so the
        // cash backing them can never stay at the reception desk.
        if ($compensationMethod->requiresBizumPhone() && ($bizumPhone === null || trim($bizumPhone) === '')) {
            throw new InvalidArgumentException('El pago por Bizum requiere el teléfono del reclamante.');
        }

        if ($compensationMethod->requiresIban() && ($iban === null || trim($iban) === '')) {
            throw new InvalidArgumentException('La transferencia bancaria requiere el IBAN del reclamante.');
        }

        if ($cashCustodyAction === CashCustodyAction::LEFT_AT_RECEPTION
            && ($compensationMethod->isDigital() || $claimedAmount > self::RECEPTION_DELIVERY_LIMIT)) {
            throw new InvalidArgumentException(
                'No se puede custodiar en conserjería un importe digital o superior a 10,00 €.'
            );
        }
    }

    /**
     * Whether the case needs an explicit coordinator sign-off before payment:
     * an elevated amount, a discrepancy above the tolerated gap, or an
     * unverified absence of cash (RF-REF-03).
     */
    public function requiresSpecialSupervision(): bool
    {
        if ($this->claimedAmount > self::COORDINATOR_APPROVAL_THRESHOLD) {
            return true;
        }

        if ($this->technicianFinding?->requiresEscalation() === true) {
            return true;
        }

        return $this->hasMaterialDiscrepancy();
    }

    /**
     * Relative gap between the claimed and the recovered cash, or null while
     * the technician has not reported any amount.
     */
    public function discrepancyRatio(): ?float
    {
        if ($this->recoveredAmount === null) {
            return null;
        }

        if ($this->claimedAmount <= 0.0) {
            return null;
        }

        return abs($this->claimedAmount - $this->recoveredAmount) / $this->claimedAmount;
    }

    /**
     * Whether the gap between claimed and recovered cash exceeds the tolerated
     * 20% and therefore must be reviewed by the coordinator (RF-REF-03).
     */
    public function hasMaterialDiscrepancy(): bool
    {
        $ratio = $this->discrepancyRatio();

        return $ratio !== null && $ratio > self::DISCREPANCY_TOLERANCE;
    }

    /**
     * Claimant name reduced to its first token plus the initial of the second,
     * so the reception desk can identify a claimant without exposing the full
     * name (Art. V.4, RF-REF-10). Example: "Laura Sanitaria" -> "Laura S.".
     */
    public function getAnonymizedClaimantName(): string
    {
        $tokens = preg_split('/\s+/', trim($this->claimantName)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        if ($tokens === []) {
            return '';
        }

        if (count($tokens) === 1) {
            return $tokens[0];
        }

        return $tokens[0] . ' ' . mb_substr($tokens[1], 0, 1) . '.';
    }

    /**
     * Constant-time comparison of a pickup PIN presented at the reception desk
     * (RF-REF-06). Kept on the entity so no caller can fall back to a plain
     * string comparison that leaks timing information.
     */
    public function verifyPickupPin(string $inputPin): bool
    {
        if ($this->pickupPin === null) {
            return false;
        }

        return hash_equals($this->pickupPin, trim($inputPin));
    }

    /**
     * Whether the case still needs a technician verdict before money moves.
     */
    public function awaitsInspection(): bool
    {
        return $this->status === RefundStatus::PENDING_INSPECTION;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getClaimantName(): string
    {
        return $this->claimantName;
    }

    public function getClaimantContact(): string
    {
        return $this->claimantContact;
    }

    public function getClaimedAmount(): float
    {
        return $this->claimedAmount;
    }

    public function getProductAttempted(): string
    {
        return $this->productAttempted;
    }

    public function getCompensationMethod(): CompensationMethod
    {
        return $this->compensationMethod;
    }

    public function getBizumPhone(): ?string
    {
        return $this->bizumPhone;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function getPickupPin(): ?string
    {
        return $this->pickupPin;
    }

    public function getTrackingToken(): string
    {
        return $this->trackingToken;
    }

    public function getStatus(): RefundStatus
    {
        return $this->status;
    }

    public function getTechnicianFinding(): ?TechnicianFinding
    {
        return $this->technicianFinding;
    }

    public function getRecoveredAmount(): ?float
    {
        return $this->recoveredAmount;
    }

    public function getCashCustodyAction(): ?CashCustodyAction
    {
        return $this->cashCustodyAction;
    }

    public function getReceptionistName(): ?string
    {
        return $this->receptionistName;
    }

    public function getTechnicianJustification(): ?string
    {
        return $this->technicianJustification;
    }

    public function getApprovedAmount(): ?float
    {
        return $this->approvedAmount;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Safe projection for the reception desk and field technician: it exposes
     * the anonymized claimant and never the IBAN, the Bizum phone or the PIN
     * (Art. V.4, RF-REF-10).
     *
     * @return array<string, mixed>
     */
    public function toRestrictedArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'machine_id' => $this->machineId,
            'location_id' => $this->locationId,
            'claimant_name' => $this->getAnonymizedClaimantName(),
            'claimed_amount' => $this->claimedAmount,
            'product_attempted' => $this->productAttempted,
            'compensation_method' => $this->compensationMethod->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'technician_finding' => $this->technicianFinding?->value,
            'recovered_amount' => $this->recoveredAmount,
            'cash_custody_action' => $this->cashCustodyAction?->value,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'machine_id' => $this->machineId,
            'location_id' => $this->locationId,
            'claimant_name' => $this->claimantName,
            'claimant_contact' => $this->claimantContact,
            'claimed_amount' => $this->claimedAmount,
            'product_attempted' => $this->productAttempted,
            'compensation_method' => $this->compensationMethod->value,
            'bizum_phone' => $this->bizumPhone,
            'iban' => $this->iban,
            'pickup_pin' => $this->pickupPin,
            'tracking_token' => $this->trackingToken,
            'status' => $this->status->value,
            'technician_finding' => $this->technicianFinding?->value,
            'recovered_amount' => $this->recoveredAmount,
            'cash_custody_action' => $this->cashCustodyAction?->value,
            'receptionist_name' => $this->receptionistName,
            'technician_justification' => $this->technicianJustification,
            'approved_amount' => $this->approvedAmount,
            'payment_reference' => $this->paymentReference,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}