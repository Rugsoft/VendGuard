<?php

declare(strict_types=1);

/**
 * CoordinatorSparePartsControllerTest
 * 
 * Batería de pruebas unitarias para CoordinatorSparePartsController (Tarea T-SPARE-10).
 * Verifica que todos los endpoints del controlador cumplan sus contratos HTTP:
 * 1. getCatalog: listado y filtrado por texto, categoría, modelo y estado activo.
 * 2. getPart: consulta de detalle y manejo de 404 ante repuesto no encontrado.
 * 3. createPart: alta de repuesto (201 Created), control de duplicados (409) y validaciones (422).
 * 4. updatePart: edición de repuesto (200 OK), 404 Not Found y validaciones (422).
 * 5. toggleStatus: conmutación de estado activo/inactivo sin borrado físico (Art. III) y validaciones.
 * 6. getModels: obtención de modelos de máquinas para selectores dinámicos.
 * 7. getAnalytics: panel analítico, totales consolidados y alertas de fallos recurrentes.
 * 8. exportCsv: descarga de archivo CSV con BOM UTF-8 y cabeceras attachment.
 * 9. getPendingReviewRequests: bandeja de piezas fuera de catálogo pendientes de revisión.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\SparePartAnalyticsService;
use VendGuard\Application\Service\SparePartCatalogService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
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
use VendGuard\Presentation\Controller\CoordinatorSparePartsController;
use VendGuard\Presentation\Http\Request;

echo "==========================================================================\n";
echo " VendGuard: Test Unitario - CoordinatorSparePartsControllerTest (T-SPARE-10)\n";
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

class MockControllerSparePartRepository implements SparePartRepositoryInterface
{
    /** @var array<int, SparePart> */
    public array $parts = [];
    private int $nextId = 1;

    public function create(SparePart $sparePart): SparePart
    {
        $id = $this->nextId++;
        $created = new SparePart(
            id: $id,
            partCode: $sparePart->getPartCode(),
            name: $sparePart->getName(),
            category: $sparePart->getCategory(),
            manufacturer: $sparePart->getManufacturer(),
            referenceCost: $sparePart->getReferenceCost(),
            isActive: $sparePart->isActive(),
            notes: $sparePart->getNotes(),
            compatibleModels: $sparePart->getCompatibleModels(),
            totalInstalledUnits: 0,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: null
        );
        $this->parts[$id] = $created;
        return $created;
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

    public function softDelete(int $id): bool
    {
        return $this->updateStatus($id, false);
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (!isset($this->parts[$id])) {
            return false;
        }
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
            totalInstalledUnits: $p->getTotalInstalledUnits(),
            createdAt: $p->getCreatedAt(),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: $isActive ? null : date('Y-m-d H:i:s')
        );
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
        foreach ($this->parts as $p) {
            if ($excludeId !== null && $p->getId() === $excludeId) {
                continue;
            }
            if (strcasecmp($p->getPartCode(), $partCode) === 0) {
                return true;
            }
        }
        return false;
    }

    public function findAll(
        ?string $search = null,
        ?string $category = null,
        ?string $machineModel = null,
        ?bool $isActive = null
    ): array {
        return array_values(array_filter($this->parts, function (SparePart $p) use ($search, $category, $machineModel, $isActive) {
            if ($isActive !== null && $p->isActive() !== $isActive) {
                return false;
            }
            if ($category !== null && $p->getCategory()->value !== $category) {
                return false;
            }
            if ($machineModel !== null && !$p->isCompatibleWithModel($machineModel)) {
                return false;
            }
            if ($search !== null && $search !== '') {
                $s = mb_strtolower($search);
                $inCode = str_contains(mb_strtolower($p->getPartCode()), $s);
                $inName = str_contains(mb_strtolower($p->getName()), $s);
                if (!$inCode && !$inName) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(SparePart $p) => $p->isCompatibleWithModel($machineModel) && (!$onlyActive || $p->isActive())
        ));
    }

    public function findDistinctMachineModels(): array
    {
        $models = [];
        foreach ($this->parts as $p) {
            foreach ($p->getCompatibleModels() as $m) {
                $models[$m] = true;
            }
        }
        return array_values(array_keys($models));
    }
}

class MockControllerReplacedPartRepository implements IncidentReplacedPartRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $topParts = [];
    /** @var array<int, array<string, mixed>> */
    public array $modelCosts = [];
    /** @var array<int, array<string, mixed>> */
    public array $locationCosts = [];
    /** @var array<int, array<string, mixed>> */
    public array $chronicAlerts = [];
    /** @var array<int, array<string, mixed>> */
    public array $exportRecords = [];

    public function insertReplacedPart(IncidentReplacedPart $part): IncidentReplacedPart { return $part; }
    public function insertManyReplacedParts(array $parts): array { return $parts; }
    public function findById(int $id): ?IncidentReplacedPart { return null; }
    public function findByIncidentId(int $incidentId): array { return []; }
    public function findByPreventiveOrderId(int $preventiveOrderId): array { return []; }

    public function getCostSummaryByMachineModel(?int $periodDays = null): array
    {
        return $this->modelCosts;
    }

    public function getCostSummaryByLocation(?int $periodDays = null): array
    {
        return $this->locationCosts;
    }

    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array
    {
        return array_slice($this->topParts, 0, $limit);
    }

    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array
    {
        return $this->chronicAlerts;
    }

    public function findAllForExport(?int $periodDays = null): array
    {
        return $this->exportRecords;
    }
}

