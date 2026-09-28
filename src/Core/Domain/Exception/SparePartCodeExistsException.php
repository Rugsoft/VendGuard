<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SparePartCodeExistsException
 * 
 * Excepción de Dominio lanzada cuando se intenta registrar un repuesto con un
 * código de pieza que ya existe en el catálogo maestro (RF-REP-01).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class SparePartCodeExistsException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'SPARE_PART_CODE_EXISTS';

    private string $errorCode;
    private int $httpStatusCode;
    private string $partCode;

    public function __construct(
        string $partCode,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        $msg = $message !== '' 
            ? $message 
            : "Ya existe un repuesto en el catálogo con el código '{$partCode}'.";
            
        parent::__construct($msg, $httpStatusCode);
        $this->partCode = $partCode;
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

    public function getPartCode(): string
    {
        return $this->partCode;
    }
}
