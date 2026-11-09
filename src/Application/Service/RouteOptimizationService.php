<?php
declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\RouteStop;
use VendGuard\Core\Domain\Model\StopPriority;
use VendGuard\Core\Domain\Model\StopStatus;
use VendGuard\Core\Domain\Model\TechnicianRouteMap;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\SlaTargetCalculator;

/**
 * Builds a deterministic technician route from assigned corrective and preventive tasks.
 */
final class RouteOptimizationService
{
    /** Frontera inclusiva de la ventana crítica: 240 min = 14.400 s (D3, QA 1). */
    private const CRITICAL_SLA_SECONDS = 14400.0;

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
                $this->individualNavigationUrl($latitude, $longitude),
                $location->getSlaTier(),
                $location->getSlaTierLabel(),
                $stopData['critical_reason'],
                $stopData['is_cold_chain_critical'],
                $stopData['is_contractual_critical']
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
     * Resume una parada bajo la tarea correctiva pendiente más restrictiva (C3, EARS 04.4).
     *
     * Las órdenes preventivas se agrupan para la visita, pero nunca gobiernan la
     * prioridad ni el vencimiento. Una tarea con riesgo de cadena de frío entra
     * siempre en el bloque crítico; una tarea sin ese riesgo entra si su ventana
     * efectiva es de 240 min o menos, con frontera inclusiva.
     *
     * @param array{location: Location, tasks: array<array<string, mixed>>} $group
     * @return array{location: Location, tasks: array<array<string, mixed>>, completed_tasks: int, status: StopStatus, priority: StopPriority, sla_due_at: ?DateTimeImmutable, created_at: ?DateTimeImmutable, critical_reason: ?string, is_cold_chain_critical: bool, is_contractual_critical: bool}
     */
    private function summarizeGroup(array $group, DateTimeInterface $referenceTime): array
    {
        $reference = $referenceTime instanceof DateTimeImmutable
            ? $referenceTime
            : DateTimeImmutable::createFromInterface($referenceTime);
        $completedTasks = 0;
        $hasInProgress = false;
        $isCritical = false;
        $isColdChainCritical = false;
        $isContractualCritical = false;
        $criticalDeadlines = [];
        $creationTimes = [];
        $criticalCreationTimes = [];
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

            if (!$this->isCorrectiveTask($task)) {
                continue;
            }

            $taskIsColdChainCritical = $this->isColdChainCritical($task);
            $slaDueAt = $this->resolveSlaDeadline(
                $task,
                $createdAt,
                $reference,
                $taskIsColdChainCritical
            );
            $evaluationAt = $status === 'PENDING_INFO'
                ? ($this->parseDate($task['paused_at'] ?? null) ?? $reference)
                : $reference;
            $remainingSeconds = $slaDueAt !== null
                ? (float)$slaDueAt->format('U.u') - (float)$evaluationAt->format('U.u')
                : null;
            $taskIsContractualCritical = $remainingSeconds !== null
                && $remainingSeconds <= self::CRITICAL_SLA_SECONDS;

            if (!$taskIsColdChainCritical && !$taskIsContractualCritical) {
                continue;
            }

            $isCritical = true;
            $isColdChainCritical = $isColdChainCritical || $taskIsColdChainCritical;
            $isContractualCritical = $isContractualCritical || $taskIsContractualCritical;
            if ($slaDueAt !== null) {
                $criticalDeadlines[] = $slaDueAt;
            }
            if ($createdAt !== null) {
                $criticalCreationTimes[] = $createdAt;
            }
        }

