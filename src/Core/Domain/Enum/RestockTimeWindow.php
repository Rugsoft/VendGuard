<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockTimeWindow
 *
 * Franja horaria tipificada de acceso y carga de una parada (Módulo 37,
 * columna `machine_restock_schedules.preferred_time_window`, tipo nativo #46,
 * RF-01.1, D6).
 *
 * La franja no es una cita: es la preferencia con la que el optimizador ordena
 * el bloque `SCHEDULED_CADENCE` antes de aplicar la distancia Haversine
 * (`RF-03.2`). Por eso el enumerado declara un peso determinista y no una hora
 * concreta: dos agendas con la misma franja se desempatan por cercanía, nunca
 * por el orden de inserción.
 *
 *   MORNING (1) → MIDDAY (2) → AFTERNOON (3) → ANY (4)
 *
 * `ANY` pesa al final a propósito: una parada sin preferencia no debe
 * adelantar a una que declaró ventana.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockTimeWindow: string
{
    case MORNING = 'MORNING';
    case MIDDAY = 'MIDDAY';
    case AFTERNOON = 'AFTERNOON';
    case ANY = 'ANY';

    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Mañana',
            self::MIDDAY => 'Mediodía',
            self::AFTERNOON => 'Tarde',
            self::ANY => 'Cualquiera',
        };
    }

    /**
     * Peso de ordenación de la franja: menor significa antes en la ruta.
     */
    public function windowOrder(): int
    {
        return match ($this) {
            self::MORNING => 1,
            self::MIDDAY => 2,
            self::AFTERNOON => 3,
            self::ANY => 4,
        };
    }

    public function isAny(): bool
    {
        return $this === self::ANY;
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
        $window = self::tryFrom(strtoupper(trim($value)));
        if ($window === null) {
            throw new InvalidArgumentException(sprintf(
                "Franja horaria no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $window;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
