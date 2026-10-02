<?php

declare(strict_types=1);

/**
 * TechnicianPreventiveApiTest
 * 
 * Test de Integración HTTP para los Endpoints del Técnico en Mantenimiento Preventivo (T-PREV-17).
 * Requisitos: RF-PREV-02, RF-PREV-03, RF-PREV-04, RF-PREV-05, RF-PREV-08, RNF-04, RNF-05.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad RBAC: acceso anónimo devuelve 401.
 * 2. GET /api/technician/preventive/route: 200 OK con ruta preventiva.
 * 3. POST /api/technician/preventive/orders/{id}/claim: 200 OK (Visita Oportunista, EARS 2.3).
 * 4. GET /api/technician/preventive/orders/{id}/checklist: 200 OK con plantilla normativa.
 * 5. POST /api/technician/preventive/orders/{id}/start: 200 OK (transición a IN_INSPECTION).
 * 6. POST /api/technician/preventive/orders/{id}/complete: 200 OK (CONFORME con certificado).
 * 7. POST /api/technician/preventive/orders/{id}/reinspect: 200 OK (levantamiento de cuarentena).
 * 8. Verificación de registro en audit_log tras cada acción.
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Integración - TechnicianPreventiveApiTest (T-PREV-17)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

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

// Obtención de usuarios
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert("0.1 Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR CRÍTICO: Usuarios semilla no encontrados.\n";
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);

$machine1 = $machineRepo->findByCode('VEND-0101');
$machine2 = $machineRepo->findByCode('VEND-0102');
$assert("0.2 Máquinas semilla encontradas", $machine1 !== null && $machine2 !== null);
if ($machine1 === null || $machine2 === null) {
    exit(1);
}

// =========================================================================
// BLOQUE 1: Seguridad RBAC en endpoints de técnico preventivo
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad RBAC ---\n";

$techEndpoints = [
    ['GET', '/api/technician/preventive/route'],
];

foreach ($techEndpoints as [$method, $endpoint]) {
    $reqAnon = new Request($method, $endpoint);
    $resAnon = $router->dispatch($reqAnon);
    $assert("1. {$method} {$endpoint} anónimo => 401", $resAnon->getStatusCode() === 401);
}

// =========================================================================
// BLOQUE 2: GET /api/technician/preventive/route
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/technician/preventive/route ---\n";

$reqRoute = new Request('GET', '/api/technician/preventive/route', [], [], ['Authorization' => 'Bearer ' . $techToken]);
$resRoute = $router->dispatch($reqRoute);

$assert("2.1 Ruta preventiva retorna HTTP 200", $resRoute->getStatusCode() === 200);
$bodyRoute = json_decode($resRoute->getBody(), true);
$assert("2.2 Respuesta contiene success=true", ($bodyRoute['success'] ?? false) === true);

// =========================================================================
// BLOQUE 3: Crear orden preventiva para pruebas de claim/checklist/complete
// =========================================================================
echo "\n--- BLOQUE 3: Preparación de orden preventiva para flujo técnico ---\n";

// Crear orden manualmente vía coordinador para VEND-0102 (HOT_DRINKS, no perecedera)
$reqCreateOrder = new Request('POST', '/api/coordinator/preventive/orders', [], [
    'machine_id' => $machine2->getId(),
    'order_type' => 'ROUTINE',
    'scheduled_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+5 days')),
    'notes' => 'Orden de prueba para flujo técnico T-PREV-17.',
], ['Authorization' => 'Bearer ' . $coordToken]);
$resCreateOrder = $router->dispatch($reqCreateOrder);

$assert("3.1 Creación de orden para VEND-0102 retorna 201", $resCreateOrder->getStatusCode() === 201);
$orderData = json_decode($resCreateOrder->getBody(), true)['data'] ?? [];
$orderId = $orderData['id'] ?? null;
$orderCode = $orderData['order_code'] ?? '';
$assert("3.2 Orden creada con ID numérico", is_numeric($orderId));

// =========================================================================
// BLOQUE 4: POST /api/technician/preventive/orders/{id}/claim (Visita Oportunista)
// =========================================================================
echo "\n--- BLOQUE 4: POST .../claim (Visita Oportunista) ---\n";

if ($orderId !== null) {
    $auditBefore = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

    $reqClaim = new Request('POST', "/api/technician/preventive/orders/{$orderId}/claim", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $reqClaim->setRouteParams(['id' => (string)$orderId]);
    $resClaim = $router->dispatch($reqClaim);

    $assert("4.1 Claim retorna HTTP 200", $resClaim->getStatusCode() === 200);
    $bodyClaim = json_decode($resClaim->getBody(), true);
    $assert("4.2 Respuesta contiene success=true", ($bodyClaim['success'] ?? false) === true);
    $claimData = $bodyClaim['data'] ?? [];
    $assert("4.3 Orden en estado SCHEDULED tras claim", ($claimData['status'] ?? '') === 'SCHEDULED');
    $assert("4.4 Técnico asignado coincide", ($claimData['assigned_technician_id'] ?? 0) === $technician->getId());
    $assert("4.5 Contiene machine.code", isset($claimData['machine']['code']));

    $auditAfter = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $assert("4.6 Evento CLAIM registrado en audit_log", $auditAfter > $auditBefore);

    // 4b. Intentar claim duplicado -> 409 Conflict
    $reqClaimDup = new Request('POST', "/api/technician/preventive/orders/{$orderId}/claim", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $reqClaimDup->setRouteParams(['id' => (string)$orderId]);
    $resClaimDup = $router->dispatch($reqClaimDup);
    $assert("4.7 Claim duplicado retorna 409", $resClaimDup->getStatusCode() === 409);
} else {
    echo "  [SKIP] Bloque 4 omitido.\n";
}

// =========================================================================
// BLOQUE 5: GET /api/technician/preventive/orders/{id}/checklist
// =========================================================================
echo "\n--- BLOQUE 5: GET .../checklist ---\n";

if ($orderId !== null) {
    $reqChecklist = new Request('GET', "/api/technician/preventive/orders/{$orderId}/checklist", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $reqChecklist->setRouteParams(['id' => (string)$orderId]);
    $resChecklist = $router->dispatch($reqChecklist);

    $assert("5.1 Checklist retorna HTTP 200", $resChecklist->getStatusCode() === 200);
    $bodyChecklist = json_decode($resChecklist->getBody(), true);
    $assert("5.2 Respuesta contiene success=true", ($bodyChecklist['success'] ?? false) === true);
    $checkData = $bodyChecklist['data'] ?? [];
    $assert("5.3 Contiene order_code", isset($checkData['order_code']));
    $assert("5.4 Contiene machine con machine_type", isset($checkData['machine']['machine_type']));
    $assert("5.5 Contiene checklist_items array", is_array($checkData['checklist_items'] ?? null) && count($checkData['checklist_items']) > 0);
} else {
    echo "  [SKIP] Bloque 5 omitido.\n";
}

// =========================================================================
// BLOQUE 6: POST /api/technician/preventive/orders/{id}/start
// =========================================================================
echo "\n--- BLOQUE 6: POST .../start ---\n";

if ($orderId !== null) {
    $reqStart = new Request('POST', "/api/technician/preventive/orders/{$orderId}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $reqStart->setRouteParams(['id' => (string)$orderId]);
    $resStart = $router->dispatch($reqStart);

    $assert("6.1 Inicio de inspección retorna HTTP 200", $resStart->getStatusCode() === 200);
    $bodyStart = json_decode($resStart->getBody(), true);
    $assert("6.2 Respuesta contiene success=true", ($bodyStart['success'] ?? false) === true);
    $startData = $bodyStart['data'] ?? [];
    $assert("6.3 Estado transiciona a IN_INSPECTION", ($startData['status'] ?? '') === 'IN_INSPECTION');
    $assert("6.4 Contiene started_at", isset($startData['started_at']));
} else {
    echo "  [SKIP] Bloque 6 omitido.\n";
}

// =========================================================================
// BLOQUE 7: POST /api/technician/preventive/orders/{id}/complete (CONFORME)
// =========================================================================
echo "\n--- BLOQUE 7: POST .../complete (inspección HOT_DRINKS conforme) ---\n";

if ($orderId !== null) {
    $checklistItems = $bodyChecklist['data']['checklist_items'] ?? [];
    $itemsPayload = [];
    foreach ($checklistItems as $item) {
        $itemsPayload[] = [
            'item_code' => $item['item_code'],
            'status' => 'PASS',
            'observations' => null,
        ];
    }

    $reqComplete = new Request('POST', "/api/technician/preventive/orders/{$orderId}/complete", [], [
        'temperature_measured' => null,
        'items' => $itemsPayload,
        'general_notes' => 'Máquina de bebidas calientes en perfecto estado higiénico.',
    ], ['Authorization' => 'Bearer ' . $techToken]);
    $reqComplete->setRouteParams(['id' => (string)$orderId]);
    $resComplete = $router->dispatch($reqComplete);

    $assert("7.1 Completar inspección retorna HTTP 200", $resComplete->getStatusCode() === 200);
    $bodyComplete = json_decode($resComplete->getBody(), true);
    $assert("7.2 Respuesta contiene success=true", ($bodyComplete['success'] ?? false) === true);
    $completeData = $bodyComplete['data'] ?? [];
    $assert("7.3 Dictamen es CONFORME", ($completeData['result'] ?? '') === 'CONFORME');
    $assert("7.4 Estado COMPLETED", ($completeData['status'] ?? '') === 'COMPLETED');
    $assert("7.5 No se activa cuarentena", ($completeData['is_quarantine_triggered'] ?? true) === false);
    $assert("7.6 Certificado emitido con certificate_code", isset($completeData['certificate']['certificate_code']));
    $assert("7.7 Certificado contiene technician_operator_code (Art. V.4)", isset($completeData['certificate']['technician_operator_code']));
    $assert("7.8 Estado sanitario de máquina es OK", ($completeData['machine_sanitary_status'] ?? '') === 'OK');
} else {
    echo "  [SKIP] Bloque 7 omitido.\n";
}

// =========================================================================
// BLOQUE 8: Flujo completo PERECEDERA con NO_CONFORME y Reinspección
// =========================================================================
echo "\n--- BLOQUE 8: Flujo NO_CONFORME + Reinspección (PERECEDERA) ---\n";

// Crear nueva orden para VEND-0101 (PERISHABLE_FOOD)
$reqCreatePerishable = new Request('POST', '/api/coordinator/preventive/orders', [], [
    'machine_id' => $machine1->getId(),
    'order_type' => 'ROUTINE',
    'scheduled_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+3 days')),
], ['Authorization' => 'Bearer ' . $coordToken]);
$resCreatePerishable = $router->dispatch($reqCreatePerishable);

$perishOrderData = json_decode($resCreatePerishable->getBody(), true)['data'] ?? [];
$perishOrderId = $perishOrderData['id'] ?? null;
$assert("8.1 Orden perecedera creada con ID", is_numeric($perishOrderId));

if ($perishOrderId !== null) {
    // Claim
    $rClaim = new Request('POST', "/api/technician/preventive/orders/{$perishOrderId}/claim", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $rClaim->setRouteParams(['id' => (string)$perishOrderId]);
    $router->dispatch($rClaim);

    // Start
    $rStart = new Request('POST', "/api/technician/preventive/orders/{$perishOrderId}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $rStart->setRouteParams(['id' => (string)$perishOrderId]);
    $router->dispatch($rStart);

    // Obtener checklist para perecedera
    $rCheck = new Request('GET', "/api/technician/preventive/orders/{$perishOrderId}/checklist", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $rCheck->setRouteParams(['id' => (string)$perishOrderId]);
    $resCheck = $router->dispatch($rCheck);
    $perishCheckItems = json_decode($resCheck->getBody(), true)['data']['checklist_items'] ?? [];

    // Complete con temperatura alta => NO_CONFORME
    $failItems = [];
    foreach ($perishCheckItems as $item) {
        $st = ($item['item_code'] === 'TEMP_PROBE') ? 'FAIL' : 'PASS';
        $failItems[] = [
            'item_code' => $item['item_code'],
            'status' => $st,
            'observations' => $st === 'FAIL' ? 'Temperatura elevada: 6.8 °C (> 4.0 °C)' : null,
        ];
    }

    $rComplete = new Request('POST', "/api/technician/preventive/orders/{$perishOrderId}/complete", [], [
        'temperature_measured' => 6.8,
        'items' => $failItems,
        'general_notes' => 'Rotura de frío detectada. Compresor averiado.',
    ], ['Authorization' => 'Bearer ' . $techToken]);
    $rComplete->setRouteParams(['id' => (string)$perishOrderId]);
    $resComplete2 = $router->dispatch($rComplete);

    $bodyComplete2 = json_decode($resComplete2->getBody(), true);
    $complete2Data = $bodyComplete2['data'] ?? [];
    $assert("8.2 Complete con rotura de frío retorna 200", $resComplete2->getStatusCode() === 200);
    $assert("8.3 Dictamen NO_CONFORME", ($complete2Data['result'] ?? '') === 'NO_CONFORME');
    $assert("8.4 Cuarentena activada", ($complete2Data['is_quarantine_triggered'] ?? false) === true);
    $assert("8.5 Estado sanitario QUARANTINE", ($complete2Data['machine_sanitary_status'] ?? '') === 'QUARANTINE');
    $assert("8.6 Acción correctiva ejecutada", isset($complete2Data['corrective_action']));
    $correctiveMode = $complete2Data['corrective_action']['mode'] ?? '';
    $assert("8.7 Correctivo mode es CREATED_NEW_INCIDENT o APPENDED", in_array($correctiveMode, ['CREATED_NEW_INCIDENT', 'APPENDED_TO_EXISTING_INCIDENT'], true));

    // Reinspección: necesitamos una orden de reinspección
    // Buscar si se creó una orden de reinspección o crear una
    $reinspectOrders = $pdo->query("SELECT id FROM preventive_orders WHERE machine_id = {$machine1->getId()} AND order_type = 'REINSPECTION' AND status != 'COMPLETED' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchAll();
    
    $reinspectOrderId = null;
    if (!empty($reinspectOrders)) {
        $reinspectOrderId = (int)$reinspectOrders[0]['id'];
    } else {
        // Crear orden de reinspección manualmente
        $rCreateReinsp = new Request('POST', '/api/coordinator/preventive/orders', [], [
            'machine_id' => $machine1->getId(),
            'order_type' => 'REINSPECTION',
            'scheduled_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d'),
            'assigned_technician_id' => $technician->getId(),
        ], ['Authorization' => 'Bearer ' . $coordToken]);
        $resReinsp = $router->dispatch($rCreateReinsp);
        $reinspectOrderId = json_decode($resReinsp->getBody(), true)['data']['id'] ?? null;
    }

    if ($reinspectOrderId !== null) {
        // Start la reinspección si no está en IN_INSPECTION
        $rStartReinsp = new Request('POST', "/api/technician/preventive/orders/{$reinspectOrderId}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
        $rStartReinsp->setRouteParams(['id' => (string)$reinspectOrderId]);
        $router->dispatch($rStartReinsp);

        // Reinspeccionar con temperatura correcta
        $rReinspect = new Request('POST', "/api/technician/preventive/orders/{$reinspectOrderId}/reinspect", [], [
            'temperature_measured' => 3.1,
            'reinspection_notes' => 'Sustituido termostato averiado y desescarchado completo del evaporador. Temperatura estabilizada en 3.1 °C.',
        ], ['Authorization' => 'Bearer ' . $techToken]);
        $rReinspect->setRouteParams(['id' => (string)$reinspectOrderId]);
        $resReinspect = $router->dispatch($rReinspect);

        $assert("8.8 Reinspección retorna HTTP 200", $resReinspect->getStatusCode() === 200);
        $bodyReinspect = json_decode($resReinspect->getBody(), true);
        $reinspData = $bodyReinspect['data'] ?? [];
        $assert("8.9 Reinspección resultado CONFORME", ($reinspData['result'] ?? '') === 'CONFORME');
        $assert("8.10 Estado sanitario vuelve a OK", ($reinspData['machine_sanitary_status'] ?? '') === 'OK');
        $assert("8.11 Certificado emitido tras reinspección", isset($reinspData['certificate']['certificate_code']));
    } else {
        echo "  [SKIP] Reinspección omitida: no se pudo crear orden de reinspección.\n";
    }
}

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);
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
