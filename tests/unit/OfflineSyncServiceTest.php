<?php

declare(strict_types=1);

/**
 * OfflineSyncServiceTest · T-OFFLINE-07
 *
 * Certifica las reglas que el servidor no puede delegar al navegador en la
 * sincronización sin conexión del técnico de campo (Módulo 24, RF-03.4,
 * RF-04.2, RF-04.3, RF-05.2, RF-05.3, RF-05.4, RF-06.4, QA 1, QA 2, QA 4 y
 * QA 5).
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Idempotencia (RF-04.2).** El reenvío de la misma clave devuelve el
 *      cuerpo original con `idempotent_replay = true` sin volver a ejecutar la
 *      transición de dominio; una clave ya volcada como conflicto no se
 *      reaplica; y la petición online ordinaria no escribe en el libro.
 *   2. **Colisión parcial de reintegro (RF-05.2, QA 2).** El cierre se completa
 *      aunque Coordinación ya regularizara el reintegro, pero sólo si el cierre
 *      anexa la Nota Interna con el dictamen de efectivo; sin ella se aborta y
 *      no queda rastro parcial.
 *   3. **Blindaje sanitario (RF-05.4, Art. II).** Una resolución offline sobre
 *      una máquina perecedera en `SANITARY_QUARANTINE` mantiene la cuarentena, y
 *      el servicio aborta si la transición intenta levantarla. La pausa sin
 *      precinto confirmado se rechaza antes de escribir nada (RF-03.4, QA 1).
 *   4. **Volcado de conflictos (RF-05.3, QA 1 y QA 2).** Averías canceladas y
 *      cerradas hace más de 48 horas aceptan el volcado como Nota Interna de
 *      Taller confidencial con su evidencia; las órdenes preventivas anexan en
 *      sus observaciones; el volcado es idempotente y exige contenido real.
 *   5. **Volcado por baja de cuenta (RF-06.4, QA 5).** La cola se archiva sin
 *      alterar estados ni titularidad, es idempotente y libera el dispositivo.
 *
 * Esta suite es hermética: el repositorio es un doble en memoria y no se toca
 * el motor PostgreSQL ni el sistema de ficheros.
 *
 * Uso: php tests/unit/OfflineSyncServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\OfflineSyncService;
use VendGuard\Core\Domain\Exception\OfflineSyncValidationException;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\Repository\OfflineSyncMutationRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\OfflineSyncMetadata;

/**
 * Doble de la unidad de trabajo.
 *
 * Ejecuta el trabajo sin abrir transacción real y, si la operación falla,
 * devuelve el libro en memoria a su estado anterior: así el doble reproduce la
 * única propiedad que el servicio necesita del motor —un veredicto negativo
 * deshace la reserva de idempotencia (`H3`)—. La atomicidad real la certifica
 * la suite de integración contra PostgreSQL.
 */
final class ImmediateTransactionManager implements TransactionManagerInterface
{
    /** Unidades de trabajo abiertas por el servicio. */
    public int $opened = 0;

    public function __construct(
        private readonly ?InMemoryOfflineSyncMutationRepository $repository = null
    ) {
    }

    public function runInTransaction(callable $operation): mixed
    {
        $this->opened++;
        $snapshot = $this->repository?->snapshotState();

        try {
            return $operation();
        } catch (Throwable $exception) {
            if ($snapshot !== null) {
                $this->repository->restoreState($snapshot);
            }

            throw $exception;
        }
    }
}

/**
 * Doble en memoria del libro de sincronización.
 *
 * Reproduce sólo lo que el servicio necesita del puerto: la guarda de
 * idempotencia, el alta idempotente de la mutación y los dos volcados de
 * auditoría con su nota interna. No simula SQL ni transacciones.
 */
final class InMemoryOfflineSyncMutationRepository implements OfflineSyncMutationRepositoryInterface
{
    /** @var array<string, OfflineSyncMutation> */
    public array $rows = [];

    /** @var list<array<string, mixed>> Notas internas de taller creadas por los volcados. */
    public array $internalNotes = [];

    /** @var array<int, string> Estado vigente de cada avería en el servidor. */
    public array $incidentStatus = [];

    /** @var array<int, string> Observaciones anexadas a cada orden preventiva. */
    public array $preventiveNotes = [];

    /** @var array<int, string> Titular vigente de cada avería (Art. V.3). */
    public array $incidentOwner = [];

    /** @var list<OfflineSyncMutation> Mutaciones registradas, en orden de llegada. */
    public array $recorded = [];

    private int $nextId = 1;

    private int $nextCommentId = 800;

    public function findByTechnicianAndMutationId(
        int $technicianId,
        string $clientMutationId
    ): ?OfflineSyncMutation {
        return $this->rows[$technicianId . ':' . $clientMutationId] ?? null;
    }

    public function fetchBootstrapSnapshotForTechnician(int $technicianId): array
    {
        return [
            'route' => [
                'stops' => [],
                'enabled_locations' => [],
                'pending_critical_stops_other_locations' => [],
            ],
            'preventive_orders' => [],
            'preventive_checklists_by_order' => [],
            'spare_parts_catalog_by_machine' => [],
            'van_stock' => [
                'items' => [],
                'active_dispatched_order' => null,
                'last_confirmed_order' => null,
            ],
        ];
    }

