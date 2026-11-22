<?php

declare(strict_types=1);

/**
 * RestockRoutePlannerApiTest · T-RRP-09
 *
 * Certifica la superficie HTTP del Módulo 37 sobre el router real, el middleware
 * de rol, los controladores, los servicios del planificador y los repositorios
 * PDO:
 *
 *   1. RBAC de las dos superficies: sin token 401; Coordinación fuera de la
 *      ruta móvil y el reponedor fuera del tablero de Coordinación; técnico SAT
 *      fuera de ambas.
 *   2. Agendas versionadas: listado con `has_schedule`, alta, versionado,
 *      coherencia de cadencia, lote atómico y baja lógica sin `DELETE`.
 *   3. Tablero «Ruta del Día»: contadores del único `service_date`, rutas por
 *      reponedor y bandeja de bloqueadas.
 *   4. Excepciones diarias: `SKIPPED`, `REASSIGNED`, revocación, conflicto de
 *      estado y prohibición de auto-desvío.
 *   5. Hoja móvil: `service_date` + `route_plan` opaco, omisión idempotente por
 *      `client_mutation_id`, perímetro del reponedor y volcado offline.
 *   6. Propuesta M33 con `TODAY_ROUTE` y `service_date`, incluida la exclusión
 *      de máquinas bloqueadas y el lote con `NO_DEMAND`.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/RestockRoutePlannerApiTest.php
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

const RRP09_API_SCHEMA = 't_rrp09_api';

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
echo " VENDGUARD · T-RRP-09 · API del Planificador de Rutas\n";
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

    PostgresFixtureBuilder::createIsolationSchema($pdo, RRP09_API_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('+1 day')
        ->format('Y-m-d');

    $createUser = static function (string $suffix, string $role, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'rrp09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rrp09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rrp09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RRP9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-RRP-09');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-RRP-09');
    $targetId = $createUser('target', 'RESTOCKER', 'Reposición Destino T-RRP-09');
    $outsiderId = $createUser('outsider', 'RESTOCKER', 'Reposición Ajena T-RRP-09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-RRP-09');

    $createZone = static function (string $code, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO operational_zones (code, name, color_hex)
             VALUES (:code, :name, '#2560ff')
             RETURNING id"
        );
        $stmt->execute([':code' => $code, ':name' => $name]);

        return (int)$stmt->fetchColumn();
    };

    $zoneA = $createZone('RRP9-A', 'Zona A T-RRP-09');
    $zoneB = $createZone('RRP9-B', 'Zona B T-RRP-09');
    $zoneC = $createZone('RRP9-C', 'Zona C T-RRP-09');

    $createLocation = static function (
        string $siteCode,
        string $name,
        int $zoneId,
        float $latitude,
        float $longitude
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, zone_id, has_physical_reception, latitude, longitude)
             VALUES (:site_code, :site_code_key, :name, :address, :zone_id, TRUE, :latitude, :longitude)
             RETURNING id'
        );
        $stmt->execute([
            ':site_code' => $siteCode,
            ':site_code_key' => strtolower($siteCode),
            ':name' => $name,
            ':address' => 'Carrer de Certificació 9, Barcelona',
            ':zone_id' => $zoneId,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $locationA = $createLocation('SEDE-RRP9-A', 'Sede A T-RRP-09', $zoneA, 41.4036299, 2.1743558);
    $locationB = $createLocation('SEDE-RRP9-B', 'Sede B T-RRP-09', $zoneB, 41.4100000, 2.1800000);
    $locationC = $createLocation('SEDE-RRP9-C', 'Sede C T-RRP-09', $zoneC, 41.4200000, 2.1900000);

    $createMachine = static function (
        int $locationId,
        string $code,
        string $sanitaryStatus = 'OK'
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
             VALUES (:location_id, :code, 'Modelo T-RRP-09', 'SNACKS', 'Planta baja', :sanitary_status)
             RETURNING id"
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':sanitary_status' => $sanitaryStatus,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $machineA = $createMachine($locationA, 'RRP9-SNACK-A');
    $machineBlocked = $createMachine($locationA, 'RRP9-SNACK-A2', 'QUARANTINE');
    $machineC = $createMachine($locationC, 'RRP9-SNACK-C');

    $assign = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assign->execute([':restocker_id' => $ownerId, ':zone_id' => $zoneA]);
    $assign->execute([':restocker_id' => $targetId, ':zone_id' => $zoneB]);
    $assign->execute([':restocker_id' => $outsiderId, ':zone_id' => $zoneC]);

    $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES (:id, :date, 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '2 hours')"
    )->execute([':id' => $ownerId, ':date' => $today]);

    $pdo->exec(
        "INSERT INTO route_settings (base_name, base_latitude, base_longitude)
         VALUES ('Base T-RRP-09', 41.3935, 2.1701)"
    );

    $productStmt = $pdo->prepare(
        "INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, unit_cost_eur, sale_price_eur)
         VALUES ('RRP9-SNACK-01', 'Barrita T-RRP-09', 'SNACK', 'UNIT', FALSE, 0.60, 1.50)
         RETURNING id"
    );
    $productStmt->execute();
    $productId = (int)$productStmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO machine_planogram_slots
             (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active)
         VALUES (:machine_id, :slot_code, :product_id, 12, 5, 2, TRUE)'
    )->execute([':machine_id' => $machineA, ':slot_code' => 'A1', ':product_id' => $productId]);

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
    $targetToken = $authService->generateInternalToken($userRepo->findById($targetId));
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

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Guardas RBAC de las dos superficies ---\n";

    $anonymous = $call('GET', '/api/coordinator/restock-route/day');
    $assert(
        '1.1 Sin token, el tablero responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/restock-route/day', [], $ownerToken);
    $assert(
        '1.2 El reponedor no entra en el tablero de Coordinación (403)',
        $restockerOnCoordinator->getStatusCode() === 403 && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );

    $coordinatorOnMobile = $call('GET', '/api/restocker/my-route', [], $coordinatorToken);
    $assert(
        '1.3 Coordinación no entra en la hoja móvil del reponedor (403)',
        $coordinatorOnMobile->getStatusCode() === 403
    );

    $technicianOnSkip = $call('POST', '/api/restocker/route/stops/' . $machineA . '/skip', [], $technicianToken);
    $assert('1.4 El técnico SAT no alcanza la omisión del reponedor (403)', $technicianOnSkip->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Agendas versionadas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Agendas versionadas, lote y baja lógica ---\n";

    $list = $call('GET', '/api/coordinator/restock-schedules', [], $coordinatorToken);
    $listData = $data($list);
    $byMachine = [];
    foreach ($listData['items'] ?? [] as $item) {
        $byMachine[(int)$item['machine_id']] = $item;
    }
    $assert(
        '2.1 El catálogo incluye máquinas sin agenda con has_schedule=false',
        $list->getStatusCode() === 200
            && isset($byMachine[$machineA])
            && $byMachine[$machineA]['has_schedule'] === false
            && $byMachine[$machineA]['schedule'] === null
    );

    $create = $call('POST', '/api/coordinator/restock-schedules', [
        'machine_id' => $machineA,
        'cadence_type' => 'DAILY',
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
        'notes' => 'Acceso por muelle de carga antes de las 11:30.',
    ], $coordinatorToken);
    $created = $data($create)['schedule'] ?? [];
    $firstScheduleId = (int)($created['id'] ?? 0);
    $assert(
        '2.2 El alta crea la primera versión activa con próxima fecha',
        $create->getStatusCode() === 200
            && $firstScheduleId > 0
            && ($created['is_active'] ?? false) === true
            && ($created['previous_version_id'] ?? null) === null
            && is_string($created['next_scheduled_date'] ?? null)
    );

    $update = $call('PUT', '/api/coordinator/restock-schedules/machine/' . $machineA, [
        'cadence_type' => 'WEEKLY',
        'day_of_week' => 3,
        'preferred_time_window' => 'MORNING',
        'notes' => 'Cambio a miércoles por reestructuración de ruta.',
    ], $coordinatorToken);
    $updated = $data($update)['schedule'] ?? [];
    $secondScheduleId = (int)($updated['id'] ?? 0);
    $closedRow = $pdo->query(
        'SELECT is_active FROM machine_restock_schedules WHERE id = ' . $firstScheduleId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '2.3 El versionado cierra la vigente e inserta la nueva sin borrar',
        $update->getStatusCode() === 200
            && $secondScheduleId > 0
            && $secondScheduleId !== $firstScheduleId
            && (int)($updated['previous_version_id'] ?? 0) === $firstScheduleId
            && $closedRow !== false
            && filter_var($closedRow['is_active'], FILTER_VALIDATE_BOOLEAN) === false
    );

    $invalidCadence = $call('POST', '/api/coordinator/restock-schedules', [
        'machine_id' => $machineA,
        'cadence_type' => 'WEEKLY',
        'preferred_time_window' => 'MORNING',
    ], $coordinatorToken);
    $assert(
        '2.4 Una cadencia incoherente responde 422 SCHEDULE_CADENCE_FIELDS_INVALID',
        $invalidCadence->getStatusCode() === 422
            && $errorCode($invalidCadence) === 'SCHEDULE_CADENCE_FIELDS_INVALID'
    );

    $bulk = $call('POST', '/api/coordinator/restock-schedules/bulk', [
        'machine_ids' => [$machineA, $machineC],
        'cadence_type' => 'DAILY',
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
        'overwrite_existing' => true,
        'notes' => 'Cadencia de certificación para la zona de pruebas.',
    ], $coordinatorToken);
    $bulkData = $data($bulk);
    $assert(
        '2.5 El lote atómico actualiza una agenda y crea la otra',
        $bulk->getStatusCode() === 200
            && (int)($bulkData['matched_count'] ?? 0) === 2
            && (int)($bulkData['created_count'] ?? 0) === 1
            && (int)($bulkData['updated_count'] ?? 0) === 1
            && (int)($bulkData['skipped_count'] ?? 0) === 0
    );

    $machineCScheduleId = (int)$pdo->query(
        'SELECT id FROM machine_restock_schedules WHERE machine_id = ' . $machineC . ' AND is_active = TRUE'
    )->fetchColumn();
    $deactivate = $call(
        'PATCH',
        '/api/coordinator/restock-schedules/' . $machineCScheduleId . '/deactivate',
        [],
        $coordinatorToken
    );
    $deactivatedRow = $pdo->query(
        'SELECT is_active FROM machine_restock_schedules WHERE id = ' . $machineCScheduleId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '2.6 La baja es lógica y conserva la fila histórica',
        $deactivate->getStatusCode() === 200
            && ($data($deactivate)['deactivated'] ?? false) === true
            && $deactivatedRow !== false
            && filter_var($deactivatedRow['is_active'], FILTER_VALIDATE_BOOLEAN) === false
    );

    $withoutSchedule = $call(
        'GET',
        '/api/coordinator/restock-schedules',
        [],
        $coordinatorToken,
        ['has_schedule' => 'false', 'machine_id' => (string)$machineC]
    );
    $withoutItems = $data($withoutSchedule)['items'] ?? [];
    $assert(
        '2.7 El filtro has_schedule=false devuelve la máquina dada de baja',
        count($withoutItems) === 1
            && (int)$withoutItems[0]['machine_id'] === $machineC
            && $withoutItems[0]['has_schedule'] === false
    );

    $missingMachineSchedule = $call('POST', '/api/coordinator/restock-schedules', [
        'machine_id' => 999999,
        'cadence_type' => 'DAILY',
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
    ], $coordinatorToken);
    $assert(
        '2.8 Configurar agenda sobre máquina inexistente responde 404 MACHINE_NOT_FOUND',
        $missingMachineSchedule->getStatusCode() === 404
            && $errorCode($missingMachineSchedule) === 'MACHINE_NOT_FOUND'
    );

    $inactiveMachineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status, is_active)
         VALUES (:location_id, 'RRP9-SNACK-OFF-SCHED', 'Modelo T-RRP-09', 'SNACKS', 'Planta baja', 'OK', FALSE)
         RETURNING id"
    );
    $inactiveMachineStmt->execute([':location_id' => $locationA]);
    $inactiveMachineForSchedule = (int)$inactiveMachineStmt->fetchColumn();

    $inactiveMachineSchedule = $call('POST', '/api/coordinator/restock-schedules', [
        'machine_id' => $inactiveMachineForSchedule,
        'cadence_type' => 'DAILY',
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
    ], $coordinatorToken);
    $assert(
        '2.9 Configurar agenda sobre máquina inactiva responde 422 MACHINE_INACTIVE_OR_DELETED',
        $inactiveMachineSchedule->getStatusCode() === 422
            && $errorCode($inactiveMachineSchedule) === 'MACHINE_INACTIVE_OR_DELETED'
    );

    $bulkMissing = $call('POST', '/api/coordinator/restock-schedules/bulk', [
        'machine_ids' => [$machineA, 999999],
        'cadence_type' => 'MON_WED_FRI',
        'preferred_time_window' => 'AFTERNOON',
        'overwrite_existing' => true,
    ], $coordinatorToken);
    $machineACadenceAfterMissingBulk = (string)$pdo->query(
        'SELECT cadence_type FROM machine_restock_schedules WHERE machine_id = ' . $machineA . ' AND is_active = TRUE'
    )->fetchColumn();
    $assert(
        '2.10 Lote explícito con máquina inexistente revierte todo con 404 sin alterar agendas',
        $bulkMissing->getStatusCode() === 404
            && $errorCode($bulkMissing) === 'MACHINE_NOT_FOUND'
            && $machineACadenceAfterMissingBulk === 'DAILY'
    );

    $bulkInactive = $call('POST', '/api/coordinator/restock-schedules/bulk', [
        'machine_ids' => [$machineA, $inactiveMachineForSchedule],
        'cadence_type' => 'MON_WED_FRI',
        'preferred_time_window' => 'AFTERNOON',
        'overwrite_existing' => true,
    ], $coordinatorToken);
    $machineACadenceAfterInactiveBulk = (string)$pdo->query(
        'SELECT cadence_type FROM machine_restock_schedules WHERE machine_id = ' . $machineA . ' AND is_active = TRUE'
    )->fetchColumn();
    $assert(
        '2.11 Lote explícito con máquina inactiva revierte todo con 422 sin alterar agendas',
        $bulkInactive->getStatusCode() === 422
            && $errorCode($bulkInactive) === 'MACHINE_INACTIVE_OR_DELETED'
            && $machineACadenceAfterInactiveBulk === 'DAILY'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Tablero «Ruta del Día»
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Tablero «Ruta del Día» de Coordinación ---\n";

    $day = $call('GET', '/api/coordinator/restock-route/day', [], $coordinatorToken, ['service_date' => $today]);
    $dayData = $data($day);
    $ownerRoute = null;
    foreach ($dayData['restocker_routes'] ?? [] as $route) {
        if ((int)($route['restocker_id'] ?? 0) === $ownerId) {
            $ownerRoute = $route;
            break;
        }
    }
    $ownerMachineIds = [];
    foreach ($ownerRoute['stops'] ?? [] as $stop) {
        foreach ($stop['machines'] ?? [] as $machine) {
            $ownerMachineIds[] = (int)($machine['machine_id'] ?? 0);
        }
    }
    $blockedIds = array_map(
        static fn (array $stop): int => (int)($stop['machine_id'] ?? 0),
        $dayData['blocked_stops'] ?? []
    );
    $assert(
        '3.1 El tablero publica los contadores del único service_date',
        $day->getStatusCode() === 200
            && ($dayData['service_date'] ?? null) === $today
            && ($dayData['is_future_projection'] ?? null) === false
            && (int)($dayData['kpis']['scheduled_machines_count'] ?? 0) >= 1
            && array_key_exists('uncovered_count', $dayData['kpis'] ?? [])
    );
    $assert(
        '3.2 La ruta del titular incluye la máquina con agenda y hueco',
        $ownerRoute !== null && in_array($machineA, $ownerMachineIds, true)
    );
    $assert(
        '3.3 La máquina en cuarentena se segrega en blocked_stops',
        in_array($machineBlocked, $blockedIds, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Excepciones diarias
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Excepciones SKIPPED, REASSIGNED y revocación ---\n";

    $skipTomorrow = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineA,
        'service_date' => $tomorrow,
        'override_type' => 'SKIPPED',
        'skip_reason_code' => 'LOCAL_HOLIDAY',
        'reason_text' => 'Edificio municipal cerrado todo el día por festivo patronal confirmado por conserjería.',
    ], $coordinatorToken);
    $skipData = $data($skipTomorrow);
    $skipOverrideId = (int)($skipData['override']['id'] ?? 0);
    $assert(
        '4.1 Coordinación registra SKIPPED con motivo y estado ACTIVE',
        $skipTomorrow->getStatusCode() === 201
            && $skipOverrideId > 0
            && ($skipData['override']['override_type'] ?? null) === 'SKIPPED'
            && ($skipData['override']['status'] ?? null) === 'ACTIVE'
            && ($skipData['fefo_alert'] ?? null) === false
    );

    $reassign = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineA,
        'service_date' => $tomorrow,
        'override_type' => 'REASSIGNED',
        'target_restocker_id' => $targetId,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
        'reason_text' => 'Zona Norte sin cobertura por baja simultánea de titular y suplente.',
    ], $coordinatorToken);
    $reassignData = $data($reassign);
    $reassignOverrideId = (int)($reassignData['override']['id'] ?? 0);
    $revokedPrevious = $pdo->query(
        'SELECT status FROM restock_route_daily_overrides WHERE id = ' . $skipOverrideId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '4.2 REASSIGNED revoca la excepción viva anterior en la misma transacción',
        $reassign->getStatusCode() === 201
            && $reassignOverrideId > 0
            && (int)($reassignData['override']['target_restocker_id'] ?? 0) === $targetId
            && (int)($reassignData['override']['revoked_previous_override_id'] ?? 0) === $skipOverrideId
            && $revokedPrevious !== false
            && (string)$revokedPrevious['status'] === 'REVOKED'
    );

    $revoke = $call(
        'POST',
        '/api/coordinator/restock-route/overrides/' . $reassignOverrideId . '/revoke',
        [],
        $coordinatorToken
    );
    $assert(
        '4.3 La revocación manual deja la excepción en REVOKED',
        $revoke->getStatusCode() === 200
            && ($data($revoke)['override']['status'] ?? null) === 'REVOKED'
            && ($data($revoke)['revoked'] ?? false) === true
    );

    $revokeAgain = $call(
        'POST',
        '/api/coordinator/restock-route/overrides/' . $reassignOverrideId . '/revoke',
        [],
        $coordinatorToken
    );
    $assert(
        '4.4 Revocar una excepción ya cerrada responde 409 OVERRIDE_NOT_ACTIVE',
        $revokeAgain->getStatusCode() === 409 && $errorCode($revokeAgain) === 'OVERRIDE_NOT_ACTIVE'
    );

    $self = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineA,
        'service_date' => $tomorrow,
        'override_type' => 'REASSIGNED',
        'target_restocker_id' => $ownerId,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
    ], $coordinatorToken);
    $assert(
        '4.5 El auto-desvío se rechaza con 422 SELF_REASSIGNMENT_FORBIDDEN',
        $self->getStatusCode() === 422 && $errorCode($self) === 'SELF_REASSIGNMENT_FORBIDDEN'
    );

    $l1Stmt = $pdo->prepare(
        "INSERT INTO incidents (
            ticket_code, machine_id, location_id, category, description, urgency, status,
            dispatch_level, level1_issue_type, assigned_technician_id, assigned_at,
            sla_tier_snapshot, sla_target_minutes, cycle_started_at
         ) VALUES (
            'INC-RRP9-L1-01', :machine, :location, 'PRODUCT_JAM', 'Espiral A1 atascada para prueba L1',
            'MEDIUM', 'ASSIGNED', 'L1_RESTOCKER', 'MINOR_SPIRAL_JAM', :restocker, CURRENT_TIMESTAMP,
            'STANDARD', 1440, CURRENT_TIMESTAMP
         ) RETURNING id"
    );
    $l1Stmt->execute([':machine' => $machineA, ':location' => $locationA, ':restocker' => $ownerId]);
    $l1IncidentId = (int)$l1Stmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO incident_assignments (
            incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
            assignment_source, assigned_at
         ) VALUES (
            :incident, :restocker, 'Reposición Titular T-RRP-09', 'RRP9-OWNER', 'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP
         )"
    )->execute([':incident' => $l1IncidentId, ':restocker' => $ownerId]);

    $reassignWithL1 = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineA,
        'service_date' => $tomorrow,
        'override_type' => 'REASSIGNED',
        'target_restocker_id' => $targetId,
        'reassignment_reason_code' => 'LOAD_BALANCING',
    ], $coordinatorToken);
    $reassignWithL1Id = (int)($data($reassignWithL1)['override']['id'] ?? 0);
    $l1OwnerAfter = (int)$pdo->query(
        'SELECT assigned_technician_id FROM incidents WHERE id = ' . $l1IncidentId
    )->fetchColumn();
    $activeCustodyCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM incident_assignments WHERE incident_id = ' . $l1IncidentId . ' AND technician_id = ' . $ownerId . ' AND unassigned_at IS NULL'
    )->fetchColumn();
    $assert(
        '4.6 El desvío REASSIGNED no muta la custodia L1 en incidents ni incident_assignments',
        $reassignWithL1->getStatusCode() === 201
            && $l1OwnerAfter === $ownerId
            && $activeCustodyCount === 1
    );
    if ($reassignWithL1Id > 0) {
        $call('POST', '/api/coordinator/restock-route/overrides/' . $reassignWithL1Id . '/revoke', [], $coordinatorToken);
    }
    $pdo->exec(
        "UPDATE incident_assignments
            SET unassigned_at = CURRENT_TIMESTAMP,
                unassignment_reason_type = 'CANCELLED',
                unassigned_by_name_snapshot = 'Coordinación T-RRP-09',
                reassignment_reason = 'Cierre de fixture temporal T-RRP-09'
          WHERE incident_id = " . $l1IncidentId
    );
    $pdo->exec("UPDATE incidents SET status = 'CLOSED', resolved_at = CURRENT_TIMESTAMP, closed_at = CURRENT_TIMESTAMP WHERE id = " . $l1IncidentId);

    $machineRevokeAfterVisit = $createMachine($locationA, 'RRP9-SNACK-A-REV');
    $skipBeforeVisit = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineRevokeAfterVisit,
        'service_date' => $today,
        'override_type' => 'SKIPPED',
        'skip_reason_code' => 'LOCAL_HOLIDAY',
        'reason_text' => 'Acceso temporalmente bloqueado antes de confirmar visita posterior en la misma jornada.',
    ], $coordinatorToken);
    $skipBeforeVisitId = (int)($data($skipBeforeVisit)['override']['id'] ?? 0);
    $pdo->prepare(
        "INSERT INTO machine_restock_visits
             (visit_code, machine_id, restocker_id, status, cabin_temp_celsius, client_mutation_id, started_at, completed_at)
         VALUES ('MRV-2026-9201', :machine, :restocker, 'COMPLETED', 4.0, 'visit-rrp09-rev-01',
                 CURRENT_TIMESTAMP - INTERVAL '1 hour', CURRENT_TIMESTAMP)"
    )->execute([':machine' => $machineRevokeAfterVisit, ':restocker' => $ownerId]);
    $revokeCompleted = $call(
        'POST',
        '/api/coordinator/restock-route/overrides/' . $skipBeforeVisitId . '/revoke',
        [],
        $coordinatorToken
    );
    $assert(
        '4.7 Revocar una excepción cuando ya existe visita COMPLETED responde 409 RESTOCK_STOP_ALREADY_COMPLETED_TODAY',
        $revokeCompleted->getStatusCode() === 409
            && $errorCode($revokeCompleted) === 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Hoja móvil, omisión idempotente y conflicto offline
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Hoja móvil del reponedor y omisión in situ ---\n";

    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteData = $data($myRoute);
    $routePlan = $myRouteData['route_plan'] ?? [];
    $routeMachineIds = [];
    foreach ($routePlan['stops'] ?? [] as $stop) {
        foreach ($stop['machines'] ?? [] as $machine) {
            $routeMachineIds[] = (int)($machine['machine_id'] ?? 0);
        }
    }
    $forbiddenKeys = array_intersect(
        ['unit_cost_eur', 'commercial_spoilage_loss_eur', 'reason_text'],
        $collectKeys($myRouteData)
    );
    $assert(
        '5.1 La hoja móvil publica service_date y route_plan con la parada del día',
        $myRoute->getStatusCode() === 200
            && ($myRouteData['service_date'] ?? null) === $today
            && in_array($machineA, $routeMachineIds, true)
            && ($routePlan['summary']['pending_stops_count'] ?? 0) >= 1
    );
    $assert(
        '5.2 La proyección móvil es opaca: sin costes, merma ni notas internas',
        $forbiddenKeys === []
    );

    $skipPayload = [
        'skip_reason_code' => 'LOCATION_CLOSED',
        'reason_text' => 'La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.',
        'client_mutation_id' => 'rrp09-skip-0001',
        'client_occurred_at' => $today . 'T08:45:00+01:00',
    ];
    $skipToday = $call(
        'POST',
        '/api/restocker/route/stops/' . $machineA . '/skip',
        $skipPayload,
        $ownerToken
    );
    $skipTodayData = $data($skipToday);
    $assert(
        '5.3 El reponedor omite su parada con 201 y sin alerta FEFO',
        $skipToday->getStatusCode() === 201
            && ($skipTodayData['override']['status'] ?? null) === 'ACTIVE'
            && ($skipTodayData['fefo_alert'] ?? null) === false
            && ($skipTodayData['expired_stamp'] ?? null) === false
    );

    $replay = $call(
        'POST',
        '/api/restocker/route/stops/' . $machineA . '/skip',
        $skipPayload,
        $ownerToken
    );
    $assert(
        '5.4 El mismo client_mutation_id devuelve 200 sin duplicar la omisión',
        $replay->getStatusCode() === 200
            && ($data($replay)['idempotent_replay'] ?? false) === true
    );

    $foreignSkip = $call(
        'POST',
        '/api/restocker/route/stops/' . $machineC . '/skip',
        array_merge($skipPayload, ['client_mutation_id' => 'rrp09-skip-foreign-0001']),
        $ownerToken
    );
    $assert(
        '5.5 Una máquina fuera de la ruta efectiva responde 403',
        $foreignSkip->getStatusCode() === 403
            && $errorCode($foreignSkip) === 'RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE',
        'status=' . $foreignSkip->getStatusCode() . ' code=' . $errorCode($foreignSkip)
    );

    $blockedSkip = $call(
        'POST',
        '/api/restocker/route/stops/' . $machineBlocked . '/skip',
        array_merge($skipPayload, ['client_mutation_id' => 'rrp09-skip-blocked-0001']),
        $ownerToken
    );
    $assert(
        '5.6 Una máquina bloqueada en cuarentena responde 409 RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT',
        $blockedSkip->getStatusCode() === 409
            && $errorCode($blockedSkip) === 'RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT',
        'status=' . $blockedSkip->getStatusCode() . ' code=' . $errorCode($blockedSkip)
    );

    $inactiveStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status, is_active)
         VALUES (:location_id, 'RRP9-SNACK-INACTIVE', 'Modelo T-RRP-09', 'SNACKS', 'Planta baja', 'OK', FALSE)
         RETURNING id"
    );
    $inactiveStmt->execute([':location_id' => $locationA]);
    $machineInactive = (int)$inactiveStmt->fetchColumn();

    $inactiveSkip = $call(
        'POST',
        '/api/restocker/route/stops/' . $machineInactive . '/skip',
        array_merge($skipPayload, ['client_mutation_id' => 'rrp09-skip-inactive-0001']),
        $ownerToken
    );
    $assert(
        '5.7 Una máquina inactiva responde 422 MACHINE_INACTIVE_OR_DELETED',
        $inactiveSkip->getStatusCode() === 422
            && $errorCode($inactiveSkip) === 'MACHINE_INACTIVE_OR_DELETED',
        'status=' . $inactiveSkip->getStatusCode() . ' code=' . $errorCode($inactiveSkip)
    );

    $conflict = $call('POST', '/api/restocker/route/override-conflict-dump', [
        'machine_id' => $machineA,
        'client_mutation_id' => 'rrp09-conflict-0001',
        'http_status' => 409,
        'error_code' => 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY',
        'message' => 'La parada ya figura completada hoy.',
        'client_occurred_at' => $today . 'T10:15:00+01:00',
    ], $ownerToken);
    $dumped = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'RESTOCK_ROUTE_DAILY_OVERRIDE'
            AND action = 'DUMP_RESTOCK_OVERRIDE_CONFLICT'"
    )->fetchColumn();
    $assert(
        '5.8 El conflicto offline se archiva en auditoría sin tocar la verdad',
        $conflict->getStatusCode() === 201
            && ($data($conflict)['archived'] ?? false) === true
            && $dumped === 1
    );

    $openShiftStmt = $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES (:id, :date, 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '2 hours')"
    );
    $openShiftStmt->execute([':id' => $targetId, ':date' => $today]);
    $openShiftStmt->execute([':id' => $technicianId, ':date' => $today]);

    $satIncidentStmt = $pdo->prepare(
        "INSERT INTO incidents (
            ticket_code, machine_id, location_id, category, description, urgency, status,
            dispatch_level, level1_issue_type, assigned_technician_id, assigned_at,
            sla_tier_snapshot, sla_target_minutes, cycle_started_at
         ) VALUES (
            'INC-RRP9-SAT-01', :machine, :location, 'PAYMENT_SYSTEM', 'Avería SAT en validador de monedas',
            'HIGH', 'IN_PROGRESS', 'L2_SAT', NULL, :tech, CURRENT_TIMESTAMP,
            'STANDARD', 480, CURRENT_TIMESTAMP
         ) RETURNING id"
    );
    $satIncidentStmt->execute([':machine' => $machineC, ':location' => $locationC, ':tech' => $technicianId]);
    $satIncidentId = (int)$satIncidentStmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO incident_assignments (
            incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
            assignment_source, assigned_at
         ) VALUES (
            :incident, :tech, 'Técnico SAT T-RRP-09', 'RRP9-SAT', 'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP
         )"
    )->execute([':incident' => $satIncidentId, ':tech' => $technicianId]);

    $reassignForCoincidence = $call('POST', '/api/coordinator/restock-route/overrides', [
        'machine_id' => $machineC,
        'service_date' => $today,
        'override_type' => 'REASSIGNED',
        'target_restocker_id' => $targetId,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
    ], $coordinatorToken);
    $reassignCoincidenceId = (int)($data($reassignForCoincidence)['override']['id'] ?? 0);

    $targetRoute = $call('GET', '/api/restocker/my-route', [], $targetToken);
    $targetRouteData = $data($targetRoute);
    $locationCStopCoincidence = null;
    foreach ($targetRouteData['route_plan']['stops'] ?? [] as $stopRow) {
        if ((int)($stopRow['location_id'] ?? 0) === $locationC) {
            $locationCStopCoincidence = $stopRow['site_coincidence'] ?? null;
            break;
        }
    }
    $targetStopTechIds = array_map(
        static fn (array $entry): int => (int)($entry['user_id'] ?? 0),
        is_array($locationCStopCoincidence) ? $locationCStopCoincidence : []
    );
    $planMapTechIds = array_map(
        static fn (array $entry): int => (int)($entry['user_id'] ?? 0),
        $targetRouteData['route_plan']['site_coincidence'][$locationC]
            ?? $targetRouteData['route_plan']['site_coincidence'][(string)$locationC]
            ?? []
    );
    $assert(
        '5.9 La hoja móvil publica site_coincidence dentro del route_plan para paradas desviadas con REASSIGNED',
        $reassignForCoincidence->getStatusCode() === 201
            && $targetRoute->getStatusCode() === 200
            && in_array($technicianId, $targetStopTechIds, true)
            && in_array($technicianId, $planMapTechIds, true)
    );

    $techRoute = $call('GET', '/api/technician/my-route', [], $technicianToken);
    $techStops = $data($techRoute);
    $techLocCCoincidence = [];
    foreach (is_array($techStops) ? $techStops : [] as $techStop) {
        if ((int)($techStop['location']['id'] ?? ($techStop['location_id'] ?? 0)) === $locationC) {
            $techLocCCoincidence = $techStop['site_coincidence'] ?? [];
            break;
        }
    }
    $techCoincidenceUserIds = array_map(
        static fn (array $entry): int => (int)($entry['user_id'] ?? 0),
        is_array($techLocCCoincidence) ? $techLocCCoincidence : []
    );
    $assert(
        '5.10 La ruta SAT detecta en site_coincidence al reponedor receptor del desvío puntual REASSIGNED',
        $techRoute->getStatusCode() === 200
            && in_array($targetId, $techCoincidenceUserIds, true)
    );

    if ($reassignCoincidenceId > 0) {
        $call('POST', '/api/coordinator/restock-route/overrides/' . $reassignCoincidenceId . '/revoke', [], $coordinatorToken);
    }
    $pdo->exec(
        "UPDATE incident_assignments
            SET unassigned_at = CURRENT_TIMESTAMP,
                unassignment_reason_type = 'CANCELLED',
                unassigned_by_name_snapshot = 'Coordinación T-RRP-09',
                reassignment_reason = 'Cierre de fixture temporal T-RRP-09'
          WHERE incident_id = " . $satIncidentId
    );
    $pdo->exec("UPDATE incidents SET status = 'CLOSED', resolved_at = CURRENT_TIMESTAMP, closed_at = CURRENT_TIMESTAMP WHERE id = " . $satIncidentId);

    // ─────────────────────────────────────────────────────────────────────
    // 6. Propuesta M33 con TODAY_ROUTE
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Propuesta M33 con demanda TODAY_ROUTE ---\n";

    $proposalToday = $call('GET', '/api/coordinator/product-loading-orders/proposal', [], $coordinatorToken, [
        'restocker_id' => (string)$ownerId,
        'demand_mode' => 'TODAY_ROUTE',
        'service_date' => $today,
    ]);
    $proposalTodayData = $data($proposalToday);
    $assert(
        '6.1 Tras la omisión, la ruta de hoy no aporta demanda ni paradas',
        $proposalToday->getStatusCode() === 200
            && ($proposalTodayData['demand_mode'] ?? null) === 'TODAY_ROUTE'
            && ($proposalTodayData['service_date'] ?? null) === $today
            && ($proposalTodayData['route_machine_ids'] ?? null) === []
            && ($proposalTodayData['items'] ?? null) === []
    );

    $proposalTomorrow = $call('GET', '/api/coordinator/product-loading-orders/proposal', [], $coordinatorToken, [
        'restocker_id' => (string)$ownerId,
        'demand_mode' => 'TODAY_ROUTE',
        'service_date' => $tomorrow,
    ]);
    $proposalTomorrowData = $data($proposalTomorrow);
    $tomorrowMachineIds = $proposalTomorrowData['route_machine_ids'] ?? [];
    $tomorrowItems = $proposalTomorrowData['items'] ?? [];
    $assert(
        '6.2 La propuesta futura acota la demanda a la ruta y excluye bloqueadas',
        $proposalTomorrow->getStatusCode() === 200
            && in_array($machineA, $tomorrowMachineIds, true)
            && !in_array($machineBlocked, $tomorrowMachineIds, true)
            && in_array($machineBlocked, $proposalTomorrowData['excluded_blocked_machine_ids'] ?? [], true)
            && $tomorrowItems !== []
            && ($tomorrowItems[0]['reason'] ?? null) === 'ROUTE_GAP_FILL'
    );

    $invalidDate = $call('GET', '/api/coordinator/product-loading-orders/proposal', [], $coordinatorToken, [
        'restocker_id' => (string)$ownerId,
        'demand_mode' => 'TODAY_ROUTE',
        'service_date' => '2026-13-99',
    ]);
    $assert(
        '6.3 Una service_date inválida responde 400 VALIDATION_ERROR',
        $invalidDate->getStatusCode() === 400 && $errorCode($invalidDate) === 'VALIDATION_ERROR'
    );

    $batch = $call('POST', '/api/coordinator/product-loading-orders/batch', [
        'demand_mode' => 'TODAY_ROUTE',
        'service_date' => $tomorrow,
        'safety_buffer_pct' => 10,
    ], $coordinatorToken);
    $batchData = $data($batch);
    $skipReasons = array_map(
        static fn (array $row): string => (string)($row['reason'] ?? ''),
        $batchData['skipped'] ?? []
    );
    $assert(
        '6.4 El lote TODAY_ROUTE crea la orden con demanda y omite NO_DEMAND',
        $batch->getStatusCode() === 200
            && (int)($batchData['created_count'] ?? 0) === 1
            && (int)($batchData['skipped_count'] ?? 0) >= 1
            && in_array('NO_DEMAND', $skipReasons, true)
    );
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, "\n[FAIL] Excepción inesperada: " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, RRP09_API_SCHEMA);
        } catch (Throwable $exception) {
            $failures++;
            fwrite(STDERR, "\n[FAIL] No se pudo limpiar el esquema aislado: " . $exception->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo $failures === 0 ? " RESULTADO: 100% EN VERDE\n" : " RESULTADO: EN ROJO\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
