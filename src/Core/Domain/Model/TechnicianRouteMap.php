<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable ordered route map for one technician's working day.
 */
final readonly class TechnicianRouteMap implements JsonSerializable
{
    private int $totalStops;
    private int $totalTasks;
    private int $totalCritical;
    private float $estimatedTotalDistanceKm;

    /**
     * @param array<string, mixed> $origin
     * @param RouteStop[] $stops
     */
    public function __construct(
        private array $origin,
        private array $stops,
        private string $fullRouteNavigationUrl,
        private bool $waypointsTruncated
    ) {
        $latitude = $origin['latitude'] ?? null;
        $longitude = $origin['longitude'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new InvalidArgumentException('El origen de ruta requiere coordenadas numéricas.');
        }

        $latitude = (float)$latitude;
        $longitude = (float)$longitude;
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90.0 || $latitude > 90.0
            || $longitude < -180.0 || $longitude > 180.0
            || $latitude < 27.0 || $latitude > 44.5
            || $longitude < -18.5 || $longitude > 5.0
            || ($latitude === 0.0 && $longitude === 0.0)) {
            throw new InvalidArgumentException('Las coordenadas del origen de ruta no son válidas.');
        }

        if (!array_is_list($stops)) {
            throw new InvalidArgumentException('Las paradas de ruta deben recibirse en una lista ordenada.');
        }

        if ($stops !== [] && trim($fullRouteNavigationUrl) === '') {
            throw new InvalidArgumentException('La ruta con paradas requiere una URL de navegación completa.');
        }

        $totalTasks = 0;
        $totalCritical = 0;
        $estimatedDistance = 0.0;
        foreach ($stops as $index => $stop) {
            if (!$stop instanceof RouteStop || $stop->getOrder() !== $index + 1) {
                throw new InvalidArgumentException('Las paradas deben ser RouteStop y seguir un orden correlativo.');
            }

            $totalTasks += $stop->getTotalTasks();
            $totalCritical += $stop->isCritical() ? 1 : 0;
            $estimatedDistance += $stop->getDistanceFromPreviousKm();
        }

        $this->totalStops = count($stops);
        $this->totalTasks = $totalTasks;
        $this->totalCritical = $totalCritical;
        $this->estimatedTotalDistanceKm = round($estimatedDistance, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrigin(): array
    {
        return $this->origin;
    }

    /**
     * @return RouteStop[]
     */
    public function getStops(): array
    {
        return $this->stops;
    }

    public function getFullRouteNavigationUrl(): string
    {
        return $this->fullRouteNavigationUrl;
    }

    public function areWaypointsTruncated(): bool
    {
        return $this->waypointsTruncated;
    }

    public function getTotalStops(): int
    {
        return $this->totalStops;
    }

    public function getTotalTasks(): int
    {
        return $this->totalTasks;
    }

    public function getTotalCritical(): int
    {
        return $this->totalCritical;
    }

    public function getEstimatedTotalDistanceKm(): float
    {
        return $this->estimatedTotalDistanceKm;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin,
            'stops' => array_map(static fn(RouteStop $stop): array => $stop->toArray(), $this->stops),
            'full_route_navigation_url' => $this->fullRouteNavigationUrl,
            'waypoints_truncated' => $this->waypointsTruncated,
            'summary' => [
                'total_stops' => $this->totalStops,
                'total_tasks' => $this->totalTasks,
                'total_critical' => $this->totalCritical,
                'estimated_total_distance_km' => $this->estimatedTotalDistanceKm,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
