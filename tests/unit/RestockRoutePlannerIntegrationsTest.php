<?php

declare(strict_types=1);

/**
 * RestockRoutePlannerIntegrationsTest · T-RRP-08
 *
 * Certifica las integraciones transversales del Módulo 37:
 *
 *   1. `ProductLoadingOrderService::TODAY_ROUTE` (M33): la propuesta individual
 *      y el lote reclaman todos los huecos llenables exclusivamente de las
 *      máquinas `PENDING` de la ruta efectiva, omitiendo con `NO_DEMAND` cuando
 *      no queda ninguna parada.
 *   2. `MachineRestockVisitService` (M34): enlace automático/validado de
 *      `route_schedule_id` (incluidas versiones cerradas para visitas offline),
 *      transición `SKIPPED -> SUPERSEDED` y restitución `EXPIRED -> 'OK'`.
 *   3. `RestockRouteReassignmentRevoker` (M21/M31): revocación automática de los
 *      desvíos `REASSIGNED` del receptor al cerrar turno, ausentarse o causar baja.
 *
 * Dogma Vanilla: PHP 8.2+ puro, dobles en memoria y cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminStaffService;
use VendGuard\Application\Service\AdminUserService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineRestockVisitService;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Application\Service\RestockRoutePlannerService;
use VendGuard\Application\Service\RestockRouteReassignmentRevoker;
use VendGuard\Application\Service\RestockRouteScheduleService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Core\Domain\Enum\RestockOverrideStatus;
use VendGuard\Core\Domain\Enum\RestockOverrideType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\CashCollectionRegistrarInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\InSituRestockVisitGatewayInterface;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\ProductLoadingOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;
use VendGuard\Core\Service\RestockCadenceEvaluator;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * @return MachineRestockVisitValidationException
 */
function rrpIntExpectVisitFailure(callable $operation, string $expectedCode): MachineRestockVisitValidationException
{
    try {
        $operation();
    } catch (MachineRestockVisitValidationException $exception) {
        if ($exception->getErrorCode() !== $expectedCode) {
            fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y llegó {$exception->getErrorCode()}.\n");
            exit(1);
        }

        return $exception;
    }

    fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y no se lanzó ninguna excepción.\n");
    exit(1);
}

final class RrpIntTransactions implements TransactionManagerInterface
{
    public function runInTransaction(callable $operation): mixed
    {
        return $operation();
    }
}

final class RrpIntAuditRepository implements AuditLogRepositoryInterface
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

final class RrpIntRouteReadModel implements RestockRouteReadModelInterface
{
    /** @var list<array<string, mixed>> */
    public array $machines = [];

    /** @var list<array<string, mixed>> */
    public array $restockers = [];

    /** @var array<int, MachineRestockSchedule> */
    public array $schedules = [];

    public function loadParkSnapshot(string $serviceDate): array
    {
        return [
            'machines' => $this->machines,
            'restockers' => $this->restockers,
            'overrides' => [],
        ];
    }

    public function loadMachineFacts(int $machineId, string $serviceDate): ?array
    {
        foreach ($this->machines as $machine) {
            if ((int)$machine['id'] === $machineId) {
                return $machine;
            }
        }

        return null;
    }

    public function listRestockers(string $serviceDate): array
    {
        return $this->restockers;
    }

    public function findRestocker(int $restockerId): ?array
    {
        foreach ($this->restockers as $restocker) {
            if ((int)$restocker['id'] === $restockerId) {
                return $restocker;
            }
        }

        return null;
    }

    public function findScheduleById(int $scheduleId): ?MachineRestockSchedule
    {
        return $this->schedules[$scheduleId] ?? null;
    }

    public function findOverrideById(int $overrideId): ?RestockRouteDailyOverride
    {
        return null;
    }
}

final class RrpIntRouteGateway implements RestockRoutePlannerGatewayInterface
{
    /** @var array<int, MachineRestockSchedule> */
    public array $activeSchedules = [];

    /** @var array<int, MachineRestockSchedule> */
    public array $schedulesById = [];

    /** @var list<RestockRouteDailyOverride> */
    public array $reassignments = [];

    /** @var list<array<string, mixed>> */
    public array $supersededCalls = [];

    /** @var list<array<string, mixed>> */
    public array $restoreCalls = [];

    /** @var list<array<string, mixed>> */
    public array $revokeCalls = [];

    public int $supersedeCount = 1;

    public bool $restoreResult = true;

    public function lockMachine(int $machineId): ?array
    {
        return null;
    }

    public function findActiveSchedule(int $machineId): ?MachineRestockSchedule
    {
        return $this->activeSchedules[$machineId] ?? null;
    }

    public function listActiveSchedules(): array
    {
        return $this->activeSchedules;
    }

    public function saveScheduleVersion(MachineRestockSchedule $schedule): int
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function deactivateSchedule(int $scheduleId, int $actorId, string $deactivatedAt): bool
    {
        if (!isset($this->schedulesById[$scheduleId]) || !$this->schedulesById[$scheduleId]->isActive()) {
            return false;
        }

        $closed = $this->schedulesById[$scheduleId]->deactivate($actorId, $deactivatedAt);
        $this->schedulesById[$scheduleId] = $closed;
        unset($this->activeSchedules[$closed->getMachineId()]);

        return true;
    }

