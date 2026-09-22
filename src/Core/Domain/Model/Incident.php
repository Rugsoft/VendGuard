<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * Incident
 * 
 * Entidad Raíz de Agregado que modela una avería o incidencia en una máquina de vending.
 * Incorpora reglas de negocio, ciclo de vida, trazabilidad y enriquecimiento relacional.
 */
class Incident implements ArrayAccess, JsonSerializable
{
    private ?int $id;
    private string $ticketCode;
    private int $machineId;
    private int $locationId;
    private ?int $assignedTechnicianId;
    private ?string $reporterName;
    private ?string $reporterPhone;
    private IncidentCategory $category;
    private string $description;
    private ?float $retainedMoneyAmount;
    private ?string $photoPath;
    private UrgencyLevel $urgency;
    private IncidentStatus $status;
    private ?string $assignedAt;
    private ?string $startedAt;
    private ?string $pendingPartsReason;
    private ?string $resolutionDiagnosis;
    private ?string $resolutionAction;
    private ?string $resolvedAt;
    private ?string $reopenReason;
    private ?string $reopenedAt;
    private ?string $closedAt;
    private ?string $cancellationReason;
    private ?string $cancelledAt;
    private ?int $isActiveTicket;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    // Campos enriquecidos opcionales (vía JOIN)
    private ?string $machineCode;
    private ?string $machineModel;
    private ?string $machineType;
    private ?string $locationName;
    private ?string $locationSiteCode;
    private ?string $technicianName;

    public function __construct(
        ?int $id,
        string $ticketCode,
        int $machineId,
        int $locationId,
        IncidentCategory $category,
        string $description,
        UrgencyLevel $urgency,
        IncidentStatus $status = IncidentStatus::REGISTERED,
        ?int $assignedTechnicianId = null,
        ?string $reporterName = null,
        ?string $reporterPhone = null,
        ?float $retainedMoneyAmount = null,
        ?string $photoPath = null,
        ?string $assignedAt = null,
        ?string $startedAt = null,
        ?string $pendingPartsReason = null,
        ?string $resolutionDiagnosis = null,
        ?string $resolutionAction = null,
        ?string $resolvedAt = null,
        ?string $reopenReason = null,
        ?string $reopenedAt = null,
        ?string $closedAt = null,
        ?string $cancellationReason = null,
        ?string $cancelledAt = null,
        ?int $isActiveTicket = 1,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
        ?string $machineCode = null,
        ?string $machineModel = null,
        ?string $machineType = null,
        ?string $locationName = null,
        ?string $locationSiteCode = null,
        ?string $technicianName = null
    ) {
        $this->id = $id;
        $this->ticketCode = strtoupper(trim($ticketCode));
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->category = $category;
        $this->description = trim($description);
        $this->urgency = $urgency;
        $this->status = $status;
        $this->assignedTechnicianId = $assignedTechnicianId;
        $this->reporterName = $reporterName !== null ? trim($reporterName) : null;
        $this->reporterPhone = $reporterPhone !== null ? trim($reporterPhone) : null;
        $this->retainedMoneyAmount = $retainedMoneyAmount;
        $this->photoPath = $photoPath;
        $this->assignedAt = $assignedAt;
        $this->startedAt = $startedAt;
        $this->pendingPartsReason = $pendingPartsReason;
        $this->resolutionDiagnosis = $resolutionDiagnosis;
        $this->resolutionAction = $resolutionAction;
        $this->resolvedAt = $resolvedAt;
        $this->reopenReason = $reopenReason;
        $this->reopenedAt = $reopenedAt;
        $this->closedAt = $closedAt;
        $this->cancellationReason = $cancellationReason;
        $this->cancelledAt = $cancelledAt;
        $this->isActiveTicket = $isActiveTicket;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->machineCode = $machineCode;
        $this->machineModel = $machineModel;
        $this->machineType = $machineType;
        $this->locationName = $locationName;
        $this->locationSiteCode = $locationSiteCode;
        $this->technicianName = $technicianName;
    }

