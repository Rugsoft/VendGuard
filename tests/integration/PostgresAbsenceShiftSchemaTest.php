<?php

declare(strict_types=1);

/**
 * PostgresAbsenceShiftSchemaTest · T-ASH-01
 *
 * Certifica **en el motor real** el esquema de ausencias y turnos del Módulo 21: las
 * tablas `technician_absences` y `technician_shifts`, sus restricciones de rango de fechas,
 * unicidad de turno activo (R3), orden cronológico de pausas y cierres, y su inmutabilidad
 * constitucional frente a borrados físicos (Art. III.1).
 *
 *   1. **Tablas (M21).** Tablas 29 (`technician_absences`) y 30 (`technician_shifts`).
 *   2. **Ausencias:**
 *      - Rango de fechas: `ends_at >= starts_at` (`chk_tech_absences_dates`).
 *      - Motivo cerrado: (`chk_tech_absences_reason`).
 *      - Cancelación coherente: (`chk_tech_absences_cancellation`).
 *   3. **Turnos:**
 *      - Unicidad de turno activo por técnico: `uq_tech_shifts_single_active` (R3).
 *      - Turno partido: múltiples turnos cerrados admitidos para el mismo técnico.
 *      - Estados válidos: (`chk_tech_shifts_status`).
 *      - Orden cronológico de pausa de comida y cierre (`chk_tech_shifts_meal_order`, `chk_tech_shifts_close_order`).
 *   4. **Inmutabilidad (Art. III.1).** `DELETE` lanza `SQLSTATE '45000'`; solo cede dentro de la ventana de purga.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`).
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresAbsenceShiftSchemaTest.php
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

/** Esquema aislado de esta suite. */
const ABSENCE_SHIFT_TEST_SCHEMA = 't_ash01_absences_shifts';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_MARKER = 'ART_III_1_VIOLATION';

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
echo " VENDGUARD · T-ASH-01 · Esquema de ausencias y turnos (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueProbe = null;

