<?php
declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteSettingsService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRouteSettingsRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * Serves territorial triage data and central-base route settings to coordinators.
 */
final class CoordinatorRouteMapController
{
    private const ACTIVE_INCIDENT_STATUSES = ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'REOPENED'];
    private const ACTIVE_PREVENTIVE_STATUSES = ['PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION', 'EXPIRED'];
    private const SETTINGS_UPDATED_MESSAGE = 'Configuración de ruta y Base Central actualizada correctamente.';

    private readonly RouteSettingsService $settingsService;
    private readonly RouteSettingsRepositoryInterface $settingsRepository;

    public function __construct(
        private readonly IncidentRepositoryInterface $incidentRepository = new PdoIncidentRepository(),
        private readonly PreventiveOrderRepositoryInterface $preventiveOrderRepository = new PdoPreventiveOrderRepository(),
        private readonly LocationRepositoryInterface $locationRepository = new PdoLocationRepository(),
        ?RouteSettingsService $settingsService = null,
        ?RouteSettingsRepositoryInterface $settingsRepository = null,
        private readonly ?\PDO $pdo = null
    ) {
        $this->settingsRepository = $settingsRepository ?? new PdoRouteSettingsRepository($this->pdo);
        $this->settingsService = $settingsService ?? new RouteSettingsService(
            $this->settingsRepository,
            new GeoDistanceService()
        );
    }

    /** GET /api/coordinator/map/active-incidents */
    public function getActiveIncidents(Request $request): Response
    {
        if (($authorizationError = $this->authorizeCoordinator($request)) !== null) {
            return $authorizationError;
        }

        $technicianId = null;
        $technicianFilter = $request->getQuery('technician_id');
        if ($technicianFilter !== null && $technicianFilter !== '') {
            if (!$this->isPositiveInteger($technicianFilter) || (float)$technicianFilter > PHP_INT_MAX) {
                return Response::error('INVALID_TECHNICIAN_FILTER', 'El parámetro technician_id debe ser un número entero positivo.', 400);
            }
            $technicianId = (int)$technicianFilter;
        }

        $unassignedOnly = $this->isEnabled($request->getQuery('unassigned_only'));
        $criticalOnly = $this->isEnabled($request->getQuery('is_critical_only'));

        try {
            $sites = [];
            $incidents = $this->incidentRepository->findAll([
                'status' => self::ACTIVE_INCIDENT_STATUSES,
                'active_only' => true,
            ]);
            foreach ($incidents as $incident) {
                if (!$incident instanceof Incident || $incident->getId() === null
                    || !in_array($incident->getStatus()->value, self::ACTIVE_INCIDENT_STATUSES, true)
                    || $incident->getIsActiveTicket() === 0
                    || $incident->getDeletedAt() !== null
                    || ($technicianId !== null && $incident->getAssignedTechnicianId() !== $technicianId)) {
                    continue;
                }
                $location = $this->getActiveLocation($incident->getLocationId());
                if ($location === null) {
                    continue;
                }
                $site =& $this->siteAccumulator($sites, $location);
                $this->addIncident($site, $incident);
                unset($site);
            }

            foreach (self::ACTIVE_PREVENTIVE_STATUSES as $status) {
                foreach ($this->preventiveOrderRepository->findForCoordinatorList(['status' => $status]) as $order) {
                    if (!$order instanceof PreventiveOrder || $order->getDeletedAt() !== null
                        || !in_array($order->getStatus(), self::ACTIVE_PREVENTIVE_STATUSES, true)
                        || ($technicianId !== null && $order->getAssignedTechnicianId() !== $technicianId)) {
                        continue;
                    }
                    $location = $this->getActiveLocation($order->getLocationId());
                    if ($location === null) {
                        continue;
                    }
                    $site =& $this->siteAccumulator($sites, $location);
                    $this->addPreventive($site, $order);
                    unset($site);
                }
            }

            $locations = [];
            foreach ($sites as $site) {
                if ($site['total_incidents'] === 0 && $site['total_preventives'] === 0) {
                    continue;
                }
                $hasUnassignedIncident = $site['has_unassigned_incident'];
                $site['assigned_technicians'] = array_values($site['assigned_technicians']);
                $site['is_multi_technician'] = count($site['assigned_technicians']) > 1;
                $site['has_unassigned'] = $hasUnassignedIncident || $site['has_unassigned_preventive'];
                unset($site['has_unassigned_incident'], $site['has_unassigned_preventive']);
                if ($unassignedOnly && !$hasUnassignedIncident) {
                    continue;
                }
                if ($criticalOnly && !$site['has_perishable_risk']) {
                    continue;
                }
                $locations[] = $site;
            }

            usort($locations, static fn(array $left, array $right): int => $left['location_id'] <=> $right['location_id']);
            $totalTasks = array_sum(array_map(
                static fn(array $site): int => $site['total_incidents'] + $site['total_preventives'],
                $locations
            ));

            return Response::json([
                'total_locations' => count($locations),
                'total_active_tasks' => $totalTasks,
                'locations' => $locations,
            ]);
        } catch (Throwable) {
            return Response::error(
                'DATABASE_ERROR',
                'Se produjo un error al procesar los datos de georreferenciación.',
                500
            );
        }
    }

    /** GET /api/coordinator/route/settings */
    public function getRouteSettings(Request $request): Response
    {
        if (($authorizationError = $this->authorizeCoordinator($request)) !== null) {
            return $authorizationError;
        }

        try {
            $settings = $this->settingsService->getSettings();
            if ($settings === null) {
                return $this->databaseError();
            }
            $data = $this->settingsPayload($settings);
            $updatedAt = $this->fetchSettingsUpdatedAt();
            if ($updatedAt !== null) {
                $data['updated_at'] = $this->formatTimestamp($updatedAt);
            }

            return Response::json($data);
        } catch (Throwable) {
            return $this->databaseError();
        }
    }

    /** PUT /api/coordinator/route/settings */
    public function updateRouteSettings(Request $request): Response
    {
        if (($authorizationError = $this->authorizeCoordinator($request)) !== null) {
            return $authorizationError;
        }

        $body = $request->getParsedBody();
        foreach (['base_name', 'base_address', 'base_latitude', 'base_longitude', 'operational_radius_km'] as $field) {
            if (!array_key_exists($field, $body)) {
                return Response::error('INVALID_ROUTE_SETTINGS', 'Todos los campos de configuración de ruta son obligatorios.', 422, ['field' => $field]);
            }
        }

        if (!is_string($body['base_name']) || trim($body['base_name']) === ''
            || !is_string($body['base_address']) || trim($body['base_address']) === '') {
            return Response::error('INVALID_ROUTE_SETTINGS', 'El nombre y la dirección de la Base Central son obligatorios.', 422);
        }
        if (!$this->isNumericCoordinate($body['base_latitude']) || !$this->isNumericCoordinate($body['base_longitude'])) {
            return Response::error(
                'INVALID_COORDINATES',
                'Las coordenadas geográficas (latitud y longitud) son obligatorias y deben ser numéricas.',
                422
            );
        }
        if (!$this->isPositiveInteger($body['operational_radius_km'])) {
            return Response::error('INVALID_ROUTE_SETTINGS', 'El radio operativo debe ser un número entero mayor que cero.', 422);
        }

        $latitude = (float)$body['base_latitude'];
        $longitude = (float)$body['base_longitude'];
        if (!is_finite($latitude) || !is_finite($longitude)) {
            return Response::error(
                'INVALID_COORDINATES',
                'Las coordenadas geográficas (latitud y longitud) son obligatorias y deben ser numéricas.',
                422
            );
        }
        $currentSettings = null;
        try {
            $currentSettings = $this->settingsService->getSettings();
        } catch (Throwable) {
            return $this->databaseError();
        }
        if ($currentSettings === null) {
            return $this->databaseError();
        }
        if (!$currentSettings->isInsideOperationalArea($latitude, $longitude)) {
            return Response::error(
                'COORDINATES_OUT_OF_BOUNDS',
                'Las coordenadas especificadas se encuentran fuera del territorio geográfico operativo permitido.',
                422
            );
        }

        try {
            $updated = $this->settingsService->updateSettings(
                trim($body['base_name']),
                trim($body['base_address']),
                $latitude,
                $longitude,
                (int)$body['operational_radius_km']
            );
            if (!$updated) {
                return $this->databaseError();
            }

            $settings = new RouteSettings(
                trim($body['base_name']),
                trim($body['base_address']),
                $latitude,
                $longitude,
                (int)$body['operational_radius_km'],
                $currentSettings->getMinLatitude(),
                $currentSettings->getMaxLatitude(),
                $currentSettings->getMinLongitude(),
                $currentSettings->getMaxLongitude()
            );

            return Response::json($this->settingsPayload($settings), 200, self::SETTINGS_UPDATED_MESSAGE);
        } catch (InvalidArgumentException $exception) {
            return Response::error('INVALID_ROUTE_SETTINGS', $exception->getMessage(), 422);
        } catch (Throwable) {
            return $this->databaseError();
        }
    }

    /** @return array<string, mixed> */
    private function &siteAccumulator(array &$sites, Location $location): array
    {
        $id = $location->getId();
        if (!isset($sites[$id])) {
            $sites[$id] = [
                'location_id' => $id,
                'site_code' => $location->getSiteCode(),
                'name' => $location->getName(),
                'address' => $location->getAddress(),
                'latitude' => $location->getLatitude(),
                'longitude' => $location->getLongitude(),
                'max_urgency' => null,
                'has_perishable_risk' => false,
                'total_incidents' => 0,
                'total_preventives' => 0,
                'assigned_technicians' => [],
                'has_unassigned_incident' => false,
                'has_unassigned_preventive' => false,
                'incidents_summary' => [],
            ];
        }
        return $sites[$id];
    }

    /** @param array<string, mixed> $site */
    private function addIncident(array &$site, Incident $incident): void
    {
        $site['total_incidents']++;
        $urgency = $incident->getUrgency();
        if ($site['max_urgency'] === null || $urgency->priorityRank() > UrgencyLevel::from($site['max_urgency'])->priorityRank()) {
            $site['max_urgency'] = $urgency->value;
        }
        if ($urgency === UrgencyLevel::CRITICAL && $incident->getMachineType() === 'PERISHABLE_FOOD') {
            $site['has_perishable_risk'] = true;
        }

        $technicianId = $incident->getAssignedTechnicianId();
        if ($technicianId === null) {
            $site['has_unassigned_incident'] = true;
        } else {
            $technician = $site['assigned_technicians'][$technicianId] ?? [
                'id' => $technicianId,
                'name' => $incident->getTechnicianName() ?? '',
                'operator_code' => $incident->getTechnicianOperatorCode() ?? '',
            ];
            if ($technician['name'] === '' && $incident->getTechnicianName() !== null) {
                $technician['name'] = $incident->getTechnicianName();
            }
            if ($technician['operator_code'] === '' && $incident->getTechnicianOperatorCode() !== null) {
                $technician['operator_code'] = $incident->getTechnicianOperatorCode();
            }
            $site['assigned_technicians'][$technicianId] = $technician;
        }

        $site['incidents_summary'][] = [
            'id' => $incident->getId(),
            'machine_code' => $incident->getMachineCode(),
            'status' => $incident->getStatus()->value,
            'urgency' => $urgency->value,
            'assigned_technician_name' => $incident->getTechnicianName(),
        ];
    }

    /** @param array<string, mixed> $site */
    private function addPreventive(array &$site, PreventiveOrder $order): void
    {
        $site['total_preventives']++;
        $technicianId = $order->getAssignedTechnicianId();
        if ($technicianId === null) {
            $site['has_unassigned_preventive'] = true;
            return;
        }

        $technicianData = $order->getTechnicianData() ?? [];
        $technician = $site['assigned_technicians'][$technicianId] ?? [
            'id' => $technicianId,
            'name' => '',
            'operator_code' => '',
        ];
        if ($technician['name'] === '') {
            $technician['name'] = (string)($technicianData['name'] ?? '');
        }
        if ($technician['operator_code'] === '') {
            $technician['operator_code'] = (string)($technicianData['operator_code'] ?? '');
        }
        $site['assigned_technicians'][$technicianId] = $technician;
    }

    private function getActiveLocation(int $locationId): ?Location
    {
        $location = $this->locationRepository->findById($locationId);
        return $location instanceof Location && $location->isActive() ? $location : null;
    }

    private function authorizeCoordinator(Request $request): ?Response
    {
        if (!is_numeric($request->getAttribute('user_id')) || (int)$request->getAttribute('user_id') < 1) {
            return Response::error('UNAUTHORIZED', 'Token de autenticación ausente o inválido.', 401);
        }
        if ($request->getAttribute('user_role') !== 'COORDINATOR') {
            return Response::error(
                'FORBIDDEN',
                'No dispone de permisos para acceder a las funciones cartográficas o de rutas.',
                403
            );
        }
        return null;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string)$value) === 1
            && (float)$value <= PHP_INT_MAX;
    }

    private function isNumericCoordinate(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && trim((string)$value) !== ''
            && is_numeric($value);
    }

    private function isEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    /** @return array<string, float|int|string> */
    private function settingsPayload(RouteSettings $settings): array
    {
        return [
            'base_name' => $settings->getBaseName(),
            'base_address' => $settings->getBaseAddress(),
            'base_latitude' => $settings->getBaseLatitude(),
            'base_longitude' => $settings->getBaseLongitude(),
            'operational_radius_km' => $settings->getOperationalRadiusKm(),
        ];
    }

    private function fetchSettingsUpdatedAt(): ?string
    {
        if ($this->settingsRepository instanceof PdoRouteSettingsRepository) {
            return $this->settingsRepository->findUpdatedAt();
        }

        $pdo = $this->pdo ?? ConnectionFactory::getConnection();
        $updatedAt = $pdo->query('SELECT `updated_at` FROM `route_settings` WHERE `id` = 1 LIMIT 1')->fetchColumn();

        return $updatedAt === false || $updatedAt === null ? null : (string)$updatedAt;
    }

    private function formatTimestamp(string $timestamp): string
    {
        try {
            return (new \DateTimeImmutable($timestamp, new \DateTimeZone('Europe/Madrid')))->format(DATE_ATOM);
        } catch (Throwable) {
            return $timestamp;
        }
    }

    private function databaseError(): Response
    {
        return Response::error(
            'DATABASE_ERROR',
            'Se produjo un error al procesar los datos de georreferenciación.',
            500
        );
    }
}
