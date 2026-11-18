<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyConstitutionalTest · T-RSS-09
 *
 * Certifica el blindaje constitucional del Módulo 36 sobre PostgreSQL y el
 * router real:
 *
 *   1. Art. II / QA-CONST-01: el motor rechaza un L1 térmico o eléctrico y la
 *      resolución L1 en cuarentena responde 409.
 *   2. Art. V.1: diagnóstico y acción de menos de 20 caracteres se rechazan.
 *   3. Art. V.3 / QA-CONST-02: escalar sobre un L2 preexistente absorbe la
 *      evidencia sin crear otro ticket ni robar la custodia del técnico.
 *   4. Art. V.4 / QA-CONST-03: QR no expone nivel operativo ni tipo L1.
 *   5. Art. III.1 / III.3: la custodia activa es única y las operaciones dejan
 *      rastro en `audit_log` y `incident_history`.
 *
 * CATEGORÍA: `new_engine`. Sin PostgreSQL sale con código 2. El esquema aislado
 * se retira con `CASCADE` en el `finally`.
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
const RSS09_CONST_SCHEMA = 't_rss09_const';

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
echo " VENDGUARD · T-RSS-09 · Blindaje constitucional de la sinergia L1\n";
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
    PostgresFixtureBuilder::createIsolationSchema($pdo, RSS09_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE) RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'rss09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rss09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rss09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RSS9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-RSS-09');
    $restockerId = $createUser('rest', 'RESTOCKER', 'Reponedora T-RSS-09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-RSS-09');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-RSS9', 'Zona T-RSS-09', '#2560ff') RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:code, :key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':code' => 'SEDE-RSS9',
        ':key' => 'sede-rss9',
        ':name' => 'Sede T-RSS-09',
        ':address' => 'Carrer de la Constitució 9, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
         VALUES (:location_id, :code, 'Modelo RSS9', 'SNACKS', 'Planta baja', :sanitary) RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS9-SNACK-01', ':sanitary' => 'OK']);
    $machineA = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS9-SNACK-02', ':sanitary' => 'OK']);
    $machineB = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS9-SNACK-03', ':sanitary' => 'OK']);
    $machineC = (int)$machineStmt->fetchColumn();

    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:id, :zone, 'PRIMARY', TRUE)"
    )->execute([':id' => $restockerId, ':zone' => $zoneId]);

    $seedIncident = static function (string $code, int $machine, string $level, string $category, string $status, ?int $tech) use ($pdo, $locationId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (
                ticket_code, machine_id, location_id, category, description, urgency, status,
                dispatch_level, level1_issue_type, assigned_technician_id, assigned_at,
                sla_tier_snapshot, sla_target_minutes, cycle_started_at
             ) VALUES (
                :code, :machine, :location, :category, 'Avería de certificación constitucional',
                'MEDIUM', :status, :level, :issue, :tech, CURRENT_TIMESTAMP,
                'STANDARD', 1440, CURRENT_TIMESTAMP
             ) RETURNING id"
        );
        $stmt->execute([
            ':code' => $code,
            ':machine' => $machine,
            ':location' => $locationId,
            ':category' => $category,
            ':status' => $status,
            ':level' => $level,
            ':issue' => $level === 'L1_RESTOCKER' ? 'MINOR_SPIRAL_JAM' : null,
            ':tech' => $tech,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $incidentA = $seedIncident('INC-2026-9901', $machineA, 'L1_RESTOCKER', 'PRODUCT_JAM', 'ASSIGNED', $restockerId);
    $incidentB = $seedIncident('INC-2026-9902', $machineB, 'L2_SAT', 'PRODUCT_JAM', 'ASSIGNED', $technicianId);
    $pdo->prepare(
        "INSERT INTO incident_assignments
            (incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
             assigned_by, assigned_by_name_snapshot, assignment_source, assigned_at)
         VALUES (:incident, :tech, 'Técnico SAT T-RSS-09', 'RSS9-SAT', :by, 'Coordinación T-RSS-09',
                 'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP)"
    )->execute([':incident' => $incidentB, ':tech' => $technicianId, ':by' => $coordinatorId]);

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $router = AppRouter::create();
    $call = static function (string $method, string $path, array $body = [], ?string $token = null) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, [], $body, $headers, []));
    };

    echo "\n--- 1. Art. II: coherencia L1 / categoría y cuarentena ---\n";
    $blockedByCheck = false;
    try {
        $pdo->exec("UPDATE incidents SET category = 'ELECTRICAL_OFF' WHERE id = {$incidentA}");
    } catch (PDOException $exception) {
        $blockedByCheck = str_contains($exception->getMessage(), 'chk_incidents_l1_dispatch_coherence')
            || str_contains($exception->getMessage(), 'check constraint');
    }
    $assert('El motor rechaza un L1 eléctrico (Art. II)', $blockedByCheck);

    $start = $call('POST', "/api/restocker/incidents/{$incidentA}/start", [], $restockerToken);
    $assert('El aviso L1 arranca antes de la guarda sanitaria', $start->getStatusCode() === 200);
    $pdo->exec("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = {$machineA}");
    $quarantined = $call('POST', "/api/restocker/incidents/{$incidentA}/resolve", [
        'machine_id' => $machineA,
        'sanitary_status' => 'QUARANTINE',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
    ], $restockerToken);
    $assert(
        'La resolución L1 en cuarentena responde 409 SANITARY_L1_RESOLUTION_BLOCKED',
        $quarantined->getStatusCode() === 409 && $errorCode($quarantined) === 'SANITARY_L1_RESOLUTION_BLOCKED',
        'status=' . $quarantined->getStatusCode() . ' code=' . $errorCode($quarantined)
    );

    echo "\n--- 2. Art. V.1: textos mínimos ---\n";
    $short = $call('POST', "/api/restocker/incidents/{$incidentA}/resolve", [
        'machine_id' => $machineA,
        'sanitary_status' => 'OK',
        'diagnosis' => 'Corto',
        'action' => 'Nada',
    ], $restockerToken);
    $assert(
        'Los textos de resolución de menos de 20 caracteres se rechazan',
        $short->getStatusCode() === 422 && $errorCode($short) === 'RESOLUTION_TEXT_TOO_SHORT',
        'status=' . $short->getStatusCode() . ' code=' . $errorCode($short)
    );

    $pdo->exec("UPDATE machines SET sanitary_status = 'OK' WHERE id = {$machineA}");
    $resolved = $call('POST', "/api/restocker/incidents/{$incidentA}/resolve", [
        'machine_id' => $machineA,
        'sanitary_status' => 'OK',
        'diagnosis' => 'Envase atascado en la espiral del carril central',
        'action' => 'Liberación manual del envase y reacomodo del carril',
        'client_mutation_id' => 'rss09-resolve-0001',
        'client_occurred_at' => '2026-11-18 10:00:00+00',
    ], $restockerToken);
    $assert('La resolución válida sella RESOLVED', $resolved->getStatusCode() === 200, 'status=' . $resolved->getStatusCode());

    echo "\n--- 3. Art. V.3 / QA-CONST-02: absorción sin robar custodia ---\n";
    $before = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE machine_id = {$machineB}")->fetchColumn();
    $escalate = $call('POST', "/api/restocker/machines/{$machineB}/escalate-to-sat", [
        'category' => 'ELECTRICAL_OFF',
        'internal_note' => 'La puerta deja de cerrar y el producto queda expuesto al exterior.',
        'photo_path' => '/uploads/incidents/rss09_door.jpg',
        'client_mutation_id' => 'rss09-escalate-0001',
        'client_occurred_at' => '2026-11-18 10:10:00+00',
    ], $restockerToken);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE machine_id = {$machineB}")->fetchColumn();
    $assert('El escalado sobre un L2 preexistente responde 201', $escalate->getStatusCode() === 201, 'status=' . $escalate->getStatusCode() . ' ' . $errorCode($escalate));
    $assert('No se crea un segundo ticket en la máquina', $before === 1 && $after === 1, "antes={$before} después={$after}");
    $custody = $pdo->query(
        "SELECT technician_id FROM incident_assignments WHERE incident_id = {$incidentB} AND unassigned_at IS NULL"
    )->fetchColumn();
    $assert('La custodia del técnico SAT se conserva', (int)$custody === $technicianId, 'custodio=' . var_export($custody, true));
    $internalRows = $pdo->query(
        "SELECT is_internal, comment_text FROM incident_comments WHERE incident_id = {$incidentB}"
    )->fetchAll();
    $internal = count(array_filter(
        $internalRows,
        static fn (array $row): bool => (bool)$row['is_internal']
            && str_contains((string)$row['comment_text'], 'puerta deja de cerrar')
    ));
    $assert(
        'La nota interna de taller se guarda como is_internal',
        $internal === 1,
        'comentarios=' . count($internalRows) . ' internos=' . $internal . ' muestra=' . json_encode($internalRows, JSON_UNESCAPED_UNICODE)
    );

    echo "\n--- 4. Art. V.4 / QA-CONST-03: opacidad QR ---\n";
    $qr = $call('POST', '/api/qr/report', [
        'machine_code' => 'RSS9-SNACK-03',
        'category' => 'PRODUCT_JAM',
        'description' => 'El producto no cae al pulsar la selección',
        'level1_issue_type' => 'MINOR_SPIRAL_JAM',
    ]);
    $qrBody = $data($qr);
    $qrKeys = array_keys($qrBody);
    $assert(
        'El QR no expone dispatch_level ni level1_issue_type',
        !in_array('dispatch_level', $qrKeys, true) && !in_array('level1_issue_type', $qrKeys, true)
    );
    $notes = (int)$pdo->query(
        "SELECT COUNT(*) FROM incident_comments WHERE incident_id = {$incidentB} AND author_type = 'REPORTER'"
    )->fetchColumn();
    $assert('Las notas internas no se mezclan con comentarios públicos', $notes === 0, 'públicos=' . $notes);

    echo "\n--- 5. Art. III.1 / III.3: custodia única y auditoría ---\n";
    $duplicateCustody = false;
    try {
        $pdo->exec(
            "INSERT INTO incident_assignments
                (incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
                 assigned_by, assigned_by_name_snapshot, assignment_source, assigned_at)
             VALUES ({$incidentB}, {$technicianId}, 'Duplicado', 'DUP', {$coordinatorId}, 'Coordinación',
                     'COORDINATOR_ASSIGN', CURRENT_TIMESTAMP)"
        );
    } catch (PDOException $exception) {
        $duplicateCustody = str_contains($exception->getMessage(), 'uq_incident_assignments_active_custody')
            || str_contains($exception->getMessage(), 'unique');
    }
    $assert('El motor impide dos custodios activos simultáneos (Art. V.3)', $duplicateCustody);

    $audit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE entity_id IN ({$incidentA}, {$incidentB})
          AND action IN ('RESOLVE_L1_INCIDENT', 'RESTOCKER_ESCALATION_TO_SAT', 'ESCALATE_INCIDENT_TO_SAT')"
    )->fetchColumn();
    $assert('Las operaciones dejan rastro en audit_log (Art. III.3)', $audit >= 1, 'filas=' . $audit);

    $history = (int)$pdo->query(
        "SELECT COUNT(*) FROM incident_history WHERE incident_id = {$incidentA} AND to_status = 'RESOLVED'"
    )->fetchColumn();
    $assert('La resolución deja historia de estado', $history === 1);

    echo "\n--- 6. QA-LIM-02 / Art. V.6: promoción real en reapertura ---\n";
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'RSS9-SNACK-04', ':sanitary' => 'OK']);
    $machineD = (int)$machineStmt->fetchColumn();
    $resolvedIncident = $seedIncident('INC-2026-9904', $machineD, 'L1_RESTOCKER', 'PRODUCT_JAM', 'RESOLVED', $restockerId);
    $pdo->exec("UPDATE incidents SET resolved_at = CURRENT_TIMESTAMP - INTERVAL '5 hours' WHERE id = {$resolvedIncident}");
    $reopened = (new \VendGuard\Infrastructure\Repository\PdoIncidentRepository($pdo))
        ->reopen($resolvedIncident, 'El mismo carril vuelve a atascarse tras la resolución');
    $assert('La reapertura L1 < 48 h promueve a L2_SAT en el repositorio', $reopened->getDispatchLevel()->value === 'L2_SAT');
    $promotedHistory = (int)$pdo->query(
        "SELECT COUNT(*) FROM incident_history WHERE incident_id = {$resolvedIncident}
          AND action_note LIKE '%promovida automáticamente de Nivel 1%'"
    )->fetchColumn();
    $assert('La promoción deja la nota exigida por Art. V.6', $promotedHistory === 1);

    $deleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM incident_comments WHERE incident_id = {$incidentB} AND is_internal = TRUE");
    } catch (PDOException $exception) {
        $deleteBlocked = true;
    }
    $assert('El hilo interno no se borra físicamente (Art. III.1)', $deleteBlocked);
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    if ($schemaReady) {
        try {
            $pdo->exec('DROP SCHEMA IF EXISTS ' . RSS09_CONST_SCHEMA . ' CASCADE');
        } catch (Throwable $cleanupFailure) {
            fwrite(STDERR, '[LIMPIEZA] ' . $cleanupFailure->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallidas: {$failures}\n";
if ($failures === 0 && $assertions > 0) {
    echo " RESULTADO: 100% EN VERDE. BLINDAJE CONSTITUCIONAL CERTIFICADO (T-RSS-09).\n";
    $exitCode = 0;
} else {
    echo " RESULTADO: FALLO.\n";
}
echo "======================================================================\n";

exit($exitCode);
