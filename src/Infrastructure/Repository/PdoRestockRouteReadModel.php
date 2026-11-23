<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRestockRouteReadModel
 *
 * Implementación PDO del puerto de lectura del Planificador de Rutas (Módulo 37;
 * RF-01–RF-03, RF-05.1, D1–D7, C1–C3, QA-1–QA-6, Art. II, Art. V.4, Art. VI).
 *
 * Decisiones de implementación:
 *
 *   1. **Una foto, no N+1.** `loadParkSnapshot()` resuelve en un puñado de
 *      consultas acotadas todas las máquinas del parque con su agenda viva,
 *      FEFO refrigerado, carriles vacíos, aviso L1, avería térmica, visita del
 *      día y excepción viva. La composición diaria no vuelve a consultar por
 *      cada parada.
 *   2. **FEFO acotado a frío (Art. II).** La fecha más próxima y el sello de
 *      lote vencido solo consideran `vending_products.requires_cold_chain = TRUE`;
 *      un producto de temperatura ambiente nunca entra en el Bloque 1.
 *   3. **Opacidad (Art. V.4).** La foto no proyecta `unit_cost_eur`,
 *      `commercial_spoilage_loss_eur`, notas internas de Coordinación ni
 *      importes: solo hechos operativos.
 *   4. **Sin borrados.** Solo se lee; ninguna sentencia muta el motor.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoRestockRouteReadModel implements RestockRouteReadModelInterface
{
    /** Estados de aviso L1 que mantienen viva una inyección de demanda. */
    public const ACTIVE_L1_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_INFO', 'REOPENED'];

    /** Estados de visita que cierran la parada del día (C1, QA-6). */
    public const CLOSED_VISIT_STATUSES = ['COMPLETED', 'ABORTED_THERMAL_BREACH'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function loadParkSnapshot(string $serviceDate): array
    {
        return [
            'machines' => $this->fetchMachines($serviceDate, null),
            'restockers' => $this->listRestockers($serviceDate),
            'overrides' => $this->fetchActiveOverrides($serviceDate),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function loadMachineFacts(int $machineId, string $serviceDate): ?array
    {
        if ($machineId <= 0) {
            return null;
        }

        $rows = $this->fetchMachines($serviceDate, $machineId);

        return $rows[0] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function listRestockers(string $serviceDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                u.id,
                u.name,
                u.operator_code,
                u.is_active,
                COALESCE(
                    array_agg(DISTINCT rza.zone_id) FILTER (WHERE rza.zone_id IS NOT NULL),
                    '{}'
                ) AS zone_ids,
                COALESCE(BOOL_OR(rza.is_primary), FALSE) AS is_primary,
                COALESCE(sh.status, 'OFF_SHIFT') AS shift_status,
                EXISTS (
                    SELECT 1
                      FROM technician_absences a
                     WHERE a.technician_id = u.id
                       AND a.cancelled_at IS NULL
                       AND (a.starts_at AT TIME ZONE 'Europe/Madrid')::date <= :service_date
                       AND (a.ends_at AT TIME ZONE 'Europe/Madrid')::date >= :service_date
                ) AS has_absence
             FROM users u
             LEFT JOIN restocker_zone_assignments rza
                    ON rza.restocker_id = u.id
                   AND rza.removed_at IS NULL
             LEFT JOIN technician_shifts sh
                    ON sh.technician_id = u.id
                   AND sh.shift_date = :service_date
            WHERE u.role = 'RESTOCKER'
              AND u.is_active = TRUE
              AND u.deleted_at IS NULL
            GROUP BY u.id, u.name, u.operator_code, u.is_active, sh.status
            ORDER BY u.name ASC, u.id ASC"
        );
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        $stmt->execute();

        $restockers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $restockers[] = $this->mapRestocker($row);
        }

        return $restockers;
    }

    /**
     * {@inheritdoc}
     */
    public function findRestocker(int $restockerId): ?array
    {
        if ($restockerId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.id,
                u.name,
                u.operator_code,
                u.is_active,
                COALESCE(
                    array_agg(DISTINCT rza.zone_id) FILTER (WHERE rza.zone_id IS NOT NULL),
                    '{}'
                ) AS zone_ids,
                COALESCE(BOOL_OR(rza.is_primary), FALSE) AS is_primary
             FROM users u
             LEFT JOIN restocker_zone_assignments rza
                    ON rza.restocker_id = u.id
                   AND rza.removed_at IS NULL
            WHERE u.id = :restocker_id
              AND u.role = 'RESTOCKER'
              AND u.deleted_at IS NULL
            GROUP BY u.id, u.name, u.operator_code, u.is_active"
        );
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'operator_code' => $row['operator_code'] !== null ? (string)$row['operator_code'] : null,
            'is_active' => self::toBool($row['is_active']),
            'zone_ids' => self::parseZoneIds($row['zone_ids']),
            'is_primary' => self::toBool($row['is_primary'] ?? false),
            'shift_status' => 'OFF_SHIFT',
            'has_absence' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findScheduleById(int $scheduleId): ?MachineRestockSchedule
    {
        if ($scheduleId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM machine_restock_schedules WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $scheduleId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MachineRestockSchedule::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findOverrideById(int $overrideId): ?RestockRouteDailyOverride
    {
        if ($overrideId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM restock_route_daily_overrides WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $overrideId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RestockRouteDailyOverride::fromDatabaseRow($row);
    }

    /**
     * Consulta única de máquinas con todos los hechos de la composición diaria.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchMachines(string $serviceDate, ?int $machineId): array
    {
        $machineFilter = $machineId !== null ? ' AND m.id = :machine_id' : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                m.id,
                m.code AS machine_code,
                m.machine_type,
                m.location_id,
                m.is_active,
                m.is_blocked_no_access,
                m.is_seasonal_pause,
                m.seasonal_pause_until,
                m.sanitary_status,
                l.name AS location_name,
                l.address,
                l.site_code,
                l.zone_id,
                l.is_active AS location_active,
                l.sla_tier,
                l.latitude,
                l.longitude,
                oz.name AS zone_name,
                oz.is_active AS zone_active,
                s.id AS schedule_id,
                s.cadence_type,
                s.day_of_week,
                s.anchor_date,
                s.preferred_time_window,
                s.include_weekends,
                s.notes,
                s.created_by AS schedule_created_by,
                s.updated_by AS schedule_updated_by,
                s.created_at AS schedule_created_at,
                s.updated_at AS schedule_updated_at,
                (
                    SELECT MIN(sl.current_expiration_date)
                      FROM machine_planogram_slots sl
                      INNER JOIN vending_products vp ON vp.id = sl.product_id
                     WHERE sl.machine_id = m.id
                       AND sl.is_active = TRUE
                       AND vp.is_active = TRUE
                       AND vp.requires_cold_chain = TRUE
                       AND sl.current_expiration_date IS NOT NULL
                ) AS earliest_fefo_expiration_date,
                EXISTS (
                    SELECT 1
                      FROM machine_planogram_slots sl
                      INNER JOIN vending_products vp ON vp.id = sl.product_id
                     WHERE sl.machine_id = m.id
                       AND sl.is_active = TRUE
                       AND vp.is_active = TRUE
                       AND vp.requires_cold_chain = TRUE
                       AND sl.current_expiration_date <= :service_date
                ) AS has_expired_cold_batch,
                (
                    SELECT COUNT(*)
                      FROM machine_planogram_slots sl
                     WHERE sl.machine_id = m.id
                       AND sl.is_active = TRUE
                       AND sl.current_estimated_qty = 0
                ) AS empty_slots_count,
                (
                    SELECT i.id
                      FROM incidents i
                     WHERE i.machine_id = m.id
                       AND i.deleted_at IS NULL
                       AND i.dispatch_level = 'L1_RESTOCKER'
                       AND i.status IN ('ASSIGNED', 'IN_PROGRESS', 'PENDING_INFO', 'REOPENED')
                       AND i.assigned_technician_id IS NOT NULL
                     ORDER BY i.id DESC
                     LIMIT 1
                ) AS active_l1_incident_id,
                (
                    SELECT i.assigned_technician_id
                      FROM incidents i
                     WHERE i.machine_id = m.id
                       AND i.deleted_at IS NULL
                       AND i.dispatch_level = 'L1_RESTOCKER'
                       AND i.status IN ('ASSIGNED', 'IN_PROGRESS', 'PENDING_INFO', 'REOPENED')
                       AND i.assigned_technician_id IS NOT NULL
                     ORDER BY i.id DESC
                     LIMIT 1
                ) AS active_l1_restocker_id,
                EXISTS (
                    SELECT 1
                      FROM incidents i
                     WHERE i.machine_id = m.id
                       AND i.deleted_at IS NULL
                       AND i.category = 'TEMPERATURE_COLD'
                       AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                ) AS has_open_thermal_breakdown,
                (
                    SELECT v.id
                      FROM machine_restock_visits v
                     WHERE v.machine_id = m.id
                       AND v.status IN ('COMPLETED', 'ABORTED_THERMAL_BREACH')
                       AND (v.completed_at AT TIME ZONE 'Europe/Madrid')::date = :service_date
                     ORDER BY v.completed_at DESC, v.id DESC
                     LIMIT 1
                ) AS visit_today_id,
                (
                    SELECT v.status
                      FROM machine_restock_visits v
                     WHERE v.machine_id = m.id
                       AND v.status IN ('COMPLETED', 'ABORTED_THERMAL_BREACH')
                       AND (v.completed_at AT TIME ZONE 'Europe/Madrid')::date = :service_date
                     ORDER BY v.completed_at DESC, v.id DESC
                     LIMIT 1
                ) AS visit_today_status,
                (
                    SELECT v.restocker_id
                      FROM machine_restock_visits v
                     WHERE v.machine_id = m.id
                       AND v.status IN ('COMPLETED', 'ABORTED_THERMAL_BREACH')
                       AND (v.completed_at AT TIME ZONE 'Europe/Madrid')::date = :service_date
                     ORDER BY v.completed_at DESC, v.id DESC
                     LIMIT 1
                ) AS visit_today_restocker_id,
                (
                    SELECT o.id
                      FROM restock_route_daily_overrides o
                     WHERE o.machine_id = m.id
                       AND o.service_date = :service_date
                       AND o.status = 'ACTIVE'
                     LIMIT 1
                ) AS active_override_id,
                (
                    SELECT o.override_type
                      FROM restock_route_daily_overrides o
                     WHERE o.machine_id = m.id
                       AND o.service_date = :service_date
                       AND o.status = 'ACTIVE'
                     LIMIT 1
                ) AS active_override_type,
                (
                    SELECT o.target_restocker_id
                      FROM restock_route_daily_overrides o
                     WHERE o.machine_id = m.id
                       AND o.service_date = :service_date
                       AND o.status = 'ACTIVE'
                     LIMIT 1
                ) AS active_override_target_restocker_id
             FROM machines m
             INNER JOIN locations l ON l.id = m.location_id
             LEFT JOIN operational_zones oz ON oz.id = l.zone_id
             LEFT JOIN machine_restock_schedules s
                    ON s.machine_id = m.id
                   AND s.is_active = TRUE
            WHERE m.deleted_at IS NULL{$machineFilter}
            ORDER BY m.id ASC"
        );
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        if ($machineId !== null) {
            $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $machines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $machines[] = $this->mapMachine($row);
        }

        return $machines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActiveOverrides(string $serviceDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*, m.code AS machine_code
              FROM restock_route_daily_overrides o
              LEFT JOIN machines m ON m.id = o.machine_id
              WHERE o.service_date = :service_date
                AND o.status = 'ACTIVE'
              ORDER BY o.id ASC"
        );
        $stmt->bindValue(':service_date', $serviceDate, PDO::PARAM_STR);
        $stmt->execute();

        $overrides = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $override = RestockRouteDailyOverride::fromDatabaseRow($row)->toArray();
            $override['machine_code'] = isset($row['machine_code']) && $row['machine_code'] !== null
                ? (string)$row['machine_code']
                : null;
            $overrides[] = $override;
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapMachine(array $row): array
    {
        $schedule = null;
        if (($row['schedule_id'] ?? null) !== null) {
            $schedule = MachineRestockSchedule::fromDatabaseRow([
                'id' => (int)$row['schedule_id'],
                'machine_id' => (int)$row['id'],
                'cadence_type' => (string)$row['cadence_type'],
                'day_of_week' => $row['day_of_week'],
                'anchor_date' => $row['anchor_date'],
                'preferred_time_window' => (string)$row['preferred_time_window'],
                'include_weekends' => $row['include_weekends'],
                'notes' => $row['notes'],
                'is_active' => true,
                'created_by' => (int)$row['schedule_created_by'],
                'updated_by' => (int)$row['schedule_updated_by'],
                'created_at' => $row['schedule_created_at'],
                'updated_at' => $row['schedule_updated_at'],
            ])->toArray();
        }

        $visitToday = null;
        if (($row['visit_today_id'] ?? null) !== null) {
            $visitToday = [
                'id' => (int)$row['visit_today_id'],
                'status' => (string)$row['visit_today_status'],
                'restocker_id' => $row['visit_today_restocker_id'] !== null
                    ? (int)$row['visit_today_restocker_id']
                    : null,
            ];
        }

        $activeL1 = null;
        if (($row['active_l1_incident_id'] ?? null) !== null) {
            $activeL1 = [
                'id' => (int)$row['active_l1_incident_id'],
                'assigned_restocker_id' => $row['active_l1_restocker_id'] !== null
                    ? (int)$row['active_l1_restocker_id']
                    : null,
            ];
        }

        $activeOverride = null;
        if (($row['active_override_id'] ?? null) !== null) {
            $activeOverride = [
                'id' => (int)$row['active_override_id'],
                'override_type' => (string)$row['active_override_type'],
                'target_restocker_id' => $row['active_override_target_restocker_id'] !== null
                    ? (int)$row['active_override_target_restocker_id']
                    : null,
            ];
        }

        return [
            'id' => (int)$row['id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'location_id' => (int)$row['location_id'],
            'location_name' => (string)$row['location_name'],
            'address' => (string)$row['address'],
            'site_code' => (string)$row['site_code'],
            'zone_id' => $row['zone_id'] !== null ? (int)$row['zone_id'] : null,
            'zone_name' => $row['zone_name'] !== null ? (string)$row['zone_name'] : null,
            'zone_active' => $row['zone_active'] !== null ? self::toBool($row['zone_active']) : false,
            'sla_tier' => (string)$row['sla_tier'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_blocked_no_access' => self::toBool($row['is_blocked_no_access']),
            'is_seasonal_pause' => self::toBool($row['is_seasonal_pause']),
            'seasonal_pause_until' => $row['seasonal_pause_until'] !== null
                ? (string)$row['seasonal_pause_until']
                : null,
            'is_active' => self::toBool($row['is_active']),
            'location_active' => self::toBool($row['location_active']),
            'schedule' => $schedule,
            'earliest_fefo_expiration_date' => $row['earliest_fefo_expiration_date'] !== null
                ? (string)$row['earliest_fefo_expiration_date']
                : null,
            'has_expired_cold_batch' => self::toBool($row['has_expired_cold_batch']),
            'empty_slots_count' => (int)$row['empty_slots_count'],
            'active_l1_incident' => $activeL1,
            'has_open_thermal_breakdown' => self::toBool($row['has_open_thermal_breakdown']),
            'visit_today' => $visitToday,
            'active_override' => $activeOverride,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapRestocker(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'operator_code' => $row['operator_code'] !== null ? (string)$row['operator_code'] : null,
            'is_active' => self::toBool($row['is_active']),
            'zone_ids' => self::parseZoneIds($row['zone_ids']),
            'is_primary' => self::toBool($row['is_primary'] ?? false),
            'shift_status' => (string)$row['shift_status'],
            'has_absence' => self::toBool($row['has_absence']),
        ];
    }

    /**
     * @return list<int>
     */
    private static function parseZoneIds(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $clean = trim($value, '{}');
        if ($clean === '') {
            return [];
        }

        return array_values(array_map('intval', explode(',', $clean)));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
