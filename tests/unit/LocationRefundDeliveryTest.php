<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `LocationRefundController` (T-REF-11).
 *
 * The "Hecho cuando" criterion asks for an anonymised, bank-data-free list of
 * the site's refunds and a hand delivery gated behind the four-digit PIN.
 *
 * The properties that matter more than the happy path are asserted first:
 *
 * 1. The reception screen never shows money data. It is a shared terminal in
 *    plain sight of the queue, and the person collecting is standing right
 *    there, so an IBAN, a Bizum phone or even the pickup PIN on that screen is a
 *    leak to whoever is behind the counter.
 * 2. A wrong PIN changes NOTHING. Not the state, not the cash, not the audit.
 *    A delivery that half-succeeds hands money to the wrong person.
 * 3. Site segregation. A concierge of one building must not be able to read or
 *    release another building's money.
 *
 * No database is touched: every repository is an in-memory fake and the real
 * `RefundManagementService` runs on top of them.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\LocationRefundController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

const SITE_ID = 1;
const OTHER_SITE_ID = 2;
const MACHINE_ID = 11;
const INCIDENT_ID = 101;

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

final class FaultedResponse
{
    public function __construct(public readonly string $fault)
    {
    }

    public function getStatusCode(): int
    {
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecodedBody(): array
    {
        return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// In-memory fakes
// ─────────────────────────────────────────────────────────────────────────────

final class DeskMachineRepo implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[$id] ?? null;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        foreach ($this->machines as $machine) {
            if ($machine->getCode() === $code) {
                return $machine;
            }
        }

        return null;
    }

    public function findActiveByLocationId(int $locationId): array
    {
        return array_values(array_filter(
            $this->machines,
            static fn (Machine $m): bool => $m->getLocationId() === $locationId
        ));
    }

    public function create(array $data): Machine
    {
        throw new LogicException('Not used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function findAll(array $filters = []): array
    {
        return array_values($this->machines);
    }

    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return false;
    }

    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        return null;
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }
}

final class DeskLocationRepo implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $rows = [];

    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        foreach ($this->rows as $location) {
            if ($location->getSiteCode() === $siteCode) {
                return $location;
            }
        }

        return null;
    }

    public function findAllActive(): array
    {
        return array_values($this->rows);
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        return array_values($this->rows);
    }

    public function create(array $data): Location
    {
        throw new LogicException('Not used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function restore(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function countActiveMachines(int $locationId): int
    {
        return 0;
    }
}

final class DeskIncidentRepo implements IncidentRepositoryInterface
{
    /** @var array<int, Incident> */
    public array $rows = [];

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function findById(int $id): ?Incident
    {
        return $this->rows[$id] ?? null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        foreach ($this->rows as $row) {
            if ($row->getTicketCode() === $ticketCode) {
                return $row;
            }
        }

        return null;
    }

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        return null;
    }

    public function findAllByLocation(int $locationId, bool $activeOnly = false): array
    {
        return [];
    }

    public function findAll(array $filters = []): array
    {
        return array_values($this->rows);
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [];
    }

    public function update(Incident $incident): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function insertHistory(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null
    ): int {
        return 1;
    }

    public function getHistory(int $incidentId): array
    {
        return [];
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        return $comment;
    }

    public function getComments(int $incidentId, bool $includeInternal = true): array
    {
        return [];
    }

    public function countReopenEvents(int $incidentId): int
    {
        return 0;
    }

    public function markAsChronic(int $incidentId): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): Incident {
        throw new LogicException('Not used in this suite.');
    }

    public function reopen(int $incidentId, string $reasonText): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }
}

