<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineCashCollectionValidationException
 *
 * Única excepción de negocio de la recaudación de efectivo, la carga de tubos de
 * cambio y el arqueo de caja (Módulo 35). Transporta el código exacto del
 * contrato REST y el estado HTTP con el que la capa de presentación debe
 * responder, de modo que ningún controlador reinvente la traducción de las
 * reglas del Arqueo Ciego.
 *
 * Los constructores son semánticos: el código no se escribe a mano en el
 * servicio, se elige llamando al constructor que describe el fallo.
 *
 * Códigos por estado HTTP:
 *   - `409`: máquina bloqueada por cuarentena, fuera de servicio o avería
 *     térmica abierta (Art. II.2); sede sin conserjería física (D3); bolsa ya
 *     arqueada (D4, Art. III.3).
 *   - `422`: precinto inválido (C1), regresión del contador o reseteo sin nota
 *     real (C3, Art. V.1), cotas económicas (D2), entrega en conserjería (D3),
 *     visita abortada por rotura de frío (C4, Art. II.2) y justificación del
 *     descuadre (D2, Art. V.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineCashCollectionValidationException extends DomainException
{
    // ── 403 · rol y perímetro territorial ────────────────────────────────
    public const MACHINE_OUTSIDE_RESTOCKER_ZONES = 'MACHINE_OUTSIDE_RESTOCKER_ZONES';
    public const CASH_COLLECTION_FORBIDDEN_FOR_ROLE = 'CASH_COLLECTION_FORBIDDEN_FOR_ROLE';

    // ── 404 · máquina o recaudación inexistente ──────────────────────────
    public const MACHINE_NOT_FOUND = 'MACHINE_NOT_FOUND';
    public const CASH_COLLECTION_NOT_FOUND = 'CASH_COLLECTION_NOT_FOUND';

    // ── 409 · cerrojo sanitario, conserjería y estado terminal ─────────────
    public const SANITARY_CASH_COLLECTION_BLOCKED = 'SANITARY_CASH_COLLECTION_BLOCKED';
    public const BAG_SEAL_CODE_ALREADY_USED = 'BAG_SEAL_CODE_ALREADY_USED';
    public const REFUND_REQUEST_NOT_ELIGIBLE_FOR_RECEPTION = 'REFUND_REQUEST_NOT_ELIGIBLE_FOR_RECEPTION';
    public const INVALID_CASH_COLLECTION_STATE_TRANSITION = 'INVALID_CASH_COLLECTION_STATE_TRANSITION';
    public const LOCATION_HAS_NO_PHYSICAL_RECEPTION = 'LOCATION_HAS_NO_PHYSICAL_RECEPTION';
    public const CASH_COLLECTION_ALREADY_RECONCILED = 'CASH_COLLECTION_ALREADY_RECONCILED';

    // ── 422 · precinto, contador, conserjería, térmico y arqueo ────────────
    public const INVALID_BAG_SEAL_CODE = 'INVALID_BAG_SEAL_CODE';
    public const SALES_COUNTER_REGRESSION = 'SALES_COUNTER_REGRESSION';
    public const COUNTER_RESET_NOTES_REQUIRED = 'COUNTER_RESET_NOTES_REQUIRED';
    public const INVALID_CASH_AMOUNT_BOUNDS = 'INVALID_CASH_AMOUNT_BOUNDS';
    public const RECEPTION_CASH_LIMIT_EXCEEDED = 'RECEPTION_CASH_LIMIT_EXCEEDED';
    public const RECEPTIONIST_NAME_REQUIRED = 'RECEPTIONIST_NAME_REQUIRED';
    public const THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN = 'THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN';
    public const DISCREPANCY_JUSTIFICATION_REQUIRED = 'DISCREPANCY_JUSTIFICATION_REQUIRED';
    public const REFUND_AMOUNT_MISMATCH = 'REFUND_AMOUNT_MISMATCH';

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
    // 403 · rol y perímetro territorial (C2, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────

    public static function cashCollectionForbiddenForRole(string $role): self
    {
        return new self(
            self::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
            sprintf('El rol «%s» no está autorizado a operar sobre la recaudación de efectivo (Art. V.4).', $role),
            403,
            'actor',
            ['role' => $role]
        );
    }

    public static function machineOutsideRestockerZones(int $machineId, int $restockerId): self
    {
        return new self(
            self::MACHINE_OUTSIDE_RESTOCKER_ZONES,
            sprintf('La máquina %d no pertenece a las zonas operativas cubiertas hoy por el reponedor %d (C2).', $machineId, $restockerId),
            403,
            'machine_id',
            ['machine_id' => $machineId, 'restocker_id' => $restockerId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 404 · máquina o recaudación inexistente
    // ─────────────────────────────────────────────────────────────────────

    public static function machineNotFound(int $machineId): self
    {
        return new self(
            self::MACHINE_NOT_FOUND,
            sprintf('La máquina %d no existe o está inactiva.', $machineId),
            404,
            'machine_id',
            ['machine_id' => $machineId]
        );
    }

    public static function cashCollectionNotFound(int $collectionId): self
    {
        return new self(
            self::CASH_COLLECTION_NOT_FOUND,
            sprintf('La recaudación %d no existe o no tiene el dato solicitado.', $collectionId),
            404,
            'cash_collection_id',
            ['cash_collection_id' => $collectionId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409 · precinto ya consumido, reintegro no elegible y transición inválida
    // ─────────────────────────────────────────────────────────────────────

    public static function bagSealCodeAlreadyUsed(string $bagSealCode): self
    {
        return new self(
            self::BAG_SEAL_CODE_ALREADY_USED,
            sprintf('El precinto «%s» ya fue utilizado en otra recaudación histórica (C1).', $bagSealCode),
            409,
            'bag_seal_code',
            ['bag_seal_code' => $bagSealCode]
        );
    }

    public static function refundRequestNotEligibleForReception(int $refundRequestId, string $reason): self
    {
        return new self(
            self::REFUND_REQUEST_NOT_ELIGIBLE_FOR_RECEPTION,
            sprintf('El reintegro %d no es elegible para entrega en conserjería: %s (D3).', $refundRequestId, $reason),
            409,
            'linked_refund_request_id',
            ['linked_refund_request_id' => $refundRequestId, 'reason' => $reason]
        );
    }

    public static function invalidCashCollectionStateTransition(string $from, string $to): self
    {
        return new self(
            self::INVALID_CASH_COLLECTION_STATE_TRANSITION,
            sprintf('La recaudación no puede pasar de %s a %s en su ciclo de custodia (D4).', $from, $to),
            409,
            'status',
            ['from' => $from, 'to' => $to]
        );
    }

    public static function refundAmountMismatch(float $claimedAmount, float $deliveredAmount): self
    {
        return new self(
            self::REFUND_AMOUNT_MISMATCH,
            sprintf(
                'El efectivo entregado (%.2f €) no coincide con el importe reclamado (%.2f €) del reintegro vinculado (D3).',
                $deliveredAmount,
                $claimedAmount
            ),
            422,
            'reception_cash_delivered_eur',
            ['claimed_amount' => $claimedAmount, 'reception_cash_delivered_eur' => $deliveredAmount]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · precinto (C1)
    // ─────────────────────────────────────────────────────────────────────

    public static function invalidBagSealCode(string $bagSealCode, string $reason): self
    {
        return new self(
            self::INVALID_BAG_SEAL_CODE,
            sprintf('El precinto «%s» no es válido: %s (C1).', $bagSealCode, $reason),
            422,
            'bag_seal_code',
            ['bag_seal_code' => $bagSealCode, 'reason' => $reason]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · contador (C3, D2, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────

    public static function salesCounterRegression(?float $previous, float $current): self
    {
        return new self(
            self::SALES_COUNTER_REGRESSION,
            sprintf(
                'La lectura del contador no puede retroceder de %.2f € a %.2f € sin declarar is_counter_reset = TRUE (C3).',
                $previous ?? 0.00,
                $current
            ),
            422,
            'sales_counter_reading',
            ['previous_sales_counter_reading' => $previous, 'sales_counter_reading' => $current]
        );
    }

    public static function counterResetNotesRequired(?int $length): self
    {
        return new self(
            self::COUNTER_RESET_NOTES_REQUIRED,
            sprintf(
                'Un reseteo de contador exige counter_reset_notes de 20 a 500 caracteres reales con al menos 3 bloques alfanuméricos; se recibieron %s (C3, Art. V.1).',
                $length === null ? 'ninguna nota' : $length . ' caracteres'
            ),
            422,
            'counter_reset_notes',
            ['length' => $length]
        );
    }

    public static function invalidCashAmountBounds(string $field, ?float $value, float $minimum, float $maximum): self
    {
        return new self(
            self::INVALID_CASH_AMOUNT_BOUNDS,
            sprintf(
                'El campo %s debe estar entre %.2f y %.2f €; se recibió %s (D2, C3).',
                $field,
                $minimum,
                $maximum,
                $value === null ? 'NULL' : sprintf('%.2f €', $value)
            ),
            422,
            $field,
            ['field' => $field, 'value' => $value, 'minimum' => $minimum, 'maximum' => $maximum]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 / 409 · entrega en conserjería (D3)
    // ─────────────────────────────────────────────────────────────────────

    public static function receptionCashLimitExceeded(float $amount): self
    {
        return new self(
            self::RECEPTION_CASH_LIMIT_EXCEEDED,
            sprintf('El efectivo entregado en conserjería debe estar entre 0,00 y 10,00 €; se recibió %.2f € (D3).', $amount),
            422,
            'reception_cash_delivered_eur',
            ['reception_cash_delivered_eur' => $amount]
        );
    }

    public static function receptionistNameRequired(float $amount): self
    {
        return new self(
            self::RECEPTIONIST_NAME_REQUIRED,
            sprintf(
                'La entrega de %.2f € en conserjería exige un receptionist_name de 3 a 120 caracteres reales (D3).',
                $amount
            ),
            422,
            'receptionist_name',
            ['reception_cash_delivered_eur' => $amount]
        );
    }

    public static function locationHasNoPhysicalReception(int $machineId): self
    {
        return new self(
            self::LOCATION_HAS_NO_PHYSICAL_RECEPTION,
            sprintf('La sede de la máquina %d no tiene conserjería física y no admite entrega de efectivo (D3).', $machineId),
            409,
            'reception_cash_delivered_eur',
            ['machine_id' => $machineId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409 / 422 · primacía sanitaria (C4, Art. II.2)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $reasons
     */
    public static function sanitaryCashCollectionBlocked(array $reasons, int $machineId): self
    {
        return new self(
            self::SANITARY_CASH_COLLECTION_BLOCKED,
            sprintf(
                'La máquina %d no admite recaudación: %s (C4, Art. II.2).',
                $machineId,
                implode('; ', $reasons)
            ),
            409,
            'machine_id',
            ['machine_id' => $machineId, 'reasons' => $reasons]
        );
    }

    public static function thermalAbortCashCollectionForbidden(int $machineId, ?int $visitId): self
    {
        return new self(
            self::THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN,
            sprintf(
                'La visita %s de la máquina %d está abortada por rotura de cadena de frío y no admite recaudación (C4, Art. II.2).',
                $visitId === null ? '(sin identificar)' : (string)$visitId,
                $machineId
            ),
            422,
            'cash_collection',
            ['machine_id' => $machineId, 'restock_visit_id' => $visitId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 / 409 · arqueo terminal (D2, D4, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────

    public static function discrepancyJustificationRequired(float $discrepancy, ?int $length): self
    {
        return new self(
            self::DISCREPANCY_JUSTIFICATION_REQUIRED,
            sprintf(
                'El descuadre de %.2f € exige discrepancy_justification de 20 a 1000 caracteres reales con al menos 3 bloques alfanuméricos; se recibieron %s (D2, Art. V.1).',
                $discrepancy,
                $length === null ? 'ninguna justificación' : $length . ' caracteres'
            ),
            422,
            'discrepancy_justification',
            ['discrepancy_eur' => $discrepancy, 'length' => $length]
        );
    }

    public static function cashCollectionAlreadyReconciled(string $status): self
    {
        return new self(
            self::CASH_COLLECTION_ALREADY_RECONCILED,
            sprintf('La recaudación ya fue arqueada (%s) y es terminal: una corrección se registra como una recaudación nueva (D4, Art. III.3).', $status),
            409,
            'status',
            ['status' => $status]
        );
    }
}
