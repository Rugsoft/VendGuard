<?php

declare(strict_types=1);

/**
 * RouteMapMigrationContractTest
 *
 * Verifies the route-map migration and seed contract without modifying a database.
 */

require_once __DIR__ . '/../bootstrap.php';

$baseDir = dirname(__DIR__, 2);
$migrationPath = $baseDir . '/database/migrations/007_field_logistics_map.sql';
$cloudInitPath = $baseDir . '/database/cloud_init.sql';
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

$migration = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
$cloudInit = is_file($cloudInitPath) ? (string)file_get_contents($cloudInitPath) : '';
$seedRunner = is_file($seedRunnerPath) ? (string)file_get_contents($seedRunnerPath) : '';

$assert('Migration 007 exists and uses strict SQL idempotency guards for coordinate columns',
    $migration !== ''
    && substr_count($migration, 'information_schema.columns') >= 2
    && str_contains($migration, "column_name = 'latitude'")
    && str_contains($migration, "column_name = 'longitude'"));
$assert('Migration 007 conditionally creates the location coordinate index',
    str_contains($migration, 'information_schema.statistics')
    && str_contains($migration, "index_name = 'idx_locations_lat_lng'")
    && str_contains($migration, 'CREATE INDEX `idx_locations_lat_lng`'));
$assert('Migration 007 creates route_settings idempotently and preserves the singleton row',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS `route_settings`')
    && str_contains($migration, 'ON DUPLICATE KEY UPDATE `id` = VALUES(`id`)'));

$seedCoordinates = [
    'SEDE-BCN-01' => [41.3853120, 2.1932450],
    'SEDE-BCN-02' => [41.4036290, 2.1895120],
];
foreach ($seedCoordinates as $siteCode => [$latitude, $longitude]) {
    $coordinatesAreValid = $latitude >= 27.0 && $latitude <= 44.5
        && $longitude >= -18.5 && $longitude <= 5.0
        && !($latitude === 0.0 && $longitude === 0.0);
    $assert("{$siteCode} seed coordinates are inside the approved operational bounds", $coordinatesAreValid);
    $assert("Migration 007 backfills {$siteCode} with its canonical coordinates",
        str_contains($migration, "'{$siteCode}'")
        && str_contains($migration, number_format($latitude, 7, '.', ''))
        && str_contains($migration, number_format($longitude, 7, '.', '')));
    $assert("cloud_init seeds {$siteCode} with its canonical coordinates",
        str_contains($cloudInit, "'{$siteCode}'")
        && str_contains($cloudInit, number_format($latitude, 7, '.', ''))
        && str_contains($cloudInit, number_format($longitude, 7, '.', '')));
    $assert("SeedRunner carries {$siteCode} coordinates in its location seeds",
        str_contains($seedRunner, "'site_code' => '{$siteCode}'")
        && str_contains($seedRunner, "'latitude' => " . number_format($latitude, 7, '.', ''))
        && str_contains($seedRunner, "'longitude' => " . number_format($longitude, 7, '.', '')));
}

$assert('cloud_init defines coordinate columns and index in locations',
    preg_match('/CREATE TABLE `locations`\s*\([\s\S]*?`latitude` DECIMAL\(10, 7\)[\s\S]*?`longitude` DECIMAL\(11, 7\)[\s\S]*?INDEX `idx_locations_lat_lng`/', $cloudInit) === 1);
$assert('cloud_init creates and seeds the central route settings singleton',
    str_contains($cloudInit, 'CREATE TABLE IF NOT EXISTS `route_settings`')
    && preg_match('/INSERT INTO `route_settings`\s*\([\s\S]*?VALUES\s*\(1,/', $cloudInit) === 1);
$assert('cloud_init seeds canonical Barcelona coordinates and the central base',
    str_contains($cloudInit, '41.3853120, 2.1932450')
    && str_contains($cloudInit, '41.4036290, 2.1895120')
    && str_contains($cloudInit, '41.3935000, 2.1890000'));
$assert('SeedRunner persists coordinates and initializes route settings as part of seedAll',
    str_contains($seedRunner, "`latitude`, `longitude`")
    && str_contains($seedRunner, 'public function seedRouteSettings(): int')
    && str_contains($seedRunner, '$this->seedRouteSettings();'));

printf("Total assertions: %d | Failures: %d\n", $assertions, $failures);
exit($failures === 0 ? 0 : 1);