function rebuildDeskCase(RefundRequest $case, array $overrides = []): RefundRequest
{
    $pick = static function (string $key, mixed $default) use ($overrides): mixed {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    };

    return new RefundRequest(
        id: $pick('id', $case->getId()),
        incidentId: (int)$pick('incidentId', $case->getIncidentId()),
        machineId: (int)$pick('machineId', $case->getMachineId()),
        locationId: (int)$pick('locationId', $case->getLocationId()),
        claimantName: (string)$pick('claimantName', $case->getClaimantName()),
        claimantContact: (string)$pick('claimantContact', $case->getClaimantContact()),
        claimedAmount: (float)$pick('claimedAmount', $case->getClaimedAmount()),
        productAttempted: (string)$pick('productAttempted', $case->getProductAttempted()),
        compensationMethod: $pick('compensationMethod', $case->getCompensationMethod()),
        bizumPhone: $pick('bizumPhone', $case->getBizumPhone()),
        iban: $pick('iban', $case->getIban()),
        pickupPin: $pick('pickupPin', $case->getPickupPin()),
        trackingToken: (string)$pick('trackingToken', $case->getTrackingToken()),
        status: $pick('status', $case->getStatus()),
        technicianFinding: $pick('technicianFinding', $case->getTechnicianFinding()),
        recoveredAmount: $pick('recoveredAmount', $case->getRecoveredAmount()),
        cashCustodyAction: $pick('cashCustodyAction', $case->getCashCustodyAction()),
        receptionistName: $pick('receptionistName', $case->getReceptionistName()),
        technicianJustification: $pick('technicianJustification', $case->getTechnicianJustification()),
        approvedAmount: $pick('approvedAmount', $case->getApprovedAmount()),
        paymentReference: $pick('paymentReference', $case->getPaymentReference()),
        isActive: (bool)$pick('isActive', $case->isActive()),
        createdAt: $pick('createdAt', $case->getCreatedAt()),
        updatedAt: $pick('updatedAt', $case->getUpdatedAt())
    );
}

final class DeskRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildDeskCase($refundRequest, ['id' => $id]);

        return $id;
    }

    public function findById(int $id): ?RefundRequest
    {
        return $this->rows[$id] ?? null;
    }

    public function findByTrackingToken(string $trackingToken): ?RefundRequest
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row->getTrackingToken(), $trackingToken)) {
                return $row;
            }
        }

        return null;
    }

    public function findRestrictedByIncident(int $incidentId): array
    {
        return array_values(array_filter($this->rows, static fn (RefundRequest $r): bool => $r->getIncidentId() === $incidentId));
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        return array_values(array_filter($this->rows, static function (RefundRequest $r) use ($locationId, $status): bool {
            return $r->getLocationId() === $locationId && ($status === null || $r->getStatus() === $status);
        }));
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice(array_values($this->rows), $offset, $limit);
    }

    public function countForCoordinator(array $filters = []): int
    {
        return count($this->rows);
    }

    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null || $current->getStatus() !== $expectedStatus) {
            return false;
        }

        $entityFields = [];
        foreach ($fields as $column => $value) {
            $entityFields[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string)$column))))] = $value;
        }

        $this->rows[$id] = rebuildDeskCase($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        return false;
    }

    public function deactivate(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildDeskCase($this->rows[$id], ['isActive' => false]);

        return true;
    }
}

final class DeskAuditRepo implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter($this->events, static fn (AuditEvent $e): bool => $e->getEntityType() === $entityType));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

$refundRepo = new DeskRefundRepo();
$auditRepo = new DeskAuditRepo();
$incidentRepo = new DeskIncidentRepo();
$machineRepo = new DeskMachineRepo();
$locationRepo = new DeskLocationRepo();

$site = new Location(
    id: SITE_ID,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Carrer del Mar, 1',
    contactName: 'Imposada Recepción',
    contactPhone: '933001122',
    isActive: true,
    createdAt: '2026-01-01 09:00:00',
    latitude: 41.38,
    longitude: 2.18
);
$otherSite = new Location(
    id: OTHER_SITE_ID,
    siteCode: 'SEDE-BCN-02',
    name: 'Clínica Mar - Annexo',
    address: 'Carrer del Mar, 9',
    isActive: true,
    createdAt: '2026-01-01 09:00:00',
    latitude: 41.39,
    longitude: 2.19
);
$locationRepo->rows[SITE_ID] = $site;
$locationRepo->rows[OTHER_SITE_ID] = $otherSite;

