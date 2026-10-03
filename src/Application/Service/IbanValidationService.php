<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;

/**
 * IbanValidationService
 *
 * Servicio de Aplicación que valida la sintaxis de los datos bancarios y de
 * contacto de un expediente de reintegro, conforme a RF-REF-01, RF-REF-10,
 * RNF-REF-03 y al Art. IV (Dogma Vanilla).
 *
 * Implementa la norma ISO 13616 / ISO 7064 MOD 97-10 en PHP puro, sin ninguna
 * dependencia de Composer (librerías del estilo `jschaedl/iban` o
 * `pear/validate_finance` quedaron descartadas por el Art. IV).
 *
 * ## Aritmética modular por bloques
 * La cadena numérica de un IBAN puede tener hasta 68 dígitos, muy por encima de
 * lo que caben en un entero de 64 bits. En lugar de `bcmod` (extensión no
 * garantizada) o de aritmética con enteros grandes, se recorre la cadena en
 * bloques de hasta 7 dígitos aplicando
 * `resto = (resto_anterior . bloque) mod 97`. El operando intermedio nunca
 * supera los 13 dígitos, con lo que cabe de sobra en un entero nativo.
 *
 * ## Orden de normalización (desviación documentada)
 * El pseudocódigo de `specs/08-refunds/plan.md` §3.1 y de
 * `specs/technical/refunds_contracts.md` §1.2 exprima la limpieza como
 * `strtoupper(preg_replace('/[^A-Z0-9]/', '', $iban))`, es decir, filtrando
 * antes de pasar a mayúsculas. Aplicado literalmente, ese orden descarta
 * cualquier letra minúscula, de modo que un IBAN tecleado en minúsculas por el
 * consumidor en el formulario público QR quedaría reducido a sus dígitos y
 * siempre sería rechazado. Como la intención declarada de ambos documentos es
 * "limpiar espacios y guiones", aquí se invierte el orden: primero se pasa a
 * mayúsculas y después se filtra. El comportamiento resultante es un superconjunto
 * del especificado para entradas ya en mayúsculas.
 *
 * ## Enmascarado (Art. V.4)
 * El IBAN nunca debe aparecer en claro en una excepción ni en el registro de
 * auditoría. `InvalidIbanFormatException` recibe exclusivamente la versión
 * enmascarada que produce `maskIban()`.
 */
final class IbanValidationService
{
    /** Longitud mínima de un IBAN según ISO 13616. */
    public const IBAN_MIN_LENGTH = 15;

    /** Longitud máxima de un IBAN según ISO 13616. */
    public const IBAN_MAX_LENGTH = 34;

    /** Longitud exacta de un IBAN español. */
    public const IBAN_ES_LENGTH = 24;

    /** Longitud exacta de un teléfono móvil español habilitado para Bizum. */
    public const BIZUM_PHONE_LENGTH = 9;

    /**
     * Longitud mínima a partir de la cual se pueden enmascarar al menos cuatro
     * caracteres. Por debajo de este valor se enmascara la cadena completa.
     */
    private const MIN_MASKABLE_LENGTH = 10;

    /** Código de país de España. */
    private const SPAIN_COUNTRY_CODE = 'ES';

    /**
     * Valida un IBAN aplicando la norma ISO 13616 / ISO 7064 MOD 97-10.
     *
     * No lanza excepciones: devuelve `false` ante cualquier entrada inválida.
     *
     * @param string $iban IBAN con o sin espacios, guiones o minúsculas.
     */
    public function isValidIban(string $iban): bool
    {
        $normalized = $this->normalizeIban($iban);

        // 1. Estructura: los dos primeros caracteres son el código de país
        //    (letras) y los dos siguientes son los dígitos de control.
        if (preg_match('/^[A-Z]{2}[0-9]{2}/', $normalized) !== 1) {
            return false;
        }

        // 2. Longitud según ISO 13616, con longitud exacta para España.
        $length = strlen($normalized);
        if ($length < self::IBAN_MIN_LENGTH || $length > self::IBAN_MAX_LENGTH) {
            return false;
        }
        if (str_starts_with($normalized, self::SPAIN_COUNTRY_CODE) && $length !== self::IBAN_ES_LENGTH) {
            return false;
        }

        // 3. Reordenar los cuatro primeros caracteres al final (BBAN + banco).
        $reordered = substr($normalized, 4) . substr($normalized, 0, 4);

        // 4. Sustituir cada letra A-Z por su valor numérico (A=10 ... Z=35).
        $numeric = '';
        foreach (str_split($reordered) as $char) {
            $numeric .= ctype_alpha($char)
                ? (string)(ord($char) - 55)
                : $char;
        }

        // 5. Aritmética modular por bloques y 6. resto === 1.
        return $this->modularMod97($numeric) === 1;
    }

