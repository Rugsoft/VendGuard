<?php

declare(strict_types=1);

/**
 * PostgresSchemaTest · T-HYBRID-21
 *
 * CRUD real sobre el **motor nuevo**, tabla por tabla, contra el esquema que el repositorio
 * declara: el DDL base (`database/supabase_init.sql`) más las migraciones
 * (`database/supabase_migrations/`), aplicados en un esquema aislado dentro del motor de
 * certificación. Es la sustitución del nombre engañoso `PostgresParityTest`: aquí **no** se
 * compara con el motor local —eso es la correspondencia declarada de T-HYBRID-24, que exige los
 * dos motores a la vez— sino que se demuestra que el motor nuevo **hace** las cuatro operaciones
 * sobre las 28 tablas del contrato.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **El esquema del repositorio se levanta entero** en un esquema aislado: 136 sentencias de
 *      DDL base y la migración del motor aplicada por el ejecutor canónico. Si el DDL dejara de
 *      compilar o la migración dejara de aplicar, no habría dónde certificar nada.
 *
 *   2. **Alta mínima en las 28 tablas respetando el grafo de claves foráneas.** La fixture no se
 *      escribe a mano tabla por tabla: se deriva del catálogo vivo (`information_schema` y
 *      `pg_constraint`), de modo que una columna obligatoria nueva entra sola y una referencia
 *      nueva reordena el alta. Un ciclo en el grafo, o un tipo que el arnés no sepa sintetizar,
 *      se declaran en voz alta en lugar de saltarse la tabla.
 *
 *   3. **Lectura por clave primaria, modificación y borrado**, con una aserción por tabla y por
 *      operación. La modificación se comprueba **por el valor leído**, con la forma nativa de
 *      cada familia de tipo (no comparando cadenas), y el borrado corre dentro de la **ventana de
 *      purga del arnés** (`TestDataCleaner::withPurgeWindow`), que es la vía por la que el propio
 *      arnés dice en voz alta que está reponiendo una base de pruebas (Art. III.1). El rechazo
 *      sin ventana es materia de `PostgresConstitutionalTest`: aquí se certifica que la vía
 *      existe y funciona en el motor nuevo, incluidos los borrados que las suites emiten sin
 *      transacción explícita.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04, spec §8.1): se declara con su marca de categoría, se ejecuta en su
 *   canal propio y su informe va aparte. Sin `PG_TEST_DSN` —contrato de entorno de la categoría,
 *   sin valor por defecto— la suite **no se omite en silencio**: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresSchemaTest.php
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

/** Las 30 tablas del contrato de correspondencia (§2.1, Módulos 14, 17, 18, 19, 20 y 21). */
const CONTRACT_TABLES = [
    'locations', 'machines', 'users', 'incidents', 'incident_history', 'incident_comments',
    'incident_replaced_parts', 'refund_requests', 'unclaimed_cash_findings', 'spare_parts',
    'spare_part_compatibilities', 'spare_part_requests', 'preventive_settings', 'preventive_orders',
    'preventive_order_items', 'sanitary_certificates', 'route_settings', 'sanitary_sweep_passes',
    'sanitary_sweep_contentions', 'audit_log',
    'notification_outbox', 'notification_channel_settings',
    'operational_zones', 'operational_zone_postal_codes', 'technician_zone_assignments',
    'technician_van_stocks', 'incident_service_sheets', 'incident_satisfaction_surveys',
    'technician_absences', 'technician_shifts',
];

/**
 * Tablas de solo inserción: la prueba genérica de `UPDATE` no puede exigir que la fila
 * cambie, porque el motor la rechaza a propósito (Art. III.1). Se certifica el rechazo.
 */
const APPEND_ONLY_TABLES = ['incident_service_sheets', 'incident_satisfaction_surveys'];

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
echo " VENDGUARD · T-HYBRID-21 · CRUD sobre el motor nuevo (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

