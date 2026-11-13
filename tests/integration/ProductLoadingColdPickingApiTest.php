<?php

declare(strict_types=1);

/**
 * ProductLoadingColdPickingApiTest · T-PLD-09
 *
 * Certifica la superficie HTTP del Módulo 33 sobre el router real, el middleware
 * de rol, los controladores, los servicios y los repositorios PDO:
 *
 *   1. RBAC: Coordinación no entra en la superficie móvil y el reponedor no
 *      entra en la consola de Coordinación; sin token, `401`.
 *   2. Inventario por lote: consulta, ajuste auditado con motivo de 20
 *      caracteres reales y opacidad de `unit_cost_eur`.
 *   3. Propuesta, emisión individual, listado, detalle y Hoja A4 sin importes.
 *   4. Edición con baja lógica, despacho con cerrojo térmico y transiciones.
 *   5. Recepción en un toque, aislamiento horizontal y liquidación al cierre.
 *   6. Bootstrap móvil extendido con orden activa, última liquidada y stock FEFO.
 *   7. Cancelación justificada.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/ProductLoadingColdPickingApiTest.php
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const PLD09_API_SCHEMA = 't_pld09_api';

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
echo " VENDGUARD · T-PLD-09 · API de carga de producto y picking en frío\n";
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

/** @return list<string> */
$collectKeys = static function (array $payload) use (&$collectKeys): array {
    $keys = [];
    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        if (is_array($value)) {
            $keys = array_merge($keys, $collectKeys($value));
        }
    }

    return $keys;
};

