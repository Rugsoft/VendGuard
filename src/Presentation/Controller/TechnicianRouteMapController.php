<?php
declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteOptimizationService;
use VendGuard\Application\Service\RouteSettingsService;
use VendGuard\Core\Domain\Exception\InvalidCoordinatesException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Service\SlaTargetCalculator;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRouteSettingsRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * Serves the authenticated technician's consolidated route map.
 */
final class TechnicianRouteMapController
{
    private const ROUTE_INCIDENT_STATUSES = ['ASSIGNED', 'IN_PROGRESS'];
    private const ROUTE_PREVENTIVE_STATUSES = ['SCHEDULED', 'IN_INSPECTION', 'EXPIRED'];

    private readonly RouteSettingsService $settingsService;
    private readonly RouteOptimizationService $routeOptimizationService;

    public function __construct(
        private readonly IncidentRepositoryInterface $incidentRepository = new PdoIncidentRepository(),
        private readonly PreventiveOrderRepositoryInterface $preventiveOrderRepository = new PdoPreventiveOrderRepository(),
        private readonly MachineRepositoryInterface $machineRepository = new PdoMachineRepository(),
        private readonly LocationRepositoryInterface $locationRepository = new PdoLocationRepository(),
        ?RouteSettingsService $settingsService = null,
        ?RouteOptimizationService $routeOptimizationService = null
    ) {
        $geoDistanceService = new GeoDistanceService();
        $this->settingsService = $settingsService ?? new RouteSettingsService(
            new PdoRouteSettingsRepository(),
            $geoDistanceService
        );
        $this->routeOptimizationService = $routeOptimizationService ?? new RouteOptimizationService($geoDistanceService);
    }

