<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * TicketCodeCollisionException
 *
 * El índice único `uq_incidents_ticket_code` rechazó el código con el que un alta
 * pretendía nacer: otra escritura ya lo reservó (confirmada, o en vuelo y todavía
 * sin confirmar). **No** es un duplicado de máquina —eso es
 * `DuplicateIncidentException`— sino un choque de identidad del propio expediente.
 *
 * Por qué existe como excepción propia: el código de ticket se genera al azar sobre
 * un espacio finito (`TicketCode::generate()`), así que la colisión no es un error
 * de negocio que deba contarse al usuario, es una señal para **volver a intentar el
 * alta con un código nuevo** (EARS 2.4). La política de reintento vive en la capa de
 * aplicación (`IncidentTicketAllocator`), y sólo puede hacerlo si distingue esta
 * colisión de cualquier otra: de ahí que el repositorio no la disfrace de duplicado.
 *
 * Cuando el reintento acotado se agota, esta excepción es la que llega al cliente:
 * `409 Conflict` con `TICKET_CODE_COLLISION`, nunca un `500`. El número de intentos
 * viaja en los detalles para que el cliente sepa que no es un fallo transitorio de
 * red, sino un espacio de códigos agotado en ese instante.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */
final class TicketCodeCollisionException extends DomainException
{
    /** Código estable que viaja en el envolvente de error. */
    public const ERROR_CODE = 'TICKET_CODE_COLLISION';

    /** Estado HTTP del conflicto de identidad del expediente. */
    public const HTTP_STATUS = 409;

    /** Intentos por defecto que la política de alta declara como cota. */
    public const DEFAULT_ATTEMPTS = 3;

    /**
     * @param string $attemptedTicketCode Último código rechazado por el índice único.
     * @param int $attempts Intentos consumidos por la política de alta.
     */
    public function __construct(
        private readonly string $attemptedTicketCode,
        private readonly int $attempts = self::DEFAULT_ATTEMPTS
    ) {
        parent::__construct(
            'No se pudo reservar un código de ticket único tras varios intentos. Vuelva a enviar el aviso.',
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

    /**
     * Último código rechazado. No es secreto: viaja en la auditoría del alta fallida,
     * no en la respuesta pública.
     */
    public function getAttemptedTicketCode(): string
    {
        return $this->attemptedTicketCode;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Detalles públicos del conflicto (contrato §3.2).
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['attempts' => $this->attempts];
    }
}
