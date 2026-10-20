<?php

declare(strict_types=1);

/**
 * TechnicianAssignmentSuggesterTest · T-ZONE-08
 *
 * Certifica el **caso de uso** de la sugerencia asistida (RF-04, RF-05, RF-06,
 * QA 1, QA 2, QA 3, QA 5, QA 9 y QA 12) sin base de datos ni reloj: la foto del
 * estado operativo entra por parámetro y el ranking sale por el DTO, de modo que
 * todos los caminos lógicos se recorren y el determinismo se puede afirmar.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Caso ordinario (RF-04.2).** Puntuación descendente con el desglose del
 *      scorer y la insignia del titular; el ranking queda encabezado por el mismo
 *      técnico que declara la recomendación.
 *   2. **Desempates (QA 5).** Mayor tiempo desde la última asignación primero, un
 *      técnico sin asignaciones tratado como `epoch` y, en último término,
 *      `technician_id` ascendente.
 *   3. **Escalado sanitario (Art. II, QA 1 y QA 9).** Con todos los titulares
 *      ocupados, la guardia 24/7 encabeza el ranking **aunque puntúe menos**, y el
 *      `+100` explica el motivo. Con un titular libre no hay escalado ni bono.
 *   4. **Escalado sin guardia (QA 9).** Manda la menor carga viva y, a igual carga,
 *      la mayor proximidad, por delante de la afinidad territorial.
 *   5. **Zona desatendida (RF-06.1).** Sin cuadrante propio el orden es proximidad
 *      Haversine y después carga; la asignación nunca se bloquea.
 *   6. **Proximidad sin GPS (QA 5).** La referencia es la parada en curso, después
 *      la última completada y después la Base Central; una coordenada inválida
 *      computa 999 km.
 *   7. **Cupo de paradas vivas (RF-06.1, QA 14).** Con todo el cuadrante en diez
 *      paradas vivas la zona queda desatendida aunque haya técnicos; un suplente
 *      libre la mantiene atendida y el profesional en el cupo sigue siendo
 *      asignable (RF-06.3).
 *   7. **Exclusiones y validación.** Los técnicos inactivos no entran, y una foto
 *      imposible (afinidad desconocida, contador negativo, fecha ilegible) falla en
 *      voz alta en lugar de producir un ranking plausible.
 *   8. **Determinismo y forma del contrato.** Dos llamadas con la misma foto
 *      devuelven lo mismo, la foto no se muta y el DTO serializa con las claves de
 *      `GET /api/coordinator/incidents/{id}/suggested-technicians`.
 *
 * Uso:
 *   php tests/unit/TechnicianAssignmentSuggesterTest.php
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
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-08 · Sugerencia asistida de técnicos (Módulo 17)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// Foto base: sede de Barcelona y Base Central
// ─────────────────────────────────────────────────────────────────────────────

$targetLocation = [
    'id' => 4,
    'site_code' => 'SEDE-BCN-01',
    'name' => 'Hospital del Mar',
    'postal_code' => '08005',
    'zone_id' => 1,
    'zone_name' => 'Barcelona Litoral y Norte',
    'latitude' => 41.3874,
    'longitude' => 2.1701,
];

$base = ['base_latitude' => 41.3800, 'base_longitude' => 2.1600];

/** Sede cercana (~1 km) y sede lejana (~15 km) para las pruebas de proximidad. */
$nearPoint = ['location_id' => 10, 'location_name' => 'Sede cercana', 'latitude' => 41.3900, 'longitude' => 2.1750];
$farPoint = ['location_id' => 11, 'location_name' => 'Sede lejana', 'latitude' => 41.5000, 'longitude' => 2.3000];

/** Candidato con valores por defecto; cada caso sobrescribe lo que le interesa. */
$technician = static fn (int $id, array $overrides = []): array => array_merge([
    'technician_id' => $id,
    'name' => 'Técnico ' . $id,
    'operator_code' => 'OP-' . sprintf('%03d', $id),
    'is_on_call' => false,
    'is_active' => true,
    'affinity' => 'NONE',
    'active_incidents' => 0,
    'pending_info_incidents' => 0,
    'preventive_orders_today' => 0,
    'has_live_stop_at_target_location' => false,
    'live_stops_in_zone_other_locations' => 0,
    'last_assigned_at' => null,
    'in_progress_location' => null,
    'last_completed_location' => null,
], $overrides);

$incident = static fn (array $overrides = []): array => array_merge([
    'id' => 1042,
    'ticket_code' => 'INC-20261020-0012',
    'priority' => 'HIGH',
    'location' => $targetLocation,
], $overrides);

