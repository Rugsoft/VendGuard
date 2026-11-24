<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Core\Domain\Repository\RestockingBiAnalyticsRepositoryInterface;

/**
 * PdoRestockingBiAnalyticsRepository
 *
 * Implementación PostgreSQL de lectura agregada del Cuadro de Mando de
 * Reposición (Módulo 38; `RNF-01`, `RNF-02`, `C1`, `QA-2`, `QA-4`, `QA-5`,
 * Art. III, Art. IV).
 *
 * **Cero DDL (`RNF-01`, Art. IV).** Esta clase no crea, altera ni elimina nada:
 * solo ejecuta `SELECT` sobre las 51 tablas del esquema canónico. El inventario
 * permanece en 50 tipos `ENUM`, 56 columnas `ENUM` y 34 migraciones.
 *
 * **Una consulta por fuente (`RNF-02`).** Visitas, carriles, actas M29, turnos,
 * arqueos e incidencias se agregan en sentencias independientes y se cruzan
 * después en memoria PHP por su clave primaria. Es la única forma de no
 * multiplicar filas `1:N` (una visita con varios carriles y varias actas
 * devolvería el producto cartesiano de ambas) y de mantener latencia acotada
 * sobre Supavisor.
 *
 * Decisiones que el SQL materializa y no se pueden deducir de los nombres:
 *
 *   - **Ventana semiabierta (`C1`).** `>= fromAt AND < toExclusiveAt`, cada
 *     fuente por su marca operativa: `completed_at` (M34), `created_at` (M29),
 *     `started_at` (M22/M31), `collected_at` (M35) y `resolved_at` /
 *     `escalated_to_sat_at` (M36).
 *   - **In-Stock por visita (`D1`).** `NOT EXISTS` con `qty_before = 0`: una
 *     visita con tres roturas cuenta una sola visita con rotura, y una visita
 *     sin líneas cuenta como *In-Stock*.
 *   - **Guarda de producto en el carril (`QA-5`).** El umbral vigente solo se
 *     aplica si `machine_planogram_slots.product_id` coincide con el producto
 *     realmente auditado en la línea.
 *   - **Térmica APPCC (`QA-2`, `D2`).** Todas las actas del periodo cuentan
 *     (incluida la máquina vacía y los tres orígenes de cuarentena) y la
 *     consulta ni siquiera se ejecuta cuando se filtra por reponedor: la
 *     segmentación individual es imposible por diseño, no un `null` accidental.
 *   - **Turnos anulados (`C3`).** `NOT (status = 'OFF_SHIFT' AND closed_at IS
 *     NOT NULL AND closed_at = started_at)`.
 *   - **Bajas lógicas (`QA-4`, Art. III).** Ningún agregado filtra por
 *     `is_active` ni `deleted_at`; el estado se publica y la UI pone la insignia.
 *
 * Los filtros territoriales se aplican siempre por la sede real de la máquina
 * (`locations.zone_id` / `locations.id`), nunca por una copia en la visita, de
 * modo que la consulta general incluye las sedes sin zona y solo se excluyen
 * cuando el Coordinador filtra una zona concreta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class PdoRestockingBiAnalyticsRepository implements RestockingBiAnalyticsRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMachineCatalog(RestockingBiFilterDto $filter): array
    {
        // El orden de los marcadores sigue el texto SQL: primero la actividad de
        // la ventana y después los filtros territoriales.
        $params = [];

        $activitySql = 'EXISTS (
                SELECT 1
                  FROM machine_restock_visits mrv
                 WHERE mrv.machine_id = m.id
                   AND mrv.completed_at >= ? AND mrv.completed_at < ?';

        $params[] = $filter->fromAt()->format('Y-m-d H:i:sP');
        $params[] = $filter->toExclusiveAt()->format('Y-m-d H:i:sP');

        if ($filter->restockerId() !== null) {
            $activitySql .= ' AND mrv.restocker_id = ?';
            $params[] = $filter->restockerId();
        }

        $activitySql .= "\n            )";

        if ($filter->restockerId() === null) {
            $activitySql .= '
            OR EXISTS (
                SELECT 1
                  FROM sanitary_spoilage_records ssr
                 WHERE ssr.machine_id = m.id
                   AND ssr.created_at >= ? AND ssr.created_at < ?
            )';
            $params[] = $filter->fromAt()->format('Y-m-d H:i:sP');
            $params[] = $filter->toExclusiveAt()->format('Y-m-d H:i:sP');
        }

        // El reponedor ya restringe la actividad dentro del EXISTS; no existe un
        // alias `mrv` en el nivel exterior donde añadir otra condición.
        $conditions = $this->scopeConditions('l', null, $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT m.id AS machine_id,
                   m.code AS machine_code,
                   m.machine_type AS machine_type,
                   l.id AS location_id,
                   l.name AS location_name,
                   l.zone_id AS zone_id,
                   z.name AS zone_name,
                   (m.is_active AND m.deleted_at IS NULL) AS is_active
              FROM machines m
              JOIN locations l ON l.id = m.location_id
              LEFT JOIN operational_zones z ON z.id = l.zone_id
             WHERE ({$activitySql}){$where}
             ORDER BY m.code ASC
        ";

        $rows = $this->fetchAll($sql, $params);

        return array_map(static fn(array $row): array => [
            'machine_id' => (int)$row['machine_id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'location_id' => (int)$row['location_id'],
            'location_name' => (string)$row['location_name'],
            'zone_id' => $row['zone_id'] === null ? null : (int)$row['zone_id'],
            'zone_name' => $row['zone_name'] === null ? null : (string)$row['zone_name'],
            'is_active' => self::toBool($row['is_active']),
        ], $rows);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchVisitHeaders(RestockingBiFilterDto $filter): array
    {
        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', 'mrv.restocker_id', $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT mrv.machine_id,
                   mrv.restocker_id,
                   COUNT(*) FILTER (WHERE mrv.status = 'COMPLETED') AS completed_visits_count,
                   COUNT(*) FILTER (
                       WHERE mrv.status = 'COMPLETED'
                         AND NOT EXISTS (
                             SELECT 1
                               FROM machine_restock_visit_items mvi
                              WHERE mvi.visit_id = mrv.id
                                AND mvi.qty_before = 0
                         )
                   ) AS clean_in_stock_visits_count,
                   COUNT(*) FILTER (WHERE mrv.status = 'ABORTED_THERMAL_BREACH') AS thermal_aborted_visits_count,
                   COALESCE(
                       SUM(EXTRACT(EPOCH FROM (mrv.completed_at - mrv.started_at)))
                           FILTER (WHERE mrv.status = 'COMPLETED'),
                       0
                   ) AS completed_duration_seconds_sum
              FROM machine_restock_visits mrv
              JOIN machines m ON m.id = mrv.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE mrv.completed_at >= ? AND mrv.completed_at < ?{$where}
             GROUP BY mrv.machine_id, mrv.restocker_id
             ORDER BY mrv.machine_id ASC, mrv.restocker_id ASC
        ";

        $rows = $this->fetchAll($sql, $params);

        return array_map(static fn(array $row): array => [
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'completed_visits_count' => (int)$row['completed_visits_count'],
            'clean_in_stock_visits_count' => (int)$row['clean_in_stock_visits_count'],
            'thermal_aborted_visits_count' => (int)$row['thermal_aborted_visits_count'],
            'completed_duration_seconds_sum' => round((float)$row['completed_duration_seconds_sum'], 3),
        ], $rows);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchSlotItemsByMachineProduct(RestockingBiFilterDto $filter): array
    {
        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', 'mrv.restocker_id', $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT mrv.machine_id,
                   mvi.product_id,
                   COUNT(*) AS audited_slots_count,
                   COUNT(*) FILTER (WHERE mvi.qty_before = 0) AS stockout_slots_count,
                   COUNT(*) FILTER (
                       WHERE mvi.qty_before > 0
                         AND mps.min_threshold IS NOT NULL
                         AND mps.product_id = mvi.product_id
                         AND mvi.qty_before <= mps.min_threshold
                   ) AS low_threshold_slots_count,
                   COALESCE(SUM(mvi.qty_added), 0) AS added_units,
                   COALESCE(SUM(mvi.qty_withdrawn_expired), 0) AS expired_units,
                   COALESCE(SUM(mvi.qty_withdrawn_damaged), 0) AS damaged_units,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_expired * mvi.unit_cost_snapshot_eur, 2)), 0) AS expired_loss_eur,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_damaged * mvi.unit_cost_snapshot_eur, 2)), 0) AS damaged_loss_eur,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_expired * mvi.unit_cost_snapshot_eur, 2)), 0)
                     + COALESCE(SUM(ROUND(mvi.qty_withdrawn_damaged * mvi.unit_cost_snapshot_eur, 2)), 0) AS commercial_spoilage_loss_eur
              FROM machine_restock_visit_items mvi
              JOIN machine_restock_visits mrv ON mrv.id = mvi.visit_id
              JOIN machines m ON m.id = mrv.machine_id
              JOIN locations l ON l.id = m.location_id
              LEFT JOIN machine_planogram_slots mps ON mps.id = mvi.slot_id
             WHERE mrv.status = 'COMPLETED'
               AND mrv.completed_at >= ? AND mrv.completed_at < ?{$where}
             GROUP BY mrv.machine_id, mvi.product_id
             ORDER BY mrv.machine_id ASC, mvi.product_id ASC
        ";

        return array_map(static fn(array $row): array => [
            'machine_id' => (int)$row['machine_id'],
            'product_id' => (int)$row['product_id'],
            'audited_slots_count' => (int)$row['audited_slots_count'],
            'stockout_slots_count' => (int)$row['stockout_slots_count'],
            'low_threshold_slots_count' => (int)$row['low_threshold_slots_count'],
            'added_units' => (int)$row['added_units'],
            'expired_units' => (int)$row['expired_units'],
            'damaged_units' => (int)$row['damaged_units'],
            'expired_loss_eur' => round((float)$row['expired_loss_eur'], 2),
            'damaged_loss_eur' => round((float)$row['damaged_loss_eur'], 2),
            'commercial_spoilage_loss_eur' => round((float)$row['commercial_spoilage_loss_eur'], 2),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchProductCatalog(RestockingBiFilterDto $filter): array
    {
        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', 'mrv.restocker_id', $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT DISTINCT vp.id AS product_id,
                   vp.sku,
                   vp.name,
                   vp.category,
                   vp.is_active
              FROM vending_products vp
              JOIN machine_restock_visit_items mvi ON mvi.product_id = vp.id
              JOIN machine_restock_visits mrv ON mrv.id = mvi.visit_id
              JOIN machines m ON m.id = mrv.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE mrv.status = 'COMPLETED'
               AND mrv.completed_at >= ? AND mrv.completed_at < ?{$where}
             ORDER BY vp.sku ASC
        ";

        return array_map(static fn(array $row): array => [
            'product_id' => (int)$row['product_id'],
            'sku' => (string)$row['sku'],
            'name' => (string)$row['name'],
            'category' => (string)$row['category'],
            'is_active' => self::toBool($row['is_active']),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchSlotItemsByRestocker(RestockingBiFilterDto $filter): array
    {
        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', 'mrv.restocker_id', $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT mrv.restocker_id,
                   COUNT(*) AS audited_slots_count,
                   COUNT(*) FILTER (WHERE mvi.qty_before = 0) AS stockout_slots_count,
                   COUNT(*) FILTER (
                       WHERE mvi.qty_before > 0
                         AND mps.min_threshold IS NOT NULL
                         AND mps.product_id = mvi.product_id
                         AND mvi.qty_before <= mps.min_threshold
                   ) AS low_threshold_slots_count,
                   COALESCE(SUM(mvi.qty_added), 0) AS added_units,
                   COALESCE(SUM(mvi.qty_withdrawn_expired), 0) AS expired_units,
                   COALESCE(SUM(mvi.qty_withdrawn_damaged), 0) AS damaged_units,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_expired * mvi.unit_cost_snapshot_eur, 2)), 0) AS expired_loss_eur,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_damaged * mvi.unit_cost_snapshot_eur, 2)), 0) AS damaged_loss_eur,
                   COALESCE(SUM(ROUND(mvi.qty_withdrawn_expired * mvi.unit_cost_snapshot_eur, 2)), 0)
                     + COALESCE(SUM(ROUND(mvi.qty_withdrawn_damaged * mvi.unit_cost_snapshot_eur, 2)), 0) AS commercial_spoilage_loss_eur
              FROM machine_restock_visit_items mvi
              JOIN machine_restock_visits mrv ON mrv.id = mvi.visit_id
              JOIN machines m ON m.id = mrv.machine_id
              JOIN locations l ON l.id = m.location_id
              LEFT JOIN machine_planogram_slots mps ON mps.id = mvi.slot_id
             WHERE mrv.status = 'COMPLETED'
               AND mrv.completed_at >= ? AND mrv.completed_at < ?{$where}
             GROUP BY mrv.restocker_id
             ORDER BY mrv.restocker_id ASC
        ";

        return array_map(static fn(array $row): array => [
            'restocker_id' => (int)$row['restocker_id'],
            'audited_slots_count' => (int)$row['audited_slots_count'],
            'stockout_slots_count' => (int)$row['stockout_slots_count'],
            'low_threshold_slots_count' => (int)$row['low_threshold_slots_count'],
            'added_units' => (int)$row['added_units'],
            'expired_units' => (int)$row['expired_units'],
            'damaged_units' => (int)$row['damaged_units'],
            'expired_loss_eur' => round((float)$row['expired_loss_eur'], 2),
            'damaged_loss_eur' => round((float)$row['damaged_loss_eur'], 2),
            'commercial_spoilage_loss_eur' => round((float)$row['commercial_spoilage_loss_eur'], 2),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchThermalRecords(RestockingBiFilterDto $filter): array
    {
        if ($filter->restockerId() !== null) {
            return [];
        }

        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', null, $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT ssr.machine_id,
                   COUNT(*) AS records_count,
                   COALESCE(SUM(ssr.discarded_units_count), 0) AS discarded_units_count,
                   COALESCE(SUM(ssr.estimated_merchandise_loss_eur), 0) AS estimated_merchandise_loss_eur
              FROM sanitary_spoilage_records ssr
              JOIN machines m ON m.id = ssr.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE ssr.created_at >= ? AND ssr.created_at < ?{$where}
             GROUP BY ssr.machine_id
             ORDER BY ssr.machine_id ASC
        ";

        return array_map(static fn(array $row): array => [
            'machine_id' => (int)$row['machine_id'],
            'records_count' => (int)$row['records_count'],
            'discarded_units_count' => (int)$row['discarded_units_count'],
            'estimated_merchandise_loss_eur' => round((float)$row['estimated_merchandise_loss_eur'], 2),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchValidShifts(RestockingBiFilterDto $filter): array
    {
        if ($filter->hasTerritorialFilter()) {
            return [];
        }

        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $restockerClause = '';

        if ($filter->restockerId() !== null) {
            $restockerClause = ' AND ts.technician_id = ?';
            $params[] = $filter->restockerId();
        }

        $sql = "
            SELECT ts.technician_id,
                   COUNT(*) AS valid_shifts_count
              FROM technician_shifts ts
              JOIN users u ON u.id = ts.technician_id
             WHERE u.role = 'RESTOCKER'
               AND ts.started_at >= ? AND ts.started_at < ?
               AND NOT (
                   ts.status = 'OFF_SHIFT'
                   AND ts.closed_at IS NOT NULL
                   AND ts.closed_at = ts.started_at
               ){$restockerClause}
             GROUP BY ts.technician_id
             ORDER BY ts.technician_id ASC
        ";

        return array_map(static fn(array $row): array => [
            'technician_id' => (int)$row['technician_id'],
            'valid_shifts_count' => (int)$row['valid_shifts_count'],
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchCashCollections(RestockingBiFilterDto $filter): array
    {
        $params = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $conditions = $this->scopeConditions('l', 'mcc.restocker_id', $filter, $params);
        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $sql = "
            SELECT mcc.restocker_id,
                   COUNT(*) FILTER (WHERE mcc.status = 'RECONCILED_OK') AS reconciled_ok_count,
                   COUNT(*) FILTER (WHERE mcc.status = 'DISCREPANCY_FLAGGED') AS discrepancy_flagged_count,
                   COUNT(*) FILTER (WHERE mcc.status IN ('SEALED_IN_ROUTE', 'DELIVERED_TO_VAULT')) AS pending_audit_count,
                   COALESCE(
                       SUM(ABS(mcc.discrepancy_eur))
                           FILTER (WHERE mcc.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED')),
                       0
                   ) AS total_abs_discrepancy_eur
              FROM machine_cash_collections mcc
              JOIN machines m ON m.id = mcc.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE mcc.collected_at >= ? AND mcc.collected_at < ?{$where}
             GROUP BY mcc.restocker_id
             ORDER BY mcc.restocker_id ASC
        ";

        return array_map(static fn(array $row): array => [
            'restocker_id' => (int)$row['restocker_id'],
            'reconciled_ok_count' => (int)$row['reconciled_ok_count'],
            'discrepancy_flagged_count' => (int)$row['discrepancy_flagged_count'],
            'pending_audit_count' => (int)$row['pending_audit_count'],
            'total_abs_discrepancy_eur' => round((float)$row['total_abs_discrepancy_eur'], 2),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function fetchL1Incidents(RestockingBiFilterDto $filter): array
    {
        $resolvedParams = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $resolvedConditions = $this->scopeConditions('l', 'i.assigned_technician_id', $filter, $resolvedParams);
        $resolvedWhere = $resolvedConditions === [] ? '' : ' AND ' . implode(' AND ', $resolvedConditions);

        $resolvedSql = "
            SELECT i.assigned_technician_id AS restocker_id,
                   COUNT(*) AS l1_resolved_count
              FROM incidents i
              JOIN users u ON u.id = i.assigned_technician_id
              JOIN machines m ON m.id = i.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE i.deleted_at IS NULL
               AND u.role = 'RESTOCKER'
               AND i.dispatch_level = 'L1_RESTOCKER'
               AND i.status IN ('RESOLVED', 'CLOSED')
               AND i.resolved_at >= ? AND i.resolved_at < ?{$resolvedWhere}
             GROUP BY i.assigned_technician_id
        ";

        $escalatedParams = [$filter->fromAt()->format('Y-m-d H:i:sP'), $filter->toExclusiveAt()->format('Y-m-d H:i:sP')];
        $escalatedConditions = $this->scopeConditions('l', 'i.escalated_from_restocker_id', $filter, $escalatedParams);
        $escalatedWhere = $escalatedConditions === [] ? '' : ' AND ' . implode(' AND ', $escalatedConditions);

        $escalatedSql = "
            SELECT i.escalated_from_restocker_id AS restocker_id,
                   COUNT(*) AS escalated_to_sat_count
              FROM incidents i
              JOIN users u ON u.id = i.escalated_from_restocker_id
              JOIN machines m ON m.id = i.machine_id
              JOIN locations l ON l.id = m.location_id
             WHERE i.deleted_at IS NULL
               AND u.role = 'RESTOCKER'
               AND i.escalated_from_restocker_id IS NOT NULL
               AND i.escalated_to_sat_at >= ? AND i.escalated_to_sat_at < ?{$escalatedWhere}
             GROUP BY i.escalated_from_restocker_id
        ";

        $rows = [];
        foreach ($this->fetchAll($resolvedSql, $resolvedParams) as $row) {
            $restockerId = (int)$row['restocker_id'];
            $rows[$restockerId] = [
                'restocker_id' => $restockerId,
                'l1_resolved_count' => (int)$row['l1_resolved_count'],
                'escalated_to_sat_count' => 0,
            ];
        }

        foreach ($this->fetchAll($escalatedSql, $escalatedParams) as $row) {
            $restockerId = (int)$row['restocker_id'];
            $rows[$restockerId] ??= [
                'restocker_id' => $restockerId,
                'l1_resolved_count' => 0,
                'escalated_to_sat_count' => 0,
            ];
            $rows[$restockerId]['escalated_to_sat_count'] = (int)$row['escalated_to_sat_count'];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAvailableFilters(RestockingBiFilterDto $filter): array
    {
        $from = $filter->fromAt()->format('Y-m-d H:i:sP');
        $to = $filter->toExclusiveAt()->format('Y-m-d H:i:sP');

        return [
            'zones' => $this->fetchAvailableZones($filter, $from, $to),
            'locations' => $this->fetchAvailableLocations($filter, $from, $to),
            'restockers' => $this->fetchAvailableRestockers($filter, $from, $to),
        ];
    }

    /**
     * Zonas activas, inactivas con actividad y la seleccionada (`QA-4`).
     *
     * @return list<array{id: int, code: string, name: string, is_active: bool}>
     */
    private function fetchAvailableZones(RestockingBiFilterDto $filter, string $from, string $to): array
    {
        $sql = "
            SELECT z.id, z.code, z.name, z.is_active
              FROM operational_zones z
             WHERE z.is_active = TRUE
                OR z.id = ?
                OR EXISTS (
                    SELECT 1
                      FROM locations al
                      JOIN machines am ON am.location_id = al.id
                     WHERE al.zone_id = z.id
                       AND (
                           EXISTS (
                               SELECT 1 FROM machine_restock_visits av
                                WHERE av.machine_id = am.id
                                  AND av.completed_at >= ? AND av.completed_at < ?
                           )
                           OR EXISTS (
                               SELECT 1 FROM sanitary_spoilage_records asr
                                WHERE asr.machine_id = am.id
                                  AND asr.created_at >= ? AND asr.created_at < ?
                           )
                       )
                )
             ORDER BY z.code ASC
        ";

        $params = [$filter->zoneId(), $from, $to, $from, $to];

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'code' => (string)$row['code'],
            'name' => (string)$row['name'],
            'is_active' => self::toBool($row['is_active']),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * Sedes activas, inactivas con actividad y la seleccionada (`QA-4`).
     *
     * @return list<array{id: int, site_code: string, name: string, zone_id: int|null, is_active: bool}>
     */
    private function fetchAvailableLocations(RestockingBiFilterDto $filter, string $from, string $to): array
    {
        $sql = "
            SELECT l.id, l.site_code, l.name, l.zone_id,
                   (l.is_active AND l.deleted_at IS NULL) AS is_active
              FROM locations l
             WHERE (l.is_active AND l.deleted_at IS NULL)
                OR l.id = ?
                OR EXISTS (
                    SELECT 1
                      FROM machines am
                      JOIN machine_restock_visits av ON av.machine_id = am.id
                     WHERE am.location_id = l.id
                       AND av.completed_at >= ? AND av.completed_at < ?
                )
                OR EXISTS (
                    SELECT 1
                      FROM machines am
                      JOIN sanitary_spoilage_records asr ON asr.machine_id = am.id
                     WHERE am.location_id = l.id
                       AND asr.created_at >= ? AND asr.created_at < ?
                )
             ORDER BY l.site_code ASC
        ";

        $params = [$filter->locationId(), $from, $to, $from, $to];

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'site_code' => (string)$row['site_code'],
            'name' => (string)$row['name'],
            'zone_id' => $row['zone_id'] === null ? null : (int)$row['zone_id'],
            'is_active' => self::toBool($row['is_active']),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * Reponedores activos, inactivos con actividad y el seleccionado (`QA-4`).
     *
     * La actividad es la misma que usa `by_restocker`: visita, recaudación,
     * incidencia L1/escalado o turno válido. Con filtro territorial los turnos
     * no segmentan, igual que en el cálculo de `machines_per_shift`.
     *
     * @return list<array{id: int, operator_code: string|null, name: string, is_active: bool}>
     */
    private function fetchAvailableRestockers(RestockingBiFilterDto $filter, string $from, string $to): array
    {
        $shiftActivity = $filter->hasTerritorialFilter()
            ? ''
            : "
                OR EXISTS (
                    SELECT 1 FROM technician_shifts ts
                     WHERE ts.technician_id = u.id
                       AND ts.started_at >= ? AND ts.started_at < ?
                       AND NOT (
                           ts.status = 'OFF_SHIFT'
                           AND ts.closed_at IS NOT NULL
                           AND ts.closed_at = ts.started_at
                       )
                )";

        $sql = "
            SELECT u.id, u.operator_code, u.name,
                   (u.is_active AND u.deleted_at IS NULL) AS is_active
              FROM users u
             WHERE u.role = 'RESTOCKER'
               AND (
                   (u.is_active AND u.deleted_at IS NULL)
                   OR u.id = ?
                   OR EXISTS (
                       SELECT 1 FROM machine_restock_visits av
                        WHERE av.restocker_id = u.id
                          AND av.completed_at >= ? AND av.completed_at < ?
                   )
                   OR EXISTS (
                       SELECT 1 FROM machine_cash_collections acc
                        WHERE acc.restocker_id = u.id
                          AND acc.collected_at >= ? AND acc.collected_at < ?
                   )
                   OR EXISTS (
                       SELECT 1 FROM incidents ai
                        WHERE ai.deleted_at IS NULL
                          AND ai.assigned_technician_id = u.id
                          AND ai.dispatch_level = 'L1_RESTOCKER'
                          AND ai.status IN ('RESOLVED', 'CLOSED')
                          AND ai.resolved_at >= ? AND ai.resolved_at < ?
                   )
                   OR EXISTS (
                       SELECT 1 FROM incidents ae
                        WHERE ae.deleted_at IS NULL
                          AND ae.escalated_from_restocker_id = u.id
                          AND ae.escalated_to_sat_at >= ? AND ae.escalated_to_sat_at < ?
                   ){$shiftActivity}
               )
             ORDER BY u.name ASC
        ";

        $params = [$filter->restockerId(), $from, $to, $from, $to, $from, $to, $from, $to];

        if (!$filter->hasTerritorialFilter()) {
            $params[] = $from;
            $params[] = $to;
        }

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'operator_code' => $row['operator_code'] === null ? null : (string)$row['operator_code'],
            'name' => (string)$row['name'],
            'is_active' => self::toBool($row['is_active']),
        ], $this->fetchAll($sql, $params));
    }

    /**
     * Condiciones territoriales compartidas y sus parámetros.
     *
     * La restricción por sede se resuelve contra la ubicación real de la máquina:
     * filtrar por zona excluye las sedes sin demarcación (`zone_id = null`), que
     * sí entran en la consulta general.
     *
     * @param list<mixed> $params Se amplía en el orden en que se añaden las condiciones.
     *
     * @return list<string>
     */
    private function scopeConditions(
        string $locationAlias,
        ?string $restockerColumn,
        RestockingBiFilterDto $filter,
        array &$params
    ): array {
        $conditions = [];

        if ($filter->zoneId() !== null) {
            $conditions[] = $locationAlias . '.zone_id = ?';
            $params[] = $filter->zoneId();
        }

        if ($filter->locationId() !== null) {
            $conditions[] = $locationAlias . '.id = ?';
            $params[] = $filter->locationId();
        }

        if ($restockerColumn !== null && $filter->restockerId() !== null) {
            $conditions[] = $restockerColumn . ' = ?';
            $params[] = $filter->restockerId();
        }

        return $conditions;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows === false ? [] : $rows;
    }

    private static function toBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