$machineRepo->machines[MACHINE_ID] = new Machine(
    id: MACHINE_ID,
    locationId: SITE_ID,
    code: 'VEND-0101',
    model: 'VendGuard 300',
    machineType: MachineType::COMBO,
    floorWing: 'Planta 0',
    isActive: true,
    createdAt: '2026-10-01 09:00:00'
);

$incidentRepo->rows[INCIDENT_ID] = new Incident(
    id: INCIDENT_ID,
    ticketCode: 'INC-2026-0001',
    machineId: MACHINE_ID,
    locationId: SITE_ID,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'La máquina cobra y no entrega el producto.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::RESOLVED,
    assignedTechnicianId: 7
);

$management = new RefundManagementService($refundRepo, new IbanValidationService(), new AuditLogger($auditRepo));

$controller = new LocationRefundController($refundRepo, $locationRepo, $incidentRepo, $machineRepo, $management);

/**
 * Opens a claim and, when asked, walks it to the desk so there is an envelope
 * to hand over.
 */
$makeCase = static function (
    CompensationMethod $method,
    float $amount,
    bool $depositAtReception = false,
    int $locationId = SITE_ID,
    string $name = 'Laura Sanitaria'
) use ($management, $refundRepo): RefundRequest {
    $case = $management->createCase(new CreateRefundRequestDTO(
        incidentId: INCIDENT_ID,
        machineId: MACHINE_ID,
        locationId: $locationId,
        claimantName: $name,
        claimantContact: '600111222',
        claimedAmount: $amount,
        compensationMethod: $method,
        productAttempted: 'Café con leche carril 2',
        bizumPhone: $method->requiresBizumPhone() ? '600111222' : null,
        iban: $method->requiresIban() ? 'ES9121000418450200051332' : null
    ));

    if ($depositAtReception) {
        $refundRepo->transitionStatus(
            (int)$case->getId(),
            RefundStatus::PENDING_INSPECTION,
            RefundStatus::DEPOSITED_AT_RECEPTION
        );
    }

    return $refundRepo->findById((int)$case->getId());
};

$deskRequest = static function (string $method = 'GET', array $body = [], string $rawId = ''): Request {
    $path = $method === 'GET'
        ? '/api/location/refunds'
        : '/api/location/refunds/' . $rawId . '/deliver';

    $request = new Request(
        method: $method,
        path: $path,
        parsedBody: $body,
        headers: ['content-type' => 'application/json']
    );

    if ($method !== 'GET') {
        $request->setRouteParams(['id' => $rawId]);
    }

    // El middleware de sede inyecta `location_id`; aquí se simula el portal real.
    $request->setAttribute('location_id', SITE_ID);
    $request->setAttribute('user_id', 42);
    $request->setAttribute('user_role', 'LOCATION_MANAGER');

    return $request;
};

