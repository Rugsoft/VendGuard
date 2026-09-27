<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SeasonalPauseMissingReasonException
 * 
 * Excepción de Dominio lanzada cuando se intenta activar una Pausa Estacional sin justificar
 * documentalmente el motivo o sin indicar la fecha estimada de reanudación (EARS 1.5).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class SeasonalPauseMissingReasonException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'SEASONAL_PAUSE_REQUIRES_REASON';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $machineId;

    public function __construct(
        string $message = 'Debe justificar documentalmente el motivo de la pausa estacional y la fecha estimada de reanudación.',
        ?int $machineId = null,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->machineId = $machineId;
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

    public function getMachineId(): ?int
    {
        return $this->machineId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'machine_id' => $this->machineId,
        ];
    }
}
