<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for the technician side of the refund module (T-REF-10).
 *
 * The "Hecho cuando" criterion asks for two things: the field technician can read
 * which claims hang on the fault they are about to repair, and resolving a fault
 * that carries a claim is impossible without filing a verdict on the money.
 *
 * Three properties are treated as first-class and asserted before the happy
 * paths:
 *
 * 1. The technician never sees money data. The endpoint is opened on a phone in
 *    someone else's building; an IBAN or a private phone number there is a leak,
 *    not a convenience.
 * 2. The verdict is MANDATORY and validated before anything is written, so a
 *    rejected verdict leaves the incident exactly as it was and the technician
 *    can fix the dropdown and retry on the spot.
 * 3. Repair and refund stay decoupled: a machine physically fixed goes back into
 *    service whatever the money paperwork says (RF-REF-09, Art. II).
 *
 * No database is touched: every repository is an in-memory fake and the real
 * `RefundManagementService` and `TechnicianRefundService` run on top of them.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Application\Service\TechnicianRefundService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\UnclaimedCashFindingRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Controller\TechnicianRefundController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

const TECH_ID = 7;
const OTHER_TECH_ID = 8;
const INCIDENT_ID = 101;
const MACHINE_ID = 11;
const LOCATION_ID = 1;

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

final class VerdictMachineRepo implements MachineRepositoryInterface
{
    public function __construct(private ?Machine $machine = null)
    {
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machine;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machine;
    }

    public function findActiveByLocationId(int $locationId): array
    {
        return $this->machine !== null ? [$this->machine] : [];
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
        return $this->machine !== null ? [$this->machine] : [];
    }

    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return false;
    }

    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        return null;
    }

    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del levantamiento vive en Machine y se certifica en su suite dedicada.
        return true;
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

final class VerdictLocationRepo implements LocationRepositoryInterface
{
    public function __construct(private ?Location $location = null)
    {
    }

    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        return $this->location;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        return $this->location;
    }

    public function findAllActive(): array
    {
        return $this->location !== null ? [$this->location] : [];
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        return $this->location !== null ? [$this->location] : [];
    }

    public function create(array $data): Location
    {
        throw new LogicException('Not used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del levantamiento vive en Machine y se certifica en su suite dedicada.
        return true;
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
        return 1;
    }
}

/** Rebuilds an immutable incident with some columns replaced. */
function rebuildVerdictIncident(Incident $incident, array $overrides = []): Incident
{
    $pick = static function (string $key, mixed $default) use ($overrides): mixed {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    };

    return new Incident(
        id: $pick('id', $incident->getId()),
        ticketCode: (string)$pick('ticketCode', $incident->getTicketCode()),
        machineId: (int)$pick('machineId', $incident->getMachineId()),
        locationId: (int)$pick('locationId', $incident->getLocationId()),
        category: $pick('category', $incident->getCategory()),
        description: (string)$pick('description', $incident->getDescription()),
        urgency: $pick('urgency', $incident->getUrgency()),
        status: $pick('status', $incident->getStatus()),
        assignedTechnicianId: $pick('assignedTechnicianId', $incident->getAssignedTechnicianId()),
        reporterName: $pick('reporterName', $incident->getReporterName()),
        reporterPhone: $pick('reporterPhone', $incident->getReporterPhone()),
        resolutionDiagnosis: $pick('resolutionDiagnosis', $incident->getResolutionDiagnosis()),
        resolutionAction: $pick('resolutionAction', $incident->getResolutionAction()),
        resolvedAt: $pick('resolvedAt', $incident->getResolvedAt()),
        startedAt: $pick('startedAt', $incident->getStartedAt()),
        createdAt: $pick('createdAt', $incident->getCreatedAt())
    );
}

/**
 * Doble en memoria de los ajustes preventivos (módulo 05).
 *
 * El cierre técnico consume el Reloj Sanitario Biológico (Art. II) antes de
 * escribir, y ese reloj necesita la tipología de la máquina y su semáforo
 * sanitario. Esta suite es de reintegros y declara no abrir un PDO, así que el
 * doble publica la máquina del banco con su tipología real —`COMBO`, que está
 * bajo vigilancia por alojar producto fresco— y la cadena de frío en orden: la
 * puerta deja pasar el cierre porque el expediente acaba de abrirse, no porque
 * la puerta esté desactivada.
 */
final class VerdictPreventiveSettingsRepo implements PreventiveSettingsRepositoryInterface
{
    public function findAll(): array
    {
        return [];
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        return true;
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        return true;
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        return true;
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        return true;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        return [
            'machine_id' => $machineId,
            'machine_type' => MachineType::COMBO->value,
            'sanitary_status' => 'OK',
            'sanitary_frequency_days' => 15,
        ];
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        return true;
    }
}