$invokeIndex = static function (Request $request) use ($controller): Response|FaultedResponse {
    try {
        return $controller->index($request);
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$invokeDeliver = static function (Request $request) use ($controller): Response|FaultedResponse {
    try {
        return $controller->deliver($request);
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$statusDetail = static fn (Response|FaultedResponse $response, string $prefix = 'estado: '): string => $response instanceof FaultedResponse
    ? 'fallo inesperado -> ' . $response->fault
    : $prefix . $response->getStatusCode();

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla ---\n";

$ctlSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/LocationRefundController.php');
$viewSource = (string)file_get_contents($baseDir . '/src/Application/DTO/LocationRefundViewDTO.php');

$assert('0.1 El controlador declara tipado estricto', str_contains($ctlSource, 'declare(strict_types=1);'));
$assert('0.2 El DTO de conserjería declara tipado estricto', str_contains($viewSource, 'declare(strict_types=1);'));
$assert('0.3 El controlador NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($ctlSource), 'DELETE FROM'));
$assert('0.4 El DTO de conserjería NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($viewSource), 'DELETE FROM'));

// ─────────────────────────────────────────────────────────────────────────────
// 1. El listado del mostrador
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. GET /api/location/refunds: los sobres del mostrador ---\n";

// El orden importa y sólo es observable si hay más de un expediente: se crea
// primero uno que NO está en el mostrador y después el sobre que sí lo está.
$waitingFirst = $makeCase(CompensationMethod::EN_MANO_SEDE, 6.00);
$atDesk = $makeCase(CompensationMethod::EN_MANO_SEDE, 2.50, true);
$pending = $makeCase(CompensationMethod::BIZUM, 4.00);
$otherSiteCase = $makeCase(CompensationMethod::EN_MANO_SEDE, 1.00, true, OTHER_SITE_ID);

$listed = $invokeIndex($deskRequest());
$listedBody = $listed->getDecodedBody();

$assert('1.1 El listado devuelve HTTP 200', $listed->getStatusCode() === 200, $statusDetail($listed));
$assert('1.2 Cuenta sólo los expedientes de la sede', ($listedBody['data']['total'] ?? 0) === 3, 'total: ' . ($listedBody['data']['total'] ?? 'AUSENTE'));
$assert('1.3 Declara cuántos están listos para entregar', ($listedBody['data']['ready_for_pickup_total'] ?? 0) === 1);
// Comprobarlo sobre el JSON entero colisionaría: los ids son enteros cortos y
// "4" aparece dentro de "4.00". Se compara contra la lista de ids reales.
$listedIds = array_map(
    static fn (array $row): int => (int)$row['id'],
    $listedBody['data']['refunds'] ?? []
);
$assert(
    '1.4 El expediente de OTRA sede no aparece en el listado',
    !in_array((int)$otherSiteCase->getId(), $listedIds, true),
    'ids listados: ' . implode(',', $listedIds)
);
$assert(
    '1.5 El sobre en el mostrador va primero aunque se crease después',
    (int)($listedBody['data']['refunds'][0]['id'] ?? 0) === (int)$atDesk->getId(),
    'primero: ' . ($listedBody['data']['refunds'][0]['id'] ?? 'AUSENTE') . ' esperado: ' . $atDesk->getId()
);
$assert('1.6 El sobre del mostrador está marcado como listo', ($listedBody['data']['refunds'][0]['ready_for_pickup'] ?? false) === true);
$assert('1.7 El nombre del reclamante va anonimizado', ($listedBody['data']['refunds'][0]['claimant_name_anon'] ?? '') === 'Laura S.', 'anon: ' . ($listedBody['data']['refunds'][0]['claimant_name_anon'] ?? 'AUSENTE'));
$assert('1.8 Expone el código de la avería de origen', ($listedBody['data']['refunds'][0]['incident_code'] ?? '') === 'INC-2026-0001');
$assert('1.9 Expone el código de máquina', ($listedBody['data']['refunds'][0]['machine_code'] ?? '') === 'VEND-0101');
$assert('1.10 Expone el importe reclamado', (float)($listedBody['data']['refunds'][0]['claimed_amount'] ?? 0) === 2.50);
$assert('1.11 Expone la etiqueta en castellano del estado', ($listedBody['data']['refunds'][0]['status_label'] ?? '') !== '');

// ─────────────────────────────────────────────────────────────────────────────
// 2. La pantalla de conserjería nunca filtra datos financieros (Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. El mostrador NO ve IBAN, Bizum ni PIN ---\n";

$transferCase = $makeCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 12.00, name: 'Marc Ruibal');
$listedWithTransfer = $invokeIndex($deskRequest());
$listedWithTransferJson = (string)json_encode($listedWithTransfer->getDecodedBody(), JSON_THROW_ON_ERROR);

$assert('2.1 El IBAN nunca aparece en el listado', !str_contains($listedWithTransferJson, 'ES9121000418450200051332'));
$assert('2.2 La clave `iban` no existe en el payload', !str_contains($listedWithTransferJson, '"iban"'));
$assert('2.3 La clave `bizum_phone` no existe en el payload', !str_contains($listedWithTransferJson, 'bizum_phone'));
$assert('2.4 El PIN de recogida NO se muestra en el listado', !str_contains($listedWithTransferJson, 'pickup_pin'));
$assert('2.5 Ningún nombre completo de reclamante aparece', !str_contains($listedWithTransferJson, 'Laura Sanitaria') && !str_contains($listedWithTransferJson, 'Marc Ruibal'));
$assert('2.6 El teléfono de contacto del reclamante tampoco', !str_contains($listedWithTransferJson, '600111222'));
$assert('2.7 El token de seguimiento tampoco', !str_contains($listedWithTransferJson, 'tracking_token'));
$assert(
    '2.8 Control de no-vacuidad: el PIN SÍ existe en el dominio que se oculta',
    preg_match('/^[0-9]{4}$/', (string)$atDesk->getPickupPin()) === 1,
    'pin persistido: ' . var_export($atDesk->getPickupPin(), true)
);
$assert(
    '2.9 Control de no-vacuidad: el IBAN SÍ existe en el dominio que se oculta',
    $transferCase->getIban() === 'ES9121000418450200051332'
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Entrega presencial con PIN correcto (RF-REF-06)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Entrega en mano con el PIN correcto ---\n";

$eventsBeforeDelivery = count($auditRepo->events);
$delivered = $invokeDeliver($deskRequest('POST', ['pickup_pin' => $atDesk->getPickupPin()], (string)$atDesk->getId()));
$deliveredBody = $delivered->getDecodedBody();
$deliveredRow = $refundRepo->findById((int)$atDesk->getId());

$assert('3.1 Con el PIN correcto se responde HTTP 200', $delivered->getStatusCode() === 200, $statusDetail($delivered));
$assert('3.2 El expediente pasa a REFUNDED_IN_HAND', $deliveredRow?->getStatus() === RefundStatus::REFUNDED_IN_HAND);
$assert('3.3 La respuesta expone el nuevo estado', ($deliveredBody['data']['status'] ?? '') === 'REFUNDED_IN_HAND');
$assert('3.4 La respuesta identifica el expediente entregado', ($deliveredBody['data']['id'] ?? 0) === (int)$atDesk->getId());
$assert('3.5 El mensaje confirma la entrega en castellano', str_contains((string)($deliveredBody['message'] ?? ''), 'Entrega presencial'));
$assert('3.6 La entrega queda auditada', count($auditRepo->events) > $eventsBeforeDelivery);
$assert(
    '3.7 Control de no-vacuidad: existe el evento REFUND_DELIVERED_IN_HAND',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_DELIVERED_IN_HAND')) === 1
);
$assert(
    '3.8 La auditoría NO arrastra el PIN de recogida',
    !str_contains((string)json_encode(array_map(static fn (AuditEvent $e): array => $e->toArray(), $auditRepo->events), JSON_THROW_ON_ERROR), (string)$atDesk->getPickupPin())
);

// Un sobre ya entregado no se vuelve a entregar: la arista REFUNDED_IN_HAND ->
// REFUNDED_IN_HAND no existe en la máquina de estados, así que el catálogo la
// tipifica como 409 INVALID_REFUND_STATE_TRANSITION (contrato §5).
$twice = $invokeDeliver($deskRequest('POST', ['pickup_pin' => $atDesk->getPickupPin()], (string)$atDesk->getId()));
$twiceBody = $twice->getDecodedBody();
$assert('3.9 Un expediente ya entregado se responde 409', $twice->getStatusCode() === 409, $statusDetail($twice));
$assert(
    '3.10 El 409 expone INVALID_REFUND_STATE_TRANSITION',
    ($twiceBody['error']['code'] ?? '') === 'INVALID_REFUND_STATE_TRANSITION',
    'error: ' . ($twiceBody['error']['code'] ?? 'AUSENTE')
);
$assert(
    '3.11 Control de no-vacuidad: el estado no se altera al reintentar',
    $refundRepo->findById((int)$atDesk->getId())?->getStatus() === RefundStatus::REFUNDED_IN_HAND
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Un PIN incorrecto NO cambia absolutamente nada
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Un PIN incorrecto no suelta nada ---\n";

$guarded = $makeCase(CompensationMethod::EN_MANO_SEDE, 3.00, true);
$guardedEventsBefore = count($auditRepo->events);

$wrongPin = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '0000'], (string)$guarded->getId()));
$wrongPinBody = $wrongPin->getDecodedBody();

$assert('4.1 Con un PIN incorrecto se responde 422', $wrongPin->getStatusCode() === 422, $statusDetail($wrongPin));
$assert(
    '4.2 El 422 expone INVALID_PICKUP_PIN',
    ($wrongPinBody['error']['code'] ?? '') === 'INVALID_PICKUP_PIN',
    'error: ' . ($wrongPinBody['error']['code'] ?? 'AUSENTE')
);
$assert('4.3 El mensaje no reproduce el PIN introducido', !str_contains((string)($wrongPinBody['error']['message'] ?? ''), '0000'));
$assert(
    '4.4 El expediente sigue en el mostrador, sin entregar',
    $refundRepo->findById((int)$guarded->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);
$assert('4.5 No se registra ninguna entrega fallida en la auditoría', count($auditRepo->events) === $guardedEventsBefore);
$assert(
    '4.6 Control de no-vacuidad: el PIN almacenado NO era el introducido',
    $guarded->getPickupPin() !== '0000',
    'pin almacenado: ' . var_export($guarded->getPickupPin(), true)
);

// Tras el fallo el usuario puede reintentar con su PIN correcto
$retry = $invokeDeliver($deskRequest('POST', ['pickup_pin' => $guarded->getPickupPin()], (string)$guarded->getId()));
$assert('4.7 Tras el fallo, el PIN correcto sí entrega', $retry->getStatusCode() === 200, $statusDetail($retry));
$assert('4.8 Y el expediente queda entregado', $refundRepo->findById((int)$guarded->getId())?->getStatus() === RefundStatus::REFUNDED_IN_HAND);

// ─────────────────────────────────────────────────────────────────────────────
// 5. PIN mal formado o ausente
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. PIN ausente o mal formado ---\n";

$stillDesk = $makeCase(CompensationMethod::EN_MANO_SEDE, 2.00, true);

$missing = $invokeDeliver($deskRequest('POST', [], (string)$stillDesk->getId()));
$missingBody = $missing->getDecodedBody();
$assert('5.1 Sin PIN se responde 422', $missing->getStatusCode() === 422, $statusDetail($missing));
$assert(
    '5.2 Sin PIN se expone MISSING_PICKUP_PIN (no se confunde con PIN erróneo)',
    ($missingBody['error']['code'] ?? '') === 'MISSING_PICKUP_PIN',
    'error: ' . ($missingBody['error']['code'] ?? 'AUSENTE')
);

$tooShort = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '482'], (string)$stillDesk->getId()));
$assert('5.3 Un PIN de 3 dígitos se responde 422', $tooShort->getStatusCode() === 422, $statusDetail($tooShort));
$assert(
    '5.4 Un PIN de 3 dígitos expone INVALID_PICKUP_PIN',
    ($tooShort->getDecodedBody()['error']['code'] ?? '') === 'INVALID_PICKUP_PIN',
    'error: ' . ($tooShort->getDecodedBody()['error']['code'] ?? 'AUSENTE')
);

$letters = $invokeDeliver($deskRequest('POST', ['pickup_pin' => 'ABCD'], (string)$stillDesk->getId()));
$lettersBody = $letters->getDecodedBody();
$assert('5.5 Un PIN con letras se responde 422', $letters->getStatusCode() === 422, $statusDetail($letters));
$assert(
    '5.5b El mensaje de PIN mal formado NO reproduce lo introducido (ni en logs)',
    !str_contains((string)($lettersBody['error']['message'] ?? ''), 'ABCD'),
    'mensaje: ' . ($lettersBody['error']['message'] ?? 'AUSENTE')
);

// El PIN se teclea en un teclado numérico, pero un valor pegado desde el móvil
// puede traer espacios sobrantes: se normalizan en lugar de rechazar al usuario.
$padded = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '  ' . $stillDesk->getPickupPin() . '  '], (string)$stillDesk->getId()));
$assert('5.5c Un PIN con espacios sobrantes se acepta (se normaliza)', $padded->getStatusCode() === 200, $statusDetail($padded));
$assert(
    '5.5d Y tras normalizarlo el sobre queda entregado',
    $refundRepo->findById((int)$stillDesk->getId())?->getStatus() === RefundStatus::REFUNDED_IN_HAND
);