    public function findLatestAppliedChainStart(int $technicianId, string $stopType, int $stopId): ?OfflineSyncMutation
    {
        $candidates = array_filter(
            $this->rows,
            static fn (OfflineSyncMutation $row): bool => $row->technicianId === $technicianId
                && $row->stopType === $stopType
                && $row->stopId === $stopId
                && $row->isApplied()
                && in_array($row->operationType, [
                    OfflineSyncMutation::OPERATION_START_INCIDENT,
                    OfflineSyncMutation::OPERATION_RESUME_INCIDENT_PENDING_INFO,
                    OfflineSyncMutation::OPERATION_START_PREVENTIVE,
                ], true)
        );

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (OfflineSyncMutation $a, OfflineSyncMutation $b): int => [$b->syncedAt, $b->id] <=> [$a->syncedAt, $a->id]
        );

        return $candidates[0];
    }

    public function recordAppliedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation
    {
        $key = $mutation->technicianId . ':' . $mutation->clientMutationId;
        if (isset($this->rows[$key])) {
            return $this->rows[$key];
        }

        $stored = $this->withIdentity($mutation);
        $this->rows[$key] = $stored;
        $this->recorded[] = $stored;

        return $stored;
    }

    /**
     * Reserva de idempotencia (`H3`).
     *
     * La fila provisional entra antes de que se ejecute la transición, igual
     * que en PostgreSQL: quien encuentra la clave tomada recibe al ganador.
     *
     * @return array{reserved: bool, existing: OfflineSyncMutation|null}
     */
    public function reserveAppliedMutation(OfflineSyncMutation $mutation): array
    {
        $key = $mutation->technicianId . ':' . $mutation->clientMutationId;

        if (isset($this->rows[$key])) {
            return ['reserved' => false, 'existing' => $this->rows[$key]];
        }

        $stored = $this->withIdentity($mutation);
        $this->rows[$key] = $stored;
        $this->recorded[] = $stored;

        return ['reserved' => true, 'existing' => null];
    }

    /**
     * Confirma la reserva con el cuerpo definitivo.
     *
     * Si la reserva no está, falla cerrado: nunca escribe un resultado
     * huérfano, igual que el repositorio real.
     */
    public function completeReservedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation
    {
        $key = $mutation->technicianId . ':' . $mutation->clientMutationId;

        if (!isset($this->rows[$key])) {
            throw new DomainException('La reserva de idempotencia desapareció antes de confirmarse.');
        }

        $reserved = $this->rows[$key];
        $stored = new OfflineSyncMutation(
            id: $reserved->id,
            technicianId: $reserved->technicianId,
            clientMutationId: $reserved->clientMutationId,
            stopType: $reserved->stopType,
            stopId: $reserved->stopId,
            operationType: $reserved->operationType,
            outcomeStatus: $reserved->outcomeStatus,
            clientOccurredAt: $reserved->clientOccurredAt,
            chainStartedAt: $reserved->chainStartedAt,
            effectiveOccurredAt: $reserved->effectiveOccurredAt,
            syncedAt: $reserved->syncedAt,
            clockSkewAdjusted: $reserved->clockSkewAdjusted,
            sanitarySealConfirmed: $reserved->sanitarySealConfirmed,
            responseHttpStatus: $mutation->responseHttpStatus,
            responsePayload: $mutation->responsePayload,
            createdAt: $reserved->createdAt
        );

        $this->rows[$key] = $stored;

        foreach ($this->recorded as $index => $row) {
            if ($row->technicianId === $stored->technicianId
                && $row->clientMutationId === $stored->clientMutationId
            ) {
                $this->recorded[$index] = $stored;
            }
        }

        return $stored;
    }

    public function recordConflictDump(
        OfflineSyncMutation $mutation,
        string $conflictReason,
        array $workSummary,
        ?string $photoPath = null
    ): array {
        $internalCommentId = null;

        if ($mutation->stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT) {
            // Excepción de auditoría (QA 2): el estado y el titular vigentes no
            // se tocan, aunque la avería esté cancelada o cerrada.
            $internalCommentId = $this->nextCommentId++;
            $this->internalNotes[] = [
                'incident_id' => $mutation->stopId,
                'is_internal' => true,
                'body' => '[VOLCADO OFFLINE EN CONFLICTO] ' . $conflictReason,
                'work_summary' => $workSummary,
                'photo_path' => $photoPath,
                'client_mutation_id' => $mutation->clientMutationId,
            ];
        } else {
            $this->preventiveNotes[$mutation->stopId] = trim(
                ($this->preventiveNotes[$mutation->stopId] ?? '')
                . ' [VOLCADO OFFLINE EN CONFLICTO] ' . $conflictReason
            );
        }

        $payload = [
            'client_mutation_id' => $mutation->clientMutationId,
            'outcome_status' => OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
            'stop_type' => $mutation->stopType,
            'stop_id' => $mutation->stopId,
            'internal_comment_id' => $internalCommentId,
            'synced_at' => $mutation->syncedAt,
        ];

        $stored = $this->withIdentity($mutation, $payload);
        $this->rows[$stored->technicianId . ':' . $stored->clientMutationId] = $stored;
        $this->recorded[] = $stored;

        return [
            'mutation' => $stored,
            'internal_comment_id' => $internalCommentId,
            'audit_log_id' => 900 + count($this->recorded),
        ];
    }

    public function recordDeactivatedAccountDumpBatch(int $technicianId, array $mutations): array
    {
        $dumped = 0;
        $storedMutations = [];
        $commentIds = [];

        foreach ($mutations as $mutation) {
            $key = $technicianId . ':' . $mutation->clientMutationId;
            if (isset($this->rows[$key])) {
                continue;
            }

            $internalCommentId = null;
            if ($mutation->stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT) {
                $internalCommentId = $this->nextCommentId++;
                $this->internalNotes[] = [
                    'incident_id' => $mutation->stopId,
                    'is_internal' => true,
                    'body' => '[VOLCADO AUTOMÁTICO POR BAJA DE CUENTA]',
                    'work_summary' => $mutation->responsePayload['work_summary'] ?? [],
                    'photo_path' => null,
                    'client_mutation_id' => $mutation->clientMutationId,
                ];
                $commentIds[] = $internalCommentId;
            } else {
                $this->preventiveNotes[$mutation->stopId] = trim(
                    ($this->preventiveNotes[$mutation->stopId] ?? '')
                    . ' [VOLCADO AUTOMÁTICO POR BAJA DE CUENTA]'
                );
            }

            $stored = $this->withIdentity($mutation);
            $this->rows[$key] = $stored;
            $this->recorded[] = $stored;
            $storedMutations[] = $stored;
            $dumped++;
        }

        return [
            'dumped' => $dumped,
            'mutations' => $storedMutations,
            'internal_comment_ids' => $commentIds,
        ];
    }

    /**
     * Foto del libro para deshacer una unidad de trabajo fallida.
     *
     * @return array{rows: array<string, OfflineSyncMutation>, recorded: list<OfflineSyncMutation>}
     */
    public function snapshotState(): array
    {
        return ['rows' => $this->rows, 'recorded' => $this->recorded];
    }

    /**
     * @param array{rows: array<string, OfflineSyncMutation>, recorded: list<OfflineSyncMutation>} $state
     */
    public function restoreState(array $state): void
    {
        $this->rows = $state['rows'];
        $this->recorded = $state['recorded'];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function withIdentity(OfflineSyncMutation $mutation, ?array $payload = null): OfflineSyncMutation
    {
        return new OfflineSyncMutation(
            id: $this->nextId++,
            technicianId: $mutation->technicianId,
            clientMutationId: $mutation->clientMutationId,
            stopType: $mutation->stopType,
            stopId: $mutation->stopId,
            operationType: $mutation->operationType,
            outcomeStatus: $mutation->outcomeStatus,
            clientOccurredAt: $mutation->clientOccurredAt,
            chainStartedAt: $mutation->chainStartedAt,
            effectiveOccurredAt: $mutation->effectiveOccurredAt,
            syncedAt: $mutation->syncedAt,
            clockSkewAdjusted: $mutation->clockSkewAdjusted,
            sanitarySealConfirmed: $mutation->sanitarySealConfirmed,
            responseHttpStatus: $mutation->responseHttpStatus,
            responsePayload: $payload ?? $mutation->responsePayload,
            createdAt: $mutation->createdAt
        );
    }
}

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($ok) {
        echo "  [PASS] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";

        return;
    }
    $failures++;
    echo "  [FAIL] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
};

