<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\ValueObject\SlaTier;

/**
 * UncoveredStopComparator
 *
 * Orden determinista de la bandeja «Paradas sin cobertura hoy» (Módulo 37,
 * EARS 03.3.1, C2, QA-4). La jerarquía es la misma que la de la ruta asignada:
 *
 *   1. Bloque FEFO: `earliest_fefo_expiration_date` ascendente.
 *   2. Bloque Demanda: `SlaTier` y, dentro del mismo nivel, `L1_INCIDENT`
 *      antes que `EMPTY_SLOT`.
 *   3. Bloque Calendario: `preferred_time_window` (MORNING → MIDDAY →
 *      AFTERNOON → ANY).
 *
 * El desempate final por `machine_id` garantiza un orden estable incluso
 * cuando dos máquinas comparten bloque y clave. No hay Haversine aquí: la
 * bandeja no tiene un origen de ruta y su prioridad es operativa, no de
 * proximidad.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin E/S ni dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class UncoveredStopComparator
{
    /** Orden canónico de los tres bloques del contrato. */
    private const BLOCK_RANK = [
        RestockRouteOptimizer::BLOCK_FEFO_SANITARY => 0,
        RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT => 1,
        RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE => 2,
    ];

    /** Fecha centinela para un FEFO sin caducidad declarada: va al final. */
    private const UNKNOWN_FEFO_DATE = '9999-12-31';

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public static function compare(array $left, array $right): int
    {
        $block = (self::BLOCK_RANK[$left['priority_block'] ?? ''] ?? 9)
            <=> (self::BLOCK_RANK[$right['priority_block'] ?? ''] ?? 9);
        if ($block !== 0) {
            return $block;
        }

        $blockName = $left['priority_block'] ?? '';
        if ($blockName === RestockRouteOptimizer::BLOCK_FEFO_SANITARY) {
            $date = self::fefoDate($left) <=> self::fefoDate($right);
            if ($date !== 0) {
                return $date;
            }
        } elseif ($blockName === RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT) {
            $tier = self::slaTierRank($left['sla_tier'] ?? 'STANDARD')
                <=> self::slaTierRank($right['sla_tier'] ?? 'STANDARD');
            if ($tier !== 0) {
                return $tier;
            }

            $kind = self::demandKindRank($left) <=> self::demandKindRank($right);
            if ($kind !== 0) {
                return $kind;
            }
        } elseif ($blockName === RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE) {
            $window = self::windowRank($left['preferred_time_window'] ?? 'ANY')
                <=> self::windowRank($right['preferred_time_window'] ?? 'ANY');
            if ($window !== 0) {
                return $window;
            }
        }

        return (int)($left['machine_id'] ?? 0) <=> (int)($right['machine_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $stop
     */
    private static function fefoDate(array $stop): string
    {
        $date = $stop['earliest_fefo_expiration_date'] ?? null;

        return is_string($date) && $date !== '' ? $date : self::UNKNOWN_FEFO_DATE;
    }

    private static function slaTierRank(mixed $value): int
    {
        return match (SlaTier::fromString((string)$value)) {
            SlaTier::CRITICAL_INFRASTRUCTURE_24_7 => 0,
            SlaTier::PRIORITY_CORPORATE => 1,
            SlaTier::STANDARD => 2,
        };
    }

    /**
     * @param array<string, mixed> $stop
     */
    private static function demandKindRank(array $stop): int
    {
        $triggers = is_array($stop['triggers'] ?? null) ? $stop['triggers'] : [];

        return in_array(RestockRouteOptimizer::TRIGGER_L1_INCIDENT, $triggers, true) ? 0 : 1;
    }

    private static function windowRank(mixed $value): int
    {
        return RestockTimeWindow::fromString((string)$value)->windowOrder();
    }
}
