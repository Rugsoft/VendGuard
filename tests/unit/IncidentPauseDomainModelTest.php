<?php

declare(strict_types=1);

/**
 * IncidentPauseDomainModelTest
 *
 * Suite unitaria del agregado raíz del módulo 11 (tarea T-PAUSE-04). Certifica
 * que las reglas de negocio de la pausa viven donde la Constitución manda que
 * vivan —en el dominio, no en el controlador— y que lo hacen sin tocar nunca el
 * estado persistido hasta que un repositorio grabe el resultado:
 *
 * 1. `Incident` incorpora los atributos de pausa que la migración 016 persiste
 *    (causa tipificada, justificación, inicio del intervalo, acumulador de
 *    segundos y fecha límite de SLA) y los hidrata desde la fila de base de datos
 *    sin reventar en lectura (RF-01.4, RF-04.1).
 * 2. `pausePendingInfo()` sólo admite orígenes legales, exige la justificación de
 *    20 caracteres reales del Art. V.1 y devuelve una instancia nueva.
 * 3. `resumePendingInfo()` cierra el intervalo, acumula su duración exacta en
 *    segundos, sólo admite `IN_PROGRESS` o `ASSIGNED` y bloquea expresamente
 *    `RESOLVED` (RF-06.2, Art. V.1). Las pausas sucesivas se suman (RF-04.1).
 * 4. `shiftSlaTarget()` graba la fecha desplazada que calculará el Algoritmo 3
 *    (RF-03.3), porque el calendario de la sede pertenece al servicio.
 * 5. `Machine` soporta el estado formal "Fuera de servicio / Bloqueada por falta
 *    de acceso" (`BLOCKED_NO_ACCESS`), con precedencia sobre cualquier otra
 *    lectura operativa: una máquina nunca vuelve a leerse como apta para el
 *    servicio tras una cancelación por inactividad de la sede (RF-04.4, Art. V.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseDomainModelTest (T-PAUSE-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

/** Justificación válida de 20 caracteres reales exactos. */
$validReason = 'Edificio cerrado por festivo local.';
$validReasonShort = 'Conserje ausente.'; // 17 caracteres: por debajo del mínimo legal.

/**
 * Construye una incidencia de trabajo con los campos mínimos que exige el
 * agregado. Por defecto nace asignada, que es el origen operativo más habitual
 * de una pausa.
 */
$makeIncident = function (IncidentStatus $status = IncidentStatus::ASSIGNED): Incident {
    return new Incident(
        id: 142,
        ticketCode: 'INC-2026-00142',
        machineId: 10,
        locationId: 1,
        category: IncidentCategory::PRODUCT_JAM,
        description: 'No dispensa producto y muestra error de motor.',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: 7,
        assignedAt: '2026-10-08 09:00:00',
        createdAt: '2026-10-08 08:30:00'
    );
};

// =====================================================================
// GRUPO 1: Atributos de pausa, hidratación y proyección
// =====================================================================
echo "--- Grupo 1: Atributos de pausa, hidratación y proyección ---\n";

$freshIncident = $makeIncident();

$assert(
    '1.1 Una incidencia nueva nace sin pausa: contador a cero y sin marcas temporales',
    $freshIncident->isPendingInfo() === false
    && $freshIncident->isPaused() === false
    && $freshIncident->getPausedAt() === null
    && $freshIncident->getPendingInfoReasonCategory() === null
    && $freshIncident->getPendingInfoReasonText() === null
    && $freshIncident->getTotalPendingInfoSeconds() === 0
    && $freshIncident->getSlaTargetAt() === null
);

$hydratedRow = [
    'id' => 142,
    'ticket_code' => 'INC-2026-00142',
    'machine_id' => 10,
    'location_id' => 1,
    'category' => 'PRODUCT_JAM',
    'description' => 'No dispensa producto y muestra error de motor.',
    'urgency' => 'HIGH',
    'status' => 'PENDING_INFO',
    'assigned_technician_id' => 7,
    'pending_info_reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
    'pending_info_reason_text' => $validReason,
    'paused_at' => '2026-10-08 10:15:00',
    'total_pending_info_seconds' => '5400',
    'sla_target_at' => '2026-10-08 13:45:00',
];
$hydratedIncident = Incident::fromDatabaseRow($hydratedRow);