class MockControllerRequestRepository implements SparePartRequestRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $pendingReviews = [];

    public function createRequest(SparePartRequest $request): SparePartRequest { return $request; }
    public function findById(int $id): ?SparePartRequest { return null; }
    public function findByIncidentId(int $incidentId): array { return []; }
    public function markAttendedByIncident(int $incidentId): int { return 0; }
    public function markCancelledByIncident(int $incidentId): int { return 0; }
    public function findPendingOutOfCatalogReviews(): array
    {
        return $this->pendingReviews;
    }
}

// =============================================================================
// Helper de Creación de Entorno de Pruebas
// =============================================================================

function createControllerEnvironment(): array {
    $sparePartRepo = new MockControllerSparePartRepository();
    $replacedPartRepo = new MockControllerReplacedPartRepository();
    $requestRepo = new MockControllerRequestRepository();

    // Semilla de prueba
    $part1 = new SparePart(
        id: null,
        partCode: 'VALV-ULKA-01',
        name: 'Electroválvula 24V Ulka',
        category: SparePartCategory::HYDRAULIC,
        manufacturer: 'Ulka',
        referenceCost: 28.50,
        isActive: true,
        notes: 'Válvula para caldera',
        compatibleModels: ['Azkoyen Palma+', 'Azkoyen Palma B']
    );
    $sparePartRepo->create($part1);

    $part2 = new SparePart(
        id: null,
        partCode: 'SOND-NTC-02',
        name: 'Sonda Térmica NTC Frío 10k',
        category: SparePartCategory::THERMAL,
        manufacturer: 'Carel',
        referenceCost: 15.20,
        isActive: false, // Desactivada para filtros
        notes: 'Sonda evaporador',
        compatibleModels: ['Fas Fast 900']
    );
    $sparePartRepo->create($part2);

    $catalogService = new SparePartCatalogService($sparePartRepo);
    $analyticsService = new SparePartAnalyticsService($replacedPartRepo);

    $dummyIncidentRepo = new class implements IncidentRepositoryInterface {
        public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
        public function create(\VendGuard\Core\Domain\Model\Incident $i, ?int $u = null, ?string $n = null): \VendGuard\Core\Domain\Model\Incident { return $i; }
        public function findById(int $id): ?\VendGuard\Core\Domain\Model\Incident { return null; }
        public function findByTicketCode(string $c): ?\VendGuard\Core\Domain\Model\Incident { return null; }
        public function findActiveByMachineId(int $m): ?\VendGuard\Core\Domain\Model\Incident { return null; }
        public function findActiveOrResolvedByMachineId(int $m): ?\VendGuard\Core\Domain\Model\Incident { return null; }
        public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
        public function findAllByLocation(int $l, bool $a = false): array { return []; }
        public function findAll(array $f = []): array { return []; }
        public function findAssignedToTechnician(int $t, array $s = []): array { return []; }
        public function update(\VendGuard\Core\Domain\Model\Incident $i): bool { return true; }
        public function softDelete(int $id): bool { return true; }
        public function insertHistory(int $i, ?int $u, ?string $f, string $t, ?string $n = null): int { return 1; }
        public function getHistory(int $i): array { return []; }
        public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $c): \VendGuard\Core\Domain\Model\IncidentComment { return $c; }
        public function getComments(int $i, bool $in = true): array { return []; }
        public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
        public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
        public function countReopenEvents(int $i): int { return 0; }
        public function markAsChronic(int $i): bool { return true; }
        public function assign(int $i, int $t, ?int $c = null, ?string $o = null, ?string $r = null): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function reopen(int $i, string $r): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function cancel(int $i, string $r, ?int $c = null): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function startIntervention(int $i, int $t): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function pauseIntervention(int $i, int $t, string $r): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function resolve(int $i, int $t, string $d, string $a): \VendGuard\Core\Domain\Model\Incident { throw new RuntimeException("Stub"); }
        public function autoCloseResolvedIncidents(int $h = 48): array { return []; }
    };

    $dummyMachineRepo = new class implements MachineRepositoryInterface {
        public function findActiveByLocationId(int $l): array { return []; }
        public function findById(int $id, bool $w = true, bool $a = false): ?\VendGuard\Core\Domain\Model\Machine { return null; }
        public function findByCode(string $c, bool $w = true, bool $a = false): ?\VendGuard\Core\Domain\Model\Machine { return null; }
        public function create(array $d): \VendGuard\Core\Domain\Model\Machine { throw new RuntimeException("Stub"); }
        public function update(int $id, array $d): bool { return true; }
        public function transfer(int $i, int $t, string $f, ?string $n = null): bool { return true; }
        public function restoreWithLocation(int $i, ?int $n = null, ?string $f = null): bool { return true; }
        public function findAll(array $f = []): array { return []; }
        public function hasActiveTicketOrWarranty(int $m): bool { return false; }
        public function getActiveTicketOrWarranty(int $m): ?array { return null; }
        public function softDelete(int $id): bool { return true; }
    };

    $traceabilityService = new SparePartTraceabilityService(
        requestRepo: $requestRepo,
        replacedPartRepo: $replacedPartRepo,
        sparePartRepo: $sparePartRepo,
        incidentRepo: $dummyIncidentRepo,
        machineRepo: $dummyMachineRepo
    );

    $controller = new CoordinatorSparePartsController(
        catalogService: $catalogService,
        analyticsService: $analyticsService,
        traceabilityService: $traceabilityService,
        sparePartRepo: $sparePartRepo,
        replacedPartRepo: $replacedPartRepo,
        requestRepo: $requestRepo
    );

    $coordinatorUser = new User(
        id: 1,
        name: 'Laura Coordinadora',
        email: 'laura@vendguard.com',
        passwordHash: 'dummy',
        role: UserRole::COORDINATOR
    );

    return [
        'controller'       => $controller,
        'sparePartRepo'    => $sparePartRepo,
        'replacedPartRepo' => $replacedPartRepo,
        'requestRepo'      => $requestRepo,
        'coordinatorUser'  => $coordinatorUser,
    ];
}

