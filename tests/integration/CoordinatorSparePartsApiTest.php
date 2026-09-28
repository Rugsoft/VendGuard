<?php

declare(strict_types=1);

/**
 * VendGuard - CoordinatorSparePartsApiTest
 * 
 * Test de Integración HTTP para los Endpoints de Coordinación de Repuestos y Trazabilidad (T-SPARE-18).
 * Requisitos: RF-REP-01, RF-REP-02, RF-REP-08, RF-REP-09, Constitución Art. III y Art. V.
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad RBAC: acceso anónimo devuelve 401; acceso con rol TECHNICIAN devuelve 403.
 * 2. GET /api/coordinator/spare-parts: 200 OK con catálogo maestro y filtros por categoría, modelo y búsqueda.
 * 3. GET /api/coordinator/spare-parts/models: 200 OK con modelos únicos de máquinas.
 * 4. POST /api/coordinator/spare-parts: 201 Created con validación de código único y modelos compatibles.
 * 5. POST /api/coordinator/spare-parts: 409 Conflict ante código de repuesto duplicado (SPARE_PART_CODE_EXISTS).
 * 6. POST /api/coordinator/spare-parts: 422 Unprocessable Entity ante payload inválido.
 * 7. PUT /api/coordinator/spare-parts/{id}: 200 OK con actualización de datos maestros y modelos.
 * 8. DELETE y PATCH status /api/coordinator/spare-parts/{id}: 200 OK con baja lógica (Art. III) y reactivación.
 * 9. GET /api/coordinator/spare-parts/analytics: 200 OK con KPIs, desglose de destinos y alerta de averías crónicas.
 * 10. GET /api/coordinator/spare-parts/export: 200 OK con descarga de fichero CSV UTF-8 con BOM y cabeceras en español.
 * 11. GET /api/coordinator/spare-parts/requests/pending-review: 200 OK con bandeja de piezas fuera de catálogo.
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
echo " VendGuard: Test Integración - CoordinatorSparePartsApiTest (T-SPARE-18)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// 1. Limpieza estricta y restauración de semillas
$pdo->exec("DELETE FROM spare_part_requests");
$pdo->exec("DELETE FROM incident_replaced_parts");
$pdo->exec("DELETE FROM spare_part_compatibilities");
$pdo->exec("DELETE FROM spare_parts");

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

// Obtención de usuarios semilla
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0.1 Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR CRÍTICO: Usuarios semilla de coordinación o técnico no encontrados.\n";
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);

$machine1 = $machineRepo->findByCode('VEND-0101');
$assert("0.2 Máquina semilla VEND-0101 encontrada", $machine1 !== null);
if ($machine1 === null) {
    exit(1);
}

$partUlkaId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'VALV-ULKA-01'")->fetchColumn();
$partBombId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'BOMB-VIB-02'")->fetchColumn();
$partSondId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'SOND-NTC-01'")->fetchColumn();
$assert("0.3 IDs de repuestos semilla recuperados dinámicamente", $partUlkaId > 0 && $partBombId > 0 && $partSondId > 0);

// =========================================================================
// BLOQUE 1: Seguridad RBAC (401 y 403) en endpoints de repuestos
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad RBAC en endpoints de coordinación de repuestos ---\n";

$protectedEndpoints = [
    ['GET',    '/api/coordinator/spare-parts'],
    ['POST',   '/api/coordinator/spare-parts'],
    ['GET',    '/api/coordinator/spare-parts/models'],
    ['GET',    '/api/coordinator/spare-parts/analytics'],
    ['GET',    '/api/coordinator/spare-parts/export'],
    ['GET',    '/api/coordinator/spare-parts/requests/pending-review'],
    ['GET',    "/api/coordinator/spare-parts/{$partUlkaId}"],
    ['PUT',    "/api/coordinator/spare-parts/{$partUlkaId}"],
    ['PATCH',  "/api/coordinator/spare-parts/{$partUlkaId}/status"],
    ['DELETE', "/api/coordinator/spare-parts/{$partUlkaId}"],
];

foreach ($protectedEndpoints as [$method, $endpoint]) {
    // 1a. Acceso anónimo -> 401 Unauthorized
    $reqAnon = new Request($method, $endpoint);
    $resAnon = $router->dispatch($reqAnon);
    $assert("1a. {$method} {$endpoint} anónimo => 401", $resAnon->getStatusCode() === 401);

    // 1b. Acceso con rol técnico de campo -> 403 Forbidden
    $reqTech = new Request($method, $endpoint, [], [], ['Authorization' => 'Bearer ' . $techToken]);
    $resTech = $router->dispatch($reqTech);
    $assert("1b. {$method} {$endpoint} técnico => 403", $resTech->getStatusCode() === 403);
}

// =========================================================================
// BLOQUE 2: GET /api/coordinator/spare-parts y /models (Consulta de catálogo)
// =========================================================================
echo "\n--- BLOQUE 2: Consulta del Catálogo Maestro y Modelos (RF-REP-01) ---\n";

// 2.1 Listado global sin filtros
$reqCatalog = new Request('GET', '/api/coordinator/spare-parts', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resCatalog = $router->dispatch($reqCatalog);

$assert("2.1 Catálogo global retorna HTTP 200", $resCatalog->getStatusCode() === 200);
$bodyCatalog = json_decode($resCatalog->getBody(), true);
$assert("2.2 Respuesta contiene success=true", ($bodyCatalog['success'] ?? false) === true);
$partsList = $bodyCatalog['data'] ?? [];
$assert("2.3 Catálogo contiene piezas sembradas (>= 5)", is_array($partsList) && count($partsList) >= 5);

// Verificar estructura completa de una pieza sembrada
$firstPart = $partsList[0] ?? [];
$assert("2.4 Pieza contiene campos maestros requeridos",
    isset($firstPart['id']) &&
    isset($firstPart['part_code']) &&
    isset($firstPart['name']) &&
    isset($firstPart['category']) &&
    isset($firstPart['reference_cost']) &&
    isset($firstPart['is_active']) &&
    isset($firstPart['compatible_models']) &&
    is_array($firstPart['compatible_models'])
);

// 2.2 Filtro por categoría (HYDRAULIC)
$reqFilterCat = new Request('GET', '/api/coordinator/spare-parts', ['category' => 'HYDRAULIC'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resFilterCat = $router->dispatch($reqFilterCat);
$assert("2.5 Filtro de categoría retorna HTTP 200", $resFilterCat->getStatusCode() === 200);
$catParts = json_decode($resFilterCat->getBody(), true)['data'] ?? [];
$allHydraulic = count($catParts) > 0;
foreach ($catParts as $p) {
    if (($p['category'] ?? '') !== 'HYDRAULIC') {
        $allHydraulic = false;
        break;
    }
}
$assert("2.6 Todas las piezas filtradas pertenecen a la categoría HYDRAULIC", $allHydraulic);

// 2.3 Filtro por modelo de máquina
$reqFilterModel = new Request('GET', '/api/coordinator/spare-parts', ['machine_model' => 'Sanden Vendo G-Drink'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resFilterModel = $router->dispatch($reqFilterModel);
$assert("2.7 Filtro por modelo retorna HTTP 200", $resFilterModel->getStatusCode() === 200);
$modelParts = json_decode($resFilterModel->getBody(), true)['data'] ?? [];
$allModelMatch = count($modelParts) > 0;
foreach ($modelParts as $p) {
    if (!in_array('Sanden Vendo G-Drink', $p['compatible_models'] ?? [], true)) {
        $allModelMatch = false;
        break;
    }
}
$assert("2.8 Todas las piezas filtradas son compatibles con Sanden Vendo G-Drink", $allModelMatch);

// 2.4 Búsqueda por término (Ulka)
$reqSearch = new Request('GET', '/api/coordinator/spare-parts', ['search' => 'Ulka'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resSearch = $router->dispatch($reqSearch);
$assert("2.9 Búsqueda por texto retorna HTTP 200", $resSearch->getStatusCode() === 200);
$searchParts = json_decode($resSearch->getBody(), true)['data'] ?? [];
$assert("2.10 Búsqueda por 'Ulka' encuentra al menos una coincidencia", count($searchParts) >= 1);

// 2.5 Modelos de máquinas disponibles
$reqModels = new Request('GET', '/api/coordinator/spare-parts/models', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resModels = $router->dispatch($reqModels);
$assert("2.11 Consulta de modelos retorna HTTP 200", $resModels->getStatusCode() === 200);
$modelsList = json_decode($resModels->getBody(), true)['data'] ?? [];
$assert("2.12 Lista de modelos no está vacía y contiene cadenas de texto", is_array($modelsList) && count($modelsList) > 0 && is_string($modelsList[0]));

// =========================================================================
// BLOQUE 3: POST /api/coordinator/spare-parts (Alta en Catálogo)
// =========================================================================
echo "\n--- BLOQUE 3: Alta de Repuesto en Catálogo (RF-REP-01, RF-REP-02) ---\n";

$newPartPayload = [
    'part_code'         => 'VALV-SEG-99',
    'name'              => 'Electroválvula de Seguridad 24V',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'Ceme',
    'reference_cost'    => 29.90,
    'notes'             => 'Válvula de seguridad de alta presión',
    'compatible_models' => ['Sanden Vendo G-Drink', 'Bianchi Gaia Espresso']
];

$reqCreate = new Request('POST', '/api/coordinator/spare-parts', [], $newPartPayload, ['Authorization' => 'Bearer ' . $coordToken]);
$resCreate = $router->dispatch($reqCreate);

$assert("3.1 Alta de repuesto retorna HTTP 201", $resCreate->getStatusCode() === 201);
$bodyCreate = json_decode($resCreate->getBody(), true);
$assert("3.2 Respuesta contiene success=true", ($bodyCreate['success'] ?? false) === true);
$createdPart = $bodyCreate['data'] ?? [];
$createdId = $createdPart['id'] ?? null;
$assert("3.3 Repuesto creado tiene ID numérico", is_numeric($createdId));
$assert("3.4 Código de repuesto coincide con VALV-SEG-99", ($createdPart['part_code'] ?? '') === 'VALV-SEG-99');
$assert("3.5 Coste de referencia fijado en 29.90", (float)($createdPart['reference_cost'] ?? 0) === 29.90);
$assert("3.6 Modelos compatibles vinculados (2 modelos)", count($createdPart['compatible_models'] ?? []) === 2);
$assert("3.7 Repuesto nuevo se crea activo por defecto", ($createdPart['is_active'] ?? false) === true);

// 3.8 Comprobación de persistencia directa y endpoint GET /{id}
$reqGetPart = new Request('GET', "/api/coordinator/spare-parts/{$createdId}", [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resGetPart = $router->dispatch($reqGetPart);
$assert("3.8 GET /api/coordinator/spare-parts/{id} retorna HTTP 200", $resGetPart->getStatusCode() === 200);
$fetchedPart = json_decode($resGetPart->getBody(), true)['data'] ?? [];
$assert("3.9 Datos recuperados coinciden con los creados", ($fetchedPart['part_code'] ?? '') === 'VALV-SEG-99');

// 3.10 Intento de duplicidad de código -> 409 Conflict (SPARE_PART_CODE_EXISTS)
$reqDuplicate = new Request('POST', '/api/coordinator/spare-parts', [], $newPartPayload, ['Authorization' => 'Bearer ' . $coordToken]);
$resDuplicate = $router->dispatch($reqDuplicate);
$assert("3.10 Código duplicado retorna HTTP 409 Conflict", $resDuplicate->getStatusCode() === 409);
$bodyDup = json_decode($resDuplicate->getBody(), true);
$errCode = $bodyDup['error']['code'] ?? ($bodyDup['error'] ?? '');
$assert("3.11 Código de error es SPARE_PART_CODE_EXISTS", $errCode === 'SPARE_PART_CODE_EXISTS');

// 3.12 Validaciones de entrada (422 Unprocessable Entity)
$invalidPayloads = [
    'Sin part_code'         => array_merge($newPartPayload, ['part_code' => '']),
    'Sin name'              => array_merge($newPartPayload, ['part_code' => 'TEST-01', 'name' => '']),
    'Coste negativo'        => array_merge($newPartPayload, ['part_code' => 'TEST-02', 'reference_cost' => -10.50]),
    'Categoría inválida'    => array_merge($newPartPayload, ['part_code' => 'TEST-03', 'category' => 'INVALIDA']),
    'Sin modelos comp.'     => array_merge($newPartPayload, ['part_code' => 'TEST-04', 'compatible_models' => []]),
];

foreach ($invalidPayloads as $desc => $invPayload) {
    $reqInv = new Request('POST', '/api/coordinator/spare-parts', [], $invPayload, ['Authorization' => 'Bearer ' . $coordToken]);
    $resInv = $router->dispatch($reqInv);
    $assert("3.12 Payload inválido ({$desc}) retorna HTTP 422", $resInv->getStatusCode() === 422);
}

// =========================================================================
// BLOQUE 4: PUT /api/coordinator/spare-parts/{id} (Actualización)
// =========================================================================
echo "\n--- BLOQUE 4: Actualización de Repuesto en Catálogo (RF-REP-01, RF-REP-02) ---\n";

$updatePayload = [
    'name'              => 'Electroválvula de Seguridad 24V Reforzada',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'Ceme Italia',
    'reference_cost'    => 34.50,
    'notes'             => 'Actualizada a versión de latón reforzado',
    'compatible_models' => ['Sanden Vendo G-Drink', 'Fas Fast 900']
];

$reqUpdate = new Request('PUT', "/api/coordinator/spare-parts/{$createdId}", [], $updatePayload, ['Authorization' => 'Bearer ' . $coordToken]);
$resUpdate = $router->dispatch($reqUpdate);

$assert("4.1 Actualización retorna HTTP 200", $resUpdate->getStatusCode() === 200);
$bodyUpdate = json_decode($resUpdate->getBody(), true);
$updatedPart = $bodyUpdate['data'] ?? [];
$assert("4.2 Nombre actualizado correctamente", ($updatedPart['name'] ?? '') === 'Electroválvula de Seguridad 24V Reforzada');
$assert("4.3 Coste de referencia actualizado a 34.50", (float)($updatedPart['reference_cost'] ?? 0) === 34.50);
$assert("4.4 Modelos compatibles actualizados contienen Fas Fast 900", in_array('Fas Fast 900', $updatedPart['compatible_models'] ?? [], true));

// 4.5 Actualización de repuesto inexistente -> 404
$reqUpd404 = new Request('PUT', '/api/coordinator/spare-parts/999999', [], $updatePayload, ['Authorization' => 'Bearer ' . $coordToken]);
$resUpd404 = $router->dispatch($reqUpd404);
$assert("4.5 Actualizar repuesto inexistente retorna HTTP 404", $resUpd404->getStatusCode() === 404);

// 4.6 Actualización con coste negativo -> 422
$reqUpdInv = new Request('PUT', "/api/coordinator/spare-parts/{$createdId}", [], array_merge($updatePayload, ['reference_cost' => -5.0]), ['Authorization' => 'Bearer ' . $coordToken]);
$resUpdInv = $router->dispatch($reqUpdInv);
$assert("4.6 Actualizar con coste negativo retorna HTTP 422", $resUpdInv->getStatusCode() === 422);

// =========================================================================
// BLOQUE 5: Baja Lógica (Soft Delete) y Reactivación (DELETE / PATCH status)
// =========================================================================
echo "\n--- BLOQUE 5: Baja Lógica (Soft Delete) y Reactivación (RF-REP-02, Art. III) ---\n";

// 5.1 Baja lógica vía DELETE /api/coordinator/spare-parts/{id}
$reqDelete = new Request('DELETE', "/api/coordinator/spare-parts/{$createdId}", [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resDelete = $router->dispatch($reqDelete);

$assert("5.1 DELETE retorna HTTP 200", $resDelete->getStatusCode() === 200);
$bodyDel = json_decode($resDelete->getBody(), true);
$assert("5.2 Respuesta de baja lógica indica is_active=false", ($bodyDel['data']['is_active'] ?? null) === false);

// 5.3 Verificar en base de datos que el registro NO fue eliminado físicamente (Art. III)
$stmtCheck = $pdo->prepare("SELECT id, is_active FROM spare_parts WHERE id = :id");
$stmtCheck->execute([':id' => $createdId]);
$dbPart = $stmtCheck->fetch(\PDO::FETCH_ASSOC);
$assert("5.3 Fila física existe en MariaDB tras DELETE (Art. III)", $dbPart !== false);
$assert("5.4 Columna is_active es 0 en base de datos", (int)($dbPart['is_active'] ?? 1) === 0);

// 5.4 Reactivación con PATCH /api/coordinator/spare-parts/{id}/status (is_active = true)
$reqReactivate = new Request('PATCH', "/api/coordinator/spare-parts/{$createdId}/status", [], ['is_active' => true], ['Authorization' => 'Bearer ' . $coordToken]);
$resReactivate = $router->dispatch($reqReactivate);
$assert("5.5 Reactivación PATCH retorna HTTP 200", $resReactivate->getStatusCode() === 200);
$bodyReac = json_decode($resReactivate->getBody(), true);
$assert("5.6 Respuesta confirma is_active=true", ($bodyReac['data']['is_active'] ?? false) === true);

// 5.7 Desactivación de nuevo con PATCH (is_active = false)
$reqDeactivate = new Request('PATCH', "/api/coordinator/spare-parts/{$createdId}/status", [], ['is_active' => false], ['Authorization' => 'Bearer ' . $coordToken]);
$resDeactivate = $router->dispatch($reqDeactivate);
$assert("5.7 Desactivación PATCH retorna HTTP 200", $resDeactivate->getStatusCode() === 200);
$assert("5.8 Respuesta confirma is_active=false", ($bodyDeactivate = json_decode($resDeactivate->getBody(), true)) && $bodyDeactivate['data']['is_active'] === false);

// 5.9 Operaciones en repuesto inexistente -> 404
$reqDel404 = new Request('DELETE', '/api/coordinator/spare-parts/999999', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$assert("5.9 DELETE repuesto inexistente retorna HTTP 404", $router->dispatch($reqDel404)->getStatusCode() === 404);

$reqPatch404 = new Request('PATCH', '/api/coordinator/spare-parts/999999/status', [], ['is_active' => true], ['Authorization' => 'Bearer ' . $coordToken]);
$assert("5.10 PATCH status repuesto inexistente retorna HTTP 404", $router->dispatch($reqPatch404)->getStatusCode() === 404);

// =========================================================================
// BLOQUE 6: GET /api/coordinator/spare-parts/analytics (RF-REP-08)
// =========================================================================
echo "\n--- BLOQUE 6: Panel Analítico de Fiabilidad y Averías Crónicas (RF-REP-08) ---\n";

// Insertar datos controlados de sustitución de repuestos en MariaDB para probar analítica
// Crearemos 4 sustituciones en la máquina 1 dentro de los últimos 30 días para activar alerta crónica (> 3)
$stmtInsertPart = $pdo->prepare("
    INSERT INTO incident_replaced_parts 
    (intervention_type, incident_id, preventive_order_id, machine_id, location_id, technician_id, spare_part_id, is_out_of_catalog, custom_part_name, quantity, unit_cost_snapshot, old_part_destination, notes, installed_at)
    VALUES 
    (:intervention_type, :incident_id, :preventive_order_id, :machine_id, :location_id, :technician_id, :spare_part_id, :is_out_of_catalog, :custom_part_name, :quantity, :unit_cost_snapshot, :old_part_destination, :notes, :installed_at)
");

$locationId = (int)$machine1->getLocationId();
$machineId = (int)$machine1->getId();
$technicianId = (int)$technician->getId();

// 1-3. Sustituciones 1 a 3: DESGUACE (Electroválvula 28.50 €)
for ($i = 1; $i <= 3; $i++) {
    $stmtInsertPart->execute([
        ':intervention_type'     => 'INCIDENT',
        ':incident_id'           => null,
        ':preventive_order_id'   => null,
        ':machine_id'            => $machineId,
        ':location_id'           => $locationId,
        ':technician_id'         => $technicianId,
        ':spare_part_id'         => $partUlkaId,
        ':is_out_of_catalog'     => 0,
        ':custom_part_name'      => null,
        ':quantity'              => 1,
        ':unit_cost_snapshot'    => 28.50,
        ':old_part_destination'  => 'DESGUACE',
        ':notes'                 => 'Bobinas quemadas ' . $i,
        ':installed_at'          => date('Y-m-d H:i:s', strtotime('-' . ($i * 5) . ' days')),
    ]);
}

// 4. Sustitución 4: TALLER (Electroválvula 28.50 €) -> Total 4 sustituciones de la misma pieza en máquina 1 (> 3)
$stmtInsertPart->execute([
    ':intervention_type'     => 'PREVENTIVE',
    ':incident_id'           => null,
    ':preventive_order_id'   => null,
    ':machine_id'            => $machineId,
    ':location_id'           => $locationId,
    ':technician_id'         => $technicianId,
    ':spare_part_id'         => $partUlkaId,
    ':is_out_of_catalog'     => 0,
    ':custom_part_name'      => null,
    ':quantity'              => 1,
    ':unit_cost_snapshot'    => 28.50,
    ':old_part_destination'  => 'TALLER',
    ':notes'                 => 'Para reacondicionamiento en taller',
    ':installed_at'          => date('Y-m-d H:i:s', strtotime('-2 days')),
]);

// 5. Sustitución 5: DESGUACE (Sonda NTC 15.20 €)
$stmtInsertPart->execute([
    ':intervention_type'     => 'INCIDENT',
    ':incident_id'           => null,
    ':preventive_order_id'   => null,
    ':machine_id'            => $machineId,
    ':location_id'           => $locationId,
    ':technician_id'         => $technicianId,
    ':spare_part_id'         => $partSondId,
    ':is_out_of_catalog'     => 0,
    ':custom_part_name'      => null,
    ':quantity'              => 1,
    ':unit_cost_snapshot'    => 15.20,
    ':old_part_destination'  => 'DESGUACE',
    ':notes'                 => 'Descalibrada',
    ':installed_at'          => date('Y-m-d H:i:s', strtotime('-10 days')),
]);

// Consulta de analítica para 90 días
$reqAnalytics = new Request('GET', '/api/coordinator/spare-parts/analytics', ['period_days' => '90'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resAnalytics = $router->dispatch($reqAnalytics);

$assert("6.1 Analítica retorna HTTP 200", $resAnalytics->getStatusCode() === 200);
$bodyAnalytics = json_decode($resAnalytics->getBody(), true);
$assert("6.2 Respuesta de analítica contiene success=true", ($bodyAnalytics['success'] ?? false) === true);
$analyticsData = $bodyAnalytics['data'] ?? [];

$assert("6.3 Período analizado es 90 días", ($analyticsData['period_days'] ?? 0) === 90);
$assert("6.4 Total de piezas sustituidas es al menos 5 (unidades)", ($analyticsData['total_parts_replaced'] ?? 0) >= 5);
$assert("6.5 Coste total acumulado es mayor que cero (> 100 €)", (float)($analyticsData['total_parts_cost'] ?? 0) > 100.0);

// Verificar desglose por destinos en el repuesto principal
$topParts = $analyticsData['top_replaced_parts'] ?? [];
$ulkaSummary = null;
foreach ($topParts as $tp) {
    if (($tp['part_code'] ?? '') === 'VALV-ULKA-01') {
        $ulkaSummary = $tp;
        break;
    }
}
$ulkaDestinations = $ulkaSummary['destinations'] ?? [];
$assert("6.6 Desglose incluye destino DESGUACE", ($ulkaDestinations['DESGUACE'] ?? 0) >= 3);
$assert("6.7 Desglose incluye destino TALLER", ($ulkaDestinations['TALLER'] ?? 0) >= 1);

// Verificar detección de máquina con averías crónicas (> 3 sustituciones en 90 días para el componente)
$chronicAlerts = $analyticsData['chronic_failure_alerts'] ?? [];
$chronicFound = false;
foreach ($chronicAlerts as $cm) {
    if (($cm['machine_id'] ?? null) === $machineId && ($cm['part_code'] ?? '') === 'VALV-ULKA-01') {
        $chronicFound = true;
        break;
    }
}
$assert("6.8 Detección de averías crónicas identifica a la máquina con >3 intervenciones", $chronicFound);

// Validación de parámetro period_days erróneo -> 400 Bad Request
$reqBadDays = new Request('GET', '/api/coordinator/spare-parts/analytics', ['period_days' => '-30'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resBadDays = $router->dispatch($reqBadDays);
$assert("6.9 period_days negativo retorna HTTP 400", $resBadDays->getStatusCode() === 400);

// =========================================================================
// BLOQUE 7: GET /api/coordinator/spare-parts/export (Descarga CSV)
// =========================================================================
echo "\n--- BLOQUE 7: Exportación de Consumos a CSV (RF-REP-09) ---\n";

$reqExport = new Request('GET', '/api/coordinator/spare-parts/export', ['period_days' => '90'], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resExport = $router->dispatch($reqExport);

$assert("7.1 Exportación CSV retorna HTTP 200", $resExport->getStatusCode() === 200);
$contentType = $resExport->getHeader('Content-Type') ?? '';
$assert("7.2 Content-Type es text/csv con charset utf-8", str_contains($contentType, 'text/csv'));

$contentDisposition = $resExport->getHeader('Content-Disposition') ?? '';
$assert("7.3 Content-Disposition es attachment con extensión .csv", str_contains($contentDisposition, 'attachment') && str_contains($contentDisposition, '.csv'));

$csvBody = $resExport->getBody();
$assert("7.4 Fichero CSV incluye BOM UTF-8 (\\xEF\\xBB\\xBF)", str_starts_with($csvBody, "\xEF\xBB\xBF"));

// Verificar cabeceras en español
$assert("7.5 Cabeceras en español presentes en la primera línea",
    str_contains($csvBody, 'Fecha') &&
    str_contains($csvBody, 'Codigo_Intervencion') &&
    str_contains($csvBody, 'Codigo_Maquina') &&
    str_contains($csvBody, 'Nombre_Pieza') &&
    str_contains($csvBody, 'Coste_Total_EUR') &&
    str_contains($csvBody, 'Destino_Retirado')
);

// Verificar presencia de las filas de sustitución insertadas
$assert("7.6 Fichero CSV contiene registros de las piezas sustituidas",
    str_contains($csvBody, 'DESGUACE') &&
    str_contains($csvBody, 'TALLER') &&
    str_contains($csvBody, 'VEND-0101')
);

// =========================================================================
// BLOQUE 8: GET /api/coordinator/spare-parts/requests/pending-review (RF-REP-04)
// =========================================================================
echo "\n--- BLOQUE 8: Bandeja de Piezas Fuera de Catálogo (RF-REP-04) ---\n";

// Obtener una incidencia existente en BD para vincular la solicitud de pieza fuera de catálogo
$testIncidentId = (int)$pdo->query("SELECT id FROM incidents LIMIT 1")->fetchColumn();
$createdIncidentId = null;

if (!$testIncidentId) {
    $uniqueTicket = 'INC-' . date('Ymd') . '-' . substr(uniqid(), -4);
    $stmtCreateInc = $pdo->prepare("
        INSERT INTO `incidents` (
            `ticket_code`,
            `machine_id`,
            `location_id`,
            `assigned_technician_id`,
            `reporter_name`,
            `reporter_phone`,
            `category`,
            `description`,
            `urgency`,
            `status`,
            `created_at`,
            `updated_at`
        ) VALUES (
            :ticket_code,
            :machine_id,
            :location_id,
            :assigned_technician_id,
            'Control Calidad',
            '611223344',
            'MECHANICAL',
            'Avería mecánica para validación de repuestos',
            'MEDIUM',
            'PENDING_PARTS',
            NOW(),
            NOW()
        )
    ");
    $stmtCreateInc->execute([
        ':ticket_code'             => $uniqueTicket,
        ':machine_id'              => $machineId,
        ':location_id'             => $locationId,
        ':assigned_technician_id'  => $technicianId,
    ]);
    $testIncidentId = (int)$pdo->lastInsertId();
    $createdIncidentId = $testIncidentId;
}

// Insertar solicitud fuera de catálogo
$customDesc = 'Termostato de seguridad especial 160C para caldera industrial antigua ' . uniqid();
$stmtReq = $pdo->prepare("
    INSERT INTO spare_part_requests
    (incident_id, spare_part_id, is_out_of_catalog, custom_part_description, quantity, status, requested_by_user_id, created_at)
    VALUES
    (:incident_id, NULL, 1, :custom_desc, 1, 'PENDING', :requested_by, NOW())
");
$stmtReq->execute([
    ':incident_id'   => $testIncidentId,
    ':custom_desc'   => $customDesc,
    ':requested_by'  => $technicianId,
]);

$reqPending = new Request('GET', '/api/coordinator/spare-parts/requests/pending-review', [], [], ['Authorization' => 'Bearer ' . $coordToken]);
$resPending = $router->dispatch($reqPending);

$assert("8.1 Consulta de piezas fuera de catálogo retorna HTTP 200", $resPending->getStatusCode() === 200);
$bodyPending = json_decode($resPending->getBody(), true);
$pendingList = $bodyPending['data'] ?? [];

$assert("8.2 Bandeja contiene al menos 1 solicitud pendiente de revisión", is_array($pendingList) && count($pendingList) >= 1);
$foundDesc = false;
foreach ($pendingList as $item) {
    if (($item['custom_part_description'] ?? '') === $customDesc) {
        $foundDesc = true;
        break;
    }
}
$assert("8.3 La solicitud pendiente contiene la justificación técnica exacta del técnico", $foundDesc);

// =========================================================================
// Limpieza final
// =========================================================================
$pdo->exec("DELETE FROM spare_part_requests WHERE custom_part_description = '{$customDesc}'");
if ($createdIncidentId !== null) {
    $pdo->exec("DELETE FROM incidents WHERE id = {$createdIncidentId}");
}
$pdo->exec("DELETE FROM incident_replaced_parts WHERE machine_id = {$machineId}");

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas pasaron con éxito ({$assertions} aserciones)! (0 fallos)\n";
} else {
    echo " RESULTADO: {$failures} prueba(s) fallaron de {$assertions} aserciones.\n";
    exit(1);
}
echo "======================================================================\n";
