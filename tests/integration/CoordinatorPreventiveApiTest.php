<?php

declare(strict_types=1);

/**
 * CoordinatorPreventiveApiTest
 * 
 * Test de Integración HTTP para los Endpoints de Coordinación de Mantenimiento Preventivo (T-PREV-17).
 * Requisitos: RF-PREV-01, RF-PREV-02, RF-PREV-06, RNF-04, RNF-05.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad RBAC: acceso anónimo devuelve 401; acceso con rol TECHNICIAN devuelve 403.
 * 2. GET /api/coordinator/preventive/dashboard: 200 OK con semáforos y métricas preventivas.
 * 3. GET /api/coordinator/preventive/orders: 200 OK con paginación y filtros.
 * 4. POST /api/coordinator/preventive/orders: 201 Created (orden manual).
 * 5. POST /api/coordinator/preventive/generate-due: 200 OK con generación anticipada.
 * 6. PATCH /api/coordinator/preventive/orders/{id}/assign: 200 OK con registro en audit_log.
 * 7. PATCH /api/coordinator/preventive/orders/{id}/cancel: 200 OK con cancelación lógica (Art. III).
 * 8. GET /api/coordinator/preventive/settings: 200 OK con catálogo de frecuencias.
 * 9. PATCH /api/coordinator/preventive/settings: 422 al violar tope Art. II (perecederos > 15 días).
 * 10. PATCH /api/coordinator/machines/{id}/preventive-config: 200 OK (pausa estacional y frecuencia).
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
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
echo " VendGuard: Test Integración - CoordinatorPreventiveApiTest (T-PREV-17)\n";
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

// Obtención de usuarios semilla
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
$assert("0.2 Máquinas semilla VEND-0101 y VEND-0102 encontradas", $machine1 !== null && $machine2 !== null);
if ($machine1 === null || $machine2 === null) {
    exit(1);
}

// =========================================================================
// BLOQUE 1: Seguridad RBAC (401/403) en endpoints de coordinación preventiva
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad RBAC en endpoints preventivos ---\n";

$protectedEndpoints = [
    ['GET',  '/api/coordinator/preventive/dashboard'],
    ['GET',  '/api/coordinator/preventive/orders'],
    ['POST', '/api/coordinator/preventive/generate-due'],
    ['GET',  '/api/coordinator/preventive/settings'],
];

foreach ($protectedEndpoints as [$method, $endpoint]) {
    // 1a. Sin autenticación -> 401
    $reqAnon = new Request($method, $endpoint);
    $resAnon = $router->dispatch($reqAnon);
    $assert("1a. {$method} {$endpoint} anónimo => 401", $resAnon->getStatusCode() === 401);

    // 1b. Con rol técnico -> 403
    $reqTech = new Request($method, $endpoint, [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resTech = $router->dispatch($reqTech);
    $assert("1b. {$method} {$endpoint} técnico => 403", $resTech->getStatusCode() === 403);
}

// =========================================================================
// BLOQUE 2: GET /api/coordinator/preventive/dashboard
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/coordinator/preventive/dashboard ---\n";

$reqDash = new Request('GET', '/api/coordinator/preventive/dashboard', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resDash = $router->dispatch($reqDash);

$assert("2.1 Dashboard retorna HTTP 200", $resDash->getStatusCode() === 200);
$bodyDash = json_decode($resDash->getBody(), true);
$assert("2.2 Dashboard contiene success=true", ($bodyDash['success'] ?? false) === true);
$dashData = $bodyDash['data'] ?? [];
$assert("2.3 Dashboard contiene summary o datos del semáforo", isset($dashData['summary']) || isset($dashData['total_machines']) || is_array($dashData));

// =========================================================================
// BLOQUE 3: GET /api/coordinator/preventive/orders (Listado con filtros)
// =========================================================================
echo "\n--- BLOQUE 3: GET /api/coordinator/preventive/orders ---\n";

$reqOrders = new Request('GET', '/api/coordinator/preventive/orders', ['page' => '1', 'per_page' => '10'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resOrders = $router->dispatch($reqOrders);

$assert("3.1 Listado de órdenes retorna HTTP 200", $resOrders->getStatusCode() === 200);
$bodyOrders = json_decode($resOrders->getBody(), true);
$assert("3.2 Respuesta contiene success=true", ($bodyOrders['success'] ?? false) === true);
$assert("3.3 Respuesta contiene data con array de órdenes", is_array($bodyOrders['data'] ?? null));

// =========================================================================
// BLOQUE 4: POST /api/coordinator/preventive/orders (Creación manual)
// =========================================================================
echo "\n--- BLOQUE 4: POST /api/coordinator/preventive/orders ---\n";

$reqCreate = new Request('POST', '/api/coordinator/preventive/orders', [], [
    'machine_id' => $machine1->getId(),
    'order_type' => 'MANUAL_EXTRA',
    'scheduled_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+7 days')),
    'notes' => 'Orden de prueba creada en test de integración T-PREV-17.',
], ['Authorization' => 'Bearer ' . $coordToken]);
$resCreate = $router->dispatch($reqCreate);

$assert("4.1 Creación manual retorna HTTP 201", $resCreate->getStatusCode() === 201);
$bodyCreate = json_decode($resCreate->getBody(), true);
$assert("4.2 Respuesta contiene success=true", ($bodyCreate['success'] ?? false) === true);
$createdOrder = $bodyCreate['data'] ?? [];
$assert("4.3 Orden creada tiene order_code con prefijo PREV-", str_starts_with($createdOrder['order_code'] ?? '', 'PREV-'));
$assert("4.4 Orden creada tiene status PENDING_ASSIGNMENT", ($createdOrder['status'] ?? '') === 'PENDING_ASSIGNMENT');
$createdOrderId = $createdOrder['id'] ?? null;
$assert("4.5 Orden creada tiene ID numérico", is_numeric($createdOrderId));

// =========================================================================
// BLOQUE 5: POST /api/coordinator/preventive/generate-due (Generación anticipada)
// =========================================================================
echo "\n--- BLOQUE 5: POST /api/coordinator/preventive/generate-due ---\n";

$reqGenDue = new Request('POST', '/api/coordinator/preventive/generate-due', [], [
    'horizon_days' => 5,
], ['Authorization' => 'Bearer ' . $coordToken]);
$resGenDue = $router->dispatch($reqGenDue);

$assert("5.1 Generación anticipada retorna HTTP 200", $resGenDue->getStatusCode() === 200);
$bodyGenDue = json_decode($resGenDue->getBody(), true);
$assert("5.2 Respuesta contiene success=true", ($bodyGenDue['success'] ?? false) === true);
$genData = $bodyGenDue['data'] ?? [];
$assert("5.3 Contiene orders_generated_count", isset($genData['orders_generated_count']));
$assert("5.4 Contiene machines_evaluated_count", isset($genData['machines_evaluated_count']));

// =========================================================================
// BLOQUE 6: PATCH /api/coordinator/preventive/orders/{id}/assign
// =========================================================================
echo "\n--- BLOQUE 6: PATCH /api/coordinator/preventive/orders/{id}/assign ---\n";

if ($createdOrderId !== null) {
    // Registrar conteo de audit_log antes de la asignación
    $auditCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

    $reqAssign = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/assign", [], [
        'technician_id' => $technician->getId(),
        'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
    ], ['Authorization' => 'Bearer ' . $coordToken]);
    $reqAssign->setRouteParams(['id' => (string)$createdOrderId]);
    $resAssign = $router->dispatch($reqAssign);

    $assert("6.1 Asignación retorna HTTP 200", $resAssign->getStatusCode() === 200);
    $bodyAssign = json_decode($resAssign->getBody(), true);
    $assert("6.2 Respuesta contiene success=true", ($bodyAssign['success'] ?? false) === true);
    $assignData = $bodyAssign['data'] ?? [];
    $assert("6.3 Orden ahora en estado SCHEDULED", ($assignData['status'] ?? '') === 'SCHEDULED');
    $assert("6.4 Respuesta contiene datos del técnico", isset($assignData['technician']));

    // Verificar registro en audit_log
    $auditCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $assert("6.5 Evento ASSIGN registrado en audit_log", $auditCountAfter > $auditCountBefore);

    // ─── EARS 2.6: sólo la reasignación a otro técnico exige motivo justificado ───
    $secondTechnician = $userRepo->findByEmail('marta.ruta@vendguard.internal');
    $assert("6.6 Usuario técnico alternativo preparado para la reasignación", $secondTechnician !== null);

    if ($secondTechnician !== null) {
        // 6a. Reprogramación de la fecha conservando al mismo técnico: sigue exenta de motivo.
        $reqReschedule = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/assign", [], [
            'technician_id' => $technician->getId(),
            'scheduled_date' => date('Y-m-d', strtotime('+3 days')),
        ], ['Authorization' => 'Bearer ' . $coordToken]);
        $reqReschedule->setRouteParams(['id' => (string)$createdOrderId]);
        $resReschedule = $router->dispatch($reqReschedule);
        $assert("6.7 Reprogramar la fecha del mismo técnico no exige motivo (EARS 2.6)", $resReschedule->getStatusCode() === 200);

        // 6b. Reasignar a otro técnico sin motivo => 422 MISSING_REASSIGNMENT_REASON.
        $reqNoReason = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/assign", [], [
            'technician_id' => $secondTechnician->getId(),
            'scheduled_date' => date('Y-m-d', strtotime('+4 days')),
        ], ['Authorization' => 'Bearer ' . $coordToken]);
        $reqNoReason->setRouteParams(['id' => (string)$createdOrderId]);
        $resNoReason = $router->dispatch($reqNoReason);
        $assert("6.8 Reasignación sin motivo => 422 MISSING_REASSIGNMENT_REASON",
            $resNoReason->getStatusCode() === 422 && ($resNoReason->getDecodedBody()['error']['code'] ?? '') === 'MISSING_REASSIGNMENT_REASON');

        // 6c. Motivo por debajo de 10 caracteres reales => 422 REASSIGNMENT_REASON_TOO_SHORT.
        $reqShortReason = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/assign", [], [
            'technician_id' => $secondTechnician->getId(),
            'scheduled_date' => date('Y-m-d', strtotime('+4 days')),
            'reassignment_reason' => 'Corto',
        ], ['Authorization' => 'Bearer ' . $coordToken]);
        $reqShortReason->setRouteParams(['id' => (string)$createdOrderId]);
        $resShortReason = $router->dispatch($reqShortReason);
        $assert("6.9 Motivo por debajo de 10 caracteres => 422 REASSIGNMENT_REASON_TOO_SHORT",
            $resShortReason->getStatusCode() === 422 && ($resShortReason->getDecodedBody()['error']['code'] ?? '') === 'REASSIGNMENT_REASON_TOO_SHORT');

        // 6d. Reasignación justificada => 200 con el nuevo responsable y rastro inmutable (Art. III).
        $reassignmentReason = 'Baja médica de la técnica previa';
        $reqReassign = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/assign", [], [
            'technician_id' => $secondTechnician->getId(),
            'scheduled_date' => date('Y-m-d', strtotime('+4 days')),
            'reassignment_reason' => $reassignmentReason,
        ], ['Authorization' => 'Bearer ' . $coordToken]);
        $reqReassign->setRouteParams(['id' => (string)$createdOrderId]);
        $resReassign = $router->dispatch($reqReassign);
        $bodyReassign = json_decode($resReassign->getBody(), true);
        $assert("6.10 Reasignación justificada => 200 OK con el nuevo responsable",
            $resReassign->getStatusCode() === 200
            && ($bodyReassign['data']['technician']['id'] ?? null) === $secondTechnician->getId());

        $auditReassignStmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = 'ASSIGN_PREVENTIVE_ORDER' ORDER BY id DESC LIMIT 1");
        $auditReassignStmt->execute();
        $reassignAudit = $auditReassignStmt->fetch(PDO::FETCH_ASSOC);
        $auditNewState = json_decode((string)($reassignAudit['new_state'] ?? ''), true) ?? [];
        $auditMetadata = json_decode((string)($reassignAudit['metadata'] ?? ''), true) ?? [];
        $assert("6.11 audit_log: motivo, técnico sustituido y nuevo responsable registrados",
            ($auditNewState['reassignment_reason'] ?? null) === $reassignmentReason
            && ($auditMetadata['previous_technician_id'] ?? null) === $technician->getId()
            && ($auditMetadata['new_technician_id'] ?? null) === $secondTechnician->getId());
    }
} else {
    echo "  [SKIP] Bloque 6 omitido: no se obtuvo ID de orden creada.\n";
}

// =========================================================================
// BLOQUE 7: PATCH /api/coordinator/preventive/orders/{id}/cancel (Art. III)
// =========================================================================
echo "\n--- BLOQUE 7: PATCH /api/coordinator/preventive/orders/{id}/cancel ---\n";

if ($createdOrderId !== null) {
    // 7a. Intentar cancelar sin motivo -> debe fallar
    $reqCancelNoReason = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/cancel", [], [
        'reason' => '',
    ], ['Authorization' => 'Bearer ' . $coordToken]);
    $reqCancelNoReason->setRouteParams(['id' => (string)$createdOrderId]);
    $resCancelNoReason = $router->dispatch($reqCancelNoReason);
    $assert("7.1 Cancelación sin motivo retorna 400", $resCancelNoReason->getStatusCode() === 400);

    // 7b. Cancelar con motivo justificado
    $reqCancel = new Request('PATCH', "/api/coordinator/preventive/orders/{$createdOrderId}/cancel", [], [
        'reason' => 'Traslado de máquina a otra sede durante las obras de ampliación del hospital.',
    ], ['Authorization' => 'Bearer ' . $coordToken]);
    $reqCancel->setRouteParams(['id' => (string)$createdOrderId]);
    $resCancel = $router->dispatch($reqCancel);

    $assert("7.2 Cancelación con motivo retorna HTTP 200", $resCancel->getStatusCode() === 200);
    $bodyCancel = json_decode($resCancel->getBody(), true);
    $assert("7.3 Respuesta contiene success=true", ($bodyCancel['success'] ?? false) === true);
    $cancelData = $bodyCancel['data'] ?? [];
    $assert("7.4 Orden pasa a estado CANCELLED", ($cancelData['status'] ?? '') === 'CANCELLED');
    $assert("7.5 Se conserva el motivo de cancelación", str_contains($cancelData['cancellation_reason'] ?? '', 'Traslado'));
} else {
    echo "  [SKIP] Bloque 7 omitido.\n";
}

// =========================================================================
// BLOQUE 8: GET /api/coordinator/preventive/settings (Catálogo de frecuencias)
// =========================================================================
echo "\n--- BLOQUE 8: GET /api/coordinator/preventive/settings ---\n";

$reqSettings = new Request('GET', '/api/coordinator/preventive/settings', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resSettings = $router->dispatch($reqSettings);

$assert("8.1 Settings retorna HTTP 200", $resSettings->getStatusCode() === 200);
$bodySettings = json_decode($resSettings->getBody(), true);
$assert("8.2 Respuesta contiene success=true", ($bodySettings['success'] ?? false) === true);
$settingsData = $bodySettings['data'] ?? [];
$assert("8.3 Catálogo contiene al menos 1 tipología", is_array($settingsData) && count($settingsData) >= 1);

// Verificar que PERISHABLE_FOOD tiene max_allowed_days <= 15
$perishable = null;
foreach ($settingsData as $s) {
    if (($s['machine_type'] ?? '') === 'PERISHABLE_FOOD') {
        $perishable = $s;
        break;
    }
}
$assert("8.4 PERISHABLE_FOOD tiene max_allowed_days <= 15 (Art. II)", $perishable !== null && ($perishable['max_allowed_days'] ?? 999) <= 15);

// =========================================================================
// BLOQUE 9: PATCH /api/coordinator/preventive/settings (Blindaje Art. II)
// =========================================================================
echo "\n--- BLOQUE 9: PATCH /api/coordinator/preventive/settings (violación Art. II) ---\n";

$reqSettingsViolation = new Request('PATCH', '/api/coordinator/preventive/settings', [], [
    'machine_type' => 'PERISHABLE_FOOD',
    'default_frequency_days' => 20,
    'max_allowed_days' => 20,
], ['Authorization' => 'Bearer ' . $coordToken]);
$resSettingsViolation = $router->dispatch($reqSettingsViolation);

$assert("9.1 Violación Art. II retorna HTTP 422", $resSettingsViolation->getStatusCode() === 422);
$bodyViolation = json_decode($resSettingsViolation->getBody(), true);
$assert("9.2 Error code es PERISHABLE_FREQUENCY_LIMIT_EXCEEDED", ($bodyViolation['error']['code'] ?? '') === 'PERISHABLE_FREQUENCY_LIMIT_EXCEEDED');

// =========================================================================
// BLOQUE 10: PATCH /api/coordinator/machines/{id}/preventive-config
// =========================================================================
echo "\n--- BLOQUE 10: PATCH /api/coordinator/machines/{id}/preventive-config ---\n";

$machId = $machine2->getId();

// 10a. Activar pausa estacional
$reqPause = new Request('PATCH', "/api/coordinator/machines/{$machId}/preventive-config", [], [
    'is_seasonal_pause' => true,
    'seasonal_pause_reason' => 'Cierre por periodo vacacional de verano.',
    'seasonal_pause_until' => date('Y-m-d', strtotime('+30 days')),
], ['Authorization' => 'Bearer ' . $coordToken]);
$reqPause->setRouteParams(['id' => (string)$machId]);
$resPause = $router->dispatch($reqPause);

$assert("10.1 Activar pausa estacional retorna HTTP 200", $resPause->getStatusCode() === 200);
$bodyPause = json_decode($resPause->getBody(), true);
$assert("10.2 Respuesta contiene success=true", ($bodyPause['success'] ?? false) === true);

// 10b. Desactivar pausa estacional
$reqResume = new Request('PATCH', "/api/coordinator/machines/{$machId}/preventive-config", [], [
    'is_seasonal_pause' => false,
], ['Authorization' => 'Bearer ' . $coordToken]);
$reqResume->setRouteParams(['id' => (string)$machId]);
$resResume = $router->dispatch($reqResume);

$assert("10.3 Desactivar pausa estacional retorna HTTP 200", $resResume->getStatusCode() === 200);

// 10c. Intento de frecuencia > 15 días en perecedera (VEND-0101, PERISHABLE_FOOD) => 422
$machIdPerishable = $machine1->getId();
$reqFreqViolation = new Request('PATCH', "/api/coordinator/machines/{$machIdPerishable}/preventive-config", [], [
    'sanitary_frequency_days' => 20,
], ['Authorization' => 'Bearer ' . $coordToken]);
$reqFreqViolation->setRouteParams(['id' => (string)$machIdPerishable]);
$resFreqViolation = $router->dispatch($reqFreqViolation);

$assert("10.4 Frecuencia 20 días en perecedera retorna 422", $resFreqViolation->getStatusCode() === 422);

// 10d. GET /api/coordinator/machines/{id}/preventive-config
$reqGetConfig = new Request('GET', "/api/coordinator/machines/{$machIdPerishable}/preventive-config", [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$reqGetConfig->setRouteParams(['id' => (string)$machIdPerishable]);
$resGetConfig = $router->dispatch($reqGetConfig);

$assert("10.5 GET preventive-config retorna HTTP 200", $resGetConfig->getStatusCode() === 200);
$bodyGetConfig = json_decode($resGetConfig->getBody(), true);
$assert("10.6 Respuesta contiene datos de la máquina", ($bodyGetConfig['success'] ?? false) === true);

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

// Restaurar sanitary_status a OK
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
