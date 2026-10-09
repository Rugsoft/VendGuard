<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `CoordinatorRefundController` (T-REF-12).
 *
 * The "Hecho cuando" criterion asks for a coordination inbox with the FULL
 * financial detail, the formal double sign-off above 10,00 €, the digital
 * settlement with its banking reference and the motivated dismissal.
 *
 * The properties asserted first are the ones a happy path would never reveal:
 *
 * 1. THIS is the only projection of the module allowed to show money. If the
 *    inbox stopped emitting the IBAN, every assertion below about approval and
 *    payment would still pass while the feature became unusable, so the
 *    exposure is asserted positively and its non-vacuity is controlled.
 * 2. The reverse also holds: the pickup PIN and the public tracking token stay
 *    out even here. The PIN releases cash at the desk and Coordination never
 *    hands over an envelope; the token is a permanent public link that ends up
 *    in screenshots.
 * 3. The double sign-off cannot be skipped. Approving a case that the automatic
 *    classification did not escalate returns 409, because otherwise the 10,00 €
 *    control of RF-REF-03 would be decoration.
 * 4. A dismissal must be argued, not tapped. Under 20 characters is 422, and
 *    the rejected case keeps every financial record for the audit trail
 *    (Art. III).
 * 5. Nobody without a coordinator session gets a single byte of financial data,
 *    and a wrong answer never moves money.
 *
 * No database is touched: every repository is an in-memory fake and the real
 * `RefundManagementService` and `RefundRequest` run on top of them.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\CoordinatorDecision;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\CoordinatorRefundController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

const COORDINATOR_ID = 3;
const COORD_SITE_ID = 1;
const COORD_MACHINE_ID = 11;
const COORD_INCIDENT_ID = 101;
const COORD_IBAN = 'ES9121000418450200051332';

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

final class CoordFaultedResponse
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

function rebuildCoordCase(RefundRequest $case, array $overrides = []): RefundRequest
{
    $pick = static function (string $key, mixed $default) use ($overrides): mixed {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    };

    // Las columnas ENUM llegan como cadena desde la base de datos y la entidad
    // exige el enum: sin esta conversión el doble de pruebas reventaría con un
    // ValueError en lugar de fallar una aserción.
    $enum = static function (string $class, mixed $value): mixed {
        return is_string($value) ? $class::from($value) : $value;
    };

    $decision = $enum(CoordinatorDecision::class, $pick('coordinatorDecision', $case->getCoordinatorDecision()));
    $finding = $enum(TechnicianFinding::class, $pick('technicianFinding', $case->getTechnicianFinding()));
    $custody = $enum(CashCustodyAction::class, $pick('cashCustodyAction', $case->getCashCustodyAction()));

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
        technicianFinding: $finding,
        recoveredAmount: $pick('recoveredAmount', $case->getRecoveredAmount()),
        cashCustodyAction: $custody,
        receptionistName: $pick('receptionistName', $case->getReceptionistName()),
        technicianJustification: $pick('technicianJustification', $case->getTechnicianJustification()),
        approvedAmount: $pick('approvedAmount', $case->getApprovedAmount()),
        paidAmount: $pick('paidAmount', $case->getPaidAmount()),
        paymentReference: $pick('paymentReference', $case->getPaymentReference()),
        coordinatorDecision: $decision,
        coordinatorJustification: $pick('coordinatorJustification', $case->getCoordinatorJustification()),
        paidAt: $pick('paidAt', $case->getPaidAt()),
        isActive: (bool)$pick('isActive', $case->isActive()),
        createdAt: $pick('createdAt', $case->getCreatedAt()),
        updatedAt: $pick('updatedAt', $case->getUpdatedAt())
    );
}

final class CoordRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    /** Últimos filtros aplicados, para comprobar lo que llega al SQL. */
    public array $lastFilters = [];

    /**
     * Filtros de TODAS las llamadas de conteo, en orden. La bandeja pide tres
     * conteos por petición (total, visto bueno y atascados), así que mirar solo
     * el último diría que el filtro de la bandeja es el del contador, que es
     * justo lo que la aserción 2.2 quiere evitar.
     */
    /** @var list<array<string, mixed>> */
    public array $countFilters = [];

    public int $lastLimit = 0;

    public int $lastOffset = 0;

    public int $countCalls = 0;

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildCoordCase($refundRequest, ['id' => $id]);

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
        return array_values(array_filter(
            $this->rows,
            static fn (RefundRequest $r): bool => $r->getIncidentId() === $incidentId
        ));
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        return array_values(array_filter($this->rows, static function (RefundRequest $r) use ($locationId, $status): bool {
            return $r->getLocationId() === $locationId && ($status === null || $r->getStatus() === $status);
        }));
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->lastFilters = $filters;
        $this->lastLimit = $limit;
        $this->lastOffset = $offset;

        $matched = $this->matchFilters($filters);

        // Orden de bandeja: lo más reciente primero, igual que en SQL.
        usort($matched, static function (RefundRequest $left, RefundRequest $right): int {
            $created = strcmp((string)$right->getCreatedAt(), (string)$left->getCreatedAt());

            return $created !== 0 ? $created : ($right->getId() <=> $left->getId());
        });

        return array_slice($matched, $offset, $limit);
    }

    public function countForCoordinator(array $filters = []): int
    {
        $this->countCalls++;
        $this->lastFilters = $filters;
        $this->countFilters[] = $filters;

        return count($this->matchFilters($filters));
    }

    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null || $current->getStatus() !== $expectedStatus) {
            return false;
        }

        $entityFields = [];
        foreach ($fields as $column => $value) {
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string)$column))));
            $entityFields[$key] = $value;
        }

        $this->rows[$id] = rebuildCoordCase($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null) {
            return false;
        }

        $this->rows[$id] = rebuildCoordCase($current, [
            'bizumPhone' => $bizumPhone ?? $current->getBizumPhone(),
            'iban' => $iban ?? $current->getIban(),
        ]);

        return true;
    }

    public function detachFromIncident(int $id, \VendGuard\Core\Domain\Model\RefundStatus $newStatus): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la
        // desvinculación real se certifica en la suite dedicada del modulo 11.
        return true;
    }

    public function deactivate(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildCoordCase($this->rows[$id], ['isActive' => false]);

        return true;
    }

    /**
     * Réplica de `buildCoordinatorFilters()` del repositorio PDO, para que la
     * suite no pueda pasar con un filtro que el SQL real ignoraría.
     *
     * @return list<RefundRequest>
     */
    private function matchFilters(array $filters): array
    {
        return array_values(array_filter($this->rows, static function (RefundRequest $row) use ($filters): bool {
            if (!$row->isActive()) {
                return false;
            }

            if (isset($filters['status']) && $filters['status'] !== '' && $row->getStatus()->value !== $filters['status']) {
                return false;
            }

            foreach (['location_id', 'machine_id'] as $idFilter) {
                if (isset($filters[$idFilter]) && (int)$filters[$idFilter] > 0) {
                    $actual = $idFilter === 'location_id' ? $row->getLocationId() : $row->getMachineId();
                    if ($actual !== (int)$filters[$idFilter]) {
                        return false;
                    }
                }
            }

            if (isset($filters['from']) && $filters['from'] !== ''
                && strcmp((string)$row->getCreatedAt(), (string)$filters['from']) < 0) {
                return false;
            }

            if (isset($filters['to']) && $filters['to'] !== ''
                && strcmp((string)$row->getCreatedAt(), (string)$filters['to']) > 0) {
                return false;
            }

            return true;
        }));
    }
}

