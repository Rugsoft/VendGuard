<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;

/**
 * InvalidNotificationOutboxStatusException
 *
 * Excepción de dominio lanzada cuando una acción de supervisión de Coordinación
 * (reintento o cancelación manual) no es válida para el estado actual de la
 * notificación (RF-06.4):
 *
 *   - Reintento admitido sólo en `FAILED_PERMANENT` o `PENDING_RETRY`
 *     (`INVALID_OUTBOX_STATUS_FOR_RETRY`).
 *   - Cancelación manual admitida sólo en `PENDING`, `PENDING_RETRY` o
 *     `FAILED_PERMANENT` (`INVALID_OUTBOX_STATUS_FOR_CANCEL`).
 *
 * Mapea directamente al código HTTP 409 Conflict con el código de error que
 * declara el contrato de la bandeja de salida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class InvalidNotificationOutboxStatusException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE_RETRY = 'INVALID_OUTBOX_STATUS_FOR_RETRY';
    public const ERROR_CODE_CANCEL = 'INVALID_OUTBOX_STATUS_FOR_CANCEL';

    public function __construct(
        private readonly NotificationStatus $currentStatus,
        private readonly string $attemptedAction,
        private readonly string $errorCode = self::ERROR_CODE_RETRY,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? sprintf(
                'La acción «%s» no es válida para una notificación en estado %s.',
                $attemptedAction,
                $currentStatus->value
            ),
            self::HTTP_STATUS
        );
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getCurrentStatus(): NotificationStatus
    {
        return $this->currentStatus;
    }

    public function getAttemptedAction(): string
    {
        return $this->attemptedAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'current_status' => $this->currentStatus->value,
            'attempted_action' => $this->attemptedAction,
        ];
    }
}
