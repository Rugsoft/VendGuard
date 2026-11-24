<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VendGuard\Application\DTO\RestockingBiFilterDto;

/**
 * RestockingBiFilterValidator
 *
 * Validador puro de los filtros del Cuadro de Mando de Reposición (Módulo 38;
 * `RF-01.1`, `RF-01.2`, `RF-01.3`, `C1`, `C5`, `QA-5`).
 *
 * Reglas que muerde, en el orden del contrato (§2):
 *
 *   1. **Ventana por defecto (`RF-01.1`).** Sin `from` ni `to`, se aplican los
 *      últimos `30` días naturales en `Europe/Madrid`: desde `today - 29 days`
 *      hasta `today`, ambos inclusive. El reloj no se lee aquí si el llamante lo
 *      inyecta, para que la prueba sea reproducible.
 *   2. **Par obligatorio (`C5`).** Informar solo una de las dos fechas es
 *      `400 INVALID_FILTER_PARAMS`; el campo culpable viaja en el mensaje para
 *      que el controlador lo publique en `error.details.field`.
 *   3. **Fechas civiles estrictas.** Formato exacto `YYYY-MM-DD` y fecha real:
 *      `2026-02-30` se rechaza en vez de corregirse en silencio.
 *   4. **Orden y techo.** `from <= to` y `(to - from) + 1 <= 366` días
 *      naturales inclusivos. Las fechas futuras (`to > today`) están admitidas
 *      expresamente (`QA-5`).
 *   5. **Identificadores opcionales.** `zone_id`, `location_id` y
 *      `restocker_id` ausentes o vacíos se normalizan a `null`; informados,
 *      deben ser enteros estrictamente positivos (`>= 1`).
 *
 * Las cotas de fechas las vuelve a comprobar `RestockingBiFilterDto`, que es la
 * frontera del dominio: este validador no delega la regla, la aplica también
 * para atribuir el campo exacto en el error.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin E/S.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockingBiFilterValidator
{
    /** Código del contrato REST para el filtro inválido (`C5`). */
    public const INVALID_FILTER_PARAMS = RestockingBiFilterDto::INVALID_FILTER_PARAMS;

    /** Forma canónica de una fecha civil del contrato. */
    private const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

    /**
     * Normaliza y valida los parámetros de consulta del informe BI.
     *
     * @param array<string, mixed> $query Parámetros `query` sin filtrar.
     * @param string|null $today Fecha civil de referencia en `Europe/Madrid`;
     *                           `null` usa el reloj del servidor.
     *
     * @throws InvalidArgumentException Con `INVALID_FILTER_PARAMS` si el filtro
     *                                  no cumple el contrato.
     */
    public static function validate(array $query, ?string $today = null): RestockingBiFilterDto
    {
        $referenceToday = $today === null ? self::currentCivilDate() : $today;

        $from = self::readDate($query, 'from');
        $to = self::readDate($query, 'to');

        if ($from === null && $to === null) {
            $from = self::shiftDays($referenceToday, -(RestockingBiFilterDto::DEFAULT_WINDOW_DAYS - 1));
            $to = $referenceToday;
        } elseif ($from === null) {
            throw self::invalid('from es obligatorio cuando se informa to', 'from');
        } elseif ($to === null) {
            throw self::invalid('to es obligatorio cuando se informa from', 'to');
        }

        return new RestockingBiFilterDto(
            $from,
            $to,
            self::readOptionalId($query, 'zone_id'),
            self::readOptionalId($query, 'location_id'),
            self::readOptionalId($query, 'restocker_id')
        );
    }

    /**
     * Lee una fecha del query; `null` si el parámetro no viaja o va vacío.
     *
     * @param array<string, mixed> $query
     *
     * @throws InvalidArgumentException
     */
    private static function readDate(array $query, string $field): ?string
    {
        if (!array_key_exists($field, $query) || $query[$field] === null) {
            return null;
        }

        $value = $query[$field];
        if (!is_string($value)) {
            throw self::invalid(sprintf('%s debe ser una cadena YYYY-MM-DD', $field), $field);
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if (preg_match(self::DATE_PATTERN, $normalized) !== 1
            || $parsed === false
            || $parsed->format('Y-m-d') !== $normalized
        ) {
            throw self::invalid(sprintf(
                '%s debe ser una fecha civil real en formato YYYY-MM-DD; se recibió «%s»',
                $field,
                $value
            ), $field);
        }

        return $normalized;
    }

    /**
     * Lee un identificador opcional; `null` si no viaja o va vacío.
     *
     * @param array<string, mixed> $query
     *
     * @throws InvalidArgumentException
     */
    private static function readOptionalId(array $query, string $field): ?int
    {
        if (!array_key_exists($field, $query) || $query[$field] === null) {
            return null;
        }

        $value = $query[$field];

        if (is_int($value)) {
            if ($value < 1) {
                throw self::invalid(sprintf('%s debe ser un entero positivo; se recibió «%d»', $field, $value), $field);
            }

            return $value;
        }

        if (!is_string($value)) {
            throw self::invalid(sprintf('%s debe ser un entero positivo', $field), $field);
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[0-9]+$/', $normalized) !== 1 || (int)$normalized < 1) {
            throw self::invalid(sprintf(
                '%s debe ser un entero positivo; se recibió «%s»',
                $field,
                $value
            ), $field);
        }

        return (int)$normalized;
    }

    /**
     * Desplaza una fecha civil en días naturales sin cruzar zonas horarias.
     *
     * @throws InvalidArgumentException
     */
    private static function shiftDays(string $date, int $days): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if ($parsed === false) {
            throw self::invalid('la fecha de referencia del informe no es válida', 'from');
        }

        return $parsed->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    private static function currentCivilDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(RestockingBiFilterDto::TIMEZONE)))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function invalid(string $message, string $field, array $context = []): InvalidArgumentException
    {
        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new InvalidArgumentException(sprintf(
            '%s: %s (field: %s).%s',
            self::INVALID_FILTER_PARAMS,
            $message,
            $field,
            $suffix
        ));
    }
}
