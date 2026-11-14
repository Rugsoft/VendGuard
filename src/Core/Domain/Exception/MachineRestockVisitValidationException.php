<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineRestockVisitValidationException
 *
 * Única excepción de negocio de la visita de reposición in situ y de su
 * desglose (Módulo 34). Transporta el código exacto del contrato REST y el estado
 * HTTP con el que la capa de presentación debe responder, de modo que el
 * controlador no reinvente la traducción de cada regla del Triple Cerrojo.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio, se elige llamando al constructor que describe el fallo.
 *
 * Códigos por estado HTTP:
 *   - `409`: `SANITARY_RESTOCK_BLOCKED` (máquina en cuarentena, fuera de servicio
 *     o con avería térmica abierta; Art. II).
 *   - `422`: temperatura de cabina, techo sanitario, precinto, FEFO de carril y de
 *     furgoneta, saldo de lote, cotas del carril, checklist de higiene, evidencia
 *     fotográfica y longitud de la justificación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineRestockVisitValidationException extends DomainException
{
    // ── 409 · cerrojo 1 ────────────────────────────────────────────────────
    public const SANITARY_RESTOCK_BLOCKED = 'SANITARY_RESTOCK_BLOCKED';

    // ── 422 · cerrojo 2, cerrojo 3 y evidencia ─────────────────────────────
    public const INVALID_CABIN_TEMPERATURE = 'INVALID_CABIN_TEMPERATURE';
    public const CABIN_TEMP_EXCEEDS_SANITARY_LIMIT = 'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT';
    public const PRECAUTIONARY_SEAL_REQUIRED = 'PRECAUTIONARY_SEAL_REQUIRED';
    public const FEFO_WITHDRAWAL_REQUIRED = 'FEFO_WITHDRAWAL_REQUIRED';
    public const EXPIRED_VAN_BATCH_FORBIDDEN = 'EXPIRED_VAN_BATCH_FORBIDDEN';
    public const FEFO_OLDER_VAN_BATCH_AVAILABLE = 'FEFO_OLDER_VAN_BATCH_AVAILABLE';
    public const INSUFFICIENT_VAN_BATCH_STOCK = 'INSUFFICIENT_VAN_BATCH_STOCK';
    public const SLOT_CAPACITY_OR_WITHDRAWAL_INVALID = 'SLOT_CAPACITY_OR_WITHDRAWAL_INVALID';
    public const INCOMPLETE_DAILY_HYGIENE_CHECKLIST = 'INCOMPLETE_DAILY_HYGIENE_CHECKLIST';
    public const PHOTO_EVIDENCE_REQUIRED = 'PHOTO_EVIDENCE_REQUIRED';
    public const INVALID_JUSTIFICATION_LENGTH = 'INVALID_JUSTIFICATION_LENGTH';

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
    // 409 · cerrojo 1
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $reasons Motivos legibles del bloqueo (cuarentena, fuera
     *                              de servicio, avería térmica abierta).
     */
    public static function sanitaryRestockBlocked(array $reasons, int $machineId): self
    {
        return new self(
            self::SANITARY_RESTOCK_BLOCKED,
            sprintf(
                'La máquina %d no admite reposición in situ: %s (RF-02.1, Art. II).',
                $machineId,
                implode('; ', $reasons)
            ),
            409,
            'machine_id',
            ['machine_id' => $machineId, 'reasons' => $reasons]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · cerrojo 2 · temperatura y precinto
    // ─────────────────────────────────────────────────────────────────────

    public static function invalidCabinTemperature(
        ?float $temperature,
        string $machineType,
        string $reason
    ): self {
        return new self(
            self::INVALID_CABIN_TEMPERATURE,
            sprintf(
                'La lectura de cabina de una máquina %s no es válida: %s; se recibió %s (QA 4, Art. II).',
                $machineType,
                $reason,
                $temperature === null ? 'ninguna lectura' : sprintf('%.1f °C', $temperature)
            ),
            422,
            'cabin_temp_celsius',
            ['machine_type' => $machineType, 'temperature' => $temperature, 'reason' => $reason]
        );
    }

    public static function cabinTempExceedsSanitaryLimit(
        float $temperature,
        string $machineType,
        float $ceiling
    ): self {
        return new self(
            self::CABIN_TEMP_EXCEEDS_SANITARY_LIMIT,
            sprintf(
                'La cabina de la máquina %s registra %.1f °C y supera el techo sanitario de %.1f °C; la visita debe tramitarse como ABORTED_THERMAL_BREACH (RF-02.2, Art. II).',
                $machineType,
                $temperature,
                $ceiling
            ),
            422,
            'cabin_temp_celsius',
            ['machine_type' => $machineType, 'temperature' => $temperature, 'ceiling' => $ceiling]
        );
    }

    public static function precautionarySealRequired(): self
    {
        return new self(
            self::PRECAUTIONARY_SEAL_REQUIRED,
            'Un aborto por rotura de cadena de frío exige marcar precautionary_seal_applied = TRUE antes de cerrar la visita (RF-02.2, Art. II).',
            422,
            'precautionary_seal_applied',
            []
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · cerrojo 3 · FEFO de carril
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     */
    public static function fefoWithdrawalRequired(string $slotCode, string $reason, array $context = []): self
    {
        return new self(
            self::FEFO_WITHDRAWAL_REQUIRED,
            sprintf('El carril %s exige la retirada FEFO antes de continuar: %s (QA 1, Art. II).', $slotCode, $reason),
            422,
            'slot_code',
            ['slot_code' => $slotCode, 'reason' => $reason] + $context
        );
    }

    public static function slotCapacityOrWithdrawalInvalid(
        string $slotCode,
        string $reason,
        int $qtyBefore,
        int $qtyAfter,
        int $maxCapacity
    ): self {
        return new self(
            self::SLOT_CAPACITY_OR_WITHDRAWAL_INVALID,
            sprintf(
                'El movimiento del carril %s no cumple las cotas del C1: %s (antes %d, después %d, capacidad %d).',
                $slotCode,
                $reason,
                $qtyBefore,
                $qtyAfter,
                $maxCapacity
            ),
            422,
            'slot_code',
            [
                'slot_code' => $slotCode,
                'reason' => $reason,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'max_capacity' => $maxCapacity,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · cerrojo 3 · FEFO de furgoneta
    // ─────────────────────────────────────────────────────────────────────

    public static function expiredVanBatchForbidden(string $batchCode, string $expirationDate): self
    {
        return new self(
            self::EXPIRED_VAN_BATCH_FORBIDDEN,
            sprintf(
                'El lote %s de la furgoneta caducó el %s y no puede cargarse en ninguna máquina (QA 1, Art. II).',
                $batchCode,
                $expirationDate
            ),
            422,
            'loaded_batch_code',
            ['batch_code' => $batchCode, 'expiration_date' => $expirationDate]
        );
    }

    public static function fefoOlderVanBatchAvailable(
        string $olderBatchCode,
        string $olderExpirationDate,
        string $selectedBatchCode
    ): self {
        return new self(
            self::FEFO_OLDER_VAN_BATCH_AVAILABLE,
            sprintf(
                'El lote %s de la furgoneta vence el %s, antes que el lote seleccionado %s, y aún tiene saldo: debe agotarse primero (QA 1, Art. II).',
                $olderBatchCode,
                $olderExpirationDate,
                $selectedBatchCode
            ),
            422,
            'loaded_batch_code',
            [
                'older_batch_code' => $olderBatchCode,
                'older_expiration_date' => $olderExpirationDate,
                'selected_batch_code' => $selectedBatchCode,
            ]
        );
    }

    public static function insufficientVanBatchStock(string $batchCode, int $available, int $required): self
    {
        return new self(
            self::INSUFFICIENT_VAN_BATCH_STOCK,
            sprintf(
                'El lote %s de la furgoneta tiene %d unidades y la visita intenta cargar %d (QA 1).',
                $batchCode,
                $available,
                $required
            ),
            422,
            'loaded_batch_code',
            ['batch_code' => $batchCode, 'available' => $available, 'required' => $required]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · checklist, evidencia y justificación
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $missingItems
     */
    public static function incompleteDailyHygieneChecklist(
        array $missingItems,
        string $machineType
    ): self {
        return new self(
            self::INCOMPLETE_DAILY_HYGIENE_CHECKLIST,
            sprintf(
                'El checklist diario de higiene de una máquina %s está incompleto: falta marcar %s (EARS 03.1, D3).',
                $machineType,
                implode(', ', $missingItems)
            ),
            422,
            'hygiene_checklist_json',
            ['machine_type' => $machineType, 'missing_items' => $missingItems]
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function photoEvidenceRequired(string $reason, array $context = []): self
    {
        return new self(
            self::PHOTO_EVIDENCE_REQUIRED,
            'La visita exige una fotografía de evidencia válida: ' . $reason . ' (EARS 03.2, Art. V.5).',
            422,
            'photo_evidence_url',
            $context
        );
    }

    public static function invalidJustificationLength(
        string $field,
        int $length,
        int $minimum,
        int $maximum
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
}
