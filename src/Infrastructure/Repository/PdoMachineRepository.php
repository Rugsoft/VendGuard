<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\Machine;
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
     * @return Machine|null
     */
    public function findById(int $id, bool $withIncident = true): ?Machine
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
              AND m.`deleted_at` IS NULL
            LIMIT 1
        ";

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
     * @return Machine|null
     */
    public function findByCode(string $code, bool $withIncident = true): ?Machine
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
              AND m.`deleted_at` IS NULL
            LIMIT 1
        ";

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
     * Aplica borrado lógico marcando deleted_at = NOW() (RNF-03).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `machines`
            SET `deleted_at` = CURRENT_TIMESTAMP
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
            SET `deleted_at` = NULL
            WHERE `id` = :id
              AND `deleted_at` IS NULL
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