    public function findActiveOverride(int $machineId, string $serviceDate): ?RestockRouteDailyOverride
    {
        return null;
    }

    public function findOverrideByClientMutationId(string $clientMutationId): ?RestockRouteDailyOverride
    {
        return null;
    }

    public function insertOverride(RestockRouteDailyOverride $override): int
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function revokeActiveOverrides(
        int $machineId,
        string $serviceDate,
        int $resolverId,
        string $resolvedAt
    ): int {
        $this->revokeCalls[] = [
            'machine_id' => $machineId,
            'service_date' => $serviceDate,
            'resolver_id' => $resolverId,
            'resolved_at' => $resolvedAt,
        ];

        return 1;
    }

    public function supersedeSkippedOverrides(
        int $machineId,
        string $serviceDate,
        int $visitId,
        ?int $resolverId,
        string $resolvedAt
    ): int {
        $this->supersededCalls[] = [
            'machine_id' => $machineId,
            'service_date' => $serviceDate,
            'visit_id' => $visitId,
            'resolver_id' => $resolverId,
            'resolved_at' => $resolvedAt,
        ];

        return $this->supersedeCount;
    }

    public function markMachineSanitaryExpired(int $machineId): bool
    {
        return false;
    }

    public function restoreMachineSanitaryOkIfClear(int $machineId, string $serviceDate): bool
    {
        $this->restoreCalls[] = ['machine_id' => $machineId, 'service_date' => $serviceDate];

        return $this->restoreResult;
    }

    public function findActiveReassignmentsForRestocker(int $restockerId, string $serviceDate): array
    {
        return array_values(array_filter(
            $this->reassignments,
            static fn (RestockRouteDailyOverride $override): bool => $override->getTargetRestockerId() === $restockerId
                && $override->getServiceDate() === $serviceDate
                && $override->getStatus() === RestockOverrideStatus::ACTIVE
                && $override->getOverrideType() === RestockOverrideType::REASSIGNED
        ));
    }

    public function enqueueFefoSkipAlert(
        int $machineId,
        string $serviceDate,
        int $overrideId,
        string $recipient,
        string $channel = 'EMAIL',
        array $extraPayload = []
    ): int {
        return 1;
    }

    public function attachNotificationToOverride(int $overrideId, int $notificationId): bool
    {
        return true;
    }

    public function findOfflineMutation(int $restockerId, string $clientMutationId): ?array
    {
        return null;
    }

    public function recordOfflineMutation(
        int $restockerId,
        string $clientMutationId,
        int $machineId,
        string $clientOccurredAt,
        array $responsePayload,
        int $httpStatus = 200
    ): int {
        return 1;
    }
}

final class RrpIntProductLoadingRepository implements ProductLoadingOrderRepositoryInterface
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $demandByMachine = [];

    /** @var array<string, mixed>|null */
    public ?array $restocker = null;

    /** @var list<int>|null */
    public ?array $lastMachineIds = null;

    /** @var array<int, array{order: ProductLoadingOrder, items: list<array<string, mixed>>}> */
    public array $orders = [];

    private int $nextOrderId = 1;

    public function fetchProposalInputs(
        int $restockerId,
        ?DateTimeImmutable $reference = null,
        ?array $machineIds = null
    ): array {
        $this->lastMachineIds = $machineIds;

        $selected = $machineIds ?? array_keys($this->demandByMachine);
        $aggregated = [];
        foreach ($selected as $machineId) {
            foreach ($this->demandByMachine[(int)$machineId] ?? [] as $row) {
                $productId = (int)$row['product_id'];
                if (!isset($aggregated[$productId])) {
                    $aggregated[$productId] = $row;
                    foreach ([
                        'gap_qty_all',
                        'gap_qty_alerts',
                        'gap_slots_count',
                        'stockout_slots_count',
                        'low_stock_slots_count',
                        'alert_slots_count',
                    ] as $key) {
                        $aggregated[$productId][$key] = 0;
                    }
                }

                foreach ([
                    'gap_qty_all',
                    'gap_qty_alerts',
                    'gap_slots_count',
                    'stockout_slots_count',
                    'low_stock_slots_count',
                    'alert_slots_count',
                ] as $key) {
                    $aggregated[$productId][$key] += (int)($row[$key] ?? 0);
                }
            }
        }

        return [
            'restocker' => $this->restocker,
            'zones' => [],
            'slot_demand' => array_values($aggregated),
            'excluded_unavailable_machines_count' => 0,
        ];
    }

    public function createOrder(ProductLoadingOrder $order, array $items): array
    {
        $orderId = $this->nextOrderId++;
        $persisted = $order->withId($orderId);
        $rows = [];
        foreach ($items as $index => $item) {
            $productId = (int)$item['product_id'];
            $rows[] = [
                'id' => $index + 1,
                'order_id' => $orderId,
                'product_id' => $productId,
                'reason' => (string)$item['reason'],
                'requested_qty' => (int)$item['requested_qty'],
                'is_active' => true,
                'product_sku' => 'SKU-' . $productId,
                'product_name' => 'Producto ' . $productId,
                'product_unit_type' => ProductUnitType::UNIT->value,
                'requires_cold_chain' => $productId === 1,
                'batch_code' => null,
                'expiration_date' => null,
                'dispatched_qty' => null,
                'reason_label' => 'Motivo',
            ];
        }

        $this->orders[$orderId] = ['order' => $persisted, 'items' => $rows];

        return ['order' => $persisted, 'items' => $rows];
    }

    public function findById(int $orderId, bool $forUpdate = false): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function findActiveByRestocker(int $restockerId, bool $forUpdate = false): ?ProductLoadingOrder
    {
        return null;
    }

    public function findForCoordinator(
        ?string $status = null,
        ?int $restockerId = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        return [];
    }

    public function summarizeStatusCounts(?DateTimeImmutable $reference = null): array
    {
        return [];
    }

    public function updateOrder(ProductLoadingOrder $order): ProductLoadingOrder
    {
        return $order;
    }

    public function replaceItems(int $orderId, array $items): array
    {
        return [];
    }

    public function updateItem(ProductLoadingOrderItem $item): ProductLoadingOrderItem
    {
        return $item;
    }

    public function findItem(int $orderId, int $productId, bool $forUpdate = false): ?ProductLoadingOrderItem
    {
        return null;
    }
}

