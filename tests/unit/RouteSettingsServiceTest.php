<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteSettingsService;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;

$assertions = 0;

function assertRouteSettingsCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertRouteSettingsThrows(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        assertRouteSettingsCondition(true, $message);
        return;
    }

    assertRouteSettingsCondition(false, $message);
}

final class InMemoryRouteSettingsRepository implements RouteSettingsRepositoryInterface
{
    public int $findCalls = 0;
    public int $updateCalls = 0;
    public ?RouteSettings $settings;
    public bool $updateResult = true;

    public function __construct(?RouteSettings $settings)
    {
        $this->settings = $settings;
    }

    public function find(): ?RouteSettings
    {
        $this->findCalls++;
        return $this->settings;
    }

    public function update(RouteSettings $settings): bool
    {
        $this->updateCalls++;
        if ($this->updateResult) {
            $this->settings = $settings;
        }
        return $this->updateResult;
    }
}

try {
    $initial = new RouteSettings();
    $repository = new InMemoryRouteSettingsRepository($initial);
    $service = new RouteSettingsService($repository, new GeoDistanceService());

    assertRouteSettingsCondition($service->getSettings() === $initial, 'getSettings() returns the singleton configuration from the repository');
    assertRouteSettingsCondition($repository->findCalls === 1, 'getSettings() reads from the repository once');

    $updated = $service->updateSettings(
        'Taller y Base Central VendGuard',
        'Carrer dels Almogàvers 120, Barcelona',
        41.3972000,
        2.1883000,
        80
    );
    assertRouteSettingsCondition($updated, 'updateSettings() persists valid central-base settings');
    assertRouteSettingsCondition($repository->updateCalls === 1, 'updateSettings() writes to the repository once');
    assertRouteSettingsCondition(
        $repository->settings instanceof RouteSettings
            && $repository->settings->getBaseName() === 'Taller y Base Central VendGuard'
            && $repository->settings->getBaseAddress() === 'Carrer dels Almogàvers 120, Barcelona'
            && abs($repository->settings->getBaseLatitude() - 41.3972000) < 0.0000001
            && abs($repository->settings->getBaseLongitude() - 2.1883000) < 0.0000001
            && $repository->settings->getOperationalRadiusKm() === 80,
        'updated settings contain the submitted base data'
    );
    assertRouteSettingsCondition(
        $repository->settings instanceof RouteSettings
            && $repository->settings->getMinLatitude() === $initial->getMinLatitude()
            && $repository->settings->getMaxLatitude() === $initial->getMaxLatitude()
            && $repository->settings->getMinLongitude() === $initial->getMinLongitude()
            && $repository->settings->getMaxLongitude() === $initial->getMaxLongitude(),
        'updateSettings() preserves the configured operational boundaries'
    );

    $beforeInvalidUpdate = $repository->updateCalls;
    assertRouteSettingsThrows(
        fn() => $service->updateSettings('Base', 'Dirección', 26.9, 2.0, 80),
        'updateSettings() rejects a base latitude outside the operational area'
    );
    assertRouteSettingsThrows(
        fn() => $service->updateSettings('Base', 'Dirección', 41.0, 5.1, 80),
        'updateSettings() rejects a base longitude outside the operational area'
    );
    assertRouteSettingsThrows(
        fn() => $service->updateSettings('Base', 'Dirección', 0.0, 0.0, 80),
        'updateSettings() rejects null-island coordinates'
    );
    assertRouteSettingsCondition($repository->updateCalls === $beforeInvalidUpdate, 'invalid coordinates never reach the repository');

    $missingRepository = new InMemoryRouteSettingsRepository(null);
    $missingService = new RouteSettingsService($missingRepository, new GeoDistanceService());
    assertRouteSettingsCondition($missingService->getSettings() === null, 'getSettings() returns null when the singleton row is absent');
    assertRouteSettingsThrows(
        fn() => $missingService->updateSettings('Base', 'Dirección', 41.0, 2.0, 100),
        'updateSettings() refuses to write when the singleton settings row is absent'
    );
    assertRouteSettingsCondition($missingRepository->updateCalls === 0, 'missing singleton configuration is not recreated implicitly');

    echo "RouteSettingsService tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "RouteSettingsService test failed: {$exception->getMessage()}\n");
    exit(1);
}
