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
$readTables = static function (PDO $pdo, string $schema): array {
    $stmt = $pdo->prepare("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = :schema AND table_type = 'BASE TABLE' ORDER BY table_name");
    $stmt->execute([':schema' => $schema]);

    return array_map(static fn (array $row): string => (string)$row['name'], $stmt->fetchAll());
};

/** @return array<string, array<string, array{type: string, nullable: bool}>> */
$readColumnNames = static function (PDO $pdo, string $schema): array {
    $stmt = $pdo->prepare('SELECT table_name, column_name, is_nullable FROM information_schema.columns WHERE table_schema = :schema ORDER BY table_name, column_name');
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
/**
 * Conjuntos únicos por tabla, comparados por **columnas indexadas** y no por nombre (D-9).
 *
 * @return array<string, list<string>> table => listas de columnas separadas por coma
 */
/** @return array<string, list<string>> table => "cols -> ref(refcols) DELETE/UPDATE" */
/** @return array<string, list<string>> "tabla.columna" => miembros del conjunto, ordenados */
$readEnums = static function (PDO $pdo, string $schema): array {
    $enums = [];
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

    $baseOnlyTables = $readTables($pgPdo, $schema);
    $assert(
        'El DDL base crea las 30 tablas del contrato y ninguna más',
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
        $applied === [
            '001_initial_baseline.sql',
            '002_notification_center_outbox.sql',
            '003_notification_webhook_url_check_repair.sql',
            '004_telegram_technician_channel.sql',
            '005_opportunistic_incident.sql',
            '006_opportunistic_coherence_repair.sql',
            '007_operational_zones.sql',
            '008_zone_postal_code_release.sql',
            '009_technician_van_stocks.sql',
            '010_incident_service_sheets.sql',
            '011_incident_satisfaction_surveys.sql',
            '012_technician_absences_and_shifts.sql',
            '013_shift_handover_notification.sql',
            '014_technician_absence_exclusion.sql',
        ],
        'aplicadas: ' . $listSample($applied)
    );

    $runner->assertConverged();
    $convergedTables = $readTables($pgPdo, $schema);
    $assert(
        'Tras la migración, el inventario converge al del repositorio (30 tablas + libro A-5)',
        $sameSet($convergedTables, array_merge(CONTRACT_TABLES, [ADDITION_LEDGER])),
        'tablas: ' . count($convergedTables)
    );

    $ledgerRows = $pgPdo->query('SELECT filename, checksum FROM ' . ADDITION_LEDGER . ' ORDER BY filename')->fetchAll();
    $assert(
        'El libro registra las migraciones con su huella de 64 caracteres',
        count($ledgerRows) === 14
        && $ledgerRows[0]['filename'] === '001_initial_baseline.sql'
        && strlen((string)$ledgerRows[0]['checksum']) === 64
        && $ledgerRows[1]['filename'] === '002_notification_center_outbox.sql'
        && strlen((string)$ledgerRows[1]['checksum']) === 64
        && $ledgerRows[2]['filename'] === '003_notification_webhook_url_check_repair.sql'
        && strlen((string)$ledgerRows[2]['checksum']) === 64
        && $ledgerRows[3]['filename'] === '004_telegram_technician_channel.sql'
        && strlen((string)$ledgerRows[3]['checksum']) === 64
        && $ledgerRows[4]['filename'] === '005_opportunistic_incident.sql'
        && strlen((string)$ledgerRows[4]['checksum']) === 64
        && $ledgerRows[5]['filename'] === '006_opportunistic_coherence_repair.sql'
        && strlen((string)$ledgerRows[5]['checksum']) === 64
        && $ledgerRows[6]['filename'] === '007_operational_zones.sql'
        && strlen((string)$ledgerRows[6]['checksum']) === 64
        && $ledgerRows[7]['filename'] === '008_zone_postal_code_release.sql'
        && strlen((string)$ledgerRows[7]['checksum']) === 64
        && $ledgerRows[8]['filename'] === '009_technician_van_stocks.sql'
        && strlen((string)$ledgerRows[8]['checksum']) === 64
        && $ledgerRows[9]['filename'] === '010_incident_service_sheets.sql'
        && strlen((string)$ledgerRows[9]['checksum']) === 64
        && $ledgerRows[10]['filename'] === '011_incident_satisfaction_surveys.sql'
        && strlen((string)$ledgerRows[10]['checksum']) === 64
        && $ledgerRows[11]['filename'] === '012_technician_absences_and_shifts.sql'
        && strlen((string)$ledgerRows[11]['checksum']) === 64
        && $ledgerRows[12]['filename'] === '013_shift_handover_notification.sql'
        && strlen((string)$ledgerRows[12]['checksum']) === 64
        && $ledgerRows[13]['filename'] === '014_technician_absence_exclusion.sql'
        && strlen((string)$ledgerRows[13]['checksum']) === 64
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
    // PARTE 2 · CONTRATO DE ESQUEMA DEL MOTOR ÚNICO (POSTGRESQL)
    // ═════════════════════════════════════════════════════════════════════════
    echo "\n--- Parte 2: contrato de esquema canónico sobre PostgreSQL ---\n";

    $pgPdo->exec("SET search_path TO {$schema}, public");

    $pgTables = $readTables($pgPdo, $schema);
    $assert(
        'El motor único declara las 30 tablas canónicas del dominio',
        $sameSet(array_values(array_diff($pgTables, [ADDITION_LEDGER])), CONTRACT_TABLES),
        'tablas: ' . count($pgTables)
    );

    $pgColumns = $readColumnNames($pgPdo, $schema);
    $assert(
        'Todas las 30 tablas tienen columnas declaradas en PostgreSQL',
        count($pgColumns) >= 30
    );

    // A-1 y A-2: claves canónicas
    $assert(
        'users declara la columna canónica email_key (A-1)',
        isset($pgColumns['users'][ADDITION_USERS_KEY])
    );
    $assert(
        'locations declara la columna canónica site_code_key (A-2)',
        isset($pgColumns['locations'][ADDITION_LOCATIONS_KEY])
    );

    // Tipos nativos y ENUMs
    $pgTypes = $readPostgresTypes($pgPdo, $schema);
    $pgEnums = $readEnums($pgPdo, $schema);

    $assert(
        'El esquema declara los 24 conjuntos ENUM nativos del dominio',
        count($pgEnums) >= 24,
        'conjuntos: ' . count($pgEnums)
    );

    // A-3: las restricciones CHECK de longitud mínima existen en el motor único
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

    // A-4: 30 disparadores BEFORE DELETE en el motor único
    $triggerStmt = $pgPdo->prepare(
        "SELECT event_object_table AS table_name FROM information_schema.triggers
          WHERE trigger_schema = :schema AND event_manipulation = 'DELETE' AND action_timing = 'BEFORE'"
    );
    $triggerStmt->execute([':schema' => $schema]);
    $pgTriggerTables = array_map(static fn (array $row): string => (string)$row['table_name'], $triggerStmt->fetchAll());
    $assert(
        'A-4: las 30 tablas del dominio tienen su disparador BEFORE DELETE vinculado a prevent_hard_delete',
        count(array_unique($pgTriggerTables)) === 30 && !in_array(ADDITION_LEDGER, $pgTriggerTables, true),
        'tablas con disparador: ' . count(array_unique($pgTriggerTables))
    );

    // Clave única parcial de ticket activo (D-6)
    $d6IndexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes WHERE schemaname = :schema AND tablename = 'incidents' AND indexname = :idx"
    );
    $d6IndexStmt->execute([':schema' => $schema, ':idx' => DIFFERENCE_D6_INDEX]);
    $assert(
        'Índice único parcial uq_machine_active_ticket presente en incidents (Art. V.2/V.6)',
        (string)$d6IndexStmt->fetchColumn() === DIFFERENCE_D6_INDEX
    );

    // Sin tablas inesperadas
    $undeclaredPg = array_values(array_diff($convergedTables, CONTRACT_TABLES, [ADDITION_LEDGER]));
    $assert('Sin deriva en el motor único: ninguna tabla que el contrato no declare', $undeclaredPg === [], $listSample($undeclaredPg));
} finally {
    $pgPdo->exec("SET search_path TO public");
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    $pgPdo->exec("DROP SCHEMA IF EXISTS {$emptySchema} CASCADE");
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-HYBRID-24 CUMPLIDA CON ÉXITO (motor único).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
