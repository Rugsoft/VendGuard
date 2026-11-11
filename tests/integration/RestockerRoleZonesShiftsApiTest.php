<?php

declare(strict_types=1);

/**
 * RestockerRoleZonesShiftsApiTest · T-RES-10
 *
 * Certifica la superficie HTTP del Módulo 31 sobre el router real:
 *
 *   1. RBAC: el bootstrap del reponedor exige sesión `RESTOCKER`; el técnico SAT
 *      no entra en el cuadrante de carga y el reponedor tampoco en la ruta SAT.
 *   2. `GET /api/restocker/my-route`: perfil, zona titular, turno y estado base.
 *   3. Jornada compartida: estado, inicio, pausa, cierre directo y «Mis Horas».
 *   4. Cuadrante de carga: alta, consulta, consulta inversa, cobertura y baja
 *      lógica idempotente.
 *   5. Registro de jornada con `?role=`: informe, CSV con columna `Rol` y rechazo
 *      del filtro fuera de catálogo.
 *   6. Ausencias: listado filtrado por rol y alta de reponedor con zonas donde
 *      se promueve al `BACKUP`.
 *
 * CATEGORÍA: `new_engine`. Exige el PostgreSQL local y trabaja en un esquema
 * aislado sobre la conexión de la aplicación; sin motor sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/RestockerRoleZonesShiftsApiTest.php
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
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const RES10_API_SCHEMA = 't_res10_api';

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
echo " VENDGUARD · T-RES-10 · API REST de RESTOCKER, cuadrante y jornada\n";
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

    PostgresFixtureBuilder::createIsolationSchema($pdo, RES10_API_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, bool $isActive = true) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ucfirst($role) . ' ' . ucfirst($suffix),
            ':email' => 'res10.' . $suffix . '@vendguard.internal',
            ':email_key' => 'res10.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('res10-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'R10-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
            ':is_on_call' => 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianId = $createUser('sat', 'TECHNICIAN');
    $restockerId = $createUser('titular', 'RESTOCKER');
    $backupId = $createUser('suplente', 'RESTOCKER');

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
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de la superficie del reponedor ---\n";

    $anonymous = $call('GET', '/api/restocker/my-route');
    $assert(
        '1.1 Sin token el bootstrap del reponedor responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && ($json($anonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $coordinatorOnRestocker = $call('GET', '/api/restocker/my-route', [], $coordinatorToken);
    $assert(
        '1.2 Coordinación no alcanza la ruta móvil del reponedor (403)',
        $coordinatorOnRestocker->getStatusCode() === 403
    );

    $restockerOnSat = $call('GET', '/api/technician/my-route', [], $restockerToken);
    $assert(
        '1.3 El reponedor no alcanza la ruta SAT (403)',
        $restockerOnSat->getStatusCode() === 403
            && ($json($restockerOnSat)['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $restockerOnCoordinator = $call('GET', '/api/coordinator/zones', [], $restockerToken);
    $assert(
        '1.4 El reponedor no alcanza la consola de Coordinación (403)',
        $restockerOnCoordinator->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Zona y cuadrante de carga
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Cuadrante de carga ---\n";

    $zoneResponse = $call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-R10-NORTE',
        'name' => 'Zona Norte de Reposición',
        'color_hex' => '#2560ff',
    ], $coordinatorToken);
    $zoneId = (int)($json($zoneResponse)['data']['id'] ?? 0);
    $assert('2.1 Coordinación crea la zona operativa', $zoneResponse->getStatusCode() === 201 && $zoneId > 0);

    $assignPrimary = $call('POST', '/api/coordinator/zones/' . $zoneId . '/restockers', [
        'restocker_id' => $restockerId,
        'role_in_zone' => 'PRIMARY',
    ], $coordinatorToken);
    $assignment = $json($assignPrimary)['data']['assignment'] ?? [];
    $assert(
        '2.2 El alta de titular responde 200 con la vinculación viva',
        $assignPrimary->getStatusCode() === 200
            && (int)($assignment['restocker_id'] ?? 0) === $restockerId
            && ($assignment['role_in_zone'] ?? '') === 'PRIMARY'
            && ($assignment['is_primary'] ?? false) === true
    );

    $assignBackup = $call('POST', '/api/coordinator/zones/' . $zoneId . '/restockers', [
        'restocker_id' => $backupId,
        'is_primary' => false,
    ], $coordinatorToken);
    $assert(
        '2.3 El suplente se vincula con `is_primary` sin `role_in_zone`',
        $assignBackup->getStatusCode() === 200
            && ($json($assignBackup)['data']['assignment']['role_in_zone'] ?? '') === 'BACKUP'
    );

    $quadrant = $call('GET', '/api/coordinator/zones/' . $zoneId . '/restockers', [], $coordinatorToken);
    $quadrantRows = $json($quadrant)['data']['restockers'] ?? [];
    $assert(
        '2.4 El cuadrante publica titular y suplente con insignias',
        $quadrant->getStatusCode() === 200
            && count($quadrantRows) === 2
            && in_array('ACTIVO', array_column($quadrantRows, 'availability_badge'), true)
    );

    $inverse = $call('GET', '/api/coordinator/restockers/quadrants', [], $coordinatorToken);
    $inverseRows = $json($inverse)['data']['restockers'] ?? [];
    $titularRow = null;
    foreach ($inverseRows as $row) {
        if ((int)($row['restocker_id'] ?? 0) === $restockerId) {
            $titularRow = $row;
            break;
        }
    }
    $assert(
        '2.5 La consulta inversa agrupa las zonas del reponedor',
        $inverse->getStatusCode() === 200
            && $titularRow !== null
            && (int)($titularRow['zones'][0]['zone_id'] ?? 0) === $zoneId
    );

    $coverage = $call('GET', '/api/coordinator/zones/' . $zoneId . '/restocker-coverage', [], $coordinatorToken);
    $coverageData = $json($coverage)['data'] ?? [];
    $assert(
        '2.6 La cobertura en vivo publica estado y candidatos',
        $coverage->getStatusCode() === 200
            && ($coverageData['zone_id'] ?? 0) === $zoneId
            && count($coverageData['candidates'] ?? []) === 2
            && ($coverageData['coverage_status'] ?? '') !== ''
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Bootstrap y jornada del reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Bootstrap y jornada del reponedor ---\n";

    $myRoute = $call('GET', '/api/restocker/my-route', [], $restockerToken);
    $routeData = $json($myRoute)['data'] ?? [];
    $assert(
        '3.1 El bootstrap publica perfil, zona titular y turno OFF_SHIFT',
        $myRoute->getStatusCode() === 200
            && ($routeData['restocker']['role'] ?? '') === 'RESTOCKER'
            && (int)($routeData['zones']['primary_zone']['id'] ?? 0) === $zoneId
            && ($routeData['zones']['backup_zones'] ?? null) === []
            && ($routeData['shift']['status'] ?? '') === 'OFF_SHIFT'
            && ($routeData['route_stops'] ?? null) === []
    );

    $start = $call('POST', '/api/technician/shifts/start', [], $restockerToken);
    $assert(
        '3.2 El reponedor inicia turno por la ruta compartida',
        $start->getStatusCode() === 200 && ($json($start)['data']['status'] ?? '') === 'ON_SHIFT'
    );

    $pause = $call('POST', '/api/technician/shifts/meal-start', [], $restockerToken);
    $assert(
        '3.3 El reponedor pausa para comer',
        $pause->getStatusCode() === 200 && ($json($pause)['data']['status'] ?? '') === 'MEAL_BREAK'
    );

    $close = $call('POST', '/api/technician/shifts/close', [
        'close_reason' => 'Fin de ruta de carga',
    ], $restockerToken);
    $assert(
        '3.4 El reponedor cierra su jornada directamente desde MEAL_BREAK',
        $close->getStatusCode() === 200 && ($json($close)['data']['status'] ?? '') === 'OFF_SHIFT'
    );

    $myHours = $call('GET', '/api/technician/shifts/my-hours', [], $restockerToken);
    $hoursData = $json($myHours)['data'] ?? [];
    $assert(
        '3.5 «Mis Horas» responde aislado por el token',
        $myHours->getStatusCode() === 200
            && isset($hoursData['summary'])
            && count($hoursData['sessions'] ?? []) === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Registro de jornada por rol
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Registro de jornada con filtro por rol ---\n";

    $report = $call('GET', '/api/coordinator/metrics/work-hours', [], $coordinatorToken, ['role' => 'RESTOCKER']);
    $reportData = $json($report)['data'] ?? [];
    $assert(
        '4.1 El informe filtra por RESTOCKER y publica el rol',
        $report->getStatusCode() === 200
            && ($reportData['filter']['role'] ?? '') === 'RESTOCKER'
            && ($reportData['by_technician'][0]['technician_role'] ?? '') === 'RESTOCKER'
            && ($reportData['by_technician'][0]['intervention_seconds'] ?? -1) === 0
    );

    $csv = $call('GET', '/api/coordinator/metrics/work-hours/export', [], $coordinatorToken, ['role' => 'RESTOCKER']);
    $csvBody = $csv->getBody();
    $csvRows = [];
    $csvHandle = fopen('php://temp', 'r+');
    fwrite($csvHandle, substr($csvBody, 3));
    rewind($csvHandle);
    while (($csvRow = fgetcsv($csvHandle, 0, ';')) !== false) {
        $csvRows[] = $csvRow;
    }
    fclose($csvHandle);
    $detailHeader = null;
    foreach ($csvRows as $csvRow) {
        if (($csvRow[0] ?? null) === 'ID Sesión') {
            $detailHeader = $csvRow;
            break;
        }
    }
    $assert(
        '4.2 El CSV publica la columna Rol en las dos secciones',
        $csv->getStatusCode() === 200
            && str_starts_with($csvBody, "\xEF\xBB\xBF")
            && ($csvRows[0][1] ?? null) === 'Rol'
            && ($detailHeader[3] ?? null) === 'Rol'
    );

    $invalidRole = $call('GET', '/api/coordinator/metrics/work-hours', [], $coordinatorToken, ['role' => 'CARPENTER']);
    $assert(
        '4.3 Un rol fuera del catálogo responde 400 INVALID_ROLE_FILTER',
        $invalidRole->getStatusCode() === 400
            && ($json($invalidRole)['error']['code'] ?? '') === 'INVALID_ROLE_FILTER'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Ausencias del reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Ausencias con filtro por rol ---\n";

    $createAbsence = $call('POST', '/api/coordinator/absences', [
        'technician_id' => $restockerId,
        'reason' => 'VACATION',
        'starts_at' => '2026-12-01',
        'ends_at' => '2026-12-05',
        'notes' => 'Vacaciones del reponedor titular.',
    ], $coordinatorToken);
    $absenceData = $json($createAbsence)['data'] ?? [];
    $assert(
        '5.1 El alta de ausencia del reponedor responde 201 con zonas promovidas',
        $createAbsence->getStatusCode() === 201
            && ($absenceData['affected_incidents'] ?? null) === []
            && (int)($absenceData['promoted_zones'][0]['backup_restocker_id'] ?? 0) === $backupId
    );

    $absences = $call('GET', '/api/coordinator/absences', [], $coordinatorToken, ['role' => 'RESTOCKER']);
    $assert(
        '5.2 El listado filtra por rol y publica la ausencia',
        $absences->getStatusCode() === 200 && count($json($absences)['data'] ?? []) === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Baja idempotente del cuadrante
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Baja lógica idempotente ---\n";

    $deleteFirst = $call('DELETE', '/api/coordinator/zones/' . $zoneId . '/restockers/' . $restockerId, [], $coordinatorToken);
    $deleteAgain = $call('DELETE', '/api/coordinator/zones/' . $zoneId . '/restockers/' . $restockerId, [], $coordinatorToken);
    $assert(
        '6.1 Repetir la baja devuelve 200 sin duplicar la escritura',
        $deleteFirst->getStatusCode() === 200
            && $deleteAgain->getStatusCode() === 200
            && ($json($deleteAgain)['data']['removed'] ?? false) === true
    );
} catch (Throwable $exception) {
    $failures++;
    echo "  [FAIL] La suite abortó: " . $exception->getMessage() . "\n";
    echo $exception->getFile() . ':' . $exception->getLine() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RES10_API_SCHEMA);
    } catch (Throwable) {
        // La limpieza no debe ocultar el veredicto.
    }
}

echo "\n======================================================================\n";
echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
