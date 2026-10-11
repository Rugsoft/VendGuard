<?php

declare(strict_types=1);

/**
 * VendGuard - Calendario comercial del arnés de pruebas (dogma Vanilla, sin dependencias)
 *
 * Implementación **independiente** de la jornada de la sede (08:00-18:00, lunes a
 * viernes, `Europe/Madrid`) para las suites que certifican el desplazamiento del
 * vencimiento contractual (RF-03.3, RNF-01).
 *
 * Por qué existe y por qué no se reutiliza el servicio de producción: desde el cierre
 * de F-3 el vencimiento se desplaza por el **tiempo hábil realmente perdido** durante
 * la pausa, no por su duración de pared. Una suite que calculase la expectativa
 * llamando a `IncidentPauseService::pauseBusinessSecondsSince()` comprobaría el
 * servicio contra sí mismo; esta clase reproduce la misma regla por separado, de modo
 * que un desvío de criterio entre ambos la pone en rojo. Es la misma práctica que el
 * proyecto ya aplica al contrastar el MTTR con SQL independiente.
 *
 * Las palabras prohibidas del arnés siguen vigentes: no toca la base de datos, no
 * debilita aserciones y no introduce dependencias externas.
 */
final class BusinessCalendar
{
    /** Apertura de la jornada comercial de la sede. */
    public const OPEN_HOUR = 8;

    /** Cierre de la jornada comercial de la sede. */
    public const CLOSE_HOUR = 18;

    /** Último día hábil de la semana en formato ISO-8601 (`1` = lunes, `5` = viernes). */
    public const LAST_BUSINESS_WEEKDAY = 5;

    /** Zona horaria operativa del parque. */
    public const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /**
     * Segundos hábiles transcurridos entre dos instantes, en el intervalo `[$from, $to)`.
     *
     * Recorre el calendario día a día y suma únicamente la ventana 08:00-18:00 de lunes a
     * viernes, recortada por los extremos reales del intervalo. Devuelve cero si el
     * intervalo está invertido o vacío, y cero si cae entero fuera de la jornada (fin de
     * semana, noche o madrugada), que es precisamente lo que certifica el cierre de F-3.
     *
     * @param DateTimeImmutable $from Extremo inicial (incluido).
     * @param DateTimeImmutable $to Extremo final (excluido).
     * @return int Segundos hábiles del intervalo; nunca negativo.
     */
    public static function secondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $cursor = $from->setTimezone(self::zone());
        $end = $to->setTimezone(self::zone());

        if ($end <= $cursor) {
            return 0;
        }

        $total = 0;

        while ($cursor < $end) {
            if ((int)$cursor->format('N') <= self::LAST_BUSINESS_WEEKDAY) {
                $opening = $cursor->setTime(self::OPEN_HOUR, 0, 0);
                $closing = $cursor->setTime(self::CLOSE_HOUR, 0, 0);

                // Ventana recortada por los extremos: ni el primer día se cuenta desde
                // las 08:00 si la pausa empezó a las 10:00, ni el último se cuenta hasta
                // las 18:00 si aún es mediodía.
                $windowStart = $cursor > $opening ? $cursor : $opening;
                $windowEnd = $end < $closing ? $end : $closing;

                if ($windowEnd > $windowStart) {
                    $total += $windowEnd->getTimestamp() - $windowStart->getTimestamp();
                }
            }

            // El cursor salta al arranque del día siguiente para no perder la primera
            // hora de cada jornada (un `+1 día` desde las 09:00 se comería las 08:00).
            $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);
        }

        return $total;
    }

    /**
     * Instante que queda exactamente a `$businessSeconds` segundos HÁBILES del instante de
     * referencia, caminando hacia atrás por el calendario comercial.
     *
     * Es la traducción inversa de `secondsBetween()` y permite sembrar una pausa cuyo
     * tiempo hábil perdido sea un valor conocido **sea cual sea el día y la hora reales**
     * en que se ejecute la suite: `secondsBetween(businessSecondsBefore($n), $reference)`
     * devuelve siempre `$n`.
     *
     * @param int $businessSeconds Segundos hábiles a retroceder (cero devuelve la referencia).
     * @param DateTimeImmutable|null $reference Instante de partida (por defecto, el reloj real).
     * @return DateTimeImmutable Instante en la zona horaria del parque.
     *
     * @throws InvalidArgumentException Si se pide un retroceso negativo.
     */
    public static function businessSecondsBefore(int $businessSeconds, ?DateTimeImmutable $reference = null): DateTimeImmutable
    {
        if ($businessSeconds < 0) {
            throw new InvalidArgumentException(
                'El calendario comercial de pruebas no retrocede segundos hábiles negativos.'
            );
        }

        $cursor = ($reference ?? new DateTimeImmutable('now'))->setTimezone(self::zone());
        $remaining = $businessSeconds;

        while ($remaining > 0) {
            $secondOfDay = (int)$cursor->format('G') * 3600
                + (int)$cursor->format('i') * 60
                + (int)$cursor->format('s');
            $openingSecond = self::OPEN_HOUR * 3600;
            $closingSecond = self::CLOSE_HOUR * 3600;

            if ((int)$cursor->format('N') > self::LAST_BUSINESS_WEEKDAY) {
                $cursor = $cursor->modify('-1 day')->setTime(self::CLOSE_HOUR, 0, 0);
                continue;
            }

            if ($secondOfDay > $closingSecond) {
                // Fuera de jornada por la tarde: el reloj retrocede al cierre del día.
                $cursor = $cursor->setTime(self::CLOSE_HOUR, 0, 0);
                continue;
            }

            if ($secondOfDay < $openingSecond) {
                // Madrugada: la jornada anterior terminó al cierre del día previo hábil.
                $cursor = $cursor->modify('-1 day')->setTime(self::CLOSE_HOUR, 0, 0);
                continue;
            }

            $available = $secondOfDay - $openingSecond;

            if ($remaining <= $available) {
                return $cursor->modify(sprintf('-%d seconds', $remaining));
            }

            $remaining -= $available;
            $cursor = $cursor->modify('-1 day')->setTime(self::CLOSE_HOUR, 0, 0);
        }

        return $cursor;
    }

    /**
     * Rango de tiempo hábil que una reanudación pudo medir sobre una pausa.
     *
     * El servicio lee el reloj real en algún instante entre la marca previa y la posterior a
     * la llamada HTTP, así que las suites acotan la expectativa en lugar de clavarla: ambas
     * orillas se calculan con el calendario independiente y el rango se colapsa a un único
     * valor en los bordes de la jornada (a las 08:00 o a las 18:00 no hay jornada que ganar).
     *
     * @param string $pausedAt Marca de pausa leída de la base (formato de base de datos).
     * @param DateTimeImmutable $before Instante inmediatamente anterior a la llamada.
     * @param DateTimeImmutable $after Instante inmediatamente posterior a la llamada.
     * @return array{0: int, 1: int} Mínimo y máximo de segundos hábiles esperados.
     */
    public static function shiftRange(string $pausedAt, DateTimeImmutable $before, DateTimeImmutable $after): array
    {
        $start = new DateTimeImmutable($pausedAt, self::zone());

        return [
            self::secondsBetween($start, $before),
            self::secondsBetween($start, $after),
        ];
    }

    /**
     * Jornada comercial del parque, para que las suites no repitan el literal de la zona.
     */
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::OPERATIONAL_TIMEZONE);
    }
}
