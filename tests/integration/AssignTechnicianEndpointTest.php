<?php

declare(strict_types=1);

/**
 * AssignTechnicianEndpointTest (T-26)
 *
 * Validates PATCH /api/coordinator/incidents/{id}/assign:
 *   - EARS 5.1: REGISTERED & REOPENED => ASSIGNED correctly.
 *   - EARS 5.2: Only one active responsible technician per incident at a time; a
 *     reassignment replaces the professional instead of adding a second one.
 *   - EARS 5.3: Urgency reclassification with mandatory reason stored in history.
 *   - EARS 5.4: Missing/invalid technician_id is rejected.
 *   - RF-07.3 / T-IDM-21: reassignment from ASSIGNED, IN_PROGRESS and PENDING_PARTS with a
 *     mandatory motive (>= 10 real characters, 422 when missing, too short or targeting the
 *     current responsible), preserving the operational status and the audited assignment
 *     milestone, plus the immutable INCIDENT_REASSIGNED event in audit_log.
 *   - Auth guard: 401/403 for unauthenticated or wrong-role callers.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
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
echo " VendGuard: Test de Integración - AssignTechnicianEndpointTest (T-26 + T-IDM-21)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

// ─── Usuarios y tokens ────────────────────────────────────────────────────────
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$technicianId     = $technician->getId();
$coordinatorId    = $coordinator->getId();

// ─── Máquinas para crear incidencias de prueba ───────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper: crea incidencia REGISTERED usando el repositorio (mismo patrón T-25) ─────
$makeIncident = function (
    int $machineId,
    int $locationId,
    UrgencyLevel $urgency = UrgencyLevel::CRITICAL
) use ($incidentRepo, $pdo): Incident {
    // Soft-delete y cancelar cualquier ticket activo previo (libera el índice uq_machine_active_ticket)
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    $code = 'T26-' . uniqid();
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Prueba de asignación T-26.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    return $incidentRepo->create($inc);
};

// ─── Helper: fuerza un estado operativo concreto para probar la reasignación ───
$setStatus = function (int $incidentId, string $status) use ($pdo): void {
    $pdo->prepare("UPDATE incidents SET status = :status WHERE id = :id")
        ->execute([':status' => $status, ':id' => $incidentId]);
};

// =========================================================================
// CASO 1: Control de Acceso y RBAC (401 / 403)
// =========================================================================

echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/assign', parsedBody: ['technician_id' => $technicianId]);
$res = $router->dispatch($req);
$assert("1.1 Sin token => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token inválido => 401
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/assign', parsedBody: ['technician_id' => $technicianId], headers: ['Authorization' => 'Bearer tok_invalido_xyz']);
$res = $router->dispatch($req);
$assert("1.2 Token inválido => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.3 Técnico intentando ruta de coordinador => 403
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/assign', parsedBody: ['technician_id' => $technicianId], headers: ['Authorization' => "Bearer {$technicianToken}"]);
$res = $router->dispatch($req);
$assert("1.3 Técnico en ruta coordinador => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: Validación de Entrada (EARS 5.4)
// =========================================================================

echo "\n--- Caso 2: Validación de Entrada (EARS 5.4) ---\n";

$authHdr = ['Authorization' => "Bearer {$coordinatorToken}"];

// 2.1 Sin technician_id => 422 MISSING_TECHNICIAN_ID
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/assign', parsedBody: [], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.1 Sin technician_id => success=false", ($res->getDecodedBody()['success'] ?? true) === false);
$assert("2.1 Sin technician_id => MISSING_TECHNICIAN_ID", ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_TECHNICIAN_ID');

// 2.2 technician_id=0 => 422
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/assign', parsedBody: ['technician_id' => 0], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.2 technician_id=0 => MISSING_TECHNICIAN_ID", ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_TECHNICIAN_ID');

// 2.3 urgency_override inválido => 422 INVALID_URGENCY
$incA = $makeIncident($mach1->getId(), $location1->getId(), UrgencyLevel::CRITICAL);
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incA->getId()}/assign", parsedBody: ['technician_id' => $technicianId, 'urgency_override' => 'ULTRA_MAX'], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.3 Urgencia desconocida => INVALID_URGENCY", ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_URGENCY');

// 2.4 urgency_override válido sin motivo => 422 URGENCY_REASON_REQUIRED (EARS 5.3)
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incA->getId()}/assign", parsedBody: ['technician_id' => $technicianId, 'urgency_override' => 'MEDIUM'], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.4 Urgency sin motivo => URGENCY_REASON_REQUIRED", ($res->getDecodedBody()['error']['code'] ?? '') === 'URGENCY_REASON_REQUIRED');

// 2.5 Incidencia inexistente => 404 INCIDENT_NOT_FOUND
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/999999/assign', parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.5 Incidencia inexistente => INCIDENT_NOT_FOUND", ($res->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// 2.6 Técnico inexistente => 422 TECHNICIAN_NOT_FOUND (EARS 5.4)
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incA->getId()}/assign", parsedBody: ['technician_id' => 999998], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.6 Técnico inexistente => TECHNICIAN_NOT_FOUND", ($res->getDecodedBody()['error']['code'] ?? '') === 'TECHNICIAN_NOT_FOUND');

// =========================================================================
// CASO 3: Asignación Exitosa — REGISTERED => ASSIGNED (EARS 5.1) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================

echo "\n--- Caso 3: Asignación Exitosa REGISTERED => ASSIGNED (EARS 5.1) ---\n";

$incB = $makeIncident($mach2->getId(), $location1->getId(), UrgencyLevel::HIGH);
$req  = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$res  = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("3.1 REGISTERED => ASSIGNED, HTTP 200", $res->getStatusCode() === 200, "Código: {$res->getStatusCode()} Body: " . json_encode($body));
$assert("3.2 success=true", ($body['success'] ?? false) === true);
$data3 = $body['data'] ?? [];
$assert("3.3 status => ASSIGNED", ($data3['status'] ?? null) === 'ASSIGNED');
$assert("3.4 assigned_technician_id correcto", ($data3['assigned_technician_id'] ?? null) === $technicianId);
$assert("3.5 id de incidencia correcto", ($data3['id'] ?? null) === $incB->getId());
$assert("3.6 assigned_at presente y no nulo", isset($data3['assigned_at']) && $data3['assigned_at'] !== null);

// Verificar en BD
$row = $pdo->query("SELECT status, assigned_technician_id, assigned_at FROM incidents WHERE id = {$incB->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.7 BD: status=ASSIGNED", ($row['status'] ?? null) === 'ASSIGNED');
$assert("3.8 BD: assigned_technician_id correcto", (int)($row['assigned_technician_id'] ?? 0) === $technicianId);

// Evento inmutable de la asignación inicial en audit_log (RNF-04, plan §2.2)
$initialAuditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_ASSIGNED' ORDER BY id DESC LIMIT 1");
$initialAuditStmt->execute([':id' => $incB->getId()]);
$initialAudit = $initialAuditStmt->fetch(PDO::FETCH_ASSOC);
$assert("3.9 Evento INCIDENT_ASSIGNED registrado en audit_log", $initialAudit !== false, 'Sin filas de auditoría para el ticket.');
if ($initialAudit !== false) {
    $initialState = json_decode((string)$initialAudit['new_state'], true) ?? [];
    $assert("3.10 audit_log: técnico asignado y actor coordinador", ($initialState['assigned_technician_id'] ?? null) === $technicianId && (int)($initialAudit['user_id'] ?? 0) === $coordinatorId);
}

// =========================================================================
// CASO 4: Reasignación en línea con motivo obligatorio (RF-07.3, T-IDM-21)
// [CONDICIÓN "HECHO CUANDO"]: ASSIGNED / IN_PROGRESS / PENDING_PARTS admiten cambiar de
// profesional con motivo >= 10 caracteres, manteniendo un único responsable activo
// (Art. V.3) y registrando el evento inmutable INCIDENT_REASSIGNED (Art. III.3).
// =========================================================================

echo "\n--- Caso 4: Reasignación con motivo obligatorio (RF-07.3) ---\n";

// Segundo técnico activo de la semilla (OP-02): sin él no existe reasignación posible.
$secondTechnician = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$assert(
    "4.0 Segundo técnico activo de la semilla disponible para reasignar",
    $secondTechnician !== null && $secondTechnician->getRole()->value === 'TECHNICIAN'
);
if ($secondTechnician === null) {
    echo "ERROR FATAL: sin un segundo técnico no se puede verificar la reasignación.\n";
    exit(1);
}
$secondTechnicianId = $secondTechnician->getId();

$reassignReason = 'Reasignación por proximidad geográfica al centro con riesgo de cadena de frío.';

// 4.1 Reasignación sin motivo => 422 MISSING_REASSIGNMENT_REASON
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $secondTechnicianId], headers: $authHdr);
$res = $router->dispatch($req);
$assert("4.1 Reasignación sin motivo => 422", $res->getStatusCode() === 422, "Código: {$res->getStatusCode()}");
$assert("4.1 Reasignación sin motivo => MISSING_REASSIGNMENT_REASON", ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_REASSIGNMENT_REASON');

// 4.2 Motivo por debajo del umbral => 422 REASSIGNMENT_REASON_TOO_SHORT (multibyte-safe)
$shortReason = 'Cobertura'; // 9 caracteres reales exactos
$assert("4.2 Fixture: motivo de 9 caracteres reales", mb_strlen($shortReason, 'UTF-8') === 9, 'Longitud: ' . mb_strlen($shortReason, 'UTF-8'));
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $secondTechnicianId, 'reassignment_reason' => $shortReason], headers: $authHdr);
$res = $router->dispatch($req);
$assert("4.2 Motivo de 9 caracteres => 422", $res->getStatusCode() === 422);
$assert("4.2 Motivo de 9 caracteres => REASSIGNMENT_REASON_TOO_SHORT", ($res->getDecodedBody()['error']['code'] ?? '') === 'REASSIGNMENT_REASON_TOO_SHORT');

$multibyteShort = str_repeat('á', 9); // 9 caracteres reales en 18 bytes
$assert("4.2 Fixture multibyte: 9 caracteres reales en 18 bytes", mb_strlen($multibyteShort, 'UTF-8') === 9 && strlen($multibyteShort) === 18);
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $secondTechnicianId, 'reassignment_reason' => $multibyteShort], headers: $authHdr);
$res = $router->dispatch($req);
$assert("4.2 Motivo multibyte de 9 caracteres => REASSIGNMENT_REASON_TOO_SHORT", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'REASSIGNMENT_REASON_TOO_SHORT');

// 4.3 El responsable vigente no es destino válido => 422 TECHNICIAN_ALREADY_ASSIGNED (Art. V.3)
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $technicianId, 'reassignment_reason' => $reassignReason], headers: $authHdr);
$res = $router->dispatch($req);
$assert("4.3 Mismo técnico responsable => 422", $res->getStatusCode() === 422);
$assert("4.3 Mismo técnico responsable => TECHNICIAN_ALREADY_ASSIGNED", ($res->getDecodedBody()['error']['code'] ?? '') === 'TECHNICIAN_ALREADY_ASSIGNED');
$unchanged = $pdo->query("SELECT status, assigned_technician_id, assigned_at FROM incidents WHERE id = {$incB->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("4.3 BD intacta: sigue el responsable original en ASSIGNED", ($unchanged['status'] ?? null) === 'ASSIGNED' && (int)($unchanged['assigned_technician_id'] ?? 0) === $technicianId);

// 4.4 Reasignación válida desde ASSIGNED => 200 con cambio de responsable
$assignedAtBefore = (string)($unchanged['assigned_at'] ?? '');
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incB->getId()}/assign",
    parsedBody: ['technician_id' => $secondTechnicianId, 'reassignment_reason' => $reassignReason],
    headers: $authHdr
);
$res  = $router->dispatch($req);
$body = $res->getDecodedBody();
$assert("4.4 Reasignación válida => HTTP 200", $res->getStatusCode() === 200, "Código: {$res->getStatusCode()} Body: " . json_encode($body));
$assert("4.4 Reasignación válida => nuevo responsable y estado preservado", ($body['data']['assigned_technician_id'] ?? null) === $secondTechnicianId && ($body['data']['status'] ?? null) === 'ASSIGNED');

$reassignedRow = $pdo->query("SELECT status, assigned_technician_id, assigned_at FROM incidents WHERE id = {$incB->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("4.4 BD: un único responsable activo (el nuevo técnico)", (int)($reassignedRow['assigned_technician_id'] ?? 0) === $secondTechnicianId && ($reassignedRow['status'] ?? null) === 'ASSIGNED');
$assert("4.4 BD: el hito audited de asignación no se reescribe (Art. III.1)", (string)($reassignedRow['assigned_at'] ?? '') === $assignedAtBefore, "Antes: {$assignedAtBefore} Después: " . (string)($reassignedRow['assigned_at'] ?? ''));

// Historial inmutable: nota canónica con el motivo y autoría del coordinador
$histStmt = $pdo->prepare("SELECT * FROM incident_history WHERE incident_id = :id ORDER BY id DESC LIMIT 1");
$histStmt->execute([':id' => $incB->getId()]);
$reassignHistory = $histStmt->fetch(PDO::FETCH_ASSOC);
$assert("4.4 Historial inmutable registrado con el motivo", $reassignHistory !== false && str_contains((string)($reassignHistory['action_note'] ?? ''), 'Reasignación técnica:') && str_contains((string)($reassignHistory['action_note'] ?? ''), $reassignReason));
$assert("4.4 Historial: actor coordinador y estado preservado", $reassignHistory !== false && (int)($reassignHistory['user_id'] ?? 0) === $coordinatorId && ($reassignHistory['from_status'] ?? null) === 'ASSIGNED' && ($reassignHistory['to_status'] ?? null) === 'ASSIGNED');

// Evento inmutable INCIDENT_REASSIGNED en audit_log (RNF-04, Art. III.3)
$auditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_REASSIGNED' ORDER BY id DESC LIMIT 1");
$auditStmt->execute([':id' => $incB->getId()]);
$reassignAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
$assert("4.4 Evento INCIDENT_REASSIGNED registrado en audit_log", $reassignAudit !== false);
if ($reassignAudit !== false) {
    $auditNewState = json_decode((string)$reassignAudit['new_state'], true) ?? [];
    $auditPrevState = json_decode((string)($reassignAudit['previous_state'] ?? ''), true) ?? [];
    $auditMetadata = json_decode((string)($reassignAudit['metadata'] ?? ''), true) ?? [];
    $assert("4.4 audit_log: motivo y nuevo responsable en new_state", ($auditNewState['reassignment_reason'] ?? null) === $reassignReason && ($auditNewState['assigned_technician_id'] ?? null) === $secondTechnicianId);
    $assert("4.4 audit_log: técnico sustituido y nuevo técnico en metadata", ($auditMetadata['previous_technician_id'] ?? null) === $technicianId && ($auditMetadata['new_technician_id'] ?? null) === $secondTechnicianId);
    $assert("4.4 audit_log: previous_state apunta al responsable anterior", ($auditPrevState['assigned_technician_id'] ?? null) === $technicianId);
    $assert("4.4 audit_log: actor coordinador autenticado", (int)($reassignAudit['user_id'] ?? 0) === $coordinatorId && ($reassignAudit['user_role'] ?? null) === 'COORDINATOR');
}

// El modal recibe el motivo y el nuevo responsable desde el endpoint de detalle (RF-02, RF-04.1)
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incB->getId()}/detail", headers: $authHdr);
$res = $router->dispatch($req);
$detailTechnician = $res->getDecodedBody()['data']['technician'] ?? [];
$assert(
    "4.4 El detalle del modal expone el nuevo responsable y el motivo registrado",
    $res->getStatusCode() === 200
        && ($detailTechnician['technician_id'] ?? null) === $secondTechnicianId
        && ($detailTechnician['reassignment_reason'] ?? null) === $reassignReason,
    json_encode($detailTechnician)
);

// 4.5 Reasignación desde IN_PROGRESS: el estado operativo no retrocede
$incInProgress = $makeIncident($mach3->getId(), $location2->getId(), UrgencyLevel::HIGH);
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incInProgress->getId()}/assign", parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$router->dispatch($req);
$setStatus($incInProgress->getId(), 'IN_PROGRESS');
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incInProgress->getId()}/assign",
    parsedBody: ['technician_id' => $secondTechnicianId, 'reassignment_reason' => 'El técnico titular entra en descanso y la máquina sigue en intervención.'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$inProgressRow = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$incInProgress->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert(
    "4.5 IN_PROGRESS => reasignación 200 conservando IN_PROGRESS",
    $res->getStatusCode() === 200
        && ($res->getDecodedBody()['data']['status'] ?? null) === 'IN_PROGRESS'
        && ($inProgressRow['status'] ?? null) === 'IN_PROGRESS'
        && (int)($inProgressRow['assigned_technician_id'] ?? 0) === $secondTechnicianId
);

// 4.6 Reasignación desde PENDING_PARTS usando el alias `reason` del plan técnico (§2.2)
$incPending = $makeIncident($mach1->getId(), $location1->getId(), UrgencyLevel::CRITICAL);
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incPending->getId()}/assign", parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$router->dispatch($req);
$setStatus($incPending->getId(), 'PENDING_PARTS');
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incPending->getId()}/assign",
    parsedBody: ['technician_id' => $secondTechnicianId, 'reason' => 'Pieza recibida en almacén: reasigno a la ruta con el repuesto a bordo.'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$pendingRow = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$incPending->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert(
    "4.6 PENDING_PARTS => reasignación 200 con el alias `reason` y estado preservado",
    $res->getStatusCode() === 200
        && ($pendingRow['status'] ?? null) === 'PENDING_PARTS'
        && (int)($pendingRow['assigned_technician_id'] ?? 0) === $secondTechnicianId
);

// 4.7 Los estados terminales siguen bloqueando cualquier cambio de técnico
$incResolved = $makeIncident($mach2->getId(), $location1->getId(), UrgencyLevel::MEDIUM);
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incResolved->getId()}/assign", parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$router->dispatch($req);
$setStatus($incResolved->getId(), 'RESOLVED');
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incResolved->getId()}/assign",
    parsedBody: ['technician_id' => $secondTechnicianId, 'reassignment_reason' => 'Intento de reasignar un expediente ya resuelto y sellado.'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert(
    "4.7 RESOLVED => 422 INVALID_STATUS_FOR_ASSIGNMENT",
    $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_ASSIGNMENT'
);

// =========================================================================
// CASO 5: Reclasificación de Urgencia Auditada (EARS 5.3) — [CONDICIÓN "HECHO CUANDO"]
// Exige motivo obligatorio que queda registrado en el historial inmutable.
// =========================================================================

echo "\n--- Caso 5: Reclasificación de Urgencia Auditada (EARS 5.3) ---\n";

$incC   = $makeIncident($mach3->getId(), $location2->getId(), UrgencyLevel::CRITICAL);
$reason = 'Máquina vacía sin producto perecedero en esta temporada (comprobado por teléfono).';

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incC->getId()}/assign",
    parsedBody: [
        'technician_id'           => $technicianId,
        'urgency_override'        => 'MEDIUM',
        'urgency_override_reason' => $reason,
    ],
    headers: $authHdr
);
$res  = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("5.1 Reclasificación => HTTP 200", $res->getStatusCode() === 200, json_encode($body));
$assert("5.2 Reclasificación => success=true", ($body['success'] ?? false) === true);
$assert("5.3 status => ASSIGNED", ($body['data']['status'] ?? null) === 'ASSIGNED');
$assert("5.4 assigned_technician_id correcto", ($body['data']['assigned_technician_id'] ?? null) === $technicianId);

// Verificar urgencia actualizada en BD
$urgRow = $pdo->query("SELECT urgency FROM incidents WHERE id = {$incC->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("5.5 BD: urgencia actualizada a MEDIUM", ($urgRow['urgency'] ?? null) === 'MEDIUM', "Obtenido: " . ($urgRow['urgency'] ?? 'null'));

// Verificar que el motivo aparece en historial inmutable
$histRows = $pdo->query("SELECT action_note FROM incident_history WHERE incident_id = {$incC->getId()} ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$notes    = array_column($histRows, 'action_note');
$found    = array_filter($notes, fn($n) => str_contains((string)$n, $reason));
$assert("5.6 Motivo de reclasificación en historial inmutable", count($found) > 0, "Notas: " . json_encode($notes));

// Reasignación con reclasificación simultánea: el motivo de la reasignación sigue siendo legible
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incC->getId()}/assign",
    parsedBody: [
        'technician_id'        => $secondTechnicianId,
        'urgency_override'     => 'CRITICAL',
        'urgency_override_reason' => 'Rotura confirmada de la cadena de frío durante la intervención.',
        'reassignment_reason'  => 'Cobertura inmediata con el técnico de guardia de urgencias.',
    ],
    headers: $authHdr
);
$res = $router->dispatch($req);
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incC->getId()}/detail", headers: $authHdr);
$urgentDetail = $router->dispatch($req)->getDecodedBody()['data']['technician'] ?? [];
$assert(
    "5.7 Reasignación + reclasificación: motivo aislado y urgencia actualizada",
    $res->getStatusCode() === 200
        && ($urgentDetail['reassignment_reason'] ?? null) === 'Cobertura inmediata con el técnico de guardia de urgencias.'
        && ($urgentDetail['technician_id'] ?? null) === $secondTechnicianId,
    json_encode($urgentDetail)
);

// =========================================================================
// CASO 6: Prueba HTTP Real vía cURL
// =========================================================================

echo "\n--- Caso 6: Prueba HTTP Real (cURL) ---\n";

// Crear incidencia REGISTERED con una máquina de location2 (la máquina mach3 ya se usó en caso 5 y quedó ASSIGNED)
// Usar una segunda máquina de location2 si existe, o usar la primera de la semilla con otro machine
$machinesLoc2ForCurl = $machineRepo->findActiveByLocationId($location2->getId());
$machForCurl = count($machinesLoc2ForCurl) > 1 ? $machinesLoc2ForCurl[1] : $machinesLoc2ForCurl[0];
// Desactivar cualquier ticket activo previo (para evitar DuplicateIncidentException, cancela y soft-delete)
$pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machForCurl->getId()]);
$incD = $makeIncident($machForCurl->getId(), $location2->getId(), UrgencyLevel::HIGH);

$url = "http://127.0.0.1:8000/api/coordinator/incidents/{$incD->getId()}/assign";
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode(['technician_id' => $technicianId]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        "Authorization: Bearer {$coordinatorToken}",
    ],
    CURLOPT_TIMEOUT => 5,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode((string)$raw, true) ?? [];

$assert("6.1 HTTP real => 200 OK", $code === 200, "Código HTTP: {$code}, Body: {$raw}");
$assert("6.2 HTTP real => success=true", ($json['success'] ?? false) === true, (string)$raw);
$assert("6.3 HTTP real => status ASSIGNED", ($json['data']['status'] ?? null) === 'ASSIGNED');
$assert("6.4 HTTP real => técnico correcto", ($json['data']['assigned_technician_id'] ?? null) === $technicianId);
$assert("6.5 HTTP real => assigned_at presente", isset($json['data']['assigned_at']) && $json['data']['assigned_at'] !== null);

// 6.6 Reasignación real por HTTP con motivo justificado
$reassignUrl = "http://127.0.0.1:8000/api/coordinator/incidents/{$incD->getId()}/assign";
$chReassign = curl_init($reassignUrl);
curl_setopt_array($chReassign, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode([
        'technician_id'       => $secondTechnicianId,
        'reassignment_reason' => 'Reasignación real por HTTP: cobertura del técnico de guardia.',
    ]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        "Authorization: Bearer {$coordinatorToken}",
    ],
    CURLOPT_TIMEOUT => 5,
]);
$rawReassign  = curl_exec($chReassign);
$codeReassign = curl_getinfo($chReassign, CURLINFO_HTTP_CODE);
curl_close($chReassign);

$jsonReassign = json_decode((string)$rawReassign, true) ?? [];
$assert("6.6 HTTP real de reasignación => 200 OK con el nuevo responsable", $codeReassign === 200 && ($jsonReassign['data']['assigned_technician_id'] ?? null) === $secondTechnicianId, "Código HTTP: {$codeReassign}, Body: {$rawReassign}");

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
