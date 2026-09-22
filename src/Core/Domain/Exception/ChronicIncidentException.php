<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ChronicIncidentException
 * 
 * Excepción de dominio lanzada cuando una máquina supera el límite permitido de 2
 * reaperturas sucesivas (a la 3ª reincidencia), catalogando el expediente como
 * "Avería Crónica" y bloqueando la reapertura automática (RF-09 / EARS 9.3).
 */
class ChronicIncidentException extends DomainException
{
    private string $errorCode;
    private int $httpStatusCode;
    private ?string $ticketCode;
    private int $reopenCount;

    public function __construct(
        string $errorCode = 'CHRONIC_INCIDENT_LIMIT',
        string $message = 'Esta máquina ha presentado múltiples reincidencias consecutivas y el expediente ha sido catalogado como \'Avería Crónica\'. La reapertura automática desde el portal está bloqueada. Por favor, contacte directamente con el centro de coordinación técnica para una auditoría presencial.',
        ?string $ticketCode = null,
        int $reopenCount = 2,
        int $httpStatusCode = 422
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->ticketCode = $ticketCode;
        $this->reopenCount = $reopenCount;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getTicketCode(): ?string
    {
        return $this->ticketCode;
    }

    public function getReopenCount(): int
    {
        return $this->reopenCount;
    }
}
