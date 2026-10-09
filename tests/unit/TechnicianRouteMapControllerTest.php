<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\RouteOptimizationService;
use VendGuard\Application\Service\RouteSettingsService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianRouteMapController;
use VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

$assertions = 0;

function assertTechnicianRouteMapCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, mixed> $query
 */
function makeTechnicianRouteMapRequest(array $query = [], int $userId = 7, string $role = 'TECHNICIAN'): Request
{
    $request = new Request('GET', '/api/technician/route/map', $query);
    $request->setAttribute('user_id', $userId);
    $request->setAttribute('user_role', $role);
    return $request;
}

final class RouteMapIncidentRepositoryStub implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function __construct(private readonly array $incidents) {}
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException(); }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return $this->incidents; }
    public function update(Incident $incident): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 0; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $comment): \VendGuard\Core\Domain\Model\IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new LogicException(); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException(); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException(); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException(); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException(); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException(); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

final class RouteMapPreventiveOrderRepositoryStub implements PreventiveOrderRepositoryInterface
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
    public function findForCoordinatorList(array $filters = []): array { return []; }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return $this->orders; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

final class RouteMapMachineRepositoryStub implements MachineRepositoryInterface
{
    public function __construct(private readonly array $machines) {}
    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return $this->machines[$id] ?? null; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return false; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function softDelete(int $id): bool { return false; }
}

