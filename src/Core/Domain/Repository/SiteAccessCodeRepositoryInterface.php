<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * SiteAccessCodeRepositoryInterface
 *
 * Puerto de dominio de la credencial de sede (hallazgo S-4). Aísla del caso de uso
 * `AuthService::loginSite()` los detalles de PDO y de la columna que guarda el
 * hash, de modo que la verificación y el freno de intentos sean testeables sin
 * tocar MariaDB.
 */
interface SiteAccessCodeRepositoryInterface
{
    /**
     * Estado de acceso de una sede, sin exponer nunca el hash fuera del puerto.
     *
     * @return array{
     *     hash: string|null,
     *     issued_at: string|null,
     *     attempts: int,
     *     is_locked: bool
     * }|null `null` si la sede no existe o está borrada lógicamente.
     */
    public function findAccessState(int $locationId): ?array;

    /**
     * Guarda el hash bcrypt de la nueva clave y reinicia el freno de intentos.
     *
     * @param string $hash Hash ya calculado (`password_hash`).
     */
    public function storeAccessCodeHash(int $locationId, string $hash): void;

    /**
     * Registra un intento de acceso fallido, bloqueando temporalmente al alcanzar
     * el máximo configurado por el servicio.
     *
     * @param int $maxAttempts Número de fallos consecutivos que activa el bloqueo.
     * @param int $lockSeconds Segundos de bloqueo temporal.
     */
    public function registerFailedLogin(int $locationId, int $maxAttempts, int $lockSeconds): void;

    /**
     * Reinicia el contador de fallos tras un acceso correcto.
     */
    public function resetLoginAttempts(int $locationId): void;
}
