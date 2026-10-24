<?php

declare(strict_types=1);

/**
 * OpportunisticStateTransitionsTest · T-OPP-03
 *
 * Certifica, sin motor y sin red, los modelos de dominio que el Módulo 16
 * introduce antes de que exista una sola ruta HTTP:
 *
 *   1. **Modalidad, submotivo y acción oportunista.** Las tres listas cerradas
 *      con sus etiquetas canónicas en castellano, y la regla de coherencia
 *      modalidad ↔ submotivo escrita una sola vez (`isAllowedFor`), que es la
 *      misma que impone `chk_incidents_opportunistic_coherence`.
 *   2. **Catálogo de plantillas.** Las 22 plantillas y la prioridad sanitaria de
 *      la alerta de parada crítica postergada (Art. II).
 *   3. **Hidratación y serialización de `Incident`.** Un expediente ordinario
 *      (sin marcas oportunistas) sigue leyéndose y serializándose igual; uno
 *      oportunista expone valor y etiqueta; y el nombre del informante en sede
 *      se recorta y se descarta cuando llega vacío.
 *   4. **Validación fail-fast.** La entidad rechaza una modalidad sin submotivo,
 *      un submotivo sin modalidad, una pareja incoherente y un informante
 *      desmesurado antes de que el motor tenga que decirlo por un SQLSTATE.
 *   5. **Excepciones tipadas.** Código, estado HTTP, mensaje por defecto y carga
 *      útil (conflicto, paradas postergadas y técnico saliente) de las cuatro
 *      excepciones del módulo.
 *
 * QUÉ NO SE CERTIFICA AQUÍ: las transiciones de estado de la base de datos ni el
 * comportamiento de los endpoints, que pertenecen a T-OPP-05 a T-OPP-09.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\ActiveInterventionConflictException;
use VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException;
use VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException;
use VendGuard\Core\Domain\Exception\TakeoverConfirmationRequiredException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\OpportunisticActionType;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

$assertions = 0;
$failures = 0;

$assert = static function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($condition ? '  [PASS] ' : '  [FAIL] ') . $title . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$condition) {
        $failures++;
    }
};

/** @return array<string, mixed> */
$legacyRow = static function (array $overrides = []): array {
    return array_merge([
        'id' => 12,
        'ticket_code' => 'INC-2026-9001',
        'machine_id' => 3,
        'location_id' => 1,
        'category' => 'PRODUCT_JAM',
        'description' => 'Atasco en la espiral B4 detectado durante la visita.',
        'urgency' => 'HIGH',
        'status' => 'IN_PROGRESS',
        'created_at' => '2026-10-19 07:30:00+00',
        'updated_at' => '2026-10-19 07:30:00+00',
    ], $overrides);
};

echo "======================================================================\n";
echo " VENDGUARD · T-OPP-03 · Modelos de dominio de la avería oportunista\n";
echo "======================================================================\n\n";

// =====================================================================
// 1. Modalidad, submotivo y etiquetas canónicas
// =====================================================================
echo "--- 1. Modalidad y submotivo de origen ---\n";

$assert(
    '1.1 opportunistic_mode declara NEW y ASSUMED',
    array_map(static fn (OpportunisticMode $mode): string => $mode->value, OpportunisticMode::cases()) === ['NEW', 'ASSUMED']
);

$assert(
    '1.2 La modalidad nueva se rotula «Oportunista In Situ · Nueva»',
    OpportunisticMode::NEW->label() === 'Oportunista In Situ · Nueva',
    OpportunisticMode::NEW->label()
);

$assert(
    '1.3 La modalidad asumida se rotula «Oportunista In Situ · Asumida»',
    OpportunisticMode::ASSUMED->label() === 'Oportunista In Situ · Asumida',
    OpportunisticMode::ASSUMED->label()
);

$assert(
    '1.4 Los cuatro submotivos declaran sus etiquetas canónicas',
    array_map(static fn (OpportunisticOriginReason $reason): string => $reason->label(), OpportunisticOriginReason::cases()) === [
        'Aviso verbal en sede',
        'Detección propia del técnico',
        'Asunción de ticket abierto in situ',
        'Reapertura en garantía in situ',
    ]
);

