<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * IncidentHistory
 * 
 * Entidad / Registro inmutable de auditoría para cada transición o evento
 * en el ciclo de vida de una incidencia (Artículo III de la Constitución).
 */
class IncidentHistory implements ArrayAccess, JsonSerializable
{
    private ?int $id;
    private int $incidentId;
    private ?int $userId;
    private ?string $fromStatus;
    private string $toStatus;
    private ?string $actionNote;
    private ?string $createdAt;
    private ?string $userName;
    private ?string $userRole;

    public function __construct(
        ?int $id,
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null,
        ?string $createdAt = null,
        ?string $userName = null,
        ?string $userRole = null
    ) {
        $this->id = $id;
        $this->incidentId = $incidentId;
        $this->userId = $userId;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->actionNote = $actionNote;
        $this->createdAt = $createdAt;
        $this->userName = $userName;
        $this->userRole = $userRole;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)$row['incident_id'],
            isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null,
            isset($row['from_status']) && $row['from_status'] !== null ? (string)$row['from_status'] : null,
            (string)$row['to_status'],
            isset($row['action_note']) && $row['action_note'] !== null ? (string)$row['action_note'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['user_name']) && $row['user_name'] !== null ? (string)$row['user_name'] : null,
            isset($row['user_role']) && $row['user_role'] !== null ? (string)$row['user_role'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getActionNote(): ?string
    {
        return $this->actionNote;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getUserRole(): ?string
    {
        return $this->userRole;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'user_id' => $this->userId,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'action_note' => $this->actionNote,
            'created_at' => $this->createdAt,
            'user_name' => $this->userName,
            'user_role' => $this->userRole,
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
