<?php

declare(strict_types=1);

/**
 * QrInactiveMachineScanTest
 * 
 * Test de Integración HTTP para el escaneo y reporte ciudadano sobre máquinas inactivas (RF-04, EARS 4.1, 4.2):
 * 1. GET /api/qr/scan/{code} sobre máquina no existente => HTTP 404 Not Found (MACHINE_NOT_FOUND_OR_INACTIVE).
 * 2. GET /api/qr/scan/{code} sobre máquina con is_active = 0 => HTTP 200 OK (status_mode = INACTIVE, allow_reporting = false, mensaje oficial).
 * 3. GET /api/qr/scan/{code} sobre máquina con deleted_at IS NOT NULL => HTTP 200 OK (status_mode = INACTIVE).
 * 4. GET /api/qr/scan/{code} sobre máquina con sede inactiva => HTTP 200 OK (status_mode = INACTIVE).
 * 5. POST /api/qr/report sobre máquina inactiva => HTTP 422 Unprocessable Entity (MACHINE_INACTIVE, sin inserción de ticket).
 * 6. POST /api/qr/report sobre máquina soft-deleted => HTTP 422 Unprocessable Entity (MACHINE_INACTIVE, sin inserción de ticket).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ tipado estricto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - QrInactiveMachineScanTest (T-ADM-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router      = AppRouter::create();
$machineRepo = new PdoMachineRepository($pdo);

$assertions = 0;
$failures   = 0;

$assert = function (string $description, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
        if ($detail !== '') {
            echo "         Motivo: {$detail}\n";
        }
        $failures++;
    }
};

// =========================================================================
// BLOQUE 1: GET /api/qr/scan/{code} - Máquina No Existente (404)
// =========================================================================
echo "\n--- BLOQUE 1: GET /api/qr/scan/VEND-NOT-FOUND (404) ---\n";

$reqNotFound = new Request('GET', '/api/qr/scan/VEND-NOT-FOUND');
$resNotFound = $router->dispatch($reqNotFound);
$bodyNotFound = $resNotFound->getDecodedBody();

$assert("1.1 Máquina inexistente retorna HTTP 404 Not Found", $resNotFound->getStatusCode() === 404);
$assert("1.2 success es false", ($bodyNotFound['success'] ?? true) === false);
$assert("1.3 Código de error es MACHINE_NOT_FOUND_OR_INACTIVE", ($bodyNotFound['error']['code'] ?? '') === 'MACHINE_NOT_FOUND_OR_INACTIVE');

// =========================================================================
// BLOQUE 2: GET /api/qr/scan/{code} - Máquina Inactiva (is_active = 0) (200 OK)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/qr/scan/VEND-0201 Inactiva (200 OK) ---\n";

// Marcamos VEND-0201 como inactiva en BD
$pdo->prepare("UPDATE machines SET is_active = 0, deleted_at = NULL WHERE code = 'VEND-0201'")->execute();

$reqInactive = new Request('GET', '/api/qr/scan/VEND-0201');
$resInactive = $router->dispatch($reqInactive);
$bodyInactive = $resInactive->getDecodedBody();

$assert("2.1 Máquina inactiva retorna HTTP 200 OK amigable (no 404)", $resInactive->getStatusCode() === 200);
$assert("2.2 success es true", ($bodyInactive['success'] ?? false) === true);
$assert("2.3 status_mode es INACTIVE", ($bodyInactive['data']['status_mode'] ?? '') === 'INACTIVE');
$assert("2.4 status es INACTIVE", ($bodyInactive['data']['status'] ?? '') === 'INACTIVE');
$assert("2.5 is_active es false", ($bodyInactive['data']['is_active'] ?? true) === false);
$assert("2.6 allow_reporting es false (bloqueo en UI)", ($bodyInactive['data']['allow_reporting'] ?? true) === false);
$assert("2.7 active_incident es null", array_key_exists('active_incident', $bodyInactive['data'] ?? []) && $bodyInactive['data']['active_incident'] === null);
$assert("2.8 Incluye mensaje oficial de máquina fuera de servicio", str_contains((string)($bodyInactive['data']['message'] ?? ''), 'fuera de servicio'));
$assert("2.9 Contiene metadatos de modelo y sede", !empty($bodyInactive['data']['model']) && !empty($bodyInactive['data']['location_name']));

// =========================================================================
// BLOQUE 3: GET /api/qr/scan/{code} - Máquina Soft-Deleted (200 OK)
// =========================================================================
echo "\n--- BLOQUE 3: GET /api/qr/scan/VEND-0201 Soft-Deleted (200 OK) ---\n";

// Marcamos VEND-0201 con borrado lógico (is_active = 0, deleted_at = NOW())
$pdo->prepare("UPDATE machines SET is_active = 0, deleted_at = NOW() WHERE code = 'VEND-0201'")->execute();

$reqDeleted = new Request('GET', '/api/qr/scan/VEND-0201');
$resDeleted = $router->dispatch($reqDeleted);
$bodyDeleted = $resDeleted->getDecodedBody();

$assert("3.1 Máquina soft-deleted retorna HTTP 200 OK amigable", $resDeleted->getStatusCode() === 200);
$assert("3.2 status_mode es INACTIVE", ($bodyDeleted['data']['status_mode'] ?? '') === 'INACTIVE');
$assert("3.3 allow_reporting es false", ($bodyDeleted['data']['allow_reporting'] ?? true) === false);
$assert("3.4 is_active es false", ($bodyDeleted['data']['is_active'] ?? true) === false);

// =========================================================================
// BLOQUE 4: GET /api/qr/scan/{code} - Máquina con Sede Inactiva (200 OK)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/qr/scan/VEND-0102 con Sede Inactiva (200 OK) ---\n";

// Restauramos VEND-0201 y desactivamos la sede de VEND-0102 (SEDE-BCN-01)
$pdo->prepare("UPDATE machines SET is_active = 1, deleted_at = NULL WHERE code = 'VEND-0201'")->execute();
$pdo->prepare("UPDATE locations SET is_active = 0, deleted_at = NOW() WHERE site_code = 'SEDE-BCN-01'")->execute();

$reqLocInactive = new Request('GET', '/api/qr/scan/VEND-0102');
$resLocInactive = $router->dispatch($reqLocInactive);
$bodyLocInactive = $resLocInactive->getDecodedBody();

$assert("4.1 Máquina con sede inactiva retorna HTTP 200 OK en modo INACTIVE", $resLocInactive->getStatusCode() === 200);
$assert("4.2 status_mode es INACTIVE", ($bodyLocInactive['data']['status_mode'] ?? '') === 'INACTIVE');
$assert("4.3 allow_reporting es false", ($bodyLocInactive['data']['allow_reporting'] ?? true) === false);

// Restauramos la sede
$pdo->prepare("UPDATE locations SET is_active = 1, deleted_at = NULL WHERE site_code = 'SEDE-BCN-01'")->execute();

// =========================================================================
// BLOQUE 5: POST /api/qr/report - Bloqueo de Reporte sobre Máquina Inactiva (422)
// =========================================================================
echo "\n--- BLOQUE 5: POST /api/qr/report sobre Máquina Inactiva (422) ---\n";

// Dejamos VEND-0201 inactiva
$pdo->prepare("UPDATE machines SET is_active = 0, deleted_at = NULL WHERE code = 'VEND-0201'")->execute();

$totalIncidentsBefore = (int)$pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();

$reqReportInactive = new Request('POST', '/api/qr/report', [], [
    'machine_code'   => 'VEND-0201',
    'category'       => 'PRODUCT_JAM',
    'description'    => 'Intento ciudadano de reporte en máquina dada de baja',
    'reporter_phone' => '655112233'
], ['content-type' => 'application/json']);
$resReportInactive = $router->dispatch($reqReportInactive);
$bodyReportInactive = $resReportInactive->getDecodedBody();

$assert("5.1 Reporte sobre máquina inactiva retorna HTTP 422 Unprocessable Entity", $resReportInactive->getStatusCode() === 422);
$assert("5.2 success es false", ($bodyReportInactive['success'] ?? true) === false);
$assert("5.3 Código de error es MACHINE_INACTIVE", ($bodyReportInactive['error']['code'] ?? '') === 'MACHINE_INACTIVE');
$assert("5.4 Mensaje explica claramente que la máquina está retirada o fuera de servicio", str_contains((string)($bodyReportInactive['error']['message'] ?? ''), 'fuera de servicio') || str_contains((string)($bodyReportInactive['error']['message'] ?? ''), 'retirada'));

$totalIncidentsAfter = (int)$pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
$assert("5.5 No se crea ninguna fila en la tabla incidents", $totalIncidentsAfter === $totalIncidentsBefore);

// =========================================================================
// BLOQUE 6: POST /api/qr/report - Bloqueo de Reporte sobre Máquina Soft-Deleted (422)
// =========================================================================
echo "\n--- BLOQUE 6: POST /api/qr/report sobre Máquina Soft-Deleted (422) ---\n";

$pdo->prepare("UPDATE machines SET is_active = 0, deleted_at = NOW() WHERE code = 'VEND-0201'")->execute();

$reqReportDeleted = new Request('POST', '/api/qr/report', [], [
    'machine_code'   => 'VEND-0201',
    'category'       => 'TEMPERATURE_COLD',
    'description'    => 'Intento ciudadano en máquina borrada lógicamente',
    'reporter_phone' => '655998877'
], ['content-type' => 'application/json']);
$resReportDeleted = $router->dispatch($reqReportDeleted);
$bodyReportDeleted = $resReportDeleted->getDecodedBody();

$assert("6.1 Reporte sobre máquina soft-deleted retorna HTTP 422", $resReportDeleted->getStatusCode() === 422);
$assert("6.2 Código de error es MACHINE_INACTIVE", ($bodyReportDeleted['error']['code'] ?? '') === 'MACHINE_INACTIVE');

$totalIncidentsFinal = (int)$pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
$assert("6.3 Sigue sin insertarse ninguna incidencia", $totalIncidentsFinal === $totalIncidentsBefore);

// Restauramos VEND-0201 al parque activo
$pdo->prepare("UPDATE machines SET is_active = 1, deleted_at = NULL WHERE code = 'VEND-0201'")->execute();

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS DE MÁQUINAS INACTIVAS PASARON EXITOSAMENTE ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-ADM-12 (QrInactiveMachineScan) CUMPLIDA.\n";
    exit(0);
} else {
    echo " RESULTADO: {$failures} fallos detectados de {$assertions} aserciones evaluadas.\n";
    exit(1);
}
