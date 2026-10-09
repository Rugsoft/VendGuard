<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Model\KpiSummary;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Repository\MetricsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMetricsRepository
 * 
 * Implementación de persistencia y cálculo analítico con PDO.
 * Ejecuta agregaciones SQL nativas optimizadas para tiempo natural continuo 24/7 (RF-01, EARS 1.1)
 * filtrando por fecha de resolución (`resolved_at`, EARS 1.2) y excluyendo cancelados/duplicados.
 * 
 * Módulo 11 (RF-03.1): expone la muestra bruta de resolución y la media de segundos en
 * `PENDING_INFO` (`total_pending_info_seconds`), ambas en segundos. La resta del MTTR vive en
 * `MetricsCalculationService`, salvo en el recuento de brechas de SLA, donde el umbral se decide
 * ticket a ticket y no puede descomponerse en una media: allí se netea dentro del SQL.
 * 
 * Cumple con RNF-02 (rendimiento analítico < 1.5s) y el Dogma Vanilla (PHP 8.2+ puro).
 */
class PdoMetricsRepository implements MetricsRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Tiempo medio BRUTO de resolución en segundos para el filtro dado (filtrando por resolved_at, EARS 1.2).
     * 
     * Devuelve null cuando no hay muestra. Las pausas `PENDING_INFO` todavía NO se descuentan:
     * esa resta es responsabilidad de `MetricsCalculationService` (RF-03.1).
     */
    public function getGrossMttrSeconds(MetricFilter $filter, ?MachineType $machineType = null): ?int
    {
        [$scope, $params] = $this->buildResolvedSampleScope($filter, $machineType);

        $sql = "SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS avg_seconds"
             . $scope;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $avgSeconds = $stmt->fetchColumn();

            if ($avgSeconds === false || $avgSeconds === null) {
                return null;
            }

            return (int)$avgSeconds;
        } catch (PDOException $e) {
            throw new RuntimeException("Error al calcular el tiempo bruto de resolución: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Media de segundos en `PENDING_INFO` descontables por cada ticket resuelto del periodo
     * (RF-03.1, RF-04.1). Es el descuento acumulado multi-pausa del expediente.
     * 
     * Devuelve 0 cuando no hay muestra o ningún ticket del periodo acumuló pausas.
     */
    public function getAveragePendingInfoSeconds(MetricFilter $filter, ?MachineType $machineType = null): int
    {
        [$scope, $params] = $this->buildResolvedSampleScope($filter, $machineType);

        $sql = "SELECT ROUND(AVG(i.`total_pending_info_seconds`)) AS avg_pause_seconds"
             . $scope;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $avgSeconds = $stmt->fetchColumn();

            if ($avgSeconds === false || $avgSeconds === null) {
                return 0;
            }

            return max(0, (int)$avgSeconds);
        } catch (PDOException $e) {
            throw new RuntimeException("Error al calcular el descuento de pausas PENDING_INFO: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Construye el FROM/WHERE común de las muestras analíticas de resolución (EARS 1.2, RF-03.1):
     * sólo expedientes resueltos o cerrados dentro del periodo, con los filtros opcionales de
     * sede, técnico responsable y tipología de máquina.
     * 
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildResolvedSampleScope(MetricFilter $filter, ?MachineType $machineType): array
    {
        $from = "\n                FROM `incidents` i";
        if ($machineType !== null) {
            $from .= "\n                INNER JOIN `machines` m ON i.`machine_id` = m.`id`";
        }

        $where = "\n                WHERE i.`resolved_at` IS NOT NULL
                  AND i.`status` IN ('RESOLVED', 'CLOSED')
                  AND i.`resolved_at` >= :from_date
                  AND i.`resolved_at` <= :to_date";

        $params = [
            ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
            ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
        ];

        if ($filter->getLocationId() !== null) {
            $where .= "\n                  AND i.`location_id` = :location_id";
            $params[':location_id'] = $filter->getLocationId();
        }

        if ($filter->getTechnicianId() !== null) {
            $where .= "\n                  AND i.`assigned_technician_id` = :technician_id";
            $params[':technician_id'] = $filter->getTechnicianId();
        }

        if ($machineType !== null) {
            $where .= "\n                  AND m.`machine_type` = :machine_type";
            $params[':machine_type'] = $machineType->value;
        }

        return [$from . $where, $params];
    }

    /**
     * Total de tickets creados en el rango temporal del filtro (EARS 3.1.2).
     */
    public function countCreatedTickets(MetricFilter $filter): int
    {
        $sql = "SELECT COUNT(*) FROM `incidents`
                WHERE `created_at` >= :from_date
                  AND `created_at` <= :to_date";

        $params = [
            ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
            ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
        ];

        if ($filter->getLocationId() !== null) {
            $sql .= " AND `location_id` = :location_id";
            $params[':location_id'] = $filter->getLocationId();
        }

        if ($filter->getTechnicianId() !== null) {
            $sql .= " AND `assigned_technician_id` = :technician_id";
            $params[':technician_id'] = $filter->getTechnicianId();
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new RuntimeException("Error al contar tickets creados: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Total de tickets resueltos o cerrados en el rango temporal del filtro (EARS 3.1.3).
     */
    public function countResolvedTickets(MetricFilter $filter): int
    {
        $sql = "SELECT COUNT(*) FROM `incidents`
                WHERE `resolved_at` IS NOT NULL
                  AND `status` IN ('RESOLVED', 'CLOSED')
                  AND `resolved_at` >= :from_date
                  AND `resolved_at` <= :to_date";

        $params = [
            ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
            ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
        ];

        if ($filter->getLocationId() !== null) {
            $sql .= " AND `location_id` = :location_id";
            $params[':location_id'] = $filter->getLocationId();
        }

        if ($filter->getTechnicianId() !== null) {
            $sql .= " AND `assigned_technician_id` = :technician_id";
            $params[':technician_id'] = $filter->getTechnicianId();
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new RuntimeException("Error al contar tickets resueltos: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Conteo del backlog activo (tickets no terminales a la fecha actual, EARS 3.1.4).
     */
    public function countActiveBacklog(): int
    {
        $sql = "SELECT COUNT(*) FROM `incidents`
                WHERE `status` IN ('REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'REOPENED')";

        try {
            $stmt = $this->pdo->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new RuntimeException("Error al contar backlog activo: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Cuenta cuántos tickets superaron el SLA en el periodo (EARS 3.1.5, 3.2).
     */
    public function countCriticalSlaBreaches(MetricFilter $filter): int
    {
        $sql = "SELECT COUNT(*) FROM `incidents` i
                INNER JOIN `machines` m ON i.`machine_id` = m.`id`
                WHERE i.`resolved_at` IS NOT NULL
                  AND i.`status` IN ('RESOLVED', 'CLOSED')
                  AND i.`resolved_at` >= :from_date
                  AND i.`resolved_at` <= :to_date
                  AND (
                    (m.`machine_type` = 'PERISHABLE_FOOD'
                     AND GREATEST(0, TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`) - i.`total_pending_info_seconds`) > 240 * 60)
                    OR
                    (m.`machine_type` != 'PERISHABLE_FOOD'
                     AND GREATEST(0, TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`) - i.`total_pending_info_seconds`) > 1440 * 60)
                  )";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new RuntimeException("Error al contar incumplimientos de SLA: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Desglose agrupado por sede histórica (EARS 2.2).
     */
    public function getBreakdownByLocation(MetricFilter $filter): array
    {
        $sql = "SELECT 
                    l.`id` AS location_id,
                    l.`site_code`,
                    l.`name` AS location_name,
                    l.`is_active`,
                    COUNT(i.`id`) AS tickets_resolved,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS avg_gross_seconds,
                    ROUND(AVG(i.`total_pending_info_seconds`)) AS avg_pause_seconds
                FROM `locations` l
                LEFT JOIN `incidents` i ON i.`location_id` = l.`id`
                    AND i.`resolved_at` IS NOT NULL
                    AND i.`status` IN ('RESOLVED', 'CLOSED')
                    AND i.`resolved_at` >= :from_date
                    AND i.`resolved_at` <= :to_date
                GROUP BY l.`id`, l.`site_code`, l.`name`, l.`is_active`
                ORDER BY tickets_resolved DESC, l.`name` ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];

            foreach ($rows as $row) {
                $count = (int)$row['tickets_resolved'];
                $grossSeconds = ($row['avg_gross_seconds'] !== null && $count > 0) ? (int)$row['avg_gross_seconds'] : null;
                $pauseSeconds = ($row['avg_pause_seconds'] !== null && $count > 0) ? max(0, (int)$row['avg_pause_seconds']) : 0;

                $result[] = [
                    'location_id' => (int)$row['location_id'],
                    'site_code' => (string)$row['site_code'],
                    'location_name' => (string)$row['location_name'],
                    'is_active' => (bool)$row['is_active'],
                    'tickets_resolved' => $count,
                    'mttr_gross_seconds' => $grossSeconds,
                    'mttr_pending_info_seconds' => $pauseSeconds,
                    'sla_target_hours' => KpiSummary::SLA_GENERAL_HOURS,
                ];
            }

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException("Error en desglose por sede: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Desglose agrupado por técnico resolutor final (EARS 2.3).
     */
    public function getBreakdownByTechnician(MetricFilter $filter): array
    {
        $sql = "SELECT 
                    u.`id` AS technician_id,
                    u.`name` AS technician_name,
                    u.`is_active`,
                    COUNT(i.`id`) AS tickets_resolved,
                    COUNT(rh.`incident_id`) AS warranty_reopens,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS avg_gross_seconds,
                    ROUND(AVG(i.`total_pending_info_seconds`)) AS avg_pause_seconds
                FROM `users` u
                LEFT JOIN `incidents` i ON i.`assigned_technician_id` = u.`id`
                    AND i.`resolved_at` IS NOT NULL
                    AND i.`status` IN ('RESOLVED', 'CLOSED')
                    AND i.`resolved_at` >= :from_date
                    AND i.`resolved_at` <= :to_date
                LEFT JOIN (
                    -- Reintervenciones por garantía (Art. V.6): reaperturas dentro de las
                    -- 48 h posteriores a una resolución, atribuidas al técnico que firmó la
                    -- resolución efectiva. El evento REOPENED de incident_history lleva
                    -- user_id = NULL (reopen() desasigna al técnico), de modo que la
                    -- atribución se deriva del evento RESOLVED inmediatamente anterior de
                    -- la misma avería; con created_at (marca del evento, no del expediente).
                    SELECT h.`incident_id`, h.`created_at`,
                           (SELECT h2.`user_id`
                              FROM `incident_history` h2
                             WHERE h2.`incident_id` = h.`incident_id`
                               AND h2.`to_status` IN ('RESOLVED', 'RESUELTA')
                               AND (h2.`created_at` < h.`created_at`
                                    OR (h2.`created_at` = h.`created_at` AND h2.`id` < h.`id`))
                             ORDER BY h2.`created_at` DESC, h2.`id` DESC
                             LIMIT 1) AS resolver_technician_id
                      FROM `incident_history` h
                     WHERE h.`to_status` IN ('REOPENED', 'REABIERTA')
                       AND h.`created_at` >= :reopens_from_date
                       AND h.`created_at` <= :reopens_to_date
                ) rh ON rh.`resolver_technician_id` = u.`id`
                WHERE u.`role` = 'TECHNICIAN'
                GROUP BY u.`id`, u.`name`, u.`is_active`
                ORDER BY tickets_resolved DESC, u.`name` ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
                ':reopens_from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':reopens_to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];

            foreach ($rows as $row) {
                $count = (int)$row['tickets_resolved'];
                $grossSeconds = ($row['avg_gross_seconds'] !== null && $count > 0) ? (int)$row['avg_gross_seconds'] : null;
                $pauseSeconds = ($row['avg_pause_seconds'] !== null && $count > 0) ? max(0, (int)$row['avg_pause_seconds']) : 0;
                $isActive = (bool)$row['is_active'];
                $displayName = (string)$row['technician_name'] . ($isActive ? '' : ' (Inactivo)');

                $result[] = [
                    'technician_id' => (int)$row['technician_id'],
                    'technician_name' => (string)$row['technician_name'],
                    'is_active' => $isActive,
                    'display_name' => $displayName,
                    'tickets_resolved' => $count,
                    'warranty_reopens' => (int)$row['warranty_reopens'],
                    'mttr_gross_seconds' => $grossSeconds,
                    'mttr_pending_info_seconds' => $pauseSeconds,
                ];
            }

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException("Error en desglose por técnico: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Desglose agrupado por tipología de máquina (EARS 2.4).
     */
    public function getBreakdownByMachineType(MetricFilter $filter): array
    {
        $sql = "SELECT 
                    m.`machine_type`,
                    COUNT(i.`id`) AS tickets_resolved,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS avg_gross_seconds,
                    ROUND(AVG(i.`total_pending_info_seconds`)) AS avg_pause_seconds
                FROM `incidents` i
                INNER JOIN `machines` m ON i.`machine_id` = m.`id`
                WHERE i.`resolved_at` IS NOT NULL
                  AND i.`status` IN ('RESOLVED', 'CLOSED')
                  AND i.`resolved_at` >= :from_date
                  AND i.`resolved_at` <= :to_date
                GROUP BY m.`machine_type`
                ORDER BY tickets_resolved DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $indexed = [];
            foreach ($rows as $r) {
                $indexed[(string)$r['machine_type']] = $r;
            }

            $allTypes = [
                'PERISHABLE_FOOD' => ['name' => 'Alimentos Perecederos (Sanitario)', 'perishable' => true, 'sla' => KpiSummary::SLA_PERISHABLE_HOURS],
                'HOT_DRINKS'      => ['name' => 'Bebidas Calientes', 'perishable' => false, 'sla' => KpiSummary::SLA_GENERAL_HOURS],
                'COLD_DRINKS'     => ['name' => 'Bebidas Frías', 'perishable' => false, 'sla' => KpiSummary::SLA_GENERAL_HOURS],
                'SNACKS'          => ['name' => 'Snacks y Alimentos Secos', 'perishable' => false, 'sla' => KpiSummary::SLA_GENERAL_HOURS],
                'COMBO'           => ['name' => 'Snacks y Refrescos (Combo)', 'perishable' => false, 'sla' => KpiSummary::SLA_GENERAL_HOURS],
            ];

            $result = [];
            foreach ($allTypes as $typeKey => $meta) {
                $hasRow = isset($indexed[$typeKey]);
                $count = $hasRow ? (int)$indexed[$typeKey]['tickets_resolved'] : 0;
                $grossSeconds = ($hasRow && $indexed[$typeKey]['avg_gross_seconds'] !== null && $count > 0) ? (int)$indexed[$typeKey]['avg_gross_seconds'] : null;
                $pauseSeconds = ($hasRow && $indexed[$typeKey]['avg_pause_seconds'] !== null && $count > 0) ? max(0, (int)$indexed[$typeKey]['avg_pause_seconds']) : 0;

                $result[] = [
                    'machine_type' => $typeKey,
                    'display_name' => $meta['name'],
                    'is_perishable' => $meta['perishable'],
                    'tickets_resolved' => $count,
                    'mttr_gross_seconds' => $grossSeconds,
                    'mttr_pending_info_seconds' => $pauseSeconds,
                    'sla_target_hours' => $meta['sla'],
                ];
            }

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException("Error en desglose por tipo de máquina: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Desglose agrupado por categoría de avería (EARS 2.5).
     */
    public function getBreakdownByCategory(MetricFilter $filter): array
    {
        $sql = "SELECT 
                    i.`category`,
                    COUNT(i.`id`) AS tickets_resolved,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS avg_gross_seconds,
                    ROUND(AVG(i.`total_pending_info_seconds`)) AS avg_pause_seconds
                FROM `incidents` i
                WHERE `resolved_at` IS NOT NULL
                  AND `status` IN ('RESOLVED', 'CLOSED')
                  AND `resolved_at` >= :from_date
                  AND `resolved_at` <= :to_date
                GROUP BY `category`
                ORDER BY tickets_resolved DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $indexed = [];
            foreach ($rows as $r) {
                $indexed[(string)$r['category']] = $r;
            }

            $allCategories = [
                'TEMPERATURE_COLD' => 'Cadena de Frío / Refrigeración',
                'PAYMENT_SYSTEM'   => 'Monedero / Sistema de Pago',
                'PRODUCT_JAM'      => 'Atasco de Producto',
                'ELECTRICAL_OFF'   => 'Alimentación Eléctrica / Apagado',
                'OTHER'            => 'Otras Averías',
            ];

            $result = [];
            foreach ($allCategories as $catKey => $catName) {
                $hasRow = isset($indexed[$catKey]);
                $count = $hasRow ? (int)$indexed[$catKey]['tickets_resolved'] : 0;
                $grossSeconds = ($hasRow && $indexed[$catKey]['avg_gross_seconds'] !== null && $count > 0) ? (int)$indexed[$catKey]['avg_gross_seconds'] : null;
                $pauseSeconds = ($hasRow && $indexed[$catKey]['avg_pause_seconds'] !== null && $count > 0) ? max(0, (int)$indexed[$catKey]['avg_pause_seconds']) : 0;

                $result[] = [
                    'category' => $catKey,
                    'display_name' => $catName,
                    'tickets_resolved' => $count,
                    'mttr_gross_seconds' => $grossSeconds,
                    'mttr_pending_info_seconds' => $pauseSeconds,
                ];
            }

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException("Error en desglose por categoría: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Métricas individuales para autoconsulta del técnico autenticado (RF-04).
     */
    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array
    {
        // 1. Muestra bruta de resolución del técnico: segundos de vida y pausas PENDING_INFO
        //    descontables (RF-03.1). El neto lo compone el servicio.
        $mttrSql = "SELECT 
                        COUNT(*) AS total_resolved,
                        ROUND(AVG(TIMESTAMPDIFF(SECOND, `created_at`, `resolved_at`))) AS avg_gross_seconds,
                        ROUND(AVG(`total_pending_info_seconds`)) AS avg_pause_seconds
                    FROM `incidents`
                    WHERE `assigned_technician_id` = :tech_id
                      AND `resolved_at` IS NOT NULL
                      AND `status` IN ('RESOLVED', 'CLOSED')
                      AND `resolved_at` >= :from_date
                      AND `resolved_at` <= :to_date";

        // 2. Tickets actualmente en curso asignados
        $inProgressSql = "SELECT COUNT(*) FROM `incidents`
                          WHERE `assigned_technician_id` = :tech_id
                            AND `status` = 'IN_PROGRESS'";

        // 3. Tiempo de primera respuesta (created_at hasta started_at en minutos)
        $firstResponseSql = "SELECT 
                                 ROUND(AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `started_at`))) AS avg_response_minutes
                             FROM `incidents`
                             WHERE `assigned_technician_id` = :tech_id
                               AND `started_at` IS NOT NULL
                               AND `created_at` >= :from_date
                               AND `created_at` <= :to_date";

        try {
            $stmtMttr = $this->pdo->prepare($mttrSql);
            $stmtMttr->execute([
                ':tech_id'   => $technicianId,
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);
            $mttrRow = $stmtMttr->fetch(PDO::FETCH_ASSOC);

            $stmtProg = $this->pdo->prepare($inProgressSql);
            $stmtProg->execute([':tech_id' => $technicianId]);
            $inProgressCount = (int)$stmtProg->fetchColumn();

            $stmtResp = $this->pdo->prepare($firstResponseSql);
            $stmtResp->execute([
                ':tech_id'   => $technicianId,
                ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
                ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
            ]);
            $avgResponse = $stmtResp->fetchColumn();

            $totalResolved = (int)($mttrRow['total_resolved'] ?? 0);
            $grossSeconds = ($mttrRow['avg_gross_seconds'] !== null && $totalResolved > 0) ? (int)$mttrRow['avg_gross_seconds'] : null;
            $pauseSeconds = ($mttrRow['avg_pause_seconds'] !== null && $totalResolved > 0) ? max(0, (int)$mttrRow['avg_pause_seconds']) : 0;

            $avgRespMin = ($avgResponse !== false && $avgResponse !== null) ? (int)$avgResponse : null;
            $respFormatted = ($avgRespMin !== null) ? sprintf('%dh %02dm', intdiv($avgRespMin, 60), $avgRespMin % 60) : 'N/A';

            return [
                'technician_id' => $technicianId,
                'my_gross_mttr_seconds' => $grossSeconds,
                'my_pending_info_seconds' => $pauseSeconds,
                'total_resolved' => $totalResolved,
                'current_in_progress' => $inProgressCount,
                'avg_first_response_minutes' => $avgRespMin,
                'avg_first_response_formatted' => $respFormatted,
            ];
        } catch (PDOException $e) {
            throw new RuntimeException("Error al calcular métricas individuales del técnico: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