try {
    // ──────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migración 012 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, ABSENCE_SHIFT_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 012 aplican sin errores y el esquema converge',
        in_array('012_technician_absences_and_shifts.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf(
            '%d sentencias · migraciones: %s',
            $schemaReport['statements'],
            implode(', ', $schemaReport['migrations'])
        )
    );

    $absenceColumns = PostgresFixtureBuilder::columns($pgPdo, 'technician_absences');
    $assert(
        'La tabla technician_absences declara sus once columnas',
        count($absenceColumns) === 11,
        'columnas: ' . count($absenceColumns)
    );

    $shiftColumns = PostgresFixtureBuilder::columns($pgPdo, 'technician_shifts');
    $assert(
        'La tabla technician_shifts declara sus doce columnas',
        count($shiftColumns) === 12,
        'columnas: ' . count($shiftColumns)
    );

    // Obtener un usuario técnico para las pruebas
    $technician = $pgPdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($technician)) {
        // Si no hay sembrados aún, insertamos uno
        $pgPdo->exec("INSERT INTO users (name, email, email_key, password_hash, role)
                      VALUES ('Técnico Test ASH', 'tech.test.ash@vending.test', 'tech.test.ash@vending.test', 'hash123', 'TECHNICIAN')");
        $technicianId = (int)$pgPdo->query("SELECT id FROM users WHERE email = 'tech.test.ash@vending.test'")->fetchColumn();
    } else {
        $technicianId = (int)$technician['id'];
    }

    $coordinator = $pgPdo->query("SELECT id FROM users WHERE role = 'COORDINATOR' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($coordinator)) {
        $pgPdo->exec("INSERT INTO users (name, email, email_key, password_hash, role)
                      VALUES ('Coordinador Test ASH', 'coord.test.ash@vending.test', 'coord.test.ash@vending.test', 'hash123', 'COORDINATOR')");
        $coordinatorId = (int)$pgPdo->query("SELECT id FROM users WHERE email = 'coord.test.ash@vending.test'")->fetchColumn();
    } else {
        $coordinatorId = (int)$coordinator['id'];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. technician_absences: validaciones y restricciones
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- technician_absences: restricciones y ciclo de vida ---\n";

    // Inserción válida de ausencia
    $stmt = $pgPdo->prepare("INSERT INTO technician_absences
        (technician_id, reason, starts_at, ends_at, notes, created_by)
        VALUES (:tech_id, 'VACATION', '2026-11-01 00:00:00+00', '2026-11-15 23:59:59+00', 'Vacaciones anuales', :creator)
        RETURNING id");
    $stmt->execute([':tech_id' => $technicianId, ':creator' => $coordinatorId]);
    $absenceId = (int)$stmt->fetchColumn();

    $assert('Se admite una ausencia con motivo VACATION y fechas válidas', $absenceId > 0, "id={$absenceId}");

    // Rechazo de ends_at < starts_at
    $rejDates = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianId, $coordinatorId): void {
        $stmt = $pgPdo->prepare("INSERT INTO technician_absences
            (technician_id, reason, starts_at, ends_at, notes, created_by)
            VALUES (:tech_id, 'VACATION', '2026-11-15 00:00:00+00', '2026-11-01 00:00:00+00', 'Fechas invertidas', :creator)");
        $stmt->execute([':tech_id' => $technicianId, ':creator' => $coordinatorId]);
    });
    $assert(
        'Se rechaza ends_at < starts_at con 23514 por chk_tech_absences_dates',
        $rejDates !== null && $rejDates['state'] === '23514',
        'estado=' . ($rejDates['state'] ?? 'null')
    );

    // Rechazo de reason inválido
    $rejReason = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianId, $coordinatorId): void {
        $stmt = $pgPdo->prepare("INSERT INTO technician_absences
            (technician_id, reason, starts_at, ends_at, notes, created_by)
            VALUES (:tech_id, 'HOLIDAYS', '2026-11-01 00:00:00+00', '2026-11-05 00:00:00+00', 'Motivo inválido', :creator)");
        $stmt->execute([':tech_id' => $technicianId, ':creator' => $coordinatorId]);
    });
    $assert(
        'Se rechaza reason no permitido con 23514 por chk_tech_absences_reason',
        $rejReason !== null && $rejReason['state'] === '23514',
        'estado=' . ($rejReason['state'] ?? 'null')
    );

    // Rechazo de cancelación incoherente (cancelled_at presente pero sin motivo)
    $rejCancel = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianId, $coordinatorId): void {
        $stmt = $pgPdo->prepare("INSERT INTO technician_absences
            (technician_id, reason, starts_at, ends_at, created_by, cancelled_at, cancelled_by, cancellation_reason)
            VALUES (:tech_id, 'SICK_LEAVE', '2026-11-01 00:00:00+00', '2026-11-05 00:00:00+00', :creator, NOW(), :canceller, 'no')");
        $stmt->execute([':tech_id' => $technicianId, ':creator' => $coordinatorId, ':canceller' => $coordinatorId]);
    });
    $assert(
        'Se rechaza cancelación con motivo corto (< 5 chars) con 23514 por chk_tech_absences_cancellation',
        $rejCancel !== null && $rejCancel['state'] === '23514',
        'estado=' . ($rejCancel['state'] ?? 'null')
    );

    // Cancelación lógica válida
    $stmtCancel = $pgPdo->prepare("UPDATE technician_absences
        SET cancelled_at = NOW(), cancelled_by = :canceller, cancellation_reason = 'Alta médica voluntaria anticipada'
        WHERE id = :id");
    $stmtCancel->execute([':canceller' => $coordinatorId, ':id' => $absenceId]);
    $assert('Se admite la cancelación lógica coherente con motivo >= 5 chars', $stmtCancel->rowCount() === 1);

    // Inmutabilidad: DELETE físico rechazado con 45000
    $rejDelAbsence = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $absenceId): void {
        $pgPdo->exec("DELETE FROM technician_absences WHERE id = {$absenceId}");
    });
    $assert(
        'El borrado físico de technician_absences se rechaza con 45000 por prevent_hard_delete()',
        $rejDelAbsence !== null && $rejDelAbsence['state'] === '45000' && str_contains($rejDelAbsence['message'], PURGE_MARKER),
        'estado=' . ($rejDelAbsence['state'] ?? 'null')
    );

    // ──────────────────────────────────────────────────────────────────────
    // 2. technician_shifts: unicidad, orden cronológico y transiciones
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- technician_shifts: unicidad (R3), orden y transiciones ---\n";

    // Inserción de un turno activo inicial
    $stmtShift = $pgPdo->prepare("INSERT INTO technician_shifts
        (technician_id, status, started_at)
        VALUES (:tech_id, 'ON_SHIFT', '2026-10-23 08:00:00+00')
        RETURNING id");
    $stmtShift->execute([':tech_id' => $technicianId]);
    $shiftId = (int)$stmtShift->fetchColumn();
    $assert('Se admite un turno activo en ON_SHIFT', $shiftId > 0, "id={$shiftId}");

    // Rechazo de segundo turno activo para el mismo técnico (R3: uq_tech_shifts_single_active)
    $rejActiveShift = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianId): void {
        $stmt = $pgPdo->prepare("INSERT INTO technician_shifts
            (technician_id, status, started_at)
            VALUES (:tech_id, 'ON_SHIFT', '2026-10-23 09:00:00+00')");
        $stmt->execute([':tech_id' => $technicianId]);
    });
    $assert(
        'Se rechaza segundo turno activo simultáneo con 23505 por uq_tech_shifts_single_active (R3)',
        $rejActiveShift !== null && $rejActiveShift['state'] === '23505',
        'estado=' . ($rejActiveShift['state'] ?? 'null')
    );

    // Rechazo de status inválido
    $rejStatus = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianId): void {
        $stmt = $pgPdo->prepare("INSERT INTO technician_shifts
            (technician_id, status, started_at, closed_at)
            VALUES (:tech_id, 'SLEEPING', '2026-10-20 08:00:00+00', '2026-10-20 16:00:00+00')");
        $stmt->execute([':tech_id' => $technicianId]);
    });
    $assert(
        'Se rechaza status no permitido con 23514 por chk_tech_shifts_status',
        $rejStatus !== null && $rejStatus['state'] === '23514',
        'estado=' . ($rejStatus['state'] ?? 'null')
    );

    // Rechazo de meal_break_ended_at < meal_break_started_at
    $rejMeal = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $shiftId): void {
        $stmt = $pgPdo->prepare("UPDATE technician_shifts
            SET meal_break_started_at = '2026-10-23 14:00:00+00', meal_break_ended_at = '2026-10-23 13:00:00+00'
            WHERE id = :id");
        $stmt->execute([':id' => $shiftId]);
    });
    $assert(
        'Se rechaza fin de comida anterior al inicio con 23514 por chk_tech_shifts_meal_order',
        $rejMeal !== null && $rejMeal['state'] === '23514',
        'estado=' . ($rejMeal['state'] ?? 'null')
    );

    // Rechazo de closed_at < started_at
    $rejClose = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $shiftId): void {
        $stmt = $pgPdo->prepare("UPDATE technician_shifts
            SET closed_at = '2026-10-23 07:00:00+00'
            WHERE id = :id");
        $stmt->execute([':id' => $shiftId]);
    });
    $assert(
        'Se rechaza cierre anterior al inicio con 23514 por chk_tech_shifts_close_order',
        $rejClose !== null && $rejClose['state'] === '23514',
        'estado=' . ($rejClose['state'] ?? 'null')
    );

    // Cierre válido del turno 1
    $stmtClose1 = $pgPdo->prepare("UPDATE technician_shifts
        SET status = 'OFF_SHIFT', closed_at = '2026-10-23 14:00:00+00', closed_by = :closer, close_reason = 'Fin turno de mañana'
        WHERE id = :id");
    $stmtClose1->execute([':closer' => $technicianId, ':id' => $shiftId]);
    $assert('Se admite el cierre regular del primer turno', $stmtClose1->rowCount() === 1);

    // Soporte de turno partido (R3): abrir un segundo turno el mismo día tras cerrar el anterior
    $stmtShift2 = $pgPdo->prepare("INSERT INTO technician_shifts
        (technician_id, status, started_at)
        VALUES (:tech_id, 'ON_SHIFT', '2026-10-23 16:00:00+00')
        RETURNING id");
    $stmtShift2->execute([':tech_id' => $technicianId]);
    $shiftId2 = (int)$stmtShift2->fetchColumn();
    $assert(
        'Se admite un segundo turno en la misma jornada tras cerrar el primero (soporte turno partido R3)',
        $shiftId2 > 0 && $shiftId2 !== $shiftId,
        "id={$shiftId2}"
    );

    // Inmutabilidad: DELETE físico de turnos rechazado con 45000
    $rejDelShift = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $shiftId2): void {
        $pgPdo->exec("DELETE FROM technician_shifts WHERE id = {$shiftId2}");
    });
    $assert(
        'El borrado físico de technician_shifts se rechaza con 45000 por prevent_hard_delete()',
        $rejDelShift !== null && $rejDelShift['state'] === '45000' && str_contains($rejDelShift['message'], PURGE_MARKER),
        'estado=' . ($rejDelShift['state'] ?? 'null')
    );

    // Purga permitida dentro de la ventana del arnés
    $deletedInsidePurge = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $shiftId2): int {
        $stmt = $pgPdo->exec("DELETE FROM technician_shifts WHERE id = {$shiftId2}");
        return $stmt === false ? 0 : (int)$stmt;
    });
    $assert('Dentro de la ventana de purga del arnés el borrado físico se permite', $deletedInsidePurge === 1);

} catch (Throwable $e) {
    $crash = $e;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, ABSENCE_SHIFT_TEST_SCHEMA);
        $residueProbe = $pgPdo->query(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '" . ABSENCE_SHIFT_TEST_SCHEMA . "'"
        )->fetchColumn();
    } catch (Throwable $cleanupError) {
        // Preserva el crash original si existía
    }
}

if ($crash !== null) {
    echo "\n[ERROR INESPERADO EN LA SUITE]\n";
    echo $crash->getMessage() . "\n";
    echo $crash->getTraceAsString() . "\n";
    $failures++;
}

echo "\n--- Limpieza y residuo del escenario ---\n";
$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen en el motor',
    $residueProbe === false || $residueProbe === null,
    'la sonda de residuo encontró ' . var_export($residueProbe, true)
);

echo "\n======================================================================\n";
echo sprintf(
    " Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n",
    $assertions,
    $assertions - $failures,
    $failures
);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
