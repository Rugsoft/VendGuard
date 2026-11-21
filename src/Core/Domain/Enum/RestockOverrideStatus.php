<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockOverrideStatus
 *
 * Ciclo de vida trazable de una excepción diaria de ruta (Módulo 37, columna
 * `restock_route_daily_overrides.status`, tipo nativo #48, C1, Art. III.1).
 *
 * Una excepción no se borra: se resuelve.
 *
 *   ACTIVE ──── visita física real ───▶ SUPERSEDED
 *      │
 *      └──── revocación manual o baja del receptor ───▶ REVOKED
 *
 * `SUPERSEDED` solo nace de una visita `COMPLETED` o `ABORTED_THERMAL_BREACH`
 * del Módulo 34 y conserva `superseded_by_visit_id`; `REVOKED` deja siempre
 * rastro de quién y cuándo resolvió. El índice único parcial
 * `uq_restock_override_active_machine_day` solo cubre `ACTIVE`, de modo que el
 * histórico completo permanece auditable.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockOverrideStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUPERSEDED = 'SUPERSEDED';
    case REVOKED = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activa',
            self::SUPERSEDED => 'Superada por visita',
            self::REVOKED => 'Revocada',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * ¿La excepción ya está resuelta y conserva su histórico?
     */
    public function isTerminal(): bool
    {
        return $this !== self::ACTIVE;
    }

    public function isSuperseded(): bool
    {
        return $this === self::SUPERSEDED;
    }

    public function isRevoked(): bool
    {
        return $this === self::REVOKED;
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
        $status = self::tryFrom(strtoupper(trim($value)));
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                "Estado de excepción de ruta no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
