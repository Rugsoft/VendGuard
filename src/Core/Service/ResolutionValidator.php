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
}