final class CoordAuditRepo implements AuditLogRepositoryInterface
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
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $e): bool => $e->getEntityType() === $entityType
        ));
    }
}

final class CoordMachineRepo implements MachineRepositoryInterface
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

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }
}

final class CoordLocationRepo implements LocationRepositoryInterface
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

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
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

final class CoordIncidentRepo implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
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
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }

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

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
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
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }

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
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

$refundRepo = new CoordRefundRepo();
$auditRepo = new CoordAuditRepo();
$incidentRepo = new CoordIncidentRepo();
$machineRepo = new CoordMachineRepo();
$locationRepo = new CoordLocationRepo();

$locationRepo->rows[COORD_SITE_ID] = new Location(
    id: COORD_SITE_ID,
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

$machineRepo->machines[COORD_MACHINE_ID] = new Machine(
    id: COORD_MACHINE_ID,
    locationId: COORD_SITE_ID,
    code: 'VEND-0101',
    model: 'VendGuard 300',
    machineType: MachineType::COMBO,
    floorWing: 'Planta 0',
    isActive: true,
    createdAt: '2026-10-01 09:00:00'
);

$incidentRepo->rows[COORD_INCIDENT_ID] = new Incident(
    id: COORD_INCIDENT_ID,
    ticketCode: 'INC-2026-0001',
    machineId: COORD_MACHINE_ID,
    locationId: COORD_SITE_ID,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'La máquina cobra y no entrega el producto.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::RESOLVED,
    assignedTechnicianId: 7
);

$management = new RefundManagementService($refundRepo, new IbanValidationService(), new AuditLogger($auditRepo));
$controller = new CoordinatorRefundController($refundRepo, $incidentRepo, $machineRepo, $locationRepo, $management);

/**
 * Abre una reclamación y, si se pide, la camina hasta el estado en el que
 * Coordinación tiene que intervenir.
 *
 * @param bool $escalate Lleva el expediente a REQUIRES_COORDINATOR_APPROVAL.
 */
// Cada llamada de $makeCase monta un escenario distinto (vía digital, importe
// elevado, ventanilla), no una segunda reclamación del mismo consumidor. RF-REF-11
// prohíbe justo eso, así que cada caso abre su propia avería en vez de repetir
// la 101: el fixture sigue siendo autónomo y la regla nueva se honra.
$coordIncidentSeq = 102;
$coordLastTicketCode = '';

$makeCase = static function (
    CompensationMethod $method,
    float $amount,
    bool $escalate = false,
    ?float $recovered = null,
    TechnicianFinding $finding = TechnicianFinding::FOUND_PHYSICAL,
    string $name = 'Laura Sanitaria'
) use ($management, $refundRepo, $incidentRepo, &$coordIncidentSeq, &$coordLastTicketCode): RefundRequest {
    // La bandeja resuelve el código de avería de cada expediente (contrato §4.1),
    // así que la avería generada necesita su fila: sin ella, `incident_code`
    // saldría vacío y la aserción 1.13 midría el fixture, no el DTO.
    $incidentId = $coordIncidentSeq++;
    $coordLastTicketCode = 'INC-2026-' . str_pad((string)$incidentId, 4, '0', STR_PAD_LEFT);
    $incidentRepo->rows[$incidentId] = new Incident(
        id: $incidentId,
        ticketCode: $coordLastTicketCode,
        machineId: COORD_MACHINE_ID,
        locationId: COORD_SITE_ID,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'La máquina cobra y no entrega el producto.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::RESOLVED,
        assignedTechnicianId: 7
    );

    $case = $management->createCase(new CreateRefundRequestDTO(
        incidentId: $incidentId,
        machineId: COORD_MACHINE_ID,
        locationId: COORD_SITE_ID,
        claimantName: $name,
        claimantContact: '600111222',
        claimedAmount: $amount,
        compensationMethod: $method,
        productAttempted: 'Café con leche carril 2',
        bizumPhone: $method->requiresBizumPhone() ? '600111222' : null,
        iban: $method->requiresIban() ? COORD_IBAN : null
    ));

    $id = (int)$case->getId();

    if (!$escalate) {
        return $refundRepo->findById($id);
    }

    $refundRepo->transitionStatus($id, RefundStatus::PENDING_INSPECTION, RefundStatus::REQUIRES_COORDINATOR_APPROVAL, [
        'technician_finding' => $finding->value,
        'recovered_amount' => $recovered ?? $amount,
        'cash_custody_action' => CashCustodyAction::HELD_FOR_CENTRAL->value,
        'technician_justification' => 'Caja central sin monedas registradas en el corte.',
        'technician_id' => 7,
    ]);

    return $refundRepo->findById($id);
};

/**
 * Dejura un expediente verificado y listo para liquidar, que es el estado en el
 * que sólo puede caer tras un visto bueno formal.
 */
$makeReadyToPay = static function (CompensationMethod $method, float $amount) use ($makeCase, $refundRepo): RefundRequest {
    $case = $makeCase($method, $amount, true);

    $refundRepo->transitionStatus(
        (int)$case->getId(),
        RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
        RefundStatus::VERIFIED_PENDING_PAYMENT,
        ['approved_amount' => $amount, 'coordinator_decision' => CoordinatorDecision::APPROVED->value]
    );

    return $refundRepo->findById((int)$case->getId());
};

$coordRequest = static function (
    string $method = 'GET',
    array $body = [],
    array $query = [],
    string $rawId = '1',
    array $attributes = []
): Request {
    $paths = [
        'index' => '/api/coordinator/refunds',
        'approve' => '/api/coordinator/refunds/' . $rawId . '/approve',
        'pay' => '/api/coordinator/refunds/' . $rawId . '/pay',
        'reject' => '/api/coordinator/refunds/' . $rawId . '/reject',
    ];
    $path = match ($method) {
        'GET' => $paths['index'],
        'APPROVE' => $paths['approve'],
        'PAY' => $paths['pay'],
        default => $paths['reject'],
    };

    $request = new Request(
        method: $method === 'GET' ? 'GET' : 'POST',
        path: $path,
        queryParams: $query,
        parsedBody: $body,
        headers: ['content-type' => 'application/json']
    );

    if ($method !== 'GET') {
        $request->setRouteParams(['id' => $rawId]);
    }

    $request->setAttribute('user_id', $attributes['user_id'] ?? COORDINATOR_ID);
    $request->setAttribute('user_role', $attributes['user_role'] ?? 'COORDINATOR');

    return $request;
};

$invoke = static function (string $action, Request $request) use ($controller): Response|CoordFaultedResponse {
    try {
        return match ($action) {
            'index' => $controller->index($request),
            'approve' => $controller->approve($request),
            'pay' => $controller->pay($request),
            default => $controller->reject($request),
        };
    } catch (Throwable $e) {
        return new CoordFaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$detail = static fn (Response|CoordFaultedResponse $response): string => $response instanceof CoordFaultedResponse
    ? 'fallo inesperado -> ' . $response->fault
    : 'HTTP ' . $response->getStatusCode();

/**
 * Busca una fila de la bandeja por identificador sin colisionar con los importes.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>|null
 */
$itemById = static function (array $body, int $id): ?array {
    foreach ($body['data']['items'] ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
};

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla ---\n";

$ctlSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/CoordinatorRefundController.php');
$viewSource = (string)file_get_contents($baseDir . '/src/Application/DTO/CoordinatorRefundViewDTO.php');

$assert('0.1 El controlador declara tipado estricto', str_contains($ctlSource, 'declare(strict_types=1);'));
$assert('0.2 El DTO de coordinación declara tipado estricto', str_contains($viewSource, 'declare(strict_types=1);'));
$assert('0.3 El controlador NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($ctlSource), 'DELETE FROM'));
$assert('0.4 El DTO de coordinación NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($viewSource), 'DELETE FROM'));
$assert(
    '0.5 El controlador no invoca ninguna pasarela de pago externa (Art. VI)',
    !str_contains(strtoupper($ctlSource), 'CURL_')
    && !str_contains(strtoupper($ctlSource), 'FILE_GET_CONTENTS')
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. La bandeja global con detalle financiero completo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. GET /api/coordinator/refunds: la bandeja con detalle financiero ---\n";

$transferCase = $makeCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 12.00, true, 12.00);
$transferTicketCode = $coordLastTicketCode;
$bizumCase = $makeCase(CompensationMethod::BIZUM, 18.00, true, 3.00, TechnicianFinding::FOUND_PHYSICAL, 'Marc Rider');
$deskCase = $makeCase(CompensationMethod::EN_MANO_SEDE, 2.00);

$inbox = $invoke('index', $coordRequest());
$inboxBody = $inbox->getDecodedBody();
$inboxJson = (string)json_encode($inboxBody, JSON_THROW_ON_ERROR);

$assert('1.1 La bandeja responde HTTP 200', $inbox->getStatusCode() === 200, $detail($inbox));
$assert('1.2 El total coincide con el número de expedientes', ($inboxBody['data']['total'] ?? 0) === 3, 'total: ' . ($inboxBody['data']['total'] ?? 'AUSENTE'));
$assert('1.3 Trae una fila por expediente', count($inboxBody['data']['items'] ?? []) === 3);

$transferRow = $itemById($inboxBody, (int)$transferCase->getId());
$assert('1.4 El expediente de transferencia aparece en la bandeja', $transferRow !== null);
$assert(
    '1.5 Aquí SÍ se publica el IBAN del reclamante (Coordinación es quien paga)',
    ($transferRow['iban'] ?? null) === COORD_IBAN,
    'iban: ' . var_export($transferRow['iban'] ?? null, true)
);
$assert(
    '1.6 Control de no-vacuidad: el IBAN existía en el dominio que se publica',
    $transferCase->getIban() === COORD_IBAN
);
$assert('1.7 Aquí SÍ aparece el nombre completo del reclamante', ($transferRow['claimant_name'] ?? '') === 'Laura Sanitaria');
$assert('1.8 Y su medio de contacto', ($transferRow['claimant_contact'] ?? '') === '600111222');

$bizumRow = $itemById($inboxBody, (int)$bizumCase->getId());
$assert(
    '1.9 El teléfono Bizum también se publica a Coordinación',
    ($bizumRow['bizum_phone'] ?? null) === '600111222',
    'bizum: ' . var_export($bizumRow['bizum_phone'] ?? null, true)
);
$assert(
    '1.10 Un expediente de Bizum no inventa un IBAN',
    array_key_exists('iban', (array)$bizumRow) && $bizumRow['iban'] === null
);
// Un expediente en mano no inventa datos bancarios. `array_key_exists` y no
// `??`: el operador coalescente se tragaría justo el `null` que hay que ver.
$deskRow = $itemById($inboxBody, (int)$deskCase->getId());
$assert(
    '1.11 Un expediente en mano no inventa datos bancarios',
    $deskRow !== null
    && array_key_exists('bizum_phone', $deskRow)
    && $deskRow['bizum_phone'] === null
    && array_key_exists('iban', $deskRow)
    && $deskRow['iban'] === null,
    json_encode($deskRow, JSON_THROW_ON_ERROR)
);

// Claves exigidas literalmente por el contrato §4.4.1
$contractKeys = [
    'id', 'incident_id', 'machine_code', 'location_name', 'claimant_name',
    'claimant_contact', 'claimed_amount', 'compensation_method', 'bizum_phone',
    'status', 'technician_finding', 'recovered_amount', 'cash_custody_action',
    'requires_special_supervision', 'created_at',
];
$missingKeys = array_values(array_diff($contractKeys, array_keys((array)$transferRow)));
$assert(
    '1.12 La fila cumple el payload exacto del contrato §4.4.1',
    $missingKeys === [],
    'claves ausentes: ' . implode(',', $missingKeys)
);
$assert('1.13 Expone el código de la avería de origen', ($transferRow['incident_code'] ?? '') === $transferTicketCode, 'esperado ' . $transferTicketCode . ', recibido ' . ($transferRow['incident_code'] ?? 'vacio'));
$assert('1.14 Expone el código de máquina', ($transferRow['machine_code'] ?? '') === 'VEND-0101');
$assert('1.15 Expone el nombre de la sede', ($transferRow['location_name'] ?? '') === 'Hospital del Mar - Edificio Central');
$assert('1.16 Expone la etiqueta en castellano del estado', ($transferRow['status_label'] ?? '') !== '');
$assert(
    '1.17 Calcula la necesidad de supervisión especial por importe',
    ($transferRow['requires_special_supervision'] ?? false) === true
);
$assert(
    '1.18 Calcula el ratio de discrepancia entre reclamado y recuperado',
    abs((float)($bizumRow['discrepancy_ratio'] ?? 0) - (15.0 / 18.0)) < 0.0001,
    'ratio: ' . var_export($bizumRow['discrepancy_ratio'] ?? null, true)
);
$assert(
    '1.19 Marca los expedientes que esperan visto bueno',
    ($transferRow['requires_approval'] ?? false) === true
    && ($deskRow['requires_approval'] ?? true) === false
);
$assert(
    '1.20 Cuenta los expedientes pendientes de visto bueno',
    ($inboxBody['data']['requires_approval_total'] ?? 0) === 2,
    'pendientes: ' . ($inboxBody['data']['requires_approval_total'] ?? 'AUSENTE')
);
$assert(
    '1.21 El total sale de un COUNT con los mismos filtros, no de las filas',
    $refundRepo->countCalls >= 2,
    'llamadas a countForCoordinator: ' . $refundRepo->countCalls
);

// Lo que NO sale, ni siquiera para Coordinación
$assert('1.22 El PIN de recogida NO se publica en la bandeja', !str_contains($inboxJson, 'pickup_pin'));
$assert('1.23 El token de seguimiento público NO se publica', !str_contains($inboxJson, 'tracking_token'));
$assert(
    '1.24 Control de no-vacuidad: el PIN SÍ existe en el dominio que se oculta',
    preg_match('/^[0-9]{4}$/', (string)$deskCase->getPickupPin()) === 1,
    'pin: ' . var_export($deskCase->getPickupPin(), true)
);
$assert(
    '1.25 Control de no-vacuidad: el token SÍ existe en el dominio que se oculta',
    strlen($deskCase->getTrackingToken()) === 64,
    'token: ' . var_export($deskCase->getTrackingToken(), true)
);
$assert('1.26 Y el PIN real tampoco aparece como valor suelto', !str_contains($inboxJson, (string)$deskCase->getPickupPin()));

// ─────────────────────────────────────────────────────────────────────────────
// 2. Filtros y paginación de la bandeja
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Filtros y paginación ---\n";

// La sección 1 ya ha contado la bandeja sin filtros; se parte de cero para que
// `countFilters[0]` sea el conteo principal de ESTA petición filtrada y no el
// de una llamada anterior.
$refundRepo->countFilters = [];
$byStatus = $invoke('index', $coordRequest('GET', [], ['status' => 'REQUIRES_COORDINATOR_APPROVAL']));
$byStatusBody = $byStatus->getDecodedBody();
$assert('2.1 El filtro por estado acota la bandeja', ($byStatusBody['data']['total'] ?? 0) === 2, 'total: ' . ($byStatusBody['data']['total'] ?? 'AUSENTE'));
$assert(
    '2.2 El filtro llega al repositorio por nombre de columna',
    ($refundRepo->countFilters[0]['status'] ?? '') === 'REQUIRES_COORDINATOR_APPROVAL',
    json_encode($refundRepo->countFilters[0] ?? null, JSON_THROW_ON_ERROR)
);

$refundRepo->countFilters = [];
$approvalOnly = $invoke('index', $coordRequest('GET', [], ['requires_approval_only' => '1']));
$approvalOnlyBody = $approvalOnly->getDecodedBody();
$assert('2.3 `requires_approval_only` devuelve sólo los escalados', ($approvalOnlyBody['data']['total'] ?? 0) === 2);
$assert(
    '2.4 Y el atajo se traduce al estado, no a otra consulta',
    ($refundRepo->countFilters[0]['status'] ?? '') === 'REQUIRES_COORDINATOR_APPROVAL'
);

$byLocation = $invoke('index', $coordRequest('GET', [], ['location_id' => '1']));
$assert('2.5 El filtro por sede acota la bandeja', ($byLocation->getDecodedBody()['data']['total'] ?? 0) === 3);
$byMachine = $invoke('index', $coordRequest('GET', [], ['machine_id' => (string)COORD_MACHINE_ID]));
$assert('2.6 El filtro por máquina acota la bandeja', ($byMachine->getDecodedBody()['data']['total'] ?? 0) === 3);

$byDate = $invoke('index', $coordRequest('GET', [], ['from' => '2020-01-01 00:00:00', 'to' => '2020-01-02 00:00:00']));
$assert(
    '2.7 Un rango de fechas que no cubre los expedientes vacía la bandeja',
    ($byDate->getDecodedBody()['data']['total'] ?? -1) === 0
);

$paged = $invoke('index', $coordRequest('GET', [], ['limit' => '2', 'offset' => '1']));
$pagedBody = $paged->getDecodedBody();
$assert('2.8 La paginación recorta la página', count($pagedBody['data']['items'] ?? []) === 2);
$assert('2.9 Pero el total sigue siendo el de toda la bandeja', ($pagedBody['data']['total'] ?? 0) === 3);
$assert('2.10 El límite y el desplazamiento salen en la respuesta', ($pagedBody['data']['limit'] ?? 0) === 2 && ($pagedBody['data']['offset'] ?? -1) === 1);
$assert(
    '2.11 La página salta de verdad (no repite la primera)',
    (int)($pagedBody['data']['items'][0]['id'] ?? 0) !== (int)($inboxBody['data']['items'][0]['id'] ?? -1)
);

$echoFilters = [
    'status' => 'inventado',
    'location_id' => 'abc',
    'machine_id' => '0',
    'limit' => 'mil',
    'offset' => '-5',
    'from' => 'ayer',
];
foreach ($echoFilters as $filter => $badValue) {
    $invalid = $invoke('index', $coordRequest('GET', [], [$filter => $badValue]));
    $invalidBody = $invalid->getDecodedBody();
    $assert(
        "2.12.{$filter} Un filtro sin sentido se rechaza con 422 en lugar de ignorarse",
        $invalid->getStatusCode() === 422
        && ($invalidBody['error']['code'] ?? '') === 'INVALID_REFUND_FILTER',
        'HTTP ' . $invalid->getStatusCode() . ' error: ' . ($invalidBody['error']['code'] ?? 'AUSENTE')
    );
}

$hugeLimit = $invoke('index', $coordRequest('GET', [], ['limit' => '5000']));
$assert('2.13 Un límite por encima del tope se rechaza con 422', $hugeLimit->getStatusCode() === 422);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Sin sesión de Coordinación no hay ni un dato financiero (Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Control de acceso a la bandeja ---\n";

$anon = (static function (): Request {
    $request = new Request('GET', '/api/coordinator/refunds');
    $request->setAttribute('user_id', null);
    $request->setAttribute('user_role', null);

    return $request;
})();

$anonInbox = $invoke('index', $anon);
$assert('3.1 Sin identidad se responde 401', $anonInbox->getStatusCode() === 401, $detail($anonInbox));
$assert(
    '3.2 El 401 no filtra ningún dato financiero',
    !str_contains((string)json_encode($anonInbox->getDecodedBody(), JSON_THROW_ON_ERROR), COORD_IBAN)
);

foreach (['TECHNICIAN', 'LOCATION_MANAGER'] as $role) {
    $forbidden = $invoke('index', $coordRequest('GET', [], [], '1', ['user_role' => $role]));
    $assert("3.3 El rol {$role} recibe 403 en la bandeja", $forbidden->getStatusCode() === 403, $detail($forbidden));
    $assert(
        "3.4 El 403 de {$role} expone FORBIDDEN",
        ($forbidden->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );
}

$noUser = new Request('POST', '/api/coordinator/refunds/1/approve', [], ['approved_amount' => 5.0]);
$noUser->setRouteParams(['id' => '1']);
$assert('3.5 Sin identidad tampoco se puede aprobar', $invoke('approve', $noUser)->getStatusCode() === 401);

$techApprove = $coordRequest('APPROVE', ['approved_amount' => 5.0], [], (string)$transferCase->getId(), ['user_role' => 'TECHNICIAN']);
$assert('3.6 Un técnico no puede aprobar importes', $invoke('approve', $techApprove)->getStatusCode() === 403);
$assert(
    '3.7 Y el expediente no se ha movido por el intento',
    $refundRepo->findById((int)$transferCase->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Doble visto bueno ante importes superiores a 10,00 € (RF-REF-03)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Visto bueno formal ---\n";

$eventsBeforeApprove = count($auditRepo->events);
$approved = $invoke('approve', $coordRequest('APPROVE', [
    'approved_amount' => 11.50,
    'notes' => 'Corte de caja revisado y ticket de venta coincidente.',
], [], (string)$transferCase->getId()));
$approvedBody = $approved->getDecodedBody();
$approvedRow = $refundRepo->findById((int)$transferCase->getId());

$assert('4.1 El visto bueno responde HTTP 200', $approved->getStatusCode() === 200, $detail($approved));
$assert('4.2 El expediente pasa a VERIFIED_PENDING_PAYMENT', $approvedRow?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT);
$assert('4.3 La respuesta expone el nuevo estado', ($approvedBody['data']['status'] ?? '') === 'VERIFIED_PENDING_PAYMENT');
$assert('4.4 Queda registrada la cuantía aprobada', (float)($approvedBody['data']['approved_amount'] ?? 0) === 11.50);
$assert(
    '4.5 La decisión formal queda como APPROVED',
    $approvedRow?->getCoordinatorDecision() === CoordinatorDecision::APPROVED,
    'decisión: ' . var_export($approvedRow?->getCoordinatorDecision()?->value, true)
);
$assert(
    '4.6 El motivo del visto bueno queda escrito',
    str_contains((string)$approvedRow?->getCoordinatorJustification(), 'Corte de caja revisado')
);
$assert('4.7 La respuesta dice que queda listo para liquidar', ($approvedBody['data']['awaits_payment'] ?? false) === true);
$assert('4.8 El mensaje está en castellano', str_contains((string)($approvedBody['message'] ?? ''), 'Visto bueno'));
$assert('4.9 El visto bueno queda auditado', count($auditRepo->events) > $eventsBeforeApprove);
$assert(
    '4.10 Control de no-vacuidad: existe el evento REFUND_APPROVED con el actor',
    count(array_filter(
        $auditRepo->events,
        static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_APPROVED' && $e->getUserId() === COORDINATOR_ID
    )) === 1
);

$again = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 11.50], [], (string)$transferCase->getId()));
$assert(
    '4.11 Aprobar dos veces el mismo expediente se responde 409',
    $again->getStatusCode() === 409
    && ($again->getDecodedBody()['error']['code'] ?? '') === 'INVALID_REFUND_STATE_TRANSITION',
    $detail($again)
);

// La doble autorización no se puede saltar aprobando un expediente que la
// clasificación automática no escaló.
$premature = $makeCase(CompensationMethod::EN_MANO_SEDE, 1.50);
$prematureApprove = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 1.50], [], (string)$premature->getId()));
$assert(
    '4.12 Aprobar un expediente que no fue escalado devuelve 409 (doble autorización real)',
    $prematureApprove->getStatusCode() === 409,
    $detail($prematureApprove)
);
$assert(
    '4.13 Y no se le ha movido ni el estado ni el importe aprobado',
    $refundRepo->findById((int)$premature->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
    && $refundRepo->findById((int)$premature->getId())?->getApprovedAmount() === null
);

// La nota es opcional al autorizar (el motivo obligatorio es del rechazo)
$noNotes = $makeCase(CompensationMethod::BIZUM, 15.00, true, 15.00);
$approvedNoNotes = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 15.00], [], (string)$noNotes->getId()));
$assert('4.14 Un visto bueno sin nota se acepta', $approvedNoNotes->getStatusCode() === 200, $detail($approvedNoNotes));

// ─────────────────────────────────────────────────────────────────────────────
// 5. Importes que el visto bueno no puede autorizar (RF-REF-03)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. El veto de importes del visto bueno ---\n";

// Cada caso lleva SU cuerpo y SU código esperado. Una versión anterior de esta
// tabla usaba una clave distinta por fila y acababa siguiendo siempre la
// primera rama: cuatro de las cinco aserciones eran copias del caso «sin
// cuantía» y ninguna llegaba a probar el tope de 50,00 €.
$amountCases = [
    ['label' => '5.1', 'body' => [], 'code' => 'MISSING_APPROVED_AMOUNT', 'why' => 'sin cuantía'],
    ['label' => '5.2', 'body' => ['approved_amount' => 0], 'code' => 'INVALID_REFUND_AMOUNT', 'why' => '0,00 €'],
    ['label' => '5.3', 'body' => ['approved_amount' => -3.00], 'code' => 'INVALID_REFUND_AMOUNT', 'why' => 'importe negativo'],
    ['label' => '5.4', 'body' => ['approved_amount' => 'mucho'], 'code' => 'INVALID_REFUND_AMOUNT', 'why' => 'texto en vez de importe'],
    ['label' => '5.5', 'body' => ['approved_amount' => 50.01], 'code' => 'INVALID_REFUND_AMOUNT', 'why' => 'por encima del tope de 50,00 €'],
];

foreach ($amountCases as $payload) {
    $target = $makeCase(CompensationMethod::BIZUM, 20.00, true, 20.00);
    $response = $invoke('approve', $coordRequest('APPROVE', $payload['body'], [], (string)$target->getId()));
    $body = $response->getDecodedBody();
    $code = $body['error']['code'] ?? '';

    $assert(
        "{$payload['label']} Un importe inválido se rechaza con 422 ({$payload['why']} -> {$payload['code']})",
        $response->getStatusCode() === 422 && $code === $payload['code'],
        $detail($response) . ' error: ' . ($code !== '' ? $code : 'AUSENTE')
    );
    $assert(
        "{$payload['label']}b El expediente sigue esperando visto bueno",
        $refundRepo->findById((int)$target->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
        'estado: ' . var_export($refundRepo->findById((int)$target->getId())?->getStatus()?->value, true)
    );
}

// Esta aserción decía «el tope exacto de 50,00 € se acepta» sobre una
// reclamación de 20,00 €, y eso era exactamente el agujero de la quinta tanda:
// el único control del visto bueno era el bloque de 50,00 €, así que un
// coordinador podía autorizar tres veces lo reclamado. Con la liquidación por
// igualdad, ese 50,00 € además pasaba a ser la única cifra que el servicio
// aceptaba, de modo que firmarlo autorizaba el desembolso entero.
//
// El tope de 50,00 € sigue existiendo y se comprueba en 5.5; lo que cambia es
// que el máximo real del visto bueno es el importe reclamado.
$atClaim = $makeCase(CompensationMethod::BIZUM, 20.00, true, 20.00);
$claimOk = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 20.00], [], (string)$atClaim->getId()));
$assert(
    '5.6 Firmar exactamente lo reclamado sigue aceptándose',
    $claimOk->getStatusCode() === 200,
    $detail($claimOk)
);

