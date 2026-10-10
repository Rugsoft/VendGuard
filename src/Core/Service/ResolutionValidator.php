<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\InvalidResolutionException;

/**
 * ResolutionValidator
 * 
 * Validador de Dominio para el cierre y resolución técnica de incidencias (RF-08 / EARS 8.1, 8.2).
 * Exige de forma estricta e independiente un mínimo de 20 caracteres reales
 * tanto para el diagnóstico técnico como para la acción correctiva implementada.
 */
class ResolutionValidator
{
    public const MIN_LENGTH = 20;

    /** Lectura térmica mínima físicamente plausible en el recinto de una máquina. */
    public const MIN_PLAUSIBLE_TEMPERATURE_C = -40.0;

    /** Lectura térmica máxima físicamente plausible en el recinto de una máquina. */
    public const MAX_PLAUSIBLE_TEMPERATURE_C = 80.0;

    /**
     * Valida los campos de resolución técnica.
     *
     * @param string $diagnosis Diagnóstico del problema detectado por el técnico.
     * @param string $action Acción técnica correctiva implementada.
     * @return bool Devuelve true si ambos campos superan el umbral mínimo de 20 caracteres.
     * @throws InvalidResolutionException Si el diagnóstico o la acción no alcanzan los 20 caracteres.
     */
    public static function validate(string $diagnosis, string $action): bool
    {
        $errors = self::getValidationErrors($diagnosis, $action);

        if (!empty($errors)) {
            throw new InvalidResolutionException(
                "Datos de resolución insuficientes: " . implode(' ', $errors),
                $errors
            );
        }

        return true;
    }

    /**
     * Comprueba si los textos de resolución son válidos sin arrojar excepción.
     *
     * @param string $diagnosis
     * @param string $action
     * @return bool
     */
    public static function isValid(string $diagnosis, string $action): bool
    {
        return empty(self::getValidationErrors($diagnosis, $action));
    }

    /**
     * Evalúa los textos y devuelve un listado de mensajes de error si no cumplen los requisitos.
     *
     * @param string $diagnosis
     * @param string $action
     * @return array<string>
     */
    public static function getValidationErrors(string $diagnosis, string $action): array
    {
        $errors = [];

        $cleanDiagnosis = trim($diagnosis);
        $cleanAction = trim($action);

        $diagnosisLength = mb_strlen($cleanDiagnosis, 'UTF-8');
        $actionLength = mb_strlen($cleanAction, 'UTF-8');

        if ($diagnosisLength < self::MIN_LENGTH) {
            $errors[] = sprintf(
                "El diagnóstico técnico debe contener al menos %d caracteres descriptivos (longitud actual: %d).",
                self::MIN_LENGTH,
                $diagnosisLength
            );
        }

        if ($actionLength < self::MIN_LENGTH) {
            $errors[] = sprintf(
                "La acción correctiva debe contener al menos %d caracteres descriptivos (longitud actual: %d).",
                self::MIN_LENGTH,
                $actionLength
            );
        }

        return $errors;
    }

    /**
     * Valida el bloque de declaraciones sanitarias obligatorias del cierre de una
     * avería sobre máquina en cuarentena o con el reloj biológico vencido
     * (RF-03.5.2, Art. II).
     *
     * Las tres declaraciones son actos positivos del técnico, no una formalidad:
     * la temperatura debe ser una lectura plausible y las dos confirmaciones han de
     * llegar marcadas. Un `false` explícito se rechaza con el mismo rigor que una
     * omisión, porque el formulario no se bloquea para que el profesional deje
     * constancia de lo que no hizo, sino de lo que sí hizo antes de devolver el
     * equipo al servicio.
     *
     * @param array<string, mixed> $declarations Bloque `sanitary_declarations` del cuerpo HTTP.
     * @return bool `true` si las tres declaraciones son válidas.
     * @throws InvalidResolutionException Si falta o es inválida alguna declaración.
     */
    public static function validateSanitaryDeclarations(array $declarations): bool
    {
        $errors = self::getSanitaryDeclarationErrors($declarations);

        if (!empty($errors)) {
            throw new InvalidResolutionException(
                'Declaraciones sanitarias insuficientes: ' . implode(' ', $errors),
                $errors
            );
        }

        return true;
    }

    /**
     * Evalúa el bloque de declaraciones sanitarias y devuelve los mensajes de error
     * sin arrojar excepción.
     *
     * @param array<string, mixed>|null $declarations
     * @return array<string>
     */
    public static function getSanitaryDeclarationErrors(?array $declarations): array
    {
        if ($declarations === null) {
            return [
                'Debe registrar las tres declaraciones sanitarias que exige el Art. II antes de cerrar una avería sobre una máquina en cuarentena: temperatura real del recinto térmico, retirada y destrucción del stock perecedero deteriorado, y ejecución del checklist de higienización.',
            ];
        }

        $errors = [];

        $rawTemperature = $declarations['temperature_c'] ?? null;
        if ($rawTemperature === null || $rawTemperature === '' || !is_numeric($rawTemperature)) {
            $errors[] = 'Debe declarar la temperatura real del recinto térmico en grados Celsius (campo temperature_c).';
        } else {
            $temperature = (float)$rawTemperature;
            if ($temperature < self::MIN_PLAUSIBLE_TEMPERATURE_C || $temperature > self::MAX_PLAUSIBLE_TEMPERATURE_C) {
                $errors[] = sprintf(
                    'La temperatura declarada (%.1f °C) queda fuera del rango físicamente plausible [%.1f, %.1f] °C; registre la lectura real del termómetro.',
                    $temperature,
                    self::MIN_PLAUSIBLE_TEMPERATURE_C,
                    self::MAX_PLAUSIBLE_TEMPERATURE_C
                );
            }
        }

        if (filter_var($declarations['stock_destroyed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            $errors[] = 'Debe confirmar la retirada y destrucción del stock perecedero deteriorado por la rotura de frío (campo stock_destroyed).';
        }

        if (filter_var($declarations['hygiene_checklist'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            $errors[] = 'Debe confirmar la ejecución del checklist de higienización sanitaria (campo hygiene_checklist).';
        }

        return $errors;
    }
}
