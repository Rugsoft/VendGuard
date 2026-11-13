<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\ProductLoadingDemandMode;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;

/**
 * ProductLoadingOrder
 *
 * Entidad inmutable de una orden de carga de furgoneta (Módulo 33, Tabla 45,
 * `product_loading_orders`, RF-03.1, EARS 03.1, 03.4, 04.2, D2, D3, Art. II,
 * Art. III.1, Art. V.1).
 *
 * Reproduce en PHP las mismas coherencias que el motor impone por `CHECK`, para
 * que un estado imposible falle antes de intentar escribirlo:
 *   1. `order_code` se normaliza en mayúsculas y cumple `PLO-AAAA-NNNN`.
 *   2. El colchón de seguridad está en `0..50 %`.
 *   3. La temperatura de salida de cámara es nula o está en `[-5,0, 4,0] °C`
 *      (Art. II).
 *   4. `CANCELLED` exige fecha de cancelación y motivo de al menos 20 caracteres
 *      reales; cualquier otro estado los prohíbe (Art. V.1).
 *   5. `RECONCILED_AT_CLOSE` exige fecha y responsable de liquidación; cualquier
 *      otro estado los prohíbe (D3).
 *
 * La entidad no decide si una orden concreta exige temperatura: eso depende de
 * las líneas refrigeradas servidas y lo resuelve `ProductLoadingValidator` sobre
 * el conjunto (T-PLD-04). Aquí solo se declara el rango físico admisible.
 *
 * Las transiciones de estado las ejecutan los servicios con bloqueo pesimista;
 * esta entidad expone la matriz `canBe…()` para que no exista otra copia.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class ProductLoadingOrder implements JsonSerializable
{
    /** Forma canónica de la orden (EARS 03.1). */
    public const ORDER_CODE_PATTERN = '/^PLO-[0-9]{4}-[0-9]{4,}$/';

    public const MIN_SAFETY_BUFFER_PCT = 0;
    public const MAX_SAFETY_BUFFER_PCT = 50;

    public const MIN_COLD_CHAMBER_TEMP_CELSIUS = -5.0;
    public const MAX_COLD_CHAMBER_TEMP_CELSIUS = 4.0;

    /** Longitud mínima real de una justificación (Art. V.1). */
    public const MIN_JUSTIFICATION_LENGTH = 20;

    /** Longitud máxima de las columnas de texto del contrato. */
    public const MAX_TEXT_LENGTH = 500;

    private readonly ?int $id;
    private readonly string $orderCode;
    private readonly int $restockerId;
    private readonly int $createdBy;
    private readonly ProductLoadingOrderStatus $status;
    private readonly ProductLoadingDemandMode $demandMode;
    private readonly int $safetyBufferPct;
    private readonly ?float $coldChamberExitTempCelsius;
    private readonly ?string $notes;
    private readonly ?string $cancelReason;
    private readonly ?string $reconciliationNotes;
    private readonly ?int $dispatchedBy;
    private readonly ?int $reconciledBy;
    private readonly ?string $createdAt;
    private readonly ?string $dispatchedAt;
    private readonly ?string $confirmedAt;
    private readonly ?string $reconciledAt;
    private readonly ?string $cancelledAt;
    private readonly ?string $updatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param string $orderCode Código `PLO-AAAA-NNNN`; se normaliza.
     * @param int $restockerId Reponedor destinatario de la orden.
     * @param int $createdBy Usuario de Coordinación que emite la orden.
     * @param ProductLoadingOrderStatus $status Estado del ciclo de vida.
     * @param ProductLoadingDemandMode $demandMode Modo de cálculo de la demanda.
     * @param int $safetyBufferPct Colchón aplicado a la propuesta (`0..50`).
     * @param float|null $coldChamberExitTempCelsius Temperatura de salida de cámara.
     * @param string|null $notes Observaciones libres de la orden.
     * @param string|null $cancelReason Motivo de cancelación (≥ 20 caracteres reales).
     * @param string|null $reconciliationNotes Nota de liquidación (≥ 20 si hay descuadre).
     * @param int|null $dispatchedBy Usuario de almacén que despachó.
     * @param int|null $reconciledBy Usuario que liquidó la orden.
     * @param string|null $createdAt Sello de alta.
     * @param string|null $dispatchedAt Sello de despacho.
     * @param string|null $confirmedAt Sello de recepción en furgoneta.
     * @param string|null $reconciledAt Sello de liquidación.
     * @param string|null $cancelledAt Sello de cancelación.
     * @param string|null $updatedAt Sello de última modificación.
     *
     * @throws InvalidArgumentException Si el código, las cotas o la coherencia de
     *                                  los estados terminales no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        string $orderCode,
        int $restockerId,
        int $createdBy,
        ProductLoadingOrderStatus $status = ProductLoadingOrderStatus::PENDING_PICKING,
        ProductLoadingDemandMode $demandMode = ProductLoadingDemandMode::ALERTS_ONLY,
        int $safetyBufferPct = 10,
        ?float $coldChamberExitTempCelsius = null,
        ?string $notes = null,
        ?string $cancelReason = null,
        ?string $reconciliationNotes = null,
        ?int $dispatchedBy = null,
        ?int $reconciledBy = null,
        ?string $createdAt = null,
        ?string $dispatchedAt = null,
        ?string $confirmedAt = null,
        ?string $reconciledAt = null,
        ?string $cancelledAt = null,
        ?string $updatedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la orden de carga debe ser un entero positivo.');
        }

        $cleanCode = strtoupper(trim($orderCode));
        if (preg_match(self::ORDER_CODE_PATTERN, $cleanCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El código de orden «%s» debe cumplir PLO-AAAA-NNNN.",
                $orderCode
            ));
        }

        if ($restockerId <= 0) {
            throw new InvalidArgumentException('La orden de carga debe referenciar un reponedor existente.');
        }

        if ($createdBy <= 0) {
            throw new InvalidArgumentException('La orden de carga debe declarar el usuario que la emite.');
        }

        if ($safetyBufferPct < self::MIN_SAFETY_BUFFER_PCT || $safetyBufferPct > self::MAX_SAFETY_BUFFER_PCT) {
            throw new InvalidArgumentException(sprintf(
                'El colchón de seguridad debe estar entre %d y %d %%; se recibió %d.',
                self::MIN_SAFETY_BUFFER_PCT,
                self::MAX_SAFETY_BUFFER_PCT,
                $safetyBufferPct
            ));
        }

        if ($coldChamberExitTempCelsius !== null
            && ($coldChamberExitTempCelsius < self::MIN_COLD_CHAMBER_TEMP_CELSIUS
                || $coldChamberExitTempCelsius > self::MAX_COLD_CHAMBER_TEMP_CELSIUS)) {
            throw new InvalidArgumentException(sprintf(
                'ART_II_COLD_CHAMBER_TEMP_VIOLATION: la temperatura de salida de cámara debe estar entre %.1f y %.1f °C; se recibió %.1f °C.',
                self::MIN_COLD_CHAMBER_TEMP_CELSIUS,
                self::MAX_COLD_CHAMBER_TEMP_CELSIUS,
                $coldChamberExitTempCelsius
            ));
        }

        self::assertOptionalText('notes', $notes);
        self::assertOptionalText('reconciliation_notes', $reconciliationNotes);

        $isCancelled = $status === ProductLoadingOrderStatus::CANCELLED;
        $hasCancelData = $cancelledAt !== null || $cancelReason !== null;
        if ($isCancelled) {
            if ($cancelledAt === null || $cancelReason === null) {
                throw new InvalidArgumentException(
                    'Una orden cancelada exige fecha de cancelación y motivo justificado (Art. V.1).'
                );
            }
            if (mb_strlen(trim($cancelReason), 'UTF-8') < self::MIN_JUSTIFICATION_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'El motivo de cancelación exige al menos %d caracteres reales (Art. V.1).',
                    self::MIN_JUSTIFICATION_LENGTH
                ));
            }
            self::assertOptionalText('cancel_reason', $cancelReason);
        } elseif ($hasCancelData) {
            throw new InvalidArgumentException(
                'Solo una orden cancelada puede declarar fecha y motivo de cancelación.'
            );
        }

        $isReconciled = $status === ProductLoadingOrderStatus::RECONCILED_AT_CLOSE;
        $hasReconcileData = $reconciledAt !== null || $reconciledBy !== null;
        if ($isReconciled) {
            if ($reconciledAt === null || $reconciledBy === null) {
                throw new InvalidArgumentException(
                    'Una orden liquidada exige fecha y responsable de liquidación (D3).'
                );
            }
            if ($reconciledBy <= 0) {
                throw new InvalidArgumentException('El responsable de liquidación debe ser un usuario existente.');
            }
            if ($reconciliationNotes !== null
                && mb_strlen(trim($reconciliationNotes), 'UTF-8') < self::MIN_JUSTIFICATION_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'La nota de liquidación exige al menos %d caracteres reales cuando se declara (Art. V.1).',
                    self::MIN_JUSTIFICATION_LENGTH
                ));
            }
        } elseif ($hasReconcileData) {
            throw new InvalidArgumentException(
                'Solo una orden liquidada puede declarar fecha y responsable de liquidación.'
            );
        }

        $this->id = $id;
        $this->orderCode = $cleanCode;
        $this->restockerId = $restockerId;
        $this->createdBy = $createdBy;
        $this->status = $status;
        $this->demandMode = $demandMode;
        $this->safetyBufferPct = $safetyBufferPct;
        $this->coldChamberExitTempCelsius = $coldChamberExitTempCelsius;
        $this->notes = $notes;
        $this->cancelReason = $cancelReason;
        $this->reconciliationNotes = $reconciliationNotes;
        $this->dispatchedBy = $dispatchedBy;
        $this->reconciledBy = $reconciledBy;
        $this->createdAt = $createdAt;
        $this->dispatchedAt = $dispatchedAt;
        $this->confirmedAt = $confirmedAt;
        $this->reconciledAt = $reconciledAt;
        $this->cancelledAt = $cancelledAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException Si la fila no describe una orden válida.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)($row['order_code'] ?? ''),
            (int)($row['restocker_id'] ?? 0),
            (int)($row['created_by'] ?? 0),
            ProductLoadingOrderStatus::fromString((string)($row['status'] ?? 'PENDING_PICKING')),
            ProductLoadingDemandMode::fromString((string)($row['demand_mode'] ?? 'ALERTS_ONLY')),
            (int)($row['safety_buffer_pct'] ?? 10),
            isset($row['cold_chamber_exit_temp_celsius']) && $row['cold_chamber_exit_temp_celsius'] !== null
                ? (float)$row['cold_chamber_exit_temp_celsius']
                : null,
            isset($row['notes']) ? (string)$row['notes'] : null,
            isset($row['cancel_reason']) ? (string)$row['cancel_reason'] : null,
            isset($row['reconciliation_notes']) ? (string)$row['reconciliation_notes'] : null,
            isset($row['dispatched_by']) ? (int)$row['dispatched_by'] : null,
            isset($row['reconciled_by']) ? (int)$row['reconciled_by'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['dispatched_at']) ? (string)$row['dispatched_at'] : null,
            isset($row['confirmed_at']) ? (string)$row['confirmed_at'] : null,
            isset($row['reconciled_at']) ? (string)$row['reconciled_at'] : null,
            isset($row['cancelled_at']) ? (string)$row['cancelled_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderCode(): string
    {
        return $this->orderCode;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function getStatus(): ProductLoadingOrderStatus
    {
        return $this->status;
    }

    public function getDemandMode(): ProductLoadingDemandMode
    {
        return $this->demandMode;
    }

    public function getSafetyBufferPct(): int
    {
        return $this->safetyBufferPct;
    }

    public function getColdChamberExitTempCelsius(): ?float
    {
        return $this->coldChamberExitTempCelsius;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function getReconciliationNotes(): ?string
    {
        return $this->reconciliationNotes;
    }

    public function getDispatchedBy(): ?int
    {
        return $this->dispatchedBy;
    }

    public function getReconciledBy(): ?int
    {
        return $this->reconciledBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getDispatchedAt(): ?string
    {
        return $this->dispatchedAt;
    }

    public function getConfirmedAt(): ?string
    {
        return $this->confirmedAt;
    }

    public function getReconciledAt(): ?string
    {
        return $this->reconciledAt;
    }

    public function getCancelledAt(): ?string
    {
        return $this->cancelledAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canBeEdited(): bool
    {
        return $this->status->canBeEdited();
    }

    public function canBeDispatched(): bool
    {
        return $this->status->canBeDispatched();
    }

    public function canBeConfirmed(): bool
    {
        return $this->status->canBeConfirmed();
    }

    public function canBeReconciled(): bool
    {
        return $this->status->canBeReconciled();
    }

    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    /**
     * ¿Declara una temperatura dentro del rango físico del Art. II?
     */
    public function hasValidColdChamberTemperature(): bool
    {
        return $this->coldChamberExitTempCelsius !== null
            && $this->coldChamberExitTempCelsius >= self::MIN_COLD_CHAMBER_TEMP_CELSIUS
            && $this->coldChamberExitTempCelsius <= self::MAX_COLD_CHAMBER_TEMP_CELSIUS;
    }

    public function withId(int $id): self
    {
        return $this->copyWith(['id' => $id]);
    }

    /**
     * Observaciones de la orden; devuelve una copia, nunca muta la instancia.
     */
    public function withNotes(?string $notes, ?string $updatedAt = null): self
    {
        return $this->copyWith([
            'notes' => $notes,
            'updated_at' => $updatedAt ?? $this->updatedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode,
            'restocker_id' => $this->restockerId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'demand_mode' => $this->demandMode->value,
            'demand_mode_label' => $this->demandMode->label(),
            'safety_buffer_pct' => $this->safetyBufferPct,
            'cold_chamber_exit_temp_celsius' => $this->coldChamberExitTempCelsius,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancelReason,
            'reconciliation_notes' => $this->reconciliationNotes,
            'created_by' => $this->createdBy,
            'dispatched_by' => $this->dispatchedBy,
            'reconciled_by' => $this->reconciledBy,
            'created_at' => $this->createdAt,
            'dispatched_at' => $this->dispatchedAt,
            'confirmed_at' => $this->confirmedAt,
            'reconciled_at' => $this->reconciledAt,
            'cancelled_at' => $this->cancelledAt,
            'updated_at' => $this->updatedAt,
            'is_active' => $this->isActive(),
            'is_terminal' => $this->isTerminal(),
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
     * Copia inmutable con los campos indicados sustituidos.
     *
     * @param array<string, mixed> $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            $overrides['id'] ?? $this->id,
            $this->orderCode,
            $this->restockerId,
            $this->createdBy,
            $this->status,
            $this->demandMode,
            $this->safetyBufferPct,
            $this->coldChamberExitTempCelsius,
            array_key_exists('notes', $overrides) ? $overrides['notes'] : $this->notes,
            $this->cancelReason,
            $this->reconciliationNotes,
            $this->dispatchedBy,
            $this->reconciledBy,
            $this->createdAt,
            $this->dispatchedAt,
            $this->confirmedAt,
            $this->reconciledAt,
            $this->cancelledAt,
            $overrides['updated_at'] ?? $this->updatedAt
        );
    }

    /**
     * @throws InvalidArgumentException Si el texto supera la columna del contrato.
     */
    private static function assertOptionalText(string $field, ?string $value): void
    {
        if ($value !== null && mb_strlen($value, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El campo «%s» no puede superar %d caracteres.',
                $field,
                self::MAX_TEXT_LENGTH
            ));
        }
    }
}
