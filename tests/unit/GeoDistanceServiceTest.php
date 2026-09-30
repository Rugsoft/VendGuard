<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;

$assertions = 0;

function assertGeoCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $service = new GeoDistanceService();

    $knownDistance = $service->calculateDistanceKm(41.3853120, 2.1932450, 41.4036290, 2.1895120);
    assertGeoCondition(abs($knownDistance - 2.05) <= 0.05, 'Haversine matches the known Hospital del Mar to Torre Glòries distance');

    assertGeoCondition(
        $service->calculateDistanceKm(41.3853120, 2.1932450, 41.3853120, 2.1932450) === 0.0,
        'Haversine returns zero for identical coordinates'
    );
    assertGeoCondition(
        $service->calculateDistanceKm(41.3853120, 2.1932450, 41.4036290, 2.1895120)
            === $service->calculateDistanceKm(41.4036290, 2.1895120, 41.3853120, 2.1932450),
        'Haversine distance is symmetric'
    );

    assertGeoCondition($service->isInsideOperationalArea(27.0, -18.5), 'Operational-area minimum boundary is included');
    assertGeoCondition($service->isInsideOperationalArea(44.5, 5.0), 'Operational-area maximum boundary is included');
    assertGeoCondition($service->isInsideOperationalArea(41.3853120, 2.1932450), 'Valid Barcelona coordinates are accepted');
    assertGeoCondition(!$service->isInsideOperationalArea(26.9999, 2.0), 'Latitude below the operational range is rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(44.5001, 2.0), 'Latitude above the operational range is rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(41.0, -18.5001), 'Longitude below the operational range is rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(41.0, 5.0001), 'Longitude above the operational range is rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(0.0, 0.0), 'Null-island coordinates are rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(2.1932450, 41.3853120), 'Coordinates with swapped axes are rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(NAN, 2.0), 'Non-finite latitude is rejected');
    assertGeoCondition(!$service->isInsideOperationalArea(41.0, INF), 'Non-finite longitude is rejected');

    echo "GeoDistanceService tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "GeoDistanceService test failed: {$exception->getMessage()}\n");
    exit(1);
}
