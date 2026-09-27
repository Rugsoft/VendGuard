<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Exception\PreventiveOrderAlreadyAssignedException;

/**
 * PdoPreventiveOrderRepository
 * 
 * Implementación PDO del repositorio de órdenes de inspección preventiva.
 * Gestiona el ciclo de vida de órdenes, visita oportunista atómica, y cancelaciones lógicas (Art. III).
 */
class PdoPreventiveOrderRepository implements PreventiveOrderRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): PreventiveOrder
    {
        $orderCode = $data['order_code'] ?? null;
        if (empty($orderCode)) {
            $stmtSeq = $this->pdo->query("SELECT COALESCE(MAX(`id`), 0) + 1 FROM `preventive_orders`");
            $nextId = (int)$stmtSeq->fetchColumn();
            $orderCode = sprintf('PREV-%s-%04d', date('Y'), $nextId);
        }

        $sql = "
            INSERT INTO `preventive_orders` (
                `order_code`,
                `machine_id`,
                `location_id`,
                `assigned_technician_id`,
                `status`,
                `order_type`,
                `scheduled_date`,
                `due_date`,
                `notes`,
                `created_at`,
                `updated_at`
            ) VALUES (
                :order_code,
                :machine_id,
                :location_id,
                :technician_id,
                :status,
                :order_type,
                :scheduled_date,
                :due_date,
                :notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";

        $status = $data['status'] ?? 'PENDING_ASSIGNMENT';
        $orderType = $data['order_type'] ?? 'ROUTINE';
        $scheduledDate = $data['scheduled_date'] ?? $data['due_date'];
        $dueDate = $data['due_date'];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':order_code' => $orderCode,
            ':machine_id' => $data['machine_id'],
            ':location_id' => $data['location_id'],
            ':technician_id' => $data['assigned_technician_id'] ?? null,
            ':status' => $status,
            ':order_type' => $orderType,
            ':scheduled_date' => $scheduledDate,
            ':due_date' => $dueDate,
            ':notes' => $data['notes'] ?? null,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $order = $this->findById($newId, true);
        if (!$order) {
            throw new \RuntimeException("Error al recuperar la orden preventiva recién creada.");
        }

        return $order;
    }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder
    {
        $sql = $this->getBaseSelectQuery() . " WHERE po.`id` = :id";
        if (!$allowCancelled) {
            $sql .= " AND po.`status` != 'CANCELLED' AND po.`deleted_at` IS NULL";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder
    {
        $sql = $this->getBaseSelectQuery() . " WHERE po.`order_code` = :order_code";
        if (!$allowCancelled) {
            $sql .= " AND po.`status` != 'CANCELLED' AND po.`deleted_at` IS NULL";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':order_code' => $orderCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    public function hasActiveOrPendingOrder(int $machineId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM `preventive_orders`
            WHERE `machine_id` = :machine_id
              AND `status` IN ('PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION')
              AND `deleted_at` IS NULL
        ");
        $stmt->execute([':machine_id' => $machineId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_orders`
            SET 
                `assigned_technician_id` = :technician_id,
                `scheduled_date` = :scheduled_date,
                `status` = 'SCHEDULED',
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `status` IN ('PENDING_ASSIGNMENT', 'SCHEDULED', 'EXPIRED')
              AND `deleted_at` IS NULL
        ");

        return $stmt->execute([
            ':technician_id' => $technicianId,
            ':scheduled_date' => $scheduledDate,
            ':id' => $orderId,
        ]);
    }

    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool
    {
        // Actualización atómica con guarda de estado PENDING_ASSIGNMENT
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_orders`
            SET 
                `assigned_technician_id` = :technician_id,
                `scheduled_date` = CURRENT_DATE(),
                `status` = 'SCHEDULED',
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `status` = 'PENDING_ASSIGNMENT'
              AND `deleted_at` IS NULL
        ");

        $stmt->execute([
            ':technician_id' => $technicianId,
            ':id' => $orderId,
        ]);

        if ($stmt->rowCount() === 0) {
            $stmtCheck = $this->pdo->prepare("
                SELECT `status`, `assigned_technician_id` 
                FROM `preventive_orders` 
                WHERE `id` = :id
            ");
            $stmtCheck->execute([':id' => $orderId]);
            $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            throw new PreventiveOrderAlreadyAssignedException(
                'La orden preventiva ya se encuentra asignada o ha cambiado de estado.',
                $orderId,
                $current['status'] ?? null,
                isset($current['assigned_technician_id']) ? (int)$current['assigned_technician_id'] : null
            );
        }

        return true;
    }

    public function startInspection(int $orderId, int $technicianId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_orders`
            SET 
                `status` = 'IN_INSPECTION',
                `started_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `assigned_technician_id` = :technician_id
              AND `status` IN ('SCHEDULED', 'EXPIRED')
              AND `deleted_at` IS NULL
        ");

        return $stmt->execute([
            ':technician_id' => $technicianId,
            ':id' => $orderId,
        ]);
    }

    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool {
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_orders`
            SET 
                `status` = 'COMPLETED',
                `result` = :result,
                `temperature_measured` = :temperature_measured,
                `is_quarantine_triggered` = :is_quarantine,
                `linked_incident_id` = :linked_incident_id,
                `notes` = COALESCE(:notes, `notes`),
                `completed_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ");

        return $stmt->execute([
            ':result' => $result,
            ':temperature_measured' => $temperatureMeasured,
            ':is_quarantine' => $isQuarantineTriggered ? 1 : 0,
            ':linked_incident_id' => $linkedIncidentId,
            ':notes' => $notes,
            ':id' => $orderId,
        ]);
    }

    public function softCancel(int $orderId, string $reason): bool
    {
        // Cancelación puramente lógica en estricto cumplimiento del Artículo III
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_orders`
            SET 
                `status` = 'CANCELLED',
                `cancellation_reason` = :reason,
                `deleted_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `status` != 'COMPLETED'
        ");

        return $stmt->execute([
            ':reason' => $reason,
            ':id' => $orderId,
        ]);
    }

    public function findForCoordinatorList(array $filters = []): array
    {
        $sql = $this->getBaseSelectQuery() . " WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND po.`status` = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['machine_id'])) {
            $sql .= " AND po.`machine_id` = :machine_id";
            $params[':machine_id'] = (int)$filters['machine_id'];
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND po.`location_id` = :location_id";
            $params[':location_id'] = (int)$filters['location_id'];
        }

        if (!empty($filters['technician_id'])) {
            $sql .= " AND po.`assigned_technician_id` = :technician_id";
            $params[':technician_id'] = (int)$filters['technician_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (po.`order_code` LIKE :search OR m.`code` LIKE :search OR l.`name` LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY po.`due_date` ASC, po.`id` DESC";

        if (isset($filters['limit'])) {
            $limit = (int)$filters['limit'];
            $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = $this->hydrateOrder($row);
        }

        return $orders;
    }

    public function countForCoordinatorList(array $filters = []): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM `preventive_orders` po
            INNER JOIN `machines` m ON po.`machine_id` = m.`id`
            INNER JOIN `locations` l ON po.`location_id` = l.`id`
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND po.`status` = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['machine_id'])) {
            $sql .= " AND po.`machine_id` = :machine_id";
            $params[':machine_id'] = (int)$filters['machine_id'];
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND po.`location_id` = :location_id";
            $params[':location_id'] = (int)$filters['location_id'];
        }

        if (!empty($filters['technician_id'])) {
            $sql .= " AND po.`assigned_technician_id` = :technician_id";
            $params[':technician_id'] = (int)$filters['technician_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (po.`order_code` LIKE :search OR m.`code` LIKE :search OR l.`name` LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array
    {
        $sql = $this->getBaseSelectQuery() . " WHERE (
            (po.`assigned_technician_id` = :tech_id AND po.`status` IN ('SCHEDULED', 'IN_INSPECTION', 'EXPIRED'))
        ";

        $params = [':tech_id' => $technicianId];

        // Si se provee la sede donde el técnico está in situ, incluir preventivos pendientes de claim (EARS 2.3)
        if ($locationId !== null) {
            $sql .= " OR (po.`location_id` = :loc_id AND po.`status` = 'PENDING_ASSIGNMENT' AND po.`deleted_at` IS NULL)";
            $params[':loc_id'] = $locationId;
        }

        $sql .= ") ORDER BY po.`due_date` ASC, po.`scheduled_date` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = $this->hydrateOrder($row);
        }

        return $orders;
    }

    public function getDashboardSummary(): array
    {
        // 1. Conteo de semáforos por estado sanitario de máquinas
        $stmtStatus = $this->pdo->query("
            SELECT 
                COUNT(*) AS `total`,
                SUM(CASE WHEN `sanitary_status` = 'OK' AND DATEDIFF(COALESCE(`next_sanitary_inspection_due`, CURRENT_DATE()), CURRENT_DATE()) > 5 THEN 1 ELSE 0 END) AS `green`,
                SUM(CASE WHEN `sanitary_status` = 'ATTENTION_REQUIRED' OR (`sanitary_status` = 'OK' AND DATEDIFF(COALESCE(`next_sanitary_inspection_due`, CURRENT_DATE()), CURRENT_DATE()) BETWEEN 0 AND 5) THEN 1 ELSE 0 END) AS `yellow`,
                SUM(CASE WHEN `sanitary_status` = 'EXPIRED' OR DATEDIFF(COALESCE(`next_sanitary_inspection_due`, CURRENT_DATE()), CURRENT_DATE()) < 0 THEN 1 ELSE 0 END) AS `red`,
                SUM(CASE WHEN `sanitary_status` = 'QUARANTINE' THEN 1 ELSE 0 END) AS `quarantine`,
                SUM(CASE WHEN `sanitary_status` = 'SEASONAL_PAUSE' THEN 1 ELSE 0 END) AS `seasonal_pause`
            FROM `machines`
            WHERE `deleted_at` IS NULL AND `is_active` = 1
        ");
        $counts = $stmtStatus->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Órdenes vencidas y próximas a vencer
        $stmtOrders = $this->pdo->query("
            SELECT
                SUM(CASE WHEN `status` = 'EXPIRED' OR (`status` IN ('PENDING_ASSIGNMENT', 'SCHEDULED') AND `due_date` < CURRENT_DATE()) THEN 1 ELSE 0 END) AS `expired_orders`,
                SUM(CASE WHEN `status` IN ('PENDING_ASSIGNMENT', 'SCHEDULED') AND `due_date` BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 5 DAY) THEN 1 ELSE 0 END) AS `due_soon_orders`
            FROM `preventive_orders`
            WHERE `deleted_at` IS NULL
        ");
        $orderCounts = $stmtOrders->fetch(PDO::FETCH_ASSOC) ?: [];

        // 3. Máquinas en cuarentena urgente
        $stmtQuarantine = $this->pdo->query("
            SELECT 
                m.`id` AS `machine_id`,
                m.`code` AS `machine_code`,
                m.`model`,
                m.`floor_wing`,
                l.`name` AS `location_name`,
                po.`order_code`,
                po.`temperature_measured`,
                po.`completed_at` AS `since`,
                inc.`ticket_code` AS `active_incident_code`
            FROM `machines` m
            INNER JOIN `locations` l ON m.`location_id` = l.`id`
            LEFT JOIN `preventive_orders` po ON m.`id` = po.`machine_id` AND po.`is_quarantine_triggered` = 1
            LEFT JOIN `incidents` inc ON inc.`machine_id` = m.`id` AND inc.`is_active_ticket` = 1
            WHERE m.`sanitary_status` = 'QUARANTINE' AND m.`deleted_at` IS NULL
            ORDER BY po.`completed_at` DESC
        ");
        $quarantineList = $stmtQuarantine->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = (int)($counts['total'] ?? 0);
        $green = (int)($counts['green'] ?? 0);
        $complianceRate = $total > 0 ? round(($green / $total) * 100, 1) : 100.0;

        return [
            'summary' => [
                'total_machines' => $total,
                'status_green' => $green,
                'status_yellow' => (int)($counts['yellow'] ?? 0),
                'status_red' => (int)($counts['red'] ?? 0),
                'status_quarantine' => (int)($counts['quarantine'] ?? 0),
                'status_seasonal_pause' => (int)($counts['seasonal_pause'] ?? 0),
                'compliance_rate_percent' => $complianceRate,
            ],
            'urgent_actions' => [
                'quarantine_machines' => $quarantineList,
                'expired_orders_count' => (int)($orderCounts['expired_orders'] ?? 0),
                'due_soon_orders_count' => (int)($orderCounts['due_soon_orders'] ?? 0),
            ],
        ];
    }

    private function getBaseSelectQuery(): string
    {
        return "
            SELECT 
                po.`id`,
                po.`order_code`,
                po.`machine_id`,
                po.`location_id`,
                po.`assigned_technician_id`,
                po.`status`,
                po.`order_type`,
                po.`scheduled_date`,
                po.`due_date`,
                po.`started_at`,
                po.`completed_at`,
                po.`temperature_measured`,
                po.`result`,
                po.`linked_incident_id`,
                po.`is_quarantine_triggered`,
                po.`notes`,
                po.`cancellation_reason`,
                po.`created_at`,
                po.`updated_at`,
                po.`deleted_at`,
                m.`code` AS `machine_code`,
                m.`model` AS `machine_model`,
                m.`machine_type`,
                m.`floor_wing` AS `machine_floor_wing`,
                m.`sanitary_status` AS `machine_sanitary_status`,
                l.`site_code` AS `location_site_code`,
                l.`name` AS `location_name`,
                l.`address` AS `location_address`,
                u.`name` AS `technician_name`,
                u.`operator_code` AS `technician_operator_code`
            FROM `preventive_orders` po
            INNER JOIN `machines` m ON po.`machine_id` = m.`id`
            INNER JOIN `locations` l ON po.`location_id` = l.`id`
            LEFT JOIN `users` u ON po.`assigned_technician_id` = u.`id`
        ";
    }

    /**
     * @param array<string, mixed> $row
     * @return PreventiveOrder
     */
    private function hydrateOrder(array $row): PreventiveOrder
    {
        $machineData = [
            'id' => (int)$row['machine_id'],
            'code' => (string)$row['machine_code'],
            'model' => (string)$row['machine_model'],
            'machine_type' => (string)$row['machine_type'],
            'floor_wing' => (string)$row['machine_floor_wing'],
            'sanitary_status' => (string)$row['machine_sanitary_status'],
        ];

        $locationData = [
            'id' => (int)$row['location_id'],
            'site_code' => (string)$row['location_site_code'],
            'name' => (string)$row['location_name'],
            'address' => (string)$row['location_address'],
        ];

        $technicianData = null;
        if (!empty($row['assigned_technician_id'])) {
            $technicianData = [
                'id' => (int)$row['assigned_technician_id'],
                'name' => (string)($row['technician_name'] ?? ''),
                'operator_code' => (string)($row['technician_operator_code'] ?? ''),
            ];
        }

        return new PreventiveOrder(
            (int)$row['id'],
            (string)$row['order_code'],
            (int)$row['machine_id'],
            (int)$row['location_id'],
            $row['assigned_technician_id'] !== null ? (int)$row['assigned_technician_id'] : null,
            (string)$row['status'],
            (string)$row['order_type'],
            (string)$row['scheduled_date'],
            (string)$row['due_date'],
            $row['started_at'],
            $row['completed_at'],
            $row['temperature_measured'] !== null ? (float)$row['temperature_measured'] : null,
            $row['result'],
            $row['linked_incident_id'] !== null ? (int)$row['linked_incident_id'] : null,
            (bool)$row['is_quarantine_triggered'],
            $row['notes'],
            $row['cancellation_reason'],
            $row['created_at'],
            $row['updated_at'],
            $row['deleted_at'],
            $machineData,
            $locationData,
            $technicianData
        );
    }
}
