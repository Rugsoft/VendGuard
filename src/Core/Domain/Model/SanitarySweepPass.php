<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * SanitarySweepPass — Registro inmutable de **una pasada** del barrido del Reloj
 * Sanitario Biológico (T-PAUSE-41, RF-07.1, Constitución Art. II y Art. III).
 *
 * Es el **latido** de la salvaguarda sanitaria: la única evidencia de que el
 * proceso de fondo está corriendo. Hasta T-PAUSE-41 las tres situaciones que la
 * Coordinación necesita distinguir se veían exactamente igual desde el panel
 * —«el barrido corre y no encuentra nada», «el barrido corre y aplaza
 * candidatos» y «el barrido no está corriendo»—, porque el único sitio donde la
 * pasada se publicaba era la respuesta del planificador.
 *
 * Los recuentos son **los del resumen de la pasada** (`SanitaryClockSweepResultDto`),
 * copiados tal cual: no se recalculan ni se reinterpretan aquí. La identidad
 * contable del resumen (`candidates = evaluated + failures + contended`) la
 * sigue verificando el DTO, que es su única autoridad.
 *
 * `finished_at` nulo significa que la pasada **no llegó a terminar** (murió a
 * mitad, con las cuarentenas ya confirmadas que haya por delante): es un dato
 * operativo real y por eso se publica en lugar de inventar una marca de cierre.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitarySweepPass implements JsonSerializable
{
    /**
     * @param int|null $id Identificador de la pasada en el libro (`null` antes de persistirla).
     * @param string $startedAt Marca de apertura de la pasada (`Y-m-d H:i:s`).
     * @param string|null $finishedAt Marca de cierre, o `null` si la pasada no terminó.
     * @param int $candidates Candidatos publicados por el prefiltro.
     * @param int $evaluated Candidatos efectivamente evaluados por el reloj.
     * @param int $quarantinedCount Máquinas que quedaron en cuarentena tras la pasada.
     * @param int $contendedCount Candidatos aplazados por contención de fila.
     * @param int $failuresCount Candidatos con fallo real (lo único que alerta).
     * @param float $durationMs Duración medida de la pasada, en milisegundos.
     *
     * @throws InvalidArgumentException Si algún recuento o la duración son negativos.
     */
    public function __construct(
        public ?int $id,
        public string $startedAt,
        public ?string $finishedAt,
        public int $candidates,
        public int $evaluated,
        public int $quarantinedCount,
        public int $contendedCount,
        public int $failuresCount,
        public float $durationMs,
    ) {
        if ($candidates < 0 || $evaluated < 0 || $quarantinedCount < 0
            || $contendedCount < 0 || $failuresCount < 0 || $durationMs < 0.0) {
            throw new InvalidArgumentException(
                'El registro de una pasada del barrido sanitario no admite recuentos ni duraciones negativas.'
            );
        }

        if ($quarantinedCount > $evaluated) {
            throw new InvalidArgumentException(
                'Una pasada no puede declarar más cuarentenas que candidatos evaluados (identidad del resumen).'
            );
        }
    }

    /** ¿La pasada se quedó a mitad? (Su cierre nunca se escribió.) */
    public function isInterrupted(): bool
    {
        return $this->finishedAt === null;
    }

    /** ¿Hubo aplazamientos por contención en esta pasada? (Visible, sin alerta.) */
    public function hasContention(): bool
    {
        return $this->contendedCount > 0;
    }

    /** Minutos transcurridos desde la apertura de la pasada. */
    public function minutesSinceStart(DateTimeImmutable $now): int
    {
        $started = new DateTimeImmutable($this->startedAt, $now->getTimezone());
        $seconds = $now->getTimestamp() - $started->getTimestamp();

        return $seconds < 0 ? 0 : (int)intdiv($seconds, 60);
    }

    /**
     * Serialización canónica del contrato (`plan.md` §2.6): las nueve claves
     * viajan siempre, con la misma forma, para que la superficie operativa no
     * tenga que ramificar por campos ausentes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pass_id' => $this->id,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'candidates' => $this->candidates,
            'evaluated' => $this->evaluated,
            'quarantined_count' => $this->quarantinedCount,
            'contended_count' => $this->contendedCount,
            'failures_count' => $this->failuresCount,
            'duration_ms' => $this->durationMs,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
