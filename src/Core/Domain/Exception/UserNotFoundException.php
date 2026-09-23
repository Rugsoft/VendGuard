<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * UserNotFoundException
 * 
 * Excepción de Dominio lanzada cuando se intenta acceder, modificar o resetear
 * un usuario interno que no existe en el catálogo maestro.
 * 
 * Mapea directamente al código HTTP 404 Not Found.
 */
class UserNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'USER_NOT_FOUND';

    private string $errorCode;
    private ?int $userId;

    public function __construct(
        int $userId,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = "El usuario interno con ID {$userId} no existe en el catálogo maestro.";
        }
        parent::__construct($message, $httpStatusCode);
        $this->userId = $userId;
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getHttpStatusCode(): int
    {
        return $this->getCode();
    }
}
