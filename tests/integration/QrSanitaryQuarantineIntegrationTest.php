<?php

declare(strict_types=1);

/**
 * QrSanitaryQuarantineIntegrationTest
 * 
 * Test de Integración con MariaDB y AppRouter para los modos SANITARY_QUARANTINE
 * y SEASONAL_PAUSE en endpoints públicos de Códigos QR (Tarea T-PREV-16).
 * 
 * Valida la condición "Hecho cuando:":
 * - GET /api/qr/scan/{code} sobre máquina en cuarentena sanitaria retorna HTTP 200 con
 *   status_mode = SANITARY_QUARANTINE, alerta de peligro para la salud pública y compras/reportes bloqueados.
 * - GET /api/qr/scan/{code} sobre máquina en pausa estacional retorna HTTP 200 con
 *   status_mode = SEASONAL_PAUSE con aviso informativo vacacional.
 * - POST /api/qr/report rechaza con HTTP 422 intentos de reporte en máquinas en cuarentena o pausa.
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
echo " VendGuard: Test Integración - Modos QR Sanitarios (T-PREV-16)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

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
// BLOQUE 1: Cuarentena Sanitaria en VEND-0101
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 1: GET /api/qr/scan/{code} - Cuarentena Sanitaria ---\n";

// Poner máquina en cuarentena sanitaria
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
// T-PAUSE-29: distintivo literal de riesgo térmico (RF-03.5.1) y no-filtrado
// de la pausa interna en el canal ciudadano (RF-06.1, Art. V.4)
// -------------------------------------------------------------------------

$thermalRiskLabel = \VendGuard\Application\Service\QrScanService::THERMAL_RISK_BADGE_LABEL;
$assert(
    "1.10 RF-03.5.1: el aviso público publica el distintivo de riesgo térmico",
    ($dataQ['alert']['thermal_risk_label'] ?? '') === $thermalRiskLabel,
    'alert.thermal_risk_label: ' . ($dataQ['alert']['thermal_risk_label'] ?? 'AUSENTE')
);
$assert(
    "1.11 El distintivo dice exactamente «Riesgo térmico: máquina en cuarentena preventiva»",
    ($dataQ['alert']['thermal_risk_label'] ?? '') === 'Riesgo térmico: máquina en cuarentena preventiva',
    'literal publicado: ' . ($dataQ['alert']['thermal_risk_label'] ?? 'AUSENTE')
);

/**
 * Claves internas que la pausa por falta de acceso (módulo 11) escribe en el
 * expediente y que JAMÁS deben viajar por el canal ciudadano (RF-06.1, Art. V.4).
 *
 * @var list<string> $forbiddenPauseKeys
 */
$forbiddenPauseKeys = [
    'paused_at',
    'pending_info_reason_category',
    'pending_info_reason_category_label',
    'pending_info_reason_text',
    'total_pending_info_seconds',
];

/**
 * Barrido recursivo de la carga útil pública en busca de claves internas de pausa.
 *
 * @param array<mixed> $payload
 * @param list<string> $forbidden
 * @return list<string> Claves prohibidas encontradas (vacío si el payload es limpio).
 */
$findForbiddenPauseKeys = function (array $payload, array $forbidden) use (&$findForbiddenPauseKeys): array {
    $found = [];
    foreach ($payload as $key => $value) {
        if (is_string($key) && in_array($key, $forbidden, true)) {
            $found[] = $key;
        }
        if (is_array($value)) {
            $found = array_merge($found, $findForbiddenPauseKeys($value, $forbidden));
        }
    }

    return array_values(array_unique($found));
};

$leakedPauseKeys = $findForbiddenPauseKeys($dataQ, $forbiddenPauseKeys);
$assert(
    "1.12 Art. V.4: la carga útil pública no filtra ni un solo campo interno de pausa",
    $leakedPauseKeys === [],
    'claves filtradas: ' . implode(', ', $leakedPauseKeys)
);
$assert(
    "1.13 Art. V.4: tampoco asoma el literal interno PENDING_INFO",
    !str_contains($resScanQ->getBody(), 'PENDING_INFO'),
    'la respuesta pública contiene el estado interno'
);

// La prueba anterior se hace morder: el expediente de la máquina en cuarentena se
// pausa de verdad (columnas del módulo 11 sembradas por SQL dirigido) y se vuelve a
// escanear. Es el escenario real de RF-03.5.1 —máquina en cuarentena por rotura de
// frío con el técnico esperando respuesta de la sede— y el único en el que el canal
// ciudadano podría delatar la pausa si dejara de estar blindado.
$pdo->exec(sprintf(
    "UPDATE `incidents` SET `status` = 'PENDING_INFO', `paused_at` = NOW(), "
    . "`pending_info_reason_category` = 'SITE_ACCESS_BLOCKED', "
    . "`pending_info_reason_text` = 'Sede sin acceso al almacén (CONFIDENCIAL)' "
    . "WHERE `id` = %d",
    (int)$quarantineIncident->getId()
));

$resScanPaused = $router->dispatch(new Request('GET', '/api/qr/scan/VEND-0101'));
$bodyPaused = json_decode($resScanPaused->getBody(), true);
$dataPaused = $bodyPaused['data'] ?? [];
$leakedPausedKeys = $findForbiddenPauseKeys($dataPaused, $forbiddenPauseKeys);

$assert("1.14 Con el expediente pausado, el escaneo sigue sirviendo la cuarentena", ($dataPaused['status_mode'] ?? '') === 'SANITARY_QUARANTINE');
$assert(
    "1.15 El distintivo de riesgo térmico sigue viajando intacto con la pausa viva",
    ($dataPaused['alert']['thermal_risk_label'] ?? '') === 'Riesgo térmico: máquina en cuarentena preventiva'
);
$assert(
    "1.16 Art. V.4: ni un campo de pausa asoma con el expediente pausado",
    $leakedPausedKeys === [],
    'claves filtradas: ' . implode(', ', $leakedPausedKeys)
);
$assert(
    "1.17 Art. V.4: el estado del expediente se publica neutral",
    ($dataPaused['active_incident']['status_label'] ?? '') === 'Intervención técnica prioritaria en curso'
    && !str_contains($resScanPaused->getBody(), 'PENDING_INFO'),
    'status_label: ' . ($dataPaused['active_incident']['status_label'] ?? 'AUSENTE')
);
$assert(
    "1.18 La nota interna de la pausa no viaja al ciudadano",
    !str_contains($resScanPaused->getBody(), 'CONFIDENCIAL'),
    'la respuesta pública contiene la justificación interna'
);

// Se cierra el intervalo de pausa recién sembrado para no dejar el expediente en un
// estado que no le corresponde al resto del bloque (la incidencia se creó en curso).
$pdo->exec(sprintf(
    "UPDATE `incidents` SET `status` = 'IN_PROGRESS', `paused_at` = NULL, "
    . "`pending_info_reason_category` = NULL, `pending_info_reason_text` = NULL "
    . "WHERE `id` = %d",
    (int)$quarantineIncident->getId()
));

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
// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas de integración pasaron con éxito ({$assertions} aserciones)! (0 fallos)\n";
} else {
    echo " RESULTADO: {$failures} pruebas fallaron.\n";
    exit(1);
}
echo "======================================================================\n";
