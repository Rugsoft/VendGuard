<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\User;

/**
 * UserRepositoryInterface
 * 
 * Contrato de persistencia para el acceso y autenticación de usuarios internos.
 */
interface UserRepositoryInterface
{
    /**
     * Recupera un usuario por su dirección de correo electrónico.
     *
     * @param string $email
     * @param bool $onlyActive Si es true, exige is_active = 1.
     * @param bool $allowDeleted Si es true, permite recuperar usuarios dados de baja lógica.
     * @return User|null
     */
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User;

    /**
     * Recupera un usuario por su ID numérico.
     *
     * @param int $id
     * @param bool $onlyActive
     * @param bool $allowDeleted Si es true, permite recuperar usuarios dados de baja lógica.
     * @return User|null
     */
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User;

    /**
     * Obtiene la lista de todos los técnicos de ruta activos para la asignación de partes.
     *
     * @param bool $onlyActive
     * @return array<User>
     */
    public function findAllTechnicians(bool $onlyActive = true): array;

    /**
     * Da de alta a un nuevo usuario interno en el sistema con credenciales Bcrypt (RF-03, EARS 3.1).
     *
     * @param array<string, mixed> $data
     * @return User
     */
    public function create(array $data): User;

    /**
     * Actualiza los datos personales y de contacto de un usuario (RF-03, EARS 3.3).
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Restablece la contraseña de acceso de un usuario cifrándola en Bcrypt (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(int $id, string $newPassword): bool;

    /**
     * Alias de updatePassword para compatibilidad con nomenclatura del plan (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword(int $id, string $newPassword): bool;

    /**
     * Aplica borrado lógico marcando is_active = 0 y deleted_at = CURRENT_TIMESTAMP (RNF-03, Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;

    /**
     * Restaura un usuario previamente dado de baja lógica (RF-03, EARS 3.6).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool;

    /**
     * Recupera el listado del personal con filtros por rol, estado y recuento de averías asignadas (RF-03, EARS 3.2).
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findAll(array $filters = []): array;

    /**
     * Cuenta el número de usuarios activos para un rol específico (Guardia Mínima Operativa).
     *
     * @param \VendGuard\Core\Domain\Model\UserRole|string $role
     * @return int
     */
    public function countActiveByRole(\VendGuard\Core\Domain\Model\UserRole|string $role): int;

    /**
     * Cuenta las incidencias activas asignadas a un técnico (bloqueo de baja técnica).
     *
     * @param int $userId
     * @return int
     */
    public function countActiveAssignedIncidents(int $userId): int;

    /**
     * Alias de countActiveAssignedIncidents para compatibilidad con nomenclatura del plan.
     *
     * @param int $technicianId
     * @return int
     */
    public function countPendingIncidents(int $technicianId): int;
}
