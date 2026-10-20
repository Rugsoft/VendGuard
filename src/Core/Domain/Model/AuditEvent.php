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

    /**
     * Entidades del módulo de reintegros (Módulo 08).
     *
     * 'REFUND_REQUEST' no puede reutilizarse como 'TICKET': el expediente de
     * reintegro tiene su propio espacio de identificadores, así que auditarlo
     * como ticket apuntaría a una avería ajena y rompería la trazabilidad del
     * dinero (RNF-REF-01, Art. III.3).
     */
    public const ENTITY_REFUND_REQUEST          = 'REFUND_REQUEST';
    public const ENTITY_UNCLAIMED_CASH_FINDING  = 'UNCLAIMED_CASH_FINDING';

    /**
     * Entidades del módulo de mantenimiento preventivo (Módulo 05).
     *
     * El enum de `audit_log` admitia estos valores desde la migracion 005, pero
     * el dominio los rechazaba, de modo que ninguna suite podia emitirlos. Los
     * servicios preventivos siguenregistrando bajo `MACHINE` y `TICKET`, que es
     * lo correcto para el historico ya escrito: reetiquetar eventos existentes
     * cambiaria su `entity_id` de referencia y romperia la trazabilidad
     * inmutable (Art. III.3). Estos tipos quedan disponibles para el historico
     * nuevo que se emita a partir de ahora.
     */
    public const ENTITY_PREVENTIVE_ORDER     = 'PREVENTIVE_ORDER';
    public const ENTITY_SANITARY_CERTIFICATE = 'SANITARY_CERTIFICATE';

    /**
     * Entidades del módulo de notificaciones y canal corporativo (Módulo 14).
     */
    public const ENTITY_NOTIFICATION_OUTBOX          = 'NOTIFICATION_OUTBOX';
    public const ENTITY_NOTIFICATION_CHANNEL_SETTING = 'NOTIFICATION_CHANNEL_SETTING';

    /**
     * Entidades del módulo de zonificación operativa (Módulo 17).
     *
     * La zona y su cuadrante técnico tienen espacio de identificadores propio, así
     * que auditarlos como `LOCATION` o `USER` apuntaría a una sede o a un técnico
     * ajenos y rompería la trazabilidad territorial (RNF-03, Art. III.3).
     */
    public const ENTITY_OPERATIONAL_ZONE = 'OPERATIONAL_ZONE';
    public const ENTITY_TECHNICIAN_ZONE  = 'TECHNICIAN_ZONE';

    /**
     * Whitelist completa de tipos de entidad admitidos por el dominio.
     *
     * Debe coincidir exactamente con el enum `audit_log.entity_type` de la base
     * de datos: cualquier divergencia significa o bien un tipo que el motor
     * rechaza en silencio, o bien un valor del enum que el dominio prohibe.
     *
     * @var list<string>
     */
    public const ENTITY_TYPES = [
        self::ENTITY_TICKET,
        self::ENTITY_MACHINE,
        self::ENTITY_LOCATION,
        self::ENTITY_USER,
        self::ENTITY_PREVENTIVE_ORDER,
        self::ENTITY_SANITARY_CERTIFICATE,
        self::ENTITY_REFUND_REQUEST,
        self::ENTITY_UNCLAIMED_CASH_FINDING,
        self::ENTITY_NOTIFICATION_OUTBOX,
        self::ENTITY_NOTIFICATION_CHANNEL_SETTING,
        self::ENTITY_OPERATIONAL_ZONE,
        self::ENTITY_TECHNICIAN_ZONE,
    ];

    /**
     * Indica si un tipo de entidad está permitido por el dominio.
     *
     * @param string $entityType Tipo de entidad a comprobar.
     * @return bool
     */
    public static function isValidEntityType(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

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
     * @param string $entityType Tipo de entidad ('TICKET', 'MACHINE', 'LOCATION', 'USER',
     *   'PREVENTIVE_ORDER', 'SANITARY_CERTIFICATE', 'REFUND_REQUEST', 'UNCLAIMED_CASH_FINDING').
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
        if (!self::isValidEntityType($entityType)) {
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
        // La marca del rastro se rinde **anclada** (con el desfase de la zona operativa), no como pared
        // desnuda: `audit_log.created_at` lleva zona horaria y el motor interpreta en la suya una marca
        // sin zona, de modo que el evento quedaba el huso entero en el futuro. La marca del rastro es la
        // que compara la consulta de «la sede ya respondió en la pausa vigente» —`a.created_at >=
        // i.paused_at`— y la que data el historial del expediente (Contrato del Reloj Operativo, §1).
        $this->createdAt = $createdAt
            ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');
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
