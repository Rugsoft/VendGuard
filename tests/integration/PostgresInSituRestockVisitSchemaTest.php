<?php

declare(strict_types=1);

/**
 * PostgresInSituRestockVisitSchemaTest · T-RVF-02
 *
 * Certifica en el motor real el esquema del Módulo 34 (`34-in-situ-restock-visit`): el tipo
 * nativo `restock_visit_status`, la evolución de la Tabla 43 `machine_planogram_slots` y las
 * Tablas 47 `machine_restock_visits` y 48 `machine_restock_visit_items` con su blindaje
 * constitucional. No comprueba servicios ni API —eso llega con las tareas `T-RVF-03` a `T-RVF-12`—
 * sino las garantías que la base impone por sí sola, porque son las que ningún camino de código
 * puede saltarse:
 *
 *   1. **Tipos nativos (RF-01.2, Art. III.3).** `restock_visit_status` declara los dos desenlaces
 *      del contrato y `MACHINE_RESTOCK_VISIT` se puede castear en `audit_entity_type`.
 *   2. **Forma de la Tabla 43 (D1, QA 1).** Doce columnas en el orden del contrato, con
 *      `current_batch_code` y `current_expiration_date` nulos por defecto y el formato cerrado del
 *      lote mordiendo en el motor.
 *   3. **Forma de las Tablas 47 y 48 (C1, QA 3).** Columnas en el orden del contrato, identidad
 *      única de la visita y de su línea, claves foráneas `RESTRICT`/`CASCADE` y las nueve
 *      restricciones `CHECK` del snapshot contable y las cotas aritméticas.
 *   4. **Idempotencia del móvil (D4).** El par `(restocker_id, client_mutation_id)` no admite dos
 *      visitas: el reenvío de la misma mutación se rechaza con `23505` en lugar de descontar dos
 *      veces de la furgoneta.
 *   5. **Cerrojo térmico (Art. II, RNF-02, QA 4).** Una máquina refrigerada no admite visita sin
 *      lectura de cabina; una visita `COMPLETED` por encima del techo (4,0 °C en
 *      `PERISHABLE_FOOD`/`COMBO`, 8,0 °C en `COLD_DRINKS`) se rechaza con `45000`; y el protocolo
 *      `ABORTED_THERMAL_BREACH` exige precinto, foto y nota de 20 caracteres reales.
 *   6. **Cero carga en el aborto (Art. II).** Una línea con `qty_added > 0` sobre una visita
 *      abortada se rechaza con `45000`, y las identidades aritméticas del `C1` y el snapshot de
 *      merma (`QA 3`) muerden con `23514`.
 *   7. **Inmutabilidad e historial (Art. III.1 y III.3).** El `UPDATE` de la visita y de su línea
 *      se rechaza con `45000`, el `DELETE` sin ventana de purga también, y dentro de la ventana del
 *      arnés el borrado funciona: la tabla no es un sumidero.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo dice
 *   y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresInSituRestockVisitSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites de otros módulos. */
const VISIT_TEST_SCHEMA = 't_rvf02_visita_in_situ';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

/** Marca del disparador de inmutabilidad histórica (Art. III.3). */
const MUTATION_VIOLATION_MARKER = 'ART_III_3_IMMUTABLE_VISIT_VIOLATION';

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
echo " VENDGUARD · T-RVF-02 · Esquema de la visita de reposición in situ (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo lo que las reglas necesitan para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

$createLocation = static function (PDO $pdo, string $suffix): int {
    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address)
         VALUES (:site_code, :site_code_key, :name, :address)
         RETURNING id'
    );
    $stmt->execute([
        ':site_code' => 'SEDE-RVF02-' . strtoupper($suffix),
        ':site_code_key' => 'sede-rvf02-' . strtolower($suffix),
        ':name' => 'Sede de certificación de la visita in situ ' . $suffix,
        ':address' => 'Carrer del Repos 34, Barcelona',
    ]);

    return (int)$stmt->fetchColumn();
};

