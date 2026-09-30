<?php
declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\RouteStop;
use VendGuard\Core\Domain\Model\StopPriority;
use VendGuard\Core\Domain\Model\StopStatus;
use VendGuard\Core\Domain\Model\TechnicianRouteMap;

/**
 * Builds a deterministic technician route from assigned corrective and preventive tasks.
 */
final class RouteOptimizationService
{
    private const CRITICAL_SLA_HOURS = 4;
    private const MAX_GOOGLE_MAPS_WAYPOINTS = 9;

    public function __construct(private readonly GeoDistanceService $geoDistanceService)
    {
    }

    /**
     * Groups work by physical location, applies the hybrid priority sequence, and constructs navigation links.
     *
     * @param array<array<string, mixed>> $tasks Assigned tasks with a Location under the `location` key.
     * @param array<string, mixed>|null $origin Optional GPS origin with latitude and longitude.
     */
    public function optimizeRoute(
        array $tasks,
        ?array $origin,
        RouteSettings $settings,
        ?DateTimeInterface $referenceTime = null
    ): TechnicianRouteMap {
        $referenceTime = $referenceTime ?? new DateTimeImmutable('now');
        $originData = $this->resolveOrigin($origin, $settings);
        $groups = $this->groupTasks($tasks);
        $stops = [];

        foreach ($groups as $group) {
            $summary = $this->summarizeGroup($group, $referenceTime);
            $stops[] = $summary;
        }

        $sequence = $this->sequenceStops($stops, $originData);
        $routeStops = [];
        $previousPoint = [$originData['latitude'], $originData['longitude']];

        foreach ($sequence as $index => $stopData) {
            /** @var Location $location */
            $location = $stopData['location'];
            $latitude = $location->getLatitude();
            $longitude = $location->getLongitude();
            $distance = $this->geoDistanceService->calculateDistanceKm(
                $previousPoint[0],
                $previousPoint[1],
                $latitude,
                $longitude
            );

            $routeStops[] = new RouteStop(
                $index + 1,
                $location,
                $stopData['status'],
                $stopData['priority'],
                $stopData['priority'] === StopPriority::CRITICAL,
                count($stopData['tasks']),
                $stopData['completed_tasks'],
                $stopData['tasks'],
                $distance,
                $this->individualNavigationUrl($latitude, $longitude)
            );
            $previousPoint = [$latitude, $longitude];
        }

        [$fullRouteUrl, $waypointsTruncated] = $this->fullRouteNavigationUrl($originData, $routeStops);

        return new TechnicianRouteMap($originData, $routeStops, $fullRouteUrl, $waypointsTruncated);
    }

    /**
     * @param array<array<string, mixed>> $tasks
     * @return array<string, array{location: Location, tasks: array<array<string, mixed>>}>
     */
    private function groupTasks(array $tasks): array
    {
        $groups = [];
        foreach ($tasks as $task) {
            $status = strtoupper(trim((string)($task['status'] ?? '')));
            if ($status === 'PENDING_PARTS') {
                continue;
            }

            $location = $task['location'] ?? null;
            if (!$location instanceof Location) {
                throw new InvalidArgumentException('Cada tarea de ruta debe incluir una sede Location válida.');
            }
            if (!$location->hasValidCoordinates()) {
                throw new InvalidArgumentException('Las tareas de ruta requieren coordenadas de sede válidas.');
            }

            $locationId = $location->getId();
            if (!isset($groups[$locationId])) {
                $groups[$locationId] = ['location' => $location, 'tasks' => []];
            }
            $groups[$locationId]['tasks'][] = $task;
        }

        return $groups;
    }

