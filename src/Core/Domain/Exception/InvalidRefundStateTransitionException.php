<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\Model\RefundStatus;

/**
 * InvalidRefundStateTransitionException
 *
 * Excepción de Dominio lanzada cuando se intenta una transición que el grafo
 * legal del ciclo de vida del expediente de reintegro no permite, por ejemplo
 * pagar un expediente ya rejeté o ya entregado en mano.
 *
 * A diferencia de las demás excepciones 422 del módulo, esta representa un
 * conflicto de estado y no un dato mal formado, por lo que mapea a HTTP 409.
 */
class InvalidRefundStateTransitionException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'INVALID_REFUND_STATE_TRANSITION';
    public const DEFAULT_MESSAGE = 'La acción solicitada no es válida para el estado actual del expediente.';

    public function __construct(
        private readonly ?RefundStatus $fromStatus = null,
        private readonly ?RefundStatus $toStatus = null,
        private readonly ?string $attemptedAction = null,
        string $message = self::DEFAULT_MESSAGE
    ) {
        parent::__construct($message, self::HTTP_STATUS);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getFromStatus(): ?RefundStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?RefundStatus
    {
        return $this->toStatus;
    }

    public function getAttemptedAction(): ?string
    {
        return $this->attemptedAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'from_status' => $this->fromStatus?->value,
            'to_status' => $this->toStatus?->value,
            'attempted_action' => $this->attemptedAction,
        ];
    }
}
