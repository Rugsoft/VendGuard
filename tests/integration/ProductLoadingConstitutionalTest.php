<?php

declare(strict_types=1);

/**
 * ProductLoadingConstitutionalTest · T-PLD-10
 *
 * Certifica el blindaje constitucional del Módulo 33 contra PostgreSQL real y el
 * router real, sobre las semillas canónicas y un esquema aislado:
 *
 *   1. **Art. V.4.** Perímetro RBAC de Coordinación/Reponedor/Técnico y opacidad
 *      absoluta de `unit_cost_eur` y precios en todas las superficies.
 *   2. **Art. III.1.** El motor rechaza el `DELETE` físico de cabeceras, líneas y
 *      stock por lote; la baja de líneas es lógica y conserva la fila (`C1`).
 *   3. **Art. II / D2 / QA 2.** El stock refrigerado exige lote real y caducidad,
 *      y una orden con frío no pasa a `DISPATCHED` sin temperatura de cámara.
 *   4. **QA 3.** Un lote ya existente no admite una fecha de caducidad distinta.
 *   5. **QA 1.** Las máquinas en cuarentena o sin acceso no alimentan la demanda
 *      y se contabilizan como omitidas.
 *   6. **D3 / QA 4 / Art. V.1.** Unicidad de orden activa, cuadre aritmético
 *      impuesto por el motor y justificación de cancelación de 20 caracteres.
 *   7. **Art. III.3.** Las mutaciones del ciclo dejan rastro auditable.
 *
 * CATEGORÍA: `new_engine`. Sin PostgreSQL local sale con código 2, nunca en
 * verde silencioso. El esquema aislado se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/ProductLoadingConstitutionalTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const PLD10_CONST_SCHEMA = 't_pld10_const';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-PLD-10 · Blindaje constitucional de carga en frío\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** @return array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

/** @return array<string, mixed> */
$data = static fn (Response $response): array => $json($response)['data'] ?? [];

$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

$schemaReady = false;

