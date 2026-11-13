<?php

declare(strict_types=1);

/**
 * PdoProductLoadingOrderRepositoryTest · T-PLD-06
 *
 * Suite de integración de las órdenes de carga y sus líneas contra PostgreSQL
 * real (Módulo 33, Tablas 45 y 46, RF-02.1–RF-02.3, RF-03.1, RF-04.1, RF-04.2,
 * D1–D4, C1, QA 1, Art. III.1, Art. V.4).
 *
 * Certifica:
 *   1. Alta transaccional de cabecera y líneas, unicidad de orden activa (D3) y
 *      orden canónico de la Hoja A4: frío primero, motivo y SKU (D4).
 *   2. Edición con `UPSERT` y baja lógica (`is_active = FALSE`) sin borrado
 *      físico ni duplicación (C1, Art. III.1).
 *   3. Persistencia del despacho y de las transiciones de la cabecera.
 *   4. Lecturas bajo `SELECT ... FOR UPDATE` (D3).
 *   5. Propuesta: huecos de alertas y completos, exclusión de máquinas en
 *      cuarentena o sin acceso (`QA 1`) y contador de omitidas.
 *   6. Opacidad estructural de `unit_cost_eur` (Art. V.4).
 *   7. Prohibición de borrado físico en cabecera y líneas.
 *
 * La suite crea productos, máquinas y órdenes `TEST-PLD-T6-*` / `PLO-2026-96*`
 * y los retira en el `finally` con la ventana de purga del arnés.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PdoProductLoadingOrderRepositoryTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Enum\ProductLoadingDemandMode;
use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

echo "======================================================================\n";
echo " VendGuard · Integración · PdoProductLoadingOrderRepositoryTest (T-PLD-06)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$repo = new PdoProductLoadingOrderRepository($pdo);
$productRepo = new PdoVendingProductRepository($pdo);

$restockers = $pdo->query("
    SELECT u.id, u.name, u.operator_code
    FROM users u
    WHERE u.role = 'RESTOCKER'
      AND u.is_active = TRUE
      AND u.deleted_at IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM product_loading_orders o
           WHERE o.restocker_id = u.id
             AND o.status IN ('PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER')
      )
      AND NOT EXISTS (
          SELECT 1 FROM technician_absences a
           WHERE a.technician_id = u.id
             AND a.cancelled_at IS NULL
             AND a.starts_at < CURRENT_DATE + 1
             AND a.ends_at >= CURRENT_DATE
      )
    ORDER BY u.id ASC
    LIMIT 1
")->fetchAll(PDO::FETCH_ASSOC);

if (count($restockers) < 1) {
    fwrite(STDERR, "La suite exige un reponedor activo, sin orden abierta ni ausencia, en el banco de certificación.\n");
    exit(2);
}

$restockerA = (int)$restockers[0]['id'];

$coordinatorId = (int)$pdo->query("
    SELECT id FROM users
    WHERE role = 'COORDINATOR' AND is_active = TRUE AND deleted_at IS NULL
    ORDER BY id ASC LIMIT 1
")->fetchColumn();

if ($coordinatorId <= 0) {
    fwrite(STDERR, "La suite exige un coordinador activo en el banco de certificación.\n");
    exit(2);
}

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format(DateTimeImmutable::ATOM);
$tomorrow = $today->modify('+1 day')->format('Y-m-d');

$cleanup = static function (PDO $pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec("
            DELETE FROM product_loading_order_items
            WHERE order_id IN (SELECT id FROM product_loading_orders WHERE order_code LIKE 'PLO-2026-96%')
               OR product_id IN (SELECT id FROM vending_products WHERE sku LIKE 'TEST-PLD-T6%')
        ");
        $pdo->exec("DELETE FROM product_loading_orders WHERE order_code LIKE 'PLO-2026-96%'");
        $pdo->exec("
            DELETE FROM machine_planogram_slots
            WHERE machine_id IN (SELECT id FROM machines WHERE code LIKE 'TEST-PLD-T6%')
        ");
        $pdo->exec("DELETE FROM machines WHERE code LIKE 'TEST-PLD-T6%'");
        $pdo->exec("DELETE FROM vending_products WHERE sku LIKE 'TEST-PLD-T6%'");
        $pdo->exec("DELETE FROM users WHERE email = 'test-pld-t6-restocker@vendguard.test'");
    });
};

$cleanup($pdo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

try {
    // =================================================================
    // 1. Referencias de certificación
    // =================================================================
    echo "--- 1. Referencias de certificación ---\n";

    $coldProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T6-COLD',
        'Producto T6 refrigerado',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.50,
        3.00,
        true,
        5,
        true
    ));
    $ambientProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T6-AMB',
        'Producto T6 ambiente',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.80,
        1.50,
        false,
        null,
        true
    ));
    $quarantineProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T6-Q',
        'Producto T6 en cuarentena',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.20,
        2.50,
        true,
        4,
        true
    ));

    $coldProductId = (int)$coldProduct->getId();
    $ambientProductId = (int)$ambientProduct->getId();
    $quarantineProductId = (int)$quarantineProduct->getId();

    $assert(
        '1.1 Las tres referencias de certificación se persisten',
        $coldProductId > 0 && $ambientProductId > 0 && $quarantineProductId > 0
    );

    // =================================================================
    // 2. Alta de orden, unicidad activa y orden A4 (D3, D4)
    // =================================================================
    echo "\n--- 2. Alta transaccional y orden canónico ---\n";

    $created = $repo->createOrder(
        new ProductLoadingOrder(
            null,
            'PLO-2026-9601',
            $restockerA,
            $coordinatorId,
            ProductLoadingOrderStatus::PENDING_PICKING,
            ProductLoadingDemandMode::ALL_GAPS,
            10,
            null,
            'Orden de certificación T6'
        ),
        [
            ['product_id' => $ambientProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 5],
            ['product_id' => $coldProductId, 'reason' => 'ROUTE_GAP_FILL', 'requested_qty' => 3],
        ]
    );

    $orderId = (int)$created['order']->getId();
    $assert(
        '2.1 La cabecera y las dos líneas se guardan en una sola transacción',
        $orderId > 0 && count($created['items']) === 2,
        "order={$orderId} items=" . count($created['items'])
    );
    $assert(
        '2.2 La Hoja A4 pone el frío primero aunque su motivo sea menos urgente (D4)',
        $created['items'][0]['product_id'] === $coldProductId
        && $created['items'][0]['requires_cold_chain'] === true
        && $created['items'][0]['reason'] === ProductLoadingItemReason::ROUTE_GAP_FILL->value
        && $created['items'][1]['product_id'] === $ambientProductId,
        json_encode(array_column($created['items'], 'product_id'))
    );
    $assert(
        '2.3 Ninguna línea expone coste unitario (Art. V.4)',
        array_reduce(
            $created['items'],
            static fn (bool $carry, array $item): bool => $carry
                && !array_key_exists('unit_cost_eur', $item)
                && !array_key_exists('sale_price_eur', $item),
            true
        )
    );

    $active = $repo->findActiveByRestocker($restockerA);
    $assert(
        '2.4 La orden recién creada ocupa el hueco activo del reponedor',
        $active !== null && $active->getId() === $orderId
        && $active->getOrderCode() === 'PLO-2026-9601'
    );

    $duplicateRejected = false;
    $duplicateMessage = '';
    try {
        $repo->createOrder(
            new ProductLoadingOrder(
                null,
                'PLO-2026-9602',
                $restockerA,
                $coordinatorId,
                ProductLoadingOrderStatus::PENDING_PICKING,
                ProductLoadingDemandMode::ALERTS_ONLY,
                10
            ),
            [['product_id' => $ambientProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 1]]
        );
    } catch (InvalidArgumentException $exception) {
        $duplicateRejected = str_contains($exception->getMessage(), 'ACTIVE_LOADING_ORDER_EXISTS');
        $duplicateMessage = $exception->getMessage();
    }
    $assert(
        '2.5 Una segunda orden activa del mismo reponedor se rechaza (D3)',
        $duplicateRejected,
        $duplicateMessage
    );

    $orphan = (int)$pdo->query("SELECT COUNT(*) FROM product_loading_orders WHERE order_code = 'PLO-2026-9602'")->fetchColumn();
    $assert('2.6 El rechazo no deja una cabecera huérfana', $orphan === 0, (string)$orphan);

    // =================================================================
    // 3. Edición con UPSERT y baja lógica (C1, Art. III.1)
    // =================================================================
    echo "\n--- 3. Edición con baja lógica ---\n";

    $edited = $repo->replaceItems($orderId, [
        ['product_id' => $coldProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 4],
    ]);

    $activeAfterEdit = array_values(array_filter($edited, static fn (array $item): bool => $item['is_active'] === true));
    $inactive = array_values(array_filter($edited, static fn (array $item): bool => $item['is_active'] === false));

    $assert(
        '3.1 Retirar una línea la deja inactiva, no la borra (C1)',
        count($activeAfterEdit) === 1
        && $activeAfterEdit[0]['product_id'] === $coldProductId
        && count($inactive) === 1
        && $inactive[0]['product_id'] === $ambientProductId
    );

    $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM product_loading_order_items WHERE order_id = {$orderId}")->fetchColumn();
    $assert('3.2 El número de filas no disminuye al retirar una línea', $rowCount === 2, (string)$rowCount);

    $readded = $repo->replaceItems($orderId, [
        ['product_id' => $coldProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 4],
        ['product_id' => $ambientProductId, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 6],
    ]);

    $reactivated = array_values(array_filter(
        $readded,
        static fn (array $item): bool => $item['product_id'] === $ambientProductId
    ));
    $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM product_loading_order_items WHERE order_id = {$orderId}")->fetchColumn();

    $assert(
        '3.3 Reañadir un producto reactiva la misma fila sin duplicarla',
        count($reactivated) === 1
        && $reactivated[0]['is_active'] === true
        && $reactivated[0]['requested_qty'] === 6
        && $rowCount === 2,
        json_encode($reactivated)
    );

    // =================================================================
    // 4. Despacho y transiciones persistidas
    // =================================================================
    echo "\n--- 4. Despacho y ciclo de vida ---\n";

    $coldItem = $repo->findItem($orderId, $coldProductId);
    $ambientItem = $repo->findItem($orderId, $ambientProductId);
    if ($coldItem === null || $ambientItem === null) {
        throw new RuntimeException('No se pudieron releer las líneas de la orden de certificación.');
    }

    $repo->updateItem($coldItem->withDispatch(4, 'LOTE-T6-A', new DateTimeImmutable($tomorrow)));
    $repo->updateItem($ambientItem->withDispatch(0, null, null));

    $dispatchedCold = $repo->findItem($orderId, $coldProductId);
    $assert(
        '4.1 El despacho persiste lote, caducidad y cantidad',
        $dispatchedCold !== null
        && $dispatchedCold->getDispatchedQty() === 4
        && $dispatchedCold->getBatchCode() === 'LOTE-T6-A'
        && $dispatchedCold->getExpirationDate()?->format('Y-m-d') === $tomorrow
        && $dispatchedCold->isDispatched()
    );

    $dispatchedOrder = $repo->updateOrder(ProductLoadingOrder::fromDatabaseRow(array_merge(
        $created['order']->toArray(),
        [
            'status' => 'DISPATCHED',
            'cold_chamber_exit_temp_celsius' => 3.0,
            'dispatched_by' => $coordinatorId,
            'dispatched_at' => $now,
        ]
    )));

    $assert(
        '4.2 La cabecera pasa a DISPATCHED con la temperatura de cámara',
        $dispatchedOrder->getStatus() === ProductLoadingOrderStatus::DISPATCHED
        && $dispatchedOrder->getColdChamberExitTempCelsius() === 3.0
        && $dispatchedOrder->getDispatchedBy() === $coordinatorId
    );

    $confirmedOrder = $repo->updateOrder(ProductLoadingOrder::fromDatabaseRow(array_merge(
        $dispatchedOrder->toArray(),
        ['status' => 'CONFIRMED_BY_RESTOCKER', 'confirmed_at' => $now]
    )));

    $assert(
        '4.3 La recepción en 1 toque se persiste como CONFIRMED_BY_RESTOCKER',
        $confirmedOrder->getStatus() === ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER
        && $confirmedOrder->getConfirmedAt() !== null
    );

    $locked = null;
    $pdo->beginTransaction();
    try {
        $locked = $repo->findById($orderId, true);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $assert(
        '4.4 findById(forUpdate) bloquea cabecera y líneas dentro de transacción (D3)',
        $locked !== null
        && $locked['order']->getId() === $orderId
        && count($locked['items']) === 2
    );

    // =================================================================
    // 5. Listado de Coordinación y KPIs
    // =================================================================
    echo "\n--- 5. Listado, filtros y KPIs ---\n";

    $confirmedRows = $repo->findForCoordinator('CONFIRMED_BY_RESTOCKER', $restockerA);
    $assert(
        '5.1 El filtro por estado y reponedor devuelve la orden confirmada',
        count($confirmedRows) === 1
        && $confirmedRows[0]['id'] === $orderId
        && $confirmedRows[0]['restocker_name'] === (string)$restockers[0]['name']
        && $confirmedRows[0]['restocker_currently_absent'] === false
        && $confirmedRows[0]['active_items_count'] === 2,
        json_encode($confirmedRows[0] ?? [])
    );

    $assert(
        '5.2 El listado no expone coste unitario (Art. V.4)',
        !array_key_exists('unit_cost_eur', $confirmedRows[0])
        && !array_key_exists('sale_price_eur', $confirmedRows[0])
    );

    $invalidStatusRejected = false;
    try {
        $repo->findForCoordinator('NOT_A_STATUS');
    } catch (InvalidArgumentException) {
        $invalidStatusRejected = true;
    }
    $assert('5.3 Un estado de filtro desconocido se rechaza en voz alta', $invalidStatusRejected);

    $userInsert = $pdo->prepare("
        INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
        VALUES ('Reponedor T6', 'test-pld-t6-restocker@vendguard.test', 'test-pld-t6-restocker@vendguard.test',
                'test-only', 'RESTOCKER', 'T6-99', TRUE, FALSE)
        RETURNING id
    ");
    $userInsert->execute();
    $testRestockerId = (int)$userInsert->fetchColumn();

    $secondCreated = $repo->createOrder(
        new ProductLoadingOrder(
            null,
            'PLO-2026-9611',
            $testRestockerId,
            $coordinatorId,
            ProductLoadingOrderStatus::PENDING_PICKING,
            ProductLoadingDemandMode::ALERTS_ONLY,
            10
        ),
        [['product_id' => $ambientProductId, 'reason' => 'LOW_STOCK_ALERT', 'requested_qty' => 2]]
    );

    $assert(
        '5.4 Un segundo reponedor recibe su propia orden activa',
        $repo->findActiveByRestocker($testRestockerId)?->getId() === (int)$secondCreated['order']->getId()
    );

    $kpis = $repo->summarizeStatusCounts($today);
    $assert(
        '5.5 Los KPIs cuentan picking, despacho, ruta y liquidaciones de hoy',
        $kpis['pending_picking_count'] >= 1
        && $kpis['confirmed_in_route_count'] >= 1
        && $kpis['dispatched_count'] >= 0
        && $kpis['reconciled_today_count'] >= 0
        && array_keys($kpis) === [
            'pending_picking_count',
            'dispatched_count',
            'confirmed_in_route_count',
            'reconciled_today_count',
        ],
        json_encode($kpis)
    );

    // =================================================================
    // 6. Propuesta: huecos, alertas y exclusión QA 1
    // =================================================================
    echo "\n--- 6. Insumos de propuesta y exclusión QA 1 ---\n";

    $zone = $pdo->query("
        SELECT oz.id, oz.code, oz.name
        FROM operational_zones oz
        INNER JOIN restocker_zone_assignments a
            ON a.zone_id = oz.id
           AND a.restocker_id = {$restockerA}
           AND a.removed_at IS NULL
        WHERE oz.is_active = TRUE
        ORDER BY a.is_primary DESC, oz.id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($zone === false) {
        throw new RuntimeException('El reponedor de certificación no tiene una zona viva.');
    }

    $locationId = (int)$pdo->query("
        SELECT id FROM locations WHERE zone_id = " . (int)$zone['id'] . " ORDER BY id ASC LIMIT 1
    ")->fetchColumn();
    $machineType = (string)$pdo->query('SELECT machine_type FROM machines ORDER BY id ASC LIMIT 1')->fetchColumn();

    if ($locationId <= 0 || $machineType === '') {
        throw new RuntimeException('No hay sede o tipología de máquina para montar el escenario de propuesta.');
    }

    $baseline = $repo->fetchProposalInputs($restockerA, $today);
    $baselineExcluded = (int)$baseline['excluded_unavailable_machines_count'];

    $insertMachine = static function (
        PDO $pdo,
        int $locationId,
        string $code,
        string $machineType,
        string $sanitaryStatus,
        bool $blocked
    ): int {
        $stmt = $pdo->prepare("
            INSERT INTO machines (
                location_id, code, model, machine_type, floor_wing,
                is_active, sanitary_status, is_blocked_no_access
            ) VALUES (
                :location_id, :code, 'T6', :machine_type::machine_type, 'TEST',
                TRUE, :sanitary_status::sanitary_status, :blocked
            )
            RETURNING id
        ");
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':machine_type', $machineType, PDO::PARAM_STR);
        $stmt->bindValue(':sanitary_status', $sanitaryStatus, PDO::PARAM_STR);
        $stmt->bindValue(':blocked', $blocked, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    };

    $okMachineId = $insertMachine($pdo, $locationId, 'TEST-PLD-T6-M-OK', $machineType, 'OK', false);
    $quarantineMachineId = $insertMachine($pdo, $locationId, 'TEST-PLD-T6-M-Q', $machineType, 'QUARANTINE', false);
    $blockedMachineId = $insertMachine($pdo, $locationId, 'TEST-PLD-T6-M-BLOCK', $machineType, 'OK', true);

    $insertSlot = static function (
        PDO $pdo,
        int $machineId,
        string $slotCode,
        int $productId,
        int $maxCapacity,
        int $currentQty,
        int $minThreshold
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO machine_planogram_slots (
                machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active
            ) VALUES (
                :machine_id, :slot_code, :product_id, :max_capacity, :current_qty, :min_threshold, TRUE
            )
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':slot_code', $slotCode, PDO::PARAM_STR);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':max_capacity', $maxCapacity, PDO::PARAM_INT);
        $stmt->bindValue(':current_qty', $currentQty, PDO::PARAM_INT);
        $stmt->bindValue(':min_threshold', $minThreshold, PDO::PARAM_INT);
        $stmt->execute();
    };

    $insertSlot($pdo, $okMachineId, 'A1', $coldProductId, 10, 5, 2);
    $insertSlot($pdo, $okMachineId, 'A2', $coldProductId, 8, 0, 2);
    $insertSlot($pdo, $quarantineMachineId, 'Q1', $quarantineProductId, 20, 0, 2);
    $insertSlot($pdo, $blockedMachineId, 'B1', $ambientProductId, 4, 0, 2);

    $proposal = $repo->fetchProposalInputs($restockerA, $today);
    $demandByProduct = [];
    foreach ($proposal['slot_demand'] as $row) {
        $demandByProduct[(int)$row['product_id']] = $row;
    }

    $assert(
        '6.1 La cuarentena y la máquina sin acceso se cuentan como omitidas (QA 1)',
        (int)$proposal['excluded_unavailable_machines_count'] === $baselineExcluded + 2,
        "baseline={$baselineExcluded} actual=" . $proposal['excluded_unavailable_machines_count']
    );
    $assert(
        '6.2 Los huecos de las máquinas no operativas no alimentan la demanda',
        !isset($demandByProduct[$quarantineProductId])
        && !isset($demandByProduct[$ambientProductId])
    );
    $assert(
        '6.3 Los dos carriles del producto refrigerado se agregan por producto',
        isset($demandByProduct[$coldProductId])
        && $demandByProduct[$coldProductId]['gap_qty_all'] === 13
        && $demandByProduct[$coldProductId]['gap_slots_count'] === 2,
        json_encode($demandByProduct[$coldProductId] ?? [])
    );
    $assert(
        '6.4 La variante de alertas solo suma el carril en STOCKOUT',
        $demandByProduct[$coldProductId]['gap_qty_alerts'] === 8
        && $demandByProduct[$coldProductId]['stockout_slots_count'] === 1
        && $demandByProduct[$coldProductId]['low_stock_slots_count'] === 0
        && $demandByProduct[$coldProductId]['alert_slots_count'] === 1,
        json_encode($demandByProduct[$coldProductId] ?? [])
    );
    $assert(
        '6.5 La demanda conserva frío, vida útil y etiqueta de categoría',
        $demandByProduct[$coldProductId]['requires_cold_chain'] === true
        && $demandByProduct[$coldProductId]['max_shelf_life_days'] === 5
        && $demandByProduct[$coldProductId]['product_category_label'] === 'Alimento Perecedero Fresco'
    );

    $zoneIds = array_column($proposal['zones'], 'zone_id');
    $assert(
        '6.6 La propuesta declara la zona cubierta hoy como titular',
        in_array((int)$zone['id'], $zoneIds, true)
        && $proposal['zones'][array_search((int)$zone['id'], $zoneIds, true)]['coverage_role'] === 'PRIMARY',
        json_encode($proposal['zones'])
    );
    $assert(
        '6.7 El contexto del reponedor no publica costes y sí su estado de ausencia',
        $proposal['restocker'] !== null
        && $proposal['restocker']['id'] === $restockerA
        && $proposal['restocker']['is_currently_absent'] === false
        && !array_key_exists('unit_cost_eur', $proposal['restocker'])
    );

    $absenceStart = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('-1 hour')
        ->format(DateTimeImmutable::ATOM);
    $absenceEnd = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('+1 day')
        ->format(DateTimeImmutable::ATOM);
    $absenceStmt = $pdo->prepare("
        INSERT INTO technician_absences (technician_id, reason, starts_at, ends_at, notes, created_by)
        VALUES (:technician_id, 'VACATION', :starts_at, :ends_at, 'Ausencia de la prueba C2', :created_by)
        RETURNING id
    ");
    $absenceStmt->execute([
        ':technician_id' => $restockerA,
        ':starts_at' => $absenceStart,
        ':ends_at' => $absenceEnd,
        ':created_by' => $coordinatorId,
    ]);
    $absenceId = (int)$absenceStmt->fetchColumn();

    $absentProposal = $repo->fetchProposalInputs($restockerA, $today);
    $assert(
        '6.8 La propuesta del ausente conserva zonas, demanda y alerta C2',
        $absentProposal['restocker'] !== null
        && $absentProposal['restocker']['is_currently_absent'] === true
        && count($absentProposal['zones']) === count($proposal['zones'])
        && count($absentProposal['slot_demand']) === count($proposal['slot_demand']),
        json_encode([
            'zones' => count($absentProposal['zones']),
            'demand' => count($absentProposal['slot_demand']),
        ])
    );

    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo, $absenceId): void {
        $pdo->exec("DELETE FROM technician_absences WHERE id = {$absenceId}");
    });

    // =================================================================
    // 7. Art. III.1 y limpieza
    // =================================================================
    echo "\n--- 7. Prohibición de borrado físico y limpieza ---\n";

    $orderRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $orderId): void {
        $pdo->exec("DELETE FROM product_loading_orders WHERE id = {$orderId}");
    });
    $itemRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $orderId): void {
        $pdo->exec("DELETE FROM product_loading_order_items WHERE order_id = {$orderId}");
    });

    $assert(
        '7.1 El motor rechaza el borrado físico de la cabecera (Art. III.1)',
        $orderRejection !== null
        && $orderRejection['state'] === '45000'
        && str_contains($orderRejection['message'], 'ART_III_1_VIOLATION')
    );
    $assert(
        '7.2 El motor rechaza el borrado físico de las líneas (Art. III.1)',
        $itemRejection !== null
        && $itemRejection['state'] === '45000'
        && str_contains($itemRejection['message'], 'ART_III_1_VIOLATION')
    );

    $cleanup($pdo);

    $remainingOrders = (int)$pdo->query("SELECT COUNT(*) FROM product_loading_orders WHERE order_code LIKE 'PLO-2026-96%'")->fetchColumn();
    $remainingProducts = (int)$pdo->query("SELECT COUNT(*) FROM vending_products WHERE sku LIKE 'TEST-PLD-T6%'")->fetchColumn();
    $remainingMachines = (int)$pdo->query("SELECT COUNT(*) FROM machines WHERE code LIKE 'TEST-PLD-T6%'")->fetchColumn();

    $assert(
        '7.3 La suite retira sus órdenes, referencias y máquinas transitorias',
        $remainingOrders === 0 && $remainingProducts === 0 && $remainingMachines === 0,
        "orders={$remainingOrders} products={$remainingProducts} machines={$remainingMachines}"
    );

    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. REPOSITORIO DE ÓRDENES DE CARGA CERTIFICADO.\n";
        echo "======================================================================\n";
        exit(0);
    }

    echo " RESULTADO: FALLOS DETECTADOS EN EL REPOSITORIO DE ÓRDENES DE CARGA.\n";
    echo "======================================================================\n";
    exit(1);
} catch (Throwable $exception) {
    $cleanup($pdo);
    fwrite(STDERR, "PdoProductLoadingOrderRepositoryTest failed: {$exception->getMessage()}\n");
    exit(1);
}