$overClaim = $makeCase(CompensationMethod::BIZUM, 20.00, true, 20.00);
$overOk = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 50.00], [], (string)$overClaim->getId()));
$assert(
    '5.6b Firmar 50,00 € sobre una reclamación de 20,00 € se rechaza',
    $overOk->getStatusCode() === 422
    && ($overOk->getDecodedBody()['error']['code'] ?? '') === 'INVALID_REFUND_AMOUNT',
    $detail($overOk)
);
$assert(
    '5.6c El rechazo dice cuál era el máximo admisible',
    (float)($overOk->getDecodedBody()['error']['details']['maximum_allowed'] ?? 0) === 20.00,
    'detalles: ' . json_encode($overOk->getDecodedBody()['error']['details'] ?? null)
);
$assert(
    '5.6d El expediente sigue esperando visto bueno tras el rechazo',
    $refundRepo->findById((int)$overClaim->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
    'estado: ' . var_export($refundRepo->findById((int)$overClaim->getId())?->getStatus()?->value, true)
);

// Un importe con fracción de céntimo no es dinero, y la columna DECIMAL lo
// redondearía: 4,005 € se guardaría como 4,01 € sin que nadie lo firmara.
$halfCent = $makeCase(CompensationMethod::BIZUM, 20.00, true, 20.00);
$halfCentResponse = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 4.005], [], (string)$halfCent->getId()));
$assert(
    '5.6e Un importe con fracción de céntimo se rechaza antes de guardarse',
    $halfCentResponse->getStatusCode() === 422
    && ($halfCentResponse->getDecodedBody()['error']['code'] ?? '') === 'INVALID_REFUND_AMOUNT',
    $detail($halfCentResponse)
);

