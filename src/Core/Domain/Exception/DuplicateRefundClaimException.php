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
 * ## Por qué NO transporta el token de seguimiento
 * Se llevó en una versión anterior y fue un agujero de Credential Disclosure:
 * el token es la llave del expediente, porque con él se lee el PIN que abre el
 * sobre en la conserjería y se rectifica el teléfono de Bizum. Como el rechazo
 * se responde por un endpoint PÚBLICO, devolverlo entregaba la llave a quien
 * hubiera acertado con el número de teléfono de otra persona, que es un dato
 * adivinable y que el propio mensaje de rechazo le confirma.
 *
 * Lo que sí viaja es el identificador del expediente, que no es credencial: le
 * dice a la persona que su reclamación está en marcha y le permite pedir ayuda
 * en la conserjería. Para recuperar el acceso, el mensaje la remite al enlace
 * que recibió al abrir la reclamación; un token entregado a un desconocido es
 * un reembolso robado (RF-REF-11).
 *
 * Mapea directamente al código HTTP 409 Conflict.
 */
class DuplicateRefundClaimException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'DUPLICATE_REFUND_CLAIM';
    public const DEFAULT_MESSAGE = 'Ya tienes una solicitud de reintegro en marcha sobre esta avería. Consulta el enlace de seguimiento que recibiste al abrirla para seguirla.';

    public function __construct(
        private readonly int $existingCaseId,
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
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        // Deliberately no tracking token here: see the class docblock. The
        // details of an unauthenticated response are read by whoever asked.
        return [
            'existing_refund_id' => $this->existingCaseId,
        ];
    }
}
