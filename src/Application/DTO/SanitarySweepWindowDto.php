<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * SanitarySweepWindowDto — Bloque de **tendencia** de la superficie operativa del
 * barrido sanitario (T-PAUSE-41, RF-07.1).
 *
 * Agrega el libro de pasadas de las últimas 24 horas. Sirve para lo que un
 * recuento instantáneo no puede dar: ver una contención que **crece** antes de
 * que llegue a escalar, y distinguir una contención puntual de un parque con un
 * escritor reteniendo filas de forma sostenida.
 *
 * La tasa de contención se publica ya redondeada a dos decimales —el porcentaje
 * de pasadas de la ventana que tuvieron al menos un aplazamiento—, y viaja
 * calculada desde aquí para que la interfaz no repita la aritmética.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitarySweepWindowDto implements JsonSerializable
{
    /**
     * @param int $windowHours Ventana agregada, en horas.
     * @param int $passesInWindow Pasadas registradas en la ventana.
     * @param int $passesWithContention Pasadas que aplazaron al menos un candidato.
     * @param int $deferralsInWindow Aplazamientos acumulados de la ventana.
     * @param int $failuresInWindow Fallos reales acumulados de la ventana.
     * @param int $quarantinesInWindow Cuarentenas activadas en la ventana.
     * @param float $contentionRatePercent Porcentaje de pasadas con aplazamiento.
     *
     * @throws InvalidArgumentException Si algún recuento es negativo o la tasa no
     *                                  está en el rango [0, 100].
     */
    public function __construct(
        public int $windowHours,
        public int $passesInWindow,
        public int $passesWithContention,
        public int $deferralsInWindow,
        public int $failuresInWindow,
        public int $quarantinesInWindow,
        public float $contentionRatePercent,
    ) {
        if ($windowHours < 1 || $passesInWindow < 0 || $passesWithContention < 0
            || $passesWithContention > $passesInWindow || $deferralsInWindow < 0
            || $failuresInWindow < 0 || $quarantinesInWindow < 0
            || $contentionRatePercent < 0.0 || $contentionRatePercent > 100.0) {
            throw new InvalidArgumentException(
                'El bloque de tendencia del barrido sanitario no admite recuentos incoherentes.'
            );
        }
    }

    /**
     * Tasa de contención de la ventana, redondeada a dos decimales.
     *
     * Vive aquí —y no en el servicio— para que la definición del porcentaje
     * (pasadas **con** aplazamiento sobre pasadas registradas) no se pueda
     * implementar de dos maneras distintas en dos sitios.
     */
    public static function ratePercent(int $passesInWindow, int $passesWithContention): float
    {
        if ($passesInWindow < 1) {
            return 0.0;
        }

        return round($passesWithContention * 100 / $passesInWindow, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'window_hours' => $this->windowHours,
            'passes_in_window' => $this->passesInWindow,
            'passes_with_contention' => $this->passesWithContention,
            'deferrals_in_window' => $this->deferralsInWindow,
            'failures_in_window' => $this->failuresInWindow,
            'quarantines_in_window' => $this->quarantinesInWindow,
            'contention_rate_percent' => $this->contentionRatePercent,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
