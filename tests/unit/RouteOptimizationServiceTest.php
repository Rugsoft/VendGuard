<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteOptimizationService;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\StopPriority;
use VendGuard\Core\Domain\Model\StopStatus;
use VendGuard\Core\Domain\Model\TechnicianRouteMap;

$assertions = 0;

function assertRouteOptimizationCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeOptimizationLocation(int $id, float $latitude, float $longitude): Location
{
    return new Location(
        $id,
        'SEDE-TEST-' . $id,
        'Route test location ' . $id,
        'Carrer de Prova ' . $id,
        null,
        null,
        true,
        null,
        null,
        null,
        $latitude,
        $longitude
    );
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function makeOptimizationTask(Location $location, int $id, string $status, array $overrides = []): array
{
    return array_replace([
        'location' => $location,
        'type' => 'CORRECTIVE',
        'id' => $id,
        'machine_id' => 1000 + $id,
        'machine_code' => 'VEND-' . $id,
        'machine_type' => 'HOT_DRINKS',
        'floor_location' => 'Planta 1',
        'status' => $status,
        'urgency' => 'MEDIUM',
        'is_critical' => false,
        'sla_due_at' => null,
        'created_at' => '2026-09-29T10:00:00+02:00',
    ], $overrides);
}

try {
    $service = new RouteOptimizationService(new GeoDistanceService());
    $settings = new RouteSettings();
    $referenceTime = new DateTimeImmutable('2026-09-30T09:00:00+02:00');
    $origin = [
        'latitude' => 41.3935000,
        'longitude' => 2.1890000,
        'address' => 'GPS de prueba',
    ];

    $inProgressLocation = makeOptimizationLocation(10, 41.3940000, 2.1890000);
    $criticalSoonLocation = makeOptimizationLocation(21, 41.4100000, 2.1900000);
    $criticalLaterLocation = makeOptimizationLocation(22, 41.4050000, 2.1900000);
    $ordinaryNearLocation = makeOptimizationLocation(31, 41.4060000, 2.1900000);
    $ordinarySouthLocation = makeOptimizationLocation(33, 41.3500000, 2.1900000);
    $ordinaryFarLocation = makeOptimizationLocation(32, 41.5000000, 2.1900000);
    $ordinaryPerishableLocation = makeOptimizationLocation(34, 41.3000000, 2.1900000);
    $partsLocation = makeOptimizationLocation(99, 41.3900000, 2.1800000);

    $tasks = [
        makeOptimizationTask($ordinaryFarLocation, 320, 'ASSIGNED'),
        makeOptimizationTask($criticalLaterLocation, 220, 'ASSIGNED', [
            'machine_type' => 'PERISHABLE_FOOD',
            'urgency' => 'CRITICAL',
            'is_critical' => true,
            'sla_due_at' => '2026-09-30T12:00:00+02:00',
        ]),
        makeOptimizationTask($ordinaryNearLocation, 310, 'ASSIGNED'),
        makeOptimizationTask($inProgressLocation, 101, 'IN_PROGRESS'),
        makeOptimizationTask($criticalSoonLocation, 210, 'ASSIGNED', [
            'machine_type' => 'PERISHABLE_FOOD',
            'urgency' => 'CRITICAL',
            'is_critical' => true,
            'sla_due_at' => '2026-09-30T10:00:00+02:00',
        ]),
        makeOptimizationTask($inProgressLocation, 102, 'RESOLVED'),
        makeOptimizationTask($ordinarySouthLocation, 330, 'ASSIGNED'),
        makeOptimizationTask($ordinaryNearLocation, 311, 'RESOLVED', [
            'type' => 'CORRECTIVE',
            'machine_type' => 'PERISHABLE_FOOD',
            'urgency' => 'CRITICAL',
            'is_critical' => true,
            'sla_due_at' => '2026-09-30T09:30:00+02:00',
        ]),
        makeOptimizationTask($ordinaryPerishableLocation, 340, 'ASSIGNED', [
            'machine_type' => 'PERISHABLE_FOOD',
            'urgency' => 'CRITICAL',
            'is_critical' => true,
            'sla_due_at' => '2026-09-30T14:00:00+02:00',
        ]),
        makeOptimizationTask($partsLocation, 990, 'PENDING_PARTS', [
            'machine_type' => 'PERISHABLE_FOOD',
            'urgency' => 'CRITICAL',
            'is_critical' => true,
            'sla_due_at' => '2026-09-30T09:15:00+02:00',
        ]),
    ];

    $route = $service->optimizeRoute($tasks, $origin, $settings, $referenceTime);
    assertRouteOptimizationCondition($route instanceof TechnicianRouteMap, 'optimizeRoute() returns a TechnicianRouteMap');
    $stops = $route->getStops();
    $siteCodes = array_map(static fn($stop): string => $stop->getLocation()->getSiteCode(), $stops);
    assertRouteOptimizationCondition(
        $siteCodes === ['SEDE-TEST-10', 'SEDE-TEST-21', 'SEDE-TEST-22', 'SEDE-TEST-31', 'SEDE-TEST-33', 'SEDE-TEST-34', 'SEDE-TEST-32'],
        'Route order pins in-progress first, sorts critical SLA deadlines, then uses nearest neighbor for ordinary stops: ' . implode(',', array_map(static fn($stop): string => $stop->getLocation()->getSiteCode() . '=' . $stop->getPriority()->value . '/' . $stop->getStatus()->value, $stops))
    );
    assertRouteOptimizationCondition(count($stops) === 7, 'Tasks at one site are consolidated and PENDING_PARTS tasks are excluded');
    assertRouteOptimizationCondition(
        $stops[0]->getStatus() === StopStatus::IN_PROGRESS
            && $stops[0]->getTotalTasks() === 2
            && $stops[0]->getCompletedTasks() === 1
            && $stops[0]->getTasks()[1]['status'] === 'RESOLVED',
        'A consolidated stop reports in-progress state and partial task completion'
    );
    assertRouteOptimizationCondition(
        $stops[1]->getPriority() === StopPriority::CRITICAL
            && $stops[2]->getPriority() === StopPriority::CRITICAL,
        'Perishable tasks with SLA under four hours inherit critical stop priority'
    );
    assertRouteOptimizationCondition(
        $stops[3]->getPriority() === StopPriority::ORDINARY
            && !$stops[3]->isCritical()
            && $stops[3]->getTotalTasks() === 2
            && $stops[3]->getCompletedTasks() === 1,
        'A resolved perishable task does not keep remaining ordinary work critical'
    );
    assertRouteOptimizationCondition(
        $stops[6]->getPriority() === StopPriority::ORDINARY,
        'A perishable task with more than four hours remaining is not classified as critical'
    );
    assertRouteOptimizationCondition(
        str_contains($stops[1]->getNavigationUrl(), 'destination=41.41,2.19')
            && str_contains($route->getFullRouteNavigationUrl(), 'waypoints=')
            && str_contains($route->getFullRouteNavigationUrl(), '%7C'),
        'Individual and full-route links use universal Google Maps URLs with ordered waypoints'
    );
    assertRouteOptimizationCondition(
        $route->getOrigin()['source'] === 'GPS'
            && $route->getOrigin()['latitude'] === $origin['latitude'],
        'Valid GPS coordinates are used as the route origin'
    );

    $fallbackRoute = $service->optimizeRoute(
        [makeOptimizationTask($ordinaryNearLocation, 401, 'ASSIGNED')],
        ['latitude' => 0.0, 'longitude' => 0.0],
        $settings,
        $referenceTime
    );
    assertRouteOptimizationCondition(
        $fallbackRoute->getOrigin()['source'] === 'BASE_CENTRAL'
            && $fallbackRoute->getOrigin()['latitude'] === $settings->getBaseLatitude()
            && $fallbackRoute->getOrigin()['longitude'] === $settings->getBaseLongitude(),
        'Invalid GPS coordinates fall back to the configured central base'
    );

    $emptyRoute = $service->optimizeRoute([], null, $settings, $referenceTime);
    assertRouteOptimizationCondition(
        $emptyRoute->getTotalStops() === 0
            && $emptyRoute->getTotalTasks() === 0
            && $emptyRoute->getOrigin()['source'] === 'BASE_CENTRAL'
            && $emptyRoute->getFullRouteNavigationUrl() === '',
        'An empty task list returns an empty route without a navigation URL'
    );

    $tieOlderLocation = makeOptimizationLocation(52, 41.4000000, 2.1900000);
    $tieNewerLocation = makeOptimizationLocation(51, 41.4000000, 2.1900000);
    $tieRoute = $service->optimizeRoute([
        makeOptimizationTask($tieNewerLocation, 501, 'ASSIGNED', ['created_at' => '2026-09-29T11:00:00+02:00']),
        makeOptimizationTask($tieOlderLocation, 502, 'ASSIGNED', ['created_at' => '2026-09-29T10:00:00+02:00']),
    ], $origin, $settings, $referenceTime);
    assertRouteOptimizationCondition(
        $tieRoute->getStops()[0]->getLocation()->getId() === 52,
        'Equal nearest-neighbor distances are resolved by incident age before location ID'
    );

    $precisionRoute = $service->optimizeRoute([
        makeOptimizationTask(makeOptimizationLocation(40, 41.39348, 2.189), 540, 'ASSIGNED'),
        makeOptimizationTask(makeOptimizationLocation(41, 41.39351, 2.189), 541, 'ASSIGNED'),
    ], $origin, $settings, $referenceTime);
    assertRouteOptimizationCondition(
        $precisionRoute->getStops()[0]->getLocation()->getId() === 41,
        'Nearest-neighbor selection compares unrounded distances before applying tie breakers'
    );

    $manyTasks = [];
    for ($index = 1; $index <= 30; $index++) {
        $location = makeOptimizationLocation(
            100 + $index,
            40.0 + ($index / 100),
            1.0 + ($index / 100)
        );
        $manyTasks[] = makeOptimizationTask($location, 1000 + $index, 'ASSIGNED');
    }
    $start = microtime(true);
    $largeRoute = $service->optimizeRoute($manyTasks, $origin, $settings, $referenceTime);
    $elapsedMilliseconds = (microtime(true) - $start) * 1000;
    assertRouteOptimizationCondition($largeRoute->getTotalStops() === 30, 'The service handles a 30-stop workday');
    assertRouteOptimizationCondition(
        $elapsedMilliseconds < 50.0,
        sprintf('A 30-stop route completes in under 50 ms (%.3f ms)', $elapsedMilliseconds)
    );

    $truncationTasks = [];
    for ($index = 1; $index <= 11; $index++) {
        $location = makeOptimizationLocation(
            200 + $index,
            40.0 + ($index / 100),
            1.5 + ($index / 100)
        );
        $truncationTasks[] = makeOptimizationTask($location, 2000 + $index, 'ASSIGNED');
    }
    $truncatedRoute = $service->optimizeRoute($truncationTasks, $origin, $settings, $referenceTime);
    assertRouteOptimizationCondition(
        $truncatedRoute->areWaypointsTruncated()
            && substr_count($truncatedRoute->getFullRouteNavigationUrl(), '%7C') === 7,
        'The full-route link is capped at eight waypoints and a destination and flags truncation'
    );

    echo "RouteOptimizationService tests passed. Total Assertions: {$assertions}; 30-stop calculation: "
        . sprintf('%.3f ms', $elapsedMilliseconds) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "RouteOptimizationService test failed: {$exception->getMessage()}\n");
    exit(1);
}