final class RouteMapLocationRepositoryStub implements LocationRepositoryInterface
{
    public function __construct(private readonly array $locations) {}
    public function findBySiteCode(string $siteCode): ?Location { return null; }
    public function findById(int $id): ?Location { return $this->locations[$id] ?? null; }
    public function findAllActive(): array { return []; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

final class RouteMapSettingsRepositoryStub implements RouteSettingsRepositoryInterface
{
    public function find(): ?RouteSettings { return new RouteSettings(); }
    public function update(RouteSettings $settings): bool { return true; }
}

final class RouteMapUserRepositoryStub implements UserRepositoryInterface
{
    private User $coordinator;

    public function __construct()
    {
        $this->coordinator = new User(8, 'Coordinador de prueba', 'coordinator@example.test', 'hash', UserRole::COORDINATOR);
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User { return $id === 8 ? $this->coordinator : null; }
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

    public function coordinator(): User
    {
        return $this->coordinator;
    }
}

$settingsRepository = new RouteMapSettingsRepositoryStub();
$settingsService = new RouteSettingsService($settingsRepository, new GeoDistanceService());
$location = new Location(1, 'SEDE-MAP-01', 'Sede Ruta', 'Carrer Prova 1', null, null, true, null, null, null, 41.40, 2.19);
$criticalLocation = new Location(2, 'SEDE-MAP-02', 'Sede Perecedera', 'Carrer Crític 2', null, null, true, null, null, null, 41.41, 2.20);
$machine = new Machine(10, 1, 'VEND-MAP-01', 'Modelo prueba', MachineType::HOT_DRINKS, 'Planta 1');
$criticalMachine = new Machine(11, 2, 'VEND-MAP-02', 'Modelo frío', MachineType::PERISHABLE_FOOD, 'Planta Baja');
$criticalCreatedAt = (new DateTimeImmutable('now'))->modify('-10 minutes')->format('Y-m-d H:i:s');
$criticalIncident = new Incident(
    id: 102,
    ticketCode: 'INC-MAP-102',
    machineId: 11,
    locationId: 2,
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Avería de cadena de frío para prueba.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: 7,
    createdAt: $criticalCreatedAt,
    machineCode: 'VEND-MAP-02',
    machineType: 'PERISHABLE_FOOD',
    locationName: 'Sede Crítica'
);
$incident = new Incident(
    id: 101,
    ticketCode: 'INC-MAP-101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Avería de prueba en ruta.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: 7,
    createdAt: '2026-09-29 10:00:00',
    machineCode: 'VEND-MAP-01',
    machineType: 'HOT_DRINKS',
    locationName: 'Sede Ruta'
);
$preventiveOrder = new PreventiveOrder(
    201, 'PREV-MAP-201', 10, 1, 7, 'SCHEDULED', 'ROUTINE', '2026-09-30', '2026-10-01',
    machineData: ['code' => 'VEND-MAP-01', 'machine_type' => 'HOT_DRINKS', 'floor_wing' => 'Planta 1'],
    locationData: ['id' => 1, 'site_code' => 'SEDE-MAP-01', 'name' => 'Sede Ruta', 'address' => 'Carrer Prova 1']
);

$controller = new TechnicianRouteMapController(
    new RouteMapIncidentRepositoryStub([$incident, $criticalIncident]),
    new RouteMapPreventiveOrderRepositoryStub([$preventiveOrder]),
    new RouteMapMachineRepositoryStub([10 => $machine, 11 => $criticalMachine]),
    new RouteMapLocationRepositoryStub([1 => $location, 2 => $criticalLocation]),
    $settingsService,
    new RouteOptimizationService(new GeoDistanceService())
);

try {
    $response = $controller->getRouteMap(makeTechnicianRouteMapRequest([
        'origin_lat' => '41.3935',
        'origin_lng' => '2.189',
    ]));
    $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition($response->getStatusCode() === 200, 'Authenticated technician receives 200 OK');
    assertTechnicianRouteMapCondition(($body['success'] ?? false) === true, 'Successful response uses the JSON API envelope');
    assertTechnicianRouteMapCondition(($body['data']['origin']['source'] ?? null) === 'GPS', 'Valid query coordinates are used as GPS origin');
    assertTechnicianRouteMapCondition(($body['data']['summary']['total_tasks'] ?? 0) === 3, 'Corrective and preventive work is included in route totals');
    assertTechnicianRouteMapCondition(count($body['data']['stops'] ?? []) === 2, 'Work is grouped into one stop per physical location');
    assertTechnicianRouteMapCondition(
        array_column($body['data']['stops'], 'order') === [1, 2],
        'Route stop numbering is sequential and correlated'
    );
    assertTechnicianRouteMapCondition(
        ($body['data']['summary']['total_critical'] ?? -1) === 1
            && ($body['data']['stops'][0]['location']['id'] ?? null) === 2,
        'Critical perishable work inherits priority and is sequenced before ordinary work'
    );
    $ordinaryTasks = $body['data']['stops'][1]['tasks'] ?? [];
    assertTechnicianRouteMapCondition(
        count(array_filter($ordinaryTasks, static fn(array $task): bool => $task['type'] === 'CORRECTIVE')) === 1
            && count(array_filter($ordinaryTasks, static fn(array $task): bool => $task['type'] === 'PREVENTIVE')) === 1,
        'A stop combines corrective and preventive task payloads by location'
    );
    assertTechnicianRouteMapCondition(
        !isset($ordinaryTasks[0]['reporter_phone'], $ordinaryTasks[0]['resolution_diagnosis'])
            && !isset($body['data']['stops'][1]['location']['contact_phone']),
        'Route payload excludes private contact and incident-resolution fields'
    );
    assertTechnicianRouteMapCondition(
        str_contains((string)($body['data']['stops'][0]['navigation_url'] ?? ''), 'google.com/maps/dir/?api=1')
            && str_contains((string)($body['data']['full_route_navigation_url'] ?? ''), 'google.com/maps/dir/?api=1'),
        'Individual and complete-route navigation URLs use Google Maps universal links'
    );

    $fallbackResponse = $controller->getRouteMap(makeTechnicianRouteMapRequest());
    $fallbackBody = json_decode($fallbackResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition(
        ($fallbackBody['data']['origin']['source'] ?? null) === 'BASE_CENTRAL'
            && ($fallbackBody['data']['origin']['latitude'] ?? null) === 41.3935
            && ($fallbackBody['data']['origin']['longitude'] ?? null) === 2.189,
        'Absent GPS query parameters fall back to the configured central-base coordinates'
    );
    $partialCoordinatesResponse = $controller->getRouteMap(makeTechnicianRouteMapRequest(['origin_lat' => '41.39']));
    $partialCoordinatesBody = json_decode($partialCoordinatesResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition(
        $partialCoordinatesResponse->getStatusCode() === 422
            && ($partialCoordinatesBody['error']['code'] ?? null) === 'INVALID_ORIGIN_COORDINATES',
        'A partial GPS coordinate pair is rejected with the documented validation error'
    );

    $badCoordinatesResponse = $controller->getRouteMap(makeTechnicianRouteMapRequest([
        'origin_lat' => 'not-a-number',
        'origin_lng' => '2.19',
    ]));
    $badCoordinatesBody = json_decode($badCoordinatesResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition(
        $badCoordinatesResponse->getStatusCode() === 422
            && ($badCoordinatesBody['error']['code'] ?? null) === 'INVALID_ORIGIN_COORDINATES',
        'Malformed GPS query values return the specified 422 error'
    );
    $outOfAreaCoordinatesResponse = $controller->getRouteMap(makeTechnicianRouteMapRequest([
        'origin_lat' => '0',
        'origin_lng' => '0',
    ]));
    $outOfAreaBody = json_decode($outOfAreaCoordinatesResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition(
        $outOfAreaCoordinatesResponse->getStatusCode() === 200
            && ($outOfAreaBody['data']['origin']['source'] ?? null) === 'BASE_CENTRAL',
        'Out-of-area numeric GPS coordinates fall back to the central base'
    );

    $forbiddenResponse = $controller->getRouteMap(makeTechnicianRouteMapRequest([], 8, 'COORDINATOR'));
    $forbiddenBody = json_decode($forbiddenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    assertTechnicianRouteMapCondition(
        $forbiddenResponse->getStatusCode() === 403
            && ($forbiddenBody['error']['code'] ?? null) === 'FORBIDDEN',
        'Authenticated non-technicians are denied with 403 Forbidden'
    );

    $router = AppRouter::create();
    assertTechnicianRouteMapCondition(
        $router->dispatch(new Request('GET', '/api/technician/route/map'))->getStatusCode() === 401,
        'Router rejects unauthenticated route-map requests with 401'
    );
    assertTechnicianRouteMapCondition(
        $router->dispatch(new Request('GET', '/api/technician/route/map', [], [], ['Authorization' => 'Bearer invalid']))->getStatusCode() === 401,
        'Router rejects invalid route-map bearer tokens with 401'
    );
    $userRepository = new RouteMapUserRepositoryStub();
    $authService = new AuthService(new RouteMapLocationRepositoryStub([]), $userRepository);
    $coordinatorToken = $authService->generateInternalToken($userRepository->coordinator());
    $rbacMiddleware = new InternalAuthMiddleware(UserRole::TECHNICIAN, $authService, $userRepository);
    $coordinatorResponse = $rbacMiddleware->handle(
        new Request('GET', '/api/technician/route/map', [], [], ['Authorization' => 'Bearer ' . $coordinatorToken]),
        static fn(Request $request): Response => Response::json(['reached' => true])
    );
    assertTechnicianRouteMapCondition(
        $coordinatorResponse->getStatusCode() === 403,
        'RBAC middleware rejects a valid coordinator token for technician routes with 403'
    );

    echo "TechnicianRouteMapController tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "TechnicianRouteMapController test failed: {$exception->getMessage()}\n");
    exit(1);
}
