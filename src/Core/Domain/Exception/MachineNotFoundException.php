<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineNotFoundException
 * 
 * Excepción de Dominio lanzada cuando se intenta escanear o acceder a una máquina
 * que no existe en el sistema o se encuentra inactiva/dada de baja (RF-05, EARS 5.2).
 * 
 * Mapea al código HTTP 404 Not Found con mensaje de cortesía institucional.
 */
class MachineNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;

    private string $errorCode;

    public function __construct(
        string $errorCode = 'MACHINE_NOT_FOUND_OR_INACTIVE',
        string $message = 'Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia, contacta con el servicio técnico.',
        int $code = self::HTTP_STATUS
    ) {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
