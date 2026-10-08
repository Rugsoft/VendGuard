<?php

declare(strict_types=1);

/**
 * IncidentCommentRoutesVerificationTest
 *
 * Suite de verificación de rutas de la tarea T-COM-08 (módulo 10, hilo de comentarios
 * bidireccional con notas internas). Certifica la condición "Hecho cuando":
 *
 * - Las ocho rutas del hilo quedan registradas en `src/Presentation/Routing/AppRouter.php`
 *   bajo el middleware RBAC que le corresponde a cada perfil:
 *     · `GET/POST /api/location/incidents/{id}/comments`            → $siteAuth
 *     · `GET/POST /api/incidents/{ticket_code}/comments` (alias)    → $siteAuth
 *     · `GET/POST /api/technician/incidents/{id}/comments`          → $technicianAuth
 *     · `GET/POST /api/coordinator/incidents/{id}/comments`         → $coordinatorAuth
 * - Cada ruta apunta al controlador y a la acción correctos (GET → getComments,
 *   POST → addComment), de modo que ni un emparejamiento cruzado de perfiles ni un
 *   método invertido pasarían la verificación.
 * - Los tres middlewares se declaran con el rol exigido: `SiteAuthMiddleware` para el
 *   portal de centro e `InternalAuthMiddleware(UserRole::COORDINATOR|TECHNICIAN)` para
 *   el personal interno (RF-04, Art. V.4).
 * - Comportamiento real del enrutador: una petición anónima a cualquiera de las rutas
 *   se corta con 401 Unauthorized cuyo mensaje es el del middleware correspondiente (y no
 *   el del controlador), lo que demuestra que la petición no llegó a alcanzar la acción;
 *   además, el canal de sede (token firmado `site_token_…`) no abre las rutas internas de
 *   técnico ni de coordinación, y la cabecera `X-Site-Code` está retirada como credencial
 *   (hallazgo S-4: el acceso de sede exige código de sede y clave de centro).
 * - Control negativo: un método no admitido responde 405 Method Not Allowed con su
 *   cabecera Allow y un recurso inexistente bajo el mismo prefijo sigue devolviendo
 *   404 ROUTE_NOT_FOUND, lo que demuestra que los 401 anteriores certifican el registro
 *   real de cada ruta y no un rechazo genérico de la API.
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas. El middleware de seguridad
 * corta la petición antes de tocar la base de datos, así que la verificación no
 * necesita MariaDB ni datos semilla.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

/**
 * Firma un token de sede con el mismo algoritmo que `AuthService::generateSiteToken()`
 * (Base64Url + HMAC-SHA256), sin instanciar repositorios PDO: la suite certifica el
 * aislamiento de canales a nivel de middleware y sigue sin depender de MariaDB.
 */
$signSiteToken = static function (int $locationId, string $siteCode): string {
    $payload = json_encode([
        'type' => 'site',
        'location_id' => $locationId,
        'site_code' => $siteCode,
        'exp' => time() + 3600,
    ], JSON_UNESCAPED_SLASHES) ?: '{}';
    $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    return 'site_token_' . $encoded . '.' . hash_hmac('sha256', $encoded, SecretProvider::authSecret());
};

echo "======================================================================\n";
echo " VendGuard: Verificación de Rutas - Hilo de Comentarios (Módulo 10, T-COM-08)\n";
echo "======================================================================\n\n";

$router = AppRouter::create();
$appRouterSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');

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

/**
 * Patrón de una declaración de ruta completa: método, ruta, controlador, acción y
 * el middleware RBAC concreto entre corchetes.
 */
// La factoría registra con el nombre del método en minúsculas ($router->get(...)),
// así que el patrón se construye sobre el método normalizado.
$routePattern = static function (string $method, string $path, string $controller, string $action, string $middlewareVar): string {
    return '#\$router->' . strtolower($method) . '\(\''
        . preg_quote($path, '#') . '\',\s*\[\s*'
        . preg_quote($controller, '#') . '::class,\s*\''
        . $action . '\'\s*\],\s*\[\s*'
        . preg_quote($middlewareVar, '#') . '\s*\]\s*\)#';
};

$locationController = '\\VendGuard\\Presentation\\Controller\\LocationPortalController';
$technicianController = '\\VendGuard\\Presentation\\Controller\\TechnicianController';
$coordinatorController = '\\VendGuard\\Presentation\\Controller\\CoordinatorController';

// =====================================================================
// CASO 1: Registro con el middleware RBAC correcto (verificación estática)
// =====================================================================
echo "--- Caso 1: Registro bajo el middleware RBAC de cada perfil ---\n";

