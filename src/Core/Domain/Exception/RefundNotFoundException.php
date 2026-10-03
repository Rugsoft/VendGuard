<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * RefundNotFoundException
 *
 * Excepción de Dominio lanzada cuando no se localiza un expediente de reintegro
 * por su identificador o por su token de seguimiento, o cuando el expediente
 * existe pero ha sido archivado lógicamente (Art. III) y por tanto ya no admite
 * cambios.
 *
 * Cubre el error `REFUND_NOT_FOUND` del catálogo de contratos, que hasta ahora
 * no tenía clase de dominio propia pese a figurar como `404 Not Found`.
 *
 * Mapea directamente al código HTTP 404 Not Found.
 */
class RefundNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'REFUND_NOT_FOUND';
    public const DEFAULT_MESSAGE = 'El expediente de reintegro especificado no existe o fue archivado.';

    /**
     * @param int|string|null $refundRequestId Identificador o token buscado.
     */
    public function __construct(
        private readonly int|string|null $refundRequestId = null,
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

    public function getRefundRequestId(): int|string|null
    {
        return $this->refundRequestId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'refund_request_id' => $this->refundRequestId,
        ];
    }
}