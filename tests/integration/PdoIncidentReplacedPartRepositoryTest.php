<?php

declare(strict_types=1);

/**
 * PdoIncidentReplacedPartRepositoryTest
 * 
 * Test de Integración para PdoIncidentReplacedPartRepository (Tarea T-SPARE-06).
 * Valida la persistencia transaccional de piezas sustituidas con snapshot inmutable de coste (RF-REP-06),
 * agregaciones de costes por modelo/sede, ranking de consumo y alertas de fallo crónico (>3 sustituciones en 90 días).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\OldPartDestination;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoIncidentReplacedPartRepositoryTest (T-SPARE-06)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$replacedRepo = new PdoIncidentReplacedPartRepository($pdo);
$sparePartRepo = new PdoSparePartRepository($pdo);

// Obtener máquina, sede y técnico base
$machine = $pdo->query("SELECT id, location_id FROM `machines` WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$tech = $pdo->query("SELECT id FROM `users` WHERE role = 'TECHNICIAN' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$machine || !$tech) {
    echo "  [ERROR] Se requiere al menos 1 máquina y 1 técnico para el test.\n";
    exit(1);
}

// Asegurar que existe una incidencia con técnico asignado
$incStmt = $pdo->query("SELECT id, machine_id, location_id, assigned_technician_id FROM `incidents` WHERE assigned_technician_id IS NOT NULL LIMIT 1");
$inc = $incStmt->fetch(PDO::FETCH_ASSOC);

if (!$inc) {
    $stmt = $pdo->prepare("
        INSERT INTO `incidents` (
            `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
            `reporter_name`, `reporter_phone`, `category`, `description`, `urgency`,
            `status`, `assigned_at`, `started_at`, `created_at`, `updated_at`
        ) VALUES (
            'INC-TEST-REP06', :machine_id, :location_id, :tech_id,
            'Tester', '600000000', 'OTHER', 'Test de sustitución de repuestos para T-SPARE-06', 'MEDIUM',
            'IN_PROGRESS', NOW(), NOW(), NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':machine_id' => $machine['id'],
        ':location_id' => $machine['location_id'],
        ':tech_id' => $tech['id'],
    ]);
    $incId = (int)$pdo->lastInsertId();
    $machineId = (int)$machine['id'];
    $locId = (int)$machine['location_id'];
    $techId = (int)$tech['id'];
} else {
    $incId = (int)$inc['id'];
    $machineId = (int)$inc['machine_id'];
    $locId = (int)$inc['location_id'];
    $techId = (int)$inc['assigned_technician_id'];
}

// Asegurar orden preventiva existente
$poStmt = $pdo->query("SELECT id, machine_id, location_id, assigned_technician_id FROM `preventive_orders` WHERE assigned_technician_id IS NOT NULL LIMIT 1");
$po = $poStmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    $stmtPo = $pdo->prepare("
        INSERT INTO `preventive_orders` (
            `order_code`, `machine_id`, `location_id`, `assigned_technician_id`,
            `status`, `maintenance_type`, `due_date`, `created_at`, `updated_at`
        ) VALUES (
            'ORD-TEST-REP06', :machine_id, :location_id, :tech_id,
            'IN_PROGRESS', 'ROUTINE', CURRENT_DATE(), NOW(), NOW()
        )
    ");
    $stmtPo->execute([
        ':machine_id' => $machineId,
        ':location_id' => $locId,
        ':tech_id' => $techId,
    ]);
    $poId = (int)$pdo->lastInsertId();
} else {
    $poId = (int)$po['id'];
}

// Obtener repuesto de catálogo
$part = $sparePartRepo->findByCode('VALV-ULKA-01');
if ($part === null || $part->getId() === null) {
    echo "  [ERROR] No se encontró el repuesto VALV-ULKA-01.\n";
    exit(1);
}
$partId = $part->getId();

// Limpiar consumos previos del test
$pdo->prepare("DELETE FROM `incident_replaced_parts` WHERE `incident_id` = ? OR `preventive_order_id` = ?")->execute([$incId, $poId]);

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
// CASO 1: Inserción individual de pieza con snapshot inmutable (insertReplacedPart)
// =====================================================================
$part1 = new IncidentReplacedPart(
    null,
    'INCIDENT',
    $incId,
    null,
    $machineId,
    $locId,
    $techId,
    $partId,
    false,
    null,
    2,
    28.50, // Snapshot unitario
    OldPartDestination::TALLER,
    'Bobina eléctrica en buen estado, enviada a taller para reciclaje'
);

$inserted1 = $replacedRepo->insertReplacedPart($part1);
$assert("1.1 insertReplacedPart genera ID autoincremental", $inserted1->getId() !== null && $inserted1->getId() > 0);
$assert("1.2 Snapshot unitario congelado a 28.50", abs($inserted1->getUnitCostSnapshot() - 28.50) < 0.001);
$assert("1.3 Total snapshot congelado a 57.00 (2 * 28.50)", abs($inserted1->getTotalCostSnapshot() - 57.00) < 0.001);
$assert("1.4 Destino retirado es TALLER", $inserted1->getOldPartDestination() === OldPartDestination::TALLER);

// =====================================================================
// CASO 2: Inserción en lote (insertManyReplacedParts) incluyendo fuera de catálogo
// =====================================================================
$part2Custom = new IncidentReplacedPart(
    null,
    'INCIDENT',
    $incId,
    null,
    $machineId,
    $locId,
    $techId,
    null,
    true,
    'Tubo de teflón 4x6mm reforzado a medida',
    1,
    7.50,
    OldPartDestination::DESGUACE,
    'Sustituido tramo quemado'
);

$batchResults = $replacedRepo->insertManyReplacedParts([$part2Custom]);
$assert("2.1 insertManyReplacedParts inserta 1 elemento en lote", count($batchResults) === 1);
$assert("2.2 Pieza fuera de catálogo tiene isOutOfCatalog=true", $batchResults[0]->isOutOfCatalog() === true);
$assert("2.3 customPartName persistido correctamente", $batchResults[0]->getCustomPartName() === 'Tubo de teflón 4x6mm reforzado a medida');
$assert("2.4 Destino de pieza fuera de catálogo es DESGUACE", $batchResults[0]->getOldPartDestination() === OldPartDestination::DESGUACE);

// =====================================================================
// CASO 3: Búsqueda individual por ID con enriquecimiento relacional (findById)
// =====================================================================
$found = $replacedRepo->findById((int)$inserted1->getId());
$assert("3.1 findById recupera la pieza", $found !== null && $found->getId() === $inserted1->getId());
$assert("3.2 Enriquecido con part_code", $found !== null && $found->getPartCode() === 'VALV-ULKA-01');
$assert("3.3 Enriquecido con part_name", $found !== null && str_contains($found->getPartName() ?? '', 'Electroválvula'));
$assert("3.4 Enriquecido con machine_code", $found !== null && !empty($found->getMachineCode()));
$assert("3.5 Enriquecido con machine_model", $found !== null && !empty($found->getMachineModel()));
$assert("3.6 Enriquecido con location_name", $found !== null && !empty($found->getLocationName()));
$assert("3.7 Enriquecido con technician_name", $found !== null && !empty($found->getTechnicianName()));

// =====================================================================
// CASO 4: Consulta por incidencia (findByIncidentId)
// =====================================================================
$incidentParts = $replacedRepo->findByIncidentId($incId);
$assert("4.1 findByIncidentId devuelve 2 piezas sustituidas", count($incidentParts) === 2);
$assert("4.2 Primera pieza es de catálogo", $incidentParts[0]->isOutOfCatalog() === false);
$assert("4.3 Segunda pieza es fuera de catálogo", $incidentParts[1]->isOutOfCatalog() === true);

// =====================================================================
// CASO 5: Consulta por preventivo (findByPreventiveOrderId)
// =====================================================================
if ($poId !== null) {
    $prevPart = new IncidentReplacedPart(
        null,
        'PREVENTIVE',
        null,
        $poId,
        $machineId,
        $locId,
        $techId,
        $partId,
        false,
        null,
        1,
        28.50,
        OldPartDestination::DESGUACE,
        'Sustitución preventiva de juntas'
    );
    $insertedPrev = $replacedRepo->insertReplacedPart($prevPart);
    $prevParts = $replacedRepo->findByPreventiveOrderId($poId);
    $assert("5.1 findByPreventiveOrderId recupera pieza de preventivo", count($prevParts) >= 1 && $prevParts[0]->getPreventiveOrderId() === $poId);
}

// =====================================================================
// CASO 6: Agregaciones de coste por modelo de máquina (getCostSummaryByMachineModel)
// =====================================================================
$modelSummary = $replacedRepo->getCostSummaryByMachineModel();
$assert("6.1 getCostSummaryByMachineModel devuelve al menos un modelo", count($modelSummary) >= 1);
$firstModel = $modelSummary[0];
$assert("6.2 Agregación contiene model, machines_count, units_replaced, total_cost",
    isset($firstModel['model'], $firstModel['machines_count'], $firstModel['units_replaced'], $firstModel['total_cost']));
$assert("6.3 Total cost es mayor a 0", $firstModel['total_cost'] > 0);

// =====================================================================
// CASO 7: Agregaciones de coste por sede (getCostSummaryByLocation)
// =====================================================================
$locSummary = $replacedRepo->getCostSummaryByLocation();
$assert("7.1 getCostSummaryByLocation devuelve al menos una sede", count($locSummary) >= 1);
$firstLoc = $locSummary[0];
$assert("7.2 Agregación de sede contiene location_id, location_name, units_replaced, total_cost",
    isset($firstLoc['location_id'], $firstLoc['location_name'], $firstLoc['units_replaced'], $firstLoc['total_cost']));

// =====================================================================
// CASO 8: Ranking de piezas más sustituidas (getTopReplacedParts)
// =====================================================================
$topParts = $replacedRepo->getTopReplacedParts(limit: 5);
$assert("8.1 getTopReplacedParts devuelve ranking", count($topParts) >= 1);
$top1 = $topParts[0];
$assert("8.2 Ranking contiene desglose de destinos DESGUACE y TALLER",
    isset($top1['destinations']['DESGUACE'], $top1['destinations']['TALLER']));

// =====================================================================
// CASO 9: Detección de fallos crónicos (> 3 sustituciones en 90 días)
// =====================================================================
// Insertamos 3 sustituciones adicionales de la misma pieza en la misma máquina para superar el umbral de 3 (>3 = 4 o más)
for ($i = 0; $i < 3; $i++) {
    $chronicPart = new IncidentReplacedPart(
        null,
        'INCIDENT',
        $incId,
        null,
        $machineId,
        $locId,
        $techId,
        $partId,
        false,
        null,
        1,
        28.50,
        OldPartDestination::DESGUACE,
        "Sustitución repetitiva {$i} para test de fallo crónico",
        date('Y-m-d H:i:s', strtotime("-" . ($i * 10) . " days"))
    );
    $replacedRepo->insertReplacedPart($chronicPart);
}

$alerts = $replacedRepo->findChronicFailureAlerts(windowDays: 90, threshold: 3);
$assert("9.1 findChronicFailureAlerts detecta la máquina con fallo crónico (>3 sustituciones)", count($alerts) >= 1);

if (!empty($alerts)) {
    $firstAlert = $alerts[0];
    $assert("9.2 Alerta contiene replacements_in_period >= 4", $firstAlert['replacements_in_period'] >= 4);
    $assert("9.3 Alerta contiene warning_message estructurado", str_contains($firstAlert['warning_message'], 'Componente con Fallo Recurrente'));
    $assert("9.4 Alerta contiene first_replacement_at y last_replacement_at",
        !empty($firstAlert['first_replacement_at']) && !empty($firstAlert['last_replacement_at']));
}

// =====================================================================
// CASO 10: Consulta para exportación CSV (findAllForExport)
// =====================================================================
$exportRows = $replacedRepo->findAllForExport();
$assert("10.1 findAllForExport devuelve registros de consumos", count($exportRows) >= 1);
$firstExport = $exportRows[0];
$assert("10.2 Fila de exportación contiene todas las columnas requeridas por RF-REP-09",
    isset(
        $firstExport['fecha'],
        $firstExport['codigo_intervencion'],
        $firstExport['tipo_intervencion'],
        $firstExport['codigo_maquina'],
        $firstExport['modelo_maquina'],
        $firstExport['sede'],
        $firstExport['codigo_pieza'],
        $firstExport['nombre_pieza'],
        $firstExport['categoria'],
        $firstExport['unidades'],
        $firstExport['coste_unitario_eur'],
        $firstExport['coste_total_eur'],
        $firstExport['destino_retirado'],
        $firstExport['codigo_tecnico'],
        $firstExport['notas']
    )
);

// Limpieza de datos de prueba
$pdo->prepare("DELETE FROM `incident_replaced_parts` WHERE `incident_id` = ? OR `preventive_order_id` = ?")->execute([$incId, $poId ?? 0]);

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de PdoIncidentReplacedPartRepository pasaron con éxito.\n";
    exit(0);
} else {
    echo "Resultado: [FALLO] Se detectaron {$failures} fallos en el repositorio.\n";
    exit(1);
}
