<?php

declare(strict_types=1);

/**
 * PostgresTimeTrackingSchemaTest · T-TIME-02
 *
 * Certifica **en el motor real** el esquema del Módulo 22:
 *
 *   1. **Columnas aditivas de `technician_shifts`.** Las nueve columnas de cómputo,
 *      marcas originales e insignias de ajuste existen con el tipo y la nulabilidad
 *      que declara el contrato técnico §1.
 *   2. **Restricciones de coherencia.** `chk_tech_shifts_meal_seconds` rechaza una
 *      pausa negativa o superior a la duración bruta, y
 *      `chk_tech_shifts_adjustment_consistency` rechaza un ajuste cuyo motivo no
 *      alcanza 20 caracteres reales tras `btrim` (Art. V.1).
 *   3. **Bitácora append-only.** `technician_shift_logs` existe con sus columnas,
 *      claves foráneas, índices y los dos disparadores de inmutabilidad; `UPDATE`
 *      y `DELETE` se rechazan con SQLSTATE `45000` (Art. III.1 y III.3).
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`).
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresTimeTrackingSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite. */
const TIME_TRACKING_TEST_SCHEMA = 't_time02_time_tracking';

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

$assertRejection = static function (string $what, callable $operation, string $expectedState) use ($assert): void {
    $rejection = PostgresFixtureBuilder::expectRejection($operation);
    $assert(
        $what,
        $rejection !== null && $rejection['state'] === $expectedState,
        $rejection === null
            ? 'la operación fue admitida'
            : 'sqlstate=' . $rejection['state']
    );
};

echo "======================================================================\n";
echo " VENDGUARD · T-TIME-02 · Esquema de jornada y bitácora (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$crash = null;

