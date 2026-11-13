<?php

declare(strict_types=1);

/**
 * ProductLoadingOrderServiceTest · T-PLD-08
 *
 * Certifica la composición de la propuesta matutina, la emisión individual y en
 * lote, la edición con baja lógica, la Hoja A4 sin importes, el despacho con
 * cerrojo de frío, la recepción móvil, la liquidación de cierre y la cancelación
 * (Módulo 33; RF-02–RF-04; D1–D4; C1–C4; QA 1–QA 4; Art. II, III.3, V.1, V.4).
 *
 * Uso:
 *   php tests/unit/ProductLoadingOrderServiceTest.php
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\ProductLoadingOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;

/**
 * Repositorio de órdenes en memoria con la semántica observable del puerto.
 */
final class FakeProductLoadingOrderRepositoryForServiceTest implements ProductLoadingOrderRepositoryInterface
{
    /** @var array<int, ProductLoadingOrder> */
    private array $orders = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $items = [];

    /** @var array<int, VendingProduct> */
    private array $products;

    private int $nextOrderId = 1;
    private int $nextItemId = 1000;

    /** @param array<int, VendingProduct> $products */
    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function createOrder(ProductLoadingOrder $order, array $items): array
    {
        if ($this->findActiveByRestocker($order->getRestockerId()) !== null) {
            throw new InvalidArgumentException(sprintf(
                'ACTIVE_LOADING_ORDER_EXISTS: el reponedor %d ya tiene una orden activa.',
                $order->getRestockerId()
            ));
        }

        $orderId = $this->nextOrderId++;
        $persisted = $order->withId($orderId);
        $this->orders[$orderId] = $persisted;
        $this->items[$orderId] = [];

        foreach ($items as $draft) {
            $this->items[$orderId][(int)$draft['product_id']] = $this->newItemRow(
                $orderId,
                (int)$draft['product_id'],
                (string)$draft['reason'],
                (int)$draft['requested_qty']
            );
        }

        return ['order' => $persisted, 'items' => $this->sortedItems($orderId)];
    }

    public function findById(int $orderId, bool $forUpdate = false): ?array
    {
        if (!isset($this->orders[$orderId])) {
            return null;
        }

        return ['order' => $this->orders[$orderId], 'items' => $this->sortedItems($orderId)];
    }

    public function findActiveByRestocker(int $restockerId, bool $forUpdate = false): ?ProductLoadingOrder
    {
        foreach ($this->orders as $order) {
            if ($order->getRestockerId() === $restockerId && $order->isActive()) {
                return $order;
            }
        }

        return null;
    }

    public function findForCoordinator(
        ?string $status = null,
        ?int $restockerId = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $rows = [];
        foreach ($this->orders as $order) {
            if ($status !== null && $order->getStatus()->value !== strtoupper($status)) {
                continue;
            }
            if ($restockerId !== null && $order->getRestockerId() !== $restockerId) {
                continue;
            }

            $activeItems = array_values(array_filter(
                $this->items[$order->getId()],
                static fn (array $item): bool => (bool)$item['is_active']
            ));

            $rows[] = array_merge($order->toArray(), [
                'restocker_name' => $order->getRestockerId() === 7 ? 'Reponedor Siete' : 'Reponedor Ocho',
                'restocker_operator_code' => $order->getRestockerId() === 7 ? 'REP-07' : 'REP-08',
                'restocker_is_active' => $order->getRestockerId() !== 9,
                'restocker_currently_absent' => $order->getRestockerId() === 8,
                'active_items_count' => count($activeItems),
                'total_requested_qty' => array_sum(array_column($activeItems, 'requested_qty')),
                'total_dispatched_qty' => array_sum(array_column($activeItems, 'dispatched_qty')),
            ]);
        }

        usort($rows, static function (array $left, array $right): int {
            $leftOpen = $left['is_active'] ? 0 : 1;
            $rightOpen = $right['is_active'] ? 0 : 1;

            return [$leftOpen, $right['id']] <=> [$rightOpen, $left['id']];
        });

        return array_slice($rows, $offset, $limit);
    }

    public function summarizeStatusCounts(?DateTimeImmutable $reference = null): array
    {
        $counts = [
            'pending_picking_count' => 0,
            'dispatched_count' => 0,
            'confirmed_in_route_count' => 0,
            'reconciled_today_count' => 0,
        ];

        foreach ($this->orders as $order) {
            match ($order->getStatus()->value) {
                'PENDING_PICKING' => $counts['pending_picking_count']++,
                'DISPATCHED' => $counts['dispatched_count']++,
                'CONFIRMED_BY_RESTOCKER' => $counts['confirmed_in_route_count']++,
                'RECONCILED_AT_CLOSE' => $counts['reconciled_today_count']++,
                default => null,
            };
        }

        return $counts;
    }

    public function updateOrder(ProductLoadingOrder $order): ProductLoadingOrder
    {
        $id = $order->getId();
        if ($id === null || !isset($this->orders[$id])) {
            throw new InvalidArgumentException('La orden no existe.');
        }

        $this->orders[$id] = $order;

        return $order;
    }

    public function replaceItems(int $orderId, array $items): array
    {
        if (!isset($this->orders[$orderId])) {
            throw new InvalidArgumentException('La orden no existe.');
        }

        $desired = [];
        foreach ($items as $item) {
            $desired[(int)$item['product_id']] = $item;
        }

        foreach ($this->items[$orderId] as $productId => $row) {
            if (!isset($desired[$productId])) {
                $row['is_active'] = false;
                $row['updated_at'] = '2026-11-13T12:00:00+01:00';
                $this->items[$orderId][$productId] = $row;
            }
        }

        foreach ($desired as $productId => $draft) {
            if (isset($this->items[$orderId][$productId])) {
                $row = $this->items[$orderId][$productId];
                $row['reason'] = (string)$draft['reason'];
                $row['requested_qty'] = (int)$draft['requested_qty'];
                $row['is_active'] = true;
                $row['updated_at'] = '2026-11-13T12:00:00+01:00';
                $this->items[$orderId][$productId] = $row;
            } else {
                $this->items[$orderId][$productId] = $this->newItemRow(
                    $orderId,
                    $productId,
                    (string)$draft['reason'],
                    (int)$draft['requested_qty']
                );
            }
        }

        return $this->sortedItems($orderId);
    }

