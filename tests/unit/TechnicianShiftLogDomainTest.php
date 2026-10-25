<?php

declare(strict_types=1);

/**
 * TechnicianShiftLogDomainTest · T-TIME-03
 *
 * Certifica la lógica de dominio pura del Módulo 22 sin base de datos:
 *
 *   1. Enum `ShiftEventType`: seis casos canónicos y etiquetas en castellano.
 *   2. Excepción `ShiftAdjustmentValidationException`: códigos y HTTP de cada
 *      regla de rectificación y cierre forzado.
 *   3. Entidad inmutable `TechnicianShiftLog`: hidratación desde PostgreSQL
 *      (JSONB como cadena) y serialización `toArray()` en `snake_case`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;

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
echo " VendGuard · T-TIME-03 · Dominio de Bitácora de Turno (Unit)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. ShiftEventType
// ─────────────────────────────────────────────────────────────────────────────
echo "\n1. ShiftEventType · casos canónicos y etiquetas\n";

$expectedCases = [
    'START_SHIFT' => 'Inicio de turno',
    'PAUSE_MEAL' => 'Inicio de pausa de comida',
    'RESUME_SHIFT' => 'Reanudación de turno',
    'CLOSE_SHIFT' => 'Cierre de turno por técnico',
    'FORCE_CLOSE_SHIFT' => 'Cierre forzado por Coordinación',
    'ADJUST_SHIFT' => 'Rectificación horaria por Coordinación',
];

$assert('El enum declara exactamente seis valores', count(ShiftEventType::cases()) === 6);

foreach ($expectedCases as $value => $label) {
    $case = ShiftEventType::from($value);
    $assert("Caso {$value} con etiqueta \"" . $label . '"', $case->label() === $label);
}

$invalidValueRejected = false;
try {
    ShiftEventType::from('TECHNOLOGY_ADJUSTMENT');
} catch (ValueError) {
    $invalidValueRejected = true;
}
$assert('Un valor fuera del catálogo se rechaza', $invalidValueRejected);

// ─────────────────────────────────────────────────────────────────────────────
// 2. ShiftAdjustmentValidationException
// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. ShiftAdjustmentValidationException · códigos deterministas\n";

$assertionsByRule = [
    'reasonTooShort' => [ShiftAdjustmentValidationException::reasonTooShort(), 'INVALID_ADJUSTMENT_REASON', 422],
    'shiftStillOpen' => [ShiftAdjustmentValidationException::shiftStillOpen(), 'SHIFT_STILL_OPEN', 422],
    'futureTimestampNotAllowed' => [
        ShiftAdjustmentValidationException::futureTimestampNotAllowed(),
        'SHIFT_FUTURE_TIMESTAMP',
        422,
    ],
    'invalidCloseOrder' => [
        ShiftAdjustmentValidationException::invalidCloseOrder(),
        'SHIFT_INVALID_TIME_ORDER',
        422,
    ],
    'effectiveCloseBeforeLastActivity' => [
        ShiftAdjustmentValidationException::effectiveCloseBeforeLastActivity(),
        'EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY',
        422,
    ],
    'invalidMealBreakDuration' => [
        ShiftAdjustmentValidationException::invalidMealBreakDuration(),
        'INVALID_MEAL_BREAK_DURATION',
        422,
    ],
    'exceedsMaxShiftDuration' => [
        ShiftAdjustmentValidationException::exceedsMaxShiftDuration(),
        'SHIFT_EXCEEDS_MAX_DURATION',
        422,
    ],
    'overlappingShift' => [
        ShiftAdjustmentValidationException::overlappingShift(88),
        'SHIFT_OVERLAP_CONFLICT',
        409,
    ],
];

foreach ($assertionsByRule as $rule => [$exception, $code, $status]) {
    $assert(
        "{$rule} publica {$code} con HTTP {$status}",
        $exception->getErrorCode() === $code && $exception->getHttpStatusCode() === $status
    );
    $assert("{$rule} incluye un mensaje en castellano", $exception->getMessage() !== '');
}

$assert(
    'El solapamiento identifica el turno en conflicto',
    str_contains(ShiftAdjustmentValidationException::overlappingShift(88)->getMessage(), '88')
);
$assert(
    'El motivo exige 20 caracteres reales',
    ShiftAdjustmentValidationException::MINIMUM_REASON_LENGTH === 20
    && str_contains(ShiftAdjustmentValidationException::reasonTooShort()->getMessage(), '20')
);
$assert(
    'La duración máxima de turno es de 24 horas',
    ShiftAdjustmentValidationException::MAX_SHIFT_DURATION_SECONDS === 86400
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. TechnicianShiftLog · hidratación y serialización
// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. TechnicianShiftLog · entidad inmutable de bitácora\n";

$row = [
    'id' => '41',
    'shift_id' => '12',
    'technician_id' => '7',
    'event_type' => 'FORCE_CLOSE_SHIFT',
    'actor_user_id' => '2',
    'effective_at' => '2026-10-25 09:00:00+02',
    'previous_state' => '{"status":"ON_SHIFT","meal_break_seconds":0,"closed_at":null}',
    'new_state' => '{"status":"OFF_SHIFT","meal_break_seconds":3600,"closed_at":"2026-10-25T09:00:00+02:00"}',
    'reason' => 'Cierre forzado con hora real de salida tras olvido de fichaje.',
    'created_at' => '2026-10-25 09:00:03+02',
];

$log = TechnicianShiftLog::fromDatabaseRow($row);

$assert('Hidrata el identificador como entero', $log->id() === 41);
$assert('Hidrata el turno y el técnico', $log->shiftId() === 12 && $log->technicianId() === 7);
$assert('Hidrata el tipo de evento', $log->eventType() === ShiftEventType::FORCE_CLOSE_SHIFT);
$assert('Hidrata el actor', $log->actorUserId() === 2);
$assert('Hidrata la marca efectiva', $log->effectiveAt()->format('Y-m-d H:i:s') === '2026-10-25 09:00:00');
$assert(
    'Decodifica el estado previo JSONB',
    is_array($log->previousState()) && $log->previousState()['status'] === 'ON_SHIFT'
);
$assert(
    'Decodifica el estado resultante JSONB',
    $log->newState()['status'] === 'OFF_SHIFT' && $log->newState()['meal_break_seconds'] === 3600
);
$assert('Conserva el motivo íntegro', $log->reason() === $row['reason']);
$assert('Hidrata la fecha de creación', $log->createdAt()?->format('Y-m-d H:i:s') === '2026-10-25 09:00:03');

$serialized = $log->toArray();
$assert('Serializa el identificador del turno', $serialized['shift_id'] === 12);
$assert('Serializa el tipo de evento canónico', $serialized['event_type'] === 'FORCE_CLOSE_SHIFT');
$assert(
    'Serializa la etiqueta en castellano',
    $serialized['event_type_label'] === 'Cierre forzado por Coordinación'
);
$assert('Serializa el actor', $serialized['actor_user_id'] === 2);
$assert(
    'Serializa la marca efectiva en ISO 8601',
    $serialized['effective_at'] === $log->effectiveAt()->format(DateTimeInterface::ATOM)
);
$assert('Serializa el estado previo como estructura', $serialized['previous_state']['status'] === 'ON_SHIFT');
$assert('Serializa el estado resultante como estructura', $serialized['new_state']['status'] === 'OFF_SHIFT');
$assert('Serializa el motivo', $serialized['reason'] === $row['reason']);
$assert(
    'Serializa la fecha de creación en ISO 8601',
    $serialized['created_at'] === $log->createdAt()?->format(DateTimeInterface::ATOM)
);

$startLog = TechnicianShiftLog::fromDatabaseRow([
    'id' => 1,
    'shift_id' => 12,
    'technician_id' => 7,
    'event_type' => 'START_SHIFT',
    'actor_user_id' => 7,
    'effective_at' => '2026-10-25 08:00:00+02',
    'previous_state' => null,
    'new_state' => '{"status":"ON_SHIFT"}',
    'reason' => null,
    'created_at' => '2026-10-25 08:00:00+02',
]);
$assert('El primer fichaje no tiene estado previo', $startLog->previousState() === null);
$assert('El motivo es opcional en los eventos manuales', $startLog->reason() === null);
$assert('Un estado previo nulo se serializa como null', $startLog->toArray()['previous_state'] === null);

// La bitácora es append-only: la entidad no expone setters ni mutadores.
$mutators = array_filter(
    get_class_methods(TechnicianShiftLog::class),
    static fn (string $method): bool => str_starts_with($method, 'set')
        || str_starts_with($method, 'update')
        || str_starts_with($method, 'delete')
);
$assert('La entidad no expone métodos de mutación', $mutators === []);

// ─────────────────────────────────────────────────────────────────────────────
// Resultado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n----------------------------------------------------------------------\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "----------------------------------------------------------------------\n";

exit($failures === 0 ? 0 : 1);
