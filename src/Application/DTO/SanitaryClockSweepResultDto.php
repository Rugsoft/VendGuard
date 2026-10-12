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
 * - `contended` y `contended_count`: candidatos **aplazados por contención de fila**,
 *   es decir, cuya transición murió esperando el bloqueo exclusivo que otro consumidor
 *   —el cierre técnico en su puerta sanitaria, otro barrido o cualquier escritor del
 *   parque— sostiene sobre la MISMA máquina (T-PAUSE-39). No son fallos: la contención
 *   es la prueba de que otro consumidor está actuando ahora mismo sobre esa fila, y la
 *   transición idempotente del reloj garantiza que el rastro inmutable siga siendo uno.
 *   Se publican para que el planificador los vea, pero **no** encienden la alerta: el
 *   prefiltro vuelve a ofrecer al candidato en la pasada siguiente (la pérdida máxima
 *   es una cadencia) y así queda razonado en
 *   `docs/propuesta_politica_reintento_barrido_sanitario.md`.
 *
 * Este resumen es también el único lugar donde vive la **regla de alerta**
 * (`requiresOperatorAttention()`), para que el endpoint y el CLI no decidan por su
 * cuenta qué es un problema: una sola autoridad, un solo criterio.
 *
 * **Identidad contable (Fail-Fast).** `candidates` se reparte exactamente entre
 * `evaluated`, `failures_count` y `contended_count`; un resumen que no cuadre se
 * rechaza en el constructor en lugar de llegar al planificador como si fuera válido.
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
     * @param list<array{incident_id: int|null, ticket_code: string, reason: string}> $contended
     *   Candidatos aplazados por contención de fila (misma forma que `failures`).
     * @param int $contendedCount Número de aplazamientos por contención (espejo de `contended`).
     * @param int $thresholdSeconds Umbral del reloj aplicado en esta pasada.
     * @param float $durationMs Duración medida de la pasada, en milisegundos.
     *
     * @throws InvalidArgumentException Si algún recuento es negativo, si el umbral no
     *                                  es positivo, si alguno de los espejos no cuadra con
     *                                  su lista o si la identidad contable de la pasada
     *                                  (`candidates = evaluated + failures + contended`) falla.
     */
    public function __construct(
        public int $candidates,
        public int $evaluated,
        public array $quarantined,
        public int $skipped,
        public array $failures,
        public int $failuresCount,
        public array $contended,
        public int $contendedCount,
        public int $thresholdSeconds,
        public float $durationMs,
    ) {
        if ($candidates < 0 || $evaluated < 0 || $skipped < 0 || $failuresCount < 0
            || $contendedCount < 0 || $thresholdSeconds < 1) {
            throw new InvalidArgumentException(
                'El resumen del barrido sanitario no admite recuentos negativos ni un umbral no positivo (Art. II).'
            );
        }

        if ($failuresCount !== count($failures)) {
            throw new InvalidArgumentException(
                'El resumen del barrido sanitario no puede declarar un número de fallos distinto del que publica.'
            );
        }

        if ($contendedCount !== count($contended)) {
            throw new InvalidArgumentException(
                'El resumen del barrido sanitario no puede declarar un número de aplazamientos por contención distinto del que publica.'
            );
        }

        // Identidad contable de la pasada (Fail-Fast): cada candidato del prefiltro se
        // evaluó, se aplazó por contención o falló — nunca dos cosas a la vez y nunca
        // ninguna. Si no cuadra, el resumen es una mentira que el planificador no debe leer.
        if ($evaluated + $failuresCount + $contendedCount !== $candidates) {
            throw new InvalidArgumentException(sprintf(
                'El resumen del barrido sanitario no cuadra: %d candidatos, %d evaluados, %d fallos y %d aplazados por contención.',
                $candidates,
                $evaluated,
                $failuresCount,
                $contendedCount
            ));
        }
    }

    /**
     * Única autoridad de la **regla de alerta**: ¿esta pasada exige que alguien mire?
     *
     * Sólo los fallos reales encienden al planificador. La contención de fila NO alerta
     * aunque sí se publique: es un estado normal de operación —otro consumidor está
     * actuando sobre esa máquina— y el candidato se reintenta en la pasada siguiente,
     * que es exactamente lo que permite que la alerta conserve su valor (si cada solape
     * legítimo pintara la pasada de rojo, el aviso dejaría de distinguirse).
     *
     * La política de escalado de la contención vive fuera del código, en
     * `docs/propuesta_politica_reintento_barrido_sanitario.md`.
     */
    public function requiresOperatorAttention(): bool
    {
        return $this->failuresCount > 0;
    }

    /**
     * ¿Hubo aplazamientos por contención de fila? (Visible, pero sin alerta.)
     */
    public function hasContention(): bool
    {
        return $this->contendedCount > 0;
    }

    /**
     * Serialización canónica del contrato (plan.md §2.5): las diez claves viajan
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
            'contended' => $this->contended,
            'contended_count' => $this->contendedCount,
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
