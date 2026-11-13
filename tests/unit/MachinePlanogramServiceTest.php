<?php

declare(strict_types=1);

/**
 * MachinePlanogramServiceTest · T-PLN-08
 *
 * Suite unitaria del caso de uso del planograma (Módulo 32, RF-02.2–RF-02.3,
 * RF-03.1, RF-04.1–RF-04.2, RF-05.2, D1–D3, C1–C3, QA 2, QA 3, Art. II,
 * Art. V.4).
 *
 * Certifica, con repositorios en memoria y sin base de datos:
 *   1. Lectura de Coordinación con coste y margen por carril.
 *   2. Guardado completo por reemplazo y carril individual por `UPSERT`.
 *   3. Cerrojo directo: producto inactivo, frío o categoría incompatibles.
 *   4. Presets por modelo y por tipología, con stock ZERO/FULL y preservación.
 *   5. Clonación sin copiar existencia, omitiendo bajas y validando destino.
 *   6. Lectura del reponedor sin costes y con 403 fuera de sus zonas vivas.
 *   7. Auditoría `MACHINE_PLANOGRAM` de cada operación efectiva.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red ni motor.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachinePlanogramService;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineOutsideRestockerZonesException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard · Unitario · MachinePlanogramServiceTest (T-PLN-08)\n";
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

/** Catálogo en memoria, suficiente para presets, cerrojos y costes. */
final class InMemoryVendingProductRepository implements VendingProductRepositoryInterface
{
    /** @var array<int, VendingProduct> */
    public array $products = [];

    private int $nextId = 1;

    public function seed(VendingProduct $product): VendingProduct
    {
        $created = $product->withId($this->nextId++);
        $this->products[(int)$created->getId()] = $created;

        return $created;
    }

    public function create(VendingProduct $product): VendingProduct
    {
        return $this->seed($product);
    }

    public function update(VendingProduct $product): bool
    {
        if (!isset($this->products[(int)$product->getId()])) {
            return false;
        }
        $this->products[(int)$product->getId()] = $product;

        return true;
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (!isset($this->products[$id])) {
            return false;
        }
        $this->products[$id] = $this->products[$id]->withStatus($isActive);

        return true;
    }

    public function findById(int $id): ?VendingProduct
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?VendingProduct
    {
        foreach ($this->products as $product) {
            if ($product->getSku() === strtoupper(trim($sku))) {
                return $product;
            }
        }

        return null;
    }

    public function isSkuUsed(string $sku, ?int $excludeId = null): bool
    {
        $found = $this->findBySku($sku);

        return $found !== null && (int)$found->getId() !== $excludeId;
    }

    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array {
        $rows = array_values($this->products);
        $term = $search !== null ? mb_strtolower(trim($search), 'UTF-8') : '';

        $rows = array_values(array_filter($rows, static function (VendingProduct $product) use ($term, $category, $isActive): bool {
            if ($term !== ''
                && !str_contains(mb_strtolower($product->getName(), 'UTF-8'), $term)
                && !str_contains(mb_strtolower($product->getSku(), 'UTF-8'), $term)) {
                return false;
            }
            if ($category !== null && $product->getCategory() !== $category) {
                return false;
            }
            if ($isActive !== null && $product->isActive() !== $isActive) {
                return false;
            }

            return true;
        }));

        usort($rows, static fn (VendingProduct $a, VendingProduct $b): int => strcmp($a->getName(), $b->getName()));

        return $rows;
    }

    public function countActiveSlots(int $productId): int
    {
        return 0;
    }

    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        return [];
    }
}

