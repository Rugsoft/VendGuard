<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ActiveIncidentInProgressException
 *
 * Bloqueo estricto (*fail-fast*, D4 / RF-04) cuando se intenta cerrar turno y el técnico
 * tiene alguna avería en estado IN_PROGRESS.
 */
final class ActiveIncidentInProgressException extends DomainException
{
    /**
     * @param list<string> $blockingIncidentCodes Códigos de tickets en curso
     */
    public function __construct(
        private readonly array $blockingIncidentCodes,
        string $message = 'No es posible cerrar turno con averías en curso. Debe resolver la intervención o pausarla por repuesto (PENDING_PARTS) o falta de acceso (PENDING_INFO).'
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'HAS_IN_PROGRESS_INCIDENTS';
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    /**
     * @return list<string>
     */
    public function getBlockingIncidentCodes(): array
    {
        return $this->blockingIncidentCodes;
    }
}
