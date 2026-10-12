<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Model\SanitarySweepContention;

/**
 * SanitarySweepVisibilityDto — Respuesta canónica de la **superficie operativa
 * del barrido sanitario** (T-PAUSE-41, RF-07).
 *
 * Es la única forma que lee la Coordinación (`GET /api/coordinator/sanitary-sweep/contention`)
 * y responde a la pregunta que la respuesta del planificador no puede responder,
 * porque el planificador no es su destinatario: *¿está corriendo el barrido, está
 * aplazando trabajo y desde cuándo?*.
 *
 * Las tres claves de primer nivel separan tres lecturas distintas de la misma
 * realidad, para que la interfaz no las mezcle:
 * 1. `escalation`: la **alerta** (nivel, si exige atención y con qué umbrales).
 * 2. `sweep`: el **latido** y la **tendencia** —última pasada, minutos
 *    transcurridos, si el barrido dejó de correr y los agregados de 24 h—.
 * 3. `contentions`: los **seguimientos abiertos**, uno por expediente aplazado,
 *    con sus pasadas consecutivas y su antigüedad.
 *
 * `lastPass` es `null` cuando el barrido **nunca** ha dejado una pasada —un
 * arranque nuevo— y en ese caso `minutesSinceLastPass` también lo es: la
 * interfaz distingue «no ha corrido todavía» (`lastPass` nulo) de «lleva más de
 * dos cadencias sin correr» (`isStale`), que son dos incidentes distintos.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitarySweepVisibilityDto implements JsonSerializable
{
    /**
     * @param string $generatedAt Instante único de referencia de toda la respuesta.
     * @param int $cadenceMinutes Cadencia operativa ratificada del planificador.
     * @param SanitarySweepEscalationDto $escalation Bloque de alerta.
     * @param \VendGuard\Core\Domain\Model\SanitarySweepPass|null $lastPass Última pasada, o `null`.
     * @param int|null $minutesSinceLastPass Minutos desde la última pasada, o `null`.
     * @param bool $isStale Si el barrido lleva más de dos cadencias sin latir.
     * @param SanitarySweepWindowDto $window Agregados de la ventana de tendencia.
     * @param list<SanitarySweepContention> $contentions Seguimientos abiertos.
     *
     * @throws InvalidArgumentException Si la cadencia no es positiva o si el latido
     *                                  se declara de forma incoherente.
     */
    public function __construct(
        public string $generatedAt,
        public int $cadenceMinutes,
        public SanitarySweepEscalationDto $escalation,
        public ?\VendGuard\Core\Domain\Model\SanitarySweepPass $lastPass,
        public ?int $minutesSinceLastPass,
        public bool $isStale,
        public SanitarySweepWindowDto $window,
        public array $contentions,
    ) {
        if ($cadenceMinutes < 1) {
            throw new InvalidArgumentException(
                'La cadencia del barrido sanitario debe ser positiva para poder medir su latido.'
            );
        }

        if ($lastPass === null && $minutesSinceLastPass !== null) {
            throw new InvalidArgumentException(
                'La superficie del barrido no puede medir minutos sin pasada: si nunca corrió, no hay latido que medir.'
            );
        }
    }

    /** ¿La superficie pide que alguien mire? (Autoridad única del bloque de alerta.) */
    public function requiresAttention(): bool
    {
        return $this->escalation->requiresAttention;
    }

    /**
     * Serialización canónica del contrato (`plan.md` §2.6): las claves de primer
     * nivel viajan siempre y las antigüedades de cada seguimiento se calculan
     * contra el **mismo** instante de referencia, para que dos filas de la misma
     * respuesta no se midan contra relojes distintos.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reference = new \DateTimeImmutable($this->generatedAt, new \DateTimeZone('Europe/Madrid'));

        return [
            'generated_at' => $this->generatedAt,
            'cadence_minutes' => $this->cadenceMinutes,
            'escalation' => $this->escalation->toArray(),
            'sweep' => [
                'last_pass' => $this->lastPass?->toArray(),
                'minutes_since_last_pass' => $this->minutesSinceLastPass,
                'is_stale' => $this->isStale,
            ] + $this->window->toArray(),
            'contentions' => array_map(
                static fn (SanitarySweepContention $contention): array => $contention->toArray($reference),
                $this->contentions
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
