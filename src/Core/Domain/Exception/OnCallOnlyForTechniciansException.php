<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * OnCallOnlyForTechniciansException
 *
 * Excepción de dominio lanzada cuando Coordinación intenta marcar como
 * «Técnico de guardia» a un usuario que no es Técnico de Campo (RF-02.5): la
 * guardia es una condición operativa de la ruta técnica, no un atributo de
 * coordinación.
 *
 * Mapea directamente al código HTTP 422 Unprocessable Content con el error
 * `ON_CALL_ONLY_FOR_TECHNICIANS` del contrato de la bandeja de salida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class OnCallOnlyForTechniciansException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'ON_CALL_ONLY_FOR_TECHNICIANS';

    public function __construct(
        private readonly int $userId,
        private readonly string $userRole,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? sprintf(
                'La marca de guardia solo puede aplicarse a Técnicos de Campo; el usuario #%d tiene el rol %s.',
                $userId,
                $userRole
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

    public function getUserRole(): string
    {
        return $this->userRole;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->userRole,
        ];
    }
}
