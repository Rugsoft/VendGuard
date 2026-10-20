<?php

declare(strict_types=1);

/**
 * TechnicianWorkloadScorerTest · T-ZONE-04
 *
 * Certifica la **matriz completa** de la política de idoneidad del Módulo 17
 * (RF-04.1, QA 1, QA 2, QA 3 y QA 12) sin base de datos ni reloj: la pureza del
 * scorer es precisamente lo que permite recorrer todos los caminos y afirmar que
 * el recomendador no depende de un estado oculto.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Afinidad territorial:** +50 titular, +25 suplente, 0 sin zona, con la
 *      conversión desde texto y el rechazo de un vocabulario desconocido.
 *   2. **Presencia in situ y exclusividad (QA 12):** +40 por una parada viva en la
 *      misma sede, y el bono de +20 por otras sedes de la zona **no se suma**; el
 *      +20 es plano (una o cuatro paradas puntúan lo mismo).
 *   3. **Incompatibilidad entre sedes:** -15 por una intervención en curso en otra
 *      sede, que convive con el resto del desglose.
 *   4. **Carga viva (QA 3):** -5 por avería activa, -5 por preventivo de hoy y -2
 *      por pausa por falta de acceso, con el caso combinado y los contadores en la
 *      forma numérica que puede devolver el motor.
 *   5. **Salvaguarda de frío (Art. II, QA 1 y QA 9):** el bono de +100 exige las
 *      tres condiciones a la vez —riesgo de frío, escalado activo y guardia 24/7—;
 *      si falta cualquiera, no hay bono.
 *   6. **Determinismo y pureza:** el total es la suma exacta del desglose, dos
 *      llamadas con el mismo contexto coinciden siempre y el contexto no se muta.
 *
 * Uso:
 *   php tests/unit/TechnicianWorkloadScorerTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Core\Service\TechnicianWorkloadScorer;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** Puntuación total esperada para un contexto, sin repetir la fórmula en cada caso. */
$score = static fn (array $context): int => TechnicianWorkloadScorer::computeScore($context);

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-04 · Matriz de idoneidad del técnico (Módulo 17)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Afinidad territorial
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Afinidad territorial (+50 / +25 / 0) ---\n";

$emptyBreakdown = TechnicianWorkloadScorer::computeBreakdown([]);
$assert(
    '1.1 Un contexto vacío puntúa 0 y el desglose nace en ceros',
    $score([]) === 0
        && $emptyBreakdown === array_fill_keys(TechnicianWorkloadScorer::BREAKDOWN_KEYS, 0),
    json_encode($emptyBreakdown)
);

$assert(
    '1.2 El titular de la zona suma +50',
    $score(['affinity' => ZoneAffinityType::PRIMARY]) === 50
        && TechnicianWorkloadScorer::computeBreakdown(['affinity' => ZoneAffinityType::PRIMARY])['territorial_affinity'] === 50
);

$assert(
    '1.3 El suplente de la zona suma +25',
    $score(['affinity' => ZoneAffinityType::BACKUP]) === 25
);

$assert(
    '1.4 Sin relación con la zona no suma nada',
    $score(['affinity' => ZoneAffinityType::NONE]) === 0
        && $score(['affinity' => null]) === 0
);

$assert(
    '1.5 La afinidad se acepta como texto, sin distinguir mayúsculas ni espacios',
    $score(['affinity' => ' backup ']) === 25
        && $score(['affinity' => 'PRIMARY']) === 50
);

$unknownAffinity = null;
try {
    $score(['affinity' => 'OWNER']);
} catch (InvalidArgumentException $exception) {
    $unknownAffinity = $exception->getMessage();
}
$assert(
    '1.6 Una afinidad desconocida se rechaza en voz alta',
    $unknownAffinity !== null && str_contains($unknownAffinity, 'PRIMARY, BACKUP, NONE'),
    $unknownAffinity ?? 'no se lanzó la excepción'
);

