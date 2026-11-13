<?php

declare(strict_types=1);

/**
 * PlanogramCompatibilityValidatorTest · T-PLN-03
 *
 * Suite unitaria de los enums, entidades y el validador del catálogo y el
 * planograma (Módulo 32). Certifica la puerta del dominio sin base de datos ni
 * red:
 *
 *   1. **Catálogo de producto (RF-01.1, C2, QA 1):** etiquetas, venta directa,
 *      cadena de frío por defecto y unidades admitidas por categoría.
 *   2. **Semáforo de existencias (RF-02.3, C2):** `STOCKOUT` en cero,
 *      `LOW_STOCK` hasta el umbral y `OK` por encima, sin reloj ni motor.
 *   3. **Entidad `VendingProduct` (C1, C2, D3, QA 1):** SKU canónico, cotas de
 *      nombre, coste y PVP, coherencia categoría/unidad, perecedero con frío y
 *      vida útil obligatoria; el margen y las etiquetas del DTO.
 *   4. **Entidad `MachinePlanogramSlot` (RF-02.1, C2):** código canónico, cotas
 *      de capacidad, umbral y existencia, déficit de reposición y llenado.
 *   5. **Matriz `MachineType × ProductCategory` (D3, Art. II):** los 25 pares se
 *      comparan contra la matriz del contrato; la cadena de frío sólo entra en
 *      `PERISHABLE_FOOD` y `COMBO`.
 *   6. **Cerrojo inverso (RF-03.2, QA 4):** editar el producto o cambiar la
 *      tipología de la máquina se rechaza con el código y el estado HTTP del
 *      contrato, listando los carriles en conflicto.
 *   7. **Identidad inmutable (C1):** el SKU no cambia tras el alta.
 *
 * Los rechazos se capturan por la excepción del contrato y se comprueba su
 * código y su estado HTTP: cualquier otro fallo se propaga y rompe la suite.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\SlotStockStatus;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Service\PlanogramCompatibilityValidator;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · PlanogramCompatibilityValidatorTest (T-PLN-03)\n";
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

$rejectionOf = static function (callable $operation): ?PlanogramValidationException {
    try {
        $operation();
    } catch (PlanogramValidationException $exception) {
        return $exception;
    }

    return null;
};

$assertRejects = function (
    string $caseTitle,
    callable $operation,
    string $expectedCode,
    int $expectedStatus
) use ($assert, $rejectionOf): void {
    $exception = $rejectionOf($operation);
    $assert(
        $caseTitle . ' · ' . $expectedCode,
        $exception !== null
        && $exception->getErrorCode() === $expectedCode
        && $exception->getHttpStatusCode() === $expectedStatus,
        $exception === null
            ? 'la operación se aceptó'
            : sprintf('código %s · HTTP %d', $exception->getErrorCode(), $exception->getHttpStatusCode())
    );
};

// ─────────────────────────────────────────────────────────────────────
// Escenario: referencias válidas del catálogo
// ─────────────────────────────────────────────────────────────────────
$sandwich = new VendingProduct(
    1,
    '  sand-jamon  ',
    '  Sándwich Mixto Jamón y Queso Fresco  ',
    ProductCategory::PERISHABLE_FOOD,
    ProductUnitType::UNIT,
    1.15,
    2.40,
    true,
    5
);
$cola = new VendingProduct(
    2,
    'BEB-COLA-33',
    'Lata de Cola 33 cl',
    ProductCategory::COLD_DRINK,
    ProductUnitType::UNIT,
    0.45,
    1.20
);
$snackBar = new VendingProduct(
    3,
    'SNACK-BAR-01',
    'Barrita de Cereales y Chocolate',
    ProductCategory::SNACK,
    ProductUnitType::UNIT,
    0.60,
    1.50
);
$coffee = new VendingProduct(
    4,
    'CAFE-MOLIDO-01',
    'Café Molido Natural',
    ProductCategory::HOT_BEVERAGE_INGREDIENT,
    ProductUnitType::GRAMS,
    5.00,
    8.00
);
$cups = new VendingProduct(
    5,
    'CONS-VASOS-7OZ',
    'Tira de Vasos de Cartón 7 oz',
    ProductCategory::CUP_STIRRER_CONSUMABLE,
    ProductUnitType::SLEEVE,
    1.80,
    0.00
);

// ─────────────────────────────────────────────────────────────────────
// 1. Enums del catálogo (RF-01.1, QA 1)
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Categorías y unidades del catálogo (RF-01.1, QA 1) ---\n";

$assert(
    '1.1 ProductCategory declara las cinco categorías del contrato en su orden',
    ProductCategory::values() === [
        'COLD_DRINK', 'SNACK', 'PERISHABLE_FOOD', 'HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE',
    ],
    json_encode(ProductCategory::values())
);

$assert(
    '1.2 ProductUnitType declara UNIT, GRAMS y SLEEVE con sus etiquetas',
    ProductUnitType::values() === ['UNIT', 'GRAMS', 'SLEEVE']
    && ProductUnitType::GRAMS->label() === 'Gramos'
    && ProductUnitType::SLEEVE->label() === 'Tira'
);

$assert(
    '1.3 Sólo las categorías de venta directa exigen PVP positivo (C2)',
    ProductCategory::COLD_DRINK->isDirectRetail()
    && ProductCategory::SNACK->isDirectRetail()
    && ProductCategory::PERISHABLE_FOOD->isDirectRetail()
    && !ProductCategory::HOT_BEVERAGE_INGREDIENT->isDirectRetail()
    && !ProductCategory::CUP_STIRRER_CONSUMABLE->isDirectRetail()
);

$assert(
    '1.4 Sólo PERISHABLE_FOOD exige cadena de frío por defecto y admite frío junto a bebidas y snacks (D3)',
    ProductCategory::PERISHABLE_FOOD->requiresColdChainByDefault()
    && !ProductCategory::COLD_DRINK->requiresColdChainByDefault()
    && ProductCategory::COLD_DRINK->allowsColdChain()
    && ProductCategory::SNACK->allowsColdChain()
    && !ProductCategory::HOT_BEVERAGE_INGREDIENT->allowsColdChain()
    && !ProductCategory::CUP_STIRRER_CONSUMABLE->allowsColdChain()
);

$assert(
    '1.5 La unidad admitida depende de la categoría (QA 1)',
    ProductCategory::COLD_DRINK->allowedUnitTypes() === [ProductUnitType::UNIT]
    && ProductCategory::HOT_BEVERAGE_INGREDIENT->allowsUnitType(ProductUnitType::GRAMS)
    && ProductCategory::HOT_BEVERAGE_INGREDIENT->allowsUnitType(ProductUnitType::UNIT)
    && !ProductCategory::HOT_BEVERAGE_INGREDIENT->allowsUnitType(ProductUnitType::SLEEVE)
    && ProductCategory::CUP_STIRRER_CONSUMABLE->allowsUnitType(ProductUnitType::SLEEVE)
    && !ProductCategory::COLD_DRINK->allowsUnitType(ProductUnitType::GRAMS)
);

$assert(
    '1.6 Las etiquetas en castellano del contrato están declaradas',
    $sandwich->getCategory()->label() === 'Alimento Perecedero Fresco'
    && $cola->getCategory()->label() === 'Bebida Fría'
    && $cups->getCategory()->label() === 'Consumible y Vasos'
);

// ─────────────────────────────────────────────────────────────────────
// 2. Semáforo de existencias (RF-02.3, C2)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Semáforo determinista de existencias (RF-02.3) ---\n";

$assert(
    '2.1 Cero unidades es STOCKOUT, el umbral es LOW_STOCK y por encima es OK',
    SlotStockStatus::fromQuantities(0, 3) === SlotStockStatus::STOCKOUT
    && SlotStockStatus::fromQuantities(1, 3) === SlotStockStatus::LOW_STOCK
    && SlotStockStatus::fromQuantities(3, 3) === SlotStockStatus::LOW_STOCK
    && SlotStockStatus::fromQuantities(4, 3) === SlotStockStatus::OK
);

$assert(
    '2.2 Las etiquetas del semáforo son las de la consola (Agotado / Bajo umbral / Correcto)',
    SlotStockStatus::STOCKOUT->label() === 'Agotado'
    && SlotStockStatus::LOW_STOCK->label() === 'Bajo umbral'
    && SlotStockStatus::OK->label() === 'Correcto'
);

$negativeStatusRejected = false;
try {
    SlotStockStatus::fromQuantities(-1, 3);
} catch (InvalidArgumentException) {
    $negativeStatusRejected = true;
}
$assert('2.3 Una existencia negativa no produce un estado silencioso', $negativeStatusRejected);

// ─────────────────────────────────────────────────────────────────────
// 3. Entidad VendingProduct (C1, C2, D3, QA 1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Entidad VendingProduct (C1, C2, QA 1) ---\n";

$assert(
    '3.1 El SKU y el nombre se normalizan en el borde de la entidad',
    $sandwich->getSku() === 'SAND-JAMON'
    && $sandwich->getName() === 'Sándwich Mixto Jamón y Queso Fresco',
    $sandwich->getSku()
);

$assert(
    '3.2 El margen unitario y las etiquetas del DTO se derivan de los importes',
    $sandwich->getUnitMarginEur() === 1.25
    && $sandwich->toArray()['category_label'] === 'Alimento Perecedero Fresco'
    && $sandwich->toArray()['unit_type_label'] === 'Unidad'
    && $sandwich->toArray()['unit_margin_eur'] === 1.25
);

$assert(
    '3.3 Un consumible con PVP 0,00 € es válido y su margen puede ser negativo (C2)',
    $cups->getSalePriceEur() === 0.00 && $cups->getUnitMarginEur() === -1.80
);

$inactiveCopy = $cola->withStatus(false);
$assert(
    '3.4 La baja lógica devuelve una copia y no muta la referencia original (C1)',
    $inactiveCopy->isActive() === false && $cola->isActive() === true
);

$assertRejects(
    '3.5 Un SKU con espacios se rechaza',
    static fn (): VendingProduct => new VendingProduct(null, 'REF 01', 'Referencia inválida', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 1.00, 2.00),
    PlanogramValidationException::INVALID_PRODUCT_SKU,
    422
);

$assertRejects(
    '3.6 Un nombre de un carácter se rechaza',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'X', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 1.00, 2.00),
    PlanogramValidationException::INVALID_PRODUCT_NAME,
    422
);

$assertRejects(
    '3.7 Un coste unitario de 0,00 € se rechaza',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Referencia de prueba', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.00, 2.00),
    PlanogramValidationException::INVALID_UNIT_COST,
    422
);

$assertRejects(
    '3.8 Un refresco de venta directa con PVP 0,00 € se rechaza (C2)',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Refresco de prueba', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 1.00, 0.00),
    PlanogramValidationException::INVALID_SALE_PRICE,
    422
);

$assertRejects(
    '3.9 Una bebida fría vendida por gramos se rechaza (QA 1)',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Bebida por gramos', ProductCategory::COLD_DRINK, ProductUnitType::GRAMS, 1.00, 2.00),
    PlanogramValidationException::INVALID_UNIT_TYPE,
    422
);

$assertRejects(
    '3.10 Un perecedero sin cadena de frío se rechaza (D3)',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Sándwich sin frío', ProductCategory::PERISHABLE_FOOD, ProductUnitType::UNIT, 1.00, 2.00),
    PlanogramValidationException::PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE,
    422
);

$assertRejects(
    '3.11 La cadena de frío fuera de perecederos, bebidas y snacks se rechaza',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Café refrigerado', ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductUnitType::GRAMS, 5.00, 8.00, true, 30),
    PlanogramValidationException::INVALID_COLD_CHAIN_CATEGORY,
    422
);

$assertRejects(
    '3.12 La cadena de frío sin vida útil declarada se rechaza (QA 1)',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Yogur sin vida útil', ProductCategory::PERISHABLE_FOOD, ProductUnitType::UNIT, 1.00, 2.00, true),
    PlanogramValidationException::PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE,
    422
);

$assertRejects(
    '3.13 Una vida útil fuera de 1..3650 días se rechaza',
    static fn (): VendingProduct => new VendingProduct(null, 'REF-01', 'Snack con vida ilegal', ProductCategory::SNACK, ProductUnitType::UNIT, 1.00, 2.00, false, 3651),
    PlanogramValidationException::INVALID_SHELF_LIFE,
    422
);

// ─────────────────────────────────────────────────────────────────────
// 4. Entidad MachinePlanogramSlot (RF-02.1, C2)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Entidad MachinePlanogramSlot (RF-02.1, C2) ---\n";

$slot = new MachinePlanogramSlot(10, 1, ' a1 ', 1, 10, 0, 3);
$assert(
    '4.1 El código de carril se normaliza en mayúsculas y el semáforo se deriva',
    $slot->getSlotCode() === 'A1'
    && $slot->stockStatus() === SlotStockStatus::STOCKOUT
    && $slot->restockNeededQty() === 10
    && $slot->fillRatePercent() === 0.0
);

$lowSlot = new MachinePlanogramSlot(null, 1, '11', 1, 10, 3, 3);
$assert(
    '4.2 El umbral exacto es LOW_STOCK, el déficit se acota y el llenado se calcula',
    $lowSlot->stockStatus() === SlotStockStatus::LOW_STOCK
    && $lowSlot->restockNeededQty() === 7
    && $lowSlot->fillRatePercent() === 30.0
    && $lowSlot->toArray()['stock_status'] === 'LOW_STOCK'
);

$assertRejects(
    '4.3 Una capacidad de cero se rechaza',
    static fn (): MachinePlanogramSlot => new MachinePlanogramSlot(null, 1, '11', 1, 0),
    PlanogramValidationException::INVALID_PLANOGRAM_SLOT_BOUNDS,
    422
);

$assertRejects(
    '4.4 Un umbral igual a la capacidad se rechaza',
    static fn (): MachinePlanogramSlot => new MachinePlanogramSlot(null, 1, '11', 1, 10, 0, 10),
    PlanogramValidationException::INVALID_PLANOGRAM_SLOT_BOUNDS,
    422
);

$assertRejects(
    '4.5 Una existencia superior a la capacidad se rechaza',
    static fn (): MachinePlanogramSlot => new MachinePlanogramSlot(null, 1, '11', 1, 10, 11, 3),
    PlanogramValidationException::INVALID_PLANOGRAM_SLOT_BOUNDS,
    422
);

$assertRejects(
    '4.6 Un código de carril con guion se rechaza',
    static fn (): MachinePlanogramSlot => new MachinePlanogramSlot(null, 1, 'A-1', 1, 10),
    PlanogramValidationException::INVALID_PLANOGRAM_SLOT_BOUNDS,
    422
);

// ─────────────────────────────────────────────────────────────────────
// 5. Matriz MachineType × ProductCategory (D3, Art. II)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 5. Matriz MachineType × ProductCategory (D3, Art. II) ---\n";

$expectedMatrix = [
    'HOT_DRINKS' => ['HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE'],
    'COLD_DRINKS' => ['COLD_DRINK'],
    'SNACKS' => ['SNACK', 'COLD_DRINK'],
    'PERISHABLE_FOOD' => ['PERISHABLE_FOOD', 'COLD_DRINK', 'SNACK'],
    'COMBO' => ['COLD_DRINK', 'SNACK', 'PERISHABLE_FOOD', 'HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE'],
];

$matrixProblems = [];
foreach (MachineType::cases() as $machineType) {
    foreach (ProductCategory::cases() as $category) {
        $expected = in_array($category->value, $expectedMatrix[$machineType->value] ?? [], true);
        $actual = PlanogramCompatibilityValidator::isCompatible($machineType, $category, false);
        if ($expected !== $actual) {
            $matrixProblems[] = $machineType->value . '×' . $category->value;
        }
    }
}

$assert(
    '5.1 Los 25 pares de la matriz coinciden exactamente con el contrato D3',
    $matrixProblems === [],
    implode(', ', $matrixProblems)
);

$assert(
    '5.2 COMBO admite todas las categorías y HOT_DRINKS sólo ingredientes y consumibles',
    count(PlanogramCompatibilityValidator::allowedCategoriesFor(MachineType::COMBO)) === 5
    && PlanogramCompatibilityValidator::allowedCategoriesFor(MachineType::HOT_DRINKS) === [
        ProductCategory::HOT_BEVERAGE_INGREDIENT,
        ProductCategory::CUP_STIRRER_CONSUMABLE,
    ]
);

$assert(
    '5.3 La cadena de frío exige PERISHABLE_FOOD o COMBO aunque la categoría encaje (Art. II)',
    !PlanogramCompatibilityValidator::isCompatible(MachineType::SNACKS, ProductCategory::SNACK, true)
    && PlanogramCompatibilityValidator::isCompatible(MachineType::COMBO, ProductCategory::SNACK, true)
    && PlanogramCompatibilityValidator::isCompatible(MachineType::PERISHABLE_FOOD, ProductCategory::COLD_DRINK, true)
    && !PlanogramCompatibilityValidator::isCompatible(MachineType::COLD_DRINKS, ProductCategory::COLD_DRINK, true)
);

// ─────────────────────────────────────────────────────────────────────
// 6. Cerrojo directo del carril (RF-03.1, 422)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 6. Cerrojo directo del carril (RF-03.1, 422) ---\n";

$acceptedAssignment = $rejectionOf(static function () use ($sandwich): void {
    PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::PERISHABLE_FOOD, $sandwich);
});
$assert('6.1 Un perecedero refrigerado se admite en PERISHABLE_FOOD', $acceptedAssignment === null);

$acceptedCombo = $rejectionOf(static function () use ($sandwich): void {
    PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::COMBO, $sandwich);
});
$assert('6.2 El mismo perecedero se admite en COMBO', $acceptedCombo === null);

$assertRejects(
    '6.3 Un perecedero refrigerado en SNACKS se rechaza con COLD_CHAIN_MACHINE_INCOMPATIBLE',
    static fn (): mixed => PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::SNACKS, $sandwich),
    PlanogramValidationException::COLD_CHAIN_MACHINE_INCOMPATIBLE,
    422
);

$assertRejects(
    '6.4 Un snack en HOT_DRINKS se rechaza con PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE',
    static fn (): mixed => PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::HOT_DRINKS, $snackBar),
    PlanogramValidationException::PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE,
    422
);

$inactiveCola = $cola->withStatus(false);
$assertRejects(
    '6.5 Un producto dado de baja no se asigna a un carril nuevo (RF-02.2)',
    static fn (): mixed => PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::COLD_DRINKS, $inactiveCola),
    PlanogramValidationException::PRODUCT_INACTIVE,
    422
);

$inactiveTolerated = $rejectionOf(static function () use ($cola): void {
    PlanogramCompatibilityValidator::assertSlotAssignment(MachineType::COLD_DRINKS, $cola->withStatus(false), false);
});
$assert('6.6 La baja preexistente del producto se tolera al mantener el carril', $inactiveTolerated === null);

// ─────────────────────────────────────────────────────────────────────
// 7. Cerrojo inverso del producto (RF-03.2, 409)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 7. Cerrojo inverso del producto (RF-03.2, 409) ---\n";

$compatibleMachines = $rejectionOf(static function () use ($snackBar): void {
    PlanogramCompatibilityValidator::assertProductChange(
        $snackBar,
        [MachineType::SNACKS, MachineType::PERISHABLE_FOOD, 'COMBO']
    );
});
$assert('7.1 Un snack compatible con sus máquinas activas no bloquea la edición', $compatibleMachines === null);

$assertRejects(
    '7.2 Un snack en una máquina de calientes bloquea la edición del producto',
    static fn (): mixed => PlanogramCompatibilityValidator::assertProductChange($snackBar, [MachineType::HOT_DRINKS]),
    PlanogramValidationException::PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE,
    409
);

$assertRejects(
    '7.3 Activar la cadena de frío con carriles en SNACKS bloquea la edición',
    static fn (): mixed => PlanogramCompatibilityValidator::assertProductChange($sandwich, [MachineType::SNACKS]),
    PlanogramValidationException::COLD_CHAIN_MACHINE_INCOMPATIBLE,
    409
);

// ─────────────────────────────────────────────────────────────────────
// 8. Cerrojo inverso de la máquina (RF-03.2, QA 4, 409)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 8. Cerrojo inverso de la máquina (RF-03.2, QA 4, 409) ---\n";

$conflictSlot = new MachinePlanogramSlot(20, 1, '11', 1, 10, 4, 2);
$snackSlot = new MachinePlanogramSlot(21, 1, 'B2', 3, 8, 2, 2);

$machineTypeException = $rejectionOf(static function () use ($sandwich, $conflictSlot): void {
    PlanogramCompatibilityValidator::assertMachineTypeChange(
        MachineType::SNACKS,
        [['slot' => $conflictSlot, 'product' => $sandwich]]
    );
});

$assert(
    '8.1 Cambiar a SNACKS con un carril refrigerado se rechaza con el listado de conflictos',
    $machineTypeException !== null
    && $machineTypeException->getErrorCode() === PlanogramValidationException::PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE
    && $machineTypeException->getHttpStatusCode() === 409
    && ($machineTypeException->getContext()['conflicts'][0]['slot_code'] ?? '') === '11'
    && ($machineTypeException->getContext()['conflicts'][0]['sku'] ?? '') === 'SAND-JAMON'
    && ($machineTypeException->getContext()['conflicts'][0]['reason'] ?? '') === PlanogramValidationException::COLD_CHAIN_MACHINE_INCOMPATIBLE,
    $machineTypeException === null ? 'el cambio se aceptó' : json_encode($machineTypeException->getContext())
);

$assertRejects(
    '8.2 Cambiar a COLD_DRINKS con un carril de snack se rechaza por categoría',
    static fn (): mixed => PlanogramCompatibilityValidator::assertMachineTypeChange(
        MachineType::COLD_DRINKS,
        [['slot' => $snackSlot, 'product' => $snackBar]]
    ),
    PlanogramValidationException::PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE,
    409
);

$compatibleChange = $rejectionOf(static function () use ($sandwich, $snackBar, $conflictSlot, $snackSlot): void {
    PlanogramCompatibilityValidator::assertMachineTypeChange(
        MachineType::COMBO,
        [
            ['slot' => $conflictSlot, 'product' => $sandwich],
            ['slot' => $snackSlot, 'product' => $snackBar],
        ]
    );
});
$assert('8.3 COMBO admite todos los carriles activos del escenario', $compatibleChange === null);

// ─────────────────────────────────────────────────────────────────────
// 9. Identidad inmutable del SKU (C1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 9. Identidad inmutable del SKU (C1) ---\n";

$sameSkuAccepted = $rejectionOf(static function (): void {
    PlanogramCompatibilityValidator::assertSkuUnchanged('beb-cola-33', ' BEB-COLA-33 ');
});
$assert('9.1 La misma forma normalizada del SKU no bloquea la edición', $sameSkuAccepted === null);

$assertRejects(
    '9.2 Cambiar el SKU tras el alta se rechaza con SKU_CHANGE_FORBIDDEN',
    static fn (): mixed => PlanogramCompatibilityValidator::assertSkuUnchanged('BEB-COLA-33', 'BEB-COLA-50'),
    PlanogramValidationException::SKU_CHANGE_FORBIDDEN,
    409
);

// ─────────────────────────────────────────────────────────────────────
// 10. Constructores semánticos de la excepción
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 10. Constructores semánticos de la excepción ---\n";

$inactiveException = PlanogramValidationException::productInactive('SNACK-BAR-01');
$assert(
    '10.1 productInactive responde 422 PRODUCT_INACTIVE y conserva el contexto',
    $inactiveException->getHttpStatusCode() === 422
    && $inactiveException->getErrorCode() === PlanogramValidationException::PRODUCT_INACTIVE
    && $inactiveException->getContext()['sku'] === 'SNACK-BAR-01'
);

$boundsException = PlanogramValidationException::invalidSlotBounds('max_capacity', 'fuera de rango');
$assert(
    '10.2 invalidSlotBounds responde 422 y nombra el campo',
    $boundsException->getHttpStatusCode() === 422
    && $boundsException->getField() === 'max_capacity'
    && $boundsException->getErrorCode() === PlanogramValidationException::INVALID_PLANOGRAM_SLOT_BOUNDS
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-03 (dominio del planograma) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