    /**
     * @param array{location: Location, tasks: array<array<string, mixed>>} $group
     * @return array{location: Location, tasks: array<array<string, mixed>>, completed_tasks: int, status: StopStatus, priority: StopPriority, sla_due_at: ?DateTimeImmutable, created_at: ?DateTimeImmutable}
     */
    private function summarizeGroup(array $group, DateTimeInterface $referenceTime): array
    {
        $completedTasks = 0;
        $hasInProgress = false;
        $isCritical = false;
        $criticalDeadlines = [];
        $creationTimes = [];
        $pendingTasks = [];

        foreach ($group['tasks'] as $task) {
            $status = strtoupper(trim((string)($task['status'] ?? '')));
            if ($this->isCompletedTask($status)) {
                $completedTasks++;
                $createdAt = $this->parseDate($task['created_at'] ?? null);
                if ($createdAt !== null) {
                    $creationTimes[] = $createdAt;
                }
                continue;
            }
            if ($status === 'IN_PROGRESS') {
                $hasInProgress = true;
            }
            $pendingTasks[] = $task;
            $createdAt = $this->parseDate($task['created_at'] ?? null);
            if ($createdAt !== null) {
                $creationTimes[] = $createdAt;
            }

            $machineType = strtoupper(trim((string)($task['machine_type'] ?? '')));
            $urgency = strtoupper(trim((string)($task['urgency'] ?? '')));
            $slaDueAt = $this->parseDate($task['sla_due_at'] ?? null);
            $isPerishableCritical = $machineType === 'PERISHABLE_FOOD'
                && ($urgency === 'CRITICAL' || (bool)($task['is_critical'] ?? false));

            if ($isPerishableCritical && $slaDueAt !== null) {
                $hoursRemaining = ((float)$slaDueAt->format('U.u') - (float)$referenceTime->format('U.u')) / 3600;
                if ($hoursRemaining < self::CRITICAL_SLA_HOURS) {
                    $isCritical = true;
                    $criticalDeadlines[] = $slaDueAt;
                }
            }
        }

        $earliestSla = $criticalDeadlines === [] ? null : min($criticalDeadlines);
        $earliestCreation = $creationTimes === [] ? null : min($creationTimes);
        if ($pendingTasks === []) {
            return [
                'location' => $group['location'],
                'tasks' => $group['tasks'],
                'completed_tasks' => $completedTasks,
                'status' => StopStatus::COMPLETED,
                'pending_tasks' => [],
                'priority' => StopPriority::ORDINARY,
                'sla_due_at' => null,
                'created_at' => $earliestCreation,
            ];
        }

        return [
            'location' => $group['location'],
            'tasks' => $group['tasks'],
            'completed_tasks' => $completedTasks,
            'pending_tasks' => $pendingTasks,
            'status' => $hasInProgress ? StopStatus::IN_PROGRESS : StopStatus::PENDING,
            'priority' => $isCritical ? StopPriority::CRITICAL : StopPriority::ORDINARY,
            'sla_due_at' => $earliestSla,
            'created_at' => $earliestCreation,
        ];
    }

    /**
     * @param array<array<string, mixed>> $stops
     * @param array<string, mixed> $origin
     * @return array<array<string, mixed>>
     */
    private function sequenceStops(array $stops, array $origin): array
    {
        if ($stops === []) {
            return [];
        }

        $inProgress = [];
        $critical = [];
        $ordinary = [];
        foreach ($stops as $stop) {
            if ($stop['status'] === StopStatus::IN_PROGRESS) {
                $inProgress[] = $stop;
            } elseif ($stop['priority'] === StopPriority::CRITICAL) {
                $critical[] = $stop;
            } else {
                $ordinary[] = $stop;
            }
        }

        $ordered = [];
        if ($inProgress !== []) {
            usort($inProgress, fn(array $left, array $right): int => $this->compareStableStops($left, $right));
            $ordered[] = array_shift($inProgress);
            foreach ($inProgress as $stop) {
                if ($stop['priority'] === StopPriority::CRITICAL) {
                    $critical[] = $stop;
                } else {
                    $ordinary[] = $stop;
                }
            }
        }

        usort($critical, static function (array $left, array $right): int {
            $leftDeadline = (float)$left['sla_due_at']->format('U.u');
            $rightDeadline = (float)$right['sla_due_at']->format('U.u');
            $deadlineComparison = $leftDeadline <=> $rightDeadline;
            return $deadlineComparison !== 0 ? $deadlineComparison : self::compareAgeAndLocation($left, $right);
        });
        array_push($ordered, ...$critical);

        $currentLatitude = $ordered !== []
            ? $ordered[array_key_last($ordered)]['location']->getLatitude()
            : (float)$origin['latitude'];
        $currentLongitude = $ordered !== []
            ? $ordered[array_key_last($ordered)]['location']->getLongitude()
            : (float)$origin['longitude'];

        while ($ordinary !== []) {
            $nearestIndex = null;
            $nearestDistance = INF;
            foreach ($ordinary as $index => $candidate) {
                /** @var Location $location */
                $location = $candidate['location'];
                $distance = $this->geoDistanceService->calculatePreciseDistanceKm(
                    $currentLatitude,
                    $currentLongitude,
                    $location->getLatitude(),
                    $location->getLongitude()
                );
                if ($distance < $nearestDistance
                    || ($distance === $nearestDistance && $nearestIndex !== null
                        && $this->compareStableStops($candidate, $ordinary[$nearestIndex]) < 0)) {
                    $nearestDistance = $distance;
                    $nearestIndex = $index;
                }
            }

            if ($nearestIndex === null) {
                break;
            }
            $next = $ordinary[$nearestIndex];
            $ordered[] = $next;
            $currentLatitude = $next['location']->getLatitude();
            $currentLongitude = $next['location']->getLongitude();
            array_splice($ordinary, $nearestIndex, 1);
        }

        return $ordered;
    }

