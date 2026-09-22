<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidUploadException
 * 
 * Excepción lanzada cuando un archivo adjunto no cumple los requisitos de seguridad
 * definidos en RNF-05 y el Artículo V de la Constitución (tamaño > 5MB o formato no seguro).
 */
class InvalidUploadException extends DomainException
{
    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(string $errorCode, string $message, int $httpStatusCode = 422)
    {
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
