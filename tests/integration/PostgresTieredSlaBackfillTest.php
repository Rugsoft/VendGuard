<?php

declare(strict_types=1);

use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/**
 * PostgresTieredSlaBackfillTest · T-SLA-01 / H-03
 *
 * Certifica el backfill de datos preexistentes de la migración
 * `026_tiered_sla_contracts.sql` sobre PostgreSQL real.
 *
 *   1. **Esquema previo a 026.** Se aplica el esquema del repositorio y se
 *      retiran los artefactos que introduce la migración: el tipo `sla_tier`,
 *      las columnas de perfil y sellado, la restricción, los índices y el
 *      disparador. Se siembran sedes, máquinas y expedientes históricos antes
 *      de aplicar la migración, como en una base que ya operaba.
 *
 *   2. **Cerrados, resueltos y cancelados.** Conservan `240` min cuando la
 *      máquina es perecedera o COMBO con riesgo térmico/crítico, y `1440` min
 *      en el resto. `sla_target_at` solo se rellena si faltaba; el resto de
 *      indicadores históricos no cambia.
 *
 *   3. **Abiertos.** Reciben la matriz RF-01 bajo el perfil `STANDARD` que la
 *      migración asigna a las sedes existentes y desplazan su vencimiento por
 *      los segundos de pausa ya acumulados.
 *
 *   4. **Matriz completa RF-01.** Un segundo escenario, con los perfiles de
 *      sede ya declarados y el sellado de expedientes todavía ausente, cubre
 *      las 12 celdas `SlaTier × UrgencyLevel` y el techo sanitario de 240 min
 *      en los tres perfiles.
 *
 * CATEGORÍA: `new_engine`
 *   Exige PostgreSQL a través de `PG_TEST_DSN`, sin valor por defecto. Sin
 *   motor, la suite se declara no ejecutable con código 2 y no se omite.
 *
 * LIMPIEZA: cada escenario vive en un esquema aislado que se borra con
 * `CASCADE` en el `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresTieredSlaBackfillTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

/** Marca de categoría: `run_all.php` la lee para separar esta suite del canal hermético. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema del escenario histórico fielmente anterior a la migración 026. */
const BACKFILL_HISTORY_SCHEMA = 't_sla30_backfill_history';

/** Esquema del escenario de matriz completa, con perfiles de sede ya declarados. */
const BACKFILL_MATRIX_SCHEMA = 't_sla30_backfill_matrix';

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
echo " VENDGUARD · T-SLA-01 / H-03 · Backfill histórico y matriz RF-01\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$applySqlFile = static function (PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("No se pudo leer el script SQL: {$path}");
    }

    foreach (SqlScriptSplitter::split($sql) as $statement) {
        $pdo->exec($statement);
    }
};

/**
 * Retira los artefactos de la 026 para reconstruir el estado anterior.
 *
 * `$dropLocationTier` distingue los dos escenarios: el histórico elimina también
 * el perfil de sede; el de matriz lo conserva para poder sembrar los tres
 * perfiles antes de que la migración selle los expedientes.
 */
$dropTieredSlaArtifacts = static function (PDO $pdo, string $schema, bool $dropLocationTier): void {
    $pdo->exec('DROP TRIGGER IF EXISTS trg_incidents_protect_sla_tier_snapshot ON incidents');
    $pdo->exec("DROP FUNCTION IF EXISTS {$schema}.trg_fn_incidents_protect_sla_tier_snapshot()");
    $pdo->exec('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS chk_incidents_sla_target_minutes');
    $pdo->exec("DROP INDEX IF EXISTS {$schema}.idx_incidents_sla_target_lookup");
    $pdo->exec('ALTER TABLE incidents DROP COLUMN IF EXISTS sla_tier_snapshot, DROP COLUMN IF EXISTS sla_target_minutes');

    if (!$dropLocationTier) {
        return;
    }

    $pdo->exec("DROP INDEX IF EXISTS {$schema}.idx_locations_sla_tier");
    $pdo->exec('ALTER TABLE locations DROP COLUMN IF EXISTS sla_tier');
    $pdo->exec("DROP TYPE IF EXISTS {$schema}.sla_tier");
};

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);

    return (int)$stmt->fetchColumn() > 0;
};

