<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * ProductCategory
 *
 * Categorías comerciales del catálogo maestro de productos y consumibles
 * (Módulo 32, RF-01.1, C2, QA 1). Cada categoría decide qué unidad de medida
 * admite, si es venta directa al público y si puede exigir cadena de frío.
 *
 * La matriz de compatibilidad con la tipología de máquina vive en
 * `PlanogramCompatibilityValidator` y en el motor PostgreSQL: aquí sólo se
 * declaran las reglas propias del producto, para que no existan dos verdades.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ProductCategory: string
{
    case COLD_DRINK = 'COLD_DRINK';
    case SNACK = 'SNACK';
    case PERISHABLE_FOOD = 'PERISHABLE_FOOD';
    case HOT_BEVERAGE_INGREDIENT = 'HOT_BEVERAGE_INGREDIENT';
    case CUP_STIRRER_CONSUMABLE = 'CUP_STIRRER_CONSUMABLE';

    /**
     * Etiqueta legible para la consola de Coordinación y el móvil del reponedor.
     */
    public function label(): string
    {
        return match ($this) {
            self::COLD_DRINK => 'Bebida Fría',
            self::SNACK => 'Snack y Frutos Secos',
            self::PERISHABLE_FOOD => 'Alimento Perecedero Fresco',
            self::HOT_BEVERAGE_INGREDIENT => 'Ingrediente de Bebida Caliente',
            self::CUP_STIRRER_CONSUMABLE => 'Consumible y Vasos',
        };
    }

    /**
     * Categorías de venta directa: su PVP debe ser estrictamente positivo (C2).
     */
    public function isDirectRetail(): bool
    {
        return in_array($this, [
            self::COLD_DRINK,
            self::SNACK,
            self::PERISHABLE_FOOD,
        ], true);
    }

    /**
     * Categorías que exigen cadena de frío por su propia naturaleza (D3, QA 1).
     */
    public function requiresColdChainByDefault(): bool
    {
        return $this === self::PERISHABLE_FOOD;
    }

    /**
     * Categorías a las que el motor permite declarar `requires_cold_chain = TRUE`.
     */
    public function allowsColdChain(): bool
    {
        return in_array($this, [
            self::PERISHABLE_FOOD,
            self::COLD_DRINK,
            self::SNACK,
        ], true);
    }

    /**
     * Unidades de medida admitidas por la categoría (C2, QA 1).
     *
     * @return list<ProductUnitType>
     */
    public function allowedUnitTypes(): array
    {
        return match ($this) {
            self::COLD_DRINK, self::SNACK, self::PERISHABLE_FOOD => [ProductUnitType::UNIT],
            self::HOT_BEVERAGE_INGREDIENT => [ProductUnitType::GRAMS, ProductUnitType::UNIT],
            self::CUP_STIRRER_CONSUMABLE => [ProductUnitType::SLEEVE, ProductUnitType::UNIT],
        };
    }

    public function allowsUnitType(ProductUnitType $unitType): bool
    {
        return in_array($unitType, $this->allowedUnitTypes(), true);
    }

    /**
     * Comprueba si una cadena corresponde a una categoría del contrato.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye la categoría desde su forma canónica o lanza excepción.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $category = self::tryFrom(strtoupper(trim($value)));
        if ($category === null) {
            throw new InvalidArgumentException(sprintf(
                "Categoría de producto no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $category;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
