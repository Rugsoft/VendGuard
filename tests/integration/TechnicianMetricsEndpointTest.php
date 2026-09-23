<?php

declare(strict_types=1);

/**
 * TechnicianMetricsEndpointTest
 * 
 * Test de Integración para el endpoint analítico del Técnico de Ruta (T-MET-11).
 * Requisitos: RF-04 (EARS 4.1, 4.2), RNF-04 y Constitución Art. V.4.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Acceso anónimo a /api/technician/my-metrics devuelve 401 Unauthorized.
 * 2. Acceso con rol COORDINATOR a /api/technician/my-metrics devuelve 403 Forbidden.
 * 3. Acceso con token manipulado/expirado devuelve 401 Unauthorized.
 * 4. GET /api/technician/my-metrics con rol TECHNICIAN devuelve 200 OK y estructura canónica.
 * 5. Validación de filtros de periodo (last_7_days, last_30_days, current_month) y rechazo de filtros inválidos con 400.
 * 6. Blindaje contra suplantación (Art. V.4): un parámetro ?technician_id=X es neutralizado obligando el ID del token.
 * 7. Bloqueo 403 Forbidden garantizado ante cualquier intento del técnico de consultar métricas globales de coordinación.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - TechnicianMetricsEndpointTest (T-MET-11)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$authService = new AuthService();

$assertionCount = 0;

$assert = function (bool $condition, string $message) use (&$assertionCount) {
    $assertionCount++;
    if (!$condition) {
        throw new RuntimeException("FALLO EN ASERCIÓN [{$assertionCount}]: {$message}");
    }
    echo "  [PASS] Aserción {$assertionCount}: {$message}\n";
};

// Generar tokens para pruebas
$coordUser = new User(1, 'Sara Coordinadora', 'coord@vendguard.internal', 'hash', UserRole::COORDINATOR);
$coordToken = $authService->generateInternalToken($coordUser);

$techUser = new User(2, 'Jordi Técnico', 'tech@vendguard.internal', 'hash', UserRole::TECHNICIAN);
$techToken = $authService->generateInternalToken($techUser);

// --- CASO 1: Seguridad y RBAC en /api/technician/my-metrics ---
echo "\n--- Caso 1: Seguridad y RBAC en endpoint de técnico ---\n";

// 1.1 Sin credenciales -> 401
$reqAnon = new Request('GET', '/api/technician/my-metrics');
$resAnon = $router->dispatch($reqAnon);
$assert($resAnon->getStatusCode() === 401, "Acceso anónimo a my-metrics retorna 401 Unauthorized");

// 1.2 Con token de rol COORDINATOR -> 403 Forbidden
$reqCoord = new Request('GET', '/api/technician/my-metrics', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resCoord = $router->dispatch($reqCoord);
$assert($resCoord->getStatusCode() === 403, "Acceso de coordinador a my-metrics retorna 403 Forbidden");

// 1.3 Con token malformado -> 401 Unauthorized
$reqBadToken = new Request('GET', '/api/technician/my-metrics', [], [], ['Authorization' => 'Bearer token_invalido_xyz']);
$resBadToken = $router->dispatch($reqBadToken);
$assert($resBadToken->getStatusCode() === 401, "Token no válido retorna 401 Unauthorized");

// --- CASO 2: Consulta Exitosa con Rol TECHNICIAN ---
echo "\n--- Caso 2: Consulta exitosa de métricas personales del técnico ---\n";

$reqTech = new Request('GET', '/api/technician/my-metrics', [], [], ['Authorization' => 'Bearer ' . $techToken]);
$resTech = $router->dispatch($reqTech);
$assert($resTech->getStatusCode() === 200, "Técnico autenticado recibe 200 OK");

$bodyTech = json_decode($resTech->getBody(), true);
$assert(isset($bodyTech['success']) && $bodyTech['success'] === true, "Respuesta JSON incluye success=true");
$assert(isset($bodyTech['data']['technician']['id']) && $bodyTech['data']['technician']['id'] === 2, "Datos vinculados al ID del técnico autenticado (2)");
$assert(isset($bodyTech['data']['technician']['name']), "Nombre del técnico presente en la respuesta");
$assert(isset($bodyTech['data']['period']['key']) && $bodyTech['data']['period']['key'] === 'last_30_days', "Período por defecto es last_30_days");

$metrics = $bodyTech['data']['metrics'] ?? [];
$assert(array_key_exists('my_mttr_minutes', $metrics), "Métricas incluyen my_mttr_minutes");
$assert(array_key_exists('my_mttr_formatted', $metrics), "Métricas incluyen my_mttr_formatted");
$assert(array_key_exists('my_mttr_hours', $metrics), "Métricas incluyen my_mttr_hours");
$assert(array_key_exists('total_resolved_tickets', $metrics), "Métricas incluyen total_resolved_tickets");
$assert(array_key_exists('current_in_progress_tickets', $metrics), "Métricas incluyen current_in_progress_tickets");
$assert(array_key_exists('avg_first_response_minutes', $metrics), "Métricas incluyen avg_first_response_minutes");
$assert(array_key_exists('avg_first_response_formatted', $metrics), "Métricas incluyen avg_first_response_formatted");

// --- CASO 3: Filtros de Período válidos e inválidos ---
echo "\n--- Caso 3: Filtros de período temporal ---\n";

// 3.1 last_7_days
$req7d = new Request('GET', '/api/technician/my-metrics', ['period' => 'last_7_days'], [], ['Authorization' => 'Bearer ' . $techToken]);
$res7d = $router->dispatch($req7d);
$assert($res7d->getStatusCode() === 200, "Filtro last_7_days responde 200 OK");
$body7d = json_decode($res7d->getBody(), true);
$assert($body7d['data']['period']['key'] === 'last_7_days', "Clave de período confirmada: last_7_days");

// 3.2 current_month
$reqMonth = new Request('GET', '/api/technician/my-metrics', ['period' => 'current_month'], [], ['Authorization' => 'Bearer ' . $techToken]);
$resMonth = $router->dispatch($reqMonth);
$assert($resMonth->getStatusCode() === 200, "Filtro current_month responde 200 OK");
$bodyMonth = json_decode($resMonth->getBody(), true);
$assert($bodyMonth['data']['period']['key'] === 'current_month', "Clave de período confirmada: current_month");

// 3.3 Período inválido -> 400 Bad Request
$reqInvalid = new Request('GET', '/api/technician/my-metrics', ['period' => 'periodo_inexistente'], [], ['Authorization' => 'Bearer ' . $techToken]);
$resInvalid = $router->dispatch($reqInvalid);
$assert($resInvalid->getStatusCode() === 400, "Período inválido responde 400 Bad Request");
$bodyInvalid = json_decode($resInvalid->getBody(), true);
$assert($bodyInvalid['error']['code'] === 'INVALID_FILTER_PARAMS', "Código de error estandarizado INVALID_FILTER_PARAMS");

// --- CASO 4: Blindaje Constitucional contra Adulteración de technician_id (Art. V.4) ---
echo "\n--- Caso 4: Neutralización de suplantación de technician_id ---\n";

$reqSpoofed = new Request('GET', '/api/technician/my-metrics', ['technician_id' => '999'], [], ['Authorization' => 'Bearer ' . $techToken]);
$resSpoofed = $router->dispatch($reqSpoofed);
$assert($resSpoofed->getStatusCode() === 200, "Petición con parámetro technician_id fraudulento es procesada con 200");
$bodySpoofed = json_decode($resSpoofed->getBody(), true);
$assert($bodySpoofed['data']['technician']['id'] === 2, "El ID del técnico se mantuvo en 2 forzado por token de sesión (suplantación neutralizada)");

// --- CASO 5: Bloqueo 403 Forbidden a endpoints de Coordinación (Art. V.4) ---
echo "\n--- Caso 5: Bloqueo de acceso de técnicos a métricas de coordinación ---\n";

$coordinatorEndpoints = [
    '/api/coordinator/metrics/summary',
    '/api/coordinator/metrics/breakdown',
    '/api/coordinator/metrics/export',
    '/api/coordinator/audit-log',
    '/api/coordinator/audit-log/export',
];

foreach ($coordinatorEndpoints as $coordEp) {
    $reqBlocked = new Request('GET', $coordEp, [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resBlocked = $router->dispatch($reqBlocked);
    $assert($resBlocked->getStatusCode() === 403, "Técnico bloqueado con 403 Forbidden en {$coordEp}");
    $bodyBlocked = json_decode($resBlocked->getBody(), true);
    $assert($bodyBlocked['error']['code'] === 'FORBIDDEN', "Código de error FORBIDDEN verificado en {$coordEp}");
}

echo "\n======================================================================\n";
echo " RESUMEN: {$assertionCount} aserciones superadas exitosamente (100% PASS).\n";
echo " CONDICIÓN T-MET-11 (TechnicianMetricsEndpointTest) VERIFICADA.\n";
echo "======================================================================\n";
