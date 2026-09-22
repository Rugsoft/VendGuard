<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoUserRepository
 * 
 * Implementación PDO para el acceso y autenticación de usuarios internos.
 * Utiliza sentencias preparadas nativas y filtrado de soft delete (RF-04 / RNF-03).
 */
class PdoUserRepository implements UserRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Recupera un usuario por su dirección de correo electrónico.
     *
     * @param string $email
     * @param bool $onlyActive
     * @return User|null
     */
    public function findByEmail(string $email, bool $onlyActive = true): ?User
    {
        $normalizedEmail = strtolower(trim($email));

        $sql = "
            SELECT 
                `id`, 
                `name`, 
                `email`, 
                `password_hash`, 
                `role`, 
                `phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `users`
            WHERE `email` = :email
              AND `deleted_at` IS NULL
        ";

        if ($onlyActive) {
            $sql .= " AND `is_active` = 1";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $normalizedEmail]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return User::fromDatabaseRow($row);
    }

    /**
     * Recupera un usuario por su identificador primario.
     *
     * @param int $id
     * @param bool $onlyActive
     * @return User|null
     */
    public function findById(int $id, bool $onlyActive = true): ?User
    {
        $sql = "
            SELECT 
                `id`, 
                `name`, 
                `email`, 
                `password_hash`, 
                `role`, 
                `phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `users`
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        if ($onlyActive) {
            $sql .= " AND `is_active` = 1";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return User::fromDatabaseRow($row);
    }

    /**
     * Obtiene la lista de todos los técnicos de ruta activos para la asignación de partes.
     *
     * @param bool $onlyActive
     * @return array<User>
     */
    public function findAllTechnicians(bool $onlyActive = true): array
    {
        $sql = "
            SELECT 
                `id`, 
                `name`, 
                `email`, 
                `password_hash`, 
                `role`, 
                `phone`, 
                `is_active`, 
                `created_at`, 
                `updated_at`, 
                `deleted_at`
            FROM `users`
            WHERE `role` = 'TECHNICIAN'
              AND `deleted_at` IS NULL
        ";

        if ($onlyActive) {
            $sql .= " AND `is_active` = 1";
        }

        $sql .= " ORDER BY `name` ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => User::fromDatabaseRow($row), $rows);
    }

    /**
     * Aplica borrado lógico marcando deleted_at = CURRENT_TIMESTAMP (RNF-03).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `users`
            SET `deleted_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restaura un usuario previamente borrado lógicamente.
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE `users`
            SET `deleted_at` = NULL
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
