<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ShiftAdjustmentValidationException
 *
 * Incumplimiento del contrato de rectificación y cierre forzado de turno
 * (Módulo 22, contratos §2.3).
 *
 * El controlador no decide nada: lee `getErrorCode()` y `getHttpStatusCode()`.
 * Un 422 dice que la petición existe pero la regla no la admite; un 409 dice
 * que colisiona con otro turno ya persistido.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class ShiftAdjustmentValidationException extends DomainException
{
    public const INVALID_ADJUSTMENT_REASON_CODE = 'INVALID_ADJUSTMENT_REASON';
    public const SHIFT_STILL_OPEN_CODE = 'SHIFT_STILL_OPEN';
    public const SHIFT_FUTURE_TIMESTAMP_CODE = 'SHIFT_FUTURE_TIMESTAMP';
    public const SHIFT_INVALID_TIME_ORDER_CODE = 'SHIFT_INVALID_TIME_ORDER';
    public const EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY_CODE = 'EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY';
    public const INVALID_MEAL_BREAK_DURATION_CODE = 'INVALID_MEAL_BREAK_DURATION';
    public const SHIFT_EXCEEDS_MAX_DURATION_CODE = 'SHIFT_EXCEEDS_MAX_DURATION';
    public const SHIFT_OVERLAP_CONFLICT_CODE = 'SHIFT_OVERLAP_CONFLICT';

    public const UNPROCESSABLE_HTTP_STATUS = 422;
    public const CONFLICT_HTTP_STATUS = 409;

    /** Longitud mínima real del motivo de rectificación o cierre forzado. */
    public const MINIMUM_REASON_LENGTH = 20;

    /** Duración máxima de un turno bruto, en segundos (24 horas). */
    public const MAX_SHIFT_DURATION_SECONDS = 86400;

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatusCode
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Motivo ausente o por debajo del mínimo real de 20 caracteres (Art. V.1).
     */
    public static function reasonTooShort(int $minimumLength = self::MINIMUM_REASON_LENGTH): self
    {
        return new self(
            "El motivo de la rectificación o del cierre forzado debe tener al menos {$minimumLength} caracteres reales.",
            self::INVALID_ADJUSTMENT_REASON_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * Solo se rectifica un turno ya cerrado; un turno abierto se corrige con sus
     * propias transiciones.
     */
    public static function shiftStillOpen(): self
    {
        return new self(
            'El turno debe estar cerrado antes de poder rectificarse.',
            self::SHIFT_STILL_OPEN_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * No se admite fechar una rectificación en el futuro.
     */
    public static function futureTimestampNotAllowed(): self
    {
        return new self(
            'La marca temporal de la rectificación no puede estar en el futuro.',
            self::SHIFT_FUTURE_TIMESTAMP_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * El cierre no puede ser anterior al inicio ni la reanudación anterior a la pausa.
     */
    public static function invalidCloseOrder(): self
    {
        return new self(
            'El cierre debe ser posterior al inicio del turno.',
            self::SHIFT_INVALID_TIME_ORDER_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * El cierre efectivo debe ser posterior a la última actividad registrada.
     */
    public static function effectiveCloseBeforeLastActivity(): self
    {
        return new self(
            'El cierre efectivo no puede ser anterior a la última actividad registrada del turno.',
            self::EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * La pausa de comida queda fuera de la ventana admitida [0, 2 h].
     */
    public static function invalidMealBreakDuration(): self
    {
        return new self(
            'La pausa de comida acumulada no puede ser negativa ni superar la duración bruta del turno.',
            self::INVALID_MEAL_BREAK_DURATION_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * La jornada bruta supera el máximo admitido de 16 h.
     */
    public static function exceedsMaxShiftDuration(int $maximumSeconds = self::MAX_SHIFT_DURATION_SECONDS): self
    {
        return new self(
            "La jornada no puede superar los {$maximumSeconds} segundos de duración bruta.",
            self::SHIFT_EXCEEDS_MAX_DURATION_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * La rectificación se solapa con otro turno ya persistido del técnico.
     */
    public static function overlappingShift(int $conflictingShiftId): self
    {
        return new self(
            "La rectificación se solapa con el turno {$conflictingShiftId} del mismo técnico.",
            self::SHIFT_OVERLAP_CONFLICT_CODE,
            self::CONFLICT_HTTP_STATUS
        );
    }
}
