<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * DexEventSeverity
 *
 * Gravedad de un evento `EA1`/`EA2` del volcado `.dex` (Módulo 39, Tabla 54,
 * `machine_dex_event_lines.severity`, enum #55, RF-05.1, D4, QA-4).
 *
 * Tres niveles, en el orden del enumerado del motor: `CRITICAL`, `WARNING`,
 * `INFO`. `CRITICAL` es la gravedad con la que el catálogo marca todo evento
 * térmico (`EG*`), y `weight()` permite ordenar la bandeja del Coordinador sin
 * que cada capa reescriba la prelación a mano.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum DexEventSeverity: string
{
    case CRITICAL = 'CRITICAL';
    case WARNING = 'WARNING';
    case INFO = 'INFO';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Crítica',
            self::WARNING => 'Advertencia',
            self::INFO => 'Informativa',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CRITICAL => '🚨',
            self::WARNING => '⚠️',
            self::INFO => 'ℹ️',
        };
    }

    /**
     * Peso de ordenación de la bandeja: mayor es más grave.
     */
    public function weight(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::WARNING => 2,
            self::INFO => 1,
        };
    }

    public function isCritical(): bool
    {
        return $this === self::CRITICAL;
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
        $severity = self::tryFrom(strtoupper(trim($value)));
        if ($severity === null) {
            throw new InvalidArgumentException(sprintf(
                "Severidad de evento .dex no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $severity;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
