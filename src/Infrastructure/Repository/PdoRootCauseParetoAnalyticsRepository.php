<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use VendGuard\Application\DTO\ParetoFilterDto;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRootCauseParetoAnalyticsRepository
 *
 * Consultas analíticas del Diagrama de Pareto 80/20 (Módulo 25, RF-05, D5,
 * QA 2). Devuelve **filas crudas** listas para la agregación del servicio: no
 * calcula porcentajes, ni la Zona Vital, ni la normalización de fabricante,
 * porque esas reglas son de dominio y viven en `RootCauseParetoService` y
 * `ManufacturerNormalizer` (una sola implementación, no dos).
 *
 * Universo de la consulta (`QA 2`):
 *   - Averías con estado **actual** `RESOLVED` o `CLOSED` y `deleted_at IS NULL`.
 *   - `resolved_at` vigente dentro de la ventana `[start_date, end_date]`, con
 *     el fin tratado como día natural completo (límite exclusivo en el día
 *     siguiente) para que el último día no quede fuera por una comparación
 *     contra medianoche.
 *   - Se devuelven también las averías **sin clasificar** (`root_cause_id IS NULL`):
 *     el servicio las necesita para el contador informativo de históricas
 *     excluidas del 100 % del Pareto (C3). El filtro por categoría, en cambio,
 *     sólo puede aplicarse a las clasificadas, porque la categoría es del
 *     catálogo de causas.
 *
 * Todo se resuelve en **una sola consulta** con `LEFT JOIN` y subconsultas
 * agregadas, sin N+1: la exportación nominal de la Sección 2 del CSV necesita
 * una fila por expediente y el coste de repuestos ya viene sumado.
 */
class PdoRootCauseParetoAnalyticsRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Averías correctivas resueltas dentro de la ventana, con los datos vigentes
     * del catálogo, los snapshots congelados al cierre, el MTTR neto y el
     * consumo de repuestos.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchResolvedIncidentsForPareto(ParetoFilterDto $filter): array
    {
        $endExclusive = $this->endExclusiveDate($filter->getEndDate());

        $sql = "
            SELECT
                i.id AS incident_id,
                i.ticket_code,
                i.resolved_at,
                i.cycle_started_at,
                i.total_pending_info_seconds,
                ROUND(
                    GREATEST(
                        0,
                        EXTRACT(EPOCH FROM (i.resolved_at - i.cycle_started_at))
                        - COALESCE(i.total_pending_info_seconds, 0)
                    ) / 3600.0,
                    4
                ) AS mttr_hours,
                i.root_cause_id,
                rc.code AS current_root_cause_code,
                rc.category AS current_root_cause_category,
                rc.name AS current_root_cause_name,
                rc.is_active AS current_root_cause_is_active,
                i.root_cause_code_snapshot,
                i.root_cause_category_snapshot,
                i.root_cause_name_snapshot,
                i.standard_solution_id,
                ss.code AS current_standard_solution_code,
                ss.name AS current_standard_solution_name,
                ss.is_default AS current_standard_solution_is_default,
                ss.is_active AS current_standard_solution_is_active,
                i.standard_solution_code_snapshot,
                i.standard_solution_name_snapshot,
                i.machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type,
                i.location_id,
                l.name AS location_name,
                i.assigned_technician_id AS technician_id,
                u.name AS technician_name,
                COALESCE(rp.replaced_parts_units, 0) AS replaced_parts_units,
                COALESCE(rp.replaced_parts_cost_eur, 0) AS replaced_parts_cost_eur
            FROM incidents i
            LEFT JOIN root_causes rc ON rc.id = i.root_cause_id
            LEFT JOIN standard_solutions ss ON ss.id = i.standard_solution_id
            LEFT JOIN machines m ON m.id = i.machine_id
            LEFT JOIN locations l ON l.id = i.location_id
            LEFT JOIN users u ON u.id = i.assigned_technician_id
            LEFT JOIN (
                SELECT incident_id,
                       SUM(quantity) AS replaced_parts_units,
                       SUM(total_cost_snapshot) AS replaced_parts_cost_eur
                  FROM incident_replaced_parts
                 WHERE intervention_type = 'INCIDENT'
                 GROUP BY incident_id
            ) rp ON rp.incident_id = i.id
            WHERE i.status IN ('RESOLVED', 'CLOSED')
              AND i.deleted_at IS NULL
              AND i.resolved_at IS NOT NULL
              AND i.resolved_at >= :period_from
              AND i.resolved_at < :period_to_exclusive
        ";

        $category = $filter->getCategory();
        if ($category !== null) {
            $sql .= ' AND rc.category = :category';
        }

        $machineModel = $filter->getMachineModel();
        if ($machineModel !== null && trim($machineModel) !== '') {
            $sql .= ' AND m.model = :machine_model';
        }

        if ($filter->getLocationId() !== null) {
            $sql .= ' AND i.location_id = :location_id';
        }

        if ($filter->getTechnicianId() !== null) {
            $sql .= ' AND i.assigned_technician_id = :technician_id';
        }

        $sql .= ' ORDER BY i.resolved_at ASC, i.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':period_from', $filter->getStartDate(), PDO::PARAM_STR);
        $stmt->bindValue(':period_to_exclusive', $endExclusive, PDO::PARAM_STR);
        if ($category !== null) {
            $stmt->bindValue(':category', $category->value, PDO::PARAM_STR);
        }
        if ($machineModel !== null && trim($machineModel) !== '') {
            $stmt->bindValue(':machine_model', trim($machineModel), PDO::PARAM_STR);
        }
        if ($filter->getLocationId() !== null) {
            $stmt->bindValue(':location_id', $filter->getLocationId(), PDO::PARAM_INT);
        }
        if ($filter->getTechnicianId() !== null) {
            $stmt->bindValue(':technician_id', $filter->getTechnicianId(), PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Opciones vivas de los selectores de la pestaña analítica: modelos del
     * parque, sedes y técnicos.
     *
     * Los fabricantes y el mapa modelo → fabricante no se devuelven desde aquí:
     * se derivan de `models` con `ManufacturerNormalizer`, que es la única
     * fuente de verdad de esa extracción (`QA 4`).
     *
     * @return array{
     *     models: list<string>,
     *     locations: list<array{id: int, name: string}>,
     *     technicians: list<array{id: int, name: string}>
     * }
     */
    public function fetchAvailableFilterOptions(): array
    {
        $models = $this->pdo->query(
            "SELECT DISTINCT m.model
               FROM machines m
              WHERE m.deleted_at IS NULL
                AND m.is_active = TRUE
                AND m.model IS NOT NULL
                AND btrim(m.model) <> ''
              ORDER BY m.model ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $locations = $this->pdo->query(
            'SELECT l.id, l.name
               FROM locations l
              WHERE l.deleted_at IS NULL
              ORDER BY l.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $technicians = $this->pdo->query(
            "SELECT u.id, u.name
               FROM users u
              WHERE u.deleted_at IS NULL
                AND u.is_active = TRUE
                AND u.role = 'TECHNICIAN'
              ORDER BY u.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'models' => array_values(array_map('strval', $models)),
            'locations' => array_map(
                static fn (array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']],
                $locations
            ),
            'technicians' => array_map(
                static fn (array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']],
                $technicians
            ),
        ];
    }

    /**
     * Límite exclusivo del día siguiente al fin declarado: un rango
     * `2026-08-02 … 2026-10-30` incluye el 30 completo, no hasta su medianoche.
     */
    private function endExclusiveDate(string $endDate): string
    {
        $normalized = trim($endDate);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);

        // `createFromFormat` normaliza fechas imposibles (2026-02-31 -> 2026-03-03);
        // la ida y vuelta al mismo texto es lo que distingue una fecha real de una
        // corrección silenciosa del calendario.
        if ($parsed === false || $parsed->format('Y-m-d') !== $normalized) {
            throw new RuntimeException(sprintf(
                'La fecha de fin del filtro Pareto debe ser una fecha real en formato YYYY-MM-DD; se recibió «%s».',
                $endDate
            ));
        }

        return $parsed->modify('+1 day')->format('Y-m-d');
    }
}
