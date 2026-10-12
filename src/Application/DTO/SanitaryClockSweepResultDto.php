<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * SanitaryClockSweepResultDto — Resumen inmutable de una pasada del barrido del
 * Reloj Sanitario Biológico (T-PAUSE-38, RF-03.5.1).
 *
 * Es la respuesta única de los dos consumidores de fondo —el endpoint
 * `POST /api/cron/sanitary-clock-sweep` y el CLI `bin/sanitary_clock_sweep.php`—,
 * de modo que el programador, el panel de operación y la consola lean la misma
 * forma y con las mismas claves:
 *
 * - `candidates`: expedientes que publicó el prefiltro del repositorio. Es una
 *   medida de capacidad, **no** de decisión: el reloj puede descartarlos todos.
 * - `evaluated`: candidatos que el reloj llegó a evaluar (candidatos − fallos).
 * - `quarantined`: máquinas que el reloj reporta en cuarentena tras la evaluación.
 *   El disparo de **esta** pasada queda sellado, además, como evento
 *   `SANITARY_QUARANTINE_AUTO_TRIGGERED` en la bitácora inmutable (Art. III), que es
 *   la fuente de verdad de qué máquina entró en cuarentena y cuándo.
 * - `skipped`: evaluados que no requieren cuarentena (umbral no alcanzado en la
 *   relectura del reloj, o expediente liquidado en carrera con otro consumidor).
 * - `failures` y `failures_count`: candidatos cuya evaluación no pudo decidirse
 *   (tipología o apertura ilegibles) o cuya escritura no se pudo persistir. Nunca se
 *   silencian: viajan en la respuesta y el CLI termina con código distinto de cero
 *   para que el planificador pueda alertar.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitaryClockSweepResultDto implements JsonSerializable
{
    /**
     * @param int $candidates Expedientes publicados por el prefiltro de candidatos.
     * @param int $evaluated Candidatos efectivamente evaluados por el reloj.
     * @param list<array{incident_id: int|null, ticket_code: string, machine_id: int}> $quarantined
     *   Máquinas que el reloj reporta en cuarentena tras la pasada.
     * @param int $skipped Evaluados que no requieren cuarentena.
     * @param list<array{incident_id: int|null, ticket_code: string, reason: string}> $failures
     *   Candidatos cuya evaluación o persistencia no pudo completarse.
     * @param int $failuresCount Número de fallos del lote (espejo de `failures`).
     * @param int $thresholdSeconds Umbral del reloj aplicado en esta pasada.
     * @param float $durationMs Duración medida de la pasada, en milisegundos.
     *
     * @throws InvalidArgumentException Si algún recuento es negativo, si el umbral no
     *                                  es positivo o si el espejo de fallos no cuadra.
     */
    public function __construct(
        public int $candidates,
        public int $evaluated,
        public array $quarantined,
        public int $skipped,
        public array $failures,
        public int $failuresCount,
        public int $thresholdSeconds,
        public float $durationMs,
    ) {
        if ($candidates < 0 || $evaluated < 0 || $skipped < 0 || $failuresCount < 0 || $thresholdSeconds < 1) {
            throw new InvalidArgumentException(
                'El resumen del barrido sanitario no admite recuentos negativos ni un umbral no positivo (Art. II).'
            );
        }

        if ($failuresCount !== count($failures)) {
            throw new InvalidArgumentException(
                'El resumen del barrido sanitario no puede declarar un número de fallos distinto del que publica.'
            );
        }
    }

    /**
     * Serialización canónica del contrato (plan.md §2.5): las ocho claves viajan
     * siempre, con la misma forma, para que el programador y el CLI puedan leerlas
     * sin ramas condicionales.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'candidates' => $this->candidates,
            'evaluated' => $this->evaluated,
            'quarantined' => $this->quarantined,
            'skipped' => $this->skipped,
            'failures' => $this->failures,
            'failures_count' => $this->failuresCount,
            'threshold_seconds' => $this->thresholdSeconds,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * Serialización JSON nativa (`json_encode`) con la misma estructura canónica.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
