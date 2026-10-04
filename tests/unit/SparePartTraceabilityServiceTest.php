<?php

declare(strict_types=1);

/**
 * SparePartTraceabilityServiceTest
 * 
 * Test Unitario para SparePartTraceabilityService (Tarea T-SPARE-08).
 * Valida reglas de negocio de trazabilidad y consumo de repuestos (RF-REP-03 a RF-REP-07):
 * - Pausa técnica estructurada por repuestos con validación de modelos compatibles (RF-REP-03).
 * - Pausa excepcional por piezas fuera de catálogo exigiendo justificación >= 20 caracteres (RF-REP-04).
 * - Resolución de averías exigiendo declaración obligatoria Sí/No (RF-REP-05).
 * - Congelación inmutable del snapshot de costes (unit_cost_snapshot) blindado contra fluctuaciones (RF-REP-06, Art. III).
 * - Inmutabilidad estricta: cambios posteriores en el coste de referencia maestro NO alteran las intervenciones pasadas.
 * - Registro de piezas sustituidas en preventivos periódicos (RF-REP-07).
 * - Ciclo de vida de solicitudes de repuesto (PENDING -> ATTENDED / CANCELLED).
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Exception\IncompatibleSparePartException;
use VendGuard\Core\Domain\Exception\InvalidOutOfCatalogJustificationException;
use VendGuard\Core\Domain\Exception\InvalidPartQuantityException;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "==========================================================================\n";
echo " VendGuard: Test Unitario - SparePartTraceabilityServiceTest (T-SPARE-08)\n";
echo "==========================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

// =============================================================================
// Mocks en Memoria para Pruebas Unitarias Aisladas (Clean Architecture)
// =============================================================================

class MockSparePartRequestRepository implements SparePartRequestRepositoryInterface
{
    /** @var array<int, SparePartRequest> */
    public array $requests = [];
    private int $nextId = 1;

    public function createRequest(SparePartRequest $request): SparePartRequest
    {
        $id = $this->nextId++;
        $created = new SparePartRequest(
            id: $id,
            incidentId: $request->getIncidentId(),
            sparePartId: $request->getSparePartId(),
            isOutOfCatalog: $request->isOutOfCatalog(),
            customPartDescription: $request->getCustomPartDescription(),
            quantity: $request->getQuantity(),
            status: $request->getStatus(),
            requestedByUserId: $request->getRequestedByUserId(),
            partCode: $request->getPartCode(),
            partName: $request->getPartName(),
            technicianName: $request->getTechnicianName(),
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );
        $this->requests[$id] = $created;
        return $created;
    }

    public function findById(int $id): ?SparePartRequest
    {
        return $this->requests[$id] ?? null;
    }

    public function findByIncidentId(int $incidentId): array
    {
        return array_values(array_filter(
            $this->requests,
            fn(SparePartRequest $r) => $r->getIncidentId() === $incidentId
        ));
    }

    public function markAttendedByIncident(int $incidentId): int
    {
        $updated = 0;
        foreach ($this->requests as $id => $req) {
            if ($req->getIncidentId() === $incidentId && $req->getStatus() === SparePartRequestStatus::PENDING) {
                $this->requests[$id] = new SparePartRequest(
                    id: $req->getId(),
                    incidentId: $req->getIncidentId(),
                    sparePartId: $req->getSparePartId(),
                    isOutOfCatalog: $req->isOutOfCatalog(),
                    customPartDescription: $req->getCustomPartDescription(),
                    quantity: $req->getQuantity(),
                    status: SparePartRequestStatus::ATTENDED,
                    requestedByUserId: $req->getRequestedByUserId(),
                    partCode: $req->getPartCode(),
                    partName: $req->getPartName(),
                    technicianName: $req->getTechnicianName(),
                    createdAt: $req->getCreatedAt(),
                    updatedAt: date('Y-m-d H:i:s')
                );
                $updated++;
            }
        }
        return $updated;
    }

    public function markCancelledByIncident(int $incidentId): int
    {
        $updated = 0;
        foreach ($this->requests as $id => $req) {
            if ($req->getIncidentId() === $incidentId && $req->getStatus() === SparePartRequestStatus::PENDING) {
                $this->requests[$id] = new SparePartRequest(
                    id: $req->getId(),
                    incidentId: $req->getIncidentId(),
                    sparePartId: $req->getSparePartId(),
                    isOutOfCatalog: $req->isOutOfCatalog(),
                    customPartDescription: $req->getCustomPartDescription(),
                    quantity: $req->getQuantity(),
                    status: SparePartRequestStatus::CANCELLED,
                    requestedByUserId: $req->getRequestedByUserId(),
                    partCode: $req->getPartCode(),
                    partName: $req->getPartName(),
                    technicianName: $req->getTechnicianName(),
                    createdAt: $req->getCreatedAt(),
                    updatedAt: date('Y-m-d H:i:s')
                );
                $updated++;
            }
        }
        return $updated;
    }

    public function findPendingOutOfCatalogReviews(): array
    {
        $results = [];
        foreach ($this->requests as $req) {
            if ($req->isOutOfCatalog() && $req->getStatus() === SparePartRequestStatus::PENDING) {
                $results[] = $req->toArray();
            }
        }
        return $results;
    }
}

