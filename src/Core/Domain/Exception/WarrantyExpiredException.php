<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * WarrantyExpiredException
 * 
 * Excepción de dominio lanzada cuando se intenta reabrir una incidencia
 * habiendo transcurrido más de 48 horas desde su resolución (RF-09 / EARS 9.2).
 */
class WarrantyExpiredException extends DomainException
{
    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        string $errorCode = 'REOPEN_WINDOW_EXPIRED',
        string $message = 'Han transcurrido más de 48 horas desde la resolución de la incidencia. La ventana de garantía ha expirado; debe registrar un nuevo ticket de avería.',
        int $httpStatusCode = 422
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
