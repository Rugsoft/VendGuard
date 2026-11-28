<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * DexSelectionMatchStatus
 *
 * Resultado del emparejamiento de una selección `PA1`/`PA2` con un carril del
 * planograma (Módulo 39, Tabla 53, `machine_dex_selection_lines.match_status`,
 * enum #53, RF-01.1, C4, QA-4).
 *
 * La matriz es cerrada y tiene dos mitades: los dos emparejamientos reales
 * (`EXACT_MATCH`, `LEADING_ZERO_MATCH`) llevan carril y snapshot de código; los
 * tres restantes (`AMBIGUOUS`, `UNMAPPED`, `HOT_DRINKS_SELECTION`) nunca
 * inventan una correspondencia. `requiresSlotMatch()` es la misma regla que
 * `chk_machine_dex_selection_lines_match_coherence`, y `HOT_DRINKS_SELECTION`
 * es informativo: el café no se empareja con carriles de espiral.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum DexSelectionMatchStatus: string
{
    case EXACT_MATCH = 'EXACT_MATCH';
    case LEADING_ZERO_MATCH = 'LEADING_ZERO_MATCH';
    case AMBIGUOUS = 'AMBIGUOUS';
    case UNMAPPED = 'UNMAPPED';
    case HOT_DRINKS_SELECTION = 'HOT_DRINKS_SELECTION';

    public function label(): string
    {
        return match ($this) {
            self::EXACT_MATCH => 'Coincidencia exacta',
            self::LEADING_ZERO_MATCH => 'Coincidencia con ceros a la izquierda',
            self::AMBIGUOUS => 'Coincidencia ambigua',
            self::UNMAPPED => 'Sin carril en el planograma',
            self::HOT_DRINKS_SELECTION => 'Selección de café (informativa)',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EXACT_MATCH => '✅',
            self::LEADING_ZERO_MATCH => '🟦',
            self::AMBIGUOUS => '⚠️',
            self::UNMAPPED => '❔',
            self::HOT_DRINKS_SELECTION => '☕',
        };
    }

    /**
     * ¿El estado exige carril y snapshot de código? Espejo del `CHECK` del motor.
     */
    public function requiresSlotMatch(): bool
    {
        return $this === self::EXACT_MATCH || $this === self::LEADING_ZERO_MATCH;
    }

    public function isMatched(): bool
    {
        return $this->requiresSlotMatch();
    }

    public function isInformational(): bool
    {
        return $this === self::HOT_DRINKS_SELECTION;
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
                "Estado de emparejamiento de selección no válido: '%s'. Valores permitidos: %s.",
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