final class VerdictIncidentRepo implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    /** @var array<int, Incident> */
    public array $rows = [];

    private int $nextId = 1;

    public function seed(Incident $incident): Incident
    {
        $id = $incident->getId() ?? $this->nextId++;
        $this->rows[$id] = rebuildVerdictIncident($incident, ['id' => $id]);

        return $this->rows[$id];
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        return $this->seed($incident);
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
        foreach ($this->rows as $row) {
            if ($row->getMachineId() === $machineId && $row->getStatus()->isActive()) {
                return $row;
            }
        }

        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        return $this->findActiveByMachineId($machineId);
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

    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del levantamiento vive en Machine y se certifica en su suite dedicada.
        return true;
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
        return rebuildVerdictIncident($this->rows[$incidentId], ['status' => IncidentStatus::IN_PROGRESS]);
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        $current = $this->rows[$incidentId];
        $resolved = rebuildVerdictIncident($current, [
            'status' => IncidentStatus::RESOLVED,
            'resolutionDiagnosis' => $diagnosis,
            'resolutionAction' => $action,
            'resolvedAt' => '2026-10-02 11:00:00',
        ]);
        $this->rows[$incidentId] = $resolved;

        return $resolved;
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

function rebuildVerdictCase(RefundRequest $case, array $overrides = []): RefundRequest
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

final class VerdictRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildVerdictCase($refundRequest, ['id' => $id]);

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
        return [];
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
            // El repositorio PDO devuelve las columnas ENUM como cadenas; la
            // entidad las quiere tipadas. Sin esta conversión el fake mentía
            // sobre lo que se persiste.
            $value = match ((string)$column) {
                'technician_finding' => $value === null ? null : TechnicianFinding::from((string)$value),
                'cash_custody_action' => $value === null ? null : CashCustodyAction::from((string)$value),
                'coordinator_decision' => $value,
                default => $value,
            };

            $entityFields[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string)$column))))] = $value;
        }

        $this->rows[$id] = rebuildVerdictCase($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        return false;
    }

    public function detachFromIncident(int $id, \VendGuard\Core\Domain\Model\RefundStatus $newStatus): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la
        // desvinculación real se certifica en la suite dedicada del modulo 11.
        return true;
    }

    public function deactivate(int $id): bool
    {
        return false;
    }
}

final class VerdictFindingRepo implements UnclaimedCashFindingRepositoryInterface
{
    /** @var array<int, UnclaimedCashFinding> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(UnclaimedCashFinding $finding): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = new UnclaimedCashFinding(
            id: $id,
            incidentId: $finding->getIncidentId(),
            machineId: $finding->getMachineId(),
            technicianId: $finding->getTechnicianId(),
            amount: $finding->getAmount(),
            notes: $finding->getNotes(),
            createdAt: $finding->getCreatedAt()
        );

        return $id;
    }

    public function findById(int $id): ?UnclaimedCashFinding
    {
        return $this->rows[$id] ?? null;
    }

    public function findByIncident(int $incidentId): array
    {
        return array_values(array_filter($this->rows, static fn (UnclaimedCashFinding $f): bool => $f->getIncidentId() === $incidentId));
    }

    public function findByTechnician(int $technicianId, int $limit = 50): array
    {
        return [];
    }

    public function sumAmountByMachine(int $machineId): float
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            if ($row->getMachineId() === $machineId) {
                $total += $row->getAmount();
            }
        }

        return $total;
    }
}

final class VerdictAuditRepo implements AuditLogRepositoryInterface
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

/**
 * Spare-part resolution without the five spare-part repositories: this suite is
 * about the money verdict, and the parts machinery has its own coverage.
 */
final class StubTraceabilityService extends SparePartTraceabilityService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function resolveIncidentWithParts(int $incidentId, int $technicianId, array $data, ?array $actor = null): array
    {
        $this->calls++;

