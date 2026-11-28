<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use Throwable;

/**
 * DexAuditConflictException
 *
 * Conflicto de estado de un volcado EVA-DTS `.dex` (Módulo 39, RF-03.1,
 * RF-06.2, RF-07.2, QA-1, QA-5, Art. II.2 y Art. III.3).
 *
 * A diferencia del rechazo de forma, aquí el fichero puede ser válido y el
 * conflicto nace del estado: la huella ya existe en un volcado vigente
 * (`DEX_DUPLICATE_HASH`), la máquina está bloqueada por primacía sanitaria
 * (`SANITARY_DEX_BLOCKED`), el identificador de mutación ya se usó en otra
 * máquina (`CLIENT_MUTATION_ID_ALREADY_USED`), el volcado ya fue anulado
 * (`DEX_AUDIT_ALREADY_VOIDED`), la operación ya está enlazada
 * (`DEX_AUDIT_ALREADY_LINKED`) o el vínculo apunta a otra máquina
 * (`DEX_AUDIT_MACHINE_MISMATCH`).
 *
 * Mapea directamente al código HTTP 409 Conflict; el cliente Offline usa el
 * código para decidir entre retirar el ítem de la cola o aislarlo en la
 * bandeja de conflictos (QA-5).
 */
final class DexAuditConflictException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'DEX_AUDIT_CONFLICT';

    private string $errorCode;
    private int $httpStatusCode;

    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details Contexto del conflicto (audit_id, hash, máquina).
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
