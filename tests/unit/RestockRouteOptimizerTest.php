<?php

declare(strict_types=1);

/**
 * RestockRouteOptimizerTest · T-RRP-05
 *
 * Certifica el motor puro que convierte las máquinas pendientes del día en una
 * hoja de ruta ordenada por sede, sin base de datos ni servicios.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Agrupación por sede y estados mixtos (QA-6).** Varias máquinas de la
 *      misma sede forman una sola parada; las completadas, omitidas o bloqueadas
 *      no viajan en la parada activa y una sede sin máquinas pendientes
 *      desaparece de la hoja.
 *   2. **Tres bloques estrictos (EARS 03.2, D6, Art. II).** `FEFO_SANITARY`
 *      siempre precede a `DEMAND_L1_STOCKOUT`, y este a `SCHEDULED_CADENCE`.
 *      Dentro del bloque 1 manda la fecha de caducidad; en el bloque 2 el
 *      `SlaTier` y luego `L1_INCIDENT` antes que `EMPTY_SLOT`; en el bloque 3 la
 *      franja horaria más temprana.
 *   3. **Haversine y desempates.** Dentro de cada subgrupo gana el vecino más
 *      cercano al punto previo, encadenado desde el origen; a igual distancia
 *      decide `location_id ASC`.
 *   4. **Degradación sin GPS (C2, QA-6).** Una sede fuera del territorio
 *      operativo, en `(0, 0)` o sin coordenadas permanece al final de su
 *      subgrupo con `distance_km = null`, y sin origen válido todas las
 *      distancias son nulas, `total_distance_km = 0.0` y el enlace completo es
 *      `null`.
 *   5. **Enlaces GPS.** URL individual por parada, URL completa con origen,
 *      destino y waypoints, recorte a 9 waypoints y bandera
 *      `waypoints_truncated`.
 *
 * CATEGORÍA: hermética (`tests/unit/`). Sin base de datos, red ni reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Service\RestockRouteOptimizer;

$assertions = 0;

function assertRouteOptimizerCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<array<string, mixed>> $machines
 *
 * @return array<string, mixed>
 */
function makeOptimizerStop(
    int $locationId,
    ?float $latitude,
    ?float $longitude,
    array $machines,
    SlaTier $slaTier = SlaTier::STANDARD,
    string $name = ''
): array {
    return [
        'location_id' => $locationId,
        'location_name' => $name !== '' ? $name : 'Sede ' . $locationId,
        'address' => 'Calle de Prueba ' . $locationId . ', Barcelona',
        'sla_tier' => $slaTier,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'machines' => $machines,
    ];
}

/**
 * @param list<string> $triggers
 *
 * @return array<string, mixed>
 */
function makeOptimizerMachine(
    int $machineId,
    array $triggers,
    string $status = 'PENDING',
    ?string $fefoDate = null,
    ?string $window = null
): array {
    return [
        'machine_id' => $machineId,
        'machine_code' => sprintf('VEND-BCN-%03d', $machineId),
        'machine_type' => 'COLD_DRINKS',
        'status' => $status,
        'triggers' => $triggers,
        'earliest_fefo_expiration_date' => $fefoDate,
        'preferred_time_window' => $window,
        'empty_slots_count' => 0,
        'is_temporary_reassignment' => false,
    ];
}

/**
 * @param list<array<string, mixed>> $stops
 *
 * @return list<int>
 */
function optimizerLocationIds(array $stops): array
{
    return array_map(static fn(array $stop): int => (int)$stop['location_id'], $stops);
}

$optimizer = new RestockRouteOptimizer(new GeoDistanceService());
$origin = ['source' => 'BASE_CENTRAL', 'latitude' => 41.385064, 'longitude' => 2.1734035];

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-05 · Optimizador de ruta del planificador\n";
echo "======================================================================\n";

