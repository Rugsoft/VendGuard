<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * RestockRoutePlannerValidationException
 *
 * Única excepción de negocio del Planificador de Rutas Periódicas de Carga
 * (Módulo 37). Transporta el código exacto del contrato REST y el estado HTTP
 * con el que la capa de presentación debe responder, de modo que el controlador
 * no reinvente la traducción de cada regla de calendario o de excepción diaria.
 *
 * Códigos por estado HTTP (contrato `restock_route_planner_contracts.md`, §4):
 *   - `403`: rol ajeno a la operación y parada fuera de la ruta efectiva del
 *     reponedor (`RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE`, Art. V.4).
 *   - `409`: parada ya visitada hoy (`RESTOCK_STOP_ALREADY_COMPLETED_TODAY`),
 *     parada segregada por SAT o cuarentena
 *     (`RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT`) y revocación de una excepción
 *     que ya no está viva (`OVERRIDE_NOT_ACTIVE`).
 *   - `422`: coherencia de cadencia (`SCHEDULE_CADENCE_FIELDS_INVALID`), rigor
 *     textual de la omisión (`SKIP_REASON_INVALID`, Art. V.1), causa de desvío
 *     (`REASSIGNMENT_REASON_INVALID`), destino inválido
 *     (`INVALID_TARGET_RESTOCKER`), auto-desvío
 *     (`SELF_REASSIGNMENT_FORBIDDEN`), fechas fuera de rango
 *     (`PAST_SERVICE_DATE_FORBIDDEN`,
 *     `SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER`) e idempotencia offline
 *     (`INVALID_CLIENT_MUTATION_ID`).
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio, se elige llamando al constructor que describe el fallo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockRoutePlannerValidationException extends DomainException
{
    // ── 403 · autorización y perímetro ─────────────────────────────────────
    public const FORBIDDEN = 'FORBIDDEN';
    public const RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE = 'RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE';

    // ── 409 · conflicto con la verdad del día ──────────────────────────────
    public const RESTOCK_STOP_ALREADY_COMPLETED_TODAY = 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY';
    public const RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT = 'RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT';
    public const OVERRIDE_NOT_ACTIVE = 'OVERRIDE_NOT_ACTIVE';

    // ── 422 · contrato de agenda y excepción diaria ────────────────────────
    public const SCHEDULE_CADENCE_FIELDS_INVALID = 'SCHEDULE_CADENCE_FIELDS_INVALID';
    public const SKIP_REASON_INVALID = 'SKIP_REASON_INVALID';
    public const REASSIGNMENT_REASON_INVALID = 'REASSIGNMENT_REASON_INVALID';
    public const INVALID_TARGET_RESTOCKER = 'INVALID_TARGET_RESTOCKER';
    public const SELF_REASSIGNMENT_FORBIDDEN = 'SELF_REASSIGNMENT_FORBIDDEN';
    public const PAST_SERVICE_DATE_FORBIDDEN = 'PAST_SERVICE_DATE_FORBIDDEN';
    public const SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER = 'SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER';
    public const INVALID_CLIENT_MUTATION_ID = 'INVALID_CLIENT_MUTATION_ID';

    // ── 404 · recursos del módulo ──────────────────────────────────────────
    public const MACHINE_NOT_FOUND = 'MACHINE_NOT_FOUND';
    public const RESTOCKER_NOT_FOUND = 'RESTOCKER_NOT_FOUND';
    public const SCHEDULE_NOT_FOUND = 'SCHEDULE_NOT_FOUND';
    public const OVERRIDE_NOT_FOUND = 'OVERRIDE_NOT_FOUND';

    // ── 422 · máquina no operativa ─────────────────────────────────────────
    public const MACHINE_INACTIVE_OR_DELETED = 'MACHINE_INACTIVE_OR_DELETED';

    private string $errorCode;

    private int $httpStatusCode;

    private ?string $field;

    /** @var array<string, mixed> Datos de contexto útiles para el log, nunca secretos. */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatusCode,
        ?string $field = null,
        array $context = []
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->field = $field;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 403 · autorización y perímetro
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $allowedRoles
     */
    public static function roleForbidden(string $role, array $allowedRoles): self
    {
        return new self(
            self::FORBIDDEN,
            sprintf(
                'El rol «%s» no puede ejecutar esta operación del planificador; se exige uno de: %s (Art. V.4).',
                $role === '' ? 'anónimo' : $role,
                implode(', ', $allowedRoles)
            ),
            403,
            'role',
            ['role' => $role, 'allowed_roles' => $allowedRoles]
        );
    }

    public static function restockStopNotInRestockerRoute(int $machineId, int $restockerId): self
    {
        return new self(
            self::RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE,
            sprintf(
                'La máquina %d no pertenece a la ruta efectiva de hoy del reponedor %d (RF-02.2, Art. V.4).',
                $machineId,
                $restockerId
            ),
            403,
            'machine_id',
            ['machine_id' => $machineId, 'restocker_id' => $restockerId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409 · conflicto con la verdad del día
    // ─────────────────────────────────────────────────────────────────────

    public static function restockStopAlreadyCompletedToday(int $machineId, string $serviceDate): self
    {
        return new self(
            self::RESTOCK_STOP_ALREADY_COMPLETED_TODAY,
            sprintf(
                'La parada de la máquina %d ya figura completada el %s; no admite nuevas excepciones (RF-02.1, QA-6).',
                $machineId,
                $serviceDate
            ),
            409,
            'machine_id',
            ['machine_id' => $machineId, 'service_date' => $serviceDate]
        );
    }

    /**
     * @param list<string> $reasons
     */
    public static function restockStopBlockedBySanitaryOrSat(int $machineId, array $reasons): self
    {
        return new self(
            self::RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT,
            sprintf(
                'La máquina %d está segregada en «Bloqueadas por SAT / Cuarentena»: %s (RF-02.2, C1, Art. II).',
                $machineId,
                $reasons === [] ? 'bloqueo operativo vigente' : implode('; ', $reasons)
            ),
            409,
            'machine_id',
            ['machine_id' => $machineId, 'reasons' => $reasons]
        );
    }

    public static function overrideNotActive(int $overrideId, string $status): self
    {
        return new self(
            self::OVERRIDE_NOT_ACTIVE,
            sprintf(
                'La excepción diaria %d está en estado %s y ya no puede revocarse (RF-02.3).',
                $overrideId,
                $status
            ),
            409,
            'override_id',
            ['override_id' => $overrideId, 'status' => $status]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · agenda y excepción diaria
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     */
    public static function scheduleCadenceFieldsInvalid(string $reason, array $context = []): self
    {
        return new self(
            self::SCHEDULE_CADENCE_FIELDS_INVALID,
            'La agenda de reposición no es coherente: ' . $reason . ' (RF-01.1, QA-2).',
            422,
            'cadence_type',
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function skipReasonInvalid(string $reason, array $context = []): self
    {
        return new self(
            self::SKIP_REASON_INVALID,
            'La omisión de la parada no cumple el contrato: ' . $reason . ' (RF-02.2, Art. V.1).',
            422,
            'reason_text',
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function reassignmentReasonInvalid(string $reason, array $context = []): self
    {
        return new self(
            self::REASSIGNMENT_REASON_INVALID,
            'El desvío puntual no cumple el contrato: ' . $reason . ' (RF-02.3, QA-4).',
            422,
            'reassignment_reason_code',
            $context
        );
    }

    public static function invalidTargetRestocker(int $targetRestockerId): self
    {
        return new self(
            self::INVALID_TARGET_RESTOCKER,
            sprintf(
                'El reponedor destino %d no existe, está inactivo o no tiene rol RESTOCKER (RF-02.3).',
                $targetRestockerId
            ),
            422,
            'target_restocker_id',
            ['target_restocker_id' => $targetRestockerId]
        );
    }

    public static function selfReassignmentForbidden(int $restockerId, string $serviceDate): self
    {
        return new self(
            self::SELF_REASSIGNMENT_FORBIDDEN,
            sprintf(
                'El reponedor %d ya es el propietario efectivo de la parada el %s; el auto-desvío está prohibido (QA-4).',
                $restockerId,
                $serviceDate
            ),
            422,
            'target_restocker_id',
            ['target_restocker_id' => $restockerId, 'service_date' => $serviceDate]
        );
    }

    public static function pastServiceDateForbidden(string $serviceDate, string $today): self
    {
        return new self(
            self::PAST_SERVICE_DATE_FORBIDDEN,
            sprintf(
                'La fecha de servicio %s es anterior a hoy (%s) en Europe/Madrid; no admite excepciones (RF-02.2, QA-4).',
                $serviceDate,
                $today
            ),
            422,
            'service_date',
            ['service_date' => $serviceDate, 'today' => $today]
        );
    }

    public static function skipFutureDateForbiddenForRestocker(string $serviceDate, string $today): self
    {
        return new self(
            self::SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER,
            sprintf(
                'El reponedor solo puede omitir paradas de hoy (%s); se solicitó %s (RF-02.2, QA-4).',
                $today,
                $serviceDate
            ),
            422,
            'service_date',
            ['service_date' => $serviceDate, 'today' => $today]
        );
    }

    public static function machineNotFound(int $machineId): self
    {
        return new self(
            self::MACHINE_NOT_FOUND,
            sprintf('La máquina %d no existe o está dada de baja (RF-01.3).', $machineId),
            404,
            'machine_id',
            ['machine_id' => $machineId]
        );
    }

    public static function restockerNotFound(int $restockerId): self
    {
        return new self(
            self::RESTOCKER_NOT_FOUND,
            sprintf('El reponedor %d no existe o no está activo (RF-03.1).', $restockerId),
            404,
            'restocker_id',
            ['restocker_id' => $restockerId]
        );
    }

    public static function scheduleNotFound(int $scheduleId): self
    {
        return new self(
            self::SCHEDULE_NOT_FOUND,
            sprintf('La agenda %d no existe o ya está cerrada (RF-01.3, QA-2).', $scheduleId),
            404,
            'schedule_id',
            ['schedule_id' => $scheduleId]
        );
    }

    public static function overrideNotFound(int $overrideId): self
    {
        return new self(
            self::OVERRIDE_NOT_FOUND,
            sprintf('La excepción diaria %d no existe (RF-02.3).', $overrideId),
            404,
            'override_id',
            ['override_id' => $overrideId]
        );
    }

    public static function machineInactiveOrDeleted(int $machineId): self
    {
        return new self(
            self::MACHINE_INACTIVE_OR_DELETED,
            sprintf(
                'La máquina %d está inactiva, eliminada o pertenece a una sede inactiva (RF-01.3, QA-6).',
                $machineId
            ),
            422,
            'machine_id',
            ['machine_id' => $machineId]
        );
    }

    public static function invalidClientMutationId(string $reason, ?int $length = null): self
    {
        return new self(
            self::INVALID_CLIENT_MUTATION_ID,
            'El identificador de mutación offline no es válido: ' . $reason . ' (RF-02.1, C3).',
            422,
            'client_mutation_id',
            ['length' => $length]
        );
    }
}