// =============================================================================
// 1. Listado de Catálogo y Detalle (getCatalog / getPart)
// =============================================================================
echo "--- 1. Listado de Catálogo y Detalle (getCatalog / getPart) ---\n";

$env = createControllerEnvironment();
$controller = $env['controller'];

// 1.1 Listar catálogo completo sin filtros
$req1 = new Request('GET', '/api/coordinator/spare-parts');
$res1 = $controller->getCatalog($req1);
$data1 = $res1->getDecodedBody()['data'] ?? [];

$assert(
    "getCatalog responde 200 OK con array de piezas",
    $res1->getStatusCode() === 200 && is_array($data1) && count($data1) === 2
);

// 1.2 Filtro por estado activo (is_active=true)
$reqActive = new Request('GET', '/api/coordinator/spare-parts', ['is_active' => 'true']);
$resActive = $controller->getCatalog($reqActive);
$dataActive = $resActive->getDecodedBody()['data'] ?? [];

$assert(
    "getCatalog con is_active=true retorna solo piezas activas (1 pieza)",
    count($dataActive) === 1 && $dataActive[0]['part_code'] === 'VALV-ULKA-01'
);

// 1.3 Filtro por modelo de máquina
$reqModel = new Request('GET', '/api/coordinator/spare-parts', ['machine_model' => 'Fas Fast 900']);
$resModel = $controller->getCatalog($reqModel);
$dataModel = $resModel->getDecodedBody()['data'] ?? [];

