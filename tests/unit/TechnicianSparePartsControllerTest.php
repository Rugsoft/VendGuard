<?php

declare(strict_types=1);

/**
 * TechnicianSparePartsControllerTest
 * 
 * Batería de pruebas unitarias para TechnicianSparePartsController y TechnicianController
 * con soporte integral de repuestos (Tarea T-SPARE-11).
 * 
 * Verifica que se cumplan rigurosamente los requisitos:
 * - RF-REP-01 / RNF-REP-02: Catálogo compatible en movilidad servido en < 250 ms.
 * - Caso Límite 4: Preservación de repuestos desactivados si fueron solicitados en una avería PENDING_PARTS.
 * - RF-REP-03 / RF-REP-04: Pausa técnica estructurada con validación de compatibilidad y excepción fuera de catálogo.
 * - RF-REP-05 / RF-REP-06: Resolución obligatoriamente justificada con declaración de piezas, destino DESGUACE/TALLER
 *   y congelación inmutable de snapshot de coste (Art. III).
 * - Dogma Vanilla (PHP 8.2+ OOP nativo, cero dependencias).
 * - Dualismo Lingüístico (inglés en código/arquitectura, español en mensajes y contratos).
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Controller\TechnicianSparePartsController;
use VendGuard\Presentation\Http\Request;

echo "==========================================================================\n";
echo " VendGuard: Test Unitario - TechnicianSparePartsControllerTest (T-SPARE-11)\n";
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
// Mocks en Memoria para Pruebas Unitarias de Controlador
// =============================================================================

class MockTechMachineRepository implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];

    public function findActiveByLocationId(int $l): array { return []; }
    public function findById(int $id, bool $w = true, bool $a = false): ?Machine { return $this->machines[$id] ?? null; }
    public function findByCode(string $c, bool $w = true, bool $a = false): ?Machine { return null; }
    public function create(array $d): Machine { throw new RuntimeException("Stub"); }
    public function update(int $id, array $d): bool { return true; }
    public function transfer(int $i, int $t, string $f, ?string $n = null): bool { return true; }
    public function restoreWithLocation(int $i, ?int $n = null, ?string $f = null): bool { return true; }
    public function findAll(array $f = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $m): bool { return false; }
    public function getActiveTicketOrWarranty(int $m): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

class MockTechSparePartRepository implements SparePartRepositoryInterface
{
    /** @var array<int, SparePart> */
    public array $parts = [];
    private int $nextId = 1;

    public function create(SparePart $s): SparePart
    {
        $id = $this->nextId++;
        $created = new SparePart(
            id: $id,
            partCode: $s->getPartCode(),
            name: $s->getName(),
            category: $s->getCategory(),
            manufacturer: $s->getManufacturer(),
            referenceCost: $s->getReferenceCost(),
            isActive: $s->isActive(),
            notes: $s->getNotes(),
            compatibleModels: $s->getCompatibleModels(),
            totalInstalledUnits: 0
        );
        $this->parts[$id] = $created;
        return $created;
    }

    public function update(SparePart $s): bool
    {
        $id = (int)$s->getId();
        if (!isset($this->parts[$id])) return false;
        $this->parts[$id] = $s;
        return true;
    }

    public function softDelete(int $id): bool { return $this->updateStatus($id, false); }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (!isset($this->parts[$id])) return false;
        $p = $this->parts[$id];
        $this->parts[$id] = new SparePart(
            id: $p->getId(),
            partCode: $p->getPartCode(),
            name: $p->getName(),
            category: $p->getCategory(),
            manufacturer: $p->getManufacturer(),
            referenceCost: $p->getReferenceCost(),
            isActive: $isActive,
            notes: $p->getNotes(),
            compatibleModels: $p->getCompatibleModels(),
            totalInstalledUnits: $p->getTotalInstalledUnits()
        );
        return true;
    }

    public function findById(int $id): ?SparePart { return $this->parts[$id] ?? null; }
    public function findByCode(string $c): ?SparePart { return null; }
    public function isCodeExists(string $c, ?int $e = null): bool { return false; }
    public function findAll(?string $s = null, ?string $c = null, ?string $m = null, ?bool $a = null): array { return []; }

    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(SparePart $p) => $p->isCompatibleWithModel($machineModel) && (!$onlyActive || $p->isActive())
        ));
    }

    public function findDistinctMachineModels(): array { return []; }
}

