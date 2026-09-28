<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidOutOfCatalogJustificationException
 * 
 * Excepción de Dominio lanzada cuando la justificación técnica para una pieza
 * fuera de catálogo no alcanza el mínimo de 20 caracteres reglamentarios (RF-REP-04).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidOutOfCatalogJustificationException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_OUT_OF_CATALOG_JUSTIFICATION';
    public const MINIMUM_LENGTH = 20;

    private string $errorCode;
    private int $httpStatusCode;
    private int $attemptedLength;
    private int $minimumLength;

    public function __construct(
        int $attemptedLength,
        int $minimumLength = self::MINIMUM_LENGTH,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        $msg = $message !== '' 
            ? $message 
            : "La justificación técnica de pieza fuera de catálogo debe tener al menos {$minimumLength} caracteres (longitud actual: {$attemptedLength}).";

        parent::__construct($msg, $httpStatusCode);
        $this->attemptedLength = $attemptedLength;
        $this->minimumLength = $minimumLength;
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

    public function getAttemptedLength(): int
    {
        return $this->attemptedLength;
    }

    public function getMinimumLength(): int
    {
        return $this->minimumLength;
    }
}