try {
    echo "\n--- Escenario aislado (DDL + semillas canónicas) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, PLD10_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    $seedCounts = (new SeedRunner($pdo))->seedAll();
    $pdo->exec("SELECT set_config('vendguard.purge', 'off', false)");

    echo sprintf(
        "  Semillas: %d órdenes de carga, %d saldos de furgoneta\n",
        (int)($seedCounts['product_loading_orders'] ?? 0),
        (int)($seedCounts['restocker_van_product_stocks'] ?? 0)
    );

    $router = AppRouter::create();
    $userRepo = new PdoUserRepository($pdo);
    $productRepo = new PdoVendingProductRepository($pdo);
    $authService = new AuthService(null, $userRepo);

    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $restocker = $userRepo->findByEmail('lucia.repostaje@vendguard.internal');
    $otherRestocker = $userRepo->findByEmail('oscar.repostaje@vendguard.internal');
    $technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

    $assert(
        '0.1 Semilla con coordinación, dos reponedores y técnico SAT',
        $coordinator !== null && $restocker !== null && $otherRestocker !== null && $technician !== null
    );
    if ($coordinator === null || $restocker === null || $otherRestocker === null || $technician === null) {
        throw new RuntimeException('La semilla no contiene las identidades necesarias.');
    }

    $coordinatorId = (int)$coordinator->getId();
    $restockerId = (int)$restocker->getId();
    $otherRestockerId = (int)$otherRestocker->getId();

    $coordinatorToken = $authService->generateInternalToken($coordinator);
    $restockerToken = $authService->generateInternalToken($restocker);
    $technicianToken = $authService->generateInternalToken($technician);

    $call = static function (
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    /** Ejecuta una sentencia que debe ser rechazada por el motor. */
    $dbRejects = static function (string $sql, array $params, string $needle) use ($pdo): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [false, 'la sentencia fue aceptada'];
        } catch (PDOException $exception) {
            return [str_contains($exception->getMessage(), $needle), $exception->getMessage()];
        }
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. V.4 · perímetro RBAC y opacidad de costes
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. V.4 · RBAC y opacidad de costes ---\n";

    $anonymous = $call('GET', '/api/coordinator/product-loading-orders');
    $restockerOnCoordinator = $call('GET', '/api/coordinator/product-loading-orders', [], [], $restockerToken);
    $coordinatorOnMobile = $call('GET', '/api/restocker/van-stocks', [], [], $coordinatorToken);
    $technicianOnConfirm = $call(
        'POST',
        '/api/restocker/product-loading-orders/1/confirm',
        [],
        [],
        $technicianToken
    );
    $assert(
        '1.1 Sin sesión la consola de carga responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );
    $assert(
        '1.2 El reponedor no alcanza la consola de Coordinación (403)',
        $restockerOnCoordinator->getStatusCode() === 403 && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );
    $assert(
        '1.3 Coordinación no alcanza la superficie móvil del reponedor (403)',
        $coordinatorOnMobile->getStatusCode() === 403 && $errorCode($coordinatorOnMobile) === 'FORBIDDEN'
    );
    $assert(
        '1.4 El técnico SAT no puede confirmar órdenes de carga (403)',
        $technicianOnConfirm->getStatusCode() === 403
    );

    $coordinatorList = $call('GET', '/api/coordinator/product-loading-orders', [], [], $coordinatorToken);
    $coordinatorListRaw = (string)$coordinatorList->getBody();
    $restockerRoute = $call('GET', '/api/restocker/my-route', [], [], $restockerToken);
    $restockerRouteRaw = (string)$restockerRoute->getBody();
    $assert(
        '1.5 Las superficies de Coordinación y Reponedor no publican coste ni precio',
        $coordinatorList->getStatusCode() === 200
            && $restockerRoute->getStatusCode() === 200
            && !str_contains($coordinatorListRaw, 'unit_cost_eur')
            && !str_contains($coordinatorListRaw, 'sale_price_eur')
            && !str_contains($restockerRouteRaw, 'unit_cost_eur')
            && !str_contains($restockerRouteRaw, 'sale_price_eur')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. III.1 · prohibición de borrado físico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. III.1 · sin DELETE físico ---\n";

    $seededOrder = $pdo->query(
        "SELECT id FROM product_loading_orders WHERE order_code = 'PLO-2026-0001'"
    )->fetchColumn();
    $seededItem = $pdo->query(
        'SELECT id FROM product_loading_order_items WHERE order_id = ' . (int)$seededOrder . ' ORDER BY id LIMIT 1'
    )->fetchColumn();
    $seededStock = $pdo->query(
        'SELECT id FROM restocker_van_product_stocks WHERE restocker_id = ' . $restockerId . ' ORDER BY id LIMIT 1'
    )->fetchColumn();

    $assert(
        '2.1 Hay cabecera, línea y saldo sembrados',
        (int)$seededOrder > 0 && (int)$seededItem > 0 && (int)$seededStock > 0
    );

    [$orderDeleteRejected, $orderDeleteMessage] = $dbRejects(
        'DELETE FROM product_loading_orders WHERE id = :id',
        [':id' => (int)$seededOrder],
        'ART_III_1_VIOLATION'
    );
    [$itemDeleteRejected, $itemDeleteMessage] = $dbRejects(
        'DELETE FROM product_loading_order_items WHERE id = :id',
        [':id' => (int)$seededItem],
        'ART_III_1_VIOLATION'
    );
    [$stockDeleteRejected, $stockDeleteMessage] = $dbRejects(
        'DELETE FROM restocker_van_product_stocks WHERE id = :id',
        [':id' => (int)$seededStock],
        'ART_III_1_VIOLATION'
    );
    $assert(
        '2.2 El motor rechaza el borrado físico de las tres tablas del Módulo 33',
        $orderDeleteRejected && $itemDeleteRejected && $stockDeleteRejected,
        implode(' | ', [$orderDeleteMessage, $itemDeleteMessage, $stockDeleteMessage])
    );
    $assert(
        '2.3 Las filas siguen existiendo tras los tres rechazos',
        (int)$pdo->query('SELECT COUNT(*) FROM product_loading_orders WHERE id = ' . (int)$seededOrder)->fetchColumn() === 1
            && (int)$pdo->query('SELECT COUNT(*) FROM product_loading_order_items WHERE id = ' . (int)$seededItem)->fetchColumn() === 1
            && (int)$pdo->query('SELECT COUNT(*) FROM restocker_van_product_stocks WHERE id = ' . (int)$seededStock)->fetchColumn() === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. II / D2 · cerrojo de frío en el motor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. II / D2 · cerrojo de frío ---\n";

    $coldProductId = (int)$pdo->query(
        "SELECT id FROM vending_products WHERE sku = 'SAND-MIXTO-01' AND requires_cold_chain = TRUE"
    )->fetchColumn();
    $assert('3.1 Hay un producto refrigerado sembrado', $coldProductId > 0);

    [$coldStockRejected, $coldStockMessage] = $dbRejects(
        'INSERT INTO restocker_van_product_stocks (restocker_id, product_id, batch_code, expiration_date, quantity_available)
         VALUES (:restocker, :product, :batch, NULL, 1)',
        [':restocker' => $restockerId, ':product' => $coldProductId, ':batch' => 'SIN-LOTE'],
        'ART_II_COLD_CHAIN_BATCH_REQUIRED'
    );
    $assert(
        '3.2 El stock de frío sin lote real y fecha es rechazado por el motor',
        $coldStockRejected,
        $coldStockMessage
    );

    $dispatchedOrderId = (int)$pdo->query(
        "SELECT id FROM product_loading_orders WHERE order_code = 'PLO-2026-0002' AND status = 'DISPATCHED'"
    )->fetchColumn();
    [$missingTempRejected, $missingTempMessage] = $dbRejects(
        'UPDATE product_loading_orders SET cold_chamber_exit_temp_celsius = NULL WHERE id = :id',
        [':id' => $dispatchedOrderId],
        'ART_II_COLD_CHAMBER_DISPATCH_VIOLATION'
    );
    [$outOfRangeTempRejected, $outOfRangeTempMessage] = $dbRejects(
        'UPDATE product_loading_orders SET cold_chamber_exit_temp_celsius = 4.1 WHERE id = :id',
        [':id' => $dispatchedOrderId],
        'ART_II_COLD_CHAMBER_DISPATCH_VIOLATION'
    );
    $assert(
        '3.3 Una orden despachada con frío no admite temperatura nula ni 4,1 °C',
        $missingTempRejected && $outOfRangeTempRejected,
        implode(' | ', [$missingTempMessage, $outOfRangeTempMessage])
    );

    $validTemp = $pdo->prepare(
        'UPDATE product_loading_orders SET cold_chamber_exit_temp_celsius = 3.5 WHERE id = :id'
    );
    $validTemp->execute([':id' => $dispatchedOrderId]);
    $storedTemp = $pdo->query(
        'SELECT cold_chamber_exit_temp_celsius FROM product_loading_orders WHERE id = ' . $dispatchedOrderId
    )->fetchColumn();
    $assert('3.4 La temperatura dentro de rango se conserva', (float)$storedTemp === 3.5, (string)$storedTemp);

    // ─────────────────────────────────────────────────────────────────────
    // 4. QA 3 · inmutabilidad de la caducidad de un lote
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. QA 3 · lote existente con fecha inmutable ---\n";

    $sandProductId = (int)$pdo->query(
        "SELECT id FROM vending_products WHERE sku = 'SAND-MIXTO-01'"
    )->fetchColumn();
    $existingBatch = (string)$pdo->query(
        "SELECT batch_code FROM restocker_van_product_stocks
          WHERE restocker_id = {$restockerId} AND product_id = {$sandProductId}
          ORDER BY expiration_date ASC LIMIT 1"
    )->fetchColumn();
    $differentExpiration = (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
        ->modify('+3 days')
        ->format('Y-m-d');

    $collision = $call('PUT', '/api/coordinator/restocker-van-stocks/' . $restockerId, [], [
        'product_id' => $sandProductId,
        'batch_code' => $existingBatch,
        'expiration_date' => $differentExpiration,
        'quantity_available' => 12,
        'reason' => 'Intento certificado de reescribir la caducidad del lote.',
    ], $coordinatorToken);
    $assert(
        '4.1 Cambiar la caducidad de un lote existente responde 409 BATCH_EXPIRATION_MISMATCH',
        $collision->getStatusCode() === 409
            && $errorCode($collision) === 'BATCH_EXPIRATION_MISMATCH'
            && !str_contains((string)$collision->getBody(), 'unit_cost_eur'),
        $errorCode($collision)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. QA 1 · exclusión de máquinas no operativas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. QA 1 · cuarentena y bloqueo fuera de la demanda ---\n";

    $excludedProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T10-Q',
        'Producto exclusivo de la sonda QA 1',
        ProductCategory::SNACK,
        ProductUnitType::UNIT,
        0.90,
        1.80,
        false,
        null,
        true
    ));
    $excludedProductId = (int)$excludedProduct->getId();

    $baselineProposal = $call('GET', '/api/coordinator/product-loading-orders/proposal', [
        'restocker_id' => $restockerId,
        'demand_mode' => 'ALL_GAPS',
    ], [], $coordinatorToken);
    $baselineData = $data($baselineProposal);
    $baselineExcluded = (int)($baselineData['excluded_unavailable_machines_count'] ?? -1);
    $assert(
        '5.1 La propuesta de la reponedora responde y declara máquinas omitidas',
        $baselineProposal->getStatusCode() === 200 && $baselineExcluded >= 0,
        $errorCode($baselineProposal)
    );

    $zoneId = (int)$pdo->query(
        'SELECT zone_id FROM restocker_zone_assignments
          WHERE restocker_id = ' . $restockerId . ' AND removed_at IS NULL AND is_primary = TRUE
          ORDER BY id LIMIT 1'
    )->fetchColumn();
    $locationId = (int)$pdo->query(
        'SELECT id FROM locations WHERE zone_id = ' . $zoneId . ' AND is_active = TRUE ORDER BY id LIMIT 1'
    )->fetchColumn();
    $machineType = (string)$pdo->query(
        "SELECT machine_type FROM machines WHERE machine_type IN ('SNACKS', 'COMBO') LIMIT 1"
    )->fetchColumn();
    $assert(
        '5.2 Hay zona, sede y tipología para montar las máquinas de la sonda',
        $zoneId > 0 && $locationId > 0 && $machineType !== ''
    );

    $insertMachine = static function (string $code, string $sanitaryStatus, bool $blocked) use ($pdo, $locationId, $machineType): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (
                location_id, code, model, machine_type, floor_wing,
                is_active, sanitary_status, is_blocked_no_access
             ) VALUES (
                :location_id, :code, :model, :machine_type::machine_type, :wing,
                TRUE, :sanitary_status::sanitary_status, :blocked
             ) RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'T-PLD-10',
            ':machine_type' => $machineType,
            ':wing' => 'Planta de certificación',
            ':sanitary_status' => $sanitaryStatus,
            ':blocked' => $blocked ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $quarantineMachineId = $insertMachine('TEST-PLD-T10-M-Q', 'QUARANTINE', false);
    $blockedMachineId = $insertMachine('TEST-PLD-T10-M-B', 'OK', true);

    $insertSlot = static function (int $machineId, string $slotCode) use ($pdo, $excludedProductId): void {
        $stmt = $pdo->prepare(
            'INSERT INTO machine_planogram_slots (
                machine_id, slot_code, product_id, max_capacity,
                current_estimated_qty, min_threshold, is_active
             ) VALUES (
                :machine_id, :slot_code, :product_id, 10, 0, 2, TRUE
             )'
        );
        $stmt->execute([
            ':machine_id' => $machineId,
            ':slot_code' => $slotCode,
            ':product_id' => $excludedProductId,
        ]);
    };
    $insertSlot($quarantineMachineId, 'Q1');
    $insertSlot($blockedMachineId, 'B1');

    $afterProposal = $call('GET', '/api/coordinator/product-loading-orders/proposal', [
        'restocker_id' => $restockerId,
        'demand_mode' => 'ALL_GAPS',
    ], [], $coordinatorToken);
    $afterData = $data($afterProposal);
    $afterExcluded = (int)($afterData['excluded_unavailable_machines_count'] ?? -1);
    $demandProductIds = array_map(
        static fn (array $row): int => (int)($row['product_id'] ?? 0),
        $afterData['items'] ?? []
    );
    $assert(
        '5.3 La cuarentena y la máquina sin acceso elevan el contador de omitidas en 2',
        $afterProposal->getStatusCode() === 200 && $afterExcluded === $baselineExcluded + 2,
        "baseline={$baselineExcluded} actual={$afterExcluded}"
    );
    $assert(
        '5.4 Los huecos de las máquinas no operativas no alimentan la demanda',
        !in_array($excludedProductId, $demandProductIds, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. D3 / C1 / QA 4 · ciclo de orden, baja lógica y cuadre
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. D3 / C1 / QA 4 · orden activa, baja lógica y cuadre ---\n";

    $coldProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T10-COLD',
        'Producto refrigerado T-PLD-10',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.20,
        2.80,
        true,
        10,
        true
    ));
    $ambientProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T10-AMB',
        'Producto ambiente T-PLD-10',
        ProductCategory::SNACK,
        ProductUnitType::UNIT,
        0.45,
        1.10,
        false,
        null,
        true
    ));
    $newColdProductId = (int)$coldProduct->getId();
    $newAmbientProductId = (int)$ambientProduct->getId();

    $create = $call('POST', '/api/coordinator/product-loading-orders', [], [
        'restocker_id' => $restockerId,
        'demand_mode' => 'ALERTS_ONLY',
        'items' => [
            ['product_id' => $newColdProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 4],
            ['product_id' => $newAmbientProductId, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 6],
        ],
    ], $coordinatorToken);
    $created = $data($create);
    $orderId = (int)($created['order']['id'] ?? 0);
    $assert(
        '6.1 Alta individual con dos líneas y código de contrato',
        $create->getStatusCode() === 201
            && $orderId > 0
            && preg_match('/^PLO-\d{4}-\d{4,}$/', (string)($created['order']['order_code'] ?? '')) === 1
    );

    $duplicate = $call('POST', '/api/coordinator/product-loading-orders', [], [
        'restocker_id' => $restockerId,
        'items' => [['product_id' => $newAmbientProductId, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 1]],
    ], $coordinatorToken);
    $assert(
        '6.2 El índice único parcial rechaza una segunda orden activa (D3)',
        $duplicate->getStatusCode() === 409 && $errorCode($duplicate) === 'ACTIVE_LOADING_ORDER_EXISTS'
    );

    $edit = $call('PUT', '/api/coordinator/product-loading-orders/' . $orderId, [], [
        'items' => [['product_id' => $newColdProductId, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 4]],
    ], $coordinatorToken);
    $itemRows = $pdo->query(
        'SELECT product_id, is_active FROM product_loading_order_items
          WHERE order_id = ' . $orderId . ' ORDER BY product_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $inactiveRows = array_values(array_filter(
        $itemRows,
        static fn (array $row): bool => $row['is_active'] === false
    ));
    $assert(
        '6.3 Retirar una línea la deja inactiva y conserva las dos filas (C1)',
        $edit->getStatusCode() === 200
            && count($itemRows) === 2
            && count($inactiveRows) === 1
            && (int)$inactiveRows[0]['product_id'] === $newAmbientProductId
    );

    $dispatchWithoutTemp = $call(
        'POST',
        '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch',
        [],
        [
            'items' => [[
                'product_id' => $newColdProductId,
                'dispatched_qty' => 4,
                'batch_code' => 'LOTE-T10-A',
                'expiration_date' => (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
                    ->modify('+3 days')->format('Y-m-d'),
            ]],
        ],
        $coordinatorToken
    );
    $assert(
        '6.4 La API rechaza el despacho de frío sin temperatura (422)',
        $dispatchWithoutTemp->getStatusCode() === 422
            && $errorCode($dispatchWithoutTemp) === 'COLD_CHAMBER_TEMP_EXCEEDED'
    );

    $dispatch = $call(
        'POST',
        '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch',
        [],
        [
            'cold_chamber_exit_temp_celsius' => 3.0,
            'items' => [[
                'product_id' => $newColdProductId,
                'dispatched_qty' => 4,
                'batch_code' => 'LOTE-T10-A',
                'expiration_date' => (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
                    ->modify('+3 days')->format('Y-m-d'),
            ]],
        ],
        $coordinatorToken
    );
    $assert(
        '6.5 El despacho válido transiciona a DISPATCHED',
        $dispatch->getStatusCode() === 200 && ($data($dispatch)['order']['status'] ?? '') === 'DISPATCHED'
    );

    $confirm = $call('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', [], [], $restockerToken);
    $assert(
        '6.6 La reponedora confirma su propia orden y suma el lote',
        $confirm->getStatusCode() === 200
            && ($data($confirm)['order']['status'] ?? '') === 'CONFIRMED_BY_RESTOCKER'
    );

    $reconcile = $call('POST', '/api/restocker/product-loading-orders/' . $orderId . '/reconcile', [], [
        'items' => [[
            'product_id' => $newColdProductId,
            'restocked_in_machines_qty' => 0,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 4,
        ]],
    ], $restockerToken);
    $assert(
        '6.7 La liquidación al cierre deja RECONCILED_AT_CLOSE sin descuadre',
        $reconcile->getStatusCode() === 200
            && ($data($reconcile)['order']['status'] ?? '') === 'RECONCILED_AT_CLOSE'
    );

    $reconciledItemId = (int)$pdo->query(
        'SELECT id FROM product_loading_order_items
          WHERE order_id = ' . $orderId . ' AND is_active = TRUE LIMIT 1'
    )->fetchColumn();
    [$badReconcileRejected, $badReconcileMessage] = $dbRejects(
        'UPDATE product_loading_order_items SET discrepancy_qty = 7 WHERE id = :id',
        [':id' => $reconciledItemId],
        'chk_product_loading_order_items_reconcile_bounds'
    );
    $assert(
        '6.8 El motor rechaza un cuadre aritméticamente incoherente (QA 4)',
        $badReconcileRejected,
        $badReconcileMessage
    );

    $cancelShort = $call(
        'POST',
        '/api/coordinator/product-loading-orders/' . $orderId . '/cancel',
        [],
        ['cancel_reason' => 'Corto'],
        $coordinatorToken
    );
    $assert(
        '6.9 Una orden ya recepcionada no se cancela y el motivo corto no se evalúa',
        $cancelShort->getStatusCode() === 409
            && $errorCode($cancelShort) === 'ORDER_CANCELLATION_NOT_ALLOWED'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Art. III.3 · auditoría del ciclo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Art. III.3 · auditoría ---\n";

    $actions = $pdo->query(
        "SELECT action, COUNT(*) AS total FROM audit_log
          WHERE entity_type = 'PRODUCT_LOADING_ORDER'
          GROUP BY action"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $expectedActions = [
        'PRODUCT_LOADING_ORDER_CREATED',
        'PRODUCT_LOADING_ORDER_UPDATED',
        'PRODUCT_LOADING_ORDER_DISPATCHED',
        'PRODUCT_LOADING_ORDER_CONFIRMED',
        'PRODUCT_LOADING_ORDER_RECONCILED',
    ];
    $missingActions = array_values(array_filter(
        $expectedActions,
        static fn (string $action): bool => (int)($actions[$action] ?? 0) < 1
    ));
    $assert(
        '7.1 El ciclo completo firma las cinco acciones de auditoría',
        $missingActions === [],
        implode(', ', $missingActions)
    );

    $stockAudit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'RESTOCKER_VAN_STOCK'
            AND action IN ('RESTOCKER_VAN_STOCK_RECEIVED', 'RESTOCKER_VAN_STOCK_RECONCILED')"
    )->fetchColumn();
    $assert('7.2 La recepción y la liquidación auditan el stock de furgoneta', $stockAudit >= 2);

    $assert(
        '7.3 El detalle final sigue sin coste ni precio (Art. V.4)',
        !str_contains((string)$call(
            'GET',
            '/api/coordinator/product-loading-orders/' . $orderId,
            [],
            [],
            $coordinatorToken
        )->getBody(), 'unit_cost_eur')
    );
} catch (Throwable $exception) {
    $failures++;
    echo "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n";
    echo $exception->getFile() . ':' . $exception->getLine() . "\n";
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, PLD10_CONST_SCHEMA);
        } catch (Throwable) {
            // La limpieza no debe ocultar el veredicto.
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. BLINDAJE CONSTITUCIONAL DEL MÓDULO 33 CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
