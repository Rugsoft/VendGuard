<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * PreventiveOrder
 * 
 * Entidad de Dominio que representa una orden de inspección preventiva periódica,
 * su ciclo de vida, mediciones higiénico-sanitarias y dictamen final.
 */
class PreventiveOrder implements ArrayAccess, JsonSerializable
{
    private int $id;
    private string $orderCode;
    private int $machineId;
    private int $locationId;
    private ?int $assignedTechnicianId;
    private string $status;
    private string $orderType;
    private string $scheduledDate;
    private string $dueDate;
    private ?string $startedAt;
    private ?string $completedAt;
    private ?float $temperatureMeasured;
    private ?string $result;
    private ?int $linkedIncidentId;
    private bool $isQuarantineTriggered;
    private ?string $notes;
    private ?string $cancellationReason;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    /** @var array<string, mixed>|null */
    private ?array $machineData;
    /** @var array<string, mixed>|null */
    private ?array $locationData;
    /** @var array<string, mixed>|null */
    private ?array $technicianData;

    public function __construct(
        int $id,
        string $orderCode,
        int $machineId,
        int $locationId,
        ?int $assignedTechnicianId,
        string $status,
        string $orderType,
        string $scheduledDate,
        string $dueDate,
        ?string $startedAt = null,
        ?string $completedAt = null,
        ?float $temperatureMeasured = null,
        ?string $result = null,
        ?int $linkedIncidentId = null,
        bool $isQuarantineTriggered = false,
        ?string $notes = null,
        ?string $cancellationReason = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
        ?array $machineData = null,
        ?array $locationData = null,
        ?array $technicianData = null
    ) {
        $this->id = $id;
        $this->orderCode = $orderCode;
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->assignedTechnicianId = $assignedTechnicianId;
        $this->status = $status;
        $this->orderType = $orderType;
        $this->scheduledDate = $scheduledDate;
        $this->dueDate = $dueDate;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
        $this->temperatureMeasured = $temperatureMeasured;
        $this->result = $result;
        $this->linkedIncidentId = $linkedIncidentId;
        $this->isQuarantineTriggered = $isQuarantineTriggered;
        $this->notes = $notes;
        $this->cancellationReason = $cancellationReason;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->machineData = $machineData;
        $this->locationData = $locationData;
        $this->technicianData = $technicianData;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderCode(): string
    {
        return $this->orderCode;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getAssignedTechnicianId(): ?int
    {
        return $this->assignedTechnicianId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOrderType(): string
    {
        return $this->orderType;
    }

    public function getScheduledDate(): string
    {
        return $this->scheduledDate;
    }

    public function getDueDate(): string
    {
        return $this->dueDate;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    public function getTemperatureMeasured(): ?float
    {
        return $this->temperatureMeasured;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getLinkedIncidentId(): ?int
    {
        return $this->linkedIncidentId;
    }

    public function isQuarantineTriggered(): bool
    {
        return $this->isQuarantineTriggered;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
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

    /**
     * @return array<string, mixed>|null
     */
    public function getMachineData(): ?array
    {
        return $this->machineData;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLocationData(): ?array
    {
        return $this->locationData;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTechnicianData(): ?array
    {
        return $this->technicianData;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode,
            'machine_id' => $this->machineId,
            'location_id' => $this->locationId,
            'assigned_technician_id' => $this->assignedTechnicianId,
            'status' => $this->status,
            'order_type' => $this->orderType,
            'scheduled_date' => $this->scheduledDate,
            'due_date' => $this->dueDate,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'temperature_measured' => $this->temperatureMeasured,
            'result' => $this->result,
            'linked_incident_id' => $this->linkedIncidentId,
            'is_quarantine_triggered' => $this->isQuarantineTriggered,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellationReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
            'machine' => $this->machineData,
            'location' => $this->locationData,
            'technician' => $this->technicianData,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        $arr = $this->toArray();
        return $arr[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Inmutable
    }

    public function offsetUnset(mixed $offset): void
    {
        // Inmutable
    }
}