class MockTechIncidentRepository implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    /** @var array<int, Incident> */
    public array $incidents = [];

    public function create(Incident $i, ?int $u = null, ?string $n = null): Incident { return $i; }
    public function findById(int $id): ?Incident { return $this->incidents[$id] ?? null; }
    public function findByTicketCode(string $c): ?Incident { return null; }
    public function findActiveByMachineId(int $m): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $m): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $l, bool $a = false): array { return []; }
    public function findAll(array $f = []): array { return []; }
    public function findAssignedToTechnician(int $t, array $s = []): array { return []; }
    public function update(Incident $i): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $i, ?int $u, ?string $f, string $t, ?string $n = null): int { return 1; }
    public function getHistory(int $i): array { return []; }
    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $c): \VendGuard\Core\Domain\Model\IncidentComment { return $c; }
    public function getComments(int $i, bool $in = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $i): int { return 0; }
    public function markAsChronic(int $i): bool { return true; }
    public function assign(int $i, int $t, ?int $c = null, ?string $o = null, ?string $r = null): Incident { throw new RuntimeException("Stub"); }
    public function reopen(int $i, string $r): Incident { throw new RuntimeException("Stub"); }
    public function cancel(int $i, string $r, ?int $c = null): Incident { throw new RuntimeException("Stub"); }

    public function startIntervention(int $i, int $t): Incident
    {
        $inc = $this->incidents[$i];
        $updated = new Incident(
            id: $inc->getId(),
            ticketCode: $inc->getTicketCode(),
            machineId: $inc->getMachineId(),
            locationId: $inc->getLocationId(),
            category: $inc->getCategory(),
            description: $inc->getDescription(),
            urgency: $inc->getUrgency(),
            status: IncidentStatus::IN_PROGRESS,
            assignedTechnicianId: $inc->getAssignedTechnicianId(),
            startedAt: date('Y-m-d H:i:s'),
            machineModel: $inc->getMachineModel()
        );
        $this->incidents[$i] = $updated;
        return $updated;
    }

    public function pauseIntervention(int $i, int $t, string $r): Incident
    {
        $inc = $this->incidents[$i];
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
            startedAt: $inc->getStartedAt(),
            pendingPartsReason: $r,
            machineModel: $inc->getMachineModel()
        );
        $this->incidents[$i] = $updated;
        return $updated;
    }

    public function resolve(int $i, int $t, string $d, string $a): Incident
    {
        $inc = $this->incidents[$i];
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
            startedAt: $inc->getStartedAt(),
            pendingPartsReason: $inc->getPendingPartsReason(),
            resolutionDiagnosis: $d,
            resolutionAction: $a,
            resolvedAt: date('Y-m-d H:i:s'),
            machineModel: $inc->getMachineModel()
        );
        $this->incidents[$i] = $updated;
        return $updated;
    }

    public function autoCloseResolvedIncidents(int $h = 48): array { return []; }
}

class MockTechRequestRepository implements SparePartRequestRepositoryInterface
{
    /** @var array<int, SparePartRequest> */
    public array $requests = [];
    private int $nextId = 1;

    public function createRequest(SparePartRequest $r): SparePartRequest
    {
        $id = $this->nextId++;
        $created = new SparePartRequest(
            id: $id,
            incidentId: $r->getIncidentId(),
            sparePartId: $r->getSparePartId(),
            isOutOfCatalog: $r->isOutOfCatalog(),
            customPartDescription: $r->getCustomPartDescription(),
            quantity: $r->getQuantity(),
            status: $r->getStatus(),
            requestedByUserId: $r->getRequestedByUserId(),
            partCode: $r->getPartCode(),
            partName: $r->getPartName()
        );
        $this->requests[$id] = $created;
        return $created;
    }

    public function findById(int $id): ?SparePartRequest { return $this->requests[$id] ?? null; }

    public function findByIncidentId(int $incidentId): array
    {
        return array_values(array_filter(
            $this->requests,
            fn(SparePartRequest $r) => $r->getIncidentId() === $incidentId
        ));
    }

    public function markAttendedByIncident(int $incidentId): int
    {
        $count = 0;
        foreach ($this->requests as $id => $r) {
            if ($r->getIncidentId() === $incidentId && $r->getStatus() === SparePartRequestStatus::PENDING) {
                $this->requests[$id] = new SparePartRequest(
                    id: $r->getId(),
                    incidentId: $r->getIncidentId(),
                    sparePartId: $r->getSparePartId(),
                    isOutOfCatalog: $r->isOutOfCatalog(),
                    customPartDescription: $r->getCustomPartDescription(),
                    quantity: $r->getQuantity(),
                    status: SparePartRequestStatus::ATTENDED,
                    requestedByUserId: $r->getRequestedByUserId(),
                    partCode: $r->getPartCode(),
                    partName: $r->getPartName()
                );
                $count++;
            }
        }
        return $count;
    }