$registeredRoutes = [
    // Portal de sede: rutas canónicas por ID y alias retrocompatible por ticket.
    ['GET', '/api/location/incidents/{id}/comments', $locationController, 'getComments', '$siteAuth'],
    ['POST', '/api/location/incidents/{id}/comments', $locationController, 'addComment', '$siteAuth'],
    ['GET', '/api/incidents/{ticket_code}/comments', $locationController, 'getComments', '$siteAuth'],
    ['POST', '/api/incidents/{ticket_code}/comments', $locationController, 'addComment', '$siteAuth'],
    // Técnico de ruta: hilo íntegro con notas internas.
    ['GET', '/api/technician/incidents/{id}/comments', $technicianController, 'getComments', '$technicianAuth'],
    ['POST', '/api/technician/incidents/{id}/comments', $technicianController, 'addComment', '$technicianAuth'],
    // Coordinador de operaciones: inspección total y publicación clasificada.
    ['GET', '/api/coordinator/incidents/{id}/comments', $coordinatorController, 'getComments', '$coordinatorAuth'],
    ['POST', '/api/coordinator/incidents/{id}/comments', $coordinatorController, 'addComment', '$coordinatorAuth'],
];

foreach ($registeredRoutes as [$method, $path, $controller, $action, $middlewareVar]) {
    $assert(
        "1. {$method} {$path} → {$action}() bajo [{$middlewareVar}]",
        preg_match($routePattern($method, $path, $controller, $action, $middlewareVar), $appRouterSource) === 1,
        'La declaración de ruta no coincide con el controlador, la acción y el middleware esperados.'
    );
}

$assert(
    '1.9 El portal de sede declara su middleware como SiteAuthMiddleware',
    preg_match('/\$siteAuth\s*=\s*new\s+\\\\?VendGuard\\\\Presentation\\\\Http\\\\Middleware\\\\SiteAuthMiddleware\(\)/', $appRouterSource) === 1
);
$assert(
    '1.10 El coordinador exige InternalAuthMiddleware(UserRole::COORDINATOR)',
    preg_match('/\$coordinatorAuth\s*=\s*new\s+\\\\?VendGuard\\\\Presentation\\\\Http\\\\Middleware\\\\InternalAuthMiddleware\(\s*\\\\?VendGuard\\\\Core\\\\Domain\\\\Model\\\\UserRole::COORDINATOR\s*\)/', $appRouterSource) === 1
);
$assert(
    '1.11 El técnico exige InternalAuthMiddleware(UserRole::TECHNICIAN)',
    preg_match('/\$technicianAuth\s*=\s*new\s+\\\\?VendGuard\\\\Presentation\\\\Http\\\\Middleware\\\\InternalAuthMiddleware\(\s*\\\\?VendGuard\\\\Core\\\\Domain\\\\Model\\\\UserRole::TECHNICIAN\s*\)/', $appRouterSource) === 1
);

// =====================================================================
// CASO 2: Blindaje real del enrutador — petición anónima => 401 (antes del controlador)
// =====================================================================
echo "\n--- Caso 2: Petición anónima cortada con 401 por el middleware ---\n";

foreach ($registeredRoutes as [$method, $path, , , $middlewareVar]) {
    $requestPath = str_replace(['{id}', '{ticket_code}'], ['142', 'TICK-2026-00142'], $path);
    $response = $router->dispatch(new Request(method: $method, path: $requestPath));
    $decoded = $response->getDecodedBody();

    $assert(
        "2. {$method} {$requestPath} responde 401 Unauthorized sin credenciales",
        $response->getStatusCode() === 401 && ($decoded['error']['code'] ?? '') === 'UNAUTHORIZED',
        'Código obtenido: ' . $response->getStatusCode() . ' | error: ' . json_encode($decoded['error'] ?? null)
    );
}

// El 401 debe proceder del middleware y no del controlador: si faltara el middleware,
// el controlador respondería igualmente 401 pero con SU propio mensaje. Se comprueba
// por tanto el texto exacto que solo produce cada middleware (prueba de que la petición
// se cortó antes de alcanzar la acción del controlador).
$site401 = $router->dispatch(new Request(method: 'GET', path: '/api/location/incidents/142/comments'));
$technician401 = $router->dispatch(new Request(method: 'GET', path: '/api/technician/incidents/142/comments'));
$coordinator401 = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/incidents/142/comments'));

