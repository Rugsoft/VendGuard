<?php

declare(strict_types=1);

/**
 * SchemaConvergenceParityTest · T-HYBRID-24
 *
 * Certifica **dos cosas distintas y separadas**, tal y como las declara la tarea:
 *
 *   1. **Convergencia.** Una base construida solo con el DDL base se detecta como
 *      incompleta; el ejecutor de migraciones la hace converger al esquema del
 *      repositorio (`database/supabase_init.sql` + `database/supabase_migrations/`);
 *      la guarda del baseline muerde y el libro registra la huella.
 *
 *   2. **Correspondencia declarada.** Qué debe coincidir uno a uno entre los dos
 *      motores y qué se acepta por diseño, según
 *      `specs/technical/schema_correspondence_contract.md`: tablas, columnas, claves
 *      únicas, claves foráneas con sus reglas, conjuntos `ENUM`, el mapeo de tipos y
 *      las adiciones `A-1` a `A-5`. **Nunca igualdad literal de inventarios**: el
 *      diseño la impide a propósito y este contrato la prohíbe.
 *
 * CATEGORÍA: `new_engine`
 *   Esta suite exige un PostgreSQL disponible (el motor local de
 *   `deploy/local/postgres/`) **y** el MariaDB local a la vez. Por eso NO pertenece a
 *   la batería hermética: se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte (RNF-04, spec §8.1). Sin `PG_TEST_DSN` la categoría
 *   no es ejecutable y esta suite lo dice y sale con código 2 —sin omitirse en
 *   silencio—: ninguna aserción se marca como superada sin motor real.
 *
 * Uso:
 *   PG_TEST_DSN='pgsql:host=...;dbname=...' php tests/integration/SchemaConvergenceParityTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Las 20 tablas del contrato de correspondencia (§2.1). */
const CONTRACT_TABLES = [
    'locations', 'machines', 'users', 'incidents', 'incident_history', 'incident_comments',
    'incident_replaced_parts', 'refund_requests', 'unclaimed_cash_findings', 'spare_parts',
    'spare_part_compatibilities', 'spare_part_requests', 'preventive_settings', 'preventive_orders',
    'preventive_order_items', 'sanitary_certificates', 'route_settings', 'sanitary_sweep_passes',
    'sanitary_sweep_contentions', 'audit_log',
];

/** Adiciones declaradas del motor nuevo (§5). */
const ADDITION_LEDGER = 'schema_migrations';       // A-5
const ADDITION_USERS_KEY = 'email_key';            // A-1
const ADDITION_LOCATIONS_KEY = 'site_code_key';    // A-2
const ADDITION_CHECKS = [                          // A-3
    'chk_resolution_diagnosis_len',
    'chk_resolution_action_len',
    'chk_pending_info_reason_text_len',
    'chk_cancellation_reason_len',
    'chk_coordinator_justification_len',
    'chk_technician_justification_len',
    'chk_custom_part_description_len',
];

/** Diferencia aceptada D-6: la columna derivada del motor local y el índice único parcial del nuevo. */
const DIFFERENCE_D6_COLUMN = 'is_active_ticket';
const DIFFERENCE_D6_INDEX = 'uq_machine_active_ticket';

$projectRoot = dirname(__DIR__, 2);
$failures = 0;
$assertions = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$failures, &$assertions): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$listSample = static function (array $items, int $limit = 12): string {
    $slice = array_slice($items, 0, $limit);
    $suffix = count($items) > $limit ? sprintf(' … (+%d)', count($items) - $limit) : '';

    return implode(' · ', $slice) . $suffix;
};

/** Compara dos inventarios como conjuntos: el orden del catálogo no es una diferencia. */
$sameSet = static function (array $left, array $right): bool {
    sort($left, SORT_STRING);
    sort($right, SORT_STRING);

    return $left === $right;
};

