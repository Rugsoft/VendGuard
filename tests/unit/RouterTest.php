<?php

declare(strict_types=1);

/**
 * RouterTest
 * 
 * Test Unitario para Router (Tarea T-17).
 * Verifica la captura de métodos (GET, POST, PATCH), el procesamiento de parámetros
 * en URL (ej: /api/locations/{code}/machines) y el despacho al controlador correspondiente.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\Router;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - RouterTest (T-17)\n";
echo "======================================================================\n\n";

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

$router = new Router();

// =====================================================================
// CASO 1: Captura de GET con parámetros en URL (/api/locations/{code}/machines)
// =====================================================================
echo "--- Caso 1: Captura de GET con parámetro dinámico {code} ---\n";

$router->get('/api/locations/{code}/machines', function (Request $request): Response {
    $code = $request->getRouteParam('code');
    return Response::json([
        'site_code' => $code,
        'machines' => [
            ['id' => 1, 'code' => 'VEND-0101', 'status' => 'OK'],
            ['id' => 2, 'code' => 'VEND-0102', 'status' => 'OK'],
        ]
    ]);
});

$getRequest = new Request('GET', '/api/locations/SEDE-BCN-01/machines');
$getResponse = $router->dispatch($getRequest);

$assert("1.1 Despacho de GET exitoso (HTTP 200)", $getResponse->getStatusCode() === 200);

$getBody = $getResponse->getDecodedBody();
$assert("1.2 Respuesta contiene JSON válido con success => true", ($getBody['success'] ?? false) === true);
$assert(
    "1.3 Parámetro {code} capturado correctamente ('SEDE-BCN-01')",
    ($getBody['data']['site_code'] ?? '') === 'SEDE-BCN-01',
    "Valor recibido: " . var_export($getBody['data']['site_code'] ?? null, true)
);
$assert("1.4 Catálogo de máquinas devuelto por el manejador", count($getBody['data']['machines'] ?? []) === 2);

// =====================================================================
// CASO 2: Captura de POST (/api/incidents)
// =====================================================================
echo "\n--- Caso 2: Captura de POST y procesamiento de cuerpo JSON ---\n";

$router->post('/api/incidents', function (Request $request): Response {
    $machineId = $request->getBodyParam('machine_id');
    $category = $request->getBodyParam('category');

    return Response::json([
        'ticket_code' => 'INC-2026-TEST',
        'machine_id' => $machineId,
        'category' => $category,
        'status' => 'REGISTERED'
    ], 201, 'Incidencia creada con éxito');
});

$postRequest = new Request(
    'POST',
    '/api/incidents',
    [],
    ['machine_id' => 10, 'category' => 'TEMPERATURE_COLD']
);

$postResponse = $router->dispatch($postRequest);

$assert("2.1 Despacho de POST exitoso (HTTP 201 Created)", $postResponse->getStatusCode() === 201);
$postBody = $postResponse->getDecodedBody();
$assert("2.2 Cuerpo procesado y devuelto por el controlador", ($postBody['data']['ticket_code'] ?? '') === 'INC-2026-TEST');
$assert("2.3 Parámetro de cuerpo machine_id recibido", ($postBody['data']['machine_id'] ?? 0) === 10);

// =====================================================================
// CASO 3: Captura de PATCH con parámetro numérico {id} (/api/coordinator/incidents/{id}/assign)
// =====================================================================
echo "\n--- Caso 3: Captura de PATCH con parámetro dinámico {id} ---\n";

$router->patch('/api/coordinator/incidents/{id}/assign', function (Request $request): Response {
    $incidentId = $request->getRouteParam('id');
    $techId = $request->getBodyParam('technician_id');

    return Response::json([
        'assigned' => true,
        'incident_id' => (int)$incidentId,
        'technician_id' => (int)$techId,
        'status' => 'ASSIGNED'
    ]);
});

$patchRequest = new Request(
    'PATCH',
    '/api/coordinator/incidents/42/assign',
    [],
    ['technician_id' => 7]
);

$patchResponse = $router->dispatch($patchRequest);

$assert("3.1 Despacho de PATCH exitoso (HTTP 200)", $patchResponse->getStatusCode() === 200);
$patchBody = $patchResponse->getDecodedBody();
$assert("3.2 Parámetro de ruta {id} capturado como '42'", ($patchBody['data']['incident_id'] ?? 0) === 42);
$assert("3.3 Parámetro de cuerpo technician_id recibido como 7", ($patchBody['data']['technician_id'] ?? 0) === 7);

// =====================================================================
// CASO 4: Despacho a clase controladora [Controller::class, 'method']
// =====================================================================
echo "\n--- Caso 4: Despacho a clase controladora nativa ---\n";

class SampleMockController
{
    public function showProfile(Request $request): Response
    {
        $username = $request->getRouteParam('username');
        return Response::json(['username' => $username, 'role' => 'COORDINATOR']);
    }
}

$router->get('/api/users/{username}', [SampleMockController::class, 'showProfile']);

$controllerRequest = new Request('GET', '/api/users/coordinacion');
$controllerResponse = $router->dispatch($controllerRequest);

$assert("4.1 Despacho a [SampleMockController::class, 'showProfile'] exitoso", $controllerResponse->getStatusCode() === 200);
$controllerBody = $controllerResponse->getDecodedBody();
$assert("4.2 Parámetro username recibido en el método del controlador", ($controllerBody['data']['username'] ?? '') === 'coordinacion');

// =====================================================================
// CASO 5: Respuestas de error automáticas (404 Not Found y 405 Method Not Allowed)
// =====================================================================
echo "\n--- Caso 5: Códigos de error estándar HTTP 404 y 405 ---\n";

// 5.1 Ruta no existente -> 404
$notFoundRequest = new Request('GET', '/api/rutanoexistente');
$notFoundResponse = $router->dispatch($notFoundRequest);

$assert("5.1 Ruta inexistente devuelve HTTP 404 Not Found", $notFoundResponse->getStatusCode() === 404);
$notFoundBody = $notFoundResponse->getDecodedBody();
$assert("5.2 Envolvente de error con código 'ROUTE_NOT_FOUND'", ($notFoundBody['error']['code'] ?? '') === 'ROUTE_NOT_FOUND');

// 5.2 Ruta existe para POST pero se llama con GET -> 405 Method Not Allowed
$methodNotAllowedRequest = new Request('GET', '/api/incidents');
$methodNotAllowedResponse = $router->dispatch($methodNotAllowedRequest);

$assert("5.3 Método incorrecto devuelve HTTP 405 Method Not Allowed", $methodNotAllowedResponse->getStatusCode() === 405);
$assert("5.4 Cabecera Allow incluye 'POST'", str_contains($methodNotAllowedResponse->getHeader('Allow') ?? '', 'POST'));

// =====================================================================
// CASO 6: Transformación de Excepciones de Dominio en Respuestas HTTP
// =====================================================================
echo "\n--- Caso 6: Transformación automática de Excepciones de Dominio ---\n";

$router->post('/api/test-duplicate', function (Request $req): Response {
    throw new DuplicateIncidentException('MACHINE_HAS_ACTIVE_INCIDENT', 'Aviso activo', 'INC-999', 'REGISTERED', 409);
});

$dupResponse = $router->dispatch(new Request('POST', '/api/test-duplicate'));
$assert("6.1 DuplicateIncidentException se traduce automáticamente a HTTP 409", $dupResponse->getStatusCode() === 409);

$router->post('/api/test-transition', function (Request $req): Response {
    throw new InvalidTransitionException('Transición no permitida');
});

$transResponse = $router->dispatch(new Request('POST', '/api/test-transition'));
$assert("6.2 InvalidTransitionException se traduce automáticamente a HTTP 422", $transResponse->getStatusCode() === 422);

// =====================================================================
// Resumen final
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-17 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