$assert(
    '1.2 fromDatabaseRow hidrata las cinco columnas de pausa de la migración 016',
    $hydratedIncident->isPendingInfo() === true
    && $hydratedIncident->isPaused() === true
    && $hydratedIncident->getPendingInfoReasonCategory() === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS
    && $hydratedIncident->getPendingInfoReasonText() === $validReason
    && $hydratedIncident->getPausedAt() === '2026-10-08 10:15:00'
    && $hydratedIncident->getTotalPendingInfoSeconds() === 5400
    && $hydratedIncident->getSlaTargetAt() === '2026-10-08 13:45:00',
    json_encode($hydratedIncident->toArray(), JSON_UNESCAPED_UNICODE)
);

$legacyRow = [
    'id' => 143,
    'ticket_code' => 'INC-2026-00143',
    'machine_id' => 11,
    'location_id' => 1,
    'category' => 'PRODUCT_JAM',
    'description' => 'Ticket anterior al módulo 11.',
    'urgency' => 'LOW',
    'status' => 'ASSIGNED',
];
$legacyIncident = Incident::fromDatabaseRow($legacyRow);

$assert(
    '1.3 Una fila previa al módulo 11 se hidrata sin pausa y sin lanzar excepción',
    $legacyIncident->isPaused() === false
    && $legacyIncident->getTotalPendingInfoSeconds() === 0
    && $legacyIncident->getPendingInfoReasonCategory() === null
    && $legacyIncident->getSlaTargetAt() === null
);

$negativeAccumulatorRejected = false;
try {
    new Incident(
        id: 144,
        ticketCode: 'INC-2026-00144',
        machineId: 12,
        locationId: 1,
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Acumulador corrupto.',
        urgency: UrgencyLevel::LOW,
        totalPendingInfoSeconds: -1
    );
} catch (InvalidArgumentException) {
    $negativeAccumulatorRejected = true;
}
$assert(
    '1.4 El acumulador de pausa no admite valores negativos en la entidad',
    $negativeAccumulatorRejected
);

$paddedReasonRow = array_merge($hydratedRow, ['pending_info_reason_text' => '   ' . $validReason . '   ']);
$assert(
    '1.5 La hidratación recorta los espacios sobrantes de la justificación',
    Incident::fromDatabaseRow($paddedReasonRow)->getPendingInfoReasonText() === $validReason
);

$pausedPayload = $hydratedIncident->toArray();

$assert(
    '1.6 toArray() proyecta el bloque de pausa con la etiqueta castellana de la causa',
    $pausedPayload['pending_info_reason_category'] === 'BUILDING_CLOSED_NO_ACCESS'
    && $pausedPayload['pending_info_reason_category_label'] === 'Edificio cerrado / Sin acceso a instalaciones'
    && $pausedPayload['pending_info_reason_text'] === $validReason
    && $pausedPayload['paused_at'] === '2026-10-08 10:15:00'
    && $pausedPayload['is_paused'] === true
    && $pausedPayload['total_pending_info_seconds'] === 5400
    && $pausedPayload['sla_target_at'] === '2026-10-08 13:45:00'
);

$assert(
    '1.7 jsonSerialize() y toArray() siguen siendo la misma proyección',
    $hydratedIncident->jsonSerialize() === $pausedPayload
);

// =====================================================================
// GRUPO 2: Declaración de la pausa (RF-01.1, RF-01.2, RF-01.3, Art. V.1)
// =====================================================================
echo "\n--- Grupo 2: Declaración de la pausa ---\n";

$pausedAt = new DateTimeImmutable('2026-10-08 10:15:00');
$assignedIncident = $makeIncident(IncidentStatus::ASSIGNED);
$pausedIncident = $assignedIncident->pausePendingInfo(
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    $validReason,
    $pausedAt
);

$assert(
    '2.1 La pausa devuelve una instancia nueva y no muta la receptora (inmutabilidad)',
    $pausedIncident !== $assignedIncident
    && $assignedIncident->getStatus() === IncidentStatus::ASSIGNED
    && $assignedIncident->isPaused() === false
    && $pausedIncident->isPaused() === true
);

$assert(
    '2.2 La incidencia pausada queda en PENDING_INFO con la causa y el motivo declarados',
    $pausedIncident->getStatus() === IncidentStatus::PENDING_INFO
    && $pausedIncident->isPendingInfo() === true
    && $pausedIncident->getPendingInfoReasonCategory() === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS
    && $pausedIncident->getPendingInfoReasonText() === $validReason
);

$assert(
    '2.3 La marca de inicio del intervalo se formatea con precisión de segundos (RNF-01)',
    $pausedIncident->getPausedAt() === '2026-10-08 10:15:00'
    && $pausedIncident->getPausedAt() === $pausedAt->format('Y-m-d H:i:s')
);