echo "======================================================================\n";
echo " VENDGUARD · T-HYBRID-24 · Convergencia y correspondencia de esquemas\n";
echo " Categoría: new_engine (requiere PostgreSQL y MariaDB a la vez)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// Puerta de la categoría: sin motor no hay verde ni omisión silenciosa
// ─────────────────────────────────────────────────────────────────────────────
$dsn = getenv('PG_TEST_DSN');
if ($dsn === false || trim($dsn) === '') {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] Falta PG_TEST_DSN: el motor nuevo no está declarado.\n");
    fwrite(STDERR, "Aprovisione deploy/local/postgres/ y exporte su contrato de entorno; la suite no se omite en silencio.\n");
    exit(2);
}

try {
    $pgPdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $pgPdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor nuevo no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$mariaPdo = ConnectionFactory::getConnection(['dbname' => null], true);
$mariaSchema = (string)$mariaPdo->query('SELECT DATABASE()')->fetchColumn();
$mariaPdo->exec('USE `' . $mariaSchema . '`');

$schema = 'contract_convergence_check';
$emptySchema = $schema . '_empty';

// ─────────────────────────────────────────────────────────────────────────────
// Lectores de catálogo: la verdad es el catálogo vivo, nunca un fichero .sql
// ─────────────────────────────────────────────────────────────────────────────
$applyStatements = static function (PDO $pdo, string $path): int {
    $executed = 0;
    foreach (SqlScriptSplitter::split((string)file_get_contents($path)) as $statement) {
        $result = $pdo->query($statement);
        if ($result instanceof PDOStatement) {
            $result->closeCursor();
        }
        $executed++;
    }

    return $executed;
};

/** @return list<string> */
$readTables = static function (PDO $pdo, string $schema, string $catalog): array {        $sql = $catalog === 'pgsql'
        ? "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = :schema AND table_type = 'BASE TABLE' ORDER BY table_name"
        : 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type ORDER BY TABLE_NAME';

        // (El orden del catálogo no forma parte del contrato: las comparaciones van por conjunto.)

    $stmt = $pdo->prepare($sql);
    $stmt->execute($catalog === 'pgsql' ? [':schema' => $schema] : [':schema' => $schema, ':type' => 'BASE TABLE']);

    return array_map(static fn (array $row): string => (string)$row['name'], $stmt->fetchAll());
};

/** @return array<string, array<string, array{type: string, nullable: bool}>> */
$readColumnNames = static function (PDO $pdo, string $schema, string $catalog): array {
    $sql = $catalog === 'pgsql'
        ? 'SELECT table_name, column_name, is_nullable FROM information_schema.columns WHERE table_schema = :schema ORDER BY table_name, column_name'
        : 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME, COLUMN_NAME';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':schema' => $schema]);

    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[(string)$row['table_name']][(string)$row['column_name']] = [
            'nullable' => strtoupper((string)$row['is_nullable']) === 'YES',
        ];
    }

    return $columns;
};

/** @return array<string, array<string, array<string, mixed>>> */
$readPostgresTypes = static function (PDO $pdo, string $schema): array {
    $stmt = $pdo->prepare(
        'SELECT table_name, column_name, data_type, udt_name, character_maximum_length, numeric_precision, numeric_scale, is_identity
           FROM information_schema.columns
          WHERE table_schema = :schema'
    );
    $stmt->execute([':schema' => $schema]);

    $types = [];
    foreach ($stmt->fetchAll() as $row) {
        $types[(string)$row['table_name']][(string)$row['column_name']] = [
            'data_type' => (string)$row['data_type'],
            'udt_name' => (string)$row['udt_name'],
            'length' => $row['character_maximum_length'] !== null ? (int)$row['character_maximum_length'] : null,
            'precision' => $row['numeric_precision'] !== null ? (int)$row['numeric_precision'] : null,
            'scale' => $row['numeric_scale'] !== null ? (int)$row['numeric_scale'] : null,
            'identity' => (string)$row['is_identity'] === 'YES',
        ];
    }

    return $types;
};