$suggester = new TechnicianAssignmentSuggester(new GeoDistanceService);

/** Identificadores del ranking en orden, para afirmar el orden sin ruido. */
$order = static fn (array $ranked): array => array_column($ranked, 'technician_id');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Caso ordinario y desglose
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Caso ordinario: puntuación y recomendación ---\n";

$ordinary = $suggester->getSuggestionsForIncident($incident(), [
    $technician(3, ['affinity' => 'NONE']),
    $technician(2, ['affinity' => 'BACKUP']),
    $technician(1, ['affinity' => 'PRIMARY', 'last_assigned_at' => '2026-10-18T09:00:00+02:00']),
], $base);

$assert(
    '1.1 El orden ordinario es titular (+50), suplente (+25) y sin zona (0)',
    $order($ordinary->rankedTechnicians) === [1, 2, 3],
    json_encode($order($ordinary->rankedTechnicians))
);
$assert(
    '1.2 El titular encabeza la recomendación con su desglose y su insignia',
    $ordinary->recommendation['top_technician_id'] === 1
        && $ordinary->rankedTechnicians[0]['total_score'] === 50
        && $ordinary->rankedTechnicians[0]['score_breakdown']['territorial_affinity'] === 50
        && $ordinary->rankedTechnicians[0]['is_top_suggestion'] === true
        && in_array('RECOMENDADO', $ordinary->rankedTechnicians[0]['badges'], true)
        && in_array('TITULAR_ZONA', $ordinary->rankedTechnicians[0]['badges'], true)
        && $ordinary->rankedTechnicians[0]['zone_affinity_label'] === 'Titular de zona'
);
$assert(
    '1.3 El suplente lleva SUPLENTE_ZONA y no es la recomendación',
    in_array('SUPLENTE_ZONA', $ordinary->rankedTechnicians[1]['badges'], true)
        && $ordinary->rankedTechnicians[1]['is_top_suggestion'] === false
);
$assert(
    '1.4 Sin escalado sanitario ni zona desatendida, ambas banderas van a false',
    $ordinary->recommendation['is_cold_chain_escalation'] === false
        && $ordinary->recommendation['unattended_zone'] === false
        && str_contains((string)$ordinary->recommendation['explanation'], 'Sugerido por Zona')
);
$assert(
    '1.5 La distancia se mide respecto a la Base Central y respeta el Haversine',
    $ordinary->rankedTechnicians[0]['current_status']['distance_km'] > 0.0
        && $ordinary->rankedTechnicians[0]['current_status']['distance_km'] < 5.0,
    (string)$ordinary->rankedTechnicians[0]['current_status']['distance_km']
);

$reference = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['in_progress_location' => $nearPoint, 'last_completed_location' => $farPoint]),
], $base);
$assert(
    '1.6 La referencia de distancia prefiere la parada en curso sobre la última completada',
    $reference->rankedTechnicians[0]['current_status']['distance_km'] < 5.0
        && $reference->rankedTechnicians[0]['current_status']['has_in_progress_stop'] === true
        && $reference->rankedTechnicians[0]['current_status']['in_progress_location_name'] === 'Sede cercana',
    (string)$reference->rankedTechnicians[0]['current_status']['distance_km']
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Desempates deterministas (RF-04.2 / QA 5)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Desempates deterministas ---\n";

$tie = $suggester->getSuggestionsForIncident($incident(), [
    $technician(7, ['last_assigned_at' => '2026-10-20T09:00:00+02:00']),
    $technician(5, ['last_assigned_at' => null]),
    $technician(9, ['last_assigned_at' => '2026-10-19T09:00:00+02:00']),
], $base);
$assert(
    '2.1 A igual puntuación gana quien lleva más tiempo sin asignación (epoch primero)',
    $order($tie->rankedTechnicians) === [5, 9, 7],
    json_encode($order($tie->rankedTechnicians))
);

$idTie = $suggester->getSuggestionsForIncident($incident(), [
    $technician(12),
    $technician(8),
], $base);
$assert(
    '2.2 El empate total se cierra por technician_id ascendente',
    $order($idTie->rankedTechnicians) === [8, 12]
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Salvaguarda sanitaria de frío (Art. II / QA 1 / QA 9)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Salvaguarda sanitaria de frío ---\n";

$coldIncident = $incident(['priority' => 'CRITICAL']);

$escalated = $suggester->getSuggestionsForIncident($coldIncident, [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 1, 'has_live_stop_at_target_location' => true]),
    $technician(7, ['affinity' => 'NONE', 'is_on_call' => true, 'active_incidents' => 5]),
    $technician(2, ['affinity' => 'BACKUP', 'active_incidents' => 1]),
], $base);

