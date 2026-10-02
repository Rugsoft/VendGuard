<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * DuplicateRefundClaimException
 *
 * Excepción de Dominio lanzada cuando un consumidor intenta abrir un segundo
 * expediente de reintegro sobre una avería para la que ya tiene uno vivo
 * (RF-REF-11).
 *
 * La unidad de la regla es la pareja (avería, consumidor), NO la avería a secas.
 * Una máquina que se come la moneda de cinco personas genera cinco
 * reclamaciones legítimas y distintas, y bloquearlas por avería sería un fallo
 * mucho peor que el que esta excepción evita.
 *
 * Transporta el token de seguimiento del expediente ya abierto porque el
 * consumidor no puede perder el acceso a lo que ya tramitó: sin ese token,
 * rechazar su segundo intento le dejaría sin ninguna forma de seguir el
 * expediente que ya tiene en marcha.
 *
 * Mapea directamente al código HTTP 409 Conflict.
 */
class DuplicateRefundClaimException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'DUPLICATE_REFUND_CLAIM';
    public const DEFAULT_MESSAGE = 'Ya tienes una solicitud de reintegro en marcha sobre esta avería. Te presentamos la que está en curso para que puedas seguirla.';

    public function __construct(
        private readonly int $existingCaseId,
        private readonly string $existingTrackingToken,
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

    public function getExistingCaseId(): int
    {
        return $this->existingCaseId;
    }

    /**
     * The tracking token of the case already in progress, so the claimant can be
     * pointed at their own link instead of losing access to it.
     */
    public function getExistingTrackingToken(): string
    {
        return $this->existingTrackingToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'existing_refund_id' => $this->existingCaseId,
            'existing_tracking_token' => $this->existingTrackingToken,
        ];
    }
}
