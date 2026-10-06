<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidCommentLengthException
 *
 * Excepción de dominio lanzada cuando el texto de un comentario o nota interna
 * no alcanza los 5 caracteres descriptivos mínimos o supera los 1.000 máximos
 * (Módulo 10, T-COM-03 · RF-03.1).
 */
class InvalidCommentLengthException extends DomainException
{
    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        string $errorCode = 'INVALID_COMMENT_LENGTH',
        string $message = 'El mensaje debe contener entre 5 y 1.000 caracteres descriptivos.',
        int $httpStatusCode = 422
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
