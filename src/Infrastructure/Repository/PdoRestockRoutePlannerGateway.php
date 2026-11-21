<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRestockRoutePlannerGateway
 *
 * Implementación PDO del puerto del Planificador de Rutas (Módulo 37;
 * RF-01.1, RF-01.3, RF-02.1–RF-02.3, RF-03.1, RF-05.1; QA-1–QA-3, QA-6;
 * Art. II, Art. III.1 y Art. III.3).
 *
 * Decisiones de implementación:
 *
 *   1. **Versionado atómico real.** `saveScheduleVersion()` cierra la versión
 *      vigente y inserta la nueva dentro de una única unidad de trabajo. Si el
 *      servicio ya abrió una transacción, el gateway usa un `SAVEPOINT`: el
 *      fallo revierte solo su parte y no la composición diaria entera.
 *   2. **Bloqueo pesimista.** `lockMachine()` usa `FOR UPDATE OF m` para que dos
 *      omisiones simultáneas de la misma máquina se serialicen antes de leer
 *      FEFO o de sellar `EXPIRED` (Art. II, C1).
 *   3. **Cero borrados.** Ninguna sentencia usa `DELETE`; el motor responde con
 *      `prevent_hard_delete()` si alguien lo intenta por otra vía (Art. III.1).
 *   4. **Idempotencia offline.** El registro en `offline_sync_mutations` usa
 *      `ON CONFLICT DO NOTHING` y relee el identificador existente: el reintento
 *      del móvil nunca duplica la mutación (C3).
 *   5. **Opacidad.** Las lecturas devuelven solo columnas operativas; no se
 *      proyectan costes ni notas ajenas a quien consulta (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoRestockRoutePlannerGateway implements RestockRoutePlannerGatewayInterface
{
    /** Zona horaria de negocio del módulo (D6). */
    public const TIMEZONE = 'Europe/Madrid';

    /** Plantilla y prioridad invariantes de la alerta sanitaria (RF-02.2). */
    public const FEFO_SKIP_TEMPLATE = 'RESTOCK_FEFO_STOP_SKIPPED_ALERT';
    public const FEFO_SKIP_PRIORITY = 'CRITICAL_SANITARY';

    /** Operación de la cola offline admitida por el módulo (C3). */
    public const OFFLINE_OPERATION = 'SKIP_RESTOCK_STOP';

    private PDO $pdo;

    /** Profundidad de las unidades de trabajo abiertas por esta instancia. */
    private int $transactionDepth = 0;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function lockMachine(int $machineId): ?array
    {
        if ($machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                m.id,
                m.code AS machine_code,
                m.machine_type,
                m.location_id,
                m.sanitary_status,
                m.is_blocked_no_access,
                m.is_seasonal_pause,
                m.seasonal_pause_until,
                m.is_active,
                l.name AS location_name,
                l.site_code,
                l.zone_id,
                l.latitude,
                l.longitude,
                l.sla_tier,
                oz.code AS zone_code
             FROM machines m
             INNER JOIN locations l ON l.id = m.location_id
             LEFT JOIN operational_zones oz ON oz.id = l.zone_id
             WHERE m.id = :machine_id
               AND m.deleted_at IS NULL
             FOR UPDATE OF m"
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'location_id' => (int)$row['location_id'],
            'location_name' => (string)$row['location_name'],
            'site_code' => (string)$row['site_code'],
            'zone_id' => $row['zone_id'] !== null ? (int)$row['zone_id'] : null,
            'sla_tier' => (string)$row['sla_tier'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_out_of_service' => self::toBool($row['is_blocked_no_access']),
            'is_seasonal_pause' => self::toBool($row['is_seasonal_pause']),
            'seasonal_pause_until' => $row['seasonal_pause_until'] !== null
                ? (string)$row['seasonal_pause_until']
                : null,
            'is_active' => self::toBool($row['is_active']),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveSchedule(int $machineId): ?MachineRestockSchedule
    {
        if ($machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM machine_restock_schedules
              WHERE machine_id = :machine_id AND is_active = TRUE
              LIMIT 1'
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MachineRestockSchedule::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function listActiveSchedules(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM machine_restock_schedules WHERE is_active = TRUE ORDER BY machine_id ASC'
        );

        $schedules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $schedule = MachineRestockSchedule::fromDatabaseRow($row);
            $schedules[$schedule->getMachineId()] = $schedule;
        }

        return $schedules;
    }

    /**
     * {@inheritdoc}
     */
    public function saveScheduleVersion(MachineRestockSchedule $schedule): int
    {
        return $this->transactional(function () use ($schedule): int {
            $now = $this->now();

            $close = $this->pdo->prepare(
                'UPDATE machine_restock_schedules
                    SET is_active = FALSE,
                        deactivated_by = :actor,
                        deactivated_at = :at,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE machine_id = :machine_id
                    AND is_active = TRUE'
            );
            $close->bindValue(':actor', $schedule->getUpdatedBy(), PDO::PARAM_INT);
            $close->bindValue(':at', $now, PDO::PARAM_STR);
            $close->bindValue(':machine_id', $schedule->getMachineId(), PDO::PARAM_INT);
            $close->execute();

            $insert = $this->pdo->prepare(
                'INSERT INTO machine_restock_schedules (
                    machine_id, cadence_type, day_of_week, anchor_date,
                    preferred_time_window, include_weekends, notes,
                    is_active, created_by, updated_by, deactivated_by,
                    created_at, updated_at, deactivated_at
                 ) VALUES (
                    :machine_id, :cadence_type, :day_of_week, :anchor_date,
                    :preferred_time_window, :include_weekends, :notes,
                    TRUE, :created_by, :updated_by, NULL,
                    COALESCE(:created_at, CURRENT_TIMESTAMP),
                    COALESCE(:updated_at, CURRENT_TIMESTAMP),
                    NULL
                 )
                 RETURNING id'
            );
            $insert->bindValue(':machine_id', $schedule->getMachineId(), PDO::PARAM_INT);
            $insert->bindValue(':cadence_type', $schedule->getCadenceType()->value, PDO::PARAM_STR);
            $this->bindNullableInt($insert, ':day_of_week', $schedule->getDayOfWeek());
            $this->bindNullableString($insert, ':anchor_date', $schedule->getAnchorDate());
            $insert->bindValue(
                ':preferred_time_window',
                $schedule->getPreferredTimeWindow()->value,
                PDO::PARAM_STR
            );
            $insert->bindValue(
                ':include_weekends',
                $schedule->includesWeekends() ? 'TRUE' : 'FALSE',
                PDO::PARAM_STR
            );
            $this->bindNullableString($insert, ':notes', $schedule->getNotes());
            $insert->bindValue(':created_by', $schedule->getCreatedBy(), PDO::PARAM_INT);
            $insert->bindValue(':updated_by', $schedule->getUpdatedBy(), PDO::PARAM_INT);
            $this->bindNullableString($insert, ':created_at', $schedule->getCreatedAt());
            $this->bindNullableString($insert, ':updated_at', $schedule->getUpdatedAt());
            $insert->execute();

            return (int)$insert->fetchColumn();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function deactivateSchedule(int $scheduleId, int $actorId, string $deactivatedAt): bool
    {
        if ($scheduleId <= 0 || $actorId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE machine_restock_schedules
                SET is_active = FALSE,
                    deactivated_by = :actor,
                    deactivated_at = :at,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :schedule_id
                AND is_active = TRUE'
        );
        $stmt->bindValue(':actor', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':at', $deactivatedAt, PDO::PARAM_STR);
        $stmt->bindValue(':schedule_id', $scheduleId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveOverride(int $machineId, string $serviceDate): ?RestockRouteDailyOverride
    {
        if ($machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM restock_route_daily_overrides
              WHERE machine_id = :machine_id
                AND service_date = :service_date
                AND status = 'ACTIVE'
              LIMIT 1"
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RestockRouteDailyOverride::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findOverrideByClientMutationId(string $clientMutationId): ?RestockRouteDailyOverride
    {
        $clean = trim($clientMutationId);
        if ($clean === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM restock_route_daily_overrides
              WHERE client_mutation_id = :client_mutation_id
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->bindValue(':client_mutation_id', $clean, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RestockRouteDailyOverride::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function insertOverride(RestockRouteDailyOverride $override): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO restock_route_daily_overrides (
                machine_id, service_date, override_type, status,
                original_restocker_id, target_restocker_id,
                skip_reason_code, reassignment_reason_code, reason_text,
                had_active_fefo_alert, triggered_notification_id,
                client_mutation_id, superseded_by_visit_id,
                created_by, resolved_by, created_at, resolved_at, updated_at
             ) VALUES (
                :machine_id, :service_date, :override_type, :status,
                :original_restocker_id, :target_restocker_id,
                :skip_reason_code, :reassignment_reason_code, :reason_text,
                :had_active_fefo_alert, :triggered_notification_id,
                :client_mutation_id, :superseded_by_visit_id,
                :created_by, :resolved_by,
                COALESCE(:created_at, CURRENT_TIMESTAMP),
                :resolved_at,
                COALESCE(:updated_at, CURRENT_TIMESTAMP)
             )
             RETURNING id'
        );

        $stmt->bindValue(':machine_id', $override->getMachineId(), PDO::PARAM_INT);
        $stmt->bindValue(':service_date', $override->getServiceDate(), PDO::PARAM_STR);
        $stmt->bindValue(':override_type', $override->getOverrideType()->value, PDO::PARAM_STR);
        $stmt->bindValue(':status', $override->getStatus()->value, PDO::PARAM_STR);
        $this->bindNullableInt($stmt, ':original_restocker_id', $override->getOriginalRestockerId());
        $this->bindNullableInt($stmt, ':target_restocker_id', $override->getTargetRestockerId());
        $this->bindNullableString($stmt, ':skip_reason_code', $override->getSkipReasonCode()?->value);
        $this->bindNullableString(
            $stmt,
            ':reassignment_reason_code',
            $override->getReassignmentReasonCode()?->value
        );
        $this->bindNullableString($stmt, ':reason_text', $override->getReasonText());
        $stmt->bindValue(
            ':had_active_fefo_alert',
            $override->hadActiveFefoAlert() ? 'TRUE' : 'FALSE',
            PDO::PARAM_STR
        );
        $this->bindNullableInt($stmt, ':triggered_notification_id', $override->getTriggeredNotificationId());
        $this->bindNullableString($stmt, ':client_mutation_id', $override->getClientMutationId());
        $this->bindNullableInt($stmt, ':superseded_by_visit_id', $override->getSupersededByVisitId());
        $stmt->bindValue(':created_by', $override->getCreatedBy(), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':resolved_by', $override->getResolvedBy());
        $this->bindNullableString($stmt, ':created_at', $override->getCreatedAt());
        $this->bindNullableString($stmt, ':resolved_at', $override->getResolvedAt());
        $this->bindNullableString($stmt, ':updated_at', $override->getUpdatedAt());
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * {@inheritdoc}
     */
    public function revokeActiveOverrides(
        int $machineId,
        string $serviceDate,
        int $resolverId,
        string $resolvedAt
    ): int {
        if ($machineId <= 0 || $resolverId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE restock_route_daily_overrides
                SET status = 'REVOKED',
                    resolved_by = :resolver_id,
                    resolved_at = :resolved_at,
                    updated_at = CURRENT_TIMESTAMP
              WHERE machine_id = :machine_id
                AND service_date = :service_date
                AND status = 'ACTIVE'"
        );
        $stmt->bindValue(':resolver_id', $resolverId, PDO::PARAM_INT);
        $stmt->bindValue(':resolved_at', $resolvedAt, PDO::PARAM_STR);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function supersedeSkippedOverrides(
        int $machineId,
        string $serviceDate,
        int $visitId,
        ?int $resolverId,
        string $resolvedAt
    ): int {
        if ($machineId <= 0 || $visitId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE restock_route_daily_overrides
                SET status = 'SUPERSEDED',
                    superseded_by_visit_id = :visit_id,
                    resolved_by = :resolver_id,
                    resolved_at = :resolved_at,
                    updated_at = CURRENT_TIMESTAMP
              WHERE machine_id = :machine_id
                AND service_date = :service_date
                AND override_type = 'SKIPPED'
                AND status = 'ACTIVE'"
        );
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':resolver_id', $resolverId);
        $stmt->bindValue(':resolved_at', $resolvedAt, PDO::PARAM_STR);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function markMachineSanitaryExpired(int $machineId): bool
    {
        if ($machineId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE machines
                SET sanitary_status = 'EXPIRED',
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :machine_id
                AND sanitary_status <> 'QUARANTINE'
                AND sanitary_status <> 'EXPIRED'"
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function enqueueFefoSkipAlert(
        int $machineId,
        string $serviceDate,
        int $overrideId,
        string $recipient,
        string $channel = 'EMAIL',
        array $extraPayload = []
    ): int {
        $payload = $extraPayload + [
            'machine_id' => $machineId,
            'service_date' => $serviceDate,
            'override_id' => $overrideId,
            'reason' => 'RESTOCK_FEFO_STOP_SKIPPED',
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_outbox (
                incident_id, channel, priority, template, recipient,
                recipient_role, subject, payload_json, dedup_key, status
             ) VALUES (
                NULL, :channel, :priority, :template, :recipient,
                :recipient_role, :subject, :payload_json, :dedup_key, \'PENDING\'
             )
             RETURNING id'
        );
        $stmt->bindValue(':channel', strtoupper(trim($channel)), PDO::PARAM_STR);
        $stmt->bindValue(':priority', self::FEFO_SKIP_PRIORITY, PDO::PARAM_STR);
        $stmt->bindValue(':template', self::FEFO_SKIP_TEMPLATE, PDO::PARAM_STR);
        $stmt->bindValue(':recipient', trim($recipient), PDO::PARAM_STR);
        $stmt->bindValue(':recipient_role', 'COORDINATOR', PDO::PARAM_STR);
        $stmt->bindValue(
            ':subject',
            sprintf('Parada con FEFO omitida · máquina %d · %s', $machineId, $serviceDate),
            PDO::PARAM_STR
        );
        $stmt->bindValue(
            ':payload_json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            PDO::PARAM_STR
        );
        $stmt->bindValue(
            ':dedup_key',
            sprintf('restock-fefo-skip:%d:%s:%d', $machineId, $serviceDate, $overrideId),
            PDO::PARAM_STR
        );
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * {@inheritdoc}
     */
    public function findOfflineMutation(int $restockerId, string $clientMutationId): ?array
    {
        if ($restockerId <= 0 || trim($clientMutationId) === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, response_http_status, response_payload
               FROM offline_sync_mutations
              WHERE technician_id = :restocker_id
                AND client_mutation_id = :client_mutation_id
              LIMIT 1'
        );
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':client_mutation_id', trim($clientMutationId), PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $payload = json_decode((string)$row['response_payload'], true);

        return [
            'id' => (int)$row['id'],
            'http_status' => (int)$row['response_http_status'],
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function recordOfflineMutation(
        int $restockerId,
        string $clientMutationId,
        int $machineId,
        string $clientOccurredAt,
        array $responsePayload,
        int $httpStatus = 200
    ): int {
        if ($restockerId <= 0 || $machineId <= 0 || trim($clientMutationId) === '') {
            throw new RuntimeException('La mutación offline exige reponedor, máquina e identificador.');
        }

        $existing = $this->findOfflineMutation($restockerId, $clientMutationId);
        if ($existing !== null) {
            return $existing['id'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO offline_sync_mutations (
                technician_id, client_mutation_id, stop_type, stop_id,
                operation_type, outcome_status, client_occurred_at,
                effective_occurred_at, response_http_status, response_payload
             ) VALUES (
                :restocker_id, :client_mutation_id, \'MACHINE\', :machine_id,
                :operation_type, \'APPLIED\', :client_occurred_at,
                :effective_occurred_at, :http_status, :response_payload
             )
             ON CONFLICT (technician_id, client_mutation_id) DO NOTHING
             RETURNING id'
        );
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':client_mutation_id', trim($clientMutationId), PDO::PARAM_STR);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':operation_type', self::OFFLINE_OPERATION, PDO::PARAM_STR);
        $stmt->bindValue(':client_occurred_at', $clientOccurredAt, PDO::PARAM_STR);
        $stmt->bindValue(':effective_occurred_at', $clientOccurredAt, PDO::PARAM_STR);
        $stmt->bindValue(':http_status', $httpStatus, PDO::PARAM_INT);
        $stmt->bindValue(
            ':response_payload',
            json_encode($responsePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            PDO::PARAM_STR
        );
        $stmt->execute();

        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        $existing = $this->findOfflineMutation($restockerId, $clientMutationId);
        if ($existing === null) {
            throw new RuntimeException('La mutación offline no pudo registrarse ni recuperarse.');
        }

        return $existing['id'];
    }

    /**
     * Ejecuta la unidad de trabajo con `SAVEPOINT` si ya hay una transacción
     * abierta por el llamador.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $depth = $this->transactionDepth;
        $savepoint = 'rrp_gateway_' . $depth;

        if ($depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $this->transactionDepth++;

        try {
            $result = $work();
            $this->transactionDepth--;

            if ($this->transactionDepth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->transactionDepth--;

            if ($this->transactionDepth === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:sP');
    }

    private function bindNullableInt(\PDOStatement $stmt, string $placeholder, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);

            return;
        }

        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    }

    private function bindNullableString(\PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);

            return;
        }

        $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
