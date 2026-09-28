<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SparePartNotFoundException
 * 
 * Excepción de dominio lanzada cuando un repuesto solicitado por ID o código
 * no existe en el catálogo maestro del sistema (RF-REP-01 / RF-REP-02).
 * Mapea a HTTP 404 Not Found.
 */
class SparePartNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;

    private string $errorCode;

    public function __construct(
        string $message = 'El repuesto solicitado no existe en el catálogo.',
        string $errorCode = 'SPARE_PART_NOT_FOUND',
        int $code = self::HTTP_STATUS
    ) {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
