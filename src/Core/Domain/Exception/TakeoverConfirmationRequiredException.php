<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * TakeoverConfirmationRequiredException
 *
 * El expediente que el técnico presente en la sede quiere asumir está asignado
 * —o pausado— a nombre de **otro compañero** que aún no ha iniciado su
 * intervención. Asumirlo sin confirmación le ahorraría un desplazamiento en vano,
 * pero también podría dejarlo esperando en la puerta: por eso la regla exige
 * confirmación expresa y, si se aporta, una nota interna de traspaso (Módulo 16,
 * RF-05.3 y Resolución QA 5).
 *
 * Mapea al código HTTP 409 y transporta al técnico saliente para que la interfaz
 * explique a quién se está reemplazando.
 */
class TakeoverConfirmationRequiredException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'TAKEOVER_CONFIRMATION_REQUIRED';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $previousTechnicianId;
    private ?string $previousTechnicianName;

    public function __construct(
        ?int $previousTechnicianId = null,
        ?string $previousTechnicianName = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $holder = $previousTechnicianName !== null && trim($previousTechnicianName) !== ''
                ? trim($previousTechnicianName)
                : 'otro compañero';
            $message = sprintf(
                'El expediente está a nombre de %s. Confirme el traspaso para asumirlo in situ y evitarle un desplazamiento en vano.',
                $holder
            );
        }

        parent::__construct($message, $httpStatusCode);
        $this->previousTechnicianId = $previousTechnicianId;
        $this->previousTechnicianName = $previousTechnicianName !== null ? trim($previousTechnicianName) : null;
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

    public function getPreviousTechnicianId(): ?int
    {
        return $this->previousTechnicianId;
    }

    public function getPreviousTechnicianName(): ?string
    {
        return $this->previousTechnicianName;
    }
}