$assert(
    '1.5 fromString normaliza espacios y minúsculas',
    OpportunisticMode::fromString('  assumed ') === OpportunisticMode::ASSUMED
        && OpportunisticOriginReason::fromString(' technician_detection ') === OpportunisticOriginReason::TECHNICIAN_DETECTION
);

$assert(
    '1.6 isValid distingue el catálogo cerrado de un valor inventado',
    OpportunisticMode::isValid('new') && !OpportunisticMode::isValid('reopened')
        && OpportunisticOriginReason::isValid('warranty_reopen_in_situ')
        && !OpportunisticOriginReason::isValid('CUSTOMER_CALL')
);

$invalidModeRejected = false;
try {
    OpportunisticMode::fromString('PARTIAL');
} catch (InvalidArgumentException) {
    $invalidModeRejected = true;
}
$assert('1.7 Una modalidad fuera del catálogo lanza InvalidArgumentException', $invalidModeRejected);

$invalidReasonRejected = false;
try {
    OpportunisticOriginReason::fromString('UNKNOWN_REASON');
} catch (InvalidArgumentException) {
    $invalidReasonRejected = true;
}
$assert('1.8 Un submotivo fuera del catálogo lanza InvalidArgumentException', $invalidReasonRejected);

// =====================================================================
// 2. Coherencia modalidad ↔ submotivo (RF-04.1)
// =====================================================================
echo "\n--- 2. Coherencia modalidad ↔ submotivo ---\n";

$acceptedPairs = [
    ['NEW', 'SITE_VERBAL_NOTICE'],
    ['NEW', 'TECHNICIAN_DETECTION'],
    ['NEW', 'WARRANTY_REOPEN_IN_SITU'],
    ['ASSUMED', 'EXISTING_TICKET_CLAIM'],
    ['ASSUMED', 'WARRANTY_REOPEN_IN_SITU'],
];

foreach ($acceptedPairs as [$modeValue, $reasonValue]) {
    $mode = OpportunisticMode::fromString($modeValue);
    $reason = OpportunisticOriginReason::fromString($reasonValue);
    $assert(
        "2.x Se admite {$modeValue} + {$reasonValue}",
        $reason->isAllowedFor($mode),
        $reason->label()
    );
}

$rejectedPairs = [
    ['NEW', 'EXISTING_TICKET_CLAIM'],
    ['ASSUMED', 'SITE_VERBAL_NOTICE'],
    ['ASSUMED', 'TECHNICIAN_DETECTION'],
];

foreach ($rejectedPairs as [$modeValue, $reasonValue]) {
    $mode = OpportunisticMode::fromString($modeValue);
    $reason = OpportunisticOriginReason::fromString($reasonValue);
    $assert(
        "2.y Se rechaza {$modeValue} + {$reasonValue}",
        !$reason->isAllowedFor($mode),
        $reason->label()
    );
}

$assert(
    '2.3 La modalidad nueva admite tres motivos y la asumida dos',
    count(OpportunisticOriginReason::allowedFor(OpportunisticMode::NEW)) === 3
        && count(OpportunisticOriginReason::allowedFor(OpportunisticMode::ASSUMED)) === 2
);

$assert(
    '2.4 La reapertura en garantía es el único motivo compartido',
    in_array(OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU, OpportunisticOriginReason::allowedFor(OpportunisticMode::NEW), true)
        && in_array(OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU, OpportunisticOriginReason::allowedFor(OpportunisticMode::ASSUMED), true)
);

// =====================================================================
// 3. Acción oportunista del catálogo de sede
// =====================================================================
echo "\n--- 3. Acción oportunista del catálogo ---\n";

$assert(
    '3.1 El catálogo declara las seis acciones del contrato',
    array_map(static fn (OpportunisticActionType $action): string => $action->value, OpportunisticActionType::cases()) === [
        'CREATE_NEW',
        'ASSUME_DIRECT',
        'ASSUME_WITH_CONFIRMATION',
        'REOPEN_IN_SITU',
        'CURRENT_TECHNICIAN_IN_PROGRESS',
        'BLOCKED_OTHER_TECH_IN_PROGRESS',
    ]
);

