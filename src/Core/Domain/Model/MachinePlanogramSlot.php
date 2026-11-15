<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;

/**
 * MachinePlanogramSlot
 *
 * Entidad inmutable de un carril o tolva del planograma de una máquina (Módulo 32,
 * Tabla 43, RF-02.1, RF-02.3, C1, C2).
 *
 * Invariantes que muerde en el borde de la entidad:
 *   1. `slot_code` se normaliza en mayúsculas y cumple `^[A-Z0-9_]{1,24}$`.
 *   2. La capacidad está en `1..10000`; el umbral en `0..capacidad-1`; la
 *      existencia estimada en `0..capacidad`.
 *   3. El semáforo de existencias y el déficit de reposición se derivan de esas
 *      cifras con la regla determinista de `SlotStockStatus` (RF-02.3).
 *   4. El lote vigente del carril (Módulo 34, `D1`) es opcional, se normaliza en
 *      mayúsculas y cumple `^[A-Z0-9_-]{2,40}$`; su semáforo sanitario
 *      `slot_expiry_status` solo alerta cuando quedan unidades en el carril
 *      (EARS 01.1.2).
 *
 * La baja es lógica (`is_active = FALSE`) y el guardado por `(machine_id,
 * slot_code)` es un `UPSERT`: la entidad nunca representa un borrado físico (C1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
final class MachinePlanogramSlot implements JsonSerializable
{
    /** Forma canónica del código de carril (RF-02.1). */
    public const SLOT_CODE_PATTERN = '/^[A-Z0-9_]{1,24}$/';

    /** Forma canónica del lote vigente del carril (Módulo 34, `D1`). */
    public const CURRENT_BATCH_PATTERN = '/^[A-Z0-9_-]{2,40}$/';

    public const MIN_CAPACITY = 1;
    public const MAX_CAPACITY = 10000;

    private ?int $id;

    private int $machineId;

    private string $slotCode;

    private int $productId;

    private int $maxCapacity;

    private int $currentEstimatedQty;

    private int $minThreshold;

    private bool $isActive;

    private ?string $createdAt;

    private ?string $updatedAt;

    private ?string $currentBatchCode;

    private ?DateTimeImmutable $currentExpirationDate;

    public function __construct(
        ?int $id,
        int $machineId,
        string $slotCode,
        int $productId,
        int $maxCapacity,
        int $currentEstimatedQty = 0,
        int $minThreshold = 2,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $currentBatchCode = null,
        ?DateTimeImmutable $currentExpirationDate = null
    ) {
        if ($machineId <= 0) {
            throw PlanogramValidationException::invalidSlotBounds('machine_id', 'la máquina debe ser un identificador positivo.');
        }

        if ($productId <= 0) {
            throw PlanogramValidationException::invalidSlotBounds('product_id', 'el producto debe ser un identificador positivo.');
        }

        $cleanSlotCode = strtoupper(trim($slotCode));
        if (preg_match(self::SLOT_CODE_PATTERN, $cleanSlotCode) !== 1) {
            throw PlanogramValidationException::invalidSlotBounds(
                'slot_code',
                sprintf('el código «%s» debe cumplir ^[A-Z0-9_]{1,24}$ y se normaliza en mayúsculas.', $slotCode)
            );
        }

        if ($maxCapacity < self::MIN_CAPACITY || $maxCapacity > self::MAX_CAPACITY) {
            throw PlanogramValidationException::invalidSlotBounds(
                'max_capacity',
                sprintf('la capacidad debe estar entre %d y %d; se recibió %d.', self::MIN_CAPACITY, self::MAX_CAPACITY, $maxCapacity)
            );
        }

        if ($minThreshold < 0 || $minThreshold >= $maxCapacity) {
            throw PlanogramValidationException::invalidSlotBounds(
                'min_threshold',
                sprintf('el umbral debe estar entre 0 y capacidad - 1 (%d); se recibió %d.', $maxCapacity - 1, $minThreshold)
            );
        }

        if ($currentEstimatedQty < 0 || $currentEstimatedQty > $maxCapacity) {
            throw PlanogramValidationException::invalidSlotBounds(
                'current_estimated_qty',
                sprintf('la existencia estimada debe estar entre 0 y %d; se recibió %d.', $maxCapacity, $currentEstimatedQty)
            );
        }

        $cleanCurrentBatch = $currentBatchCode === null ? null : strtoupper(trim($currentBatchCode));
        if ($cleanCurrentBatch === '') {
            $cleanCurrentBatch = null;
        }

        if ($cleanCurrentBatch !== null && preg_match(self::CURRENT_BATCH_PATTERN, $cleanCurrentBatch) !== 1) {
            throw PlanogramValidationException::invalidSlotBounds(
                'current_batch_code',
                sprintf('el lote «%s» debe cumplir ^[A-Z0-9_-]{2,40}$ y se normaliza en mayúsculas.', $currentBatchCode)
            );
        }

        $this->id = $id;
        $this->machineId = $machineId;
        $this->slotCode = $cleanSlotCode;
        $this->productId = $productId;
        $this->maxCapacity = $maxCapacity;
        $this->currentEstimatedQty = $currentEstimatedQty;
        $this->minThreshold = $minThreshold;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->currentBatchCode = $cleanCurrentBatch;
        $this->currentExpirationDate = $currentExpirationDate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getSlotCode(): string
    {
        return $this->slotCode;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getMaxCapacity(): int
    {
        return $this->maxCapacity;
    }

    public function getCurrentEstimatedQty(): int
    {
        return $this->currentEstimatedQty;
    }

    public function getMinThreshold(): int
    {
        return $this->minThreshold;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Semáforo determinista del carril (RF-02.3, C2).
     */
    public function stockStatus(): SlotStockStatus
    {
        return SlotStockStatus::fromQuantities($this->currentEstimatedQty, $this->minThreshold);
    }

    /**
     * Unidades que faltan para llenar el carril; nunca negativo.
     */
    public function restockNeededQty(): int
    {
        return max(0, $this->maxCapacity - $this->currentEstimatedQty);
    }

    /**
     * Porcentaje de llenado del carril, redondeado a dos decimales.
     */
    public function fillRatePercent(): float
    {
        return round(($this->currentEstimatedQty / $this->maxCapacity) * 100, 2);
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Lote vigente del carril tras la última visita (`D1`); `null` en un carril
     * vacío o sin trazabilidad de lote.
     */
    public function getCurrentBatchCode(): ?string
    {
        return $this->currentBatchCode;
    }

    /**
     * Caducidad del lote vigente del carril (`D1`); `null` cuando no hay lote.
     */
    public function getCurrentExpirationDate(): ?DateTimeImmutable
    {
        return $this->currentExpirationDate;
    }

    /**
     * Semáforo sanitario de caducidad del carril (EARS 01.1.2, QA 1).
     *
     * La regla del carril es más estrecha que la del lote suelto: un carril vacío
     * (`current_estimated_qty = 0`) o sin fecha nunca alerta, porque no hay
     * producto que retirar. Con unidades, delega en `BatchExpiryStatus` —la misma
     * ventana de 48 h que usa la furgoneta, para que no haya dos umbrales—, de modo
     * que `EXPIRED` cubre la caducidad de hoy y `EXPIRING_SOON` los dos días
     * siguientes.
     */
    public function slotExpiryStatus(?DateTimeImmutable $today = null): BatchExpiryStatus
    {
        if ($this->currentEstimatedQty === 0 || $this->currentExpirationDate === null) {
            return BatchExpiryStatus::OK;
        }

        return BatchExpiryStatus::fromExpirationDate($this->currentExpirationDate, $today);
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Baja lógica o reactivación del carril (C1, Art. III.1).
     */
    public function withStatus(bool $isActive): self
    {
        $clone = clone $this;
        $clone->isActive = $isActive;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $status = $this->stockStatus();
        $slotExpiry = $this->slotExpiryStatus();

        return [
            'id' => $this->id,
            'machine_id' => $this->machineId,
            'slot_code' => $this->slotCode,
            'product_id' => $this->productId,
            'max_capacity' => $this->maxCapacity,
            'current_estimated_qty' => $this->currentEstimatedQty,
            'min_threshold' => $this->minThreshold,
            'restock_needed_qty' => $this->restockNeededQty(),
            'stock_status' => $status->value,
            'stock_status_label' => $status->label(),
            'fill_rate_percent' => $this->fillRatePercent(),
            'current_batch_code' => $this->currentBatchCode,
            'current_expiration_date' => $this->currentExpirationDate?->format('Y-m-d'),
            'slot_expiry_status' => $slotExpiry->value,
            'slot_expiry_status_label' => $slotExpiry->label(),
            'slot_expiry_status_icon' => $slotExpiry->icon(),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
