<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use JsonSerializable;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;

/**
 * VendingProduct
 *
 * Entidad inmutable del catálogo maestro comercial y de consumibles (Módulo 32,
 * Tabla 42, RF-01.1, C1, C2, D3, QA 1).
 *
 * Invariantes que muerde en el borde de la entidad, antes de que la fila llegue
 * al motor:
 *   1. `sku` se normaliza en mayúsculas y cumple `^[A-Z0-9_-]{3,32}$`; es la
 *      identidad inmutable de la referencia (C1).
 *   2. `name` exige entre 2 y 120 caracteres reales.
 *   3. Coste unitario en `(0,00, 9 999,99]` y PVP en `[0,00, 9 999,99]`; las
 *      categorías de venta directa exigen PVP estrictamente positivo (C2).
 *   4. La unidad de medida pertenece al catálogo de la categoría (QA 1).
 *   5. `PERISHABLE_FOOD` exige cadena de frío y vida útil; con frío, la vida útil
 *      es obligatoria entre 1 y 3650 días (D3, QA 1).
 *
 * Cada cambio devuelve una copia (`withId()`, `withStatus()`), nunca muta la
 * instancia recibida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
final class VendingProduct implements JsonSerializable
{
    /** Forma canónica del SKU (C1, QA 1). */
    public const SKU_PATTERN = '/^[A-Z0-9_-]{3,32}$/';

    public const MIN_NAME_LENGTH = 2;
    public const MAX_NAME_LENGTH = 120;

    public const MIN_AMOUNT_EUR = 0.00;
    public const MAX_AMOUNT_EUR = 9999.99;

    public const MIN_SHELF_LIFE_DAYS = 1;
    public const MAX_SHELF_LIFE_DAYS = 3650;

    private ?int $id;

    private string $sku;

    private string $name;

    private ProductCategory $category;

    private ProductUnitType $unitType;

    private bool $requiresColdChain;

    private ?int $maxShelfLifeDays;

    private float $unitCostEur;

    private float $salePriceEur;

    private bool $isActive;

    private ?string $createdAt;

    private ?string $updatedAt;

    public function __construct(
        ?int $id,
        string $sku,
        string $name,
        ProductCategory $category,
        ProductUnitType $unitType,
        float $unitCostEur,
        float $salePriceEur,
        bool $requiresColdChain = false,
        ?int $maxShelfLifeDays = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $cleanSku = strtoupper(trim($sku));
        if (preg_match(self::SKU_PATTERN, $cleanSku) !== 1) {
            throw PlanogramValidationException::invalidProductSku($sku);
        }

        $cleanName = trim($name);
        $nameLength = mb_strlen($cleanName, 'UTF-8');
        if ($nameLength < self::MIN_NAME_LENGTH || $nameLength > self::MAX_NAME_LENGTH) {
            throw PlanogramValidationException::invalidProductName($nameLength);
        }

        $cost = round($unitCostEur, 2);
        if ($cost <= self::MIN_AMOUNT_EUR || $cost > self::MAX_AMOUNT_EUR) {
            throw PlanogramValidationException::invalidUnitCost($unitCostEur);
        }

        $price = round($salePriceEur, 2);
        $priceOutOfBounds = $price < self::MIN_AMOUNT_EUR || $price > self::MAX_AMOUNT_EUR;
        if ($priceOutOfBounds || ($category->isDirectRetail() && $price <= 0.00)) {
            throw PlanogramValidationException::invalidSalePrice($salePriceEur, $category->value);
        }

        if (!$category->allowsUnitType($unitType)) {
            throw PlanogramValidationException::invalidUnitType($category->value, $unitType->value);
        }

        if ($category->requiresColdChainByDefault() && !$requiresColdChain) {
            throw PlanogramValidationException::perishableRequiresColdChainAndShelfLife(
                $cleanSku,
                'PERISHABLE_FOOD exige requires_cold_chain = TRUE'
            );
        }

        if ($requiresColdChain && !$category->allowsColdChain()) {
            throw PlanogramValidationException::invalidColdChainCategory($category->value);
        }

        if ($maxShelfLifeDays !== null
            && ($maxShelfLifeDays < self::MIN_SHELF_LIFE_DAYS || $maxShelfLifeDays > self::MAX_SHELF_LIFE_DAYS)) {
            throw PlanogramValidationException::invalidShelfLife($maxShelfLifeDays);
        }

        if ($requiresColdChain && $maxShelfLifeDays === null) {
            throw PlanogramValidationException::perishableRequiresColdChainAndShelfLife(
                $cleanSku,
                'la cadena de frío exige max_shelf_life_days entre 1 y 3650 días'
            );
        }

        $this->id = $id;
        $this->sku = $cleanSku;
        $this->name = $cleanName;
        $this->category = $category;
        $this->unitType = $unitType;
        $this->requiresColdChain = $requiresColdChain;
        $this->maxShelfLifeDays = $maxShelfLifeDays;
        $this->unitCostEur = $cost;
        $this->salePriceEur = $price;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): ProductCategory
    {
        return $this->category;
    }

    public function getUnitType(): ProductUnitType
    {
        return $this->unitType;
    }

    public function requiresColdChain(): bool
    {
        return $this->requiresColdChain;
    }

    public function getMaxShelfLifeDays(): ?int
    {
        return $this->maxShelfLifeDays;
    }

    public function getUnitCostEur(): float
    {
        return $this->unitCostEur;
    }

    public function getSalePriceEur(): float
    {
        return $this->salePriceEur;
    }

    /**
     * Margen unitario bruto. Puede ser negativo en consumibles con PVP 0,00 €.
     */
    public function getUnitMarginEur(): float
    {
        return round($this->salePriceEur - $this->unitCostEur, 2);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Baja lógica o reactivación de la referencia (C1, Art. III.1).
     */
    public function withStatus(bool $isActive): self
    {
        $clone = clone $this;
        $clone->isActive = $isActive;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'unit_type' => $this->unitType->value,
            'unit_type_label' => $this->unitType->label(),
            'requires_cold_chain' => $this->requiresColdChain,
            'max_shelf_life_days' => $this->maxShelfLifeDays,
            'unit_cost_eur' => $this->unitCostEur,
            'sale_price_eur' => $this->salePriceEur,
            'unit_margin_eur' => $this->getUnitMarginEur(),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
