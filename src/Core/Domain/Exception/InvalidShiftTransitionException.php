<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidShiftTransitionException
 *
 * Excepción lanzada cuando una transición de estado de turno no está permitida
 * por la máquina de estados de jornada.
 */
final class InvalidShiftTransitionException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'INVALID_SHIFT_TRANSITION',
        private readonly int $httpStatusCode = 422
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public static function notOnShift(string $action): self
    {
        return new self(
            "No se puede {$action} porque el turno no se encuentra en estado ON_SHIFT.",
            'INVALID_SHIFT_TRANSITION',
            422
        );
    }

    public static function notInMealBreak(): self
    {
        return new self(
            'No se puede reanudar la jornada porque el turno no se encuentra en MEAL_BREAK.',
            'INVALID_SHIFT_TRANSITION',
            422
        );
    }

    public static function alreadyClosed(): self
    {
        return new self(
            'El turno ya se encuentra cerrado (OFF_SHIFT).',
            'INVALID_SHIFT_TRANSITION',
            422
        );
    }
}