foreach ([
    ['ASSIGNED', IncidentStatus::ASSIGNED],
    ['IN_PROGRESS', IncidentStatus::IN_PROGRESS],
    ['PENDING_PARTS', IncidentStatus::PENDING_PARTS],
    ['REOPENED', IncidentStatus::REOPENED],
] as [$name, $origin]) {
    $assert(
        "2.4-2.7 Pausa legal desde {$name} (RF-01.1)",
        $makeIncident($origin)
            ->pausePendingInfo(IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION, $validReason, $pausedAt)
            ->getStatus() === IncidentStatus::PENDING_INFO
    );
}

$illegalOriginBlocked = false;
$illegalOriginFrom = null;
$illegalOriginTo = null;
try {
    $makeIncident(IncidentStatus::REGISTERED)
        ->pausePendingInfo(IncidentPauseReasonCategory::EXTERNAL_POWER_CUT, $validReason, $pausedAt);
} catch (InvalidTransitionException $exception) {
    $illegalOriginBlocked = true;
    $illegalOriginFrom = $exception->getFromStatus();
    $illegalOriginTo = $exception->getToStatus();
}
$assert(
    '2.8 No se pausa desde REGISTERED: sin asignación previa no hay intervención que bloquear',
    $illegalOriginBlocked
    && $illegalOriginFrom === IncidentStatus::REGISTERED
    && $illegalOriginTo === IncidentStatus::PENDING_INFO
);

$doublePauseBlocked = false;
try {
    $pausedIncident->pausePendingInfo(IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND, $validReason, $pausedAt);
} catch (InvalidTransitionException) {
    $doublePauseBlocked = true;
}
$assert(
    '2.9 Una doble pausa queda bloqueada: el intervalo vivo no se reinicia ni se solapa',
    $doublePauseBlocked
);

$shortReasonBlocked = false;
$shortReasonMessage = '';
try {
    $makeIncident()->pausePendingInfo(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, $validReasonShort, $pausedAt);
} catch (InvalidArgumentException $exception) {
    $shortReasonBlocked = true;
    $shortReasonMessage = $exception->getMessage();
}
$assert(
    '2.10 Una justificación de 17 caracteres es rechazada con el mínimo legal en el mensaje (Art. V.1)',
    $shortReasonBlocked && str_contains($shortReasonMessage, '20'),
    $shortReasonMessage
);

$exactTwenty = str_repeat('a', 20); // Al borde exacto del mínimo legal.
$assert(
    '2.11 Una justificación de exactamente 20 caracteres reales se acepta al borde',
    $makeIncident()
        ->pausePendingInfo(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, $exactTwenty, $pausedAt)
        ->getPendingInfoReasonText() === $exactTwenty
);

$multibyteReason = str_repeat('á', 20);
$multibyteAccepted = $makeIncident()
    ->pausePendingInfo(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, $multibyteReason, $pausedAt)
    ->getPendingInfoReasonText() === $multibyteReason;
$assert(
    '2.12 La medición es multibyte real: 20 acentos son 20 caracteres, no 40 bytes',
    $multibyteAccepted && mb_strlen($multibyteReason) === 20 && strlen($multibyteReason) === 40
);

$blankReasonBlocked = false;
try {
    $makeIncident()->pausePendingInfo(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, '                       ', $pausedAt);
} catch (InvalidArgumentException) {
    $blankReasonBlocked = true;
}
$assert(
    '2.13 Una justificación de solo espacios no vale como texto descriptivo',
    $blankReasonBlocked
);

$paddedPause = $makeIncident()->pausePendingInfo(
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    '   ' . $validReason . '   ',
    $pausedAt
);
$assert(
    '2.14 El umbral se mide sobre el texto recortado y se persiste sin espacios sobrantes',
    mb_strlen($validReason) > 20
    && $paddedPause->getPendingInfoReasonText() === $validReason
);

$assert(
    '2.15 Declarar la pausa no altera el acumulador: el intervalo suma al reanudar',
    $pausedIncident->getTotalPendingInfoSeconds() === 0
);

// =====================================================================
// GRUPO 3: Duración del intervalo vivo (RF-02.1, RF-03.2, RF-03.3)
// =====================================================================
echo "\n--- Grupo 3: Duración del intervalo vivo ---\n";

