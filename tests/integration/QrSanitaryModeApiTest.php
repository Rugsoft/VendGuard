<?php

declare(strict_types=1);

/**
 * QrSanitaryModeApiTest
 * 
 * Test de Integración HTTP para los Modos Sanitarios del Endpoint Público QR (T-PREV-17).
 * Requisitos: RF-PREV-04, RF-PREV-01, RNF-04, RNF-05 y Artículos I a V de la Constitución.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. GET /api/qr/scan/{code} en máquina bajo cuarentena sanitaria:
 *    - 200 OK con status_mode = SANITARY_QUARANTINE.
 *    - Alerta de gravedad CRITICAL_DANGER (Art. II).
 *    - can_report = false (bloqueo público de compra y reportes).
 *    - active_incident presente pero con privacidad estricta (Art. V.4: sin datos personales del técnico ni notas internas).
 * 2. POST /api/qr/report sobre máquina en cuarentena sanitaria:
 *    - 422 Unprocessable Content con MACHINE_IN_QUARANTINE.
 * 3. GET /api/qr/scan/{code} en máquina bajo pausa estacional:
 *    - 200 OK con status_mode = SEASONAL_PAUSE.
 *    - Alerta de gravedad INFO.
 *    - can_report = false.
 * 4. POST /api/qr/report sobre máquina en pausa estacional:
 *    - 422 Unprocessable Content con MACHINE_IN_SEASONAL_PAUSE.
 * 5. Reanudación de servicio y retorno a estado CAN_REPORT.
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Integración - QrSanitaryModeApiTest (T-PREV-17)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// 1. Limpieza y preparación de semillas
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$machineRepo = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$settingsRepo = new PdoPreventiveSettingsRepository($pdo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

$mach1 = $machineRepo->findByCode('VEND-0101');
$mach2 = $machineRepo->findByCode('VEND-0102');
$assert("0.1 Máquinas de prueba VEND-0101 y VEND-0102 localizadas", $mach1 !== null && $mach2 !== null);
if ($mach1 === null || $mach2 === null) {
    exit(1);
}

// -------------------------------------------------------------------------
// BLOQUE 1: GET /api/qr/scan/{code} - Cuarentena Sanitaria
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 1: GET /api/qr/scan/{code} - Cuarentena Sanitaria ---\n";

// Configurar máquina en cuarentena sanitaria
$settingsRepo->updateSanitaryStatus($mach1->getId(), 'QUARANTINE');

// Crear incidencia activa asociada
$quarantineIncident = new Incident(
    null,
    'INC-2026-0091',
    $mach1->getId(),
    $mach1->getLocationId(),
    IncidentCategory::TEMPERATURE_COLD,
    'Rotura térmica detectada en inspección preventiva. Temperatura 6.8 °C.',
    UrgencyLevel::CRITICAL,
    IncidentStatus::IN_PROGRESS,
    1,
    'Sistema Preventivo',
    '600123456',
    null,
    null,
    date('Y-m-d H:i:s'),
    date('Y-m-d H:i:s'),
    null,
    null,
    'Compresor averiado (CONFIDENCIAL)',
    'En proceso de cambio (CONFIDENCIAL)'
);
$incidentRepo->create($quarantineIncident);

$reqScanQ = new Request('GET', '/api/qr/scan/VEND-0101');
$resScanQ = $router->dispatch($reqScanQ);

$assert("1.1 GET /api/qr/scan/VEND-0101 retorna HTTP 200", $resScanQ->getStatusCode() === 200);
$bodyQ = json_decode($resScanQ->getBody(), true);
$dataQ = $bodyQ['data'] ?? [];

$assert("1.2 status_mode es SANITARY_QUARANTINE", ($dataQ['status_mode'] ?? '') === 'SANITARY_QUARANTINE');
$assert("1.3 can_report es false", ($dataQ['can_report'] ?? true) === false);
$assert("1.4 alert.severity es CRITICAL_DANGER", ($dataQ['alert']['severity'] ?? '') === 'CRITICAL_DANGER');
$assert("1.5 alert.title contiene MÁQUINA FUERA DE SERVICIO", str_contains($dataQ['alert']['title'] ?? '', 'MÁQUINA FUERA DE SERVICIO'));
$assert("1.6 alert.message menciona Artículo II", str_contains($dataQ['alert']['message'] ?? '', 'Artículo II'));
$assert("1.7 active_incident contiene ticket_code INC-2026-0091", ($dataQ['active_incident']['ticket_code'] ?? '') === 'INC-2026-0091');
$assert("1.8 active_incident contiene status_label", isset($dataQ['active_incident']['status_label']));
$assert("1.9 Art. IV: NO contiene notas de taller ni datos del técnico", !isset($dataQ['active_incident']['assigned_technician_id']) && !isset($dataQ['active_incident']['resolution_diagnosis']));

// -------------------------------------------------------------------------
// BLOQUE 2: Bloqueo de POST /api/qr/report en Cuarentena
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 2: Bloqueo de Reporte en Cuarentena ---\n";

$reqReportQ = new Request(
    'POST',
    '/api/qr/report',
    [],
    [
        'machine_code' => 'VEND-0101',
        'category' => 'PRODUCT_JAM',
        'description' => 'Avería ordinaria reportada por usuario en máquina en cuarentena',
    ]
);
$resReportQ = $router->dispatch($reqReportQ);
$assert("2.1 POST /api/qr/report en cuarentena retorna HTTP 422", $resReportQ->getStatusCode() === 422);
$bodyReportQ = json_decode($resReportQ->getBody(), true);
$assert("2.2 error.code es MACHINE_IN_QUARANTINE", ($bodyReportQ['error']['code'] ?? '') === 'MACHINE_IN_QUARANTINE');

// -------------------------------------------------------------------------
// BLOQUE 3: Pausa Estacional en VEND-0102
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 3: GET /api/qr/scan/{code} - Pausa Estacional ---\n";

$settingsRepo->setSeasonalPause($mach2->getId(), 'Cierre por periodo vacacional de verano', '2026-09-30');

$reqScanP = new Request('GET', '/api/qr/scan/VEND-0102');
$resScanP = $router->dispatch($reqScanP);

$assert("3.1 GET /api/qr/scan/VEND-0102 retorna HTTP 200", $resScanP->getStatusCode() === 200);
$bodyP = json_decode($resScanP->getBody(), true);
$dataP = $bodyP['data'] ?? [];

$assert("3.2 status_mode es SEASONAL_PAUSE", ($dataP['status_mode'] ?? '') === 'SEASONAL_PAUSE');
$assert("3.3 can_report es false", ($dataP['can_report'] ?? true) === false);
$assert("3.4 alert.severity es INFO", ($dataP['alert']['severity'] ?? '') === 'INFO');
$assert("3.5 alert.title contiene PAUSA ESTACIONAL", str_contains($dataP['alert']['title'] ?? '', 'PAUSA ESTACIONAL'));
$assert("3.6 alert.message menciona periodo vacacional", str_contains($dataP['alert']['message'] ?? '', 'periodo vacacional'));

// -------------------------------------------------------------------------
// BLOQUE 4: Bloqueo de POST /api/qr/report en Pausa Estacional
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 4: Bloqueo de Reporte en Pausa Estacional ---\n";

$reqReportP = new Request(
    'POST',
    '/api/qr/report',
    [],
    [
        'machine_code' => 'VEND-0102',
        'category' => 'COIN_ACCEPTOR',
        'description' => 'Avería ordinaria reportada por usuario en máquina en pausa vacacional',
    ]
);
$resReportP = $router->dispatch($reqReportP);
$assert("4.1 POST /api/qr/report en pausa estacional retorna HTTP 422", $resReportP->getStatusCode() === 422);
$bodyReportP = json_decode($resReportP->getBody(), true);
$assert("4.2 error.code es MACHINE_IN_SEASONAL_PAUSE", ($bodyReportP['error']['code'] ?? '') === 'MACHINE_IN_SEASONAL_PAUSE');

// -------------------------------------------------------------------------
// BLOQUE 5: Reanudación y Retorno a la Normalidad
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 5: Reanudación y Retorno a CAN_REPORT ---\n";

$settingsRepo->resumeSeasonalPause($mach2->getId());
$settingsRepo->updateSanitaryStatus($mach2->getId(), 'OK');

$reqScanNormal = new Request('GET', '/api/qr/scan/VEND-0102');
$resScanNormal = $router->dispatch($reqScanNormal);
$bodyNormal = json_decode($resScanNormal->getBody(), true);
$assert("5.1 Tras reanudación, status_mode vuelve a CAN_REPORT", ($bodyNormal['data']['status_mode'] ?? '') === 'CAN_REPORT');
$assert("5.2 can_report vuelve a true", ($bodyNormal['data']['can_report'] ?? false) === true);

// Limpieza final de máquinas de prueba
$settingsRepo->updateSanitaryStatus($mach1->getId(), 'OK');
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas de integración pasaron con éxito ({$assertions} aserciones)! (0 fallos)\n";
} else {
    echo " RESULTADO: {$failures} pruebas fallaron.\n";
    exit(1);
}
echo "======================================================================\n";
