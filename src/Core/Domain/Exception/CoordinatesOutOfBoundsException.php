<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * Raised when numeric geographic coordinates fall outside the operational territory.
 */
final class CoordinatesOutOfBoundsException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'COORDINATES_OUT_OF_BOUNDS';

    public function __construct(
        string $message = 'Las coordenadas especificadas se encuentran fuera del territorio geográfico operativo permitido.',
        private readonly ?float $latitude = null,
        private readonly ?float $longitude = null
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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    /**
     * @return array{latitude: float|null, longitude: float|null}
     */
    public function getDetails(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