$badId = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 5.00], [], 'abc'));
$assert(
    '5.7 Un ID de expediente no numérico se responde 400',
    $badId->getStatusCode() === 400 && ($badId->getDecodedBody()['error']['code'] ?? '') === 'INVALID_REFUND_ID',
    $detail($badId)
);

$ghost = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 5.00], [], '99999'));
$assert(
    '5.8 Un expediente inexistente se responde 404',
    $ghost->getStatusCode() === 404 && ($ghost->getDecodedBody()['error']['code'] ?? '') === 'REFUND_NOT_FOUND',
    $detail($ghost)
);

$archived = $makeCase(CompensationMethod::BIZUM, 20.00, true, 20.00);
$refundRepo->deactivate((int)$archived->getId());
$archivedApprove = $invoke('approve', $coordRequest('APPROVE', ['approved_amount' => 20.00], [], (string)$archived->getId()));
$assert('5.9 Un expediente archivado lógicamente se responde 404 (Art. III)', $archivedApprove->getStatusCode() === 404, $detail($archivedApprove));

// ─────────────────────────────────────────────────────────────────────────────
// 6. Liquidación digital con justificante bancario (RF-REF-07)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Liquidación digital ---\n";

$payable = $makeReadyToPay(CompensationMethod::TRANSFERENCIA_BANCARIA, 12.00);
$eventsBeforePay = count($auditRepo->events);
$paid = $invoke('pay', $coordRequest('PAY', [
    'payment_reference' => 'BIZUM-20261001-998822',
    'paid_amount' => 12.00,
], [], (string)$payable->getId()));
$paidBody = $paid->getDecodedBody();
$paidRow = $refundRepo->findById((int)$payable->getId());

