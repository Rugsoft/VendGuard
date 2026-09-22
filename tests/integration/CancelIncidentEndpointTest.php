<?php

declare(strict_types=1);

/**
 * CancelIncidentEndpointTest (T-27)
 *
 * Validates PATCH /api/coordinator/incidents/{id}/cancel:
 *   - EARS 6.1: Requires a mandatory cancellation reason.
 *   - EARS 6.2: Logical cancellation (Soft Delete), changing status to CANCELLED and preserving the row in DB.
 *   - EARS 6.3: Blocks cancellation if reason is missing/empty or state transition is illegal.
 *   - RNF-03 / Article III: No hard deletion (DELETE FROM), audit trail in incident_history.
 *   - Auth guard: 401/403 for unauthenticated or unauthorized users.
 *   - Real HTTP verification via cURL.
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
echo " VendGuard: Test de Integración - CancelIncidentEndpointTest (T-27)\n";
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
$coordinatorId    = $coordinator->getId();

// ─── Máquinas para crear incidencias de prueba ───────────────────────────────
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
    UrgencyLevel $urgency = UrgencyLevel::MEDIUM
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket activo previo si existiese
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    $code = 'T27-' . uniqid();
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::OTHER,
        description: 'Aviso de prueba para cancelación T-27.',
        urgency: $urgency,
        status: $status,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    // Si se requiere un estado distinto a REGISTERED, actualizarlo en BD
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
// CASO 1: Control de Acceso y RBAC (401 / 403)
// =========================================================================
echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/cancel', parsedBody: ['cancellation_reason' => 'Falsa alarma']);
$res = $router->dispatch($req);
$assert("1.1 Sin token => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token inválido => 401
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/cancel', parsedBody: ['cancellation_reason' => 'Falsa alarma'], headers: ['Authorization' => 'Bearer fake_token_xyz']);
$res = $router->dispatch($req);
$assert("1.2 Token inválido => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.3 Token de técnico (se exige COORDINATOR) => 403
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/1/cancel', parsedBody: ['cancellation_reason' => 'Falsa alarma'], headers: ['Authorization' => "Bearer {$technicianToken}"]);
$res = $router->dispatch($req);
$assert("1.3 Técnico en ruta de coordinador => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: Validación de Entrada (EARS 6.1, 6.3)
// =========================================================================
echo "\n--- Caso 2: Validación de Entrada (EARS 6.1, 6.3) ---\n";

$authHdr = ['Authorization' => "Bearer {$coordinatorToken}"];

// 2.1 Motivo ausente => 422 MISSING_CANCELLATION_REASON (EARS 6.1, 6.3)
$incA = $makeIncident($mach1->getId(), $location1->getId());
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incA->getId()}/cancel", parsedBody: [], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.1 Motivo ausente => 422", $res->getStatusCode() === 422);
$assert("2.1 Motivo ausente => MISSING_CANCELLATION_REASON", ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_CANCELLATION_REASON');

// 2.2 Motivo vacío o solo espacios => 422 (EARS 6.3)
$req = new Request(method: 'PATCH', path: "/api/coordinator/incidents/{$incA->getId()}/cancel", parsedBody: ['cancellation_reason' => '   '], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.2 Motivo vacío => 422 MISSING_CANCELLATION_REASON", ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_CANCELLATION_REASON');

// 2.3 ID de incidencia no numérico => 400 INVALID_INCIDENT_ID
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/abc/cancel', parsedBody: ['cancellation_reason' => 'Falsa alarma'], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.3 ID alfanumérico => 400 INVALID_INCIDENT_ID", $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_ID');

// 2.4 Incidencia inexistente => 404 INCIDENT_NOT_FOUND
$req = new Request(method: 'PATCH', path: '/api/coordinator/incidents/999999/cancel', parsedBody: ['cancellation_reason' => 'Falsa alarma'], headers: $authHdr);
$res = $router->dispatch($req);
$assert("2.4 Incidencia inexistente => 404 INCIDENT_NOT_FOUND", $res->getStatusCode() === 404 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// =========================================================================
// CASO 3: Cancelación Exitosa desde REGISTERED [CONDICIÓN "HECHO CUANDO"]
// Exige motivo de descarte y cambia el estado a CANCELADA sin borrar la fila de la base de datos.
// =========================================================================
echo "\n--- Caso 3: Cancelación Exitosa desde REGISTERED (EARS 6.1, 6.2, RNF-03) ---\n";

$incB = $makeIncident($mach2->getId(), $location1->getId());
$reasonB = "Comprobado por llamada telefónica: el usuario no introdujo monedas correctamente, falsa alarma.";

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incB->getId()}/cancel",
    parsedBody: ['cancellation_reason' => $reasonB],
    headers: $authHdr
);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("3.1 HTTP 200 OK", $res->getStatusCode() === 200, "Código HTTP: {$res->getStatusCode()}");
$assert("3.2 success: true", ($body['success'] ?? false) === true);
$data3 = $body['data'] ?? [];
$assert("3.3 data.id correcto", ($data3['id'] ?? null) === $incB->getId());
$assert("3.4 data.status => CANCELLED", ($data3['status'] ?? null) === 'CANCELLED');
$assert("3.5 data.cancelled_at presente y no nulo", isset($data3['cancelled_at']) && $data3['cancelled_at'] !== null);

// Comprobación en Base de Datos: la fila NO fue borrada físicamente (RNF-03, Artículo III, EARS 6.2)
$dbRow = $pdo->query("SELECT * FROM incidents WHERE id = {$incB->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.6 Fila existe físicamente en BD (sin hard delete)", $dbRow !== false && !empty($dbRow));
$assert("3.7 BD: status = CANCELLED", ($dbRow['status'] ?? null) === 'CANCELLED');
$assert("3.8 BD: cancellation_reason coincide con el motivo proporcionado", ($dbRow['cancellation_reason'] ?? null) === $reasonB);
$assert("3.9 BD: cancelled_at no es nulo", !empty($dbRow['cancelled_at']));
$assert("3.10 BD: deleted_at es NULL (borrado lógico por estado, no borrado físico)", $dbRow['deleted_at'] === null);

// Comprobación en incident_history: trazabilidad completa
$historyRows = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incB->getId()} ORDER BY id DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
$assert("3.11 Historial inmutable registrado", count($historyRows) > 0);
if (!empty($historyRows)) {
    $lastHist = $historyRows[0];
    $assert("3.12 Historial: from_status = REGISTERED", ($lastHist['from_status'] ?? null) === 'REGISTERED');
    $assert("3.13 Historial: to_status = CANCELLED", ($lastHist['to_status'] ?? null) === 'CANCELLED');
    $assert("3.14 Historial: acción contiene el motivo", str_contains((string)($lastHist['action_note'] ?? ''), $reasonB));
    $assert("3.15 Historial: user_id registrado con ID del coordinador", (int)($lastHist['user_id'] ?? 0) === $coordinatorId);
}

// Comprobación: Candado de máquina liberado (la máquina ya puede recibir otro aviso)
$mach2Active = $incidentRepo->findActiveByMachineId($mach2->getId());
$assert("3.16 Máquina liberada: no tiene ticket activo tras cancelación", $mach2Active === null);

// =========================================================================
// CASO 4: Cancelación desde otros estados legales (ASSIGNED, IN_PROGRESS, REOPENED)
// =========================================================================
echo "\n--- Caso 4: Cancelación desde ASSIGNED, IN_PROGRESS y REOPENED ---\n";

// 4.1 Desde ASSIGNED
$incAssigned = $makeIncident($mach3->getId(), $location2->getId(), IncidentStatus::ASSIGNED);
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incAssigned->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Técnico notifica que la avería ya estaba subsanada'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("4.1 ASSIGNED => CANCELLED (HTTP 200)", $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? null) === 'CANCELLED');

// 4.2 Desde IN_PROGRESS
$incInProgress = $makeIncident($mach1->getId(), $location1->getId(), IncidentStatus::IN_PROGRESS);
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incInProgress->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Máquina retirada de las instalaciones del cliente'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("4.2 IN_PROGRESS => CANCELLED (HTTP 200)", $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? null) === 'CANCELLED');

// 4.3 Desde REOPENED
$incReopened = $makeIncident($mach2->getId(), $location1->getId(), IncidentStatus::REOPENED);
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incReopened->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Reapertura por error de un usuario distinto'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("4.3 REOPENED => CANCELLED (HTTP 200)", $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? null) === 'CANCELLED');

// =========================================================================
// CASO 5: Bloqueo de cancelación desde estados no permitidos (EARS 6.3)
// =========================================================================
echo "\n--- Caso 5: Bloqueo de Cancelación desde Estados Terminales o No Permitidos ---\n";

// 5.1 Re-cancelación de una incidencia ya CANCELLED
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incB->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Intento de cancelar una segunda vez'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("5.1 Ya CANCELLED => 422", $res->getStatusCode() === 422);
$assert("5.1 Ya CANCELLED => INVALID_STATUS_FOR_CANCELLATION", ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_CANCELLATION');

// 5.2 Incidencia en estado CLOSED
$incClosed = $makeIncident($mach3->getId(), $location2->getId(), IncidentStatus::CLOSED);
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incClosed->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Intento de cancelar incidencia cerrada'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("5.2 CLOSED => 422 INVALID_STATUS_FOR_CANCELLATION", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_CANCELLATION');

// 5.3 Incidencia en estado RESOLVED
$incResolved = $makeIncident($mach1->getId(), $location1->getId(), IncidentStatus::RESOLVED);
$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incResolved->getId()}/cancel",
    parsedBody: ['cancellation_reason' => 'Intento de cancelar incidencia resuelta'],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert("5.3 RESOLVED => 422 INVALID_STATUS_FOR_CANCELLATION", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_CANCELLATION');

// =========================================================================
// CASO 6: Prueba HTTP Real vía cURL
// =========================================================================
echo "\n--- Caso 6: Prueba HTTP Real (cURL) contra 127.0.0.1:8000 ---\n";

$incCurl = $makeIncident($mach2->getId(), $location1->getId(), IncidentStatus::REGISTERED);
$curlReason = "Descarte real vía cURL: máquina revisada presencialmente por el conserje y funcionando perfectamente.";

$url = "http://127.0.0.1:8000/api/coordinator/incidents/{$incCurl->getId()}/cancel";
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode(['cancellation_reason' => $curlReason]),
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
$assert("6.2 HTTP real => success: true", ($json['success'] ?? false) === true);
$assert("6.3 HTTP real => status: CANCELLED", ($json['data']['status'] ?? null) === 'CANCELLED');
$assert("6.4 HTTP real => cancelled_at presente", isset($json['data']['cancelled_at']) && $json['data']['cancelled_at'] !== null);

// Verificar en BD que la fila sigue existiendo (preservada físicamente)
$curlDb = $pdo->query("SELECT * FROM incidents WHERE id = {$incCurl->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("6.5 Fila preservada en BD tras llamada HTTP real", $curlDb !== false && ($curlDb['status'] ?? null) === 'CANCELLED');

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
