<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentDetailApiTest (T-IDM-17)
 *
 * HTTP integration certification of the incident detail module (spec 09) against real MariaDB.
 * Done-when checklist evaluated end to end through the router:
 *   1. GET /api/coordinator/incidents/{id}/detail returns the enriched file in a single
 *      aggregated read (RF-01, RF-02, RF-03, RNF-01): incident, location, machine, technician,
 *      timeline, SLA, technical intervention and permission flags, by numeric id and by ticket code.
 *   2. PATCH .../assign performs the assignment launched from the modal (RF-07.3) and persists
 *      the immutable INCIDENT_ASSIGNED event in audit_log (RNF-04, Art. III.3).
 *   3. PATCH .../cancel executes the justified soft-delete discard with a valid reason (RF-07.4,
 *      Art. III.2) leaving the full trail in incident_history and the immutable
 *      INCIDENT_CANCELLED event in audit_log (RNF-04, Art. III.3); short reasons are rejected
 *      with HTTP 422 CANCELLATION_REASON_TOO_SHORT (T-IDM-20) without persisting anything.
 *   4. POST .../comments publishes internal workshop notes (RF-05.2, RF-05.3) persisted with the
 *      immutable INCIDENT_COMMENT_ADDED event in audit_log, and the enriched detail exposes them
 *      with their visibility flag.
 *   5. RBAC: 401 without a token, 403 with a technician token (Art. V.4 defense in depth).
 *
 * The discard audit trail is certified on both surfaces: the incident_history transition
 * rows and the INCIDENT_CANCELLED event in audit_log carrying the reason, the timestamp
 * and the authenticated coordinator as the actor.
 *
 * Dogma Vanilla: PHP 8.2 strict types, PDO against the real database, no external libraries.
 * Dualismo Linguistico: identifiers in English, test titles and messages in Spanish.
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
echo " VendGuard: Test de Integración - CoordinatorIncidentDetailApiTest (T-IDM-17)\n";
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

// ─── Usuarios semilla y tokens ───────────────────────────────────────────────
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$coordinatorId    = $coordinator->getId();
$technicianId     = $technician->getId();
$authHdr          = ['Authorization' => "Bearer {$coordinatorToken}"];

// ─── Máquinas semilla para las incidencias de prueba ─────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper: crea incidencia usando el repositorio ────────────────────────────
$makeIncident = function (
    int $machineId,
    int $locationId,
    IncidentStatus $status = IncidentStatus::REGISTERED,
    UrgencyLevel $urgency = UrgencyLevel::CRITICAL
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket activo previo si existiese (candado de una avería por máquina)
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    // Sufijo en mayúsculas: la resolución de código de ticket normaliza con strtoupper
    $code = 'T17-' . strtoupper(uniqid());
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::OTHER,
        description: 'Expediente de prueba para la certificación de integración del módulo de detalle.',
        urgency: $urgency,
        status: $status,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    if ($status !== IncidentStatus::REGISTERED) {
        $pdo->prepare("UPDATE incidents SET status = :status WHERE id = :id")->execute([
            ':status' => $status->value,
            ':id'     => $created->getId(),
        ]);
        return $incidentRepo->findById($created->getId());
    }

    return $created;
};

// =========================================================================
// CASO 1: RBAC del endpoint de detalle (Art. V.4, defensa en profundidad)
// =========================================================================

echo "\n--- Caso 1: RBAC del detalle integral (401 / 403) ---\n";

