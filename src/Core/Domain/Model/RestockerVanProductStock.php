<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;

/**
 * RestockerVanProductStock
 *
 * Entidad inmutable del stock de producto por lote que viaja en la furgoneta de
 * un reponedor (Módulo 33, Tabla 44, `restocker_van_product_stocks`, RF-01.1,
 * EARS 01.2, D2, D4, C1, Art. II).
 *
 * Invariantes que muerden en el borde de la entidad, antes de llegar al motor:
 *   1. `restocker_id` y `product_id` son identificadores positivos.
 *   2. `batch_code` se normaliza en mayúsculas y cumple `^[A-Z0-9_-]{2,40}$`;
 *      sin lote se usa `'SIN-LOTE'`, que es legítimo en un refresco y jamás en un
 *      producto refrigerado (Art. II).
 *   3. La cantidad está en `0..99999` (cota `C1` y del contrato).
 *   4. Un producto con `requires_cold_chain = TRUE` exige lote real (distinto de
 *      `'SIN-LOTE'`) y fecha de caducidad. Es la misma regla que impone el
 *      disparador `enforce_van_stock_cold_chain_batch()`: defensa en profundidad
 *      (`RNF-02`), no una segunda verdad.
 *
 * El semáforo sanitario y el stock útil no se guardan: se derivan de la fecha con
 * `BatchExpiryStatus` (EARS 01.2), de modo que el paso del tiempo no deja una
 * columna envejecida.
 *
 * La baja de un lote es un ajuste a `0`, nunca un `DELETE` (Art. III.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerVanProductStock implements JsonSerializable
{
    /** Lote normalizado de los productos sin cadena de frío. */
    public const DEFAULT_BATCH_CODE = 'SIN-LOTE';

    /** Forma canónica del código de lote (EARS 01.1, QA 3). */
    public const BATCH_PATTERN = '/^[A-Z0-9_-]{2,40}$/';

    public const MIN_QUANTITY = 0;
    public const MAX_QUANTITY = 99999;

    private readonly ?int $id;
    private readonly int $restockerId;
    private readonly int $productId;
    private readonly string $batchCode;
    private readonly ?DateTimeImmutable $expirationDate;
    private readonly int $quantityAvailable;
    private readonly ?string $createdAt;
    private readonly ?string $updatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $restockerId Usuario `RESTOCKER` propietario del saldo.
     * @param int $productId Producto del catálogo maestro.
     * @param string $batchCode Lote real o `'SIN-LOTE'`; se normaliza.
     * @param DateTimeImmutable|null $expirationDate Caducidad, obligatoria con frío.
     * @param int $quantityAvailable Existencias del lote en la furgoneta.
     * @param bool $requiresColdChain Bandera del producto, para el cerrojo de frío.
     * @param string|null $createdAt Sello de alta, tal y como lo devuelve el motor.
     * @param string|null $updatedAt Sello de última modificación.
     *
     * @throws InvalidArgumentException Si el lote, la cantidad o el cerrojo de frío
     *                                  no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $restockerId,
        int $productId,
        string $batchCode = self::DEFAULT_BATCH_CODE,
        ?DateTimeImmutable $expirationDate = null,
        int $quantityAvailable = 0,
        bool $requiresColdChain = false,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador del saldo de furgoneta debe ser un entero positivo.');
        }

        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El saldo de furgoneta debe referenciar un reponedor existente.');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('El saldo de furgoneta debe referenciar un producto existente.');
        }

        $cleanBatch = strtoupper(trim($batchCode));
        if ($cleanBatch === '') {
            $cleanBatch = self::DEFAULT_BATCH_CODE;
        }

        if (preg_match(self::BATCH_PATTERN, $cleanBatch) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El lote «%s» debe cumplir ^[A-Z0-9_-]{2,40}$ y se normaliza en mayúsculas.",
                $batchCode
            ));
        }

        if ($quantityAvailable < self::MIN_QUANTITY || $quantityAvailable > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'La cantidad del lote debe estar entre %d y %d; se recibió %d.',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY,
                $quantityAvailable
            ));
        }

        if ($requiresColdChain
            && ($cleanBatch === self::DEFAULT_BATCH_CODE || $expirationDate === null)) {
            throw new InvalidArgumentException(sprintf(
                'ART_II_COLD_CHAIN_BATCH_REQUIRED: el producto refrigerado exige lote real (distinto de %s) y fecha de caducidad.',
                self::DEFAULT_BATCH_CODE
            ));
        }

        $this->id = $id;
        $this->restockerId = $restockerId;
        $this->productId = $productId;
        $this->batchCode = $cleanBatch;
        $this->expirationDate = $expirationDate;
        $this->quantityAvailable = $quantityAvailable;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * El driver puede devolver los booleanos como `t`/`f` y las cantidades como
     * cadena; la entidad no depende de esa forma. `requires_cold_chain` llega del
     * `JOIN` con `vending_products` cuando el repositorio lo aporta.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException Si la fila no describe un saldo válido.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)($row['restocker_id'] ?? 0),
            (int)($row['product_id'] ?? 0),
            (string)($row['batch_code'] ?? self::DEFAULT_BATCH_CODE),
            isset($row['expiration_date']) && $row['expiration_date'] !== null
                ? new DateTimeImmutable((string)$row['expiration_date'])
                : null,
            (int)($row['quantity_available'] ?? 0),
            self::toBoolean($row['requires_cold_chain'] ?? false),
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getBatchCode(): string
    {
        return $this->batchCode;
    }

    public function getExpirationDate(): ?DateTimeImmutable
    {
        return $this->expirationDate;
    }

    public function getQuantityAvailable(): int
    {
        return $this->quantityAvailable;
    }

    /**
     * Semáforo sanitario del lote respecto a la fecha de referencia (EARS 01.2).
     */
    public function expiryStatus(?DateTimeImmutable $today = null): BatchExpiryStatus
    {
        return BatchExpiryStatus::fromExpirationDate($this->expirationDate, $today);
    }

    /**
     * ¿El lote puede servirse hoy? Caducado o sin unidades no es stock útil (D1).
     */
    public function isUsable(?DateTimeImmutable $today = null): bool
    {
        return $this->quantityAvailable > 0 && !$this->expiryStatus($today)->isExpired();
    }

    /**
     * Unidades que descuentan la propuesta matutina: cero si el lote caducó (D1).
     */
    public function usableQuantity(?DateTimeImmutable $today = null): int
    {
        return $this->isUsable($today) ? $this->quantityAvailable : 0;
    }

    public function isExpired(?DateTimeImmutable $today = null): bool
    {
        return $this->expiryStatus($today)->isExpired();
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->restockerId,
            $this->productId,
            $this->batchCode,
            $this->expirationDate,
            $this->quantityAvailable,
            false,
            $this->createdAt,
            $this->updatedAt
        );
    }

    /**
     * Ajuste de existencias del lote; devuelve una copia, nunca muta la instancia.
     *
     * @throws InvalidArgumentException Si la cantidad sale de `0..99999`.
     */
    public function withQuantity(int $quantityAvailable, ?string $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->restockerId,
            $this->productId,
            $this->batchCode,
            $this->expirationDate,
            $quantityAvailable,
            false,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $status = $this->expiryStatus();

        return [
            'id' => $this->id,
            'restocker_id' => $this->restockerId,
            'product_id' => $this->productId,
            'batch_code' => $this->batchCode,
            'expiration_date' => $this->expirationDate?->format('Y-m-d'),
            'quantity_available' => $this->quantityAvailable,
            'expiry_status' => $status->value,
            'expiry_status_label' => $status->label(),
            'expiry_status_icon' => $status->icon(),
            'is_expired' => $status->isExpired(),
            'is_usable' => $this->isUsable(),
            'usable_quantity' => $this->usableQuantity(),
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

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