    public function updateItem(ProductLoadingOrderItem $item): ProductLoadingOrderItem
    {
        $id = $item->getId();
        $orderId = $item->getOrderId();
        if ($id === null || !isset($this->items[$orderId][$item->getProductId()])) {
            throw new InvalidArgumentException('La línea no existe.');
        }

        $row = $this->items[$orderId][$item->getProductId()];
        $row = array_merge($row, [
            'reason' => $item->getReason()->value,
            'requested_qty' => $item->getRequestedQty(),
            'dispatched_qty' => $item->getDispatchedQty(),
            'batch_code' => $item->getBatchCode(),
            'expiration_date' => $item->getExpirationDate()?->format('Y-m-d'),
            'van_stock_before_reconcile' => $item->getVanStockBeforeReconcile(),
            'restocked_in_machines_qty' => $item->getRestockedInMachinesQty(),
            'withdrawn_spoilage_qty' => $item->getWithdrawnSpoilageQty(),
            'expected_return_qty' => $item->getExpectedReturnQty(),
            'counted_return_qty' => $item->getCountedReturnQty(),
            'discrepancy_qty' => $item->getDiscrepancyQty(),
            'is_active' => $item->isActive(),
            'updated_at' => '2026-11-13T12:30:00+01:00',
        ]);
        $this->items[$orderId][$item->getProductId()] = $row;

        return ProductLoadingOrderItem::fromDatabaseRow($row);
    }

    public function findItem(int $orderId, int $productId, bool $forUpdate = false): ?ProductLoadingOrderItem
    {
        $row = $this->items[$orderId][$productId] ?? null;

        return $row === null ? null : ProductLoadingOrderItem::fromDatabaseRow($row);
    }

