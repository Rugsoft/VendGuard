<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * MachineOutsideRestockerZonesException
 *
 * El reponedor ha solicitado una máquina cuya sede no pertenece a ninguna de
 * sus zonas operativas vivas (`PRIMARY`/`BACKUP`, `removed_at IS NULL`, zona
 * activa). Responde 403 `MACHINE_OUTSIDE_RESTOCKER_ZONES` (QA 2, RF-05.2).
 */
final class MachineOutsideRestockerZonesException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'MACHINE_OUTSIDE_RESTOCKER_ZONES';

    private int $machineId;

    private int $restockerId;

    public function __construct(int $machineId, int $restockerId, string $message = '')
    {
        $this->machineId = $machineId;
        $this->restockerId = $restockerId;

        parent::__construct(
            $message !== ''
                ? $message
                : "La máquina {$machineId} no pertenece a las zonas operativas vivas del reponedor {$restockerId}.",
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

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }
}
