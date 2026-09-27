<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ChecklistIncompleteException
 * 
 * Excepción de Dominio lanzada cuando se intenta finalizar una inspección preventiva
 * sin responder todos los ítems obligatorios o sin consignar la temperatura requerida (EARS 3.5).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class ChecklistIncompleteException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'CHECKLIST_INCOMPLETE';

    private string $errorCode;
    private int $httpStatusCode;
    /** @var array<string> */
    private array $missingItems;

    /**
     * @param string $message
     * @param array<string> $missingItems
     * @param string $errorCode
     * @param int $httpStatusCode
     */
    public function __construct(
        string $message = 'Debe cumplimentar todos los puntos obligatorios del checklist y la temperatura antes de finalizar la inspección.',
        array $missingItems = [],
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->missingItems = $missingItems;
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

    /**
     * @return array<string>
     */
    public function getMissingItems(): array
    {
        return $this->missingItems;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'missing_items' => $this->missingItems,
        ];
    }
}
