<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ColdChainOverrideJustificationRequiredException
 *
 * La avería es de cadena de frío (`PERISHABLE_FOOD` o `COMBO`) o crítica, la
 * recomendación sanitaria del Art. II está activa, y el coordinador ha elegido a
 * un técnico distinto del recomendado sin explicar por qué. La Constitución no
 * permite ese descarte mudo: exige una justificación de **al menos 20 caracteres
 * reales** (Art. V.1, RF-05.2, Resolución QA 8).
 *
 * La justificación no sustituye a `reassignment_reason`: una razona el descarte
 * sanitario y la otra el cambio de titular, y ambas pueden convivir en la misma
 * reasignación. Mapea a HTTP 422 con el código exacto
 * `COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` del contrato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class ColdChainOverrideJustificationRequiredException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED';
    public const MINIMUM_LENGTH = 20;
    public const DEFAULT_MESSAGE = 'La avería presenta riesgo crítico de frío (Art. II). Si descarta al técnico de guardia sugerido, debe aportar una justificación obligatoria de al menos 20 caracteres.';

    /**
     * @param string $justification Texto recibido (posiblemente vacío o demasiado corto).
     */
    public function __construct(
        private readonly string $justification = '',
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

    /**
     * Texto que rechazó la regla. Nunca se rellena con datos internos del técnico.
     */
    public function getJustification(): string
    {
        return $this->justification;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'justification_length' => mb_strlen(trim($this->justification)),
            'minimum_length' => self::MINIMUM_LENGTH,
        ];
    }
}