$unavailableActions = array_values(array_filter(
    OpportunisticActionType::cases(),
    static fn (OpportunisticActionType $action): bool => !$action->isAvailable()
));
$assert(
    '3.2 Sólo la intervención en curso de otro técnico queda deshabilitada (Art. V.3)',
    $unavailableActions === [OpportunisticActionType::BLOCKED_OTHER_TECH_IN_PROGRESS]
);

$assert(
    '3.3 Sólo la asunción de un ticket ajeno exige confirmación',
    OpportunisticActionType::ASSUME_WITH_CONFIRMATION->requiresConfirmation()
        && !OpportunisticActionType::ASSUME_DIRECT->requiresConfirmation()
        && !OpportunisticActionType::REOPEN_IN_SITU->requiresConfirmation()
);

$assert(
    '3.4 El alta de un expediente nuevo se identifica como creación (Flujo A)',
    OpportunisticActionType::CREATE_NEW->isCreation()
        && !OpportunisticActionType::ASSUME_DIRECT->isCreation()
);

$assert(
    '3.5 Las etiquetas de acción que lee el técnico son las del diseño',
    OpportunisticActionType::CREATE_NEW->label() === 'Oportunista In Situ · Nueva'
        && OpportunisticActionType::REOPEN_IN_SITU->label() === 'Reabrir in situ'
        && OpportunisticActionType::BLOCKED_OTHER_TECH_IN_PROGRESS->label() === 'En curso por otro técnico'
);

// =====================================================================
// 4. Catálogo de plantillas de notificación
// =====================================================================
echo "\n--- 4. Catálogo de plantillas ---\n";

$assert(
    '4.1 El catálogo cerrado declara 22 plantillas',
    count(NotificationTemplate::cases()) === 22,
    (string)count(NotificationTemplate::cases())
);

$assert(
    '4.2 Las dos plantillas del módulo 16 están en el catálogo',
    NotificationTemplate::tryFrom('OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT') !== null
        && NotificationTemplate::tryFrom('INCIDENT_REOPENED_IN_SITU_SITE') !== null
);

$assert(
    '4.3 La alerta de parada crítica postergada tiene prioridad sanitaria (Art. II)',
    NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT->defaultPriority() === NotificationPriority::CRITICAL_SANITARY
);

$assert(
    '4.4 El aviso de reapertura in situ a la Sede es ordinario',
    NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE->defaultPriority() === NotificationPriority::STANDARD
);

$assert(
    '4.5 Ambos rótulos describen el hecho notificado',
    NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT->label() === 'Alerta a Coordinación por Posponer una Parada Crítica de Frío'
        && NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE->label() === 'Aviso a Sede de Reapertura In Situ'
);

// =====================================================================
// 5. Incident: hidratación, serialización y validación fail-fast
// =====================================================================
echo "\n--- 5. Entidad Incident ---\n";

/**
 * Argumentos nombrados de un expediente válido: los ocho obligatorios del
 * constructor y el resto por defecto. Se esparecen con nombres para que la
 * prueba siga siendo legible aunque la entidad gane columnas.
 *
 * @return array<string, mixed>
 */
$incidentArgs = static function (array $overrides = []): array {
    return array_merge([
        'id' => null,
        'ticketCode' => 'INC-2026-9100',
        'machineId' => 1,
        'locationId' => 1,
        'category' => IncidentCategory::PRODUCT_JAM,
        'description' => 'Descripción de prueba suficientemente larga del síntoma observado.',
        'urgency' => UrgencyLevel::HIGH,
        'status' => IncidentStatus::IN_PROGRESS,
    ], $overrides);
};

$legacyIncident = Incident::fromDatabaseRow($legacyRow());
$assert(
    '5.1 Un expediente ordinario se hidrata sin marcas oportunistas',
    $legacyIncident->getOpportunisticMode() === null
        && $legacyIncident->getOpportunisticOriginReason() === null
        && $legacyIncident->getOpportunisticInformantName() === null
        && $legacyIncident->getChronicPredecessorIncidentId() === null
        && $legacyIncident->isOpportunistic() === false
);

