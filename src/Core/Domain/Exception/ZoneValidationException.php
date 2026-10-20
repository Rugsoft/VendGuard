<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ZoneValidationException
 *
 * Una zona operativa (o su código postal) no cumple el contrato del Módulo 17:
 * código o nombre por debajo del mínimo de tres caracteres, color que no es
 * `#RRGGBB`, o un CP que no son exactamente cinco dígitos (RF-01.1, RF-01.2,
 * Resolución QA 7).
 *
 * Se distinguen dos usos, y la diferencia no es cosmética:
 *
 *  - **400 `BAD_REQUEST`** cuando lo que llega no tiene la forma que el contrato
 *    declara. Es un error de la petición y el cliente puede corregirlo sin
 *    consultar el estado del sistema.
 *  - **422 `UNPROCESSABLE_ENTITY`** cuando la forma es correcta pero la regla de
 *    negocio impide la operación (por ejemplo, reactivar una zona sin volver a
 *    declarar sus códigos postales). El cliente no puede arreglarlo cambiando el
 *    formato: tiene que cambiar la situación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class ZoneValidationException extends DomainException
{
    /** HTTP y código de error por defecto: la forma de la petición no es válida. */
    public const HTTP_STATUS = 400;
    public const ERROR_CODE = 'BAD_REQUEST';

    /** Variante de regla de negocio: la forma es válida, la operación no procede. */
    public const UNPROCESSABLE_HTTP_STATUS = 422;
    public const UNPROCESSABLE_ERROR_CODE = 'UNPROCESSABLE_ENTITY';

    /**
     * @param string $message Mensaje en castellano para el coordinador.
     * @param string $errorCode Código del contrato que viaja en `error.code`.
     * @param int $httpStatusCode Estado HTTP con el que el controlador responde.
     * @param array<string, string> $fieldErrors Campo del payload => motivo del rechazo.
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = self::ERROR_CODE,
        private readonly int $httpStatusCode = self::HTTP_STATUS,
        private readonly array $fieldErrors = []
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    /**
     * Rechazo de un campo concreto del payload (400 por defecto).
     *
     * @param array<string, string> $fieldErrors
     */
    public static function forField(
        string $field,
        string $message,
        array $fieldErrors = [],
        string $errorCode = self::ERROR_CODE
    ): self {
        $fieldErrors[$field] = $message;

        return new self($message, $errorCode, self::HTTP_STATUS, $fieldErrors);
    }

    /**
     * Regla de negocio que impide operar sobre una zona con forma válida (422).
     *
     * @param array<string, string> $fieldErrors
     */
    public static function unprocessable(string $message, array $fieldErrors = []): self
    {
        return new self($message, self::UNPROCESSABLE_ERROR_CODE, self::UNPROCESSABLE_HTTP_STATUS, $fieldErrors);
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
     * Motivos por campo, para que el modal del coordinador señale el input exacto.
     *
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['field_errors' => $this->fieldErrors];
    }
}