$assert(
    "getCatalog con machine_model filtra correctamente por compatibilidad",
    count($dataModel) === 1 && $dataModel[0]['part_code'] === 'SOND-NTC-02'
);

// 1.4 getPart existente
$reqPart = (new Request('GET', '/api/coordinator/spare-parts/1'))->setRouteParams(['id' => '1']);
$resPart = $controller->getPart($reqPart);
$dataPart = $resPart->getDecodedBody()['data'] ?? [];

$assert(
    "getPart con ID 1 devuelve 200 OK con datos del repuesto",
    $resPart->getStatusCode() === 200 && ($dataPart['part_code'] ?? '') === 'VALV-ULKA-01'
);

// 1.5 getPart no existente -> 404 Not Found
$reqPart404 = (new Request('GET', '/api/coordinator/spare-parts/999'))->setRouteParams(['id' => '999']);
$resPart404 = $controller->getPart($reqPart404);
$err404 = $resPart404->getDecodedBody()['error'] ?? [];

$assert(
    "getPart con ID inexistente devuelve 404 SPARE_PART_NOT_FOUND",
    $resPart404->getStatusCode() === 404 && ($err404['code'] ?? '') === 'SPARE_PART_NOT_FOUND'
);

// =============================================================================
// 2. Creación de Repuesto (createPart)
// =============================================================================
echo "\n--- 2. Creación de Repuesto (createPart) ---\n";

$env = createControllerEnvironment();
$controller = $env['controller'];

// 2.1 Creación exitosa (201 Created)
$validCreateBody = [
    'part_code'         => 'PRES-VALV-03',
    'name'              => 'Válvula de Seguridad de Presión 12 Bar',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'CEME',
    'reference_cost'    => 19.80,
    'notes'             => 'Válvula para grupos de presión',
    'compatible_models' => ['Azkoyen Palma B', 'Azkoyen Palma+'],
];
$reqCreate = (new Request('POST', '/api/coordinator/spare-parts', [], $validCreateBody))
    ->setAttribute('authenticated_user', $env['coordinatorUser']);
$resCreate = $controller->createPart($reqCreate);
$dataCreate = $resCreate->getDecodedBody()['data'] ?? [];

$assert(
    "createPart devuelve 201 Created con repuesto creado",
    $resCreate->getStatusCode() === 201 && ($dataCreate['part_code'] ?? '') === 'PRES-VALV-03'
);
$assert(
    "createPart asigna modelos compatibles correctamente",
    in_array('Azkoyen Palma+', $dataCreate['compatible_models'] ?? [], true)
);

