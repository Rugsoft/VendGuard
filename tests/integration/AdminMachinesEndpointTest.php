<?php

declare(strict_types=1);

/**
 * AdminMachinesEndpointTest
 * 
 * Test de Integración HTTP para los endpoints administrativos de Máquinas (RF-02, RNF-04, Art. II, III.1, III.3, V.6):
 * 1. Control de acceso RBAC estricto (401 si no hay token, 403 si el rol no es COORDINATOR).
 * 2. GET /api/coordinator/machines (Catálogo con filtros por estado, sede, tipología sanitaria y badges).
 * 3. POST /api/coordinator/machines (Alta en sede activa, validaciones sanitarias, inmutabilidad y auditoría).
 * 4. GET /api/coordinator/machines/{id} (Detalle de máquina).
 * 5. PATCH /api/coordinator/machines/{id} (Edición y bloqueo de cambio de tipo con avería activa/garantía).
 * 6. PATCH /api/coordinator/machines/{id}/transfer (Traslado entre sedes activas y bloqueo con avería/garantía).
 * 7. PATCH /api/coordinator/machines/{id}/deactivate (Baja lógica y bloqueo con avería/garantía).
 * 8. PATCH /api/coordinator/machines/{id}/reactivate (Reactivación directa y asistida con reubicación obligatoria si sede original inactiva).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ tipado estricto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - AdminMachinesEndpointTest (T-ADM-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

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

// 1. Obtener usuarios y tokens
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0.1 Coordinador encontrado", $coordinator !== null);
$assert("0.2 Técnico encontrado", $technician !== null);

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);

// =========================================================================
// BLOQUE 1: Control de Acceso RBAC (401 / 403)
// =========================================================================
echo "\n--- BLOQUE 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$reqNoAuth = new Request('GET', '/api/coordinator/machines');
$resNoAuth = $router->dispatch($reqNoAuth);
$assert("1.1 GET /machines sin token => 401 Unauthorized", $resNoAuth->getStatusCode() === 401);

// 1.2 Token de técnico => 403
$reqTech = new Request('GET', '/api/coordinator/machines', [], [], ['authorization' => "Bearer {$technicianToken}"]);
$resTech = $router->dispatch($reqTech);
$assert("1.2 GET /machines con rol TECHNICIAN => 403 Forbidden", $resTech->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: GET /api/coordinator/machines (Catálogo con filtros y badges)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/coordinator/machines ---\n";

$reqListAll = new Request('GET', '/api/coordinator/machines', ['status' => 'all'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resListAll = $router->dispatch($reqListAll);
$bodyListAll = $resListAll->getDecodedBody();

$assert("2.1 Responde HTTP 200 OK", $resListAll->getStatusCode() === 200);
$assert("2.2 success es true", ($bodyListAll['success'] ?? false) === true);
$assert("2.3 Contiene máquinas registradas", count($bodyListAll['data'] ?? []) >= 2);

$firstMachine = $bodyListAll['data'][0] ?? [];
$assert("2.4 Contiene código de máquina", isset($firstMachine['code']));
$assert("2.5 Contiene tipología sanitaria", isset($firstMachine['machine_type']));
$assert("2.6 Contiene flag is_perishable", isset($firstMachine['is_perishable']));
$assert("2.7 Contiene nombre de sede", isset($firstMachine['location_name']));

// Filtro por tipología
$reqFilterType = new Request('GET', '/api/coordinator/machines', ['machine_type' => 'PERISHABLE_FOOD'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resFilterType = $router->dispatch($reqFilterType);
$bodyFilterType = $resFilterType->getDecodedBody();
$assert("2.8 Filtro PERISHABLE_FOOD responde 200 OK", $resFilterType->getStatusCode() === 200);
$assert("2.9 Máquina filtrada es perecedera", ($bodyFilterType['data'][0]['is_perishable'] ?? false) === true);

// =========================================================================
// BLOQUE 3: POST /api/coordinator/machines (Alta y Auditoría)
// =========================================================================
echo "\n--- BLOQUE 3: POST /api/coordinator/machines ---\n";

// 3.1 Datos incompletos => 400 Bad Request
$reqBadPost = new Request('POST', '/api/coordinator/machines', [], [
    'code' => 'VEND-ERR',
    'model' => '',
    'machine_type' => 'INVALID_TYPE',
    'location_id' => 1
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resBadPost = $router->dispatch($reqBadPost);
$assert("3.1 Alta con datos inválidos retorna HTTP 400 Bad Request", $resBadPost->getStatusCode() === 400);

// 3.2 Código existente => 409 Conflict
$reqDupPost = new Request('POST', '/api/coordinator/machines', [], [
    'code' => 'VEND-0101',
    'model' => 'Modelo Duplicado',
    'machine_type' => 'SNACKS',
    'location_id' => 1,
    'floor_wing' => 'Planta 1'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resDupPost = $router->dispatch($reqDupPost);
$assert("3.2 Alta con código existente retorna HTTP 409 Conflict", $resDupPost->getStatusCode() === 409);

// 3.3 Sede inexistente => 400 Bad Request o 404 Not Found
$reqNoLocPost = new Request('POST', '/api/coordinator/machines', [], [
    'code' => 'VEND-9988',
    'model' => 'Modelo Sede Inexistente',
    'machine_type' => 'HOT_DRINKS',
    'location_id' => 999999,
    'floor_wing' => 'Planta 1'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resNoLocPost = $router->dispatch($reqNoLocPost);
$assert("3.3 Alta con sede inexistente retorna error 400 o 404", in_array($resNoLocPost->getStatusCode(), [400, 404]));

// 3.4 Alta exitosa de nueva máquina
$uniqueMachineCode = strtoupper('VEND-T' . substr(md5((string)microtime(true)), 0, 4));
$locBcn = $locationRepo->findBySiteCode('SEDE-BCN-01');
$bcnId = $locBcn !== null ? $locBcn->getId() : 1;

$reqCreateMac = new Request('POST', '/api/coordinator/machines', [], [
    'code' => $uniqueMachineCode,
    'model' => 'FAS Young 1050',
    'machine_type' => 'PERISHABLE_FOOD',
    'location_id' => $bcnId,
    'floor_wing' => 'Planta 3 - Laboratorios',
    'notes' => 'Máquina nueva para alimentos frescos'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resCreateMac = $router->dispatch($reqCreateMac);
$bodyCreateMac = $resCreateMac->getDecodedBody();

$assert("3.4 Alta válida retorna HTTP 201 Created", $resCreateMac->getStatusCode() === 201);
$newMachineId = (int)($bodyCreateMac['data']['id'] ?? 0);
$assert("3.4 ID generado devuelto", $newMachineId > 0);
$assert("3.4 Código coincide", ($bodyCreateMac['data']['code'] ?? '') === $uniqueMachineCode);
$assert("3.4 Tipología sanitaria correcta", ($bodyCreateMac['data']['machine_type'] ?? '') === 'PERISHABLE_FOOD');

// 3.5 Verificación en BD y Auditoría
$stmtMac = $pdo->prepare("SELECT * FROM machines WHERE id = :id");
$stmtMac->execute([':id' => $newMachineId]);
$dbMac = $stmtMac->fetch(PDO::FETCH_ASSOC);
$assert("3.5 Máquina persistida en BD", $dbMac !== false && (int)$dbMac['is_active'] === 1);

$stmtAuditMac = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'MACHINE' AND action = 'MACHINE_CREATED' AND entity_id = :id");
$stmtAuditMac->execute([':id' => $newMachineId]);
$auditMac = $stmtAuditMac->fetch(PDO::FETCH_ASSOC);
$assert("3.5 Evento MACHINE_CREATED en audit_log", $auditMac !== false);
$assert("3.5 Actor registrado es el coordinador", (int)($auditMac['user_id'] ?? 0) === $coordinator->getId());

// =========================================================================
// BLOQUE 4: GET /api/coordinator/machines/{id} (Detalle de máquina)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/coordinator/machines/{id} ---\n";

$reqGetMac = new Request('GET', "/api/coordinator/machines/{$newMachineId}", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetMac = $router->dispatch($reqGetMac);
$bodyGetMac = $resGetMac->getDecodedBody();

$assert("4.1 Detalle de máquina retorna HTTP 200 OK", $resGetMac->getStatusCode() === 200);
$assert("4.2 ID devuelto coincide", ($bodyGetMac['data']['id'] ?? 0) === $newMachineId);
$assert("4.3 Modelo coincide", ($bodyGetMac['data']['model'] ?? '') === 'FAS Young 1050');

// Detalle inexistente => 404
$reqGetMac404 = new Request('GET', '/api/coordinator/machines/999999', [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetMac404 = $router->dispatch($reqGetMac404);
$assert("4.4 Máquina inexistente retorna HTTP 404 Not Found", $resGetMac404->getStatusCode() === 404);

// =========================================================================
// BLOQUE 5: PATCH /api/coordinator/machines/{id} (Edición y Regla de Bloqueo)
// =========================================================================
echo "\n--- BLOQUE 5: PATCH /api/coordinator/machines/{id} ---\n";

// 5.1 Bloqueo de tipología si tiene avería activa o en garantía (Art. II, V.6)
$m0101 = $machineRepo->findByCode('VEND-0101');
$m0101Id = $m0101 !== null ? $m0101->getId() : 1;
$m0101LocId = $m0101 !== null ? $m0101->getLocationId() : $bcnId;

// Asegurar que VEND-0101 tiene ticket activo
$pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE machine_id = :mid)")->execute([':mid' => $m0101Id]);
$pdo->prepare("DELETE FROM incidents WHERE machine_id = :mid")->execute([':mid' => $m0101Id]);
$pdo->prepare("INSERT INTO incidents (ticket_code, machine_id, machine_type_snapshot, location_id, reporter_phone, category, description, urgency, status)
    VALUES ('INC-TEST-BLOCKED', :mid, 'PERISHABLE_FOOD', :locId, '600111222', 'TEMPERATURE_COLD', 'Avería activa de prueba', 'HIGH', 'ASSIGNED')")->execute([':mid' => $m0101Id, ':locId' => $m0101LocId]);

$reqChangeTypeBlocked = new Request('PATCH', "/api/coordinator/machines/{$m0101Id}", [], [
    'machine_type' => 'HOT_DRINKS',
    'notes' => 'Intento de cambio de tipología con ticket activo'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resChangeTypeBlocked = $router->dispatch($reqChangeTypeBlocked);
$bodyChangeTypeBlocked = $resChangeTypeBlocked->getDecodedBody();

$assert("5.1 Cambio de tipología con ticket activo retorna HTTP 409 Conflict", $resChangeTypeBlocked->getStatusCode() === 409);
$assert("5.2 Código de error es MACHINE_TYPE_CHANGE_BLOCKED o MACHINE_HAS_ACTIVE_INCIDENT", in_array($bodyChangeTypeBlocked['error']['code'] ?? '', ['MACHINE_TYPE_CHANGE_BLOCKED', 'MACHINE_HAS_ACTIVE_INCIDENT']));

// 5.3 Edición permitida sobre máquina sin tickets activos ($newMachineId)
$reqUpdateMac = new Request('PATCH', "/api/coordinator/machines/{$newMachineId}", [], [
    'model' => 'FAS Young 1050 Pro',
    'floor_wing' => 'Planta 3 - Quirófanos',
    'notes' => 'Modelo actualizado a Pro'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resUpdateMac = $router->dispatch($reqUpdateMac);
$bodyUpdateMac = $resUpdateMac->getDecodedBody();

$assert("5.3 Edición de máquina limpia retorna HTTP 200 OK", $resUpdateMac->getStatusCode() === 200);
$assert("5.4 Modelo actualizado devuelto", ($bodyUpdateMac['data']['model'] ?? '') === 'FAS Young 1050 Pro');

// Auditoría
$stmtAuditUpdMac = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'MACHINE' AND action = 'MACHINE_UPDATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditUpdMac->execute([':id' => $newMachineId]);
$auditUpdMac = $stmtAuditUpdMac->fetch(PDO::FETCH_ASSOC);
$assert("5.5 Evento MACHINE_UPDATED en audit_log", $auditUpdMac !== false);

// =========================================================================
// BLOQUE 6: PATCH /api/coordinator/machines/{id}/transfer (Traslado)
// =========================================================================
echo "\n--- BLOQUE 6: PATCH /api/coordinator/machines/{id}/transfer ---\n";

// 6.1 Traslado bloqueado si hay avería activa
$reqTransferBlocked = new Request('PATCH', "/api/coordinator/machines/{$m0101Id}/transfer", [], [
    'target_location_id' => 2,
    'floor_wing' => 'Planta Baja',
    'notes' => 'Intento de traslado con avería'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resTransferBlocked = $router->dispatch($reqTransferBlocked);
$bodyTransferBlocked = $resTransferBlocked->getDecodedBody();

$assert("6.1 Traslado con ticket activo retorna HTTP 409 Conflict", $resTransferBlocked->getStatusCode() === 409);
$assert("6.2 Código de error es MACHINE_TRANSFER_BLOCKED o MACHINE_HAS_ACTIVE_INCIDENT", in_array($bodyTransferBlocked['error']['code'] ?? '', ['MACHINE_TRANSFER_BLOCKED', 'MACHINE_HAS_ACTIVE_INCIDENT']));

// 6.2 Traslado exitoso de máquina limpia a Sede 2
$locBcn2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$targetLocId = $locBcn2 !== null ? $locBcn2->getId() : 2;

$reqTransfer = new Request('PATCH', "/api/coordinator/machines/{$newMachineId}/transfer", [], [
    'target_location_id' => $targetLocId,
    'floor_wing' => 'Planta 4 - Oficinas Dirección',
    'notes' => 'Traslado completado por reorganización'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resTransfer = $router->dispatch($reqTransfer);
$bodyTransfer = $resTransfer->getDecodedBody();

$assert("6.3 Traslado válido retorna HTTP 200 OK", $resTransfer->getStatusCode() === 200);
$assert("6.4 location_id actualizado a sede destino", (int)($bodyTransfer['data']['location_id'] ?? 0) === $targetLocId);

// Auditoría de traslado
$stmtAuditTrans = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'MACHINE' AND action = 'MACHINE_TRANSFERRED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditTrans->execute([':id' => $newMachineId]);
$auditTrans = $stmtAuditTrans->fetch(PDO::FETCH_ASSOC);
$assert("6.5 Evento MACHINE_TRANSFERRED en audit_log", $auditTrans !== false);

// =========================================================================
// BLOQUE 7: PATCH /api/coordinator/machines/{id}/deactivate (Baja Lógica)
// =========================================================================
echo "\n--- BLOQUE 7: PATCH /api/coordinator/machines/{id}/deactivate ---\n";

// 7.1 Baja bloqueada por avería activa
$reqDeactMacBlocked = new Request('PATCH', "/api/coordinator/machines/{$m0101Id}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resDeactMacBlocked = $router->dispatch($reqDeactMacBlocked);
$bodyDeactMacBlocked = $resDeactMacBlocked->getDecodedBody();

$assert("7.1 Baja con avería activa retorna HTTP 409 Conflict", $resDeactMacBlocked->getStatusCode() === 409);
$assert("7.2 Código de error es MACHINE_TRANSFER_BLOCKED o MACHINE_HAS_ACTIVE_INCIDENT", in_array($bodyDeactMacBlocked['error']['code'] ?? '', ['MACHINE_TRANSFER_BLOCKED', 'MACHINE_DEACTIVATION_BLOCKED', 'MACHINE_HAS_ACTIVE_INCIDENT']));

// 7.2 Baja de máquina limpia
$reqDeactMac = new Request('PATCH', "/api/coordinator/machines/{$newMachineId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resDeactMac = $router->dispatch($reqDeactMac);
$bodyDeactMac = $resDeactMac->getDecodedBody();

$assert("7.3 Baja de máquina limpia retorna HTTP 200 OK", $resDeactMac->getStatusCode() === 200);
$assert("7.4 is_active es false", ($bodyDeactMac['data']['is_active'] ?? true) === false);

// Verificación en BD
$stmtCheckDeact = $pdo->prepare("SELECT is_active, deleted_at FROM machines WHERE id = :id");
$stmtCheckDeact->execute([':id' => $newMachineId]);
$rowCheckDeact = $stmtCheckDeact->fetch(PDO::FETCH_ASSOC);
$assert("7.5 En BD is_active = 0 y deleted_at IS NOT NULL", (int)$rowCheckDeact['is_active'] === 0 && $rowCheckDeact['deleted_at'] !== null);

// Auditoría de baja
$stmtAuditDeactMac = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'MACHINE' AND action = 'MACHINE_DEACTIVATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditDeactMac->execute([':id' => $newMachineId]);
$auditDeactMac = $stmtAuditDeactMac->fetch(PDO::FETCH_ASSOC);
$assert("7.6 Evento MACHINE_DEACTIVATED en audit_log", $auditDeactMac !== false);

// =========================================================================
// BLOQUE 8: PATCH /api/coordinator/machines/{id}/reactivate (Reactivación)
// =========================================================================
echo "\n--- BLOQUE 8: PATCH /api/coordinator/machines/{id}/reactivate ---\n";

// 8.1 Reactivación simple (sede destino SEDE-BCN-02 sigue activa)
$reqReactDirect = new Request('PATCH', "/api/coordinator/machines/{$newMachineId}/reactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resReactDirect = $router->dispatch($reqReactDirect);
$bodyReactDirect = $resReactDirect->getDecodedBody();

$assert("8.1 Reactivación directa retorna HTTP 200 OK", $resReactDirect->getStatusCode() === 200);
$assert("8.2 is_active es true", ($bodyReactDirect['data']['is_active'] ?? false) === true);

// Auditoría de reactivación directa
$stmtAuditReactMac = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'MACHINE' AND action = 'MACHINE_REACTIVATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditReactMac->execute([':id' => $newMachineId]);
$auditReactMac = $stmtAuditReactMac->fetch(PDO::FETCH_ASSOC);
$assert("8.3 Evento MACHINE_REACTIVATED en audit_log", $auditReactMac !== false);

// 8.4 Reactivación asistida con sede original dada de baja
// Creamos una sede temporal, una máquina en ella, damos de baja la sede y la máquina
$tempLoc = $locationRepo->create([
    'site_code' => 'SEDE-TEMP-' . substr(md5((string)microtime(true)), 0, 4),
    'name' => 'Sede Efímera',
    'address' => 'Zona Portuaria',
    'contact_name' => 'Portuario',
    'contact_phone' => '600000000',
]);
$tempLocId = $tempLoc->getId();

$tempMac = $machineRepo->create([
    'code' => 'VEND-TEMP-' . substr(md5((string)microtime(true)), 0, 4),
    'model' => 'Modelo Temporal',
    'machine_type' => 'SNACKS',
    'location_id' => $tempLocId,
    'floor_wing' => 'Muelle',
]);
$tempMacId = $tempMac->getId();

// Desactivamos la máquina y luego la sede
$machineRepo->softDelete($tempMacId);
$locationRepo->softDelete($tempLocId);

// Intento de reactivar la máquina sin nueva sede => 422 Unprocessable Entity
$reqReactOrphan = new Request('PATCH', "/api/coordinator/machines/{$tempMacId}/reactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resReactOrphan = $router->dispatch($reqReactOrphan);
$bodyReactOrphan = $resReactOrphan->getDecodedBody();

$assert("8.4 Reactivación con sede original inactiva sin destino retorna HTTP 422", $resReactOrphan->getStatusCode() === 422);
$assert("8.5 Código de error es MACHINE_REACTIVATION_REQUIRES_NEW_LOCATION", ($bodyReactOrphan['error']['code'] ?? '') === 'MACHINE_REACTIVATION_REQUIRES_NEW_LOCATION');

// Reactivación válida aportando sede activa de destino y nueva planta
$reqReactAssisted = new Request('PATCH', "/api/coordinator/machines/{$tempMacId}/reactivate", [], [
    'target_location_id' => $bcnId,
    'floor_wing' => 'Planta 1 - Entrada'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resReactAssisted = $router->dispatch($reqReactAssisted);
$bodyReactAssisted = $resReactAssisted->getDecodedBody();

$assert("8.6 Reactivación asistida con nueva sede retorna HTTP 200 OK", $resReactAssisted->getStatusCode() === 200);
$assert("8.7 Máquina reubicada a la sede activa", (int)($bodyReactAssisted['data']['location_id'] ?? 0) === $bcnId);
$assert("8.8 is_active es true tras reactivación asistida", ($bodyReactAssisted['data']['is_active'] ?? false) === true);

// Limpieza de datos temporales de prueba para preservar semillas puras
$pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE ticket_code = 'INC-TEST-BLOCKED')")->execute();
$pdo->prepare("DELETE FROM incidents WHERE ticket_code = 'INC-TEST-BLOCKED'")->execute();
$pdo->prepare("DELETE FROM machines WHERE id IN (:id1, :id2)")->execute([':id1' => $newMachineId, ':id2' => $tempMacId]);
$pdo->prepare("DELETE FROM locations WHERE id = :locId")->execute([':locId' => $tempLocId]);

// Restaurar máquinas semilla a su sede original
$pdo->prepare("UPDATE machines SET location_id = :bcnId, is_active = 1, deleted_at = NULL WHERE code = 'VEND-0101'")->execute([':bcnId' => $bcnId]);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS ASIGNACIONES DE MÁQUINAS PASARON EXITOSAMENTE ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-ADM-12 (Máquinas) CUMPLIDA.\n";
    exit(0);
} else {
    echo " RESULTADO: {$failures} fallos detectados de {$assertions} aserciones evaluadas.\n";
    exit(1);
}
