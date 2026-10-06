<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ConversationSealedException
 *
 * Excepción de dominio lanzada cuando se intenta publicar un comentario o nota
 * interna sobre un expediente sellado en modo solo lectura: estado CLOSED o
 * CANCELLED, o RESOLVED con la ventana de garantía de 48 horas vencida
 * (Módulo 10, T-COM-03 · RF-05.3, RF-07.2 / Art. V.6).
 */
class ConversationSealedException extends DomainException
{
    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        string $errorCode = 'CONVERSATION_SEALED',
        string $message = 'El expediente está archivado: la conversación quedó sellada por auditoría y no admite nuevos mensajes.',
        int $httpStatusCode = 403
    ) {
        parent::__construct($message, $httpStatusCode);
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
}
