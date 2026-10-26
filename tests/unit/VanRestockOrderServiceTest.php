<?php

declare(strict_types=1);

/**
 * VanRestockOrderServiceTest · T-RESTOCK-09
 *
 * Certifica los casos de uso del servicio de órdenes de carga (Módulo 23,
 * RF-02 a RF-05, D1, D3, C3, QA 1, QA 4, Art. III.1 y Art. V.1) sin PostgreSQL.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Creación individual.** Técnico activo, rechazo de inactivo y de orden
 *      abierta, permiso para técnico ausente con su bandera (`QA 4`), snapshots
 *      tomados del servidor y no del cliente, y validación de líneas y tope.
 *   2. **Lote.** Desglose de creadas y omitidas por ausencia, orden abierta y
 *      falta de déficit; mensaje en castellano y orden congelado de la propuesta.
 *   3. **Edición.** Solo en `PENDING_PICKING`; la pieza retirada conserva su
 *      fila con `removed_at`/`removed_by` y se reactiva la misma fila al
 *      reañadirla, sin duplicar el registro (`QA 1`, Art. III.1).
 *   4. **Expedición y cancelación.** Expedición parcial con una línea a `0`
 *      que se conserva (`C3`), rechazo de suma nula, cancelación con el mínimo
 *      real de 20 caracteres (Art. V.1) y liberación del hueco sin tocar stock.
 *   5. **Confirmación móvil.** Propiedad de la orden, incremento atómico del
 *      saldo, rechazo del doble toque y confirmación tanto en jornada nominal
 *      `ON_SHIFT` como `OFF_SHIFT`: el servicio no consulta turnos (`QA 4`).
 *   6. **Privacidad.** Ninguna proyección expone coste (Art. V.4).
 *
 * El doble de repositorio mantiene órdenes y líneas en memoria con la misma
 * semántica de baja lógica que el adaptador PDO. Certifica el contrato que el
 * servicio exige al puerto; la transacción real la cubre la suite de integración.
 *
 * Uso:
 *   php tests/unit/VanRestockOrderServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\VanRestockOrderService;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;

/**
 * Repositorio en memoria con la semántica observable del puerto de órdenes.
 *
 * No simula PDO ni transacciones: mantiene el estado que el servicio necesita
 * para orquestar y aplica las mismas reglas de baja lógica, reactivación,
 * expedición y confirmación que el adaptador real.
 */
