<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * RoleChangeForbiddenException
 *
 * Excepción de dominio lanzada cuando Coordinación intenta cambiar el `role`
 * de un usuario ya creado desde el CRUD de Personal Interno (Módulo 31, `C1`,
 * EARS 01.2).
 *
 * El rol es la clave del cuadrante de carga, del filtro de ausencias y turnos y
 * de las métricas por rol: reescribirlo en una ficha existente movería
 * retroactivamente el histórico de un operario de un grupo a otro y rompería la
 * trazabilidad del Art. III.3. La vía legítima es desactivar la cuenta y crear
 * una ficha nueva con el rol correcto.
 *
 * Mapea directamente al código HTTP 409 Conflict con el error
 * `ROLE_CHANGE_FORBIDDEN` del contrato del módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class RoleChangeForbiddenException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'ROLE_CHANGE_FORBIDDEN';

    public function __construct(
        private readonly int $userId,
        private readonly string $currentRole,
        private readonly string $requestedRole,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? sprintf(
                'El rol del usuario #%d es inmutable: se solicitó %s sobre una cuenta %s. '
                . 'Desactive la cuenta y cree una ficha nueva con el rol correcto.',
                $userId,
                $requestedRole,
                $currentRole
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

    public function getCurrentRole(): string
    {
        return $this->currentRole;
    }

    public function getRequestedRole(): string
    {
        return $this->requestedRole;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'user_id' => $this->userId,
            'current_role' => $this->currentRole,
            'requested_role' => $this->requestedRole,
        ];
    }
}
