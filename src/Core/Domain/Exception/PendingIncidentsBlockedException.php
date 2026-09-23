<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PendingIncidentsBlockedException
 * 
 * Excepción de Dominio lanzada cuando se intenta dar de baja a un técnico que tiene
 * averías asignadas pendientes en estado ASSIGNED, IN_PROGRESS o PENDING_PARTS (Decisión QA 2).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class PendingIncidentsBlockedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'TECHNICIAN_HAS_PENDING_INCIDENTS';

    private string $errorCode;
    private int $pendingIncidentsCount;
    private ?int $technicianId;
    private int $httpStatusCode;

    public function __construct(
        int $pendingIncidentsCount,
        ?int $technicianId = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = "No se puede dar de baja al técnico porque tiene {$pendingIncidentsCount} averías asignadas pendientes. Reasigne sus averías desde el panel de triaje antes de darlo de baja.";
        }

        parent::__construct($message, $httpStatusCode);
        $this->pendingIncidentsCount = $pendingIncidentsCount;
        $this->technicianId = $technicianId;
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getPendingIncidentsCount(): int
    {
        return $this->pendingIncidentsCount;
    }

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