    public function fetchProposalInputs(int $restockerId, ?DateTimeImmutable $reference = null): array
    {
        if ($restockerId === 9) {
            return [
                'restocker' => null,
                'zones' => [],
                'slot_demand' => [],
                'excluded_unavailable_machines_count' => 0,
            ];
        }

        if ($restockerId === 10 || $restockerId === 11) {
            $active = $this->findActiveByRestocker($restockerId);
            $names = [10 => 'Reponedor Sin Demanda', 11 => 'Reponedor Con Orden'];
            $codes = [10 => 'REP-10', 11 => 'REP-11'];

            return [
                'restocker' => [
                    'id' => $restockerId,
                    'name' => $names[$restockerId],
                    'operator_code' => $codes[$restockerId],
                    'is_active' => true,
                    'is_currently_absent' => false,
                    'open_order_id' => $active?->getId(),
                    'open_order_status' => $active?->getStatus()->value,
                ],
                'zones' => [],
                'slot_demand' => [],
                'excluded_unavailable_machines_count' => 0,
            ];
        }

        $active = $this->findActiveByRestocker($restockerId);
        $absent = $restockerId === 8;
        $restocker = [
            'id' => $restockerId,
            'name' => $restockerId === 7 ? 'Reponedor Siete' : 'Reponedor Ocho',
            'operator_code' => $restockerId === 7 ? 'REP-07' : 'REP-08',
            'is_active' => true,
            'is_currently_absent' => $absent,
            'open_order_id' => $active?->getId(),
            'open_order_status' => $active?->getStatus()->value,
        ];

        return [
            'restocker' => $restocker,
            'zones' => [[
                'zone_id' => 1,
                'zone_code' => 'Z-T8',
                'zone_name' => 'Zona T8',
                'coverage_role' => 'PRIMARY',
                'assigned_at' => '2026-11-01T08:00:00+01:00',
            ]],
            'slot_demand' => [
                [
                    'product_id' => 101,
                    'product_sku' => 'TEST-PLD-T8-COLD',
                    'product_name' => 'Sándwich T8',
                    'product_category' => 'PERISHABLE_FOOD',
                    'product_category_label' => 'Alimento Perecedero Fresco',
                    'product_unit_type' => 'UNIT',
                    'requires_cold_chain' => true,
                    'max_shelf_life_days' => 5,
                    'gap_qty_all' => 6,
                    'gap_qty_alerts' => 6,
                    'gap_slots_count' => 2,
                    'stockout_slots_count' => 2,
                    'low_stock_slots_count' => 0,
                    'alert_slots_count' => 2,
                ],
                [
                    'product_id' => 102,
                    'product_sku' => 'TEST-PLD-T8-AMB',
                    'product_name' => 'Refresco T8',
                    'product_category' => 'COLD_DRINK',
                    'product_category_label' => 'Bebida Fría',
                    'product_unit_type' => 'UNIT',
                    'requires_cold_chain' => false,
                    'max_shelf_life_days' => null,
                    'gap_qty_all' => 10,
                    'gap_qty_alerts' => 0,
                    'gap_slots_count' => 2,
                    'stockout_slots_count' => 0,
                    'low_stock_slots_count' => 0,
                    'alert_slots_count' => 0,
                ],
            ],
            'excluded_unavailable_machines_count' => 1,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function allItems(int $orderId): array
    {
        return $this->sortedItems($orderId);
    }

    private function newItemRow(int $orderId, int $productId, string $reason, int $requestedQty): array
    {
        $product = $this->products[$productId];

        return [
            'id' => $this->nextItemId++,
            'order_id' => $orderId,
            'product_id' => $productId,
            'reason' => $reason,
            'requested_qty' => $requestedQty,
            'dispatched_qty' => null,
            'batch_code' => null,
            'expiration_date' => null,
            'van_stock_before_reconcile' => null,
            'restocked_in_machines_qty' => null,
            'withdrawn_spoilage_qty' => null,
            'expected_return_qty' => null,
            'counted_return_qty' => null,
            'discrepancy_qty' => null,
            'is_active' => true,
            'created_at' => '2026-11-13T12:00:00+01:00',
            'updated_at' => '2026-11-13T12:00:00+01:00',
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_category' => $product->getCategory()->value,
            'product_category_label' => $product->getCategory()->label(),
            'product_unit_type' => $product->getUnitType()->value,
            'requires_cold_chain' => $product->requiresColdChain(),
            'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sortedItems(int $orderId): array
    {
        $rows = array_values($this->items[$orderId]);
        usort($rows, static function (array $left, array $right): int {
            $leftCold = $left['requires_cold_chain'] ? 0 : 1;
            $rightCold = $right['requires_cold_chain'] ? 0 : 1;
            $leftPriority = ProductLoadingOrderItem::fromDatabaseRow($left)->getReason()->priority();
            $rightPriority = ProductLoadingOrderItem::fromDatabaseRow($right)->getReason()->priority();

            return [$leftCold, $leftPriority, $left['product_sku']]
                <=> [$rightCold, $rightPriority, $right['product_sku']];
        });

        return $rows;
    }
}

/**
 * Stock de furgoneta en memoria, sin costes.
 */
final class FakeRestockerVanStockRepositoryForLoadingServiceTest implements RestockerVanStockRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    /** @var array<int, VendingProduct> */
    private array $products;

    /** @var array<int, User> */
    private array $restockers;

    private int $nextId = 500;

    public function __construct(array $products, array $restockers)
    {
        $this->products = $products;
        $this->restockers = $restockers;
    }

    public function seed(int $restockerId, int $productId, string $batch, ?string $expiration, int $quantity): void
    {
        $product = $this->products[$productId];
        $restocker = $this->restockers[$restockerId];
        $this->rows[$this->key($restockerId, $productId, $batch)] = [
            'id' => $this->nextId++,
            'restocker_id' => $restockerId,
            'product_id' => $productId,
            'batch_code' => strtoupper($batch),
            'expiration_date' => $expiration,
            'quantity_available' => $quantity,
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_category' => $product->getCategory()->value,
            'product_category_label' => $product->getCategory()->label(),
            'product_unit_type' => $product->getUnitType()->value,
            'requires_cold_chain' => $product->requiresColdChain(),
            'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
            'restocker_name' => $restocker->getName(),
            'restocker_operator_code' => $restocker->getOperatorCode(),
            'created_at' => '2026-11-13T08:00:00+01:00',
            'updated_at' => '2026-11-13T08:00:00+01:00',
        ];
    }

    public function search(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        $result = [];
        foreach ($this->rows as $row) {
            if ($restockerId !== null && (int)$row['restocker_id'] !== $restockerId) {
                continue;
            }
            if ($category !== null && (string)$row['product_category'] !== $category) {
                continue;
            }
            $withStatus = $this->withStatus($row);
            if ($expiryStatus !== null && strtoupper($expiryStatus) !== 'ALL'
                && $withStatus['expiry_status'] !== strtoupper($expiryStatus)) {
                continue;
            }
            $result[] = $withStatus;
        }

        return $result;
    }

    public function findByRestocker(
        int $restockerId,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        return $this->search($restockerId, $category, $expiryStatus, $search);
    }

    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock {
        $key = $this->key($restockerId, $productId, $batchCode);

        return isset($this->rows[$key]) ? $this->hydrate($this->rows[$key]) : null;
    }

    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        $key = $this->key($stock->getRestockerId(), $stock->getProductId(), $stock->getBatchCode());
        $existing = $this->rows[$key] ?? null;
        $product = $this->products[$stock->getProductId()];
        $restocker = $this->restockers[$stock->getRestockerId()];

        $row = $existing ?? [
            'id' => $this->nextId++,
            'restocker_id' => $stock->getRestockerId(),
            'product_id' => $stock->getProductId(),
            'batch_code' => $stock->getBatchCode(),
            'expiration_date' => $stock->getExpirationDate()?->format('Y-m-d'),
            'quantity_available' => 0,
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_category' => $product->getCategory()->value,
            'product_category_label' => $product->getCategory()->label(),
            'product_unit_type' => $product->getUnitType()->value,
            'requires_cold_chain' => $product->requiresColdChain(),
            'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
            'restocker_name' => $restocker->getName(),
            'restocker_operator_code' => $restocker->getOperatorCode(),
            'created_at' => '2026-11-13T08:00:00+01:00',
            'updated_at' => '2026-11-13T08:00:00+01:00',
        ];

        $row['quantity_available'] = $stock->getQuantityAvailable();
        $row['updated_at'] = '2026-11-13T12:45:00+01:00';
        $this->rows[$key] = $row;

        return $this->hydrate($row);
    }

    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array
    {
        $result = [];
        foreach ($this->findByRestocker($restockerId) as $row) {
            $result[(int)$row['product_id']] = ($result[(int)$row['product_id']] ?? 0)
                + (int)$row['usable_quantity'];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function allRows(): array
    {
        return array_map(fn (array $row): array => $this->withStatus($row), array_values($this->rows));
    }

    private function key(int $restockerId, int $productId, string $batch): string
    {
        return $restockerId . ':' . $productId . ':' . strtoupper($batch);
    }

    private function withStatus(array $row): array
    {
        $expiration = $row['expiration_date'] === null
            ? null
            : new DateTimeImmutable((string)$row['expiration_date']);
        $status = BatchExpiryStatus::fromExpirationDate($expiration);
        $quantity = (int)$row['quantity_available'];

        return array_merge($row, [
            'expiry_status' => $status->value,
            'expiry_status_label' => $status->label(),
            'expiry_status_icon' => $status->icon(),
            'is_expired' => $status === BatchExpiryStatus::EXPIRED,
            'is_usable' => $status !== BatchExpiryStatus::EXPIRED,
            'usable_quantity' => $status === BatchExpiryStatus::EXPIRED ? 0 : $quantity,
        ]);
    }

    private function hydrate(array $row): RestockerVanProductStock
    {
        return RestockerVanProductStock::fromDatabaseRow($row);
    }
}

final class FakeVendingProductRepositoryForLoadingServiceTest implements VendingProductRepositoryInterface
{
    /** @var array<int, VendingProduct> */
    private array $products;

    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function create(VendingProduct $product): VendingProduct
    {
        throw new RuntimeException('No usado.');
    }

    public function update(VendingProduct $product): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function deactivate(int $id): void
    {
        if (isset($this->products[$id])) {
            $this->products[$id] = $this->products[$id]->withStatus(false);
        }
    }

    public function findById(int $id): ?VendingProduct
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?VendingProduct
    {
        foreach ($this->products as $product) {
            if ($product->getSku() === strtoupper($sku)) {
                return $product;
            }
        }

        return null;
    }

    public function isSkuUsed(string $sku, ?int $excludeId = null): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array {
        throw new RuntimeException('No usado.');
    }

    public function countActiveSlots(int $productId): int
    {
        throw new RuntimeException('No usado.');
    }

    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        throw new RuntimeException('No usado.');
    }
}

final class FakeUserRepositoryForLoadingServiceTest implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $users;

    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        throw new RuntimeException('No usado.');
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        $user = $this->users[$id] ?? null;

        return $user !== null && (!$onlyActive || $user->isActive()) ? $user : null;
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        throw new RuntimeException('No usado.');
    }

    public function create(array $data): User
    {
        throw new RuntimeException('No usado.');
    }

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function softDelete(int $id): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function restore(int $id): bool
    {
        throw new RuntimeException('No usado.');
    }

    public function findAll(array $filters = []): array
    {
        $result = [];
        $role = strtoupper((string)($filters['role'] ?? ''));
        $status = (string)($filters['status'] ?? 'all');

        foreach ($this->users as $user) {
            if ($status === 'active' && !$user->isActive()) {
                continue;
            }
            if ($role !== '' && $role !== 'ALL' && $user->getRole()->value !== $role) {
                continue;
            }
            $result[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'role' => $user->getRole()->value,
                'is_active' => $user->isActive(),
            ];
        }

        return $result;
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        throw new RuntimeException('No usado.');
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        throw new RuntimeException('No usado.');
    }

    public function countPendingIncidents(int $technicianId): int
    {
        throw new RuntimeException('No usado.');
    }
}

final class FakeAuditLogRepositoryForLoadingServiceTest implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $persisted = new AuditEvent(
            count($this->events) + 1,
            $event->getEntityType(),
            $event->getEntityId(),
            $event->getAction(),
            $event->getUserId(),
            $event->getUserRole(),
            $event->getUserName(),
            $event->getPreviousState(),
            $event->getNewState(),
            $event->getMetadata(),
            $event->getCreatedAt()
        );
        $this->events[] = $persisted;

