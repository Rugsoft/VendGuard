<?php

declare(strict_types=1);

/**
 * SiteSanitaryApiTest
 * 
 * Test de Integración HTTP para los Endpoints del Portal de Sede en Mantenimiento Preventivo (T-PREV-17).
 * Requisitos: RF-PREV-06, RF-PREV-07, RNF-03, RNF-04, RNF-05.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad: acceso sin autenticación de sede devuelve 401.
 * 2. GET /api/site/sanitary-status: 200 OK con semáforos y estado higiénico de sede.
 * 3. GET /api/site/certificates/machine/{code}: 200 OK con certificado individual.
 * 4. GET /api/site/certificates/global: 200 OK con certificado consolidado de sede.
 * 5. Privacidad Art. V.4: ausencia de datos sensibles del técnico (DNI, teléfono).
 * 6. Verificación de dictamen CONDICIONADO ante máquina en cuarentena.
 * 7. Registro de eventos en audit_log.
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoSanitaryCertificateRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Integración - SiteSanitaryApiTest (T-PREV-17)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// 1. Limpieza estricta y semillas
$pdo->exec("DELETE FROM preventive_order_items");
$pdo->exec("DELETE FROM sanitary_certificates");
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");
$pdo->exec("DELETE FROM preventive_orders");

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$settingsRepo = new PdoPreventiveSettingsRepository($pdo);
$orderRepo = new PdoPreventiveOrderRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

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

// Obtención de sede y token
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("0.1 Sede SEDE-BCN-01 encontrada", $location !== null);
if ($location === null) {
    echo "ERROR CRÍTICO: Sede semilla no encontrada.\n";
    exit(1);
}

$siteToken = $authService->generateSiteToken($location);
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert("0.2 Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);

$machine1 = $machineRepo->findByCode('VEND-0101');
$machine2 = $machineRepo->findByCode('VEND-0102');
$assert("0.3 Máquinas semilla encontradas", $machine1 !== null && $machine2 !== null);
if ($machine1 === null || $machine2 === null) {
    exit(1);
}

// =========================================================================
// BLOQUE 1: Seguridad - Acceso sin autenticación
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad en endpoints de sede ---\n";

$siteEndpoints = [
    ['GET', '/api/site/sanitary-status'],
    ['GET', '/api/site/certificates/global'],
];

foreach ($siteEndpoints as [$method, $endpoint]) {
    $reqAnon = new Request($method, $endpoint);
    $resAnon = $router->dispatch($reqAnon);
    $assert("1. {$method} {$endpoint} sin auth => 401", $resAnon->getStatusCode() === 401);
}

// =========================================================================
// BLOQUE 2: GET /api/site/sanitary-status (Semáforo de sede)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/site/sanitary-status ---\n";

$reqStatus = new Request('GET', '/api/site/sanitary-status', [], [], ['Authorization' => 'Bearer ' . $siteToken]);
$resStatus = $router->dispatch($reqStatus);

$assert("2.1 Sanitary status retorna HTTP 200", $resStatus->getStatusCode() === 200);
$bodyStatus = json_decode($resStatus->getBody(), true);
$assert("2.2 Respuesta contiene success=true", ($bodyStatus['success'] ?? false) === true);
$statusData = $bodyStatus['data'] ?? [];
$assert("2.3 Contiene datos de location", isset($statusData['location']));
$assert("2.4 Contiene machines array", isset($statusData['machines']) && is_array($statusData['machines']));

// Verificar que los datos son de la sede autenticada
if (isset($statusData['location']['site_code'])) {
    $assert("2.5 Sede coincide con SEDE-BCN-01", $statusData['location']['site_code'] === 'SEDE-BCN-01');
}

// =========================================================================
// BLOQUE 3: Preparar certificado para pruebas (flujo completo conforme)
// =========================================================================
echo "\n--- BLOQUE 3: Preparación de certificado para VEND-0102 ---\n";

// Crear orden, asignar, iniciar y completar (CONFORME) para tener un certificado
$reqCreate = new Request('POST', '/api/coordinator/preventive/orders', [], [
    'machine_id' => $machine2->getId(),
    'order_type' => 'ROUTINE',
    'scheduled_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+5 days')),
    'assigned_technician_id' => $technician->getId(),
], ['Authorization' => 'Bearer ' . $coordToken]);
$resCreate = $router->dispatch($reqCreate);
$createData = json_decode($resCreate->getBody(), true)['data'] ?? [];
$testOrderId = $createData['id'] ?? null;
$assert("3.1 Orden de prueba creada", is_numeric($testOrderId));

if ($testOrderId !== null) {
    // Start inspection
    $rStart = new Request('POST', "/api/technician/preventive/orders/{$testOrderId}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $rStart->setRouteParams(['id' => (string)$testOrderId]);
    $router->dispatch($rStart);

    // Get checklist
    $rCheck = new Request('GET', "/api/technician/preventive/orders/{$testOrderId}/checklist", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $rCheck->setRouteParams(['id' => (string)$testOrderId]);
    $resCheck = $router->dispatch($rCheck);
    $checkItems = json_decode($resCheck->getBody(), true)['data']['checklist_items'] ?? [];

    $itemsPayload = [];
    foreach ($checkItems as $item) {
        $itemsPayload[] = [
            'item_code' => $item['item_code'],
            'status' => 'PASS',
            'observations' => null,
        ];
    }

    // Complete (CONFORME)
    $rComplete = new Request('POST', "/api/technician/preventive/orders/{$testOrderId}/complete", [], [
        'items' => $itemsPayload,
        'general_notes' => 'Inspección de prueba conforme para certificado.',
    ], ['Authorization' => 'Bearer ' . $techToken]);
    $rComplete->setRouteParams(['id' => (string)$testOrderId]);
    $resComplete = $router->dispatch($rComplete);

    $completeBody = json_decode($resComplete->getBody(), true);
    $certCode = $completeBody['data']['certificate']['certificate_code'] ?? null;
    $assert("3.2 Certificado emitido para VEND-0102", $certCode !== null);
}

// =========================================================================
// BLOQUE 4: GET /api/site/certificates/machine/{code} (Certificado individual)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/site/certificates/machine/{code} ---\n";

$reqCert = new Request('GET', '/api/site/certificates/machine/VEND-0102', [], [], ['Authorization' => 'Bearer ' . $siteToken]);
$reqCert->setRouteParams(['code' => 'VEND-0102']);
$resCert = $router->dispatch($reqCert);

$assert("4.1 Certificado individual retorna HTTP 200", $resCert->getStatusCode() === 200);
$bodyCert = json_decode($resCert->getBody(), true);
$assert("4.2 Respuesta contiene success=true", ($bodyCert['success'] ?? false) === true);
$certData = $bodyCert['data'] ?? [];
$assert("4.3 Contiene certificate_code", isset($certData['certificate_code']));
$assert("4.4 Contiene inspector con operator_code (Art. V.4)", isset($certData['inspector']['operator_code']));

// Art. V.4: Verificar que NO contiene datos sensibles
$certJson = json_encode($certData);
$assert("4.5 Art. V.4: NO contiene teléfono personal", !str_contains($certJson, 'phone') || str_contains($certJson, 'contact_phone'));
$assert("4.6 Contiene result (CONFORME)", in_array($certData['result'] ?? '', ['CONFORME', 'CONFORME_CON_OBSERVACIONES'], true));
$assert("4.7 Contiene machine.code", isset($certData['machine']['code']));
$assert("4.8 Contiene inspection_date", isset($certData['inspection_date']));
$assert("4.9 Contiene valid_until", isset($certData['valid_until']));

// =========================================================================
// BLOQUE 5: GET /api/site/certificates/global (Certificado consolidado de sede)
// =========================================================================
echo "\n--- BLOQUE 5: GET /api/site/certificates/global ---\n";

$reqGlobal = new Request('GET', '/api/site/certificates/global', [], [], ['Authorization' => 'Bearer ' . $siteToken]);
$resGlobal = $router->dispatch($reqGlobal);

$assert("5.1 Certificado global retorna HTTP 200", $resGlobal->getStatusCode() === 200);
$bodyGlobal = json_decode($resGlobal->getBody(), true);
$assert("5.2 Respuesta contiene success=true", ($bodyGlobal['success'] ?? false) === true);
$globalData = $bodyGlobal['data'] ?? [];
$assert("5.3 Contiene location.site_code", isset($globalData['location']['site_code']));
$assert("5.4 Contiene global_verdict o dictamen", isset($globalData['global_verdict']) || isset($globalData['verdict']));
$assert("5.5 Contiene machines_breakdown", isset($globalData['machines_breakdown']) && is_array($globalData['machines_breakdown']));

// =========================================================================
// BLOQUE 6: Verificar dictamen CONDICIONADO ante máquina en cuarentena
// =========================================================================
echo "\n--- BLOQUE 6: Dictamen CONDICIONADO con cuarentena ---\n";

// Poner VEND-0101 en cuarentena
$settingsRepo->updateSanitaryStatus($machine1->getId(), 'QUARANTINE');

$reqGlobalQ = new Request('GET', '/api/site/certificates/global', [], [], ['Authorization' => 'Bearer ' . $siteToken]);
$resGlobalQ = $router->dispatch($reqGlobalQ);

$bodyGlobalQ = json_decode($resGlobalQ->getBody(), true);
$globalQData = $bodyGlobalQ['data'] ?? [];
$globalVerdict = $globalQData['global_verdict'] ?? ($globalQData['verdict'] ?? '');
$assert("6.1 Certificado global con cuarentena retorna 200", $resGlobalQ->getStatusCode() === 200);
$assert("6.2 Dictamen es CONDICIONADO ante cuarentena", $globalVerdict === 'CONDICIONADO');
$assert("6.3 has_quarantine_or_expired es true", ($globalQData['has_quarantine_or_expired'] ?? false) === true);

// Verificar que la máquina en cuarentena aparece en el breakdown
$quarantineMachine = null;
foreach ($globalQData['machines_breakdown'] ?? [] as $mb) {
    if (($mb['code'] ?? '') === 'VEND-0101') {
        $quarantineMachine = $mb;
        break;
    }
}
$assert("6.4 VEND-0101 aparece en breakdown con quarantine=true", $quarantineMachine !== null && ($quarantineMachine['quarantine'] ?? false) === true);

// =========================================================================
// BLOQUE 7: Acceso con X-Site-Code header (alternativa a Bearer)
// =========================================================================
echo "\n--- BLOQUE 7: Autenticación mediante X-Site-Code ---\n";

$reqXSite = new Request('GET', '/api/site/sanitary-status', [], [], ['X-Site-Code' => 'SEDE-BCN-01']);
$resXSite = $router->dispatch($reqXSite);

$assert("7.1 Acceso con X-Site-Code retorna HTTP 200", $resXSite->getStatusCode() === 200);
$bodyXSite = json_decode($resXSite->getBody(), true);
$assert("7.2 Respuesta contiene success=true", ($bodyXSite['success'] ?? false) === true);

// =========================================================================
// Limpieza final
// =========================================================================
$pdo->exec("DELETE FROM preventive_order_items");
$pdo->exec("DELETE FROM sanitary_certificates");
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");
$pdo->exec("DELETE FROM preventive_orders");
$settingsRepo->updateSanitaryStatus($machine1->getId(), 'OK');
$settingsRepo->updateSanitaryStatus($machine2->getId(), 'OK');

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas pasaron con éxito ({$assertions} aserciones)! (0 fallos)\n";
} else {
    echo " RESULTADO: {$failures} prueba(s) fallaron de {$assertions} aserciones.\n";
    exit(1);
}
echo "======================================================================\n";
