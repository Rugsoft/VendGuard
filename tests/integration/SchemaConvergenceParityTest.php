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

/** Las 46 tablas del contrato de correspondencia (§2.1, Módulos 14 a 33). */
const CONTRACT_TABLES = [
    'locations', 'machines', 'users', 'incidents', 'incident_history', 'incident_comments',
    'incident_replaced_parts', 'refund_requests', 'unclaimed_cash_findings', 'spare_parts',
    'spare_part_compatibilities', 'spare_part_requests', 'preventive_settings', 'preventive_orders',
    'preventive_order_items', 'sanitary_certificates', 'route_settings', 'sanitary_sweep_passes',
    'sanitary_sweep_contentions', 'audit_log',
    'notification_outbox', 'notification_channel_settings',
    'operational_zones', 'operational_zone_postal_codes', 'technician_zone_assignments',
    'technician_van_stocks', 'incident_service_sheets', 'incident_satisfaction_surveys',
    'technician_absences', 'technician_shifts', 'technician_shift_logs',
    'van_restock_orders', 'van_restock_order_items',
    'offline_sync_mutations',
    // Módulo 25 · taxonomía de causas raíz y soluciones estandarizadas.
    'root_causes', 'standard_solutions',
    // Módulo 26 · cadena de custodia y reasignaciones.
    'incident_assignments',
    // Módulo 27 · bandeja de taller central de piezas recuperadas.
    'workshop_repair_items',
    // Módulo 28 · guías rápidas de diagnóstico por modelo.
    'machine_troubleshooting_guides',
    // Módulo 29 · acta de merma y destrucción de lotes por cuarentena sanitaria.
    'sanitary_spoilage_records',
    // Módulo 31 · cuadrante de carga de los reponedores de ruta.
    'restocker_zone_assignments',
    // Módulo 32 · catálogo comercial y matriz de planograma por máquina.
    'vending_products',
    'machine_planogram_slots',
    // Módulo 33 · stock de producto por lote en furgoneta, orden de carga y
    // sus líneas con el cuadre de liquidación al cierre.
    'restocker_van_product_stocks',
    'product_loading_orders',
    'product_loading_order_items',
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
        'El DDL base crea las 46 tablas del contrato y ninguna más',
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
            '015_technician_time_tracking.sql',
            '016_van_restock_orders.sql',
            '017_van_restock_reception_note.sql',
            '018_spare_part_requests_pending_index.sql',
            '019_van_restock_order_items_snapshots.sql',
            '020_offline_pwa_sync.sql',
            '021_root_cause_taxonomy.sql',
            '022_incident_assignments_custody_chain.sql',
            '023_workshop_repair_items.sql',
            '024_machine_troubleshooting_guides.sql',
            '025_sanitary_spoilage_records.sql',
            '026_tiered_sla_contracts.sql',
            '027_restocker_role_and_zone_assignments.sql',
            '028_product_catalog_and_planogram_slots.sql',
            '029_product_loading_orders_and_cold_picking.sql',
        ],
        'aplicadas: ' . $listSample($applied)
    );

    $runner->assertConverged();
    $convergedTables = $readTables($pgPdo, $schema);
    $assert(
        'Tras la migración, el inventario converge al del repositorio (46 tablas + libro A-5)',
        $sameSet($convergedTables, array_merge(CONTRACT_TABLES, [ADDITION_LEDGER])),
        'tablas: ' . count($convergedTables)
    );

    $ledgerRows = $pgPdo->query('SELECT filename, checksum FROM ' . ADDITION_LEDGER . ' ORDER BY filename')->fetchAll();
    $expectedLedgerFiles = [
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
        '015_technician_time_tracking.sql',
        '016_van_restock_orders.sql',
        '017_van_restock_reception_note.sql',
        '018_spare_part_requests_pending_index.sql',
        '019_van_restock_order_items_snapshots.sql',
        '020_offline_pwa_sync.sql',
        '021_root_cause_taxonomy.sql',
        '022_incident_assignments_custody_chain.sql',
        '023_workshop_repair_items.sql',
        '024_machine_troubleshooting_guides.sql',
        '025_sanitary_spoilage_records.sql',
        '026_tiered_sla_contracts.sql',
        '027_restocker_role_and_zone_assignments.sql',
        '028_product_catalog_and_planogram_slots.sql',
        '029_product_loading_orders_and_cold_picking.sql',
    ];
    $ledgerFiles = array_map(static fn (array $row): string => (string)$row['filename'], $ledgerRows);
    $ledgerChecksumsOk = true;
    foreach ($ledgerRows as $ledgerRow) {
        $ledgerChecksumsOk = $ledgerChecksumsOk && strlen((string)$ledgerRow['checksum']) === 64;
    }
    $assert(
        'El libro registra las 29 migraciones, en orden, con su huella de 64 caracteres',
        $ledgerFiles === $expectedLedgerFiles && $ledgerChecksumsOk,
        'registradas: ' . count($ledgerRows)
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
        'El motor único declara las 46 tablas canónicas del dominio',
        $sameSet(array_values(array_diff($pgTables, [ADDITION_LEDGER])), CONTRACT_TABLES),
        'tablas: ' . count($pgTables)
    );

    $pgColumns = $readColumnNames($pgPdo, $schema);
    $assert(
        'Todas las 46 tablas tienen columnas declaradas en PostgreSQL',
        count($pgColumns) >= 46
    );
    $assert(
        'incidents declara las 52 columnas del contrato (43 + 7 de taxonomía + 2 de SLA)',
        count($pgColumns['incidents'] ?? []) === 52,
        'columnas: ' . count($pgColumns['incidents'] ?? [])
    );
    $assert(
        'root_causes declara sus 8 columnas canónicas',
        count($pgColumns['root_causes'] ?? []) === 8,
        'columnas: ' . count($pgColumns['root_causes'] ?? [])
    );
    $assert(
        'standard_solutions declara sus 8 columnas canónicas',
        count($pgColumns['standard_solutions'] ?? []) === 8,
        'columnas: ' . count($pgColumns['standard_solutions'] ?? [])
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

    $assert(
        'sanitary_spoilage_records declara sus 22 columnas canónicas',
        count($pgColumns['sanitary_spoilage_records'] ?? []) === 22,
        'columnas: ' . count($pgColumns['sanitary_spoilage_records'] ?? [])
    );
    $assert(
        'machine_troubleshooting_guides declara sus 14 columnas canónicas',
        count($pgColumns['machine_troubleshooting_guides'] ?? []) === 14,
        'columnas: ' . count($pgColumns['machine_troubleshooting_guides'] ?? [])
    );
    $assert(
        'workshop_repair_items declara sus 34 columnas canónicas',
        count($pgColumns['workshop_repair_items'] ?? []) === 34,
        'columnas: ' . count($pgColumns['workshop_repair_items'] ?? [])
    );
    $assert(
        'incident_assignments declara sus 15 columnas canónicas',
        count($pgColumns['incident_assignments'] ?? []) === 15,
        'columnas: ' . count($pgColumns['incident_assignments'] ?? [])
    );

    // Tipos nativos y ENUMs
    $pgTypes = $readPostgresTypes($pgPdo, $schema);
    $pgEnums = $readEnums($pgPdo, $schema);

    $assert(
        'incident_assignments declara los 9 canales de apertura y las 10 causas de cierre',
        ($pgEnums['incident_assignments.assignment_source'] ?? []) === [
            'COORDINATOR_ASSIGN', 'COORDINATOR_REASSIGN', 'ABSENCE_COVERAGE_REASSIGN',
            'OPPORTUNISTIC_NEW', 'OPPORTUNISTIC_ASSUMED', 'OPPORTUNISTIC_TAKEOVER',
            'OPPORTUNISTIC_WARRANTY_REOPEN', 'SHIFT_HANDOVER', 'SANITARY_FAILSAFE_GUARD',
        ]
        && ($pgEnums['incident_assignments.unassignment_reason_type'] ?? []) === [
            'REASSIGNED_BY_COORDINATOR', 'ABSENCE_COVERAGE_REASSIGN', 'TAKEN_OVER_IN_SITU',
            'SHIFT_HANDOVER', 'SANITARY_FAILSAFE_GUARD', 'SANITARY_FAILSAFE_UNASSIGNED',
            'RESOLVED', 'CHRONIC_REPLACEMENT_CLOSED', 'WARRANTY_REOPENED', 'CANCELLED',
        ],
        sprintf(
            'canales: %d · causas: %d',
            count($pgEnums['incident_assignments.assignment_source'] ?? []),
            count($pgEnums['incident_assignments.unassignment_reason_type'] ?? [])
        )
    );

    $assert(
        'El ciclo de taller declara sus cinco estados y sus dos destinos de reintegro',
        ($pgEnums['workshop_repair_items.status'] ?? []) === [
            'PENDING_DELIVERY',
            'RECEIVED_IN_WORKSHOP',
            'IN_BENCH_TEST',
            'REFURBISHED_RESTORED',
            'SCRAPPED_IRREPARABLE',
        ]
        && ($pgEnums['workshop_repair_items.restock_destination'] ?? []) === ['CENTRAL_WAREHOUSE', 'TECHNICIAN_VAN']
    );
    $assert(
        'El ciclo de carga de furgoneta declara sus cinco estados, sus dos modos de demanda y sus cuatro motivos de línea (Módulo 33)',
        ($pgEnums['product_loading_orders.status'] ?? []) === [
            'PENDING_PICKING',
            'DISPATCHED',
            'CONFIRMED_BY_RESTOCKER',
            'RECONCILED_AT_CLOSE',
            'CANCELLED',
        ]
        && ($pgEnums['product_loading_orders.demand_mode'] ?? []) === ['ALERTS_ONLY', 'ALL_GAPS']
        && ($pgEnums['product_loading_order_items.reason'] ?? []) === [
            'STOCKOUT_ALERT',
            'LOW_STOCK_ALERT',
            'ROUTE_GAP_FILL',
            'MANUAL_ADDITION',
        ]
    );
    $assert(
        'El esquema declara los 46 conjuntos ENUM nativos del dominio (40 tipos)',
        count($pgEnums) === 46,
        'conjuntos: ' . count($pgEnums)
    );

    $enumTypesStmt = $pgPdo->prepare(
        "SELECT COUNT(DISTINCT t.oid) AS total
           FROM pg_type t
           JOIN pg_namespace n ON n.oid = t.typnamespace
           JOIN pg_enum e ON e.enumtypid = t.oid
          WHERE n.nspname = :schema"
    );
    $enumTypesStmt->execute([':schema' => $schema]);
    $enumTypeCount = (int)$enumTypesStmt->fetchColumn();
    $assert(
        'El esquema declara los 40 tipos ENUM nativos distintos del contrato',
        $enumTypeCount === 40,
        'tipos: ' . $enumTypeCount
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

    // A-4: 34 disparadores BEFORE DELETE en el motor único
    $triggerStmt = $pgPdo->prepare(
        "SELECT event_object_table AS table_name FROM information_schema.triggers
          WHERE trigger_schema = :schema AND event_manipulation = 'DELETE' AND action_timing = 'BEFORE'"
    );
    $triggerStmt->execute([':schema' => $schema]);
    $pgTriggerTables = array_map(static fn (array $row): string => (string)$row['table_name'], $triggerStmt->fetchAll());
    $assert(
        'A-4: las 46 tablas del dominio tienen su disparador BEFORE DELETE vinculado a prevent_hard_delete',
        count(array_unique($pgTriggerTables)) === 46 && !in_array(ADDITION_LEDGER, $pgTriggerTables, true),
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

    // Módulo 30: el índice de vencimiento y el disparador de inmutabilidad del
    // perfil sellado forman parte del contrato convergente en el motor único.
    $slaIndexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes WHERE schemaname = :schema AND tablename = 'incidents' AND indexname = :idx"
    );
    $slaIndexStmt->execute([':schema' => $schema, ':idx' => 'idx_incidents_sla_target_lookup']);
    $assert(
        'Índice idx_incidents_sla_target_lookup presente en incidents (Módulo 30)',
        (string)$slaIndexStmt->fetchColumn() === 'idx_incidents_sla_target_lookup'
    );

    $slaTriggerStmt = $pgPdo->prepare(
        "SELECT trigger_name FROM information_schema.triggers
          WHERE trigger_schema = :schema AND event_object_table = 'incidents'
            AND trigger_name = 'trg_incidents_protect_sla_tier_snapshot'"
    );
    $slaTriggerStmt->execute([':schema' => $schema]);
    $assert(
        'Disparador trg_incidents_protect_sla_tier_snapshot vinculado a incidents (Módulo 30)',
        (string)$slaTriggerStmt->fetchColumn() === 'trg_incidents_protect_sla_tier_snapshot'
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
