<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ZoneNotFoundException
 *
 * La zona operativa solicitada no existe: ni el identificador ni el código
 * alfanumérico corresponden a ninguna fila del catálogo (RF-01.1).
 *
 * No se confunde con una zona **inactiva**: una zona desactivada sigue existiendo
 * y conserva su historial (Art. III.1); lo que aquí falta es la fila. Mapea al
 * código HTTP 404 con el código exacto del contrato (`ZONE_NOT_FOUND`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class ZoneNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'ZONE_NOT_FOUND';

    private ?int $zoneId;
    private string $errorCode;
    private int $httpStatusCode;

    public function __construct(
        ?int $zoneId = null,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = $zoneId !== null
                ? sprintf('No se encontró ninguna zona operativa con ID %d.', $zoneId)
                : 'No se encontró la zona operativa indicada.';
        }

        parent::__construct($message, $httpStatusCode);
        $this->zoneId = $zoneId;
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

    public function getZoneId(): ?int
    {
        return $this->zoneId;
    }
}
