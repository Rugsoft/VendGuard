<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidServiceSheetException
 *
 * El bloque de firma no cumple el contrato del Módulo 19 (RD-02, contratos §3.2).
 * Se lanza antes de cualquier escritura, de modo que un bloque mal formado no
 * puede degradar en un «cierre sin firma» silencioso.
 *
 * Mapea al código HTTP 422 con el código técnico `INVALID_SERVICE_SHEET`.
 * `getErrors()` lleva una entrada por regla incumplida para que el cliente pueda
 * señalar el campo exacto y no un mensaje genérico.
 */
final class InvalidServiceSheetException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_SERVICE_SHEET';

    /** @var list<string> */
    private array $errors;

    /**
     * @param list<string> $errors
     */
    public function __construct(array $errors, string $message = '')
    {
        $this->errors = array_values($errors);

        parent::__construct(
            $message !== '' ? $message : implode(' ', $this->errors),
            self::HTTP_STATUS
        );
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
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
