<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InactiveRecordCollisionException
 * 
 * Excepción de Dominio lanzada cuando se intenta registrar un código o identificador
 * único que ya existe en la base de datos pero se encuentra dado de baja lógica (Decisión QA 5).
 * 
 * Permite a la API responder con HTTP 409 Conflict y ofrecer la reactivación asistida (can_reactivate = true).
 */
class InactiveRecordCollisionException extends DomainException
{
    public const HTTP_STATUS = 409;

    private string $errorCode;
    private ?int $entityId;
    private string $entityType;
    private bool $canReactivate;
    private int $httpStatusCode;

    public function __construct(
        string $errorCode,
        string $message,
        ?int $entityId = null,
        string $entityType = '',
        bool $canReactivate = true,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->entityId = $entityId;
        $this->entityType = $entityType;
        $this->canReactivate = $canReactivate;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function canReactivate(): bool
    {
        return $this->canReactivate;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
