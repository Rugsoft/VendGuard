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
 * Responde a las restricciones del Artículo V de la Constitución y a los requisitos RF-02 / RNF-02.
 */
class DuplicateIncidentException extends DomainException
{
    private string $errorCode;
    private ?string $ticketCode;
    private ?string $ticketStatus;

    public function __construct(
        string $errorCode,
        string $message,
        ?string $ticketCode = null,
        ?string $ticketStatus = null
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->ticketCode = $ticketCode;
        $this->ticketStatus = $ticketStatus;
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
}