class MockIncidentReplacedPartRepository implements IncidentReplacedPartRepositoryInterface
{
    /** @var array<int, IncidentReplacedPart> */
    public array $parts = [];
    private int $nextId = 1;

    public function insertReplacedPart(IncidentReplacedPart $part): IncidentReplacedPart
    {
        $id = $this->nextId++;
        $created = new IncidentReplacedPart(
            id: $id,
            interventionType: $part->getInterventionType(),
            incidentId: $part->getIncidentId(),
            preventiveOrderId: $part->getPreventiveOrderId(),
            machineId: $part->getMachineId(),
            locationId: $part->getLocationId(),
            technicianId: $part->getTechnicianId(),
            sparePartId: $part->getSparePartId(),
            isOutOfCatalog: $part->isOutOfCatalog(),
            customPartName: $part->getCustomPartName(),
            quantity: $part->getQuantity(),
            unitCostSnapshot: $part->getUnitCostSnapshot(),
            oldPartDestination: $part->getOldPartDestination(),
            notes: $part->getNotes(),
            installedAt: $part->getInstalledAt() ?: date('Y-m-d H:i:s'),
            createdAt: date('Y-m-d H:i:s'),
            partCode: $part->getPartCode(),
            partName: $part->getPartName(),
            machineCode: $part->getMachineCode(),
            machineModel: $part->getMachineModel(),
            locationName: $part->getLocationName(),
            technicianName: $part->getTechnicianName()
        );
        $this->parts[$id] = $created;
        return $created;
    }

    public function insertManyReplacedParts(array $parts): array
    {
        $results = [];
        foreach ($parts as $p) {
            $results[] = $this->insertReplacedPart($p);
        }
        return $results;
    }

    public function findById(int $id): ?IncidentReplacedPart
    {
        return $this->parts[$id] ?? null;
    }

    public function findByIncidentId(int $incidentId): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(IncidentReplacedPart $p) => $p->getIncidentId() === $incidentId
        ));
    }

    public function findByPreventiveOrderId(int $preventiveOrderId): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(IncidentReplacedPart $p) => $p->getPreventiveOrderId() === $preventiveOrderId
        ));
    }

    public function getCostSummaryByMachineModel(?int $periodDays = null): array
    {
        return [];
    }

    public function getCostSummaryByLocation(?int $periodDays = null): array
    {
        return [];
    }

    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array
    {
        return [];
    }

    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array
    {
        return [];
    }

    public function findAllForExport(?int $periodDays = null): array
    {
        return [];
    }
}

class MockSparePartRepository implements SparePartRepositoryInterface
{
    /** @var array<int, SparePart> */
    public array $parts = [];

    public function create(SparePart $sparePart): SparePart
    {
        $id = $sparePart->getId() ?? count($this->parts) + 1;
        $this->parts[$id] = $sparePart;
        return $sparePart;
    }

    public function update(SparePart $sparePart): bool
    {
        $id = (int)$sparePart->getId();
        if (!isset($this->parts[$id])) {
            return false;
        }
        $this->parts[$id] = $sparePart;
        return true;
    }