$site401Message = (string)($site401->getDecodedBody()['error']['message'] ?? '');
$assert(
    '2.9 El 401 de sede lo emite SiteAuthMiddleware (mensaje propio), no el controlador',
    str_contains($site401Message, 'Inicie sesión en el centro')
        && !str_contains($site401Message, 'X-Site-Code')
);
$assert(
    '2.10 El 401 de técnico lo emite InternalAuthMiddleware (mensaje de cabecera Bearer)',
    str_contains((string)($technician401->getDecodedBody()['error']['message'] ?? ''), 'token Bearer válido')
);
$assert(
    '2.11 El 401 de coordinación lo emite InternalAuthMiddleware (mensaje de cabecera Bearer)',
    str_contains((string)($coordinator401->getDecodedBody()['error']['message'] ?? ''), 'token Bearer válido')
);

// =====================================================================
// CASO 3: Aislamiento de canales — la credencial de sede no abre las rutas internas
// =====================================================================
echo "\n--- Caso 3: Aislamiento de canales de autenticación ---\n";

$internalRoutes = [
    ['GET', '/api/technician/incidents/142/comments'],
    ['POST', '/api/technician/incidents/142/comments'],
    ['GET', '/api/coordinator/incidents/142/comments'],
    ['POST', '/api/coordinator/incidents/142/comments'],
];

$validSiteToken = $signSiteToken(1, 'SEDE-BCN-01');

foreach ($internalRoutes as [$method, $path]) {
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        headers: ['Authorization' => "Bearer {$validSiteToken}"]
    ));

    $assert(
        "3. {$method} {$path} rechaza un token de sede legítimo (401) y sigue exigiendo token interno",
        $response->getStatusCode() === 401 && ($response->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );
}

$retiredHeader = $router->dispatch(new Request(
    method: 'GET',
    path: '/api/technician/incidents/142/comments',
    headers: ['X-Site-Code' => 'SEDE-BCN-01']
));
$assert(
    '3.5b La cabecera X-Site-Code está retirada: ya no abre ni el canal interno ni el de sede',
    $retiredHeader->getStatusCode() === 401
        && ($retiredHeader->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
        && !str_contains((string)($retiredHeader->getDecodedBody()['error']['message'] ?? ''), 'X-Site-Code')
);

$siteRouteWithInternalToken = $router->dispatch(new Request(
    method: 'GET',
    path: '/api/location/incidents/142/comments',
    headers: ['Authorization' => 'Bearer auth_token_ajeno_a_la_sede']
));
$assert(
    '3.5 El portal de sede no acepta un token Bearer interno (401 del SiteAuthMiddleware)',
    $siteRouteWithInternalToken->getStatusCode() === 401
        && ($siteRouteWithInternalToken->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
);

// =====================================================================
// CASO 4: Control negativo — rutas acotadas a sus métodos y a su prefijo
// =====================================================================
echo "\n--- Caso 4: Control negativo del registro ---\n";

$methodNotAllowedSite = $router->dispatch(new Request(method: 'PATCH', path: '/api/location/incidents/142/comments'));
$assert(
    '4.1 PATCH sobre el hilo de sede responde 405 Method Not Allowed',
    $methodNotAllowedSite->getStatusCode() === 405
        && ($methodNotAllowedSite->getDecodedBody()['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    'Código obtenido: ' . $methodNotAllowedSite->getStatusCode()
);

$allowSite = (string)$methodNotAllowedSite->getHeader('Allow');
$assert(
    '4.2 La cabecera Allow confirma que el hilo de sede solo admite GET y POST',
    str_contains($allowSite, 'GET') && str_contains($allowSite, 'POST')
);

$methodNotAllowedCoordinator = $router->dispatch(new Request(method: 'DELETE', path: '/api/coordinator/incidents/142/comments'));
$allowCoordinator = (string)$methodNotAllowedCoordinator->getHeader('Allow');
$assert(
    '4.3 El hilo de coordinación también está acotado a GET y POST (405)',
    $methodNotAllowedCoordinator->getStatusCode() === 405
        && str_contains($allowCoordinator, 'GET')
        && str_contains($allowCoordinator, 'POST')
);

$notFound = $router->dispatch(new Request(method: 'GET', path: '/api/technician/incidents/142/unknown-resource'));
$assert(
    '4.4 Un recurso inexistente bajo el prefijo responde 404 ROUTE_NOT_FOUND',
    $notFound->getStatusCode() === 404 && ($notFound->getDecodedBody()['error']['code'] ?? '') === 'ROUTE_NOT_FOUND'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-08 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
