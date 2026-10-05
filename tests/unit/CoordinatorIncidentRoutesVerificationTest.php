<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentRoutesVerificationTest
 *
 * Suite de verificación de rutas de la tarea T-IDM-07 (módulo 09, modal de detalle
 * integral de incidencias en triaje). Certifica la condición "Hecho cuando":
 *
 * - `GET /api/coordinator/incidents/{id}/detail` y
 *   `POST /api/coordinator/incidents/{id}/comments` están registradas en
 *   `src/Presentation/Routing/AppRouter.php` bajo el middleware $coordinatorAuth.
 * - Ambas rutas responden 401 Unauthorized a peticiones anónimas (el middleware se
 *   ejecuta antes del controlador), lo que demuestra su blindaje por rol.
 * - Un token corrupto también es rechazado con 401 sin alcanzar el controlador.
 * - El método HTTP está restringido por ruta (405 Method Not Allowed) y un recurso
 *   inexistente bajo el mismo prefijo sigue devolviendo 404 ROUTE_NOT_FOUND, de modo
 *   que los 401 anteriores prueban el registro real de las rutas y no un rechazo
 *   genérico de la API.
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas. El middleware de
 * autenticación corta la petición antes de tocar la base de datos, así que la
 * verificación de registro y blindaje no necesita MariaDB.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Verificación de Rutas - Detalle de Incidencia (T-IDM-07)\n";
echo "======================================================================\n\n";

$router = AppRouter::create();

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

// =====================================================================
// CASO 1: Las dos rutas del módulo 09 están registradas y blindadas (401)
// =====================================================================
echo "--- Caso 1: Registro y blindaje bajo \$coordinatorAuth ---\n";

$protectedRoutes = [
    ['GET', '/api/coordinator/incidents/142/detail'],
    ['POST', '/api/coordinator/incidents/142/comments'],
    ['GET', '/api/coordinator/incidents/INC-2026-0142/detail'],
    ['POST', '/api/coordinator/incidents/INC-2026-0142/comments'],
];

foreach ($protectedRoutes as [$method, $path]) {
    $response = $router->dispatch(new Request(method: $method, path: $path));
    $decoded = $response->getDecodedBody();

    $assert(
        "1. {$method} {$path} responde 401 Unauthorized sin token",
        $response->getStatusCode() === 401 && ($decoded['error']['code'] ?? '') === 'UNAUTHORIZED',
        'Código obtenido: ' . $response->getStatusCode() . ' | error: ' . json_encode($decoded['error'] ?? null)
    );
}

// =====================================================================
// CASO 2: Token corrupto rechazado por el middleware (401) antes del controlador
// =====================================================================
echo "\n--- Caso 2: Token corrupto ---\n";

$badTokenRoutes = [
    ['GET', '/api/coordinator/incidents/142/detail'],
    ['POST', '/api/coordinator/incidents/142/comments'],
];

foreach ($badTokenRoutes as [$method, $path]) {
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        headers: ['Authorization' => 'Bearer token_invalido']
    ));

    $assert(
        "2. {$method} {$path} responde 401 con token corrupto",
        $response->getStatusCode() === 401 && ($response->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );
}

// =====================================================================
// CASO 3: Control negativo — las rutas están acotadas a su método y prefijo
// =====================================================================
echo "\n--- Caso 3: Control negativo del registro ---\n";

$methodNotAllowed = $router->dispatch(new Request(method: 'PATCH', path: '/api/coordinator/incidents/142/detail'));
$assert(
    '3.1 PATCH sobre la ruta de detalle responde 405 Method Not Allowed',
    $methodNotAllowed->getStatusCode() === 405 && ($methodNotAllowed->getDecodedBody()['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    'Código obtenido: ' . $methodNotAllowed->getStatusCode()
);

$allowHeader = (string)$methodNotAllowed->getHeader('Allow');
$assert(
    '3.2 La cabecera Allow confirma que el detalle solo admite GET',
    $allowHeader !== '' && str_contains($allowHeader, 'GET')
);

$notFound = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/incidents/142/unknown-resource'));
$assert(
    '3.3 Un recurso inexistente bajo el prefijo responde 404 ROUTE_NOT_FOUND',
    $notFound->getStatusCode() === 404 && ($notFound->getDecodedBody()['error']['code'] ?? '') === 'ROUTE_NOT_FOUND'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-07 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