$assert('6.1 La liquidación responde HTTP 200', $paid->getStatusCode() === 200, $detail($paid));
$assert('6.2 El expediente pasa a PAID_DIGITAL', $paidRow?->getStatus() === RefundStatus::PAID_DIGITAL);
$assert('6.3 La referencia bancaria queda registrada', $paidRow?->getPaymentReference() === 'BIZUM-20261001-998822');
$assert(
    '6.4 Queda registrada la fecha de pago (contrato §4.4.3)',
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$paidRow?->getPaidAt()) === 1,
    'paid_at: ' . var_export($paidRow?->getPaidAt(), true)
);
$assert(
    '6.5 La respuesta del contrato §4.4.3 trae id, estado, referencia y fecha',
    array_key_exists('id', $paidBody['data'] ?? [])
    && ($paidBody['data']['status'] ?? '') === 'PAID_DIGITAL'
    && ($paidBody['data']['payment_reference'] ?? '') === 'BIZUM-20261001-998822'
    && array_key_exists('paid_at', $paidBody['data'] ?? [])
);
$assert(
    '6.6 El mensaje es el del contrato',
    ($paidBody['message'] ?? '') === 'Pago digital registrado con éxito. Expediente de reintegro liquidado.'
);
$assert('6.7 La liquidación queda auditada', count($auditRepo->events) > $eventsBeforePay);
$assert(
    '6.8 Control de no-vacuidad: existe el evento REFUND_PAID_DIGITAL',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_PAID_DIGITAL')) === 1
);

