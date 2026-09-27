<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PreventiveOrderNotInInspectionException
 * 
 * Excepción de Dominio lanzada cuando se intenta completar una orden preventiva que no ha sido
 * iniciada formalmente (no se encuentra en estado 'IN_INSPECTION').
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class PreventiveOrderNotInInspectionException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'ORDER_NOT_IN_INSPECTION';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $orderId;
    private ?string $currentStatus;

    public function __construct(
        string $message = 'La orden preventiva debe ser iniciada previamente (\'EN_INSPECCION\') para remitir el checklist.',
        ?int $orderId = null,
        ?string $currentStatus = null,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->orderId = $orderId;
        $this->currentStatus = $currentStatus;
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

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'order_id' => $this->orderId,
            'current_status' => $this->currentStatus,
        ];
    }
}
