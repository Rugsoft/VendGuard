<?php

declare(strict_types=1);

/**
 * OpportunisticSurfaceContractsTest (Módulo 16 · laguna G1)
 *
 * Certifica la costura **servidor → portal de sede → ficha de coordinación** del
 * expediente oportunista, que hasta ahora sólo estaba cubierta por dobles:
 *
 *   1. **Portal de sede (`GET /api/locations/{site_code}/machines`):** el
 *      `active_incident` que recibe el Responsable de Ubicación publica la
 *      modalidad, el submotivo y el informante verbal (RF-06.4) y, al mismo
 *      tiempo, sigue blindado por el Art. V.4: ni identificador ni nombre del
 *      técnico, ni notas internas, ni datos de piezas.
 *   2. **Ficha de coordinación (`GET /api/coordinator/incidents/{id}/detail`):**
 *      el bloque `incident` traduce los enums a sus etiquetas canónicas
 *      (`Oportunista In Situ · Nueva`, `Aviso verbal en sede`) y publica el
 *      expediente crónico predecesor como entero, no como cadena.
 *   3. **Continuidad entre superficies:** el mismo expediente que ve la sede es
 *      el que audita coordinación; ningún campo se pierde ni se inventa en el
 *      camino.
 *
 * La suite escribe por la API real (router + middlewares) y lee por las dos
 * superficies de consulta, que es exactamente el recorrido del producto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · G1 · Servidor → portal de sede → ficha\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

TestDataCleaner::registerShutdownRestitution();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);

$assertions = 0;
$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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

$dispatch = static function (string $method, string $path, ?string $token = null, array $body = []) use ($router): array {
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        parsedBody: $body,
        headers: $token !== null ? ['Authorization' => "Bearer {$token}"] : []
    ));

    return [$response->getStatusCode(), $response->getDecodedBody() ?? []];
};

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert(
    '0.1 Semilla disponible: técnico y coordinación',
    $jordi !== null && $coordinator !== null
);
if ($jordi === null || $coordinator === null) {
    echo "\nNo se puede certificar sin las cuentas semilla.\n";
    exit(1);
}

$machines = [];
foreach (['VEND-0301', 'VEND-0302', 'VEND-0502'] as $code) {
    $machine = $machineRepo->findByCode($code);
    $assert("0.2 Máquina semilla {$code} disponible", $machine !== null);
    if ($machine !== null) {
        $machines[$code] = $machine;
        TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());
    }
}
if (count($machines) !== 3) {
    echo "\nNo se puede certificar sin el parque semilla del escenario.\n";
    exit(1);
}

// La sede del escenario es la que aloja las máquinas 03xx (no se asume un código fijo).
$locationThree = $locationRepo->findById($machines['VEND-0301']->getLocationId());
$assert('0.3 La sede de las máquinas 03xx existe y está activa', $locationThree !== null);
if ($locationThree === null) {
    echo "\nNo se puede certificar sin la sede del escenario.\n";
    exit(1);
}

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

/** Parada ancla que habilita la sede para el técnico (RF-01.1). */
$anchor = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: 'INC-2026-G1AN',
        machineId: $machines['VEND-0301']->getId(),
        locationId: $locationThree->getId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Parada ancla de la certificación G1.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::ASSIGNED,
        assignedTechnicianId: $jordi->getId(),
        reporterName: 'Fixture G1',
        assignedAt: $stamp(),
        cycleStartedAt: $stamp()
    ),
    $jordi->getId(),
    'Parada ancla de la certificación G1'
);
$assert('0.4 Parada ancla ASSIGNED creada para habilitar la sede', $anchor->getId() !== null);

$auth = new AuthService($locationRepo, $userRepo);
$techToken = $auth->generateInternalToken($jordi);
$coordinatorToken = $auth->generateInternalToken($coordinator);
$siteToken = $auth->generateSiteToken($locationThree);

// ---------------------------------------------------------------------
// CASO 1 · Alta por la API real con informante verbal en sede
// ---------------------------------------------------------------------
echo "\n--- Caso 1: alta oportunista con informante verbal ---\n";

