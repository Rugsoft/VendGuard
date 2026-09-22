<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

/**
 * InvalidTransitionException
 * 
 * Excepción de Dominio lanzada cuando se intenta realizar un cambio de estado
 * no permitido por el grafo legal del ciclo de vida de la incidencia.
 */
class InvalidTransitionException extends DomainException
{
    private ?IncidentStatus $fromStatus;
    private ?IncidentStatus $toStatus;

    public function __construct(
        string $message,
        ?IncidentStatus $fromStatus = null,
        ?IncidentStatus $toStatus = null
    ) {
        parent::__construct($message);
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
    }

    public function getFromStatus(): ?IncidentStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?IncidentStatus
    {
        return $this->toStatus;
    }
}
