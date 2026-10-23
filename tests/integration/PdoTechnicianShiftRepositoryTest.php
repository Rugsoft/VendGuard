<?php

declare(strict_types=1);

/**
 * PdoTechnicianShiftRepositoryTest · T-ASH-03
 *
 * Certifica el repositorio PDO de ciclo de turnos de técnicos sobre PostgreSQL real.
 *
 * Casos cubiertos:
 *   1. startShift() inicia una sesión en ON_SHIFT.
 *   2. findActiveShiftByTechnician() encuentra el turno actualmente abierto.
 *   3. pauseMeal() transiciona a MEAL_BREAK y persiste timestamps.
 *   4. resumeShift() transiciona a ON_SHIFT y persiste meal_break_ended_at.
 *   5. closeShift() transiciona a OFF_SHIFT y persiste cierre.
 *   6. Turno partido (R3): permite abrir un segundo turno el mismo día tras cerrar el primero.
 *   7. getActiveShiftsMap() devuelve el mapa technician_id => status sin N+1.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - PdoTechnicianShiftRepositoryTest (T-ASH-03)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$repo = new PdoTechnicianShiftRepository($pdo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

try {
    $techRows = $pdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $tech1 = (int)$techRows[0];
    $tech2 = (int)$techRows[1];

    // ─────────────────────────────────────────────────────────────────────────
    // 1. startShift() y findActiveShiftByTechnician()
    // ─────────────────────────────────────────────────────────────────────────
    $activeBefore = $repo->findActiveShiftByTechnician($tech1);
    $assert('findActiveShiftByTechnician() devuelve null si no hay turno abierto', $activeBefore === null);

    $shift = $repo->startShift($tech1);
    $assert('startShift() abre turno con ID generado', $shift->id() !== null && $shift->id() > 0);
    $assert('startShift() arranca en ON_SHIFT', $shift->isOnShift());

    $active = $repo->findActiveShiftByTechnician($tech1);
    $assert('findActiveShiftByTechnician() recupera el turno activo', $active !== null && $active->id() === $shift->id());
    $assert('findActiveShiftByTechnician() confirma que está abierto', $active->isOpen());

    // ─────────────────────────────────────────────────────────────────────────
    // 2. pauseMeal() y resumeShift()
    // ─────────────────────────────────────────────────────────────────────────
    $paused = $repo->pauseMeal($shift->id());
    $assert('pauseMeal() transiciona a MEAL_BREAK', $paused->isMealBreak());
    $assert('pauseMeal() persiste meal_break_started_at', $paused->mealBreakStartedAt() !== null);

    $activeInMeal = $repo->findActiveShiftByTechnician($tech1);
    $assert('findActiveShiftByTechnician() mantiene el turno abierto en pausa de comida', $activeInMeal->isMealBreak());

    $resumed = $repo->resumeShift($shift->id());
    $assert('resumeShift() transiciona a ON_SHIFT', $resumed->isOnShift());
    $assert('resumeShift() persiste meal_break_ended_at', $resumed->mealBreakEndedAt() !== null);

    // ─────────────────────────────────────────────────────────────────────────
    // 3. closeShift()
    // ─────────────────────────────────────────────────────────────────────────
    $closed = $repo->closeShift(
        shiftId: $shift->id(),
        closedBy: $tech1,
        closeReason: 'Fin de jornada regular'
    );
    $assert('closeShift() transiciona a OFF_SHIFT', $closed->isOffShift());
    $assert('closeShift() marca cerrado', !$closed->isOpen());

    $activeAfterClose = $repo->findActiveShiftByTechnician($tech1);
    $assert('findActiveShiftByTechnician() devuelve null tras cerrar el turno', $activeAfterClose === null);

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Soporte de Turno Partido / Múltiples Sesiones Diarias (R3)
    // ─────────────────────────────────────────────────────────────────────────
    $shift2 = $repo->startShift($tech1);
    $assert('Se admite abrir un segundo turno en la misma fecha tras cerrar el primero (R3)', $shift2->id() !== null && $shift2->id() !== $shift->id());

    $repo->closeShift($shift2->id(), $tech1, 'Fin turno tarde');
    $assert('El segundo turno se cierra correctamente', !$repo->findActiveShiftByTechnician($tech1)?->isOpen());

    // ─────────────────────────────────────────────────────────────────────────
    // 5. getActiveShiftsMap()
    // ─────────────────────────────────────────────────────────────────────────
    $tech1Shift = $repo->startShift($tech1);
    $tech2Shift = $repo->startShift($tech2);
    $tech2ShiftPaused = $repo->pauseMeal($tech2Shift->id());

    $map = $repo->getActiveShiftsMap();
    $assert('getActiveShiftsMap() incluye tech1 en ON_SHIFT', isset($map[$tech1]) && $map[$tech1] === 'ON_SHIFT');
    $assert('getActiveShiftsMap() incluye tech2 en MEAL_BREAK', isset($map[$tech2]) && $map[$tech2] === 'MEAL_BREAK');

    // Cerrar ambos
    $repo->closeShift($tech1Shift->id(), $tech1);
    $repo->closeShift($tech2Shift->id(), $tech2);

    $mapAfter = $repo->getActiveShiftsMap();
    $assert('getActiveShiftsMap() excluye turnos cerrados', !isset($mapAfter[$tech1]) && !isset($mapAfter[$tech2]));

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
