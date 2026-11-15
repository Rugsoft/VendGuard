<?php

declare(strict_types=1);

/**
 * PdoMachineRestockVisitRepositoryTest · T-RVF-06
 *
 * Certifica el repositorio del libro de visitas de reposición in situ (Módulo 34,
 * Tablas 47 y 48; RF-01.2, RF-03.3, RF-03.4, RF-05.2, D4, C1, C4, QA 3,
 * Art. III.1, Art. III.3, Art. V.4).
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Alta inmutable.** Cabecera y líneas se insertan; cualquier `UPDATE` o
 *      `DELETE` posterior lo rechaza el motor con `SQLSTATE 45000`
 *      (`prevent_restock_visit_mutation()` y `prevent_hard_delete()`).
 *   2. **Idempotencia `D4`.** `findByClientMutationId()` devuelve la visita ya
 *      registrada y un segundo `INSERT` de la misma mutación choca con el índice
 *      único `(restocker_id, client_mutation_id)` (`23505`).
 *   3. **Opacidad de costes (Art. V.4, QA 3).** El expediente del reponedor y su
 *      historial no contienen `unit_cost_snapshot_eur` ni
 *      `commercial_spoilage_loss_eur`; Coordinación los recibe solo con
 *      `includeCosts = true`.
 *   4. **Cinco KPIs (RF-05.2).** El listado de Coordinación agrega visitas
 *      completadas, abortadas, unidades cargadas, retiradas y merma comercial con
 *      los filtros aplicados.
 *   5. **Pre-cálculo `C4`.** La agregación por producto/lote desde el
 *      `confirmed_at` de la orden suma exactamente las cargas y retiradas de las
 *      visitas creadas, sin mezclar otras órdenes.
 *   6. **Filtros.** Estado, máquina, reponedor y rango de fechas `Y-m-d`; las
 *      fechas inválidas o un estado desconocido se rechazan en voz alta.
 *
 * AISLAMIENTO: las dos visitas de prueba usan el código `MRV-2026-96NN` y se
 * purgan en el `finally` con la ventana de purga del arnés, porque las tablas son
 * inmutables incluso para el propio test. Se reutilizan la máquina 1, sus
 * carriles 11/12, la reponedora titular de la zona centro y la orden
 * `PLO-2026-0001`, sin modificar ninguna fila maestra.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoMachineRestockVisitRepository;

echo "======================================================================\n";
echo " VendGuard · Integración · PdoMachineRestockVisitRepositoryTest (T-RVF-06)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$repo = new PdoMachineRestockVisitRepository($pdo);

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

$cleanup = static function (PDO $pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec("
            DELETE FROM machine_restock_visit_items
            WHERE visit_id IN (
                SELECT id FROM machine_restock_visits WHERE visit_code LIKE 'MRV-2026-96%'
            )
        ");
        $pdo->exec("DELETE FROM machine_restock_visits WHERE visit_code LIKE 'MRV-2026-96%'");
    });
};

$cleanup($pdo);

try {
    // =================================================================
    // 0. Contexto canónico
    // =================================================================
    $machineId = (int)$pdo->query("SELECT id FROM machines WHERE code = 'VEND-0101'")->fetchColumn();
    $restockerId = (int)$pdo->query(
        "SELECT id FROM users WHERE email_key = 'lucia.repostaje@vendguard.internal'"
    )->fetchColumn();
    $orderId = (int)$pdo->query(
        "SELECT id FROM product_loading_orders WHERE order_code = 'PLO-2026-0001'"
    )->fetchColumn();
    $orderConfirmedAt = (string)$pdo->query(
        "SELECT confirmed_at FROM product_loading_orders WHERE id = " . $orderId
    )->fetchColumn();

    $slot11 = $pdo->query(
        "SELECT s.id, s.product_id, s.current_estimated_qty, p.unit_cost_eur
         FROM machine_planogram_slots s
         INNER JOIN vending_products p ON p.id = s.product_id
         WHERE s.machine_id = {$machineId} AND s.slot_code = '11'"
    )->fetch(PDO::FETCH_ASSOC);
    $slot12 = $pdo->query(
        "SELECT s.id, s.product_id, s.current_estimated_qty, p.unit_cost_eur
         FROM machine_planogram_slots s
         INNER JOIN vending_products p ON p.id = s.product_id
         WHERE s.machine_id = {$machineId} AND s.slot_code = '12'"
    )->fetch(PDO::FETCH_ASSOC);
    $checklist = DailyHygieneChecklist::fromArray([
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ], MachineType::PERISHABLE_FOOD);

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    // La orden sembrada ya tiene visitas previas: el pre-cálculo C4 se mide por
    // delta contra este estado, no por un absoluto que dependería de la siembra.
    $baseline = $repo->suggestLiquidationQuantities($orderId, $restockerId);
    $baselineIndexed = [];
    foreach ($baseline as $row) {
        $baselineIndexed[$row['product_id'] . '|' . ($row['batch_code'] ?? '')] = $row;
    }

    // =================================================================
    // 1. Alta inmutable de cabecera y líneas (Art. III.1 y III.3)
    // =================================================================
    echo "--- 1. Alta inmutable de la visita ---\n";

    $visit = new MachineRestockVisit(
        null,
        'MRV-2026-9601',
        $machineId,
        $restockerId,
        RestockVisitStatus::COMPLETED,
        'rvf6-mut-0001',
        $checklist,
        3.5,
        false,
        '/uploads/restock-visits/MRV-2026-9601.jpg',
        'Reposición de prueba T-RVF-06: retirada FEFO y recarga de sándwiches.',
        $orderId,
        null,
        $now,
        $now
    );
    $persisted = $repo->insertVisit($visit);

    $assert(
        '1.1 insertVisit persiste la cabecera y devuelve su id',
        $persisted->getId() !== null && $persisted->getId() > 0,
        json_encode($persisted->getId())
    );

    $item11 = $repo->insertItem(new MachineRestockVisitItem(
        null,
        (int)$persisted->getId(),
        (int)$slot11['id'],
        '11',
        (int)$slot11['product_id'],
        (int)$slot11['current_estimated_qty'],
        2,
        1,
        4,
        (float)$slot11['unit_cost_eur'],
        'L-SAND-2602',
        (new DateTimeImmutable('today'))->modify('+2 days'),
        'L-SAND-2501',
        (new DateTimeImmutable('today'))->modify('-1 day')
    ));
    $item12 = $repo->insertItem(new MachineRestockVisitItem(
        null,
        (int)$persisted->getId(),
        (int)$slot12['id'],
        '12',
        (int)$slot12['product_id'],
        (int)$slot12['current_estimated_qty'],
        0,
        0,
        2,
        (float)$slot12['unit_cost_eur'],
        'L-ENS-2603',
        (new DateTimeImmutable('today'))->modify('+3 days')
    ));

    $assert(
        '1.2 insertItem persiste ambas líneas con su id',
        $item11->getId() !== null && $item12->getId() !== null
    );

    $stored = $repo->findById((int)$persisted->getId(), true);
    $assert(
        '1.3 El expediente recupera cabecera, checklist, máquina, sede y reponedor',
        $stored !== null
        && $stored['visit_code'] === 'MRV-2026-9601'
        && $stored['status'] === 'COMPLETED'
        && $stored['machine_code'] === 'VEND-0101'
        && $stored['location_name'] !== ''
        && $stored['restocker_name'] !== ''
        && $stored['hygiene_checklist']['cabin_spills_checked'] === true,
        json_encode($stored)
    );
    $assert(
        '1.4 Las líneas conservan la aritmética del C1 y el orden por carril',
        $stored !== null
        && count($stored['items']) === 2
        && array_column($stored['items'], 'slot_code') === ['11', '12']
        && $stored['items'][0]['qty_after'] === 5
        && $stored['items'][1]['qty_after'] === 2
        && $stored['units_added'] === 6
        && $stored['units_withdrawn'] === 3,
        json_encode($stored['items'] ?? null)
    );
    $expectedLoss = round(3 * (float)$slot11['unit_cost_eur'], 2);
    $assert(
        '1.5 La merma comercial se congela por línea y en el total (QA 3)',
        $stored !== null
        && abs($stored['items'][0]['commercial_spoilage_loss_eur'] - $expectedLoss) < 0.001
        && abs($stored['commercial_spoilage_loss_eur'] - $expectedLoss) < 0.001,
        json_encode([$stored['items'][0]['commercial_spoilage_loss_eur'] ?? null, $expectedLoss])
    );

    $updateBlocked = false;
    try {
        $pdo->exec("UPDATE machine_restock_visits SET internal_notes = 'mutada' WHERE id = " . (int)$persisted->getId());
    } catch (PDOException $exception) {
        $updateBlocked = true;
    }
    $assert(
        '1.6 El disparador de inmutabilidad rechaza el UPDATE (Art. III.3)',
        $updateBlocked
        && $repo->findById((int)$persisted->getId(), true)['internal_notes'] === 'Reposición de prueba T-RVF-06: retirada FEFO y recarga de sándwiches.'
    );

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM machine_restock_visit_items WHERE id = ' . (int)$item12->getId());
    } catch (PDOException $exception) {
        $deleteBlocked = true;
    }
    $assert(
        '1.7 El disparador anti-borrado rechaza el DELETE (Art. III.1)',
        $deleteBlocked && $repo->findById((int)$persisted->getId(), true)['items'][1]['id'] === $item12->getId()
    );

    $source = (string)file_get_contents(__DIR__ . '/../../src/Infrastructure/Repository/PdoMachineRestockVisitRepository.php');
    $assert(
        '1.8 El repositorio no contiene sentencias de UPDATE ni DELETE sobre el libro',
        stripos($source, 'UPDATE machine_restock') === false
        && stripos($source, 'DELETE FROM') === false
    );

    // =================================================================
    // 2. Idempotencia client_mutation_id (D4)
    // =================================================================
    echo "\n--- 2. Idempotencia de la cola offline ---\n";

    $byMutation = $repo->findByClientMutationId($restockerId, 'rvf6-mut-0001', false);
    $assert(
        '2.1 findByClientMutationId recupera la visita ya registrada',
        $byMutation !== null
        && $byMutation['id'] === $persisted->getId()
        && $byMutation['client_mutation_id'] === 'rvf6-mut-0001'
    );
    $assert(
        '2.2 Una mutación de otro reponedor no resuelve la visita',
        $repo->findByClientMutationId(8320, 'rvf6-mut-0001') === null
        && $repo->findByClientMutationId(0, 'rvf6-mut-0001') === null
    );

    $duplicateBlocked = false;
    try {
        $repo->insertVisit(new MachineRestockVisit(
            null,
            'MRV-2026-9601',
            $machineId,
            $restockerId,
            RestockVisitStatus::COMPLETED,
            'rvf6-mut-0001',
            $checklist,
            3.5,
            false,
            null,
            null,
            $orderId,
            null,
            $now,
            $now
        ));
    } catch (PDOException $exception) {
        $duplicateBlocked = $exception->getCode() === '23505';
    }
    $assert(
        '2.3 El índice único rechaza una segunda visita con la misma mutación',
        $duplicateBlocked
    );

    // =================================================================
    // 3. Visita abortada y su expediente sin costes para el reponedor
    // =================================================================
    echo "\n--- 3. Aborto térmico y opacidad de costes ---\n";

    $abort = $repo->insertVisit(new MachineRestockVisit(
        null,
        'MRV-2026-9602',
        $machineId,
        $restockerId,
        RestockVisitStatus::ABORTED_THERMAL_BREACH,
        'rvf6-mut-0002',
        DailyHygieneChecklist::emptyFor(MachineType::PERISHABLE_FOOD),
        7.5,
        true,
        '/uploads/restock-visits/MRV-2026-9602.jpg',
        'Apertura con 7,5 °C: máquina precintada y SAT avisado en el acto.',
        null,
        null,
        $now,
        $now
    ));
    $abortView = $repo->findById((int)$abort->getId(), false);

    $assert(
        '3.1 El aborto térmico se persiste con su protocolo y sin líneas',
        $abortView !== null
        && $abortView['status'] === 'ABORTED_THERMAL_BREACH'
        && $abortView['cabin_temp_celsius'] === 7.5
        && $abortView['precautionary_seal_applied'] === true
        && $abortView['items'] === []
        && $abortView['units_added'] === 0,
        json_encode($abortView)
    );
    $assert(
        '3.2 El expediente del reponedor no proyecta costes (Art. V.4)',
        $stored !== null
        && !array_key_exists('commercial_spoilage_loss_eur', $repo->findById((int)$persisted->getId(), false))
        && !array_key_exists('unit_cost_snapshot_eur', $repo->findById((int)$persisted->getId(), false)['items'][0])
        && !array_key_exists('commercial_spoilage_loss_eur', $repo->findById((int)$persisted->getId(), false)['items'][0])
    );

    // =================================================================
    // 4. Historial del reponedor sin costes (Art. V.4)
    // =================================================================
    echo "\n--- 4. Historial móvil ---\n";

    $history = $repo->listForRestocker($restockerId, [
        'machine_id' => $machineId,
        'date_from' => $today,
        'date_to' => $today,
    ]);
    $historyCodes = array_column($history, 'visit_code');
    $assert(
        '4.1 listForRestocker devuelve las dos visitas del día en la máquina',
        in_array('MRV-2026-9601', $historyCodes, true)
        && in_array('MRV-2026-9602', $historyCodes, true),
        json_encode($historyCodes)
    );
    $assert(
        '4.2 Ninguna fila del historial contiene campos de coste',
        array_reduce(
            $history,
            static fn (bool $carry, array $row): bool => $carry
                && !array_key_exists('unit_cost_snapshot_eur', $row)
                && !array_key_exists('commercial_spoilage_loss_eur', $row),
            true
        )
    );
    $assert(
        '4.3 El historial expone unidades cargadas y retiradas',
        array_reduce(
            $history,
            static fn (bool $carry, array $row): bool => $carry && array_key_exists('units_added', $row)
                && array_key_exists('units_withdrawn', $row),
            true
        )
    );

    // =================================================================
    // 5. Listado de Coordinación con cinco KPIs (RF-05.2)
    // =================================================================
    echo "\n--- 5. Libro de Coordinación y KPIs ---\n";

    $filters = [
        'restocker_id' => $restockerId,
        'machine_id' => $machineId,
        'date_from' => $today,
        'date_to' => $today,
    ];
    $coordinator = $repo->listForCoordinator($filters);

    $assert(
        '5.1 El listado incluye las visitas y su contexto de Coordinación',
        count($coordinator['visits']) === 2
        && $coordinator['visits'][0]['restocker_name'] !== ''
        && array_key_exists('commercial_spoilage_loss_eur', $coordinator['visits'][0]),
        json_encode($coordinator['visits'])
    );
    $assert(
        '5.2 Los KPIs cuentan una completada y una abortada',
        $coordinator['kpis']['completed_visits_count'] === 1
        && $coordinator['kpis']['aborted_thermal_visits_count'] === 1,
        json_encode($coordinator['kpis'])
    );
    $assert(
        '5.3 Los KPIs suman 6 unidades cargadas, 3 retiradas y la merma congelada',
        $coordinator['kpis']['total_units_added'] === 6
        && $coordinator['kpis']['total_units_withdrawn'] === 3
        && abs($coordinator['kpis']['total_commercial_spoilage_loss_eur'] - $expectedLoss) < 0.001,
        json_encode($coordinator['kpis'])
    );

    $abortedOnly = $repo->listForCoordinator($filters + ['status' => 'ABORTED_THERMAL_BREACH']);
    $completedOnly = $repo->listForCoordinator($filters + ['status' => 'COMPLETED']);
    $assert(
        '5.4 El filtro de estado aísla abortadas y completadas',
        count($abortedOnly['visits']) === 1
        && $abortedOnly['visits'][0]['visit_code'] === 'MRV-2026-9602'
        && count($completedOnly['visits']) === 1
        && $completedOnly['visits'][0]['visit_code'] === 'MRV-2026-9601'
    );

    $otherMachine = $repo->listForCoordinator([
        'restocker_id' => $restockerId,
        'machine_id' => 7,
        'date_from' => $today,
        'date_to' => $today,
    ]);
    $assert(
        '5.5 El filtro de máquina excluye las visitas de otra máquina',
        $otherMachine['visits'] === [] && $otherMachine['kpis']['completed_visits_count'] === 0
    );

    $invalidStatus = false;
    try {
        $repo->listForCoordinator(['status' => 'PENDING']);
    } catch (InvalidArgumentException $exception) {
        $invalidStatus = true;
    }
    $invalidDate = false;
    try {
        $repo->listForCoordinator(['date_from' => '2026-13-40']);
    } catch (InvalidArgumentException $exception) {
        $invalidDate = true;
    }
    $assert(
        '5.6 Estado o fecha inválidos se rechazan en voz alta',
        $invalidStatus && $invalidDate
    );

    // =================================================================
    // 6. Pre-cálculo C4 por producto y lote
    // =================================================================
    echo "\n--- 6. Agregación para la liquidación del Módulo 33 ---\n";

    $assert(
        '6.1 La agregación base de la orden se resuelve sin error',
        is_array($baseline)
    );

    $quantities = $repo->suggestLiquidationQuantities($orderId, $restockerId);
    $indexed = [];
    foreach ($quantities as $row) {
        $indexed[$row['product_id'] . '|' . ($row['batch_code'] ?? '')] = $row;
    }

    $sandKey = (int)$slot11['product_id'] . '|L-SAND-2602';
    $withdrawnKey = (int)$slot11['product_id'] . '|L-SAND-2501';
    $saladKey = (int)$slot12['product_id'] . '|L-ENS-2603';

    $assert(
        '6.2 El lote cargado de sándwich suma las 4 unidades de la visita',
        isset($indexed[$sandKey])
        && $indexed[$sandKey]['suggested_restocked_in_machines_qty']
            === ($baselineIndexed[$sandKey]['suggested_restocked_in_machines_qty'] ?? 0) + 4,
        json_encode([$indexed[$sandKey] ?? null, $baselineIndexed[$sandKey] ?? null])
    );
    $assert(
        '6.3 El lote retirado de sándwich suma las 3 unidades de merma',
        isset($indexed[$withdrawnKey])
        && $indexed[$withdrawnKey]['suggested_withdrawn_spoilage_qty']
            === ($baselineIndexed[$withdrawnKey]['suggested_withdrawn_spoilage_qty'] ?? 0) + 3,
        json_encode([$indexed[$withdrawnKey] ?? null, $baselineIndexed[$withdrawnKey] ?? null])
    );
    $assert(
        '6.4 La segunda línea aporta sus 2 unidades al lote de ensalada',
        isset($indexed[$saladKey])
        && $indexed[$saladKey]['suggested_restocked_in_machines_qty']
            === ($baselineIndexed[$saladKey]['suggested_restocked_in_machines_qty'] ?? 0) + 2,
        json_encode([$indexed[$saladKey] ?? null, $baselineIndexed[$saladKey] ?? null])
    );
    $assert(
        '6.5 Las cifras sugeridas crecen respecto del estado previo de la orden',
        array_sum(array_column($baseline, 'suggested_restocked_in_machines_qty')) + 6
            === array_sum(array_column($quantities, 'suggested_restocked_in_machines_qty'))
        && array_sum(array_column($baseline, 'suggested_withdrawn_spoilage_qty')) + 3
            === array_sum(array_column($quantities, 'suggested_withdrawn_spoilage_qty'))
    );
    $assert(
        '6.6 Una orden ajena o inexistente no produce agregación',
        $repo->suggestLiquidationQuantities(0, $restockerId) === []
        && $repo->suggestLiquidationQuantities(999999, $restockerId) === []
        && $repo->suggestLiquidationQuantities($orderId, 8320) === []
    );
    $assert(
        '6.7 La orden confirmada anterior a las visitas es la ventana de cálculo',
        $orderConfirmedAt !== ''
        && strtotime($orderConfirmedAt) < strtotime($now)
    );

} finally {
    $cleanup($pdo);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-RVF-06 (repositorio de visitas in situ) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
