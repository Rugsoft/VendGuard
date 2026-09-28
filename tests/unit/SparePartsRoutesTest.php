<?php

declare(strict_types=1);

/**
 * SparePartsRoutesTest
 * 
 * Batería de pruebas unitarias para el registro de rutas y verificación de seguridad
 * de los endpoints de repuestos (Módulo 06 - T-SPARE-13).
 * 
 * Verifica rigurosamente:
 * 1. Registro de todas las rutas /api/coordinator/spare-parts/* y /api/technician/spare-parts/*
 *    en AppRouter.php.
 * 2. Protección estricta con InternalAuthMiddleware (401 ante peticiones anónimas o con token inválido).
 * 3. Control de acceso basado en roles RBAC (403 Forbidden ante acceso cruzado Técnico <-> Coordinador).
 * 4. Control de métodos HTTP no permitidos (405 Method Not Allowed y cabecera Allow).
 * 5. Despacho y ejecución correcta hacia los controladores correspondientes ante credenciales válidas.
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

echo "========================================================================================\n";
echo " VendGuard: Verificación de Rutas y Seguridad - SparePartsRoutesTest (T-SPARE-13)\n";
echo "========================================================================================\n\n";

// 1. Inicialización de la base de datos y emisión de credenciales
$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedUsers();
$seedRunner->seedMachines();

$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService(userRepo: $userRepo);

$coordUser = $userRepo->findByEmail('coordinacion@vendguard.internal');
$techUser = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

if ($coordUser === null || $techUser === null) {
    echo "ERROR: Usuarios semilla no disponibles para la prueba.\n";
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordUser);
$techToken = $authService->generateInternalToken($techUser);

$router = AppRouter::create();

// Definición de endpoints de repuestos
$coordinatorRoutes = [
    ['GET', '/api/coordinator/spare-parts', 'Listado de catálogo maestro'],
    ['POST', '/api/coordinator/spare-parts', 'Creación de nuevo repuesto'],
    ['GET', '/api/coordinator/spare-parts/models', 'Modelos únicos para autocompletado'],
    ['GET', '/api/coordinator/spare-parts/analytics', 'Cuadro de mando analítico de fallos'],
    ['GET', '/api/coordinator/spare-parts/export', 'Exportación de consumos a CSV'],
    ['GET', '/api/coordinator/spare-parts/requests/pending-review', 'Bandeja de solicitudes fuera de catálogo'],
    ['PUT', '/api/coordinator/spare-parts/1', 'Actualización de datos maestros de repuesto'],
    ['PATCH', '/api/coordinator/spare-parts/1/status', 'Baja lógica o reactivación de repuesto'],
];

$technicianRoutes = [
    ['GET', '/api/technician/spare-parts/catalog', 'Catálogo compatible en movilidad'],
];

// =============================================================================
// SECCIÓN 1: Bloqueo 401 a Peticiones Anónimas en Todos los Endpoints
// =============================================================================
echo "--- 1. Bloqueo 401 Unauthorized a Peticiones Anónimas ---\n";

foreach ($coordinatorRoutes as [$method, $path, $desc]) {
    $req = new Request(method: $method, path: $path);
    $res = $router->dispatch($req);
    $body = $res->getDecodedBody();

    $assert(
        "1.1 [Coordinación] {$method} {$path} ({$desc}) rechaza sin token con 401",
        $res->getStatusCode() === 401 && ($body['error']['code'] ?? '') === 'UNAUTHORIZED',
        "Status: {$res->getStatusCode()}, Error: " . json_encode($body['error'] ?? null)
    );
}

foreach ($technicianRoutes as [$method, $path, $desc]) {
    $req = new Request(method: $method, path: $path);
    $res = $router->dispatch($req);
    $body = $res->getDecodedBody();

    $assert(
        "1.2 [Técnico] {$method} {$path} ({$desc}) rechaza sin token con 401",
        $res->getStatusCode() === 401 && ($body['error']['code'] ?? '') === 'UNAUTHORIZED',
        "Status: {$res->getStatusCode()}, Error: " . json_encode($body['error'] ?? null)
    );
}

// =============================================================================
// SECCIÓN 2: Bloqueo 401 ante Token Inválido o Manipulado
// =============================================================================
echo "\n--- 2. Bloqueo 401 ante Token Inválido o Alterado ---\n";

$tamperedHeaders = ['Authorization' => 'Bearer auth_token_eyJhbGciOiJIUzI1NiJ9.fake_tampered_payload'];

$reqBadCoord = new Request(method: 'GET', path: '/api/coordinator/spare-parts', headers: $tamperedHeaders);
$resBadCoord = $router->dispatch($reqBadCoord);
$bodyBadCoord = $resBadCoord->getDecodedBody();

$assert(
    "2.1 Token alterado en endpoint de coordinación devuelve 401 UNAUTHORIZED",
    $resBadCoord->getStatusCode() === 401 && ($bodyBadCoord['error']['code'] ?? '') === 'UNAUTHORIZED'
);

$reqBadTech = new Request(method: 'GET', path: '/api/technician/spare-parts/catalog', headers: $tamperedHeaders);
$resBadTech = $router->dispatch($reqBadTech);
$bodyBadTech = $resBadTech->getDecodedBody();

$assert(
    "2.2 Token alterado en endpoint de técnico devuelve 401 UNAUTHORIZED",
    $resBadTech->getStatusCode() === 401 && ($bodyBadTech['error']['code'] ?? '') === 'UNAUTHORIZED'
);

// =============================================================================
// SECCIÓN 3: Control de Acceso Basado en Roles (RBAC - 403 Forbidden)
// =============================================================================
echo "\n--- 3. Control de Acceso Basado en Roles (RBAC - 403 Forbidden) ---\n";

$techHeaders = ['Authorization' => "Bearer {$techToken}"];
$coordHeaders = ['Authorization' => "Bearer {$coordToken}"];

// 3.1 Técnico intentando acceder a rutas de Coordinador -> 403
foreach ($coordinatorRoutes as [$method, $path, $desc]) {
    $req = new Request(method: $method, path: $path, headers: $techHeaders);
    $res = $router->dispatch($req);
    $body = $res->getDecodedBody();

    $assert(
        "3.1 Técnico bloqueado con 403 FORBIDDEN en ruta de Coordinador {$method} {$path}",
        $res->getStatusCode() === 403 && ($body['error']['code'] ?? '') === 'FORBIDDEN',
        "Status: {$res->getStatusCode()}, Body: " . json_encode($body)
    );
}

// 3.2 Coordinador intentando acceder a rutas exclusivas de Técnico -> 403
foreach ($technicianRoutes as [$method, $path, $desc]) {
    $req = new Request(method: $method, path: $path, headers: $coordHeaders);
    $res = $router->dispatch($req);
    $body = $res->getDecodedBody();

    $assert(
        "3.2 Coordinador bloqueado con 403 FORBIDDEN en ruta de Técnico {$method} {$path}",
        $res->getStatusCode() === 403 && ($body['error']['code'] ?? '') === 'FORBIDDEN',
        "Status: {$res->getStatusCode()}, Body: " . json_encode($body)
    );
}

// =============================================================================
// SECCIÓN 4: Verificación de Métodos No Permitidos (405 Method Not Allowed)
// =============================================================================
echo "\n--- 4. Verificación de Métodos No Permitidos (405 Method Not Allowed) ---\n";

// DELETE en catálogo de piezas está prohibido por Constitución Art. III.1
$reqDelete = new Request(method: 'DELETE', path: '/api/coordinator/spare-parts', headers: $coordHeaders);
$resDelete = $router->dispatch($reqDelete);
$assert(
    "4.1 DELETE /api/coordinator/spare-parts responde 405 Method Not Allowed",
    $resDelete->getStatusCode() === 405 && $resDelete->getHeader('Allow') !== null
);

// POST en catálogo móvil de técnico no existe (solo GET)
$reqPostTech = new Request(method: 'POST', path: '/api/technician/spare-parts/catalog', headers: $techHeaders);
$resPostTech = $router->dispatch($reqPostTech);
$assert(
    "4.2 POST /api/technician/spare-parts/catalog responde 405 Method Not Allowed",
    $resPostTech->getStatusCode() === 405 && $resPostTech->getHeader('Allow') !== null
);

// GET en /status no existe (solo PATCH)
$reqGetStatus = new Request(method: 'GET', path: '/api/coordinator/spare-parts/1/status', headers: $coordHeaders);
$resGetStatus = $router->dispatch($reqGetStatus);
$assert(
    "4.3 GET /api/coordinator/spare-parts/1/status responde 405 Method Not Allowed",
    $resGetStatus->getStatusCode() === 405 && $resGetStatus->getHeader('Allow') !== null
);

// =============================================================================
// SECCIÓN 5: Despacho y Ejecución Correcta con Roles Autorizados
// =============================================================================
echo "\n--- 5. Despacho y Ejecución Correcta con Roles Autorizados ---\n";

// 5.1 GET /api/coordinator/spare-parts/models con token de Coordinador
$reqModels = new Request(method: 'GET', path: '/api/coordinator/spare-parts/models', headers: $coordHeaders);
$resModels = $router->dispatch($reqModels);
$bodyModels = $resModels->getDecodedBody();

$assert(
    "5.1 Coordinador autorizado despacha GET /api/coordinator/spare-parts/models (200 OK)",
    $resModels->getStatusCode() === 200 &&
    is_array($bodyModels['data'] ?? null)
);

// 5.2 GET /api/coordinator/spare-parts con token de Coordinador
$reqCatalog = new Request(method: 'GET', path: '/api/coordinator/spare-parts', headers: $coordHeaders);
$resCatalog = $router->dispatch($reqCatalog);
$bodyCatalog = $resCatalog->getDecodedBody();

$assert(
    "5.2 Coordinador autorizado despacha GET /api/coordinator/spare-parts (200 OK)",
    $resCatalog->getStatusCode() === 200 &&
    is_array($bodyCatalog['data'] ?? null)
);

// 5.3 GET /api/coordinator/spare-parts/analytics con token de Coordinador
$reqAnalytics = new Request(method: 'GET', path: '/api/coordinator/spare-parts/analytics', headers: $coordHeaders);
$resAnalytics = $router->dispatch($reqAnalytics);
$bodyAnalytics = $resAnalytics->getDecodedBody();

$assert(
    "5.3 Coordinador autorizado despacha GET /api/coordinator/spare-parts/analytics (200 OK)",
    $resAnalytics->getStatusCode() === 200 &&
    isset($bodyAnalytics['data']['period_days'])
);

// 5.4 GET /api/coordinator/spare-parts/export con token de Coordinador (CSV)
$reqExport = new Request(method: 'GET', path: '/api/coordinator/spare-parts/export', headers: $coordHeaders);
$resExport = $router->dispatch($reqExport);

$assert(
    "5.4 Coordinador autorizado despacha GET /api/coordinator/spare-parts/export con text/csv (200 OK)",
    $resExport->getStatusCode() === 200 &&
    str_contains($resExport->getHeader('Content-Type') ?? '', 'text/csv'),
    "Status: {$resExport->getStatusCode()}, Content-Type: " . ($resExport->getHeader('Content-Type') ?? 'null') . ", Body: " . substr($resExport->getBody(), 0, 200)
);

// 5.5 GET /api/coordinator/spare-parts/requests/pending-review con token de Coordinador
$reqPending = new Request(method: 'GET', path: '/api/coordinator/spare-parts/requests/pending-review', headers: $coordHeaders);
$resPending = $router->dispatch($reqPending);
$bodyPending = $resPending->getDecodedBody();

$assert(
    "5.5 Coordinador autorizado despacha GET /api/coordinator/spare-parts/requests/pending-review (200 OK)",
    $resPending->getStatusCode() === 200 &&
    is_array($bodyPending['data'] ?? null)
);

// 5.6 GET /api/technician/spare-parts/catalog?machine_id=1 con token de Técnico
$reqTechCat = new Request(
    method: 'GET',
    path: '/api/technician/spare-parts/catalog',
    queryParams: ['machine_id' => '1'],
    headers: $techHeaders
);
$resTechCat = $router->dispatch($reqTechCat);
$bodyTechCat = $resTechCat->getDecodedBody();

$assert(
    "5.6 Técnico autorizado despacha GET /api/technician/spare-parts/catalog (200 OK)",
    $resTechCat->getStatusCode() === 200 &&
    isset($bodyTechCat['data']['machine']) &&
    is_array($bodyTechCat['data']['compatible_parts'] ?? null)
);

// =============================================================================
// RESUMEN FINAL
// =============================================================================
echo "\n========================================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "========================================================================================\n";

exit($failures === 0 ? 0 : 1);
