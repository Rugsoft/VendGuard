<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * AuditEvent
 * 
 * Entidad inmutable que representa un evento atómico en el registro histórico de auditoría.
 * Cumple estrictamente con el Artículo III.3, Artículo V.1 y RF-05 (EARS 5.1 a 5.4).
 * 
 * Diseñado como registro de sólo adición (append-only), sin setters ni opciones de modificación.
 */
class AuditEvent implements JsonSerializable
{
    public const ENTITY_TICKET   = 'TICKET';
    public const ENTITY_MACHINE  = 'MACHINE';
    public const ENTITY_LOCATION = 'LOCATION';
    public const ENTITY_USER     = 'USER';

    private ?int $id;
    private string $entityType;
    private int $entityId;
    private string $action;
    private ?int $userId;
    private string $userRole;
    private string $userName;
    private ?array $previousState;
    private array $newState;
    private ?array $metadata;
    private string $createdAt;

    /**
     * @param int|null $id Identificador unívoco del registro en BD (null antes de persistir).
     * @param string $entityType Tipo de entidad ('TICKET', 'MACHINE', 'LOCATION', 'USER').
     * @param int $entityId ID numérico de la entidad afectada.
     * @param string $action Acción ejecutada (ej. 'RESOLVE_INCIDENT', 'STATUS_CHANGE', 'ASSIGN_TECHNICIAN').
     * @param int|null $userId ID del usuario causante (null si es sistema o reporte anónimo).
     * @param string $userRole Rol del usuario ('COORDINATOR', 'TECHNICIAN', 'SYSTEM', 'PUBLIC').
     * @param string $userName Nombre del usuario o identificador de origen.
     * @param array|null $previousState Estado o valores previos.
     * @param array $newState Estado o valores nuevos resultantes.
     * @param array|null $metadata Metadatos contextuales adicionales.
     * @param string|null $createdAt Marca de tiempo de inserción (formato Y-m-d H:i:s).
     */
    public function __construct(
        ?int $id,
        string $entityType,
        int $entityId,
        string $action,
        ?int $userId,
        string $userRole,
        string $userName,
        ?array $previousState,
        array $newState,
        ?array $metadata = null,
        ?string $createdAt = null
    ) {
        $validTypes = [self::ENTITY_TICKET, self::ENTITY_MACHINE, self::ENTITY_LOCATION, self::ENTITY_USER];
        if (!in_array($entityType, $validTypes, true)) {
            throw new InvalidArgumentException("Tipo de entidad de auditoría inválido: {$entityType}");
        }

        $trimmedAction = trim($action);
        if ($trimmedAction === '') {
            throw new InvalidArgumentException('La acción de auditoría no puede estar vacía.');
        }

        if ($entityId <= 0) {
            throw new InvalidArgumentException('El identificador de la entidad debe ser un entero positivo.');
        }

        $this->id = $id;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->action = $trimmedAction;
        $this->userId = $userId;
        $this->userRole = strtoupper(trim($userRole));
        $this->userName = trim($userName);
        $this->previousState = $previousState;
        $this->newState = $newState;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUserRole(): string
    {
        return $this->userRole;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getPreviousState(): ?array
    {
        return $this->previousState;
    }

    public function getNewState(): array
    {
        return $this->newState;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->createdAt,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'action' => $this->action,
            'user' => [
                'id' => $this->userId,
                'role' => $this->userRole,
                'name' => $this->userName,
            ],
            'previous_state' => $this->previousState,
            'new_state' => $this->newState,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
