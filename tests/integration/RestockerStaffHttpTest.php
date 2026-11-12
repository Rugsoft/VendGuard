<?php

declare(strict_types=1);

/**
 * RestockerStaffHttpTest · T-RES-15
 *
 * Certifica por HTTP las dos reglas del Módulo 31 que hasta ahora solo estaban
 * cubiertas en servicio:
 *
 *   1. `C1` (EARS 01.2): `PUT /api/coordinator/staff/{id}` rechaza con
 *      `409 ROLE_CHANGE_FORBIDDEN` cualquier cambio de rol y no toca la fila.
 *   2. `C3` (EARS 01.3): `PUT /api/coordinator/staff/{id}` con
 *      `is_active: false` cierra el turno abierto en la misma transacción,
 *      escribe `FORCE_CLOSE_SHIFT` en la bitácora y en `audit_log`, y deja la
 *      cuenta inactiva.
 *
 * Además comprueba el alta de `RESTOCKER` (`RF-01.1`): `operator_code`
 * obligatorio y único, `is_on_call` forzado a `FALSE` y rechazo de la guardia
 * con `422 RESTOCKER_CANNOT_BE_ON_CALL`, junto con el RBAC de Coordinación.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL local y trabaja en un esquema
 * aislado; sin motor sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.battery && php tests/integration/RestockerStaffHttpTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AdminStaffService;
use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const RES15_STAFF_SCHEMA = 't_res15_staff';

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
echo " VENDGUARD · T-RES-15 · Personal Interno por HTTP: C1 y C3\n";
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

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, RES15_STAFF_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, bool $isActive = true) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ucfirst($role) . ' ' . ucfirst($suffix),
            ':email' => 'res15.' . $suffix . '@vendguard.internal',
            ':email_key' => 'res15.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('res15-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'R15-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
            ':is_on_call' => 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianId = $createUser('sat', 'TECHNICIAN');
    $secondTechnicianId = $createUser('sat2', 'TECHNICIAN');
    $restockerId = $createUser('repo', 'RESTOCKER');

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));

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
    // 1. RBAC de las rutas de personal
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de /api/coordinator/staff ---\n";

    $anonymous = $call('GET', '/api/coordinator/staff');
    $assert(
        '1.1 Sin token el listado de personal responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && ($json($anonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $technicianCreate = $call('POST', '/api/coordinator/staff', [
        'name' => 'Intruso Técnico',
        'email' => 'res15.intruso@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
        'operator_code' => 'REP-900',
    ], $technicianToken);
    $assert(
        '1.2 Un técnico no puede dar de alta personal (403)',
        $technicianCreate->getStatusCode() === 403
    );

    $restockerUpdate = $call('PUT', '/api/coordinator/staff/' . $restockerId, [
        'name' => 'Reponedor Alterado',
    ], $restockerToken);
    $assert(
        '1.3 Un reponedor no puede editar personal (403)',
        $restockerUpdate->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Alta de RESTOCKER
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Alta de RESTOCKER con operator_code ---\n";

    $onCallAttempt = $call('POST', '/api/coordinator/staff', [
        'name' => 'Reponedor Guardia',
        'email' => 'res15.guardia@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
        'operator_code' => 'REP-901',
        'is_on_call' => true,
    ], $coordinatorToken);
    $assert(
        '2.1 La guardia de un reponedor se rechaza con 422 RESTOCKER_CANNOT_BE_ON_CALL',
        $onCallAttempt->getStatusCode() === 422
            && ($json($onCallAttempt)['error']['code'] ?? '') === 'RESTOCKER_CANNOT_BE_ON_CALL'
    );

    $missingCode = $call('POST', '/api/coordinator/staff', [
        'name' => 'Reponedor Sin Código',
        'email' => 'res15.sincodigo@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
    ], $coordinatorToken);
    $assert(
        '2.2 Sin operator_code el alta de reponedor responde 400 VALIDATION_ERROR',
        $missingCode->getStatusCode() === 400
            && ($json($missingCode)['error']['code'] ?? '') === 'VALIDATION_ERROR'
    );

    $badCode = $call('POST', '/api/coordinator/staff', [
        'name' => 'Reponedor Código Malo',
        'email' => 'res15.malcodigo@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
        'operator_code' => 'sin-formato',
    ], $coordinatorToken);
    $assert(
        '2.3 Un operator_code fuera de formato responde 400 VALIDATION_ERROR',
        $badCode->getStatusCode() === 400
            && ($json($badCode)['error']['code'] ?? '') === 'VALIDATION_ERROR'
    );

    $created = $call('POST', '/api/coordinator/staff', [
        'name' => 'Reponedor Nuevo',
        'email' => 'res15.nuevo@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
        'operator_code' => 'rep-77',
    ], $coordinatorToken);
    $createdData = $json($created)['data'] ?? [];
    $createdId = (int)($createdData['id'] ?? 0);
    $assert(
        '2.4 El alta válida responde 201 con el código normalizado y la guardia apagada',
        $created->getStatusCode() === 201
            && $createdId > 0
            && ($createdData['role'] ?? '') === 'RESTOCKER'
            && ($createdData['operator_code'] ?? '') === 'REP-77'
            && ($createdData['is_on_call'] ?? true) === false
    );

    $persisted = $pdo->prepare('SELECT operator_code, is_on_call FROM users WHERE id = :id');
    $persisted->execute([':id' => $createdId]);
    $persistedRow = $persisted->fetch(PDO::FETCH_ASSOC) ?: [];
    $auditCreated = $pdo->prepare(
        "SELECT metadata, new_state FROM audit_log WHERE action = 'USER_CREATED' AND entity_id = :id ORDER BY id DESC LIMIT 1"
    );
    $auditCreated->execute([':id' => $createdId]);
    $auditRow = $auditCreated->fetch(PDO::FETCH_ASSOC) ?: [];
    $auditState = json_decode((string)($auditRow['new_state'] ?? '{}'), true) ?: [];
    $assert(
        '2.5 El código queda persistido, sin guardia, y la auditoría registra el rol',
        ($persistedRow['operator_code'] ?? '') === 'REP-77'
            && in_array($persistedRow['is_on_call'] ?? null, [false, 0, '0', 'f'], true)
            && ($auditState['role'] ?? '') === 'RESTOCKER'
            && ($auditState['operator_code'] ?? '') === 'REP-77',
        'row=' . json_encode($persistedRow) . ' audit=' . json_encode($auditState)
    );

    $duplicate = $call('POST', '/api/coordinator/staff', [
        'name' => 'Reponedor Duplicado',
        'email' => 'res15.duplicado@vendguard.internal',
        'role' => 'RESTOCKER',
        'password' => 'res15-secret',
        'operator_code' => 'REP-77',
    ], $coordinatorToken);
    $assert(
        '2.6 Un operator_code repetido responde 400 VALIDATION_ERROR',
        $duplicate->getStatusCode() === 400
            && ($json($duplicate)['error']['code'] ?? '') === 'VALIDATION_ERROR'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. C1 · El rol es inmutable
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. C1 · Inmutabilidad del rol por HTTP ---\n";

    $roleChange = $call('PUT', '/api/coordinator/staff/' . $technicianId, [
        'role' => 'RESTOCKER',
        'operator_code' => 'REP-902',
    ], $coordinatorToken);
    $roleChangeError = $json($roleChange)['error'] ?? [];
    $assert(
        '3.1 Cambiar el rol responde 409 ROLE_CHANGE_FORBIDDEN con ambos roles',
        $roleChange->getStatusCode() === 409
            && ($roleChangeError['code'] ?? '') === 'ROLE_CHANGE_FORBIDDEN'
            && ($roleChangeError['current_role'] ?? '') === 'TECHNICIAN'
            && ($roleChangeError['requested_role'] ?? '') === 'RESTOCKER'
    );

    $roleAfter = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $roleAfter->execute([':id' => $technicianId]);
    $assert(
        '3.2 La fila conserva el rol original tras el rechazo',
        (string)$roleAfter->fetchColumn() === 'TECHNICIAN'
    );

    $sameRoleUpdate = $call('PUT', '/api/coordinator/staff/' . $technicianId, [
        'role' => 'TECHNICIAN',
        'name' => 'Técnico Renombrado',
    ], $coordinatorToken);
    $assert(
        '3.3 Reenviar el mismo rol no bloquea la edición (200)',
        $sameRoleUpdate->getStatusCode() === 200
            && ($json($sameRoleUpdate)['data']['name'] ?? '') === 'Técnico Renombrado'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. C3 · Baja con cierre de turno abierto
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. C3 · Baja con cierre automático de turno ---\n";

    $openShift = $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, status, started_at)
         VALUES (:id, 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '2 hours')
         RETURNING id"
    );
    $openShift->execute([':id' => $technicianId]);
    $shiftId = (int)$openShift->fetchColumn();
    $assert('4.0 El escenario deja un turno abierto que cerrar', $shiftId > 0);

    $deactivate = $call('PUT', '/api/coordinator/staff/' . $technicianId, [
        'is_active' => false,
    ], $coordinatorToken);
    $assert(
        '4.1 La baja responde 200 y deja la cuenta inactiva',
        $deactivate->getStatusCode() === 200
            && ($json($deactivate)['data']['is_active'] ?? true) === false
    );

    $closedShift = $pdo->prepare(
        'SELECT status, closed_at, is_force_closed, close_reason FROM technician_shifts WHERE id = :id'
    );
    $closedShift->execute([':id' => $shiftId]);
    $closedRow = $closedShift->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.2 El turno queda cerrado, forzado y con el motivo contractual',
        ($closedRow['status'] ?? '') === 'OFF_SHIFT'
            && ($closedRow['closed_at'] ?? null) !== null
            && (bool)($closedRow['is_force_closed'] ?? false) === true
            && ($closedRow['close_reason'] ?? '') === AdminStaffService::DEACTIVATION_CLOSE_REASON
    );

    $shiftLog = $pdo->prepare(
        "SELECT actor_user_id, reason FROM technician_shift_logs
          WHERE shift_id = :shift AND event_type = 'FORCE_CLOSE_SHIFT' ORDER BY id DESC LIMIT 1"
    );
    $shiftLog->execute([':shift' => $shiftId]);
    $shiftLogRow = $shiftLog->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.3 La bitácora registra FORCE_CLOSE_SHIFT con el coordinador como actor',
        (int)($shiftLogRow['actor_user_id'] ?? 0) === $coordinatorId
            && ($shiftLogRow['reason'] ?? '') === AdminStaffService::DEACTIVATION_CLOSE_REASON
    );

    $auditClose = $pdo->prepare(
        "SELECT user_id, metadata FROM audit_log
          WHERE action = 'FORCE_CLOSE_SHIFT' AND entity_id = :id ORDER BY id DESC LIMIT 1"
    );
    $auditClose->execute([':id' => $technicianId]);
    $auditCloseRow = $auditClose->fetch(PDO::FETCH_ASSOC) ?: [];
    $auditCloseMetadata = json_decode((string)($auditCloseRow['metadata'] ?? '{}'), true) ?: [];
    $assert(
        '4.4 audit_log registra el cierre con su motivo y el actor',
        (int)($auditCloseRow['user_id'] ?? 0) === $coordinatorId
            && ($auditCloseRow['metadata']['reason'] ?? $auditCloseMetadata['reason'] ?? '') === AdminStaffService::DEACTIVATION_CLOSE_REASON
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Listado de personal
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Listado de personal interno ---\n";

    $staffList = $call('GET', '/api/coordinator/staff', [], $coordinatorToken, ['role' => 'RESTOCKER']);
    $staffRows = $json($staffList)['data'] ?? [];
    $codes = array_map(static fn (array $row): string => (string)($row['operator_code'] ?? ''), $staffRows);
    $assert(
        '5.1 El listado filtra por rol y publica operator_code',
        $staffList->getStatusCode() === 200
            && in_array('REP-77', $codes, true)
            && count(array_filter($staffRows, static fn (array $row): bool => ($row['role'] ?? '') !== 'RESTOCKER')) === 0,
        'status=' . $staffList->getStatusCode() . ' rows=' . json_encode($staffRows)
    );
} catch (Throwable $exception) {
    $failures++;
    echo "  [FAIL] La suite abortó: " . $exception->getMessage() . "\n";
    echo $exception->getFile() . ':' . $exception->getLine() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RES15_STAFF_SCHEMA);
    } catch (Throwable) {
        // La limpieza no debe ocultar el veredicto.
    }
}

echo "\n======================================================================\n";
echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