echo "======================================================================\n";
echo " VENDGUARD · T-OFFLINE-07 · Servicio de sincronización offline (Módulo 24)\n";
echo "======================================================================\n";

$utc = new DateTimeZone('UTC');
$synced = new DateTimeImmutable('2026-10-27T11:00:00Z');
$assignedAt = new DateTimeImmutable('2026-10-27T08:00:00Z');

/** Marca compacta para los mensajes de detalle. */
$stamp = static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone($utc)->format('Y-m-d H:i:s');

/** Captura la excepción de dominio de un cierre inválido sin romper la suite. */
$capture = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
};

$repo = new InMemoryOfflineSyncMutationRepository();
$service = new OfflineSyncService($repo, new ImmediateTransactionManager($repo));

$offlineMetadata = static fn (
    string $mutationId,
    DateTimeImmutable $occurredAt,
    bool $seal = false
): OfflineSyncMetadata => new OfflineSyncMetadata($mutationId, $occurredAt, null, null, $seal);

// =============================================================================
// 1. Idempotencia por (technician_id, client_mutation_id) — RF-04.2
// =============================================================================
echo "\n--- 1. Idempotencia y libro de sincronización (RF-04.2) ---\n";

$firstMutationId = 'mut_20261027_091500_idem0001';
$domainCalls = 0;
$firstContext = null;

$resolveTransition = static function (array $context) use (&$domainCalls, &$firstContext): array {
    $domainCalls++;
    $firstContext = $context;

    return [
        'http_status' => 200,
        'payload' => [
            'success' => true,
            'data' => [
                'incident_id' => 105,
                'status' => 'RESOLVED',
                'machine_status' => 'OPERATIVE',
            ],
        ],
        'machine_status_after' => 'OPERATIVE',
    ];
};

$firstResult = $service->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 105,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata($firstMutationId, $synced->modify('-25 minutes')),
    stopFacts: ['previous_milestone_at' => $assignedAt],
    applyTransition: $resolveTransition,
    now: $synced
);

$assert(
    '1.1 La primera aplicación ejecuta el dominio una sola vez',
    $firstResult['http_status'] === 200
        && $firstResult['idempotent_replay'] === false
        && $domainCalls === 1
        && count($repo->rows) === 1
);
$assert(
    '1.2 La mutación aplicada queda en el libro con su doble sello',
    ($repo->rows['2:' . $firstMutationId]->outcomeStatus ?? null) === OfflineSyncMutation::OUTCOME_APPLIED
        && ($repo->rows['2:' . $firstMutationId]->clientOccurredAt ?? '') === '2026-10-27T10:35:00+00:00'
        && ($firstResult['offline_sync']['synced_at'] ?? '') === '2026-10-27T11:00:00+00:00',
    $stamp((string)($firstResult['offline_sync']['effective_occurred_at'] ?? $synced->format('c')))
);
$assert(
    '1.3 La operación offline llega al dominio marcada como tal',
    ($firstContext['is_offline'] ?? null) === true
        && ($firstContext['clock_skew_adjusted'] ?? null) === false
);

$replayResult = $service->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 105,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata($firstMutationId, $synced->modify('-25 minutes')),
    stopFacts: ['previous_milestone_at' => $assignedAt],
    applyTransition: $resolveTransition,
    now: $synced->modify('+2 minutes')
);