    public function markCancelledByIncident(int $incidentId): int
    {
        $count = 0;
        foreach ($this->requests as $id => $r) {
            if ($r->getIncidentId() === $incidentId && $r->getStatus() === SparePartRequestStatus::PENDING) {
                $this->requests[$id] = new SparePartRequest(
                    id: $r->getId(),
                    incidentId: $r->getIncidentId(),
                    sparePartId: $r->getSparePartId(),
                    isOutOfCatalog: $r->isOutOfCatalog(),
                    customPartDescription: $r->getCustomPartDescription(),
                    quantity: $r->getQuantity(),
                    status: SparePartRequestStatus::CANCELLED,
                    requestedByUserId: $r->getRequestedByUserId(),
                    partCode: $r->getPartCode(),
                    partName: $r->getPartName()
                );
                $count++;
            }
        }
        return $count;
    }

    public function findPendingOutOfCatalogReviews(): array { return []; }
}

class MockTechReplacedPartRepository implements IncidentReplacedPartRepositoryInterface
{
    /** @var array<int, IncidentReplacedPart> */
    public array $replacedParts = [];
    private int $nextId = 1;

    public function insertReplacedPart(IncidentReplacedPart $p): IncidentReplacedPart
    {
        $id = $this->nextId++;
        $created = new IncidentReplacedPart(
            id: $id,
            interventionType: $p->getInterventionType(),
            incidentId: $p->getIncidentId(),
            preventiveOrderId: $p->getPreventiveOrderId(),
            machineId: $p->getMachineId(),
            locationId: $p->getLocationId(),
            technicianId: $p->getTechnicianId(),
            sparePartId: $p->getSparePartId(),
            isOutOfCatalog: $p->isOutOfCatalog(),
            customPartName: $p->getCustomPartName(),
            quantity: $p->getQuantity(),
            unitCostSnapshot: $p->getUnitCostSnapshot(),
            oldPartDestination: $p->getOldPartDestination(),
            notes: $p->getNotes(),
            installedAt: $p->getInstalledAt(),
            partCode: $p->getPartCode(),
            partName: $p->getPartName()
        );
        $this->replacedParts[$id] = $created;
        return $created;
    }

    public function insertManyReplacedParts(array $parts): array
    {
        $result = [];
        foreach ($parts as $p) {
            $result[] = $this->insertReplacedPart($p);
        }
        return $result;
    }

    public function findById(int $id): ?IncidentReplacedPart { return $this->replacedParts[$id] ?? null; }

    public function findByIncidentId(int $incidentId): array
    {
        return array_values(array_filter(
            $this->replacedParts,
            fn(IncidentReplacedPart $p) => $p->getIncidentId() === $incidentId
        ));
    }

    public function findByPreventiveOrderId(int $preventiveOrderId): array { return []; }
    public function getCostSummaryByMachineModel(?int $periodDays = null): array { return []; }
    public function getCostSummaryByLocation(?int $periodDays = null): array { return []; }
    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array { return []; }
    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array { return []; }
    public function findAllForExport(?int $periodDays = null): array { return []; }
}

// =============================================================================
// Helper de Configuración del Entorno de Pruebas
// =============================================================================

