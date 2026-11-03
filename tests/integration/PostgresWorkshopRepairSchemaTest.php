<?php

declare(strict_types=1);

/**
 * PostgresWorkshopRepairSchemaTest · T-WORKSHOP-03
 *
 * Certifica el suelo del Módulo 27 sobre el motor único PostgreSQL: la Tabla 38
 * `workshop_repair_items` y las guardas que la sostienen.
 *
 *   1. **Forma declarada.** 34 columnas, las 10 claves foráneas con `ON DELETE
 *      RESTRICT`, la unicidad `(incident_replaced_part_id, unit_index)`, los seis
 *      índices de la bandeja y los disparadores `trg_no_delete_workshop_repair_items`
 *      (Art. III.1) y `trg_touch_workshop_repair_items` (Art. III.3).
 *
 *   2. **Desglose unitario `1..N`.** El backfill de la migración 023 convierte una
 *      línea con `quantity = 3` en tres expedientes con `unit_index = 1..3`, y
 *      reejecutarlo no duplica ni reescribe (D1, C4, C6, QA 4).
 *
 *   3. **Coherencia terminal (`C1`, `C5`, `D3`).** El motor rechaza un dictamen sin
 *      diagnóstico, un diagnóstico por debajo de 20 caracteres, el reintegro a
 *      furgoneta de una pieza fuera de catálogo, una baja con destino de stock y una
 *      baja que declare ahorro.
 *
 *   4. **Prohibición de borrado físico.** Sin la ventana de purga, el disparador
 *      constitucional rechaza con `45000`; con la ventana abierta, la clave foránea
 *      `fk_workshop_repair_replaced_part` impide borrar la sustitución padre mientras
 *      exista su expediente (Art. III.3).
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible a través de `PG_TEST_DSN`, sin valor por defecto.
 *   Sin motor, la suite se declara no ejecutable con código 2 y no se omite en silencio.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE` en el
 * `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresWorkshopRepairSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';

use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const WORKSHOP_SCHEMA = 't_workshop27_schema';

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
echo " VENDGUARD · T-WORKSHOP-03 · Esquema y guardas de la bandeja de taller\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueCheck = null;

try {
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, WORKSHOP_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio se aplica entero, incluida la migración 023',
        $schemaReport['statements'] > 100 && in_array('023_workshop_repair_items.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    echo "\n--- Forma declarada de la Tabla 38 ---\n";

    $columns = PostgresFixtureBuilder::columns($pgPdo, 'workshop_repair_items');
    $assert('La tabla declara sus 34 columnas canónicas', count($columns) === 34, 'columnas: ' . count($columns));

    $indexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'workshop_repair_items'"
    );
    $indexStmt->execute();
    $indexes = array_map(static fn (array $row): string => (string)$row['indexname'], $indexStmt->fetchAll());
    $expectedIndexes = [
        'idx_workshop_repair_status_created',
        'idx_workshop_repair_extracted_tech',
        'idx_workshop_repair_spare_part',
        'idx_workshop_repair_incident',
        'idx_workshop_repair_preventive_order',
        'idx_workshop_repair_resolved_at',
    ];
    $missingIndexes = array_values(array_diff($expectedIndexes, $indexes));
    $assert('Los seis índices de la bandeja existen', $missingIndexes === [], 'faltan: ' . implode(', ', $missingIndexes));

    $fkStmt = $pgPdo->prepare(
        "SELECT conname, confdeltype FROM pg_constraint
          WHERE conrelid = 'workshop_repair_items'::regclass AND contype = 'f'"
    );
    $fkStmt->execute();
    $foreignKeys = $fkStmt->fetchAll();
    $allRestrict = $foreignKeys !== []
        && count(array_filter($foreignKeys, static fn (array $row): bool => $row['confdeltype'] === 'r')) === count($foreignKeys);
    $assert(
        'Las diez claves foráneas son ON DELETE RESTRICT (Art. III.3)',
        count($foreignKeys) === 10 && $allRestrict,
        'FKs: ' . count($foreignKeys)
    );

    $uniqueStmt = $pgPdo->prepare(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'workshop_repair_items'::regclass AND contype = 'u'"
    );
    $uniqueStmt->execute();
    $assert(
        'Existe uq_workshop_repair_replaced_part_unit',
        in_array('uq_workshop_repair_replaced_part_unit', array_column($uniqueStmt->fetchAll(), 'conname'), true)
    );

    $triggerStmt = $pgPdo->prepare(
        "SELECT tgname FROM pg_trigger WHERE tgrelid = 'workshop_repair_items'::regclass AND NOT tgisinternal"
    );
    $triggerStmt->execute();
    $triggers = array_column($triggerStmt->fetchAll(), 'tgname');
    $assert(
        'Los dos disparadores constitucionales están vinculados',
        in_array('trg_no_delete_workshop_repair_items', $triggers, true)
        && in_array('trg_touch_workshop_repair_items', $triggers, true),
        implode(', ', $triggers)
    );

    $statusEnum = $pgPdo->query(
        "SELECT enumlabel FROM pg_enum WHERE enumtypid = 'workshop_repair_status'::regtype ORDER BY enumsortorder"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'workshop_repair_status declara los cinco estados del ciclo (D1)',
        $statusEnum === ['PENDING_DELIVERY', 'RECEIVED_IN_WORKSHOP', 'IN_BENCH_TEST', 'REFURBISHED_RESTORED', 'SCRAPPED_IRREPARABLE'],
        implode(', ', $statusEnum)
    );

    $destinationEnum = $pgPdo->query(
        "SELECT enumlabel FROM pg_enum WHERE enumtypid = 'workshop_restock_destination'::regtype ORDER BY enumsortorder"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'workshop_restock_destination declara los dos destinos de reintegro (D3)',
        $destinationEnum === ['CENTRAL_WAREHOUSE', 'TECHNICIAN_VAN'],
        implode(', ', $destinationEnum)
    );

    echo "\n--- Desglose unitario 1..N y backfill idempotente ---\n";

    $parents = [];
    $parents['locations'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'locations', $parents, '01');
    $parents['machines'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $parents, '01');
    $parents['users'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'users', $parents, '01');
    $parents['spare_parts'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'spare_parts', $parents, '01');
    $parents['incidents'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'incidents', $parents, '01');

    $replacedPart = PostgresFixtureBuilder::insertWorkshopReplacedPart($pgPdo, $parents, '01', 3);
    $replacedPartId = (int)$replacedPart['id'];

    $applyMigration = static function (PDO $pdo, string $path): void {
        foreach (SqlScriptSplitter::split((string)file_get_contents($path)) as $statement) {
            $pdo->exec($statement);
        }
    };
    $applyMigration($pgPdo, $projectRoot . '/database/supabase_migrations/023_workshop_repair_items.sql');

    $units = $pgPdo->prepare(
        'SELECT unit_index, total_units_in_line FROM workshop_repair_items
          WHERE incident_replaced_part_id = :id ORDER BY unit_index'
    );
    $units->execute([':id' => $replacedPartId]);
    $unitRows = $units->fetchAll();
    $assert(
        'Una línea con quantity = 3 genera tres expedientes unitarios (D1, QA 4)',
        count($unitRows) === 3
        && array_column($unitRows, 'unit_index') === [1, 2, 3]
        && array_unique(array_column($unitRows, 'total_units_in_line')) === [3],
        'filas: ' . count($unitRows)
    );

    $applyMigration($pgPdo, $projectRoot . '/database/supabase_migrations/023_workshop_repair_items.sql');
    $units->execute([':id' => $replacedPartId]);
    $assert(
        'Reejecutar el backfill no duplica unidades',
        count($units->fetchAll()) === 3
    );

    echo "\n--- Coherencia terminal del ciclo (C1, C5, D3) ---\n";

    $unitId = (int)$pgPdo->query(
        'SELECT id FROM workshop_repair_items WHERE incident_replaced_part_id = ' . $replacedPartId . ' ORDER BY unit_index LIMIT 1'
    )->fetchColumn();
    $coordinatorEmail = (string)$parents['users']['email'];

    $updateUnit = static function (PDO $pdo, int $id, string $set): void {
        $pdo->exec('UPDATE workshop_repair_items SET ' . $set . ' WHERE id = ' . $id);
    };

    $missingDiagnosis = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $updateUnit(
            $pgPdo,
            $unitId,
            "status = 'REFURBISHED_RESTORED', received_at = CURRENT_TIMESTAMP,
             received_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             bench_started_at = CURRENT_TIMESTAMP, resolved_at = CURRENT_TIMESTAMP,
             resolved_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             restock_destination = 'CENTRAL_WAREHOUSE'"
        )
    );
    $assert(
        'Se rechaza un dictamen sin diagnóstico de banco',
        $missingDiagnosis !== null && $missingDiagnosis['state'] === '23514',
        $missingDiagnosis === null ? 'la fila se actualizó' : $missingDiagnosis['message']
    );

    $shortDiagnosis = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $updateUnit($pgPdo, $unitId, "bench_diagnosis = 'Demasiado corto'")
    );
    $assert(
        'Se rechaza un diagnóstico por debajo de 20 caracteres (Art. V.1)',
        $shortDiagnosis !== null && $shortDiagnosis['state'] === '23514',
        $shortDiagnosis === null ? 'la fila se actualizó' : $shortDiagnosis['message']
    );

    $outOfCatalogVan = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $updateUnit(
            $pgPdo,
            $unitId,
            "status = 'REFURBISHED_RESTORED', received_at = CURRENT_TIMESTAMP,
             received_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             bench_started_at = CURRENT_TIMESTAMP,
             bench_diagnosis = 'Pieza calibrada y verificada en banco de pruebas.',
             resolved_at = CURRENT_TIMESTAMP,
             resolved_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             restock_destination = 'TECHNICIAN_VAN', is_out_of_catalog = TRUE"
        )
    );
    $assert(
        'Se rechaza reintegrar a furgoneta una pieza fuera de catálogo (D3)',
        $outOfCatalogVan !== null && $outOfCatalogVan['state'] === '23514',
        $outOfCatalogVan === null ? 'la fila se actualizó' : $outOfCatalogVan['message']
    );

    $scrappedWithDestination = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $updateUnit(
            $pgPdo,
            $unitId,
            "status = 'SCRAPPED_IRREPARABLE', received_at = CURRENT_TIMESTAMP,
             received_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             bench_started_at = CURRENT_TIMESTAMP,
             bench_diagnosis = 'Placa corroída sin reparación posible, se envía a reciclaje.',
             resolved_at = CURRENT_TIMESTAMP,
             resolved_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             restock_destination = 'CENTRAL_WAREHOUSE'"
        )
    );
    $assert(
        'Se rechaza una baja definitiva con destino de stock',
        $scrappedWithDestination !== null && $scrappedWithDestination['state'] === '23514',
        $scrappedWithDestination === null ? 'la fila se actualizó' : $scrappedWithDestination['message']
    );

    $scrappedWithSavings = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $updateUnit(
            $pgPdo,
            $unitId,
            "status = 'SCRAPPED_IRREPARABLE', received_at = CURRENT_TIMESTAMP,
             received_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             bench_started_at = CURRENT_TIMESTAMP,
             bench_diagnosis = 'Placa corroída sin reparación posible, se envía a reciclaje.',
             resolved_at = CURRENT_TIMESTAMP,
             resolved_by_user_id = (SELECT id FROM users WHERE email = '{$coordinatorEmail}'),
             net_savings_eur = 12.00"
        )
    );
    $assert(
        'Se rechaza una baja definitiva que declare ahorro neto',
        $scrappedWithSavings !== null && $scrappedWithSavings['state'] === '23514',
        $scrappedWithSavings === null ? 'la fila se actualizó' : $scrappedWithSavings['message']
    );

    echo "\n--- Borrado físico: disparador y clave foránea ---\n";

    $deleteUnit = static function () use ($pgPdo, $unitId): void {
        $pgPdo->exec('DELETE FROM workshop_repair_items WHERE id = ' . $unitId);
    };

    $constitutional = PostgresFixtureBuilder::expectRejection($deleteUnit);
    $assert(
        'Sin la ventana de purga, el disparador rechaza el borrado con SQLSTATE 45000 (Art. III.1)',
        $constitutional !== null && $constitutional['state'] === '45000',
        $constitutional === null ? 'la fila se borró' : $constitutional['message']
    );

    $parentRestrict = TestDataCleaner::withPurgeWindow(
        $pgPdo,
        static fn (): ?array => PostgresFixtureBuilder::expectRejection(
            static fn (): mixed => $pgPdo->exec('DELETE FROM incident_replaced_parts WHERE id = ' . $replacedPartId)
        )
    );
    $assert(
        'Con la ventana abierta, fk_workshop_repair_replaced_part impide borrar la sustitución padre',
        $parentRestrict !== null
        && $parentRestrict['state'] === '23503'
        && str_contains($parentRestrict['message'], 'fk_workshop_repair_replaced_part'),
        $parentRestrict === null ? 'la sustitución se borró' : $parentRestrict['message']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, WORKSHOP_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . WORKSHOP_SCHEMA . ".workshop_repair_items')"
    )->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado se retira entero y no deja tablas en el motor',
    $residueCheck === null,
    'residuo: ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-WORKSHOP-03 (esquema y guardas) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL ESQUEMA DE LA BANDEJA DE TALLER.\n";
echo "======================================================================\n";
exit(1);
