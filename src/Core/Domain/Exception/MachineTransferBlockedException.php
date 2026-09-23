<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineTransferBlockedException
 * 
 * Excepción de Dominio lanzada cuando se intenta trasladar o dar de baja una máquina
 * que tiene incidencias activas o en periodo de garantía de 48h (Decisión QA 1, Art. V.6).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class MachineTransferBlockedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'MACHINE_TRANSFER_BLOCKED';

    private string $errorCode;
    private ?string $ticketCode;
    private ?string $ticketStatus;
    private ?int $machineId;
    private int $httpStatusCode;

    public function __construct(
        string $message,
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
