<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * SatisfactionSurveyValidationException
 *
 * La valoración de un servicio resuelto no cumple el contrato del Módulo 20: el
 * expediente no existe, pertenece a otra sede, ya no admite valoración, el cuerpo
 * no tiene la forma declarada o el episodio ya fue valorado (contratos §4.2 y §5).
 *
 * Los siete casos comparten forma —código de contrato, HTTP y errores de campo—
 * pero no significado: el `404` dice que el destino no existe, el `403` que existe
 * pero es de otra sede, el `409` que ya hay una respuesta y el `422` que la regla
 * no permite la operación. El controlador no decide nada: lee `getErrorCode()`,
 * `getHttpStatusCode()` y `getFieldErrors()`.
 *
 * La precedencia del contrato (§4.2) la impone el orden en que el servicio invoca
 * estas factorías, no el orden de este fichero.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class SatisfactionSurveyValidationException extends DomainException
{
    /** El expediente no existe (404). */
    public const INCIDENT_NOT_FOUND_CODE = 'INCIDENT_NOT_FOUND';

    /** El expediente pertenece a otra sede (403). */
    public const SITE_MISMATCH_CODE = 'SITE_MISMATCH';

    /** Sin resolución vigente o en un estado que no admite valoración (422). */
    public const SURVEY_NOT_AVAILABLE_CODE = 'SURVEY_NOT_AVAILABLE';

    /** El cuerpo no trae `rating` (400). */
    public const MISSING_RATING_CODE = 'MISSING_RATING';

    /** `rating` no es un entero de 1 a 5 (422). */
    public const INVALID_RATING_CODE = 'INVALID_RATING';

    /** El comentario no es texto o supera los 500 caracteres (422). */
    public const COMMENT_TOO_LONG_CODE = 'COMMENT_TOO_LONG';

    /** El episodio de resolución ya tiene respuesta (409). */
    public const SURVEY_ALREADY_SUBMITTED_CODE = 'SURVEY_ALREADY_SUBMITTED';

    private const UNPROCESSABLE_HTTP_STATUS = 422;
    private const BAD_REQUEST_HTTP_STATUS = 400;
    private const NOT_FOUND_HTTP_STATUS = 404;
    private const FORBIDDEN_HTTP_STATUS = 403;
    private const CONFLICT_HTTP_STATUS = 409;

    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatusCode,
        private readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
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
     * El expediente indicado no existe (o el `{id}` de la ruta no es numérico).
     */
    public static function incidentNotFound(): self
    {
        return new self(
            'La avería solicitada no existe.',
            self::INCIDENT_NOT_FOUND_CODE,
            self::NOT_FOUND_HTTP_STATUS
        );
    }

    /**
     * El expediente existe pero no pertenece a la sede autenticada.
     */
    public static function siteMismatch(): self
    {
        return new self(
            'La avería no pertenece a su sede.',
            self::SITE_MISMATCH_CODE,
            self::FORBIDDEN_HTTP_STATUS
        );
    }

    /**
     * La avería no tiene una resolución vigente que valorar.
     */
    public static function notAvailable(): self
    {
        return new self(
            'La avería no está resuelta o ya no admite valoración.',
            self::SURVEY_NOT_AVAILABLE_CODE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * El cuerpo no incluye la clave `rating`.
     */
    public static function missingRating(): self
    {
        return new self(
            'Debe indicar una valoración de 1 a 5 estrellas.',
            self::MISSING_RATING_CODE,
            self::BAD_REQUEST_HTTP_STATUS,
            ['rating' => 'Falta la valoración.']
        );
    }

    /**
     * `rating` no es un entero de 1 a 5 ni una cadena que lo represente (R9).
     */
    public static function invalidRating(mixed $received): self
    {
        return new self(
            'La valoración debe ser un entero entre 1 y 5.',
            self::INVALID_RATING_CODE,
            self::UNPROCESSABLE_HTTP_STATUS,
            ['rating' => 'Valor recibida: ' . self::describe($received) . '.']
        );
    }

    /**
     * El comentario no es texto (contratos §4.4).
     */
    public static function commentNotText(mixed $received): self
    {
        return new self(
            'El comentario debe ser texto.',
            self::COMMENT_TOO_LONG_CODE,
            self::UNPROCESSABLE_HTTP_STATUS,
            ['comment' => 'Tipo recibido: ' . get_debug_type($received) . '.']
        );
    }

    /**
     * El comentario supera la cota de 500 caracteres tras normalizar (RD-05).
     */
    public static function commentTooLong(int $length): self
    {
        return new self(
            'El comentario no puede superar los 500 caracteres.',
            self::COMMENT_TOO_LONG_CODE,
            self::UNPROCESSABLE_HTTP_STATUS,
            ['comment' => sprintf('Longitud recibida: %d caracteres.', $length)]
        );
    }

    /**
     * El episodio de resolución vigente ya tiene respuesta (RF-02.7).
     */
    public static function alreadySubmitted(): self
    {
        return new self(
            'Este servicio ya fue valorado.',
            self::SURVEY_ALREADY_SUBMITTED_CODE,
            self::CONFLICT_HTTP_STATUS
        );
    }

    /**
     * Descripción breve y segura del valor recibido, para el error de campo.
     */
    private static function describe(mixed $received): string
    {
        if (is_scalar($received) || $received === null) {
            return json_encode($received, JSON_UNESCAPED_UNICODE) ?: var_export($received, true);
        }

        return get_debug_type($received);
    }
}
