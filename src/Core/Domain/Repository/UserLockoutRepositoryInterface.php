<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * UserLockoutRepositoryInterface
 *
 * Puerto de dominio para el control de intentos de acceso y bloqueo por fuerza bruta
 * de cuentas de usuarios internos (hallazgo S-3). Desacopla la gestión del estado de
 * bloqueo respecto a UserRepositoryInterface, preservando el principio de segregación
 * de interfaces (ISP) y evitando que stubs/mocks de administración de usuarios se vean
 * forzados a implementar métodos ajenos a su caso de uso.
 */
interface UserLockoutRepositoryInterface
{
    /**
     * Obtiene el estado de bloqueo y control de fuerza bruta del usuario.
     *
     * @param int $userId
     * @return array{attempts: int, is_locked: bool, locked_until: string|null}|null
     */
    public function findLoginLockoutState(int $userId): ?array;

    /**
     * Registra un intento de login fallido para el usuario, aplicando bloqueo si alcanza el umbral.
     *
     * @param int $userId
     * @param int $maxAttempts
     * @param int $lockSeconds
     */
    public function registerFailedLogin(int $userId, int $maxAttempts, int $lockSeconds): void;

    /**
     * Reinicia el contador de intentos fallidos de login y limpia el bloqueo tras un acceso exitoso.
     *
     * @param int $userId
     */
    public function resetLoginAttempts(int $userId): void;
}
