<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use Throwable;

/**
 * DexAuditForbiddenException
 *
 * Rechazo de perímetro o de rol en la importación de un volcado EVA-DTS `.dex`
 * (Módulo 39, RF-03.1, C2, QA-1, Art. II.2 y Art. V.4).
 *
 * Mapea directamente al código HTTP 403 Forbidden. Es la respuesta del sistema
 * cuando el fichero es válido y la máquina existe, pero el actor no cubre esa
 * máquina hoy: el `RESTOCKER` fuera de sus zonas operativas
 * (`MACHINE_OUTSIDE_RESTOCKER_ZONES`) o el `TECHNICIAN` sin intervención activa
 * ni cobertura de la zona de la sede (`MACHINE_OUTSIDE_TECHNICIAN_SCOPE`).
 *
 * La primacía sanitaria no vive aquí: es un conflicto de estado (`409
 * SANITARY_DEX_BLOCKED`) y la lanza `DexAuditConflictException`, igual que el
 * disparador `enforce_dex_audit_sanitary_guard()` en el motor.
 *
 * Dogma Vanilla: PHP 8.2+ puro, excepción de dominio sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class DexAuditForbiddenException extends DomainException
{
    public const HTTP_STATUS = 403;
    public const ERROR_CODE = 'DEX_AUDIT_FORBIDDEN';

    private string $errorCode;
    private int $httpStatusCode;

    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details Contexto del rechazo (actor, máquina, rol).
     */
    public function __construct(
        string $message,
        string $errorCode = self::ERROR_CODE,
        array $details = [],
        int $httpStatusCode = self::HTTP_STATUS,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
