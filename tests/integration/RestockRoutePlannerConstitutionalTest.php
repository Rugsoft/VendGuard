<?php

declare(strict_types=1);

/**
 * RestockRoutePlannerConstitutionalTest · T-RRP-10
 *
 * Certifica el blindaje constitucional del Módulo 37 sobre PostgreSQL real, el
 * router HTTP y dos conexiones independientes:
 *
 *   1. **RBAC y perímetro de superficies.** Sin token 401; el reponedor no
 *      entra en Coordinación ni Coordinación en la hoja móvil; el técnico SAT
 *      no alcanza la omisión; las rutas del planificador no existen bajo
 *      `/api/site`, `/api/qr` ni `/api/public`.
 *   2. **Art. II.** `FEFO_ALERT` solo con `requires_cold_chain = TRUE`; una
 *      omisión con lote refrigerado vencido sella `EXPIRED`, encola la alerta
 *      interna y no bloquea la visita M34; la cuarentena sigue bloqueada y no
 *      se degrada.
 *   3. **Art. III.1/III.3.** Versionado inmutable de agendas, auditoría
 *      `MACHINE_RESTOCK_SCHEDULE`/`RESTOCK_ROUTE_DAILY_OVERRIDE`, cero
 *      `DELETE FROM` y `prevent_hard_delete()` mordiendo en ambas tablas.
 *   4. **Art. V.1.** `reason_text` de 20..500 caracteres reales y al menos 3
 *      bloques alfanuméricos, con rechazo 422 en los tres bordes.
 *   5. **Art. V.4.** La hoja móvil no proyecta costes, notas internas ni
 *      `reason_text`; la alerta FEFO queda en el canal interno de Coordinación;
 *      Sede, QR y A4 no referencian las tablas del planificador.
 *   6. **Art. VI.** Contadores instantáneos de un único `service_date`
 *      (completadas/omitidas/bloqueadas no se heredan al día siguiente), DDL de
 *      `locations` intacto y `CoordinatorTerritorialMapTab.js` sin cambios.
 *   7. **Concurrencia real.** `lockMachine()` bloquea la fila con `FOR UPDATE
 *      OF m`: la segunda conexión agota `lock_timeout` con `55P03`, el bloqueo
 *      es por fila y se libera al confirmar/retroceder.
 *   8. **Idempotencia offline.** El mismo `client_mutation_id` devuelve 200 sin
 *      duplicar la omisión ni la mutación de `offline_sync_mutations`.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin motor responde 2, nunca un
 * verde silencioso. El esquema aislado se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/RestockRoutePlannerConstitutionalTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Service\MachineRestockVisitValidator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoRestockRoutePlannerGateway;
use VendGuard\Infrastructure\Repository\PdoRestockRouteReadModel;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

const RRP10_SCHEMA = 't_rrp10_constitutional';

/** Huella normalizada del mapa territorial que el Art. VI declara intacto. */
const RRP10_TERRITORIAL_MAP_SHA256 = '167016d64a008ee8e9bdea7fa00c6aeca23869e53d295287d0c62f0f91b070a2';

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
echo " VENDGUARD · T-RRP-10 · Blindaje constitucional del Planificador de Rutas\n";
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

/** @return array<int, array<string, mixed>> */
$routeMachines = static function (array $routePlan): array {
    $machines = [];
    foreach ($routePlan['stops'] ?? [] as $stop) {
        foreach ($stop['machines'] ?? [] as $machine) {
            $machines[(int)($machine['machine_id'] ?? 0)] = $machine;
        }
    }

    return $machines;
};

/** @return list<int> */
$stopMachineIds = static function (array $stops): array {
    $ids = [];
    foreach ($stops as $stop) {
        if (isset($stop['machine_id'])) {
            $ids[] = (int)$stop['machine_id'];
        }
        foreach ($stop['machines'] ?? [] as $machine) {
            $ids[] = (int)($machine['machine_id'] ?? 0);
        }
    }

    return $ids;
};

