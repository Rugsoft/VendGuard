<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * IncidentNotFoundException
 *
 * El expediente que el técnico pretende asumir o reabrir in situ no existe, o
 * fue borrado lógicamente (Módulo 16, RF-05.1).
 *
 * Se distingue de una transición ilegal: aquí no hay expediente sobre el que
 * decidir nada. Mapea al código HTTP 404 con el código exacto del contrato
 * (`INCIDENT_NOT_FOUND`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class IncidentNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'INCIDENT_NOT_FOUND';

    private string $errorCode;
    private int $httpStatusCode;
    private ?int $incidentId;

    public function __construct(
        ?int $incidentId = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = $incidentId !== null
                ? sprintf('No se encontró ningún expediente con ID %d.', $incidentId)
                : 'No se encontró el expediente indicado.';
        }

        parent::__construct($message, $httpStatusCode);
        $this->incidentId = $incidentId;
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

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }
}
