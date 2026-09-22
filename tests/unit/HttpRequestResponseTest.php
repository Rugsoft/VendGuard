<?php

declare(strict_types=1);

/**
 * HttpRequestResponseTest
 * 
 * Test Unitario para Request y Response (Tarea T-16).
 * Verifica la emisión de la cabecera Content-Type: application/json y
 * la estructura estandarizada de la envolvente JSON (Envelope).
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - HttpRequestResponseTest (T-16)\n";
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

// =====================================================================
// CASO 1: Response::json() - Envolvente estándar de éxito (Condición Hecho cuando:)
// =====================================================================
echo "--- Caso 1: Response::json() - Condición Hecho cuando: ---\n";

$data = [
    'id' => 101,
    'site_code' => 'SEDE-BCN-01',
    'name' => 'Hospital del Mar',
    'active' => true,
];

$response200 = Response::json($data, 200);

$assert(
    "1.1 El código de estado HTTP es 200",
    $response200->getStatusCode() === 200
);

$contentType = $response200->getHeader('Content-Type');
$assert(
    "1.2 Emite cabecera Content-Type que contiene 'application/json'",
    $contentType !== null && str_contains($contentType, 'application/json'),
    "Content-Type actual: " . var_export($contentType, true)
);

$decoded = $response200->getDecodedBody();
$assert(
    "1.3 El cuerpo es un JSON válido",
    $decoded !== null
);

$assert(
    "1.4 La envolvente contiene 'success' => true",
    isset($decoded['success']) && $decoded['success'] === true
);

$assert(
    "1.5 La envolvente contiene 'data' con los datos exactos",
    isset($decoded['data']) && $decoded['data'] === $data
);

$assert(
    "1.6 Si no se pasa mensaje, el campo opcional 'message' no está presente",
    !isset($decoded['message'])
);

// Response::json() con mensaje opcional y código 201
$response201 = Response::json(['ticket_code' => 'INC-2026-0001'], 201, 'Incidencia registrada');
$decoded201 = $response201->getDecodedBody();

$assert(
    "1.7 Response::json() con código 201 Created",
    $response201->getStatusCode() === 201
);
$assert(
    "1.8 Response::json() con mensaje opcional en el payload",
    isset($decoded201['message']) && $decoded201['message'] === 'Incidencia registrada'
);

// =====================================================================
// CASO 2: Response::error() - Envolvente estándar de error
// =====================================================================
echo "\n--- Caso 2: Response::error() - Envolvente estándar de error ---\n";

$details = ['ticket_code' => 'INC-2026-0001', 'status' => 'IN_PROGRESS'];
$response409 = Response::error(
    'MACHINE_HAS_ACTIVE_INCIDENT',
    'La máquina ya cuenta con una avería activa.',
    409,
    $details
);

$assert(
    "2.1 El código de estado HTTP es 409",
    $response409->getStatusCode() === 409
);

$contentTypeError = $response409->getHeader('Content-Type');
$assert(
    "2.2 Cabecera Content-Type contiene 'application/json'",
    $contentTypeError !== null && str_contains($contentTypeError, 'application/json')
);

$decodedError = $response409->getDecodedBody();
$assert(
    "2.3 La envolvente de error contiene 'success' => false",
    isset($decodedError['success']) && $decodedError['success'] === false
);

$assert(
    "2.4 El bloque 'error' contiene el código de dominio y el mensaje",
    isset($decodedError['error']['code']) &&
    $decodedError['error']['code'] === 'MACHINE_HAS_ACTIVE_INCIDENT' &&
    $decodedError['error']['message'] === 'La máquina ya cuenta con una avería activa.'
);

$assert(
    "2.5 El bloque 'error' incluye los detalles complementarios",
    isset($decodedError['error']['details']) &&
    $decodedError['error']['details'] === $details
);

// =====================================================================
// CASO 3: Response::send() emite el cuerpo JSON correctamente
// =====================================================================
echo "\n--- Caso 3: Response::send() y captura de buffer ---\n";

ob_start();
$response200->send();
$emittedOutput = ob_get_clean();

$assert(
    "3.1 send() emite exactamente la cadena del cuerpo JSON configurado",
    $emittedOutput === $response200->getBody()
);

// =====================================================================
// CASO 4: Request - Construcción y extracción de datos
// =====================================================================
echo "\n--- Caso 4: Request - Procesamiento de peticiones HTTP ---\n";

$request = new Request(
    'post',
    '/api/locations/SEDE-BCN-01/machines?filter=active',
    ['filter' => 'active'],
    ['name' => 'Nueva Máquina', 'model' => 'Bianchi Vending'],
    [
        'Authorization' => 'Bearer token_test_xyz123',
        'X-Custom-Header' => 'CustomValue',
        'Content-Type' => 'application/json',
    ]
);

$assert(
    "4.1 getMethod() normaliza el método a mayúsculas ('POST')",
    $request->getMethod() === 'POST' && $request->isMethod('POST') && $request->isMethod('post')
);

$assert(
    "4.2 getPath() extrae la ruta normalizada sin query string",
    $request->getPath() === '/api/locations/SEDE-BCN-01/machines'
);

$assert(
    "4.3 getQuery() recupera parámetros de query string",
    $request->getQuery('filter') === 'active' && $request->getQuery('missing', 'def') === 'def'
);

$assert(
    "4.4 getBodyParam() recupera parámetros del cuerpo",
    $request->getBodyParam('name') === 'Nueva Máquina' && $request->getBodyParam('missing') === null
);

$assert(
    "4.5 getHeader() es insensible a mayúsculas y minúsculas",
    $request->getHeader('content-type') === 'application/json' &&
    $request->getHeader('CONTENT-TYPE') === 'application/json' &&
    $request->getHeader('x-custom-header') === 'CustomValue'
);

$assert(
    "4.6 getBearerToken() extrae limpiamente el token de la cabecera Authorization",
    $request->getBearerToken() === 'token_test_xyz123'
);

// Parámetros de ruta y atributos de middleware
$request->setRouteParams(['code' => 'SEDE-BCN-01']);
$request->setAttribute('auth_user_id', 42);

$assert(
    "4.7 getRouteParam() devuelve parámetros capturados por el enrutador",
    $request->getRouteParam('code') === 'SEDE-BCN-01'
);

$assert(
    "4.8 getAttribute() almacena y recupera contexto inyectado por middleware",
    $request->getAttribute('auth_user_id') === 42
);

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-16 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
