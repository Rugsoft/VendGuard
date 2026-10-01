<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidRefundAmountException
 *
 * Excepción de Dominio lanzada cuando el importe reclamado queda fuera del
 * rango antifraude admitido: debe ser estrictamente mayor que 0,00 € y no
 * superar el tope bloqueante de 50,00 € por reclamación (RF-REF-03).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidRefundAmountException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_REFUND_AMOUNT';
    public const DEFAULT_MESSAGE = 'El importe reclamado debe ser mayor a 0,00 € y no puede exceder el límite máximo de 50,00 €.';

    public function __construct(
        private readonly float $attemptedAmount,
        private readonly float $maximumAllowed = 50.00,
        string $message = self::DEFAULT_MESSAGE
    ) {
        parent::__construct($message, self::HTTP_STATUS);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getAttemptedAmount(): float
    {
        return $this->attemptedAmount;
    }

    public function getMaximumAllowed(): float
    {
        return $this->maximumAllowed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'attempted_amount' => $this->attemptedAmount,
            'maximum_allowed' => $this->maximumAllowed,
        ];
    }
}