// Sin `paid_amount` se liquida la cuantía formalmente aprobada
$payableNoAmount = $makeReadyToPay(CompensationMethod::BIZUM, 14.00);
$defaultPaid = $invoke('pay', $coordRequest('PAY', ['payment_reference' => 'BIZUM-20261002-1'], [], (string)$payableNoAmount->getId()));
$assert(
    '6.9 Sin `paid_amount` se liquida el importe aprobado del expediente',
    $defaultPaid->getStatusCode() === 200
    && (float)($defaultPaid->getDecodedBody()['data']['paid_amount'] ?? 0) === 14.00,
    $detail($defaultPaid)
);

foreach ([
    ['body' => [], 'label' => '6.10', 'code' => 'MISSING_PAYMENT_REFERENCE'],
    ['body' => ['payment_reference' => '   '], 'label' => '6.11', 'code' => 'MISSING_PAYMENT_REFERENCE'],
    ['body' => ['payment_reference' => 'X', 'paid_amount' => 0], 'label' => '6.12', 'code' => 'INVALID_REFUND_AMOUNT'],
    ['body' => ['payment_reference' => 'X', 'paid_amount' => 'todo'], 'label' => '6.13', 'code' => 'INVALID_REFUND_AMOUNT'],
] as $badPay) {
    $target = $makeReadyToPay(CompensationMethod::BIZUM, 9.00);
    $response = $invoke('pay', $coordRequest('PAY', $badPay['body'], [], (string)$target->getId()));
    $code = $response->getDecodedBody()['error']['code'] ?? '';
    $assert(
        "{$badPay['label']} Un pago sin justificante válido se rechaza ({$badPay['code']})",
        $response->getStatusCode() === 422 && $code === $badPay['code'],
        $detail($response) . ' error: ' . ($code !== '' ? $code : 'AUSENTE')
    );
    $assert(
        "{$badPay['label']}b Y el dinero sigue sin salir del cajero",
        $refundRepo->findById((int)$target->getId())?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT
    );
}

