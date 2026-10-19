<?php

declare(strict_types=1);

/**
 * IncidentTicketAllocatorTest
 *
 * Certifica la política única de asignación del código de ticket (EARS 2.4):
 *
 *   1. Sin colisión, el alta se intenta **una sola vez** con el código del dominio.
 *   2. Si el índice único del ticket rechaza el código, se repite la unidad de trabajo
 *      completa con un código **distinto** y se devuelve el expediente del intento que
 *      sí prosperó.
 *   3. La cota es de tres intentos: al tercer choque se lanza
 *      `TicketCodeCollisionException` con el número de intentos consumidos, para que el
 *      cliente reciba un 409 y nunca un 500.
 *   4. Sólo se reintenta la colisión del ticket: un ticket activo en la misma máquina
 *      (`MACHINE_HAS_ACTIVE_INCIDENT`) es una regla de negocio y debe subir tal cual.
 *
 * La suite es puramente de aplicación: no abre conexión ni toca la base.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\IncidentTicketAllocator;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\TicketCodeCollisionException;

echo "======================================================================\n";
echo " VendGuard · G7 · Asignación acotada del código de ticket (EARS 2.4)\n";
echo "======================================================================\n\n";

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

/** Secuencia determinista de códigos: cada llamada entrega el siguiente. */
$sequence = static function (array $codes): callable {
    $remaining = array_values($codes);

    return static function () use (&$remaining): string {
        return $remaining === [] ? 'INC-2026-AGOT' : (string)array_shift($remaining);
    };
};

// ---------------------------------------------------------------------
// CASO 1 · Camino feliz: un solo intento con el código del dominio
// ---------------------------------------------------------------------
echo "--- Caso 1: alta sin colisión ---\n";

$defaultAllocator = new IncidentTicketAllocator();
$attempts = 0;
$defaultTicket = $defaultAllocator->persistWithUniqueTicket(static function (string $ticketCode) use (&$attempts): string {
    $attempts++;

    return $ticketCode;
});
$assert('1.1 El código por defecto respeta el formato INC-AAAA-XXXX', preg_match('/^INC-\d{4}-[A-Z0-9]{4,10}$/', $defaultTicket) === 1, $defaultTicket);
$assert('1.2 Sin colisión la unidad de trabajo se ejecuta una sola vez', $attempts === 1, "intentos: {$attempts}");

// ---------------------------------------------------------------------
// CASO 2 · Colisión del ticket: reintento con un código nuevo
// ---------------------------------------------------------------------
echo "\n--- Caso 2: una colisión, un reintento ---\n";

$allocator = new IncidentTicketAllocator($sequence(['INC-2026-RACE', 'INC-2026-FRSH']));
$seen = [];
$result = $allocator->persistWithUniqueTicket(static function (string $ticketCode) use (&$seen): string {
    $seen[] = $ticketCode;
    if (count($seen) === 1) {
        throw new TicketCodeCollisionException($ticketCode);
    }

    return "persistido:{$ticketCode}";
});
$assert('2.1 El reintento usa un código distinto del rechazado', $seen === ['INC-2026-RACE', 'INC-2026-FRSH'], implode(' → ', $seen));
$assert('2.2 El resultado es el del intento que prosperó', $result === 'persistido:INC-2026-FRSH', (string)$result);

// ---------------------------------------------------------------------
// CASO 3 · Cota de tres intentos
// ---------------------------------------------------------------------
echo "\n--- Caso 3: tres colisiones seguidas ---\n";

$exhaustedAttempts = 0;
$allocatorExhausted = new IncidentTicketAllocator($sequence(['INC-2026-SAME', 'INC-2026-SAME', 'INC-2026-SAME']));
$collision = null;
try {
    $allocatorExhausted->persistWithUniqueTicket(static function (string $ticketCode) use (&$exhaustedAttempts): string {
        $exhaustedAttempts++;

        throw new TicketCodeCollisionException($ticketCode);
    });
} catch (TicketCodeCollisionException $exception) {
    $collision = $exception;
}

$assert(
    '3.1 La política se detiene exactamente en el tercer intento',
    $exhaustedAttempts === IncidentTicketAllocator::MAX_ATTEMPTS && $exhaustedAttempts === 3,
    "intentos: {$exhaustedAttempts}"
);
$assert(
    '3.2 Al agotarse se lanza la colisión con el 409 y los intentos consumidos',
    $collision instanceof TicketCodeCollisionException
        && $collision->getErrorCode() === 'TICKET_CODE_COLLISION'
        && $collision->getHttpStatusCode() === 409
        && $collision->getAttempts() === 3
        && $collision->getAttemptedTicketCode() === 'INC-2026-SAME',
    $collision === null ? 'no se lanzó excepción' : "attempts={$collision->getAttempts()} code={$collision->getAttemptedTicketCode()}"
);

// ---------------------------------------------------------------------
// CASO 4 · Lo que NO se reintenta
// ---------------------------------------------------------------------
echo "\n--- Caso 4: un duplicado de máquina no se reintenta ---\n";

$businessAttempts = 0;
$allocatorBusiness = new IncidentTicketAllocator($sequence(['INC-2026-AAAA', 'INC-2026-BBBB', 'INC-2026-CCCC']));
$propagated = null;
try {
    $allocatorBusiness->persistWithUniqueTicket(static function (string $ticketCode) use (&$businessAttempts): string {
        $businessAttempts++;

        throw new DuplicateIncidentException(
            'MACHINE_HAS_ACTIVE_INCIDENT',
            'La máquina ya tiene un aviso activo.',
            'INC-2026-0001',
            'REGISTERED'
        );
    });
} catch (DuplicateIncidentException $exception) {
    $propagated = $exception;
}

$assert(
    '4.1 El ticket activo de la misma máquina sube en el primer intento, sin reintento',
    $businessAttempts === 1 && $propagated instanceof DuplicateIncidentException
        && $propagated->getErrorCode() === 'MACHINE_HAS_ACTIVE_INCIDENT',
    "intentos: {$businessAttempts}"
);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. POLÍTICA DE TICKET CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
