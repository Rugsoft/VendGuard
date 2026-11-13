<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Core\Domain\Enum\ProductLoadingDemandMode;
use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\ProductLoadingOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Service\ProductLoadingValidator;

/**
 * ProductLoadingOrderService
 *
 * Casos de uso de la propuesta matutina, emisión, preparación, despacho con
 * cerrojo de frío, recepción móvil, liquidación y cancelación de órdenes de
 * carga (Módulo 33; RF-02–RF-04; D1–D4; C1–C4; QA 1–QA 4; Art. II, III.3, V.1
 * y V.4).
 *
 * QUÉ HACE
 *
 *  1. **Propuesta (`buildProposal`).** Compone la demanda de los huecos del
 *     repositorio —ya filtrada por `QA 1`— con el modo de demanda, el colchón y
 *     el stock útil de la furgoneta. La fórmula es la del EARS 02.1:
 *     `suggested = min(99999, 99999 - total_van_stock, max(0, ceil(gap * (1 +
 *     buffer/100)) - usable_van_stock))`. El motivo se clasifica por la mayor
 *     urgencia de los carriles afectados.
 *  2. **Emisión individual y en lote (`createOrder`, `createBatchOrders`).**
 *     Valida destinatario activo `RESTOCKER` (`C2`), una sola orden activa
 *     (`D3`, `409`) y líneas con producto activo (`C3`). El lote recorre la
 *     plantilla activa y devuelve el desglose de creadas y omitidas con causa.
 *  3. **Edición (`updateOrder`).** Solo en `PENDING_PICKING`; reemplaza por
 *     `UPSERT` y baja lógica, nunca `DELETE` (`C1`, Art. III.1).
 *  4. **Hoja A4 (`getPickingSheet`).** Dos secciones —cámara frigorífica y
 *     almacén general—, sin precio ni coste (Art. V.4).
 *  5. **Despacho (`dispatchOrder`).** Cerrojo térmico y de lote de
 *     `ProductLoadingValidator`, colisión de fecha (`QA 3`) y tope proyectado
 *     de furgoneta.
 *  6. **Recepción y liquidación (`confirmOrder`, `reconcileOrder`).** La
 *     recepción incrementa el stock por lote en una transacción; la liquidación
 *     lee el saldo real bajo bloqueo, sella las líneas no despachadas en `0`
 *     (`QA 4`) y deja `quantity_available` en el retorno contado.
 *  7. **Cancelación (`cancelOrder`).** Justificación de 20 caracteres reales y
 *     auditoría, sin tocar el stock.
 *
 * El servicio no accede a `unit_cost_eur` en ninguna proyección: la Hoja A4 y
 * todas las respuestas se construyen campo a campo (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class ProductLoadingOrderService
{
    public const AUDIT_ENTITY_TYPE = AuditEvent::ENTITY_PRODUCT_LOADING_ORDER;

    public const AUDIT_CREATED = 'PRODUCT_LOADING_ORDER_CREATED';
    public const AUDIT_UPDATED = 'PRODUCT_LOADING_ORDER_UPDATED';
    public const AUDIT_DISPATCHED = 'PRODUCT_LOADING_ORDER_DISPATCHED';
    public const AUDIT_CONFIRMED = 'PRODUCT_LOADING_ORDER_CONFIRMED';
    public const AUDIT_RECONCILED = 'PRODUCT_LOADING_ORDER_RECONCILED';
    public const AUDIT_CANCELLED = 'PRODUCT_LOADING_ORDER_CANCELLED';

    public const SKIP_ABSENT = 'ABSENT';
    public const SKIP_ACTIVE_ORDER = 'ACTIVE_ORDER_EXISTS';
    public const SKIP_NO_DEMAND = 'NO_DEMAND';
    public const SKIP_INVALID_RESTOCKER = 'INVALID_RESTOCKER';

    private AuditLogger $auditLogger;

    /**
     * @param (Closure(): string)|null $orderCodeGenerator Generador inyectable para
     *        pruebas o para una secuencia corporativa; por defecto usa marca temporal
     *        y entropía.
     */
    public function __construct(
        private readonly ProductLoadingOrderRepositoryInterface $orderRepository,
        private readonly RestockerVanStockRepositoryInterface $stockRepository,
        private readonly VendingProductRepositoryInterface $productRepository,
        private readonly UserRepositoryInterface $userRepository,
        ?AuditLogger $auditLogger = null,
        private readonly ?PDO $pdo = null,
        private readonly ?Closure $orderCodeGenerator = null
    ) {
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Propuesta matutina individual (RF-02.1, D1, QA 1).
     *
     * @return array<string, mixed>
     */
    public function buildProposal(
        int $restockerId,
        string $demandMode = 'ALERTS_ONLY',
        int $safetyBufferPct = 10,
        ?DateTimeImmutable $reference = null
    ): array {
        $mode = ProductLoadingDemandMode::fromString($demandMode);
        $this->assertSafetyBuffer($safetyBufferPct);

        $inputs = $this->orderRepository->fetchProposalInputs($restockerId, $reference);
        $restocker = $inputs['restocker'];

        if ($restocker === null) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'no existe, no está activo o su rol no es RESTOCKER.'
            );
        }

        return $this->buildProposalFromInputs($restocker, $inputs, $mode, $safetyBufferPct, $reference);
    }

    /**
     * Emisión individual de una orden (EARS 02.3).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function createOrder(int $restockerId, array $payload, array $actor): array
    {
        $createdBy = $this->actorId($actor);
        $mode = ProductLoadingDemandMode::fromString((string)($payload['demand_mode'] ?? 'ALERTS_ONLY'));
        $buffer = $this->readInteger($payload['safety_buffer_pct'] ?? 10, 'safety_buffer_pct', 0, 50);
        $notes = $this->optionalText($payload['notes'] ?? null, 'notes');

        $restocker = $this->requireActiveRestocker($restockerId);
        $active = $this->orderRepository->findActiveByRestocker($restockerId);
        if ($active !== null) {
            throw ProductLoadingValidationException::activeLoadingOrderExists(
                $restockerId,
                $active->getOrderCode()
            );
        }

        if (array_key_exists('items', $payload)) {
            if (!is_array($payload['items'])) {
                throw ProductLoadingValidationException::emptyLoadingOrderItems();
            }
            $drafts = $this->normalizeDraftItems($payload['items']);
        } else {
            $proposal = $this->buildProposal($restockerId, $mode->value, $buffer);
            $drafts = $this->proposalDrafts($proposal);
        }

        if ($drafts === []) {
            throw ProductLoadingValidationException::emptyLoadingOrderItems();
        }

        $order = new ProductLoadingOrder(
            null,
            $this->nextOrderCode(),
            $restockerId,
            $createdBy,
            ProductLoadingOrderStatus::PENDING_PICKING,
            $mode,
            $buffer,
            null,
            $notes
        );

        return $this->transaction(function () use ($order, $drafts, $actor, $restocker): array {
            try {
                $created = $this->orderRepository->createOrder($order, $drafts);
            } catch (InvalidArgumentException $exception) {
                if (str_contains($exception->getMessage(), 'ACTIVE_LOADING_ORDER_EXISTS')) {
                    throw ProductLoadingValidationException::activeLoadingOrderExists(
                        $order->getRestockerId(),
                        'ya tiene una orden activa.'
                    );
                }

                throw $exception;
            }

            $persisted = $created['order'];
            $items = $created['items'];
            $this->auditOrder(
                $persisted,
                self::AUDIT_CREATED,
                $actor,
                null,
                array_merge($persisted->toArray(), ['items' => $items]),
                ['restocker_name' => $restocker->getName()]
            );

            return $this->buildDetail($persisted, $items);
        });
    }

    /**
     * Emisión masiva para toda la plantilla activa (EARS 02.3.3, C2).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array{created: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function createBatchOrders(array $payload, array $actor): array
    {
        $this->actorId($actor);
        $mode = ProductLoadingDemandMode::fromString((string)($payload['demand_mode'] ?? 'ALERTS_ONLY'));
        $buffer = $this->readInteger($payload['safety_buffer_pct'] ?? 10, 'safety_buffer_pct', 0, 50);

        $created = [];
        $skipped = [];
        $restockers = $this->userRepository->findAll([
            'status' => 'active',
            'role' => UserRole::RESTOCKER->value,
        ]);

        foreach ($restockers as $row) {
            $restockerId = (int)($row['id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }

            $inputs = $this->orderRepository->fetchProposalInputs($restockerId);
            $restocker = $inputs['restocker'];

            if ($restocker === null) {
                $skipped[] = $this->skip($restockerId, (string)($row['name'] ?? ''), self::SKIP_INVALID_RESTOCKER);
                continue;
            }

            if ((bool)($restocker['is_currently_absent'] ?? false)) {
                $skipped[] = $this->skip($restockerId, (string)$restocker['name'], self::SKIP_ABSENT);
                continue;
            }

            if (($restocker['open_order_id'] ?? null) !== null) {
                $skipped[] = $this->skip(
                    $restockerId,
                    (string)$restocker['name'],
                    self::SKIP_ACTIVE_ORDER,
                    ['order_id' => (int)$restocker['open_order_id']]
                );
                continue;
            }

            $proposal = $this->buildProposalFromInputs($restocker, $inputs, $mode, $buffer);
            if ($proposal['items'] === []) {
                $skipped[] = $this->skip($restockerId, (string)$restocker['name'], self::SKIP_NO_DEMAND);
                continue;
            }

            try {
                $order = $this->createOrder($restockerId, [
                    'demand_mode' => $mode->value,
                    'safety_buffer_pct' => $buffer,
                    'items' => $this->proposalDrafts($proposal),
                ], $actor);
                $created[] = [
                    'order_id' => (int)$order['order']['id'],
                    'order_code' => (string)$order['order']['order_code'],
                    'restocker_id' => $restockerId,
                    'restocker_name' => (string)$restocker['name'],
                    'items_count' => count($order['items']),
                    'total_requested_qty' => array_sum(array_map(
                        static fn (array $item): int => (int)$item['requested_qty'],
                        $order['items']
                    )),
                ];
            } catch (ProductLoadingValidationException $exception) {
                $reason = $exception->getErrorCode() === ProductLoadingValidationException::ACTIVE_LOADING_ORDER_EXISTS
                    ? self::SKIP_ACTIVE_ORDER
                    : self::SKIP_INVALID_RESTOCKER;
                $skipped[] = $this->skip($restockerId, (string)$restocker['name'], $reason);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Listado de Coordinación con KPIs (EARS 05.1).
     *
     * @return array{kpis: array<string, int>, orders: list<array<string, mixed>>}
     */
    public function listOrders(
        ?string $status = null,
        ?int $restockerId = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        return [
            'kpis' => $this->orderRepository->summarizeStatusCounts(),
            'orders' => $this->orderRepository->findForCoordinator($status, $restockerId, $limit, $offset),
        ];
    }

    /**
     * Contexto de carga del bootstrap móvil del reponedor (RF-04, C4).
     *
     * Devuelve la orden activa con sus líneas y el comprobante de la última
     * orden liquidada. Ambas lecturas pasan por `getOrder()` con el reponedor
     * autenticado, de modo que el aislamiento horizontal se aplica también a
     * esta proyección y ninguna respuesta transporta `unit_cost_eur`.
     *
     * @return array{
     *   active_loading_order: array<string, mixed>|null,
     *   last_reconciled_order: array<string, mixed>|null
     * }
     */
    public function getRestockerLoadingContext(int $restockerId): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El reponedor autenticado debe ser un usuario existente.');
        }

        $active = $this->orderRepository->findActiveByRestocker($restockerId);
        $activeDetail = null;
        if ($active !== null && $active->getId() !== null) {
            $activeDetail = $this->getOrder($active->getId(), $restockerId);
        }

        $lastReconciled = null;
        foreach ($this->orderRepository->findForCoordinator(null, $restockerId, 50, 0) as $row) {
            if (($row['status'] ?? '') !== ProductLoadingOrderStatus::RECONCILED_AT_CLOSE->value) {
                continue;
            }

            $lastReconciled = $this->getOrder((int)$row['id'], $restockerId);
            break;
        }

        return [
            'active_loading_order' => $activeDetail,
            'last_reconciled_order' => $lastReconciled,
        ];
    }

    /**
     * Detalle completo con Hoja A4 (EARS 03.2).
     *
     * @return array<string, mixed>
     */
    public function getOrder(int $orderId, ?int $actingRestockerId = null): array
    {
        $data = $this->loadOrder($orderId, false, $actingRestockerId);

        return $this->buildDetail($data['order'], $data['items']);
    }

    /**
     * Hoja de Picking A4 en dos secciones, sin importes (EARS 03.2, Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function getPickingSheet(int $orderId, ?int $actingRestockerId = null): array
    {
        return $this->getOrder($orderId, $actingRestockerId)['picking_sheet'];
    }

    /**
     * Edición de una orden en preparación (EARS 03.1, C1).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function updateOrder(int $orderId, array $payload, array $actor): array
    {
        return $this->transaction(function () use ($orderId, $payload, $actor): array {
            $data = $this->loadOrder($orderId, true);
            $order = $data['order'];

            if (!$order->canBeEdited()) {
                throw ProductLoadingValidationException::invalidOrderStateTransition(
                    $order->getStatus()->value,
                    'update'
                );
            }

            if (!array_key_exists('items', $payload) || !is_array($payload['items'])) {
                throw ProductLoadingValidationException::emptyLoadingOrderItems($order->getOrderCode());
            }

            $drafts = $this->normalizeDraftItems($payload['items']);
            if ($drafts === []) {
                throw ProductLoadingValidationException::emptyLoadingOrderItems($order->getOrderCode());
            }

            $mode = array_key_exists('demand_mode', $payload)
                ? ProductLoadingDemandMode::fromString((string)$payload['demand_mode'])
                : $order->getDemandMode();
            $buffer = array_key_exists('safety_buffer_pct', $payload)
                ? $this->readInteger($payload['safety_buffer_pct'], 'safety_buffer_pct', 0, 50)
                : $order->getSafetyBufferPct();
            $notes = array_key_exists('notes', $payload)
                ? $this->optionalText($payload['notes'], 'notes')
                : $order->getNotes();

            $previous = $order->toArray();
            $planned = $order->withPlanning($mode, $buffer, $notes);
            $items = $this->orderRepository->replaceItems($orderId, $drafts);
            $persisted = $this->orderRepository->updateOrder($planned);

            $this->auditOrder(
                $persisted,
                self::AUDIT_UPDATED,
                $actor,
                $previous,
                array_merge($persisted->toArray(), ['items' => $items])
            );

            return $this->buildDetail($persisted, $items);
        });
    }

    /**
     * Despacho con cerrojo sanitario (EARS 03.3, D2, QA 2, QA 3).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function dispatchOrder(int $orderId, array $payload, array $actor): array
    {
        $dispatchedBy = $this->actorId($actor);

        return $this->transaction(function () use ($orderId, $payload, $actor, $dispatchedBy): array {
            $data = $this->loadOrder($orderId, true);
            $order = $data['order'];
            $rows = $data['items'];

            if (!$order->canBeDispatched()) {
                throw ProductLoadingValidationException::invalidOrderStateTransition(
                    $order->getStatus()->value,
                    'dispatch'
                );
            }

            $drafts = $this->indexByProduct($payload['items'] ?? []);
            $temperature = $this->readTemperature($payload['cold_chamber_exit_temp_celsius'] ?? null);
            $entries = [];
            $previous = $order->toArray();
            $now = $this->now();

            foreach ($rows as $row) {
                $item = ProductLoadingOrderItem::fromDatabaseRow($row);
                if (!$item->isActive()) {
                    continue;
                }

                $product = $this->requireProduct($item->getProductId());
                $draft = $drafts[$item->getProductId()] ?? [];
                $quantity = array_key_exists('dispatched_qty', $draft)
                    ? $this->readInteger($draft['dispatched_qty'], 'dispatched_qty', 0, 99999)
                    : 0;
                $batch = ProductLoadingValidator::normalizeBatchCode(
                    isset($draft['batch_code']) && is_string($draft['batch_code']) ? $draft['batch_code'] : null,
                    $product->requiresColdChain() && $quantity > 0
                );
                $expiration = $this->parseExpiration($draft['expiration_date'] ?? null);

                $updated = $item->withDispatch($quantity, $batch, $expiration, $now);
                $entries[] = ['item' => $updated, 'product' => $product];
            }

            ProductLoadingValidator::assertDispatch($order, $entries, $temperature, $this->today());

            foreach ($entries as $entry) {
                $item = $entry['item'];
                if (!$item->isDispatched()) {
                    continue;
                }

                $this->assertLotCoherence(
                    $order->getRestockerId(),
                    $item->getProductId(),
                    (string)$item->getBatchCode(),
                    $item->getExpirationDate()
                );
                $this->assertProjectedStock(
                    $order->getRestockerId(),
                    $item->getProductId(),
                    (string)$item->getBatchCode(),
                    (int)$item->getDispatchedQty()
                );
            }

            $dispatched = $order->withDispatchData($temperature, $dispatchedBy, $now);
            $persistedItems = [];
            foreach ($entries as $entry) {
                $persistedItems[] = $this->orderRepository->updateItem($entry['item'])->toArray();
            }
            $persisted = $this->orderRepository->updateOrder($dispatched);

            $this->auditOrder(
                $persisted,
                self::AUDIT_DISPATCHED,
                $actor,
                $previous,
                array_merge($persisted->toArray(), ['items' => $persistedItems]),
                ['cold_chamber_exit_temp_celsius' => $temperature]
            );

            $refreshed = $this->orderRepository->findById($orderId);

            return $this->buildDetail($persisted, $refreshed['items'] ?? $persistedItems);
        });
    }

    /**
     * Recepción móvil en un toque (EARS 04.1, D3).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function confirmOrder(int $orderId, int $restockerId, array $actor): array
    {
        return $this->transaction(function () use ($orderId, $restockerId, $actor): array {
            $data = $this->loadOrder($orderId, true, $restockerId);
            $order = $data['order'];

            if (!$order->canBeConfirmed()) {
                throw ProductLoadingValidationException::invalidOrderStateTransition(
                    $order->getStatus()->value,
                    'confirm'
                );
            }

            $now = $this->now();
            $previous = $order->toArray();
            $increments = [];

            foreach ($data['items'] as $row) {
                $item = ProductLoadingOrderItem::fromDatabaseRow($row);
                if (!$item->isActive() || !$item->isDispatched()) {
                    continue;
                }

                $product = $this->requireProduct($item->getProductId());
                $batch = $item->getBatchCode() ?? RestockerVanProductStock::DEFAULT_BATCH_CODE;

                if ($product->requiresColdChain()) {
                    ProductLoadingValidator::normalizeBatchCode($batch, true);
                    if ($item->getExpirationDate() === null
                        || $item->getExpirationDate()->setTime(0, 0) <= $this->today()) {
                        throw ProductLoadingValidationException::expiredOrInvalidBatch(
                            'la orden recibida declara una línea de frío sin caducidad futura.',
                            ['product_id' => $item->getProductId(), 'batch_code' => $batch]
                        );
                    }
                }

                $existing = $this->stockRepository->findLot(
                    $order->getRestockerId(),
                    $item->getProductId(),
                    $batch,
                    true
                );

                $this->assertLotCoherence(
                    $order->getRestockerId(),
                    $item->getProductId(),
                    $batch,
                    $item->getExpirationDate(),
                    $existing
                );

                $newQuantity = ($existing?->getQuantityAvailable() ?? 0) + (int)$item->getDispatchedQty();
                if ($newQuantity > RestockerVanProductStock::MAX_QUANTITY) {
                    throw ProductLoadingValidationException::invalidLoadingQuantity(
                        'quantity_available',
                        $newQuantity,
                        0,
                        RestockerVanProductStock::MAX_QUANTITY
                    );
                }

                $stock = new RestockerVanProductStock(
                    $existing?->getId(),
                    $order->getRestockerId(),
                    $item->getProductId(),
                    $batch,
                    $item->getExpirationDate(),
                    $newQuantity,
                    $product->requiresColdChain()
                );
                $persistedStock = $this->stockRepository->upsertLot($stock);

                $this->auditStock(
                    $persistedStock,
                    'RESTOCKER_VAN_STOCK_RECEIVED',
                    $actor,
                    $existing?->toArray(),
                    $persistedStock->toArray(),
                    ['order_id' => $orderId, 'order_code' => $order->getOrderCode()]
                );

                $increments[] = [
                    'product_id' => $item->getProductId(),
                    'batch_code' => $batch,
                    'quantity' => (int)$item->getDispatchedQty(),
                    'quantity_after' => $newQuantity,
                ];
            }

            $confirmed = $order->withConfirmation($now);
            $persisted = $this->orderRepository->updateOrder($confirmed);
            $this->auditOrder(
                $persisted,
                self::AUDIT_CONFIRMED,
                $actor,
                $previous,
                $persisted->toArray(),
                ['stock_increments' => $increments]
            );

            return $this->buildDetail($persisted, $data['items']);
        });
    }

    /**
     * Liquidación de cierre con cuadre por lote (EARS 04.2, D3, QA 4).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function reconcileOrder(
        int $orderId,
        array $payload,
        array $actor,
        ?int $actingRestockerId = null
    ): array {
        $reconciledBy = $this->actorId($actor);

        return $this->transaction(function () use ($orderId, $payload, $actor, $actingRestockerId, $reconciledBy): array {
            $data = $this->loadOrder($orderId, true, $actingRestockerId);
            $order = $data['order'];

            if (!$order->canBeReconciled()) {
                throw ProductLoadingValidationException::invalidOrderStateTransition(
                    $order->getStatus()->value,
                    'reconcile'
                );
            }

            $drafts = $this->indexByProduct($payload['items'] ?? []);
            $entries = [];
            $stocks = [];

            foreach ($data['items'] as $row) {
                $item = ProductLoadingOrderItem::fromDatabaseRow($row);
                if (!$item->isActive()) {
                    continue;
                }

                if (!$item->isDispatched()) {
                    $entries[] = ['item' => $item];
                    continue;
                }

                $batch = $item->getBatchCode() ?? RestockerVanProductStock::DEFAULT_BATCH_CODE;
                $stock = $this->stockRepository->findLot(
                    $order->getRestockerId(),
                    $item->getProductId(),
                    $batch,
                    true
                );
                if ($stock === null) {
                    throw new InvalidArgumentException(sprintf(
                        'La línea %d no tiene saldo de lote en la furgoneta para liquidar.',
                        $item->getProductId()
                    ));
                }

                $draft = $drafts[$item->getProductId()] ?? [];
                $entries[] = [
                    'item' => $item,
                    'van_stock_before_reconcile' => $stock->getQuantityAvailable(),
                    'restocked_in_machines_qty' => $draft['restocked_in_machines_qty'] ?? null,
                    'withdrawn_spoilage_qty' => $draft['withdrawn_spoilage_qty'] ?? null,
                    'counted_return_qty' => $draft['counted_return_qty'] ?? null,
                ];
                $stocks[$item->getProductId()] = $stock;
            }

            $reconciliation = ProductLoadingValidator::assertReconciliation(
                $order,
                $entries,
                isset($payload['reconciliation_notes']) && is_string($payload['reconciliation_notes'])
                    ? $payload['reconciliation_notes']
                    : null
            );

            $now = $this->now();
            $previous = $order->toArray();
            $persistedItems = [];
            $stockSummary = [];

            foreach ($reconciliation['items'] as $row) {
                $original = $row['item'];
                $line = $row['line'];
                $persistedLine = $this->orderRepository->updateItem($line);
                $persistedItems[] = $persistedLine->toArray();

                if (!$original->isDispatched()) {
                    continue;
                }

                $stock = $stocks[$original->getProductId()] ?? null;
                if ($stock === null) {
                    throw new InvalidArgumentException('No se encontró el saldo del lote durante la liquidación.');
                }

                $newQuantity = (int)$line->getCountedReturnQty();
                $updatedStock = new RestockerVanProductStock(
                    $stock->getId(),
                    $stock->getRestockerId(),
                    $stock->getProductId(),
                    $stock->getBatchCode(),
                    $stock->getExpirationDate(),
                    $newQuantity,
                    $this->requireProduct($stock->getProductId())->requiresColdChain()
                );
                $persistedStock = $this->stockRepository->upsertLot($updatedStock);
                $stockSummary[] = [
                    'product_id' => $stock->getProductId(),
                    'batch_code' => $stock->getBatchCode(),
                    'quantity_before' => $stock->getQuantityAvailable(),
                    'quantity_after' => $persistedStock->getQuantityAvailable(),
                    'discrepancy_qty' => $line->getDiscrepancyQty(),
                ];
                $this->auditStock(
                    $persistedStock,
                    'RESTOCKER_VAN_STOCK_RECONCILED',
                    $actor,
                    $stock->toArray(),
                    $persistedStock->toArray(),
                    [
                        'order_id' => $orderId,
                        'order_code' => $order->getOrderCode(),
                        'discrepancy_qty' => $line->getDiscrepancyQty(),
                    ]
                );
            }

            $reconciled = $order->withReconciliationData(
                $reconciledBy,
                $now,
                $reconciliation['reconciliation_notes']
            );
            $persisted = $this->orderRepository->updateOrder($reconciled);
            $this->auditOrder(
                $persisted,
                self::AUDIT_RECONCILED,
                $actor,
                $previous,
                array_merge($persisted->toArray(), ['items' => $persistedItems]),
                [
                    'discrepancy_lines_count' => $reconciliation['discrepancy_lines_count'],
                    'stock' => $stockSummary,
                ]
            );

            return $this->buildDetail($persisted, $persistedItems);
        });
    }

    /**
     * Cancelación justificada (EARS 03.4, Art. V.1).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function cancelOrder(int $orderId, ?string $reason, array $actor): array
    {
        return $this->transaction(function () use ($orderId, $reason, $actor): array {
            $data = $this->loadOrder($orderId, true);
            $order = $data['order'];
            $normalizedReason = ProductLoadingValidator::assertCancellation($order, $reason);
            $now = $this->now();
            $previous = $order->toArray();
            $cancelled = $order->withCancellation($normalizedReason, $now);
            $persisted = $this->orderRepository->updateOrder($cancelled);

            $this->auditOrder(
                $persisted,
                self::AUDIT_CANCELLED,
                $actor,
                $previous,
                $persisted->toArray(),
                ['cancel_reason' => $normalizedReason]
            );

            return $this->buildDetail($persisted, $data['items']);
        });
    }

    /**
     * @param array<string, mixed> $restocker
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    private function buildProposalFromInputs(
        array $restocker,
        array $inputs,
        ProductLoadingDemandMode $mode,
        int $safetyBufferPct,
        ?DateTimeImmutable $reference = null
    ): array {
        if (!(bool)($restocker['is_active'] ?? true)) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                (int)$restocker['id'],
                'la cuenta está inactiva.'
            );
        }

        if ((bool)($restocker['is_currently_absent'] ?? false)) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                (int)$restocker['id'],
                'tiene una ausencia vigente hoy.'
            );
        }

        $restockerId = (int)$restocker['id'];
        $stockRows = $this->stockRepository->findByRestocker($restockerId);
        $totalVanStock = array_sum(array_map(
            static fn (array $row): int => (int)$row['quantity_available'],
            $stockRows
        ));
        $usableStock = $this->stockRepository->sumUsableQuantityByProduct($restockerId, $reference);
        $usableTotal = array_sum($usableStock);

        $items = [];
        foreach ($inputs['slot_demand'] as $row) {
            $gap = $mode->includesAllGaps()
                ? (int)($row['gap_qty_all'] ?? 0)
                : (int)($row['gap_qty_alerts'] ?? 0);

            if ($gap <= 0) {
                continue;
            }

            $gross = (int)ceil($gap * (1 + $safetyBufferPct / 100));
            $productId = (int)$row['product_id'];
            $available = (int)($usableStock[$productId] ?? 0);
            $suggested = min(
                99999,
                99999 - $totalVanStock,
                max(0, $gross - $available)
            );

            if ($suggested <= 0) {
                continue;
            }

            $reason = $this->proposalReason($row, $mode);
            $items[] = [
                'product_id' => $productId,
                'product_sku' => (string)$row['product_sku'],
                'product_name' => (string)$row['product_name'],
                'product_category' => (string)$row['product_category'],
                'product_category_label' => (string)($row['product_category_label'] ?? ''),
                'product_unit_type' => (string)$row['product_unit_type'],
                'requires_cold_chain' => (bool)$row['requires_cold_chain'],
                'max_shelf_life_days' => $row['max_shelf_life_days'] === null
                    ? null
                    : (int)$row['max_shelf_life_days'],
                'gap_qty' => $gap,
                'gross_demand_qty' => $gross,
                'usable_van_stock_qty' => $available,
                'suggested_qty' => $suggested,
                'reason' => $reason->value,
                'reason_label' => $reason->label(),
                'reason_priority' => $reason->priority(),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ($left['requires_cold_chain'] !== $right['requires_cold_chain']) {
                return $left['requires_cold_chain'] ? -1 : 1;
            }

            return [$left['reason_priority'], $left['product_sku']]
                <=> [$right['reason_priority'], $right['product_sku']];
        });

        return [
            'restocker' => $restocker,
            'zones' => $inputs['zones'],
            'demand_mode' => $mode->value,
            'demand_mode_label' => $mode->label(),
            'safety_buffer_pct' => $safetyBufferPct,
            'total_van_stock_qty' => $totalVanStock,
            'usable_van_stock_qty' => $usableTotal,
            'excluded_unavailable_machines_count' => (int)$inputs['excluded_unavailable_machines_count'],
            'items' => $items,
            'total_suggested_qty' => array_sum(array_column($items, 'suggested_qty')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function proposalReason(array $row, ProductLoadingDemandMode $mode): ProductLoadingItemReason
    {
        if ((int)($row['stockout_slots_count'] ?? 0) > 0) {
            return ProductLoadingItemReason::STOCKOUT_ALERT;
        }

        if ((int)($row['low_stock_slots_count'] ?? 0) > 0) {
            return ProductLoadingItemReason::LOW_STOCK_ALERT;
        }

        return $mode->includesAllGaps()
            ? ProductLoadingItemReason::ROUTE_GAP_FILL
            : ProductLoadingItemReason::LOW_STOCK_ALERT;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{product_id: int, reason: string, requested_qty: int}>
     */
    private function normalizeDraftItems(array $items): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw ProductLoadingValidationException::emptyLoadingOrderItems();
            }

            $productId = $this->readInteger($item['product_id'] ?? null, 'product_id', 1, 999999999);
            if (isset($seen[$productId])) {
                throw new InvalidArgumentException(sprintf(
                    'El producto %d no puede repetirse en la misma orden de carga.',
                    $productId
                ));
            }
            $seen[$productId] = true;

            $product = $this->requireProduct($productId);
            if (!$product->isActive()) {
                throw ProductLoadingValidationException::productInactive($productId, $product->getSku());
            }

            $quantity = $this->readInteger(
                $item['requested_qty'] ?? null,
                'requested_qty',
                ProductLoadingOrderItem::MIN_REQUESTED_QTY,
                ProductLoadingOrderItem::MAX_REQUESTED_QTY
            );
            $reason = ProductLoadingItemReason::fromString((string)($item['reason'] ?? 'MANUAL_ADDITION'));

            $normalized[] = [
                'product_id' => $productId,
                'reason' => $reason->value,
                'requested_qty' => $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $proposal
     *
     * @return list<array{product_id: int, reason: string, requested_qty: int}>
     */
    private function proposalDrafts(array $proposal): array
    {
        return array_map(
            static fn (array $item): array => [
                'product_id' => (int)$item['product_id'],
                'reason' => (string)$item['reason'],
                'requested_qty' => (int)$item['suggested_qty'],
            ],
            $proposal['items']
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<int, array<string, mixed>>
     */
    private function indexByProduct(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $indexed = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId > 0) {
                $indexed[$productId] = $item;
            }
        }

        return $indexed;
    }

    /**
     * @return array{order: ProductLoadingOrder, items: list<array<string, mixed>>}
     */
    private function loadOrder(int $orderId, bool $forUpdate, ?int $actingRestockerId = null): array
    {
        $data = $this->orderRepository->findById($orderId, $forUpdate);
        if ($data === null) {
            throw ProductLoadingValidationException::loadingOrderNotFound($orderId);
        }

        if ($actingRestockerId !== null && $data['order']->getRestockerId() !== $actingRestockerId) {
            throw ProductLoadingValidationException::forbiddenLoadingOrderAccess(
                $orderId,
                $actingRestockerId
            );
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildDetail(ProductLoadingOrder $order, array $items): array
    {
        $restocker = $this->userRepository->findById($order->getRestockerId(), false, false);
        $zones = [];
        try {
            $inputs = $this->orderRepository->fetchProposalInputs($order->getRestockerId());
            $zones = $inputs['zones'];
        } catch (Throwable) {
            $zones = [];
        }

        $orderRow = array_merge($order->toArray(), [
            'restocker_name' => $restocker?->getName() ?? '',
            'restocker_operator_code' => $restocker?->getOperatorCode(),
            'restocker_is_active' => $restocker?->isActive() ?? false,
            'zones' => $zones,
        ]);

        return [
            'order' => $orderRow,
            'items' => $items,
            'picking_sheet' => $this->buildPickingSheet($orderRow, $items),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildPickingSheet(array $order, array $items): array
    {
        $cold = [];
        $general = [];

        foreach ($items as $item) {
            if (!(bool)($item['is_active'] ?? false)) {
                continue;
            }

            $row = [
                'product_id' => (int)$item['product_id'],
                'sku' => (string)($item['product_sku'] ?? ''),
                'name' => (string)($item['product_name'] ?? ''),
                'unit_type' => (string)($item['product_unit_type'] ?? ''),
                'batch_code' => $item['batch_code'] ?? null,
                'expiration_date' => $item['expiration_date'] ?? null,
                'requested_qty' => (int)$item['requested_qty'],
                'dispatched_qty' => $item['dispatched_qty'] === null ? null : (int)$item['dispatched_qty'],
                'reason' => (string)$item['reason'],
                'reason_label' => (string)($item['reason_label'] ?? ''),
                'checkbox' => false,
            ];

            if ((bool)($item['requires_cold_chain'] ?? false)) {
                $cold[] = $row;
            } else {
                $general[] = $row;
            }
        }

        return [
            'order_code' => (string)$order['order_code'],
            'created_at' => $order['created_at'] ?? null,
            'restocker_name' => (string)($order['restocker_name'] ?? ''),
            'restocker_operator_code' => $order['restocker_operator_code'] ?? null,
            'zones' => $order['zones'] ?? [],
            'demand_mode' => (string)$order['demand_mode'],
            'demand_mode_label' => (string)($order['demand_mode_label'] ?? ''),
            'safety_buffer_pct' => (int)$order['safety_buffer_pct'],
            'status' => (string)$order['status'],
            'status_label' => (string)($order['status_label'] ?? ''),
            'cold_chamber_exit_temp_celsius' => $order['cold_chamber_exit_temp_celsius'] ?? null,
            'sections' => [
                'cold_chain' => [
                    'title' => '❄️ Cámara Frigorífica (Salida ≤ 4,0 °C · Art. II)',
                    'items' => $cold,
                ],
                'general' => [
                    'title' => '📦 Almacén General y Consumibles',
                    'items' => $general,
                ],
            ],
            'totals' => [
                'cold_items_count' => count($cold),
                'general_items_count' => count($general),
                'total_requested_qty' => array_sum(array_column($cold, 'requested_qty'))
                    + array_sum(array_column($general, 'requested_qty')),
                'total_dispatched_qty' => array_sum(array_map(
                    static fn (array $row): int => (int)($row['dispatched_qty'] ?? 0),
                    array_merge($cold, $general)
                )),
            ],
        ];
    }

    private function requireActiveRestocker(int $restockerId)
    {
        $user = $this->userRepository->findById($restockerId, false, false);
        if ($user === null) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'el usuario no existe.'
            );
        }

        if ($user->getRole() !== UserRole::RESTOCKER) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'su rol no es RESTOCKER.'
            );
        }

        if (!$user->isActive()) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'la cuenta está inactiva.'
            );
        }

        return $user;
    }

    private function requireProduct(int $productId): VendingProduct
    {
        $product = $this->productRepository->findById($productId);
        if ($product === null) {
            throw new InvalidArgumentException(sprintf('El producto %d no existe.', $productId));
        }

        return $product;
    }

    private function assertSafetyBuffer(int $safetyBufferPct): void
    {
        if ($safetyBufferPct < ProductLoadingOrder::MIN_SAFETY_BUFFER_PCT
            || $safetyBufferPct > ProductLoadingOrder::MAX_SAFETY_BUFFER_PCT) {
            throw ProductLoadingValidationException::invalidLoadingQuantity(
                'safety_buffer_pct',
                $safetyBufferPct,
                ProductLoadingOrder::MIN_SAFETY_BUFFER_PCT,
                ProductLoadingOrder::MAX_SAFETY_BUFFER_PCT
            );
        }
    }

    private function assertLotCoherence(
        int $restockerId,
        int $productId,
        string $batchCode,
        ?DateTimeImmutable $expirationDate,
        ?RestockerVanProductStock $existing = null
    ): void {
        $lot = $existing ?? $this->stockRepository->findLot($restockerId, $productId, $batchCode, true);
        if ($lot === null) {
            return;
        }

        $existingDate = $lot->getExpirationDate()?->format('Y-m-d');
        $givenDate = $expirationDate?->format('Y-m-d');
        if ($existingDate !== $givenDate) {
            throw ProductLoadingValidationException::batchExpirationMismatch(
                $batchCode,
                $existingDate ?? 'sin fecha',
                $givenDate ?? 'sin fecha'
            );
        }
    }

    private function assertProjectedStock(
        int $restockerId,
        int $productId,
        string $batchCode,
        int $additional
    ): void {
        $lot = $this->stockRepository->findLot($restockerId, $productId, $batchCode);
        $projected = ($lot?->getQuantityAvailable() ?? 0) + $additional;
        if ($projected > RestockerVanProductStock::MAX_QUANTITY) {
            throw ProductLoadingValidationException::invalidLoadingQuantity(
                'dispatched_qty',
                $projected,
                0,
                RestockerVanProductStock::MAX_QUANTITY
            );
        }
    }

    private function readTemperature(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw ProductLoadingValidationException::coldChamberTempExceeded(null);
        }

        return round((float)$value, 1);
    }

    private function parseExpiration(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTime(0, 0);
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            $normalized = trim($value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
            if ($date !== false && $date->format('Y-m-d') === $normalized) {
                return $date;
            }
        }

        throw ProductLoadingValidationException::expiredOrInvalidBatch(
            'la fecha de caducidad no es una fecha real con formato YYYY-MM-DD.',
            ['field' => 'expiration_date']
        );
    }

    private function readInteger(mixed $value, string $field, int $minimum, int $maximum): int
    {
        $normalized = null;
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $normalized = (int)$value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $normalized = (int)trim($value);
        }

        if ($normalized === null || $normalized < $minimum || $normalized > $maximum) {
            throw ProductLoadingValidationException::invalidLoadingQuantity(
                $field,
                $value,
                $minimum,
                $maximum
            );
        }

        return $normalized;
    }

    private function optionalText(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('El campo «%s» debe ser texto.', $field));
        }

        $normalized = ProductLoadingValidator::normalizeText($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     */
    private function actorId(array $actor): int
    {
        $id = (int)($actor['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('La operación exige un usuario autenticado.');
        }

        return $id;
    }

    private function nextOrderCode(): string
    {
        if ($this->orderCodeGenerator !== null) {
            return ($this->orderCodeGenerator)();
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));

        return sprintf(
            'PLO-%s-%s%04d',
            $now->format('Y'),
            $now->format('Hisv'),
            random_int(0, 9999)
        );
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $new
     * @param array<string, mixed>|null $metadata
     */
    private function auditOrder(
        ProductLoadingOrder $order,
        string $action,
        array $actor,
        ?array $previous,
        ?array $new,
        ?array $metadata = null
    ): void {
        $this->auditLogger->logEvent(new AuditEvent(
            null,
            self::AUDIT_ENTITY_TYPE,
            (int)$order->getId(),
            $action,
            isset($actor['id']) ? (int)$actor['id'] : null,
            (string)($actor['role'] ?? 'COORDINATOR'),
            (string)($actor['name'] ?? 'Coordinación'),
            $previous,
            $new,
            $metadata
        ));
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $new
     * @param array<string, mixed>|null $metadata
     */
    private function auditStock(
        RestockerVanProductStock $stock,
        string $action,
        array $actor,
        ?array $previous,
        ?array $new,
        ?array $metadata = null
    ): void {
        $this->auditLogger->logEvent(new AuditEvent(
            null,
            AuditEvent::ENTITY_RESTOCKER_VAN_STOCK,
            (int)$stock->getId(),
            $action,
            isset($actor['id']) ? (int)$actor['id'] : null,
            (string)($actor['role'] ?? 'COORDINATOR'),
            (string)($actor['name'] ?? 'Coordinación'),
            $previous,
            $new,
            $metadata
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(int $restockerId, string $name, string $reason, array $extra = []): array
    {
        return array_merge([
            'restocker_id' => $restockerId,
            'restocker_name' => $name,
            'reason' => $reason,
        ], $extra);
    }

    /**
     * Ejecuta la composición en una transacción propia cuando hay PDO y no hay
     * una abierta; respeta la del llamador.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = $this->pdo !== null && !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function today(): DateTimeImmutable
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))->setTime(0, 0);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
            ->format(DateTimeImmutable::ATOM);
    }
}
