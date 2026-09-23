<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoUserRepository
 * 
 * Implementación PDO para el acceso y autenticación de usuarios internos.
 * Utiliza sentencias preparadas nativas y filtrado de soft delete (RF-03, RF-04 / RNF-03).
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
     * @param bool $allowDeleted
     * @return User|null
     */
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
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
        ";

        if (!$allowDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

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
     * @param bool $allowDeleted
     * @return User|null
     */
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
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
        ";

        if (!$allowDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

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
     * Da de alta a un nuevo usuario interno en el sistema con credenciales Bcrypt (RF-03, EARS 3.1).
     *
     * @param array<string, mixed> $data
     * @return User
     * @throws RuntimeException
     */
    public function create(array $data): User
    {
        $passwordHash = isset($data['password_hash']) && !empty($data['password_hash'])
            ? (string)$data['password_hash']
            : password_hash((string)($data['password'] ?? ''), PASSWORD_BCRYPT, ['cost' => 10]);

        $sql = "
            INSERT INTO `users` (
                `name`,
                `email`,
                `password_hash`,
                `role`,
                `phone`,
                `is_active`,
                `created_at`,
                `updated_at`,
                `deleted_at`
            ) VALUES (
                :name,
                :email,
                :password_hash,
                :role,
                :phone,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':name'          => trim((string)$data['name']),
            ':email'         => strtolower(trim((string)$data['email'])),
            ':password_hash' => $passwordHash,
            ':role'          => strtoupper(trim((string)$data['role'])),
            ':phone'         => isset($data['phone']) && $data['phone'] !== '' ? trim((string)$data['phone']) : null,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $created = $this->findById($newId, false, true);
        if ($created === null) {
            throw new RuntimeException("Error al recuperar el usuario recién creado con ID {$newId}");
        }

        return $created;
    }

    /**
     * Actualiza los datos personales y de contacto de un usuario (RF-03, EARS 3.3).
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
        if (array_key_exists('phone', $data)) {
            $fields[] = "`phone` = :phone";
            $params[':phone'] = $data['phone'] !== null && $data['phone'] !== '' ? trim((string)$data['phone']) : null;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = CURRENT_TIMESTAMP";

        $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Restablece la contraseña de acceso de un usuario cifrándola en Bcrypt (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(int $id, string $newPassword): bool
    {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        $sql = "
            UPDATE `users`
            SET `password_hash` = :password_hash,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':id'            => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Alias de updatePassword para compatibilidad con nomenclatura del plan (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        return $this->updatePassword($id, $newPassword);
    }

    /**
     * Aplica borrado lógico marcando is_active = 0 y deleted_at = CURRENT_TIMESTAMP (RF-03, RNF-03, Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `users`
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
     * Restaura un usuario previamente borrado lógicamente (RF-03, EARS 3.6).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE `users`
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
     * Recupera el listado del personal con filtros por rol, estado y recuento de averías asignadas (RF-03, EARS 3.2).
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT 
                u.`id`,
                u.`name`,
                u.`email`,
                u.`role`,
                u.`phone`,
                u.`is_active`,
                u.`created_at`,
                u.`updated_at`,
                u.`deleted_at`,
                COALESCE(inc.active_count, 0) AS `active_assigned_incidents_count`
            FROM `users` u
            LEFT JOIN (
                SELECT `assigned_technician_id`, COUNT(*) as `active_count`
                FROM `incidents`
                WHERE `deleted_at` IS NULL
                  AND `is_active_ticket` = 1
                GROUP BY `assigned_technician_id`
            ) inc ON inc.`assigned_technician_id` = u.`id`
            WHERE 1=1
        ";

        $params = [];
        $status = $filters['status'] ?? 'all';

        if ($status === 'active') {
            $sql .= " AND u.`is_active` = 1 AND u.`deleted_at` IS NULL";
        } elseif ($status === 'inactive') {
            $sql .= " AND (u.`is_active` = 0 OR u.`deleted_at` IS NOT NULL)";
        }

        if (!empty($filters['role']) && $filters['role'] !== 'all') {
            $sql .= " AND u.`role` = :role";
            $params[':role'] = strtoupper(trim((string)$filters['role']));
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (
                u.`name` LIKE :s1
                OR u.`email` LIKE :s2
                OR u.`phone` LIKE :s3
            )";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
        }

        $sql .= " ORDER BY u.`name` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $roleEnum = UserRole::tryFrom((string)$row['role']);

            return [
                'id'                              => (int)$row['id'],
                'name'                            => (string)$row['name'],
                'email'                           => (string)$row['email'],
                'role'                            => (string)$row['role'],
                'role_label'                      => $roleEnum !== null ? $roleEnum->label() : (string)$row['role'],
                'phone'                           => $row['phone'] !== null ? (string)$row['phone'] : null,
                'is_active'                       => (bool)$row['is_active'] && $row['deleted_at'] === null,
                'active_assigned_incidents_count' => (int)$row['active_assigned_incidents_count'],
                'created_at'                      => (string)$row['created_at'],
                'updated_at'                      => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
                'deleted_at'                      => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            ];
        }, $rows);
    }

    /**
     * Cuenta el número de usuarios activos para un rol específico (Guardia Mínima Operativa).
     *
     * @param UserRole|string $role
     * @return int
     */
    public function countActiveByRole(UserRole|string $role): int
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtoupper(trim($role));

        $sql = "
            SELECT COUNT(*) 
            FROM `users`
            WHERE `role` = :role
              AND `is_active` = 1
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role' => $roleValue]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Cuenta las incidencias activas asignadas a un técnico (bloqueo de baja técnica).
     *
     * @param int $userId
     * @return int
     */
    public function countActiveAssignedIncidents(int $userId): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM `incidents`
            WHERE `assigned_technician_id` = :user_id
              AND `deleted_at` IS NULL
              AND `is_active_ticket` = 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Alias de countActiveAssignedIncidents para compatibilidad con nomenclatura del plan.
     *
     * @param int $technicianId
     * @return int
     */
    public function countPendingIncidents(int $technicianId): int
    {
        return $this->countActiveAssignedIncidents($technicianId);
    }
}
