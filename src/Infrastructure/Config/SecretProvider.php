<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Config;

use RuntimeException;

/**
 * SecretProvider
 *
 * Única fuente de verdad de las claves criptográficas del sistema (hallazgo S-1 de la
 * auditoría de arquitectura). Antes de este proveedor, la clave HMAC con la que se firmaban
 * TODOS los tokens de sesión y el secreto del proceso cron vivían como literales en el
 * código fuente: cualquiera con acceso al repositorio podía forjar un token de coordinador.
 *
 * Política de resolución (deliberadamente fail-closed):
 * 1. Si la variable de entorno existe y no está vacía, esa es la clave. En producción es la
 *    única vía admitida.
 * 2. Si falta y el entorno se declara de producción (`APP_ENV` o `VENDGUARD_ENV` con valor
 *    `production`/`prod`), se lanza excepción: el sistema no arranca con una clave por
 *    defecto. No hay "default que funcione" en un despliegue.
 * 3. Si falta y el entorno es local/desarrollo, se usa una clave exclusiva de desarrollo,
 *    distinta de la histórica. Cualquier token firmado con la antigua clave del repositorio
 *    deja de ser válido, también en local.
 *
 * Variables de entorno reconocidas:
 * - `SECRET_KEY`   → firma HMAC de tokens de sede e internos.
 * - `CRON_SECRET`  → credencial del proceso batch `/api/cron/auto-close`.
 * - `APP_ENV` / `VENDGUARD_ENV` → `production`/`prod` activan el fallo en cerrado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
final class SecretProvider
{
    public const ENV_AUTH_SECRET = 'SECRET_KEY';
    public const ENV_CRON_SECRET = 'CRON_SECRET';

    /**
     * Claves de desarrollo. No son secretos: su único propósito es que un entorno local
     * funcione sin configuración y que la clave histórica del repositorio quede invalidada.
     */
    public const DEV_AUTH_SECRET = 'vendguard-dev-auth-key-local-only';
    public const DEV_CRON_SECRET = 'vendguard-dev-cron-key-local-only';

    private const PRODUCTION_ENV_VARS = ['APP_ENV', 'VENDGUARD_ENV'];
    private const PRODUCTION_VALUES = ['production', 'prod'];

    /**
     * Clave de firma de tokens (sede e internos).
     */
    public static function authSecret(): string
    {
        return self::resolve(self::ENV_AUTH_SECRET, self::DEV_AUTH_SECRET);
    }

    /**
     * Secreto del proceso batch `/api/cron/auto-close`.
     */
    public static function cronSecret(): string
    {
        return self::resolve(self::ENV_CRON_SECRET, self::DEV_CRON_SECRET);
    }

    /**
     * ¿El proceso se declara en producción? Determina si la ausencia de variable es fatal.
     */
    public static function isProduction(): bool
    {
        foreach (self::PRODUCTION_ENV_VARS as $name) {
            $value = self::read($name);
            if ($value !== null && in_array(strtolower($value), self::PRODUCTION_VALUES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function resolve(string $envName, string $devFallback): string
    {
        $value = self::read($envName);
        if ($value !== null && $value !== '') {
            return $value;
        }

        if (self::isProduction()) {
            throw new RuntimeException(sprintf(
                'Falta la variable de entorno %s y el sistema está en producción: no se admite una clave por defecto. Defínela antes de desplegar (SECRET_KEY y CRON_SECRET).',
                $envName
            ));
        }

        return $devFallback;
    }

    /**
     * Lee una variable de entorno de todas las fuentes habituales de PHP.
     */
    private static function read(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_string($value) ? trim($value) : null;
    }
}
