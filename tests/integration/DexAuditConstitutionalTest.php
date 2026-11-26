<?php

declare(strict_types=1);

/**
 * DexAuditConstitutionalTest · T-DEX-10
 *
 * Certifica el blindaje constitucional del Módulo 39 sobre el router real, el
 * servicio, los repositorios PDO y PostgreSQL:
 *
 *   1. **Art. V.4 / RF-03.2.** Sede, QR y A4 no exponen volcados `.dex`,
 *      contadores `VA1`, bloques `CA3` ni `raw_payload`; el Reponedor solo recibe
 *      el prefill inmediato del `POST` y el Técnico nunca recibe euros.
 *   2. **Art. II.2 / QA-1.** El Reponedor queda bloqueado con
 *      `409 SANITARY_DEX_BLOCKED` en cuarentena o avería `TEMPERATURE_COLD`
 *      abierta; el Técnico y Coordinación conservan el acceso forense.
 *   3. **Art. III.1 / III.3.** El `.dex` y sus líneas no admiten `UPDATE` ni
 *      `DELETE`; la anulación `ACTIVE → VOIDED` es terminal y deja rastro en
 *      `audit_log`; el hash anulado puede reimportarse sin tocar la fila histórica.
 *   4. **Art. V.5 / QA-5.** `/raw` solo se sirve con `nosniff`, `text/plain` y
 *      nombre `DEX-AAAA-NNNN.dex`.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y un esquema aislado; sin motor sale
 * con código 2. El esquema se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/DexAuditConstitutionalTest.php
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
const DEX10_CONST_SCHEMA = 't_dex10_const';

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
echo " VENDGUARD · T-DEX-10 · Blindaje constitucional de auditorías EVA-DTS\n";
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
    PostgresFixtureBuilder::createIsolationSchema($pdo, DEX10_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    echo "\n--- Escenario aislado: usuarios, zona, sede y cuatro máquinas ---\n";

    $createUser = static function (PDO $pdo, string $suffix, string $role, string $name): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'dex10.const.' . $suffix . '@vendguard.internal',
            ':email_key' => 'dex10.const.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('dex10-const-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'DEX10C-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser($pdo, 'coord', 'COORDINATOR', 'Coordinación DEX 10');
    $restockerId = $createUser($pdo, 'repo', 'RESTOCKER', 'Reposición DEX 10');
    $technicianId = $createUser($pdo, 'sat', 'TECHNICIAN', 'Técnico DEX 10');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX10-CONST', 'Zona constitucional DEX', '#2560ff') RETURNING id"
    )->fetchColumn();
    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX10-CONST',
        ':site_code_key' => 'sede-dex10-const',
        ':name' => 'Sede constitucional DEX',
        ':address' => 'Carrer de la Constitució 10, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $createMachine = static function (string $code, string $type) use ($pdo, $locationId): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :machine_type, :floor_wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo constitucional ' . $code,
            ':machine_type' => $type,
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $normalId = $createMachine('DEX10-CONST-NORMAL', 'PERISHABLE_FOOD');
    $quarantineId = $createMachine('DEX10-CONST-QUAR', 'SNACKS');
    $thermalId = $createMachine('DEX10-CONST-THERM', 'COMBO');
    $expiredId = $createMachine('DEX10-CONST-EXPIRED', 'SNACKS');

    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    )->execute([':restocker_id' => $restockerId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:technician_id, :zone_id, TRUE)'
    )->execute([':technician_id' => $technicianId, ':zone_id' => $zoneId]);

    $pdo->prepare(
        "UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = :id"
    )->execute([':id' => $quarantineId]);
    $pdo->prepare(
        "UPDATE machines SET sanitary_status = 'EXPIRED', is_blocked_no_access = TRUE WHERE id = :id"
    )->execute([':id' => $expiredId]);
    $pdo->prepare(
        "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status)
         VALUES ('TCK-DEX10-CONST-THERM', :machine_id, :location_id, 'TEMPERATURE_COLD',
                 'Rotura de frío abierta para certificar la primacía sanitaria.', 'HIGH', 'REGISTERED')"
    )->execute([':machine_id' => $thermalId, ':location_id' => $locationId]);

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));
    $siteToken = $authService->generateSiteToken($locationRepo->findById($locationId));
    $router = AppRouter::create();

    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = [],
        array $headers = []
    ) use ($router): Response {
        $allHeaders = ['Content-Type' => 'application/json'] + $headers;
        if ($token !== null) {
            $allHeaders['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $allHeaders));
    };

    $makeRaw = static function (string $machineCode, float $sales, int $units, bool $thermal = false): string {
        $raw = "DXS*COMM-DEX10*VA*DEX 6.0\n"
            . "ID1*{$machineCode}\n"
            . "ID4*2\n"
            . "VA1*" . number_format($sales, 2, '.', '') . "*{$units}\n"
            . "CA3*500*10*2\n"
            . "PA1*A1*2\n"
            . "PA2*10*1000\n";
        if ($thermal) {
            $raw .= "EA2*EG01*1*1*1\n";
        }

        return $raw . 'DXE*FIN';
    };

    $import = static function (int $machineId, string $raw, string $mutation, string $source, string $capturedAt) use (
        $call,
        $coordinatorToken,
        $restockerToken,
        $technicianToken
    ): Response {
        $token = match ($source) {
            'RESTOCKER_ROUTE' => $restockerToken,
            'TECHNICIAN_ROUTE' => $technicianToken,
            default => $coordinatorToken,
        };
        $path = match ($source) {
            'RESTOCKER_ROUTE' => '/api/restocker/machines/' . $machineId . '/dex-audits',
            'TECHNICIAN_ROUTE' => '/api/technician/machines/' . $machineId . '/dex-audits',
            default => '/api/coordinator/machines/' . $machineId . '/dex-audits',
        };

        return $call('POST', $path, [
            'raw_payload' => $raw,
            'original_filename' => 'constitucional.dex',
            'captured_at' => $capturedAt,
            'client_mutation_id' => $mutation,
        ], $token);
    };

    $dbRejects = static function (string $sql, array $params, string $needle) use ($pdo): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [false, 'la sentencia fue aceptada'];
        } catch (PDOException $exception) {
            return [str_contains($exception->getMessage(), $needle), $exception->getMessage()];
        }
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. V.4 · opacidad de Sede, QR y A4
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. V.4 · Sede, QR y A4 sin datos .dex ---\n";

    $qr = $call('GET', '/api/qr/scan/DEX10-CONST-NORMAL');
    $qrRaw = (string)$qr->getBody();
    $siteMachines = $call('GET', '/api/locations/SEDE-DEX10-CONST/machines', [], $siteToken);
    $siteMachinesRaw = (string)$siteMachines->getBody();
    $siteSanitary = $call('GET', '/api/site/sanitary-status', [], $siteToken);
    $siteSanitaryRaw = (string)$siteSanitary->getBody();

    $forbiddenPublicTokens = ['dex_audit', 'machine_dex_audits', 'va1_total_sales_eur', 'ca3_cashbox_eur', 'raw_payload'];
    $publicPayloads = [$qrRaw, $siteMachinesRaw, $siteSanitaryRaw];
    $publicLeaks = [];
    foreach ($publicPayloads as $payload) {
        foreach ($forbiddenPublicTokens as $token) {
            if (str_contains($payload, $token)) {
                $publicLeaks[] = $token;
            }
        }
    }
    $assert(
        '1.1 QR y Portal de Sede responden sin campos .dex, VA1, CA3 ni raw_payload',
        $qr->getStatusCode() === 200
            && $siteMachines->getStatusCode() === 200
            && $siteSanitary->getStatusCode() === 200
            && $publicLeaks === [],
        'status=' . $qr->getStatusCode() . '/' . $siteMachines->getStatusCode() . '/' . $siteSanitary->getStatusCode() . ' leaks=' . json_encode($publicLeaks)
    );

    $publicSurfaceFiles = [
        'src/Presentation/Controller/QrScanController.php',
        'src/Presentation/Controller/LocationPortalController.php',
        'src/Presentation/Controller/SiteSanitaryController.php',
        'src/Presentation/Controller/CoordinatorVanRestockOrderController.php',
    ];
    $surfaceLeaks = [];
    foreach ($publicSurfaceFiles as $relativePath) {
        $source = (string)file_get_contents($projectRoot . '/' . $relativePath);
        foreach ($forbiddenPublicTokens as $token) {
            if (str_contains($source, $token)) {
                $surfaceLeaks[] = $relativePath . ':' . $token;
            }
        }
    }
    $assert(
        '1.2 Las superficies Sede/QR/A4 no contienen ninguna consulta ni token .dex',
        $surfaceLeaks === [],
        json_encode($surfaceLeaks)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. V.4 / QA-1 · Arqueo Ciego y proyecciones por rol
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. V.4 / QA-1 · Arqueo Ciego y proyecciones ---\n";

    $restockerRaw = $makeRaw('DEX10-CONST-NORMAL', 125.00, 25);
    $restockerImport = $import($normalId, $restockerRaw, 'dex10-const-rest-0001', 'RESTOCKER_ROUTE', '2026-11-24 09:00:00+00');
    $restockerData = $data($restockerImport);
    $restockerKeys = $collectKeys($restockerData);
    $assert(
        '2.1 El POST del Reponedor devuelve el prefill inmediato sin CA3 ni euros por carril',
        $restockerImport->getStatusCode() === 201
            && (float)($restockerData['prefill_sales_counter_reading_eur'] ?? 0) === 125.0
            && !in_array('ca3_cashbox_eur', $restockerKeys, true)
            && !in_array('unit_price_eur', $restockerKeys, true)
            && !in_array('total_sales_eur', $restockerKeys, true)
            && !in_array('raw_payload', $restockerKeys, true),
        $errorCode($restockerImport)
    );

    $restockerHistory = $call('GET', '/api/restocker/machines/' . $normalId . '/dex-audits', [], $restockerToken);
    $restockerHistoryValues = $collectKeys($data($restockerHistory));
    $assert(
        '2.2 El GET histórico del Reponedor omite prefill, CA3 y cualquier importe',
        $restockerHistory->getStatusCode() === 200
            && !in_array('prefill_sales_counter_reading_eur', $restockerHistoryValues, true)
            && !in_array('ca3_cashbox_eur', $restockerHistoryValues, true)
            && !in_array('ca3_coin_tubes_eur', $restockerHistoryValues, true)
            && !in_array('unit_price_eur', $restockerHistoryValues, true)
            && !in_array('delta_sales_eur', $restockerHistoryValues, true)
            && !in_array('raw_payload', $restockerHistoryValues, true)
    );

    $technicianRaw = $makeRaw('DEX10-CONST-NORMAL', 150.00, 30, true);
    $technicianImport = $import($normalId, $technicianRaw, 'dex10-const-tech-0001', 'TECHNICIAN_ROUTE', '2026-11-24 10:00:00+00');
    $technicianData = $data($technicianImport);
    $technicianKeys = $collectKeys($technicianData);
    $technicianHistory = $call('GET', '/api/technician/machines/' . $normalId . '/dex-audits', [], $technicianToken);
    $technicianHistoryKeys = $collectKeys($data($technicianHistory));
    $assert(
        '2.3 El Técnico importa y lista sin prefill ni importes EUR (Art. V.4)',
        $technicianImport->getStatusCode() === 201
            && $technicianHistory->getStatusCode() === 200
            && !in_array('prefill_sales_counter_reading_eur', $technicianKeys, true)
            && !in_array('ca3_cashbox_eur', $technicianKeys, true)
            && !in_array('prefill_sales_counter_reading_eur', $technicianHistoryKeys, true)
            && !in_array('ca3_cashbox_eur', $technicianHistoryKeys, true)
            && !in_array('unit_price_eur', $technicianHistoryKeys, true),
        $errorCode($technicianImport) . '/' . $errorCode($technicianHistory)
    );

    $coordinatorList = $call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['machine_id' => (string)$normalId]);
    $coordinatorRows = $data($coordinatorList)['audits'] ?? [];
    $coordinatorDetail = $call('GET', '/api/coordinator/dex-audits/' . (int)($coordinatorRows[0]['id'] ?? 0), [], $coordinatorToken);
    $coordinatorDetailData = $data($coordinatorDetail);
    $assert(
        '2.4 Coordinación recibe la vista completa con VA1/CA3 y sin raw_payload',
        $coordinatorList->getStatusCode() === 200
            && $coordinatorDetail->getStatusCode() === 200
            && ($coordinatorDetailData['va1_total_sales_eur'] ?? null) !== null
            && ($coordinatorDetailData['ca3_cashbox_eur'] ?? null) !== null
            && ($coordinatorDetailData['can_download_raw'] ?? false) === true
            && !in_array('raw_payload', $collectKeys($coordinatorDetailData), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. II.2 / QA-1 · primacía sanitaria
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. II.2 / QA-1 · bloqueo sanitario del Reponedor ---\n";

    $quarantineRestocker = $import($quarantineId, $makeRaw('DEX10-CONST-QUAR', 60.00, 6), 'dex10-const-quar-rest', 'RESTOCKER_ROUTE', '2026-11-24 11:00:00+00');
    $thermalRestocker = $import($thermalId, $makeRaw('DEX10-CONST-THERM', 60.00, 6), 'dex10-const-therm-rest', 'RESTOCKER_ROUTE', '2026-11-24 11:01:00+00');
    $expiredRestocker = $import($expiredId, $makeRaw('DEX10-CONST-EXPIRED', 60.00, 6), 'dex10-const-exp-rest', 'RESTOCKER_ROUTE', '2026-11-24 11:02:00+00');
    $assert(
        '3.1 El Reponedor recibe 409 SANITARY_DEX_BLOCKED en cuarentena y avería fría abierta',
        $quarantineRestocker->getStatusCode() === 409
            && $errorCode($quarantineRestocker) === 'SANITARY_DEX_BLOCKED'
            && $thermalRestocker->getStatusCode() === 409
            && $errorCode($thermalRestocker) === 'SANITARY_DEX_BLOCKED',
        $errorCode($quarantineRestocker) . '/' . $errorCode($thermalRestocker)
    );
    $assert(
        '3.2 Una máquina EXPIRED con bloqueo de acceso no sanitario sí admite al Reponedor',
        $expiredRestocker->getStatusCode() === 201,
        $errorCode($expiredRestocker)
    );

    $quarantineTechnician = $import($quarantineId, $makeRaw('DEX10-CONST-QUAR', 70.00, 7), 'dex10-const-quar-tech', 'TECHNICIAN_ROUTE', '2026-11-24 11:03:00+00');
    $thermalTechnician = $import($thermalId, $makeRaw('DEX10-CONST-THERM', 70.00, 7), 'dex10-const-therm-tech', 'TECHNICIAN_ROUTE', '2026-11-24 11:04:00+00');
    $quarantineCoordinator = $import($quarantineId, $makeRaw('DEX10-CONST-QUAR', 80.00, 8), 'dex10-const-quar-coord', 'COORDINATOR_CONSOLE', '2026-11-24 11:05:00+00');
    $assert(
        '3.3 Técnico y Coordinación conservan el acceso forense en cuarentena y frío abierto',
        $quarantineTechnician->getStatusCode() === 201
            && $thermalTechnician->getStatusCode() === 201
            && $quarantineCoordinator->getStatusCode() === 201,
        $errorCode($quarantineTechnician) . '/' . $errorCode($thermalTechnician) . '/' . $errorCode($quarantineCoordinator)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. V.5 / QA-5 · descarga segura
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Art. V.5 / QA-5 · descarga /raw ---\n";

    $rawAuditId = (int)($coordinatorRows[0]['id'] ?? 0);
    $raw = $call('GET', '/api/coordinator/dex-audits/' . $rawAuditId . '/raw', [], $coordinatorToken);
    $assert(
        '4.1 /raw responde text/plain, nosniff y filename DEX-AAAA-NNNN.dex',
        $raw->getStatusCode() === 200
            && $raw->getHeader('Content-Type') === 'text/plain; charset=utf-8'
            && $raw->getHeader('X-Content-Type-Options') === 'nosniff'
            && preg_match('/filename="DEX-\d{4}-\d{4,}\.dex"/', (string)$raw->getHeader('Content-Disposition')) === 1
            && !str_contains((string)$raw->getHeader('Content-Disposition'), '..')
    );
    $storedRaw = (string)$pdo->query(
        'SELECT raw_payload FROM machine_dex_audits WHERE id = ' . $rawAuditId
    )->fetchColumn();
    $assert(
        '4.2 /raw entrega exactamente el .dex persistido',
        $raw->getBody() === $storedRaw && $storedRaw !== '',
        'body=' . strlen($raw->getBody()) . ' stored=' . strlen($storedRaw)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Art. III · inmutabilidad, VOIDED y reimportación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Art. III · inmutabilidad y anulación lógica ---\n";

    $voidRaw = $makeRaw('DEX10-CONST-NORMAL', 500.00, 50);
    $voidImport = $import($normalId, $voidRaw, 'dex10-const-void-0001', 'COORDINATOR_CONSOLE', '2026-11-24 12:00:00+00');
    $voidId = (int)($data($voidImport)['audit']['id'] ?? 0);
    $voidReason = 'Volcado cargado sobre la máquina equivocada durante la certificación.';
    $voided = $call('POST', '/api/coordinator/dex-audits/' . $voidId . '/void', ['void_reason' => $voidReason], $coordinatorToken);

    $rawBefore = (string)$pdo->query(
        'SELECT raw_payload FROM machine_dex_audits WHERE id = ' . $voidId
    )->fetchColumn();
    [$headerUpdated, $headerUpdateDetail] = $dbRejects(
        'UPDATE machine_dex_audits SET va1_total_sales_eur = va1_total_sales_eur WHERE id = :id',
        [':id' => $voidId],
        'ART_III'
    );
    [$headerDeleted, $headerDeleteDetail] = $dbRejects(
        'DELETE FROM machine_dex_audits WHERE id = :id',
        [':id' => $voidId],
        'ART_III_1'
    );
    $selectionId = (int)$pdo->query(
        'SELECT id FROM machine_dex_selection_lines WHERE dex_audit_id = ' . $voidId . ' LIMIT 1'
    )->fetchColumn();
    [$lineUpdated, $lineUpdateDetail] = $dbRejects(
        'UPDATE machine_dex_selection_lines SET total_vend_units = total_vend_units WHERE id = :id',
        [':id' => $selectionId],
        'ART_III'
    );
    $rawAfter = (string)$pdo->query(
        'SELECT raw_payload FROM machine_dex_audits WHERE id = ' . $voidId
    )->fetchColumn();
    $voidStatus = (string)$pdo->query(
        'SELECT status FROM machine_dex_audits WHERE id = ' . $voidId
    )->fetchColumn();
    $voidAudits = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_DEX_AUDIT'
            AND entity_id = " . $voidId . "
            AND action = 'DEX_AUDIT_VOIDED'
            AND user_id = " . $coordinatorId . "
            AND metadata->>'dex_audit_id' = '" . $voidId . "'"
    )->fetchColumn();

    $assert(
        '5.1 La anulación válida responde 200 y deja la fila VOIDED con el .dex intacto',
        $voided->getStatusCode() === 200
            && $voidStatus === 'VOIDED'
            && $rawBefore !== ''
            && $rawBefore === $rawAfter
            && $voidAudits === 1,
        $errorCode($voided) . ' status=' . $voidStatus . ' audits=' . $voidAudits . ' same_raw=' . ($rawBefore === $rawAfter ? 'si' : 'no')
    );
    $assert(
        '5.2 El motor rechaza UPDATE de cabecera, DELETE y UPDATE de líneas .dex',
        $headerUpdated && $headerDeleted && $lineUpdated,
        json_encode([$headerUpdateDetail, $headerDeleteDetail, $lineUpdateDetail])
    );

    $reimport = $import($normalId, $voidRaw, 'dex10-const-void-0002', 'COORDINATOR_CONSOLE', '2026-11-24 12:01:00+00');
    $reimportData = $data($reimport);
    $reimportId = (int)($reimportData['audit']['id'] ?? 0);
    $statusCounts = $pdo->query(
        "SELECT status, COUNT(*) FROM machine_dex_audits
          WHERE machine_id = " . $normalId . "
          GROUP BY status ORDER BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $assert(
        '5.3 El hash liberado por VOIDED se reimporta como una fila nueva sin resucitar la anulada',
        $reimport->getStatusCode() === 201
            && $reimportId > 0
            && $reimportId !== $voidId
            && (int)($statusCounts['VOIDED'] ?? 0) === 1
            && (int)($statusCounts['ACTIVE'] ?? 0) >= 3,
        $errorCode($reimport) . ' counts=' . json_encode($statusCounts)
    );

    $exitCode = $failures === 0 ? 0 : 1;
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[ERROR] " . $exception::class . ': ' . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, DEX10_CONST_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. T-DEX-10 (blindaje constitucional .dex) CERTIFICADO.\n"
    : " RESULTADO: FALLOS DETECTADOS EN EL BLINDAJE CONSTITUCIONAL .DEX.\n";
echo "======================================================================\n";
exit($failures > 0 ? 1 : 0);
