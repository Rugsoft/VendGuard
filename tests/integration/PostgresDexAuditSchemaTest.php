<?php

declare(strict_types=1);

/**
 * PostgresDexAuditSchemaTest · T-DEX-02
 *
 * Certifica, sobre el **motor nuevo** y el esquema del repositorio, el contrato inmutable del
 * Módulo 39: los cinco tipos `ENUM` #51–#55, las Tablas 52–54 (`machine_dex_audits`,
 * `machine_dex_selection_lines` y `machine_dex_event_lines`), sus índices únicos parciales y las
 * guardas que el motor impone por sí mismo.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Catálogo nativo:** los cinco enumerados existen con sus etiquetas en el orden declarado,
 *      y `audit_entity_type` / `notification_template` incorporan `MACHINE_DEX_AUDIT` y
 *      `DEX_THERMAL_EVENT_ALERT`. Un catálogo corto rompería el visor y el outbox sin que el DDL
 *      fallara.
 *
 *   2. **Índices y disparadores:** los cuatro índices únicos parciales del contrato (hash vigente,
 *      idempotencia del móvil, visita y recaudación), los tres de servicio, las tres guardas de
 *      borrado físico (Art. III.1), la inmutabilidad de las líneas y las dos guardas de la
 *      cabecera (Art. III.3).
 *
 *   3. **Guardas medidas en el motor, no descritas:** un `PA101` repetido choca con su índice
 *      único (`23505`), un contador negativo o un evento térmico incoherente violan su `CHECK`
 *      (`23514`), la reescritura de la captura y de las líneas se rechaza con `45000`, el enlace
 *      de una operación solo ocurre una vez, la anulación exige motivo real (Art. V.1) y es
 *      terminal, y la reimportación del mismo fichero solo es posible tras la anulación lógica
 *      (QA-4).
 *
 *   4. **Primacía sanitaria (Art. II.2 / QA-1):** el Reponedor queda bloqueado con `45000` sobre
 *      una máquina en `QUARANTINE` o con una avería `TEMPERATURE_COLD` abierta, mientras el
 *      Técnico SAT y Coordinación conservan el acceso forense.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`) y se ejecuta en su canal
 *   propio. Sin `PG_TEST_DSN` la suite lo dice y sale con código 2, sin omitirse en silencio.
 *
 * LIMPIEZA: el escenario vive en su propio esquema aislado y se borra con `CASCADE` al terminar,
 * incluso si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresDexAuditSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

const DEX_TABLES = ['machine_dex_audits', 'machine_dex_selection_lines', 'machine_dex_event_lines'];

const DEX_ENUMS = [
    'dex_audit_status' => ['ACTIVE', 'VOIDED'],
    'dex_import_source' => ['RESTOCKER_ROUTE', 'TECHNICIAN_ROUTE', 'COORDINATOR_CONSOLE'],
    'dex_selection_match_status' => ['EXACT_MATCH', 'LEADING_ZERO_MATCH', 'AMBIGUOUS', 'UNMAPPED', 'HOT_DRINKS_SELECTION'],
    'dex_event_category' => ['THERMAL_REFRIGERATION', 'PAYMENT_MDB', 'MECHANICAL_VEND', 'POWER_CONTROLLER', 'OTHER'],
    'dex_event_severity' => ['CRITICAL', 'WARNING', 'INFO'],
];

const DEX_UNIQUE_INDEXES = [
    'uq_machine_dex_audits_machine_hash',
    'uq_machine_dex_audits_actor_mutation',
    'uq_machine_dex_audits_visit',
    'uq_machine_dex_audits_cash_collection',
];

const DEX_TRIGGERS = [
    'trg_no_delete_machine_dex_audits',
    'trg_no_delete_machine_dex_selection_lines',
    'trg_no_delete_machine_dex_event_lines',
    'trg_touch_machine_dex_audits',
    'trg_immutable_machine_dex_selection_lines',
    'trg_immutable_machine_dex_event_lines',
    'trg_guard_machine_dex_audits',
    'trg_enforce_dex_audit_sanitary_guard',
];

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
echo " VENDGUARD · T-DEX-02 · Contrato inmutable del volcado EVA-DTS (.dex)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$enumLabels = static function (PDO $pdo, string $type): array {
    $stmt = $pdo->prepare(
        'SELECT e.enumlabel
           FROM pg_enum e
           JOIN pg_type t ON t.oid = e.enumtypid
          WHERE t.typname = :type
          ORDER BY e.enumsortorder'
    );
    $stmt->execute([':type' => $type]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

$rows = [];
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
        'El esquema del repositorio compila y converge con el libro de migraciones',
        $schemaReport['statements'] > 100
            && in_array('035_eva_dts_dex_audit.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true
            && $convergence['pending'] === [],
        sprintf('%d sentencias · %d migraciones · pendientes: %s',
            $schemaReport['statements'],
            count($schemaReport['migrations']),
            json_encode($convergence['pending']))
    );

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    $missingTables = array_values(array_diff(DEX_TABLES, $tables));
    $assert(
        'Las tres tablas del volcado existen en el esquema certificado',
        $missingTables === [],
        $missingTables === [] ? count($tables) . ' tablas de dominio' : 'ausentes: ' . implode(', ', $missingTables)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Catálogo nativo: los cinco ENUM y los dos valores añadidos
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos ENUM #51–#55 y catálogos ampliados ---\n";

    foreach (DEX_ENUMS as $type => $expected) {
        $actual = $enumLabels($pgPdo, $type);
        $assert(
            "El tipo «{$type}» declara sus etiquetas en el orden del contrato",
            $actual === $expected,
            'leídas: ' . implode(', ', $actual)
        );
    }

    $entityLabels = $enumLabels($pgPdo, 'audit_entity_type');
    $assert(
        '`audit_entity_type` incorpora `MACHINE_DEX_AUDIT`',
        in_array('MACHINE_DEX_AUDIT', $entityLabels, true),
        count($entityLabels) . ' etiquetas'
    );

    $templateLabels = $enumLabels($pgPdo, 'notification_template');
    $assert(
        '`notification_template` incorpora `DEX_THERMAL_EVENT_ALERT`',
        in_array('DEX_THERMAL_EVENT_ALERT', $templateLabels, true),
        count($templateLabels) . ' etiquetas'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Índices únicos parciales y disparadores constitucionales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Índices y disparadores del contrato ---\n";

    $indexStmt = $pgPdo->query(
        "SELECT indexname, indexdef
           FROM pg_indexes
          WHERE schemaname = current_schema()
            AND tablename IN ('machine_dex_audits', 'machine_dex_selection_lines', 'machine_dex_event_lines')"
    );
    $indexes = [];
    foreach ($indexStmt->fetchAll() as $index) {
        $indexes[$index['indexname']] = $index['indexdef'];
    }

    $missingUnique = array_values(array_filter(
        DEX_UNIQUE_INDEXES,
        static fn (string $name): bool => !isset($indexes[$name])
            || !str_contains($indexes[$name], 'UNIQUE INDEX')
            || !str_contains($indexes[$name], 'WHERE')
    ));
    $assert(
        'Los cuatro índices únicos parciales del volcado existen y filtran por estado o vínculo',
        $missingUnique === [],
        $missingUnique === [] ? implode(', ', DEX_UNIQUE_INDEXES) : 'incorrectos: ' . implode(', ', $missingUnique)
    );

    $expectedIndexNames = [
        ...DEX_UNIQUE_INDEXES,
        'idx_machine_dex_audits_machine_captured',
        'idx_machine_dex_audits_status_captured',
        'idx_machine_dex_audits_imported_by_captured',
        'idx_machine_dex_selection_lines_audit',
        'idx_machine_dex_selection_lines_slot',
        'idx_machine_dex_event_lines_audit',
        'idx_machine_dex_event_lines_category',
    ];
    $missingIndexes = array_values(array_diff($expectedIndexNames, array_keys($indexes)));
    $assert(
        'Los once índices del módulo están declarados sobre las tres tablas',
        $missingIndexes === [],
        $missingIndexes === [] ? count($indexes) . ' índices' : 'ausentes: ' . implode(', ', $missingIndexes)
    );

    $triggerStmt = $pgPdo->query(
        "SELECT t.tgname
           FROM pg_trigger t
           JOIN pg_class c ON c.oid = t.tgrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE NOT t.tgisinternal
            AND n.nspname = current_schema()
            AND c.relname IN ('machine_dex_audits', 'machine_dex_selection_lines', 'machine_dex_event_lines')"
    );
    $triggers = $triggerStmt->fetchAll(PDO::FETCH_COLUMN);
    $missingTriggers = array_values(array_diff(DEX_TRIGGERS, $triggers));
    $assert(
        'Las tres guardas de borrado, las dos de inmutabilidad y las dos de la cabecera están instaladas',
        $missingTriggers === [],
        $missingTriggers === [] ? count($triggers) . ' disparadores' : 'ausentes: ' . implode(', ', $missingTriggers)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Padres mínimos para ejercitar las guardas reales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario de datos mínimo ---\n";

    foreach (['locations', 'users', 'machines', 'vending_products', 'machine_planogram_slots', 'machine_restock_visits'] as $index => $table) {
        $rows[$table] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, $table, $rows, sprintf('%02d', $index + 1));
    }

    $location = $rows['locations'];
    $importer = $rows['users'];
    $machine = $rows['machines'];
    $slot = $rows['machine_planogram_slots'];
    $visit = $rows['machine_restock_visits'];

    $insertAudit = static function (array $overrides, ?array $targetMachine = null) use ($pgPdo, $machine, $importer): array {
        $target = $targetMachine ?? $machine;
        $values = $overrides + [
            'audit_code' => 'DEX-2026-7001',
            'machine_code_snapshot' => $target['code'],
            'machine_type_snapshot' => $target['machine_type'],
            'importer_role_snapshot' => 'COORDINATOR',
            'importer_name_snapshot' => 'Coordinación de Pruebas',
            'import_source' => 'COORDINATOR_CONSOLE',
            'original_filename' => 'volcado.dex',
            'file_size_bytes' => 512,
            'sha256_hash' => str_repeat('a', 64),
            'raw_payload' => "DXS*0001*VA*D\nVA1*1*2550*41\nDXE*0001\n",
            'va1_total_sales_eur' => '25.50',
            'va1_total_vend_units' => 41,
            'delta_source' => 'INITIAL_BASELINE',
            'captured_at' => '2026-11-20 09:00:00+00',
        ];

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = 'INSERT INTO machine_dex_audits (machine_id, imported_by, '
            . implode(', ', $columns)
            . ') VALUES (:machine_id, :imported_by, '
            . implode(', ', $placeholders)
            . ') RETURNING *';

        $stmt = $pgPdo->prepare($sql);
        $params = [':machine_id' => $target['id'], ':imported_by' => $importer['id']];
        foreach ($values as $column => $value) {
            $params[':' . $column] = $value;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió el volcado insertado.');
        }

        return $row;
    };

    $audit = $insertAudit([]);
    $assert(
        'El alta mínima del volcado nace `ACTIVE`, sin desfase de sincronización y con delta de línea base',
        $audit['status'] === 'ACTIVE'
            && $audit['out_of_order_sync'] === false
            && $audit['delta_source'] === 'INITIAL_BASELINE',
        sprintf('estado=%s · out_of_order_sync=%s', $audit['status'], json_encode($audit['out_of_order_sync']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // QA-4 / D3: unicidad del fichero vigente y del código legible
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- QA-4 / D3: unicidad del volcado vigente ---\n";

    $duplicateHash = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertAudit(['audit_code' => 'DEX-2026-7002'])
    );
    $assert(
        'Un segundo volcado `ACTIVE` con la misma huella y máquina se rechaza por su índice único',
        $duplicateHash !== null && $duplicateHash['state'] === '23505',
        $duplicateHash === null ? 'el duplicado pasó' : 'estado=' . $duplicateHash['state']
    );

    $duplicateCode = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertAudit(['audit_code' => 'DEX-2026-7001', 'sha256_hash' => str_repeat('c', 64)])
    );
    $assert(
        'El código legible `DEX-AAAA-NNNN` es único en todo el libro',
        $duplicateCode !== null && $duplicateCode['state'] === '23505',
        $duplicateCode === null ? 'el código repetido pasó' : 'estado=' . $duplicateCode['state']
    );

    $badFormat = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertAudit(['audit_code' => 'DEX-26-1', 'sha256_hash' => str_repeat('d', 64)])
    );
    $assert(
        'Un código fuera del formato `DEX-AAAA-NNNN` viola su `CHECK`',
        $badFormat !== null && $badFormat['state'] === '23514',
        $badFormat === null ? 'el formato inválido pasó' : 'estado=' . $badFormat['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Tabla 53: selecciones `PA1`/`PA2`
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tabla 53: selecciones `PA1`/`PA2` inmutables ---\n";

    $insertSelection = static function (array $overrides) use ($pgPdo, $audit, $slot): array {
        $values = $overrides + [
            'line_sequence' => 1,
            'raw_selection_code' => 'PA101',
            'normalized_selection_code' => 'PA101',
            'matched_slot_id' => $slot['id'],
            'matched_slot_code_snapshot' => $slot['slot_code'],
            'match_status' => 'EXACT_MATCH',
            'unit_price_eur' => '1.50',
            'total_vend_units' => 10,
            'total_sales_eur' => '15.00',
        ];

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = 'INSERT INTO machine_dex_selection_lines (dex_audit_id, '
            . implode(', ', $columns)
            . ') VALUES (:dex_audit_id, '
            . implode(', ', $placeholders)
            . ') RETURNING *';

        $stmt = $pgPdo->prepare($sql);
        $params = [':dex_audit_id' => $audit['id']];
        foreach ($values as $column => $value) {
            $params[':' . $column] = $value;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió la selección insertada.');
        }

        return $row;
    };

    $selection = $insertSelection([]);
    $assert(
        'Una selección `EXACT_MATCH` se persiste con su carril y su snapshot de código',
        $selection['match_status'] === 'EXACT_MATCH'
            && (int)$selection['matched_slot_id'] === (int)$slot['id']
            && $selection['matched_slot_code_snapshot'] === $slot['slot_code'],
        sprintf('carril=%s · snapshot=%s', $selection['matched_slot_id'], $selection['matched_slot_code_snapshot'])
    );

    $duplicateSelection = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertSelection(['line_sequence' => 2])
    );
    $assert(
        'Un `PA101` repetido dentro del mismo `.dex` se rechaza con `23505` (QA-4)',
        $duplicateSelection !== null && $duplicateSelection['state'] === '23505',
        $duplicateSelection === null ? 'el código repetido pasó' : 'estado=' . $duplicateSelection['state']
    );

    $ambiguousWithSlot = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertSelection([
            'line_sequence' => 3,
            'normalized_selection_code' => 'PA103',
            'match_status' => 'AMBIGUOUS',
        ])
    );
    $assert(
        'Un emparejamiento ambiguo no puede inventar carril ni snapshot',
        $ambiguousWithSlot !== null && $ambiguousWithSlot['state'] === '23514',
        $ambiguousWithSlot === null ? 'la fila incoherente pasó' : 'estado=' . $ambiguousWithSlot['state']
    );

    $negativeSales = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertSelection([
            'line_sequence' => 4,
            'normalized_selection_code' => 'PA104',
            'total_sales_eur' => '-0.01',
        ])
    );
    $assert(
        'Un importe de venta negativo viola el `CHECK` no negativo de la línea',
        $negativeSales !== null && $negativeSales['state'] === '23514',
        $negativeSales === null ? 'el importe negativo pasó' : 'estado=' . $negativeSales['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Tabla 54: eventos `EA1`/`EA2` y aviso térmico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tabla 54: eventos `EA1`/`EA2` y coherencia térmica ---\n";

    $insertEvent = static function (array $overrides) use ($pgPdo, $audit): array {
        $values = $overrides + [
            'line_sequence' => 1,
            'source_block' => 'EA1',
            'event_code' => 'EG01',
            'event_category' => 'THERMAL_REFRIGERATION',
            'severity' => 'CRITICAL',
            'occurrence_count' => 2,
            'count_since_reset' => 1,
            'count_since_init' => 1,
            'is_active_flag' => true,
            'description_es' => 'Aviso térmico de prueba',
            'is_thermal_alert' => true,
        ];

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = 'INSERT INTO machine_dex_event_lines (dex_audit_id, '
            . implode(', ', $columns)
            . ') VALUES (:dex_audit_id, '
            . implode(', ', $placeholders)
            . ') RETURNING *';

        $stmt = $pgPdo->prepare($sql);
        $params = [':dex_audit_id' => $audit['id']];
        foreach ($values as $column => $value) {
            $params[':' . $column] = $value;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió el evento insertado.');
        }

        return $row;
    };

    $event = $insertEvent([]);
    $assert(
        'Un evento `EG01` se persiste con sus contadores `EA202`/`EA203`, la bandera `EA205` y el aviso térmico',
        $event['event_category'] === 'THERMAL_REFRIGERATION'
            && $event['severity'] === 'CRITICAL'
            && (int)$event['count_since_reset'] === 1
            && (int)$event['count_since_init'] === 1
            && $event['is_active_flag'] === true
            && $event['is_thermal_alert'] === true,
        sprintf('categoría=%s · severidad=%s', $event['event_category'], $event['severity'])
    );

    $thermalOnOther = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertEvent([
            'line_sequence' => 2,
            'event_code' => 'EA999',
            'event_category' => 'OTHER',
            'severity' => 'INFO',
        ])
    );
    $assert(
        'El aviso térmico solo puede colgar de un evento `THERMAL_REFRIGERATION` (Art. II / D4)',
        $thermalOnOther !== null && $thermalOnOther['state'] === '23514',
        $thermalOnOther === null ? 'el aviso incoherente pasó' : 'estado=' . $thermalOnOther['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. III.3: inmutabilidad de la captura y de las líneas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.3: inmutabilidad de la captura y de las líneas ---\n";

    $rewriteAudit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $audit): void {
        $pgPdo->prepare('UPDATE machine_dex_audits SET audit_code = :code WHERE id = :id')
            ->execute([':code' => 'DEX-2026-9999', ':id' => $audit['id']]);
    });
    $assert(
        'La cabecera rechaza con `45000` la reescritura de su código de captura',
        $rewriteAudit !== null && $rewriteAudit['state'] === '45000',
        $rewriteAudit === null ? 'la captura se dejó reescribir' : 'estado=' . $rewriteAudit['state']
    );

    $rewriteSelection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $selection): void {
        $pgPdo->prepare('UPDATE machine_dex_selection_lines SET total_sales_eur = 99.99 WHERE id = :id')
            ->execute([':id' => $selection['id']]);
    });
    $assert(
        'Una línea de selección rechaza con `45000` cualquier `UPDATE`',
        $rewriteSelection !== null && $rewriteSelection['state'] === '45000',
        $rewriteSelection === null ? 'la línea se dejó reescribir' : 'estado=' . $rewriteSelection['state']
    );

    $rewriteEvent = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $event): void {
        $pgPdo->prepare('UPDATE machine_dex_event_lines SET description_es = :text WHERE id = :id')
            ->execute([':text' => 'Descripción reescrita', ':id' => $event['id']]);
    });
    $assert(
        'Una línea de evento rechaza con `45000` cualquier `UPDATE`',
        $rewriteEvent !== null && $rewriteEvent['state'] === '45000',
        $rewriteEvent === null ? 'la línea se dejó reescribir' : 'estado=' . $rewriteEvent['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // D1: enlace único de la operación y anulación lógica (Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- D1 / Art. V.1: enlace único y anulación lógica ---\n";

    $linkVisit = $pgPdo->prepare('UPDATE machine_dex_audits SET restock_visit_id = :visit WHERE id = :id');
    $linkVisit->execute([':visit' => $visit['id'], ':id' => $audit['id']]);
    $assert(
        'Un volcado `ACTIVE` se enlaza una sola vez a la visita de reposición (D1)',
        $linkVisit->rowCount() === 1,
        'filas enlazadas: ' . $linkVisit->rowCount()
    );

    $relinkVisit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $audit): void {
        $pgPdo->prepare('UPDATE machine_dex_audits SET restock_visit_id = NULL WHERE id = :id')
            ->execute([':id' => $audit['id']]);
    });
    $assert(
        'El vínculo ya fijado no se puede reescribir ni desenlazar (`45000`)',
        $relinkVisit !== null && $relinkVisit['state'] === '45000',
        $relinkVisit === null ? 'el vínculo se dejó reescribir' : 'estado=' . $relinkVisit['state']
    );

    $shortVoid = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $audit, $importer): void {
        $pgPdo->prepare(
            'UPDATE machine_dex_audits
                SET status = :status, void_reason = :reason, voided_by = :actor,
                    voided_by_name_snapshot = :name, voided_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        )->execute([
            ':status' => 'VOIDED',
            ':reason' => 'Motivo corto',
            ':actor' => $importer['id'],
            ':name' => 'Coordinación de Pruebas',
            ':id' => $audit['id'],
        ]);
    });
    $assert(
        'La anulación exige un motivo real de 20 a 500 caracteres (Art. V.1)',
        $shortVoid !== null && $shortVoid['state'] === '23514',
        $shortVoid === null ? 'la anulación corta pasó' : 'estado=' . $shortVoid['state']
    );

    $void = $pgPdo->prepare(
        'UPDATE machine_dex_audits
            SET status = :status, void_reason = :reason, voided_by = :actor,
                voided_by_name_snapshot = :name, voided_at = CURRENT_TIMESTAMP
          WHERE id = :id
      RETURNING status, voided_at'
    );
    $void->execute([
        ':status' => 'VOIDED',
        ':reason' => 'Reimportación correcta tras corregir el fichero original',
        ':actor' => $importer['id'],
        ':name' => 'Coordinación de Pruebas',
        ':id' => $audit['id'],
    ]);
    $voided = $void->fetch();
    $assert(
        'La transición única `ACTIVE` → `VOIDED` sella el autor y la fecha',
        is_array($voided) && $voided['status'] === 'VOIDED' && $voided['voided_at'] !== null,
        is_array($voided) ? 'estado=' . $voided['status'] : 'el motor no devolvió la fila'
    );

    $afterVoidRewrite = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $audit): void {
        $pgPdo->prepare('UPDATE machine_dex_audits SET void_reason = :reason WHERE id = :id')
            ->execute([':reason' => 'Quiero cambiar el motivo ya sellado del volcado', ':id' => $audit['id']]);
    });
    $assert(
        'Un volcado `VOIDED` es terminal y rechaza con `45000` cualquier escritura posterior',
        $afterVoidRewrite !== null && $afterVoidRewrite['state'] === '45000',
        $afterVoidRewrite === null ? 'la fila anulada se dejó reescribir' : 'estado=' . $afterVoidRewrite['state']
    );

    $reimport = $insertAudit(['audit_code' => 'DEX-2026-7003']);
    $assert(
        'La anulación lógica libera la huella y permite reimportar el mismo fichero (QA-4)',
        $reimport['status'] === 'ACTIVE' && $reimport['sha256_hash'] === $audit['sha256_hash'],
        'nuevo id=' . $reimport['id'] . ' · estado=' . $reimport['status']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. II.2 / QA-1: primacía sanitaria en el alta del Reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. II.2 / QA-1: primacía sanitaria ---\n";

    $pgPdo->prepare('UPDATE machines SET sanitary_status = :status WHERE id = :id')
        ->execute([':status' => 'QUARANTINE', ':id' => $machine['id']]);

    $restockerBlocked = PostgresFixtureBuilder::expectRejection(
        static fn () => $insertAudit([
            'audit_code' => 'DEX-2026-7101',
            'importer_role_snapshot' => 'RESTOCKER',
            'import_source' => 'RESTOCKER_ROUTE',
            'sha256_hash' => str_repeat('e', 64),
        ])
    );
    $assert(
        'El Reponedor no puede importar sobre una máquina en `QUARANTINE` (`45000`, Art. II.2)',
        $restockerBlocked !== null && $restockerBlocked['state'] === '45000',
        $restockerBlocked === null ? 'la importación bloqueada pasó' : 'estado=' . $restockerBlocked['state']
    );

    $coordinatorForensic = $insertAudit([
        'audit_code' => 'DEX-2026-7102',
        'sha256_hash' => str_repeat('f', 64),
    ]);
    $assert(
        'Coordinación conserva el acceso forense sobre la misma máquina en cuarentena',
        $coordinatorForensic['status'] === 'ACTIVE',
        'estado=' . $coordinatorForensic['status']
    );

    $pgPdo->prepare('UPDATE machines SET sanitary_status = :status WHERE id = :id')
        ->execute([':status' => 'OK', ':id' => $machine['id']]);

    $secondMachine = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $rows, '02');
    $thermalIncident = PostgresFixtureBuilder::insertIncident(
        $pgPdo,
        'TCK-DEX-0001',
        (int)$location['id'],
        (int)$secondMachine['id'],
        ['category' => 'TEMPERATURE_COLD']
    );

    $thermalBlocked = PostgresFixtureBuilder::expectRejection(
        static function () use ($insertAudit, $secondMachine): array {
            // El alta apunta a la segunda máquina: el guarda lee la avería abierta
            // de `incidents`, no la categoría del volcado.
            return $insertAudit([
                'audit_code' => 'DEX-2026-7103',
                'importer_role_snapshot' => 'RESTOCKER',
                'import_source' => 'RESTOCKER_ROUTE',
                'sha256_hash' => str_repeat('1', 64),
            ], $secondMachine);
        }
    );
    $assert(
        'El Reponedor queda bloqueado con una avería `TEMPERATURE_COLD` abierta (QA-1)',
        $thermalBlocked !== null && $thermalBlocked['state'] === '45000',
        $thermalBlocked === null ? 'la importación bloqueada pasó' : 'estado=' . $thermalBlocked['state']
    );

    $technicianForensic = $insertAudit([
        'audit_code' => 'DEX-2026-7104',
        'importer_role_snapshot' => 'TECHNICIAN',
        'import_source' => 'TECHNICIAN_ROUTE',
        'sha256_hash' => str_repeat('2', 64),
    ], $secondMachine);
    $assert(
        'El Técnico SAT conserva el acceso forense con la avería térmica abierta (C2)',
        $technicianForensic['status'] === 'ACTIVE',
        'estado=' . $technicianForensic['status']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Borrado: rechazo sin ventana y purga explícita del escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1: borrado físico y ventana de purga ---\n";

    $deleteBlocked = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec('DELETE FROM machine_dex_event_lines');
    });
    $assert(
        'Sin la ventana del arnés, el borrado físico de los eventos se rechaza con `45000`',
        $deleteBlocked !== null && $deleteBlocked['state'] === '45000',
        $deleteBlocked === null ? 'el borrado pasó' : 'estado=' . $deleteBlocked['state']
    );

    $purged = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo): array {
        $deleted = [];
        foreach (['machine_dex_event_lines', 'machine_dex_selection_lines', 'machine_dex_audits'] as $table) {
            $deleted[$table] = $pgPdo->exec('DELETE FROM ' . $table);
        }

        return $deleted;
    });
    $assert(
        'La ventana de purga retira las líneas y la cabecera del escenario',
        $purged['machine_dex_event_lines'] === 1
            && $purged['machine_dex_selection_lines'] === 1
            && $purged['machine_dex_audits'] === 4,
        json_encode($purged)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query("SELECT to_regclass('" . PostgresFixtureBuilder::ISOLATION_SCHEMA . ".machine_dex_audits')")->fetchColumn();
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
    echo " RESULT: 100% EN VERDE. T-DEX-02 (contrato inmutable del volcado .dex) CUMPLIDO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL CONTRATO DEL VOLCADO .DEX.\n";
echo "======================================================================\n";
exit(1);
