<?php

declare(strict_types=1);

/**
 * ModelPlanogramPresetCatalogTest · T-PLN-04
 *
 * Suite unitaria de las plantillas de planograma por modelo y tipología
 * (Módulo 32, D1, RF-04.1). Certifica la puerta del dominio sin base de datos ni
 * red:
 *
 *   1. **Modelo primero (D1):** los cuatro modelos del parque —Necta Korinto,
 *      Saeco Cristallo, Fas Fast 900 y Jofemar Vision— tienen plantilla propia y
 *      se resuelven sin distinguir mayúsculas ni espacios repetidos.
 *   2. **Respaldo por tipología (D1):** un modelo desconocido cae al preset de su
 *      `MachineType`; un modelo conocido con una tipología distinta a la que
 *      declara su plantilla también, porque la fila de la máquina manda.
 *   3. **Cerrojo sanitario (Art. II, D3):** todos los carriles de todas las
 *      plantillas respetan la matriz `MachineType × ProductCategory` y sólo los
 *      perecederos llevan cadena de frío.
 *   4. **Cotas del carril (C2):** códigos canónicos y únicos, capacidad en
 *      `1..10000`, umbral en `0..capacidad-1` y unidad admitida por la categoría.
 *   5. **Independencia:** cada entrega es una copia; mutar una plantilla no
 *      altera el catálogo estático.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Service\ModelPlanogramPresetCatalog;
use VendGuard\Core\Service\PlanogramCompatibilityValidator;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · ModelPlanogramPresetCatalogTest (T-PLN-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/**
 * Comprueba la forma, las cotas y la compatibilidad de una plantilla completa.
 *
 * @param array{model: ?string, machine_type: MachineType, source: string, slots: list<array<string, mixed>>} $preset
 *
 * @return list<string> problemas encontrados
 */
$slotProblems = static function (array $preset): array {
    $problems = [];
    $codes = [];
    $machineType = $preset['machine_type'];

    foreach ($preset['slots'] as $slot) {
        $code = (string)$slot['slot_code'];
        $category = $slot['product_category'];
        $unitType = $slot['unit_type'];

        if (preg_match(ModelPlanogramPresetCatalog::SLOT_CODE_PATTERN, $code) !== 1) {
            $problems[] = "código inválido {$code}";
        }
        if (isset($codes[$code])) {
            $problems[] = "código duplicado {$code}";
        }
        $codes[$code] = true;

        if ($slot['max_capacity'] < ModelPlanogramPresetCatalog::MIN_CAPACITY
            || $slot['max_capacity'] > ModelPlanogramPresetCatalog::MAX_CAPACITY) {
            $problems[] = "capacidad fuera de rango en {$code}";
        }
        if ($slot['min_threshold'] < 0 || $slot['min_threshold'] >= $slot['max_capacity']) {
            $problems[] = "umbral fuera de rango en {$code}";
        }
        if (!$category instanceof ProductCategory) {
            $problems[] = "categoría no tipada en {$code}";
            continue;
        }
        if (!$category->allowsUnitType($unitType)) {
            $problems[] = "unidad no admitida en {$code}";
        }
        if (!PlanogramCompatibilityValidator::isCompatible($machineType, $category, (bool)$slot['requires_cold_chain'])) {
            $problems[] = "pareja incompatible en {$code}";
        }
        if ($slot['requires_cold_chain'] === true
            && (!in_array($machineType, [MachineType::PERISHABLE_FOOD, MachineType::COMBO], true)
                || !$category->allowsColdChain())) {
            $problems[] = "frío fuera de lugar en {$code}";
        }
    }

    return $problems;
};

// ─────────────────────────────────────────────────────────────────────
// 1. Normalización y catálogo de modelos (D1)
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Normalización y modelos con plantilla (D1) ---\n";

$assert(
    '1.1 normalizeModel recorta, colapsa espacios y despliega a minúsculas',
    ModelPlanogramPresetCatalog::normalizeModel('  NECTA   Korinto ') === 'necta korinto',
    ModelPlanogramPresetCatalog::normalizeModel('  NECTA   Korinto ')
);

$assert(
    '1.2 Los cuatro modelos del parque están declarados en el catálogo',
    ModelPlanogramPresetCatalog::knownModels() === [
        'Necta Korinto', 'Saeco Cristallo', 'Fas Fast 900', 'Jofemar Vision',
    ],
    json_encode(ModelPlanogramPresetCatalog::knownModels())
);

