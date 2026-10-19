<?php

declare(strict_types=1);

/**
 * OpportunisticCatalogContractTest (Módulo 16 · laguna G3)
 *
 * Certifica **campo a campo** el contrato del catálogo oportunista que consume la
 * tarjeta del móvil (`GET /api/technician/locations/{id}/opportunistic-machines`,
 * §4.2 de `specs/technical/opportunistic_incident_contracts.md`):
 *
 *   1. Las cuatro raíces del contrato viajan siempre y en el mismo orden
 *      (`location`, `active_intervention_conflict`, `cold_chain_route_warning`,
 *      `machines`), con los valores por defecto declarados.
 *   2. La fila de máquina conserva intactas las claves que entrega el
 *      repositorio y la decoración añade exactamente cuatro claves derivadas
 *      (`opportunistic_action`, `opportunistic_action_label`, `is_available`,
 *      `requires_confirmation`), sin inventar ni borrar ninguna otra.
 *   3. Cada una de las seis acciones del catálogo cerrado publica su etiqueta y
 *      su disponibilidad exactas, incluida la única deshabilitada
 *      (`BLOCKED_OTHER_TECH_IN_PROGRESS`, Art. V.3).
 *   4. La clasificación tolera las formas reales del repositorio (estado en
 *      minúsculas, identificador de técnico como cadena, filas sin
 *      `assigned_technician_id`) sin degradar la acción.
 *
 * La suite es puramente de dominio: no abre conexión ni toca base de datos, de
 * modo que un cambio accidental en la proyección se detecta aquí antes que en la
 * suite de integración.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\OpportunisticMachineCatalogDto;
use VendGuard\Core\Domain\ValueObject\OpportunisticActionType;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · G3 · Contrato campo a campo del catálogo\n";
echo "======================================================================\n\n";

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/**
 * Fila de máquina tal y como la entrega `PdoIncidentRepository::findLocationMachineCatalog()`.
 *
 * @return array<string, mixed>
 */
$machineRow = static fn (array $overrides = []): array => array_merge([
    'id' => 42,
    'code' => 'VEND-0301',
    'model' => 'FAS Perla Fast Cold',
    'machine_type' => 'PERISHABLE_FOOD',
    'floor_wing' => 'Planta Baja - Urgencias',
    'is_active' => true,
    'is_blocked_no_access' => false,
    'sanitary_status' => 'OK',
    'active_incident' => null,
], $overrides);

/**
 * Expediente activo tal y como lo entrega el repositorio.
 *
 * @return array<string, mixed>
 */
$activeIncident = static fn (array $overrides = []): array => array_merge([
    'id' => 142,
    'ticket_code' => 'INC-2026-00142',
    'status' => 'ASSIGNED',
    'urgency' => 'HIGH',
    'category' => 'PRODUCT_JAM',
    'description' => 'Atasco en espiral B4.',
    'assigned_technician_id' => 7,
    'assigned_technician_name' => 'Jordi Ruta',
    'resolved_at' => null,
    'started_at' => null,
    'sla_target_at' => '2026-10-19T18:00:00+02:00',
    'is_in_warranty' => false,
    'reopen_count' => 0,
    'will_trigger_chronic_replacement' => false,
    'opportunistic_mode' => null,
    'opportunistic_origin_reason' => null,
], $overrides);

$technicianId = 7;

// ---------------------------------------------------------------------
// CASO 1 · Las cuatro raíces del contrato y sus valores por defecto
// ---------------------------------------------------------------------
echo "--- Caso 1: raíces del contrato ---\n";

$emptyCatalog = new OpportunisticMachineCatalogDto(
    location: [
        'id' => 3,
        'site_code' => 'SEDE-BCN-03',
        'name' => 'Centro Cívico',
        'address' => 'Calle Mayor 1',
        'enablement_type' => 'ACTIVE_STOP',
        'grace_expires_at' => null,
        'grace_remaining_seconds' => 0,
    ],
    machines: []
);

$payload = $emptyCatalog->toArray();
$assert(
    '1.1 Las raíces son exactamente las cuatro del contrato y en su orden',
    array_keys($payload) === ['location', 'active_intervention_conflict', 'cold_chain_route_warning', 'machines']
);
$assert('1.2 Sin conflicto de coexistencia la raíz publica null', $payload['active_intervention_conflict'] === null);
$assert(
    '1.3 La advertencia sanitaria vacía declara los dos campos del contrato',
    $payload['cold_chain_route_warning'] === ['requires_confirmation' => false, 'pending_critical_stops' => []]
);
$assert('1.4 Sin conflicto el catálogo no bloquea la visita', $emptyCatalog->hasActiveInterventionConflict() === false);
$assert('1.5 Sin paradas críticas no exige confirmación del Art. II', $emptyCatalog->requiresColdChainConfirmation() === false);
$assert('1.6 La sede publica su tipo de habilitación', ($payload['location']['enablement_type'] ?? null) === 'ACTIVE_STOP');

