<?php

declare(strict_types=1);

/**
 * TechnicianAbsenceDomainTest · T-ASH-02
 *
 * Certifica la lógica de dominio pura y las invariantes de ausencias de técnicos
 * (Módulo 21) sin base de datos:
 *
 *   1. Enums de motivo: `AbsenceReason` y sus etiquetas en castellano.
 *   2. Creación e invariantes temporales:
 *      - `ends_at >= starts_at` (rechazo si `ends_at < starts_at`).
 *      - Notas opcionales acotadas a 500 caracteres.
 *   3. Comprobación de vigencia: `isActiveAt()` evalúa pertenencia al rango.
 *   4. Cancelación lógica inmutable:
 *      - Motivo obligatorio >= 5 caracteres tras `trim`.
 *      - Rechazo de cancelación duplicada.
 *      - La cancelación inactiva la vigencia en `isActiveAt()`.
 *   5. Serialización e hidratación desde fila de base de datos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

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
echo " VendGuard · T-ASH-02 · Dominio de Ausencias de Técnicos (Unit)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Enums y etiquetas
// ─────────────────────────────────────────────────────────────────────────────
$assert(
    'AbsenceReason enum cuenta con los seis casos tipificados',
    count(AbsenceReason::cases()) === 6
);

$assert(
    'AbsenceReason::VACATION etiqueta como Vacaciones',
    AbsenceReason::VACATION->label() === 'Vacaciones'
);

$assert(
    'AbsenceReason::SICK_LEAVE etiqueta como Baja médica',
    AbsenceReason::SICK_LEAVE->label() === 'Baja médica'
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Creación e invariantes
// ─────────────────────────────────────────────────────────────────────────────
$startsAt = new DateTimeImmutable('2026-11-01 00:00:00+00');
$endsAt = new DateTimeImmutable('2026-11-15 23:59:59+00');

$absence = TechnicianAbsence::create(
    technicianId: 7,
    reason: AbsenceReason::VACATION,
    startsAt: $startsAt,
    endsAt: $endsAt,
    notes: 'Vacaciones de noviembre',
    createdBy: 1
);

$assert('TechnicianAbsence se crea con datos válidos', $absence->technicianId() === 7);
$assert('TechnicianAbsence no está cancelada al crearse', !$absence->isCancelled());
$assert('TechnicianAbsence preserva el motivo', $absence->reason() === AbsenceReason::VACATION);

// Rechazo de ends_at < starts_at
$rejectedDates = false;
try {
    TechnicianAbsence::create(
        technicianId: 7,
        reason: AbsenceReason::VACATION,
        startsAt: new DateTimeImmutable('2026-11-15 00:00:00+00'),
        endsAt: new DateTimeImmutable('2026-11-01 00:00:00+00'),
        notes: null,
        createdBy: 1
    );
} catch (InvalidAbsenceException $e) {
    $rejectedDates = $e->getErrorCode() === 'INVALID_DATE_RANGE';
}
$assert('TechnicianAbsence rechaza ends_at < starts_at con INVALID_DATE_RANGE', $rejectedDates);

// Rechazo de notas demasiado largas (> 500 caracteres)
$rejectedNotes = false;
try {
    TechnicianAbsence::create(
        technicianId: 7,
        reason: AbsenceReason::OTHER,
        startsAt: $startsAt,
        endsAt: $endsAt,
        notes: str_repeat('a', 501),
        createdBy: 1
    );
} catch (InvalidAbsenceException $e) {
    $rejectedNotes = $e->getErrorCode() === 'NOTES_TOO_LONG';
}
$assert('TechnicianAbsence rechaza notas > 500 caracteres con NOTES_TOO_LONG', $rejectedNotes);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Comprobación de vigencia (isActiveAt)
// ─────────────────────────────────────────────────────────────────────────────
$assert(
    'isActiveAt() devuelve true dentro de la ventana de fechas',
    $absence->isActiveAt(new DateTimeImmutable('2026-11-05 12:00:00+00'))
);

$assert(
    'isActiveAt() devuelve false antes del inicio',
    !$absence->isActiveAt(new DateTimeImmutable('2026-10-31 23:59:59+00'))
);

$assert(
    'isActiveAt() devuelve false después del fin',
    !$absence->isActiveAt(new DateTimeImmutable('2026-11-16 00:00:00+00'))
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Cancelación lógica inmutable
// ─────────────────────────────────────────────────────────────────────────────
$rejectedCancelShort = false;
try {
    $absence->cancel(cancelledBy: 1, cancellationReason: 'abc');
} catch (InvalidAbsenceException $e) {
    $rejectedCancelShort = $e->getErrorCode() === 'CANCELLED_REASON_TOO_SHORT';
}
$assert('cancel() rechaza motivo < 5 caracteres con CANCELLED_REASON_TOO_SHORT', $rejectedCancelShort);

$cancelledAbsence = $absence->cancel(cancelledBy: 1, cancellationReason: 'Alta médica anticipada');
$assert('cancel() genera una nueva instancia inmutable cancelada', $cancelledAbsence->isCancelled());
$assert('cancel() no muta la instancia original', !$absence->isCancelled());
$assert('isActiveAt() devuelve false en una ausencia cancelada', !$cancelledAbsence->isActiveAt(new DateTimeImmutable('2026-11-05 12:00:00+00')));

// Rechazo de cancelar una ya cancelada
$rejectedAlreadyCancelled = false;
try {
    $cancelledAbsence->cancel(cancelledBy: 1, cancellationReason: 'Intento duplicado');
} catch (InvalidAbsenceException $e) {
    $rejectedAlreadyCancelled = $e->getErrorCode() === 'ABSENCE_ALREADY_CANCELLED'
        && $e->getHttpStatusCode() === 409;
}
$assert('cancel() rechaza cancelar una ausencia ya cancelada', $rejectedAlreadyCancelled);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Hidratación y serialización
// ─────────────────────────────────────────────────────────────────────────────
$dbRow = [
    'id' => 10,
    'technician_id' => 7,
    'reason' => 'SICK_LEAVE',
    'starts_at' => '2026-11-01 00:00:00+00',
    'ends_at' => '2026-11-05 23:59:59+00',
    'notes' => 'Gripe común',
    'created_by' => 2,
    'created_at' => '2026-10-23 10:00:00+00',
    'cancelled_at' => null,
    'cancelled_by' => null,
    'cancellation_reason' => null,
];

$fromDb = TechnicianAbsence::fromDatabaseRow($dbRow);
$assert('fromDatabaseRow hidrata correctamente el id', $fromDb->id() === 10);
$assert('fromDatabaseRow hidrata el Enum AbsenceReason', $fromDb->reason() === AbsenceReason::SICK_LEAVE);
$assert('fromDatabaseRow hidrata las fechas', $fromDb->startsAt()->format('Y-m-d') === '2026-11-01');

$arrayRepresentation = $fromDb->toArray();
$assert('toArray exporta el reason como string', $arrayRepresentation['reason'] === 'SICK_LEAVE');
$assert('toArray exporta el label correspondiente', $arrayRepresentation['reason_label'] === 'Baja médica');

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
