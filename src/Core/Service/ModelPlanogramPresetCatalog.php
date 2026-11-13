<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;

/**
 * ModelPlanogramPresetCatalog
 *
 * Catálogo puro de plantillas de planograma por modelo normalizado y, como
 * respaldo, por tipología de máquina (Módulo 32; D1, RF-04.1).
 *
 * La plantilla no crea tablas ni productos: describe los carriles que
 * Coordinación aplicará en 1 clic y que `MachinePlanogramService` emparejará con
 * referencias activas del catálogo. Cada carril declara su categoría, si exige
 * cadena de frío, la unidad de medida y las cotas de capacidad y umbral; así el
 * servicio puede rechazar referencias incompatibles sin inventar reglas.
 *
 * Reglas que respeta por construcción:
 *   1. **Cerrojo sanitario (Art. II, D3).** Ninguna plantilla propone una pareja
 *      `MachineType × ProductCategory` fuera de la matriz, ni producto de frío en
 *      una máquina que no sea `PERISHABLE_FOOD` o `COMBO`.
 *   2. **Modelo primero (D1).** Los cuatro modelos del parque tienen plantilla
 *      propia; un modelo desconocido —o conocido con una tipología distinta a la
 *      que la plantilla declara— cae al preset de su `MachineType`, porque la
 *      tipología de la fila manda sobre el nombre comercial.
 *   3. **Cotas del carril (C2).** Capacidad en `1..10000`, umbral en
 *      `0..capacidad-1`, códigos canónicos y únicos dentro de la plantilla.
 *
 * El catálogo no escribe ni consulta la base y no conoce SKU concretos: entra el
 * modelo y la tipología, y sale la plantilla normalizada.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
final class ModelPlanogramPresetCatalog
{
    public const SOURCE_MODEL = 'MODEL';
    public const SOURCE_MACHINE_TYPE = 'MACHINE_TYPE';

    /** Códigos de carril canónicos (RF-02.1). */
    public const SLOT_CODE_PATTERN = '/^[A-Z0-9_]{1,24}$/';

    public const MIN_CAPACITY = 1;
    public const MAX_CAPACITY = 10000;

    /**
     * Plantillas por modelo del parque (D1). La clave es el modelo normalizado.
     *
     * @var array<string, array{model: string, machine_type: MachineType, slots: list<array<string, mixed>>}>
     */
    private const MODEL_PRESETS = [
        'necta korinto' => [
            'model' => 'Necta Korinto',
            'machine_type' => MachineType::HOT_DRINKS,
            'slots' => [
                ['slot_code' => 'HOPPER_COFFEE', 'label' => 'Café en grano', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 2000, 'min_threshold' => 400],
                ['slot_code' => 'HOPPER_MILK', 'label' => 'Leche en polvo', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1500, 'min_threshold' => 300],
                ['slot_code' => 'HOPPER_SUGAR', 'label' => 'Azúcar blanco', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1000, 'min_threshold' => 200],
                ['slot_code' => 'HOPPER_CHOCOLATE', 'label' => 'Chocolate soluble', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1200, 'min_threshold' => 250],
                ['slot_code' => 'TUBE_CUPS', 'label' => 'Vasos de cartón', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 600, 'min_threshold' => 150],
                ['slot_code' => 'TUBE_STIRRERS', 'label' => 'Paletinas de madera', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 400, 'min_threshold' => 100],
            ],
        ],
        'saeco cristallo' => [
            'model' => 'Saeco Cristallo',
            'machine_type' => MachineType::HOT_DRINKS,
            'slots' => [
                ['slot_code' => 'HOPPER_COFFEE', 'label' => 'Café en grano', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1800, 'min_threshold' => 350],
                ['slot_code' => 'HOPPER_MILK', 'label' => 'Leche en polvo', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1200, 'min_threshold' => 250],
                ['slot_code' => 'HOPPER_SUGAR', 'label' => 'Azúcar blanco', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1000, 'min_threshold' => 200],
                ['slot_code' => 'TUBE_CUPS', 'label' => 'Vasos de cartón', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 600, 'min_threshold' => 150],
            ],
        ],
        'fas fast 900' => [
            'model' => 'Fas Fast 900',
            'machine_type' => MachineType::SNACKS,
            'slots' => [
                ['slot_code' => '11', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
                ['slot_code' => '12', 'label' => 'Barrita energética', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
                ['slot_code' => '13', 'label' => 'Frutos secos', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
                ['slot_code' => '14', 'label' => 'Bollería envasada', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 12, 'min_threshold' => 4],
                ['slot_code' => '21', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
                ['slot_code' => '22', 'label' => 'Agua mineral', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ],
        ],
        'jofemar vision' => [
            'model' => 'Jofemar Vision',
            'machine_type' => MachineType::COMBO,
            'slots' => [
                ['slot_code' => '11', 'label' => 'Sándwich fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 10, 'min_threshold' => 3],
                ['slot_code' => '12', 'label' => 'Ensalada fresca', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 8, 'min_threshold' => 2],
                ['slot_code' => '13', 'label' => 'Lácteo fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 12, 'min_threshold' => 4],
                ['slot_code' => '21', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
                ['slot_code' => '22', 'label' => 'Barrita energética', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
                ['slot_code' => '31', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
                ['slot_code' => 'HOPPER_COFFEE', 'label' => 'Café en grano', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1500, 'min_threshold' => 300],
                ['slot_code' => 'TUBE_CUPS', 'label' => 'Vasos de cartón', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 600, 'min_threshold' => 150],
            ],
        ],
    ];

    /**
     * Plantillas de respaldo por tipología (D1), cuando el modelo no está en el
     * catálogo o su tipología no coincide con la de la plantilla del modelo.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const MACHINE_TYPE_PRESETS = [
        MachineType::HOT_DRINKS->value => [
            ['slot_code' => 'HOPPER_COFFEE', 'label' => 'Café en grano', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 2000, 'min_threshold' => 400],
            ['slot_code' => 'HOPPER_MILK', 'label' => 'Leche en polvo', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1500, 'min_threshold' => 300],
            ['slot_code' => 'HOPPER_SUGAR', 'label' => 'Azúcar blanco', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1000, 'min_threshold' => 200],
            ['slot_code' => 'TUBE_CUPS', 'label' => 'Vasos de cartón', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 600, 'min_threshold' => 150],
        ],
        MachineType::COLD_DRINKS->value => [
            ['slot_code' => '11', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 18, 'min_threshold' => 5],
            ['slot_code' => '12', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 18, 'min_threshold' => 5],
            ['slot_code' => '13', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
            ['slot_code' => '14', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
            ['slot_code' => '21', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
            ['slot_code' => '22', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
        ],
        MachineType::SNACKS->value => [
            ['slot_code' => '11', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
            ['slot_code' => '12', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 16, 'min_threshold' => 5],
            ['slot_code' => '13', 'label' => 'Frutos secos', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ['slot_code' => '21', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ['slot_code' => '22', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
        ],
        MachineType::PERISHABLE_FOOD->value => [
            ['slot_code' => '11', 'label' => 'Sándwich fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 10, 'min_threshold' => 3],
            ['slot_code' => '12', 'label' => 'Ensalada fresca', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 8, 'min_threshold' => 2],
            ['slot_code' => '13', 'label' => 'Lácteo fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 12, 'min_threshold' => 4],
            ['slot_code' => '21', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 12, 'min_threshold' => 3],
            ['slot_code' => '22', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
        ],
        MachineType::COMBO->value => [
            ['slot_code' => '11', 'label' => 'Sándwich fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 10, 'min_threshold' => 3],
            ['slot_code' => '12', 'label' => 'Lácteo fresco', 'product_category' => ProductCategory::PERISHABLE_FOOD, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => true, 'max_capacity' => 12, 'min_threshold' => 4],
            ['slot_code' => '21', 'label' => 'Snack salado', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ['slot_code' => '22', 'label' => 'Barrita energética', 'product_category' => ProductCategory::SNACK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ['slot_code' => '31', 'label' => 'Bebida fría', 'product_category' => ProductCategory::COLD_DRINK, 'unit_type' => ProductUnitType::UNIT, 'requires_cold_chain' => false, 'max_capacity' => 14, 'min_threshold' => 4],
            ['slot_code' => 'HOPPER_COFFEE', 'label' => 'Café en grano', 'product_category' => ProductCategory::HOT_BEVERAGE_INGREDIENT, 'unit_type' => ProductUnitType::GRAMS, 'requires_cold_chain' => false, 'max_capacity' => 1500, 'min_threshold' => 300],
            ['slot_code' => 'TUBE_CUPS', 'label' => 'Vasos de cartón', 'product_category' => ProductCategory::CUP_STIRRER_CONSUMABLE, 'unit_type' => ProductUnitType::SLEEVE, 'requires_cold_chain' => false, 'max_capacity' => 600, 'min_threshold' => 150],
        ],
    ];

    /**
     * Plantilla aplicable a una máquina: la del modelo si este se conoce y su
     * tipología coincide; en cualquier otro caso, la de la tipología (D1).
     *
     * @return array{model: ?string, machine_type: MachineType, source: string, slots: list<array<string, mixed>>}
     */
    public static function forMachine(string $model, MachineType $machineType): array
    {
        $normalizedModel = self::normalizeModel($model);
        $preset = self::MODEL_PRESETS[$normalizedModel] ?? null;

        if ($preset !== null && $preset['machine_type'] === $machineType) {
            return [
                'model' => $preset['model'],
                'machine_type' => $machineType,
                'source' => self::SOURCE_MODEL,
                'slots' => self::copySlots($preset['slots']),
            ];
        }

        return self::forMachineType($machineType);
    }

    /**
     * Plantilla de respaldo por tipología (D1).
     *
     * @return array{model: null, machine_type: MachineType, source: string, slots: list<array<string, mixed>>}
     */
    public static function forMachineType(MachineType $machineType): array
    {
        return [
            'model' => null,
            'machine_type' => $machineType,
            'source' => self::SOURCE_MACHINE_TYPE,
            'slots' => self::copySlots(self::MACHINE_TYPE_PRESETS[$machineType->value] ?? []),
        ];
    }

    /**
     * Modelos con plantilla propia, en su forma de presentación.
     *
     * @return list<string>
     */
    public static function knownModels(): array
    {
        return array_values(array_map(
            static fn (array $preset): string => $preset['model'],
            self::MODEL_PRESETS
        ));
    }

    /**
     * Forma normalizada del modelo: recorte, espacios colapsados y minúsculas.
     */
    public static function normalizeModel(string $model): string
    {
        $trimmed = trim($model);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return mb_strtolower($collapsed, 'UTF-8');
    }

    /**
     * Copia profunda de los carriles para que el llamador no pueda mutar el
     * catálogo estático: cada plantilla entregada es independiente.
     *
     * @param list<array<string, mixed>> $slots
     *
     * @return list<array<string, mixed>>
     */
    private static function copySlots(array $slots): array
    {
        return array_map(
            static fn (array $slot): array => [
                'slot_code' => $slot['slot_code'],
                'label' => $slot['label'],
                'product_category' => $slot['product_category'],
                'unit_type' => $slot['unit_type'],
                'requires_cold_chain' => $slot['requires_cold_chain'],
                'max_capacity' => $slot['max_capacity'],
                'min_threshold' => $slot['min_threshold'],
            ],
            $slots
        );
    }
}