$assert(
    '3.1 Con el titular ocupado se antepone a la guardia 24/7 aunque puntúe menos',
    $order($escalated->rankedTechnicians)[0] === 7
        && $escalated->recommendation['is_cold_chain_escalation'] === true
        && $escalated->rankedTechnicians[0]['is_on_call'] === true
        && in_array('GUARDIA_24_7', $escalated->rankedTechnicians[0]['badges'], true)
        && in_array('RECOMENDADO', $escalated->rankedTechnicians[0]['badges'], true),
    'orden: ' . json_encode($order($escalated->rankedTechnicians))
);
$assert(
    '3.2 El bono de +100 explica la recomendación y la frase cita el Art. II',
    $escalated->rankedTechnicians[0]['score_breakdown']['cold_chain_on_call_bonus'] === 100
        && str_contains((string)$escalated->recommendation['explanation'], 'Art. II')
        && str_contains((string)$escalated->recommendation['explanation'], 'guardia 24/7')
);
$assert(
    '3.3 La guardia puntúa por debajo del titular y aun así encabeza (precedencia, no premio)',
    $escalated->rankedTechnicians[0]['total_score']
        < $escalated->rankedTechnicians[1]['total_score'],
    sprintf(
        'guardia=%d · titular=%d',
        $escalated->rankedTechnicians[0]['total_score'],
        $escalated->rankedTechnicians[1]['total_score']
    )
);

$covered = $suggester->getSuggestionsForIncident($coldIncident, [
    $technician(1, ['affinity' => 'PRIMARY']),
    $technician(7, ['affinity' => 'NONE', 'is_on_call' => true]),
], $base);
$assert(
    '3.4 Con un titular libre no hay escalado ni bono de guardia',
    $covered->recommendation['is_cold_chain_escalation'] === false
        && $order($covered->rankedTechnicians)[0] === 1
        && $covered->rankedTechnicians[1]['score_breakdown']['cold_chain_on_call_bonus'] === 0
);

$noCall = $suggester->getSuggestionsForIncident($coldIncident, [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 3, 'in_progress_location' => $nearPoint]),
    $technician(4, ['active_incidents' => 1, 'in_progress_location' => $farPoint]),
    $technician(5, ['active_incidents' => 0, 'in_progress_location' => $farPoint]),
    $technician(6, ['active_incidents' => 0, 'in_progress_location' => $nearPoint]),
], $base);
$assert(
    '3.5 Sin guardia manda la menor carga viva y, a igual carga, la mayor proximidad',
    $order($noCall->rankedTechnicians) === [6, 5, 4, 1]
        && $noCall->recommendation['is_cold_chain_escalation'] === true
        && str_contains((string)$noCall->recommendation['explanation'], 'sin técnico de guardia'),
    json_encode($order($noCall->rankedTechnicians))
);

$perishable = $incident(['priority' => 'HIGH', 'machine_type' => 'PERISHABLE_FOOD']);
$derivedRisk = $suggester->getSuggestionsForIncident($perishable, [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 1]),
    $technician(7, ['is_on_call' => true]),
], $base);
$assert(
    '3.6 El riesgo de frío se deriva del tipo de máquina cuando no viene declarado',
    $derivedRisk->incident['is_cold_chain_risk'] === true
        && $derivedRisk->recommendation['is_cold_chain_escalation'] === true
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Zona desatendida (RF-06.1 / QA 5)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Zona desatendida y coordenadas inválidas ---\n";

$unattended = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['active_incidents' => 2, 'in_progress_location' => $farPoint]),
    $technician(2, ['in_progress_location' => $nearPoint]),
    $technician(3, ['in_progress_location' => $farPoint]),
], $base);
$assert(
    '4.1 Sin cuadrante propio el ranking va por proximidad y después por carga',
    $order($unattended->rankedTechnicians) === [2, 3, 1]
        && $unattended->recommendation['unattended_zone'] === true
        && str_contains((string)$unattended->recommendation['explanation'], 'Sin técnicos disponibles'),
    json_encode($order($unattended->rankedTechnicians))
);