/** @return array<string, array<string, string>> [table][column] => COLUMN_TYPE */
$readMariaTypes = static function (PDO $pdo, string $schema): array {
    $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema');
    $stmt->execute([':schema' => $schema]);

    $types = [];
    foreach ($stmt->fetchAll() as $row) {
        $types[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = (string)$row['COLUMN_TYPE'];
    }

    return $types;
};

/**
 * Conjuntos únicos por tabla, comparados por **columnas indexadas** y no por nombre (D-9).
 *
 * @return array<string, list<string>> table => listas de columnas separadas por coma
 */
$readUniqueSets = static function (PDO $pdo, string $schema, string $catalog): array {
    if ($catalog === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT c.relname AS table_name, ix.indisprimary AS is_primary,
                    array_to_string(ARRAY(
                        SELECT pg_get_indexdef(ix.indexrelid, k + 1, true)
                          FROM generate_subscripts(ix.indkey, 1) AS k
                         ORDER BY k
                    ), ',') AS columns
               FROM pg_index ix
               JOIN pg_class c ON c.oid = ix.indrelid
               JOIN pg_class i ON i.oid = ix.indexrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = :schema AND ix.indisunique"
        );
        $stmt->execute([':schema' => $schema]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME AS table_name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = :schema AND NON_UNIQUE = 0
              GROUP BY TABLE_NAME, INDEX_NAME'
        );
        $stmt->execute([':schema' => $schema]);
    }

    $sets = [];
    foreach ($stmt->fetchAll() as $row) {
        $sets[(string)$row['table_name']][] = (string)$row['columns'];
    }
    foreach ($sets as &$tableSets) {
        sort($tableSets, SORT_STRING);
    }
    unset($tableSets);

    return $sets;
};

