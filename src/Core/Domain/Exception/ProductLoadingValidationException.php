<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ProductLoadingValidationException
 *
 * Única excepción de negocio de las órdenes de carga de furgoneta y del stock por
 * lote (Módulo 33). Transporta el código exacto del contrato REST y el estado
 * HTTP con el que la capa de presentación debe responder, de modo que el
 * controlador no reinvente la traducción de cada regla.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio, se elige llamando al constructor que describe el fallo.
 *
 * Códigos por estado HTTP:
 *   - `409`: `BATCH_EXPIRATION_MISMATCH`, `INVALID_ORDER_STATE_TRANSITION` y
 *     `ORDER_CANCELLATION_NOT_ALLOWED`.
 *   - `422`: cotas de cantidad, orden sin líneas, temperatura de cámara, lote y
 *     caducidad, justificación insuficiente y cuadre de liquidación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class ProductLoadingValidationException extends DomainException
{
    // ── 409 ────────────────────────────────────────────────────────────────
    public const BATCH_EXPIRATION_MISMATCH = 'BATCH_EXPIRATION_MISMATCH';
    public const INVALID_ORDER_STATE_TRANSITION = 'INVALID_ORDER_STATE_TRANSITION';
    public const ORDER_CANCELLATION_NOT_ALLOWED = 'ORDER_CANCELLATION_NOT_ALLOWED';

    // ── 422 ────────────────────────────────────────────────────────────────
    public const EMPTY_LOADING_ORDER_ITEMS = 'EMPTY_LOADING_ORDER_ITEMS';
    public const INVALID_RESTOCKER_RECIPIENT = 'INVALID_RESTOCKER_RECIPIENT';
    public const INVALID_LOADING_QUANTITY = 'INVALID_LOADING_QUANTITY';
    public const COLD_CHAMBER_TEMP_EXCEEDED = 'COLD_CHAMBER_TEMP_EXCEEDED';
    public const EXPIRED_OR_INVALID_BATCH = 'EXPIRED_OR_INVALID_BATCH';
    public const EXPIRATION_EXCEEDS_MAX_SHELF_LIFE = 'EXPIRATION_EXCEEDS_MAX_SHELF_LIFE';
    public const INVALID_JUSTIFICATION_LENGTH = 'INVALID_JUSTIFICATION_LENGTH';
    public const RECONCILIATION_EXCEEDS_AVAILABLE_STOCK = 'RECONCILIATION_EXCEEDS_AVAILABLE_STOCK';
    public const RECONCILIATION_DISCREPANCY_REASON_REQUIRED = 'RECONCILIATION_DISCREPANCY_REASON_REQUIRED';

    private string $errorCode;

    private int $httpStatusCode;

    private ?string $field;

    /** @var array<string, mixed> Datos de contexto útiles para el log, nunca secretos. */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatusCode,
        ?string $field = null,
        array $context = []
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->field = $field;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409 · identidad del lote y estados
    // ─────────────────────────────────────────────────────────────────────

    public static function batchExpirationMismatch(
        string $batchCode,
        string $existingExpirationDate,
        string $givenExpirationDate
    ): self {
        return new self(
            self::BATCH_EXPIRATION_MISMATCH,
            sprintf(
                'El lote %s ya existe en la furgoneta con caducidad %s; no se puede declarar %s (QA 3).',
                $batchCode,
                $existingExpirationDate,
                $givenExpirationDate
            ),
            409,
            'expiration_date',
            [
                'batch_code' => $batchCode,
                'existing_expiration_date' => $existingExpirationDate,
                'given_expiration_date' => $givenExpirationDate,
            ]
        );
    }

    public static function invalidOrderStateTransition(string $currentStatus, string $action): self
    {
        return new self(
            self::INVALID_ORDER_STATE_TRANSITION,
            sprintf(
                'La orden está en estado %s y no admite la acción «%s» en este momento (RF-03, RF-04).',
                $currentStatus,
                $action
            ),
            409,
            'status',
            ['current_status' => $currentStatus, 'action' => $action]
        );
    }

    public static function orderCancellationNotAllowed(string $currentStatus): self
    {
        return new self(
            self::ORDER_CANCELLATION_NOT_ALLOWED,
            sprintf(
                'Una orden en estado %s no puede cancelarse: la mercancía ya fue recepcionada o la orden es terminal (RF-03.4).',
                $currentStatus
            ),
            409,
            'status',
            ['current_status' => $currentStatus]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · cantidades, líneas y temperatura
    // ─────────────────────────────────────────────────────────────────────

    public static function invalidRestockerRecipient(int $restockerId, string $detail): self
    {
        return new self(
            self::INVALID_RESTOCKER_RECIPIENT,
            sprintf(
                'El reponedor %d no puede recibir carga: %s (C2).',
                $restockerId,
                $detail
            ),
            422,
            'restocker_id',
            ['restocker_id' => $restockerId, 'detail' => $detail]
        );
    }

    public static function emptyLoadingOrderItems(?string $orderCode = null): self
    {
        return new self(
            self::EMPTY_LOADING_ORDER_ITEMS,
            $orderCode === null
                ? 'La orden de carga no tiene ninguna línea activa válida (C1).'
                : sprintf('La orden %s no tiene ninguna línea activa válida (C1).', $orderCode),
            422,
            'items',
            $orderCode === null ? [] : ['order_code' => $orderCode]
        );
    }

    public static function invalidLoadingQuantity(
        string $field,
        mixed $value,
        int $minimum,
        int $maximum
    ): self {
        return new self(
            self::INVALID_LOADING_QUANTITY,
            sprintf(
                'La cantidad de «%s» debe ser un entero entre %d y %d; se recibió %s (C1).',
                $field,
                $minimum,
                $maximum,
                json_encode($value)
            ),
            422,
            $field,
            ['value' => $value, 'minimum' => $minimum, 'maximum' => $maximum]
        );
    }

    public static function coldChamberTempExceeded(?float $temperature, ?string $orderCode = null): self
    {
        return new self(
            self::COLD_CHAMBER_TEMP_EXCEEDED,
            sprintf(
                'La temperatura de salida de cámara debe estar entre -5,0 y 4,0 °C%s; se recibió %s (Art. II).',
                $orderCode === null ? '' : ' para la orden ' . $orderCode,
                $temperature === null ? 'ninguna lectura' : sprintf('%.1f °C', $temperature)
            ),
            422,
            'cold_chamber_exit_temp_celsius',
            [
                'temperature' => $temperature,
                'order_code' => $orderCode,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · lote y caducidad
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     */
    public static function expiredOrInvalidBatch(string $reason, array $context = []): self
    {
        return new self(
            self::EXPIRED_OR_INVALID_BATCH,
            'El lote del producto refrigerado no cumple el cerrojo sanitario: ' . $reason . ' (Art. II, QA 2).',
            422,
            'batch_code',
            $context
        );
    }

    public static function expirationExceedsMaxShelfLife(
        string $expirationDate,
        int $maxShelfLifeDays,
        string $today
    ): self {
        return new self(
            self::EXPIRATION_EXCEEDS_MAX_SHELF_LIFE,
            sprintf(
                'La caducidad %s supera el techo de %d días de vida útil desde %s (QA 2, Art. II).',
                $expirationDate,
                $maxShelfLifeDays,
                $today
            ),
            422,
            'expiration_date',
            [
                'expiration_date' => $expirationDate,
                'max_shelf_life_days' => $maxShelfLifeDays,
                'today' => $today,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · justificaciones y cuadre
    // ─────────────────────────────────────────────────────────────────────

    public static function invalidJustificationLength(
        string $field,
        int $length,
        int $minimum = 20,
        int $maximum = 500
    ): self {
        return new self(
            self::INVALID_JUSTIFICATION_LENGTH,
            sprintf(
                'La justificación de «%s» debe contener entre %d y %d caracteres reales; se recibieron %d (Art. V.1).',
                $field,
                $minimum,
                $maximum,
                $length
            ),
            422,
            $field,
            ['length' => $length, 'minimum' => $minimum, 'maximum' => $maximum]
        );
    }

    public static function reconciliationExceedsAvailableStock(
        int $vanStockBeforeReconcile,
        int $restockedInMachinesQty,
        int $withdrawnSpoilageQty
    ): self {
        return new self(
            self::RECONCILIATION_EXCEEDS_AVAILABLE_STOCK,
            sprintf(
                'Abastecidas (%d) + mermas (%d) superan el saldo previo del lote (%d): el cuadre es imposible (D3, QA 4).',
                $restockedInMachinesQty,
                $withdrawnSpoilageQty,
                $vanStockBeforeReconcile
            ),
            422,
            'reconciliation',
            [
                'van_stock_before_reconcile' => $vanStockBeforeReconcile,
                'restocked_in_machines_qty' => $restockedInMachinesQty,
                'withdrawn_spoilage_qty' => $withdrawnSpoilageQty,
            ]
        );
    }

    public static function reconciliationDiscrepancyReasonRequired(int $discrepancyLines): self
    {
        return new self(
            self::RECONCILIATION_DISCREPANCY_REASON_REQUIRED,
            sprintf(
                'La liquidación declara %d línea(s) con descuadre y exige una nota de al menos 20 caracteres reales (D3, Art. V.1).',
                $discrepancyLines
            ),
            422,
            'reconciliation_notes',
            ['discrepancy_lines' => $discrepancyLines]
        );
    }
}
