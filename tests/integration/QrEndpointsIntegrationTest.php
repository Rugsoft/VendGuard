<?php

declare(strict_types=1);

/**
 * QrEndpointsIntegrationTest
 * 
 * Test de Integración para los Endpoints HTTP del Módulo de Códigos QR (Tarea T-QR-12).
 * Requisitos: RF-01, RF-02, RF-03, RF-04, RF-05 y Artículos I a V de la Constitución de VendGuard.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. GET /api/qr/scan/{code}
 *    - 200 OK máquina limpia (status_mode: CAN_REPORT, metadatos completos, is_perishable, active_incident null).
 *    - 200 OK máquina con avería activa (status_mode: ACTIVE_INCIDENT, campos redactados sin datos privados Art. V.4).
 *    - 200 OK máquina en garantía (status_mode: UNDER_WARRANTY, resolved_incident redactado).
 *    - 404 Not Found ante máquina inexistente o inactiva (MACHINE_NOT_FOUND_OR_INACTIVE).
 * 2. POST /api/qr/report
 *    - 201 Created con creación de ticket inicial (merged: false, ticket_code generado).
 *    - 200 OK ante reporte concurrente/duplicado sobre la misma máquina (merged: true, comentario añadido EARS 4.5).
 *    - 422 Unprocessable Content ante campos obligatorios omitidos (MISSING_DESCRIPTION, etc.).
 *    - 404 Not Found ante máquina inexistente (MACHINE_NOT_FOUND_OR_INACTIVE).
 * 3. GET /api/coordinator/machines/{id}/qr-label
 *    - 401 Unauthorized sin token.
 *    - 403 Forbidden con rol de técnico.
 *    - 200 OK con token de coordinador (JSON con svg_content y metadatos).
 *    - 200 OK con actualización persistente de teléfono (update_location_phone=1).
 *    - 200 OK con descarga directa SVG (format=svg, Content-Type: image/svg+xml, Content-Disposition: attachment).
 *    - 404 Not Found ante máquina inexistente.
 * 4. GET /api/coordinator/locations/{id}/qr-batch
 *    - 401 Unauthorized sin token.
 *    - 403 Forbidden con rol de técnico.
 *    - 200 OK con token de coordinador (lote de etiquetas SVG para todas las máquinas activas de la sede).
 *    - 404 Not Found ante sede inexistente.
 * 5. Prueba HTTP real mediante cURL si el servidor local está activo en 127.0.0.1:8000.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - QrEndpointsIntegrationTest (T-QR-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// 1. Limpieza para aislamiento estricto
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

// 2. Restauración de semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

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

// Obtención de usuarios semilla para pruebas de autenticación RBAC
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0.1 Coordinador y Técnico semilla encontrados en la base de datos", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR CRÍTICO: Usuarios semilla no encontrados.\n";
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);

// Obtención de máquinas semilla de prueba
$locationBcn1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("0.2 Sede SEDE-BCN-01 encontrada", $locationBcn1 !== null);
$loc1Id = $locationBcn1->getId();

$machinePerishable = $machineRepo->findByCode('VEND-0101');
$machineHotDrinks = $machineRepo->findByCode('VEND-0102');
$assert("0.3 Máquinas semilla VEND-0101 y VEND-0102 encontradas", $machinePerishable !== null && $machineHotDrinks !== null);

// =========================================================================
// BLOQUE 1: GET /api/qr/scan/{code} (Escaneo Público de Código QR)
// =========================================================================
echo "\n--- BLOQUE 1: GET /api/qr/scan/{code} ---\n";

// 1.1 Máquina limpia (sin averías activas)
$reqClean = new Request('GET', '/api/qr/scan/VEND-0101');
$resClean = $router->dispatch($reqClean);

$assert("1.1 Máquina limpia retorna HTTP 200 OK", $resClean->getStatusCode() === 200);
$bodyClean = json_decode($resClean->getBody(), true);
$assert("1.1 Body contiene success=true", ($bodyClean['success'] ?? false) === true);
$assert("1.1 Status mode es CAN_REPORT", ($bodyClean['data']['status_mode'] ?? '') === 'CAN_REPORT');
$assert("1.1 Machine code es VEND-0101", ($bodyClean['data']['machine']['code'] ?? '') === 'VEND-0101');
$assert("1.1 is_perishable es true para VEND-0101", ($bodyClean['data']['machine']['is_perishable'] ?? false) === true);
$assert("1.1 active_incident es null en máquina limpia", array_key_exists('active_incident', $bodyClean['data'] ?? []) && $bodyClean['data']['active_incident'] === null);

// 1.2 Máquina no perecedera (HOT_DRINKS)
$reqHot = new Request('GET', '/api/qr/scan/VEND-0102');
$resHot = $router->dispatch($reqHot);
$bodyHot = json_decode($resHot->getBody(), true);
$assert("1.2 is_perishable es false para VEND-0102 (HOT_DRINKS)", ($bodyHot['data']['machine']['is_perishable'] ?? true) === false);

// 1.3 Máquina con avería activa (Auditoría de Privacidad Art. V.4)
$activeIncident = new Incident(
    null,
    '#TICK-TEST-ACT1',
    $machineHotDrinks->getId(),
    $loc1Id,
    IncidentCategory::PRODUCT_JAM,
    'El producto ha quedado atascado en la espiral.',
    UrgencyLevel::MEDIUM,
    IncidentStatus::ASSIGNED,
    $technician->getId(),
    'Test Reporter',
    '600123456',
    null,
    null,
    date('Y-m-d H:i:s')
);
$incidentRepo->create($activeIncident);

$reqActive = new Request('GET', '/api/qr/scan/VEND-0102');
$resActive = $router->dispatch($reqActive);
$bodyActive = json_decode($resActive->getBody(), true);

$assert("1.3 Máquina con avería retorna HTTP 200 OK", $resActive->getStatusCode() === 200);
$assert("1.3 Status mode es ACTIVE_INCIDENT", ($bodyActive['data']['status_mode'] ?? '') === 'ACTIVE_INCIDENT');
$assert("1.3 active_incident contiene ticket_code", isset($bodyActive['data']['active_incident']['ticket_code']));
$assert("1.3 active_incident contiene category", isset($bodyActive['data']['active_incident']['category']));
$assert("1.3 Art. V.4: NO contiene assigned_technician_id", !isset($bodyActive['data']['active_incident']['assigned_technician_id']));
$assert("1.3 Art. V.4: NO contiene assigned_technician", !isset($bodyActive['data']['active_incident']['assigned_technician']));
$assert("1.3 Art. V.4: NO contiene reporter_name ni reporter_phone", !isset($bodyActive['data']['active_incident']['reporter_name']) && !isset($bodyActive['data']['active_incident']['reporter_phone']));

// 1.4 Máquina en periodo de garantía (resuelta hace menos de 48h)
$warrantyIncident = new Incident(
    null,
    '#TICK-TEST-WAR1',
    $machinePerishable->getId(),
    $loc1Id,
    IncidentCategory::TEMPERATURE_COLD,
    'Fallo de temperatura resuelto',
    UrgencyLevel::CRITICAL,
    IncidentStatus::RESOLVED,
    $technician->getId(),
    'Usuario Previo',
    '699112233',
    null,
    null,
    date('Y-m-d H:i:s', time() - 3600),
    date('Y-m-d H:i:s', time() - 3000),
    null,
    'Compresor congelado',
    'Sustitución de válvula',
    date('Y-m-d H:i:s', time() - 1800)
);
$incidentRepo->create($warrantyIncident);

$reqWarranty = new Request('GET', '/api/qr/scan/VEND-0101');
$resWarranty = $router->dispatch($reqWarranty);
$bodyWarranty = json_decode($resWarranty->getBody(), true);

$assert("1.4 Máquina en garantía retorna HTTP 200 OK", $resWarranty->getStatusCode() === 200);
$assert("1.4 Status mode es UNDER_WARRANTY", ($bodyWarranty['data']['status_mode'] ?? '') === 'UNDER_WARRANTY');
$assert("1.4 resolved_incident incluye ticket_code y resolved_at", isset($bodyWarranty['data']['resolved_incident']['ticket_code']) && isset($bodyWarranty['data']['resolved_incident']['resolved_at']));
$assert("1.4 resolved_incident incluye warranty_expires_at", isset($bodyWarranty['data']['resolved_incident']['warranty_expires_at']));

// 1.5 Máquina inexistente
$reqNotFound = new Request('GET', '/api/qr/scan/VEND-9999');
$resNotFound = $router->dispatch($reqNotFound);
$assert("1.5 Máquina inexistente retorna HTTP 404 Not Found", $resNotFound->getStatusCode() === 404);
$bodyNotFound = json_decode($resNotFound->getBody(), true);
$assert("1.5 Código de error es MACHINE_NOT_FOUND_OR_INACTIVE", ($bodyNotFound['error']['code'] ?? '') === 'MACHINE_NOT_FOUND_OR_INACTIVE');

// 1.6 Máquina inactiva (T-ADM-11)
$pdo->prepare("UPDATE machines SET is_active = 0 WHERE code = 'VEND-0201'")->execute();
$reqInactive = new Request('GET', '/api/qr/scan/VEND-0201');
$resInactive = $router->dispatch($reqInactive);
$assert("1.6 Máquina inactiva retorna HTTP 200 OK", $resInactive->getStatusCode() === 200);
$bodyInactive = json_decode($resInactive->getBody(), true);
$assert("1.6 Status mode es INACTIVE", ($bodyInactive['data']['status_mode'] ?? '') === 'INACTIVE');
$assert("1.6 allow_reporting es false", ($bodyInactive['data']['allow_reporting'] ?? true) === false);
$assert("1.6 is_active es false", ($bodyInactive['data']['is_active'] ?? true) === false);
$assert("1.6 Incluye mensaje de máquina fuera de servicio", str_contains($bodyInactive['data']['message'] ?? '', 'fuera de servicio'));

// 1.6b POST /api/qr/report bloquea la creación sobre máquina inactiva con HTTP 422
$reqReportInactive = new Request('POST', '/api/qr/report', [], [
    'machine_code' => 'VEND-0201',
    'category' => 'PRODUCT_JAM',
    'description' => 'Intento de reporte en máquina inactiva.'
], ['content-type' => 'application/json']);
$resReportInactive = $router->dispatch($reqReportInactive);
$assert("1.6b Reportar incidencia en máquina inactiva retorna 422", $resReportInactive->getStatusCode() === 422);
$bodyReportInactive = json_decode($resReportInactive->getBody(), true);
$assert("1.6c Error code es MACHINE_INACTIVE", ($bodyReportInactive['error']['code'] ?? '') === 'MACHINE_INACTIVE');

// Restauramos máquina
$pdo->prepare("UPDATE machines SET is_active = 1 WHERE code = 'VEND-0201'")->execute();

// =========================================================================
// BLOQUE 2: POST /api/qr/report (Reporte Ciudadano / Usuario Final)
// =========================================================================
echo "\n--- BLOQUE 2: POST /api/qr/report ---\n";

// Limpiamos incidencias activas en VEND-0201 para probar creación limpia
$pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE machine_id = (SELECT id FROM machines WHERE code = 'VEND-0201'))")->execute();
$pdo->prepare("DELETE FROM incidents WHERE machine_id = (SELECT id FROM machines WHERE code = 'VEND-0201')")->execute();

// 2.1 Creación de ticket inicial sobre máquina limpia
$postDataClean = [
    'machine_code' => 'VEND-0201',
    'category' => 'PRODUCT_JAM',
    'description' => 'Un paquete de patatas quedó colgando de la espiral.',
    'reporter_phone' => '655443322',
];
$reqReportClean = new Request(
    'POST',
    '/api/qr/report',
    [],
    $postDataClean,
    ['content-type' => 'application/json']
);
$resReportClean = $router->dispatch($reqReportClean);

$assert("2.1 Reporte inicial retorna HTTP 201 Created", $resReportClean->getStatusCode() === 201);
$bodyReportClean = json_decode($resReportClean->getBody(), true);
$assert("2.1 success=true", ($bodyReportClean['success'] ?? false) === true);
$assert("2.1 merged=false", ($bodyReportClean['data']['merged'] ?? true) === false);
$ticketCode = $bodyReportClean['data']['ticket_code'] ?? '';
$assert("2.1 ticket_code generado con formato INC-...", str_starts_with($ticketCode, 'INC-'));
$assert("2.1 status inicial es REGISTERED", ($bodyReportClean['data']['status'] ?? '') === 'REGISTERED');

// Verificamos que se haya persistido en BD
$incidentInDb = $incidentRepo->findByTicketCode($ticketCode);
$assert("2.1 Incidencia guardada en BD correctamente", $incidentInDb !== null);

// 2.2 Concurrencia / subsecuente reporte sobre máquina con incidencia abierta (EARS 4.5)
$postDataConcurrent = [
    'machine_code' => 'VEND-0201',
    'category' => 'PAYMENT_SYSTEM',
    'description' => 'A mí tampoco me cae el producto y además no me devolvió las monedas.',
    'reporter_phone' => '688990011',
];
$reqReportConcurrent = new Request(
    'POST',
    '/api/qr/report',
    [],
    $postDataConcurrent,
    ['content-type' => 'application/json']
);
$resReportConcurrent = $router->dispatch($reqReportConcurrent);

$assert("2.2 Reporte concurrente/duplicado retorna HTTP 200 OK (sin 409 conflict)", $resReportConcurrent->getStatusCode() === 200);
$bodyReportConcurrent = json_decode($resReportConcurrent->getBody(), true);
$assert("2.2 merged=true", ($bodyReportConcurrent['data']['merged'] ?? false) === true);
$assert("2.2 Mismo ticket_code unificado", ($bodyReportConcurrent['data']['ticket_code'] ?? '') === $ticketCode);
$concurrentMsg = ($bodyReportConcurrent['data']['message'] ?? '') . ($bodyReportConcurrent['message'] ?? '');
$assert("2.2 Mensaje de unificación amigable", str_contains($concurrentMsg, 'observaciones') || str_contains($concurrentMsg, 'unificada'));

// Verificamos que se haya agregado como comentario en incident_comments
if ($incidentInDb !== null) {
    $stmtComments = $pdo->prepare("SELECT * FROM incident_comments WHERE incident_id = :id ORDER BY id DESC");
    $stmtComments->execute([':id' => $incidentInDb->getId()]);
    $comments = $stmtComments->fetchAll();
    $assert("2.2 Comentario añadido a la avería existente en BD", count($comments) >= 1);
    $assert("2.2 Contenido del comentario coincide con el segundo reporte", str_contains($comments[0]['comment_text'] ?? '', 'no me devolvió las monedas'));
}

// 2.3 Validación de campos obligatorios omitidos
$postInvalid = [
    'machine_code' => 'VEND-0201',
    'category' => '',
    'description' => '',
];
$reqInvalid = new Request('POST', '/api/qr/report', [], $postInvalid, ['content-type' => 'application/json']);
$resInvalid = $router->dispatch($reqInvalid);
$assert("2.3 Campos obligatorios vacíos retorna HTTP 422", $resInvalid->getStatusCode() === 422);
$bodyInvalid = json_decode($resInvalid->getBody(), true);
$assert("2.3 Código de error MISSING_CATEGORY o MISSING_DESCRIPTION", in_array($bodyInvalid['error']['code'] ?? '', ['MISSING_CATEGORY', 'MISSING_DESCRIPTION'], true));

// 2.4 Reporte sobre máquina inexistente
$postNotFound = [
    'machine_code' => 'VEND-FAKEXX',
    'category' => 'OTHER',
    'description' => 'Avería en máquina que no existe.',
];
$reqReportNotFound = new Request('POST', '/api/qr/report', [], $postNotFound, ['content-type' => 'application/json']);
$resReportNotFound = $router->dispatch($reqReportNotFound);
$assert("2.4 Reporte sobre máquina inexistente retorna HTTP 404 Not Found", $resReportNotFound->getStatusCode() === 404);

// =========================================================================
// BLOQUE 3: GET /api/coordinator/machines/{id}/qr-label (Etiqueta Individual)
// =========================================================================
echo "\n--- BLOQUE 3: GET /api/coordinator/machines/{id}/qr-label ---\n";

$targetMachineId = $machinePerishable->getId();

// 3.1 Control de acceso sin token
$reqLabelNoToken = new Request('GET', "/api/coordinator/machines/{$targetMachineId}/qr-label");
$resLabelNoToken = $router->dispatch($reqLabelNoToken);
$assert("3.1 Sin token retorna HTTP 401 Unauthorized", $resLabelNoToken->getStatusCode() === 401);

// 3.2 Control de acceso con token de técnico (rol no permitido)
$reqLabelTech = new Request(
    'GET',
    "/api/coordinator/machines/{$targetMachineId}/qr-label",
    [],
    [],
    ['authorization' => 'Bearer ' . $techToken]
);
$resLabelTech = $router->dispatch($reqLabelTech);
$assert("3.2 Rol técnico retorna HTTP 403 Forbidden", $resLabelTech->getStatusCode() === 403);

// 3.3 Con token de coordinador (JSON por defecto)
$reqLabelCoord = new Request(
    'GET',
    "/api/coordinator/machines/{$targetMachineId}/qr-label",
    [],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resLabelCoord = $router->dispatch($reqLabelCoord);
$assert("3.3 Coordinador retorna HTTP 200 OK en formato JSON", $resLabelCoord->getStatusCode() === 200);
$bodyLabelCoord = json_decode($resLabelCoord->getBody(), true);
$assert("3.3 Contiene svg_content", isset($bodyLabelCoord['data']['svg_content']));
$assert("3.3 svg_content es XML SVG válido", str_contains($bodyLabelCoord['data']['svg_content'], '<svg') && str_contains($bodyLabelCoord['data']['svg_content'], '</svg>'));
$assert("3.3 Metadatos de máquina presentes", ($bodyLabelCoord['data']['machine']['code'] ?? '') === 'VEND-0101');
$assert("3.3 Metadatos de sede presentes", ($bodyLabelCoord['data']['location']['site_code'] ?? '') === 'SEDE-BCN-01');

// 3.4 Actualización persistente de teléfono (update_location_phone=1)
$newPhone = '933009988';
$reqLabelUpdatePhone = new Request(
    'GET',
    "/api/coordinator/machines/{$targetMachineId}/qr-label",
    ['phone' => $newPhone, 'update_location_phone' => '1'],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resLabelUpdatePhone = $router->dispatch($reqLabelUpdatePhone);
$assert("3.4 Petición con update_location_phone=1 retorna HTTP 200 OK", $resLabelUpdatePhone->getStatusCode() === 200);
$bodyUpdatePhone = json_decode($resLabelUpdatePhone->getBody(), true);
$assert("3.4 Teléfono actualizado en el SVG devuelto", str_contains($bodyUpdatePhone['data']['svg_content'], $newPhone));

// Verificamos persistencia en tabla locations de MariaDB
$locUpdated = $locationRepo->findById($loc1Id);
$assert("3.4 Teléfono maestro actualizado en BD MariaDB", $locUpdated !== null && $locUpdated->getContactPhone() === $newPhone);

// 3.5 Descarga directa SVG (format=svg)
$reqLabelSvg = new Request(
    'GET',
    "/api/coordinator/machines/{$targetMachineId}/qr-label",
    ['format' => 'svg'],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resLabelSvg = $router->dispatch($reqLabelSvg);
$assert("3.5 Descarga directa retorna HTTP 200 OK", $resLabelSvg->getStatusCode() === 200);
$headersSvg = $resLabelSvg->getHeaders();
$assert("3.5 Cabecera Content-Type es image/svg+xml; charset=utf-8", ($headersSvg['Content-Type'] ?? '') === 'image/svg+xml; charset=utf-8');
$assert("3.5 Cabecera Content-Disposition es attachment; filename=etiqueta-VEND-0101.svg", ($headersSvg['Content-Disposition'] ?? '') === 'attachment; filename="etiqueta-VEND-0101.svg"');
$assert("3.5 Cuerpo de respuesta comienza con <svg", str_starts_with(trim($resLabelSvg->getBody()), '<svg'));

// 3.6 Máquina inexistente
$reqLabelNotFound = new Request(
    'GET',
    '/api/coordinator/machines/999999/qr-label',
    [],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resLabelNotFound = $router->dispatch($reqLabelNotFound);
$assert("3.6 Máquina inexistente retorna HTTP 404 Not Found", $resLabelNotFound->getStatusCode() === 404);

// =========================================================================
// BLOQUE 4: GET /api/coordinator/locations/{id}/qr-batch (Lote de Sede)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/coordinator/locations/{id}/qr-batch ---\n";

// 4.1 Control de acceso sin token
$reqBatchNoToken = new Request('GET', "/api/coordinator/locations/{$loc1Id}/qr-batch");
$resBatchNoToken = $router->dispatch($reqBatchNoToken);
$assert("4.1 Sin token retorna HTTP 401 Unauthorized", $resBatchNoToken->getStatusCode() === 401);

// 4.2 Control de acceso con token técnico
$reqBatchTech = new Request(
    'GET',
    "/api/coordinator/locations/{$loc1Id}/qr-batch",
    [],
    [],
    ['authorization' => 'Bearer ' . $techToken]
);
$resBatchTech = $router->dispatch($reqBatchTech);
$assert("4.2 Rol técnico retorna HTTP 403 Forbidden", $resBatchTech->getStatusCode() === 403);

// 4.3 Con token de coordinador
$reqBatchCoord = new Request(
    'GET',
    "/api/coordinator/locations/{$loc1Id}/qr-batch",
    [],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resBatchCoord = $router->dispatch($reqBatchCoord);
$assert("4.3 Coordinador retorna HTTP 200 OK", $resBatchCoord->getStatusCode() === 200);
$bodyBatchCoord = json_decode($resBatchCoord->getBody(), true);
$assert("4.3 Contiene metadatos de sede", ($bodyBatchCoord['data']['location']['site_code'] ?? '') === 'SEDE-BCN-01');
$assert("4.3 total_machines es igual a 2", ($bodyBatchCoord['data']['total_machines'] ?? 0) === 2);
$assert("4.3 items es un array con 2 elementos", count($bodyBatchCoord['data']['items'] ?? []) === 2);
$assert("4.3 Cada item contiene svg_content y code", isset($bodyBatchCoord['data']['items'][0]['svg_content']) && isset($bodyBatchCoord['data']['items'][0]['code']));

// 4.4 Sede inexistente
$reqBatchNotFound = new Request(
    'GET',
    '/api/coordinator/locations/999999/qr-batch',
    [],
    [],
    ['authorization' => 'Bearer ' . $coordToken]
);
$resBatchNotFound = $router->dispatch($reqBatchNotFound);
$assert("4.4 Sede inexistente retorna HTTP 404 Not Found", $resBatchNotFound->getStatusCode() === 404);

// =========================================================================
// BLOQUE 5: Verificación de Servidor HTTP Real (cURL)
// =========================================================================
echo "\n--- BLOQUE 5: Verificación HTTP Real (127.0.0.1:8000) ---\n";

$serverAvailable = false;
if (function_exists('curl_init')) {
    $chCheck = curl_init('http://127.0.0.1:8000/api/qr/scan/VEND-0101');
    curl_setopt($chCheck, CURLOPT_TIMEOUT, 1);
    curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
    $healthRes = curl_exec($chCheck);
    $httpCode = curl_getinfo($chCheck, CURLINFO_HTTP_CODE);
    curl_close($chCheck);
    if ($httpCode === 200) {
        $serverAvailable = true;
    }
}

if ($serverAvailable) {
    // 5.1 Escaneo por cURL
    $chScan = curl_init('http://127.0.0.1:8000/api/qr/scan/VEND-0101');
    curl_setopt($chScan, CURLOPT_RETURNTRANSFER, true);
    $scanRes = curl_exec($chScan);
    $scanStatus = curl_getinfo($chScan, CURLINFO_HTTP_CODE);
    curl_close($chScan);

    $assert("5.1 cURL real a GET /api/qr/scan/VEND-0101 retorna 200 OK", $scanStatus === 200);
    $scanJson = json_decode((string)$scanRes, true);
    $assert("5.1 cURL real body contiene machine VEND-0101", ($scanJson['data']['machine']['code'] ?? '') === 'VEND-0101');

    // 5.2 Descarga directa de SVG por cURL
    $chSvg = curl_init("http://127.0.0.1:8000/api/coordinator/machines/{$targetMachineId}/qr-label?format=svg");
    curl_setopt($chSvg, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chSvg, CURLOPT_HEADER, true);
    curl_setopt($chSvg, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$coordToken}"]);
    $svgRaw = curl_exec($chSvg);
    $svgStatus = curl_getinfo($chSvg, CURLINFO_HTTP_CODE);
    $svgContentType = curl_getinfo($chSvg, CURLINFO_CONTENT_TYPE);
    curl_close($chSvg);

    $assert("5.2 cURL real descarga SVG retorna 200 OK", $svgStatus === 200);
    $assert("5.2 cURL real Content-Type es image/svg+xml", str_contains((string)$svgContentType, 'image/svg+xml'));
} else {
    echo "  [SKIP] Servidor local en 127.0.0.1:8000 no detectado. Pruebas de integración HTTP ejecutadas in-memory al 100%.\n";
}

// Resumen final
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS DE INTEGRACIÓN PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
