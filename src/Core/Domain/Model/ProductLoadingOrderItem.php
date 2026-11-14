<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;

/**
 * ProductLoadingOrderItem
 *
 * Entidad inmutable de una línea de la orden de carga (Módulo 33, Tabla 46,
 * `product_loading_order_items`, RF-03.1, RF-04.2, EARS 03.1, 04.2, C1, D2, D3).
 *
 * Invariantes que muerden en el borde de la entidad:
 *   1. Lo solicitado está en `1..99999` y lo despachado, si existe, en `0..99999`
 *      (cotas del contrato).
 *   2. El lote declarado se normaliza en mayúsculas y cumple `^[A-Z0-9_-]{2,40}$`;
 *      la línea puede no tenerlo mientras la orden está en preparación.
 *   3. El cuadre de liquidación es **todo o nada**: o los seis campos están
 *      vacíos, o se cumple
 *      `expected_return_qty = van_stock_before_reconcile - restocked_in_machines_qty
 *      - withdrawn_spoilage_qty` y
 *      `discrepancy_qty = counted_return_qty - expected_return_qty`, con
 *      `restocked_in_machines_qty + withdrawn_spoilage_qty <= van_stock_before_reconcile`
 *      y no negatividad (D3, QA 4).
 *
 * La identidad `(order_id, product_id)` es única en el motor: editar una línea es
 * un `UPSERT` y retirarla es `is_active = FALSE`, nunca un `DELETE` (C1,
 * Art. III.1). La entidad no inventa el saldo previo del lote: lo recibe del
 * repositorio bajo bloqueo y lo valida aquí.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class ProductLoadingOrderItem implements JsonSerializable
{
    /** Forma canónica del código de lote (QA 3). */
    public const BATCH_PATTERN = '/^[A-Z0-9_-]{2,40}$/';

    public const MIN_REQUESTED_QTY = 1;
    public const MAX_REQUESTED_QTY = 99999;
    public const MIN_DISPATCHED_QTY = 0;
    public const MAX_DISPATCHED_QTY = 99999;

    private readonly ?int $id;
    private readonly int $orderId;
    private readonly int $productId;
    private readonly ProductLoadingItemReason $reason;
    private readonly int $requestedQty;
    private readonly ?int $dispatchedQty;
    private readonly ?string $batchCode;
    private readonly ?DateTimeImmutable $expirationDate;
    private readonly ?int $vanStockBeforeReconcile;
    private readonly ?int $restockedInMachinesQty;
    private readonly ?int $withdrawnSpoilageQty;
    private readonly ?int $expectedReturnQty;
    private readonly ?int $countedReturnQty;
    private readonly ?int $discrepancyQty;
    private readonly bool $isActive;
    private readonly ?string $createdAt;
    private readonly ?string $updatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $orderId Orden de carga a la que pertenece la línea.
     * @param int $productId Producto del catálogo maestro.
     * @param ProductLoadingItemReason $reason Motivo de inclusión en la orden.
     * @param int $requestedQty Unidades solicitadas (`1..99999`).
     * @param int|null $dispatchedQty Unidades despachadas (`0..99999` o `null`).
     * @param string|null $batchCode Lote del despacho; se normaliza.
     * @param DateTimeImmutable|null $expirationDate Caducidad del lote despachado.
     * @param int|null $vanStockBeforeReconcile Saldo del lote antes de liquidar.
     * @param int|null $restockedInMachinesQty Unidades abastecidas en máquinas.
     * @param int|null $withdrawnSpoilageQty Unidades retiradas por merma.
     * @param int|null $expectedReturnQty Retorno esperado (identidad aritmética).
     * @param int|null $countedReturnQty Retorno contado físicamente.
     * @param int|null $discrepancyQty Descuadre contado − esperado.
     * @param bool $isActive Baja lógica de la línea (C1).
     * @param string|null $createdAt Sello de alta.
     * @param string|null $updatedAt Sello de última modificación.
     *
     * @throws InvalidArgumentException Si las cotas, el lote o el cuadre no
     *                                  cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $orderId,
        int $productId,
        ProductLoadingItemReason $reason = ProductLoadingItemReason::STOCKOUT_ALERT,
        int $requestedQty = 1,
        ?int $dispatchedQty = null,
        ?string $batchCode = null,
        ?DateTimeImmutable $expirationDate = null,
        ?int $vanStockBeforeReconcile = null,
        ?int $restockedInMachinesQty = null,
        ?int $withdrawnSpoilageQty = null,
        ?int $expectedReturnQty = null,
        ?int $countedReturnQty = null,
        ?int $discrepancyQty = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la línea de carga debe ser un entero positivo.');
        }

        if ($orderId <= 0) {
            throw new InvalidArgumentException('La línea de carga debe pertenecer a una orden existente.');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('La línea de carga debe referenciar un producto existente.');
        }

        if ($requestedQty < self::MIN_REQUESTED_QTY || $requestedQty > self::MAX_REQUESTED_QTY) {
            throw new InvalidArgumentException(sprintf(
                'Las unidades solicitadas deben estar entre %d y %d; se recibieron %d.',
                self::MIN_REQUESTED_QTY,
                self::MAX_REQUESTED_QTY,
                $requestedQty
            ));
        }

        if ($dispatchedQty !== null
            && ($dispatchedQty < self::MIN_DISPATCHED_QTY || $dispatchedQty > self::MAX_DISPATCHED_QTY)) {
            throw new InvalidArgumentException(sprintf(
                'Las unidades despachadas deben estar entre %d y %d; se recibieron %d.',
                self::MIN_DISPATCHED_QTY,
                self::MAX_DISPATCHED_QTY,
                $dispatchedQty
            ));
        }

        $cleanBatch = $batchCode === null ? null : strtoupper(trim($batchCode));
        if ($cleanBatch === '') {
            $cleanBatch = null;
        }

        if ($cleanBatch !== null && preg_match(self::BATCH_PATTERN, $cleanBatch) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El lote «%s» debe cumplir ^[A-Z0-9_-]{2,40}$ y se normaliza en mayúsculas.",
                (string)$batchCode
            ));
        }

        self::assertReconciliationCoherence(
            $vanStockBeforeReconcile,
            $restockedInMachinesQty,
            $withdrawnSpoilageQty,
            $expectedReturnQty,
            $countedReturnQty,
            $discrepancyQty
        );

        $this->id = $id;
        $this->orderId = $orderId;
        $this->productId = $productId;
        $this->reason = $reason;
        $this->requestedQty = $requestedQty;
        $this->dispatchedQty = $dispatchedQty;
        $this->batchCode = $cleanBatch;
        $this->expirationDate = $expirationDate;
        $this->vanStockBeforeReconcile = $vanStockBeforeReconcile;
        $this->restockedInMachinesQty = $restockedInMachinesQty;
        $this->withdrawnSpoilageQty = $withdrawnSpoilageQty;
        $this->expectedReturnQty = $expectedReturnQty;
        $this->countedReturnQty = $countedReturnQty;
        $this->discrepancyQty = $discrepancyQty;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException Si la fila no describe una línea válida.
     */
    public static function fromDatabaseRow(array $row): self
    {
        $optionalInt = static function (mixed $value): ?int {
            return $value === null ? null : (int)$value;
        };

        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)($row['order_id'] ?? 0),
            (int)($row['product_id'] ?? 0),
            ProductLoadingItemReason::fromString((string)($row['reason'] ?? 'STOCKOUT_ALERT')),
            (int)($row['requested_qty'] ?? 1),
            $optionalInt($row['dispatched_qty'] ?? null),
            isset($row['batch_code']) ? (string)$row['batch_code'] : null,
            isset($row['expiration_date']) && $row['expiration_date'] !== null
                ? new DateTimeImmutable((string)$row['expiration_date'])
                : null,
            $optionalInt($row['van_stock_before_reconcile'] ?? null),
            $optionalInt($row['restocked_in_machines_qty'] ?? null),
            $optionalInt($row['withdrawn_spoilage_qty'] ?? null),
            $optionalInt($row['expected_return_qty'] ?? null),
            $optionalInt($row['counted_return_qty'] ?? null),
            $optionalInt($row['discrepancy_qty'] ?? null),
            self::toBoolean($row['is_active'] ?? true),
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getReason(): ProductLoadingItemReason
    {
        return $this->reason;
    }

    public function getRequestedQty(): int
    {
        return $this->requestedQty;
    }

    public function getDispatchedQty(): ?int
    {
        return $this->dispatchedQty;
    }

    public function getBatchCode(): ?string
    {
        return $this->batchCode;
    }

    public function getExpirationDate(): ?DateTimeImmutable
    {
        return $this->expirationDate;
    }

    public function getVanStockBeforeReconcile(): ?int
    {
        return $this->vanStockBeforeReconcile;
    }

    public function getRestockedInMachinesQty(): ?int
    {
        return $this->restockedInMachinesQty;
    }

    public function getWithdrawnSpoilageQty(): ?int
    {
        return $this->withdrawnSpoilageQty;
    }

    public function getExpectedReturnQty(): ?int
    {
        return $this->expectedReturnQty;
    }

    public function getCountedReturnQty(): ?int
    {
        return $this->countedReturnQty;
    }

    public function getDiscrepancyQty(): ?int
    {
        return $this->discrepancyQty;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * ¿La línea declara unidades servidas al despacho?
     */
    public function isDispatched(): bool
    {
        return $this->dispatchedQty !== null && $this->dispatchedQty > 0;
    }

    /**
     * ¿La línea ya tiene el cuadre de liquidación completo?
     */
    public function hasReconcileData(): bool
    {
        return $this->vanStockBeforeReconcile !== null;
    }

    /**
     * ¿El cuadre declara un descuadre que exige justificación (Art. V.1)?
     */
    public function hasDiscrepancy(): bool
    {
        return $this->discrepancyQty !== null && $this->discrepancyQty !== 0;
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
     * Registra el despacho de la línea; devuelve una copia, nunca muta la instancia.
     *
     * @throws InvalidArgumentException Si la cantidad o el lote no son válidos.
     */
    public function withDispatch(
        ?int $dispatchedQty,
        ?string $batchCode,
        ?DateTimeImmutable $expirationDate,
        ?string $updatedAt = null
    ): self {
        return new self(
            $this->id,
            $this->orderId,
            $this->productId,
            $this->reason,
            $this->requestedQty,
            $dispatchedQty,
            $batchCode,
            $expirationDate,
            $this->vanStockBeforeReconcile,
            $this->restockedInMachinesQty,
            $this->withdrawnSpoilageQty,
            $this->expectedReturnQty,
            $this->countedReturnQty,
            $this->discrepancyQty,
            $this->isActive,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    /**
     * Baja lógica o reactivación de la línea (C1, Art. III.1).
     */
    public function withActive(bool $isActive, ?string $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->orderId,
            $this->productId,
            $this->reason,
            $this->requestedQty,
            $this->dispatchedQty,
            $this->batchCode,
            $this->expirationDate,
            $this->vanStockBeforeReconcile,
            $this->restockedInMachinesQty,
            $this->withdrawnSpoilageQty,
            $this->expectedReturnQty,
            $this->countedReturnQty,
            $this->discrepancyQty,
            $isActive,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->orderId,
            $this->productId,
            $this->reason,
            $this->requestedQty,
            $this->dispatchedQty,
            $this->batchCode,
            $this->expirationDate,
            $this->vanStockBeforeReconcile,
            $this->restockedInMachinesQty,
            $this->withdrawnSpoilageQty,
            $this->expectedReturnQty,
            $this->countedReturnQty,
            $this->discrepancyQty,
            $this->isActive,
            $this->createdAt,
            $this->updatedAt
        );
    }

    /**
     * Cuadre de liquidación de una línea despachada (EARS 04.2, D3, QA 4).
     *
     * Calcula el retorno esperado y el descuadre con la identidad aritmética del
     * contrato y devuelve una copia; la entidad resultante ya está validada.
     *
     * @throws InvalidArgumentException Si las cifras son negativas o si
     *                                  `abastecidas + mermas > saldo previo`.
     */
    public function reconciled(
        int $vanStockBeforeReconcile,
        int $restockedInMachinesQty,
        int $withdrawnSpoilageQty,
        int $countedReturnQty,
        ?string $updatedAt = null
    ): self {
        if ($vanStockBeforeReconcile < 0
            || $restockedInMachinesQty < 0
            || $withdrawnSpoilageQty < 0
            || $countedReturnQty < 0) {
            throw new InvalidArgumentException(
                'Las cifras de liquidación deben ser enteros no negativos.'
            );
        }

        if ($restockedInMachinesQty + $withdrawnSpoilageQty > $vanStockBeforeReconcile) {
            throw new InvalidArgumentException(sprintf(
                'RECONCILIATION_EXCEEDS_AVAILABLE_STOCK: abastecidas (%d) + mermas (%d) superan el saldo previo del lote (%d).',
                $restockedInMachinesQty,
                $withdrawnSpoilageQty,
                $vanStockBeforeReconcile
            ));
        }

        $expectedReturnQty = $vanStockBeforeReconcile - $restockedInMachinesQty - $withdrawnSpoilageQty;

        return new self(
            $this->id,
            $this->orderId,
            $this->productId,
            $this->reason,
            $this->requestedQty,
            $this->dispatchedQty,
            $this->batchCode,
            $this->expirationDate,
            $vanStockBeforeReconcile,
            $restockedInMachinesQty,
            $withdrawnSpoilageQty,
            $expectedReturnQty,
            $countedReturnQty,
            $countedReturnQty - $expectedReturnQty,
            $this->isActive,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    /**
     * Sellado de una línea no despachada al liquidar la orden (QA 4).
     *
     * Deja los seis campos del cuadre en `0` sin exigir entrada manual.
     */
    public function sealedAtZero(?string $updatedAt = null): self
    {
        return $this->reconciled(0, 0, 0, 0, $updatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'reason_priority' => $this->reason->priority(),
            'requested_qty' => $this->requestedQty,
            'dispatched_qty' => $this->dispatchedQty,
            'batch_code' => $this->batchCode,
            'expiration_date' => $this->expirationDate?->format('Y-m-d'),
            'van_stock_before_reconcile' => $this->vanStockBeforeReconcile,
            'restocked_in_machines_qty' => $this->restockedInMachinesQty,
            'withdrawn_spoilage_qty' => $this->withdrawnSpoilageQty,
            'expected_return_qty' => $this->expectedReturnQty,
            'counted_return_qty' => $this->countedReturnQty,
            'discrepancy_qty' => $this->discrepancyQty,
            'is_active' => $this->isActive,
            'is_dispatched' => $this->isDispatched(),
            'has_reconcile_data' => $this->hasReconcileData(),
            'has_discrepancy' => $this->hasDiscrepancy(),
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

    /**
     * Valida la coherencia todo-o-nada y la identidad aritmética del cuadre.
     *
     * @throws InvalidArgumentException
     */
    private static function assertReconciliationCoherence(
        ?int $vanStockBeforeReconcile,
        ?int $restockedInMachinesQty,
        ?int $withdrawnSpoilageQty,
        ?int $expectedReturnQty,
        ?int $countedReturnQty,
        ?int $discrepancyQty
    ): void {
        $values = [
            $vanStockBeforeReconcile,
            $restockedInMachinesQty,
            $withdrawnSpoilageQty,
            $expectedReturnQty,
            $countedReturnQty,
            $discrepancyQty,
        ];

        $declared = array_values(array_filter($values, static fn (?int $value): bool => $value !== null));

        if ($declared === []) {
            return;
        }

        if (count($declared) !== count($values)) {
            throw new InvalidArgumentException(
                'El cuadre de liquidación es todo o nada: los seis campos deben declararse juntos.'
            );
        }

        /** @var int $before */
        $before = $vanStockBeforeReconcile;
        /** @var int $restocked */
        $restocked = $restockedInMachinesQty;
        /** @var int $withdrawn */
        $withdrawn = $withdrawnSpoilageQty;
        /** @var int $expected */
        $expected = $expectedReturnQty;
        /** @var int $counted */
        $counted = $countedReturnQty;
        /** @var int $discrepancy */
        $discrepancy = $discrepancyQty;

        if ($before < 0 || $restocked < 0 || $withdrawn < 0 || $counted < 0) {
            throw new InvalidArgumentException('Las cifras de liquidación deben ser enteros no negativos.');
        }

        if ($counted > self::MAX_DISPATCHED_QTY) {
            throw new InvalidArgumentException(sprintf(
                'El retorno contado no puede superar %d unidades.',
                self::MAX_DISPATCHED_QTY
            ));
        }

        if ($restocked + $withdrawn > $before) {
            throw new InvalidArgumentException(sprintf(
                'RECONCILIATION_EXCEEDS_AVAILABLE_STOCK: abastecidas (%d) + mermas (%d) superan el saldo previo del lote (%d).',
                $restocked,
                $withdrawn,
                $before
            ));
        }

        if ($expected !== $before - $restocked - $withdrawn) {
            throw new InvalidArgumentException(sprintf(
                'El retorno esperado debe ser %d (saldo previo − abastecidas − mermas); se declaró %d.',
                $before - $restocked - $withdrawn,
                $expected
            ));
        }

        if ($discrepancy !== $counted - $expected) {
            throw new InvalidArgumentException(sprintf(
                'El descuadre debe ser %d (contado − esperado); se declaró %d.',
                $counted - $expected,
                $discrepancy
            ));
        }
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
