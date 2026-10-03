<?php

declare(strict_types=1);

/**
 * VendGuard - TechnicianSparePartsApiTest
 * 
 * Test de Integración HTTP para los Endpoints del Técnico en Movilidad y Trazabilidad de Repuestos (T-SPARE-19).
 * Requisitos: RF-REP-03, RF-REP-04, RF-REP-05, RF-REP-06, RF-REP-07, RNF-REP-02, RNF-REP-04, Constitución Art. III y Art. V.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad RBAC: acceso anónimo devuelve 401; acceso con rol no autorizado (LOCATION_MANAGER) devuelve 403;
 *    incidencia no asignada al técnico devuelve 403.
 * 2. GET /api/technician/spare-parts/catalog: 200 OK con catálogo móvil compatible (< 250 ms, RNF-REP-02),
 *    validación de machine_id (400/404), y preservación de repuestos desactivados en averías en curso (Caso Límite 4).
 * 3. PATCH /api/technician/incidents/{id}/pause: 200 OK con pausa técnica estructurada por repuestos pendientes (RF-REP-03, RF-REP-04):
 *    - Validación de estado actual (debe estar en IN_PROGRESS -> 422).
 *    - Validación de incompatibilidad de modelo (422 INCOMPATIBLE_SPARE_PART).
 *    - Validación de cantidad [1..50] (422 INVALID_PART_QUANTITY).
 *    - Validación de ausencia de selección (422 MISSING_PARTS_REQUEST).
 *    - Validación de justificación técnica fuera de catálogo >= 20 caracteres (422 INVALID_OUT_OF_CATALOG_JUSTIFICATION).
 *    - Registro exitoso en spare_part_requests y transición a PENDING_PARTS.
 * 4. POST /api/technician/incidents/{id}/resolve: 200 OK con resolución obligatoriamente justificada y repuestos (RF-REP-05, RF-REP-06, RF-REP-07):
 *    - Reanudación de la incidencia a IN_PROGRESS vía startIntervention.
 *    - Validación de diagnósticos y acciones mínimas (>= 20 caracteres -> 422).
 *    - Declaración obligatoria de sustitución (replaced_parts_declared -> 422 PARTS_RECORD_REQUIRED).
 *    - Validación de lista vacía con declaración afirmativa (422 EMPTY_REPLACED_PARTS_LIST).
 *    - Validación de destino reglamentario (DESGUACE o TALLER -> 422 INVALID_PART_DESTINATION).
 *    - Resolución exitosa CON piezas: congelación inmutable de snapshot de coste (Art. III), paso de solicitudes a ATTENDED.
 *    - Verificación de inmutabilidad: el cambio posterior del precio maestro en spare_parts no altera el snapshot histórico.
 *    - Resolución exitosa SIN piezas: cancelación automática de solicitudes pendientes (Caso Límite 3 / RF-REP-05).
 * 5. POST /api/technician/preventive/orders/{id}/complete: 200 OK con registro de piezas sustituidas en preventivo (RF-REP-07):
 *    - Persistencia en incident_replaced_parts con intervention_type = 'PREVENTIVE'.
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 * Dualismo Lingüístico: Arquitectura y código en inglés, contratos y mensajes en español.
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Integración - TechnicianSparePartsApiTest (T-SPARE-19)\n";
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

// 2. Obtención de usuarios y tokens
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert("0.1 Usuarios semilla localizados", $technician !== null && $coordinator !== null);
if ($technician === null || $coordinator === null) {
    echo "ERROR CRÍTICO: Usuarios semilla requeridos no encontrados.\n";
    exit(1);
}

$techToken = $authService->generateInternalToken($technician);
$coordToken = $authService->generateInternalToken($coordinator);

$techId = (int)$technician->getId();

// 3. Obtención de máquinas de prueba
$machine1 = $machineRepo->findByCode('VEND-0101'); // Sanden Vendo G-Drink
$machine2 = $machineRepo->findByCode('VEND-0102'); // Bianchi Gaia Espresso

$assert("0.2 Máquinas semilla localizadas", $machine1 !== null && $machine2 !== null);
if ($machine1 === null || $machine2 === null) {
    exit(1);
}

$m1Id = (int)$machine1->getId();
$m1LocId = (int)$machine1->getLocationId();
$m2Id = (int)$machine2->getId();
$m2LocId = (int)$machine2->getLocationId();

// 4. Identificadores de repuestos semilla
$ulkaPartId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'VALV-ULKA-01'")->fetchColumn(); // Comp: Bianchi Gaia Espresso
$bombPartId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'BOMB-VIB-02'")->fetchColumn(); // Comp: Bianchi Gaia Espresso
$sondPartId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'SOND-NTC-01'")->fetchColumn(); // Comp: Sanden Vendo
$juntPartId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'JUNT-TOR-01'")->fetchColumn(); // Comp: Bianchi Gaia Espresso
$motPartId  = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'MOT-ESP-01'")->fetchColumn();  // Comp: Sanden Vendo (Incompatible con Bianchi)

$assert("0.3 Repuestos semilla recuperados dinámicamente", $ulkaPartId > 0 && $bombPartId > 0 && $sondPartId > 0 && $juntPartId > 0 && $motPartId > 0);

// Helper para crear incidencias de prueba asignadas o no asignadas
$createTestIncident = function (int $machineId, int $locationId, ?int $assignedTechId, string $status = 'IN_PROGRESS') use ($pdo): int {
    // Limpiar incidencias previas en esta máquina para respetar la restricción única uq_machine_active_ticket
    // (purga dirigida FK-segura: incluye las hijas RESTRICT antes que `incidents`)
    TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineId);
    $pdo->exec("DELETE FROM incident_replaced_parts WHERE machine_id = {$machineId}");

    $uniqueCode = 'TICK-' . date('Ymd') . '-' . substr(uniqid(), -5);
    $stmt = $pdo->prepare("
        INSERT INTO `incidents` (
            `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
            `reporter_name`, `reporter_phone`, `category`, `description`,
            `urgency`, `status`, `started_at`, `created_at`, `updated_at`
        ) VALUES (
            :ticket_code, :machine_id, :location_id, :assigned_tech_id,
            'Operador Test', '600112233', 'ELECTRICAL_OFF', 'Avería de prueba técnica para trazabilidad de repuestos',
            'HIGH', :status, NOW(), NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':ticket_code'       => $uniqueCode,
        ':machine_id'        => $machineId,
        ':location_id'       => $locationId,
        ':assigned_tech_id'  => $assignedTechId,
        ':status'            => $status,
    ]);
    return (int)$pdo->lastInsertId();
};

// =========================================================================
// BLOQUE 1: Seguridad y RBAC en Endpoints de Técnico de Repuestos
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad y RBAC en Endpoints de Repuestos de Técnico ---\n";

// 1.1 Catálogo anónimo -> 401
$reqCatAnon = new Request('GET', "/api/technician/spare-parts/catalog?machine_id={$m2Id}");
$resCatAnon = $router->dispatch($reqCatAnon);
$assert("1.1 Catálogo anónimo retorna HTTP 401", $resCatAnon->getStatusCode() === 401);

// 1.2 Catálogo con rol COORDINATOR (no TECHNICIAN) -> 403 Forbidden
$reqCatCoord = new Request('GET', "/api/technician/spare-parts/catalog?machine_id={$m2Id}", [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resCatCoord = $router->dispatch($reqCatCoord);
$assert("1.2 Catálogo con rol distinto de TECHNICIAN retorna HTTP 403 Forbidden", $resCatCoord->getStatusCode() === 403);

// 1.3 Pausa anónima -> 401
$reqPauseAnon = new Request('PATCH', '/api/technician/incidents/1/pause');
$resPauseAnon = $router->dispatch($reqPauseAnon);
$assert("1.3 Pausa anónima retorna HTTP 401", $resPauseAnon->getStatusCode() === 401);

// 1.4 Resolución anónima -> 401
$reqResAnon = new Request('POST', '/api/technician/incidents/1/resolve');
$resResAnon = $router->dispatch($reqResAnon);
$assert("1.4 Resolución anónima retorna HTTP 401", $resResAnon->getStatusCode() === 401);

// 1.5 Incidencia no asignada al técnico autenticado -> 403 Forbidden
$unassignedIncId = $createTestIncident($m2Id, $m2LocId, null, 'IN_PROGRESS');
$reqPauseUnassigned = new Request('PATCH', "/api/technician/incidents/{$unassignedIncId}/pause", [], [
    'requested_parts' => [['spare_part_id' => $ulkaPartId, 'quantity' => 1]],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resPauseUnassigned = $router->dispatch($reqPauseUnassigned);
$assert("1.5 Pausa en incidencia ajena o no asignada retorna HTTP 403 Forbidden", $resPauseUnassigned->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: Catálogo de Repuestos en Movilidad (RF-REP-03, RNF-REP-02)
// =========================================================================
echo "\n--- BLOQUE 2: Catálogo de Repuestos en Movilidad (RF-REP-03, RNF-REP-02) ---\n";

// 2.1 Consulta sin parámetro machine_id -> 400 Bad Request
$reqNoMach = new Request('GET', '/api/technician/spare-parts/catalog', [], [], ['Authorization' => 'Bearer ' . $techToken]);
$resNoMach = $router->dispatch($reqNoMach);
$assert("2.1 Catálogo sin machine_id retorna HTTP 400", $resNoMach->getStatusCode() === 400);

// 2.2 Consulta con machine_id inexistente -> 404 Not Found
$reqNonExistMach = new Request('GET', '/api/technician/spare-parts/catalog', ['machine_id' => '999999'], [], ['Authorization' => 'Bearer ' . $techToken]);
$resNonExistMach = $router->dispatch($reqNonExistMach);
$assert("2.2 Catálogo con machine_id inexistente retorna HTTP 404", $resNonExistMach->getStatusCode() === 404);

// 2.3 Consulta para máquina VEND-0102 (Bianchi Gaia Espresso)
$startBenchmark = microtime(true);
$reqCatM2 = new Request('GET', '/api/technician/spare-parts/catalog', ['machine_id' => (string)$m2Id], [], ['Authorization' => 'Bearer ' . $techToken]);
$resCatM2 = $router->dispatch($reqCatM2);
$elapsedMs = (microtime(true) - $startBenchmark) * 1000;

$assert("2.3 Catálogo compatible retorna HTTP 200", $resCatM2->getStatusCode() === 200);
$assert("2.4 Catálogo responde en menos de 250 ms (RNF-REP-02) [{$elapsedMs} ms]", $elapsedMs < 250);

$bodyCatM2 = json_decode($resCatM2->getBody(), true);
$dataCatM2 = $bodyCatM2['data'] ?? [];
$assert("2.5 Respuesta contiene datos de la máquina (id, code, model)",
    isset($dataCatM2['machine']['id']) &&
    $dataCatM2['machine']['code'] === 'VEND-0102' &&
    $dataCatM2['machine']['model'] === 'Bianchi Gaia Espresso'
);

$partsList = $dataCatM2['compatible_parts'] ?? [];
$assert("2.6 Lista compatible_parts es un array no vacío", is_array($partsList) && count($partsList) >= 1);

// Verificar que todas las piezas son activas y compatibles
$allActive = true;
$foundUlka = false;
$foundMotIncompatible = false;
foreach ($partsList as $part) {
    if (empty($part['is_active'])) {
        $allActive = false;
    }
    if (($part['part_code'] ?? '') === 'VALV-ULKA-01') {
        $foundUlka = true;
    }
    if (($part['part_code'] ?? '') === 'MOT-ESP-01') {
        $foundMotIncompatible = true;
    }
}
$assert("2.7 Todos los repuestos devueltos están activos (is_active = true)", $allActive);
$assert("2.8 VALV-ULKA-01 figura en repuestos compatibles con Bianchi Gaia Espresso", $foundUlka);
$assert("2.9 MOT-ESP-01 (exclusivo de espirales de snacks) NO figura para Bianchi Gaia", !$foundMotIncompatible);

// 2.10 Caso Límite 4: Preservación de repuestos desactivados si fueron solicitados en una avería PENDING_PARTS
$incLimit4 = $createTestIncident($m2Id, $m2LocId, $techId, 'IN_PROGRESS');

// Pausamos la incidencia solicitando VALV-ULKA-01
$reqPauseL4 = new Request('PATCH', "/api/technician/incidents/{$incLimit4}/pause", [], [
    'requested_parts' => [['spare_part_id' => $ulkaPartId, 'quantity' => 1]],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$router->dispatch($reqPauseL4);

// Ahora el coordinador desactiva VALV-ULKA-01 en el catálogo
$pdo->exec("UPDATE spare_parts SET is_active = 0 WHERE id = {$ulkaPartId}");

// Consulta estándar de catálogo sin incident_id: VALV-ULKA-01 no debe figurar por estar inactiva
$resCatWithoutInc = $router->dispatch(new Request('GET', '/api/technician/spare-parts/catalog', ['machine_id' => (string)$m2Id], [], ['Authorization' => 'Bearer ' . $techToken]));
$partsWithoutInc = json_decode($resCatWithoutInc->getBody(), true)['data']['compatible_parts'] ?? [];
$foundDeactivatedNormal = in_array('VALV-ULKA-01', array_column($partsWithoutInc, 'part_code'), true);
$assert("2.10 Repuesto desactivado NO figura en consulta normal de catálogo", !$foundDeactivatedNormal);

// Consulta con incident_id de la incidencia en PENDING_PARTS: VALV-ULKA-01 DEBE ser preservada (Caso Límite 4)
$resCatWithInc = $router->dispatch(new Request('GET', '/api/technician/spare-parts/catalog', [
    'machine_id'  => (string)$m2Id,
    'incident_id' => (string)$incLimit4,
], [], ['Authorization' => 'Bearer ' . $techToken]));
$partsWithInc = json_decode($resCatWithInc->getBody(), true)['data']['compatible_parts'] ?? [];
$foundDeactivatedPreserved = in_array('VALV-ULKA-01', array_column($partsWithInc, 'part_code'), true);
$assert("2.11 Repuesto desactivado SÍ figura al incluir incident_id en PENDING_PARTS (Caso Límite 4)", $foundDeactivatedPreserved);

// Restaurar repuesto a activo para siguientes pruebas
$pdo->exec("UPDATE spare_parts SET is_active = 1 WHERE id = {$ulkaPartId}");

// =========================================================================
// BLOQUE 3: Pausa Técnica Estructurada (RF-REP-03, RF-REP-04)
// =========================================================================
echo "\n--- BLOQUE 3: Pausa Técnica Estructurada (RF-REP-03, RF-REP-04) ---\n";

// 3.1 Intento de pausa en estado ASSIGNED (no está en IN_PROGRESS) -> 422
$incAssigned = $createTestIncident($m2Id, $m2LocId, $techId, 'ASSIGNED');
$reqPauseAssigned = new Request('PATCH', "/api/technician/incidents/{$incAssigned}/pause", [], [
    'requested_parts' => [['spare_part_id' => $ulkaPartId, 'quantity' => 1]],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resPauseAssigned = $router->dispatch($reqPauseAssigned);
$assert("3.1 Pausar incidencia que no está en IN_PROGRESS retorna HTTP 422", $resPauseAssigned->getStatusCode() === 422);

// Incidencia en IN_PROGRESS para validaciones de pausa sobre VEND-0102
$testInc1 = $createTestIncident($m2Id, $m2LocId, $techId, 'IN_PROGRESS');

// 3.2 Repuesto incompatible con el modelo de la máquina -> 422 INCOMPATIBLE_SPARE_PART
$reqIncompat = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [['spare_part_id' => $motPartId, 'quantity' => 1]], // MOT-ESP-01 es incompatible con Bianchi Gaia
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resIncompat = $router->dispatch($reqIncompat);
$assert("3.2 Repuesto incompatible con el modelo retorna HTTP 422", $resIncompat->getStatusCode() === 422);
$errIncompat = json_decode($resIncompat->getBody(), true)['error']['code'] ?? '';
$assert("3.3 Código de error es INCOMPATIBLE_SPARE_PART", $errIncompat === 'INCOMPATIBLE_SPARE_PART');

// 3.4 Cantidad inválida (0 o 51) -> 422 INVALID_PART_QUANTITY
$reqZeroQty = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [['spare_part_id' => $ulkaPartId, 'quantity' => 0]],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resZeroQty = $router->dispatch($reqZeroQty);
$assert("3.4 Cantidad cero de repuesto retorna HTTP 422", $resZeroQty->getStatusCode() === 422);

$reqExcessQty = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [['spare_part_id' => $ulkaPartId, 'quantity' => 51]],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resExcessQty = $router->dispatch($reqExcessQty);
$assert("3.5 Cantidad superior a 50 retorna HTTP 422", $resExcessQty->getStatusCode() === 422);

// 3.6 Petición sin repuestos y sin fuera de catálogo -> 422 MISSING_PARTS_REQUEST
$reqEmptyParts = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resEmptyParts = $router->dispatch($reqEmptyParts);
$assert("3.6 Array de repuestos vacío con is_out_of_catalog=false retorna HTTP 422", $resEmptyParts->getStatusCode() === 422);

// 3.7 Fuera de catálogo con justificación menor a 20 caracteres -> 422 INVALID_OUT_OF_CATALOG_JUSTIFICATION
$reqShortJustif = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [],
    'is_out_of_catalog' => true,
    'custom_part_description' => 'Tubo corto roto', // 15 chars (< 20)
], ['Authorization' => 'Bearer ' . $techToken]);
$resShortJustif = $router->dispatch($reqShortJustif);
$assert("3.7 Justificación de pieza fuera de catálogo < 20 caracteres retorna HTTP 422", $resShortJustif->getStatusCode() === 422);
$errShort = json_decode($resShortJustif->getBody(), true)['error']['code'] ?? '';
$assert("3.8 Código de error es INVALID_OUT_OF_CATALOG_JUSTIFICATION", $errShort === 'INVALID_OUT_OF_CATALOG_JUSTIFICATION');

// 3.9 Pausa técnica exitosa con repuestos catalogados compatibles (VALV-ULKA-01 y BOMB-VIB-02 en Bianchi)
$reqPauseOk = new Request('PATCH', "/api/technician/incidents/{$testInc1}/pause", [], [
    'requested_parts' => [
        ['spare_part_id' => $ulkaPartId, 'quantity' => 2],
        ['spare_part_id' => $bombPartId, 'quantity' => 1],
    ],
    'is_out_of_catalog' => false,
], ['Authorization' => 'Bearer ' . $techToken]);
$resPauseOk = $router->dispatch($reqPauseOk);

$assert("3.9 Pausa estructurada con piezas de catálogo retorna HTTP 200", $resPauseOk->getStatusCode() === 200);
$bodyPauseOk = json_decode($resPauseOk->getBody(), true);
$dataPauseOk = $bodyPauseOk['data'] ?? [];
$assert("3.10 Incidencia pasa al estado PENDING_PARTS", ($dataPauseOk['status'] ?? '') === 'PENDING_PARTS');
$assert("3.11 Respuesta incluye lista de solicitudes generadas (2 solicitudes)", count($dataPauseOk['requests'] ?? []) === 2);

// Verificar persistencia en base de datos MariaDB
$dbRequests = $pdo->query("SELECT * FROM spare_part_requests WHERE incident_id = {$testInc1}")->fetchAll(PDO::FETCH_ASSOC);
$assert("3.12 Fila física creada en spare_part_requests con status PENDING", count($dbRequests) === 2 && $dbRequests[0]['status'] === 'PENDING');
$assert("3.13 Solicitud guarda requested_by_user_id correspondiente al técnico", (int)$dbRequests[0]['requested_by_user_id'] === $techId);

// 3.14 Pausa técnica exitosa con repuesto FUERA DE CATÁLOGO (RF-REP-04) sobre VEND-0101
$testInc2 = $createTestIncident($m1Id, $m1LocId, $techId, 'IN_PROGRESS');
$longJustification = 'Sensor infrarrojo reflectante para detección de producto en bandeja inferior modelo especial';
$reqOutOfCat = new Request('PATCH', "/api/technician/incidents/{$testInc2}/pause", [], [
    'requested_parts' => [],
    'is_out_of_catalog' => true,
    'custom_part_description' => $longJustification,
], ['Authorization' => 'Bearer ' . $techToken]);
$resOutOfCat = $router->dispatch($reqOutOfCat);

$assert("3.14 Pausa estructurada fuera de catálogo retorna HTTP 200", $resOutOfCat->getStatusCode() === 200);
$dbOutOfCat = $pdo->query("SELECT * FROM spare_part_requests WHERE incident_id = {$testInc2} AND is_out_of_catalog = 1")->fetch(PDO::FETCH_ASSOC);
$assert("3.15 Solicitud fuera de catálogo persistida con is_out_of_catalog=1", $dbOutOfCat !== false && (int)$dbOutOfCat['is_out_of_catalog'] === 1);
$assert("3.16 Justificación técnica íntegramente guardada en custom_part_description", ($dbOutOfCat['custom_part_description'] ?? '') === $longJustification);

// =========================================================================
// BLOQUE 4: Resolución con Declaración de Piezas y Congelación de Costes (RF-REP-05, RF-REP-06, RF-REP-07)
// =========================================================================
echo "\n--- BLOQUE 4: Resolución con Declaración de Piezas y Congelación de Costes ---\n";

// 4.1 Intento de resolver directamente una incidencia en PENDING_PARTS (sin reanudar previamente a IN_PROGRESS)
$reqResPending = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => 'Diagnóstico detallado de prueba técnica con más de veinte caracteres',
    'resolution_action' => 'Acción técnica detallada de prueba con más de veinte caracteres reglamentarios',
    'replaced_parts_declared' => false,
    'replaced_parts' => [],
], ['Authorization' => 'Bearer ' . $techToken]);
$resResPending = $router->dispatch($reqResPending);
$assert("4.1 Intentar resolver incidencia en PENDING_PARTS sin reanudar retorna HTTP 422", $resResPending->getStatusCode() === 422);

// Reanudar la incidencia a IN_PROGRESS vía startIntervention
$reqResume = new Request('PATCH', "/api/technician/incidents/{$testInc1}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
$resResume = $router->dispatch($reqResume);
$assert("4.2 Reanudar trabajos (startIntervention) retorna HTTP 200", $resResume->getStatusCode() === 200);
$assert("4.3 Incidencia reanudada pasa a IN_PROGRESS", json_decode($resResume->getBody(), true)['data']['status'] === 'IN_PROGRESS');

// 4.4 Validación de textos reglamentarios (< 20 caracteres -> 422)
$reqShortDiag = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => 'Bomba rota', // 10 chars
    'resolution_action' => 'Acción técnica detallada de prueba con más de veinte caracteres reglamentarios',
    'replaced_parts_declared' => false,
    'replaced_parts' => [],
], ['Authorization' => 'Bearer ' . $techToken]);
$resShortDiag = $router->dispatch($reqShortDiag);
$assert("4.4 Diagnóstico menor a 20 caracteres retorna HTTP 422 INVALID_RESOLUTION", $resShortDiag->getStatusCode() === 422);

// 4.5 Falta declaración de sustitución de componentes (campo replaced_parts_declared) -> 422 PARTS_RECORD_REQUIRED
$validDiag = 'Electroválvula de entrada con cal y bloqueo electromagnético de solenoide';
$validAct  = 'Desmontaje de grupo electroválvula, sustitución por recambio y purga a 2.5 bar';
$reqNoDecl = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => $validDiag,
    'resolution_action' => $validAct,
    'replaced_parts' => [
        [
            'spare_part_id' => $ulkaPartId,
            'quantity' => 1,
            'old_part_destination' => 'DESGUACE',
        ]
    ],
], ['Authorization' => 'Bearer ' . $techToken]);
$resNoDecl = $router->dispatch($reqNoDecl);
$assert("4.5 Ausencia de declaración de sustitución retorna HTTP 422", $resNoDecl->getStatusCode() === 422);
$errNoDecl = json_decode($resNoDecl->getBody(), true)['error']['code'] ?? '';
$assert("4.5b Código de error es PARTS_RECORD_REQUIRED", $errNoDecl === 'PARTS_RECORD_REQUIRED');

// 4.6 Declaración afirmativa con lista vacía -> 422 EMPTY_REPLACED_PARTS_LIST
$reqEmptyDecl = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => $validDiag,
    'resolution_action' => $validAct,
    'replaced_parts_declared' => true,
    'replaced_parts' => [],
], ['Authorization' => 'Bearer ' . $techToken]);
$resEmptyDecl = $router->dispatch($reqEmptyDecl);
$assert("4.6 replaced_parts_declared=true con lista vacía retorna HTTP 422", $resEmptyDecl->getStatusCode() === 422);

// 4.7 Destino de pieza inválido (distinto de DESGUACE o TALLER) -> 422 INVALID_PART_DESTINATION
$reqBadDest = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => $validDiag,
    'resolution_action' => $validAct,
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        [
            'spare_part_id' => $ulkaPartId,
            'quantity' => 1,
            'old_part_destination' => 'CONTENEDOR_BASURA',
        ]
    ],
], ['Authorization' => 'Bearer ' . $techToken]);
$resBadDest = $router->dispatch($reqBadDest);
$assert("4.7 Destino no reglamentario de pieza retirada retorna HTTP 422", $resBadDest->getStatusCode() === 422);

// 4.8 Cantidad fuera de rango en resolución -> 422
$reqBadQtyRes = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => $validDiag,
    'resolution_action' => $validAct,
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        [
            'spare_part_id' => $ulkaPartId,
            'quantity' => 0,
            'old_part_destination' => 'DESGUACE',
        ]
    ],
], ['Authorization' => 'Bearer ' . $techToken]);
$resBadQtyRes = $router->dispatch($reqBadQtyRes);
$assert("4.8 Cantidad 0 en pieza sustituida retorna HTTP 422", $resBadQtyRes->getStatusCode() === 422);

// 4.9 Resolución exitosa CON sustitución de piezas (1 catalogada + 1 fuera de catálogo)
$reqResolvePartsOk = new Request('POST', "/api/technician/incidents/{$testInc1}/resolve", [], [
    'resolution_diagnosis' => $validDiag,
    'resolution_action' => $validAct,
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        [
            'spare_part_id' => $ulkaPartId,
            'is_out_of_catalog' => false,
            'custom_part_name' => null,
            'quantity' => 2,
            'old_part_destination' => 'DESGUACE',
            'notes' => 'Bobinas eléctricas quemadas por sobretensión',
        ],
        [
            'spare_part_id' => null,
            'is_out_of_catalog' => true,
            'custom_part_name' => 'Abrazadera especial de apriete rápido 16mm',
            'quantity' => 1,
            'old_part_destination' => 'TALLER',
            'notes' => 'Para revisión y calibración en taller central',
        ]
    ],
], ['Authorization' => 'Bearer ' . $techToken]);
$resResolvePartsOk = $router->dispatch($reqResolvePartsOk);

$assert("4.9 Resolución con repuestos retorna HTTP 200", $resResolvePartsOk->getStatusCode() === 200);
$bodyResolve = json_decode($resResolvePartsOk->getBody(), true);
$dataResolve = $bodyResolve['data'] ?? [];

$assert("4.10 Incidencia pasa al estado RESOLVED", ($dataResolve['status'] ?? '') === 'RESOLVED');
$assert("4.11 Conteo de piezas sustituidas es 2", ($dataResolve['replaced_parts_count'] ?? 0) === 2);
// 2 unidades de VALV-ULKA-01 a 28.50 € = 57.00 €, fuera de catálogo a 0.00 €
$assert("4.12 Coste total calculado es 57.00 €", (float)($dataResolve['total_parts_cost'] ?? 0) === 57.00);

// Verificación en base de datos MariaDB
$dbReplaced = $pdo->query("SELECT * FROM incident_replaced_parts WHERE incident_id = {$testInc1} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$assert("4.13 Se insertaron exactamente 2 filas físicas en incident_replaced_parts", count($dbReplaced) === 2);

$rowCatalog = $dbReplaced[0] ?? [];
$assert("4.14 Pieza catalogada tiene intervention_type = INCIDENT", ($rowCatalog['intervention_type'] ?? '') === 'INCIDENT');
$assert("4.15 Snapshot unitario congelado a 28.50 € (Art. III)", (float)($rowCatalog['unit_cost_snapshot'] ?? 0) === 28.50);
$assert("4.16 Snapshot total calculado en 57.00 €", (float)($rowCatalog['total_cost_snapshot'] ?? 0) === 57.00);
$assert("4.17 Destino DESGUACE correctamente almacenado", ($rowCatalog['old_part_destination'] ?? '') === 'DESGUACE');

$rowOutOfCat = $dbReplaced[1] ?? [];
$assert("4.18 Pieza fuera de catálogo guardada con is_out_of_catalog = 1", (int)($rowOutOfCat['is_out_of_catalog'] ?? 0) === 1);
$assert("4.19 Coste snapshot fijado en 0.00 € para pieza no catalogada", (float)($rowOutOfCat['unit_cost_snapshot'] ?? 0) === 0.00);
$assert("4.20 Destino TALLER correctamente almacenado", ($rowOutOfCat['old_part_destination'] ?? '') === 'TALLER');

// Verificar que las solicitudes pendientes previas de esta incidencia pasaron a ATTENDED (RF-REP-06)
$pendingCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM spare_part_requests WHERE incident_id = {$testInc1} AND status = 'PENDING'")->fetchColumn();
$attendedCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM spare_part_requests WHERE incident_id = {$testInc1} AND status = 'ATTENDED'")->fetchColumn();
$assert("4.21 Solicitudes previas de la incidencia actualizadas a ATTENDED", $pendingCountAfter === 0 && $attendedCountAfter === 2);

// 4.22 Prueba de inmutabilidad del snapshot de costes (Constitución Art. III / RF-REP-07)
// Modificamos el precio de referencia en la tabla maestra spare_parts a 99.99 €
$pdo->exec("UPDATE spare_parts SET reference_cost = 99.99 WHERE id = {$ulkaPartId}");
$refetchSnapshot = (float)$pdo->query("SELECT unit_cost_snapshot FROM incident_replaced_parts WHERE id = {$rowCatalog['id']}")->fetchColumn();
$assert("4.22 Inmutabilidad de snapshot: unit_cost_snapshot sigue en 28.50 tras subir el precio maestro", $refetchSnapshot === 28.50);
// Restaurar precio maestro original
$pdo->exec("UPDATE spare_parts SET reference_cost = 28.50 WHERE id = {$ulkaPartId}");

// 4.23 Resolución exitosa SIN sustitución de piezas (Caso Límite 3 / RF-REP-05)
// Usamos $testInc2 (que tenía una solicitud fuera de catálogo en PENDING_PARTS)
// Reanudamos a IN_PROGRESS
$router->dispatch(new Request('PATCH', "/api/technician/incidents/{$testInc2}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]));

$reqResolveNoParts = new Request('POST', "/api/technician/incidents/{$testInc2}/resolve", [], [
    'resolution_diagnosis' => 'Falsa alarma o desajuste temporal de sensor que no requirió cambio de material',
    'resolution_action' => 'Limpieza superficial de ópticas, recalibración electrónica de sensibilidad y verificación',
    'replaced_parts_declared' => false,
    'replaced_parts' => [],
], ['Authorization' => 'Bearer ' . $techToken]);
$resResolveNoParts = $router->dispatch($reqResolveNoParts);

$assert("4.23 Resolución sin piezas retorna HTTP 200", $resResolveNoParts->getStatusCode() === 200);
$bodyNoParts = json_decode($resResolveNoParts->getBody(), true);
$assert("4.24 replaced_parts_count es 0 y total_parts_cost es 0.00",
    ($bodyNoParts['data']['replaced_parts_count'] ?? -1) === 0 &&
    (float)($bodyNoParts['data']['total_parts_cost'] ?? -1) === 0.00
);

// Verificar que las solicitudes pendientes previas de $testInc2 pasaron a CANCELLED (Caso Límite 3)
$cancelledCount = (int)$pdo->query("SELECT COUNT(*) FROM spare_part_requests WHERE incident_id = {$testInc2} AND status = 'CANCELLED'")->fetchColumn();
$assert("4.25 Solicitudes pendientes de la incidencia pasan a CANCELLED al resolver sin piezas", $cancelledCount === 1);

// =========================================================================
// BLOQUE 5: Registro de Piezas en Mantenimiento Preventivo (RF-REP-07)
// =========================================================================
echo "\n--- BLOQUE 5: Registro de Piezas en Inspección Preventiva (RF-REP-07) ---\n";

// Crear orden preventiva para VEND-0102 (Bianchi Gaia Espresso)
$reqCreateOrder = new Request('POST', '/api/coordinator/preventive/orders', [], [
    'machine_id'     => $m2Id,
    'order_type'     => 'ROUTINE',
    'scheduled_date' => date('Y-m-d'),
    'due_date'       => date('Y-m-d', strtotime('+5 days')),
    'notes'          => 'Inspección de prueba para repuestos en preventivo.',
], ['Authorization' => 'Bearer ' . $coordToken]);
$resCreateOrder = $router->dispatch($reqCreateOrder);
$prevOrderId = json_decode($resCreateOrder->getBody(), true)['data']['id'] ?? null;
$assert("5.1 Creación de orden preventiva para VEND-0102 retorna 201", is_numeric($prevOrderId));

if ($prevOrderId !== null) {
    // 5.2 Claim de orden
    $reqClaim = new Request('POST', "/api/technician/preventive/orders/{$prevOrderId}/claim", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resClaim = $router->dispatch($reqClaim);
    $assert("5.2 Claim de orden preventiva retorna HTTP 200", $resClaim->getStatusCode() === 200);

    // 5.3 Iniciar inspección in situ
    $reqStartPrev = new Request('POST', "/api/technician/preventive/orders/{$prevOrderId}/start", [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resStartPrev = $router->dispatch($reqStartPrev);
    $assert("5.3 Iniciar inspección preventiva retorna HTTP 200", $resStartPrev->getStatusCode() === 200);

    // Obtener checklist para ítems normativos
    $resChecklist = $router->dispatch(new Request('GET', "/api/technician/preventive/orders/{$prevOrderId}/checklist", [], [], ['Authorization' => 'Bearer ' . $techToken]));
    $checklistItems = json_decode($resChecklist->getBody(), true)['data']['checklist_items'] ?? [];
    $itemsPayload = [];
    foreach ($checklistItems as $it) {
        $itemsPayload[] = [
            'item_code'    => $it['item_code'],
            'status'       => 'PASS',
            'observations' => null,
        ];
    }

    // 5.4 Completar inspección con sustitución sistemática de Kit de Juntas Tóricas (JUNT-TOR-01, 8.50 €)
    $reqCompletePrev = new Request('POST', "/api/technician/preventive/orders/{$prevOrderId}/complete", [], [
        'temperature_measured' => null,
        'items' => $itemsPayload,
        'general_notes' => 'Inspección periódica completada con sustitución de juntas de infusión.',
        'replaced_parts_declared' => true,
        'replaced_parts' => [
            [
                'spare_part_id' => $juntPartId,
                'quantity' => 1,
                'old_part_destination' => 'DESGUACE',
                'notes' => 'Cambio preventivo programado de kit de juntas tóricas',
            ]
        ],
    ], ['Authorization' => 'Bearer ' . $techToken]);
    $resCompletePrev = $router->dispatch($reqCompletePrev);

    $assert("5.4 Completar preventivo con repuestos retorna HTTP 200", $resCompletePrev->getStatusCode() === 200);
    $bodyPrev = json_decode($resCompletePrev->getBody(), true);
    $dataPrev = $bodyPrev['data'] ?? [];

    $assert("5.5 Dictamen de inspección es CONFORME", ($dataPrev['result'] ?? '') === 'CONFORME');
    $assert("5.6 Conteo de repuestos en respuesta preventiva es 1", ($dataPrev['replaced_parts_count'] ?? 0) === 1);
    $assert("5.7 Coste total de piezas en preventivo es 8.50 €", (float)($dataPrev['total_parts_cost'] ?? 0) === 8.50);

    // Verificación en MariaDB
    $dbPrevReplaced = $pdo->query("SELECT * FROM incident_replaced_parts WHERE preventive_order_id = {$prevOrderId}")->fetch(PDO::FETCH_ASSOC);
    $assert("5.8 Fila física creada en incident_replaced_parts con intervention_type = PREVENTIVE",
        $dbPrevReplaced !== false && ($dbPrevReplaced['intervention_type'] ?? '') === 'PREVENTIVE'
    );
    $assert("5.9 Coste unitario congelado en 8.50 € y destino DESGUACE",
        (float)($dbPrevReplaced['unit_cost_snapshot'] ?? 0) === 8.50 &&
        ($dbPrevReplaced['old_part_destination'] ?? '') === 'DESGUACE'
    );
}

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas pasaron con éxito ({$assertions} aserciones)! (0 fallos)\n";
} else {
    echo " RESULTADO: {$failures} prueba(s) fallaron de {$assertions} aserciones.\n";
    exit(1);
}
echo "======================================================================\n";