$assert(
    '1.4 El reenvío no vuelve a ejecutar el dominio',
    $domainCalls === 1,
    "callback invocado {$domainCalls} vez/veces"
);
$assert(
    '1.5 El reenvío devuelve el cuerpo original con idempotent_replay = true',
    $replayResult['idempotent_replay'] === true
        && $replayResult['payload'] === $firstResult['payload'] + ['idempotent_replay' => true]
        && $replayResult['http_status'] === $firstResult['http_status']
);
$assert(
    '1.6 El libro no duplica la fila en el reenvío',
    count($repo->rows) === 1
);

$isolatedTechnician = $service->executeIdempotentMutation(
    technicianId: 3,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 105,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata($firstMutationId, $synced->modify('-25 minutes')),
    stopFacts: ['previous_milestone_at' => $assignedAt],
    applyTransition: $resolveTransition,
    now: $synced
);
$assert(
    '1.7 La misma clave en otro técnico es otra operación (Art. V.4)',
    $isolatedTechnician['idempotent_replay'] === false
        && count($repo->rows) === 2
        && isset($repo->rows['3:' . $firstMutationId])
);

$onlineCalls = 0;
$onlineContext = null;
$onlineResult = $service->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 106,
    operationType: OfflineSyncMutation::OPERATION_START_INCIDENT,
    metadata: null,
    stopFacts: ['previous_milestone_at' => $assignedAt],
    applyTransition: static function (array $context) use (&$onlineCalls, &$onlineContext): array {
        $onlineCalls++;
        $onlineContext = $context;

        return ['http_status' => 200, 'payload' => ['success' => true]];
    },
    now: $synced
);
$assert(
    '1.8 La petición online ordinaria no escribe en el libro ni se marca offline',
    $onlineCalls === 1
        && $onlineResult['offline_sync'] === null
        && $onlineResult['idempotent_replay'] === false
        && ($onlineContext['is_offline'] ?? null) === false
        && count($repo->rows) === 2
);

$dumpedKey = 'mut_20261027_091600_dump0001';
$service->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata($dumpedKey, $synced->modify('-40 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 105,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: 'La avería fue reasignada mientras el técnico estaba incomunicado.',
    workSummary: ['diagnosis' => 'Sonda NTC descalibrada.'],
    now: $synced
);
$reapplyDumped = $capture(static fn () => $service->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 105,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata($dumpedKey, $synced->modify('-40 minutes')),
    stopFacts: ['previous_milestone_at' => $assignedAt],
    applyTransition: $resolveTransition,
    now: $synced
));
$assert(
    '1.9 Una clave ya volcada como conflicto no se reaplica (409)',
    $reapplyDumped instanceof OfflineSyncValidationException
        && $reapplyDumped->getErrorCode() === OfflineSyncValidationException::OFFLINE_MUTATION_CONFLICT
        && $reapplyDumped->getHttpStatusCode() === 409
);

// =============================================================================
// 2. Colisión parcial en el sub-bloque de reintegro — RF-05.2, QA 2
// =============================================================================
echo "\n--- 2. Colisión parcial de reintegro (RF-05.2, QA 2) ---\n";

$refundContext = null;
$refundRepo = new InMemoryOfflineSyncMutationRepository();
$refundService = new OfflineSyncService($refundRepo, new ImmediateTransactionManager($refundRepo));

$refundResult = $refundService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 205,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata('mut_20261027_091700_refund01', $synced->modify('-10 minutes'), true),
    stopFacts: [
        'previous_milestone_at' => $assignedAt,
        'refund_already_regularized' => true,
    ],
    applyTransition: static function (array $context) use (&$refundContext): array {
        $refundContext = $context;

        return [
            'http_status' => 200,
            'payload' => ['success' => true, 'data' => ['incident_status' => 'RESOLVED']],
            'refund_collision_note_id' => 842,
        ];
    },
    now: $synced
);

$assert(
    '2.1 La resolución se completa con éxito y el dominio no pisa el reintegro',
    $refundResult['http_status'] === 200
        && ($refundResult['payload']['data']['incident_status'] ?? null) === 'RESOLVED'
        && ($refundContext['skip_refund_block'] ?? null) === true
);
$assert(
    '2.2 La colisión parcial queda marcada en la respuesta y en la auditoría',
    $refundResult['partial_refund_collision_dumped'] === true
        && ($refundResult['offline_sync']['partial_refund_collision_dumped'] ?? null) === true
);
$assert(
    '2.3 La máquina perecedera sin cuarentena no activa el blindaje',
    $refundResult['sanitary_quarantine_retained'] === false
        && ($refundResult['offline_sync']['sanitary_quarantine_retained'] ?? null) === false
);

$repoWithoutNote = new InMemoryOfflineSyncMutationRepository();
$serviceWithoutNote = new OfflineSyncService($repoWithoutNote, new ImmediateTransactionManager($repoWithoutNote));
$missingNoteFailure = $capture(static fn () => $serviceWithoutNote->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 206,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata('mut_20261027_091800_refund02', $synced->modify('-10 minutes')),
    stopFacts: ['previous_milestone_at' => $assignedAt, 'refund_already_regularized' => true],
    applyTransition: static fn (array $context): array => [
        'http_status' => 200,
        'payload' => ['success' => true],
        'refund_collision_note_id' => 0,
    ],
    now: $synced
));
$assert(
    '2.4 Sin Nota Interna con el dictamen de efectivo el cierre se aborta',
    $missingNoteFailure instanceof DomainException
        && str_contains($missingNoteFailure->getMessage(), 'Nota Interna')
);
$assert(
    '2.5 El cierre abortado no deja fila parcial en el libro',
    $repoWithoutNote->rows === []
);

