<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * VanStockValidationException
 *
 * Un ajuste de stock de furgoneta no cumple el contrato del Módulo 18: el payload no tiene la
 * forma declarada, la cantidad o el umbral quedan fuera de sus cotas, el técnico o la pieza no
 * existen, o el usuario destino no es un técnico (contratos §4.3 y §5).
 *
 * Los cuatro casos comparten forma —código de contrato, HTTP y errores de campo— pero **no** el
 * significado: un `404` dice que el destino no existe, y un `422` dice que existe pero la regla
 * no permite la operación. El controlador no decide nada: lee `getErrorCode()`,
 * `getHttpStatusCode()` y `getFieldErrors()`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class VanStockValidationException extends DomainException
{
    /** La forma del payload no es la declarada (422). */
    public const INVALID_PAYLOAD_CODE = 'INVALID_VAN_STOCK_PAYLOAD';

    /** Cantidad o umbral fuera de las cotas anti-errata (422). */
    public const INVALID_QUANTITY_CODE = 'INVALID_VAN_STOCK_QUANTITY';

    /** El técnico o la pieza indicados no existen (404). */
    public const TARGET_NOT_FOUND_CODE = 'VAN_STOCK_TARGET_NOT_FOUND';

    /** El usuario destino existe pero no ejerce de técnico (422). */
    public const ROLE_NOT_TECHNICIAN_CODE = 'VAN_STOCK_ROLE_NOT_TECHNICIAN';

    /** HTTP por defecto del catálogo: la petición no es procesable. */
    public const UNPROCESSABLE_HTTP_STATUS = 422;

    /** HTTP del destino inexistente. */
    public const NOT_FOUND_HTTP_STATUS = 404;

    /**
     * @param string $message Mensaje en castellano para el coordinador.
     * @param string $errorCode Código del contrato que viaja en `error.code`.
     * @param int $httpStatusCode Estado HTTP con el que el controlador responde.
     * @param array<string, string> $fieldErrors Campo del payload => motivo del rechazo.
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatusCode,
        private readonly array $fieldErrors = []
    ) {
        parent::__construct($message, $httpStatusCode);
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
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * Payload incompleto o con tipos que el contrato no admite.
     *
     * @param array<string, string> $fieldErrors
     */
    public static function invalidPayload(
        string $message = 'Los datos del stock son incompletos o erróneos.',
        array $fieldErrors = []
    ): self {
        return new self($message, self::INVALID_PAYLOAD_CODE, self::UNPROCESSABLE_HTTP_STATUS, $fieldErrors);
    }

    /**
     * Cantidad o umbral fuera de las cotas anti-errata declaradas.
     */
    public static function invalidQuantity(int $quantityAvailable, int $minThreshold): self
    {
        return new self(
            'La cantidad disponible debe estar entre -999 y 9999 unidades y el umbral entre 0 y 9999.',
            self::INVALID_QUANTITY_CODE,
            self::UNPROCESSABLE_HTTP_STATUS,
            [
                'quantity_available' => sprintf('Cantidad recibida: %d.', $quantityAvailable),
                'min_threshold' => sprintf('Umbral recibido: %d.', $minThreshold),
            ]
        );
    }

    /**
     * Técnico o pieza inexistentes.
     *
     * @param array<string, string> $fieldErrors
     */
    public static function targetNotFound(
        string $message = 'No se encontró el técnico o la pieza indicados.',
        array $fieldErrors = []
    ): self {
        return new self($message, self::TARGET_NOT_FOUND_CODE, self::NOT_FOUND_HTTP_STATUS, $fieldErrors);
    }

    /**
     * El usuario destino existe pero no es técnico: el stock es de una furgoneta de ruta.
     */
    public static function roleNotTechnician(int $userId): self
    {
        return new self(
            'El stock solo puede asignarse a un usuario con rol de técnico.',
            self::ROLE_NOT_TECHNICIAN_CODE,
            self::UNPROCESSABLE_HTTP_STATUS,
            ['technician_id' => sprintf('El usuario %d no es técnico.', $userId)]
        );
    }
}
