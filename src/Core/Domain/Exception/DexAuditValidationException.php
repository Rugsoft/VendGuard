<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use Throwable;

/**
 * DexAuditValidationException
 *
 * Rechazo de validación de un volcado EVA-DTS `.dex` (Módulo 39, RF-02,
 * QA-5, Art. I.3 y Art. IV).
 *
 * El parser no lanza una excepción distinta por cada regla: todas las
 * validaciones de forma del fichero —envoltorio `DXS`/`DXE`, presencia de
 * `VA1`, codificación, `ID4`, duplicados de cabecera o de selección, cotas
 * numéricas— comparten este tipo y viajan con su **código funcional** y el
 * detalle de la línea que falló. El controlador traduce el código a la
 * respuesta `422` del contrato sin volver a interpretar mensajes.
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
final class DexAuditValidationException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'DEX_AUDIT_INVALID';

    private string $errorCode;
    private int $httpStatusCode;

    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details Contexto del fallo (línea, campo, valor).
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
