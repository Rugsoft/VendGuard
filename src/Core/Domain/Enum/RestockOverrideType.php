<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockOverrideType
 *
 * Naturaleza de una excepción diaria de ruta (Módulo 37, columna
 * `restock_route_daily_overrides.override_type`, tipo nativo #47, RF-02.1, C1).
 *
 * Solo existen dos formas de alterar la ruta de un día sin tocar la agenda:
 *
 *   SKIPPED    ─ la parada se omite con causa tipificada y motivo escrito
 *   REASSIGNED ─ la parada se desvía a otro reponedor durante ese día
 *
 * Cada forma arrastra sus propios campos obligatorios, que el motor impone en
 * `chk_rrdo_type_fields_coherence`: la omisión exige `skip_reason_code` y un
 * `reason_text` de 20 a 500 caracteres reales (Art. V.1), mientras el desvío
 * exige `target_restocker_id` y `reassignment_reason_code` con nota opcional.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockOverrideType: string
{
    case SKIPPED = 'SKIPPED';
    case REASSIGNED = 'REASSIGNED';

    public function label(): string
    {
        return match ($this) {
            self::SKIPPED => 'Omitida',
            self::REASSIGNED => 'Reasignada',
        };
    }

    public function isSkipped(): bool
    {
        return $this === self::SKIPPED;
    }

    public function isReassigned(): bool
    {
        return $this === self::REASSIGNED;
    }

    /**
     * ¿La excepción exige causa tipificada de omisión?
     */
    public function requiresSkipReasonCode(): bool
    {
        return $this === self::SKIPPED;
    }

    /**
     * ¿La excepción exige un reponedor destino?
     */
    public function requiresTargetRestocker(): bool
    {
        return $this === self::REASSIGNED;
    }

    /**
     * ¿La excepción exige motivo escrito de 20 caracteres reales?
     */
    public function requiresReasonText(): bool
    {
        return $this === self::SKIPPED;
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
        $type = self::tryFrom(strtoupper(trim($value)));
        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                "Tipo de excepción de ruta no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $type;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
