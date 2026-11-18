<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineRestockVisitService;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\CashCollectionRegistrarInterface;
use VendGuard\Core\Domain\Repository\InSituRestockVisitGatewayInterface;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

$GLOBALS['assertions'] = 0;

function check(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * @return MachineRestockVisitValidationException
 */
function expectCashFailure(callable $operation, string $expectedCode): MachineCashCollectionValidationException
{
    try {
        $operation();
    } catch (MachineCashCollectionValidationException $exception) {
        check($exception->getErrorCode() === $expectedCode, "código {$expectedCode}, recibido {$exception->getErrorCode()}");
        return $exception;
    }

    fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y no se lanzó ninguna excepción de recaudación.\n");
    exit(1);
}

function expectValidationFailure(callable $operation, string $expectedCode): MachineRestockVisitValidationException
{
    try {
        $operation();
    } catch (MachineRestockVisitValidationException $exception) {
        check($exception->getErrorCode() === $expectedCode, "código {$expectedCode}, recibido {$exception->getErrorCode()}");
        return $exception;
    }

    fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y no se lanzó ninguna excepción.\n");
    exit(1);
}

final class InMemoryTransactionManager implements TransactionManagerInterface
{
    public function runInTransaction(callable $operation): mixed
    {
        return $operation();
    }
}

final class FakeCashCollectionRegistrar implements CashCollectionRegistrarInterface
{
    /** @var list<array{machineId: int, payload: array<string, mixed>, actor: array<string, mixed>, visitId: int|null}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $visitSummary = null;

    /** @var array{recent_cash_collections: list<array<string, mixed>>, cash_route_summary: array<string, mixed>} */
    public array $routeContext = ['recent_cash_collections' => [], 'cash_route_summary' => []];

    public function registerCollectionInTransaction(
        int $machineId,
        array $payload,
        array $actor,
        ?int $restockVisitId = null
    ): array {
        $this->calls[] = [
            'machineId' => $machineId,
            'payload' => $payload,
            'actor' => $actor,
            'visitId' => $restockVisitId,
        ];

        return [
            'id' => 901,
            'collection_code' => 'MCC-2026-000901',
            'bag_seal_code' => strtoupper(trim((string)($payload['bag_seal_code'] ?? ''))),
            'status' => 'SEALED_IN_ROUTE',
        ];
    }

    public function summarizeForVisit(int $restockVisitId): ?array
    {
        return $this->visitSummary;
    }

    public function routeCashContext(int $restockerId, int $limit = 5): array
    {
        return $this->routeContext;
    }
}

final class InMemoryAuditLogRepository implements AuditLogRepositoryInterface
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
        return $this->events;
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

final class InMemoryRestockVisitRepository implements MachineRestockVisitRepositoryInterface
{
    /** @var array<int, MachineRestockVisit> */
    public array $visits = [];

    /** @var list<MachineRestockVisitItem> */
    public array $items = [];

    private int $nextId = 1;

    public function insertVisit(MachineRestockVisit $visit): MachineRestockVisit
    {
        $persisted = $visit->withId($this->nextId++);
        $this->visits[(int)$persisted->getId()] = $persisted;
        return $persisted;
    }

    public function insertItem(MachineRestockVisitItem $item): MachineRestockVisitItem
    {
        $persisted = $item->withId(count($this->items) + 1);
        $this->items[] = $persisted;
        return $persisted;
    }

    public function findById(int $visitId, bool $includeCosts = false): ?array
    {
        if (!isset($this->visits[$visitId])) {
            return null;
        }

        $visit = $this->visits[$visitId];
        $items = array_values(array_filter(
            $this->items,
            static fn (MachineRestockVisitItem $item): bool => $item->getVisitId() === $visitId
        ));

        return [
            'id' => $visit->getId(),
            'visit_code' => $visit->getVisitCode(),
            'status' => $visit->getStatus()->value,
            'machine_id' => $visit->getMachineId(),
            'restocker_id' => $visit->getRestockerId(),
            'loading_order_id' => $visit->getLoadingOrderId(),
            'triggered_incident_id' => $visit->getTriggeredIncidentId(),
            'items' => array_map(
                static fn (MachineRestockVisitItem $item): array => $item->toArray($includeCosts),
                $items
            ),
        ];
    }

    public function findByClientMutationId(
        int $restockerId,
        string $clientMutationId,
        bool $includeCosts = false
    ): ?array {
        foreach ($this->visits as $visit) {
            if ($visit->getRestockerId() === $restockerId
                && $visit->getClientMutationId() === $clientMutationId) {
                return $this->findById((int)$visit->getId(), $includeCosts);
            }
        }

        return null;
    }

    public function listForRestocker(int $restockerId, array $filters = []): array
    {
        return [];
    }

    public function listForCoordinator(array $filters = []): array
    {
        return [];
    }

    public function suggestLiquidationQuantities(int $loadingOrderId, int $restockerId): array
    {
        return [];
    }
}

final class InMemoryPlanogramRepository implements MachinePlanogramRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $movements = [];

    public function findMachineById(int $machineId): ?array
    {
        return null;
    }

    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array
    {
        return [];
    }

    public function findSlot(int $machineId, string $slotCode): ?array
    {
        return null;
    }

    public function upsertSlot(MachinePlanogramSlot $slot): array
    {
        return $slot->toArray();
    }

    public function replaceMachinePlanogram(int $machineId, array $slots): array
    {
        return [];
    }

    public function deactivateSlot(int $machineId, string $slotCode): bool
    {
        return true;
    }

    public function summarize(int $machineId): array
    {
        return [];
    }

    public function findCompatibleSourceMachines(int $machineId, MachineType $machineType): array
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

    public function applyVisitSlotMovement(
        int $machineId,
        string $slotCode,
        int $qtyWithdrawnExpired,
        int $qtyWithdrawnDamaged,
        int $qtyAdded,
        ?string $loadedBatchCode = null,
        ?DateTimeImmutable $loadedExpirationDate = null,
        ?DateTimeImmutable $today = null
    ): array {
        $this->movements[] = [
            'machine_id' => $machineId,
            'slot_code' => $slotCode,
            'withdrawn_expired' => $qtyWithdrawnExpired,
            'withdrawn_damaged' => $qtyWithdrawnDamaged,
            'added' => $qtyAdded,
            'loaded_batch_code' => $loadedBatchCode,
            'loaded_expiration_date' => $loadedExpirationDate?->format('Y-m-d'),
        ];

        return [];
    }
}

