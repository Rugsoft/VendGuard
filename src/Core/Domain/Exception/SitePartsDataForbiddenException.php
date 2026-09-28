<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SitePartsDataForbiddenException
 * 
 * Excepción de Dominio lanzada cuando un usuario con rol de Responsable de Sede
 * (o informador externo) intenta acceder a información sobre piezas de repuesto,
 * consumos de taller o costes económicos asociados (Constitución Art. V.4).
 * 
 * Mapea directamente al código HTTP 403 Forbidden.
 */
class SitePartsDataForbiddenException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'SITE_PARTS_DATA_FORBIDDEN';

    private string $errorCode;
    private int $httpStatusCode;
    private string $attemptedRole;
    private string $requestedResource;

    public function __construct(
        string $attemptedRole = 'LOCATION_MANAGER',
        string $requestedResource = 'SPARE_PARTS_DATA',
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        $msg = $message !== '' 
            ? $message 
            : 'Por imperativo del Artículo V.4 de la Constitución (Segregación y Privacidad), los responsables de sede no tienen acceso a información sobre piezas de repuesto, notas internas ni costes económicos.';

        parent::__construct($msg, $httpStatusCode);
        $this->attemptedRole = $attemptedRole;
        $this->requestedResource = $requestedResource;
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

    public function getAttemptedRole(): string
    {
        return $this->attemptedRole;
    }

    public function getRequestedResource(): string
    {
        return $this->requestedResource;
    }
}
