<?php

declare(strict_types=1);

/**
 * TieredSlaContractsApiTest · T-SLA-11
 *
 * Certificación de integración de los contratos HTTP del Módulo 30
 * (Perfiles de SLA Diferenciados por Tipo de Sede) contra PostgreSQL real:
 *
 *   1. CRUD de sedes: perfil STANDARD por defecto, rechazo 422 del perfil
 *      desconocido, cambio efectivo auditado una sola vez y snapshot histórico
 *      inmutable de los tickets anteriores (RF-01, RF-02, QA 4).
 *   2. Alta de ticket con objetivo sellado, reclasificación de urgencia en
 *      Coordinación y absorción QR de un aviso térmico en una máquina COMBO
 *      (RF-01, RF-03, C4).
 *   3. Reapertura en garantía < 48 h: no reinicia created_at, acumula el
 *      intervalo cerrado en total_pending_info_seconds y desplaza sla_target_at
 *      (Art. V.6, QA 3).
 *   4. Escalado contractual 24/7 en sugerencias, prelación sanitaria en Ruta,
 *      contrato SLA en el paquete offline, métricas por perfil y opacidad total
 *      del canal QR frente a la visibilidad del Portal de Sede (Art. V.4, D5).
 *
 * CATEGORÍA: `new_engine`. El escenario vive en un esquema aislado sobre la
 * conexión de la aplicación; sin motor real la suite declara la categoría no
 * ejecutable y sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/TieredSlaContractsApiTest.php
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

/** Marca de categoría: `run_all.php` la lee para no confundirla con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta certificación. */
const TIERED_SLA_API_SCHEMA = 't_sla11_contracts_api';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-SLA-11 · Contratos HTTP del SLA por perfiles de sede\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** @var array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

/** Recorre recursivamente una estructura y devuelve true si alguna clave cumple el predicado. */
$hasKeyMatching = static function (mixed $value, callable $predicate) use (&$hasKeyMatching): bool {
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $item) {
        if (is_string($key) && $predicate($key)) {
            return true;
        }
        if ($hasKeyMatching($item, $predicate)) {
            return true;
        }
    }

    return false;
};

/** Primer array asociativo con una clave concreta y el valor esperado. */
$findBy = static function (array $rows, string $key, mixed $expected): ?array {
    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists($key, $row) && $row[$key] === $expected) {
            return $row;
        }
    }

    return null;
};

