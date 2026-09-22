<?php

declare(strict_types=1);

/**
 * AssignTechnicianEndpointTest (T-26)
 *
 * Validates PATCH /api/coordinator/incidents/{id}/assign:
 *   - EARS 5.1: REGISTERED & REOPENED => ASSIGNED correctly.
 *   - EARS 5.2: Only one technician per incident at a time (re-assignment rejected).
 *   - EARS 5.3: Urgency reclassification with mandatory reason stored in history.
 *   - EARS 5.4: Missing/invalid technician_id is rejected.
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
echo " VendGuard: Test de Integración - AssignTechnicianEndpointTest (T-26)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

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
$row = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$incB->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.7 BD: status=ASSIGNED", ($row['status'] ?? null) === 'ASSIGNED');
$assert("3.8 BD: assigned_technician_id correcto", (int)($row['assigned_technician_id'] ?? 0) === $technicianId);

// =========================================================================
// CASO 4: Unicidad de técnico — re-asignación rechazada (EARS 5.2)
// =========================================================================
echo "\n--- Caso 4: Re-asignación de incidencia ya ASSIGNED (EARS 5.2) ---\n";

$req2 = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incB->getId()}/assign", parsedBody: ['technician_id' => $technicianId], headers: $authHdr);
$res2 = $router->dispatch($req2);
$assert("4.1 Re-asignación => success=false", ($res2->getDecodedBody()['success'] ?? true) === false, json_encode($res2->getDecodedBody()));
$assert("4.2 Re-asignación => INVALID_STATUS_FOR_ASSIGNMENT", ($res2->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_ASSIGNMENT');

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

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
