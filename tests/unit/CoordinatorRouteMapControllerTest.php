<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteSettingsService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\CoordinatorRouteMapController;
use VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

$assertions = 0;

function assertCoordinatorRouteMapCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $query */
function makeCoordinatorRouteMapRequest(
    string $method = 'GET',
    string $path = '/api/coordinator/map/active-incidents',
    array $query = [],
    array $body = [],
    int $userId = 8,
    string $role = 'COORDINATOR'
): Request {
    $request = new Request($method, $path, $query, $body);
    $request->setAttribute('user_id', $userId);
    $request->setAttribute('user_role', $role);
    return $request;
}

final class CoordinatorMapIncidentRepositoryStub implements IncidentRepositoryInterface
{
    public array $lastFilters = [];
    public function __construct(private readonly array $incidents) {}
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException(); }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { $this->lastFilters = $filters; return $this->incidents; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 0; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $comment): \VendGuard\Core\Domain\Model\IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new LogicException(); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException(); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException(); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException(); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException(); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException(); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
}

final class CoordinatorMapPreventiveRepositoryStub implements PreventiveOrderRepositoryInterface
{
    public function __construct(private readonly array $orders) {}
    public function create(array $data): PreventiveOrder { throw new LogicException(); }
    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function hasActiveOrPendingOrder(int $machineId): bool { return false; }
    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool { return false; }
    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool { return false; }
    public function startInspection(int $orderId, int $technicianId): bool { return false; }
    public function completeOrder(int $orderId, string $result, ?float $temperatureMeasured, bool $isQuarantineTriggered, ?int $linkedIncidentId = null, ?string $notes = null): bool { return false; }
    public function linkIncident(int $orderId, int $incidentId): bool { return false; }
    public function softCancel(int $orderId, string $reason): bool { return false; }
    public function findForCoordinatorList(array $filters = []): array
    {
        return array_values(array_filter($this->orders, static fn(PreventiveOrder $order): bool => $order->getStatus() === ($filters['status'] ?? null)));
    }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return []; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

final class CoordinatorMapLocationRepositoryStub implements LocationRepositoryInterface
{
    public function __construct(private readonly array $locations) {}
    public function findBySiteCode(string $siteCode): ?Location { return null; }
    public function findById(int $id, bool $allowDeleted = false): ?Location { return $this->locations[$id] ?? null; }
    public function findAllActive(): array { return array_values($this->locations); }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

final class CoordinatorMapSettingsRepositoryStub implements RouteSettingsRepositoryInterface
{
    public ?RouteSettings $settings;
    public int $updateCalls = 0;
    public string $updatedAt = '2026-09-30 09:00:00';
    public function __construct(?RouteSettings $settings = null) { $this->settings = $settings ?? new RouteSettings(); }
    public function find(): ?RouteSettings { return $this->settings; }
    public function update(RouteSettings $settings): bool { $this->updateCalls++; $this->settings = $settings; return true; }
}

final class CoordinatorMapUserRepositoryStub implements UserRepositoryInterface
{
    public function __construct(private readonly User $user) {}
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User { return $id === $this->user->getId() ? $this->user : null; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function create(array $data): User { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function updatePassword(int $id, string $newPassword): bool { return false; }
    public function resetPassword(int $id, string $newPassword): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function countActiveByRole(UserRole|string $role): int { return 0; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
}

function makeCoordinatorMapIncident(
    int $id,
    int $locationId,
    string $urgency,
    string $machineType,
    ?int $technicianId,
    string $technicianName,
    string $operatorCode,
    string $status = 'ASSIGNED'
): Incident {
    return new Incident(
        id: $id,
        ticketCode: 'INC-MAP-' . $id,
        machineId: 100 + $id,
        locationId: $locationId,
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Internal diagnostic details must not be serialized by the map endpoint.',
        urgency: UrgencyLevel::from($urgency),
        status: IncidentStatus::from($status),
        assignedTechnicianId: $technicianId,
        reporterName: 'Private reporter',
        reporterPhone: '600000000',
        resolutionDiagnosis: 'Private diagnosis',
        machineCode: 'VEND-' . $id,
        machineType: $machineType,
        locationName: 'Ignored hydrated site name',
        technicianName: $technicianName,
        technicianOperatorCode: $operatorCode
    );
}

$locations = [
    1 => new Location(1, 'SEDE-MAP-01', 'Sede Uno', 'Carrer Uno 1', 'Contacto privado', '600111111', true, null, null, null, 41.3853, 2.1932),
    2 => new Location(2, 'SEDE-MAP-02', 'Sede Dos', 'Carrer Dos 2', null, null, true, null, null, null, 41.4036, 2.1895),
    3 => new Location(3, 'SEDE-MAP-03', 'Sede Inactiva', 'Carrer Tres 3', null, null, false, null, null, null, 41.41, 2.2),
];
$settingsRepository = new CoordinatorMapSettingsRepositoryStub();
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE route_settings (id INTEGER PRIMARY KEY, updated_at TEXT)');
$pdo->exec("INSERT INTO route_settings (id, updated_at) VALUES (1, '2026-09-30 09:00:00')");
$controller = new CoordinatorRouteMapController(
    new CoordinatorMapIncidentRepositoryStub([
        makeCoordinatorMapIncident(101, 1, 'CRITICAL', 'PERISHABLE_FOOD', 2, 'Técnico Dos', 'OP-02', 'IN_PROGRESS'),
        makeCoordinatorMapIncident(102, 1, 'LOW', 'SNACKS', null, '', ''),
        makeCoordinatorMapIncident(103, 2, 'HIGH', 'COMBO', 2, 'Técnico Dos', 'OP-02'),
        makeCoordinatorMapIncident(104, 2, 'MEDIUM', 'HOT_DRINKS', 3, 'Técnico Tres', 'OP-03'),
        makeCoordinatorMapIncident(105, 3, 'CRITICAL', 'PERISHABLE_FOOD', 3, 'Técnico Tres', 'OP-03'),
        makeCoordinatorMapIncident(106, 1, 'HIGH', 'SNACKS', 2, 'Técnico Dos', 'OP-02', 'CLOSED'),
    ]),
    new CoordinatorMapPreventiveRepositoryStub([
        new PreventiveOrder(201, 'PREV-MAP-201', 301, 1, 4, 'SCHEDULED', 'ROUTINE', '2026-09-30', '2026-10-01', technicianData: ['id' => 4, 'name' => 'Técnica Cuatro', 'operator_code' => 'OP-04']),
        new PreventiveOrder(202, 'PREV-MAP-202', 302, 2, null, 'PENDING_ASSIGNMENT', 'ROUTINE', '2026-09-30', '2026-10-01'),
        new PreventiveOrder(203, 'PREV-MAP-203', 303, 2, null, 'CANCELLED', 'ROUTINE', '2026-09-30', '2026-10-01'),
    ]),
    new CoordinatorMapLocationRepositoryStub($locations),
    new RouteSettingsService($settingsRepository, new GeoDistanceService()),
    $settingsRepository,
    $pdo
);

try {
    $response = $controller->getActiveIncidents(makeCoordinatorRouteMapRequest());
    $body = $response->getDecodedBody();
    $siteOne = $body['data']['locations'][0] ?? [];
    $siteTwo = $body['data']['locations'][1] ?? [];
    assertCoordinatorRouteMapCondition($response->getStatusCode() === 200 && ($body['success'] ?? false) === true, 'Coordinator active map returns the standard 200 JSON envelope');
    assertCoordinatorRouteMapCondition(($body['data']['total_locations'] ?? 0) === 2 && ($body['data']['total_active_tasks'] ?? 0) === 6, 'Map totals include active incidents and preventive orders only at active sites');
    assertCoordinatorRouteMapCondition(($siteOne['max_urgency'] ?? null) === 'CRITICAL' && ($siteOne['has_perishable_risk'] ?? false) === true, 'Site severity and food-risk flag reflect the most urgent perishable incident');
    assertCoordinatorRouteMapCondition(($siteOne['total_incidents'] ?? 0) === 2 && ($siteOne['total_preventives'] ?? 0) === 1, 'Site consolidates incident and preventive task counts');
    assertCoordinatorRouteMapCondition(($siteOne['assigned_technicians'][0]['operator_code'] ?? null) === 'OP-02' && ($siteOne['assigned_technicians'][1]['operator_code'] ?? null) === 'OP-04', 'Assigned technician identities are unique and include their operator codes');
    assertCoordinatorRouteMapCondition(($siteTwo['is_multi_technician'] ?? false) === true && count($siteTwo['assigned_technicians'] ?? []) === 2, 'Concurrent technician assignments produce the multi-technician flag');
    assertCoordinatorRouteMapCondition(($siteOne['has_unassigned'] ?? false) === true && ($siteTwo['has_unassigned'] ?? false) === true, 'Map identifies sites with unassigned corrective or preventive work');
    assertCoordinatorRouteMapCondition(($siteOne['incidents_summary'][0]['id'] ?? null) === 101 && ($siteOne['incidents_summary'][0]['assigned_technician_name'] ?? null) === 'Técnico Dos', 'Corrective summary includes only its contracted safe fields');
    assertCoordinatorRouteMapCondition(!isset($siteOne['contact_phone'], $siteOne['contact_name'], $siteOne['incidents_summary'][0]['reporter_phone'], $siteOne['incidents_summary'][0]['description']), 'Map omits site contact and private incident details');

    $technicianFilter = $controller->getActiveIncidents(makeCoordinatorRouteMapRequest(query: ['technician_id' => '2']));
    $techFilterBody = $technicianFilter->getDecodedBody();
    assertCoordinatorRouteMapCondition(($techFilterBody['data']['total_locations'] ?? -1) === 2 && ($techFilterBody['data']['total_active_tasks'] ?? -1) === 2, 'Technician filter retains only the technician\'s active assigned corrective work');
    $unassignedFilter = $controller->getActiveIncidents(makeCoordinatorRouteMapRequest(query: ['unassigned_only' => '1']));
    $unassignedBody = $unassignedFilter->getDecodedBody();
    assertCoordinatorRouteMapCondition(($unassignedBody['data']['total_locations'] ?? -1) === 1 && ($unassignedBody['data']['total_active_tasks'] ?? -1) === 3, 'Unassigned-only filter includes sites with unassigned incidents');
    $criticalFilter = $controller->getActiveIncidents(makeCoordinatorRouteMapRequest(query: ['is_critical_only' => '1']));
    $criticalBody = $criticalFilter->getDecodedBody();
    assertCoordinatorRouteMapCondition(($criticalBody['data']['total_locations'] ?? -1) === 1 && ($criticalBody['data']['locations'][0]['location_id'] ?? null) === 1, 'Critical-only filter shows sites with active perishable cold-chain risk');
    $invalidFilter = $controller->getActiveIncidents(makeCoordinatorRouteMapRequest(query: ['technician_id' => '1.5']));
    assertCoordinatorRouteMapCondition($invalidFilter->getStatusCode() === 400, 'Invalid technician filters are rejected before repository access');

    $settingsResponse = $controller->getRouteSettings(makeCoordinatorRouteMapRequest(path: '/api/coordinator/route/settings'));
    $settingsBody = $settingsResponse->getDecodedBody();
    assertCoordinatorRouteMapCondition(($settingsBody['data']['base_name'] ?? null) === 'Base Central VendGuard' && ($settingsBody['data']['updated_at'] ?? null) === '2026-09-30T09:00:00+02:00', 'GET settings returns explicit base fields and a timezone-aware update timestamp without exposing territorial bounds');
    $validSettings = [
        'base_name' => 'Taller VendGuard',
        'base_address' => 'Carrer Nova 15, Barcelona',
        'base_latitude' => 41.3972,
        'base_longitude' => 2.1883,
        'operational_radius_km' => 80,
    ];
    $putResponse = $controller->updateRouteSettings(makeCoordinatorRouteMapRequest('PUT', '/api/coordinator/route/settings', body: $validSettings));
    $putBody = $putResponse->getDecodedBody();
    assertCoordinatorRouteMapCondition($putResponse->getStatusCode() === 200 && $settingsRepository->updateCalls === 1, 'PUT settings persists a valid update');
    assertCoordinatorRouteMapCondition(($putBody['data']['base_name'] ?? null) === 'Taller VendGuard' && ($putBody['data']['operational_radius_km'] ?? null) === 80 && ($putBody['message'] ?? null) === 'Configuración de ruta y Base Central actualizada correctamente.', 'PUT response matches the central-base settings contract');
    $invalidSettings = $controller->updateRouteSettings(makeCoordinatorRouteMapRequest('PUT', '/api/coordinator/route/settings', body: array_merge($validSettings, ['base_latitude' => 'north'])));
    assertCoordinatorRouteMapCondition($invalidSettings->getStatusCode() === 422 && ($invalidSettings->getDecodedBody()['error']['code'] ?? null) === 'INVALID_COORDINATES', 'PUT rejects non-numeric coordinate fields');
    $outsideSettings = $controller->updateRouteSettings(makeCoordinatorRouteMapRequest('PUT', '/api/coordinator/route/settings', body: array_merge($validSettings, ['base_latitude' => 26.9])));
    assertCoordinatorRouteMapCondition($outsideSettings->getStatusCode() === 422 && ($outsideSettings->getDecodedBody()['error']['code'] ?? null) === 'COORDINATES_OUT_OF_BOUNDS', 'PUT rejects a base outside the configured operational territory');
    $missingSettings = $controller->updateRouteSettings(makeCoordinatorRouteMapRequest('PUT', '/api/coordinator/route/settings', body: ['base_name' => 'Incomplete']));
    assertCoordinatorRouteMapCondition($missingSettings->getStatusCode() === 422, 'PUT requires every documented settings field');
    assertCoordinatorRouteMapCondition($settingsRepository->updateCalls === 1, 'Invalid settings requests do not persist changes');

    $forbidden = $controller->getRouteSettings(makeCoordinatorRouteMapRequest(path: '/api/coordinator/route/settings', userId: 4, role: 'TECHNICIAN'));
    assertCoordinatorRouteMapCondition($forbidden->getStatusCode() === 403, 'Non-coordinators receive 403 from settings endpoints');
    assertCoordinatorRouteMapCondition($controller->getActiveIncidents(new Request())->getStatusCode() === 401, 'Unauthenticated controller calls receive 401');

    $router = AppRouter::create();
    $paths = [
        ['GET', '/api/coordinator/map/active-incidents'],
        ['GET', '/api/coordinator/route/settings'],
        ['PUT', '/api/coordinator/route/settings'],
    ];
    foreach ($paths as [$method, $path]) {
        assertCoordinatorRouteMapCondition($router->dispatch(new Request($method, $path))->getStatusCode() === 401, "{$method} {$path} requires authentication");
    }
    $user = new User(8, 'Coordinator test', 'coordinator@example.test', 'hash', UserRole::COORDINATOR);
    $userRepository = new CoordinatorMapUserRepositoryStub($user);
    $authService = new AuthService(new CoordinatorMapLocationRepositoryStub([]), $userRepository);
    $token = $authService->generateInternalToken($user);
    $technician = new User(9, 'Technician test', 'technician@example.test', 'hash', UserRole::TECHNICIAN);
    $technicianRepository = new CoordinatorMapUserRepositoryStub($technician);
    $technicianAuthService = new AuthService(new CoordinatorMapLocationRepositoryStub([]), $technicianRepository);
    $technicianToken = $technicianAuthService->generateInternalToken($technician);
    $rbac = new InternalAuthMiddleware(UserRole::COORDINATOR, $technicianAuthService, $technicianRepository);
    $wrongRoleResponse = $rbac->handle(
        new Request('GET', '/api/coordinator/map/active-incidents', [], [], ['Authorization' => 'Bearer ' . $technicianToken]),
        static fn(Request $request): Response => Response::json(['reached' => true])
    );
    assertCoordinatorRouteMapCondition($wrongRoleResponse->getStatusCode() === 403, 'Valid non-coordinator tokens are denied coordinator map access');

    echo "CoordinatorRouteMapController tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "CoordinatorRouteMapController test failed: {$exception->getMessage()}\n");
    exit(1);
}