try {
    // ─────────────────────────────────────────────────────────────────────
    // 1. Agrupación por sede y estados mixtos (QA-6)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Agrupación por sede y estados mixtos ---\n";

    $grouped = $optimizer->optimize([
        makeOptimizerStop(1, 41.3800, 2.1700, [makeOptimizerMachine(11, ['SCHEDULED'])]),
        makeOptimizerStop(1, 41.3800, 2.1700, [makeOptimizerMachine(12, ['EMPTY_SLOT'])]),
    ], $origin);
    assertRouteOptimizerCondition(
        count($grouped['stops']) === 1
            && count($grouped['stops'][0]['machines']) === 2
            && $grouped['stops'][0]['machines'][0]['machine_code'] === 'VEND-BCN-011',
        'Dos entradas de la misma sede se agrupan en una parada con sus máquinas'
    );

    $mixed = $optimizer->optimize([
        makeOptimizerStop(2, 41.3900, 2.1800, [
            makeOptimizerMachine(21, ['SCHEDULED'], 'COMPLETED'),
            makeOptimizerMachine(22, ['EMPTY_SLOT']),
        ]),
    ], $origin);
    assertRouteOptimizerCondition(
        count($mixed['stops']) === 1
            && count($mixed['stops'][0]['machines']) === 1
            && $mixed['stops'][0]['machines'][0]['machine_id'] === 22,
        'Una sede con estado mixto conserva la parada solo con sus máquinas pendientes'
    );

    $allDone = $optimizer->optimize([
        makeOptimizerStop(3, 41.4000, 2.1900, [
            makeOptimizerMachine(31, ['SCHEDULED'], 'COMPLETED'),
            makeOptimizerMachine(32, ['SCHEDULED'], 'SKIPPED'),
        ]),
    ], $origin);
    assertRouteOptimizerCondition(
        $allDone['stops'] === [] && $allDone['total_distance_km'] === 0.0,
        'Una sede sin máquinas pendientes desaparece de la hoja del día'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Clasificación y precedencia de los 3 bloques (EARS 03.2, Art. II)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Bloques FEFO, demanda y calendario ---\n";

    $blocks = $optimizer->optimize([
        makeOptimizerStop(10, 41.3800, 2.1700, [makeOptimizerMachine(101, ['SCHEDULED'])]),
        makeOptimizerStop(11, 41.3900, 2.1800, [makeOptimizerMachine(111, ['EMPTY_SLOT'])]),
        makeOptimizerStop(12, 41.4000, 2.1900, [
            makeOptimizerMachine(121, ['FEFO_ALERT', 'L1_INCIDENT'], 'PENDING', '2026-11-21'),
        ]),
        makeOptimizerStop(13, 41.4100, 2.2000, [makeOptimizerMachine(131, ['INCOMING_REASSIGNMENT'])]),
    ], $origin);

    assertRouteOptimizerCondition(
        optimizerLocationIds($blocks['stops']) === [12, 11, 10, 13],
        'FEFO_SANITARY precede a DEMAND_L1_STOCKOUT y este a SCHEDULED_CADENCE'
    );
    assertRouteOptimizerCondition(
        $blocks['stops'][0]['priority_block'] === RestockRouteOptimizer::BLOCK_FEFO_SANITARY
            && $blocks['stops'][0]['has_fefo_alert'] === true
            && $blocks['stops'][0]['has_l1_incident'] === true,
        'Un FEFO con L1 se clasifica en el bloque sanitario y conserva ambos indicadores'
    );
    assertRouteOptimizerCondition(
        $blocks['stops'][1]['priority_block'] === RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT
            && $blocks['stops'][2]['priority_block'] === RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE
            && $blocks['stops'][3]['priority_block'] === RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE,
        'Los disparadores de demanda y calendario se clasifican en su bloque'
    );
    assertRouteOptimizerCondition(
        array_column($blocks['stops'], 'stop_order') === [1, 2, 3, 4],
        'stop_order numera la hoja de ruta de forma consecutiva'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Bloque 1: fecha FEFO y Haversine
    // ─────────────────────────────────────────────────────────────────────
    $fefoOrder = $optimizer->optimize([
        makeOptimizerStop(20, 41.4100, 2.2000, [
            makeOptimizerMachine(201, ['FEFO_ALERT'], 'PENDING', '2026-11-23'),
        ]),
        makeOptimizerStop(21, 41.4036, 2.1744, [
            makeOptimizerMachine(211, ['FEFO_ALERT'], 'PENDING', '2026-11-21'),
        ]),
        makeOptimizerStop(22, 41.3800, 2.1700, [
            makeOptimizerMachine(221, ['FEFO_ALERT'], 'PENDING', '2026-11-21'),
        ]),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($fefoOrder['stops']) === [22, 21, 20],
        'El bloque FEFO ordena por fecha y, a igual fecha, por vecino más cercano'
    );
    assertRouteOptimizerCondition(
        $fefoOrder['stops'][0]['earliest_fefo_expiration_date'] === '2026-11-21'
            && $fefoOrder['stops'][2]['earliest_fefo_expiration_date'] === '2026-11-23',
        'La fecha FEFO más temprana se conserva en la parada'
    );

    $unknownFefo = $optimizer->optimize([
        makeOptimizerStop(23, 41.3900, 2.1800, [
            makeOptimizerMachine(231, ['FEFO_ALERT']),
        ]),
        makeOptimizerStop(24, 41.4000, 2.1900, [
            makeOptimizerMachine(241, ['FEFO_ALERT'], 'PENDING', '2026-11-21'),
        ]),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($unknownFefo['stops']) === [24, 23],
        'Un FEFO sin fecha declarada queda al final de su bloque'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Bloque 2: SlaTier y tipo de demanda
    // ─────────────────────────────────────────────────────────────────────
    $demandOrder = $optimizer->optimize([
        makeOptimizerStop(30, 41.3800, 2.1700, [makeOptimizerMachine(301, ['EMPTY_SLOT'])], SlaTier::STANDARD),
        makeOptimizerStop(31, 41.3900, 2.1800, [makeOptimizerMachine(311, ['L1_INCIDENT'])], SlaTier::STANDARD),
        makeOptimizerStop(32, 41.4000, 2.1900, [makeOptimizerMachine(321, ['EMPTY_SLOT'])], SlaTier::PRIORITY_CORPORATE),
        makeOptimizerStop(33, 41.4100, 2.2000, [makeOptimizerMachine(331, ['L1_INCIDENT'])], SlaTier::CRITICAL_INFRASTRUCTURE_24_7),
        makeOptimizerStop(34, 41.4200, 2.2100, [makeOptimizerMachine(341, ['EMPTY_SLOT'])], SlaTier::CRITICAL_INFRASTRUCTURE_24_7),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($demandOrder['stops']) === [33, 34, 32, 31, 30],
        'El bloque de demanda ordena por SlaTier y, dentro del mismo, L1 antes que carril vacío'
    );
    assertRouteOptimizerCondition(
        $demandOrder['stops'][0]['sla_tier'] === 'CRITICAL_INFRASTRUCTURE_24_7'
            && $demandOrder['stops'][2]['sla_tier'] === 'PRIORITY_CORPORATE'
            && $demandOrder['stops'][4]['sla_tier'] === 'STANDARD',
        'La parada publica el perfil contractual de su sede'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Bloque 3: franja horaria
    // ─────────────────────────────────────────────────────────────────────
    $windowOrder = $optimizer->optimize([
        makeOptimizerStop(40, 41.3800, 2.1700, [makeOptimizerMachine(401, ['SCHEDULED'], 'PENDING', null, 'ANY')]),
        makeOptimizerStop(41, 41.3900, 2.1800, [makeOptimizerMachine(411, ['SCHEDULED'], 'PENDING', null, 'AFTERNOON')]),
        makeOptimizerStop(42, 41.4000, 2.1900, [makeOptimizerMachine(421, ['SCHEDULED'], 'PENDING', null, 'MORNING')]),
        makeOptimizerStop(43, 41.4100, 2.2000, [makeOptimizerMachine(431, ['SCHEDULED'], 'PENDING', null, 'MIDDAY')]),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($windowOrder['stops']) === [42, 43, 41, 40],
        'El bloque de calendario ordena MORNING, MIDDAY, AFTERNOON y ANY'
    );
    assertRouteOptimizerCondition(
        $windowOrder['stops'][0]['preferred_time_window'] === 'MORNING',
        'La parada publica su franja horaria'
    );

    $earliestWindow = $optimizer->optimize([
        makeOptimizerStop(44, 41.3900, 2.1800, [
            makeOptimizerMachine(441, ['SCHEDULED'], 'PENDING', null, 'ANY'),
            makeOptimizerMachine(442, ['SCHEDULED'], 'PENDING', null, 'MORNING'),
        ]),
    ], $origin);
    assertRouteOptimizerCondition(
        $earliestWindow['stops'][0]['preferred_time_window'] === 'MORNING',
        'La franja de la parada es la más temprana entre sus máquinas'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Haversine, desempates y degradación sin GPS (C2, QA-6)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Haversine y degradación sin GPS ---\n";

    assertRouteOptimizerCondition(
        $blocks['stops'][0]['has_valid_coordinates'] === true
            && is_float($blocks['stops'][0]['distance_km'])
            && $blocks['stops'][0]['distance_km'] > 0.0
            && $blocks['stops'][0]['navigation_url'] !== null
            && str_contains($blocks['stops'][0]['navigation_url'], 'destination='),
        'Una parada mapeable publica distancia y enlace GPS individual'
    );

    $tie = $optimizer->optimize([
        makeOptimizerStop(50, 41.4000, 2.1900, [makeOptimizerMachine(501, ['EMPTY_SLOT'])], SlaTier::STANDARD),
        makeOptimizerStop(49, 41.4000, 2.1900, [makeOptimizerMachine(491, ['EMPTY_SLOT'])], SlaTier::STANDARD),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($tie['stops']) === [49, 50],
        'A igual distancia Haversine desempata location_id ASC'
    );

    $unmapped = $optimizer->optimize([
        makeOptimizerStop(60, 41.3800, 2.1700, [makeOptimizerMachine(601, ['EMPTY_SLOT'])]),
        makeOptimizerStop(61, null, null, [makeOptimizerMachine(611, ['EMPTY_SLOT'])]),
        makeOptimizerStop(62, 41.4000, 2.1900, [makeOptimizerMachine(621, ['EMPTY_SLOT'])]),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($unmapped['stops']) === [60, 62, 61]
            && $unmapped['stops'][2]['distance_km'] === null
            && $unmapped['stops'][2]['has_valid_coordinates'] === false
            && $unmapped['stops'][2]['navigation_url'] === null,
        'La sede sin GPS queda al final de su subgrupo con distancia nula'
    );
    assertRouteOptimizerCondition(
        $unmapped['unmapped_stops_count'] === 1,
        'El resumen contabiliza las paradas sin GPS'
    );

    $invalidCoordinates = $optimizer->optimize([
        makeOptimizerStop(63, 0.0, 0.0, [makeOptimizerMachine(631, ['EMPTY_SLOT'])]),
        makeOptimizerStop(64, 50.0, 2.0, [makeOptimizerMachine(641, ['EMPTY_SLOT'])]),
        makeOptimizerStop(65, 41.3800, 2.1700, [makeOptimizerMachine(651, ['EMPTY_SLOT'])]),
    ], $origin);
    assertRouteOptimizerCondition(
        optimizerLocationIds($invalidCoordinates['stops']) === [65, 63, 64]
            && $invalidCoordinates['unmapped_stops_count'] === 2,
        'Null island y las coordenadas fuera del territorio operativo degradan sin GPS'
    );

    $noOrigin = $optimizer->optimize([
        makeOptimizerStop(73, 41.4000, 2.1900, [makeOptimizerMachine(731, ['EMPTY_SLOT'])]),
        makeOptimizerStop(71, 41.3800, 2.1700, [makeOptimizerMachine(711, ['EMPTY_SLOT'])]),
        makeOptimizerStop(72, 41.3900, 2.1800, [makeOptimizerMachine(721, ['EMPTY_SLOT'])]),
    ], null);
    assertRouteOptimizerCondition(
        optimizerLocationIds($noOrigin['stops']) === [71, 72, 73]
            && $noOrigin['total_distance_km'] === 0.0
            && $noOrigin['full_route_navigation_url'] === null
            && $noOrigin['origin'] === null,
        'Sin origen válido la hoja se ordena por location_id y no inventa distancias'
    );
    assertRouteOptimizerCondition(
        array_reduce(
            $noOrigin['stops'],
            static fn(bool $carry, array $stop): bool => $carry && $stop['distance_km'] === null,
            true
        ),
        'Sin origen válido ninguna parada publica distancia'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Enlaces de ruta y waypoints
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Enlaces GPS y waypoints ---\n";

    $route = $optimizer->optimize([
        makeOptimizerStop(80, 41.3800, 2.1700, [makeOptimizerMachine(801, ['SCHEDULED'], 'PENDING', null, 'MORNING')]),
        makeOptimizerStop(81, 41.3900, 2.1800, [makeOptimizerMachine(811, ['SCHEDULED'], 'PENDING', null, 'MORNING')]),
        makeOptimizerStop(82, 41.4000, 2.1900, [makeOptimizerMachine(821, ['SCHEDULED'], 'PENDING', null, 'MORNING')]),
    ], $origin);
    assertRouteOptimizerCondition(
        $route['full_route_navigation_url'] !== null
            && str_contains($route['full_route_navigation_url'], 'origin=41.385064,2.1734035')
            && str_contains($route['full_route_navigation_url'], 'destination=')
            && str_contains($route['full_route_navigation_url'], 'waypoints=')
            && $route['waypoints_truncated'] === false,
        'La URL completa encadena origen, waypoints y destino sin recortar'
    );
    assertRouteOptimizerCondition(
        substr_count((string)$route['full_route_navigation_url'], '|') === 1,
        'Con tres paradas mapeables viaja un único waypoint intermedio'
    );
    assertRouteOptimizerCondition(
        $route['origin'] === [
            'source' => 'BASE_CENTRAL',
            'latitude' => 41.385064,
            'longitude' => 2.1734035,
        ],
        'El origen válido se publica tal cual en la hoja'
    );

    $distanceSum = 0.0;
    foreach ($route['stops'] as $stop) {
        $distanceSum += (float)$stop['distance_km'];
    }
    assertRouteOptimizerCondition(
        $route['total_distance_km'] === round($distanceSum, 2) && $route['total_distance_km'] > 0.0,
        'La distancia total es la suma exacta de las distancias de las paradas'
    );

    $manyStops = [];
    for ($i = 0; $i < 12; $i++) {
        $manyStops[] = makeOptimizerStop(
            100 + $i,
            41.3700 + ($i * 0.004),
            2.1600 + ($i * 0.004),
            [makeOptimizerMachine(1000 + $i, ['SCHEDULED'], 'PENDING', null, 'MORNING')]
        );
    }
    $truncated = $optimizer->optimize($manyStops, $origin);
    $waypointQuery = '';
    if (preg_match('/&waypoints=([^&]+)/', (string)$truncated['full_route_navigation_url'], $matches) === 1) {
        $waypointQuery = $matches[1];
    }
    assertRouteOptimizerCondition(
        $truncated['waypoints_truncated'] === true
            && substr_count($waypointQuery, '|') === RestockRouteOptimizer::MAX_WAYPOINTS - 1,
        'Con más de diez paradas el enlace recorta a nueve waypoints y lo declara'
    );
    assertRouteOptimizerCondition(
        $truncated['unmapped_stops_count'] === 0
            && count($truncated['stops']) === 12,
        'Doce paradas mapeables conservan sus doce paradas en la hoja'
    );

    $singleStop = $optimizer->optimize([
        makeOptimizerStop(90, 41.3800, 2.1700, [makeOptimizerMachine(901, ['SCHEDULED'])]),
    ], $origin);
    assertRouteOptimizerCondition(
        $singleStop['full_route_navigation_url'] !== null
            && !str_contains((string)$singleStop['full_route_navigation_url'], 'waypoints=')
            && $singleStop['waypoints_truncated'] === false,
        'Con una sola parada el enlace completo no inventa waypoints'
    );

    $onlyUnmapped = $optimizer->optimize([
        makeOptimizerStop(91, null, null, [makeOptimizerMachine(911, ['SCHEDULED'])]),
    ], $origin);
    assertRouteOptimizerCondition(
        $onlyUnmapped['full_route_navigation_url'] === null
            && $onlyUnmapped['total_distance_km'] === 0.0,
        'Sin paradas mapeables no se publica enlace completo'
    );
    echo "  [OK]   Agrupación, 3 bloques, Haversine, sin GPS y enlaces de ruta\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-05 · Optimizador de ruta del planificador\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
