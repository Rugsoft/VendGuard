<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * DexAuditStatus
 *
 * Ciclo de vida del volcado EVA-DTS `.dex` (Módulo 39, Tabla 52,
 * `machine_dex_audits.status`, enum #51, RF-01.1, D1, QA-4, Art. III.3).
 *
 * Solo existen dos estados y la transición es de un único sentido: un volcado
 * `ACTIVE` puede anularse lógicamente (`VOIDED`) con motivo real, y un volcado
 * anulado es terminal. La reimportación del mismo fichero no reabre la fila
 * histórica: crea otra fila `ACTIVE` y el índice único parcial de huella vigente
 * solo cuenta las activas (QA-4).
 *
 * `isActive()` coincide exactamente con el predicado de
 * `uq_machine_dex_audits_machine_hash` y de los índices de visita y recaudación:
 * las filas anuladas liberan la huella y el vínculo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum DexAuditStatus: string
{
    case ACTIVE = 'ACTIVE';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Vigente',
            self::VOIDED => 'Anulado',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ACTIVE => '✅',
            self::VOIDED => '🚫',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isVoided(): bool
    {
        return $this === self::VOIDED;
    }

    /**
     * Única transición admitida por `enforce_machine_dex_audits_immutability()`.
     */
    public function canTransitionTo(self $target): bool
    {
        return $this === self::ACTIVE && $target === self::VOIDED;
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
                "Estado de volcado .dex no válido: '%s'. Valores permitidos: %s.",
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
