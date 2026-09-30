<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoRouteSettingsRepository;

$pdo = ConnectionFactory::getConnection();
$failures = 0;
$assert = static function (string $caseTitle, bool $condition) use (&$failures): void {
    echo $condition ? "  [PASS] {$caseTitle}\n" : "  [FAIL] {$caseTitle}\n";
    if (!$condition) {
        $failures++;
    }
};

try {
    $pdo->beginTransaction();

    $settingsRepository = new PdoRouteSettingsRepository($pdo);
    $settings = $settingsRepository->find();
    $assert('find() hydrates the singleton route settings row', $settings instanceof RouteSettings);

    if ($settings instanceof RouteSettings) {
        $updatedSettings = new RouteSettings(
            'Test Central VendGuard',
            'Carrer de Prova 10, Barcelona',
            41.3972000,
            2.1883000,
            80,
            $settings->getMinLatitude(),
            $settings->getMaxLatitude(),
            $settings->getMinLongitude(),
            $settings->getMaxLongitude()
        );
        $assert('update() updates the singleton settings row', $settingsRepository->update($updatedSettings));

        $persistedSettings = $settingsRepository->find();
        $assert(
            'find() returns the updated base data and operational limits',
            $persistedSettings instanceof RouteSettings
                && $persistedSettings->getBaseName() === 'Test Central VendGuard'
                && $persistedSettings->getBaseAddress() === 'Carrer de Prova 10, Barcelona'
                && abs($persistedSettings->getBaseLatitude() - 41.3972000) < 0.0000001
                && abs($persistedSettings->getBaseLongitude() - 2.1883000) < 0.0000001
                && $persistedSettings->getOperationalRadiusKm() === 80
                && $persistedSettings->getMinLatitude() === $settings->getMinLatitude()
                && $persistedSettings->getMaxLatitude() === $settings->getMaxLatitude()
                && $persistedSettings->getMinLongitude() === $settings->getMinLongitude()
                && $persistedSettings->getMaxLongitude() === $settings->getMaxLongitude()
        );
    }

    $locationRepository = new PdoLocationRepository($pdo);
    $siteCode = 'T-MAP-04-' . strtoupper(bin2hex(random_bytes(4)));
    $createdLocation = $locationRepository->create([
        'site_code' => $siteCode,
        'name' => 'Route map repository test location',
        'address' => 'Carrer de Prova 20, Barcelona',
        'latitude' => 41.4025000,
        'longitude' => 2.1740000,
    ]);
    $assert('create() persists the supplied location coordinates',
        abs($createdLocation->getLatitude() - 41.4025000) < 0.0000001
        && abs($createdLocation->getLongitude() - 2.1740000) < 0.0000001
    );

    $legacyLocation = $locationRepository->create([
        'site_code' => 'T-MAP-04-LEGACY-' . strtoupper(bin2hex(random_bytes(4))),
        'name' => 'Legacy caller test location',
        'address' => 'Carrer de Prova 30, Barcelona',
    ]);
    $assert('create() preserves coordinate defaults for legacy callers',
        abs($legacyLocation->getLatitude() - 41.3850640) < 0.0000001
        && abs($legacyLocation->getLongitude() - 2.1734035) < 0.0000001
    );

    $locationRepository->update($createdLocation->getId(), [
        'latitude' => 41.4036290,
        'longitude' => 2.1895120,
    ]);

    $byId = $locationRepository->findById($createdLocation->getId());
    $bySiteCode = $locationRepository->findBySiteCode($siteCode);
    $activeLocations = $locationRepository->findAllActive();
    $structuredLocations = $locationRepository->findAll('all', $siteCode);
    $activeMatch = array_values(array_filter(
        $activeLocations,
        static fn(Location $location): bool => $location->getSiteCode() === $siteCode
    ));
    $expectedCoordinates = static fn(?Location $location): bool => $location instanceof Location
        && abs($location->getLatitude() - 41.4036290) < 0.0000001
        && abs($location->getLongitude() - 2.1895120) < 0.0000001;

    $assert('findById() recovers updated location coordinates', $expectedCoordinates($byId));
    $assert('findBySiteCode() recovers updated location coordinates', $expectedCoordinates($bySiteCode));
    $assert('findAllActive() recovers updated location coordinates', isset($activeMatch[0]) && $expectedCoordinates($activeMatch[0]));
    $assert(
        'findAll() includes numeric latitude and longitude',
        isset($structuredLocations[0]['latitude'], $structuredLocations[0]['longitude'])
            && abs((float)$structuredLocations[0]['latitude'] - 41.4036290) < 0.0000001
            && abs((float)$structuredLocations[0]['longitude'] - 2.1895120) < 0.0000001
    );
} catch (Throwable $exception) {
    $failures++;
    echo '  [FAIL] Unexpected test exception: ' . $exception->getMessage() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n";
if ($failures === 0) {
    echo "RESULT: 100% GREEN. T-MAP-04 persistence conditions met.\n";
    exit(0);
}

echo "RESULT: {$failures} CHECK(S) FAILED.\n";
exit(1);
