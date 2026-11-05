<?php

declare(strict_types=1);

/**
 * PostgresTroubleshootingGuideSchemaTest · T-GUIDE-03
 *
 * Certifica el suelo del Módulo 28 sobre el motor único PostgreSQL: la Tabla 39
 * `machine_troubleshooting_guides` y las guardas que la sostienen.
 *
 *   1. **Forma declarada.** 14 columnas, las tres claves foráneas con `ON DELETE
 *      RESTRICT`, la unicidad `(machine_model_key, breakdown_type)`, los tres
 *      índices de lectura y los tres disparadores: `trg_no_delete_…` (Art. III.1),
 *      `trg_touch_…` (Art. III.3) y `trg_immutable_…` (C1).
 *
 *   2. **Identidad inmutable (C1).** El motor rechaza con `45000` cualquier cambio
 *      de modelo o de categoría tras el alta, mientras que el título —la edición
 *      legítima de Coordinación— se actualiza y se lee.
 *
 *   3. **Cotas de los documentos JSONB (C2, QA 3).** El motor rechaza una guía con
 *      un solo paso, con más de diez pasos, con más de veinte códigos de error y con
 *      más de diez repuestos recomendados, y exige la clave de modelo plegada.
 *
 *   4. **Una guía por modelo y categoría (C1).** El segundo alta del mismo par
 *      choca con `uq_troubleshooting_guides_model_category`, y la causa sugerida
 *      debe existir en `root_causes` (FK RESTRICT).
 *
 *   5. **Prohibición de borrado físico.** Sin la ventana de purga el disparador
 *      constitucional rechaza con `45000`; con la ventana abierta la fila se retira.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible a través de `PG_TEST_DSN`, sin valor por defecto.
 *   Sin motor, la suite se declara no ejecutable con código 2 y no se omite en silencio.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE` en el
 * `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresTroubleshootingGuideSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';

use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const GUIDES_SCHEMA = 't_guides28_schema';

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
echo " VENDGUARD · T-GUIDE-03 · Esquema y guardas de las guías de diagnóstico\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueCheck = null;

try {
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, GUIDES_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio se aplica entero, incluida la migración 024',
        $schemaReport['statements'] > 100
        && in_array('024_machine_troubleshooting_guides.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    echo "\n--- Forma declarada de la Tabla 39 ---\n";

    $columns = PostgresFixtureBuilder::columns($pgPdo, 'machine_troubleshooting_guides');
    $assert('La tabla declara sus 14 columnas canónicas', count($columns) === 14, 'columnas: ' . count($columns));

    $expectedColumns = [
        'id', 'machine_model', 'machine_model_key', 'breakdown_type', 'title',
        'error_codes_json', 'steps_json', 'recommended_spare_part_ids',
        'suggested_root_cause_id', 'is_active', 'created_by', 'updated_by',
        'created_at', 'updated_at',
    ];
    $missingColumns = array_values(array_diff($expectedColumns, array_keys($columns)));
    $assert('Las columnas del contrato están todas presentes', $missingColumns === [], 'faltan: ' . implode(', ', $missingColumns));

    $indexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'machine_troubleshooting_guides'"
    );
    $indexStmt->execute();
    $indexes = array_map(static fn (array $row): string => (string)$row['indexname'], $indexStmt->fetchAll());
    $expectedIndexes = [
        'idx_troubleshooting_guides_model_active',
        'idx_troubleshooting_guides_category_active',
        'idx_troubleshooting_guides_root_cause',
    ];
    $missingIndexes = array_values(array_diff($expectedIndexes, $indexes));
    $assert('Los tres índices de lectura existen', $missingIndexes === [], 'faltan: ' . implode(', ', $missingIndexes));

    $fkStmt = $pgPdo->prepare(
        "SELECT conname, confdeltype FROM pg_constraint
          WHERE conrelid = 'machine_troubleshooting_guides'::regclass AND contype = 'f'"
    );
    $fkStmt->execute();
    $foreignKeys = $fkStmt->fetchAll();
    $allRestrict = $foreignKeys !== []
        && count(array_filter($foreignKeys, static fn (array $row): bool => $row['confdeltype'] === 'r')) === count($foreignKeys);
    $assert(
        'Las tres claves foráneas son ON DELETE RESTRICT (Art. III.3)',
        count($foreignKeys) === 3 && $allRestrict,
        'FKs: ' . count($foreignKeys)
    );

    $uniqueStmt = $pgPdo->prepare(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'machine_troubleshooting_guides'::regclass AND contype = 'u'"
    );
    $uniqueStmt->execute();
    $assert(
        'Existe uq_troubleshooting_guides_model_category',
        in_array('uq_troubleshooting_guides_model_category', array_column($uniqueStmt->fetchAll(), 'conname'), true)
    );

    $triggerStmt = $pgPdo->prepare(
        "SELECT tgname FROM pg_trigger WHERE tgrelid = 'machine_troubleshooting_guides'::regclass AND NOT tgisinternal"
    );
    $triggerStmt->execute();
    $triggers = array_column($triggerStmt->fetchAll(), 'tgname');
    $expectedTriggers = [
        'trg_no_delete_machine_troubleshooting_guides',
        'trg_touch_machine_troubleshooting_guides',
        'trg_immutable_machine_troubleshooting_guides',
    ];
    $missingTriggers = array_values(array_diff($expectedTriggers, $triggers));
    $assert('Los tres disparadores constitucionales están vinculados', $missingTriggers === [], 'faltan: ' . implode(', ', $missingTriggers));

    $auditValues = $pgPdo->query(
        "SELECT enumlabel FROM pg_enum WHERE enumtypid = 'audit_entity_type'::regtype"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'audit_entity_type incorpora MACHINE_TROUBLESHOOTING_GUIDE',
        in_array('MACHINE_TROUBLESHOOTING_GUIDE', $auditValues, true)
    );

    echo "\n--- Alta válida, edición legítima e identidad inmutable (C1) ---\n";

    $parents = [];
    $parents['users'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'users', $parents, '01');
    $parents['root_causes'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'root_causes', $parents, '01');

    $sequence = 0;
    $insertGuide = static function (array $overrides = []) use ($pgPdo, $parents, &$sequence): int {
        $sequence++;
        $steps = $overrides['steps'] ?? [
            ['step_order' => 1, 'title' => 'Comprobar el componente', 'instruction' => 'Revisar el componente indicado antes de continuar con la prueba.'],
            ['step_order' => 2, 'title' => 'Confirmar el resultado', 'instruction' => 'Verificar que la medida queda dentro del rango declarado por el fabricante.'],
        ];
        $errorCodes = $overrides['error_codes'] ?? [['code' => 'E01', 'meaning' => 'Fallo sintético de certificación']];

        $stmt = $pgPdo->prepare(
            "INSERT INTO machine_troubleshooting_guides (
                machine_model, machine_model_key, breakdown_type, title,
                error_codes_json, steps_json, recommended_spare_part_ids,
                suggested_root_cause_id, created_by, updated_by
            ) VALUES (
                :machine_model, :machine_model_key, :breakdown_type, :title,
                CAST(:error_codes AS JSONB), CAST(:steps AS JSONB), CAST(:spare_parts AS JSONB),
                :root_cause_id, :actor, :actor
            ) RETURNING id"
        );
        $stmt->execute([
            ':machine_model' => $overrides['machine_model'] ?? 'Modelo sintético ' . $sequence,
            ':machine_model_key' => $overrides['machine_model_key'] ?? 'fixture.guide.' . $sequence,
            ':breakdown_type' => $overrides['breakdown_type'] ?? 'OTHER',
            ':title' => $overrides['title'] ?? 'Guía sintética de certificación del Módulo 28',
            ':error_codes' => (string)json_encode($errorCodes, JSON_UNESCAPED_UNICODE),
            ':steps' => (string)json_encode($steps, JSON_UNESCAPED_UNICODE),
            ':spare_parts' => (string)json_encode($overrides['spare_parts'] ?? []),
            ':root_cause_id' => $overrides['root_cause_id'] ?? $parents['root_causes']['id'],
            ':actor' => $parents['users']['id'],
        ]);

        return (int)$stmt->fetchColumn();
    };

    $guideId = $insertGuide();
    $assert('Una guía válida se inserta con su clave generada', $guideId > 0, 'id: ' . $guideId);

    $stepsRead = $pgPdo->query(
        'SELECT jsonb_array_length(steps_json) FROM machine_troubleshooting_guides WHERE id = ' . $guideId
    )->fetchColumn();
    $assert('El motor conserva los dos pasos declarados', (int)$stepsRead === 2, 'pasos: ' . (string)$stepsRead);

    $immutableModel = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE machine_troubleshooting_guides SET machine_model = 'Otro modelo' WHERE id = " . $guideId)
    );
    $assert(
        'Se rechaza cambiar el modelo visible de una guía (C1)',
        $immutableModel !== null && $immutableModel['state'] === '45000',
        $immutableModel === null ? 'la fila se actualizó' : $immutableModel['message']
    );

    $immutableKey = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE machine_troubleshooting_guides SET machine_model_key = 'otro.modelo' WHERE id = " . $guideId)
    );
    $assert(
        'Se rechaza cambiar la clave de modelo de una guía (C1)',
        $immutableKey !== null && $immutableKey['state'] === '45000',
        $immutableKey === null ? 'la fila se actualizó' : $immutableKey['message']
    );

    $immutableCategory = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE machine_troubleshooting_guides SET breakdown_type = 'PRODUCT_JAM' WHERE id = " . $guideId)
    );
    $assert(
        'Se rechaza cambiar la categoría de avería de una guía (C1)',
        $immutableCategory !== null && $immutableCategory['state'] === '45000',
        $immutableCategory === null ? 'la fila se actualizó' : $immutableCategory['message']
    );

    $editable = $pgPdo->exec("UPDATE machine_troubleshooting_guides SET title = 'Título editado por Coordinación' WHERE id = " . $guideId);
    $titleRead = $pgPdo->query(
        'SELECT title FROM machine_troubleshooting_guides WHERE id = ' . $guideId
    )->fetchColumn();
    $assert(
        'El título es editable y el cambio se lee',
        $editable === 1 && $titleRead === 'Título editado por Coordinación',
        'filas: ' . (string)$editable . ' · título: ' . (string)$titleRead
    );

    echo "\n--- Cotas de los documentos JSONB y clave plegada (C2) ---\n";

    $stepsFor = static fn (int $count): array => array_map(
        static fn (int $i): array => [
            'step_order' => $i,
            'title' => 'Paso sintético ' . $i,
            'instruction' => 'Comprobar el componente número ' . $i . ' antes de continuar con la prueba.',
        ],
        range(1, max(1, $count))
    );
    $codesFor = static fn (int $count): array => array_map(
        static fn (int $i): array => ['code' => 'E' . $i, 'meaning' => 'Código sintético número ' . $i],
        range(1, max(1, $count))
    );

    $singleStep = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['steps' => $stepsFor(1)])
    );
    $assert(
        'Se rechaza una guía con un solo paso (2..10)',
        $singleStep !== null && $singleStep['state'] === '23514',
        $singleStep === null ? 'la fila se insertó' : $singleStep['message']
    );

    $elevenSteps = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['steps' => $stepsFor(11)])
    );
    $assert(
        'Se rechaza una guía con más de diez pasos',
        $elevenSteps !== null && $elevenSteps['state'] === '23514',
        $elevenSteps === null ? 'la fila se insertó' : $elevenSteps['message']
    );

    $tooManyCodes = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['error_codes' => $codesFor(21)])
    );
    $assert(
        'Se rechaza una guía con más de veinte códigos de error',
        $tooManyCodes !== null && $tooManyCodes['state'] === '23514',
        $tooManyCodes === null ? 'la fila se insertó' : $tooManyCodes['message']
    );

    $tooManyParts = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['spare_parts' => range(1, 11)])
    );
    $assert(
        'Se rechaza una guía con más de diez repuestos recomendados',
        $tooManyParts !== null && $tooManyParts['state'] === '23514',
        $tooManyParts === null ? 'la fila se insertó' : $tooManyParts['message']
    );

    $shortTitle = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['title' => 'Guía'])
    );
    $assert(
        'Se rechaza un título por debajo de cinco caracteres',
        $shortTitle !== null && $shortTitle['state'] === '23514',
        $shortTitle === null ? 'la fila se insertó' : $shortTitle['message']
    );

    $uppercaseKey = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['machine_model_key' => 'Fixture.Guide.Mayusculas'])
    );
    $assert(
        'Se rechaza una clave de modelo sin plegar a minúsculas',
        $uppercaseKey !== null && $uppercaseKey['state'] === '23514',
        $uppercaseKey === null ? 'la fila se insertó' : $uppercaseKey['message']
    );

    $missingRootCause = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['root_cause_id' => 2147483000])
    );
    $assert(
        'Se rechaza una causa raíz inexistente (FK RESTRICT)',
        $missingRootCause !== null && $missingRootCause['state'] === '23503',
        $missingRootCause === null ? 'la fila se insertó' : $missingRootCause['message']
    );

    echo "\n--- Una guía por modelo y categoría (C1) ---\n";

    $insertGuide(['machine_model_key' => 'fixture.duplicate', 'breakdown_type' => 'OTHER']);
    $duplicate = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertGuide(['machine_model_key' => 'fixture.duplicate', 'breakdown_type' => 'OTHER'])
    );
    $assert(
        'El segundo alta del mismo modelo y categoría choca con la clave única',
        $duplicate !== null
        && $duplicate['state'] === '23505'
        && str_contains($duplicate['message'], 'uq_troubleshooting_guides_model_category'),
        $duplicate === null ? 'la fila se insertó' : $duplicate['message']
    );

    echo "\n--- Borrado físico: disparador constitucional ---\n";

    $deleteGuide = static fn (): mixed => $pgPdo->exec('DELETE FROM machine_troubleshooting_guides WHERE id = ' . $guideId);
    $constitutional = PostgresFixtureBuilder::expectRejection($deleteGuide);
    $assert(
        'Sin la ventana de purga, el disparador rechaza el borrado con SQLSTATE 45000 (Art. III.1)',
        $constitutional !== null && $constitutional['state'] === '45000',
        $constitutional === null ? 'la fila se borró' : $constitutional['message']
    );

    $deleted = TestDataCleaner::withPurgeWindow($pgPdo, static fn (): mixed => $deleteGuide());
    $remaining = (int)$pgPdo->query(
        'SELECT count(*) FROM machine_troubleshooting_guides WHERE id = ' . $guideId
    )->fetchColumn();
    $assert(
        'Con la ventana abierta, la fila se retira y deja de leerse',
        $deleted === 1 && $remaining === 0,
        'filas borradas: ' . (string)$deleted
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, GUIDES_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . GUIDES_SCHEMA . ".machine_troubleshooting_guides')"
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
    echo " RESULT: 100% EN VERDE. T-GUIDE-03 (esquema y guardas) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL ESQUEMA DE LAS GUÍAS DE DIAGNÓSTICO.\n";
echo "======================================================================\n";
exit(1);
