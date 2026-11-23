<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockCadenceType
 *
 * Frecuencia periódica de reposición de una agenda de máquina (Módulo 37,
 * columna `machine_restock_schedules.cadence_type`, tipo nativo #45, RF-01.1,
 * D6, QA-2).
 *
 * La cadencia no guarda zona ni reponedor (D2): declara **cuándo** toca
 * abastecer y el titular efectivo del día lo resuelve la cobertura de zona del
 * Módulo 31. Cada valor arrastra su propia coherencia estructural, que el
 * motor impone en `chk_mrs_cadence_coherence` y esta capa reproduce antes de
 * escribir:
 *
 *   DAILY        ─ todos los días laborables (o todos con `include_weekends`)
 *   MON_WED_FRI  ─ lunes, miércoles y viernes
 *   TUE_THU      ─ martes y jueves
 *   WEEKLY       ─ un día ISO concreto (`day_of_week` 1..7)
 *   BIWEEKLY     ─ cada 14 días desde una fecha ancla (`anchor_date`)
 *
 * `WEEKLY` exige `day_of_week` y prohíbe `anchor_date`; `BIWEEKLY` exige
 * `anchor_date` y prohíbe `day_of_week`; las tres cadencias fijas prohíben
 * ambas. Solo `DAILY` admite `include_weekends = TRUE`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockCadenceType: string
{
    case DAILY = 'DAILY';
    case MON_WED_FRI = 'MON_WED_FRI';
    case TUE_THU = 'TUE_THU';
    case WEEKLY = 'WEEKLY';
    case BIWEEKLY = 'BIWEEKLY';

    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'Diaria',
            self::MON_WED_FRI => 'Lunes, miércoles y viernes',
            self::TUE_THU => 'Martes y jueves',
            self::WEEKLY => 'Semanal',
            self::BIWEEKLY => 'Quincenal',
        };
    }

    /**
     * ¿La cadencia necesita un día ISO-8601 (1 = lunes, 7 = domingo)?
     */
    public function requiresDayOfWeek(): bool
    {
        return $this === self::WEEKLY;
    }

    /**
     * ¿La cadencia se cuenta desde una fecha ancla cada 14 días?
     */
    public function requiresAnchorDate(): bool
    {
        return $this === self::BIWEEKLY;
    }

    /**
     * ¿La cadencia admite `include_weekends = TRUE`? Solo la diaria.
     */
    public function allowsWeekends(): bool
    {
        return $this === self::DAILY;
    }

    /**
     * ¿La cadencia se define por días fijos de la semana, sin día ni ancla?
     */
    public function isFixedWeekdayPattern(): bool
    {
        return $this === self::DAILY || $this === self::MON_WED_FRI || $this === self::TUE_THU;
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
        $cadence = self::tryFrom(strtoupper(trim($value)));
        if ($cadence === null) {
            throw new InvalidArgumentException(sprintf(
                "Cadencia de reposición no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $cadence;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
