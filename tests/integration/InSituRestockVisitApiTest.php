<?php

declare(strict_types=1);

/**
 * InSituRestockVisitApiTest · T-RVF-09
 *
 * Certifica la superficie HTTP del Módulo 34 sobre el router real, el middleware
 * de rol, los controladores, el servicio de visitas y los repositorios PDO:
 *
 *   1. RBAC: sin token 401; Coordinación no entra en la superficie móvil y el
 *      reponedor no entra en la consola de Coordinación; el técnico SAT tampoco.
 *   2. Registro idempotente de una visita `COMPLETED` y traducción de errores
 *      sanitarios del Triple Cerrojo (`CABIN_TEMP_EXCEEDS_SANITARY_LIMIT`).
 *   3. Historial del reponedor y `recent_visits` del bootstrap sin costes.
 *   4. Libro de Coordinación con cinco KPIs, filtros y detalle con snapshot
 *      contable (`QA 3`, Art. V.4).
 *   5. Evidencia fotográfica multipart/base64 y volcado de conflicto offline.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/InSituRestockVisitApiTest.php
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

const RVF09_API_SCHEMA = 't_rvf09_api';

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
echo " VENDGUARD · T-RVF-09 · API de visitas de reposición in situ\n";
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
$uploadedFiles = [];
$exitCode = 1;

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, RVF09_API_SCHEMA);
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
            ':email' => 'rvf09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rvf09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rvf09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RVF9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-RVF-09');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-RVF-09');
    $otherId = $createUser('other', 'RESTOCKER', 'Reposición Ajena T-RVF-09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-RVF-09');

    $zoneStmt = $pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-RVF9', 'Zona de certificación T-RVF-09', '#2560ff')
         RETURNING id"
    );
    $zoneId = (int)$zoneStmt->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-RVF9',
        ':site_code_key' => 'sede-rvf9',
        ':name' => 'Sede de certificación T-RVF-09',
        ':address' => 'Carrer de la Reposició 9, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, 'PERISHABLE_FOOD', :floor_wing)
         RETURNING id"
    );
    $machineStmt->execute([
        ':location_id' => $locationId,
        ':code' => 'RVF9-FOOD-01',
        ':model' => 'Modelo de certificación RVF9',
        ':floor_wing' => 'Planta baja',
    ]);
    $machineId = (int)$machineStmt->fetchColumn();

    $assignStmt = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assignStmt->execute([':restocker_id' => $ownerId, ':zone_id' => $zoneId]);
    $assignStmt->execute([':restocker_id' => $otherId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'UPDATE restocker_zone_assignments
            SET removed_at = CURRENT_TIMESTAMP
          WHERE restocker_id = :restocker_id AND is_primary = TRUE'
    )->execute([':restocker_id' => $otherId]);

    $productStmt = $pdo->prepare(
        "INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES ('RVF9-SANDWICH', 'Sándwich T-RVF-09', 'PERISHABLE_FOOD', 'UNIT', TRUE, 5, 1.25, 2.40)
         RETURNING id"
    );
    $productStmt->execute();
    $productId = (int)$productStmt->fetchColumn();

    $slotStmt = $pdo->prepare(
        "INSERT INTO machine_planogram_slots
             (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold,
              current_batch_code, current_expiration_date)
         VALUES (:machine_id, '11', :product_id, 12, 7, 2, 'RVF9-NEW', CURRENT_DATE + 5)
         RETURNING id"
    );
    $slotStmt->execute([':machine_id' => $machineId, ':product_id' => $productId]);
    $slotId = (int)$slotStmt->fetchColumn();

    $orderStmt = $pdo->prepare(
        "INSERT INTO product_loading_orders
             (order_code, restocker_id, status, demand_mode, safety_buffer_pct, created_by, confirmed_at)
         VALUES ('PLO-2026-9009', :restocker_id, 'CONFIRMED_BY_RESTOCKER', 'ALERTS_ONLY', 10, :created_by, CURRENT_TIMESTAMP)
         RETURNING id"
    );
    $orderStmt->execute([':restocker_id' => $ownerId, ':created_by' => $coordinatorId]);
    $loadingOrderId = (int)$orderStmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO product_loading_order_items
             (order_id, product_id, reason, requested_qty, batch_code, expiration_date)
         VALUES (:order_id, :product_id, 'MANUAL_ADDITION', 4, 'RVF9-NEW', CURRENT_DATE + 5)"
    )->execute([':order_id' => $loadingOrderId, ':product_id' => $productId]);

    $vanStockStmt = $pdo->prepare(
        "INSERT INTO restocker_van_product_stocks
             (restocker_id, product_id, batch_code, expiration_date, quantity_available)
         VALUES (:restocker_id, :product_id, 'RVF9-VAN', CURRENT_DATE + 4, 2)"
    );
    $vanStockStmt->execute([':restocker_id' => $ownerId, ':product_id' => $productId]);

    $hygiene = json_encode([
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ], JSON_THROW_ON_ERROR);

    $seedVisitStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visits
             (visit_code, machine_id, restocker_id, loading_order_id, status, cabin_temp_celsius,
              hygiene_checklist_json, client_mutation_id)
         VALUES ('MRV-2026-9009', :machine_id, :restocker_id, :loading_order_id, 'COMPLETED', 3.5,
                 CAST(:hygiene AS jsonb), 'seed-rvf09-0001')
         RETURNING id"
    );
    $seedVisitStmt->execute([
        ':machine_id' => $machineId,
        ':restocker_id' => $ownerId,
        ':loading_order_id' => $loadingOrderId,
        ':hygiene' => $hygiene,
    ]);
    $seedVisitId = (int)$seedVisitStmt->fetchColumn();

    $seedItemStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visit_items
             (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
              qty_withdrawn_damaged, qty_added, qty_after, withdrawn_batch_code,
              withdrawn_expiration_date, loaded_batch_code, loaded_expiration_date,
              unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
         VALUES (:visit_id, :slot_id, '11', :product_id, 5, 1, 0, 3, 7, 'RVF9-OLD',
                 CURRENT_DATE - 1, 'RVF9-NEW', CURRENT_DATE + 5, 1.25, 1.25)"
    );
    $seedItemStmt->execute([
        ':visit_id' => $seedVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
    ]);

    $userRepo = new PdoUserRepository($pdo);
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
        array $query = [],
        array $files = []
    ) use ($router): Response {
        $headers = ['Content-Type' => $files === [] ? 'application/json' : 'multipart/form-data'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers, $files));
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Guardas RBAC de las dos superficies ---\n";

    $anonymous = $call('GET', '/api/restocker/visits');
    $assert(
        '1.1 Sin token, el historial móvil responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );

    $coordinatorOnMobile = $call('GET', '/api/restocker/visits', [], $coordinatorToken);
    $assert(
        '1.2 Coordinación no entra en el historial móvil (403 FORBIDDEN)',
        $coordinatorOnMobile->getStatusCode() === 403 && $errorCode($coordinatorOnMobile) === 'FORBIDDEN'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/restock-visits', [], $ownerToken);
    $assert(
        '1.3 El reponedor no entra en el libro de Coordinación (403 FORBIDDEN)',
        $restockerOnCoordinator->getStatusCode() === 403 && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );

    $technicianOnPhoto = $call('POST', '/api/restocker/visits/photo', [], $technicianToken);
    $assert('1.4 El técnico SAT no alcanza la evidencia del reponedor (403)', $technicianOnPhoto->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Registro de visita y cerrojo térmico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Registro y cerrojo térmico ---\n";

    $validVisitPayload = [
        'status' => 'COMPLETED',
        'cabin_temp_celsius' => 3.0,
        'client_mutation_id' => 'api-rvf09-0001',
        'hygiene_checklist_json' => [
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [],
    ];

    $registered = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', $validVisitPayload, $ownerToken);
    $registeredData = $data($registered);
    $assert(
        '2.1 Una visita COMPLETED válida responde 201 y sin costes',
        $registered->getStatusCode() === 201
            && (int)($registeredData['id'] ?? 0) > 0
            && ($registeredData['status'] ?? '') === 'COMPLETED'
            && !in_array('unit_cost_snapshot_eur', $collectKeys($registeredData), true)
            && !in_array('commercial_spoilage_loss_eur', $collectKeys($registeredData), true),
        $errorCode($registered)
    );

    $repeated = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', $validVisitPayload, $ownerToken);
    $repeatedData = $data($repeated);
    $visitCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM machine_restock_visits WHERE client_mutation_id = 'api-rvf09-0001'"
    )->fetchColumn();
    $assert(
        '2.2 El reenvío con el mismo client_mutation_id devuelve la misma visita sin duplicar',
        $repeated->getStatusCode() === 201
            && (int)($repeatedData['id'] ?? 0) === (int)($registeredData['id'] ?? -1)
            && $visitCount === 1
    );

    $tooWarm = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/visits',
        array_merge($validVisitPayload, [
            'cabin_temp_celsius' => 6.5,
            'client_mutation_id' => 'api-rvf09-0002',
        ]),
        $ownerToken
    );
    $assert(
        '2.3 Una visita COMPLETED por encima de 4,0 °C responde 422 CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        $tooWarm->getStatusCode() === 422
            && $errorCode($tooWarm) === 'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        $errorCode($tooWarm)
    );

    $outside = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/visits',
        array_merge($validVisitPayload, ['client_mutation_id' => 'api-rvf09-0003']),
        $otherToken
    );
    $assert(
        '2.4 Un reponedor fuera de la zona recibe 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $outside->getStatusCode() === 403 && $errorCode($outside) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $errorCode($outside)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Historial móvil y bootstrap sin costes
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Historial móvil y bootstrap ---\n";

    $restockerList = $call('GET', '/api/restocker/visits', [], $ownerToken, ['machine_id' => $machineId]);
    $restockerListData = $data($restockerList);
    $assert(
        '3.1 El reponedor ve sus visitas y ninguna clave de coste',
        $restockerList->getStatusCode() === 200
            && count($restockerListData['visits'] ?? []) === 2
            && !in_array('unit_cost_snapshot_eur', $collectKeys($restockerListData), true)
            && !in_array('commercial_spoilage_loss_eur', $collectKeys($restockerListData), true)
    );

    $invalidStatus = $call('GET', '/api/restocker/visits', [], $ownerToken, ['status' => 'NO_EXISTE']);
    $assert(
        '3.2 Un estado desconocido en el filtro responde 400 VALIDATION_ERROR',
        $invalidStatus->getStatusCode() === 400 && $errorCode($invalidStatus) === 'VALIDATION_ERROR'
    );

    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteData = $data($myRoute);
    $planogram = $call('GET', '/api/restocker/machines/' . $machineId . '/planogram', [], $ownerToken);
    $planogramSlots = array_column($data($planogram)['slots'] ?? [], null, 'slot_code');
    $vanBatches = $planogramSlots['11']['available_van_batches'] ?? [];
    $assert(
        '3.3 El planograma del reponedor recibe los lotes FEFO de su furgoneta',
        $planogram->getStatusCode() === 200
            && count($vanBatches) === 1
            && ($vanBatches[0]['batch_code'] ?? '') === 'RVF9-VAN'
            && (int)($vanBatches[0]['quantity_available'] ?? 0) === 2
    );

    $assert(
        '3.4 El bootstrap incluye recent_visits sin costes',
        $myRoute->getStatusCode() === 200
            && isset($myRouteData['recent_visits'])
            && count($myRouteData['recent_visits']) >= 2
            && !in_array('unit_cost_snapshot_eur', $collectKeys($myRouteData['recent_visits']), true)
            && !in_array('commercial_spoilage_loss_eur', $collectKeys($myRouteData['recent_visits']), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Libro de Coordinación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Libro histórico de Coordinación ---\n";

    $coordinatorList = $call('GET', '/api/coordinator/restock-visits', [], $coordinatorToken, [
        'restocker_id' => $ownerId,
        'status' => 'COMPLETED',
    ]);
    $coordinatorListData = $data($coordinatorList);
    $coordinatorVisits = $coordinatorListData['visits'] ?? [];
    $assert(
        '4.1 Coordinación recibe el libro y los cinco KPIs',
        $coordinatorList->getStatusCode() === 200
            && isset(
                $coordinatorListData['kpis']['completed_visits_count'],
                $coordinatorListData['kpis']['aborted_thermal_visits_count'],
                $coordinatorListData['kpis']['total_units_added'],
                $coordinatorListData['kpis']['total_units_withdrawn'],
                $coordinatorListData['kpis']['total_commercial_spoilage_loss_eur']
            )
            && count($coordinatorVisits) === 2
    );

    $seedVisitRow = null;
    foreach ($coordinatorVisits as $visitRow) {
        if ((int)($visitRow['id'] ?? 0) === $seedVisitId) {
            $seedVisitRow = $visitRow;
            break;
        }
    }
    $assert(
        '4.2 La visita sembrada publica la merma comercial agregada (QA 3)',
        is_array($seedVisitRow)
            && (float)($seedVisitRow['commercial_spoilage_loss_eur'] ?? -1) === 1.25
            && (int)($seedVisitRow['units_added'] ?? 0) === 3
            && (int)($seedVisitRow['units_withdrawn'] ?? 0) === 1
    );

    $coordinatorDetail = $call('GET', '/api/coordinator/restock-visits/' . $seedVisitId, [], $coordinatorToken);
    $coordinatorDetailData = $data($coordinatorDetail);
    $detailItems = $coordinatorDetailData['items'] ?? [];
    $assert(
        '4.3 El expediente de Coordinación incluye el snapshot contable por carril',
        $coordinatorDetail->getStatusCode() === 200
            && count($detailItems) === 1
            && (float)($detailItems[0]['unit_cost_snapshot_eur'] ?? -1) === 1.25
            && (float)($detailItems[0]['commercial_spoilage_loss_eur'] ?? -1) === 1.25
            && (float)($coordinatorDetailData['commercial_spoilage_loss_eur'] ?? -1) === 1.25
    );

    $missingVisit = $call('GET', '/api/coordinator/restock-visits/999999', [], $coordinatorToken);
    $orderDetail = $call('GET', '/api/coordinator/product-loading-orders/' . $loadingOrderId, [], $coordinatorToken);
    $orderItems = $data($orderDetail)['items'] ?? [];
    $assert(
        '4.4 El detalle de la orden confirmada recibe el pre-cálculo C4 por lote',
        $orderDetail->getStatusCode() === 200
            && (int)($orderItems[0]['suggested_restocked_in_machines_qty'] ?? -1) === 3
            && (int)($orderItems[0]['suggested_withdrawn_spoilage_qty'] ?? -1) === 0
    );

    $assert(
        '4.5 Una visita inexistente responde 404 RESTOCK_VISIT_NOT_FOUND',
        $missingVisit->getStatusCode() === 404 && $errorCode($missingVisit) === 'RESTOCK_VISIT_NOT_FOUND'
    );

    $invalidCoordinatorStatus = $call('GET', '/api/coordinator/restock-visits', [], $coordinatorToken, [
        'status' => 'NO_EXISTE',
    ]);
    $assert(
        '4.6 Un filtro inválido de Coordinación responde 400 VALIDATION_ERROR',
        $invalidCoordinatorStatus->getStatusCode() === 400
            && $errorCode($invalidCoordinatorStatus) === 'VALIDATION_ERROR'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Evidencia fotográfica y conflicto offline
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Evidencia y conflicto offline ---\n";

    $pngPath = tempnam(sys_get_temp_dir(), 'rvf09_png_');
    $pngBinary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    file_put_contents($pngPath, $pngBinary);

    $photo = $call('POST', '/api/restocker/visits/photo', [], $ownerToken, [], [
        'photo' => [
            'name' => 'evidence.png',
            'tmp_name' => $pngPath,
            'size' => filesize($pngPath),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ],
    ]);
    $photoData = $data($photo);
    $photoUrl = (string)($photoData['photo_evidence_url'] ?? '');
    $photoDiskPath = $projectRoot . '/public' . $photoUrl;
    if ($photoUrl !== '') {
        $uploadedFiles[] = $photoDiskPath;
    }
    $assert(
        '5.1 La evidencia multipart se valida por magic bytes y devuelve /uploads/',
        $photo->getStatusCode() === 201
            && str_starts_with($photoUrl, '/uploads/')
            && is_file($photoDiskPath)
    );

    $invalidPath = tempnam(sys_get_temp_dir(), 'rvf09_txt_');
    file_put_contents($invalidPath, 'esto no es una imagen');
    $invalidPhoto = $call('POST', '/api/restocker/visits/photo', [], $ownerToken, [], [
        'photo' => [
            'name' => 'evidence.png',
            'tmp_name' => $invalidPath,
            'size' => filesize($invalidPath),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ],
    ]);
    $assert(
        '5.2 Un archivo sin firma de imagen responde 422 INVALID_FILE_TYPE',
        $invalidPhoto->getStatusCode() === 422 && $errorCode($invalidPhoto) === 'INVALID_FILE_TYPE',
        $errorCode($invalidPhoto)
    );

    $base64Photo = $call('POST', '/api/restocker/visits/photo', [
        'photo_base64' => 'data:image/png;base64,' . base64_encode($pngBinary),
    ], $ownerToken);
    $base64Data = $data($base64Photo);
    $base64Url = (string)($base64Data['photo_evidence_url'] ?? '');
    if ($base64Url !== '') {
        $uploadedFiles[] = $projectRoot . '/public' . $base64Url;
    }
    $assert(
        '5.3 La evidencia base64 segura también se valida y almacena',
        $base64Photo->getStatusCode() === 201 && str_starts_with($base64Url, '/uploads/')
    );

    $conflict = $call('POST', '/api/restocker/visits/conflict-dump', [
        'machine_id' => $machineId,
        'client_mutation_id' => 'offline-rvf09-0001',
        'reason' => 'La máquina entró en cuarentena antes de sincronizar.',
    ], $ownerToken);
    $conflictData = $data($conflict);
    $auditCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP'"
    )->fetchColumn();
    $assert(
        '5.4 El volcado offline se archiva en auditoría y confirma ARCHIVED',
        $conflict->getStatusCode() === 200
            && ($conflictData['status'] ?? '') === 'ARCHIVED'
            && $auditCount === 1,
        $errorCode($conflict)
    );

    // ─────────────────────────────────────────────────────────────────────
    // RESUMEN
    // ─────────────────────────────────────────────────────────────────────
    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-RVF-09 (API de visitas in situ) CUMPLIDA.\n";
        echo "======================================================================\n";
        $exitCode = 0;
    } else {
        echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
        echo "======================================================================\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[ERROR] " . $exception::class . ': ' . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    foreach ($uploadedFiles as $uploadedFile) {
        if (is_string($uploadedFile) && is_file($uploadedFile)) {
            @unlink($uploadedFile);
        }
    }
    if (isset($pngPath) && is_string($pngPath) && is_file($pngPath)) {
        @unlink($pngPath);
    }
    if (isset($invalidPath) && is_string($invalidPath) && is_file($invalidPath)) {
        @unlink($invalidPath);
    }
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, RVF09_API_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

exit($exitCode);
