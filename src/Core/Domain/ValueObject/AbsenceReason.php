<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * AbsenceReason
 *
 * Motivos tipificados para ausencias temporales de técnicos (Módulo 21).
 *
 * Catálogo cerrado:
 *   - VACATION: Vacaciones
 *   - SICK_LEAVE: Baja médica
 *   - TRAINING: Formación / Permiso
 *   - PERSONAL_LEAVE: Asuntos propios
 *   - COMPENSATORY: Descanso compensatorio
 *   - OTHER: Otra ausencia
 *
 * Dogma Vanilla: PHP 8.2+ puro, enum con respaldo de cadena (backed enum).
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum AbsenceReason: string
{
    case VACATION = 'VACATION';
    case SICK_LEAVE = 'SICK_LEAVE';
    case TRAINING = 'TRAINING';
    case PERSONAL_LEAVE = 'PERSONAL_LEAVE';
    case COMPENSATORY = 'COMPENSATORY';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::VACATION => 'Vacaciones',
            self::SICK_LEAVE => 'Baja médica',
            self::TRAINING => 'Formación / Permiso',
            self::PERSONAL_LEAVE => 'Asuntos propios',
            self::COMPENSATORY => 'Descanso compensatorio',
            self::OTHER => 'Otra ausencia',
        };
    }
}