// Se repone un sobre intacto para el resto de las comprobaciones de esta sección
$stillDesk = $makeCase(CompensationMethod::EN_MANO_SEDE, 2.00, true);

$tooLong = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '48210'], (string)$stillDesk->getId()));
$assert('5.6 Un PIN de 5 dígitos se responde 422', $tooLong->getStatusCode() === 422, $statusDetail($tooLong));

$assert(
    '5.7 Ninguno de los intentos mal formados entregó el sobre',
    $refundRepo->findById((int)$stillDesk->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);

$nonNumericId = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '4821'], 'abc'));
$assert('5.8 Un ID de expediente no numérico se responde 400', $nonNumericId->getStatusCode() === 400, $statusDetail($nonNumericId));

$ghost = $invokeDeliver($deskRequest('POST', ['pickup_pin' => '4821'], '99999'));
$ghostBody = $ghost->getDecodedBody();
$assert('5.9 Un expediente inexistente se responde 404', $ghost->getStatusCode() === 404, $statusDetail($ghost));
$assert(
    '5.10 El 404 expone REFUND_NOT_FOUND',
    ($ghostBody['error']['code'] ?? '') === 'REFUND_NOT_FOUND',
    'error: ' . ($ghostBody['error']['code'] ?? 'AUSENTE')
);