        return $persisted;
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
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

/**
 * @return array{
 *   service: ProductLoadingOrderService,
 *   orders: FakeProductLoadingOrderRepositoryForServiceTest,
 *   stocks: FakeRestockerVanStockRepositoryForLoadingServiceTest,
 *   audit: FakeAuditLogRepositoryForLoadingServiceTest,
 *   products: array<int, VendingProduct>,
 *   actor: array{id: int, role: string, name: string}
 * }
 */
function makeProductLoadingWorld(array $extraUsers = []): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
    $date = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

    $products = [
        101 => new VendingProduct(
            101,
            'TEST-PLD-T8-COLD',
            'Sándwich T8',
            ProductCategory::PERISHABLE_FOOD,
            ProductUnitType::UNIT,
            1.50,
            3.00,
            true,
            5,
            true
        ),
        102 => new VendingProduct(
            102,
            'TEST-PLD-T8-AMB',
            'Refresco T8',
            ProductCategory::COLD_DRINK,
            ProductUnitType::UNIT,
            0.80,
            1.50,
            false,
            null,
            true
        ),
        103 => new VendingProduct(
            103,
            'TEST-PLD-T8-OFF',
            'Producto Inactivo T8',
            ProductCategory::SNACK,
            ProductUnitType::UNIT,
            0.50,
            1.00,
            false,
            null,
            false
        ),
    ];

    $users = [
        7 => new User(7, 'Reponedor Siete', 'rep7@vendguard.test', 'hash', UserRole::RESTOCKER, null, true, null, null, null, false, null, 'REP-07'),
        8 => new User(8, 'Reponedor Ocho', 'rep8@vendguard.test', 'hash', UserRole::RESTOCKER, null, true, null, null, null, false, null, 'REP-08'),
        9 => new User(9, 'Reponedor Inactivo', 'rep9@vendguard.test', 'hash', UserRole::RESTOCKER, null, false, null, null, null, false, null, 'REP-09'),
        3 => new User(3, 'Coordinación', 'coord@vendguard.test', 'hash', UserRole::COORDINATOR, null, true, null, null, null, false, null, 'COORD-03'),
    ];
    $users += $extraUsers;

    $orders = new FakeProductLoadingOrderRepositoryForServiceTest($products);
    $stocks = new FakeRestockerVanStockRepositoryForLoadingServiceTest($products, $users);
    $stocks->seed(7, 101, 'LOTE-T8-A', $date(2), 2);
    $stocks->seed(7, 102, 'SIN-LOTE', null, 3);
    $stocks->seed(7, 101, 'LOTE-T8-OLD', $date(-1), 4);

    $catalog = new FakeVendingProductRepositoryForLoadingServiceTest($products);
    $audit = new FakeAuditLogRepositoryForLoadingServiceTest();
    $codes = ['PLO-2026-9001', 'PLO-2026-9002', 'PLO-2026-9003', 'PLO-2026-9004', 'PLO-2026-9005'];
    $generator = static function () use (&$codes): string {
        return array_shift($codes) ?? 'PLO-2026-' . random_int(1000, 9999);
    };

    $service = new ProductLoadingOrderService(
        $orders,
        $stocks,
        $catalog,
        new FakeUserRepositoryForLoadingServiceTest($users),
        new AuditLogger($audit),
        null,
        $generator
    );

    return [
        'service' => $service,
        'orders' => $orders,
        'stocks' => $stocks,
        'catalog' => $catalog,
        'audit' => $audit,
        'products' => $products,
        'actor' => ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación'],
        'date' => $date,
    ];
}

$failures = 0;
$assertions = 0;