final class RrpIntVanStock implements RestockerVanStockRepositoryInterface
{
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
        return [];
    }

    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock {
        return null;
    }

    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        return $stock;
    }

    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array
    {
        return [];
    }
}

final class RrpIntProductRepository implements VendingProductRepositoryInterface
{
    public function create(VendingProduct $product): VendingProduct
    {
        throw new LogicException('No utilizado por esta suite.');
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
        return new VendingProduct(
            $id,
            'SKU-' . $id,
            'Producto ' . $id,
            ProductCategory::COLD_DRINK,
            ProductUnitType::UNIT,
            1.0,
            2.0,
            $id === 1,
            30,
            true
        );
    }

    public function findBySku(string $sku): ?VendingProduct
    {
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
        return [];
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

final class RrpIntUserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        return null;
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        if ($id !== 7 && $id !== 8) {
            return null;
        }

        return new User(
            $id,
            $id === 7 ? 'Reponedor Siete' : 'Reponedor Ocho',
            'rep' . $id . '@vendguard.test',
            'hash',
            UserRole::RESTOCKER,
            null,
            true,
            null,
            null,
            null,
            false,
            null,
            'REP-0' . $id
        );
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        return [];
    }

    public function create(array $data): User
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function update(int $id, array $data): bool
    {
        return false;
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return true;
    }

    public function restore(int $id): bool
    {
        return false;
    }

    public function findAll(array $filters = []): array
    {
        return [['id' => 7, 'name' => 'Reponedor Siete']];
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        return 5;
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        return 0;
    }

    public function countPendingIncidents(int $technicianId): int
    {
        return 0;
    }
}

final class RrpIntVisitRepository implements MachineRestockVisitRepositoryInterface
{
    /** @var array<int, MachineRestockVisit> */
    public array $visits = [];

    private int $nextId = 1;

    public function insertVisit(MachineRestockVisit $visit): MachineRestockVisit
    {
        $persisted = $visit->withId($this->nextId++);
        $this->visits[(int)$persisted->getId()] = $persisted;

        return $persisted;
    }

    public function insertItem(MachineRestockVisitItem $item): MachineRestockVisitItem
    {
        return $item->withId(1);
    }

    public function findById(int $visitId, bool $includeCosts = false): ?array
    {
        $visit = $this->visits[$visitId] ?? null;
        if ($visit === null) {
            return null;
        }

        return [
            'id' => $visit->getId(),
            'visit_code' => $visit->getVisitCode(),
            'status' => $visit->getStatus()->value,
            'machine_id' => $visit->getMachineId(),
            'restocker_id' => $visit->getRestockerId(),
            'route_schedule_id' => $visit->getRouteScheduleId(),
            'loading_order_id' => $visit->getLoadingOrderId(),
            'triggered_incident_id' => $visit->getTriggeredIncidentId(),
            'items' => [],
        ];
    }

    public function findByClientMutationId(
        int $restockerId,
        string $clientMutationId,
        bool $includeCosts = false
    ): ?array {
        return null;
    }

    public function listForRestocker(int $restockerId, array $filters = []): array
    {
        return [];
    }

    public function listForCoordinator(array $filters = []): array
    {
        return ['visits' => [], 'kpis' => []];
    }

    public function suggestLiquidationQuantities(int $loadingOrderId, int $restockerId): array
    {
        return [];
    }
}

final class RrpIntPlanogramRepository implements MachinePlanogramRepositoryInterface
{
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
        return [];
    }

    public function replaceMachinePlanogram(int $machineId, array $slots): array
    {
        return [];
    }

    public function deactivateSlot(int $machineId, string $slotCode): bool
    {
        return false;
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
        return [];
    }
}

final class RrpIntInSituGateway implements InSituRestockVisitGatewayInterface
{
    /** @var array<string, mixed>|null */
    public ?array $context = null;

    /** @var list<MachinePlanogramSlot> */
    public array $slots = [];

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
        return 1.0;
    }

    public function lockMachineSanitarily(int $machineId, MachineType $machineType, string $reason): void
    {
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
        return 77;
    }
}

final class RrpIntShiftRepository implements TechnicianShiftRepositoryInterface
{
    /** @var array<int, TechnicianShift> */
    public array $storage = [];

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        foreach ($this->storage as $shift) {
            if ($shift->technicianId() === $technicianId && $shift->isOpen()) {
                return $shift;
            }
        }