$createMachine = static function (PDO $pdo, int $locationId, string $code, string $type): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, :machine_type, :floor_wing)
         RETURNING id'
    );
    $stmt->execute([
        ':location_id' => $locationId,
        ':code' => $code,
        ':model' => 'Modelo de certificación ' . $code,
        ':machine_type' => $type,
        ':floor_wing' => 'Planta de certificación',
    ]);

    return (int)$stmt->fetchColumn();
};

$createUser = static function (PDO $pdo, string $suffix, string $role): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES (:name, :email, :email_key, :password_hash, :role)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => 'Actor de certificación ' . $suffix,
        ':email' => 'rvf02.' . strtolower($suffix) . '@gestion-vending.test',
        ':email_key' => 'rvf02.' . strtolower($suffix) . '@gestion-vending.test',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => $role,
    ]);

    return (int)$stmt->fetchColumn();
};

$createProduct = static function (PDO $pdo, string $sku, string $name, string $category, string $cost): int {
    $stmt = $pdo->prepare(
        'INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES (:sku, :name, :category, \'UNIT\', TRUE, 5, :unit_cost, 2.40)
         RETURNING id'
    );
    $stmt->execute([
        ':sku' => $sku,
        ':name' => $name,
        ':category' => $category,
        ':unit_cost' => $cost,
    ]);

    return (int)$stmt->fetchColumn();
};

$createSlot = static function (
    PDO $pdo,
    int $machineId,
    string $slotCode,
    int $productId,
    int $estimatedQty,
    ?string $batchCode = null,
    ?string $expirationExpression = null
): int {
    $sql = 'INSERT INTO machine_planogram_slots
                (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold,
                 current_batch_code, current_expiration_date)
            VALUES (:machine_id, :slot_code, :product_id, 12, :estimated_qty, 2, :batch_code, ';
    $sql .= $expirationExpression === null ? 'NULL' : $expirationExpression;
    $sql .= ') RETURNING id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':machine_id' => $machineId,
        ':slot_code' => $slotCode,
        ':product_id' => $productId,
        ':estimated_qty' => $estimatedQty,
        ':batch_code' => $batchCode,
    ]);

    return (int)$stmt->fetchColumn();
};

$insertVisit = static function (
    PDO $pdo,
    int $machineId,
    int $restockerId,
    string $visitCode,
    string $status,
    ?float $cabinTemp,
    string $mutationId,
    bool $seal = false,
    ?string $photo = null,
    ?string $notes = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machine_restock_visits
             (visit_code, machine_id, restocker_id, status, cabin_temp_celsius,
              precautionary_seal_applied, photo_evidence_url, internal_notes, client_mutation_id)
         VALUES (:visit_code, :machine_id, :restocker_id, :status, :cabin_temp,
              :seal, :photo, :notes, :client_mutation_id)
         RETURNING id'
    );
    $stmt->execute([
        ':visit_code' => $visitCode,
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':status' => $status,
        ':cabin_temp' => $cabinTemp,
        ':seal' => $seal ? 'TRUE' : 'FALSE',
        ':photo' => $photo,
        ':notes' => $notes,
        ':client_mutation_id' => $mutationId,
    ]);

    return (int)$stmt->fetchColumn();
};

