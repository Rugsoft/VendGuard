<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;

/**
 * WebhookUrlSecurityValidator — Validador de seguridad anti-SSRF para URLs de Webhook (T-NOTIF-03).
 *
 * Cumple con los requisitos RF-06.5.1 y RNF-04:
 * 1. Exige obligatoriamente esquema HTTPS ('https://').
 * 2. Bloquea de forma tajante esquemas sin cifrar ('http://', 'ftp://', etc.).
 * 3. Bloquea nombres de host locales ('localhost', 'localhost.localdomain', etc.).
 * 4. Bloquea direcciones IP de bucle local (127.0.0.0/8, ::1, 0.0.0.0).
 * 5. Bloquea rangos IPv4 privados e infraestructura de enlace local (RFC 1918 / RFC 3927):
 *    - 10.0.0.0/8
 *    - 172.16.0.0/12 (172.16.0.0 - 172.31.255.255)
 *    - 192.168.0.0/16
 *    - 169.254.0.0/16 (Link-local / Cloud metadata como AWS/GCP 169.254.169.254)
 * 6. Bloquea rangos IPv6 privados y locales únicos (fc00::/7, fe80::/10).
 * 7. Si el host es un nombre de dominio resuelto a IP literal en tiempo de comprobación opcional,
 *    verifica que la IP no apunte a un rango privado o reservado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class WebhookUrlSecurityValidator
{
    /**
     * Valida una URL de Webhook candidata. Lanza InvalidArgumentException si no cumple los requisitos de seguridad.
     *
     * @param string $url URL a validar.
     * @param bool $checkDnsResolution Si es true, resuelve el host para verificar si apunta a IP privada (por defecto false para pruebas herméticas sin red).
     * @return string La URL validada y normalizada.
     * @throws InvalidArgumentException
     */
    public static function validate(string $url, bool $checkDnsResolution = false): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Webhook URL cannot be empty.');
        }

        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('Webhook URL exceeds maximum length of 2048 characters.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Malformed webhook URL format.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            throw new InvalidArgumentException(sprintf(
                'Invalid webhook URL scheme "%s". Only secure HTTPS ("https://") is allowed.',
                $scheme
            ));
        }

        $host = strtolower($parts['host']);
        self::validateHost($host);

        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port <= 0 || $port > 65535) {
                throw new InvalidArgumentException('Invalid webhook port number.');
            }
        }

        // Si se solicita validación de resolución DNS y el host no es una IP literal
        if ($checkDnsResolution && !filter_var($host, FILTER_VALIDATE_IP)) {
            $resolvedIps = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($resolvedIps) && $resolvedIps !== []) {
                foreach ($resolvedIps as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                    if ($ip !== null && self::isPrivateOrReservedIp($ip)) {
                        throw new InvalidArgumentException(sprintf(
                            'Webhook host "%s" resolves to a private or reserved IP address (%s).',
                            $host,
                            $ip
                        ));
                    }
                }
            }
        }

        return $url;
    }

    /**
     * Comprueba si una URL es segura sin lanzar excepciones.
     */
    public static function isValid(string $url, bool $checkDnsResolution = false): bool
    {
        try {
            self::validate($url, $checkDnsResolution);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Valida la cadena de host (nombre de dominio o dirección IP).
     *
     * @throws InvalidArgumentException
     */
    private static function validateHost(string $host): void
    {
        // Bloqueo explícito de loopback por nombre
        if (
            $host === 'localhost' ||
            $host === 'localhost.localdomain' ||
            str_ends_with($host, '.localhost') ||
            str_ends_with($host, '.local') ||
            str_ends_with($host, '.internal')
        ) {
            throw new InvalidArgumentException(sprintf(
                'Localhost and local network domains are forbidden for webhooks ("%s").',
                $host
            ));
        }

        // Si el host es una dirección IP literal (v4 o v6)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (self::isPrivateOrReservedIp($ip)) {
                throw new InvalidArgumentException(sprintf(
                    'Private, loopback and link-local IP addresses are forbidden for webhooks ("%s").',
                    $ip
                ));
            }
            return;
        }

        // Verificación de formato de nombre de dominio
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            throw new InvalidArgumentException(sprintf('Invalid webhook host name "%s".', $host));
        }

        // Exigir al menos un punto en el dominio (dominio cualificado: ejemplo.com) salvo para IPs públicas
        if (!str_contains($host, '.')) {
            throw new InvalidArgumentException(sprintf(
                'Single-label hosts without a domain extension are forbidden for webhooks ("%s").',
                $host
            ));
        }
    }

    /**
     * Determina si una IP (v4 o v6) pertenece a rangos privados, de bucle local, enlace local o reservados.
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // Comprobación con flags nativos de PHP: FILTER_FLAG_NO_PRIV_RANGE y FILTER_FLAG_NO_RES_RANGE
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            return true;
        }

        // Verificaciones adicionales para rangos específicos IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            // 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) {
                return true;
            }
            // 127.0.0.0/8 (Loopback)
            if (($long & 0xFF000000) === 0x7F000000) {
                return true;
            }
            // 10.0.0.0/8 (Privada Clase A)
            if (($long & 0xFF000000) === 0x0A000000) {
                return true;
            }
            // 172.16.0.0/12 (Privada Clase B)
            if (($long & 0xFFF00000) === 0xAC100000) {
                return true;
            }
            // 192.168.0.0/16 (Privada Clase C)
            if (($long & 0xFFFF0000) === 0xC0A80000) {
                return true;
            }
            // 169.254.0.0/16 (Link-local / Metadatos de nube)
            if (($long & 0xFFFF0000) === 0xA9FE0000) {
                return true;
            }
            // 100.64.0.0/10 (Carrier-Grade NAT)
            if (($long & 0xFFC00000) === 0x64400000) {
                return true;
            }
        }

        // Verificaciones adicionales para IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = inet_pton($ip);
            if ($normalized === false) {
                return true;
            }
            // ::1 (Loopback)
            if ($normalized === inet_pton('::1')) {
                return true;
            }
            // :: (Unspecified)
            if ($normalized === inet_pton('::')) {
                return true;
            }
            // fe80::/10 (Link-local)
            $firstByte = ord($normalized[0]);
            $secondByte = ord($normalized[1]);
            if ($firstByte === 0xfe && ($secondByte & 0xc0) === 0x80) {
                return true;
            }
            // fc00::/7 (Unique Local)
            if (($firstByte & 0xfe) === 0xfc) {
                return true;
            }
        }

        return false;
    }
}