        return null;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return $this->storage[$shiftId] ?? null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        $closed = $this->storage[$shiftId]->close($closedBy, $closeReason, $closedAt, $isForceClosed, $executionTime);
        $this->storage[$shiftId] = $closed;

        return $closed;
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        return $adjustedShift;
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        return null;
    }

    public function findShiftsByDateRange(
        string $fromDate,
        string $toDate,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return array_values($this->storage);
    }

    public function findOrphanOpenShiftsBefore(
        string $beforeDate,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return [];
    }

    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return [];
    }

    public function getActiveShiftsMap(?string $role = null): array
    {
        return [];
    }
}

final class RrpIntAbsenceRepository implements TechnicianAbsenceRepositoryInterface
{
    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence {
        return TechnicianAbsence::create($technicianId, $reason, $startsAt, $endsAt, $notes, $createdBy);
    }

    public function findById(int $id): ?TechnicianAbsence
    {
        return null;
    }

    public function findAll(?int $technicianId = null, ?string $status = null, ?string $role = null): array
    {
        return [];
    }

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence
    {
        return null;
    }

    public function findActiveAbsencesMap(DateTimeImmutable $at): array
    {
        return [];
    }

    public function hasOverlappingAbsence(
        int $technicianId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?int $excludeAbsenceId = null
    ): bool {
        return false;
    }

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool
    {
        return false;
    }
}

final class RrpIntLockRepository implements TechnicianLockRepositoryInterface
{
    public function lockTechnicianForUpdate(int $technicianId): bool
    {
        return true;
    }
}

final class RrpIntIncidentRepository implements IncidentRepositoryInterface
{
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function findById(int $id): ?Incident
    {
        return null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
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

    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array
    {
        return [];
    }

    public function findAll(array $filters = []): array
    {
        return [];
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [];
    }

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        return null;
    }

    public function update(Incident $incident): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return false;
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

    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array
    {
        return [];
    }

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        return 0;
    }

    public function countCommentsForIncidents(array $incidentIds, bool $includeInternal): array
    {
        return [];
    }

    public function countReopenEvents(int $incidentId): int
    {
        return 0;
    }

    public function markAsChronic(int $incidentId): bool
    {
        return false;
    }

    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): Incident {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function reopen(int $incidentId, string $reasonText): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }

    public function recordPauseEvent(
        int $incidentId,
        int $userId,
        IncidentStatus $fromStatus,
        IncidentPauseReasonCategory $category,
        string $reasonText,
        DateTimeImmutable $pausedAt
    ): void {
    }

    public function recordResumeEvent(
        int $incidentId,
        ?int $userId,
        IncidentStatus $targetStatus,
        int $pauseDurationSeconds,
        ?string $note,
        DateTimeImmutable $resumedAt
    ): void {
    }

    public function getPendingInfoIncidentsOlderThanHours(int $hours): array
    {
        return [];
    }
}

/**
 * @return array<string, mixed>
 */
function rrpIntScheduleRow(int $id, int $machineId, bool $active = true): array
{
    return [
        'id' => $id,
        'machine_id' => $machineId,
        'cadence_type' => 'DAILY',
        'day_of_week' => null,
        'anchor_date' => null,
        'preferred_time_window' => 'MORNING',
        'include_weekends' => false,
        'notes' => null,
        'is_active' => $active,
        'created_by' => 1,
        'updated_by' => 1,
        'deactivated_by' => $active ? null : 1,
        'created_at' => '2026-11-01T08:00:00+01:00',
        'updated_at' => '2026-11-01T08:00:00+01:00',
        'deactivated_at' => $active ? null : '2026-11-20T10:00:00+01:00',
    ];
}

/**
 * @param array<string, mixed>|null $visit
 *
 * @return array<string, mixed>
 */
