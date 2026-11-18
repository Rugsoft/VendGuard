<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRestockerSatSynergyGateway
 *
 * Gateway transaccional de la sinergia reponedor ↔ SAT (Módulo 36; RF-02.1,
 * RF-03.1, RF-03.2, RF-04.1, RF-04.2, RF-05.1; D1–D4, C1–C2; Art. III.1,
 * Art. III.3, Art. V.2 y Art. V.3).
 *
 * Decisiones de implementación:
 *
 *   1. **Bloqueo pesimista por máquina o expediente.** Inicio, resolución y
 *      escalado leen la fila con `FOR UPDATE` dentro de la transacción: dos
 *      operarios no pueden decidir a la vez sobre el mismo expediente. El
 *      escalado bloquea la máquina, porque las tres ramas dependen de si hay o
 *      no un ticket vivo en ella (Art. V.2).
 *   2. **Una sola custodia abierta.** Al escalar o resolver se cierra el tramo
 *      abierto con su causa tipificada y, si hay técnico entrante, se abre el
 *      siguiente con sus snapshots. El índice único parcial del motor es la
 *      última red; aquí se respeta el orden (Art. V.3).
 *   3. **Idempotencia offline.** Cada mutación con `client_mutation_id` se
 *      registra en `offline_sync_mutations` después de aplicarse. Un reenvío
 *      devuelve el `response_payload` original con `idempotent_replay = true`
 *      sin volver a tocar el expediente (C2, QA 4).
 *   4. **Errores de contrato.** El gateway no lanza excepciones de negocio:
 *      devuelve `ok = false` con `error_code` y `http_status`, y la capa de
 *      aplicación responde. Así el mismo camino sirve al canal online y al
 *      volcado de conflictos de la cola móvil.
 *   5. **Opacidad.** Ninguna consulta de lectura devuelve notas internas ni
 *      datos de escalado fuera de las estructuras que las necesitan; la
 *      Coincidencia en Sede solo se calcula para el operario consultante y su
 *      sede (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class PdoRestockerSatSynergyGateway implements RestockerSatSynergyGatewayInterface
{
    private const ACTIVE_L1_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_INFO', 'REOPENED'];
    private const TERMINAL_STATUSES = ['RESOLVED', 'CLOSED', 'CANCELLED'];
    private const OPEN_SHIFT_STATUSES = ['ON_SHIFT', 'MEAL_BREAK'];

    private PDO $pdo;

    /** @var callable(): string */
    private mixed $ticketCodeGenerator;

    /**
     * @param callable(): string|null $ticketCodeGenerator Fuente determinista del
     *        código de ticket en pruebas; en producción, `TicketCode::generate()`.
     */
    public function __construct(?PDO $pdo = null, ?callable $ticketCodeGenerator = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->ticketCodeGenerator = $ticketCodeGenerator
            ?? static fn (): string => TicketCode::generate()->value();
    }

    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array
    {
        if (!$this->hasRole($actor, 'RESTOCKER')) {
            return $this->error('RESTOCKER_ROLE_REQUIRED', 403, 'Solo un reponedor puede iniciar un aviso de Nivel 1.');
        }

        $replay = $this->findReplay($actor, $mutation);
        if ($replay !== null) {
            return $replay;
        }

        $this->begin();
        try {
            $incident = $this->lockIncident($incidentId);
            if ($incident === null) {
                return $this->fail('INCIDENT_NOT_FOUND', 404, 'El expediente no existe o está dado de baja.');
            }

            if ((string)$incident['dispatch_level'] !== 'L1_RESTOCKER'
                || (int)($incident['assigned_technician_id'] ?? 0) !== $this->actorId($actor)) {
                return $this->fail(
                    'INCIDENT_NOT_ASSIGNED_TO_RESTOCKER',
                    403,
                    'El expediente no es de Nivel 1 o no está bajo la custodia de este reponedor.'
                );
            }

            if ((string)$incident['status'] !== 'ASSIGNED') {
                return $this->fail(
                    'INVALID_INCIDENT_STATE_TRANSITION',
                    409,
                    'Solo un aviso ASSIGNED puede iniciarse.'
                );
            }

            $occurredAt = $this->occurredAt($mutation);
            $stmt = $this->pdo->prepare(
                'UPDATE incidents
                    SET status = \'IN_PROGRESS\',
                        started_at = COALESCE(started_at, :occurred_at::timestamptz),
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id'
            );
            $stmt->execute([':occurred_at' => $occurredAt, ':id' => $incidentId]);

            $this->insertHistory($incidentId, $this->actorId($actor), 'ASSIGNED', 'IN_PROGRESS', 'START_L1_INCIDENT');
            $this->audit($incidentId, 'START_L1_INCIDENT', $actor, ['status' => 'ASSIGNED'], ['status' => 'IN_PROGRESS']);

            $payload = [
                'ok' => true,
                'incident_id' => $incidentId,
                'ticket_code' => (string)$incident['ticket_code'],
                'dispatch_level' => 'L1_RESTOCKER',
                'status' => 'IN_PROGRESS',
                'started_at' => $occurredAt,
                'idempotent_replay' => false,
            ];
            $this->recordMutation($actor, $mutation, 'INCIDENT', $incidentId, 'START_L1_INCIDENT', $payload);
            $this->commit();

            return $payload;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function assignDispatchLevel(
        array $incident,
        string $dispatchLevel,
        array $assignee,
        array $actor
    ): array {
        $level = IncidentDispatchLevel::tryFrom(strtoupper(trim($dispatchLevel)));
        if ($level === null) {
            return $this->error('INVALID_DISPATCH_LEVEL', 422, 'El nivel de despacho no es válido.');
        }

        $incidentId = (int)($incident['id'] ?? 0);
        $assigneeId = (int)($assignee['id'] ?? 0);

        $this->begin();
        try {
            $current = $this->lockIncident($incidentId);
            if ($current === null) {
                return $this->fail('INCIDENT_NOT_FOUND', 404, 'El expediente no existe o está dado de baja.');
            }

            $category = IncidentCategory::tryFrom((string)$current['category']);
            if ($level->isLevel1()
                && $category !== null
                && in_array($category, [IncidentCategory::TEMPERATURE_COLD, IncidentCategory::ELECTRICAL_OFF], true)) {
                return $this->fail(
                    'L1_DISPATCH_FORBIDDEN_FOR_CATEGORY',
                    422,
                    'Una avería térmica o eléctrica no puede despacharse en Nivel 1 (Art. II).'
                );
            }

            if ($assigneeId > 0) {
                $userStmt = $this->pdo->prepare('SELECT role, is_active FROM users WHERE id = :id');
                $userStmt->execute([':id' => $assigneeId]);
                $user = $userStmt->fetch();
                $expectedRole = $level->requiresTechnician() ? 'TECHNICIAN' : 'RESTOCKER';
                if (!is_array($user)
                    || strtoupper((string)$user['role']) !== $expectedRole
                    || !$this->toBool($user['is_active'])) {
                    return $this->fail(
                        'INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL',
                        422,
                        'El rol del operario no coincide con el nivel de despacho (QA-CON-01).'
                    );
                }
            }

            $status = $assigneeId > 0 ? 'ASSIGNED' : 'REGISTERED';
            $this->closeOpenCustody($incidentId, $actor, 'REASSIGNED_BY_COORDINATOR', 'Cambio de nivel operativo', null);

            $stmt = $this->pdo->prepare(
                'UPDATE incidents
                    SET dispatch_level = :dispatch_level::incident_dispatch_level,
                        assigned_technician_id = :assignee_id,
                        status = :status,
                        assigned_at = CASE WHEN :assignee_flag::bigint IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END,
                        started_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id'
            );
            $stmt->execute([
                ':dispatch_level' => $level->value,
                ':assignee_id' => $assigneeId > 0 ? $assigneeId : null,
                ':assignee_flag' => $assigneeId > 0 ? $assigneeId : null,
                ':status' => $status,
                ':id' => $incidentId,
            ]);

            if ($assigneeId > 0) {
                $this->openCustody($incidentId, $assigneeId, 'COORDINATOR_ASSIGN', $this->actorId($actor), null);
            }

            $this->insertHistory(
                $incidentId,
                $this->actorId($actor),
                (string)$current['status'],
                $status,
                'DISPATCH_LEVEL_CHANGE:' . $level->value
            );
            $this->audit(
                $incidentId,
                'DISPATCH_LEVEL_CHANGE',
                $actor,
                ['dispatch_level' => (string)$current['dispatch_level']],
                ['dispatch_level' => $level->value, 'assigned_technician_id' => $assigneeId > 0 ? $assigneeId : null]
            );
            $this->commit();

            return [
                'ok' => true,
                'incident_id' => $incidentId,
                'dispatch_level' => $level->value,
                'status' => $status,
                'assigned_technician_id' => $assigneeId > 0 ? $assigneeId : null,
            ];
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function recordConflictDump(array $actor, array $mutation, array $conflict): array
    {
        $mutationId = trim((string)($mutation['client_mutation_id'] ?? ''));
        if ($mutationId === '') {
            return $this->error('MISSING_CLIENT_MUTATION_ID', 422, 'El volcado exige client_mutation_id.');
        }

        $this->begin();
        try {
            $occurredAt = $this->occurredAt($mutation);
            $stmt = $this->pdo->prepare(
                'INSERT INTO offline_sync_mutations (
                    technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                    outcome_status, client_occurred_at, effective_occurred_at,
                    response_http_status, response_payload
                 ) VALUES (
                    :technician_id, :client_mutation_id, :stop_type, :stop_id, :operation_type,
                    \'CONFLICT_DUMPED\', :client_occurred_at::timestamptz, :effective_occurred_at::timestamptz,
                    :response_status, :response_payload::jsonb
                 )
                 ON CONFLICT (technician_id, client_mutation_id) DO NOTHING'
            );
            $stmt->execute([
                ':technician_id' => $this->actorId($actor),
                ':client_mutation_id' => $mutationId,
                ':stop_type' => strtoupper(trim((string)($mutation['stop_type'] ?? 'INCIDENT'))),
                ':stop_id' => (int)($mutation['stop_id'] ?? 0),
                ':operation_type' => strtoupper(trim((string)($mutation['operation_type'] ?? 'RESOLVE_L1_INCIDENT'))),
                ':client_occurred_at' => (string)($mutation['client_occurred_at'] ?? $occurredAt),
                ':effective_occurred_at' => $occurredAt,
                ':response_status' => (int)($conflict['http_status'] ?? 409),
                ':response_payload' => json_encode($conflict, JSON_THROW_ON_ERROR),
            ]);

            $this->audit(
                (int)($mutation['stop_id'] ?? 0),
                'OFFLINE_RESTOCKER_INCIDENT_CONFLICT_DUMP',
                $actor,
                [],
                ['client_mutation_id' => $mutationId, 'conflict' => $conflict]
            );
            $this->commit();

            return ['ok' => true, 'client_mutation_id' => $mutationId, 'outcome_status' => 'CONFLICT_DUMPED'];
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function resolveLevel1(
        int $incidentId,
        array $actor,
        array $resolution,
        ?array $mutation = null
    ): array {
        if (!$this->hasRole($actor, 'RESTOCKER')) {
            return $this->error('RESTOCKER_ROLE_REQUIRED', 403, 'Solo un reponedor puede resolver un aviso de Nivel 1.');
        }

        $replay = $this->findReplay($actor, $mutation);
        if ($replay !== null) {
            return $replay;
        }

        $this->begin();
        try {
            $incident = $this->lockIncident($incidentId);
            if ($incident === null) {
                return $this->fail('INCIDENT_NOT_FOUND', 404, 'El expediente no existe o está dado de baja.');
            }

            if ((string)$incident['dispatch_level'] !== 'L1_RESTOCKER'
                || (int)($incident['assigned_technician_id'] ?? 0) !== $this->actorId($actor)) {
                return $this->fail(
                    'INCIDENT_NOT_ASSIGNED_TO_RESTOCKER',
                    403,
                    'El expediente no es de Nivel 1 o no está bajo la custodia de este reponedor.'
                );
            }

            if ((string)$incident['status'] !== 'IN_PROGRESS') {
                return $this->fail(
                    'INVALID_INCIDENT_STATE_TRANSITION',
                    409,
                    'Solo un aviso IN_PROGRESS puede resolverse.'
                );
            }

            if (strtoupper(trim((string)$incident['sanitary_status'])) === 'QUARANTINE') {
                return $this->fail(
                    'SANITARY_L1_RESOLUTION_BLOCKED',
                    409,
                    'La máquina está en cuarentena sanitaria (QA-CONST-01, Art. II).'
                );
            }

            $diagnosis = (string)($resolution['resolution_diagnosis'] ?? '');
            $action = (string)($resolution['resolution_action'] ?? '');
            $occurredAt = $this->occurredAt($mutation);

            $this->closeOpenCustody(
                $incidentId,
                $actor,
                'RESOLVED',
                mb_substr($diagnosis !== '' ? $diagnosis : 'Resolución de Nivel 1', 0, 1000),
                $occurredAt
            );

            $stmt = $this->pdo->prepare(
                'UPDATE incidents
                    SET status = \'RESOLVED\',
                        resolved_at = :resolved_at::timestamptz,
                        resolution_diagnosis = :diagnosis,
                        resolution_action = :action,
                        root_cause_id = :root_cause_id,
                        standard_solution_id = :standard_solution_id,
                        root_cause_code_snapshot = :root_cause_code,
                        root_cause_category_snapshot = :root_cause_category,
                        root_cause_name_snapshot = :root_cause_name,
                        standard_solution_code_snapshot = :solution_code,
                        standard_solution_name_snapshot = :solution_name,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id'
            );
            $stmt->execute([
                ':resolved_at' => $occurredAt,
                ':diagnosis' => $diagnosis,
                ':action' => $action,
                ':root_cause_id' => $resolution['root_cause_id'] ?? null,
                ':standard_solution_id' => $resolution['standard_solution_id'] ?? null,
                ':root_cause_code' => $resolution['root_cause_code'] ?? null,
                ':root_cause_category' => $resolution['root_cause_category'] ?? null,
                ':root_cause_name' => $resolution['root_cause_name'] ?? null,
                ':solution_code' => $resolution['standard_solution_code'] ?? null,
                ':solution_name' => $resolution['standard_solution_name'] ?? null,
                ':id' => $incidentId,
            ]);

            $this->insertHistory($incidentId, $this->actorId($actor), 'IN_PROGRESS', 'RESOLVED', 'RESOLVE_L1_INCIDENT');
            $this->audit(
                $incidentId,
                'RESOLVE_L1_INCIDENT',
                $actor,
                ['status' => 'IN_PROGRESS'],
                ['status' => 'RESOLVED', 'dispatch_level' => 'L1_RESTOCKER']
            );

            $payload = [
                'ok' => true,
                'incident_id' => $incidentId,
                'ticket_code' => (string)$incident['ticket_code'],
                'dispatch_level' => 'L1_RESTOCKER',
                'status' => 'RESOLVED',
                'resolved_at' => $occurredAt,
                'idempotent_replay' => false,
            ];
            $this->recordMutation($actor, $mutation, 'INCIDENT', $incidentId, 'RESOLVE_L1_INCIDENT', $payload);
            $this->commit();

            return $payload;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function escalateToSat(
        int $machineId,
        array $actor,
        array $payload,
        ?array $mutation = null
    ): array {
        if (!$this->hasRole($actor, 'RESTOCKER')) {
            return $this->error('RESTOCKER_ROLE_REQUIRED', 403, 'Solo un reponedor puede escalar una avería al SAT.');
        }

        $replay = $this->findReplay($actor, $mutation);
        if ($replay !== null) {
            return $replay;
        }

        $note = (string)($payload['workshop_internal_note'] ?? '');
        $category = IncidentCategory::tryFrom(strtoupper(trim((string)($payload['category'] ?? ''))));
        if ($category === null) {
            return $this->error('INVALID_CATEGORY', 422, 'La categoría del escalado no es válida.');
        }

        $this->begin();
        try {
            $machine = $this->lockMachine($machineId);
            if ($machine === null) {
                return $this->fail('MACHINE_NOT_FOUND', 404, 'La máquina no existe o está dada de baja.');
            }

            if (!$this->isCovered((int)$machine['zone_id'], $this->actorId($actor))) {
                return $this->fail(
                    'MACHINE_OUTSIDE_RESTOCKER_ZONES',
                    403,
                    'La máquina no pertenece a ninguna zona cubierta hoy por el reponedor.'
                );
            }

            $incident = $this->lockActiveIncident($machineId);
            $occurredAt = $this->occurredAt($mutation);
            $technicianId = isset($payload['assign_technician_id']) ? (int)$payload['assign_technician_id'] : 0;
            $technicianId = $technicianId > 0 ? $technicianId : null;

            if ($incident !== null && (string)$incident['dispatch_level'] === 'L1_RESTOCKER') {
                return $this->escalateBranchA($incident, $machine, $actor, $category, $note, $payload, $technicianId, $occurredAt, $mutation);
            }

            if ($incident !== null) {
                return $this->escalateBranchC($incident, $machine, $actor, $category, $note, $payload, $occurredAt, $mutation);
            }

            return $this->escalateBranchB($machine, $actor, $category, $note, $payload, $technicianId, $occurredAt, $mutation);
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function suggestRestockers(int $incidentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.location_id, l.zone_id
               FROM incidents i
               INNER JOIN locations l ON l.id = i.location_id
              WHERE i.id = :id AND i.deleted_at IS NULL'
        );
        $stmt->execute([':id' => $incidentId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['zone_id'] === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.operator_code, u.phone, rza.role_in_zone, rza.is_primary,
                    (
                        SELECT COUNT(*)
                          FROM incidents i
                         WHERE i.dispatch_level = \'L1_RESTOCKER\'
                           AND i.assigned_technician_id = u.id
                           AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'PENDING_INFO\', \'REOPENED\')
                    ) AS active_l1_count
               FROM users u
               INNER JOIN technician_shifts s
                       ON s.technician_id = u.id
                      AND s.closed_at IS NULL
                      AND s.shift_date = CURRENT_DATE
                      AND s.status IN (\'ON_SHIFT\', \'MEAL_BREAK\')
               INNER JOIN restocker_zone_assignments rza
                       ON rza.restocker_id = u.id
                      AND rza.zone_id = :zone_id
                      AND rza.removed_at IS NULL
               INNER JOIN operational_zones oz ON oz.id = rza.zone_id AND oz.is_active = TRUE
              WHERE u.role = \'RESTOCKER\'
                AND u.is_active = TRUE
                AND u.deleted_at IS NULL
              ORDER BY rza.is_primary DESC, active_l1_count ASC, u.name ASC'
        );
        $stmt->execute([':zone_id' => (int)$row['zone_id']]);

        $restockers = [];
        foreach ($stmt->fetchAll() as $candidate) {
            $restockers[] = [
                'user_id' => (int)$candidate['id'],
                'name' => (string)$candidate['name'],
                'operator_code' => (string)($candidate['operator_code'] ?? ''),
                'phone' => $candidate['phone'] !== null ? (string)$candidate['phone'] : null,
                'role_in_zone' => (string)$candidate['role_in_zone'],
                'is_primary' => $this->toBool($candidate['is_primary']),
                'active_l1_incidents_count' => (int)$candidate['active_l1_count'],
            ];
        }

        return $restockers;
    }

    public function technicianCoincidences(int $technicianId, array $locationIds): array
    {
        if (!$this->hasOpenShift($technicianId)) {
            return [];
        }

        $result = [];
        foreach ($locationIds as $locationId) {
            $locationId = (int)$locationId;
            if ($locationId <= 0) {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT u.id, u.name, u.operator_code, u.phone, s.status,
                        (
                            SELECT COUNT(*)
                              FROM incidents i
                             WHERE i.location_id = :loc_count
                               AND i.dispatch_level = \'L1_RESTOCKER\'
                               AND i.assigned_technician_id = u.id
                               AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'REOPENED\')
                        ) AS activity_count
                   FROM users u
                   INNER JOIN technician_shifts s
                           ON s.technician_id = u.id
                          AND s.closed_at IS NULL
                          AND s.shift_date = CURRENT_DATE
                          AND s.status IN (\'ON_SHIFT\', \'MEAL_BREAK\')
                  WHERE u.role = \'RESTOCKER\'
                    AND u.is_active = TRUE
                    AND u.deleted_at IS NULL
                    AND (
                        EXISTS (
                            SELECT 1 FROM incidents i
                             WHERE i.location_id = :loc_incident
                               AND i.dispatch_level = \'L1_RESTOCKER\'
                               AND i.assigned_technician_id = u.id
                               AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'REOPENED\')
                        )
                        OR EXISTS (
                            SELECT 1
                              FROM restocker_zone_assignments rza
                              INNER JOIN locations l ON l.zone_id = rza.zone_id
                             WHERE rza.restocker_id = u.id
                               AND rza.removed_at IS NULL
                               AND l.id = :loc_zone
                        )
                    )
                  ORDER BY u.name ASC'
            );
            $stmt->execute([
                ':loc_count' => $locationId,
                ':loc_incident' => $locationId,
                ':loc_zone' => $locationId,
            ]);

            $rows = [];
            foreach ($stmt->fetchAll() as $candidate) {
                $count = (int)$candidate['activity_count'];
                $rows[] = [
                    'user_id' => (int)$candidate['id'],
                    'name' => (string)$candidate['name'],
                    'operator_code' => (string)($candidate['operator_code'] ?? ''),
                    'role' => 'RESTOCKER',
                    'shift_status' => (string)$candidate['status'],
                    'phone' => $candidate['phone'] !== null ? (string)$candidate['phone'] : null,
                    'activity_summary' => $count > 0
                        ? sprintf('%d aviso(s) L1 activo(s) en esta sede', $count)
                        : 'Cobertura de zona activa en esta sede',
                ];
            }
            $result[$locationId] = $rows;
        }

        return $result;
    }

    public function restockerCoincidences(int $restockerId, array $locationIds): array
    {
        if (!$this->hasOpenShift($restockerId)) {
            return [];
        }

        $result = [];
        foreach ($locationIds as $locationId) {
            $locationId = (int)$locationId;
            if ($locationId <= 0) {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT u.id, u.name, u.operator_code, u.phone, s.status,
                        (
                            SELECT COUNT(*)
                              FROM incidents i
                             WHERE i.location_id = :loc_count
                               AND i.dispatch_level = \'L2_SAT\'
                               AND i.assigned_technician_id = u.id
                               AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'REOPENED\')
                        ) AS activity_count,
                        EXISTS (
                            SELECT 1 FROM preventive_orders p
                             WHERE p.location_id = :loc_prev
                               AND p.assigned_technician_id = u.id
                               AND p.status IN (\'SCHEDULED\', \'IN_INSPECTION\')
                               AND p.deleted_at IS NULL
                        ) AS has_preventive
                   FROM users u
                   INNER JOIN technician_shifts s
                           ON s.technician_id = u.id
                          AND s.closed_at IS NULL
                          AND s.shift_date = CURRENT_DATE
                          AND s.status IN (\'ON_SHIFT\', \'MEAL_BREAK\')
                  WHERE u.role = \'TECHNICIAN\'
                    AND u.is_active = TRUE
                    AND u.deleted_at IS NULL
                    AND (
                        EXISTS (
                            SELECT 1 FROM incidents i
                             WHERE i.location_id = :loc_incident
                               AND i.dispatch_level = \'L2_SAT\'
                               AND i.assigned_technician_id = u.id
                               AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'REOPENED\')
                        )
                        OR EXISTS (
                            SELECT 1 FROM preventive_orders p
                             WHERE p.location_id = :loc_prev2
                               AND p.assigned_technician_id = u.id
                               AND p.status IN (\'SCHEDULED\', \'IN_INSPECTION\')
                               AND p.deleted_at IS NULL
                        )
                    )
                  ORDER BY u.name ASC'
            );
            $stmt->execute([
                ':loc_count' => $locationId,
                ':loc_prev' => $locationId,
                ':loc_incident' => $locationId,
                ':loc_prev2' => $locationId,
            ]);

            $rows = [];
            foreach ($stmt->fetchAll() as $candidate) {
                $count = (int)$candidate['activity_count'];
                $hasPreventive = $this->toBool($candidate['has_preventive']);
                $summary = match (true) {
                    $count > 0 => sprintf('%d avería(s) SAT activa(s) en esta sede', $count),
                    $hasPreventive => 'Orden preventiva activa en esta sede',
                    default => 'Actividad SAT en esta sede',
                };
                $rows[] = [
                    'user_id' => (int)$candidate['id'],
                    'name' => (string)$candidate['name'],
                    'operator_code' => (string)($candidate['operator_code'] ?? ''),
                    'role' => 'TECHNICIAN',
                    'shift_status' => (string)$candidate['status'],
                    'phone' => $candidate['phone'] !== null ? (string)$candidate['phone'] : null,
                    'activity_summary' => $summary,
                ];
            }
            $result[$locationId] = $rows;
        }

        return $result;
    }

    public function handoverLevel1(int $restockerId, string $cause, string $reason): array
    {
        $cause = strtoupper(trim($cause));
        $reasonType = $cause === 'SHIFT_CLOSE' ? 'RESTOCKER_SHIFT_HANDOVER' : 'ABSENCE_COVERAGE_REASSIGN';
        $source = $reasonType;
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 1000) : 'Relevo automático de Nivel 1';

        $this->begin();
        try {
            $blocked = $this->pdo->prepare(
                'SELECT ticket_code
                   FROM incidents
                  WHERE dispatch_level = \'L1_RESTOCKER\'
                    AND assigned_technician_id = :restocker_id
                    AND status = \'IN_PROGRESS\'
                    AND deleted_at IS NULL
                  ORDER BY ticket_code ASC'
            );
            $blocked->execute([':restocker_id' => $restockerId]);
            $blockedCodes = array_map(
                static fn (array $row): string => (string)$row['ticket_code'],
                $blocked->fetchAll()
            );

            if ($blockedCodes !== []) {
                $this->rollback();

                return [
                    'ok' => false,
                    'error_code' => 'SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1',
                    'http_status' => 409,
                    'blocked_ticket_codes' => $blockedCodes,
                ];
            }

            $tickets = $this->pdo->prepare(
                'SELECT id, ticket_code, machine_id, location_id
                   FROM incidents
                  WHERE dispatch_level = \'L1_RESTOCKER\'
                    AND assigned_technician_id = :restocker_id
                    AND status IN (\'ASSIGNED\', \'PENDING_INFO\')
                    AND deleted_at IS NULL
                  ORDER BY id ASC
                  FOR UPDATE'
            );
            $tickets->execute([':restocker_id' => $restockerId]);
            $rows = $tickets->fetchAll();

            $actor = $this->actorRow($restockerId);
            $reassigned = [];
            $released = [];

            foreach ($rows as $ticket) {
                $incidentId = (int)$ticket['id'];
                $this->closeOpenCustody($incidentId, $actor, $reasonType, $reason, null);

                $next = $this->findNextRestocker((int)$ticket['location_id'], $restockerId);
                if ($next !== null) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE incidents
                            SET assigned_technician_id = :next_id,
                                assigned_at = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP
                          WHERE id = :id'
                    );
                    $stmt->execute([':next_id' => (int)$next['id'], ':id' => $incidentId]);
                    $this->openCustody($incidentId, (int)$next['id'], $source, $restockerId, null);
                    $reassigned[] = (string)$ticket['ticket_code'];
                    $this->insertHistory($incidentId, $restockerId, 'ASSIGNED', 'ASSIGNED', 'L1_HANDOVER_TO_RESTOCKER:' . (int)$next['id']);
                } else {
                    $stmt = $this->pdo->prepare(
                        'UPDATE incidents
                            SET assigned_technician_id = NULL,
                                status = \'REGISTERED\',
                                assigned_at = NULL,
                                updated_at = CURRENT_TIMESTAMP
                          WHERE id = :id'
                    );
                    $stmt->execute([':id' => $incidentId]);
                    $released[] = (string)$ticket['ticket_code'];
                    $this->insertHistory($incidentId, $restockerId, 'ASSIGNED', 'REGISTERED', 'L1_HANDOVER_RELEASED_TO_QUEUE');
                }

                $this->audit(
                    $incidentId,
                    'L1_HANDOVER_' . $reasonType,
                    $actor,
                    ['assigned_technician_id' => $restockerId],
                    ['assigned_technician_id' => $next['id'] ?? null]
                );
            }

            $this->commit();

            return [
                'ok' => true,
                'blocked_ticket_codes' => [],
                'reassigned_ticket_codes' => $reassigned,
                'released_ticket_codes' => $released,
                'reassigned_count' => count($reassigned),
                'released_count' => count($released),
            ];
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ramas del escalado
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    private function escalateBranchA(
        array $incident,
        array $machine,
        array $actor,
        IncidentCategory $category,
        string $note,
        array $payload,
        ?int $technicianId,
        string $occurredAt,
        ?array $mutation
    ): array {
        $incidentId = (int)$incident['id'];

        $this->closeOpenCustody($incidentId, $actor, 'ESCALATED_TO_SAT_BY_RESTOCKER', $note, $occurredAt);

        $status = $technicianId !== null ? 'ASSIGNED' : 'REGISTERED';
        $stmt = $this->pdo->prepare(
            'UPDATE incidents
                SET dispatch_level = \'L2_SAT\',
                    category = :category,
                    status = :status,
                    assigned_technician_id = :technician_id,
                    assigned_at = CASE WHEN :technician_flag::bigint IS NULL THEN NULL ELSE :occurred_at::timestamptz END,
                    started_at = NULL,
                    escalated_from_restocker_id = :restocker_id,
                    escalated_to_sat_at = :occurred_at::timestamptz,
                    urgency = COALESCE(:urgency::urgency_level, urgency),
                    sla_target_minutes = COALESCE(:sla_minutes::integer, sla_target_minutes),
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->execute([
            ':category' => $category->value,
            ':status' => $status,
            ':technician_id' => $technicianId,
            ':technician_flag' => $technicianId,
            ':occurred_at' => $occurredAt,
            ':restocker_id' => $this->actorId($actor),
            ':urgency' => $payload['urgency'] ?? null,
            ':sla_minutes' => $payload['sla_target_minutes'] ?? null,
            ':id' => $incidentId,
        ]);

        if ($technicianId !== null) {
            $this->openCustody($incidentId, $technicianId, 'RESTOCKER_ESCALATION_TO_SAT', $this->actorId($actor), $occurredAt);
        }

        $this->insertInternalComment($incidentId, $actor, $note, $payload['photo_path'] ?? null);
        $this->insertHistory($incidentId, $this->actorId($actor), (string)$incident['status'], $status, 'ESCALATE_INCIDENT_TO_SAT:ESCALATED_FROM_L1');
        $this->audit(
            $incidentId,
            'ESCALATE_INCIDENT_TO_SAT',
            $actor,
            ['dispatch_level' => 'L1_RESTOCKER', 'status' => (string)$incident['status']],
            ['dispatch_level' => 'L2_SAT', 'status' => $status, 'category' => $category->value]
        );

        $response = [
            'ok' => true,
            'incident_id' => $incidentId,
            'ticket_code' => (string)$incident['ticket_code'],
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['code'],
            'dispatch_level' => 'L2_SAT',
            'status' => $status,
            'escalation_outcome' => 'ESCALATED_FROM_L1',
            'merged_into_existing_sat_ticket' => false,
            'escalated_from_restocker_id' => $this->actorId($actor),
            'escalated_to_sat_at' => $occurredAt,
            'idempotent_replay' => false,
        ];
        $this->recordMutation($actor, $mutation, 'INCIDENT', $incidentId, 'ESCALATE_INCIDENT_TO_SAT', $response);
        $this->commit();

        return $response;
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    private function escalateBranchC(
        array $incident,
        array $machine,
        array $actor,
        IncidentCategory $category,
        string $note,
        array $payload,
        string $occurredAt,
        ?array $mutation
    ): array {
        $incidentId = (int)$incident['id'];
        $currentCategory = IncidentCategory::tryFrom((string)$incident['category']);
        $incomingWins = $this->categorySeverity($category) > $this->categorySeverity($currentCategory);

        $stmt = $this->pdo->prepare(
            'UPDATE incidents
                SET escalated_from_restocker_id = COALESCE(escalated_from_restocker_id, :restocker_id),
                    escalated_to_sat_at = COALESCE(escalated_to_sat_at, :occurred_at::timestamptz),
                    category = CASE WHEN :incoming_wins_a::boolean THEN :category::incident_category ELSE category END,
                    urgency = CASE WHEN :incoming_wins_b::boolean THEN COALESCE(:urgency::urgency_level, \'CRITICAL\'::urgency_level) ELSE urgency END,
                    sla_target_minutes = CASE WHEN :incoming_wins_c::boolean THEN COALESCE(:sla_minutes::integer, sla_target_minutes) ELSE sla_target_minutes END,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->execute([
            ':restocker_id' => $this->actorId($actor),
            ':occurred_at' => $occurredAt,
            ':incoming_wins_a' => $incomingWins ? 'true' : 'false',
            ':incoming_wins_b' => $incomingWins ? 'true' : 'false',
            ':incoming_wins_c' => $incomingWins ? 'true' : 'false',
            ':category' => $category->value,
            ':urgency' => $payload['urgency'] ?? null,
            ':sla_minutes' => $payload['sla_target_minutes'] ?? null,
            ':id' => $incidentId,
        ]);

        $this->insertInternalComment($incidentId, $actor, $note, $payload['photo_path'] ?? null);
        $this->insertHistory($incidentId, $this->actorId($actor), (string)$incident['status'], (string)$incident['status'], 'ESCALATE_INCIDENT_TO_SAT:MERGED_INTO_EXISTING_SAT_TICKET');
        $this->audit(
            $incidentId,
            'ESCALATE_INCIDENT_TO_SAT',
            $actor,
            ['assigned_technician_id' => $incident['assigned_technician_id'] ?? null],
            ['dispatch_level' => 'L2_SAT', 'merged' => true]
        );

        $response = [
            'ok' => true,
            'incident_id' => $incidentId,
            'ticket_code' => (string)$incident['ticket_code'],
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['code'],
            'dispatch_level' => 'L2_SAT',
            'status' => (string)$incident['status'],
            'escalation_outcome' => 'MERGED_INTO_EXISTING_SAT_TICKET',
            'merged_into_existing_sat_ticket' => true,
            'escalated_from_restocker_id' => (int)($incident['escalated_from_restocker_id'] ?? $this->actorId($actor)),
            'escalated_to_sat_at' => (string)($incident['escalated_to_sat_at'] ?? $occurredAt),
            'idempotent_replay' => false,
        ];
        $this->recordMutation($actor, $mutation, 'INCIDENT', $incidentId, 'ESCALATE_INCIDENT_TO_SAT', $response);
        $this->commit();

        return $response;
    }

    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    private function escalateBranchB(
        array $machine,
        array $actor,
        IncidentCategory $category,
        string $note,
        array $payload,
        ?int $technicianId,
        string $occurredAt,
        ?array $mutation
    ): array {
        $status = $technicianId !== null ? 'ASSIGNED' : 'REGISTERED';
        $urgency = (string)($payload['urgency'] ?? $this->defaultUrgency($category));
        $ticketCode = ($this->ticketCodeGenerator)();

        $stmt = $this->pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, location_id, category, description, photo_path,
                urgency, status, assigned_technician_id, assigned_at,
                dispatch_level, escalated_from_restocker_id, escalated_to_sat_at,
                sla_target_minutes, reporter_name
             ) VALUES (
                :ticket_code, :machine_id, :location_id, :category, :description, :photo_path,
                :urgency, :status, :technician_id,
                CASE WHEN :technician_flag::bigint IS NULL THEN NULL ELSE :occurred_at_assigned::timestamptz END,
                \'L2_SAT\', :restocker_id, :occurred_at::timestamptz,
                COALESCE(:sla_minutes::integer, 1440), :reporter_name
             ) RETURNING id, ticket_code, status'
        );
        $stmt->execute([
            ':ticket_code' => $ticketCode,
            ':machine_id' => (int)$machine['id'],
            ':location_id' => (int)$machine['location_id'],
            ':category' => $category->value,
            ':description' => $note,
            ':photo_path' => $payload['photo_path'] ?? null,
            ':urgency' => $urgency,
            ':status' => $status,
            ':technician_id' => $technicianId,
            ':technician_flag' => $technicianId,
            ':occurred_at' => $occurredAt,
            ':occurred_at_assigned' => $occurredAt,
            ':restocker_id' => $this->actorId($actor),
            ':sla_minutes' => $payload['sla_target_minutes'] ?? null,
            ':reporter_name' => $this->actorName($actor),
        ]);
        $created = $stmt->fetch();
        if (!is_array($created)) {
            throw new RuntimeException('El escalado a puerta abierta no devolvió el expediente creado.');
        }

        $incidentId = (int)$created['id'];
        if ($technicianId !== null) {
            $this->openCustody($incidentId, $technicianId, 'RESTOCKER_ESCALATION_TO_SAT', $this->actorId($actor), $occurredAt);
        }

        $this->insertInternalComment($incidentId, $actor, $note, $payload['photo_path'] ?? null);
        $this->insertHistory($incidentId, $this->actorId($actor), null, $status, 'ESCALATE_INCIDENT_TO_SAT:CREATED_NEW_SAT_TICKET');
        $this->audit(
            $incidentId,
            'ESCALATE_INCIDENT_TO_SAT',
            $actor,
            [],
            ['dispatch_level' => 'L2_SAT', 'status' => $status, 'machine_id' => (int)$machine['id']]
        );

        $response = [
            'ok' => true,
            'incident_id' => $incidentId,
            'ticket_code' => (string)$created['ticket_code'],
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['code'],
            'dispatch_level' => 'L2_SAT',
            'status' => $status,
            'escalation_outcome' => 'CREATED_NEW_SAT_TICKET',
            'merged_into_existing_sat_ticket' => false,
            'escalated_from_restocker_id' => $this->actorId($actor),
            'escalated_to_sat_at' => $occurredAt,
            'idempotent_replay' => false,
        ];
        $this->recordMutation($actor, $mutation, 'MACHINE', (int)$machine['id'], 'ESCALATE_INCIDENT_TO_SAT', $response);
        $this->commit();

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lecturas bloqueadas y helpers de expediente
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function lockIncident(int $incidentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, m.sanitary_status, m.code AS machine_code, l.zone_id
               FROM incidents i
               INNER JOIN machines m ON m.id = i.machine_id
               INNER JOIN locations l ON l.id = i.location_id
              WHERE i.id = :id AND i.deleted_at IS NULL
              FOR UPDATE OF i'
        );
        $stmt->execute([':id' => $incidentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockMachine(int $machineId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.code, m.location_id, m.machine_type, m.sanitary_status, l.zone_id
               FROM machines m
               INNER JOIN locations l ON l.id = m.location_id
              WHERE m.id = :id AND m.is_active = TRUE AND m.deleted_at IS NULL
              FOR UPDATE OF m'
        );
        $stmt->execute([':id' => $machineId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockActiveIncident(int $machineId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM incidents
              WHERE machine_id = :machine_id
                AND deleted_at IS NULL
                AND status NOT IN (\'RESOLVED\', \'CLOSED\', \'CANCELLED\')
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([':machine_id' => $machineId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function isCovered(int $zoneId, int $restockerId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM restocker_zone_assignments rza
               INNER JOIN operational_zones oz ON oz.id = rza.zone_id AND oz.is_active = TRUE
              WHERE rza.restocker_id = :restocker_id
                AND rza.zone_id = :zone_id
                AND rza.removed_at IS NULL'
        );
        $stmt->execute([':restocker_id' => $restockerId, ':zone_id' => $zoneId]);

        return $stmt->fetchColumn() !== false;
    }

    private function hasOpenShift(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM technician_shifts
              WHERE technician_id = :user_id
                AND closed_at IS NULL
                AND shift_date = CURRENT_DATE
                AND status IN (\'ON_SHIFT\', \'MEAL_BREAK\')'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findNextRestocker(int $locationId, int $excludeRestockerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.operator_code
               FROM users u
               INNER JOIN technician_shifts s
                       ON s.technician_id = u.id
                      AND s.closed_at IS NULL
                      AND s.shift_date = CURRENT_DATE
                      AND s.status IN (\'ON_SHIFT\', \'MEAL_BREAK\')
               INNER JOIN restocker_zone_assignments rza
                       ON rza.restocker_id = u.id
                      AND rza.zone_id = (SELECT zone_id FROM locations WHERE id = :location_id)
                      AND rza.removed_at IS NULL
              WHERE u.role = \'RESTOCKER\'
                AND u.is_active = TRUE
                AND u.deleted_at IS NULL
                AND u.id <> :excluded_id
              ORDER BY rza.is_primary DESC,
                       (
                           SELECT COUNT(*) FROM incidents i
                            WHERE i.dispatch_level = \'L1_RESTOCKER\'
                              AND i.assigned_technician_id = u.id
                              AND i.status IN (\'ASSIGNED\', \'IN_PROGRESS\', \'PENDING_INFO\', \'REOPENED\')
                       ) ASC,
                       u.name ASC
              LIMIT 1'
        );
        $stmt->execute([':location_id' => $locationId, ':excluded_id' => $excludeRestockerId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function closeOpenCustody(
        int $incidentId,
        array $actor,
        string $reasonType,
        string $reason,
        ?string $unassignedAt
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE incident_assignments
                SET unassigned_at = GREATEST(assigned_at, COALESCE(:unassigned_at::timestamptz, CURRENT_TIMESTAMP)),
                    unassigned_by = :closed_by,
                    unassigned_by_name_snapshot = :closed_by_name,
                    unassignment_reason_type = :reason_type,
                    reassignment_reason = :reason
              WHERE incident_id = :incident_id
                AND unassigned_at IS NULL'
        );
        $stmt->execute([
            ':unassigned_at' => $unassignedAt,
            ':closed_by' => $this->actorId($actor),
            ':closed_by_name' => $this->actorName($actor),
            ':reason_type' => $reasonType,
            ':reason' => mb_substr($reason !== '' ? $reason : 'Cierre de custodia', 0, 1000),
            ':incident_id' => $incidentId,
        ]);
    }

    private function openCustody(
        int $incidentId,
        int $userId,
        string $source,
        int $assignedBy,
        ?string $assignedAt
    ): void {
        $stmt = $this->pdo->prepare('SELECT name, operator_code FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('El custodio entrante no existe.');
        }

        $code = trim((string)($user['operator_code'] ?? ''));

        $stmt = $this->pdo->prepare(
            'INSERT INTO incident_assignments (
                incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
                assigned_by, assigned_by_name_snapshot, assignment_source, assigned_at
             ) VALUES (
                :incident_id, :technician_id, :name, :code,
                :assigned_by, :assigned_by_name, :source,
                COALESCE(:assigned_at::timestamptz, CURRENT_TIMESTAMP)
             )'
        );
        $stmt->execute([
            ':incident_id' => $incidentId,
            ':technician_id' => $userId,
            ':name' => (string)$user['name'],
            ':code' => $code !== '' ? $code : 'SIN-CODIGO',
            ':assigned_by' => $assignedBy > 0 ? $assignedBy : null,
            ':assigned_by_name' => (string)($this->actorRow($assignedBy)['name'] ?? 'Sistema'),
            ':source' => $source,
            ':assigned_at' => $assignedAt,
        ]);
    }

    private function insertInternalComment(int $incidentId, array $actor, string $note, ?string $photoPath): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incident_comments (
                incident_id, author_type, user_id, author_name, comment_text, photo_path, is_internal
             ) VALUES (
                :incident_id, \'RESTOCKER\', :user_id, :author_name, :comment_text, :photo_path, TRUE
             )'
        );
        $stmt->execute([
            ':incident_id' => $incidentId,
            ':user_id' => $this->actorId($actor),
            ':author_name' => $this->actorName($actor),
            ':comment_text' => $note,
            ':photo_path' => $photoPath,
        ]);
    }

    private function insertHistory(
        int $incidentId,
        int $userId,
        ?string $fromStatus,
        string $toStatus,
        string $note
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incident_history (incident_id, user_id, from_status, to_status, action_note)
             VALUES (:incident_id, :user_id, :from_status, :to_status, :action_note)'
        );
        $stmt->execute([
            ':incident_id' => $incidentId,
            ':user_id' => $userId > 0 ? $userId : null,
            ':from_status' => $fromStatus,
            ':to_status' => $toStatus,
            ':action_note' => $note,
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     */
    private function audit(int $incidentId, string $action, array $actor, array $previous, array $next): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata
             ) VALUES (
                \'TICKET\', :entity_id, :action, :user_id, :user_role, :user_name,
                :previous_state::jsonb, :new_state::jsonb, :metadata::jsonb
             )'
        );
        $stmt->execute([
            ':entity_id' => $incidentId,
            ':action' => $action,
            ':user_id' => $this->actorId($actor) > 0 ? $this->actorId($actor) : null,
            ':user_role' => $this->actorRole($actor),
            ':user_name' => $this->actorName($actor),
            ':previous_state' => json_encode($previous, JSON_THROW_ON_ERROR),
            ':new_state' => json_encode($next, JSON_THROW_ON_ERROR),
            ':metadata' => json_encode(['module' => 36], JSON_THROW_ON_ERROR),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotencia offline y transacción
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>|null
     */
    private function findReplay(array $actor, ?array $mutation): ?array
    {
        $mutationId = trim((string)($mutation['client_mutation_id'] ?? ''));
        if ($mutationId === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT response_payload
               FROM offline_sync_mutations
              WHERE technician_id = :technician_id AND client_mutation_id = :client_mutation_id'
        );
        $stmt->execute([
            ':technician_id' => $this->actorId($actor),
            ':client_mutation_id' => $mutationId,
        ]);
        $payload = $stmt->fetchColumn();
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $decoded['idempotent_replay'] = true;

        return $decoded;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $mutation
     * @param array<string, mixed> $payload
     */
    private function recordMutation(
        array $actor,
        ?array $mutation,
        string $stopType,
        int $stopId,
        string $operationType,
        array $payload
    ): void {
        $mutationId = trim((string)($mutation['client_mutation_id'] ?? ''));
        if ($mutationId === '') {
            return;
        }

        $occurredAt = $this->occurredAt($mutation);

        $stmt = $this->pdo->prepare(
            'INSERT INTO offline_sync_mutations (
                technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                outcome_status, client_occurred_at, effective_occurred_at,
                response_http_status, response_payload
             ) VALUES (
                :technician_id, :client_mutation_id, :stop_type, :stop_id, :operation_type,
                \'APPLIED\', :client_occurred_at::timestamptz, :effective_occurred_at::timestamptz,
                200, :response_payload::jsonb
             )
             ON CONFLICT (technician_id, client_mutation_id) DO NOTHING'
        );
        $stmt->execute([
            ':technician_id' => $this->actorId($actor),
            ':client_mutation_id' => $mutationId,
            ':stop_type' => $stopType,
            ':stop_id' => $stopId,
            ':operation_type' => $operationType,
            ':client_occurred_at' => (string)($mutation['client_occurred_at'] ?? $occurredAt),
            ':effective_occurred_at' => $occurredAt,
            ':response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    private function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Actores, tiempos y utilidades
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $actor */
    private function actorId(array $actor): int
    {
        return (int)($actor['id'] ?? 0);
    }

    /** @param array<string, mixed> $actor */
    private function actorName(array $actor): string
    {
        $name = trim((string)($actor['name'] ?? ''));

        return $name !== '' ? $name : 'Operario';
    }

    /** @param array<string, mixed> $actor */
    private function actorRole(array $actor): string
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));

        return $role !== '' ? $role : 'RESTOCKER';
    }

    /** @param array<string, mixed> $actor */
    private function hasRole(array $actor, string $role): bool
    {
        return strtoupper(trim((string)($actor['role'] ?? ''))) === $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function actorRow(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, operator_code, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string, mixed>|null $mutation
     */
    private function occurredAt(?array $mutation): string
    {
        $declared = trim((string)($mutation['client_occurred_at'] ?? ''));
        if ($declared === '') {
            return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');
        }

        return $declared;
    }

    private function categorySeverity(?IncidentCategory $category): int
    {
        if ($category === null) {
            return 0;
        }

        return match ($category) {
            IncidentCategory::OTHER => 1,
            IncidentCategory::PRODUCT_JAM => 2,
            IncidentCategory::PAYMENT_SYSTEM => 3,
            IncidentCategory::ELECTRICAL_OFF => 4,
            IncidentCategory::TEMPERATURE_COLD => 5,
        };
    }

    private function defaultUrgency(IncidentCategory $category): string
    {
        return in_array($category, [IncidentCategory::TEMPERATURE_COLD, IncidentCategory::ELECTRICAL_OFF], true)
            ? 'CRITICAL'
            : 'MEDIUM';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $code, int $status, string $message): array
    {
        return ['ok' => false, 'error_code' => $code, 'http_status' => $status, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $code, int $status, string $message): array
    {
        $this->rollback();

        return $this->error($code, $status, $message);
    }
}
