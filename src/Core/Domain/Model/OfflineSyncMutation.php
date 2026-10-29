<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * OfflineSyncMutation
 *
 * Entidad de dominio inmutable que representa una mutación de parada
 * sincronizada desde la cola sin conexión del técnico (Módulo 24, RF-04.2,
 * RF-04.3, RF-05.3 y RF-06.4).
 *
 * Cada fila de `offline_sync_mutations` es la prueba de que una operación
 * ejecutada in situ llegó al servidor una sola vez, con su doble sello temporal
 * (`client_occurred_at`, `effective_occurred_at`, `synced_at`) y su resultado
 * (`APPLIED`, `CONFLICT_DUMPED` o `DEACTIVATED_ACCOUNT_DUMP`, Art. III.3).
 *
 * Invariantes que esta entidad hace explícitos:
 *   - Los tres catálogos son cerrados y espejan los `CHECK` del motor
 *     (`chk_offline_sync_stop_type`, `chk_offline_sync_operation_type` y
 *     `chk_offline_sync_outcome_status`): una operación fuera de las nueve
 *     autorizadas en `D1` no puede existir ni en memoria.
 *   - El identificador de operación respeta el mismo alfabeto y longitud que
 *     `chk_offline_sync_mutation_id_format` (8–64 caracteres de
 *     `^[A-Za-z0-9_.:-]+$`), porque es la clave de idempotencia por técnico
 *     (`uq_offline_sync_tech_mutation`).
 *   - La fila nunca se borra físicamente: el disparador
 *     `trg_no_delete_offline_sync_mutations` lo impide en el motor (Art. III.1).
 *   - El ajuste de reloj no se decide aquí: lo resuelve `OfflineSyncMetadata`
 *     con la tolerancia de 5 minutos y el máximo de 12 horas de `Δt` (§5.1 de
 *     los contratos técnicos). Esta entidad sólo conserva el veredicto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class OfflineSyncMutation
{
    /** Identificador de las filas que aún no han sido persistidas. */
    public const UNPERSISTED_ID = 0;

    public const STOP_TYPE_INCIDENT = 'INCIDENT';
    public const STOP_TYPE_PREVENTIVE_ORDER = 'PREVENTIVE_ORDER';

    /** @var list<string> Tipos de parada admitidos por el motor (D1). */
    public const STOP_TYPES = [
        self::STOP_TYPE_INCIDENT,
        self::STOP_TYPE_PREVENTIVE_ORDER,
    ];

    public const OPERATION_START_INCIDENT = 'START_INCIDENT';
    public const OPERATION_RESUME_INCIDENT_PENDING_INFO = 'RESUME_INCIDENT_PENDING_INFO';
    public const OPERATION_PAUSE_INCIDENT_PARTS = 'PAUSE_INCIDENT_PARTS';
    public const OPERATION_PAUSE_INCIDENT_PENDING_INFO = 'PAUSE_INCIDENT_PENDING_INFO';
    public const OPERATION_RESOLVE_INCIDENT = 'RESOLVE_INCIDENT';
    public const OPERATION_START_PREVENTIVE = 'START_PREVENTIVE';
    public const OPERATION_COMPLETE_PREVENTIVE = 'COMPLETE_PREVENTIVE';
    public const OPERATION_REINSPECT_PREVENTIVE = 'REINSPECT_PREVENTIVE';
    public const OPERATION_ADD_INCIDENT_COMMENT = 'ADD_INCIDENT_COMMENT';

    /**
     * @var list<string> Las nueve operaciones encolables sin conexión (`D1`).
     */
    public const OPERATIONS = [
        self::OPERATION_START_INCIDENT,
        self::OPERATION_RESUME_INCIDENT_PENDING_INFO,
        self::OPERATION_PAUSE_INCIDENT_PARTS,
        self::OPERATION_PAUSE_INCIDENT_PENDING_INFO,
        self::OPERATION_RESOLVE_INCIDENT,
        self::OPERATION_START_PREVENTIVE,
        self::OPERATION_COMPLETE_PREVENTIVE,
        self::OPERATION_REINSPECT_PREVENTIVE,
        self::OPERATION_ADD_INCIDENT_COMMENT,
    ];

    /**
     * @var list<string> Operaciones que mueven el estado de la parada.
     *
     * `ADD_INCIDENT_COMMENT` queda fuera a propósito: es independiente de la
     * cadena y su conflicto no bloquea la resolución posterior (`QA 3`).
     */
    public const STATE_TRANSITION_OPERATIONS = [
        self::OPERATION_START_INCIDENT,
        self::OPERATION_RESUME_INCIDENT_PENDING_INFO,
        self::OPERATION_PAUSE_INCIDENT_PARTS,
        self::OPERATION_PAUSE_INCIDENT_PENDING_INFO,
        self::OPERATION_RESOLVE_INCIDENT,
        self::OPERATION_START_PREVENTIVE,
        self::OPERATION_COMPLETE_PREVENTIVE,
        self::OPERATION_REINSPECT_PREVENTIVE,
    ];

    public const OUTCOME_APPLIED = 'APPLIED';
    public const OUTCOME_CONFLICT_DUMPED = 'CONFLICT_DUMPED';
    public const OUTCOME_DEACTIVATED_ACCOUNT_DUMP = 'DEACTIVATED_ACCOUNT_DUMP';

    /** @var list<string> Resultados admitidos por el motor. */
    public const OUTCOMES = [
        self::OUTCOME_APPLIED,
        self::OUTCOME_CONFLICT_DUMPED,
        self::OUTCOME_DEACTIVATED_ACCOUNT_DUMP,
    ];

    /** Longitud mínima del identificador de operación (contrato §6.2). */
    public const MIN_MUTATION_ID_LENGTH = 8;

    /** Longitud máxima del identificador de operación (contrato §6.2). */
    public const MAX_MUTATION_ID_LENGTH = 64;

    /** Alfabeto admitido por `chk_offline_sync_mutation_id_format`. */
    public const MUTATION_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /**
     * @param int $id `UNPERSISTED_ID` hasta que el repositorio asigne la
     *        identidad de la fila.
     * @param array<string, mixed> $responsePayload Cuerpo devuelto al cliente
     *        en la primera aplicación; la repetición idempotente lo reproduce
     *        tal cual con `idempotent_replay = true` (RF-04.2).
     */
    public function __construct(
        public readonly int $id,
        public readonly int $technicianId,
        public readonly string $clientMutationId,
        public readonly string $stopType,
        public readonly int $stopId,
        public readonly string $operationType,
        public readonly string $outcomeStatus,
        public readonly string $clientOccurredAt,
        public readonly ?string $chainStartedAt,
        public readonly string $effectiveOccurredAt,
        public readonly string $syncedAt,
        public readonly bool $clockSkewAdjusted,
        public readonly bool $sanitarySealConfirmed,
        public readonly int $responseHttpStatus,
        public readonly array $responsePayload,
        public readonly string $createdAt
    ) {
        if ($id < 0) {
            throw new InvalidArgumentException('El identificador de la mutación no puede ser negativo.');
        }

        if ($technicianId <= 0) {
            throw new InvalidArgumentException('La mutación sin conexión exige un técnico propietario.');
        }

        if (!self::isValidMutationId($clientMutationId)) {
            throw new InvalidArgumentException(
                'El identificador de la mutación debe tener entre '
                . self::MIN_MUTATION_ID_LENGTH . ' y ' . self::MAX_MUTATION_ID_LENGTH
                . ' caracteres de [A-Za-z0-9_.:-].'
            );
        }

        if (!in_array($stopType, self::STOP_TYPES, true)) {
            throw new InvalidArgumentException("Tipo de parada no admitido: {$stopType}.");
        }

        if ($stopId <= 0) {
            throw new InvalidArgumentException('La parada referenciada no puede ser nula.');
        }

        if (!in_array($operationType, self::OPERATIONS, true)) {
            throw new InvalidArgumentException("Operación sin conexión no autorizada: {$operationType}.");
        }

        if (!in_array($outcomeStatus, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Resultado de sincronización no admitido: {$outcomeStatus}.");
        }

        if (trim($clientOccurredAt) === '' || trim($effectiveOccurredAt) === '' || trim($syncedAt) === '') {
            throw new InvalidArgumentException('La mutación sin conexión exige sus tres sellos temporales.');
        }

        if ($responseHttpStatus < 100 || $responseHttpStatus > 599) {
            throw new InvalidArgumentException("Código HTTP de respuesta no válido: {$responseHttpStatus}.");
        }
    }

    /**
     * Formato canónico del identificador de operación, compartido por el
     * cliente, el value object y el motor.
     */
    public static function isValidMutationId(string $clientMutationId): bool
    {
        $value = trim($clientMutationId);
        $length = mb_strlen($value);

        return $length >= self::MIN_MUTATION_ID_LENGTH
            && $length <= self::MAX_MUTATION_ID_LENGTH
            && preg_match(self::MUTATION_ID_PATTERN, $value) === 1;
    }

    /**
     * Transición de estado encolada: su conflicto arrastra a las posteriores de
     * la misma parada, mientras que un comentario intermedio queda aislado
     * (`EARS 05.1` apartado 2, `QA 3`).
     */
    public function isStateTransition(): bool
    {
        return in_array($this->operationType, self::STATE_TRANSITION_OPERATIONS, true);
    }

    public function isApplied(): bool
    {
        return $this->outcomeStatus === self::OUTCOME_APPLIED;
    }

    public function isConflictDumped(): bool
    {
        return $this->outcomeStatus === self::OUTCOME_CONFLICT_DUMPED;
    }

    public function isDeactivatedAccountDump(): bool
    {
        return $this->outcomeStatus === self::OUTCOME_DEACTIVATED_ACCOUNT_DUMP;
    }

    /**
     * Proyección con los nombres de columna de `offline_sync_mutations`.
     *
     * @return array<string, int|string|bool|array<string, mixed>|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technicianId,
            'client_mutation_id' => $this->clientMutationId,
            'stop_type' => $this->stopType,
            'stop_id' => $this->stopId,
            'operation_type' => $this->operationType,
            'outcome_status' => $this->outcomeStatus,
            'client_occurred_at' => $this->clientOccurredAt,
            'chain_started_at' => $this->chainStartedAt,
            'effective_occurred_at' => $this->effectiveOccurredAt,
            'synced_at' => $this->syncedAt,
            'clock_skew_adjusted' => $this->clockSkewAdjusted,
            'sanitary_seal_confirmed' => $this->sanitarySealConfirmed,
            'response_http_status' => $this->responseHttpStatus,
            'response_payload' => $this->responsePayload,
            'created_at' => $this->createdAt,
        ];
    }
}
