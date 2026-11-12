<?php

declare(strict_types=1);

/**
 * PostgresProductPlanogramSchemaTest · T-PLN-02
 *
 * Certifica en el motor real el esquema del Módulo 32 (`32-product-catalog-planogram`): los tipos
 * nativos del catálogo comercial, la Tabla 42 `vending_products`, la Tabla 43
 * `machine_planogram_slots` y el cerrojo sanitario bidireccional `MachineType × ProductCategory`.
 * No comprueba servicios ni API —eso llega con las tareas `T-PLN-05` a `T-PLN-11`— sino las
 * garantías que la base de datos impone por sí sola, porque son las que ningún camino de código
 * puede saltarse:
 *
 *   1. **Tipos nativos (QA 1).** `vending_product_category` declara las cinco categorías y
 *      `vending_product_unit_type` las tres unidades; `VENDING_PRODUCT` y `MACHINE_PLANOGRAM`
 *      se pueden castear en `audit_entity_type`.
 *   2. **Forma de la Tabla 42.** Doce columnas en el orden del contrato, `sku` único y acotado,
 *      cotas de coste y precio, coherencia categoría/unidad, cadena de frío obligatoria en
 *      perecederos y vida útil obligatoria cuando hay frío (C2, QA 1).
 *   3. **Forma de la Tabla 43.** Once columnas, referencias `RESTRICT`/`CASCADE` a máquinas y
 *      productos, identidad única `(machine_id, slot_code)` y cotas de capacidad, umbral y
 *      existencia estimada (C2).
 *   4. **Cerrojo directo (Art. II, D3).** Un producto refrigerado no se asigna a una máquina que
 *      no es `PERISHABLE_FOOD` ni `COMBO`, y una categoría fuera de la matriz se rechaza con
 *      `SQLSTATE '45000'` nombrando el código del contrato.
 *   5. **Cerrojo inverso del producto (RF-03.2).** Cambiar categoría o cadena de frío con carriles
 *      activos incompatibles se rechaza; el SKU es inmutable tras el alta (C1).
 *   6. **Cerrojo inverso de la máquina (RF-03.2, QA 4).** Cambiar la tipología con carriles
 *      activos incompatibles se rechaza con el detalle del primer carril.
 *   7. **Baja lógica (Art. III.1).** `DELETE` sobre ambas tablas lanza `45000` con la marca
 *      constitucional; dentro de la ventana de purga del arnés la fila se puede retirar.
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
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresProductPlanogramSchemaTest.php
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
const PLANOGRAM_TEST_SCHEMA = 't_pln02_catalogo_planograma';

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
echo " VENDGUARD · T-PLN-02 · Esquema del catálogo y el planograma (PostgreSQL)\n";
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
        ':site_code' => 'SEDE-PLN02-' . strtoupper($suffix),
        ':site_code_key' => 'sede-pln02-' . strtolower($suffix),
        ':name' => 'Sede de certificación del planograma ' . $suffix,
        ':address' => 'Carrer del Pla 32, Barcelona',
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

$createProduct = static function (
    PDO $pdo,
    string $sku,
    string $name,
    string $category,
    string $unitType,
    string $cost,
    string $salePrice,
    bool $coldChain = false,
    ?int $shelfLifeDays = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES (:sku, :name, :category, :unit_type, :cold_chain, :shelf_life, :unit_cost, :sale_price)
         RETURNING id'
    );
    $stmt->execute([
        ':sku' => $sku,
        ':name' => $name,
        ':category' => $category,
        ':unit_type' => $unitType,
        ':cold_chain' => $coldChain ? 'TRUE' : 'FALSE',
        ':shelf_life' => $shelfLifeDays,
        ':unit_cost' => $cost,
        ':sale_price' => $salePrice,
    ]);

    return (int)$stmt->fetchColumn();
};

$createSlot = static function (
    PDO $pdo,
    int $machineId,
    string $slotCode,
    int $productId,
    int $maxCapacity = 12,
    int $estimatedQty = 4,
    bool $active = true
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machine_planogram_slots
             (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active)
         VALUES (:machine_id, :slot_code, :product_id, :max_capacity, :estimated_qty, 2, :is_active)
         RETURNING id'
    );
    $stmt->execute([
        ':machine_id' => $machineId,
        ':slot_code' => $slotCode,
        ':product_id' => $productId,
        ':max_capacity' => $maxCapacity,
        ':estimated_qty' => $estimatedQty,
        ':is_active' => $active ? 'TRUE' : 'FALSE',
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 028) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, PLANOGRAM_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 028 del Módulo 32',
        in_array('028_product_catalog_and_planogram_slots.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %d · pendientes: %s', $schemaReport['statements'], count($schemaReport['migrations']), json_encode($convergence['pending']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos y entidades auditables (QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos del catálogo y entidades auditables ---\n";

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
        'vending_product_category declara las cinco categorías del contrato en su orden',
        $enumLabels($pgPdo, 'vending_product_category') === [
            'COLD_DRINK', 'SNACK', 'PERISHABLE_FOOD', 'HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE',
        ],
        implode(',', $enumLabels($pgPdo, 'vending_product_category'))
    );

    $assert(
        'vending_product_unit_type declara UNIT, GRAMS y SLEEVE',
        $enumLabels($pgPdo, 'vending_product_unit_type') === ['UNIT', 'GRAMS', 'SLEEVE'],
        implode(',', $enumLabels($pgPdo, 'vending_product_unit_type'))
    );

    $auditCast = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->query("SELECT 'VENDING_PRODUCT'::audit_entity_type, 'MACHINE_PLANOGRAM'::audit_entity_type")->fetch();
    });

    $assert(
        'Las entidades auditables VENDING_PRODUCT y MACHINE_PLANOGRAM se pueden castear en el motor',
        $auditCast === null,
        $auditCast === null ? 'admitidas' : 'rechazado con ' . $auditCast['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de la Tabla 42 vending_products (C2, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 42 vending_products ---\n";

    $productColumns = PostgresFixtureBuilder::columns($pgPdo, 'vending_products');
    $assert(
        'La tabla declara sus doce columnas canónicas en el orden del contrato',
        array_keys($productColumns) === [
            'id', 'sku', 'name', 'category', 'unit_type', 'requires_cold_chain',
            'max_shelf_life_days', 'unit_cost_eur', 'sale_price_eur', 'is_active', 'created_at', 'updated_at',
        ],
        implode(', ', array_keys($productColumns))
    );

    $assert(
        'sku es VARCHAR(32) obligatorio y sale_price_eur es NUMERIC(10,2) con defecto 0,00 €',
        ($productColumns['sku']['udt_name'] ?? '') === 'varchar'
            && ($productColumns['sku']['max_length'] ?? 0) === 32
            && $productColumns['sku']['nullable'] === false
            && ($productColumns['sale_price_eur']['udt_name'] ?? '') === 'numeric'
            && str_contains((string)($productColumns['sale_price_eur']['default'] ?? ''), '0.00'),
        sprintf('sku=%s(%d) · default precio=%s', $productColumns['sku']['udt_name'] ?? '?', $productColumns['sku']['max_length'] ?? 0, $productColumns['sale_price_eur']['default'] ?? '?')
    );

    $productChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'vending_products' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las ocho restricciones CHECK del contrato están declaradas en la Tabla 42',
        array_values(array_intersect([
            'chk_vending_products_sku_format',
            'chk_vending_products_name_not_blank',
            'chk_vending_products_unit_cost',
            'chk_vending_products_sale_price',
            'chk_vending_products_unit_type_coherence',
            'chk_vending_products_perishable_cold_chain',
            'chk_vending_products_cold_chain_allowed_categories',
            'chk_vending_products_shelf_life',
        ], $productChecks)) === [
            'chk_vending_products_sku_format',
            'chk_vending_products_name_not_blank',
            'chk_vending_products_unit_cost',
            'chk_vending_products_sale_price',
            'chk_vending_products_unit_type_coherence',
            'chk_vending_products_perishable_cold_chain',
            'chk_vending_products_cold_chain_allowed_categories',
            'chk_vending_products_shelf_life',
        ],
        'declaradas: ' . implode(', ', $productChecks)
    );

    $skuDefinition = (string)$pgPdo->query(
        "SELECT indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND indexname = 'uq_vending_products_sku'"
    )->fetchColumn();

    $assert(
        'El SKU es único en el motor (uq_vending_products_sku)',
        str_contains($skuDefinition, 'UNIQUE') && str_contains($skuDefinition, 'sku'),
        $skuDefinition
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Forma de la Tabla 43 machine_planogram_slots (C2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 43 machine_planogram_slots ---\n";

    $slotColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_planogram_slots');
    $assert(
        'La tabla declara sus once columnas canónicas en el orden del contrato',
        array_keys($slotColumns) === [
            'id', 'machine_id', 'slot_code', 'product_id', 'max_capacity',
            'current_estimated_qty', 'min_threshold', 'is_active', 'created_at', 'updated_at',
        ],
        implode(', ', array_keys($slotColumns))
    );

    $slotForeignKeys = [];
    foreach ($pgPdo->query(
        "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_planogram_slots' AND con.contype = 'f'"
    )->fetchAll() as $foreignKey) {
        $slotForeignKeys[(string)$foreignKey['name']] = (string)$foreignKey['definition'];
    }

    $assert(
        'Las dos claves foráneas referencian machines y vending_products con RESTRICT y CASCADE',
        isset($slotForeignKeys['fk_machine_planogram_slots_machine'], $slotForeignKeys['fk_machine_planogram_slots_product'])
            && str_contains($slotForeignKeys['fk_machine_planogram_slots_machine'], 'machines(id)')
            && str_contains($slotForeignKeys['fk_machine_planogram_slots_machine'], 'ON DELETE RESTRICT')
            && str_contains($slotForeignKeys['fk_machine_planogram_slots_machine'], 'ON UPDATE CASCADE')
            && str_contains($slotForeignKeys['fk_machine_planogram_slots_product'], 'vending_products(id)')
            && str_contains($slotForeignKeys['fk_machine_planogram_slots_product'], 'ON DELETE RESTRICT'),
        implode(' | ', array_keys($slotForeignKeys))
    );

    $slotChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_planogram_slots' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las cuatro restricciones CHECK del contrato están declaradas en la Tabla 43',
        array_values(array_intersect([
            'chk_machine_planogram_slot_code_format',
            'chk_machine_planogram_max_capacity',
            'chk_machine_planogram_min_threshold',
            'chk_machine_planogram_estimated_qty',
        ], $slotChecks)) === [
            'chk_machine_planogram_slot_code_format',
            'chk_machine_planogram_max_capacity',
            'chk_machine_planogram_min_threshold',
            'chk_machine_planogram_estimated_qty',
        ],
        'declaradas: ' . implode(', ', $slotChecks)
    );

    $slotUniqueDefinition = (string)$pgPdo->query(
        "SELECT indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND indexname = 'uq_machine_planogram_slot'"
    )->fetchColumn();

    $assert(
        'El par (machine_id, slot_code) es la identidad única del carril',
        str_contains($slotUniqueDefinition, 'UNIQUE')
            && str_contains($slotUniqueDefinition, 'machine_id')
            && str_contains($slotUniqueDefinition, 'slot_code'),
        $slotUniqueDefinition
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Guardas de la Tabla 42 (C2, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guardas del catálogo comercial ---\n";

    $locationId = $createLocation($pgPdo, 'uno');
    $productCount = 0;

    $lowercaseSku = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, unit_cost_eur, sale_price_eur)
             VALUES ('refresco-01', 'Refresco con SKU en minúsculas', 'COLD_DRINK', 'UNIT', 1.00, 2.00)"
        );
    });

    $assert(
        'Un SKU en minúsculas se rechaza con 23514 por chk_vending_products_sku_format',
        $lowercaseSku !== null && $lowercaseSku['state'] === '23514' && str_contains($lowercaseSku['message'], 'chk_vending_products_sku_format'),
        $lowercaseSku === null ? 'el SKU inválido se admitió' : 'estado=' . $lowercaseSku['state']
    );

    $zeroPriceDrink = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, unit_cost_eur, sale_price_eur)
             VALUES ('BEB-CERO-01', 'Refresco a precio cero', 'COLD_DRINK', 'UNIT', 1.00, 0.00)"
        );
    });

    $assert(
        'Un refresco de venta directa a 0,00 € se rechaza con 23514 (chk_vending_products_sale_price)',
        $zeroPriceDrink !== null && $zeroPriceDrink['state'] === '23514' && str_contains($zeroPriceDrink['message'], 'chk_vending_products_sale_price'),
        $zeroPriceDrink === null ? 'el precio cero se admitió' : 'estado=' . $zeroPriceDrink['state']
    );

    $consumable = $createProduct($pgPdo, 'CONS-CERO-01', 'Vaso consumible a coste', 'CUP_STIRRER_CONSUMABLE', 'SLEEVE', '0.80', '0.00');
    $productCount++;

    $assert(
        'El mismo precio cero sí se admite en un consumible (C2)',
        $consumable > 0
    );

    $incoherentUnit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, unit_cost_eur, sale_price_eur)
             VALUES ('BEB-GRAM-01', 'Refresco vendido por gramos', 'COLD_DRINK', 'GRAMS', 1.00, 2.00)"
        );
    });

    $assert(
        'COLD_DRINK con unidad GRAMS se rechaza por chk_vending_products_unit_type_coherence',
        $incoherentUnit !== null && $incoherentUnit['state'] === '23514' && str_contains($incoherentUnit['message'], 'chk_vending_products_unit_type_coherence'),
        $incoherentUnit === null ? 'la unidad incoherente se admitió' : 'estado=' . $incoherentUnit['state']
    );

    $perishableWithoutCold = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, unit_cost_eur, sale_price_eur)
             VALUES ('PER-SIN-FRIO-01', 'Sándwich sin cadena de frío', 'PERISHABLE_FOOD', 'UNIT', 1.50, 3.50)"
        );
    });

    $assert(
        'Un PERISHABLE_FOOD sin cadena de frío se rechaza (chk_vending_products_perishable_cold_chain)',
        $perishableWithoutCold !== null && $perishableWithoutCold['state'] === '23514' && str_contains($perishableWithoutCold['message'], 'chk_vending_products_perishable_cold_chain'),
        $perishableWithoutCold === null ? 'el perecedero sin frío se admitió' : 'estado=' . $perishableWithoutCold['state']
    );

    $cafeWithCold = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
             VALUES ('CAFE-FRIO-01', 'Café con cadena de frío', 'HOT_BEVERAGE_INGREDIENT', 'GRAMS', TRUE, 30, 5.00, 8.00)"
        );
    });

    $assert(
        'La cadena de frío solo se admite en categorías refrigeradas (chk_vending_products_cold_chain_allowed_categories)',
        $cafeWithCold !== null && $cafeWithCold['state'] === '23514' && str_contains($cafeWithCold['message'], 'chk_vending_products_cold_chain_allowed_categories'),
        $cafeWithCold === null ? 'el café refrigerado se admitió' : 'estado=' . $cafeWithCold['state']
    );

    $coldWithoutShelfLife = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "INSERT INTO vending_products (sku, name, category, unit_type, requires_cold_chain, unit_cost_eur, sale_price_eur)
             VALUES ('LACT-SIN-VIDA-01', 'Yogur sin vida útil', 'PERISHABLE_FOOD', 'UNIT', TRUE, 1.20, 2.50)"
        );
    });

    $assert(
        'La cadena de frío exige vida útil declarada (chk_vending_products_shelf_life)',
        $coldWithoutShelfLife !== null && $coldWithoutShelfLife['state'] === '23514' && str_contains($coldWithoutShelfLife['message'], 'chk_vending_products_shelf_life'),
        $coldWithoutShelfLife === null ? 'el perecedero sin vida útil se admitió' : 'estado=' . $coldWithoutShelfLife['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Guardas de la Tabla 43 (C2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guardas de la matriz de carriles ---\n";

    $coldProduct = $createProduct($pgPdo, 'LACT-ENTERO-01', 'Yogur entero certificado', 'PERISHABLE_FOOD', 'UNIT', '1.20', '2.50', true, 21);
    $snackProduct = $createProduct($pgPdo, 'SNACK-BAR-01', 'Barrita de certificación', 'SNACK', 'UNIT', '0.60', '1.50');
    $coldDrink = $createProduct($pgPdo, 'BEB-COLA-01', 'Refresco de cola certificado', 'COLD_DRINK', 'UNIT', '0.45', '1.20');
    $productCount += 3;

    $coldMachine = $createMachine($pgPdo, $locationId, 'PLN-CRIO-01', 'PERISHABLE_FOOD');
    $snackMachine = $createMachine($pgPdo, $locationId, 'PLN-SNACK-01', 'SNACKS');
    $hotMachine = $createMachine($pgPdo, $locationId, 'PLN-CALIENTE-01', 'HOT_DRINKS');

    $assert(
        'El escenario siembra la sede, las tres máquinas y los tres productos de las reglas',
        $locationId > 0 && $coldMachine > 0 && $snackMachine > 0 && $hotMachine > 0 && $productCount === 4
    );

    $lowercaseSlot = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $coldMachine, $coldProduct): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity)
             VALUES (:machine, 'a1', :product, 10)"
        );
        $stmt->execute([':machine' => $coldMachine, ':product' => $coldProduct]);
    });

    $assert(
        'Un código de carril en minúsculas se rechaza (chk_machine_planogram_slot_code_format)',
        $lowercaseSlot !== null && $lowercaseSlot['state'] === '23514' && str_contains($lowercaseSlot['message'], 'chk_machine_planogram_slot_code_format'),
        $lowercaseSlot === null ? 'el código inválido se admitió' : 'estado=' . $lowercaseSlot['state']
    );

    $badThreshold = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $coldMachine, $coldProduct): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, min_threshold)
             VALUES (:machine, :slot, :product, 10, 10)'
        );
        $stmt->execute([':machine' => $coldMachine, ':slot' => 'A1', ':product' => $coldProduct]);
    });

    $assert(
        'Un umbral igual a la capacidad se rechaza (chk_machine_planogram_min_threshold)',
        $badThreshold !== null && $badThreshold['state'] === '23514' && str_contains($badThreshold['message'], 'chk_machine_planogram_min_threshold'),
        $badThreshold === null ? 'el umbral imposible se admitió' : 'estado=' . $badThreshold['state']
    );

    $badEstimatedQty = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $coldMachine, $coldProduct): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty)
             VALUES (:machine, :slot, :product, 10, 11)'
        );
        $stmt->execute([':machine' => $coldMachine, ':slot' => 'A1', ':product' => $coldProduct]);
    });

    $assert(
        'Una existencia estimada mayor que la capacidad se rechaza (chk_machine_planogram_estimated_qty)',
        $badEstimatedQty !== null && $badEstimatedQty['state'] === '23514' && str_contains($badEstimatedQty['message'], 'chk_machine_planogram_estimated_qty'),
        $badEstimatedQty === null ? 'la existencia imposible se admitió' : 'estado=' . $badEstimatedQty['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Cerrojo directo MachineType × ProductCategory (Art. II, D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo directo del carril ---\n";

    $coldSlot = $createSlot($pgPdo, $coldMachine, 'A1', $coldProduct);
    $slotSnack = $createSlot($pgPdo, $snackMachine, 'B1', $snackProduct);
    $slotColdDrink = $createSlot($pgPdo, $snackMachine, 'B2', $coldDrink);

    $assert(
        'Un producto refrigerado se admite en una máquina PERISHABLE_FOOD',
        $coldSlot > 0
    );

    $coldIntoHot = PostgresFixtureBuilder::expectRejection(static function () use ($createSlot, $pgPdo, $hotMachine, $coldProduct): void {
        $createSlot($pgPdo, $hotMachine, 'C1', $coldProduct);
    });

    $assert(
        'Un producto refrigerado en una máquina HOT_DRINKS se rechaza con 45000 (COLD_CHAIN_MACHINE_INCOMPATIBLE)',
        $coldIntoHot !== null
            && $coldIntoHot['state'] === '45000'
            && str_contains($coldIntoHot['message'], 'COLD_CHAIN_MACHINE_INCOMPATIBLE'),
        $coldIntoHot === null ? 'el carril incompatible se admitió' : 'estado=' . $coldIntoHot['state']
    );

    $snackIntoHot = PostgresFixtureBuilder::expectRejection(static function () use ($createSlot, $pgPdo, $hotMachine, $snackProduct): void {
        $createSlot($pgPdo, $hotMachine, 'C2', $snackProduct);
    });

    $assert(
        'Una categoría fuera de la matriz se rechaza con 45000 (PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE)',
        $snackIntoHot !== null
            && $snackIntoHot['state'] === '45000'
            && str_contains($snackIntoHot['message'], 'PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE'),
        $snackIntoHot === null ? 'el carril incompatible se admitió' : 'estado=' . $snackIntoHot['state']
    );

    $assert(
        'SNACKS admite bebida fría y snack, y la matriz no generaliza de más',
        $slotSnack > 0 && $slotColdDrink > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Cerrojo inverso del producto (RF-03.2, C1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo inverso del producto ---\n";

    $categoryChange = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $snackProduct): void {
        $pgPdo->exec(
            "UPDATE vending_products SET category = 'HOT_BEVERAGE_INGREDIENT'
              WHERE id = " . $snackProduct
        );
    });

    $assert(
        'Cambiar la categoría de un producto con carriles activos incompatibles se rechaza con 45000',
        $categoryChange !== null
            && $categoryChange['state'] === '45000'
            && str_contains($categoryChange['message'], 'COLD_CHAIN_MACHINE_INCOMPATIBLE'),
        $categoryChange === null ? 'el cambio incompatible se admitió' : 'estado=' . $categoryChange['state']
    );

    $skuChange = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $snackProduct): void {
        $pgPdo->exec("UPDATE vending_products SET sku = 'SNACK-BAR-02' WHERE id = " . $snackProduct);
    });

    $assert(
        'El SKU es inmutable tras el alta (SKU_CHANGE_FORBIDDEN)',
        $skuChange !== null
            && $skuChange['state'] === '45000'
            && str_contains($skuChange['message'], 'SKU_CHANGE_FORBIDDEN'),
        $skuChange === null ? 'el SKU se dejó reescribir' : 'estado=' . $skuChange['state']
    );

    $pgPdo->exec('UPDATE machine_planogram_slots SET is_active = FALSE WHERE id = ' . $slotSnack);

    $categoryAfterDeactivation = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $snackProduct): void {
        $pgPdo->exec(
            "UPDATE vending_products SET category = 'HOT_BEVERAGE_INGREDIENT'
              WHERE id = " . $snackProduct
        );
    });

    $assert(
        'Desactivado el carril, la categoría del producto se puede corregir (la baja lógica no planifica)',
        $categoryAfterDeactivation === null,
        $categoryAfterDeactivation === null ? 'admitido' : 'rechazado con ' . $categoryAfterDeactivation['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Cerrojo inverso de la máquina (RF-03.2, QA 4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo inverso de la máquina ---\n";

    $machineTypeChange = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $snackMachine): void {
        $pgPdo->exec("UPDATE machines SET machine_type = 'HOT_DRINKS' WHERE id = " . $snackMachine);
    });

    $assert(
        'Cambiar la tipología con un carril activo incompatible se rechaza con 45000 (PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE)',
        $machineTypeChange !== null
            && $machineTypeChange['state'] === '45000'
            && str_contains($machineTypeChange['message'], 'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE'),
        $machineTypeChange === null ? 'el cambio de tipología se admitió' : 'estado=' . $machineTypeChange['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Art. III.1: borrado físico prohibido, ventana de purga del arnés aparte
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1 sobre las Tablas 42 y 43 ---\n";

    $slotDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $coldSlot): void {
        $pgPdo->exec('DELETE FROM machine_planogram_slots WHERE id = ' . $coldSlot);
    });

    $assert(
        'El DELETE físico de un carril se rechaza con SQLSTATE 45000 y la marca constitucional',
        $slotDelete !== null
            && $slotDelete['state'] === '45000'
            && str_contains($slotDelete['message'], PURGE_VIOLATION_MARKER),
        $slotDelete === null ? 'el DELETE se admitió' : 'estado=' . $slotDelete['state']
    );

    $productDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $consumable): void {
        $pgPdo->exec('DELETE FROM vending_products WHERE id = ' . $consumable);
    });

    $assert(
        'El DELETE físico de un producto se rechaza con SQLSTATE 45000 y la marca constitucional',
        $productDelete !== null
            && $productDelete['state'] === '45000'
            && str_contains($productDelete['message'], PURGE_VIOLATION_MARKER),
        $productDelete === null ? 'el DELETE se admitió' : 'estado=' . $productDelete['state']
    );

    TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $slotColdDrink, $consumable): void {
        $pgPdo->exec('DELETE FROM machine_planogram_slots WHERE id = ' . $slotColdDrink);
        $pgPdo->exec('DELETE FROM vending_products WHERE id = ' . $consumable);
    });

    $assert(
        'Dentro de la ventana de purga del arnés las filas se pueden retirar (la vía existe y funciona)',
        (int)$pgPdo->query('SELECT COUNT(*) FROM machine_planogram_slots WHERE id = ' . $slotColdDrink)->fetchColumn() === 0
            && (int)$pgPdo->query('SELECT COUNT(*) FROM vending_products WHERE id = ' . $consumable)->fetchColumn() === 0
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, PLANOGRAM_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . PLANOGRAM_TEST_SCHEMA . ".vending_products')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus Tablas 42/43 ya no existen',
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
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DEL CATÁLOGO Y EL PLANOGRAMA CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL ESQUEMA DEL MÓDULO 32.\n";
echo "======================================================================\n";
exit(1);