    public function findById(int $id): ?SparePart
    {
        return $this->parts[$id] ?? null;
    }

    public function findByCode(string $partCode): ?SparePart
    {
        foreach ($this->parts as $p) {
            if (strcasecmp($p->getPartCode(), $partCode) === 0) {
                return $p;
            }
        }
        return null;
    }

    public function isCodeExists(string $partCode, ?int $excludeId = null): bool
    {
        return false;
    }

    public function findAll(?string $search = null, ?string $category = null, ?string $machineModel = null, ?bool $isActive = null): array
    {
        return array_values($this->parts);
    }

    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(SparePart $p) => $p->isCompatibleWithModel($machineModel) && (!$onlyActive || $p->isActive())
        ));
    }

    public function softDelete(int $id): bool
    {
        return true;
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (isset($this->parts[$id])) {
            $p = $this->parts[$id];
            $this->parts[$id] = new SparePart(
                $p->getId(),
                $p->getPartCode(),
                $p->getName(),
                $p->getCategory(),
                $p->getManufacturer(),
                $p->getReferenceCost(),
                $isActive,
                $p->getNotes(),
                $p->getCompatibleModels()
            );
            return true;
        }
        return false;
    }

    public function findDistinctMachineModels(): array
    {
        return [];
    }
}

class MockIncidentRepository implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    /** @var array<int, Incident> */
    public array $incidents = [];

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $this->incidents[$incident->getId()] = $incident;
        return $incident;
    }

    public function findById(int $id): ?Incident
    {
        return $this->incidents[$id] ?? null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return array_values($this->incidents); }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool
    {
        $this->incidents[$incident->getId()] = $incident;
        return true;
    }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return true; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { return $this->incidents[$incidentId]; }
    public function reopen(int $incidentId, string $reasonText): Incident { return $this->incidents[$incidentId]; }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { return $this->incidents[$incidentId]; }
    public function startIntervention(int $incidentId, int $technicianId): Incident { return $this->incidents[$incidentId]; }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        $inc = $this->incidents[$incidentId] ?? null;
        if ($inc === null) {
            throw new DomainException("Incidencia no encontrada");
        }
        $updated = new Incident(
            id: $inc->getId(),
            ticketCode: $inc->getTicketCode(),
            machineId: $inc->getMachineId(),
            locationId: $inc->getLocationId(),
            category: $inc->getCategory(),
            description: $inc->getDescription(),
            urgency: $inc->getUrgency(),
            status: IncidentStatus::PENDING_PARTS,
            assignedTechnicianId: $inc->getAssignedTechnicianId(),
            reporterName: $inc->getReporterName(),
            reporterPhone: $inc->getReporterPhone(),
            retainedMoneyAmount: $inc->getRetainedMoneyAmount(),
            photoPath: $inc->getPhotoPath(),
            assignedAt: $inc->getAssignedAt(),
            startedAt: $inc->getStartedAt(),
            pendingPartsReason: $pendingPartsReason,
            resolutionDiagnosis: $inc->getResolutionDiagnosis(),
            resolutionAction: $inc->getResolutionAction(),
            resolvedAt: $inc->getResolvedAt(),
            machineModel: $inc->getMachineModel()
        );
        $this->incidents[$incidentId] = $updated;
        return $updated;
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        $inc = $this->incidents[$incidentId] ?? null;
        if ($inc === null) {
            throw new DomainException("Incidencia no encontrada");
        }
        $updated = new Incident(
            id: $inc->getId(),
            ticketCode: $inc->getTicketCode(),
            machineId: $inc->getMachineId(),
            locationId: $inc->getLocationId(),
            category: $inc->getCategory(),
            description: $inc->getDescription(),
            urgency: $inc->getUrgency(),
            status: IncidentStatus::RESOLVED,
            assignedTechnicianId: $inc->getAssignedTechnicianId(),
            reporterName: $inc->getReporterName(),
            reporterPhone: $inc->getReporterPhone(),
            retainedMoneyAmount: $inc->getRetainedMoneyAmount(),
            photoPath: $inc->getPhotoPath(),
            assignedAt: $inc->getAssignedAt(),
            startedAt: $inc->getStartedAt(),
            pendingPartsReason: $inc->getPendingPartsReason(),
            resolutionDiagnosis: $diagnosis,
            resolutionAction: $action,
            resolvedAt: date('Y-m-d H:i:s'),
            machineModel: $inc->getMachineModel()
        );
        $this->incidents[$incidentId] = $updated;
        return $updated;
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
}