$conflictCatalog = new OpportunisticMachineCatalogDto(
    location: $payload['location'],
    machines: [],
    activeInterventionConflict: [
        'ticket_code' => 'INC-2026-0099',
        'status' => 'IN_PROGRESS',
        'location_name' => 'Hospital del Mar',
        'machine_code' => 'VEND-0101',
    ],
    coldChainRouteWarning: [
        'requires_confirmation' => true,
        'pending_critical_stops' => [
            ['ticket_code' => 'INC-2026-0100', 'location_name' => 'Hospital del Mar'],
        ],
    ]
);
$assert(
    '1.7 El conflicto de coexistencia y la advertencia sanitaria viajan íntegros',
    $conflictCatalog->hasActiveInterventionConflict() === true
        && $conflictCatalog->requiresColdChainConfirmation() === true
        && ($conflictCatalog->toArray()['active_intervention_conflict']['ticket_code'] ?? null) === 'INC-2026-0099'
        && count($conflictCatalog->toArray()['cold_chain_route_warning']['pending_critical_stops']) === 1
);

// ---------------------------------------------------------------------
// CASO 2 · La fila de máquina: claves del repositorio + cuatro derivadas
// ---------------------------------------------------------------------
echo "\n--- Caso 2: composición de la fila de máquina ---\n";

$rawMachine = $machineRow(['active_incident' => $activeIncident(['assigned_technician_id' => 9])]);
$decorated = OpportunisticMachineCatalogDto::decorateMachine($rawMachine, $technicianId);

$expectedKeys = [
    'id', 'code', 'model', 'machine_type', 'floor_wing',
    'is_active', 'is_blocked_no_access', 'sanitary_status', 'active_incident',
    'opportunistic_action', 'opportunistic_action_label', 'is_available', 'requires_confirmation',
];
$actualKeys = array_keys($decorated);
sort($actualKeys);
sort($expectedKeys);

$assert(
    '2.1 La máquina publica exactamente las claves del repositorio más las cuatro derivadas',
    $actualKeys === $expectedKeys,
    'Claves reales: ' . implode(', ', array_keys($decorated))
);
$assert(
    '2.2 La decoración no altera ningún campo entregado por el repositorio',
    $decorated['id'] === 42
        && $decorated['code'] === 'VEND-0301'
        && $decorated['machine_type'] === 'PERISHABLE_FOOD'
        && $decorated['floor_wing'] === 'Planta Baja - Urgencias'
        && $decorated['sanitary_status'] === 'OK'
        && $decorated['active_incident'] === $rawMachine['active_incident']
);
$assert(
    '2.3 Una parada de otro compañero se ofrece con confirmación expresa',
    $decorated['opportunistic_action'] === 'ASSUME_WITH_CONFIRMATION'
        && $decorated['opportunistic_action_label'] === 'Asumir con confirmación'
        && $decorated['is_available'] === true
        && $decorated['requires_confirmation'] === true
);
$assert(
    '2.4 La fila sin expediente propone crear y no exige confirmación',
    (static function () use ($machineRow, $technicianId): bool {
        $clean = OpportunisticMachineCatalogDto::decorateMachine($machineRow(), $technicianId);

        return $clean['opportunistic_action'] === 'CREATE_NEW'
            && $clean['opportunistic_action_label'] === 'Oportunista In Situ · Nueva'
            && $clean['is_available'] === true
            && $clean['requires_confirmation'] === false;
    })()
);

// ---------------------------------------------------------------------
// CASO 3 · Matriz completa acción → etiqueta / disponibilidad
// ---------------------------------------------------------------------
echo "\n--- Caso 3: matriz de las seis acciones ---\n";

$expectedMatrix = [
    'CREATE_NEW' => ['Oportunista In Situ · Nueva', true, false],
    'ASSUME_DIRECT' => ['Asumir ahora', true, false],
    'ASSUME_WITH_CONFIRMATION' => ['Asumir con confirmación', true, true],
    'REOPEN_IN_SITU' => ['Reabrir in situ', true, false],
    'CURRENT_TECHNICIAN_IN_PROGRESS' => ['En curso contigo', true, false],
    'BLOCKED_OTHER_TECH_IN_PROGRESS' => ['En curso por otro técnico', false, false],
];

foreach ($expectedMatrix as $actionValue => [$label, $isAvailable, $requiresConfirmation]) {
    $action = OpportunisticActionType::fromString($actionValue);
    $assert(
        "3.x {$actionValue} publica «{$label}», disponible=" . ($isAvailable ? 'sí' : 'no'),
        $action->value === $actionValue
            && $action->label() === $label
            && $action->isAvailable() === $isAvailable
            && $action->requiresConfirmation() === $requiresConfirmation
    );
}

