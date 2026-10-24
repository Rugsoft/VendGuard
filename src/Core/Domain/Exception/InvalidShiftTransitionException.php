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

    /**
     * No existe un turno abierto sobre el que ejecutar la transición.
     *
     * Se separa de las transiciones inválidas sobre un turno existente para que
     * el cliente distinga «no has fichado» de «ya estás en pausa» (m-2).
     */
    public static function noOpenShift(string $action): self
    {
        return new self(
            "No se puede {$action} porque no hay ningún turno abierto.",
            'NO_OPEN_SHIFT',
            409
        );
    }

    public static function alreadyPaused(): self
    {
        return new self(
            'No se puede pausar para comida porque el turno ya se encuentra en MEAL_BREAK.',
            'SHIFT_ALREADY_PAUSED',
            422
        );
    }

    public static function notPaused(): self
    {
        return new self(
            'No se puede reanudar la jornada porque el turno no se encuentra en MEAL_BREAK.',
            'SHIFT_NOT_IN_MEAL_BREAK',
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
