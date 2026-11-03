<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;

/**
 * BenchDiagnosisValidator — puerta única de la evidencia técnica de taller (Art. V.1).
 *
 * Un dictamen de banco no se acredita con un texto largo: se acredita con un texto
 * que dice algo. La regla se mide sobre el texto **normalizado** —extremos
 * recortados y cualquier secuencia de espacios, tabuladores o saltos de línea
 * colapsada a un único espacio—, así que rellenar con líneas en blanco no suma
 * longitud y separar palabras con saltos no resta palabras.
 *
 * Sobre ese texto se exigen tres cosas a la vez:
 *   1. Entre 20 y 1000 caracteres reales (`mb_strlen` en UTF-8).
 *   2. Al menos tres tokens alfanuméricos de dos o más caracteres
 *      (`/[\p{L}\p{N}]{2,}/u`), de modo que «--------------------» o «ok ok ok»
 *      no pasen por una revisión.
 *   3. Un texto no vacío tras normalizar, que ya cubre el mínimo.
 *
 * El validador se usa tanto para `REFURBISHED_RESTORED` como para
 * `SCRAPPED_IRREPARABLE`, incluida la baja directa por extravío desde
 * `PENDING_DELIVERY` (`C1`): la pieza no se ha visto, pero el motivo de la baja
 * sigue siendo una afirmación técnica que hay que sostener con palabras.
 *
 * El código de error es parametrizable para que la capa REST pueda publicar su
 * código canónico sin duplicar la regla; el valor por defecto es el del contrato
 * del módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias ni estado.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class BenchDiagnosisValidator
{
    /** Longitud efectiva mínima, medida tras normalizar. */
    public const MIN_LENGTH = 20;

    /** Longitud efectiva máxima, medida tras normalizar. */
    public const MAX_LENGTH = 1000;

    /** Tokens alfanuméricos mínimos de dos o más caracteres. */
    public const MIN_ALNUM_TOKENS = 3;

    /**
     * Normaliza y valida el diagnóstico de banco.
     *
     * @param string $rawText   Texto tal como llega del cliente.
     * @param string $errorCode Código de error con el que se rechaza un texto sin
     *                          sustancia (por defecto `BENCH_DIAGNOSIS_TOO_SHORT`).
     * @return string Diagnóstico normalizado: lo que se guarda es exactamente lo que se midió.
     *
     * @throws WorkshopRepairValidationException Si el texto es demasiado corto, demasiado
     *                                           largo o no alcanza tres tokens alfanuméricos.
     */
    public static function normalizeAndValidate(
        string $rawText,
        string $errorCode = WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_SHORT
    ): string {
        $collapsed = preg_replace('/\s+/u', ' ', trim($rawText));
        $normalized = is_string($collapsed) ? $collapsed : '';
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            throw WorkshopRepairValidationException::benchDiagnosisTooShort($length, $errorCode);
        }

        if ($length > self::MAX_LENGTH) {
            throw WorkshopRepairValidationException::benchDiagnosisTooLong($length);
        }

        $tokens = preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized);
        if (!is_int($tokens) || $tokens < self::MIN_ALNUM_TOKENS) {
            throw WorkshopRepairValidationException::benchDiagnosisWithoutWords(
                is_int($tokens) ? $tokens : 0,
                $errorCode
            );
        }

        return $normalized;
    }
}
