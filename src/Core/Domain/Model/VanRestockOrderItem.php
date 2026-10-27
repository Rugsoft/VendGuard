<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * VanRestockOrderItem
 *
 * Entidad de dominio inmutable que representa una línea de una orden de carga
 * de furgoneta (Módulo 23, RF-01 a RF-04).
 *
 * Cada línea congela los tres datos que explican la propuesta en el momento de
 * emitirse —saldo declarado, umbral mínimo y unidades pedidas en averías
 * activas— para que el Albarán de Picking A4 y la auditoría puedan reconstruir
 * por qué se pidió esa pieza aunque el inventario o las averías cambien después.
 *
 * Invariantes:
 *   - `requestedQty` vive en `[1, 9999]` y `dispatchedQty` en `[0, 9999]`; las
 *     restricciones `CHECK` del motor son la última puerta (QA 1).
 *   - La baja lógica mueve `removedAt` y `removedBy` juntos: una línea retirada
 *     nunca se borra y puede reactivarse reutilizando la misma fila (Art. III.1).
 *   - La orden de serialización no incluye coste ni precio alguno (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class VanRestockOrderItem
{
    /** Identificador de las líneas que aún no han sido persistidas. */
    public const UNPERSISTED_ID = 0;

    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $sparePartId,
        public readonly string $partCode,
        public readonly string $partName,
        public readonly string $category,
        public readonly int $currentQuantitySnapshot,
        public readonly int $minThresholdSnapshot,
        public readonly int $pendingRequestQtySnapshot,
        public readonly int $requestedQty,
        public readonly ?int $dispatchedQty,
        public readonly string $reason,
        public readonly ?string $removedAt = null,
        public readonly ?int $removedBy = null
    ) {
    }

    /**
     * Línea propuesta por el motor de pre-cálculo, aún sin persistir.
     *
     * El repositorio le asigna el identificador y la orden al insertarla; hasta
     * entonces la entidad vive con `UNPERSISTED_ID`.
     */
    public static function requested(
        int $sparePartId,
        string $partCode,
        string $partName,
        string $category,
        int $currentQuantitySnapshot,
        int $minThresholdSnapshot,
        int $pendingRequestQtySnapshot,
        int $requestedQty,
        string $reason
    ): self {
        return new self(
            id: self::UNPERSISTED_ID,
            orderId: self::UNPERSISTED_ID,
            sparePartId: $sparePartId,
            partCode: $partCode,
            partName: $partName,
            category: $category,
            currentQuantitySnapshot: $currentQuantitySnapshot,
            minThresholdSnapshot: $minThresholdSnapshot,
            pendingRequestQtySnapshot: $pendingRequestQtySnapshot,
            requestedQty: $requestedQty,
            dispatchedQty: null,
            reason: $reason
        );
    }

    /**
     * Baja lógica vigente (QA 1): la fila sigue en la base y se excluye de las
     * vistas operativas, del despacho, de la suma de stock y del Picking A4.
     */
    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    /**
     * Unidades servidas por almacén, con `0` cuando la referencia se agotó en
     * central (C3) y `0` también para una línea aún sin despachar.
     */
    public function dispatchedQuantity(): int
    {
        return $this->dispatchedQty ?? 0;
    }

    /**
     * Proyección de Coordinación (contrato §5.3): la línea completa con sus
     * snapshots y el motivo que justificó la reposición. Sin coste (Art. V.4).
     *
     * @return array<string, int|string|null>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'spare_part_id' => $this->sparePartId,
            'part_code' => $this->partCode,
            'part_name' => $this->partName,
            'category' => $this->category,
            'current_quantity_snapshot' => $this->currentQuantitySnapshot,
            'min_threshold_snapshot' => $this->minThresholdSnapshot,
            'pending_request_qty_snapshot' => $this->pendingRequestQtySnapshot,
            'requested_qty' => $this->requestedQty,
            'dispatched_qty' => $this->dispatchedQty,
            'reason' => $this->reason,
        ];
    }

    /**
     * Línea de la orden despachada que el técnico titular ve en su móvil
     * (§5.10): sin snapshots ni identidad de terceros.
     *
     * @return array<string, int|string|null>
     */
    public function toTechnicianActiveArray(): array
    {
        return [
            'spare_part_id' => $this->sparePartId,
            'part_code' => $this->partCode,
            'part_name' => $this->partName,
            'category' => $this->category,
            'requested_qty' => $this->requestedQty,
            'dispatched_qty' => $this->dispatchedQty,
            'reason' => $this->reason,
        ];
    }

    /**
     * Línea del comprobante de última recepción (§5.10, QA 5): sólo la pieza y
     * las unidades efectivamente recibidas.
     *
     * @return array<string, int|string>
     */
    public function toTechnicianConfirmedSummaryArray(): array
    {
        return [
            'spare_part_id' => $this->sparePartId,
            'part_code' => $this->partCode,
            'part_name' => $this->partName,
            'dispatched_qty' => $this->dispatchedQuantity(),
        ];
    }
}
