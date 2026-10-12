<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Model\SanitarySweepContention;

/**
 * SanitarySweepEscalationPolicy — Regla **pura** del escalado de la contención
 * del barrido sanitario (T-PAUSE-41, RF-07.3 a RF-07.5, Constitución Art. II).
 *
 * Es la única autoridad sobre **cuándo un aplazamiento deja de ser operación
 * normal y pasa a ser una alerta**, y sobre **cuándo esa alerta se apaga**. Vive
 * aquí y no en SQL ni en el controlador por el mismo motivo por el que
 * `SanitaryClockSweepResultDto::requiresOperatorAttention()` es la única
 * autoridad de la alerta del planificador: una sola regla, un solo sitio, y se
 * puede probar sin base de datos.
 *
 * Los umbrales salen de la Capa D de
 * `docs/propuesta_politica_reintento_barrido_sanitario.md`, ratificada en su
 * recomendación (decisión D3):
 *
 * 1. **Tres pasadas consecutivas** (≈45 min con la cadencia ratificada de 15
 *    minutos). Un aplazamiento aislado es la prueba de que otro consumidor está
 *    actuando sobre la fila ahora mismo y no alerta (T-PAUSE-39); tres pasadas
 *    seguidas ya no pueden explicarse por el tenedor corto **medido** —p95 de
 *    2,54 ms en 120 ejecuciones reales, T-PAUSE-40—: es un escritor reteniendo
 *    filas de máquina.
 * 2. **Más de cinco aplazamientos en una sola pasada**: escalado de **parque**.
 *    Esa cifra no describe un solape casual, sino un escritor sosteniendo filas
 *    de varias máquinas a la vez.
 *
 * Nada de esto cambia el resultado del barrido ni el código de salida del
 * planificador (decisión D4): el escalado es una señal para la Coordinación, que
 * es quien puede actuar sobre el escritor que retiene las filas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin I/O.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class SanitarySweepEscalationPolicy
{
    /** Pasadas consecutivas a partir de las cuales el aplazamiento escala (≈45 min). */
    public const CONSECUTIVE_PASSES_THRESHOLD = 3;

    /** Aplazamientos en una sola pasada que elevan la señal a escalado de parque. */
    public const PER_PASS_THRESHOLD = 5;

    /** Cadencia operativa ratificada del planificador, en minutos (RF-03.5). */
    public const CADENCE_MINUTES = 15;

    /** Sin seguimientos abiertos: no hay nada que mirar. */
    public const LEVEL_CLEAR = 'CLEAR';

    /** Hay aplazamientos vivos, ninguno persistente: se observa, no se escala. */
    public const LEVEL_MONITORED = 'MONITORED';

    /** Al menos un expediente escaló, o la última pasada superó el umbral de parque. */
    public const LEVEL_ESCALATED = 'ESCALATED';

    /**
     * ¿El episodio vigente alcanzó el umbral de pasadas consecutivas?
     */
    public function shouldEscalate(int $consecutivePasses): bool
    {
        return $consecutivePasses >= self::CONSECUTIVE_PASSES_THRESHOLD;
    }

    /**
     * ¿Una pasada con este número de aplazamientos es una señal de parque?
     */
    public function isFleetEscalation(int $contendedCount): bool
    {
        return $contendedCount > self::PER_PASS_THRESHOLD;
    }

    /**
     * Siguiente estado del seguimiento tras una detección (RF-07.2).
     *
     * Distingue los tres casos que el barrido puede encontrarse:
     * 1. **Sin seguimiento previo**: arranca el episodio en 1 pasada.
     * 2. **Episodio abierto**: otra pasada consecutiva; el escalado se sella la
     *    primera vez que se alcanza el umbral y ya no se vuelve a tocar (la marca
     *    es del episodio, no de la pasada).
     * 3. **Episodio cerrado** (una pasada anterior ya no lo aplazó): el
     *    aplazamiento es un episodio **nuevo** —contadores a 1, marcas limpias—
     *    que conserva el acumulado histórico en `total_passes`.
     *
     * @param SanitarySweepContention|null $current Seguimiento vigente, o `null` si es el primero.
     * @param int $incidentId Expediente aplazado.
     * @param int $machineId Máquina cuyo bloqueo sostiene el otro consumidor.
     * @param DateTimeImmutable $detectedAt Instante de esta detección.
     * @return SanitarySweepContention Estado resultante (sin persistir: `id` es nulo).
     *
     * @throws InvalidArgumentException Si el expediente o la máquina no son válidos.
     */
    public function nextDetection(
        ?SanitarySweepContention $current,
        int $incidentId,
        int $machineId,
        DateTimeImmutable $detectedAt
    ): SanitarySweepContention {
        $moment = $detectedAt->format('Y-m-d H:i:s');

        if ($current === null) {
            $consecutive = 1;
            $total = 1;
            $first = $moment;
            $escalatedAt = $this->shouldEscalate($consecutive) ? $moment : null;
        } elseif ($current->isOpen()) {
            $consecutive = $current->consecutivePasses + 1;
            $total = $current->totalPasses + 1;
            $first = $current->firstDetectedAt;
            $escalatedAt = $current->escalatedAt
                ?? ($this->shouldEscalate($consecutive) ? $moment : null);
        } else {
            // Episodio nuevo tras una resolución: los contadores del episodio
            // arrancan de cero, el acumulado histórico no.
            $consecutive = 1;
            $total = $current->totalPasses + 1;
            $first = $moment;
            $escalatedAt = $this->shouldEscalate($consecutive) ? $moment : null;
        }

        return new SanitarySweepContention(
            id: $current?->id,
            incidentId: $incidentId,
            machineId: $machineId,
            firstDetectedAt: $first,
            lastDetectedAt: $moment,
            consecutivePasses: $consecutive,
            totalPasses: $total,
            escalatedAt: $escalatedAt,
            resolvedAt: null,
        );
    }

    /**
     * Nivel del aviso de la superficie (RF-07.5).
     *
     * @param int $openContentions Seguimientos abiertos.
     * @param int $escalatedContentions Seguimientos abiertos ya escalados.
     * @param bool $fleetEscalation Última pasada por encima del umbral de parque.
     */
    public function level(int $openContentions, int $escalatedContentions, bool $fleetEscalation): string
    {
        if ($escalatedContentions > 0 || $fleetEscalation) {
            return self::LEVEL_ESCALATED;
        }

        return $openContentions > 0 ? self::LEVEL_MONITORED : self::LEVEL_CLEAR;
    }

    /**
     * ¿La superficie debe destacar el aviso? Es la **única** autoridad de la
     * alerta, igual que el DTO del barrido lo es de la del planificador.
     */
    public function requiresAttention(string $level): bool
    {
        return $level === self::LEVEL_ESCALATED;
    }

    /** Copy castellano del nivel, para que la interfaz no traduzca en dos sitios. */
    public function levelLabel(string $level): string
    {
        return match ($level) {
            self::LEVEL_ESCALATED => 'Contención persistente: la detección sanitaria se está retrasando',
            self::LEVEL_MONITORED => 'Aplazamientos vivos: operación normal, sin escalado',
            default => 'Sin aplazamientos: el barrido no está encontrando contención',
        };
    }

    /**
     * ¿El barrido ha dejado de latir? Más de **dos cadencias** sin ninguna pasada
     * registrada: la salvaguarda sanitaria no está corriendo, que es una
     * situación distinta de «no encuentra nada» (RF-07.1).
     */
    public function isSweepStale(?int $minutesSinceLastPass): bool
    {
        if ($minutesSinceLastPass === null) {
            return false; // Nunca ha corrido: la superficie lo dice con su propia clave.
        }

        return $minutesSinceLastPass > self::CADENCE_MINUTES * 2;
    }
}
