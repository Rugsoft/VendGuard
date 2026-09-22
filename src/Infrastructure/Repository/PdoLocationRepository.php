<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoLocationRepository
 * 
 * Implementación nativa con PDO de la persistencia de Sedes (locations) en MariaDB.
 * Cumple estrictamente con el Dogma Vanilla y el mandato constitucional de Soft Delete (RNF-03).
 */
class PdoLocationRepository implements LocationRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Recupera una sede activa por su código alfanumérico único.
     * Devuelve null si no existe, si está inactiva o si ha sido borrada lógicamente.
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @param bool $onlyActive Si es true, exige además is_active = 1.
     * @return Location|null
     */
    public function findBySiteCode(string $siteCode, bool $onlyActive = true): ?Location
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
              AND `deleted_at` IS NULL
        ";

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
     * Recupera una sede activa por su identificador primario.
     *
     * @param int $id Identificador numérico de sede.
     * @return Location|null
     */
    public function findById(int $id): ?Location
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
              AND `deleted_at` IS NULL
            LIMIT 1
        ";

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
     * Aplica borrado lógico (Soft Delete) marcando deleted_at con la fecha/hora actual.
     * Respeta la prohibición constitucional de eliminación física de registros.
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `locations`
            SET `deleted_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restaura una sede previamente dada de baja por soft delete (fija deleted_at = NULL).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE `locations`
            SET `deleted_at` = NULL
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