$assert(
    '1.7 territorialPoints coincide con los pesos públicos del scorer',
    TechnicianWorkloadScorer::territorialPoints(ZoneAffinityType::PRIMARY) === TechnicianWorkloadScorer::TERRITORIAL_PRIMARY_POINTS
        && TechnicianWorkloadScorer::territorialPoints(ZoneAffinityType::BACKUP) === TechnicianWorkloadScorer::TERRITORIAL_BACKUP_POINTS
        && TechnicianWorkloadScorer::territorialPoints(ZoneAffinityType::NONE) === 0
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Presencia in situ y proximidad de zona (excluyentes, QA 12)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Presencia in situ (+40) y misma zona (+20, excluyente) ---\n";

$onsite = ['has_live_stop_at_target_location' => true];
$onsiteBreakdown = TechnicianWorkloadScorer::computeBreakdown($onsite);
$assert(
    '2.1 Una parada viva en la misma sede suma +40',
    $score($onsite) === 40 && $onsiteBreakdown['onsite_presence'] === 40
);

$onsitePlusZone = [
    'has_live_stop_at_target_location' => true,
    'live_stops_in_zone_other_locations' => 3,
];
$combinedBreakdown = TechnicianWorkloadScorer::computeBreakdown($onsitePlusZone);
$assert(
    '2.2 Estar en la sede no suma además el bono de zona (QA 12)',
    $score($onsitePlusZone) === 40
        && $combinedBreakdown['onsite_presence'] === 40
        && $combinedBreakdown['same_zone_stops'] === 0,
    json_encode($combinedBreakdown)
);

$assert(
    '2.3 Una parada viva en otra sede de la misma zona suma +20',
    $score(['live_stops_in_zone_other_locations' => 1]) === 20
);

$assert(
    '2.4 El bono de zona es plano: cuatro paradas no suman cuatro veces',
    $score(['live_stops_in_zone_other_locations' => 4]) === 20
);

$assert(
    '2.5 Sin paradas en la zona no hay bono',
    $score(['live_stops_in_zone_other_locations' => 0]) === 0
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Intervención en curso en otra sede
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Intervención en curso en otra sede (-15) ---\n";

$assert(
    '3.1 Estar en curso en otra sede resta 15 puntos',
    $score(['has_in_progress_elsewhere' => true]) === -15
        && TechnicianWorkloadScorer::computeBreakdown(['has_in_progress_elsewhere' => true])['inter_location_penalty'] === -15
);

$assert(
    '3.2 Sin intervención en otra sede no hay penalización',
    $score(['has_in_progress_elsewhere' => false]) === 0
);

$assert(
    '3.3 El resto del desglose convive con la penalización entre sedes',
    $score([
        'affinity' => ZoneAffinityType::PRIMARY,
        'has_live_stop_at_target_location' => true,
        'has_in_progress_elsewhere' => true,
    ]) === 75,
    'esperado 50 + 40 - 15 = 75'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Carga operativa viva (-5 / -5 / -2)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Carga viva: averías, preventivos y pausas ---\n";

$assert(
    '4.1 Cada avería activa resta 5 puntos',
    $score(['active_incidents' => 2]) === -10
);

$assert(
    '4.2 Cada preventivo de hoy resta 5 puntos',
    $score(['preventive_orders_today' => 2]) === -10
);

$assert(
    '4.3 Cada pausa por falta de acceso resta 2 puntos (atenuada)',
    $score(['pending_info_incidents' => 3]) === -6
);

$workloadBreakdown = TechnicianWorkloadScorer::computeBreakdown([
    'active_incidents' => 2,
    'pending_info_incidents' => 1,
    'preventive_orders_today' => 3,
]);
$assert(
    '4.4 La carga combinada se compone sin redondeos: 2·5 + 1·2 + 3·5 = 27',
    $workloadBreakdown['active_workload_penalty'] === -27
        && $score([
            'active_incidents' => 2,
            'pending_info_incidents' => 1,
            'preventive_orders_today' => 3,
        ]) === -27,
    (string)$workloadBreakdown['active_workload_penalty']
);

$assert(
    '4.5 Los contadores admiten la forma numérica del motor',
    $score(['active_incidents' => '3', 'preventive_orders_today' => '1']) === -20
);

$invalidCounters = [
    'averías negativas' => ['active_incidents' => -1],
    'preventivo no numérico' => ['preventive_orders_today' => 'muchos'],
    'pausa decimal' => ['pending_info_incidents' => 1.5],
];
foreach ($invalidCounters as $case => $context) {
    $rejection = null;
    try {
        $score($context);
    } catch (InvalidArgumentException $exception) {
        $rejection = $exception->getMessage();
    }
    $assert(
        "4.6 Un contador imposible ({$case}) se rechaza en voz alta",
        $rejection !== null && str_contains($rejection, 'entero no negativo'),
        $rejection ?? 'no se lanzó la excepción'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Salvaguarda sanitaria de frío (Art. II)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Escalado de frío: el bono exige sus tres condiciones ---\n";

$coldEscalation = [
    'is_cold_chain_risk' => true,
    'cold_chain_escalation_active' => true,
    'is_on_call' => true,
];
$assert(
    '5.1 Frío + escalado + guardia 24/7 suma +100',
    $score($coldEscalation) === 100
        && TechnicianWorkloadScorer::computeBreakdown($coldEscalation)['cold_chain_on_call_bonus'] === 100
);

$assert(
    '5.2 El escalado sin técnico de guardia no reparte el bono',
    $score(['is_cold_chain_risk' => true, 'cold_chain_escalation_active' => true, 'is_on_call' => false]) === 0
);

$assert(
    '5.3 La guardia sin escalado (algún titular libre) no suma el bono',
    $score(['is_cold_chain_risk' => true, 'cold_chain_escalation_active' => false, 'is_on_call' => true]) === 0
);

$assert(
    '5.4 La guardia en una avería que no es de frío no suma el bono',
    $score(['is_cold_chain_risk' => false, 'cold_chain_escalation_active' => true, 'is_on_call' => true]) === 0
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Casos del contrato, determinismo y pureza
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Casos del contrato, determinismo y pureza ---\n";

$marta = [
    'affinity' => ZoneAffinityType::PRIMARY,
    'is_cold_chain_risk' => true,
    'cold_chain_escalation_active' => true,
    'is_on_call' => true,
    'active_incidents' => 2,
];
$expectedMartaBreakdown = [
    'territorial_affinity' => 50,
    'onsite_presence' => 0,
    'same_zone_stops' => 0,
    'active_workload_penalty' => -10,
    'inter_location_penalty' => 0,
    'cold_chain_on_call_bonus' => 100,
];
$assert(
    '6.1 El caso de guardia del contrato puntúa 140 con su desglose exacto',
    $score($marta) === 140 && TechnicianWorkloadScorer::computeBreakdown($marta) === $expectedMartaBreakdown,
    json_encode(TechnicianWorkloadScorer::computeBreakdown($marta))
);

$jordi = [
    'affinity' => ZoneAffinityType::PRIMARY,
    'live_stops_in_zone_other_locations' => 3,
    'has_in_progress_elsewhere' => true,
    'active_incidents' => 5,
    'preventive_orders_today' => 1,
];
$assert(
    '6.2 Titular saturado con parada en otra sede de su zona puntúa 25',
    $score($jordi) === 25,
    'esperado 50 + 20 - 30 - 15 = 25'
);

$assert(
    '6.3 El desglose declara siempre las seis claves del contrato',
    array_keys(TechnicianWorkloadScorer::computeBreakdown($jordi)) === TechnicianWorkloadScorer::BREAKDOWN_KEYS,
    implode(', ', array_keys(TechnicianWorkloadScorer::computeBreakdown($jordi)))
);

$sumOfBreakdown = array_sum(TechnicianWorkloadScorer::computeBreakdown($jordi));
$assert(
    '6.4 computeScore es exactamente la suma del desglose que se muestra al coordinador',
    $sumOfBreakdown === $score($jordi),
    'suma=' . $sumOfBreakdown . ' · total=' . $score($jordi)
);

$deterministic = true;
$reference = $score($jordi);
for ($iteration = 0; $iteration < 200; $iteration++) {
    if ($score($jordi) !== $reference) {
        $deterministic = false;
        break;
    }
}
$assert('6.5 Doscientas evaluaciones del mismo contexto dan el mismo número', $deterministic, (string)$reference);

$contextSnapshot = $marta;
$score($marta);
$assert(
    '6.6 La política es pura: no muta el contexto que recibe',
    $marta === $contextSnapshot
);

$assert(
    '6.7 La puntuación se devuelve como entero, lista para ordenar y comparar',
    is_int($score($marta))
);

$assert(
    '6.8 Los indicadores aceptan la forma del driver («t»/«f») y rechazan un texto arbitrario',
    TechnicianWorkloadScorer::computeBreakdown(['has_live_stop_at_target_location' => 't'])['onsite_presence'] === 40
        && TechnicianWorkloadScorer::computeBreakdown(['has_in_progress_elsewhere' => 'f'])['inter_location_penalty'] === 0
);

$invalidFlag = null;
try {
    $score(['has_live_stop_at_target_location' => 'quizá']);
} catch (InvalidArgumentException $exception) {
    $invalidFlag = $exception->getMessage();
}
$assert(
    '6.9 Un indicador no booleano no se convierte en verdadero por accidente',
    $invalidFlag !== null && str_contains($invalidFlag, 'has_live_stop_at_target_location'),
    $invalidFlag ?? 'no se lanzó la excepción'
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-04 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
