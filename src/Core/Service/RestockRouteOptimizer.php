<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\ValueObject\SlaTier;

/**
 * RestockRouteOptimizer
 *
 * Motor puro de agrupación y ordenación de la hoja de ruta diaria (Módulo 37,
 * RF-03.2, RNF-02, D6, C2, QA-6, Art. II y Art. IV).
 *
 * No lee la base, no escribe y no conoce usuarios ni permisos: recibe las
 * máquinas ya filtradas por el servicio y devuelve las paradas ordenadas, la
 * distancia total y el enlace de navegación. La composición de candidatas
 * (`FEFO_ALERT`, `L1_INCIDENT`, `EMPTY_SLOT`, `SCHEDULED`,
 * `INCOMING_REASSIGNMENT`), la propiedad efectiva y las bandejas de bloqueadas
 * y sin cobertura pertenecen a las tareas de servicio.
 *
 * Reglas que aplica, en el orden del Criterio EARS 03.2:
 *
 *   1. **Agrupación por sede.** Las máquinas `PENDING` se agrupan por
 *      `location_id`; una sede con estado mixto conserva su parada mientras
 *      quede al menos una máquina pendiente y las completadas, omitidas o
 *      bloqueadas se descartan de la parada activa (`QA-6`).
 *   2. **Bloque 1 · `FEFO_SANITARY` (Art. II).** Sedes con al menos una máquina
 *      con disparador `FEFO_ALERT`, ordenadas por
 *      `earliest_fefo_expiration_date` ascendente y, a igual fecha, por vecino
 *      más cercano Haversine con desempate `location_id ASC`.
 *   3. **Bloque 2 · `DEMAND_L1_STOCKOUT`.** Sedes sin FEFO con `L1_INCIDENT` o
 *      `EMPTY_SLOT`, ordenadas por `SlaTier`
 *      (`CRITICAL_INFRASTRUCTURE_24_7` → `PRIORITY_CORPORATE` → `STANDARD`),
 *      después las de `L1_INCIDENT` antes que las de solo `EMPTY_SLOT`, y dentro
 *      de cada subgrupo por vecino más cercano.
 *   4. **Bloque 3 · `SCHEDULED_CADENCE`.** Resto de sedes, ordenadas por la
 *      franja más temprana entre sus máquinas (`MORNING` → `MIDDAY` →
 *      `AFTERNOON` → `ANY`) y, dentro de cada franja, por vecino más cercano.
 *   5. **Degradación elegante sin GPS (C2, QA-6).** Una sede sin coordenadas
 *      válidas —fuera de `[27.0..44.5] × [-18.5..5.0]`, `(0, 0)` o valores no
 *      finitos— permanece al final de su subgrupo con `distance_km = null` y
 *      `has_valid_coordinates = false`, sin abortar la ruta. Sin origen válido,
 *      todas las distancias son `null`, `total_distance_km = 0.0` y
 *      `full_route_navigation_url = null`.
 *   6. **Enlace de ruta completa.** Se construye con el origen y la última
 *      parada mapeable como destino; las paradas intermedias viajan como
 *      `waypoints` y se recortan a los 9 que admite Google Maps, marcando
 *      `waypoints_truncated = true`.
 *
 * La entrada de cada parada es un array documentado (no una entidad) porque el
 * gateway proyecta filas SQL y este motor debe seguir siendo puro y comprobable
 * sin base de datos. Las claves de máquina que no usa se copian tal cual para no
 * perder el contrato de la superficie móvil.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRouteOptimizer
{
    /** Bloques de prioridad, en el orden exacto del contrato. */
    public const BLOCK_FEFO_SANITARY = 'FEFO_SANITARY';
    public const BLOCK_DEMAND_L1_STOCKOUT = 'DEMAND_L1_STOCKOUT';
    public const BLOCK_SCHEDULED_CADENCE = 'SCHEDULED_CADENCE';

    /** Disparadores canónicos de la composición diaria (EARS 03.1). */
    public const TRIGGER_FEFO_ALERT = 'FEFO_ALERT';
    public const TRIGGER_L1_INCIDENT = 'L1_INCIDENT';
    public const TRIGGER_EMPTY_SLOT = 'EMPTY_SLOT';
    public const TRIGGER_SCHEDULED = 'SCHEDULED';
    public const TRIGGER_INCOMING_REASSIGNMENT = 'INCOMING_REASSIGNMENT';

    /** Única situación que mantiene viva una parada en la hoja del día. */
    public const MACHINE_STATUS_PENDING = 'PENDING';

    /** Tope de paradas intermedias del enlace de Google Maps. */
    public const MAX_WAYPOINTS = 9;

    /** Fecha centinela para un FEFO sin caducidad declarada: va al final. */
    private const UNKNOWN_FEFO_DATE = '9999-12-31';

    /** Tolerancia de comparación entre distancias Haversine. */
    private const DISTANCE_EPSILON = 1e-9;

    private const GOOGLE_MAPS_DIRECTIONS_URL = 'https://www.google.com/maps/dir/';

    private GeoDistanceService $geoDistance;

    public function __construct(GeoDistanceService $geoDistance)
    {
        $this->geoDistance = $geoDistance;
    }

    /**
     * Ordena la hoja de ruta del día y calcula sus distancias.
     *
     * Cada parada de entrada admite:
     *   - `location_id` (int, obligatorio)
     *   - `location_name` (string)
     *   - `address` (string)
     *   - `sla_tier` (`SlaTier` o string; `STANDARD` por defecto)
     *   - `latitude`, `longitude` (`float|null`)
     *   - `machines` (lista de arrays; cada una con `status` —`PENDING` por
     *     defecto—, `triggers`, `earliest_fefo_expiration_date` y
     *     `preferred_time_window`)
     *
     * @param list<array<string, mixed>> $stops
     * @param array{source?: string, latitude?: float|null, longitude?: float|null}|null $origin
     *
     * @return array{
     *     stops: list<array<string, mixed>>,
     *     total_distance_km: float,
     *     unmapped_stops_count: int,
     *     full_route_navigation_url: ?string,
     *     waypoints_truncated: bool,
     *     origin: ?array<string, mixed>
     * }
     *
     * @throws InvalidArgumentException Si una parada no declara `location_id`.
     */
    public function optimize(array $stops, ?array $origin = null): array
    {
        $originPoint = $this->normalizeOrigin($origin);
        $grouped = $this->groupByLocation($stops);

        $fefoStops = [];
        $demandStops = [];
        $scheduledStops = [];

        foreach ($grouped as $stop) {
            $block = $stop['priority_block'];
            if ($block === self::BLOCK_FEFO_SANITARY) {
                $fefoStops[] = $stop;
            } elseif ($block === self::BLOCK_DEMAND_L1_STOCKOUT) {
                $demandStops[] = $stop;
            } else {
                $scheduledStops[] = $stop;
            }
        }

        $currentPoint = $originPoint;
        $ordered = array_merge(
            $this->orderFefoBlock($fefoStops, $currentPoint),
            $this->orderDemandBlock($demandStops, $currentPoint),
            $this->orderScheduledBlock($scheduledStops, $currentPoint)
        );

        $position = 1;
        $totalDistance = 0.0;
        $unmappedStops = 0;
        $mappedStops = [];

        foreach ($ordered as $index => $stop) {
            $stop['stop_order'] = $position++;
            if ($stop['has_valid_coordinates']) {
                $mappedStops[] = $stop;
                $totalDistance += (float)$stop['distance_km'];
            } else {
                $unmappedStops++;
            }
            $ordered[$index] = $stop;
        }

        $fullRouteUrl = null;
        $waypointsTruncated = false;
        if ($originPoint !== null && $mappedStops !== []) {
            [$fullRouteUrl, $waypointsTruncated] = $this->buildFullRouteUrl($originPoint, $mappedStops);
        }

        return [
            'stops' => $ordered,
            'total_distance_km' => round($totalDistance, 2),
            'unmapped_stops_count' => $unmappedStops,
            'full_route_navigation_url' => $fullRouteUrl,
            'waypoints_truncated' => $waypointsTruncated,
            'origin' => $originPoint,
        ];
    }

    /**
     * Agrupa por sede, filtra máquinas no pendientes y clasifica el bloque.
     *
     * @param list<array<string, mixed>> $stops
     *
     * @return list<array<string, mixed>>
     */
    private function groupByLocation(array $stops): array
    {
        /** @var array<int, array<string, mixed>> $byLocation */
        $byLocation = [];

        foreach ($stops as $stop) {
            if (!isset($stop['location_id']) || (int)$stop['location_id'] <= 0) {
                throw new InvalidArgumentException('Cada parada debe declarar un location_id positivo.');
            }

            $locationId = (int)$stop['location_id'];
            if (!isset($byLocation[$locationId])) {
                $byLocation[$locationId] = [
                    'location_id' => $locationId,
                    'location_name' => (string)($stop['location_name'] ?? ''),
                    'address' => (string)($stop['address'] ?? ''),
                    'sla_tier' => $this->normalizeSlaTier($stop['sla_tier'] ?? null),
                    'latitude' => isset($stop['latitude']) ? (float)$stop['latitude'] : null,
                    'longitude' => isset($stop['longitude']) ? (float)$stop['longitude'] : null,
                    'machines' => [],
                ];
            }

            foreach ((array)($stop['machines'] ?? []) as $machine) {
                if (!is_array($machine)) {
                    continue;
                }

                $status = strtoupper(trim((string)($machine['status'] ?? self::MACHINE_STATUS_PENDING)));
                if ($status !== self::MACHINE_STATUS_PENDING) {
                    continue;
                }

                $triggers = array_values(array_filter(
                    array_map(
                        static fn(mixed $trigger): string => strtoupper(trim((string)$trigger)),
                        (array)($machine['triggers'] ?? [])
                    ),
                    static fn(string $trigger): bool => $trigger !== ''
                ));
                $machine['triggers'] = $triggers;
                $machine['has_fefo_alert'] = in_array(self::TRIGGER_FEFO_ALERT, $triggers, true);

                $byLocation[$locationId]['machines'][] = $machine;
            }
        }

        $result = [];
        foreach ($byLocation as $stop) {
            if ($stop['machines'] === []) {
                continue;
            }
            $result[] = $this->enrichStop($stop);
        }

        return $result;
    }

    /**
     * Deriva del conjunto de máquinas el bloque, la fecha FEFO y la franja.
     *
     * @param array<string, mixed> $stop
     *
     * @return array<string, mixed>
     */
    private function enrichStop(array $stop): array
    {
        $hasFefo = false;
        $hasL1 = false;
        $hasEmptySlot = false;
        $earliestFefo = null;
        $window = null;

        foreach ($stop['machines'] as $machine) {
            $triggers = $machine['triggers'];

            if (in_array(self::TRIGGER_FEFO_ALERT, $triggers, true)) {
                $hasFefo = true;
                $expiration = $machine['earliest_fefo_expiration_date'] ?? null;
                $expiration = is_string($expiration) && trim($expiration) !== '' ? trim($expiration) : null;
                if ($expiration !== null && ($earliestFefo === null || $expiration < $earliestFefo)) {
                    $earliestFefo = $expiration;
                }
            }

            if (in_array(self::TRIGGER_L1_INCIDENT, $triggers, true)) {
                $hasL1 = true;
            }

            if (in_array(self::TRIGGER_EMPTY_SLOT, $triggers, true)) {
                $hasEmptySlot = true;
            }

            $machineWindow = $this->normalizeTimeWindow($machine['preferred_time_window'] ?? null);
            if ($window === null || $machineWindow->windowOrder() < $window->windowOrder()) {
                $window = $machineWindow;
            }
        }

        if ($hasFefo) {
            $block = self::BLOCK_FEFO_SANITARY;
        } elseif ($hasL1 || $hasEmptySlot) {
            $block = self::BLOCK_DEMAND_L1_STOCKOUT;
        } else {
            $block = self::BLOCK_SCHEDULED_CADENCE;
        }

        $hasValidCoordinates = $stop['latitude'] !== null
            && $stop['longitude'] !== null
            && $this->geoDistance->isInsideOperationalArea($stop['latitude'], $stop['longitude']);

        $stop['priority_block'] = $block;
        $stop['has_fefo_alert'] = $hasFefo;
        $stop['has_l1_incident'] = $hasL1;
        $stop['has_empty_slot'] = $hasEmptySlot;
        $stop['earliest_fefo_expiration_date'] = $hasFefo ? $earliestFefo : null;
        $stop['preferred_time_window'] = ($window ?? RestockTimeWindow::ANY)->value;
        $stop['has_valid_coordinates'] = $hasValidCoordinates;
        $stop['distance_km'] = null;
        $stop['navigation_url'] = null;
        $stop['sla_tier'] = $stop['sla_tier']->value;

        return $stop;
    }

    /**
     * Bloque 1: fecha FEFO ascendente y, a igual fecha, vecino más cercano.
     *
     * @param list<array<string, mixed>> $stops
     * @param array{latitude: float, longitude: float}|null $currentPoint
     *
     * @return list<array<string, mixed>>
     */
    private function orderFefoBlock(array $stops, ?array &$currentPoint): array
    {
        $byDate = [];
        foreach ($stops as $stop) {
            $date = $stop['earliest_fefo_expiration_date'] ?? self::UNKNOWN_FEFO_DATE;
            $byDate[$date][] = $stop;
        }

        ksort($byDate, SORT_STRING);

        $ordered = [];
        foreach ($byDate as $group) {
            $ordered = array_merge($ordered, $this->orderNearestNeighbor($group, $currentPoint));
        }

        return $ordered;
    }

    /**
     * Bloque 2: SlaTier, luego L1 antes que solo carril vacío, y Haversine.
     *
     * @param list<array<string, mixed>> $stops
     * @param array{latitude: float, longitude: float}|null $currentPoint
     *
     * @return list<array<string, mixed>>
     */
    private function orderDemandBlock(array $stops, ?array &$currentPoint): array
    {
        $groups = [];
        foreach ($stops as $stop) {
            $tier = SlaTier::fromString((string)$stop['sla_tier']);
            $kind = $stop['has_l1_incident'] ? 0 : 1;
            $groups[$this->slaTierRank($tier) . ':' . $kind][] = $stop;
        }

        ksort($groups, SORT_STRING);

        $ordered = [];
        foreach ($groups as $group) {
            $ordered = array_merge($ordered, $this->orderNearestNeighbor($group, $currentPoint));
        }

        return $ordered;
    }

    /**
     * Bloque 3: franja más temprana y, dentro de ella, vecino más cercano.
     *
     * @param list<array<string, mixed>> $stops
     * @param array{latitude: float, longitude: float}|null $currentPoint
     *
     * @return list<array<string, mixed>>
     */
    private function orderScheduledBlock(array $stops, ?array &$currentPoint): array
    {
        $groups = [];
        foreach ($stops as $stop) {
            $window = RestockTimeWindow::fromString((string)$stop['preferred_time_window']);
            $groups[$window->windowOrder()][] = $stop;
        }

        ksort($groups, SORT_NUMERIC);

        $ordered = [];
        foreach ($groups as $group) {
            $ordered = array_merge($ordered, $this->orderNearestNeighbor($group, $currentPoint));
        }

        return $ordered;
    }

    /**
     * Vecino más cercano encadenado desde el punto previo, con las sedes sin
     * GPS al final del subgrupo y desempate `location_id ASC`.
     *
     * @param list<array<string, mixed>> $stops
     * @param array{latitude: float, longitude: float}|null $currentPoint
     *
     * @return list<array<string, mixed>>
     */
    private function orderNearestNeighbor(array $stops, ?array &$currentPoint): array
    {
        $mapped = [];
        $unmapped = [];
        foreach ($stops as $stop) {
            if ($stop['has_valid_coordinates']) {
                $mapped[] = $stop;
            } else {
                $unmapped[] = $stop;
            }
        }

        usort(
            $unmapped,
            static fn(array $left, array $right): int => $left['location_id'] <=> $right['location_id']
        );

        if ($currentPoint === null) {
            usort(
                $mapped,
                static fn(array $left, array $right): int => $left['location_id'] <=> $right['location_id']
            );

            return array_merge($mapped, $unmapped);
        }

        $ordered = [];
        $remaining = $mapped;

        while ($remaining !== []) {
            $bestIndex = 0;
            $bestDistance = null;

            foreach ($remaining as $index => $candidate) {
                $distance = $this->geoDistance->calculatePreciseDistanceKm(
                    $currentPoint['latitude'],
                    $currentPoint['longitude'],
                    (float)$candidate['latitude'],
                    (float)$candidate['longitude']
                );

                $isCloser = $bestDistance === null || $distance < $bestDistance - self::DISTANCE_EPSILON;
                $isTieWithLowerId = $bestDistance !== null
                    && abs($distance - $bestDistance) <= self::DISTANCE_EPSILON
                    && $candidate['location_id'] < $remaining[$bestIndex]['location_id'];

                if ($isCloser || $isTieWithLowerId) {
                    $bestIndex = $index;
                    $bestDistance = $distance;
                }
            }

            $chosen = $remaining[$bestIndex];
            array_splice($remaining, $bestIndex, 1);

            $chosen['distance_km'] = $this->geoDistance->calculateDistanceKm(
                $currentPoint['latitude'],
                $currentPoint['longitude'],
                (float)$chosen['latitude'],
                (float)$chosen['longitude']
            );
            $chosen['navigation_url'] = $this->buildStopNavigationUrl($chosen);

            $currentPoint = [
                'latitude' => (float)$chosen['latitude'],
                'longitude' => (float)$chosen['longitude'],
            ];

            $ordered[] = $chosen;
        }

        foreach ($unmapped as $stop) {
            $stop['distance_km'] = null;
            $stop['navigation_url'] = null;
            $ordered[] = $stop;
        }

        return $ordered;
    }

    /**
     * @param array{latitude: float, longitude: float} $origin
     * @param list<array<string, mixed>> $mappedStops
     *
     * @return array{0: ?string, 1: bool}
     */
    private function buildFullRouteUrl(array $origin, array $mappedStops): array
    {
        $destination = $mappedStops[count($mappedStops) - 1];
        $waypoints = array_slice($mappedStops, 0, -1);

        $url = self::GOOGLE_MAPS_DIRECTIONS_URL
            . '?api=1&origin=' . $this->formatCoordinates($origin['latitude'], $origin['longitude'])
            . '&destination=' . $this->formatCoordinates(
                (float)$destination['latitude'],
                (float)$destination['longitude']
            )
            . '&travelmode=driving';

        $truncated = count($waypoints) > self::MAX_WAYPOINTS;
        if ($waypoints !== []) {
            $waypoints = array_slice($waypoints, 0, self::MAX_WAYPOINTS);
            $url .= '&waypoints=' . implode('|', array_map(
                fn(array $stop): string => $this->formatCoordinates(
                    (float)$stop['latitude'],
                    (float)$stop['longitude']
                ),
                $waypoints
            ));
        }

        return [$url, $truncated];
    }

    /**
     * @param array<string, mixed> $stop
     */
    private function buildStopNavigationUrl(array $stop): ?string
    {
        if (!$stop['has_valid_coordinates']) {
            return null;
        }

        return self::GOOGLE_MAPS_DIRECTIONS_URL
            . '?api=1&destination=' . $this->formatCoordinates(
                (float)$stop['latitude'],
                (float)$stop['longitude']
            )
            . '&travelmode=driving';
    }

    private function formatCoordinates(float $latitude, float $longitude): string
    {
        return $this->formatCoordinate($latitude) . ',' . $this->formatCoordinate($longitude);
    }

    private function formatCoordinate(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @param array{source?: string, latitude?: float|null, longitude?: float|null}|null $origin
     *
     * @return array{source: string, latitude: float, longitude: float}|null
     */
    private function normalizeOrigin(?array $origin): ?array
    {
        if ($origin === null) {
            return null;
        }

        $latitude = isset($origin['latitude']) ? (float)$origin['latitude'] : null;
        $longitude = isset($origin['longitude']) ? (float)$origin['longitude'] : null;

        if ($latitude === null
            || $longitude === null
            || !$this->geoDistance->isInsideOperationalArea($latitude, $longitude)) {
            return null;
        }

        return [
            'source' => (string)($origin['source'] ?? 'BASE_CENTRAL'),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function normalizeSlaTier(mixed $value): SlaTier
    {
        if ($value instanceof SlaTier) {
            return $value;
        }

        return is_string($value) && $value !== '' ? SlaTier::fromString($value) : SlaTier::STANDARD;
    }

    private function normalizeTimeWindow(mixed $value): RestockTimeWindow
    {
        if ($value instanceof RestockTimeWindow) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? RestockTimeWindow::fromString($value)
            : RestockTimeWindow::ANY;
    }

    private function slaTierRank(SlaTier $tier): int
    {
        return match ($tier) {
            SlaTier::CRITICAL_INFRASTRUCTURE_24_7 => 0,
            SlaTier::PRIORITY_CORPORATE => 1,
            SlaTier::STANDARD => 2,
        };
    }
}