class MockMachineRepository implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[$id] ?? null;
    }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new RuntimeException("Not implemented"); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

// =============================================================================
// Fixtures Base de Prueba
// =============================================================================

function createTestEnvironment(): array {
    $requestRepo = new MockSparePartRequestRepository();
    $replacedPartRepo = new MockIncidentReplacedPartRepository();
    $sparePartRepo = new MockSparePartRepository();
    $incidentRepo = new MockIncidentRepository();
    $machineRepo = new MockMachineRepository();

    // 1. Repuestos de prueba
    $part1 = new SparePart(
        id: 1,
        partCode: 'VALV-ULKA-01',
        name: 'Electroválvula 24V Ulka',
        category: SparePartCategory::HYDRAULIC,
        manufacturer: 'Ulka',
        referenceCost: 28.50,
        isActive: true,
        notes: 'Repuesto bomba de agua',
        compatibleModels: ['Azkoyen Palma+', 'Azkoyen Palma B']
    );
    $sparePartRepo->create($part1);

    $part2 = new SparePart(
        id: 2,
        partCode: 'SOND-NTC-02',
        name: 'Sonda Térmica NTC Frío 10k',
        category: SparePartCategory::THERMAL,
        manufacturer: 'Carel',
        referenceCost: 15.20,
        isActive: true,
        notes: 'Sonda evaporador',
        compatibleModels: ['Fas Fast 900']
    );
    $sparePartRepo->create($part2);

    // 2. Máquina dispensadora
    $machine = new Machine(
        id: 10,
        locationId: 5,
        code: 'MAQ-MAD-001',
        model: 'Azkoyen Palma+',
        machineType: MachineType::HOT_DRINKS,
        floorWing: 'Planta 1 - Descanso'
    );
    $machineRepo->machines[10] = $machine;

    // 3. Incidencia en curso (IN_PROGRESS)
    $incident = new Incident(
        id: 101,
        ticketCode: 'TICK-2026-00101',
        machineId: 10,
        locationId: 5,
        category: IncidentCategory::OTHER,
        description: 'Fuga de agua constante bajo el dispensador',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::IN_PROGRESS,
        assignedTechnicianId: 42,
        machineModel: 'Azkoyen Palma+'
    );
    $incidentRepo->create($incident);

    $service = new SparePartTraceabilityService(
        requestRepo: $requestRepo,
        replacedPartRepo: $replacedPartRepo,
        sparePartRepo: $sparePartRepo,
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo
    );

    return [
        'service'          => $service,
        'requestRepo'      => $requestRepo,
        'replacedPartRepo' => $replacedPartRepo,
        'sparePartRepo'    => $sparePartRepo,
        'incidentRepo'     => $incidentRepo,
        'machineRepo'      => $machineRepo,
    ];
}

// =============================================================================
// 1. Pausa Técnica Estructurada por Repuestos (RF-REP-03 / RF-REP-04)
// =============================================================================
echo "--- 1. Pausa Técnica Estructurada (RF-REP-03 / RF-REP-04) ---\n";

$env = createTestEnvironment();
$service = $env['service'];
$requestRepo = $env['requestRepo'];
$incidentRepo = $env['incidentRepo'];

// 1.1 Pausa exitosa con repuesto compatible
$pausePayload = [
    'is_out_of_catalog' => false,
    'requested_parts' => [
        ['spare_part_id' => 1, 'quantity' => 2],
    ],
];
$pauseRes = $service->pauseIncidentWithParts(101, 42, $pausePayload);

