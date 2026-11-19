<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyApiTest · T-RSS-08
 *
 * Certifica la superficie HTTP del Módulo 36 sobre el router real y PostgreSQL:
 *
 *   1. RBAC de las rutas nuevas: 401 sin token, 403 para un rol ajeno.
 *   2. Inicio y resolución L1 del reponedor con idempotencia offline.
 *   3. Volcado de conflicto offline (Art. III.3).
 *   4. Coordinación: sugerencia de reponedores y conmutación de nivel.
 *   5. Opacidad del alta QR: `level1_issue_type` no se expone al ciudadano.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin motor responde código 2. El
 * esquema aislado se retira con `CASCADE` en el `finally`.
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
const RSS08_API_SCHEMA = 't_rss08_api';

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
echo " VENDGUARD · T-RSS-08 · API de la sinergia reponedor-SAT\n";
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
$data = static fn (Response $response): array => $json($response)['data'] ?? [];
$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

$schemaReady = false;
$exitCode = 1;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, RSS08_API_SCHEMA);
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
            ':email' => 'rss08.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rss08.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rss08-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RSS8-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-RSS-08');
    $restockerId = $createUser('rest', 'RESTOCKER', 'Reponedora T-RSS-08');
    $backupId = $createUser('backup', 'RESTOCKER', 'Reponedor Suplente T-RSS-08');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-RSS-08');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-RSS8', 'Zona T-RSS-08', '#2560ff') RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:code, :key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':code' => 'SEDE-RSS8',
        ':key' => 'sede-rss8',
        ':name' => 'Sede T-RSS-08',
        ':address' => 'Carrer de la Sinergia 8, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, 'Modelo RSS8', 'SNACKS', 'Planta baja') RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS8-SNACK-01']);
    $machineId = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS8-SNACK-02']);
    $dispatchMachineId = (int)$machineStmt->fetchColumn();

    $perishableStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, 'Modelo RSS8', 'PERISHABLE_FOOD', 'Planta baja') RETURNING id"
    );
    $perishableStmt->execute([':location_id' => $locationId, ':code' => 'RSS8-PERISH-03']);
    $thermalMachineId = (int)$perishableStmt->fetchColumn();

    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS8-SNACK-04']);
    $refundMachineId = (int)$machineStmt->fetchColumn();

    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:id, :zone, 'PRIMARY', TRUE)"
    )->execute([':id' => $restockerId, ':zone' => $zoneId]);

    $pdo->prepare(
        "INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:id, :zone, TRUE)"
    )->execute([':id' => $technicianId, ':zone' => $zoneId]);

    $shiftStmt = $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES (:id, CURRENT_DATE, 'ON_SHIFT', CURRENT_TIMESTAMP) RETURNING id"
    );
    $shiftStmt->execute([':id' => $restockerId]);
    $shiftStmt->execute([':id' => $backupId]);
    $shiftStmt->execute([':id' => $technicianId]);

    $seedIncident = static function (string $code, int $machineId) use ($pdo, $locationId, $restockerId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (
                ticket_code, machine_id, location_id, category, description, urgency, status,
                dispatch_level, level1_issue_type, assigned_technician_id, assigned_at,
                sla_tier_snapshot, sla_target_minutes, cycle_started_at
             ) VALUES (
                :code, :machine, :location, 'PRODUCT_JAM', 'Envase atascado en el carril de salida',
                'MEDIUM', 'ASSIGNED', 'L1_RESTOCKER', 'MINOR_SPIRAL_JAM', :restocker, CURRENT_TIMESTAMP,
                'STANDARD', 1440, CURRENT_TIMESTAMP
             ) RETURNING id"
        );
        $stmt->execute([
            ':code' => $code,
            ':machine' => $machineId,
            ':location' => $locationId,
            ':restocker' => $restockerId,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $incidentId = $seedIncident('INC-2026-8801', $machineId);
    $dispatchIncidentId = $seedIncident('INC-2026-8802', $dispatchMachineId);
    $thermalIncidentId = $seedIncident('INC-2026-8803', $thermalMachineId);
    $refundIncidentId = $seedIncident('INC-2026-8804', $refundMachineId);

    $seedCustody = static function (int $seedIncidentId) use ($pdo, $restockerId): void {
        $stmt = $pdo->prepare(
            "INSERT INTO incident_assignments (
                incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
                assignment_source, assigned_at
             ) VALUES (
                :incident, :restocker, 'Reponedora T-RSS-08', 'RSS8-REST', 'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP
             )"
        );
        $stmt->execute([':incident' => $seedIncidentId, ':restocker' => $restockerId]);
    };
    $seedCustody($thermalIncidentId);
    $seedCustody($refundIncidentId);

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));

    $router = AppRouter::create();
    $call = static function (string $method, string $path, array $body = [], ?string $token = null, array $query = []) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers, []));
    };

    echo "\n--- 1. RBAC ---\n";
    $anonymous = $call('POST', "/api/restocker/incidents/{$incidentId}/start", []);
    $assert('Sin token el inicio L1 responde 401', $anonymous->getStatusCode() === 401, 'status=' . $anonymous->getStatusCode());

    $wrongRole = $call('POST', "/api/restocker/incidents/{$incidentId}/start", [], $coordinatorToken);
    $assert('Coordinación no entra en la superficie del reponedor', $wrongRole->getStatusCode() === 403, 'status=' . $wrongRole->getStatusCode());

    $wrongCoordinator = $call('GET', "/api/coordinator/incidents/{$incidentId}/suggested-restockers", [], $technicianToken);
    $assert('El técnico SAT no entra en Coordinación', $wrongCoordinator->getStatusCode() === 403, 'status=' . $wrongCoordinator->getStatusCode());

    echo "\n--- 2. Inicio y resolución L1 ---\n";
    $start = $call('POST', "/api/restocker/incidents/{$incidentId}/start", [
        'client_mutation_id' => 'rss08-start-0001',
        'client_occurred_at' => '2026-11-18 09:00:00+00',
    ], $restockerToken);
    $startBody = $data($start);
    $assert('El reponedor asignado inicia el L1', $start->getStatusCode() === 200 && ($startBody['status'] ?? '') === 'IN_PROGRESS', 'status=' . $start->getStatusCode());

    $replay = $call('POST', "/api/restocker/incidents/{$incidentId}/start", [
        'client_mutation_id' => 'rss08-start-0001',
        'client_occurred_at' => '2026-11-18 09:00:00+00',
    ], $restockerToken);
    $assert('El reenvío devuelve idempotent_replay', ($data($replay)['idempotent_replay'] ?? false) === true);

    $resolve = $call('POST', "/api/restocker/incidents/{$incidentId}/resolve", [
        'machine_id' => $machineId,
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'client_mutation_id' => 'rss08-resolve-0001',
        'client_occurred_at' => '2026-11-18 09:10:00+00',
    ], $restockerToken);
    $assert('La resolución L1 responde 200', $resolve->getStatusCode() === 200, 'status=' . $resolve->getStatusCode() . ' ' . $errorCode($resolve));

    echo "\n--- 3. Volcado de conflicto ---\n";
    $dump = $call('POST', '/api/restocker/incidents/conflict-dump', [
        'client_mutation_id' => 'rss08-conflict-0001',
        'client_occurred_at' => '2026-11-18 09:20:00+00',
        'stop_type' => 'INCIDENT',
        'stop_id' => $incidentId,
        'operation_type' => 'RESOLVE_L1_INCIDENT',
        'conflict' => ['http_status' => 409, 'error_code' => 'SANITARY_L1_RESOLUTION_BLOCKED'],
    ], $restockerToken);
    $assert('El volcado de conflicto responde 201', $dump->getStatusCode() === 201, 'status=' . $dump->getStatusCode());

    echo "\n--- 4. Coordinación ---\n";
    $suggested = $call('GET', "/api/coordinator/incidents/{$dispatchIncidentId}/suggested-restockers", [], $coordinatorToken);
    $assert('La sugerencia de reponedores responde 200', $suggested->getStatusCode() === 200, 'status=' . $suggested->getStatusCode());

    $dispatch = $call('POST', "/api/coordinator/incidents/{$dispatchIncidentId}/dispatch-level", [
        'dispatch_level' => 'L2_SAT',
        'assignee_id' => $technicianId,
    ], $coordinatorToken);
    $assert('La conmutación a L2 responde 200', $dispatch->getStatusCode() === 200, 'status=' . $dispatch->getStatusCode() . ' ' . $errorCode($dispatch));
    $assert('El nivel conmutado es L2_SAT', ($data($dispatch)['dispatch_level'] ?? '') === 'L2_SAT');

    $filtered = $call('GET', '/api/coordinator/incidents', [], $coordinatorToken, ['dispatch_level' => 'L1_RESTOCKER']);
    $filteredRows = $data($filtered);
    $allLevel1 = $filteredRows !== [] && count(array_filter(
        $filteredRows,
        static fn (array $row): bool => ($row['dispatch_level'] ?? '') === 'L1_RESTOCKER'
    )) === count($filteredRows);
    $assert('El filtro dispatch_level=L1 devuelve solo avisos L1', $allLevel1, 'filas=' . count($filteredRows));

    $invalidFilter = $call('GET', '/api/coordinator/incidents', [], $coordinatorToken, ['dispatch_level' => 'L3']);
    $assert('Un nivel de filtro inválido responde 400', $invalidFilter->getStatusCode() === 400);

    $technicianRoute = $call('GET', '/api/technician/my-route', [], $technicianToken);
    $stops = $data($technicianRoute);
    $coincidence = $stops[0]['site_coincidence'] ?? null;
    $assert(
        'La ruta del técnico publica site_coincidence con la reponedora en turno',
        $technicianRoute->getStatusCode() === 200 && is_array($coincidence)
            && in_array($restockerId, array_column($coincidence, 'user_id'), true),
        'status=' . $technicianRoute->getStatusCode()
    );

    echo "\n--- 5. Alta QR y opacidad ---\n";
    $qr = $call('POST', '/api/qr/report', [
        'machine_code' => 'RSS8-SNACK-01',
        'category' => 'PRODUCT_JAM',
        'description' => 'No puedo retirar el producto del carril',
        'level1_issue_type' => 'MINOR_SPIRAL_JAM',
    ]);
    $qrBody = $data($qr);
    $assert('El alta QR responde 200', $qr->getStatusCode() === 200, 'status=' . $qr->getStatusCode() . ' ' . $errorCode($qr));
    $assert('La respuesta QR no expone dispatch_level', !array_key_exists('dispatch_level', $qrBody) && !array_key_exists('level1_issue_type', $qrBody));

    $restockerRoute = $call('GET', '/api/restocker/my-route', [], $restockerToken);
    $restockerRouteData = $data($restockerRoute);
    $assert(
        'La ruta del reponedor publica el contexto L1 y la coincidencia en sede',
        $restockerRoute->getStatusCode() === 200
            && is_array($restockerRouteData['assigned_l1_incidents'] ?? null)
            && is_array($restockerRouteData['l1_taxonomy_catalog'] ?? null)
            && is_array($restockerRouteData['site_coincidence'] ?? null),
        'status=' . $restockerRoute->getStatusCode()
    );
    $routeMachine = $restockerRouteData['machines'][0] ?? [];
    $assert(
        'Cada máquina declara su aviso L1 y el SAT abierto sin filtrar el nivel al portal',
        array_key_exists('active_l1_incident', $routeMachine)
            && array_key_exists('has_active_sat_incident', $routeMachine),
        'machine_keys=' . implode(',', array_keys($routeMachine))
    );
    $qrIncidentId = (int)($qrBody['incident_id'] ?? 0);
    $storedStmt = $pdo->prepare('SELECT dispatch_level, level1_issue_type FROM incidents WHERE id = :id');
    $storedStmt->execute([':id' => $qrIncidentId]);
    $stored = $storedStmt->fetch();
    $assert('El alta QR se persiste como L1_RESTOCKER', is_array($stored) && $stored['dispatch_level'] === 'L1_RESTOCKER');

    echo "\n--- 6. Auto-escalado QR real (FIX-36-01) ---\n";

    $qrThermal = $call('POST', '/api/qr/report', [
        'machine_code' => 'RSS8-PERISH-03',
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina no enfría y el producto perecedero está a temperatura ambiente',
    ]);
    $qrThermalBody = $data($qrThermal);
    $assert(
        '6.1 El reporte QR térmico se absorbe en el L1 existente',
        $qrThermal->getStatusCode() === 200 && ($qrThermalBody['merged'] ?? false) === true,
        'status=' . $qrThermal->getStatusCode() . ' ' . $errorCode($qrThermal)
    );

    $thermalStmt = $pdo->prepare(
        'SELECT dispatch_level, category, status, assigned_technician_id, urgency, sla_target_minutes
           FROM incidents WHERE id = :id'
    );
    $thermalStmt->execute([':id' => $thermalIncidentId]);
    $thermalRow = $thermalStmt->fetch();
    $assert(
        '6.2 La absorción QR promociona a L2_SAT con categoría térmica',
        is_array($thermalRow)
            && $thermalRow['dispatch_level'] === 'L2_SAT'
            && $thermalRow['category'] === 'TEMPERATURE_COLD',
        'row=' . json_encode($thermalRow)
    );
    $assert(
        '6.3 El update posterior no revierte la asignación ni el estado',
        is_array($thermalRow)
            && (int)($thermalRow['assigned_technician_id'] ?? 0) === $technicianId
            && $thermalRow['status'] === 'ASSIGNED',
        'assigned=' . ($thermalRow['assigned_technician_id'] ?? 'null') . ' status=' . ($thermalRow['status'] ?? 'null')
    );
    $assert(
        '6.4 El SLA se endurece a 240 min tras la promoción',
        is_array($thermalRow)
            && $thermalRow['urgency'] === 'CRITICAL'
            && (int)$thermalRow['sla_target_minutes'] === 240,
        'urgency=' . ($thermalRow['urgency'] ?? 'null') . ' sla=' . ($thermalRow['sla_target_minutes'] ?? 'null')
    );

    $custodyStmt = $pdo->prepare(
        "SELECT technician_id, assignment_source, unassignment_reason_type
           FROM incident_assignments
          WHERE incident_id = :id
          ORDER BY id ASC"
    );
    $custodyStmt->execute([':id' => $thermalIncidentId]);
    $custodyRows = $custodyStmt->fetchAll();
    $closedRestockerCustody = array_filter(
        $custodyRows,
        static fn (array $row): bool => (int)$row['technician_id'] === $restockerId
            && $row['unassignment_reason_type'] === 'QR_AUTO_ESCALATED_TO_SAT'
    );
    $openTechnicianCustody = array_filter(
        $custodyRows,
        static fn (array $row): bool => (int)$row['technician_id'] === $technicianId
            && $row['assignment_source'] === 'QR_AUTO_ESCALATION_TO_SAT'
            && $row['unassignment_reason_type'] === null
    );
    $assert(
        '6.5 La custodia del reponedor se cierra y la del SAT se abre',
        $closedRestockerCustody !== [] && $openTechnicianCustody !== [],
        'rows=' . json_encode($custodyRows)
    );

    $outboxStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notification_outbox
          WHERE incident_id = :id
            AND template IN ('TECHNICIAN_ASSIGNED', 'TECHNICIAN_UNASSIGNED')"
    );
    $outboxStmt->execute([':id' => $thermalIncidentId]);
    $assert(
        '6.6 El auto-escalado encola el aviso de asignación',
        (int)$outboxStmt->fetchColumn() >= 1
    );

    $coordinatorAlertStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notification_outbox
          WHERE incident_id = :id
            AND template = 'COLD_CHAIN_IMMEDIATE_ALERT'
            AND recipient_role = 'COORDINATOR'"
    );
    $coordinatorAlertStmt->execute([':id' => $thermalIncidentId]);
    $assert(
        '6.7 El auto-escalado térmico alerta a Coordinación por cadena de frío',
        (int)$coordinatorAlertStmt->fetchColumn() >= 1
    );

    $qrRefund = $call('POST', '/api/qr/report', [
        'machine_code' => 'RSS8-SNACK-04',
        'category' => 'OTHER',
        'description' => 'No me devuelve el cambio y he pagado con Bizum',
        'retained_money_amount' => 5.00,
        'refund_method' => 'BIZUM',
    ]);
    $qrRefundBody = $data($qrRefund);
    $assert(
        '6.8 El reporte QR con reclamación digital se absorbe en el L1 existente',
        $qrRefund->getStatusCode() === 200 && ($qrRefundBody['merged'] ?? false) === true,
        'status=' . $qrRefund->getStatusCode() . ' ' . $errorCode($qrRefund)
    );

    $refundStmt = $pdo->prepare(
        'SELECT dispatch_level, category, status, assigned_technician_id
           FROM incidents WHERE id = :id'
    );
    $refundStmt->execute([':id' => $refundIncidentId]);
    $refundRow = $refundStmt->fetch();
    $assert(
        '6.9 El método de reembolso BIZUM auto-escala aunque el importe sea <= 10 €',
        is_array($refundRow)
            && $refundRow['dispatch_level'] === 'L2_SAT'
            && $refundRow['category'] === 'OTHER'
            && (int)($refundRow['assigned_technician_id'] ?? 0) === $technicianId,
        'row=' . json_encode($refundRow)
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    if ($schemaReady) {
        try {
            $pdo->exec('DROP SCHEMA IF EXISTS ' . RSS08_API_SCHEMA . ' CASCADE');
        } catch (Throwable $cleanupFailure) {
            fwrite(STDERR, '[LIMPIEZA] ' . $cleanupFailure->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallidas: {$failures}\n";
if ($failures === 0 && $assertions > 0) {
    echo " RESULTADO: 100% EN VERDE. API DE LA SINERGIA CERTIFICADA (T-RSS-08).\n";
    $exitCode = 0;
} else {
    echo " RESULTADO: FALLO.\n";
}
echo "======================================================================\n";

exit($exitCode);
