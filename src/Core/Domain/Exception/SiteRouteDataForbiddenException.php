<?php
declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * Raised when a location manager attempts to access route or map data.
 */
final class SiteRouteDataForbiddenException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'FORBIDDEN';

    public function __construct(
        private readonly string $attemptedRole = 'LOCATION_MANAGER',
        private readonly ?string $requestedResource = null,
        string $message = 'Por imperativo del Artículo V.4 de la Constitución, los responsables de sede no tienen acceso a mapas de rutas, ubicaciones de otros centros ni destinos de técnicos.'
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

    public function getAttemptedRole(): string
    {
        return $this->attemptedRole;
    }

    public function getRequestedResource(): ?string
    {
        return $this->requestedResource;
    }
}
