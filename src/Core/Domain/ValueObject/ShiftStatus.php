<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * ShiftStatus
 *
 * Estados del ciclo de jornada diaria de un técnico en ruta (Módulo 21).
 *
 *   - ON_SHIFT: En turno / Disponible para asignación y emergencias.
 *   - MEAL_BREAK: Pausa de comida / Temporalmente no disponible.
 *   - OFF_SHIFT: Fuera de turno / Jornada finalizada o descanso.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ShiftStatus: string
{
    case ON_SHIFT = 'ON_SHIFT';
    case MEAL_BREAK = 'MEAL_BREAK';
    case OFF_SHIFT = 'OFF_SHIFT';

    public function isAvailable(): bool
    {
        return $this === self::ON_SHIFT;
    }

    public function label(): string
    {
        return match ($this) {
            self::ON_SHIFT => 'En turno',
            self::MEAL_BREAK => 'Pausa comida',
            self::OFF_SHIFT => 'Fuera de turno',
        };
    }
}
