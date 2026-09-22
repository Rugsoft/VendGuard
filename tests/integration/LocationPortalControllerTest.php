<?php

declare(strict_types=1);

/**
 * LocationPortalControllerTest
 * 
 * Test de Integración para LocationPortalController (Tarea T-21).
 * Valida que:
 * - GET /api/locations/{site_code}/machines devuelve el catálogo JSON de máquinas de la sede.
 * - Detecta si cada máquina tiene avería activa o en periodo de garantía (ticket_code, status).
 * - Aplica segregación de sedes (HTTP 403 ante SITE_MISMATCH).
 * - Funciona tanto en despacho interno del Router como vía HTTP real contra 127.0.0.1:8000.
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
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - LocationPortalControllerTest (T-21)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$authService = new AuthService($locationRepo);

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

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("1. Sede SEDE-BCN-01 recuperada de base de datos", $location !== null);

if ($location !== null) {
    $locId = $location->getId();

    // Limpiar posibles incidencias de prueba previas
    $pdo->exec("DELETE FROM incident_history");
    $pdo->exec("DELETE FROM incidents");

    // Generar token de sede para autenticación Bearer
    $siteToken = $authService->generateSiteToken($location);

    // =====================================================================
    // CASO 1: Consulta de catálogo inicial de máquinas sin averías activas
    // =====================================================================
    echo "\n--- Caso 1: Catálogo de máquinas sin averías activas ---\n";

    $req1 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'X-Site-Code' => 'SEDE-BCN-01'
    ]);
    $res1 = $router->dispatch($req1);

    $assert("1.1 Petición responde HTTP 200 OK", $res1->getStatusCode() === 200);
    $body1 = $res1->getDecodedBody();

    $assert("1.2 Envolvente contiene success => true", ($body1['success'] ?? false) === true);
    $machinesData = $body1['data'] ?? [];
    $assert("1.3 Se devuelven al menos 2 máquinas en SEDE-BCN-01", count($machinesData) >= 2);

    $firstMachine = $machinesData[0] ?? [];
    $assert(
        "1.4 Campos requeridos presentes en la máquina (id, code, model, machine_type, floor_wing)",
        isset($firstMachine['id'], $firstMachine['code'], $firstMachine['model'], $firstMachine['machine_type'], $firstMachine['floor_wing'])
    );
    $assert("1.5 Inicialmente la máquina no tiene avería activa (active_incident === null)", $firstMachine['active_incident'] === null);

    // =====================================================================
    // CASO 2: Detección de avería activa en el catálogo
    // =====================================================================
    echo "\n--- Caso 2: Detección de aviso de avería activo en una máquina ---\n";

    $targetMachineId = (int)$firstMachine['id'];

    // Insertar incidencia activa para la primera máquina
    $testIncident = new Incident(
        null,
        'INC-TEST-T2101',
        $targetMachineId,
        $locId,
        IncidentCategory::TEMPERATURE_COLD,
        'Temperatura de refrigeración a 12 grados en máquina de sándwiches',
        UrgencyLevel::CRITICAL,
        IncidentStatus::REGISTERED
    );
    $createdIncident = $incidentRepo->create($testIncident, null, 'Aviso de prueba T-21');

    $req2 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$siteToken}"
    ]);
    $res2 = $router->dispatch($req2);

    $assert("2.1 Consulta autenticada por Bearer site_token responde HTTP 200 OK", $res2->getStatusCode() === 200);
    $body2 = $res2->getDecodedBody();
    $machinesData2 = $body2['data'] ?? [];

    $reportedMachine = null;
    $unaffectedMachine = null;
    foreach ($machinesData2 as $m) {
        if ($m['id'] === $targetMachineId) {
            $reportedMachine = $m;
        } else {
            $unaffectedMachine = $m;
        }
    }

    $assert(
        "2.2 La máquina averiada reporta active_incident no nulo",
        $reportedMachine !== null && $reportedMachine['active_incident'] !== null
    );

    $activeInc = $reportedMachine['active_incident'] ?? [];
    $assert(
        "2.3 active_incident contiene ticket_code ('INC-TEST-T2101') y status ('REGISTERED')",
        ($activeInc['ticket_code'] ?? '') === 'INC-TEST-T2101' && ($activeInc['status'] ?? '') === 'REGISTERED'
    );

    $assert(
        "2.4 Las demás máquinas de la sede permanecen libres de aviso (active_incident === null)",
        $unaffectedMachine !== null && $unaffectedMachine['active_incident'] === null
    );

    // =====================================================================
    // CASO 3: Detección de máquina con aviso en periodo de garantía (< 48h)
    // =====================================================================
    echo "\n--- Caso 3: Detección de máquina en garantía (RESOLVED) ---\n";

    $pdo->prepare("
        UPDATE incidents 
        SET status = 'RESOLVED',
            resolved_at = NOW(),
            resolution_diagnosis = 'Cambio de compresor y termostato digital',
            resolution_action = 'Carga de gas R134a y prueba de temperatura a 4C completada'
        WHERE id = :id
    ")->execute([':id' => $createdIncident->getId()]);

    $req3 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'X-Site-Code' => 'SEDE-BCN-01'
    ]);
    $res3 = $router->dispatch($req3);
    $body3 = $res3->getDecodedBody();

    $machineInWarranty = null;
    foreach ($body3['data'] ?? [] as $m) {
        if ($m['id'] === $targetMachineId) {
            $machineInWarranty = $m;
            break;
        }
    }

    $assert("3.1 Máquina reporta estado en garantía", $machineInWarranty !== null && $machineInWarranty['active_incident'] !== null);
    $warrantyInfo = $machineInWarranty['active_incident'] ?? [];
    $assert("3.2 Estado de la avería es 'RESOLVED'", ($warrantyInfo['status'] ?? '') === 'RESOLVED');
    $assert("3.3 Flag is_in_warranty es true", ($warrantyInfo['is_in_warranty'] ?? false) === true);

    // =====================================================================
    // CASO 4: Cierre definitivo (CLOSED) inactiva el aviso en la máquina
    // =====================================================================
    echo "\n--- Caso 4: Cierre definitivo (CLOSED) libera la máquina en el portal ---\n";

    $pdo->prepare("UPDATE incidents SET status = 'CLOSED', closed_at = NOW() WHERE id = :id")->execute([':id' => $createdIncident->getId()]);

    $req4 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'X-Site-Code' => 'SEDE-BCN-01'
    ]);
    $res4 = $router->dispatch($req4);
    $body4 = $res4->getDecodedBody();

    $machineClosed = null;
    foreach ($body4['data'] ?? [] as $m) {
        if ($m['id'] === $targetMachineId) {
            $machineClosed = $m;
            break;
        }
    }

    $assert("4.1 Tras CLOSED, la máquina vuelve a tener active_incident === null", $machineClosed !== null && $machineClosed['active_incident'] === null);

    // =====================================================================
    // CASO 5: Segregación constitucional de sedes (HTTP 403 SITE_MISMATCH)
    // =====================================================================
    echo "\n--- Caso 5: Segregación constitucional de datos entre centros (Art. V) ---\n";

    // Petición identificada como SEDE-BCN-02 intentando consultar máquinas de SEDE-BCN-01
    $reqMismatch = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'X-Site-Code' => 'SEDE-BCN-02'
    ]);
    $resMismatch = $router->dispatch($reqMismatch);

    $assert("5.1 Intento de cruce de sede responde HTTP 403 Forbidden", $resMismatch->getStatusCode() === 403);
    $bodyMismatch = $resMismatch->getDecodedBody();
    $assert("5.2 Código de error es 'SITE_MISMATCH'", ($bodyMismatch['error']['code'] ?? '') === 'SITE_MISMATCH');

    // =====================================================================
    // CASO 6: Petición HTTP en vivo contra el servidor local (127.0.0.1:8000)
    // =====================================================================
    echo "\n--- Caso 6: Petición HTTP en vivo (127.0.0.1:8000) ---\n";

    $liveServerUrl = 'http://127.0.0.1:8000';
    $socket = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 1);
    if ($socket) {
        fclose($socket);

        $liveOptions = [
            'http' => [
                'method' => 'GET',
                'header' => "X-Site-Code: SEDE-BCN-01\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ];
        $liveRaw = file_get_contents("{$liveServerUrl}/api/locations/SEDE-BCN-01/machines", false, stream_context_create($liveOptions));
        $liveDecoded = json_decode((string)$liveRaw, true);

        $assert("6.1 Servidor local responde HTTP 200 en GET /api/locations/SEDE-BCN-01/machines", ($liveDecoded['success'] ?? false) === true);
        $assert("6.2 Servidor devuelve catálogo de máquinas en vivo", count($liveDecoded['data'] ?? []) >= 2);
    } else {
        echo "  [INFO] Servidor local no accesible en puerto 8000.\n";
    }

    // Limpieza de datos de prueba
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id = :id")->execute([':id' => $createdIncident->getId()]);
    $pdo->prepare("DELETE FROM incidents WHERE id = :id")->execute([':id' => $createdIncident->getId()]);
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-21 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