// 1.1 Sin token => 401
$req = new Request(method: 'GET', path: '/api/coordinator/incidents/1/detail');
$res = $router->dispatch($req);
$assert("1.1 Sin token => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token de técnico (se exige COORDINATOR) => 403
$req = new Request(method: 'GET', path: '/api/coordinator/incidents/1/detail', headers: ['Authorization' => "Bearer {$technicianToken}"]);
$res = $router->dispatch($req);
$assert("1.2 Técnico en ruta de coordinador => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: Endpoint de detalle enriquecido (RF-01, RF-02, RF-03, RNF-01)
// [CONDICIÓN "HECHO CUANDO"]: una única lectura agregada devuelve la ficha
// completa con todos sus bloques, por ID numérico y por código de ticket.
// =========================================================================

echo "\n--- Caso 2: Detalle enriquecido por ID y por código de ticket ---\n";

$incDetail = $makeIncident($mach1->getId(), $location1->getId(), IncidentStatus::REGISTERED, UrgencyLevel::CRITICAL);

// 2.1 Detalle por ID numérico
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incDetail->getId()}/detail", headers: $authHdr);
$res = $router->dispatch($req);
$body       = $res->getDecodedBody();
$dataById   = $body['data'] ?? [];
$assert("2.1 Detalle por ID => 200 OK con success true", $res->getStatusCode() === 200 && ($body['success'] ?? false) === true, "Código HTTP: {$res->getStatusCode()}");
$assert("2.2 Bloque incident: id, código y estado", ($dataById['incident']['id'] ?? null) === $incDetail->getId() && ($dataById['incident']['ticket_code'] ?? null) === $incDetail->getTicketCode() && ($dataById['incident']['status'] ?? null) === 'REGISTERED');
$assert("2.3 Bloque location: sede y centro", ($dataById['location']['code'] ?? null) === 'SEDE-BCN-01' && isset($dataById['location']['name']));
$assert("2.4 Bloque machine: código, modelo e indicador sanitario (Art. II)", ($dataById['machine']['code'] ?? null) === $mach1->getCode() && ($dataById['machine']['model'] ?? null) === $mach1->getModel() && array_key_exists('has_perishables', $dataById['machine'] ?? []));
$assert("2.5 Bloque technician: expediente sin responsable aún", ($dataById['technician']['assigned'] ?? null) === false);
$assert("2.6 Bloque timeline con hito de creación", isset($dataById['timeline']['created_at']));
$assert("2.7 Bloque sla con límite evaluado (RF-03)", array_key_exists('has_sla_limit', $dataById['sla'] ?? []));
$assert("2.8 Permisos de triaje activos en estado REGISTERED (RF-07.1)", ($dataById['permissions']['can_assign'] ?? false) === true && ($dataById['permissions']['can_cancel'] ?? false) === true && ($dataById['permissions']['can_add_comment'] ?? false) === true);
$assert("2.9 Bloque refund oculto sin expediente de reintegro (RF-06.2)", ($dataById['refund']['has_refund'] ?? null) === false);

// 2.2 Detalle por código de ticket (la resolución admite ID o código, plan §2.1)
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incDetail->getTicketCode()}/detail", headers: $authHdr);
$res = $router->dispatch($req);
$dataByCode = $res->getDecodedBody()['data'] ?? [];
$assert("2.10 Detalle por código de ticket => 200 y misma ficha", $res->getStatusCode() === 200 && ($dataByCode['incident']['id'] ?? null) === $incDetail->getId());