$legacyPayload = $legacyIncident->toArray();
$assert(
    '5.2 La serialización de un expediente ordinario expone las claves en null',
    array_key_exists('opportunistic_mode', $legacyPayload)
        && $legacyPayload['opportunistic_mode'] === null
        && $legacyPayload['opportunistic_mode_label'] === null
        && $legacyPayload['opportunistic_origin_reason'] === null
        && $legacyPayload['opportunistic_origin_label'] === null
        && $legacyPayload['opportunistic_informant_name'] === null
        && $legacyPayload['chronic_predecessor_incident_id'] === null
        && $legacyPayload['is_opportunistic'] === false
);

$opportunisticIncident = Incident::fromDatabaseRow($legacyRow([
    'id' => 77,
    'ticket_code' => 'INC-2026-9077',
    'status' => 'IN_PROGRESS',
    'opportunistic_mode' => 'NEW',
    'opportunistic_origin_reason' => 'SITE_VERBAL_NOTICE',
    'opportunistic_informant_name' => '  Marta Gómez (Conserjería)  ',
    'chronic_predecessor_incident_id' => null,
]));

$assert(
    '5.3 La hidratación reconstruye los Value Objects desde las columnas',
    $opportunisticIncident->getOpportunisticMode() === OpportunisticMode::NEW
        && $opportunisticIncident->getOpportunisticOriginReason() === OpportunisticOriginReason::SITE_VERBAL_NOTICE
);

$assert(
    '5.4 El nombre del informante se recorta al hidratar',
    $opportunisticIncident->getOpportunisticInformantName() === 'Marta Gómez (Conserjería)',
    (string)$opportunisticIncident->getOpportunisticInformantName()
);

$opportunisticPayload = $opportunisticIncident->toArray();
$assert(
    '5.5 La serialización expone valor y etiqueta de modalidad y submotivo',
    $opportunisticPayload['opportunistic_mode'] === 'NEW'
        && $opportunisticPayload['opportunistic_mode_label'] === 'Oportunista In Situ · Nueva'
        && $opportunisticPayload['opportunistic_origin_reason'] === 'SITE_VERBAL_NOTICE'
        && $opportunisticPayload['opportunistic_origin_label'] === 'Aviso verbal en sede'
        && $opportunisticPayload['is_opportunistic'] === true
);

$successorIncident = Incident::fromDatabaseRow($legacyRow([
    'id' => 78,
    'ticket_code' => 'INC-2026-9078',
    'opportunistic_mode' => 'NEW',
    'opportunistic_origin_reason' => 'WARRANTY_REOPEN_IN_SITU',
    'chronic_predecessor_incident_id' => '77',
]));

$assert(
    '5.6 El sucesor crónico hidrata su predecesor como entero',
    $successorIncident->getChronicPredecessorIncidentId() === 77
        && $successorIncident->toArray()['chronic_predecessor_incident_id'] === 77
);

$assert(
    '5.7 Un informante vacío se normaliza a null, no a cadena vacía',
    Incident::fromDatabaseRow($legacyRow([
        'opportunistic_mode' => 'NEW',
        'opportunistic_origin_reason' => 'TECHNICIAN_DETECTION',
        'opportunistic_informant_name' => '   ',
    ]))->getOpportunisticInformantName() === null
);

