<?php

declare(strict_types=1);

/**
 * SiteZonePrivacyTest · T-ZONE-10
 *
 * Certifica el **blindaje del Art. V.4** para la demarcación territorial del
 * Módulo 17: la sede nunca ve a qué zona operativa pertenece, ni si su
 * asignación es heredada o forzada a mano, ni el cuadrante técnico.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Superficie de sede:** con token de centro, el catálogo de máquinas y el
 *      alta de un aviso responden sin una sola clave territorial —ni en el primer
 *      nivel ni anidada—, mientras el expediente conserva sus datos legítimos.
 *   2. **Sanitizador:** `LocationPortalController::sanitizeIncidentForSite()`
 *      retira zona, excepción manual y afinidad de un bloque enriquecido y deja
 *      intacto todo lo que la sede sí debe leer.
 *   3. **Contraste de rol:** la misma sede sí publica `zone_id` a Coordinación, de
 *      modo que la ocultación es por audiencia y no por pérdida de dato.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. El escenario vive en un esquema aislado sobre la
 *   conexión de la aplicación; sin motor, sale con código 2 en lugar de omitirse.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una
 * aserción.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/SiteZonePrivacyTest.php
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
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const SITE_PRIVACY_TEST_SCHEMA = 't_zone10_privacy';

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
echo " VENDGUARD · T-ZONE-10 · Privacidad territorial del portal de sede (Art. V.4)\n";
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

/** Claves territoriales que la sede no debe recibir nunca (Art. V.4). */
const SITE_FORBIDDEN_ZONE_KEYS = [
    'zone_id',
    'zone_name',
    'zone_code',
    'zone_color_hex',
    'zone_affinity_type',
    'zone_affinity_label',
    'is_manual_override',
    'operational_zone_id',
];

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, SITE_PRIVACY_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $pdo->exec("INSERT INTO route_settings (base_name) VALUES ('Base V4')");

    $hasZoneKey = static function (mixed $value) use (&$hasZoneKey): bool {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array($key, SITE_FORBIDDEN_ZONE_KEYS, true)) {
                return true;
            }
            if ($hasZoneKey($nested)) {
                return true;
            }
        }

        return false;
    };

    $coordinatorStmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
         VALUES (:name, :email, :email_key, :hash, :role, :operator) RETURNING id'
    );
    $coordinatorStmt->execute([
        ':name' => 'Coordinación Privacidad',
        ':email' => 'zona10c.coord@vendguard.internal',
        ':email_key' => 'zona10c.coord@vendguard.internal',
        ':hash' => password_hash('zona10c', PASSWORD_BCRYPT),
        ':role' => 'COORDINATOR',
        ':operator' => 'Z10C-COORD',
    ]);
    $coordinatorId = (int)$coordinatorStmt->fetchColumn();

    $zoneStmt = $pdo->prepare("INSERT INTO operational_zones (code, name, color_hex) VALUES ('ZONA-V4-T10', 'Zona Reservada V4', '#2560ff') RETURNING id");
    $zoneStmt->execute();
    $zoneId = (int)$zoneStmt->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, postal_code, zone_id, is_manual_override)
         VALUES (:site, :site_key, :name, :address, :lat, :lng, :cp, :zone_id, TRUE) RETURNING id'
    );
    $locationStmt->execute([
        ':site' => 'SEDE-V4-01',
        ':site_key' => 'SEDE-V4-01',
        ':name' => 'Centro Cívico Reservado',
        ':address' => 'Calle del Secreto 4',
        ':lat' => 41.3800,
        ':lng' => 2.1700,
        ':cp' => '08001',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, :type, :wing) RETURNING id'
    );
    $machineStmt->execute([
        ':location_id' => $locationId,
        ':code' => 'MAQ-V4-01',
        ':model' => 'Modelo Privacidad',
        ':type' => 'COLD_DRINKS',
        ':wing' => 'Vestíbulo',
    ]);
    $machineId = (int)$machineStmt->fetchColumn();

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $location = $locationRepo->findById($locationId);
    $siteToken = $authService->generateSiteToken($location);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));

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
    // 1. Catálogo de máquinas de la sede
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Catálogo de máquinas de la sede ---\n";

    $machinesResponse = $call('GET', '/api/locations/SEDE-V4-01/machines', [], $siteToken);
    $machinesBody = $json($machinesResponse);
    $machinesData = $machinesBody['data'] ?? [];

    $assert(
        '1.1 La sede lee su parque con el token de centro (200)',
        $machinesResponse->getStatusCode() === 200
            && ($machinesBody['success'] ?? false) === true
            && count($machinesData) === 1
            && ($machinesData[0]['code'] ?? '') === 'MAQ-V4-01',
        json_encode(array_column($machinesData, 'code'))
    );

    $assert(
        '1.2 El catálogo no publica ninguna clave territorial, ni anidada (Art. V.4)',
        !$hasZoneKey($machinesData),
        json_encode($machinesData)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Alta de un aviso desde la sede
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Alta de aviso desde la sede ---\n";

    $createdResponse = $call('POST', '/api/incidents', [
        'machine_id' => $machineId,
        'category' => 'PRODUCT_JAM',
        'description' => 'La espiral de snacks no entrega el producto seleccionado.',
    ], $siteToken);
    $createdBody = $json($createdResponse);
    $createdData = $createdBody['data'] ?? [];

    $assert(
        '2.1 El alta responde 201 con el expediente creado',
        $createdResponse->getStatusCode() === 201
            && ($createdBody['success'] ?? false) === true
            && ($createdData['ticket_code'] ?? '') !== '',
        json_encode($createdBody)
    );

    $assert(
        '2.2 La respuesta del aviso no revela zona, excepción manual ni afinidad',
        !$hasZoneKey($createdData),
        json_encode(array_keys($createdData))
    );

    $assert(
        '2.3 El expediente conserva los datos que la sede sí debe leer',
        ($createdData['status'] ?? '') === 'REGISTERED'
            && ($createdData['category'] ?? '') === 'PRODUCT_JAM'
            && isset($createdData['machine_id']),
        json_encode($createdData)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Sanitizador del portal con un bloque enriquecido
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Sanitizador con bloque enriquecido ---\n";

    $controller = new LocationPortalController();
    $sanitized = $controller->sanitizeIncidentForSite([
        'id' => 42,
        'status' => 'ASSIGNED',
        'ticket_code' => 'INC-20261020-V4001',
        'zone_id' => $zoneId,
        'zone_name' => 'Zona Reservada V4',
        'is_manual_override' => true,
        'location' => [
            'id' => $locationId,
            'site_code' => 'SEDE-V4-01',
            'zone_id' => $zoneId,
            'zone_name' => 'Zona Reservada V4',
            'zone_affinity_type' => 'PRIMARY',
            'zone_affinity_label' => 'Titular de zona',
            'postal_code' => '08001',
        ],
        'technician_zone' => ['zone_id' => $zoneId, 'is_primary' => true],
    ]);

    $assert(
        '3.1 El sanitizador retira todas las claves territoriales del expediente',
        !$hasZoneKey($sanitized),
        json_encode($sanitized)
    );

    $assert(
        '3.2 El resto del expediente sobrevive intacto',
        ($sanitized['id'] ?? null) === 42
            && ($sanitized['ticket_code'] ?? '') === 'INC-20261020-V4001'
            && ($sanitized['location']['site_code'] ?? '') === 'SEDE-V4-01'
            && ($sanitized['location']['postal_code'] ?? '') === '08001',
        json_encode($sanitized)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Contraste de rol: Coordinación sí ve la zona
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Contraste de rol ---\n";

    $coordinatorView = $json($call('GET', '/api/coordinator/locations/' . $locationId, [], $coordinatorToken));
    $assert(
        '4.1 La misma sede sí publica su zona a Coordinación (la ocultación es por audiencia)',
        ($coordinatorView['data']['zone_id'] ?? null) === $zoneId
            && ($coordinatorView['data']['is_manual_override'] ?? null) === true,
        json_encode($coordinatorView['data'] ?? null)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, SITE_PRIVACY_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ART. V.4 CERTIFICADO EN LA SUPERFICIE DE SEDE.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
