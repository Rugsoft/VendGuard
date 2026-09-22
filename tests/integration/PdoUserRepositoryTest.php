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
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-13 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} COMPROBACIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