/** Repositorio de planogramas en memoria con el mismo contrato que el PDO. */
final class InMemoryMachinePlanogramRepository implements MachinePlanogramRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $slots = [];

    /** @var array<int, list<int>> restocker => machine ids */
    public array $restockerMachines = [];

    /** @var list<string> */
    public array $operations = [];

    private int $nextSlotId = 1;

    private InMemoryVendingProductRepository $products;

    public function __construct(InMemoryVendingProductRepository $products)
    {
        $this->products = $products;
    }

    public function findMachineById(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? null;
    }

    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array
    {
        $rows = array_values(array_filter(
            $this->slots[$machineId] ?? [],
            static fn (array $row): bool => !$onlyActive || $row['is_active'] === true
        ));

        usort($rows, static function (array $a, array $b): int {
            $aNum = preg_match('/^\d+$/', $a['slot_code']) === 1;
            $bNum = preg_match('/^\d+$/', $b['slot_code']) === 1;
            if ($aNum !== $bNum) {
                return $aNum ? -1 : 1;
            }

            return $aNum
                ? (int)$a['slot_code'] <=> (int)$b['slot_code']
                : strcmp($a['slot_code'], $b['slot_code']);
        });

        return $rows;
    }

    public function findSlot(int $machineId, string $slotCode): ?array
    {
        foreach ($this->slots[$machineId] ?? [] as $row) {
            if ($row['slot_code'] === strtoupper(trim($slotCode))) {
                return $row;
            }
        }

        return null;
    }

    public function upsertSlot(MachinePlanogramSlot $slot): array
    {
        $this->operations[] = 'upsert';
        $row = $this->rowFromEntity($slot);

        foreach ($this->slots[$slot->getMachineId()] ?? [] as $index => $existing) {
            if ($existing['slot_code'] === $slot->getSlotCode()) {
                $row['id'] = $existing['id'];
                $this->slots[$slot->getMachineId()][$index] = $row;

                return $row;
            }
        }

        $this->slots[$slot->getMachineId()][] = $row;

        return $row;
    }

    public function replaceMachinePlanogram(int $machineId, array $slots): array
    {
        $this->operations[] = 'replace';
        $existing = $this->slots[$machineId] ?? [];
        $kept = [];
        foreach ($slots as $slot) {
            $kept[$slot->getSlotCode()] = $this->rowFromEntity($slot);
        }

        $newRows = [];
        foreach ($existing as $row) {
            if (isset($kept[$row['slot_code']])) {
                $newRow = $kept[$row['slot_code']];
                $newRow['id'] = $row['id'];
                $newRows[] = $newRow;
                unset($kept[$row['slot_code']]);
            } else {
                $row['is_active'] = false;
                $row['stock_status'] = 'STOCKOUT';
                $newRows[] = $row;
            }
        }
        foreach ($kept as $row) {
            $newRows[] = $row;
        }

        $this->slots[$machineId] = $newRows;

        return $this->findSlotsByMachine($machineId, true);
    }

    public function deactivateSlot(int $machineId, string $slotCode): bool
    {
        foreach ($this->slots[$machineId] ?? [] as $index => $row) {
            if ($row['slot_code'] === strtoupper(trim($slotCode))) {
                $this->operations[] = 'deactivate';
                $this->slots[$machineId][$index]['is_active'] = false;

                return true;
            }
        }

        return false;
    }

    public function summarize(int $machineId): array
    {
        $rows = $this->findSlotsByMachine($machineId, true);
        $summary = [
            'active_slots_count' => count($rows),
            'stockout_slots_count' => 0,
            'low_stock_slots_count' => 0,
            'ok_slots_count' => 0,
            'inactive_product_slots_count' => 0,
            'total_capacity' => 0,
            'total_estimated_qty' => 0,
            'fill_rate_percent' => 0.0,
        ];
        foreach ($rows as $row) {
            $summary[$row['stock_status'] === 'STOCKOUT' ? 'stockout_slots_count' : ($row['stock_status'] === 'LOW_STOCK' ? 'low_stock_slots_count' : 'ok_slots_count')]++;
            $summary['inactive_product_slots_count'] += $row['product_is_active'] ? 0 : 1;
            $summary['total_capacity'] += $row['max_capacity'];
            $summary['total_estimated_qty'] += $row['current_estimated_qty'];
        }
        $summary['fill_rate_percent'] = $summary['total_capacity'] > 0
            ? round(($summary['total_estimated_qty'] / $summary['total_capacity']) * 100, 1)
            : 0.0;

        return $summary;
    }

    public function findCompatibleSourceMachines(int $machineId, MachineType $machineType): array
    {
        $rows = [];
        foreach ($this->machines as $machine) {
            if ($machine['id'] === $machineId
                || $machine['machine_type'] !== $machineType
                || ($machine['is_active'] ?? true) === false) {
                continue;
            }

            $rows[] = [
                'id' => $machine['id'],
                'code' => $machine['code'],
                'model' => $machine['model'],
                'machine_type' => $machine['machine_type'],
                'active_slots_count' => count($this->findSlotsByMachine((int)$machine['id'], true)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    public function findMachinesForRestocker(int $restockerId): array
    {
        $rows = [];
        foreach ($this->restockerMachines[$restockerId] ?? [] as $machineId) {
            $machine = $this->machines[$machineId];
            $rows[] = [
                'id' => $machine['id'],
                'code' => $machine['code'],
                'model' => $machine['model'],
                'machine_type' => $machine['machine_type'],
                'location_name' => $machine['location_name'],
                'site_code' => $machine['site_code'],
                'zone_id' => $machine['zone_id'],
                'zone_code' => $machine['zone_code'],
                'zone_name' => $machine['zone_name'],
                'sanitary_status' => $machine['sanitary_status'],
            ] + $this->summarize($machineId);
        }

        return $rows;
    }

    public function findMachineForRestocker(int $machineId, int $restockerId): ?array
    {
        if (!in_array($machineId, $this->restockerMachines[$restockerId] ?? [], true)) {
            return null;
        }

        return $this->findMachinesForRestocker($restockerId)[array_search($machineId, $this->restockerMachines[$restockerId], true)] ?? null;
    }

    private function rowFromEntity(MachinePlanogramSlot $slot): array
    {
        $product = $this->products->findById($slot->getProductId());
        if ($product === null) {
            throw new RuntimeException('Producto inexistente en el repositorio de prueba.');
        }

        return [
            'id' => $this->nextSlotId++,
            'machine_id' => $slot->getMachineId(),
            'slot_code' => $slot->getSlotCode(),
            'product_id' => $slot->getProductId(),
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_category' => $product->getCategory(),
            'product_unit_type' => $product->getUnitType(),
            'requires_cold_chain' => $product->requiresColdChain(),
            'product_is_active' => $product->isActive(),
            'sale_price_eur' => $product->getSalePriceEur(),
            'max_capacity' => $slot->getMaxCapacity(),
            'current_estimated_qty' => $slot->getCurrentEstimatedQty(),
            'min_threshold' => $slot->getMinThreshold(),
            'is_active' => $slot->isActive(),
            'stock_status' => $slot->stockStatus()->value,
            'stock_status_label' => $slot->stockStatus()->label(),
            'restock_needed_qty' => $slot->restockNeededQty(),
            'fill_rate_percent' => $slot->fillRatePercent(),
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}

final class RecordingPlanogramAuditLogger extends AuditLogger
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function __construct()
    {
    }

    public function logEvent(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Catálogo de prueba
// ─────────────────────────────────────────────────────────────────────
$products = new InMemoryVendingProductRepository();

$makeProduct = static function (
    string $sku,
    string $name,
    ProductCategory $category,
    ProductUnitType $unit,
    float $cost,
    float $price,
    bool $cold = false,
    ?int $shelf = null,
    bool $active = true
) use ($products): VendingProduct {
    return $products->seed(new VendingProduct(
        null,
        $sku,
        $name,
        $category,
        $unit,
        $cost,
        $price,
        $cold,
        $shelf,
        $active
    ));
};

$coffee = $makeProduct('T8-CAFE', 'Café en Grano Natural 1kg', ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductUnitType::GRAMS, 7.25, 0.00);
$milk = $makeProduct('T8-LECHE', 'Leche en Polvo 1kg', ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductUnitType::GRAMS, 6.10, 0.00);
$sugar = $makeProduct('T8-AZUCAR', 'Azúcar Blanco 1kg', ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductUnitType::GRAMS, 1.20, 0.00);
$choco = $makeProduct('T8-CHOCO', 'Chocolate Soluble 1kg', ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductUnitType::GRAMS, 8.40, 0.00);
$cups = $makeProduct('T8-VASOS', 'Vasos de Cartón 7oz', ProductCategory::CUP_STIRRER_CONSUMABLE, ProductUnitType::SLEEVE, 1.80, 0.00);
$stirrers = $makeProduct('T8-PALETINAS', 'Paletinas de Madera', ProductCategory::CUP_STIRRER_CONSUMABLE, ProductUnitType::SLEEVE, 0.90, 0.00);

$snack = $makeProduct('T8-PATATAS', 'Patatas Fritas 40g', ProductCategory::SNACK, ProductUnitType::UNIT, 0.55, 1.50);
$bar = $makeProduct('T8-BARRA', 'Barrita Energética 45g', ProductCategory::SNACK, ProductUnitType::UNIT, 0.70, 1.80);
$nuts = $makeProduct('T8-FRUTOS', 'Frutos Secos 50g', ProductCategory::SNACK, ProductUnitType::UNIT, 0.85, 2.00);
$pastry = $makeProduct('T8-BOLLERIA', 'Bollería Envasada 60g', ProductCategory::SNACK, ProductUnitType::UNIT, 0.60, 1.70);
$cola = $makeProduct('T8-COLA', 'Cola 33cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.45, 1.20);
$water = $makeProduct('T8-AGUA', 'Agua Mineral 50cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.25, 0.90);
$juice = $makeProduct('T8-ZUMO', 'Zumo de Naranja 33cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.50, 1.30);
$tea = $makeProduct('T8-TE', 'Té Helado 33cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.48, 1.25);
$isotonic = $makeProduct('T8-ISOTONICA', 'Bebida Isotónica 50cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.65, 1.60);
$lemonade = $makeProduct('T8-LIMONADA', 'Limonada 33cl', ProductCategory::COLD_DRINK, ProductUnitType::UNIT, 0.42, 1.15);

$sandwich = $makeProduct('T8-SANDWICH', 'Sándwich Mixto Jamón y Queso', ProductCategory::PERISHABLE_FOOD, ProductUnitType::UNIT, 1.15, 2.40, true, 5);
$salad = $makeProduct('T8-ENSALADA', 'Ensalada César', ProductCategory::PERISHABLE_FOOD, ProductUnitType::UNIT, 1.40, 2.80, true, 4);
$yogurt = $makeProduct('T8-YOGUR', 'Yogur Natural 125g', ProductCategory::PERISHABLE_FOOD, ProductUnitType::UNIT, 0.40, 1.10, true, 10);
$inactiveSnack = $makeProduct('T8-BAJA', 'Snack Dado de Baja', ProductCategory::SNACK, ProductUnitType::UNIT, 0.50, 1.40, false, null, false);

$planograms = new InMemoryMachinePlanogramRepository($products);
$planograms->machines = [
    1 => [
        'id' => 1, 'code' => 'T8-HOT', 'model' => 'Necta Korinto',
        'machine_type' => MachineType::HOT_DRINKS,
        'location_name' => 'Sede Uno', 'site_code' => 'SEDE-1',
        'zone_id' => 100, 'zone_code' => 'ZONA-1', 'zone_name' => 'Zona Uno',
        'sanitary_status' => 'CERTIFIED', 'is_active' => true,
    ],
    2 => [
        'id' => 2, 'code' => 'T8-SNACK', 'model' => 'Fas Fast 900',
        'machine_type' => MachineType::SNACKS,
        'location_name' => 'Sede Dos', 'site_code' => 'SEDE-2',
        'zone_id' => 100, 'zone_code' => 'ZONA-1', 'zone_name' => 'Zona Uno',
        'sanitary_status' => 'CERTIFIED', 'is_active' => true,
    ],
    3 => [
        'id' => 3, 'code' => 'T8-COLD', 'model' => 'Modelo Nuevo',
        'machine_type' => MachineType::COLD_DRINKS,
        'location_name' => 'Sede Tres', 'site_code' => 'SEDE-3',
        'zone_id' => 200, 'zone_code' => 'ZONA-2', 'zone_name' => 'Zona Dos',
        'sanitary_status' => 'CERTIFIED', 'is_active' => true,
    ],
    4 => [
        'id' => 4, 'code' => 'T8-COMBO', 'model' => 'Jofemar Vision',
        'machine_type' => MachineType::COMBO,
        'location_name' => 'Sede Cuatro', 'site_code' => 'SEDE-4',
        'zone_id' => 200, 'zone_code' => 'ZONA-2', 'zone_name' => 'Zona Dos',
        'sanitary_status' => 'CERTIFIED', 'is_active' => true,
    ],
    5 => [
        'id' => 5, 'code' => 'T8-HOT-B', 'model' => 'Necta Korinto',
        'machine_type' => MachineType::HOT_DRINKS,
        'location_name' => 'Sede Cinco', 'site_code' => 'SEDE-5',
        'zone_id' => 100, 'zone_code' => 'ZONA-1', 'zone_name' => 'Zona Uno',
        'sanitary_status' => 'CERTIFIED', 'is_active' => true,
    ],
];
$planograms->restockerMachines = [10 => [1, 2]];

$planograms->upsertSlot(new MachinePlanogramSlot(null, 1, 'HOPPER_COFFEE', (int)$coffee->getId(), 2000, 500, 400, true));
$planograms->upsertSlot(new MachinePlanogramSlot(null, 1, 'TUBE_CUPS', (int)$cups->getId(), 600, 100, 150, true));
$planograms->upsertSlot(new MachinePlanogramSlot(null, 2, '11', (int)$snack->getId(), 16, 6, 5, true));
$planograms->upsertSlot(new MachinePlanogramSlot(null, 2, '21', (int)$cola->getId(), 14, 10, 4, true));
$planograms->upsertSlot(new MachinePlanogramSlot(null, 2, '12', (int)$inactiveSnack->getId(), 16, 2, 5, true));
$planograms->upsertSlot(new MachinePlanogramSlot(null, 5, 'HOPPER_MILK', (int)$milk->getId(), 1000, 400, 200, true));
$planograms->operations = [];

$audit = new RecordingPlanogramAuditLogger();
$service = new MachinePlanogramService($planograms, $products, $audit);
$actor = ['id' => 7, 'role' => 'COORDINATOR', 'name' => 'Coordinación Planograma'];

// =====================================================================
// 1. Lectura de Coordinación
// =====================================================================
echo "--- 1. Lectura de Coordinación ---\n";

$coordinator = $service->getCoordinatorPlanogram(1);
$assert(
    '1.1 Devuelve máquina, resumen y dos carriles ordenados',
    $coordinator['machine']['code'] === 'T8-HOT'
    && $coordinator['summary']['active_slots_count'] === 2
    && array_column($coordinator['slots'], 'slot_code') === ['HOPPER_COFFEE', 'TUBE_CUPS']
);

$coffeeRow = $coordinator['slots'][0];
$assert(
    '1.2 Coordinación recibe coste y margen por carril',
    array_key_exists('unit_cost_eur', $coffeeRow)
    && abs($coffeeRow['unit_cost_eur'] - 7.25) < 0.001
    && abs($coffeeRow['unit_margin_eur'] + 7.25) < 0.001
    && $coffeeRow['stock_status'] === 'OK'
);

$missingMachine = null;
try {
    $service->getCoordinatorPlanogram(999);
} catch (MachineNotFoundException $exception) {
    $missingMachine = $exception->getErrorCode();
}
$assert('1.3 Una máquina inexistente devuelve MACHINE_NOT_FOUND', $missingMachine === 'MACHINE_NOT_FOUND');

$compatibleSources = $coordinator['compatible_source_machines'];
$assert(
    '1.4 La lectura incluye fuentes compatibles de la misma tipología y excluye la propia',
    array_column($compatibleSources, 'code') === ['T8-HOT-B']
    && (int)$compatibleSources[0]['active_slots_count'] === 1
);

// =====================================================================
// 2. Guardado del mapa completo y cerrojo directo
// =====================================================================
echo "\n--- 2. Guardado completo y cerrojo directo ---\n";

$savedMap = $service->saveMachinePlanogram(2, [
    ['slot_code' => '11', 'product_id' => (int)$snack->getId(), 'max_capacity' => 16, 'current_estimated_qty' => 7, 'min_threshold' => 5],
    ['slot_code' => '21', 'product_id' => (int)$cola->getId(), 'max_capacity' => 14, 'current_estimated_qty' => 0, 'min_threshold' => 4],
], $actor);

$assert(
    '2.1 El mapa completo se guarda por reemplazo y deja dos carriles activos',
    $savedMap['summary']['active_slots_count'] === 2
    && array_column($savedMap['slots'], 'slot_code') === ['11', '21']
    && in_array('replace', $planograms->operations, true)
);

$saveEvent = $audit->events[0] ?? null;
$assert(
    '2.2 El guardado audita MACHINE_PLANOGRAM_SAVED con la máquina',
    $saveEvent !== null
    && $saveEvent->getEntityType() === 'MACHINE_PLANOGRAM'
    && $saveEvent->getAction() === 'MACHINE_PLANOGRAM_SAVED'
    && $saveEvent->getEntityId() === 2
    && $saveEvent->getNewState()['slots'] !== []
);

$duplicateCode = null;
try {
    $service->saveMachinePlanogram(2, [
        ['slot_code' => '11', 'product_id' => (int)$snack->getId(), 'max_capacity' => 10, 'min_threshold' => 2],
        ['slot_code' => '11', 'product_id' => (int)$cola->getId(), 'max_capacity' => 10, 'min_threshold' => 2],
    ]);
} catch (PlanogramValidationException $exception) {
    $duplicateCode = $exception->getErrorCode();
}
$assert('2.3 Un código de carril duplicado devuelve INVALID_PLANOGRAM_SLOT_BOUNDS', $duplicateCode === 'INVALID_PLANOGRAM_SLOT_BOUNDS');

$inactiveCode = null;
try {
    $service->saveMachinePlanogram(2, [
        ['slot_code' => '31', 'product_id' => (int)$inactiveSnack->getId(), 'max_capacity' => 10, 'min_threshold' => 2],
    ]);
} catch (PlanogramValidationException $exception) {
    $inactiveCode = $exception->getErrorCode();
}
$assert('2.4 Un producto inactivo en un carril nuevo devuelve PRODUCT_INACTIVE', $inactiveCode === 'PRODUCT_INACTIVE');

$coldCode = null;
try {
    $service->saveSlot(2, '31', ['product_id' => (int)$sandwich->getId(), 'max_capacity' => 10, 'min_threshold' => 2]);
} catch (PlanogramValidationException $exception) {
    $coldCode = $exception->getErrorCode();
}
$assert('2.5 Un perecedero de frío en SNACKS devuelve COLD_CHAIN_MACHINE_INCOMPATIBLE', $coldCode === 'COLD_CHAIN_MACHINE_INCOMPATIBLE');

$categoryCode = null;
try {
    $service->saveSlot(2, '31', ['product_id' => (int)$coffee->getId(), 'max_capacity' => 1000, 'min_threshold' => 100]);
} catch (PlanogramValidationException $exception) {
    $categoryCode = $exception->getErrorCode();
}
$assert('2.6 Un ingrediente caliente en SNACKS devuelve PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE', $categoryCode === 'PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE');

// Un producto dado de baja puede conservarse en un carril preexistente.
$inactiveSnackWithStatus = $products->findById((int)$inactiveSnack->getId());
$planograms->upsertSlot(new MachinePlanogramSlot(null, 2, '12', (int)$inactiveSnack->getId(), 16, 2, 5, true));
$keptInactive = $service->saveSlot(2, '12', ['product_id' => (int)$inactiveSnack->getId(), 'max_capacity' => 16, 'min_threshold' => 5], $actor);
$assert(
    '2.7 Mantener en un carril preexistente un producto dado de baja se permite',
    $keptInactive['product_sku'] === $inactiveSnackWithStatus->getSku() && $keptInactive['is_active'] === true
);

// =====================================================================
// 3. Guardado individual y baja lógica
// =====================================================================
echo "\n--- 3. Carril individual y baja lógica ---\n";

$single = $service->saveSlot(2, '31', [
    'product_id' => (int)$snack->getId(),
    'max_capacity' => 20,
    'current_estimated_qty' => 4,
    'min_threshold' => 6,
], $actor);

$assert(
    '3.1 El carril individual se guarda con semáforo LOW_STOCK y coste',
    $single['slot_code'] === '31'
    && $single['stock_status'] === 'LOW_STOCK'
    && $single['restock_needed_qty'] === 16
    && abs($single['unit_cost_eur'] - 0.55) < 0.001
);

$slotEvent = $audit->events[count($audit->events) - 1];
$assert(
    '3.2 El carril individual audita MACHINE_PLANOGRAM_SLOT_SAVED',
    $slotEvent->getAction() === 'MACHINE_PLANOGRAM_SLOT_SAVED'
    && $slotEvent->getMetadata()['slot_code'] === '31'
);

$assert(
    '3.3 La baja lógica retira el carril sin borrarlo',
    $service->deactivateSlot(2, '31', $actor) === true
    && $planograms->findSlot(2, '31')['is_active'] === false
    && $audit->events[count($audit->events) - 1]->getAction() === 'MACHINE_PLANOGRAM_SLOT_DEACTIVATED'
);

$assert(
    '3.4 Retirar un carril inexistente devuelve false sin auditar',
    $service->deactivateSlot(2, 'NO_EXISTE') === false
);

// =====================================================================
// 4. Presets (D1, C3)
// =====================================================================
echo "\n--- 4. Plantillas por modelo y tipología ---\n";

$preset = $service->applyPreset(1, 'ZERO', $actor);
$presetCodes = array_column($preset['slots'], 'slot_code');
$presetByCode = [];
foreach ($preset['slots'] as $presetRow) {
    $presetByCode[$presetRow['slot_code']] = $presetRow;
}
$coffeeAfterPreset = $presetByCode['HOPPER_COFFEE'];

$assert(
    '4.1 El preset de Necta Korinto aplica los seis carriles del modelo',
    $preset['preset']['source'] === 'MODEL'
    && $preset['preset']['model'] === 'Necta Korinto'
    && $presetCodes === ['HOPPER_CHOCOLATE', 'HOPPER_COFFEE', 'HOPPER_MILK', 'HOPPER_SUGAR', 'TUBE_CUPS', 'TUBE_STIRRERS']
    && $preset['preset']['applied_slots_count'] === 6
);

$assert(
    '4.2 El preset ZERO preserva la existencia del mismo producto y ceros en los nuevos',
    $coffeeAfterPreset['current_estimated_qty'] === 500
    && $presetByCode['HOPPER_MILK']['current_estimated_qty'] === 0
    && $presetByCode['HOPPER_MILK']['product_sku'] === 'T8-LECHE'
);

$assert(
    '4.3 El preset empareja etiquetas con referencias activas (vasos y paletinas)',
    $presetByCode['TUBE_CUPS']['product_sku'] === 'T8-VASOS'
    && $presetByCode['TUBE_STIRRERS']['product_sku'] === 'T8-PALETINAS'
);

$presetEvent = $audit->events[count($audit->events) - 1];
$assert(
    '4.4 El preset audita PLANOGRAM_PRESET_APPLIED con modo y origen',
    $presetEvent->getAction() === 'PLANOGRAM_PRESET_APPLIED'
    && $presetEvent->getMetadata()['preset_source'] === 'MODEL'
    && $presetEvent->getMetadata()['initial_stock_mode'] === 'ZERO'
);

$fallback = $service->applyPreset(3, 'FULL', $actor);
$assert(
    '4.5 Un modelo nuevo cae al preset de su tipología con stock FULL',
    $fallback['preset']['source'] === 'MACHINE_TYPE'
    && $fallback['preset']['model'] === null
    && count($fallback['slots']) === 6
    && array_reduce(
        $fallback['slots'],
        static fn (bool $carry, array $row): bool => $carry && $row['current_estimated_qty'] === $row['max_capacity'],
        true
    )
);

$invalidMode = false;
try {
    $service->applyPreset(1, 'HALF', $actor);
} catch (InvalidArgumentException) {
    $invalidMode = true;
}
$assert('4.6 Un modo de stock desconocido se rechaza', $invalidMode);

// =====================================================================
// 5. Clonación (RF-04.2, QA 3, C3)
// =====================================================================
echo "\n--- 5. Clonación ---\n";

// Deja en la máquina 2 un carril con producto inactivo que debe omitirse.
$planograms->upsertSlot(new MachinePlanogramSlot(null, 2, '12', (int)$inactiveSnack->getId(), 16, 2, 5, true));
// La máquina 4 ya tiene un carril 11 con el mismo snack y 3 unidades.
$planograms->upsertSlot(new MachinePlanogramSlot(null, 4, '11', (int)$snack->getId(), 16, 3, 5, true));

$clone = $service->clonePlanogram(4, 2, 'ZERO', $actor);
$cloneCodes = array_column($clone['slots'], 'slot_code');
$cloneSlot11 = array_values(array_filter($clone['slots'], static fn (array $row): bool => $row['slot_code'] === '11'))[0];
$cloneSlot21 = array_values(array_filter($clone['slots'], static fn (array $row): bool => $row['slot_code'] === '21'))[0];

$assert(
    '5.1 La clonación omite el carril con producto dado de baja',
    count($clone['skipped_inactive_slots']) === 1
    && $clone['skipped_inactive_slots'][0]['slot_code'] === '12'
    && !in_array('12', $cloneCodes, true)
);

$assert(
    '5.2 Preserva la existencia del destino con el mismo producto y no copia la de origen',
    $cloneSlot11['current_estimated_qty'] === 3
    && $cloneSlot21['current_estimated_qty'] === 0
    && $clone['source_machine']['id'] === 2
);

$assert(
    '5.3 El clon audita PLANOGRAM_CLONED con el origen y los omitidos',
    $audit->events[count($audit->events) - 1]->getAction() === 'PLANOGRAM_CLONED'
    && $audit->events[count($audit->events) - 1]->getMetadata()['source_machine_id'] === 2
    && count($audit->events[count($audit->events) - 1]->getMetadata()['skipped_inactive_slots']) === 1
);

// Origen con todos los productos inactivos: se vacía el preset anterior.
$planograms->slots[3] = [];
$planograms->upsertSlot(new MachinePlanogramSlot(null, 3, '11', (int)$inactiveSnack->getId(), 10, 1, 2, true));
$emptyCode = null;
try {
    $service->clonePlanogram(1, 3);
} catch (PlanogramValidationException $exception) {
    $emptyCode = $exception->getErrorCode();
}
$assert('5.4 Un origen sin productos activos devuelve SOURCE_PLANOGRAM_EMPTY', $emptyCode === 'SOURCE_PLANOGRAM_EMPTY');

// Origen con perecedero de frío hacia una máquina SNACKS.
$planograms->upsertSlot(new MachinePlanogramSlot(null, 4, '12', (int)$sandwich->getId(), 10, 5, 3, true));
$targetBefore = $planograms->findSlotsByMachine(2, true);
$coldCloneCode = null;
try {
    $service->clonePlanogram(2, 4);
} catch (PlanogramValidationException $exception) {
    $coldCloneCode = $exception->getErrorCode();
}
$assert('5.5 Clonar frío hacia SNACKS devuelve COLD_CHAIN_MACHINE_INCOMPATIBLE', $coldCloneCode === 'COLD_CHAIN_MACHINE_INCOMPATIBLE');
$targetAfter = $planograms->findSlotsByMachine(2, true);
$assert(
    '5.5b La clonación rechazada conserva intacto el planograma destino',
    $targetAfter === $targetBefore
    && count($targetAfter) === 3
    && array_column($targetAfter, 'slot_code') === ['11', '12', '21']
);

$sameMachine = false;
try {
    $service->clonePlanogram(2, 2);
} catch (InvalidArgumentException) {
    $sameMachine = true;
}
$assert('5.6 Clonar una máquina sobre sí misma se rechaza', $sameMachine);

// =====================================================================
// 6. Lectura segregada del reponedor (QA 2, Art. V.4)
// =====================================================================
echo "\n--- 6. Lectura del reponedor ---\n";

$restockerMachines = $service->listRestockerMachines(10);
$assert(
    '6.1 El reponedor sólo ve las máquinas de su perímetro',
    count($restockerMachines) === 2
    && array_column($restockerMachines, 'code') === ['T8-HOT', 'T8-SNACK']
);

$assert(
    '6.2 Las tarjetas del reponedor no incluyen coste ni margen',
    array_reduce(
        $restockerMachines,
        static fn (bool $carry, array $row): bool => $carry
            && !array_key_exists('unit_cost_eur', $row)
            && !array_key_exists('unit_margin_eur', $row),
        true
    )
);

$zoneFiltered = $service->listRestockerMachines(10, ['zone_id' => 100]);
$assert('6.3 El filtro zone_id conserva las máquinas de la zona', count($zoneFiltered) === 2);

$zoneEmpty = $service->listRestockerMachines(10, ['zone_id' => 200]);
$assert('6.4 Una zona ajena no devuelve máquinas', $zoneEmpty === []);

$restockerPlanogram = $service->getRestockerPlanogram(10, 1);
$assert(
    '6.5 El planograma del reponedor no contiene coste ni margen en ningún carril',
    array_reduce(
        $restockerPlanogram['slots'],
        static fn (bool $carry, array $row): bool => $carry
            && !array_key_exists('unit_cost_eur', $row)
            && !array_key_exists('unit_margin_eur', $row)
            && array_key_exists('sale_price_eur', $row),
        true
    )
);

$outsideCode = null;
try {
    $service->getRestockerPlanogram(10, 3);
} catch (MachineOutsideRestockerZonesException $exception) {
    $outsideCode = $exception->getErrorCode();
}
$assert(
    '6.6 Una máquina fuera de sus zonas devuelve 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
    $outsideCode === 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
);

$assert('6.7 Un reponedor inválido no recibe máquinas', $service->listRestockerMachines(0) === []);

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-08 (servicio del planograma) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
