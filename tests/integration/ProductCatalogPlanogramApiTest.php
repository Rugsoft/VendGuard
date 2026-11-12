<?php

declare(strict_types=1);

/**
 * ProductCatalogPlanogramApiTest · T-PLN-10
 *
 * Certifica la superficie HTTP del catálogo maestro y del planograma por
 * máquina (Módulo 32): router real, middleware de rol, controladores, servicios
 * y repositorios PDO sobre un esquema aislado. No hay dobles en la ruta.
 *
 * QUÉ SE CERTIFICA
 *   1. RBAC: catálogo/planograma de Coordinación sólo para `COORDINATOR`; el
 *      parque del reponedor sólo para `RESTOCKER`.
 *   2. Catálogo: listado con KPIs, alta, SKU duplicado, cerrojo sanitario,
 *      edición con SKU inmutable y baja/reactivación lógica.
 *   3. Planograma de Coordinación: lectura con coste y margen, `UPSERT` de un
 *      carril, reemplazo del mapa, baja lógica idempotente, preset y clonación.
 *   4. Reponedor: listado de sus zonas vivas, planograma sin `unit_cost_eur` ni
 *      `unit_margin_eur`, `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` fuera de ellas y
 *      bootstrap `my-route` con el parque.
 *
 * CATEGORÍA: `new_engine`. Sin PostgreSQL local la suite sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/ProductCatalogPlanogramApiTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const PRODUCT_PLANOGRAM_API_SCHEMA = 't_pln10_api';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-PLN-10 · API de catálogo y planograma (Módulo 32)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];
$schemaReady = false;

try {
    echo "\n--- Escenario aislado (DDL + semillas canónicas) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, PRODUCT_PLANOGRAM_API_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $seedRunner = new SeedRunner($pdo);
    $seedCounts = $seedRunner->seedAll();
    echo sprintf(
        "  Semillas: %d productos, %d carriles\n",
        (int)($seedCounts['vending_products'] ?? 0),
        (int)($seedCounts['machine_planogram_slots'] ?? 0)
    );

    $router = AppRouter::create();
    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);

    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
    $restocker = $userRepo->findByEmail('lucia.repostaje@vendguard.internal');
    $assert(
        '0.1 Semilla con coordinación, técnico y reponedor',
        $coordinator !== null && $technician !== null && $restocker !== null
    );
    if ($coordinator === null || $technician === null || $restocker === null) {
        throw new RuntimeException('La semilla no contiene las tres identidades necesarias.');
    }

    $coordinatorToken = $authService->generateInternalToken($coordinator);
    $technicianToken = $authService->generateInternalToken($technician);
    $restockerToken = $authService->generateInternalToken($restocker);

    $call = static function (
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null
    ) use ($router): Response {
        $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de las dos superficies ---\n";

    $noAuth = $call('GET', '/api/coordinator/products');
    $technicianOnProducts = $call('GET', '/api/coordinator/products', [], [], $technicianToken);
    $restockerOnCoordinator = $call('GET', '/api/coordinator/products', [], [], $restockerToken);
    $coordinatorOnRestocker = $call('GET', '/api/restocker/machines', [], [], $coordinatorToken);
    $assert(
        '1.1 Catálogo sin token 401 y con técnico/reponedor 403',
        $noAuth->getStatusCode() === 401
        && $technicianOnProducts->getStatusCode() === 403
        && $restockerOnCoordinator->getStatusCode() === 403
    );
    $assert(
        '1.2 El parque del reponedor rechaza al coordinador con 403',
        $coordinatorOnRestocker->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Catálogo maestro
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Catálogo maestro (/api/coordinator/products) ---\n";

    $list = $call('GET', '/api/coordinator/products', [], [], $coordinatorToken);
    $listData = $json($list)['data'] ?? [];
    $assert(
        '2.1 GET responde 200 con KPIs, productos y coste comercial',
        $list->getStatusCode() === 200
        && isset($listData['kpis']['total_products'], $listData['products'][0]['unit_cost_eur'])
        && count($listData['products']) >= 10
    );

    $coldList = $call('GET', '/api/coordinator/products', ['requires_cold_chain' => 'true'], [], $coordinatorToken);
    $coldProducts = $json($coldList)['data']['products'] ?? [];
    $assert(
        '2.2 Filtro de cadena de frío devuelve sólo referencias refrigeradas',
        $coldList->getStatusCode() === 200
        && $coldProducts !== []
        && array_reduce($coldProducts, static fn (bool $ok, array $p): bool => $ok && $p['requires_cold_chain'] === true, true)
    );

    $newSku = 'T10-API-CUPS';
    $createPayload = [
        'sku' => $newSku,
        'name' => 'Vasos de certificación API (tira)',
        'category' => 'CUP_STIRRER_CONSUMABLE',
        'unit_type' => 'SLEEVE',
        'unit_cost_eur' => 0.03,
        'sale_price_eur' => 0.0,
        'requires_cold_chain' => false,
    ];
    $created = $call('POST', '/api/coordinator/products', [], $createPayload, $coordinatorToken);
    $createdData = $json($created)['data'] ?? [];
    $createdId = (int)($createdData['id'] ?? 0);
    $assert(
        '2.3 POST crea el producto con 201 y devuelve el id',
        $created->getStatusCode() === 201 && $createdId > 0 && ($createdData['sku'] ?? '') === $newSku
    );

    $duplicate = $call('POST', '/api/coordinator/products', [], $createPayload, $coordinatorToken);
    $duplicateBody = $json($duplicate);
    $assert(
        '2.4 SKU duplicado 409 DUPLICATE_PRODUCT_SKU',
        $duplicate->getStatusCode() === 409
        && ($duplicateBody['error']['code'] ?? '') === 'DUPLICATE_PRODUCT_SKU'
    );

    $invalidCold = $call('POST', '/api/coordinator/products', [], [
        'sku' => 'T10-API-COLD',
        'name' => 'Perecedero sin frío de certificación',
        'category' => 'PERISHABLE_FOOD',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 1.0,
        'sale_price_eur' => 2.0,
        'requires_cold_chain' => false,
        'max_shelf_life_days' => 5,
    ], $coordinatorToken);
    $assert(
        '2.5 Perecedero sin cadena de frío 422 PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE',
        $invalidCold->getStatusCode() === 422
        && ($json($invalidCold)['error']['code'] ?? '') === 'PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE'
    );

    $updated = $call('PUT', "/api/coordinator/products/{$createdId}", [], [
        'name' => 'Vasos de certificación API renombrados',
    ], $coordinatorToken);
    $assert(
        '2.6 PUT actualiza campos omitidos sin tocar el SKU',
        $updated->getStatusCode() === 200
        && ($json($updated)['data']['name'] ?? '') === 'Vasos de certificación API renombrados'
        && ($json($updated)['data']['sku'] ?? '') === $newSku
    );

    $skuChange = $call('PUT', "/api/coordinator/products/{$createdId}", [], [
        'sku' => 'T10-API-OTHER',
    ], $coordinatorToken);
    $assert(
        '2.7 Cambio de SKU 409 SKU_CHANGE_FORBIDDEN',
        $skuChange->getStatusCode() === 409
        && ($json($skuChange)['error']['code'] ?? '') === 'SKU_CHANGE_FORBIDDEN'
    );

    $deactivate = $call('PATCH', "/api/coordinator/products/{$createdId}/status", [], ['is_active' => false], $coordinatorToken);
    $deactivateData = $json($deactivate)['data'] ?? [];
    $assert(
        '2.8 PATCH de estado da de baja y publica affected_active_slots_count',
        $deactivate->getStatusCode() === 200
        && ($deactivateData['is_active'] ?? true) === false
        && ($deactivateData['affected_active_slots_count'] ?? -1) === 0
    );

    $inactiveList = $call('GET', '/api/coordinator/products', ['status' => 'INACTIVE'], [], $coordinatorToken);
    $inactiveSkus = array_column($json($inactiveList)['data']['products'] ?? [], 'sku');
    $assert(
        '2.9 El producto inactivo aparece en el filtro INACTIVE',
        $inactiveList->getStatusCode() === 200 && in_array($newSku, $inactiveSkus, true)
    );

    $reactivate = $call('PATCH', "/api/coordinator/products/{$createdId}/status", [], ['is_active' => true], $coordinatorToken);
    $assert(
        '2.10 Reactivación lógica 200',
        $reactivate->getStatusCode() === 200 && ($json($reactivate)['data']['is_active'] ?? false) === true
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Planograma de Coordinación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Planograma de Coordinación ---\n";

    $machines = $pdo->query(
        "SELECT id, code, machine_type FROM machines
          WHERE is_active = TRUE AND deleted_at IS NULL AND machine_type = 'COMBO'
          ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert('3.1 Hay al menos dos máquinas COMBO sembradas', count($machines) >= 2);
    if (count($machines) < 2) {
        throw new RuntimeException('La semilla no ofrece dos máquinas COMBO para clonar.');
    }

    $machineA = (int)$machines[0]['id'];
    $machineB = (int)$machines[1]['id'];
    $products = $pdo->query(
        'SELECT id, sku FROM vending_products WHERE is_active = TRUE ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert('3.2 Hay productos activos para asignar', $products !== []);
    $productId = (int)$products[0]['id'];

    $planogram = $call('GET', "/api/coordinator/machines/{$machineA}/planogram", [], [], $coordinatorToken);
    $planogramData = $json($planogram)['data'] ?? [];
    $assert(
        '3.3 GET incluye máquina, resumen y carriles con unit_cost_eur',
        $planogram->getStatusCode() === 200
        && isset($planogramData['machine']['code'], $planogramData['summary']['fill_rate_percent'])
        && isset($planogramData['slots'][0]['unit_cost_eur'])
    );

    $compatibleSources = $planogramData['compatible_source_machines'] ?? [];
    $compatibleIds = array_map('intval', array_column($compatibleSources, 'id'));
    $assert(
        '3.3b GET devuelve las fuentes compatibles sin incluir la máquina actual',
        in_array($machineB, $compatibleIds, true)
        && !in_array($machineA, $compatibleIds, true)
        && array_key_exists('active_slots_count', $compatibleSources[0] ?? [])
    );

    $slotCode = 'T10A';
    $slotPayload = [
        'product_id' => $productId,
        'max_capacity' => 10,
        'current_estimated_qty' => 5,
        'min_threshold' => 2,
        'is_active' => true,
    ];
    $savedSlot = $call('PUT', "/api/coordinator/machines/{$machineA}/planogram/slots/{$slotCode}", [], $slotPayload, $coordinatorToken);
    $savedSlotData = $json($savedSlot)['data'] ?? [];
    $assert(
        '3.4 PUT de carril crea el UPSERT y devuelve el estado del stock',
        $savedSlot->getStatusCode() === 200
        && ($savedSlotData['slot_code'] ?? '') === $slotCode
        && ($savedSlotData['stock_status'] ?? '') === 'OK'
    );

    $savedMap = $call('PUT', "/api/coordinator/machines/{$machineA}/planogram", [], [
        'slots' => [[
            'slot_code' => $slotCode,
            'product_id' => $productId,
            'max_capacity' => 10,
            'current_estimated_qty' => 7,
            'min_threshold' => 2,
        ]],
    ], $coordinatorToken);
    $savedMapData = $json($savedMap)['data'] ?? [];
    $assert(
        '3.5 PUT del mapa reemplaza los carriles y conserva el carril enviado',
        $savedMap->getStatusCode() === 200
        && count($savedMapData['slots'] ?? []) === 1
        && (int)($savedMapData['slots'][0]['current_estimated_qty'] ?? 0) === 7
    );

    $deletedSlot = $call('DELETE', "/api/coordinator/machines/{$machineA}/planogram/slots/{$slotCode}", [], [], $coordinatorToken);
    $afterDelete = $call('GET', "/api/coordinator/machines/{$machineA}/planogram", [], [], $coordinatorToken);
    $afterDeleteCodes = array_column($json($afterDelete)['data']['slots'] ?? [], 'slot_code');
    $assert(
        '3.6 DELETE retira el carril lógicamente y deja de aparecer',
        $deletedSlot->getStatusCode() === 200 && !in_array($slotCode, $afterDeleteCodes, true)
    );

    $preset = $call('POST', "/api/coordinator/machines/{$machineA}/planogram/apply-preset", [], [
        'initial_stock_mode' => 'FULL',
    ], $coordinatorToken);
    $presetData = $json($preset)['data'] ?? [];
    $assert(
        '3.7 POST apply-preset devuelve la plantilla aplicada con carriles',
        $preset->getStatusCode() === 200 && count($presetData['slots'] ?? []) > 0
    );

    $clone = $call('POST', "/api/coordinator/machines/{$machineB}/planogram/clone", [], [
        'source_machine_id' => $machineA,
        'initial_stock_mode' => 'ZERO',
    ], $coordinatorToken);
    $cloneData = $json($clone)['data'] ?? [];
    $assert(
        '3.8 POST clone informa origen, modo y carriles omitidos',
        $clone->getStatusCode() === 200
        && (int)($cloneData['source_machine']['id'] ?? 0) === $machineA
        && ($cloneData['initial_stock_mode'] ?? '') === 'ZERO'
        && is_array($cloneData['skipped_inactive_slots'] ?? null)
    );

    $coldMachineId = (int)$pdo->query(
        "SELECT m.id
           FROM machines m
           INNER JOIN machine_planogram_slots s ON s.machine_id = m.id AND s.is_active = TRUE
           INNER JOIN vending_products vp ON vp.id = s.product_id AND vp.requires_cold_chain = TRUE
          WHERE m.is_active = TRUE AND m.deleted_at IS NULL AND m.machine_type = 'COMBO'
          ORDER BY m.id
          LIMIT 1"
    )->fetchColumn();
    $assert('3.9 Hay una máquina COMBO con carril refrigerado para QA 4', $coldMachineId > 0);

    $typeChange = $call('PUT', "/api/coordinator/admin/machines/{$coldMachineId}", [], [
        'machine_type' => 'SNACKS',
    ], $coordinatorToken);
    $typeChangeBody = $json($typeChange);
    $assert(
        '3.10 El alias administrativo rechaza la tipología incompatible con 409 QA 4',
        $typeChange->getStatusCode() === 409
        && ($typeChangeBody['error']['code'] ?? '') === 'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE'
        && ($typeChangeBody['error']['conflicts'] ?? []) !== [],
        json_encode(['status' => $typeChange->getStatusCode(), 'body' => $typeChangeBody], JSON_UNESCAPED_UNICODE)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Planograma del reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Lectura segregada del reponedor ---\n";

    $routeMachines = $call('GET', '/api/restocker/machines', [], [], $restockerToken);
    $routeMachinesData = $json($routeMachines)['data']['machines'] ?? [];
    $assert(
        '4.1 GET /api/restocker/machines devuelve máquinas de sus zonas',
        $routeMachines->getStatusCode() === 200 && $routeMachinesData !== []
    );
    if ($routeMachinesData === []) {
        throw new RuntimeException('El reponedor semilla no tiene máquinas en sus zonas vivas.');
    }

    $restockerMachineId = (int)$routeMachinesData[0]['id'];
    $restockerPlanogram = $call('GET', "/api/restocker/machines/{$restockerMachineId}/planogram", [], [], $restockerToken);
    $restockerBody = $json($restockerPlanogram);
    $encoded = (string)json_encode($restockerBody, JSON_UNESCAPED_UNICODE);
    $assert(
        '4.2 GET del planograma responde 200 con resumen y sin coste ni margen',
        $restockerPlanogram->getStatusCode() === 200
        && isset($restockerBody['data']['machine']['code'], $restockerBody['data']['summary']['active_slots_count'])
        && !str_contains($encoded, 'unit_cost_eur')
        && !str_contains($encoded, 'unit_margin_eur')
    );

    $visibleIds = array_map(static fn (array $row): int => (int)$row['id'], $routeMachinesData);
    $outsideId = null;
    foreach ($pdo->query('SELECT id FROM machines WHERE is_active = TRUE AND deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
        if (!in_array((int)$candidate, $visibleIds, true)) {
            $outsideId = (int)$candidate;
            break;
        }
    }
    $assert('4.3 Existe una máquina fuera de las zonas del reponedor', $outsideId !== null);
    if ($outsideId !== null) {
        $outside = $call('GET', "/api/restocker/machines/{$outsideId}/planogram", [], [], $restockerToken);
        $assert(
            '4.4 Máquina fuera de zona 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
            $outside->getStatusCode() === 403
            && ($json($outside)['error']['code'] ?? '') === 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
        );
    }

    $route = $call('GET', '/api/restocker/my-route', [], [], $restockerToken);
    $routeData = $json($route)['data'] ?? [];
    $assert(
        '4.5 my-route publica machines junto al bootstrap existente',
        $route->getStatusCode() === 200
        && is_array($routeData['machines'] ?? null)
        && count($routeData['machines']) >= 1
        && ($routeData['route_stops'] ?? null) === []
    );
} catch (Throwable $exception) {
    $failures++;
    echo "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, PRODUCT_PLANOGRAM_API_SCHEMA);
        } catch (Throwable) {
            // El cierre no debe ocultar el resultado de la suite.
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-10 (API del catálogo y planograma) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
