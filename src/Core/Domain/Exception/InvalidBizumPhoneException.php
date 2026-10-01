<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidBizumPhoneException
 *
 * Excepción de Dominio lanzada cuando el número de teléfono destinado a Bizum
 * no tiene exactamente 9 dígitos numéricos (RF-REF-07).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidBizumPhoneException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_BIZUM_PHONE';
    public const DEFAULT_MESSAGE = 'El número de teléfono para Bizum debe contener exactamente 9 dígitos numéricos.';

    public function __construct(string $message = self::DEFAULT_MESSAGE)
    {
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
}
