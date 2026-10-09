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
 * Admite además el estado formal "Fuera de servicio / Bloqueada por falta de acceso"
 * (`BLOCKED_NO_ACCESS`): cuando una avería se cancela tras 72 horas hábiles de
 * silencio de la sede, la máquina NO puede volver a figurar como operativa (RF-04.4,
 * Art. V.1), porque el fallo reportado nunca llegó a repararse.
 * 
 * Implementa ArrayAccess y JsonSerializable para compatibilidad fluida.
 */
class Machine implements ArrayAccess, JsonSerializable
{
    /** Máquina apta para el servicio, sin avería activa (contrato `operational_status`). */
    public const OPERATIONAL_STATUS_OPERATIONAL = 'OPERATIONAL';

    /** Máquina con una avería en curso que exige intervención técnica. */
    public const OPERATIONAL_STATUS_ACTIVE_INCIDENT = 'ACTIVE_INCIDENT';

    /** Máquina resuelta dentro de la ventana de garantía de 48 horas (Art. V.6). */
    public const OPERATIONAL_STATUS_IN_WARRANTY = 'IN_WARRANTY';

    /** Máquina fuera de servicio por acceso bloqueado tras cancelación de la avería (RF-04.4). */
    public const OPERATIONAL_STATUS_BLOCKED_NO_ACCESS = 'BLOCKED_NO_ACCESS';

    /**
     * Anotación de auditoría legible que el bloqueo añade a las notas de la máquina
     * (Algoritmo 5 del plan técnico), para que el operador vea por qué está fuera de servicio.
     */
    private const BLOCKED_NO_ACCESS_NOTE_TEMPLATE = '[Bloqueada por falta de acceso tras ticket %s]';

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
    private bool $isBlockedNoAccess;

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
     * @param bool $isBlockedNoAccess Estado formal fuera de servicio por acceso bloqueado (RF-04.4).
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
        ?array $activeIncident = null,
        bool $isBlockedNoAccess = false
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
        $this->isBlockedNoAccess = $isBlockedNoAccess;
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

        // La marca de bloqueo se hidrata de la forma que exponga la persistencia: la
        // clave dedicada `is_blocked_no_access` o, en su defecto, el propio contrato
        // `operational_status` ya resuelto. Sin ninguna de las dos, la máquina se
        // considera operativa (fila previa al módulo 11). El cableado definitivo de
        // la columna corresponde a T-PAUSE-09 en `PdoMachineRepository`.
        $isBlockedNoAccess = (bool)($row['is_blocked_no_access'] ?? false)
            || strtoupper(trim((string)($row['operational_status'] ?? ''))) === self::OPERATIONAL_STATUS_BLOCKED_NO_ACCESS;

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
            $activeIncident,
            $isBlockedNoAccess
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

    /**
     * Indica si la máquina quedó formalmente fuera de servicio por falta de acceso
     * tras la cancelación de su avería (RF-04.4, Art. V.1).
     */
    public function isBlockedNoAccess(): bool
    {
        return $this->isBlockedNoAccess;
    }

    /**
     * Estado operativo de la máquina para el contrato REST (`operational_status`).
     *
     * El bloqueo por falta de acceso tiene precedencia absoluta: una máquina nunca
     * puede leerse como operativa ni en garantía mientras esté fuera de servicio por
     * un acceso que la sede no facilitó (RF-04.4). Sin bloqueo se conserva la lectura
     * histórica del parque: avería en curso -> `ACTIVE_INCIDENT`, avería resuelta ->
     * `IN_WARRANTY`, sin avería -> `OPERATIONAL`.
     */
    public function getOperationalStatus(): string
    {
        if ($this->isBlockedNoAccess) {
            return self::OPERATIONAL_STATUS_BLOCKED_NO_ACCESS;
        }

        if ($this->activeIncident === null) {
            return self::OPERATIONAL_STATUS_OPERATIONAL;
        }

        $activeStatus = strtoupper(trim((string)($this->activeIncident['status'] ?? '')));

        return $activeStatus === 'RESOLVED'
            ? self::OPERATIONAL_STATUS_IN_WARRANTY
            : self::OPERATIONAL_STATUS_ACTIVE_INCIDENT;
    }

    /**
     * Marca la máquina como fuera de servicio por acceso bloqueado y devuelve una
     * NUEVA instancia (RF-04.4, Algoritmo 5 del plan técnico):
     * 1. Se registra el bloqueo, que tiene precedencia en `getOperationalStatus()`.
     * 2. La máquina queda inactiva (`is_active = false`): no vuelve a figurar como
     *    apta para el servicio ni reaparece en el parque activo de la sede.
     * 3. Se anota el ticket causante en las notas para dejar rastro legible a pie de
     *    máquina; la trazabilidad formal vive en `incident_history` (Art. III).
     *
     * Es idempotente: un segundo bloqueo no duplica la anotación ni pisa la primera.
     *
     * @throws \InvalidArgumentException si falta el código de ticket que justifica el bloqueo.
     */
    public function blockForNoAccess(string $ticketCode): self
    {
        $normalizedTicketCode = strtoupper(trim($ticketCode));
        if ($normalizedTicketCode === '') {
            throw new \InvalidArgumentException('El bloqueo por falta de acceso exige el código del ticket que lo motiva.');
        }

        if ($this->isBlockedNoAccess) {
            return clone $this;
        }

        $annotation = sprintf(self::BLOCKED_NO_ACCESS_NOTE_TEMPLATE, $normalizedTicketCode);
        $previousNotes = $this->notes !== null && $this->notes !== '' ? $this->notes : null;

        $clone = clone $this;
        $clone->isBlockedNoAccess = true;
        $clone->isActive = false;
        $clone->notes = $previousNotes !== null ? $previousNotes . ' ' . $annotation : $annotation;

        return $clone;
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
            'is_blocked_no_access' => $this->isBlockedNoAccess,
            'operational_status' => $this->getOperationalStatus(),
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
