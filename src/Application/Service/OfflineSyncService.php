<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use VendGuard\Application\DTO\OfflineBootstrapSnapshotDto;
use VendGuard\Core\Domain\Exception\OfflineSyncValidationException;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\Repository\OfflineSyncMutationRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\OfflineSyncMetadata;

/**
 * OfflineSyncService
 *
 * Orquestador de los casos de uso del Modo Sin Conexión del técnico de campo
 * (Módulo 24, RF-02.1, RF-04.2, RF-04.3, RF-05.1 a RF-05.4 y RF-06.4).
 *
 * ## Qué decide este servicio y qué no
 *
 * El servicio concentra las reglas que **no** pueden vivir en el controlador ni
 * en el navegador: la idempotencia por `(technician_id, client_mutation_id)`, el
 * sello temporal efectivo con preservación de Δt, el precinto sanitario
 * obligatorio del Art. II, la retención de la cuarentena sanitaria y la
 * excepción de auditoría del volcado de conflictos. La escritura del cambio de
 * estado sigue perteneciendo al dominio: el llamador entrega un *callback* que
 * ejecuta la transición real y devuelve su resultado. Así el mismo envoltorio
 * sirve para las nueve operaciones de `D1` sin duplicar la lógica de cada una.
 *
 * ## Contrato del callback de transición
 *
 * Recibe un contexto con:
 *
 *   - `is_offline` (`bool`): la operación llegó desde la cola del dispositivo.
 *   - `effective_occurred_at` (`DateTimeImmutable`): instante real corregido que
 *     debe grabarse como hora de la intervención in situ (`RF-04.3`, `QA 4`).
 *   - `synced_at` (`DateTimeImmutable`): instante de recepción en el servidor.
 *   - `clock_skew_adjusted` (`bool`): el reloj del teléfono estaba fuera de rango.
 *   - `sanitary_seal_confirmed` (`bool`): precinto físico confirmado in situ.
 *   - `preserve_sanitary_quarantine` (`bool`): la máquina ya estaba en
 *     `SANITARY_QUARANTINE`; la resolución no puede levantarla (Art. II).
 *   - `skip_refund_block` (`bool`): el reintegro ya fue regularizado por
 *     Coordinación; no se sobrescribe y el hallazgo se vuelca como Nota Interna.
 *
 * Devuelve un `array` con:
 *
 *   - `payload` (`array`): cuerpo de la respuesta al cliente. Si se omite, se
 *     usa el propio array descontando las claves de control.
 *   - `http_status` (`int`, opcional): 200 por defecto.
 *   - `machine_status_after` (`string|null`, obligatorio si se retiene la
 *     cuarentena): estado final de la máquina, para certificar el blindaje.
 *   - `refund_collision_note_id` (`int`, obligatorio si hubo colisión de
 *     reintegro): identificador de la Nota Interna con el dictamen de efectivo.
 *
 * ## Invariantes que el servicio hace cumplir
 *
 *  - Un reenvío con la misma clave devuelve el `response_payload` original con
 *    `idempotent_replay = true` y no vuelve a ejecutar el dominio (`RF-04.2`).
 *  - Una operación que ya quedó volcada como conflicto no se reaplica: devuelve
 *    `409 OFFLINE_MUTATION_CONFLICT`, porque el trabajo ya está en el expediente.
 *  - Sobre `FOOD_PERISHABLE`, toda pausa o riesgo sanitario encolado exige
 *    `sanitary_seal_confirmed = true`; sin él se responde
 *    `422 SANITARY_SEAL_CONFIRMATION_REQUIRED` **antes** de escribir nada
 *    (`EARS 03.4`, `QA 1`, Art. II).
 *  - Si la máquina perecedera ya estaba en cuarentena, el callback debe
 *    confirmar con `machine_status_after` que sigue en cuarentena; si no, el
 *    servicio aborta en lugar de dar por buena una liberación sanitaria
 *    (`EARS 05.4`, Art. II).
 *  - Si el reintegro ya fue regularizado, el callback debe certificar la Nota
 *    Interna con el dictamen; sin ella no hay cierre válido, porque el hallazgo
 *    de efectivo no puede perderse (`EARS 05.2`, `QA 2`).
 *
 * ## Sobre la carrera entre dos reintentos simultáneos
 *
 * La comprobación previa cubre el reenvío normal. Si dos peticiones con la
 * misma clave entran a la vez, la clave única del motor decide cuál persiste y
 * `recordAppliedMutation()` devuelve la fila ganadora; el servicio la reconoce
 * por su sello `synced_at` y responde como repetición idempotente. La segunda
 * transición la frena el propio estado de la parada (una avería ya resuelta no
 * vuelve a resolverse), que es la guarda constitucional del dominio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, POO limpia, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OfflineSyncService
{
    /** Estado de máquina que la resolución offline nunca puede levantar (Art. II). */
    public const MACHINE_STATUS_SANITARY_QUARANTINE = 'SANITARY_QUARANTINE';

    /** Pausas sobre máquina perecedera: riesgo sanitario por definición (Art. II). */
    private const PAUSE_OPERATIONS = [
        OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
        OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PENDING_INFO,
    ];

    /** @var array<string, list<string>> Operaciones válidas por tipo de parada. */
    private const OPERATIONS_BY_STOP_TYPE = [
        OfflineSyncMutation::STOP_TYPE_INCIDENT => [
            OfflineSyncMutation::OPERATION_START_INCIDENT,
            OfflineSyncMutation::OPERATION_RESUME_INCIDENT_PENDING_INFO,
            OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
            OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PENDING_INFO,
            OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
            OfflineSyncMutation::OPERATION_ADD_INCIDENT_COMMENT,
        ],
        OfflineSyncMutation::STOP_TYPE_PREVENTIVE_ORDER => [
            OfflineSyncMutation::OPERATION_START_PREVENTIVE,
            OfflineSyncMutation::OPERATION_COMPLETE_PREVENTIVE,
            OfflineSyncMutation::OPERATION_REINSPECT_PREVENTIVE,
        ],
    ];

    /** Claves del array devuelto por el callback que no forman parte del cuerpo. */
    private const TRANSITION_CONTROL_KEYS = [
        'payload',
        'http_status',
        'machine_status_after',
        'refund_collision_note_id',
    ];

    public function __construct(
        private readonly OfflineSyncMutationRepositoryInterface $repository
    ) {
    }

    /**
     * Arma el paquete consolidado de la ruta del técnico autenticado (`RF-02.1`).
     *
     * La identidad y el instante de sincronización se fijan aquí, no en el
     * repositorio: el paquete no puede mezclar dos cuentas ni declarar una
     * antigüedad distinta de la del momento en que se sirvió (Art. V.4, `D6`).
     */
    public function getBootstrapSnapshot(
        int $technicianId,
        string $technicianName,
        string $technicianEmail,
        ?DateTimeImmutable $syncedAt = null
    ): OfflineBootstrapSnapshotDto {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('El paquete de ruta exige un técnico autenticado.');
        }

        $snapshot = $this->repository->fetchBootstrapSnapshotForTechnician($technicianId);

        return new OfflineBootstrapSnapshotDto(
            technicianId: $technicianId,
            technicianName: $technicianName,
            technicianEmail: $technicianEmail,
            syncedAt: $this->now($syncedAt),
            route: is_array($snapshot['route'] ?? null) ? $snapshot['route'] : [],
            preventiveOrders: is_array($snapshot['preventive_orders'] ?? null)
                ? $snapshot['preventive_orders']
                : [],
            preventiveChecklistsByOrder: is_array($snapshot['preventive_checklists_by_order'] ?? null)
                ? $snapshot['preventive_checklists_by_order']
                : [],
            sparePartsCatalogByMachine: is_array($snapshot['spare_parts_catalog_by_machine'] ?? null)
                ? $snapshot['spare_parts_catalog_by_machine']
                : [],
            vanStock: is_array($snapshot['van_stock'] ?? null) ? $snapshot['van_stock'] : [],
        );
    }

    /**
     * Ejecuta una operación de parada de forma idempotente y con doble sello.
     *
     * Sin `$metadata` (petición online ordinaria) el callback recibe
     * `is_offline = false` y no se escribe nada en el libro de sincronización:
     * el comportamiento es exactamente el de antes del Módulo 24.
     *
     * @param array{
     *   machine_is_perishable?: bool,
     *   machine_status?: string|null,
     *   operation_has_sanitary_risk?: bool,
     *   previous_milestone_at?: DateTimeImmutable|null,
     *   refund_already_regularized?: bool
     * } $stopFacts Hechos de la parada ya leídos por el llamador (máquina,
     *        estado sanitario, hito previo del expediente y estado del reintegro).
     * @param callable(array<string, mixed>): array<string, mixed> $applyTransition
     *        Transición de dominio real; su contrato está en la cabecera de la clase.
     * @return array{
     *   http_status: int,
     *   payload: array<string, mixed>,
     *   idempotent_replay: bool,
     *   offline_sync: array<string, mixed>|null,
     *   partial_refund_collision_dumped: bool,
     *   sanitary_quarantine_retained: bool
     * }
     */
    public function executeIdempotentMutation(
        int $technicianId,
        string $stopType,
        int $stopId,
        string $operationType,
        ?OfflineSyncMetadata $metadata,
        array $stopFacts,
        callable $applyTransition,
        ?DateTimeImmutable $now = null
    ): array {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('La sincronización sin conexión exige un técnico propietario.');
        }

        $this->assertCoherentStop($stopType, $operationType, $stopId);

        $syncedAt = $this->now($now);
        $preserveQuarantine = $this->shouldPreserveQuarantine($stopType, $operationType, $stopFacts);

        if ($metadata === null) {
            $transition = $this->invokeTransition($applyTransition, [
                'is_offline' => false,
                'effective_occurred_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'clock_skew_adjusted' => false,
                'sanitary_seal_confirmed' => false,
                'preserve_sanitary_quarantine' => $preserveQuarantine,
                'skip_refund_block' => false,
            ]);

            return [
                'http_status' => $transition['http_status'],
                'payload' => $transition['payload'],
                'idempotent_replay' => false,
                'offline_sync' => null,
                'partial_refund_collision_dumped' => false,
                'sanitary_quarantine_retained' => $preserveQuarantine,
            ];
        }

        // 1. Idempotencia (RF-04.2). El reintento devuelve el cuerpo original y
        //    no vuelve a ejecutar el dominio.
        $existing = $this->repository->findByTechnicianAndMutationId(
            $technicianId,
            $metadata->clientMutationId
        );

        if ($existing !== null) {
            if (!$existing->isApplied()) {
                throw OfflineSyncValidationException::mutationConflict(
                    'Esa operación ya quedó registrada como volcado de auditoría y no puede reaplicarse.'
                );
            }

            return $this->replayResult($existing);
        }

        // 2. Precinto sanitario in situ (EARS 03.4, QA 1, Art. II). Se comprueba
        //    antes de tocar la base: una pausa sobre perecederos sin precinto no
        //    debe dejar rastro parcial en el expediente.
        $this->assertSanitarySeal($metadata, $operationType, $stopFacts);

        // 3. Sello temporal efectivo y preservación de Δt (RF-04.3, QA 4).
        $resolved = $metadata->resolveEffectiveTimestamp(
            $this->previousMilestone($stopFacts, $syncedAt),
            $syncedAt
        );

        $partialRefundCollision = $operationType === OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT
            && (bool)($stopFacts['refund_already_regularized'] ?? false);

        // 4. Transición real del dominio con el contexto ya corregido.
        $transition = $this->invokeTransition($applyTransition, [
            'is_offline' => true,
            'effective_occurred_at' => $resolved['effective_occurred_at'],
            'synced_at' => $syncedAt,
            'clock_skew_adjusted' => $resolved['clock_skew_adjusted'],
            'sanitary_seal_confirmed' => $metadata->sanitarySealConfirmed,
            'preserve_sanitary_quarantine' => $preserveQuarantine,
            'skip_refund_block' => $partialRefundCollision,
        ]);

        // 5. Blindaje sanitario (EARS 05.4): la avería se cierra, la cuarentena
        //    no se levanta. El cierre tardío no puede reabrir la máquina al público.
        if ($preserveQuarantine) {
            if ($transition['machine_status_after'] !== self::MACHINE_STATUS_SANITARY_QUARANTINE) {
                throw new DomainException(
                    'La resolución sin conexión no puede levantar la cuarentena sanitaria de una máquina '
                    . 'de alimentos perecederos (Art. II). El cierre queda abortado para revisión.'
                );
            }
        }

        // 6. Colisión parcial de reintegro (EARS 05.2, QA 2): el hallazgo del
        //    técnico debe quedar como Nota Interna; sin ella el cierre no vale.
        if ($partialRefundCollision) {
            if (($transition['refund_collision_note_id'] ?? 0) <= 0) {
                throw new DomainException(
                    'El cierre con reintegro ya regularizado debe volcar el dictamen de efectivo como Nota '
                    . 'Interna de Taller antes de completarse (QA 2, Art. III.3).'
                );
            }
        }

        $envelope = $this->buildEnvelope(
            $metadata,
            $resolved,
            $syncedAt,
            $preserveQuarantine,
            $partialRefundCollision
        );
        $payload = $transition['payload'];
        $payload['offline_sync'] = $envelope;

        $mutation = new OfflineSyncMutation(
            id: OfflineSyncMutation::UNPERSISTED_ID,
            technicianId: $technicianId,
            clientMutationId: $metadata->clientMutationId,
            stopType: $stopType,
            stopId: $stopId,
            operationType: $operationType,
            outcomeStatus: OfflineSyncMutation::OUTCOME_APPLIED,
            clientOccurredAt: $this->format($metadata->clientOccurredAt),
            chainStartedAt: $metadata->chainStartedAt !== null
                ? $this->format($metadata->chainStartedAt)
                : null,
            effectiveOccurredAt: $this->format($resolved['effective_occurred_at']),
            syncedAt: $this->format($syncedAt),
            clockSkewAdjusted: $resolved['clock_skew_adjusted'],
            sanitarySealConfirmed: $metadata->sanitarySealConfirmed,
            responseHttpStatus: $transition['http_status'],
            responsePayload: $payload,
            createdAt: $this->format($syncedAt)
        );

        $persisted = $this->repository->recordAppliedMutation($mutation);
        $persistedEnvelope = $this->envelopeFrom($persisted);

        // 7. Si el motor devolvió otra fila, el reintento simultáneo llegó antes:
        //    se responde como repetición idempotente, nunca como una segunda
        //    aplicación silenciosa.
        $replayed = ($persistedEnvelope['synced_at'] ?? null) !== $this->format($syncedAt);

        return [
            'http_status' => $persisted->responseHttpStatus,
            'payload' => $persisted->responsePayload,
            'idempotent_replay' => $replayed,
            'offline_sync' => $persistedEnvelope === [] ? null : $persistedEnvelope,
            'partial_refund_collision_dumped' => (bool)(
                $persistedEnvelope['partial_refund_collision_dumped'] ?? false
            ),
            'sanitary_quarantine_retained' => (bool)(
                $persistedEnvelope['sanitary_quarantine_retained'] ?? false
            ),
        ];
    }

    /**
     * Vuelca en un solo toque el trabajo de una acción rechazada (`EARS 05.3`).
     *
     * No aplica la transición ni cambia titularidad o estado: la parada se queda
     * como está y el trabajo se archiva como Nota Interna de Taller (averías) o
     * anexo de auditoría (`preventive_orders.notes`). Es la excepción del
     * Art. III.3 que impide el descarte silencioso del trabajo del técnico.
     *
     * @param array<string, mixed> $workSummary Diagnóstico, acción, pausa,
     *        piezas y constancia de firma recogidos in situ.
     * @return array{
     *   http_status: int,
     *   payload: array<string, mixed>,
     *   idempotent_replay: bool,
     *   internal_comment_id: int|null
     * }
     */
    public function dumpConflict(
        int $technicianId,
        OfflineSyncMetadata $metadata,
        string $stopType,
        int $stopId,
        string $operationType,
        string $conflictReason,
        array $workSummary,
        ?string $photoPath = null,
        ?DateTimeImmutable $now = null
    ): array {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('El volcado de conflicto exige un técnico propietario.');
        }

        $this->assertCoherentStop($stopType, $operationType, $stopId);

        $reason = trim($conflictReason);
        if ($reason === '') {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                'falta `conflict_reason`: hay que dejar constancia de por qué se rechazó la parada.'
            );
        }

        $this->assertWorkSummary($workSummary);

        $existing = $this->repository->findByTechnicianAndMutationId(
            $technicianId,
            $metadata->clientMutationId
        );

        if ($existing !== null) {
            return [
                'http_status' => $existing->responseHttpStatus,
                'payload' => $existing->responsePayload,
                'idempotent_replay' => true,
                'internal_comment_id' => isset($existing->responsePayload['internal_comment_id'])
                    ? (int)$existing->responsePayload['internal_comment_id']
                    : null,
            ];
        }

        $syncedAt = $this->now($now);
        $resolved = $metadata->resolveEffectiveTimestamp(
            $metadata->chainStartedAt ?? $metadata->clientOccurredAt,
            $syncedAt
        );

        $mutation = new OfflineSyncMutation(
            id: OfflineSyncMutation::UNPERSISTED_ID,
            technicianId: $technicianId,
            clientMutationId: $metadata->clientMutationId,
            stopType: $stopType,
            stopId: $stopId,
            operationType: $operationType,
            outcomeStatus: OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
            clientOccurredAt: $this->format($metadata->clientOccurredAt),
            chainStartedAt: $metadata->chainStartedAt !== null
                ? $this->format($metadata->chainStartedAt)
                : null,
            effectiveOccurredAt: $this->format($resolved['effective_occurred_at']),
            syncedAt: $this->format($syncedAt),
            clockSkewAdjusted: $resolved['clock_skew_adjusted'],
            sanitarySealConfirmed: $metadata->sanitarySealConfirmed,
            responseHttpStatus: 200,
            responsePayload: [],
            createdAt: $this->format($syncedAt)
        );

        $recorded = $this->repository->recordConflictDump(
            mutation: $mutation,
            conflictReason: $reason,
            workSummary: $workSummary,
            photoPath: $photoPath
        );

        /** @var OfflineSyncMutation $persisted */
        $persisted = $recorded['mutation'];

        return [
            'http_status' => $persisted->responseHttpStatus,
            'payload' => $persisted->responsePayload,
            'idempotent_replay' => ($persisted->responsePayload['synced_at'] ?? null) !== $this->format($syncedAt),
            'internal_comment_id' => isset($recorded['internal_comment_id'])
                ? (int)$recorded['internal_comment_id']
                : null,
        ];
    }

    /**
     * Volcado atómico de la cola pendiente de una cuenta desactivada
     * (`EARS 06.4`, `QA 5`).
     *
     * No aplica transiciones ni altera titularidad: cada elemento se archiva como
     * Nota Interna de Taller o anotación de auditoría, de modo que el dispositivo
     * pueda liberarse sin perder el trabajo del técnico. La comprobación de que la
     * cuenta está realmente desactivada corresponde al controlador, que es quien
     * puede leer `users.is_active` (403 `ACCOUNT_DEACTIVATED` /
     * 409 `ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC`).
     *
     * @param list<array<string, mixed>> $items Cada elemento con
     *        `client_mutation_id`, `client_occurred_at`, `stop_type`, `stop_id`,
     *        `operation_type` y `work_summary`.
     * @return array{
     *   http_status: int,
     *   payload: array<string, mixed>,
     *   offline_sync: null
     * }
     */
    public function dumpDeactivatedAccountQueue(
        int $technicianId,
        array $items,
        ?DateTimeImmutable $now = null
    ): array {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('El volcado por baja de cuenta exige un técnico propietario.');
        }

        $syncedAt = $this->now($now);
        $mutations = [];
        $summaries = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                    'el elemento ' . ($index + 1) . ' del lote no es un objeto.'
                );
            }

            $metadata = OfflineSyncMetadata::fromArray($item);
            if ($metadata === null) {
                throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                    'el elemento ' . ($index + 1) . ' del lote no trae `client_mutation_id`.'
                );
            }

            $stopType = trim((string)($item['stop_type'] ?? ''));
            $operationType = trim((string)($item['operation_type'] ?? ''));
            $stopId = isset($item['stop_id']) ? (int)$item['stop_id'] : 0;
            $workSummary = $item['work_summary'] ?? null;

            $this->assertCoherentStop($stopType, $operationType, $stopId);

            if (!is_array($workSummary)) {
                throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                    'el elemento ' . ($index + 1) . ' del lote no trae `work_summary`.'
                );
            }

            $this->assertWorkSummary($workSummary);

            $resolved = $metadata->resolveEffectiveTimestamp(
                $metadata->chainStartedAt ?? $metadata->clientOccurredAt,
                $syncedAt
            );

            $mutations[] = new OfflineSyncMutation(
                id: OfflineSyncMutation::UNPERSISTED_ID,
                technicianId: $technicianId,
                clientMutationId: $metadata->clientMutationId,
                stopType: $stopType,
                stopId: $stopId,
                operationType: $operationType,
                outcomeStatus: OfflineSyncMutation::OUTCOME_DEACTIVATED_ACCOUNT_DUMP,
                clientOccurredAt: $this->format($metadata->clientOccurredAt),
                chainStartedAt: $metadata->chainStartedAt !== null
                    ? $this->format($metadata->chainStartedAt)
                    : null,
                effectiveOccurredAt: $this->format($resolved['effective_occurred_at']),
                syncedAt: $this->format($syncedAt),
                clockSkewAdjusted: $resolved['clock_skew_adjusted'],
                sanitarySealConfirmed: $metadata->sanitarySealConfirmed,
                responseHttpStatus: 200,
                responsePayload: ['work_summary' => $workSummary],
                createdAt: $this->format($syncedAt)
            );

            $summaries[] = [
                'client_mutation_id' => $metadata->clientMutationId,
                'stop_type' => $stopType,
                'stop_id' => $stopId,
                'operation_type' => $operationType,
                'outcome_status' => OfflineSyncMutation::OUTCOME_DEACTIVATED_ACCOUNT_DUMP,
            ];
        }

        $recorded = $mutations === []
            ? ['dumped' => 0, 'mutations' => [], 'internal_comment_ids' => []]
            : $this->repository->recordDeactivatedAccountDumpBatch($technicianId, $mutations);

        return [
            'http_status' => 200,
            'payload' => [
                'dumped' => (int)$recorded['dumped'],
                'clear_local_storage' => true,
                'items' => $summaries,
                'message' => 'El trabajo pendiente se ha registrado como constancia de auditoría. '
                    . 'Puedes cerrar la sesión en este dispositivo.',
            ],
            'offline_sync' => null,
        ];
    }

    // ---------------------------------------------------------------------
    // Guardas de dominio
    // ---------------------------------------------------------------------

    /**
     * El tipo de parada y la operación deben pertenecer al mismo flujo (`D1`).
     *
     * Sin esta guarda, un `START_PREVENTIVE` sobre una avería escribiría en la
     * tabla equivocada o en ninguna, y el error aparecería lejos del origen.
     */
    private function assertCoherentStop(string $stopType, string $operationType, int $stopId): void
    {
        if ($stopId <= 0) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                '`stop_id` debe identificar una parada existente.'
            );
        }

        if (!in_array($stopType, OfflineSyncMutation::STOP_TYPES, true)) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                "`stop_type` no admitido: {$stopType}."
            );
        }

        if (!in_array($operationType, OfflineSyncMutation::OPERATIONS, true)) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                "`operation_type` no autorizada sin conexión: {$operationType}."
            );
        }

        $allowed = self::OPERATIONS_BY_STOP_TYPE[$stopType] ?? [];
        if (!in_array($operationType, $allowed, true)) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                "La operación {$operationType} no pertenece a una parada de tipo {$stopType}."
            );
        }
    }

    /**
     * Precinto sanitario obligatorio sobre máquinas de alimentos perecederos.
     *
     * Cualquier pausa en una máquina perecedera se considera riesgo sanitario
     * aunque el llamador no marque la bandera: el frío se interrumpe igual y el
     * Art. II no admite excepciones por omisión.
     *
     * @param array<string, mixed> $stopFacts
     */
    private function assertSanitarySeal(
        OfflineSyncMetadata $metadata,
        string $operationType,
        array $stopFacts
    ): void {
        if (!(bool)($stopFacts['machine_is_perishable'] ?? false)) {
            return;
        }

        $risk = (bool)($stopFacts['operation_has_sanitary_risk'] ?? false)
            || in_array($operationType, self::PAUSE_OPERATIONS, true);

        if ($risk && !$metadata->sanitarySealConfirmed) {
            throw OfflineSyncValidationException::sanitarySealConfirmationRequired();
        }
    }

    /**
     * @param array<string, mixed> $stopFacts
     */
    private function shouldPreserveQuarantine(
        string $stopType,
        string $operationType,
        array $stopFacts
    ): bool {
        return $stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT
            && $operationType === OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT
            && (bool)($stopFacts['machine_is_perishable'] ?? false)
            && (string)($stopFacts['machine_status'] ?? '') === self::MACHINE_STATUS_SANITARY_QUARANTINE;
    }

    /**
     * @param array<string, mixed> $stopFacts
     */
    private function previousMilestone(array $stopFacts, DateTimeImmutable $syncedAt): DateTimeImmutable
    {
        $milestone = $stopFacts['previous_milestone_at'] ?? null;

        return $milestone instanceof DateTimeImmutable
            ? $milestone->setTimezone(new DateTimeZone('UTC'))
            : $syncedAt;
    }

    /**
     * Un volcado sin texto no es una constancia de trabajo: no se archiva vacío.
     *
     * @param array<string, mixed> $workSummary
     */
    private function assertWorkSummary(array $workSummary): void
    {
        $textKeys = ['diagnosis', 'action_taken', 'pause_reason', 'conformity_summary', 'notes'];

        foreach ($textKeys as $key) {
            $value = $workSummary[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return;
            }
        }

        $parts = $workSummary['replaced_parts'] ?? null;
        if (is_array($parts) && $parts !== []) {
            return;
        }

        throw OfflineSyncValidationException::invalidOfflineDumpPayload(
            '`work_summary` no contiene ningún texto ni pieza: no hay trabajo que archivar.'
        );
    }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    /**
     * Ejecuta la transición y normaliza su resultado sin confiar en el llamador.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $applyTransition
     * @param array<string, mixed> $context
     * @return array{
     *   payload: array<string, mixed>,
     *   http_status: int,
     *   machine_status_after: string|null,
     *   refund_collision_note_id: int
     * }
     */
    private function invokeTransition(callable $applyTransition, array $context): array
    {
        $raw = $applyTransition($context);

        if (!is_array($raw)) {
            throw new DomainException('La transición de dominio debe devolver un array con su resultado.');
        }

        $httpStatus = isset($raw['http_status']) ? (int)$raw['http_status'] : 200;
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new InvalidArgumentException("Código HTTP de transición no válido: {$httpStatus}.");
        }

        $payload = array_key_exists('payload', $raw) && is_array($raw['payload'])
            ? $raw['payload']
            : array_diff_key($raw, array_flip(self::TRANSITION_CONTROL_KEYS));

        return [
            'payload' => $payload,
            'http_status' => $httpStatus,
            'machine_status_after' => isset($raw['machine_status_after'])
                ? (string)$raw['machine_status_after']
                : null,
            'refund_collision_note_id' => isset($raw['refund_collision_note_id'])
                ? (int)$raw['refund_collision_note_id']
                : 0,
        ];
    }

    /**
     * Respuesta de una repetición idempotente: el cuerpo original, intacto, más
     * la bandera que el cliente usa para no volver a contar la operación.
     *
     * @return array{
     *   http_status: int,
     *   payload: array<string, mixed>,
     *   idempotent_replay: bool,
     *   offline_sync: array<string, mixed>|null,
     *   partial_refund_collision_dumped: bool,
     *   sanitary_quarantine_retained: bool
     * }
     */
    private function replayResult(OfflineSyncMutation $existing): array
    {
        $envelope = $this->envelopeFrom($existing);
        $payload = $existing->responsePayload;
        $payload['idempotent_replay'] = true;

        return [
            'http_status' => $existing->responseHttpStatus,
            'payload' => $payload,
            'idempotent_replay' => true,
            'offline_sync' => $envelope === [] ? null : $envelope,
            'partial_refund_collision_dumped' => (bool)(
                $envelope['partial_refund_collision_dumped'] ?? false
            ),
            'sanitary_quarantine_retained' => (bool)(
                $envelope['sanitary_quarantine_retained'] ?? false
            ),
        ];
    }

    /**
     * Bloque `offline_sync` que viaja en la respuesta y en la auditoría
     * (contratos §6.2 y §8): las dos marcas temporales, el veredicto del reloj y
     * los dos blindajes constitucionales.
     *
     * @param array{effective_occurred_at: DateTimeImmutable, clock_skew_adjusted: bool} $resolved
     * @return array<string, mixed>
     */
    private function buildEnvelope(
        OfflineSyncMetadata $metadata,
        array $resolved,
        DateTimeImmutable $syncedAt,
        bool $quarantineRetained,
        bool $partialRefundCollisionDumped
    ): array {
        return [
            'client_mutation_id' => $metadata->clientMutationId,
            'client_occurred_at' => $this->format($metadata->clientOccurredAt),
            'effective_occurred_at' => $this->format($resolved['effective_occurred_at']),
            'synced_at' => $this->format($syncedAt),
            'clock_skew_adjusted' => $resolved['clock_skew_adjusted'],
            'sanitary_seal_confirmed' => $metadata->sanitarySealConfirmed,
            'partial_refund_collision_dumped' => $partialRefundCollisionDumped,
            'sanitary_quarantine_retained' => $quarantineRetained,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeFrom(OfflineSyncMutation $mutation): array
    {
        $envelope = $mutation->responsePayload['offline_sync'] ?? [];

        return is_array($envelope) ? $envelope : [];
    }

    private function now(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }
}