        $earliestSla = $criticalDeadlines === [] ? null : min($criticalDeadlines);
        $earliestCreation = $creationTimes === [] ? null : min($creationTimes);
        $orderingCreation = $isCritical
            ? ($criticalCreationTimes === [] ? null : min($criticalCreationTimes))
            : $earliestCreation;
        $criticalReason = $isCritical
            ? ($isColdChainCritical ? 'COLD_CHAIN' : 'CONTRACTUAL_SLA')
            : null;

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
                'critical_reason' => null,
                'is_cold_chain_critical' => false,
                'is_contractual_critical' => false,
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
            'created_at' => $orderingCreation,
            'critical_reason' => $criticalReason,
            'is_cold_chain_critical' => $isColdChainCritical,
            'is_contractual_critical' => $isContractualCritical,
        ];
    }

    /**
     * Vencimiento efectivo de una tarea correctiva, con respaldo determinista (C2, C4).
     *
     * La pausa no se descuenta aquí: el vencimiento ya la incorpora en el objetivo
     * sellado y, para la promoción, la referencia se congela en `paused_at`.
     */
    private function resolveSlaDeadline(
        array $task,
        ?DateTimeImmutable $createdAt,
        DateTimeImmutable $reference,
        bool $isColdChainCritical
    ): ?DateTimeImmutable {
        $slaDueAt = $this->parseDate($task['sla_due_at'] ?? null);
        if ($slaDueAt !== null) {
            return $slaDueAt;
        }

        if ($createdAt === null) {
            return $isColdChainCritical ? $reference : null;
        }

        $targetMinutes = isset($task['sla_target_minutes']) && is_numeric($task['sla_target_minutes'])
            ? max(0, (int)$task['sla_target_minutes'])
            : null;

        // Sin objetivo sellado no se inventa una ventana contractual: la foto
        // antigua conserva su comportamiento y T-SLA-10 entrega el campo. El
        // riesgo de frío sí tiene un techo conocido de 240 min.
        if ($targetMinutes === null) {
            return $isColdChainCritical ? $createdAt->modify('+240 minutes') : null;
        }

        $discountSeconds = max(0, (int)($task['total_pending_info_seconds'] ?? 0));

        return $createdAt->modify('+' . (($targetMinutes * 60) + $discountSeconds) . ' seconds');
    }

    /**
     * La tarea correctiva es la única que puede promover o retrasar la parada.
     */
    private function isCorrectiveTask(array $task): bool
    {
        return strtoupper(trim((string)($task['type'] ?? 'CORRECTIVE'))) !== 'PREVENTIVE';
    }

    /**
     * Riesgo de cadena de frío explícito o derivado del catálogo del Art. II.
     */
    private function isColdChainCritical(array $task): bool
    {
        if (array_key_exists('is_cold_chain_critical', $task)
            && filter_var($task['is_cold_chain_critical'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $machineType = $this->machineType($task);
        if ($machineType === null) {
            return false;
        }

        return SlaTargetCalculator::isColdChainRisk(
            $machineType,
            $this->incidentCategory($task),
            $this->urgency($task) ?? UrgencyLevel::MEDIUM
        );
    }

    private function machineType(array $task): ?MachineType
    {
        return MachineType::tryFrom(strtoupper(trim((string)($task['machine_type'] ?? ''))));
    }

    private function urgency(array $task): ?UrgencyLevel
    {
        return UrgencyLevel::tryFrom(strtoupper(trim((string)($task['urgency'] ?? ''))));
    }

    private function incidentCategory(array $task): IncidentCategory
    {
        return IncidentCategory::tryFrom(strtoupper(trim((string)($task['category'] ?? ''))))
            ?? IncidentCategory::OTHER;
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

        usort($critical, fn(array $left, array $right): int => $this->compareCriticalStops($left, $right));
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
     * Prelación sanitaria dentro del bloque crítico (Art. II.2, QA 1).
     *
     * Primero las paradas con cadena de frío, después las puramente
     * contractuales; dentro de cada sub-bloque manda el vencimiento más próximo y,
     * en empate, la antigüedad y el identificador de sede.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareCriticalStops(array $left, array $right): int
    {
        $leftRank = $left['is_cold_chain_critical'] ? 0 : 1;
        $rightRank = $right['is_cold_chain_critical'] ? 0 : 1;
        $rankComparison = $leftRank <=> $rightRank;
        if ($rankComparison !== 0) {
            return $rankComparison;
        }

        $leftDeadline = $left['sla_due_at'] instanceof DateTimeInterface
            ? (float)$left['sla_due_at']->format('U.u')
            : INF;
        $rightDeadline = $right['sla_due_at'] instanceof DateTimeInterface
            ? (float)$right['sla_due_at']->format('U.u')
            : INF;
        $deadlineComparison = $leftDeadline <=> $rightDeadline;

        return $deadlineComparison !== 0
            ? $deadlineComparison
            : self::compareAgeAndLocation($left, $right);
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