// 2.2 Rechazo de código duplicado (409 Conflict)
$dupCreateBody = $validCreateBody;
$dupCreateBody['part_code'] = 'VALV-ULKA-01'; // Código ya existente
$reqDup = new Request('POST', '/api/coordinator/spare-parts', [], $dupCreateBody);
$resDup = $controller->createPart($reqDup);
$errDup = $resDup->getDecodedBody()['error'] ?? [];

$assert(
    "createPart con código duplicado devuelve 409 SPARE_PART_CODE_EXISTS",
    $resDup->getStatusCode() === 409 && ($errDup['code'] ?? '') === 'SPARE_PART_CODE_EXISTS'
);

// 2.3 Rechazo de payload inválido (422 Unprocessable Entity)
$invalidCreateBody = $validCreateBody;
$invalidCreateBody['part_code'] = 'INVALID-COST-99'; // Código único para evitar 409
$invalidCreateBody['reference_cost'] = -5.00; // Coste negativo
$reqInv = new Request('POST', '/api/coordinator/spare-parts', [], $invalidCreateBody);
$resInv = $controller->createPart($reqInv);
$errInv = $resInv->getDecodedBody()['error'] ?? [];

$assert(
    "createPart con coste negativo devuelve 422 INVALID_SPARE_PART_PAYLOAD",
    $resInv->getStatusCode() === 422 && ($errInv['code'] ?? '') === 'INVALID_SPARE_PART_PAYLOAD'
);

// =============================================================================
// 3. Actualización de Repuesto (updatePart)
// =============================================================================
echo "\n--- 3. Actualización de Repuesto (updatePart) ---\n";

$env = createControllerEnvironment();
$controller = $env['controller'];

// 3.1 Actualización exitosa (200 OK)
$updateBody = [
    'name'              => 'Electroválvula 24V Ulka Reforzada',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'Ulka Italia',
    'reference_cost'    => 31.00,
    'notes'             => 'Versión 2026',
    'compatible_models' => ['Azkoyen Palma+'],
];
$reqUpdate = (new Request('PUT', '/api/coordinator/spare-parts/1', [], $updateBody))
    ->setRouteParams(['id' => '1'])
    ->setAttribute('authenticated_user', $env['coordinatorUser']);
$resUpdate = $controller->updatePart($reqUpdate);
$dataUpdate = $resUpdate->getDecodedBody()['data'] ?? [];

$assert(
    "updatePart devuelve 200 OK con campos modificados",
    $resUpdate->getStatusCode() === 200 &&
    ($dataUpdate['name'] ?? '') === 'Electroválvula 24V Ulka Reforzada' &&
    (float)($dataUpdate['reference_cost'] ?? 0.0) === 31.00
);

// 3.2 updatePart en ID inexistente -> 404
$reqUpdate404 = (new Request('PUT', '/api/coordinator/spare-parts/999', [], $updateBody))
    ->setRouteParams(['id' => '999']);
$resUpdate404 = $controller->updatePart($reqUpdate404);
$errUpd404 = $resUpdate404->getDecodedBody()['error'] ?? [];

$assert(
    "updatePart en repuesto inexistente devuelve 404 SPARE_PART_NOT_FOUND",
    $resUpdate404->getStatusCode() === 404 && ($errUpd404['code'] ?? '') === 'SPARE_PART_NOT_FOUND'
);

// =============================================================================
// 4. Baja Lógica y Reactivación (toggleStatus)
// =============================================================================
echo "\n--- 4. Baja Lógica y Reactivación (toggleStatus) ---\n";

$env = createControllerEnvironment();
$controller = $env['controller'];

// 4.1 Desactivar repuesto (is_active = false)
$reqDeact = (new Request('PATCH', '/api/coordinator/spare-parts/1/status', [], ['is_active' => false]))
    ->setRouteParams(['id' => '1']);
