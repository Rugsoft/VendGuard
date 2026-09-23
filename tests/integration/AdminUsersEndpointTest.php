<?php

declare(strict_types=1);

/**
 * AdminUsersEndpointTest
 * 
 * Test de Integración HTTP para los endpoints administrativos de Personal Interno (RF-03, RNF-04, Art. III.1, III.3, V.1):
 * 1. Control de acceso RBAC estricto (401 si no hay token, 403 si el rol no es COORDINATOR).
 * 2. GET /api/coordinator/users (Listado con filtros por rol, estado, búsqueda y recuento de averías).
 * 3. POST /api/coordinator/users (Alta segura con hash Bcrypt, validación mín 8 caracteres y auditoría inmutable sin rastro de credenciales).
 * 4. GET /api/coordinator/users/{id} (Detalle de usuario).
 * 5. PATCH /api/coordinator/users/{id} (Edición de contacto y auditoría inmutable).
 * 6. PATCH /api/coordinator/users/{id}/reset-password (Reseteo de contraseña y blindaje criptográfico de auditoría).
 * 7. PATCH /api/coordinator/users/{id}/deactivate (Baja lógica con bloqueo de auto-baja y bloqueo por averías activas asignadas).
 * 8. PATCH /api/coordinator/users/{id}/reactivate (Reactivación de usuario y auditoría).
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
echo " VendGuard: Test de Integración - AdminUsersEndpointTest (T-ADM-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
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
$reqNoAuth = new Request('GET', '/api/coordinator/users');
$resNoAuth = $router->dispatch($reqNoAuth);
$assert("1.1 GET /users sin token => 401 Unauthorized", $resNoAuth->getStatusCode() === 401);

// 1.2 Token de técnico => 403
$reqTech = new Request('GET', '/api/coordinator/users', [], [], ['authorization' => "Bearer {$technicianToken}"]);
$resTech = $router->dispatch($reqTech);
$assert("1.2 GET /users con rol TECHNICIAN => 403 Forbidden", $resTech->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: GET /api/coordinator/users (Listado con filtros y averías)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/coordinator/users ---\n";

$reqListAll = new Request('GET', '/api/coordinator/users', ['status' => 'all'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resListAll = $router->dispatch($reqListAll);
$bodyListAll = $resListAll->getDecodedBody();

$assert("2.1 Responde HTTP 200 OK", $resListAll->getStatusCode() === 200);
$assert("2.2 success es true", ($bodyListAll['success'] ?? false) === true);
$assert("2.3 Contiene usuarios registrados", count($bodyListAll['data'] ?? []) >= 2);

$firstUser = $bodyListAll['data'][0] ?? [];
$assert("2.4 Contiene nombre", isset($firstUser['name']));
$assert("2.5 Contiene email", isset($firstUser['email']));
$assert("2.6 Contiene role", isset($firstUser['role']));
$assert("2.7 Contiene active_assigned_incidents_count", isset($firstUser['active_assigned_incidents_count']) || isset($firstUser['active_incidents_count']));

// Filtro por rol TECHNICIAN
$reqFilterTech = new Request('GET', '/api/coordinator/users', ['role' => 'TECHNICIAN'], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resFilterTech = $router->dispatch($reqFilterTech);
$bodyFilterTech = $resFilterTech->getDecodedBody();
$assert("2.8 Filtro TECHNICIAN responde 200 OK", $resFilterTech->getStatusCode() === 200);
$assert("2.9 Todos los usuarios devueltos tienen rol TECHNICIAN", count(array_filter($bodyFilterTech['data'] ?? [], fn($u) => $u['role'] !== 'TECHNICIAN')) === 0);

// =========================================================================
// BLOQUE 3: POST /api/coordinator/users (Alta segura y Garantía Criptográfica)
// =========================================================================
echo "\n--- BLOQUE 3: POST /api/coordinator/users ---\n";

// 3.1 Contraseña menor a 8 caracteres => 400 Bad Request
$reqShortPass = new Request('POST', '/api/coordinator/users', [], [
    'name' => 'Usuario Clave Corta',
    'email' => 'clave.corta@vendguard.internal',
    'role' => 'TECHNICIAN',
    'phone' => '600111222',
    'password' => '12345'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resShortPass = $router->dispatch($reqShortPass);
$assert("3.1 Alta con contraseña corta retorna HTTP 400 Bad Request", $resShortPass->getStatusCode() === 400);

// 3.2 Email duplicado => 409 Conflict
$reqDupEmail = new Request('POST', '/api/coordinator/users', [], [
    'name' => 'Coordinador Copia',
    'email' => 'coordinacion@vendguard.internal',
    'role' => 'COORDINATOR',
    'phone' => '600999888',
    'password' => 'Segura2026!'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resDupEmail = $router->dispatch($reqDupEmail);
$assert("3.2 Alta con email existente retorna HTTP 409 Conflict", $resDupEmail->getStatusCode() === 409);

// 3.3 Alta exitosa de nuevo técnico
$uniqueEmail = 'tecnico.nuevo.' . substr(md5((string)microtime(true)), 0, 4) . '@vendguard.internal';
$plainPassword = 'PasswordSegura2026!';

$reqCreateUsr = new Request('POST', '/api/coordinator/users', [], [
    'name' => 'Raquel Técnica Nueva',
    'email' => $uniqueEmail,
    'role' => 'TECHNICIAN',
    'phone' => '677001122',
    'password' => $plainPassword
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resCreateUsr = $router->dispatch($reqCreateUsr);
$bodyCreateUsr = $resCreateUsr->getDecodedBody();

$assert("3.3 Alta válida retorna HTTP 201 Created", $resCreateUsr->getStatusCode() === 201);
$newUserId = (int)($bodyCreateUsr['data']['id'] ?? 0);
$assert("3.3 ID generado devuelto", $newUserId > 0);
$assert("3.3 Email devuelto coincide", ($bodyCreateUsr['data']['email'] ?? '') === $uniqueEmail);
$assert("3.3 is_active es true", ($bodyCreateUsr['data']['is_active'] ?? false) === true);

// 3.4 Verificación en BD (Bcrypt)
$stmtUsr = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmtUsr->execute([':id' => $newUserId]);
$dbUsr = $stmtUsr->fetch(PDO::FETCH_ASSOC);
$assert("3.4 Usuario persistido en BD", $dbUsr !== false);
$assert("3.4 Hash Bcrypt válido en BD", password_verify($plainPassword, (string)($dbUsr['password_hash'] ?? '')));

// 3.5 Verificación en Audit Log y Garantía Criptográfica (Art. III.3, V.1)
$stmtAuditUsr = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'USER' AND action = 'USER_CREATED' AND entity_id = :id");
$stmtAuditUsr->execute([':id' => $newUserId]);
$auditUsr = $stmtAuditUsr->fetch(PDO::FETCH_ASSOC);
$assert("3.5 Evento USER_CREATED en audit_log", $auditUsr !== false);

// Inviolabilidad Criptográfica: Ningún campo de auditoría expone contraseñas
$auditText = json_encode($auditUsr);
$assert("3.5 Garantía Criptográfica: Contraseña en texto plano ausente de auditoría", !str_contains((string)$auditText, $plainPassword));
$assert("3.5 Garantía Criptográfica: Hash Bcrypt ausente de auditoría", !str_contains((string)$auditText, (string)$dbUsr['password_hash']));

// =========================================================================
// BLOQUE 4: GET /api/coordinator/users/{id} (Detalle de usuario)
// =========================================================================
echo "\n--- BLOQUE 4: GET /api/coordinator/users/{id} ---\n";

$reqGetUsr = new Request('GET', "/api/coordinator/users/{$newUserId}", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetUsr = $router->dispatch($reqGetUsr);
$bodyGetUsr = $resGetUsr->getDecodedBody();

$assert("4.1 Detalle de usuario retorna HTTP 200 OK", $resGetUsr->getStatusCode() === 200);
$assert("4.2 ID coincide", ($bodyGetUsr['data']['id'] ?? 0) === $newUserId);
$assert("4.3 Nombre coincide", ($bodyGetUsr['data']['name'] ?? '') === 'Raquel Técnica Nueva');

// Detalle inexistente => 404
$reqGetUsr404 = new Request('GET', '/api/coordinator/users/999999', [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resGetUsr404 = $router->dispatch($reqGetUsr404);
$assert("4.4 Usuario inexistente retorna HTTP 404 Not Found", $resGetUsr404->getStatusCode() === 404);

// =========================================================================
// BLOQUE 5: PATCH /api/coordinator/users/{id} (Edición de contacto)
// =========================================================================
echo "\n--- BLOQUE 5: PATCH /api/coordinator/users/{id} ---\n";

$reqUpdateUsr = new Request('PATCH', "/api/coordinator/users/{$newUserId}", [], [
    'name' => 'Raquel Técnica Senior',
    'phone' => '688990022'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resUpdateUsr = $router->dispatch($reqUpdateUsr);
$bodyUpdateUsr = $resUpdateUsr->getDecodedBody();

$assert("5.1 Edición de datos retorna HTTP 200 OK", $resUpdateUsr->getStatusCode() === 200);
$assert("5.2 Nombre actualizado en respuesta", ($bodyUpdateUsr['data']['name'] ?? '') === 'Raquel Técnica Senior');
$assert("5.3 Teléfono actualizado en respuesta", ($bodyUpdateUsr['data']['phone'] ?? '') === '688990022');

// Auditoría de edición
$stmtAuditUpdUsr = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'USER' AND action = 'USER_UPDATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditUpdUsr->execute([':id' => $newUserId]);
$auditUpdUsr = $stmtAuditUpdUsr->fetch(PDO::FETCH_ASSOC);
$assert("5.4 Evento USER_UPDATED en audit_log", $auditUpdUsr !== false);

// =========================================================================
// BLOQUE 6: PATCH /api/coordinator/users/{id}/reset-password (Reseteo)
// =========================================================================
echo "\n--- BLOQUE 6: PATCH /api/coordinator/users/{id}/reset-password ---\n";

// 6.1 Contraseña menor a 8 caracteres => 400 Bad Request
$reqBadReset = new Request('PATCH', "/api/coordinator/users/{$newUserId}/reset-password", [], [
    'new_password' => 'short'
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resBadReset = $router->dispatch($reqBadReset);
$assert("6.1 Reseteo con clave corta retorna HTTP 400 Bad Request", $resBadReset->getStatusCode() === 400);

// 6.2 Reseteo válido
$newPassword = 'PasswordRenovada2026!';
$reqReset = new Request('PATCH', "/api/coordinator/users/{$newUserId}/reset-password", [], [
    'new_password' => $newPassword
], ['authorization' => "Bearer {$coordinatorToken}", 'content-type' => 'application/json']);
$resReset = $router->dispatch($reqReset);

$assert("6.2 Reseteo con clave válida retorna HTTP 200 OK", $resReset->getStatusCode() === 200);

// Comprobación en BD
$stmtCheckPass = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
$stmtCheckPass->execute([':id' => $newUserId]);
$dbHash = (string)$stmtCheckPass->fetchColumn();
$assert("6.3 Nuevo hash Bcrypt funcional en BD", password_verify($newPassword, $dbHash));

// Verificación en audit_log y blindaje criptográfico
$stmtAuditReset = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'USER' AND action = 'USER_PASSWORD_RESET' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditReset->execute([':id' => $newUserId]);
$auditReset = $stmtAuditReset->fetch(PDO::FETCH_ASSOC);
$assert("6.4 Evento USER_PASSWORD_RESET en audit_log", $auditReset !== false);
$resetAuditText = json_encode($auditReset);
$assert("6.5 Garantía Criptográfica: Nueva clave ausente de auditoría", !str_contains((string)$resetAuditText, $newPassword));

// =========================================================================
// BLOQUE 7: PATCH /api/coordinator/users/{id}/deactivate (Baja Lógica y Bloqueos)
// =========================================================================
echo "\n--- BLOQUE 7: PATCH /api/coordinator/users/{id}/deactivate ---\n";

// 7.1 Auto-baja bloqueada: el coordinador autenticado intenta desactivarse a sí mismo
$coordId = $coordinator->getId();
$reqSelfDeact = new Request('PATCH', "/api/coordinator/users/{$coordId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resSelfDeact = $router->dispatch($reqSelfDeact);
$bodySelfDeact = $resSelfDeact->getDecodedBody();

$assert("7.1 Auto-desactivación de coordinador retorna HTTP 403 Forbidden", $resSelfDeact->getStatusCode() === 403);
$assert("7.2 Código de error es SELF_DEACTIVATION_NOT_ALLOWED o CANNOT_DEACTIVATE_SELF", in_array($bodySelfDeact['error']['code'] ?? '', ['SELF_DEACTIVATION_NOT_ALLOWED', 'CANNOT_DEACTIVATE_SELF']));

// 7.2 Bloqueo de baja si tiene incidencias asignadas
$techId = $technician->getId();

// Asignamos una incidencia activa al técnico
$m0101 = (new PdoMachineRepository($pdo))->findByCode('VEND-0101');
$m0101Id = $m0101 !== null ? $m0101->getId() : 1;
$m0101LocId = $m0101 !== null ? $m0101->getLocationId() : 1;

$pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE machine_id = :mid)")->execute([':mid' => $m0101Id]);
$pdo->prepare("DELETE FROM incidents WHERE machine_id = :mid")->execute([':mid' => $m0101Id]);
$pdo->prepare("INSERT INTO incidents (ticket_code, machine_id, location_id, assigned_technician_id, category, description, urgency, status)
    VALUES ('INC-TECH-BLOCKED', :mid, :locId, :techId, 'PRODUCT_JAM', 'Asignada a técnico para prueba de baja', 'MEDIUM', 'ASSIGNED')")
    ->execute([':mid' => $m0101Id, ':locId' => $m0101LocId, ':techId' => $techId]);

$reqTechDeactBlocked = new Request('PATCH', "/api/coordinator/users/{$techId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resTechDeactBlocked = $router->dispatch($reqTechDeactBlocked);
$bodyTechDeactBlocked = $resTechDeactBlocked->getDecodedBody();

$assert("7.3 Baja de técnico con incidencias asignadas retorna HTTP 409 Conflict", $resTechDeactBlocked->getStatusCode() === 409);
$assert("7.4 Código de error es TECHNICIAN_HAS_PENDING_INCIDENTS o PENDING_INCIDENTS_BLOCKED", in_array($bodyTechDeactBlocked['error']['code'] ?? '', ['TECHNICIAN_HAS_PENDING_INCIDENTS', 'TECHNICIAN_HAS_ACTIVE_ASSIGNMENTS', 'PENDING_INCIDENTS_BLOCKED']));

// 7.5 Baja permitida sobre técnico sin incidencias ($newUserId)
$reqDeactUsr = new Request('PATCH', "/api/coordinator/users/{$newUserId}/deactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resDeactUsr = $router->dispatch($reqDeactUsr);
$bodyDeactUsr = $resDeactUsr->getDecodedBody();

$assert("7.5 Baja de técnico limpio retorna HTTP 200 OK", $resDeactUsr->getStatusCode() === 200);
$assert("7.6 is_active es false", ($bodyDeactUsr['data']['is_active'] ?? true) === false);

// Verificación en BD
$stmtCheckUserDeact = $pdo->prepare("SELECT is_active, deleted_at FROM users WHERE id = :id");
$stmtCheckUserDeact->execute([':id' => $newUserId]);
$rowUserDeact = $stmtCheckUserDeact->fetch(PDO::FETCH_ASSOC);
$assert("7.7 En BD is_active = 0 y deleted_at IS NOT NULL", (int)$rowUserDeact['is_active'] === 0 && $rowUserDeact['deleted_at'] !== null);

// Auditoría de baja
$stmtAuditDeactUsr = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'USER' AND action = 'USER_DEACTIVATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditDeactUsr->execute([':id' => $newUserId]);
$auditDeactUsr = $stmtAuditDeactUsr->fetch(PDO::FETCH_ASSOC);
$assert("7.8 Evento USER_DEACTIVATED en audit_log", $auditDeactUsr !== false);

// =========================================================================
// BLOQUE 8: PATCH /api/coordinator/users/{id}/reactivate (Reactivación)
// =========================================================================
echo "\n--- BLOQUE 8: PATCH /api/coordinator/users/{id}/reactivate ---\n";

$reqReactUsr = new Request('PATCH', "/api/coordinator/users/{$newUserId}/reactivate", [], [], ['authorization' => "Bearer {$coordinatorToken}"]);
$resReactUsr = $router->dispatch($reqReactUsr);
$bodyReactUsr = $resReactUsr->getDecodedBody();

$assert("8.1 Reactivación de usuario retorna HTTP 200 OK", $resReactUsr->getStatusCode() === 200);
$assert("8.2 is_active es true", ($bodyReactUsr['data']['is_active'] ?? false) === true);

// Verificación en BD
$stmtCheckUserReact = $pdo->prepare("SELECT is_active, deleted_at FROM users WHERE id = :id");
$stmtCheckUserReact->execute([':id' => $newUserId]);
$rowUserReact = $stmtCheckUserReact->fetch(PDO::FETCH_ASSOC);
$assert("8.3 En BD is_active = 1 y deleted_at IS NULL", (int)$rowUserReact['is_active'] === 1 && $rowUserReact['deleted_at'] === null);

// Auditoría de reactivación
$stmtAuditReactUsr = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'USER' AND action = 'USER_REACTIVATED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$stmtAuditReactUsr->execute([':id' => $newUserId]);
$auditReactUsr = $stmtAuditReactUsr->fetch(PDO::FETCH_ASSOC);
$assert("8.4 Evento USER_REACTIVATED en audit_log", $auditReactUsr !== false);

// Limpieza de datos temporales de prueba
$pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE ticket_code = 'INC-TECH-BLOCKED')")->execute();
$pdo->prepare("DELETE FROM incidents WHERE ticket_code = 'INC-TECH-BLOCKED'")->execute();
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $newUserId]);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS ASIGNACIONES DE PERSONAL PASARON EXITOSAMENTE ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-ADM-12 (Personal Interno) CUMPLIDA.\n";
    exit(0);
} else {
    echo " RESULTADO: {$failures} fallos detectados de {$assertions} aserciones evaluadas.\n";
    exit(1);
}