final class FakeVanRestockOrderRepositoryForServiceTest implements VanRestockOrderRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $photo;

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $items = [];

    private int $nextOrderId = 1000;

    private int $nextItemId = 5000;

    public int $createCalls = 0;

    public int $updateCalls = 0;

    public int $dispatchCalls = 0;

    public int $cancelCalls = 0;

    public int $confirmCalls = 0;

    /** No existe ninguna comprobación de turno en este puerto (QA 4). */
    public int $shiftLookups = 0;

    /**
     * Turno declarado por el entorno de prueba. El puerto no lo expone y el
     * servicio no lo consulta: se cambia solo para certificar que la recepción
     * móvil produce el mismo resultado en `ON_SHIFT` y en `OFF_SHIFT` (QA 4).
     */
    public string $technicianShiftState = 'ON_SHIFT';

    /**
     * @param array<string, mixed> $photo
     */
    public function __construct(array $photo)
    {
        $this->photo = $photo;
    }

    public function fetchProposalInputs(?int $technicianId = null): array
    {
        if ($technicianId === null) {
            $photo = $this->photo;
            $photo['technicians'] = array_values(array_filter(
                $this->photo['technicians'],
                static fn (array $technician): bool => (bool)$technician['is_active']
            ));

            return $photo;
        }

        $photo = $this->photo;
        $photo['technicians'] = array_values(array_filter(
            $this->photo['technicians'],
            static fn (array $technician): bool => (int)$technician['technician_id'] === $technicianId
        ));

        return $photo;
    }

    public function createOrder(
        int $technicianId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        $this->createCalls++;

        $open = $this->findOpenOrderForTechnician($technicianId);
        if ($open !== null) {
            throw VanRestockOrderValidationException::openOrderAlreadyExists($open->orderCode());
        }

        $orderId = ++$this->nextOrderId;
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->itemRowFromEntity($orderId, $item);
        }

        $this->orders[$orderId] = [
            'id' => $orderId,
            'technician_id' => $technicianId,
            'status' => VanRestockOrder::STATUS_PENDING_PICKING,
            'notes' => $notes,
            'created_by' => $actorUserId,
            'created_at' => '2026-10-26T08:00:00+01:00',
            'dispatched_by' => null,
            'dispatched_at' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'updated_at' => '2026-10-26T08:00:00+01:00',
        ];
        $this->items[$orderId] = $rows;

        return $this->hydrateOrder($orderId);
    }

    public function updatePendingOrderWithSoftDelete(
        int $orderId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        $this->updateCalls++;

        if (!isset($this->orders[$orderId])) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        if ($this->orders[$orderId]['status'] !== VanRestockOrder::STATUS_PENDING_PICKING) {
            throw VanRestockOrderValidationException::invalidTransition(
                $this->orders[$orderId]['status'],
                'editar'
            );
        }

        $rows = $this->items[$orderId] ?? [];
        $byPart = [];
        foreach ($rows as $index => $row) {
            $byPart[(int)$row['spare_part_id']] = $index;
        }

        $desired = [];
        foreach ($items as $item) {
            $partId = $item->sparePartId;
            $desired[$partId] = true;

            if (isset($byPart[$partId])) {
                $index = $byPart[$partId];
                $rows[$index] = array_merge($rows[$index], [
                    'current_quantity_snapshot' => $item->currentQuantitySnapshot,
                    'min_threshold_snapshot' => $item->minThresholdSnapshot,
                    'pending_request_qty_snapshot' => $item->pendingRequestQtySnapshot,
                    'requested_qty' => $item->requestedQty,
                    'reason' => $item->reason,
                    'part_code' => $item->partCode,
                    'part_name' => $item->partName,
                    'category' => $item->category,
                    'removed_at' => null,
                    'removed_by' => null,
                ]);
                continue;
            }

            $rows[] = $this->itemRowFromEntity($orderId, $item);
        }

        foreach ($rows as $index => $row) {
            if ((int)$row['spare_part_id'] === 0) {
                continue;
            }

            if (!isset($desired[(int)$row['spare_part_id']]) && $row['removed_at'] === null) {
                $rows[$index]['removed_at'] = '2026-10-26T08:20:00+01:00';
                $rows[$index]['removed_by'] = $actorUserId;
            }
        }

        $this->items[$orderId] = array_values($rows);
        $this->orders[$orderId]['notes'] = $notes;
        $this->orders[$orderId]['updated_at'] = '2026-10-26T08:20:00+01:00';

        return $this->hydrateOrder($orderId);
    }

    public function markDispatched(
        int $orderId,
        array $dispatchedQuantities,
        int $actorUserId
    ): VanRestockOrder {
        $this->dispatchCalls++;

        if (!isset($this->orders[$orderId])) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        if ($this->orders[$orderId]['status'] !== VanRestockOrder::STATUS_PENDING_PICKING) {
            throw VanRestockOrderValidationException::invalidTransition(
                $this->orders[$orderId]['status'],
                'expedir'
            );
        }

        $active = $this->activeRows($orderId);
        $activeByPart = [];
        foreach ($active as $row) {
            $activeByPart[(int)$row['spare_part_id']] = true;
        }

        foreach (array_keys($activeByPart) as $partId) {
            if (!array_key_exists($partId, $dispatchedQuantities)) {
                throw VanRestockOrderValidationException::invalidPayload(
                    "faltan las cantidades despachadas de la pieza {$partId}."
                );
            }
        }

        foreach ($this->items[$orderId] as $index => $row) {
            if ($row['removed_at'] !== null) {
                continue;
            }

            $partId = (int)$row['spare_part_id'];
            if (!isset($dispatchedQuantities[$partId])) {
                continue;
            }

            $this->items[$orderId][$index]['dispatched_qty'] = (int)$dispatchedQuantities[$partId];
        }

        $this->orders[$orderId]['status'] = VanRestockOrder::STATUS_DISPATCHED;
        $this->orders[$orderId]['dispatched_by'] = $actorUserId;
        $this->orders[$orderId]['dispatched_at'] = '2026-10-26T08:30:00+01:00';
        $this->orders[$orderId]['updated_at'] = '2026-10-26T08:30:00+01:00';

        return $this->hydrateOrder($orderId);
    }

    public function markCancelled(
        int $orderId,
        string $cancellationReason,
        int $actorUserId
    ): VanRestockOrder {
        $this->cancelCalls++;

        if (!isset($this->orders[$orderId])) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        if (!in_array($this->orders[$orderId]['status'], VanRestockOrder::OPEN_STATUSES, true)) {
            throw VanRestockOrderValidationException::invalidTransition(
                $this->orders[$orderId]['status'],
                'cancelar'
            );
        }

        $this->orders[$orderId]['status'] = VanRestockOrder::STATUS_CANCELLED;
        $this->orders[$orderId]['cancelled_by'] = $actorUserId;
        $this->orders[$orderId]['cancelled_at'] = '2026-10-26T08:40:00+01:00';
        $this->orders[$orderId]['cancellation_reason'] = $cancellationReason;
        $this->orders[$orderId]['updated_at'] = '2026-10-26T08:40:00+01:00';

        return $this->hydrateOrder($orderId);
    }

    public function confirmOrderAndIncrementVanStock(
        int $orderId,
        int $authenticatedTechnicianId
    ): array {
        if (!isset($this->orders[$orderId])) {
            throw VanRestockOrderValidationException::orderNotFound($orderId);
        }

        if ((int)$this->orders[$orderId]['technician_id'] !== $authenticatedTechnicianId) {
            throw VanRestockOrderValidationException::ownershipMismatch();
        }

        if ($this->orders[$orderId]['status'] !== VanRestockOrder::STATUS_DISPATCHED) {
            throw VanRestockOrderValidationException::invalidTransition(
                $this->orders[$orderId]['status'],
                'confirmar'
            );
        }

        $updated = [];

        foreach ($this->items[$orderId] ?? [] as $index => $row) {
            if ($row['removed_at'] !== null) {
                continue;
            }

            $dispatchedQty = $row['dispatched_qty'] === null ? 0 : (int)$row['dispatched_qty'];
            if ($dispatchedQty <= 0) {
                continue;
            }

            $partId = (int)$row['spare_part_id'];
            $stock = $this->photo['van_stock'][$authenticatedTechnicianId][$partId]
                ?? ['quantity_available' => 0, 'min_threshold' => 0];

            $previous = (int)$stock['quantity_available'];
            $threshold = (int)$stock['min_threshold'];
            $newQty = $previous + $dispatchedQty;

            if ($newQty > VanRestockOrder::QUANTITY_MAX) {
                throw VanRestockOrderValidationException::invalidPayload(
                    "la pieza {$row['part_code']} superaría el tope de 9999 unidades."
                );
            }

            $this->photo['van_stock'][$authenticatedTechnicianId][$partId] = [
                'quantity_available' => $newQty,
                'min_threshold' => $threshold,
            ];

            $updated[] = [
                'spare_part_id' => $partId,
                'part_code' => (string)$row['part_code'],
                'part_name' => (string)$row['part_name'],
                'previous_quantity_available' => $previous,
                'dispatched_qty' => $dispatchedQty,
                'new_quantity_available' => $newQty,
                'min_threshold' => $threshold,
                'is_below_threshold' => $newQty <= $threshold,
            ];
        }

        $this->confirmCalls++;
        $this->orders[$orderId]['status'] = VanRestockOrder::STATUS_CONFIRMED_BY_TECH;
        $this->orders[$orderId]['confirmed_by'] = $authenticatedTechnicianId;
        $this->orders[$orderId]['confirmed_at'] = '2026-10-26T08:50:00+01:00';
        $this->orders[$orderId]['updated_at'] = '2026-10-26T08:50:00+01:00';

        return [
            'order' => $this->hydrateOrder($orderId),
            'updated_van_stock' => $updated,
        ];
    }

    public function findById(int $orderId): ?VanRestockOrder
    {
        return isset($this->orders[$orderId]) ? $this->hydrateOrder($orderId) : null;
    }

    public function findOpenOrderForTechnician(int $technicianId): ?VanRestockOrder
    {
        foreach ($this->orders as $orderId => $row) {
            if ((int)$row['technician_id'] === $technicianId
                && in_array($row['status'], VanRestockOrder::OPEN_STATUSES, true)
            ) {
                return $this->hydrateOrder($orderId);
            }
        }

        return null;
    }

    public function findAllForCoordinator(?int $technicianId = null, ?string $status = null): array
    {
        $result = [];
        foreach ($this->orders as $orderId => $row) {
            if ($technicianId !== null && (int)$row['technician_id'] !== $technicianId) {
                continue;
            }

            if ($status === 'OPEN' && !in_array($row['status'], VanRestockOrder::OPEN_STATUSES, true)) {
                continue;
            }

            if ($status !== null && $status !== 'OPEN' && $row['status'] !== $status) {
                continue;
            }

            $result[] = $this->hydrateOrder($orderId);
        }

        return $result;
    }

    public function findActiveDispatchedForTechnician(int $technicianId): ?VanRestockOrder
    {
        foreach ($this->orders as $orderId => $row) {
            if ((int)$row['technician_id'] === $technicianId
                && $row['status'] === VanRestockOrder::STATUS_DISPATCHED
            ) {
                return $this->hydrateOrder($orderId);
            }
        }

        return null;
    }

    public function findLastConfirmedForTechnician(int $technicianId): ?VanRestockOrder
    {
        $found = null;
        foreach ($this->orders as $orderId => $row) {
            if ((int)$row['technician_id'] === $technicianId
                && $row['status'] === VanRestockOrder::STATUS_CONFIRMED_BY_TECH
            ) {
                $found = $this->hydrateOrder($orderId);
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsForOrder(int $orderId): array
    {
        return $this->items[$orderId] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function itemRow(int $orderId, int $sparePartId): ?array
    {
        foreach ($this->items[$orderId] ?? [] as $row) {
            if ((int)$row['spare_part_id'] === $sparePartId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeRows(int $orderId): array
    {
        return array_values(array_filter(
            $this->items[$orderId] ?? [],
            static fn (array $row): bool => $row['removed_at'] === null
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRowFromEntity(int $orderId, VanRestockOrderItem $item): array
    {
        return [
            'id' => ++$this->nextItemId,
            'order_id' => $orderId,
            'spare_part_id' => $item->sparePartId,
            'part_code' => $item->partCode,
            'part_name' => $item->partName,
            'category' => $item->category,
            'current_quantity_snapshot' => $item->currentQuantitySnapshot,
            'min_threshold_snapshot' => $item->minThresholdSnapshot,
            'pending_request_qty_snapshot' => $item->pendingRequestQtySnapshot,
            'requested_qty' => $item->requestedQty,
            'dispatched_qty' => null,
            'reason' => $item->reason,
            'removed_at' => null,
            'removed_by' => null,
        ];
    }

    private function hydrateOrder(int $orderId): VanRestockOrder
    {
        $row = $this->orders[$orderId];
        $technician = $this->technician((int)$row['technician_id']);

        $items = [];
        foreach ($this->items[$orderId] ?? [] as $item) {
            $items[] = new VanRestockOrderItem(
                (int)$item['id'],
                (int)$item['order_id'],
                (int)$item['spare_part_id'],
                (string)$item['part_code'],
                (string)$item['part_name'],
                (string)$item['category'],
                (int)$item['current_quantity_snapshot'],
                (int)$item['min_threshold_snapshot'],
                (int)$item['pending_request_qty_snapshot'],
                (int)$item['requested_qty'],
                $item['dispatched_qty'] === null ? null : (int)$item['dispatched_qty'],
                (string)$item['reason'],
                $item['removed_at'] === null ? null : (string)$item['removed_at'],
                $item['removed_by'] === null ? null : (int)$item['removed_by']
            );
        }

        return new VanRestockOrder(
            (int)$row['id'],
            (int)$row['technician_id'],
            (string)$technician['technician_name'],
            $technician['operator_code'] === null ? null : (string)$technician['operator_code'],
            (bool)$technician['is_active'],
            (bool)$technician['is_currently_absent'],
            (string)$row['status'],
            $row['notes'] === null ? null : (string)$row['notes'],
            (int)$row['created_by'],
            'Ana Coordinadora',
            (string)$row['created_at'],
            $row['dispatched_by'] === null ? null : (int)$row['dispatched_by'],
            $row['dispatched_by'] === null ? null : 'Ana Coordinadora',
            $row['dispatched_at'] === null ? null : (string)$row['dispatched_at'],
            $row['confirmed_by'] === null ? null : (int)$row['confirmed_by'],
            $row['confirmed_by'] === null ? null : (string)$technician['technician_name'],
            $row['confirmed_at'] === null ? null : (string)$row['confirmed_at'],
            $row['cancelled_by'] === null ? null : (int)$row['cancelled_by'],
            $row['cancelled_by'] === null ? null : 'Ana Coordinadora',
            $row['cancelled_at'] === null ? null : (string)$row['cancelled_at'],
            $row['cancellation_reason'] === null ? null : (string)$row['cancellation_reason'],
            (string)$row['updated_at'],
            $items
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function technician(int $technicianId): array
    {
        foreach ($this->photo['technicians'] as $technician) {
            if ((int)$technician['technician_id'] === $technicianId) {
                return $technician;
            }
        }

        return [
            'technician_id' => $technicianId,
            'technician_name' => 'Técnico ' . $technicianId,
            'operator_code' => null,
            'is_active' => true,
            'is_currently_absent' => false,
            'open_order_id' => null,
            'open_order_status' => null,
        ];
    }
}

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$assertThrows = static function (string $what, callable $fn, string $expectedCode) use (&$assertions, &$failures): void {
    $assertions++;

    try {
        $fn();
        echo "  [FAIL] {$what} · no se lanzó ninguna excepción\n";
        $failures++;
    } catch (VanRestockOrderValidationException $exception) {
        $ok = $exception->getErrorCode() === $expectedCode;
        echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ' · ' . $exception->getErrorCode() . "\n";
        if (!$ok) {
            $failures++;
        }
    }
};

$part = static fn (int $id, string $code, string $category = 'THERMAL'): array => [
    'spare_part_id' => $id,
    'part_code' => $code,
    'part_name' => 'Pieza ' . $code,
    'category' => $category,
];

$technician = static fn (
    int $id,
    string $name,
    bool $active = true,
    bool $absent = false,
    ?int $openOrderId = null
): array => [
    'technician_id' => $id,
    'technician_name' => $name,
    'operator_code' => 'TEC-' . $id,
    'is_active' => $active,
    'is_currently_absent' => $absent,
    'open_order_id' => $openOrderId,
    'open_order_status' => $openOrderId === null ? null : VanRestockOrder::STATUS_PENDING_PICKING,
];

$makePhoto = static function () use ($part, $technician): array {
    return [
        'technicians' => [
            $technician(7, 'Carlos Mendoza'),
            $technician(8, 'Marta Técnica', true, true),
            $technician(9, 'Técnico Inactivo', false),
            $technician(10, 'Técnico Sin Déficit'),
            $technician(12, 'Técnico Con Orden', true, false, 42),
        ],
        'spare_parts' => [
            $part(101, 'AAA-TH-01'),
            $part(102, 'BBB-VAL-02', 'HYDRAULIC'),
            $part(103, 'CCC-BOM-03', 'MECHANICAL'),
        ],
        'van_stock' => [
            7 => [
                101 => ['quantity_available' => 2, 'min_threshold' => 2],
                102 => ['quantity_available' => 0, 'min_threshold' => 0],
            ],
            8 => [
                101 => ['quantity_available' => 0, 'min_threshold' => 2],
            ],
            9 => [
                101 => ['quantity_available' => 5, 'min_threshold' => 4],
            ],
            10 => [
                101 => ['quantity_available' => 5, 'min_threshold' => 4],
            ],
            12 => [
                102 => ['quantity_available' => 0, 'min_threshold' => 3],
            ],
        ],
        'pending_requests' => [
            7 => [102 => 1],
        ],
    ];
};

$makeRepository = static fn (): FakeVanRestockOrderRepositoryForServiceTest => new FakeVanRestockOrderRepositoryForServiceTest($makePhoto());

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-09 · Ciclo de vida de órdenes de carga\n";
echo "======================================================================\n";

// =====================================================================
// 1. Creación individual
// =====================================================================
echo "\n--- 1. Creación individual ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$order = $service->createOrder(7, 'Preparar en jaula 3', [
    ['spare_part_id' => 101, 'requested_qty' => 2],
], 1);

$assert('1.1 Crea una orden PENDING_PICKING', $order->isPendingPicking() && $order->id > 0, $order->orderCode());
$assert('1.2 El técnico destinatario es el solicitado', $order->technicianId === 7 && $order->technicianName === 'Carlos Mendoza');
$assert('1.3 Una línea con cantidad y motivo por defecto', $order->activeItemsCount() === 1
    && $order->activeItems()[0]->requestedQty === 2
    && $order->activeItems()[0]->reason === VanRestockOrder::REASON_MANUAL);
$assert('1.4 Snapshots del servidor', $order->activeItems()[0]->currentQuantitySnapshot === 2
    && $order->activeItems()[0]->minThresholdSnapshot === 2
    && $order->activeItems()[0]->pendingRequestQtySnapshot === 0);
$assert('1.5 Notas y total solicitado', $order->notes === 'Preparar en jaula 3' && $order->totalRequestedUnits() === 2);
$assert('1.6 Se delega una sola creación', $repository->createCalls === 1);

$assertThrows('1.7 Técnico inactivo rechazado (422)', static function () use ($service): void {
    $service->createOrder(9, null, [['spare_part_id' => 101, 'requested_qty' => 1]], 1);
}, VanRestockOrderValidationException::TECHNICIAN_NOT_ELIGIBLE);

$assertThrows('1.8 Técnico inexistente rechazado (422)', static function () use ($service): void {
    $service->createOrder(999, null, [['spare_part_id' => 101, 'requested_qty' => 1]], 1);
}, VanRestockOrderValidationException::TECHNICIAN_NOT_ELIGIBLE);

$assertThrows('1.9 Orden abierta rechazada (409)', static function () use ($service): void {
    $service->createOrder(12, null, [['spare_part_id' => 102, 'requested_qty' => 1]], 1);
}, VanRestockOrderValidationException::OPEN_RESTOCK_ORDER_ALREADY_EXISTS);

$assertThrows('1.10 Pieza inactiva o inexistente rechazada', static function () use ($service): void {
    $service->createOrder(10, null, [['spare_part_id' => 999, 'requested_qty' => 1]], 1);
}, VanRestockOrderValidationException::SPARE_PART_NOT_ELIGIBLE);

$assertThrows('1.11 Línea duplicada rechazada', static function () use ($service): void {
    $service->createOrder(10, null, [
        ['spare_part_id' => 101, 'requested_qty' => 1],
        ['spare_part_id' => 101, 'requested_qty' => 2],
    ], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$assertThrows('1.12 Cantidad cero rechazada', static function () use ($service): void {
    $service->createOrder(10, null, [['spare_part_id' => 101, 'requested_qty' => 0]], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$assertThrows('1.13 Cantidad por encima del tope rechazada', static function () use ($service): void {
    $service->createOrder(10, null, [['spare_part_id' => 101, 'requested_qty' => 10000]], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$assertThrows('1.14 Saldo proyectado por encima de 9999 rechazado', static function () use ($service): void {
    $service->createOrder(10, null, [['spare_part_id' => 101, 'requested_qty' => 9995]], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$assertThrows('1.15 Orden sin líneas rechazada', static function () use ($service): void {
    $service->createOrder(10, null, [], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$absentOrder = $service->createOrder(8, null, [
    [
        'spare_part_id' => 101,
        'requested_qty' => 1,
        'current_quantity' => 9999,
        'min_threshold' => 9999,
        'pending_request_qty' => 9999,
    ],
], 1);
$absentItem = $absentOrder->activeItems()[0];
$assert('1.16 Técnico ausente permitido con alerta (QA 4)', $absentOrder->recipientCurrentlyAbsent === true
    && $absentOrder->hasRecipientAlert()
    && $absentOrder->isPendingPicking());
$assert('1.17 El cliente no puede imponer snapshots', $absentItem->currentQuantitySnapshot === 0
    && $absentItem->minThresholdSnapshot === 2
    && $absentItem->pendingRequestQtySnapshot === 0);

// =====================================================================
// 2. Generación por lotes
// =====================================================================
echo "\n--- 2. Generación por lotes ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$batch = $service->generateBatch('Lote matinal', 1);

$assert('2.1 Una orden creada y tres técnicos omitidos', $batch['created_count'] === 1
    && $batch['skipped_count'] === 3, json_encode($batch));
$assert('2.2 La orden creada es la del técnico con déficit', count($batch['created_orders']) === 1
    && $batch['created_orders'][0]['technician_id'] === 7);
$assert('2.3 La orden congela la propuesta completa', $batch['created_orders'][0]['active_items_count'] === 2
    && $batch['created_orders'][0]['total_requested_units'] === 3);
$assert('2.4 El lote crea con los snapshots de la propuesta', $repository->createCalls === 1);

$skipReasons = array_map(static fn (array $row): string => $row['skip_reason'], $batch['skipped_technicians']);
$assert(
    '2.5 Motivos de omisión: ausencia, sin déficit y orden abierta',
    $skipReasons === [
        VanRestockOrderService::SKIP_ACTIVE_ABSENCE,
        VanRestockOrderService::SKIP_NO_DEFICIT,
        VanRestockOrderService::SKIP_OPEN_ORDER_EXISTS,
    ],
    implode(',', $skipReasons)
);
$assert('2.6 La omisión por ausencia no inventa orden abierta', $batch['skipped_technicians'][0]['open_order_id'] === null);
$assert('2.7 La omisión por orden abierta la identifica', $batch['skipped_technicians'][2]['open_order_id'] === 42);
$assert('2.8 Mensaje en castellano con singular y plural', $batch['message'] === 'Generación por lotes completada: 1 orden creada, 3 técnicos omitidos.', $batch['message']);

$createdKeys = array_keys($batch['created_orders'][0]);
$assert('2.9 Claves del resumen de creadas', $createdKeys === [
    'id', 'order_code', 'technician_id', 'technician_name', 'active_items_count', 'total_requested_units',
], implode(',', $createdKeys));

$skippedKeys = array_keys($batch['skipped_technicians'][0]);
$assert('2.10 Claves del resumen de omitidas', $skippedKeys === [
    'technician_id', 'technician_name', 'skip_reason', 'open_order_id',
], implode(',', $skippedKeys));

$twoDeficitPhoto = $makePhoto();
$twoDeficitPhoto['van_stock'][10] = [101 => ['quantity_available' => 0, 'min_threshold' => 2]];
$twoRepository = new FakeVanRestockOrderRepositoryForServiceTest($twoDeficitPhoto);
$twoService = new VanRestockOrderService($twoRepository);
$twoBatch = $twoService->generateBatch(null, 1);
$assert('2.11 Dos técnicos con déficit crean dos órdenes', $twoBatch['created_count'] === 2
    && $twoBatch['skipped_count'] === 2, json_encode($twoBatch['skipped_technicians']));
$assert('2.12 Mensaje en plural', $twoBatch['message'] === 'Generación por lotes completada: 2 órdenes creadas, 2 técnicos omitidos.', $twoBatch['message']);

// =====================================================================
// 3. Edición con baja lógica y reactivación
// =====================================================================
echo "\n--- 3. Edición con baja lógica y reactivación ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$order = $service->createOrder(7, 'Inicial', [
    ['spare_part_id' => 101, 'requested_qty' => 1],
    ['spare_part_id' => 102, 'requested_qty' => 2, 'reason' => 'BOTH'],
], 1);
$original102 = $repository->itemRow($order->id, 102);
$assert('3.1 La orden nace con dos líneas activas', $order->activeItemsCount() === 2
    && $original102 !== null
    && $original102['removed_at'] === null);

$edited = $service->updateOrder($order->id, 'Sin la electroválvula', [
    ['spare_part_id' => 101, 'requested_qty' => 2],
], 1);
$removed102 = $repository->itemRow($order->id, 102);
$assert('3.2 La línea retirada desaparece de la vista activa', $edited->activeItemsCount() === 1
    && $edited->totalRequestedUnits() === 2);
$assert('3.3 La fila retirada se conserva con baja lógica', $removed102 !== null
    && $removed102['removed_at'] !== null
    && (int)$removed102['removed_by'] === 1);
$assert('3.4 No hay borrado: la fila sigue teniendo su identificador', $removed102 !== null
    && (int)$removed102['id'] === (int)$original102['id']);

$reactivated = $service->updateOrder($order->id, 'Con la electroválvula otra vez', [
    ['spare_part_id' => 101, 'requested_qty' => 3],
    ['spare_part_id' => 102, 'requested_qty' => 2, 'reason' => 'BOTH'],
], 1);
$reactivated102 = $repository->itemRow($order->id, 102);
$assert('3.5 La pieza vuelve a estar activa', $reactivated->activeItemsCount() === 2
    && $reactivated->totalRequestedUnits() === 5);
$assert('3.6 Se reactiva la misma fila', $reactivated102 !== null
    && (int)$reactivated102['id'] === (int)$original102['id']
    && $reactivated102['removed_at'] === null
    && $reactivated102['removed_by'] === null);
$assert('3.7 No se duplica la fila de la pieza', count(array_filter(
    $repository->rowsForOrder($order->id),
    static fn (array $row): bool => (int)$row['spare_part_id'] === 102
)) === 1);
$assert('3.8 La edición conserva las notas nuevas', $reactivated->notes === 'Con la electroválvula otra vez');

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);
$order = $service->createOrder(7, null, [
    ['spare_part_id' => 101, 'requested_qty' => 1],
    ['spare_part_id' => 102, 'requested_qty' => 1, 'reason' => 'BOTH'],
], 1);
$service->dispatchOrder($order->id, [101 => 1, 102 => 0], 1);
$assertThrows('3.9 Edición fuera de PENDING rechazada (409)', static function () use ($service, $order): void {
    $service->updateOrder($order->id, null, [['spare_part_id' => 101, 'requested_qty' => 2]], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_TRANSITION);

$assertThrows('3.10 Edición de una orden inexistente (404)', static function () use ($service): void {
    $service->updateOrder(99999, null, [['spare_part_id' => 101, 'requested_qty' => 1]], 1);
}, VanRestockOrderValidationException::RESTOCK_ORDER_NOT_FOUND);

// =====================================================================
// 4. Expedición parcial y cancelación
// =====================================================================
echo "\n--- 4. Expedición parcial y cancelación ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$order = $service->createOrder(7, null, [
    ['spare_part_id' => 101, 'requested_qty' => 3],
    ['spare_part_id' => 102, 'requested_qty' => 2, 'reason' => 'BOTH'],
], 1);

$assertThrows('4.1 Expedición con suma cero rechazada', static function () use ($service, $order): void {
    $service->dispatchOrder($order->id, [101 => 0, 102 => 0], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$dispatched = $service->dispatchOrder($order->id, ['101' => '2', '102' => 0], 1);
$assert('4.2 La orden pasa a DISPATCHED', $dispatched->isDispatched(), $dispatched->status);
$assert('4.3 Dos unidades servidas de tres pedidas', $dispatched->totalDispatchedUnits() === 2);
$assert('4.4 La línea sin servir se conserva con cero (C3)', $repository->itemRow($order->id, 102)['dispatched_qty'] === 0
    && $repository->itemRow($order->id, 102)['removed_at'] === null);

$assertThrows('4.5 Expedir una orden ya expedida (409)', static function () use ($service, $order): void {
    $service->dispatchOrder($order->id, [101 => 1, 102 => 0], 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_TRANSITION);

$stockBeforeCancel = $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'];
$assertThrows('4.6 Cancelación con 19 caracteres rechazada', static function () use ($service, $order): void {
    $service->cancelOrder($order->id, 'Demasiado corto', 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD);

$cancelled = $service->cancelOrder($order->id, '  Error de ruta; se prepara por la tarde.  ', 1);
$assert('4.7 Cancelación con el mínimo real (Art. V.1)', $cancelled->isCancelled()
    && $cancelled->cancellationReason === 'Error de ruta; se prepara por la tarde.');
$assert('4.8 Cancelar no toca el stock', $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'] === $stockBeforeCancel);

$assertThrows('4.9 Cancelar dos veces (409)', static function () use ($service, $order): void {
    $service->cancelOrder($order->id, 'Segundo intento de cancelación justificada.', 1);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_TRANSITION);

$assertThrows('4.10 Cancelar una orden inexistente (404)', static function () use ($service): void {
    $service->cancelOrder(99999, 'Motivo suficientemente largo para superar el mínimo.', 1);
}, VanRestockOrderValidationException::RESTOCK_ORDER_NOT_FOUND);

// =====================================================================
// 5. Confirmación móvil y turnos
// =====================================================================
echo "\n--- 5. Confirmación móvil y turnos ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$order = $service->createOrder(7, null, [
    ['spare_part_id' => 101, 'requested_qty' => 3],
    ['spare_part_id' => 102, 'requested_qty' => 2, 'reason' => 'BOTH'],
], 1);
$service->dispatchOrder($order->id, [101 => 2, 102 => 0], 1);

$stockBefore = $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'];
$assertThrows('5.1 Otro técnico no puede confirmar (403)', static function () use ($service, $order): void {
    $service->confirmOrder($order->id, 8);
}, VanRestockOrderValidationException::RESTOCK_ORDER_OWNERSHIP_MISMATCH);
$assert('5.2 El rechazo no toca el stock ni el estado', $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'] === $stockBefore
    && $repository->findById($order->id)->isDispatched());

$confirmation = $service->confirmOrder($order->id, 7);
$confirmed = $confirmation['order'];
$updated = $confirmation['updated_van_stock'];
$stockAfter = $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'];
$assert('5.3 Confirmación en un toque', $confirmed->isConfirmedByTech() && $repository->confirmCalls === 1);
$assert('5.4 Solo se incrementa la línea con unidades', count($updated) === 1
    && $updated[0]['spare_part_id'] === 101
    && $updated[0]['dispatched_qty'] === 2);
$assert('5.5 Saldo anterior y posterior coherentes', $stockAfter === $stockBefore + 2
    && $updated[0]['previous_quantity_available'] === $stockBefore
    && $updated[0]['new_quantity_available'] === $stockAfter);
$assert('5.6 La línea sin servir no crea saldo', count(array_filter(
    $repository->rowsForOrder($order->id),
    static fn (array $row): bool => (int)$row['spare_part_id'] === 102 && $row['dispatched_qty'] === 0
)) === 1);
$assert('5.7 El comprobante de última carga está disponible', $repository->findLastConfirmedForTechnician(7)?->id === $order->id);
$assert('5.8 No hay ninguna consulta de turno (QA 4)', $repository->shiftLookups === 0);

$assertThrows('5.9 Doble toque rechazado (409)', static function () use ($service, $order): void {
    $service->confirmOrder($order->id, 7);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_TRANSITION);
$assert('5.10 El doble toque no vuelve a sumar', $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'] === $stockAfter);

$offShiftOrder = $service->createOrder(7, null, [
    ['spare_part_id' => 101, 'requested_qty' => 1],
], 1);
$service->dispatchOrder($offShiftOrder->id, [101 => 1], 1);
$offShiftConfirmation = $service->confirmOrder($offShiftOrder->id, 7);
$assert('5.11 La recepción funciona fuera de turno (QA 4)', $offShiftConfirmation['order']->isConfirmedByTech()
    && $repository->fetchProposalInputs(7)['van_stock'][7][101]['quantity_available'] === $stockAfter + 1);

$cancelThenConfirm = $service->createOrder(7, null, [
    ['spare_part_id' => 101, 'requested_qty' => 1],
], 1);
$service->dispatchOrder($cancelThenConfirm->id, [101 => 1], 1);
$service->cancelOrder($cancelThenConfirm->id, 'La ruta cambia y esta carga ya no procede.', 1);
$assertThrows('5.12 Confirmar una orden cancelada (409)', static function () use ($service, $cancelThenConfirm): void {
    $service->confirmOrder($cancelThenConfirm->id, 7);
}, VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_TRANSITION);

$assertThrows('5.13 Confirmar una orden inexistente (404)', static function () use ($service): void {
    $service->confirmOrder(99999, 7);
}, VanRestockOrderValidationException::RESTOCK_ORDER_NOT_FOUND);

$shiftConfirmations = [];
foreach (['ON_SHIFT', 'OFF_SHIFT'] as $shiftState) {
    $repository->technicianShiftState = $shiftState;
    $shiftOrder = $service->createOrder(7, null, [['spare_part_id' => 101, 'requested_qty' => 1]], 1);
    $service->dispatchOrder($shiftOrder->id, [101 => 1], 1);
    $shiftConfirmations[$shiftState] = $service->confirmOrder($shiftOrder->id, 7)['order']->isConfirmedByTech();
}
$assert('5.14 Confirmación idéntica en ON_SHIFT y OFF_SHIFT (QA 4)', $shiftConfirmations === [
    'ON_SHIFT' => true,
    'OFF_SHIFT' => true,
], json_encode($shiftConfirmations));

$constructor = (new ReflectionClass(VanRestockOrderService::class))->getConstructor();
$assert('5.15 El servicio solo depende del puerto de órdenes, sin turnos', $constructor !== null
    && $constructor->getNumberOfParameters() === 1
    && (string)$constructor->getParameters()[0]->getType() === VanRestockOrderRepositoryInterface::class);
$assert('5.16 Sin SQL en el servicio: la persistencia vive en el puerto', !preg_match(
    '/\b(UPDATE|INSERT INTO|DELETE FROM)\b/i',
    (string)file_get_contents(__DIR__ . '/../../src/Application/Service/VanRestockOrderService.php')
));

// =====================================================================
// 6. Contrato y privacidad
// =====================================================================
echo "\n--- 6. Contrato y privacidad ---\n";

$repository = $makeRepository();
$service = new VanRestockOrderService($repository);

$order = $service->createOrder(7, null, [['spare_part_id' => 101, 'requested_qty' => 1]], 1);
$coordinator = $order->toCoordinatorArray();
$technicianView = $order->toTechnicianActiveArray();
$summary = $order->toTechnicianConfirmedSummaryArray();

$assert('6.1 La proyección de Coordinación no expone coste', !array_key_exists('reference_cost', $coordinator)
    && !str_contains(json_encode($coordinator), 'reference_cost'));
$assert('6.2 La proyección móvil no expone coste', !array_key_exists('reference_cost', $technicianView)
    && !str_contains(json_encode($technicianView), 'reference_cost'));
$assert('6.3 El comprobante de recepción no expone coste', !str_contains(json_encode($summary), 'reference_cost'));
$assert('6.4 La proyección móvil no revela la identidad del creador', !array_key_exists('created_by', $technicianView)
    && !array_key_exists('created_by_name', $technicianView));
$assert('6.5 La orden conserva su código legible', $order->orderCode() === 'RO-' . str_pad((string)$order->id, 6, '0', STR_PAD_LEFT));
$assert('6.6 La línea de Coordinación conserva los snapshots', $coordinator['items'][0]['current_quantity_snapshot'] === 2
    && $coordinator['items'][0]['min_threshold_snapshot'] === 2
    && $coordinator['items'][0]['pending_request_qty_snapshot'] === 0);
$assert('6.7 La proyección de Coordinación publica el código de operador para el A4',
    ($coordinator['technician_operator_code'] ?? null) === 'TEC-7');

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CICLO DE VIDA CERTIFICADO (T-RESTOCK-09).\n"
    : " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