$insertItem = static function (
    PDO $pdo,
    int $visitId,
    int $slotId,
    string $slotCode,
    int $productId,
    int $before,
    int $withdrawnExpired,
    int $withdrawnDamaged,
    int $added,
    int $after,
    ?string $loadedBatch,
    string $unitCost,
    string $loss,
    ?string $withdrawnBatch = null,
    ?string $withdrawnExpirationExpression = null,
    ?string $loadedExpirationExpression = null
): int {
    $sql = 'INSERT INTO machine_restock_visit_items
                (visit_id, slot_id, slot_code, product_id, qty_before,
                 qty_withdrawn_expired, qty_withdrawn_damaged, qty_added, qty_after,
                 withdrawn_batch_code, withdrawn_expiration_date,
                 loaded_batch_code, loaded_expiration_date,
                 unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
            VALUES (:visit_id, :slot_id, :slot_code, :product_id, :qty_before,
                 :withdrawn_expired, :withdrawn_damaged, :qty_added, :qty_after,
                 :withdrawn_batch, ';
    $sql .= $withdrawnExpirationExpression === null ? 'NULL' : $withdrawnExpirationExpression;
    $sql .= ', :loaded_batch, ';
    $sql .= $loadedExpirationExpression === null ? 'NULL' : $loadedExpirationExpression;
    $sql .= ', :unit_cost, :loss) RETURNING id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':visit_id' => $visitId,
        ':slot_id' => $slotId,
        ':slot_code' => $slotCode,
        ':product_id' => $productId,
        ':qty_before' => $before,
        ':withdrawn_expired' => $withdrawnExpired,
        ':withdrawn_damaged' => $withdrawnDamaged,
        ':qty_added' => $added,
        ':qty_after' => $after,
        ':withdrawn_batch' => $withdrawnBatch,
        ':loaded_batch' => $loadedBatch,
        ':unit_cost' => $unitCost,
        ':loss' => $loss,
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 030) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, VISIT_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 030 del Módulo 34',
        in_array('030_in_situ_restock_visit_and_fefo.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %d · pendientes: %s', $schemaReport['statements'], count($schemaReport['migrations']), json_encode($convergence['pending']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos y entidad auditable (RF-01.2, Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos de la visita in situ y entidad auditable ---\n";

    $enumLabels = static function (PDO $pdo, string $type): array {
        $stmt = $pdo->prepare(
            "SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder)
               FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid
              WHERE t.typname = :type AND t.typnamespace = current_schema()::regnamespace"
        );
        $stmt->execute([':type' => $type]);
        $labels = (string)$stmt->fetchColumn();

        return $labels === '' ? [] : explode(',', $labels);
    };

    $assert(
        'restock_visit_status declara los dos desenlaces del contrato en su orden',
        $enumLabels($pgPdo, 'restock_visit_status') === ['COMPLETED', 'ABORTED_THERMAL_BREACH'],
        implode(',', $enumLabels($pgPdo, 'restock_visit_status'))
    );

    $auditCast = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->query("SELECT 'MACHINE_RESTOCK_VISIT'::audit_entity_type")->fetch();
    });

    $assert(
        'La entidad auditable MACHINE_RESTOCK_VISIT se puede castear en el motor',
        $auditCast === null,
        $auditCast === null ? 'admitida' : 'rechazado con ' . $auditCast['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de la Tabla 43 machine_planogram_slots (D1, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 43 machine_planogram_slots ---\n";

    $slotColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_planogram_slots');
    $assert(
        'La tabla declara sus doce columnas canónicas con el lote y la caducidad al final',
        array_keys($slotColumns) === [
            'id', 'machine_id', 'slot_code', 'product_id', 'max_capacity',
            'current_estimated_qty', 'min_threshold', 'is_active', 'created_at', 'updated_at',
            'current_batch_code', 'current_expiration_date',
        ],
        implode(', ', array_keys($slotColumns))
    );

    $assert(
        'El lote vigente admite NULL y se acota a 40 caracteres; la caducidad es DATE',
        $slotColumns['current_batch_code']['nullable'] === true
            && $slotColumns['current_batch_code']['max_length'] === 40
            && $slotColumns['current_expiration_date']['nullable'] === true
            && $slotColumns['current_expiration_date']['udt_name'] === 'date',
        sprintf(
            'lote=%s(%s) · caducidad=%s',
            $slotColumns['current_batch_code']['udt_name'] ?? '?',
            $slotColumns['current_batch_code']['max_length'] ?? '?',
            $slotColumns['current_expiration_date']['udt_name'] ?? '?'
        )
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Forma de la Tabla 47 machine_restock_visits (RF-01.2, D2, D4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 47 machine_restock_visits ---\n";

    $visitColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_restock_visits');
    $assert(
        'La tabla declara sus diecisiete columnas canónicas en el orden del contrato',
        array_keys($visitColumns) === [
            'id', 'visit_code', 'machine_id', 'restocker_id', 'loading_order_id', 'route_schedule_id',
            'status', 'started_at', 'completed_at', 'cabin_temp_celsius', 'hygiene_checklist_json',
            'precautionary_seal_applied', 'photo_evidence_url', 'internal_notes',
            'triggered_incident_id', 'client_mutation_id', 'created_at',
        ],
        implode(', ', array_keys($visitColumns))
    );

    $visitIndexes = [];
    foreach ($pgPdo->query(
        "SELECT indexname, indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND tablename = 'machine_restock_visits'"
    )->fetchAll() as $index) {
        $visitIndexes[(string)$index['indexname']] = (string)$index['indexdef'];
    }

    $assert(
        'visit_code es único y el par (restocker_id, client_mutation_id) declara la idempotencia',
        isset($visitIndexes['uq_machine_restock_visits_code'], $visitIndexes['uq_machine_restock_visits_mutation'])
            && str_contains($visitIndexes['uq_machine_restock_visits_code'], 'UNIQUE')
            && str_contains($visitIndexes['uq_machine_restock_visits_mutation'], 'restocker_id')
            && str_contains($visitIndexes['uq_machine_restock_visits_mutation'], 'client_mutation_id'),
        implode(' | ', array_keys($visitIndexes))
    );

    $visitForeignKeys = [];
    foreach ($pgPdo->query(
        "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_restock_visits' AND con.contype = 'f'"
    )->fetchAll() as $foreignKey) {
        $visitForeignKeys[(string)$foreignKey['name']] = (string)$foreignKey['definition'];
    }

    $assert(
        'Las cuatro claves foráneas referencian máquina, reponedor, orden de carga y avería con RESTRICT y CASCADE',
        count($visitForeignKeys) === 4
            && str_contains($visitForeignKeys['fk_machine_restock_visits_machine'] ?? '', 'machines(id)')
            && str_contains($visitForeignKeys['fk_machine_restock_visits_machine'] ?? '', 'ON DELETE RESTRICT')
            && str_contains($visitForeignKeys['fk_machine_restock_visits_restocker'] ?? '', 'users(id)')
            && str_contains($visitForeignKeys['fk_machine_restock_visits_loading_order'] ?? '', 'product_loading_orders(id)')
            && str_contains($visitForeignKeys['fk_machine_restock_visits_triggered_incident'] ?? '', 'incidents(id)')
            && substr_count(implode(' ', $visitForeignKeys), 'ON UPDATE CASCADE') === 4,
        implode(' | ', array_keys($visitForeignKeys))
    );

    $visitChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_restock_visits' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $expectedVisitChecks = [
        'chk_machine_restock_visits_code_format',
        'chk_machine_restock_visits_mutation_format',
        'chk_machine_restock_visits_time_order',
        'chk_machine_restock_visits_temp_range',
        'chk_machine_restock_visits_thermal_abort_coherence',
    ];
    $assert(
        'Las cinco restricciones CHECK de la visita están declaradas en el motor',
        array_values(array_intersect($expectedVisitChecks, $visitChecks)) === $expectedVisitChecks,
        'declaradas: ' . implode(', ', $visitChecks)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Forma de la Tabla 48 machine_restock_visit_items (C1, QA 3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 48 machine_restock_visit_items ---\n";

    $itemColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_restock_visit_items');
    $assert(
        'La tabla declara sus diecisiete columnas canónicas en el orden del contrato',
        array_keys($itemColumns) === [
            'id', 'visit_id', 'slot_id', 'slot_code', 'product_id', 'qty_before',
            'qty_withdrawn_expired', 'qty_withdrawn_damaged', 'qty_added', 'qty_after',
            'withdrawn_batch_code', 'withdrawn_expiration_date',
            'loaded_batch_code', 'loaded_expiration_date',
            'unit_cost_snapshot_eur', 'commercial_spoilage_loss_eur', 'created_at',
        ],
        implode(', ', array_keys($itemColumns))
    );

    $itemChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_restock_visit_items' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $expectedItemChecks = [
        'chk_machine_restock_visit_items_qty_bounds',
        'chk_machine_restock_visit_items_batch_formats',
        'chk_machine_restock_visit_items_cost_coherence',
    ];
    $assert(
        'Las tres restricciones CHECK de la línea están declaradas en el motor',
        array_values(array_intersect($expectedItemChecks, $itemChecks)) === $expectedItemChecks,
        'declaradas: ' . implode(', ', $itemChecks)
    );

    $itemUniqueDefinition = (string)$pgPdo->query(
        "SELECT indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND indexname = 'uq_machine_restock_visit_item_slot'"
    )->fetchColumn();
    $assert(
        'El par (visit_id, slot_code) es la identidad única de la línea',
        str_contains($itemUniqueDefinition, 'UNIQUE')
            && str_contains($itemUniqueDefinition, 'visit_id')
            && str_contains($itemUniqueDefinition, 'slot_code'),
        $itemUniqueDefinition
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Escenario vivo: máquinas refrigeradas, producto, carril y actor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario vivo: parque mínimo del módulo ---\n";

    $locationId = $createLocation($pgPdo, 'uno');
    $comboMachineId = $createMachine($pgPdo, $locationId, 'VEND-RVF-COMBO', 'COMBO');
    $coldMachineId = $createMachine($pgPdo, $locationId, 'VEND-RVF-COLD', 'COLD_DRINKS');
    $restockerId = $createUser($pgPdo, 'lucia', 'RESTOCKER');
    $productId = $createProduct($pgPdo, 'SAND-RVF-01', 'Sándwich de certificación', 'PERISHABLE_FOOD', '1.15');
    $slotId = $createSlot($pgPdo, $comboMachineId, '11', $productId, 6);
    $secondSlotId = $createSlot($pgPdo, $comboMachineId, '13', $productId, 4);

    $assert(
        'El parque mínimo se levanta con dos máquinas refrigeradas, un reponedor y un carril',
        $comboMachineId > 0 && $coldMachineId > 0 && $restockerId > 0 && $productId > 0 && $slotId > 0 && $secondSlotId > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Idempotencia del móvil (D4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Idempotencia por client_mutation_id ---\n";

    $completedVisitId = $insertVisit(
        $pgPdo,
        $comboMachineId,
        $restockerId,
        'MRV-2026-0001',
        'COMPLETED',
        3.0,
        'mutation-completed-0001',
        false,
        null,
        'Visita de certificación con retirada FEFO de dos unidades caducadas.'
    );

    $duplicateCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $comboMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, cabin_temp_celsius, client_mutation_id)
             VALUES (:code, :machine, :restocker, 3.0, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0001',
            ':machine' => $comboMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-completed-0099',
        ]);
    });
    $assert(
        'Un visit_code repetido se rechaza con 23505',
        $duplicateCode !== null && $duplicateCode['state'] === '23505',
        $duplicateCode === null ? 'la clave única no mordió' : 'estado=' . $duplicateCode['state']
    );

    $duplicateMutation = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $comboMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, cabin_temp_celsius, client_mutation_id)
             VALUES (:code, :machine, :restocker, 3.0, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0002',
            ':machine' => $comboMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-completed-0001',
        ]);
    });
    $assert(
        'Un client_mutation_id repetido para el mismo reponedor se rechaza con 23505',
        $duplicateMutation !== null && $duplicateMutation['state'] === '23505',
        $duplicateMutation === null ? 'la idempotencia no mordió' : 'estado=' . $duplicateMutation['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Cerrojo térmico en el motor (Art. II, RNF-02, QA 4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo térmico de apertura de puerta ---\n";

    $missingTemp = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $comboMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, client_mutation_id)
             VALUES (:code, :machine, :restocker, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0003',
            ':machine' => $comboMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-missing-temp',
        ]);
    });
    $assert(
        'Una visita sin lectura térmica en máquina refrigerada se rechaza con 45000',
        $missingTemp !== null && $missingTemp['state'] === '45000' && str_contains($missingTemp['message'], 'ART_II_CABIN_TEMP_REQUIRED'),
        $missingTemp === null ? 'la visita sin temperatura se admitió' : json_encode(['state' => $missingTemp['state'], 'message' => mb_substr($missingTemp['message'], 0, 70)])
    );

    $overCeiling = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $comboMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, cabin_temp_celsius, client_mutation_id)
             VALUES (:code, :machine, :restocker, 6.5, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0004',
            ':machine' => $comboMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-over-ceiling',
        ]);
    });
    $assert(
        'Una visita COMPLETED a 6,5 °C en COMBO se rechaza con 45000',
        $overCeiling !== null && $overCeiling['state'] === '45000' && str_contains($overCeiling['message'], 'ART_II_CABIN_TEMP_EXCEEDED'),
        $overCeiling === null ? 'la carga fuera de rango se admitió' : json_encode(['state' => $overCeiling['state'], 'message' => mb_substr($overCeiling['message'], 0, 70)])
    );

    $coldOverCeiling = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $coldMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, cabin_temp_celsius, client_mutation_id)
             VALUES (:code, :machine, :restocker, 8.5, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0005',
            ':machine' => $coldMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-cold-over-ceiling',
        ]);
    });
    $assert(
        'Una visita COMPLETED a 8,5 °C en COLD_DRINKS se rechaza con 45000 (techo 8,0 °C)',
        $coldOverCeiling !== null && $coldOverCeiling['state'] === '45000' && str_contains($coldOverCeiling['message'], 'ART_II_CABIN_TEMP_EXCEEDED'),
        $coldOverCeiling === null ? 'la carga fuera de rango se admitió' : 'estado=' . $coldOverCeiling['state']
    );

    $coldInRange = $insertVisit($pgPdo, $coldMachineId, $restockerId, 'MRV-2026-0006', 'COMPLETED', 7.5, 'mutation-cold-in-range');
    $assert(
        'Una visita COMPLETED a 7,5 °C en COLD_DRINKS se admite (por debajo del techo de 8,0 °C)',
        $coldInRange > 0
    );

    // Protocolo de aborto: sin precinto, foto ni nota no es un aborto válido (CHECK del contrato)
    $incompleteAbort = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $comboMachineId, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, status, cabin_temp_celsius, client_mutation_id)
             VALUES (:code, :machine, :restocker, \'ABORTED_THERMAL_BREACH\', 7.5, :mutation)'
        );
        $stmt->execute([
            ':code' => 'MRV-2026-0007',
            ':machine' => $comboMachineId,
            ':restocker' => $restockerId,
            ':mutation' => 'mutation-incomplete-abort',
        ]);
    });
    $assert(
        'Un aborto térmico sin precinto, foto y nota se rechaza con 23514',
        $incompleteAbort !== null && $incompleteAbort['state'] === '23514'
            && str_contains($incompleteAbort['message'], 'chk_machine_restock_visits_thermal_abort_coherence'),
        $incompleteAbort === null ? 'el aborto incompleto se admitió' : 'estado=' . $incompleteAbort['state']
    );

    $abortedVisitId = $insertVisit(
        $pgPdo,
        $comboMachineId,
        $restockerId,
        'MRV-2026-0008',
        'ABORTED_THERMAL_BREACH',
        7.5,
        'mutation-complete-abort',
        true,
        '/uploads/restock-visits/MRV-2026-0008.jpg',
        'Apertura con 7,5 °C en cabina: máquina precintada y avería comunicada al SAT de la zona.'
    );
    $assert('El aborto térmico completo se persiste con su protocolo sanitario', $abortedVisitId > 0);

    $abortAdd = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $abortedVisitId, $slotId, $productId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visit_items
                 (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
                  qty_withdrawn_damaged, qty_added, qty_after, loaded_batch_code, loaded_expiration_date,
                  unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
             VALUES (:visit, :slot, \'11\', :product, 0, 0, 0, 1, 1, \'L-RVF-01\', CURRENT_DATE + 4, 1.15, 0.00)'
        );
        $stmt->execute([
            ':visit' => $abortedVisitId,
            ':slot' => $slotId,
            ':product' => $productId,
        ]);
    });
    $assert(
        'Una línea con qty_added > 0 sobre una visita abortada se rechaza con 45000',
        $abortAdd !== null && $abortAdd['state'] === '45000' && str_contains($abortAdd['message'], 'ART_II_THERMAL_ABORT_ADD_FORBIDDEN'),
        $abortAdd === null ? 'la carga en una máquina abortada se admitió' : 'estado=' . $abortAdd['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Identidades aritméticas del C1 y snapshot contable (QA 3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Identidades aritméticas de la línea y snapshot de merma ---\n";

    $mismatchedTotal = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId, $slotId, $productId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visit_items
                 (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
                  qty_withdrawn_damaged, qty_added, qty_after, loaded_batch_code,
                  unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
             VALUES (:visit, :slot, \'11\', :product, 6, 0, 0, 1, 6, \'L-RVF-01\', 1.15, 0.00)'
        );
        $stmt->execute([':visit' => $completedVisitId, ':slot' => $slotId, ':product' => $productId]);
    });
    $assert(
        'Una línea cuyo qty_after no cuadra con la aritmética del C1 se rechaza con 23514',
        $mismatchedTotal !== null && $mismatchedTotal['state'] === '23514'
            && str_contains($mismatchedTotal['message'], 'chk_machine_restock_visit_items_qty_bounds'),
        $mismatchedTotal === null ? 'el descuadre se admitió' : 'estado=' . $mismatchedTotal['state']
    );

    $withdrawnOverStock = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId, $slotId, $productId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visit_items
                 (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
                  qty_withdrawn_damaged, qty_added, qty_after, unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
             VALUES (:visit, :slot, \'11\', :product, 2, 3, 0, 0, 0, 1.15, 3.45)'
        );
        $stmt->execute([':visit' => $completedVisitId, ':slot' => $slotId, ':product' => $productId]);
    });
    $assert(
        'Retirar más unidades que la existencia previa se rechaza con 23514',
        $withdrawnOverStock !== null && $withdrawnOverStock['state'] === '23514'
            && str_contains($withdrawnOverStock['message'], 'chk_machine_restock_visit_items_qty_bounds'),
        $withdrawnOverStock === null ? 'la retirada imposible se admitió' : 'estado=' . $withdrawnOverStock['state']
    );

    $missingBatch = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId, $slotId, $productId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visit_items
                 (visit_id, slot_id, slot_code, product_id, qty_before, qty_added, qty_after,
                  unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
             VALUES (:visit, :slot, \'11\', :product, 0, 2, 2, 1.15, 0.00)'
        );
        $stmt->execute([':visit' => $completedVisitId, ':slot' => $slotId, ':product' => $productId]);
    });
    $assert(
        'Cargar unidades sin lote de origen se rechaza con 23514',
        $missingBatch !== null && $missingBatch['state'] === '23514'
            && str_contains($missingBatch['message'], 'chk_machine_restock_visit_items_batch_formats'),
        $missingBatch === null ? 'la carga sin lote se admitió' : 'estado=' . $missingBatch['state']
    );

    $wrongLoss = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId, $secondSlotId, $productId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_restock_visit_items
                 (visit_id, slot_id, slot_code, product_id, qty_before, qty_withdrawn_expired,
                  qty_withdrawn_damaged, qty_added, qty_after,
                  unit_cost_snapshot_eur, commercial_spoilage_loss_eur)
             VALUES (:visit, :slot, \'13\', :product, 4, 2, 0, 0, 2, 1.15, 2.00)'
        );
        $stmt->execute([':visit' => $completedVisitId, ':slot' => $secondSlotId, ':product' => $productId]);
    });
    $assert(
        'Una merma comercial que no es coste × unidades se rechaza con 23514',
        $wrongLoss !== null && $wrongLoss['state'] === '23514'
            && str_contains($wrongLoss['message'], 'chk_machine_restock_visit_items_cost_coherence'),
        $wrongLoss === null ? 'el coste incoherente se admitió' : 'estado=' . $wrongLoss['state']
    );

    $itemId = $insertItem(
        $pgPdo,
        $completedVisitId,
        $slotId,
        '11',
        $productId,
        6,
        6,
        0,
        4,
        4,
        'L-RVF-02',
        '1.15',
        '6.90',
        'L-RVF-01',
        'CURRENT_DATE - 1',
        'CURRENT_DATE + 2'
    );
    $assert('La línea con retirada FEFO y snapshot de merma se persiste con su cuadre exacto', $itemId > 0);

    $storedLoss = (string)$pgPdo->query('SELECT commercial_spoilage_loss_eur FROM machine_restock_visit_items WHERE id = ' . $itemId)->fetchColumn();
    $assert(
        'El motor conserva la merma comercial congelada a 6,90 € (6 unidades × 1,15 €)',
        (float)$storedLoss === 6.90,
        'leído=' . $storedLoss
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Inmutabilidad histórica y ventana de purga (Art. III.1 y III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Inmutabilidad histórica y ventana de purga ---\n";

    $visitUpdate = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId): void {
        $stmt = $pgPdo->prepare('UPDATE machine_restock_visits SET internal_notes = :notes WHERE id = :id');
        $stmt->execute([':notes' => 'Reescritura de una visita ya cerrada en el historial.', ':id' => $completedVisitId]);
    });
    $assert(
        'El UPDATE de la visita se rechaza con 45000 por inmutabilidad (Art. III.3)',
        $visitUpdate !== null && $visitUpdate['state'] === '45000' && str_contains($visitUpdate['message'], MUTATION_VIOLATION_MARKER),
        $visitUpdate === null ? 'la visita se dejó reescribir' : 'estado=' . $visitUpdate['state']
    );

    $itemUpdate = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $itemId): void {
        $stmt = $pgPdo->prepare('UPDATE machine_restock_visit_items SET qty_added = 0 WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
    });
    $assert(
        'El UPDATE de la línea de visita se rechaza con 45000 por inmutabilidad (Art. III.3)',
        $itemUpdate !== null && $itemUpdate['state'] === '45000' && str_contains($itemUpdate['message'], MUTATION_VIOLATION_MARKER),
        $itemUpdate === null ? 'la línea se dejó reescribir' : 'estado=' . $itemUpdate['state']
    );

    $visitDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $completedVisitId): void {
        $stmt = $pgPdo->prepare('DELETE FROM machine_restock_visits WHERE id = :id');
        $stmt->execute([':id' => $completedVisitId]);
    });
    $assert(
        'El DELETE de la visita sin ventana de purga se rechaza con 45000 (Art. III.1)',
        $visitDelete !== null && $visitDelete['state'] === '45000' && str_contains($visitDelete['message'], PURGE_VIOLATION_MARKER),
        $visitDelete === null ? 'el borrado físico pasó' : 'estado=' . $visitDelete['state']
    );

    $itemDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $itemId): void {
        $stmt = $pgPdo->prepare('DELETE FROM machine_restock_visit_items WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
    });
    $assert(
        'El DELETE de la línea sin ventana de purga se rechaza con 45000 (Art. III.1)',
        $itemDelete !== null && $itemDelete['state'] === '45000' && str_contains($itemDelete['message'], PURGE_VIOLATION_MARKER),
        $itemDelete === null ? 'el borrado físico pasó' : 'estado=' . $itemDelete['state']
    );

    $purgedRows = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $completedVisitId, $itemId): array {
        $deleted = [];
        $itemStmt = $pgPdo->prepare('DELETE FROM machine_restock_visit_items WHERE id = :id');
        $itemStmt->execute([':id' => $itemId]);
        $deleted['items'] = $itemStmt->rowCount();

        $visitStmt = $pgPdo->prepare('DELETE FROM machine_restock_visits WHERE id = :id');
        $visitStmt->execute([':id' => $completedVisitId]);
        $deleted['visits'] = $visitStmt->rowCount();

        return $deleted;
    });
    $assert(
        'Dentro de la ventana de purga del arnés el borrado de la visita y su línea funciona',
        $purgedRows === ['items' => 1, 'visits' => 1],
        json_encode($purgedRows)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, VISIT_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query("SELECT to_regclass('" . VISIT_TEST_SCHEMA . ".machine_restock_visits')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus Tablas 47/48 ya no existen en el motor',
    $residueProbe === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DE LA VISITA IN SITU CERTIFICADO (T-RVF-02).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