function createTechTestEnvironment(): array
{
    $machineRepo      = new MockTechMachineRepository();
    $sparePartRepo    = new MockTechSparePartRepository();
    $incidentRepo     = new MockTechIncidentRepository();
    $requestRepo      = new MockTechRequestRepository();
    $replacedPartRepo = new MockTechReplacedPartRepository();

    // 1. Crear Máquinas
    $machine1 = new Machine(
        id: 10,
        locationId: 1,
        code: 'MAQ-MAD-001',
        model: 'Azkoyen Palma+',
        machineType: \VendGuard\Core\Domain\Model\MachineType::HOT_DRINKS,
        floorWing: 'Planta 1'
    );
    $machineRepo->machines[10] = $machine1;

    $machine2 = new Machine(
        id: 20,
        locationId: 1,
        code: 'MAQ-MAD-002',
        model: 'Fas Fast 900',
        machineType: \VendGuard\Core\Domain\Model\MachineType::SNACKS,
        floorWing: 'Planta 2'
    );
    $machineRepo->machines[20] = $machine2;

    // 2. Crear Repuestos en catálogo
    $part1 = $sparePartRepo->create(new SparePart(
        id: null,
        partCode: 'VALV-ULKA-01',
        name: 'Electroválvula 24V Ulka',
        category: SparePartCategory::HYDRAULIC,
        manufacturer: 'Ulka',
        referenceCost: 28.50,
        isActive: true,
        notes: null,
        compatibleModels: ['Azkoyen Palma+']
    ));

    $part2 = $sparePartRepo->create(new SparePart(
        id: null,
        partCode: 'MOT-ESP-05',
        name: 'Motor Extractor 24V',
        category: SparePartCategory::MECHANICAL,
        manufacturer: 'Azkoyen',
        referenceCost: 34.00,
        isActive: true,
        notes: null,
        compatibleModels: ['Azkoyen Palma+']
    ));

    $partInactive = $sparePartRepo->create(new SparePart(
        id: null,
        partCode: 'SOND-OLD-99',
        name: 'Sonda Térmica Antigua Desactivada',
        category: SparePartCategory::THERMAL,
        manufacturer: 'Carel',
        referenceCost: 12.00,
        isActive: false, // Desactivada
        notes: null,
        compatibleModels: ['Azkoyen Palma+']
    ));

    $partFas = $sparePartRepo->create(new SparePart(
        id: null,
        partCode: 'COMP-FAS-01',
        name: 'Compresor Frío Fas',
        category: SparePartCategory::THERMAL,
        manufacturer: 'Embraco',
        referenceCost: 120.00,
        isActive: true,
        notes: null,
        compatibleModels: ['Fas Fast 900'] // Incompatible con Azkoyen Palma+
    ));

    // 3. Crear Incidencias
    $incTech1 = new Incident(
        id: 101,
        ticketCode: 'TICK-2026-00101',
        machineId: 10,
        locationId: 1,
        category: IncidentCategory::OTHER,
        description: 'Fuga de agua en válvula',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::IN_PROGRESS,
        assignedTechnicianId: 42,
        startedAt: date('Y-m-d H:i:s'),
        machineModel: 'Azkoyen Palma+'
    );
    $incidentRepo->incidents[101] = $incTech1;

    $traceabilityService = new SparePartTraceabilityService(
        requestRepo: $requestRepo,
        replacedPartRepo: $replacedPartRepo,
        sparePartRepo: $sparePartRepo,
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo
    );

    $techSparePartsController = new TechnicianSparePartsController(
        machineRepo: $machineRepo,
        sparePartRepo: $sparePartRepo,
        incidentRepo: $incidentRepo,
        requestRepo: $requestRepo
    );

    $technicianController = new TechnicianController(
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo,
        locationRepo: null,
        userRepo: null,
        traceabilityService: $traceabilityService
    );

    $technicianUser = new User(
        id: 42,
        name: 'Carlos Técnico',
        email: 'carlos@vendguard.com',
        passwordHash: 'dummy',
        role: UserRole::TECHNICIAN
    );

    return [
        'machineRepo'               => $machineRepo,
        'sparePartRepo'             => $sparePartRepo,
        'incidentRepo'              => $incidentRepo,
        'requestRepo'               => $requestRepo,
        'replacedPartRepo'          => $replacedPartRepo,
        'traceabilityService'       => $traceabilityService,
        'techSparePartsController'  => $techSparePartsController,
        'technicianController'      => $technicianController,
        'technicianUser'            => $technicianUser,
        'part1'                     => $part1,
        'part2'                     => $part2,
        'partInactive'              => $partInactive,
        'partFas'                   => $partFas,
    ];
}

// =============================================================================
// 1. Catálogo Móvil de Repuestos (TechnicianSparePartsController::getCatalog)
// =============================================================================
echo "--- 1. Catálogo Móvil de Repuestos (TechnicianSparePartsController::getCatalog) ---\n";

$env = createTechTestEnvironment();
$ctrl = $env['techSparePartsController'];

// 1.1 Sin machine_id => 400 INVALID_MACHINE_ID
$reqNoMach = new Request('GET', '/api/technician/spare-parts/catalog');
$resNoMach = $ctrl->getCatalog($reqNoMach);
$assert(
    "1.1 Sin machine_id devuelve 400 INVALID_MACHINE_ID",
    $resNoMach->getStatusCode() === 400 && ($resNoMach->getDecodedBody()['error']['code'] ?? '') === 'INVALID_MACHINE_ID'
);

// 1.2 machine_id inexistente => 404 MACHINE_NOT_FOUND
$reqMach404 = new Request('GET', '/api/technician/spare-parts/catalog', ['machine_id' => '999']);
$resMach404 = $ctrl->getCatalog($reqMach404);
$assert(
    "1.2 Máquina inexistente devuelve 404 MACHINE_NOT_FOUND",
    $resMach404->getStatusCode() === 404 && ($resMach404->getDecodedBody()['error']['code'] ?? '') === 'MACHINE_NOT_FOUND'
);

