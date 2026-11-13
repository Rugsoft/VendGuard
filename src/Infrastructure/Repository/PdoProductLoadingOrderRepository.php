<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Repository\ProductLoadingOrderRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoProductLoadingOrderRepository
 *
 * Persistencia PDO de las órdenes de carga de furgoneta y sus líneas (Módulo 33,
 * Tablas 45 y 46, RF-02.1–RF-02.3, RF-03.1, RF-04.1, RF-04.2, D1–D4, C1, QA 1,
 * Art. III.1, Art. V.4).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Sin `DELETE` (C1, Art. III.1).** Editar una orden es `UPSERT` sobre
 *      `(order_id, product_id)` y baja lógica de las líneas ausentes; el fichero
 *      no contiene ninguna sentencia de borrado.
 *   2. **Bloqueo pesimista (D3).** `findById($id, true)` bloquea la cabecera y
 *      las líneas con `FOR UPDATE`; la confirmación, la liquidación y la
 *      cancelación de T-PLD-08 se serializan sobre la misma fila.
 *   3. **Hoja A4 determinista (D4).** Las líneas se leen con el orden canónico
 *      `requires_cold_chain DESC, prioridad del motivo, SKU ASC`.
 *   4. **Propuesta sin máquinas no operativas (QA 1).** Las máquinas en
 *      cuarentena sanitaria, certificado vencido, pausa estacional o sin acceso
 *      no aportan huecos y se cuentan aparte. La cobertura de zonas replica la
 *      regla del Módulo 31: titular sin ausencia vigente hoy o suplente sin
 *      ausencia cuando el titular está ausente o vacante.
 *   5. **Opacidad de costes (Art. V.4).** Ninguna proyección lee
 *      `unit_cost_eur`, precios ni márgenes.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoProductLoadingOrderRepository implements ProductLoadingOrderRepositoryInterface
{
    /** Estados que ocupan el hueco activo del reponedor (D3). */
    private const ACTIVE_STATUSES = "'PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER'";

    /**
     * Estados operativos que QA 1 excluye de la propuesta.
     *
     * El contrato funcional los nombra `SANITARY_QUARANTINE` y `OUT_OF_SERVICE`;
     * en el esquema se materializan como `sanitary_status` en cuarentena,
     * certificado vencido o pausa estacional, o como máquina sin acceso o en
     * pausa estacional explícita. Se excluye cualquiera de ellos: una máquina
     * precintada no debe sacar producto de cámara.
     */
    private const UNAVAILABLE_MACHINE_PREDICATE = "(
        m.sanitary_status IN ('QUARANTINE', 'EXPIRED', 'SEASONAL_PAUSE')
        OR m.is_blocked_no_access = TRUE
        OR m.is_seasonal_pause = TRUE
    )";

    /** Orden canónico de la Hoja de Picking A4 (D4, EARS 02.2). */
    private const ITEM_ORDER = "
        ORDER BY
            vp.requires_cold_chain DESC,
            CASE i.reason
                WHEN 'STOCKOUT_ALERT' THEN 1
                WHEN 'LOW_STOCK_ALERT' THEN 2
                WHEN 'ROUTE_GAP_FILL' THEN 3
                WHEN 'MANUAL_ADDITION' THEN 4
                ELSE 5
            END ASC,
            vp.sku ASC,
            i.id ASC
    ";

    private const ORDER_COLUMNS = "
        o.id,
        o.order_code,
        o.restocker_id,
        o.status,
        o.demand_mode,
        o.safety_buffer_pct,
        o.cold_chamber_exit_temp_celsius,
        o.notes,
        o.cancel_reason,
        o.reconciliation_notes,
        o.created_by,
        o.dispatched_by,
        o.reconciled_by,
        o.created_at,
        o.dispatched_at,
        o.confirmed_at,
        o.reconciled_at,
        o.cancelled_at,
        o.updated_at,
        u.name AS restocker_name,
        u.operator_code AS restocker_operator_code,
        u.is_active AS restocker_is_active
    ";

    private const ITEM_COLUMNS = "
        i.id,
        i.order_id,
        i.product_id,
        i.reason,
        i.requested_qty,
        i.dispatched_qty,
        i.batch_code,
        i.expiration_date,
        i.van_stock_before_reconcile,
        i.restocked_in_machines_qty,
        i.withdrawn_spoilage_qty,
        i.expected_return_qty,
        i.counted_return_qty,
        i.discrepancy_qty,
        i.is_active,
        i.created_at,
        i.updated_at,
        vp.sku AS product_sku,
        vp.name AS product_name,
        vp.category AS product_category,
        vp.unit_type AS product_unit_type,
        vp.requires_cold_chain,
        vp.max_shelf_life_days
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function createOrder(ProductLoadingOrder $order, array $items): array
    {
        if ($order->getId() !== null) {
            throw new InvalidArgumentException('Una orden nueva no puede llegar ya persistida.');
        }

        $normalized = $this->normalizeItemDrafts($items);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $active = $this->findActiveByRestocker($order->getRestockerId(), true);
            if ($active !== null) {
                throw new InvalidArgumentException(sprintf(
                    'ACTIVE_LOADING_ORDER_EXISTS: el reponedor %d ya tiene la orden %s activa.',
                    $order->getRestockerId(),
                    $active->getOrderCode()
                ));
            }

            $insert = $this->pdo->prepare("
                INSERT INTO product_loading_orders (
                    order_code,
                    restocker_id,
                    status,
                    demand_mode,
                    safety_buffer_pct,
                    cold_chamber_exit_temp_celsius,
                    notes,
                    created_by
                ) VALUES (
                    :order_code,
                    :restocker_id,
                    :status,
                    :demand_mode,
                    :safety_buffer_pct,
                    :cold_chamber_exit_temp_celsius,
                    :notes,
                    :created_by
                )
                RETURNING id
            ");
            $insert->bindValue(':order_code', $order->getOrderCode(), PDO::PARAM_STR);
            $insert->bindValue(':restocker_id', $order->getRestockerId(), PDO::PARAM_INT);
            $insert->bindValue(':status', $order->getStatus()->value, PDO::PARAM_STR);
            $insert->bindValue(':demand_mode', $order->getDemandMode()->value, PDO::PARAM_STR);
            $insert->bindValue(':safety_buffer_pct', $order->getSafetyBufferPct(), PDO::PARAM_INT);
            $this->bindNullableFloat($insert, ':cold_chamber_exit_temp_celsius', $order->getColdChamberExitTempCelsius());
            $this->bindNullableText($insert, ':notes', $order->getNotes());
            $insert->bindValue(':created_by', $order->getCreatedBy(), PDO::PARAM_INT);
            $insert->execute();
            $orderId = (int)$insert->fetchColumn();
            $insert->closeCursor();

            $itemInsert = $this->pdo->prepare("
                INSERT INTO product_loading_order_items (
                    order_id,
                    product_id,
                    reason,
                    requested_qty,
                    is_active
                ) VALUES (
                    :order_id,
                    :product_id,
                    :reason,
                    :requested_qty,
                    TRUE
                )
            ");

            foreach ($normalized as $item) {
                $itemInsert->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $itemInsert->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
                $itemInsert->bindValue(':reason', $item['reason'], PDO::PARAM_STR);
                $itemInsert->bindValue(':requested_qty', $item['requested_qty'], PDO::PARAM_INT);
                $itemInsert->execute();
            }

            $created = $this->findById($orderId);
            if ($created === null) {
                throw new RuntimeException('La orden se insertó pero no pudo releerse.');
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $created;
        } catch (PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($exception->getCode() === '23505') {
                throw new InvalidArgumentException(sprintf(
                    'ACTIVE_LOADING_ORDER_EXISTS: ya existe una orden activa para el reponedor %d.',
                    $order->getRestockerId()
                ), 0, $exception);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $orderId, bool $forUpdate = false): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $sql = 'SELECT ' . self::ORDER_COLUMNS
            . ' FROM product_loading_orders o'
            . ' INNER JOIN users u ON u.id = o.restocker_id'
            . ' WHERE o.id = :order_id'
            . ($forUpdate ? ' FOR UPDATE OF o' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'order' => ProductLoadingOrder::fromDatabaseRow($row),
            'items' => $this->fetchItems($orderId, $forUpdate),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveByRestocker(int $restockerId, bool $forUpdate = false): ?ProductLoadingOrder
    {
        if ($restockerId <= 0) {
            return null;
        }

        $sql = 'SELECT ' . self::ORDER_COLUMNS
            . ' FROM product_loading_orders o'
            . ' INNER JOIN users u ON u.id = o.restocker_id'
            . ' WHERE o.restocker_id = :restocker_id'
            . ' AND o.status IN (' . self::ACTIVE_STATUSES . ')'
            . ($forUpdate ? ' FOR UPDATE OF o' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ProductLoadingOrder::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findForCoordinator(
        ?string $status = null,
        ?int $restockerId = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $conditions = [];
        $params = [];

        if ($status !== null && trim($status) !== '') {
            $normalized = strtoupper(trim($status));
            if (ProductLoadingOrderStatus::tryFrom($normalized) === null) {
                throw new InvalidArgumentException(sprintf(
                    "Filtro de estado no válido: '%s'. Valores permitidos: %s.",
                    $status,
                    implode(', ', array_map(
                        static fn (ProductLoadingOrderStatus $case): string => $case->value,
                        ProductLoadingOrderStatus::cases()
                    ))
                ));
            }
            $conditions[] = 'o.status = :status';
            $params[':status'] = ['value' => $normalized, 'type' => PDO::PARAM_STR];
        }

        if ($restockerId !== null) {
            if ($restockerId <= 0) {
                return [];
            }
            $conditions[] = 'o.restocker_id = :restocker_id';
            $params[':restocker_id'] = ['value' => $restockerId, 'type' => PDO::PARAM_INT];
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
        $params[':day_start'] = ['value' => $today->format(DateTimeImmutable::ATOM), 'type' => PDO::PARAM_STR];
        $params[':day_end'] = ['value' => $today->modify('+1 day')->format(DateTimeImmutable::ATOM), 'type' => PDO::PARAM_STR];

        $sql = 'SELECT ' . self::ORDER_COLUMNS . ",
                EXISTS (
                    SELECT 1 FROM technician_absences a
                     WHERE a.technician_id = o.restocker_id
                       AND a.cancelled_at IS NULL
                       AND a.starts_at < :day_end
                       AND a.ends_at >= :day_start
                ) AS restocker_currently_absent,
                (SELECT COUNT(*) FROM product_loading_order_items i
                  WHERE i.order_id = o.id AND i.is_active = TRUE) AS active_items_count,
                (SELECT COALESCE(SUM(i.requested_qty), 0) FROM product_loading_order_items i
                  WHERE i.order_id = o.id AND i.is_active = TRUE) AS total_requested_qty,
                (SELECT COALESCE(SUM(i.dispatched_qty), 0) FROM product_loading_order_items i
                  WHERE i.order_id = o.id AND i.is_active = TRUE AND i.dispatched_qty IS NOT NULL) AS total_dispatched_qty
            FROM product_loading_orders o
            INNER JOIN users u ON u.id = o.restocker_id";

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY
                    CASE WHEN o.status IN (" . self::ACTIVE_STATUSES . ') THEN 0 ELSE 1 END ASC,
                    o.created_at DESC,
                    o.id DESC
                  LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $param) {
            $stmt->bindValue($placeholder, $param['value'], $param['type']);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => array_merge(ProductLoadingOrder::fromDatabaseRow($row)->toArray(), [
                'restocker_name' => (string)$row['restocker_name'],
                'restocker_operator_code' => $row['restocker_operator_code'] === null
                    ? null
                    : (string)$row['restocker_operator_code'],
                'restocker_is_active' => self::toBoolean($row['restocker_is_active']),
                'restocker_currently_absent' => self::toBoolean($row['restocker_currently_absent']),
                'active_items_count' => (int)$row['active_items_count'],
                'total_requested_qty' => (int)$row['total_requested_qty'],
                'total_dispatched_qty' => (int)$row['total_dispatched_qty'],
            ]),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function summarizeStatusCounts(?DateTimeImmutable $reference = null): array
    {
        $today = ($reference ?? new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
            ->setTime(0, 0);
        $dayStart = $today->format(DateTimeImmutable::ATOM);
        $dayEnd = $today->modify('+1 day')->format(DateTimeImmutable::ATOM);

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) FILTER (WHERE status = 'PENDING_PICKING') AS pending_picking_count,
                COUNT(*) FILTER (WHERE status = 'DISPATCHED') AS dispatched_count,
                COUNT(*) FILTER (WHERE status = 'CONFIRMED_BY_RESTOCKER') AS confirmed_in_route_count,
                COUNT(*) FILTER (
                    WHERE status = 'RECONCILED_AT_CLOSE'
                      AND reconciled_at >= :day_start
                      AND reconciled_at < :day_end
                ) AS reconciled_today_count
            FROM product_loading_orders
        ");
        $stmt->bindValue(':day_start', $dayStart, PDO::PARAM_STR);
        $stmt->bindValue(':day_end', $dayEnd, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'pending_picking_count' => (int)($row['pending_picking_count'] ?? 0),
            'dispatched_count' => (int)($row['dispatched_count'] ?? 0),
            'confirmed_in_route_count' => (int)($row['confirmed_in_route_count'] ?? 0),
            'reconciled_today_count' => (int)($row['reconciled_today_count'] ?? 0),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateOrder(ProductLoadingOrder $order): ProductLoadingOrder
    {
        $orderId = $order->getId();
        if ($orderId === null || $orderId <= 0) {
            throw new InvalidArgumentException('No se puede actualizar una orden sin identificador.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE product_loading_orders
               SET status = :status,
                   demand_mode = :demand_mode,
                   safety_buffer_pct = :safety_buffer_pct,
                   cold_chamber_exit_temp_celsius = :cold_chamber_exit_temp_celsius,
                   notes = :notes,
                   cancel_reason = :cancel_reason,
                   reconciliation_notes = :reconciliation_notes,
                   dispatched_by = :dispatched_by,
                   reconciled_by = :reconciled_by,
                   dispatched_at = :dispatched_at,
                   confirmed_at = :confirmed_at,
                   reconciled_at = :reconciled_at,
                   cancelled_at = :cancelled_at,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
        ");
        $stmt->bindValue(':status', $order->getStatus()->value, PDO::PARAM_STR);
        $stmt->bindValue(':demand_mode', $order->getDemandMode()->value, PDO::PARAM_STR);
        $stmt->bindValue(':safety_buffer_pct', $order->getSafetyBufferPct(), PDO::PARAM_INT);
        $this->bindNullableFloat($stmt, ':cold_chamber_exit_temp_celsius', $order->getColdChamberExitTempCelsius());
        $this->bindNullableText($stmt, ':notes', $order->getNotes());
        $this->bindNullableText($stmt, ':cancel_reason', $order->getCancelReason());
        $this->bindNullableText($stmt, ':reconciliation_notes', $order->getReconciliationNotes());
        $this->bindNullableInt($stmt, ':dispatched_by', $order->getDispatchedBy());
        $this->bindNullableInt($stmt, ':reconciled_by', $order->getReconciledBy());
        $this->bindNullableText($stmt, ':dispatched_at', $order->getDispatchedAt());
        $this->bindNullableText($stmt, ':confirmed_at', $order->getConfirmedAt());
        $this->bindNullableText($stmt, ':reconciled_at', $order->getReconciledAt());
        $this->bindNullableText($stmt, ':cancelled_at', $order->getCancelledAt());
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS
            . ' FROM product_loading_orders o'
            . ' INNER JOIN users u ON u.id = o.restocker_id'
            . ' WHERE o.id = :id');
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new InvalidArgumentException(sprintf('La orden de carga %d no existe.', $orderId));
        }

        return ProductLoadingOrder::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function replaceItems(int $orderId, array $items): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('La orden de destino debe existir.');
        }

        $normalized = $this->normalizeItemDrafts($items);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $productIds = array_column($normalized, 'product_id');
            $deactivate = 'UPDATE product_loading_order_items
                              SET is_active = FALSE,
                                  updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = :order_id
                              AND is_active = TRUE';

            $deactivateParams = [':order_id' => $orderId];
            if ($productIds !== []) {
                $placeholders = [];
                foreach (array_values($productIds) as $index => $productId) {
                    $placeholder = ':product_' . $index;
                    $placeholders[] = $placeholder;
                    $deactivateParams[$placeholder] = $productId;
                }
                $deactivate .= ' AND product_id NOT IN (' . implode(', ', $placeholders) . ')';
            }

            $stmt = $this->pdo->prepare($deactivate);
            foreach ($deactivateParams as $placeholder => $value) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            }
            $stmt->execute();

            $upsert = $this->pdo->prepare("
                INSERT INTO product_loading_order_items (
                    order_id,
                    product_id,
                    reason,
                    requested_qty,
                    is_active
                ) VALUES (
                    :order_id,
                    :product_id,
                    :reason,
                    :requested_qty,
                    TRUE
                )
                ON CONFLICT (order_id, product_id) DO UPDATE
                SET reason = EXCLUDED.reason,
                    requested_qty = EXCLUDED.requested_qty,
                    is_active = TRUE,
                    updated_at = CURRENT_TIMESTAMP
            ");

            foreach ($normalized as $item) {
                $upsert->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $upsert->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
                $upsert->bindValue(':reason', $item['reason'], PDO::PARAM_STR);
                $upsert->bindValue(':requested_qty', $item['requested_qty'], PDO::PARAM_INT);
                $upsert->execute();
            }

            $result = $this->fetchItems($orderId, false);

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

    /**
     * {@inheritdoc}
     */
    public function updateItem(ProductLoadingOrderItem $item): ProductLoadingOrderItem
    {
        $itemId = $item->getId();
        if ($itemId === null || $itemId <= 0) {
            throw new InvalidArgumentException('No se puede actualizar una línea sin identificador.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE product_loading_order_items
               SET reason = :reason,
                   requested_qty = :requested_qty,
                   dispatched_qty = :dispatched_qty,
                   batch_code = :batch_code,
                   expiration_date = :expiration_date,
                   van_stock_before_reconcile = :van_stock_before_reconcile,
                   restocked_in_machines_qty = :restocked_in_machines_qty,
                   withdrawn_spoilage_qty = :withdrawn_spoilage_qty,
                   expected_return_qty = :expected_return_qty,
                   counted_return_qty = :counted_return_qty,
                   discrepancy_qty = :discrepancy_qty,
                   is_active = :is_active,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND order_id = :order_id
        ");
        $stmt->bindValue(':reason', $item->getReason()->value, PDO::PARAM_STR);
        $stmt->bindValue(':requested_qty', $item->getRequestedQty(), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':dispatched_qty', $item->getDispatchedQty());
        $this->bindNullableText($stmt, ':batch_code', $item->getBatchCode());
        $this->bindNullableText(
            $stmt,
            ':expiration_date',
            $item->getExpirationDate()?->format('Y-m-d')
        );
        $this->bindNullableInt($stmt, ':van_stock_before_reconcile', $item->getVanStockBeforeReconcile());
        $this->bindNullableInt($stmt, ':restocked_in_machines_qty', $item->getRestockedInMachinesQty());
        $this->bindNullableInt($stmt, ':withdrawn_spoilage_qty', $item->getWithdrawnSpoilageQty());
        $this->bindNullableInt($stmt, ':expected_return_qty', $item->getExpectedReturnQty());
        $this->bindNullableInt($stmt, ':counted_return_qty', $item->getCountedReturnQty());
        $this->bindNullableInt($stmt, ':discrepancy_qty', $item->getDiscrepancyQty());
        $stmt->bindValue(':is_active', $item->isActive(), PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $stmt->bindValue(':order_id', $item->getOrderId(), PDO::PARAM_INT);
        $stmt->execute();

        $persisted = $this->findItem($item->getOrderId(), $item->getProductId());
        if ($persisted === null) {
            throw new InvalidArgumentException(sprintf(
                'La línea %d de la orden %d no existe.',
                $itemId,
                $item->getOrderId()
            ));
        }

        return $persisted;
    }

    /**
     * {@inheritdoc}
     */
    public function findItem(int $orderId, int $productId, bool $forUpdate = false): ?ProductLoadingOrderItem
    {
        if ($orderId <= 0 || $productId <= 0) {
            return null;
        }

        $sql = 'SELECT ' . self::ITEM_COLUMNS
            . ' FROM product_loading_order_items i'
            . ' INNER JOIN vending_products vp ON vp.id = i.product_id'
            . ' WHERE i.order_id = :order_id AND i.product_id = :product_id'
            . ($forUpdate ? ' FOR UPDATE OF i' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ProductLoadingOrderItem::fromDatabaseRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchProposalInputs(int $restockerId, ?DateTimeImmutable $reference = null): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El reponedor de la propuesta debe existir.');
        }

        $today = ($reference ?? new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))
            ->setTime(0, 0);
        $dayStart = $today->format(DateTimeImmutable::ATOM);
        $dayEnd = $today->modify('+1 day')->format(DateTimeImmutable::ATOM);

        return [
            'restocker' => $this->fetchProposalRestocker($restockerId, $dayStart, $dayEnd),
            'zones' => $this->fetchCoveredZones($restockerId, $dayStart, $dayEnd),
            'slot_demand' => $this->fetchSlotDemand($restockerId, $dayStart, $dayEnd),
            'excluded_unavailable_machines_count' => $this->countUnavailableMachines($restockerId, $dayStart, $dayEnd),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapItemRow(array $row): array
    {
        $category = ProductCategory::fromString((string)$row['product_category']);
        $item = ProductLoadingOrderItem::fromDatabaseRow($row);

        return array_merge($item->toArray(), [
            'product_sku' => (string)$row['product_sku'],
            'product_name' => (string)$row['product_name'],
            'product_category' => $category->value,
            'product_category_label' => $category->label(),
            'product_unit_type' => (string)$row['product_unit_type'],
            'requires_cold_chain' => self::toBoolean($row['requires_cold_chain']),
            'max_shelf_life_days' => $row['max_shelf_life_days'] === null
                ? null
                : (int)$row['max_shelf_life_days'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(int $orderId, bool $forUpdate): array
    {
        $sql = 'SELECT ' . self::ITEM_COLUMNS
            . ' FROM product_loading_order_items i'
            . ' INNER JOIN vending_products vp ON vp.id = i.product_id'
            . ' WHERE i.order_id = :order_id'
            . ' ' . self::ITEM_ORDER
            . ($forUpdate ? ' FOR UPDATE OF i' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->mapItemRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<array{product_id: int|string, reason: string, requested_qty: int|string}> $items
     *
     * @return list<array{product_id: int, reason: string, requested_qty: int}>
     */
    private function normalizeItemDrafts(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $requestedQty = (int)($item['requested_qty'] ?? 0);
            $reason = ProductLoadingItemReason::fromString((string)($item['reason'] ?? ''));

            if ($productId <= 0) {
                throw new InvalidArgumentException('Cada línea de carga debe referenciar un producto existente.');
            }

            if ($requestedQty < ProductLoadingOrderItem::MIN_REQUESTED_QTY
                || $requestedQty > ProductLoadingOrderItem::MAX_REQUESTED_QTY) {
                throw new InvalidArgumentException(sprintf(
                    'Las unidades solicitadas deben estar entre %d y %d; se recibieron %d.',
                    ProductLoadingOrderItem::MIN_REQUESTED_QTY,
                    ProductLoadingOrderItem::MAX_REQUESTED_QTY,
                    $requestedQty
                ));
            }

            $normalized[] = [
                'product_id' => $productId,
                'reason' => $reason->value,
                'requested_qty' => $requestedQty,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProposalRestocker(int $restockerId, string $dayStart, string $dayEnd): ?array
    {
        $activeStatuses = self::ACTIVE_STATUSES;
        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                u.name,
                u.operator_code,
                u.is_active,
                EXISTS (
                    SELECT 1 FROM technician_absences a
                     WHERE a.technician_id = u.id
                       AND a.cancelled_at IS NULL
                       AND a.starts_at < :day_end
                       AND a.ends_at >= :day_start
                ) AS is_currently_absent,
                o.id AS open_order_id,
                o.status AS open_order_status
            FROM users u
            LEFT JOIN product_loading_orders o
                ON o.restocker_id = u.id
               AND o.status IN ({$activeStatuses})
            WHERE u.id = :restocker_id
              AND u.role = 'RESTOCKER'
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':day_start', $dayStart, PDO::PARAM_STR);
        $stmt->bindValue(':day_end', $dayEnd, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'operator_code' => $row['operator_code'] === null ? null : (string)$row['operator_code'],
            'is_active' => self::toBoolean($row['is_active']),
            'is_currently_absent' => self::toBoolean($row['is_currently_absent']),
            'open_order_id' => $row['open_order_id'] === null ? null : (int)$row['open_order_id'],
            'open_order_status' => $row['open_order_status'] === null ? null : (string)$row['open_order_status'],
        ];
    }

    /**
     * Zonas cubiertas hoy según la regla determinista del Módulo 31.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchCoveredZones(int $restockerId, string $dayStart, string $dayEnd): array
    {
        $stmt = $this->pdo->prepare($this->coveredZonesCte() . '
            SELECT zone_id, zone_code, zone_name, coverage_role, assigned_at
              FROM covered_zones
             ORDER BY zone_name ASC, zone_id ASC
        ');
        $this->bindCoverageParams($stmt, $restockerId, $dayStart, $dayEnd);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'zone_id' => (int)$row['zone_id'],
                'zone_code' => (string)$row['zone_code'],
                'zone_name' => (string)$row['zone_name'],
                'coverage_role' => (string)$row['coverage_role'],
                'assigned_at' => (string)$row['assigned_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Huecos por producto sobre las máquinas operativas cubiertas (D1, QA 1).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSlotDemand(int $restockerId, string $dayStart, string $dayEnd): array
    {
        $sql = $this->coveredZonesCte() . ",
            active_machines AS (
                SELECT m.id, m.code, m.sanitary_status, m.is_blocked_no_access, m.is_seasonal_pause
                  FROM covered_zones c
                  INNER JOIN locations l ON l.zone_id = c.zone_id
                  INNER JOIN machines m ON m.location_id = l.id
                 WHERE m.is_active = TRUE
                   AND m.deleted_at IS NULL
            ),
            operational_machines AS (
                SELECT id, code FROM active_machines m
                 WHERE NOT " . self::UNAVAILABLE_MACHINE_PREDICATE . "
            )
            SELECT
                vp.id AS product_id,
                vp.sku AS product_sku,
                vp.name AS product_name,
                vp.category AS product_category,
                vp.unit_type AS product_unit_type,
                vp.requires_cold_chain,
                vp.max_shelf_life_days,
                SUM(s.max_capacity - s.current_estimated_qty) AS gap_qty_all,
                SUM(s.max_capacity - s.current_estimated_qty)
                    FILTER (WHERE s.current_estimated_qty = 0 OR s.current_estimated_qty <= s.min_threshold)
                    AS gap_qty_alerts,
                COUNT(*) AS gap_slots_count,
                COUNT(*) FILTER (WHERE s.current_estimated_qty = 0) AS stockout_slots_count,
                COUNT(*) FILTER (
                    WHERE s.current_estimated_qty > 0 AND s.current_estimated_qty <= s.min_threshold
                ) AS low_stock_slots_count,
                COUNT(*) FILTER (
                    WHERE s.current_estimated_qty = 0 OR s.current_estimated_qty <= s.min_threshold
                ) AS alert_slots_count
            FROM operational_machines m
            INNER JOIN machine_planogram_slots s
                ON s.machine_id = m.id
               AND s.is_active = TRUE
            INNER JOIN vending_products vp
                ON vp.id = s.product_id
               AND vp.is_active = TRUE
            WHERE s.current_estimated_qty < s.max_capacity
            GROUP BY
                vp.id, vp.sku, vp.name, vp.category, vp.unit_type,
                vp.requires_cold_chain, vp.max_shelf_life_days
            ORDER BY vp.requires_cold_chain DESC, vp.sku ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $this->bindCoverageParams($stmt, $restockerId, $dayStart, $dayEnd);
        $stmt->execute();

        return array_map(
            static function (array $row): array {
                $category = ProductCategory::fromString((string)$row['product_category']);

                return [
                    'product_id' => (int)$row['product_id'],
                    'product_sku' => (string)$row['product_sku'],
                    'product_name' => (string)$row['product_name'],
                    'product_category' => $category->value,
                    'product_category_label' => $category->label(),
                    'product_unit_type' => (string)$row['product_unit_type'],
                    'requires_cold_chain' => self::toBoolean($row['requires_cold_chain']),
                    'max_shelf_life_days' => $row['max_shelf_life_days'] === null
                        ? null
                        : (int)$row['max_shelf_life_days'],
                    'gap_qty_all' => (int)$row['gap_qty_all'],
                    'gap_qty_alerts' => (int)$row['gap_qty_alerts'],
                    'gap_slots_count' => (int)$row['gap_slots_count'],
                    'stockout_slots_count' => (int)$row['stockout_slots_count'],
                    'low_stock_slots_count' => (int)$row['low_stock_slots_count'],
                    'alert_slots_count' => (int)$row['alert_slots_count'],
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function countUnavailableMachines(int $restockerId, string $dayStart, string $dayEnd): int
    {
        $sql = $this->coveredZonesCte() . '
            SELECT COUNT(*)
              FROM covered_zones c
              INNER JOIN locations l ON l.zone_id = c.zone_id
              INNER JOIN machines m ON m.location_id = l.id
             WHERE m.is_active = TRUE
               AND m.deleted_at IS NULL
               AND ' . self::UNAVAILABLE_MACHINE_PREDICATE;

        $stmt = $this->pdo->prepare($sql);
        $this->bindCoverageParams($stmt, $restockerId, $dayStart, $dayEnd);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * CTE de zonas cubiertas hoy: titular sin ausencia o suplente promovido
     * cuando el titular está ausente o la zona no tiene titular vivo.
     */
    private function coveredZonesCte(): string
    {
        return "WITH covered_zones AS (
            SELECT
                oz.id AS zone_id,
                oz.code AS zone_code,
                oz.name AS zone_name,
                CASE WHEN a.is_primary THEN 'PRIMARY' ELSE 'BACKUP' END AS coverage_role,
                a.assigned_at
            FROM restocker_zone_assignments a
            INNER JOIN operational_zones oz
                ON oz.id = a.zone_id
               AND oz.is_active = TRUE
            WHERE a.restocker_id = :restocker_id
              AND a.removed_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM technician_absences ra
                   WHERE ra.technician_id = :restocker_id
                     AND ra.cancelled_at IS NULL
                     AND ra.starts_at < :day_end
                     AND ra.ends_at >= :day_start
              )
              AND (
                  a.is_primary = TRUE
                  OR NOT EXISTS (
                      SELECT 1
                        FROM restocker_zone_assignments p
                       WHERE p.zone_id = oz.id
                         AND p.is_primary = TRUE
                         AND p.removed_at IS NULL
                         AND NOT EXISTS (
                             SELECT 1 FROM technician_absences pa
                              WHERE pa.technician_id = p.restocker_id
                                AND pa.cancelled_at IS NULL
                                AND pa.starts_at < :day_end
                                AND pa.ends_at >= :day_start
                         )
                  )
              )
        )";
    }

    private function bindCoverageParams(PDOStatement $stmt, int $restockerId, string $dayStart, string $dayEnd): void
    {
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':day_start', $dayStart, PDO::PARAM_STR);
        $stmt->bindValue(':day_end', $dayEnd, PDO::PARAM_STR);
    }

    private function bindNullableText(\PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        $stmt->bindValue($placeholder, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $placeholder, ?int $value): void
    {
        $stmt->bindValue($placeholder, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableFloat(\PDOStatement $stmt, string $placeholder, ?float $value): void
    {
        $stmt->bindValue(
            $placeholder,
            $value === null ? null : number_format($value, 1, '.', ''),
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