$assert(
    '3.1 Sin pausa abierta la duración viva es 0 (el reloj contractual no descuenta nada)',
    $assignedIncident->currentPauseDurationSeconds(new DateTimeImmutable('2026-10-08 12:00:00')) === 0
);

$assert(
    '3.2 Una pausa de 90 minutos se mide en segundos exactos (RNF-01)',
    $pausedIncident->currentPauseDurationSeconds(new DateTimeImmutable('2026-10-08 11:45:00')) === 5400
);

$assert(
    '3.3 Una referencia anterior a la pausa devuelve 0 y nunca un negativo',
    $pausedIncident->currentPauseDurationSeconds(new DateTimeImmutable('2026-10-08 09:00:00')) === 0
);

// =====================================================================
// GRUPO 4: Reanudación y acumulación (RF-02.3, RF-04.1, RF-06.2)
// =====================================================================
echo "\n--- Grupo 4: Reanudación y acumulación de intervalos ---\n";

$resumedAt = new DateTimeImmutable('2026-10-08 11:45:00');
$resumedIncident = $pausedIncident->resumePendingInfo(IncidentStatus::IN_PROGRESS, $resumedAt);

$assert(
    '4.1 La reanudación acumula los 90 minutos exactos y limpia la marca de pausa',
    $resumedIncident->getStatus() === IncidentStatus::IN_PROGRESS
    && $resumedIncident->isPaused() === false
    && $resumedIncident->getPausedAt() === null
    && $resumedIncident->getTotalPendingInfoSeconds() === 5400
    && $pausedIncident->isPaused() === true
    && $pausedIncident->getTotalPendingInfoSeconds() === 0,
    'acumulado: ' . $resumedIncident->getTotalPendingInfoSeconds()
);

$assert(
    '4.2 La reanudación conserva el último motivo conocido como contexto del bloqueo',
    $resumedIncident->getPendingInfoReasonCategory() === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS
    && $resumedIncident->getPendingInfoReasonText() === $validReason
);

$assert(
    '4.3 El destino ASSIGNED es legal: la sede responde tarde y el técnico debe volver',
    $pausedIncident->resumePendingInfo(IncidentStatus::ASSIGNED, $resumedAt)->getStatus() === IncidentStatus::ASSIGNED
);

$resolveBlocked = false;
$resolveFrom = null;
$resolveTo = null;
try {
    $pausedIncident->resumePendingInfo(IncidentStatus::RESOLVED, $resumedAt);
} catch (InvalidTransitionException $exception) {
    $resolveBlocked = true;
    $resolveFrom = $exception->getFromStatus();
    $resolveTo = $exception->getToStatus();
}
$assert(
    '4.4 PROHIBIDO reanudar directo a RESOLVED: exige intervención física previa (RF-06.2, Art. V.1)',
    $resolveBlocked
    && $resolveFrom === IncidentStatus::PENDING_INFO
    && $resolveTo === IncidentStatus::RESOLVED
);

$illegalTargetsBlocked = 0;
foreach ([IncidentStatus::CANCELLED, IncidentStatus::PENDING_PARTS, IncidentStatus::PENDING_INFO, IncidentStatus::CLOSED] as $illegalTarget) {
    try {
        $pausedIncident->resumePendingInfo($illegalTarget, $resumedAt);
    } catch (InvalidTransitionException) {
        $illegalTargetsBlocked++;
    }
}
$assert(
    '4.5 La reanudación sólo admite IN_PROGRESS o ASSIGNED; ninguna otra puerta se abre',
    $illegalTargetsBlocked === 4,
    "bloqueados: {$illegalTargetsBlocked}/4"
);

$noPauseBlocked = false;
try {
    $assignedIncident->resumePendingInfo(IncidentStatus::IN_PROGRESS, $resumedAt);
} catch (InvalidTransitionException) {
    $noPauseBlocked = true;
}
$assert(
    '4.6 Reanudar sin pausa abierta se rechaza (no se inventan intervalos)',
    $noPauseBlocked
);

// RF-04.1: dos pausas sucesivas en el mismo ticket se suman acumulativamente.
$secondPause = $resumedIncident->pausePendingInfo(
    IncidentPauseReasonCategory::EXTERNAL_POWER_CUT,
    'Corte de suministro general del inmueble durante la prueba de encendido.',
    new DateTimeImmutable('2026-10-08 12:00:00')
);
$secondResume = $secondPause->resumePendingInfo(IncidentStatus::ASSIGNED, new DateTimeImmutable('2026-10-08 13:00:00'));