    /**
     * Valida un IBAN y normaliza su formato, o lanza la excepción de dominio.
     *
     * @throws InvalidIbanFormatException Si el IBAN no supera la verificación
     *   sintáctica ni el checksum Módulo 97. El IBAN nunca viaja en claro en la
     *   excepción: sólo su forma enmascarada (Art. V.4).
     */
    public function assertValidIban(string $iban): string
    {
        if (!$this->isValidIban($iban)) {
            throw new InvalidIbanFormatException($this->maskIban($iban));
        }

        return $this->normalizeIban($iban);
    }

    /**
     * Normaliza un IBAN a su forma canónica: mayúsculas y sin separadores.
     */
    public function normalizeIban(string $iban): string
    {
        $uppercased = strtoupper($iban);

        return (string)preg_replace('/[^A-Z0-9]/', '', $uppercased);
    }

    /**
     * Enmascara un IBAN para poder registrarlo sin exponer la cuenta (Art. V.4).
     *
     * Conserva el código de país y los cuatro últimos dígitos; el resto se
     * sustituye por asteriscos.
     *
     * Si la entrada es demasiado corta para ocultar al menos cuatro caracteres,
     * se enmascara por completo. El umbral es deliberadamente estricto: con un
     * IBAN de seis caracteres, mostrar «país + 4 últimos» equivaldría a devolver
     * la cuenta entera en claro, que es exactamente lo que esta función existe
     * para impedir. El IBAN español más corto posible (15 caracteres) siempre
     * queda con 9 asteriscos de margen.
     */
    public function maskIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $normalized = $this->normalizeIban($iban);
        if ($normalized === '') {
            return null;
        }

        $length = strlen($normalized);
        if ($length < self::MIN_MASKABLE_LENGTH) {
            return str_repeat('*', $length);
        }

        $country = substr($normalized, 0, 2);
        $tail = substr($normalized, -4);
        $maskedLength = $length - 6;

        return $country . str_repeat('*', $maskedLength) . $tail;
    }

    /**
     * Valida un teléfono móvil destino de Bizum.
     *
     * Debe tener exactamente nueve dígitos. Se aceptan únicamente los
     * separadores de presentación habituales (espacios, puntos, guiones y
     * paréntesis); cualquier otro carácter, incluidos los prefijos
     * internacionales `+34`, invalidan la entrada.
     */
    public function isValidBizumPhone(string $phone): bool
    {
        $normalized = $this->normalizeBizumPhone($phone);

        return preg_match('/^[0-9]{' . self::BIZUM_PHONE_LENGTH . '}$/', $normalized) === 1;
    }

    /**
     * Valida un teléfono Bizum y devuelve su forma normalizada, o lanza la
     * excepción de dominio.
     *
     * @throws InvalidBizumPhoneException Si no tiene exactamente nueve dígitos.
     */
    public function assertValidBizumPhone(string $phone): string
    {
        if (!$this->isValidBizumPhone($phone)) {
            throw new InvalidBizumPhoneException();
        }

        return $this->normalizeBizumPhone($phone);
    }

    /**
     * Normaliza un teléfono Bizum eliminando los separadores de presentación.
     */
    public function normalizeBizumPhone(string $phone): string
    {
        return (string)preg_replace('/[\s.\-()]/', '', trim($phone));
    }

    /**
     * Calcula el resto módulo 97 de una cadena numérica arbitrariamente larga
     * sin recurrir a enteros grandes ni a extensiones opcionales.
     */
    private function modularMod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits, 7) as $block) {
            $remainder = (int) (($remainder . $block) % 97);
        }

        return $remainder;
    }
}