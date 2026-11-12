<?php

declare(strict_types=1);

/**
 * VendingProductServiceTest · T-PLN-07
 *
 * Suite unitaria del caso de uso del catálogo (Módulo 32, RF-01.1–RF-01.3,
 * RF-03.2, C1–C3, D3, QA 1, Art. II, Art. III.3).
 *
 * Certifica, con repositorios en memoria y sin base de datos:
 *   1. Listado con KPIs, filtros y `active_slots_count` por referencia.
 *   2. Alta con las cotas, unidades, frío y vida útil del contrato.
 *   3. Unicidad del SKU traducida a 409 `DUPLICATE_PRODUCT_SKU`.
 *   4. Edición con SKU inmutable (409 `SKU_CHANGE_FORBIDDEN`).
 *   5. Cerrojo inverso: categoría/frío incompatibles con las máquinas donde el
 *      producto tiene carriles activos (409, sin persistir).
 *   6. Baja lógica y reactivación con `affected_active_slots_count`.
 *   7. Auditoría `VENDING_PRODUCT_CREATED/UPDATED/ACTIVATED/DEACTIVATED`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red ni motor.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\VendingProductService;
use VendGuard\Core\Domain\Exception\DuplicateProductSkuException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Exception\ProductNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard · Unitario · VendingProductServiceTest (T-PLN-07)\n";
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
 * Repositorio en memoria del catálogo, con asignaciones de máquina controladas
 * por la suite para ejercitar el cerrojo inverso.
 */
final class InMemoryVendingProductRepository implements VendingProductRepositoryInterface
{
    /** @var array<int, VendingProduct> */
    public array $products = [];

    /** @var array<int, list<MachineType>> */
    public array $assignments = [];

    /** @var array<int, int> */
    public array $slotCounts = [];

    /** @var list<string> */
    public array $operations = [];

    private int $nextId = 1;

    public function seed(VendingProduct $product): VendingProduct
    {
        $created = $product->withId($this->nextId++);
        $this->products[(int)$created->getId()] = $created;

        return $created;
    }

    public function create(VendingProduct $product): VendingProduct
    {
        if ($this->isSkuUsed($product->getSku())) {
            throw DuplicateProductSkuException::forSku($product->getSku());
        }

        $this->operations[] = 'create';
        $created = new VendingProduct(
            $this->nextId++,
            $product->getSku(),
            $product->getName(),
            $product->getCategory(),
            $product->getUnitType(),
            $product->getUnitCostEur(),
            $product->getSalePriceEur(),
            $product->requiresColdChain(),
            $product->getMaxShelfLifeDays(),
            $product->isActive(),
            '2026-11-12T10:00:00+01:00',
            '2026-11-12T10:00:00+01:00'
        );
        $this->products[(int)$created->getId()] = $created;

        return $created;
    }