// 1.3 machine_id válido devuelve 200 OK con repuestos activos y compatibles
$startTimer = microtime(true);
$reqMachValid = new Request('GET', '/api/technician/spare-parts/catalog', ['machine_id' => '10']);
$resMachValid = $ctrl->getCatalog($reqMachValid);
$elapsedMs = (microtime(true) - $startTimer) * 1000.0;
$bodyValid = $resMachValid->getDecodedBody()['data'] ?? [];

$assert(
    "1.3 Máquina válida devuelve 200 OK con datos de máquina y repuestos compatibles",
    $resMachValid->getStatusCode() === 200 &&
    ($bodyValid['machine']['model'] ?? '') === 'Azkoyen Palma+' &&
    count($bodyValid['compatible_parts'] ?? []) === 2
);

// 1.4 RNF-REP-02: Tiempo de respuesta inferior a 250 ms
$assert(
    "1.4 RNF-REP-02: Catálogo móvil servido en < 250 ms (real: " . round($elapsedMs, 2) . " ms)",
    $elapsedMs < 250.0
);

// 1.5 Caso Límite 4: Si se incluye incident_id en PENDING_PARTS, incluye pieza solicitada aunque esté inactiva
// Simulamos solicitud previa de la pieza inactiva en la incidencia 101
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Avería con pieza descontinuada',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::PENDING_PARTS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$env['requestRepo']->createRequest(new SparePartRequest(
    id: null,
    incidentId: 101,
    sparePartId: $env['partInactive']->getId(), // Pieza inactiva
    isOutOfCatalog: false,
    customPartDescription: null,
    quantity: 1,
    status: SparePartRequestStatus::PENDING,
    requestedByUserId: 42
));

$reqWithIncident = new Request('GET', '/api/technician/spare-parts/catalog', [
    'machine_id'  => '10',
    'incident_id' => '101',
]);
$resWithIncident = $ctrl->getCatalog($reqWithIncident);
$partsWithInactive = $resWithIncident->getDecodedBody()['data']['compatible_parts'] ?? [];
$partCodes = array_column($partsWithInactive, 'part_code');

$assert(
    "1.5 Caso Límite 4: Catálogo incluye pieza inactiva solicitada previamente en avería PENDING_PARTS",
    $resWithIncident->getStatusCode() === 200 &&
    count($partsWithInactive) === 3 &&
    in_array('SOND-OLD-99', $partCodes, true)
);

// =============================================================================
// 2. Pausa Técnica con Repuestos (TechnicianController::pauseIntervention)
// =============================================================================
echo "\n--- 2. Pausa Técnica Estructurada (TechnicianController::pauseIntervention) ---\n";

$techCtrl = $env['technicianController'];

// 2.1 Pausa estructurada válida con repuesto compatible
$pausePayloadValid = [
    'requested_parts' => [
        ['spare_part_id' => $env['part1']->getId(), 'quantity' => 1],
    ],
    'is_out_of_catalog' => false,
];
// Reset incident a IN_PROGRESS
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);

$reqPauseValid = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pausePayloadValid))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseValid = $techCtrl->pauseIntervention($reqPauseValid);
$dataPauseValid = $resPauseValid->getDecodedBody()['data'] ?? [];

$assert(
    "2.1 pauseIntervention estructurada transiciona a PENDING_PARTS y genera spare_part_requests",
    $resPauseValid->getStatusCode() === 200 &&
    ($dataPauseValid['status'] ?? '') === 'PENDING_PARTS' &&
    count($dataPauseValid['requests'] ?? []) === 1 &&
    ($dataPauseValid['requests'][0]['part_code'] ?? '') === 'VALV-ULKA-01'
);

// 2.2 Pausa con repuesto incompatible rechaza con 422 INCOMPATIBLE_SPARE_PART
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$pauseIncompatPayload = [
    'requested_parts' => [
        ['spare_part_id' => $env['partFas']->getId(), 'quantity' => 1], // Incompatible con Azkoyen Palma+
    ],
    'is_out_of_catalog' => false,
];
$reqPauseIncompat = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pauseIncompatPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseIncompat = $techCtrl->pauseIntervention($reqPauseIncompat);
$errIncompat = $resPauseIncompat->getDecodedBody()['error'] ?? [];

$assert(
    "2.2 pauseIntervention con repuesto incompatible devuelve 422 INCOMPATIBLE_SPARE_PART",
    $resPauseIncompat->getStatusCode() === 422 &&
    ($errIncompat['code'] ?? '') === 'INCOMPATIBLE_SPARE_PART'
);

