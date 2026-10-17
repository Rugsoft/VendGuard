<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * NotificationOutboxItemNotFoundException
 *
 * Excepción de dominio lanzada cuando Coordinación intenta reintentar o
 * cancelar una notificación de la bandeja de salida que no existe o que fue
 * archivada lógicamente (RF-06.4).
 *
 * Mapea directamente al código HTTP 404 Not Found con el error
 * `OUTBOX_ITEM_NOT_FOUND` del catálogo de contratos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class NotificationOutboxItemNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'OUTBOX_ITEM_NOT_FOUND';
    public const DEFAULT_MESSAGE = 'La notificación indicada no existe o fue archivada.';

    public function __construct(
        private readonly ?int $outboxItemId = null,
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

    public function getOutboxItemId(): ?int
    {
        return $this->outboxItemId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['outbox_item_id' => $this->outboxItemId];
    }
}
