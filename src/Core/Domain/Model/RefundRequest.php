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

    /**
     * Wrong pickup PINs a case tolerates before it locks (RF-REF-02).
     *
     * Four digits is 10.000 combinations. Measured against this endpoint, 3000
     * wrong PINs went through in 1.02 seconds, so without a brake the whole
     * keyspace was reachable in under four minutes. `hash_equals()` stops the
     * secret leaking through timing; only a counter stops it being ground down
     * by volume.
     */
    public const PICKUP_PIN_MAX_ATTEMPTS = 5;

    /** How long the pickup PIN stays locked, in minutes (RF-REF-02). */
    public const PICKUP_PIN_LOCK_MINUTES = 15;

    public function __construct(
        private ?int $id,
        private ?int $incidentId,
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
        private ?float $paidAmount = null,
        private ?CoordinatorDecision $coordinatorDecision = null,
        private ?string $coordinatorJustification = null,
        private ?string $paidAt = null,
        private bool $isActive = true,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private bool $financialColumnsRestricted = false,
        // RF-REF-02: the brute-force brake. Declared last so every existing
        // construction site keeps working; they are named-argument calls.
        private int $pickupAttempts = 0,
        private ?string $pickupLockedUntil = null
    ) {
        // `null` significa "todavía no persistido", el mismo centinela que usa
        // la entidad `Incident`. El identificador sólo existe una vez insertada
        // la fila, así que el servicio de aplicación construye el expediente con
        // `null` y lo recarga tras el INSERT. Un identificador explícito
        // continúa siendo obligatorio y estrictamente positivo: cero o
        // negativos son corrupción, no "sin guardar".
        if ($id !== null && $id < 1) {
            throw new InvalidArgumentException('El expediente requiere un identificador estrictamente positivo.');
        }

        // RF-04.5: la cancelación por inactividad de sede DESVINCULA el expediente
        // de su avería (`incident_id = NULL`) para que la reclamación sobreviva y el
        // consumidor cobre su dinero. `null` es por tanto un vínculo cortado a
        // propósito, no un dato ausente. Máquina y sede siguen siendo obligatorias:
        // toda reclamación nace en una ubicación física concreta.
        if (($incidentId !== null && $incidentId < 1) || $machineId < 1 || $locationId < 1) {
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

        // RF-REF-07: the settled amount is a stored fact. It is bounded by the
        // 50,00 EUR block so a corrupted figure can never sit in a monetary
        // column; `registerDigitalPayment()` enforces the tighter per-case
        // ceiling against the approved or claimed amount.
        if ($paidAmount !== null && (!is_finite($paidAmount) || $paidAmount <= 0.0)) {
            throw new InvalidArgumentException('El importe liquidado debe ser un valor finito mayor que 0,00 €.');
        }

        if ($paidAmount !== null && $paidAmount > self::MAX_CLAIMED_AMOUNT) {
            throw new InvalidArgumentException('El importe liquidado no puede superar el tope máximo de 50,00 €.');
        }

        // TINYINT UNSIGNED is the column, so 255 is the storage truth. Anything
        // beyond it is not a counter, it is corruption.
        if ($pickupAttempts < 0 || $pickupAttempts > 255) {
            throw new InvalidArgumentException('El contador de intentos del PIN debe estar entre 0 y 255.');
        }

        if ($pickupLockedUntil !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $pickupLockedUntil) !== 1) {
            throw new InvalidArgumentException('El bloqueo del PIN debe ser una fecha y hora válidas.');
        }

        // RF-REF-05: digital channels are settled by the central office, so the
        // cash backing them can never stay at the reception desk.
        //
        // The two payment-detail rules below are skipped only for a restricted
        // rehydration, where the columns were never selected. They still hold
        // for every creation path, so a case can never be created incomplete.
        if (!$financialColumnsRestricted) {
            if ($compensationMethod->requiresBizumPhone() && ($bizumPhone === null || trim($bizumPhone) === '')) {
                throw new InvalidArgumentException('El pago por Bizum requiere el teléfono del reclamante.');
            }

            if ($compensationMethod->requiresIban() && ($iban === null || trim($iban) === '')) {
                throw new InvalidArgumentException('La transferencia bancaria requiere el IBAN del reclamante.');
            }
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
     * The amount Coordination actually settled (RF-REF-07).
     *
     * Stored rather than re-derived: the case is the audit record of the money,
     * and a case sitting in PAID_DIGITAL must be able to say on its own how much
     * left the till, without anyone having to reconstruct it from a banking
     * reference afterwards.
     */
    public function getPaidAmount(): ?float
    {
        return $this->paidAmount;
    }

    /**
     * How many wrong PINs this case has absorbed (RF-REF-02).
     *
     * Never projected to a consumer or to the desk: the counter tells an
     * attacker how much of the keyspace is gone.
     */
    public function getPickupAttempts(): int
    {
        return $this->pickupAttempts;
    }

    /**
     * When the pickup PIN stops being locked, or null when it is not locked
     * (RF-REF-02). A lock that never expired would be a denial of service on
     * the consumer, so it is time-bounded rather than a flag.
     */
    public function getPickupLockedUntil(): ?string
    {
        return $this->pickupLockedUntil;
    }

    /**
     * Whether the pickup PIN is locked at `$now` (RF-REF-02).
     *
     * The comparison is a string one on purpose: both sides are
     * `Y-m-d H:i:s` in the same timezone, and lexicographic order IS
     * chronological order for that format.
     */
    public function isPickupLockedAt(string $now): bool
    {
        return $this->pickupLockedUntil !== null && strcmp($this->pickupLockedUntil, $now) > 0;
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
     * Builds a case from a row that was projected WITHOUT the financial columns.
     *
     * The constructor refuses a Bizum case with no phone, because a case must
     * never be *created* incomplete. Reading one back through a restricted
     * projection is a different matter: the site desk and the field technician
     * are not allowed to see the IBAN or the Bizum number at all (Art. V.4),
     * so the columns are absent by policy rather than by corruption. This
     * factory is the single sanctioned way to hydrate such a row, and it keeps
     * the creation-time invariant intact for every other path.
     *
     * @param array<string, mixed> $row Row without `iban` and `bizum_phone`.
     * @return self
     */
    public static function fromRestrictedProjection(array $row): self
    {
        $nullable = static fn (string $key): ?string => isset($row[$key]) ? (string)$row[$key] : null;

        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            incidentId: isset($row['incident_id']) && $row['incident_id'] !== null ? (int)$row['incident_id'] : null,
            machineId: (int)($row['machine_id'] ?? 0),
            locationId: (int)($row['location_id'] ?? 0),
            claimantName: (string)($row['claimant_name'] ?? ''),
            claimantContact: (string)($row['claimant_contact'] ?? ''),
            claimedAmount: (float)($row['claimed_amount'] ?? 0.0),
            productAttempted: (string)($row['product_attempted'] ?? ''),
            compensationMethod: CompensationMethod::from((string)($row['compensation_method'] ?? '')),
            bizumPhone: null,
            iban: null,
            pickupPin: $nullable('pickup_pin'),
            trackingToken: (string)($row['tracking_token'] ?? ''),
            status: RefundStatus::from((string)($row['status'] ?? '')),
            technicianFinding: isset($row['technician_finding']) && $row['technician_finding'] !== null
                ? TechnicianFinding::from((string)$row['technician_finding'])
                : null,
            recoveredAmount: isset($row['recovered_amount']) && $row['recovered_amount'] !== null
                ? (float)$row['recovered_amount']
                : null,
            cashCustodyAction: isset($row['cash_custody_action']) && $row['cash_custody_action'] !== null
                ? CashCustodyAction::from((string)$row['cash_custody_action'])
                : null,
            receptionistName: $nullable('receptionist_name'),
            technicianJustification: $nullable('technician_justification'),
            approvedAmount: isset($row['approved_amount']) && $row['approved_amount'] !== null
                ? (float)$row['approved_amount']
                : null,
            paymentReference: $nullable('payment_reference'),
            paidAmount: isset($row['paid_amount']) && $row['paid_amount'] !== null
                ? (float)$row['paid_amount']
                : null,
            // `coordinator_decision` sólo se proyecta en la vista completa de
            // Coordinación: una proyección restringida nunca lo seleccionó, y
            // quien no puede ver el IBAN tampoco necesita el motivo del rechazo.
            coordinatorDecision: isset($row['coordinator_decision']) && $row['coordinator_decision'] !== null
                ? CoordinatorDecision::from((string)$row['coordinator_decision'])
                : null,
            coordinatorJustification: $nullable('coordinator_justification'),
            paidAt: $nullable('paid_at'),
            isActive: (int)($row['is_active'] ?? 1) === 1,
            createdAt: $nullable('created_at'),
            updatedAt: $nullable('updated_at'),
            financialColumnsRestricted: true,
            // La proyección restringida nunca selecciona el freno antifuerza: el
            // contador de intentos es tan sensible como el propio PIN, porque
            // dice cuántas combinaciones quedan por probar. Lejos de la mesa de
            // conserjería el valor por defecto (cero) es además la lectura
            // honesta, ya que sólo la vista completa conoce el histórico real.
            pickupAttempts: 0,
            pickupLockedUntil: null,
        );
    }

    /**
     * Whether this case was rehydrated from a projection that never selected
     * the financial columns, so its IBAN and Bizum phone are absent by policy.
     */
    public function hasRestrictedFinancialColumns(): bool
    {
        return $this->financialColumnsRestricted;
    }

    /**
     * Whether the case still needs a technician verdict before money moves.
     */
    public function awaitsInspection(): bool
    {
        return $this->status === RefundStatus::PENDING_INSPECTION;
    }

    /**
     * Returns a copy carrying the technician's on-site verdict.
 *
     * The case is immutable, so filing a verdict produces a new instance rather
     * than mutating this one. Keeping the copy here means the service never has
     * to restate the twenty-six constructor arguments just to attach a verdict.
     *
     * The status is deliberately left untouched: filing a verdict and moving the
     * case to its next state are two separate steps, and the latter goes through
     * the repository's guarded transition.
     */
    public function withInspection(
        TechnicianFinding $finding,
        ?float $recoveredAmount = null,
        ?CashCustodyAction $cashCustodyAction = null,
        ?string $receptionistName = null,
        ?string $technicianJustification = null
    ): self {
        return new self(
            id: $this->id,
            incidentId: $this->incidentId,
            machineId: $this->machineId,
            locationId: $this->locationId,
            claimantName: $this->claimantName,
            claimantContact: $this->claimantContact,
            claimedAmount: $this->claimedAmount,
            productAttempted: $this->productAttempted,
            compensationMethod: $this->compensationMethod,
            bizumPhone: $this->bizumPhone,
            iban: $this->iban,
            pickupPin: $this->pickupPin,
            trackingToken: $this->trackingToken,
            status: $this->status,
            technicianFinding: $finding,
            recoveredAmount: $recoveredAmount,
            cashCustodyAction: $cashCustodyAction,
            receptionistName: $receptionistName ?? $this->receptionistName,
            technicianJustification: $technicianJustification ?? $this->technicianJustification,
            approvedAmount: $this->approvedAmount,
            paymentReference: $this->paymentReference,
            paidAmount: $this->paidAmount,
            coordinatorDecision: $this->coordinatorDecision,
            coordinatorJustification: $this->coordinatorJustification,
            pickupAttempts: $this->pickupAttempts,
            pickupLockedUntil: $this->pickupLockedUntil,
            paidAt: $this->paidAt,
            isActive: $this->isActive,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            financialColumnsRestricted: $this->financialColumnsRestricted
        );
    }

    /**
     * The persisted identifier, or null while the case has not been stored yet.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Avería vinculada, o `null` cuando el expediente fue desvinculado por la
     * cancelación de su avería por inactividad de sede (RF-04.5).
     */
    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    /**
     * Indica si el expediente quedó desvinculado de su avería técnica: la
     * reclamación sigue viva en la bandeja de Coordinación para liquidación
     * central, pero ya no cuelga de ningún ticket (RF-04.5).
     */
    public function isDetachedFromIncident(): bool
    {
        return $this->incidentId === null;
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

    /**
     * Decisión formal de Coordinación, o null mientras el expediente no se ha
     * trámite (RF-REF-03, RF-REF-08).
     */
    public function getCoordinatorDecision(): ?CoordinatorDecision
    {
        return $this->coordinatorDecision;
    }

    /**
     * Motivo escrito del visto bueno o del rechazo.
     */
    public function getCoordinatorJustification(): ?string
    {
        return $this->coordinatorJustification;
    }

    /**
     * Instante de la liquidación digital efectiva, o null si el dinero aún no
     * ha salido (RF-REF-07).
     */
    public function getPaidAt(): ?string
    {
        return $this->paidAt;
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
            'coordinator_decision' => $this->coordinatorDecision?->value,
            'coordinator_justification' => $this->coordinatorJustification,
            'paid_at' => $this->paidAt,
            // `pickup_attempts` y `pickup_locked_until` NO se serializan a
            // propósito: son estado antifuerza interno (RF-REF-02) y nadie fuera
            // del dominio necesita saber cuántos intentos lleva el expediente.
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