<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SiteRefundDataForbiddenException
 *
 * Excepción de Dominio lanzada cuando un rol no autorizado intenta consultar
 * o gestionar los datos financieros de un expediente de reintegro: el IBAN y el
 * teléfono de Bizum son territorio exclusivo de Coordinación (Art. V.4,
 * RF-REF-10).
 *
 * Cubre tanto al Responsable de Sede como al Técnico de Campo, que sólo deben
 * ver el importe, la anonymously claimant y el estado.
 *
 * Mapea directamente al código HTTP 403 Forbidden.
 */
class SiteRefundDataForbiddenException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'FORBIDDEN';
    public const DEFAULT_MESSAGE = 'No dispone de permisos para gestionar o consultar datos financieros de reintegros.';

    public function __construct(
        private readonly string $attemptedRole = 'LOCATION_MANAGER',
        private readonly ?string $requestedResource = null,
        string $message = self::DEFAULT_MESSAGE
    ) {
        parent::__construct($message, self::HTTP_STATUS);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getAttemptedRole(): string
    {
        return $this->attemptedRole;
    }

    public function getRequestedResource(): ?string
    {
        return $this->requestedResource;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'attempted_role' => $this->attemptedRole,
            'requested_resource' => $this->requestedResource,
        ];
    }
}
