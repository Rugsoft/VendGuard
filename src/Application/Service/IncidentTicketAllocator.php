<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Exception\TicketCodeCollisionException;
use VendGuard\Core\Domain\ValueObject\TicketCode;

/**
 * IncidentTicketAllocator — política única de asignación del código de ticket.
 *
 * POR QUÉ EXISTE
 *
 *   El código de ticket (`INC-AAAA-XXXX`) se genera al azar sobre un espacio finito
 *   y el índice único `uq_incidents_ticket_code` es quien garantiza que nunca se
 *   repita. Hasta ahora, cuando dos altas simultáneas caían en el mismo código, la
 *   segunda moría como `PDOException` y el ciudadano o el técnico recibían un `500`
 *   en el portal de sede, en el QR o en el puente preventivo. La colisión no es un
 *   error de negocio: es una señal de «prueba otro código», y eso es lo que hace
 *   esta clase (EARS 2.4).
 *
 * DÓNDE VIVE EL REINTENTO, Y POR QUÉ AQUÍ
 *
 *   En la capa de aplicación y no en el repositorio, por una razón del motor: en
 *   PostgreSQL, un error dentro de una transacción la deja abortada hasta que se
 *   deshace. Reintentar la escritura suelta dentro del repositorio exigiría
 *   `SAVEPOINT` y dejaría la política escondida en infraestructura; reintentar la
 *   **unidad de trabajo completa** desde aquí es lo que hace la operación atómica de
 *   verdad. Cada intento reconstruye el expediente con su código y vuelve a ejecutar
 *   el cierre que le entrega el llamador —que incluye la transacción, el historial y
 *   el encolado de la notificación—, de modo que el reintento no puede dejar un
 *   aviso a medias ni duplicar la notificación: el encolado sólo ocurre después de
 *   que el alta se confirma.
 *
 *   Lo que NO se reintenta es igual de importante: sólo se captura
 *   `TicketCodeCollisionException`. Un ticket activo en la misma máquina
 *   (`MACHINE_HAS_ACTIVE_INCIDENT`) es una regla de negocio (EARS 2.1) y debe subir
 *   como 409 con el expediente existente, no reintentarse con otro código.
 *
 * COTA
 *
 *   Tres intentos (EARS 2.4). Si los tres chocan, se lanza la colisión con el número
 *   de intentos consumidos y el controlador responde `409 TICKET_CODE_COLLISION`.
 *   Rendirse en vez de reintentar sin fin es deliberado: un espacio de códigos
 *   agotado es un problema operativo que debe verse, no esconderse en un bucle.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */
final class IncidentTicketAllocator
{
    /** Cota de intentos por alta (EARS 2.4). */
    public const MAX_ATTEMPTS = TicketCodeCollisionException::DEFAULT_ATTEMPTS;

    /**
     * Fuente de códigos. En producción es `TicketCode::generate()`; las pruebas
     * inyectan una secuencia determinista para que la carrera sea reproducible.
     *
     * @var callable(): string|null
     */
    private readonly mixed $codeGenerator;

    /**
     * @param callable(): string|null $codeGenerator Fuente de códigos (por defecto, la aleatoria del dominio).
     */
    public function __construct(?callable $codeGenerator = null)
    {
        $this->codeGenerator = $codeGenerator;
    }

    /**
     * Ejecuta el alta con un código de ticket único, reintentando la operación
     * completa mientras la colisión venga del índice del ticket.
     *
     * @template T
     * @param callable(string): T $persist Recibe el código candidato y devuelve el
     *        expediente persistido, con la unidad de trabajo ya confirmada.
     * @return T
     * @throws TicketCodeCollisionException Si se agotan los intentos.
     */
    public function persistWithUniqueTicket(callable $persist): mixed
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            $candidate = $this->nextTicketCode();

            try {
                return $persist($candidate);
            } catch (TicketCodeCollisionException $collision) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    throw new TicketCodeCollisionException(
                        $collision->getAttemptedTicketCode(),
                        $attempts
                    );
                }
            }
        }
    }

    /**
     * Siguiente código candidato: el del dominio o el que inyecte la prueba.
     */
    private function nextTicketCode(): string
    {
        if ($this->codeGenerator !== null) {
            $generated = ($this->codeGenerator)();
            if (is_string($generated) && $generated !== '') {
                return $generated;
            }
        }

        return TicketCode::generate()->value();
    }
}