$assert(
    "Pausa exitosa transiciona incidencia a PENDING_PARTS",
    $pauseRes['status'] === IncidentStatus::PENDING_PARTS->value
);
$assert(
    "Pausa genera solicitud estructurada en repositorio",
    count($pauseRes['requests']) === 1 && $pauseRes['requests'][0]['spare_part_id'] === 1
);
$assert(
    "Solicitud creada tiene cantidad 2 y estado PENDING",
    $pauseRes['requests'][0]['quantity'] === 2 && $pauseRes['requests'][0]['status'] === 'PENDING'
);
$assert(
    "Incidencia registra motivo estructurado de pausa con código y unidades",
    str_contains($pauseRes['pending_parts_reason'], 'VALV-ULKA-01 (2 uds)')
);

// 1.2 Rechazo de pausa con lista de repuestos vacía (MISSING_PARTS_REQUEST)
$env = createTestEnvironment();
$service = $env['service'];
$emptyPartsPayload = [
    'is_out_of_catalog' => false,
    'requested_parts' => [],
];
try {
    $service->pauseIncidentWithParts(101, 42, $emptyPartsPayload);
    $assert("Rechaza pausa sin piezas si is_out_of_catalog es false", false);
} catch (InvalidArgumentException $e) {
    $assert(
        "Rechaza pausa sin piezas con InvalidArgumentException",
        str_contains($e->getMessage(), 'al menos un repuesto compatible')
    );
}

// 1.3 Rechazo de pausa con pieza incompatible (INCOMPATIBLE_SPARE_PART)
// Reestablecer incidencia a IN_PROGRESS para probar incompatibilidad
$env = createTestEnvironment();
$incompatiblePayload = [
    'is_out_of_catalog' => false,
    'requested_parts' => [
        ['spare_part_id' => 2, 'quantity' => 1], // SOND-NTC-02 solo compatible con 'Fas Fast 900', máquina es 'Azkoyen Palma+'
    ],
];
try {
    $env['service']->pauseIncidentWithParts(101, 42, $incompatiblePayload);
    $assert("Rechaza repuesto incompatible con modelo de máquina", false);
} catch (IncompatibleSparePartException $e) {
    $assert(
        "Rechaza repuesto incompatible lanzando IncompatibleSparePartException (HTTP 422)",
        $e->getCode() === 422 && str_contains($e->getMessage(), 'no es compatible con el modelo')
    );
}

// 1.4 Rechazo de pausa con cantidad inválida (< 1 o > 50)
$env = createTestEnvironment();
$invalidQtyPayload = [
    'is_out_of_catalog' => false,
    'requested_parts' => [
        ['spare_part_id' => 1, 'quantity' => 0],
    ],
];
try {
    $env['service']->pauseIncidentWithParts(101, 42, $invalidQtyPayload);
    $assert("Rechaza cantidad 0 en solicitud de repuesto", false);
} catch (InvalidPartQuantityException $e) {
    $assert(
        "Rechaza cantidad 0 lanzando InvalidPartQuantityException (HTTP 422)",
        $e->getCode() === 422 && str_contains($e->getMessage(), 'entre 1 y 50')
    );
}

// 1.5 Pausa excepcional fuera de catálogo con justificación válida (>= 20 caracteres)
$env = createTestEnvironment();
$outOfCatPayload = [
    'is_out_of_catalog' => true,
    'custom_part_description' => 'Sensor de caída infrarrojo especial para tolva número 3',
    'quantity' => 1,
];
$outOfCatRes = $env['service']->pauseIncidentWithParts(101, 42, $outOfCatPayload);

$assert(
    "Pausa fuera de catálogo transiciona a PENDING_PARTS",
    $outOfCatRes['status'] === IncidentStatus::PENDING_PARTS->value
);
$assert(
    "Pausa fuera de catálogo genera SparePartRequest con is_out_of_catalog = true",
    $outOfCatRes['requests'][0]['is_out_of_catalog'] === true &&
    $outOfCatRes['requests'][0]['custom_part_description'] === 'Sensor de caída infrarrojo especial para tolva número 3'
);

