<?php

declare(strict_types=1);

/**
 * PdoLocationRepositoryTest
 * 
 * Test de Integración para PdoLocationRepository (Tarea T-11).
 * Verifica la recuperación por site_code y el respeto estricto de Soft Delete.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Core\Domain\Model\Location;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoLocationRepositoryTest (T-11)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar datos semilla base
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedLocations();

$repository = new PdoLocationRepository($pdo);
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
// 1. Recuperar sede activa existente (SEDE-BCN-01)
// =====================================================================
echo "--- Caso 1: Recuperación de sede activa existente ---\n";
$loc = $repository->findBySiteCode('SEDE-BCN-01');

$assert("1.1 findBySiteCode('SEDE-BCN-01') devuelve una instancia de Location", $loc instanceof Location);
if ($loc !== null) {
    $assert("1.2 site_code coincide con 'SEDE-BCN-01'", $loc->getSiteCode() === 'SEDE-BCN-01');
    $assert("1.3 Sede marcada como activa (isActive === true)", $loc->isActive());
    $assert("1.4 deleted_at es null", $loc->getDeletedAt() === null);
    $assert("1.5 Soporte ArrayAccess: \$loc['name'] funciona", !empty($loc['name']));
}

// Comprobar insensibilidad a mayúsculas/minúsculas y espacios
$locLower = $repository->findBySiteCode('  sede-bcn-01  ');
$assert("1.6 Búsqueda insensible a mayúsculas y espacios periféricos", $locLower instanceof Location);

// =====================================================================
// 2. Comprobar que código inexistente devuelve null
// =====================================================================
echo "\n--- Caso 2: Código inexistente devuelve null ---\n";
$locNonExistent = $repository->findBySiteCode('SEDE-INEXISTENTE-999');
$assert("2.1 findBySiteCode con código desconocido devuelve null", $locNonExistent === null);

// =====================================================================
// 3. Comprobar que sede con deleted_at IS NOT NULL devuelve null
// =====================================================================
echo "\n--- Caso 3: Respeto estricto de Soft Delete (deleted_at IS NOT NULL) ---\n";

// Crear sede de prueba para borrado lógico
$testSiteCode = 'SEDE-TEST-SOFTDEL';
$pdo->prepare("
    INSERT INTO `locations` (`site_code`, `name`, `address`, `is_active`)
    VALUES (:code, 'Sede Temporal Test', 'Calle Test 123, BCN', 1)
    ON DUPLICATE KEY UPDATE `deleted_at` = NULL
")->execute([':code' => $testSiteCode]);

$tempLoc = $repository->findBySiteCode($testSiteCode);
$assert("3.1 Sede temporal creada y encontrada activamente", $tempLoc instanceof Location);

if ($tempLoc !== null) {
    // Aplicar Soft Delete
    $deletedOk = $repository->softDelete($tempLoc->getId());
    $assert("3.2 softDelete() ejecutado con éxito", $deletedOk);

    // Intentar buscar de nuevo: DEBE DEVOLVER NULL
    $deletedLocSearch = $repository->findBySiteCode($testSiteCode);
    $assert("3.3 findBySiteCode() devuelve null para sede con deleted_at IS NOT NULL", $deletedLocSearch === null);

    // Verificar en BD que el registro SIGUE EXISTIENDO FÍSICAMENTE (RNF-03)
    $stmtRaw = $pdo->prepare("SELECT id, deleted_at FROM `locations` WHERE `id` = :id");
    $stmtRaw->execute([':id' => $tempLoc->getId()]);
    $rawRow = $stmtRaw->fetch();

    $assert("3.4 El registro físico NO fue destruido (prohibición física DELETE)", $rawRow !== false);
    $assert("3.5 deleted_at contiene marca temporal válida", $rawRow !== false && !empty($rawRow['deleted_at']));

    // Limpieza final de la sede de test
    $pdo->prepare("DELETE FROM `locations` WHERE `id` = :id")->execute([':id' => $tempLoc->getId()]);
}

// =====================================================================
// 4. Comprobar findAllActive()
// =====================================================================
echo "\n--- Caso 4: findAllActive() ---\n";
$allActive = $repository->findAllActive();
$assert("4.1 findAllActive() devuelve al menos 2 sedes", count($allActive) >= 2);

$hasBcn01 = false;
$hasBcn02 = false;
foreach ($allActive as $l) {
    if ($l->getSiteCode() === 'SEDE-BCN-01') $hasBcn01 = true;
    if ($l->getSiteCode() === 'SEDE-BCN-02') $hasBcn02 = true;
}
$assert("4.2 SEDE-BCN-01 y SEDE-BCN-02 presentes en listado activo", $hasBcn01 && $hasBcn02);

// =====================================================================
// 5. Crear nueva sede (create)
// =====================================================================
echo "\n--- Caso 5: create() nueva sede ---\n";
$newCode = 'SEDE-TEST-CRUD-01';
$createdLoc = $repository->create([
    'site_code' => $newCode,
    'name' => 'Campus Universitario Norte',
    'address' => 'Calle Mayor 50, BCN',
    'contact_name' => 'Carlos Gestor',
    'contact_phone' => '655112233'
]);
$assert("5.1 create() retorna instancia de Location", $createdLoc instanceof Location);
$assert("5.2 ID autogenerado mayor que 0", $createdLoc->getId() > 0);
$assert("5.3 site_code coincide", $createdLoc->getSiteCode() === $newCode);
$assert("5.4 is_active inicial es true", $createdLoc->isActive());

// =====================================================================
// 6. Actualizar datos de sede (update)
// =====================================================================
echo "\n--- Caso 6: update() sede ---\n";
$updateOk = $repository->update($createdLoc->getId(), [
    'name' => 'Campus Universitario Norte - Edificio A',
    'contact_name' => 'Carla Gestora',
    'contact_phone' => '655998877'
]);
$assert("6.1 update() retorna true", $updateOk);

$updatedLoc = $repository->findById($createdLoc->getId());
$assert("6.2 Nombre actualizado en base de datos", $updatedLoc !== null && $updatedLoc->getName() === 'Campus Universitario Norte - Edificio A');
$assert("6.3 Contacto actualizado", $updatedLoc !== null && $updatedLoc->getContactName() === 'Carla Gestora');
$assert("6.4 Teléfono actualizado", $updatedLoc !== null && $updatedLoc->getContactPhone() === '655998877');

// =====================================================================
// 7. Baja lógica (softDelete) y Reactivación (restore)
// =====================================================================
echo "\n--- Caso 7: softDelete() y restore() ---\n";
$softDelOk = $repository->softDelete($createdLoc->getId());
$assert("7.1 softDelete() retorna true", $softDelOk);

$afterDel = $repository->findById($createdLoc->getId(), true);
$assert("7.2 Tras baja, is_active es false", $afterDel !== null && $afterDel->isActive() === false);
$assert("7.3 Tras baja, deleted_at tiene fecha", $afterDel !== null && $afterDel->getDeletedAt() !== null);

$restoreOk = $repository->restore($createdLoc->getId());
$assert("7.4 restore() retorna true", $restoreOk);

$afterRestore = $repository->findById($createdLoc->getId(), false);
$assert("7.5 Tras reactivar, is_active es true", $afterRestore !== null && $afterRestore->isActive() === true);
$assert("7.6 Tras reactivar, deleted_at es null", $afterRestore !== null && $afterRestore->getDeletedAt() === null);

// =====================================================================
// 8. Listado con filtros y conteos (findAll)
// =====================================================================
echo "\n--- Caso 8: findAll() con filtros de estado y búsqueda ---\n";
$listAll = $repository->findAll('all');
$assert("8.1 findAll('all') retorna array", is_array($listAll) && count($listAll) >= 3);
$assert("8.2 Primer elemento contiene campos de conteo", isset($listAll[0]['active_machines_count']) && isset($listAll[0]['total_machines_count']));

$listSearch = $repository->findAll('all', 'Universitario');
$assert("8.3 Búsqueda por texto filtra correctamente", count($listSearch) >= 1 && str_contains($listSearch[0]['name'], 'Universitario'));

// =====================================================================
// 9. Conteo de máquinas activas (countActiveMachines)
// =====================================================================
echo "\n--- Caso 9: countActiveMachines() ---\n";
$bcn01 = $repository->findBySiteCode('SEDE-BCN-01');
$assert("9.1 Sede SEDE-BCN-01 encontrada", $bcn01 !== null);
if ($bcn01 !== null) {
    $activeCount = $repository->countActiveMachines($bcn01->getId());
    $assert("9.2 Conteo de máquinas activas en SEDE-BCN-01 es >= 1", $activeCount >= 1);
}

// Limpieza de la sede de test creada
$pdo->prepare("DELETE FROM `locations` WHERE `id` = :id")->execute([':id' => $createdLoc->getId()]);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ADM-04 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} COMPROBACIONES.\n";
    echo "======================================================================\n";
    exit(1);
}

