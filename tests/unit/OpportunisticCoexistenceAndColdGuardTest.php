<?php

declare(strict_types=1);

/**
 * OpportunisticCoexistenceAndColdGuardTest
 *
 * Suite de pruebas unitarias de las dos políticas puras de salvaguarda del
 * Módulo 16 (T-OPP-04):
 *  - `OpportunisticCoexistencePolicy`: coexistencia dentro de la misma sede,
 *    bloqueo `409` en sede distinta y ordenación de la Parada #1 por
 *    urgencia/SLA (RF-02.1, RF-02.2 y Resolución QA 4).
 *  - `OpportunisticColdChainGuardPolicy`: advertencia no bloqueante del Art. II
 *    ante paradas críticas, de frío o en cuarentena sanitaria en otra sede
 *    (RF-03.1, RF-03.2 y Resolución QA 1).
 *
 * Dogma Vanilla: ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\ActiveInterventionConflictException;
use VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException;
use VendGuard\Core\Service\OpportunisticCoexistencePolicy;
use VendGuard\Core\Service\OpportunisticColdChainGuardPolicy;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - OpportunisticCoexistenceAndColdGuardTest\n";
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

$coexistence = new OpportunisticCoexistencePolicy();
$coldGuard = new OpportunisticColdChainGuardPolicy();

$inProgress = static fn (array $overrides = []): array => array_merge([
    'id' => 100,
    'ticket_code' => 'INC-2026-0100',
    'status' => 'IN_PROGRESS',
    'record_type' => 'INCIDENT',
    'urgency' => 'MEDIUM',
    'sla_target_at' => '2026-10-19T12:00:00+02:00',
    'started_at' => '2026-10-19T08:00:00+02:00',
    'location_id' => 8,
], $overrides);

$pendingStop = static fn (array $overrides = []): array => array_merge([
    'id' => 100,
    'incident_id' => 100,
    'ticket_code' => 'INC-2026-0100',
    'status' => 'ASSIGNED',
    'urgency' => 'MEDIUM',
    'category' => 'PRODUCT_JAM',
    'machine_type' => 'SNACKS',
    'sanitary_status' => 'OK',
    'machine_code' => 'MAQ-008',
    'location_id' => 8,
    'location_name' => 'Centro Logístico Zona Franca',
    'sla_target_at' => '2026-10-19T12:00:00+02:00',
], $overrides);

// =====================================================================
// GRUPO 1: Coexistencia dentro de la misma sede (RF-02.1)
// =====================================================================
echo "--- Grupo 1: Coexistencia permitida en la misma sede ---\n";

$sameLocationStops = [
    $inProgress(['id' => 101, 'location_id' => 3]),
    $inProgress(['id' => 102, 'record_type' => 'PREVENTIVE_ORDER', 'status' => 'IN_INSPECTION', 'location_id' => 3]),
];

$assert(
    '1.1 Varias intervenciones abiertas en la misma sede no generan conflicto',
    $coexistence->findBlockingIntervention(3, $sameLocationStops) === null
);

$noThrow = true;
try {
    $coexistence->assertNoBlockingIntervention(3, $sameLocationStops);
} catch (Throwable $e) {
    $noThrow = false;
}
$assert('1.2 assertNoBlockingIntervention no lanza en la misma sede', $noThrow);

$assert(
    '1.3 Una parada no iniciada (ASSIGNED) no bloquea aunque esté en otra sede',
    $coexistence->findBlockingIntervention(3, [$inProgress(['status' => 'ASSIGNED'])]) === null
);

$assert(
    '1.4 Una parada ya cerrada (CLOSED) no bloquea',
    $coexistence->findBlockingIntervention(3, [$inProgress(['status' => 'CLOSED'])]) === null
);

$assert(
    '1.5 Una parada sin sede atribuible no puede bloquear',
    $coexistence->findBlockingIntervention(3, [$inProgress(['location_id' => null])]) === null
);

// =====================================================================
// GRUPO 2: Bloqueo por intervención olvidada en otra sede (RF-02.2)
// =====================================================================
echo "\n--- Grupo 2: Bloqueo 409 en sede distinta ---\n";

$conflict = $coexistence->findBlockingIntervention(3, [
    $inProgress(['id' => 201, 'location_id' => 8, 'ticket_code' => 'INC-2026-0201']),
]);

$assert(
    '2.1 Una incidencia EN CURSO en otra sede bloquea la operación',
    $conflict !== null
        && $conflict['id'] === 201
        && $conflict['record_type'] === OpportunisticCoexistencePolicy::RECORD_INCIDENT
);
$assert(
    '2.2 El conflicto conserva ticket y sede para el mensaje',
    $conflict !== null
        && $conflict['ticket_code'] === 'INC-2026-0201'
        && $conflict['location_id'] === 8
);

$caught = null;
try {
    $coexistence->assertNoBlockingIntervention(3, [
        $inProgress(['id' => 202, 'location_id' => 8, 'ticket_code' => 'INC-2026-0202']),
    ]);
} catch (ActiveInterventionConflictException $e) {
    $caught = $e;
} catch (Throwable $e) {
    $caught = $e;
}

$assert(
    '2.3 assertNoBlockingIntervention lanza ActiveInterventionConflictException',
    $caught instanceof ActiveInterventionConflictException
);
$assert(
    '2.4 La excepción conserva código y HTTP 409',
    $caught instanceof ActiveInterventionConflictException
        && $caught->getErrorCode() === 'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION'
        && $caught->getHttpStatusCode() === 409
);
$assert(
    '2.5 La excepción transporta el expediente en conflicto',
    $caught instanceof ActiveInterventionConflictException
        && $caught->getConflict()['ticket_code'] === 'INC-2026-0202'
);
$assert(
    '2.6 El mensaje pide finalizar o pausar antes de continuar',
    $caught instanceof ActiveInterventionConflictException
        && str_contains($caught->getMessage(), 'Finalice o pause')
);

$preventiveConflict = $coexistence->findBlockingIntervention(3, [
    $inProgress([
        'id' => 203,
        'status' => 'IN_INSPECTION',
        'record_type' => '',
        'location_id' => 9,
    ]),
]);

$assert(
    '2.7 Un preventivo EN INSPECCIÓN en otra sede bloquea y se tipifica como orden',
    $preventiveConflict !== null
        && $preventiveConflict['record_type'] === OpportunisticCoexistencePolicy::RECORD_PREVENTIVE_ORDER
);

$nestedLocation = $coexistence->findBlockingIntervention(3, [
    [
        'id' => 204,
        'status' => 'IN_PROGRESS',
        'location' => ['id' => 7],
        'ticket_code' => 'INC-2026-0204',
    ],
]);

$assert(
    '2.8 La sede anidada en location.id también se reconoce',
    $nestedLocation !== null && $nestedLocation['location_id'] === 7
);

$oldest = $coexistence->findBlockingIntervention(3, [
    $inProgress(['id' => 206, 'location_id' => 9, 'started_at' => '2026-10-19T09:30:00+02:00']),
    $inProgress(['id' => 205, 'location_id' => 8, 'started_at' => '2026-10-19T09:00:00+02:00']),
]);
$assert(
    '2.9 Con varios conflictos se informa del más antiguo (determinista)',
    $oldest !== null && $oldest['id'] === 205
);

// =====================================================================
// GRUPO 3: Ordenación de la Parada #1 (RF-02.1, Resolución QA 4)
// =====================================================================
echo "\n--- Grupo 3: Ordenación por urgencia y SLA ---\n";

$ordered = $coexistence->orderInProgressStops([
    $inProgress(['id' => 11, 'urgency' => 'LOW', 'sla_target_at' => '2026-10-19T10:00:00+02:00']),
    $inProgress(['id' => 12, 'urgency' => 'CRITICAL', 'sla_target_at' => '2026-10-19T12:00:00+02:00']),
    $inProgress(['id' => 13, 'urgency' => 'HIGH', 'sla_target_at' => '2026-10-19T11:00:00+02:00']),
    $inProgress(['id' => 14, 'urgency' => 'MEDIUM', 'sla_target_at' => '2026-10-19T09:00:00+02:00']),
]);
$assert(
    '3.1 Orden descendente por urgencia (CRITICAL > HIGH > MEDIUM > LOW)',
    array_column($ordered, 'id') === [12, 13, 14, 11]
);

$ordered = $coexistence->orderInProgressStops([
    $inProgress(['id' => 21, 'urgency' => 'HIGH', 'sla_target_at' => '2026-10-19T12:00:00+02:00']),
    $inProgress(['id' => 22, 'urgency' => 'HIGH', 'sla_target_at' => '2026-10-19T10:00:00+02:00']),
    $inProgress(['id' => 23, 'urgency' => 'HIGH', 'sla_target_at' => null]),
]);
$assert(
    '3.2 A igual urgencia manda el SLA más próximo, y el SLA ausente va al final',
    array_column($ordered, 'id') === [22, 21, 23]
);

$ordered = $coexistence->orderInProgressStops([
    $inProgress(['id' => 31, 'urgency' => 'MEDIUM', 'started_at' => '2026-10-19T09:00:00+02:00']),
    $inProgress(['id' => 32, 'urgency' => 'MEDIUM', 'started_at' => '2026-10-19T08:00:00+02:00']),
]);
$assert(
    '3.3 A igual urgencia y SLA se ordena por inicio más antiguo',
    array_column($ordered, 'id') === [32, 31]
);

$ordered = $coexistence->orderInProgressStops([
    $inProgress(['id' => 42, 'urgency' => 'MEDIUM']),
    $inProgress(['id' => 41, 'urgency' => 'MEDIUM']),
]);
$assert(
    '3.4 El identificador desempata de forma estable',
    array_column($ordered, 'id') === [41, 42]
);

$ordered = $coexistence->orderInProgressStops([
    $inProgress([
        'id' => 50,
        'record_type' => 'PREVENTIVE_ORDER',
        'status' => 'IN_INSPECTION',
        'urgency' => 'CRITICAL',
    ]),
    $inProgress(['id' => 51, 'urgency' => 'LOW']),
]);
$assert(
    '3.5 Las correctivas siempre preceden a los preventivos, aunque sean menos urgentes',
    array_column($ordered, 'id') === [51, 50]
);

$ordered = $coexistence->orderInProgressStops([
    $inProgress(['id' => 60, 'urgency' => 'BOGUS']),
    $inProgress(['id' => 61, 'urgency' => 'LOW']),
]);
$assert(
    '3.6 Una urgencia desconocida se trata como la más baja, nunca como crítica',
    array_column($ordered, 'id') === [61, 60]
);

$original = [
    $inProgress(['id' => 71, 'urgency' => 'LOW']),
    $inProgress(['id' => 72, 'urgency' => 'CRITICAL']),
];
$originalIds = array_column($original, 'id');
$coexistence->orderInProgressStops($original);
$assert(
    '3.7 La ordenación no muta la lista recibida',
    array_column($original, 'id') === $originalIds
);

$repeat = $coexistence->orderInProgressStops(array_reverse($original));
$assert(
    '3.8 El orden es determinista con independencia del orden de entrada',
    array_column($repeat, 'id') === [72, 71]
);

// =====================================================================
// GRUPO 4: Detección de la advertencia sanitaria (RF-03.1, Art. II)
// =====================================================================
echo "\n--- Grupo 4: Detección de emergencias en otras sedes ---\n";

$cases = [
    'una urgencia CRITICAL' => [
        ['urgency' => 'CRITICAL'],
        OpportunisticColdChainGuardPolicy::TRIGGER_CRITICAL_URGENCY,
    ],
    'perecederos con fallo térmico' => [
        ['machine_type' => 'PERISHABLE_FOOD', 'category' => 'TEMPERATURE_COLD'],
        OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH,
    ],
    'combo con corte eléctrico' => [
        ['machine_type' => 'COMBO', 'category' => 'ELECTRICAL_OFF'],
        OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH,
    ],
    'cuarentena sanitaria del Módulo 11' => [
        ['machine_type' => 'HOT_DRINKS', 'sanitary_status' => 'QUARANTINE'],
        OpportunisticColdChainGuardPolicy::TRIGGER_SANITARY_QUARANTINE,
    ],
];

$caseIndex = 0;
foreach ($cases as $description => [$overrides, $expectedTrigger]) {
    $caseIndex++;
    $stop = $pendingStop($overrides);
    $assert(
        "4.{$caseIndex} Dispara la advertencia: {$description}",
        $coldGuard->isCriticalOrSanitaryStop($stop)
            && $coldGuard->warningTrigger($stop) === $expectedTrigger
    );
}

$nonTriggering = [
    'perecederos con atasco de producto' => ['machine_type' => 'PERISHABLE_FOOD', 'category' => 'PRODUCT_JAM'],
    'bebidas frías con fallo térmico' => ['machine_type' => 'COLD_DRINKS', 'category' => 'TEMPERATURE_COLD'],
    'snacks con corte eléctrico' => ['machine_type' => 'SNACKS', 'category' => 'ELECTRICAL_OFF'],
    'máquina sin tipología declarada' => ['machine_type' => null, 'category' => 'TEMPERATURE_COLD'],
];

$caseIndex = 0;
foreach ($nonTriggering as $description => $overrides) {
    $caseIndex++;
    $assert(
        "4.10.{$caseIndex} No dispara la advertencia: {$description}",
        !$coldGuard->isCriticalOrSanitaryStop($pendingStop($overrides))
    );
}

$assert(
    '4.11 Una parada crítica en la MISMA sede no se considera postergada',
    $coldGuard->findPendingCriticalStops(3, [
        $pendingStop(['location_id' => 3, 'urgency' => 'CRITICAL']),
    ]) === []
);

$assert(
    '4.12 Una parada resuelta o cerrada ya no retiene al técnico',
    $coldGuard->findPendingCriticalStops(3, [
        $pendingStop(['status' => 'RESOLVED', 'urgency' => 'CRITICAL']),
        $pendingStop(['status' => 'CLOSED', 'urgency' => 'CRITICAL']),
        $pendingStop(['status' => 'CANCELLED', 'urgency' => 'CRITICAL']),
    ]) === []
);

$assert(
    '4.13 Un PENDING_INFO en otra sede sí cuenta como pendiente',
    count($coldGuard->findPendingCriticalStops(3, [
        $pendingStop(['status' => 'PENDING_INFO', 'urgency' => 'CRITICAL']),
    ])) === 1
);

$assert(
    '4.14 Sin paradas pendientes no hay advertencia',
    $coldGuard->requiresConfirmation(3, []) === false
);

$flagged = $coldGuard->findPendingCriticalStops(3, [
    $pendingStop([
        'id' => 302,
        'urgency' => 'HIGH',
        'machine_type' => 'PERISHABLE_FOOD',
        'category' => 'TEMPERATURE_COLD',
    ]),
    $pendingStop(['id' => 301, 'urgency' => 'CRITICAL', 'ticket_code' => 'INC-2026-0301']),
]);

$assert(
    '4.15 La lista postergada se ordena por urgencia',
    array_column($flagged, 'id') === [301, 302]
);
$assert(
    '4.16 Cada parada lleva su disparador y su sede',
    count($flagged) === 2
        && $flagged[0]['warning_trigger'] === OpportunisticColdChainGuardPolicy::TRIGGER_CRITICAL_URGENCY
        && $flagged[0]['location_id'] === 8
        && $flagged[1]['warning_trigger'] === OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH
);
$assert(
    '4.17 El payload conserva los datos que pinta el modal',
    $flagged[0]['ticket_code'] === 'INC-2026-0301'
        && $flagged[0]['machine_code'] === 'MAQ-008'
        && $flagged[0]['location_name'] === 'Centro Logístico Zona Franca'
);

$sanitaryFlagged = $coldGuard->findPendingCriticalStops(3, [
    $pendingStop(['machine_type' => 'COMBO', 'category' => 'ELECTRICAL_OFF', 'location_id' => 9]),
]);
$assert(
    '4.18 La advertencia de frío se marca como vigilancia sanitaria',
    count($sanitaryFlagged) === 1
        && $sanitaryFlagged[0]['warning_trigger'] === OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH
);

$assert(
    '4.19 Las etiquetas de disparador están en castellano',
    str_contains(
        $coldGuard->warningTriggerLabel(OpportunisticColdChainGuardPolicy::TRIGGER_CRITICAL_URGENCY),
        'Parada crítica'
    )
        && str_contains(
            $coldGuard->warningTriggerLabel(OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH),
            'cadena de frío'
        )
        && str_contains(
            $coldGuard->warningTriggerLabel(OpportunisticColdChainGuardPolicy::TRIGGER_SANITARY_QUARANTINE),
            'cuarentena'
        )
        && str_contains($coldGuard->warningTriggerLabel(null), 'Sin riesgo sanitario')
);

// =====================================================================
// GRUPO 5: Confirmación expresa obligatoria (RF-03.1 / RF-03.2)
// =====================================================================
echo "\n--- Grupo 5: Confirmación expresa de la postergación ---\n";

$pending = [
    $pendingStop(['id' => 401, 'urgency' => 'CRITICAL', 'ticket_code' => 'INC-2026-0401']),
];

$warning = null;
try {
    $coldGuard->assertNoUnconfirmedWarning(3, $pending, false);
} catch (ColdChainRouteWarningConfirmationException $e) {
    $warning = $e;
} catch (Throwable $e) {
    $warning = $e;
}

$assert(
    '5.1 Sin confirmación se lanza ColdChainRouteWarningConfirmationException',
    $warning instanceof ColdChainRouteWarningConfirmationException
);
$assert(
    '5.2 La excepción conserva código y HTTP 409',
    $warning instanceof ColdChainRouteWarningConfirmationException
        && $warning->getErrorCode() === 'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED'
        && $warning->getHttpStatusCode() === 409
);
$assert(
    '5.3 La excepción transporta la parada postergada',
    $warning instanceof ColdChainRouteWarningConfirmationException
        && count($warning->getPendingCriticalStops()) === 1
        && $warning->getPendingCriticalStops()[0]['ticket_code'] === 'INC-2026-0401'
);
$assert(
    '5.4 El mensaje invoca el Art. II y pide confirmación expresa',
    $warning instanceof ColdChainRouteWarningConfirmationException
        && str_contains($warning->getMessage(), 'Art. II')
);

$confirmed = $coldGuard->assertNoUnconfirmedWarning(3, $pending, true);
$assert(
    '5.5 Con confirmación expresa se devuelve la lista sin bloquear',
    count($confirmed) === 1 && $confirmed[0]['id'] === 401
);

$assert(
    '5.6 Sin paradas pendientes no se exige confirmación',
    $coldGuard->assertNoUnconfirmedWarning(3, [], false) === []
);

// =====================================================================
// GRUPO 6: Entradas inválidas
// =====================================================================
echo "\n--- Grupo 6: Identificadores inválidos ---\n";

$rejectedConflict = false;
try {
    $coexistence->findBlockingIntervention(0, []);
} catch (InvalidArgumentException $e) {
    $rejectedConflict = true;
}

$rejectedGuard = false;
try {
    $coldGuard->findPendingCriticalStops(-1, []);
} catch (InvalidArgumentException $e) {
    $rejectedGuard = true;
}

$assert('6.1 La sede destino inválida se rechaza en coexistencia', $rejectedConflict);
$assert('6.2 La sede destino inválida se rechaza en la salvaguarda de frío', $rejectedGuard);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-OPP-04 (COEXISTENCIA Y FRÍO) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
