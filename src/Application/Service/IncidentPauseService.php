<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * IncidentPauseService — Orquestación del estado operativo "Pendiente de
 * Información" (`PENDING_INFO`) con pausa de SLA (Módulo 11).
 *
 * Esta primera entrega (T-PAUSE-06) implementa el **Algoritmo 3: recálculo del
 * vencimiento contractual en ventana de horario hábil comercial** (RF-03.3),
 * aislado como función pura de calendario: sin persistencia, sin red y sin
 * dependencias externas, de modo que el cálculo sea verificable al segundo.
 * Las tareas siguientes (T-PAUSE-07 a T-PAUSE-09) añadirán a esta misma clase la
 * orquestación con repositorios (reloj sanitario, reactivación condicional y
 * cancelación por inactividad) sin que la firma de este método cambie.
 *
 * Regla de negocio que implementa:
 *
 * > Cuando una incidencia se reanuda, su fecha límite contractual
 * > (`sla_target_at`) se desplaza hacia adelante la duración exacta de la pausa,
 * > computada **únicamente** dentro de la ventana comercial de la sede
 * > (08:00 a 18:00, lunes a viernes). Nunca puede vencer de madrugada, en
 * > sábado ni en domingo: un cliente que cierra a las 18:00 no recibe un
 * > compromiso que expire un sábado a las 03:00 (RF-03.3).
 *
 * Decisiones de diseño anotadas para las tareas siguientes:
 *
 * 1. **Precisión de segundos (RNF-01):** la tarea fija la firma en segundos, así
 *    que el cómputo es aritmética de segundos y no de minutos. El pseudocódigo
 *    del `plan.md` §3.3 trabaja en minutos por legibilidad; convertirlo a
 *    segundos es lo que exige el requisito no funcional, no una desviación.
 * 2. **Zona horaria de la sede:** el calendario comercial se resuelve en
 *    `Europe/Madrid`, que es la zona operativa del sistema (la misma que usan
 *    `AuditEvent`, `MetricFilter` y `CoordinatorPreventiveDetailService`). La
 *    fecha devuelta viaja en esa zona, de modo que el `format('Y-m-d H:i:s')`
 *    que se persiste es siempre hora local de sede.
 * 3. **Ventana cerrada por arriba:** el intervalo legal es `[08:00, 18:00]`; un
 *    vencimiento exactamente a las 18:00 es el borde del último segundo hábil
 *    del día y se acepta. Cualquier instante posterior salta al siguiente día
 *    hábil a las 08:00.
 * 4. **La ventana es hoy uniforme para toda la red** (08:00-18:00, lunes a
 *    viernes), tal y como fija el requisito ("por defecto" en `plan.md` §3.3).
 *    El parámetro `$locationId` identifica la sede de forma explícita y se
 *    valida, para que un identificador inválido no aplique en silencio el
 *    calendario por defecto; es además el punto de extensión previsto para
 *    admitir calendarios por sede sin romper la firma.
 * 5. **Pausa de duración cero:** devuelve el vencimiento original intacto. Sin
 *    tiempo pausado no hay nada que desplazar y normalizar la fecha alteraría
 *    el contrato sin causa (caso límite §6.1 de la especificación funcional).
 *
 * Dogma Vanilla: PHP 8.2+ puro, tipado estricto, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y errores en castellano.
 */
final class IncidentPauseService
{
    /** Apertura de la jornada comercial de la sede. */
    private const BUSINESS_OPEN_HOUR = 8;

    /** Cierre de la jornada comercial de la sede. */
    private const BUSINESS_CLOSE_HOUR = 18;

    /** Zona horaria operativa del calendario comercial. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Último día hábil de la semana en formato ISO-8601 (`1` = lunes, `5` = viernes). */
    private const LAST_BUSINESS_WEEKDAY = 5;

