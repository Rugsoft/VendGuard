<?php

declare(strict_types=1);

/**
 * AuthControllerTest
 * 
 * Test de Integración para AuthController (Tarea T-20).
 * Valida los endpoints:
 * - POST /api/auth/site-login (validación de sedes y emisión de site_token)
 * - POST /api/auth/login (validación de credenciales internas y emisión de auth_token)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - AuthControllerTest (T-20)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$authService = new AuthService();

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
// SECCIÓN 1: POST /api/auth/site-login (Acceso por Código de Sede)
// =====================================================================
echo "--- Sección 1: POST /api/auth/site-login (RF-01) ---\n";

// 1.1 Sede válida existente (SEDE-BCN-01)
$reqSiteOk = new Request('POST', '/api/auth/site-login', [], ['site_code' => 'SEDE-BCN-01']);
$resSiteOk = $router->dispatch($reqSiteOk);

$assert("1.1 Login con 'SEDE-BCN-01' responde HTTP 200 OK", $resSiteOk->getStatusCode() === 200);
$bodySiteOk = $resSiteOk->getDecodedBody();

$assert("1.2 Envolvente contiene success => true", ($bodySiteOk['success'] ?? false) === true);
$token = $bodySiteOk['data']['token'] ?? '';
$assert("1.3 Se emite un token de sede con prefijo 'site_token_'", str_starts_with($token, 'site_token_'));

$validatedPayload = $authService->validateSiteToken($token);
$assert("1.4 El token emitido es criptográficamente válido", $validatedPayload !== null);
$assert("1.5 El token codifica el site_code 'SEDE-BCN-01'", ($validatedPayload['site_code'] ?? '') === 'SEDE-BCN-01');

$locationData = $bodySiteOk['data']['location'] ?? [];
$assert("1.6 Se devuelven los datos de la sede (nombre y dirección)", ($locationData['site_code'] ?? '') === 'SEDE-BCN-01' && !empty($locationData['name']));

// 1.2 Sede con espacios o minúsculas (normalización)
$reqSiteNorm = new Request('POST', '/api/auth/site-login', [], ['site_code' => '  sede-bcn-01  ']);
$resSiteNorm = $router->dispatch($reqSiteNorm);
$assert("1.7 Búsqueda de sede es insensible a mayúsculas y espacios periféricos", $resSiteNorm->getStatusCode() === 200);

// 1.3 Sede inexistente
$reqSiteBad = new Request('POST', '/api/auth/site-login', [], ['site_code' => 'SEDE-INVENTADA-99']);
$resSiteBad = $router->dispatch($reqSiteBad);

$assert("1.8 Código de sede desconocido responde HTTP 401 Unauthorized", $resSiteBad->getStatusCode() === 401);
$bodySiteBad = $resSiteBad->getDecodedBody();
$assert("1.9 Código de error es 'INVALID_SITE_CODE'", ($bodySiteBad['error']['code'] ?? '') === 'INVALID_SITE_CODE');

// 1.4 Código de sede ausente
$reqSiteEmpty = new Request('POST', '/api/auth/site-login', [], []);
$resSiteEmpty = $router->dispatch($reqSiteEmpty);

$assert("1.10 Petición sin 'site_code' responde HTTP 400 Bad Request", $resSiteEmpty->getStatusCode() === 400);

// =====================================================================
// SECCIÓN 2: POST /api/auth/login (Acceso de Personal Interno)
// =====================================================================
echo "\n--- Sección 2: POST /api/auth/login (RF-04) ---\n";

// 2.1 Coordinador con credenciales correctas
$reqCoordOk = new Request('POST', '/api/auth/login', [], [
    'email' => 'coordinacion@vendguard.internal',
    'password' => 'Password123!',
]);
$resCoordOk = $router->dispatch($reqCoordOk);

$assert("2.1 Login de Coordinador responde HTTP 200 OK", $resCoordOk->getStatusCode() === 200);
$bodyCoordOk = $resCoordOk->getDecodedBody();

$coordToken = $bodyCoordOk['data']['token'] ?? '';
$assert("2.2 Emite auth_token_ con firma criptográfica", str_starts_with($coordToken, 'auth_token_'));

$validatedCoord = $authService->validateInternalToken($coordToken);
$assert("2.3 Token interno de Coordinador validado con éxito", $validatedCoord !== null && ($validatedCoord['role'] ?? '') === 'COORDINATOR');

$coordUser = $bodyCoordOk['data']['user'] ?? [];
$assert("2.4 Rol del usuario devuelto es 'COORDINATOR'", ($coordUser['role'] ?? '') === 'COORDINATOR');
$assert("2.5 No se filtra el hash de contraseña en la respuesta", !isset($coordUser['password_hash']));

// 2.2 Técnico de Ruta con credenciales correctas
$reqTechOk = new Request('POST', '/api/auth/login', [], [
    'email' => 'jordi.ruta@vendguard.internal',
    'password' => 'Password123!',
]);
$resTechOk = $router->dispatch($reqTechOk);

$assert("2.6 Login de Técnico de Campo responde HTTP 200 OK", $resTechOk->getStatusCode() === 200);
$bodyTechOk = $resTechOk->getDecodedBody();
$assert("2.7 Rol del técnico devuelto es 'TECHNICIAN'", ($bodyTechOk['data']['user']['role'] ?? '') === 'TECHNICIAN');

// 2.3 Contraseña incorrecta
$reqBadPass = new Request('POST', '/api/auth/login', [], [
    'email' => 'jordi.ruta@vendguard.internal',
    'password' => 'ContrasenaErronea',
]);
$resBadPass = $router->dispatch($reqBadPass);

$assert("2.8 Contraseña incorrecta responde HTTP 401 Unauthorized", $resBadPass->getStatusCode() === 401);
$bodyBadPass = $resBadPass->getDecodedBody();
$assert("2.9 Código de error es 'INVALID_CREDENTIALS'", ($bodyBadPass['error']['code'] ?? '') === 'INVALID_CREDENTIALS');

// 2.4 Correo no registrado
$reqUnknownEmail = new Request('POST', '/api/auth/login', [], [
    'email' => 'desconocido@vendguard.internal',
    'password' => 'Password123!',
]);
$resUnknownEmail = $router->dispatch($reqUnknownEmail);

$assert("2.10 Correo inexistente responde HTTP 401 Unauthorized", $resUnknownEmail->getStatusCode() === 401);

// 2.5 Credenciales ausentes
$reqNoCreds = new Request('POST', '/api/auth/login', [], ['email' => '']);
$resNoCreds = $router->dispatch($reqNoCreds);

$assert("2.11 Credenciales ausentes responden HTTP 400 Bad Request", $resNoCreds->getStatusCode() === 400);

// =====================================================================
// SECCIÓN 3: Peticiones HTTP en vivo contra el servidor local (127.0.0.1:8000)
// =====================================================================
echo "\n--- Sección 3: Peticiones HTTP en vivo (127.0.0.1:8000) ---\n";

$liveServerUrl = 'http://127.0.0.1:8000';
$socket = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 1);
if ($socket) {
    fclose($socket);

    // 3.1 Live site login
    $siteOptions = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['site_code' => 'SEDE-BCN-01']),
            'ignore_errors' => true,
        ],
    ];
    $siteRaw = file_get_contents("{$liveServerUrl}/api/auth/site-login", false, stream_context_create($siteOptions));
    $siteDecoded = json_decode((string)$siteRaw, true);

    $assert("3.1 Servidor local responde HTTP 200 en POST /api/auth/site-login", ($siteDecoded['success'] ?? false) === true);
    $assert("3.2 Servidor emite site_token válido en vivo", str_starts_with($siteDecoded['data']['token'] ?? '', 'site_token_'));

    // 3.2 Live user login
    $userOptions = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['email' => 'coordinacion@vendguard.internal', 'password' => 'Password123!']),
            'ignore_errors' => true,
        ],
    ];
    $userRaw = file_get_contents("{$liveServerUrl}/api/auth/login", false, stream_context_create($userOptions));
    $userDecoded = json_decode((string)$userRaw, true);

    $assert("3.3 Servidor local responde HTTP 200 en POST /api/auth/login", ($userDecoded['success'] ?? false) === true);
    $assert("3.4 Servidor emite auth_token válido para Coordinador en vivo", ($userDecoded['data']['user']['role'] ?? '') === 'COORDINATOR');
} else {
    echo "  [INFO] Servidor local en puerto 8000 no accesible directamente en este paso.\n";
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-20 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