// 1.6 Rechazo de justificación fuera de catálogo insuficiente (< 20 caracteres)
$env = createTestEnvironment();
$shortDescPayload = [
    'is_out_of_catalog' => true,
    'custom_part_description' => 'Sensor roto tolva', // 17 caracteres
];
try {
    $env['service']->pauseIncidentWithParts(101, 42, $shortDescPayload);
    $assert("Rechaza justificación de repuesto < 20 caracteres", false);
} catch (InvalidOutOfCatalogJustificationException $e) {
    $assert(
        "Rechaza justificación corta lanzando InvalidOutOfCatalogJustificationException (HTTP 422)",
        $e->getCode() === 422 && $e->getAttemptedLength() === 17 && $e->getMinimumLength() === 20
    );
}

// 1.7 Rechazo de pausa por técnico no asignado
$env = createTestEnvironment();
try {
    $env['service']->pauseIncidentWithParts(101, 999, $pausePayload);
    $assert("Rechaza pausa solicitada por un técnico no asignado", false);
} catch (DomainException $e) {
    $assert(
        "Rechaza pausa de técnico no asignado con DomainException",
        str_contains($e->getMessage(), 'no está asignada al técnico')
    );
}

// =============================================================================
// 2. Resolución de Incidencia y Congelación de Snapshot de Coste (RF-REP-05, RF-REP-06, Art. III)
// =============================================================================
echo "\n--- 2. Resolución y Congelación Inmutable de Costes (RF-REP-05, RF-REP-06, Art. III) ---\n";

$env = createTestEnvironment();
$service = $env['service'];
$replacedRepo = $env['replacedPartRepo'];
$requestRepo = $env['requestRepo'];
$sparePartRepo = $env['sparePartRepo'];

// Pre-crear una solicitud previa de pausa para verificar paso a ATTENDED
$requestRepo->createRequest(new SparePartRequest(
    id: null,
    incidentId: 101,
    sparePartId: 1,
    isOutOfCatalog: false,
    customPartDescription: null,
    quantity: 2,
    status: SparePartRequestStatus::PENDING,
    requestedByUserId: 42
));

// 2.1 Resolución exitosa con piezas declaradas y cálculo de coste
$resolvePayload = [
    'resolution_diagnosis' => 'Rotura de membrana interna en electroválvula de entrada de caldera',
    'resolution_action' => 'Sustitución completa de electroválvula y prueba de estanqueidad a 2.5 bar',
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        [
            'spare_part_id' => 1,
            'is_out_of_catalog' => false,
            'quantity' => 2,
            'old_part_destination' => 'TALLER',
            'notes' => 'Bobina eléctrica intacta para reacondicionar',
        ],
    ],
];

$resolveRes = $service->resolveIncidentWithParts(101, 42, $resolvePayload);

$assert(
    "Resolución transiciona incidencia a estado RESOLVED",
    $resolveRes['status'] === IncidentStatus::RESOLVED->value
);
$assert(
    "Resolución contabiliza 1 registro de repuesto sustituido",
    $resolveRes['replaced_parts_count'] === 1
);
$assert(
    "Coste total calculado correctamente (2 uds * 28.50 = 57.00)",
    $resolveRes['total_parts_cost'] === 57.00
);
$assert(
    "Snapshot de coste unitario congelado a 28.50 en el registro",
    $resolveRes['replaced_parts'][0]['unit_cost_snapshot'] === 28.50 &&
    $resolveRes['replaced_parts'][0]['total_cost_snapshot'] === 57.00
);
$assert(
    "Destino del repuesto retirado registrado como TALLER",
    $resolveRes['replaced_parts'][0]['old_part_destination'] === 'TALLER'
);

// 2.2 Verificación de paso de solicitud PENDING a ATTENDED
$incidentRequests = $requestRepo->findByIncidentId(101);
$assert(
    "Solicitud estructurada previa pasa de PENDING a ATTENDED tras la resolución",
    count($incidentRequests) === 1 && $incidentRequests[0]->getStatus() === SparePartRequestStatus::ATTENDED
);

// =============================================================================
// 3. INVARIANTE FUNDAMENTAL: Inmutabilidad del Snapshot Histórico (Constitución Art. III)
// =============================================================================
echo "\n--- 3. Invariante de Inmutabilidad del Snapshot de Costes (Art. III) ---\n";

