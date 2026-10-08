<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

/**
 * SiteAccessCodeGenerator
 *
 * Generador canónico de la clave de centro (hallazgo S-4). Concentra en un solo lugar
 * el alfabeto, la longitud y el formato de presentación para que la emisión, la
 * verificación y la interfaz web no puedan divergir.
 *
 * Decisiones de diseño:
 * - Alfabeto Crockford-like (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`): sin `0/O`, `1/I/L`
 *   ni vocales, de modo que un código dictado por teléfono o transcrito a mano en la
 *   sede no se confunda.
 * - `random_int()` (CSPRNG nativo): una clave de centro es una credencial de sede
 *   completa; `rand()`/`mt_rand()` queda descartado por predecible.
 * - La normalización quita separadores y unifica mayúsculas: `ab3de fghjk` y
 *   `AB3DE-FGHJK` verifican contra el mismo hash.
 *
 * Dogma Vanilla: sin dependencias externas; solo funciona con `random_int`.
 */
final class SiteAccessCodeGenerator
{
    /** Alfabeto canónico: 31 símbolos sin pares confundibles (sin 0/O/1/I/L ni vocales). */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Longitud de la clave una vez normalizada (sin separadores). */
    public const LENGTH = 10;

    /** Tamaño de cada grupo de presentación (`XXXXX-XXXXX`). */
    public const GROUP_SIZE = 5;

    /**
     * Genera una clave de centro nueva con formato de presentación `XXXXX-XXXXX`.
     */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return self::format($code);
    }

    /**
     * Quita separadores y unifica a mayúsculas para comparar o hashear.
     */
    public static function normalize(string $code): string
    {
        $stripped = preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';

        return strtoupper($stripped);
    }

    /**
     * Aplica el formato de presentación canónico (`XXXXX-XXXXX`).
     */
    public static function format(string $code): string
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return '';
        }

        return implode('-', str_split($normalized, self::GROUP_SIZE));
    }

    /**
     * Valida que el texto recibido sea una clave de centro bien formada.
     */
    public static function isValid(string $code): bool
    {
        $normalized = self::normalize($code);
        if (strlen($normalized) !== self::LENGTH) {
            return false;
        }

        return strspn($normalized, self::ALPHABET) === self::LENGTH;
    }
}
