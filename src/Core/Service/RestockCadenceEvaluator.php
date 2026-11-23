<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\ValueObject\SlaTier;

/**
 * RestockCadenceEvaluator
 *
 * Calculador puro del calendario de reposición (Módulo 37, RF-01.2, D6, QA-6).
 * No lee la base, no consulta el reloj del sistema y no escribe nada: entran la
 * agenda, la fecha civil y el perfil contractual de la sede, y sale una decisión
 * determinista.
 *
 * Reglas que aplica, en el orden del Criterio EARS 01.2:
 *
 *   1. **Agenda viva y máquina operativa.** Una agenda inactiva no aplica nunca;
 *      una máquina en pausa estacional vigente tampoco (el motor de ruta la
 *      conserva solo para `FEFO_ALERT`, que no pasa por aquí).
 *   2. **`DAILY` adaptado al contrato.** Aplica todos los días en
 *      `CRITICAL_INFRASTRUCTURE_24_7`; con `include_weekends = TRUE` también de
 *      lunes a domingo; en `STANDARD` y `PRIORITY_CORPORATE` se limita a
 *      lunes–viernes.
 *   3. **Cadencias fijas.** `MON_WED_FRI` (1, 3, 5) y `TUE_THU` (2, 4) usan el
 *      día ISO-8601 de la fecha civil.
 *   4. **`WEEKLY` y `BIWEEKLY`.** La semanal compara `day_of_week`; la quincenal
 *      exige `service_date >= anchor_date` y un número de días naturales
 *      múltiplo exacto de `14`.
 *   5. **Aritmética DST-safe.** Las fechas se anclan a medianoche UTC
 *      (`!Y-m-d`) y se comparan por diferencia de días naturales, de modo que el
 *      cambio de hora de `Europe/Madrid` (marzo y octubre) no desplace ni un día
 *      el cómputo quincenal.
 *   6. **`nextScheduledDate`.** Devuelve la primera fecha estrictamente
 *      posterior que satisface la regla, o `anchor_date` directamente si la
 *      quincenal aún no ha empezado (aunque diste más de 15 días). Devuelve
 *      `null` si no hay agenda activa o la pausa estacional está vigente.
 *
 * La pausa estacional se decide con `isSeasonalPauseActive()` a partir de
 * `machines.is_seasonal_pause` y `seasonal_pause_until`: la pausa rige mientras
 * `seasonal_pause_until >= service_date` o mientras no tenga fecha de fin.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockCadenceEvaluator
{
    /** Zona horaria de negocio de todo el módulo (D6). */
    public const TIMEZONE = 'Europe/Madrid';

    /** Periodicidad exacta de la cadencia quincenal (EARS 01.2). */
    public const BIWEEKLY_PERIOD_DAYS = 14;

    /** Horizonte máximo de búsqueda de la próxima parada, en días naturales. */
    public const LOOKAHEAD_DAYS = 366;

    /** Días ISO-8601 de las cadencias fijas. */
    public const MON_WED_FRI_DAYS = [1, 3, 5];
    public const TUE_THU_DAYS = [2, 4];

    /** Forma canónica de una fecha civil del contrato. */
    private const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

    /**
     * Fecha civil de hoy en `Europe/Madrid` a partir del instante recibido.
     *
     * El instante se inyecta desde fuera para que el cálculo sea reproducible en
     * pruebas y en la proyección futura del tablero.
     */
    public static function today(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * ¿Sigue vigente la pausa estacional de la máquina en `serviceDate`?
     *
     * Una pausa sin fecha de fin rige indefinidamente; con fecha de fin, el
     * último día pausado es el propio `seasonal_pause_until` (`>= service_date`).
     */
    public static function isSeasonalPauseActive(
        bool $isSeasonalPause,
        ?string $seasonalPauseUntil,
        string $serviceDate
    ): bool {
        if (!$isSeasonalPause) {
            return false;
        }

        if ($seasonalPauseUntil === null) {
            return true;
        }

        return self::parseDate($seasonalPauseUntil) >= self::parseDate($serviceDate);
    }

    /**
     * ¿La agenda aplica en la fecha civil indicada? (Criterio EARS 01.2)
     *
     * @param bool $isSeasonallyPaused Pausa estacional ya resuelta para la fecha.
     *
     * @throws InvalidArgumentException Si alguna fecha no es civilmente válida.
     */
    public static function appliesOnDate(
        MachineRestockSchedule $schedule,
        string $serviceDate,
        SlaTier $slaTier,
        bool $isSeasonallyPaused = false
    ): bool {
        if (!$schedule->isActive() || $isSeasonallyPaused) {
            return false;
        }

        $date = self::parseDate($serviceDate);
        $weekday = (int)$date->format('N');

        return match ($schedule->getCadenceType()) {
            RestockCadenceType::DAILY => $slaTier === SlaTier::CRITICAL_INFRASTRUCTURE_24_7
                || $schedule->includesWeekends()
                || $weekday <= 5,
            RestockCadenceType::MON_WED_FRI => in_array($weekday, self::MON_WED_FRI_DAYS, true),
            RestockCadenceType::TUE_THU => in_array($weekday, self::TUE_THU_DAYS, true),
            RestockCadenceType::WEEKLY => $schedule->getDayOfWeek() === $weekday,
            RestockCadenceType::BIWEEKLY => self::isBiweeklyAnchorDay($schedule, $date),
        };
    }

    /**
     * Primera fecha civil estrictamente posterior que satisface la agenda.
     *
     * Devuelve `anchor_date` directamente cuando la quincenal todavía no ha
     * comenzado, aunque el ancla diste más de 15 días (`EARS 01.2`, `QA-6`).
     * Devuelve `null` sin agenda activa o con pausa estacional vigente.
     *
     * @throws InvalidArgumentException Si alguna fecha no es civilmente válida.
     */
    public static function nextScheduledDate(
        MachineRestockSchedule $schedule,
        string $serviceDate,
        SlaTier $slaTier,
        bool $isSeasonallyPaused = false,
        int $lookaheadDays = self::LOOKAHEAD_DAYS
    ): ?string {
        if (!$schedule->isActive() || $isSeasonallyPaused) {
            return null;
        }

        if ($lookaheadDays < 1) {
            throw new InvalidArgumentException('El horizonte de búsqueda debe ser de al menos un día.');
        }

        $date = self::parseDate($serviceDate);

        if ($schedule->getCadenceType() === RestockCadenceType::BIWEEKLY) {
            $anchorDate = $schedule->getAnchorDate();
            if ($anchorDate !== null && self::parseDate($anchorDate) > $date) {
                return $anchorDate;
            }
        }

        for ($offset = 1; $offset <= $lookaheadDays; $offset++) {
            $candidate = $date->modify(sprintf('+%d day', $offset))->format('Y-m-d');
            if (self::appliesOnDate($schedule, $candidate, $slaTier, false)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Días naturales transcurridos entre dos fechas civiles (DST-safe).
     *
     * El resultado es negativo si `$to` es anterior a `$from`.
     *
     * @throws InvalidArgumentException Si alguna fecha no es civilmente válida.
     */
    public static function daysBetween(string $from, string $to): int
    {
        return self::daysBetweenDates(self::parseDate($from), self::parseDate($to));
    }

    /**
     * Normaliza una fecha civil `YYYY-MM-DD` o rechaza el valor.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeServiceDate(string $value): string
    {
        return self::parseDate($value)->format('Y-m-d');
    }

    /**
     * Misma regla que `BIWEEKLY`: `service_date >= anchor_date` y múltiplo de 14.
     */
    private static function isBiweeklyAnchorDay(
        MachineRestockSchedule $schedule,
        DateTimeImmutable $serviceDate
    ): bool {
        $anchorDate = $schedule->getAnchorDate();
        if ($anchorDate === null) {
            // La entidad ya lo impide; el calculador degrada sin excepción.
            return false;
        }

        $anchor = self::parseDate($anchorDate);
        if ($serviceDate < $anchor) {
            return false;
        }

        return self::daysBetweenDates($anchor, $serviceDate) % self::BIWEEKLY_PERIOD_DAYS === 0;
    }

    private static function daysBetweenDates(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int)$from->diff($to)->format('%r%a');
    }

    /**
     * Ancla la fecha civil a medianoche UTC: el cambio de hora no altera el día.
     *
     * @throws InvalidArgumentException
     */
    private static function parseDate(string $value): DateTimeImmutable
    {
        $clean = trim($value);

        if (preg_match(self::DATE_PATTERN, $clean) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'La fecha «%s» debe cumplir el formato Y-m-d.',
                $value
            ));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $clean, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $clean) {
            throw new InvalidArgumentException(sprintf(
                'La fecha «%s» no es una fecha civil válida.',
                $value
            ));
        }

        return $date;
    }
}
