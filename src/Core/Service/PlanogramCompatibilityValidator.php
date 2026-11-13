<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\VendingProduct;

/**
 * PlanogramCompatibilityValidator
 *
 * Validador puro del cerrojo sanitario bidireccional y de la matriz
 * `MachineType × ProductCategory` (Módulo 32; Art. II, D3, QA 4, RF-03.1 y
 * RF-03.2).
 *
 * La matriz se declara UNA sola vez aquí y el DDL la replica en la función
 * inmutable `is_planogram_product_compatible_with_machine()`; el servicio de
 * aplicación y el motor tienen que rechazar exactamente los mismos pares, o la
 * defensa en profundidad dejaría de ser una sola regla (RNF-02).
 *
 * Reglas que muerde:
 *   1. **Cadena de frío (Art. II).** Un producto con `requires_cold_chain = TRUE`
 *      sólo se admite en `PERISHABLE_FOOD` o `COMBO`, sea cual sea su categoría.
 *   2. **Matriz de categorías (D3).** `HOT_DRINKS` sólo ingredientes y
 *      consumibles; `COLD_DRINKS` sólo bebidas frías; `SNACKS` snacks y bebidas
 *      frías; `PERISHABLE_FOOD` perecederos, bebidas frías y snacks; `COMBO`
 *      todas.
 *   3. **Cerrojo inverso (RF-03.2).** Editar un producto o cambiar la tipología
 *      de una máquina se rechaza si algún carril activo quedaría incompatible.
 *   4. **Identidad (C1).** El SKU no cambia tras el alta.
 *
 * El validador no escribe ni consulta la base: entra el agregado y sale una
 * excepción con el código exacto del contrato, o no sale nada.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class PlanogramCompatibilityValidator
{
    /**
     * Categorías admitidas por cada tipología de máquina (D3).
     *
     * @var array<string, list<ProductCategory>>
     */
    private const CATEGORY_MATRIX = [
        MachineType::HOT_DRINKS->value => [
            ProductCategory::HOT_BEVERAGE_INGREDIENT,
            ProductCategory::CUP_STIRRER_CONSUMABLE,
        ],
        MachineType::COLD_DRINKS->value => [
            ProductCategory::COLD_DRINK,
        ],
        MachineType::SNACKS->value => [
            ProductCategory::SNACK,
            ProductCategory::COLD_DRINK,
        ],
        MachineType::PERISHABLE_FOOD->value => [
            ProductCategory::PERISHABLE_FOOD,
            ProductCategory::COLD_DRINK,
            ProductCategory::SNACK,
        ],
        MachineType::COMBO->value => [
            ProductCategory::PERISHABLE_FOOD,
            ProductCategory::COLD_DRINK,
            ProductCategory::SNACK,
            ProductCategory::HOT_BEVERAGE_INGREDIENT,
            ProductCategory::CUP_STIRRER_CONSUMABLE,
        ],
    ];

    /** Tipologías que pueden alojar producto de cadena de frío (Art. II). */
    private const COLD_CHAIN_MACHINE_TYPES = [
        MachineType::PERISHABLE_FOOD,
        MachineType::COMBO,
    ];

    /**
     * ¿La pareja máquina-producto respeta la cadena de frío y la matriz D3?
     */
    public static function isCompatible(
        MachineType $machineType,
        ProductCategory $category,
        bool $requiresColdChain
    ): bool {
        if ($requiresColdChain && !in_array($machineType, self::COLD_CHAIN_MACHINE_TYPES, true)) {
            return false;
        }

        return in_array($category, self::CATEGORY_MATRIX[$machineType->value] ?? [], true);
    }

    /**
     * Categorías que una tipología admite según la matriz D3.
     *
     * @return list<ProductCategory>
     */
    public static function allowedCategoriesFor(MachineType $machineType): array
    {
        return self::CATEGORY_MATRIX[$machineType->value] ?? [];
    }

    /**
     * Cerrojo directo: asignar o mantener activo un carril (RF-03.1, 422).
     *
     * Con `$isNewAssignment = false` se permite la convivencia con un producto
     * dado de baja que ya estaba en el carril; el alta, el cambio de producto y
     * la reactivación exigen una referencia activa (RF-02.2).
     */
    public static function assertSlotAssignment(
        MachineType $machineType,
        VendingProduct $product,
        bool $isNewAssignment = true
    ): void {
        if ($isNewAssignment && !$product->isActive()) {
            throw PlanogramValidationException::productInactive($product->getSku());
        }

        if ($product->requiresColdChain() && !in_array($machineType, self::COLD_CHAIN_MACHINE_TYPES, true)) {
            throw PlanogramValidationException::coldChainMachineIncompatible(
                $machineType->value,
                $product->getSku(),
                422
            );
        }

        if (!self::isCompatible($machineType, $product->getCategory(), $product->requiresColdChain())) {
            throw PlanogramValidationException::productCategoryMachineIncompatible(
                $machineType->value,
                $product->getCategory()->value,
                $product->getSku(),
                422
            );
        }
    }

    /**
     * Cerrojo inverso del producto: comprobar la edición contra las tipologías de
     * las máquinas donde el producto tiene carriles activos (RF-03.2, 409).
     *
     * @param array<MachineType|string> $assignedMachineTypes
     */
    public static function assertProductChange(VendingProduct $product, array $assignedMachineTypes): void
    {
        foreach ($assignedMachineTypes as $assignedMachineType) {
            $machineType = $assignedMachineType instanceof MachineType
                ? $assignedMachineType
                : MachineType::fromString($assignedMachineType);

            if ($product->requiresColdChain() && !in_array($machineType, self::COLD_CHAIN_MACHINE_TYPES, true)) {
                throw PlanogramValidationException::coldChainMachineIncompatible(
                    $machineType->value,
                    $product->getSku()
                );
            }

            if (!self::isCompatible($machineType, $product->getCategory(), $product->requiresColdChain())) {
                throw PlanogramValidationException::productCategoryMachineIncompatible(
                    $machineType->value,
                    $product->getCategory()->value,
                    $product->getSku()
                );
            }
        }
    }

    /**
     * Cerrojo inverso de la máquina: ningún carril activo puede quedar
     * incompatible con la nueva tipología (RF-03.2, QA 4, 409).
     *
     * @param list<array{slot: MachinePlanogramSlot, product: VendingProduct}> $activeSlots
     */
    public static function assertMachineTypeChange(MachineType $newMachineType, array $activeSlots): void
    {
        $conflicts = [];

        foreach ($activeSlots as $activeSlot) {
            $slot = $activeSlot['slot'] ?? null;
            $product = $activeSlot['product'] ?? null;

            if (!$slot instanceof MachinePlanogramSlot || !$product instanceof VendingProduct) {
                throw new InvalidArgumentException(
                    'Cada carril activo debe declarar una entidad MachinePlanogramSlot y su VendingProduct.'
                );
            }

            if (!self::isCompatible($newMachineType, $product->getCategory(), $product->requiresColdChain())) {
                $reason = $product->requiresColdChain()
                    && !in_array($newMachineType, self::COLD_CHAIN_MACHINE_TYPES, true)
                    ? PlanogramValidationException::COLD_CHAIN_MACHINE_INCOMPATIBLE
                    : PlanogramValidationException::PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE;

                $conflicts[] = [
                    'slot_code' => $slot->getSlotCode(),
                    'sku' => $product->getSku(),
                    'reason' => $reason,
                ];
            }
        }

        if ($conflicts !== []) {
            throw PlanogramValidationException::planogramIncompatibleWithMachineType(
                $newMachineType->value,
                $conflicts
            );
        }
    }

    /**
     * El SKU es la identidad inmutable de la referencia (C1).
     */
    public static function assertSkuUnchanged(?string $currentSku, string $newSku): void
    {
        if ($currentSku === null) {
            return;
        }

        $current = strtoupper(trim($currentSku));
        $candidate = strtoupper(trim($newSku));

        if ($current !== $candidate) {
            throw PlanogramValidationException::skuChangeForbidden($currentSku, $newSku);
        }
    }
}