// 2.3 Incidencia inexistente => 404 INCIDENT_NOT_FOUND (RF-01.3)
$req = new Request(method: 'GET', path: '/api/coordinator/incidents/999999/detail', headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.11 Incidencia inexistente => 404 INCIDENT_NOT_FOUND", $res->getStatusCode() === 404 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// 2.4 Identificador numérico no positivo => 400 INVALID_INCIDENT_IDENTIFIER (los
// códigos alfanuméricos cortos como 'abc' son códigos de ticket válidos y resuelven
// como 404 INCIDENT_NOT_FOUND, certificado en 2.11).
$req = new Request(method: 'GET', path: '/api/coordinator/incidents/0/detail', headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.12 Identificador no positivo (0) => 400 INVALID_INCIDENT_IDENTIFIER", $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_IDENTIFIER', "Código HTTP: {$res->getStatusCode()}");

// =========================================================================
// CASO 3: Asignación técnica desde el modal (RF-07.3) con auditoría (RNF-04)
// =========================================================================

echo "\n--- Caso 3: Asignación técnica desde el modal con auditoría ---\n";

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/assign",
    parsedBody: ['technician_id' => $technicianId],
    headers: $authHdr
);
$res       = $router->dispatch($req);
$assignBody = $res->getDecodedBody();
$assert("3.1 Asignación => 200 OK con success true", $res->getStatusCode() === 200 && ($assignBody['success'] ?? false) === true, "Código HTTP: {$res->getStatusCode()}");
$assert("3.2 data.status => ASSIGNED", ($assignBody['data']['status'] ?? null) === 'ASSIGNED');
$assert("3.3 data.assigned_at presente", isset($assignBody['data']['assigned_at']) && $assignBody['data']['assigned_at'] !== null);

$row = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$incDetail->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.4 BD: técnico asignado", ($row['status'] ?? null) === 'ASSIGNED' && (int)($row['assigned_technician_id'] ?? 0) === $technicianId);

// Evento inmutable INCIDENT_ASSIGNED en audit_log (RNF-04, Art. III.3)
$auditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_ASSIGNED' ORDER BY id DESC LIMIT 1");
$auditStmt->execute([':id' => $incDetail->getId()]);
$assignedAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
$assert("3.5 Evento INCIDENT_ASSIGNED persistido en audit_log", $assignedAudit !== false, 'Sin filas de auditoría para el ticket.');
if ($assignedAudit !== false) {
    $assignedState = json_decode((string)$assignedAudit['new_state'], true) ?? [];
    $assert("3.6 audit_log: técnico nuevo y actor coordinador", ($assignedState['assigned_technician_id'] ?? null) === $technicianId && (int)($assignedAudit['user_id'] ?? 0) === $coordinatorId && ($assignedAudit['user_role'] ?? null) === 'COORDINATOR');
}

// El detalle enriquecido refleja al responsable y habilita la reasignación
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incDetail->getId()}/detail", headers: $authHdr);
$res        = $router->dispatch($req);
$detailData = $res->getDecodedBody()['data'] ?? [];
$assert("3.7 Detalle expone al técnico responsable con código de operador", ($detailData['technician']['assigned'] ?? null) === true && ($detailData['technician']['technician_id'] ?? null) === $technicianId && isset($detailData['technician']['name']) && isset($detailData['technician']['assigned_at']));
$assert("3.8 Detalle habilita la reasignación tras la asignación (RF-07.3)", ($detailData['permissions']['can_reassign'] ?? false) === true);

// =========================================================================
// CASO 4: Nota interna de taller en la bitácora (RF-05.2, RF-05.3, RNF-04)
// [CONDICIÓN "HECHO CUANDO"]: la nota de taller se publica, queda registrada
// en audit_log con INCIDENT_COMMENT_ADDED y el detalle expone su visibilidad.
// =========================================================================

echo "\n--- Caso 4: Nota interna de taller con auditoría ---\n";

$internalNote = 'Nota interna de taller: compresor con condensador sucio; recambio solicitado al almacén central.';
$req = new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/comments",
    parsedBody: ['comment_text' => $internalNote, 'is_internal' => true],
    headers: $authHdr
);
$res          = $router->dispatch($req);
$commentBody  = $res->getDecodedBody();
$assert("4.1 Nota interna => 201 Created", $res->getStatusCode() === 201 && ($commentBody['success'] ?? false) === true, "Código HTTP: {$res->getStatusCode()}");
$assert("4.2 data: texto íntegro y visibilidad interna", str_contains((string)($commentBody['data']['comment_text'] ?? ''), 'condensador sucio') && ($commentBody['data']['is_internal'] ?? null) === true);

$commentAuditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_COMMENT_ADDED' ORDER BY id DESC LIMIT 1");
$commentAuditStmt->execute([':id' => $incDetail->getId()]);
$commentAudit = $commentAuditStmt->fetch(PDO::FETCH_ASSOC);
$assert("4.3 Evento INCIDENT_COMMENT_ADDED persistido en audit_log", $commentAudit !== false, 'Sin filas de auditoría del comentario.');
if ($commentAudit !== false) {
    $commentState    = json_decode((string)$commentAudit['new_state'], true) ?? [];
    $commentMetadata = json_decode((string)$commentAudit['metadata'], true) ?? [];
    $assert("4.4 audit_log: nota interna y actor coordinador", ($commentState['is_internal'] ?? null) === true && (int)($commentAudit['user_id'] ?? 0) === $coordinatorId && ($commentMetadata['visibility'] ?? null) === 'INTERNAL');
}