/** `WHERE` por clave primaria: la fila se direcciona por lo que la tabla declara, no por un `id` supuesto. */
$primaryKeyWhere = static function (PDO $pdo, string $table, array $row): array {
    $predicates = [];
    $params = [];

    foreach (PostgresFixtureBuilder::primaryKey($pdo, $table) as $index => $column) {
        $predicates[] = '"' . $column . '" = :pk' . $index;
        $params[':pk' . $index] = $row[$column];
    }

    return [implode(' AND ', $predicates), $params];
};

$selectByPrimaryKey = static function (PDO $pdo, string $table, array $row) use ($primaryKeyWhere): ?array {
    [$where, $params] = $primaryKeyWhere($pdo, $table, $row);
    $stmt = $pdo->prepare('SELECT * FROM "' . $table . '" WHERE ' . $where . ' LIMIT 1');
    $stmt->execute($params);
    $found = $stmt->fetch();

    return is_array($found) ? $found : null;
};

$rows = [];
$deletedRows = [];
$residueCheck = null;
$crash = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio, entero, en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base compila sentencia a sentencia y las migraciones del motor aplican',
        $schemaReport['statements'] > 100 && in_array('001_initial_baseline.sql', $schemaReport['migrations'], true) && in_array('002_notification_center_outbox.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones aplicadas: %s', $schemaReport['statements'], $schemaReport['migrations'] === [] ? 'ninguna' : implode(', ', $schemaReport['migrations']))
    );

    $assert(
        'El esquema aislado queda convergido con el repositorio (libro de migraciones al día)',
        $convergence['converged'] === true && $convergence['pending'] === [],
        'pendientes: ' . json_encode($convergence['pending'])
    );

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    sort($tables, SORT_STRING);
    $contractSorted = CONTRACT_TABLES;
    sort($contractSorted, SORT_STRING);

    $assert(
        sprintf('El motor nuevo tiene exactamente las %d tablas del contrato', count(CONTRACT_TABLES)),
        $tables === $contractSorted,
        sprintf('%d tablas · ausentes: %s · inesperadas: %s',
            count($tables),
            implode(', ', array_diff($contractSorted, $tables)) ?: 'ninguna',
            implode(', ', array_diff($tables, $contractSorted)) ?: 'ninguna')
    );

    // ─────────────────────────────────────────────────────────────────────
    // Alta: una fila mínima por tabla, en orden que respeta el grafo de claves foráneas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- CREATE: alta mínima de las 28 tablas en orden de claves foráneas ---\n";

    $order = PostgresFixtureBuilder::insertionOrder($pgPdo, $tables);
    $insertionFailure = null;

    foreach ($order as $index => $table) {
        try {
            $rows[$table] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, $table, $rows, sprintf('%02d', $index + 1));
        } catch (Throwable $exception) {
            $insertionFailure = "{$table}: " . $exception->getMessage();
            break;
        }
    }

    $assert(
        sprintf('Se inserta una fila mínima en cada una de las %d tablas respetando el orden de claves foráneas', count(CONTRACT_TABLES)),
        $insertionFailure === null && count($rows) === count(CONTRACT_TABLES),
        $insertionFailure ?? implode(' → ', $order)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Lectura: la fila vuelve por su clave primaria
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- READ: lectura por clave primaria ---\n";

    foreach (CONTRACT_TABLES as $table) {
        if (!isset($rows[$table])) {
            $assert("SELECT · «{$table}» devuelve la fila recién escrita", false, 'la tabla no llegó a sembrarse');
            continue;
        }

        $found = $selectByPrimaryKey($pgPdo, $table, $rows[$table]);
        $required = PostgresFixtureBuilder::requiredColumns($pgPdo, $table);
        $emptyRequired = $found === null
            ? $required
            : array_values(array_filter($required, static fn (string $column): bool => $found[$column] === null));

        $assert(
            "SELECT · «{$table}» devuelve la fila con sus columnas obligatorias pobladas",
            $found !== null && $emptyRequired === [],
            $found === null ? 'la fila no se encontró por su clave primaria' : 'columnas obligatorias vacías: ' . implode(', ', $emptyRequired)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Modificación: comprobada por el valor leído, con la forma nativa de cada tipo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- UPDATE: modificación comprobada por el valor leído ---\n";

    foreach (CONTRACT_TABLES as $index => $table) {
        if (!isset($rows[$table])) {
            $assert("UPDATE · «{$table}» modifica la fila y el cambio se lee", false, 'la tabla no llegó a sembrarse');
            continue;
        }

        $column = PostgresFixtureBuilder::updatableColumn($pgPdo, $table, $rows[$table]);
        $assignment = PostgresFixtureBuilder::assignment($pgPdo, $table, $column, sprintf('%02d', $index + 1));

        if (in_array($table, APPEND_ONLY_TABLES, true)) {
            [$where, $whereParams] = $primaryKeyWhere($pgPdo, $table, $rows[$table]);
            $rejection = PostgresFixtureBuilder::expectRejection(
                static function () use ($pgPdo, $table, $assignment, $where, $whereParams): void {
                    $stmt = $pgPdo->prepare('UPDATE "' . $table . '" SET ' . $assignment['sql'] . ' WHERE ' . $where);
                    $stmt->execute($assignment['params'] + $whereParams);
                }
            );

            $assert(
                "UPDATE · «{$table}» es de solo inserción y el motor rechaza la reescritura",
                $rejection !== null && $rejection['state'] === '45000',
                $rejection === null ? 'la fila se dejó reescribir' : json_encode($rejection)
            );
            continue;
        }
        $nativeType = PostgresFixtureBuilder::columns($pgPdo, $table)[$column]['udt_name'];
        [$where, $whereParams] = $primaryKeyWhere($pgPdo, $table, $rows[$table]);

        $stmt = $pgPdo->prepare('UPDATE "' . $table . '" SET ' . $assignment['sql'] . ' WHERE ' . $where);
        $stmt->execute($assignment['params'] + $whereParams);
        $affected = $stmt->rowCount();

        $after = $selectByPrimaryKey($pgPdo, $table, $rows[$table]);
        $changed = $after !== null && PostgresFixtureBuilder::sameScalar($nativeType, $assignment['expected'], $after[$column]);

        $assert(
            "UPDATE · «{$table}» modifica «{$column}» y el valor nuevo se lee",
            $affected === 1 && $changed,
            sprintf('filas=%d · tipo=%s · esperado=%s · leído=%s',
                $affected,
                $nativeType,
                json_encode($assignment['expected']),
                json_encode($after[$column] ?? null))
        );

        if ($after !== null) {
            $rows[$table] = $after;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Borrado: dentro de la ventana de purga del arnés, en orden inverso al alta
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- DELETE: borrado dentro de la ventana de purga del arnés ---\n";

    $deleteOrder = array_reverse(array_values(array_filter($order, static fn (string $table): bool => isset($rows[$table]))));

    TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $deleteOrder, $rows, $primaryKeyWhere, &$deletedRows): void {
        foreach ($deleteOrder as $table) {
            [$where, $params] = $primaryKeyWhere($pgPdo, $table, $rows[$table]);

            $stmt = $pgPdo->prepare('DELETE FROM "' . $table . '" WHERE ' . $where);
            $stmt->execute($params);
            $deletedRows[$table] = $stmt->rowCount();
        }
    });

    foreach (CONTRACT_TABLES as $table) {
        if (!isset($rows[$table])) {
            $assert("DELETE · «{$table}» borra la fila bajo la ventana de purga", false, 'la tabla no llegó a sembrarse');
            continue;
        }

        $affected = $deletedRows[$table] ?? 0;
        $gone = $selectByPrimaryKey($pgPdo, $table, $rows[$table]) === null;

        $assert(
            "DELETE · «{$table}» borra exactamente su fila y deja de aparecer en la lectura",
            $affected === 1 && $gone,
            sprintf('filas borradas=%d · sigue leyéndose=%s', $affected, $gone ? 'no' : 'sí')
        );
    }
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueCheck = $pgPdo->query("SELECT to_regclass('" . PostgresFixtureBuilder::ISOLATION_SCHEMA . ".locations')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen en el motor',
    $residueCheck === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-HYBRID-21 (CRUD sobre el motor nuevo) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LA CERTIFICACIÓN DEL MOTOR NUEVO.\n";
echo "======================================================================\n";
exit(1);
