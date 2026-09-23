<?php

declare(strict_types=1);

/**
 * PdoUserRepositoryTest
 * 
 * Test de Integración para PdoUserRepository (Tarea T-13).
 * Verifica la recuperación de usuarios por email, autenticación Bcrypt y Soft Delete.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoUserRepositoryTest (T-13)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedUsers();

$userRepo = new PdoUserRepository($pdo);
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

// =====================================================================
// CASO 1: Recuperar coordinador y verificar contraseña
// =====================================================================
echo "--- Caso 1: Recuperar Coordinador y validar contraseña Bcrypt ---\n";
$coord = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert("1.1 findByEmail('coordinacion@vendguard.internal') devuelve instancia de User", $coord instanceof User);
if ($coord !== null) {
    $assert("1.2 Rol del usuario es COORDINATOR", $coord->getRole() === UserRole::COORDINATOR && $coord->isCoordinator());
    $assert("1.3 verifyPassword('Password123!') devuelve true", $coord->verifyPassword('Password123!'));
    $assert("1.4 password_verify() directo contra getPasswordHash() devuelve true", password_verify('Password123!', $coord->getPasswordHash()));
    $assert("1.5 Contraseña incorrecta devuelve false", !$coord->verifyPassword('ClaveIncorrecta!'));
    $assert("1.6 toArray() por defecto oculta el hash de contraseña", !isset($coord->toArray()['password_hash']));
}

// =====================================================================
// CASO 2: Recuperar técnico de ruta y verificar contraseña
// =====================================================================
echo "\n--- Caso 2: Recuperar Técnico de Ruta y validar credenciales ---\n";
$tech = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("2.1 findByEmail('jordi.ruta@vendguard.internal') devuelve instancia de User", $tech instanceof User);
if ($tech !== null) {
    $assert("2.2 Rol del usuario es TECHNICIAN", $tech->getRole() === UserRole::TECHNICIAN && $tech->isTechnician());
    $assert("2.3 verifyPassword('Password123!') devuelve true", $tech->verifyPassword('Password123!'));
    $assert("2.4 Nombre del técnico coincide", str_contains($tech->getName(), 'Jordi'));
}

// Comprobar normalización de espacios y mayúsculas
$techTrimmed = $userRepo->findByEmail('   JORDI.RUTA@VENDGUARD.INTERNAL   ');
$assert("2.5 findByEmail es insensible a mayúsculas y espacios", $techTrimmed instanceof User);

// =====================================================================
// CASO 3: Email inexistente devuelve null
// =====================================================================
echo "\n--- Caso 3: Email desconocido devuelve null ---\n";
$nonExistent = $userRepo->findByEmail('fantasma@vendguard.internal');
$assert("3.1 findByEmail con correo inexistente devuelve null", $nonExistent === null);

// =====================================================================
// CASO 4: Respeto estricto de Soft Delete (deleted_at IS NOT NULL)
// =====================================================================
echo "\n--- Caso 4: Respeto de Soft Delete en usuarios ---\n";
$testUserEmail = 'test.softdel@vendguard.internal';
$testHash = password_hash('Password123!', PASSWORD_BCRYPT);

// Insertar usuario temporal
$pdo->prepare("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`)
    VALUES ('Usuario Temporal SoftDel', :email, :hash, 'TECHNICIAN', 1)
    ON DUPLICATE KEY UPDATE `deleted_at` = NULL
")->execute([':email' => $testUserEmail, ':hash' => $testHash]);

$tempUser = $userRepo->findByEmail($testUserEmail);
$assert("4.1 Usuario temporal insertado y localizado activamente", $tempUser instanceof User);

if ($tempUser !== null) {
    $deletedOk = $userRepo->softDelete($tempUser->getId());
    $assert("4.2 softDelete() de usuario ejecutado con éxito", $deletedOk);

    $deletedUserSearch = $userRepo->findByEmail($testUserEmail);
    $assert("4.3 findByEmail() devuelve null tras soft delete", $deletedUserSearch === null);

    // Comprobar persistencia física
    $stmtRaw = $pdo->prepare("SELECT id, deleted_at FROM `users` WHERE `id` = :id");
    $stmtRaw->execute([':id' => $tempUser->getId()]);
    $rawRow = $stmtRaw->fetch();

    $assert("4.4 Fila física intacta en la base de datos (RNF-03)", $rawRow !== false && !empty($rawRow['deleted_at']));

    // Limpieza
    $pdo->prepare("DELETE FROM `users` WHERE `id` = :id")->execute([':id' => $tempUser->getId()]);
}

// =====================================================================
// CASO 5: Listado de técnicos de ruta (findAllTechnicians)
// =====================================================================
echo "\n--- Caso 5: Listado de técnicos activos para asignación ---\n";
$technicians = $userRepo->findAllTechnicians();

$assert("5.1 findAllTechnicians() devuelve al menos 1 técnico", count($technicians) >= 1);
$onlyTechnicians = true;
foreach ($technicians as $t) {
    if (!$t->isTechnician()) {
        $onlyTechnicians = false;
    }
}
$assert("5.2 Todos los usuarios listados tienen rol TECHNICIAN (ningún coordinador)", $onlyTechnicians);

// =====================================================================
// CASO 6: Creación de usuario con Bcrypt (create)
// =====================================================================
echo "\n--- Caso 6: Creación de usuario con Bcrypt (create) ---\n";
$newTechEmail = 'alberto.nuevo@vendguard.internal';
$pdo->prepare("DELETE FROM `users` WHERE `email` = :e")->execute([':e' => $newTechEmail]);

$createdUser = $userRepo->create([
    'name'     => 'Alberto Nuevo Técnico',
    'email'    => $newTechEmail,
    'password' => 'Temporal2026!',
    'role'     => 'TECHNICIAN',
    'phone'    => '644112233',
]);

$assert("6.1 create() devuelve instancia de User", $createdUser instanceof User);
$assert("6.2 Email normalizado a minúsculas", $createdUser->getEmail() === $newTechEmail);
$assert("6.3 Rol es TECHNICIAN", $createdUser->getRole() === UserRole::TECHNICIAN);
$assert("6.4 Contraseña 'Temporal2026!' verificable con verifyPassword", $createdUser->verifyPassword('Temporal2026!'));
$assert("6.5 Está activo por defecto", $createdUser->isActive());

$newUserId = $createdUser->getId();

// =====================================================================
// CASO 7: Edición de datos personales (update)
// =====================================================================
echo "\n--- Caso 7: Edición de datos de usuario (update) ---\n";
$updateOk = $userRepo->update($newUserId, [
    'name'  => 'Alberto N. Técnico Senior',
    'phone' => '644998877',
]);
$assert("7.1 update() retorna true", $updateOk === true);

$reloadedUser = $userRepo->findById($newUserId);
$assert("7.2 Nombre actualizado en base de datos", $reloadedUser !== null && $reloadedUser->getName() === 'Alberto N. Técnico Senior');
$assert("7.3 Teléfono actualizado en base de datos", $reloadedUser !== null && $reloadedUser->getPhone() === '644998877');

// =====================================================================
// CASO 8: Cambio de contraseña (updatePassword / resetPassword)
// =====================================================================
echo "\n--- Caso 8: Cambio de contraseña (updatePassword / resetPassword) ---\n";
$pwUpdatedOk = $userRepo->updatePassword($newUserId, 'NuevaClaveSuperSegura2026!');
$assert("8.1 updatePassword() retorna true", $pwUpdatedOk === true);

$userWithNewPw = $userRepo->findById($newUserId);
$assert("8.2 Contraseña anterior ya NO es válida", $userWithNewPw !== null && !$userWithNewPw->verifyPassword('Temporal2026!'));
$assert("8.3 Nueva contraseña es válida", $userWithNewPw !== null && $userWithNewPw->verifyPassword('NuevaClaveSuperSegura2026!'));

// Probar alias resetPassword
$resetOk = $userRepo->resetPassword($newUserId, 'ClaveReset2026!');
$assert("8.4 resetPassword() retorna true", $resetOk === true);
$userWithResetPw = $userRepo->findById($newUserId);
$assert("8.5 Contraseña tras resetPassword() es válida", $userWithResetPw !== null && $userWithResetPw->verifyPassword('ClaveReset2026!'));

// =====================================================================
// CASO 9: Guardia Mínima Operativa (countActiveByRole)
// =====================================================================
echo "\n--- Caso 9: Conteo de personal por rol (countActiveByRole) ---\n";
$activeCoords = $userRepo->countActiveByRole('COORDINATOR');
$activeTechs  = $userRepo->countActiveByRole('TECHNICIAN');
$activeCoordsEnum = $userRepo->countActiveByRole(UserRole::COORDINATOR);
$assert("9.1 Al menos 1 coordinador activo en el sistema (cadena)", $activeCoords >= 1);
$assert("9.2 Al menos 1 coordinador activo en el sistema (enum)", $activeCoordsEnum >= 1);
$assert("9.3 Al menos 1 técnico activo en el sistema", $activeTechs >= 2); // Jordi + Alberto

// =====================================================================
// CASO 10: Conteo de incidencias activas asignadas (countActiveAssignedIncidents / countPendingIncidents)
// =====================================================================
echo "\n--- Caso 10: Conteo de incidencias activas asignadas ---\n";
$assert("10.1 Inicialmente 0 incidencias activas para el nuevo técnico", $userRepo->countActiveAssignedIncidents($newUserId) === 0);
$assert("10.1b Inicialmente 0 incidencias activas con alias countPendingIncidents", $userRepo->countPendingIncidents($newUserId) === 0);

// Crear máquina temporal dedicada para el test de incidencia asignada
$tempMachineCode = 'VEND-TMP-USERTEST-10';
$pdo->prepare("DELETE FROM `machines` WHERE `code` = :c")->execute([':c' => $tempMachineCode]);
$pdo->prepare("
    INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `is_active`)
    VALUES (1, :c, 'Test Temp Model', 'COMBO', 'Planta Test', 1)
")->execute([':c' => $tempMachineCode]);
$tempMachineId = (int)$pdo->lastInsertId();

// Asignar una incidencia en IN_PROGRESS
$testTicketCode = 'INC-USER-TEST-01';
$pdo->prepare("DELETE FROM `incidents` WHERE `ticket_code` = :tc")->execute([':tc' => $testTicketCode]);

$stmtInc = $pdo->prepare("
    INSERT INTO `incidents` (
        `ticket_code`, `machine_id`, `location_id`, `category`, `description`, `urgency`, `status`, `assigned_technician_id`
    ) VALUES (
        :tc, :mid, :lid, 'OTHER', 'Test técnico asignado', 'LOW', 'IN_PROGRESS', :tech_id
    )
");
$stmtInc->execute([
    ':tc'      => $testTicketCode,
    ':mid'     => $tempMachineId,
    ':lid'     => 1,
    ':tech_id' => $newUserId,
]);
$assignedIncId = (int)$pdo->lastInsertId();

$assert("10.2 Detecta 1 incidencia activa asignada (countActiveAssignedIncidents)", $userRepo->countActiveAssignedIncidents($newUserId) === 1);
$assert("10.2b Detecta 1 incidencia activa asignada (countPendingIncidents)", $userRepo->countPendingIncidents($newUserId) === 1);

// Cerrar la incidencia
$pdo->prepare("UPDATE `incidents` SET `status` = 'CLOSED', `closed_at` = CURRENT_TIMESTAMP WHERE `id` = :id")
    ->execute([':id' => $assignedIncId]);
$assert("10.3 Detecta 0 incidencias activas tras CLOSED", $userRepo->countActiveAssignedIncidents($newUserId) === 0);

// Limpieza de incidencia y máquina temporal
$pdo->prepare("DELETE FROM `incidents` WHERE `id` = :id")->execute([':id' => $assignedIncId]);
$pdo->prepare("DELETE FROM `machines` WHERE `id` = :id")->execute([':id' => $tempMachineId]);

// =====================================================================
// CASO 11: Baja lógica y Reactivación (softDelete / restore)
// =====================================================================
echo "\n--- Caso 11: Baja lógica y reactivación (softDelete / restore) ---\n";
$softDelOk = $userRepo->softDelete($newUserId);
$assert("11.1 softDelete() retorna true", $softDelOk === true);

// findById convencional (onlyActive: true, allowDeleted: false) no debe encontrarlo
$assert("11.2 findById(onlyActive=true, allowDeleted=false) devuelve null", $userRepo->findById($newUserId, true, false) === null);

// findById con allowDeleted: true sí lo encuentra
$delUser = $userRepo->findById($newUserId, false, true);
$assert("11.3 findById(allowDeleted=true) localiza el usuario dado de baja", $delUser !== null);
$assert("11.4 is_active es false", $delUser !== null && !$delUser->isActive());

// Reactivar
$restoreOk = $userRepo->restore($newUserId);
$assert("11.5 restore() retorna true", $restoreOk === true);

$restoredUser = $userRepo->findById($newUserId);
$assert("11.6 Usuario reactivado localizado activamente", $restoredUser !== null && $restoredUser->isActive());

// =====================================================================
// CASO 12: Listado integral con filtros (findAll)
// =====================================================================
echo "\n--- Caso 12: Listado integral con filtros (findAll) ---\n";
$allUsers = $userRepo->findAll();
$assert("12.1 findAll() devuelve lista de usuarios", count($allUsers) >= 2);

$firstUser = $allUsers[0];
$assert("12.2 Estructura contiene 'id', 'name', 'email'", isset($firstUser['id'], $firstUser['name'], $firstUser['email']));
$assert("12.3 Estructura contiene 'role' y 'role_label'", isset($firstUser['role'], $firstUser['role_label']));
$assert("12.4 Estructura contiene 'active_assigned_incidents_count'", isset($firstUser['active_assigned_incidents_count']));

// Filtro por rol
$techList = $userRepo->findAll(['role' => 'TECHNICIAN']);
$allAreTechs = true;
foreach ($techList as $tu) {
    if ($tu['role'] !== 'TECHNICIAN') {
        $allAreTechs = false;
    }
}
$assert("12.5 Filtro por rol TECHNICIAN devuelve solo técnicos", count($techList) >= 1 && $allAreTechs);

// Filtro por búsqueda
$searchUsers = $userRepo->findAll(['search' => 'Alberto']);
$assert("12.6 Búsqueda por término encuentra al usuario creado", count($searchUsers) === 1 && $searchUsers[0]['email'] === $newTechEmail);

// Limpieza final del usuario de prueba
$pdo->prepare("DELETE FROM `users` WHERE `id` = :id")->execute([':id' => $newUserId]);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ADM-06 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} COMPROBACIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