    /**
     * Factoría para reconstruir la entidad desde una fila asociativa de base de datos.
     *
     * @param array<string, mixed> $row
     * @return self
     */
    public static function fromDatabaseRow(array $row): self
    {
        $category = IncidentCategory::fromString((string)$row['category']);
        $urgency = UrgencyLevel::fromString((string)$row['urgency']);
        $status = IncidentStatus::fromString((string)$row['status']);

        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)$row['ticket_code'],
            (int)$row['machine_id'],
            (int)$row['location_id'],
            $category,
            (string)$row['description'],
            $urgency,
            $status,
            isset($row['assigned_technician_id']) && $row['assigned_technician_id'] !== null ? (int)$row['assigned_technician_id'] : null,
            isset($row['reporter_name']) && $row['reporter_name'] !== null ? (string)$row['reporter_name'] : null,
            isset($row['reporter_phone']) && $row['reporter_phone'] !== null ? (string)$row['reporter_phone'] : null,
            isset($row['retained_money_amount']) && $row['retained_money_amount'] !== null ? (float)$row['retained_money_amount'] : null,
            isset($row['photo_path']) && $row['photo_path'] !== null ? (string)$row['photo_path'] : null,
            isset($row['assigned_at']) && $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null,
            isset($row['started_at']) && $row['started_at'] !== null ? (string)$row['started_at'] : null,
            isset($row['pending_parts_reason']) && $row['pending_parts_reason'] !== null ? (string)$row['pending_parts_reason'] : null,
            isset($row['resolution_diagnosis']) && $row['resolution_diagnosis'] !== null ? (string)$row['resolution_diagnosis'] : null,
            isset($row['resolution_action']) && $row['resolution_action'] !== null ? (string)$row['resolution_action'] : null,
            isset($row['resolved_at']) && $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            isset($row['reopen_reason']) && $row['reopen_reason'] !== null ? (string)$row['reopen_reason'] : null,
            isset($row['reopened_at']) && $row['reopened_at'] !== null ? (string)$row['reopened_at'] : null,
            isset($row['closed_at']) && $row['closed_at'] !== null ? (string)$row['closed_at'] : null,
            isset($row['cancellation_reason']) && $row['cancellation_reason'] !== null ? (string)$row['cancellation_reason'] : null,
            isset($row['cancelled_at']) && $row['cancelled_at'] !== null ? (string)$row['cancelled_at'] : null,
            isset($row['is_active_ticket']) && $row['is_active_ticket'] !== null ? (int)$row['is_active_ticket'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            isset($row['machine_code']) && $row['machine_code'] !== null ? (string)$row['machine_code'] : null,
            isset($row['machine_model']) && $row['machine_model'] !== null ? (string)$row['machine_model'] : null,
            isset($row['machine_type']) && $row['machine_type'] !== null ? (string)$row['machine_type'] : null,
            isset($row['location_name']) && $row['location_name'] !== null ? (string)$row['location_name'] : null,
            isset($row['location_site_code']) && $row['location_site_code'] !== null ? (string)$row['location_site_code'] : null,
            isset($row['technician_name']) && $row['technician_name'] !== null ? (string)$row['technician_name'] : null
        );
    }

    /**
     * Retorna una nueva instancia con el identificador asignado tras la inserción.
     */
    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicketCode(): string
    {
        return $this->ticketCode;
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

    public function getReporterName(): ?string
    {
        return $this->reporterName;
    }

    public function getReporterPhone(): ?string
    {
        return $this->reporterPhone;
    }

    public function getCategory(): IncidentCategory
    {
        return $this->category;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getRetainedMoneyAmount(): ?float
    {
        return $this->retainedMoneyAmount;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function getUrgency(): UrgencyLevel
    {
        return $this->urgency;
    }

    public function getStatus(): IncidentStatus
    {
        return $this->status;
    }

    public function getAssignedAt(): ?string
    {
        return $this->assignedAt;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getPendingPartsReason(): ?string
    {
        return $this->pendingPartsReason;
    }

    public function getResolutionDiagnosis(): ?string
    {
        return $this->resolutionDiagnosis;
    }

    public function getResolutionAction(): ?string
    {
        return $this->resolutionAction;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function getReopenReason(): ?string
    {
        return $this->reopenReason;
    }

    public function getReopenedAt(): ?string
    {
        return $this->reopenedAt;
    }

    public function getClosedAt(): ?string
    {
        return $this->closedAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function getCancelledAt(): ?string
    {
        return $this->cancelledAt;
    }

    public function getIsActiveTicket(): ?int
    {
        return $this->isActiveTicket;
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

    public function getMachineCode(): ?string
    {
        return $this->machineCode;
    }

    public function getMachineModel(): ?string
    {
        return $this->machineModel;
    }

    public function getMachineType(): ?string
    {
        return $this->machineType;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function getLocationSiteCode(): ?string
    {
        return $this->locationSiteCode;
    }

    public function getTechnicianName(): ?string
    {
        return $this->technicianName;
    }

    /**
     * Comprueba si la incidencia está activa (bloqueando nuevos avisos en la máquina).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    public function isClosed(): bool
    {
        return $this->status === IncidentStatus::CLOSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === IncidentStatus::CANCELLED;
    }

    /**
     * Valida si el ticket se encuentra dentro de la ventana de garantía legal (48 horas tras resolución).
     *
     * @param int $hours Ventana en horas (por defecto 48 según Art. V Constitución).
     * @return bool
     */
    public function isInWarranty(int $hours = 48): bool
    {
        if ($this->status !== IncidentStatus::RESOLVED || $this->resolvedAt === null) {
            return false;
        }

        $resolvedTime = strtotime($this->resolvedAt);
        if ($resolvedTime === false) {
            return false;
        }

        $now = time();
        $diffSeconds = $now - $resolvedTime;

        return $diffSeconds >= 0 && $diffSeconds <= ($hours * 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_code' => $this->ticketCode,
            'machine_id' => $this->machineId,
            'location_id' => $this->locationId,
            'assigned_technician_id' => $this->assignedTechnicianId,
            'reporter_name' => $this->reporterName,
            'reporter_phone' => $this->reporterPhone,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'retained_money_amount' => $this->retainedMoneyAmount,
            'photo_path' => $this->photoPath,
            'urgency' => $this->urgency->value,
            'urgency_label' => $this->urgency->label(),
            'urgency_color' => $this->urgency->color(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'assigned_at' => $this->assignedAt,
            'started_at' => $this->startedAt,
            'pending_parts_reason' => $this->pendingPartsReason,
            'resolution_diagnosis' => $this->resolutionDiagnosis,
            'resolution_action' => $this->resolutionAction,
            'resolved_at' => $this->resolvedAt,
            'reopen_reason' => $this->reopenReason,
            'reopened_at' => $this->reopenedAt,
            'closed_at' => $this->closedAt,
            'cancellation_reason' => $this->cancellationReason,
            'cancelled_at' => $this->cancelledAt,
            'is_active_ticket' => $this->isActiveTicket,
            'is_active' => $this->isActive(),
            'is_in_warranty' => $this->isInWarranty(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
            // Campos de visualización joined
            'machine_code' => $this->machineCode,
            'machine_model' => $this->machineModel,
            'machine_type' => $this->machineType,
            'location_name' => $this->locationName,
            'location_site_code' => $this->locationSiteCode,
            'technician_name' => $this->technicianName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
