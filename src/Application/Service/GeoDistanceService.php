<?php
declare(strict_types=1);

namespace VendGuard\Application\Service;

/**
 * Calculates geodesic distances and validates coordinates against the operational area.
 */
final class GeoDistanceService
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const MIN_LATITUDE = 27.0;
    private const MAX_LATITUDE = 44.5;
    private const MIN_LONGITUDE = -18.5;
    private const MAX_LONGITUDE = 5.0;

    /**
     * Calculates the great-circle distance between two WGS84 coordinates using Haversine.
     */
    public function calculateDistanceKm(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        return round($this->calculatePreciseDistanceKm($latitude1, $longitude1, $latitude2, $longitude2), 2);
    }

    /**
     * Calculates the unrounded great-circle distance for accurate route comparisons.
     */
    public function calculatePreciseDistanceKm(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {
        $latitudeDelta = deg2rad($latitude2 - $latitude1);
        $longitudeDelta = deg2rad($longitude2 - $longitude1);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($longitudeDelta / 2) ** 2;
        $a = min(1.0, max(0.0, $a));
        $centralAngle = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $centralAngle;
    }

    /**
     * Checks coordinate ranges, rejects null-island, and enforces the operational bounding box.
     */
    public function isInsideOperationalArea(float $latitude, float $longitude): bool
    {
        return is_finite($latitude)
            && is_finite($longitude)
            && $latitude >= -90.0
            && $latitude <= 90.0
            && $longitude >= -180.0
            && $longitude <= 180.0
            && !($latitude === 0.0 && $longitude === 0.0)
            && $latitude >= self::MIN_LATITUDE
            && $latitude <= self::MAX_LATITUDE
            && $longitude >= self::MIN_LONGITUDE
            && $longitude <= self::MAX_LONGITUDE;
    }
}
