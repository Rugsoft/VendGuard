<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockSkipReasonCode
 *
 * Causas tipificadas de omisión de una parada (Módulo 37, columna
 * `restock_route_daily_overrides.skip_reason_code`, tipo nativo #49, RF-02.1,
 * QA-4, Art. V.1).
 *
 * La causa no sustituye al motivo escrito: el motor exige ambos en la rama
 * `SKIPPED`. La causa permite agregar y auditar sin leer texto libre; el motivo
 * de 20 a 500 caracteres reales explica el caso concreto y es lo que revisa
 * Coordinación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockSkipReasonCode: string
{
    case LOCATION_CLOSED = 'LOCATION_CLOSED';
    case NO_KEY_OR_ACCESS = 'NO_KEY_OR_ACCESS';
    case LOCAL_HOLIDAY = 'LOCAL_HOLIDAY';
    case FLEET_CAPACITY_SHORTAGE = 'FLEET_CAPACITY_SHORTAGE';
    case OTHER_OPERATIONAL = 'OTHER_OPERATIONAL';

    public function label(): string
    {
        return match ($this) {
            self::LOCATION_CLOSED => 'Sede cerrada',
            self::NO_KEY_OR_ACCESS => 'Sin llave o acceso',
            self::LOCAL_HOLIDAY => 'Festivo local',
            self::FLEET_CAPACITY_SHORTAGE => 'Falta de capacidad de flota',
            self::OTHER_OPERATIONAL => 'Otra causa operativa',
        };
    }

    /**
     * ¿La causa exige explicación escrita además del código?
     *
     * `OTHER_OPERATIONAL` es la única que no se explica por sí sola; el motor
     * exige motivo en todas las omisiones, así que esta ayuda es informativa
     * para la interfaz y no una dispensa.
     */
    public function requiresWrittenExplanation(): bool
    {
        return $this === self::OTHER_OPERATIONAL;
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
        $reason = self::tryFrom(strtoupper(trim($value)));
        if ($reason === null) {
            throw new InvalidArgumentException(sprintf(
                "Causa de omisión no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $reason;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
