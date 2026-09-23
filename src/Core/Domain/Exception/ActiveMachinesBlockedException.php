<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ActiveMachinesBlockedException
 * 
 * Excepción de Dominio lanzada cuando se intenta dar de baja una Sede que cuenta
 * con máquinas operativas activas asociadas (RF-01, EARS 1.4).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class ActiveMachinesBlockedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'LOCATION_HAS_ACTIVE_MACHINES';

    private string $errorCode;
    private int $activeMachinesCount;
    private ?int $locationId;
    private int $httpStatusCode;

    public function __construct(
        int $activeMachinesCount,
        ?int $locationId = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = "No se puede dar de baja la sede porque tiene {$activeMachinesCount} máquinas activas asociadas. Reubique o dé de baja las máquinas antes de desactivar la sede.";
        }

        parent::__construct($message, $httpStatusCode);
        $this->activeMachinesCount = $activeMachinesCount;
        $this->locationId = $locationId;
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getActiveMachinesCount(): int
    {
        return $this->activeMachinesCount;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getActiveMachines(): array
    {
        return [];
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
