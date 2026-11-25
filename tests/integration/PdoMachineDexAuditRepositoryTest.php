<?php

declare(strict_types=1);

/**
 * PdoMachineDexAuditRepositoryTest · T-DEX-06
 *
 * Certifica en el motor real la infraestructura del Módulo 39
 * (`39-eva-dts-dex-audit`): cerrojos y coberturas del gateway, cruces M34/M35
 * del mismo día civil `Europe/Madrid`, alta atómica de cabecera y líneas,
 * cadena de deltas `QA-2`, listado/KPIs sin `raw_payload`, lectura aislada del
 * `.dex` y anulación lógica terminal.
 *
 *   1. **Gateway (RF-03.1, QA-1).** `lockMachineContext()` bloquea la máquina y
 *      devuelve cobertura de Reponedor y Técnico, avería `TEMPERATURE_COLD`
 *      abierta y snapshots del actor; una máquina inactiva no se entrega.
 *   2. **Enlaces (D1).** Visita M34, recaudación M35 e incidencia M16 se leen
 *      bajo `FOR UPDATE` y solo si pertenecen a la máquina.
 *   3. **Cruces QA-3.** Recaudación y visita `COMPLETED` más cercanas del mismo
 *      día civil, visita anterior, cantidades por carril y carriles activos.
 *   4. **Alta y deltas (RF-04.1, QA-2).** `create()` inserta cabecera, selecciones
 *      y eventos en una transacción; `findPreviousActive()` ignora `VOIDED` y
 *      respeta `captured_at <=`; `hasLaterActive()` detecta el volcado posterior.
 *   5. **RNF-01 y KPIs.** Ningún listado, detalle o KPI contiene `raw_payload`;
 *      solo `findRawPayload()` lo entrega.
 *   6. **Anulación (Art. V.1).** `void()` sella la fila y una segunda anulación
 *      devuelve `409 DEX_AUDIT_ALREADY_VOIDED`; la huella libera la reimportación.
 *
 * CATEGORÍA: `new_engine`
 *   Exige PostgreSQL. Sin `PG_TEST_DSN` sale con código 2; no se omite en silencio.
 *
 * LIMPIEZA: esquema aislado `t_dex06_repositorio` borrado con `CASCADE` al
 * terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PdoMachineDexAuditRepositoryTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\Enum\DexAuditStatus;
use VendGuard\Core\Domain\Enum\DexImportSource;
use VendGuard\Core\Domain\Enum\DexSelectionMatchStatus;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditGateway;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const DEX06_SCHEMA = 't_dex06_repositorio';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expectConflict = static function (callable $operation, string $errorCode): bool {
    try {
        $operation();
    } catch (DexAuditConflictException $exception) {
        return $exception->getErrorCode() === $errorCode;
    } catch (Throwable $exception) {
        return false;
    }

    return false;
};

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-06 · Repositorio y gateway PDO de auditorías .dex\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = PostgresFixtureBuilder::gateOrExit();
$crash = null;
$residue = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, DEX06_SCHEMA);
    $schema = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    $assert(
        'El esquema del repositorio (DDL base + migraciones) se aplica sobre el esquema aislado',
        $schema['statements'] > 0,
        json_encode(['statements' => $schema['statements'], 'migrations' => count($schema['migrations'])])
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (zona, sede, máquina, dos visitas, dos cajas y avería) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name) VALUES ('ZONA-DEX06', 'Zona DEX 06') RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX06',
        ':site_code_key' => 'sede-dex06',
        ':name' => 'Sede de certificación DEX 06',
        ':address' => 'Carrer EVA-DTS 39, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $userStmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, :password_hash, :role, TRUE)
         RETURNING id'
    );
    $userIds = [];
    foreach ([
        'restocker' => ['Reponedora DEX 06', 'dex06.repo@gestion-vending.test', 'RESTOCKER'],
        'other_restocker' => ['Reponedor Ajeno DEX 06', 'dex06.ajeno@gestion-vending.test', 'RESTOCKER'],
        'technician' => ['Técnico DEX 06', 'dex06.tec@gestion-vending.test', 'TECHNICIAN'],
        'coordinator' => ['Coordinadora DEX 06', 'dex06.coord@gestion-vending.test', 'COORDINATOR'],
    ] as $key => [$name, $email, $role]) {
        $userStmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':email_key' => $email,
            ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
        ]);
        $userIds[$key] = (int)$userStmt->fetchColumn();
    }

    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    )->execute([':restocker_id' => $userIds['restocker'], ':zone_id' => $zoneId]);

    $pdo->prepare(
        'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:technician_id, :zone_id, TRUE)'
    )->execute([':technician_id' => $userIds['technician'], ':zone_id' => $zoneId]);

    $productStmt = $pdo->prepare(
        "INSERT INTO vending_products (sku, name, category, unit_cost_eur, sale_price_eur)
         VALUES (:sku, :name, :category, 0.50, 1.50) RETURNING id"
    );
    $productStmt->execute([':sku' => 'DEX06-COLD', ':name' => 'Agua DEX 06', ':category' => 'COLD_DRINK']);
    $productId = (int)$productStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, 'VEND-DEX06', 'Modelo DEX 06', 'COMBO', 'Planta baja')
         RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId]);
    $machineId = (int)$machineStmt->fetchColumn();

    $slotStmt = $pdo->prepare(
        'INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, is_active)
         VALUES (:machine_id, :slot_code, :product_id, 12, :qty, :is_active) RETURNING id'
    );
    $slotStmt->execute([
        ':machine_id' => $machineId,
        ':slot_code' => 'A1',
        ':product_id' => $productId,
        ':qty' => 7,
        ':is_active' => 'true',
    ]);
    $slotA1 = (int)$slotStmt->fetchColumn();
    $slotStmt->execute([
        ':machine_id' => $machineId,
        ':slot_code' => 'A2',
        ':product_id' => $productId,
        ':qty' => 0,
        ':is_active' => 'false',
    ]);
    $slotA2 = (int)$slotStmt->fetchColumn();

    $visitStmt = $pdo->prepare(
        'INSERT INTO machine_restock_visits
            (visit_code, machine_id, restocker_id, status, started_at, completed_at,
             cabin_temp_celsius, client_mutation_id)
         VALUES (:visit_code, :machine_id, :restocker_id, :status,
                 :started_at::timestamptz, :completed_at::timestamptz, 4.0, :mutation)
         RETURNING id'
    );
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-9001',
        ':machine_id' => $machineId,
        ':restocker_id' => $userIds['restocker'],
        ':status' => 'COMPLETED',
        ':started_at' => '2026-11-19 08:00:00+00',
        ':completed_at' => '2026-11-19 09:00:00+00',
        ':mutation' => 'dex06-visit-0001',
    ]);
    $visit1 = (int)$visitStmt->fetchColumn();
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-9002',
        ':machine_id' => $machineId,
        ':restocker_id' => $userIds['restocker'],
        ':status' => 'COMPLETED',
        ':started_at' => '2026-11-20 08:00:00+00',
        ':completed_at' => '2026-11-20 09:00:00+00',
        ':mutation' => 'dex06-visit-0002',
    ]);
    $visit2 = (int)$visitStmt->fetchColumn();

    $itemStmt = $pdo->prepare(
        'INSERT INTO machine_restock_visit_items
            (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired, qty_after)
         VALUES (:visit_id, :slot_id, :slot_code, :product_id, :qty_before, :withdrawn, :qty_after)'
    );
    $itemStmt->execute([
        ':visit_id' => $visit2,
        ':slot_id' => $slotA1,
        ':slot_code' => 'A1',
        ':product_id' => $productId,
        ':qty_before' => 8,
        ':withdrawn' => 3,
        ':qty_after' => 5,
    ]);
    $itemStmt->execute([
        ':visit_id' => $visit2,
        ':slot_id' => $slotA2,
        ':slot_code' => 'A2',
        ':product_id' => $productId,
        ':qty_before' => 4,
        ':withdrawn' => 0,
        ':qty_after' => 4,
    ]);

    $collectionStmt = $pdo->prepare(
        "INSERT INTO machine_cash_collections
            (collection_code, machine_id, machine_code_snapshot, restocker_id,
             restocker_name_snapshot, restocker_code_snapshot, bag_seal_code,
             sales_counter_reading, previous_sales_counter_reading, expected_cashbox_eur,
             status, delivered_to_vault_at, delivered_to_vault_by,
             vault_counted_eur, discrepancy_eur, vault_reconciled_by,
             vault_reconciled_by_name_snapshot, vault_reconciled_at, discrepancy_justification,
             collected_at, client_mutation_id)
         VALUES (:code, :machine_id, 'VEND-DEX06', :restocker_id,
                 'Reponedora DEX 06', 'REP-DEX06', :seal,
                 125.50, 100.00, 25.50,
                 :status, :delivered_at::timestamptz, :delivered_by,
                 :vault, :discrepancy, :reconciled_by,
                 :reconciled_name, :reconciled_at::timestamptz, :justification,
                 :collected_at::timestamptz, :mutation)
         RETURNING id"
    );
    $collectionStmt->execute([
        ':code' => 'MCC-2026-9001',
        ':machine_id' => $machineId,
        ':restocker_id' => $userIds['restocker'],
        ':seal' => 'SEAL-DEX06-001',
        ':status' => 'DISCREPANCY_FLAGGED',
        ':delivered_at' => '2026-11-20 09:00:00+00',
        ':delivered_by' => $userIds['coordinator'],
        ':vault' => 30.00,
        ':discrepancy' => 4.50,
        ':reconciled_by' => $userIds['coordinator'],
        ':reconciled_name' => 'Coordinadora DEX 06',
        ':reconciled_at' => '2026-11-20 09:30:00+00',
        ':justification' => 'Descuadre detectado en el arqueo de certificación',
        ':collected_at' => '2026-11-20 08:00:00+00',
        ':mutation' => 'dex06-collection-0001',
    ]);
    $collection1 = (int)$collectionStmt->fetchColumn();
    $collectionStmt->execute([
        ':code' => 'MCC-2026-9002',
        ':machine_id' => $machineId,
        ':restocker_id' => $userIds['restocker'],
        ':seal' => 'SEAL-DEX06-002',
        ':status' => 'SEALED_IN_ROUTE',
        ':delivered_at' => null,
        ':delivered_by' => null,
        ':vault' => null,
        ':discrepancy' => null,
        ':reconciled_by' => null,
        ':reconciled_name' => null,
        ':reconciled_at' => null,
        ':justification' => null,
        ':collected_at' => '2026-11-19 22:00:00+00',
        ':mutation' => 'dex06-collection-0002',
    ]);
    $collection2 = (int)$collectionStmt->fetchColumn();

    $pdo->prepare(
        "INSERT INTO incidents
            (ticket_code, machine_id, location_id, assigned_technician_id,
             category, description, urgency, status)
         VALUES ('INC-2026-9001', :machine_id, :location_id, :technician_id,
                 'TEMPERATURE_COLD', 'Avería térmica abierta de certificación DEX 06', 'HIGH', 'REGISTERED')"
    )->execute([
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':technician_id' => $userIds['technician'],
    ]);
    $incidentId = (int)$pdo->query("SELECT id FROM incidents WHERE ticket_code = 'INC-2026-9001'")->fetchColumn();

    $repository = new PdoMachineDexAuditRepository($pdo);
    $gateway = new PdoMachineDexAuditGateway($pdo);

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Gateway: cerrojo, coberturas y primacía sanitaria (QA-1) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $machineContext = $gateway->lockMachineContext($machineId, $userIds['restocker']);
    $assert(
        '1.1 lockMachineContext devuelve la máquina y los snapshots del actor',
        $machineContext !== null
            && $machineContext['machine_code'] === 'VEND-DEX06'
            && $machineContext['machine_type'] === 'COMBO'
            && $machineContext['actor_role'] === 'RESTOCKER'
    );
    $assert(
        '1.2 El Reponedor con zona viva cubre la máquina',
        $machineContext['is_covered_restocker'] === true
    );
    $assert(
        '1.3 El Técnico con zona viva cubre la máquina',
        $gateway->lockMachineContext($machineId, $userIds['technician'])['is_covered_technician'] === true
    );
    $assert(
        '1.4 Un Reponedor ajeno no cubre la máquina',
        $gateway->lockMachineContext($machineId, $userIds['other_restocker'])['is_covered_restocker'] === false
    );
    $assert(
        '1.5 La avería TEMPERATURE_COLD abierta se expone a la guarda sanitaria',
        $machineContext['has_open_thermal_breakdown'] === true
    );

    $pdo->exec("UPDATE machines SET is_active = FALSE WHERE id = {$machineId}");
    $assert(
        '1.6 Una máquina inactiva no se entrega al importador',
        $gateway->lockMachineContext($machineId, $userIds['restocker']) === null
    );
    $pdo->exec("UPDATE machines SET is_active = TRUE WHERE id = {$machineId}");

    $assert(
        '1.7 lockRestockVisit devuelve la visita de la máquina',
        $gateway->lockRestockVisit($visit2, $machineId)['status'] === 'COMPLETED'
            && $gateway->lockRestockVisit($visit2, $machineId + 999) === null
    );
    $assert(
        '1.8 lockCashCollection devuelve la recaudación de la máquina',
        $gateway->lockCashCollection($collection1, $machineId)['id'] === $collection1
            && $gateway->lockCashCollection($collection1, $machineId + 999) === null
    );
    $assert(
        '1.9 lockIncident devuelve la incidencia de la máquina',
        $gateway->lockIncident($incidentId, $machineId)['category'] === 'TEMPERATURE_COLD'
            && $gateway->lockIncident($incidentId, $machineId + 999) === null
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Cruces M34/M35 del mismo día civil Europe/Madrid (QA-3) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $nearestCash = $gateway->findNearestCashCollectionSameDay($machineId, '2026-11-20 10:00:00+00');
    $assert(
        '2.1 La recaudación más cercana del mismo día civil es la de las 09:00 Madrid',
        $nearestCash !== null && $nearestCash['id'] === $collection1,
        json_encode($nearestCash)
    );
    $nearestVisit = $gateway->findCompletedRestockVisitSameDay($machineId, '2026-11-20 10:00:00+00');
    $assert(
        '2.2 La visita COMPLETED más cercana del mismo día civil es la segunda',
        $nearestVisit !== null && $nearestVisit['id'] === $visit2,
        json_encode($nearestVisit)
    );
    $previousVisit = $gateway->findPreviousCompletedRestockVisit($machineId, $visit2);
    $assert(
        '2.3 La visita COMPLETED inmediatamente anterior es la primera',
        $previousVisit !== null && $previousVisit['id'] === $visit1
    );
    $quantities = $gateway->findVisitSlotQuantities($visit2);
    $assert(
        '2.4 Las cantidades por carril conservan qty_before y qty_after',
        count($quantities) === 2
            && $quantities[0]['slot_code'] === 'A1'
            && $quantities[0]['qty_before'] === 8
            && $quantities[0]['qty_after'] === 5
    );
    $slots = $gateway->findActivePlanogramSlots($machineId);
    $assert(
        '2.5 Solo los carriles activos entran en el emparejamiento',
        count($slots) === 1 && $slots[0]['id'] === $slotA1 && $slots[0]['slot_code'] === 'A1'
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Alta atómica, deltas y lecturas sin payload (RF-04.1, RNF-01) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $assert(
        '3.1 El correlativo anual reserva DEX-AAAA-NNNN en Europe/Madrid',
        $repository->reserveAuditCode(new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC'))) === 'DEX-2026-0001'
    );

    $makeAudit = static function (
        string $code,
        string $capturedAt,
        string $payload,
        string $deltaSource = 'INITIAL_BASELINE',
        ?int $previousAuditId = null,
        ?float $deltaSales = null,
        ?int $deltaUnits = null,
        ?float $ca3 = null,
        bool $identityWarning = false
    ) use ($machineId, $userIds): MachineDexAudit {
        return new MachineDexAudit(
            id: null,
            auditCode: $code,
            machineId: $machineId,
            machineCodeSnapshot: 'VEND-DEX06',
            machineTypeSnapshot: 'COMBO',
            importedBy: $userIds['coordinator'],
            importerRoleSnapshot: 'COORDINATOR',
            importerNameSnapshot: 'Coordinadora DEX 06',
            importSource: DexImportSource::RESTOCKER_ROUTE,
            originalFilename: 'volcado-' . strtolower($code) . '.dex',
            fileSizeBytes: strlen($payload),
            sha256Hash: hash('sha256', $payload),
            rawPayload: $payload,
            va1TotalSalesEur: 25.50,
            va1TotalVendUnits: 41,
            deltaSource: $deltaSource,
            capturedAt: $capturedAt,
            dxsCommId: 'COMM-DEX06',
            dxsVersion: 'DEX 6.0',
            dexMachineIdentifier: 'VEND-DEX06',
            identityMismatchWarning: $identityWarning,
            ca3CashboxEur: $ca3,
            previousAuditId: $previousAuditId,
            deltaSalesEur: $deltaSales,
            deltaVendUnits: $deltaUnits,
            status: DexAuditStatus::ACTIVE
        );
    };

    $payloadA = "DXS*COMM-DEX06*VA*DEX 6.0\nID1*VEND-DEX06\nVA1*2550*41\nCA3*2200*0*0\nDXE*FIN";
    $auditA = $repository->create(
        $makeAudit('DEX-2026-0001', '2026-11-20 10:00:00+00', $payloadA, ca3: 22.00),
        [[
            'line_sequence' => 1,
            'raw_selection_code' => 'a1',
            'normalized_selection_code' => 'A1',
            'matched_slot_id' => $slotA1,
            'matched_slot_code_snapshot' => 'A1',
            'match_status' => DexSelectionMatchStatus::EXACT_MATCH,
            'unit_price_eur' => 1.50,
            'total_vend_units' => 10,
            'total_sales_eur' => 15.00,
            'reset_vend_units' => null,
            'reset_sales_eur' => null,
            'delta_vend_units' => null,
            'delta_sales_eur' => null,
        ]],
        [[
            'line_sequence' => 1,
            'source_block' => 'EA2',
            'event_code' => 'EA202',
            'event_category' => 'PAYMENT_MDB',
            'severity' => 'WARNING',
            'occurrence_count' => 2,
            'count_since_reset' => 2,
            'count_since_init' => 2,
            'is_active_flag' => true,
            'event_occurred_at_raw' => null,
            'description_es' => 'Evento Sistema de Pago (MDB) · EA202',
            'is_thermal_alert' => false,
        ]]
    );
    $auditAId = (int)$auditA->getId();
    $assert('3.2 create() inserta la cabecera y devuelve su identificador', $auditAId > 0);

    $detailA = $repository->findById($auditAId);
    $assert(
        '3.3 El detalle trae cabecera, una selección y un evento',
        $detailA !== null
            && $detailA['audit_code'] === 'DEX-2026-0001'
            && count($detailA['selections']) === 1
            && count($detailA['events']) === 1
            && $detailA['has_thermal_alert'] === false
    );
    $assert(
        '3.4 Ninguna lectura de detalle expone raw_payload (RNF-01)',
        $detailA !== null && !array_key_exists('raw_payload', $detailA)
    );
    $assert(
        '3.5 La selección persistida conserva carril, snapshot, estado y unidades',
        $detailA['selections'][0]['matched_slot_id'] === $slotA1
            && $detailA['selections'][0]['matched_slot_code_snapshot'] === 'A1'
            && $detailA['selections'][0]['match_status'] === 'EXACT_MATCH'
            && $detailA['selections'][0]['total_vend_units'] === 10
    );
    $assert(
        '3.6 El evento persistido conserva EA202, gravedad y bandera',
        $detailA['events'][0]['event_code'] === 'EA202'
            && $detailA['events'][0]['severity'] === 'WARNING'
            && $detailA['events'][0]['is_active_flag'] === true
    );
    $assert(
        '3.7 findActiveIdByHash localiza el volcado vigente por huella',
        $repository->findActiveIdByHash($machineId, hash('sha256', $payloadA)) === $auditAId
    );
    $raw = $repository->findRawPayload($auditAId);
    $assert(
        '3.8 findRawPayload es la única lectura que devuelve el .dex original',
        $raw !== null && $raw['raw_payload'] === $payloadA && $raw['original_filename'] === 'volcado-dex-2026-0001.dex'
    );

    $payloadB = "DXS*COMM-DEX06*VA*DEX 6.0\nVA1*1000*20\nDXE*FIN";
    $auditB = $repository->create($makeAudit('DEX-2026-0002', '2026-11-19 10:00:00+00', $payloadB));
    $auditBId = (int)$auditB->getId();

    $payloadC = "DXS*COMM-DEX06*VA*DEX 6.0\nVA1*4550*51\nEA1*EG01*20261121*091500\nDXE*FIN";
    $auditC = $repository->create(
        $makeAudit(
            'DEX-2026-0003',
            '2026-11-21 10:00:00+00',
            $payloadC,
            'PREVIOUS_AUDIT',
            $auditAId,
            20.00,
            10,
            null,
            true
        ),
        [],
        [[
            'line_sequence' => 1,
            'source_block' => 'EA1',
            'event_code' => 'EG01',
            'event_category' => 'THERMAL_REFRIGERATION',
            'severity' => 'CRITICAL',
            'occurrence_count' => 1,
            'count_since_reset' => null,
            'count_since_init' => null,
            'is_active_flag' => false,
            'event_occurred_at_raw' => '20261121 091500',
            'description_es' => 'Evento Refrigeración / Cadena de Frío · EG01',
            'is_thermal_alert' => true,
        ]]
    );
    $auditCId = (int)$auditC->getId();

    $previousForC = $repository->findPreviousActive($machineId, '2026-11-21 10:00:00+00', $auditCId);
    $assert(
        '3.9 El predecesor es el último ACTIVE con captured_at <= y no el posterior',
        $previousForC !== null && $previousForC['id'] === $auditAId,
        json_encode($previousForC)
    );
    $assert(
        '3.10 Un volcado retroactivo no tiene predecesor y marca out_of_order (QA-2)',
        $repository->findPreviousActive($machineId, '2026-11-19 10:00:00+00', $auditBId) === null
            && $repository->hasLaterActive($machineId, '2026-11-19 10:00:00+00') === true
            && $repository->hasLaterActive($machineId, '2026-11-21 10:00:00+00') === false
    );

    $assert(
        '3.11 El listado de Coordinación excluye raw_payload y cuenta las líneas',
        (static function () use ($repository, $machineId): bool {
            $rows = $repository->listForCoordinator(['machine_id' => $machineId]);
            if (count($rows) !== 3) {
                return false;
            }
            foreach ($rows as $row) {
                if (array_key_exists('raw_payload', $row)) {
                    return false;
                }
            }

            return $rows[0]['id'] > 0 && $rows[0]['selections_count'] >= 0 && $rows[0]['events_count'] >= 0;
        })()
    );
    $assert(
        '3.12 El filtro alerts_only devuelve solo el volcado con aviso o alerta térmica',
        (static function () use ($repository, $machineId, $auditCId): bool {
            $rows = $repository->listForCoordinator(['machine_id' => $machineId, 'alerts_only' => true]);

            return count($rows) === 1 && $rows[0]['id'] === $auditCId && $rows[0]['has_thermal_alert'] === true;
        })()
    );
    $kpis = $repository->kpisForCoordinator(['machine_id' => $machineId]);
    $assert(
        '3.13 Los KPIs cuentan activos, térmicos, avisos y desviación de efectivo (QA-3)',
        $kpis['total_active'] === 3
            && $kpis['thermal_alert_count'] === 1
            && $kpis['warning_count'] === 1
            && $kpis['cash_deviation_count'] === 1
            && $kpis['voided_count'] === 0,
        json_encode($kpis)
    );
    $assert(
        '3.14 La huella vigente bloquea una segunda importación (D3)',
        $expectConflict(
            static fn () => $repository->create($makeAudit('DEX-2026-0004', '2026-11-22 10:00:00+00', $payloadA)),
            'DEX_DUPLICATE_HASH'
        )
    );
    $assert(
        '3.15 findSelectionsByAudit devuelve las líneas en su secuencia',
        count($repository->findSelectionsByAudit($auditAId)) === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Anulación lógica terminal y liberación de la huella (Art. V.1) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $voided = $repository->void(
        $auditAId,
        'Anulación por duplicado operativo detectado',
        $userIds['coordinator'],
        'Coordinadora DEX 06',
        '2026-12-31 12:00:00+00'
    );
    $assert(
        '4.1 void() sella estado VOIDED, motivo, autor y fecha',
        $voided['status'] === 'VOIDED'
            && $voided['void_reason'] === 'Anulación por duplicado operativo detectado'
            && $voided['voided_by'] === $userIds['coordinator']
            && $voided['voided_at'] !== null
    );
    $assert(
        '4.2 Una segunda anulación es un conflicto terminal',
        $expectConflict(
            static fn () => $repository->void(
                $auditAId,
                'Segundo intento de anulación del volcado',
                $userIds['coordinator'],
                'Coordinadora DEX 06',
                '2026-12-31 12:05:00+00'
            ),
            'DEX_AUDIT_ALREADY_VOIDED'
        )
    );
    $assert(
        '4.3 La huella deja de bloquear y la reimportación es posible',
        $repository->findActiveIdByHash($machineId, hash('sha256', $payloadA)) === null
    );
    $previousAfterVoid = $repository->findPreviousActive($machineId, '2026-11-21 10:00:00+00', $auditCId);
    $assert(
        '4.4 La cadena excluye el volcado anulado y retrocede al siguiente ACTIVE',
        $previousAfterVoid !== null && $previousAfterVoid['id'] === $auditBId,
        json_encode($previousAfterVoid)
    );
    $kpisAfterVoid = $repository->kpisForCoordinator(['machine_id' => $machineId]);
    $assert(
        '4.5 Los KPIs reflejan la anulación',
        $kpisAfterVoid['total_active'] === 2 && $kpisAfterVoid['voided_count'] === 1
    );
    $assert(
        '4.6 El listado con estado ALL incluye el volcado anulado',
        (static function () use ($repository, $machineId, $auditAId): bool {
            $rows = $repository->listForCoordinator(['machine_id' => $machineId, 'status' => 'ALL']);
            $ids = array_column($rows, 'id');

            return in_array($auditAId, $ids, true) && count($rows) === 3;
        })()
    );
    $assert(
        '4.7 El payload original sigue disponible tras la anulación (C3)',
        $repository->findRawPayload($auditAId)['raw_payload'] === $payloadA
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, DEX06_SCHEMA);
        $residue = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . DEX06_SCHEMA . "'"
        )->fetchColumn();
    } catch (Throwable $cleanupException) {
        $residue = 'ERROR: ' . $cleanupException->getMessage();
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($crash !== null) {
    echo ' EXCEPCIÓN: ' . get_class($crash) . ' · ' . $crash->getMessage() . "\n";
    echo ' TRAZA: ' . $crash->getFile() . ':' . $crash->getLine() . "\n";
}
if ($residue !== 0) {
    echo ' RESIDUO EN POSTGRESQL: ' . var_export($residue, true) . "\n";
}

if ($failures === 0 && $crash === null && $residue === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-06 (repositorio y gateway PDO) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS O RESIDUO DETECTADO EN LA CAPA PDO DEL MÓDULO 39.\n";
echo "======================================================================\n";
exit(1);
