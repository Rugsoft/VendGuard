<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http;

/**
 * HttpsPolicy
 *
 * Política de transporte seguro del frontal web (Módulo 24, RNF-06 y `R2`).
 *
 * El cerrojo entre pestañas del técnico depende de `navigator.locks`, que el
 * navegador solo expone en **contexto seguro**: sobre HTTP plano dos ventanas
 * pueden vaciar la misma cola y el Service Worker ni siquiera se registra. Este
 * proyecto no puede confiar en que el despliegue «ya ponga TLS»: el frontal es
 * el único punto de entrada y es aquí donde la conexión se corrige o se rechaza.
 *
 * Reglas:
 *  - Si la petición ya viaja por TLS —directa (`HTTPS=on`) o a través de un
 *    proxy que la termina (`X-Forwarded-Proto: https`)—, no hay redirección y
 *    se emite HSTS.
 *  - Si viaja por HTTP hacia un host público, se responde `308` a la misma URL
 *    sobre HTTPS. `308` conserva método y cuerpo, de modo que un `POST` de la
 *    API no se convierte en un `GET` vacío.
 *  - El bucle local (`localhost`, `127.0.0.1`, `::1`, `0.0.0.0`, `*.localhost`)
 *    queda exento: es contexto seguro por definición del navegador y es donde
 *    corre el motor de desarrollo.
 *  - `VENDGUARD_ALLOW_INSECURE_HTTP=1` permite HTTP plano en un despliegue
 *    controlado (demo en LAN sin terminador TLS). Es una excepción explícita:
 *    sin ella, el valor por defecto es proteger la conexión.
 *
 * El host se sanea antes de construir la cabecera `Location`: un `Host` con
 * saltos de línea, barras o puertos no numéricos no puede convertirse en una
 * redirección abierta ni en una inyección de cabeceras.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class HttpsPolicy
{
    /** Variable de entorno que rebaja la política a HTTP plano de forma explícita. */
    public const ALLOW_INSECURE_ENV = 'VENDGUARD_ALLOW_INSECURE_HTTP';

    /** HSTS de un año, también para subdominios. */
    public const HSTS_HEADER = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';

    /** Nombres que el navegador considera contexto seguro aunque no haya TLS. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /** Valores que activan la excepción de desarrollo. */
    private const TRUTHY = ['1', 'true', 'yes', 'on'];

    /**
     * Esquema real de la petición según el servidor y el proxy delantero.
     *
     * @param array<string, mixed> $server
     */
    public static function requestScheme(array $server): string
    {
        $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return 'https';
        }

        $forwarded = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwarded !== '') {
            $first = trim((string)explode(',', $forwarded)[0]);
            if ($first === 'https') {
                return 'https';
            }
        }

        return 'http';
    }

    /**
     * Host saneado de la petición (`host[:puerto]`), o cadena vacía si no es
     * utilizable para redirigir.
     *
     * @param array<string, mixed> $server
     */
    public static function requestHost(array $server): string
    {
        return self::safeHost(
            (string)($server['HTTP_HOST'] ?? ''),
            (string)($server['SERVER_NAME'] ?? '')
        );
    }

    /**
     * Decide si la petición debe corregirse a HTTPS antes de servir contenido.
     *
     * @param array<string, mixed> $server
     */
    public static function shouldRedirect(array $server): bool
    {
        if (self::requestScheme($server) === 'https') {
            return false;
        }

        // La sonda de salud la consulta el orquestador por la red interna, sin
        // pasar por el proxy que termina el TLS: redirigirla la pondría en rojo.
        if (self::isHealthProbe($server)) {
            return false;
        }

        $host = self::requestHost($server);
        if ($host === '' || self::isLoopbackHost($host)) {
            return false;
        }

        return !self::allowsInsecureHttp();
    }

    /**
     * URL absoluta equivalente sobre HTTPS, conservando ruta y query.
     *
     * @param array<string, mixed> $server
     */
    public static function targetUrl(array $server): string
    {
        $host = self::requestHost($server);
        if ($host === '') {
            // Sin un host saneado no se construye una redirección: nunca se
            // devuelve al cliente una cabecera armada con sus propios datos.
            return '';
        }

        $name = self::hostName($host);
        $port = self::hostPort($host);

        $authority = $name;
        if ($port !== null && $port !== '80' && $port !== '443') {
            $authority .= ':' . $port;
        }

        return 'https://' . $authority . self::requestPath($server);
    }

    /** ¿El host es de bucle local, donde el navegador ya da contexto seguro? */
    public static function isLoopbackHost(string $host): bool
    {
        $name = self::hostName($host);
        if ($name === '') {
            return false;
        }

        return in_array($name, self::LOOPBACK_HOSTS, true) || str_ends_with($name, '.localhost');
    }

    /** ¿El operador rebajó la política de forma explícita? */
    public static function allowsInsecureHttp(): bool
    {
        $value = getenv(self::ALLOW_INSECURE_ENV);
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), self::TRUTHY, true);
    }

    /**
     * Sanea `HTTP_HOST` y, si no sirve, recurre a `SERVER_NAME`.
     *
     * Solo se admiten nombres DNS/IPv4, IPv6 entre corchetes y un puerto
     * numérico: cualquier otra cosa devuelve cadena vacía para que el llamador
     * no redirija con datos del cliente.
     */
    public static function safeHost(string $httpHost, string $serverName = ''): string
    {
        $candidate = trim($httpHost);
        if ($candidate === '' || !self::isSafeAuthority($candidate)) {
            $fallback = trim($serverName);
            if ($fallback === '' || !self::isSafeAuthority($fallback)) {
                return '';
            }

            return strtolower($fallback);
        }

        return strtolower($candidate);
    }

    /** Nombre del host, sin puerto y sin corchetes de IPv6. */
    public static function hostName(string $host): string
    {
        $trimmed = strtolower(trim($host));
        if ($trimmed === '') {
            return '';
        }

        if ($trimmed[0] === '[') {
            $end = strpos($trimmed, ']');

            return $end === false ? '' : substr($trimmed, 1, $end - 1);
        }

        $colon = strrpos($trimmed, ':');

        return $colon === false ? $trimmed : substr($trimmed, 0, $colon);
    }

    /** Puerto explícito del host, o `null` si no lo trae. */
    public static function hostPort(string $host): ?string
    {
        $trimmed = strtolower(trim($host));
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] === '[') {
            $end = strpos($trimmed, ']');
            if ($end === false) {
                return null;
            }

            $rest = substr($trimmed, $end + 1);

            return str_starts_with($rest, ':') ? substr($rest, 1) : null;
        }

        $colon = strrpos($trimmed, ':');

        return $colon === false ? null : substr($trimmed, $colon + 1);
    }

    /** ¿La petición es la sonda de salud del orquestador? */
    public static function isHealthProbe(array $server): bool
    {
        return self::requestPath($server) === '/api/health';
    }

    /** Ruta y query sin saltos de línea, siempre empezando por `/`. */
    private static function requestPath(array $server): string
    {
        $raw = (string)($server['REQUEST_URI'] ?? '/');
        $path = preg_replace('/[\r\n].*/', '', $raw) ?? '/';

        return $path === '' || $path[0] !== '/' ? '/' . ltrim($path, '/') : $path;
    }

    /** Validación estricta de `host[:puerto]` para no inyectar cabeceras. */
    private static function isSafeAuthority(string $authority): bool
    {
        if (preg_match('/^\[[0-9A-Fa-f:.]+\](?::(\d{1,5}))?$/', $authority, $matches) === 1) {
            return self::isValidPort($matches[1] ?? null);
        }

        if (preg_match('/^([A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', $authority, $matches) !== 1) {
            return false;
        }

        $name = (string)$matches[1];
        if ($name === '' || str_starts_with($name, '.') || str_ends_with($name, '.')
            || str_contains($name, '..')) {
            return false;
        }

        return self::isValidPort($matches[2] ?? null);
    }

    private static function isValidPort(?string $port): bool
    {
        if ($port === null || $port === '') {
            return true;
        }

        $value = (int)$port;

        return $value >= 1 && $value <= 65535;
    }
}