$thrower = static function (callable $factory): ?Throwable {
    try {
        $factory();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
};

$modeWithoutReason = $thrower(static fn (): Incident => new Incident(...$incidentArgs([
    'opportunisticMode' => OpportunisticMode::NEW,
])));
$assert(
    '5.8 Una modalidad sin submotivo se rechaza en el dominio',
    $modeWithoutReason instanceof InvalidArgumentException,
    $modeWithoutReason?->getMessage() ?? 'no se lanzó'
);

$reasonWithoutMode = $thrower(static fn (): Incident => new Incident(...$incidentArgs([
    'opportunisticOriginReason' => OpportunisticOriginReason::EXISTING_TICKET_CLAIM,
])));
$assert(
    '5.9 Un submotivo sin modalidad se rechaza en el dominio',
    $reasonWithoutMode instanceof InvalidArgumentException,
    $reasonWithoutMode?->getMessage() ?? 'no se lanzó'
);

$incoherentPair = $thrower(static fn (): Incident => new Incident(...$incidentArgs([
    'opportunisticMode' => OpportunisticMode::ASSUMED,
    'opportunisticOriginReason' => OpportunisticOriginReason::TECHNICIAN_DETECTION,
])));
$assert(
    '5.10 Una pareja modalidad ↔ submotivo incoherente se rechaza en el dominio',
    $incoherentPair instanceof InvalidArgumentException,
    $incoherentPair?->getMessage() ?? 'no se lanzó'
);

$overlongInformant = $thrower(static fn (): Incident => Incident::fromDatabaseRow($legacyRow([
    'opportunistic_mode' => 'NEW',
    'opportunistic_origin_reason' => 'SITE_VERBAL_NOTICE',
    'opportunistic_informant_name' => str_repeat('x', 101),
])));
$assert(
    '5.11 Un informante de más de 100 caracteres se rechaza en el dominio',
    $overlongInformant instanceof InvalidArgumentException,
    $overlongInformant?->getMessage() ?? 'no se lanzó'
);

// =====================================================================
// 6. Excepciones tipadas del módulo
// =====================================================================
echo "\n--- 6. Excepciones de dominio ---\n";

$locationException = new LocationNotEnabledForOpportunisticException(5, 2);
$assert(
    '6.1 La sede no habilitada responde 403 y lleva sede y técnico',
    $locationException->getErrorCode() === 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC'
        && $locationException->getHttpStatusCode() === 403
        && $locationException->getLocationId() === 5
        && $locationException->getTechnicianId() === 2
        && str_contains($locationException->getMessage(), 'ventana de gracia')
);

$conflictException = new ActiveInterventionConflictException([
    'ticket_code' => 'INC-2026-9040',
    'location_name' => 'Hospital del Mar',
    'status' => 'IN_PROGRESS',
]);
$assert(
    '6.2 El conflicto entre sedes responde 409 y transporta el expediente abierto',
    $conflictException->getErrorCode() === 'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION'
        && $conflictException->getHttpStatusCode() === 409
        && $conflictException->getConflict()['ticket_code'] === 'INC-2026-9040'
        && str_contains($conflictException->getMessage(), 'INC-2026-9040')
);

$stops = [
    ['ticket_code' => 'INC-2026-9050', 'location_name' => 'Hospital Clínic', 'urgency' => 'CRITICAL'],
];
$coldChainException = new ColdChainRouteWarningConfirmationException($stops);
$assert(
    '6.3 La advertencia de frío responde 409 y enumera las paradas postergadas (Art. II)',
    $coldChainException->getErrorCode() === 'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED'
        && $coldChainException->getHttpStatusCode() === 409
        && $coldChainException->getPendingCriticalStops() === $stops
        && str_contains($coldChainException->getMessage(), '1 parada')
);

$takeoverException = new TakeoverConfirmationRequiredException(4, 'Jordi Técnico Ruta BCN');
$assert(
    '6.4 El traspaso sin confirmación responde 409 y nombra al técnico saliente',
    $takeoverException->getErrorCode() === 'TAKEOVER_CONFIRMATION_REQUIRED'
        && $takeoverException->getHttpStatusCode() === 409
        && $takeoverException->getPreviousTechnicianId() === 4
        && str_contains($takeoverException->getMessage(), 'Jordi Técnico Ruta BCN')
);

$customMessage = new TakeoverConfirmationRequiredException(null, null, 'Mensaje propio de la capa de presentación.');
$assert(
    '6.5 Un mensaje explícito de la capa superior se respeta',
    $customMessage->getMessage() === 'Mensaje propio de la capa de presentación.'
        && str_contains($customMessage->getMessage(), 'Mensaje propio')
);

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-OPP-03 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