try {
    echo "\n--- Escenario (DDL base + migraciones en esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, TIERED_SLA_API_SCHEMA);
    $schema = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    echo '  DDL base y ' . count($schema['migrations']) . " migraciones aplicadas.\n";

    $now = new DateTimeImmutable('now');
    $iso = static fn (DateTimeImmutable $instant): string => $instant->format('Y-m-d H:i:sP');

    $createUser = static function (string $suffix, string $role, bool $isOnCall = false) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación SLA ' : 'Técnico SLA ') . ucfirst($suffix),
            ':email' => 'sla11.' . $suffix . '@vendguard.internal',
            ':email_key' => 'sla11.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('sla11-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'SLA11-' . mb_strtoupper($suffix),
            ':is_on_call' => $isOnCall ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $startShift = static function (int $technicianId) use ($pdo): void {
        $pdo->prepare(
            "INSERT INTO technician_shifts (technician_id, status, shift_date, started_at)
             VALUES (:technician_id, 'ON_SHIFT', CURRENT_DATE, CURRENT_TIMESTAMP)"
        )->execute([':technician_id' => $technicianId]);
    };

    $pdo->exec("INSERT INTO route_settings (base_name, base_latitude, base_longitude) VALUES ('Base SLA-11', 41.3935, 2.1701)");

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $routeTechId = $createUser('ruta', 'TECHNICIAN');
    $onCallTechId = $createUser('guardia', 'TECHNICIAN', true);
    $idleTechId = $createUser('apoyo', 'TECHNICIAN');
    foreach ([$routeTechId, $onCallTechId, $idleTechId] as $technicianId) {
        $startShift($technicianId);
    }

    $locationRepo = new PdoLocationRepository($pdo);
    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);
    $router = AppRouter::create();

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $routeTechToken = $authService->generateInternalToken($userRepo->findById($routeTechId));

    /** Petición al router real. */
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

    $createLocation = static function (
        string $siteCode,
        string $name,
        ?string $slaTier,
        float $latitude,
        float $longitude
    ) use ($call, $coordinatorToken, $json, $assert): array {
        $body = [
            'site_code' => $siteCode,
            'name' => $name,
            'address' => 'Calle de Certificación SLA 30',
            'postal_code' => '08001',
            'contact_name' => 'Contacto SLA 30',
            'contact_phone' => '600112233',
            'contact_email' => strtolower($siteCode) . '@vendguard.internal',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
        if ($slaTier !== null) {
            $body['sla_tier'] = $slaTier;
        }

        $response = $call('POST', '/api/coordinator/locations', $body, $coordinatorToken);
        $payload = $json($response);
        $assert(
            "Alta de sede {$siteCode} responde 201 y conserva el código",
            $response->getStatusCode() === 201
                && ($payload['data']['site_code'] ?? '') === $siteCode
                && (int)($payload['data']['id'] ?? 0) > 0,
            'HTTP ' . $response->getStatusCode() . ' · ' . json_encode($payload['error'] ?? null)
        );

        return [
            'id' => (int)($payload['data']['id'] ?? 0),
            'payload' => $payload['data'] ?? [],
        ];
    };

    $createMachine = static function (int $locationId, string $code, string $type) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :type, :wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo SLA-30',
            ':type' => $type,
            ':wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $fetchIncident = static function (int $id) use ($pdo): ?array {
        $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    };

    $insertIncident = static function (array $data) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                assigned_at, started_at, resolved_at, resolution_diagnosis, resolution_action,
                sla_tier_snapshot, sla_target_minutes, sla_target_at,
                total_pending_info_seconds, created_at
             ) VALUES (
                :ticket_code, :machine_id, :machine_type, :location_id,
                :technician_id, :category, :description, :urgency, :status,
                :assigned_at, :started_at, :resolved_at, :diagnosis, :action,
                :sla_tier, :sla_target_minutes, :sla_target_at,
                :pending_seconds, :created_at
             ) RETURNING id'
        );
        $stmt->execute([
            ':ticket_code' => $data['ticket_code'],
            ':machine_id' => $data['machine_id'],
            ':machine_type' => $data['machine_type'],
            ':location_id' => $data['location_id'],
            ':technician_id' => $data['technician_id'] ?? null,
            ':category' => $data['category'],
            ':description' => $data['description'] ?? 'Incidencia sembrada para la certificación T-SLA-11.',
            ':urgency' => $data['urgency'],
            ':status' => $data['status'],
            ':assigned_at' => $data['assigned_at'] ?? null,
            ':started_at' => $data['started_at'] ?? null,
            ':resolved_at' => $data['resolved_at'] ?? null,
            ':diagnosis' => $data['diagnosis'] ?? null,
            ':action' => $data['action'] ?? null,
            ':sla_tier' => $data['sla_tier'],
            ':sla_target_minutes' => $data['sla_target_minutes'],
            ':sla_target_at' => $data['sla_target_at'],
            ':pending_seconds' => $data['pending_seconds'] ?? 0,
            ':created_at' => $data['created_at'],
        ]);

        return (int)$stmt->fetchColumn();
    };

    $auditCount = static function (int $locationId) use ($pdo): int {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE entity_type = 'LOCATION' AND entity_id = :id AND action = 'LOCATION_UPDATED'"
        );
        $stmt->execute([':id' => $locationId]);

        return (int)$stmt->fetchColumn();
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Perfiles de sede, auditoría limpia y snapshot histórico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Perfiles de sede, auditoría limpia y snapshot histórico ---\n";

    $anonymous = $call('POST', '/api/coordinator/locations', []);
    $assert(
        '1.1 El alta de sede exige token de Coordinación (401)',
        $anonymous->getStatusCode() === 401 && ($json($anonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $technicianAttempt = $call('POST', '/api/coordinator/locations', [], $routeTechToken);
    $assert(
        '1.2 Un técnico no puede dar de alta sedes (403)',
        $technicianAttempt->getStatusCode() === 403 && ($json($technicianAttempt)['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $standard = $createLocation('SEDE-SLA11-STD', 'Sede Estándar SLA 11', null, 41.3900, 2.1800);
    $standardId = $standard['id'];
    $assert(
        '1.3 El alta sin perfil nace en STANDARD por defecto',
        ($standard['payload']['sla_tier'] ?? '') === 'STANDARD',
        (string)($standard['payload']['sla_tier'] ?? '')
    );

    $invalidTier = $call('POST', '/api/coordinator/locations', [
        'site_code' => 'SEDE-SLA11-BAD',
        'name' => 'Sede con perfil inválido SLA 11',
        'address' => 'Calle de Certificación SLA 30',
        'postal_code' => '08001',
        'latitude' => 41.3910,
        'longitude' => 2.1810,
        'sla_tier' => 'PLATINUM',
    ], $coordinatorToken);
    $invalidTierBody = $json($invalidTier);
    $assert(
        '1.4 Un perfil desconocido responde 422 INVALID_SLA_TIER',
        $invalidTier->getStatusCode() === 422
            && ($invalidTierBody['error']['code'] ?? '') === 'INVALID_SLA_TIER'
    );
    $assert(
        '1.5 El rechazo no persiste la sede',
        $locationRepo->findBySiteCode('SEDE-SLA11-BAD', onlyActive: false, allowDeleted: true) === null
    );

    $tierOneMachine = $createMachine($standardId, 'SLA11-STD-COLD-01', 'COLD_DRINKS');
    $standardLocation = $locationRepo->findById($standardId);
    $standardSiteToken = $authService->generateSiteToken($standardLocation);

    $ticketOneResponse = $call('POST', '/api/incidents', [
        'machine_id' => $tierOneMachine,
        'category' => 'PRODUCT_JAM',
        'description' => 'Atasco mecánico en el carril de bebidas frías de la sede estándar.',
    ], $standardSiteToken);
    $ticketOneData = $json($ticketOneResponse)['data'] ?? [];
    $ticketOneId = (int)($ticketOneData['id'] ?? 0);
    $ticketOneCode = (string)($ticketOneData['ticket_code'] ?? '');
    $ticketOne = $fetchIncident($ticketOneId);
    $assert(
        '1.6 El Portal crea el ticket con snapshot STANDARD y objetivo 1440 min',
        $ticketOneResponse->getStatusCode() === 201
            && ($ticketOne['sla_tier_snapshot'] ?? '') === 'STANDARD'
            && (int)($ticketOne['sla_target_minutes'] ?? 0) === 1440
            && (string)($ticketOne['created_at'] ?? '') !== '',
        json_encode([
            'snapshot' => $ticketOne['sla_tier_snapshot'] ?? null,
            'target' => $ticketOne['sla_target_minutes'] ?? null,
        ])
    );

    $auditBeforeChange = $auditCount($standardId);
    $updateToPriority = $call('PATCH', '/api/coordinator/locations/' . $standardId, [
        'postal_code' => '08001',
        'latitude' => 41.3900,
        'longitude' => 2.1800,
        'sla_tier' => 'PRIORITY_CORPORATE',
    ], $coordinatorToken);
    $auditAfterChange = $auditCount($standardId);
    $assert(
        '1.7 El cambio efectivo de perfil responde 200 y deja UN evento de auditoría',
        $updateToPriority->getStatusCode() === 200
            && ($json($updateToPriority)['data']['sla_tier'] ?? '') === 'PRIORITY_CORPORATE'
            && $auditAfterChange === $auditBeforeChange + 1,
        "antes={$auditBeforeChange} · después={$auditAfterChange}"
    );

    $sameTierUpdate = $call('PATCH', '/api/coordinator/locations/' . $standardId, [
        'postal_code' => '08001',
        'latitude' => 41.3900,
        'longitude' => 2.1800,
        'sla_tier' => 'PRIORITY_CORPORATE',
    ], $coordinatorToken);
    $auditAfterNoop = $auditCount($standardId);
    $assert(
        '1.8 Reescribir el mismo perfil no genera auditoría ficticia',
        $sameTierUpdate->getStatusCode() === 200 && $auditAfterNoop === $auditAfterChange,
        "después del no-op={$auditAfterNoop}"
    );

    $ticketTwoMachine = $createMachine($standardId, 'SLA11-STD-COLD-02', 'COLD_DRINKS');
    $ticketTwoResponse = $call('POST', '/api/incidents', [
        'machine_id' => $ticketTwoMachine,
        'category' => 'PRODUCT_JAM',
        'description' => 'Segundo atasco en la sede ya reclasificada como prioritaria.',
    ], $standardSiteToken);
    $ticketTwoData = $json($ticketTwoResponse)['data'] ?? [];
    $ticketTwo = $fetchIncident((int)($ticketTwoData['id'] ?? 0));
    $ticketOneAfterChange = $fetchIncident($ticketOneId);
    $assert(
        '1.9 El ticket nuevo hereda PRIORITY_CORPORATE y 720 min',
        $ticketTwoResponse->getStatusCode() === 201
            && ($ticketTwo['sla_tier_snapshot'] ?? '') === 'PRIORITY_CORPORATE'
            && (int)($ticketTwo['sla_target_minutes'] ?? 0) === 720,
        'snapshot=' . ($ticketTwo['sla_tier_snapshot'] ?? 'null') . ' · target=' . ($ticketTwo['sla_target_minutes'] ?? 'null')
    );
    $assert(
        '1.10 El ticket previo conserva STANDARD y 1440 min después del cambio de sede',
        ($ticketOneAfterChange['sla_tier_snapshot'] ?? '') === 'STANDARD'
            && (int)($ticketOneAfterChange['sla_target_minutes'] ?? 0) === 1440
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1.b Alta desde Coordinación y sellado SLA (H-01)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1.b Alta desde Coordinación y sellado SLA ---\n";

    $coordLocation = $createLocation(
        'SEDE-SLA11-COORD',
        'Sede Alta Coordinación SLA 11',
        'PRIORITY_CORPORATE',
        41.3920,
        2.1820
    );
    $coordMachine = $createMachine($coordLocation['id'], 'SLA11-COORD-HOT', 'HOT_DRINKS');

    $coordAnonymous = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $coordMachine,
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'El datáfono de la máquina no acepta tarjetas.',
    ]);
    $assert(
        '1.11 El alta desde Coordinación exige token de Coordinación (401)',
        $coordAnonymous->getStatusCode() === 401
            && ($json($coordAnonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $coordTechnician = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $coordMachine,
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'El datáfono de la máquina no acepta tarjetas.',
    ], $routeTechToken);
    $assert(
        '1.12 Un técnico no puede abrir avisos desde Coordinación (403)',
        $coordTechnician->getStatusCode() === 403
            && ($json($coordTechnician)['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $coordResponse = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $coordMachine,
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'El datáfono de la máquina no acepta tarjetas.',
    ], $coordinatorToken);
    $coordBody = $json($coordResponse);
    $coordId = (int)($coordBody['data']['id'] ?? 0);
    $coordRow = $fetchIncident($coordId);
    $coordCreatedAt = strtotime((string)($coordRow['created_at'] ?? ''));
    $coordTargetAt = strtotime((string)($coordRow['sla_target_at'] ?? ''));
    $assert(
        '1.13 El alta desde Coordinación responde 201 y sella PRIORITY_CORPORATE / 480 min',
        $coordResponse->getStatusCode() === 201
            && ($coordRow['sla_tier_snapshot'] ?? '') === 'PRIORITY_CORPORATE'
            && (int)($coordRow['sla_target_minutes'] ?? 0) === 480
            && (string)($coordRow['sla_target_at'] ?? '') !== '',
        'HTTP ' . $coordResponse->getStatusCode() . ' · ' . json_encode($coordBody['error'] ?? null)
    );
    $assert(
        '1.14 La hora límite inicial equivale a created_at + 480 min (±2 s)',
        $coordCreatedAt !== false
            && $coordTargetAt !== false
            && abs(($coordTargetAt - $coordCreatedAt) - (480 * 60)) <= 2,
        "created_at={$coordRow['created_at']} · target_at={$coordRow['sla_target_at']}"
    );

    $coordDuplicate = $call('POST', '/api/coordinator/incidents', [
        'machine_id' => $coordMachine,
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Segundo intento sobre la misma máquina.',
    ], $coordinatorToken);
    $coordDuplicateBody = $json($coordDuplicate);
    $assert(
        '1.15 El alta desde Coordinación rechaza duplicados con 409',
        $coordDuplicate->getStatusCode() === 409
            && ($coordDuplicateBody['error']['code'] ?? '') === 'MACHINE_HAS_ACTIVE_INCIDENT'
            && (string)($coordDuplicateBody['error']['details']['ticket_code'] ?? '') !== '',
        'HTTP ' . $coordDuplicate->getStatusCode() . ' · ' . json_encode($coordDuplicateBody['error'] ?? null)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1.c Exposición REST en bandeja y detalle de Coordinación (EARS 05.1.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1.c Exposición REST en bandeja y detalle de Coordinación ---\n";

    $boardResponse = $call('GET', '/api/coordinator/incidents', [], $coordinatorToken);
    $boardData = $json($boardResponse)['data'] ?? [];
    $coordInBoard = null;
    foreach ($boardData as $bIncident) {
        if ((int)($bIncident['id'] ?? 0) === $coordId) {
            $coordInBoard = $bIncident;
            break;
        }
    }
    $assert(
        '1.16 La bandeja de Coordinación expone el contrato de SLA sellado (EARS 05.1.1)',
        $boardResponse->getStatusCode() === 200
            && $coordInBoard !== null
            && ($coordInBoard['sla_tier_snapshot'] ?? null) === 'PRIORITY_CORPORATE'
            && ($coordInBoard['sla_tier_label'] ?? null) === 'Prioritario Corporativo'
            && (int)($coordInBoard['sla_target_minutes'] ?? 0) === 480
            && (float)($coordInBoard['sla_target_hours'] ?? 0) === 8.0
            && is_string($coordInBoard['sla_target_at'] ?? null)
            && ($coordInBoard['is_cold_chain_risk'] ?? null) === false,
        json_encode([
            'snapshot' => $coordInBoard['sla_tier_snapshot'] ?? null,
            'target' => $coordInBoard['sla_target_minutes'] ?? null,
            'risk' => $coordInBoard['is_cold_chain_risk'] ?? null,
        ])
    );

    $detailResponse = $call('GET', '/api/coordinator/incidents/' . $coordId . '/detail', [], $coordinatorToken);
    $detailData = $json($detailResponse)['data'] ?? [];
    $detailIncident = $detailData['incident'] ?? [];
    $assert(
        '1.17 El detalle de Coordinación expone el contrato de SLA sellado (EARS 05.1.1)',
        $detailResponse->getStatusCode() === 200
            && ($detailIncident['sla_tier_snapshot'] ?? null) === 'PRIORITY_CORPORATE'
            && ($detailIncident['sla_tier_label'] ?? null) === 'Prioritario Corporativo'
            && (int)($detailIncident['sla_target_minutes'] ?? 0) === 480
            && (float)($detailIncident['sla_target_hours'] ?? 0) === 8.0
            && is_string($detailIncident['sla_target_at'] ?? null)
            && ($detailIncident['is_cold_chain_risk'] ?? null) === false,
        json_encode([
            'snapshot' => $detailIncident['sla_tier_snapshot'] ?? null,
            'target' => $detailIncident['sla_target_minutes'] ?? null,
            'risk' => $detailIncident['is_cold_chain_risk'] ?? null,
        ])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Reclasificación en Coordinación y reapertura en garantía
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Reclasificación en Coordinación y reapertura en garantía ---\n";

    $assignResponse = $call('PATCH', '/api/coordinator/incidents/' . $ticketOneId . '/assign', [
        'technician_id' => $routeTechId,
        'urgency_override' => 'CRITICAL',
        'urgency_override_reason' => 'Impacto comercial crítico confirmado por la sede durante el triaje.',
    ], $coordinatorToken);
    $ticketOneAssigned = $fetchIncident($ticketOneId);
    $assert(
        '2.1 La asignación con urgency_override=CRITICAL responde 200',
        $assignResponse->getStatusCode() === 200,
        'HTTP ' . $assignResponse->getStatusCode() . ' · ' . json_encode($json($assignResponse)['error'] ?? null)
    );
    $assert(
        '2.2 La urgencia sube y el objetivo se recalcula a 480 min sin tocar el snapshot',
        ($ticketOneAssigned['urgency'] ?? '') === 'CRITICAL'
            && (int)($ticketOneAssigned['sla_target_minutes'] ?? 0) === 480
            && ($ticketOneAssigned['sla_tier_snapshot'] ?? '') === 'STANDARD',
        json_encode([
            'urgency' => $ticketOneAssigned['urgency'] ?? null,
            'target' => $ticketOneAssigned['sla_target_minutes'] ?? null,
        ])
    );

    // Caso 4 (EARS 02.2.1 / C4): aviso con 9 h de antigüedad reclasificado a CRITICAL
    $consumedMachine = $createMachine($standardId, 'SLA11-STD-CONSUMED', 'COLD_DRINKS');
    $nineHoursAgo = $now->modify('-9 hours');
    $consumedTargetAt = $nineHoursAgo->modify('+1440 minutes');
    $consumedIncidentId = $insertIncident([
        'ticket_code' => 'INC-SLA11-CONSUMED',
        'machine_id' => $consumedMachine,
        'machine_type' => 'COLD_DRINKS',
        'location_id' => $standardId,
        'category' => 'PRODUCT_JAM',
        'description' => 'Aviso abierto hace 9 horas para certificar recálculo con margen consumido.',
        'urgency' => 'MEDIUM',
        'status' => 'REGISTERED',
        'sla_tier' => 'STANDARD',
        'sla_target_minutes' => 1440,
        'sla_target_at' => $iso($consumedTargetAt),
        'created_at' => $iso($nineHoursAgo),
    ]);

    $reclassifyConsumed = $call('PATCH', '/api/coordinator/incidents/' . $consumedIncidentId . '/assign', [
        'technician_id' => $routeTechId,
        'urgency_override' => 'CRITICAL',
        'urgency_override_reason' => 'Subida a CRÍTICA tras 9 horas consumidas; objetivo pasa a 480 min.',
    ], $coordinatorToken);

    $consumedAssigned = $fetchIncident($consumedIncidentId);
    $consumedTargetTimestamp = strtotime((string)($consumedAssigned['sla_target_at'] ?? ''));
    $nowTimestamp = $now->getTimestamp();

    $assert(
        '2.2.b Recálculo con 9 h consumidas reduce objetivo a 480 min y vence inmediatamente (EARS 02.2.1, C4)',
        $reclassifyConsumed->getStatusCode() === 200
            && (int)($consumedAssigned['sla_target_minutes'] ?? 0) === 480
            && ($consumedAssigned['sla_tier_snapshot'] ?? '') === 'STANDARD'
            && $consumedTargetTimestamp !== false
            && $consumedTargetTimestamp < $nowTimestamp
            && abs(($consumedTargetTimestamp - $nineHoursAgo->getTimestamp()) - (480 * 60)) <= 2,
        'target_at=' . ($consumedAssigned['sla_target_at'] ?? 'null') . ' · now=' . $iso($now)
    );

    $consumedDetailResp = $call('GET', '/api/coordinator/incidents/' . $consumedIncidentId . '/detail', [], $coordinatorToken);
    $consumedDetailIncident = $json($consumedDetailResp)['data']['incident'] ?? [];
    $consumedDetailTargetAt = strtotime((string)($consumedDetailIncident['sla_target_at'] ?? ''));
    $assert(
        '2.2.c El detalle de Coordinación refleja el vencimiento superado tras el recálculo (EARS 02.2.1, EARS 05.1.1)',
        $consumedDetailResp->getStatusCode() === 200
            && (int)($consumedDetailIncident['sla_target_minutes'] ?? 0) === 480
            && ($consumedDetailIncident['sla_tier_snapshot'] ?? '') === 'STANDARD'
            && $consumedDetailTargetAt !== false
            && $consumedDetailTargetAt < $nowTimestamp,
        'target_at=' . ($consumedDetailIncident['sla_target_at'] ?? 'null') . ' · now=' . $iso($now)
    );

    $resolvedAt = $now->modify('-20 hours');
    $pdo->prepare(
        "UPDATE incidents
            SET status = 'RESOLVED',
                resolved_at = :resolved_at,
                resolution_diagnosis = 'Diagnóstico de certificación de reapertura.',
                resolution_action = 'Solución de certificación de reapertura.',
                total_pending_info_seconds = 0
          WHERE id = :id"
    )->execute([
        ':resolved_at' => $iso($resolvedAt),
        ':id' => $ticketOneId,
    ]);
    $beforeReopen = $fetchIncident($ticketOneId);

    $reopenResponse = $call(
        'POST',
        '/api/incidents/' . rawurlencode($ticketOneCode) . '/reopen',
        ['reopen_reason' => 'La avería ha vuelto a aparecer tras la intervención en garantía.'],
        $standardSiteToken
    );
    $afterReopen = $fetchIncident($ticketOneId);
    $closedSeconds = max(0, $now->getTimestamp() - $resolvedAt->getTimestamp());
    $pendingAfter = (int)($afterReopen['total_pending_info_seconds'] ?? 0);
    $targetShift = strtotime((string)($afterReopen['sla_target_at'] ?? '')) - strtotime((string)($beforeReopen['sla_target_at'] ?? ''));
    $assert(
        '2.3 La reapertura dentro de 48 h responde 200 y deja el ticket REOPENED',
        $reopenResponse->getStatusCode() === 200
            && ($afterReopen['status'] ?? '') === 'REOPENED'
            && ($afterReopen['assigned_technician_id'] ?? null) === null,
        'HTTP ' . $reopenResponse->getStatusCode() . ' · ' . json_encode($json($reopenResponse)['error'] ?? null)
    );
    $assert(
        '2.4 No se reinicia created_at',
        ($afterReopen['created_at'] ?? '') === ($beforeReopen['created_at'] ?? '')
    );
    $assert(
        '2.5 El intervalo cerrado se acumula en total_pending_info_seconds',
        $pendingAfter >= $closedSeconds - 5 && $pendingAfter <= $closedSeconds + 5,
        "esperado≈{$closedSeconds} · real={$pendingAfter}"
    );
    $assert(
        '2.6 sla_target_at se desplaza exactamente por el intervalo cerrado',
        $targetShift >= $closedSeconds - 5 && $targetShift <= $closedSeconds + 5,
        "desplazamiento={$targetShift} · esperado≈{$closedSeconds}"
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. QR público: absorción térmica, opacidad y visibilidad de la sede
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. QR público: absorción térmica, opacidad y visibilidad de la sede ---\n";

    $critical = $createLocation('SEDE-SLA11-CRIT', 'Sede Crítica 24x7 SLA 11', 'CRITICAL_INFRASTRUCTURE_24_7', 41.3950, 2.1850);
    $criticalId = $critical['id'];
    $comboMachine = $createMachine($criticalId, 'SLA11-CRIT-COMBO', 'COMBO');
    $criticalLocation = $locationRepo->findById($criticalId);
    $criticalSiteToken = $authService->generateSiteToken($criticalLocation);

    $firstQr = $call('POST', '/api/qr/report', [
        'machine_code' => 'SLA11-CRIT-COMBO',
        'category' => 'PRODUCT_JAM',
        'description' => 'Atasco en la máquina mixta de la sede crítica.',
    ]);
    $firstQrBody = $json($firstQr);
    $qrIncidentId = (int)($firstQrBody['data']['incident_id'] ?? 0);
    $qrIncident = $fetchIncident($qrIncidentId);
    $qrCreatedAt = (string)($qrIncident['created_at'] ?? '');
    $assert(
        '3.1 El primer aviso QR nace con snapshot CRITICAL y objetivo contractual 480 min',
        $firstQr->getStatusCode() === 201
            && ($qrIncident['sla_tier_snapshot'] ?? '') === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (int)($qrIncident['sla_target_minutes'] ?? 0) === 480,
        'HTTP ' . $firstQr->getStatusCode() . ' · ' . json_encode($firstQrBody['error'] ?? null)
    );

    $secondQr = $call('POST', '/api/qr/report', [
        'machine_code' => 'SLA11-CRIT-COMBO',
        'category' => 'TEMPERATURE_COLD',
        'description' => 'El módulo de frío de la máquina mixta ha dejado de enfriar.',
    ]);
    $secondQrBody = $json($secondQr);
    $qrAfterAbsorption = $fetchIncident($qrIncidentId);
    $assert(
        '3.2 La absorción QR devuelve 200 merged y eleva el objetivo a 240 min',
        $secondQr->getStatusCode() === 200
            && ($secondQrBody['data']['merged'] ?? false) === true
            && (int)($qrAfterAbsorption['sla_target_minutes'] ?? 0) === 240
            && ($qrAfterAbsorption['sla_tier_snapshot'] ?? '') === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (string)($qrAfterAbsorption['created_at'] ?? '') === $qrCreatedAt,
        json_encode([
            'status' => $secondQr->getStatusCode(),
            'merged' => $secondQrBody['data']['merged'] ?? null,
            'target' => $qrAfterAbsorption['sla_target_minutes'] ?? null,
        ])
    );

    $isSlaKey = static fn (string $key): bool => preg_match('/^sla_/i', $key) === 1
        || in_array($key, ['critical_reason', 'is_cold_chain_critical', 'is_contractual_critical', 'is_cold_chain_risk'], true);

    $assert(
        '3.3 La respuesta de reporte QR no publica ninguna clave SLA ni de criticidad',
        !$hasKeyMatching($firstQrBody, $isSlaKey) && !$hasKeyMatching($secondQrBody, $isSlaKey)
    );

    $scanResponse = $call('GET', '/api/qr/scan/' . rawurlencode('SLA11-CRIT-COMBO'));
    $scanBody = $json($scanResponse);
    $assert(
        '3.4 El escaneo QR tampoco publica el contrato ni el objetivo',
        $scanResponse->getStatusCode() === 200 && !$hasKeyMatching($scanBody, $isSlaKey),
        'HTTP ' . $scanResponse->getStatusCode() . ' · ' . json_encode($scanBody['error'] ?? null)
    );

    $portalResponse = $call('GET', '/api/locations/SEDE-SLA11-CRIT/machines', [], $criticalSiteToken);
    $portalBody = $json($portalResponse);
    $portalMachine = $findBy($portalBody['data'] ?? [], 'code', 'SLA11-CRIT-COMBO');
    $portalIncident = is_array($portalMachine) ? ($portalMachine['active_incident'] ?? null) : null;
    $assert(
        '3.5 La sede sí ve el perfil y el objetivo de su propio aviso',
        $portalResponse->getStatusCode() === 200
            && is_array($portalIncident)
            && ($portalIncident['sla_tier'] ?? '') === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (int)($portalIncident['sla_target_minutes'] ?? 0) === 240
            && (string)($portalIncident['sla_target_at'] ?? '') !== '',
        json_encode($portalIncident)
    );
    $assert(
        '3.6 La opacidad QR se certifica sobre datos SLA realmente persistidos',
        ($qrAfterAbsorption['sla_target_minutes'] ?? 0) === 240
            && ($qrAfterAbsorption['sla_tier_snapshot'] ?? '') === 'CRITICAL_INFRASTRUCTURE_24_7'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Escalado contractual, Ruta y paquete offline
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Escalado contractual, Ruta y paquete offline ---\n";

    $suggestionMachine = $createMachine($criticalId, 'SLA11-CRIT-HOT', 'HOT_DRINKS');
    $suggestionId = $insertIncident([
        'ticket_code' => 'INC-SLA11-SUGGEST',
        'machine_id' => $suggestionMachine,
        'machine_type' => 'HOT_DRINKS',
        'location_id' => $criticalId,
        'category' => 'PAYMENT_SYSTEM',
        'urgency' => 'CRITICAL',
        'status' => 'REGISTERED',
        'sla_tier' => 'CRITICAL_INFRASTRUCTURE_24_7',
        'sla_target_minutes' => 240,
        'sla_target_at' => $iso($now->modify('+3 hours')),
        'created_at' => $iso($now->modify('-1 hour')),
    ]);
    $suggestionResponse = $call(
        'GET',
        '/api/coordinator/incidents/' . $suggestionId . '/suggested-technicians',
        [],
        $coordinatorToken
    );
    $suggestionBody = $json($suggestionResponse);
    $suggestionData = $suggestionBody['data'] ?? [];
    $assert(
        '4.1 La sugerencia publica el contrato SLA del ticket',
        $suggestionResponse->getStatusCode() === 200
            && ($suggestionData['incident']['sla_tier_snapshot'] ?? '') === 'CRITICAL_INFRASTRUCTURE_24_7'
            && (int)($suggestionData['incident']['sla_target_minutes'] ?? 0) === 240
            && ($suggestionData['incident']['is_cold_chain_risk'] ?? true) === false,
        json_encode($suggestionData['incident'] ?? null)
    );
    $assert(
        '4.2 La sede crítica sin cuadrante activa el escalado contractual 24/7',
        ($suggestionData['recommendation']['contractual_24_7_escalation_active'] ?? false) === true
            && (int)($suggestionData['recommendation']['top_technician_id'] ?? 0) === $onCallTechId,
        json_encode($suggestionData['recommendation'] ?? null)
    );

    $routeCold = $createLocation('SEDE-SLA11-ROUTE-COLD', 'Ruta Frío SLA 11', 'PRIORITY_CORPORATE', 41.4000, 2.1900);
    $routeHot = $createLocation('SEDE-SLA11-ROUTE-HOT', 'Ruta Contractual SLA 11', 'PRIORITY_CORPORATE', 41.4050, 2.1950);
    $routeColdMachine = $createMachine($routeCold['id'], 'SLA11-ROUTE-COLD', 'PERISHABLE_FOOD');
    $routeHotMachine = $createMachine($routeHot['id'], 'SLA11-ROUTE-HOT', 'HOT_DRINKS');
    $routeColdId = $insertIncident([
        'ticket_code' => 'INC-SLA11-ROUTE-COLD',
        'machine_id' => $routeColdMachine,
        'machine_type' => 'PERISHABLE_FOOD',
        'location_id' => $routeCold['id'],
        'technician_id' => $routeTechId,
        'category' => 'TEMPERATURE_COLD',
        'urgency' => 'CRITICAL',
        'status' => 'ASSIGNED',
        'assigned_at' => $iso($now->modify('-30 minutes')),
        'sla_tier' => 'PRIORITY_CORPORATE',
        'sla_target_minutes' => 240,
        'sla_target_at' => $iso($now->modify('+2 hours')),
        'created_at' => $iso($now->modify('-1 hour')),
    ]);
    $routeHotId = $insertIncident([
        'ticket_code' => 'INC-SLA11-ROUTE-HOT',
        'machine_id' => $routeHotMachine,
        'machine_type' => 'HOT_DRINKS',
        'location_id' => $routeHot['id'],
        'technician_id' => $routeTechId,
        'category' => 'PAYMENT_SYSTEM',
        'urgency' => 'HIGH',
        'status' => 'ASSIGNED',
        'assigned_at' => $iso($now->modify('-40 minutes')),
        'sla_tier' => 'PRIORITY_CORPORATE',
        'sla_target_minutes' => 240,
        'sla_target_at' => $iso($now->modify('+4 hours')),
        'created_at' => $iso($now->modify('-2 hours')),
    ]);

    $routeResponse = $call('GET', '/api/technician/route/map', [], $routeTechToken);
    $routeBody = $json($routeResponse);
    $stops = $routeBody['data']['stops'] ?? [];
    $coldStop = null;
    $hotStop = null;
    foreach ($stops as $stop) {
        $site = $stop['location']['site_code'] ?? '';
        if ($site === 'SEDE-SLA11-ROUTE-COLD') {
            $coldStop = $stop;
        }
        if ($site === 'SEDE-SLA11-ROUTE-HOT') {
            $hotStop = $stop;
        }
    }
    $coldTask = is_array($coldStop) ? ($coldStop['tasks'][0] ?? []) : [];
    $hotTask = is_array($hotStop) ? ($hotStop['tasks'][0] ?? []) : [];
    $assert(
        '4.3 La Ruta publica perfil, vencimiento y criticidad de cada tarea',
        $routeResponse->getStatusCode() === 200
            && ($coldTask['sla_tier'] ?? '') === 'PRIORITY_CORPORATE'
            && (int)($coldTask['sla_target_minutes'] ?? 0) === 240
            && ($coldTask['critical_reason'] ?? '') === 'COLD_CHAIN'
            && ($coldTask['is_cold_chain_critical'] ?? false) === true
            && ($hotTask['critical_reason'] ?? '') === 'CONTRACTUAL_SLA'
            && ($hotTask['is_contractual_critical'] ?? false) === true,
        json_encode(['cold' => $coldTask, 'hot' => $hotTask])
    );
    $assert(
        '4.4 La prelación sanitaria coloca la parada de frío antes que la contractual',
        is_array($coldStop) && is_array($hotStop)
            && (int)($coldStop['order'] ?? 0) < (int)($hotStop['order'] ?? 0)
            && ($stops[0]['is_cold_chain_critical'] ?? false) === true,
        'orden frío=' . ($coldStop['order'] ?? 'null') . ' · contractual=' . ($hotStop['order'] ?? 'null')
    );

    $offlineResponse = $call('GET', '/api/technician/offline/bootstrap', [], $routeTechToken);
    $offlineBody = $json($offlineResponse);
    $offlineTasks = [];
    foreach (($offlineBody['data']['route']['stops'] ?? []) as $stop) {
        if (isset($stop['ticket_code'])) {
            $offlineTasks[(string)$stop['ticket_code']] = $stop;
        }
        foreach (($stop['tasks'] ?? []) as $task) {
            if (isset($task['ticket_code'])) {
                $offlineTasks[(string)$task['ticket_code']] = $task;
            }
        }
    }
    $offlineCold = $offlineTasks['INC-SLA11-ROUTE-COLD'] ?? [];
    $offlineHot = $offlineTasks['INC-SLA11-ROUTE-HOT'] ?? [];
    $assert(
        '4.5 El paquete offline entrega el contrato SLA de las dos tareas',
        $offlineResponse->getStatusCode() === 200
            && ($offlineCold['sla_tier_snapshot'] ?? '') === 'PRIORITY_CORPORATE'
            && (int)($offlineCold['sla_target_minutes'] ?? 0) === 240
            && (string)($offlineCold['sla_target_at'] ?? '') !== ''
            && ($offlineCold['critical_reason'] ?? '') === 'COLD_CHAIN'
            && ($offlineHot['critical_reason'] ?? '') === 'CONTRACTUAL_SLA',
        json_encode(['cold' => $offlineCold, 'hot' => $offlineHot])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Métricas por perfil y exportación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Métricas por perfil y exportación ---\n";

    $metrics = $createLocation('SEDE-SLA11-METRICS', 'Sede Métricas SLA 11', 'PRIORITY_CORPORATE', 41.4100, 2.2000);
    $metricsId = $metrics['id'];
    $metricsMachineA = $createMachine($metricsId, 'SLA11-METRICS-A', 'COLD_DRINKS');
    $metricsMachineB = $createMachine($metricsId, 'SLA11-METRICS-B', 'COLD_DRINKS');
    $resolvedAt = $now->modify('-1 hour');
    $metricsCreatedCompliant = $now->modify('-2 hours');
    $metricsCreatedBreached = $now->modify('-15 hours');
    $insertIncident([
        'ticket_code' => 'INC-SLA11-METRICS-OK',
        'machine_id' => $metricsMachineA,
        'machine_type' => 'COLD_DRINKS',
        'location_id' => $metricsId,
        'technician_id' => $routeTechId,
        'category' => 'PRODUCT_JAM',
        'urgency' => 'MEDIUM',
        'status' => 'RESOLVED',
        'assigned_at' => $iso($metricsCreatedCompliant),
        'started_at' => $iso($metricsCreatedCompliant),
        'resolved_at' => $iso($resolvedAt),
        'diagnosis' => 'Diagnóstico conforme de la métrica de certificación.',
        'action' => 'Resolución conforme de la métrica de certificación.',
        'sla_tier' => 'PRIORITY_CORPORATE',
        'sla_target_minutes' => 720,
        'sla_target_at' => $iso($metricsCreatedCompliant->modify('+12 hours')),
        'created_at' => $iso($metricsCreatedCompliant),
    ]);
    $insertIncident([
        'ticket_code' => 'INC-SLA11-METRICS-BREACH',
        'machine_id' => $metricsMachineB,
        'machine_type' => 'COLD_DRINKS',
        'location_id' => $metricsId,
        'technician_id' => $routeTechId,
        'category' => 'PRODUCT_JAM',
        'urgency' => 'MEDIUM',
        'status' => 'RESOLVED',
        'assigned_at' => $iso($metricsCreatedBreached),
        'started_at' => $iso($metricsCreatedBreached),
        'resolved_at' => $iso($resolvedAt),
        'diagnosis' => 'Diagnóstico de la métrica con brecha contractual.',
        'action' => 'Resolución de la métrica con brecha contractual.',
        'sla_tier' => 'PRIORITY_CORPORATE',
        'sla_target_minutes' => 720,
        'sla_target_at' => $iso($metricsCreatedBreached->modify('+12 hours')),
        'created_at' => $iso($metricsCreatedBreached),
    ]);

    $range = [
        'from' => $now->modify('-2 days')->format('Y-m-d'),
        'to' => $now->modify('+1 day')->format('Y-m-d'),
    ];
    $breakdownResponse = $call('GET', '/api/coordinator/metrics/breakdown', [], $coordinatorToken, $range);
    $breakdownBody = $json($breakdownResponse);
    $metricsRow = null;
    foreach (($breakdownBody['data']['by_location'] ?? []) as $row) {
        if (($row['site_code'] ?? '') === 'SEDE-SLA11-METRICS') {
            $metricsRow = $row;
            break;
        }
    }
    $assert(
        '5.1 El desglose por sede publica perfil, horas nominales y brechas reales',
        $breakdownResponse->getStatusCode() === 200
            && is_array($metricsRow)
            && ($metricsRow['sla_tier'] ?? '') === 'PRIORITY_CORPORATE'
            && ($metricsRow['sla_tier_label'] ?? '') === 'Prioritario Corporativo'
            && (float)($metricsRow['sla_target_hours'] ?? 0) === 12.0
            && (int)($metricsRow['sla_breaches'] ?? 0) === 1
            && ($metricsRow['sla_status'] ?? '') === 'BREACHED',
        json_encode($metricsRow)
    );

    $summaryResponse = $call('GET', '/api/coordinator/metrics/summary', [], $coordinatorToken, $range);
    $summaryBody = $json($summaryResponse);
    $assert(
        '5.2 El resumen mantiene el contrato general y el bloque de alertas SLA',
        $summaryResponse->getStatusCode() === 200
            && isset($summaryBody['data']['kpis'])
            && (float)($summaryBody['data']['sla_alerts']['general']['sla_target_hours'] ?? 0) === 24.0
    );

    $exportResponse = $call('GET', '/api/coordinator/metrics/export', [], $coordinatorToken, $range);
    $csv = $exportResponse->getBody();
    $assert(
        '5.3 La exportación CSV incluye las columnas y el perfil de la sede',
        $exportResponse->getStatusCode() === 200
            && str_contains((string)$exportResponse->getHeader('Content-Type'), 'text/csv')
            && str_contains($csv, 'Nivel SLA')
            && str_contains($csv, 'Objetivo SLA (Horas)')
            && str_contains($csv, 'Brechas SLA')
            && str_contains($csv, 'Prioritario Corporativo')
            && str_contains($csv, '12.0'),
        'HTTP ' . $exportResponse->getStatusCode() . ' · bytes=' . strlen($csv)
    );

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones · " . ($failures === 0 ? 'TODO EN VERDE' : "{$failures} FALLOS") . "\n";
    echo "======================================================================\n";
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[CRASH] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, TIERED_SLA_API_SCHEMA);
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupError->getMessage() . "\n");
        $crash ??= $cleanupError;
    }
}

if ($crash !== null) {
    exit(1);
}

exit($failures === 0 ? 0 : 1);