// Un expediente archivado lógicamente tampoco se entrega (Art. III)
$archived = $makeCase(CompensationMethod::EN_MANO_SEDE, 2.00, true);
$refundRepo->deactivate((int)$archived->getId());
$archivedDelivery = $invokeDeliver($deskRequest('POST', ['pickup_pin' => $archived->getPickupPin()], (string)$archived->getId()));
$assert('5.11 Un expediente archivado lógicamente se responde 404 (Art. III)', $archivedDelivery->getStatusCode() === 404, $statusDetail($archivedDelivery));

// ─────────────────────────────────────────────────────────────────────────────
// 6. Segregación de sede: el dinero de otro edificio no se toca
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Un conserje no toca el dinero de otra sede ---\n";

$foreign = $makeCase(CompensationMethod::EN_MANO_SEDE, 5.00, true, OTHER_SITE_ID);

$foreignList = $invokeIndex($deskRequest());
$foreignListIds = array_map(
    static fn (array $row): int => (int)$row['id'],
    $foreignList->getDecodedBody()['data']['refunds'] ?? []
);
$assert(
    '6.1 El listado de la sede no incluye expedientes ajenos',
    !in_array((int)$foreign->getId(), $foreignListIds, true),
    'ids listados: ' . implode(',', $foreignListIds)
);

