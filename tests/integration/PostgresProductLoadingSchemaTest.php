<?php

declare(strict_types=1);

/**
 * PostgresProductLoadingSchemaTest · T-PLD-02
 *
 * Certifica en el motor real el esquema del Módulo 33 (`33-product-loading-cold-picking`): los tres
 * tipos nativos, las Tablas 44, 45 y 46 y el cerrojo sanitario de frío y lote. No comprueba
 * servicios ni API —eso llega con las tareas `T-PLD-03` a `T-PLD-10`— sino las garantías que la
 * base de datos impone por sí sola, porque son las que ningún camino de código puede saltarse:
 *
 *   1. **Tipos nativos (QA 4).** `product_loading_order_status` declara los cinco estados del ciclo,
 *      `product_loading_demand_mode` los dos modos de demanda y `product_loading_item_reason` los
 *      cuatro motivos de línea; `RESTOCKER_VAN_STOCK` y `PRODUCT_LOADING_ORDER` se pueden castear
 *      en `audit_entity_type`.
 *   2. **Forma de la Tabla 44.** Ocho columnas en el orden del contrato, identidad
 *      `(restocker_id, product_id, batch_code)`, lote normalizado `'SIN-LOTE'` por defecto,
 *      referencias `RESTRICT`/`CASCADE`, cotas de cantidad e índice FEFO con `NULLS LAST`.
 *   3. **Forma de la Tabla 45.** Diecinueve columnas, `order_code` único con formato `PLO-AAAA-NNNN`,
 *      coherencia de cancelación y liquidación, rango térmico de cámara e índice único parcial de
 *      orden activa por reponedor (D3).
 *   4. **Forma de la Tabla 46.** Diecisiete columnas, identidad `(order_id, product_id)`, baja
 *      lógica `is_active` y cuadre de liquidación como identidad aritmética (D3, QA 4, C1).
 *   5. **Cerrojo sanitario de stock (Art. II, D2).** Un producto refrigerado no viaja con
 *      `'SIN-LOTE'` ni sin fecha de caducidad: el motor responde `45000` con la marca del
 *      contrato, y admite el lote real con su fecha.
 *   6. **Cerrojo sanitario de despacho (Art. II, D2).** Una orden con líneas refrigeradas servidas
 *      no pasa a `DISPATCHED` sin temperatura de cámara en `[-5,0, 4,0] °C` ni con una línea sin
 *      lote real; con 3,5 °C y lote válido, el despacho pasa.
 *   7. **Art. III.1.** `DELETE` sobre las tres tablas lanza `45000` con la marca constitucional;
 *      dentro de la ventana de purga del arnés las filas se pueden retirar.
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
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresProductLoadingSchemaTest.php
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
const PRODUCT_LOADING_TEST_SCHEMA = 't_pld02_carga_frio';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

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
echo " VENDGUARD · T-PLD-02 · Esquema de órdenes de carga y picking de frío (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo lo que las reglas necesitan para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

$createUser = static function (PDO $pdo, string $role, string $suffix): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES (:name, :email, :email_key, :password_hash, :role)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => 'Usuario de certificación ' . $suffix,
        ':email' => strtolower($suffix) . '@carga.pld02.test',
        ':email_key' => strtolower($suffix) . '@carga.pld02.test',
        ':password_hash' => password_hash('Certification123!', PASSWORD_BCRYPT),
        ':role' => $role,
    ]);

    return (int)$stmt->fetchColumn();
};

$createProduct = static function (PDO $pdo, string $sku, bool $coldChain, ?int $shelfLifeDays = null): int {
    $stmt = $pdo->prepare(
        'INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES (:sku, :name, :category, \'UNIT\', :cold_chain, :shelf_life, 1.00, 2.50)
         RETURNING id'
    );
    $stmt->execute([
        ':sku' => $sku,
        ':name' => 'Producto de certificación ' . $sku,
        ':category' => $coldChain ? 'PERISHABLE_FOOD' : 'COLD_DRINK',
        ':cold_chain' => $coldChain ? 'TRUE' : 'FALSE',
        ':shelf_life' => $shelfLifeDays,
    ]);

    return (int)$stmt->fetchColumn();
};

$createStock = static function (
    PDO $pdo,
    int $restockerId,
    int $productId,
    ?string $batchCode,
    ?int $expirationOffsetDays,
    int $quantity = 10
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO restocker_van_product_stocks
             (restocker_id, product_id, batch_code, expiration_date, quantity_available)
         VALUES (
             :restocker_id, :product_id,
             COALESCE(:batch_code, \'SIN-LOTE\'),
             CASE WHEN CAST(:expiration_days AS INTEGER) IS NULL THEN NULL
                  ELSE CURRENT_DATE + CAST(:expiration_days AS INTEGER) END,
             :quantity
         )
         RETURNING id'
    );
    $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':batch_code', $batchCode, $batchCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':expiration_days', $expirationOffsetDays, $expirationOffsetDays === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
};

$createOrder = static function (
    PDO $pdo,
    string $orderCode,
    int $restockerId,
    int $createdBy,
    string $status = 'PENDING_PICKING',
    ?string $coldTemp = null,
    ?int $reconciledBy = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO product_loading_orders
             (order_code, restocker_id, status, demand_mode, safety_buffer_pct,
              cold_chamber_exit_temp_celsius, created_by, reconciled_by, reconciled_at)
         VALUES (
             :order_code, :restocker_id, :status, \'ALERTS_ONLY\', 10,
             :cold_temp, :created_by,
             :reconciled_by,
             CASE WHEN :reconciled_by_at IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END
         )
         RETURNING id'
    );
    $stmt->execute([
        ':order_code' => $orderCode,
        ':restocker_id' => $restockerId,
        ':status' => $status,
        ':cold_temp' => $coldTemp,
        ':created_by' => $createdBy,
        ':reconciled_by' => $reconciledBy,
        ':reconciled_by_at' => $reconciledBy,
    ]);

    return (int)$stmt->fetchColumn();
};

$createItem = static function (
    PDO $pdo,
    int $orderId,
    int $productId,
    int $requestedQty,
    ?int $dispatchedQty = null,
    ?string $batchCode = null,
    ?int $expirationOffsetDays = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO product_loading_order_items
             (order_id, product_id, reason, requested_qty, dispatched_qty, batch_code, expiration_date)
         VALUES (
             :order_id, :product_id, \'STOCKOUT_ALERT\', :requested_qty, :dispatched_qty,
             :batch_code,
             CASE WHEN CAST(:expiration_days AS INTEGER) IS NULL THEN NULL
                  ELSE CURRENT_DATE + CAST(:expiration_days AS INTEGER) END
         )
         RETURNING id'
    );
    $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':requested_qty', $requestedQty, PDO::PARAM_INT);
    $stmt->bindValue(':dispatched_qty', $dispatchedQty, $dispatchedQty === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':batch_code', $batchCode, $batchCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':expiration_days', $expirationOffsetDays, $expirationOffsetDays === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 029) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, PRODUCT_LOADING_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 029 del Módulo 33',
        in_array('029_product_loading_orders_and_cold_picking.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %d · pendientes: %s', $schemaReport['statements'], count($schemaReport['migrations']), json_encode($convergence['pending']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos y entidades auditables (QA 4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos del ciclo de carga y entidades auditables ---\n";

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
        'product_loading_order_status declara los cinco estados del ciclo en su orden',
        $enumLabels($pgPdo, 'product_loading_order_status') === [
            'PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER', 'RECONCILED_AT_CLOSE', 'CANCELLED',
        ],
        implode(',', $enumLabels($pgPdo, 'product_loading_order_status'))
    );

    $assert(
        'product_loading_demand_mode declara ALERTS_ONLY y ALL_GAPS',
        $enumLabels($pgPdo, 'product_loading_demand_mode') === ['ALERTS_ONLY', 'ALL_GAPS'],
        implode(',', $enumLabels($pgPdo, 'product_loading_demand_mode'))
    );

    $assert(
        'product_loading_item_reason declara los cuatro motivos de línea en su prelación',
        $enumLabels($pgPdo, 'product_loading_item_reason') === [
            'STOCKOUT_ALERT', 'LOW_STOCK_ALERT', 'ROUTE_GAP_FILL', 'MANUAL_ADDITION',
        ],
        implode(',', $enumLabels($pgPdo, 'product_loading_item_reason'))
    );

    $auditCast = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->query("SELECT 'RESTOCKER_VAN_STOCK'::audit_entity_type, 'PRODUCT_LOADING_ORDER'::audit_entity_type")->fetch();
    });

    $assert(
        'Las entidades auditables RESTOCKER_VAN_STOCK y PRODUCT_LOADING_ORDER se pueden castear en el motor',
        $auditCast === null,
        $auditCast === null ? 'admitidas' : 'rechazado con ' . $auditCast['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de la Tabla 44 restocker_van_product_stocks
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 44 restocker_van_product_stocks ---\n";

    $stockColumns = PostgresFixtureBuilder::columns($pgPdo, 'restocker_van_product_stocks');
    $assert(
        'La tabla declara sus ocho columnas canónicas en el orden del contrato',
        array_keys($stockColumns) === [
            'id', 'restocker_id', 'product_id', 'batch_code', 'expiration_date',
            'quantity_available', 'created_at', 'updated_at',
        ],
        implode(', ', array_keys($stockColumns))
    );

    $assert(
        'batch_code es VARCHAR(40) obligatorio con defecto SIN-LOTE y quantity_available es entero no negativo por defecto 0',
        ($stockColumns['batch_code']['udt_name'] ?? '') === 'varchar'
            && ($stockColumns['batch_code']['max_length'] ?? 0) === 40
            && $stockColumns['batch_code']['nullable'] === false
            && str_contains((string)($stockColumns['batch_code']['default'] ?? ''), 'SIN-LOTE')
            && ($stockColumns['quantity_available']['udt_name'] ?? '') === 'int4'
            && str_contains((string)($stockColumns['quantity_available']['default'] ?? ''), '0'),
        sprintf(
            'batch=%s(%d) default=%s · qty=%s default=%s',
            $stockColumns['batch_code']['udt_name'] ?? '?',
            $stockColumns['batch_code']['max_length'] ?? 0,
            $stockColumns['batch_code']['default'] ?? '?',
            $stockColumns['quantity_available']['udt_name'] ?? '?',
            $stockColumns['quantity_available']['default'] ?? '?'
        )
    );

    $stockUnique = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'restocker_van_product_stocks' AND con.contype = 'u'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'La identidad del saldo es la terna (restocker_id, product_id, batch_code)',
        in_array('uq_restocker_van_product_batch', $stockUnique, true),
        'únicas declaradas: ' . implode(', ', $stockUnique)
    );

    $stockForeignKeys = [];
    foreach ($pgPdo->query(
        "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'restocker_van_product_stocks' AND con.contype = 'f'"
    )->fetchAll() as $foreignKey) {
        $stockForeignKeys[(string)$foreignKey['name']] = (string)$foreignKey['definition'];
    }

    $assert(
        'Las referencias a users y vending_products son RESTRICT/CASCADE',
        isset($stockForeignKeys['fk_restocker_van_product_stocks_restocker'], $stockForeignKeys['fk_restocker_van_product_stocks_product'])
            && str_contains($stockForeignKeys['fk_restocker_van_product_stocks_restocker'], 'ON DELETE RESTRICT')
            && str_contains($stockForeignKeys['fk_restocker_van_product_stocks_restocker'], 'ON UPDATE CASCADE')
            && str_contains($stockForeignKeys['fk_restocker_van_product_stocks_product'], 'ON DELETE RESTRICT')
            && str_contains($stockForeignKeys['fk_restocker_van_product_stocks_product'], 'ON UPDATE CASCADE'),
        implode(' · ', array_keys($stockForeignKeys))
    );

    $stockChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'restocker_van_product_stocks' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las dos CHECK del contrato acotan el formato del lote y la cantidad',
        in_array('chk_restocker_van_product_stocks_batch_format', $stockChecks, true)
            && in_array('chk_restocker_van_product_stocks_qty_range', $stockChecks, true),
        'declaradas: ' . implode(', ', $stockChecks)
    );

    // `pg_indexes.indexdef` omite las cláusulas que son el defecto del motor, así que la
    // dirección y el tratamiento de nulos se leen de `pg_index.indoption`: los dos bits
    // bajos de la segunda clave valen 0 cuando es ASC NULLS LAST (INDOPTION_DESC = 1,
    // INDOPTION_NULLS_FIRST = 2). Comprobar el texto de la definición no serviría.
    $fefoIndex = $pgPdo->query(
        "SELECT pg_get_indexdef(i.indexrelid) AS definition, i.indoption[2] AS second_key_option
           FROM pg_index i
           JOIN pg_class c ON c.oid = i.indexrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'idx_restocker_van_product_stocks_restocker_fefo'"
    )->fetch();

    $fefoDefinition = $fefoIndex === false ? '' : (string)$fefoIndex['definition'];
    $fefoSecondKey = $fefoIndex === false ? -1 : (int)$fefoIndex['second_key_option'];

    $assert(
        'El índice FEFO ordena por caducidad ascendente con NULLS LAST y desempata por producto',
        str_contains($fefoDefinition, 'restocker_id')
            && str_contains($fefoDefinition, 'expiration_date')
            && str_contains($fefoDefinition, 'product_id')
            && $fefoSecondKey === 0,
        $fefoDefinition . ' · indoption[2]=' . $fefoSecondKey
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Forma de la Tabla 45 product_loading_orders
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 45 product_loading_orders ---\n";

    $orderColumns = PostgresFixtureBuilder::columns($pgPdo, 'product_loading_orders');
    $assert(
        'La tabla declara sus diecinueve columnas canónicas en el orden del contrato',
        array_keys($orderColumns) === [
            'id', 'order_code', 'restocker_id', 'status', 'demand_mode', 'safety_buffer_pct',
            'cold_chamber_exit_temp_celsius', 'notes', 'cancel_reason', 'reconciliation_notes',
            'created_by', 'dispatched_by', 'reconciled_by', 'created_at', 'dispatched_at',
            'confirmed_at', 'reconciled_at', 'cancelled_at', 'updated_at',
        ],
        implode(', ', array_keys($orderColumns))
    );

    $orderChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'product_loading_orders' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las cinco CHECK del contrato están declaradas en la Tabla 45',
        array_values(array_intersect([
            'chk_product_loading_orders_code_format',
            'chk_product_loading_orders_buffer_range',
            'chk_product_loading_orders_cold_temp_range',
            'chk_product_loading_orders_cancel_coherence',
            'chk_product_loading_orders_reconcile_coherence',
        ], $orderChecks)) === [
            'chk_product_loading_orders_code_format',
            'chk_product_loading_orders_buffer_range',
            'chk_product_loading_orders_cold_temp_range',
            'chk_product_loading_orders_cancel_coherence',
            'chk_product_loading_orders_reconcile_coherence',
        ],
        'declaradas: ' . implode(', ', $orderChecks)
    );

    $activeOrderIndex = (string)$pgPdo->query(
        "SELECT indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND indexname = 'uq_product_loading_orders_active_restocker'"
    )->fetchColumn();

    $assert(
        'El índice único parcial impide dos órdenes activas del mismo reponedor (D3)',
        str_contains($activeOrderIndex, 'UNIQUE')
            && str_contains($activeOrderIndex, 'restocker_id')
            && str_contains($activeOrderIndex, 'PENDING_PICKING')
            && str_contains($activeOrderIndex, 'DISPATCHED')
            && str_contains($activeOrderIndex, 'CONFIRMED_BY_RESTOCKER'),
        $activeOrderIndex
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Forma de la Tabla 46 product_loading_order_items
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 46 product_loading_order_items ---\n";

    $itemColumns = PostgresFixtureBuilder::columns($pgPdo, 'product_loading_order_items');
    $assert(
        'La tabla declara sus diecisiete columnas canónicas en el orden del contrato',
        array_keys($itemColumns) === [
            'id', 'order_id', 'product_id', 'reason', 'requested_qty', 'dispatched_qty',
            'batch_code', 'expiration_date', 'van_stock_before_reconcile',
            'restocked_in_machines_qty', 'withdrawn_spoilage_qty', 'expected_return_qty',
            'counted_return_qty', 'discrepancy_qty', 'is_active', 'created_at', 'updated_at',
        ],
        implode(', ', array_keys($itemColumns))
    );

    $itemChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'product_loading_order_items' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las cuatro CHECK del contrato están declaradas en la Tabla 46',
        array_values(array_intersect([
            'chk_product_loading_order_items_requested_qty',
            'chk_product_loading_order_items_dispatched_qty',
            'chk_product_loading_order_items_batch_format',
            'chk_product_loading_order_items_reconcile_bounds',
        ], $itemChecks)) === [
            'chk_product_loading_order_items_requested_qty',
            'chk_product_loading_order_items_dispatched_qty',
            'chk_product_loading_order_items_batch_format',
            'chk_product_loading_order_items_reconcile_bounds',
        ],
        'declaradas: ' . implode(', ', $itemChecks)
    );

    $itemUnique = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'product_loading_order_items' AND con.contype = 'u'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'La línea es única por (order_id, product_id): editar es UPSERT, retirar es is_active = FALSE (C1)',
        in_array('uq_product_loading_order_item_product', $itemUnique, true),
        'únicas declaradas: ' . implode(', ', $itemUnique)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Cerrojo sanitario de stock y lote (Art. II, D2, QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo sanitario del stock por lote (Art. II) ---\n";

    $restockerA = $createUser($pgPdo, 'RESTOCKER', 'restock-a');
    $restockerB = $createUser($pgPdo, 'RESTOCKER', 'restock-b');
    $coordinator = $createUser($pgPdo, 'COORDINATOR', 'coord-a');
    $sandwich = $createProduct($pgPdo, 'CERT-SAND-01', true, 5);
    $cola = $createProduct($pgPdo, 'CERT-COLA-01', false);

    $coldWithoutBatch = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerA, $sandwich): void {
        $pgPdo->exec(
            "INSERT INTO restocker_van_product_stocks (restocker_id, product_id, batch_code, expiration_date, quantity_available)
             VALUES ({$restockerA}, {$sandwich}, 'SIN-LOTE', CURRENT_DATE + 2, 5)"
        );
    });

    $assert(
        'El producto refrigerado no viaja con el lote normalizado SIN-LOTE',
        $coldWithoutBatch !== null
            && $coldWithoutBatch['state'] === '45000'
            && str_contains($coldWithoutBatch['message'], 'ART_II_COLD_CHAIN_BATCH_REQUIRED'),
        $coldWithoutBatch === null ? 'la fila se admitió' : 'estado=' . $coldWithoutBatch['state']
    );

    $coldWithoutExpiry = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerA, $sandwich): void {
        $pgPdo->exec(
            "INSERT INTO restocker_van_product_stocks (restocker_id, product_id, batch_code, expiration_date, quantity_available)
             VALUES ({$restockerA}, {$sandwich}, 'L-CERT-01', NULL, 5)"
        );
    });

    $assert(
        'El producto refrigerado exige fecha de caducidad: sin ella no hay FEFO ni trazabilidad',
        $coldWithoutExpiry !== null
            && $coldWithoutExpiry['state'] === '45000'
            && str_contains($coldWithoutExpiry['message'], 'ART_II_COLD_CHAIN_BATCH_REQUIRED'),
        $coldWithoutExpiry === null ? 'la fila se admitió' : 'estado=' . $coldWithoutExpiry['state']
    );

    $validColdStock = null;
    $validColdStockError = null;
    try {
        $validColdStock = $createStock($pgPdo, $restockerA, $sandwich, 'L-CERT-02', 2, 12);
    } catch (Throwable $exception) {
        $validColdStockError = $exception->getMessage();
    }

    $assert(
        'El lote real con su fecha de caducidad sí entra en la furgoneta',
        $validColdStock !== null,
        $validColdStockError ?? 'id=' . (string)$validColdStock
    );

    $nonColdNoBatch = null;
    $nonColdError = null;
    try {
        $nonColdNoBatch = $createStock($pgPdo, $restockerA, $cola, null, null, 60);
    } catch (Throwable $exception) {
        $nonColdError = $exception->getMessage();
    }

    $assert(
        'El producto sin cadena de frío sigue viajando con SIN-LOTE y sin caducidad',
        $nonColdNoBatch !== null,
        $nonColdError ?? 'id=' . (string)$nonColdNoBatch
    );

    $duplicateBatch = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerA, $sandwich): void {
        $pgPdo->exec(
            "INSERT INTO restocker_van_product_stocks (restocker_id, product_id, batch_code, expiration_date, quantity_available)
             VALUES ({$restockerA}, {$sandwich}, 'L-CERT-02', CURRENT_DATE + 3, 4)"
        );
    });

    $assert(
        'El mismo lote no se duplica en la furgoneta del reponedor (23505)',
        $duplicateBatch !== null && $duplicateBatch['state'] === '23505',
        $duplicateBatch === null ? 'la fila se admitió' : 'estado=' . $duplicateBatch['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Coherencia de estados y de la línea (D3, QA 4, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Coherencia de estados, unicidad activa y cuadre de la línea ---\n";

    $orderPending = $createOrder($pgPdo, 'PLO-2026-7001', $restockerA, $coordinator);
    $coldLine = $createItem($pgPdo, $orderPending, $sandwich, 24, 24, 'L-CERT-02', 2);
    $colaLine = $createItem($pgPdo, $orderPending, $cola, 60, 60, 'SIN-LOTE', null);

    $duplicateActive = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerA, $coordinator): void {
        $pgPdo->exec(
            "INSERT INTO product_loading_orders (order_code, restocker_id, created_by)
             VALUES ('PLO-2026-7002', {$restockerA}, {$coordinator})"
        );
    });

    $assert(
        'Un reponedor no acumula dos órdenes activas simultáneas (409 del contrato, 23505 en el motor)',
        $duplicateActive !== null && $duplicateActive['state'] === '23505',
        $duplicateActive === null ? 'la segunda orden se admitió' : 'estado=' . $duplicateActive['state']
    );

    $cancelWithoutReason = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerB, $coordinator): void {
        $pgPdo->exec(
            "INSERT INTO product_loading_orders (order_code, restocker_id, status, created_by, cancelled_at)
             VALUES ('PLO-2026-7003', {$restockerB}, 'CANCELLED', {$coordinator}, CURRENT_TIMESTAMP)"
        );
    });

    $assert(
        'Una orden cancelada sin motivo justificado se rechaza (Art. V.1, 23514)',
        $cancelWithoutReason !== null && $cancelWithoutReason['state'] === '23514',
        $cancelWithoutReason === null ? 'la fila se admitió' : 'estado=' . $cancelWithoutReason['state']
    );

    $reconciledWithoutActor = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerB, $coordinator): void {
        $pgPdo->exec(
            "INSERT INTO product_loading_orders (order_code, restocker_id, status, created_by, reconciled_at)
             VALUES ('PLO-2026-7004', {$restockerB}, 'RECONCILED_AT_CLOSE', {$coordinator}, CURRENT_TIMESTAMP)"
        );
    });

    $assert(
        'Una orden liquidada sin responsable y fecha se rechaza (chk_product_loading_orders_reconcile_coherence)',
        $reconciledWithoutActor !== null && $reconciledWithoutActor['state'] === '23514',
        $reconciledWithoutActor === null ? 'la fila se admitió' : 'estado=' . $reconciledWithoutActor['state']
    );

    $duplicateLine = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderPending, $sandwich): void {
        $pgPdo->exec(
            "INSERT INTO product_loading_order_items (order_id, product_id, requested_qty)
             VALUES ({$orderPending}, {$sandwich}, 5)"
        );
    });

    $assert(
        'La línea es única por orden y producto (23505): editar es UPSERT',
        $duplicateLine !== null && $duplicateLine['state'] === '23505',
        $duplicateLine === null ? 'la línea duplicada se admitió' : 'estado=' . $duplicateLine['state']
    );

    $zeroRequested = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderPending, $cola): void {
        $pgPdo->exec(
            "UPDATE product_loading_order_items SET requested_qty = 0
              WHERE order_id = {$orderPending} AND product_id = {$cola}"
        );
    });

    $assert(
        'Lo solicitado se acota a 1..99999 (chk_product_loading_order_items_requested_qty)',
        $zeroRequested !== null && $zeroRequested['state'] === '23514',
        $zeroRequested === null ? 'el cero se admitió' : 'estado=' . $zeroRequested['state']
    );

    $badReconcile = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderPending, $sandwich): void {
        $pgPdo->exec(
            "UPDATE product_loading_order_items
                SET van_stock_before_reconcile = 24, restocked_in_machines_qty = 20,
                    withdrawn_spoilage_qty = 2, expected_return_qty = 5,
                    counted_return_qty = 2, discrepancy_qty = 0
              WHERE order_id = {$orderPending} AND product_id = {$sandwich}"
        );
    });

    $assert(
        'El cuadre no admite una identidad aritmética falsa (expected_return_qty debe ser 2)',
        $badReconcile !== null && $badReconcile['state'] === '23514',
        $badReconcile === null ? 'el cuadre imposible se admitió' : 'estado=' . $badReconcile['state']
    );

    $reconciledLine = null;
    $reconciledLineError = null;
    try {
        $pgPdo->exec(
            "UPDATE product_loading_order_items
                SET van_stock_before_reconcile = 24, restocked_in_machines_qty = 20,
                    withdrawn_spoilage_qty = 2, expected_return_qty = 2,
                    counted_return_qty = 1, discrepancy_qty = -1
              WHERE order_id = {$orderPending} AND product_id = {$sandwich}"
        );
        $reconciledLine = (int)$pgPdo->query(
            "SELECT discrepancy_qty FROM product_loading_order_items
              WHERE order_id = {$orderPending} AND product_id = {$sandwich}"
        )->fetchColumn();
    } catch (Throwable $exception) {
        $reconciledLineError = $exception->getMessage();
    }

    $assert(
        'El cuadre coherente se admite y conserva el descuadre declarado (-1)',
        $reconciledLine === -1,
        $reconciledLineError ?? 'discrepancy_qty=' . var_export($reconciledLine, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Cerrojo sanitario del despacho (Art. II, D2, QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo sanitario del despacho (Art. II) ---\n";

    $dispatch = static function (int $orderId, ?string $temperature, string $status = 'DISPATCHED', bool $withActor = true) use ($pgPdo): void {
        $setTemperature = $temperature === null ? '' : ", cold_chamber_exit_temp_celsius = {$temperature}";
        $setActor = $withActor ? ', dispatched_at = CURRENT_TIMESTAMP, dispatched_by = created_by' : '';
        $pgPdo->exec("UPDATE product_loading_orders SET status = '{$status}'{$setTemperature}{$setActor} WHERE id = {$orderId}");
    };

    $noTemperature = PostgresFixtureBuilder::expectRejection(static function () use ($dispatch, $orderPending): void {
        $dispatch($orderPending, null);
    });

    $assert(
        'El despacho con producto refrigerado exige temperatura de salida de cámara',
        $noTemperature !== null
            && $noTemperature['state'] === '45000'
            && str_contains($noTemperature['message'], 'ART_II_COLD_CHAMBER_DISPATCH_VIOLATION'),
        $noTemperature === null ? 'el despacho se admitió' : 'estado=' . $noTemperature['state']
    );

    $coldTempTooHigh = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderPending): void {
        $pgPdo->exec('UPDATE product_loading_orders SET cold_chamber_exit_temp_celsius = 5.0 WHERE id = ' . $orderPending);
    });

    $assert(
        'La temperatura de cámara se acota a -5,0..4,0 °C también fuera del despacho (23514)',
        $coldTempTooHigh !== null && $coldTempTooHigh['state'] === '23514',
        $coldTempTooHigh === null ? '5,0 °C se admitió' : 'estado=' . $coldTempTooHigh['state']
    );

    $coldBatchLine = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderPending, $sandwich): void {
        $pgPdo->exec(
            "UPDATE product_loading_order_items SET batch_code = 'SIN-LOTE'
              WHERE order_id = {$orderPending} AND product_id = {$sandwich}"
        );
        $pgPdo->exec('UPDATE product_loading_orders SET status = \'DISPATCHED\', cold_chamber_exit_temp_celsius = 3.5 WHERE id = ' . $orderPending);
    });

    $assert(
        'Una línea refrigerada servida sin lote real bloquea el despacho (ART_II_COLD_CHAIN_BATCH_REQUIRED)',
        $coldBatchLine !== null
            && $coldBatchLine['state'] === '45000'
            && str_contains($coldBatchLine['message'], 'ART_II_COLD_CHAIN_BATCH_REQUIRED'),
        $coldBatchLine === null ? 'el despacho se admitió' : 'estado=' . $coldBatchLine['state']
    );

    // La línea vuelve a su lote real y la orden se despacha con 3,5 °C: el caso válido pasa.
    $pgPdo->exec(
        "UPDATE product_loading_order_items SET batch_code = 'L-CERT-02'
          WHERE order_id = {$orderPending} AND product_id = {$sandwich}"
    );

    $validDispatch = null;
    $validDispatchError = null;
    try {
        $dispatch($orderPending, '3.5');
        $validDispatch = (string)$pgPdo->query('SELECT status FROM product_loading_orders WHERE id = ' . $orderPending)->fetchColumn();
    } catch (Throwable $exception) {
        $validDispatchError = $exception->getMessage();
    }

    $assert(
        'El despacho con 3,5 °C y lote real en la línea refrigerada pasa el cerrojo',
        $validDispatch === 'DISPATCHED',
        $validDispatchError ?? 'estado=' . var_export($validDispatch, true)
    );

    $nonColdOrder = $createOrder($pgPdo, 'PLO-2026-7005', $restockerB, $coordinator);
    $createItem($pgPdo, $nonColdOrder, $cola, 24, 24, 'SIN-LOTE', null);

    $nonColdDispatch = null;
    $nonColdDispatchError = null;
    try {
        $dispatch($nonColdOrder, null);
        $nonColdDispatch = (string)$pgPdo->query('SELECT status FROM product_loading_orders WHERE id = ' . $nonColdOrder)->fetchColumn();
    } catch (Throwable $exception) {
        $nonColdDispatchError = $exception->getMessage();
    }

    $assert(
        'Una orden sin producto refrigerado se despacha sin temperatura de cámara',
        $nonColdDispatch === 'DISPATCHED',
        $nonColdDispatchError ?? 'estado=' . var_export($nonColdDispatch, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Art. III.1: borrado físico prohibido, ventana de purga del arnés aparte
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1 sobre las Tablas 44, 45 y 46 ---\n";

    foreach ([
        'restocker_van_product_stocks' => $validColdStock,
        'product_loading_orders' => $orderPending,
        'product_loading_order_items' => $coldLine,
    ] as $table => $rowId) {
        $rejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $table, $rowId): void {
            $pgPdo->exec('DELETE FROM ' . $table . ' WHERE id = ' . (int)$rowId);
        });

        $assert(
            "Art. III.1 · «{$table}» rechaza el DELETE físico con SQLSTATE 45000",
            $rejection !== null
                && $rejection['state'] === '45000'
                && str_contains($rejection['message'], PURGE_VIOLATION_MARKER),
            $rejection === null ? 'el DELETE se admitió' : 'estado=' . $rejection['state']
        );
    }

    TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $orderPending, $validColdStock): void {
        $pgPdo->exec('DELETE FROM product_loading_order_items WHERE order_id = ' . $orderPending);
        $pgPdo->exec('DELETE FROM product_loading_orders WHERE id = ' . $orderPending);
        $pgPdo->exec('DELETE FROM restocker_van_product_stocks WHERE id = ' . $validColdStock);
    });

    $assert(
        'Dentro de la ventana de purga del arnés las tres tablas se pueden drenar (la vía existe y funciona)',
        (int)$pgPdo->query('SELECT COUNT(*) FROM product_loading_orders WHERE id = ' . $orderPending)->fetchColumn() === 0
            && (int)$pgPdo->query('SELECT COUNT(*) FROM restocker_van_product_stocks WHERE id = ' . $validColdStock)->fetchColumn() === 0
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, PRODUCT_LOADING_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . PRODUCT_LOADING_TEST_SCHEMA . ".product_loading_orders')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus Tablas 44/45/46 ya no existen',
    $residueProbe === null || $residueProbe === false,
    'la sonda de residuo encontró ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DE ORDENES DE CARGA Y FRIO CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL ESQUEMA DEL MÓDULO 33.\n";
echo "======================================================================\n";
exit(1);
