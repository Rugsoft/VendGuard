<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use VendGuard\Core\Domain\Exception\ZoneValidationException;

/**
 * PostalCode
 *
 * Objeto de Valor inmutable del código postal territorial del Módulo 17.
 *
 * El CP es la llave de la herencia de zona (RF-03.1) y la identidad del mapeo
 * `operational_zone_postal_codes` (RF-01.2), así que su forma se valida en un
 * único sitio: el mismo objeto valida el CP del catálogo territorial y el CP de
 * la sede en el alta y la edición (Resolución QA 7). Dos validaciones distintas
 * serían dos verdades distintas.
 *
 * Reglas:
 *  - Exactamente cinco dígitos numéricos (`^\d{5}$`), que es la forma que exige
 *    el contrato y la que blinda el `CHECK` de las dos tablas (QA 7).
 *  - Los ceros a la izquierda **son parte del valor**: `08001` no es `8001`, y
 *    la entidad nunca lo convierte a entero (perdería el cero y el CP pasaría a
 *    identificar otra demarcación).
 *  - Un **entero** se interpreta como el número de CP y se rellena a cinco
 *    cifras (`801` → `00801`), porque un número no puede llevar ceros perdidos.
 *    Una **cadena** de menos de cinco cifras se rechaza: rellenar `0800` daría
 *    `00800`, un CP distinto del que se escribió, y la herencia territorial se
 *    resolvería contra la zona equivocada en silencio.
 *  - Se rechaza cualquier otra cosa: vacío, letras, signos, decimales o más de
 *    cinco dígitos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
final class PostalCode
{
    /** Longitud canónica del CP, la que declara el contrato (`VARCHAR(5)`). */
    public const LENGTH = 5;

    /** Forma que acepta el motor en `operational_zone_postal_codes.postal_code`. */
    public const PATTERN = '/^\d{5}$/';

    private string $value;

    /**
     * @throws ZoneValidationException Si el valor no es un CP de cinco dígitos.
     */
    public function __construct(string|int $postalCode)
    {
        if (is_int($postalCode)) {
            if ($postalCode < 0 || $postalCode > 99999) {
                throw self::invalidPostalCode((string)$postalCode);
            }

            $this->value = str_pad((string)$postalCode, self::LENGTH, '0', STR_PAD_LEFT);

            return;
        }

        $raw = trim($postalCode);
        if (preg_match(self::PATTERN, $raw) !== 1) {
            throw self::invalidPostalCode($postalCode);
        }

        $this->value = $raw;
    }

    /**
     * Valor canónico de cinco dígitos, con los ceros a la izquierda restituidos.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Compara igualdad estructural con otro código postal.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Comprueba si un valor puede convertirse en CP válido, sin lanzar.
     */
    public static function isValid(string|int $postalCode): bool
    {
        if (is_int($postalCode)) {
            return $postalCode >= 0 && $postalCode <= 99999;
        }

        return preg_match(self::PATTERN, trim($postalCode)) === 1;
    }

    /**
     * Rechazo tipado con el formato exigido en el mensaje.
     */
    private static function invalidPostalCode(string $postalCode): ZoneValidationException
    {
        return ZoneValidationException::forField(
            'postal_code',
            sprintf(
                "Código postal no válido: '%s'. Debe contener exactamente cinco dígitos numéricos (ej. 08001).",
                $postalCode
            )
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