$foreignDelivery = $invokeDeliver($deskRequest('POST', ['pickup_pin' => $foreign->getPickupPin()], (string)$foreign->getId()));
$foreignBody = $foreignDelivery->getDecodedBody();
$assert('6.2 Entregar el sobre de otra sede se responde 403', $foreignDelivery->getStatusCode() === 403, $statusDetail($foreignDelivery));
$assert(
    '6.3 El 403 expone SITE_MISMATCH',
    ($foreignBody['error']['code'] ?? '') === 'SITE_MISMATCH',
    'error: ' . ($foreignBody['error']['code'] ?? 'AUSENTE')
);
$assert(
    '6.4 El mensaje no revela el importe ni el nombre del otro expediente',
    !str_contains((string)($foreignBody['error']['message'] ?? ''), '5,00')
    && !str_contains((string)($foreignBody['error']['message'] ?? ''), 'Laura')
);
$assert(
    '6.5 El sobre ajeno sigue intacto en su edificio',
    $refundRepo->findById((int)$foreign->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Sin sede autenticada no hay ni lectura ni dinero
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Sin sede autenticada ---\n";

$anonymous = new Request(method: 'GET', path: '/api/location/refunds');
$assert('7.1 Sin sede se responde 401 en el listado', $invokeIndex($anonymous)->getStatusCode() === 401);

$anonymousDelivery = new Request(
    method: 'POST',
    path: '/api/location/refunds/' . $foreign->getId() . '/deliver',
    parsedBody: ['pickup_pin' => $foreign->getPickupPin()],
    headers: ['content-type' => 'application/json']
);
$anonymousDelivery->setRouteParams(['id' => (string)$foreign->getId()]);
$assert('7.2 Sin sede se responde 401 en la entrega', $invokeDeliver($anonymousDelivery)->getStatusCode() === 401);
$assert(
    '7.3 Y el sobre ajeno sigue donde estaba',
    $refundRepo->findById((int)$foreign->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);

// La cabecera X-Site-Code también vale, como en el resto del portal de sede
$byHeader = new Request(method: 'GET', path: '/api/location/refunds', headers: ['X-Site-Code' => 'SEDE-BCN-02']);
$headerBody = $invokeIndex($byHeader)->getDecodedBody();
$assert('7.4 La cabecera X-Site-Code identifica la sede', ($headerBody['data']['total'] ?? 0) >= 1, 'total: ' . ($headerBody['data']['total'] ?? 'AUSENTE'));
$assert(
    '7.5 Y desde ahí sí se ve el sobre del otro edificio',
    in_array(
        (int)$foreign->getId(),
        array_map(static fn (array $row): int => (int)$row['id'], $headerBody['data']['refunds'] ?? []),
        true
    )
);

// El atributo `site_code` es la tercera vía que usa el portal de sede, y no
// puede caerse sin que el listado se quede sin sede.
$byAttribute = new Request(method: 'GET', path: '/api/location/refunds');
$byAttribute->setAttribute('site_code', 'SEDE-BCN-02');
$attributeResponse = $invokeIndex($byAttribute);
$attributeBody = $attributeResponse->getDecodedBody();
$assert('7.6 El atributo `site_code` también identifica la sede', $attributeResponse->getStatusCode() === 200, $statusDetail($attributeResponse));
$assert(
    '7.7 Y desde ahí se ve el sobre del otro edificio',
    in_array(
        (int)$foreign->getId(),
        array_map(static fn (array $row): int => (int)$row['id'], $attributeBody['data']['refunds'] ?? []),
        true
    ),
    'total: ' . ($attributeBody['data']['total'] ?? 'AUSENTE')
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-11 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
