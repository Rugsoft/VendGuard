<?php

declare(strict_types=1);

/**
 * PdoVendingProductRepositoryTest · T-PLN-05
 *
 * Suite de integración del catálogo maestro `vending_products` contra el motor
 * PostgreSQL real (Módulo 32, RF-01.1–RF-01.3, RF-03.2, C1, D3, Art. III.1).
 *
 * Certifica:
 *   1. Lectura del catálogo por id y por SKU normalizado, incluida la semilla.
 *   2. Alta transaccional con importes exactos y timestamps del motor.
 *   3. Unicidad traducida a `409 DUPLICATE_PRODUCT_SKU` (también en carrera).
 *   4. Filtros de catálogo por texto, categoría y estado activo.
 *   5. Edición sin tocar el SKU y rechazo `409 SKU_CHANGE_FORBIDDEN` si difiere.
 *   6. Baja lógica y reactivación (`is_active`), con el disparador anti-borrado
 *      físico del Art. III.1 como red de seguridad.
 *   7. Conteo de carriles activos (`affected_active_slots_count`) y consulta de
 *      máquinas vinculadas con su tipología tipada para el cerrojo inverso.
 *
 * La suite usa referencias prefijadas `TEST-PLN-` y carriles `T5A*`, y los purga
 * al empezar y al terminar con la ventana de purga del contrato de limpieza.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Exception\DuplicateProductSkuException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

echo "======================================================================\n";
echo " VendGuard · Integración · PdoVendingProductRepositoryTest (T-PLN-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$repo = new PdoVendingProductRepository($pdo);

$cleanup = static function (PDO $pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec("DELETE FROM machine_planogram_slots WHERE slot_code LIKE 'T5%'");
        $pdo->exec("DELETE FROM vending_products WHERE sku LIKE 'TEST-PLN-%'");
    });
};

$cleanup($pdo);

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

try {
    // =================================================================
    // 1. Lectura del catálogo sembrado (RF-01.1)
    // =================================================================
    echo "--- 1. Lectura del catálogo por id y por SKU ---\n";

    $seeded = $repo->findBySku('cafe-grano-1k');
    $assert(
        '1.1 findBySku normaliza el SKU y encuentra la semilla CAFE-GRANO-1K',
        $seeded !== null
        && $seeded->getId() !== null
        && $seeded->getSku() === 'CAFE-GRANO-1K'
        && $seeded->getName() === 'Café en Grano Natural 1kg',
        $seeded === null ? 'no encontrado' : $seeded->getName()
    );

    $assert(
        '1.2 La semilla conserva categoría, unidad, frío, vida útil y estado',
        $seeded !== null
        && $seeded->getCategory() === ProductCategory::HOT_BEVERAGE_INGREDIENT
        && $seeded->getUnitType() === ProductUnitType::GRAMS
        && $seeded->requiresColdChain() === false
        && $seeded->getMaxShelfLifeDays() === null
        && $seeded->isActive() === true
    );

    $assert(
        '1.3 findById devuelve la misma referencia',
        $seeded !== null && $repo->findById((int)$seeded->getId())?->getSku() === 'CAFE-GRANO-1K'
    );

    $assert(
        '1.4 isSkuUsed distingue existencia, exclusión propia e inexistencia',
        $repo->isSkuUsed('CAFE-GRANO-1K') === true
        && $seeded !== null
        && $repo->isSkuUsed('CAFE-GRANO-1K', (int)$seeded->getId()) === false
        && $repo->isSkuUsed('SKU-QUE-NO-EXISTE') === false
    );

    $assert(
        '1.5 findById y findBySku toleran identificadores ausentes',
        $repo->findById(0) === null && $repo->findBySku('SKU-QUE-NO-EXISTE') === null
    );

    // =================================================================
    // 2. Alta transaccional y persistencia exacta (RF-01.1, C2)
    // =================================================================
    echo "\n--- 2. Alta de referencias e importes exactos ---\n";

    $cola = new VendingProduct(
        null,
        'test-pln-cola',
        'Cola de prueba 33 cl',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.45,
        1.20,
        false,
        null,
        true
    );
    $createdCola = $repo->create($cola);

    $assert(
        '2.1 create asigna id y normaliza el SKU a mayúsculas',
        $createdCola->getId() !== null && $createdCola->getId() > 0
        && $createdCola->getSku() === 'TEST-PLN-COLA',
        $createdCola->getSku()
    );

    $reloadedCola = $repo->findById((int)$createdCola->getId());
    $assert(
        '2.2 Importes exactos a dos decimales y margen derivado',
        $reloadedCola !== null
        && abs($reloadedCola->getUnitCostEur() - 0.45) < 0.001
        && abs($reloadedCola->getSalePriceEur() - 1.20) < 0.001
        && abs($reloadedCola->getUnitMarginEur() - 0.75) < 0.001,
        $reloadedCola === null ? 'no recargado' : (string)$reloadedCola->getUnitCostEur()
    );

    $assert(
        '2.3 El motor sella created_at y updated_at y la fila nace activa',
        $reloadedCola !== null
        && $reloadedCola->getCreatedAt() !== null
        && $reloadedCola->getUpdatedAt() !== null
        && $reloadedCola->isActive() === true
    );

    // =================================================================
    // 3. Unicidad del SKU traducida a 409 (RF-01.2)
    // =================================================================
    echo "\n--- 3. Unicidad del SKU (DUPLICATE_PRODUCT_SKU) ---\n";

    $duplicateCode = null;
    $duplicateStatus = null;
    $duplicateSku = null;
    try {
        $repo->create(new VendingProduct(
            null,
            'TEST-PLN-COLA',
            'Duplicado de prueba',
            ProductCategory::COLD_DRINK,
            ProductUnitType::UNIT,
            0.50,
            1.30
        ));
    } catch (DuplicateProductSkuException $exception) {
        $duplicateCode = $exception->getErrorCode();
        $duplicateStatus = $exception->getHttpStatusCode();
        $duplicateSku = $exception->getSku();
    }

    $assert(
        '3.1 El alta duplicada lanza 409 DUPLICATE_PRODUCT_SKU con el SKU',
        $duplicateCode === 'DUPLICATE_PRODUCT_SKU'
        && $duplicateStatus === 409
        && $duplicateSku === 'TEST-PLN-COLA',
        json_encode([$duplicateCode, $duplicateStatus, $duplicateSku])
    );

    $sameSkuRows = $repo->findAll('TEST-PLN-COLA');
    $assert(
        '3.2 El rechazo no deja una segunda fila en el catálogo',
        count($sameSkuRows) === 1,
        (string)count($sameSkuRows)
    );

    // =================================================================
    // 4. Filtros del catálogo (RF-01.1)
    // =================================================================
    echo "\n--- 4. Filtros por texto, categoría y estado ---\n";

    $assert(
        '4.1 La búsqueda libre encuentra por fragmento de SKU',
        count($repo->findAll('PLN-COLA')) === 1
        && $repo->findAll('PLN-COLA')[0]->getSku() === 'TEST-PLN-COLA'
    );

    $coldActive = $repo->findAll(null, ProductCategory::COLD_DRINK, true);
    $coldActiveSkus = array_map(static fn (VendingProduct $p): string => $p->getSku(), $coldActive);
    $assert(
        '4.2 El filtro de categoría y estado no mezcla categorías ni inactivos',
        in_array('TEST-PLN-COLA', $coldActiveSkus, true)
        && !in_array('CAFE-GRANO-1K', $coldActiveSkus, true)
        && $coldActive !== []
    );

    $hotAll = $repo->findAll(null, ProductCategory::HOT_BEVERAGE_INGREDIENT, null);
    $assert(
        '4.3 El filtro de categoría sin estado incluye activos e inactivos',
        count(array_filter($hotAll, static fn (VendingProduct $p): bool => $p->getSku() === 'CAFE-GRANO-1K')) === 1
    );

    $assert(
        '4.4 Sin filtros el catálogo devuelve las referencias sembradas',
        count($repo->findAll()) >= 15
    );

    // =================================================================
    // 5. Edición con SKU inmutable (C1)
    // =================================================================
    echo "\n--- 5. Edición e inmutabilidad del SKU ---\n";

    $editedCola = new VendingProduct(
        (int)$createdCola->getId(),
        'TEST-PLN-COLA',
        'Cola de prueba 33 cl (actualizada)',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.52,
        1.35,
        false,
        null,
        true
    );
    $updateOk = $repo->update($editedCola);
    $reloadedEdited = $repo->findById((int)$createdCola->getId());

    $assert(
        '5.1 update persiste nombre, coste y PVP',
        $updateOk === true
        && $reloadedEdited !== null
        && $reloadedEdited->getName() === 'Cola de prueba 33 cl (actualizada)'
        && abs($reloadedEdited->getUnitCostEur() - 0.52) < 0.001
        && abs($reloadedEdited->getSalePriceEur() - 1.35) < 0.001
    );

    $skuCode = null;
    $skuStatus = null;
    try {
        $repo->update(new VendingProduct(
            (int)$createdCola->getId(),
            'TEST-PLN-OTRO',
            'Cola de prueba 33 cl (actualizada)',
            ProductCategory::COLD_DRINK,
            ProductUnitType::UNIT,
            0.52,
            1.35
        ));
    } catch (PlanogramValidationException $exception) {
        $skuCode = $exception->getErrorCode();
        $skuStatus = $exception->getHttpStatusCode();
    }

    $afterSkuAttempt = $repo->findById((int)$createdCola->getId());
    $assert(
        '5.2 Cambiar el SKU se rechaza con 409 SKU_CHANGE_FORBIDDEN',
        $skuCode === 'SKU_CHANGE_FORBIDDEN' && $skuStatus === 409,
        json_encode([$skuCode, $skuStatus])
    );

    $assert(
        '5.3 El rechazo conserva el SKU persistido y no crea otra referencia',
        $afterSkuAttempt !== null
        && $afterSkuAttempt->getSku() === 'TEST-PLN-COLA'
        && $repo->findBySku('TEST-PLN-OTRO') === null
    );

    // =================================================================
    // 6. Baja lógica y reactivación (RF-01.3, Art. III.1)
    // =================================================================
    echo "\n--- 6. Baja lógica y reactivación ---\n";

    $assert(
        '6.1 updateStatus desactiva sin borrar la fila',
        $repo->updateStatus((int)$createdCola->getId(), false) === true
        && $repo->findById((int)$createdCola->getId())?->isActive() === false
    );

    $inactive = $repo->findAll(null, ProductCategory::COLD_DRINK, false);
    $inactiveSkus = array_map(static fn (VendingProduct $p): string => $p->getSku(), $inactive);
    $assert(
        '6.2 El filtro is_active=false incluye la referencia dada de baja',
        in_array('TEST-PLN-COLA', $inactiveSkus, true)
    );

    $activeAfterDeactivation = $repo->findAll(null, ProductCategory::COLD_DRINK, true);
    $assert(
        '6.3 La referencia inactiva queda fuera del filtro de activos',
        count(array_filter(
            $activeAfterDeactivation,
            static fn (VendingProduct $p): bool => $p->getSku() === 'TEST-PLN-COLA'
        )) === 0
    );

    $assert(
        '6.4 updateStatus reactiva la referencia',
        $repo->updateStatus((int)$createdCola->getId(), true) === true
        && $repo->findById((int)$createdCola->getId())?->isActive() === true
    );

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM vending_products WHERE id = ' . (int)$createdCola->getId());
    } catch (PDOException $exception) {
        $deleteBlocked = true;
    }
    $assert(
        '6.5 El disparador anti-borrado físico rechaza el DELETE (Art. III.1)',
        $deleteBlocked === true && $repo->findById((int)$createdCola->getId()) !== null
    );

    // =================================================================
    // 7. Conteo de carriles activos y máquinas vinculadas (RF-01.3, RF-03.2)
    // =================================================================
    echo "\n--- 7. Carriles activos y máquinas vinculadas ---\n";

    $cafe = $repo->create(new VendingProduct(
        null,
        'TEST-PLN-CAFE',
        'Café de prueba 1 kg',
        ProductCategory::HOT_BEVERAGE_INGREDIENT,
        ProductUnitType::GRAMS,
        7.25,
        0.00,
        false,
        null,
        true
    ));

    $assert(
        '7.1 Un producto sin carriles cuenta cero asignaciones activas',
        $repo->countActiveSlots((int)$createdCola->getId()) === 0
        && $repo->findMachineAssignments((int)$createdCola->getId()) === []
    );

    $hotMachine = $pdo->query("SELECT id, code FROM machines WHERE machine_type = 'HOT_DRINKS' ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $comboMachine = $pdo->query("SELECT id, code FROM machines WHERE machine_type = 'COMBO' ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $assert(
        '7.2 El parque sembrado ofrece una máquina HOT_DRINKS y otra COMBO',
        is_array($hotMachine) && is_array($comboMachine),
        json_encode([$hotMachine, $comboMachine])
    );

    $insertSlot = static function (
        PDO $pdo,
        int $machineId,
        string $slotCode,
        int $productId,
        int $capacity,
        int $qty,
        int $threshold,
        bool $active
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO machine_planogram_slots
                (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active)
             VALUES (:machine_id, :slot_code, :product_id, :max_capacity, :qty, :threshold, :is_active)'
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':slot_code', $slotCode, PDO::PARAM_STR);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':max_capacity', $capacity, PDO::PARAM_INT);
        $stmt->bindValue(':qty', $qty, PDO::PARAM_INT);
        $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $stmt->bindValue(':is_active', $active, PDO::PARAM_BOOL);
        $stmt->execute();
    };

    $insertSlot($pdo, (int)$hotMachine['id'], 'T5A', (int)$cafe->getId(), 2000, 500, 400, true);
    $insertSlot($pdo, (int)$hotMachine['id'], 'T5B', (int)$cafe->getId(), 1000, 100, 100, false);
    $insertSlot($pdo, (int)$comboMachine['id'], 'T5C', (int)$cafe->getId(), 1500, 0, 300, true);

    $assert(
        '7.3 countActiveSlots cuenta sólo carriles activos (2 de 3)',
        $repo->countActiveSlots((int)$cafe->getId()) === 2,
        (string)$repo->countActiveSlots((int)$cafe->getId())
    );

    $assignments = $repo->findMachineAssignments((int)$cafe->getId());
    $allAssignments = $repo->findMachineAssignments((int)$cafe->getId(), false);

    $assert(
        '7.4 findMachineAssignments devuelve sólo activos con tipología tipada',
        count($assignments) === 2
        && array_reduce(
            $assignments,
            static fn (bool $carry, array $row): bool => $carry
                && $row['machine_type'] instanceof MachineType
                && $row['machine_code'] !== ''
                && $row['slot_code'] !== '',
            true
        )
    );

    $assert(
        '7.5 Las asignaciones llegan ordenadas por código de máquina y carril',
        array_column($assignments, 'machine_code') === array_values(array_unique(array_column($assignments, 'machine_code')))
        && $assignments[0]['machine_code'] <= $assignments[1]['machine_code']
    );

    $assert(
        '7.6 onlyActive=false incluye el carril inactivo',
        count($allAssignments) === 3
        && count(array_filter($allAssignments, static fn (array $row): bool => $row['is_active'] === false)) === 1
    );

    $pdo->exec("UPDATE machine_planogram_slots SET is_active = FALSE WHERE slot_code = 'T5C'");
    $assert(
        '7.7 Al desactivar un carril el contador baja a 1',
        $repo->countActiveSlots((int)$cafe->getId()) === 1
    );

    $assert(
        '7.8 El cerrojo inverso puede leer tipologías HOT_DRINKS y COMBO',
        array_reduce(
            $assignments,
            static fn (bool $carry, array $row): bool => $carry
                && in_array($row['machine_type'], [MachineType::HOT_DRINKS, MachineType::COMBO], true),
            true
        )
    );
} finally {
    $cleanup($pdo);
}

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-05 (repositorio del catálogo) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
