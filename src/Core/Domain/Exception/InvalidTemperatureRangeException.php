<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidTemperatureRangeException
 * 
 * Excepción de Dominio lanzada cuando la temperatura de sonda se encuentra fuera del rango
 * físico admisible de [-5.0 °C, +25.0 °C] o carece del formato decimal requerido (RNF-06).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidTemperatureRangeException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_TEMPERATURE_RANGE';

    private string $errorCode;
    private int $httpStatusCode;
    private ?float $measuredTemperature;
    private float $minTemperature;
    private float $maxTemperature;

    public function __construct(
        string $message = 'La temperatura debe ser un número con un decimal comprendido estrictamente entre -5.0 °C y 25.0 °C.',
        ?float $measuredTemperature = null,
        float $minTemperature = -5.0,
        float $maxTemperature = 25.0,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->measuredTemperature = $measuredTemperature;
        $this->minTemperature = $minTemperature;
        $this->maxTemperature = $maxTemperature;
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

    public function getMeasuredTemperature(): ?float
    {
        return $this->measuredTemperature;
    }

    public function getMinTemperature(): float
    {
        return $this->minTemperature;
    }

    public function getMaxTemperature(): float
    {
        return $this->maxTemperature;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'measured_temperature' => $this->measuredTemperature,
            'min_temperature' => $this->minTemperature,
            'max_temperature' => $this->maxTemperature,
        ];
    }
}
