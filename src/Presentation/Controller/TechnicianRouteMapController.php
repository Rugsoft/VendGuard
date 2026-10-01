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
        return [
            'type' => 'CORRECTIVE',
            'id' => $incident->getId(),
            'machine_id' => $incident->getMachineId(),
            'machine_code' => $incident->getMachineCode() ?? $machine->getCode(),
            'machine_type' => $machine->getMachineType()->value,
            'floor_location' => $machine->getFloorWing(),
            'status' => $incident->getStatus()->value,
            'urgency' => $incident->getUrgency()->value,
            'sla_due_at' => $this->incidentSlaDeadline($incident),
            'is_critical' => $incident->getUrgency()->isCritical()
                && $machine->getMachineType()->isPerishable(),
            'location' => $location,
        ];
    }

    private function incidentSlaDeadline(Incident $incident): ?string
    {
        $createdAt = $incident->getCreatedAt();
        if ($createdAt === null || trim($createdAt) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($createdAt))->modify(
                '+' . $incident->getUrgency()->slaResponseMinutes() . ' minutes'
            )->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
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
            'is_critical' => false,
            'sla_due_at' => null,
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
            'status', 'urgency', 'is_critical', 'sla_due_at',
        ];

        foreach ($route['stops'] as &$stop) {
            $stop['location'] = array_intersect_key($stop['location'], array_flip($locationFields));
            $stop['tasks'] = array_map(
                static fn(array $task): array => array_intersect_key($task, array_flip($taskFields)),
                $stop['tasks']
            );
        }
        unset($stop);

        return $route;
    }
}