final class InMemoryRestockerVanStockRepository implements RestockerVanStockRepositoryInterface
{
    /** @var array<int, array<int, array<string, RestockerVanProductStock>>> */
    public array $lots = [];

    public function search(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        return [];
    }

    public function findByRestocker(
        int $restockerId,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        $rows = [];
        foreach ($this->lots[$restockerId] ?? [] as $productLots) {
            foreach ($productLots as $lot) {
                $rows[] = $lot->toArray() + ['restocker_id' => $restockerId, 'requires_cold_chain' => true];
            }
        }

        return $rows;
    }

    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock {
        $batch = strtoupper(trim($batchCode));
        return $this->lots[$restockerId][$productId][$batch] ?? null;
    }

    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        $this->lots[$stock->getRestockerId()][$stock->getProductId()][$stock->getBatchCode()] = $stock;
        return $stock;
    }

    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array
    {
        return [];
    }
}

final class FakeInSituRestockVisitGateway implements InSituRestockVisitGatewayInterface
{
    /** @var array<string, mixed>|null */
    public ?array $context = null;

    /** @var list<MachinePlanogramSlot> */
    public array $slots = [];

    /** @var array<int, float> */
    public array $costs = [];

    public bool $quarantined = false;

    public ?int $resolvedIncidentId = null;

    /** @var array<string, mixed> */
    public array $incidentCall = [];

    public function lockMachineContext(int $machineId, int $restockerId): ?array
    {
        return $this->context;
    }

    public function lockActiveSlots(int $machineId): array
    {
        return $this->slots;
    }

    public function findProductUnitCost(int $productId): float
    {
        return $this->costs[$productId] ?? 1.00;
    }

