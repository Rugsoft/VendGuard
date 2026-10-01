<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\RouteStop;
use VendGuard\Core\Domain\Model\StopPriority;
use VendGuard\Core\Domain\Model\StopStatus;
use VendGuard\Core\Domain\Model\TechnicianRouteMap;

$assertions = 0;

function assertRouteCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertRouteThrows(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        assertRouteCondition(true, $message);
        return;
    }

    assertRouteCondition(false, $message);
}

try {
    assertRouteCondition(
        array_column(StopStatus::cases(), 'value') === ['IN_PROGRESS', 'PENDING', 'COMPLETED'],
        'StopStatus exposes only the specified states'
    );
    assertRouteCondition(
        array_column(StopPriority::cases(), 'value') === ['CRITICAL', 'ORDINARY'],
        'StopPriority exposes only the specified priorities'
    );

    $legacyLocation = new Location(1, 'SEDE-BCN-01', 'Hospital del Mar', 'Passeig Marítim 25');
    assertRouteCondition($legacyLocation->hasValidCoordinates(), 'legacy Location construction receives valid migration defaults');
    assertRouteCondition(
        $legacyLocation->getLatitude() === 41.3850640 && $legacyLocation->getLongitude() === 2.1734035,
        'Location coordinates default to the migration values'
    );

    $location = new Location(
        2,
        'SEDE-BCN-02',
        'Torre Glòries',
        'Avinguda Diagonal 211',
        null,
        null,
        true,
        null,
        null,
        null,
        41.4036290,
        2.1895120
    );
    assertRouteCondition($location->hasValidCoordinates(), 'Location accepts coordinates inside the operational territory');
    assertRouteCondition($location->toArray()['latitude'] === 41.4036290, 'Location serializes latitude and longitude');
    $databaseLocation = Location::fromDatabaseRow([
        'id' => 5,
        'site_code' => 'SEDE-DB-01',
        'name' => 'Sede de prueba',
        'address' => 'Carrer de Prova 1',
        'latitude' => '41.4036290',
        'longitude' => '2.1895120',
    ]);
    assertRouteCondition($databaseLocation->getLatitude() === 41.4036290, 'Location restores coordinates from database rows');
    assertRouteThrows(
        fn() => new Location(3, 'SEDE-INVALID', 'Invalid', 'Unknown', null, null, true, null, null, null, 0.0, 0.0),
        'Location rejects coordinates outside the operational territory'
    );
    assertRouteThrows(
        fn() => new Location(4, 'SEDE-INVALID', 'Invalid', 'Unknown', null, null, true, null, null, null, NAN, 2.0),
        'Location rejects non-finite coordinates'
    );

    $tasks = [
        ['type' => 'CORRECTIVE', 'id' => 101, 'status' => 'IN_PROGRESS'],
        ['type' => 'PREVENTIVE', 'id' => 501, 'status' => 'ASSIGNED'],
    ];
    $stop = new RouteStop(
        1,
        $location,
        StopStatus::IN_PROGRESS,
        StopPriority::CRITICAL,
        true,
        2,
        1,
        $tasks,
        2.85,
        'https://www.google.com/maps/dir/?api=1&destination=41.403629,2.189512&travelmode=driving'
    );
    assertRouteCondition($stop->getProgressRatio() === 0.5, 'RouteStop calculates partial task progress');
    assertRouteCondition($stop->isCritical(), 'RouteStop preserves inherited critical priority');
    assertRouteCondition($stop->toArray()['status'] === 'IN_PROGRESS', 'RouteStop serializes enum values in API format');
    assertRouteCondition(
        json_decode(json_encode($stop, JSON_THROW_ON_ERROR), true)['priority'] === 'CRITICAL',
        'RouteStop serializes to JSON with scalar enum values'
    );
    assertRouteCondition((new ReflectionClass(RouteStop::class))->isReadOnly(), 'RouteStop is immutable');

    assertRouteThrows(
        fn() => new RouteStop(0, $location, StopStatus::PENDING, StopPriority::ORDINARY, false, 1, 0, [['id' => 1]], 0.0, 'https://maps.example/stop'),
        'RouteStop rejects an invalid sequence number'
    );
    assertRouteThrows(
        fn() => new RouteStop(1, $location, StopStatus::PENDING, StopPriority::ORDINARY, false, 1, 0, [['id' => 1]], 0.0, ''),
        'RouteStop requires an individual navigation URL'
    );
    assertRouteThrows(
        fn() => new RouteStop(1, $location, StopStatus::PENDING, StopPriority::CRITICAL, false, 1, 0, [['id' => 1]], 0.0, ''),
        'RouteStop rejects inconsistent critical priority'
    );
    assertRouteThrows(
        fn() => new RouteStop(1, $location, StopStatus::COMPLETED, StopPriority::ORDINARY, false, 2, 1, [['id' => 1], ['id' => 2]], 0.0, ''),
        'RouteStop rejects completed status while tasks remain'
    );
    assertRouteThrows(
        fn() => new RouteStop(1, $location, StopStatus::PENDING, StopPriority::ORDINARY, false, 2, 0, [['id' => 1]], 0.0, ''),
        'RouteStop rejects task-count mismatch'
    );
    assertRouteThrows(
        fn() => new RouteStop(1, $location, StopStatus::PENDING, StopPriority::ORDINARY, false, 2, 3, [['id' => 1], ['id' => 2]], 0.0, ''),
        'RouteStop rejects completed-task counts greater than total tasks'
    );

    $secondStop = new RouteStop(
        2,
        $legacyLocation,
        StopStatus::PENDING,
        StopPriority::ORDINARY,
        false,
        1,
        0,
        [['type' => 'CORRECTIVE', 'id' => 102]],
        3.15,
        'https://www.google.com/maps/dir/?api=1&destination=41.385064,2.1734035&travelmode=driving'
    );
    $route = new TechnicianRouteMap(
        ['source' => 'GPS', 'latitude' => 41.3887901, 'longitude' => 2.1589920, 'address' => 'GPS'],
        [$stop, $secondStop],
        'https://www.google.com/maps/dir/?api=1',
        false
    );
    assertRouteCondition($route->getTotalStops() === 2, 'TechnicianRouteMap counts stops');
    assertRouteCondition($route->getTotalTasks() === 3, 'TechnicianRouteMap sums tasks');
    assertRouteCondition($route->getTotalCritical() === 1, 'TechnicianRouteMap counts critical stops');
    assertRouteCondition($route->getEstimatedTotalDistanceKm() === 6.0, 'TechnicianRouteMap sums stop distances');
    assertRouteCondition($route->toArray()['stops'][0]['order'] === 1, 'TechnicianRouteMap serializes ordered stops');
    assertRouteCondition(
        json_decode(json_encode($route, JSON_THROW_ON_ERROR), true)['summary']['total_tasks'] === 3,
        'TechnicianRouteMap serializes its summary to JSON'
    );
    assertRouteCondition((new ReflectionClass(TechnicianRouteMap::class))->isReadOnly(), 'TechnicianRouteMap is immutable');

    assertRouteThrows(
        fn() => new TechnicianRouteMap(['latitude' => 0.0, 'longitude' => 0.0], [$stop], 'https://maps.example/route', false),
        'TechnicianRouteMap rejects invalid origin coordinates'
    );
    assertRouteThrows(
        fn() => new TechnicianRouteMap(['latitude' => 41.0, 'longitude' => 2.0], [$stop], '', false),
        'TechnicianRouteMap requires a full-route navigation URL when stops exist'
    );
    assertRouteThrows(
        fn() => new TechnicianRouteMap(['latitude' => 41.0, 'longitude' => 2.0], [$secondStop, $stop], '', false),
        'TechnicianRouteMap rejects a non-sequential stop ordering'
    );

    $settings = new RouteSettings('Base VendGuard', 'Carrer de la Marina 100');
    assertRouteCondition($settings->isInsideOperationalArea(27.0, -18.5), 'RouteSettings includes operational-area boundary coordinates');
    assertRouteCondition(!$settings->isInsideOperationalArea(26.9, -18.5), 'RouteSettings rejects coordinates outside the operational area');
    assertRouteCondition($settings->getOperationalRadiusKm() === 100, 'RouteSettings uses the specified default operational radius');
    assertRouteThrows(
        fn() => new RouteSettings('Base inválida', 'Fuera del territorio', 0.0, 0.0),
        'RouteSettings rejects an out-of-area base position'
    );
    assertRouteCondition((new ReflectionClass(RouteSettings::class))->isReadOnly(), 'RouteSettings is immutable');

    echo "Route domain model tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Route domain model test failed: {$exception->getMessage()}\n");
    exit(1);
}