// =============================================================================
// 3. Blindaje de Cuarentena Sanitaria — RF-05.4, Art. II
// =============================================================================
echo "\n--- 3. Cuarentena sanitaria y precinto in situ (RF-05.4, QA 1, Art. II) ---\n";

$quarantineRepo = new InMemoryOfflineSyncMutationRepository();
$quarantineService = new OfflineSyncService($quarantineRepo, new ImmediateTransactionManager($quarantineRepo));

$quarantineResult = $quarantineService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 305,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata('mut_20261027_091900_quarant1', $synced->modify('-15 minutes'), true),
    stopFacts: [
        'machine_is_perishable' => true,
        'machine_status' => OfflineSyncService::MACHINE_STATUS_SANITARY_QUARANTINE,
        'previous_milestone_at' => $assignedAt,
    ],
    applyTransition: static fn (array $context): array => [
        'http_status' => 200,
        'payload' => [
            'success' => true,
            'data' => [
                'incident_status' => 'RESOLVED',
                'message' => 'La máquina continúa bloqueada por protocolo de seguridad alimentaria.',
            ],
        ],
        'machine_status_after' => OfflineSyncService::MACHINE_STATUS_SANITARY_QUARANTINE,
    ],
    now: $synced
);

$assert(
    '3.1 La avería se cierra y la cuarentena se mantiene (Art. II)',
    $quarantineResult['http_status'] === 200
        && ($quarantineResult['payload']['data']['incident_status'] ?? null) === 'RESOLVED'
        && $quarantineResult['sanitary_quarantine_retained'] === true
        && ($quarantineResult['offline_sync']['sanitary_quarantine_retained'] ?? null) === true
);
$assert(
    '3.2 El aviso sanitario llega al técnico en la respuesta',
    str_contains((string)($quarantineResult['payload']['data']['message'] ?? ''), 'seguridad alimentaria')
);

$liftFailure = $capture(static fn () => $quarantineService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 306,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: $offlineMetadata('mut_20261027_092000_quarant2', $synced->modify('-15 minutes')),
    stopFacts: [
        'machine_is_perishable' => true,
        'machine_status' => OfflineSyncService::MACHINE_STATUS_SANITARY_QUARANTINE,
        'previous_milestone_at' => $assignedAt,
    ],
    applyTransition: static fn (array $context): array => [
        'http_status' => 200,
        'payload' => ['success' => true],
        'machine_status_after' => 'OPERATIVE',
    ],
    now: $synced
));
$assert(
    '3.3 Una transición que levante la cuarentena se aborta',
    $liftFailure instanceof DomainException
        && str_contains($liftFailure->getMessage(), 'cuarentena sanitaria')
);
$assert(
    '3.4 El cierre abortado no se registra como aplicado',
    $quarantineRepo->findByTechnicianAndMutationId(2, 'mut_20261027_092000_quarant2') === null
);

$sealRepo = new InMemoryOfflineSyncMutationRepository();
$sealService = new OfflineSyncService($sealRepo, new ImmediateTransactionManager($sealRepo));
$sealCalled = false;
$sealFailure = $capture(static function () use ($sealService, $offlineMetadata, $synced, $assignedAt, &$sealCalled): void {
    $sealService->executeIdempotentMutation(
        technicianId: 2,
        stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
        stopId: 307,
        operationType: OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
        metadata: $offlineMetadata('mut_20261027_092100_seal0001', $synced->modify('-5 minutes')),
        stopFacts: ['machine_is_perishable' => true, 'previous_milestone_at' => $assignedAt],
        applyTransition: static function (array $context) use (&$sealCalled): array {
            $sealCalled = true;

            return ['http_status' => 200, 'payload' => ['success' => true]];
        },
        now: $synced
    );
});
$assert(
    '3.5 La pausa térmica sin precinto se rechaza con 422 antes de escribir',
    $sealFailure instanceof OfflineSyncValidationException
        && $sealFailure->getErrorCode() === OfflineSyncValidationException::SANITARY_SEAL_CONFIRMATION_REQUIRED
        && $sealFailure->getHttpStatusCode() === 422
        && $sealCalled === false
        && $sealRepo->rows === []
);

$sealedContext = null;
$sealedResult = $sealService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 307,
    operationType: OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
    metadata: $offlineMetadata('mut_20261027_092200_seal0002', $synced->modify('-5 minutes'), true),
    stopFacts: ['machine_is_perishable' => true, 'previous_milestone_at' => $assignedAt],
    applyTransition: static function (array $context) use (&$sealedContext): array {
        $sealedContext = $context;

        return ['http_status' => 200, 'payload' => ['success' => true]];
    },
    now: $synced
);
$assert(
    '3.6 Con el precinto confirmado la pausa se sincroniza y queda auditada',
    $sealedResult['http_status'] === 200
        && ($sealedContext['sanitary_seal_confirmed'] ?? null) === true
        && ($sealedResult['offline_sync']['sanitary_seal_confirmed'] ?? null) === true
);

// =============================================================================
// 4. Volcado de conflictos en 1 toque — RF-05.3, QA 1, QA 2
// =============================================================================
echo "\n--- 4. Volcado de conflictos (RF-05.3, QA 1, QA 2) ---\n";

$dumpRepo = new InMemoryOfflineSyncMutationRepository();
$dumpRepo->incidentStatus[405] = 'CANCELLED';
$dumpRepo->incidentOwner[405] = 7;
$dumpRepo->incidentStatus[406] = 'CLOSED';
$dumpRepo->incidentOwner[406] = 2;
$dumpService = new OfflineSyncService($dumpRepo, new ImmediateTransactionManager($dumpRepo));