$schemaReady = false;
$exitCode = 1;
$pdoB = null;

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, RRP10_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('+1 day')
        ->format('Y-m-d');
    $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('-1 day')
        ->format('Y-m-d');
    // La agenda semanal de la sección 5 no debe caer en el día de la corrida: si
    // coincidiera, el tablero contaría una sexta máquina programada y la guarda
    // dependería del calendario. Se elige siempre el día siguiente al de hoy.
    $todayWeekday = (int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('N');
    $weekdayNotToday = $todayWeekday === 7 ? 1 : $todayWeekday + 1;

    $createUser = static function (string $suffix, string $role, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'rrp10.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rrp10.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rrp10-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RRP10-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-RRP-10');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-RRP-10');
    $targetId = $createUser('target', 'RESTOCKER', 'Reposición Destino T-RRP-10');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-RRP-10');

    $createZone = static function (string $code, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO operational_zones (code, name, color_hex)
             VALUES (:code, :name, '#2560ff')
             RETURNING id"
        );
        $stmt->execute([':code' => $code, ':name' => $name]);

        return (int)$stmt->fetchColumn();
    };

    $zoneA = $createZone('RRP10-A', 'Zona A T-RRP-10');
    $zoneB = $createZone('RRP10-B', 'Zona B T-RRP-10');

    $createLocation = static function (string $siteCode, string $name, int $zoneId, float $lat, float $lng) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, zone_id, has_physical_reception, latitude, longitude)
             VALUES (:site_code, :site_code_key, :name, :address, :zone_id, TRUE, :lat, :lng)
             RETURNING id'
        );
        $stmt->execute([
            ':site_code' => $siteCode,
            ':site_code_key' => strtolower($siteCode),
            ':name' => $name,
            ':address' => 'Carrer de la Constitució 10, Barcelona',
            ':zone_id' => $zoneId,
            ':lat' => $lat,
            ':lng' => $lng,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $locationA = $createLocation('SEDE-RRP10-A', 'Sede A T-RRP-10', $zoneA, 41.4036299, 2.1743558);
    $locationB = $createLocation('SEDE-RRP10-B', 'Sede B T-RRP-10', $zoneB, 41.4100000, 2.1800000);

    $createMachine = static function (int $locationId, string $code, string $type, string $sanitary = 'OK') use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
             VALUES (:location_id, :code, :model, :type, :floor, :sanitary)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo T-RRP-10',
            ':type' => $type,
            ':floor' => 'Planta baja',
            ':sanitary' => $sanitary,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $machineCold = $createMachine($locationA, 'RRP10-COLD', 'PERISHABLE_FOOD');
    $machineAmbient = $createMachine($locationA, 'RRP10-AMBIENT', 'SNACKS');
    $machineCompleted = $createMachine($locationA, 'RRP10-COMPLETED', 'SNACKS');
    $machineQuarantine = $createMachine($locationA, 'RRP10-QUARANTINE', 'SNACKS', 'QUARANTINE');
    $machineSchedule = $createMachine($locationA, 'RRP10-SCHEDULE', 'SNACKS');
    $machineTarget = $createMachine($locationB, 'RRP10-TARGET', 'SNACKS');

    $assign = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assign->execute([':restocker_id' => $ownerId, ':zone_id' => $zoneA]);
    $assign->execute([':restocker_id' => $targetId, ':zone_id' => $zoneB]);

    $shift = $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES (:id, :date, 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '2 hours')"
    );
    $shift->execute([':id' => $ownerId, ':date' => $today]);
    $shift->execute([':id' => $targetId, ':date' => $today]);

    $pdo->exec(
        "INSERT INTO route_settings (base_name, base_latitude, base_longitude)
         VALUES ('Base T-RRP-10', 41.3935, 2.1701)"
    );

    $productStmt = $pdo->prepare(
        "INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES (:sku, :name, :category, 'UNIT', :cold, :shelf_life, :cost, 2.50)
         RETURNING id"
    );
    $productStmt->execute([
        ':sku' => 'RRP10-COLD-01',
        ':name' => 'Yogur T-RRP-10',
        ':category' => 'PERISHABLE_FOOD',
        ':cold' => 'TRUE',
        ':shelf_life' => 21,
        ':cost' => 0.90,
    ]);
    $coldProductId = (int)$productStmt->fetchColumn();
    $productStmt->execute([
        ':sku' => 'RRP10-AMBIENT-01',
        ':name' => 'Barrita T-RRP-10',
        ':category' => 'SNACK',
        ':cold' => 'FALSE',
        ':shelf_life' => null,
        ':cost' => 0.60,
    ]);
    $ambientProductId = (int)$productStmt->fetchColumn();

    $slotStmt = $pdo->prepare(
        'INSERT INTO machine_planogram_slots
             (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, current_expiration_date, is_active)
         VALUES (:machine_id, :slot_code, :product_id, 12, 5, 2, :expiration, TRUE)'
    );
    $slotStmt->execute([
        ':machine_id' => $machineCold,
        ':slot_code' => 'C1',
        ':product_id' => $coldProductId,
        ':expiration' => $today,
    ]);
    $slotStmt->execute([
        ':machine_id' => $machineAmbient,
        ':slot_code' => 'A1',
        ':product_id' => $ambientProductId,
        ':expiration' => $yesterday,
    ]);

    $scheduleStmt = $pdo->prepare(
        "INSERT INTO machine_restock_schedules
             (machine_id, cadence_type, preferred_time_window, include_weekends, is_active, created_by, updated_by)
         VALUES (:machine_id, 'DAILY', 'MORNING', TRUE, TRUE, :actor, :actor)"
    );
    foreach ([$machineCold, $machineAmbient, $machineCompleted, $machineQuarantine, $machineTarget] as $machineId) {
        $scheduleStmt->execute([':machine_id' => $machineId, ':actor' => $coordinatorId]);
    }

    $pdo->prepare(
        "INSERT INTO machine_restock_visits
             (visit_code, machine_id, restocker_id, status, cabin_temp_celsius, client_mutation_id, started_at, completed_at)
         VALUES ('MRV-2026-9101', :machine, :restocker, 'COMPLETED', 4.0, 'visit-rrp10-0001',
                 CURRENT_TIMESTAMP - INTERVAL '1 hour', CURRENT_TIMESTAMP)"
    )->execute([':machine' => $machineCompleted, ':restocker' => $ownerId]);

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
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
    // 1. RBAC y perímetro de superficies
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC y perímetro de superficies ---\n";

    $anonymous = $call('GET', '/api/coordinator/restock-route/day');
    $assert(
        '1.1 Sin token, el tablero responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/restock-route/day', [], $ownerToken);
    $assert(
        '1.2 El reponedor no entra en el tablero de Coordinación (403)',
        $restockerOnCoordinator->getStatusCode() === 403
    );

    $coordinatorOnMobile = $call('GET', '/api/restocker/my-route', [], $coordinatorToken);
    $assert(
        '1.3 Coordinación no entra en la hoja móvil del reponedor (403)',
        $coordinatorOnMobile->getStatusCode() === 403
    );

    $technicianOnSkip = $call('POST', '/api/restocker/route/stops/' . $machineCold . '/skip', [], $technicianToken);
    $assert(
        '1.4 El técnico SAT no alcanza la omisión del reponedor (403)',
        $technicianOnSkip->getStatusCode() === 403
    );

    $publicPlannerPaths = [
        '/api/site/restock-route/day',
        '/api/qr/restock-route/day',
        '/api/public/restock-route/day',
    ];
    $publicPlannerStatuses = [];
    foreach ($publicPlannerPaths as $path) {
        $publicPlannerStatuses[$path] = $call('GET', $path)->getStatusCode();
    }
    $assert(
        '1.5 El planificador no se monta en Sede, QR ni portal público',
        array_values(array_filter($publicPlannerStatuses, static fn (int $status): bool => $status === 200)) === []
            && array_values($publicPlannerStatuses) === [404, 404, 404],
        json_encode($publicPlannerStatuses)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. II: FEFO refrigerado, sello y exclusión sanitaria
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. II · FEFO, sello EXPIRED y cuarentena ---\n";

    $readModel = new PdoRestockRouteReadModel($pdo);
    $snapshot = $readModel->loadParkSnapshot($today);
    $snapshotMachines = [];
    foreach ($snapshot['machines'] as $machine) {
        $snapshotMachines[(int)$machine['id']] = $machine;
    }
    $coldSnapshot = $snapshotMachines[$machineCold] ?? [];
    $ambientSnapshot = $snapshotMachines[$machineAmbient] ?? [];
    $assert(
        '2.1 El frío con lote vencido publica FEFO y sello de lote caducado',
        ($coldSnapshot['earliest_fefo_expiration_date'] ?? null) === $today
            && ($coldSnapshot['has_expired_cold_batch'] ?? null) === true
    );
    $assert(
        '2.2 El producto de temperatura ambiente no entra en FEFO',
        array_key_exists('earliest_fefo_expiration_date', $ambientSnapshot)
            && $ambientSnapshot['earliest_fefo_expiration_date'] === null
            && ($ambientSnapshot['has_expired_cold_batch'] ?? null) === false,
        json_encode([
            'fefo' => array_key_exists('earliest_fefo_expiration_date', $ambientSnapshot)
                ? $ambientSnapshot['earliest_fefo_expiration_date']
                : 'missing',
            'expired' => $ambientSnapshot['has_expired_cold_batch'] ?? 'missing',
        ])
    );

    $myRouteBefore = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteBeforeData = $data($myRouteBefore);
    $beforePlan = $myRouteBeforeData['route_plan'] ?? [];
    $beforeMachines = $routeMachines($beforePlan);
    $assert(
        '2.3 La ruta del titular pone el frío en el bloque FEFO',
        $myRouteBefore->getStatusCode() === 200
            && ($beforeMachines[$machineCold]['has_fefo_alert'] ?? null) === true
            && ($beforePlan['stops'][0]['priority_block'] ?? null) === 'FEFO_SANITARY'
            && in_array('FEFO_ALERT', $beforeMachines[$machineCold]['triggers'] ?? [], true)
    );
    $assert(
        '2.4 El ambiente vencido no dispara FEFO propio y sigue pendiente por cadencia',
        ($beforeMachines[$machineAmbient]['has_fefo_alert'] ?? null) === false
            && !in_array('FEFO_ALERT', $beforeMachines[$machineAmbient]['triggers'] ?? [], true)
            && in_array('SCHEDULED', $beforeMachines[$machineAmbient]['triggers'] ?? [], true)
            && ($beforeMachines[$machineAmbient]['status'] ?? null) === 'PENDING',
        json_encode([
            'ambient' => $beforeMachines[$machineAmbient] ?? null,
            'blocks' => array_map(static fn (array $stop): string => (string)($stop['priority_block'] ?? ''), $beforePlan['stops'] ?? []),
        ])
    );

    $assert(
        '2.5 El técnico SAT no puede omitir una parada (perímetro de rol)',
        $technicianOnSkip->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. V.1: rigor textual de la omisión
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. V.1 · reason_text 20..500 y >= 3 bloques ---\n";

    $invalidReason = static function (string $reasonText, string $mutation) use ($call, $ownerToken, $machineCold): Response {
        return $call('POST', '/api/restocker/route/stops/' . $machineCold . '/skip', [
            'skip_reason_code' => 'LOCATION_CLOSED',
            'reason_text' => $reasonText,
            'client_mutation_id' => $mutation,
        ], $ownerToken);
    };

    $short = $invalidReason(str_repeat('A', 19), 'rrp10-invalid-short');
    $assert(
        '3.1 Un motivo de 19 caracteres se rechaza con 422 SKIP_REASON_INVALID',
        $short->getStatusCode() === 422 && $errorCode($short) === 'SKIP_REASON_INVALID',
        'status=' . $short->getStatusCode() . ' code=' . $errorCode($short)
    );

    $twoBlocks = $invalidReason(str_repeat('A', 10) . ' ' . str_repeat('B', 10), 'rrp10-invalid-blocks');
    $assert(
        '3.2 Un motivo de 21 caracteres y dos bloques se rechaza con 422',
        $twoBlocks->getStatusCode() === 422 && $errorCode($twoBlocks) === 'SKIP_REASON_INVALID'
    );

    $tooLong = $invalidReason(str_repeat('AAAA ', 100) . 'X', 'rrp10-invalid-long');
    $assert(
        '3.3 Un motivo de 501 caracteres se rechaza con 422',
        $tooLong->getStatusCode() === 422 && $errorCode($tooLong) === 'SKIP_REASON_INVALID'
    );

    $assert(
        '3.4 Los tres rechazos no han escrito ninguna excepción',
        (int)$pdo->query('SELECT COUNT(*) FROM restock_route_daily_overrides')->fetchColumn() === 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Omisión válida, sello EXPIRED, alerta interna e idempotencia
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Omisión válida, sello EXPIRED y alerta interna ---\n";

    $validReason = 'La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.';
    $skipPayload = [
        'skip_reason_code' => 'LOCATION_CLOSED',
        'reason_text' => $validReason,
        'client_mutation_id' => 'rrp10-skip-0001',
        'client_occurred_at' => $today . 'T08:45:00+01:00',
    ];
    $skip = $call('POST', '/api/restocker/route/stops/' . $machineCold . '/skip', $skipPayload, $ownerToken);
    $skipData = $data($skip);
    $overrideId = (int)($skipData['override']['id'] ?? 0);
    $notificationId = (int)($skipData['notification_id'] ?? 0);
    $assert(
        '4.1 La omisión válida responde 201 con FEFO y sello EXPIRED',
        $skip->getStatusCode() === 201
            && $overrideId > 0
            && ($skipData['fefo_alert'] ?? null) === true
            && ($skipData['expired_stamp'] ?? null) === true
            && ($skipData['override']['had_active_fefo_alert'] ?? null) === true
    );
    $assert(
        '4.2 La máquina refrigerada queda sellada EXPIRED sin cuarentena',
        (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineCold)->fetchColumn() === 'EXPIRED'
    );

    $notification = $pdo->query(
        'SELECT template, priority, recipient_role, channel, payload_json
           FROM notification_outbox WHERE id = ' . $notificationId
    )->fetch(PDO::FETCH_ASSOC);
    $notificationPayload = $notification !== false
        ? (json_decode((string)$notification['payload_json'], true) ?: [])
        : [];
    $assert(
        '4.3 La alerta FEFO es interna, crítica y exclusiva de Coordinación',
        $notificationId > 0
            && $notification !== false
            && (string)$notification['template'] === 'RESTOCK_FEFO_STOP_SKIPPED_ALERT'
            && (string)$notification['priority'] === 'CRITICAL_SANITARY'
            && (string)$notification['recipient_role'] === 'COORDINATOR'
            && (string)$notification['channel'] === 'EMAIL'
            && !in_array('SITE', [$notification['recipient_role']], true)
    );
    $assert(
        '4.4 El payload de la alerta no filtra costes ni notas internas',
        array_intersect(['unit_cost_eur', 'commercial_spoilage_loss_eur', 'reason_text'], array_keys($notificationPayload)) === []
    );

    $expiredAllowed = true;
    try {
        MachineRestockVisitValidator::assertMachineAvailable('EXPIRED', false, false, $machineCold);
    } catch (MachineRestockVisitValidationException $exception) {
        $expiredAllowed = false;
    }
    $quarantineBlocked = false;
    try {
        MachineRestockVisitValidator::assertMachineAvailable('QUARANTINE', false, false, $machineQuarantine);
    } catch (MachineRestockVisitValidationException $exception) {
        $quarantineBlocked = $exception->getHttpStatusCode() === 409;
    }
    $assert(
        '4.5 EXPIRED no bloquea la visita M34; QUARANTINE sí (409)',
        $expiredAllowed === true && $quarantineBlocked === true
    );

    $quarantineStays = (new PdoRestockRoutePlannerGateway($pdo))->markMachineSanitaryExpired($machineQuarantine) === false
        && (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineQuarantine)->fetchColumn() === 'QUARANTINE';
    $assert(
        '4.6 La cuarentena no se degrada a EXPIRED',
        $quarantineStays
    );

    $replay = $call('POST', '/api/restocker/route/stops/' . $machineCold . '/skip', $skipPayload, $ownerToken);
    $assert(
        '4.7 El mismo client_mutation_id devuelve 200 idempotente',
        $replay->getStatusCode() === 200
            && ($data($replay)['idempotent_replay'] ?? false) === true
    );
    $assert(
        '4.8 No se duplica la omisión ni la mutación offline',
        (int)$pdo->query(
            'SELECT COUNT(*) FROM restock_route_daily_overrides
              WHERE machine_id = ' . $machineCold . ' AND service_date = \'' . $today . '\' AND status = \'ACTIVE\''
        )->fetchColumn() === 1
            && (int)$pdo->query(
                "SELECT COUNT(*) FROM offline_sync_mutations
                  WHERE technician_id = {$ownerId} AND client_mutation_id = 'rrp10-skip-0001'"
            )->fetchColumn() === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Art. III.1/III.3: versionado, auditoría y cero borrados
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Art. III.1/III.3 · versionado, auditoría y cero borrados ---\n";

    $createSchedule = $call('POST', '/api/coordinator/restock-schedules', [
        'machine_id' => $machineSchedule,
        'cadence_type' => 'DAILY',
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
        'notes' => 'Agenda constitucional de la máquina de certificación.',
    ], $coordinatorToken);
    $firstScheduleId = (int)($data($createSchedule)['schedule']['id'] ?? 0);
    $assert(
        '5.1 El alta versionada crea la primera agenda activa',
        $createSchedule->getStatusCode() === 200 && $firstScheduleId > 0
    );

    $updateSchedule = $call('PUT', '/api/coordinator/restock-schedules/machine/' . $machineSchedule, [
        'cadence_type' => 'WEEKLY',
        'day_of_week' => $weekdayNotToday,
        'preferred_time_window' => 'AFTERNOON',
        'notes' => 'Segunda versión constitucional de la agenda.',
    ], $coordinatorToken);
    $secondScheduleId = (int)($data($updateSchedule)['schedule']['id'] ?? 0);
    $closed = $pdo->query(
        'SELECT is_active, deactivated_by, deactivated_at FROM machine_restock_schedules WHERE id = ' . $firstScheduleId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '5.2 El versionado cierra la vigente e inserta una nueva fila',
        $updateSchedule->getStatusCode() === 200
            && $secondScheduleId > $firstScheduleId
            && (int)($data($updateSchedule)['schedule']['previous_version_id'] ?? 0) === $firstScheduleId
            && $closed !== false
            && filter_var($closed['is_active'], FILTER_VALIDATE_BOOLEAN) === false
            && (int)$closed['deactivated_by'] === $coordinatorId
            && $closed['deactivated_at'] !== null
    );
    $assert(
        '5.3 El histórico conserva las dos versiones sin borrar la primera',
        (int)$pdo->query(
            'SELECT COUNT(*) FROM machine_restock_schedules WHERE machine_id = ' . $machineSchedule
        )->fetchColumn() === 2
    );

    $scheduleAudits = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_RESTOCK_SCHEDULE'
            AND action = 'UPSERT_RESTOCK_SCHEDULE'
            AND entity_id IN ({$firstScheduleId}, {$secondScheduleId})"
    )->fetchColumn();
    $assert(
        '5.4 Cada versión deja su evento MACHINE_RESTOCK_SCHEDULE',
        $scheduleAudits === 2
    );

    $scheduleDeleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM machine_restock_schedules WHERE id = ' . $firstScheduleId);
    } catch (PDOException $exception) {
        $scheduleDeleteBlocked = (string)$exception->getCode() === '45000';
    }
    $assert(
        '5.5 prevent_hard_delete rechaza el borrado físico de la agenda',
        $scheduleDeleteBlocked
    );

    $overrideAudit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'RESTOCK_ROUTE_DAILY_OVERRIDE'
            AND action = 'SKIP_RESTOCK_STOP'
            AND entity_id = {$overrideId}"
    )->fetchColumn();
    $assert(
        '5.6 La omisión deja su evento RESTOCK_ROUTE_DAILY_OVERRIDE',
        $overrideAudit === 1
    );

    $overrideDeleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM restock_route_daily_overrides WHERE id = ' . $overrideId);
    } catch (PDOException $exception) {
        $overrideDeleteBlocked = (string)$exception->getCode() === '45000';
    }
    $assert(
        '5.7 prevent_hard_delete rechaza el borrado físico de la excepción',
        $overrideDeleteBlocked
    );

    $overrideCountBeforeDump = (int)$pdo->query('SELECT COUNT(*) FROM restock_route_daily_overrides')->fetchColumn();
    $conflict = $call('POST', '/api/restocker/route/override-conflict-dump', [
        'machine_id' => $machineCold,
        'client_mutation_id' => 'rrp10-conflict-0001',
        'http_status' => 409,
        'error_code' => 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY',
        'message' => 'La parada ya figura completada hoy.',
        'client_occurred_at' => $today . 'T10:15:00+01:00',
    ], $ownerToken);
    $conflictAudits = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'RESTOCK_ROUTE_DAILY_OVERRIDE'
            AND action = 'DUMP_RESTOCK_OVERRIDE_CONFLICT'"
    )->fetchColumn();
    $assert(
        '5.8 El volcado offline audita sin sobreescribir la verdad',
        $conflict->getStatusCode() === 201
            && ($data($conflict)['archived'] ?? false) === true
            && $conflictAudits === 1
            && (int)$pdo->query('SELECT COUNT(*) FROM restock_route_daily_overrides')->fetchColumn() === $overrideCountBeforeDump
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Art. V.4: opacidad móvil, alerta interna y superficies externas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Art. V.4 · opacidad móvil y superficies externas ---\n";

    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteData = $data($myRoute);
    $mobileKeys = $collectKeys($myRouteData);
    $forbiddenMobileKeys = [
        'unit_cost_eur',
        'commercial_spoilage_loss_eur',
        'reason_text',
        'notes',
        'created_by',
        'resolved_by',
        'triggered_notification_id',
        'schedule_created_by',
        'schedule_updated_by',
    ];
    $assert(
        '6.1 La hoja móvil no proyecta costes, notas ni texto interno',
        $myRoute->getStatusCode() === 200
            && array_intersect($forbiddenMobileKeys, $mobileKeys) === []
    );
    $assert(
        '6.2 La hoja móvil conserva la omisión como hecho sin reason_text',
        in_array($machineCold, $stopMachineIds($myRouteData['route_plan']['skipped_stops'] ?? []), true)
            && !in_array('reason_text', $collectKeys($myRouteData['route_plan']['skipped_stops'] ?? []), true)
    );

    $day = $call('GET', '/api/coordinator/restock-route/day', [], $coordinatorToken, ['service_date' => $today]);
    $dayData = $data($day);
    $assert(
        '6.3 El tablero interno no publica costes ni merma',
        $day->getStatusCode() === 200
            && array_intersect(['unit_cost_eur', 'commercial_spoilage_loss_eur'], $collectKeys($dayData)) === []
    );

    $externalControllers = [
        'src/Presentation/Controller/SiteSanitaryController.php',
        'src/Presentation/Controller/QrScanController.php',
        'src/Presentation/Controller/LocationPortalController.php',
        'src/Presentation/Controller/CoordinatorVanRestockOrderController.php',
    ];
    $externalLeaks = [];
    foreach ($externalControllers as $relativePath) {
        $contents = (string)file_get_contents($projectRoot . '/' . $relativePath);
        foreach (['machine_restock_schedules', 'restock_route_daily_overrides', 'restock_route_planner', 'route_plan'] as $needle) {
            if (str_contains($contents, $needle)) {
                $externalLeaks[] = $relativePath . ':' . $needle;
            }
        }
    }
    $assert(
        '6.4 Sede, QR, Portal y A4 no referencian el planificador',
        $externalLeaks === [],
        json_encode($externalLeaks)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Art. VI: contadores de un único service_date y no regresión
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Art. VI · contadores por fecha y no regresión ---\n";

    $ownerRoute = null;
    foreach ($dayData['restocker_routes'] ?? [] as $route) {
        if ((int)($route['restocker_id'] ?? 0) === $ownerId) {
            $ownerRoute = $route;
            break;
        }
    }
    $todayKpis = $dayData['kpis'] ?? [];
    $assert(
        '7.1 El tablero publica el service_date pedido y sus contadores exactos',
        ($dayData['service_date'] ?? null) === $today
            && ($dayData['is_future_projection'] ?? null) === false
            && (int)($todayKpis['scheduled_machines_count'] ?? -1) === 5
            && (int)($todayKpis['completed_count'] ?? -1) === 1
            && (int)($todayKpis['skipped_count'] ?? -1) === 1
            && (int)($todayKpis['blocked_count'] ?? -1) === 1,
        json_encode([
            'esperado' => [$today, false, 5, 1, 1, 1],
            'obtenido' => [
                $dayData['service_date'] ?? null,
                $dayData['is_future_projection'] ?? null,
                $todayKpis['scheduled_machines_count'] ?? null,
                $todayKpis['completed_count'] ?? null,
                $todayKpis['skipped_count'] ?? null,
                $todayKpis['blocked_count'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE)
    );
    $assert(
        '7.2 La ruta del titular separa completada, omitida y bloqueada',
        $ownerRoute !== null
            && in_array($machineCompleted, $stopMachineIds($ownerRoute['completed_stops'] ?? []), true)
            && in_array($machineCold, $stopMachineIds($ownerRoute['skipped_stops'] ?? []), true)
            && in_array($machineQuarantine, $stopMachineIds($ownerRoute['blocked_stops'] ?? []), true)
            && !in_array($machineCold, $stopMachineIds($ownerRoute['stops'] ?? []), true)
    );

    $tomorrowDay = $call('GET', '/api/coordinator/restock-route/day', [], $coordinatorToken, ['service_date' => $tomorrow]);
    $tomorrowData = $data($tomorrowDay);
    $tomorrowKpis = $tomorrowData['kpis'] ?? [];
    $assert(
        '7.3 La proyección futura no hereda completadas ni omitidas de hoy',
        $tomorrowDay->getStatusCode() === 200
            && ($tomorrowData['service_date'] ?? null) === $tomorrow
            && ($tomorrowData['is_future_projection'] ?? null) === true
            && (int)($tomorrowKpis['completed_count'] ?? -1) === 0
            && (int)($tomorrowKpis['skipped_count'] ?? -1) === 0
            && (int)($tomorrowKpis['blocked_count'] ?? -1) === 1
    );

    $locationColumns = $pdo->prepare(
        "SELECT column_name, is_nullable, data_type
           FROM information_schema.columns
          WHERE table_schema = :schema AND table_name = 'locations'
            AND column_name IN ('latitude', 'longitude')
          ORDER BY column_name"
    );
    $locationColumns->execute([':schema' => RRP10_SCHEMA]);
    $locationRows = $locationColumns->fetchAll(PDO::FETCH_ASSOC);
    $assert(
        '7.4 El DDL de locations conserva latitude/longitude NOT NULL',
        count($locationRows) === 2
            && array_reduce($locationRows, static fn (bool $carry, array $row): bool => $carry
                && $row['is_nullable'] === 'NO'
                && in_array($row['data_type'], ['numeric', 'double precision'], true), true)
    );

    $migrationSql = (string)file_get_contents($projectRoot . '/database/supabase_migrations/033_restock_route_planner.sql');
    $assert(
        '7.5 La migración 033 no altera ni borra locations',
        !str_contains($migrationSql, 'ALTER TABLE locations')
            && !str_contains($migrationSql, 'DROP TABLE locations')
            && !str_contains($migrationSql, 'DROP COLUMN')
    );

    $territorialMapPath = $projectRoot . '/public/assets/js/components/CoordinatorTerritorialMapTab.js';
    $territorialMapHash = hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents($territorialMapPath)));
    $assert(
        '7.6 CoordinatorTerritorialMapTab.js permanece byte a byte intacto',
        $territorialMapHash === RRP10_TERRITORIAL_MAP_SHA256,
        $territorialMapHash
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Concurrencia real: FOR UPDATE por fila y liberación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 8. Concurrencia real · FOR UPDATE OF m ---\n";

    $pdoB = ConnectionFactory::getConnection([], true);
    $pdoB->exec('SET search_path TO ' . RRP10_SCHEMA . ', public');
    $pdoB->exec("SET lock_timeout = '300ms'");
    $gatewayA = new PdoRestockRoutePlannerGateway($pdo);
    $gatewayB = new PdoRestockRoutePlannerGateway($pdoB);

    $pdo->beginTransaction();
    $gatewayA->lockMachine($machineCold);
    $pdoB->beginTransaction();
    $lockTimeout = false;
    try {
        $gatewayB->lockMachine($machineCold);
    } catch (PDOException $exception) {
        $lockTimeout = (string)$exception->getCode() === '55P03'
            || (string)($exception->errorInfo[0] ?? '') === '55P03';
    }
    $pdoB->rollBack();
    $assert(
        '8.1 La segunda conexión espera el FOR UPDATE y agota lock_timeout (55P03)',
        $lockTimeout
    );
    $assert(
        '8.2 La primera conexión conserva el bloqueo y la fila intacta',
        (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineCold)->fetchColumn() === 'EXPIRED'
    );
    $pdo->rollBack();

    $pdoB->beginTransaction();
    $releasedLock = $gatewayB->lockMachine($machineCold);
    $pdoB->rollBack();
    $assert(
        '8.3 Liberado el bloqueo, la segunda conexión entra sin espera',
        $releasedLock !== null && $releasedLock['id'] === $machineCold
    );

    $pdo->beginTransaction();
    $gatewayA->lockMachine($machineCold);
    $pdoB->beginTransaction();
    $otherRowLock = $gatewayB->lockMachine($machineTarget);
    $pdoB->rollBack();
    $pdo->rollBack();
    $assert(
        '8.4 El bloqueo es de una fila: otra máquina se bloquea en paralelo',
        $otherRowLock !== null && $otherRowLock['id'] === $machineTarget
    );

    $assert(
        '8.5 No queda ninguna transacción abierta tras la carrera',
        $pdo->inTransaction() === false && $pdoB->inTransaction() === false
    );
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, "\n[FAIL] Excepción inesperada: " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdoB instanceof PDO && $pdoB->inTransaction()) {
            $pdoB->rollBack();
        }
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "\n[FAIL] No se pudo cerrar una transacción abierta: " . $exception->getMessage() . "\n");
    }

    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, RRP10_SCHEMA);
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
