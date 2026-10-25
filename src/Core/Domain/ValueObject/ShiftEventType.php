<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * ShiftEventType
 *
 * Eventos canónicos de la bitácora inmutable `technician_shift_logs`
 * (Módulo 22).
 *
 * La bitácora registra la evidencia de jornada; los cuatro eventos manuales
 * son los que ejecuta el técnico desde la ruta, mientras que
 * FORCE_CLOSE_SHIFT y ADJUST_SHIFT documentan una intervención de
 * Coordinación, siempre con motivo escrito y sin borrar la marca original.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ShiftEventType: string
{
    case START_SHIFT = 'START_SHIFT';
    case PAUSE_MEAL = 'PAUSE_MEAL';
    case RESUME_SHIFT = 'RESUME_SHIFT';
    case CLOSE_SHIFT = 'CLOSE_SHIFT';
    case FORCE_CLOSE_SHIFT = 'FORCE_CLOSE_SHIFT';
    case ADJUST_SHIFT = 'ADJUST_SHIFT';

    public function label(): string
    {
        return match ($this) {
            self::START_SHIFT => 'Inicio de turno',
            self::PAUSE_MEAL => 'Inicio de pausa de comida',
            self::RESUME_SHIFT => 'Reanudación de turno',
            self::CLOSE_SHIFT => 'Cierre de turno por técnico',
            self::FORCE_CLOSE_SHIFT => 'Cierre forzado por Coordinación',
            self::ADJUST_SHIFT => 'Rectificación horaria por Coordinación',
        };
    }
}
