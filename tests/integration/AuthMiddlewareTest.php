<?php

declare(strict_types=1);

/**
 * AuthMiddlewareTest
 * 
 * Test de Integración para SiteAuthMiddleware e InternalAuthMiddleware (Tarea T-18).
 * Valida la interceptación y rechazo con HTTP 401 Unauthorized cuando no se aporta
 * una cabecera de autenticación válida, y el control de roles RBAC (HTTP 403).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware;
use VendGuard\Presentation\Http\Middleware\SiteAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\Router;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - AuthMiddlewareTest (T-18)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

$siteMiddleware = new SiteAuthMiddleware($authService, $locationRepo);
$internalMiddleware = new InternalAuthMiddleware(null, $authService, $userRepo);
$coordMiddleware = new InternalAuthMiddleware(UserRole::COORDINATOR, $authService, $userRepo);
$techMiddleware = new InternalAuthMiddleware(UserRole::TECHNICIAN, $authService, $userRepo);

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

$nextOk = function (Request $req): Response {
    return Response::json(['authenticated' => true, 'site_code' => $req->getAttribute('site_code')]);
};

// =====================================================================
// SECCIÓN 1: SiteAuthMiddleware (Portal de Ubicación)
// =====================================================================
echo "--- Sección 1: SiteAuthMiddleware - Seguridad del Portal de Sede ---\n";

// Caso 1.1: Petición sin ninguna cabecera de autenticación -> 401
$reqEmpty = new Request('GET', '/api/locations/SEDE-BCN-01/machines');
$resEmpty = $siteMiddleware->handle($reqEmpty, $nextOk);

$assert(
    "1.1 Petición sin cabecera de autenticación es rechazada con HTTP 401 Unauthorized",
    $resEmpty->getStatusCode() === 401
);
$bodyEmpty = $resEmpty->getDecodedBody();
$assert(
    "1.2 Envolvente de error con código 'UNAUTHORIZED'",
    ($bodyEmpty['error']['code'] ?? '') === 'UNAUTHORIZED'
);

// Caso 1.2: Petición con X-Site-Code inexistente o erróneo -> 401
$reqBadCode = new Request('GET', '/api/locations/UNKNOWN/machines', [], [], ['X-Site-Code' => 'SEDE-FANTASMA-99']);
$resBadCode = $siteMiddleware->handle($reqBadCode, $nextOk);

$assert(
    "1.3 Petición con X-Site-Code inexistente es rechazada con HTTP 401",
    $resBadCode->getStatusCode() === 401
);

// Caso 1.3: Petición con X-Site-Code válido -> 200 OK
$reqGoodCode = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], ['X-Site-Code' => 'SEDE-BCN-01']);
$resGoodCode = $siteMiddleware->handle($reqGoodCode, $nextOk);

$assert(
    "1.4 Petición con X-Site-Code válido ('SEDE-BCN-01') es autorizada (HTTP 200)",
    $resGoodCode->getStatusCode() === 200
);
$assert(
    "1.5 Atributo site_code inyectado en el contexto de la petición",
    $reqGoodCode->getAttribute('site_code') === 'SEDE-BCN-01'
);

// Caso 1.4: Petición con Bearer site_token firmado y válido -> 200 OK
$loc = $locationRepo->findBySiteCode('SEDE-BCN-01');
$siteToken = $authService->generateSiteToken($loc);

$reqGoodToken = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
    'Authorization' => "Bearer {$siteToken}"
]);
$resGoodToken = $siteMiddleware->handle($reqGoodToken, $nextOk);

$assert(
    "1.6 Petición con Bearer site_token válido es autorizada (HTTP 200)",
    $resGoodToken->getStatusCode() === 200
);

// Caso 1.5: Petición con Bearer site_token alterado / firma manipulada -> 401
$tamperedToken = $siteToken . 'tampered';
$reqTampered = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
    'Authorization' => "Bearer {$tamperedToken}"
]);
$resTampered = $siteMiddleware->handle($reqTampered, $nextOk);

$assert(
    "1.7 Token de sede manipulado es interceptado y rechazado con HTTP 401",
    $resTampered->getStatusCode() === 401
);

// =====================================================================
// SECCIÓN 2: InternalAuthMiddleware (Personal Interno y RBAC)
// =====================================================================
echo "\n--- Sección 2: InternalAuthMiddleware - Personal Interno y RBAC ---\n";

$coordUser = $userRepo->findByEmail('coordinacion@vendguard.internal');
$techUser = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$coordToken = $authService->generateInternalToken($coordUser);
$techToken = $authService->generateInternalToken($techUser);

// Caso 2.1: Petición a ruta interna sin cabecera de autenticación -> 401
$reqInternalEmpty = new Request('GET', '/api/coordinator/incidents');
$resInternalEmpty = $internalMiddleware->handle($reqInternalEmpty, $nextOk);

$assert(
    "2.1 Petición interna sin Authorization es rechazada con HTTP 401 Unauthorized",
    $resInternalEmpty->getStatusCode() === 401
);

// Caso 2.2: Petición con token malformado o no Bearer -> 401
$reqMalformed = new Request('GET', '/api/coordinator/incidents', [], [], [
    'Authorization' => 'Basic coord:secret'
]);
$resMalformed = $internalMiddleware->handle($reqMalformed, $nextOk);

$assert(
    "2.2 Petición sin token Bearer válido es rechazada con HTTP 401",
    $resMalformed->getStatusCode() === 401
);

// Caso 2.3: Petición con token interno válido -> 200 OK
$reqCoordOk = new Request('GET', '/api/coordinator/incidents', [], [], [
    'Authorization' => "Bearer {$coordToken}"
]);
$resCoordOk = $internalMiddleware->handle($reqCoordOk, $nextOk);

$assert(
    "2.3 Petición con token interno válido es autorizada (HTTP 200)",
    $resCoordOk->getStatusCode() === 200
);
$assert(
    "2.4 Contexto de usuario autenticado inyectado en la petición",
    $reqCoordOk->getAttribute('user_email') === 'coordinacion@vendguard.internal' &&
    $reqCoordOk->getAttribute('user_role') === 'COORDINATOR'
);

// Caso 2.4: Control RBAC - Técnico intentando acceder a ruta exclusiva de Coordinador -> 403 Forbidden
$reqTechOnCoord = new Request('PATCH', '/api/coordinator/incidents/1/assign', [], [], [
    'Authorization' => "Bearer {$techToken}"
]);
$resTechOnCoord = $coordMiddleware->handle($reqTechOnCoord, $nextOk);

$assert(
    "2.5 Técnico intentando acceder a endpoint de Coordinador recibe HTTP 403 Forbidden",
    $resTechOnCoord->getStatusCode() === 403
);
$bodyTechOnCoord = $resTechOnCoord->getDecodedBody();
$assert(
    "2.6 Código de error es 'FORBIDDEN'",
    ($bodyTechOnCoord['error']['code'] ?? '') === 'FORBIDDEN'
);

// Caso 2.5: Técnico accediendo a ruta exclusiva de Técnico -> 200 OK
$reqTechOnTech = new Request('GET', '/api/technician/my-route', [], [], [
    'Authorization' => "Bearer {$techToken}"
]);
$resTechOnTech = $techMiddleware->handle($reqTechOnTech, $nextOk);

$assert(
    "2.7 Técnico accediendo a su propia ruta móvil es autorizado (HTTP 200)",
    $resTechOnTech->getStatusCode() === 200
);

// =====================================================================
// SECCIÓN 3: Integración directa en el Router
// =====================================================================
echo "\n--- Sección 3: Integración de Middlewares en el Router ---\n";

$router = new Router();

// Ruta protegida por SiteAuthMiddleware
$router->get('/api/protected/machines', function (Request $req): Response {
    return Response::json(['site' => $req->getAttribute('site_code')]);
}, [$siteMiddleware]);

// Ruta protegida por InternalAuthMiddleware (exclusiva Coordinador)
$router->get('/api/protected/coordinator', function (Request $req): Response {
    return Response::json(['user' => $req->getAttribute('user_email')]);
}, [$coordMiddleware]);

// 3.1 Petición no autenticada contra el router es interceptada con 401
$routerRes1 = $router->dispatch(new Request('GET', '/api/protected/machines'));
$assert(
    "3.1 Router intercepta petición no autenticada a sede con HTTP 401",
    $routerRes1->getStatusCode() === 401
);

$routerRes2 = $router->dispatch(new Request('GET', '/api/protected/coordinator'));
$assert(
    "3.2 Router intercepta petición no autenticada a coordinador con HTTP 401",
    $routerRes2->getStatusCode() === 401
);

// 3.2 Petición con autenticación correcta a través del router -> 200 OK
$routerRes3 = $router->dispatch(new Request('GET', '/api/protected/machines', [], [], [
    'X-Site-Code' => 'SEDE-BCN-01'
]));
$assert(
    "3.3 Router permite acceso autorizado por middleware y ejecuta controlador (HTTP 200)",
    $routerRes3->getStatusCode() === 200
);

$routerRes4 = $router->dispatch(new Request('GET', '/api/protected/coordinator', [], [], [
    'Authorization' => "Bearer {$coordToken}"
]));
$assert(
    "3.4 Router autoriza token de coordinador y devuelve datos del controlador (HTTP 200)",
    $routerRes4->getStatusCode() === 200
);

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-18 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