// Modificar el coste de referencia en el catálogo maestro para simular una fluctuación de proveedor
$catalogPart1 = $sparePartRepo->findById(1);
$updatedCatalogPart1 = new SparePart(
    id: $catalogPart1->getId(),
    partCode: $catalogPart1->getPartCode(),
    name: $catalogPart1->getName(),
    category: $catalogPart1->getCategory(),
    manufacturer: $catalogPart1->getManufacturer(),
    referenceCost: 49.90, // Subida sustancial de 28.50 a 49.90 €
    isActive: $catalogPart1->isActive(),
    notes: 'Subida de precio por inflación del fabricante Ulka',
    compatibleModels: $catalogPart1->getCompatibleModels()
);
$sparePartRepo->update($updatedCatalogPart1);

// Verificar en el catálogo que el precio maestro cambió
$assert(
    "El precio de referencia maestro del catálogo ha subido a 49.90 €",
    $sparePartRepo->findById(1)->getReferenceCost() === 49.90
);

// Verificar que la intervención histórica registrada MANTIENE intacto su coste de 28.50 €
$savedPartRecord = $replacedRepo->findById(1);
$assert(
    "El registro histórico de la intervención mantiene inmutable su unit_cost_snapshot en 28.50 €",
    $savedPartRecord->getUnitCostSnapshot() === 28.50
);
$assert(
    "El coste total congelado de la intervención mantiene inmutable 57.00 € (no se recalculó a 99.80 €)",
    $savedPartRecord->getTotalCostSnapshot() === 57.00
);

// =============================================================================
// 4. Validaciones de Resolución (Sí/No, Justificación técnica, Casos Límite)
// =============================================================================
echo "\n--- 4. Validaciones de Resolución y Casos Límite ---\n";

// 4.1 Rechazo si no se indica la declaración de sustitución (falta replaced_parts_declared)
$env = createTestEnvironment();
$missingDeclPayload = [
    'resolution_diagnosis' => 'Rotura de membrana interna en electroválvula de entrada',
    'resolution_action' => 'Sustitución completa de electroválvula y purga hidráulica',
];
try {
    $env['service']->resolveIncidentWithParts(101, 42, $missingDeclPayload);
    $assert("Rechaza resolución sin campo replaced_parts_declared", false);
} catch (InvalidArgumentException $e) {
    $assert(
        "Rechaza resolución sin declaración obligatoria Sí/No (PARTS_RECORD_REQUIRED)",
        str_contains($e->getMessage(), 'replaced_parts_declared obligatorio')
    );
}

// 4.2 Rechazo si se declara Sí (true) pero la lista de piezas está vacía
$env = createTestEnvironment();
$emptyDeclaredPayload = [
    'resolution_diagnosis' => 'Rotura de membrana interna en electroválvula de entrada',
    'resolution_action' => 'Sustitución completa de electroválvula y purga hidráulica',
    'replaced_parts_declared' => true,
    'replaced_parts' => [],
];
try {
    $env['service']->resolveIncidentWithParts(101, 42, $emptyDeclaredPayload);
    $assert("Rechaza declaración true con lista vacía", false);
} catch (InvalidArgumentException $e) {
    $assert(
        "Rechaza replaced_parts_declared = true con lista vacía (EMPTY_REPLACED_PARTS_LIST)",
        str_contains($e->getMessage(), 'no ha registrado ninguna pieza')
    );
}

// 4.3 Resolución exitosa declarando No (false) -> Cancela solicitudes pendientes
$env = createTestEnvironment();
$env['requestRepo']->createRequest(new SparePartRequest(
    id: null,
    incidentId: 101,
    sparePartId: 1,
    isOutOfCatalog: false,
    customPartDescription: null,
    quantity: 1,
    status: SparePartRequestStatus::PENDING,
    requestedByUserId: 42
));

$noPartsPayload = [
    'resolution_diagnosis' => 'Obstrucción mecánica en conducto por calcificación severa',
    'resolution_action' => 'Descalcificación manual in situ con ácido cítrico y prueba de caudal',
    'replaced_parts_declared' => false,
    'replaced_parts' => [],
];
$noPartsRes = $env['service']->resolveIncidentWithParts(101, 42, $noPartsPayload);

