<?php

declare(strict_types=1);

/**
 * PdoMachineRepositoryTest
 * 
 * Test de Integración para PdoMachineRepository (Tarea T-12).
 * Verifica la obtención de máquinas por sede y la detección de avisos activos o en garantía.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Core\Domain\Model\Machine;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoMachineRepositoryTest (T-12)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpiar incidencias previas para aislamiento del test
$pdo->exec("DELETE FROM `incident_history`");
$pdo->exec("DELETE FROM `incidents`");

// Asegurar semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);

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

$loc1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("1. Sede SEDE-BCN-01 encontrada para el test", $loc1 !== null);

if ($loc1 !== null) {
    $locId = $loc1->getId();

    // =====================================================================
    // CASO 1: Listado inicial de máquinas sin incidencias activas
    // =====================================================================
    echo "\n--- Caso 1: Listado inicial sin incidencias activas ---\n";
    $machines = $machineRepo->findActiveByLocationId($locId);

    $assert("1.1 findActiveByLocationId() devuelve al menos 2 máquinas en SEDE-BCN-01", count($machines) >= 2);

    $m101 = null;
    $m102 = null;
    foreach ($machines as $m) {
        if ($m->getCode() === 'VEND-0101') $m101 = $m;
        if ($m->getCode() === 'VEND-0102') $m102 = $m;
    }

    $assert("1.2 VEND-0101 y VEND-0102 encontradas", $m101 !== null && $m102 !== null);
    $assert("1.3 Inicialmente VEND-0101 no tiene aviso activo (active_incident es null)", $m101 !== null && !$m101->hasActiveIncident());
    $assert("1.4 VEND-0101 clasificada como PERISHABLE_FOOD", $m101 !== null && $m101->getMachineType()->isPerishable());

    // =====================================================================
    // CASO 2: Detección de aviso activo (REGISTRADA)
    // =====================================================================
    echo "\n--- Caso 2: Detección de aviso activo (ticket_code y status) ---\n";
    $testTicketCode = 'INC-2026-TEST-T12';

    // Limpiar previo si existiera
    $pdo->prepare("DELETE FROM `incidents` WHERE `ticket_code` = :tc")->execute([':tc' => $testTicketCode]);

    // Insertar incidencia de prueba en estado REGISTERED
    $stmt = $pdo->prepare("
        INSERT INTO `incidents` (
            `ticket_code`, `machine_id`, `location_id`, `category`, `description`, `urgency`, `status`
        ) VALUES (
            :tc, :mid, :lid, 'TEMPERATURE_COLD', 'Fallo de prueba en refrigeración', 'CRITICAL', 'REGISTERED'
        )
    ");
    $stmt->execute([
        ':tc'  => $testTicketCode,
        ':mid' => $m101->getId(),
        ':lid' => $locId,
    ]);
    $incidentId = (int)$pdo->lastInsertId();

    // Reconsultar máquinas de la sede
    $machinesWithIncident = $machineRepo->findActiveByLocationId($locId);
    $m101WithInc = null;
    $m102WithInc = null;
    foreach ($machinesWithIncident as $m) {
        if ($m->getCode() === 'VEND-0101') $m101WithInc = $m;
        if ($m->getCode() === 'VEND-0102') $m102WithInc = $m;
    }

    $assert("2.1 VEND-0101 detecta incidencia activa (hasActiveIncident === true)", $m101WithInc !== null && $m101WithInc->hasActiveIncident());
    
    $incData = $m101WithInc ? $m101WithInc->getActiveIncident() : null;
    $assert("2.2 active_incident contiene ticket_code esperado ('{$testTicketCode}')", ($incData['ticket_code'] ?? null) === $testTicketCode);
    $assert("2.3 active_incident contiene status esperado ('REGISTERED')", ($incData['status'] ?? null) === 'REGISTERED');
    $assert("2.4 VEND-0102 permanece sin incidencia activa", $m102WithInc !== null && !$m102WithInc->hasActiveIncident());

    // =====================================================================
    // CASO 3: Detección de aviso en garantía (RESOLVED < 48h)
    // =====================================================================
    echo "\n--- Caso 3: Detección de aviso en garantía (RESOLVED < 48h) ---\n";
    $pdo->prepare("
        UPDATE `incidents` 
        SET `status` = 'RESOLVED', 
            `resolved_at` = CURRENT_TIMESTAMP 
        WHERE `id` = :id
    ")->execute([':id' => $incidentId]);

    $machinesInWarranty = $machineRepo->findActiveByLocationId($locId);
    $m101InWarranty = null;
    foreach ($machinesInWarranty as $m) {
        if ($m->getCode() === 'VEND-0101') $m101InWarranty = $m;
    }

    $assert("3.1 VEND-0101 detecta ticket en garantía", $m101InWarranty !== null && $m101InWarranty->hasActiveIncident());
    $incDataWarranty = $m101InWarranty ? $m101InWarranty->getActiveIncident() : null;
    $assert("3.2 status en garantía es 'RESOLVED'", ($incDataWarranty['status'] ?? null) === 'RESOLVED');
    $assert("3.3 is_in_warranty es true", ($incDataWarranty['is_in_warranty'] ?? false) === true);

    // =====================================================================
    // CASO 4: Cierre definitivo (CLOSED libera la máquina)
    // =====================================================================
    echo "\n--- Caso 4: Cierre definitivo (CLOSED inactiva el aviso en la máquina) ---\n";
    $pdo->prepare("
        UPDATE `incidents` 
        SET `status` = 'CLOSED', 
            `closed_at` = CURRENT_TIMESTAMP 
        WHERE `id` = :id
    ")->execute([':id' => $incidentId]);

    $machinesAfterClosed = $machineRepo->findActiveByLocationId($locId);
    $m101AfterClosed = null;
    foreach ($machinesAfterClosed as $m) {
        if ($m->getCode() === 'VEND-0101') $m101AfterClosed = $m;
    }

    $assert("4.1 VEND-0101 ya NO reporta aviso activo tras CLOSED (active_incident es null)", $m101AfterClosed !== null && !$m101AfterClosed->hasActiveIncident());

    // Limpieza de datos de prueba
    $pdo->prepare("DELETE FROM `incidents` WHERE `id` = :id")->execute([':id' => $incidentId]);

    // =====================================================================
    // CASO 5: Creación de nueva máquina (create)
    // =====================================================================
    echo "\n--- Caso 5: Creación de máquina (create) ---\n";
    $testCode = 'VEND-TEST-9901';
    $pdo->prepare("DELETE FROM `machines` WHERE `code` = :c")->execute([':c' => $testCode]);

    $newMachine = $machineRepo->create([
        'location_id'  => $locId,
        'code'         => $testCode,
        'model'        => 'Bianchi Vending BVM 952',
        'machine_type' => 'PERISHABLE_FOOD',
        'floor_wing'   => 'Planta 3 - Comedor Este',
        'notes'        => 'Requiere mantenimiento quincenal',
    ]);

    $assert("5.1 create() devuelve instancia de Machine", $newMachine instanceof Machine);
    $assert("5.2 Código coincide en mayúsculas", $newMachine->getCode() === $testCode);
    $assert("5.3 Tipología es PERISHABLE_FOOD", $newMachine->getMachineType()->value === 'PERISHABLE_FOOD');
    $assert("5.4 Es perecedera (isPerishable === true)", $newMachine->getMachineType()->isPerishable());
    $assert("5.5 Está activa por defecto", $newMachine->isActive());

    $createdId = $newMachine->getId();

    // =====================================================================
    // CASO 6: Actualización de datos descriptivos y tipo (update)
    // =====================================================================
    echo "\n--- Caso 6: Actualización de datos (update) ---\n";
    $updatedOk = $machineRepo->update($createdId, [
        'model'        => 'Bianchi Vending BVM 952 V2',
        'machine_type' => 'COMBO',
        'floor_wing'   => 'Planta 3 - Sala de Profesores',
        'notes'        => 'Mantenimiento mensual actualizado',
    ]);
    $assert("6.1 update() retorna true", $updatedOk === true);

    $reloaded = $machineRepo->findById($createdId);
    $assert("6.2 Modelo actualizado", $reloaded !== null && $reloaded->getModel() === 'Bianchi Vending BVM 952 V2');
    $assert("6.3 Tipología cambiada a COMBO", $reloaded !== null && $reloaded->getMachineType()->value === 'COMBO');
    $assert("6.4 Ubicación interna actualizada", $reloaded !== null && $reloaded->getFloorWing() === 'Planta 3 - Sala de Profesores');

    // =====================================================================
    // CASO 7: Traslado de máquina entre sedes (transfer)
    // =====================================================================
    echo "\n--- Caso 7: Traslado entre sedes (transfer) ---\n";
    $loc2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
    $assert("7.0 Sede de destino SEDE-BCN-02 encontrada", $loc2 !== null);
    if ($loc2 !== null) {
        $loc2Id = $loc2->getId();
        $transferredOk = $machineRepo->transfer($createdId, $loc2Id, 'Planta Baja - Recepción', 'Traslado de prueba');
        $assert("7.1 transfer() retorna true", $transferredOk === true);

        $mTransferred = $machineRepo->findById($createdId);
        $assert("7.2 Nueva sede asignada correctamente", $mTransferred !== null && $mTransferred->getLocationId() === $loc2Id);
        $assert("7.3 Nueva planta/ala asignada correctamente", $mTransferred !== null && $mTransferred->getFloorWing() === 'Planta Baja - Recepción');
    }

    // =====================================================================
    // CASO 8: Comprobación atómica hasActiveTicketOrWarranty
    // =====================================================================
    echo "\n--- Caso 8: hasActiveTicketOrWarranty y getActiveTicketOrWarranty ---\n";
    $assert("8.1 Inicialmente sin tickets activos ni en garantía", $machineRepo->hasActiveTicketOrWarranty($createdId) === false);
    $assert("8.2 getActiveTicketOrWarranty es null", $machineRepo->getActiveTicketOrWarranty($createdId) === null);

    // Insertar ticket en progreso
    $ticketCodeTest = 'INC-TEST-ATW-01';
    $pdo->prepare("DELETE FROM `incidents` WHERE `ticket_code` = :tc")->execute([':tc' => $ticketCodeTest]);
    $stmt = $pdo->prepare("
        INSERT INTO `incidents` (`ticket_code`, `machine_id`, `location_id`, `category`, `description`, `urgency`, `status`)
        VALUES (:tc, :mid, :lid, 'PAYMENT_FAILURE', 'Fallo validador monedas', 'MEDIUM', 'IN_PROGRESS')
    ");
    $stmt->execute([':tc' => $ticketCodeTest, ':mid' => $createdId, ':lid' => $locId]);
    $incAtwId = (int)$pdo->lastInsertId();

    $assert("8.3 hasActiveTicketOrWarranty detecta ticket IN_PROGRESS", $machineRepo->hasActiveTicketOrWarranty($createdId) === true);
    $ticketInfo = $machineRepo->getActiveTicketOrWarranty($createdId);
    $assert("8.4 getActiveTicketOrWarranty retorna ticket_code correcto", ($ticketInfo['ticket_code'] ?? null) === $ticketCodeTest);
    $assert("8.5 getActiveTicketOrWarranty retorna status IN_PROGRESS", ($ticketInfo['status'] ?? null) === 'IN_PROGRESS');

    // Cambiar a RESOLVED (en garantía de 48h)
    $pdo->prepare("UPDATE `incidents` SET `status` = 'RESOLVED', `resolved_at` = CURRENT_TIMESTAMP WHERE `id` = :id")
        ->execute([':id' => $incAtwId]);
    $assert("8.6 hasActiveTicketOrWarranty detecta ticket en garantía (RESOLVED)", $machineRepo->hasActiveTicketOrWarranty($createdId) === true);

    // Cambiar a CLOSED
    $pdo->prepare("UPDATE `incidents` SET `status` = 'CLOSED', `closed_at` = CURRENT_TIMESTAMP WHERE `id` = :id")
        ->execute([':id' => $incAtwId]);
    $assert("8.7 hasActiveTicketOrWarranty retorna false tras CLOSED", $machineRepo->hasActiveTicketOrWarranty($createdId) === false);
    $assert("8.8 getActiveTicketOrWarranty retorna null tras CLOSED", $machineRepo->getActiveTicketOrWarranty($createdId) === null);

    // Limpieza de ticket
    $pdo->prepare("DELETE FROM `incidents` WHERE `id` = :id")->execute([':id' => $incAtwId]);

    // =====================================================================
    // CASO 9: Baja lógica (softDelete) y reactivación con reubicación (restoreWithLocation)
    // =====================================================================
    echo "\n--- Caso 9: softDelete y restoreWithLocation ---\n";
    $softDeletedOk = $machineRepo->softDelete($createdId);
    $assert("9.1 softDelete() retorna true", $softDeletedOk === true);

    // findById sin allowDeleted no debe encontrarla
    $assert("9.2 findById() convencional no encuentra la máquina borrada", $machineRepo->findById($createdId, false, false) === null);
    // findById con allowDeleted sí debe encontrarla
    $mSoftDeleted = $machineRepo->findById($createdId, false, true);
    $assert("9.3 findById(allowDeleted=true) recupera la máquina", $mSoftDeleted !== null);
    $assert("9.4 is_active es false", $mSoftDeleted !== null && !$mSoftDeleted->isActive());

    // Reactivar con reubicación
    $restoredOk = $machineRepo->restoreWithLocation($createdId, $locId, 'Planta 1 - Acceso Principal');
    $assert("9.5 restoreWithLocation() retorna true", $restoredOk === true);

    $mRestored = $machineRepo->findById($createdId);
    $assert("9.6 Máquina vuelve a encontrarse activa", $mRestored !== null && $mRestored->isActive());
    $assert("9.7 Reubicada a sede original", $mRestored !== null && $mRestored->getLocationId() === $locId);
    $assert("9.8 Reubicada a nueva planta/ala", $mRestored !== null && $mRestored->getFloorWing() === 'Planta 1 - Acceso Principal');

    // =====================================================================
    // CASO 10: Listado integral con filtros (findAll)
    // =====================================================================
    echo "\n--- Caso 10: Listado con filtros (findAll) ---\n";
    $allMachines = $machineRepo->findAll();
    $assert("10.1 findAll() devuelve listado no vacío", count($allMachines) > 0);

    // Verificar estructura del primer elemento
    $first = $allMachines[0];
    $assert("10.2 Contrato contiene 'id' y 'code'", isset($first['id'], $first['code']));
    $assert("10.3 Contrato contiene 'machine_type_label' e 'is_perishable'", isset($first['machine_type_label'], $first['is_perishable']));
    $assert("10.4 Contrato contiene 'location_name' y 'location_site_code'", isset($first['location_name'], $first['location_site_code']));
    $assert("10.5 Contrato contiene 'has_active_ticket' e 'is_in_warranty'", isset($first['has_active_ticket'], $first['is_in_warranty']));

    // Filtro por sede
    $byLoc = $machineRepo->findAll(['location_id' => $locId]);
    $assert("10.6 Filtro por location_id contiene sólo máquinas de esa sede", count($byLoc) >= 2);
    $allMatchLoc = true;
    foreach ($byLoc as $bm) {
        if ($bm['location_id'] !== $locId) {
            $allMatchLoc = false;
        }
    }
    $assert("10.7 Todas las máquinas coinciden con la sede filtrada", $allMatchLoc);

    // Filtro por tipología
    $byType = $machineRepo->findAll(['machine_type' => 'COMBO']);
    $assert("10.8 Filtro por machine_type retorna resultados", count($byType) >= 1);

    // Filtro por búsqueda
    $bySearch = $machineRepo->findAll(['search' => $testCode]);
    $assert("10.9 Filtro search por código encuentra la máquina de prueba", count($bySearch) === 1 && $bySearch[0]['code'] === $testCode);

    // Limpieza final de máquina de prueba
    $pdo->prepare("DELETE FROM `machines` WHERE `id` = :id")->execute([':id' => $createdId]);
}

// Restaurar métricas de demo si existe el seeder
if (file_exists(__DIR__ . '/../../database/DemoMetricsSeeder.php')) {
    require_once __DIR__ . '/../../database/DemoMetricsSeeder.php';
    $demoSeeder = new \VendGuard\Database\DemoMetricsSeeder($pdo);
    $demoSeeder->seed();
}

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ADM-05 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} COMPROBACIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