$informant = 'Conserje de mañana · Carmen';
[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    [
        'machine_id' => $machines['VEND-0302']->getId(),
        'category' => 'PRODUCT_JAM',
        'origin_reason' => 'SITE_VERBAL_NOTICE',
        'description' => 'La sede avisa del atasco en la espiral B4 al pasar el técnico.',
        'informant_name' => $informant,
    ]
);
$created = $body['data'] ?? [];
$incidentId = (int)($created['id'] ?? 0);

$assert(
    '1.1 El alta responde 201 con modalidad NEW y el informante verbal',
    $status === 201
        && ($created['opportunistic_mode'] ?? null) === 'NEW'
        && ($created['opportunistic_origin_reason'] ?? null) === 'SITE_VERBAL_NOTICE'
        && ($created['opportunistic_informant_name'] ?? null) === $informant,
    'HTTP ' . $status . ' · ' . json_encode($body, JSON_UNESCAPED_UNICODE)
);
if ($incidentId < 1) {
    echo "\nNo se puede certificar la costura sin el expediente creado.\n";
    exit(1);
}

// ---------------------------------------------------------------------
// CASO 2 · Portal de sede: el expediente se publica y se blinda (Art. V.4)
// ---------------------------------------------------------------------
echo "\n--- Caso 2: portal de sede ---\n";

[$status, $portalBody] = $dispatch(
    'GET',
    '/api/locations/' . $locationThree->getSiteCode() . '/machines',
    $siteToken
);
$assert('2.1 El parque de la sede responde 200', $status === 200 && is_array($portalBody));

$portalMachines = is_array($portalBody['data'] ?? null) ? $portalBody['data'] : $portalBody;
$portalMachine = null;
foreach ($portalMachines as $machine) {
    if ((int)($machine['id'] ?? 0) === (int)$machines['VEND-0302']->getId()) {
        $portalMachine = $machine;
        break;
    }
}
$activeIncident = $portalMachine['active_incident'] ?? null;
$assert('2.2 La máquina afectada publica su expediente activo', is_array($activeIncident));

$expectedKeys = [
    'id',
    'ticket_code',
    'status',
    'category',
    'urgency',
    'created_at',
    'resolved_at',
    'is_in_warranty',
    'pending_info_reason_category',
    'pending_info_reason_category_label',
    'pending_info_reason_text',
    'opportunistic_mode',
    'opportunistic_origin_reason',
    'opportunistic_informant_name',
    'technician_name',
    'public_comments_count',
];
$actualKeys = array_keys($activeIncident ?? []);
sort($actualKeys);
sort($expectedKeys);

$assert(
    '2.3 El contrato del portal publica exactamente los campos saneados previstos',
    $actualKeys === $expectedKeys,
    'Claves reales: ' . implode(', ', array_keys($activeIncident ?? []))
);
$assert(
    '2.4 La modalidad y el submotivo llegan a la sede tal cual',
    ($activeIncident['opportunistic_mode'] ?? null) === 'NEW'
        && ($activeIncident['opportunistic_origin_reason'] ?? null) === 'SITE_VERBAL_NOTICE'
);
$assert(
    '2.5 El informante verbal se publica íntegro para contextualizar el aviso',
    ($activeIncident['opportunistic_informant_name'] ?? null) === $informant
);
$assert(
    '2.6 Art. V.4: el técnico sólo aparece bajo el alias oficial, nunca con su nombre',
    ($activeIncident['technician_name'] ?? null) === 'Servicio Técnico Oficial'
        && !str_contains((string)($activeIncident['technician_name'] ?? ''), (string)$jordi->getName())
);
$assert(
    '2.7 Art. V.4: no viajan identificador del técnico, notas internas ni piezas',
    is_array($activeIncident)
        && !array_key_exists('assigned_technician_id', $activeIncident)
        && !array_key_exists('assigned_technician_name', $activeIncident)
        && !array_key_exists('internal_notes', $activeIncident)
        && !array_key_exists('pending_parts_reason', $activeIncident)
        && !array_key_exists('resolution_diagnosis', $activeIncident)
);
$assert(
    '2.8 El ticket y su estado canónico coinciden con el alta',
    ($activeIncident['id'] ?? null) === $incidentId
        && ($activeIncident['ticket_code'] ?? null) === ($created['ticket_code'] ?? null)
        && ($activeIncident['status'] ?? null) === 'IN_PROGRESS'
);

