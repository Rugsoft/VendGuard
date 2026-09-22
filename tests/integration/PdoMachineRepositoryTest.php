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
}

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-12 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} COMPROBACIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
