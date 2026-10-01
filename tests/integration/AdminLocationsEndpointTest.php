<?php

declare(strict_types=1);

/**
 * AdminLocationsEndpointTest
 * 
 * Test de Integración HTTP para los endpoints administrativos de Sedes (RF-01, RNF-04, Art. III.1, III.3):
 * 1. Control de acceso RBAC estricto (401 si no hay token, 403 si el rol no es COORDINATOR).
 * 2. GET /api/coordinator/locations (Catálogo de sedes con filtros por status y búsqueda, con conteo de máquinas).
 * 3. POST /api/coordinator/locations (Alta de sede con coordenadas obligatorias, validaciones y auditoría inmutable).
 * 4. GET /api/coordinator/locations/{id} (Detalle de sede).
 * 5. PUT/PATCH /api/coordinator/locations/{id} (Edición de datos y auditoría inmutable).
 * 6. PATCH /api/coordinator/locations/{id}/deactivate (Baja lógica con bloqueo ante máquinas activas y auditoría).
 * 7. PATCH /api/coordinator/locations/{id}/reactivate (Reactivación de sede y auditoría).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ tipado estricto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - AdminLocationsEndpointTest (T-ADM-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$assertions = 0;
$failures   = 0;

$assert = function (string $description, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
        if ($detail !== '') {
            echo "         Motivo: {$detail}\n";
        }
        $failures++;
    }
};

// 1. Obtener usuarios y tokens
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0.1 Usuario coordinador de prueba encontrado", $coordinator !== null);
$assert("0.2 Usuario técnico de prueba encontrado", $technician !== null);

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);

// =========================================================================
// BLOQUE 1: Control de Acceso RBAC (401 / 403)
// =========================================================================
echo "\n--- BLOQUE 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$reqNoAuth = new Request('GET', '/api/coordinator/locations');
$resNoAuth = $router->dispatch($reqNoAuth);
$assert("1.1 GET /locations sin token => 401 Unauthorized", $resNoAuth->getStatusCode() === 401);

// 1.2 Token de técnico => 403
$reqTech = new Request('GET', '/api/coordinator/locations', [], [], ['authorization' => "Bearer {$technicianToken}"]);
$resTech = $router->dispatch($reqTech);
$assert("1.2 GET /locations con rol TECHNICIAN => 403 Forbidden", $resTech->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: GET /api/coordinator/locations (Listado con filtros y conteos)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/coordinator/locations ---\n";

$reqListAll = new Request('GET', '/api/coordinator/locations', ['status' => 'all'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resListAll = $router->dispatch($reqListAll);
$bodyListAll = $resListAll->getDecodedBody();

$assert("2.1 Responde HTTP 200 OK", $resListAll->getStatusCode() === 200);
$assert("2.2 success es true", ($bodyListAll['success'] ?? false) === true);
$assert("2.3 Contiene array de datos", is_array($bodyListAll['data'] ?? null));
$assert("2.4 Cada sede tiene conteo de máquinas activas", isset($bodyListAll['data'][0]['active_machines_count']));
$assert("2.5 Cada sede tiene conteo de máquinas totales", isset($bodyListAll['data'][0]['total_machines_count']));

// Filtro de búsqueda
$reqSearch = new Request('GET', '/api/coordinator/locations', ['search' => 'Hospital'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resSearch = $router->dispatch($reqSearch);
$bodySearch = $resSearch->getDecodedBody();
$assert("2.6 Búsqueda por texto devuelve resultados coincidentes", count($bodySearch['data'] ?? []) >= 1);
$assert("2.7 Coincide con Hospital del Mar", str_contains($bodySearch['data'][0]['name'] ?? '', 'Hospital'));

// =========================================================================
// BLOQUE 3: POST /api/coordinator/locations (Alta de sede y Auditoría)
// =========================================================================
echo "\n--- BLOQUE 3: POST /api/coordinator/locations ---\n";

$createLocationRequest = static function (string $siteCode, array $coordinates) use ($coordinatorToken): Request {
    return new Request('POST', '/api/coordinator/locations', [], array_merge([
        'site_code' => $siteCode,
        'name' => 'Ubicación geográfica de aceptación',
        'address' => 'Avenida de prueba 10, Barcelona',
    ], $coordinates), [
        'authorization' => "Bearer {$coordinatorToken}",
        'content-type' => 'application/json',
    ]);
};

$invalidCoordinateCases = [
    ['missing latitude', 'SEDE-MAP-NL', ['longitude' => 2.17], 'INVALID_COORDINATES'],
    ['missing longitude', 'SEDE-MAP-NG', ['latitude' => 41.38], 'INVALID_COORDINATES'],
    ['non-numeric latitude', 'SEDE-MAP-XL', ['latitude' => 'north', 'longitude' => 2.17], 'INVALID_COORDINATES'],
    ['out-of-territory coordinates', 'SEDE-MAP-OB', ['latitude' => 20.0, 'longitude' => 2.17], 'COORDINATES_OUT_OF_BOUNDS'],
    ['null-island coordinates', 'SEDE-MAP-NI', ['latitude' => 0, 'longitude' => 0], 'COORDINATES_OUT_OF_BOUNDS'],
];
foreach ($invalidCoordinateCases as [$caseName, $siteCode, $coordinates, $expectedCode]) {
    $response = $router->dispatch($createLocationRequest($siteCode, $coordinates));
    $payload = $response->getDecodedBody();
    $assert("Coordenadas de alta {$caseName} => HTTP 422", $response->getStatusCode() === 422);
    $assert(
        "Coordenadas de alta {$caseName} => {$expectedCode}",
        ($payload['error']['code'] ?? '') === $expectedCode
    );
    $assert(
        "Coordenadas de alta {$caseName} no persisten ubicación",
        $locationRepo->findBySiteCode($siteCode, onlyActive: false, allowDeleted: true) === null
    );
}

$invalidBody = $invalidCoordinateCases[0];
$invalidRequest = $createLocationRequest($invalidBody[1], $invalidBody[2]);
$invalidResponse = $router->dispatch($invalidRequest);
$invalidPayload = $invalidResponse->getDecodedBody();
$assert(
    "Error de coordenadas ausentes contiene el mensaje del contrato",
    ($invalidPayload['error']['message'] ?? '') === 'Las coordenadas geográficas (latitud y longitud) son obligatorias y deben ser numéricas.'
);

$validSiteCode = strtoupper('SEDE-MAP-VALID-' . substr(md5((string)microtime(true)), 0, 4));
$validRequest = $createLocationRequest($validSiteCode, ['latitude' => 41.385312, 'longitude' => 2.193245]);
$validResponse = $router->dispatch($validRequest);
$validPayload = $validResponse->getDecodedBody();
$assert("Coordenadas operativas válidas permiten el alta", $validResponse->getStatusCode() === 201);
$assert(
    "Coordenadas operativas válidas se persisten como valores geográficos recibidos",
    ($validPayload['data']['latitude'] ?? null) === 41.385312
        && ($validPayload['data']['longitude'] ?? null) === 2.193245
);
$validCreatedId = (int)($validPayload['data']['id'] ?? 0);
if ($validCreatedId > 0) {
    $locationRepo->softDelete($validCreatedId);
}

// 3.1 Error de validación: campos obligatorios vacíos o teléfono inválido
$reqInvalidPost = new Request('POST', '/api/coordinator/locations', [], [
    'site_code' => 'SEDE-BAD',
    'name' => '',
    'address' => 'Calle Falsa',
    'contact_name' => 'Test',
    'contact_phone' => '123', // Menos de 9 dígitos
    'latitude' => 41.385312,
    'longitude' => 2.193245
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resInvalidPost = $router->dispatch($reqInvalidPost);
$assert("3.1 Validación de datos incorrectos retorna HTTP 400 Bad Request", $resInvalidPost->getStatusCode() === 400);

// 3.2 Error de duplicado: site_code existente
$reqDuplicate = new Request('POST', '/api/coordinator/locations', [], [
    'site_code' => 'SEDE-BCN-01',
    'name' => 'Duplicado Sede',
    'address' => 'Calle Copia 1',
    'contact_name' => 'Contacto',
    'contact_phone' => '600112233',
    'latitude' => 41.385312,
    'longitude' => 2.193245
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resDuplicate = $router->dispatch($reqDuplicate);
$assert("3.2 Alta con site_code existente retorna HTTP 409 Conflict", $resDuplicate->getStatusCode() === 409);
$bodyDuplicate = $resDuplicate->getDecodedBody();
$assert("3.2 Código de error es INACTIVE_RECORD_COLLISION o LOCATION_CODE_EXISTS", in_array($bodyDuplicate['error']['code'] ?? '', ['INACTIVE_RECORD_COLLISION', 'LOCATION_CODE_EXISTS', 'LOCATION_ALREADY_EXISTS_ACTIVE']));

// 3.3 Alta exitosa de nueva sede
$uniqueCode = strtoupper('SEDE-TEST-' . substr(md5((string)microtime(true)), 0, 4));
$reqCreate = new Request('POST', '/api/coordinator/locations', [], [
    'site_code' => $uniqueCode,
    'name' => 'Parque Tecnológico Norte',
    'address' => 'Avenida de la Innovación 45, Planta 0',
    'contact_name' => 'Elena Gestora',
    'contact_phone' => '677889900',
    'latitude' => 41.385312,
    'longitude' => 2.193245
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resCreate = $router->dispatch($reqCreate);
$bodyCreate = $resCreate->getDecodedBody();

$assert("3.3 Alta válida retorna HTTP 201 Created", $resCreate->getStatusCode() === 201);
$assert("3.3 Devuelve ID generado", !empty($bodyCreate['data']['id']));
$newLocationId = (int)($bodyCreate['data']['id'] ?? 0);
$assert(
    "3.3 Coordenadas persistidas en la sede creada",
    ($bodyCreate['data']['latitude'] ?? null) === 41.385312
        && ($bodyCreate['data']['longitude'] ?? null) === 2.193245
);
$assert("3.3 Devuelve site_code inmutable correcto", ($bodyCreate['data']['site_code'] ?? '') === $uniqueCode);
$assert("3.3 is_active es true", ($bodyCreate['data']['is_active'] ?? false) === true);

// 3.4 Verificación en BD y Auditoría Inmutable
$stmtLoc = $pdo->prepare("SELECT * FROM locations WHERE id = :id");
$stmtLoc->execute([':id' => $newLocationId]);
$dbLoc = $stmtLoc->fetch(PDO::FETCH_ASSOC);
$assert("3.4 Sede persistida físicamente en base de datos", $dbLoc !== false && (int)$dbLoc['is_active'] === 1);
$assert(
    "3.4 Coordenadas correctas almacenadas en MariaDB",
    $dbLoc !== false
        && (float)$dbLoc['latitude'] === 41.385312
        && (float)$dbLoc['longitude'] === 2.193245
);

$stmtAudit = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'LOCATION' AND action = 'LOCATION_CREATED' AND entity_id = :id");
$stmtAudit->execute([':id' => $newLocationId]);
$auditEntry = $stmtAudit->fetch(PDO::FETCH_ASSOC);
$assert("3.4 Evento LOCATION_CREATED registrado en audit_log", $auditEntry !== false);
$assert("3.4 Actor registrado es el coordinador", (int)($auditEntry['user_id'] ?? 0) === $coordinator->getId());

// =========================================================================
// BLOQUE 4: GET /api/coordinator/locations/{id} (Detalle de sede)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/coordinator/locations/{id} ---\n";

$reqGetOne = new Request('GET', "/api/coordinator/locations/{$newLocationId}", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetOne = $router->dispatch($reqGetOne);
$bodyGetOne = $resGetOne->getDecodedBody();

$assert("4.1 Detalle de sede retorna HTTP 200 OK", $resGetOne->getStatusCode() === 200);
$assert("4.2 ID coincide con la sede consultada", ($bodyGetOne['data']['id'] ?? 0) === $newLocationId);
$assert("4.3 Nombre de sede coincide", ($bodyGetOne['data']['name'] ?? '') === 'Parque Tecnológico Norte');

// Detalle inexistente => 404
$reqGetNotFound = new Request('GET', '/api/coordinator/locations/999999', [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetNotFound = $router->dispatch($reqGetNotFound);
$assert("4.4 Sede inexistente retorna HTTP 404 Not Found", $resGetNotFound->getStatusCode() === 404);

$putNoAuth = $router->dispatch(new Request('PUT', "/api/coordinator/locations/{$newLocationId}"));
$putWrongRole = $router->dispatch(new Request(
    'PUT',
    "/api/coordinator/locations/{$newLocationId}",
    [],
    [],
    ['authorization' => "Bearer {$technicianToken}"]
));
$assert("4.5 PUT de edición sin token se rechaza con HTTP 401", $putNoAuth->getStatusCode() === 401);
$assert("4.6 PUT de edición como técnico se rechaza con HTTP 403", $putWrongRole->getStatusCode() === 403);

// =========================================================================
// BLOQUE 5: PUT /api/coordinator/locations/{id} (Edición con coordenadas obligatorias)
// =========================================================================
echo "\n--- BLOQUE 5: PUT /api/coordinator/locations/{id} ---\n";

$updateLocationRequest = static function (array $coordinates) use ($coordinatorToken, $newLocationId): Request {
    return new Request('PUT', "/api/coordinator/locations/{$newLocationId}", [], array_merge([
        'name' => 'Parque Tecnológico Norte (Renombrado)',
    ], $coordinates), [
        'authorization' => "Bearer {$coordinatorToken}",
        'content-type' => 'application/json',
    ]);
};

$invalidUpdateCases = [
    ['missing latitude', [], 'INVALID_COORDINATES'],
    ['missing longitude', ['latitude' => 41.385312], 'INVALID_COORDINATES'],
    ['non-numeric longitude', ['latitude' => 41.385312, 'longitude' => 'east'], 'INVALID_COORDINATES'],
    ['outside territory', ['latitude' => 41.0, 'longitude' => 6.0], 'COORDINATES_OUT_OF_BOUNDS'],
];
foreach ($invalidUpdateCases as [$caseName, $coordinates, $expectedCode]) {
    $beforeUpdate = $locationRepo->findById($newLocationId);
    $auditCountStatement = $pdo->prepare("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'LOCATION' AND `entity_id` = :id AND `action` = 'LOCATION_UPDATED'");
    $auditCountStatement->execute([':id' => $newLocationId]);
    $auditBeforeUpdate = $auditCountStatement->fetchColumn();
    $response = $router->dispatch($updateLocationRequest($coordinates));
    $payload = $response->getDecodedBody();
    $afterUpdate = $locationRepo->findById($newLocationId);
    $auditCountStatement->execute([':id' => $newLocationId]);
    $auditAfterUpdate = $auditCountStatement->fetchColumn();
    $assert("Coordenadas de edición {$caseName} => HTTP 422", $response->getStatusCode() === 422);
    $assert(
        "Coordenadas de edición {$caseName} => {$expectedCode}",
        ($payload['error']['code'] ?? '') === $expectedCode
    );
    $assert(
        "Coordenadas de edición {$caseName} no actualizan la sede",
        $beforeUpdate !== null
            && $afterUpdate !== null
            && $afterUpdate->getName() === $beforeUpdate->getName()
            && $afterUpdate->getLatitude() === $beforeUpdate->getLatitude()
            && $afterUpdate->getLongitude() === $beforeUpdate->getLongitude()
            && (int)$auditAfterUpdate === (int)$auditBeforeUpdate
    );
}

// 5.1 Edición válida con coordenadas territoriales
$reqUpdate = new Request('PUT', "/api/coordinator/locations/{$newLocationId}", [], [
    'latitude' => 41.403629,
    'longitude' => 2.189512,
    'name' => 'Parque Tecnológico Norte (Renombrado)',
    'address' => 'Avenida de la Innovación 45, Planta 1',
    'contact_name' => 'Elena Gestora Principal',
    'contact_phone' => '688990011'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resUpdate = $router->dispatch($reqUpdate);
$bodyUpdate = $resUpdate->getDecodedBody();

$assert("5.1 Edición de sede retorna HTTP 200 OK", $resUpdate->getStatusCode() === 200);
$assert("5.2 Nombre actualizado en respuesta", ($bodyUpdate['data']['name'] ?? '') === 'Parque Tecnológico Norte (Renombrado)');
$assert("5.3 Teléfono actualizado en respuesta", ($bodyUpdate['data']['contact_phone'] ?? '') === '688990011');
$assert(
    "5.3 Coordenadas geográficas actualizadas en respuesta",
    ($bodyUpdate['data']['latitude'] ?? null) === 41.403629
        && ($bodyUpdate['data']['longitude'] ?? null) === 2.189512
);

// 5.2 Verificación en audit_log
$stmtAuditUpd = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'LOCATION' AND action = 'LOCATION_UPDATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditUpd->execute([':id' => $newLocationId]);
$auditUpd = $stmtAuditUpd->fetch(PDO::FETCH_ASSOC);
$assert("5.4 Evento LOCATION_UPDATED registrado en audit_log", $auditUpd !== false);
$assert(
    "5.4 Coordenadas nuevas quedan registradas en auditoría",
    $auditUpd !== false
        && str_contains((string)$auditUpd['new_state'], '41.403629')
        && str_contains((string)$auditUpd['new_state'], '2.189512')
);

// =========================================================================
// BLOQUE 6: PATCH /api/coordinator/locations/{id}/deactivate (Baja Lógica)
// =========================================================================
echo "\n--- BLOQUE 6: PATCH /api/coordinator/locations/{id}/deactivate ---\n";

// 6.1 Intento de baja sobre sede con máquinas activas (ej: sede 'SEDE-BCN-01')
$locBcn = $locationRepo->findBySiteCode('SEDE-BCN-01');
$bcnId = $locBcn !== null ? $locBcn->getId() : 1;
$reqDeactBlocked = new Request('PATCH', "/api/coordinator/locations/{$bcnId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resDeactBlocked = $router->dispatch($reqDeactBlocked);
$bodyDeactBlocked = $resDeactBlocked->getDecodedBody();

$assert("6.1 Baja de sede con máquinas activas retorna HTTP 409 Conflict", $resDeactBlocked->getStatusCode() === 409, "Obtenido: " . $resDeactBlocked->getStatusCode() . " Body: " . $resDeactBlocked->getBody());
$assert("6.2 Código de error es LOCATION_HAS_ACTIVE_MACHINES", ($bodyDeactBlocked['error']['code'] ?? '') === 'LOCATION_HAS_ACTIVE_MACHINES', "Error code obtenido: " . ($bodyDeactBlocked['error']['code'] ?? 'null'));

// 6.2 Baja de sede sin máquinas activas ($newLocationId)
$reqDeact = new Request('PATCH', "/api/coordinator/locations/{$newLocationId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resDeact = $router->dispatch($reqDeact);
$bodyDeact = $resDeact->getDecodedBody();

$assert("6.3 Baja de sede vacía retorna HTTP 200 OK", $resDeact->getStatusCode() === 200);
$assert("6.4 is_active es false", ($bodyDeact['data']['is_active'] ?? true) === false);

// Verificación en BD
$stmtLocDeact = $pdo->prepare("SELECT is_active, deleted_at FROM locations WHERE id = :id");
$stmtLocDeact->execute([':id' => $newLocationId]);
$rowDeact = $stmtLocDeact->fetch(PDO::FETCH_ASSOC);
$assert("6.5 En BD is_active = 0", (int)$rowDeact['is_active'] === 0);
$assert("6.6 deleted_at IS NOT NULL (Art. III.1)", $rowDeact['deleted_at'] !== null);

// Verificación en audit_log
$stmtAuditDeact = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'LOCATION' AND action = 'LOCATION_DEACTIVATED' AND entity_id = :id");
$stmtAuditDeact->execute([':id' => $newLocationId]);
$auditDeact = $stmtAuditDeact->fetch(PDO::FETCH_ASSOC);
$assert("6.7 Evento LOCATION_DEACTIVATED registrado en audit_log", $auditDeact !== false);

// =========================================================================
// BLOQUE 7: PATCH /api/coordinator/locations/{id}/reactivate (Reactivación)
// =========================================================================
echo "\n--- BLOQUE 7: PATCH /api/coordinator/locations/{id}/reactivate ---\n";

$reqReact = new Request('PATCH', "/api/coordinator/locations/{$newLocationId}/reactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resReact = $router->dispatch($reqReact);
$bodyReact = $resReact->getDecodedBody();

$assert("7.1 Reactivación de sede retorna HTTP 200 OK", $resReact->getStatusCode() === 200);
$assert("7.2 is_active es true", ($bodyReact['data']['is_active'] ?? false) === true);

// Verificación en BD
$stmtLocReact = $pdo->prepare("SELECT is_active, deleted_at FROM locations WHERE id = :id");
$stmtLocReact->execute([':id' => $newLocationId]);
$rowReact = $stmtLocReact->fetch(PDO::FETCH_ASSOC);
$assert("7.3 En BD is_active = 1", (int)$rowReact['is_active'] === 1);
$assert("7.4 En BD deleted_at IS NULL", $rowReact['deleted_at'] === null);

// Verificación en audit_log
$stmtAuditReact = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'LOCATION' AND action = 'LOCATION_REACTIVATED' AND entity_id = :id");
$stmtAuditReact->execute([':id' => $newLocationId]);
$auditReact = $stmtAuditReact->fetch(PDO::FETCH_ASSOC);
$assert("7.5 Evento LOCATION_REACTIVATED registrado en audit_log", $auditReact !== false);

// Limpieza de sede temporal de prueba
$locationRepo->softDelete($newLocationId);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS ASIGNACIONES DE SEDES PASARON EXITOSAMENTE ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-ADM-12 (Sedes) CUMPLIDA.\n";
    exit(0);
} else {
    echo " RESULTADO: {$failures} fallos detectados de {$assertions} aserciones evaluadas.\n";
    exit(1);
}