$cancelledSummary = [
    'diagnosis' => 'Compresor sin arranque por relé térmico disparado.',
    'action_taken' => 'Rearme del relé y verificación de consumo a 4 °C.',
    'pause_reason' => null,
    'replaced_parts' => [
        ['spare_part_id' => 14, 'part_code' => 'COMP-TH-01', 'part_name' => 'Sonda NTC', 'quantity' => 1],
    ],
    'conformity_summary' => 'Firma manuscrita recogida in situ de Laura Gómez (12345678Z).',
];

$cancelledDump = $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092300_dump0002', $synced->modify('-50 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 405,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: 'La avería fue cancelada mientras el técnico estaba sin cobertura.',
    workSummary: $cancelledSummary,
    photoPath: 'storage/evidence/offline/405-evidencia.jpg',
    now: $synced
);

$assert(
    '4.1 Una avería cancelada acepta el volcado como Nota Interna (QA 2)',
    $cancelledDump['http_status'] === 200
        && $cancelledDump['idempotent_replay'] === false
        && ($cancelledDump['internal_comment_id'] ?? 0) > 0
        && count($dumpRepo->internalNotes) === 1
);
$assert(
    '4.2 La nota es confidencial, conserva la evidencia y el dictamen',
    ($dumpRepo->internalNotes[0]['is_internal'] ?? null) === true
        && ($dumpRepo->internalNotes[0]['photo_path'] ?? null) === 'storage/evidence/offline/405-evidencia.jpg'
        && ($dumpRepo->internalNotes[0]['work_summary']['conformity_summary'] ?? '') !== ''
);
$assert(
    '4.3 El volcado no altera estado ni titularidad de la avería (Art. V.3)',
    ($dumpRepo->incidentStatus[405] ?? null) === 'CANCELLED'
        && ($dumpRepo->incidentOwner[405] ?? null) === 7
);
$assert(
    '4.4 La mutación queda registrada como CONFLICT_DUMPED',
    ($dumpRepo->rows['2:mut_20261027_092300_dump0002']->outcomeStatus ?? null)
        === OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED
);

$cancelledReplay = $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092300_dump0002', $synced->modify('-50 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 405,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: 'La avería fue cancelada mientras el técnico estaba sin cobertura.',
    workSummary: $cancelledSummary,
    now: $synced->modify('+3 minutes')
);
$assert(
    '4.5 El volcado repetido es idempotente y no duplica la nota',
    $cancelledReplay['idempotent_replay'] === true
        && $cancelledReplay['internal_comment_id'] === $cancelledDump['internal_comment_id']
        && count($dumpRepo->internalNotes) === 1
);

$closedDump = $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092400_dump0003', $synced->modify('-4 days')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 406,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: 'El expediente se cerró hace más de 48 horas.',
    workSummary: ['notes' => 'Comprobación de frío realizada in situ con termómetro patrón.'],
    now: $synced
);
$assert(
    '4.6 Un hilo cerrado hace más de 48 h también admite el volcado (QA 2)',
    ($closedDump['internal_comment_id'] ?? 0) > 0
        && ($dumpRepo->incidentStatus[406] ?? null) === 'CLOSED'
        && count($dumpRepo->internalNotes) === 2
);

$noReasonFailure = $capture(static fn () => $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092500_dump0004', $synced->modify('-20 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 405,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: '   ',
    workSummary: $cancelledSummary,
    now: $synced
));
$assert(
    '4.7 Un volcado sin motivo se rechaza con 422 INVALID_OFFLINE_DUMP_PAYLOAD',
    $noReasonFailure instanceof OfflineSyncValidationException
        && $noReasonFailure->getErrorCode() === OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD
        && $noReasonFailure->getHttpStatusCode() === 422
);

$emptySummaryFailure = $capture(static fn () => $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092600_dump0005', $synced->modify('-20 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 405,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    conflictReason: 'Parada reasignada a otro compañero.',
    workSummary: ['diagnosis' => '   ', 'replaced_parts' => []],
    now: $synced
));
$assert(
    '4.8 Un resumen sin texto ni piezas no se archiva: no hay trabajo que volcar',
    $emptySummaryFailure instanceof OfflineSyncValidationException
        && $emptySummaryFailure->getErrorCode() === OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD
);

$preventiveDump = $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092700_dump0006', $synced->modify('-35 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_PREVENTIVE_ORDER,
    stopId: 505,
    operationType: OfflineSyncMutation::OPERATION_COMPLETE_PREVENTIVE,
    conflictReason: 'La orden preventiva fue reasignada durante la inspección.',
    workSummary: ['action_taken' => 'Limpieza de condensador y prueba de estanqueidad.'],
    now: $synced
);
$assert(
    '4.9 Una orden preventiva anexa el resumen en sus observaciones (QA 2)',
    $preventiveDump['http_status'] === 200
        && $preventiveDump['internal_comment_id'] === null
        && str_contains((string)($dumpRepo->preventiveNotes[505] ?? ''), 'VOLCADO OFFLINE EN CONFLICTO')
        && count($dumpRepo->internalNotes) === 2
);

$mismatchedDump = $capture(static fn () => $dumpService->dumpConflict(
    technicianId: 2,
    metadata: $offlineMetadata('mut_20261027_092800_dump0007', $synced->modify('-20 minutes')),
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 405,
    operationType: OfflineSyncMutation::OPERATION_COMPLETE_PREVENTIVE,
    conflictReason: 'Operación cruzada.',
    workSummary: $cancelledSummary,
    now: $synced
));
$assert(
    '4.10 Una operación de preventiva sobre una avería se rechaza (D1)',
    $mismatchedDump instanceof OfflineSyncValidationException
        && $mismatchedDump->getErrorCode() === OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD
);

