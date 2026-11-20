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
 *   6. Auto-escalado QR real (FIX-36-01).
 *   7. Relevo L1 en cierre, ausencia y baja (FIX-36-02).
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin motor responde código 2. El
 * esquema aislado se retira con `CASCADE` en el `finally`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AdminStaffService;
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
$uploadedFiles = [];

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
    $shiftRestockerId = $createUser('shift', 'RESTOCKER', 'Reponedora de Cierre T-RSS-08');
    $deactivateRestockerId = $createUser('deact', 'RESTOCKER', 'Reponedora de Baja T-RSS-08');
    $staffRestockerId = $createUser('staff', 'RESTOCKER', 'Reponedora de Staff T-RSS-08');
    $guardRestockerId = $createUser('guard', 'RESTOCKER', 'Reponedor de Guardia T-RSS-08');
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

    // H-36-09: sede crítica 24x7 para certificar la herencia del SLA en la rama B.
    $criticalLocationStmt = $pdo->prepare(
        "INSERT INTO locations (site_code, site_code_key, name, address, zone_id, sla_tier)
         VALUES ('SEDE-RSS8-CRIT', 'sede-rss8-crit', 'Sede Crítica T-RSS-08',
                 'Carrer de la Sinergia 8, Barcelona', :zone_id, 'CRITICAL_INFRASTRUCTURE_24_7')
         RETURNING id"
    );
    $criticalLocationStmt->execute([':zone_id' => $zoneId]);
    $criticalLocationId = (int)$criticalLocationStmt->fetchColumn();

    $criticalMachineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, 'RSS8-SNACK-19', 'Modelo RSS8', 'SNACKS', 'Planta baja') RETURNING id"
    );
    $criticalMachineStmt->execute([':location_id' => $criticalLocationId]);
    $criticalMachineId = (int)$criticalMachineStmt->fetchColumn();

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

    // PR22-04: dedicated machines keep the lifecycle guard isolated from the
    // fixtures used by the later QR and coordinator flows.
    $stateMachineIds = [];
    foreach (['RSS8-STATE-21', 'RSS8-STATE-22', 'RSS8-STATE-23', 'RSS8-STATE-24', 'RSS8-STATE-25'] as $stateMachineCode) {
        $machineStmt->execute([':location_id' => $locationId, ':code' => $stateMachineCode]);
        $stateMachineIds[$stateMachineCode] = (int)$machineStmt->fetchColumn();
    }

    $fixtureMachines = [];
    foreach ([
        'RSS8-SNACK-05',
        'RSS8-SNACK-06',
        'RSS8-SNACK-07',
        'RSS8-SNACK-08',
        'RSS8-SNACK-09',
        'RSS8-SNACK-10',
        'RSS8-SNACK-11',
        'RSS8-SNACK-12',
        'RSS8-SNACK-13',
        'RSS8-SNACK-14',
        'RSS8-SNACK-15',
        'RSS8-SNACK-16',
        'RSS8-SNACK-17',
        'RSS8-SNACK-18',
        'RSS8-SNACK-20',
    ] as $fixtureMachineCode) {
        $machineStmt->execute([':location_id' => $locationId, ':code' => $fixtureMachineCode]);
        $fixtureMachines[$fixtureMachineCode] = (int)$machineStmt->fetchColumn();
    }

    // D2 / EARS 01.2: una máquina en cuarentena no puede nacer en L1.
    $pdo->prepare("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = :id")
        ->execute([':id' => $fixtureMachines['RSS8-SNACK-14']]);

    $taxonomyCauseStmt = $pdo->prepare(
        "INSERT INTO root_causes (code, category, name, is_active)
         VALUES ('RC-RSS8-SPIRAL', 'PRODUCT_JAM', 'Causa sembrada T-RSS-08', TRUE) RETURNING id"
    );
    $taxonomyCauseStmt->execute();
    $taxonomyCauseId = (int)$taxonomyCauseStmt->fetchColumn();

    $taxonomySolutionStmt = $pdo->prepare(
        "INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
         VALUES (:root_cause_id, 'SS-RSS8-CLEAR', 'Solución sembrada T-RSS-08', TRUE, TRUE) RETURNING id"
    );
    $taxonomySolutionStmt->execute([':root_cause_id' => $taxonomyCauseId]);
    $taxonomySolutionId = (int)$taxonomySolutionStmt->fetchColumn();

    // H-36-08: pareja activa de otra categoría para probar el rechazo 422.
    $incompatibleCauseStmt = $pdo->prepare(
        "INSERT INTO root_causes (code, category, name, is_active)
         VALUES ('RC-RSS8-CONSUMABLES', 'OTHER', 'Consumibles sembrados T-RSS-08', TRUE) RETURNING id"
    );
    $incompatibleCauseStmt->execute();
    $incompatibleCauseId = (int)$incompatibleCauseStmt->fetchColumn();

    $incompatibleSolutionStmt = $pdo->prepare(
        "INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
         VALUES (:root_cause_id, 'SS-RSS8-CONSUMABLES', 'Reposición de consumibles sembrada T-RSS-08', TRUE, TRUE) RETURNING id"
    );
    $incompatibleSolutionStmt->execute([':root_cause_id' => $incompatibleCauseId]);
    $incompatibleSolutionId = (int)$incompatibleSolutionStmt->fetchColumn();

    $zoneAssignmentStmt = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:id, :zone, 'PRIMARY', TRUE)"
    );
    foreach ([
        $restockerId,
        $backupId,
        $shiftRestockerId,
        $deactivateRestockerId,
        $staffRestockerId,
    ] as $zoneRestockerId) {
        $zoneAssignmentStmt->execute([':id' => $zoneRestockerId, ':zone' => $zoneId]);
    }

    $pdo->prepare(
        "INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:id, :zone, TRUE)"
    )->execute([':id' => $technicianId, ':zone' => $zoneId]);

    $shiftStmt = $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES (:id, CURRENT_DATE, 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '2 hours') RETURNING id"
    );
    $shiftStmt->execute([':id' => $restockerId]);
    $shiftStmt->execute([':id' => $backupId]);
    $shiftStmt->execute([':id' => $shiftRestockerId]);
    $shiftStmt->execute([':id' => $staffRestockerId]);
    $shiftStmt->execute([':id' => $technicianId]);

    $seedIncident = static function (
        string $code,
        int $machineId,
        ?int $assigneeId = null,
        string $status = 'ASSIGNED'
    ) use ($pdo, $locationId, $restockerId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (
                ticket_code, machine_id, location_id, category, description, urgency, status,
                dispatch_level, level1_issue_type, assigned_technician_id, assigned_at,
                sla_tier_snapshot, sla_target_minutes, cycle_started_at
             ) VALUES (
                :code, :machine, :location, 'PRODUCT_JAM', 'Envase atascado en el carril de salida',
                'MEDIUM', :status, 'L1_RESTOCKER', 'MINOR_SPIRAL_JAM', :restocker, CURRENT_TIMESTAMP,
                'STANDARD', 1440, CURRENT_TIMESTAMP
             ) RETURNING id"
        );
        $stmt->execute([
            ':code' => $code,
            ':machine' => $machineId,
            ':location' => $locationId,
            ':restocker' => $assigneeId ?? $restockerId,
            ':status' => $status,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $incidentId = $seedIncident('INC-2026-8801', $machineId);
    $dispatchIncidentId = $seedIncident('INC-2026-8802', $dispatchMachineId);
    $thermalIncidentId = $seedIncident('INC-2026-8803', $thermalMachineId);
    $refundIncidentId = $seedIncident('INC-2026-8804', $refundMachineId);
    $shiftBlockIncidentId = $seedIncident(
        'INC-2026-8805',
        $fixtureMachines['RSS8-SNACK-05'],
        $shiftRestockerId,
        'IN_PROGRESS'
    );
    $shiftHandoverIncidentId = $seedIncident(
        'INC-2026-8806',
        $fixtureMachines['RSS8-SNACK-06'],
        $shiftRestockerId
    );
    $absenceIncidentId = $seedIncident(
        'INC-2026-8807',
        $fixtureMachines['RSS8-SNACK-07'],
        $restockerId
    );
    $deactivateIncidentId = $seedIncident(
        'INC-2026-8808',
        $fixtureMachines['RSS8-SNACK-08'],
        $deactivateRestockerId
    );
    $staffDeactivateIncidentId = $seedIncident(
        'INC-2026-8809',
        $fixtureMachines['RSS8-SNACK-09'],
        $staffRestockerId
    );
    $taxonomyIncidentId = $seedIncident('INC-2026-8810', $fixtureMachines['RSS8-SNACK-17']);

    // H-36-07: ticket nacido en L2 sin motivo, para demostrar que el despacho
    // manual a L1 sella la taxonomía en la misma fila de `incidents`.
    $manualL1IncidentId = $seedIncident('INC-2026-8811', $fixtureMachines['RSS8-SNACK-18']);
    $pdo->prepare(
        "UPDATE incidents
            SET dispatch_level = 'L2_SAT',
                level1_issue_type = NULL,
                assigned_technician_id = NULL,
                assigned_at = NULL,
                status = 'REGISTERED'
          WHERE id = :id"
    )->execute([':id' => $manualL1IncidentId]);

    // PR22-04: each non-triage state must be rejected without mutation.
    $protectedDispatchStates = [
        'RESOLVED' => $seedIncident('INC-2026-8812', $stateMachineIds['RSS8-STATE-21'], $guardRestockerId, 'RESOLVED'),
        'CLOSED' => $seedIncident('INC-2026-8813', $stateMachineIds['RSS8-STATE-22'], $guardRestockerId, 'CLOSED'),
        'IN_PROGRESS' => $seedIncident('INC-2026-8814', $stateMachineIds['RSS8-STATE-23'], $guardRestockerId, 'IN_PROGRESS'),
        'PENDING_INFO' => $seedIncident('INC-2026-8815', $stateMachineIds['RSS8-STATE-24'], $guardRestockerId, 'PENDING_INFO'),
    ];
    $pdo->prepare("UPDATE incidents SET resolved_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id = :id")
        ->execute([':id' => $protectedDispatchStates['RESOLVED']]);
    $pdo->prepare("UPDATE incidents SET resolved_at = CURRENT_TIMESTAMP - INTERVAL '2 hours', closed_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id = :id")
        ->execute([':id' => $protectedDispatchStates['CLOSED']]);
    $pdo->prepare("UPDATE incidents SET started_at = CURRENT_TIMESTAMP - INTERVAL '45 minutes' WHERE id = :id")
        ->execute([':id' => $protectedDispatchStates['IN_PROGRESS']]);
    $pdo->prepare("UPDATE incidents SET paused_at = CURRENT_TIMESTAMP - INTERVAL '30 minutes' WHERE id = :id")
        ->execute([':id' => $protectedDispatchStates['PENDING_INFO']]);

    // PR22-06: a reopened L1 can still be under the restocker's custody after a
    // direct reassignment and must be startable again.
    $reopenedIncidentId = $seedIncident(
        'INC-2026-8816',
        $stateMachineIds['RSS8-STATE-25'],
        $restockerId,
        'REOPENED'
    );
    $pdo->prepare(
        "UPDATE incidents
            SET started_at = CURRENT_TIMESTAMP - INTERVAL '2 days',
                reopened_at = CURRENT_TIMESTAMP - INTERVAL '1 hour'
          WHERE id = :id"
    )->execute([':id' => $reopenedIncidentId]);

    $seedCustody = static function (int $seedIncidentId, ?int $assigneeId = null) use ($pdo, $restockerId): void {
        $stmt = $pdo->prepare(
            "INSERT INTO incident_assignments (
                incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
                assignment_source, assigned_at
             ) VALUES (
                :incident, :restocker, 'Reponedora T-RSS-08', 'RSS8-REST', 'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP
             )"
        );
        $stmt->execute([':incident' => $seedIncidentId, ':restocker' => $assigneeId ?? $restockerId]);
    };
    $seedCustody($thermalIncidentId);
    $seedCustody($refundIncidentId);
    $seedCustody($shiftBlockIncidentId, $shiftRestockerId);
    $seedCustody($shiftHandoverIncidentId, $shiftRestockerId);
    $seedCustody($absenceIncidentId, $restockerId);
    $seedCustody($deactivateIncidentId, $deactivateRestockerId);
    $seedCustody($staffDeactivateIncidentId, $staffRestockerId);
    $seedCustody($taxonomyIncidentId);
    $seedCustody($reopenedIncidentId);

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
    $shiftRestockerToken = $authService->generateInternalToken($userRepo->findById($shiftRestockerId));
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

    $reopenedAt = '2026-11-18 09:05:00+00';
    $reopenedStart = $call('POST', "/api/restocker/incidents/{$reopenedIncidentId}/start", [
        'client_mutation_id' => 'rss08-start-reopened',
        'client_occurred_at' => $reopenedAt,
    ], $restockerToken);
    $reopenedStartBody = $data($reopenedStart);
    $assert(
        'PR22-06: el L1 REOPENED asignado se inicia en IN_PROGRESS',
        $reopenedStart->getStatusCode() === 200
            && ($reopenedStartBody['status'] ?? '') === 'IN_PROGRESS'
            && ($reopenedStartBody['started_at'] ?? '') === $reopenedAt,
        'status=' . $reopenedStart->getStatusCode()
            . ' code=' . $errorCode($reopenedStart)
            . ' body=' . json_encode($reopenedStartBody)
    );

    $reopenedHistoryStmt = $pdo->prepare(
        "SELECT from_status
           FROM incident_history
          WHERE incident_id = :id AND action_note = 'START_L1_INCIDENT'
          ORDER BY id DESC LIMIT 1"
    );
    $reopenedHistoryStmt->execute([':id' => $reopenedIncidentId]);
    $reopenedAuditStmt = $pdo->prepare(
        "SELECT previous_state
           FROM audit_log
          WHERE entity_id = :id AND action = 'START_L1_INCIDENT'
          ORDER BY id DESC LIMIT 1"
    );
    $reopenedAuditStmt->execute([':id' => $reopenedIncidentId]);
    $reopenedAuditState = json_decode((string)$reopenedAuditStmt->fetchColumn(), true);
    $reopenedHistoryFrom = $reopenedHistoryStmt->fetchColumn();
    $reopenedRowStmt = $pdo->prepare(
        'SELECT status, (started_at = :expected::timestamptz) AS started_at_matches
           FROM incidents WHERE id = :id'
    );
    $reopenedRowStmt->execute([':expected' => $reopenedAt, ':id' => $reopenedIncidentId]);
    $reopenedRow = $reopenedRowStmt->fetch();
    $assert(
        'PR22-06: historial y auditoría conservan REOPENED y started_at se refresca',
        is_array($reopenedRow)
            && $reopenedRow['status'] === 'IN_PROGRESS'
            && in_array($reopenedRow['started_at_matches'], [true, 1, '1', 't'], true)
            && $reopenedHistoryFrom === 'REOPENED'
            && is_array($reopenedAuditState)
            && ($reopenedAuditState['status'] ?? '') === 'REOPENED',
        'row=' . json_encode($reopenedRow)
            . ' history=' . json_encode($reopenedHistoryFrom)
            . ' audit=' . json_encode($reopenedAuditState)
    );

    // The reopened fixture stays isolated from the later shift and absence flow.
    $pdo->prepare(
        "UPDATE incidents SET status = 'RESOLVED', resolved_at = CURRENT_TIMESTAMP WHERE id = :id"
    )->execute([':id' => $reopenedIncidentId]);

    $resolve = $call('POST', "/api/restocker/incidents/{$incidentId}/resolve", [
        'machine_id' => $machineId,
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'root_cause_id' => $taxonomyCauseId,
        'standard_solution_id' => $taxonomySolutionId,
        'client_mutation_id' => 'rss08-resolve-0001',
        'client_occurred_at' => '2026-11-18 09:10:00+00',
    ], $restockerToken);
    $assert('La resolución L1 responde 200', $resolve->getStatusCode() === 200, 'status=' . $resolve->getStatusCode() . ' ' . $errorCode($resolve));

    echo "\n--- 2.1 Resolución L1 con snapshots de taxonomía ---\n";
    $taxonomyStart = $call('POST', "/api/restocker/incidents/{$taxonomyIncidentId}/start", [
        'client_mutation_id' => 'rss08-start-taxonomy',
        'client_occurred_at' => '2026-11-18 10:00:00+00',
    ], $restockerToken);
    $assert(
        'El aviso L1 de taxonomía se inicia para resolverlo',
        $taxonomyStart->getStatusCode() === 200,
        'status=' . $taxonomyStart->getStatusCode() . ' ' . $errorCode($taxonomyStart)
    );

    $invalidTaxonomy = $call('POST', "/api/restocker/incidents/{$taxonomyIncidentId}/resolve", [
        'machine_id' => $fixtureMachines['RSS8-SNACK-17'],
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'root_cause_id' => $taxonomyCauseId,
        'standard_solution_id' => $taxonomySolutionId + 100000,
    ], $restockerToken);
    $assert(
        'Una pareja de taxonomía inexistente se rechaza con 422',
        $invalidTaxonomy->getStatusCode() === 422 && $errorCode($invalidTaxonomy) === 'INVALID_RESOLUTION_TAXONOMY',
        'status=' . $invalidTaxonomy->getStatusCode() . ' code=' . $errorCode($invalidTaxonomy)
    );

    $wrongCategory = $call('POST', "/api/restocker/incidents/{$taxonomyIncidentId}/resolve", [
        'machine_id' => $fixtureMachines['RSS8-SNACK-17'],
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'root_cause_id' => $incompatibleCauseId,
        'standard_solution_id' => $incompatibleSolutionId,
    ], $restockerToken);
    $assert(
        'Una causa raíz de otra categoría se rechaza con 422 y campo',
        $wrongCategory->getStatusCode() === 422
            && $errorCode($wrongCategory) === 'INVALID_RESOLUTION_TAXONOMY'
            && ($json($wrongCategory)['error']['details']['field'] ?? null) === 'root_cause_id',
        'status=' . $wrongCategory->getStatusCode() . ' code=' . $errorCode($wrongCategory)
    );

    $wrongCategoryStmt = $pdo->prepare('SELECT status, root_cause_id FROM incidents WHERE id = :id');
    $wrongCategoryStmt->execute([':id' => $taxonomyIncidentId]);
    $wrongCategoryRow = $wrongCategoryStmt->fetch();
    $assert(
        'El 422 de categoría no resuelve ni clasifica el aviso',
        is_array($wrongCategoryRow)
            && $wrongCategoryRow['status'] === 'IN_PROGRESS'
            && $wrongCategoryRow['root_cause_id'] === null,
        'row=' . json_encode($wrongCategoryRow)
    );

    $taxonomyResolve = $call('POST', "/api/restocker/incidents/{$taxonomyIncidentId}/resolve", [
        'machine_id' => $fixtureMachines['RSS8-SNACK-17'],
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'root_cause_id' => $taxonomyCauseId,
        'standard_solution_id' => $taxonomySolutionId,
        // Client-supplied snapshots must be ignored in favour of the catalog.
        'root_cause_code' => 'CLIENT_FORGED',
        'root_cause_category' => 'OTHER',
        'root_cause_name' => 'Nombre falseado por el cliente',
        'standard_solution_code' => 'CLIENT_FORGED',
        'standard_solution_name' => 'Solución falseada por el cliente',
        'client_mutation_id' => 'rss08-resolve-taxonomy',
        'client_occurred_at' => '2026-11-18 10:10:00+00',
    ], $restockerToken);
    $assert(
        'La resolución L1 con taxonomía real responde 200',
        $taxonomyResolve->getStatusCode() === 200,
        'status=' . $taxonomyResolve->getStatusCode() . ' ' . $errorCode($taxonomyResolve)
    );

    $taxonomyStmt = $pdo->prepare(
        'SELECT status, root_cause_id, standard_solution_id,
                root_cause_code_snapshot, root_cause_category_snapshot, root_cause_name_snapshot,
                standard_solution_code_snapshot, standard_solution_name_snapshot
           FROM incidents
          WHERE id = :id'
    );
    $taxonomyStmt->execute([':id' => $taxonomyIncidentId]);
    $taxonomyRow = $taxonomyStmt->fetch();
    $assert(
        'La resolución selló los cinco snapshots desde el catálogo, no desde el cliente',
        is_array($taxonomyRow)
            && $taxonomyRow['status'] === 'RESOLVED'
            && (int)$taxonomyRow['root_cause_id'] === $taxonomyCauseId
            && (int)$taxonomyRow['standard_solution_id'] === $taxonomySolutionId
            && $taxonomyRow['root_cause_code_snapshot'] === 'RC-RSS8-SPIRAL'
            && $taxonomyRow['root_cause_category_snapshot'] === 'PRODUCT_JAM'
            && $taxonomyRow['root_cause_name_snapshot'] === 'Causa sembrada T-RSS-08'
            && $taxonomyRow['standard_solution_code_snapshot'] === 'SS-RSS8-CLEAR'
            && $taxonomyRow['standard_solution_name_snapshot'] === 'Solución sembrada T-RSS-08',
        'row=' . json_encode($taxonomyRow)
    );

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
        'urgency_override' => 'CRITICAL',
        'urgency_override_reason' => 'La sede confirma que el cobro está bloqueado y exige prioridad inmediata.',
    ], $coordinatorToken);
    $assert('La conmutación a L2 responde 200', $dispatch->getStatusCode() === 200, 'status=' . $dispatch->getStatusCode() . ' ' . $errorCode($dispatch));
    $dispatchData = $data($dispatch);
    $assert('El nivel conmutado es L2_SAT', ($dispatchData['dispatch_level'] ?? '') === 'L2_SAT');
    $assert(
        'La conmutación aplica la urgencia elegida',
        ($dispatchData['urgency'] ?? '') === 'CRITICAL'
            && ($dispatchData['urgency_changed'] ?? false) === true,
        'body=' . json_encode($dispatchData)
    );

    $dispatchRowStmt = $pdo->prepare(
        'SELECT urgency, sla_target_minutes, sla_target_at FROM incidents WHERE id = :id'
    );
    $dispatchRowStmt->execute([':id' => $dispatchIncidentId]);
    $dispatchRow = $dispatchRowStmt->fetch();
    $assert(
        'La urgencia y el SLA quedan persistidos con la conmutación',
        is_array($dispatchRow)
            && $dispatchRow['urgency'] === 'CRITICAL'
            && (int)$dispatchRow['sla_target_minutes'] === 480
            && $dispatchRow['sla_target_at'] !== null,
        'row=' . json_encode($dispatchRow)
    );

    $dispatchAuditStmt = $pdo->prepare(
        "SELECT previous_state, new_state FROM audit_log
          WHERE entity_id = :id AND action = 'DISPATCH_LEVEL_CHANGE'
          ORDER BY id DESC LIMIT 1"
    );
    $dispatchAuditStmt->execute([':id' => $dispatchIncidentId]);
    $dispatchAudit = $dispatchAuditStmt->fetch();
    $dispatchAuditNew = is_array($dispatchAudit)
        ? json_decode((string)$dispatchAudit['new_state'], true)
        : null;
    $assert(
        'La auditoría registra la urgencia aplicada y su motivo',
        is_array($dispatchAuditNew)
            && ($dispatchAuditNew['urgency'] ?? '') === 'CRITICAL'
            && ($dispatchAuditNew['urgency_override_reason'] ?? '') === 'La sede confirma que el cobro está bloqueado y exige prioridad inmediata.',
        'audit=' . json_encode($dispatchAuditNew)
    );

    $protectedStateStmt = $pdo->prepare(
        'SELECT status, dispatch_level, assigned_technician_id,
                started_at, paused_at, resolved_at, closed_at
           FROM incidents
          WHERE id = :id'
    );
    $protectedCustodyStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM incident_assignments WHERE incident_id = :id'
    );
    $protectedAuditStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_id = :id AND action = 'DISPATCH_LEVEL_CHANGE'"
    );
    foreach ($protectedDispatchStates as $protectedStatus => $protectedIncidentId) {
        $protectedStateStmt->execute([':id' => $protectedIncidentId]);
        $beforeBlocked = $protectedStateStmt->fetch();

        $blocked = $call('POST', "/api/coordinator/incidents/{$protectedIncidentId}/dispatch-level", [
            'dispatch_level' => 'L2_SAT',
            'assignee_id' => $technicianId,
        ], $coordinatorToken);
        $assert(
            "PR22-04: {$protectedStatus} dispatch-level returns 409",
            $blocked->getStatusCode() === 409
                && $errorCode($blocked) === 'INVALID_INCIDENT_STATE_TRANSITION',
            'status=' . $blocked->getStatusCode()
                . ' code=' . $errorCode($blocked)
                . ' body=' . json_encode($json($blocked))
        );

        $protectedStateStmt->execute([':id' => $protectedIncidentId]);
        $afterBlocked = $protectedStateStmt->fetch();
        $protectedCustodyStmt->execute([':id' => $protectedIncidentId]);
        $protectedCustodyCount = (int)$protectedCustodyStmt->fetchColumn();
        $protectedAuditStmt->execute([':id' => $protectedIncidentId]);
        $protectedAuditCount = (int)$protectedAuditStmt->fetchColumn();
        $assert(
            "PR22-04: {$protectedStatus} remains unchanged without custody or audit side effects",
            is_array($afterBlocked)
                && is_array($beforeBlocked)
                && $afterBlocked === $beforeBlocked
                && $protectedCustodyCount === 0
                && $protectedAuditCount === 0,
            'before=' . json_encode($beforeBlocked)
                . ' after=' . json_encode($afterBlocked)
                . ' custody=' . $protectedCustodyCount
                . ' audit=' . $protectedAuditCount
        );
    }

    // H-36-07: el 422 por pareja incompatible no debe mutar el expediente.
    $incompatibleL1 = $call('POST', "/api/coordinator/incidents/{$manualL1IncidentId}/dispatch-level", [
        'dispatch_level' => 'L1_RESTOCKER',
        'assignee_id' => $restockerId,
        'level1_issue_type' => 'EMPTY_CHANGE_TUBES',
    ], $coordinatorToken);
    $assert(
        'Un motivo L1 incompatible responde 422',
        $incompatibleL1->getStatusCode() === 422
            && $errorCode($incompatibleL1) === 'INVALID_LEVEL1_ISSUE_TYPE',
        'status=' . $incompatibleL1->getStatusCode() . ' code=' . $errorCode($incompatibleL1)
    );

    $manualL1Stmt = $pdo->prepare(
        'SELECT dispatch_level, level1_issue_type FROM incidents WHERE id = :id'
    );
    $manualL1Stmt->execute([':id' => $manualL1IncidentId]);
    $manualL1RejectedRow = $manualL1Stmt->fetch();
    $assert(
        'El 422 L1 no muta el nivel ni la taxonomía',
        is_array($manualL1RejectedRow)
            && $manualL1RejectedRow['dispatch_level'] === 'L2_SAT'
            && $manualL1RejectedRow['level1_issue_type'] === null,
        'row=' . json_encode($manualL1RejectedRow)
    );

    // H-36-07: el despacho válido sella el motivo y deja la fila en L1.
    $manualL1 = $call('POST', "/api/coordinator/incidents/{$manualL1IncidentId}/dispatch-level", [
        'dispatch_level' => 'L1_RESTOCKER',
        'assignee_id' => $restockerId,
        'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
    ], $coordinatorToken);
    $assert(
        'El despacho manual a L1 con motivo responde 200',
        $manualL1->getStatusCode() === 200
            && ($data($manualL1)['dispatch_level'] ?? '') === 'L1_RESTOCKER',
        'status=' . $manualL1->getStatusCode() . ' ' . $errorCode($manualL1)
    );

    $manualL1Stmt->execute([':id' => $manualL1IncidentId]);
    $manualL1Row = $manualL1Stmt->fetch();
    $assert(
        'El motivo L1 queda persistido en incidents',
        is_array($manualL1Row)
            && $manualL1Row['dispatch_level'] === 'L1_RESTOCKER'
            && $manualL1Row['level1_issue_type'] === 'STOCKOUT_EMPTY_SLOT',
        'row=' . json_encode($manualL1Row)
    );

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
    // Regresión PR22-01: la máquina debe estar limpia para que el alta cree un
    // expediente nuevo y no se fusione con un L1 sembrado.
    $qr = $call('POST', '/api/qr/report', [
        'machine_code' => 'RSS8-SNACK-20',
        'category' => 'PRODUCT_JAM',
        'description' => 'No puedo retirar el producto del carril',
        'level1_issue_type' => 'MINOR_SPIRAL_JAM',
    ]);
    $qrBody = $data($qr);
    $assert('El alta QR responde 201', $qr->getStatusCode() === 201, 'status=' . $qr->getStatusCode() . ' ' . $errorCode($qr));
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

    // H-36-10: la tarjeta del reponedor indexa la coincidencia con
    // `machine.location_id`, así que el DTO debe publicar el id persistido.
    $routeLocationIds = array_map(
        static fn (array $machine): int => (int)($machine['location_id'] ?? 0),
        $restockerRouteData['machines'] ?? []
    );
    $storedMachineLocations = $pdo
        ->query("SELECT code, location_id FROM machines WHERE code LIKE 'RSS8-%'")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $locationsMatchDatabase = $routeLocationIds !== [];
    foreach ($restockerRouteData['machines'] as $routeMachineRow) {
        if ((int)($storedMachineLocations[$routeMachineRow['code']] ?? 0)
            !== (int)($routeMachineRow['location_id'] ?? 0)) {
            $locationsMatchDatabase = false;
        }
    }
    $assert(
        'H-36-10: cada máquina de la ruta publica el location_id persistido',
        $locationsMatchDatabase,
        'machines=' . json_encode($routeLocationIds)
    );
    $routeCoincidence = $restockerRouteData['site_coincidence'][$locationId] ?? [];
    $assert(
        'H-36-10: la coincidencia se indexa por location_id y contiene al técnico en turno',
        in_array($locationId, $routeLocationIds, true)
            && in_array($technicianId, array_column($routeCoincidence, 'user_id'), true),
        'location=' . $locationId . ' coincidence=' . json_encode($routeCoincidence)
    );
    $qrIncidentId = (int)($qrBody['incident_id'] ?? 0);
    $storedStmt = $pdo->prepare('SELECT dispatch_level, level1_issue_type FROM incidents WHERE id = :id');
    $storedStmt->execute([':id' => $qrIncidentId]);
    $stored = $storedStmt->fetch();
    $assert(
        'El alta QR se persiste como L1_RESTOCKER con su motivo',
        is_array($stored)
            && $stored['dispatch_level'] === 'L1_RESTOCKER'
            && $stored['level1_issue_type'] === 'MINOR_SPIRAL_JAM',
        'row=' . json_encode($stored)
    );

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

    echo "\n--- 7. Relevo L1 en cierre, ausencia y baja (FIX-36-02) ---\n";

    $blockedClose = $call('POST', '/api/technician/shifts/close', [
        'close_reason' => 'Fin de jornada con un aviso L1 en curso',
    ], $shiftRestockerToken);
    $assert(
        '7.1 El cierre con un L1 IN_PROGRESS responde 409 SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1',
        $blockedClose->getStatusCode() === 409
            && $errorCode($blockedClose) === 'SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1',
        'status=' . $blockedClose->getStatusCode() . ' code=' . $errorCode($blockedClose)
    );

    $openShiftStmt = $pdo->prepare(
        'SELECT status, closed_at FROM technician_shifts
          WHERE technician_id = :id AND closed_at IS NULL
          ORDER BY id DESC LIMIT 1'
    );
    $openShiftStmt->execute([':id' => $shiftRestockerId]);
    $openShiftRow = $openShiftStmt->fetch();
    $assert(
        '7.2 El turno bloqueado sigue abierto',
        is_array($openShiftRow) && $openShiftRow['closed_at'] === null,
        'row=' . json_encode($openShiftRow)
    );

    $pdo->prepare("UPDATE incidents SET status = 'RESOLVED' WHERE id = :id")
        ->execute([':id' => $shiftBlockIncidentId]);

    $close = $call('POST', '/api/technician/shifts/close', [
        'close_reason' => 'Fin de jornada tras resolver el aviso',
    ], $shiftRestockerToken);
    $assert(
        '7.3 El cierre con un L1 ASSIGNED responde 200',
        $close->getStatusCode() === 200,
        'status=' . $close->getStatusCode() . ' ' . $errorCode($close)
    );

    $closedShiftStmt = $pdo->prepare(
        'SELECT status, closed_at FROM technician_shifts
          WHERE technician_id = :id ORDER BY id DESC LIMIT 1'
    );
    $closedShiftStmt->execute([':id' => $shiftRestockerId]);
    $closedShiftRow = $closedShiftStmt->fetch();
    $assert(
        '7.4 El turno queda cerrado tras el relevo',
        is_array($closedShiftRow)
            && $closedShiftRow['status'] === 'OFF_SHIFT'
            && $closedShiftRow['closed_at'] !== null,
        'row=' . json_encode($closedShiftRow)
    );

    $shiftIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $shiftIncidentStmt->execute([':id' => $shiftHandoverIncidentId]);
    $shiftIncidentRow = $shiftIncidentStmt->fetch();
    $assert(
        '7.5 El L1 ASSIGNED se releva al reponedor suplente',
        is_array($shiftIncidentRow)
            && $shiftIncidentRow['status'] === 'ASSIGNED'
            && (int)($shiftIncidentRow['assigned_technician_id'] ?? 0) === $backupId,
        'row=' . json_encode($shiftIncidentRow)
    );

    $handoverCustodyStmt = $pdo->prepare(
        'SELECT technician_id, assignment_source, unassignment_reason_type
           FROM incident_assignments
          WHERE incident_id = :id
          ORDER BY id DESC LIMIT 1'
    );
    $handoverCustodyStmt->execute([':id' => $shiftHandoverIncidentId]);
    $handoverCustodyRow = $handoverCustodyStmt->fetch();
    $assert(
        '7.6 La custodia del cierre usa RESTOCKER_SHIFT_HANDOVER',
        is_array($handoverCustodyRow)
            && (int)$handoverCustodyRow['technician_id'] === $backupId
            && $handoverCustodyRow['assignment_source'] === 'RESTOCKER_SHIFT_HANDOVER'
            && $handoverCustodyRow['unassignment_reason_type'] === null,
        'row=' . json_encode($handoverCustodyRow)
    );

    $absence = $call('POST', "/api/coordinator/technicians/{$restockerId}/absences", [
        'reason' => 'SICK_LEAVE',
        'starts_at' => date('Y-m-d'),
        'ends_at' => date('Y-m-d', strtotime('+1 day')),
        'notes' => 'Cobertura de ausencia para FIX-36-02',
    ], $coordinatorToken);
    $assert(
        '7.7 El alta de ausencia vigente responde 201',
        $absence->getStatusCode() === 201,
        'status=' . $absence->getStatusCode() . ' ' . $errorCode($absence)
    );

    $absenceIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $absenceIncidentStmt->execute([':id' => $absenceIncidentId]);
    $absenceIncidentRow = $absenceIncidentStmt->fetch();
    $assert(
        '7.8 El L1 de la ausencia se reasigna al suplente',
        is_array($absenceIncidentRow)
            && $absenceIncidentRow['status'] === 'ASSIGNED'
            && (int)($absenceIncidentRow['assigned_technician_id'] ?? 0) === $backupId,
        'row=' . json_encode($absenceIncidentRow)
    );

    $absenceCustodyStmt = $pdo->prepare(
        'SELECT technician_id, assignment_source, unassignment_reason_type
           FROM incident_assignments
          WHERE incident_id = :id
          ORDER BY id DESC LIMIT 1'
    );
    $absenceCustodyStmt->execute([':id' => $absenceIncidentId]);
    $absenceCustodyRow = $absenceCustodyStmt->fetch();
    $assert(
        '7.9 La custodia de la ausencia usa ABSENCE_COVERAGE_REASSIGN',
        is_array($absenceCustodyRow)
            && (int)$absenceCustodyRow['technician_id'] === $backupId
            && $absenceCustodyRow['assignment_source'] === 'ABSENCE_COVERAGE_REASSIGN'
            && $absenceCustodyRow['unassignment_reason_type'] === null,
        'row=' . json_encode($absenceCustodyRow)
    );

    $directDeactivate = $call('PATCH', "/api/coordinator/users/{$deactivateRestockerId}/deactivate", [], $coordinatorToken);
    $assert(
        '7.10 La baja directa de una reponedora responde 200',
        $directDeactivate->getStatusCode() === 200,
        'status=' . $directDeactivate->getStatusCode() . ' ' . $errorCode($directDeactivate)
    );

    $deactivatedUserStmt = $pdo->prepare('SELECT is_active, deleted_at FROM users WHERE id = :id');
    $deactivatedUserStmt->execute([':id' => $deactivateRestockerId]);
    $deactivatedUserRow = $deactivatedUserStmt->fetch();
    $assert(
        '7.11 La cuenta queda inactiva',
        is_array($deactivatedUserRow)
            && (bool)$deactivatedUserRow['is_active'] === false
            && $deactivatedUserRow['deleted_at'] !== null,
        'row=' . json_encode($deactivatedUserRow)
    );

    $deactivateIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $deactivateIncidentStmt->execute([':id' => $deactivateIncidentId]);
    $deactivateIncidentRow = $deactivateIncidentStmt->fetch();
    $reassignedRestockerId = (int)($deactivateIncidentRow['assigned_technician_id'] ?? 0);
    $reassignedUserStmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :id');
    $reassignedUserStmt->execute([':id' => $reassignedRestockerId]);
    $reassignedUserRow = $reassignedUserStmt->fetch();
    $assert(
        '7.12 El L1 no queda asignado a la cuenta inactiva',
        is_array($deactivateIncidentRow)
            && $deactivateIncidentRow['status'] === 'ASSIGNED'
            && $reassignedRestockerId > 0
            && $reassignedRestockerId !== $deactivateRestockerId
            && is_array($reassignedUserRow)
            && (bool)$reassignedUserRow['is_active'] === true,
        'row=' . json_encode($deactivateIncidentRow) . ' assigned_user=' . json_encode($reassignedUserRow)
    );

    $staffDeactivate = $call('PUT', "/api/coordinator/staff/{$staffRestockerId}", [
        'is_active' => false,
    ], $coordinatorToken);
    $assert(
        '7.13 La baja por staff responde 200',
        $staffDeactivate->getStatusCode() === 200,
        'status=' . $staffDeactivate->getStatusCode() . ' ' . $errorCode($staffDeactivate)
    );

    $staffIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $staffIncidentStmt->execute([':id' => $staffDeactivateIncidentId]);
    $staffIncidentRow = $staffIncidentStmt->fetch();
    $staffReassignedId = (int)($staffIncidentRow['assigned_technician_id'] ?? 0);
    $staffReassignedUserStmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :id');
    $staffReassignedUserStmt->execute([':id' => $staffReassignedId]);
    $staffReassignedUserRow = $staffReassignedUserStmt->fetch();
    $assert(
        '7.14 El L1 se releva antes de cerrar el turno por staff',
        is_array($staffIncidentRow)
            && $staffIncidentRow['status'] === 'ASSIGNED'
            && $staffReassignedId > 0
            && $staffReassignedId !== $staffRestockerId
            && is_array($staffReassignedUserRow)
            && (bool)$staffReassignedUserRow['is_active'] === true,
        'row=' . json_encode($staffIncidentRow) . ' assigned_user=' . json_encode($staffReassignedUserRow)
    );

    $staffShiftStmt = $pdo->prepare(
        'SELECT status, close_reason FROM technician_shifts
          WHERE technician_id = :id ORDER BY id DESC LIMIT 1'
    );
    $staffShiftStmt->execute([':id' => $staffRestockerId]);
    $staffShiftRow = $staffShiftStmt->fetch();
    $assert(
        '7.15 El turno de la baja por staff queda cerrado con el motivo contractual',
        is_array($staffShiftRow)
            && $staffShiftRow['status'] === 'OFF_SHIFT'
            && $staffShiftRow['close_reason'] === AdminStaffService::DEACTIVATION_CLOSE_REASON,
        'row=' . json_encode($staffShiftRow)
    );

    echo "\n--- 8. Escalado real sin assign_technician_id (FIX-36-03) ---\n";

    $evidenceDir = $projectRoot . '/public/uploads/incidents';
    if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0777, true) && !is_dir($evidenceDir)) {
        throw new RuntimeException('No se pudo preparar el directorio de evidencias de la regresión.');
    }
    $validPngBytes = hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082');
    foreach ([
        'rss08_escalation.png',
        'rss08_critical_sla.png',
        'rss08_escalation_unassigned.png',
    ] as $validEvidenceName) {
        $validEvidencePath = $evidenceDir . '/' . $validEvidenceName;
        file_put_contents($validEvidencePath, $validPngBytes);
        $uploadedFiles[] = $validEvidencePath;
    }
    $renamedEvidencePath = $evidenceDir . '/rss08_fake_image.jpg';
    file_put_contents($renamedEvidencePath, 'esto no es una imagen');
    $uploadedFiles[] = $renamedEvidencePath;
    $oversizeEvidencePath = $evidenceDir . '/rss08_oversize.png';
    $oversizeHandle = fopen($oversizeEvidencePath, 'w');
    fseek($oversizeHandle, 5 * 1024 * 1024);
    fwrite($oversizeHandle, 'x');
    fclose($oversizeHandle);
    $uploadedFiles[] = $oversizeEvidencePath;

    $cleanMachineId = $fixtureMachines['RSS8-SNACK-10'];
    $incidentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE machine_id = :machine');
    $incidentCountStmt->execute([':machine' => $cleanMachineId]);
    $photoRejectionsBefore = (int)$incidentCountStmt->fetchColumn();

    $missingPhoto = $call('POST', "/api/restocker/machines/{$cleanMachineId}/escalate-to-sat", [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/rss08_missing.png',
        'client_mutation_id' => 'rss08-photo-missing',
    ], $restockerToken);
    $assert(
        '8.0a Un photo_path inexistente responde 422 INVALID_EVIDENCE_IMAGE',
        $missingPhoto->getStatusCode() === 422
            && $errorCode($missingPhoto) === 'INVALID_EVIDENCE_IMAGE',
        'status=' . $missingPhoto->getStatusCode() . ' code=' . $errorCode($missingPhoto)
    );

    $renamedPhoto = $call('POST', "/api/restocker/machines/{$cleanMachineId}/escalate-to-sat", [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/rss08_fake_image.jpg',
        'client_mutation_id' => 'rss08-photo-renamed',
    ], $restockerToken);
    $assert(
        '8.0b Un archivo renombrado a .jpg responde 422 INVALID_EVIDENCE_IMAGE',
        $renamedPhoto->getStatusCode() === 422
            && $errorCode($renamedPhoto) === 'INVALID_EVIDENCE_IMAGE',
        'status=' . $renamedPhoto->getStatusCode() . ' code=' . $errorCode($renamedPhoto)
    );

    $oversizePhoto = $call('POST', "/api/restocker/machines/{$cleanMachineId}/escalate-to-sat", [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/rss08_oversize.png',
        'client_mutation_id' => 'rss08-photo-oversize',
    ], $restockerToken);
    $assert(
        '8.0c Una evidencia de más de 5 MB responde 422 INVALID_EVIDENCE_IMAGE',
        $oversizePhoto->getStatusCode() === 422
            && $errorCode($oversizePhoto) === 'INVALID_EVIDENCE_IMAGE',
        'status=' . $oversizePhoto->getStatusCode() . ' code=' . $errorCode($oversizePhoto)
    );

    $incidentCountStmt->execute([':machine' => $cleanMachineId]);
    $photoRejectionsAfter = (int)$incidentCountStmt->fetchColumn();
    $assert(
        '8.0d Los rechazos de evidencia no crean expedientes',
        $photoRejectionsAfter === $photoRejectionsBefore,
        'before=' . $photoRejectionsBefore . ' after=' . $photoRejectionsAfter
    );

    $escalation = $call('POST', "/api/restocker/machines/{$cleanMachineId}/escalate-to-sat", [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/rss08_escalation.png',
        'urgency' => 'HIGH',
        'sla_target_minutes' => 480,
        'client_mutation_id' => 'rss08-escalate-0001',
        'client_occurred_at' => '2026-11-18 11:00:00+00',
    ], $restockerToken);
    $escalationData = $data($escalation);
    $assert(
        '8.1 El escalado sin assign_technician_id crea el ticket L2',
        $escalation->getStatusCode() === 201
            && ($escalationData['escalation_outcome'] ?? '') === 'CREATED_NEW_SAT_TICKET',
        'status=' . $escalation->getStatusCode() . ' body=' . json_encode($escalationData)
    );

    $cleanIncidentId = (int)($escalationData['incident_id'] ?? 0);
    $cleanIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $cleanIncidentStmt->execute([':id' => $cleanIncidentId]);
    $cleanIncidentRow = $cleanIncidentStmt->fetch();
    $assert(
        '8.2 El recomendador asigna el mejor técnico SAT de la zona',
        is_array($cleanIncidentRow)
            && $cleanIncidentRow['status'] === 'ASSIGNED'
            && (int)($cleanIncidentRow['assigned_technician_id'] ?? 0) === $technicianId,
        'row=' . json_encode($cleanIncidentRow) . ' technician=' . $technicianId
    );

    $assignmentOutboxStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notification_outbox
          WHERE incident_id = :id
            AND template = 'TECHNICIAN_ASSIGNED'
            AND recipient_user_id = :technician
            AND deleted_at IS NULL"
    );
    $assignmentOutboxStmt->execute([':id' => $cleanIncidentId, ':technician' => $technicianId]);
    $assignmentOutboxCount = (int)$assignmentOutboxStmt->fetchColumn();

    // Composición HTTP real de RF-05.2.2: la tarjeta del técnico recibe el rastro del
    // escalado y la última Nota Interna de Taller con su foto, no un objeto sintético.
    $technicianRouteAfterEscalation = $call('GET', '/api/technician/my-route', [], $technicianToken);
    $technicianStops = $data($technicianRouteAfterEscalation);
    $escalatedStop = null;
    foreach ($technicianStops as $stop) {
        if ((int)($stop['id'] ?? 0) === $cleanIncidentId) {
            $escalatedStop = $stop;
            break;
        }
    }

    $assert(
        '8.3 El escalado real encola el aviso y la ruta técnica publica nota interna y foto',
        $assignmentOutboxCount >= 1
            && $technicianRouteAfterEscalation->getStatusCode() === 200
            && is_array($escalatedStop)
            && (int)($escalatedStop['escalated_from_restocker_id'] ?? 0) === $restockerId
            && ($escalatedStop['escalated_to_sat_at'] ?? null) !== null
            && ($escalatedStop['workshop_internal_note'] ?? '') === 'El carril queda bloqueado y la espiral no gira al pulsar el botón.'
            && ($escalatedStop['workshop_internal_photo_path'] ?? '') === '/uploads/incidents/rss08_escalation.png'
            && ($escalatedStop['photo_path'] ?? '') === '/uploads/incidents/rss08_escalation.png',
        'status=' . $technicianRouteAfterEscalation->getStatusCode()
            . ' stop=' . json_encode($escalatedStop)
    );

    // H-36-09: una máquina limpia de sede CRITICAL_INFRASTRUCTURE_24_7 debe
    // derivar HIGH desde SNACKS + ELECTRICAL_OFF y calcular 240 min, ignorando
    // tanto la urgencia manipulada a LOW como el SLA del cliente.
    $criticalEscalation = $call('POST', "/api/restocker/machines/{$criticalMachineId}/escalate-to-sat", [
        'category' => 'ELECTRICAL_OFF',
        'workshop_internal_note' => 'La máquina no enciende y el display queda completamente apagado.',
        'photo_path' => '/uploads/incidents/rss08_critical_sla.png',
        'urgency' => 'LOW',
        'sla_target_minutes' => 1440,
        'client_mutation_id' => 'rss08-escalate-critical-sla',
        'client_occurred_at' => '2026-11-18 11:10:00+00',
    ], $restockerToken);
    $criticalEscalationData = $data($criticalEscalation);
    $assert(
        '8.3.1 El escalado deriva la urgencia y hereda el SLA de la ubicación',
        $criticalEscalation->getStatusCode() === 201
            && ($criticalEscalationData['urgency'] ?? null) === 'HIGH'
            && ($criticalEscalationData['sla_tier_snapshot'] ?? null) === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (int)($criticalEscalationData['sla_target_minutes'] ?? 0) === 240,
        'status=' . $criticalEscalation->getStatusCode() . ' body=' . json_encode($criticalEscalationData)
    );

    $criticalIncidentStmt = $pdo->prepare(
        'SELECT category, urgency, sla_tier_snapshot, sla_target_minutes
           FROM incidents WHERE id = :id'
    );
    $criticalIncidentStmt->execute([':id' => (int)($criticalEscalationData['incident_id'] ?? 0)]);
    $criticalIncidentRow = $criticalIncidentStmt->fetch();
    $assert(
        '8.3.2 El SLA crítico se persiste con la urgencia derivada y no acepta el payload',
        is_array($criticalIncidentRow)
            && $criticalIncidentRow['category'] === 'ELECTRICAL_OFF'
            && $criticalIncidentRow['urgency'] === 'HIGH'
            && $criticalIncidentRow['sla_tier_snapshot'] === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (int)$criticalIncidentRow['sla_target_minutes'] === 240,
        'row=' . json_encode($criticalIncidentRow)
    );

    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $technicianId]);

    $noCandidateMachineId = $fixtureMachines['RSS8-SNACK-11'];
    $noCandidate = $call('POST', "/api/restocker/machines/{$noCandidateMachineId}/escalate-to-sat", [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'La espiral vuelve a atascarse y no hay técnico disponible en la zona.',
        'photo_path' => '/uploads/incidents/rss08_escalation_unassigned.png',
        'urgency' => 'MEDIUM',
        'client_mutation_id' => 'rss08-escalate-0002',
        'client_occurred_at' => '2026-11-18 11:05:00+00',
    ], $restockerToken);
    $noCandidateData = $data($noCandidate);
    $assert(
        '8.4 Sin candidato el escalado deja el ticket en la cola de Coordinación',
        $noCandidate->getStatusCode() === 201
            && ($noCandidateData['escalation_outcome'] ?? '') === 'CREATED_NEW_SAT_TICKET',
        'status=' . $noCandidate->getStatusCode() . ' body=' . json_encode($noCandidateData)
    );

    $unassignedIncidentId = (int)($noCandidateData['incident_id'] ?? 0);
    $unassignedIncidentStmt = $pdo->prepare(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = :id'
    );
    $unassignedIncidentStmt->execute([':id' => $unassignedIncidentId]);
    $unassignedIncidentRow = $unassignedIncidentStmt->fetch();
    $assert(
        '8.5 Sin candidato el ticket queda REGISTERED sin técnico',
        is_array($unassignedIncidentRow)
            && $unassignedIncidentRow['status'] === 'REGISTERED'
            && $unassignedIncidentRow['assigned_technician_id'] === null,
        'row=' . json_encode($unassignedIncidentRow)
    );

    $coordinationOutboxStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notification_outbox
          WHERE incident_id = :id
            AND template = 'SLA_IMMINENCE_2H_UNASSIGNED'
            AND recipient_role = 'COORDINATOR'
            AND deleted_at IS NULL"
    );
    $coordinationOutboxStmt->execute([':id' => $unassignedIncidentId]);
    $assert(
        '8.6 Sin candidato se encola la alerta de cobertura a Coordinación',
        (int)$coordinationOutboxStmt->fetchColumn() >= 1
    );

    echo "\n--- 9. Alta de Coordinación con pre-clasificación L1 (FIX-36-04) ---\n";

    $level1MachineId = $fixtureMachines['RSS8-SNACK-12'];
    $level1Creation = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $level1MachineId,
        'category' => 'OTHER',
        'description' => 'Falta de vasos y azúcar detectada durante la revisión de la máquina.',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
    ], $coordinatorToken);
    $assert(
        '9.1 El alta de Coordinación con un motivo L1 responde 201',
        $level1Creation->getStatusCode() === 201,
        'status=' . $level1Creation->getStatusCode() . ' code=' . $errorCode($level1Creation)
    );

    $level1CreationData = $data($level1Creation);
    $level1IncidentStmt = $pdo->prepare(
        'SELECT dispatch_level, category, level1_issue_type, status FROM incidents WHERE id = :id'
    );
    $level1IncidentStmt->execute([':id' => (int)($level1CreationData['id'] ?? 0)]);
    $level1IncidentRow = $level1IncidentStmt->fetch();
    $assert(
        '9.2 El expediente nace en L1_RESTOCKER con la categoría mapeada',
        is_array($level1IncidentRow)
            && $level1IncidentRow['dispatch_level'] === 'L1_RESTOCKER'
            && $level1IncidentRow['category'] === 'OTHER'
            && $level1IncidentRow['level1_issue_type'] === 'MISSING_CONSUMABLES'
            && $level1IncidentRow['status'] === 'REGISTERED',
        'row=' . json_encode($level1IncidentRow)
    );

    $thermalMachineId = $fixtureMachines['RSS8-SNACK-13'];
    $thermalCreation = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $thermalMachineId,
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina no enfría y el producto está a temperatura ambiente.',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
    ], $coordinatorToken);
    $assert(
        '9.3 El alta de Coordinación con una exclusión térmica responde 201',
        $thermalCreation->getStatusCode() === 201,
        'status=' . $thermalCreation->getStatusCode() . ' code=' . $errorCode($thermalCreation)
    );

    $thermalCreationData = $data($thermalCreation);
    $thermalCreationStmt = $pdo->prepare(
        'SELECT dispatch_level, category, level1_issue_type FROM incidents WHERE id = :id'
    );
    $thermalCreationStmt->execute([':id' => (int)($thermalCreationData['id'] ?? 0)]);
    $thermalCreationRow = $thermalCreationStmt->fetch();
    $assert(
        '9.4 La exclusión térmica fuerza L2_SAT aunque se informe un motivo L1',
        is_array($thermalCreationRow)
            && $thermalCreationRow['dispatch_level'] === 'L2_SAT'
            && $thermalCreationRow['category'] === 'TEMPERATURE_COLD',
        'row=' . json_encode($thermalCreationRow)
    );

    $quarantineMachineId = $fixtureMachines['RSS8-SNACK-14'];
    $quarantineCreation = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $quarantineMachineId,
        'category' => 'OTHER',
        'description' => 'La máquina está en cuarentena sanitaria y requiere revisión del SAT.',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
    ], $coordinatorToken);
    $quarantineCreationData = $data($quarantineCreation);
    $quarantineStmt = $pdo->prepare(
        'SELECT dispatch_level, category, level1_issue_type FROM incidents WHERE id = :id'
    );
    $quarantineStmt->execute([':id' => (int)($quarantineCreationData['id'] ?? 0)]);
    $quarantineRow = $quarantineStmt->fetch();
    $assert(
        '9.5 La cuarentena sanitaria fuerza L2_SAT aunque se informe un motivo L1',
        $quarantineCreation->getStatusCode() === 201
            && is_array($quarantineRow)
            && $quarantineRow['dispatch_level'] === 'L2_SAT'
            && $quarantineRow['category'] === 'OTHER'
            && $quarantineRow['level1_issue_type'] === 'MISSING_CONSUMABLES',
        'status=' . $quarantineCreation->getStatusCode() . ' row=' . json_encode($quarantineRow)
    );

    $claimMachineId = $fixtureMachines['RSS8-SNACK-15'];
    $claimCreation = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $claimMachineId,
        'category' => 'OTHER',
        'description' => 'Reclamación de dinero retenido superior al límite de conserjería.',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
        'retained_money_amount' => 10.01,
    ], $coordinatorToken);
    $claimCreationData = $data($claimCreation);
    $claimStmt = $pdo->prepare(
        'SELECT dispatch_level, category, level1_issue_type, retained_money_amount FROM incidents WHERE id = :id'
    );
    $claimStmt->execute([':id' => (int)($claimCreationData['id'] ?? 0)]);
    $claimRow = $claimStmt->fetch();
    $assert(
        '9.6 Una reclamación superior a 10 € fuerza L2_SAT aunque se informe un motivo L1',
        $claimCreation->getStatusCode() === 201
            && is_array($claimRow)
            && $claimRow['dispatch_level'] === 'L2_SAT'
            && $claimRow['category'] === 'OTHER'
            && $claimRow['level1_issue_type'] === 'MISSING_CONSUMABLES'
            && (float)$claimRow['retained_money_amount'] === 10.01,
        'status=' . $claimCreation->getStatusCode() . ' row=' . json_encode($claimRow)
    );

    $digitalMachineId = $fixtureMachines['RSS8-SNACK-16'];
    $digitalCreation = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $digitalMachineId,
        'category' => 'OTHER',
        'description' => 'La devolución solicitada se abonará por un método digital.',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
        'refund_method' => 'BIZUM',
    ], $coordinatorToken);
    $digitalCreationData = $data($digitalCreation);
    $digitalStmt = $pdo->prepare(
        'SELECT dispatch_level, category, level1_issue_type FROM incidents WHERE id = :id'
    );
    $digitalStmt->execute([':id' => (int)($digitalCreationData['id'] ?? 0)]);
    $digitalRow = $digitalStmt->fetch();
    $assert(
        '9.7 Un método de devolución digital fuerza L2_SAT aunque se informe un motivo L1',
        $digitalCreation->getStatusCode() === 201
            && is_array($digitalRow)
            && $digitalRow['dispatch_level'] === 'L2_SAT'
            && $digitalRow['category'] === 'OTHER'
            && $digitalRow['level1_issue_type'] === 'MISSING_CONSUMABLES',
        'status=' . $digitalCreation->getStatusCode() . ' row=' . json_encode($digitalRow)
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    foreach ($uploadedFiles as $uploadedFile) {
        if (is_string($uploadedFile) && is_file($uploadedFile)) {
            @unlink($uploadedFile);
        }
    }
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
