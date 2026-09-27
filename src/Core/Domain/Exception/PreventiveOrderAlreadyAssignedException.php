<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PreventiveOrderAlreadyAssignedException
 * 
 * Excepción de Dominio lanzada cuando un técnico intenta autoasignarse una orden preventiva
 * (Visita Oportunista) que ya fue asignada o ha cambiado de estado (EARS 2.3).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class PreventiveOrderAlreadyAssignedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'ORDER_ALREADY_ASSIGNED';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $orderId;
    private ?string $currentStatus;
    private ?int $assignedTechnicianId;

    public function __construct(
        string $message = 'La orden preventiva ya se encuentra asignada o ha cambiado de estado.',
        ?int $orderId = null,
        ?string $currentStatus = null,
        ?int $assignedTechnicianId = null,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->orderId = $orderId;
        $this->currentStatus = $currentStatus;
        $this->assignedTechnicianId = $assignedTechnicianId;
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

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getCurrentStatus(): ?string
    {
        return $this->currentStatus;
    }

    public function getAssignedTechnicianId(): ?int
    {
        return $this->assignedTechnicianId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'order_id' => $this->orderId,
            'current_status' => $this->currentStatus,
            'assigned_technician_id' => $this->assignedTechnicianId,
        ];
    }
}
