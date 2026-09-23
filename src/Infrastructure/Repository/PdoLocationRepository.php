<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoLocationRepository
 * 
 * Implementación nativa con PDO de la persistencia de Sedes (locations) en MariaDB.
 * Cumple estrictamente con el Dogma Vanilla y el mandato constitucional de Soft Delete (Art. III.1).
 */
class PdoLocationRepository implements LocationRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Recupera una sede por su código alfanumérico único.
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @param bool $onlyActive Si es true, exige además is_active = 1.
     * @param bool $allowDeleted Si es true, permite recuperar registros con deleted_at IS NOT NULL.
     * @return Location|null
     */
    public function findBySiteCode(string $siteCode, bool $onlyActive = true, bool $allowDeleted = false): ?Location
    {
        $normalizedCode = strtoupper(trim($siteCode));

        $sql = "
            SELECT 
                `id`, 
                `site_code`, 
                `name`, 
                `address`, 
                `contact_name`, 
                `contact_phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `locations`
            WHERE `site_code` = :site_code
        ";

        if (!$allowDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        if ($onlyActive) {
            $sql .= " AND `is_active` = 1";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':site_code' => $normalizedCode]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Location::fromDatabaseRow($row);
    }

    /**
     * Recupera una sede por su identificador primario.
     *
     * @param int $id Identificador numérico de sede.
     * @param bool $allowDeleted Si es true, permite recuperar sedes dadas de baja lógica.
     * @return Location|null
     */
    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        $sql = "
            SELECT 
                `id`, 
                `site_code`, 
                `name`, 
                `address`, 
                `contact_name`, 
                `contact_phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `locations`
            WHERE `id` = :id
        ";

        if (!$allowDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Location::fromDatabaseRow($row);
    }

    /**
     * Lista todas las sedes activas y no borradas lógicamente del sistema.
     *
     * @return array<Location>
     */
    public function findAllActive(): array
    {
        $sql = "
            SELECT 
                `id`, 
                `site_code`, 
                `name`, 
                `address`, 
                `contact_name`, 
                `contact_phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `locations`
            WHERE `deleted_at` IS NULL
              AND `is_active` = 1
            ORDER BY `site_code` ASC
        ";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => Location::fromDatabaseRow($row), $rows);
    }

    /**
     * Lista todas las sedes con filtros de estado, búsqueda y conteo de máquinas (RF-01, EARS 1.1).
     *
     * @param string $status 'active', 'inactive' o 'all'.
     * @param string|null $search Búsqueda de texto parcial.
     * @return array<array<string, mixed>>
     */
    public function findAll(string $status = 'all', ?string $search = null): array
    {
        $sql = "
            SELECT 
                l.`id`, 
                l.`site_code`, 
                l.`name`, 
                l.`address`, 
                l.`contact_name`, 
                l.`contact_phone`, 
                l.`is_active`, 
                l.`created_at`, 
                l.`updated_at`, 
                l.`deleted_at`,
                COALESCE(m_active.active_count, 0) AS `active_machines_count`,
                COALESCE(m_total.total_count, 0) AS `total_machines_count`
            FROM `locations` l
            LEFT JOIN (
                SELECT `location_id`, COUNT(*) as `active_count`
                FROM `machines`
                WHERE `is_active` = 1 AND `deleted_at` IS NULL
                GROUP BY `location_id`
            ) m_active ON l.`id` = m_active.`location_id`
            LEFT JOIN (
                SELECT `location_id`, COUNT(*) as `total_count`
                FROM `machines`
                GROUP BY `location_id`
            ) m_total ON l.`id` = m_total.`location_id`
            WHERE 1=1
        ";

        $params = [];

        if ($status === 'active') {
            $sql .= " AND l.`is_active` = 1 AND l.`deleted_at` IS NULL";
        } elseif ($status === 'inactive') {
            $sql .= " AND (l.`is_active` = 0 OR l.`deleted_at` IS NOT NULL)";
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $sql .= " AND (
                l.`site_code` LIKE :s1 
                OR l.`name` LIKE :s2 
                OR l.`address` LIKE :s3 
                OR l.`contact_name` LIKE :s4
            )";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
        }

        $sql .= " ORDER BY l.`site_code` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'site_code' => (string)$row['site_code'],
            'name' => (string)$row['name'],
            'address' => (string)$row['address'],
            'contact_name' => $row['contact_name'] !== null ? (string)$row['contact_name'] : null,
            'contact_phone' => $row['contact_phone'] !== null ? (string)$row['contact_phone'] : null,
            'is_active' => (bool)$row['is_active'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            'deleted_at' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            'active_machines_count' => (int)$row['active_machines_count'],
            'total_machines_count' => (int)$row['total_machines_count'],
        ], $rows);
    }

    /**
     * Da de alta una nueva sede cliente con código único (RF-01, EARS 1.2).
     *
     * @param array<string, mixed> $data
     * @return Location
     * @throws RuntimeException
     */
    public function create(array $data): Location
    {
        $sql = "
            INSERT INTO `locations` (
                `site_code`,
                `name`,
                `address`,
                `contact_name`,
                `contact_phone`,
                `is_active`,
                `created_at`,
                `updated_at`,
                `deleted_at`
            ) VALUES (
                :site_code,
                :name,
                :address,
                :contact_name,
                :contact_phone,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':site_code' => strtoupper(trim((string)$data['site_code'])),
            ':name' => trim((string)$data['name']),
            ':address' => trim((string)$data['address']),
            ':contact_name' => isset($data['contact_name']) && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null,
            ':contact_phone' => isset($data['contact_phone']) && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $created = $this->findById($newId, true);
        if ($created === null) {
            throw new RuntimeException("Error al recuperar la sede recién creada con ID {$newId}");
        }

        return $created;
    }

    /**
     * Actualiza los datos descriptivos de una sede (RF-01, EARS 1.3).
     * El site_code es inmutable y no se modifica.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('name', $data)) {
            $fields[] = "`name` = :name";
            $params[':name'] = trim((string)$data['name']);
        }
        if (array_key_exists('address', $data)) {
            $fields[] = "`address` = :address";
            $params[':address'] = trim((string)$data['address']);
        }
        if (array_key_exists('contact_name', $data)) {
            $fields[] = "`contact_name` = :contact_name";
            $params[':contact_name'] = $data['contact_name'] !== null && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null;
        }
        if (array_key_exists('contact_phone', $data)) {
            $fields[] = "`contact_phone` = :contact_phone";
            $params[':contact_phone'] = $data['contact_phone'] !== null && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = CURRENT_TIMESTAMP";

        $sql = "UPDATE `locations` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Aplica borrado lógico (Soft Delete) marcando is_active = 0 y deleted_at = NOW().
     * Respeta la prohibición constitucional de eliminación física de registros (Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `locations`
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
     * Restaura una sede previamente dada de baja (fija is_active = 1 y deleted_at = NULL).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE `locations`
            SET `is_active` = 1,
                `deleted_at` = NULL,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Actualiza el teléfono de contacto maestro de una sede.
     */
    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        $sql = "
            UPDATE `locations`
            SET `contact_phone` = :contact_phone,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':contact_phone' => trim($contactPhone),
        ]);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Cuenta el número de máquinas activas vinculadas a una sede (RF-01, EARS 1.4).
     *
     * @param int $locationId
     * @return int
     */
    public function countActiveMachines(int $locationId): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM `machines` 
            WHERE `location_id` = :location_id 
              AND `is_active` = 1 
              AND `deleted_at` IS NULL
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Cuenta el total histórico de máquinas (activas e inactivas) de una sede.
     *
     * @param int $locationId
     * @return int
     */
    public function countTotalMachines(int $locationId): int
    {
        $sql = "SELECT COUNT(*) FROM `machines` WHERE `location_id` = :location_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);

        return (int)$stmt->fetchColumn();
    }
}
