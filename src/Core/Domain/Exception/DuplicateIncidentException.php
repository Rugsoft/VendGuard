<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * DuplicateIncidentException
 * 
 * Excepción de Dominio lanzada cuando se intenta crear un aviso activo o duplicado
 * sobre una máquina que ya cuenta con una avería en curso o en periodo de garantía.
 * 
 * Mapea directamente al código HTTP 409 Conflict según RF-02 y Artículo V de la Constitución.
 */
class DuplicateIncidentException extends DomainException
{
    public const HTTP_STATUS = 409;

    private string $errorCode;
    private ?string $ticketCode;
    private ?string $ticketStatus;
    private int $httpStatusCode;

    public function __construct(
        string $errorCode,
        string $message,
        ?string $ticketCode = null,
        ?string $ticketStatus = null,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->ticketCode = $ticketCode;
        $this->ticketStatus = $ticketStatus;
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

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