    public function update(VendingProduct $product): bool
    {
        $id = (int)$product->getId();
        $current = $this->products[$id] ?? null;
        if ($current === null) {
            return false;
        }
        if ($current->getSku() !== $product->getSku()) {
            throw PlanogramValidationException::skuChangeForbidden($current->getSku(), $product->getSku());
        }

        $this->operations[] = 'update';
        $this->products[$id] = $product;

        return true;
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (!isset($this->products[$id])) {
            return false;
        }

        $this->operations[] = 'status:' . ($isActive ? 'on' : 'off');
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

        return array_values(array_filter($rows, static function (VendingProduct $product) use ($term, $category, $isActive): bool {
            if ($term !== ''
                && !str_contains(mb_strtolower($product->getSku(), 'UTF-8'), $term)
                && !str_contains(mb_strtolower($product->getName(), 'UTF-8'), $term)) {
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
    }

    public function countActiveSlots(int $productId): int
    {
        return $this->slotCounts[$productId] ?? 0;
    }

    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        $rows = [];
        foreach ($this->assignments[$productId] ?? [] as $index => $machineType) {
            $rows[] = [
                'machine_id' => $index + 1,
                'machine_code' => 'MACH-' . ($index + 1),
                'machine_model' => 'Modelo de prueba',
                'machine_type' => $machineType,
                'slot_code' => (string)(($index + 1) * 11),
                'is_active' => true,
            ];
        }

        return $rows;
    }
}

/** Registrador de auditoría en memoria: captura los eventos sin persistirlos. */
final class RecordingAuditLogger extends AuditLogger
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

$repo = new InMemoryVendingProductRepository();
$audit = new RecordingAuditLogger();
$service = new VendingProductService($repo, $audit);

// Catálogo base: snack activo con dos carriles, refresco activo, ingrediente
// inactivo y perecedero refrigerado activo.
$snack = $repo->seed(new VendingProduct(
    null,
    'TEST-SNACK-40',
    'Patatas de prueba 40 g',
    ProductCategory::SNACK,
    ProductUnitType::UNIT,
    0.55,
    1.50,
    false,
    null,
    true,
    '2026-11-12T09:00:00+01:00',
    '2026-11-12T09:00:00+01:00'
));
$repo->seed(new VendingProduct(
    null,
    'TEST-COLA-33',
    'Cola de prueba 33 cl',
    ProductCategory::COLD_DRINK,
    ProductUnitType::UNIT,
    0.45,
    1.20,
    false,
    null,
    true
));
$repo->seed(new VendingProduct(
    null,
    'TEST-CAFE-1K',
    'Café de prueba 1 kg',
    ProductCategory::HOT_BEVERAGE_INGREDIENT,
    ProductUnitType::GRAMS,
    7.25,
    0.00,
    false,
    null,
    false
));
$repo->seed(new VendingProduct(
    null,
    'TEST-SAND-01',
    'Sándwich de prueba',
    ProductCategory::PERISHABLE_FOOD,
    ProductUnitType::UNIT,
    1.15,
    2.40,
    true,
    5,
    true
));
$repo->assignments[(int)$snack->getId()] = [MachineType::SNACKS];
$repo->slotCounts[(int)$snack->getId()] = 2;

$actor = ['id' => 44, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Pruebas'];

// =====================================================================
// 1. Listado, KPIs y filtros
// =====================================================================
echo "--- 1. Catálogo, KPIs y filtros ---\n";

$catalog = $service->listProducts();
$assert(
    '1.1 El listado devuelve cuatro referencias y sus KPIs globales',
    count($catalog['products']) === 4
    && $catalog['kpis'] === [
        'total_products' => 4,
        'active_products' => 3,
        'inactive_products' => 1,
        'cold_chain_products' => 1,
        'consumable_and_ingredient_products' => 1,
    ],
    json_encode($catalog['kpis'])
);

$snackRows = array_values(array_filter(
    $catalog['products'],
    static fn (array $row): bool => $row['sku'] === 'TEST-SNACK-40'
));
$assert(
    '1.2 Cada fila incluye active_slots_count y el margen del catálogo',
    $snackRows !== []
    && $snackRows[0]['active_slots_count'] === 2
    && abs($snackRows[0]['unit_margin_eur'] - 0.95) < 0.001
);

$byCategory = $service->listProducts(['category' => 'snack']);
$assert(
    '1.3 El filtro de categoría devuelve sólo snacks',
    count($byCategory['products']) === 1 && $byCategory['products'][0]['sku'] === 'TEST-SNACK-40'
);

$inactive = $service->listProducts(['status' => 'INACTIVE']);
$assert(
    '1.4 El filtro INACTIVE devuelve sólo la referencia dada de baja',
    count($inactive['products']) === 1 && $inactive['products'][0]['sku'] === 'TEST-CAFE-1K'
);

$cold = $service->listProducts(['requires_cold_chain' => true]);
$assert(
    '1.5 El filtro de cadena de frío devuelve sólo el perecedero',
    count($cold['products']) === 1 && $cold['products'][0]['sku'] === 'TEST-SAND-01'
);

$search = $service->listProducts(['search' => 'SAND']);
$assert(
    '1.6 La búsqueda libre encuentra por nombre o SKU',
    count($search['products']) === 1 && $search['products'][0]['sku'] === 'TEST-SAND-01'
);

$invalidFilterCaught = false;
try {
    $service->listProducts(['status' => 'BROKEN']);
} catch (InvalidArgumentException) {
    $invalidFilterCaught = true;
}
$assert('1.7 Un estado de filtro desconocido se rechaza', $invalidFilterCaught);

// =====================================================================
// 2. Alta y validaciones (C2, QA 1, D3)
// =====================================================================
echo "\n--- 2. Alta y validaciones del contrato ---\n";

$created = $service->createProduct([
    'sku' => ' new-cola-50 ',
    'name' => 'Cola nueva de prueba 50 cl',
    'category' => 'COLD_DRINK',
    'unit_type' => 'UNIT',
    'unit_cost_eur' => 0.50,
    'sale_price_eur' => 1.30,
], $actor);

$assert(
    '2.1 El alta normaliza el SKU y aplica los valores por defecto',
    $created->getSku() === 'NEW-COLA-50'
    && $created->isActive() === true
    && $created->requiresColdChain() === false
    && $created->getMaxShelfLifeDays() === null
);

$createEvent = $audit->events[0] ?? null;
$assert(
    '2.2 El alta audita VENDING_PRODUCT_CREATED con actor y estado nuevo',
    $createEvent !== null
    && $createEvent->getEntityType() === 'VENDING_PRODUCT'
    && $createEvent->getAction() === 'VENDING_PRODUCT_CREATED'
    && $createEvent->getEntityId() === (int)$created->getId()
    && $createEvent->getUserRole() === 'COORDINATOR'
    && $createEvent->getUserName() === 'Coordinación de Pruebas'
    && $createEvent->getPreviousState() === null
    && $createEvent->getNewState()['sku'] === 'NEW-COLA-50'
);

$duplicateCaught = null;
try {
    $service->createProduct([
        'sku' => 'NEW-COLA-50',
        'name' => 'Duplicado de prueba',
        'category' => 'COLD_DRINK',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 0.50,
        'sale_price_eur' => 1.30,
    ]);
} catch (DuplicateProductSkuException $exception) {
    $duplicateCaught = $exception;
}
$assert(
    '2.3 El SKU duplicado se rechaza con 409 DUPLICATE_PRODUCT_SKU',
    $duplicateCaught !== null
    && $duplicateCaught->getErrorCode() === 'DUPLICATE_PRODUCT_SKU'
    && $duplicateCaught->getHttpStatusCode() === 409
);

$validationCases = [
    ['sku' => 'X', 'name' => 'Producto de prueba', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'unit_cost_eur' => 1.0, 'sale_price_eur' => 1.0, 'expected' => 'INVALID_PRODUCT_SKU'],
    ['sku' => 'TEST-BAD-NAME', 'name' => 'X', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'unit_cost_eur' => 1.0, 'sale_price_eur' => 1.0, 'expected' => 'INVALID_PRODUCT_NAME'],
    ['sku' => 'TEST-BAD-COST', 'name' => 'Producto de prueba', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'unit_cost_eur' => 0.0, 'sale_price_eur' => 1.0, 'expected' => 'INVALID_UNIT_COST'],
    ['sku' => 'TEST-BAD-PRICE', 'name' => 'Producto de prueba', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'unit_cost_eur' => 1.0, 'sale_price_eur' => 0.0, 'expected' => 'INVALID_SALE_PRICE'],
    ['sku' => 'TEST-BAD-UNIT', 'name' => 'Producto de prueba', 'category' => 'COLD_DRINK', 'unit_type' => 'GRAMS', 'unit_cost_eur' => 1.0, 'sale_price_eur' => 1.0, 'expected' => 'INVALID_UNIT_TYPE'],
    ['sku' => 'TEST-BAD-COLD', 'name' => 'Producto de prueba', 'category' => 'PERISHABLE_FOOD', 'unit_type' => 'UNIT', 'unit_cost_eur' => 1.0, 'sale_price_eur' => 1.0, 'expected' => 'PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE'],
];

foreach ($validationCases as $case) {
    $expected = $case['expected'];
    unset($case['expected']);
    $code = null;
    try {
        $service->createProduct($case);
    } catch (PlanogramValidationException $exception) {
        $code = $exception->getErrorCode();
    }
    $assert("2.4 El alta inválida devuelve {$expected}", $code === $expected, (string)$code);
}

$invalidShelfCode = null;
try {
    $service->createProduct([
        'sku' => 'TEST-BAD-SHELF',
        'name' => 'Producto de prueba',
        'category' => 'PERISHABLE_FOOD',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 1.0,
        'sale_price_eur' => 1.0,
        'requires_cold_chain' => true,
        'max_shelf_life_days' => 0,
    ]);
} catch (PlanogramValidationException $exception) {
    $invalidShelfCode = $exception->getErrorCode();
}
$assert('2.5 Una vida útil fuera de 1..3650 devuelve INVALID_SHELF_LIFE', $invalidShelfCode === 'INVALID_SHELF_LIFE');

$invalidCategoryCaught = false;
try {
    $service->createProduct([
        'sku' => 'TEST-BAD-CAT',
        'name' => 'Producto de prueba',
        'category' => 'NOT_A_CATEGORY',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 1.0,
        'sale_price_eur' => 1.0,
    ]);
} catch (InvalidArgumentException) {
    $invalidCategoryCaught = true;
}
$assert('2.6 Una categoría desconocida se rechaza como payload inválido', $invalidCategoryCaught);

// =====================================================================
// 3. Edición con SKU inmutable (C1)
// =====================================================================
echo "\n--- 3. Edición e inmutabilidad del SKU ---\n";

$auditBeforeUpdate = count($audit->events);
$updated = $service->updateProduct((int)$snack->getId(), [
    'sku' => 'TEST-SNACK-40',
    'name' => 'Patatas de prueba 40 g (nueva receta)',
    'category' => 'SNACK',
    'unit_type' => 'UNIT',
    'unit_cost_eur' => 0.60,
    'sale_price_eur' => 1.65,
], $actor);

$assert(
    '3.1 La edición persiste nombre, coste y PVP sin mover el SKU',
    $updated->getName() === 'Patatas de prueba 40 g (nueva receta)'
    && abs($updated->getUnitCostEur() - 0.60) < 0.001
    && abs($updated->getSalePriceEur() - 1.65) < 0.001
    && $updated->getSku() === 'TEST-SNACK-40'
);

$updateEvent = $audit->events[$auditBeforeUpdate] ?? null;
$assert(
    '3.2 La edición audita VENDING_PRODUCT_UPDATED con antes y después',
    $updateEvent !== null
    && $updateEvent->getAction() === 'VENDING_PRODUCT_UPDATED'
    && $updateEvent->getPreviousState()['name'] === 'Patatas de prueba 40 g'
    && $updateEvent->getNewState()['name'] === 'Patatas de prueba 40 g (nueva receta)'
);

$operationsBeforeSkuAttempt = count($repo->operations);
$skuCode = null;
try {
    $service->updateProduct((int)$snack->getId(), ['sku' => 'TEST-SNACK-99'], $actor);
} catch (PlanogramValidationException $exception) {
    $skuCode = $exception->getErrorCode();
}
$assert(
    '3.3 Cambiar el SKU devuelve 409 SKU_CHANGE_FORBIDDEN sin persistir',
    $skuCode === 'SKU_CHANGE_FORBIDDEN'
    && $repo->findById((int)$snack->getId())->getSku() === 'TEST-SNACK-40'
    && count($audit->events) === $auditBeforeUpdate + 1
    && count($repo->operations) === $operationsBeforeSkuAttempt
);

$notFoundCode = null;
try {
    $service->updateProduct(999, ['name' => 'No existe']);
} catch (ProductNotFoundException $exception) {
    $notFoundCode = $exception->getErrorCode();
}
$assert('3.4 Editar una referencia inexistente devuelve 404 PRODUCT_NOT_FOUND', $notFoundCode === 'PRODUCT_NOT_FOUND');

// =====================================================================
// 4. Cerrojo inverso contra máquinas asignadas (D3, RF-03.2)
// =====================================================================
echo "\n--- 4. Cerrojo inverso de la edición ---\n";

$coldLockCode = null;
try {
    $service->updateProduct((int)$snack->getId(), [
        'category' => 'SNACK',
        'unit_type' => 'UNIT',
        'requires_cold_chain' => true,
        'max_shelf_life_days' => 10,
    ], $actor);
} catch (PlanogramValidationException $exception) {
    $coldLockCode = $exception->getErrorCode();
}
$assert(
    '4.1 Activar frío en un snack asignado a SNACKS devuelve 409 COLD_CHAIN_MACHINE_INCOMPATIBLE',
    $coldLockCode === 'COLD_CHAIN_MACHINE_INCOMPATIBLE'
    && $repo->findById((int)$snack->getId())->requiresColdChain() === false
);

$categoryLockCode = null;
try {
    $service->updateProduct((int)$snack->getId(), [
        'category' => 'HOT_BEVERAGE_INGREDIENT',
        'unit_type' => 'GRAMS',
        'sale_price_eur' => 0.00,
    ], $actor);
} catch (PlanogramValidationException $exception) {
    $categoryLockCode = $exception->getErrorCode();
}
$assert(
    '4.2 Cambiar a ingrediente caliente en una máquina SNACKS devuelve 409 PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE',
    $categoryLockCode === 'PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE'
    && $repo->findById((int)$snack->getId())->getCategory() === ProductCategory::SNACK
);

$compatibleChange = $service->updateProduct((int)$snack->getId(), [
    'category' => 'COLD_DRINK',
    'unit_type' => 'UNIT',
    'sale_price_eur' => 1.70,
], $actor);
$assert(
    '4.3 Un cambio compatible con la matriz SNACKS se persiste',
    $compatibleChange->getCategory() === ProductCategory::COLD_DRINK
    && $compatibleChange->getSku() === 'TEST-SNACK-40'
);

// =====================================================================
// 5. Baja lógica, reactivación y auditoría (RF-01.3, Art. III.1)
// =====================================================================
echo "\n--- 5. Baja lógica y reactivación ---\n";

$auditBeforeStatus = count($audit->events);
$deactivated = $service->changeStatus((int)$snack->getId(), false, $actor);
$deactivateEvent = $audit->events[$auditBeforeStatus] ?? null;

$assert(
    '5.1 La baja lógica devuelve los carriles afectados y deja la referencia inactiva',
    $deactivated['affected_active_slots_count'] === 2
    && $deactivated['product']->isActive() === false
    && $repo->findById((int)$snack->getId())->isActive() === false
);

$assert(
    '5.2 La baja audita VENDING_PRODUCT_DEACTIVATED con el contador',
    $deactivateEvent !== null
    && $deactivateEvent->getAction() === 'VENDING_PRODUCT_DEACTIVATED'
    && $deactivateEvent->getMetadata()['affected_active_slots_count'] === 2
);

$reactivated = $service->changeStatus((int)$snack->getId(), true, $actor);
$assert(
    '5.3 La reactivación devuelve el contador y audita VENDING_PRODUCT_ACTIVATED',
    $reactivated['affected_active_slots_count'] === 2
    && $reactivated['product']->isActive() === true
    && $audit->events[count($audit->events) - 1]->getAction() === 'VENDING_PRODUCT_ACTIVATED'
);

$auditBeforeIdempotent = count($audit->events);
$idempotent = $service->changeStatus((int)$snack->getId(), true, $actor);
$assert(
    '5.4 Repetir el estado vigente no escribe ni duplica auditoría',
    $idempotent['product']->isActive() === true
    && count($audit->events) === $auditBeforeIdempotent
);

$statusNotFound = null;
try {
    $service->changeStatus(999, false);
} catch (ProductNotFoundException $exception) {
    $statusNotFound = $exception->getErrorCode();
}
$assert('5.5 Cambiar el estado de una referencia inexistente devuelve 404', $statusNotFound === 'PRODUCT_NOT_FOUND');

// =====================================================================
// 6. Lectura individual
// =====================================================================
echo "\n--- 6. Lectura individual ---\n";

$assert(
    '6.1 getProduct devuelve la referencia buscada',
    $service->getProduct((int)$snack->getId())->getSku() === 'TEST-SNACK-40'
);

$getNotFound = null;
try {
    $service->getProduct(999);
} catch (ProductNotFoundException $exception) {
    $getNotFound = $exception->getErrorCode();
}
$assert('6.2 getProduct lanza 404 PRODUCT_NOT_FOUND si no existe', $getNotFound === 'PRODUCT_NOT_FOUND');

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-07 (servicio del catálogo) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
