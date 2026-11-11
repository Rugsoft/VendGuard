<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * RestockerCannotBeOnCallException
 *
 * Excepción de dominio lanzada cuando se intenta marcar la guardia 24/7
 * (`is_on_call = TRUE`) sobre un usuario `RESTOCKER`, o cuando el alta de una
 * cuenta de reposición lo envía encendido (Módulo 31, `D3`, RF-01.1).
 *
 * La guardia de emergencias térmicas es una condición exclusiva del Técnico
 * SAT (Art. II). Un reponedor no atiende averías electromecánicas y, si
 * apareciera en la lista de guardia, las alertas críticas de frío quedarían
 * apuntando a quien no puede resolverlas. La base de datos ya lo impide con
 * `chk_users_restocker_not_on_call`; esta excepción da el 422 de negocio antes
 * de llegar al motor.
 *
 * Mapea al código HTTP 422 Unprocessable Content con el error
 * `RESTOCKER_CANNOT_BE_ON_CALL` del contrato del módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class RestockerCannotBeOnCallException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'RESTOCKER_CANNOT_BE_ON_CALL';

    public function __construct(
        private readonly ?string $operatorReference = null,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Un reponedor de ruta no puede estar de guardia%s: la guardia 24/7 de emergencias térmicas '
                . 'es exclusiva de los técnicos SAT.',
                $operatorReference !== null && $operatorReference !== ''
                    ? " ({$operatorReference})"
                    : ''
            ),
            self::HTTP_STATUS
        );
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getOperatorReference(): ?string
    {
        return $this->operatorReference;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['operator_reference' => $this->operatorReference];
    }
}
