<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * ProductUnitType
 *
 * Unidad de medida con la que se carga y controla un producto o consumible
 * (Módulo 32, RF-01.1, C2, QA 1): unidad vendible, gramos de tolva o tira de
 * vasos/consumibles.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ProductUnitType: string
{
    case UNIT = 'UNIT';
    case GRAMS = 'GRAMS';
    case SLEEVE = 'SLEEVE';

    public function label(): string
    {
        return match ($this) {
            self::UNIT => 'Unidad',
            self::GRAMS => 'Gramos',
            self::SLEEVE => 'Tira',
        };
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
        $unit = self::tryFrom(strtoupper(trim($value)));
        if ($unit === null) {
            throw new InvalidArgumentException(sprintf(
                "Unidad de producto no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $unit;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
