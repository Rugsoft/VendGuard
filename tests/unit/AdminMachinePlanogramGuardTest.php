<?php

declare(strict_types=1);

/**
 * AdminMachinePlanogramGuardTest · T-PLN-09
 *
 * Suite unitaria del cerrojo inverso de la máquina (Módulo 32, RF-03.2, QA 4,
 * D3, Art. II). Certifica que `AdminMachineService::updateMachine` rechaza un
 * cambio de `machine_type` cuando algún carril activo quedaría incompatible y
 * que no escribe ni audita nada en ese caso.
 *
 * Se ejecuta el guardián PDO real (`PdoMachineTypePlanogramGuard`) sobre
 * repositorios en memoria: la lógica de compatibilidad no se sustituye, sólo se
 * aísla la persistencia.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red ni motor.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminMachineService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\MachineTypeChangeBlockedException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineTypePlanogramGuard;

echo "======================================================================\n";
echo " VendGuard · Unitario · AdminMachinePlanogramGuardTest (T-PLN-09)\n";
echo "======================================================================\n\n";

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

/** Repositorio de máquinas en memoria con contador de escrituras. */
final class GuardMachineRepository implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];

    /** @var array<int, array{ticket_code: string, status: string}> */
    public array $activeTickets = [];

    public int $updates = 0;

    public function findActiveByLocationId(int $locationId): array
    {
        return [];
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[$id] ?? null;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        foreach ($this->machines as $machine) {
            if ($machine->getCode() === strtoupper(trim($code))) {
                return $machine;
            }
        }

        return null;
    }

    public function create(array $data): Machine
    {
        throw new RuntimeException('No usado en esta suite.');
    }

    public function update(int $id, array $data): bool
    {
        $machine = $this->machines[$id] ?? null;
        if ($machine === null) {
            return false;
        }

        $this->updates++;
        $this->machines[$id] = new Machine(
            $machine->getId(),
            $machine->getLocationId(),
            $machine->getCode(),
            $data['model'] ?? $machine->getModel(),
            array_key_exists('machine_type', $data)
                ? MachineType::fromString((string)$data['machine_type'])
                : $machine->getMachineType(),
            $data['floor_wing'] ?? $machine->getFloorWing(),
            array_key_exists('notes', $data) ? $data['notes'] : $machine->getNotes(),
            $machine->isActive(),
            $machine->getCreatedAt(),
            '2026-11-12 12:00:00',
            $machine->getDeletedAt()
        );

        return true;
    }

    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        return false;
    }

    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        return false;
    }

    public function findAll(array $filters = []): array
    {
        return [];
    }

    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return isset($this->activeTickets[$machineId]);
    }

    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        return $this->activeTickets[$machineId] ?? null;
    }

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        return false;
    }

    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return false;
    }
}

/** Sede mínima: el cerrojo no la consulta, pero el constructor la exige. */
final class GuardLocationRepository implements LocationRepositoryInterface
{
    public function findBySiteCode(string $siteCode): ?Location
    {
        return null;
    }

    public function findById(int $id): ?Location
    {
        return null;
    }

    public function findAllActive(): array
    {
        return [];
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        return [];
    }

    public function create(array $data): Location
    {
        throw new RuntimeException('No usado en esta suite.');
    }

    public function update(int $id, array $data): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return false;
    }

    public function restore(int $id): bool
    {
        return false;
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        return false;
    }

    public function countActiveMachines(int $locationId): int
    {
        return 0;
    }
}

/** Planograma en memoria: sólo se consume la lectura de carriles activos. */
final class GuardPlanogramRepository implements MachinePlanogramRepositoryInterface
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $slots = [];

    public function findMachineById(int $machineId): ?array
    {
        return null;
    }

    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->slots[$machineId] ?? [],
            static fn (array $row): bool => !$onlyActive || $row['is_active'] === true
        ));
    }

    public function findSlot(int $machineId, string $slotCode): ?array
    {
        return null;
    }

    public function upsertSlot(MachinePlanogramSlot $slot): array
    {
        throw new RuntimeException('No usado en esta suite.');
    }

    public function replaceMachinePlanogram(int $machineId, array $slots): array
    {
        throw new RuntimeException('No usado en esta suite.');
    }

    public function deactivateSlot(int $machineId, string $slotCode): bool
    {
        return false;
    }

    public function summarize(int $machineId): array
    {
        return [];
    }

    public function findMachinesForRestocker(int $restockerId): array
    {
        return [];
    }

    public function findMachineForRestocker(int $machineId, int $restockerId): ?array
    {
        return null;
    }
}

/** Catálogo mínimo para hidratar los productos de los carriles. */
final class GuardProductRepository implements VendingProductRepositoryInterface
{
    /** @var array<int, VendingProduct> */
    public array $products = [];

