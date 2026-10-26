<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoVanRestockOrderRepository
 *
 * Persistencia de las órdenes de carga de furgoneta sobre PostgreSQL (Módulo 23,
 * RF-01 a RF-05). Traduce entre el dominio y el motor; las fórmulas de propuesta
 * y las decisiones de caso de uso viven en `VanRestockOrderService`.
 *
 * DECISIONES QUE ESTE ADAPTADOR HACE EXPLÍCITAS
 *
 *  - **Nunca se borra.** No existe `DELETE` en todo el fichero. Retirar una línea
 *    es `removed_at` + `removed_by`, y el motor lo impone además con
 *    `trg_no_delete_van_restock_order_items` (Art. III.1, `QA 1`).
 *  - **La creación se serializa por técnico.** `createOrder()` bloquea la fila
 *    del destinatario con `SELECT ... FOR UPDATE` antes de comprobar el hueco
 *    abierto: dos peticiones simultáneas del mismo técnico no pueden ganar las
 *    dos, y el índice único parcial `uq_van_restock_open_per_tech` queda como
 *    última puerta (D3). Su violación se traduce a
 *    `OPEN_RESTOCK_ORDER_ALREADY_EXISTS`, nunca a un error de motor.
 *  - **La confirmación móvil es una sola unidad de escritura.** Bloquea la
 *    cabecera y cada saldo de `technician_van_stocks` con `SELECT ... FOR UPDATE`,
 *    verifica el tope `9999` (QA 1), suma las unidades servidas, cierra la orden
 *    y escribe su evento de auditoría. O queda todo, o no queda nada (RNF-02).
 *    No consulta `technician_shifts` (QA 4) ni toca averías ni
 *    `spare_part_requests` (C5, QA 3).
 *  - **La auditoría comparte transacción.** Los eventos se insertan en
 *    `audit_log` con `entity_type = 'VAN_RESTOCK_ORDER'` dentro de la misma
 *    transacción que la escritura, igual que hace el sembrador del módulo. Se
 *    escribe con SQL directo porque el tipo de entidad ya vive en el enumerado
 *    del motor y este adaptador no amplía la lista blanca de `AuditEvent`.
 *  - **Ninguna proyección incluye coste.** Los `SELECT` no leen
 *    `spare_parts.reference_cost` y las entidades no tienen campo monetario
 *    (Art. V.4). El aislamiento por técnico lo resuelve el servicio con
 *    `authenticatedTechnicianId`; aquí se compara contra `technician_id` antes
 *    de escribir una sola unidad.
 *
 * `fetchProposalInputs()` resuelve la foto del pre-cálculo en **cuatro viajes
 * constantes** —técnicos, catálogo activo, saldos y demanda pendiente— y agrupa
 * en PHP, de modo que el coste no crece con el número de técnicos ni de piezas
 * (RNF-01).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoVanRestockOrderRepository implements VanRestockOrderRepositoryInterface
{
    /** Estados de avería que cuentan como demanda viva (contrato §3.1). */
    private const ACTIVE_INCIDENT_STATUSES = "'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO', 'REOPENED'";

    /** Acciones canónicas del rastro (contrato §6). */
    private const ACTION_CREATE = 'CREATE_VAN_RESTOCK_ORDER';
    private const ACTION_UPDATE = 'UPDATE_VAN_RESTOCK_ORDER';
    private const ACTION_DISPATCH = 'DISPATCH_VAN_RESTOCK_ORDER';
    private const ACTION_CONFIRM = 'CONFIRM_VAN_RESTOCK_ORDER';
    private const ACTION_CANCEL = 'CANCEL_VAN_RESTOCK_ORDER';

    /** Tipología de la entidad auditada (enumerado del motor, T-RESTOCK-01). */
    private const AUDIT_ENTITY_TYPE = 'VAN_RESTOCK_ORDER';

    /**
     * Cabecera con sus identidades y las dos marcas operativas del destinatario.
     *
     * La ausencia vigente se resuelve en SQL y no en PHP: la frontera temporal es
     * la del motor (`CURRENT_TIMESTAMP`), la misma que usó la creación de la
     * orden, de modo que dos peticiones simultáneas ven la misma ventana.
     */
    private const ORDER_COLUMNS = 'o.id, o.technician_id, o.status, o.notes,
        o.created_by, o.created_at, o.dispatched_by, o.dispatched_at,
        o.confirmed_by, o.confirmed_at, o.cancelled_by, o.cancelled_at,
        o.cancellation_reason, o.reception_note, o.updated_at,
        t.name AS technician_name, t.operator_code AS technician_operator_code,
        t.is_active AS technician_is_active,
        EXISTS (
            SELECT 1 FROM technician_absences a
             WHERE a.technician_id = o.technician_id
               AND a.starts_at <= CURRENT_TIMESTAMP
               AND a.ends_at >= CURRENT_TIMESTAMP
               AND a.cancelled_at IS NULL
        ) AS recipient_currently_absent,
        cb.name AS created_by_name,
        db.name AS dispatched_by_name,
        fb.name AS confirmed_by_name,
        xb.name AS cancelled_by_name';

    private const ORDER_JOINS = 'FROM van_restock_orders o
        INNER JOIN users t ON t.id = o.technician_id
        INNER JOIN users cb ON cb.id = o.created_by
        LEFT JOIN users db ON db.id = o.dispatched_by
        LEFT JOIN users fb ON fb.id = o.confirmed_by
        LEFT JOIN users xb ON xb.id = o.cancelled_by';

    /**
     * Líneas con su pieza resuelta y en el orden determinista de `QA 5`.
     *
     * El orden vive en SQL porque la propuesta, la orden y el Albarán de Picking
     * A4 tienen que coincidir; el `CASE` replica `VanRestockOrder::reasonPriority()`
     * y no puede divergir de él sin que las suites lo noten.
     */
    private const ITEM_COLUMNS = 'i.id, i.order_id, i.spare_part_id, i.current_quantity_snapshot,
        i.min_threshold_snapshot, i.pending_request_qty_snapshot, i.requested_qty,
        i.dispatched_qty, i.reason, i.removed_at, i.removed_by,
        sp.part_code, sp.name AS part_name, sp.category';

    private const ITEM_ORDER = "CASE i.reason
            WHEN 'BOTH' THEN 1
            WHEN 'PENDING_REQUEST' THEN 2
            WHEN 'LOW_STOCK' THEN 3
            WHEN 'MANUAL' THEN 4
            ELSE 5
        END ASC, i.current_quantity_snapshot ASC, sp.part_code ASC, i.id ASC";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function fetchProposalInputs(?int $technicianId = null): array
    {
        if ($technicianId !== null && $technicianId < 1) {
            throw new InvalidArgumentException('El identificador del técnico debe ser un entero positivo.');
        }

        return [
            'technicians' => $this->fetchProposalTechnicians($technicianId),
            'spare_parts' => $this->fetchActiveSpareParts(),
            'van_stock' => $this->fetchVanStockMap($technicianId),
            'pending_requests' => $this->fetchPendingRequestMap($technicianId),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function createOrder(
        int $technicianId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        if ($technicianId < 1) {
            throw VanRestockOrderValidationException::technicianNotEligible(
                'el identificador del destinatario debe ser un entero positivo.'
            );
        }

        $normalized = $this->assertItemsContract($items);
        $normalizedNotes = $this->normalizeNotes($notes);
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->assertTechnicianEligible($technicianId, true);
            $this->assertSparePartsEligible(array_keys($normalized));
            $this->assertProjectedCapacity($technicianId, $normalized);

            $open = $this->findOpenOrderRow($technicianId);
            if ($open !== null) {
                throw VanRestockOrderValidationException::openOrderAlreadyExists(
                    $this->formatOrderCode((int)$open['id'])
                );
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO van_restock_orders (technician_id, status, notes, created_by)
                 VALUES (:technician_id, :status, :notes, :created_by)
                 RETURNING id'
            );
            $insert->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
            $insert->bindValue(':status', VanRestockOrder::STATUS_PENDING_PICKING, PDO::PARAM_STR);
            $insert->bindValue(
                ':notes',
                $normalizedNotes,
                $normalizedNotes === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $insert->bindValue(':created_by', $actorUserId, PDO::PARAM_INT);
            $insert->execute();
            $orderId = (int)$insert->fetchColumn();
            $insert->closeCursor();

            foreach ($normalized as $item) {
                $this->insertItem($orderId, $item);
            }

            $this->recordAudit(
                $orderId,
                self::ACTION_CREATE,
                $actorUserId,
                null,
                [
                    'status' => VanRestockOrder::STATUS_PENDING_PICKING,
                    'technician_id' => $technicianId,
                    'source' => 'INDIVIDUAL',
                    'notes' => $normalizedNotes,
                    'items' => $this->auditItemRows($normalized),
                ]
            );

            $order = $this->requireOrder($orderId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $order;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($this->isOpenOrderUniqueViolation($exception)) {
                throw VanRestockOrderValidationException::openOrderAlreadyExists(
                    $this->resolveOpenOrderCodeAfterConflict($technicianId)
                );
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updatePendingOrderWithSoftDelete(
        int $orderId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        $normalized = $this->assertItemsContract($items);
        $normalizedNotes = $this->normalizeNotes($notes);
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $orderRow = $this->lockOrder($orderId);

            if ($orderRow['status'] !== VanRestockOrder::STATUS_PENDING_PICKING) {
                throw VanRestockOrderValidationException::invalidTransition(
                    (string)$orderRow['status'],
                    'editar'
                );
            }

            $technicianId = (int)$orderRow['technician_id'];
            $this->assertSparePartsEligible(array_keys($normalized));
            $this->assertProjectedCapacity($technicianId, $normalized);

            $existingRows = $this->fetchItemRows($orderId);
            $previousActive = array_values(array_filter(
                $existingRows,
                static fn (array $row): bool => $row['removed_at'] === null
            ));

            $bySparePart = [];
            foreach ($existingRows as $row) {
                $bySparePart[(int)$row['spare_part_id']] = $row;
            }

            $softRemovedPartIds = [];

            foreach ($normalized as $item) {
                $existing = $bySparePart[$item->sparePartId] ?? null;

                if ($existing === null) {
                    $this->insertItem($orderId, $item);
                    continue;
                }

                // Reactivación de la misma fila (QA 1): la baja lógica nunca se
                // convierte en un segundo registro para la misma pieza.
                $update = $this->pdo->prepare(
                    'UPDATE van_restock_order_items
                        SET current_quantity_snapshot = :current_quantity_snapshot,
                            min_threshold_snapshot = :min_threshold_snapshot,
                            pending_request_qty_snapshot = :pending_request_qty_snapshot,
                            requested_qty = :requested_qty,
                            reason = :reason,
                            removed_at = NULL,
                            removed_by = NULL
                      WHERE id = :id'
                );
                $update->bindValue(':current_quantity_snapshot', $item->currentQuantitySnapshot, PDO::PARAM_INT);
                $update->bindValue(':min_threshold_snapshot', $item->minThresholdSnapshot, PDO::PARAM_INT);
                $update->bindValue(':pending_request_qty_snapshot', $item->pendingRequestQtySnapshot, PDO::PARAM_INT);
                $update->bindValue(':requested_qty', $item->requestedQty, PDO::PARAM_INT);
                $update->bindValue(':reason', $item->reason, PDO::PARAM_STR);
                $update->bindValue(':id', (int)$existing['id'], PDO::PARAM_INT);
                $update->execute();
            }

            foreach ($previousActive as $row) {
                $sparePartId = (int)$row['spare_part_id'];

                if (isset($normalized[$sparePartId])) {
                    continue;
                }

                $remove = $this->pdo->prepare(
                    'UPDATE van_restock_order_items
                        SET removed_at = CURRENT_TIMESTAMP,
                            removed_by = :removed_by
                      WHERE id = :id'
                );
                $remove->bindValue(':removed_by', $actorUserId, PDO::PARAM_INT);
                $remove->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
                $remove->execute();

                $softRemovedPartIds[] = $sparePartId;
            }

            $updateOrder = $this->pdo->prepare(
                'UPDATE van_restock_orders SET notes = :notes WHERE id = :id'
            );
            $updateOrder->bindValue(
                ':notes',
                $normalizedNotes,
                $normalizedNotes === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $updateOrder->bindValue(':id', $orderId, PDO::PARAM_INT);
            $updateOrder->execute();

            $this->recordAudit(
                $orderId,
                self::ACTION_UPDATE,
                $actorUserId,
                [
                    'status' => VanRestockOrder::STATUS_PENDING_PICKING,
                    'notes' => $orderRow['notes'],
                    'active_items' => $this->auditItemRows($previousActive),
                ],
                [
                    'status' => VanRestockOrder::STATUS_PENDING_PICKING,
                    'notes' => $normalizedNotes,
                    'active_items' => $this->auditItemRows($normalized),
                    'soft_removed_part_ids' => $softRemovedPartIds,
                ]
            );

            $order = $this->requireOrder($orderId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $order;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function markDispatched(
        int $orderId,
        array $dispatchedQuantities,
        int $actorUserId
    ): VanRestockOrder {
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $orderRow = $this->lockOrder($orderId);

            if ($orderRow['status'] !== VanRestockOrder::STATUS_PENDING_PICKING) {
                throw VanRestockOrderValidationException::invalidTransition(
                    (string)$orderRow['status'],
                    'expedir'
                );
            }

            $technicianId = (int)$orderRow['technician_id'];
            $activeRows = $this->fetchActiveItemRowsWithPart($orderId);
            $activeByPart = [];

            foreach ($activeRows as $row) {
                $activeByPart[(int)$row['spare_part_id']] = $row;
            }

            $declared = $this->normalizeDispatchedQuantities($dispatchedQuantities);

            $missing = array_diff(array_keys($activeByPart), array_keys($declared));
            if ($missing !== []) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'faltan las cantidades despachadas de las piezas ' . implode(', ', $missing) . '.'
                );
            }

            $unknown = array_diff(array_keys($declared), array_keys($activeByPart));
            if ($unknown !== []) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'las piezas ' . implode(', ', $unknown) . ' no son líneas activas de la orden.'
                );
            }

            $totalDispatched = array_sum($declared);
            if ($totalDispatched < 1) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'la expedición debe servir al menos una unidad; si no hay material, procede cancelar la orden.'
                );
            }

            $this->assertDispatchCapacity($technicianId, $activeByPart, $declared);

            $updateItem = $this->pdo->prepare(
                'UPDATE van_restock_order_items
                    SET dispatched_qty = :dispatched_qty
                  WHERE id = :id'
            );

            foreach ($activeByPart as $sparePartId => $row) {
                $updateItem->bindValue(':dispatched_qty', $declared[$sparePartId], PDO::PARAM_INT);
                $updateItem->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
                $updateItem->execute();
            }

            $dispatchOrder = $this->pdo->prepare(
                "UPDATE van_restock_orders
                    SET status = 'DISPATCHED',
                        dispatched_by = :dispatched_by,
                        dispatched_at = CURRENT_TIMESTAMP
                  WHERE id = :id"
            );
            $dispatchOrder->bindValue(':dispatched_by', $actorUserId, PDO::PARAM_INT);
            $dispatchOrder->bindValue(':id', $orderId, PDO::PARAM_INT);
            $dispatchOrder->execute();

            $auditItems = [];
            foreach ($activeByPart as $sparePartId => $row) {
                $auditItems[] = [
                    'spare_part_id' => $sparePartId,
                    'part_code' => (string)$row['part_code'],
                    'requested_qty' => (int)$row['requested_qty'],
                    'dispatched_qty' => $declared[$sparePartId],
                ];
            }

            $this->recordAudit(
                $orderId,
                self::ACTION_DISPATCH,
                $actorUserId,
                ['status' => VanRestockOrder::STATUS_PENDING_PICKING],
                [
                    'status' => VanRestockOrder::STATUS_DISPATCHED,
                    'dispatched_at' => $this->now(),
                    'items' => $auditItems,
                ]
            );

            $order = $this->requireOrder($orderId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $order;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function markCancelled(
        int $orderId,
        string $cancellationReason,
        int $actorUserId
    ): VanRestockOrder {
        $reason = trim($cancellationReason);

        if (mb_strlen($reason, 'UTF-8') < VanRestockOrder::MIN_CANCELLATION_REASON_LENGTH) {
            throw VanRestockOrderValidationException::cancellationReasonTooShort(
                VanRestockOrder::MIN_CANCELLATION_REASON_LENGTH
            );
        }

        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $orderRow = $this->lockOrder($orderId);

            if (!in_array((string)$orderRow['status'], VanRestockOrder::OPEN_STATUSES, true)) {
                throw VanRestockOrderValidationException::invalidTransition(
                    (string)$orderRow['status'],
                    'cancelar'
                );
            }

            $previousStatus = (string)$orderRow['status'];

            $cancel = $this->pdo->prepare(
                "UPDATE van_restock_orders
                    SET status = 'CANCELLED',
                        cancelled_by = :cancelled_by,
                        cancelled_at = CURRENT_TIMESTAMP,
                        cancellation_reason = :cancellation_reason
                  WHERE id = :id"
            );
            $cancel->bindValue(':cancelled_by', $actorUserId, PDO::PARAM_INT);
            $cancel->bindValue(':cancellation_reason', $reason, PDO::PARAM_STR);
            $cancel->bindValue(':id', $orderId, PDO::PARAM_INT);
            $cancel->execute();

            $this->recordAudit(
                $orderId,
                self::ACTION_CANCEL,
                $actorUserId,
                ['status' => $previousStatus],
                [
                    'status' => VanRestockOrder::STATUS_CANCELLED,
                    'cancelled_at' => $this->now(),
                    'cancellation_reason' => $reason,
                ]
            );

            $order = $this->requireOrder($orderId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $order;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function confirmOrderAndIncrementVanStock(
        int $orderId,
        int $authenticatedTechnicianId,
        ?string $receptionNote = null
    ): array {
        if ($authenticatedTechnicianId < 1) {
            throw VanRestockOrderValidationException::ownershipMismatch();
        }

        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $orderRow = $this->lockOrderForConfirmation($orderId);

            if ((int)$orderRow['technician_id'] !== $authenticatedTechnicianId) {
                throw VanRestockOrderValidationException::ownershipMismatch();
            }

            if ($orderRow['status'] !== VanRestockOrder::STATUS_DISPATCHED) {
                throw VanRestockOrderValidationException::invalidTransition(
                    (string)$orderRow['status'],
                    'confirmar'
                );
            }

            $technicianId = (int)$orderRow['technician_id'];
            $technicianName = (string)$orderRow['technician_name'];
            $activeRows = $this->fetchActiveItemRowsWithPart($orderId);

            $stockBefore = [];
            $stockAfter = [];

            $lockStock = $this->pdo->prepare(
                'SELECT id, quantity_available, min_threshold
                   FROM technician_van_stocks
                  WHERE technician_id = :technician_id
                    AND spare_part_id = :spare_part_id
                  FOR UPDATE'
            );

            $upsertStock = $this->pdo->prepare(
                'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
                 VALUES (:technician_id, :spare_part_id, :dispatched_qty, 0)
                 ON CONFLICT (technician_id, spare_part_id)
                 DO UPDATE SET
                     quantity_available = technician_van_stocks.quantity_available + EXCLUDED.quantity_available,
                     updated_at = CURRENT_TIMESTAMP
                 RETURNING quantity_available'
            );

            foreach ($activeRows as $row) {
                $dispatchedQty = $row['dispatched_qty'] === null ? 0 : (int)$row['dispatched_qty'];

                if ($dispatchedQty <= 0) {
                    continue;
                }

                $sparePartId = (int)$row['spare_part_id'];

                $lockStock->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
                $lockStock->bindValue(':spare_part_id', $sparePartId, PDO::PARAM_INT);
                $lockStock->execute();
                $stockRow = $lockStock->fetch();
                $lockStock->closeCursor();

                $previousQuantity = $stockRow === false ? 0 : (int)$stockRow['quantity_available'];
                $minThreshold = $stockRow === false ? 0 : (int)$stockRow['min_threshold'];
                $newQuantity = $previousQuantity + $dispatchedQty;

                if ($newQuantity > VanRestockOrder::QUANTITY_MAX) {
                    throw VanRestockOrderValidationException::invalidPayload(
                        sprintf(
                            'recibir %d unidades de la pieza %s dejaría el saldo de la furgoneta en %d, por encima del tope de %d.',
                            $dispatchedQty,
                            (string)$row['part_code'],
                            $newQuantity,
                            VanRestockOrder::QUANTITY_MAX
                        )
                    );
                }

                $stockBefore[] = [
                    'spare_part_id' => $sparePartId,
                    'quantity_available' => $previousQuantity,
                ];

                $upsertStock->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
                $upsertStock->bindValue(':spare_part_id', $sparePartId, PDO::PARAM_INT);
                $upsertStock->bindValue(':dispatched_qty', $dispatchedQty, PDO::PARAM_INT);

                try {
                    $upsertStock->execute();
                    $writtenQuantity = (int)$upsertStock->fetchColumn();
                    $upsertStock->closeCursor();
                } catch (PDOException $exception) {
                    // La restricción del motor es la última puerta del tope: si dos
                    // escrituras concurrentes lo superan, el saldo no puede quedar
                    // fuera de rango y el técnico recibe un 422 explicable.
                    if ($this->isCheckConstraintViolation($exception)) {
                        throw VanRestockOrderValidationException::invalidPayload(
                            sprintf(
                                'el saldo resultante de la pieza %s superaría el tope de %d unidades.',
                                (string)$row['part_code'],
                                VanRestockOrder::QUANTITY_MAX
                            )
                        );
                    }

                    throw $exception;
                }

                $stockAfter[] = [
                    'spare_part_id' => $sparePartId,
                    'part_code' => (string)$row['part_code'],
                    'part_name' => (string)$row['part_name'],
                    'previous_quantity_available' => $previousQuantity,
                    'dispatched_qty' => $dispatchedQty,
                    'new_quantity_available' => $writtenQuantity,
                    'min_threshold' => $minThreshold,
                    'is_below_threshold' => $writtenQuantity <= $minThreshold,
                ];
            }

            if ($stockAfter === []) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'la orden no declara ninguna unidad despachada que pueda recibirse.'
                );
            }

            $cleanReceptionNote = $receptionNote !== null ? trim($receptionNote) : null;
            if ($cleanReceptionNote === '') {
                $cleanReceptionNote = null;
            }

            $confirm = $this->pdo->prepare(
                "UPDATE van_restock_orders
                    SET status = 'CONFIRMED_BY_TECH',
                        confirmed_by = :confirmed_by,
                        confirmed_at = CURRENT_TIMESTAMP,
                        reception_note = :reception_note
                  WHERE id = :id"
            );
            $confirm->bindValue(':confirmed_by', $technicianId, PDO::PARAM_INT);
            $confirm->bindValue(':reception_note', $cleanReceptionNote, $cleanReceptionNote !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $confirm->bindValue(':id', $orderId, PDO::PARAM_INT);
            $confirm->execute();

            $this->recordAuditWithActor(
                $orderId,
                self::ACTION_CONFIRM,
                $technicianId,
                'TECHNICIAN',
                $technicianName,
                [
                    'status' => VanRestockOrder::STATUS_DISPATCHED,
                    'stock_before' => $stockBefore,
                ],
                [
                    'status' => VanRestockOrder::STATUS_CONFIRMED_BY_TECH,
                    'confirmed_at' => $this->now(),
                    'reception_note' => $cleanReceptionNote,
                    'stock_after' => array_map(
                        static fn (array $row): array => [
                            'spare_part_id' => $row['spare_part_id'],
                            'dispatched_qty' => $row['dispatched_qty'],
                            'quantity_available' => $row['new_quantity_available'],
                        ],
                        $stockAfter
                    ),
                ]
            );

            $order = $this->requireOrder($orderId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'order' => $order,
                'updated_van_stock' => $stockAfter,
            ];
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $orderId): ?VanRestockOrder
    {
        if ($orderId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' ' . self::ORDER_JOINS . ' WHERE o.id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $orders = $this->hydrateOrders([$row]);

        return $orders[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findOpenOrderForTechnician(int $technicianId): ?VanRestockOrder
    {
        if ($technicianId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' ' . self::ORDER_JOINS . '
              WHERE o.technician_id = :technician_id
                AND o.status IN (\'PENDING_PICKING\', \'DISPATCHED\')
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $orders = $this->hydrateOrders([$row]);

        return $orders[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findAllForCoordinator(?int $technicianId = null, ?string $status = null): array
    {
        if ($technicianId !== null && $technicianId < 1) {
            throw new InvalidArgumentException('El identificador del técnico debe ser un entero positivo.');
        }

        $filter = $this->normalizeStatusFilter($status);

        $sql = 'SELECT ' . self::ORDER_COLUMNS . ' ' . self::ORDER_JOINS . ' WHERE 1 = 1';
        $params = [];

        if ($technicianId !== null) {
            $sql .= ' AND o.technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        if ($filter === 'OPEN') {
            $sql .= ' AND o.status IN (\'PENDING_PICKING\', \'DISPATCHED\')';
        } elseif ($filter !== null) {
            $sql .= ' AND o.status = :status';
            $params[':status'] = $filter;
        }

        // RF-04.4: las abiertas primero y, dentro de cada bloque, la más reciente.
        $sql .= ' ORDER BY CASE WHEN o.status IN (\'PENDING_PICKING\', \'DISPATCHED\') THEN 0 ELSE 1 END ASC,
                           o.created_at DESC, o.id DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue(
                $placeholder,
                $value,
                $placeholder === ':status' ? PDO::PARAM_STR : PDO::PARAM_INT
            );
        }
        $stmt->execute();

        return $this->hydrateOrders($stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveDispatchedForTechnician(int $technicianId): ?VanRestockOrder
    {
        if ($technicianId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' ' . self::ORDER_JOINS . '
              WHERE o.technician_id = :technician_id
                AND o.status = \'DISPATCHED\'
              ORDER BY o.dispatched_at DESC, o.id DESC
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $orders = $this->hydrateOrders([$row]);

        return $orders[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findLastConfirmedForTechnician(int $technicianId): ?VanRestockOrder
    {
        if ($technicianId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' ' . self::ORDER_JOINS . '
              WHERE o.technician_id = :technician_id
                AND o.status = \'CONFIRMED_BY_TECH\'
              ORDER BY o.confirmed_at DESC, o.id DESC
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $orders = $this->hydrateOrders([$row]);

        return $orders[0] ?? null;
    }

    // ---------------------------------------------------------------------
    // Insumos del pre-cálculo (RF-01, RNF-01)
    // ---------------------------------------------------------------------

    /**
     * Plantilla técnica de la foto de propuesta.
     *
     * Sin `$technicianId` se leen los técnicos activos —los únicos para los que
     * RF-02.1 calcula propuesta—; con identificador explícito se incluye también
     * al inactivo y se informa de su bandera, para que el caso de uso pueda
     * rechazar la emisión con `TECHNICIAN_NOT_ELIGIBLE` sin inventarse el motivo.
     *
     * @return list<array{
     *   technician_id: int,
     *   technician_name: string,
     *   operator_code: string|null,
     *   is_active: bool,
     *   is_currently_absent: bool,
     *   open_order_id: int|null,
     *   open_order_status: string|null
     * }>
     */
    private function fetchProposalTechnicians(?int $technicianId): array
    {
        $sql = "SELECT u.id, u.name, u.operator_code, u.is_active,
                       EXISTS (
                           SELECT 1 FROM technician_absences a
                            WHERE a.technician_id = u.id
                              AND a.starts_at <= CURRENT_TIMESTAMP
                              AND a.ends_at >= CURRENT_TIMESTAMP
                              AND a.cancelled_at IS NULL
                       ) AS is_currently_absent,
                       o.id AS open_order_id,
                       o.status AS open_order_status
                  FROM users u
                  LEFT JOIN van_restock_orders o
                    ON o.technician_id = u.id
                   AND o.status IN ('PENDING_PICKING', 'DISPATCHED')
                 WHERE u.role = 'TECHNICIAN'
                   AND u.deleted_at IS NULL";

        $params = [];

        if ($technicianId === null) {
            $sql .= ' AND u.is_active = TRUE';
        } else {
            $sql .= ' AND u.id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $sql .= ' ORDER BY u.name ASC, u.id ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $technicians = [];
        foreach ($stmt->fetchAll() as $row) {
            $technicians[] = [
                'technician_id' => (int)$row['id'],
                'technician_name' => (string)$row['name'],
                'operator_code' => $row['operator_code'] === null ? null : (string)$row['operator_code'],
                'is_active' => (bool)$row['is_active'],
                'is_currently_absent' => (bool)$row['is_currently_absent'],
                'open_order_id' => $row['open_order_id'] === null ? null : (int)$row['open_order_id'],
                'open_order_status' => $row['open_order_status'] === null ? null : (string)$row['open_order_status'],
            ];
        }

        return $technicians;
    }

    /**
     * Catálogo vivo: solo piezas activas y no dadas de baja lógica.
     *
     * `reference_cost` no se selecciona a propósito: el módulo no emite coste
     * alguno y la privacidad del Art. V.4 empieza por no leerlo.
     *
     * @return list<array{spare_part_id: int, part_code: string, part_name: string, category: string}>
     */
    private function fetchActiveSpareParts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, part_code, name, category
               FROM spare_parts
              WHERE is_active = TRUE
                AND deleted_at IS NULL
              ORDER BY part_code ASC, id ASC'
        );

        $parts = [];
        foreach ($stmt->fetchAll() as $row) {
            $parts[] = [
                'spare_part_id' => (int)$row['id'],
                'part_code' => (string)$row['part_code'],
                'part_name' => (string)$row['name'],
                'category' => (string)$row['category'],
            ];
        }

        return $parts;
    }

    /**
     * Saldos declarados por técnico y pieza.
     *
     * @return array<int, array<int, array{quantity_available: int, min_threshold: int}>>
     */
    private function fetchVanStockMap(?int $technicianId): array
    {
        $sql = 'SELECT vs.technician_id, vs.spare_part_id, vs.quantity_available, vs.min_threshold
                  FROM technician_van_stocks vs
                  INNER JOIN users u ON u.id = vs.technician_id
                 WHERE u.role = \'TECHNICIAN\'
                   AND u.deleted_at IS NULL';
        $params = [];

        if ($technicianId !== null) {
            $sql .= ' AND vs.technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['technician_id']][(int)$row['spare_part_id']] = [
                'quantity_available' => (int)$row['quantity_available'],
                'min_threshold' => (int)$row['min_threshold'],
            ];
        }

        return $map;
    }

    /**
     * Demanda pendiente de averías activas, sumada por técnico y pieza.
     *
     * Solo solicitudes catalogadas (`spare_part_id IS NOT NULL`): la pieza fuera
     * de catálogo no se puede cruzar con una referencia, y el servicio la trata
     * como aviso, no como cantidad (QA 2).
     *
     * @return array<int, array<int, int>>
     */
    private function fetchPendingRequestMap(?int $technicianId): array
    {
        $sql = 'SELECT i.assigned_technician_id, spr.spare_part_id, SUM(spr.quantity) AS pending_request_qty
                  FROM spare_part_requests spr
                  INNER JOIN incidents i ON i.id = spr.incident_id
                 WHERE spr.status = \'PENDING\'
                   AND spr.spare_part_id IS NOT NULL
                   AND i.assigned_technician_id IS NOT NULL
                   AND i.deleted_at IS NULL
                   AND i.status IN (' . self::ACTIVE_INCIDENT_STATUSES . ')';
        $params = [];

        if ($technicianId !== null) {
            $sql .= ' AND i.assigned_technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $sql .= ' GROUP BY i.assigned_technician_id, spr.spare_part_id
                  ORDER BY i.assigned_technician_id ASC, spr.spare_part_id ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['assigned_technician_id']][(int)$row['spare_part_id']] = (int)$row['pending_request_qty'];
        }

        return $map;
    }

    // ---------------------------------------------------------------------
    // Validaciones de escritura (fail-fast, Art. I.3)
    // ---------------------------------------------------------------------

    /**
     * Contrato de las líneas pedidas: al menos una, sin piezas repetidas, con
     * cantidades y snapshots dentro de rango y con un motivo del vocabulario.
     *
     * @param array<int, mixed> $items
     * @return array<int, VanRestockOrderItem> Indexado por `spare_part_id`.
     */
    private function assertItemsContract(array $items): array
    {
        if ($items === []) {
            throw VanRestockOrderValidationException::invalidPayload(
                'la orden debe contener al menos una línea activa.'
            );
        }

        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            if (!$item instanceof VanRestockOrderItem) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la línea %d no es una línea de orden de carga válida.', $index + 1)
                );
            }

            $sparePartId = $item->sparePartId;

            if ($sparePartId < 1) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la línea %d no identifica una pieza del catálogo.', $index + 1)
                );
            }

            if (isset($normalized[$sparePartId])) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la pieza %d aparece repetida en la orden.', $sparePartId)
                );
            }

            if ($item->requestedQty < 1 || $item->requestedQty > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la cantidad solicitada de la pieza %d debe estar entre 1 y %d unidades.',
                        $sparePartId,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }

            if (!in_array($item->reason, [
                VanRestockOrder::REASON_LOW_STOCK,
                VanRestockOrder::REASON_PENDING_REQUEST,
                VanRestockOrder::REASON_BOTH,
                VanRestockOrder::REASON_MANUAL,
            ], true)) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la pieza %d declara un motivo de reposición desconocido.', $sparePartId)
                );
            }

            if ($item->currentQuantitySnapshot < -999
                || $item->currentQuantitySnapshot > VanRestockOrder::QUANTITY_MAX
            ) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('el saldo declarado de la pieza %d está fuera del rango permitido.', $sparePartId)
                );
            }

            if ($item->minThresholdSnapshot < 0
                || $item->minThresholdSnapshot > VanRestockOrder::QUANTITY_MAX
                || $item->pendingRequestQtySnapshot < 0
                || $item->pendingRequestQtySnapshot > VanRestockOrder::QUANTITY_MAX
            ) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('el umbral o la demanda declarados de la pieza %d están fuera del rango permitido.', $sparePartId)
                );
            }

            $normalized[$sparePartId] = $item;
        }

        return $normalized;
    }

    /**
     * Bloquea y valida al destinatario: rol técnico, cuenta activa y no borrado.
     *
     * El `FOR UPDATE` serializa la creación por técnico antes de comprobar el
     * hueco abierto (D3).
     *
     * @return array<string, mixed>
     */
    private function assertTechnicianEligible(int $technicianId, bool $lock): array
    {
        $sql = 'SELECT id, name, role, is_active
                  FROM users
                 WHERE id = :technician_id
                   AND deleted_at IS NULL
                 LIMIT 1' . ($lock ? ' FOR UPDATE' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw VanRestockOrderValidationException::technicianNotEligible(
                'el usuario no existe o está dado de baja.'
            );
        }

        if (strtoupper((string)$row['role']) !== 'TECHNICIAN') {
            throw VanRestockOrderValidationException::technicianNotEligible(
                'el usuario no tiene rol de técnico de campo.'
            );
        }

        if (!(bool)$row['is_active']) {
            throw VanRestockOrderValidationException::technicianNotEligible(
                'la cuenta del técnico está inactiva.'
            );
        }

        return $row;
    }

    /**
     * Comprueba que todas las piezas pedidas siguen activas en el catálogo.
     *
     * @param list<int> $sparePartIds
     */
    private function assertSparePartsEligible(array $sparePartIds): void
    {
        if ($sparePartIds === []) {
            throw VanRestockOrderValidationException::invalidPayload(
                'la orden debe contener al menos una pieza del catálogo.'
            );
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($sparePartIds) as $index => $sparePartId) {
            $placeholder = ':part_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $sparePartId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM spare_parts
              WHERE is_active = TRUE
                AND deleted_at IS NULL
                AND id IN (' . implode(', ', $placeholders) . ')'
        );
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($sparePartIds as $sparePartId) {
            if (!in_array($sparePartId, $found, true)) {
                throw VanRestockOrderValidationException::sparePartNotEligible(
                    $sparePartId,
                    'no existe, está inactiva o no pertenece al catálogo vivo.'
                );
            }
        }
    }

    /**
     * Tope doble `9999` sobre el saldo real de la furgoneta al crear o editar
     * (QA 1): `quantity_available + requested_qty <= 9999`.
     *
     * @param array<int, VanRestockOrderItem> $items
     */
    private function assertProjectedCapacity(int $technicianId, array $items): void
    {
        $placeholders = [];
        $params = [':technician_id' => $technicianId];

        foreach (array_keys($items) as $index => $sparePartId) {
            $placeholder = ':part_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int)$sparePartId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT spare_part_id, quantity_available
               FROM technician_van_stocks
              WHERE technician_id = :technician_id
                AND spare_part_id IN (' . implode(', ', $placeholders) . ')'
        );
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $available = [];
        foreach ($stmt->fetchAll() as $row) {
            $available[(int)$row['spare_part_id']] = (int)$row['quantity_available'];
        }

        foreach ($items as $sparePartId => $item) {
            $current = $available[$sparePartId] ?? 0;

            if ($current + $item->requestedQty > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la pieza %d dejaría el saldo de la furgoneta en %d unidades, por encima del tope de %d.',
                        $sparePartId,
                        $current + $item->requestedQty,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }
        }
    }

    /**
     * Tope `9999` en la expedición: `saldo actual + dispatched_qty <= 9999`.
     *
     * @param array<int, array<string, mixed>> $activeByPart
     * @param array<int, int> $declared
     */
    private function assertDispatchCapacity(int $technicianId, array $activeByPart, array $declared): void
    {
        $placeholders = [];
        $params = [':technician_id' => $technicianId];

        foreach (array_keys($activeByPart) as $index => $sparePartId) {
            $placeholder = ':part_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $sparePartId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT spare_part_id, quantity_available
               FROM technician_van_stocks
              WHERE technician_id = :technician_id
                AND spare_part_id IN (' . implode(', ', $placeholders) . ')'
        );
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $available = [];
        foreach ($stmt->fetchAll() as $row) {
            $available[(int)$row['spare_part_id']] = (int)$row['quantity_available'];
        }

        foreach ($declared as $sparePartId => $dispatchedQty) {
            $projected = ($available[$sparePartId] ?? 0) + $dispatchedQty;

            if ($projected > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la pieza %s dejaría el saldo de la furgoneta en %d unidades, por encima del tope de %d.',
                        (string)$activeByPart[$sparePartId]['part_code'],
                        $projected,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }
        }
    }

    /**
     * Normaliza las cantidades servidas declaradas por almacén.
     *
     * @param array<int|string, mixed> $dispatchedQuantities
     * @return array<int, int>
     */
    private function normalizeDispatchedQuantities(array $dispatchedQuantities): array
    {
        $normalized = [];

        foreach ($dispatchedQuantities as $sparePartId => $quantity) {
            if (!is_numeric($sparePartId) || !is_numeric($quantity)) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'las cantidades despachadas deben llegar como mapa de pieza a unidades.'
                );
            }

            $partId = (int)$sparePartId;
            $qty = (int)$quantity;

            if ($partId < 1) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'las cantidades despachadas contienen una pieza ilegible.'
                );
            }

            if ($qty < 0 || $qty > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la cantidad despachada de la pieza %d debe estar entre 0 y %d unidades.',
                        $partId,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }

            if (isset($normalized[$partId])) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la pieza %d aparece repetida en la expedición.', $partId)
                );
            }

            $normalized[$partId] = $qty;
        }

        return $normalized;
    }

    // ---------------------------------------------------------------------
    // Lectura de filas y ensamblado de entidades
    // ---------------------------------------------------------------------

    /**
     * Bloquea la cabecera y la devuelve, o falla con `404` (D1).
     *
     * @return array<string, mixed>
     */
    private function lockOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, technician_id, status, notes
               FROM van_restock_orders
              WHERE id = :id
              FOR UPDATE'
        );
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        return $row;
    }

    /**
     * Bloquea la cabecera para la confirmación móvil y trae el nombre del titular
     * en el mismo viaje, sin bloquear la fila de `users`.
     *
     * @return array<string, mixed>
     */
    private function lockOrderForConfirmation(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.technician_id, o.status, t.name AS technician_name
               FROM van_restock_orders o
               INNER JOIN users t ON t.id = o.technician_id
              WHERE o.id = :id
              FOR UPDATE OF o'
        );
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOpenOrderRow(int $technicianId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status
               FROM van_restock_orders
              WHERE technician_id = :technician_id
                AND status IN (\'PENDING_PICKING\', \'DISPATCHED\')
              ORDER BY created_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItemRows(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ITEM_COLUMNS . '
               FROM van_restock_order_items i
               INNER JOIN spare_parts sp ON sp.id = i.spare_part_id
              WHERE i.order_id = :order_id
              ORDER BY ' . self::ITEM_ORDER
        );
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Líneas vigentes con su pieza, para expedir y confirmar.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchActiveItemRowsWithPart(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ITEM_COLUMNS . '
               FROM van_restock_order_items i
               INNER JOIN spare_parts sp ON sp.id = i.spare_part_id
              WHERE i.order_id = :order_id
                AND i.removed_at IS NULL
              ORDER BY i.spare_part_id ASC'
        );
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<VanRestockOrder>
     */
    private function hydrateOrders(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $orderIds = array_map(static fn (array $row): int => (int)$row['id'], $rows);
        $itemsByOrder = $this->fetchItemsByOrderIds($orderIds);

        $orders = [];
        foreach ($rows as $row) {
            $orderId = (int)$row['id'];
            $orders[] = $this->hydrateOrder($row, $itemsByOrder[$orderId] ?? []);
        }

        return $orders;
    }

    /**
     * Líneas de varias órdenes en un solo viaje, agrupadas por orden.
     *
     * Evita el N+1 del listado de Coordinación: una consulta de cabeceras y otra
     * de líneas, independientemente del número de órdenes.
     *
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function fetchItemsByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($orderIds) as $index => $orderId) {
            $placeholder = ':order_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $orderId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ITEM_COLUMNS . '
               FROM van_restock_order_items i
               INNER JOIN spare_parts sp ON sp.id = i.spare_part_id
              WHERE i.order_id IN (' . implode(', ', $placeholders) . ')
              ORDER BY i.order_id ASC, ' . self::ITEM_ORDER
        );
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int)$row['order_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $itemRows
     */
    private function hydrateOrder(array $row, array $itemRows): VanRestockOrder
    {
        return new VanRestockOrder(
            (int)$row['id'],
            (int)$row['technician_id'],
            (string)$row['technician_name'],
            $row['technician_operator_code'] === null ? null : (string)$row['technician_operator_code'],
            (bool)$row['technician_is_active'],
            (bool)$row['recipient_currently_absent'],
            (string)$row['status'],
            $row['notes'] === null ? null : (string)$row['notes'],
            (int)$row['created_by'],
            (string)$row['created_by_name'],
            (string)$row['created_at'],
            $row['dispatched_by'] === null ? null : (int)$row['dispatched_by'],
            $row['dispatched_by_name'] === null ? null : (string)$row['dispatched_by_name'],
            $row['dispatched_at'] === null ? null : (string)$row['dispatched_at'],
            $row['confirmed_by'] === null ? null : (int)$row['confirmed_by'],
            $row['confirmed_by_name'] === null ? null : (string)$row['confirmed_by_name'],
            $row['confirmed_at'] === null ? null : (string)$row['confirmed_at'],
            $row['cancelled_by'] === null ? null : (int)$row['cancelled_by'],
            $row['cancelled_by_name'] === null ? null : (string)$row['cancelled_by_name'],
            $row['cancelled_at'] === null ? null : (string)$row['cancelled_at'],
            $row['cancellation_reason'] === null ? null : (string)$row['cancellation_reason'],
            $row['reception_note'] === null ? null : (string)$row['reception_note'],
            (string)$row['updated_at'],
            array_map(fn (array $itemRow): VanRestockOrderItem => $this->hydrateItem($itemRow), $itemRows)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateItem(array $row): VanRestockOrderItem
    {
        return new VanRestockOrderItem(
            (int)$row['id'],
            (int)$row['order_id'],
            (int)$row['spare_part_id'],
            (string)$row['part_code'],
            (string)$row['part_name'],
            (string)$row['category'],
            (int)$row['current_quantity_snapshot'],
            (int)$row['min_threshold_snapshot'],
            (int)$row['pending_request_qty_snapshot'],
            (int)$row['requested_qty'],
            $row['dispatched_qty'] === null ? null : (int)$row['dispatched_qty'],
            (string)$row['reason'],
            $row['removed_at'] === null ? null : (string)$row['removed_at'],
            $row['removed_by'] === null ? null : (int)$row['removed_by']
        );
    }

    private function requireOrder(int $orderId): VanRestockOrder
    {
        $order = $this->findById($orderId);

        if ($order === null) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        return $order;
    }

    private function insertItem(int $orderId, VanRestockOrderItem $item): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO van_restock_order_items (
                order_id, spare_part_id, current_quantity_snapshot, min_threshold_snapshot,
                pending_request_qty_snapshot, requested_qty, reason
             ) VALUES (
                :order_id, :spare_part_id, :current_quantity_snapshot, :min_threshold_snapshot,
                :pending_request_qty_snapshot, :requested_qty, :reason
             )'
        );
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue(':spare_part_id', $item->sparePartId, PDO::PARAM_INT);
        $stmt->bindValue(':current_quantity_snapshot', $item->currentQuantitySnapshot, PDO::PARAM_INT);
        $stmt->bindValue(':min_threshold_snapshot', $item->minThresholdSnapshot, PDO::PARAM_INT);
        $stmt->bindValue(':pending_request_qty_snapshot', $item->pendingRequestQtySnapshot, PDO::PARAM_INT);
        $stmt->bindValue(':requested_qty', $item->requestedQty, PDO::PARAM_INT);
        $stmt->bindValue(':reason', $item->reason, PDO::PARAM_STR);
        $stmt->execute();
    }

    // ---------------------------------------------------------------------
    // Auditoría inmutable (Art. III.3, contrato §6)
    // ---------------------------------------------------------------------

    /**
     * Evento de auditoría del actor, resolviendo su rol y nombre en la tabla.
     *
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     */
    private function recordAudit(
        int $orderId,
        string $action,
        int $actorUserId,
        ?array $previousState,
        array $newState
    ): void {
        $actor = $this->resolveActor($actorUserId);

        $this->recordAuditWithActor(
            $orderId,
            $action,
            $actorUserId,
            $actor['role'],
            $actor['name'],
            $previousState,
            $newState
        );
    }

    /**
     * Evento de auditoría con el actor ya resuelto (la confirmación móvil ya
     * conoce el nombre del titular y no necesita un viaje extra).
     *
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     */
    private function recordAuditWithActor(
        int $orderId,
        string $action,
        int $actorUserId,
        string $actorRole,
        string $actorName,
        ?array $previousState,
        array $newState
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata, created_at
             ) VALUES (
                :entity_type, :entity_id, :action, :user_id, :user_role, :user_name,
                :previous_state, :new_state, :metadata, :created_at
             )'
        );

        $stmt->bindValue(':entity_type', self::AUDIT_ENTITY_TYPE, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $actorUserId, PDO::PARAM_INT);
        $stmt->bindValue(':user_role', $actorRole, PDO::PARAM_STR);
        $stmt->bindValue(':user_name', $actorName, PDO::PARAM_STR);
        $stmt->bindValue(
            ':previous_state',
            $previousState === null ? null : $this->encodeJson($previousState),
            $previousState === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(':new_state', $this->encodeJson($newState), PDO::PARAM_STR);
        $stmt->bindValue(
            ':metadata',
            $this->encodeJson(['order_code' => $this->formatOrderCode($orderId)]),
            PDO::PARAM_STR
        );
        $stmt->bindValue(':created_at', $this->now(), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array{role: string, name: string}
     */
    private function resolveActor(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT role, name FROM users WHERE id = :user_id LIMIT 1');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InvalidArgumentException(
                "No existe el usuario {$userId} que pretende modificar una orden de carga."
            );
        }

        return [
            'role' => strtoupper((string)$row['role']),
            'name' => (string)$row['name'],
        ];
    }

    /**
     * @param array<int, VanRestockOrderItem>|list<array<string, mixed>> $items
     * @return list<array<string, int|string>>
     */
    private function auditItemRows(array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            if ($item instanceof VanRestockOrderItem) {
                $rows[] = [
                    'spare_part_id' => $item->sparePartId,
                    'part_code' => $item->partCode,
                    'current_quantity_snapshot' => $item->currentQuantitySnapshot,
                    'min_threshold_snapshot' => $item->minThresholdSnapshot,
                    'pending_request_qty_snapshot' => $item->pendingRequestQtySnapshot,
                    'requested_qty' => $item->requestedQty,
                    'reason' => $item->reason,
                ];
                continue;
            }

            $rows[] = [
                'spare_part_id' => (int)$item['spare_part_id'],
                'part_code' => (string)$item['part_code'],
                'current_quantity_snapshot' => (int)$item['current_quantity_snapshot'],
                'min_threshold_snapshot' => (int)$item['min_threshold_snapshot'],
                'pending_request_qty_snapshot' => (int)$item['pending_request_qty_snapshot'],
                'requested_qty' => (int)$item['requested_qty'],
                'reason' => (string)$item['reason'],
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return string|null `OPEN`, uno de los cuatro estados, o `null` para todas.
     */
    private function normalizeStatusFilter(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtoupper(trim($status));

        if ($normalized === 'OPEN' || in_array($normalized, VanRestockOrder::STATUSES, true)) {
            return $normalized;
        }

        throw new InvalidArgumentException(
            "Estado de orden de carga desconocido: {$status}."
        );
    }

    private function formatOrderCode(int $orderId): string
    {
        return 'RO-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Marca anclada a la zona operativa, igual que el rastro del resto del
     * sistema: una marca sin desfase la reinterpretaría el motor en su huso.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
            ->format('Y-m-d\TH:i:sP');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function isOpenOrderUniqueViolation(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        return (string)$exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'uq_van_restock_open_per_tech');
    }

    private function isCheckConstraintViolation(PDOException $exception): bool
    {
        return (string)$exception->getCode() === '23514';
    }

    /**
     * Código de la orden abierta tras perder la carrera del índice único.
     *
     * Solo se consulta fuera de una transacción propia (ya deshecha): dentro de
     * una transacción ajena abortada el motor rechazaría cualquier `SELECT`, y
     * entonces se informa con un código genérico sin inventar un identificador.
     */
    private function resolveOpenOrderCodeAfterConflict(int $technicianId): string
    {
        if ($this->pdo->inTransaction()) {
            return 'vigente';
        }

        $open = $this->findOpenOrderRow($technicianId);

        return $open === null ? 'vigente' : $this->formatOrderCode((int)$open['id']);
    }
}
