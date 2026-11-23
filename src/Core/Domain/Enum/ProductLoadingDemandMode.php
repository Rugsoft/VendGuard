<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * ProductLoadingDemandMode
 *
 * Modo de cálculo de la propuesta matutina de carga (Módulo 33, Tabla 45,
 * `product_loading_demand_mode`, RF-02.1, D1, QA 4).
 *
 * La diferencia es de alcance, no de formato: `ALERTS_ONLY` reclama solo los
 * carriles agotados o por debajo del umbral (el modo conservador por defecto),
 * `ALL_GAPS` reclama todos los huecos del planograma y `TODAY_ROUTE` reclama
 * esos mismos huecos llenables pero **solo** de las máquinas `PENDING` de la
 * ruta efectiva del día (Módulo 37, `D5`, `QA-5`). El motor guarda el modo en
 * la orden para que la Hoja de Picking y la auditoría expliquen por qué se pidió
 * lo que se pidió.
 *
 * `includesAllGaps()` mide el alcance de **flota**: sigue siendo exclusivo de
 * `ALL_GAPS`. La restricción de `TODAY_ROUTE` a la ruta efectiva la aplica el
 * servicio del Módulo 33 al componer la propuesta (T-RRP-08), no este
 * enumerado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ProductLoadingDemandMode: string
{
    case ALERTS_ONLY = 'ALERTS_ONLY';
    case ALL_GAPS = 'ALL_GAPS';
    case TODAY_ROUTE = 'TODAY_ROUTE';

    public function label(): string
    {
        return match ($this) {
            self::ALERTS_ONLY => 'Solo alertas',
            self::ALL_GAPS => 'Ruta completa',
            self::TODAY_ROUTE => 'Ruta efectiva de hoy',
        };
    }

    /**
     * ¿La propuesta incluye también los carriles en estado correcto?
     */
    public function includesAllGaps(): bool
    {
        return $this === self::ALL_GAPS;
    }

    public function isAlertsOnly(): bool
    {
        return $this === self::ALERTS_ONLY;
    }

    /**
     * ¿La propuesta se limita a las máquinas `PENDING` de la ruta efectiva del
     * día? La restricción se aplica en el servicio del Módulo 33 (T-RRP-08).
     */
    public function isTodayRoute(): bool
    {
        return $this === self::TODAY_ROUTE;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $mode = self::tryFrom(strtoupper(trim($value)));
        if ($mode === null) {
            throw new InvalidArgumentException(sprintf(
                "Modo de demanda no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $mode;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
