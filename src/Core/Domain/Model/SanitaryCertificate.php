<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;

/**
 * Entidad de Dominio: SanitaryCertificate
 *
 * Representa el Certificado Oficial de Inspección Sanitaria emitido tras una
 * evaluación técnica conforme (Art. V.4 de la Constitución).
 * Protege la privacidad de los inspectores limitando la identificación
 * al nombre profesional y Código de Operador Oficial.
 */
class SanitaryCertificate
{
    public const STATUS_VALID = 'VALID';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_REVOKED = 'REVOKED';

    public const RESULT_CONFORME = 'CONFORME';
    public const RESULT_CONFORME_OBSERVACIONES = 'CONFORME_CON_OBSERVACIONES';

    public const VALID_STATUSES = [
        self::STATUS_VALID,
        self::STATUS_SUSPENDED,
        self::STATUS_REVOKED,
    ];

    private ?int $id;
    private string $certificateCode;
    private int $preventiveOrderId;
    private int $machineId;
    private int $locationId;
    private int $technicianId;
    private string $technicianName;
    private string $technicianOperatorCode;
    private string $inspectionDate;
    private string $validUntil;
    private ?float $temperatureMeasured;
    private string $result;
    private string $status;
    private ?string $suspendedReason;
    private ?string $suspendedAt;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    /**
     * @var array<string, mixed>|null Metadatos hidratados de la máquina
     */
    private ?array $machineData;

    /**
     * @var array<string, mixed>|null Metadatos hidratados de la sede
     */
    private ?array $locationData;

    /**
     * @var array<int, array<string, mixed>>|null Ítems del checklist normativo incluidos
     */
    private ?array $inspectedItems;

    public function __construct(
        ?int $id,
        string $certificateCode,
        int $preventiveOrderId,
        int $machineId,
        int $locationId,
        int $technicianId,
        string $technicianName,
        string $technicianOperatorCode,
        string $inspectionDate,
        string $validUntil,
        ?float $temperatureMeasured,
        string $result,
        string $status = self::STATUS_VALID,
        ?string $suspendedReason = null,
        ?string $suspendedAt = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
        ?array $machineData = null,
        ?array $locationData = null,
        ?array $inspectedItems = null
    ) {
        $this->id = $id;
        $this->certificateCode = trim($certificateCode);
        $this->preventiveOrderId = $preventiveOrderId;
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->technicianId = $technicianId;
        $this->technicianName = trim($technicianName);
        $this->technicianOperatorCode = trim($technicianOperatorCode);
        $this->inspectionDate = $inspectionDate;
        $this->validUntil = $validUntil;
        $this->temperatureMeasured = $temperatureMeasured;
        $this->result = strtoupper(trim($result));
        $this->status = strtoupper(trim($status));
        $this->suspendedReason = $suspendedReason;
        $this->suspendedAt = $suspendedAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->machineData = $machineData;
        $this->locationData = $locationData;
        $this->inspectedItems = $inspectedItems;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCertificateCode(): string
    {
        return $this->certificateCode;
    }

    public function getPreventiveOrderId(): int
    {
        return $this->preventiveOrderId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getTechnicianName(): string
    {
        return $this->technicianName;
    }

    public function getTechnicianOperatorCode(): string
    {
        return $this->technicianOperatorCode;
    }

    public function getInspectionDate(): string
    {
        return $this->inspectionDate;
    }

    public function getValidUntil(): string
    {
        return $this->validUntil;
    }

    public function getTemperatureMeasured(): ?float
    {
        return $this->temperatureMeasured;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSuspendedReason(): ?string
    {
        return $this->suspendedReason;
    }

    public function getSuspendedAt(): ?string
    {
        return $this->suspendedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function getMachineData(): ?array
    {
        return $this->machineData;
    }

    public function getLocationData(): ?array
    {
        return $this->locationData;
    }

    public function getInspectedItems(): ?array
    {
        return $this->inspectedItems;
    }

    public function setInspectedItems(?array $items): self
    {
        $this->inspectedItems = $items;
        return $this;
    }

    public function isValid(): bool
    {
        if ($this->status !== self::STATUS_VALID || $this->deletedAt !== null) {
            return false;
        }

        $today = new DateTimeImmutable('today');
        $validUntilDate = new DateTimeImmutable($this->validUntil);

        return $validUntilDate >= $today;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * Serializa el certificado sanitario a array asociativo conforme al contrato API
     * y garantizando la privacidad del técnico (Art. V.4).
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'certificate_code' => $this->certificateCode,
            'status' => $this->status,
            'is_valid' => $this->isValid(),
            'inspection_date' => $this->inspectionDate,
            'valid_until' => $this->validUntil,
            'temperature_measured' => $this->temperatureMeasured,
            'result' => $this->result,
            'suspended_reason' => $this->suspendedReason,
            'suspended_at' => $this->suspendedAt,
            'inspector' => [
                'name' => $this->technicianName,
                'operator_code' => $this->technicianOperatorCode,
            ],
            'created_at' => $this->createdAt,
        ];

        if ($this->machineData !== null) {
            $data['machine'] = $this->machineData;
        }

        if ($this->locationData !== null) {
            $data['location'] = $this->locationData;
        }

        if ($this->inspectedItems !== null) {
            $data['inspected_items'] = $this->inspectedItems;
        }

        $data['verification_url'] = 'https://vendguard.internal/verify/' . $this->certificateCode;

        return $data;
    }
}