$assert(
    '4.7 Las pausas sucesivas se suman: 90 min + 60 min = 9.000 segundos descontables (RF-04.1)',
    $secondResume->getTotalPendingInfoSeconds() === 9000
    && $secondResume->getStatus() === IncidentStatus::ASSIGNED
);

$assert(
    '4.8 El origen de la segunda pausa fue IN_PROGRESS, no la pausa anterior',
    $secondPause->getStatus() === IncidentStatus::PENDING_INFO
    && $secondResume->isPaused() === false
);

$zeroLengthResume = $pausedIncident->resumePendingInfo(
    IncidentStatus::IN_PROGRESS,
    new DateTimeImmutable('2026-10-08 10:00:00')
);
$assert(
    '4.9 Una reanudación anterior a la pausa suma 0 segundos (caso límite de relojes)',
    $zeroLengthResume->getTotalPendingInfoSeconds() === 0 && $zeroLengthResume->isPaused() === false
);

// =====================================================================
// GRUPO 5: Grabación de la fecha límite desplazada (RF-03.3)
// =====================================================================
echo "\n--- Grupo 5: Fecha límite de SLA desplazada ---\n";

$newDeadline = new DateTimeImmutable('2026-10-08 13:45:00');
$shiftedIncident = $resumedIncident->shiftSlaTarget($newDeadline);

$assert(
    '5.1 shiftSlaTarget graba la fecha y no muta la instancia receptora',
    $shiftedIncident->getSlaTargetAt() === '2026-10-08 13:45:00'
    && $resumedIncident->getSlaTargetAt() === null
);

$assert(
    '5.2 Un ticket sin SLA contractual previo puede recibir su primera fecha límite',
    $makeIncident()->shiftSlaTarget($newDeadline)->getSlaTargetAt() === '2026-10-08 13:45:00'
);

$composedFlow = $makeIncident(IncidentStatus::IN_PROGRESS)
    ->pausePendingInfo(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, $validReason, new DateTimeImmutable('2026-10-08 10:15:00'))
    ->resumePendingInfo(IncidentStatus::IN_PROGRESS, new DateTimeImmutable('2026-10-08 11:45:00'))
    ->shiftSlaTarget(new DateTimeImmutable('2026-10-08 13:45:00'));