    /**
     * Resolves valid GPS coordinates or the configured central-base fallback.
     *
     * @param array<string, mixed>|null $origin
     * @return array<string, mixed>
     */
    private function resolveOrigin(?array $origin, RouteSettings $settings): array
    {
        $latitude = $origin['latitude'] ?? null;
        $longitude = $origin['longitude'] ?? null;
        if (is_numeric($latitude) && is_numeric($longitude)
            && $settings->isInsideOperationalArea((float)$latitude, (float)$longitude)) {
            return [
                'source' => 'GPS',
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'address' => (string)($origin['address'] ?? 'Ubicación actual del técnico (GPS móvil)'),
            ];
        }

        return [
            'source' => 'BASE_CENTRAL',
            'latitude' => $settings->getBaseLatitude(),
            'longitude' => $settings->getBaseLongitude(),
            'address' => $settings->getBaseAddress(),
        ];
    }

    /**
     * @param array<string, mixed> $origin
     * @param RouteStop[] $stops
     * @return array{string, bool}
     */
    private function fullRouteNavigationUrl(array $origin, array $stops): array
    {
        if ($stops === []) {
            return ['', false];
        }

        // The technical contract caps the URL at ten total points: origin, eight waypoints, and destination.
        $maximumStops = self::MAX_GOOGLE_MAPS_WAYPOINTS;
        $truncated = count($stops) > $maximumStops;
        $includedStops = array_slice($stops, 0, $maximumStops);
        $destination = $includedStops[array_key_last($includedStops)]->getLocation();
        $waypoints = array_slice($includedStops, 0, -1);

        $url = 'https://www.google.com/maps/dir/?api=1'
            . '&origin=' . $this->formatCoordinate((float)$origin['latitude']) . ',' . $this->formatCoordinate((float)$origin['longitude'])
            . '&destination=' . $this->formatCoordinate($destination->getLatitude()) . ',' . $this->formatCoordinate($destination->getLongitude());

        if ($waypoints !== []) {
            $coordinates = array_map(
                fn(RouteStop $stop): string => $this->formatCoordinate($stop->getLocation()->getLatitude())
                    . ',' . $this->formatCoordinate($stop->getLocation()->getLongitude()),
                $waypoints
            );
            $url .= '&waypoints=' . implode('%7C', $coordinates);
        }

        return [$url . '&travelmode=driving', $truncated];
    }

    private function individualNavigationUrl(float $latitude, float $longitude): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='
            . $this->formatCoordinate($latitude) . ',' . $this->formatCoordinate($longitude)
            . '&travelmode=driving';
    }

    private function formatCoordinate(float $coordinate): string
    {
        return rtrim(rtrim(number_format($coordinate, 7, '.', ''), '0'), '.');
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isCompletedTask(string $status): bool
    {
        return in_array($status, ['COMPLETED', 'RESOLVED', 'CLOSED'], true);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareStableStops(array $left, array $right): int
    {
        return self::compareAgeAndLocation($left, $right);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function compareAgeAndLocation(array $left, array $right): int
    {
        $leftCreatedAt = $left['created_at'] instanceof DateTimeInterface
            ? (float)$left['created_at']->format('U.u')
            : INF;
        $rightCreatedAt = $right['created_at'] instanceof DateTimeInterface
            ? (float)$right['created_at']->format('U.u')
            : INF;
        $ageComparison = $leftCreatedAt <=> $rightCreatedAt;
        if ($ageComparison !== 0) {
            return $ageComparison;
        }

        return $left['location']->getId() <=> $right['location']->getId();
    }
}