$brokenCoordinates = $suggester->getSuggestionsForIncident(
    $incident(['location' => array_merge($targetLocation, ['latitude' => null, 'longitude' => null])]),
    [
        $technician(1, ['in_progress_location' => $nearPoint]),
        $technician(2, ['in_progress_location' => $nearPoint]),
    ],
    $base
);
$assert(
    '4.2 Una coordenada inválida computa 999 km sin romper el ranking',
    $brokenCoordinates->rankedTechnicians[0]['current_status']['distance_km'] === 999.0
        && $brokenCoordinates->rankedTechnicians[1]['current_status']['distance_km'] === 999.0
        && $order($brokenCoordinates->rankedTechnicians) === [1, 2]
);

$invalidReference = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['in_progress_location' => ['location_id' => 1, 'location_name' => 'Cero', 'latitude' => 0, 'longitude' => 0]]),
], $base);
$assert(
    '4.3 Una referencia fuera del área operativa también computa 999 km',
    $invalidReference->rankedTechnicians[0]['current_status']['distance_km'] === 999.0
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Exclusiones y validación temprana
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Exclusiones y validación temprana ---\n";

$withInactive = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['affinity' => 'PRIMARY']),
    $technician(2, ['affinity' => 'PRIMARY', 'is_active' => false]),
], $base);
$assert(
    '5.1 Un técnico inactivo no entra en el ranking aunque sea titular',
    $order($withInactive->rankedTechnicians) === [1]
);

$emptyPark = $suggester->getSuggestionsForIncident($incident(), [], $base);
$assert(
    '5.2 Sin técnicos activos hay ranking vacío, recomendación nula y aviso explícito',
    $emptyPark->rankedTechnicians === []
        && $emptyPark->recommendation['top_technician_id'] === null
        && str_contains((string)$emptyPark->recommendation['explanation'], 'No hay técnicos activos')
);

$unknownAffinity = null;
try {
    $suggester->getSuggestionsForIncident($incident(), [$technician(1, ['affinity' => 'TITULAR'])] , $base);
} catch (InvalidArgumentException $exception) {
    $unknownAffinity = $exception->getMessage();
}
$assert(
    '5.3 Una afinidad fuera del vocabulario no produce un ranking plausible',
    $unknownAffinity !== null && str_contains(strtolower($unknownAffinity), 'afinidad'),
    $unknownAffinity ?? 'no se lanzó la excepción'
);

$negativeLoad = null;
try {
    $suggester->getSuggestionsForIncident($incident(), [$technician(1, ['active_incidents' => -1])], $base);
} catch (InvalidArgumentException $exception) {
    $negativeLoad = $exception->getMessage();
}
$assert(
    '5.4 Una carga negativa se rechaza en voz alta',
    $negativeLoad !== null && str_contains($negativeLoad, 'active_incidents'),
    $negativeLoad ?? 'no se lanzó la excepción'
);

$badTimestamp = null;
try {
    $suggester->getSuggestionsForIncident($incident(), [$technician(1, ['last_assigned_at' => 'ayer por la tarde'])], $base);
} catch (InvalidArgumentException $exception) {
    $badTimestamp = $exception->getMessage();
}
$assert(
    '5.5 Una fecha de última asignación ilegible se rechaza',
    $badTimestamp !== null && str_contains($badTimestamp, 'ayer por la tarde'),
    $badTimestamp ?? 'no se lanzó la excepción'
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Determinismo y forma del contrato
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Determinismo y forma del contrato ---\n";

$photo = [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 1]),
    $technician(2, ['affinity' => 'BACKUP']),
];
$photoBefore = $photo;

$firstRun = $suggester->getSuggestionsForIncident($incident(), $photo, $base);
$secondRun = $suggester->getSuggestionsForIncident($incident(), $photo, $base);