// 2.3 Pausa sin piezas seleccionadas rechaza con 422 MISSING_PARTS_REQUEST
$pauseEmptyPayload = [
    'requested_parts' => [],
    'is_out_of_catalog' => false,
];
$reqPauseEmpty = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pauseEmptyPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseEmpty = $techCtrl->pauseIntervention($reqPauseEmpty);
$errEmpty = $resPauseEmpty->getDecodedBody()['error'] ?? [];

$assert(
    "2.3 pauseIntervention con array vacío devuelve 422 MISSING_PARTS_REQUEST",
    $resPauseEmpty->getStatusCode() === 422 &&
    ($errEmpty['code'] ?? '') === 'MISSING_PARTS_REQUEST'
);

// 2.4 Pausa fuera de catálogo válida (RF-REP-04: justificación >= 20 caracteres)
$pauseOutOfCatPayload = [
    'is_out_of_catalog'       => true,
    'custom_part_description' => 'Sensor de caída infrarrojo especial de tercera generación',
    'quantity'                => 1,
];
$reqPauseOut = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pauseOutOfCatPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseOut = $techCtrl->pauseIntervention($reqPauseOut);
$dataPauseOut = $resPauseOut->getDecodedBody()['data'] ?? [];

$assert(
    "2.4 pauseIntervention fuera de catálogo con justificación >= 20 chars transiciona a PENDING_PARTS",
    $resPauseOut->getStatusCode() === 200 &&
    ($dataPauseOut['status'] ?? '') === 'PENDING_PARTS' &&
    count($dataPauseOut['requests'] ?? []) === 1 &&
    ($dataPauseOut['requests'][0]['is_out_of_catalog'] ?? false) === true
);

// 2.5 Pausa fuera de catálogo con justificación < 20 chars rechaza con 422
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$pauseOutShortPayload = [
    'is_out_of_catalog'       => true,
    'custom_part_description' => 'Sensor roto', // Solo 11 chars
];
$reqPauseOutShort = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pauseOutShortPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseOutShort = $techCtrl->pauseIntervention($reqPauseOutShort);
$errOutShort = $resPauseOutShort->getDecodedBody()['error'] ?? [];

$assert(
    "2.5 pauseIntervention fuera de catálogo con < 20 chars devuelve 422 INVALID_OUT_OF_CATALOG_JUSTIFICATION",
    $resPauseOutShort->getStatusCode() === 422 &&
    ($errOutShort['code'] ?? '') === 'INVALID_OUT_OF_CATALOG_JUSTIFICATION'
);

