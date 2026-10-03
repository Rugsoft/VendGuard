<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\Model\CompensationMethod;

/**
 * ReceptionDeliveryNotAllowedException
 *
 * Excepción de Dominio lanzada cuando el técnico intenta custodiar el efectivo
 * en la conserjería de la sede cuando la compensationmethod es digital o el
 * importe supera el límite presencial de 10,00 € (RF-REF-05).
 *
 * En esos casos la custodia física queda forzada hacia caja central
 * (`HELD_FOR_CENTRAL`).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class ReceptionDeliveryNotAllowedException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'RECEPTION_DELIVERY_NOT_ALLOWED';
    public const DEFAULT_MESSAGE = 'No se permite el depósito en conserjería para importes superiores a 10,00 € o con método de compensación digital.';

    public function __construct(
        private readonly float $claimedAmount,
        private readonly CompensationMethod $compensationMethod,
        private readonly float $receptionLimit = 10.00,
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

    public function getClaimedAmount(): float
    {
        return $this->claimedAmount;
    }

    public function getCompensationMethod(): CompensationMethod
    {
        return $this->compensationMethod;
    }

    public function getReceptionLimit(): float
    {
        return $this->receptionLimit;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'claimed_amount' => $this->claimedAmount,
            'compensation_method' => $this->compensationMethod->value,
            'reception_limit' => $this->receptionLimit,
        ];
    }
}
