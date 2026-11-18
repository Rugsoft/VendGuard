<?php

declare(strict_types=1);

/**
 * PdoMachinePlanogramRepositoryTest · T-PLN-06
 *
 * Suite de integración del planograma contra PostgreSQL real (Módulo 32,
 * RF-02.1–RF-02.3, RF-05.2, C1, C2, QA 2, QA 3, Art. III.1).
 *
 * Certifica:
 *   1. Lectura de la máquina y de sus carriles con semáforo, déficit y llenado.
 *   2. Orden físico `QA 3`: numéricos por valor entero y después alfanuméricos.
 *   3. `UPSERT` por `(machine_id, slot_code)` sin duplicar filas ni borrar.
 *   4. Reemplazo completo del mapa con baja lógica de los carriles ausentes.
 *   5. Resumen determinista: activos, agotados, bajo umbral, ok, productos en
 *      baja, capacidad, existencia y porcentaje de llenado.
 *   6. Perímetro del reponedor `QA 2`: sólo máquinas de zonas vivas asignadas
 *      (`removed_at IS NULL` + `operational_zones.is_active = TRUE`).
 *   7. Opacidad estructural de `unit_cost_eur` en las filas del planograma.
 *   8. El disparador anti-borrado físico rechaza cualquier `DELETE`.
 *
 * La suite crea una referencia `TEST-PLN-T6HOT`, carriles numéricos y `T6HOP`
 * en la máquina 2, y restaura cualquier fila semilla que toque (zona canónica
 * `ZONA-BCN-CENTRO`, asignación de la reponedora titular y estado de la máquina 8)
 * en el `finally`. La zona se resuelve por código, nunca por un ID de secuencia.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

echo "======================================================================\n";
echo " VendGuard · Integración · PdoMachinePlanogramRepositoryTest (T-PLN-06)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$repo = new PdoMachinePlanogramRepository($pdo);
$productRepo = new PdoVendingProductRepository($pdo);

const TEST_MACHINE_ID = 2;       // VEND-0102 · HOT_DRINKS

// Los identificadores de usuario no son estables entre re-siembras: el vínculo
// canónico es el correo del cuadrante, igual que la zona se resuelve por código.
$restockerOneId = (int)$pdo->query(
    "SELECT id FROM users WHERE email = 'lucia.repostaje@vendguard.internal'"
)->fetchColumn();
$restockerTwoId = (int)$pdo->query(
    "SELECT id FROM users WHERE email = 'oscar.repostaje@vendguard.internal'"
)->fetchColumn();

// La secuencia de `operational_zones` avanza con cada re-siembra: el vínculo
// canónico es el código, tal como declara `supabase_seeds.sql`.
$zoneId = (int)$pdo->query("SELECT id FROM operational_zones WHERE code = 'ZONA-BCN-CENTRO'")->fetchColumn();

$cleanup = static function (PDO $pdo) use ($zoneId, $restockerOneId): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec("
            DELETE FROM machine_planogram_slots
            WHERE product_id IN (SELECT id FROM vending_products WHERE sku LIKE 'TEST-PLN-T6%')
        ");
        $pdo->exec("DELETE FROM vending_products WHERE sku LIKE 'TEST-PLN-T6%'");
    });

    $pdo->exec('UPDATE operational_zones SET is_active = TRUE WHERE id = ' . $zoneId);
    $pdo->exec('UPDATE restocker_zone_assignments SET removed_at = NULL WHERE restocker_id = ' . $restockerOneId);
    $pdo->exec('UPDATE machines SET is_active = TRUE WHERE id = 8');
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
    // 1. Máquina y lectura del planograma (RF-02.1)
    // =================================================================
    echo "--- 1. Máquina y carriles con semáforo ---\n";

    $machine = $repo->findMachineById(1);
    $assert(
        '1.1 findMachineById devuelve código, modelo, tipología, sede y zona',
        $machine !== null
        && $machine['code'] === 'VEND-0101'
        && $machine['model'] === 'Sanden Vendo G-Drink'
        && $machine['machine_type'] === MachineType::PERISHABLE_FOOD
        && $machine['site_code'] === 'SEDE-BCN-01'
        && $machine['zone_id'] === $zoneId
        && $machine['zone_code'] === 'ZONA-BCN-CENTRO',
        json_encode($machine)
    );

    $assert(
        '1.2 findMachineById tolera identificadores inexistentes',
        $repo->findMachineById(0) === null && $repo->findMachineById(999999) === null
    );

    $slots = $repo->findSlotsByMachine(1);
    $assert(
        '1.3 La máquina 1 tiene sus cuatro carriles activos',
        count($slots) === 4,
        (string)count($slots)
    );

    $assert(
        '1.4 Los carriles llegan ordenados físicamente (QA 3)',
        array_column($slots, 'slot_code') === ['11', '12', '13', '21']
    );

    $slot11 = $slots[0];
    $assert(
        '1.5 El carril 11 hidrata producto, semáforo, déficit y llenado',
        $slot11['product_sku'] === 'SAND-MIXTO-01'
        && $slot11['stock_status'] === 'OK'
        && $slot11['restock_needed_qty'] === 6
        && abs($slot11['fill_rate_percent'] - 40.0) < 0.01
        && $slot11['product_is_active'] === true
    );

    $assert(
        '1.6 El carril 12 está agotado y su producto exige frío',
        $slots[1]['stock_status'] === 'STOCKOUT'
        && $slots[1]['restock_needed_qty'] === 8
        && $slots[1]['requires_cold_chain'] === true
        && $slots[1]['product_category'] === ProductCategory::PERISHABLE_FOOD
    );

    $assert(
        '1.7 Ninguna fila del planograma expone unit_cost_eur (Art. V.4)',
        array_reduce(
            $slots,
            static fn (bool $carry, array $row): bool => $carry && !array_key_exists('unit_cost_eur', $row),
            true
        )
    );

    $assert(
        '1.8 findSlot recupera el carril con su producto',
        $repo->findSlot(1, '11')['slot_code'] === '11'
        && $repo->findSlot(1, '11')['product_sku'] === 'SAND-MIXTO-01'
    );
    $assert(
        '1.9 findSlot devuelve null para un código inexistente o máquina inválida',
        $repo->findSlot(1, 'NO_EXISTE') === null && $repo->findSlot(0, '11') === null
    );

    // =================================================================
    // 2. Resumen determinista (RF-02.3)
    // =================================================================
    echo "\n--- 2. Resumen del planograma ---\n";

    $summary = $repo->summarize(1);
    $assert(
        '2.1 Resumen exacto de la máquina 1 (4 carriles, 1 agotado, 3 ok)',
        $summary['active_slots_count'] === 4
        && $summary['stockout_slots_count'] === 1
        && $summary['low_stock_slots_count'] === 0
        && $summary['ok_slots_count'] === 3
        && $summary['inactive_product_slots_count'] === 0
        && $summary['total_capacity'] === 42
        && $summary['total_estimated_qty'] === 17
        && abs($summary['fill_rate_percent'] - 40.5) < 0.01,
        json_encode($summary)
    );

    $emptySummary = $repo->summarize(0);
    $assert(
        '2.2 Una máquina inválida devuelve resumen vacío sin dividir por cero',
        $emptySummary['active_slots_count'] === 0 && $emptySummary['fill_rate_percent'] === 0.0
    );

    // =================================================================
    // 3. UPSERT sin duplicar ni borrar (C1, Art. III.1)
    // =================================================================
    echo "\n--- 3. UPSERT por (machine_id, slot_code) ---\n";

    $testProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLN-T6HOT',
        'Ingrediente de prueba T6',
        ProductCategory::HOT_BEVERAGE_INGREDIENT,
        ProductUnitType::GRAMS,
        5.00,
        0.00,
        false,
        null,
        true
    ));
    $productId = (int)$testProduct->getId();

    $slot9 = new MachinePlanogramSlot(null, TEST_MACHINE_ID, '9', $productId, 1000, 0, 100, true);
    $created9 = $repo->upsertSlot($slot9);
    $assert(
        '3.1 upsertSlot crea el carril y calcula STOCKOUT con existencia cero',
        $created9['slot_code'] === '9'
        && $created9['product_sku'] === 'TEST-PLN-T6HOT'
        && $created9['stock_status'] === 'STOCKOUT'
        && $created9['restock_needed_qty'] === 1000
    );

    $updated9 = $repo->upsertSlot(new MachinePlanogramSlot(null, TEST_MACHINE_ID, '9', $productId, 1000, 500, 100, true));
    $rowsFor9 = $pdo->query(
        'SELECT COUNT(*) FROM machine_planogram_slots WHERE machine_id = ' . TEST_MACHINE_ID . " AND slot_code = '9'"
    )->fetchColumn();
    $assert(
        '3.2 Reupsertar el mismo carril actualiza la fila sin duplicarla',
        (int)$rowsFor9 === 1
        && $updated9['current_estimated_qty'] === 500
        && $updated9['stock_status'] === 'OK'
        && $updated9['id'] === $created9['id'],
        'filas=' . $rowsFor9
    );

    $repo->upsertSlot(new MachinePlanogramSlot(null, TEST_MACHINE_ID, '11', $productId, 800, 0, 100, true));
    $repo->upsertSlot(new MachinePlanogramSlot(null, TEST_MACHINE_ID, '12', $productId, 800, 80, 100, true));
    $repo->upsertSlot(new MachinePlanogramSlot(null, TEST_MACHINE_ID, 'T6HOP', $productId, 600, 100, 100, true));

    $ordered = $repo->findSlotsByMachine(TEST_MACHINE_ID);
    $assert(
        '3.3 QA 3 ordena numéricos por valor entero y después alfanuméricos',
        array_column($ordered, 'slot_code') === [
            '9', '11', '12', 'HOPPER_COFFEE', 'HOPPER_MILK', 'HOPPER_SUGAR', 'T6HOP', 'TUBE_CUPS',
        ],
        json_encode(array_column($ordered, 'slot_code'))
    );

    $assert(
        '3.4 El carril 12 queda en LOW_STOCK con existencia igual al umbral',
        $repo->findSlot(TEST_MACHINE_ID, '12')['stock_status'] === 'LOW_STOCK'
    );

    // =================================================================
    // 4. Reemplazo completo con baja lógica (C1, Art. III.1)
    // =================================================================
    echo "\n--- 4. Reemplazo del mapa completo ---\n";

    $activeBefore = $repo->findSlotsByMachine(TEST_MACHINE_ID, true);
    $keep = [];
    foreach ($activeBefore as $row) {
        if (in_array($row['slot_code'], ['11', '12'], true)) {
            continue;
        }
        $keep[] = new MachinePlanogramSlot(
            $row['id'],
            TEST_MACHINE_ID,
            $row['slot_code'],
            $row['product_id'],
            $row['max_capacity'],
            $row['current_estimated_qty'],
            $row['min_threshold'],
            true
        );
    }

    $result = $repo->replaceMachinePlanogram(TEST_MACHINE_ID, $keep);
    $resultCodes = array_column($result, 'slot_code');
    $assert(
        '4.1 El reemplazo conserva los carriles recibidos y los siembra todos activos',
        !in_array('11', $resultCodes, true)
        && !in_array('12', $resultCodes, true)
        && in_array('9', $resultCodes, true)
        && in_array('T6HOP', $resultCodes, true)
        && in_array('HOPPER_COFFEE', $resultCodes, true)
    );

    $physicalRows = $pdo->query(
        'SELECT COUNT(*) FROM machine_planogram_slots WHERE machine_id = ' . TEST_MACHINE_ID
    )->fetchColumn();
    $deactivatedRows = $pdo->query(
        'SELECT COUNT(*) FROM machine_planogram_slots WHERE machine_id = ' . TEST_MACHINE_ID . ' AND is_active = FALSE'
    )->fetchColumn();
    $assert(
        '4.2 No hay DELETE: las ocho filas siguen existiendo y dos quedan inactivas',
        (int)$physicalRows === 8 && (int)$deactivatedRows === 2,
        'filas=' . $physicalRows . ' inactivas=' . $deactivatedRows
    );

    $assert(
        '4.3 findSlotsByMachine(false) incluye los carriles retirados',
        count($repo->findSlotsByMachine(TEST_MACHINE_ID, false)) === 8
        && count($repo->findSlotsByMachine(TEST_MACHINE_ID, true)) === 6
    );

    $summary2 = $repo->summarize(TEST_MACHINE_ID);
    $assert(
        '4.4 El resumen cuenta seis carriles activos, un LOW_STOCK y llenado 60,0 %',
        $summary2['active_slots_count'] === 6
        && $summary2['stockout_slots_count'] === 0
        && $summary2['low_stock_slots_count'] === 1
        && $summary2['ok_slots_count'] === 5
        && $summary2['total_capacity'] === 6700
        && $summary2['total_estimated_qty'] === 4020
        && abs($summary2['fill_rate_percent'] - 60.0) < 0.01,
        json_encode($summary2)
    );

    // =================================================================
    // 5. Producto dado de baja y baja lógica de carril (C1)
    // =================================================================
    echo "\n--- 5. Producto en baja y retirada de carril ---\n";

    $productRepo->updateStatus($productId, false);
    $summaryAfterProduct = $repo->summarize(TEST_MACHINE_ID);
    $slot9After = $repo->findSlot(TEST_MACHINE_ID, '9');
    $assert(
        '5.1 El resumen cuenta los carriles activos con producto dado de baja',
        $summaryAfterProduct['inactive_product_slots_count'] === 2
        && $slot9After['product_is_active'] === false
    );

    $assert(
        '5.2 deactivateSlot retira el carril sin borrarlo',
        $repo->deactivateSlot(TEST_MACHINE_ID, '9') === true
        && $repo->findSlot(TEST_MACHINE_ID, '9')['is_active'] === false
        && $repo->summarize(TEST_MACHINE_ID)['inactive_product_slots_count'] === 1
    );

    $assert(
        '5.3 deactivateSlot devuelve false para un carril inexistente',
        $repo->deactivateSlot(TEST_MACHINE_ID, 'NO_EXISTE') === false
    );

    $deleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM machine_planogram_slots WHERE machine_id = " . TEST_MACHINE_ID . " AND slot_code = 'T6HOP'");
    } catch (PDOException $exception) {
        $deleteBlocked = true;
    }
    $assert(
        '5.4 El disparador anti-borrado físico rechaza el DELETE (Art. III.1)',
        $deleteBlocked === true && $repo->findSlot(TEST_MACHINE_ID, 'T6HOP') !== null
    );

    // =================================================================
    // 6. Perímetro territorial del reponedor (QA 2)
    // =================================================================
    echo "\n--- 6. Máquinas y planogramas por zona del reponedor ---\n";

    $oneZoneMachines = $repo->findMachinesForRestocker($restockerOneId);
    $oneZoneCodes = array_column($oneZoneMachines, 'code');
    $assert(
        '6.1 El reponedor de una zona ve sólo las cinco máquinas de su zona viva',
        $oneZoneCodes === ['VEND-0101', 'VEND-0102', 'VEND-0401', 'VEND-0501', 'VEND-0502'],
        json_encode($oneZoneCodes)
    );

    $assert(
        '6.2 Todas las máquinas listadas pertenecen a ZONA-BCN-CENTRO',
        array_reduce(
            $oneZoneMachines,
            static fn (bool $carry, array $row): bool => $carry
                && $row['zone_code'] === 'ZONA-BCN-CENTRO'
                && $row['zone_id'] === $zoneId,
            true
        )
    );

    $assert(
        '6.3 Cada tarjeta trae el resumen del planograma sin coste interno',
        $oneZoneMachines[0]['active_slots_count'] === 4
        && $oneZoneMachines[0]['stockout_slots_count'] === 1
        && abs($oneZoneMachines[0]['fill_rate_percent'] - 40.5) < 0.01
        && !array_key_exists('unit_cost_eur', $oneZoneMachines[0])
    );

    $assert(
        '6.4 El detalle de una máquina de su zona se resuelve; la ajena devuelve null',
        $repo->findMachineForRestocker(1, $restockerOneId) !== null
        && $repo->findMachineForRestocker(4, $restockerOneId) === null
        && $repo->findMachineForRestocker(3, $restockerOneId) === null
    );

    $twoZoneMachines = $repo->findMachinesForRestocker($restockerTwoId);
    $twoZoneCodes = array_column($twoZoneMachines, 'code');
    $twoZoneCodesSeen = array_values(array_unique(array_column($twoZoneMachines, 'zone_code')));
    sort($twoZoneCodesSeen);
    $assert(
        '6.5 El reponedor de dos zonas ve diez máquinas de ambas zonas',
        count($twoZoneMachines) === 10
        && in_array('VEND-0301', $twoZoneCodes, true)
        && in_array('VEND-0101', $twoZoneCodes, true)
        && $twoZoneCodesSeen === ['ZONA-BCN-CENTRO', 'ZONA-BCN-SUR'],
        json_encode([count($twoZoneMachines), $twoZoneCodesSeen])
    );

    $pdo->exec('UPDATE restocker_zone_assignments SET removed_at = NOW() WHERE restocker_id = ' . $restockerOneId);
    $assert(
        '6.6 Una asignación retirada deja el perímetro vacío',
        $repo->findMachinesForRestocker($restockerOneId) === []
        && $repo->findMachineForRestocker(1, $restockerOneId) === null
    );
    $pdo->exec('UPDATE restocker_zone_assignments SET removed_at = NULL WHERE restocker_id = ' . $restockerOneId);

    $pdo->exec('UPDATE operational_zones SET is_active = FALSE WHERE id = ' . $zoneId);
    $assert(
        '6.7 Una zona inactiva deja el perímetro vacío',
        $repo->findMachinesForRestocker($restockerOneId) === []
    );
    $pdo->exec('UPDATE operational_zones SET is_active = TRUE WHERE id = ' . $zoneId);

    $pdo->exec('UPDATE machines SET is_active = FALSE WHERE id = 8');
    $activeAfterMachineOff = array_column($repo->findMachinesForRestocker($restockerOneId), 'code');
    $pdo->exec('UPDATE machines SET is_active = TRUE WHERE id = 8');
    $assert(
        '6.8 Una máquina inactiva desaparece del listado del reponedor',
        !in_array('VEND-0502', $activeAfterMachineOff, true)
        && count($activeAfterMachineOff) === 4,
        json_encode($activeAfterMachineOff)
    );

    $assert(
        '6.9 Un reponedor inválido no ve máquinas',
        $repo->findMachinesForRestocker(0) === [] && $repo->findMachineForRestocker(1, 0) === null
    );
    // =================================================================
    // 7. FEFO de carril y actualización post-visita (Módulo 34, D1, QA 1)
    // =================================================================
    echo "\n--- 7. Lote vigente, semáforo FEFO y movimiento post-visita ---\n";

    $productRepo->updateStatus($productId, true);
    $today = new DateTimeImmutable('today');

    $repo->upsertSlot(new MachinePlanogramSlot(
        null, TEST_MACHINE_ID, 'FEFO_EXPIRED', $productId, 20, 10, 2, true,
        null, null, 'FEFO-B0', $today->modify('-1 day')
    ));
    $repo->upsertSlot(new MachinePlanogramSlot(
        null, TEST_MACHINE_ID, 'FEFO_SOON', $productId, 20, 10, 2, true,
        null, null, 'FEFO-B1', $today->modify('+1 day')
    ));
    $repo->upsertSlot(new MachinePlanogramSlot(
        null, TEST_MACHINE_ID, 'FEFO_FRESH', $productId, 20, 10, 2, true,
        null, null, 'FEFO-B2', $today->modify('+5 days')
    ));
    $repo->upsertSlot(new MachinePlanogramSlot(
        null, TEST_MACHINE_ID, 'FEFO_EMPTY', $productId, 20, 0, 2, true,
        null, null, 'FEFO-B3', $today->modify('-1 day')
    ));

    $expiredSlot = $repo->findSlot(TEST_MACHINE_ID, 'FEFO_EXPIRED');
    $soonSlot = $repo->findSlot(TEST_MACHINE_ID, 'FEFO_SOON');
    $freshSlot = $repo->findSlot(TEST_MACHINE_ID, 'FEFO_FRESH');
    $emptySlot = $repo->findSlot(TEST_MACHINE_ID, 'FEFO_EMPTY');

    $assert(
        '7.1 El carril caducado expone lote, fecha, EXPIRED y retirada obligatoria',
        $expiredSlot['current_batch_code'] === 'FEFO-B0'
        && $expiredSlot['current_expiration_date'] === $today->modify('-1 day')->format('Y-m-d')
        && $expiredSlot['slot_expiry_status'] === 'EXPIRED'
        && $expiredSlot['fefo_withdrawal_required'] === true
        && $expiredSlot['slot_expiry_status_label'] !== '',
        json_encode($expiredSlot)
    );
    $assert(
        '7.2 Un carril que caduca en 48 h o menos está EXPIRING_SOON',
        $soonSlot['slot_expiry_status'] === 'EXPIRING_SOON'
        && $soonSlot['fefo_withdrawal_required'] === true
    );
    $assert(
        '7.3 Un lote sano no alerta y un carril vacío nunca alerta (EARS 01.1.2)',
        $freshSlot['slot_expiry_status'] === 'OK'
        && $freshSlot['fefo_withdrawal_required'] === false
        && $emptySlot['slot_expiry_status'] === 'OK'
        && $emptySlot['fefo_withdrawal_required'] === false
    );
    $assert(
        '7.4 Ninguna fila FEFO expone coste interno (Art. V.4)',
        !array_key_exists('unit_cost_eur', $expiredSlot)
        && !array_key_exists('unit_cost_eur', $freshSlot)
    );

    $machineCard = null;
    foreach ($repo->findMachinesForRestocker($restockerOneId) as $row) {
        if ($row['code'] === 'VEND-0102') {
            $machineCard = $row;
            break;
        }
    }
    $assert(
        '7.5 La tarjeta de la máquina cuenta un caducado y uno próximo a caducar',
        $machineCard !== null
        && $machineCard['expired_slots_count'] === 1
        && $machineCard['expiring_soon_slots_count'] === 1
        && $machineCard['can_restock'] === true,
        json_encode($machineCard)
    );

    $previousSanitaryStatus = (string)$pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . TEST_MACHINE_ID
    )->fetchColumn();
    $pdo->exec("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = " . TEST_MACHINE_ID);
    $quarantinedCard = $repo->findMachineForRestocker(TEST_MACHINE_ID, $restockerOneId);
    $pdo->exec("UPDATE machines SET sanitary_status = '" . $previousSanitaryStatus . "' WHERE id = " . TEST_MACHINE_ID);
    $assert(
        '7.6 Una máquina en cuarentena no puede reponerse (Art. II)',
        $quarantinedCard !== null && $quarantinedCard['can_restock'] === false
    );

    $mixedMovement = $repo->applyVisitSlotMovement(
        TEST_MACHINE_ID, 'FEFO_FRESH', 2, 1, 4, 'fefo-b9', $today->modify('+3 days')
    );
    $assert(
        '7.7 El remanente sano conserva la caducidad más próxima y su lote (D1)',
        $mixedMovement['current_estimated_qty'] === 11
        && $mixedMovement['current_batch_code'] === 'FEFO-B9'
        && $mixedMovement['current_expiration_date'] === $today->modify('+3 days')->format('Y-m-d')
        && $mixedMovement['slot_expiry_status'] === 'OK',
        json_encode($mixedMovement)
    );

    $replacedBatch = $repo->applyVisitSlotMovement(
        TEST_MACHINE_ID, 'FEFO_EXPIRED', 10, 0, 6, 'FEFO-B4', $today->modify('+4 days')
    );
    $assert(
        '7.8 Retirar todo el remanente caducado adopta el lote cargado (D1)',
        $replacedBatch['current_estimated_qty'] === 6
        && $replacedBatch['current_batch_code'] === 'FEFO-B4'
        && $replacedBatch['current_expiration_date'] === $today->modify('+4 days')->format('Y-m-d')
    );

    $emptied = $repo->applyVisitSlotMovement(TEST_MACHINE_ID, 'FEFO_SOON', 10, 0, 0);
    $assert(
        '7.9 Vaciar el carril borra lote y caducidad sin borrar la fila (D1, Art. III.1)',
        $emptied['current_estimated_qty'] === 0
        && $emptied['current_batch_code'] === null
        && $emptied['current_expiration_date'] === null
        && $emptied['slot_expiry_status'] === 'OK'
        && $emptied['fefo_withdrawal_required'] === false
    );

    $withdrawError = null;
    try {
        $repo->applyVisitSlotMovement(TEST_MACHINE_ID, 'FEFO_FRESH', 12, 0, 0);
    } catch (PlanogramValidationException $exception) {
        $withdrawError = $exception;
    }
    $assert(
        '7.10 Retirar más de lo existente se rechaza y no altera el carril (C1)',
        $withdrawError !== null
        && $withdrawError->getErrorCode() === 'INVALID_PLANOGRAM_SLOT_BOUNDS'
        && $repo->findSlot(TEST_MACHINE_ID, 'FEFO_FRESH')['current_estimated_qty'] === 11
    );

    $overflowError = null;
    try {
        $repo->applyVisitSlotMovement(TEST_MACHINE_ID, 'FEFO_FRESH', 0, 0, 10, 'FEFO-B5', $today->modify('+6 days'));
    } catch (PlanogramValidationException $exception) {
        $overflowError = $exception;
    }
    $assert(
        '7.11 Superar la capacidad del carril se rechaza sin persistir el movimiento',
        $overflowError !== null
        && $overflowError->getErrorCode() === 'INVALID_PLANOGRAM_SLOT_BOUNDS'
        && $repo->findSlot(TEST_MACHINE_ID, 'FEFO_FRESH')['current_estimated_qty'] === 11
    );

    $pdo->beginTransaction();
    $repo->applyVisitSlotMovement(TEST_MACHINE_ID, 'FEFO_FRESH', 0, 0, 2, 'FEFO-B6', $today->modify('+7 days'));
    $pdo->rollBack();
    $assert(
        '7.12 El movimiento respeta la transacción del llamador (visita atómica)',
        $repo->findSlot(TEST_MACHINE_ID, 'FEFO_FRESH')['current_estimated_qty'] === 11
    );

    $repo->deactivateSlot(TEST_MACHINE_ID, 'FEFO_EMPTY');
    $inactiveError = null;
    try {
        $repo->applyVisitSlotMovement(TEST_MACHINE_ID, 'FEFO_EMPTY', 0, 0, 1, 'FEFO-B7', $today->modify('+8 days'));
    } catch (PlanogramValidationException $exception) {
        $inactiveError = $exception;
    }
    $assert(
        '7.13 Un carril inactivo no admite movimientos (RF-03.3)',
        $inactiveError !== null && $inactiveError->getErrorCode() === 'INVALID_PLANOGRAM_SLOT_BOUNDS'
    );

    $finalCard = $repo->findMachineForRestocker(TEST_MACHINE_ID, $restockerOneId);
    $assert(
        '7.14 Tras la visita no quedan alertas FEFO pendientes en la máquina',
        $finalCard !== null
        && $finalCard['expired_slots_count'] === 0
        && $finalCard['expiring_soon_slots_count'] === 0,
        json_encode($finalCard)
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
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-06 (repositorio del planograma) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
