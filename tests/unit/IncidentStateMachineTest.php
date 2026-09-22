<?php

declare(strict_types=1);

/**
 * IncidentStateMachineTest
 * 
 * Suite de pruebas unitarias para la máquina de estados de incidencias (T-10).
 * Valida el grafo de transiciones legales y el lanzamiento de InvalidTransitionException.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\IncidentStateMachine;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - IncidentStateMachineTest (T-10)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

// =====================================================================
// GRUPO 1: Transiciones Legales del Grafo
// =====================================================================
echo "--- Grupo 1: Transiciones legales permitidas ---\n";

$legalTransitions = [
    // Origen, Destino, Descripción
    [IncidentStatus::REGISTERED, IncidentStatus::ASSIGNED, 'REGISTRADA -> ASIGNADA (Asignación inicial de técnico)'],
    [IncidentStatus::REGISTERED, IncidentStatus::CANCELLED, 'REGISTRADA -> CANCELADA (Descarte temprano por coordinador)'],
    [IncidentStatus::ASSIGNED, IncidentStatus::IN_PROGRESS, 'ASIGNADA -> EN_CURSO (Técnico inicia intervención in situ)'],
    [IncidentStatus::ASSIGNED, IncidentStatus::ASSIGNED, 'ASIGNADA -> ASIGNADA (Reasignación a otro técnico)'],
    [IncidentStatus::ASSIGNED, IncidentStatus::CANCELLED, 'ASIGNADA -> CANCELADA (Descarte por máquina retirada)'],
    [IncidentStatus::IN_PROGRESS, IncidentStatus::PENDING_PARTS, 'EN_CURSO -> PENDIENTE_REPUESTO (Pausa por falta de recambio)'],
    [IncidentStatus::IN_PROGRESS, IncidentStatus::RESOLVED, 'EN_CURSO -> RESUELTA (Técnico cierra con diagnóstico y acción)'],
    [IncidentStatus::IN_PROGRESS, IncidentStatus::CANCELLED, 'EN_CURSO -> CANCELADA (Máquina dada de baja en taller)'],
    [IncidentStatus::PENDING_PARTS, IncidentStatus::IN_PROGRESS, 'PENDIENTE_REPUESTO -> EN_CURSO (Reanudación tras recibir pieza)'],
    [IncidentStatus::PENDING_PARTS, IncidentStatus::CANCELLED, 'PENDIENTE_REPUESTO -> CANCELADA (Repuesto obsoleto/descarte)'],
    [IncidentStatus::RESOLVED, IncidentStatus::CLOSED, 'RESUELTA -> CERRADA (Cierre definitivo tras 48h sin reapertura)'],
    [IncidentStatus::RESOLVED, IncidentStatus::REOPENED, 'RESUELTA -> REABIERTA (Cliente reabre en ventana de 48h)'],
    [IncidentStatus::REOPENED, IncidentStatus::ASSIGNED, 'REABIERTA -> ASIGNADA (Coordinador reasigna incidencia reabierta)'],
    [IncidentStatus::REOPENED, IncidentStatus::IN_PROGRESS, 'REABIERTA -> EN_CURSO (Técnico retoma intervención directa)'],
    [IncidentStatus::REOPENED, IncidentStatus::CANCELLED, 'REABIERTA -> CANCELADA (Resolución confirmada o error de cliente)'],
];

foreach ($legalTransitions as [$from, $to, $desc]) {
    try {
        $allowed = IncidentStateMachine::canTransition($from, $to);
        $assert("1." . $assertions . " Legal: {$desc}", $allowed === true);
    } catch (Throwable $e) {
        $assert("1." . $assertions . " Legal: {$desc}", false, $e->getMessage());
    }
}

// =====================================================================
// GRUPO 2: Transiciones Ilegales y Lanzamiento de InvalidTransitionException
// =====================================================================
echo "\n--- Grupo 2: Transiciones no autorizadas (Excepción obligatoria) ---\n";

$illegalTransitions = [
    ['REGISTRADA', 'RESUELTA', 'Salto prohibido directo de REGISTRADA a RESUELTA (ejemplo de tarea)'],
    ['REGISTERED', 'IN_PROGRESS', 'Salto prohibido de REGISTERED a IN_PROGRESS (sin asignación previa)'],
    ['REGISTERED', 'CLOSED', 'Salto prohibido de REGISTERED a CLOSED'],
    ['CLOSED', 'IN_PROGRESS', 'Intento de modificar un estado terminal CLOSED'],
    ['CLOSED', 'REOPENED', 'Intento de reabrir una incidencia ya CERRADA definitivamente'],
    ['CANCELLED', 'ASSIGNED', 'Intento de asignar una incidencia ya CANCELADA'],
    ['CANCELLED', 'IN_PROGRESS', 'Intento de intervenir una incidencia CANCELADA'],
    ['RESOLVED', 'IN_PROGRESS', 'Intento de pasar de RESUELTA a EN_CURSO sin pasar por REABIERTA'],
    ['PENDING_PARTS', 'RESOLVED', 'Intento de resolver directamente desde PENDIENTE_REPUESTO sin reanudar'],
];

foreach ($illegalTransitions as [$from, $to, $desc]) {
    $caughtExpectedException = false;
    $fromStatusCaptured = null;
    $toStatusCaptured = null;

    try {
        IncidentStateMachine::canTransition($from, $to);
    } catch (InvalidTransitionException $e) {
        $caughtExpectedException = true;
        $fromStatusCaptured = $e->getFromStatus();
        $toStatusCaptured = $e->getToStatus();
    } catch (Throwable $e) {
        // Excepción errónea no esperada
    }

    $assert("2." . $assertions . " Bloqueo: {$desc}", $caughtExpectedException);
    $assert("2." . $assertions . " isValid() devuelve false para {$from} -> {$to}", !IncidentStateMachine::isValid($from, $to));
}

// =====================================================================
// GRUPO 3: Dualidad Lingüística (Castellano e Inglés)
// =====================================================================
echo "\n--- Grupo 3: Compatibilidad de terminología (ES / EN) ---\n";

$assert(
    "3.1 canTransition('REGISTRADA', 'ASIGNADA') en castellano devuelve true",
    IncidentStateMachine::canTransition('REGISTRADA', 'ASIGNADA') === true
);
$assert(
    "3.2 canTransition('REGISTERED', 'ASSIGNED') en inglés devuelve true",
    IncidentStateMachine::canTransition('REGISTERED', 'ASSIGNED') === true
);

$caughtSpanishInvalid = false;
try {
    IncidentStateMachine::canTransition('REGISTRADA', 'RESUELTA');
} catch (InvalidTransitionException $e) {
    $caughtSpanishInvalid = true;
}
$assert(
    "3.3 canTransition('REGISTRADA', 'RESUELTA') en castellano lanza InvalidTransitionException",
    $caughtSpanishInvalid
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-10 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
