<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineTypeChangeBlockedException
 * 
 * Excepción de Dominio lanzada cuando se intenta modificar la tipología sanitaria de una
 * máquina que tiene averías activas o en periodo de garantía de 48h (Decisión QA 3, Art. II).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class MachineTypeChangeBlockedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'MACHINE_TYPE_CHANGE_BLOCKED';

    private string $errorCode;
    private ?string $ticketCode;
    private ?string $ticketStatus;
    private ?int $machineId;
    private int $httpStatusCode;

    public function __construct(
        string $message = 'No se puede modificar la tipología sanitaria de la máquina mientras tenga una avería activa o en periodo de garantía de 48 horas.',
        ?string $ticketCode = null,
        ?string $ticketStatus = null,
        ?int $machineId = null,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->ticketCode = $ticketCode;
        $this->ticketStatus = $ticketStatus;
        $this->machineId = $machineId;
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getTicketCode(): ?string
    {
        return $this->ticketCode;
    }

    public function getTicketStatus(): ?string
    {
        return $this->ticketStatus;
    }

    public function getMachineId(): ?int
    {
        return $this->machineId;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