    /**
     * GET /api/technician/route/map
     */
    public function getRouteMap(Request $request): Response
    {
        $technicianId = $request->getAttribute('user_id');
        if (!is_numeric($technicianId) || (int)$technicianId < 1) {
            return Response::error('UNAUTHORIZED', 'Token de autenticación ausente o inválido.', 401);
        }
        if ($request->getAttribute('user_role') !== 'TECHNICIAN') {
            return Response::error(
                'FORBIDDEN',
                'No dispone de permisos para acceder a las funciones cartográficas o de rutas.',
                403
            );
        }

        try {
            $settings = $this->settingsService->getSettings();
            if ($settings === null) {
                return Response::error('DATABASE_ERROR', 'Se produjo un error al procesar los datos de georreferenciación.', 500);
            }
            $origin = $this->extractOrigin($request, $settings);
            $tasks = $this->loadAssignedTasks((int)$technicianId);
            $route = $this->routeOptimizationService->optimizeRoute($tasks, $origin, $settings);

            return Response::json($this->sanitizeRoutePayload($route->toArray()), 200);
        } catch (InvalidCoordinatesException) {
            return Response::error(
                'INVALID_ORIGIN_COORDINATES',
                'Las coordenadas de origen proporcionadas no son válidas.',
                422
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error(
                'INVALID_ROUTE_DATA',
                $exception->getMessage(),
                422
            );
        } catch (Throwable) {
            return Response::error('DATABASE_ERROR', 'Se produjo un error al procesar los datos de georreferenciación.', 500);
        }
    }

    /**
     * @return array<string, float>|null
     */
    private function extractOrigin(Request $request, RouteSettings $settings): ?array
    {
        $rawLatitude = $request->getQuery('origin_lat');
        $rawLongitude = $request->getQuery('origin_lng');
        if ($rawLatitude === null && $rawLongitude === null) {
            return null;
        }

        if (!is_numeric($rawLatitude) || !is_numeric($rawLongitude)) {
            throw new InvalidCoordinatesException('Las coordenadas de origen proporcionadas no son válidas.');
        }

        $latitude = (float)$rawLatitude;
        $longitude = (float)$rawLongitude;
        if (!$settings->isInsideOperationalArea($latitude, $longitude)) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function loadAssignedTasks(int $technicianId): array
    {
        $tasks = [];
        $incidentMachineCache = [];
        $locationCache = [];

        foreach ($this->incidentRepository->findAssignedToTechnician($technicianId, self::ROUTE_INCIDENT_STATUSES) as $incident) {
            if (!$incident instanceof Incident || $incident->getId() === null
                || $incident->getAssignedTechnicianId() !== $technicianId
                || !in_array($incident->getStatus()->value, self::ROUTE_INCIDENT_STATUSES, true)) {
                continue;
            }
            $machine = $this->findMachine($incident->getMachineId(), $incidentMachineCache);
            $location = $this->findLocation($incident->getLocationId(), $locationCache);
            if ($machine === null || $location === null) {
                continue;
            }
            $tasks[] = $this->formatIncidentTask($incident, $machine, $location);
        }

        foreach ($this->preventiveOrderRepository->findForTechnicianRoute($technicianId) as $order) {
            if (!$order instanceof PreventiveOrder
                || $order->getAssignedTechnicianId() !== $technicianId
                || !in_array($order->getStatus(), self::ROUTE_PREVENTIVE_STATUSES, true)) {
                continue;
            }
            $machine = $this->findMachine($order->getMachineId(), $incidentMachineCache);
            $location = $this->findLocation($order->getLocationId(), $locationCache);
            if ($machine === null || $location === null) {
                continue;
            }
            $tasks[] = $this->formatPreventiveTask($order, $machine, $location);
        }

        return $tasks;
    }

    /**
     * @param array<int, Machine|null> $cache
     */
    private function findMachine(int $machineId, array &$cache): ?Machine
    {
        if (!array_key_exists($machineId, $cache)) {
            $cache[$machineId] = $this->machineRepository->findById($machineId, withIncident: false);
        }

        return $cache[$machineId];
    }

    /**
     * @param array<int, Location|null> $cache
     */
    private function findLocation(int $locationId, array &$cache): ?Location
    {
        if (!array_key_exists($locationId, $cache)) {
            $cache[$locationId] = $this->locationRepository->findById($locationId);
        }

        return $cache[$locationId];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatIncidentTask(Incident $incident, Machine $machine, Location $location): array
    {
        $slaDueAt = $this->incidentSlaDeadline($incident);
        $isColdChainCritical = SlaTargetCalculator::isColdChainRisk(
            $machine->getMachineType(),
            $incident->getCategory(),
            $incident->getUrgency()
        );
        $isContractualCritical = $this->isContractualCritical($slaDueAt, $incident->getPausedAt());

        return [
            'type' => 'CORRECTIVE',
            'id' => $incident->getId(),
            'machine_id' => $incident->getMachineId(),
            'machine_code' => $incident->getMachineCode() ?? $machine->getCode(),
            'machine_type' => $machine->getMachineType()->value,
            'floor_location' => $machine->getFloorWing(),
            'status' => $incident->getStatus()->value,
            'urgency' => $incident->getUrgency()->value,
            'sla_tier' => $incident->getSlaTierSnapshot()->value,
            'sla_tier_snapshot' => $incident->getSlaTierSnapshot()->value,
            'sla_tier_label' => $incident->getSlaTierLabel(),
            'sla_target_minutes' => $incident->getSlaTargetMinutes(),
            'sla_target_at' => $incident->getSlaTargetAt(),
            'sla_due_at' => $slaDueAt,
            'critical_reason' => $isColdChainCritical
                ? 'COLD_CHAIN'
                : ($isContractualCritical ? 'CONTRACTUAL_SLA' : null),
            'is_cold_chain_critical' => $isColdChainCritical,
            'is_contractual_critical' => $isContractualCritical,
            'is_critical' => $isColdChainCritical || $isContractualCritical,
            'location' => $location,
        ];
    }

    private function incidentSlaDeadline(Incident $incident): ?string
    {
        $sealedTargetAt = $incident->getSlaTargetAt();
        if ($sealedTargetAt !== null && trim($sealedTargetAt) !== '') {
            try {
                return (new DateTimeImmutable($sealedTargetAt))->format(DATE_ATOM);
            } catch (\Throwable) {
                // Una huella corrupta no debe tumbar la ruta: se recalcula desde el objetivo sellado.
            }
        }

        $createdAt = $incident->getCreatedAt();
        if ($createdAt === null || trim($createdAt) === '') {
            return null;
        }

        try {
            $targetSeconds = ($incident->getSlaTargetMinutes() * 60)
                + max(0, $incident->getTotalPendingInfoSeconds());

            return (new DateTimeImmutable($createdAt))
                ->modify('+' . $targetSeconds . ' seconds')
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Frontera inclusiva de 240 min: un aviso vencido también es urgente (D3, QA 1).
     * Si hay una pausa viva, la referencia se congela en `paused_at` (C2).
     */
    private function isContractualCritical(?string $slaDueAt, ?string $pausedAt = null): bool
    {
        if ($slaDueAt === null || trim($slaDueAt) === '') {
            return false;
        }

        try {
            $due = new DateTimeImmutable($slaDueAt);
            $reference = $pausedAt !== null && trim($pausedAt) !== ''
                ? (new DateTimeImmutable($pausedAt))->getTimestamp()
                : time();
        } catch (\Throwable) {
            return false;
        }

        return ($due->getTimestamp() - $reference) <= (240 * 60);
    }

    /**
     * Vencimiento correctivo más próximo de la parada (C3).
     *
     * @param array<array<string, mixed>> $tasks
     */
    private function earliestSlaDueAt(array $tasks): ?string
    {
        $earliest = null;
        $earliestTimestamp = null;

        foreach ($tasks as $task) {
            $raw = $task['sla_due_at'] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            try {
                $due = new DateTimeImmutable($raw);
            } catch (\Throwable) {
                continue;
            }

            $timestamp = $due->getTimestamp();
            if ($earliestTimestamp === null || $timestamp < $earliestTimestamp) {
                $earliestTimestamp = $timestamp;
                $earliest = $raw;
            }
        }

        return $earliest;
    }

    /**
     * Objetivo de la correctiva más restrictiva que gobierna la parada (C3).
     *
     * @param array<array<string, mixed>> $tasks
     */
    private function governingSlaTargetMinutes(array $tasks): ?int
    {
        $targetMinutes = null;

        foreach ($tasks as $task) {
            if (strtoupper(trim((string)($task['type'] ?? 'CORRECTIVE'))) === 'PREVENTIVE') {
                continue;
            }

            $value = $task['sla_target_minutes'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            $minutes = max(0, (int)$value);
            if ($targetMinutes === null || $minutes < $targetMinutes) {
                $targetMinutes = $minutes;
            }
        }

        return $targetMinutes;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPreventiveTask(PreventiveOrder $order, Machine $machine, Location $location): array
    {
        return [
            'type' => 'PREVENTIVE',
            'id' => $order->getId(),
            'machine_id' => $order->getMachineId(),
            'machine_code' => $machine->getCode(),
            'machine_type' => $machine->getMachineType()->value,
            'floor_location' => $machine->getFloorWing(),
            'status' => $order->getStatus() === 'IN_INSPECTION' ? 'IN_PROGRESS' : $order->getStatus(),
            'urgency' => 'MEDIUM',
            'sla_tier' => null,
            'sla_tier_snapshot' => null,
            'sla_tier_label' => null,
            'sla_target_minutes' => null,
            'sla_target_at' => null,
            'sla_due_at' => null,
            'critical_reason' => null,
            'is_cold_chain_critical' => false,
            'is_contractual_critical' => false,
            'is_critical' => false,
            'location' => $location,
        ];
    }

    /**
     * Limits response fields to the route-map API contract and avoids exposing contact details or incident internals.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function sanitizeRoutePayload(array $route): array
    {
        $locationFields = ['id', 'site_code', 'name', 'address', 'latitude', 'longitude'];
        $taskFields = [
            'type', 'id', 'machine_id', 'machine_code', 'machine_type', 'floor_location',
            'status', 'urgency', 'is_critical', 'sla_tier', 'sla_tier_snapshot', 'sla_tier_label',
            'sla_target_minutes', 'sla_target_at', 'sla_due_at', 'critical_reason',
            'is_cold_chain_critical', 'is_contractual_critical',
        ];

        foreach ($route['stops'] as &$stop) {
            $stop['location'] = array_intersect_key($stop['location'], array_flip($locationFields));
            $stop['tasks'] = array_map(
                static fn(array $task): array => array_intersect_key($task, array_flip($taskFields)),
                $stop['tasks']
            );
            $stop['sla_due_at'] = $this->earliestSlaDueAt($stop['tasks']);
            $stop['sla_target_minutes'] = $this->governingSlaTargetMinutes($stop['tasks']);
        }
        unset($stop);

        return $route;
    }
}
