<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\SanitarySweepEscalationDto;
use VendGuard\Application\DTO\SanitarySweepVisibilityDto;
use VendGuard\Application\DTO\SanitarySweepWindowDto;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Core\Service\SanitarySweepEscalationPolicy;
use VendGuard\Infrastructure\Repository\PdoSanitarySweepVisibilityRepository;

/**
 * SanitarySweepVisibilityService — Caso de uso de la **superficie operativa del
 * barrido sanitario** (T-PAUSE-41, RF-07.1 a RF-07.5, Art. II y Art. III).
 *
 * Es de **sólo lectura**: no decide, no escribe y no toca el reloj sanitario.
 * Compone la respuesta de la Coordinación a partir de tres lecturas del libro —
 * la última pasada, las pasadas de la ventana de tendencia y los seguimientos
 * abiertos— y delega **toda** la regla en `SanitarySweepEscalationPolicy`, que es
 * la única autoridad del escalado, igual que el DTO del barrido lo es de la
 * alerta del planificador.
 *
 * Ventana de tendencia declarada: **24 horas** (96 pasadas con la cadencia
 * ratificada de 15 minutos). El número vive en la constante
 * `TREND_WINDOW_HOURS` y viaja en la respuesta, para que la interfaz pueda
 * etiquetar los agregados sin conocerlo de antemano.
 *
 * Un instante de referencia **único** para toda la respuesta: todas las
 * antigüedades de la superficie se miden contra el mismo `generated_at`, de modo
 * que dos filas de la misma respuesta no puedan quedar medidas contra relojes
 * distintos (RNF-01).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin escrituras.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class SanitarySweepVisibilityService
{
    /** Ventana de tendencia de los agregados, en horas. */
    public const TREND_WINDOW_HOURS = 24;

    /** Zona operativa del sistema: la misma que usan el reloj y la auditoría. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Libro de pasadas y seguimientos; se resuelve en el primer uso si no se inyecta. */
    private ?SanitarySweepVisibilityRepositoryInterface $repository;

    /** Regla del escalado; se resuelve en el primer uso si no se inyecta. */
    private ?SanitarySweepEscalationPolicy $policy;

    public function __construct(
        ?SanitarySweepVisibilityRepositoryInterface $repository = null,
        ?SanitarySweepEscalationPolicy $policy = null
    ) {
        $this->repository = $repository;
        $this->policy = $policy;
    }

    /**
     * Fotografía de la contención del barrido para la Coordinación.
     *
     * @param DateTimeImmutable|null $now Instante de referencia (por defecto, el del sistema).
     * @return SanitarySweepVisibilityDto Payload canónico de la superficie.
     */
    public function snapshot(?DateTimeImmutable $now = null): SanitarySweepVisibilityDto
    {
        $reference = $now ?? new DateTimeImmutable('now', new DateTimeZone(self::OPERATIONAL_TIMEZONE));

        $lastPass = $this->repository()->findLatestPass();
        $passes = $this->repository()->findPassesSince(
            $reference->modify(sprintf('-%d hours', self::TREND_WINDOW_HOURS))
        );
        $contentions = $this->repository()->findOpenContentions();

        $minutesSinceLastPass = $lastPass?->minutesSinceStart($reference);

        // Escalado de parque: la ÚLTIMA pasada por encima del umbral de aplazamientos.
        // Se mide sobre el latido y no sobre la ventana a propósito: la señal es «ahora
        // mismo hay un escritor sosteniendo filas de varias máquinas», no «hubo un pico
        // esta mañana».
        $fleetEscalation = $this->policy()->isFleetEscalation($lastPass?->contendedCount ?? 0);

        $escalated = 0;
        foreach ($contentions as $contention) {
            if ($contention->isEscalated()) {
                $escalated++;
            }
        }

        $level = $this->policy()->level(count($contentions), $escalated, $fleetEscalation);

        return new SanitarySweepVisibilityDto(
            generatedAt: $reference->format('Y-m-d H:i:s'),
            cadenceMinutes: SanitarySweepEscalationPolicy::CADENCE_MINUTES,
            escalation: new SanitarySweepEscalationDto(
                level: $level,
                requiresAttention: $this->policy()->requiresAttention($level),
                consecutivePassesThreshold: SanitarySweepEscalationPolicy::CONSECUTIVE_PASSES_THRESHOLD,
                perPassThreshold: SanitarySweepEscalationPolicy::PER_PASS_THRESHOLD,
                openContentions: count($contentions),
                escalatedContentions: $escalated,
                fleetEscalation: $fleetEscalation,
                levelLabel: $this->policy()->levelLabel($level),
            ),
            lastPass: $lastPass,
            minutesSinceLastPass: $minutesSinceLastPass,
            isStale: $this->policy()->isSweepStale($minutesSinceLastPass),
            window: $this->buildWindow($passes),
            contentions: $contentions,
        );
    }

    /**
     * Agregados de la ventana de tendencia (RF-07.1).
     *
     * @param list<\VendGuard\Core\Domain\Model\SanitarySweepPass> $passes
     */
    private function buildWindow(array $passes): SanitarySweepWindowDto
    {
        $withContention = 0;
        $deferrals = 0;
        $failures = 0;
        $quarantines = 0;

        foreach ($passes as $pass) {
            if ($pass->hasContention()) {
                $withContention++;
            }

            $deferrals += $pass->contendedCount;
            $failures += $pass->failuresCount;
            $quarantines += $pass->quarantinedCount;
        }

        return new SanitarySweepWindowDto(
            windowHours: self::TREND_WINDOW_HOURS,
            passesInWindow: count($passes),
            passesWithContention: $withContention,
            deferralsInWindow: $deferrals,
            failuresInWindow: $failures,
            quarantinesInWindow: $quarantines,
            contentionRatePercent: SanitarySweepWindowDto::ratePercent(count($passes), $withContention),
        );
    }

    private function repository(): SanitarySweepVisibilityRepositoryInterface
    {
        return $this->repository ??= new PdoSanitarySweepVisibilityRepository();
    }

    private function policy(): SanitarySweepEscalationPolicy
    {
        return $this->policy ??= new SanitarySweepEscalationPolicy();
    }
}