// =============================================================================
// 5. Volcado por baja de cuenta — RF-06.4, QA 5
// =============================================================================
echo "\n--- 5. Volcado por baja de cuenta (RF-06.4, QA 5) ---\n";

$deactivatedRepo = new InMemoryOfflineSyncMutationRepository();
$deactivatedRepo->incidentStatus[605] = 'IN_PROGRESS';
$deactivatedRepo->incidentOwner[605] = 2;
$deactivatedService = new OfflineSyncService($deactivatedRepo, new ImmediateTransactionManager($deactivatedRepo));

$queue = [
    [
        'client_mutation_id' => 'mut_20261027_093000_baja0001',
        'stop_type' => OfflineSyncMutation::STOP_TYPE_INCIDENT,
        'stop_id' => 605,
        'operation_type' => OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
        'client_occurred_at' => '2026-10-27T10:30:00.000Z',
        'work_summary' => ['pause_reason' => 'Sin repuesto en furgoneta: compresor.'],
    ],
    [
        'client_mutation_id' => 'mut_20261027_093100_baja0002',
        'stop_type' => OfflineSyncMutation::STOP_TYPE_PREVENTIVE_ORDER,
        'stop_id' => 705,
        'operation_type' => OfflineSyncMutation::OPERATION_COMPLETE_PREVENTIVE,
        'client_occurred_at' => '2026-10-27T10:45:00.000Z',
        'work_summary' => ['action_taken' => 'Lista de verificación completada sin incidencias.'],
    ],
];

$deactivatedDump = $deactivatedService->dumpDeactivatedAccountQueue(2, $queue, $synced);

$assert(
    '5.1 El lote completo se vuelca y pide liberar el dispositivo',
    $deactivatedDump['http_status'] === 200
        && ($deactivatedDump['payload']['dumped'] ?? null) === 2
        && ($deactivatedDump['payload']['clear_local_storage'] ?? null) === true
        && $deactivatedDump['offline_sync'] === null
);
$assert(
    '5.2 Cada elemento queda con DEACTIVATED_ACCOUNT_DUMP',
    ($deactivatedRepo->rows['2:mut_20261027_093000_baja0001']->outcomeStatus ?? null)
        === OfflineSyncMutation::OUTCOME_DEACTIVATED_ACCOUNT_DUMP
        && ($deactivatedRepo->rows['2:mut_20261027_093100_baja0002']->outcomeStatus ?? null)
        === OfflineSyncMutation::OUTCOME_DEACTIVATED_ACCOUNT_DUMP
);
$assert(
    '5.3 El volcado no altera el estado ni la titularidad de la parada (Art. III.3)',
    ($deactivatedRepo->incidentStatus[605] ?? null) === 'IN_PROGRESS'
        && ($deactivatedRepo->incidentOwner[605] ?? null) === 2
);
$assert(
    '5.4 El resumen queda archivado como Nota Interna confidencial',
    count($deactivatedRepo->internalNotes) === 1
        && ($deactivatedRepo->internalNotes[0]['is_internal'] ?? null) === true
        && str_contains((string)($deactivatedRepo->internalNotes[0]['body'] ?? ''), 'BAJA DE CUENTA')
);

$deactivatedReplay = $deactivatedService->dumpDeactivatedAccountQueue(2, $queue, $synced->modify('+5 minutes'));
$assert(
    '5.5 El reintento del volcado no duplica notas ni filas',
    ($deactivatedReplay['payload']['dumped'] ?? null) === 0
        && count($deactivatedRepo->internalNotes) === 1
        && count($deactivatedRepo->rows) === 2
);

$missingIdFailure = $capture(static fn () => $deactivatedService->dumpDeactivatedAccountQueue(2, [[
    'stop_type' => OfflineSyncMutation::STOP_TYPE_INCIDENT,
    'stop_id' => 605,
    'operation_type' => OfflineSyncMutation::OPERATION_PAUSE_INCIDENT_PARTS,
    'client_occurred_at' => '2026-10-27T10:30:00.000Z',
    'work_summary' => ['pause_reason' => 'Sin acceso al local.'],
]], $synced));
$assert(
    '5.6 Un elemento sin clave de idempotencia rechaza el lote completo (422)',
    $missingIdFailure instanceof OfflineSyncValidationException
        && $missingIdFailure->getErrorCode() === OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD
        && count($deactivatedRepo->rows) === 2
);

// =============================================================================
// 6. Anclaje del cierre encadenado con reanudación — EARS 04.3, QA 4
// =============================================================================
echo "\n--- 6. Anclaje del cierre encadenado tras reanudar (EARS 04.3, QA 4) ---\n";

$chainRepo = new InMemoryOfflineSyncMutationRepository();
$chainService = new OfflineSyncService($chainRepo, new ImmediateTransactionManager($chainRepo));
$chainSyncedAt = new DateTimeImmutable('2026-10-27T12:00:00+00:00');

$recordChainStart = static function (
    string $operationType,
    string $effectiveAt,
    string $syncedAt,
    string $mutationId
) use ($chainRepo): void {
    $chainRepo->recordAppliedMutation(new OfflineSyncMutation(
        id: OfflineSyncMutation::UNPERSISTED_ID,
        technicianId: 2,
        clientMutationId: $mutationId,
        stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
        stopId: 905,
        operationType: $operationType,
        outcomeStatus: OfflineSyncMutation::OUTCOME_APPLIED,
        clientOccurredAt: $effectiveAt,
        chainStartedAt: null,
        effectiveOccurredAt: $effectiveAt,
        syncedAt: $syncedAt,
        clockSkewAdjusted: true,
        sanitarySealConfirmed: false,
        responseHttpStatus: 200,
        responsePayload: ['status' => 'IN_PROGRESS'],
        createdAt: $syncedAt
    ));
};