$typeExists = static function (PDO $pdo, string $type): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM pg_type t
           JOIN pg_namespace n ON n.oid = t.typnamespace
          WHERE n.nspname = current_schema() AND t.typname = :type'
    );
    $stmt->execute([':type' => $type]);

    return (int)$stmt->fetchColumn() > 0;
};

$insertLocation = static function (PDO $pdo, string $suffix, ?string $tier = null): array {
    $location = PostgresFixtureBuilder::insertMinimumRow($pdo, 'locations', [], $suffix);

    if ($tier !== null) {
        $stmt = $pdo->prepare('UPDATE locations SET sla_tier = :tier WHERE id = :id');
        $stmt->execute([':tier' => $tier, ':id' => $location['id']]);
        $location['sla_tier'] = $tier;
    }

    return $location;
};

$insertMachine = static function (PDO $pdo, array $location, string $suffix, string $machineType): array {
    $machine = PostgresFixtureBuilder::insertMinimumRow(
        $pdo,
        'machines',
        ['locations' => $location],
        $suffix
    );

    $stmt = $pdo->prepare('UPDATE machines SET machine_type = :type WHERE id = :id');
    $stmt->execute([':type' => $machineType, ':id' => $machine['id']]);
    $machine['machine_type'] = $machineType;

    return $machine;
};

/**
 * Siembra un expediente con un alta mínima y lo lleva al estado histórico pedido.
 *
 * El alta mínima es la del arnés: deriva las columnas obligatorias del catálogo
 * vivo y respeta las claves foráneas. Después se fijan los hechos del caso.
 *
 * @param array<string, mixed> $case
 * @param array<string, mixed> $location
 * @return array<string, mixed>
 */
$insertIncidentCase = static function (PDO $pdo, array $case, array $location) use ($insertMachine): array {
    $machine = $insertMachine($pdo, $location, (string)$case['suffix'], (string)$case['machine_type']);
    $incident = PostgresFixtureBuilder::insertMinimumRow(
        $pdo,
        'incidents',
        ['locations' => $location, 'machines' => $machine],
        (string)$case['suffix']
    );

    $createdAt = new DateTimeImmutable((string)$case['created_at']);
    $isClosed = in_array((string)$case['status'], ['RESOLVED', 'CLOSED', 'CANCELLED'], true);
    $isCancelled = (string)$case['status'] === 'CANCELLED';
    $resolvedAt = $isClosed && !$isCancelled
        ? $createdAt->modify('+2 hours')->format('Y-m-d H:i:sP')
        : null;
    $closedAt = (string)$case['status'] === 'CLOSED'
        ? $createdAt->modify('+3 hours')->format('Y-m-d H:i:sP')
        : null;
    $cancelledAt = $isCancelled
        ? $createdAt->modify('+1 hour')->format('Y-m-d H:i:sP')
        : null;

    $stmt = $pdo->prepare(
        'UPDATE incidents
            SET status = :status,
                category = :category,
                urgency = :urgency,
                created_at = :created_at,
                total_pending_info_seconds = :pending_seconds,
                sla_target_at = :existing_target,
                resolution_diagnosis = :diagnosis,
                resolution_action = :action,
                resolved_at = :resolved_at,
                closed_at = :closed_at,
                cancelled_at = :cancelled_at,
                cancellation_reason = :cancellation_reason
          WHERE id = :id
          RETURNING *'
    );
    $stmt->execute([
        ':status' => $case['status'],
        ':category' => $case['category'],
        ':urgency' => $case['urgency'],
        ':created_at' => $case['created_at'],
        ':pending_seconds' => $case['pending_seconds'],
        ':existing_target' => $case['existing_target'],
        ':diagnosis' => $isClosed && !$isCancelled
            ? 'Diagnóstico histórico de la avería certificada.'
            : null,
        ':action' => $isClosed && !$isCancelled
            ? 'Reparación histórica ejecutada y verificada.'
            : null,
        ':resolved_at' => $resolvedAt,
        ':closed_at' => $closedAt,
        ':cancelled_at' => $cancelledAt,
        ':cancellation_reason' => $isCancelled
            ? 'Cancelación histórica registrada antes de la migración.'
            : null,
        ':id' => $incident['id'],
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('El motor no devolvió el expediente histórico sembrado.');
    }

    return $row;
};

$fetchIncident = static function (PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        throw new RuntimeException("No se encontró el expediente {$id}.");
    }

    return $row;
};

