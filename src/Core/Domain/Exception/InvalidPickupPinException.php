<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidPickupPinException
 *
 * Excepción de Dominio lanzada cuando el PIN de 4 dígitos introducido por el
 * conserje no coincide con el secreto del expediente de reintegro.
 *
 * El PIN nunca se compara ni se registra en el mensaje de esta excepción: sólo
 * se anota que la verificación falló, para no filtrar el secreto por los logs
 * de auditoría (Art. V.4).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidPickupPinException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_PICKUP_PIN';
    public const DEFAULT_MESSAGE = 'El PIN de recogida introducido no coincide con el expediente de reintegro.';

    public function __construct(string $message = self::DEFAULT_MESSAGE)
    {
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
}
