<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SanitarySpoilageValidationException
 *
 * Única excepción de negocio del Módulo 29. Transporta el código exacto que
 * espera el contrato REST y el estado HTTP con el que el controlador debe
 * responder, de modo que la capa de presentación no reinvente la traducción de
 * cada regla.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio de aplicación, se elige llamando al constructor que
 * describe el fallo. Así un error nuevo no puede nacer con un código huérfano.
 *
 * Códigos por estado HTTP:
 *   - `422`: temperatura, techo sanitario de reapertura, unidades, confirmación
 *     de máquina vacía, lotes, método de destrucción, fotografía, observaciones,
 *     ajuste financiero y rango de fechas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class SanitarySpoilageValidationException extends DomainException
{
    // ── 422 · temperaturas ─────────────────────────────────────────────────
    public const INVALID_TEMPERATURE_RANGE = 'INVALID_TEMPERATURE_RANGE';
    public const RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT = 'RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT';

    // ── 422 · unidades y ramas del acta ────────────────────────────────────
    public const INVALID_DISCARDED_UNITS = 'INVALID_DISCARDED_UNITS';
    public const EMPTY_MACHINE_CONFIRMATION_REQUIRED = 'EMPTY_MACHINE_CONFIRMATION_REQUIRED';
    public const INVALID_BATCH_CODES = 'INVALID_BATCH_CODES';
    public const INVALID_DISPOSAL_METHOD = 'INVALID_DISPOSAL_METHOD';

    // ── 422 · evidencia y observaciones ────────────────────────────────────
    public const EVIDENCE_PHOTO_REQUIRED = 'EVIDENCE_PHOTO_REQUIRED';
    public const INVALID_SANITARY_NOTES = 'INVALID_SANITARY_NOTES';

    // ── 422 · ajuste financiero de Coordinación ─────────────────────────────
    public const EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED = 'EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED';
    public const INVALID_LOSS_ESTIMATE_AMOUNT = 'INVALID_LOSS_ESTIMATE_AMOUNT';
    public const IDENTICAL_LOSS_ESTIMATE = 'IDENTICAL_LOSS_ESTIMATE';
    public const INVALID_LOSS_ADJUSTMENT_REASON = 'INVALID_LOSS_ADJUSTMENT_REASON';

    // ── 422 · filtros de consulta ──────────────────────────────────────────
    public const INVALID_DATE_RANGE = 'INVALID_DATE_RANGE';

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
    // 422 · temperaturas
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidTemperatureRange(
        string $field,
        mixed $value,
        float $min,
        float $max
    ): self {
        return new self(
            self::INVALID_TEMPERATURE_RANGE,
            sprintf(
                'La lectura %s debe ser un número dentro de [%.1f, %.1f] °C; se recibió «%s».',
                $field,
                $min,
                $max,
                self::stringify($value)
            ),
            422,
            $field,
            ['value' => $value, 'min' => $min, 'max' => $max]
        );
    }

    public static function restoredTemperatureExceedsSafeLimit(
        string $machineType,
        float $temperature,
        float $safeLimit
    ): self {
        return new self(
            self::RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT,
            sprintf(
                'La temperatura estabilizada de reapertura (%.1f °C) supera el techo sanitario de %.1f °C '
                . 'para la tipología %s; no se puede levantar la cuarentena (Art. II, QA 3).',
                $temperature,
                $safeLimit,
                $machineType
            ),
            422,
            'restored_stabilized_temp_c',
            [
                'machine_type' => $machineType,
                'temperature' => $temperature,
                'safe_limit' => $safeLimit,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · unidades y ramas del acta
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidDiscardedUnits(mixed $value): self
    {
        return new self(
            self::INVALID_DISCARDED_UNITS,
            sprintf(
                'Las unidades destruidas deben ser un entero entre 0 y 500; se recibió «%s».',
                self::stringify($value)
            ),
            422,
            'discarded_units_count',
            ['value' => $value]
        );
    }

    public static function emptyMachineConfirmationRequired(): self
    {
        return new self(
            self::EMPTY_MACHINE_CONFIRMATION_REQUIRED,
            'Un acta con cero unidades destruidas exige confirmar expresamente que la máquina estaba vacía '
            . 'y sin stock expuesto (D3).',
            422,
            'empty_machine_confirmed'
        );
    }

    public static function invalidBatchCodes(string $reason): self
    {
        return new self(
            self::INVALID_BATCH_CODES,
            'Los códigos de lote del acta no cumplen el contrato: ' . $reason,
            422,
            'affected_batch_codes'
        );
    }

    public static function invalidDisposalMethod(mixed $value): self
    {
        return new self(
            self::INVALID_DISPOSAL_METHOD,
            sprintf(
                'El método de destrucción «%s» no es válido cuando hay producto destruido; '
                . 'se admite ON_SITE_ORGANIC_SEALED, RETURN_TO_CENTRAL_BIOHAZARD o AUTHORIZED_WASTE_MANAGER.',
                self::stringify($value)
            ),
            422,
            'disposal_method',
            ['value' => $value]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · evidencia y observaciones
    // ─────────────────────────────────────────────────────────────────────
    public static function evidencePhotoRequired(string $reason): self
    {
        return new self(
            self::EVIDENCE_PHOTO_REQUIRED,
            'La fotografía de evidencia no cumple el contrato: ' . $reason,
            422,
            'evidence_photo_url'
        );
    }

    public static function invalidSanitaryNotes(string $reason): self
    {
        return new self(
            self::INVALID_SANITARY_NOTES,
            'Las observaciones sanitarias del acta no cumplen el contrato: ' . $reason,
            422,
            'sanitary_notes'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · ajuste financiero de Coordinación
    // ─────────────────────────────────────────────────────────────────────
    public static function emptyMachineLossCannotBeAdjusted(): self
    {
        return new self(
            self::EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED,
            'Un acta de máquina vacía no tiene producto destruido y su coste no puede ajustarse (C5).',
            422,
            'estimated_merchandise_loss_eur'
        );
    }

    public static function invalidLossEstimateAmount(mixed $value): self
    {
        return new self(
            self::INVALID_LOSS_ESTIMATE_AMOUNT,
            sprintf(
                'El coste estimado debe ser un importe numérico mayor que 0,00 € y no superior a 99.999,99 €; '
                . 'se recibió «%s» (C5).',
                self::stringify($value)
            ),
            422,
            'estimated_merchandise_loss_eur',
            ['value' => $value]
        );
    }

    public static function identicalLossEstimate(float $amount): self
    {
        return new self(
            self::IDENTICAL_LOSS_ESTIMATE,
            sprintf(
                'El nuevo coste estimado (%.2f €) es idéntico al vigente; un ajuste debe cambiar el importe (QA 4).',
                $amount
            ),
            422,
            'estimated_merchandise_loss_eur',
            ['amount' => $amount]
        );
    }

    public static function invalidLossAdjustmentReason(string $reason): self
    {
        return new self(
            self::INVALID_LOSS_ADJUSTMENT_REASON,
            'El motivo del ajuste de coste no cumple el contrato: ' . $reason,
            422,
            'loss_adjustment_reason'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · filtros de consulta
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidDateRange(string $from, string $to, ?string $detail = null): self
    {
        return new self(
            self::INVALID_DATE_RANGE,
            $detail ?? sprintf(
                'El rango de fechas no es válido: «%s» es posterior a «%s» (QA 4).',
                $from,
                $to
            ),
            422,
            'date_from',
            ['date_from' => $from, 'date_to' => $to]
        );
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if ($value === null) {
            return 'null';
        }

        return get_debug_type($value);
    }
}
