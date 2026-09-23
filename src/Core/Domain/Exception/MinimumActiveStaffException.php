<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MinimumActiveStaffException
 * 
 * Excepción de Dominio lanzada cuando la desactivación de un usuario vulneraría
 * la regla de guardia mínima operativa (al menos 1 técnico y 1 coordinador activos, Caso Límite 9).
 * 
 * Mapea directamente al código HTTP 409 Conflict.
 */
class MinimumActiveStaffException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'MINIMUM_ACTIVE_STAFF_BREACH';

    private string $errorCode;
    private string $role;
    private int $httpStatusCode;

    public function __construct(
        string $message = 'No se puede dar de baja al usuario porque debe existir al menos un coordinador y un técnico activo en la plataforma.',
        string $role = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->role = $role;
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