$recordChainStart(
    OfflineSyncMutation::OPERATION_START_INCIDENT,
    '2026-10-27T09:00:00+00:00',
    '2026-10-27T09:20:00+00:00',
    'mut_20261027_091900_chain001'
);
$recordChainStart(
    OfflineSyncMutation::OPERATION_RESUME_INCIDENT_PENDING_INFO,
    '2026-10-27T10:00:00+00:00',
    '2026-10-27T10:20:00+00:00',
    'mut_20261027_101900_chain002'
);

$chainContext = null;
$chainResolve = $chainService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 905,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: new OfflineSyncMetadata(
        'mut_20261027_120000_chain003',
        new DateTimeImmutable('2026-10-27T15:30:00Z'),
        new DateTimeImmutable('2026-10-27T15:00:00Z')
    ),
    // El expediente conserva el started_at original: el ancla debe salir de la
    // reanudación aplicada, no de este hito (EARS 04.3).
    stopFacts: ['previous_milestone_at' => new DateTimeImmutable('2026-10-27T09:00:00+00:00')],
    applyTransition: static function (array $context) use (&$chainContext): array {
        $chainContext = $context;

        return ['http_status' => 200, 'payload' => ['status' => 'RESOLVED']];
    },
    now: $chainSyncedAt
);

$assert(
    '6.1 El cierre se ancla en la reanudación, no en el inicio original',
    ($chainContext['effective_occurred_at'] ?? null) instanceof DateTimeImmutable
        && $chainContext['effective_occurred_at']->format('Y-m-d\TH:i:s') === '2026-10-27T10:30:00'
        && $chainContext['clock_skew_adjusted'] === true,
    ($chainContext['effective_occurred_at'] ?? null) instanceof DateTimeImmutable
        ? $stamp($chainContext['effective_occurred_at']->format('c'))
        : 'sin contexto'
);
$assert(
    '6.2 El sobre conserva Δt = 30 min y sincroniza a las 12:00',
    ($chainResolve['offline_sync']['effective_occurred_at'] ?? '') === '2026-10-27T10:30:00+00:00'
        && ($chainResolve['offline_sync']['synced_at'] ?? '') === '2026-10-27T12:00:00+00:00'
        && ($chainResolve['offline_sync']['clock_skew_adjusted'] ?? false) === true
);
$assert(
    '6.3 La mutación aplicada conserva el ancla reanudada',
    ($chainRepo->rows['2:mut_20261027_120000_chain003']->effectiveOccurredAt ?? '') === '2026-10-27T10:30:00+00:00'
        && ($chainRepo->rows['2:mut_20261027_120000_chain003']->chainStartedAt ?? '') === '2026-10-27T15:00:00+00:00'
);

$fallbackRepo = new InMemoryOfflineSyncMutationRepository();
$fallbackService = new OfflineSyncService($fallbackRepo, new ImmediateTransactionManager($fallbackRepo));
$fallbackContext = null;
$fallback = $fallbackService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 915,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: new OfflineSyncMetadata(
        'mut_20261027_120100_chain004',
        new DateTimeImmutable('2026-10-27T15:30:00Z'),
        new DateTimeImmutable('2026-10-27T15:00:00Z')
    ),
    stopFacts: ['previous_milestone_at' => new DateTimeImmutable('2026-10-27T09:00:00+00:00')],
    applyTransition: static function (array $context) use (&$fallbackContext): array {
        $fallbackContext = $context;

        return ['http_status' => 200, 'payload' => ['status' => 'RESOLVED']];
    },
    now: $chainSyncedAt
);
$assert(
    '6.4 Sin inicio offline aplicado, el cierre cae en el hito del expediente + Δt',
    ($fallbackContext['effective_occurred_at'] ?? null) instanceof DateTimeImmutable
        && $fallbackContext['effective_occurred_at']->format('Y-m-d\TH:i:s') === '2026-10-27T09:30:00'
        && ($fallback['offline_sync']['effective_occurred_at'] ?? '') === '2026-10-27T09:30:00+00:00'
);

$recordChainStart(
    OfflineSyncMutation::OPERATION_RESUME_INCIDENT_PENDING_INFO,
    '2026-10-27T11:50:00+00:00',
    '2026-10-27T11:55:00+00:00',
    'mut_20261027_115400_chain005'
);
$clampedContext = null;
$chainService->executeIdempotentMutation(
    technicianId: 2,
    stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
    stopId: 905,
    operationType: OfflineSyncMutation::OPERATION_RESOLVE_INCIDENT,
    metadata: new OfflineSyncMetadata(
        'mut_20261027_120200_chain006',
        new DateTimeImmutable('2026-10-27T15:30:00Z'),
        new DateTimeImmutable('2026-10-27T15:00:00Z')
    ),
    stopFacts: ['previous_milestone_at' => new DateTimeImmutable('2026-10-27T09:00:00+00:00')],
    applyTransition: static function (array $context) use (&$clampedContext): array {
        $clampedContext = $context;

        return ['http_status' => 200, 'payload' => ['status' => 'RESOLVED']];
    },
    now: new DateTimeImmutable('2026-10-27T12:00:00+00:00')
);
$assert(
    '6.5 El cierre nunca se adelanta a su recepción: tope en synced_at',
    ($clampedContext['effective_occurred_at'] ?? null) instanceof DateTimeImmutable
        && $clampedContext['effective_occurred_at']->format('Y-m-d\TH:i:s') === '2026-10-27T12:00:00'
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n==========================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "==========================================================================\n";

exit($failures === 0 ? 0 : 1);
