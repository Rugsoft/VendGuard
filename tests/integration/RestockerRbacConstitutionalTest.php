<?php

declare(strict_types=1);

/**
 * RestockerRbacConstitutionalTest · T-RES-11
 *
 * Certifica contra PostgreSQL real y el router real los blindajes
 * constitucionales del Módulo 31:
 *
 *   1. Art. V.4 (segregación RBAC): el reponedor recibe `403 FORBIDDEN` en toda
 *      la superficie SAT (averías, repuestos, furgoneta, preventivos, métricas y
 *      relevo) y en la consola de Coordinación; técnico y coordinador no entran
 *      en la ruta móvil del reponedor.
 *   2. Art. II (guardia 24/7 exclusiva del técnico): el motor rechaza un
 *      `RESTOCKER` con `is_on_call = TRUE` y el repositorio no lo marca.
 *   3. Art. V.3 (selector de asignación): `findAllTechnicians()` excluye a los
 *      reponedores, de modo que una avería SAT solo puede asignarse a técnicos.
 *   4. Art. III.1 y QA 1 (Tabla 41): coherencia `role_in_zone`/`is_primary`,
 *      titularidad única viva, suplencias múltiples, prohibición de borrado
 *      físico y baja lógica que conserva la fila.
 *   5. Art. III.3 (auditoría): el alta y la baja escriben `RESTOCKER_ZONE_ASSIGNED`
 *      y `RESTOCKER_ZONE_REMOVED` con actor de Coordinación, y la baja repetida
 *      no duplica el evento (QA 4).
 *   6. Art. V.4 (aislamiento horizontal): «Mis Horas» y la ruta móvil de un
 *      reponedor no exponen jornadas ni zonas de otro reponedor.
 *
 * CATEGORÍA: `new_engine`. Exige el PostgreSQL local y trabaja en un esquema
 * aislado sobre la conexión de la aplicación; sin motor sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/RestockerRbacConstitutionalTest.php
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
const RES11_CONST_SCHEMA = 't_res11_const';

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
echo " VENDGUARD · T-RES-11 · Blindaje constitucional del rol RESTOCKER\n";
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

    PostgresFixtureBuilder::createIsolationSchema($pdo, RES11_CONST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, bool $isOnCall = false) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ucfirst($role) . ' ' . ucfirst($suffix),
            ':email' => 'res11.' . $suffix . '@vendguard.internal',
            ':email_key' => 'res11.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('res11-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'R11-' . mb_strtoupper($suffix),
            ':is_on_call' => $isOnCall ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianId = $createUser('sat', 'TECHNICIAN');
    $titularId = $createUser('titular', 'RESTOCKER');
    $backupId = $createUser('suplente', 'RESTOCKER');
    $coherenceId = $createUser('coherencia', 'RESTOCKER');
    $primaryCheckId = $createUser('primario', 'RESTOCKER');

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));
    $titularToken = $authService->generateInternalToken($userRepo->findById($titularId));
    $backupToken = $authService->generateInternalToken($userRepo->findById($backupId));

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
    // 1. Art. V.4 · Perímetro RBAC de la superficie móvil
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. V.4 · Segregación RBAC ---\n";

    $satSurface = [
        '/api/technician/my-route',
        '/api/technician/spare-parts/catalog',
        '/api/technician/van-stock',
        '/api/technician/preventive/route',
        '/api/technician/my-metrics',
        '/api/technician/handover-targets',
    ];
    $blocked = 0;
    $leaked = [];
    foreach ($satSurface as $path) {
        $response = $call('GET', $path, [], $titularToken);
        $code = $json($response)['error']['code'] ?? '';
        if ($response->getStatusCode() === 403 && $code === 'FORBIDDEN') {
            $blocked++;
        } else {
            $leaked[] = $path . '=' . $response->getStatusCode() . '/' . $code;
        }
    }
    $assert(
        '1.1 Art. V.4: el reponedor recibe 403 FORBIDDEN en las seis superficies SAT',
        $blocked === count($satSurface),
        implode(', ', $leaked)
    );

    $coordinatorOnRestocker = $call('GET', '/api/restocker/my-route', [], $coordinatorToken);
    $technicianOnRestocker = $call('GET', '/api/restocker/my-route', [], $technicianToken);
    $assert(
        '1.2 Solo el reponedor alcanza su ruta móvil (coordinador y técnico reciben 403)',
        $coordinatorOnRestocker->getStatusCode() === 403
            && $technicianOnRestocker->getStatusCode() === 403
    );

    $restockerOnConsole = $call('GET', '/api/coordinator/restockers/quadrants', [], $titularToken);
    $assert(
        '1.3 El reponedor no alcanza el cuadrante de Coordinación (403)',
        $restockerOnConsole->getStatusCode() === 403
    );

    $anonymous = $call('GET', '/api/restocker/my-route');
    $assert(
        '1.4 Sin sesión la ruta móvil del reponedor responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401
            && ($json($anonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. II · La guardia 24/7 es exclusiva del TECHNICIAN
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. II · Guardia técnica exclusiva ---\n";

    $onCallRejection = PostgresFixtureBuilder::expectRejection(
        static fn () => $createUser('guardia', 'RESTOCKER', true)
    );
    $assert(
        '2.1 El motor rechaza cualquier RESTOCKER en guardia (23514)',
        $onCallRejection !== null && $onCallRejection['state'] === '23514',
        json_encode($onCallRejection)
    );

    $restockerGuard = $pdo->query(
        "SELECT pg_get_constraintdef(oid) FROM pg_constraint
          WHERE conrelid = 'users'::regclass AND conname = 'chk_users_restocker_not_on_call'"
    )->fetchColumn();
    $assert(
        '2.2 La guarda constitucional del reponedor está declarada en el motor',
        is_string($restockerGuard)
            && str_contains($restockerGuard, 'RESTOCKER')
            && str_contains($restockerGuard, 'is_on_call')
    );

    $marked = $userRepo->setOnCall($titularId, true);
    $onCallFlag = $pdo->query(
        'SELECT is_on_call FROM users WHERE id = ' . $titularId
    )->fetchColumn();
    $assert(
        '2.3 El repositorio no marca en guardia a un reponedor',
        $marked === false && ($onCallFlag === false || $onCallFlag === 'f')
    );

    $selectableIds = array_map(
        static fn ($user): int => (int)$user->getId(),
        $userRepo->findAllTechnicians(false)
    );
    $assert(
        '2.4 Art. V.3: el selector de asignación SAT excluye a todos los reponedores',
        in_array($technicianId, $selectableIds, true)
            && !in_array($titularId, $selectableIds, true)
            && !in_array($backupId, $selectableIds, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. III.1 y QA 1 · Invariantes de la Tabla 41
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. III.1 / QA 1 · Cuadrante de carga ---\n";

    $createZone = static function (string $code, string $name) use ($call, $coordinatorToken, $json): int {
        $response = $call('POST', '/api/coordinator/zones', [
            'code' => $code,
            'name' => $name,
            'color_hex' => '#2560ff',
        ], $coordinatorToken);

        return (int)($json($response)['data']['id'] ?? 0);
    };

    $zoneA = $createZone('ZONA-R11-A', 'Zona de certificación A');
    $zoneB = $createZone('ZONA-R11-B', 'Zona de certificación B');
    $zoneC = $createZone('ZONA-R11-C', 'Zona de certificación C');
    $assert('3.1 Coordinación crea las tres zonas del escenario', $zoneA > 0 && $zoneB > 0 && $zoneC > 0);

    $coherenceInsert = static function (int $restockerId, int $zoneId, string $role, bool $isPrimary) use ($pdo): ?array {
        return PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $restockerId, $zoneId, $role, $isPrimary): void {
            $stmt = $pdo->prepare(
                'INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
                 VALUES (:restocker_id, :zone_id, :role_in_zone, :is_primary)'
            );
            $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':role_in_zone', $role, PDO::PARAM_STR);
            $stmt->bindValue(':is_primary', $isPrimary, PDO::PARAM_BOOL);
            $stmt->execute();
        });
    };

    $primaryFalse = $coherenceInsert($coherenceId, $zoneC, 'PRIMARY', false);
    $backupTrue = $coherenceInsert($coherenceId, $zoneC, 'BACKUP', true);
    $assert(
        '3.2 chk_restocker_zone_role_coherence rechaza PRIMARY sin is_primary',
        $primaryFalse !== null && $primaryFalse['state'] === '23514'
    );
    $assert(
        '3.3 chk_restocker_zone_role_coherence rechaza BACKUP con is_primary',
        $backupTrue !== null && $backupTrue['state'] === '23514'
    );

    $assignPrimaryCheck = $call('POST', '/api/coordinator/zones/' . $zoneA . '/restockers', [
        'restocker_id' => $primaryCheckId,
        'role_in_zone' => 'PRIMARY',
    ], $coordinatorToken);
    $assert('3.4 Un reponedor puede ser titular de una zona viva', $assignPrimaryCheck->getStatusCode() === 200);

    $secondPrimary = $coherenceInsert($primaryCheckId, $zoneB, 'PRIMARY', true);
    $assert(
        '3.5 uq_restocker_primary_zone impide un segundo destino PRIMARY vivo',
        $secondPrimary !== null && $secondPrimary['state'] === '23505'
    );

    $multipleBackup = $coherenceInsert($primaryCheckId, $zoneC, 'BACKUP', false);
    $assert(
        '3.6 El mismo reponedor puede ser suplente en varias zonas',
        $multipleBackup === null
    );

    $assignTitular = $call('POST', '/api/coordinator/zones/' . $zoneA . '/restockers', [
        'restocker_id' => $titularId,
        'role_in_zone' => 'PRIMARY',
    ], $coordinatorToken);
    $titularAssignment = $json($assignTitular)['data']['assignment'] ?? [];
    $titularAssignmentId = (int)($titularAssignment['id'] ?? 0);
    $assert(
        '3.7 El titular del escenario se vincula con is_primary sincronizado',
        $assignTitular->getStatusCode() === 200
            && $titularAssignmentId > 0
            && ($titularAssignment['is_primary'] ?? false) === true
    );

    $assignBackup = $call('POST', '/api/coordinator/zones/' . $zoneA . '/restockers', [
        'restocker_id' => $backupId,
        'is_primary' => false,
    ], $coordinatorToken);
    $backupAssignmentId = (int)($json($assignBackup)['data']['assignment']['id'] ?? 0);
    $assert(
        '3.8 El suplente del escenario se vincula como BACKUP',
        $assignBackup->getStatusCode() === 200 && $backupAssignmentId > 0
    );

    $hardDelete = PostgresFixtureBuilder::expectRejection(
        static fn () => $pdo->exec('DELETE FROM restocker_zone_assignments WHERE id = ' . $backupAssignmentId)
    );
    $survivors = (int)$pdo->query(
        'SELECT COUNT(*) FROM restocker_zone_assignments WHERE id = ' . $backupAssignmentId
    )->fetchColumn();
    $assert(
        '3.9 trg_no_delete_restocker_zone_assignments impide el borrado físico (Art. III.1)',
        $hardDelete !== null && $survivors === 1
    );

    $remove = $call('DELETE', '/api/coordinator/zones/' . $zoneA . '/restockers/' . $titularId, [], $coordinatorToken);
    $removedRow = $pdo->query(
        'SELECT removed_at FROM restocker_zone_assignments WHERE id = ' . $titularAssignmentId
    )->fetch();
    $assert(
        '3.10 La baja es lógica: la fila sobrevive con removed_at sellado',
        $remove->getStatusCode() === 200
            && is_array($removedRow)
            && $removedRow['removed_at'] !== null
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. III.3 y QA 4 · Auditoría del cuadrante
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Art. III.3 / QA 4 · Auditoría ---\n";

    $auditFor = static function (int $assignmentId, string $action) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT action, user_role, new_state FROM audit_log
              WHERE entity_type = :entity_type AND entity_id = :entity_id AND action = :action'
        );
        $stmt->execute([
            ':entity_type' => 'RESTOCKER_ZONE',
            ':entity_id' => $assignmentId,
            ':action' => $action,
        ]);

        return $stmt->fetchAll();
    };

    $assignedAudit = $auditFor($titularAssignmentId, 'RESTOCKER_ZONE_ASSIGNED');
    $assert(
        '4.1 El alta de titular audita RESTOCKER_ZONE_ASSIGNED con actor de Coordinación',
        count($assignedAudit) === 1
            && ($assignedAudit[0]['user_role'] ?? '') === 'COORDINATOR'
            && is_string($assignedAudit[0]['new_state'] ?? null)
            && str_contains((string)$assignedAudit[0]['new_state'], 'PRIMARY')
    );

    $removedAudit = $auditFor($titularAssignmentId, 'RESTOCKER_ZONE_REMOVED');
    $assert(
        '4.2 La baja lógica audita RESTOCKER_ZONE_REMOVED',
        count($removedAudit) === 1
    );

    $removeAgain = $call('DELETE', '/api/coordinator/zones/' . $zoneA . '/restockers/' . $titularId, [], $coordinatorToken);
    $removedAuditAgain = $auditFor($titularAssignmentId, 'RESTOCKER_ZONE_REMOVED');
    $assert(
        '4.3 QA 4: repetir la baja responde 200 sin duplicar el evento',
        $removeAgain->getStatusCode() === 200
            && ($json($removeAgain)['data']['removed'] ?? false) === true
            && count($removedAuditAgain) === 1
    );

    $reassign = $call('POST', '/api/coordinator/zones/' . $zoneA . '/restockers', [
        'restocker_id' => $titularId,
        'role_in_zone' => 'PRIMARY',
    ], $coordinatorToken);
    $reassignId = (int)($json($reassign)['data']['assignment']['id'] ?? 0);
    $reassignedAudit = $reassignId > 0 ? $auditFor($reassignId, 'RESTOCKER_ZONE_ASSIGNED') : [];
    $assert(
        '4.4 El reingreso estrena vinculación viva y evento de auditoría propio',
        $reassign->getStatusCode() === 200
            && $reassignId > 0
            && $reassignId !== $titularAssignmentId
            && count($reassignedAudit) === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Art. V.4 · Aislamiento horizontal del reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Art. V.4 · Aislamiento horizontal ---\n";

    $startTitular = $call('POST', '/api/technician/shifts/start', [], $titularToken);
    $startBackup = $call('POST', '/api/technician/shifts/start', [], $backupToken);
    $assert(
        '5.1 Ambos reponedores inician su jornada por la ruta compartida',
        $startTitular->getStatusCode() === 200 && $startBackup->getStatusCode() === 200
    );

    $titularHours = $call('GET', '/api/technician/shifts/my-hours', [], $titularToken);
    $titularHoursData = $json($titularHours)['data'] ?? [];
    $titularHoursRaw = (string)$titularHours->getBody();
    $assert(
        '5.2 «Mis Horas» devuelve solo la sesión del token',
        $titularHours->getStatusCode() === 200 && count($titularHoursData['sessions'] ?? []) === 1
    );
    $assert(
        '5.3 «Mis Horas» no expone el código de operador del compañero',
        !str_contains($titularHoursRaw, 'R11-SUPLENTE')
            && !str_contains($titularHoursRaw, 'R11-TITULAR')
            && !str_contains($titularHoursRaw, 'Restocker Suplente')
    );

    $backupHours = $call('GET', '/api/technician/shifts/my-hours', [], $backupToken);
    $assert(
        '5.4 El compañero obtiene únicamente su propia sesión',
        $backupHours->getStatusCode() === 200
            && count($json($backupHours)['data']['sessions'] ?? []) === 1
    );

    $titularRoute = $call('GET', '/api/restocker/my-route', [], $titularToken);
    $routeData = $json($titularRoute)['data'] ?? [];
    $assert(
        '5.5 La ruta móvil publica solo las zonas propias del reponedor',
        $titularRoute->getStatusCode() === 200
            && (int)($routeData['zones']['primary_zone']['id'] ?? 0) === $zoneA
            && ($routeData['zones']['backup_zones'] ?? null) === []
            && !isset($routeData['restockers'])
            && !isset($routeData['zones']['all'])
    );
} catch (Throwable $exception) {
    $failures++;
    echo '  [FAIL] La suite abortó: ' . $exception->getMessage() . "\n";
    echo $exception->getFile() . ':' . $exception->getLine() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RES11_CONST_SCHEMA);
    } catch (Throwable) {
        // La limpieza no debe ocultar el veredicto.
    }
}

echo "\n======================================================================\n";
echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