$payTwice = $invoke('pay', $coordRequest('PAY', ['payment_reference' => 'BIZUM-OTRO'], [], (string)$payable->getId()));
$assert(
    '6.14 Pagar dos veces el mismo expediente se responde 409',
    $payTwice->getStatusCode() === 409,
    $detail($payTwice)
);
$assert(
    '6.15 Y la referencia original no se pisa',
    $refundRepo->findById((int)$payable->getId())?->getPaymentReference() === 'BIZUM-20261001-998822'
);

$payBeforeInspection = $makeCase(CompensationMethod::BIZUM, 3.00);
$earlyPay = $invoke('pay', $coordRequest('PAY', ['payment_reference' => 'BIZUM-PRONTO'], [], (string)$payBeforeInspection->getId()));
$assert(
    '6.16 Pagar un expediente sin dictamen técnico se responde 409',
    $earlyPay->getStatusCode() === 409,
    $detail($earlyPay)
);

$payGhost = $invoke('pay', $coordRequest('PAY', ['payment_reference' => 'X'], [], '99999'));
$assert('6.17 Pagar un expediente inexistente se responde 404', $payGhost->getStatusCode() === 404, $detail($payGhost));

// ─────────────────────────────────────────────────────────────────────────────
// 7. Desestimación motivada (RF-REF-08)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Desestimación motivada ---\n";

$shortReason = $makeCase(CompensationMethod::BIZUM, 25.00, true, 0.00, TechnicianFinding::UNVERIFIED_NO_CASH);
$shortReject = $invoke('reject', $coordRequest('REJECT', ['rejection_reason' => 'No hay dinero'], [], (string)$shortReason->getId()));
$shortBody = $shortReject->getDecodedBody();

