<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * IncidentAlreadyInProgressByOtherTechnicianException
 *
 * El técnico presente en la sede pretende asumir un expediente que **otro
 * compañero ya tiene en curso** sobre la misma máquina (Módulo 16, RF-05.5 y
 * Constitución Art. V.3).
 *
 * No es una transición discutible: dos técnicos no pueden intervenir a la vez
 * la misma máquina, porque duplicaría el tiempo real de intervención, el
 * consumo de repuestos y la trazabilidad del cierre. A diferencia del traspaso
 * de un ticket meramente asignado (RF-05.3, que sí admite confirmación), aquí no
 * existe confirmación posible: el compañero está físicamente delante del equipo.
 *
 * Mapea al código HTTP 409 con el código exacto del contrato
 * (`INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN`) y transporta al titular
 * para que la interfaz explique quién tiene la máquina, sin exponer nada más.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class IncidentAlreadyInProgressByOtherTechnicianException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $technicianId;
    private ?string $technicianName;

    public function __construct(
        ?int $technicianId = null,
        ?string $technicianName = null,
        string $message = '',
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $holder = $technicianName !== null && trim($technicianName) !== ''
                ? trim($technicianName)
                : 'otro técnico';
            $message = sprintf(
                'La máquina ya está en curso en manos de %s. No se puede asumir una intervención que otro compañero está realizando en este momento.',
                $holder
            );
        }

        parent::__construct($message, $httpStatusCode);
        $this->errorCode = self::ERROR_CODE;
        $this->httpStatusCode = $httpStatusCode;
        $this->technicianId = $technicianId;
        $this->technicianName = $technicianName !== null ? trim($technicianName) : null;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function getTechnicianName(): ?string
    {
        return $this->technicianName;
    }
}
