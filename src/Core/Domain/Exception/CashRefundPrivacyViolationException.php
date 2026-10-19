<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * CashRefundPrivacyViolationException
 *
 * El técnico presente en la sede intentó adjuntar al parte de avería un dato
 * bancario o de reembolso (`iban`, `bizum_phone` o `refund_requested`) mientras
 * abría una Avería Oportunista In Situ (Módulo 16, RF-04.3 y Constitución
 * Art. V.4).
 *
 * El gestor de incidencias **nunca** custodia coordenadas de cobro: los
 * reintegros se tramitan exclusivamente por el circuito del Ciudadano QR, que
 * tiene su propio expediente, sus propias garantías antifraude y su propio
 * consumo. Aceptar un IBAN por el canal del técnico abriría un tratamiento de
 * datos personales sin base ni trazabilidad, y por eso el rechazo no es una
 * preferencia de interfaz sino una puerta de dominio.
 *
 * Mapea al código HTTP 422 con el código de error exacto del contrato
 * (`CASH_REFUND_PRIVACY_VIOLATION`), transportando **solo los nombres** de los
 * campos rechazados —jamás sus valores— para que la interfaz explique qué
 * retirar del formulario sin reexponer el dato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class CashRefundPrivacyViolationException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'CASH_REFUND_PRIVACY_VIOLATION';

    /** Campos financieros prohibidos en el parte del técnico (RF-04.3). */
    public const FORBIDDEN_FIELDS = ['iban', 'bizum_phone', 'refund_requested'];

    private string $errorCode;
    private int $httpStatusCode;

    /** @var list<string> */
    private array $violatingFields;

    /**
     * @param list<string> $violatingFields Nombres de los campos bancarios detectados (nunca sus valores).
     */
    public function __construct(
        array $violatingFields = [],
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $message = 'El parte del técnico no puede contener datos bancarios ni de reembolso: '
                . 'los reintegros se tramitan exclusivamente por el código QR exterior (Art. V.4).';
        }

        parent::__construct($message, $httpStatusCode);
        $this->violatingFields = array_values($violatingFields);
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
     * @return list<string> Campos prohibidos presentes en la petición.
     */
    public function getViolatingFields(): array
    {
        return $this->violatingFields;
    }

    /**
     * Carga útil pública del rechazo, sin valores personales.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'violating_fields' => $this->violatingFields,
            'forbidden_fields' => self::FORBIDDEN_FIELDS,
            'remedy' => 'Retire los datos bancarios del parte y remita al usuario al código QR exterior para su reembolso.',
        ];
    }
}
