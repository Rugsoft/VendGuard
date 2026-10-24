<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidAbsenceException
 *
 * Excepción lanzada cuando una ausencia vulnera las reglas de dominio
 * (rango de fechas invertido, motivo inválido, notas excesivas, cancelación incoherente).
 */
final class InvalidAbsenceException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
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

    public static function invalidDateRange(): self
    {
        return new self(
            'La fecha de finalización debe ser posterior o igual a la de inicio.',
            'INVALID_DATE_RANGE',
            422
        );
    }

    public static function notesTooLong(int $maxLength = 500): self
    {
        return new self(
            "Las notas no pueden superar los {$maxLength} caracteres.",
            'NOTES_TOO_LONG',
            422
        );
    }

    public static function cancellationReasonTooShort(int $minLength = 5): self
    {
        return new self(
            "El motivo de cancelación debe tener al menos {$minLength} caracteres.",
            'CANCELLED_REASON_TOO_SHORT',
            422
        );
    }

    public static function alreadyCancelled(): self
    {
        return new self(
            'La ausencia ya se encuentra cancelada.',
            'ABSENCE_ALREADY_CANCELLED',
            409
        );
    }

    public static function overlap(string $message = 'El técnico ya tiene una ausencia registrada que se solapa con las fechas indicadas.'): self
    {
        return new self(
            $message,
            'ABSENCE_OVERLAP',
            409
        );
    }
}
