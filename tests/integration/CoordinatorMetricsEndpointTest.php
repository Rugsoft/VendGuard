<?php

declare(strict_types=1);

/**
 * CoordinatorMetricsEndpointTest
 * 
 * Test de Integración para los Endpoints Analíticos y de Auditoría del Coordinador (T-MET-09).
 * Requisitos: RF-01, RF-02, RF-03, RF-05, RF-06 y EARS 1.1, 2.1, 3.1, 5.5, 6.1, 6.2.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Acceso anónimo a /api/coordinator/metrics/* y /api/coordinator/audit-log/* devuelve 401 Unauthorized.
 * 2. Acceso con rol TECHNICIAN a endpoints de coordinador devuelve 403 Forbidden (Art. V.4).
 * 3. GET /api/coordinator/metrics/summary devuelve 200 OK con MTTR global, tendencias y alertas de SLA.
 * 4. GET /api/coordinator/metrics/breakdown devuelve 200 OK con 4 desgloses (sedes, técnicos, máquinas, averías).
 * 5. GET /api/coordinator/metrics/export devuelve 200 OK con Content-Type text/csv y BOM UTF-8.
 * 6. GET /api/coordinator/audit-log devuelve 200 OK con paginación interactiva y lista de eventos.
 * 7. GET /api/coordinator/audit-log/export devuelve 200 OK con descarga plana CSV del audit log.
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
echo " VendGuard: Test de Integración - CoordinatorMetricsEndpointTest (T-MET-09)\n";
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

// --- CASO 1: Seguridad y RBAC (401 sin auth, 403 con rol técnico) ---
echo "\n--- Caso 1: Seguridad y RBAC en rutas analíticas y auditoría ---\n";

$endpointsToTest = [
    '/api/coordinator/metrics/summary',
    '/api/coordinator/metrics/breakdown',
    '/api/coordinator/metrics/export',
    '/api/coordinator/audit-log',
    '/api/coordinator/audit-log/export',
];

foreach ($endpointsToTest as $endpoint) {
    $reqAnon = new Request('GET', $endpoint);
    $resAnon = $router->dispatch($reqAnon);
    $assert($resAnon->getStatusCode() === 401, "Endpoint {$endpoint} bloquea acceso anónimo con 401");

    $reqTech = new Request('GET', $endpoint, [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resTech = $router->dispatch($reqTech);
    $assert($resTech->getStatusCode() === 403, "Endpoint {$endpoint} deniega acceso a técnicos con 403");
}

// --- CASO 2: GET /api/coordinator/metrics/summary con rol COORDINATOR ---
echo "\n--- Caso 2: GET /api/coordinator/metrics/summary con rol COORDINATOR ---\n";

$reqSummary = new Request('GET', '/api/coordinator/metrics/summary', ['period' => 'last_30_days'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resSummary = $router->dispatch($reqSummary);
$assert($resSummary->getStatusCode() === 200, "Summary devuelve HTTP 200");

$bodySummary = json_decode($resSummary->getBody(), true);
$assert(isset($bodySummary['success']) && $bodySummary['success'] === true, "Summary devuelve JSON con success=true");
$assert(isset($bodySummary['data']['period']['key']) && $bodySummary['data']['period']['key'] === 'last_30_days', "Summary devuelve period.key correcto");
$assert(isset($bodySummary['data']['kpis']['mttr_global_formatted']), "Summary contiene mttr_global_formatted");
$assert(isset($bodySummary['data']['kpis']['resolution_rate_percentage']), "Summary contiene resolution_rate_percentage");
$assert(isset($bodySummary['data']['sla_alerts']['perishable_food']['sla_target_hours']), "Summary contiene alerta de SLA perecedero");
$assert(isset($bodySummary['data']['sla_alerts']['general']['sla_target_hours']), "Summary contiene alerta de SLA general");

// --- CASO 3: GET /api/coordinator/metrics/breakdown con rol COORDINATOR ---
echo "\n--- Caso 3: GET /api/coordinator/metrics/breakdown con rol COORDINATOR ---\n";

$reqBreakdown = new Request('GET', '/api/coordinator/metrics/breakdown', ['period' => 'last_30_days'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resBreakdown = $router->dispatch($reqBreakdown);
$assert($resBreakdown->getStatusCode() === 200, "Breakdown devuelve HTTP 200");

$bodyBreakdown = json_decode($resBreakdown->getBody(), true);
$assert(isset($bodyBreakdown['success']) && $bodyBreakdown['success'] === true, "Breakdown devuelve JSON con success=true");
$assert(isset($bodyBreakdown['data']['by_location']), "Breakdown contiene desglose by_location");
$assert(isset($bodyBreakdown['data']['by_technician']), "Breakdown contiene desglose by_technician");
$assert(isset($bodyBreakdown['data']['by_machine_type']), "Breakdown contiene desglose by_machine_type");
$assert(isset($bodyBreakdown['data']['by_category']), "Breakdown contiene desglose by_category");

// --- CASO 4: GET /api/coordinator/metrics/export con rol COORDINATOR ---
echo "\n--- Caso 4: GET /api/coordinator/metrics/export con rol COORDINATOR ---\n";

$reqExport = new Request('GET', '/api/coordinator/metrics/export', ['period' => 'last_30_days'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resExport = $router->dispatch($reqExport);
$assert($resExport->getStatusCode() === 200, "Export de métricas devuelve HTTP 200");

$contentType = $resExport->getHeader('Content-Type');
$assert(str_contains((string)$contentType, 'text/csv'), "Cabecera Content-Type es text/csv");
$assert(str_starts_with($resExport->getBody(), "\xEF\xBB\xBF"), "Cuerpo CSV incluye BOM UTF-8 (\\xEF\\xBB\\xBF)");
$assert(str_contains($resExport->getBody(), 'Identificador'), "Cabeceras CSV de métricas presentes");

// --- CASO 5: GET /api/coordinator/audit-log con rol COORDINATOR ---
echo "\n--- Caso 5: GET /api/coordinator/audit-log con rol COORDINATOR ---\n";

$reqAudit = new Request('GET', '/api/coordinator/audit-log', ['limit' => '10'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resAudit = $router->dispatch($reqAudit);
$assert($resAudit->getStatusCode() === 200, "Audit log devuelve HTTP 200");

$bodyAudit = json_decode($resAudit->getBody(), true);
$assert(isset($bodyAudit['success']) && $bodyAudit['success'] === true, "Audit log devuelve JSON con success=true");
$assert(isset($bodyAudit['data']['page']) && $bodyAudit['data']['page'] === 1, "Audit log devuelve página 1");
$assert(isset($bodyAudit['data']['limit']) && $bodyAudit['data']['limit'] === 10, "Audit log devuelve limit 10");
$assert(isset($bodyAudit['data']['total_records']), "Audit log contiene total_records");
$assert(isset($bodyAudit['data']['items']) && is_array($bodyAudit['data']['items']), "Audit log contiene array de items");

// --- CASO 6: GET /api/coordinator/audit-log/export con rol COORDINATOR ---
echo "\n--- Caso 6: GET /api/coordinator/audit-log/export con rol COORDINATOR ---\n";

$reqAuditExp = new Request('GET', '/api/coordinator/audit-log/export', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resAuditExp = $router->dispatch($reqAuditExp);
$assert($resAuditExp->getStatusCode() === 200, "Export de audit log devuelve HTTP 200");

$contentDisposition = $resAuditExp->getHeader('Content-Disposition');
$assert(str_contains((string)$contentDisposition, 'attachment; filename="vendguard_audit_log_'), "Cabecera Content-Disposition con nombre de archivo correcto");
$assert(str_starts_with($resAuditExp->getBody(), "\xEF\xBB\xBF"), "Audit CSV incluye BOM UTF-8");
$assert(str_contains($resAuditExp->getBody(), 'Fecha y Hora'), "Cabeceras CSV de auditoría presentes");

echo "\n======================================================================\n";
echo " RESUMEN: {$assertionCount} aserciones superadas exitosamente (100% PASS).\n";
echo " CONDICIÓN T-MET-09 VERIFICADA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