// ---------------------------------------------------------------------
// CASO 3 · Ficha de coordinación: etiquetas y trazabilidad crónica
// ---------------------------------------------------------------------
echo "\n--- Caso 3: ficha de coordinación ---\n";

[$status, $detailBody] = $dispatch(
    'GET',
    "/api/coordinator/incidents/{$incidentId}/detail",
    $coordinatorToken
);
$detailIncident = $detailBody['data']['incident'] ?? [];
$assert(
    '3.1 La ficha responde 200 con el bloque `incident`',
    $status === 200 && is_array($detailIncident) && ($detailIncident['id'] ?? null) === $incidentId
);
$assert(
    '3.2 Coordinación recibe la modalidad y su etiqueta canónica',
    ($detailIncident['opportunistic_mode'] ?? null) === 'NEW'
        && ($detailIncident['opportunistic_mode_label'] ?? null) === 'Oportunista In Situ · Nueva'
);
$assert(
    '3.3 El submotivo viaja con su etiqueta legible',
    ($detailIncident['opportunistic_origin_reason'] ?? null) === 'SITE_VERBAL_NOTICE'
        && ($detailIncident['opportunistic_origin_label'] ?? null) === 'Aviso verbal en sede'
);
$assert(
    '3.4 El informante verbal se conserva en la ficha interna',
    ($detailIncident['opportunistic_informant_name'] ?? null) === $informant
);
$assert(
    '3.5 Sin predecesor crónico el campo se publica como null, no como cadena vacía',
    array_key_exists('chronic_predecessor_incident_id', $detailIncident)
        && $detailIncident['chronic_predecessor_incident_id'] === null
);

// Sucesor crónico real: el expediente limpio que sustituye al predecesor debe
// publicar el enlace como entero en la misma ficha (RF-05.4.2 / RF-06.4).
$successorMachine = $machines['VEND-0502'];
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$successorMachine->getId());
$successor = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: 'INC-2026-G1CR',
        machineId: $successorMachine->getId(),
        locationId: $successorMachine->getLocationId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Sucesor limpio de la avería crónica certificada en G1.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::IN_PROGRESS,
        assignedTechnicianId: $jordi->getId(),
        reporterName: 'Fixture G1',
        assignedAt: $stamp(),
        startedAt: $stamp(),
        cycleStartedAt: $stamp(),
        opportunisticMode: OpportunisticMode::NEW,
        opportunisticOriginReason: OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU,
        chronicPredecessorIncidentId: (int)$anchor->getId()
    ),
    $jordi->getId(),
    'Alta del sucesor crónico de la certificación G1'
);

[$status, $detailBody] = $dispatch(
    'GET',
    '/api/coordinator/incidents/' . (int)$successor->getId() . '/detail',
    $coordinatorToken
);
$successorDetail = $detailBody['data']['incident'] ?? [];
$assert(
    '3.6 El sucesor crónico publica su predecesor como entero en la ficha',
    $status === 200
        && ($successorDetail['chronic_predecessor_incident_id'] ?? null) === (int)$anchor->getId()
        && ($successorDetail['opportunistic_origin_label'] ?? null) === 'Reapertura en garantía in situ'
);

// ---------------------------------------------------------------------
// CASO 4 · El mismo expediente en las dos superficies
// ---------------------------------------------------------------------
echo "\n--- Caso 4: continuidad portal ↔ ficha ---\n";

$assert(
    '4.1 El ticket, el estado y la modalidad coinciden entre portal y ficha',
    ($activeIncident['ticket_code'] ?? null) === ($detailIncident['ticket_code'] ?? null)
        && ($activeIncident['status'] ?? null) === ($detailIncident['status'] ?? null)
        && ($activeIncident['opportunistic_mode'] ?? null) === ($detailIncident['opportunistic_mode'] ?? null)
);
$assert(
    '4.2 La ficha interna conserva el motivo verbal que el portal ya publicó',
    ($activeIncident['opportunistic_informant_name'] ?? null) === ($detailIncident['opportunistic_informant_name'] ?? null)
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} · Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G1 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

TestDataCleaner::purge($pdo);

exit($failures === 0 ? 0 : 1);