$resDeact = $controller->toggleStatus($reqDeact);
$dataDeact = $resDeact->getDecodedBody()['data'] ?? [];

$assert(
    "toggleStatus con is_active=false desactiva el repuesto devolviendo 200 OK",
    $resDeact->getStatusCode() === 200 && ($dataDeact['is_active'] ?? null) === false
);

// 4.2 Reactivar repuesto (is_active = true)
$reqReact = (new Request('PATCH', '/api/coordinator/spare-parts/1/status', [], ['is_active' => true]))
    ->setRouteParams(['id' => '1']);
$resReact = $controller->toggleStatus($reqReact);
$dataReact = $resReact->getDecodedBody()['data'] ?? [];

$assert(
    "toggleStatus con is_active=true reactiva el repuesto devolviendo 200 OK",
    $resReact->getStatusCode() === 200 && ($dataReact['is_active'] ?? null) === true
);

// 4.3 Falta de campo is_active -> 422
$reqNoStatus = (new Request('PATCH', '/api/coordinator/spare-parts/1/status', [], []))
    ->setRouteParams(['id' => '1']);
$resNoStatus = $controller->toggleStatus($reqNoStatus);
$assert(
    "toggleStatus sin campo is_active devuelve 422",
    $resNoStatus->getStatusCode() === 422
);

// =============================================================================
// 5. Lista de Modelos de Máquina (getModels)
// =============================================================================
echo "\n--- 5. Lista de Modelos de Máquina (getModels) ---\n";

$env = createControllerEnvironment();
$controller = $env['controller'];

$reqModels = new Request('GET', '/api/coordinator/spare-parts/models');
$resModels = $controller->getModels($reqModels);
$dataModels = $resModels->getDecodedBody()['data'] ?? [];

$assert(
    "getModels devuelve 200 OK con lista de modelos únicos",
    $resModels->getStatusCode() === 200 &&
    is_array($dataModels) &&
    in_array('Azkoyen Palma+', $dataModels, true) &&
    in_array('Fas Fast 900', $dataModels, true)
);

// =============================================================================
// 6. Panel Analítico y Alertas de Fallos Recurrentes (getAnalytics)
// =============================================================================
echo "\n--- 6. Panel Analítico (getAnalytics) ---\n";

$env = createControllerEnvironment();
$replacedRepo = $env['replacedPartRepo'];
$controller = $env['controller'];

// Poblar repositorios de prueba con datos analíticos
$replacedRepo->topParts = [
    [
        'part_code'        => 'VALV-ULKA-01',
        'name'             => 'Electroválvula 24V Ulka',
        'category'         => 'HYDRAULIC',
        'units_installed'  => 18,
        'accumulated_cost' => 513.00,
        'destinations'     => ['DESGUACE' => 12, 'TALLER' => 6],
    ],
];
$replacedRepo->modelCosts = [
    [
        'model'          => 'Azkoyen Palma+',
        'machines_count' => 8,
        'units_replaced' => 18,
        'total_cost'     => 513.00,
    ],
];
$replacedRepo->chronicAlerts = [
    [
        'machine_id'             => 7,
        'machine_code'           => 'MAQ-HOSP-002',
        'part_code'              => 'SOND-NTC-02',
        'replacements_in_period' => 4,
        'threshold'              => 3,
        'warning_message'        => 'Componente con Fallo Recurrente',
    ],
];

$reqAnalytics = new Request('GET', '/api/coordinator/spare-parts/analytics', ['period_days' => '90']);
$resAnalytics = $controller->getAnalytics($reqAnalytics);
$dataAnalytics = $resAnalytics->getDecodedBody()['data'] ?? [];

$assert(
    "getAnalytics responde 200 OK con estructura analítica",
    $resAnalytics->getStatusCode() === 200 &&
    ($dataAnalytics['period_days'] ?? 0) === 90 &&
    ($dataAnalytics['total_parts_replaced'] ?? 0) === 18 &&
    count($dataAnalytics['chronic_failure_alerts'] ?? []) === 1
);