try {
    // ──────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones 012 a 015 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, TIME_TRACKING_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y las migraciones aplican sin errores y el esquema converge',
        in_array('015_technician_time_tracking.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true
            && $convergence['pending'] === [],
        sprintf('%d sentencias · pendientes: %s', $schemaReport['statements'], json_encode($convergence['pending']))
    );

    // ──────────────────────────────────────────────────────────────────────
    // 1. Columnas aditivas de technician_shifts
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- technician_shifts: columnas de cómputo y marcas originales ---\n";

    $shiftColumns = PostgresFixtureBuilder::columns($pgPdo, 'technician_shifts');
    $expectedShiftColumns = [
        'meal_break_seconds' => ['udt' => 'int4', 'nullable' => false],
        'original_started_at' => ['udt' => 'timestamptz', 'nullable' => false],
        'original_closed_at' => ['udt' => 'timestamptz', 'nullable' => true],
        'original_meal_break_seconds' => ['udt' => 'int4', 'nullable' => true],
        'is_force_closed' => ['udt' => 'bool', 'nullable' => false],
        'is_adjusted' => ['udt' => 'bool', 'nullable' => false],
        'adjusted_at' => ['udt' => 'timestamptz', 'nullable' => true],
        'adjusted_by' => ['udt' => 'int8', 'nullable' => true],
        'adjustment_reason' => ['udt' => 'text', 'nullable' => true],
    ];

    $missingShiftColumns = [];
    foreach ($expectedShiftColumns as $column => $expected) {
        $meta = $shiftColumns[$column] ?? null;
        if ($meta === null
            || $meta['udt_name'] !== $expected['udt']
            || $meta['nullable'] !== $expected['nullable']
        ) {
            $missingShiftColumns[] = $column;
        }
    }

    $assert(
        'Las nueve columnas nuevas existen con el tipo y la nulabilidad del contrato',
        $missingShiftColumns === [],
        'divergentes: ' . (implode(', ', $missingShiftColumns) ?: 'ninguna')
    );
    $assert(
        'meal_break_seconds arranca en cero y original_started_at es obligatoria',
        ($shiftColumns['meal_break_seconds']['default'] ?? null) === '0'
            && ($shiftColumns['original_started_at']['default'] ?? null) !== null,
        sprintf(
            'meal_default=%s · original_started_default=%s',
            $shiftColumns['meal_break_seconds']['default'] ?? 'NULL',
            $shiftColumns['original_started_at']['default'] ?? 'NULL'
        )
    );

    $constraintStmt = $pgPdo->prepare(
        "SELECT conname, contype
           FROM pg_constraint
          WHERE conrelid = 'technician_shifts'::regclass
            AND conname IN ('fk_tech_shifts_adjuster', 'chk_tech_shifts_meal_seconds', 'chk_tech_shifts_adjustment_consistency')"
    );
    $constraintStmt->execute();
    $constraints = [];
    foreach ($constraintStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $constraints[(string)$row['conname']] = (string)$row['contype'];
    }

    $assert(
        'La FK del ajustador y las dos restricciones CHECK están declaradas',
        ($constraints['fk_tech_shifts_adjuster'] ?? null) === 'f'
            && ($constraints['chk_tech_shifts_meal_seconds'] ?? null) === 'c'
            && ($constraints['chk_tech_shifts_adjustment_consistency'] ?? null) === 'c',
        'encontradas: ' . json_encode($constraints)
    );

    // ──────────────────────────────────────────────────────────────────────
    // 2. Sujetos de prueba y restricciones de coherencia
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- technician_shifts: restricciones de pausa y ajuste ---\n";

    $insertUser = $pgPdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES (:name, :email, :email_key, 'hash', :role)
         RETURNING id"
    );
    $insertUser->execute([
        ':name' => 'Técnico T-TIME-02',
        ':email' => 'tech.time02@vending.test',
        ':email_key' => 'tech.time02@vending.test',
        ':role' => 'TECHNICIAN',
    ]);
    $technicianId = (int)$insertUser->fetchColumn();

    $insertUser->execute([
        ':name' => 'Coordinación T-TIME-02',
        ':email' => 'coord.time02@vending.test',
        ':email_key' => 'coord.time02@vending.test',
        ':role' => 'COORDINATOR',
    ]);
    $coordinatorId = (int)$insertUser->fetchColumn();

    $insertShift = $pgPdo->prepare(
        "INSERT INTO technician_shifts (
            technician_id, shift_date, status, started_at, closed_at, meal_break_seconds
         ) VALUES (
            :technician_id, DATE '2026-10-20', 'OFF_SHIFT',
            TIMESTAMPTZ '2026-10-20 08:00:00+02',
            TIMESTAMPTZ '2026-10-20 09:00:00+02',
            0
         ) RETURNING id"
    );
    $insertShift->execute([':technician_id' => $technicianId]);
    $shiftId = (int)$insertShift->fetchColumn();

    $assert('Se admite un turno cerrado con pausa cero', $shiftId > 0, "id={$shiftId}");

    $assertRejection(
        'Se rechaza meal_break_seconds negativo',
        static function () use ($pgPdo, $technicianId): void {
            $stmt = $pgPdo->prepare(
                "INSERT INTO technician_shifts (
                    technician_id, shift_date, status, started_at, closed_at, meal_break_seconds
                 ) VALUES (
                    :technician_id, DATE '2026-10-21', 'OFF_SHIFT',
                    TIMESTAMPTZ '2026-10-21 08:00:00+02',
                    TIMESTAMPTZ '2026-10-21 09:00:00+02',
                    -1
                 )"
            );
            $stmt->execute([':technician_id' => $technicianId]);
        },
        '23514'
    );

    $assertRejection(
        'Se rechaza una pausa superior a la duración bruta',
        static function () use ($pgPdo, $technicianId): void {
            $stmt = $pgPdo->prepare(
                "INSERT INTO technician_shifts (
                    technician_id, shift_date, status, started_at, closed_at, meal_break_seconds
                 ) VALUES (
                    :technician_id, DATE '2026-10-22', 'OFF_SHIFT',
                    TIMESTAMPTZ '2026-10-22 08:00:00+02',
                    TIMESTAMPTZ '2026-10-22 09:00:00+02',
                    7200
                 )"
            );
            $stmt->execute([':technician_id' => $technicianId]);
        },
        '23514'
    );

    $assertRejection(
        'Se rechaza un ajuste con motivo de 19 caracteres reales',
        static function () use ($pgPdo, $technicianId, $coordinatorId): void {
            $stmt = $pgPdo->prepare(
                "INSERT INTO technician_shifts (
                    technician_id, shift_date, status, started_at, closed_at, meal_break_seconds,
                    is_adjusted, adjusted_at, adjusted_by, adjustment_reason
                 ) VALUES (
                    :technician_id, DATE '2026-10-23', 'OFF_SHIFT',
                    TIMESTAMPTZ '2026-10-23 08:00:00+02',
                    TIMESTAMPTZ '2026-10-23 09:00:00+02',
                    0, TRUE, CURRENT_TIMESTAMP, :adjusted_by,
                    '  1234567890123456789  '
                 )"
            );
            $stmt->execute([':technician_id' => $technicianId, ':adjusted_by' => $coordinatorId]);
        },
        '23514'
    );

    $insertAdjustedShift = $pgPdo->prepare(
        "INSERT INTO technician_shifts (
            technician_id, shift_date, status, started_at, closed_at, meal_break_seconds,
            is_adjusted, adjusted_at, adjusted_by, adjustment_reason
         ) VALUES (
            :technician_id, DATE '2026-10-24', 'OFF_SHIFT',
            TIMESTAMPTZ '2026-10-24 08:00:00+02',
            TIMESTAMPTZ '2026-10-24 09:00:00+02',
            0, TRUE, CURRENT_TIMESTAMP, :adjusted_by,
            '  12345678901234567890  '
         ) RETURNING id"
    );
    $insertAdjustedShift->execute([':technician_id' => $technicianId, ':adjusted_by' => $coordinatorId]);
    $adjustedShiftId = (int)$insertAdjustedShift->fetchColumn();

    $assert(
        'Se admite un ajuste con exactamente 20 caracteres reales',
        $adjustedShiftId > 0,
        "id={$adjustedShiftId}"
    );

    // ──────────────────────────────────────────────────────────────────────
    // 3. Bitácora append-only
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- technician_shift_logs: estructura e inmutabilidad ---\n";

    $logColumns = PostgresFixtureBuilder::columns($pgPdo, 'technician_shift_logs');
    $expectedLogColumns = [
        'id', 'shift_id', 'technician_id', 'event_type', 'actor_user_id',
        'effective_at', 'previous_state', 'new_state', 'reason', 'created_at',
    ];
    $assert(
        'La bitácora declara sus diez columnas canónicas',
        array_keys($logColumns) === $expectedLogColumns,
        'columnas: ' . implode(', ', array_keys($logColumns))
    );

    $indexStmt = $pgPdo->query(
        "SELECT indexname FROM pg_indexes
          WHERE schemaname = current_schema()
            AND tablename = 'technician_shift_logs'"
    );
    $indexes = array_map(static fn (array $row): string => (string)$row['indexname'], $indexStmt->fetchAll(PDO::FETCH_ASSOC));
    $assert(
        'Los dos índices de consulta de la bitácora existen',
        in_array('idx_tech_shift_logs_shift', $indexes, true)
            && in_array('idx_tech_shift_logs_tech', $indexes, true),
        'índices: ' . implode(', ', $indexes)
    );

    $triggerStmt = $pgPdo->query(
        "SELECT t.tgname, p.proname
           FROM pg_trigger t
           JOIN pg_proc p ON p.oid = t.tgfoid
          WHERE t.tgrelid = 'technician_shift_logs'::regclass
            AND NOT t.tgisinternal
          ORDER BY t.tgname"
    );
    $triggers = [];
    foreach ($triggerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $triggers[(string)$row['tgname']] = (string)$row['proname'];
    }
    $assert(
        'Los dos disparadores de inmutabilidad están vinculados a sus funciones',
        ($triggers['trg_no_delete_technician_shift_logs'] ?? null) === 'prevent_hard_delete'
            && ($triggers['trg_no_update_technician_shift_logs'] ?? null) === 'prevent_service_sheet_update',
        'disparadores: ' . json_encode($triggers)
    );

    $insertLog = $pgPdo->prepare(
        "INSERT INTO technician_shift_logs (
            shift_id, technician_id, event_type, actor_user_id, new_state
         ) VALUES (
            :shift_id, :technician_id, 'START_SHIFT', :actor_user_id,
            jsonb_build_object('status', 'ON_SHIFT')
         ) RETURNING id"
    );
    $insertLog->execute([
        ':shift_id' => $shiftId,
        ':technician_id' => $technicianId,
        ':actor_user_id' => $technicianId,
    ]);
    $logId = (int)$insertLog->fetchColumn();
    $assert('Se admite un evento START_SHIFT en la bitácora', $logId > 0, "id={$logId}");

    $assertRejection(
        'La bitácora rechaza UPDATE con SQLSTATE 45000',
        static function () use ($pgPdo, $logId): void {
            $stmt = $pgPdo->prepare('UPDATE technician_shift_logs SET reason = :reason WHERE id = :id');
            $stmt->execute([':reason' => 'Reescritura no permitida', ':id' => $logId]);
        },
        '45000'
    );

    $assertRejection(
        'La bitácora rechaza DELETE con SQLSTATE 45000',
        static function () use ($pgPdo, $logId): void {
            $stmt = $pgPdo->prepare('DELETE FROM technician_shift_logs WHERE id = :id');
            $stmt->execute([':id' => $logId]);
        },
        '45000'
    );

    $remainStmt = $pgPdo->prepare('SELECT COUNT(*) FROM technician_shift_logs WHERE id = :id');
    $remainStmt->execute([':id' => $logId]);
    $assert(
        'El evento sigue presente después de los dos rechazos',
        (int)$remainStmt->fetchColumn() === 1
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    PostgresFixtureBuilder::dropIsolationSchema($pgPdo, TIME_TRACKING_TEST_SCHEMA);
}

if ($crash !== null) {
    echo "\n[FATAL] " . $crash->getMessage() . "\n";
    $failures++;
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-TIME-02 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLIDO.\n";
echo "======================================================================\n";
exit(1);