    public function lockMachineSanitarily(int $machineId, MachineType $machineType, string $reason): void
    {
        $this->quarantined = true;
    }

    public function resolveThermalIncident(
        int $machineId,
        string $machineCode,
        int $locationId,
        ?int $existingIncidentId,
        float $cabinTemp,
        string $photoUrl,
        string $notes,
        array $actor
    ): int {
        $this->incidentCall = [
            'machine_id' => $machineId,
            'existing_incident_id' => $existingIncidentId,
            'cabin_temp' => $cabinTemp,
            'photo_url' => $photoUrl,
            'notes' => $notes,
        ];

        return $this->resolvedIncidentId ?? 77;
    }
}

/**
 * @param array<string, mixed> $overrides
 */
function completedContext(array $overrides = []): array
{
    return $overrides + [
        'id' => 1,
        'machine_code' => 'VEND-0101',
        'machine_type' => MachineType::PERISHABLE_FOOD,
        'location_id' => 10,
        'sanitary_status' => 'OK',
        'is_out_of_service' => false,
        'is_covered' => true,
        'has_open_thermal_breakdown' => false,
        'active_incident_id' => null,
        'loading_order_id' => 55,
    ];
}

/**
 * @param array<string, mixed> $overrides
 */
function completedPayload(array $overrides = []): array
{
    return $overrides + [
        'status' => 'COMPLETED',
        'client_mutation_id' => 'mut-completed-0001',
        'cabin_temp_celsius' => 3.2,
        'hygiene_checklist_json' => [
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [
            ['slot_code' => '11', 'qty_added' => 7, 'loaded_batch_code' => 'B1'],
            ['slot_code' => '12', 'qty_added' => 4, 'loaded_batch_code' => 'B2'],
        ],
    ];
}

/**
 * @return array{0: MachineRestockVisitService, 1: InMemoryRestockVisitRepository, 2: InMemoryAuditLogRepository, 3: FakeInSituRestockVisitGateway, 4: InMemoryPlanogramRepository, 5: InMemoryRestockerVanStockRepository, 6: FakeCashCollectionRegistrar}
 */
function buildService(?string $uploadDir = null, bool $withCash = true): array
{
    $visits = new InMemoryRestockVisitRepository();
    $planograms = new InMemoryPlanogramRepository();
    $vanStock = new InMemoryRestockerVanStockRepository();
    $gateway = new FakeInSituRestockVisitGateway();
    $auditRepo = new InMemoryAuditLogRepository();
    $cash = new FakeCashCollectionRegistrar();

    $service = new MachineRestockVisitService(
        $visits,
        $planograms,
        $vanStock,
        $gateway,
        new InMemoryTransactionManager(),
        new AuditLogger($auditRepo),
        new LocalFileUploader($uploadDir),
        $withCash ? $cash : null
    );

    return [$service, $visits, $auditRepo, $gateway, $planograms, $vanStock, $cash];
}

function seedCompletedFixtures(
    FakeInSituRestockVisitGateway $gateway,
    InMemoryRestockerVanStockRepository $vanStock
): void {
    $gateway->context = completedContext();
    $gateway->slots = [
        new MachinePlanogramSlot(101, 1, '11', 1, 20, 5, 2, true, null, null, 'CARRIL-A', new DateTimeImmutable('+10 days')),
        new MachinePlanogramSlot(102, 1, '12', 2, 20, 0, 2, true, null, null, null, null),
    ];
    $gateway->costs = [1 => 1.50, 2 => 2.25];

    $vanStock->upsertLot(new RestockerVanProductStock(null, 7, 1, 'B1', new DateTimeImmutable('+20 days'), 10, true));
    $vanStock->upsertLot(new RestockerVanProductStock(null, 7, 2, 'B2', new DateTimeImmutable('+25 days'), 10, true));
}

// 1 · COMPLETED: movimiento atómico, snapshot, orden de carga y auditoría.
[$service, $visits, $auditRepo, $gateway, $planograms, $vanStock] = buildService();
seedCompletedFixtures($gateway, $vanStock);

$result = $service->registerVisit(1, completedPayload(), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
check(($result['status'] ?? null) === 'COMPLETED', 'la visita completada se persiste');
check(($result['loading_order_id'] ?? null) === 55, 'la orden CONFIRMED_BY_RESTOCKER se vincula sola');
check(count($visits->visits) === 1, 'se inserta una única cabecera');
check(count($visits->items) === 2, 'se insertan las dos líneas declaradas');
check($gateway->quarantined === false, 'una visita completada no bloquea la máquina');
check($vanStock->findLot(7, 1, 'B1')?->getQuantityAvailable() === 3, 'el lote B1 se descuenta de 10 a 3');
check($vanStock->findLot(7, 2, 'B2')?->getQuantityAvailable() === 6, 'el lote B2 se descuenta de 10 a 6');
check(count($planograms->movements) === 2, 'se aplican dos movimientos de carril');
check(($planograms->movements[0]['loaded_batch_code'] ?? null) === 'B1', 'el movimiento recibe el lote cargado');
check(($planograms->movements[0]['loaded_expiration_date'] ?? null) === (new DateTimeImmutable('+20 days'))->format('Y-m-d'), 'el movimiento recibe la caducidad real del lote');
check($visits->items[0]->getUnitCostSnapshotEur() === 1.50, 'el coste unitario se congela');
check($visits->items[1]->getCommercialSpoilageLossEur() === 0.0, 'sin retiradas no hay merma comercial');
check(count($auditRepo->events) === 1, 'se registra un evento de auditoría');
check($auditRepo->events[0]->getAction() === MachineRestockVisitService::ACTION_RESTOCK_VISIT_COMPLETED, 'la acción completada es la del contrato');

// 2 · Idempotencia por client_mutation_id.
$repeat = $service->registerVisit(1, completedPayload(), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
check(($repeat['visit_code'] ?? null) === ($result['visit_code'] ?? null), 'el reenvío devuelve la visita original');
check(count($visits->visits) === 1, 'el reenvío no duplica la cabecera');
check(count($visits->items) === 2, 'el reenvío no duplica las líneas');
check(count($planograms->movements) === 2, 'el reenvío no vuelve a mover carriles');

// 3 · ABORTED_THERMAL_BREACH: cuarentena, ticket idempotente y sin líneas.
$uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vg_mrv_' . bin2hex(random_bytes(4));
mkdir($uploadDir, 0777, true);
$pngPath = $uploadDir . DIRECTORY_SEPARATOR . 'evidence.png';
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1cAAAAASUVORK5CYII='));

[$service, $visits, $auditRepo, $gateway, , $vanStock] = buildService($uploadDir);
$gateway->context = completedContext([
    'id' => 2,
    'machine_code' => 'VEND-0202',
    'machine_type' => MachineType::COLD_DRINKS,
    'sanitary_status' => 'OK',
    'loading_order_id' => null,
]);
$photoUrl = $service->uploadPhoto([
    'tmp_name' => $pngPath,
    'name' => 'evidence.png',
    'size' => filesize($pngPath),
    'error' => UPLOAD_ERR_OK,
]);
check(str_starts_with($photoUrl, '/uploads/'), 'la foto se guarda bajo /uploads/');

$abortResult = $service->registerVisit(2, [
    'status' => 'ABORTED_THERMAL_BREACH',
    'client_mutation_id' => 'mut-abort-0001',
    'cabin_temp_celsius' => 9.5,
    'precautionary_seal_applied' => true,
    'photo_evidence_url' => $photoUrl,
    'internal_notes' => 'Cabina a 9,5 grados al abrir la puerta; se precinta y se avisa al SAT.',
], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
check(($abortResult['status'] ?? null) === 'ABORTED_THERMAL_BREACH', 'el aborto térmico se persiste');
check(($abortResult['triggered_incident_id'] ?? null) === 77, 'el ticket térmico queda vinculado a la visita');
check($gateway->quarantined === true, 'el aborto bloquea sanitariamente la máquina');
check(($gateway->incidentCall['cabin_temp'] ?? null) === 9.5, 'el ticket recibe la lectura de cabina');
check(count($visits->items) === 0, 'el aborto no escribe líneas de carga');
check(count($auditRepo->events) >= 1, 'el aborto deja rastro de auditoría');
check(
    $auditRepo->events[count($auditRepo->events) - 1]->getAction() === MachineRestockVisitService::ACTION_RESTOCK_VISIT_ABORTED_THERMAL_BREACH,
    'la acción del aborto es la del contrato'
);

// 4 · Cerrojo 1: avería térmica abierta.
[$service, , , $gateway] = buildService();
$gateway->context = completedContext(['has_open_thermal_breakdown' => true]);
expectValidationFailure(
    fn () => $service->registerVisit(1, completedPayload(), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']),
    MachineRestockVisitValidationException::SANITARY_RESTOCK_BLOCKED
);

// 5 · Cerrojo 3: carril caducado sin retirada total, aunque no haya líneas.
[$service, , , $gateway] = buildService();
$gateway->context = completedContext();
$gateway->slots = [
    new MachinePlanogramSlot(201, 1, '21', 1, 20, 5, 2, true, null, null, 'CAD-1', new DateTimeImmutable('-1 day')),
];
expectValidationFailure(
    fn () => $service->registerVisit(1, completedPayload([
        'items' => [],
        'client_mutation_id' => 'mut-fefo-0001',
    ]), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']),
    MachineRestockVisitValidationException::FEFO_WITHDRAWAL_REQUIRED
);

// 6 · Cerrojo 3: saldo insuficiente en el lote FEFO de la furgoneta.
[$service, , , $gateway, , $vanStock] = buildService();
$gateway->context = completedContext();
$gateway->slots = [
    new MachinePlanogramSlot(301, 1, '31', 1, 30, 0, 2, true, null, null, null, null),
];
$gateway->costs = [1 => 1.00];
$vanStock->upsertLot(new RestockerVanProductStock(null, 7, 1, 'SHORT', new DateTimeImmutable('+5 days'), 2, true));
expectValidationFailure(
    fn () => $service->registerVisit(1, completedPayload([
        'client_mutation_id' => 'mut-short-0001',
        'items' => [['slot_code' => '31', 'qty_added' => 9, 'loaded_batch_code' => 'SHORT']],
    ]), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']),
    MachineRestockVisitValidationException::INSUFFICIENT_VAN_BATCH_STOCK
);

// 7 · Volcado de conflicto offline en audit_log (C3).
[$service, , $auditRepo] = buildService();
$ack = $service->dumpOfflineConflict([
    'machine_id' => 1,
    'client_mutation_id' => 'mut-offline-0001',
    'reason' => 'La máquina entró en cuarentena mientras el móvil estaba sin cobertura.',
], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
check(($ack['status'] ?? null) === 'ARCHIVED', 'el conflicto offline se archiva');
$last = $auditRepo->events[count($auditRepo->events) - 1];
check($last->getAction() === MachineRestockVisitService::ACTION_OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP, 'la acción del volcado es la del contrato');
check($last->getEntityType() === AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT, 'el volcado se audita bajo la visita de reposición');

// 8 · Solo RESTOCKER puede registrar.
[$service, , , $gateway] = buildService();
$gateway->context = completedContext();
expectValidationFailure(
    fn () => $service->registerVisit(1, completedPayload(), ['id' => 99, 'role' => 'COORDINATOR', 'name' => 'Coordi']),
    MachineRestockVisitValidationException::RESTOCKER_ROLE_REQUIRED
);

// 9 · D1/C4: la recaudación se registra dentro de la transacción de la visita.
[$service, $visits, , $gateway, , $vanStock, $cashRegistrar] = buildService();
seedCompletedFixtures($gateway, $vanStock);
$visitWithCash = $service->registerVisit(1, completedPayload([
    'client_mutation_id' => 'mut-visit-cash-0001',
    'cash_collection' => [
        'client_mutation_id' => 'mut-cash-070001',
        'bag_seal_code' => ' bag-2026-070001 ',
        'sales_counter_reading' => 342.10,
        'change_tubes_loaded_eur' => 10.00,
    ],
]), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
check(($visitWithCash['cash_collection']['id'] ?? null) === 901, 'la visita devuelve la recaudación creada');
check(count($cashRegistrar->calls) === 1, 'la recaudación se delega una sola vez');
check(($cashRegistrar->calls[0]['machineId'] ?? null) === 1, 'la recaudación hereda la máquina de la visita');
check(($cashRegistrar->calls[0]['visitId'] ?? null) === 1, 'la recaudación se vincula al id de la visita');
check(($cashRegistrar->calls[0]['payload']['bag_seal_code'] ?? null) === ' bag-2026-070001 ', 'el payload de recaudación llega íntegro');
check(count($visits->visits) === 1, 'la visita con recaudación se inserta una sola vez');

// 10 · C4: un aborto térmico no admite recaudación (422, sin residuo).
[$service, $visits, , $gateway, , , $cashRegistrar] = buildService();
$gateway->context = completedContext();
expectCashFailure(
    fn () => $service->registerVisit(1, [
        'status' => 'ABORTED_THERMAL_BREACH',
        'client_mutation_id' => 'mut-abort-cash-0001',
        'cabin_temp_celsius' => 9.5,
        'cash_collection' => [
            'client_mutation_id' => 'mut-cash-070002',
            'bag_seal_code' => 'BAG-2026-070002',
            'sales_counter_reading' => 100.00,
        ],
    ], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']),
    MachineCashCollectionValidationException::THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN
);
check(count($visits->visits) === 0, 'el aborto con recaudación no deja visita residual');
check(count($cashRegistrar->calls) === 0, 'el aborto con recaudación no registra efectivo');

// 11 · QA 3: el detalle expone solo el resumen opaco del corte.
[$service, , , $gateway, , $vanStock, $cashRegistrar] = buildService();
seedCompletedFixtures($gateway, $vanStock);
$service->registerVisit(1, completedPayload(['client_mutation_id' => 'mut-detail-0001']), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
$cashRegistrar->visitSummary = [
    'id' => 901,
    'collection_code' => 'MCC-2026-000901',
    'bag_seal_code' => 'BAG-2026-070001',
    'status' => 'SEALED_IN_ROUTE',
    'status_label' => 'Precintada en ruta',
    'change_tubes_loaded_eur' => 10.00,
    'reception_cash_delivered_eur' => null,
    'has_reception_receipt' => false,
];
$detail = $service->findVisitDetail(1);
check(($detail['cash_collection_summary']['collection_code'] ?? null) === 'MCC-2026-000901', 'el detalle incluye el resumen del corte');
check(!array_key_exists('expected_cashbox_eur', $detail['cash_collection_summary'] ?? []), 'el detalle no filtra el esperado del arqueo ciego');
check(!array_key_exists('previous_counter_reading', $detail['cash_collection_summary'] ?? []), 'el detalle no filtra la lectura previa');
check($service->findVisitDetail(999) === null, 'una visita inexistente no inventa detalle');

// 12 · QA 3: la ruta recibe recaudaciones recientes y KPIs, o vacío sin servicio.
$cashRegistrar->routeContext = [
    'recent_cash_collections' => [['id' => 901, 'collection_code' => 'MCC-2026-000901']],
    'cash_route_summary' => ['pending_vault_bags_count' => 1, 'cash_in_route_eur' => 352.10],
];
$cashContext = $service->routeCashContext(7);
check(count($cashContext['recent_cash_collections']) === 1, 'la ruta lista la recaudación reciente');
check(($cashContext['cash_route_summary']['pending_vault_bags_count'] ?? null) === 1, 'la ruta recibe el KPI de bolsas pendientes');

[$serviceWithoutCash] = buildService(null, false);
$emptyContext = $serviceWithoutCash->routeCashContext(7);
check($emptyContext['recent_cash_collections'] === [], 'sin servicio de recaudación la ruta no falla');
check($emptyContext['cash_route_summary'] === [], 'sin servicio de recaudación el KPI queda vacío');

echo "MachineRestockVisitServiceTest: OK · Total Assertions: {$GLOBALS['assertions']}\n";
exit(0);
