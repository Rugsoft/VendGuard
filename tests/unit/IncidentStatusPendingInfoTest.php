<?php

declare(strict_types=1);

/**
 * IncidentStatusPendingInfoTest
 *
 * Suite unitaria del estado operativo "Pendiente de Información" (módulo 11,
 * tarea T-PAUSE-02). Certifica tres cosas que la especificación exige juntas:
 *
 * 1. `IncidentStatus` declara PENDING_INFO como estado activo, no terminal, con
 *    la etiqueta castellana pactada (RF-01.1).
 * 2. La matriz de transiciones permite pausar desde ASSIGNED, IN_PROGRESS,
 *    PENDING_PARTS y REOPENED, y reanudar solo hacia ASSIGNED, IN_PROGRESS o
 *    CANCELLED. La transición directa a RESOLVED queda expresamente prohibida
 *    (RF-06.2, Art. V.1).
 * 3. Existen las cuatro causas tipificadas de pausa en
 *    `IncidentPauseReasonCategory` (RF-01.2).
 *
 * Además guarda la coherencia del propio commit: el guardián de estados acepta
 * las formas bilingües de PENDING_INFO y la conversación no se rompe con el
 * estado nuevo (`IncidentCommentService`, cuyo `match` es exhaustivo).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Service\IncidentStateMachine;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentStatusPendingInfoTest (T-PAUSE-02)\n";
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
// GRUPO 1: El estado existe y se presenta en castellano
// =====================================================================
echo "--- Grupo 1: Declaración del estado PENDING_INFO ---\n";

$assert(
    '1.1 El caso PENDING_INFO existe con el valor escalar PENDING_INFO',
    IncidentStatus::PENDING_INFO->value === 'PENDING_INFO'
);
$assert(
    '1.2 La etiqueta descriptiva es «Pendiente de información»',
    IncidentStatus::PENDING_INFO->label() === 'Pendiente de información',
    IncidentStatus::PENDING_INFO->label()
);
$assert(
    '1.3 El ciclo de vida declara exactamente 9 estados auditados',
    count(IncidentStatus::cases()) === 9,
    'casos: ' . count(IncidentStatus::cases())
);
$assert(
    '1.4 PENDING_INFO convive con los 8 estados previos sin alterarlos',
    IncidentStatus::values() === [
        'REGISTERED',
        'ASSIGNED',
        'IN_PROGRESS',
        'PENDING_PARTS',
        'PENDING_INFO',
        'RESOLVED',
        'REOPENED',
        'CLOSED',
        'CANCELLED',
    ],
    implode(',', IncidentStatus::values())
);
$assert(
    '1.5 isValid()/fromString() reconocen el estado (insensible a mayúsculas y espacios)',
    IncidentStatus::isValid('pending_info') === true
    && IncidentStatus::isValid('  PENDING_INFO ') === true
    && IncidentStatus::fromString('pending_info') === IncidentStatus::PENDING_INFO
);
$assert(
    '1.6 fromString() sigue rechazando estados inexistentes',
    !IncidentStatus::isValid('PENDING_SOMETHING')
);

// =====================================================================
// GRUPO 2: Semántica activa y no terminal (paridad con is_active_ticket)
// =====================================================================
echo "\n--- Grupo 2: Semántica activa y no terminal (RF-01.1) ---\n";

$assert(
    '2.1 isActive() es true: el candado de unicidad por máquina sigue armado',
    IncidentStatus::PENDING_INFO->isActive() === true
);
$assert(
    '2.2 isTerminal() es false: la pausa no cierra el expediente',
    IncidentStatus::PENDING_INFO->isTerminal() === false
);
$assert(
    '2.3 isResolved() es false: no es un estado de resolución',
    IncidentStatus::PENDING_INFO->isResolved() === false
);
$assert(
    '2.4 Solo CLOSED y CANCELLED permanecen inactivos (paridad con MariaDB)',
    count(array_filter(
        IncidentStatus::cases(),
        static fn(IncidentStatus $status): bool => $status->isActive()
    )) === 7
);

// =====================================================================
// GRUPO 3: Matriz de transiciones legales y prohibidas
// =====================================================================
echo "\n--- Grupo 3: Matriz de transiciones (RF-01.1 / RF-06.2) ---\n";

foreach ([
    ['ASSIGNED', IncidentStatus::ASSIGNED],
    ['IN_PROGRESS', IncidentStatus::IN_PROGRESS],
    ['PENDING_PARTS', IncidentStatus::PENDING_PARTS],
    ['REOPENED', IncidentStatus::REOPENED],
] as [$name, $origin]) {
    $assert(
        "3.1-3.4 Pausa legal: {$name} -> PENDING_INFO",
        $origin->canTransitionTo(IncidentStatus::PENDING_INFO) === true
    );
}

$assert(
    '3.5 La pausa NO se permite desde REGISTERED (sin asignación previa)',
    IncidentStatus::REGISTERED->canTransitionTo(IncidentStatus::PENDING_INFO) === false
);
$assert(
    '3.6 La pausa NO se permite desde RESOLVED ni desde CLOSED ni desde CANCELLED',
    IncidentStatus::RESOLVED->canTransitionTo(IncidentStatus::PENDING_INFO) === false
    && IncidentStatus::CLOSED->canTransitionTo(IncidentStatus::PENDING_INFO) === false
    && IncidentStatus::CANCELLED->canTransitionTo(IncidentStatus::PENDING_INFO) === false
);
$assert(
    '3.7 Reanudar desde PENDING_INFO es legal hacia ASSIGNED, IN_PROGRESS y CANCELLED',
    IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::ASSIGNED) === true
    && IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::IN_PROGRESS) === true
    && IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::CANCELLED) === true
);
$assert(
    '3.8 PROHIBIDO resolver directamente desde PENDING_INFO (RF-06.2, Art. V.1)',
    IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::RESOLVED) === false
);
$assert(
    '3.9 PENDING_INFO no reingresa en sí mismo ni salta a CLOSED/REOPENED/PENDING_PARTS',
    IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::PENDING_INFO) === false
    && IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::CLOSED) === false
    && IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::REOPENED) === false
    && IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::PENDING_PARTS) === false
);
$assert(
    '3.10 allowedTransitions() de PENDING_INFO es exactamente [ASSIGNED, IN_PROGRESS, CANCELLED]',
    array_map(
        static fn(IncidentStatus $status): string => $status->value,
        IncidentStatus::PENDING_INFO->allowedTransitions()
    ) === ['ASSIGNED', 'IN_PROGRESS', 'CANCELLED']
);

// =====================================================================
// GRUPO 4: Guardián de estados bilingüe (IncidentStateMachine)
// =====================================================================
echo "\n--- Grupo 4: Guardián de estados bilingüe ---\n";

$assert(
    '4.1 normalizarStatus reconoce la clave canónica y la forma castellana',
    IncidentStateMachine::normalizeStatus('PENDING_INFO') === IncidentStatus::PENDING_INFO
    && IncidentStateMachine::normalizeStatus('PENDIENTE_INFORMACION') === IncidentStatus::PENDING_INFO
    && IncidentStateMachine::normalizeStatus('pendiente_info') === IncidentStatus::PENDING_INFO
);
$assert(
    '4.2 canTransition() acepta el ciclo en ambos idiomas',
    IncidentStateMachine::canTransition('IN_PROGRESS', 'PENDING_INFO') === true
    && IncidentStateMachine::canTransition('PENDIENTE_INFORMACION', 'ASIGNADA') === true
    && IncidentStateMachine::canTransition(IncidentStatus::PENDING_INFO, IncidentStatus::CANCELLED) === true
);
$assert(
    '4.3 isValid() bloquea sin excepción el cierre directo desde la pausa',
    IncidentStateMachine::isValid('PENDING_INFO', 'RESOLVED') === false
    && IncidentStateMachine::isValid('PENDIENTE_INFORMACION', 'RESUELTA') === false
);

$transitionBlocked = false;
$fromCaptured = null;
$toCaptured = null;
try {
    IncidentStateMachine::assertCanTransition('PENDING_INFO', 'RESOLVED');
} catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $exception) {
    $transitionBlocked = true;
    $fromCaptured = $exception->getFromStatus();
    $toCaptured = $exception->getToStatus();
}
$assert(
    '4.4 assertCanTransition lanza InvalidTransitionException con los estados origen y destino',
    $transitionBlocked
    && $fromCaptured === IncidentStatus::PENDING_INFO
    && $toCaptured === IncidentStatus::RESOLVED
);

// =====================================================================
// GRUPO 5: Causas tipificadas de la pausa (RF-01.2)
// =====================================================================
echo "\n--- Grupo 5: Causas tipificadas (IncidentPauseReasonCategory) ---\n";

$expectedReasonCategories = [
    'BUILDING_CLOSED_NO_ACCESS',
    'MACHINE_LOCATION_NOT_FOUND',
    'EXTERNAL_POWER_CUT',
    'PENDING_SITE_AUTHORIZATION',
];
$assert(
    '5.1 Existen exactamente las 4 causas tipificadas pactadas',
    IncidentPauseReasonCategory::values() === $expectedReasonCategories,
    implode(',', IncidentPauseReasonCategory::values())
);
$assert(
    '5.2 Cada causa tiene su etiqueta castellana descriptiva',
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS->label() === 'Edificio cerrado / Sin acceso a instalaciones'
    && IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND->label() === 'Máquina no localizada en la planta o zona indicada'
    && IncidentPauseReasonCategory::EXTERNAL_POWER_CUT->label() === 'Corte eléctrico o de suministro ajeno a la máquina'
    && IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION->label() === 'Pendiente de autorización o contacto de sede'
);
$assert(
    '5.3 isValid()/fromString() normalizan mayúsculas y espacios sobrantes',
    IncidentPauseReasonCategory::isValid('building_closed_no_access') === true
    && IncidentPauseReasonCategory::fromString(' external_power_cut ') === IncidentPauseReasonCategory::EXTERNAL_POWER_CUT
);
$assert(
    '5.4 Una causa inventada se rechaza en isValid() y lanza excepción en fromString()',
    IncidentPauseReasonCategory::isValid('SITE_ON_FIRE') === false
);

$reasonRejected = false;
try {
    IncidentPauseReasonCategory::fromString('SITE_ON_FIRE');
} catch (InvalidArgumentException) {
    $reasonRejected = true;
}
$assert('5.5 fromString() lanza InvalidArgumentException con la causa inválida', $reasonRejected);

// =====================================================================
// GRUPO 6: Coherencia con los consumidores del estado nuevo
// =====================================================================
echo "\n--- Grupo 6: Consumidores alineados con el estado nuevo ---\n";

// El `match` de acceptsNewComments es exhaustivo y sin `default`: si el estado
// nuevo no estuviera contemplado, esta llamada lanzaría UnhandledMatchError. Se
// construye la instancia sin constructor porque el método evaluado es puro
// respecto a la fila (no toca repositorios ni subida de ficheros).
$commentService = (new ReflectionClass(IncidentCommentService::class))->newInstanceWithoutConstructor();
$assert(
    '6.1 La conversación admite comentarios en PENDING_INFO (la respuesta de sede desbloquea)',
    $commentService->acceptsNewComments(['status' => 'PENDING_INFO']) === true
);
$assert(
    '6.2 La conversación sigue sellada en estados terminales',
    $commentService->acceptsNewComments(['status' => 'CANCELLED']) === false
    && $commentService->acceptsNewComments(['status' => 'CLOSED']) === false
);

$activeStatuses = (new ReflectionClass(CoordinatorIncidentDetailService::class))->getConstant('ACTIVE_STATUSES');
$activeValues = is_array($activeStatuses)
    ? array_map(static fn(IncidentStatus $status): string => $status->value, $activeStatuses)
    : [];
$assert(
    '6.3 ACTIVE_STATUSES incluye PENDING_INFO (cancelación tras 72 h y respuesta de sede disponibles)',
    in_array('PENDING_INFO', $activeValues, true),
    implode(',', $activeValues)
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-02 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
