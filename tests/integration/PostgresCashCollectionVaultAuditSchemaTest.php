<?php

declare(strict_types=1);

/**
 * PostgresCashCollectionVaultAuditSchemaTest · T-CCV-02
 *
 * Certifica en el motor real el esquema del Módulo 35 (`35-cash-collection-vault-audit`): el tipo
 * nativo #42 `cash_collection_status` y la Tabla 49 `machine_cash_collections` con su blindaje
 * constitucional. No comprueba servicios ni API —eso llega con las tareas `T-CCV-03` a
 * `T-CCV-12`— sino las garantías que la base impone por sí sola, porque son las que ningún camino
 * de código puede saltarse:
 *
 *   1. **Tipos nativos (RF-01.1, Art. III.3).** `cash_collection_status` declara los cuatro
 *      desenlaces del contrato y `MACHINE_CASH_COLLECTION` se puede castear en `audit_entity_type`.
 *   2. **Forma de la Tabla 49 (D1, D2, QA 1).** Treinta y tres columnas en el orden del contrato,
 *      con la captura de ruta, la entrega en conserjería y el arqueo terminal.
 *   3. **Identidades del corte (C1, D1, D3, D4).** `collection_code`, el precinto global y la
 *      mutación del móvil son únicos; los índices parciales impiden dos recaudaciones de la misma
 *      visita y dos liquidaciones del mismo reintegro.
 *   4. **Aritmética del arqueo ciego (D2, C3).** La diferencia del contador es una identidad del
 *      registro, con y sin reseteo, y el reseteo exige nota real de 20 caracteres.
 *   5. **Ciclo de custodia y tolerancia (D4, QA 1).** Cada estado declara sus marcas, el arqueo
 *      terminal exige la tolerancia de ±1,00 € y, por encima, la justificación real del Art. V.1.
 *   6. **Cerrojo sanitario y de conserjería (Art. II.2, RNF-02).** Cuarentena, fuera de servicio,
 *      avería térmica abierta, visita abortada por rotura de frío y sede sin recepción física se
 *      rechazan con `45000`.
 *   7. **Inmutabilidad e historial (Art. III.1 y III.3).** El `UPDATE` de la captura en ruta y de
 *      una fila ya arqueada se rechaza con `45000`, el `DELETE` sin ventana de purga también, y
 *      dentro de la ventana del arnés el borrado funciona: la tabla no es un sumidero.
 *   8. **Guarda documental (PR-09).** Los comentarios de la migración 031 describen el correlativo
 *      real `MCC-AAAA-NNNN` y no reintroducen el formato obsoleto de seis dígitos.
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
 *   source deploy/local/postgres/.env.docker-tls && set -a && php tests/integration/PostgresCashCollectionVaultAuditSchemaTest.php
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

/** Esquema aislado de esta suite: no comparte escenario con las suites de otros módulos. */
const CASH_TEST_SCHEMA = 't_ccv02_recaudacion';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

/** Marca del disparador de inmutabilidad de la captura en ruta (Art. III.3). */
const ROUTE_MUTATION_MARKER = 'ART_III_3_ROUTE_CAPTURE_IMMUTABLE';

/** Marca del bloqueo terminal tras el arqueo (Art. III.3). */
const TERMINAL_MUTATION_MARKER = 'ART_III_3_CASH_COLLECTION_IMMUTABLE';

/** Marca de la guarda sanitaria y de conserjería (Art. II.2, D3). */
const SANITARY_BLOCK_MARKER = 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED';

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
echo " VENDGUARD · T-CCV-02 · Esquema de la recaudación y el arqueo de caja (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo lo que las reglas necesitan para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

$createLocation = static function (PDO $pdo, string $suffix, bool $hasPhysicalReception): int {
    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, :reception)
         RETURNING id'
    );
    $stmt->execute([
        ':site_code' => 'SEDE-CCV-' . strtoupper($suffix),
        ':site_code_key' => 'sede-ccv-' . strtolower($suffix),
        ':name' => 'Sede de certificación de la recaudación ' . $suffix,
        ':address' => 'Carrer de la Caixa 35, Barcelona',
        ':reception' => $hasPhysicalReception ? 'TRUE' : 'FALSE',
    ]);

    return (int)$stmt->fetchColumn();
};

$createMachine = static function (
    PDO $pdo,
    int $locationId,
    string $code,
    string $sanitaryStatus = 'OK',
    bool $blocked = false
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status, is_blocked_no_access)
         VALUES (:location_id, :code, :model, \'COMBO\', :floor_wing, :sanitary, :blocked)
         RETURNING id'
    );
    $stmt->execute([
        ':location_id' => $locationId,
        ':code' => $code,
        ':model' => 'Modelo de certificación ' . $code,
        ':floor_wing' => 'Planta de certificación',
        ':sanitary' => $sanitaryStatus,
        ':blocked' => $blocked ? 'TRUE' : 'FALSE',
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
        ':email' => 'ccv02.' . strtolower($suffix) . '@gestion-vending.test',
        ':email_key' => 'ccv02.' . strtolower($suffix) . '@gestion-vending.test',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => $role,
    ]);

    return (int)$stmt->fetchColumn();
};

$createVisit = static function (
    PDO $pdo,
    int $machineId,
    int $restockerId,
    string $visitCode,
    string $status,
    float $cabinTemp,
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
        ':client_mutation_id' => 'mutation-visit-' . strtolower($visitCode),
    ]);

    return (int)$stmt->fetchColumn();
};

