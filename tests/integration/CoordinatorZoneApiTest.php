<?php

declare(strict_types=1);

/**
 * CoordinatorZoneApiTest · T-ZONE-09
 *
 * Certifica la **superficie HTTP** de la zonificación operativa de punta a punta:
 * el router real, el middleware de Coordinación, el controlador, el servicio y el
 * repositorio contra el mismo motor que la aplicación. No hay dobles: lo que se
 * prueba es que la ruta registrada responde con el código y la forma del contrato.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **RBAC (Art. V.4, RF-07.1).** Sin token `401 UNAUTHORIZED`; con token de
 *      técnico `403 FORBIDDEN`. Las ocho rutas están detrás del mismo middleware.
 *   2. **Catálogo (RF-01.1).** Alta `201`, listado con contadores y sedes sin zona,
 *      ficha `200`, `404 ZONE_NOT_FOUND` y `400 VALIDATION_ERROR` para un id no
 *      numérico. El código y el nombre duplicados responden `409`.
 *   3. **Códigos postales (RF-01.2 / RF-01.3).** Alta de CP libres, conflicto
 *      `409 POSTAL_CODES_REQUIRE_CONFIRMATION` con `error.conflicts`, traslado
 *      confirmado con sedes heredadas recolocadas, `400` de formato y `404` de zona.
 *   4. **Cuadrante (RF-02.1 / RF-02.2).** Vinculación, `409
 *      TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`, confirmación con degradación,
 *      `422` para un técnico inactivo y `DELETE` con `200` / `404`.
 *   5. **Excepción manual de sede (RF-03.2 / RF-03.3).** Forzar `200`, restablecer
 *      la herencia `200`, sede inexistente `404` y zona inactiva `422`.
 *   6. **Desactivación (RF-01.3 / QA 7 / QA 13).** Sin `confirm_release` responde
 *      `422`; con confirmación libera el territorio y el CP queda reasignable.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. El escenario vive en un esquema aislado sobre la
 *   conexión de la aplicación, de modo que el router y los repositorios que éste
 *   construye trabajan sobre las mismas tablas efímeras. Sin motor, sale con
 *   código 2 en lugar de omitirse en silencio.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una
 * aserción.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/CoordinatorZoneApiTest.php
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

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const ZONE_API_TEST_SCHEMA = 't_zone09_api';

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
echo " VENDGUARD · T-ZONE-09 · API REST de zonas operativas (Módulo 17)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// Escenario aislado
// ─────────────────────────────────────────────────────────────────────────────

/** @var array<string, mixed> $json */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, ZONE_API_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, bool $isActive = true) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'zona09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'zona09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('zona09-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'Z09-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
            ':is_on_call' => 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianOne = $createUser('uno', 'TECHNICIAN');
    $technicianTwo = $createUser('dos', 'TECHNICIAN');
    $technicianInactive = $createUser('baja', 'TECHNICIAN', false);

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianOne));

    $router = AppRouter::create();

    /** Petición al router real con la cabecera y el cuerpo indicados. */
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
    echo "\n--- 1. RBAC de las rutas territoriales ---\n";

    $anonymous = $call('GET', '/api/coordinator/zones');
    $anonymousBody = $json($anonymous);
    $assert(
        '1.1 Sin token el listado de zonas responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && ($anonymousBody['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $technicianAttempt = $call('GET', '/api/coordinator/zones', [], $technicianToken);
    $technicianBody = $json($technicianAttempt);
    $assert(
        '1.2 Con token de técnico responde 403 FORBIDDEN',
        $technicianAttempt->getStatusCode() === 403
            && ($technicianBody['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $technicianPost = $call('POST', '/api/coordinator/zones', ['code' => 'X', 'name' => 'Y'], $technicianToken);
    $assert(
        '1.3 La escritura territorial también está cerrada al técnico (403)',
        $technicianPost->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Catálogo de zonas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Catálogo de zonas ---\n";

    $empty = $call('GET', '/api/coordinator/zones', [], $coordinatorToken);
    $emptyBody = $json($empty);
    $assert(
        '2.1 El catálogo arranca vacío con el contador de sedes sin zona a cero',
        $empty->getStatusCode() === 200
            && ($emptyBody['success'] ?? false) === true
            && ($emptyBody['data']['zones'] ?? null) === []
            && ($emptyBody['data']['unassigned_locations_count'] ?? null) === 0
    );

    $createdNorth = $call('POST', '/api/coordinator/zones', [
        'code' => 'zona-norte-s09',
        'name' => 'Zona Norte de Certificación API',
        'color_hex' => '#2560FF',
        'description' => 'Demarcación norte del arnés HTTP',
    ], $coordinatorToken);
    $northBody = $json($createdNorth);
    $northId = (int)($northBody['data']['id'] ?? 0);
    $assert(
        '2.2 El alta responde 201 con la zona normalizada',
        $createdNorth->getStatusCode() === 201
            && $northId > 0
            && ($northBody['data']['code'] ?? '') === 'ZONA-NORTE-S09'
            && ($northBody['data']['color_hex'] ?? '') === '#2560ff',
        json_encode($northBody)
    );

    $duplicateCode = $call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-NORTE-S09',
        'name' => 'Otra zona con el mismo código',
    ], $coordinatorToken);
    $duplicateBody = $json($duplicateCode);
    $assert(
        '2.3 Un código duplicado responde 409 ZONE_CODE_ALREADY_EXISTS',
        $duplicateCode->getStatusCode() === 409
            && ($duplicateBody['error']['code'] ?? '') === 'ZONE_CODE_ALREADY_EXISTS',
        json_encode($duplicateBody)
    );

    $duplicateName = $call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-OTRA-S09',
        'name' => 'Zona Norte de Certificación API',
    ], $coordinatorToken);
    $assert(
        '2.4 Un nombre duplicado responde 409 ZONE_NAME_ALREADY_EXISTS',
        $duplicateName->getStatusCode() === 409
            && ($json($duplicateName)['error']['code'] ?? '') === 'ZONE_NAME_ALREADY_EXISTS'
    );

    $invalidColor = $call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-COLOR-S09',
        'name' => 'Zona con color inválido',
        'color_hex' => 'azul',
    ], $coordinatorToken);
    $assert(
        '2.5 Un color fuera de #RRGGBB responde 400 BAD_REQUEST con el campo señalado',
        $invalidColor->getStatusCode() === 400
            && ($json($invalidColor)['error']['code'] ?? '') === 'BAD_REQUEST'
            && isset($json($invalidColor)['error']['field_errors']['color_hex'])
    );

    $southId = (int)($json($call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-SUR-S09',
        'name' => 'Zona Sur de Certificación API',
        'color_hex' => '#10b981',
    ], $coordinatorToken))['data']['id'] ?? 0);
    $eastId = (int)($json($call('POST', '/api/coordinator/zones', [
        'code' => 'ZONA-ESTE-S09',
        'name' => 'Zona Este de Certificación API',
        'color_hex' => '#dc2626',
    ], $coordinatorToken))['data']['id'] ?? 0);
    $assert('2.6 Las zonas de apoyo se crean con identificador', $southId > 0 && $eastId > 0);

    $detail = $call('GET', '/api/coordinator/zones/' . $northId, [], $coordinatorToken);
    $assert(
        '2.7 La ficha de la zona responde 200 con su forma de contrato',
        $detail->getStatusCode() === 200
            && ($json($detail)['data']['postal_codes'] ?? null) === []
            && array_key_exists('locations_count', $json($detail)['data'] ?? [])
    );

    $missingZone = $call('GET', '/api/coordinator/zones/999999', [], $coordinatorToken);
    $assert(
        '2.8 Una zona inexistente responde 404 ZONE_NOT_FOUND',
        $missingZone->getStatusCode() === 404
            && ($json($missingZone)['error']['code'] ?? '') === 'ZONE_NOT_FOUND'
    );

    $badId = $call('GET', '/api/coordinator/zones/abc', [], $coordinatorToken);
    $assert(
        '2.9 Un id no numérico responde 400 VALIDATION_ERROR',
        $badId->getStatusCode() === 400
            && ($json($badId)['error']['code'] ?? '') === 'VALIDATION_ERROR'
    );

    $renamed = $call('PUT', '/api/coordinator/zones/' . $northId, [
        'name' => 'Zona Norte Certificada',
    ], $coordinatorToken);
    $assert(
        '2.10 La edición de metadatos responde 200 con el nombre nuevo',
        $renamed->getStatusCode() === 200
            && ($json($renamed)['data']['name'] ?? '') === 'Zona Norte Certificada'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Códigos postales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Códigos postales y traslados ---\n";

    $assignFree = $call('POST', '/api/coordinator/zones/' . $northId . '/postal-codes', [
        'postal_codes' => ['08002', '08001'],
    ], $coordinatorToken);
    $assignBody = $json($assignFree);
    $assert(
        '3.1 El alta de CP libres responde 200 con el lote ordenado',
        $assignFree->getStatusCode() === 200
            && ($assignBody['data']['assigned_postal_codes'] ?? null) === ['08001', '08002']
            && ($assignBody['data']['transferred_postal_codes'] ?? null) === []
            && ($assignBody['data']['affected_locations_count'] ?? null) === 0,
        json_encode($assignBody)
    );

    $inheritedLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z09-HEREDADA',
        'name' => 'Sede heredada del CP 08001',
        'address' => 'Calle de la Certificación 1, Barcelona',
        'postal_code' => '08001',
    ]);
    $orphanLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z09-SIN-ZONA',
        'name' => 'Sede sin catálogo de CP',
        'address' => 'Calle de la Certificación 2, Barcelona',
        'postal_code' => '99999',
    ]);
    $assert(
        '3.2 La sede hereda la zona del CP y la huérfana queda sin zona',
        $locationRepo->findById($inheritedLocation->getId())?->getZoneId() === $northId
            && $locationRepo->findById($orphanLocation->getId())?->getZoneId() === null
    );

    $badPostalCodes = $call('POST', '/api/coordinator/zones/' . $northId . '/postal-codes', [
        'postal_codes' => 'no-es-una-lista',
    ], $coordinatorToken);
    $assert(
        '3.3 Un lote que no es lista responde 400 VALIDATION_ERROR',
        $badPostalCodes->getStatusCode() === 400
            && ($json($badPostalCodes)['error']['code'] ?? '') === 'VALIDATION_ERROR'
    );

    $invalidFormat = $call('POST', '/api/coordinator/zones/' . $northId . '/postal-codes', [
        'postal_codes' => ['BARCELONA'],
    ], $coordinatorToken);
    $assert(
        '3.4 Un CP no numérico responde 400 BAD_REQUEST',
        $invalidFormat->getStatusCode() === 400
            && ($json($invalidFormat)['error']['code'] ?? '') === 'BAD_REQUEST'
    );

    $conflict = $call('POST', '/api/coordinator/zones/' . $southId . '/postal-codes', [
        'postal_codes' => ['08001', '08003'],
    ], $coordinatorToken);
    $conflictBody = $json($conflict);
    $assert(
        '3.5 Un CP de otra zona sin confirmación responde 409 con error.conflicts',
        $conflict->getStatusCode() === 409
            && ($conflictBody['error']['code'] ?? '') === 'POSTAL_CODES_REQUIRE_CONFIRMATION'
            && ($conflictBody['error']['conflicts'][0]['postal_code'] ?? null) === '08001'
            && ($conflictBody['error']['conflicts'][0]['current_zone_id'] ?? null) === $northId
            && ($conflictBody['error']['conflicts'][0]['affected_locations_count'] ?? null) === 1,
        json_encode($conflictBody)
    );

    $transfer = $call('POST', '/api/coordinator/zones/' . $southId . '/postal-codes', [
        'postal_codes' => ['08001', '08003'],
        'confirm_transfers' => true,
    ], $coordinatorToken);
    $transferBody = $json($transfer);
    $assert(
        '3.6 Con confirm_transfers el CP se mueve y la sede heredada se recoloca',
        $transfer->getStatusCode() === 200
            && ($transferBody['data']['transferred_postal_codes'][0]['previous_zone_id'] ?? null) === $northId
            && ($transferBody['data']['affected_locations_count'] ?? null) === 1
            && $locationRepo->findById($inheritedLocation->getId())?->getZoneId() === $southId,
        json_encode($transferBody)
    );

    $missingZoneCp = $call('POST', '/api/coordinator/zones/999999/postal-codes', [
        'postal_codes' => ['08010'],
    ], $coordinatorToken);
    $assert(
        '3.7 Asignar CP a una zona inexistente responde 404 ZONE_NOT_FOUND',
        $missingZoneCp->getStatusCode() === 404
            && ($json($missingZoneCp)['error']['code'] ?? '') === 'ZONE_NOT_FOUND'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Cuadrante técnico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Cuadrante técnico ---\n";

    $primary = $call('POST', '/api/coordinator/zones/' . $northId . '/technicians', [
        'technician_id' => $technicianOne,
        'is_primary' => true,
    ], $coordinatorToken);
    $primaryBody = $json($primary);
    $assert(
        '4.1 La vinculación como titular responde 200 con la asignación',
        $primary->getStatusCode() === 200
            && ($primaryBody['data']['assignment']['technician_id'] ?? null) === $technicianOne
            && ($primaryBody['data']['assignment']['is_primary'] ?? null) === true
            && (int)($primaryBody['data']['assignment']['id'] ?? 0) > 0,
        json_encode($primaryBody)
    );

    $primaryConflict = $call('POST', '/api/coordinator/zones/' . $southId . '/technicians', [
        'technician_id' => $technicianOne,
        'is_primary' => true,
    ], $coordinatorToken);
    $assert(
        '4.2 La segunda titularidad sin confirmación responde 409 con la zona actual',
        $primaryConflict->getStatusCode() === 409
            && ($json($primaryConflict)['error']['code'] ?? '') === 'TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE'
            && ($json($primaryConflict)['error']['current_zone_id'] ?? null) === $northId
    );

    $overridden = $call('POST', '/api/coordinator/zones/' . $southId . '/technicians', [
        'technician_id' => $technicianOne,
        'is_primary' => true,
        'confirm_primary_override' => true,
    ], $coordinatorToken);
    $demotedPrimary = (int)$pdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments
         WHERE technician_id = ' . $technicianOne . ' AND zone_id = ' . $northId . '
           AND is_primary = FALSE AND removed_at IS NULL'
    )->fetchColumn();
    $assert(
        '4.3 Con confirm_primary_override responde 200 y degrada la titularidad previa',
        $overridden->getStatusCode() === 200
            && ($json($overridden)['data']['assignment']['zone_id'] ?? null) === $southId
            && $demotedPrimary === 1
    );

    $inactiveAttempt = $call('POST', '/api/coordinator/zones/' . $northId . '/technicians', [
        'technician_id' => $technicianInactive,
        'is_primary' => false,
    ], $coordinatorToken);
    $assert(
        '4.4 Un técnico inactivo responde 422 UNPROCESSABLE_ENTITY',
        $inactiveAttempt->getStatusCode() === 422
            && ($json($inactiveAttempt)['error']['code'] ?? '') === 'UNPROCESSABLE_ENTITY'
    );

    $badTechnician = $call('POST', '/api/coordinator/zones/' . $northId . '/technicians', [
        'technician_id' => 'abc',
        'is_primary' => false,
    ], $coordinatorToken);
    $assert(
        '4.5 Un technician_id no numérico responde 400 VALIDATION_ERROR',
        $badTechnician->getStatusCode() === 400
    );

    $call('POST', '/api/coordinator/zones/' . $northId . '/technicians', [
        'technician_id' => $technicianTwo,
        'is_primary' => true,
    ], $coordinatorToken);

    $removed = $call(
        'DELETE',
        '/api/coordinator/zones/' . $southId . '/technicians/' . $technicianOne,
        [],
        $coordinatorToken
    );
    $assert(
        '4.6 La baja del cuadrante responde 200 con baja lógica',
        $removed->getStatusCode() === 200
            && ($json($removed)['data']['removed'] ?? null) === true
    );

    $removedAgain = $call(
        'DELETE',
        '/api/coordinator/zones/' . $southId . '/technicians/' . $technicianOne,
        [],
        $coordinatorToken
    );
    $assert(
        '4.7 Repetir la baja sin vinculación viva responde 404 ASSIGNMENT_NOT_FOUND',
        $removedAgain->getStatusCode() === 404
            && ($json($removedAgain)['error']['code'] ?? '') === 'ASSIGNMENT_NOT_FOUND'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Excepción manual de sede
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Excepción manual de sede ---\n";

    $forced = $call('POST', '/api/coordinator/locations/' . $orphanLocation->getId() . '/override-zone', [
        'zone_id' => $northId,
        'is_manual_override' => true,
    ], $coordinatorToken);
    $forcedBody = $json($forced);
    $assert(
        '5.1 Forzar la zona responde 200 con MANUAL_OVERRIDE',
        $forced->getStatusCode() === 200
            && ($forcedBody['data']['zone_id'] ?? null) === $northId
            && ($forcedBody['data']['is_manual_override'] ?? null) === true
            && ($forcedBody['data']['resolution_mode'] ?? null) === 'MANUAL_OVERRIDE',
        json_encode($forcedBody)
    );

    $restored = $call('POST', '/api/coordinator/locations/' . $orphanLocation->getId() . '/override-zone', [
        'is_manual_override' => false,
    ], $coordinatorToken);
    $restoredBody = $json($restored);
    $assert(
        '5.2 Restablecer la herencia responde 200 con INHERITED_BY_POSTAL_CODE',
        $restored->getStatusCode() === 200
            && ($restoredBody['data']['is_manual_override'] ?? null) === false
            && array_key_exists('zone_id', $restoredBody['data'] ?? [])
            && $restoredBody['data']['zone_id'] === null
            && ($restoredBody['data']['resolution_mode'] ?? null) === 'INHERITED_BY_POSTAL_CODE',
        json_encode($restoredBody)
    );

    $missingLocation = $call('POST', '/api/coordinator/locations/999999/override-zone', [
        'zone_id' => $northId,
    ], $coordinatorToken);
    $assert(
        '5.3 Una sede inexistente responde 404 LOCATION_NOT_FOUND',
        $missingLocation->getStatusCode() === 404
            && ($json($missingLocation)['error']['code'] ?? '') === 'LOCATION_NOT_FOUND'
    );

    // Una sede más que permanece en Norte con el CP 08002: es la que debe quedar
    // «Sin Zona Asignada» al desactivar la zona.
    $northLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z09-NORTE',
        'name' => 'Sede norte del CP 08002',
        'address' => 'Calle de la Certificación 3, Barcelona',
        'postal_code' => '08002',
    ]);

    // Desactivamos Este (sin CP, sedes ni cuadrante) para probar la zona inactiva.
    $deactivateEast = $call('PUT', '/api/coordinator/zones/' . $eastId, [
        'is_active' => false,
    ], $coordinatorToken);
    $assert(
        '5.4 Una zona sin territorio se desactiva sin pedir confirmación',
        $deactivateEast->getStatusCode() === 200
            && ($json($deactivateEast)['data']['is_active'] ?? null) === false
    );

    $inactiveOverride = $call('POST', '/api/coordinator/locations/' . $orphanLocation->getId() . '/override-zone', [
        'zone_id' => $eastId,
    ], $coordinatorToken);
    $assert(
        '5.5 Forzar una zona inactiva responde 422 UNPROCESSABLE_ENTITY',
        $inactiveOverride->getStatusCode() === 422
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Desactivación con liberación del territorio
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Desactivación con liberación ---\n";

    $releaseGuard = $call('PUT', '/api/coordinator/zones/' . $northId, [
        'is_active' => false,
    ], $coordinatorToken);
    $releaseBody = $json($releaseGuard);
    $assert(
        '6.1 Desactivar una zona con CP, sedes y cuadrante responde 422 con confirm_release',
        $releaseGuard->getStatusCode() === 422
            && ($releaseBody['error']['code'] ?? '') === 'UNPROCESSABLE_ENTITY'
            && isset($releaseBody['error']['field_errors']['confirm_release']),
        json_encode($releaseBody)
    );

    $deactivated = $call('PUT', '/api/coordinator/zones/' . $northId, [
        'is_active' => false,
        'confirm_release' => true,
    ], $coordinatorToken);
    $deactivatedBody = $json($deactivated);
    $assert(
        '6.2 Con confirm_release responde 200 y la zona queda inactiva',
        $deactivated->getStatusCode() === 200
            && ($deactivatedBody['data']['is_active'] ?? null) === false
            && ($deactivatedBody['data']['postal_codes'] ?? null) === []
    );
    $assert(
        '6.3 La sede de la zona queda sin zona, la trasladada conserva la suya y el cuadrante se extingue',
        $locationRepo->findById($northLocation->getId())?->getZoneId() === null
            && $locationRepo->findById($inheritedLocation->getId())?->getZoneId() === $southId
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM technician_zone_assignments
                 WHERE zone_id = ' . $northId . ' AND removed_at IS NULL'
            )->fetchColumn() === 0
    );

    $reassigned = $call('POST', '/api/coordinator/zones/' . $southId . '/postal-codes', [
        'postal_codes' => ['08002'],
    ], $coordinatorToken);
    $assert(
        '6.4 El CP liberado se reasigna a otra zona sin confirmación de traslado',
        $reassigned->getStatusCode() === 200
            && ($json($reassigned)['data']['transferred_postal_codes'] ?? null) === []
            && in_array('08002', $json($reassigned)['data']['assigned_postal_codes'] ?? [], true)
    );

    $finalCatalog = $call('GET', '/api/coordinator/zones', [], $coordinatorToken);
    $finalBody = $json($finalCatalog);
    $byCode = [];
    foreach ($finalBody['data']['zones'] ?? [] as $zone) {
        $byCode[$zone['code']] = $zone;
    }
    $assert(
        '6.5 El listado final refleja estados, contadores y sedes sin zona',
        $finalCatalog->getStatusCode() === 200
            && $byCode['ZONA-NORTE-S09']['is_active'] === false
            && $byCode['ZONA-ESTE-S09']['is_active'] === false
            && $byCode['ZONA-SUR-S09']['postal_codes_count'] === 3
            && ($finalBody['data']['unassigned_locations_count'] ?? -1) === 2,
        json_encode([
            'norte' => $byCode['ZONA-NORTE-S09'] ?? null,
            'sur' => $byCode['ZONA-SUR-S09'] ?? null,
            'sin_zona' => $finalBody['data']['unassigned_locations_count'] ?? null,
        ])
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, ZONE_API_TEST_SCHEMA);
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
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-09 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
