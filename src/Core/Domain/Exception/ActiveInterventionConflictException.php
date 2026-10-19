<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ActiveInterventionConflictException
 *
 * El técnico mantiene una intervención en curso en **otra sede** (una incidencia
 * en `IN_PROGRESS` o una orden preventiva `IN_INSPECTION`) y pretende abrir o
 * asumir una avería oportunista en la sede donde está ahora (Módulo 16, RF-02.2).
 *
 * Coexistir con varias intervenciones en la **misma** sede es legítimo; dejar una
 * abierta en otro edificio no lo es: falsearía el tiempo real de intervención.
 *
 * Mapea al código HTTP 409 y transporta los datos del expediente en conflicto
 * para que la interfaz pueda mostrar dónde está el trabajo sin abrir una consulta
 * adicional.
 */
class ActiveInterventionConflictException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION';

    private string $errorCode;
    private int $httpStatusCode;

    /** @var array<string, mixed> */
    private array $conflict;

    /**
     * @param array<string, mixed> $conflict Datos del expediente u orden abierta en la otra sede
     *                                        (ticket, sede, estado, tipo de registro).
     */
    public function __construct(
        array $conflict = [],
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $ticket = isset($conflict['ticket_code']) && $conflict['ticket_code'] !== null
                ? sprintf(' (expediente %s)', (string)$conflict['ticket_code'])
                : '';
            $message = 'No se puede iniciar una intervención oportunista: el técnico mantiene una intervención en curso en otra sede'
                . $ticket . '. Finalice o pause ese trabajo antes de continuar.';
        }

        parent::__construct($message, $httpStatusCode);
        $this->conflict = $conflict;
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

    /** @return array<string, mixed> */
    public function getConflict(): array
    {
        return $this->conflict;
    }
}