$insertCash = static function (
    PDO $pdo,
    string $collectionCode,
    string $bagSealCode,
    int $machineId,
    int $restockerId,
    array $overrides = []
): int {
    $data = array_merge([
        'restock_visit_id' => null,
        'machine_code_snapshot' => 'VEND-CCV-001',
        'restocker_name_snapshot' => 'Reponedor de certificación',
        'restocker_code_snapshot' => 'REP-CCV-01',
        'change_tubes_loaded_eur' => '20.00',
        'sales_counter_reading' => '125.50',
        'previous_sales_counter_reading' => '100.00',
        'initial_baseline_reading_eur' => '0.00',
        'is_counter_reset' => false,
        'counter_reset_notes' => null,
        'expected_cashbox_eur' => '25.50',
        'reception_cash_delivered_eur' => '0.00',
        'receptionist_name' => null,
        'linked_refund_request_id' => null,
        'route_notes' => null,
        'status' => 'SEALED_IN_ROUTE',
        'collected_at' => '2026-11-10 10:00:00+00',
        'delivered_to_vault_at' => null,
        'delivered_to_vault_by' => null,
        'vault_counted_eur' => null,
        'discrepancy_eur' => null,
        'vault_reconciled_by' => null,
        'vault_reconciled_by_name_snapshot' => null,
        'vault_reconciled_at' => null,
        'discrepancy_justification' => null,
        'client_mutation_id' => 'mutation-ccv-' . strtolower($collectionCode),
    ], $overrides);

    $stmt = $pdo->prepare(
        'INSERT INTO machine_cash_collections
             (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
              restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, change_tubes_loaded_eur,
              sales_counter_reading, previous_sales_counter_reading, initial_baseline_reading_eur,
              is_counter_reset, counter_reset_notes, expected_cashbox_eur, reception_cash_delivered_eur,
              receptionist_name, linked_refund_request_id, route_notes, status, collected_at,
              delivered_to_vault_at, delivered_to_vault_by, vault_counted_eur, discrepancy_eur,
              vault_reconciled_by, vault_reconciled_by_name_snapshot, vault_reconciled_at,
              discrepancy_justification, client_mutation_id)
         VALUES (:collection_code, :restock_visit_id, :machine_id, :machine_code_snapshot, :restocker_id,
              :restocker_name_snapshot, :restocker_code_snapshot, :bag_seal_code, :change_tubes_loaded_eur,
              :sales_counter_reading, :previous_sales_counter_reading, :initial_baseline_reading_eur,
              :is_counter_reset, :counter_reset_notes, :expected_cashbox_eur, :reception_cash_delivered_eur,
              :receptionist_name, :linked_refund_request_id, :route_notes, :status, :collected_at,
              :delivered_to_vault_at, :delivered_to_vault_by, :vault_counted_eur, :discrepancy_eur,
              :vault_reconciled_by, :vault_reconciled_by_name_snapshot, :vault_reconciled_at,
              :discrepancy_justification, :client_mutation_id)
         RETURNING id'
    );
    $stmt->execute([
        ':collection_code' => $collectionCode,
        ':restock_visit_id' => $data['restock_visit_id'],
        ':machine_id' => $machineId,
        ':machine_code_snapshot' => $data['machine_code_snapshot'],
        ':restocker_id' => $restockerId,
        ':restocker_name_snapshot' => $data['restocker_name_snapshot'],
        ':restocker_code_snapshot' => $data['restocker_code_snapshot'],
        ':bag_seal_code' => $bagSealCode,
        ':change_tubes_loaded_eur' => $data['change_tubes_loaded_eur'],
        ':sales_counter_reading' => $data['sales_counter_reading'],
        ':previous_sales_counter_reading' => $data['previous_sales_counter_reading'],
        ':initial_baseline_reading_eur' => $data['initial_baseline_reading_eur'],
        ':is_counter_reset' => $data['is_counter_reset'] === true ? 'TRUE' : 'FALSE',
        ':counter_reset_notes' => $data['counter_reset_notes'],
        ':expected_cashbox_eur' => $data['expected_cashbox_eur'],
        ':reception_cash_delivered_eur' => $data['reception_cash_delivered_eur'],
        ':receptionist_name' => $data['receptionist_name'],
        ':linked_refund_request_id' => $data['linked_refund_request_id'],
        ':route_notes' => $data['route_notes'],
        ':status' => $data['status'],
        ':collected_at' => $data['collected_at'],
        ':delivered_to_vault_at' => $data['delivered_to_vault_at'],
        ':delivered_to_vault_by' => $data['delivered_to_vault_by'],
        ':vault_counted_eur' => $data['vault_counted_eur'],
        ':discrepancy_eur' => $data['discrepancy_eur'],
        ':vault_reconciled_by' => $data['vault_reconciled_by'],
        ':vault_reconciled_by_name_snapshot' => $data['vault_reconciled_by_name_snapshot'],
        ':vault_reconciled_at' => $data['vault_reconciled_at'],
        ':discrepancy_justification' => $data['discrepancy_justification'],
        ':client_mutation_id' => $data['client_mutation_id'],
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 031) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, CASH_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 031 del Módulo 35',
        in_array('031_cash_collection_vault_audit.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf(
            '%d sentencias · migraciones: %d · pendientes: %s',
            $schemaReport['statements'],
            count($schemaReport['migrations']),
            json_encode($convergence['pending'])
        )
    );

    $migrationSql = (string)file_get_contents(
        $projectRoot . '/database/supabase_migrations/031_cash_collection_vault_audit.sql'
    );
    $assert(
        'Los comentarios de la migración 031 usan el formato real MCC-AAAA-NNNN (PR-09)',
        preg_match('/MCC-AAAA-N{4}(?!N)/', $migrationSql) === 1
            && preg_match('/MCC-AAAA-N{5,}/', $migrationSql) === 0,
        'el correlativo documentado debe coincidir con el CHECK de cuatro o más dígitos'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos y entidad auditable (RF-01.1, Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos de la recaudación y entidad auditable ---\n";

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
        'cash_collection_status declara los cuatro desenlaces del contrato en su orden',
        $enumLabels($pgPdo, 'cash_collection_status') === [
            'SEALED_IN_ROUTE',
            'DELIVERED_TO_VAULT',
            'RECONCILED_OK',
            'DISCREPANCY_FLAGGED',
        ],
        implode(',', $enumLabels($pgPdo, 'cash_collection_status'))
    );

    $auditCast = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->query("SELECT 'MACHINE_CASH_COLLECTION'::audit_entity_type")->fetch();
    });

    $assert(
        'La entidad auditable MACHINE_CASH_COLLECTION se puede castear en el motor',
        $auditCast === null,
        $auditCast === null ? 'admitida' : 'rechazado con ' . $auditCast['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de la Tabla 49 machine_cash_collections (D1, D2, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 49 machine_cash_collections ---\n";

    $cashColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_cash_collections');
    $assert(
        'La tabla declara sus treinta y tres columnas canónicas en el orden del contrato',
        array_keys($cashColumns) === [
            'id', 'collection_code', 'restock_visit_id', 'machine_id', 'machine_code_snapshot',
            'restocker_id', 'restocker_name_snapshot', 'restocker_code_snapshot', 'bag_seal_code',
            'change_tubes_loaded_eur', 'sales_counter_reading', 'previous_sales_counter_reading',
            'initial_baseline_reading_eur', 'is_counter_reset', 'counter_reset_notes',
            'expected_cashbox_eur', 'reception_cash_delivered_eur', 'receptionist_name',
            'linked_refund_request_id', 'route_notes', 'status', 'collected_at',
            'delivered_to_vault_at', 'delivered_to_vault_by', 'vault_counted_eur', 'discrepancy_eur',
            'vault_reconciled_by', 'vault_reconciled_by_name_snapshot', 'vault_reconciled_at',
            'discrepancy_justification', 'client_mutation_id', 'created_at', 'updated_at',
        ],
        implode(', ', array_keys($cashColumns))
    );

    $assert(
        'La captura de ruta y el arqueo usan los tipos y longitudes del contrato',
        $cashColumns['bag_seal_code']['max_length'] === 40
            && $cashColumns['sales_counter_reading']['udt_name'] === 'numeric'
            && $cashColumns['status']['udt_name'] === 'cash_collection_status'
            && $cashColumns['delivered_to_vault_at']['nullable'] === true
            && $cashColumns['vault_reconciled_by']['nullable'] === true
            && $cashColumns['discrepancy_justification']['max_length'] === 1000,
        sprintf(
            'precinto=%s · contador=%s · estado=%s · justificación=%s',
            $cashColumns['bag_seal_code']['max_length'] ?? '?',
            $cashColumns['sales_counter_reading']['udt_name'] ?? '?',
            $cashColumns['status']['udt_name'] ?? '?',
            $cashColumns['discrepancy_justification']['max_length'] ?? '?'
        )
    );

    $cashChecks = $pgPdo->query(
        "SELECT con.conname
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_cash_collections' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $expectedCashChecks = [
        'chk_machine_cash_collections_code_format',
        'chk_machine_cash_collections_bag_seal_format',
        'chk_machine_cash_collections_mutation_format',
        'chk_machine_cash_collections_snapshots_not_empty',
        'chk_machine_cash_collections_amounts_bounds',
        'chk_machine_cash_collections_counter_coherence',
        'chk_machine_cash_collections_reception_coherence',
        'chk_machine_cash_collections_vault_lifecycle',
    ];
    $assert(
        'Las ocho restricciones CHECK del arqueo ciego están declaradas en el motor',
        array_values(array_intersect($expectedCashChecks, $cashChecks)) === $expectedCashChecks,
        'declaradas: ' . implode(', ', $cashChecks)
    );

    $cashIndexes = [];
    foreach ($pgPdo->query(
        "SELECT indexname, indexdef FROM pg_indexes
          WHERE schemaname = current_schema() AND tablename = 'machine_cash_collections'"
    )->fetchAll() as $index) {
        $cashIndexes[(string)$index['indexname']] = (string)$index['indexdef'];
    }

    $assert(
        'collection_code, el precinto global y la mutación del móvil son identidades únicas',
        str_contains($cashIndexes['uq_machine_cash_collections_code'] ?? '', 'UNIQUE')
            && str_contains($cashIndexes['uq_machine_cash_collections_bag_seal'] ?? '', 'UNIQUE')
            && str_contains($cashIndexes['uq_machine_cash_collections_mutation'] ?? '', 'restocker_id')
            && str_contains($cashIndexes['uq_machine_cash_collections_mutation'] ?? '', 'client_mutation_id'),
        implode(' | ', array_keys($cashIndexes))
    );

    $assert(
        'Los índices parciales impiden dos recaudaciones de la visita y dos liquidaciones del reintegro',
        str_contains($cashIndexes['uq_machine_cash_collections_visit'] ?? '', 'WHERE (restock_visit_id IS NOT NULL)')
            && str_contains($cashIndexes['uq_machine_cash_collections_refund'] ?? '', 'WHERE (linked_refund_request_id IS NOT NULL)'),
        'visita: ' . ($cashIndexes['uq_machine_cash_collections_visit'] ?? 'ausente')
            . ' · reintegro: ' . ($cashIndexes['uq_machine_cash_collections_refund'] ?? 'ausente')
    );

    $assert(
        'Los tres índices de consulta del libro contable existen',
        isset(
            $cashIndexes['idx_machine_cash_collections_machine_collected'],
            $cashIndexes['idx_machine_cash_collections_restocker_collected'],
            $cashIndexes['idx_machine_cash_collections_status_collected']
        )
    );

    $cashForeignKeys = [];
    foreach ($pgPdo->query(
        "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_cash_collections' AND con.contype = 'f'"
    )->fetchAll() as $foreignKey) {
        $cashForeignKeys[(string)$foreignKey['name']] = (string)$foreignKey['definition'];
    }

    $assert(
        'Las seis claves foráneas referencian visita, máquina, reponedor, reintegro y actores con RESTRICT y CASCADE',
        count($cashForeignKeys) === 6
            && str_contains($cashForeignKeys['fk_machine_cash_collections_visit'] ?? '', 'machine_restock_visits(id)')
            && str_contains($cashForeignKeys['fk_machine_cash_collections_machine'] ?? '', 'machines(id)')
            && str_contains($cashForeignKeys['fk_machine_cash_collections_restocker'] ?? '', 'users(id)')
            && str_contains($cashForeignKeys['fk_machine_cash_collections_refund'] ?? '', 'refund_requests(id)')
            && str_contains($cashForeignKeys['fk_machine_cash_collections_delivered_by'] ?? '', 'users(id)')
            && str_contains($cashForeignKeys['fk_machine_cash_collections_reconciled_by'] ?? '', 'users(id)')
            && substr_count(implode(' ', $cashForeignKeys), 'ON DELETE RESTRICT') === 6
            && substr_count(implode(' ', $cashForeignKeys), 'ON UPDATE CASCADE') === 6,
        implode(' | ', array_keys($cashForeignKeys))
    );

    $cashTriggers = $pgPdo->query(
        "SELECT tgname
           FROM pg_trigger t
           JOIN pg_class c ON c.oid = t.tgrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'machine_cash_collections' AND NOT t.tgisinternal"
    )->fetchAll(PDO::FETCH_COLUMN);

    $expectedCashTriggers = [
        'trg_no_delete_machine_cash_collections',
        'trg_touch_machine_cash_collections',
        'trg_guard_machine_cash_collections',
        'trg_enforce_cash_collection_sanitary_guard',
    ];
    $assert(
        'Los cuatro disparadores de blindaje están declarados en el motor',
        array_values(array_intersect($expectedCashTriggers, $cashTriggers)) === $expectedCashTriggers,
        'declarados: ' . implode(', ', $cashTriggers)
    );

    $guardFunctions = $pgPdo->query(
        "SELECT proname
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE n.nspname = current_schema()
            AND p.proname IN ('enforce_machine_cash_collections_immutability', 'enforce_cash_collection_sanitary_guard')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'Las dos funciones de guarda del Módulo 35 existen en el motor',
        count(array_unique($guardFunctions)) === 2,
        implode(', ', $guardFunctions)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Escenario vivo: sede con conserjería, máquinas y actores
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario vivo: parque mínimo del módulo ---\n";

    $locationId = $createLocation($pgPdo, 'uno', true);
    $closedLocationId = $createLocation($pgPdo, 'dos', false);
    $machineId = $createMachine($pgPdo, $locationId, 'VEND-CCV-001');
    $quarantineMachineId = $createMachine($pgPdo, $locationId, 'VEND-CCV-QUAR', 'QUARANTINE');
    $blockedMachineId = $createMachine($pgPdo, $locationId, 'VEND-CCV-BLOCK', 'OK', true);
    $thermalMachineId = $createMachine($pgPdo, $locationId, 'VEND-CCV-THERM');
    $closedSiteMachineId = $createMachine($pgPdo, $closedLocationId, 'VEND-CCV-NOREC');
    $restockerId = $createUser($pgPdo, 'rut', 'RESTOCKER');
    $coordinatorId = $createUser($pgPdo, 'caja', 'COORDINATOR');

    $assert(
        'El parque mínimo se levanta con dos sedes, cinco máquinas y los dos actores del ciclo',
        $locationId > 0 && $closedLocationId > 0 && $machineId > 0 && $quarantineMachineId > 0
            && $blockedMachineId > 0 && $thermalMachineId > 0 && $closedSiteMachineId > 0
            && $restockerId > 0 && $coordinatorId > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Identidades del corte y alta mínima (C1, D4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Identidades del corte y alta mínima ---\n";

    $sealedId = $insertCash($pgPdo, 'MCC-2026-0001', 'BAG-CCV-000001', $machineId, $restockerId);
    $assert('La recaudación sellada en ruta se persiste y el motor devuelve su identidad', $sealedId > 0);

    $duplicateCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0001', 'BAG-CCV-000002', $machineId, $restockerId, [
            'client_mutation_id' => 'mutation-ccv-duplicate-code',
        ]);
    });
    $assert(
        'Un collection_code repetido se rechaza con 23505',
        $duplicateCode !== null && $duplicateCode['state'] === '23505',
        $duplicateCode === null ? 'la clave única no mordió' : 'estado=' . $duplicateCode['state']
    );

    $duplicateSeal = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0002', 'BAG-CCV-000001', $machineId, $restockerId);
    });
    $assert(
        'Un precinto ya usado en la historia del sistema se rechaza con 23505 (C1)',
        $duplicateSeal !== null && $duplicateSeal['state'] === '23505',
        $duplicateSeal === null ? 'el precinto se reutilizó' : 'estado=' . $duplicateSeal['state']
    );

    $duplicateMutation = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0003', 'BAG-CCV-000003', $machineId, $restockerId, [
            'client_mutation_id' => 'mutation-ccv-mcc-2026-0001',
        ]);
    });
    $assert(
        'El reenvío de la misma mutación del reponedor se rechaza con 23505 (D4)',
        $duplicateMutation !== null && $duplicateMutation['state'] === '23505',
        $duplicateMutation === null ? 'la idempotencia no mordió' : 'estado=' . $duplicateMutation['state']
    );

    $badSeal = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0004', 'bag ccv 01', $machineId, $restockerId);
    });
    $assert(
        'Un precinto fuera del formato cerrado se rechaza con 23514',
        $badSeal !== null && $badSeal['state'] === '23514'
            && str_contains($badSeal['message'], 'chk_machine_cash_collections_bag_seal_format'),
        $badSeal === null ? 'el precinto inválido se admitió' : 'estado=' . $badSeal['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Aritmética del arqueo ciego (D2, C3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Aritmética del contador con y sin reseteo ---\n";

    $wrongExpected = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0005', 'BAG-CCV-000005', $machineId, $restockerId, [
            'expected_cashbox_eur' => '20.00',
        ]);
    });
    $assert(
        'Un expected_cashbox_eur que no es la diferencia del contador se rechaza con 23514',
        $wrongExpected !== null && $wrongExpected['state'] === '23514'
            && str_contains($wrongExpected['message'], 'chk_machine_cash_collections_counter_coherence'),
        $wrongExpected === null ? 'el descuadre se admitió' : 'estado=' . $wrongExpected['state']
    );

    $resetWithoutNotes = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0006', 'BAG-CCV-000006', $machineId, $restockerId, [
            'is_counter_reset' => true,
            'previous_sales_counter_reading' => null,
            'expected_cashbox_eur' => '125.50',
        ]);
    });
    $assert(
        'Un reseteo sin nota real de 20 caracteres se rechaza con 23514',
        $resetWithoutNotes !== null && $resetWithoutNotes['state'] === '23514'
            && str_contains($resetWithoutNotes['message'], 'chk_machine_cash_collections_counter_coherence'),
        $resetWithoutNotes === null ? 'el reseteo sin nota se admitió' : 'estado=' . $resetWithoutNotes['state']
    );

    $resetId = $insertCash($pgPdo, 'MCC-2026-0007', 'BAG-CCV-000007', $machineId, $restockerId, [
        'is_counter_reset' => true,
        'previous_sales_counter_reading' => null,
        'counter_reset_notes' => 'Reseteo autorizado del contador tras la sustitución del monedero.',
        'expected_cashbox_eur' => '125.50',
    ]);
    $assert('El reseteo con nota justificada se persiste como lectura completa', $resetId > 0);

    $counterRegression = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0008', 'BAG-CCV-000008', $machineId, $restockerId, [
            'previous_sales_counter_reading' => '130.00',
            'expected_cashbox_eur' => '25.50',
        ]);
    });
    $assert(
        'Una lectura inferior a la previa sin reseteo se rechaza con 23514',
        $counterRegression !== null && $counterRegression['state'] === '23514'
            && str_contains($counterRegression['message'], 'chk_machine_cash_collections_counter_coherence'),
        $counterRegression === null ? 'el contador retrocedió' : 'estado=' . $counterRegression['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Entrega en conserjería (D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Entrega de efectivo en conserjería ---\n";

    $overLimit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0009', 'BAG-CCV-000009', $machineId, $restockerId, [
            'reception_cash_delivered_eur' => '12.00',
            'receptionist_name' => 'Conserje de certificación',
        ]);
    });
    $assert(
        'Una entrega superior al límite de 10,00 € se rechaza con 23514',
        $overLimit !== null && $overLimit['state'] === '23514'
            && str_contains($overLimit['message'], 'chk_machine_cash_collections_amounts_bounds'),
        $overLimit === null ? 'la entrega excesiva se admitió' : 'estado=' . $overLimit['state']
    );

    $missingReceptionist = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0010', 'BAG-CCV-000010', $machineId, $restockerId, [
            'reception_cash_delivered_eur' => '4.50',
        ]);
    });
    $assert(
        'Una entrega sin nombre de conserje se rechaza con 23514',
        $missingReceptionist !== null && $missingReceptionist['state'] === '23514'
            && str_contains($missingReceptionist['message'], 'chk_machine_cash_collections_reception_coherence'),
        $missingReceptionist === null ? 'la entrega sin conserje se admitió' : 'estado=' . $missingReceptionist['state']
    );

    $noReceptionSite = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $closedSiteMachineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0011', 'BAG-CCV-000011', $closedSiteMachineId, $restockerId, [
            'reception_cash_delivered_eur' => '4.50',
            'receptionist_name' => 'Conserje de certificación',
        ]);
    });
    $assert(
        'Una sede sin conserjería física rechaza la entrega con 45000 (D3)',
        $noReceptionSite !== null && $noReceptionSite['state'] === '45000'
            && str_contains($noReceptionSite['message'], 'LOCATION_HAS_NO_PHYSICAL_RECEPTION'),
        $noReceptionSite === null ? 'la entrega se admitió sin conserjería' : json_encode([
            'state' => $noReceptionSite['state'],
            'message' => mb_substr($noReceptionSite['message'], 0, 80),
        ])
    );

    $receptionId = $insertCash($pgPdo, 'MCC-2026-0012', 'BAG-CCV-000012', $machineId, $restockerId, [
        'reception_cash_delivered_eur' => '4.50',
        'receptionist_name' => 'Conserje de certificación',
    ]);
    $assert('La entrega con conserje en sede habilitada se persiste', $receptionId > 0);

    // ─────────────────────────────────────────────────────────────────────
    // 7. Cerrojo sanitario de la máquina y de la visita (Art. II.2, RNF-02)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cerrojo sanitario de la recaudación ---\n";

    $quarantine = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $quarantineMachineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0013', 'BAG-CCV-000013', $quarantineMachineId, $restockerId);
    });
    $assert(
        'Una máquina en cuarentena sanitaria se rechaza con 45000 (Art. II.2)',
        $quarantine !== null && $quarantine['state'] === '45000'
            && str_contains($quarantine['message'], SANITARY_BLOCK_MARKER),
        $quarantine === null ? 'la cuarentena se saltó' : 'estado=' . $quarantine['state']
    );

    $blocked = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $blockedMachineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0014', 'BAG-CCV-000014', $blockedMachineId, $restockerId);
    });
    $assert(
        'Una máquina fuera de servicio se rechaza con 45000 (Art. II.2)',
        $blocked !== null && $blocked['state'] === '45000'
            && str_contains($blocked['message'], SANITARY_BLOCK_MARKER),
        $blocked === null ? 'el bloqueo de acceso se saltó' : 'estado=' . $blocked['state']
    );

    $thermalIncident = $pgPdo->prepare(
        'INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status)
         VALUES (:ticket, :machine, :location, \'TEMPERATURE_COLD\', :description, \'HIGH\', \'REGISTERED\')
         RETURNING id'
    );
    $thermalIncident->execute([
        ':ticket' => 'INC-CCV-THERM-0001',
        ':machine' => $thermalMachineId,
        ':location' => $locationId,
        ':description' => 'Avería térmica abierta de certificación del cerrojo sanitario del Módulo 35.',
    ]);
    $thermalIncidentId = (int)$thermalIncident->fetchColumn();
    $assert('La avería térmica abierta se siembra sobre su máquina dedicada', $thermalIncidentId > 0);

    $thermalBlock = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $thermalMachineId, $restockerId): void {
        $insertCash($pgPdo, 'MCC-2026-0015', 'BAG-CCV-000015', $thermalMachineId, $restockerId);
    });
    $assert(
        'Una máquina con ticket TEMPERATURE_COLD vivo se rechaza con 45000',
        $thermalBlock !== null && $thermalBlock['state'] === '45000'
            && str_contains($thermalBlock['message'], SANITARY_BLOCK_MARKER),
        $thermalBlock === null ? 'la avería térmica no bloqueó' : 'estado=' . $thermalBlock['state']
    );

    $completedVisitId = $createVisit(
        $pgPdo,
        $machineId,
        $restockerId,
        'MRV-2026-0001',
        'COMPLETED',
        3.0,
        false,
        null,
        'Visita completada de certificación del vínculo con la recaudación.'
    );

    $abortedVisitId = $createVisit(
        $pgPdo,
        $machineId,
        $restockerId,
        'MRV-2026-0002',
        'ABORTED_THERMAL_BREACH',
        12.0,
        true,
        'https://evidencias.gestion-vending.test/ccv/aborto-termico.jpg',
        'Aborto por rotura de cadena de frío detectada durante la visita de certificación.'
    );

    $abortedCash = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $abortedVisitId): void {
        $insertCash($pgPdo, 'MCC-2026-0016', 'BAG-CCV-000016', $machineId, $restockerId, [
            'restock_visit_id' => $abortedVisitId,
        ]);
    });
    $assert(
        'Una visita abortada por rotura térmica no admite recaudación (45000)',
        $abortedCash !== null && $abortedCash['state'] === '45000'
            && str_contains($abortedCash['message'], 'ART_II_THERMAL_ABORT_CASH_FORBIDDEN'),
        $abortedCash === null ? 'la visita abortada admitió efectivo' : json_encode([
            'state' => $abortedCash['state'],
            'message' => mb_substr($abortedCash['message'], 0, 80),
        ])
    );

    $foreignVisitId = $createVisit(
        $pgPdo,
        $closedSiteMachineId,
        $restockerId,
        'MRV-2026-0003',
        'COMPLETED',
        3.0,
        false,
        null,
        'Visita completada en otra máquina para certificar el desajuste de vínculo.'
    );

    $mismatchedVisit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $foreignVisitId): void {
        $insertCash($pgPdo, 'MCC-2026-0017', 'BAG-CCV-000017', $machineId, $restockerId, [
            'restock_visit_id' => $foreignVisitId,
        ]);
    });
    $assert(
        'Una visita de otra máquina se rechaza con 45000',
        $mismatchedVisit !== null && $mismatchedVisit['state'] === '45000'
            && str_contains($mismatchedVisit['message'], 'ART_II_CASH_COLLECTION_VISIT_MACHINE_MISMATCH'),
        $mismatchedVisit === null ? 'el vínculo cruzado se admitió' : 'estado=' . $mismatchedVisit['state']
    );

    $linkedId = $insertCash($pgPdo, 'MCC-2026-0018', 'BAG-CCV-000018', $machineId, $restockerId, [
        'restock_visit_id' => $completedVisitId,
    ]);
    $assert('La recaudación vinculada a su visita completada se persiste (D1)', $linkedId > 0);

    $duplicateVisit = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $completedVisitId): void {
        $insertCash($pgPdo, 'MCC-2026-0019', 'BAG-CCV-000019', $machineId, $restockerId, [
            'restock_visit_id' => $completedVisitId,
        ]);
    });
    $assert(
        'Una segunda recaudación sobre la misma visita se rechaza con 23505 (D1)',
        $duplicateVisit !== null && $duplicateVisit['state'] === '23505',
        $duplicateVisit === null ? 'la visita admitió dos recaudaciones' : 'estado=' . $duplicateVisit['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Ciclo de custodia y arqueo terminal (D4, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Ciclo de custodia y arqueo terminal ---\n";

    $deliveredId = $insertCash($pgPdo, 'MCC-2026-0020', 'BAG-CCV-000020', $machineId, $restockerId, [
        'status' => 'DELIVERED_TO_VAULT',
        'delivered_to_vault_at' => '2026-11-10 12:00:00+00',
        'delivered_to_vault_by' => $coordinatorId,
    ]);
    $assert('La entrega en Caja Central se persiste con su marca y su actor', $deliveredId > 0);

    $badDeliveryOrder = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $coordinatorId): void {
        $insertCash($pgPdo, 'MCC-2026-0021', 'BAG-CCV-000021', $machineId, $restockerId, [
            'status' => 'DELIVERED_TO_VAULT',
            'delivered_to_vault_at' => '2026-11-10 09:00:00+00',
            'delivered_to_vault_by' => $coordinatorId,
        ]);
    });
    $assert(
        'Una entrega anterior a la recaudación se rechaza con 23514',
        $badDeliveryOrder !== null && $badDeliveryOrder['state'] === '23514'
            && str_contains($badDeliveryOrder['message'], 'chk_machine_cash_collections_vault_lifecycle'),
        $badDeliveryOrder === null ? 'la entrega retrocedió en el tiempo' : 'estado=' . $badDeliveryOrder['state']
    );

    $reconciledOkId = $insertCash($pgPdo, 'MCC-2026-0022', 'BAG-CCV-000022', $machineId, $restockerId, [
        'status' => 'RECONCILED_OK',
        'delivered_to_vault_at' => '2026-11-10 12:00:00+00',
        'delivered_to_vault_by' => $coordinatorId,
        'vault_counted_eur' => '26.00',
        'discrepancy_eur' => '0.50',
        'vault_reconciled_by' => $coordinatorId,
        'vault_reconciled_by_name_snapshot' => 'Coordinadora de Caja',
        'vault_reconciled_at' => '2026-11-10 13:00:00+00',
    ]);
    $assert('El arqueo dentro de la tolerancia de ±1,00 € se sella como RECONCILED_OK', $reconciledOkId > 0);

    $outOfTolerance = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $coordinatorId): void {
        $insertCash($pgPdo, 'MCC-2026-0023', 'BAG-CCV-000023', $machineId, $restockerId, [
            'status' => 'RECONCILED_OK',
            'delivered_to_vault_at' => '2026-11-10 12:00:00+00',
            'delivered_to_vault_by' => $coordinatorId,
            'vault_counted_eur' => '27.50',
            'discrepancy_eur' => '2.00',
            'vault_reconciled_by' => $coordinatorId,
            'vault_reconciled_by_name_snapshot' => 'Coordinadora de Caja',
            'vault_reconciled_at' => '2026-11-10 13:00:00+00',
        ]);
    });
    $assert(
        'Un arqueo cuadrado por encima de la tolerancia se rechaza con 23514',
        $outOfTolerance !== null && $outOfTolerance['state'] === '23514'
            && str_contains($outOfTolerance['message'], 'chk_machine_cash_collections_vault_lifecycle'),
        $outOfTolerance === null ? 'el descuadre se cuadró sin justificar' : 'estado=' . $outOfTolerance['state']
    );

    $flaggedWithoutJustification = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $insertCash, $machineId, $restockerId, $coordinatorId): void {
        $insertCash($pgPdo, 'MCC-2026-0024', 'BAG-CCV-000024', $machineId, $restockerId, [
            'status' => 'DISCREPANCY_FLAGGED',
            'delivered_to_vault_at' => '2026-11-10 12:00:00+00',
            'delivered_to_vault_by' => $coordinatorId,
            'vault_counted_eur' => '20.00',
            'discrepancy_eur' => '-5.50',
            'vault_reconciled_by' => $coordinatorId,
            'vault_reconciled_by_name_snapshot' => 'Coordinadora de Caja',
            'vault_reconciled_at' => '2026-11-10 13:00:00+00',
        ]);
    });
    $assert(
        'Un descuadre sin justificación real se rechaza con 23514 (Art. V.1)',
        $flaggedWithoutJustification !== null && $flaggedWithoutJustification['state'] === '23514'
            && str_contains($flaggedWithoutJustification['message'], 'chk_machine_cash_collections_vault_lifecycle'),
        $flaggedWithoutJustification === null ? 'el descuadre pasó sin justificar' : 'estado=' . $flaggedWithoutJustification['state']
    );

    $flaggedId = $insertCash($pgPdo, 'MCC-2026-0025', 'BAG-CCV-000025', $machineId, $restockerId, [
        'status' => 'DISCREPANCY_FLAGGED',
        'delivered_to_vault_at' => '2026-11-10 12:00:00+00',
        'delivered_to_vault_by' => $coordinatorId,
        'vault_counted_eur' => '20.00',
        'discrepancy_eur' => '-5.50',
        'vault_reconciled_by' => $coordinatorId,
        'vault_reconciled_by_name_snapshot' => 'Coordinadora de Caja',
        'vault_reconciled_at' => '2026-11-10 13:00:00+00',
        'discrepancy_justification' => 'Faltan cinco euros y cincuenta céntimos en la bolsa precintada.',
    ]);
    $assert('El descuadre con justificación real se sella como DISCREPANCY_FLAGGED', $flaggedId > 0);

    // ─────────────────────────────────────────────────────────────────────
    // 9. Inmutabilidad, bloqueo terminal y ventana de purga (Art. III.1 y III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Inmutabilidad, bloqueo terminal y ventana de purga ---\n";

    $routeRewrite = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $sealedId): void {
        $stmt = $pgPdo->prepare('UPDATE machine_cash_collections SET bag_seal_code = :seal WHERE id = :id');
        $stmt->execute([':seal' => 'BAG-CCV-999999', ':id' => $sealedId]);
    });
    $assert(
        'El UPDATE de la captura en ruta se rechaza con 45000 (Art. III.3)',
        $routeRewrite !== null && $routeRewrite['state'] === '45000'
            && str_contains($routeRewrite['message'], ROUTE_MUTATION_MARKER),
        $routeRewrite === null ? 'el precinto se dejó reescribir' : 'estado=' . $routeRewrite['state']
    );

    $terminalRewrite = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $reconciledOkId): void {
        $stmt = $pgPdo->prepare('UPDATE machine_cash_collections SET route_notes = :notes WHERE id = :id');
        $stmt->execute([':notes' => 'Reescritura de una recaudación ya arqueada.', ':id' => $reconciledOkId]);
    });
    $assert(
        'El UPDATE de una fila ya arqueada se rechaza con 45000 (Art. III.3)',
        $terminalRewrite !== null && $terminalRewrite['state'] === '45000'
            && str_contains($terminalRewrite['message'], TERMINAL_MUTATION_MARKER),
        $terminalRewrite === null ? 'el arqueo terminal se dejó reescribir' : 'estado=' . $terminalRewrite['state']
    );

    $stateRegression = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $deliveredId): void {
        $stmt = $pgPdo->prepare('UPDATE machine_cash_collections SET status = \'SEALED_IN_ROUTE\' WHERE id = :id');
        $stmt->execute([':id' => $deliveredId]);
    });
    $assert(
        'Una bolsa entregada no puede regresar a SEALED_IN_ROUTE (45000)',
        $stateRegression !== null && $stateRegression['state'] === '45000'
            && str_contains($stateRegression['message'], 'ART_III_3_INVALID_CASH_STATE_REGRESSION'),
        $stateRegression === null ? 'el estado retrocedió' : 'estado=' . $stateRegression['state']
    );

    $deleteWithoutWindow = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $sealedId): void {
        $stmt = $pgPdo->prepare('DELETE FROM machine_cash_collections WHERE id = :id');
        $stmt->execute([':id' => $sealedId]);
    });
    $assert(
        'El DELETE sin ventana de purga se rechaza con 45000 (Art. III.1)',
        $deleteWithoutWindow !== null && $deleteWithoutWindow['state'] === '45000'
            && str_contains($deleteWithoutWindow['message'], PURGE_VIOLATION_MARKER),
        $deleteWithoutWindow === null ? 'el borrado físico pasó' : 'estado=' . $deleteWithoutWindow['state']
    );

    $purgedRows = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $sealedId, $flaggedId): array {
        $deleted = [];
        foreach (['sealed' => $sealedId, 'flagged' => $flaggedId] as $label => $id) {
            $stmt = $pgPdo->prepare('DELETE FROM machine_cash_collections WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $deleted[$label] = $stmt->rowCount();
        }

        return $deleted;
    });
    $assert(
        'Dentro de la ventana de purga del arnés el borrado de la recaudación funciona',
        $purgedRows === ['sealed' => 1, 'flagged' => 1],
        json_encode($purgedRows)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, CASH_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query("SELECT to_regclass('" . CASH_TEST_SCHEMA . ".machine_cash_collections')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y su Tabla 49 ya no existen en el motor',
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
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DE LA RECAUDACIÓN CERTIFICADO (T-CCV-02).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
