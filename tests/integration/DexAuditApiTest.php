<?php

declare(strict_types=1);

/**
 * DexAuditApiTest · T-DEX-09
 *
 * Certifica la superficie HTTP del Módulo 39 sobre el router real, el middleware
 * de rol, los controladores, el servicio y los repositorios PDO:
 *
 *   1. RBAC de las tres puertas: sin token 401; cada rol solo entra en la suya.
 *   2. Importación `.dex` del Reponedor (201, prefill inmediato) y listado
 *      histórico sin ningún importe en euros (`QA-1`, Art. V.4).
 *   3. Carga manual de Coordinación, listado con KPIs y detalle completo.
 *   4. `/raw` con `text/plain`, `nosniff` y `DEX-AAAA-NNNN.dex`.
 *   5. Anulación lógica con motivo real y `export-csv` UTF-8 BOM.
 *   6. Volcado de conflicto Offline auditado.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/DexAuditApiTest.php
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
const DEX09_API_SCHEMA = 't_dex09_api';

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
echo " VENDGUARD · T-DEX-09 · API REST de auditorías EVA-DTS (.dex)\n";
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
$exitCode = 1;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, DEX09_API_SCHEMA);
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
            ':email' => 'dex09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'dex09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('dex09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'DX9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación DEX 09');
    $restockerId = $createUser('repo', 'RESTOCKER', 'Reposición DEX 09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico DEX 09');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX9', 'Zona DEX 09', '#2560ff') RETURNING id"
    )->fetchColumn();
    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX9',
        ':site_code_key' => 'sede-dex9',
        ':name' => 'Sede DEX 09',
        ':address' => 'Carrer DEX 9, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();
    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, 'Modelo DEX 9', 'PERISHABLE_FOOD', 'Planta baja') RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-01']);
    $machineId = (int)$machineStmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    )->execute([':restocker_id' => $restockerId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:technician_id, :zone_id, TRUE)'
    )->execute([':technician_id' => $technicianId, ':zone_id' => $zoneId]);

    // Hechos M34/M35 reales para el cruce del detalle (QA-3, RF-04.3.3):
    // un carril A1, dos visitas COMPLETED del mismo día y dos recaudaciones.
    $productId = (int)$pdo->query(
        "INSERT INTO vending_products (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES ('DEX9-YOGUR', 'Yogur DEX 09', 'PERISHABLE_FOOD', 'UNIT', TRUE, 30, 1.00, 2.00) RETURNING id"
    )->fetchColumn();
    $slotStmt = $pdo->prepare(
        "INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty)
         VALUES (:machine_id, 'A1', :product_id, 20, 10) RETURNING id"
    );
    $slotStmt->execute([':machine_id' => $machineId, ':product_id' => $productId]);
    $slotId = (int)$slotStmt->fetchColumn();

    $visitStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, status, started_at, completed_at, cabin_temp_celsius, client_mutation_id)
         VALUES (:visit_code, :machine_id, :restocker_id, 'COMPLETED', :started_at, :completed_at, 4.0, :mutation) RETURNING id"
    );
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0001',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 06:30:00+00',
        ':completed_at' => '2026-11-24 07:00:00+00',
        ':mutation' => 'dex09-visit-0001',
    ]);
    $previousVisitId = (int)$visitStmt->fetchColumn();
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0002',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 08:00:00+00',
        ':completed_at' => '2026-11-24 08:30:00+00',
        ':mutation' => 'dex09-visit-0002',
    ]);
    $currentVisitId = (int)$visitStmt->fetchColumn();
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0003',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 09:15:00+00',
        ':completed_at' => '2026-11-24 09:45:00+00',
        ':mutation' => 'dex09-visit-0003',
    ]);
    $secondVisitId = (int)$visitStmt->fetchColumn();

    $itemStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visit_items (visit_id, slot_id, slot_code, product_id, qty_before, qty_added, qty_after, loaded_batch_code)
         VALUES (:visit_id, :slot_id, 'A1', :product_id, :qty_before, :qty_added, :qty_after, :loaded_batch)"
    );
    $itemStmt->execute([
        ':visit_id' => $previousVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 12,
        ':qty_added' => 0,
        ':qty_after' => 12,
        ':loaded_batch' => 'LOT-DEX9-001',
    ]);
    $itemStmt->execute([
        ':visit_id' => $currentVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 5,
        ':qty_added' => 10,
        ':qty_after' => 15,
        ':loaded_batch' => 'LOT-DEX9-002',
    ]);
    $itemStmt->execute([
        ':visit_id' => $secondVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 8,
        ':qty_added' => 0,
        ':qty_after' => 8,
        ':loaded_batch' => 'LOT-DEX9-003',
    ]);

    $cashStmt = $pdo->prepare(
        "INSERT INTO machine_cash_collections
         (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
          restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, sales_counter_reading,
          previous_sales_counter_reading, initial_baseline_reading_eur, expected_cashbox_eur,
          status, collected_at, delivered_to_vault_at, delivered_to_vault_by, vault_counted_eur,
          discrepancy_eur, vault_reconciled_by, vault_reconciled_by_name_snapshot, vault_reconciled_at,
          discrepancy_justification, client_mutation_id)
         VALUES ('MCC-2026-0001', :visit_id, :machine_id, 'DEX9-FOOD-01', :restocker_id,
          'Reposición DEX 09', 'DX9-REPO', 'BAG-2026-001', 9.00, 0.00, 0.00, 9.00,
          'DISCREPANCY_FLAGGED', '2026-11-24 09:30:00+00', '2026-11-24 11:00:00+00', :delivered_by, 10.50,
          1.50, :reconciled_by, 'Coordinación DEX 09', '2026-11-24 12:00:00+00',
          'Descuadre de prueba con tres bloques', :mutation) RETURNING id"
    );
    $cashStmt->execute([
        ':visit_id' => $currentVisitId,
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':delivered_by' => $coordinatorId,
        ':reconciled_by' => $coordinatorId,
        ':mutation' => 'dex09-cash-0001',
    ]);
    $cashCollection1Id = (int)$cashStmt->fetchColumn();
    $sealedStmt = $pdo->prepare(
        "INSERT INTO machine_cash_collections
         (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
          restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, sales_counter_reading,
          previous_sales_counter_reading, initial_baseline_reading_eur, expected_cashbox_eur,
          status, collected_at, client_mutation_id)
         VALUES ('MCC-2026-0002', NULL, :machine_id, 'DEX9-FOOD-01', :restocker_id,
          'Reposición DEX 09', 'DX9-REPO', 'BAG-2026-002', 9.00, 0.00, 0.00, 9.00,
          'SEALED_IN_ROUTE', '2026-11-24 09:45:00+00', :mutation) RETURNING id"
    );
    $sealedStmt->execute([
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':mutation' => 'dex09-cash-0002',
    ]);
    $cashCollection2Id = (int)$sealedStmt->fetchColumn();

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
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

    echo "\n--- 1. RBAC de las tres puertas ---\n";
    $anonymous = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits');
    $assert('1.1 Sin token responde 401 UNAUTHORIZED', $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED');
    $coordinatorOnMobile = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $coordinatorToken);
    $assert('1.2 Coordinación no entra en la puerta móvil (403)', $coordinatorOnMobile->getStatusCode() === 403);
    $restockerOnCoordinator = $call('GET', '/api/coordinator/dex-audits', [], $restockerToken);
    $assert('1.3 El Reponedor no entra en la consola de Coordinación (403)', $restockerOnCoordinator->getStatusCode() === 403);
    $technicianOnRestocker = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $technicianToken);
    $assert('1.4 El Técnico no entra en la puerta del Reponedor (403)', $technicianOnRestocker->getStatusCode() === 403);

    echo "\n--- 2. Importación y Arqueo Ciego del Reponedor (QA-1) ---\n";
    $dexPayload = "DXS*COMM-DEX09*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*1250*25\nCA3*900*0*0\nPA1*A1*150\nPA2*10*1250\nEA2*EG01*1*1*0*1\nDXE*FIN";
    $importPayload = [
        'raw_payload' => $dexPayload,
        'original_filename' => 'ruta.dex',
        'captured_at' => '2026-11-24 09:00:00+00',
        'client_mutation_id' => 'dex09-api-0001',
        'restock_visit_id' => $currentVisitId,
        'cash_collection_id' => $cashCollection1Id,
    ];
    $imported = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', $importPayload, $restockerToken);
    $importedData = $data($imported);
    $assert('2.1 La importación del Reponedor responde 201', $imported->getStatusCode() === 201, $errorCode($imported));
    $assert('2.2 El POST inmediato trae prefill VA1', ($importedData['prefill_sales_counter_reading_eur'] ?? null) === 12.50);
    $assert(
        '2.3 El POST no filtra CA3 ni euros por carril (Art. V.4)',
        $importedData !== []
            && !in_array('ca3_cashbox_eur', $collectKeys($importedData), true)
            && !in_array('total_sales_eur', $collectKeys($importedData), true)
    );

    $history = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $restockerToken);
    $historyData = $data($history);
    $historyAudits = $historyData['audits'] ?? [];
    $assert(
        '2.4 El histórico del Reponedor no contiene ningún importe en euros',
        $history->getStatusCode() === 200
            && $historyAudits !== []
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($historyData), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($historyData), true)
            && !in_array('total_sales_eur', $collectKeys($historyData), true)
            && !in_array('unit_price_eur', $collectKeys($historyData), true)
    );

    $duplicated = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => $dexPayload,
        'original_filename' => 'ruta-2.dex',
        'captured_at' => '2026-11-24 10:00:00+00',
        'client_mutation_id' => 'dex09-api-0002',
    ], $restockerToken);
    $assert('2.5 La misma huella responde 409 DEX_DUPLICATE_HASH', $duplicated->getStatusCode() === 409 && $errorCode($duplicated) === 'DEX_DUPLICATE_HASH');

    echo "\n--- 3. Consola de Coordinación, detalle y carga manual ---\n";
    $list = $call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['machine_id' => (string)$machineId]);
    $listData = $data($list);
    $assert(
        '3.1 El listado devuelve volcados y los cinco KPIs',
        $list->getStatusCode() === 200
            && count($listData['audits'] ?? []) === 1
            && isset($listData['kpis']['total_active'], $listData['kpis']['thermal_alert_count'], $listData['kpis']['warning_count'], $listData['kpis']['cash_deviation_count'], $listData['kpis']['voided_count'])
    );
    $invalidFilter = $call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'BORRADO']);
    $assert('3.1.b Un filtro status fuera del contrato responde 422', $invalidFilter->getStatusCode() === 422 && $errorCode($invalidFilter) === 'VALIDATION_ERROR');
    $auditId = (int)($listData['audits'][0]['id'] ?? 0);
    $detail = $call('GET', '/api/coordinator/dex-audits/' . $auditId, [], $coordinatorToken);
    $detailData = $data($detail);
    $assert(
        '3.2 El detalle completo conserva VA1/CA3 y PA1 sin raw_payload',
        $detail->getStatusCode() === 200
            && ($detailData['va1_total_sales_eur'] ?? null) === 12.50
            && (float)($detailData['ca3_cashbox_eur'] ?? 0) === 9.0
            && ($detailData['can_download_raw'] ?? false) === true
            && !in_array('raw_payload', $collectKeys($detailData), true),
        json_encode($detailData)
    );

    $manual = $call('POST', '/api/coordinator/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID4*2\nVA1*2000*40\nDXE*FIN",
        'original_filename' => 'consola.dex',
        'captured_at' => '2026-11-24 11:00:00+00',
        'client_mutation_id' => 'dex09-api-0003',
    ], $coordinatorToken);
    $assert('3.3 La carga manual de Coordinación responde 201', $manual->getStatusCode() === 201, $errorCode($manual));
    $assert('3.4 La consola recibe la vista completa', in_array('ca3_coin_tubes_eur', $collectKeys($data($manual)), true));

    $technicianImport = $call('POST', '/api/technician/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID4*2\nVA1*1500*30\nDXE*FIN",
        'original_filename' => 'sat.dex',
        'captured_at' => '2026-11-24 12:00:00+00',
        'client_mutation_id' => 'dex09-api-0004',
    ], $technicianToken);
    $assert(
        '3.5 El Técnico importa en su zona con 201 y sin prefill en euros',
        $technicianImport->getStatusCode() === 201
            && ($data($technicianImport)['audit']['id'] ?? 0) > 0
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($data($technicianImport)), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($data($technicianImport)), true),
        (string)$technicianImport->getStatusCode()
    );
    $technicianList = $call('GET', '/api/technician/machines/' . $machineId . '/dex-audits', [], $technicianToken);
    $assert(
        '3.6 El listado del Técnico no filtra euros (Art. V.4)',
        $technicianList->getStatusCode() === 200
            && count($data($technicianList)['audits'] ?? []) === 3
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($data($technicianList)), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($data($technicianList)), true)
    );

    echo "\n--- 3.7. Cruces M34/M35 del detalle de Coordinación (QA-3) ---\n";
    $assert(
        '3.7 El detalle compone M35 con prioridad CA3_VS_VAULT y umbral ±1,00 €',
        ($detailData['m35_cross_check']['cash_collection_id'] ?? 0) === $cashCollection1Id
            && ($detailData['m35_cross_check']['comparison_basis'] ?? '') === 'CA3_VS_VAULT'
            && ($detailData['m35_cross_check']['diff_eur'] ?? null) === 1.50
            && ($detailData['m35_cross_check']['is_deviation'] ?? false) === true,
        json_encode($detailData['m35_cross_check'] ?? null)
    );
    $assert(
        '3.8 El detalle compone el consumo observado M34 y deja la diferencia nula sin delta',
        ($detailData['m34_cross_check']['visit_id'] ?? 0) === $currentVisitId
            && ($detailData['m34_cross_check']['previous_visit_id'] ?? 0) === $previousVisitId
            && ($detailData['m34_cross_check']['lines'][0]['observed_consumption_units'] ?? null) === 7
            && array_key_exists('difference_units', $detailData['m34_cross_check']['lines'][0] ?? [])
            && $detailData['m34_cross_check']['lines'][0]['difference_units'] === null,
        json_encode($detailData['m34_cross_check'] ?? null)
    );

    $secondImport = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*2000*40\nPA1*A1*200\nPA2*16*2000\nDXE*FIN",
        'original_filename' => 'ruta-segunda.dex',
        'captured_at' => '2026-11-24 10:00:00+00',
        'client_mutation_id' => 'dex09-api-0005',
        'restock_visit_id' => $secondVisitId,
        'cash_collection_id' => $cashCollection2Id,
    ], $restockerToken);
    $secondAuditId = (int)($data($secondImport)['audit']['id'] ?? 0);
    $secondDetail = $call('GET', '/api/coordinator/dex-audits/' . $secondAuditId, [], $coordinatorToken);
    $secondData = $data($secondDetail);
    $assert(
        '3.9 La segunda carga cae a VA1_VS_EXPECTED y calcula la diferencia por carril',
        $secondAuditId > 0
            && ($secondData['m35_cross_check']['comparison_basis'] ?? '') === 'VA1_VS_EXPECTED'
            && ($secondData['m35_cross_check']['diff_eur'] ?? null) === 1.50
            && ($secondData['m35_cross_check']['is_deviation'] ?? false) === true
            && ($secondData['m34_cross_check']['lines'][0]['delta_vend_units'] ?? null) === 6
            && ($secondData['m34_cross_check']['lines'][0]['observed_consumption_units'] ?? null) === 7
            && ($secondData['m34_cross_check']['lines'][0]['difference_units'] ?? null) === -1,
        json_encode(['m35' => $secondData['m35_cross_check'] ?? null, 'm34' => $secondData['m34_cross_check'] ?? null])
    );

    echo "\n--- 4. Descarga segura del .dex original (QA-5) ---\n";
    $raw = $call('GET', '/api/coordinator/dex-audits/' . $auditId . '/raw', [], $coordinatorToken);
    $assert(
        '4.1 /raw responde text/plain con nosniff y filename DEX-AAAA-NNNN.dex',
        $raw->getStatusCode() === 200
            && $raw->getHeader('Content-Type') === 'text/plain; charset=utf-8'
            && $raw->getHeader('X-Content-Type-Options') === 'nosniff'
            && preg_match('/filename="DEX-\d{4}-\d{4}\.dex"/', (string)$raw->getHeader('Content-Disposition')) === 1
    );
    $assert('4.2 /raw entrega el fichero original', $raw->getBody() === $dexPayload);
    $missingRaw = $call('GET', '/api/coordinator/dex-audits/999999/raw', [], $coordinatorToken);
    $assert('4.3 Un volcado inexistente responde 404', $missingRaw->getStatusCode() === 404 && $errorCode($missingRaw) === 'DEX_AUDIT_NOT_FOUND');

    echo "\n--- 5. Anulación lógica y CSV ---\n";
    $void = $call('POST', '/api/coordinator/dex-audits/' . $auditId . '/void', [
        'void_reason' => 'Anulación por duplicado operativo en la ruta de certificación',
    ], $coordinatorToken);
    $voidData = $data($void);
    $assert('5.1 La anulación válida responde 200 y VOIDED', $void->getStatusCode() === 200 && ($voidData['status'] ?? '') === 'VOIDED');
    $again = $call('POST', '/api/coordinator/dex-audits/' . $auditId . '/void', [
        'void_reason' => 'Segundo intento de anulación del mismo volcado',
    ], $coordinatorToken);
    $assert('5.2 Una segunda anulación responde 409 DEX_AUDIT_ALREADY_VOIDED', $again->getStatusCode() === 409 && $errorCode($again) === 'DEX_AUDIT_ALREADY_VOIDED');
    $manualAuditId = (int)($data($manual)['audit']['id'] ?? 0);
    $shortVoid = $call('POST', '/api/coordinator/dex-audits/' . $manualAuditId . '/void', ['void_reason' => 'Corto'], $coordinatorToken);
    $assert('5.3 Un motivo corto responde 422 VOID_REASON_REQUIRED', $shortVoid->getStatusCode() === 422 && $errorCode($shortVoid) === 'VOID_REASON_REQUIRED');

    $csv = $call('GET', '/api/coordinator/dex-audits/export-csv', [], $coordinatorToken, ['machine_id' => (string)$machineId]);
    $assert(
        '5.4 El CSV lleva BOM UTF-8 y separador ;',
        $csv->getStatusCode() === 200
            && str_starts_with($csv->getBody(), "\xEF\xBB\xBF")
            && str_contains($csv->getBody(), 'audit_code;machine_code;captured_at')
    );

    echo "\n--- 6. Conflict-dump Offline ---\n";
    $dump = $call('POST', '/api/restocker/dex-audits/conflict-dump', [
        'machine_id' => $machineId,
        'reason' => 'DEX_DUPLICATE_HASH',
        'client_mutation_id' => 'dex09-api-conflict-0001',
    ], $restockerToken);
    $assert('6.1 El volcado de conflicto responde 200 CONFLICT_DUMPED', $dump->getStatusCode() === 200 && (($data($dump)['outcome_status'] ?? '') === 'CONFLICT_DUMPED'));
    $badDump = $call('POST', '/api/technician/dex-audits/conflict-dump', ['machine_id' => 0], $technicianToken);
    $assert('6.2 Un conflict-dump sin motivo responde 422', $badDump->getStatusCode() === 422);

    $exitCode = $failures === 0 ? 0 : 1;
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, DEX09_API_SCHEMA);
        } catch (Throwable $exception) {
            fwrite(STDERR, "\n[RESIDUO] No se pudo borrar el esquema: " . $exception->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. T-DEX-09 (API REST .dex) CERTIFICADO.\n"
    : " RESULTADO: FALLOS DETECTADOS EN LA API .DEX.\n";
echo "======================================================================\n";
exit($exitCode);
