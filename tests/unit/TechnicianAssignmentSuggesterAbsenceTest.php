<?php

declare(strict_types=1);

/**
 * TechnicianAssignmentSuggesterAbsenceTest · T-ASH-06
 *
 * Certifica la integración de disponibilidad, ausencias y ciclo de turno en el
 * recomendador territorial (`TechnicianAssignmentSuggester`, Módulo 21, RF-08):
 *
 *   1. Evaluación de disponibilidad (RF-08.1):
 *      - ON_SHIFT -> is_available = true.
 *      - OFF_SHIFT -> is_available = false (motivo: "Fuera de turno").
 *      - MEAL_BREAK -> is_available = false (motivo: "Pausa comida").
 *      - Ausencia activa -> is_available = false (motivo: etiqueta del motivo de ausencia).
 *      - Presencia física manda (R4): Si tiene ausencia activa pero shift_status = 'ON_SHIFT',
 *        is_available es true.
 *   2. Relegación de no disponibles (RF-08.2):
 *      - Los técnicos no disponibles van obligatoriamente al final del ranking.
 *      - Llevan la insignia NO_DISPONIBLE y su motivo explicativo (RF-08.3).
 *   3. Promoción del suplente (RF-08.4):
 *      - Si el titular (PRIMARY) de la zona está ausente o fuera de turno, el suplente (BACKUP)
 *        pasa al puesto #1 coronado con RECOMENDADO ⭐.
 *   4. Persistencia de guardia para frío (RF-08.5 / R6):
 *      - En emergencia de frío, el técnico de guardia (is_on_call = true) puede ser priorizado
 *        aunque se encuentre en OFF_SHIFT en su jornada comercial.
 *   5. Técnico sin turno abierto (RF-03.1 / RF-08.1):
 *      - Si la foto no trae fila de turno, el técnico está operativamente en OFF_SHIFT:
 *        is_available = false, motivo "Fuera de turno" e insignia NO_DISPONIBLE.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\TechnicianAssignmentSuggester;

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
echo " VendGuard · T-ASH-06 · Recomendador con Ausencias y Turnos (Unit)\n";
echo "======================================================================\n";

$suggester = new TechnicianAssignmentSuggester(new GeoDistanceService());

$incident = [
    'id' => 10,
    'ticket_code' => 'INC-2026-0010',
    'priority' => 'MEDIUM',
    'location' => [
        'id' => 1,
        'latitude' => 41.3874,
        'longitude' => 2.1701,
        'zone_id' => 1,
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// 1. Promoción del suplente ante titular no disponible (RF-08.4)
// ─────────────────────────────────────────────────────────────────────────────
$technicians1 = [
    [
        'technician_id' => 1,
        'name' => 'Titular en Vacaciones',
        'affinity' => 'PRIMARY',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'OFF_SHIFT',
        'active_absence' => [
            'reason' => 'VACATION',
            'reason_label' => 'Vacaciones',
        ],
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
    [
        'technician_id' => 2,
        'name' => 'Suplente En Turno',
        'affinity' => 'BACKUP',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT',
        'active_absence' => null,
        'active_incidents' => 1,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
];

$dto1 = $suggester->getSuggestionsForIncident($incident, $technicians1);
$ranked1 = $dto1->rankedTechnicians;

$assert('El suplente disponible pasa a puesto #1 ante titular ausente (RF-08.4)', $ranked1[0]['technician_id'] === 2);
$assert('El suplente lleva la insignia RECOMENDADO', in_array('RECOMENDADO', $ranked1[0]['badges'], true));
$assert('El titular ausente queda en el puesto #2 (relegado al final)', $ranked1[1]['technician_id'] === 1);
$assert('El titular ausente lleva la insignia NO_DISPONIBLE', in_array('NO_DISPONIBLE', $ranked1[1]['badges'], true));
$assert('El titular ausente reporta motivo Vacaciones', $ranked1[1]['current_status']['unavailability_reason'] === 'Vacaciones');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Relegación de técnicos en MEAL_BREAK y OFF_SHIFT
// ─────────────────────────────────────────────────────────────────────────────
$technicians2 = [
    [
        'technician_id' => 1,
        'name' => 'Tech en Pausa Comida',
        'affinity' => 'PRIMARY',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'MEAL_BREAK',
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
    [
        'technician_id' => 2,
        'name' => 'Tech Sin Zona En Turno',
        'affinity' => 'NONE',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT',
        'active_incidents' => 2,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
];

$dto2 = $suggester->getSuggestionsForIncident($incident, $technicians2);
$ranked2 = $dto2->rankedTechnicians;

$assert('El técnico en turno (incluso sin zona) va antes que el titular en comida', $ranked2[0]['technician_id'] === 2);
$assert('El técnico en comida lleva motivo Pausa comida', $ranked2[1]['current_status']['unavailability_reason'] === 'Pausa comida');

// ─────────────────────────────────────────────────────────────────────────────
// 3. Precedencia de presencia física sobre ausencia planificada (R4)
// ─────────────────────────────────────────────────────────────────────────────
$technicians3 = [
    [
        'technician_id' => 1,
        'name' => 'Titular que vino a trabajar en vacaciones',
        'affinity' => 'PRIMARY',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT', // Inició turno físicamente
        'active_absence' => [
            'reason' => 'VACATION',
            'reason_label' => 'Vacaciones',
        ],
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
    [
        'technician_id' => 2,
        'name' => 'Suplente En Turno',
        'affinity' => 'BACKUP',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT',
        'active_absence' => null,
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
];

$dto3 = $suggester->getSuggestionsForIncident($incident, $technicians3);
$ranked3 = $dto3->rankedTechnicians;

$assert('La presencia física (ON_SHIFT) prevalece sobre la ausencia: titular sigue en #1 (R4)', $ranked3[0]['technician_id'] === 1);
$assert('El titular presente no lleva NO_DISPONIBLE', !in_array('NO_DISPONIBLE', $ranked3[0]['badges'], true));

// ─────────────────────────────────────────────────────────────────────────────
// 4. Persistencia de la Guardia para averías de frío aunque esté en OFF_SHIFT (RF-08.5, R6)
// ─────────────────────────────────────────────────────────────────────────────
$coldIncident = array_merge($incident, [
    'priority' => 'CRITICAL',
    'is_cold_chain_risk' => true,
]);

$technicians4 = [
    [
        'technician_id' => 1,
        'name' => 'Titular Ocupado',
        'affinity' => 'PRIMARY',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT',
        'active_incidents' => 2, // Ocupado
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
    [
        'technician_id' => 7,
        'name' => 'Guardián en Casa',
        'affinity' => 'NONE',
        'is_active' => true,
        'is_on_call' => true,
        'shift_status' => 'OFF_SHIFT', // Fuera de turno ordinario
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
];

$dto4 = $suggester->getSuggestionsForIncident($coldIncident, $technicians4);
$ranked4 = $dto4->rankedTechnicians;

$assert('En emergencia de frío el guardián 24/7 en OFF_SHIFT encabeza el escalado (Art. II / R6)', $ranked4[0]['technician_id'] === 7);
$assert('El guardián encabeza con RECOMENDADO', in_array('RECOMENDADO', $ranked4[0]['badges'], true));

// ─────────────────────────────────────────────────────────────────────────────
// 5. Técnico sin turno abierto (RF-03.1 / RF-08.1)
// ─────────────────────────────────────────────────────────────────────────────
$technicians5 = [
    [
        'technician_id' => 3,
        'name' => 'Titular Sin Turno',
        'affinity' => 'PRIMARY',
        'is_active' => true,
        'is_on_call' => false,
        // Sin clave shift_status: no hay fila de turno abierto en la foto.
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
    [
        'technician_id' => 2,
        'name' => 'Suplente En Turno',
        'affinity' => 'BACKUP',
        'is_active' => true,
        'is_on_call' => false,
        'shift_status' => 'ON_SHIFT',
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
    ],
];

$dto5 = $suggester->getSuggestionsForIncident($incident, $technicians5);
$ranked5 = $dto5->rankedTechnicians;

$assert(
    'El técnico sin turno abierto se relega y lleva NO_DISPONIBLE aunque sea el titular',
    $ranked5[0]['technician_id'] === 2
        && $ranked5[1]['technician_id'] === 3
        && in_array('NO_DISPONIBLE', $ranked5[1]['badges'], true)
);
$assert(
    'El técnico sin turno abierto se reporta como OFF_SHIFT con motivo Fuera de turno',
    $ranked5[1]['current_status']['is_available'] === false
        && $ranked5[1]['current_status']['shift_status'] === 'OFF_SHIFT'
        && $ranked5[1]['current_status']['unavailability_reason'] === 'Fuera de turno'
);

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
