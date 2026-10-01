<?php
declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable central base and operational-area configuration.
 */
final readonly class RouteSettings implements JsonSerializable
{
    private float $minLatitude;
    private float $maxLatitude;
    private float $minLongitude;
    private float $maxLongitude;

    public function __construct(
        private string $baseName = 'Base Central VendGuard',
        private string $baseAddress = 'Carrer de la Marina 100, 08018 Barcelona',
        private float $baseLatitude = 41.3935000,
        private float $baseLongitude = 2.1890000,
        private int $operationalRadiusKm = 100,
        float $minLatitude = 27.0,
        float $maxLatitude = 44.5,
        float $minLongitude = -18.5,
        float $maxLongitude = 5.0
    ) {
        $this->minLatitude = $minLatitude;
        $this->maxLatitude = $maxLatitude;
        $this->minLongitude = $minLongitude;
        $this->maxLongitude = $maxLongitude;
        if (trim($baseName) === '' || trim($baseAddress) === '') {
            throw new InvalidArgumentException('El nombre y la dirección de la Base Central son obligatorios.');
        }

        if ($operationalRadiusKm < 1) {
            throw new InvalidArgumentException('El radio operativo debe ser mayor que cero.');
        }

        if (!is_finite($this->minLatitude) || !is_finite($this->maxLatitude)
            || !is_finite($this->minLongitude) || !is_finite($this->maxLongitude)
            || $this->minLatitude < -90.0 || $this->maxLatitude > 90.0 || $this->minLatitude > $this->maxLatitude
            || $this->minLongitude < -180.0 || $this->maxLongitude > 180.0 || $this->minLongitude > $this->maxLongitude) {
            throw new InvalidArgumentException('El marco territorial operativo no es válido.');
        }

        if (!$this->isInsideOperationalArea($baseLatitude, $baseLongitude)) {
            throw new InvalidArgumentException('La Base Central debe encontrarse dentro del territorio operativo.');
        }
    }

    public function getBaseName(): string
    {
        return $this->baseName;
    }

    public function getBaseAddress(): string
    {
        return $this->baseAddress;
    }

    public function getBaseLatitude(): float
    {
        return $this->baseLatitude;
    }

    public function getBaseLongitude(): float
    {
        return $this->baseLongitude;
    }

    public function getOperationalRadiusKm(): int
    {
        return $this->operationalRadiusKm;
    }

    public function getMinLatitude(): float
    {
        return $this->minLatitude;
    }

    public function getMaxLatitude(): float
    {
        return $this->maxLatitude;
    }

    public function getMinLongitude(): float
    {
        return $this->minLongitude;
    }

    public function getMaxLongitude(): float
    {
        return $this->maxLongitude;
    }

    public function isInsideOperationalArea(float $latitude, float $longitude): bool
    {
        return is_finite($latitude)
            && is_finite($longitude)
            && $latitude >= -90.0
            && $latitude <= 90.0
            && $longitude >= -180.0
            && $longitude <= 180.0
            && !($latitude === 0.0 && $longitude === 0.0)
            && $latitude >= $this->minLatitude
            && $latitude <= $this->maxLatitude
            && $longitude >= $this->minLongitude
            && $longitude <= $this->maxLongitude;
    }

    /**
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        return [
            'base_name' => $this->baseName,
            'base_address' => $this->baseAddress,
            'base_latitude' => $this->baseLatitude,
            'base_longitude' => $this->baseLongitude,
            'operational_radius_km' => $this->operationalRadiusKm,
            'min_latitude' => $this->minLatitude,
            'max_latitude' => $this->maxLatitude,
            'min_longitude' => $this->minLongitude,
            'max_longitude' => $this->maxLongitude,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