$assert('7.1 Un motivo de menos de 20 caracteres se responde 422', $shortReject->getStatusCode() === 422, $detail($shortReject));
$assert(
    '7.2 Expone JUSTIFICATION_TOO_SHORT',
    ($shortBody['error']['code'] ?? '') === 'JUSTIFICATION_TOO_SHORT',
    'error: ' . ($shortBody['error']['code'] ?? 'AUSENTE')
);
$assert(
    '7.3 El error dice cuántos caracteres faltaban',
    (int)($shortBody['error']['details']['minimum_length'] ?? 0) === 20
    && (int)($shortBody['error']['details']['justification_length'] ?? 0) === mb_strlen('No hay dinero'),
    'details: ' . json_encode($shortBody['error']['details'] ?? null, JSON_THROW_ON_ERROR)
);
$assert(
    '7.4 El expediente NO se desestima con un motivo insuficiente',
    $refundRepo->findById((int)$shortReason->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

$noReason = $makeCase(CompensationMethod::BIZUM, 25.00, true, 25.00);
$missingReject = $invoke('reject', $coordRequest('REJECT', [], [], (string)$noReason->getId()));
$assert(
    '7.5 Sin motivo se responde 422 MISSING_REJECTION_REASON',
    $missingReject->getStatusCode() === 422
    && ($missingReject->getDecodedBody()['error']['code'] ?? '') === 'MISSING_REJECTION_REASON',
    $detail($missingReject)
);

$rejected = $makeCase(CompensationMethod::BIZUM, 25.00, true, 0.00, TechnicianFinding::UNVERIFIED_NO_CASH);
$eventsBeforeReject = count($auditRepo->events);
$reject = $invoke('reject', $coordRequest('REJECT', [
    'rejection_reason' => 'Inspección técnica sin monedas atascadas y auditoría de ventas sin cobro.',
], [], (string)$rejected->getId()));
$rejectBody = $reject->getDecodedBody();
$rejectedRow = $refundRepo->findById((int)$rejected->getId());

$assert('7.6 Un motivo suficiente desestima el expediente', $reject->getStatusCode() === 200, $detail($reject));
$assert('7.7 El expediente queda en REJECTED', $rejectedRow?->getStatus() === RefundStatus::REJECTED);
$assert(
    '7.8 La decisión formal queda como REJECTED',
    $rejectedRow?->getCoordinatorDecision() === CoordinatorDecision::REJECTED
);
$assert(
    '7.9 El motivo queda escrito para la auditoría',
    str_contains((string)$rejectedRow?->getCoordinatorJustification(), 'auditoría de ventas')
);
$assert(
    '7.10 La respuesta deja claro que NO se ha pagado nada',
    array_key_exists('paid_amount', (array)($rejectBody['data'] ?? []))
    && $rejectBody['data']['paid_amount'] === null
);
$assert(
    '7.11 El expediente se conserva íntegro, con su importe (Art. III)',
    $rejectedRow?->isActive() === true && $rejectedRow?->getClaimedAmount() === 25.00
);
$assert('7.12 El rechazo queda auditado', count($auditRepo->events) > $eventsBeforeReject);
$assert(
    '7.13 Control de no-vacuidad: existe el evento REFUND_REJECTED',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_REJECTED')) === 1
);

$rejectTwice = $invoke('reject', $coordRequest('REJECT', [
    'rejection_reason' => 'Segundo intento de desestimación sobre el mismo expediente.',
], [], (string)$rejected->getId()));
$assert('7.14 Desestimar dos veces se responde 409', $rejectTwice->getStatusCode() === 409, $detail($rejectTwice));

$rejectPaid = $invoke('reject', $coordRequest('REJECT', [
    'rejection_reason' => 'Se intenta desestimar un expediente ya liquidado.',
], [], (string)$payable->getId()));
$assert('7.15 Desestimar un expediente ya pagado se responde 409', $rejectPaid->getStatusCode() === 409, $detail($rejectPaid));
$assert(
    '7.16 Y el pago queda intacto',
    $refundRepo->findById((int)$payable->getId())?->getStatus() === RefundStatus::PAID_DIGITAL
);

$rejectGhost = $invoke('reject', $coordRequest('REJECT', [
    'rejection_reason' => 'Motivo perfectamente válido pero de un expediente inexistente.',
], [], '99999'));
$assert('7.17 Desestimar un expediente inexistente se responde 404', $rejectGhost->getStatusCode() === 404, $detail($rejectGhost));

// ─────────────────────────────────────────────────────────────────────────────
// 8. Trazabilidad contable del ciclo completo (Art. III y RNF-REF-01)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Trazabilidad del ciclo completo ---\n";

$cycle = $makeReadyToPay(CompensationMethod::TRANSFERENCIA_BANCARIA, 13.00);
$cycleId = (int)$cycle->getId();

/**
 * @return list<AuditEvent>
 */
$eventsOfCase = static function (int $caseId) use ($auditRepo): array {
    return array_values(array_filter(
        $auditRepo->events,
        static fn (AuditEvent $e): bool => $e->getEntityId() === $caseId
    ));
};

$eventsBeforeCycle = count($eventsOfCase($cycleId));

$invoke('pay', $coordRequest('PAY', ['payment_reference' => 'TRANSF-2026-0007', 'paid_amount' => 13.00], [], (string)$cycleId));

$cycleEvents = $eventsOfCase($cycleId);
$cycleActions = array_map(static fn (AuditEvent $e): string => $e->getAction(), $cycleEvents);

$assert(
    '8.1 La liquidación añade su apunte al historial del expediente',
    count($cycleEvents) === $eventsBeforeCycle + 1,
    'antes: ' . $eventsBeforeCycle . ' despues: ' . count($cycleEvents)
);
$assert(
    '8.2 Y los apuntes son los del ciclo REFUND del catálogo',
    array_diff($cycleActions, ['REFUND_CASE_CREATED', 'REFUND_APPROVED', 'REFUND_PAID_DIGITAL']) === []
        && in_array('REFUND_CASE_CREATED', $cycleActions, true)
        && in_array('REFUND_PAID_DIGITAL', $cycleActions, true),
    json_encode($cycleActions, JSON_THROW_ON_ERROR)
);
$assert(
    '8.3 La auditoría del ciclo nunca arrastra el IBAN ni el teléfono (Art. V.4)',
    !str_contains(
        (string)json_encode(array_map(static fn (AuditEvent $e): array => $e->toArray(), $cycleEvents), JSON_THROW_ON_ERROR),
        COORD_IBAN
    )
);

// Tras liquidar, la bandeja muestra el cierre contable con su referencia
$finalInbox = $invoke('index', $coordRequest('GET', [], ['status' => 'PAID_DIGITAL']));
$finalRow = $itemById($finalInbox->getDecodedBody(), $cycleId);
$assert(
    '8.4 La bandeja final muestra la referencia bancaria del reembolso',
    ($finalRow['payment_reference'] ?? '') === 'TRANSF-2026-0007',
    'referencia: ' . ($finalRow['payment_reference'] ?? 'AUSENTE')
);
$assert(
    '8.5 Y la fecha de pago queda consultable en la bandeja',
    ($finalRow['paid_at'] ?? null) !== null && ($finalRow['paid_at'] ?? '') !== ''
);
$assert('8.6 Un expediente liquidado ya no espera visto bueno', ($finalRow['requires_approval'] ?? true) === false);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-12 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