// El detalle enriquecido lista la nota con su visibilidad (RF-05.2)
$req        = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incDetail->getId()}/detail", headers: $authHdr);
$res        = $router->dispatch($req);
$detailData = $res->getDecodedBody()['data'] ?? [];
$internalEntries = array_values(array_filter(
    is_array($detailData['comments'] ?? null) ? $detailData['comments'] : [],
    static fn (array $entry): bool => str_contains((string)($entry['comment_text'] ?? ''), 'condensador sucio')
));
$assert("4.5 Detalle expone la nota interna con su bandera de visibilidad", count($internalEntries) === 1 && ($internalEntries[0]['is_internal'] ?? null) === true);

// Comentario demasiado corto => 422 COMMENT_TOO_SHORT (validación temprana)
$req = new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/comments",
    parsedBody: ['comment_text' => 'Ok'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("4.6 Comentario corto => 422 COMMENT_TOO_SHORT", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_TOO_SHORT');

// =========================================================================
// CASO 5: Descarte justificado desde el modal (RF-07.4) con umbral de 20 (T-IDM-20)
// [CONDICIÓN "HECHO CUANDO"]: motivo válido => soft delete CANCELLED con rastro
// íntegro en incident_history; motivo corto => 422 sin persistir cambio alguno.
// =========================================================================

echo "\n--- Caso 5: Descarte justificado y rechazo de motivos cortos ---\n";

// 5.1 Motivo de 19 caracteres reales => 422 CANCELLATION_REASON_TOO_SHORT
$reasonShort = 'Descarte duplicados'; // 19 caracteres reales exactos
$assert("5.1 Fixture: motivo de 19 caracteres reales", mb_strlen($reasonShort, 'UTF-8') === 19);

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/cancel",
    parsedBody: ['cancellation_reason' => $reasonShort],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("5.1 Motivo corto => 422 CANCELLATION_REASON_TOO_SHORT", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'CANCELLATION_REASON_TOO_SHORT', "Código HTTP: {$res->getStatusCode()}");

$row = $pdo->query("SELECT status, cancellation_reason, cancelled_at FROM incidents WHERE id = {$incDetail->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("5.2 Incidencia intacta tras el rechazo: sin descarte ni motivo persistidos", ($row['status'] ?? null) === 'ASSIGNED' && ($row['cancellation_reason'] ?? null) === null && ($row['cancelled_at'] ?? null) === null);

// 5.3 Motivo válido (>= 20 caracteres reales) => 200 y soft delete CANCELLED (Art. III.2)
$discardReason = 'Avería duplicada confirmada telefónicamente con la sede; el ticket gemelo ya está en ruta.';
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/cancel",
    parsedBody: ['cancellation_reason' => $discardReason],
    headers: $authHdr
);
$res          = $router->dispatch($req);
$discardBody  = $res->getDecodedBody();
$assert("5.3 Descarte válido => 200 OK con success true", $res->getStatusCode() === 200 && ($discardBody['success'] ?? false) === true, "Código HTTP: {$res->getStatusCode()}");
$assert("5.4 data.status => CANCELLED con cancelled_at", ($discardBody['data']['status'] ?? null) === 'CANCELLED' && isset($discardBody['data']['cancelled_at']));

$row = $pdo->query("SELECT status, cancellation_reason, cancelled_at, deleted_at FROM incidents WHERE id = {$incDetail->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("5.5 BD: fila preservada físicamente (sin hard delete) con motivo y fecha", $row !== false && ($row['status'] ?? null) === 'CANCELLED' && ($row['cancellation_reason'] ?? null) === $discardReason && ($row['deleted_at'] ?? null) === null);

// Trazabilidad del descarte en incident_history (Art. III.3). Nota: la emisión del
// evento INCIDENT_CANCELLED en audit_log permanece pendiente (api_contracts.md §4.3).
$historyStmt = $pdo->prepare("SELECT from_status, to_status, action_note, user_id FROM incident_history WHERE incident_id = :id ORDER BY id DESC LIMIT 1");
$historyStmt->execute([':id' => $incDetail->getId()]);
$lastHistory = $historyStmt->fetch(PDO::FETCH_ASSOC);
$assert("5.6 Historial inmutable: ASSIGNED => CANCELLED con motivo y coordinador", $lastHistory !== false && ($lastHistory['from_status'] ?? null) === 'ASSIGNED' && ($lastHistory['to_status'] ?? null) === 'CANCELLED' && str_contains((string)($lastHistory['action_note'] ?? ''), 'duplicada') && (int)($lastHistory['user_id'] ?? 0) === $coordinatorId);

// Evento inmutable INCIDENT_CANCELLED en audit_log (RNF-04, Art. III.3, RF-07.4)
$cancelAuditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_CANCELLED' ORDER BY id DESC LIMIT 1");
$cancelAuditStmt->execute([':id' => $incDetail->getId()]);
$cancelAudit = $cancelAuditStmt->fetch(PDO::FETCH_ASSOC);
$assert("5.7 Evento INCIDENT_CANCELLED persistido en audit_log", $cancelAudit !== false, 'Sin filas de auditoría del descarte.');
if ($cancelAudit !== false) {
    $cancelState    = json_decode((string)$cancelAudit['new_state'], true) ?? [];
    $cancelMetadata = json_decode((string)$cancelAudit['metadata'], true) ?? [];
    $assert("5.7 audit_log: motivo del descarte, fecha y actor coordinador", ($cancelState['cancellation_reason'] ?? null) === $discardReason && isset($cancelState['cancelled_at']) && (int)($cancelAudit['user_id'] ?? 0) === $coordinatorId && ($cancelMetadata['ticket_code'] ?? null) === $incDetail->getTicketCode());
}

// 5.8 El detalle en modo consulta refleja el descarte y sella los permisos (RF-07.2, RF-04.4)
$req        = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incDetail->getId()}/detail", headers: $authHdr);
$res        = $router->dispatch($req);
$detailData = $res->getDecodedBody()['data'] ?? [];
$assert("5.8 Detalle: estado CANCELLED con bloque de cancelación justificada (RF-04.4)", ($detailData['incident']['status'] ?? null) === 'CANCELLED' && ($detailData['technical_intervention']['cancellation']['is_cancelled'] ?? false) === true);
$assert("5.9 Detalle: acciones operativas inhabilitadas en estado terminal (RF-07.2)", ($detailData['permissions']['can_assign'] ?? true) === false && ($detailData['permissions']['can_cancel'] ?? true) === false && ($detailData['permissions']['can_reassign'] ?? true) === false && ($detailData['permissions']['can_add_comment'] ?? true) === false);

// 5.10 La bitácora de un ticket descartado está sellada (RF-07.2, Art. V.6)
$req = new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incDetail->getId()}/comments",
    parsedBody: ['comment_text' => 'Intento de comentar sobre una incidencia descartada.'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("5.10 Comentario sobre ticket descartado => 422 COMMENT_WINDOW_CLOSED", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_WINDOW_CLOSED');

// =========================================================================
// CASO 6: Prueba HTTP real vía cURL contra 127.0.0.1:8000
// =========================================================================

echo "\n--- Caso 6: HTTP real (cURL) del endpoint de detalle ---\n";

$incCurl = $makeIncident($mach2->getId(), $location1->getId());
$url = "http://127.0.0.1:8000/api/coordinator/incidents/{$incCurl->getId()}/detail";
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        "Authorization: Bearer {$coordinatorToken}",
    ],
    CURLOPT_TIMEOUT => 5,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json       = json_decode((string)$raw, true) ?? [];
$curlDetail = $json['data'] ?? [];

$assert("6.1 HTTP real => 200 OK con success true", $code === 200 && ($json['success'] ?? false) === true, "Código HTTP: {$code}, Body: " . substr((string)$raw, 0, 200));
$assert("6.2 HTTP real: ficha enriquecida completa sobre el expediente correcto", ($curlDetail['incident']['id'] ?? null) === $incCurl->getId() && ($curlDetail['incident']['ticket_code'] ?? null) === $incCurl->getTicketCode() && isset($curlDetail['location']['name'], $curlDetail['machine']['code'], $curlDetail['timeline']['created_at'], $curlDetail['permissions']['can_assign']));

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
