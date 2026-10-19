<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * LocationNotEnabledForOpportunisticException
 *
 * La sede no está habilitada para una intervención oportunista: el técnico no
 * tiene una parada activa (correctiva o preventiva) ni transcurre la ventana de
 * gracia de 30 minutos desde que cerró o pausó su última intervención allí
 * (Módulo 16, RF-01.1 a RF-01.3).
 *
 * Mapea al código HTTP 403: la petición es legítima, pero el técnico no está
 * autorizado a operar sobre esa sede en este instante.
 */
class LocationNotEnabledForOpportunisticException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC';

    private string $errorCode;
    private int $httpStatusCode;
    private int $locationId;
    private int $technicianId;

    public function __construct(
        int $locationId,
        int $technicianId,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = 'La sede no está habilitada para una intervención oportunista: no hay parada activa ni ventana de gracia de 30 minutos vigente para este técnico.';
        }

        parent::__construct($message, $httpStatusCode);
        $this->locationId = $locationId;
        $this->technicianId = $technicianId;
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

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }
}
