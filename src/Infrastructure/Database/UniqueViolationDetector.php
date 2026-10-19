<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDOException;
use Throwable;

/**
 * UniqueViolationDetector — **única autoridad** sobre la pregunta «¿esta escritura
 * chocó contra un índice único?».
 *
 * POR QUÉ EXISTE
 *
 *   El proyecto nació sobre MariaDB y hoy escribe sobre PostgreSQL (motor único,
 *   `specs/13-single-engine-supabase`), pero la detección de duplicados se escribió
 *   en la forma del motor retirado: `SQLSTATE 23000` + errno `1062`. PostgreSQL
 *   declara la misma situación con **`SQLSTATE 23505`** (`unique_violation`) y sin
 *   errno de controlador, así que cada ruta que sólo miraba los códigos antiguos
 *   dejaba escapar la carrera como `PDOException`: el técnico o el coordinador
 *   recibían un **500** donde la especificación exige un 409 con el registro
 *   existente.
 *
 *   Hubo dos copias de la comprobación —una en el repositorio de incidencias y otra
 *   en el servicio de administración de usuarios— y el error volvió a aparecer al
 *   añadir la segunda. Este detector es la **única** implementación: cualquier
 *   repositorio o servicio que traduzca una colisión de unicidad debe preguntarle a
 *   él, nunca volver a comparar códigos por su cuenta.
 *
 * QUÉ RECONOCE
 *
 *   - `23505` · `unique_violation` de PostgreSQL (motor único).
 *   - `23000` · `integrity_constraint_violation`, la familia que engloba la
 *     violación de unicidad en el motor retirado.
 *   - Errno `1062` · `ER_DUP_ENTRY` de MySQL/MariaDB, tolerado mientras quede
 *     cualquier ruta heredada.
 *
 * IDENTIFICACIÓN DE LA RESTRICCIÓN
 *
 *   PDO no publica el nombre de la restricción de forma estructurada: `errorInfo[0]`
 *   trae el SQLSTATE y `errorInfo[2]` el texto del motor, que es donde PostgreSQL
 *   nombra el índice (`duplicate key value violates unique constraint "…"`) y MariaDB
 *   la clave (`Duplicate entry '…' for key '…'`). Por eso `matches()` combina las dos
 *   preguntas —¿es violación de unicidad? y ¿el mensaje nombra ESTA identidad?— y
 *   devuelve `false` ante la duda: una traducción a negocio jamás puede capturar una
 *   violación de clave ajena ni un error que no sea de unicidad.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class UniqueViolationDetector
{
    /** SQLSTATE de PostgreSQL para `unique_violation`. */
    public const SQLSTATE_UNIQUE_VIOLATION = '23505';

    /** SQLSTATE de integridad que engloba la unicidad en el motor retirado. */
    public const SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION = '23000';

    /** Errno `ER_DUP_ENTRY` de MySQL/MariaDB (tolerancia declarada). */
    public const DRIVER_ERROR_DUPLICATE_ENTRY = 1062;

    /**
     * ¿La excepción es una violación de unicidad del motor?
     *
     * Sólo responde que sí con un código estructurado: `23505`, `23000` o el errno
     * `1062`. Nunca inspecciona el texto —eso es competencia de `mentions()`—, de
     * modo que un mensaje que se parezca a un duplicado no puede colarse.
     */
    public static function isUniqueViolation(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $sqlState = self::sqlStateOf($exception);
        if ($sqlState === self::SQLSTATE_UNIQUE_VIOLATION
            || $sqlState === self::SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION
        ) {
            return true;
        }

        return self::driverErrorCodeOf($exception) === self::DRIVER_ERROR_DUPLICATE_ENTRY;
    }

    /**
     * ¿El mensaje del motor nombra alguna de las identidades indicadas?
     *
     * Acepta nombres de índice o de columna, porque cada motor nombra el conflicto a
     * su manera. La comparación es sobre el mensaje completo, en minúsculas, para no
     * depender del entrecomillado de cada versión del motor.
     */
    public static function mentions(Throwable $exception, string ...$identities): bool
    {
        $message = strtolower($exception->getMessage());
        if ($message === '') {
            return false;
        }

        foreach ($identities as $identity) {
            $needle = strtolower(trim($identity));
            if ($needle !== '' && str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pregunta completa: ¿es una violación de unicidad **de esta** restricción?
     *
     * Es la forma que deben usar los traductores de negocio. Sin identidades
     * declaradas se comporta como `isUniqueViolation()`; con ellas, exige que el
     * mensaje nombre al menos una, de modo que dos índices únicos del mismo cuadro
     * no compartan traducción por accidente.
     */
    public static function matches(Throwable $exception, string ...$identities): bool
    {
        if (!self::isUniqueViolation($exception)) {
            return false;
        }

        if ($identities === []) {
            return true;
        }

        return self::mentions($exception, ...$identities);
    }

    /**
     * SQLSTATE efectivo del error, prefiriendo el que PDO publica en `errorInfo`.
     */
    public static function sqlStateOf(Throwable $exception): string
    {
        if (!$exception instanceof PDOException) {
            return '';
        }

        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];

        return (string)($errorInfo[0] ?? $exception->getCode());
    }

    /**
     * Errno del controlador (`errorInfo[1]`), o `null` si el motor no lo publica
     * —PostgreSQL lo omite—.
     */
    public static function driverErrorCodeOf(Throwable $exception): ?int
    {
        if (!$exception instanceof PDOException) {
            return null;
        }

        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $driverCode = $errorInfo[1] ?? null;

        return is_numeric($driverCode) ? (int)$driverCode : null;
    }
}
