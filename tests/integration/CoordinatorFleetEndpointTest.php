<?php

declare(strict_types=1);

/**
 * CoordinatorFleetEndpointTest
 * 
 * Test de Integración HTTP para los endpoints de Parque de Sedes y Máquinas (RF-FLEET-01, RF-FLEET-02, RF-FLEET-03):
 * 1. GET /api/coordinator/locations (Catálogo de sedes con conteo de máquinas).
 * 2. GET /api/coordinator/locations/{id}/machines (Detalle de máquinas instaladas con estado operativo).
 * 3. Control de acceso RBAC estricto (401 si no hay token, 403 si el rol no es COORDINATOR).
 * 4. Manejo de errores 400 (ID inválido) y 404 (Sede no encontrada).
 * 
 * Dogma Vanilla: Cero frameworks externos, tipado estricto PHP 8.2+.
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
echo " VendGuard: Test de Integración - CoordinatorFleetEndpointTest\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$assertions = 0;
$failures = 0;

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

$assert("0.1 Usuario coordinador encontrado", $coordinator !== null);
$assert("0.2 Usuario técnico encontrado", $technician !== null);

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);

// =========================================================================
// CASO 1: Control de Acceso RBAC (401 / 403)
// =========================================================================
echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$reqNoAuth = new Request(method: 'GET', path: '/api/coordinator/locations');
$resNoAuth = $router->dispatch($reqNoAuth);
$assert("1.1 GET /locations sin token => 401 Unauthorized", $resNoAuth->getStatusCode() === 401);

// 1.2 Token con rol TECHNICIAN => 403
$reqTech = new Request(
    method: 'GET',
    path: '/api/coordinator/locations',
    headers: ['Authorization' => "Bearer {$technicianToken}"]
);
$resTech = $router->dispatch($reqTech);
$assert("1.2 GET /locations con rol TECHNICIAN => 403 Forbidden", $resTech->getStatusCode() === 403);

// =========================================================================
// CASO 2: GET /api/coordinator/locations (Catálogo de Sedes)
// =========================================================================
echo "\n--- Caso 2: GET /api/coordinator/locations (Catálogo de Sedes) ---\n";

$reqLocations = new Request(
    method: 'GET',
    path: '/api/coordinator/locations',
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$resLocations = $router->dispatch($reqLocations);
$bodyLocations = $resLocations->getDecodedBody();

$assert("2.1 Responde HTTP 200 OK", $resLocations->getStatusCode() === 200);
$assert("2.2 success es true", ($bodyLocations['success'] ?? false) === true);
$assert("2.3 data es un array", is_array($bodyLocations['data'] ?? null));

$locationsList = $bodyLocations['data'] ?? [];
$assert("2.4 Contiene al menos 2 sedes activas", count($locationsList) >= 2);

$firstLoc = $locationsList[0] ?? [];
$assert("2.5 Cada sede contiene site_code", isset($firstLoc['site_code']) && !empty($firstLoc['site_code']));
$assert("2.6 Cada sede contiene name", isset($firstLoc['name']) && !empty($firstLoc['name']));
$assert("2.7 Cada sede contiene contact_phone", isset($firstLoc['contact_phone']));
$assert("2.8 Cada sede contiene machine_count", isset($firstLoc['machine_count']) && is_numeric($firstLoc['machine_count']));

// =========================================================================
// CASO 3: GET /api/coordinator/locations/{id}/machines (Detalle de Máquinas)
// =========================================================================
echo "\n--- Caso 3: GET /api/coordinator/locations/{id}/machines (Detalle de Máquinas) ---\n";

$loc1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("3.0 Sede SEDE-BCN-01 existe", $loc1 !== null);

$reqMachines = new Request(
    method: 'GET',
    path: "/api/coordinator/locations/{$loc1->getId()}/machines",
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$resMachines = $router->dispatch($reqMachines);
$bodyMachines = $resMachines->getDecodedBody();

$assert("3.1 Responde HTTP 200 OK", $resMachines->getStatusCode() === 200);
$assert("3.2 success es true", ($bodyMachines['success'] ?? false) === true);
$assert("3.3 data contiene objeto location", isset($bodyMachines['data']['location']['site_code']));
$assert("3.4 data.location.site_code es SEDE-BCN-01", ($bodyMachines['data']['location']['site_code'] ?? '') === 'SEDE-BCN-01');

$machines = $bodyMachines['data']['machines'] ?? [];
$assert("3.5 data.machines es un array de máquinas", is_array($machines) && count($machines) >= 2);

$perishableMach = null;
foreach ($machines as $m) {
    if (($m['code'] ?? '') === 'VEND-0101') {
        $perishableMach = $m;
        break;
    }
}

$assert("3.6 Máquina VEND-0101 encontrada en la sede", $perishableMach !== null);
$assert("3.7 VEND-0101 es de tipo perecedero (is_perishable = true)", ($perishableMach['is_perishable'] ?? false) === true);
$assert("3.8 Contiene operational_status válido", in_array($perishableMach['operational_status'] ?? '', ['OPERATIONAL', 'ACTIVE_INCIDENT', 'IN_WARRANTY'], true));
$assert("3.9 Contiene floor_wing", isset($perishableMach['floor_wing']));

// =========================================================================
// CASO 4: Casos Límite y Manejo de Errores (400 / 404)
// =========================================================================
echo "\n--- Caso 4: Casos Límite y Manejo de Errores (400 / 404) ---\n";

// 4.1 Sede inexistente => 404
$reqNotFound = new Request(
    method: 'GET',
    path: '/api/coordinator/locations/99999/machines',
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$resNotFound = $router->dispatch($reqNotFound);
$assert("4.1 Sede inexistente devuelve 404 NOT_FOUND", $resNotFound->getStatusCode() === 404 && ($resNotFound->getDecodedBody()['error']['code'] ?? '') === 'LOCATION_NOT_FOUND');

// 4.2 ID malformado => 400
$reqBadId = new Request(
    method: 'GET',
    path: '/api/coordinator/locations/invalido/machines',
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$resBadId = $router->dispatch($reqBadId);
$assert("4.2 ID alfanumérico devuelve 400 INVALID_LOCATION_ID", $resBadId->getStatusCode() === 400);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON ({$assertions} aserciones, 0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S) de {$assertions} evaluadas.\n";
}
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
