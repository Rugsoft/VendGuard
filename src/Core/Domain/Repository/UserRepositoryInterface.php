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
     * Si no existe o tiene deleted_at IS NOT NULL, debe devolver null.
     *
     * @param string $email
     * @param bool $onlyActive Si es true, exige is_active = 1.
     * @return User|null
     */
    public function findByEmail(string $email, bool $onlyActive = true): ?User;

    /**
     * Recupera un usuario por su ID numérico.
     *
     * @param int $id
     * @param bool $onlyActive
     * @return User|null
     */
    public function findById(int $id, bool $onlyActive = true): ?User;

    /**
     * Obtiene la lista de todos los técnicos de ruta activos para la asignación de partes.
     *
     * @param bool $onlyActive
     * @return array<User>
     */
    public function findAllTechnicians(bool $onlyActive = true): array;

    /**
     * Aplica borrado lógico marcando deleted_at = NOW() (RNF-03).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;
}
