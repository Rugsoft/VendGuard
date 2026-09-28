<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * IncompatibleSparePartException
 * 
 * Excepción de Dominio lanzada cuando se intenta asociar, solicitar o instalar
 * un repuesto que no es compatible con el modelo específico de la máquina (RF-REP-01 / RF-REP-03).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class IncompatibleSparePartException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INCOMPATIBLE_SPARE_PART';

    private string $errorCode;
    private int $httpStatusCode;
    private int $sparePartId;
    private string $partCode;
    private string $machineModel;

    public function __construct(
        int $sparePartId,
        string $partCode,
        string $machineModel,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        $msg = $message !== '' 
            ? $message 
            : "El repuesto con código '{$partCode}' (ID {$sparePartId}) no es compatible con el modelo de máquina '{$machineModel}'.";

        parent::__construct($msg, $httpStatusCode);
        $this->sparePartId = $sparePartId;
        $this->partCode = $partCode;
        $this->machineModel = $machineModel;
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

    public function getSparePartId(): int
    {
        return $this->sparePartId;
    }

    public function getPartCode(): string
    {
        return $this->partCode;
    }

    public function getMachineModel(): string
    {
        return $this->machineModel;
    }
}
