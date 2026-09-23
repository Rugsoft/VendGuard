<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * CannotDeactivateSelfException
 * 
 * Excepción de Dominio lanzada cuando un Coordinador intenta dar de baja su propio
 * usuario en sesión activa (Caso Límite 8).
 * 
 * Mapea directamente al código HTTP 403 Forbidden.
 */
class CannotDeactivateSelfException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'CANNOT_DEACTIVATE_SELF';

    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        string $message = 'Acción denegada: no puede desactivar su propio usuario en sesión activa.',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
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
