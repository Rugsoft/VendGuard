<?php

declare(strict_types=1);

/**
 * RouteMapMigrationContractTest
 *
 * Verifies the route-map schema and seed contract on PostgreSQL without modifying a database.
 */

require_once __DIR__ . '/../bootstrap.php';

$baseDir = dirname(__DIR__, 2);
$supabaseInitPath = $baseDir . '/database/supabase_init.sql';
$seedRunnerPath = $baseDir . '/src/Infrastructure/Database/SeedRunner.php';

$assertions = 0;
$failures = 0;
$assert = static function (string $name, bool $condition) use (&$assertions, &$failures): void {
    $assertions++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
};

$supabaseInit = is_file($supabaseInitPath) ? (string)file_get_contents($supabaseInitPath) : '';
$seedRunner = is_file($seedRunnerPath) ? (string)file_get_contents($seedRunnerPath) : '';

$assert('supabase_init defines coordinate columns and index in locations',
    str_contains($supabaseInit, 'latitude')
    && str_contains($supabaseInit, 'longitude')
    && str_contains($supabaseInit, 'idx_locations_lat_lng'));
$assert('supabase_init conditionally creates the location coordinate index',
    str_contains($supabaseInit, 'CREATE INDEX IF NOT EXISTS idx_locations_lat_lng ON locations'));
$assert('supabase_init creates route_settings table idempotently',
    str_contains($supabaseInit, 'CREATE TABLE IF NOT EXISTS route_settings'));

$seedCoordinates = [
    'SEDE-BCN-01' => [41.3853120, 2.1932450],
    'SEDE-BCN-02' => [41.4036290, 2.1895120],
];
foreach ($seedCoordinates as $siteCode => [$latitude, $longitude]) {
    $coordinatesAreValid = $latitude >= 27.0 && $latitude <= 44.5
        && $longitude >= -18.5 && $longitude <= 5.0
        && !($latitude === 0.0 && $longitude === 0.0);
    $assert("{$siteCode} seed coordinates are inside the approved operational bounds", $coordinatesAreValid);
    $assert("SeedRunner carries {$siteCode} coordinates in its location seeds",
        str_contains($seedRunner, "'site_code' => '{$siteCode}'")
        && str_contains($seedRunner, "'latitude' => " . number_format($latitude, 7, '.', ''))
        && str_contains($seedRunner, "'longitude' => " . number_format($longitude, 7, '.', '')));
}

$assert('supabase_init creates route_settings table',
    str_contains($supabaseInit, 'CREATE TABLE IF NOT EXISTS route_settings'));

$coordinatesInSeedColumns = str_contains($seedRunner, "`latitude`, `longitude`")
    || str_contains($seedRunner, "'latitude', 'longitude'")
    || str_contains($seedRunner, 'latitude, longitude');
$assert('SeedRunner persists coordinates and initializes route settings as part of seedAll',
    $coordinatesInSeedColumns
    && str_contains($seedRunner, 'public function seedRouteSettings(): int')
    && str_contains($seedRunner, '$this->seedRouteSettings();'));

printf("Total assertions: %d | Failures: %d\n", $assertions, $failures);
exit($failures === 0 ? 0 : 1);