function rrpIntMachineRow(
    int $id,
    string $code,
    ?array $schedule,
    int $emptySlots = 1,
    ?array $visit = null,
    string $sanitaryStatus = 'OK'
): array {
    return [
        'id' => $id,
        'machine_code' => $code,
        'machine_type' => 'PERISHABLE_FOOD',
        'location_id' => 10 + $id,
        'location_name' => 'Sede ' . $id,
        'address' => 'Calle ' . $id,
        'zone_id' => 1,
        'zone_name' => 'Zona 1',
        'zone_active' => true,
        'sla_tier' => 'STANDARD',
        'latitude' => 40.4 + ($id / 1000),
        'longitude' => -3.7 + ($id / 1000),
        'sanitary_status' => $sanitaryStatus,
        'is_blocked_no_access' => false,
        'is_seasonal_pause' => false,
        'seasonal_pause_until' => null,
        'is_active' => true,
        'location_active' => true,
        'schedule' => $schedule,
        'earliest_fefo_expiration_date' => null,
        'has_expired_cold_batch' => false,
        'empty_slots_count' => $emptySlots,
        'active_l1_incident' => null,
        'has_open_thermal_breakdown' => false,
        'visit_today' => $visit,
        'active_override' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function rrpIntDemandRow(int $productId, int $gapAll, int $gapAlerts, int $stockouts = 0, int $lowStock = 0): array
{
    return [
        'product_id' => $productId,
        'product_sku' => 'SKU-' . $productId,
        'product_name' => 'Producto ' . $productId,
        'product_category' => 'COLD_DRINK',
        'product_category_label' => 'Bebida fría',
        'product_unit_type' => 'UNIT',
        'requires_cold_chain' => $productId === 1,
        'max_shelf_life_days' => 30,
        'gap_qty_all' => $gapAll,
        'gap_qty_alerts' => $gapAlerts,
        'gap_slots_count' => $stockouts + $lowStock,
        'stockout_slots_count' => $stockouts,
        'low_stock_slots_count' => $lowStock,
        'alert_slots_count' => $stockouts + $lowStock,
    ];
}

/**
 * @param array<string, mixed>|null $visit
 *
 * @return array<string, mixed>
 */
function rrpIntRestockerRow(int $id = 7, string $shiftStatus = 'ON_SHIFT', bool $absent = false): array
{
    return [
        'id' => $id,
        'name' => 'Reponedor ' . $id,
        'operator_code' => 'REP-0' . $id,
        'is_active' => true,
        'has_absence' => $absent,
        'shift_status' => $shiftStatus,
        'is_primary' => true,
        'zone_ids' => [1],
    ];
}

/**
 * @return RestockRouteDailyOverride
 */
function rrpIntReassignment(int $id, int $machineId, int $targetRestockerId, string $serviceDate): RestockRouteDailyOverride
{
    return new RestockRouteDailyOverride(
        $id,
        $machineId,
        $serviceDate,
        RestockOverrideType::REASSIGNED,
        RestockOverrideStatus::ACTIVE,
        3,
        $targetRestockerId,
        null,
        RestockReassignmentReasonCode::ZONE_UNCOVERED,
        'Cobertura puntual por ausencia del titular.',
        false,
        null,
        null,
        null,
        3,
        null,
        null,
        null,
        null
    );
}

echo "======================================================================\n";
echo " VendGuard · T-RRP-08 · Integraciones del Planificador de Rutas\n";
echo "======================================================================\n";

$reference = new DateTimeImmutable('2026-11-20 08:00:00', new DateTimeZone('Europe/Madrid'));
$serviceDate = RestockCadenceEvaluator::today($reference);
$today = RestockCadenceEvaluator::today(new DateTimeImmutable('now'));

// =====================================================================
// 1 · M33 · TODAY_ROUTE restringe la propuesta a las paradas PENDING
// =====================================================================
echo "\n[1/3] M33 · ProductLoadingOrderService::TODAY_ROUTE\n";

$readModel = new RrpIntRouteReadModel();
$readModel->restockers = [rrpIntRestockerRow()];
$readModel->machines = [
    rrpIntMachineRow(1, 'VEND-01', rrpIntScheduleRow(11, 1)),
    rrpIntMachineRow(2, 'VEND-02', rrpIntScheduleRow(12, 2), 0, [
        'id' => 901,
        'status' => 'COMPLETED',
        'restocker_id' => 7,
    ]),
    rrpIntMachineRow(3, 'VEND-03', rrpIntScheduleRow(13, 3), 1, null, 'QUARANTINE'),
];

$orderRepo = new RrpIntProductLoadingRepository();
$orderRepo->restocker = [
    'id' => 7,
    'name' => 'Reponedor Siete',
    'operator_code' => 'REP-07',
    'is_active' => true,
    'is_currently_absent' => false,
    'open_order_id' => null,
    'open_order_status' => null,
];
$orderRepo->demandByMachine = [
    1 => [rrpIntDemandRow(1, 4, 4, 1), rrpIntDemandRow(2, 2, 0)],
    2 => [rrpIntDemandRow(1, 10, 10, 2)],
    3 => [rrpIntDemandRow(1, 7, 7, 2)],
];

$auditRepo = new RrpIntAuditRepository();
$service = new ProductLoadingOrderService(
    $orderRepo,
    new RrpIntVanStock(),
    new RrpIntProductRepository(),
    new RrpIntUserRepository(),
    new AuditLogger($auditRepo),
    null,
    null,
    null,
    new RestockRoutePlannerService($readModel)
);

$proposal = $service->buildProposal(7, 'TODAY_ROUTE', 0, $reference);
$byProduct = [];
foreach ($proposal['items'] as $item) {
    $byProduct[(int)$item['product_id']] = $item;
}

$assert(
    '1.1 TODAY_ROUTE limita la foto a la máquina PENDING de la ruta',
    $orderRepo->lastMachineIds === [1],
    'machine_ids=' . json_encode($orderRepo->lastMachineIds)
);
$assert(
    '1.2 La máquina completada y la bloqueada no aportan demanda',
    count($proposal['items']) === 2 && isset($byProduct[1], $byProduct[2])
);
$assert(
    '1.3 TODAY_ROUTE reclama todos los huecos llenables (estilo ALL_GAPS)',
    (int)$byProduct[1]['gap_qty'] === 4 && (int)$byProduct[2]['gap_qty'] === 2,
    json_encode(['p1' => $byProduct[1]['gap_qty'] ?? null, 'p2' => $byProduct[2]['gap_qty'] ?? null])
);
$assert(
    '1.4 El stockout conserva su motivo y el hueco sin alerta es ROUTE_GAP_FILL',
    $byProduct[1]['reason'] === 'STOCKOUT_ALERT' && $byProduct[2]['reason'] === 'ROUTE_GAP_FILL'
);

$alerts = $service->buildProposal(7, 'ALERTS_ONLY', 0, $reference);
$alertsByProduct = [];
foreach ($alerts['items'] as $item) {
    $alertsByProduct[(int)$item['product_id']] = $item;
}
$assert(
    '1.5 ALERTS_ONLY conserva el alcance de flota y su gap de alertas',
    $orderRepo->lastMachineIds === null && (int)$alertsByProduct[1]['gap_qty'] === 21,
    json_encode(['machine_ids' => $orderRepo->lastMachineIds, 'gap' => $alertsByProduct[1]['gap_qty'] ?? null])
);

$batch = $service->createBatchOrders(['demand_mode' => 'TODAY_ROUTE', 'safety_buffer_pct' => 0], [
    'id' => 3,
    'role' => 'COORDINATOR',
    'name' => 'Coordinación',
]);
$assert(
    '1.6 El lote TODAY_ROUTE crea una orden con la demanda de la ruta',
    count($batch['created']) === 1 && (int)$batch['created'][0]['items_count'] === 2,
    json_encode($batch)
);
$assert(
    '1.7 La orden creada conserva el modo TODAY_ROUTE',
    $orderRepo->orders[1]['order']->getDemandMode()->value === 'TODAY_ROUTE'
);

$readModel->machines[0] = rrpIntMachineRow(1, 'VEND-01', rrpIntScheduleRow(11, 1), 0, [
    'id' => 902,
    'status' => 'COMPLETED',
    'restocker_id' => 7,
]);
$noRouteBatch = $service->createBatchOrders(['demand_mode' => 'TODAY_ROUTE'], [
    'id' => 3,
    'role' => 'COORDINATOR',
    'name' => 'Coordinación',
]);
$assert(
    '1.8 Sin paradas pendientes el lote omite con NO_DEMAND',
    count($noRouteBatch['created']) === 0
        && ($noRouteBatch['skipped'][0]['reason'] ?? null) === ProductLoadingOrderService::SKIP_NO_DEMAND,
    json_encode($noRouteBatch)
);

$readModel->machines[0] = rrpIntMachineRow(1, 'VEND-01', rrpIntScheduleRow(11, 1));
$assert(
    '1.9 La máquina bloqueada por cuarentena no entra en la ruta efectiva',
    !in_array(3, array_map(
        static fn (array $stop): int => (int)$stop['machines'][0]['machine_id'],
        (new RestockRoutePlannerService($readModel))->buildRestockerRoute(7, $serviceDate, null, $reference)['route_plan']['stops']
    ), true)
);

// =====================================================================
// 2 · M34 · route_schedule_id, SUPERSEDED y EXPIRED -> OK
// =====================================================================
echo "\n[2/3] M34 · MachineRestockVisitService\n";

$routeGateway = new RrpIntRouteGateway();
$routeGateway->activeSchedules[1] = MachineRestockSchedule::fromDatabaseRow(rrpIntScheduleRow(55, 1));

$routeReadModel = new RrpIntRouteReadModel();
$routeReadModel->schedules[66] = MachineRestockSchedule::fromDatabaseRow(rrpIntScheduleRow(66, 1, false));
$routeReadModel->schedules[67] = MachineRestockSchedule::fromDatabaseRow(rrpIntScheduleRow(67, 2, true));

$inSitu = new RrpIntInSituGateway();
$inSitu->context = [
    'id' => 1,
    'machine_code' => 'VEND-01',
    'machine_type' => MachineType::PERISHABLE_FOOD,
    'location_id' => 11,
    'sanitary_status' => 'EXPIRED',
    'is_out_of_service' => false,
    'is_covered' => true,
    'has_open_thermal_breakdown' => false,
    'active_incident_id' => null,
    'loading_order_id' => null,
];
$inSitu->slots = [
    new MachinePlanogramSlot(101, 1, '11', 1, 20, 5, 2, true, null, null, null, null),
];

$visits = new RrpIntVisitRepository();
$visitAudit = new RrpIntAuditRepository();
$visitService = new MachineRestockVisitService(
    $visits,
    new RrpIntPlanogramRepository(),
    new RrpIntVanStock(),
    $inSitu,
    new RrpIntTransactions(),
    new AuditLogger($visitAudit),
    null,
    null,
    $routeGateway,
    $routeReadModel
);

$completedPayload = [
    'status' => 'COMPLETED',
    'client_mutation_id' => 'rrp-int-completed-01',
    'cabin_temp_celsius' => 3.2,
    'hygiene_checklist_json' => [
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ],
    'items' => [],
];

$visitService->registerVisit(1, $completedPayload, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
$persistedVisit = $visits->visits[1];

$assert(
    '2.1 Sin route_schedule_id se vincula la agenda viva de la máquina',
    $persistedVisit->getRouteScheduleId() === 55,
    'route_schedule_id=' . json_encode($persistedVisit->getRouteScheduleId())
);
$assert(
    '2.2 La visita completada supera la omisión viva del día',
    count($routeGateway->supersededCalls) === 1
        && $routeGateway->supersededCalls[0]['machine_id'] === 1
        && $routeGateway->supersededCalls[0]['visit_id'] === 1
        && $routeGateway->supersededCalls[0]['resolver_id'] === 7
        && $routeGateway->supersededCalls[0]['service_date'] === $today,
    json_encode($routeGateway->supersededCalls)
);
$assert(
    '2.3 La visita sella EXPIRED -> OK solo cuando no quedan lotes vencidos',
    count($routeGateway->restoreCalls) === 1 && $routeGateway->restoreCalls[0]['machine_id'] === 1,
    json_encode($routeGateway->restoreCalls)
);
$assert(
    '2.4 La auditoría de la visita registra el enlace y la superación',
    ($visitAudit->events[0]->getNewState()['route_schedule_id'] ?? null) === 55
        && ($visitAudit->events[0]->getNewState()['superseded_overrides_count'] ?? null) === 1
        && ($visitAudit->events[0]->getNewState()['sanitary_status_restored'] ?? null) === true,
    json_encode($visitAudit->events[0]->getNewState())
);

$closedVersionVisits = new RrpIntVisitRepository();
$closedVersionService = new MachineRestockVisitService(
    $closedVersionVisits,
    new RrpIntPlanogramRepository(),
    new RrpIntVanStock(),
    $inSitu,
    new RrpIntTransactions(),
    new AuditLogger(new RrpIntAuditRepository()),
    null,
    null,
    $routeGateway,
    $routeReadModel
);
$closedVersionService->registerVisit(1, [
    'status' => 'COMPLETED',
    'client_mutation_id' => 'rrp-int-offline-01',
    'cabin_temp_celsius' => 3.2,
    'route_schedule_id' => 66,
    'hygiene_checklist_json' => [
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ],
    'items' => [],
], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
$assert(
    '2.5 Una visita offline admite la versión cerrada de su propia máquina',
    $closedVersionVisits->visits[1]->getRouteScheduleId() === 66,
    'route_schedule_id=' . json_encode($closedVersionVisits->visits[1]->getRouteScheduleId())
);

$wrongMachineService = new MachineRestockVisitService(
    new RrpIntVisitRepository(),
    new RrpIntPlanogramRepository(),
    new RrpIntVanStock(),
    $inSitu,
    new RrpIntTransactions(),
    new AuditLogger(new RrpIntAuditRepository()),
    null,
    null,
    $routeGateway,
    $routeReadModel
);
rrpIntExpectVisitFailure(
    static fn () => $wrongMachineService->registerVisit(1, [
        'status' => 'COMPLETED',
        'client_mutation_id' => 'rrp-int-wrong-01',
        'cabin_temp_celsius' => 3.2,
        'route_schedule_id' => 67,
        'hygiene_checklist_json' => [
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [],
    ], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']),
    MachineRestockVisitValidationException::INVALID_ROUTE_SCHEDULE
);
$assert('2.6 La agenda de otra máquina se rechaza con INVALID_ROUTE_SCHEDULE', true);

$midDaySchedule = MachineRestockSchedule::fromDatabaseRow(rrpIntScheduleRow(88, 1, true));
$midDayScheduleGateway = new RrpIntRouteGateway();
$midDayScheduleGateway->schedulesById[88] = $midDaySchedule;
$midDayScheduleGateway->activeSchedules[1] = $midDaySchedule;
$midDayReadModel = new RrpIntRouteReadModel();
$midDayReadModel->restockers = [rrpIntRestockerRow()];
$midDayReadModel->schedules[88] = $midDaySchedule;
$midDayScheduleService = new RestockRouteScheduleService(
    $midDayScheduleGateway,
    $midDayReadModel,
    new AuditLogger(new RrpIntAuditRepository()),
    new RrpIntTransactions()
);
$midDayScheduleService->deactivate(88, ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación'], $reference);
$midDayReadModel->schedules[88] = $midDayScheduleGateway->schedulesById[88];
$midDayReadModel->machines = [rrpIntMachineRow(1, 'VEND-01', null, 2)];
$midDayRoute = (new RestockRoutePlannerService($midDayReadModel))->buildRestockerRoute(7, $serviceDate, null, $reference);
$midDayTriggers = $midDayRoute['route_plan']['stops'][0]['machines'][0]['triggers'] ?? [];
$assert(
    '2.7 Tras la baja lógica de la agenda, la ruta elimina SCHEDULED pero conserva EMPTY_SLOT',
    in_array('EMPTY_SLOT', $midDayTriggers, true) && !in_array('SCHEDULED', $midDayTriggers, true),
    json_encode($midDayTriggers)
);

$midDayVisits = new RrpIntVisitRepository();
$midDayVisitService = new MachineRestockVisitService(
    $midDayVisits,
    new RrpIntPlanogramRepository(),
    new RrpIntVanStock(),
    $inSitu,
    new RrpIntTransactions(),
    new AuditLogger(new RrpIntAuditRepository()),
    null,
    null,
    $midDayScheduleGateway,
    $midDayReadModel
);
$midDayVisitService->registerVisit(1, [
    'status' => 'COMPLETED',
    'client_mutation_id' => 'rrp-int-midday-offline-01',
    'cabin_temp_celsius' => 3.5,
    'route_schedule_id' => 88,
    'hygiene_checklist_json' => [
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ],
    'items' => [],
], ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
$assert(
    '2.8 La visita offline capturada antes de la baja enlaza la versión cerrada recién desactivada',
    $midDayVisits->visits[1]->getRouteScheduleId() === 88
);

// =====================================================================
// 3 · M21/M31 · revocación automática de REASSIGNED
// =====================================================================
echo "\n[3/3] M21/M31 · RestockRouteReassignmentRevoker\n";

$revokerGateway = new RrpIntRouteGateway();
$revokerGateway->reassignments = [
    rrpIntReassignment(701, 10, 7, $serviceDate),
    rrpIntReassignment(702, 11, 7, $serviceDate),
    rrpIntReassignment(703, 12, 8, $serviceDate),
];
$revokerAudit = new RrpIntAuditRepository();
$revoker = new RestockRouteReassignmentRevoker($revokerGateway, new AuditLogger($revokerAudit));

$revoked = $revoker->revokeForRestocker(7, RestockRouteReassignmentRevoker::CAUSE_SHIFT_CLOSE, 7, $reference);
$assert(
    '3.1 El cierre de turno revoca los dos desvíos del receptor',
    $revoked === 2 && count($revokerGateway->revokeCalls) === 2,
    json_encode(['revoked' => $revoked, 'calls' => $revokerGateway->revokeCalls])
);
$assert(
    '3.2 La revocación no toca los desvíos de otro reponedor',
    array_column($revokerGateway->revokeCalls, 'machine_id') === [10, 11]
);
$assert(
    '3.3 Cada revocación firma auditoría bajo RESTOCK_ROUTE_DAILY_OVERRIDE',
    count($revokerAudit->events) === 2
        && $revokerAudit->events[0]->getEntityType() === AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE
        && $revokerAudit->events[0]->getAction() === RestockRouteReassignmentRevoker::AUDIT_ACTION
        && ($revokerAudit->events[0]->getMetadata()['cause'] ?? null) === RestockRouteReassignmentRevoker::CAUSE_SHIFT_CLOSE
);

$shiftGateway = new RrpIntRouteGateway();
$shiftGateway->reassignments = [rrpIntReassignment(711, 20, 7, $today)];
$shiftRepo = new RrpIntShiftRepository();
$shiftRepo->storage[1] = new TechnicianShift(
    id: 1,
    technicianId: 7,
    status: ShiftStatus::ON_SHIFT,
    startedAt: new DateTimeImmutable('-2 hours')
);
$shiftAudit = new RrpIntAuditRepository();
$shiftService = new ShiftLifecycleService(
    $shiftRepo,
    new RrpIntAbsenceRepository(),
    new RrpIntUserRepository(),
    $shiftAudit,
    new RrpIntTransactions(),
    new RrpIntLockRepository(),
    null,
    null,
    new RestockRouteReassignmentRevoker($shiftGateway, new AuditLogger($shiftAudit))
);
$shiftService->closeShift(7, 'Fin de jornada de reposición');
$assert(
    '3.4 ShiftLifecycleService cierra el turno y revoca en la misma transacción',
    count($shiftGateway->revokeCalls) === 1
        && $shiftGateway->revokeCalls[0]['machine_id'] === 20
        && $shiftGateway->revokeCalls[0]['resolver_id'] === 7,
    json_encode($shiftGateway->revokeCalls)
);

$absenceGateway = new RrpIntRouteGateway();
$absenceGateway->reassignments = [rrpIntReassignment(721, 30, 7, $today)];
$absenceAudit = new RrpIntAuditRepository();
$absenceService = new TechnicianAbsenceService(
    new RrpIntAbsenceRepository(),
    new RrpIntUserRepository(),
    new RrpIntIncidentRepository(),
    $absenceAudit,
    new RrpIntTransactions(),
    new RrpIntLockRepository(),
    null,
    null,
    null,
    new RestockRouteReassignmentRevoker($absenceGateway, new AuditLogger($absenceAudit))
);
$absenceService->createAbsence(
    7,
    AbsenceReason::VACATION->value,
    (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
    (new DateTimeImmutable('+1 day'))->format(DATE_ATOM),
    null,
    3
);
$assert(
    '3.5 TechnicianAbsenceService revoca los desvíos de una ausencia vigente',
    count($absenceGateway->revokeCalls) === 1
        && $absenceGateway->revokeCalls[0]['machine_id'] === 30
        && $absenceGateway->revokeCalls[0]['resolver_id'] === 3,
    json_encode($absenceGateway->revokeCalls)
);

$staffGateway = new RrpIntRouteGateway();
$staffGateway->reassignments = [rrpIntReassignment(731, 40, 7, $today)];
$staffAudit = new RrpIntAuditRepository();
$staffService = new AdminStaffService(
    new AdminUserService(new RrpIntUserRepository(), new AuditLogger($staffAudit)),
    new RrpIntUserRepository(),
    new RrpIntShiftRepository(),
    new AuditLogger($staffAudit),
    null,
    new RrpIntTransactions(),
    null,
    new RestockRouteReassignmentRevoker($staffGateway, new AuditLogger($staffAudit))
);
$staffService->deactivateUser(7, ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);
$assert(
    '3.6 AdminStaffService revoca los desvíos al dar de baja al receptor',
    count($staffGateway->revokeCalls) === 1
        && $staffGateway->revokeCalls[0]['machine_id'] === 40
        && $staffGateway->revokeCalls[0]['resolver_id'] === 3,
    json_encode($staffGateway->revokeCalls)
);

echo "\n======================================================================\n";
echo sprintf(" Resultado: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
