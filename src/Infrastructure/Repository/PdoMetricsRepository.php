<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Model\KpiSummary;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\MttrMetric;
use VendGuard\Core\Domain\Repository\MetricsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMetricsRepository
 * 
 * Implementación de persistencia y cálculo analítico con PDO.
 * Ejecuta agregaciones SQL nativas optimizadas para tiempo natural continuo 24/7 (RF-01, EARS 1.1)
 * filtrando por fecha de resolución (`resolved_at`, EARS 1.2) y excluyendo cancelados/duplicados.
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
     * Calcula el MTTR global para el filtro temporal dado (filtrando por resolved_at, EARS 1.2).
     */
    public function getGlobalMttr(MetricFilter $filter): MttrMetric
    {
        $sql = "SELECT 
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `resolved_at`))) as avg_minutes
                FROM `incidents`
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
            $avgMinutes = $stmt->fetchColumn();

            if ($avgMinutes === false || $avgMinutes === null) {
                return MttrMetric::noData();
            }

            return MttrMetric::fromMinutes((int)$avgMinutes);
        } catch (PDOException $e) {
            throw new RuntimeException("Error al calcular MTTR global: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Calcula el MTTR específico para máquinas de alimentos perecederos (PERISHABLE_FOOD, Art. II).
     */
    public function getPerishableMttr(MetricFilter $filter): MttrMetric
    {
        $sql = "SELECT 
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`))) as avg_minutes
                FROM `incidents` i
                INNER JOIN `machines` m ON i.`machine_id` = m.`id`
                WHERE i.`resolved_at` IS NOT NULL
                  AND i.`status` IN ('RESOLVED', 'CLOSED')
                  AND m.`machine_type` = 'PERISHABLE_FOOD'
                  AND i.`resolved_at` >= :from_date
                  AND i.`resolved_at` <= :to_date";

        $params = [
            ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
            ':to_date'   => $filter->getTo()->format('Y-m-d H:i:s'),
        ];

        if ($filter->getLocationId() !== null) {
            $sql .= " AND i.`location_id` = :location_id";
            $params[':location_id'] = $filter->getLocationId();
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $avgMinutes = $stmt->fetchColumn();

            if ($avgMinutes === false || $avgMinutes === null) {
                return MttrMetric::noData();
            }

            return MttrMetric::fromMinutes((int)$avgMinutes);
        } catch (PDOException $e) {
            throw new RuntimeException("Error al calcular MTTR de perecederos: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
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
                    (m.`machine_type` = 'PERISHABLE_FOOD' AND TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`) > 240)
                    OR
                    (m.`machine_type` != 'PERISHABLE_FOOD' AND TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`) > 1440)
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
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`))) AS avg_minutes
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
                $avgMin = ($row['avg_minutes'] !== null && $count > 0) ? (int)$row['avg_minutes'] : null;
                $mttr = MttrMetric::fromMinutes($avgMin);

                $targetHours = KpiSummary::SLA_GENERAL_HOURS;
                $slaStatus = 'NO_DATA';
                if ($mttr->hasData()) {
                    $slaStatus = ($mttr->getHours() > $targetHours) ? 'BREACHED' : 'COMPLIANT';
                }

                $result[] = [
                    'location_id' => (int)$row['location_id'],
                    'site_code' => (string)$row['site_code'],
                    'location_name' => (string)$row['location_name'],
                    'is_active' => (bool)$row['is_active'],
                    'tickets_resolved' => $count,
                    'mttr_minutes' => $mttr->getMinutes(),
                    'mttr_formatted' => $mttr->getFormatted(),
                    'mttr_hours' => $mttr->getHours(),
                    'sla_target_hours' => $targetHours,
                    'sla_status' => $slaStatus,
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
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`))) AS avg_minutes
                FROM `users` u
                LEFT JOIN `incidents` i ON i.`assigned_technician_id` = u.`id`
                    AND i.`resolved_at` IS NOT NULL
                    AND i.`status` IN ('RESOLVED', 'CLOSED')
                    AND i.`resolved_at` >= :from_date
                    AND i.`resolved_at` <= :to_date
                WHERE u.`role` = 'TECHNICIAN'
                GROUP BY u.`id`, u.`name`, u.`is_active`
                ORDER BY tickets_resolved DESC, u.`name` ASC";

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
                $avgMin = ($row['avg_minutes'] !== null && $count > 0) ? (int)$row['avg_minutes'] : null;
                $mttr = MttrMetric::fromMinutes($avgMin);
                $isActive = (bool)$row['is_active'];
                $displayName = (string)$row['technician_name'] . ($isActive ? '' : ' (Inactivo)');

                $result[] = [
                    'technician_id' => (int)$row['technician_id'],
                    'technician_name' => (string)$row['technician_name'],
                    'is_active' => $isActive,
                    'display_name' => $displayName,
                    'tickets_resolved' => $count,
                    'mttr_minutes' => $mttr->getMinutes(),
                    'mttr_formatted' => $mttr->getFormatted(),
                    'mttr_hours' => $mttr->getHours(),
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
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.`created_at`, i.`resolved_at`))) AS avg_minutes
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
                $avgMin = ($hasRow && $indexed[$typeKey]['avg_minutes'] !== null && $count > 0) ? (int)$indexed[$typeKey]['avg_minutes'] : null;
                $mttr = MttrMetric::fromMinutes($avgMin);

                $slaStatus = 'NO_DATA';
                if ($mttr->hasData()) {
                    $slaStatus = ($mttr->getHours() > $meta['sla']) ? 'BREACHED' : 'COMPLIANT';
                }

                $result[] = [
                    'machine_type' => $typeKey,
                    'display_name' => $meta['name'],
                    'is_perishable' => $meta['perishable'],
                    'tickets_resolved' => $count,
                    'mttr_minutes' => $mttr->getMinutes(),
                    'mttr_formatted' => $mttr->getFormatted(),
                    'mttr_hours' => $mttr->getHours(),
                    'sla_target_hours' => $meta['sla'],
                    'sla_status' => $slaStatus,
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
                    `category`,
                    COUNT(`id`) AS tickets_resolved,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `resolved_at`))) AS avg_minutes
                FROM `incidents`
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
                $avgMin = ($hasRow && $indexed[$catKey]['avg_minutes'] !== null && $count > 0) ? (int)$indexed[$catKey]['avg_minutes'] : null;
                $mttr = MttrMetric::fromMinutes($avgMin);

                $result[] = [
                    'category' => $catKey,
                    'display_name' => $catName,
                    'tickets_resolved' => $count,
                    'mttr_minutes' => $mttr->getMinutes(),
                    'mttr_formatted' => $mttr->getFormatted(),
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
        // 1. MTTR y tickets resueltos
        $mttrSql = "SELECT 
                        COUNT(*) AS total_resolved,
                        ROUND(AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `resolved_at`))) AS avg_mttr_minutes
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
            $avgMttrMin = ($mttrRow['avg_mttr_minutes'] !== null && $totalResolved > 0) ? (int)$mttrRow['avg_mttr_minutes'] : null;
            $myMttr = MttrMetric::fromMinutes($avgMttrMin);

            $avgRespMin = ($avgResponse !== false && $avgResponse !== null) ? (int)$avgResponse : null;
            $respFormatted = ($avgRespMin !== null) ? sprintf('%dh %02dm', intdiv($avgRespMin, 60), $avgRespMin % 60) : 'N/A';

            return [
                'technician_id' => $technicianId,
                'my_mttr' => $myMttr,
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
