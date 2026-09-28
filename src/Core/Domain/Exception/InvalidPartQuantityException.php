<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidPartQuantityException
 * 
 * Excepción de Dominio lanzada cuando se intenta registrar o solicitar una cantidad
 * de piezas fuera del rango permitido de 1 a 50 unidades (RF-REP-03 / RF-REP-06 / Anti-Errata).
 * 
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidPartQuantityException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_PART_QUANTITY';
    public const MIN_QUANTITY = 1;
    public const MAX_QUANTITY = 50;

    private string $errorCode;
    private int $httpStatusCode;
    private int $attemptedQuantity;
    private int $minQuantity;
    private int $maxQuantity;

    public function __construct(
        int $attemptedQuantity,
        int $minQuantity = self::MIN_QUANTITY,
        int $maxQuantity = self::MAX_QUANTITY,
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        $msg = $message !== '' 
            ? $message 
            : "La cantidad de unidades debe ser un número entero entre {$minQuantity} y {$maxQuantity} (valor recibido: {$attemptedQuantity}).";

        parent::__construct($msg, $httpStatusCode);
        $this->attemptedQuantity = $attemptedQuantity;
        $this->minQuantity = $minQuantity;
        $this->maxQuantity = $maxQuantity;
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

    public function getAttemptedQuantity(): int
    {
        return $this->attemptedQuantity;
    }

    public function getMinQuantity(): int
    {
        return $this->minQuantity;
    }

    public function getMaxQuantity(): int
    {
        return $this->maxQuantity;
    }
}
