<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * JustificationTooShortException
 *
 * Excepción de Dominio lanzada cuando una justificación técnica o de rechazo no
 * alcanza el mínimo de 20 caracteres descriptivos.
 *
 * Dos situaciones la exigen, y ninguna de las dos es un capricho de formato:
 *
 *  - `UNVERIFIED_NO_CASH`: el técnico afirma que no localiza dinero atascado ni
 *    existen evidencias de saldo retenido. Es la conclusión que más conviene
 *    verificar, así que el sistema exige una explicación escrita (RF-REF-04).
 *  - Desestimación de una reclamación por parte de Coordinación (RF-REF-08).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class JustificationTooShortException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'JUSTIFICATION_TOO_SHORT';
    public const DEFAULT_MESSAGE = 'La justificación técnica o de rechazo debe contener un mínimo de 20 caracteres descriptivos.';

    /** Longitud mínima exigida por el catálogo de contratos. */
    public const MINIMUM_LENGTH = 20;

    /**
     * @param string $justification Texto incompleto que se rechazó.
     */
    public function __construct(
        private readonly string $justification = '',
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

    /**
     * The rejected text. Only ever the text the technician actually typed; it is
     * never populated with any financial datum.
     */
    public function getJustification(): string
    {
        return $this->justification;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'justification_length' => mb_strlen($this->justification),
            'minimum_length' => self::MINIMUM_LENGTH,
        ];
    }
}