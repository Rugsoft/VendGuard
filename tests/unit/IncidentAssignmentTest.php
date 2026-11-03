<?php

declare(strict_types=1);

/**
 * IncidentAssignmentTest · T-CUSTODY-04
 *
 * Suite unitaria del tramo de custodia (Módulo 26, RF-01, D2, D4, R2, QA 2,
 * QA 4). Certifica las dos reglas que sostienen la cadena completa:
 *
 *  1. **Coherencia de cierre (EARS 01.2, R5).** Un tramo está abierto —sin
 *     ningún dato de salida— o cerrado con todos ellos; el estado a medias no se
 *     puede construir en memoria, igual que el motor no lo admite en la tabla.
 *  2. **Invariante temporal y retención en tiempo natural continuo (D4, QA 4).**
 *     El cierre nunca precede a la apertura y la retención se mide desde
 *     `assigned_at` hasta el cierre o hasta `$now`, con suelo en `0` para las
 *     resoluciones sincronizadas que llegan con la hora de un cliente móvil
 *     anterior a la apertura.
 *
 * Además fija la **tabla de verdad de `isUnresolvedTransferOrRelease()`** para
 * las diez causas de cierre (R2, QA 2): sólo los seis traspasos o liberaciones
 * sin resolver suman al contador de reasignaciones. Si esa tabla cambia, el
 * resumen de la cadena de custodia deja de decir la verdad aunque el código siga
 * compilando.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Enum\IncidentAssignmentSource;
use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;
use VendGuard\Core\Domain\Model\IncidentAssignment;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentAssignmentTest (T-CUSTODY-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";

        return;
    }

    echo "  [FAIL] {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

/** ¿El constructor rechaza la combinación declarada? */
$rejects = static function (callable $build): bool {
    try {
        $build();

        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};

$assignedAt = new DateTimeImmutable('2026-11-02 10:00:00+00:00');
$closedAt = new DateTimeImmutable('2026-11-02 12:30:00+00:00');

/**
 * Tramo de trabajo. Por defecto nace **abierto** y con los snapshots completos;
 * cada caso sobrescribe sólo lo que certifica.
 */
$makeAssignment = static function (
    ?DateTimeImmutable $unassignedAt = null,
    ?DateTimeImmutable $assignedAtOverride = null,
    ?IncidentUnassignmentReasonType $reasonType = null,
    ?int $unassignedBy = null,
    ?string $unassignedByName = null,
    ?string $reassignmentReason = null,
    ?int $id = null,
    int $incidentId = 12,
    int $technicianId = 5,
    string $technicianName = 'Jordi Puig',
    string $technicianCode = 'OP-07',
    IncidentAssignmentSource $source = IncidentAssignmentSource::COORDINATOR_ASSIGN,
    ?int $assignedBy = 2,
    string $assignedByName = 'Coordinación de Servicio'
): IncidentAssignment {
    return new IncidentAssignment(
        $id,
        $incidentId,
        $technicianId,
        $technicianName,
        $technicianCode,
        $source,
        $assignedBy,
        $assignedByName,
        $assignedAtOverride ?? new DateTimeImmutable('2026-11-02 10:00:00+00:00'),
        $unassignedAt,
        $unassignedBy,
        $unassignedByName,
        $reasonType,
        $reassignmentReason
    );
};

echo "--- Grupo 1: Tramo abierto ---\n";

$active = $makeAssignment();
$assert('1.1 Un tramo recién abierto está activo', $active->isActive() === true);
$assert('1.2 Un tramo activo no declara fecha de cierre', $active->getUnassignedAt() === null);
$assert('1.3 Un tramo activo no declara causa de cierre', $active->getUnassignmentReasonType() === null);
$assert('1.4 Un tramo activo no declara motivo de cierre', $active->getReassignmentReason() === null);
$assert(
    '1.5 Los snapshots de apertura se conservan tal como se congelaron (D2)',
    $active->getTechnicianNameSnapshot() === 'Jordi Puig'
    && $active->getTechnicianCodeSnapshot() === 'OP-07'
    && $active->getAssignedByNameSnapshot() === 'Coordinación de Servicio'
    && $active->getAssignmentSource() === IncidentAssignmentSource::COORDINATOR_ASSIGN
);
$assert('1.6 El identificador puede ser nulo antes de persistir', $active->getId() === null);

echo "\n--- Grupo 2: Tramo cerrado ---\n";

$closed = $makeAssignment(
    $closedAt,
    null,
    IncidentUnassignmentReasonType::RESOLVED,
    5,
    'Jordi Puig',
    'Sustituido el presostato y verificado el ciclo de frío.'
);
$assert('2.1 Un tramo con fecha de cierre no está activo', $closed->isActive() === false);
$assert('2.2 El cierre conserva su causa tipificada', $closed->getUnassignmentReasonType() === IncidentUnassignmentReasonType::RESOLVED);
$assert('2.3 El cierre conserva el nombre del actor (D2)', $closed->getUnassignedByNameSnapshot() === 'Jordi Puig');
$assert('2.4 El cierre conserva el motivo o diagnóstico', $closed->getReassignmentReason() === 'Sustituido el presostato y verificado el ciclo de frío.');

echo "\n--- Grupo 3: Coherencia de cierre (EARS 01.2, R5) ---\n";

$assert(
    '3.1 Un tramo cerrado sin causa tipificada se rechaza',
    $rejects(static fn() => $makeAssignment($closedAt, null, null, 5, 'Jordi Puig', 'Motivo suficientemente largo'))
);
$assert(
    '3.2 Un tramo cerrado sin actor de cierre se rechaza',
    $rejects(static fn() => $makeAssignment($closedAt, null, IncidentUnassignmentReasonType::RESOLVED, 5, null, 'Motivo suficientemente largo'))
);
$assert(
    '3.3 Un tramo cerrado sin motivo de cierre se rechaza',
    $rejects(static fn() => $makeAssignment($closedAt, null, IncidentUnassignmentReasonType::RESOLVED, 5, 'Jordi Puig', null))
);
$assert(
    '3.4 Un tramo cerrado con motivo en blanco se rechaza',
    $rejects(static fn() => $makeAssignment($closedAt, null, IncidentUnassignmentReasonType::RESOLVED, 5, 'Jordi Puig', '    '))
);
$assert(
    '3.5 Un tramo abierto con causa de cierre se rechaza',
    $rejects(static fn() => $makeAssignment(null, null, IncidentUnassignmentReasonType::RESOLVED))
);
$assert(
    '3.6 Un tramo abierto con fecha de cierre pero sin causa se rechaza',
    $rejects(static fn() => $makeAssignment($closedAt))
);
$assert(
    '3.7 Un tramo con snapshots de apertura vacíos se rechaza',
    $rejects(static fn() => $makeAssignment(null, null, null, null, null, null, null, 12, 5, '   '))
);
$assert(
    '3.8 Un identificador persistido no positivo se rechaza',
    $rejects(static fn() => $makeAssignment(null, null, null, null, null, null, 0))
);
$assert(
    '3.9 Un identificador de incidencia no positivo se rechaza',
    $rejects(static fn() => $makeAssignment(null, null, null, null, null, null, null, 0))
);
$assert(
    '3.10 Un identificador de técnico no positivo se rechaza',
    $rejects(static fn() => $makeAssignment(null, null, null, null, null, null, null, 12, 0))
);

echo "\n--- Grupo 4: Invariante temporal y retención (D4, QA 4) ---\n";

$assert(
    '4.1 Un cierre anterior a la apertura se rechaza',
    $rejects(static fn() => $makeAssignment(
        new DateTimeImmutable('2026-11-02 09:59:59+00:00'),
        null,
        IncidentUnassignmentReasonType::REASSIGNED_BY_COORDINATOR,
        2,
        'Coordinación de Servicio',
        'Reasignada por ausencia del titular'
    ))
);

$assert(
    '4.2 La retención de un tramo cerrado es el intervalo exacto hasta su cierre',
    $closed->retentionSecondsAt(new DateTimeImmutable('2026-11-03 00:00:00+00:00')) === 9000,
    'esperado 9000 s (2 h 30 min), leído ' . $closed->retentionSecondsAt(new DateTimeImmutable('2026-11-03 00:00:00+00:00'))
);

$assert(
    '4.3 La retención de un tramo activo crece hasta el instante de lectura',
    $active->retentionSecondsAt(new DateTimeImmutable('2026-11-02 10:45:00+00:00')) === 2700,
    'esperado 2700 s (45 min), leído ' . $active->retentionSecondsAt(new DateTimeImmutable('2026-11-02 10:45:00+00:00'))
);

$assert(
    '4.4 Una lectura anterior a la apertura devuelve 0 y nunca un negativo',
    $active->retentionSecondsAt(new DateTimeImmutable('2026-11-02 09:00:00+00:00')) === 0
);

$zeroDuration = $makeAssignment(
    new DateTimeImmutable('2026-11-02 10:00:00+00:00'),
    null,
    IncidentUnassignmentReasonType::RESOLVED,
    5,
    'Jordi Puig',
    'Suelo temporal aplicado en la sincronización diferida.'
);
$assert(
    '4.5 Un cierre igual a la apertura dura exactamente 0 s (suelo temporal, QA 4)',
    $zeroDuration->isActive() === false && $zeroDuration->retentionSecondsAt(new DateTimeImmutable('2026-11-02 18:00:00+00:00')) === 0
);

echo "\n--- Grupo 5: Catálogos cerrados de apertura y cierre ---\n";

$assert(
    '5.1 El canal de apertura declara los 9 valores canónicos del motor',
    count(IncidentAssignmentSource::cases()) === 9
);
$assert(
    '5.2 Las 10 causas de cierre declaran su etiqueta en castellano',
    count(IncidentUnassignmentReasonType::cases()) === 10
    && array_filter(
        IncidentUnassignmentReasonType::cases(),
        static fn(IncidentUnassignmentReasonType $reason): bool => trim($reason->label()) === ''
    ) === []
);
$assert(
    '5.3 Los 9 canales declaran su etiqueta en castellano',
    array_filter(
        IncidentAssignmentSource::cases(),
        static fn(IncidentAssignmentSource $source): bool => trim($source->label()) === ''
    ) === []
);

echo "\n--- Grupo 6: Tabla de verdad de isUnresolvedTransferOrRelease() (R2, QA 2) ---\n";

$expectedTransfers = [
    'REASSIGNED_BY_COORDINATOR' => true,
    'ABSENCE_COVERAGE_REASSIGN' => true,
    'TAKEN_OVER_IN_SITU' => true,
    'SHIFT_HANDOVER' => true,
    'SANITARY_FAILSAFE_GUARD' => true,
    'SANITARY_FAILSAFE_UNASSIGNED' => true,
    'RESOLVED' => false,
    'CHRONIC_REPLACEMENT_CLOSED' => false,
    'WARRANTY_REOPENED' => false,
    'CANCELLED' => false,
];

foreach ($expectedTransfers as $value => $expected) {
    $reason = IncidentUnassignmentReasonType::from($value);
    $assert(
        sprintf('6.x %s %s suma al contador de reasignaciones', $value, $expected ? 'SÍ' : 'NO'),
        $reason->isUnresolvedTransferOrRelease() === $expected,
        'el método devolvió ' . ($reason->isUnresolvedTransferOrRelease() ? 'true' : 'false')
    );
}

$transfers = array_filter(
    IncidentUnassignmentReasonType::cases(),
    static fn(IncidentUnassignmentReasonType $reason): bool => $reason->isUnresolvedTransferOrRelease()
);
$assert(
    '6.11 Exactamente 6 de las 10 causas suman al contador',
    count($transfers) === 6,
    'encontradas: ' . count($transfers)
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-CUSTODY-04 (entidad de tramo) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
