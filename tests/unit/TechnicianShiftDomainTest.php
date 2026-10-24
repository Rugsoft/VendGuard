<?php

declare(strict_types=1);

/**
 * TechnicianShiftDomainTest · T-ASH-02
 *
 * Certifica la lógica de dominio pura y la máquina de estados de turnos de técnicos
 * (Módulo 21) sin base de datos:
 *
 *   1. Enum `ShiftStatus`:
 *      - Casos: `ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`.
 *      - `isAvailable()` es true solo en `ON_SHIFT`.
 *      - Etiquetas legibles en castellano.
 *   2. Inicio de turno (`start`):
 *      - Inicia en `ON_SHIFT` con marca de inicio.
 *      - Invariante de turno abierto (`isOpen()` es true).
 *   3. Pausa de comida (`pauseMeal`):
 *      - Transiciona de `ON_SHIFT` a `MEAL_BREAK`.
 *      - Rechaza pausar si no está en `ON_SHIFT` (`INVALID_SHIFT_TRANSITION`).
 *   4. Reanudación de jornada (`resume`):
 *      - Transiciona de `MEAL_BREAK` a `ON_SHIFT`.
 *      - Rechaza reanudar si no está en `MEAL_BREAK`.
 *   5. Cierre de turno (`close`):
 *      - Permite cierre desde `ON_SHIFT`.
 *      - Permite cierre directo desde `MEAL_BREAK` (Resolución R10).
 *      - Rechaza cierre si ya está en `OFF_SHIFT`.
 *   6. Excepción de averías en curso (`ActiveIncidentInProgressException`):
 *      - Código `HAS_IN_PROGRESS_INCIDENTS`, lista de tickets.
 *   7. Hidratación y serialización (`fromDatabaseRow`, `toArray`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VendGuard · T-ASH-02 · Dominio de Turnos de Técnicos (Unit)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. ShiftStatus Enum
// ─────────────────────────────────────────────────────────────────────────────
$assert(
    'ShiftStatus enum cuenta con los tres estados del ciclo de jornada',
    count(ShiftStatus::cases()) === 3
);

$assert('ON_SHIFT está disponible', ShiftStatus::ON_SHIFT->isAvailable());
$assert('MEAL_BREAK no está disponible', !ShiftStatus::MEAL_BREAK->isAvailable());
$assert('OFF_SHIFT no está disponible', !ShiftStatus::OFF_SHIFT->isAvailable());

$assert('ON_SHIFT etiqueta En turno', ShiftStatus::ON_SHIFT->label() === 'En turno');
$assert('MEAL_BREAK etiqueta Pausa comida', ShiftStatus::MEAL_BREAK->label() === 'Pausa comida');
$assert('OFF_SHIFT etiqueta Fuera de turno', ShiftStatus::OFF_SHIFT->label() === 'Fuera de turno');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Inicio de turno
// ─────────────────────────────────────────────────────────────────────────────
$startedAt = new DateTimeImmutable('2026-10-23 08:00:00+00');
$shift = TechnicianShift::start(technicianId: 4, startedAt: $startedAt);

$assert('TechnicianShift arranca en estado ON_SHIFT', $shift->isOnShift());
$assert('TechnicianShift está abierto al arrancar', $shift->isOpen());
$assert('TechnicianShift preserva el técnico', $shift->technicianId() === 4);
$assert('TechnicianShift preserva startedAt', $shift->startedAt() === $startedAt);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Pausa de comida y reanudación
// ─────────────────────────────────────────────────────────────────────────────
$mealStart = new DateTimeImmutable('2026-10-23 13:00:00+00');
$mealShift = $shift->pauseMeal($mealStart);

$assert('pauseMeal transiciona a MEAL_BREAK', $mealShift->isMealBreak());
$assert('pauseMeal preserva el inicio de comida', $mealShift->mealBreakStartedAt() === $mealStart);
$assert('pauseMeal mantiene el turno abierto', $mealShift->isOpen());

// Rechazo de pausar si ya está en pausa
$rejectedPauseAgain = false;
try {
    $mealShift->pauseMeal();
} catch (InvalidShiftTransitionException $e) {
    $rejectedPauseAgain = $e->getErrorCode() === 'SHIFT_ALREADY_PAUSED'
        && $e->getHttpStatusCode() === 422;
}
$assert('pauseMeal rechaza pausar si ya está en MEAL_BREAK con SHIFT_ALREADY_PAUSED', $rejectedPauseAgain);

// Reanudación
$mealEnd = new DateTimeImmutable('2026-10-23 14:00:00+00');
$resumedShift = $mealShift->resume($mealEnd);

$assert('resume transiciona de vuelta a ON_SHIFT', $resumedShift->isOnShift());
$assert('resume preserva el fin de comida', $resumedShift->mealBreakEndedAt() === $mealEnd);

// Rechazo de reanudar si ya está en ON_SHIFT
$rejectedResumeAgain = false;
try {
    $resumedShift->resume();
} catch (InvalidShiftTransitionException $e) {
    $rejectedResumeAgain = $e->getErrorCode() === 'SHIFT_NOT_IN_MEAL_BREAK'
        && $e->getHttpStatusCode() === 422;
}
$assert('resume rechaza reanudar si no está en MEAL_BREAK con SHIFT_NOT_IN_MEAL_BREAK', $rejectedResumeAgain);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Cierre de turno y soporte de cierre directo desde MEAL_BREAK (R10)
// ─────────────────────────────────────────────────────────────────────────────
$closedAt = new DateTimeImmutable('2026-10-23 17:00:00+00');
$closedShift = $resumedShift->close(closedBy: 4, closeReason: 'Fin de jornada regular', closedAt: $closedAt);

$assert('close transiciona a OFF_SHIFT', $closedShift->isOffShift());
$assert('close marca isOpen() como false', !$closedShift->isOpen());
$assert('close preserva closed_by y close_reason', $closedShift->closedBy() === 4 && $closedShift->closeReason() === 'Fin de jornada regular');

// Cierre directo desde MEAL_BREAK (R10)
$directClosedFromMeal = $mealShift->close(closedBy: 4, closeReason: 'Urgencia personal durante la comida');
$assert('close permite cerrar directamente desde MEAL_BREAK (Resolución R10)', $directClosedFromMeal->isOffShift());

// Rechazo de cerrar turno ya cerrado
$rejectedCloseAgain = false;
try {
    $closedShift->close(closedBy: 4);
} catch (InvalidShiftTransitionException $e) {
    $rejectedCloseAgain = $e->getErrorCode() === 'INVALID_SHIFT_TRANSITION';
}
$assert('close rechaza cerrar un turno ya cerrado', $rejectedCloseAgain);

// ─────────────────────────────────────────────────────────────────────────────
// 5. ActiveIncidentInProgressException
// ─────────────────────────────────────────────────────────────────────────────
$exception = new ActiveIncidentInProgressException(['INC-2026-0001', 'INC-2026-0002']);
$assert(
    'ActiveIncidentInProgressException reporta código HAS_IN_PROGRESS_INCIDENTS',
    $exception->getErrorCode() === 'HAS_IN_PROGRESS_INCIDENTS'
);
$assert(
    'ActiveIncidentInProgressException incluye los códigos bloqueantes',
    $exception->getBlockingIncidentCodes() === ['INC-2026-0001', 'INC-2026-0002']
);
$assert(
    'ActiveIncidentInProgressException tiene código HTTP 422',
    $exception->getHttpStatusCode() === 422
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Hidratación y serialización
// ─────────────────────────────────────────────────────────────────────────────
$dbRow = [
    'id' => 5,
    'technician_id' => 4,
    'shift_date' => '2026-10-23',
    'status' => 'ON_SHIFT',
    'started_at' => '2026-10-23 08:00:00+00',
    'meal_break_started_at' => null,
    'meal_break_ended_at' => null,
    'closed_at' => null,
    'closed_by' => null,
    'close_reason' => null,
    'created_at' => '2026-10-23 08:00:00+00',
    'updated_at' => '2026-10-23 08:00:00+00',
];

$shiftFromDb = TechnicianShift::fromDatabaseRow($dbRow);
$assert('fromDatabaseRow hidrata status ON_SHIFT', $shiftFromDb->status() === ShiftStatus::ON_SHIFT);
$assert('fromDatabaseRow hidrata technicianId', $shiftFromDb->technicianId() === 4);
$assert('fromDatabaseRow hidrata id', $shiftFromDb->id() === 5);

$arr = $shiftFromDb->toArray();
$assert('toArray exporta status como string', $arr['status'] === 'ON_SHIFT');
$assert('toArray exporta status_label', $arr['status_label'] === 'En turno');
$assert('toArray exporta is_available', $arr['is_available'] === true);

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
