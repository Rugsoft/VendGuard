<?php

declare(strict_types=1);

/**
 * TechnicianShiftTimeTrackingTest · T-TIME-04
 *
 * Certifica el modelo de cómputo de jornada del Módulo 22 sin base de datos:
 *
 *   1. Acumulación de múltiples pausas de comida (`RF-01.2`).
 *   2. Descuento del tramo abierto al cerrar directamente desde `MEAL_BREAK`
 *      (`RF-01.3`).
 *   3. Imputación al día de apertura de un turno que cruza medianoche
 *      (`RF-01.4`, C1).
 *   4. Alerta de duración anómala sobre la duración bruta (`RF-02.4`, QA 4).
 *   5. Cierre forzado en tiempo real y con hora efectiva retroactiva
 *      (`RF-02.1`, QA 2).
 *   6. Validación cuádruple y preservación de marcas originales en
 *      rectificaciones (`RF-03.1` a `RF-03.4`, QA 3).
 *   7. Permisión de `0s` efectivos y formato `HHh MMm` del DTO.
 *
 * La cuarta regla de la validación cuádruple (solapamiento con otro turno) se
 * ejerce en el servicio con el repositorio; esta suite cubre las tres reglas
 * temporales locales y documenta esa frontera.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Model\TechnicianShift;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expectDomainError = static function (
    string $what,
    callable $operation,
    string $expectedCode,
    int $expectedStatus
) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'no se lanzó la excepción esperada');
    } catch (ShiftAdjustmentValidationException $exception) {
        $assert(
            $what,
            $exception->getErrorCode() === $expectedCode
                && $exception->getHttpStatusCode() === $expectedStatus,
            $exception->getErrorCode() . ' · HTTP ' . $exception->getHttpStatusCode()
        );
    } catch (Throwable $exception) {
        $assert($what, false, get_class($exception) . ': ' . $exception->getMessage());
    }
};

$reason = 'Rectificación justificada por olvido de fichaje al finalizar la ruta.';
$adjustedNow = new DateTimeImmutable('2026-10-25 12:00:00+01:00');

echo "======================================================================\n";
echo " VendGuard · T-TIME-04 · Cómputo de Jornada y Rectificaciones (Unit)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Múltiples pausas de comida
// ─────────────────────────────────────────────────────────────────────────────
echo "\n1. Acumulación de múltiples pausas (RF-01.2)\n";

$start = new DateTimeImmutable('2026-10-20 08:00:00+02:00');
$shift = TechnicianShift::start(7, $start);

$paused = $shift->pauseMeal(new DateTimeImmutable('2026-10-20 10:00:00+02:00'));
$resumed = $paused->resume(new DateTimeImmutable('2026-10-20 10:30:00+02:00'));
$pausedAgain = $resumed->pauseMeal(new DateTimeImmutable('2026-10-20 13:00:00+02:00'));
$resumedAgain = $pausedAgain->resume(new DateTimeImmutable('2026-10-20 13:45:00+02:00'));
$closed = $resumedAgain->close(
    closedBy: 7,
    closeReason: 'Fin de jornada',
    closedAt: new DateTimeImmutable('2026-10-20 17:00:00+02:00')
);

$assert('Primera pausa acumula 30 minutos', $resumed->mealBreakSeconds() === 1800);
$assert('Segunda pausa suma 45 minutos sin perder la primera', $resumedAgain->mealBreakSeconds() === 4500);
$assert('El cierre conserva la pausa acumulada', $closed->mealBreakSeconds() === 4500);
$assert('Duración bruta de 9 horas', $closed->grossDurationSeconds() === 32400);
$assert('Pausa total de 1 h 15 m', $closed->totalMealBreakSeconds() === 4500);
$assert('Horas efectivas de 7 h 45 m', $closed->effectiveWorkSeconds() === 27900);
$assert('El turno no es anómalo con 9 horas brutas', $closed->isAnomalousDuration() === false);

// La pausa abierta también devenga tiempo en vivo.
$liveReference = new DateTimeImmutable('2026-10-20 13:30:00+02:00');
$assert(
    'La pausa abierta se computa hasta el instante de referencia',
    $pausedAgain->totalMealBreakSeconds($liveReference) === 3600
);
$assert(
    'Las horas efectivas en vivo descuentan la pausa abierta',
    $pausedAgain->effectiveWorkSeconds($liveReference) === 16200
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Cierre directo desde MEAL_BREAK
// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Cierre directo desde pausa de comida (RF-01.3)\n";

$directShift = TechnicianShift::start(7, new DateTimeImmutable('2026-10-21 08:00:00+02:00'));
$directPaused = $directShift->pauseMeal(new DateTimeImmutable('2026-10-21 13:00:00+02:00'));
$directClosed = $directPaused->close(
    closedBy: 7,
    closeReason: 'Salida durante la pausa',
    closedAt: new DateTimeImmutable('2026-10-21 14:30:00+02:00')
);

$assert('Cerrar desde MEAL_BREAK suma el tramo abierto a la pausa', $directClosed->mealBreakSeconds() === 5400);
$assert('La duración bruta incluye el tramo de pausa', $directClosed->grossDurationSeconds() === 23400);
$assert('Las horas efectivas descuentan el tramo de pausa', $directClosed->effectiveWorkSeconds() === 18000);
$assert('El cierre directo deja el turno cerrado', $directClosed->isOffShift() && !$directClosed->isOpen());

// ─────────────────────────────────────────────────────────────────────────────
// 3. Turno que cruza medianoche
// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Imputación al día de apertura (RF-01.4, C1)\n";

$nightShift = TechnicianShift::start(8, new DateTimeImmutable('2026-10-31 22:30:00+01:00'));
$nightClosed = $nightShift->close(
    closedBy: 8,
    closedAt: new DateTimeImmutable('2026-11-01 01:30:00+01:00')
);

$assert('El turno nocturno se imputa al día de apertura', $nightClosed->shiftDate() === '2026-10-31');
$assert('La duración bruta nocturna es de 3 horas', $nightClosed->grossDurationSeconds() === 10800);
$assert('La fecha de cierre cruza al día siguiente', $nightClosed->closedAt()?->format('Y-m-d') === '2026-11-01');

// ─────────────────────────────────────────────────────────────────────────────
// 4. Alerta por duración bruta anómala
// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. Umbral de 12 horas brutas (RF-02.4, QA 4)\n";

$twelveHours = TechnicianShift::start(9, new DateTimeImmutable('2026-10-22 08:00:00+02:00'))
    ->close(closedBy: 9, closedAt: new DateTimeImmutable('2026-10-22 20:00:00+02:00'));
$overTwelve = TechnicianShift::start(9, new DateTimeImmutable('2026-10-22 08:00:00+02:00'))
    ->close(closedBy: 9, closedAt: new DateTimeImmutable('2026-10-22 20:00:01+02:00'));

$assert('Exactamente 12 horas brutas no dispara la alerta', $twelveHours->isAnomalousDuration() === false);
$assert('12 horas y un segundo sí dispara la alerta', $overTwelve->isAnomalousDuration() === true);
$assert(
    'El umbral normativo es 43.200 segundos',
    TechnicianShift::ANOMALOUS_DURATION_THRESHOLD_SECONDS === 43200
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Cierre forzado en tiempo real y retroactivo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Cierre forzado y marcas originales (RF-02.1, QA 2)\n";

$realTimeShift = TechnicianShift::start(10, new DateTimeImmutable('2026-10-23 08:00:00+02:00'));
$realTimeClosed = $realTimeShift->close(
    closedBy: 2,
    closeReason: 'Cierre forzado al finalizar la ruta.',
    closedAt: new DateTimeImmutable('2026-10-23 17:00:00+02:00'),
    isForceClosed: true,
    executionTime: new DateTimeImmutable('2026-10-23 17:00:00+02:00')
);

$assert('El cierre forzado en tiempo real lleva la insignia is_force_closed', $realTimeClosed->isForceClosed());
$assert('El cierre forzado en tiempo real no se marca como ajustado', $realTimeClosed->isAdjusted() === false);
$assert(
    'La marca original de salida es el instante de ejecución',
    $realTimeClosed->originalClosedAt()?->format('Y-m-d H:i:s') === '2026-10-23 17:00:00'
);
$assert('El motivo del cierre forzado se conserva', $realTimeClosed->closeReason() === 'Cierre forzado al finalizar la ruta.');

$retroShift = TechnicianShift::start(10, new DateTimeImmutable('2026-10-23 08:00:00+02:00'));
$retroExecution = new DateTimeImmutable('2026-10-24 09:00:00+02:00');
$retroClosed = $retroShift->close(
    closedBy: 2,
    closeReason: $reason,
    closedAt: new DateTimeImmutable('2026-10-23 17:00:00+02:00'),
    isForceClosed: true,
    executionTime: $retroExecution
);

$assert('El cierre retroactivo conserva la hora efectiva de salida', $retroClosed->closedAt()?->format('Y-m-d H:i:s') === '2026-10-23 17:00:00');
$assert('El cierre retroactivo se marca como ajustado', $retroClosed->isAdjusted() === true);
$assert('El cierre retroactivo conserva la insignia de cierre forzado', $retroClosed->isForceClosed() === true);
$assert(
    'La marca original de salida es el instante real de ejecución',
    $retroClosed->originalClosedAt()?->format('Y-m-d H:i:s') === '2026-10-24 09:00:00'
);
$assert(
    'La marca original de entrada es el primer fichaje',
    $retroClosed->originalStartedAt()->format('Y-m-d H:i:s') === '2026-10-23 08:00:00'
);
$assert('La pausa original se fija en el primer cierre', $retroClosed->originalMealBreakSeconds() === 0);
$assert(
    'La auditoría del cierre retroactivo usa el instante real',
    $retroClosed->adjustedAt()?->format('Y-m-d H:i:s') === '2026-10-24 09:00:00'
    && $retroClosed->adjustedBy() === 2
    && $retroClosed->adjustmentReason() === $reason
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Rectificaciones múltiples y preservación de original_*
// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. Rectificación auditada y marcas originales (RF-03.4, QA 3)\n";

$base = TechnicianShift::start(11, new DateTimeImmutable('2026-10-20 08:00:00+02:00'))
    ->pauseMeal(new DateTimeImmutable('2026-10-20 13:00:00+02:00'))
    ->resume(new DateTimeImmutable('2026-10-20 14:00:00+02:00'))
    ->close(closedBy: 11, closedAt: new DateTimeImmutable('2026-10-20 17:00:00+02:00'));

$firstAdjustment = $base->adjust(
    new DateTimeImmutable('2026-10-20 07:30:00+02:00'),
    new DateTimeImmutable('2026-10-20 16:30:00+02:00'),
    1800,
    2,
    '  ' . $reason . '  ',
    new DateTimeImmutable('2026-10-25 10:00:00+01:00')
);

$assert('La rectificación actualiza la hora de entrada', $firstAdjustment->startedAt()->format('H:i') === '07:30');
$assert('La rectificación actualiza la hora de salida', $firstAdjustment->closedAt()?->format('H:i') === '16:30');
$assert('La rectificación actualiza la pausa acumulada', $firstAdjustment->mealBreakSeconds() === 1800);
$assert('La rectificación recorta el motivo antes de guardarlo', $firstAdjustment->adjustmentReason() === $reason);
$assert('La rectificación marca is_adjusted', $firstAdjustment->isAdjusted() === true && $firstAdjustment->adjustedBy() === 2);
$assert(
    'La primera rectificación conserva las marcas originales del fichaje',
    $firstAdjustment->originalStartedAt()->format('H:i') === '08:00'
    && $firstAdjustment->originalClosedAt()?->format('H:i') === '17:00'
    && $firstAdjustment->originalMealBreakSeconds() === 3600
);

$secondAdjustment = $firstAdjustment->adjust(
    new DateTimeImmutable('2026-10-19 23:30:00+02:00'),
    new DateTimeImmutable('2026-10-20 00:30:00+02:00'),
    0,
    3,
    'Segunda rectificación para imputar el turno al día correcto.',
    new DateTimeImmutable('2026-10-25 11:00:00+01:00')
);

$assert('La segunda rectificación recalcula shift_date en Europe/Madrid', $secondAdjustment->shiftDate() === '2026-10-19');
$assert(
    'La segunda rectificación no pisa las marcas originales',
    $secondAdjustment->originalStartedAt()->format('H:i') === '08:00'
    && $secondAdjustment->originalClosedAt()?->format('H:i') === '17:00'
    && $secondAdjustment->originalMealBreakSeconds() === 3600
);
$assert('La segunda rectificación registra al nuevo coordinador', $secondAdjustment->adjustedBy() === 3);

$zeroEffective = $base->adjust(
    new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
    new DateTimeImmutable('2026-10-20 17:00:00+02:00'),
    32400,
    2,
    'Anulación del cómputo de un fichaje accidental sin borrado físico.',
    $adjustedNow
);
$assert('Se permite pausa igual a duración bruta', $zeroEffective->mealBreakSeconds() === 32400);
$assert('Un turno anulado conserva 0 segundos efectivos', $zeroEffective->effectiveWorkSeconds() === 0);

$exactlyTwentyFourHours = TechnicianShift::start(12, new DateTimeImmutable('2026-10-20 08:00:00+02:00'))
    ->close(closedBy: 12, closedAt: new DateTimeImmutable('2026-10-20 17:00:00+02:00'))
    ->adjust(
        new DateTimeImmutable('2026-10-19 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        0,
        2,
        'Rectificación de jornada de veinticuatro horas exactas.',
        $adjustedNow
    );
$assert('La duración bruta de 24 horas exactas se admite', $exactlyTwentyFourHours->grossDurationSeconds() === 86400);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Rechazos de la validación temporal y de pausas
// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Validación de rectificaciones (RF-03.1 a RF-03.3)\n";

$openShift = TechnicianShift::start(13, new DateTimeImmutable('2026-10-20 08:00:00+02:00'));
$expectDomainError(
    'Rechaza rectificar un turno abierto',
    static fn () => $openShift->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 17:00:00+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_STILL_OPEN',
    422
);

$expectDomainError(
    'Rechaza un motivo de menos de 20 caracteres reales',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 17:00:00+02:00'),
        0,
        2,
        '   motivo corto   ',
        $adjustedNow
    ),
    'INVALID_ADJUSTMENT_REASON',
    422
);

$expectDomainError(
    'Rechaza una entrada futura',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-26 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-26 17:00:00+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_FUTURE_TIMESTAMP',
    422
);

$expectDomainError(
    'Rechaza una salida futura',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-26 17:00:00+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_FUTURE_TIMESTAMP',
    422
);

$expectDomainError(
    'Rechaza salida igual a entrada',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_INVALID_TIME_ORDER',
    422
);

$expectDomainError(
    'Rechaza salida anterior a la entrada',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 07:00:00+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_INVALID_TIME_ORDER',
    422
);

$expectDomainError(
    'Rechaza pausa negativa',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 17:00:00+02:00'),
        -1,
        2,
        $reason,
        $adjustedNow
    ),
    'INVALID_MEAL_BREAK_DURATION',
    422
);

$expectDomainError(
    'Rechaza pausa superior a la duración bruta',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-20 17:00:00+02:00'),
        32401,
        2,
        $reason,
        $adjustedNow
    ),
    'INVALID_MEAL_BREAK_DURATION',
    422
);

$expectDomainError(
    'Rechaza una duración bruta superior a 24 horas',
    static fn () => $base->adjust(
        new DateTimeImmutable('2026-10-20 08:00:00+02:00'),
        new DateTimeImmutable('2026-10-21 08:00:01+02:00'),
        0,
        2,
        $reason,
        $adjustedNow
    ),
    'SHIFT_EXCEEDS_MAX_DURATION',
    422
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. DTO de proyección
// ─────────────────────────────────────────────────────────────────────────────
echo "\n8. TechnicianShiftDto · segundos y formato HHh MMm\n";

$dto = TechnicianShiftDto::fromEntity($closed, 'Jordi Ruta');
$payload = $dto->toArray();

$assert('El DTO conserva el estado del turno', $payload['status'] === 'OFF_SHIFT');
$assert('El DTO expone la pausa en segundos', $payload['meal_break_seconds'] === 4500);
$assert('El DTO expone la duración bruta en segundos', $payload['gross_seconds'] === 32400);
$assert('El DTO expone las horas efectivas en segundos', $payload['effective_seconds'] === 27900);
$assert('El DTO formatea la duración bruta', $payload['gross_formatted'] === '09h 00m');
$assert('El DTO formatea la pausa de comida', $payload['meal_break_formatted'] === '01h 15m');
$assert('El DTO formatea las horas efectivas', $payload['effective_formatted'] === '07h 45m');
$assert('El DTO expone la insignia is_anomalous', $payload['is_anomalous'] === false);
$assert('El DTO expone is_force_closed e is_adjusted', $payload['is_force_closed'] === false && $payload['is_adjusted'] === false);
$assert('El DTO expone las marcas originales', $payload['original_started_at'] !== null && $payload['original_meal_break_seconds'] === 4500);

$adjustedDto = TechnicianShiftDto::fromEntity($firstAdjustment);
$assert('El DTO proyecta la insignia de ajuste', $adjustedDto->isAdjusted === true && $adjustedDto->adjustedBy === 2);
$assert('El DTO expone el motivo de la rectificación', $adjustedDto->adjustmentReason === $reason);

// ─────────────────────────────────────────────────────────────────────────────
// 9. Hidratación desde PostgreSQL
// ─────────────────────────────────────────────────────────────────────────────
echo "\n9. Hidratación de las columnas nuevas\n";

$row = [
    'id' => 20,
    'technician_id' => 11,
    'status' => 'OFF_SHIFT',
    'shift_date' => '2026-10-20',
    'started_at' => '2026-10-20 07:30:00+02',
    'meal_break_started_at' => '2026-10-20 13:00:00+02',
    'meal_break_ended_at' => '2026-10-20 14:00:00+02',
    'closed_at' => '2026-10-20 16:30:00+02',
    'closed_by' => 11,
    'close_reason' => 'Fin de jornada',
    'created_at' => '2026-10-20 07:30:00+02',
    'updated_at' => '2026-10-25 10:00:00+01',
    'meal_break_seconds' => 1800,
    'original_started_at' => '2026-10-20 08:00:00+02',
    'original_closed_at' => '2026-10-20 17:00:00+02',
    'original_meal_break_seconds' => 3600,
    'is_force_closed' => true,
    'is_adjusted' => true,
    'adjusted_at' => '2026-10-25 10:00:00+01',
    'adjusted_by' => 2,
    'adjustment_reason' => $reason,
];

$hydrated = TechnicianShift::fromDatabaseRow($row);
$assert('Hidrata meal_break_seconds', $hydrated->mealBreakSeconds() === 1800);
$assert('Hidrata original_started_at', $hydrated->originalStartedAt()->format('H:i') === '08:00');
$assert('Hidrata original_closed_at', $hydrated->originalClosedAt()?->format('H:i') === '17:00');
$assert('Hidrata original_meal_break_seconds', $hydrated->originalMealBreakSeconds() === 3600);
$assert('Hidrata las insignias de cierre y ajuste', $hydrated->isForceClosed() && $hydrated->isAdjusted());
$assert(
    'Hidrata al coordinador y el motivo',
    $hydrated->adjustedBy() === 2 && $hydrated->adjustmentReason() === $reason
);

$legacyRow = $row;
unset(
    $legacyRow['meal_break_seconds'],
    $legacyRow['original_started_at'],
    $legacyRow['original_closed_at'],
    $legacyRow['original_meal_break_seconds'],
    $legacyRow['is_force_closed'],
    $legacyRow['is_adjusted'],
    $legacyRow['adjusted_at'],
    $legacyRow['adjusted_by'],
    $legacyRow['adjustment_reason']
);
$legacy = TechnicianShift::fromDatabaseRow($legacyRow);
$assert('Una fila anterior a la migración arranca con pausa 0', $legacy->mealBreakSeconds() === 0);
$assert(
    'Una fila anterior conserva su entrada como marca original',
    $legacy->originalStartedAt()->format('Y-m-d H:i:s') === '2026-10-20 07:30:00'
);

// ─────────────────────────────────────────────────────────────────────────────
// Resultado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n----------------------------------------------------------------------\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "----------------------------------------------------------------------\n";

exit($failures === 0 ? 0 : 1);