$assert(
    "Resolución sin piezas (declared=false) transiciona a RESOLVED con coste 0.00",
    $noPartsRes['status'] === IncidentStatus::RESOLVED->value &&
    $noPartsRes['replaced_parts_count'] === 0 &&
    $noPartsRes['total_parts_cost'] === 0.00
);
$cancelledRequests = $env['requestRepo']->findByIncidentId(101);
$assert(
    "Solicitud pendiente previa se cancela automáticamente si se resolvió sin repuestos",
    count($cancelledRequests) === 1 && $cancelledRequests[0]->getStatus() === SparePartRequestStatus::CANCELLED
);

// 4.4 Rechazo de diagnóstico o acción inferiores a 20 caracteres (Constitución Art. V.1)
$env = createTestEnvironment();
$shortJustifPayload = [
    'resolution_diagnosis' => 'Fuga de agua', // < 20 caracteres
    'resolution_action' => 'Sustitución de electroválvula',
    'replaced_parts_declared' => false,
];
try {
    $env['service']->resolveIncidentWithParts(101, 42, $shortJustifPayload);
    $assert("Rechaza diagnóstico < 20 caracteres", false);
} catch (InvalidResolutionException $e) {
    $assert(
        "Rechaza justificación insuficiente con InvalidResolutionException (Art. V.1)",
        count($e->getErrors()) > 0
    );
}

// 4.5 Resolución con pieza fuera de catálogo
$env = createTestEnvironment();
$outOfCatResolvePayload = [
    'resolution_diagnosis' => 'Adaptador de soporte de tolva roto por impacto físico',
    'resolution_action' => 'Instalación de brida metálica reforzada especial a medida',
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        [
            'is_out_of_catalog' => true,
            'custom_part_name' => 'Brida de sujeción metálica M4',
            'quantity' => 2,
            'old_part_destination' => 'DESGUACE',
            'notes' => 'Pieza artesanal fabricada in situ',
        ],
    ],
];
$outOfCatRes = $env['service']->resolveIncidentWithParts(101, 42, $outOfCatResolvePayload);

$assert(
    "Resolución con pieza fuera de catálogo se registra con unit_cost_snapshot = 0.00",
    $outOfCatRes['replaced_parts_count'] === 1 &&
    $outOfCatRes['replaced_parts'][0]['unit_cost_snapshot'] === 0.00 &&
    $outOfCatRes['replaced_parts'][0]['is_out_of_catalog'] === true
);

// =============================================================================
// 5. Sustitución de Piezas en Preventivos Periódicos (RF-REP-07)
// =============================================================================
echo "\n--- 5. Sustitución en Preventivos Periódicos (RF-REP-07) ---\n";

$env = createTestEnvironment();
$service = $env['service'];

$preventiveParts = [
    [
        'spare_part_id' => 1,
        'quantity' => 1,
        'old_part_destination' => 'DESGUACE',
        'notes' => 'Sustitución preventiva periódica anual',
    ],
];
$prevRes = $service->recordPreventiveReplacedParts(
    preventiveOrderId: 501,
    technicianId: 42,
    machineId: 10,
    locationId: 5,
    replacedParts: $preventiveParts
);

$assert(
    "Registro preventivo devuelve 1 pieza y coste correcto de snapshot",
    $prevRes['replaced_parts_count'] === 1 &&
    $prevRes['total_parts_cost'] === 28.50
);
$assert(
    "Pieza en preventivo registra intervention_type = PREVENTIVE y preventive_order_id = 501",
    $prevRes['replaced_parts'][0]['intervention_type'] === 'PREVENTIVE' &&
    $prevRes['replaced_parts'][0]['preventive_order_id'] === 501 &&
    $prevRes['replaced_parts'][0]['incident_id'] === null
);

// 5.2 Consultas de agregación en servicio
$assert(
    "getReplacedPartsByPreventiveOrder recupera las piezas del preventivo 501",
    count($service->getReplacedPartsByPreventiveOrder(501)) === 1
);
$assert(
    "getRequestsByIncident recupera solicitudes de la incidencia",
    is_array($service->getRequestsByIncident(101))
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n==========================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "==========================================================================\n";

exit($failures === 0 ? 0 : 1);