        return [
            'id' => $incidentId,
            'ticket_code' => '#TICK-TEST-VRD',
            'status' => 'RESOLVED',
            'resolved_at' => '2026-10-02 11:00:00',
            'replaced_parts_count' => 0,
            'total_parts_cost' => 0.0,
            'replaced_parts' => [],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

$refundRepo = new VerdictRefundRepo();
$findingRepo = new VerdictFindingRepo();
$auditRepo = new VerdictAuditRepo();
$incidentRepo = new VerdictIncidentRepo();

$ibanValidator = new IbanValidationService();
$management = new RefundManagementService($refundRepo, $ibanValidator, new AuditLogger($auditRepo));
$refundService = new TechnicianRefundService($refundRepo, $findingRepo, $management, new AuditLogger($auditRepo));

$machine = new Machine(
    id: MACHINE_ID,
    locationId: LOCATION_ID,
    code: 'VEND-0101',
    model: 'VendGuard 300',
    machineType: MachineType::COMBO,
    floorWing: 'Planta 0',
    isActive: true,
    createdAt: '2026-10-01 09:00:00'
);
$locationRepo = new VerdictLocationRepo(new Location(
    id: LOCATION_ID,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Carrer del Mar, 1',
    isActive: true,
    createdAt: '2026-01-01 09:00:00',
    latitude: 41.38,
    longitude: 2.18
));

$traceability = new StubTraceabilityService();

$technicianController = new TechnicianController(
    incidentRepo: $incidentRepo,
    machineRepo: new VerdictMachineRepo($machine),
    locationRepo: $locationRepo,
    userRepo: null,
    traceabilityService: $traceability,
    refundRepo: $refundRepo,
    refundService: $refundService,
    pauseService: new IncidentPauseService(
        settingsRepo: new VerdictPreventiveSettingsRepo(),
        incidentRepo: $incidentRepo
    )
);
$refundController = new TechnicianRefundController($incidentRepo, $refundRepo);

/**
 * Opens a claim against an incident.
 */
// `$contact` existe por RF-REF-08: varias personas pueden reclamar sobre la
// MISMA avería, y cada una es un expediente distinto. Lo que la regla prohíbe
// (RF-REF-11) es que el MISMO consumidor acumule dos, así que las reclamaciones
// compartidas deben traer contactos distintos, no el mismo teléfono tres veces.
// El teléfono de Bizum sigue al contacto para que el expediente sea coherente.
$openCase = static function (
    CompensationMethod $method,
    float $amount,
    int $incidentId,
    string $name = 'Laura Sanitaria',
    ?string $contact = null
) use ($management): RefundRequest {
    $contact ??= '600111222';

    return $management->createCase(new CreateRefundRequestDTO(
        incidentId: $incidentId,
        machineId: MACHINE_ID,
        locationId: LOCATION_ID,
        claimantName: $name,
        claimantContact: $contact,
        claimedAmount: $amount,
        compensationMethod: $method,
        productAttempted: 'Café con leche carril 2',
        bizumPhone: $method->requiresBizumPhone() ? $contact : null,
        iban: $method->requiresIban() ? 'ES9121000418450200051332' : null
    ));
};

/**
 * Opens a FRESH incident in progress and returns its id.
 *
 * Every scenario owns its incident on purpose: a verdict is filed against
 * every claim of an incident at once, so sharing one incident across scenarios
 * would make each verdict answer the previous scenario's claims too.
 */
$nextIncidentId = 300;
$makeIncident = static function (
    IncidentStatus $status = IncidentStatus::IN_PROGRESS,
    ?int $techId = TECH_ID
) use ($incidentRepo, &$nextIncidentId): int {
    $id = $nextIncidentId++;
    $incidentRepo->seed(new Incident(
        id: $id,
        ticketCode: '#TICK-TEST-V' . $id,
        machineId: MACHINE_ID,
        locationId: LOCATION_ID,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'La máquina cobra y no entrega el producto.',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: $techId,
        // Marca de apertura real: el Reloj Sanitario Biológico la necesita para
        // medir las 4 h naturales continuas (Art. II) antes de dejar cerrar.
        createdAt: date('Y-m-d H:i:s')
    ));

    return $id;
};

$showRequest = static function (?int $techId = TECH_ID, string $rawId = '101'): Request {
    $request = (new Request(method: 'GET', path: "/api/technician/incidents/{$rawId}/refund"))
        ->setRouteParams(['id' => $rawId]);

    // `null` es "sin técnico autenticado"; un `0` sería un id numérico válido
    // y no serviría para probar la guarda de 401.
    if ($techId !== null) {
        $request->setAttribute('user_id', $techId);
    }

    return $request;
};

$resolveRequest = static function (int $incidentId, array $body, ?int $techId = TECH_ID): Request {
    $request = (new Request(
        method: 'POST',
        path: '/api/technician/incidents/' . $incidentId . '/resolve',
        parsedBody: $body,
        headers: ['content-type' => 'application/json']
    ))
        ->setRouteParams(['id' => (string)$incidentId]);

    if ($techId !== null) {
        $request->setAttribute('user_id', $techId);
    }

    return $request;
};

$invokeShow = static function (Request $request) use ($refundController): Response|FaultedResponse {
    try {
        return $refundController->show($request);
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$invokeResolve = static function (Request $request) use ($technicianController): Response|FaultedResponse {
    try {
        return $technicianController->resolveIncident($request);
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$statusDetail = static fn (Response|FaultedResponse $response, string $prefix = 'estado: '): string => $response instanceof FaultedResponse
    ? 'fallo inesperado -> ' . $response->fault
    : $prefix . $response->getStatusCode();

// El controlador lee `resolution_diagnosis`/`diagnosis` y
// `resolution_action`/`action_taken`; con cualquier otro nombre la resolución se
// rechazaría por `INVALID_RESOLUTION` antes de llegar al bloque de saldo.
$VALID_RESOLUTION = [
    'diagnosis' => 'Moneda de 2 euros atascada en el embudo del selector mecánico.',
    'action_taken' => 'Desatasco del selector, limpieza de canaleta y prueba satisfactoria.',
];

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla ---\n";

$showSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/TechnicianRefundController.php');
$viewSource = (string)file_get_contents($baseDir . '/src/Application/DTO/TechnicianRefundViewDTO.php');
$techSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/TechnicianController.php');

$assert('0.1 El nuevo controlador declara tipado estricto', str_contains($showSource, 'declare(strict_types=1);'));
$assert('0.2 El DTO de vista declara tipado estricto', str_contains($viewSource, 'declare(strict_types=1);'));
$assert('0.3 El controlador técnico mantiene tipado estricto', str_contains($techSource, 'declare(strict_types=1);'));
$assert('0.4 El endpoint del técnico NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($showSource), 'DELETE FROM'));
$assert('0.5 La resolución con dictamen NO ejecuta ningún DELETE FROM (Art. III)', !str_contains(strtoupper($techSource), 'DELETE FROM'));

// ─────────────────────────────────────────────────────────────────────────────
// 1. El técnico lee las reclamaciones de la avería que va a reparar
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. GET .../refund: qué hay que dictaminar ---\n";

$sharedIncident = $makeIncident();
$inHand = $openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $sharedIncident, 'Laura Sanitaria', '600111222');
$bizum = $openCase(CompensationMethod::BIZUM, 15.00, $sharedIncident, 'Marc Ruibal', '600999888');
$transfer = $openCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 3.50, $sharedIncident, 'Rosa Palmera', '600777555');

$shown = $invokeShow($showRequest(TECH_ID, (string)$sharedIncident));
$shownBody = $shown->getDecodedBody();

$assert('1.1 La consulta del técnico devuelve HTTP 200', $shown->getStatusCode() === 200, $statusDetail($shown));
$assert('1.2 Declara que hay reclamaciones', ($shownBody['data']['has_refund_requests'] ?? false) === true);
$assert('1.3 Cuenta las tres reclamaciones', ($shownBody['data']['total_requests'] ?? 0) === 3);
$assert('1.4 Suma el importe reclamado', abs((float)($shownBody['data']['total_claimed_amount'] ?? 0) - 20.50) < 0.001, 'total: ' . ($shownBody['data']['total_claimed_amount'] ?? 'AUSENTE'));
$assert('1.5 Advierte de que hay dictamen pendiente', ($shownBody['data']['has_pending_verdict'] ?? false) === true);
$assert('1.6 Ofrece el catálogo cerrado de dictámenes', count($shownBody['data']['available_findings'] ?? []) === 3);
$assert(
    '1.7 Marca que UNVERIFIED_NO_CASH exige justificación',
    ($shownBody['data']['available_findings'][2]['requires_justification'] ?? false) === true
);
$assert('1.8 Lista cada expediente', count($shownBody['data']['requests'] ?? []) === 3);
$assert(
    '1.9 Cada expediente trae importe, producto, vía, estado e instrucción',
    array_keys($shownBody['data']['requests'][0] ?? []) === [
        'id',
        'claimed_amount',
        'product_attempted',
        'compensation_method',
        'status',
        'custody_instruction',
    ],
    'claves: ' . implode(',', array_keys($shownBody['data']['requests'][0] ?? []))
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. La vista del técnico nunca filtra datos bancarios ni identidad (Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. El técnico NO ve el IBAN, el Bizum ni el nombre (Art. V.4) ---\n";

$shownJson = (string)json_encode($shownBody, JSON_THROW_ON_ERROR);

$assert('2.1 El IBAN nunca aparece en la vista del técnico', !str_contains($shownJson, 'ES9121000418450200051332'));
$assert('2.2 La clave `iban` no existe en la proyección', !str_contains($shownJson, '"iban"'));
$assert('2.3 La clave `bizum_phone` no existe en la proyección', !str_contains($shownJson, 'bizum_phone'));
$assert('2.4 Ningún nombre de reclamante aparece', !str_contains($shownJson, 'Laura Sanitaria') && !str_contains($shownJson, 'Marc Ruibal') && !str_contains($shownJson, 'Rosa Palmera'));
$assert('2.5 Tampoco el teléfono de contacto del reclamante', !str_contains($shownJson, '600111222'));
$assert(
    '2.6 Control de no-vacuidad: el IBAN SÍ existe en el dominio que se está ocultando',
    $transfer->getIban() === 'ES9121000418450200051332' && $bizum->getBizumPhone() === '600999888',
    'iban: ' . var_export($transfer->getIban(), true) . ' bizum: ' . var_export($bizum->getBizumPhone(), true)
);

// La instrucción de custodia es lo único que el técnico necesita saber
$byId = [];
foreach ($shownBody['data']['requests'] ?? [] as $row) {
    $byId[(string)$row['id']] = $row;
}
$assert(
    '2.7 La reclamación en mano LE INDICA conserjería',
    str_contains($byId[(string)$inHand->getId()]['custody_instruction'] ?? '', 'recepción'),
    'instrucción: ' . ($byId[(string)$inHand->getId()]['custody_instruction'] ?? 'AUSENTE')
);
$assert(
    '2.8 La reclamación digital LE INDICA caja central',
    str_contains($byId[(string)$bizum->getId()]['custody_instruction'] ?? '', 'caja central'),
    'instrucción: ' . ($byId[(string)$bizum->getId()]['custody_instruction'] ?? 'AUSENTE')
);
$assert(
    '2.9 Una reclamación de importe elevado LE INDICA caja central',
    str_contains($byId[(string)$transfer->getId()]['custody_instruction'] ?? '', 'caja central'),
    'instrucción: ' . ($byId[(string)$transfer->getId()]['custody_instruction'] ?? 'AUSENTE')
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Autorización de la vista del técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. La vista del técnico respeta la propiedad de la avería ---\n";

$assert('3.1 Sin técnico autenticado se responde 401', $invokeShow($showRequest(null))->getStatusCode() === 401);
$assert(
    '3.2 Un técnico ajeno a la ruta se responde 403',
    $invokeShow($showRequest(OTHER_TECH_ID, (string)$sharedIncident))->getStatusCode() === 403
);
$assert(
    '3.3 Un ID de avería no numérico se responde 400',
    $invokeShow($showRequest(TECH_ID, 'abc'))->getStatusCode() === 400
);

$missingRequest = (new Request(method: 'GET', path: '/api/technician/incidents/999/refund'))
    ->setRouteParams(['id' => '999'])
    ->setAttribute('user_id', TECH_ID);
$assert('3.4 Una avería inexistente se responde 404', $invokeShow($missingRequest)->getStatusCode() === 404);

// ─────────────────────────────────────────────────────────────────────────────
// 4. El dictamen de saldo es obligatorio para poder resolver
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Sin dictamen no se puede cerrar una avería con reclamación ---\n";

$mandatoryIncident = $makeIncident();
$mandatoryCase = $openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $mandatoryIncident);
$assert(
    '4.0 Control de no-vacuidad: la reclamación está pendiente de dictamen',
    $refundRepo->findById((int)$mandatoryCase->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
);

$missingVerdict = $invokeResolve($resolveRequest($mandatoryIncident, $VALID_RESOLUTION));
$missingVerdictBody = $missingVerdict->getDecodedBody();

$assert('4.1 Sin `refund_inspection` se responde 422', $missingVerdict->getStatusCode() === 422, $statusDetail($missingVerdict));
$assert(
    '4.2 El 422 expone REFUND_INSPECTION_REQUIRED',
    ($missingVerdictBody['error']['code'] ?? '') === 'REFUND_INSPECTION_REQUIRED',
    'error: ' . ($missingVerdictBody['error']['code'] ?? 'AUSENTE')
);
$assert(
    '4.3 La avería NO se resuelve (sigue en curso)',
    $incidentRepo->findById($mandatoryIncident)?->getStatus() === IncidentStatus::IN_PROGRESS
);
$assert(
    '4.4 La reclamación NO se toca: sigue esperando inspección',
    $refundRepo->findById((int)$mandatoryCase->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
);

// Un dictamen con valor inventado tampoco vale
$bogusIncident = $makeIncident();
$openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $bogusIncident);
$bogusFinding = $invokeResolve($resolveRequest($bogusIncident, $VALID_RESOLUTION + [
    'refund_inspection' => ['finding' => 'ME_LO_COMI_LA_MAQUINA'],
]));
$assert('4.5 Un dictamen fuera del catálogo se responde 422', $bogusFinding->getStatusCode() === 422, $statusDetail($bogusFinding));
$assert('4.6 La avería sigue sin resolverse', $incidentRepo->findById($bogusIncident)?->getStatus() === IncidentStatus::IN_PROGRESS);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Los tres dictámenes y la custodia coherente (RF-REF-05)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Dictamen de saldo y custodia coherente ---\n";

// 5.1 Efectivo encontrado y dejado en conserjería: 2,00 € en mano
$receptionIncident = $makeIncident();
$receptionCase = $openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $receptionIncident);
$inHandVerdict = $invokeResolve($resolveRequest($receptionIncident, $VALID_RESOLUTION + [
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 2.00,
        'cash_custody_action' => 'LEFT_AT_RECEPTION',
        'receptionist_name' => 'Imposada (Conserjería Planta Baja)',
    ],
]));
$inHandVerdictBody = $inHandVerdict->getDecodedBody();
$receptionRow = $refundRepo->findById((int)$receptionCase->getId());

$assert('5.1 Con dictamen válido se resuelve la avería', $inHandVerdict->getStatusCode() === 200, $statusDetail($inHandVerdict));
$assert('5.2 La avería queda en RESOLVED', ($inHandVerdictBody['data']['status'] ?? '') === 'RESOLVED');
$assert('5.3 La respuesta expone el estado del expediente', ($inHandVerdictBody['data']['refund_status'] ?? '') === 'DEPOSITED_AT_RECEPTION', 'estado: ' . ($inHandVerdictBody['data']['refund_status'] ?? 'AUSENTE'));
$assert('5.4 El mensaje confirma el doble efecto', str_contains((string)($inHandVerdictBody['message'] ?? ''), 'dictamen de efectivo'));
$assert('5.5 El expediente queda en DEPOSITED_AT_RECEPTION', $receptionRow?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION);
$assert('5.6 Se registra quién recibió el sobre', ($receptionRow?->getReceptionistName() ?? '') !== '');
$assert('5.7 La custodia persistida es LEFT_AT_RECEPTION', $receptionRow?->getCashCustodyAction() === CashCustodyAction::LEFT_AT_RECEPTION);
$assert('5.8 El importe recuperado queda registrado', $receptionRow?->getRecoveredAmount() === 2.00);

// 5.2 Un canal digital NO puede quedarse en conserjería
$digitalIncident = $makeIncident();
$digitalCase = $openCase(CompensationMethod::BIZUM, 15.00, $digitalIncident);
$bizumVerdict = $invokeResolve($resolveRequest($digitalIncident, $VALID_RESOLUTION + [
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 15.00,
        'cash_custody_action' => 'LEFT_AT_RECEPTION',
    ],
]));
$bizumVerdictBody = $bizumVerdict->getDecodedBody();

$assert('5.9 Dejar efectivo digital en conserjería se responde 422', $bizumVerdict->getStatusCode() === 422, $statusDetail($bizumVerdict));
$assert(
    '5.10 El 422 expone RECEPTION_DELIVERY_NOT_ALLOWED',
    ($bizumVerdictBody['error']['code'] ?? '') === 'RECEPTION_DELIVERY_NOT_ALLOWED',
    'error: ' . ($bizumVerdictBody['error']['code'] ?? 'AUSENTE')
);
$assert(
    '5.11 La avería NO se resuelve: el técnico puede corregir el desplegable y reintentar',
    $incidentRepo->findById($digitalIncident)?->getStatus() === IncidentStatus::IN_PROGRESS
);
$assert(
    '5.12 La reclamación digital sigue pendiente de dictamen',
    $refundRepo->findById((int)$digitalCase->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
);

// 5.3 El técnico lo deja sin decidir: las reglas fuerzan caja central
$centralIncident = $makeIncident();
$centralCase = $openCase(CompensationMethod::BIZUM, 15.00, $centralIncident);
$centralVerdict = $invokeResolve($resolveRequest($centralIncident, $VALID_RESOLUTION + [
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 15.00,
    ],
]));
$centralRow = $refundRepo->findById((int)$centralCase->getId());

$assert('5.13 Sin custody explícita la resolución también prospera', $centralVerdict->getStatusCode() === 200, $statusDetail($centralVerdict));
$assert('5.14 Las reglas fuerzan HELD_FOR_CENTRAL en canal digital', $centralRow?->getCashCustodyAction() === CashCustodyAction::HELD_FOR_CENTRAL);
// 15,00 € supera el umbral de 10,00 €: además de custodia central, el importe
// obliga a pasar por Coordinación antes de pagar (RF-REF-03, doble visto bueno).
$assert(
    '5.15 El importe elevado escala además a Coordinación',
    $centralRow?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
    'estado: ' . $centralRow?->getStatus()->value
);

// 5.4 Un dictamen sin justificación es el que más merece revisión
$unjustifiedIncident = $makeIncident();
$openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $unjustifiedIncident);
$unjustified = $invokeResolve($resolveRequest($unjustifiedIncident, $VALID_RESOLUTION + [
    'refund_inspection' => ['finding' => 'UNVERIFIED_NO_CASH', 'justification' => 'No vi nada'],
]));
$unjustifiedBody = $unjustified->getDecodedBody();

$assert('5.16 UNVERIFIED_NO_CASH sin justificación se responde 422', $unjustified->getStatusCode() === 422, $statusDetail($unjustified));
$assert(
    '5.17 El 422 expone JUSTIFICATION_TOO_SHORT',
    ($unjustifiedBody['error']['code'] ?? '') === 'JUSTIFICATION_TOO_SHORT',
    'error: ' . ($unjustifiedBody['error']['code'] ?? 'AUSENTE')
);
$assert('5.18 La avería sigue sin resolverse', $incidentRepo->findById($unjustifiedIncident)?->getStatus() === IncidentStatus::IN_PROGRESS);

// 5.5 FOUND_PHYSICAL sin importe recuperado es una contradicción
$noAmountIncident = $makeIncident();
$openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $noAmountIncident);
$noAmount = $invokeResolve($resolveRequest($noAmountIncident, $VALID_RESOLUTION + [
    'refund_inspection' => ['finding' => 'FOUND_PHYSICAL'],
]));
$assert('5.19 FOUND_PHYSICAL sin importe se responde 422', $noAmount->getStatusCode() === 422, $statusDetail($noAmount));
$assert('5.20 La avería sigue sin resolverse', $incidentRepo->findById($noAmountIncident)?->getStatus() === IncidentStatus::IN_PROGRESS);

// 5.6 Discrepancia entre lo reclamado y lo recuperado (RF-REF-08)
$shortfallIncident = $makeIncident();
$shortfallCase = $openCase(CompensationMethod::EN_MANO_SEDE, 4.00, $shortfallIncident);
$shortfall = $invokeResolve($resolveRequest($shortfallIncident, $VALID_RESOLUTION + [
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 1.00,
        'cash_custody_action' => 'LEFT_AT_RECEPTION',
    ],
]));
$shortfallBody = $shortfall->getDecodedBody();

$assert('5.21 Una discrepancia se resuelve pero avisa', $shortfall->getStatusCode() === 200, $statusDetail($shortfall));
$assert('5.22 La respuesta marca la discrepancia', ($shortfallBody['data']['refund_discrepancy'] ?? false) === true);
$assert(
    '5.23 El importe que no cubre lo reclamado escala a Coordinación',
    $refundRepo->findById((int)$shortfallCase->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Desacoplamiento: la máquina reparada vuelve a vender (RF-REF-09)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. La avería técnica se resuelve aunque el dinero siga su camino ---\n";

$decoupledIncident = $makeIncident();
$decoupledCase = $openCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 3.50, $decoupledIncident);
$eventsBefore = count($auditRepo->events);
$decoupled = $invokeResolve($resolveRequest($decoupledIncident, $VALID_RESOLUTION + [
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 3.50,
    ],
]));
$decoupledBody = $decoupled->getDecodedBody();

$assert('6.1 La máquina reparada vuelve a RESOLVED', ($decoupledBody['data']['status'] ?? '') === 'RESOLVED');
$assert(
    '6.2 El expediente NO se liquida por resolver la avería (desacoplamiento)',
    $refundRepo->findById((int)$decoupledCase->getId())?->getStatus() !== RefundStatus::REFUNDED_IN_HAND
    && $refundRepo->findById((int)$decoupledCase->getId())?->getStatus() !== RefundStatus::PAID_DIGITAL,
    'estado: ' . $refundRepo->findById((int)$decoupledCase->getId())?->getStatus()->value
);
$assert('6.3 El dictamen sí quedó auditado', count($auditRepo->events) > $eventsBefore);
$assert(
    '6.4 Control de no-vacuidad: existe el evento REFUND_INSPECTED',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_INSPECTED')) > 0
);

// Una avería SIN reclamación se comporta exactamente como antes de T-REF-10
$plainIncident = $makeIncident(IncidentStatus::IN_PROGRESS, TECH_ID);
$plainResolution = $invokeResolve($resolveRequest($plainIncident, $VALID_RESOLUTION));
$plainBody = $plainResolution->getDecodedBody();

$assert('6.5 Una avería sin reclamación se resuelve sin pedir dictamen', $plainResolution->getStatusCode() === 200, $statusDetail($plainResolution));
$assert('6.6 La respuesta NO inventa un estado de reintegro', !array_key_exists('refund_status', $plainBody['data'] ?? []));
$assert(
    '6.7 La vista del técnico informa de que no hay nada que dictaminar',
    ($invokeShow($showRequest(TECH_ID, (string)$plainIncident))->getDecodedBody()['data']['has_refund_requests'] ?? true) === false
);
$assert(
    '6.8 Y tampoco ofrece un catálogo de dictámenes que no aplican',
    ($invokeShow($showRequest(TECH_ID, (string)$plainIncident))->getDecodedBody()['data']['has_pending_verdict'] ?? true) === false
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Hallazgo de monedas de oficio (RF-REF-04)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Efectivo recuperado sin reclamación previa ---\n";

$findingIncident = $makeIncident();
$findingsBefore = count($findingRepo->rows);
$withFinding = $invokeResolve($resolveRequest($findingIncident, $VALID_RESOLUTION + [
    'unclaimed_cash_found' => ['amount' => 1.00, 'notes' => 'Moneda de un euro en la canaleta.'],
]));
$withFindingBody = $withFinding->getDecodedBody();
$findingId = (int)($withFindingBody['data']['unclaimed_cash_finding_id'] ?? 0);

$assert('7.1 El hallazgo de oficio no impide resolver', $withFinding->getStatusCode() === 200, $statusDetail($withFinding));
$assert('7.2 Se registra el hallazgo', count($findingRepo->rows) === $findingsBefore + 1);
$assert('7.3 La respuesta expone el identificador del hallazgo', $findingId > 0);
$assert('7.4 El hallazgo queda a nombre del técnico que lo recogió', $findingRepo->findById($findingId)?->getTechnicianId() === TECH_ID);
$assert('7.5 El importe del hallazgo es el declarado', $findingRepo->findById($findingId)?->getAmount() === 1.00);
$assert(
    '7.6 El hallazgo se ancla a la avería intervenida',
    $findingRepo->findById($findingId)?->getIncidentId() === $findingIncident
);

$zeroIncident = $makeIncident();
$zeroFinding = $invokeResolve($resolveRequest($zeroIncident, $VALID_RESOLUTION + [
    'unclaimed_cash_found' => ['amount' => 0],
]));
// La guarda del importe vive en dos capas (payload y entidad `UnclaimedCashFinding`),
// así que la aserción comprueba el efecto observable y no cuál de las dos saltó.
$assert('7.7 Un hallazgo de importe cero se responde 422', $zeroFinding->getStatusCode() === 422, $statusDetail($zeroFinding));
$assert('7.8 No se registra ningún hallazgo con importe cero', count($findingRepo->rows) === $findingsBefore + 1);
$assert('7.9 La avería sigue sin resolverse', $incidentRepo->findById($zeroIncident)?->getStatus() === IncidentStatus::IN_PROGRESS);

// ─────────────────────────────────────────────────────────────────────────────
// 8. La ruta con registro de repuestos también arrastra el dictamen
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Resolución con repuestos y dictamen de saldo ---\n";

$partsIncident = $makeIncident();
$partsCase = $openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $partsIncident);
$partsCallsBefore = $traceability->calls;
$withParts = $invokeResolve($resolveRequest($partsIncident, $VALID_RESOLUTION + [
    'replaced_parts_declared' => false,
    'refund_inspection' => [
        'finding' => 'FOUND_PHYSICAL',
        'recovered_amount' => 2.00,
        'cash_custody_action' => 'LEFT_AT_RECEPTION',
    ],
]));
$withPartsBody = $withParts->getDecodedBody();

$assert('8.1 La ruta con repuestos sigue funcionando', $withParts->getStatusCode() === 200, $statusDetail($withParts));
$assert('8.2 Se invocó el servicio de trazabilidad de repuestos', $traceability->calls === $partsCallsBefore + 1);
$assert('8.3 También arrastra el estado del expediente de saldo', ($withPartsBody['data']['refund_status'] ?? '') === 'DEPOSITED_AT_RECEPTION', 'estado: ' . ($withPartsBody['data']['refund_status'] ?? 'AUSENTE'));
$assert('8.4 Añade el identificador de la avería al contrato §4.2.2', ($withPartsBody['data']['incident_id'] ?? 0) === $partsIncident);
$assert('8.5 El expediente quedó dictaminado igualmente', $refundRepo->findById((int)$partsCase->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION);

$noVerdictIncident = $makeIncident();
$openCase(CompensationMethod::EN_MANO_SEDE, 2.00, $noVerdictIncident);
$partsWithoutVerdict = $invokeResolve($resolveRequest($noVerdictIncident, $VALID_RESOLUTION + ['replaced_parts_declared' => false]));
$assert('8.6 La exigencia de dictamen también aplica con repuestos', $partsWithoutVerdict->getStatusCode() === 422, $statusDetail($partsWithoutVerdict));
$assert('8.7 Ni siquiera se llegó a tocar los repuestos', $traceability->calls === $partsCallsBefore + 1);

// ─────────────────────────────────────────────────────────────────────────────
// 9. La auditoría del dictamen no arrastra datos financieros (Art. V.4 + RNF-REF-01)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 9. RNF-REF-01 y Art. V.4 en la auditoría del técnico ---\n";

$auditJson = (string)json_encode(
    array_map(static fn (AuditEvent $e): array => $e->toArray(), $auditRepo->events),
    JSON_THROW_ON_ERROR
);

$assert('9.1 La auditoría NO arrastra ningún IBAN', !str_contains($auditJson, 'ES9121000418450200051332'));
$assert('9.2 La auditoría NO arrastra el teléfono de Bizum', !str_contains($auditJson, '600111222'));
$assert('9.3 La auditoría NO arrastra el nombre del reclamante', !str_contains($auditJson, 'Laura Sanitaria'));

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-10 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
