<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * LocationNotFoundException
 * 
 * Excepción de Dominio lanzada cuando no se localiza una sede por su identificador primario (RF-01).
 * Mapea a HTTP 404 Not Found.
 */
class LocationNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;

    private string $errorCode;
    private ?int $locationId;

    public function __construct(
        int|string|null $locationId = null,
        string $message = 'La sede indicada no existe.',
        string $errorCode = 'LOCATION_NOT_FOUND',
        int $code = self::HTTP_STATUS
    ) {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
        $this->locationId = is_numeric($locationId) ? (int)$locationId : null;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }
}
