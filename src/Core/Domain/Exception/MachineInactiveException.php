<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineInactiveException
 *
 * La máquina sobre la que se pretende abrir una Avería Oportunista In Situ está
 * dada de baja definitiva: `is_active = false` **y** `is_blocked_no_access =
 * false` (Módulo 16, RF-01.5).
 *
 * La distinción importa: una máquina bloqueada por falta de acceso continúa en
 * el catálogo —el técnico que ya está en la sede puede resolverla y levantar el
 * bloqueo, RF-06.1—, pero una baja definitiva no se repara: no tiene sentido
 * abrir un parte sobre un aparato retirado del parque, y permitirlo ensuciaría
 * el histórico y las métricas de intervención.
 *
 * Mapea al código HTTP 422 con el código exacto del contrato (`MACHINE_INACTIVE`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class MachineInactiveException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'MACHINE_INACTIVE';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $machineId;

    public function __construct(
        ?int $machineId = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = 'La máquina indicada está dada de baja definitiva y no admite una intervención oportunista in situ.';
        }

        parent::__construct($message, $httpStatusCode);
        $this->machineId = $machineId;
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

    public function getMachineId(): ?int
    {
        return $this->machineId;
    }
}