/** @return array<string, list<string>> table => "cols -> ref(refcols) DELETE/UPDATE" */
$readForeignKeys = static function (PDO $pdo, string $schema, string $catalog): array {
    if ($catalog === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT tc.table_name, kcu.column_name, ccu.table_name AS ref_table, ccu.column_name AS ref_column,
                    rc.delete_rule, rc.update_rule
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
               JOIN information_schema.referential_constraints rc
                 ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
               JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
              WHERE tc.table_schema = :schema AND tc.constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute([':schema' => $schema]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT k.TABLE_NAME AS table_name, k.COLUMN_NAME AS column_name,
                    k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_column,
                    r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = :schema AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute([':schema' => $schema]);
    }

    $normalizeRule = static fn (string $rule): string => strtoupper($rule) === 'NO ACTION' ? 'RESTRICT' : strtoupper($rule);

    $keys = [];
    foreach ($stmt->fetchAll() as $row) {
        $keys[(string)$row['table_name']][] = sprintf(
            '%s -> %s(%s) ON DELETE %s ON UPDATE %s',
            (string)$row['column_name'],
            (string)$row['ref_table'],
            (string)$row['ref_column'],
            $normalizeRule((string)$row['delete_rule']),
            $normalizeRule((string)$row['update_rule'])
        );
    }
    foreach ($keys as &$tableKeys) {
        sort($tableKeys, SORT_STRING);
    }
    unset($tableKeys);

    return $keys;
};

/** @return array<string, list<string>> "tabla.columna" => miembros del conjunto, ordenados */
$readEnums = static function (PDO $pdo, string $schema, string $catalog): array {
    $enums = [];

    if ($catalog === 'pgsql') {
        // El tipo se empareja por nombre **y** por espacio de nombres: los conjuntos del
        // contrato viven en el mismo esquema que sus columnas, y sin esta segunda
        // condición un motor que ya tenga el esquema desplegado en `public` (el caso de
        // la categoría contra un motor inicializado) devolvería cada etiqueta dos veces y
        // la comparación fallaría por una razón que no es una divergencia real.
        $stmt = $pdo->prepare(
            "SELECT c.table_name, c.column_name, e.enumlabel
               FROM information_schema.columns c
               JOIN pg_namespace n ON n.nspname = c.table_schema
               JOIN pg_type t ON t.typname = c.udt_name AND t.typnamespace = n.oid
               JOIN pg_enum e ON e.enumtypid = t.oid
              WHERE c.table_schema = :schema
              ORDER BY c.table_name, c.column_name, e.enumsortorder"
        );
        $stmt->execute([':schema' => $schema]);

        foreach ($stmt->fetchAll() as $row) {
            $enums[(string)$row['table_name'] . '.' . (string)$row['column_name']][] = (string)$row['enumlabel'];
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = :schema AND DATA_TYPE = 'enum'"
        );
        $stmt->execute([':schema' => $schema]);

        foreach ($stmt->fetchAll() as $row) {
            preg_match_all("/'((?:[^']|'')*)'/", (string)$row['COLUMN_TYPE'], $members);
            $enums[(string)$row['TABLE_NAME'] . '.' . (string)$row['COLUMN_NAME']] = array_map(
                static fn (string $member): string => str_replace("''", "'", $member),
                $members[1]
            );
        }
    }

    foreach ($enums as &$members) {
        sort($members, SORT_STRING);
    }
    unset($members);

    return $enums;
};

// ─────────────────────────────────────────────────────────────────────────────
// Los dos escenarios y el contraste
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$emptySchema} CASCADE");
    $pgPdo->exec("CREATE SCHEMA {$schema}");
    $pgPdo->exec("CREATE SCHEMA {$emptySchema}");

    // ═════════════════════════════════════════════════════════════════════════
    // PARTE 1 · CONVERGENCIA
    // ═════════════════════════════════════════════════════════════════════════
    echo "\n--- Parte 1: convergencia del esquema desplegado ---\n";

    $migrationFiles = glob($projectRoot . '/database/supabase_migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);
    $assert(
        'El repositorio declara el conjunto de migraciones del motor nuevo, arrancando por 001_initial_baseline.sql',
        $migrationFiles !== [] && basename($migrationFiles[0]) === '001_initial_baseline.sql',
        'ficheros: ' . count($migrationFiles)
    );

    $pgPdo->exec("SET search_path TO {$schema}, public");
    $applyStatements($pgPdo, $projectRoot . '/database/supabase_init.sql');

    $baseOnlyTables = $readTables($pgPdo, $schema, 'pgsql');
    $assert(
        'El DDL base crea las 20 tablas del contrato y ninguna más',
        $sameSet($baseOnlyTables, CONTRACT_TABLES),
        'tablas: ' . count($baseOnlyTables) . ' · ' . $listSample(array_merge(array_diff($baseOnlyTables, CONTRACT_TABLES), array_diff(CONTRACT_TABLES, $baseOnlyTables)))
    );
    $assert(
        'Una base solo con el DDL base NO tiene el libro de migraciones (A-5)',
        !in_array(ADDITION_LEDGER, $baseOnlyTables, true)
    );

    $runner = new PostgresMigrationRunner($pgPdo);
    $baseOnlyReport = $runner->convergenceReport();
    $assert(
        'El escenario «solo DDL base» se detecta como incompleto (no convergido)',
        $baseOnlyReport['converged'] === false && in_array('001_initial_baseline.sql', $baseOnlyReport['pending'], true),
        'pendientes: ' . $listSample($baseOnlyReport['pending'])
    );

    $applied = $runner->runAll();
    $assert(
        'El ejecutor aplica la migración posterior sobre la base ya creada',
        $applied === ['001_initial_baseline.sql'],
        'aplicadas: ' . $listSample($applied)
    );

    $runner->assertConverged();
    $convergedTables = $readTables($pgPdo, $schema, 'pgsql');
    $assert(
        'Tras la migración, el inventario converge al del repositorio (20 tablas + libro A-5)',
        $sameSet($convergedTables, array_merge(CONTRACT_TABLES, [ADDITION_LEDGER])),
        'tablas: ' . count($convergedTables)
    );

    $ledgerRows = $pgPdo->query('SELECT filename, checksum FROM ' . ADDITION_LEDGER)->fetchAll();
    $assert(
        'El libro registra la migración con su huella de 64 caracteres',
        count($ledgerRows) === 1
        && $ledgerRows[0]['filename'] === '001_initial_baseline.sql'
        && strlen((string)$ledgerRows[0]['checksum']) === 64
    );

    $assert('Reejecutar el ejecutor no aplica nada (idempotente)', $runner->runAll() === []);

    // Prueba de mordida: sin DDL base, la guarda del baseline impide registrar convergencia.
    $pgPdo->exec("SET search_path TO {$emptySchema}, public");
    $baselineGuardBit = false;
    try {
        (new PostgresMigrationRunner($pgPdo))->runAll();
    } catch (Throwable $exception) {
        $baselineGuardBit = str_contains($exception->getMessage(), 'Baseline del motor nuevo incompleto');
    }
    $assert('Sin DDL base, el ejecutor muerde: no marca un esquema vacío como convergido', $baselineGuardBit);

    // ═════════════════════════════════════════════════════════════════════════
    // PARTE 2 · CORRESPONDENCIA DECLARADA
    // ═════════════════════════════════════════════════════════════════════════
    echo "\n--- Parte 2: correspondencia declarada entre los dos motores ---\n";

    $pgPdo->exec("SET search_path TO {$schema}, public");

    $mariaTables = $readTables($mariaPdo, $mariaSchema, 'mysql');
    $assert(
        'Las 20 tablas del contrato existen en el motor local y ninguna fuera de él',
        $sameSet($mariaTables, CONTRACT_TABLES),
        'tablas: ' . count($mariaTables) . ' · ' . $listSample(array_merge(array_diff($mariaTables, CONTRACT_TABLES), array_diff(CONTRACT_TABLES, $mariaTables)))
    );

    $pgColumns = $readColumnNames($pgPdo, $schema, 'pgsql');
    $mariaColumns = $readColumnNames($mariaPdo, $mariaSchema, 'mysql');

    $columnMismatches = [];
    foreach (CONTRACT_TABLES as $table) {
        $mariaNames = array_keys($mariaColumns[$table] ?? []);
        $pgNames = array_keys($pgColumns[$table] ?? []);

        // Excepciones declaradas: D-6 (columna derivada del motor local) y A-1/A-2 (claves canónicas).
        if ($table === 'incidents') {
            $mariaNames = array_values(array_diff($mariaNames, [DIFFERENCE_D6_COLUMN]));
        }
        if ($table === 'users') {
            $pgNames = array_values(array_diff($pgNames, [ADDITION_USERS_KEY]));
        }
        if ($table === 'locations') {
            $pgNames = array_values(array_diff($pgNames, [ADDITION_LOCATIONS_KEY]));
        }

        $missingInPostgres = array_values(array_diff($mariaNames, $pgNames));
        $extraInPostgres = array_values(array_diff($pgNames, $mariaNames));
        if ($missingInPostgres !== [] || $extraInPostgres !== []) {
            $columnMismatches[] = sprintf(
                '%s (faltan en postgres: %s · sobran: %s)',
                $table,
                implode(',', $missingInPostgres) ?: '—',
                implode(',', $extraInPostgres) ?: '—'
            );
        }
    }
    $assert(
        'Los nombres de columna corresponden uno a uno en las 20 tablas (con las excepciones declaradas)',
        $columnMismatches === [],
        $listSample($columnMismatches)
    );

    // Mapeo de tipos del §3, aplicado columna a columna sobre el catálogo vivo.
    $pgTypes = $readPostgresTypes($pgPdo, $schema);
    $mariaTypes = $readMariaTypes($mariaPdo, $mariaSchema);
    $pgEnums = $readEnums($pgPdo, $schema, 'pgsql');
    $mariaEnums = $readEnums($mariaPdo, $mariaSchema, 'mysql');

    $typeMismatches = [];
    foreach (CONTRACT_TABLES as $table) {
        foreach ($mariaTypes[$table] ?? [] as $column => $mariaType) {
            if ($table === 'incidents' && $column === DIFFERENCE_D6_COLUMN) {
                continue; // D-6: la columna derivada no cruza.
            }
            if (!isset($pgTypes[$table][$column])) {
                continue; // ya lo señaló la correspondencia de nombres
            }

            $pg = $pgTypes[$table][$column];
            $normalized = strtolower(trim($mariaType));
            $matches = false;
            $expected = '';

            if (str_contains($normalized, 'enum(')) {
                $pgLabels = $pgEnums[$table . '.' . $column] ?? [];
                $mariaLabels = $mariaEnums[$table . '.' . $column] ?? [];
                $matches = $pg['data_type'] === 'USER-DEFINED' && $pgLabels === $mariaLabels;
                $expected = 'ENUM con ' . count($mariaLabels) . ' miembros';
            } elseif (preg_match('/^(tinyint)\(1\)/', $normalized) === 1) {
                $matches = $pg['data_type'] === 'boolean';
                $expected = 'BOOLEAN (D-3)';
            } elseif (preg_match('/^(tinyint)\(3\) unsigned/', $normalized) === 1) {
                $matches = $pg['data_type'] === 'smallint';
                $expected = 'SMALLINT';
            } elseif (preg_match('/^(int|bigint)(\(\d+\))? unsigned/', $normalized) === 1) {
                $matches = $pg['data_type'] === 'bigint';
                $expected = 'BIGINT (D-1)';
            } elseif (preg_match('/^varchar\((\d+)\)/', $normalized, $length) === 1) {
                $matches = $pg['data_type'] === 'character varying' && $pg['length'] === (int)$length[1];
                $expected = 'VARCHAR(' . $length[1] . ')';
            } elseif ($normalized === 'text') {
                $matches = $pg['data_type'] === 'text';
                $expected = 'TEXT';
            } elseif ($normalized === 'longtext') {
                $matches = $pg['data_type'] === 'jsonb';
                $expected = 'JSONB (D-4)';
            } elseif (preg_match('/^decimal\((\d+),(\d+)\)/', $normalized, $numeric) === 1) {
                $matches = $pg['data_type'] === 'numeric'
                    && $pg['precision'] === (int)$numeric[1]
                    && $pg['scale'] === (int)$numeric[2];
                $expected = 'NUMERIC(' . $numeric[1] . ',' . $numeric[2] . ')';
            } elseif ($normalized === 'datetime' || $normalized === 'timestamp') {
                $matches = $pg['data_type'] === 'timestamp with time zone';
                $expected = 'TIMESTAMPTZ (D-2)';
            } elseif ($normalized === 'date') {
                $matches = $pg['data_type'] === 'date';
                $expected = 'DATE';
            } else {
                $expected = 'tipo no cubierto por el §3';
            }

            if (!$matches) {
                $typeMismatches[] = sprintf('%s.%s: %s → %s (esperado %s)', $table, $column, $mariaType, $pg['data_type'], $expected);
            }
        }
    }
    $assert(
        'Cada tipo de PostgreSQL es el que declara el §3 para su columna del motor local',
        $typeMismatches === [],
        $listSample($typeMismatches)
    );

    $pgUniques = $readUniqueSets($pgPdo, $schema, 'pgsql');
    $mariaUniques = $readUniqueSets($mariaPdo, $mariaSchema, 'mysql');

    // D-6: se retiran la forma de cada motor y se certifica el índice parcial declarado.
    $d6MariaColumn = array_search('machine_id,' . DIFFERENCE_D6_COLUMN, $mariaUniques['incidents'] ?? [], true);
    if ($d6MariaColumn === false) {
        $d6MariaColumn = array_search(DIFFERENCE_D6_COLUMN . ',machine_id', $mariaUniques['incidents'] ?? [], true);
    }
    if ($d6MariaColumn !== false) {
        unset($mariaUniques['incidents'][$d6MariaColumn]);
        $mariaUniques['incidents'] = array_values($mariaUniques['incidents']);
    }
    $d6PgIndex = array_search('machine_id', $pgUniques['incidents'] ?? [], true);
    if ($d6PgIndex !== false) {
        unset($pgUniques['incidents'][$d6PgIndex]);
        $pgUniques['incidents'] = array_values($pgUniques['incidents']);
    }

    $partialIndex = $pgPdo->prepare(
        "SELECT pg_get_expr(ix.indpred, ix.indrelid) AS predicate
           FROM pg_index ix
           JOIN pg_class i ON i.oid = ix.indexrelid
           JOIN pg_namespace n ON n.oid = i.relnamespace
          WHERE n.nspname = :schema AND i.relname = :name"
    );
    $partialIndex->execute([':schema' => $schema, ':name' => DIFFERENCE_D6_INDEX]);
    $predicate = (string)($partialIndex->fetchColumn() ?: '');
    $assert(
        'D-6: el índice único parcial de PostgreSQL existe con la condición declarada',
        $d6MariaColumn !== false && $d6PgIndex !== false
        && str_contains($predicate, 'CLOSED') && str_contains($predicate, 'CANCELLED'),
        'predicado: ' . $predicate
    );

    // A-1/A-2: las claves canónicas son únicas en el motor nuevo.
    $usersKeyUnique = in_array(ADDITION_USERS_KEY, $pgUniques['users'] ?? [], true);
    $locationsKeyUnique = in_array(ADDITION_LOCATIONS_KEY, $pgUniques['locations'] ?? [], true);
    $assert('A-1/A-2: las claves canónicas email_key y site_code_key son únicas', $usersKeyUnique && $locationsKeyUnique);

    $uniqueMismatches = [];
    foreach (array_unique(array_merge(array_keys($mariaUniques), array_keys($pgUniques))) as $table) {
        if ($table === ADDITION_LEDGER) {
            continue; // A-5: el libro del ejecutor sólo existe en el motor nuevo.
        }

        $mariaSets = $mariaUniques[$table] ?? [];
        $pgSets = $pgUniques[$table] ?? [];
        if ($table === 'users') {
            $pgSets = array_values(array_diff($pgSets, [ADDITION_USERS_KEY]));
        }
        if ($table === 'locations') {
            $pgSets = array_values(array_diff($pgSets, [ADDITION_LOCATIONS_KEY]));
        }
        sort($mariaSets, SORT_STRING);
        sort($pgSets, SORT_STRING);
        if ($mariaSets !== $pgSets) {
            $uniqueMismatches[] = sprintf('%s (local: %s · nuevo: %s)', $table, implode('|', $mariaSets), implode('|', $pgSets));
        }
    }
    $assert(
        'Las claves primarias y únicas corresponden por columnas indexadas (D-9), sin deriva',
        $uniqueMismatches === [],
        $listSample($uniqueMismatches, 6)
    );

    $pgForeignKeys = $readForeignKeys($pgPdo, $schema, 'pgsql');
    $mariaForeignKeys = $readForeignKeys($mariaPdo, $mariaSchema, 'mysql');
    $foreignMismatches = [];
    foreach (array_unique(array_merge(array_keys($mariaForeignKeys), array_keys($pgForeignKeys))) as $table) {
        $mariaKeys = $mariaForeignKeys[$table] ?? [];
        $pgKeys = $pgForeignKeys[$table] ?? [];
        sort($mariaKeys, SORT_STRING);
        sort($pgKeys, SORT_STRING);
        if ($mariaKeys !== $pgKeys) {
            $foreignMismatches[] = sprintf('%s (faltan: %s · sobran: %s)', $table, $listSample(array_diff($mariaKeys, $pgKeys), 3), $listSample(array_diff($pgKeys, $mariaKeys), 3));
        }
    }
    $assert(
        'Las claves foráneas corresponden con sus reglas ON DELETE / ON UPDATE',
        $foreignMismatches === [],
        $listSample($foreignMismatches, 6)
    );

    $enumMismatches = [];
    foreach ($mariaEnums as $columnKey => $members) {
        if (!isset($pgEnums[$columnKey]) || $pgEnums[$columnKey] !== $members) {
            $enumMismatches[] = $columnKey;
        }
    }
    foreach ($pgEnums as $columnKey => $members) {
        if (!isset($mariaEnums[$columnKey])) {
            $enumMismatches[] = $columnKey . ' (solo en el motor nuevo)';
        }
    }
    $assert(
        'Los 24 conjuntos ENUM coinciden miembro a miembro en los dos motores',
        $enumMismatches === [],
        'conjuntos comparados: ' . count($mariaEnums) . ' · ' . $listSample($enumMismatches, 6)
    );

    // A-3: las restricciones CHECK de longitud mínima existen en el motor nuevo.
    $checkStmt = $pgPdo->prepare(
        "SELECT constraint_name FROM information_schema.table_constraints
          WHERE table_schema = :schema AND constraint_type = 'CHECK' AND constraint_name LIKE 'chk\_%'"
    );
    $checkStmt->execute([':schema' => $schema]);
    $checkNames = array_map(static fn (array $row): string => (string)$row['constraint_name'], $checkStmt->fetchAll());
    $missingChecks = array_values(array_diff(ADDITION_CHECKS, $checkNames));
    $assert(
        'A-3: las siete restricciones CHECK del Art. V.1 están declaradas en la base',
        $missingChecks === [],
        'faltan: ' . $listSample($missingChecks)
    );

    // A-4 y D-8: 20 disparadores BEFORE DELETE en el motor nuevo, 1 en el motor local.
    $triggerStmt = $pgPdo->prepare(
        "SELECT event_object_table AS table_name FROM information_schema.triggers
          WHERE trigger_schema = :schema AND event_manipulation = 'DELETE' AND action_timing = 'BEFORE'"
    );
    $triggerStmt->execute([':schema' => $schema]);
    $pgTriggerTables = array_map(static fn (array $row): string => (string)$row['table_name'], $triggerStmt->fetchAll());
    $assert(
        'A-4: las 20 tablas del dominio tienen su disparador BEFORE DELETE',
        count(array_unique($pgTriggerTables)) === 20 && !in_array(ADDITION_LEDGER, $pgTriggerTables, true),
        'tablas con disparador: ' . count(array_unique($pgTriggerTables))
    );

    $mariaTrigger = (int)$mariaPdo->query(
        "SELECT COUNT(DISTINCT EVENT_OBJECT_TABLE) FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = '" . $mariaSchema . "' AND EVENT_MANIPULATION = 'DELETE'"
    )->fetchColumn();
    $assert(
        'D-8: el motor local declara su único disparador anti-borrado (refund_requests)',
        $mariaTrigger === 1,
        'disparadores: ' . $mariaTrigger
    );

    // Deriva: nada fuera de la correspondencia, las diferencias y las adiciones declaradas.
    $undeclaredPg = array_values(array_diff($convergedTables, CONTRACT_TABLES, [ADDITION_LEDGER]));
    $assert('Sin deriva en el motor nuevo: ninguna tabla que el contrato no declare', $undeclaredPg === [], $listSample($undeclaredPg));
} finally {
    $pgPdo->exec("SET search_path TO public");
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$emptySchema} CASCADE");
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-HYBRID-24 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