// 2.6 Pausa con cantidad inválida (> 50) rechaza con 422
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$pauseBadQtyPayload = [
    'requested_parts' => [
        ['spare_part_id' => $env['part1']->getId(), 'quantity' => 99], // Límite 50
    ],
    'is_out_of_catalog' => false,
];
$reqPauseBadQty = (new Request('PATCH', '/api/technician/incidents/101/pause', [], $pauseBadQtyPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseBadQty = $techCtrl->pauseIntervention($reqPauseBadQty);
$errBadQty = $resPauseBadQty->getDecodedBody()['error'] ?? [];

$assert(
    "2.6 pauseIntervention con cantidad > 50 devuelve 422 INVALID_PART_QUANTITY",
    $resPauseBadQty->getStatusCode() === 422 &&
    ($errBadQty['code'] ?? '') === 'INVALID_PART_QUANTITY'
);

// 2.7 Pausa legacy con texto libre conserva retrocompatibilidad
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$reqPauseLegacy = (new Request('PATCH', '/api/technician/incidents/101/pause', [], [
    'pending_parts_reason' => 'Se requiere electroválvula Ulka de 24V pedida a central',
]))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resPauseLegacy = $techCtrl->pauseIntervention($reqPauseLegacy);

$assert(
    "2.7 pauseIntervention legacy con pending_parts_reason conserva retrocompatibilidad (200 OK)",
    $resPauseLegacy->getStatusCode() === 200 &&
    ($resPauseLegacy->getDecodedBody()['data']['status'] ?? '') === 'PENDING_PARTS'
);

// =============================================================================
// 3. Resolución con Registro de Repuestos y Snapshot (TechnicianController::resolveIncident)
// =============================================================================
echo "\n--- 3. Resolución con Repuestos y Snapshot (TechnicianController::resolveIncident) ---\n";

// 3.1 Resolución válida con sustitución de componentes y congelación de coste
$env['incidentRepo']->incidents[101] = new Incident(
    id: 101,
    ticketCode: 'TICK-2026-00101',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Fuga de agua',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);

$validResolvePayload = [
    'resolution_diagnosis'     => 'Fisura longitudinal en la cámara de presión de la electroválvula',
    'resolution_action'        => 'Sustitución completa de electroválvula por repuesto nuevo original',
    'replaced_parts_declared'  => true,
    'replaced_parts'           => [
        [
            'spare_part_id'        => $env['part1']->getId(),
            'is_out_of_catalog'    => false,
            'quantity'             => 1,
            'old_part_destination' => 'TALLER',
            'notes'                => 'Bobina recuperable',
        ],
    ],
];
$reqResolveValid = (new Request('POST', '/api/technician/incidents/101/resolve', [], $validResolvePayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resResolveValid = $techCtrl->resolveIncident($reqResolveValid);
$dataResolveValid = $resResolveValid->getDecodedBody()['data'] ?? [];

$assert(
    "3.1 resolveIncident con repuesto devuelve 200 OK, transiciona a RESOLVED y congela total_parts_cost",
    $resResolveValid->getStatusCode() === 200 &&
    ($dataResolveValid['status'] ?? '') === 'RESOLVED' &&
    ($dataResolveValid['replaced_parts_count'] ?? 0) === 1 &&
    (float)($dataResolveValid['total_parts_cost'] ?? 0.0) === 28.50
);

// 3.2 Inmutabilidad del Snapshot: Modificar reference_cost en catálogo no debe alterar el registro histórico
$env['sparePartRepo']->update(new SparePart(
    id: $env['part1']->getId(),
    partCode: $env['part1']->getPartCode(),
    name: $env['part1']->getName(),
    category: $env['part1']->getCategory(),
    manufacturer: $env['part1']->getManufacturer(),
    referenceCost: 55.00, // Inflación posterior de 28.50 a 55.00
    isActive: true,
    notes: null,
    compatibleModels: $env['part1']->getCompatibleModels(),
    totalInstalledUnits: 1
));
$savedReplaced = $env['replacedPartRepo']->findByIncidentId(101);
$assert(
    "3.2 Inviolabilidad Art. III: unit_cost_snapshot permanece congelado en 28.50 tras subida de precio",
    count($savedReplaced) === 1 &&
    $savedReplaced[0]->getUnitCostSnapshot() === 28.50 &&
    $savedReplaced[0]->getOldPartDestination() === OldPartDestination::TALLER
);

// 3.3 Resolución sin piezas (replaced_parts_declared = false)
$env['incidentRepo']->incidents[102] = new Incident(
    id: 102,
    ticketCode: 'TICK-2026-00102',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Ajuste mecánico sin cambio de piezas',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$noPartsPayload = [
    'resolution_diagnosis'     => 'Desajuste de abrazadera del conducto de teflón sin rotura',
    'resolution_action'        => 'Reapriete de abrazadera y prueba de estanqueidad satisfactoria',
    'replaced_parts_declared'  => false,
    'replaced_parts'           => [],
];
$reqNoParts = (new Request('POST', '/api/technician/incidents/102/resolve', [], $noPartsPayload))
    ->setRouteParams(['id' => '102'])
    ->setAttribute('user_id', 42);
$resNoParts = $techCtrl->resolveIncident($reqNoParts);
$dataNoParts = $resNoParts->getDecodedBody()['data'] ?? [];

$assert(
    "3.3 resolveIncident sin piezas (declared = false) resuelve con 0 repuestos y 0.00 coste",
    $resNoParts->getStatusCode() === 200 &&
    ($dataNoParts['status'] ?? '') === 'RESOLVED' &&
    ($dataNoParts['replaced_parts_count'] ?? 0) === 0 &&
    (float)($dataNoParts['total_parts_cost'] ?? 0.0) === 0.00
);

// 3.4 declared = true pero lista de piezas vacía => 422 EMPTY_REPLACED_PARTS_LIST
$env['incidentRepo']->incidents[103] = new Incident(
    id: 103,
    ticketCode: 'TICK-2026-00103',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Inconsistencia en declaración',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$inconsistentPayload = [
    'resolution_diagnosis'     => 'Fallo en sensor térmico detectado con multímetro digital',
    'resolution_action'        => 'Sustitución de sonda por componente nuevo en almacén local',
    'replaced_parts_declared'  => true,
    'replaced_parts'           => [], // Vacío a pesar de ser true
];
$reqInconsistent = (new Request('POST', '/api/technician/incidents/103/resolve', [], $inconsistentPayload))
    ->setRouteParams(['id' => '103'])
    ->setAttribute('user_id', 42);
$resInconsistent = $techCtrl->resolveIncident($reqInconsistent);
$errInconsistent = $resInconsistent->getDecodedBody()['error'] ?? [];

$assert(
    "3.4 declared = true con lista vacía devuelve 422 EMPTY_REPLACED_PARTS_LIST",
    $resInconsistent->getStatusCode() === 422 &&
    ($errInconsistent['code'] ?? '') === 'EMPTY_REPLACED_PARTS_LIST'
);

// 3.5 Destino inválido de componente retirado => 422 INVALID_PART_DESTINATION
$badDestPayload = [
    'resolution_diagnosis'     => 'Fallo en sensor térmico detectado con multímetro digital',
    'resolution_action'        => 'Sustitución de sonda por componente nuevo en almacén local',
    'replaced_parts_declared'  => true,
    'replaced_parts'           => [
        [
            'spare_part_id'        => $env['part1']->getId(),
            'is_out_of_catalog'    => false,
            'quantity'             => 1,
            'old_part_destination' => 'FURGONETA', // Inválido (solo DESGUACE o TALLER)
        ],
    ],
];
$reqBadDest = (new Request('POST', '/api/technician/incidents/103/resolve', [], $badDestPayload))
    ->setRouteParams(['id' => '103'])
    ->setAttribute('user_id', 42);
$resBadDest = $techCtrl->resolveIncident($reqBadDest);
$errBadDest = $resBadDest->getDecodedBody()['error'] ?? [];

$assert(
    "3.5 Destino no contemplado devuelve 422 INVALID_PART_DESTINATION",
    $resBadDest->getStatusCode() === 422 &&
    ($errBadDest['code'] ?? '') === 'INVALID_PART_DESTINATION'
);

// 3.6 Resolución con pieza fuera de catálogo congela unit_cost_snapshot en 0.00
$env['incidentRepo']->incidents[104] = new Incident(
    id: 104,
    ticketCode: 'TICK-2026-00104',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Sensor especial fuera de catálogo',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$outOfCatResolvePayload = [
    'resolution_diagnosis'     => 'Fallo en fotocélula de caída especial de fabricante externo',
    'resolution_action'        => 'Adaptación e instalación de sensor óptico provisional calibrado',
    'replaced_parts_declared'  => true,
    'replaced_parts'           => [
        [
            'spare_part_id'        => null,
            'is_out_of_catalog'    => true,
            'custom_part_name'     => 'Sensor Óptico Especial Canal 4',
            'quantity'             => 1,
            'old_part_destination' => 'DESGUACE',
            'notes'                => 'Sin coste de referencia en catálogo',
        ],
    ],
];
$reqOutOfCat = (new Request('POST', '/api/technician/incidents/104/resolve', [], $outOfCatResolvePayload))
    ->setRouteParams(['id' => '104'])
    ->setAttribute('user_id', 42);
$resOutOfCat = $techCtrl->resolveIncident($reqOutOfCat);
$dataOutOfCat = $resOutOfCat->getDecodedBody()['data'] ?? [];

$assert(
    "3.6 resolveIncident con pieza fuera de catálogo resuelve con total_parts_cost 0.00",
    $resOutOfCat->getStatusCode() === 200 &&
    ($dataOutOfCat['replaced_parts_count'] ?? 0) === 1 &&
    (float)($dataOutOfCat['total_parts_cost'] ?? 0.0) === 0.00
);

// 3.7 Resolución legacy sin declaración de repuestos conserva retrocompatibilidad (T-29)
$env['incidentRepo']->incidents[105] = new Incident(
    id: 105,
    ticketCode: 'TICK-2026-00105',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::OTHER,
    description: 'Avería de software resuelta sin piezas',
    urgency: UrgencyLevel::LOW,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 42,
    machineModel: 'Azkoyen Palma+'
);
$legacyResolvePayload = [
    'resolution_diagnosis' => 'Bloqueo temporal de microcontrolador por microcorte de tensión',
    'resolution_action'    => 'Reinicio en frío de placa base y verificación de parámetros de red',
];
$reqLegacyResolve = (new Request('POST', '/api/technician/incidents/105/resolve', [], $legacyResolvePayload))
    ->setRouteParams(['id' => '105'])
    ->setAttribute('user_id', 42);
$resLegacyResolve = $techCtrl->resolveIncident($reqLegacyResolve);
$dataLegacyResolve = $resLegacyResolve->getDecodedBody()['data'] ?? [];

$assert(
    "3.7 resolveIncident legacy sin declaración de piezas resuelve con 200 OK (retrocompatibilidad T-29)",
    $resLegacyResolve->getStatusCode() === 200 &&
    ($dataLegacyResolve['status'] ?? '') === 'RESOLVED' &&
    !empty($dataLegacyResolve['resolved_at'])
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