$assert(
    '5.3 El flujo encadenado que usará IncidentPauseService produce acumulado y SLA nuevos',
    $composedFlow->getStatus() === IncidentStatus::IN_PROGRESS
    && $composedFlow->getTotalPendingInfoSeconds() === 5400
    && $composedFlow->getSlaTargetAt() === '2026-10-08 13:45:00'
    && $composedFlow->isPaused() === false,
    json_encode($composedFlow->toArray(), JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 6: Máquina fuera de servicio por acceso bloqueado (RF-04.4)
// =====================================================================
echo "\n--- Grupo 6: Máquina bloqueada por falta de acceso ---\n";

$makeMachine = function (?array $activeIncident = null, ?string $notes = null): Machine {
    return new Machine(
        id: 10,
        locationId: 1,
        code: 'VEND-0101',
        model: 'Sanden Vendo G-Drink',
        machineType: MachineType::PERISHABLE_FOOD,
        floorWing: 'Planta Baja - Urgencias',
        notes: $notes,
        isActive: true,
        createdAt: '2026-09-01 08:00:00',
        updatedAt: '2026-10-08 08:30:00',
        deletedAt: null,
        activeIncident: $activeIncident
    );
};

$cleanMachine = $makeMachine();
$assert(
    '6.1 Una máquina sin avería se lee como operativa y no está bloqueada',
    $cleanMachine->getOperationalStatus() === Machine::OPERATIONAL_STATUS_OPERATIONAL
    && $cleanMachine->isBlockedNoAccess() === false
    && $cleanMachine->isActive() === true
);

$activeIncidentRow = [
    'id' => 142,
    'ticket_code' => 'INC-2026-00142',
    'status' => 'PENDING_INFO',
    'category' => 'PRODUCT_JAM',
    'urgency' => 'HIGH',
    'created_at' => '2026-10-08 08:30:00',
    'resolved_at' => null,
    'is_in_warranty' => false,
];
$assert(
    '6.2 Una avería activa (también en pausa) mantiene la lectura ACTIVE_INCIDENT',
    $makeMachine($activeIncidentRow)->getOperationalStatus() === Machine::OPERATIONAL_STATUS_ACTIVE_INCIDENT
);

$assert(
    '6.3 Una avería resuelta se lee como IN_WARRANTY (ventana de 48 h, Art. V.6)',
    $makeMachine(array_merge($activeIncidentRow, ['status' => 'RESOLVED']))->getOperationalStatus() === Machine::OPERATIONAL_STATUS_IN_WARRANTY
);

$sourceMachine = $makeMachine($activeIncidentRow);
$blockedMachine = $sourceMachine->blockForNoAccess('inc-2026-00142');

$assert(
    '6.4 El bloqueo marca la máquina, la deja inactiva y anota el ticket causante (Algoritmo 5)',
    $blockedMachine->isBlockedNoAccess() === true
    && $blockedMachine->isActive() === false
    && $blockedMachine->getNotes() === '[Bloqueada por falta de acceso tras ticket INC-2026-00142]'
    && $blockedMachine->getOperationalStatus() === Machine::OPERATIONAL_STATUS_BLOCKED_NO_ACCESS
);

$assert(
    '6.5 El bloqueo tiene precedencia sobre la avería activa y sobre la garantía (RF-04.4)',
    $blockedMachine->getOperationalStatus() !== Machine::OPERATIONAL_STATUS_ACTIVE_INCIDENT
    && $makeMachine(array_merge($activeIncidentRow, ['status' => 'RESOLVED']))
        ->blockForNoAccess('INC-2026-00142')
        ->getOperationalStatus() === Machine::OPERATIONAL_STATUS_BLOCKED_NO_ACCESS
);

$assert(
    '6.6 La máquina bloqueada NO vuelve a figurar como operativa (Art. V.1)',
    $blockedMachine->getOperationalStatus() !== Machine::OPERATIONAL_STATUS_OPERATIONAL
    && $blockedMachine->isActive() === false
);

$rebocked = $blockedMachine->blockForNoAccess('INC-2026-00142');
$assert(
    '6.7 El bloqueo es idempotente: no duplica la anotación ni pisa la primera',
    $rebocked->getNotes() === $blockedMachine->getNotes()
    && substr_count((string)$rebocked->getNotes(), '[Bloqueada por falta de acceso') === 1
);

$machineWithNotes = $makeMachine($activeIncidentRow, 'Revisada tras la avería de septiembre.')->blockForNoAccess('INC-2026-00142');
$assert(
    '6.8 La anotación del bloqueo se anexa a las notas existentes sin borrarlas',
    str_starts_with((string)$machineWithNotes->getNotes(), 'Revisada tras la avería de septiembre. [Bloqueada')
);

$blockWithoutTicketRejected = false;
try {
    $makeMachine()->blockForNoAccess('   ');
} catch (InvalidArgumentException) {
    $blockWithoutTicketRejected = true;
}
$assert(
    '6.9 Un bloqueo sin ticket que lo motive se rechaza: sin trazabilidad no hay estado (Art. III)',
    $blockWithoutTicketRejected
);

$assert(
    '6.10 El bloqueo no muta la instancia receptora (inmutabilidad)',
    $blockedMachine !== $sourceMachine
    && $sourceMachine->isBlockedNoAccess() === false
    && $sourceMachine->isActive() === true
    && $sourceMachine->getNotes() === null
);

$blockedPayload = $blockedMachine->toArray();
$assert(
    '6.11 toArray() expone el bloqueo y el contrato operational_status al frontend',
    $blockedPayload['is_blocked_no_access'] === true
    && $blockedPayload['operational_status'] === 'BLOCKED_NO_ACCESS'
    && $blockedPayload['is_active'] === false
    && $cleanMachine->toArray()['operational_status'] === 'OPERATIONAL'
);

$machineRow = [
    'id' => 10,
    'location_id' => 1,
    'code' => 'VEND-0101',
    'model' => 'Sanden Vendo G-Drink',
    'machine_type' => 'PERISHABLE_FOOD',
    'floor_wing' => 'Planta Baja - Urgencias',
    'notes' => null,
    'is_active' => 0,
];
$assert(
    '6.12 fromDatabaseRow hidrata el bloqueo por clave dedicada, por contrato o por ausencia',
    Machine::fromDatabaseRow(array_merge($machineRow, ['is_blocked_no_access' => 1]))->isBlockedNoAccess() === true
    && Machine::fromDatabaseRow(array_merge($machineRow, ['operational_status' => 'BLOCKED_NO_ACCESS']))->isBlockedNoAccess() === true
    && Machine::fromDatabaseRow($machineRow)->isBlockedNoAccess() === false
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-04 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
