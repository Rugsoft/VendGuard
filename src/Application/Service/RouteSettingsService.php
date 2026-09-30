<?php
declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;

/**
 * Coordinates central-base configuration reads and validated updates.
 */
final class RouteSettingsService
{
    public function __construct(
        private readonly RouteSettingsRepositoryInterface $settingsRepository,
        private readonly GeoDistanceService $geoDistanceService
    ) {
    }

    /**
     * Retrieves the current central-base configuration, or null if it has not been initialized.
     */
    public function getSettings(): ?RouteSettings
    {
        return $this->settingsRepository->find();
    }

    /**
     * Validates and persists the central-base settings while retaining the configured territory limits.
     *
     * @throws InvalidArgumentException If coordinates are outside the operational territory or settings are absent.
     */
    public function updateSettings(
        string $baseName,
        string $baseAddress,
        float $baseLatitude,
        float $baseLongitude,
        int $operationalRadiusKm
    ): bool {
        $currentSettings = $this->settingsRepository->find();
        if ($currentSettings === null) {
            throw new InvalidArgumentException('La configuración de la Base Central no está inicializada.');
        }

        if (!$this->geoDistanceService->isInsideOperationalArea($baseLatitude, $baseLongitude)) {
            throw new InvalidArgumentException('La Base Central debe encontrarse dentro del territorio operativo.');
        }

        $updatedSettings = new RouteSettings(
            $baseName,
            $baseAddress,
            $baseLatitude,
            $baseLongitude,
            $operationalRadiusKm,
            $currentSettings->getMinLatitude(),
            $currentSettings->getMaxLatitude(),
            $currentSettings->getMinLongitude(),
            $currentSettings->getMaxLongitude()
        );

        return $this->settingsRepository->update($updatedSettings);
    }
}
