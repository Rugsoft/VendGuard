<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * VanRestockOrder
 *
 * Entidad de dominio inmutable que representa la cabecera de una orden de carga
 * de furgoneta (Módulo 23, RF-01 a RF-05).
 *
 * Ciclo de vida (D1):
 *   - [Nuevo] -> PENDING_PICKING (creación individual o en lote)
 *   - PENDING_PICKING -> PENDING_PICKING (edición con baja lógica de líneas)
 *   - PENDING_PICKING -> DISPATCHED (despacho de almacén con cantidades reales)
 *   - PENDING_PICKING -> CANCELLED (justificación de >= 20 caracteres reales)
 *   - DISPATCHED -> CONFIRMED_BY_TECH (recepción móvil en 1 toque)
 *   - DISPATCHED -> CANCELLED (justificación de >= 20 caracteres reales)
 *
 * `CONFIRMED_BY_TECH` y `CANCELLED` son estados terminales: la entidad no
 * expone transición alguna desde ellos.
 *
 * Invariantes que esta entidad hace explícitos:
 *   - La orden nunca se borra: las líneas retiradas se conservan con baja
 *     lógica (`removedAt`) y las proyecciones operativas las excluyen (Art. III.1).
 *   - Ninguna proyección incluye coste, precio ni importe alguno (Art. V.4).
 *   - El orden determinista de líneas vive aquí (`reasonPriority()`), no en el
 *     frontend ni en cada consumidor, para que la propuesta, la orden y el
 *     Albarán de Picking A4 no puedan divergir (QA 5).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class VanRestockOrder
{
    public const STATUS_PENDING_PICKING = 'PENDING_PICKING';
    public const STATUS_DISPATCHED = 'DISPATCHED';
    public const STATUS_CONFIRMED_BY_TECH = 'CONFIRMED_BY_TECH';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const REASON_LOW_STOCK = 'LOW_STOCK';
    public const REASON_PENDING_REQUEST = 'PENDING_REQUEST';
    public const REASON_BOTH = 'BOTH';
    public const REASON_MANUAL = 'MANUAL';

    /** Cota superior de unidades por referencia, solicitadas o despachadas (QA 1). */
    public const QUANTITY_MAX = 9999;

    /** Longitud mínima real de la justificación de cancelación (Art. V.1). */
    public const MIN_CANCELLATION_REASON_LENGTH = 20;

    /** @var list<string> Estados admitidos por el motor y por la entidad. */
    public const STATUSES = [
        self::STATUS_PENDING_PICKING,
        self::STATUS_DISPATCHED,
        self::STATUS_CONFIRMED_BY_TECH,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> Estados que ocupan el hueco de «orden abierta» (D3). */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING_PICKING,
        self::STATUS_DISPATCHED,
    ];

    /**
     * @param list<VanRestockOrderItem> $items Líneas de la orden, incluidas las
     *        que conservan baja lógica; las proyecciones filtran por vigencia.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $technicianId,
        public readonly string $technicianName,
        public readonly ?string $technicianOperatorCode,
        public readonly bool $technicianIsActive,
        public readonly bool $recipientCurrentlyAbsent,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly int $createdBy,
        public readonly string $createdByName,
        public readonly string $createdAt,
        public readonly ?int $dispatchedBy,
        public readonly ?string $dispatchedByName,
        public readonly ?string $dispatchedAt,
        public readonly ?int $confirmedBy,
        public readonly ?string $confirmedByName,
        public readonly ?string $confirmedAt,
        public readonly ?int $cancelledBy,
        public readonly ?string $cancelledByName,
        public readonly ?string $cancelledAt,
        public readonly ?string $cancellationReason,
        public readonly ?string $receptionNote,
        public readonly string $updatedAt,
        public readonly array $items = []
    ) {
    }

    /**
     * Código legible de la orden: `RO-XXXXXX` con seis dígitos.
     */
    public function orderCode(): string
    {
        return 'RO-' . str_pad((string)$this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isPendingPicking(): bool
    {
        return $this->status === self::STATUS_PENDING_PICKING;
    }

    public function isDispatched(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function isConfirmedByTech(): bool
    {
        return $this->status === self::STATUS_CONFIRMED_BY_TECH;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Orden abierta: ocupa el índice único parcial `uq_van_restock_open_per_tech`.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Alerta de lectura del destinatario (C2, QA 4): cuenta inactiva o ausencia
     * vigente. No cancela ni reasigna la orden por sí sola.
     */
    public function hasRecipientAlert(): bool
    {
        return !$this->technicianIsActive || $this->recipientCurrentlyAbsent;
    }

    /**
     * Líneas vigentes: las que no conservan baja lógica (QA 1, Art. III.1).
     *
     * @return list<VanRestockOrderItem>
     */
    public function activeItems(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (VanRestockOrderItem $item): bool => !$item->isRemoved()
        ));
    }

    public function activeItemsCount(): int
    {
        return count($this->activeItems());
    }

    public function totalRequestedUnits(): int
    {
        $total = 0;
        foreach ($this->activeItems() as $item) {
            $total += $item->requestedQty;
        }

        return $total;
    }

    /**
     * Unidades efectivamente despachadas, o `null` si aún no hay despacho
     * (ninguna línea lleva cantidad servida).
     */
    public function totalDispatchedUnits(): ?int
    {
        $total = null;
        foreach ($this->activeItems() as $item) {
            if ($item->dispatchedQty === null) {
                continue;
            }
            $total = ($total ?? 0) + $item->dispatchedQty;
        }

        return $total;
    }

    /**
     * Prioridad determinista de motivos (QA 5):
     * `BOTH` -> `PENDING_REQUEST` -> `LOW_STOCK` -> `MANUAL`.
     *
     * Un motivo desconocido se ordena al final en lugar de romper la vista: la
     * base restringe el vocabulario con `chk_van_restock_items_reason`, de modo
     * que sólo un dato corrupto podría llegar aquí.
     */
    public static function reasonPriority(string $reason): int
    {
        return match ($reason) {
            self::REASON_BOTH => 1,
            self::REASON_PENDING_REQUEST => 2,
            self::REASON_LOW_STOCK => 3,
            self::REASON_MANUAL => 4,
            default => PHP_INT_MAX,
        };
    }

    /**
     * Proyección de Coordinación (contrato §5.3): cabecera, marcas del
     * destinatario y líneas vigentes. Sin campos de coste (Art. V.4).
     *
     * @return array<string, int|string|bool|array<int, array<string, int|string|null>>|null>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode(),
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'technician_operator_code' => $this->technicianOperatorCode,
            'technician_is_active' => $this->technicianIsActive,
            'recipient_currently_absent' => $this->recipientCurrentlyAbsent,
            'status' => $this->status,
            'notes' => $this->notes,
            'active_items_count' => $this->activeItemsCount(),
            'total_requested_units' => $this->totalRequestedUnits(),
            'total_dispatched_units' => $this->totalDispatchedUnits(),
            'created_by' => $this->createdBy,
            'created_by_name' => $this->createdByName,
            'created_at' => $this->createdAt,
            'dispatched_by' => $this->dispatchedBy,
            'dispatched_by_name' => $this->dispatchedByName,
            'dispatched_at' => $this->dispatchedAt,
            'confirmed_by' => $this->confirmedBy,
            'confirmed_by_name' => $this->confirmedByName,
            'confirmed_at' => $this->confirmedAt,
            'cancelled_by' => $this->cancelledBy,
            'cancelled_by_name' => $this->cancelledByName,
            'cancelled_at' => $this->cancelledAt,
            'cancellation_reason' => $this->cancellationReason,
            'reception_note' => $this->receptionNote,
            'updated_at' => $this->updatedAt,
            'items' => array_map(
                static fn (VanRestockOrderItem $item): array => $item->toCoordinatorArray(),
                $this->activeItems()
            ),
        ];
    }

    /**
     * Proyección de la orden despachada pendiente de recepción (§5.10): la que
     * ve el técnico titular en su móvil. Sin identidad de otros técnicos y sin
     * coste alguno (Art. V.4).
     *
     * @return array<string, int|string|bool|array<int, array<string, int|string|null>>|null>
     */
    public function toTechnicianActiveArray(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode(),
            'status' => $this->status,
            'notes' => $this->notes,
            'dispatched_at' => $this->dispatchedAt,
            'dispatched_by_name' => $this->dispatchedByName,
            'total_dispatched_units' => $this->totalDispatchedUnits(),
            'items' => array_map(
                static fn (VanRestockOrderItem $item): array => $item->toTechnicianActiveArray(),
                $this->activeItems()
            ),
        ];
    }

    /**
     * Comprobante de solo lectura de la última carga confirmada (§5.10, QA 5):
     * código, instante de recepción y unidades recibidas por referencia.
     *
     * @return array<string, int|string|array<int, array<string, int|string|null>>|null>
     */
    public function toTechnicianConfirmedSummaryArray(): array
    {
        $receivedItems = array_values(array_filter(
            $this->activeItems(),
            static fn (VanRestockOrderItem $item): bool => $item->dispatchedQuantity() > 0
        ));

        return [
            'id' => $this->id,
            'order_code' => $this->orderCode(),
            'confirmed_at' => $this->confirmedAt,
            'reception_note' => $this->receptionNote,
            'total_received_units' => $this->totalDispatchedUnits() ?? 0,
            'items' => array_map(
                static fn (VanRestockOrderItem $item): array => $item->toTechnicianConfirmedSummaryArray(),
                $receivedItems
            ),
        ];
    }
}
