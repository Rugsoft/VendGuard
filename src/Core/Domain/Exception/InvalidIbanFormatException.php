<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidIbanFormatException
 *
 * Excepción de Dominio lanzada cuando el IBAN introducido no supera la
 * verificación sintáctica conforme al algoritmo oficial Módulo 97 (ISO 13616 /
 * ISO 7064), implementado en PHP puro para respetar el Dogma Vanilla (Art. IV).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidIbanFormatException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_IBAN_FORMAT';
    public const DEFAULT_MESSAGE = 'El código de cuenta bancaria (IBAN) introducido no es válido conforme al algoritmo oficial Módulo 97.';

    /**
     * @param string|null $maskedIban IBAN parcialmente enmascarado para el
     *   diagnóstico. Nunca debe receber el IBAN completo en claro, porque esta
     *   excepción acaba en el log de auditoría (Art. V.4).
     */
    public function __construct(
        private readonly ?string $maskedIban = null,
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

    public function getMaskedIban(): ?string
    {
        return $this->maskedIban;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'masked_iban' => $this->maskedIban,
        ];
    }
}