// ─────────────────────────────────────────────────────────────────────
// 2. Plantillas por modelo (D1, RF-04.1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Plantillas por modelo del parque ---\n";

$necta = ModelPlanogramPresetCatalog::forMachine('Necta Korinto', MachineType::HOT_DRINKS);
$assert(
    '2.1 Necta Korinto resuelve su plantilla de modelo para HOT_DRINKS',
    $necta['source'] === ModelPlanogramPresetCatalog::SOURCE_MODEL
    && $necta['model'] === 'Necta Korinto'
    && $necta['machine_type'] === MachineType::HOT_DRINKS
    && count($necta['slots']) === 6
    && array_column($necta['slots'], 'slot_code') === [
        'HOPPER_COFFEE', 'HOPPER_MILK', 'HOPPER_SUGAR', 'HOPPER_CHOCOLATE', 'TUBE_CUPS', 'TUBE_STIRRERS',
    ],
    json_encode(array_column($necta['slots'], 'slot_code'))
);

$nectaProblems = $slotProblems($necta);
$assert('2.2 Los seis carriles de Necta Korinto respetan matriz y cotas', $nectaProblems === [], implode(', ', $nectaProblems));

$assert(
    '2.3 La plantilla de calientes no lleva cadena de frío y usa tolvas GRAMS y tubos SLEEVE',
    array_reduce($necta['slots'], static fn (bool $carry, array $slot): bool => $carry && $slot['requires_cold_chain'] === false, true)
    && $necta['slots'][0]['unit_type']->value === 'GRAMS'
    && $necta['slots'][4]['unit_type']->value === 'SLEEVE'
    && $necta['slots'][0]['product_category'] === ProductCategory::HOT_BEVERAGE_INGREDIENT
    && $necta['slots'][4]['product_category'] === ProductCategory::CUP_STIRRER_CONSUMABLE
);

$saeco = ModelPlanogramPresetCatalog::forMachine('  saeco   CRISTALLO  ', MachineType::HOT_DRINKS);
$assert(
    '2.4 Saeco Cristallo se resuelve sin distinguir mayúsculas ni espacios',
    $saeco['source'] === ModelPlanogramPresetCatalog::SOURCE_MODEL
    && $saeco['model'] === 'Saeco Cristallo'
    && count($saeco['slots']) === 4
    && $slotProblems($saeco) === [],
    json_encode($saeco['model'])
);

$fas = ModelPlanogramPresetCatalog::forMachine('Fas Fast 900', MachineType::SNACKS);
$assert(
    '2.5 Fas Fast 900 declara seis carriles de snack y bebida fría para SNACKS',
    $fas['source'] === ModelPlanogramPresetCatalog::SOURCE_MODEL
    && $fas['machine_type'] === MachineType::SNACKS
    && count($fas['slots']) === 6
    && $slotProblems($fas) === []
    && array_column($fas['slots'], 'product_category') === [
        ProductCategory::SNACK, ProductCategory::SNACK, ProductCategory::SNACK,
        ProductCategory::SNACK, ProductCategory::COLD_DRINK, ProductCategory::COLD_DRINK,
    ]
);

$jofemar = ModelPlanogramPresetCatalog::forMachine('Jofemar Vision', MachineType::COMBO);
$assert(
    '2.6 Jofemar Vision combina perecederos refrigerados, snacks, bebida y café',
    $jofemar['source'] === ModelPlanogramPresetCatalog::SOURCE_MODEL
    && $jofemar['machine_type'] === MachineType::COMBO
    && count($jofemar['slots']) === 8
    && $slotProblems($jofemar) === [],
    json_encode(array_column($jofemar['slots'], 'slot_code'))
);

$coldSlots = array_values(array_filter(
    $jofemar['slots'],
    static fn (array $slot): bool => $slot['requires_cold_chain'] === true
));
$assert(
    '2.7 Sólo los tres perecederos del combo declaran cadena de frío',
    count($coldSlots) === 3
    && array_reduce($coldSlots, static fn (bool $carry, array $slot): bool => $carry
        && $slot['product_category'] === ProductCategory::PERISHABLE_FOOD, true)
    && $coldSlots[0]['slot_code'] === '11'
);

