<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoIncidentReplacedPartRepository
 * 
 * Implementación PDO del repositorio de consumos de piezas sustituidas.
 * Registra snapshots inmutables de coste en resoluciones correctivas y preventivas (RF-REP-06, RF-REP-07),
 * y proporciona agregaciones analíticas y detección de fallos crónicos (RF-REP-08, RF-REP-09).
 */
class PdoIncidentReplacedPartRepository implements IncidentReplacedPartRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function insertReplacedPart(IncidentReplacedPart $part): IncidentReplacedPart
    {
        return $this->insertSingle($part);
    }

    /**
     * {@inheritdoc}
     */
    public function insertManyReplacedParts(array $parts): array
    {
        if (empty($parts)) {
            return [];
        }

        $results = [];
        $wasInTransaction = $this->pdo->inTransaction();

        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($parts as $part) {
                $results[] = $this->insertSingle($part);
            }

            if (!$wasInTransaction) {
                $this->pdo->commit();
            }

            return $results;
        } catch (PDOException $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error al registrar repuestos sustituidos en lote: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?IncidentReplacedPart
    {
        $sql = "
            SELECT 
                irp.*,
                sp.part_code,
                sp.name AS part_name,
                m.code AS machine_code,
                m.model AS machine_model,
                loc.name AS location_name,
                u.name AS technician_name
            FROM `incident_replaced_parts` irp
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            INNER JOIN `machines` m ON m.id = irp.machine_id
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            LEFT JOIN `users` u ON u.id = irp.technician_id
            WHERE irp.id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateReplacedPart($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByIncidentId(int $incidentId): array
    {
        $sql = "
            SELECT 
                irp.*,
                sp.part_code,
                sp.name AS part_name,
                m.code AS machine_code,
                m.model AS machine_model,
                loc.name AS location_name,
                u.name AS technician_name
            FROM `incident_replaced_parts` irp
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            INNER JOIN `machines` m ON m.id = irp.machine_id
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            LEFT JOIN `users` u ON u.id = irp.technician_id
            WHERE irp.incident_id = :incident_id
            ORDER BY irp.installed_at ASC, irp.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->hydrateReplacedPart($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function findByPreventiveOrderId(int $preventiveOrderId): array
    {
        $sql = "
            SELECT 
                irp.*,
                sp.part_code,
                sp.name AS part_name,
                m.code AS machine_code,
                m.model AS machine_model,
                loc.name AS location_name,
                u.name AS technician_name
            FROM `incident_replaced_parts` irp
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            INNER JOIN `machines` m ON m.id = irp.machine_id
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            LEFT JOIN `users` u ON u.id = irp.technician_id
            WHERE irp.preventive_order_id = :preventive_order_id
            ORDER BY irp.installed_at ASC, irp.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':preventive_order_id' => $preventiveOrderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->hydrateReplacedPart($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function getCostSummaryByMachineModel(?int $periodDays = null): array
    {
        $sql = "
            SELECT 
                m.model,
                COUNT(DISTINCT irp.machine_id) AS machines_count,
                COALESCE(SUM(irp.quantity), 0) AS units_replaced,
                ROUND(COALESCE(SUM(irp.total_cost_snapshot), 0.00), 2) AS total_cost
            FROM `incident_replaced_parts` irp
            INNER JOIN `machines` m ON m.id = irp.machine_id
            WHERE 1 = 1
        ";
        $params = [];

        if ($periodDays !== null && $periodDays > 0) {
            $sql .= " AND irp.installed_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)";
            $params[':period_days'] = $periodDays;
        }

        $sql .= " GROUP BY m.model ORDER BY total_cost DESC, units_replaced DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'model'          => (string)$r['model'],
                'machines_count' => (int)$r['machines_count'],
                'units_replaced' => (int)$r['units_replaced'],
                'total_cost'     => (float)$r['total_cost'],
            ];
        }, $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function getCostSummaryByLocation(?int $periodDays = null): array
    {
        $sql = "
            SELECT 
                loc.id AS location_id,
                loc.name AS location_name,
                COALESCE(SUM(irp.quantity), 0) AS units_replaced,
                ROUND(COALESCE(SUM(irp.total_cost_snapshot), 0.00), 2) AS total_cost
            FROM `incident_replaced_parts` irp
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            WHERE 1 = 1
        ";
        $params = [];

        if ($periodDays !== null && $periodDays > 0) {
            $sql .= " AND irp.installed_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)";
            $params[':period_days'] = $periodDays;
        }

        $sql .= " GROUP BY loc.id, loc.name ORDER BY total_cost DESC, units_replaced DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'location_id'    => (int)$r['location_id'],
                'location_name'  => (string)$r['location_name'],
                'units_replaced' => (int)$r['units_replaced'],
                'total_cost'     => (float)$r['total_cost'],
            ];
        }, $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array
    {
        $sql = "
            SELECT 
                COALESCE(sp.part_code, 'OUT_OF_CATALOG') AS part_code,
                COALESCE(sp.name, irp.custom_part_name, 'Pieza fuera de catálogo') AS name,
                COALESCE(sp.category, 'OTHER') AS category,
                COALESCE(SUM(irp.quantity), 0) AS units_installed,
                ROUND(COALESCE(SUM(irp.total_cost_snapshot), 0.00), 2) AS accumulated_cost,
                COALESCE(SUM(CASE WHEN irp.old_part_destination = 'DESGUACE' THEN irp.quantity ELSE 0 END), 0) AS desguace_units,
                COALESCE(SUM(CASE WHEN irp.old_part_destination = 'TALLER' THEN irp.quantity ELSE 0 END), 0) AS taller_units
            FROM `incident_replaced_parts` irp
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            WHERE 1 = 1
        ";
        $params = [];

        if ($periodDays !== null && $periodDays > 0) {
            $sql .= " AND irp.installed_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)";
            $params[':period_days'] = $periodDays;
        }

        $sql .= " GROUP BY 
                    COALESCE(sp.part_code, 'OUT_OF_CATALOG'),
                    COALESCE(sp.name, irp.custom_part_name, 'Pieza fuera de catálogo'),
                    COALESCE(sp.category, 'OTHER')
                  ORDER BY units_installed DESC, accumulated_cost DESC
                  LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'part_code'        => (string)$r['part_code'],
                'name'             => (string)$r['name'],
                'category'         => (string)$r['category'],
                'units_installed'  => (int)$r['units_installed'],
                'accumulated_cost' => (float)$r['accumulated_cost'],
                'destinations'     => [
                    'DESGUACE' => (int)$r['desguace_units'],
                    'TALLER'   => (int)$r['taller_units'],
                ],
            ];
        }, $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array
    {
        $sql = "
            SELECT 
                irp.machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                loc.name AS location_name,
                COALESCE(sp.part_code, 'OUT_OF_CATALOG') AS part_code,
                COALESCE(sp.name, irp.custom_part_name, 'Pieza fuera de catálogo') AS part_name,
                COALESCE(SUM(irp.quantity), 0) AS total_quantity,
                COUNT(irp.id) AS replacements_count,
                MIN(irp.installed_at) AS first_replacement_at,
                MAX(irp.installed_at) AS last_replacement_at
            FROM `incident_replaced_parts` irp
            INNER JOIN `machines` m ON m.id = irp.machine_id
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            WHERE irp.installed_at >= DATE_SUB(NOW(), INTERVAL :window_days DAY)
            GROUP BY 
                irp.machine_id,
                m.code,
                m.model,
                loc.name,
                COALESCE(sp.part_code, 'OUT_OF_CATALOG'),
                COALESCE(sp.name, irp.custom_part_name, 'Pieza fuera de catálogo')
            HAVING replacements_count > :threshold
            ORDER BY replacements_count DESC, last_replacement_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':window_days' => $windowDays,
            ':threshold'   => $threshold,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alerts = [];
        foreach ($rows as $r) {
            $firstTime = strtotime((string)$r['first_replacement_at']);
            $lastTime = strtotime((string)$r['last_replacement_at']);
            $daysElapsed = max(0, (int)round(abs($lastTime - $firstTime) / 86400));
            $count = (int)$r['replacements_count'];

            $alerts[] = [
                'machine_id'             => (int)$r['machine_id'],
                'machine_code'           => (string)$r['machine_code'],
                'machine_model'          => (string)$r['machine_model'],
                'location_name'          => (string)$r['location_name'],
                'part_code'              => (string)$r['part_code'],
                'part_name'              => (string)$r['part_name'],
                'replacements_in_period' => $count,
                'threshold'              => $threshold,
                'first_replacement_at'   => (string)$r['first_replacement_at'],
                'last_replacement_at'    => (string)$r['last_replacement_at'],
                'severity'               => ($count >= 5) ? 'CRITICAL' : 'WARNING',
                'warning_message'        => sprintf(
                    "Componente con Fallo Recurrente / Prematuro (%d sustituciones en %d días)",
                    $count,
                    $daysElapsed
                ),
            ];
        }

        return $alerts;
    }

    /**
     * {@inheritdoc}
     */
    public function findAllForExport(?int $periodDays = null): array
    {
        $sql = "
            SELECT 
                irp.installed_at AS fecha,
                COALESCE(inc.ticket_code, po.order_code, 'N/A') AS codigo_intervencion,
                irp.intervention_type AS tipo_intervencion,
                m.code AS codigo_maquina,
                m.model AS modelo_maquina,
                loc.name AS sede,
                COALESCE(sp.part_code, 'OUT_OF_CATALOG') AS codigo_pieza,
                COALESCE(sp.name, irp.custom_part_name, 'Pieza fuera de catálogo') AS nombre_pieza,
                COALESCE(sp.category, 'OTHER') AS categoria,
                irp.quantity AS unidades,
                irp.unit_cost_snapshot AS coste_unitario_eur,
                irp.total_cost_snapshot AS coste_total_eur,
                irp.old_part_destination AS destino_retirado,
                COALESCE(u.operator_code, u.name, 'N/A') AS codigo_tecnico,
                COALESCE(irp.notes, '') AS notas
            FROM `incident_replaced_parts` irp
            LEFT JOIN `incidents` inc ON inc.id = irp.incident_id
            LEFT JOIN `preventive_orders` po ON po.id = irp.preventive_order_id
            INNER JOIN `machines` m ON m.id = irp.machine_id
            INNER JOIN `locations` loc ON loc.id = irp.location_id
            LEFT JOIN `users` u ON u.id = irp.technician_id
            LEFT JOIN `spare_parts` sp ON sp.id = irp.spare_part_id
            WHERE 1 = 1
        ";
        $params = [];

        if ($periodDays !== null && $periodDays > 0) {
            $sql .= " AND irp.installed_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)";
            $params[':period_days'] = $periodDays;
        }

        $sql .= " ORDER BY irp.installed_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ejecuta la inserción individual de un registro de pieza sustituida.
     *
     * @param IncidentReplacedPart $part
     * @return IncidentReplacedPart
     */
    private function insertSingle(IncidentReplacedPart $part): IncidentReplacedPart
    {
        $sql = "
            INSERT INTO `incident_replaced_parts` (
                `intervention_type`,
                `incident_id`,
                `preventive_order_id`,
                `machine_id`,
                `location_id`,
                `technician_id`,
                `spare_part_id`,
                `is_out_of_catalog`,
                `custom_part_name`,
                `quantity`,
                `unit_cost_snapshot`,
                `old_part_destination`,
                `notes`,
                `installed_at`,
                `created_at`
            ) VALUES (
                :intervention_type,
                :incident_id,
                :preventive_order_id,
                :machine_id,
                :location_id,
                :technician_id,
                :spare_part_id,
                :is_out_of_catalog,
                :custom_part_name,
                :quantity,
                :unit_cost_snapshot,
                :old_part_destination,
                :notes,
                :installed_at,
                NOW()
            )
        ";

        try {
            $installedAt = !empty($part->getInstalledAt()) ? $part->getInstalledAt() : date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':intervention_type'    => $part->getInterventionType(),
                ':incident_id'          => $part->getIncidentId(),
                ':preventive_order_id'  => $part->getPreventiveOrderId(),
                ':machine_id'           => $part->getMachineId(),
                ':location_id'          => $part->getLocationId(),
                ':technician_id'        => $part->getTechnicianId(),
                ':spare_part_id'        => $part->getSparePartId(),
                ':is_out_of_catalog'    => $part->isOutOfCatalog() ? 1 : 0,
                ':custom_part_name'     => $part->getCustomPartName(),
                ':quantity'             => $part->getQuantity(),
                ':unit_cost_snapshot'   => $part->getUnitCostSnapshot(),
                ':old_part_destination' => $part->getOldPartDestination()->value,
                ':notes'                => $part->getNotes(),
                ':installed_at'         => $installedAt,
            ]);

            $id = (int)$this->pdo->lastInsertId();

            return $this->findById($id) ?? new IncidentReplacedPart(
                $id,
                $part->getInterventionType(),
                $part->getIncidentId(),
                $part->getPreventiveOrderId(),
                $part->getMachineId(),
                $part->getLocationId(),
                $part->getTechnicianId(),
                $part->getSparePartId(),
                $part->isOutOfCatalog(),
                $part->getCustomPartName(),
                $part->getQuantity(),
                $part->getUnitCostSnapshot(),
                $part->getOldPartDestination(),
                $part->getNotes(),
                $installedAt,
                date('Y-m-d H:i:s'),
                $part->getPartCode(),
                $part->getPartName(),
                $part->getMachineCode(),
                $part->getMachineModel(),
                $part->getLocationName(),
                $part->getTechnicianName()
            );
        } catch (PDOException $e) {
            throw new RuntimeException("Error al insertar repuesto sustituido: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Hidrata una entidad IncidentReplacedPart desde una fila de BD.
     *
     * @param array<string, mixed> $row
     * @return IncidentReplacedPart
     */
    private function hydrateReplacedPart(array $row): IncidentReplacedPart
    {
        return new IncidentReplacedPart(
            (int)$row['id'],
            (string)$row['intervention_type'],
            $row['incident_id'] !== null ? (int)$row['incident_id'] : null,
            $row['preventive_order_id'] !== null ? (int)$row['preventive_order_id'] : null,
            (int)$row['machine_id'],
            (int)$row['location_id'],
            (int)$row['technician_id'],
            $row['spare_part_id'] !== null ? (int)$row['spare_part_id'] : null,
            (bool)$row['is_out_of_catalog'],
            $row['custom_part_name'] !== null ? (string)$row['custom_part_name'] : null,
            (int)$row['quantity'],
            (float)$row['unit_cost_snapshot'],
            OldPartDestination::from((string)$row['old_part_destination']),
            $row['notes'] !== null ? (string)$row['notes'] : null,
            (string)$row['installed_at'],
            (string)$row['created_at'],
            $row['part_code'] !== null ? (string)$row['part_code'] : null,
            $row['part_name'] !== null ? (string)$row['part_name'] : null,
            $row['machine_code'] !== null ? (string)$row['machine_code'] : null,
            $row['machine_model'] !== null ? (string)$row['machine_model'] : null,
            $row['location_name'] !== null ? (string)$row['location_name'] : null,
            $row['technician_name'] !== null ? (string)$row['technician_name'] : null
        );
    }
}
