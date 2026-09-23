<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineRepository
 * 
 * Implementación nativa con PDO para la gestión del parque de máquinas dispensadoras.
 * Enriquecimiento automático de estado de aviso activo / ventana de garantía (RF-01, RF-02).
 */
class PdoMachineRepository implements MachineRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Recupera el catálogo de máquinas activas de una sede concreta,
     * incorporando para cada máquina la información del ticket activo o en garantía si existe.
     *
     * @param int $locationId Identificador de la sede.
     * @return array<Machine>
     */
    public function findActiveByLocationId(int $locationId): array
    {
        $sql = "
            SELECT 
                m.`id`,
                m.`location_id`,
                m.`code`,
                m.`model`,
                m.`machine_type`,
                m.`floor_wing`,
                m.`notes`,
                m.`is_active`,
                m.`created_at`,
                m.`updated_at`,
                m.`deleted_at`,
                i.`id` AS inc_id,
                i.`ticket_code` AS inc_ticket_code,
                i.`status` AS inc_status,
                i.`category` AS inc_category,
                i.`urgency` AS inc_urgency,
                i.`created_at` AS inc_created_at,
                i.`resolved_at` AS inc_resolved_at
            FROM `machines` m
            LEFT JOIN `incidents` i ON i.`machine_id` = m.`id`
                AND i.`deleted_at` IS NULL
                AND i.`is_active_ticket` = 1
            WHERE m.`location_id` = :location_id
              AND m.`deleted_at` IS NULL
              AND m.`is_active` = 1
            ORDER BY m.`code` ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);
        $rows = $stmt->fetchAll();

        $machines = [];
        foreach ($rows as $row) {
            $activeIncident = $this->extractActiveIncident($row);
            $machines[] = Machine::fromDatabaseRow($row, $activeIncident);
        }

        return $machines;
    }

    /**
     * Recupera una máquina por su ID numérico.
     *
     * @param int $id
     * @param bool $withIncident
     * @param bool $allowDeleted
     * @return Machine|null
     */
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        $sql = "
            SELECT 
                m.`id`,
                m.`location_id`,
                m.`code`,
                m.`model`,
                m.`machine_type`,
                m.`floor_wing`,
                m.`notes`,
                m.`is_active`,
                m.`created_at`,
                m.`updated_at`,
                m.`deleted_at`,
                i.`id` AS inc_id,
                i.`ticket_code` AS inc_ticket_code,
                i.`status` AS inc_status,
                i.`category` AS inc_category,
                i.`urgency` AS inc_urgency,
                i.`created_at` AS inc_created_at,
                i.`resolved_at` AS inc_resolved_at
            FROM `machines` m
            LEFT JOIN `incidents` i ON i.`machine_id` = m.`id`
                AND i.`deleted_at` IS NULL
                AND i.`is_active_ticket` = 1
            WHERE m.`id` = :id
        ";

        if (!$allowDeleted) {
            $sql .= " AND m.`deleted_at` IS NULL";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $activeIncident = $withIncident ? $this->extractActiveIncident($row) : null;
        return Machine::fromDatabaseRow($row, $activeIncident);
    }

    /**
     * Recupera una máquina por su código alfanumérico visible (ej: VEND-0101).
     *
     * @param string $code
     * @param bool $withIncident
     * @param bool $allowDeleted
     * @return Machine|null
     */
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        $normalized = strtoupper(trim($code));

        $sql = "
            SELECT 
                m.`id`,
                m.`location_id`,
                m.`code`,
                m.`model`,
                m.`machine_type`,
                m.`floor_wing`,
                m.`notes`,
                m.`is_active`,
                m.`created_at`,
                m.`updated_at`,
                m.`deleted_at`,
                i.`id` AS inc_id,
                i.`ticket_code` AS inc_ticket_code,
                i.`status` AS inc_status,
                i.`category` AS inc_category,
                i.`urgency` AS inc_urgency,
                i.`created_at` AS inc_created_at,
                i.`resolved_at` AS inc_resolved_at
            FROM `machines` m
            LEFT JOIN `incidents` i ON i.`machine_id` = m.`id`
                AND i.`deleted_at` IS NULL
                AND i.`is_active_ticket` = 1
            WHERE m.`code` = :code
        ";

        if (!$allowDeleted) {
            $sql .= " AND m.`deleted_at` IS NULL";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $normalized]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $activeIncident = $withIncident ? $this->extractActiveIncident($row) : null;
        return Machine::fromDatabaseRow($row, $activeIncident);
    }

    /**
     * Da de alta una nueva máquina dispensadora en una sede activa (RF-02, EARS 2.1).
     *
     * @param array<string, mixed> $data
     * @return Machine
     * @throws RuntimeException
     */
    public function create(array $data): Machine
    {
        $sql = "
            INSERT INTO `machines` (
                `location_id`,
                `code`,
                `model`,
                `machine_type`,
                `floor_wing`,
                `notes`,
                `is_active`,
                `created_at`,
                `updated_at`,
                `deleted_at`
            ) VALUES (
                :location_id,
                :code,
                :model,
                :machine_type,
                :floor_wing,
                :notes,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':location_id'  => (int)$data['location_id'],
            ':code'         => strtoupper(trim((string)$data['code'])),
            ':model'        => trim((string)$data['model']),
            ':machine_type' => trim((string)$data['machine_type']),
            ':floor_wing'   => trim((string)$data['floor_wing']),
            ':notes'        => isset($data['notes']) && $data['notes'] !== '' ? trim((string)$data['notes']) : null,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $created = $this->findById($newId, false, true);
        if ($created === null) {
            throw new RuntimeException("Error al recuperar la máquina recién creada con ID {$newId}");
        }

        return $created;
    }

    /**
     * Actualiza modelo, tipología sanitaria, ubicación física interna y notas (RF-02, EARS 2.3).
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('model', $data)) {
            $fields[] = "`model` = :model";
            $params[':model'] = trim((string)$data['model']);
        }
        if (array_key_exists('machine_type', $data)) {
            $fields[] = "`machine_type` = :machine_type";
            $params[':machine_type'] = trim((string)$data['machine_type']);
        }
        if (array_key_exists('floor_wing', $data)) {
            $fields[] = "`floor_wing` = :floor_wing";
            $params[':floor_wing'] = trim((string)$data['floor_wing']);
        }
        if (array_key_exists('notes', $data)) {
            $fields[] = "`notes` = :notes";
            $params[':notes'] = $data['notes'] !== null && $data['notes'] !== '' ? trim((string)$data['notes']) : null;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = CURRENT_TIMESTAMP";

        $sql = "UPDATE `machines` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Traslada una máquina operativa a otra sede cliente activa (RF-02, EARS 2.7).
     *
     * @param int $id
     * @param int $targetLocationId
     * @param string $floorWing
     * @param string|null $notes
     * @return bool
     */
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        $sql = "
            UPDATE `machines`
            SET `location_id` = :target_location_id,
                `floor_wing` = :floor_wing,
                `notes` = COALESCE(:notes, `notes`),
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'                 => $id,
            ':target_location_id' => $targetLocationId,
            ':floor_wing'         => trim($floorWing),
            ':notes'              => $notes !== null ? trim($notes) : null,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reactiva una máquina dada de baja, reubicándola si la sede original está inactiva (RF-02, EARS 2.12).
     *
     * @param int $id
     * @param int|null $newLocationId
     * @param string|null $newFloorWing
     * @return bool
     */
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        if ($newLocationId !== null && $newFloorWing !== null) {
            $sql = "
                UPDATE `machines`
                SET `is_active` = 1,
                    `deleted_at` = NULL,
                    `location_id` = :location_id,
                    `floor_wing` = :floor_wing,
                    `updated_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id'          => $id,
                ':location_id' => $newLocationId,
                ':floor_wing'  => trim($newFloorWing),
            ]);
        } else {
            $sql = "
                UPDATE `machines`
                SET `is_active` = 1,
                    `deleted_at` = NULL,
                    `updated_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Recupera el catálogo integral de máquinas con filtros de estado, sede, tipología y búsqueda (RF-02, EARS 2.2).
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT 
                m.`id`,
                m.`location_id`,
                m.`code`,
                m.`model`,
                m.`machine_type`,
                m.`floor_wing`,
                m.`notes`,
                m.`is_active`,
                m.`created_at`,
                m.`updated_at`,
                m.`deleted_at`,
                l.`name` AS location_name,
                l.`site_code` AS location_site_code,
                i.`ticket_code` AS inc_ticket_code,
                i.`status` AS inc_status,
                i.`resolved_at` AS inc_resolved_at
            FROM `machines` m
            LEFT JOIN `locations` l ON m.`location_id` = l.`id`
            LEFT JOIN `incidents` i ON i.`machine_id` = m.`id`
                AND i.`deleted_at` IS NULL
                AND i.`is_active_ticket` = 1
            WHERE 1=1
        ";

        $params = [];
        $status = $filters['status'] ?? 'all';

        if ($status === 'active') {
            $sql .= " AND m.`is_active` = 1 AND m.`deleted_at` IS NULL";
        } elseif ($status === 'inactive') {
            $sql .= " AND (m.`is_active` = 0 OR m.`deleted_at` IS NOT NULL)";
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND m.`location_id` = :location_id";
            $params[':location_id'] = (int)$filters['location_id'];
        }

        if (!empty($filters['machine_type'])) {
            $sql .= " AND m.`machine_type` = :machine_type";
            $params[':machine_type'] = trim((string)$filters['machine_type']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (
                m.`code` LIKE :s1 
                OR m.`model` LIKE :s2 
                OR m.`floor_wing` LIKE :s3 
                OR m.`notes` LIKE :s4 
                OR l.`name` LIKE :s5
            )";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
            $params[':s5'] = $term;
        }

        $sql .= " ORDER BY m.`code` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $mType = MachineType::tryFrom((string)$row['machine_type']);
            $hasActiveTicket = !empty($row['inc_ticket_code']);
            $activeTicketCode = $hasActiveTicket ? (string)$row['inc_ticket_code'] : null;
            $activeTicketStatus = $hasActiveTicket ? (string)$row['inc_status'] : null;

            $isInWarranty = false;
            if ($activeTicketStatus === 'RESOLVED') {
                $resolvedTime = !empty($row['inc_resolved_at']) ? strtotime((string)$row['inc_resolved_at']) : time();
                $hours = (time() - $resolvedTime) / 3600.0;
                $isInWarranty = ($hours <= 48.0);
            }

            return [
                'id'                   => (int)$row['id'],
                'code'                 => (string)$row['code'],
                'model'                => (string)$row['model'],
                'machine_type'         => (string)$row['machine_type'],
                'machine_type_label'   => $mType !== null ? $mType->label() : (string)$row['machine_type'],
                'is_perishable'        => $mType !== null ? $mType->isPerishable() : false,
                'location_id'          => (int)$row['location_id'],
                'location_name'        => (string)($row['location_name'] ?? ''),
                'location_site_code'   => (string)($row['location_site_code'] ?? ''),
                'floor_wing'           => (string)$row['floor_wing'],
                'notes'                => $row['notes'] !== null ? (string)$row['notes'] : null,
                'is_active'            => (bool)$row['is_active'] && $row['deleted_at'] === null,
                'has_active_ticket'    => $hasActiveTicket,
                'active_ticket_code'   => $activeTicketCode,
                'active_ticket_status' => $activeTicketStatus,
                'is_in_warranty'       => $isInWarranty,
                'created_at'           => (string)$row['created_at'],
                'updated_at'           => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
                'deleted_at'           => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            ];
        }, $rows);
    }

    /**
     * Comprueba de forma atómica si una máquina tiene tickets activos o en ventana de garantía de 48h (Art. V.6).
     *
     * @param int $machineId
     * @return bool
     */
    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return $this->getActiveTicketOrWarranty($machineId) !== null;
    }

    /**
     * Obtiene el ticket activo o en garantía de 48h de una máquina si existe.
     *
     * @param int $machineId
     * @return array<string, string>|null ['ticket_code' => string, 'status' => string]
     */
    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        $sql = "
            SELECT `ticket_code`, `status`, `resolved_at`
            FROM `incidents`
            WHERE `machine_id` = :machine_id
              AND `deleted_at` IS NULL
              AND `is_active_ticket` = 1
            ORDER BY `id` DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':machine_id' => $machineId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $status = (string)$row['status'];
        if ($status === 'RESOLVED') {
            $resolvedAt = !empty($row['resolved_at']) ? strtotime((string)$row['resolved_at']) : time();
            $hours = (time() - $resolvedAt) / 3600.0;
            if ($hours > 48.0) {
                return null;
            }
        }

        return [
            'ticket_code' => (string)$row['ticket_code'],
            'status'      => $status,
        ];
    }

    /**
     * Aplica borrado lógico marcando is_active = 0 y deleted_at = CURRENT_TIMESTAMP (RF-02, RNF-03, Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `machines`
            SET `is_active` = 0,
                `deleted_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restaura una máquina eliminada lógicamente.
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE `machines`
            SET `is_active` = 1,
                `deleted_at` = NULL,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NOT NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Extrae y estructura el sub-objeto de aviso activo o en garantía.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function extractActiveIncident(array $row): ?array
    {
        if (empty($row['inc_ticket_code'])) {
            return null;
        }

        $status = (string)$row['inc_status'];
        $isInWarranty = false;

        if ($status === 'RESOLVED') {
            $resolvedTime = !empty($row['inc_resolved_at'])
                ? strtotime((string)$row['inc_resolved_at'])
                : time();
            $hours = (time() - $resolvedTime) / 3600.0;
            $isInWarranty = ($hours <= 48.0);
        }

        return [
            'id'             => (int)$row['inc_id'],
            'ticket_code'    => (string)$row['inc_ticket_code'],
            'status'         => $status,
            'category'       => (string)$row['inc_category'],
            'urgency'        => (string)$row['inc_urgency'],
            'created_at'     => (string)$row['inc_created_at'],
            'resolved_at'    => $row['inc_resolved_at'] !== null ? (string)$row['inc_resolved_at'] : null,
            'is_in_warranty' => $isInWarranty,
        ];
    }
}