$assertTargetAt = static function (PDO $pdo, array $incident, int $minutes, int $pendingSeconds) use ($assert): void {
    $id = (int)$incident['id'];
    $ok = $pdo->query(sprintf(
        'SELECT CASE WHEN sla_target_at = created_at + make_interval(mins => %d) + make_interval(secs => %d)
                     THEN 1 ELSE 0 END AS ok
           FROM incidents WHERE id = %d',
        $minutes,
        $pendingSeconds,
        $id
    ))->fetchColumn();

    $assert(
        "El vencimiento del expediente {$id} se calcula desde created_at y las pausas",
        (int)$ok === 1,
        sprintf('objetivo %d min · pausas %d s', $minutes, $pendingSeconds)
    );
};

$historicalFields = 'id, ticket_code, machine_id, location_id, category, description, urgency, status, '
    . 'cycle_started_at, assigned_at, started_at, resolved_at, closed_at, cancelled_at, '
    . 'total_pending_info_seconds, created_at, resolution_diagnosis, resolution_action, cancellation_reason';

try {
    echo "\n--- Escenario 1 · esquema previo a 026 y datos históricos ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, BACKFILL_HISTORY_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $dropTieredSlaArtifacts($pgPdo, BACKFILL_HISTORY_SCHEMA, true);

    $assert(
        'El escenario reconstruye el esquema anterior a 026: sin tipo sla_tier ni columnas de sellado',
        !$typeExists($pgPdo, 'sla_tier')
        && !$columnExists($pgPdo, 'locations', 'sla_tier')
        && !$columnExists($pgPdo, 'incidents', 'sla_tier_snapshot')
        && !$columnExists($pgPdo, 'incidents', 'sla_target_minutes'),
        'tipo y columnas de la 026 ausentes'
    );

    $locations = [
        $insertLocation($pgPdo, 'H01'),
        $insertLocation($pgPdo, 'H02'),
        $insertLocation($pgPdo, 'H03'),
    ];

    $closedCases = [
        [
            'key' => 'closed_perishable_low',
            'suffix' => 'HC01',
            'machine_type' => 'PERISHABLE_FOOD',
            'status' => 'CLOSED',
            'category' => 'PAYMENT_SYSTEM',
            'urgency' => 'LOW',
            'created_at' => '2026-09-01 08:00:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-09-10 10:00:00+00',
            'expected_minutes' => 240,
        ],
        [
            'key' => 'closed_perishable_critical',
            'suffix' => 'HC02',
            'machine_type' => 'PERISHABLE_FOOD',
            'status' => 'RESOLVED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'CRITICAL',
            'created_at' => '2026-09-02 09:15:00+00',
            'pending_seconds' => 0,
            'existing_target' => null,
            'expected_minutes' => 240,
        ],
        [
            'key' => 'closed_combo_cold',
            'suffix' => 'HC03',
            'machine_type' => 'COMBO',
            'status' => 'CANCELLED',
            'category' => 'TEMPERATURE_COLD',
            'urgency' => 'LOW',
            'created_at' => '2026-09-03 10:30:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-09-12 12:00:00+00',
            'expected_minutes' => 240,
        ],
        [
            'key' => 'closed_combo_critical',
            'suffix' => 'HC04',
            'machine_type' => 'COMBO',
            'status' => 'CLOSED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'CRITICAL',
            'created_at' => '2026-09-04 11:45:00+00',
            'pending_seconds' => 1800,
            'existing_target' => null,
            'expected_minutes' => 240,
        ],
        [
            'key' => 'closed_combo_high',
            'suffix' => 'HC05',
            'machine_type' => 'COMBO',
            'status' => 'CLOSED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'HIGH',
            'created_at' => '2026-09-05 12:00:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-09-15 12:00:00+00',
            'expected_minutes' => 1440,
        ],
        [
            'key' => 'closed_snacks_medium',
            'suffix' => 'HC06',
            'machine_type' => 'SNACKS',
            'status' => 'RESOLVED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'MEDIUM',
            'created_at' => '2026-09-06 13:15:00+00',
            'pending_seconds' => 3600,
            'existing_target' => null,
            'expected_minutes' => 1440,
        ],
        [
            'key' => 'closed_cold_drinks_low',
            'suffix' => 'HC07',
            'machine_type' => 'COLD_DRINKS',
            'status' => 'CANCELLED',
            'category' => 'ELECTRICAL_OFF',
            'urgency' => 'LOW',
            'created_at' => '2026-09-07 14:30:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-09-17 14:30:00+00',
            'expected_minutes' => 1440,
        ],
    ];

    $openCases = [
        [
            'key' => 'open_standard_critical',
            'suffix' => 'HO01',
            'machine_type' => 'SNACKS',
            'status' => 'REGISTERED',
            'category' => 'PAYMENT_SYSTEM',
            'urgency' => 'CRITICAL',
            'created_at' => '2026-09-10 08:00:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-01 00:00:00+00',
            'expected_minutes' => 480,
        ],
        [
            'key' => 'open_standard_high',
            'suffix' => 'HO02',
            'machine_type' => 'HOT_DRINKS',
            'status' => 'ASSIGNED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'HIGH',
            'created_at' => '2026-09-11 09:15:00+00',
            'pending_seconds' => 3600,
            'existing_target' => '2026-01-02 00:00:00+00',
            'expected_minutes' => 720,
        ],
        [
            'key' => 'open_standard_medium',
            'suffix' => 'HO03',
            'machine_type' => 'COLD_DRINKS',
            'status' => 'IN_PROGRESS',
            'category' => 'OTHER',
            'urgency' => 'MEDIUM',
            'created_at' => '2026-09-12 10:30:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-03 00:00:00+00',
            'expected_minutes' => 1440,
        ],
        [
            'key' => 'open_standard_low',
            'suffix' => 'HO04',
            'machine_type' => 'SNACKS',
            'status' => 'PENDING_INFO',
            'category' => 'ELECTRICAL_OFF',
            'urgency' => 'LOW',
            'created_at' => '2026-09-13 11:45:00+00',
            'pending_seconds' => 7200,
            'existing_target' => '2026-01-04 00:00:00+00',
            'expected_minutes' => 1440,
        ],
        [
            'key' => 'open_perishable_low',
            'suffix' => 'HO05',
            'machine_type' => 'PERISHABLE_FOOD',
            'status' => 'REGISTERED',
            'category' => 'PAYMENT_SYSTEM',
            'urgency' => 'LOW',
            'created_at' => '2026-09-14 12:00:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-05 00:00:00+00',
            'expected_minutes' => 240,
        ],
        [
            'key' => 'open_combo_cold',
            'suffix' => 'HO06',
            'machine_type' => 'COMBO',
            'status' => 'REGISTERED',
            'category' => 'TEMPERATURE_COLD',
            'urgency' => 'MEDIUM',
            'created_at' => '2026-09-15 13:15:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-06 00:00:00+00',
            'expected_minutes' => 240,
        ],
        [
            'key' => 'open_combo_critical',
            'suffix' => 'HO07',
            'machine_type' => 'COMBO',
            'status' => 'REGISTERED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'CRITICAL',
            'created_at' => '2026-09-16 14:30:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-07 00:00:00+00',
            'expected_minutes' => 240,
        ],
        [
            'key' => 'open_combo_high',
            'suffix' => 'HO08',
            'machine_type' => 'COMBO',
            'status' => 'REGISTERED',
            'category' => 'PRODUCT_JAM',
            'urgency' => 'HIGH',
            'created_at' => '2026-09-17 15:45:00+00',
            'pending_seconds' => 0,
            'existing_target' => '2026-01-08 00:00:00+00',
            'expected_minutes' => 720,
        ],
    ];

    $allCases = [];
    foreach (array_merge($closedCases, $openCases) as $index => $case) {
        $allCases[$case['key']] = $insertIncidentCase($pgPdo, $case, $locations[$index % count($locations)]);
    }

    $allIds = array_map(static fn (array $row): int => (int)$row['id'], $allCases);
    $idsLiteral = implode(', ', $allIds);
    $beforeBackfill = $pgPdo->query(
        "SELECT {$historicalFields} FROM incidents WHERE id IN ({$idsLiteral}) ORDER BY id"
    )->fetchAll();

    echo "\n--- Escenario 1 · aplicación de 026 y preservación histórica ---\n";

    $applySqlFile($pgPdo, $projectRoot . '/database/supabase_migrations/026_tiered_sla_contracts.sql');

    $tierTypePresent = $typeExists($pgPdo, 'sla_tier');
    $locationTierPresent = $columnExists($pgPdo, 'locations', 'sla_tier');
    $snapshotPresent = $columnExists($pgPdo, 'incidents', 'sla_tier_snapshot');
    $targetMinutesPresent = $columnExists($pgPdo, 'incidents', 'sla_target_minutes');
    $constraintCount = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM pg_constraint
          WHERE conrelid = 'incidents'::regclass AND conname = 'chk_incidents_sla_target_minutes'"
    )->fetchColumn();
    $triggerCount = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM pg_trigger
          WHERE tgrelid = 'incidents'::regclass
            AND tgname = 'trg_incidents_protect_sla_tier_snapshot'
            AND NOT tgisinternal"
    )->fetchColumn();
    $assert(
        'La 026 queda aplicada con su tipo, columnas, restricción y disparador',
        $tierTypePresent
        && $locationTierPresent
        && $snapshotPresent
        && $targetMinutesPresent
        && $constraintCount === 1
        && $triggerCount === 1,
        sprintf(
            'tipo=%s sede=%s snapshot=%s objetivo=%s restricción=%d disparador=%d',
            $tierTypePresent ? 'sí' : 'no',
            $locationTierPresent ? 'sí' : 'no',
            $snapshotPresent ? 'sí' : 'no',
            $targetMinutesPresent ? 'sí' : 'no',
            $constraintCount,
            $triggerCount
        )
    );

    $standardLocations = $pgPdo->query(
        "SELECT COUNT(*) FROM locations WHERE sla_tier = 'STANDARD'"
    )->fetchColumn();
    $assert(
        'Todas las sedes preexistentes reciben el perfil STANDARD',
        (int)$standardLocations === count($locations),
        "sedes STANDARD: {$standardLocations} de " . count($locations)
    );

    foreach ($closedCases as $case) {
        $incident = $fetchIncident($pgPdo, (int)$allCases[$case['key']]['id']);

        $assert(
            "Cerrado {$case['key']}: objetivo histórico de {$case['expected_minutes']} min y perfil STANDARD",
            $incident['sla_tier_snapshot'] === 'STANDARD'
            && (int)$incident['sla_target_minutes'] === $case['expected_minutes'],
            'perfil ' . (string)$incident['sla_tier_snapshot'] . ' · objetivo ' . (string)$incident['sla_target_minutes']
        );

        if ($case['existing_target'] !== null) {
            $preserved = $pgPdo->prepare(
                'SELECT CASE WHEN sla_target_at = :expected THEN 1 ELSE 0 END AS ok
                   FROM incidents WHERE id = :id'
            );
            $preserved->execute([':expected' => $case['existing_target'], ':id' => $incident['id']]);
            $assert(
                "Cerrado {$case['key']}: conserva el vencimiento histórico que ya tenía",
                (int)$preserved->fetchColumn() === 1,
                'esperado: ' . (string)$case['existing_target']
            );
        } else {
            $assertTargetAt($pgPdo, $incident, (int)$case['expected_minutes'], (int)$case['pending_seconds']);
        }
    }

    foreach ($openCases as $case) {
        $incident = $fetchIncident($pgPdo, (int)$allCases[$case['key']]['id']);

        $assert(
            "Abierto {$case['key']}: matriz STANDARD de {$case['expected_minutes']} min y perfil STANDARD",
            $incident['sla_tier_snapshot'] === 'STANDARD'
            && (int)$incident['sla_target_minutes'] === $case['expected_minutes'],
            'perfil ' . (string)$incident['sla_tier_snapshot'] . ' · objetivo ' . (string)$incident['sla_target_minutes']
        );
        $assertTargetAt($pgPdo, $incident, (int)$case['expected_minutes'], (int)$case['pending_seconds']);
    }

    $afterBackfill = $pgPdo->query(
        "SELECT {$historicalFields} FROM incidents WHERE id IN ({$idsLiteral}) ORDER BY id"
    )->fetchAll();

    $assert(
        'El backfill no altera created_at, estados, diagnósticos, cierres ni pausas históricas',
        $beforeBackfill === $afterBackfill,
        sprintf('%d expedientes comparados antes y después', count($beforeBackfill))
    );

    $assert(
        'Los expedientes conservan los segundos de pausa acumulados',
        (int)$pgPdo->query(
            "SELECT total_pending_info_seconds FROM incidents WHERE id = {$allCases['open_standard_low']['id']}"
        )->fetchColumn() === 7200
    );
} catch (Throwable $exception) {
    $failures++;
    echo "\n  [FAIL] El escenario histórico se interrumpió: " . $exception->getMessage() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, BACKFILL_HISTORY_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $failures++;
        echo "\n  [FAIL] No se pudo retirar el esquema histórico: " . $cleanupFailure->getMessage() . "\n";
    }

    $historyResidue = $pgPdo->query(
        "SELECT to_regclass('" . BACKFILL_HISTORY_SCHEMA . ".incidents')"
    )->fetchColumn();
    $assert(
        'El escenario histórico no deja tablas en el motor',
        $historyResidue === null,
        'residuo: ' . json_encode($historyResidue)
    );
}