$assert = function (string $title, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    echo $condition ? "  [PASS] {$title}\n" : "  [FAIL] {$title}\n";
    if (!$condition) {
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$assertValidation = function (string $title, callable $call, string $code, int $http) use ($assert): void {
    try {
        $call();
        $assert($title, false, 'no lanzó excepción');
    } catch (ProductLoadingValidationException $exception) {
        $assert(
            $title,
            $exception->getErrorCode() === $code && $exception->getHttpStatusCode() === $http,
            'código=' . $exception->getErrorCode() . ' http=' . $exception->getHttpStatusCode()
        );
    }
};

$assertNoCost = function (array $payload) use (&$assertNoCost): bool {
    foreach ($payload as $key => $value) {
        if (in_array($key, ['unit_cost_eur', 'sale_price_eur', 'unit_margin_eur'], true)) {
            return false;
        }
        if (is_array($value) && !$assertNoCost($value)) {
            return false;
        }
    }

    return true;
};

$activeProducts = static function (array $world): array {
    return [
        ['product_id' => 101, 'reason' => 'STOCKOUT_ALERT', 'requested_qty' => 6],
        ['product_id' => 102, 'reason' => 'ROUTE_GAP_FILL', 'requested_qty' => 4],
    ];
};

echo "======================================================================\n";
echo " VendGuard · Unit · ProductLoadingOrderServiceTest (T-PLD-08)\n";
echo "======================================================================\n\n";

// =====================================================================
// 1. Propuesta matutina
// =====================================================================
echo "--- 1. Propuesta matutina ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$date = $world['date'];

$proposal = $service->buildProposal(7, 'ALERTS_ONLY', 10);
$assert(
    '1.1 ALERTS_ONLY reclama solo el producto con alerta y aplica el colchón',
    count($proposal['items']) === 1
    && $proposal['items'][0]['product_sku'] === 'TEST-PLD-T8-COLD'
    && $proposal['items'][0]['gross_demand_qty'] === 7
    && $proposal['items'][0]['suggested_qty'] === 5
    && $proposal['items'][0]['reason'] === 'STOCKOUT_ALERT',
    json_encode($proposal['items'])
);
$assert(
    '1.2 La propuesta informa del stock total, útil y de las máquinas omitidas',
    $proposal['total_van_stock_qty'] === 9
    && $proposal['usable_van_stock_qty'] === 5
    && $proposal['excluded_unavailable_machines_count'] === 1
    && $proposal['total_suggested_qty'] === 5
);
$assert(
    '1.3 La propuesta no expone coste ni precio (Art. V.4)',
    $assertNoCost($proposal)
);

$full = $service->buildProposal(7, 'ALL_GAPS', 10);
$assert(
    '1.4 ALL_GAPS añade el hueco de ruta y ordena frío primero',
    count($full['items']) === 2
    && $full['items'][0]['product_sku'] === 'TEST-PLD-T8-COLD'
    && $full['items'][1]['product_sku'] === 'TEST-PLD-T8-AMB'
    && $full['items'][1]['suggested_qty'] === 8
    && $full['items'][1]['reason'] === 'ROUTE_GAP_FILL'
);

$absentProposal = $service->buildProposal(8);
$assert(
    '1.5 Un reponedor ausente recibe propuesta individual con su alerta (C2)',
    $absentProposal['restocker']['is_currently_absent'] === true
    && count($absentProposal['zones']) === 1
    && count($absentProposal['items']) === 1
    && $absentProposal['items'][0]['suggested_qty'] === 7,
    json_encode($absentProposal)
);
$absentOrder = $service->createOrder(8, [], $world['actor']);
$assert(
    '1.5b La orden individual del ausente se emite sin líneas explícitas (C2)',
    $absentOrder['order']['restocker_id'] === 8
    && $absentOrder['order']['status'] === 'PENDING_PICKING'
    && count($absentOrder['items']) === 1
    && $absentOrder['items'][0]['requested_qty'] === 7,
    json_encode($absentOrder)
);
$assertValidation(
    '1.6 Un usuario no reponedor no recibe propuesta (C2)',
    static fn () => $service->buildProposal(9),
    ProductLoadingValidationException::INVALID_RESTOCKER_RECIPIENT,
    422
);
$assertValidation(
    '1.7 El colchón fuera de 0..50 se rechaza',
    static fn () => $service->buildProposal(7, 'ALERTS_ONLY', 51),
    ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
    422
);

// =====================================================================
// 2. Emisión individual y en lote
// =====================================================================
echo "\n--- 2. Emisión individual y en lote ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$orders = $world['orders'];
$audit = $world['audit'];
$actor = $world['actor'];

$created = $service->createOrder(7, ['demand_mode' => 'ALERTS_ONLY', 'safety_buffer_pct' => 10], $actor);
$assert(
    '2.1 La emisión individual crea una orden PENDING_PICKING con código válido',
    $created['order']['status'] === 'PENDING_PICKING'
    && preg_match(ProductLoadingOrder::ORDER_CODE_PATTERN, (string)$created['order']['order_code']) === 1
    && count($created['items']) === 1
    && $created['items'][0]['requested_qty'] === 5
);
$assert(
    '2.2 La emisión individual deja auditoría PRODUCT_LOADING_ORDER_CREATED',
    count($audit->events) === 1
    && $audit->events[0]->getAction() === ProductLoadingOrderService::AUDIT_CREATED
    && $audit->events[0]->getEntityType() === AuditEvent::ENTITY_PRODUCT_LOADING_ORDER
    && $audit->events[0]->getEntityId() === (int)$created['order']['id']
);
$assert(
    '2.3 La creación no toca el stock de furgoneta',
    array_sum(array_column($world['stocks']->findByRestocker(7), 'quantity_available')) === 9
);

$assertValidation(
    '2.4 Una segunda orden activa se rechaza con 409 (D3)',
    static fn () => $service->createOrder(7, ['items' => $activeProducts($world)], $actor),
    ProductLoadingValidationException::ACTIVE_LOADING_ORDER_EXISTS,
    409
);
$assertValidation(
    '2.5 Un producto inactivo no puede entrar en preparación (C3)',
    static function () use ($actor): void {
        $fresh = makeProductLoadingWorld();
        $fresh['service']->createOrder(7, [
            'items' => [['product_id' => 103, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 1]],
        ], $actor);
    },
    ProductLoadingValidationException::PRODUCT_INACTIVE,
    422
);

$batchWorld = makeProductLoadingWorld();
$batch = $batchWorld['service']->createBatchOrders(['demand_mode' => 'ALERTS_ONLY', 'safety_buffer_pct' => 10], $actor);
$assert(
    '2.6 El lote crea solo al reponedor disponible y omite al ausente',
    count($batch['created']) === 1
    && $batch['created'][0]['restocker_id'] === 7
    && count($batch['skipped']) === 1
    && $batch['skipped'][0]['reason'] === ProductLoadingOrderService::SKIP_ABSENT,
    json_encode($batch)
);

$skipWorld = makeProductLoadingWorld([
    10 => new User(10, 'Reponedor Sin Demanda', 'rep10@vendguard.test', 'hash', UserRole::RESTOCKER, null, true, null, null, null, false, null, 'REP-10'),
    11 => new User(11, 'Reponedor Con Orden', 'rep11@vendguard.test', 'hash', UserRole::RESTOCKER, null, true, null, null, null, false, null, 'REP-11'),
]);
$activeOrder = $skipWorld['service']->createOrder(11, ['items' => $activeProducts($skipWorld)], $skipWorld['actor']);
$skipBatch = $skipWorld['service']->createBatchOrders([
    'demand_mode' => 'ALERTS_ONLY',
    'safety_buffer_pct' => 10,
], $skipWorld['actor']);
$skippedById = [];
foreach ($skipBatch['skipped'] as $skip) {
    $skippedById[(int)$skip['restocker_id']] = $skip;
}
$assert(
    '2.7 El lote distingue orden activa, demanda cero y ausencia por su causa',
    count($skipBatch['created']) === 1
    && $skipBatch['created'][0]['restocker_id'] === 7
    && ($skippedById[8]['reason'] ?? '') === ProductLoadingOrderService::SKIP_ABSENT
    && ($skippedById[10]['reason'] ?? '') === ProductLoadingOrderService::SKIP_NO_DEMAND
    && ($skippedById[11]['reason'] ?? '') === ProductLoadingOrderService::SKIP_ACTIVE_ORDER
    && (int)($skippedById[11]['order_id'] ?? 0) === (int)$activeOrder['order']['id'],
    json_encode($skipBatch)
);

// =====================================================================
// 3. Listado, detalle y Hoja A4
// =====================================================================
echo "\n--- 3. Listado, detalle y Hoja A4 ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$created = $service->createOrder(7, ['items' => $activeProducts($world)], $world['actor']);
$orderId = (int)$created['order']['id'];
$list = $service->listOrders();
$detail = $service->getOrder($orderId);
$sheet = $service->getPickingSheet($orderId);

$assert(
    '3.1 El listado devuelve KPIs y la orden abierta',
    $list['kpis']['pending_picking_count'] === 1
    && count($list['orders']) === 1
    && $list['orders'][0]['id'] === $orderId
);
$assert(
    '3.2 La Hoja A4 separa cámara frigorífica y almacén general',
    count($sheet['sections']['cold_chain']['items']) === 1
    && count($sheet['sections']['general']['items']) === 1
    && $sheet['sections']['cold_chain']['items'][0]['sku'] === 'TEST-PLD-T8-COLD'
    && $sheet['sections']['general']['items'][0]['sku'] === 'TEST-PLD-T8-AMB'
);
$assert(
    '3.3 La Hoja A4 y el detalle omiten todo importe económico (Art. V.4)',
    $assertNoCost($sheet) && $assertNoCost($detail)
);
$assert(
    '3.4 El detalle incluye zonas cubiertas y nombre del reponedor',
    $detail['order']['restocker_name'] === 'Reponedor Siete'
    && count($detail['order']['zones']) === 1
);
$assertValidation(
    '3.5 Una orden inexistente responde 404',
    static fn () => $service->getOrder(999),
    ProductLoadingValidationException::LOADING_ORDER_NOT_FOUND,
    404
);

// =====================================================================
// 4. Edición en preparación
// =====================================================================
echo "\n--- 4. Edición en preparación ---\n";

$updated = $service->updateOrder($orderId, [
    'items' => [['product_id' => 102, 'reason' => 'MANUAL_ADDITION', 'requested_qty' => 9]],
    'notes' => '  Ajuste de preparación  ',
], $world['actor']);
$coldRows = array_values(array_filter(
    $world['orders']->allItems($orderId),
    static fn (array $item): bool => (int)$item['product_id'] === 101
));
$activeRows = array_values(array_filter(
    $world['orders']->allItems($orderId),
    static fn (array $item): bool => (bool)$item['is_active']
));

$assert(
    '4.1 La edición sustituye líneas y retira la ausente con baja lógica',
    $updated['order']['notes'] === 'Ajuste de preparación'
    && count($activeRows) === 1
    && (int)$activeRows[0]['product_id'] === 102
    && $coldRows[0]['is_active'] === false
);
$assert(
    '4.2 La edición deja auditoría y no borra físicamente la línea',
    $world['audit']->events[count($world['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_UPDATED
    && count($coldRows) === 1
);
$assert(
    '4.3 La Hoja A4 refleja la sección de frío vacía tras retirar la línea',
    count($service->getPickingSheet($orderId)['sections']['cold_chain']['items']) === 0
);

// =====================================================================
// 5. Despacho con cerrojo de frío
// =====================================================================
echo "\n--- 5. Despacho con cerrojo de frío ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$created = $service->createOrder(7, ['items' => $activeProducts($world)], $world['actor']);
$orderId = (int)$created['order']['id'];

$assertValidation(
    '5.1 El despacho de frío sin temperatura se rechaza (Art. II)',
    static fn () => $service->dispatchOrder($orderId, [
        'items' => [
            ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $date(2)],
            ['product_id' => 102, 'dispatched_qty' => 4],
        ],
    ], $world['actor']),
    ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
    422
);
$assertValidation(
    '5.2 El despacho con lote caducado se rechaza (QA 2)',
    static fn () => $service->dispatchOrder($orderId, [
        'cold_chamber_exit_temp_celsius' => 3.0,
        'items' => [
            ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-X', 'expiration_date' => $date(-1)],
            ['product_id' => 102, 'dispatched_qty' => 4],
        ],
    ], $world['actor']),
    ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
    422
);
$assertValidation(
    '5.3 El despacho con colisión de fecha de lote se rechaza (QA 3)',
    static fn () => $service->dispatchOrder($orderId, [
        'cold_chamber_exit_temp_celsius' => 3.0,
        'items' => [
            ['product_id' => 101, 'dispatched_qty' => 1, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $date(1)],
            ['product_id' => 102, 'dispatched_qty' => 0],
        ],
    ], $world['actor']),
    ProductLoadingValidationException::BATCH_EXPIRATION_MISMATCH,
    409
);

$dispatched = $service->dispatchOrder($orderId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'lote-t8-a', 'expiration_date' => $date(2)],
        ['product_id' => 102, 'dispatched_qty' => 4],
    ],
], $world['actor']);

$assert(
    '5.4 El despacho válido sella temperatura, lote y estado DISPATCHED',
    $dispatched['order']['status'] === 'DISPATCHED'
    && $dispatched['order']['cold_chamber_exit_temp_celsius'] === 3.0
    && $dispatched['order']['dispatched_by'] === 3
    && $dispatched['items'][0]['batch_code'] === 'LOTE-T8-A'
    && $dispatched['items'][1]['batch_code'] === 'SIN-LOTE'
);
$assert(
    '5.5 El despacho deja auditoría específica',
    $world['audit']->events[count($world['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_DISPATCHED
);
$assert(
    '5.6 El despacho no incrementa todavía el stock de la furgoneta',
    array_sum(array_column($world['stocks']->findByRestocker(7), 'quantity_available')) === 9
);

$assertValidation(
    '5.7 El despacho fuera de estado se rechaza (409)',
    static fn () => $service->dispatchOrder($orderId, [
        'cold_chamber_exit_temp_celsius' => 3.0,
        'items' => [['product_id' => 101, 'dispatched_qty' => 1]],
    ], $world['actor']),
    ProductLoadingValidationException::INVALID_ORDER_STATE_TRANSITION,
    409
);

// =====================================================================
// 6. Recepción móvil en un toque
// =====================================================================
echo "\n--- 6. Recepción móvil ---\n";

$confirmed = $service->confirmOrder($orderId, 7, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']);
$coldStock = $world['stocks']->findLot(7, 101, 'LOTE-T8-A');
$ambientStock = $world['stocks']->findLot(7, 102, 'SIN-LOTE');
$stockEvents = array_values(array_filter(
    $world['audit']->events,
    static fn (AuditEvent $event): bool => $event->getEntityType() === AuditEvent::ENTITY_RESTOCKER_VAN_STOCK
));

$assert(
    '6.1 La recepción suma el despacho al stock por lote (D3)',
    $confirmed['order']['status'] === 'CONFIRMED_BY_RESTOCKER'
    && $coldStock?->getQuantityAvailable() === 8
    && $ambientStock?->getQuantityAvailable() === 7
);
$assert(
    '6.2 La recepción deja auditoría de orden y de cada incremento de lote',
    $world['audit']->events[count($world['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_CONFIRMED
    && count($stockEvents) === 2
    && $stockEvents[0]->getAction() === 'RESTOCKER_VAN_STOCK_RECEIVED'
);
$assertValidation(
    '6.3 Otro reponedor no puede confirmar una orden ajena (403)',
    static fn () => $service->confirmOrder($orderId, 8, ['id' => 8, 'role' => 'RESTOCKER', 'name' => 'Reponedor Ocho']),
    ProductLoadingValidationException::FORBIDDEN_LOADING_ORDER_ACCESS,
    403
);
$assertValidation(
    '6.4 La segunda confirmación se rechaza (409)',
    static fn () => $service->confirmOrder($orderId, 7, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']),
    ProductLoadingValidationException::INVALID_ORDER_STATE_TRANSITION,
    409
);

// =====================================================================
// 7. Liquidación de cierre
// =====================================================================
echo "\n--- 7. Liquidación de cierre ---\n";

$reconcilePayload = [
    'reconciliation_notes' => 'Descuadre justificado por conteo manual',
    'items' => [
        [
            'product_id' => 101,
            'restocked_in_machines_qty' => 2,
            'withdrawn_spoilage_qty' => 1,
            'counted_return_qty' => 4,
        ],
        [
            'product_id' => 102,
            'restocked_in_machines_qty' => 4,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 3,
        ],
    ],
];

$reconciled = $service->reconcileOrder(
    $orderId,
    $reconcilePayload,
    ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']
);
$reconciledCold = $world['stocks']->findLot(7, 101, 'LOTE-T8-A');
$reconciledAmbient = $world['stocks']->findLot(7, 102, 'SIN-LOTE');

$assert(
    '7.1 La liquidación deja el stock en el retorno contado',
    $reconciled['order']['status'] === 'RECONCILED_AT_CLOSE'
    && $reconciledCold?->getQuantityAvailable() === 4
    && $reconciledAmbient?->getQuantityAvailable() === 3
);
$assert(
    '7.2 El cuadre calcula descuadre y conserva la nota',
    $reconciled['items'][0]['expected_return_qty'] === 5
    && $reconciled['items'][0]['discrepancy_qty'] === -1
    && $reconciled['order']['reconciliation_notes'] === 'Descuadre justificado por conteo manual'
);
$assert(
    '7.3 La liquidación audita orden y ajustes de stock',
    $world['audit']->events[count($world['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_RECONCILED
    && count(array_filter(
        $world['audit']->events,
        static fn (AuditEvent $event): bool => $event->getAction() === 'RESTOCKER_VAN_STOCK_RECONCILED'
    )) === 2
);
$assert(
    '7.4 La Hoja A4 liquidada sigue sin importes',
    $assertNoCost($reconciled)
);

// =====================================================================
// 8. Rechazos de liquidación
// =====================================================================
echo "\n--- 8. Rechazos de liquidación ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$order = $service->createOrder(7, ['items' => $activeProducts($world)], $world['actor']);
$orderId = (int)$order['order']['id'];
$service->dispatchOrder($orderId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $date(2)],
        ['product_id' => 102, 'dispatched_qty' => 4],
    ],
], $world['actor']);
$service->confirmOrder($orderId, 7, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']);

$assertValidation(
    '8.1 Un descuadre sin nota suficiente se rechaza (Art. V.1)',
    static fn () => $service->reconcileOrder($orderId, [
        'reconciliation_notes' => 'corta',
        'items' => [
            ['product_id' => 101, 'restocked_in_machines_qty' => 2, 'withdrawn_spoilage_qty' => 1, 'counted_return_qty' => 4],
            ['product_id' => 102, 'restocked_in_machines_qty' => 4, 'withdrawn_spoilage_qty' => 0, 'counted_return_qty' => 3],
        ],
    ], $world['actor']),
    ProductLoadingValidationException::RECONCILIATION_DISCREPANCY_REASON_REQUIRED,
    422
);
$assertValidation(
    '8.2 Abastecidas más mermas por encima del saldo previo se rechaza (QA 4)',
    static fn () => $service->reconcileOrder($orderId, [
        'reconciliation_notes' => 'Descuadre justificado por conteo manual',
        'items' => [
            ['product_id' => 101, 'restocked_in_machines_qty' => 8, 'withdrawn_spoilage_qty' => 1, 'counted_return_qty' => 0],
            ['product_id' => 102, 'restocked_in_machines_qty' => 4, 'withdrawn_spoilage_qty' => 0, 'counted_return_qty' => 3],
        ],
    ], $world['actor']),
    ProductLoadingValidationException::RECONCILIATION_EXCEEDS_AVAILABLE_STOCK,
    422
);
$assert(
    '8.3 Los rechazos no modifican el stock',
    $world['stocks']->findLot(7, 101, 'LOTE-T8-A')?->getQuantityAvailable() === 8
);

// =====================================================================
// 9. Liquidación con línea no despachada y cancelación
// =====================================================================
echo "\n--- 9. Sellado en cero y cancelación ---\n";

$world = makeProductLoadingWorld();
$service = $world['service'];
$order = $service->createOrder(7, ['items' => $activeProducts($world)], $world['actor']);
$orderId = (int)$order['order']['id'];
$service->dispatchOrder($orderId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $date(2)],
        ['product_id' => 102, 'dispatched_qty' => 0],
    ],
], $world['actor']);
$service->confirmOrder($orderId, 7, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']);
$sealed = $service->reconcileOrder($orderId, [
    'items' => [
        ['product_id' => 101, 'restocked_in_machines_qty' => 2, 'withdrawn_spoilage_qty' => 1, 'counted_return_qty' => 5],
    ],
], $world['actor']);
$ambientItem = array_values(array_filter(
    $sealed['items'],
    static fn (array $item): bool => (int)$item['product_id'] === 102
))[0];

$assert(
    '9.1 La línea no despachada se sella en cero sin entrada manual (QA 4)',
    $ambientItem['van_stock_before_reconcile'] === 0
    && $ambientItem['expected_return_qty'] === 0
    && $ambientItem['counted_return_qty'] === 0
    && $ambientItem['discrepancy_qty'] === 0
);
$assert(
    '9.2 La línea no despachada no altera el stock de su lote',
    $world['stocks']->findLot(7, 102, 'SIN-LOTE')?->getQuantityAvailable() === 3
);

$cancelWorld = makeProductLoadingWorld();
$cancelService = $cancelWorld['service'];
$cancelOrder = $cancelService->createOrder(7, ['items' => $activeProducts($cancelWorld)], $cancelWorld['actor']);
$cancelId = (int)$cancelOrder['order']['id'];
$stockBefore = array_sum(array_column($cancelWorld['stocks']->findByRestocker(7), 'quantity_available'));

$assertValidation(
    '9.3 La cancelación con motivo corto se rechaza (Art. V.1)',
    static fn () => $cancelService->cancelOrder($cancelId, '  corto  ', $cancelWorld['actor']),
    ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
    422
);
$cancelled = $cancelService->cancelOrder($cancelId, 'Cancelación por falta de producto en almacén', $cancelWorld['actor']);
$assert(
    '9.4 La cancelación válida deja el estado y el motivo auditado',
    $cancelled['order']['status'] === 'CANCELLED'
    && $cancelled['order']['cancel_reason'] === 'Cancelación por falta de producto en almacén'
    && $cancelWorld['audit']->events[count($cancelWorld['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_CANCELLED
    && array_sum(array_column($cancelWorld['stocks']->findByRestocker(7), 'quantity_available')) === $stockBefore
);

$closedOrder = $cancelService->createOrder(7, ['items' => $activeProducts($cancelWorld)], $cancelWorld['actor']);
$closedId = (int)$closedOrder['order']['id'];
$cancelService->dispatchOrder($closedId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 1, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $date(2)],
        ['product_id' => 102, 'dispatched_qty' => 0],
    ],
], $cancelWorld['actor']);
$cancelService->confirmOrder($closedId, 7, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']);

$assertValidation(
    '9.5 Una orden ya recepcionada no puede cancelarse (409)',
    static fn () => $cancelService->cancelOrder($closedId, 'Intento de cancelar una orden ya recepcionada', $cancelWorld['actor']),
    ProductLoadingValidationException::ORDER_CANCELLATION_NOT_ALLOWED,
    409
);

// =====================================================================
// 10. Producto desactivado en tránsito
// =====================================================================
echo "\n--- 10. Producto desactivado en tránsito ---\n";

$inactiveWorld = makeProductLoadingWorld();
$inactiveService = $inactiveWorld['service'];
$inactiveDate = $inactiveWorld['date'];
$transitOrder = $inactiveService->createOrder(7, ['items' => $activeProducts($inactiveWorld)], $inactiveWorld['actor']);
$transitId = (int)$transitOrder['order']['id'];
$inactiveService->dispatchOrder($transitId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $inactiveDate(2)],
        ['product_id' => 102, 'dispatched_qty' => 4],
    ],
], $inactiveWorld['actor']);
$inactiveWorld['catalog']->deactivate(101);
$receivedWhileInactive = $inactiveService->confirmOrder(
    $transitId,
    7,
    ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Reponedor Siete']
);
$assert(
    '10.1 La recepción tolera un producto desactivado después del despacho (C3)',
    $inactiveWorld['catalog']->findById(101)?->isActive() === false
    && $receivedWhileInactive['order']['status'] === 'CONFIRMED_BY_RESTOCKER'
    && $inactiveWorld['stocks']->findLot(7, 101, 'LOTE-T8-A')?->getQuantityAvailable() === 8
    && $inactiveWorld['stocks']->findLot(7, 102, 'SIN-LOTE')?->getQuantityAvailable() === 7,
    json_encode($receivedWhileInactive['order'])
);
$settledWhileInactive = $inactiveService->reconcileOrder($transitId, [
    'items' => [
        ['product_id' => 101, 'restocked_in_machines_qty' => 2, 'withdrawn_spoilage_qty' => 1, 'counted_return_qty' => 5],
        ['product_id' => 102, 'restocked_in_machines_qty' => 4, 'withdrawn_spoilage_qty' => 0, 'counted_return_qty' => 3],
    ],
], $inactiveWorld['actor']);
$assert(
    '10.2 La liquidación tolera un producto desactivado en tránsito (C3)',
    $settledWhileInactive['order']['status'] === 'RECONCILED_AT_CLOSE'
    && $inactiveWorld['stocks']->findLot(7, 101, 'LOTE-T8-A')?->getQuantityAvailable() === 5
    && $inactiveWorld['stocks']->findLot(7, 102, 'SIN-LOTE')?->getQuantityAvailable() === 3,
    json_encode($settledWhileInactive['order'])
);

// =====================================================================
// 11. Cancelación desde DISPATCHED
// =====================================================================
echo "\n--- 11. Cancelación desde DISPATCHED ---\n";

$dispatchedCancelWorld = makeProductLoadingWorld();
$dispatchedCancelService = $dispatchedCancelWorld['service'];
$dispatchedCancelDate = $dispatchedCancelWorld['date'];
$dispatchedCancelOrder = $dispatchedCancelService->createOrder(
    7,
    ['items' => $activeProducts($dispatchedCancelWorld)],
    $dispatchedCancelWorld['actor']
);
$dispatchedCancelId = (int)$dispatchedCancelOrder['order']['id'];
$dispatchedForCancellation = $dispatchedCancelService->dispatchOrder($dispatchedCancelId, [
    'cold_chamber_exit_temp_celsius' => 3.0,
    'items' => [
        ['product_id' => 101, 'dispatched_qty' => 6, 'batch_code' => 'LOTE-T8-A', 'expiration_date' => $dispatchedCancelDate(2)],
        ['product_id' => 102, 'dispatched_qty' => 4],
    ],
], $dispatchedCancelWorld['actor']);
$stockBeforeDispatchedCancel = array_sum(array_column(
    $dispatchedCancelWorld['stocks']->findByRestocker(7),
    'quantity_available'
));
$cancelledFromDispatched = $dispatchedCancelService->cancelOrder(
    $dispatchedCancelId,
    'Cancelación tras despacho por cambio de ruta del reponedor.',
    $dispatchedCancelWorld['actor']
);
$assert(
    '11.1 La cancelación desde DISPATCHED deja CANCELLED, audita y no altera el stock',
    $dispatchedForCancellation['order']['status'] === 'DISPATCHED'
    && $cancelledFromDispatched['order']['status'] === 'CANCELLED'
    && $cancelledFromDispatched['order']['cancel_reason'] === 'Cancelación tras despacho por cambio de ruta del reponedor.'
    && $cancelledFromDispatched['order']['cancelled_at'] !== null
    && $dispatchedCancelWorld['audit']->events[count($dispatchedCancelWorld['audit']->events) - 1]->getAction() === ProductLoadingOrderService::AUDIT_CANCELLED
    && array_sum(array_column($dispatchedCancelWorld['stocks']->findByRestocker(7), 'quantity_available')) === $stockBeforeDispatchedCancel,
    json_encode($cancelledFromDispatched['order'])
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. SERVICIO DE ÓRDENES DE CARGA CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL SERVICIO DE ÓRDENES DE CARGA.\n";
echo "======================================================================\n";
exit(1);
