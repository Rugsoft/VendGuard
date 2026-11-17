<?php

declare(strict_types=1);

/**
 * CashCollectionVaultAuditApiTest · T-CCV-08
 *
 * Certifica la superficie HTTP del Módulo 35 sobre el router real, el middleware
 * de rol, los controladores, el servicio de recaudación y los repositorios PDO:
 *
 *   1. RBAC de las dos superficies: sin token 401; Coordinación fuera de la
 *      ruta móvil y el reponedor fuera de Caja Central; técnico SAT fuera.
 *   2. Captura independiente en ruta, idempotencia, precinto global y proyección
 *      de Arqueo Ciego (`C1`, `C2`, `D1`, `D4`, `Art. V.4`).
 *   3. Recibo limpio de conserjería, historial y volcado de conflicto offline.
 *   4. Bootstrap `/my-route` con flags de conserjería, historial y reintegros
 *      elegibles sin PII ni `pickup_pin` (`D3`, `QA 3`, `Art. V.4`).
 *   5. Libro de Coordinación, 6 KPIs, detalle financiero, recepción en cámara,
 *      arqueo ciego, recibo y exportación CSV (`QA 3`, `QA 4`).
 *   6. Resumen `cash_collection_summary` en el detalle de visita del Módulo 34.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/CashCollectionVaultAuditApiTest.php
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

const CCV08_API_SCHEMA = 't_ccv08_api';

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
echo " VENDGUARD · T-CCV-08 · API de recaudación, cambio y arqueo de caja\n";
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
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, CCV08_API_SCHEMA);
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
            ':email' => 'ccv08.' . $suffix . '@vendguard.internal',
            ':email_key' => 'ccv08.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('ccv08-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'CCV8-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-CCV-08');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-CCV-08');
    $otherId = $createUser('other', 'RESTOCKER', 'Reposición Ajena T-CCV-08');
    $inactiveRestockerId = $createUser('inactive', 'RESTOCKER', 'Reposición Inactiva T-CCV-08');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-CCV-08');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-CCV8', 'Zona de certificación T-CCV-08', '#2560ff')
         RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id, TRUE)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-CCV8',
        ':site_code_key' => 'sede-ccv8',
        ':name' => 'Sede de certificación T-CCV-08',
        ':address' => 'Carrer de la Recaptació 8, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, 'SNACKS', :floor_wing)
         RETURNING id"
    );
    $machineStmt->execute([
        ':location_id' => $locationId,
        ':code' => 'CCV8-SNACK-01',
        ':model' => 'Modelo de certificación CCV8',
        ':floor_wing' => 'Planta baja',
    ]);
    $machineId = (int)$machineStmt->fetchColumn();

    $assignStmt = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assignStmt->execute([':restocker_id' => $ownerId, ':zone_id' => $zoneId]);
    $assignStmt->execute([':restocker_id' => $otherId, ':zone_id' => $zoneId]);
    $assignStmt->execute([':restocker_id' => $inactiveRestockerId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'UPDATE restocker_zone_assignments
            SET removed_at = CURRENT_TIMESTAMP
          WHERE restocker_id = :restocker_id AND is_primary = TRUE'
    )->execute([':restocker_id' => $otherId]);

    $refundStmt = $pdo->prepare(
        "INSERT INTO refund_requests
             (machine_id, location_id, claimant_name, claimant_contact, claimed_amount,
              compensation_method, tracking_token, status)
         VALUES (:machine_id, :location_id, 'Reclamante de prueba', 'ccv08@example.test', 5.00,
                 'EN_MANO_SEDE', :tracking_token, 'PENDING_INSPECTION')
         RETURNING id"
    );
    $refundStmt->execute([
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':tracking_token' => 'ccv08-refund-token-0001',
    ]);
    $refundId = (int)$refundStmt->fetchColumn();

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
    $otherToken = $authService->generateInternalToken($userRepo->findById($otherId));
    $inactiveRestockerToken = $authService->generateInternalToken($userRepo->findById($inactiveRestockerId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));

    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $inactiveRestockerId]);
    PdoUserRepository::clearCache();

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

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Guardas RBAC de las dos superficies ---\n";

    $anonymous = $call('GET', '/api/restocker/cash-collections');
    $assert(
        '1.1 Sin token, el historial de recaudaciones responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );

    $coordinatorOnMobile = $call('GET', '/api/restocker/cash-collections', [], $coordinatorToken);
    $assert(
        '1.2 Coordinación no entra en la superficie móvil (403 FORBIDDEN)',
        $coordinatorOnMobile->getStatusCode() === 403 && $errorCode($coordinatorOnMobile) === 'FORBIDDEN'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/cash-collections', [], $ownerToken);
    $assert(
        '1.3 El reponedor no entra en el libro de Caja Central (403 FORBIDDEN)',
        $restockerOnCoordinator->getStatusCode() === 403 && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );

    $technicianOnCash = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', [], $technicianToken);
    $assert('1.4 El técnico SAT no alcanza la captura del reponedor (403)', $technicianOnCash->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Bootstrap móvil con conserjería e historial
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Bootstrap móvil de efectivo ---\n";

    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteData = $data($myRoute);
    $routeMachine = $myRouteData['machines'][0] ?? [];
    $assert(
        '2.1 La máquina declara conserjería física y aún no tiene historial',
        $myRoute->getStatusCode() === 200
            && ($routeMachine['has_physical_reception'] ?? null) === true
            && ($routeMachine['has_prior_cash_collection'] ?? null) === false
    );

    $routeRefunds = $routeMachine['pending_reception_refunds'] ?? [];
    $assert(
        '2.2 El bootstrap expone el reintegro EN_MANO_SEDE sin PII ni pickup_pin',
        count($routeRefunds) === 1
            && (int)($routeRefunds[0]['id'] ?? 0) === $refundId
            && !in_array('pickup_pin', $collectKeys($routeRefunds), true)
            && !in_array('claimant_contact', $collectKeys($routeRefunds), true)
            && !in_array('claimant_name', $collectKeys($routeRefunds), true)
    );

    $assert(
        '2.3 El bootstrap incluye los contenedores de efectivo de la ruta',
        array_key_exists('recent_cash_collections', $myRouteData)
            && array_key_exists('cash_route_summary', $myRouteData)
            && array_key_exists('pending_reception_refunds', $myRouteData)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Captura independiente, idempotencia y precinto
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Captura en ruta y Arqueo Ciego ---\n";

    $capturePayload = [
        'client_mutation_id' => 'ccv08-cash-0001',
        'bag_seal_code' => 'bag-ccv8-0001',
        'sales_counter_reading' => 120.00,
        'change_tubes_loaded_eur' => 10.00,
        'reception_cash_delivered_eur' => 5.00,
        'receptionist_name' => 'Conserje T-CCV-08',
        'linked_refund_request_id' => $refundId,
        'route_notes' => 'Bolsa precintada y entrega parcial en conserjería.',
    ];

    $created = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', $capturePayload, $ownerToken);
    $createdData = $data($created);
    $collectionId = (int)($createdData['id'] ?? 0);
    $assert(
        '3.1 La captura responde 201 con el estado de ruta y sin datos privados',
        $created->getStatusCode() === 201
            && $collectionId > 0
            && ($createdData['status'] ?? '') === 'SEALED_IN_ROUTE'
            && ($createdData['idempotent_replay'] ?? null) === false
            && !in_array('expected_cashbox_eur', $collectKeys($createdData), true)
            && !in_array('previous_sales_counter_reading', $collectKeys($createdData), true)
            && !in_array('vault_counted_eur', $collectKeys($createdData), true)
            && !in_array('discrepancy_eur', $collectKeys($createdData), true)
            && !in_array('discrepancy_justification', $collectKeys($createdData), true)
    );

    $repeated = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', $capturePayload, $ownerToken);
    $repeatedData = $data($repeated);
    $assert(
        '3.2 El reenvío idempotente devuelve la misma recaudación',
        $repeated->getStatusCode() === 200
            && (int)($repeatedData['id'] ?? 0) === $collectionId
            && ($repeatedData['idempotent_replay'] ?? false) === true
    );

    $regression = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-regression-0001',
            'bag_seal_code' => 'BAG-CCV8-0008',
            'sales_counter_reading' => 100.00,
            'reception_cash_delivered_eur' => 0.00,
            'receptionist_name' => null,
            'linked_refund_request_id' => null,
        ]),
        $ownerToken
    );
    $assert(
        '3.2b Una lectura de contador inferior responde 422 SALES_COUNTER_REGRESSION',
        $regression->getStatusCode() === 422
            && $errorCode($regression) === 'SALES_COUNTER_REGRESSION',
        $errorCode($regression)
    );

    $duplicateSeal = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-0002',
            'bag_seal_code' => 'BAG-CCV8-0001',
        ]),
        $ownerToken
    );
    $assert(
        '3.3 Un precinto ya consumido responde 409 BAG_SEAL_CODE_ALREADY_USED',
        $duplicateSeal->getStatusCode() === 409
            && $errorCode($duplicateSeal) === 'BAG_SEAL_CODE_ALREADY_USED',
        $errorCode($duplicateSeal)
    );

    $outside = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', array_merge($capturePayload, [
        'client_mutation_id' => 'ccv08-cash-0003',
        'bag_seal_code' => 'BAG-CCV8-0003',
    ]), $otherToken);
    $assert(
        '3.4 Un reponedor fuera de la zona recibe 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $outside->getStatusCode() === 403 && $errorCode($outside) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $errorCode($outside)
    );

    $thermal = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', [
        'status' => 'ABORTED_THERMAL_BREACH',
        'client_mutation_id' => 'ccv08-visit-thermal-0001',
        'cabin_temp_celsius' => 9.5,
        'cash_collection' => [
            'client_mutation_id' => 'ccv08-thermal-cash-0001',
            'bag_seal_code' => 'BAG-CCV8-0009',
            'sales_counter_reading' => 130.00,
        ],
    ], $ownerToken);
    $assert(
        '3.4b Una visita abortada por frío con recaudación responde 422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN',
        $thermal->getStatusCode() === 422
            && $errorCode($thermal) === 'THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN',
        $errorCode($thermal)
    );

    $pdo->prepare('UPDATE locations SET has_physical_reception = FALSE WHERE id = :id')
        ->execute([':id' => $locationId]);
    $noReception = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-no-reception-0001',
            'bag_seal_code' => 'BAG-CCV8-0010',
            'sales_counter_reading' => 130.00,
            'reception_cash_delivered_eur' => 1.00,
            'linked_refund_request_id' => null,
        ]),
        $ownerToken
    );
    $pdo->prepare('UPDATE locations SET has_physical_reception = TRUE WHERE id = :id')
        ->execute([':id' => $locationId]);
    $assert(
        '3.4c Una sede sin conserjería física responde 409 LOCATION_HAS_NO_PHYSICAL_RECEPTION',
        $noReception->getStatusCode() === 409
            && $errorCode($noReception) === 'LOCATION_HAS_NO_PHYSICAL_RECEPTION',
        $errorCode($noReception)
    );

    $countCollections = static fn () => (int)$pdo->query('SELECT COUNT(*) FROM machine_cash_collections')->fetchColumn();
    $beforeActivityChecksCount = $countCollections();

    $pdo->prepare('UPDATE operational_zones SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $zoneId]);
    $inactiveZone = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-inactive-zone-0001',
            'bag_seal_code' => 'BAG-CCV8-0011',
            'sales_counter_reading' => 130.00,
            'reception_cash_delivered_eur' => 0.00,
            'receptionist_name' => null,
            'linked_refund_request_id' => null,
        ]),
        $ownerToken
    );
    $pdo->prepare('UPDATE operational_zones SET is_active = TRUE WHERE id = :id')
        ->execute([':id' => $zoneId]);
    $assert(
        '3.4d Una zona operativa inactiva responde 403 MACHINE_OUTSIDE_RESTOCKER_ZONES sin insertar fila (RF-02.1)',
        $inactiveZone->getStatusCode() === 403
            && $errorCode($inactiveZone) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
            && $countCollections() === $beforeActivityChecksCount,
        $errorCode($inactiveZone)
    );

    $pdo->prepare('UPDATE machines SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $machineId]);
    $inactiveMachine = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-inactive-machine-0001',
            'bag_seal_code' => 'BAG-CCV8-0012',
            'sales_counter_reading' => 130.00,
            'reception_cash_delivered_eur' => 0.00,
            'receptionist_name' => null,
            'linked_refund_request_id' => null,
        ]),
        $ownerToken
    );
    $pdo->prepare('UPDATE machines SET is_active = TRUE WHERE id = :id')
        ->execute([':id' => $machineId]);
    $assert(
        '3.4e Una máquina inactiva responde 404 MACHINE_NOT_FOUND sin insertar fila (RF-02.1)',
        $inactiveMachine->getStatusCode() === 404
            && $errorCode($inactiveMachine) === 'MACHINE_NOT_FOUND'
            && $countCollections() === $beforeActivityChecksCount,
        $errorCode($inactiveMachine)
    );

    $inactiveUser = $call(
        'POST',
        '/api/restocker/machines/' . $machineId . '/cash-collections',
        array_merge($capturePayload, [
            'client_mutation_id' => 'ccv08-cash-inactive-user-0001',
            'bag_seal_code' => 'BAG-CCV8-0013',
            'sales_counter_reading' => 130.00,
            'reception_cash_delivered_eur' => 0.00,
            'receptionist_name' => null,
            'linked_refund_request_id' => null,
        ]),
        $inactiveRestockerToken
    );
    $assert(
        '3.4f Un usuario RESTOCKER inactivo recibe 401 UNAUTHORIZED sin insertar fila (RF-02.1)',
        $inactiveUser->getStatusCode() === 401
            && $errorCode($inactiveUser) === 'UNAUTHORIZED'
            && $countCollections() === $beforeActivityChecksCount,
        $errorCode($inactiveUser)
    );

    $receipt = $call('GET', '/api/restocker/cash-collections/' . $collectionId . '/reception-receipt', [], $ownerToken);
    $receiptData = $data($receipt);
    $receiptKeys = $collectKeys($receiptData);
    $assert(
        '3.5 El recibo de conserjería es limpio y no filtra la bolsa ni el PIN',
        $receipt->getStatusCode() === 200
            && (int)($receiptData['linked_refund_request_id'] ?? 0) === $refundId
            && !in_array('bag_seal_code', $receiptKeys, true)
            && !in_array('pickup_pin', $receiptKeys, true)
            && !in_array('expected_cashbox_eur', $receiptKeys, true)
    );

    $history = $call('GET', '/api/restocker/cash-collections', [], $ownerToken, ['machine_id' => $machineId]);
    $historyData = $data($history);
    $assert(
        '3.6 El historial filtra por máquina y conserva el Arqueo Ciego',
        $history->getStatusCode() === 200
            && count($historyData['cash_collections'] ?? []) === 1
            && !in_array('expected_cashbox_eur', $collectKeys($historyData), true)
            && !in_array('previous_sales_counter_reading', $collectKeys($historyData), true)
            && !in_array('vault_counted_eur', $collectKeys($historyData), true)
            && !in_array('discrepancy_eur', $collectKeys($historyData), true)
            && !in_array('discrepancy_justification', $collectKeys($historyData), true)
    );

    $conflict = $call('POST', '/api/restocker/cash-collections/conflict-dump', [
        'machine_id' => $machineId,
        'client_mutation_id' => 'ccv08-cash-conflict-0001',
        'bag_seal_code' => 'BAG-CCV8-0001',
        'reason' => 'La captura offline llegó después de consumirse el precinto.',
    ], $ownerToken);
    $conflictData = $data($conflict);
    $assert(
        '3.7 El conflicto offline se archiva en auditoría',
        $conflict->getStatusCode() === 201
            && ($conflictData['logged'] ?? false) === true
            && ($conflictData['action'] ?? '') === 'OFFLINE_CASH_COLLECTION_CONFLICT_DUMP'
    );

    $myRouteAfter = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $routeAfterData = $data($myRouteAfter);
    $assert(
        '3.8 El bootstrap refleja el historial y la recaudación reciente',
        ($routeAfterData['machines'][0]['has_prior_cash_collection'] ?? null) === true
            && count($routeAfterData['recent_cash_collections'] ?? []) === 1
            && (int)($routeAfterData['cash_route_summary']['total_collections_count'] ?? 0) === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Visita in situ con recaudación y resumen en el detalle
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Visita in situ y resumen de recaudación ---\n";

    $visitPayload = [
        'status' => 'COMPLETED',
        'client_mutation_id' => 'ccv08-visit-0001',
        'cabin_temp_celsius' => 3.0,
        'hygiene_checklist_json' => [
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [],
        'cash_collection' => [
            'client_mutation_id' => 'ccv08-visit-cash-0001',
            'bag_seal_code' => 'BAG-CCV8-0002',
            'sales_counter_reading' => 140.00,
            'change_tubes_loaded_eur' => 5.00,
        ],
    ];

    $visit = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', $visitPayload, $ownerToken);
    $visitData = $data($visit);
    $visitId = (int)($visitData['id'] ?? 0);
    $assert(
        '4.1 La visita COMPLETED registra su recaudación en la misma transacción',
        $visit->getStatusCode() === 201
            && $visitId > 0
            && (int)($visitData['cash_collection']['restock_visit_id'] ?? 0) === $visitId
            && ($visitData['cash_collection']['status'] ?? '') === 'SEALED_IN_ROUTE'
    );

    $detail = $call('GET', '/api/coordinator/restock-visits/' . $visitId, [], $coordinatorToken);
    $detailData = $data($detail);
    $assert(
        '4.2 El detalle de visita publica cash_collection_summary sin campos privados',
        $detail->getStatusCode() === 200
            && (int)($detailData['cash_collection_summary']['id'] ?? 0) > 0
            && !in_array('expected_cashbox_eur', $collectKeys($detailData['cash_collection_summary'] ?? []), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Libro, KPIs, detalle, cámara, arqueo y CSV
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Caja Central y exportación ---\n";

    $book = $call('GET', '/api/coordinator/cash-collections', [], $coordinatorToken, ['machine_id' => $machineId]);
    $bookData = $data($book);
    $assert(
        '5.1 El libro lista las dos recaudaciones con los 6 KPIs',
        $book->getStatusCode() === 200
            && count($bookData['cash_collections'] ?? []) === 2
            && count($bookData['kpis'] ?? []) === 6
            && array_key_exists('pending_vault_bags_count', $bookData['kpis'] ?? [])
    );

    $coordinatorDetail = $call('GET', '/api/coordinator/cash-collections/' . $collectionId, [], $coordinatorToken);
    $coordinatorDetailData = $data($coordinatorDetail);
    $assert(
        '5.2 El detalle de Caja Central publica el esperado del arqueo ciego',
        $coordinatorDetail->getStatusCode() === 200
            && array_key_exists('expected_cashbox_eur', $coordinatorDetailData)
            && (float)($coordinatorDetailData['expected_cashbox_eur'] ?? 0) === 120.00
    );

    $delivered = $call('POST', '/api/coordinator/cash-collections/' . $collectionId . '/deliver-to-vault', [], $coordinatorToken);
    $assert(
        '5.3 La recepción en cámara sella DELIVERED_TO_VAULT',
        $delivered->getStatusCode() === 200
            && (($data($delivered)['status'] ?? '') === 'DELIVERED_TO_VAULT')
    );

    $reconciled = $call('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordinatorToken);
    $assert(
        '5.4 El arqueo cuadrado sella RECONCILED_OK',
        $reconciled->getStatusCode() === 200
            && (($data($reconciled)['status'] ?? '') === 'RECONCILED_OK')
            && (float)($data($reconciled)['discrepancy_eur'] ?? 1) === 0.0
    );

    $alreadyReconciled = $call('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordinatorToken);
    $assert(
        '5.5 Un segundo arqueo responde 409 CASH_COLLECTION_ALREADY_RECONCILED',
        $alreadyReconciled->getStatusCode() === 409
            && $errorCode($alreadyReconciled) === 'CASH_COLLECTION_ALREADY_RECONCILED'
    );

    $coordinatorReceipt = $call('GET', '/api/coordinator/cash-collections/' . $collectionId . '/reception-receipt', [], $coordinatorToken);
    $assert(
        '5.6 Coordinación obtiene el mismo recibo limpio de conserjería',
        $coordinatorReceipt->getStatusCode() === 200
            && !in_array('bag_seal_code', $collectKeys($data($coordinatorReceipt)), true)
            && !in_array('pickup_pin', $collectKeys($data($coordinatorReceipt)), true)
    );

    $csv = $call('GET', '/api/coordinator/cash-collections/export-csv', [], $coordinatorToken, ['machine_id' => $machineId]);
    $assert(
        '5.7 La exportación CSV lleva BOM UTF-8, separador y cabecera completa',
        $csv->getStatusCode() === 200
            && str_starts_with($csv->getBody(), "\xEF\xBB\xBF")
            && str_contains($csv->getBody(), 'Código;"Fecha (Europe/Madrid)"')
            && str_contains((string)$csv->getHeader('Content-Type'), 'text/csv')
    );

    $assert(
        '5.8 El libro financiero responde con la envolvente JSON',
        str_contains((string)$book->getHeader('Content-Type'), 'application/json')
    );

    $visitCashId = (int)($visitData['cash_collection']['id'] ?? 0);
    $flagged = $call('POST', '/api/coordinator/cash-collections/' . $visitCashId . '/reconcile', [
        'vault_counted_eur' => 25.00,
        'discrepancy_justification' => '=HYPERLINK("http://evil.test","descuadre real de cinco euros")',
    ], $coordinatorToken);
    $assert(
        '5.9 El descuadre justificado se sella como DISCREPANCY_FLAGGED',
        $flagged->getStatusCode() === 200
            && (($data($flagged)['status'] ?? '') === 'DISCREPANCY_FLAGGED')
    );

    $csvFlagged = $call('GET', '/api/coordinator/cash-collections/export-csv', [], $coordinatorToken, ['machine_id' => $machineId]);
    $assert(
        '5.10 El CSV neutraliza la fórmula de la justificación',
        $csvFlagged->getStatusCode() === 200
            && str_contains($csvFlagged->getBody(), "'=HYPERLINK")
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5.11–5.14 · «Recepcionar y Arquear» en un solo paso (EARS 04.2, D4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5b. Arqueo directo desde SEALED_IN_ROUTE ---\n";

    $directCapture = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', [
        'client_mutation_id' => 'ccv08-cash-direct-0001',
        'bag_seal_code' => 'BAG-CCV8-0003',
        'sales_counter_reading' => 150.00,
        'change_tubes_loaded_eur' => 0.00,
    ], $ownerToken);
    $directId = (int)($data($directCapture)['id'] ?? 0);

    $directBefore = $call('GET', '/api/coordinator/cash-collections/' . $directId, [], $coordinatorToken);
    $directBeforeData = $data($directBefore);
    $directExpected = (float)($directBeforeData['expected_cashbox_eur'] ?? 0);

    $assert(
        '5.11 La bolsa arranca en SEALED_IN_ROUTE, sin sello de cámara y con esperado propio',
        $directCapture->getStatusCode() === 201
            && $directBefore->getStatusCode() === 200
            && ($directBeforeData['status'] ?? '') === 'SEALED_IN_ROUTE'
            && ($directBeforeData['delivered_to_vault_at'] ?? null) === null
            && ($directBeforeData['delivered_to_vault_by'] ?? null) === null
            && $directExpected > 0
    );

    $directReconcile = $call('POST', '/api/coordinator/cash-collections/' . $directId . '/reconcile', [
        'vault_counted_eur' => $directExpected,
    ], $coordinatorToken);
    $directReconciled = $data($directReconcile);

    $assert(
        '5.12 EARS 04.2: el arqueo directo desde SEALED_IN_ROUTE responde 200 y sella RECONCILED_OK',
        $directReconcile->getStatusCode() === 200
            && ($directReconciled['status'] ?? '') === 'RECONCILED_OK'
            && (float)($directReconciled['discrepancy_eur'] ?? 1) === 0.0
    );

    $deliveredAtTs = strtotime((string)($directReconciled['delivered_to_vault_at'] ?? ''));
    $reconciledAtTs = strtotime((string)($directReconciled['vault_reconciled_at'] ?? ''));

    $assert(
        '5.13 El arqueo directo sella a la vez delivered_to_vault_at y delivered_to_vault_by',
        $deliveredAtTs !== false
            && (int)($directReconciled['delivered_to_vault_by'] ?? 0) === $coordinatorId
            && $reconciledAtTs !== false
            && $reconciledAtTs >= $deliveredAtTs
    );

    $directAfter = $call('GET', '/api/coordinator/cash-collections/' . $directId, [], $coordinatorToken);
    $directAfterData = $data($directAfter);

    $assert(
        '5.14 El doble sello del arqueo directo persiste en la lectura posterior',
        $directAfter->getStatusCode() === 200
            && ($directAfterData['status'] ?? '') === 'RECONCILED_OK'
            && !empty($directAfterData['delivered_to_vault_at'])
            && (int)($directAfterData['delivered_to_vault_by'] ?? 0) === $coordinatorId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5.15–5.16 · Etiquetas legibles en el CSV (H-4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5c. CSV con etiquetas legibles ---\n";

    $csvBody = $csvFlagged->getBody();
    // `fputcsv` entrecomilla la cabecera nueva porque contiene espacios.
    $csvHeaderOk = str_contains($csvBody, 'Justificación;"Código de estado"');
    $csvLabelFlagged = str_contains($csvBody, 'Arqueada con descuadre');
    $csvLabelOk = str_contains($csvBody, 'Arqueada sin descuadre');
    $csvCodeFlagged = str_contains($csvBody, ';DISCREPANCY_FLAGGED');
    $csvCodeOk = str_contains($csvBody, ';RECONCILED_OK');

    $assert(
        '5.15 El CSV publica la etiqueta del estado como la consola y conserva el código técnico',
        $csvHeaderOk && $csvLabelFlagged && $csvLabelOk && $csvCodeFlagged && $csvCodeOk,
        sprintf(
            'header=%d label_flagged=%d label_ok=%d code_flagged=%d code_ok=%d',
            $csvHeaderOk,
            $csvLabelFlagged,
            $csvLabelOk,
            $csvCodeFlagged,
            $csvCodeOk
        )
    );

    $flaggedLine = '';
    foreach (explode("\n", $csvFlagged->getBody()) as $csvLine) {
        if (str_contains($csvLine, 'DISCREPANCY_FLAGGED')) {
            $flaggedLine = trim($csvLine);
        }
    }
    $flaggedCells = $flaggedLine === '' ? [] : str_getcsv($flaggedLine, ';', '"', '');

    $assert(
        '5.16 La etiqueta ocupa «Estado» y el código cierra la fila, sin mover las columnas de dinero',
        count($flaggedCells) === 12
            && ($flaggedCells[6] ?? '') === 'Arqueada con descuadre'
            && ($flaggedCells[9] ?? '') !== ''
            && ($flaggedCells[11] ?? '') === 'DISCREPANCY_FLAGGED'
    );

    $ok = $failures === 0;
    echo "\n======================================================================\n";
    echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
    if ($ok) {
        echo " RESULTADO: 100% EN VERDE. SUPERFICIE DE RECAUDACIÓN CERTIFICADA (T-CCV-08).\n";
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
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, CCV08_API_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

exit($exitCode);
