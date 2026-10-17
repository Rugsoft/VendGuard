<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * CannotSetOnCallInactiveUserException
 *
 * Excepción de dominio lanzada cuando Coordinación intenta poner en guardia a
 * un usuario inactivo (RF-02.5, RF-02.6): un aviso crítico no puede dirigirse a
 * una cuenta dada de baja, porque nadie lo atendería y el blindaje sanitario
 * quedaría confiado a un buzón muerto. La restricción de base de datos
 * `chk_users_on_call_active_tech` impone la misma regla en el motor.
 *
 * Mapea directamente al código HTTP 409 Conflict con el error
 * `CANNOT_SET_ON_CALL_INACTIVE_USER` del contrato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class CannotSetOnCallInactiveUserException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'CANNOT_SET_ON_CALL_INACTIVE_USER';

    public function __construct(
        private readonly int $userId,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? sprintf(
                'No se puede activar la guardia del usuario #%d porque su cuenta está dada de baja.',
                $userId
            ),
            self::HTTP_STATUS
        );
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['user_id' => $this->userId];
    }
}