$assert(
    '6.1 Dos ejecuciones con la misma foto devuelven exactamente el mismo ranking',
    $firstRun->toArray() === $secondRun->toArray()
);
$assert(
    '6.2 La foto de entrada no se muta',
    $photo === $photoBefore
);
$assert(
    '6.3 El DTO expone los tres bloques del contrato y serialize igual que toArray',
    array_keys($firstRun->toArray()) === ['incident', 'recommendation', 'ranked_technicians']
        && array_keys($firstRun->rankedTechnicians[0]) === [
            'technician_id', 'name', 'operator_code', 'is_on_call', 'affinity_type',
            'zone_affinity_label', 'total_score', 'score_breakdown', 'active_workload',
            'current_status', 'is_top_suggestion', 'badges',
        ]
        && json_decode((string)json_encode($firstRun), true) === $firstRun->toArray()
);
$assert(
    '6.4 El bloque de la avería lleva la sede con su zona y su prioridad',
    $firstRun->incident['id'] === 1042
        && $firstRun->incident['ticket_code'] === 'INC-20261020-0012'
        && $firstRun->incident['location']['zone_id'] === 1
        && $firstRun->incident['location']['postal_code'] === '08005'
        && $firstRun->incident['priority'] === 'HIGH'
);
$assert(
    '6.5 La carga viva del DTO separa averías, pausas y preventivos',
    $firstRun->rankedTechnicians[0]['active_workload'] === [
        'active_tickets_count' => 1,
        'pending_info_count' => 0,
        'preventive_orders_count' => 0,
    ]
);
$assert(
    '6.6 El total es la suma exacta del desglose en cada fila del ranking',
    array_reduce(
        $firstRun->rankedTechnicians,
        static fn (bool $carry, array $row): bool => $carry && array_sum($row['score_breakdown']) === $row['total_score'],
        true
    ),
    json_encode(array_map(
        static fn (array $row): array => [$row['total_score'], array_sum($row['score_breakdown'])],
        $firstRun->rankedTechnicians
    ))
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Cupo de paradas vivas de la zona desatendida (RF-06.1, QA 14)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Cupo de paradas vivas (QA 14) ---\n";

$assert(
    '7.0 El cupo es una constante de dominio de diez paradas vivas',
    TechnicianAssignmentSuggester::MAX_LIVE_STOPS_PER_TECHNICIAN === 10
);

$atCap = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 10, 'in_progress_location' => $farPoint]),
    $technician(2, ['in_progress_location' => $nearPoint]),
], $base);
$assert(
    '7.1 Un titular en el cupo deja la zona desatendida y ordena por proximidad',
    $atCap->recommendation['unattended_zone'] === true
        && $order($atCap->rankedTechnicians) === [2, 1]
        && str_contains((string)$atCap->recommendation['explanation'], 'cupo de 10 paradas vivas'),
    json_encode($order($atCap->rankedTechnicians))
);

$belowCap = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 9, 'in_progress_location' => $farPoint]),
    $technician(2, ['in_progress_location' => $nearPoint]),
], $base);
$assert(
    '7.2 Con nueve paradas vivas la zona sigue atendida y recomienda al titular',
    $belowCap->recommendation['unattended_zone'] === false
        && $order($belowCap->rankedTechnicians) === [1, 2]
        && str_contains((string)$belowCap->recommendation['explanation'], 'Sugerido por Zona'),
    json_encode([$belowCap->recommendation['unattended_zone'], $order($belowCap->rankedTechnicians)])
);

$mixedCap = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, [
        'affinity' => 'PRIMARY',
        'active_incidents' => 8,
        'pending_info_incidents' => 1,
        'preventive_orders_today' => 1,
    ]),
    $technician(2, ['in_progress_location' => $nearPoint]),
], $base);
$assert(
    '7.3 Pausas y preventivos computan para el cupo: 8 + 1 + 1 lo agotan',
    $mixedCap->recommendation['unattended_zone'] === true,
    json_encode($mixedCap->recommendation)
);

$backupFree = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['affinity' => 'PRIMARY', 'active_incidents' => 10]),
    $technician(2, ['affinity' => 'BACKUP']),
], $base);
$assert(
    '7.4 Un suplente libre mantiene la zona atendida aunque el titular esté en el cupo',
    $backupFree->recommendation['unattended_zone'] === false
        && $order($backupFree->rankedTechnicians) === [2, 1]
        && !str_contains((string)$backupFree->recommendation['explanation'], 'Sin técnicos disponibles'),
    json_encode($order($backupFree->rankedTechnicians))
);

$assert(
    '7.5 El técnico en el cupo sigue en el ranking y sigue siendo asignable (RF-06.3)',
    count($atCap->rankedTechnicians) === 2
        && in_array(1, $order($atCap->rankedTechnicians), true)
        && $atCap->rankedTechnicians[0]['technician_id'] === 2
);

$emptyZone = $suggester->getSuggestionsForIncident($incident(), [
    $technician(1, ['in_progress_location' => $nearPoint]),
], $base);
$assert(
    '7.6 Una zona sin cuadrante avisa sin atribuirlo al cupo',
    $emptyZone->recommendation['unattended_zone'] === true
        && str_contains((string)$emptyZone->recommendation['explanation'], 'Sin técnicos disponibles')
        && !str_contains((string)$emptyZone->recommendation['explanation'], 'cupo de')
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-08 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