    public function create(VendingProduct $product): VendingProduct
    {
        throw new RuntimeException('No usado en esta suite.');
    }

    public function update(VendingProduct $product): bool
    {
        return false;
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        return false;
    }

    public function findById(int $id): ?VendingProduct
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?VendingProduct
    {
        foreach ($this->products as $product) {
            if ($product->getSku() === strtoupper(trim($sku))) {
                return $product;
            }
        }

        return null;
    }

    public function isSkuUsed(string $sku, ?int $excludeId = null): bool
    {
        return false;
    }

    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array {
        return array_values($this->products);
    }

    public function countActiveSlots(int $productId): int
    {
        return 0;
    }

    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        return [];
    }
}

final class GuardAuditLogger extends AuditLogger
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function __construct()
    {
    }

    public function logEvent(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function logMachineEvent(
        int $machineId,
        string $action,
        array $user,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        return $this->logEvent(new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_MACHINE,
            entityId: $machineId,
            action: strtoupper(trim($action)),
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'COORDINATOR',
            userName: $user['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        ));
    }
}

$makeMachine = static function (int $id, string $code, MachineType $type): Machine {
    return new Machine(
        $id,
        1,
        $code,
        'Modelo ' . $code,
        $type,
        'Planta 1',
        null,
        true,
        '2026-11-01 08:00:00',
        '2026-11-01 08:00:00',
        null
    );
};

$machineRepo = new GuardMachineRepository();
$machineRepo->machines[1] = $makeMachine(1, 'T9-INCOMPATIBLE', MachineType::PERISHABLE_FOOD);
$machineRepo->machines[2] = $makeMachine(2, 'T9-VACIA', MachineType::SNACKS);
$machineRepo->machines[3] = $makeMachine(3, 'T9-INACTIVO', MachineType::PERISHABLE_FOOD);
$machineRepo->machines[4] = $makeMachine(4, 'T9-COMPATIBLE', MachineType::PERISHABLE_FOOD);
$machineRepo->machines[5] = $makeMachine(5, 'T9-MISMO-TIPO', MachineType::PERISHABLE_FOOD);

$products = new GuardProductRepository();
$products->products[10] = new VendingProduct(
    null,
    'T9-SANDWICH',
    'Sándwich refrigerado de prueba',
    ProductCategory::PERISHABLE_FOOD,
    ProductUnitType::UNIT,
    1.15,
    2.40,
    true,
    5,
    true
);
$products->products[11] = new VendingProduct(
    null,
    'T9-CAFE',
    'Café de prueba 1 kg',
    ProductCategory::HOT_BEVERAGE_INGREDIENT,
    ProductUnitType::GRAMS,
    7.25,
    0.00,
    false,
    null,
    true
);

$planograms = new GuardPlanogramRepository();
$planograms->slots[1] = [
    ['id' => 1, 'machine_id' => 1, 'slot_code' => '11', 'product_id' => 10, 'max_capacity' => 10, 'current_estimated_qty' => 5, 'min_threshold' => 3, 'is_active' => true],
    ['id' => 2, 'machine_id' => 1, 'slot_code' => '12', 'product_id' => 11, 'max_capacity' => 1000, 'current_estimated_qty' => 500, 'min_threshold' => 100, 'is_active' => true],
];
$planograms->slots[3] = [
    ['id' => 3, 'machine_id' => 3, 'slot_code' => '11', 'product_id' => 10, 'max_capacity' => 10, 'current_estimated_qty' => 0, 'min_threshold' => 3, 'is_active' => false],
];
$planograms->slots[4] = [
    ['id' => 4, 'machine_id' => 4, 'slot_code' => '11', 'product_id' => 10, 'max_capacity' => 10, 'current_estimated_qty' => 5, 'min_threshold' => 3, 'is_active' => true],
    ['id' => 5, 'machine_id' => 4, 'slot_code' => '12', 'product_id' => 11, 'max_capacity' => 1000, 'current_estimated_qty' => 500, 'min_threshold' => 100, 'is_active' => true],
];
$planograms->slots[5] = [
    ['id' => 6, 'machine_id' => 5, 'slot_code' => '11', 'product_id' => 10, 'max_capacity' => 10, 'current_estimated_qty' => 5, 'min_threshold' => 3, 'is_active' => true],
];

$guard = new PdoMachineTypePlanogramGuard($planograms, $products);
$audit = new GuardAuditLogger();
$service = new AdminMachineService($machineRepo, new GuardLocationRepository(), $audit, $guard);
$actor = ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Coordinación QA 4'];

// =====================================================================
// 1. Cambio incompatible: 409 con conflictos y sin escritura
// =====================================================================
echo "--- 1. Cambio incompatible (QA 4) ---\n";

$conflictCode = null;
$conflictStatus = null;
$conflictContext = [];
$conflictMessage = '';
try {
    $service->updateMachine(1, ['machine_type' => 'SNACKS'], $actor);
} catch (PlanogramValidationException $exception) {
    $conflictCode = $exception->getErrorCode();
    $conflictStatus = $exception->getHttpStatusCode();
    $conflictContext = $exception->getContext();
    $conflictMessage = $exception->getMessage();
}

$assert(
    '1.1 Cambiar a SNACKS con frío e ingrediente activos devuelve 409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE',
    $conflictCode === 'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE' && $conflictStatus === 409,
    json_encode([$conflictCode, $conflictStatus])
);

$assert(
    '1.2 El contexto enumera los dos carriles con su SKU y motivo',
    count($conflictContext['conflicts']) === 2
    && array_column($conflictContext['conflicts'], 'slot_code') === ['11', '12']
    && $conflictContext['conflicts'][0]['sku'] === 'T9-SANDWICH'
    && $conflictContext['conflicts'][0]['reason'] === 'COLD_CHAIN_MACHINE_INCOMPATIBLE'
    && str_contains($conflictMessage, '11')
    && str_contains($conflictMessage, 'T9-CAFE'),
    json_encode($conflictContext)
);

$assert(
    '1.3 El rechazo no escribe la máquina ni emite auditoría',
    $machineRepo->machines[1]->getMachineType() === MachineType::PERISHABLE_FOOD
    && $machineRepo->updates === 0
    && $audit->events === []
);

// =====================================================================
// 2. Avería activa: el bloqueo funcional manda antes que el planograma
// =====================================================================
echo "\n--- 2. Precedencia del bloqueo por avería ---\n";

$machineRepo->activeTickets[1] = ['ticket_code' => 'INC-T9-001', 'status' => 'IN_PROGRESS'];
$ticketBlocked = null;
try {
    $service->updateMachine(1, ['machine_type' => 'SNACKS'], $actor);
} catch (MachineTypeChangeBlockedException $exception) {
    $ticketBlocked = $exception->getErrorCode();
}
$assert(
    '2.1 Con avería activa se devuelve MACHINE_TYPE_CHANGE_BLOCKED antes de mirar el planograma',
    $ticketBlocked === 'MACHINE_TYPE_CHANGE_BLOCKED'
    && $machineRepo->updates === 0
    && $audit->events === []
);
unset($machineRepo->activeTickets[1]);

// =====================================================================
// 3. Cambio compatible, máquina vacía y carril inactivo
// =====================================================================
echo "\n--- 3. Cambios permitidos ---\n";

$compatible = $service->updateMachine(4, ['machine_type' => 'COMBO'], $actor);
$compatibleEvent = $audit->events[0] ?? null;
$assert(
    '3.1 Pasar a COMBO con frío e ingrediente es compatible y se persiste',
    $compatible->getMachineType() === MachineType::COMBO
    && $machineRepo->machines[4]->getMachineType() === MachineType::COMBO
    && $compatibleEvent !== null
    && $compatibleEvent->getAction() === 'MACHINE_UPDATED'
    && $compatibleEvent->getNewState()['machine_type'] === 'COMBO'
);

$emptyChange = $service->updateMachine(2, ['machine_type' => 'HOT_DRINKS'], $actor);
$assert(
    '3.2 Una máquina sin carriles cambia de tipología sin conflicto',
    $emptyChange->getMachineType() === MachineType::HOT_DRINKS
);

$inactiveChange = $service->updateMachine(3, ['machine_type' => 'SNACKS'], $actor);
$assert(
    '3.3 Un carril inactivo no bloquea el cambio de tipología',
    $inactiveChange->getMachineType() === MachineType::SNACKS
);

// =====================================================================
// 4. Sin cambio efectivo de tipología
// =====================================================================
echo "\n--- 4. Sin cambio efectivo ---\n";

$updatesBeforeSameType = $machineRepo->updates;
$sameType = $service->updateMachine(5, ['machine_type' => 'PERISHABLE_FOOD'], $actor);
$assert(
    '4.1 Repetir la tipología vigente no consulta ni escribe',
    $sameType->getMachineType() === MachineType::PERISHABLE_FOOD
    && $machineRepo->updates === $updatesBeforeSameType
);

$modelOnly = $service->updateMachine(5, ['model' => 'Modelo Renombrado'], $actor);
$assert(
    '4.2 Cambiar sólo el modelo no dispara el cerrojo del planograma',
    $modelOnly->getModel() === 'Modelo Renombrado'
    && $machineRepo->machines[5]->getMachineType() === MachineType::PERISHABLE_FOOD
);

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-09 (cerrojo QA 4) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
