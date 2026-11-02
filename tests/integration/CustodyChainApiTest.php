<?php

declare(strict_types=1);

/**
 * VendGuard - CustodyChainApiTest (T-CUSTODY-11)
 *
 * Certificación HTTP del módulo 26 sobre PostgreSQL real, a través del router:
 *   1. Un expediente sin técnico publica una cadena vacía explícita (R6).
 *   2. La asignación inicial abre el tramo `COORDINATOR_ASSIGN` y deja el evento
 *      `INCIDENT_ASSIGNED` con `previous_state` y `new_state` (QA 1).
 *   3. Un motivo de guiones se rechaza con `422 REASSIGNMENT_REASON_REQUIRED` sin
 *      tocar la custodia (R1, QA 1).
 *   4. Reasignar al mismo técnico responde `422 TECHNICIAN_ALREADY_ASSIGNED` (R4).
 *   5. La reasignación válida cierra el tramo saliente (`REASSIGNED_BY_COORDINATOR`)
 *      y abre el entrante (`COORDINATOR_REASSIGN`), con el motivo normalizado y el
 *      evento `INCIDENT_REASSIGNED` (QA 1, QA 2).
 *   6. La resolución cierra el tramo con `RESOLVED` y sin custodio activo (QA 3).
 *   7. Un expediente histórico sin filas se lee con el motor de fallback (QA 5).
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Service\ReassignmentReasonValidator;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
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
echo " VendGuard: CustodyChainApiTest (T-CUSTODY-11)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$failures = 0;
$assert = function (string $title, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert('0.1 Semillas de coordinación y técnico disponibles', $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR FATAL: semillas no disponibles.\n";
    exit(1);
}

$second = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'TECHNICIAN' AND is_active = TRUE AND id <> " . (int)$technician->getId() . ' ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$assert('0.2 Segundo técnico activo para la reasignación', is_array($second));
if (!is_array($second)) {
    echo "ERROR FATAL: se necesita un segundo técnico.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$coordinatorId    = (int)$coordinator->getId();
$technicianId     = (int)$technician->getId();
$secondId         = (int)$second['id'];
$authHdr          = ['Authorization' => "Bearer {$coordinatorToken}"];

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machines = $machineRepo->findActiveByLocationId($location->getId());
$assert('0.3 Sede y máquinas semilla disponibles', $location !== null && count($machines) >= 2);
if ($location === null || count($machines) < 2) {
    echo "ERROR FATAL: parque semilla insuficiente.\n";
    exit(1);
}

$makeIncident = function (int $machineId) use ($incidentRepo, $pdo, $location): Incident {
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    return $incidentRepo->create(new Incident(
        id: null,
        ticketCode: 'T26-' . strtoupper(uniqid()),
        machineId: $machineId,
        locationId: $location->getId(),
        category: IncidentCategory::OTHER,
        description: 'Expediente de certificación de la cadena de custodia del módulo 26.',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
};

$detail = function (int $incidentId) use ($router, $authHdr): array {
    $response = $router->dispatch(new Request(
        method: 'GET',
        path: "/api/coordinator/incidents/{$incidentId}/detail",
        headers: $authHdr
    ));

    return [$response->getStatusCode(), $response->getDecodedBody()['data'] ?? []];
};

// ---------------------------------------------------------------------------
echo "\n--- Caso 1: avería sin técnico, cadena vacía explícita ---\n";
// ---------------------------------------------------------------------------
$emptyIncident = $makeIncident($machines[0]->getId());
[$status, $data] = $detail((int)$emptyIncident->getId());
$assert('1.1 Detalle 200 con bloque custody_chain', $status === 200 && isset($data['custody_chain']));
$assert(
    '1.2 Sin tramos: is_empty, mensaje explícito y contadores a cero (R6)',
    ($data['custody_chain']['summary']['is_empty'] ?? null) === true
        && ($data['custody_chain']['summary']['empty_message'] ?? null) !== null
        && ($data['custody_chain']['segments'] ?? null) === []
        && ($data['custody_chain']['summary']['reassignments_count'] ?? null) === 0
);

// ---------------------------------------------------------------------------
echo "\n--- Caso 2: asignación inicial y auditoría ---\n";
// ---------------------------------------------------------------------------
$incident = $makeIncident($machines[1]->getId());
$incidentId = (int)$incident->getId();
$assign = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    parsedBody: ['technician_id' => $technicianId],
    headers: $authHdr
));
$assert('2.1 Asignación inicial 200', $assign->getStatusCode() === 200);

$segment = $pdo->query("SELECT * FROM incident_assignments WHERE incident_id = {$incidentId} ORDER BY id")->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.2 Tramo abierto con canal COORDINATOR_ASSIGN y snapshots congelados (D2)',
    is_array($segment)
        && $segment['assignment_source'] === 'COORDINATOR_ASSIGN'
        && $segment['unassigned_at'] === null
        && $segment['technician_code_snapshot'] !== ''
        && $segment['assigned_by_name_snapshot'] !== ''
);

$audit = $pdo->query(
    "SELECT previous_state, new_state FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'INCIDENT_ASSIGNED' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.3 Evento INCIDENT_ASSIGNED con previous_state y new_state (QA 1)',
    is_array($audit) && $audit['previous_state'] !== null && $audit['new_state'] !== null
);

// ---------------------------------------------------------------------------
echo "\n--- Caso 3: motivo de reasignación sin contenido real ---\n";
// ---------------------------------------------------------------------------
$invalid = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    parsedBody: ['technician_id' => $secondId, 'reassignment_reason' => '----------'],
    headers: $authHdr
));
$assert(
    '3.1 Guiones sin letras ni dígitos => 422 REASSIGNMENT_REASON_REQUIRED (R1, QA 1)',
    $invalid->getStatusCode() === 422 && ($invalid->getDecodedBody()['error']['code'] ?? '') === 'REASSIGNMENT_REASON_REQUIRED',
    'HTTP ' . $invalid->getStatusCode()
);
$still = $pdo->query("SELECT assigned_technician_id FROM incidents WHERE id = {$incidentId}")->fetchColumn();
$assert('3.2 El rechazo no mueve la custodia', (int)$still === $technicianId);

$spaces = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    parsedBody: ['technician_id' => $secondId, 'reassignment_reason' => '     .     '],
    headers: $authHdr
));
$assert('3.3 Sólo espacios y símbolos => 422 REASSIGNMENT_REASON_REQUIRED', $spaces->getStatusCode() === 422);

$same = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    parsedBody: ['technician_id' => $technicianId, 'reassignment_reason' => 'Motivo suficientemente largo para la prueba'],
    headers: $authHdr
));
$assert(
    '3.4 Reasignar al mismo técnico => 422 TECHNICIAN_ALREADY_ASSIGNED (R4)',
    $same->getStatusCode() === 422 && ($same->getDecodedBody()['error']['code'] ?? '') === 'TECHNICIAN_ALREADY_ASSIGNED'
);

// La puerta de custodia (10) y la sanitaria (20) son umbrales independientes.
$assert('3.5 El umbral de custodia es 10 y no comparte constante con la salvaguarda sanitaria', ReassignmentReasonValidator::MIN_LENGTH === 10);

// ---------------------------------------------------------------------------
echo "\n--- Caso 4: reasignación válida y contador de reasignaciones ---\n";
// ---------------------------------------------------------------------------
$validReason = '  Reasignación   por   proximidad  al centro sanitario  ';
$reassign = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    parsedBody: ['technician_id' => $secondId, 'reassignment_reason' => $validReason],
    headers: $authHdr
));
$assert('4.1 Reasignación válida 200', $reassign->getStatusCode() === 200, 'HTTP ' . $reassign->getStatusCode());

$segments = $pdo->query("SELECT * FROM incident_assignments WHERE incident_id = {$incidentId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$assert(
    '4.2 Dos tramos: el saliente cerrado por REASSIGNED_BY_COORDINATOR y el entrante COORDINATOR_REASSIGN',
    count($segments) === 2
        && $segments[0]['unassignment_reason_type'] === 'REASSIGNED_BY_COORDINATOR'
        && $segments[0]['unassigned_at'] !== null
        && $segments[1]['assignment_source'] === 'COORDINATOR_REASSIGN'
        && $segments[1]['unassigned_at'] === null
);
$assert(
    '4.3 El motivo se guarda normalizado en el tramo (R1)',
    $segments[0]['reassignment_reason'] === 'Reasignación por proximidad al centro sanitario'
);

[, $data] = $detail($incidentId);
$assert(
    '4.4 Resumen: 2 tramos, 1 reasignación y custodio activo',
    ($data['custody_chain']['summary']['total_assignments_count'] ?? null) === 2
        && ($data['custody_chain']['summary']['reassignments_count'] ?? null) === 1
        && ($data['custody_chain']['summary']['has_active_custodian'] ?? null) === true
);

$audit = $pdo->query(
    "SELECT previous_state, new_state FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'INCIDENT_REASSIGNED' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$assert(
    '4.5 Evento INCIDENT_REASSIGNED con estados previo y nuevo (QA 1)',
    is_array($audit) && $audit['previous_state'] !== null && $audit['new_state'] !== null
);

// ---------------------------------------------------------------------------
echo "\n--- Caso 5: resolución cierra el tramo ---\n";
// ---------------------------------------------------------------------------
$incidentRepo->startIntervention($incidentId, $secondId);
$incidentRepo->resolve(
    $incidentId,
    $secondId,
    'Compresor bloqueado por sobrecalentamiento del relé térmico.',
    'Sustitución del relé y verificación de la cadena de frío durante 30 minutos.'
);
[, $resolvedData] = $detail($incidentId);
$lastSegment = ($resolvedData['custody_chain']['segments'] ?? [])[1] ?? [];
$assert(
    '5.1 Sin custodio activo y último tramo cerrado por RESOLVED (QA 3)',
    ($resolvedData['custody_chain']['summary']['has_active_custodian'] ?? null) === false
        && ($lastSegment['unassignment_reason_type'] ?? null) === 'RESOLVED'
        && ($lastSegment['is_active'] ?? null) === false
);
$assert(
    '5.2 La resolución no cuenta como reasignación (R2)',
    ($resolvedData['custody_chain']['summary']['reassignments_count'] ?? null) === 1
);

// ---------------------------------------------------------------------------
echo "\n--- Caso 6: expediente histórico sin filas, motor de fallback ---\n";
// ---------------------------------------------------------------------------
$legacy = $makeIncident($machines[1]->getId());
$legacyId = (int)$legacy->getId();
$pdo->prepare("UPDATE incidents SET assigned_technician_id = :tech, status = 'ASSIGNED', assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE id = :id")
    ->execute([':tech' => $technicianId, ':id' => $legacyId]);

[, $legacyData] = $detail($legacyId);
$fallback = ($legacyData['custody_chain']['segments'] ?? [])[0] ?? [];
$assert(
    '6.1 Fallback determinista con is_synthesized_fallback y snapshots de respaldo (QA 5)',
    ($fallback['is_synthesized_fallback'] ?? null) === true
        && ($fallback['technician_code'] ?? '') !== ''
        && ($fallback['technician_name'] ?? '') !== ''
        && ($fallback['assigned_by_name'] ?? null) === 'Coordinación / Sistema',
    json_encode($fallback)
);

echo "\n======================================================================\n";
echo " Total aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-CUSTODY-11 (API) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}
echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
