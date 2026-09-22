<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * Machine
 * 
 * Entidad de Dominio que representa una máquina dispensadora del parque de vending.
 * Puede incorporar información enriquecida sobre su ticket activo o en garantía.
 * 
 * Implementa ArrayAccess y JsonSerializable para compatibilidad fluida.
 */
class Machine implements ArrayAccess, JsonSerializable
{
    private int $id;
    private int $locationId;
    private string $code;
    private string $model;
    private MachineType $machineType;
    private string $floorWing;
    private ?string $notes;
    private bool $isActive;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;
    /** @var array<string, mixed>|null */
    private ?array $activeIncident;

    /**
     * @param int $id
     * @param int $locationId
     * @param string $code
     * @param string $model
     * @param MachineType $machineType
     * @param string $floorWing
     * @param string|null $notes
     * @param bool $isActive
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @param string|null $deletedAt
     * @param array<string, mixed>|null $activeIncident
     */
    public function __construct(
        int $id,
        int $locationId,
        string $code,
        string $model,
        MachineType $machineType,
        string $floorWing,
        ?string $notes = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
        ?array $activeIncident = null
    ) {
        $this->id = $id;
        $this->locationId = $locationId;
        $this->code = strtoupper(trim($code));
        $this->model = trim($model);
        $this->machineType = $machineType;
        $this->floorWing = trim($floorWing);
        $this->notes = $notes !== null ? trim($notes) : null;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->activeIncident = $activeIncident;
    }

    /**
     * Factoría para reconstruir la entidad desde una fila asociativa de base de datos.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $activeIncident
     * @return self
     */
    public static function fromDatabaseRow(array $row, ?array $activeIncident = null): self
    {
        $machineType = MachineType::fromString((string)$row['machine_type']);

        return new self(
            (int)$row['id'],
            (int)$row['location_id'],
            (string)$row['code'],
            (string)$row['model'],
            $machineType,
            (string)$row['floor_wing'],
            isset($row['notes']) && $row['notes'] !== null ? (string)$row['notes'] : null,
            (bool)($row['is_active'] ?? 1),
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            $activeIncident
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMachineType(): MachineType
    {
        return $this->machineType;
    }

    public function getFloorWing(): string
    {
        return $this->floorWing;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->isActive && $this->deletedAt === null;
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function hasActiveIncident(): bool
    {
        return $this->activeIncident !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveIncident(): ?array
    {
        return $this->activeIncident;
    }

    /**
     * @param array<string, mixed>|null $activeIncident
     */
    public function setActiveIncident(?array $activeIncident): void
    {
        $this->activeIncident = $activeIncident;
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
     * Convierte la entidad a un array estructurado (compatible con API REST).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->locationId,
            'code' => $this->code,
            'model' => $this->model,
            'machine_type' => $this->machineType->value,
            'floor_wing' => $this->floorWing,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
            'active_incident' => $this->activeIncident,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -------------------------------------------------------------
    // Implementación de ArrayAccess
    // -------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        $data = $this->toArray();
        return $data[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Entidad inmutable vía array access
    }

    public function offsetUnset(mixed $offset): void
    {
        // Entidad inmutable vía array access
    }
}