// ---------------------------------------------------------------------
// CASO 4 · Clasificación por estado y titularidad
// ---------------------------------------------------------------------
echo "\n--- Caso 4: clasificación estado ↔ titular ---\n";

$classify = static fn (?array $incident): string =>
    OpportunisticMachineCatalogDto::classifyAction($incident, 7)->value;

$assert('4.1 Sin expediente → CREATE_NEW', $classify(null) === 'CREATE_NEW');
$assert(
    '4.2 REGISTERED y REOPENED sin técnico → ASSUME_DIRECT',
    $classify($activeIncident(['status' => 'REGISTERED', 'assigned_technician_id' => null])) === 'ASSUME_DIRECT'
        && $classify($activeIncident(['status' => 'REOPENED', 'assigned_technician_id' => null])) === 'ASSUME_DIRECT'
);
$assert(
    '4.3 Una parada propia pausada o asignada se reanuda sin confirmación',
    $classify($activeIncident(['status' => 'ASSIGNED', 'assigned_technician_id' => 7])) === 'ASSUME_DIRECT'
        && $classify($activeIncident(['status' => 'PENDING_PARTS', 'assigned_technician_id' => 7])) === 'ASSUME_DIRECT'
        && $classify($activeIncident(['status' => 'PENDING_INFO', 'assigned_technician_id' => 7])) === 'ASSUME_DIRECT'
);
$assert(
    '4.4 La parada de un compañero exige confirmación en los tres estados con titular',
    $classify($activeIncident(['status' => 'ASSIGNED', 'assigned_technician_id' => 9])) === 'ASSUME_WITH_CONFIRMATION'
        && $classify($activeIncident(['status' => 'PENDING_PARTS', 'assigned_technician_id' => 9])) === 'ASSUME_WITH_CONFIRMATION'
        && $classify($activeIncident(['status' => 'PENDING_INFO', 'assigned_technician_id' => 9])) === 'ASSUME_WITH_CONFIRMATION'
);
$assert(
    '4.5 IN_PROGRESS propio se reconoce y el ajeno queda bloqueado (Art. V.3)',
    $classify($activeIncident(['status' => 'IN_PROGRESS', 'assigned_technician_id' => 7])) === 'CURRENT_TECHNICIAN_IN_PROGRESS'
        && $classify($activeIncident(['status' => 'IN_PROGRESS', 'assigned_technician_id' => 9])) === 'BLOCKED_OTHER_TECH_IN_PROGRESS'
);
$assert(
    '4.6 RESOLVED en garantía propone la reapertura in situ',
    $classify($activeIncident(['status' => 'RESOLVED', 'resolved_at' => '2026-10-19T09:00:00+02:00', 'is_in_warranty' => true])) === 'REOPEN_IN_SITU'
);
$assert(
    '4.7 El estado se normaliza (minúsculas y espacios) sin cambiar la acción',
    $classify($activeIncident(['status' => '  assigned ', 'assigned_technician_id' => 7])) === 'ASSUME_DIRECT'
);
$assert(
    '4.8 El identificador de técnico como cadena numérica mantiene la titularidad',
    $classify($activeIncident(['status' => 'IN_PROGRESS', 'assigned_technician_id' => '7'])) === 'CURRENT_TECHNICIAN_IN_PROGRESS'
);
$assert(
    '4.9 Una fila sin `assigned_technician_id` se lee como sin titular',
    $classify(['status' => 'REGISTERED']) === 'ASSUME_DIRECT'
);

// ---------------------------------------------------------------------
// CASO 5 · Búsqueda por identificador
// ---------------------------------------------------------------------
echo "\n--- Caso 5: acceso por identificador ---\n";

$catalog = new OpportunisticMachineCatalogDto(
    location: ['id' => 3, 'site_code' => 'SEDE-BCN-03'],
    machines: [
        OpportunisticMachineCatalogDto::decorateMachine($machineRow(['id' => 42, 'code' => 'VEND-0301']), $technicianId),
        OpportunisticMachineCatalogDto::decorateMachine($machineRow(['id' => 43, 'code' => 'VEND-0302']), $technicianId),
    ]
);
$assert(
    '5.1 machineById devuelve la fila decorada exacta',
    ($catalog->machineById(43)['code'] ?? null) === 'VEND-0302'
        && ($catalog->machineById(43)['opportunistic_action'] ?? null) === 'CREATE_NEW'
);
$assert('5.2 Un identificador ausente devuelve null', $catalog->machineById(999) === null);
$assert('5.3 El catálogo conserva el orden de las filas recibidas', array_column($catalog->machines, 'id') === [42, 43]);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G3 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
