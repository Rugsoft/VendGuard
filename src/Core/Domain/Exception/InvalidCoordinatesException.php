<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * Raised when required geographic coordinates are missing or not numeric.
 */
final class InvalidCoordinatesException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_COORDINATES';

    /**
     * @param string|null $coordinate Coordinate field associated with the validation error.
     */
    public function __construct(
        string $message = 'Las coordenadas geográficas (latitud y longitud) son obligatorias y deben ser numéricas.',
        private readonly ?string $coordinate = null
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

    public function getCoordinate(): ?string
    {
        return $this->coordinate;
    }

    /**
     * @return array<string, string|null>
     */
    public function getDetails(): array
    {
        return ['coordinate' => $this->coordinate];
    }
}