// ─────────────────────────────────────────────────────────────────────
// 3. Respaldo por tipología (D1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Respaldo por tipología y modelo ausente (D1) ---\n";

$unknown = ModelPlanogramPresetCatalog::forMachine('Sanden Vendo G-Drink', MachineType::PERISHABLE_FOOD);
$assert(
    '3.1 Un modelo desconocido cae al preset de su tipología con modelo nulo',
    $unknown['source'] === ModelPlanogramPresetCatalog::SOURCE_MACHINE_TYPE
    && $unknown['model'] === null
    && $unknown['machine_type'] === MachineType::PERISHABLE_FOOD
    && count($unknown['slots']) === 5
    && $slotProblems($unknown) === [],
    json_encode($unknown['source'])
);

$mismatched = ModelPlanogramPresetCatalog::forMachine('Necta Korinto', MachineType::SNACKS);
$assert(
    '3.2 Un modelo conocido con tipología distinta a su plantilla cae al preset de la tipología',
    $mismatched['source'] === ModelPlanogramPresetCatalog::SOURCE_MACHINE_TYPE
    && $mismatched['model'] === null
    && $mismatched['machine_type'] === MachineType::SNACKS
    && count($mismatched['slots']) === 5
    && $slotProblems($mismatched) === [],
    json_encode([$mismatched['source'], $mismatched['model']])
);

$typeProblems = [];
foreach (MachineType::cases() as $machineType) {
    $preset = ModelPlanogramPresetCatalog::forMachineType($machineType);
    if ($preset['slots'] === [] || $preset['source'] !== ModelPlanogramPresetCatalog::SOURCE_MACHINE_TYPE) {
        $typeProblems[] = $machineType->value . ': plantilla vacía o fuente incorrecta';
        continue;
    }
    foreach ($slotProblems($preset) as $problem) {
        $typeProblems[] = $machineType->value . ': ' . $problem;
    }
}

$assert(
    '3.3 Las cinco tipologías tienen plantilla de respaldo no vacía y compatible',
    $typeProblems === [],
    implode(' · ', $typeProblems)
);

$modelProblems = [];
foreach (ModelPlanogramPresetCatalog::knownModels() as $model) {
    $declaredType = match ($model) {
        'Necta Korinto', 'Saeco Cristallo' => MachineType::HOT_DRINKS,
        'Fas Fast 900' => MachineType::SNACKS,
        'Jofemar Vision' => MachineType::COMBO,
    };
    $preset = ModelPlanogramPresetCatalog::forMachine($model, $declaredType);
    if ($preset['source'] !== ModelPlanogramPresetCatalog::SOURCE_MODEL || $preset['model'] !== $model) {
        $modelProblems[] = $model . ': no resolvió por modelo';
        continue;
    }
    foreach ($slotProblems($preset) as $problem) {
        $modelProblems[] = $model . ': ' . $problem;
    }
}

$assert(
    '3.4 Las cuatro plantillas de modelo son compatibles con su tipología declarada',
    $modelProblems === [],
    implode(' · ', $modelProblems)
);

// ─────────────────────────────────────────────────────────────────────
// 4. Independencia de las copias entregadas
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Independencia de las copias entregadas ---\n";

$firstDelivery = ModelPlanogramPresetCatalog::forMachineType(MachineType::COLD_DRINKS);
$firstDelivery['slots'][0]['max_capacity'] = 1;
$firstDelivery['slots'][0]['slot_code'] = 'MUTADO';

$secondDelivery = ModelPlanogramPresetCatalog::forMachineType(MachineType::COLD_DRINKS);
$assert(
    '4.1 Mutar una plantilla entregada no altera el catálogo estático',
    $secondDelivery['slots'][0]['max_capacity'] === 18
    && $secondDelivery['slots'][0]['slot_code'] === '11',
    json_encode($secondDelivery['slots'][0])
);

$nectaAgain = ModelPlanogramPresetCatalog::forMachine('NECTA KORINTO', MachineType::HOT_DRINKS);
$assert(
    '4.2 La segunda entrega de un modelo conserva sus seis carriles originales',
    count($nectaAgain['slots']) === 6 && $nectaAgain['slots'][0]['slot_code'] === 'HOPPER_COFFEE'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-04 (plantillas D1) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
