<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Application\DTO\VanRestockProposalDto;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;

/**
 * VanRestockOrderService
 *
 * Casos de uso de las órdenes de carga de furgoneta (Módulo 23, RF-01 a RF-05).
 *
 * QUÉ HACE ESTE SERVICIO Y QUÉ NO
 *
 *  - **Pre-cálculo (`getProposals`).** Cruza el catálogo activo con el saldo y
 *    el umbral de cada furgoneta y con la demanda pendiente de sus averías
 *    activas, y clasifica cada pieza en `LOW_STOCK`, `PENDING_REQUEST` o `BOTH`.
 *    Aplica el tope doble `min(9999, 9999 - disponible, bruto)` (QA 1) y el
 *    orden determinista de `QA 5`.
 *  - **Ciclo de la orden.** Crear, editar en `PENDING_PICKING` con baja lógica,
 *    expedir, cancelar con justificación y confirmar en el móvil. La
 *    persistencia y la atomicidad viven en el repositorio; aquí se valida el
 *    contrato de entrada y se componen las líneas con sus snapshots.
 *  - **Nada de turnos.** `confirmOrder()` no consulta `technician_shifts`: la
 *    recepción móvil se permite en turno, en pausa o fuera de turno (QA 4).
 *  - **Nada de averías.** Confirmar una carga no cambia el estado de una avería
 *    ni de una `spare_part_request` (C5, QA 3). El servicio no emite ninguna de
 *    esas escrituras.
 *  - **Cero coste.** Las líneas se construyen con unidades, umbrales y demanda;
 *    ningún campo monetario entra ni sale de este servicio (Art. V.4).
 *
 * Las validaciones se repiten en el repositorio a propósito: aquí se falla antes
 * de abrir una transacción y allí se protege el motor frente a cualquier otro
 * consumidor. Duplicar una comprobación barata es preferible a confiar en que
 * todos los llamadores la hicieron.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class VanRestockOrderService
{
    /** Motivos de omisión del lote (contrato §5.6, QA 4). */
    public const SKIP_ACTIVE_ABSENCE = 'SKIPPED_ACTIVE_ABSENCE';
    public const SKIP_OPEN_ORDER_EXISTS = 'SKIPPED_OPEN_ORDER_EXISTS';
    public const SKIP_NO_DEFICIT = 'SKIPPED_NO_DEFICIT';

    private VanRestockOrderRepositoryInterface $repository;

    public function __construct(VanRestockOrderRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Propuestas de reposición pre-calculadas (RF-02.1, RF-02.3, QA 1, QA 5).
     *
     * @param int|null $technicianId Limita la propuesta a un técnico; `null`
     *        recorre la plantilla activa de la foto.
     * @return list<VanRestockProposalDto>
     */
    public function getProposals(?int $technicianId = null): array
    {
        if ($technicianId !== null && $technicianId < 1) {
            throw new InvalidArgumentException('El identificador del técnico debe ser un entero positivo.');
        }

        $inputs = $this->repository->fetchProposalInputs($technicianId);
        $parts = $this->indexSpareParts($inputs['spare_parts'] ?? []);

        $proposals = [];
        foreach ($inputs['technicians'] ?? [] as $technician) {
            $proposals[] = new VanRestockProposalDto(
                (int)$technician['technician_id'],
                (string)$technician['technician_name'],
                (bool)$technician['is_active'],
                (bool)$technician['is_currently_absent'],
                $technician['open_order_id'] === null ? null : (int)$technician['open_order_id'],
                $technician['open_order_status'] === null ? null : (string)$technician['open_order_status'],
                $this->buildProposalLines(
                    (int)$technician['technician_id'],
                    $parts,
                    $inputs
                )
            );
        }

        return $proposals;
    }

    /**
     * Crea una orden individual en `PENDING_PICKING` (RF-02.1, RF-02.3, D3).
     *
     * `lines` son las líneas del contrato §5.5: `spare_part_id`, `requested_qty`
     * y `reason` opcional (`MANUAL` por defecto). Los snapshots de saldo, umbral
     * y demanda se leen de la foto actual; no se aceptan del cliente porque el
     * albarán tiene que explicar la orden con los datos que vio el servidor.
     *
     * Un técnico activo con ausencia vigente sí puede recibir una orden
     * individual: la alerta viaja en `recipient_currently_absent` y la decisión
     * de prepararla antes de su reincorporación es de Coordinación (QA 4).
     *
     * @param list<array<string, mixed>> $lines
     * @throws VanRestockOrderValidationException
     */
    public function createOrder(
        int $technicianId,
        ?string $notes,
        array $lines,
        int $actorUserId
    ): VanRestockOrder {
        $inputs = $this->repository->fetchProposalInputs($technicianId);
        $technician = $this->requireEligibleTechnician($inputs, $technicianId);

        if ($technician['open_order_id'] !== null) {
            throw VanRestockOrderValidationException::openOrderAlreadyExists(
                $this->orderCode((int)$technician['open_order_id'])
            );
        }

        $items = $this->buildOrderItems($technicianId, $lines, $inputs);

        return $this->repository->createOrder($technicianId, $notes, $items, $actorUserId);
    }

    /**
     * Genera en lote las órdenes de los técnicos con déficit (RF-02.2, QA 4).
     *
     * Omite, en este orden: al técnico con ausencia vigente, al que ya tiene una
     * orden abierta y al que no tiene ninguna línea sugerida. El resultado
     * conserva el desglose de creadas y omitidas para que Coordinación pueda
     * explicar por qué no se preparó una caja.
     *
     * @return array{
     *   created_count: int,
     *   skipped_count: int,
     *   created_orders: list<array{
     *     id: int,
     *     order_code: string,
     *     technician_id: int,
     *     technician_name: string,
     *     active_items_count: int,
     *     total_requested_units: int
     *   }>,
     *   skipped_technicians: list<array{
     *     technician_id: int,
     *     technician_name: string,
     *     skip_reason: string,
     *     open_order_id: int|null
     *   }>,
     *   message: string
     * }
     */
    public function generateBatch(?string $notes, int $actorUserId): array
    {
        $created = [];
        $skipped = [];

        foreach ($this->getProposals(null) as $proposal) {
            $skipReason = null;

            if ($proposal->recipientCurrentlyAbsent) {
                $skipReason = self::SKIP_ACTIVE_ABSENCE;
            } elseif ($proposal->hasOpenOrder()) {
                $skipReason = self::SKIP_OPEN_ORDER_EXISTS;
            } elseif (!$proposal->hasLines()) {
                $skipReason = self::SKIP_NO_DEFICIT;
            }

            if ($skipReason !== null) {
                $skipped[] = [
                    'technician_id' => $proposal->technicianId,
                    'technician_name' => $proposal->technicianName,
                    'skip_reason' => $skipReason,
                    'open_order_id' => $proposal->openOrderId,
                ];
                continue;
            }

            $items = $this->itemsFromProposal($proposal);

            try {
                $order = $this->repository->createOrder(
                    $proposal->technicianId,
                    $notes,
                    $items,
                    $actorUserId
                );
            } catch (VanRestockOrderValidationException $exception) {
                // Una carrera con otra creación del mismo técnico o un cambio sobrevenido
                // no debe abortar el lote entero: se informa como omitido y se sigue.
                if (
                    $exception->getErrorCode() === VanRestockOrderValidationException::OPEN_RESTOCK_ORDER_ALREADY_EXISTS
                    || $exception->getErrorCode() === VanRestockOrderValidationException::TECHNICIAN_NOT_ELIGIBLE
                ) {
                    $skipped[] = [
                        'technician_id' => $proposal->technicianId,
                        'technician_name' => $proposal->technicianName,
                        'skip_reason' => $exception->getErrorCode() === VanRestockOrderValidationException::OPEN_RESTOCK_ORDER_ALREADY_EXISTS
                            ? self::SKIP_OPEN_ORDER_EXISTS
                            : self::SKIP_ACTIVE_ABSENCE,
                        'open_order_id' => null,
                    ];
                    continue;
                }

                throw $exception;
            }

            $created[] = [
                'id' => $order->id,
                'order_code' => $order->orderCode(),
                'technician_id' => $order->technicianId,
                'technician_name' => $order->technicianName,
                'active_items_count' => $order->activeItemsCount(),
                'total_requested_units' => $order->totalRequestedUnits(),
            ];
        }

        return [
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'created_orders' => $created,
            'skipped_technicians' => $skipped,
            'message' => $this->batchMessage(count($created), count($skipped)),
        ];
    }

    /**
     * Edita las líneas y notas de una orden en `PENDING_PICKING` (RF-02.4, QA 1).
     *
     * La baja de una pieza ausente y la reactivación de una retirada las ejecuta
     * el repositorio sobre la misma fila: aquí solo se compone el conjunto
     * deseado de líneas vigentes.
     *
     * @param list<array<string, mixed>> $lines
     * @throws VanRestockOrderValidationException
     */
    public function updateOrder(
        int $orderId,
        ?string $notes,
        array $lines,
        int $actorUserId
    ): VanRestockOrder {
        $order = $this->repository->findById($orderId);

        if ($order === null) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        if (!$order->isPendingPicking()) {
            throw VanRestockOrderValidationException::invalidTransition($order->status, 'editar');
        }

        $inputs = $this->repository->fetchProposalInputs($order->technicianId);
        $items = $this->buildOrderItems($order->technicianId, $lines, $inputs);

        return $this->repository->updatePendingOrderWithSoftDelete(
            $orderId,
            $notes,
            $items,
            $actorUserId
        );
    }

    /**
     * Expide la orden con las cantidades reales de almacén (RF-04.1, D1, QA 1).
     *
     * @param array<int|string, mixed> $dispatchedQuantities Mapa pieza => unidades.
     * @throws VanRestockOrderValidationException
     */
    public function dispatchOrder(
        int $orderId,
        array $dispatchedQuantities,
        int $actorUserId
    ): VanRestockOrder {
        $normalized = $this->normalizeDispatchedQuantities($dispatchedQuantities);

        if ($normalized === []) {
            throw VanRestockOrderValidationException::invalidPayload(
                'la expedición debe declarar al menos una línea activa.'
            );
        }

        if (array_sum($normalized) < 1) {
            throw VanRestockOrderValidationException::invalidPayload(
                'la expedición debe servir al menos una unidad; si no hay material, procede cancelar la orden.'
            );
        }

        return $this->repository->markDispatched($orderId, $normalized, $actorUserId);
    }

    /**
     * Cancela una orden abierta con justificación real (RF-04.2, Art. V.1).
     *
     * @throws VanRestockOrderValidationException
     */
    public function cancelOrder(
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

        return $this->repository->markCancelled($orderId, $reason, $actorUserId);
    }

    /**
     * Confirmación móvil en 1 toque (RF-05.2, QA 4, RNF-02).
     *
     * No hay ninguna lectura de `technician_shifts` en este camino: la
     * recepción se permite en turno, en pausa o fuera de turno. La pertenencia
     * de la orden al técnico autenticado la impone el repositorio bajo el
     * cerrojo de la cabecera (Art. V.4).
     *
     * @return array{
     *   order: VanRestockOrder,
     *   updated_van_stock: list<array{
     *     spare_part_id: int,
     *     part_code: string,
     *     part_name: string,
     *     previous_quantity_available: int,
     *     dispatched_qty: int,
     *     new_quantity_available: int,
     *     min_threshold: int,
     *     is_below_threshold: bool
     *   }>
     * }
     */
    public function confirmOrder(
        int $orderId,
        int $authenticatedTechnicianId,
        ?string $receptionNote = null
    ): array {
        $cleanNote = null;
        if ($receptionNote !== null) {
            $cleanNote = trim($receptionNote);
            if ($cleanNote === '') {
                $cleanNote = null;
            } elseif (mb_strlen($cleanNote, 'UTF-8') > 500) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'la nota de recepción no puede superar los 500 caracteres.'
                );
            }
        }

        return $this->repository->confirmOrderAndIncrementVanStock(
            $orderId,
            $authenticatedTechnicianId,
            $cleanNote
        );
    }

    // ---------------------------------------------------------------------
    // Motor de pre-cálculo (RF-02.1, QA 1, QA 5)
    // ---------------------------------------------------------------------

    /**
     * Calcula las líneas de una furgoneta con las tres fórmulas del contrato.
     *
     * Precedencia exacta:
     *   1. `LOW_STOCK`: no hay demanda y la pieza está en umbral o por debajo.
     *   2. `BOTH`: hay demanda y, o bien ya está en umbral, o el umbral es
     *      positivo y restar la demanda dejaría la furgoneta en umbral.
     *   3. `PENDING_REQUEST`: solo con umbral `0` y demanda superior al saldo.
     *
     * Una pieza sin fila en `technician_van_stocks` no está «bajo umbral»: no
     * hay umbral declarado que incumplir. Solo la demanda puede incorporarla.
     *
     * @param array<int, array{spare_part_id: int, part_code: string, part_name: string, category: string}> $parts
     * @param array<string, mixed> $inputs
     * @return list<array<string, int|string>>
     */
    private function buildProposalLines(int $technicianId, array $parts, array $inputs): array
    {
        $stockByPart = $inputs['van_stock'][$technicianId] ?? [];
        $pendingByPart = $inputs['pending_requests'][$technicianId] ?? [];

        $lines = [];

        foreach ($parts as $part) {
            $sparePartId = $part['spare_part_id'];
            $hasStockRow = array_key_exists($sparePartId, $stockByPart);
            $currentQuantity = $hasStockRow ? (int)$stockByPart[$sparePartId]['quantity_available'] : 0;
            $minThreshold = $hasStockRow ? (int)$stockByPart[$sparePartId]['min_threshold'] : 0;
            $pendingRequestQty = (int)($pendingByPart[$sparePartId] ?? 0);

            $reason = null;
            $rawQty = 0;

            if ($pendingRequestQty === 0 && $hasStockRow && $currentQuantity <= $minThreshold) {
                $reason = VanRestockOrder::REASON_LOW_STOCK;
                $rawQty = ($minThreshold - $currentQuantity) + 1;
            } elseif (
                $pendingRequestQty > 0
                && (
                    ($hasStockRow && $currentQuantity <= $minThreshold)
                    || ($minThreshold > 0 && ($currentQuantity - $pendingRequestQty) <= $minThreshold)
                )
            ) {
                $reason = VanRestockOrder::REASON_BOTH;
                $rawQty = ($minThreshold + 1 + $pendingRequestQty) - $currentQuantity;
            } elseif ($pendingRequestQty > 0 && $minThreshold === 0 && $pendingRequestQty > $currentQuantity) {
                $reason = VanRestockOrder::REASON_PENDING_REQUEST;
                $rawQty = $pendingRequestQty - max(0, $currentQuantity);
            }

            if ($reason === null) {
                continue;
            }

            $maxAddable = VanRestockOrder::QUANTITY_MAX - $currentQuantity;
            $maxAllowed = min(VanRestockOrder::QUANTITY_MAX, $maxAddable);
            $suggestedQty = min(VanRestockOrder::QUANTITY_MAX, $maxAddable, $rawQty);

            // La furgoneta ya está llena: no hay línea que pedir (QA 1).
            if ($suggestedQty < 1 || $maxAllowed < 1) {
                continue;
            }

            $lines[] = [
                'spare_part_id' => $sparePartId,
                'part_code' => $part['part_code'],
                'part_name' => $part['part_name'],
                'category' => $part['category'],
                'current_quantity' => $currentQuantity,
                'min_threshold' => $minThreshold,
                'pending_request_qty' => $pendingRequestQty,
                'suggested_qty' => $suggestedQty,
                'max_allowed_qty' => $maxAllowed,
                'reason' => $reason,
            ];
        }

        // El DTO vuelve a ordenar en su constructor; se ordena aquí también para
        // que el motor sea determinista por sí mismo y no dependa del envoltorio.
        usort($lines, [VanRestockProposalDto::class, 'compareLines']);

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $spareParts
     * @return array<int, array{spare_part_id: int, part_code: string, part_name: string, category: string}>
     */
    private function indexSpareParts(array $spareParts): array
    {
        $indexed = [];

        foreach ($spareParts as $part) {
            $sparePartId = (int)$part['spare_part_id'];

            if ($sparePartId < 1) {
                throw new InvalidArgumentException(
                    'El catálogo de la propuesta contiene una pieza sin identificador válido.'
                );
            }

            $indexed[$sparePartId] = [
                'spare_part_id' => $sparePartId,
                'part_code' => (string)$part['part_code'],
                'part_name' => (string)$part['part_name'],
                'category' => (string)$part['category'],
            ];
        }

        return $indexed;
    }

    // ---------------------------------------------------------------------
    // Líneas de orden (RF-02.1, RF-02.4, QA 1)
    // ---------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $inputs
     * @return list<VanRestockOrderItem>
     */
    private function buildOrderItems(int $technicianId, array $lines, array $inputs): array
    {
        $normalized = $this->normalizeLines($lines);
        $parts = $this->indexSpareParts($inputs['spare_parts'] ?? []);
        $stockByPart = $inputs['van_stock'][$technicianId] ?? [];
        $pendingByPart = $inputs['pending_requests'][$technicianId] ?? [];

        $items = [];

        foreach ($normalized as $line) {
            $sparePartId = $line['spare_part_id'];

            if (!isset($parts[$sparePartId])) {
                throw VanRestockOrderValidationException::sparePartNotEligible(
                    $sparePartId,
                    'no existe o está inactiva en el catálogo maestro.'
                );
            }

            $hasStockRow = array_key_exists($sparePartId, $stockByPart);
            $currentQuantity = $hasStockRow ? (int)$stockByPart[$sparePartId]['quantity_available'] : 0;
            $minThreshold = $hasStockRow ? (int)$stockByPart[$sparePartId]['min_threshold'] : 0;
            $pendingRequestQty = (int)($pendingByPart[$sparePartId] ?? 0);

            if ($currentQuantity + $line['requested_qty'] > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la pieza %s dejaría el saldo de la furgoneta en %d unidades, por encima del tope de %d.',
                        $parts[$sparePartId]['part_code'],
                        $currentQuantity + $line['requested_qty'],
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }

            $items[] = VanRestockOrderItem::requested(
                $sparePartId,
                $parts[$sparePartId]['part_code'],
                $parts[$sparePartId]['part_name'],
                $parts[$sparePartId]['category'],
                $currentQuantity,
                $minThreshold,
                $pendingRequestQty,
                $line['requested_qty'],
                $line['reason']
            );
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array{spare_part_id: int, requested_qty: int, reason: string}>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw VanRestockOrderValidationException::invalidPayload(
                'la orden debe contener al menos una línea activa.'
            );
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($lines) as $index => $line) {
            if (!is_array($line)) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la línea %d no es un objeto de línea válido.', $index + 1)
                );
            }

            $sparePartId = filter_var(
                $line['spare_part_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($sparePartId === false) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la línea %d no identifica una pieza del catálogo.', $index + 1)
                );
            }

            $requestedQty = filter_var(
                $line['requested_qty'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => [
                    'min_range' => 1,
                    'max_range' => VanRestockOrder::QUANTITY_MAX,
                ]]
            );

            if ($requestedQty === false) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la cantidad solicitada de la pieza %d debe estar entre 1 y %d unidades.',
                        $sparePartId,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }

            $reason = strtoupper(trim((string)($line['reason'] ?? VanRestockOrder::REASON_MANUAL)));
            if ($reason === '') {
                $reason = VanRestockOrder::REASON_MANUAL;
            }

            if (!in_array($reason, [
                VanRestockOrder::REASON_LOW_STOCK,
                VanRestockOrder::REASON_PENDING_REQUEST,
                VanRestockOrder::REASON_BOTH,
                VanRestockOrder::REASON_MANUAL,
            ], true)) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la pieza %d declara un motivo de reposición desconocido.', $sparePartId)
                );
            }

            if (isset($seen[$sparePartId])) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf('la pieza %d aparece repetida en la orden.', $sparePartId)
                );
            }

            $seen[$sparePartId] = true;
            $normalized[] = [
                'spare_part_id' => $sparePartId,
                'requested_qty' => $requestedQty,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }

    /**
     * Reconstruye las líneas de una propuesta ya calculada para el lote.
     *
     * No vuelve a consultar la foto: los snapshots que se congelan en la orden
     * son exactamente los que justificaron la sugerencia que vio Coordinación.
     *
     * @return list<VanRestockOrderItem>
     */
    private function itemsFromProposal(VanRestockProposalDto $proposal): array
    {
        $items = [];

        foreach ($proposal->items as $line) {
            $items[] = VanRestockOrderItem::requested(
                (int)$line['spare_part_id'],
                (string)$line['part_code'],
                (string)$line['part_name'],
                (string)$line['category'],
                (int)$line['current_quantity'],
                (int)$line['min_threshold'],
                (int)$line['pending_request_qty'],
                (int)$line['suggested_qty'],
                (string)$line['reason']
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private function requireEligibleTechnician(array $inputs, int $technicianId): array
    {
        foreach ($inputs['technicians'] ?? [] as $technician) {
            if ((int)$technician['technician_id'] !== $technicianId) {
                continue;
            }

            if (!(bool)$technician['is_active']) {
                throw VanRestockOrderValidationException::technicianNotEligible(
                    'la cuenta del técnico está inactiva.'
                );
            }

            return $technician;
        }

        throw VanRestockOrderValidationException::technicianNotEligible(
            'el usuario no existe, está dado de baja o no tiene rol de técnico de campo.'
        );
    }

    /**
     * @param array<int|string, mixed> $dispatchedQuantities
     * @return array<int, int>
     */
    private function normalizeDispatchedQuantities(array $dispatchedQuantities): array
    {
        $normalized = [];

        foreach ($dispatchedQuantities as $sparePartId => $quantity) {
            $partId = filter_var(
                $sparePartId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($partId === false) {
                throw VanRestockOrderValidationException::invalidPayload(
                    'las cantidades despachadas contienen una pieza ilegible.'
                );
            }

            $qty = filter_var(
                $quantity,
                FILTER_VALIDATE_INT,
                ['options' => [
                    'min_range' => 0,
                    'max_range' => VanRestockOrder::QUANTITY_MAX,
                ]]
            );

            if ($qty === false) {
                throw VanRestockOrderValidationException::invalidPayload(
                    sprintf(
                        'la cantidad despachada de la pieza %d debe estar entre 0 y %d unidades.',
                        $partId,
                        VanRestockOrder::QUANTITY_MAX
                    )
                );
            }

            $normalized[$partId] = $qty;
        }

        return $normalized;
    }

    private function orderCode(int $orderId): string
    {
        return 'RO-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    }

    private function batchMessage(int $createdCount, int $skippedCount): string
    {
        $createdLabel = $createdCount === 1 ? 'orden creada' : 'órdenes creadas';
        $skippedLabel = $skippedCount === 1 ? 'técnico omitido' : 'técnicos omitidos';

        return sprintf(
            'Generación por lotes completada: %d %s, %d %s.',
            $createdCount,
            $createdLabel,
            $skippedCount,
            $skippedLabel
        );
    }
}