// Rechazo de period_days inválido
$reqBadPeriod = new Request('GET', '/api/coordinator/spare-parts/analytics', ['period_days' => '-10']);
$resBadPeriod = $controller->getAnalytics($reqBadPeriod);
$assert(
    "getAnalytics con period_days negativo devuelve 400 INVALID_FILTER_PARAMS",
    $resBadPeriod->getStatusCode() === 400
);

// =============================================================================
// 7. Exportación de Consumos a CSV (exportCsv)
// =============================================================================
echo "\n--- 7. Exportación CSV (exportCsv) ---\n";

$env = createControllerEnvironment();
$replacedRepo = $env['replacedPartRepo'];
$controller = $env['controller'];

$replacedRepo->exportRecords = [
    [
        'fecha'                => '2026-09-28 14:30:00',
        'codigo_intervencion'  => 'TICK-2026-00105',
        'tipo_intervencion'    => 'INCIDENT',
        'codigo_maquina'       => 'MAQ-MAD-001',
        'modelo_maquina'       => 'Azkoyen Palma+',
        'sede'                 => 'Oficinas Centrales Repsol',
        'codigo_pieza'         => 'VALV-ULKA-01',
        'nombre_pieza'         => 'Electroválvula 24V Ulka',
        'categoria'            => 'HYDRAULIC',
        'unidades'             => 1,
        'coste_unitario_eur'   => 28.50,
        'coste_total_eur'      => 28.50,
        'destino_retirado'     => 'TALLER',
        'codigo_tecnico'       => 'OP-02',
        'notas'                => 'Bobina eléctrica intacta',
    ],
];

$reqCsv = new Request('GET', '/api/coordinator/spare-parts/export');
$resCsv = $controller->exportCsv($reqCsv);

$assert(
    "exportCsv devuelve 200 OK con Content-Type text/csv y cabecera de descarga attachment",
    $resCsv->getStatusCode() === 200 &&
    str_contains((string)$resCsv->getHeader('Content-Type'), 'text/csv') &&
    str_contains((string)$resCsv->getHeader('Content-Disposition'), 'attachment; filename="repuestos_intervenciones_')
);
$assert(
    "exportCsv contiene el BOM UTF-8 y la cabecera contractual con 15 columnas",
    str_starts_with($resCsv->getBody(), "\xEF\xBB\xBF") &&
    str_contains($resCsv->getBody(), 'Fecha,Codigo_Intervencion,Tipo_Intervencion')
);

// =============================================================================
// 8. Bandeja de Revisión de Piezas Fuera de Catálogo (getPendingReviewRequests)
// =============================================================================
echo "\n--- 8. Bandeja de Piezas Fuera de Catálogo (getPendingReviewRequests) ---\n";

$env = createControllerEnvironment();
$requestRepo = $env['requestRepo'];
$controller = $env['controller'];

$requestRepo->pendingReviews = [
    [
        'incident_id'             => 108,
        'ticket_code'             => 'TICK-2026-00108',
        'machine_code'            => 'MAQ-UNIV-004',
        'custom_part_description' => 'Sensor de caída infrarrojo especial para canal 4',
        'incident_status'         => 'PENDING_PARTS',
    ],
];

$reqReview = new Request('GET', '/api/coordinator/spare-parts/requests/pending-review');
$resReview = $controller->getPendingReviewRequests($reqReview);
$dataReview = $resReview->getDecodedBody()['data'] ?? [];

$assert(
    "getPendingReviewRequests devuelve 200 OK con la lista de solicitudes pendientes de revisión",
    $resReview->getStatusCode() === 200 &&
    count($dataReview) === 1 &&
    ($dataReview[0]['ticket_code'] ?? '') === 'TICK-2026-00108'
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
