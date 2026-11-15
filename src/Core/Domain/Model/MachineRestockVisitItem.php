<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * MachineRestockVisitItem
 *
 * Línea carril a carril de una visita de reposición in situ (Módulo 34, Tabla 48
 * `machine_restock_visit_items`, RF-01.2, D1, C1, QA 3, Art. III.1 y III.3).
 *
 * La entidad **calcula** las dos cifras que el contrato declara como identidades
 * y no las acepta del cliente, porque un total que llega de fuera es una
 * declaración y uno que se deriva es una garantía:
 *
 *   - `qty_after = qty_before - qty_withdrawn_expired - qty_withdrawn_damaged + qty_added`
 *     (`chk_machine_restock_visit_items_qty_bounds`, C1). Es además la existencia
 *     que adopta el carril en `machine_planogram_slots` (D1).
 *   - `commercial_spoilage_loss_eur = ROUND((qty_withdrawn_expired + qty_withdrawn_damaged) × unit_cost_snapshot_eur, 2)`
 *     (`chk_machine_restock_visit_items_cost_coherence`, QA 3).
 *
 * Invariantes de borde:
 *   1. `slot_code` se normaliza en mayúsculas y cumple `^[A-Z0-9_]{1,24}$`.
 *   2. Cada cantidad está en `0..10000` y las retiradas nunca superan la
 *      existencia previa (`0 <= retiradas <= qty_before`).
 *   3. Los lotes, cuando existen, cumplen `^[A-Z0-9_-]{2,40}$`; con
 *      `qty_added > 0` el lote cargado es obligatorio.
 *   4. El coste unitario congelado está en `[0,00, 9 999,99] €` y se redondea a
 *      dos decimales, la precisión de `NUMERIC(10,2)`.
 *
 * **Opacidad de costes (Art. V.4, QA 3).** `toArray()` omite por defecto
 * `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur`; solo la proyección
 * explícita de Coordinación (`toArray(true)`) los incluye. `jsonSerialize()`
 * hereda el modo opaco, de modo que un `json_encode` accidental de la entidad en
 * una respuesta del reponedor no puede filtrar la merma comercial.
 *
 * Es inmutable: `withId()` devuelve una copia y el histórico no se reescribe
 * (Art. III.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineRestockVisitItem implements JsonSerializable
{
    /** Forma canónica del código de carril, la misma del planograma (RF-02.1). */
    public const SLOT_CODE_PATTERN = '/^[A-Z0-9_]{1,24}$/';

    /** Forma canónica del código de lote (QA 3). */
    public const BATCH_PATTERN = '/^[A-Z0-9_-]{2,40}$/';

    public const MIN_QUANTITY = 0;
    public const MAX_QUANTITY = 10000;

    public const MIN_UNIT_COST_EUR = 0.00;
    public const MAX_UNIT_COST_EUR = 9999.99;

    private ?int $id;
    private int $visitId;
    private int $slotId;
    private string $slotCode;
    private int $productId;
    private int $qtyBefore;
    private int $qtyWithdrawnExpired;
    private int $qtyWithdrawnDamaged;
    private int $qtyAdded;
    private int $qtyAfter;
    private float $unitCostSnapshotEur;
    private float $commercialSpoilageLossEur;
    private ?string $withdrawnBatchCode;
    private ?DateTimeImmutable $withdrawnExpirationDate;
    private ?string $loadedBatchCode;
    private ?DateTimeImmutable $loadedExpirationDate;
    private ?string $createdAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $visitId Visita a la que pertenece la línea.
     * @param int $slotId Carril del planograma intervenido.
     * @param string $slotCode Código de carril congelado en la línea.
     * @param int $productId Producto del carril.
     * @param int $qtyBefore Existencia estimada antes de la visita.
     * @param int $qtyWithdrawnExpired Unidades retiradas por caducidad (FEFO).
     * @param int $qtyWithdrawnDamaged Unidades retiradas por envase dañado.
     * @param int $qtyAdded Unidades cargadas desde la furgoneta.
     * @param float $unitCostSnapshotEur Coste unitario congelado en el instante de la visita.
     * @param string|null $loadedBatchCode Lote cargado; obligatorio si `qty_added > 0`.
     * @param DateTimeImmutable|null $loadedExpirationDate Caducidad del lote cargado.
     * @param string|null $withdrawnBatchCode Lote retirado del carril.
     * @param DateTimeImmutable|null $withdrawnExpirationDate Caducidad del lote retirado.
     * @param string|null $createdAt Sello de alta del motor.
     *
     * @throws InvalidArgumentException Si las cantidades rompen la aritmética del
     *                                  C1 o un código, lote o coste sale de rango.
     */
    public function __construct(
        ?int $id,
        int $visitId,
        int $slotId,
        string $slotCode,
        int $productId,
        int $qtyBefore,
        int $qtyWithdrawnExpired,
        int $qtyWithdrawnDamaged,
        int $qtyAdded,
        float $unitCostSnapshotEur,
        ?string $loadedBatchCode = null,
        ?DateTimeImmutable $loadedExpirationDate = null,
        ?string $withdrawnBatchCode = null,
        ?DateTimeImmutable $withdrawnExpirationDate = null,
        ?string $createdAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la línea de visita debe ser un entero positivo.');
        }

        if ($visitId <= 0) {
            throw new InvalidArgumentException('La línea debe pertenecer a una visita existente.');
        }

        if ($slotId <= 0) {
            throw new InvalidArgumentException('La línea debe referenciar un carril existente.');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('La línea debe referenciar un producto existente.');
        }

        $cleanSlotCode = strtoupper(trim($slotCode));
        if (preg_match(self::SLOT_CODE_PATTERN, $cleanSlotCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El código de carril «%s» debe cumplir ^[A-Z0-9_]{1,24}$ y se normaliza en mayúsculas.',
                $slotCode
            ));
        }

        $quantities = [
            'qty_before' => $qtyBefore,
            'qty_withdrawn_expired' => $qtyWithdrawnExpired,
            'qty_withdrawn_damaged' => $qtyWithdrawnDamaged,
            'qty_added' => $qtyAdded,
        ];

        foreach ($quantities as $field => $quantity) {
            if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
                throw new InvalidArgumentException(sprintf(
                    'La cantidad %s debe estar entre %d y %d; se recibió %d.',
                    $field,
                    self::MIN_QUANTITY,
                    self::MAX_QUANTITY,
                    $quantity
                ));
            }
        }

        $withdrawn = $qtyWithdrawnExpired + $qtyWithdrawnDamaged;
        if ($withdrawn > $qtyBefore) {
            throw new InvalidArgumentException(sprintf(
                'Las retiradas (%d) no pueden superar la existencia previa (%d); viola chk_machine_restock_visit_items_qty_bounds.',
                $withdrawn,
                $qtyBefore
            ));
        }

        $qtyAfter = $qtyBefore - $withdrawn + $qtyAdded;
        if ($qtyAfter < self::MIN_QUANTITY || $qtyAfter > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'La existencia resultante debe estar entre %d y %d; se calculó %d.',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY,
                $qtyAfter
            ));
        }

        $cleanLoadedBatch = self::normalizeBatch($loadedBatchCode, 'loaded_batch_code');
        if ($qtyAdded > 0 && $cleanLoadedBatch === null) {
            throw new InvalidArgumentException(
                'Cargar unidades exige el lote de origen (loaded_batch_code); viola chk_machine_restock_visit_items_batch_formats.'
            );
        }

        $cost = round($unitCostSnapshotEur, 2);
        if ($cost < self::MIN_UNIT_COST_EUR || $cost > self::MAX_UNIT_COST_EUR) {
            throw new InvalidArgumentException(sprintf(
                'El coste unitario congelado debe estar entre %.2f y %.2f €; se recibió %.2f €.',
                self::MIN_UNIT_COST_EUR,
                self::MAX_UNIT_COST_EUR,
                $cost
            ));
        }

        $this->id = $id;
        $this->visitId = $visitId;
        $this->slotId = $slotId;
        $this->slotCode = $cleanSlotCode;
        $this->productId = $productId;
        $this->qtyBefore = $qtyBefore;
        $this->qtyWithdrawnExpired = $qtyWithdrawnExpired;
        $this->qtyWithdrawnDamaged = $qtyWithdrawnDamaged;
        $this->qtyAdded = $qtyAdded;
        $this->qtyAfter = $qtyAfter;
        $this->unitCostSnapshotEur = $cost;
        $this->commercialSpoilageLossEur = round($withdrawn * $cost, 2);
        $this->withdrawnBatchCode = self::normalizeBatch($withdrawnBatchCode, 'withdrawn_batch_code');
        $this->withdrawnExpirationDate = $withdrawnExpirationDate;
        $this->loadedBatchCode = $cleanLoadedBatch;
        $this->loadedExpirationDate = $loadedExpirationDate;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitId(): int
    {
        return $this->visitId;
    }

    public function getSlotId(): int
    {
        return $this->slotId;
    }

    public function getSlotCode(): string
    {
        return $this->slotCode;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getQtyBefore(): int
    {
        return $this->qtyBefore;
    }

    public function getQtyWithdrawnExpired(): int
    {
        return $this->qtyWithdrawnExpired;
    }

    public function getQtyWithdrawnDamaged(): int
    {
        return $this->qtyWithdrawnDamaged;
    }

    public function getQtyAdded(): int
    {
        return $this->qtyAdded;
    }

    /**
     * Existencia que adopta el carril tras la visita (D1, C1).
     */
    public function getQtyAfter(): int
    {
        return $this->qtyAfter;
    }

    public function getUnitCostSnapshotEur(): float
    {
        return $this->unitCostSnapshotEur;
    }

    /**
     * Merma comercial congelada (QA 3). Solo se proyecta en Coordinación (Art. V.4).
     */
    public function getCommercialSpoilageLossEur(): float
    {
        return $this->commercialSpoilageLossEur;
    }

    public function getWithdrawnBatchCode(): ?string
    {
        return $this->withdrawnBatchCode;
    }

    public function getWithdrawnExpirationDate(): ?DateTimeImmutable
    {
        return $this->withdrawnExpirationDate;
    }

    public function getLoadedBatchCode(): ?string
    {
        return $this->loadedBatchCode;
    }

    public function getLoadedExpirationDate(): ?DateTimeImmutable
    {
        return $this->loadedExpirationDate;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Unidades retiradas en la línea: caducadas más dañadas (QA 3).
     */
    public function withdrawnUnitsTotal(): int
    {
        return $this->qtyWithdrawnExpired + $this->qtyWithdrawnDamaged;
    }

    public function hasWithdrawals(): bool
    {
        return $this->withdrawnUnitsTotal() > 0;
    }

    /**
     * ¿Quedaban unidades sanas en el carril antes de cargar? Es la condición que
     * obliga a conservar la caducidad más próxima al actualizar el carril (D1).
     */
    public function keepsHealthyRemainder(): bool
    {
        return $this->qtyBefore - $this->withdrawnUnitsTotal() > 0;
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Proyección de la línea.
     *
     * `$includeCosts = false` es el modo seguro por defecto: el reponedor, el
     * técnico, la sede y el canal QR nunca reciben los dos campos financieros
     * (Art. V.4, QA 3). Solo Coordinación pasa `true`.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeCosts = false): array
    {
        $data = [
            'id' => $this->id,
            'visit_id' => $this->visitId,
            'slot_id' => $this->slotId,
            'slot_code' => $this->slotCode,
            'product_id' => $this->productId,
            'qty_before' => $this->qtyBefore,
            'qty_withdrawn_expired' => $this->qtyWithdrawnExpired,
            'qty_withdrawn_damaged' => $this->qtyWithdrawnDamaged,
            'qty_added' => $this->qtyAdded,
            'qty_after' => $this->qtyAfter,
            'withdrawn_batch_code' => $this->withdrawnBatchCode,
            'withdrawn_expiration_date' => $this->withdrawnExpirationDate?->format('Y-m-d'),
            'loaded_batch_code' => $this->loadedBatchCode,
            'loaded_expiration_date' => $this->loadedExpirationDate?->format('Y-m-d'),
        ];

        if ($includeCosts) {
            $data['unit_cost_snapshot_eur'] = $this->unitCostSnapshotEur;
            $data['commercial_spoilage_loss_eur'] = $this->commercialSpoilageLossEur;
        }

        $data['created_at'] = $this->createdAt;

        return $data;
    }

    /**
     * Serialización opaca: los costes solo viajan por `toArray(true)`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws InvalidArgumentException Si el lote no cumple el formato del contrato.
     */
    private static function normalizeBatch(?string $batchCode, string $field): ?string
    {
        if ($batchCode === null) {
            return null;
        }

        $clean = strtoupper(trim($batchCode));
        if ($clean === '') {
            return null;
        }

        if (preg_match(self::BATCH_PATTERN, $clean) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s («%s») debe cumplir ^[A-Z0-9_-]{2,40}$ y se normaliza en mayúsculas.',
                $field,
                $batchCode
            ));
        }

        return $clean;
    }
}
