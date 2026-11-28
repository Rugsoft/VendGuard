<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Repository\MachineDexAuditGatewayInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineDexAuditGateway
 *
 * Cerrojos y hechos ajenos que la importación `.dex` necesita (Módulo 39;
 * RF-03.1, RF-04.1, RF-04.3, QA-1, QA-2, QA-3, D1, C2, Art. II.2 y Art. III).
 *
 * `lockMachineContext()` es la fotografía que serializa todo: `SELECT ... FOR
 * UPDATE OF m` sobre `machines`, con el código, la tipología, la sede, la zona,
 * el estado sanitario, la cobertura viva del Reponedor (zonas + desvíos
 * `REASSIGNED` del día) y del Técnico (zona o incidencia asignada), la avería
 * `TEMPERATURE_COLD` abierta y los snapshots del actor. Con esa fila bloqueada,
 * dos importaciones simultáneas no pueden calcular deltas desde el mismo
 * predecesor.
 *
 * Los cruces M34/M35 respetan el día civil `Europe/Madrid` y la cercanía
 * temporal (QA-3); las cantidades por carril alimentan el consumo observado sin
 * tocar `current_estimated_qty`. No decide ni escribe: devuelve hechos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineDexAuditGateway implements MachineDexAuditGatewayInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function lockMachineContext(int $machineId, int $actorId): ?array
    {
        return $this->machineContext($machineId, $actorId, true);
    }

    /**
     * {@inheritdoc}
     */
    public function findMachineContext(int $machineId, int $actorId): ?array
    {
        return $this->machineContext($machineId, $actorId, false);
    }

    /**
     * Fotografía de máquina y actor. El bloqueo solo se añade en la ruta de
     * importación; el listado histórico es una lectura y no debe esperar por
     * una fila de `machines`.
     *
     * @return array<string, mixed>|null
     */
    private function machineContext(int $machineId, int $actorId, bool $forUpdate): ?array
    {
        if ($machineId <= 0 || $actorId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                m.id,
                m.code AS machine_code,
                m.machine_type,
                m.location_id,
                l.zone_id,
                l.name AS location_name,
                m.sanitary_status,
                m.is_blocked_no_access,
                (
                    EXISTS (
                        SELECT 1
                        FROM restocker_zone_assignments rza
                        INNER JOIN operational_zones oz ON oz.id = rza.zone_id
                        WHERE rza.restocker_id = :cover_restocker_id
                          AND rza.zone_id = l.zone_id
                          AND rza.removed_at IS NULL
                          AND oz.is_active = TRUE
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM restock_route_daily_overrides rrdo
                        WHERE rrdo.machine_id = m.id
                          AND rrdo.target_restocker_id = :target_restocker_id
                          AND rrdo.service_date = (CURRENT_TIMESTAMP AT TIME ZONE 'Europe/Madrid')::date
                          AND rrdo.override_type = 'REASSIGNED'
                          AND rrdo.status = 'ACTIVE'
                    )
                ) AS is_covered_restocker,
                (
                    EXISTS (
                        SELECT 1
                        FROM technician_zone_assignments tza
                        INNER JOIN operational_zones oz2 ON oz2.id = tza.zone_id
                        WHERE tza.technician_id = :cover_technician_id
                          AND tza.zone_id = l.zone_id
                          AND tza.removed_at IS NULL
                          AND oz2.is_active = TRUE
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM incidents i2
                        WHERE i2.machine_id = m.id
                          AND i2.assigned_technician_id = :assigned_technician_id
                          AND i2.deleted_at IS NULL
                          AND i2.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                    )
                ) AS is_covered_technician,
                EXISTS (
                    SELECT 1
                    FROM incidents i
                    WHERE i.machine_id = m.id
                      AND i.deleted_at IS NULL
                      AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                      AND i.category = 'TEMPERATURE_COLD'
                ) AS has_open_thermal_breakdown,
                u.name AS actor_name,
                u.role AS actor_role,
                u.operator_code AS actor_operator_code,
                u.is_active AS actor_is_active
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            INNER JOIN users u ON u.id = :actor_user_id
            WHERE m.id = :machine_id
              AND m.is_active = TRUE
              AND m.deleted_at IS NULL
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE OF m';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cover_restocker_id', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':target_restocker_id', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':cover_technician_id', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':assigned_technician_id', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':actor_user_id', $actorId, PDO::PARAM_INT);
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
            'zone_id' => $row['zone_id'] === null ? null : (int)$row['zone_id'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_blocked_no_access' => self::toBool($row['is_blocked_no_access']),
            'is_covered_restocker' => self::toBool($row['is_covered_restocker']),
            'is_covered_technician' => self::toBool($row['is_covered_technician']),
            'has_open_thermal_breakdown' => self::toBool($row['has_open_thermal_breakdown']),
            'actor_id' => $actorId,
            'actor_name' => (string)$row['actor_name'],
            'actor_role' => (string)$row['actor_role'],
            'actor_operator_code' => trim((string)($row['actor_operator_code'] ?? '')),
            'actor_is_active' => self::toBool($row['actor_is_active']),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockRestockVisit(int $visitId, int $machineId): ?array
    {
        if ($visitId <= 0 || $machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, machine_id, restocker_id, status, started_at, completed_at
             FROM machine_restock_visits
             WHERE id = :visit_id AND machine_id = :machine_id
             FOR UPDATE'
        );
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'status' => (string)$row['status'],
            'started_at' => (string)$row['started_at'],
            'completed_at' => (string)$row['completed_at'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockCashCollection(int $cashCollectionId, int $machineId): ?array
    {
        if ($cashCollectionId <= 0 || $machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, machine_id, restocker_id, status, collected_at,
                    sales_counter_reading, vault_counted_eur
             FROM machine_cash_collections
             WHERE id = :collection_id AND machine_id = :machine_id
             FOR UPDATE'
        );
        $stmt->bindValue(':collection_id', $cashCollectionId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'status' => (string)$row['status'],
            'collected_at' => (string)$row['collected_at'],
            'sales_counter_reading' => (float)$row['sales_counter_reading'],
            'vault_counted_eur' => $row['vault_counted_eur'] === null ? null : (float)$row['vault_counted_eur'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockIncident(int $incidentId, int $machineId): ?array
    {
        if ($incidentId <= 0 || $machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, machine_id, assigned_technician_id, category, status
             FROM incidents
             WHERE id = :incident_id AND machine_id = :machine_id AND deleted_at IS NULL
             FOR UPDATE'
        );
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'assigned_technician_id' => $row['assigned_technician_id'] === null
                ? null
                : (int)$row['assigned_technician_id'],
            'category' => (string)$row['category'],
            'status' => (string)$row['status'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findNearestCashCollectionSameDay(
        int $machineId,
        string $capturedAt,
        ?int $linkedCashCollectionId = null
    ): ?array {
        if ($machineId <= 0 || trim($capturedAt) === '') {
            return null;
        }

        if ($linkedCashCollectionId !== null && $linkedCashCollectionId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, collected_at, vault_counted_eur, expected_cashbox_eur, sales_counter_reading
                 FROM machine_cash_collections
                 WHERE id = :collection_id AND machine_id = :machine_id
                 LIMIT 1'
            );
            $stmt->bindValue(':collection_id', $linkedCashCollectionId, PDO::PARAM_INT);
            $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id, status, collected_at, vault_counted_eur, expected_cashbox_eur, sales_counter_reading
                FROM machine_cash_collections
                WHERE machine_id = :machine_id
                  AND (collected_at AT TIME ZONE 'Europe/Madrid')::date
                      = (:captured_at::timestamptz AT TIME ZONE 'Europe/Madrid')::date
                ORDER BY ABS(EXTRACT(EPOCH FROM (collected_at - :captured_at_distance::timestamptz))) ASC,
                         collected_at DESC,
                         id DESC
                LIMIT 1
            ");
            $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
            $stmt->bindValue(':captured_at', $capturedAt, PDO::PARAM_STR);
            $stmt->bindValue(':captured_at_distance', $capturedAt, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'collected_at' => (string)$row['collected_at'],
            'vault_counted_eur' => $row['vault_counted_eur'] === null ? null : (float)$row['vault_counted_eur'],
            'expected_cashbox_eur' => $row['expected_cashbox_eur'] === null ? null : (float)$row['expected_cashbox_eur'],
            'sales_counter_reading' => (float)$row['sales_counter_reading'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findCompletedRestockVisitSameDay(
        int $machineId,
        string $capturedAt,
        ?int $linkedVisitId = null
    ): ?array {
        if ($machineId <= 0 || trim($capturedAt) === '') {
            return null;
        }

        if ($linkedVisitId !== null && $linkedVisitId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT id, machine_id, restocker_id, status, started_at, completed_at
                 FROM machine_restock_visits
                 WHERE id = :visit_id AND machine_id = :machine_id AND status = 'COMPLETED'
                 LIMIT 1"
            );
            $stmt->bindValue(':visit_id', $linkedVisitId, PDO::PARAM_INT);
            $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id, machine_id, restocker_id, status, started_at, completed_at
                FROM machine_restock_visits
                WHERE machine_id = :machine_id
                  AND status = 'COMPLETED'
                  AND (completed_at AT TIME ZONE 'Europe/Madrid')::date
                      = (:captured_at::timestamptz AT TIME ZONE 'Europe/Madrid')::date
                ORDER BY ABS(EXTRACT(EPOCH FROM (completed_at - :captured_at_distance::timestamptz))) ASC,
                         completed_at DESC,
                         id DESC
                LIMIT 1
            ");
            $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
            $stmt->bindValue(':captured_at', $capturedAt, PDO::PARAM_STR);
            $stmt->bindValue(':captured_at_distance', $capturedAt, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'status' => (string)$row['status'],
            'started_at' => (string)$row['started_at'],
            'completed_at' => (string)$row['completed_at'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findPreviousCompletedRestockVisit(int $machineId, int $referenceVisitId): ?array
    {
        if ($machineId <= 0 || $referenceVisitId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT prev.id, prev.machine_id, prev.restocker_id, prev.status,
                   prev.started_at, prev.completed_at
            FROM machine_restock_visits prev
            WHERE prev.machine_id = :machine_id
              AND prev.status = 'COMPLETED'
              AND prev.completed_at < (
                  SELECT ref.completed_at FROM machine_restock_visits ref WHERE ref.id = :reference_visit_id
              )
            ORDER BY prev.completed_at DESC, prev.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':reference_visit_id', $referenceVisitId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'status' => (string)$row['status'],
            'started_at' => (string)$row['started_at'],
            'completed_at' => (string)$row['completed_at'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findVisitSlotQuantities(int $visitId): array
    {
        if ($visitId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT slot_id, slot_code, qty_before, qty_after
             FROM machine_restock_visit_items
             WHERE visit_id = :visit_id
             ORDER BY slot_code ASC'
        );
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'slot_id' => (int)$row['slot_id'],
            'slot_code' => (string)$row['slot_code'],
            'qty_before' => (int)$row['qty_before'],
            'qty_after' => (int)$row['qty_after'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findActivePlanogramSlots(int $machineId): array
    {
        if ($machineId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, slot_code, current_estimated_qty
             FROM machine_planogram_slots
             WHERE machine_id = :machine_id AND is_active = TRUE
             ORDER BY slot_code ASC'
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'slot_code' => (string)$row['slot_code'],
            'current_estimated_qty' => (int)$row['current_estimated_qty'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