try {
    echo "\n--- Escenario 2 · matriz RF-01 completa con perfiles de sede ya declarados ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, BACKFILL_MATRIX_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $dropTieredSlaArtifacts($pgPdo, BACKFILL_MATRIX_SCHEMA, false);

    $assert(
        'El escenario conserva el perfil de sede y retira solo el sellado de expedientes',
        $typeExists($pgPdo, 'sla_tier')
        && $columnExists($pgPdo, 'locations', 'sla_tier')
        && !$columnExists($pgPdo, 'incidents', 'sla_tier_snapshot')
        && !$columnExists($pgPdo, 'incidents', 'sla_target_minutes'),
        'perfil de sede presente · sellado de incidents ausente'
    );

    $tierLocations = [];
    foreach (['STANDARD', 'PRIORITY_CORPORATE', 'CRITICAL_INFRASTRUCTURE_24_7'] as $index => $tier) {
        $tierLocations[$tier] = $insertLocation($pgPdo, 'M' . ($index + 1), $tier);
    }

    $matrix = [
        'STANDARD' => ['CRITICAL' => 480, 'HIGH' => 720, 'MEDIUM' => 1440, 'LOW' => 1440],
        'PRIORITY_CORPORATE' => ['CRITICAL' => 360, 'HIGH' => 480, 'MEDIUM' => 720, 'LOW' => 720],
        'CRITICAL_INFRASTRUCTURE_24_7' => ['CRITICAL' => 240, 'HIGH' => 240, 'MEDIUM' => 480, 'LOW' => 480],
    ];

    $matrixCases = [];
    $caseCounter = 0;

    foreach ($matrix as $tier => $targets) {
        foreach ($targets as $urgency => $minutes) {
            $caseCounter++;
            $case = [
                'key' => "matrix_{$tier}_{$urgency}",
                'suffix' => sprintf('MX%02d', $caseCounter),
                'machine_type' => 'SNACKS',
                'status' => 'REGISTERED',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => $urgency,
                'created_at' => sprintf('2026-10-%02d 08:00:00+00', 1 + $caseCounter),
                'pending_seconds' => 0,
                'existing_target' => '2026-01-01 00:00:00+00',
                'expected_minutes' => $minutes,
                'tier' => $tier,
            ];
            $matrixCases[$case['key']] = [
                'id' => (int)$insertIncidentCase($pgPdo, $case, $tierLocations[$tier])['id'],
                'case' => $case,
            ];
        }
    }

    $coldChainCases = [
        ['machine_type' => 'PERISHABLE_FOOD', 'category' => 'PAYMENT_SYSTEM', 'urgency' => 'LOW'],
        ['machine_type' => 'COMBO', 'category' => 'TEMPERATURE_COLD', 'urgency' => 'MEDIUM'],
        ['machine_type' => 'COMBO', 'category' => 'PRODUCT_JAM', 'urgency' => 'CRITICAL'],
    ];

    foreach (array_keys($matrix) as $tier) {
        foreach ($coldChainCases as $coldCase) {
            $caseCounter++;
            $case = [
                'key' => "cold_{$tier}_{$coldCase['machine_type']}_{$coldCase['category']}",
                'suffix' => sprintf('MX%02d', $caseCounter),
                'machine_type' => $coldCase['machine_type'],
                'status' => 'REGISTERED',
                'category' => $coldCase['category'],
                'urgency' => $coldCase['urgency'],
                'created_at' => sprintf('2026-10-%02d 09:00:00+00', 1 + $caseCounter),
                'pending_seconds' => 0,
                'existing_target' => '2026-01-01 00:00:00+00',
                'expected_minutes' => 240,
                'tier' => $tier,
            ];
            $matrixCases[$case['key']] = [
                'id' => (int)$insertIncidentCase($pgPdo, $case, $tierLocations[$tier])['id'],
                'case' => $case,
            ];
        }
    }

    $applySqlFile($pgPdo, $projectRoot . '/database/supabase_migrations/026_tiered_sla_contracts.sql');

    foreach ($matrixCases as $entry) {
        $case = $entry['case'];
        $incident = $fetchIncident($pgPdo, $entry['id']);

        $assert(
            "Matriz {$case['tier']} / {$case['urgency']} / {$case['machine_type']}: {$case['expected_minutes']} min",
            $incident['sla_tier_snapshot'] === $case['tier']
            && (int)$incident['sla_target_minutes'] === $case['expected_minutes'],
            'perfil ' . (string)$incident['sla_tier_snapshot'] . ' · objetivo ' . (string)$incident['sla_target_minutes']
        );
        $assertTargetAt($pgPdo, $incident, (int)$case['expected_minutes'], (int)$case['pending_seconds']);
    }
} catch (Throwable $exception) {
    $failures++;
    echo "\n  [FAIL] El escenario de matriz se interrumpió: " . $exception->getMessage() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, BACKFILL_MATRIX_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $failures++;
        echo "\n  [FAIL] No se pudo retirar el esquema de matriz: " . $cleanupFailure->getMessage() . "\n";
    }

    $matrixResidue = $pgPdo->query(
        "SELECT to_regclass('" . BACKFILL_MATRIX_SCHEMA . ".incidents')"
    )->fetchColumn();
    $assert(
        'El escenario de matriz no deja tablas en el motor',
        $matrixResidue === null,
        'residuo: ' . json_encode($matrixResidue)
    );
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. H-03 (BACKFILL HISTÓRICO Y MATRIZ RF-01) CUMPLIDO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL BACKFILL HISTÓRICO.\n";
echo "======================================================================\n";
exit(1);