    /**
     * Desplaza el vencimiento contractual sumando exclusivamente segundos hábiles
     * comerciales (08:00 a 18:00, lunes a viernes), saltando noches, fines de
     * semana y festivos semanales (RF-03.3, RNF-01).
     *
     * Ejemplos con la ventana por defecto:
     * - Jueves 12:15 con 90 minutos de pausa -> Jueves 13:45 (mismo día hábil).
     * - Jueves 17:30 con 60 minutos de pausa -> Viernes 08:30 (salto nocturno).
     * - Viernes 17:00 con 120 minutos de pausa -> Lunes 09:00 (salto de fin de semana).
     *
     * @param DateTimeImmutable $currentDeadline Vencimiento contractual vigente.
     * @param int $pauseDurationSeconds Duración exacta de la pausa, en segundos.
     * @param int $locationId Sede cuyo calendario comercial rige el cómputo.
     * @return DateTimeImmutable Nuevo vencimiento, expresado en la zona horaria de la sede.
     *
     * @throws InvalidArgumentException Si la sede no es válida o la duración es negativa.
     */
    public function shiftSlaTargetInBusinessHours(
        DateTimeImmutable $currentDeadline,
        int $pauseDurationSeconds,
        int $locationId
    ): DateTimeImmutable {
        if ($locationId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'El identificador de sede debe ser un entero positivo para resolver el calendario comercial; se recibió %d.',
                $locationId
            ));
        }

        if ($pauseDurationSeconds < 0) {
            throw new InvalidArgumentException(sprintf(
                'La duración de la pausa no puede ser negativa; se recibieron %d segundos.',
                $pauseDurationSeconds
            ));
        }

        if ($pauseDurationSeconds === 0) {
            return $currentDeadline;
        }

        $cursor = $currentDeadline->setTimezone($this->madridTimezone());
        $remainingSeconds = $pauseDurationSeconds;

        // Cada iteración consume, como máximo, una jornada comercial. El bucle
        // avanza un día hábil por vuelta como tope, así que su coste es
        // proporcional a los días hábiles abarcados por la pausa (para el umbral
        // de 72 horas hábiles, tres vueltas).
        while ($remainingSeconds > 0) {
            if (!$this->isBusinessDay($cursor)) {
                $cursor = $this->openingOfNextBusinessDay($cursor);
                continue;
            }

            $secondOfDay = $this->secondOfDay($cursor);
            $openingSecond = self::BUSINESS_OPEN_HOUR * 3600;
            $closingSecond = self::BUSINESS_CLOSE_HOUR * 3600;

            if ($secondOfDay < $openingSecond) {
                // Madrugada: el reloj arranca a la apertura del mismo día hábil.
                $cursor = $cursor->setTime(self::BUSINESS_OPEN_HOUR, 0, 0);
                continue;
            }

            if ($secondOfDay >= $closingSecond) {
                $cursor = $this->openingOfNextBusinessDay($cursor);
                continue;
            }

            $secondsUntilClosing = $closingSecond - $secondOfDay;

            if ($remainingSeconds <= $secondsUntilClosing) {
                return $cursor->modify(sprintf('+%d seconds', $remainingSeconds));
            }

            $remainingSeconds -= $secondsUntilClosing;
            $cursor = $this->openingOfNextBusinessDay($cursor);
        }

        return $cursor;
    }

    /**
     * Zona horaria operativa de la sede.
     */
    private function madridTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::OPERATIONAL_TIMEZONE);
    }

    /**
     * Indica si la fecha cae en día hábil (lunes a viernes).
     */
    private function isBusinessDay(DateTimeImmutable $moment): bool
    {
        return (int)$moment->format('N') <= self::LAST_BUSINESS_WEEKDAY;
    }

    /**
     * Segundos transcurridos desde la medianoche del día de la fecha dada.
     */
    private function secondOfDay(DateTimeImmutable $moment): int
    {
        return ((int)$moment->format('G')) * 3600
            + ((int)$moment->format('i')) * 60
            + (int)$moment->format('s');
    }

    /**
     * Apertura (08:00) del siguiente día hábil, saltando el fin de semana.
     *
     * Se construye siempre a partir de una fecha con la hora fijada, y los saltos
     * son de días completos a la misma hora de pared, de modo que el cambio de
     * horario estacional (madrugada de domingo) no altera la apertura comercial.
     */
    private function openingOfNextBusinessDay(DateTimeImmutable $moment): DateTimeImmutable
    {
        $opening = $moment->modify('+1 day')->setTime(self::BUSINESS_OPEN_HOUR, 0, 0);

        while (!$this->isBusinessDay($opening)) {
            $opening = $opening->modify('+1 day');
        }

        return $opening;
    }
}
