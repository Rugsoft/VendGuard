<?php

declare(strict_types=1);

/**
 * PdoSparePartRepositoryTest
 * 
 * Test de Integración para PdoSparePartRepository (Tarea T-SPARE-04).
 * Valida la persistencia del catálogo de repuestos, relaciones de compatibilidad,
 * filtros de búsqueda y baja lógica (Constitución Art. III).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoSparePartRepositoryTest (T-SPARE-04)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpiar repuestos residuales de tests previos
$pdo->exec("DELETE FROM `spare_part_compatibilities` WHERE `spare_part_id` IN (SELECT id FROM `spare_parts` WHERE `part_code` LIKE 'TEST-%')");
$pdo->exec("DELETE FROM `spare_parts` WHERE `part_code` LIKE 'TEST-%'");

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$repo = new PdoSparePartRepository($pdo);

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

// =====================================================================
// CASO 1: Búsqueda de piezas sembradas (findById, findByCode)
// =====================================================================
$existingPart = $repo->findByCode('VALV-ULKA-01');
$assert("1.1 findByCode encuentra repuesto de semilla VALV-ULKA-01", $existingPart !== null);
if ($existingPart !== null) {
    $assert("1.2 Nombre y categoría correctos", $existingPart->getName() === 'Electroválvula 24V 2 Vías Ulka' && $existingPart->getCategory() === SparePartCategory::HYDRAULIC);
    $assert("1.3 Modelos compatibles sembrados correctamente", count($existingPart->getCompatibleModels()) >= 2);
    $assert("1.4 findById devuelve el mismo repuesto", $repo->findById($existingPart->getId())?->getPartCode() === 'VALV-ULKA-01');
}

// =====================================================================
// CASO 2: Comprobación de existencia de código (isCodeExists)
// =====================================================================
$assert("2.1 isCodeExists detecta código existente", $repo->isCodeExists('VALV-ULKA-01') === true);
$assert("2.2 isCodeExists con excludeId del mismo ID devuelve false", $existingPart !== null && $repo->isCodeExists('VALV-ULKA-01', $existingPart->getId()) === false);
$assert("2.3 isCodeExists para código inexistente devuelve false", $repo->isCodeExists('COD-NO-EXISTE-XYZ') === false);

// =====================================================================
// CASO 3: Creación de un nuevo repuesto con modelos compatibles (create)
// =====================================================================
$newPart = new SparePart(
    null,
    'TEST-VALV-99',
    'Electroválvula de Entrada Test',
    SparePartCategory::HYDRAULIC,
    'SMC Corp',
    18.75,
    true,
    'Pieza de prueba para test de integración',
    ['Bianchi Gaia Espresso', 'Fas Perla']
);

$created = $repo->create($newPart);
$assert("3.1 create asigna ID generado", $created->getId() !== null && $created->getId() > 0);
$assert("3.2 create persiste modelos compatibles", count($created->getCompatibleModels()) === 2);

$foundCreated = $repo->findById($created->getId());
$assert("3.3 findById recupera la pieza creada", $foundCreated !== null && $foundCreated->getPartCode() === 'TEST-VALV-99');
$assert("3.4 Coste de referencia persistido exactamente", $foundCreated !== null && abs($foundCreated->getReferenceCost() - 18.75) < 0.001);

// =====================================================================
// CASO 4: Actualización de datos y compatibilidades (update)
// =====================================================================
$updatedPart = new SparePart(
    $created->getId(),
    'TEST-VALV-99',
    'Electroválvula de Entrada Test MODIFICADA',
    SparePartCategory::HYDRAULIC,
    'SMC Corporation Europe',
    22.50,
    true,
    'Notas actualizadas',
    ['Sanden Vendo G-Drink'] // Cambio a un único modelo diferente
);

$updateOk = $repo->update($updatedPart);
$assert("4.1 update devuelve true", $updateOk === true);

$reloaded = $repo->findById($created->getId());
$assert("4.2 Nombre y fabricante actualizados", $reloaded !== null && $reloaded->getName() === 'Electroválvula de Entrada Test MODIFICADA' && $reloaded->getManufacturer() === 'SMC Corporation Europe');
$assert("4.3 Coste actualizado a 22.50", $reloaded !== null && abs($reloaded->getReferenceCost() - 22.50) < 0.001);
$assert("4.4 Modelos compatibles sincronizados limpiamente", $reloaded !== null && $reloaded->getCompatibleModels() === ['Sanden Vendo G-Drink']);

// =====================================================================
// CASO 5: Baja lógica (softDelete - Constitución Art. III)
// =====================================================================
$deleteOk = $repo->softDelete($created->getId());
$assert("5.1 softDelete devuelve true", $deleteOk === true);

$softDeleted = $repo->findById($created->getId());
$assert("5.2 Pieza sigue existiendo físicamente (no borrado SQL)", $softDeleted !== null);
$assert("5.3 is_active pasa a ser false", $softDeleted !== null && $softDeleted->isActive() === false);
$assert("5.4 deleted_at está establecido", $softDeleted !== null && $softDeleted->getDeletedAt() !== null);

// =====================================================================
// CASO 6: Cambio explícito de estado (updateStatus)
// =====================================================================
$reactivateOk = $repo->updateStatus($created->getId(), true);
$assert("6.1 updateStatus(true) reactiva la pieza", $reactivateOk === true);
$reactivated = $repo->findById($created->getId());
$assert("6.2 is_active vuelve a ser true y deleted_at es null", $reactivated !== null && $reactivated->isActive() === true && $reactivated->getDeletedAt() === null);

// =====================================================================
// CASO 7: Listado filtrado (findAll)
// =====================================================================
$allParts = $repo->findAll();
$assert("7.1 findAll sin filtros devuelve repuestos", count($allParts) >= 8);

$filteredBySearch = $repo->findAll(search: 'Bomba');
$assert("7.2 findAll con search 'Bomba' filtra correctamente", count($filteredBySearch) >= 1 && str_contains($filteredBySearch[0]->getName(), 'Bomba'));

$filteredByCategory = $repo->findAll(category: 'HYDRAULIC');
$assert("7.3 findAll con category 'HYDRAULIC' filtra sólo hidráulicos", count($filteredByCategory) >= 2);
foreach ($filteredByCategory as $p) {
    if ($p->getCategory() !== SparePartCategory::HYDRAULIC) {
        $assert("7.3.1 Categoría no coincide", false);
    }
}

$filteredByModel = $repo->findAll(machineModel: 'Bianchi Gaia Espresso');
$assert("7.4 findAll con machineModel 'Bianchi Gaia Espresso' devuelve compatibles", count($filteredByModel) >= 2);

// =====================================================================
// CASO 8: findCompatibleWithModel
// =====================================================================
$compatibles = $repo->findCompatibleWithModel('Bianchi Gaia Espresso', onlyActive: true);
$assert("8.1 findCompatibleWithModel('Bianchi Gaia Espresso') retorna repuestos activos", count($compatibles) >= 2);
foreach ($compatibles as $c) {
    if (!$c->isActive()) {
        $assert("8.1.1 Se devolvió un repuesto inactivo cuando onlyActive=true", false);
    }
    if (!in_array('Bianchi Gaia Espresso', $c->getCompatibleModels(), true)) {
        $assert("8.1.2 Repuesto no declara el modelo compatible", false);
    }
}

// =====================================================================
// CASO 9: findDistinctMachineModels
// =====================================================================
$models = $repo->findDistinctMachineModels();
$assert("9.1 findDistinctMachineModels retorna modelos sin duplicados", count($models) >= 4 && in_array('Bianchi Gaia Espresso', $models, true));

// Limpieza de datos de prueba
$pdo->prepare("DELETE FROM `spare_part_compatibilities` WHERE `spare_part_id` = :id")->execute([':id' => $created->getId()]);
$pdo->prepare("DELETE FROM `spare_parts` WHERE `id` = :id")->execute([':id' => $created->getId()]);

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de PdoSparePartRepository pasaron con éxito.\n";
    exit(0);
} else {
    echo "Resultado: [FALLO] Se detectaron {$failures} fallos en el repositorio.\n";
    exit(1);
}
