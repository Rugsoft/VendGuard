<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use VendGuard\Core\Domain\Exception\IncidentNotFoundException;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\Repository\OfflineSyncMutationRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoOfflineSyncMutationRepository
 *
 * Persistencia PostgreSQL del libro de sincronización sin conexión (Módulo 24,
 * RF-02.1, RF-04.2, RF-04.3, RF-05.3 y RF-06.4).
 *
 * ## Qué garantiza
 *
 *  1. **Idempotencia real.** `(technician_id, client_mutation_id)` es la clave
 *     única `uq_offline_sync_tech_mutation`. La comprobación previa evita el
 *     trabajo duplicado y, si dos peticiones compiten, la violación `23505` se
 *     traduce en la fila ya persistida: nunca en un error de motor expuesto al
 *     cliente (`RF-04.2`).
 *  2. **Bootstrap sin N+1.** La foto de ruta se arma con un número fijo de
 *     consultas —once— con independencia del número de paradas, órdenes,
 *     máquinas o piezas. Las listas se cruzan en PHP por identificador, no con
 *     una consulta por elemento.
 *  3. **Volcado con excepción de auditoría.** La Nota Interna de Taller se
 *     inserta directamente en `incident_comments` con `is_internal = TRUE`
 *     aunque el técnico ya no sea el titular y aunque la avería esté
 *     `CANCELLED` o `CLOSED` hace más de 48 horas; en órdenes preventivas el
 *     bloque se anexa a `preventive_orders.notes` sin tocar estado ni
 *     titularidad (`QA 2`, Art. III.3).
 *  4. **Volcado por baja de cuenta atómico.** Todo el lote entra o no entra, y
 *     los elementos ya volcados se omiten para que un reintento no duplique
 *     notas (`QA 5`, Art. III.3).
 *
 * ## Lo que este repositorio no hace
 *
 *  - No calcula el sello temporal efectivo: lo recibe ya resuelto por
 *    `OfflineSyncMetadata` y lo persiste junto al sello original del
 *    dispositivo (`RF-04.3`, `QA 4`).
 *  - No decide códigos HTTP ni valida transiciones de estado: eso es del
 *    servicio y del controlador. Aquí sólo se rechaza un agregado incoherente
 *    antes de escribir.
 *  - No borra nada: `offline_sync_mutations` porta
 *    `trg_no_delete_offline_sync_mutations` (Art. III.1).
 *  - No expone coste, precio ni importe alguno en el paquete de ruta
 *    (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, transacciones explícitas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoOfflineSyncMutationRepository implements OfflineSyncMutationRepositoryInterface
{
    /** Estados correctivos que el técnico ve en su ruta (Módulo 11 incluido). */
    private const ROUTE_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO'];

    /** Órdenes preventivas asignadas o en curso (RF-02.1). */
    private const PREVENTIVE_STATUSES = ['SCHEDULED', 'IN_INSPECTION'];

    /** Últimos eventos de historial que viajan en cada parada (RF-02.1). */
    private const RECENT_HISTORY_LIMIT = 5;

    private const ACTION_CONFLICT_DUMPED = 'OFFLINE_CONFLICT_DUMPED';
    private const ACTION_DEACTIVATED_DUMP = 'OFFLINE_DEACTIVATED_ACCOUNT_DUMP';

    private const HEADER_CONFLICT = '[VOLCADO OFFLINE EN CONFLICTO';
    private const HEADER_DEACTIVATED = '[VOLCADO AUTOMÁTICO POR BAJA DE CUENTA';

    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    /** Repositorio de incidencias para el contexto oportunista de la ruta. */
    private ?PdoIncidentRepository $incidentRepository = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function findByTechnicianAndMutationId(int $technicianId, string $clientMutationId): ?OfflineSyncMutation
    {
        if ($technicianId < 1 || !OfflineSyncMutation::isValidMutationId($clientMutationId)) {
            return null;
        }

        $row = $this->fetchOne(
            'SELECT * FROM offline_sync_mutations
              WHERE technician_id = :technician_id
                AND client_mutation_id = :client_mutation_id
              LIMIT 1',
            [
                ':technician_id' => $technicianId,
                ':client_mutation_id' => trim($clientMutationId),
            ]
        );

        return $row === null ? null : $this->hydrateMutation($row);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchBootstrapSnapshotForTechnician(int $technicianId): array
    {
        if ($technicianId < 1) {
            throw new InvalidArgumentException('El paquete de ruta exige un técnico autenticado.');
        }

        $routeStops = $this->fetchRouteStops($technicianId);
        $this->attachRefundInspections($routeStops);
        $this->attachRecentHistory($routeStops);

        $preventive = $this->fetchPreventiveOrders($technicianId);
        $checklists = $this->fetchPreventiveChecklists($preventive);

        $machinesOnRoute = [];
        foreach ($routeStops as $stop) {
            $machinesOnRoute[(int)$stop['machine']['id']] = (string)$stop['machine']['model'];
        }
        foreach ($preventive as $order) {
            $machinesOnRoute[(int)$order['machine_id']] = (string)$order['machine_model'];
        }

        return [
            'route' => [
                'stops' => array_values($routeStops),
                'enabled_locations' => $this->incidentContext()->findOpportunisticEnabledLocations(
                    $technicianId,
                    new DateTimeImmutable('now'),
                    false
                ),
                'pending_critical_stops_other_locations' => $this->incidentContext()
                    ->findPendingCriticalStopsInOtherLocations($technicianId, 0),
            ],
            'preventive_orders' => array_values($preventive),
            'preventive_checklists_by_order' => $checklists,
            'spare_parts_catalog_by_machine' => $this->fetchSparePartsCatalogByMachine($machinesOnRoute),
            'van_stock' => $this->fetchVanStock($technicianId),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function recordAppliedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation
    {
        if (!$mutation->isApplied()) {
            throw new InvalidArgumentException(
                '`recordAppliedMutation` sólo admite mutaciones con resultado APPLIED.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $existing = $this->findByTechnicianAndMutationId($mutation->technicianId, $mutation->clientMutationId);
            if ($existing !== null) {
                $this->pdo->commit();

                return $existing;
            }

            $mutationId = $this->insertMutation($mutation);

            $this->recordAudit(
                entityType: $this->auditEntityType($mutation->stopType),
                entityId: $mutation->stopId,
                action: $mutation->operationType,
                actorUserId: $mutation->technicianId,
                createdAt: $mutation->syncedAt,
                newState: [
                    'offline_sync' => $this->offlineSyncEnvelope($mutation),
                    'operation_type' => $mutation->operationType,
                    'outcome_status' => OfflineSyncMutation::OUTCOME_APPLIED,
                    'response_http_status' => $mutation->responseHttpStatus,
                ]
            );

            $this->pdo->commit();

            return $this->findPersistedMutation($mutationId);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($this->isUniqueViolation($error)) {
                $existing = $this->findByTechnicianAndMutationId(
                    $mutation->technicianId,
                    $mutation->clientMutationId
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $error;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function recordConflictDump(
        OfflineSyncMutation $mutation,
        string $conflictReason,
        array $workSummary,
        ?string $photoPath = null
    ): array {
        if (!$mutation->isConflictDumped()) {
            throw new InvalidArgumentException(
                '`recordConflictDump` exige una mutación con resultado CONFLICT_DUMPED.'
            );
        }

        $trimmedReason = trim($conflictReason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('El volcado de conflicto exige explicar por qué se rechazó la parada.');
        }

        $this->pdo->beginTransaction();

        try {
            $existing = $this->findByTechnicianAndMutationId($mutation->technicianId, $mutation->clientMutationId);
            if ($existing !== null) {
                $this->pdo->commit();

                return [
                    'mutation' => $existing,
                    'internal_comment_id' => null,
                    'audit_log_id' => 0,
                ];
            }

            $actor = $this->resolveActor($mutation->technicianId);
            $internalCommentId = null;

            if ($mutation->stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT) {
                $incident = $this->lockIncident($mutation->stopId);
                if ($incident === null) {
                    throw new IncidentNotFoundException($mutation->stopId);
                }

                $internalCommentId = $this->insertInternalComment(
                    incidentId: $mutation->stopId,
                    actorUserId: $mutation->technicianId,
                    actorName: $actor['name'],
                    text: $this->buildDumpText(self::HEADER_CONFLICT, $mutation, $trimmedReason, $workSummary)
                        . "\n\nEstado del expediente al volcar: " . (string)$incident['status'] . '.',
                    photoPath: $photoPath,
                    createdAt: $mutation->syncedAt
                );
            } else {
                $this->appendPreventiveAuditNote($mutation->stopId, $this->buildDumpText(
                    self::HEADER_CONFLICT,
                    $mutation,
                    $trimmedReason,
                    $workSummary
                ));
            }

            $mutationId = $this->insertMutation($mutation);
            $payload = [
                'client_mutation_id' => $mutation->clientMutationId,
                'outcome_status' => OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
                'stop_type' => $mutation->stopType,
                'stop_id' => $mutation->stopId,
                'internal_comment_id' => $internalCommentId,
                'synced_at' => $mutation->syncedAt,
                'message' => 'El trabajo realizado sin conexión se ha guardado como Nota Interna de Taller en el expediente.',
            ];
            $this->storeMutationResponse($mutationId, $payload);

            $auditId = $this->recordAudit(
                entityType: $this->auditEntityType($mutation->stopType),
                entityId: $mutation->stopId,
                action: self::ACTION_CONFLICT_DUMPED,
                actorUserId: $mutation->technicianId,
                createdAt: $mutation->syncedAt,
                newState: [
                    'client_mutation_id' => $mutation->clientMutationId,
                    'operation_type' => $mutation->operationType,
                    'client_occurred_at' => $mutation->clientOccurredAt,
                    'synced_at' => $mutation->syncedAt,
                    'conflict_reason' => $trimmedReason,
                    'work_summary' => $workSummary,
                    'internal_comment_id' => $internalCommentId,
                ]
            );

            $this->pdo->commit();

            return [
                'mutation' => $this->findPersistedMutation($mutationId),
                'internal_comment_id' => $internalCommentId,
                'audit_log_id' => $auditId,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function recordDeactivatedAccountDumpBatch(int $technicianId, array $mutations): array
    {
        if ($mutations === []) {
            return ['dumped' => 0, 'mutations' => [], 'internal_comment_ids' => []];
        }

        foreach ($mutations as $mutation) {
            if (!$mutation instanceof OfflineSyncMutation || !$mutation->isDeactivatedAccountDump()) {
                throw new InvalidArgumentException(
                    'El volcado por baja de cuenta sólo admite mutaciones con resultado DEACTIVATED_ACCOUNT_DUMP.'
                );
            }

            if ($mutation->technicianId !== $technicianId) {
                throw new InvalidArgumentException(
                    'El lote de baja de cuenta no puede mezclar mutaciones de dos técnicos (Art. V.4).'
                );
            }
        }

        $this->pdo->beginTransaction();

        try {
            $actor = $this->resolveActor($technicianId);
            $dumped = 0;
            $internalCommentIds = [];
            $persisted = [];

            foreach ($mutations as $mutation) {
                $existing = $this->findByTechnicianAndMutationId($technicianId, $mutation->clientMutationId);
                if ($existing !== null) {
                    $persisted[] = $existing;
                    continue;
                }

                $internalCommentId = null;
                $targetFound = false;
                $targetStatus = null;

                if ($mutation->stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT) {
                    $incident = $this->lockIncident($mutation->stopId);
                    if ($incident !== null) {
                        $targetFound = true;
                        $targetStatus = (string)$incident['status'];
                        $internalCommentId = $this->insertInternalComment(
                            incidentId: $mutation->stopId,
                            actorUserId: $technicianId,
                            actorName: $actor['name'],
                            text: $this->buildDumpText(
                                self::HEADER_DEACTIVATED,
                                $mutation,
                                null,
                                $this->workSummaryFromPayload($mutation)
                            ),
                            photoPath: null,
                            createdAt: $mutation->syncedAt
                        );
                        $internalCommentIds[] = $internalCommentId;
                    }
                } else {
                    $preventive = $this->lockPreventiveOrder($mutation->stopId);
                    if ($preventive !== null) {
                        $targetFound = true;
                        $targetStatus = (string)$preventive['status'];
                        $this->appendPreventiveAuditNote(
                            $mutation->stopId,
                            $this->buildDumpText(
                                self::HEADER_DEACTIVATED,
                                $mutation,
                                null,
                                $this->workSummaryFromPayload($mutation)
                            )
                        );
                    }
                }

                $mutationId = $this->insertMutation($mutation);
                $payload = [
                    'client_mutation_id' => $mutation->clientMutationId,
                    'outcome_status' => OfflineSyncMutation::OUTCOME_DEACTIVATED_ACCOUNT_DUMP,
                    'stop_type' => $mutation->stopType,
                    'stop_id' => $mutation->stopId,
                    'target_found' => $targetFound,
                    'internal_comment_id' => $internalCommentId,
                    'synced_at' => $mutation->syncedAt,
                ];
                $this->storeMutationResponse($mutationId, $payload);

                $this->recordAudit(
                    entityType: $this->auditEntityType($mutation->stopType),
                    entityId: $mutation->stopId,
                    action: self::ACTION_DEACTIVATED_DUMP,
                    actorUserId: $technicianId,
                    createdAt: $mutation->syncedAt,
                    resolvedActor: $actor,
                    newState: [
                        'client_mutation_id' => $mutation->clientMutationId,
                        'operation_type' => $mutation->operationType,
                        'client_occurred_at' => $mutation->clientOccurredAt,
                        'synced_at' => $mutation->syncedAt,
                        'work_summary' => $this->workSummaryFromPayload($mutation),
                        'target_found' => $targetFound,
                        'target_status' => $targetStatus,
                        'internal_comment_id' => $internalCommentId,
                    ]
                );

                $persisted[] = $this->findPersistedMutation($mutationId);
                $dumped++;
            }

            $this->pdo->commit();

            return [
                'dumped' => $dumped,
                'mutations' => $persisted,
                'internal_comment_ids' => $internalCommentIds,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    // ---------------------------------------------------------------------
    // Bootstrap: ruta correctiva
    // ---------------------------------------------------------------------

    /**
     * Paradas correctivas vivas del técnico, con máquina y sede (1 consulta).
     *
     * @return array<int, array<string, mixed>> indexado por identificador de avería
     */
    private function fetchRouteStops(int $technicianId): array
    {
        [$statusPlaceholders, $statusParams] = $this->placeholders('route_status', self::ROUTE_STATUSES);

        $rows = $this->fetchAll(
            'SELECT i.id, i.ticket_code, i.status, i.urgency, i.category, i.description,
                    i.assigned_at, i.started_at, i.paused_at,
                    i.pending_info_reason_category, i.pending_info_reason_text,
                    i.sla_target_at, i.cycle_started_at,
                    m.id AS machine_id, m.code AS machine_code, m.model AS machine_model,
                    m.machine_type, m.floor_wing, m.sanitary_status, m.is_blocked_no_access,
                    l.id AS location_id, l.name AS location_name, l.site_code,
                    l.address, l.latitude, l.longitude,
                    l.contact_name, l.contact_phone, l.has_physical_reception
               FROM incidents i
               JOIN machines m ON m.id = i.machine_id
               JOIN locations l ON l.id = i.location_id
              WHERE i.assigned_technician_id = :technician_id
                AND i.deleted_at IS NULL
                AND i.status IN (' . $statusPlaceholders . ')
              ORDER BY CASE i.urgency
                           WHEN \'CRITICAL\' THEN 0
                           WHEN \'HIGH\' THEN 1
                           WHEN \'MEDIUM\' THEN 2
                           ELSE 3
                       END ASC,
                       CASE WHEN i.sla_target_at IS NULL THEN 1 ELSE 0 END ASC,
                       i.sla_target_at ASC,
                       i.id ASC',
            [':technician_id' => $technicianId] + $statusParams
        );

        $stops = [];
        foreach ($rows as $row) {
            $incidentId = (int)$row['id'];
            $stops[$incidentId] = [
                'id' => $incidentId,
                'incident_id' => $incidentId,
                'ticket_code' => (string)$row['ticket_code'],
                'status' => (string)$row['status'],
                'urgency' => (string)$row['urgency'],
                'category' => (string)$row['category'],
                'description' => (string)$row['description'],
                'assigned_at' => $this->nullableString($row['assigned_at']),
                'started_at' => $this->nullableString($row['started_at']),
                'paused_at' => $this->nullableString($row['paused_at']),
                'pending_info' => [
                    'category' => $this->nullableString($row['pending_info_reason_category']),
                    'reason' => $this->nullableString($row['pending_info_reason_text']),
                ],
                'sla_target_at' => $this->nullableString($row['sla_target_at']),
                'cycle_started_at' => $this->nullableString($row['cycle_started_at']),
                'machine' => [
                    'id' => (int)$row['machine_id'],
                    'code' => (string)$row['machine_code'],
                    'model' => (string)$row['machine_model'],
                    'machine_type' => (string)$row['machine_type'],
                    'floor_wing' => (string)$row['floor_wing'],
                    'sanitary_status' => (string)$row['sanitary_status'],
                    'is_blocked_no_access' => $this->toBool($row['is_blocked_no_access']),
                ],
                'location' => [
                    'id' => (int)$row['location_id'],
                    'name' => (string)$row['location_name'],
                    'site_code' => (string)$row['site_code'],
                    'address' => (string)$row['address'],
                    'latitude' => (float)$row['latitude'],
                    'longitude' => (float)$row['longitude'],
                    'contact_name' => $this->nullableString($row['contact_name']),
                    'contact_phone' => $this->nullableString($row['contact_phone']),
                    'has_physical_reception' => $this->toBool($row['has_physical_reception']),
                ],
                'pending_refund_inspections' => [],
                'recent_history' => [],
            ];
        }

        return $stops;
    }

    /**
     * Reintegros pendientes de inspección de cada parada (1 consulta).
     *
     * El técnico necesita saber, sin red, qué casos de monedero debe revisar
     * in situ; nunca se publica el IBAN ni el teléfono Bizum del reclamante
     * (Art. V.4).
     *
     * @param array<int, array<string, mixed>> $stops
     */
    private function attachRefundInspections(array &$stops): void
    {
        if ($stops === []) {
            return;
        }

        [$placeholders, $params] = $this->placeholders('refund_incident', array_keys($stops));

        $rows = $this->fetchAll(
            'SELECT id, incident_id, claimed_amount, status, created_at
               FROM refund_requests
              WHERE incident_id IN (' . $placeholders . ')
                AND status = \'PENDING_INSPECTION\'
                AND is_active = ' . $this->dialect->booleanLiteral(true) . '
                AND deleted_at IS NULL
              ORDER BY created_at ASC, id ASC',
            $params
        );

        foreach ($rows as $row) {
            $incidentId = (int)$row['incident_id'];
            if (!isset($stops[$incidentId])) {
                continue;
            }

            $stops[$incidentId]['pending_refund_inspections'][] = [
                'id' => (int)$row['id'],
                'claimed_amount' => (float)$row['claimed_amount'],
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
            ];
        }
    }

    /**
     * Últimos cinco eventos de historial de cada parada (1 consulta).
     *
     * @param array<int, array<string, mixed>> $stops
     */
    private function attachRecentHistory(array &$stops): void
    {
        if ($stops === []) {
            return;
        }

        [$placeholders, $params] = $this->placeholders('history_incident', array_keys($stops));

        $rows = $this->fetchAll(
            'SELECT recent.id, recent.incident_id, recent.from_status, recent.to_status,
                    recent.action_note, recent.created_at, recent.user_id, u.name AS user_name
               FROM (
                   SELECT h.id, h.incident_id, h.from_status, h.to_status, h.action_note,
                          h.created_at, h.user_id,
                          ROW_NUMBER() OVER (
                              PARTITION BY h.incident_id
                              ORDER BY h.created_at DESC, h.id DESC
                          ) AS position
                     FROM incident_history h
                    WHERE h.incident_id IN (' . $placeholders . ')
               ) recent
               LEFT JOIN users u ON u.id = recent.user_id
              WHERE recent.position <= ' . self::RECENT_HISTORY_LIMIT . '
              ORDER BY recent.incident_id ASC, recent.created_at ASC, recent.id ASC',
            $params
        );

        foreach ($rows as $row) {
            $incidentId = (int)$row['incident_id'];
            if (!isset($stops[$incidentId])) {
                continue;
            }

            $stops[$incidentId]['recent_history'][] = [
                'from_status' => $this->nullableString($row['from_status']),
                'to_status' => (string)$row['to_status'],
                'action_note' => $this->nullableString($row['action_note']),
                'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'user_name' => $this->nullableString($row['user_name']),
                'created_at' => (string)$row['created_at'],
            ];
        }
    }

    // ---------------------------------------------------------------------
    // Bootstrap: órdenes preventivas
    // ---------------------------------------------------------------------

    /**
     * Órdenes preventivas asignadas o en curso (1 consulta).
     *
     * @return array<int, array<string, mixed>> indexado por identificador de orden
     */
    private function fetchPreventiveOrders(int $technicianId): array
    {
        [$statusPlaceholders, $statusParams] = $this->placeholders('preventive_status', self::PREVENTIVE_STATUSES);

        $rows = $this->fetchAll(
            'SELECT po.id, po.order_code, po.machine_id, po.location_id, po.status, po.order_type,
                    po.scheduled_date, po.due_date, po.started_at, po.notes, po.is_quarantine_triggered,
                    m.code AS machine_code, m.model AS machine_model, m.machine_type, m.sanitary_status,
                    l.name AS location_name, l.site_code
               FROM preventive_orders po
               JOIN machines m ON m.id = po.machine_id
               JOIN locations l ON l.id = po.location_id
              WHERE po.assigned_technician_id = :technician_id
                AND po.deleted_at IS NULL
                AND po.status IN (' . $statusPlaceholders . ')
              ORDER BY po.due_date ASC, po.id ASC',
            [':technician_id' => $technicianId] + $statusParams
        );

        $orders = [];
        foreach ($rows as $row) {
            $orderId = (int)$row['id'];
            $orders[$orderId] = [
                'id' => $orderId,
                'order_code' => (string)$row['order_code'],
                'status' => (string)$row['status'],
                'order_type' => (string)$row['order_type'],
                'scheduled_date' => (string)$row['scheduled_date'],
                'due_date' => (string)$row['due_date'],
                'started_at' => $this->nullableString($row['started_at']),
                'notes' => $this->nullableString($row['notes']),
                'is_quarantine_triggered' => $this->toBool($row['is_quarantine_triggered']),
                'machine_id' => (int)$row['machine_id'],
                'machine_code' => (string)$row['machine_code'],
                'machine_model' => (string)$row['machine_model'],
                'machine_type' => (string)$row['machine_type'],
                'sanitary_status' => (string)$row['sanitary_status'],
                'location_id' => (int)$row['location_id'],
                'location_name' => (string)$row['location_name'],
                'site_code' => (string)$row['site_code'],
            ];
        }

        return $orders;
    }

    /**
     * Check List de cada orden preventiva (1 consulta), con la forma del
     * contrato: `{ "<order_id>": { "order": {...}, "items": [...] } }`.
     *
     * @param array<int, array<string, mixed>> $orders Órdenes ya proyectadas.
     * @return array<int, array{order: array<string, mixed>, items: list<array<string, mixed>>}>
     */
    private function fetchPreventiveChecklists(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        [$placeholders, $params] = $this->placeholders('checklist_order', array_keys($orders));

        $rows = $this->fetchAll(
            'SELECT id, preventive_order_id, item_code, item_description, is_critical,
                    status, observations, photo_path
               FROM preventive_order_items
              WHERE preventive_order_id IN (' . $placeholders . ')
              ORDER BY preventive_order_id ASC, is_critical DESC, id ASC',
            $params
        );

        $itemsByOrder = [];
        foreach ($rows as $row) {
            $itemsByOrder[(int)$row['preventive_order_id']][] = [
                'id' => (int)$row['id'],
                'item_code' => (string)$row['item_code'],
                'item_description' => (string)$row['item_description'],
                'is_critical' => $this->toBool($row['is_critical']),
                'status' => (string)$row['status'],
                'observations' => $this->nullableString($row['observations']),
                'photo_path' => $this->nullableString($row['photo_path']),
            ];
        }

        $checklists = [];
        foreach ($orders as $orderId => $order) {
            $checklists[$orderId] = [
                'order' => $order,
                'items' => $itemsByOrder[$orderId] ?? [],
            ];
        }

        return $checklists;
    }

    // ---------------------------------------------------------------------
    // Bootstrap: catálogo y furgoneta
    // ---------------------------------------------------------------------

    /**
     * Catálogo activo compatible de cada máquina de la ruta (1 consulta).
     *
     * La compatibilidad es por modelo, así que se consulta una vez por el
     * conjunto de modelos presentes y se cruza en PHP por máquina. Sin coste
     * de referencia (Art. V.4).
     *
     * @param array<int, string> $machineModels máquina => modelo
     * @return array<int, array{compatible_parts: list<array<string, int|string>>}>
     */
    private function fetchSparePartsCatalogByMachine(array $machineModels): array
    {
        if ($machineModels === []) {
            return [];
        }

        $normalizedModels = [];
        foreach ($machineModels as $model) {
            $key = $this->modelKey($model);
            if ($key !== '') {
                $normalizedModels[$key] = $key;
            }
        }

        $catalog = [];
        if ($normalizedModels !== []) {
            [$placeholders, $params] = $this->placeholders('catalog_model', array_values($normalizedModels));

            $rows = $this->fetchAll(
                'SELECT DISTINCT sp.id AS spare_part_id, sp.part_code, sp.name AS part_name,
                        sp.category, spc.machine_model
                   FROM spare_parts sp
                   JOIN spare_part_compatibilities spc
                     ON spc.spare_part_id = sp.id
                    AND spc.is_active = ' . $this->dialect->booleanLiteral(true) . '
                    AND spc.deleted_at IS NULL
                  WHERE sp.is_active = ' . $this->dialect->booleanLiteral(true) . '
                    AND sp.deleted_at IS NULL
                    AND TRIM(LOWER(spc.machine_model)) IN (' . $placeholders . ')
                  ORDER BY sp.part_code ASC, sp.id ASC',
                $params
            );

            foreach ($rows as $row) {
                $key = $this->modelKey((string)$row['machine_model']);
                $partId = (int)$row['spare_part_id'];
                $catalog[$key][$partId] = [
                    'spare_part_id' => $partId,
                    'part_code' => (string)$row['part_code'],
                    'part_name' => (string)$row['part_name'],
                    'category' => (string)$row['category'],
                ];
            }
        }

        $byMachine = [];
        foreach ($machineModels as $machineId => $model) {
            $modelKey = $this->modelKey($model);
            $byMachine[$machineId] = [
                'compatible_parts' => array_values($catalog[$modelKey] ?? []),
            ];
        }

        return $byMachine;
    }

    /**
     * Saldo de furgoneta, orden despachada pendiente de recepción y última
     * carga confirmada (3 consultas fijas, sin N+1 por línea).
     *
     * @return array{
     *   items: list<array<string, int|string>>,
     *   active_dispatched_order: array<string, mixed>|null,
     *   last_confirmed_order: array<string, mixed>|null
     * }
     */
    private function fetchVanStock(int $technicianId): array
    {
        $stockRows = $this->fetchAll(
            'SELECT vs.spare_part_id, sp.part_code, sp.name AS part_name, sp.category,
                    vs.quantity_available, vs.min_threshold
               FROM technician_van_stocks vs
               JOIN spare_parts sp ON sp.id = vs.spare_part_id
              WHERE vs.technician_id = :technician_id
              ORDER BY sp.part_code ASC, vs.spare_part_id ASC',
            [':technician_id' => $technicianId]
        );

        $items = [];
        foreach ($stockRows as $row) {
            $items[] = [
                'spare_part_id' => (int)$row['spare_part_id'],
                'part_code' => (string)$row['part_code'],
                'part_name' => (string)$row['part_name'],
                'category' => (string)$row['category'],
                'quantity_available' => (int)$row['quantity_available'],
                'min_threshold' => (int)$row['min_threshold'],
            ];
        }

        $orderRows = $this->fetchAll(
            'SELECT id, status, notes, dispatched_at, confirmed_at, reception_note
               FROM van_restock_orders
              WHERE technician_id = :technician_id
                AND status IN (\'DISPATCHED\', \'CONFIRMED_BY_TECH\')
              ORDER BY updated_at DESC, id DESC',
            [':technician_id' => $technicianId]
        );

        $dispatchedRow = null;
        $confirmedRow = null;
        foreach ($orderRows as $row) {
            if ($dispatchedRow === null && (string)$row['status'] === 'DISPATCHED') {
                $dispatchedRow = $row;
            }
            if ($confirmedRow === null && (string)$row['status'] === 'CONFIRMED_BY_TECH') {
                $confirmedRow = $row;
            }
        }

        $itemsByOrder = $this->fetchRestockItems(
            array_values(array_filter([
                $dispatchedRow !== null ? (int)$dispatchedRow['id'] : null,
                $confirmedRow !== null ? (int)$confirmedRow['id'] : null,
            ]))
        );

        return [
            'items' => $items,
            'active_dispatched_order' => $dispatchedRow === null
                ? null
                : $this->projectRestockOrder($dispatchedRow, $itemsByOrder[(int)$dispatchedRow['id']] ?? []),
            'last_confirmed_order' => $confirmedRow === null
                ? null
                : $this->projectRestockOrder($confirmedRow, $itemsByOrder[(int)$confirmedRow['id']] ?? []),
        ];
    }

    /**
     * Líneas vigentes de las órdenes de carga referenciadas (1 consulta).
     *
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function fetchRestockItems(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->placeholders('restock_order', $orderIds);

        $rows = $this->fetchAll(
            'SELECT i.order_id, i.spare_part_id,
                    COALESCE(i.part_code_snapshot, sp.part_code) AS part_code,
                    COALESCE(i.part_name_snapshot, sp.name) AS part_name,
                    COALESCE(i.category_snapshot, sp.category::text) AS category,
                    i.requested_qty, i.dispatched_qty, i.reason
               FROM van_restock_order_items i
               LEFT JOIN spare_parts sp ON sp.id = i.spare_part_id
              WHERE i.order_id IN (' . $placeholders . ')
                AND i.removed_at IS NULL
              ORDER BY i.order_id ASC, i.id ASC',
            $params
        );

        $items = [];
        foreach ($rows as $row) {
            $items[(int)$row['order_id']][] = [
                'spare_part_id' => (int)$row['spare_part_id'],
                'part_code' => (string)$row['part_code'],
                'part_name' => (string)$row['part_name'],
                'category' => (string)$row['category'],
                'requested_qty' => (int)$row['requested_qty'],
                'dispatched_qty' => $row['dispatched_qty'] !== null ? (int)$row['dispatched_qty'] : null,
                'reason' => (string)$row['reason'],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function projectRestockOrder(array $row, array $items): array
    {
        $orderId = (int)$row['id'];

        return [
            'id' => $orderId,
            'order_code' => 'RO-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
            'status' => (string)$row['status'],
            'notes' => $this->nullableString($row['notes']),
            'dispatched_at' => $this->nullableString($row['dispatched_at']),
            'confirmed_at' => $this->nullableString($row['confirmed_at']),
            'reception_note' => $this->nullableString($row['reception_note']),
            'items' => $items,
        ];
    }

    // ---------------------------------------------------------------------
    // Escritura del libro de sincronización
    // ---------------------------------------------------------------------

    private function insertMutation(OfflineSyncMutation $mutation): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO offline_sync_mutations (
                technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                outcome_status, client_occurred_at, chain_started_at, effective_occurred_at,
                synced_at, clock_skew_adjusted, sanitary_seal_confirmed,
                response_http_status, response_payload
             ) VALUES (
                :technician_id, :client_mutation_id, :stop_type, :stop_id, :operation_type,
                :outcome_status, :client_occurred_at, :chain_started_at, :effective_occurred_at,
                :synced_at, :clock_skew_adjusted, :sanitary_seal_confirmed,
                :response_http_status, :response_payload
             )'
        );

        $stmt->bindValue(':technician_id', $mutation->technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':client_mutation_id', $mutation->clientMutationId, PDO::PARAM_STR);
        $stmt->bindValue(':stop_type', $mutation->stopType, PDO::PARAM_STR);
        $stmt->bindValue(':stop_id', $mutation->stopId, PDO::PARAM_INT);
        $stmt->bindValue(':operation_type', $mutation->operationType, PDO::PARAM_STR);
        $stmt->bindValue(':outcome_status', $mutation->outcomeStatus, PDO::PARAM_STR);
        $stmt->bindValue(':client_occurred_at', $mutation->clientOccurredAt, PDO::PARAM_STR);
        $stmt->bindValue(
            ':chain_started_at',
            $mutation->chainStartedAt,
            $mutation->chainStartedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':effective_occurred_at', $mutation->effectiveOccurredAt, PDO::PARAM_STR);
        $stmt->bindValue(':synced_at', $mutation->syncedAt, PDO::PARAM_STR);
        $stmt->bindValue(':clock_skew_adjusted', $mutation->clockSkewAdjusted, PDO::PARAM_BOOL);
        $stmt->bindValue(':sanitary_seal_confirmed', $mutation->sanitarySealConfirmed, PDO::PARAM_BOOL);
        $stmt->bindValue(':response_http_status', $mutation->responseHttpStatus, PDO::PARAM_INT);
        $stmt->bindValue(
            ':response_payload',
            $this->encodeJson($mutation->responsePayload),
            PDO::PARAM_STR
        );
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    private function findPersistedMutation(int $mutationId): OfflineSyncMutation
    {
        $row = $this->fetchOne(
            'SELECT * FROM offline_sync_mutations WHERE id = :id LIMIT 1',
            [':id' => $mutationId]
        );

        if ($row === null) {
            throw new DomainException("No se pudo releer la mutación sincronizada {$mutationId}.");
        }

        return $this->hydrateMutation($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeMutationResponse(int $mutationId, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE offline_sync_mutations
                SET response_payload = :response_payload
              WHERE id = :id'
        );
        $stmt->bindValue(':response_payload', $this->encodeJson($payload), PDO::PARAM_STR);
        $stmt->bindValue(':id', $mutationId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // ---------------------------------------------------------------------
    // Volcado de auditoría
    // ---------------------------------------------------------------------

    /**
     * @return array{id: int, status: string, assigned_technician_id: int|null}|null
     */
    private function lockIncident(int $incidentId): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, status, assigned_technician_id
               FROM incidents
              WHERE id = :incident_id
                AND deleted_at IS NULL
              FOR UPDATE',
            [':incident_id' => $incidentId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'assigned_technician_id' => $row['assigned_technician_id'] !== null
                ? (int)$row['assigned_technician_id']
                : null,
        ];
    }

    /**
     * @return array{id: int, status: string, notes: string|null}|null
     */
    private function lockPreventiveOrder(int $orderId): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, status, notes
               FROM preventive_orders
              WHERE id = :order_id
                AND deleted_at IS NULL
              FOR UPDATE',
            [':order_id' => $orderId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'notes' => $this->nullableString($row['notes']),
        ];
    }

    /**
     * Anexa el bloque al campo libre de la orden preventiva sin tocar su
     * estado ni su titularidad (QA 2).
     *
     * `notes` es la única columna de observaciones que la tabla declara; el
     * bloque se concatena con dos saltos de línea y nunca sustituye lo que
     * Coordinación hubiera escrito.
     */
    private function appendPreventiveAuditNote(int $orderId, string $block): void
    {
        $order = $this->lockPreventiveOrder($orderId);
        if ($order === null) {
            throw new DomainException("No existe la orden preventiva {$orderId} que se pretende volcar.");
        }

        // Un nombre de parámetro por posición: el motor nativo rechaza el mismo
        // marcador dos veces en una sola sentencia.
        $stmt = $this->pdo->prepare(
            'UPDATE preventive_orders
                SET notes = CASE
                        WHEN notes IS NULL OR btrim(notes) = \'\' THEN :block_first
                        ELSE notes || chr(10) || chr(10) || :block_appended
                    END
              WHERE id = :order_id'
        );
        $stmt->bindValue(':block_first', $block, PDO::PARAM_STR);
        $stmt->bindValue(':block_appended', $block, PDO::PARAM_STR);
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function insertInternalComment(
        int $incidentId,
        int $actorUserId,
        string $actorName,
        string $text,
        ?string $photoPath,
        string $createdAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incident_comments (
                incident_id, author_type, user_id, author_name, comment_text,
                photo_path, is_internal, created_at
             ) VALUES (
                :incident_id, :author_type, :user_id, :author_name, :comment_text,
                :photo_path, :is_internal, :created_at
             )'
        );
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':author_type', 'TECHNICIAN', PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $actorUserId, PDO::PARAM_INT);
        $stmt->bindValue(':author_name', $actorName, PDO::PARAM_STR);
        $stmt->bindValue(':comment_text', $text, PDO::PARAM_STR);
        $stmt->bindValue(
            ':photo_path',
            $photoPath,
            $photoPath !== null && trim($photoPath) !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':is_internal', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':created_at', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Texto estructurado del volcado, en castellano neutro y sin datos de
     * terceros. La firma se describe textualmente, nunca se adjunta aquí: su
     * PNG vive en el albarán de servicio y está exento de la cuota de fotos
     * (QA 3).
     *
     * @param array<string, mixed> $workSummary
     */
    private function buildDumpText(
        string $header,
        OfflineSyncMutation $mutation,
        ?string $conflictReason,
        array $workSummary
    ): string {
        $lines = [
            $header . ' · Ejecutado in situ: ' . $mutation->clientOccurredAt
                . ' · Sincronizado: ' . $mutation->syncedAt . ']',
            'Operación: ' . $mutation->operationType . ' · Identificador: ' . $mutation->clientMutationId,
        ];

        if ($conflictReason !== null && trim($conflictReason) !== '') {
            $lines[] = 'Motivo del conflicto: ' . trim($conflictReason);
        }

        $diagnosis = $this->summaryText($workSummary['diagnosis'] ?? null);
        if ($diagnosis !== null) {
            $lines[] = 'Diagnóstico: ' . $diagnosis;
        }

        $actionTaken = $this->summaryText($workSummary['action_taken'] ?? null);
        if ($actionTaken !== null) {
            $lines[] = 'Acción realizada: ' . $actionTaken;
        }

        $pauseReason = $this->summaryText($workSummary['pause_reason'] ?? null);
        if ($pauseReason !== null) {
            $lines[] = 'Motivo de la pausa: ' . $pauseReason;
        }

        $parts = $workSummary['replaced_parts'] ?? [];
        if (is_array($parts) && $parts !== []) {
            $lines[] = 'Piezas declaradas:';
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }

                $code = $this->summaryText($part['part_code'] ?? null)
                    ?? ('#' . (string)($part['spare_part_id'] ?? 'sin-catálogo'));
                $name = $this->summaryText($part['part_name'] ?? null) ?? 'sin nombre';
                $quantity = (int)($part['quantity'] ?? 0);
                $lines[] = sprintf('  - %s · %s · %d ud.', $code, $name, $quantity);
            }
        }

        $conformity = $this->summaryText($workSummary['conformity_summary'] ?? null);
        if ($conformity !== null) {
            $lines[] = 'Conformidad: ' . $conformity;
        }

        return implode("\n", $lines);
    }

    /**
     * Resumen de trabajo que viaja en el volcado por baja de cuenta.
     *
     * El servicio deposita el informe in situ en `response_payload` antes de
     * llamar al lote; el libro de sincronización es el único soporte que
     * sobrevive al cierre de la sesión del técnico desactivado (QA 5).
     *
     * @return array<string, mixed>
     */
    private function workSummaryFromPayload(OfflineSyncMutation $mutation): array
    {
        $summary = $mutation->responsePayload['work_summary'] ?? [];

        return is_array($summary) ? $summary : [];
    }

    private function summaryText(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Auditoría
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $newState
     */
    private function recordAudit(
        string $entityType,
        int $entityId,
        string $action,
        int $actorUserId,
        string $createdAt,
        array $newState,
        ?array $resolvedActor = null
    ): int {
        $actor = $resolvedActor ?? $this->resolveActor($actorUserId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata, created_at
             ) VALUES (
                :entity_type, :entity_id, :action, :user_id, :user_role, :user_name,
                :previous_state, :new_state, :metadata, :created_at
             )'
        );

        $stmt->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $actorUserId, PDO::PARAM_INT);
        $stmt->bindValue(':user_role', $actor['role'], PDO::PARAM_STR);
        $stmt->bindValue(':user_name', $actor['name'], PDO::PARAM_STR);
        $stmt->bindValue(':previous_state', null, PDO::PARAM_NULL);
        $stmt->bindValue(':new_state', $this->encodeJson($newState), PDO::PARAM_STR);
        $stmt->bindValue(':metadata', null, PDO::PARAM_NULL);
        $stmt->bindValue(':created_at', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array{role: string, name: string}
     */
    private function resolveActor(int $userId): array
    {
        $row = $this->fetchOne(
            'SELECT role, name FROM users WHERE id = :user_id LIMIT 1',
            [':user_id' => $userId]
        );

        if ($row === null) {
            throw new InvalidArgumentException("No existe el usuario {$userId} que firma la sincronización.");
        }

        return [
            'role' => strtoupper((string)$row['role']),
            'name' => (string)$row['name'],
        ];
    }

    private function auditEntityType(string $stopType): string
    {
        return $stopType === OfflineSyncMutation::STOP_TYPE_INCIDENT ? 'TICKET' : 'PREVENTIVE_ORDER';
    }

    /**
     * @return array{
     *   client_mutation_id: string,
     *   client_occurred_at: string,
     *   effective_occurred_at: string,
     *   synced_at: string,
     *   clock_skew_adjusted: bool,
     *   sanitary_seal_confirmed: bool
     * }
     */
    private function offlineSyncEnvelope(OfflineSyncMutation $mutation): array
    {
        return [
            'client_mutation_id' => $mutation->clientMutationId,
            'client_occurred_at' => $mutation->clientOccurredAt,
            'effective_occurred_at' => $mutation->effectiveOccurredAt,
            'synced_at' => $mutation->syncedAt,
            'clock_skew_adjusted' => $mutation->clockSkewAdjusted,
            'sanitary_seal_confirmed' => $mutation->sanitarySealConfirmed,
        ];
    }

    // ---------------------------------------------------------------------
    // Hidratación y utilidades
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateMutation(array $row): OfflineSyncMutation
    {
        $payload = $row['response_payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return new OfflineSyncMutation(
            id: (int)$row['id'],
            technicianId: (int)$row['technician_id'],
            clientMutationId: (string)$row['client_mutation_id'],
            stopType: (string)$row['stop_type'],
            stopId: (int)$row['stop_id'],
            operationType: (string)$row['operation_type'],
            outcomeStatus: (string)$row['outcome_status'],
            clientOccurredAt: (string)$row['client_occurred_at'],
            chainStartedAt: $this->nullableString($row['chain_started_at']),
            effectiveOccurredAt: (string)$row['effective_occurred_at'],
            syncedAt: (string)$row['synced_at'],
            clockSkewAdjusted: $this->toBool($row['clock_skew_adjusted']),
            sanitarySealConfirmed: $this->toBool($row['sanitary_seal_confirmed']),
            responseHttpStatus: (int)$row['response_http_status'],
            responsePayload: is_array($payload) ? $payload : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $this->paramType($value));
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    private function paramType(mixed $value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }

    /**
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function placeholders(string $prefix, array $values): array
    {
        $keys = [];
        $params = [];
        foreach (array_values($values) as $index => $value) {
            $key = ':' . $prefix . '_' . $index;
            $keys[] = $key;
            $params[$key] = $value;
        }

        return [implode(', ', $keys), $params];
    }

    private function modelKey(string $model): string
    {
        return mb_strtolower(trim($model));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string)$value;

        return $text === '' ? null : $text;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 't', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function isUniqueViolation(Throwable $error): bool
    {
        if (!$error instanceof PDOException) {
            return false;
        }

        return (string)$error->getCode() === '23505'
            || (isset($error->errorInfo[0]) && (string)$error->errorInfo[0] === '23505');
    }

    /**
     * Contexto oportunista de la ruta.
     *
     * La ventana de gracia y las paradas críticas ajenas las resuelve el
     * repositorio de incidencias, que ya las certifica; duplicar aquí esa
     * política crearía dos fuentes de verdad para el mismo corte de 30 minutos.
     */
    private function incidentContext(): PdoIncidentRepository
    {
        return $this->incidentRepository ??= new PdoIncidentRepository($this->pdo);
    }
}
