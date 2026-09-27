<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PerishableFrequencyLimitException
 * 
 * Excepción de Dominio lanzada cuando se intenta configurar una periodicidad superior
 * a 15 días naturales en máquinas dispensadoras de alimentos perecederos (Art. II).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class PerishableFrequencyLimitException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'PERISHABLE_FREQUENCY_LIMIT_EXCEEDED';

    private string $errorCode;
    private int $httpStatusCode;
    private int $attemptedDays;
    private int $maximumAllowedDays;
    private string $machineType;

    public function __construct(
        string $message = 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.',
        int $attemptedDays = 0,
        int $maximumAllowedDays = 15,
        string $machineType = 'PERISHABLE_FOOD',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->attemptedDays = $attemptedDays;
        $this->maximumAllowedDays = $maximumAllowedDays;
        $this->machineType = $machineType;
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

    public function getAttemptedDays(): int
    {
        return $this->attemptedDays;
    }

    public function getMaximumAllowedDays(): int
    {
        return $this->maximumAllowedDays;
    }

    public function getMachineType(): string
    {
        return $this->machineType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'machine_type' => $this->machineType,
            'attempted_days' => $this->attemptedDays,
            'maximum_allowed_days' => $this->maximumAllowedDays,
        ];
    }
}
