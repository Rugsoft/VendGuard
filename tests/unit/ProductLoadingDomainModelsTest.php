<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Enum\ProductLoadingDemandMode;
use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;

$assertions = 0;

function assertProductLoadingCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertProductLoadingThrows(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        assertProductLoadingCondition(true, $message);
        return;
    }

    assertProductLoadingCondition(false, $message);
}

try {
    // ─────────────────────────────────────────────────────────────────────
    // Tipos nativos del ciclo de carga
    // ─────────────────────────────────────────────────────────────────────
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::values() === [
            'PENDING_PICKING',
            'DISPATCHED',
            'CONFIRMED_BY_RESTOCKER',
            'RECONCILED_AT_CLOSE',
            'CANCELLED',
        ],
        'ProductLoadingOrderStatus exposes the five states of the contract in order'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::PENDING_PICKING->label() === 'En preparación (Picking)'
            && ProductLoadingOrderStatus::CANCELLED->label() === 'Cancelada',
        'ProductLoadingOrderStatus carries its Spanish labels'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::PENDING_PICKING->isActive()
            && !ProductLoadingOrderStatus::PENDING_PICKING->isTerminal()
            && ProductLoadingOrderStatus::DISPATCHED->isActive()
            && ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER->isActive(),
        'The three open states are active and not terminal'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::RECONCILED_AT_CLOSE->isTerminal()
            && ProductLoadingOrderStatus::CANCELLED->isTerminal()
            && !ProductLoadingOrderStatus::RECONCILED_AT_CLOSE->isActive(),
        'Reconciled and cancelled orders are terminal'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::PENDING_PICKING->canBeEdited()
            && ProductLoadingOrderStatus::PENDING_PICKING->canBeDispatched()
            && ProductLoadingOrderStatus::PENDING_PICKING->canBeCancelled()
            && !ProductLoadingOrderStatus::PENDING_PICKING->canBeConfirmed()
            && !ProductLoadingOrderStatus::PENDING_PICKING->canBeReconciled(),
        'Only a pending order can be edited, dispatched or cancelled'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::DISPATCHED->canBeConfirmed()
            && ProductLoadingOrderStatus::DISPATCHED->canBeCancelled()
            && !ProductLoadingOrderStatus::DISPATCHED->canBeEdited()
            && !ProductLoadingOrderStatus::DISPATCHED->canBeDispatched(),
        'A dispatched order can only be confirmed or cancelled'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER->canBeReconciled()
            && !ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER->canBeCancelled()
            && !ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER->canBeConfirmed(),
        'A received order can only be reconciled'
    );
    assertProductLoadingCondition(
        ProductLoadingOrderStatus::fromString(' pending_picking ') === ProductLoadingOrderStatus::PENDING_PICKING
            && ProductLoadingOrderStatus::isValid('CANCELLED')
            && !ProductLoadingOrderStatus::isValid('ARCHIVED'),
        'ProductLoadingOrderStatus parses canonical values and rejects unknown ones'
    );
    assertProductLoadingThrows(
        static fn() => ProductLoadingOrderStatus::fromString('ARCHIVED'),
        'ProductLoadingOrderStatus rejects an unknown status'
    );

    assertProductLoadingCondition(
        ProductLoadingDemandMode::values() === ['ALERTS_ONLY', 'ALL_GAPS']
            && ProductLoadingDemandMode::ALL_GAPS->label() === 'Ruta completa'
            && ProductLoadingDemandMode::ALERTS_ONLY->label() === 'Solo alertas',
        'ProductLoadingDemandMode exposes the two demand modes with labels'
    );
    assertProductLoadingCondition(
        ProductLoadingDemandMode::ALL_GAPS->includesAllGaps()
            && !ProductLoadingDemandMode::ALERTS_ONLY->includesAllGaps()
            && ProductLoadingDemandMode::ALERTS_ONLY->isAlertsOnly(),
        'ProductLoadingDemandMode distinguishes the two proposal scopes'
    );
    assertProductLoadingCondition(
        ProductLoadingDemandMode::fromString('all_gaps') === ProductLoadingDemandMode::ALL_GAPS,
        'ProductLoadingDemandMode parses canonical values'
    );
    assertProductLoadingThrows(
        static fn() => ProductLoadingDemandMode::fromString('FULL_ROUTE'),
        'ProductLoadingDemandMode rejects an unknown mode'
    );

    assertProductLoadingCondition(
        ProductLoadingItemReason::values() === [
            'STOCKOUT_ALERT',
            'LOW_STOCK_ALERT',
            'ROUTE_GAP_FILL',
            'MANUAL_ADDITION',
        ],
        'ProductLoadingItemReason exposes the four reasons in urgency order'
    );
    assertProductLoadingCondition(
        ProductLoadingItemReason::STOCKOUT_ALERT->priority() === 1
            && ProductLoadingItemReason::LOW_STOCK_ALERT->priority() === 2
            && ProductLoadingItemReason::ROUTE_GAP_FILL->priority() === 3
            && ProductLoadingItemReason::MANUAL_ADDITION->priority() === 4,
        'Each reason declares its urgency priority'
    );
    assertProductLoadingCondition(
        ProductLoadingItemReason::STOCKOUT_ALERT->outranks(ProductLoadingItemReason::LOW_STOCK_ALERT)
            && !ProductLoadingItemReason::MANUAL_ADDITION->outranks(ProductLoadingItemReason::ROUTE_GAP_FILL),
        'ProductLoadingItemReason compares by urgency'
    );
    assertProductLoadingCondition(
        ProductLoadingItemReason::mostUrgent(
            ProductLoadingItemReason::ROUTE_GAP_FILL,
            ProductLoadingItemReason::LOW_STOCK_ALERT,
            ProductLoadingItemReason::STOCKOUT_ALERT,
            ProductLoadingItemReason::MANUAL_ADDITION
        ) === ProductLoadingItemReason::STOCKOUT_ALERT,
        'mostUrgent picks the most urgent reason regardless of arrival order'
    );
    assertProductLoadingCondition(
        ProductLoadingItemReason::STOCKOUT_ALERT->isAutomatic()
            && !ProductLoadingItemReason::MANUAL_ADDITION->isAutomatic(),
        'Manual additions are the only non-automatic reason'
    );
    assertProductLoadingCondition(
        ProductLoadingItemReason::fromString('route_gap_fill') === ProductLoadingItemReason::ROUTE_GAP_FILL,
        'ProductLoadingItemReason parses canonical values'
    );
    assertProductLoadingThrows(
        static fn() => ProductLoadingItemReason::mostUrgent(),
        'mostUrgent requires at least one reason'
    );
    assertProductLoadingThrows(
        static fn() => ProductLoadingItemReason::fromString('WISHLIST'),
        'ProductLoadingItemReason rejects an unknown reason'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Semáforo sanitario de caducidad (EARS 01.2, D4, Art. II)
    // ─────────────────────────────────────────────────────────────────────
    $today = new DateTimeImmutable('2026-11-13');

    assertProductLoadingCondition(
        BatchExpiryStatus::fromExpirationDate(null, $today) === BatchExpiryStatus::OK,
        'A batch without expiration date is always OK'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::fromExpirationDate($today->modify('-1 day'), $today) === BatchExpiryStatus::EXPIRED,
        'A batch that expired yesterday is EXPIRED'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::fromExpirationDate($today, $today) === BatchExpiryStatus::EXPIRED,
        'A batch that expires today is EXPIRED'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::fromExpirationDate($today->modify('+1 day'), $today) === BatchExpiryStatus::EXPIRING_SOON
            && BatchExpiryStatus::fromExpirationDate($today->modify('+2 days'), $today) === BatchExpiryStatus::EXPIRING_SOON,
        'The two-day window marks EXPIRING_SOON including its boundary'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::fromExpirationDate($today->modify('+3 days'), $today) === BatchExpiryStatus::OK,
        'Beyond the two-day window the batch is OK'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::EXPIRED->label() === 'Caducado'
            && BatchExpiryStatus::EXPIRING_SOON->label() === 'Próximo a caducar'
            && BatchExpiryStatus::OK->label() === 'Vigente',
        'BatchExpiryStatus carries its Spanish labels'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::EXPIRED->icon() === '🔴'
            && BatchExpiryStatus::EXPIRING_SOON->icon() === '🟡'
            && BatchExpiryStatus::OK->icon() === '🟢',
        'BatchExpiryStatus carries the sanitary traffic light'
    );
    assertProductLoadingCondition(
        BatchExpiryStatus::values() === ['EXPIRED', 'EXPIRING_SOON', 'OK'],
        'BatchExpiryStatus exposes the three states of the contract'
    );

    // ─────────────────────────────────────────────────────────────────────
    // RestockerVanProductStock (Tabla 44)
    // ─────────────────────────────────────────────────────────────────────
    $stock = new RestockerVanProductStock(null, 12, 7);
    assertProductLoadingCondition(
        $stock->getBatchCode() === RestockerVanProductStock::DEFAULT_BATCH_CODE
            && $stock->getQuantityAvailable() === 0
            && $stock->getExpirationDate() === null
            && $stock->expiryStatus($today) === BatchExpiryStatus::OK,
        'A stock row defaults to SIN-LOTE, zero units and no expiration'
    );
    assertProductLoadingCondition(
        !$stock->isUsable($today) && $stock->usableQuantity($today) === 0,
        'A stock row without units contributes no usable stock'
    );
    assertProductLoadingCondition(
        $stock->toArray()['batch_code'] === 'SIN-LOTE'
            && $stock->toArray()['expiry_status'] === 'OK'
            && array_key_exists('usable_quantity', $stock->toArray()),
        'RestockerVanProductStock projects the sanitary status'
    );

    $normalizedStock = new RestockerVanProductStock(null, 12, 7, ' lote-sw-01 ');
    assertProductLoadingCondition(
        $normalizedStock->getBatchCode() === 'LOTE-SW-01',
        'The batch code is normalized to uppercase'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 7, 'LOTE SW 01'),
        'An invalid batch code is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 0, 7),
        'A stock row requires a positive restocker'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 0),
        'A stock row requires a positive product'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 7, 'SIN-LOTE', $today->modify('+2 days'), 5, true),
        'A cold-chain product cannot travel with SIN-LOTE'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 7, 'LOTE-SW-01', null, 5, true),
        'A cold-chain product requires an expiration date'
    );

    $coldStock = new RestockerVanProductStock(null, 12, 7, 'LOTE-SW-01', $today->modify('+1 day'), 5, true);
    assertProductLoadingCondition(
        $coldStock->expiryStatus($today) === BatchExpiryStatus::EXPIRING_SOON
            && $coldStock->isUsable($today)
            && $coldStock->usableQuantity($today) === 5,
        'A cold batch with real lot and expiration is usable until it expires'
    );

    $expiredStock = new RestockerVanProductStock(null, 12, 7, 'LOTE-OLD', $today->modify('-1 day'), 4, true);
    assertProductLoadingCondition(
        $expiredStock->isExpired($today)
            && !$expiredStock->isUsable($today)
            && $expiredStock->usableQuantity($today) === 0,
        'An expired batch with units contributes no usable stock'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 7, 'LOTE-MAX', null, -1),
        'A negative quantity is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new RestockerVanProductStock(null, 12, 7, 'LOTE-MAX', null, 100000),
        'A quantity above 99999 is rejected'
    );
    assertProductLoadingCondition(
        (new RestockerVanProductStock(null, 12, 7, 'LOTE-MAX', null, 99999))->getQuantityAvailable() === 99999,
        'The upper quantity bound 99999 is accepted'
    );

    $adjustedStock = $coldStock->withQuantity(8);
    assertProductLoadingCondition(
        $adjustedStock->getQuantityAvailable() === 8 && $coldStock->getQuantityAvailable() === 5,
        'withQuantity returns an adjusted copy without mutating the original'
    );
    assertProductLoadingCondition(
        $stock->withId(44)->getId() === 44,
        'withId assigns the persisted identifier to the copy'
    );

    $stockRow = RestockerVanProductStock::fromDatabaseRow([
        'id' => '44',
        'restocker_id' => '12',
        'product_id' => '7',
        'batch_code' => 'lote-1',
        'expiration_date' => '2026-11-20',
        'quantity_available' => '9',
        'requires_cold_chain' => 't',
        'created_at' => '2026-11-13 08:00:00+00',
        'updated_at' => '2026-11-13 08:30:00+00',
    ]);
    assertProductLoadingCondition(
        $stockRow->getId() === 44
            && $stockRow->getBatchCode() === 'LOTE-1'
            && $stockRow->getQuantityAvailable() === 9
            && $stockRow->isUsable($today),
        'fromDatabaseRow restores a row with driver-native string values'
    );

    // ─────────────────────────────────────────────────────────────────────
    // ProductLoadingOrder (Tabla 45)
    // ─────────────────────────────────────────────────────────────────────
    $order = new ProductLoadingOrder(null, ' plo-2026-0001 ', 12, 3);
    assertProductLoadingCondition(
        $order->getOrderCode() === 'PLO-2026-0001'
            && $order->getStatus() === ProductLoadingOrderStatus::PENDING_PICKING
            && $order->getDemandMode() === ProductLoadingDemandMode::ALERTS_ONLY
            && $order->getSafetyBufferPct() === 10
            && $order->getColdChamberExitTempCelsius() === null,
        'A new order defaults to pending picking, alerts only, 10 % buffer and no temperature'
    );
    assertProductLoadingCondition(
        $order->isActive() && !$order->isTerminal() && $order->canBeEdited()
            && $order->canBeDispatched() && $order->canBeCancelled(),
        'A pending order exposes the editable and dispatchable transitions'
    );
    assertProductLoadingCondition(
        !$order->hasValidColdChamberTemperature(),
        'An order without temperature does not claim a valid cold chamber reading'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'ORD-2026-0001', 12, 3),
        'An order code outside PLO-AAAA-NNNN is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 0, 3),
        'An order requires a positive restocker'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 12, 0),
        'An order requires the coordinator that issues it'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALERTS_ONLY, -1),
        'A negative safety buffer is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALERTS_ONLY, 51),
        'A safety buffer above 50 % is rejected'
    );
    assertProductLoadingCondition(
        (new ProductLoadingOrder(null, 'PLO-2026-0002', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALL_GAPS, 0))->getSafetyBufferPct() === 0,
        'The lower buffer bound 0 is accepted'
    );
    assertProductLoadingCondition(
        (new ProductLoadingOrder(null, 'PLO-2026-0003', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALL_GAPS, 50))->getSafetyBufferPct() === 50,
        'The upper buffer bound 50 is accepted'
    );

    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALERTS_ONLY, 10, 4.1),
        'A cold chamber temperature above 4,0 °C is rejected (Art. II)'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(null, 'PLO-2026-0001', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALERTS_ONLY, 10, -5.1),
        'A cold chamber temperature below -5,0 °C is rejected (Art. II)'
    );
    $coldOrder = new ProductLoadingOrder(null, 'PLO-2026-0004', 12, 3, ProductLoadingOrderStatus::DISPATCHED, ProductLoadingDemandMode::ALERTS_ONLY, 10, 3.5);
    assertProductLoadingCondition(
        $coldOrder->hasValidColdChamberTemperature()
            && $coldOrder->getColdChamberExitTempCelsius() === 3.5
            && $coldOrder->canBeConfirmed(),
        'A temperature inside [-5,0, 4,0] °C is accepted and the order can be confirmed'
    );
    assertProductLoadingCondition(
        (new ProductLoadingOrder(null, 'PLO-2026-0005', 12, 3, ProductLoadingOrderStatus::PENDING_PICKING, ProductLoadingDemandMode::ALERTS_ONLY, 10, -5.0))->hasValidColdChamberTemperature(),
        'The lower temperature boundary -5,0 °C is accepted'
    );

    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0006',
            restockerId: 12,
            createdBy: 3,
            status: ProductLoadingOrderStatus::CANCELLED,
            cancelledAt: '2026-11-13 10:00:00'
        ),
        'A cancelled order without justification is rejected (Art. V.1)'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0006',
            restockerId: 12,
            createdBy: 3,
            status: ProductLoadingOrderStatus::CANCELLED,
            cancelReason: 'falta stock',
            cancelledAt: '2026-11-13 10:00:00'
        ),
        'A cancellation justification shorter than 20 real characters is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0006',
            restockerId: 12,
            createdBy: 3,
            cancelReason: 'Cancelada por falta de stock en cámara'
        ),
        'A non-cancelled order cannot declare a cancellation reason'
    );

    $cancelledOrder = new ProductLoadingOrder(
        id: null,
        orderCode: 'PLO-2026-0007',
        restockerId: 12,
        createdBy: 3,
        status: ProductLoadingOrderStatus::CANCELLED,
        cancelReason: str_repeat('á', 20),
        cancelledAt: '2026-11-13 10:00:00'
    );
    assertProductLoadingCondition(
        $cancelledOrder->getStatus() === ProductLoadingOrderStatus::CANCELLED
            && $cancelledOrder->isTerminal()
            && !$cancelledOrder->canBeCancelled()
            && $cancelledOrder->getCancelReason() === str_repeat('á', 20),
        'Twenty real characters (multibyte) are enough to justify a cancellation'
    );

    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0008',
            restockerId: 12,
            createdBy: 3,
            status: ProductLoadingOrderStatus::RECONCILED_AT_CLOSE,
            reconciledAt: '2026-11-13 18:00:00'
        ),
        'A reconciled order without responsible user is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0008',
            restockerId: 12,
            createdBy: 3,
            reconciledBy: 3
        ),
        'A non-reconciled order cannot declare a reconciliation actor'
    );
    $reconciledOrder = new ProductLoadingOrder(
        id: null,
        orderCode: 'PLO-2026-0009',
        restockerId: 12,
        createdBy: 3,
        status: ProductLoadingOrderStatus::RECONCILED_AT_CLOSE,
        reconciliationNotes: 'Liquidación completa de la ruta de hoy',
        reconciledBy: 3,
        reconciledAt: '2026-11-13 18:00:00'
    );
    assertProductLoadingCondition(
        $reconciledOrder->isTerminal() && $reconciledOrder->canBeReconciled() === false
            && $reconciledOrder->getReconciledBy() === 3,
        'A reconciled order seals its actor and closes the cycle'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrder(
            id: null,
            orderCode: 'PLO-2026-0010',
            restockerId: 12,
            createdBy: 3,
            status: ProductLoadingOrderStatus::RECONCILED_AT_CLOSE,
            reconciliationNotes: 'corto',
            reconciledBy: 3,
            reconciledAt: '2026-11-13 18:00:00'
        ),
        'A declared reconciliation note shorter than 20 real characters is rejected'
    );

    $notedOrder = $order->withNotes('Reponer antes de las 08:00');
    assertProductLoadingCondition(
        $notedOrder->getNotes() === 'Reponer antes de las 08:00' && $order->getNotes() === null,
        'withNotes returns a copy without mutating the original order'
    );
    assertProductLoadingCondition(
        $order->withId(91)->getId() === 91,
        'withId assigns the persisted identifier to the order copy'
    );

    $orderRow = ProductLoadingOrder::fromDatabaseRow([
        'id' => '91',
        'order_code' => 'plo-2026-0011',
        'restocker_id' => '12',
        'created_by' => '3',
        'status' => 'DISPATCHED',
        'demand_mode' => 'ALL_GAPS',
        'safety_buffer_pct' => '15',
        'cold_chamber_exit_temp_celsius' => '3.5',
        'dispatched_by' => '4',
        'dispatched_at' => '2026-11-13 07:30:00+00',
    ]);
    assertProductLoadingCondition(
        $orderRow->getId() === 91
            && $orderRow->getStatus() === ProductLoadingOrderStatus::DISPATCHED
            && $orderRow->getDemandMode() === ProductLoadingDemandMode::ALL_GAPS
            && $orderRow->getColdChamberExitTempCelsius() === 3.5
            && $orderRow->getDispatchedBy() === 4,
        'fromDatabaseRow restores an order with numeric strings and enum values'
    );
    assertProductLoadingCondition(
        $orderRow->toArray()['status_label'] === 'Despachada'
            && $orderRow->toArray()['demand_mode_label'] === 'Ruta completa'
            && $orderRow->toArray()['is_active'] === true,
        'ProductLoadingOrder projects labels and lifecycle flags'
    );

    // ─────────────────────────────────────────────────────────────────────
    // ProductLoadingOrderItem (Tabla 46)
    // ─────────────────────────────────────────────────────────────────────
    $line = new ProductLoadingOrderItem(null, 100, 7);
    assertProductLoadingCondition(
        $line->getReason() === ProductLoadingItemReason::STOCKOUT_ALERT
            && $line->getRequestedQty() === 1
            && $line->getDispatchedQty() === null
            && $line->getBatchCode() === null
            && $line->isActive(),
        'A new line defaults to one requested unit, stockout reason and no dispatch'
    );
    assertProductLoadingCondition(
        !$line->isDispatched() && !$line->hasReconcileData() && !$line->hasDiscrepancy(),
        'A new line declares neither dispatch nor reconciliation'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 7, ProductLoadingItemReason::STOCKOUT_ALERT, 0),
        'Zero requested units are rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 7, ProductLoadingItemReason::STOCKOUT_ALERT, 100000),
        'Requested units above 99999 are rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 7, ProductLoadingItemReason::STOCKOUT_ALERT, 10, -1),
        'Negative dispatched units are rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 7, ProductLoadingItemReason::STOCKOUT_ALERT, 10, 100000),
        'Dispatched units above 99999 are rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 0, 7),
        'A line requires an existing order'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 0),
        'A line requires an existing product'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(null, 100, 7, ProductLoadingItemReason::STOCKOUT_ALERT, 10, 10, 'LOTE 01'),
        'An invalid batch code is rejected'
    );

    $dispatchedLine = $line->withDispatch(10, ' lote-sw-01 ', $today->modify('+2 days'));
    assertProductLoadingCondition(
        $dispatchedLine->isDispatched()
            && $dispatchedLine->getDispatchedQty() === 10
            && $dispatchedLine->getBatchCode() === 'LOTE-SW-01'
            && $line->getDispatchedQty() === null,
        'withDispatch normalizes the lot and returns a copy without mutating the original'
    );
    assertProductLoadingCondition(
        $line->withDispatch(1, '   ', null)->getBatchCode() === null,
        'A blank batch code is normalized to null before dispatch'
    );

    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(
            id: null,
            orderId: 100,
            productId: 7,
            vanStockBeforeReconcile: 20
        ),
        'A partial reconciliation is rejected: the six fields are all or nothing'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(
            id: null,
            orderId: 100,
            productId: 7,
            vanStockBeforeReconcile: 20,
            restockedInMachinesQty: 12,
            withdrawnSpoilageQty: 0,
            expectedReturnQty: 5,
            countedReturnQty: 7,
            discrepancyQty: 2
        ),
        'An expected return that breaks the arithmetic identity is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(
            id: null,
            orderId: 100,
            productId: 7,
            vanStockBeforeReconcile: 20,
            restockedInMachinesQty: 18,
            withdrawnSpoilageQty: 5,
            expectedReturnQty: -3,
            countedReturnQty: 0,
            discrepancyQty: 3
        ),
        'Restocked plus withdrawn above the previous balance is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(
            id: null,
            orderId: 100,
            productId: 7,
            vanStockBeforeReconcile: 20,
            restockedInMachinesQty: 12,
            withdrawnSpoilageQty: 0,
            expectedReturnQty: 8,
            countedReturnQty: -1,
            discrepancyQty: -9
        ),
        'A negative counted return is rejected'
    );
    assertProductLoadingThrows(
        static fn() => new ProductLoadingOrderItem(
            id: null,
            orderId: 100,
            productId: 7,
            vanStockBeforeReconcile: 20,
            restockedInMachinesQty: 12,
            withdrawnSpoilageQty: 0,
            expectedReturnQty: 8,
            countedReturnQty: 7,
            discrepancyQty: 0
        ),
        'A discrepancy that does not match counted minus expected is rejected'
    );

    $sealedLine = $line->sealedAtZero();
    assertProductLoadingCondition(
        $sealedLine->hasReconcileData()
            && $sealedLine->getVanStockBeforeReconcile() === 0
            && $sealedLine->getExpectedReturnQty() === 0
            && $sealedLine->getDiscrepancyQty() === 0,
        'sealedAtZero closes a non-dispatched line with six zeros (QA 4)'
    );

    $reconciledLine = $dispatchedLine->reconciled(20, 12, 0, 7);
    assertProductLoadingCondition(
        $reconciledLine->getVanStockBeforeReconcile() === 20
            && $reconciledLine->getRestockedInMachinesQty() === 12
            && $reconciledLine->getExpectedReturnQty() === 8
            && $reconciledLine->getCountedReturnQty() === 7
            && $reconciledLine->getDiscrepancyQty() === -1
            && $reconciledLine->hasDiscrepancy(),
        'reconciled computes expected return 8 and discrepancy -1 (EARS 04.2)'
    );
    assertProductLoadingCondition(
        $dispatchedLine->getExpectedReturnQty() === null,
        'reconciled returns a copy without mutating the dispatched line'
    );
    assertProductLoadingThrows(
        static fn() => $dispatchedLine->reconciled(20, 18, 5, 0),
        'reconciled rejects restocked plus withdrawn above the previous balance'
    );

    $inactiveLine = $dispatchedLine->withActive(false);
    assertProductLoadingCondition(
        !$inactiveLine->isActive() && $dispatchedLine->isActive(),
        'withActive gives the line a logical withdrawal without deleting it (C1)'
    );
    assertProductLoadingCondition(
        $line->withId(301)->getId() === 301,
        'withId assigns the persisted identifier to the line copy'
    );

    $lineRow = ProductLoadingOrderItem::fromDatabaseRow([
        'id' => '301',
        'order_id' => '100',
        'product_id' => '7',
        'reason' => 'LOW_STOCK_ALERT',
        'requested_qty' => '24',
        'dispatched_qty' => '24',
        'batch_code' => 'lote-sw-02',
        'expiration_date' => '2026-11-20',
        'van_stock_before_reconcile' => '24',
        'restocked_in_machines_qty' => '20',
        'withdrawn_spoilage_qty' => '2',
        'expected_return_qty' => '2',
        'counted_return_qty' => '1',
        'discrepancy_qty' => '-1',
        'is_active' => 'f',
    ]);
    assertProductLoadingCondition(
        $lineRow->getReason() === ProductLoadingItemReason::LOW_STOCK_ALERT
            && $lineRow->getBatchCode() === 'LOTE-SW-02'
            && $lineRow->getDiscrepancyQty() === -1
            && !$lineRow->isActive(),
        'fromDatabaseRow restores a reconciled line with driver-native values'
    );
    assertProductLoadingCondition(
        $lineRow->toArray()['reason_label'] === 'Alerta de Stock Bajo'
            && $lineRow->toArray()['reason_priority'] === 2
            && $lineRow->toArray()['has_discrepancy'] === true,
        'ProductLoadingOrderItem projects labels, priority and reconciliation flags'
    );
    assertProductLoadingCondition(
        json_decode((string)json_encode($lineRow, JSON_THROW_ON_ERROR), true)['discrepancy_qty'] === -1,
        'ProductLoadingOrderItem serializes to JSON'
    );

    echo "Product loading domain model tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Product loading domain model test failed: {$exception->getMessage()}\n");
    exit(1);
}
