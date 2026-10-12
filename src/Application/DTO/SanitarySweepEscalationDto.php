<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * SanitarySweepEscalationDto — Bloque de **alerta** de la superficie operativa
 * del barrido sanitario (T-PAUSE-41, RF-07.3 a RF-07.5).
 *
 * Es el bloque que la Coordinación lee para saber si tiene que mirar algo y qué
 * umbral se aplicó para decidirlo: la regla vive en
 * `SanitarySweepEscalationPolicy`, y aquí viaja ya resuelta junto con los
 * umbrales vigentes, para que la interfaz no vuelva a derivarla ni pueda quedar
 * desfasada de ella si algún día se revisan los valores.
 *
 * `requiresAttention` es la **única autoridad** de la alerta en esta superficie,
 * igual que `SanitaryClockSweepResultDto::requiresOperatorAttention()` lo es de
 * la alerta del planificador: un solo criterio, un solo sitio.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitarySweepEscalationDto implements JsonSerializable
{
    /**
     * @param string $level Nivel del aviso (`CLEAR`, `MONITORED` o `ESCALATED`).
     * @param bool $requiresAttention Si la superficie debe destacar la alerta.
     * @param int $consecutivePassesThreshold Umbral de pasadas consecutivas aplicado.
     * @param int $perPassThreshold Umbral de aplazamientos en una pasada (parque).
     * @param int $openContentions Seguimientos abiertos.
     * @param int $escalatedContentions Seguimientos abiertos ya escalados.
     * @param bool $fleetEscalation Si la última pasada superó el umbral de parque.
     * @param string $levelLabel Copy castellano del nivel, resuelto por la política.
     *
     * @throws InvalidArgumentException Si el nivel no es uno de los tres declarados
     *                                  o si algún recuento es negativo.
     */
    public function __construct(
        public string $level,
        public bool $requiresAttention,
        public int $consecutivePassesThreshold,
        public int $perPassThreshold,
        public int $openContentions,
        public int $escalatedContentions,
        public bool $fleetEscalation,
        public string $levelLabel,
    ) {
        if (!in_array($level, ['CLEAR', 'MONITORED', 'ESCALATED'], true)) {
            throw new InvalidArgumentException(
                'El nivel de escalado de la contención del barrido sanitario no es uno de los declarados.'
            );
        }

        if ($openContentions < 0 || $escalatedContentions < 0
            || $escalatedContentions > $openContentions
            || $consecutivePassesThreshold < 1 || $perPassThreshold < 1) {
            throw new InvalidArgumentException(
                'El bloque de escalado del barrido sanitario no admite recuentos negativos ni umbrales no positivos.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'requires_attention' => $this->requiresAttention,
            'consecutive_passes_threshold' => $this->consecutivePassesThreshold,
            'per_pass_threshold' => $this->perPassThreshold,
            'open_contentions' => $this->openContentions,
            'escalated_contentions' => $this->escalatedContentions,
            'fleet_escalation' => $this->fleetEscalation,
            'level_label' => $this->levelLabel,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
