<?php

declare(strict_types=1);

/**
 * PdoRestockingBiAnalyticsRepositoryTest · T-RBI-02
 *
 * Certifica en PostgreSQL real el contrato de lectura agregada del Cuadro de
 * Mando de Reposición, Roturas de Stock y Mermas Comerciales (Módulo 38;
 * `RF-01`–`RF-05`, `RNF-01`, `RNF-02`, `C1`–`C4`, `QA-2`, `QA-4`, `QA-5`,
 * Art. III, Art. IV).
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Ventana semiabierta (`C1`).** La visita que cae exactamente en `from`
 *      entra; la que cae exactamente en `to + 1 día` a medianoche de Madrid
 *      queda fuera. El acta térmica y el arqueo se miden por su propia marca
 *      (`created_at`, `collected_at`), no por la de la visita.
 *   2. **In-Stock por visita (`D1`).** La visita con un carril `qty_before = 0`
 *      no es limpia; la visita sin líneas sí lo es; tres roturas en una misma
 *      visita cuentan una sola visita con rotura (`NOT EXISTS`).
 *   3. **Carriles y guarda de producto (`QA-5`).** Los totales de carriles no se
 *      multiplican por las visitas, actas, arqueos e incidencias de la máquina.
 *      Un carril cuyo producto vigente ya no coincide con el auditado no suma
 *      `low_threshold_slots_count`.
 *   4. **Merma comercial exacta (`D2`, `QA-A4`).** `expired_loss_eur` y
 *      `damaged_loss_eur` se suman al céntimo y coinciden con
 *      `commercial_spoilage_loss_eur`.
 *   5. **Térmica APPCC (`QA-2`).** Cuenta todas las actas del periodo, incluidas
 *      la de máquina vacía y los tres orígenes de cuarentena; con filtro por
 *      reponedor no ejecuta la consulta y devuelve lista vacía.
 *   6. **Turnos (`C3`).** El anulado (`OFF_SHIFT` con `closed_at = started_at`)
 *      no cuenta, el técnico SAT tampoco, y con filtro territorial la lista es
 *      vacía porque un turno no se segmenta por zona.
 *   7. **Arqueos (`RF-05.3`).** Se incluyen las recaudaciones independientes
 *      (`restock_visit_id IS NULL`), se separan cerradas y pendientes y el
 *      descuadre absoluto solo suma bolsas cerradas.
 *   8. **Sinergia L1 (`C4`, `QA-5`).** Solo incidencias vivas de reponedores en
 *      `L1_RESTOCKER` resueltas/cerradas; los escalados viajan aparte y la
 *      incidencia borrada o del técnico SAT queda fuera.
 *   9. **Bajas lógicas y catálogos (`QA-4`, Art. III).** La máquina inactiva con
 *      actividad entra con `is_active = false`; la máquina que solo tiene un
 *      acta térmica también entra; `available_filters` combina activos,
 *      inactivos con actividad y la entidad seleccionada, con `zone_id` y
 *      `operator_code` anulables.
 *  10. **Cero DDL (`RNF-01`, Art. IV).** El esquema aislado queda en 51 tablas
 *      tras aplicar el DDL canónico y las 34 migraciones, y no queda residuo.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin motor sale con código 2 y lo
 * dice, no se omite en silencio.
 *
 * LIMPIEZA: esquema aislado `t_rbi02_repositorio` borrado con `CASCADE` incluso
 * si una aserción falla; se verifica residuo 0.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Core\Domain\Repository\RestockingBiAnalyticsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoRestockingBiAnalyticsRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const RBI02_SCHEMA = 't_rbi02_repositorio';

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

echo "======================================================================\n";
echo " VENDGUARD · T-RBI-02 · Repositorio PDO de analítica de reposición\n";
echo " Categoría: new_engine (PostgreSQL real)\n";
echo "======================================================================\n";

try {
    // La categoría `new_engine` declara su motor con `PG_TEST_DSN` (contrato del
    // banco TLS). Si no está declarado, se resuelve por la ruta de la aplicación
    // (`DATABASE_URL`), que es la que usa el banco de la batería.
    $pgTestDsn = getenv('PG_TEST_DSN');

    if (is_string($pgTestDsn) && trim($pgTestDsn) !== '') {
        $pdo = new PDO(trim($pgTestDsn), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $pdo->query('SELECT 1')->fetchColumn();
    } else {
        $pdo = ConnectionFactory::getConnection();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor PostgreSQL no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$insertId = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql . ' RETURNING id');
    $statement->execute($params);
    $id = (int)$statement->fetchColumn();
    $statement->closeCursor();

    return $id;
};

$residue = null;
$crash = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, RBI02_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Inventario canónico: cero DDL (RNF-01, Art. IV)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Inventario del esquema aislado (cero DDL) ---\n";

    $tables = PostgresFixtureBuilder::tables($pdo);
    $assert('El esquema canónico conserva 51 tablas de dominio', count($tables) === 51, 'contadas ' . count($tables));
    $assert('La tabla de visitas M34 existe sin cambios', in_array('machine_restock_visits', $tables, true));
    $assert('La tabla de actas M29 existe sin cambios', in_array('sanitary_spoilage_records', $tables, true));

    // ─────────────────────────────────────────────────────────────────────
    // Escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (2 zonas activas, 1 histórica, 6 máquinas y 5 reponedores) ---\n";

    $zoneNorth = $insertId(
        $pdo,
        "INSERT INTO operational_zones (code, name) VALUES ('ZN-NORTE', 'Zona Norte')",
        []
    );
    $zoneSouth = $insertId(
        $pdo,
        "INSERT INTO operational_zones (code, name) VALUES ('ZN-SUR', 'Zona Sur')",
        []
    );
    $zoneHistoric = $insertId(
        $pdo,
        "INSERT INTO operational_zones (code, name) VALUES ('ZN-HIST', 'Zona Histórica')",
        []
    );
    $pdo->exec("UPDATE operational_zones SET is_active = FALSE WHERE id IN ({$zoneSouth}, {$zoneHistoric})");

    $insertLocation = static function (
        PDO $pdo,
        callable $insertId,
        string $siteCode,
        string $name,
        int $zoneId
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
             VALUES (?, ?, ?, ?, ?)',
            [$siteCode, strtolower($siteCode), $name, 'Calle de Pruebas 1', $zoneId]
        );
    };

    $locHospital = $insertLocation($pdo, $insertId, 'HOSP-01', 'Hospital Universitario', $zoneNorth);
    $locSchool = $insertLocation($pdo, $insertId, 'CEIP-02', 'CEIP Norte', $zoneNorth);
    $locNoZone = $insertLocation($pdo, $insertId, 'EXT-04', 'Sede Sin Zona', $zoneNorth);
    $pdo->exec("UPDATE locations SET zone_id = NULL WHERE id = {$locNoZone}");
    $locInactive = $insertLocation($pdo, $insertId, 'EXT-05', 'Sede Antigua', $zoneSouth);
    $pdo->exec("UPDATE locations SET is_active = FALSE WHERE id = {$locInactive}");

    $insertMachine = static function (
        PDO $pdo,
        callable $insertId,
        int $locationId,
        string $code,
        string $machineType
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (?, ?, ?, ?, ?)',
            [$locationId, $code, 'VendMax 9000', $machineType, 'Planta baja']
        );
    };

    $machineHospital = $insertMachine($pdo, $insertId, $locHospital, 'MAQ-HOSP-02', 'PERISHABLE_FOOD');
    $machineSchool = $insertMachine($pdo, $insertId, $locSchool, 'MAQ-CEIP-01', 'SNACKS');
    $machineNoZone = $insertMachine($pdo, $insertId, $locNoZone, 'MAQ-EXT-04', 'COLD_DRINKS');
    $pdo->exec("UPDATE machines SET is_active = FALSE WHERE id = {$machineNoZone}");
    $machineIdle = $insertMachine($pdo, $insertId, $locSchool, 'MAQ-CEIP-02', 'SNACKS');
    $machineThermal = $insertMachine($pdo, $insertId, $locHospital, 'MAQ-HOSP-03', 'PERISHABLE_FOOD');
    $machineInactiveSite = $insertMachine($pdo, $insertId, $locInactive, 'MAQ-EXT-05', 'SNACKS');

    $insertUser = static function (
        PDO $pdo,
        callable $insertId,
        string $name,
        string $email,
        string $role,
        ?string $operatorCode
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $email, $email, 'hash-de-pruebas', $role, $operatorCode]
        );
    };

    $restockerOne = $insertUser($pdo, $insertId, 'Lucía Navarro', 'lucia@vendguard.test', 'RESTOCKER', 'REP-01');
    $restockerTwo = $insertUser($pdo, $insertId, 'Carlos Méndez', 'carlos@vendguard.test', 'RESTOCKER', null);
    $restockerThree = $insertUser($pdo, $insertId, 'Marta Ibáñez', 'marta@vendguard.test', 'RESTOCKER', 'REP-03');
    $restockerFour = $insertUser($pdo, $insertId, 'Ana Ruiz', 'ana@vendguard.test', 'RESTOCKER', 'REP-04');
    $restockerFive = $insertUser($pdo, $insertId, 'Pablo Gil', 'pablo@vendguard.test', 'RESTOCKER', 'REP-05');
    $technician = $insertUser($pdo, $insertId, 'Técnico SAT', 'tecnico@vendguard.test', 'TECHNICIAN', 'TEC-01');
    $coordinator = $insertUser($pdo, $insertId, 'Coordinador', 'coordinador@vendguard.test', 'COORDINATOR', 'COO-01');

    $pdo->exec("UPDATE users SET is_active = FALSE WHERE id IN ({$restockerTwo}, {$restockerThree}, {$restockerFive})");

    $insertProduct = static function (
        PDO $pdo,
        callable $insertId,
        string $sku,
        string $name,
        string $category,
        bool $coldChain,
        ?int $shelfLife,
        float $cost,
        float $salePrice
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO vending_products
                (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
             VALUES (?, ?, ?, \'UNIT\', ?, ?, ?, ?)',
            [$sku, $name, $category, $coldChain ? 'TRUE' : 'FALSE', $shelfLife, $cost, $salePrice]
        );
    };

    $productPerishable = $insertProduct($pdo, $insertId, 'SAND-MIXTO-01', 'Sándwich Mixto Fresco', 'PERISHABLE_FOOD', true, 5, 2.50, 3.50);
    $productSnack = $insertProduct($pdo, $insertId, 'PATATAS-01', 'Patatas Fritas', 'SNACK', false, null, 1.50, 2.00);
    $productChocolate = $insertProduct($pdo, $insertId, 'CHOCOLATE-01', 'Chocolate con Leche', 'SNACK', false, null, 1.20, 1.80);
    $productDrink = $insertProduct($pdo, $insertId, 'AGUA-50', 'Agua Mineral 50cl', 'COLD_DRINK', false, null, 0.80, 1.20);

    $insertSlot = static function (
        PDO $pdo,
        callable $insertId,
        int $machineId,
        string $slotCode,
        int $productId,
        int $maxCapacity,
        int $estimatedQty,
        int $minThreshold
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO machine_planogram_slots
                (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$machineId, $slotCode, $productId, $maxCapacity, $estimatedQty, $minThreshold]
        );
    };

    $slotStockout = $insertSlot($pdo, $insertId, $machineHospital, 'A1', $productPerishable, 20, 0, 3);
    $slotSpoiled = $insertSlot($pdo, $insertId, $machineHospital, 'A2', $productPerishable, 20, 5, 3);
    $slotLow = $insertSlot($pdo, $insertId, $machineHospital, 'A3', $productPerishable, 20, 4, 6);
    // El carril conserva el producto viejo (patatas) mientras la visita ya
    // declara el producto nuevo (chocolate): el umbral vigente no debe aplicarse
    // a una referencia que ya no ocupa el hueco (QA-5).
    $slotChangedProduct = $insertSlot($pdo, $insertId, $machineSchool, 'B1', $productSnack, 15, 2, 5);
    $slotDrink = $insertSlot($pdo, $insertId, $machineNoZone, 'C1', $productDrink, 12, 0, 2);

    $insertVisit = static function (
        PDO $pdo,
        callable $insertId,
        string $visitCode,
        int $machineId,
        int $restockerId,
        string $status,
        string $startedAt,
        string $completedAt,
        string $mutationId,
        ?float $cabinTemp
    ): int {
        return $insertId(
            $pdo,
            'INSERT INTO machine_restock_visits
                (visit_code, machine_id, restocker_id, status, started_at, completed_at,
                 cabin_temp_celsius, hygiene_checklist_json, precautionary_seal_applied,
                 photo_evidence_url, internal_notes, client_mutation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'{}\'::jsonb, ?, ?, ?, ?)',
            [
                $visitCode,
                $machineId,
                $restockerId,
                $status,
                $startedAt,
                $completedAt,
                $cabinTemp,
                $status === 'ABORTED_THERMAL_BREACH' ? 'TRUE' : 'FALSE',
                $status === 'ABORTED_THERMAL_BREACH' ? 'https://evidencias.vendguard.test/frio.jpg' : null,
                $status === 'ABORTED_THERMAL_BREACH'
                    ? 'Rotura de cadena de frío detectada durante la reposición de la máquina.'
                    : null,
                $mutationId,
            ]
        );
    };

    $visitOne = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0001',
        $machineHospital,
        $restockerOne,
        'COMPLETED',
        '2026-11-10 09:40:00+01',
        '2026-11-10 10:00:00+01',
        'mut-rbi02-0001',
        4.0
    );
    $visitEmpty = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0002',
        $machineHospital,
        $restockerOne,
        'COMPLETED',
        '2026-11-10 10:00:00+01',
        '2026-11-10 10:12:00+01',
        'mut-rbi02-0002',
        3.8
    );
    $visitThermal = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0003',
        $machineHospital,
        $restockerTwo,
        'ABORTED_THERMAL_BREACH',
        '2026-11-14 11:00:00+01',
        '2026-11-14 11:05:00+01',
        'mut-rbi02-0003',
        9.5
    );
    $visitChangedProduct = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0004',
        $machineSchool,
        $restockerTwo,
        'COMPLETED',
        '2026-11-15 12:00:00+01',
        '2026-11-15 12:20:00+01',
        'mut-rbi02-0004',
        null
    );
    $visitNoZone = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0005',
        $machineNoZone,
        $restockerTwo,
        'COMPLETED',
        '2026-11-09 09:00:00+01',
        '2026-11-09 09:10:00+01',
        'mut-rbi02-0005',
        3.5
    );
    $visitFromBoundary = $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0006',
        $machineSchool,
        $restockerOne,
        'COMPLETED',
        '2026-11-06 23:50:00+01',
        '2026-11-07 00:00:00+01',
        'mut-rbi02-0006',
        null
    );
    $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0007',
        $machineSchool,
        $restockerOne,
        'COMPLETED',
        '2026-11-20 23:50:00+01',
        '2026-11-21 00:00:00+01',
        'mut-rbi02-0007',
        null
    );
    $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0008',
        $machineInactiveSite,
        $restockerTwo,
        'COMPLETED',
        '2026-11-16 10:00:00+01',
        '2026-11-16 10:10:00+01',
        'mut-rbi02-0008',
        null
    );
    $insertVisit(
        $pdo,
        $insertId,
        'MRV-2026-0009',
        $machineSchool,
        $restockerThree,
        'COMPLETED',
        '2026-11-17 10:00:00+01',
        '2026-11-17 10:05:00+01',
        'mut-rbi02-0009',
        null
    );

    $insertItem = static function (
        PDO $pdo,
        int $visitId,
        int $slotId,
        string $slotCode,
        int $productId,
        int $qtyBefore,
        int $expired,
        int $damaged,
        int $added,
        int $qtyAfter,
        float $unitCost,
        ?string $loadedBatch
    ) use (&$insertId): void {
        $insertId(
            $pdo,
            'INSERT INTO machine_restock_visit_items
                (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
                 qty_withdrawn_damaged, qty_added, qty_after, unit_cost_snapshot_eur,
                 commercial_spoilage_loss_eur, loaded_batch_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $visitId,
                $slotId,
                $slotCode,
                $productId,
                $qtyBefore,
                $expired,
                $damaged,
                $added,
                $qtyAfter,
                $unitCost,
                round(($expired + $damaged) * $unitCost, 2),
                $loadedBatch,
            ]
        );
    };

    $insertItem($pdo, $visitOne, $slotStockout, 'A1', $productPerishable, 0, 0, 0, 10, 10, 2.50, 'LOTE-A1');
    $insertItem($pdo, $visitOne, $slotSpoiled, 'A2', $productPerishable, 5, 2, 1, 5, 7, 2.50, 'LOTE-A2');
    $insertItem($pdo, $visitOne, $slotLow, 'A3', $productPerishable, 4, 0, 0, 2, 6, 2.50, 'LOTE-A3');
    $insertItem($pdo, $visitChangedProduct, $slotChangedProduct, 'B1', $productChocolate, 2, 0, 0, 0, 2, 1.20, null);
    $insertItem($pdo, $visitNoZone, $slotDrink, 'C1', $productDrink, 0, 0, 0, 8, 8, 0.80, 'LOTE-C1');

    $insertSpoilage = static function (
        PDO $pdo,
        callable $insertId,
        string $recordCode,
        int $machineId,
        string $origin,
        int $units,
        bool $emptyMachine,
        string $disposalMethod,
        float $loss,
        string $createdAt
    ) use ($technician, $coordinator): void {
        $insertId(
            $pdo,
            'INSERT INTO sanitary_spoilage_records
                (record_code, machine_id, technician_id, registered_by, quarantine_origin,
                 discarded_units_count, empty_machine_confirmed, affected_batch_codes,
                 cabin_temp_at_removal, restored_stabilized_temp_c, disposal_method,
                 evidence_photo_url, sanitary_notes, estimated_merchandise_loss_eur,
                 certificate_code, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $recordCode,
                $machineId,
                $technician,
                $coordinator,
                $origin,
                $units,
                $emptyMachine ? 'TRUE' : 'FALSE',
                $emptyMachine ? 'SIN-LOTE' : 'LOTE-TERMICO-01',
                9.0,
                4.0,
                $disposalMethod,
                'https://evidencias.vendguard.test/acta-' . strtolower($recordCode) . '.jpg',
                'Acta sanitaria de certificación del Cuadro de Mando de Reposición.',
                $loss,
                'CERT-' . $recordCode,
                $createdAt,
            ]
        );
    };

    $insertSpoilage(
        $pdo,
        $insertId,
        'SSR-0001',
        $machineHospital,
        'BIOLOGICAL_CLOCK_4H',
        4,
        false,
        'ON_SITE_ORGANIC_SEALED',
        10.00,
        '2026-11-10 12:00:00+01'
    );
    $insertSpoilage(
        $pdo,
        $insertId,
        'SSR-0002',
        $machineHospital,
        'MANUAL_SANITARY_LOCK',
        0,
        true,
        'NOT_APPLICABLE_EMPTY',
        0.00,
        '2026-11-11 08:00:00+01'
    );
    $insertSpoilage(
        $pdo,
        $insertId,
        'SSR-0003',
        $machineThermal,
        'PREVENTIVE_NON_CONFORMITY',
        2,
        false,
        'ON_SITE_ORGANIC_SEALED',
        5.00,
        '2026-11-12 09:00:00+01'
    );
    $insertSpoilage(
        $pdo,
        $insertId,
        'SSR-0004',
        $machineHospital,
        'MANUAL_SANITARY_LOCK',
        1,
        false,
        'ON_SITE_ORGANIC_SEALED',
        2.50,
        '2026-11-25 09:00:00+01'
    );

    $insertShift = static function (
        PDO $pdo,
        callable $insertId,
        int $technicianId,
        string $startedAt,
        ?string $closedAt,
        string $status
    ): void {
        $insertId(
            $pdo,
            'INSERT INTO technician_shifts (technician_id, shift_date, status, started_at, closed_at)
             VALUES (?, ?, ?, ?, ?)',
            [$technicianId, substr($startedAt, 0, 10), $status, $startedAt, $closedAt]
        );
    };

    $insertShift($pdo, $insertId, $restockerOne, '2026-11-10 09:00:00+01', null, 'ON_SHIFT');
    $insertShift($pdo, $insertId, $restockerOne, '2026-11-11 09:00:00+01', '2026-11-11 09:00:00+01', 'OFF_SHIFT');
    $insertShift($pdo, $insertId, $restockerTwo, '2026-11-12 09:00:00+01', null, 'ON_SHIFT');
    $insertShift($pdo, $insertId, $restockerThree, '2026-11-17 09:00:00+01', null, 'ON_SHIFT');
    $insertShift($pdo, $insertId, $technician, '2026-11-10 08:00:00+01', null, 'ON_SHIFT');

    $insertCash = static function (
        PDO $pdo,
        callable $insertId,
        string $collectionCode,
        ?int $visitId,
        int $machineId,
        string $machineCode,
        int $restockerId,
        string $restockerName,
        string $restockerCode,
        string $bagSeal,
        float $salesReading,
        float $expected,
        string $status,
        string $collectedAt,
        ?string $deliveredAt,
        ?float $vaultCounted,
        ?float $discrepancy,
        ?string $justification
    ) use ($coordinator): void {
        $isReconciled = in_array($status, ['RECONCILED_OK', 'DISCREPANCY_FLAGGED'], true);

        $insertId(
            $pdo,
            'INSERT INTO machine_cash_collections
                (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
                 restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, sales_counter_reading,
                 previous_sales_counter_reading, initial_baseline_reading_eur, is_counter_reset,
                 expected_cashbox_eur, status, collected_at, delivered_to_vault_at,
                 delivered_to_vault_by, vault_counted_eur, discrepancy_eur, vault_reconciled_by,
                 vault_reconciled_by_name_snapshot, vault_reconciled_at, discrepancy_justification,
                 client_mutation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, FALSE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $collectionCode,
                $visitId,
                $machineId,
                $machineCode,
                $restockerId,
                $restockerName,
                $restockerCode,
                $bagSeal,
                $salesReading,
                1000.00,
                $expected,
                $status,
                $collectedAt,
                $deliveredAt,
                $deliveredAt === null ? null : $coordinator,
                $vaultCounted,
                $discrepancy,
                $isReconciled ? $coordinator : null,
                $isReconciled ? 'Coordinador' : null,
                $isReconciled ? $deliveredAt : null,
                $isReconciled ? $justification : null,
                'mut-' . strtolower($collectionCode),
            ]
        );
    };

    $insertCash(
        $pdo,
        $insertId,
        'MCC-2026-0001',
        $visitOne,
        $machineHospital,
        'MAQ-HOSP-02',
        $restockerOne,
        'Lucía Navarro',
        'REP-01',
        'BAG-2026-000001',
        1100.00,
        100.00,
        'RECONCILED_OK',
        '2026-11-10 10:30:00+01',
        '2026-11-10 11:00:00+01',
        100.50,
        0.50,
        null
    );
    $insertCash(
        $pdo,
        $insertId,
        'MCC-2026-0002',
        null,
        $machineHospital,
        'MAQ-HOSP-02',
        $restockerOne,
        'Lucía Navarro',
        'REP-01',
        'BAG-2026-000002',
        1097.00,
        97.00,
        'DISCREPANCY_FLAGGED',
        '2026-11-11 10:30:00+01',
        '2026-11-11 11:00:00+01',
        94.00,
        -3.00,
        'Faltan tres euros en la bolsa precintada de la ruta.'
    );
    $insertCash(
        $pdo,
        $insertId,
        'MCC-2026-0003',
        null,
        $machineHospital,
        'MAQ-HOSP-02',
        $restockerOne,
        'Lucía Navarro',
        'REP-01',
        'BAG-2026-000003',
        1050.00,
        50.00,
        'SEALED_IN_ROUTE',
        '2026-11-12 10:30:00+01',
        null,
        null,
        null,
        null
    );
    $insertCash(
        $pdo,
        $insertId,
        'MCC-2026-0004',
        null,
        $machineInactiveSite,
        'MAQ-EXT-05',
        $restockerTwo,
        'Carlos Méndez',
        'REP-02',
        'BAG-2026-000004',
        1030.00,
        30.00,
        'DELIVERED_TO_VAULT',
        '2026-11-13 10:30:00+01',
        '2026-11-13 15:00:00+01',
        null,
        null,
        null
    );
    $insertCash(
        $pdo,
        $insertId,
        'MCC-2026-0005',
        null,
        $machineHospital,
        'MAQ-HOSP-02',
        $restockerOne,
        'Lucía Navarro',
        'REP-01',
        'BAG-2026-000005',
        1005.00,
        5.00,
        'RECONCILED_OK',
        '2026-11-22 10:30:00+01',
        '2026-11-22 11:00:00+01',
        5.00,
        0.00,
        null
    );

    $insertIncident = static function (
        PDO $pdo,
        callable $insertId,
        string $ticketCode,
        int $machineId,
        int $locationId,
        string $status,
        string $dispatchLevel,
        ?int $assignedTechnicianId,
        ?string $resolvedAt,
        ?int $escalatedFromRestockerId,
        ?string $escalatedAt,
        ?string $deletedAt
    ): void {
        $insertId(
            $pdo,
            'INSERT INTO incidents
                (ticket_code, machine_id, location_id, category, description, urgency, status,
                 dispatch_level, assigned_technician_id, resolved_at, escalated_from_restocker_id,
                 escalated_to_sat_at, deleted_at)
             VALUES (?, ?, ?, \'OTHER\', ?, \'MEDIUM\', ?, ?, ?, ?, ?, ?, ?)',
            [
                $ticketCode,
                $machineId,
                $locationId,
                'Incidencia de certificación del Cuadro de Mando de Reposición.',
                $status,
                $dispatchLevel,
                $assignedTechnicianId,
                $resolvedAt,
                $escalatedFromRestockerId,
                $escalatedAt,
                $deletedAt,
            ]
        );
    };

    $insertIncident($pdo, $insertId, 'INC-2026-0001', $machineHospital, $locHospital, 'RESOLVED', 'L1_RESTOCKER', $restockerOne, '2026-11-10 13:00:00+01', null, null, null);
    $insertIncident($pdo, $insertId, 'INC-2026-0002', $machineHospital, $locHospital, 'CLOSED', 'L1_RESTOCKER', $restockerOne, '2026-11-11 13:00:00+01', null, null, null);
    $insertIncident($pdo, $insertId, 'INC-2026-0003', $machineSchool, $locSchool, 'RESOLVED', 'L1_RESTOCKER', $restockerTwo, '2026-11-15 13:00:00+01', null, null, null);
    // Una sola incidencia viva por máquina (Art. V.2): las vivas se reparten
    // entre máquinas distintas y solo la borrada comparte máquina con otra viva.
    $insertIncident($pdo, $insertId, 'INC-2026-0004', $machineInactiveSite, $locInactive, 'ASSIGNED', 'L2_SAT', $technician, null, $restockerOne, '2026-11-12 13:00:00+01', null);
    $insertIncident($pdo, $insertId, 'INC-2026-0005', $machineThermal, $locHospital, 'RESOLVED', 'L1_RESTOCKER', $restockerOne, '2026-11-13 13:00:00+01', null, null, '2026-11-13 14:00:00+01');
    $insertIncident($pdo, $insertId, 'INC-2026-0006', $machineIdle, $locSchool, 'RESOLVED', 'L1_RESTOCKER', $technician, '2026-11-14 13:00:00+01', null, null, null);

    // ─────────────────────────────────────────────────────────────────────
    // Contrato
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Contrato de lectura ---\n";

    $repository = new PdoRestockingBiAnalyticsRepository($pdo);
    $assert(
        'El repositorio implementa RestockingBiAnalyticsRepositoryInterface',
        $repository instanceof RestockingBiAnalyticsRepositoryInterface
    );

    $filter = new RestockingBiFilterDto('2026-11-07', '2026-11-20');

    // ─────────────────────────────────────────────────────────────────────
    // 1. Cabeceras de visita (D1, C1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cabeceras de visita M34 ---\n";

    $headers = $repository->fetchVisitHeaders($filter);
    $assert('Devuelve una fila por par (máquina, reponedor)', count($headers) === 7, 'filas ' . count($headers));
    $assert(
        'La cabecera publica exactamente las claves del contrato',
        array_keys($headers[0]) === [
            'machine_id',
            'restocker_id',
            'completed_visits_count',
            'clean_in_stock_visits_count',
            'thermal_aborted_visits_count',
            'completed_duration_seconds_sum',
        ]
    );

    $machineHospitalHeaders = array_values(array_filter(
        $headers,
        static fn(array $row): bool => $row['machine_id'] === $machineHospital && $row['restocker_id'] === $restockerOne
    ));
    $assert('La máquina 1 agrupa sus dos visitas del reponedor 1', count($machineHospitalHeaders) === 1);
    $assert(
        'La visita con un carril vacío no es limpia y la visita sin líneas sí (D1)',
        $machineHospitalHeaders[0]['completed_visits_count'] === 2
        && $machineHospitalHeaders[0]['clean_in_stock_visits_count'] === 1
    );
    $assert(
        'La duración real suma solo visitas completadas',
        abs($machineHospitalHeaders[0]['completed_duration_seconds_sum'] - 1920.0) < 0.001,
        'segundos ' . $machineHospitalHeaders[0]['completed_duration_seconds_sum']
    );

    $thermalHeader = array_values(array_filter(
        $headers,
        static fn(array $row): bool => $row['machine_id'] === $machineHospital && $row['restocker_id'] === $restockerTwo
    ));
    $assert(
        'El aborto térmico se cuenta aparte y no como visita completada (Art. II)',
        count($thermalHeader) === 1
        && $thermalHeader[0]['completed_visits_count'] === 0
        && $thermalHeader[0]['thermal_aborted_visits_count'] === 1
    );

    $assert(
        'La visita exactamente en from entra en la ventana (C1)',
        array_sum(array_column($headers, 'completed_visits_count')) === 7
    );
    $schoolHeaders = array_values(array_filter(
        $headers,
        static fn(array $row): bool => $row['machine_id'] === $machineSchool
    ));
    $assert(
        'La ventana incluye el borde from y excluye la visita de to+1 día',
        count($schoolHeaders) === 3
        && array_sum(array_column($schoolHeaders, 'completed_visits_count')) === 3
    );
    $assert(
        'La máquina sin actividad no aparece en las cabeceras',
        array_filter($headers, static fn(array $row): bool => $row['machine_id'] === $machineIdle) === []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Carriles y guarda de producto (QA-5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Carriles M34 + M32 ---\n";

    $slotItems = $repository->fetchSlotItemsByMachineProduct($filter);
    $assert('Agrega los carriles por (máquina, producto)', count($slotItems) === 3, 'filas ' . count($slotItems));
    $assert(
        'El carril publica las claves del contrato',
        array_keys($slotItems[0]) === [
            'machine_id',
            'product_id',
            'audited_slots_count',
            'stockout_slots_count',
            'low_threshold_slots_count',
            'added_units',
            'expired_units',
            'damaged_units',
            'expired_loss_eur',
            'damaged_loss_eur',
            'commercial_spoilage_loss_eur',
        ]
    );

    $hospitalSlots = array_values(array_filter(
        $slotItems,
        static fn(array $row): bool => $row['machine_id'] === $machineHospital
    ))[0];
    $assert(
        'Tres carriles auditados, uno en rotura y uno bajo umbral',
        $hospitalSlots['audited_slots_count'] === 3
        && $hospitalSlots['stockout_slots_count'] === 1
        && $hospitalSlots['low_threshold_slots_count'] === 1
    );
    $assert(
        'Las unidades repuestas y retiradas no se multiplican por visitas ni actas',
        $hospitalSlots['added_units'] === 17
        && $hospitalSlots['expired_units'] === 2
        && $hospitalSlots['damaged_units'] === 1
    );
    $assert(
        'La merma FEFO y la dañada son exactas al céntimo (D2)',
        abs($hospitalSlots['expired_loss_eur'] - 5.00) < 0.00001
        && abs($hospitalSlots['damaged_loss_eur'] - 2.50) < 0.00001
    );
    $assert(
        'La merma comercial suma FEFO y dañado y coincide al céntimo (QA-A4)',
        abs(
            $hospitalSlots['expired_loss_eur']
            + $hospitalSlots['damaged_loss_eur']
            - $hospitalSlots['commercial_spoilage_loss_eur']
        ) < 0.00001
    );

    $changedLane = array_values(array_filter(
        $slotItems,
        static fn(array $row): bool => $row['machine_id'] === $machineSchool
    ))[0];
    $assert(
        'Un carril con el producto cambiado no suma bajo umbral (QA-5)',
        $changedLane['audited_slots_count'] === 1 && $changedLane['low_threshold_slots_count'] === 0
    );

    $restockerSlots = $repository->fetchSlotItemsByRestocker($filter);
    $assert('Agrega los carriles por reponedor', count($restockerSlots) === 2, 'filas ' . count($restockerSlots));
    $restockerOneSlots = array_values(array_filter(
        $restockerSlots,
        static fn(array $row): bool => $row['restocker_id'] === $restockerOne
    ))[0];
    $assert(
        'El reponedor 1 conserva su merma comercial exacta',
        $restockerOneSlots['audited_slots_count'] === 3
        && abs($restockerOneSlots['commercial_spoilage_loss_eur'] - 7.50) < 0.00001
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Actas térmicas M29 (QA-2, D2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Actas sanitarias M29 ---\n";

    $thermal = $repository->fetchThermalRecords($filter);
    $assert('Cuenta las actas por máquina con actividad en la ventana', count($thermal) === 2, 'filas ' . count($thermal));

    $hospitalThermal = array_values(array_filter(
        $thermal,
        static fn(array $row): bool => $row['machine_id'] === $machineHospital
    ))[0];
    $assert(
        'La máquina vacía cuenta como acta y no suma unidades ni importe (QA-2)',
        $hospitalThermal['records_count'] === 2
        && $hospitalThermal['discarded_units_count'] === 4
        && abs($hospitalThermal['estimated_merchandise_loss_eur'] - 10.00) < 0.00001
    );
    $assert(
        'La máquina con solo un acta térmica entra en la consulta (QA-4)',
        count(array_filter($thermal, static fn(array $row): bool => $row['machine_id'] === $machineThermal)) === 1
    );
    $assert(
        'El acta fuera de la ventana no se cuenta (C1)',
        array_filter($thermal, static fn(array $row): bool => $row['records_count'] > 2) === []
    );
    $assert(
        'Con filtro por reponedor la térmica no se segmenta (D2)',
        $repository->fetchThermalRecords(new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, null, $restockerOne)) === []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Turnos (C3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Turnos válidos M22/M31 ---\n";

    $shifts = $repository->fetchValidShifts($filter);
    $assert('Cuenta un turno válido por reponedor', count($shifts) === 3, 'filas ' . count($shifts));
    $assert(
        'El turno anulado (OFF_SHIFT con cierre inmediato) no cuenta (C3)',
        array_values(array_filter(
            $shifts,
            static fn(array $row): bool => $row['technician_id'] === $restockerOne
        ))[0]['valid_shifts_count'] === 1
    );
    $assert(
        'El técnico SAT no entra en los turnos de reposición (C3)',
        array_filter($shifts, static fn(array $row): bool => $row['technician_id'] === $technician) === []
    );
    $assert(
        'Con filtro territorial los turnos no se segmentan (C3)',
        $repository->fetchValidShifts(new RestockingBiFilterDto('2026-11-07', '2026-11-20', $zoneNorth)) === []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Arqueos M35 (RF-05.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Arqueos de efectivo M35 ---\n";

    $cash = $repository->fetchCashCollections($filter);
    $assert('Agrupa los arqueos por reponedor', count($cash) === 2, 'filas ' . count($cash));

    $restockerOneCash = array_values(array_filter(
        $cash,
        static fn(array $row): bool => $row['restocker_id'] === $restockerOne
    ))[0];
    $assert(
        'Separa bolsas cerradas, descuadres y pendientes (RF-05.3)',
        $restockerOneCash['reconciled_ok_count'] === 1
        && $restockerOneCash['discrepancy_flagged_count'] === 1
        && $restockerOneCash['pending_audit_count'] === 1
    );
    $assert(
        'El descuadre absoluto suma solo las bolsas cerradas (RF-05.3)',
        abs($restockerOneCash['total_abs_discrepancy_eur'] - 3.50) < 0.00001
    );
    $assert(
        'La recaudación independiente (restock_visit_id NULL) entra en el arqueo',
        array_values(array_filter(
            $cash,
            static fn(array $row): bool => $row['restocker_id'] === $restockerTwo
        ))[0]['pending_audit_count'] === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Sinergia L1 y escalados (C4, QA-5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Sinergia Nivel 1 M36 ---\n";

    $l1 = $repository->fetchL1Incidents($filter);
    $assert('Agrupa L1 y escalados por reponedor', count($l1) === 2, 'filas ' . count($l1));

    $restockerOneL1 = array_values(array_filter(
        $l1,
        static fn(array $row): bool => $row['restocker_id'] === $restockerOne
    ))[0];
    $assert(
        'Cuenta dos L1 efectivas y un escalado al SAT en columnas separadas (C4)',
        $restockerOneL1['l1_resolved_count'] === 2
        && $restockerOneL1['escalated_to_sat_count'] === 1
    );
    $assert(
        'La incidencia borrada y la del técnico SAT quedan fuera (C4)',
        array_filter($l1, static fn(array $row): bool => $row['restocker_id'] === $technician) === []
        && $restockerOneL1['l1_resolved_count'] === 2
    );
    $assert(
        'El reponedor 2 conserva su L1 sin escalados',
        array_values(array_filter(
            $l1,
            static fn(array $row): bool => $row['restocker_id'] === $restockerTwo
        ))[0] === [
            'restocker_id' => $restockerTwo,
            'l1_resolved_count' => 1,
            'escalated_to_sat_count' => 0,
        ]
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Catálogo de máquinas y bajas lógicas (QA-4, Art. III)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Catálogo de máquinas ---\n";

    $catalog = $repository->fetchMachineCatalog($filter);
    $codes = array_column($catalog, 'machine_code');
    $assert(
        'Incluye las cinco máquinas con actividad y excluye la inactiva sin actividad',
        $codes === ['MAQ-CEIP-01', 'MAQ-EXT-04', 'MAQ-EXT-05', 'MAQ-HOSP-02', 'MAQ-HOSP-03'],
        implode(',', $codes)
    );
    $assert(
        'La máquina dada de baja lógica se publica con is_active = false (QA-4, Art. III)',
        array_values(array_filter(
            $catalog,
            static fn(array $row): bool => $row['machine_id'] === $machineNoZone
        ))[0]['is_active'] === false
    );
    $assert(
        'La sede sin zona publica zone_id y zone_name nulos (QA-A3)',
        array_values(array_filter(
            $catalog,
            static fn(array $row): bool => $row['machine_id'] === $machineNoZone
        ))[0]['zone_id'] === null
    );
    $assert(
        'La máquina con solo un acta térmica entra en el catálogo (QA-4)',
        in_array('MAQ-HOSP-03', $codes, true)
    );
    $assert(
        'Con filtro por reponedor solo aparecen sus máquinas y sin la térmica ajena (D2)',
        array_column(
            $repository->fetchMachineCatalog(new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, null, $restockerOne)),
            'machine_code'
        ) === ['MAQ-CEIP-01', 'MAQ-HOSP-02']
    );
    $assert(
        'Con filtro de zona se excluyen las sedes sin demarcación (QA-A3)',
        array_column(
            $repository->fetchMachineCatalog(new RestockingBiFilterDto('2026-11-07', '2026-11-20', $zoneNorth)),
            'machine_code'
        ) === ['MAQ-CEIP-01', 'MAQ-HOSP-02', 'MAQ-HOSP-03']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Catálogos disponibles (QA-4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Catálogos disponibles ---\n";

    $available = $repository->fetchAvailableFilters($filter);
    $assert('Publica las tres colecciones del contrato', array_keys($available) === ['zones', 'locations', 'restockers']);
    $assert(
        'Las zonas activas y las inactivas con actividad entran; la histórica sin datos no',
        array_column($available['zones'], 'code') === ['ZN-NORTE', 'ZN-SUR'],
        implode(',', array_column($available['zones'], 'code'))
    );
    $assert(
        'La zona inactiva con actividad se publica con is_active = false (QA-4)',
        array_values(array_filter(
            $available['zones'],
            static fn(array $row): bool => $row['code'] === 'ZN-SUR'
        ))[0]['is_active'] === false
    );
    $assert(
        'Las sedes activas y la inactiva con actividad entran en el catálogo',
        array_column($available['locations'], 'site_code') === ['CEIP-02', 'EXT-04', 'EXT-05', 'HOSP-01'],
        implode(',', array_column($available['locations'], 'site_code'))
    );
    $assert(
        'La sede sin zona publica zone_id nulo (QA-A3)',
        array_values(array_filter(
            $available['locations'],
            static fn(array $row): bool => $row['site_code'] === 'EXT-04'
        ))[0]['zone_id'] === null
    );
    $assert(
        'Los reponedores activos, los inactivos con actividad y sus códigos nulos entran (QA-4)',
        array_column($available['restockers'], 'name') === ['Ana Ruiz', 'Carlos Méndez', 'Lucía Navarro', 'Marta Ibáñez'],
        implode(',', array_column($available['restockers'], 'name'))
    );
    $assert(
        'El reponedor sin operator_code lo publica como null (QA-A3)',
        array_values(array_filter(
            $available['restockers'],
            static fn(array $row): bool => $row['name'] === 'Carlos Méndez'
        ))[0]['operator_code'] === null
    );

    $selectedZoneFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', $zoneHistoric);
    $selectedFilters = $repository->fetchAvailableFilters($selectedZoneFilter);
    $assert(
        'La zona seleccionada sin actividad se conserva en el selector (QA-4)',
        in_array('ZN-HIST', array_column($selectedFilters['zones'], 'code'), true)
    );
    $assert(
        'La zona seleccionada no inventa actividad: el catálogo de máquinas queda vacío',
        $repository->fetchMachineCatalog($selectedZoneFilter) === []
    );

    $selectedRestockerFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, null, $restockerFive);
    $assert(
        'El reponedor seleccionado sin actividad se conserva en el selector (QA-4)',
        in_array('Pablo Gil', array_column(
            $repository->fetchAvailableFilters($selectedRestockerFilter)['restockers'],
            'name'
        ), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Periodo vacío (C5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Periodo sin actividad ---\n";

    $emptyFilter = new RestockingBiFilterDto('2026-01-01', '2026-01-02');
    $assert('El catálogo de máquinas queda vacío', $repository->fetchMachineCatalog($emptyFilter) === []);
    $assert('No hay cabeceras de visita', $repository->fetchVisitHeaders($emptyFilter) === []);
    $assert('No hay carriles', $repository->fetchSlotItemsByMachineProduct($emptyFilter) === []);
    $assert('No hay mermas térmicas', $repository->fetchThermalRecords($emptyFilter) === []);
    $assert('No hay turnos', $repository->fetchValidShifts($emptyFilter) === []);
    $assert('No hay arqueos', $repository->fetchCashCollections($emptyFilter) === []);
    $assert('No hay L1 ni escalados', $repository->fetchL1Incidents($emptyFilter) === []);

    $emptyAvailable = $repository->fetchAvailableFilters($emptyFilter);
    $assert(
        'El selector conserva los activos aunque no haya actividad',
        array_column($emptyAvailable['zones'], 'code') === ['ZN-NORTE']
        && array_column($emptyAvailable['locations'], 'site_code') === ['CEIP-02', 'EXT-04', 'HOSP-01']
    );
    $assert(
        'El selector de reponedores conserva solo los activos sin actividad',
        array_column($emptyAvailable['restockers'], 'name') === ['Ana Ruiz', 'Lucía Navarro']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 10. Filtro territorial y por máquina
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Filtros territoriales y por máquina ---\n";

    $zoneFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', $zoneNorth);
    $zoneHeaders = $repository->fetchVisitHeaders($zoneFilter);
    $assert(
        'El filtro de zona acota las visitas a sus sedes (C1)',
        count($zoneHeaders) === 5
        && array_sum(array_column($zoneHeaders, 'completed_visits_count')) === 5
        && array_sum(array_column($zoneHeaders, 'thermal_aborted_visits_count')) === 1
    );
    $assert(
        'El filtro de zona acota también los arqueos',
        array_values(array_filter(
            $repository->fetchCashCollections($zoneFilter),
            static fn(array $row): bool => $row['restocker_id'] === $restockerTwo
        )) === []
    );

    $locationFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, $locSchool);
    $locationHeaders = $repository->fetchVisitHeaders($locationFilter);
    $assert(
        'El filtro de sede acota a las visitas de esa sede',
        array_sum(array_column($locationHeaders, 'completed_visits_count')) === 3
    );

    $machineFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, null, $restockerTwo);
    $machineThermalScoped = $repository->fetchThermalRecords($machineFilter);
    $assert(
        'El filtro por reponedor bloquea la térmica y conserva sus carriles',
        $machineThermalScoped === []
        && count($repository->fetchSlotItemsByRestocker($machineFilter)) === 1
    );

    echo "\n--- Resultado ---\n";
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RBI02_SCHEMA);
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
        $check->execute([RBI02_SCHEMA]);
        $residue = (int)$check->fetchColumn();
    } catch (Throwable $cleanupException) {
        $residue = -1;
    }
}

if ($crash !== null) {
    $failures++;
    echo "\n[FAIL] Excepción no controlada: " . $crash->getMessage() . "\n";
}

$assert('Limpieza sin residuo del esquema temporal', $residue === 0, 'residuo ' . var_export($residue, true));

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";
echo ' RESULTADO: ' . ($failures === 0 ? '100% EN VERDE' : $failures . ' FALLOS') . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
