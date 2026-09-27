<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ReinspectionTemperatureExceededException
 * 
 * Excepción de Dominio lanzada cuando la reinspección sanitaria de una máquina en cuarentena
 * arroja una temperatura superior a 4.0 °C, impidiendo el levantamiento del bloqueo (Art. II).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class ReinspectionTemperatureExceededException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'REINSPECTION_TEMPERATURE_TOO_HIGH';

    private string $errorCode;
    private int $httpStatusCode;
    private ?float $measuredTemperature;
    private float $maximumAllowedTemperature;

    public function __construct(
        string $message = 'No es posible levantar la cuarentena: la temperatura medida excede el límite máximo reglamentario de 4.0 °C.',
        ?float $measuredTemperature = null,
        float $maximumAllowedTemperature = 4.0,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->measuredTemperature = $measuredTemperature;
        $this->maximumAllowedTemperature = $maximumAllowedTemperature;
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

    public function getMaximumAllowedTemperature(): float
    {
        return $this->maximumAllowedTemperature;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'measured_temperature' => $this->measuredTemperature,
            'maximum_allowed_temperature' => $this->maximumAllowedTemperature,
        ];
    }
}