$schemaReady = false;

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, PLD09_API_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'pld09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'pld09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('pld09-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'PLD9-' . mb_strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-PLD-09');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-PLD-09');
    $otherId = $createUser('other', 'RESTOCKER', 'Reposición Ajena T-PLD-09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-PLD-09');

    $userRepo = new PdoUserRepository($pdo);
    $productRepo = new PdoVendingProductRepository($pdo);

    $coldProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T9-COLD',
        'Producto refrigerado T-PLD-09',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.75,
        3.50,
        true,
        10,
        true
    ));
    $ambientProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T9-AMB',
        'Producto ambiente T-PLD-09',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.60,
        1.40,
        false,
        null,
        true
    ));

    $coldProductId = (int)$coldProduct->getId();
    $ambientProductId = (int)$ambientProduct->getId();

    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
    $otherToken = $authService->generateInternalToken($userRepo->findById($otherId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));

    $router = AppRouter::create();

    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
    $expiration = $today->modify('+3 days')->format('Y-m-d');
    $batchCode = 'LOTE-T9-A';

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de las dos superficies ---\n";

    $anonymous = $call('GET', '/api/coordinator/restocker-van-stocks');
    $assert(
        '1.1 Sin token, el inventario de Coordinación responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/product-loading-orders', [], $ownerToken);
    $assert(
        '1.2 El reponedor no alcanza la consola de órdenes de Coordinación (403)',
        $restockerOnCoordinator->getStatusCode() === 403 && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );

    $coordinatorOnMobile = $call('GET', '/api/restocker/van-stocks', [], $coordinatorToken);
    $assert(
        '1.3 Coordinación no alcanza el stock móvil del reponedor (403)',
        $coordinatorOnMobile->getStatusCode() === 403 && $errorCode($coordinatorOnMobile) === 'FORBIDDEN'
    );

    $technicianOnMobile = $call('GET', '/api/restocker/my-route', [], $technicianToken);
    $assert('1.4 El técnico SAT no alcanza el bootstrap del reponedor (403)', $technicianOnMobile->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Inventario por lote y ajuste auditado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Inventario por lote de las furgonetas ---\n";

    $emptyInventory = $call('GET', '/api/coordinator/restocker-van-stocks', [], $coordinatorToken);
    $assert(
        '2.1 Coordinación consulta el inventario con KPIs',
        $emptyInventory->getStatusCode() === 200
            && isset($data($emptyInventory)['kpis'], $data($emptyInventory)['stocks'])
            && $data($emptyInventory)['stocks'] === []
    );

    $shortReason = $call('PUT', '/api/coordinator/restocker-van-stocks/' . $ownerId, [
        'product_id' => $coldProductId,
        'batch_code' => $batchCode,
        'expiration_date' => $expiration,
        'quantity_available' => 5,
        'reason' => 'Corto',
    ], $coordinatorToken);
    $assert(
        '2.2 Un motivo de menos de 20 caracteres reales se rechaza con 422',
        $shortReason->getStatusCode() === 422 && $errorCode($shortReason) === 'INVALID_JUSTIFICATION_LENGTH',
        $errorCode($shortReason)
    );

    $adjust = $call('PUT', '/api/coordinator/restocker-van-stocks/' . $ownerId, [
        'product_id' => $coldProductId,
        'batch_code' => $batchCode,
        'expiration_date' => $expiration,
        'quantity_available' => 5,
        'reason' => 'Carga inicial certificada de la furgoneta T-PLD-09.',
    ], $coordinatorToken);
    $adjustData = $data($adjust);
    $assert(
        '2.3 El ajuste válido persiste el lote con auditoría',
        $adjust->getStatusCode() === 200
            && (int)($adjustData['stock']['quantity_available'] ?? -1) === 5
            && (int)($adjustData['previous_quantity'] ?? -1) === -1
            && (int)($adjustData['audit_event_id'] ?? 0) > 0,
        json_encode($adjustData)
    );
    $assert(
        '2.4 La respuesta del ajuste no publica unit_cost_eur (Art. V.4)',
        !in_array('unit_cost_eur', $collectKeys($adjustData), true)
    );

    $inventory = $call('GET', '/api/coordinator/restocker-van-stocks', [], $coordinatorToken, [
        'restocker_id' => $ownerId,
        'expiry_status' => 'OK',
    ]);
    $inventoryStocks = $data($inventory)['stocks'] ?? [];
    $assert(
        '2.5 El inventario filtra por reponedor y semáforo FEFO',
        $inventory->getStatusCode() === 200
            && count($inventoryStocks) === 1
            && ($inventoryStocks[0]['batch_code'] ?? '') === $batchCode
            && ($inventoryStocks[0]['expiry_status'] ?? '') === 'OK'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Propuesta y emisión
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Propuesta, emisión, detalle y Hoja A4 ---\n";

    $proposal = $call('GET', '/api/coordinator/product-loading-orders/proposal', [], $coordinatorToken, [
        'restocker_id' => $ownerId,
        'demand_mode' => 'ALL_GAPS',
    ]);
    $proposalData = $data($proposal);
    $assert(
        '3.1 La propuesta se calcula para un reponedor activo sin importes',
        $proposal->getStatusCode() === 200
            && isset($proposalData['items'], $proposalData['total_suggested_qty'])
            && !in_array('unit_cost_eur', $collectKeys($proposalData), true)
    );

    $missingRestocker = $call('POST', '/api/coordinator/product-loading-orders', [
        'items' => [['product_id' => $coldProductId, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 4]],
    ], $coordinatorToken);
    $assert('3.2 El alta sin restocker_id responde 400', $missingRestocker->getStatusCode() === 400);

    $create = $call('POST', '/api/coordinator/product-loading-orders', [
        'restocker_id' => $ownerId,
        'demand_mode' => 'ALERTS_ONLY',
        'safety_buffer_pct' => 10,
        'notes' => 'Orden de certificación T-PLD-09.',
        'items' => [[
            'product_id' => $coldProductId,
            'reason' => 'MANUAL_ADDITION',
            'requested_qty' => 4,
        ]],
    ], $coordinatorToken);
    $created = $data($create);
    $orderId = (int)($created['order']['id'] ?? 0);
    $orderCode = (string)($created['order']['order_code'] ?? '');
    $assert(
        '3.3 El alta individual responde 201 con código PLO-AAAA-NNNN',
        $create->getStatusCode() === 201
            && $orderId > 0
            && preg_match('/^PLO-\d{4}-\d{4,}$/', $orderCode) === 1,
        $orderCode
    );
    $assert(
        '3.4 El detalle recién creado no publica coste ni precio (Art. V.4)',
        !in_array('unit_cost_eur', $collectKeys($created), true)
            && !in_array('sale_price_eur', $collectKeys($created), true)
    );

    $duplicate = $call('POST', '/api/coordinator/product-loading-orders', [
        'restocker_id' => $ownerId,
        'items' => [['product_id' => $coldProductId, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 1]],
    ], $coordinatorToken);
    $assert(
        '3.5 Una segunda orden activa del mismo reponedor responde 409 (D3)',
        $duplicate->getStatusCode() === 409 && $errorCode($duplicate) === 'ACTIVE_LOADING_ORDER_EXISTS'
    );

    $list = $call('GET', '/api/coordinator/product-loading-orders', [], $coordinatorToken, [
        'restocker_id' => $ownerId,
    ]);
    $listData = $data($list);
    $assert(
        '3.6 El listado publica KPIs y la orden recién creada',
        $list->getStatusCode() === 200
            && isset($listData['kpis']['pending_picking_count'])
            && count($listData['orders'] ?? []) === 1
            && (int)($listData['orders'][0]['id'] ?? 0) === $orderId
    );

    $detail = $call('GET', '/api/coordinator/product-loading-orders/' . $orderId, [], $coordinatorToken);
    $detailData = $data($detail);
    $assert(
        '3.7 El detalle ordena el frío primero y no publica importes (D4, Art. V.4)',
        $detail->getStatusCode() === 200
            && (int)($detailData['order']['id'] ?? 0) === $orderId
            && (bool)($detailData['items'][0]['requires_cold_chain'] ?? false) === true
            && !in_array('unit_cost_eur', $collectKeys($detailData), true)
    );

    $sheet = $call('GET', '/api/coordinator/product-loading-orders/' . $orderId . '/picking-sheet', [], $coordinatorToken);
    $sheetData = $data($sheet);
    $assert(
        '3.8 La Hoja A4 separa cámara frigorífica y almacén sin importes',
        $sheet->getStatusCode() === 200
            && count($sheetData['sections']['cold_chain']['items'] ?? []) === 1
            && count($sheetData['sections']['general']['items'] ?? []) === 0
            && !in_array('unit_cost_eur', $collectKeys($sheetData), true)
    );

    $update = $call('PUT', '/api/coordinator/product-loading-orders/' . $orderId, [
        'items' => [[
            'product_id' => $coldProductId,
            'reason' => 'MANUAL_ADDITION',
            'requested_qty' => 4,
        ]],
    ], $coordinatorToken);
    $assert('3.9 La edición de una orden en preparación responde 200', $update->getStatusCode() === 200);

    // ─────────────────────────────────────────────────────────────────────
    // 4. Despacho con cerrojo térmico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Despacho y cerrojo sanitario ---\n";

    $dispatchPayload = [
        'items' => [[
            'product_id' => $coldProductId,
            'dispatched_qty' => 4,
            'batch_code' => $batchCode,
            'expiration_date' => $expiration,
        ]],
    ];

    $dispatchWithoutTemp = $call(
        'POST',
        '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch',
        $dispatchPayload,
        $coordinatorToken
    );
    $assert(
        '4.1 El frío sin temperatura de cámara se rechaza con 422 (Art. II)',
        $dispatchWithoutTemp->getStatusCode() === 422
            && $errorCode($dispatchWithoutTemp) === 'COLD_CHAMBER_TEMP_EXCEEDED',
        $errorCode($dispatchWithoutTemp)
    );

    $dispatch = $call(
        'POST',
        '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch',
        $dispatchPayload + ['cold_chamber_exit_temp_celsius' => 3.0],
        $coordinatorToken
    );
    $dispatched = $data($dispatch);
    $assert(
        '4.2 El despacho válido persiste lote, caducidad y transición DISPATCHED',
        $dispatch->getStatusCode() === 200
            && ($dispatched['order']['status'] ?? '') === 'DISPATCHED'
            && (int)($dispatched['items'][0]['dispatched_qty'] ?? 0) === 4
            && ($dispatched['items'][0]['batch_code'] ?? '') === $batchCode
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Recepción móvil y liquidación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Recepción en un toque y liquidación ---\n";

    $confirmOther = $call('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', [], $otherToken);
    $assert(
        '5.1 Otro reponedor no puede confirmar una orden ajena (403)',
        $confirmOther->getStatusCode() === 403
            && $errorCode($confirmOther) === 'FORBIDDEN_LOADING_ORDER_ACCESS',
        $errorCode($confirmOther)
    );

    $confirm = $call('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', [], $ownerToken);
    $confirmed = $data($confirm);
    $assert(
        '5.2 La recepción propia suma el lote y pasa a CONFIRMED_BY_RESTOCKER',
        $confirm->getStatusCode() === 200
            && ($confirmed['order']['status'] ?? '') === 'CONFIRMED_BY_RESTOCKER'
    );

    $ownStock = $call('GET', '/api/restocker/van-stocks', [], $ownerToken);
    $stockRows = $data($ownStock)['stocks'] ?? [];
    $assert(
        '5.3 El stock móvil refleja 5 + 4 unidades y no publica coste',
        $ownStock->getStatusCode() === 200
            && (int)($stockRows[0]['quantity_available'] ?? 0) === 9
            && !in_array('unit_cost_eur', $collectKeys($data($ownStock)), true)
    );

    $reconcile = $call('POST', '/api/restocker/product-loading-orders/' . $orderId . '/reconcile', [
        'items' => [[
            'product_id' => $coldProductId,
            'restocked_in_machines_qty' => 1,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 8,
        ]],
    ], $ownerToken);
    $reconciled = $data($reconcile);
    $assert(
        '5.4 La liquidación al cierre pasa a RECONCILED_AT_CLOSE',
        $reconcile->getStatusCode() === 200
            && ($reconciled['order']['status'] ?? '') === 'RECONCILED_AT_CLOSE',
        $errorCode($reconcile)
    );

    $stockAfter = $call('GET', '/api/restocker/van-stocks', [], $ownerToken);
    $assert(
        '5.5 El saldo del lote queda en el retorno contado (D3)',
        (int)($data($stockAfter)['stocks'][0]['quantity_available'] ?? -1) === 8
    );

    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteData = $data($myRoute);
    $assert(
        '5.6 El bootstrap móvil publica stock FEFO y la última orden liquidada (C4)',
        $myRoute->getStatusCode() === 200
            && ($myRouteData['active_loading_order'] ?? null) === null
            && ($myRouteData['last_reconciled_order']['order']['id'] ?? 0) === $orderId
            && count($myRouteData['van_product_stocks'] ?? []) === 1
            && !in_array('unit_cost_eur', $collectKeys($myRouteData), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Cancelación justificada
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Cancelación justificada ---\n";

    $secondCreate = $call('POST', '/api/coordinator/product-loading-orders', [
        'restocker_id' => $ownerId,
        'items' => [[
            'product_id' => $ambientProductId,
            'reason' => 'ROUTE_GAP_FILL',
            'requested_qty' => 2,
        ]],
    ], $coordinatorToken);
    $secondId = (int)($data($secondCreate)['order']['id'] ?? 0);
    $assert('6.1 Tras liquidar, el reponedor puede recibir otra orden', $secondCreate->getStatusCode() === 201 && $secondId > 0);

    $shortCancel = $call('POST', '/api/coordinator/product-loading-orders/' . $secondId . '/cancel', [
        'cancel_reason' => 'No procede',
    ], $coordinatorToken);
    $assert(
        '6.2 La cancelación con motivo corto responde 422 (Art. V.1)',
        $shortCancel->getStatusCode() === 422
            && $errorCode($shortCancel) === 'INVALID_JUSTIFICATION_LENGTH'
    );

    $cancel = $call('POST', '/api/coordinator/product-loading-orders/' . $secondId . '/cancel', [
        'cancel_reason' => 'Cancelación certificada por cambio de ruta del reponedor.',
    ], $coordinatorToken);
    $assert(
        '6.3 La cancelación válida deja la orden CANCELLED',
        $cancel->getStatusCode() === 200 && ($data($cancel)['order']['status'] ?? '') === 'CANCELLED'
    );
} catch (Throwable $exception) {
    echo "\n[FALLO INESPERADO] " . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, PLD09_API_SCHEMA);
        } catch (Throwable $cleanupException) {
            echo "\n[AVISO] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n";
            $failures++;
        }
    }
}

echo "\n======================================================================\n";
echo " RESULTADO: {$assertions} aserciones · {$failures} fallo(s)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
