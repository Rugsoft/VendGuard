<?php

declare(strict_types=1);

/**
 * TechnicianRouteEndpointTest (T-28)
 *
 * Validates Technician Field Operations (RF-07, EARS 7.1, 7.2, 7.3):
 *   - GET /api/technician/my-route: Lists only incidents assigned to the authenticated technician.
 *   - PATCH /api/technician/incidents/{id}/start: Transitions ASSIGNED or PENDING_PARTS to IN_PROGRESS.
 *   - PATCH /api/technician/incidents/{id}/pause: Transitions IN_PROGRESS to PENDING_PARTS with mandatory reason.
 *   - Ownership & security: Technicians can only operate on their own assigned incidents.
 *   - Real HTTP test via cURL.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
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
echo " VendGuard: Test de Integración - TechnicianRouteEndpointTest (T-28)\n";
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

// ─── Usuarios de Prueba ───────────────────────────────────────────────────────
$tech1 = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $tech1 !== null && $coordinator !== null);
if ($tech1 === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

// Crear un segundo técnico para validar segregación de rutas
$pdo->prepare("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`, `is_active`)
    VALUES ('Carlos Técnico Segundo', 'carlos.segundo@vendguard.internal', :hash, 'TECHNICIAN', '688999000', 1)
    ON DUPLICATE KEY UPDATE `is_active` = 1
")->execute([':hash' => password_hash('Password123!', PASSWORD_BCRYPT)]);

$tech2 = $userRepo->findByEmail('carlos.segundo@vendguard.internal');
$assert("0. Segundo técnico preparado", $tech2 !== null);

$tech1Token       = $authService->generateInternalToken($tech1);
$tech2Token       = $authService->generateInternalToken($tech2);
$coordinatorToken = $authService->generateInternalToken($coordinator);

$tech1Id = $tech1->getId();
$tech2Id = $tech2->getId();

// ─── Máquinas y Sedes ─────────────────────────────────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper para crear incidencias asignadas ──────────────────────────────────
$makeAssignedIncident = function (
    int $machineId,
    int $locationId,
    int $technicianId,
    IncidentStatus $status = IncidentStatus::ASSIGNED,
    UrgencyLevel $urgency = UrgencyLevel::HIGH
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket previo en la máquina
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    $code = 'T28-' . uniqid();
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Aviso para prueba de ruta de técnico T-28.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    // Asignar en BD al técnico
    $pdo->prepare("
        UPDATE incidents 
        SET status = :status, assigned_technician_id = :tech_id, assigned_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([
        ':status'  => $status->value,
        ':tech_id' => $technicianId,
        ':id'      => $created->getId(),
    ]);

    return $incidentRepo->findById($created->getId());
};

// =========================================================================
// CASO 1: Control de Acceso y RBAC (401 / 403)
// =========================================================================
echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token => 401
$req = new Request(method: 'GET', path: '/api/technician/my-route');
$res = $router->dispatch($req);
$assert("1.1 Sin token en /my-route => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token corrupto => 401
$req = new Request(method: 'GET', path: '/api/technician/my-route', headers: ['Authorization' => 'Bearer token_invalido']);
$res = $router->dispatch($req);
$assert("1.2 Token corrupto => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.3 Coordinador en ruta exclusiva de técnico => 403 FORBIDDEN
$req = new Request(method: 'GET', path: '/api/technician/my-route', headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res = $router->dispatch($req);
$assert("1.3 Coordinador en ruta de técnico => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: GET /api/technician/my-route (RF-07) [CONDICIÓN "HECHO CUANDO"]
// Lista solo las asignadas al técnico autenticado
// =========================================================================
echo "\n--- Caso 2: GET /api/technician/my-route (RF-07) ---\n";

// Crear incidencias asignadas: Inc1 -> Tech1, Inc2 -> Tech2
$incTech1 = $makeAssignedIncident($mach1->getId(), $location1->getId(), $tech1Id);
$incTech2 = $makeAssignedIncident($mach2->getId(), $location1->getId(), $tech2Id);

// Petición de Tech1
$req = new Request(method: 'GET', path: '/api/technician/my-route', headers: ['Authorization' => "Bearer {$tech1Token}"]);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("2.1 /my-route responde HTTP 200 OK", $res->getStatusCode() === 200);
$assert("2.2 success es true", ($body['success'] ?? false) === true);

$data = $body['data'] ?? [];
$tech1IncIds = array_column($data, 'id');

$assert("2.3 Contiene la incidencia asignada a Tech1 (#{$incTech1->getId()})", in_array($incTech1->getId(), $tech1IncIds, true));
$assert("2.4 NO contiene la incidencia asignada a Tech2 (#{$incTech2->getId()})", !in_array($incTech2->getId(), $tech1IncIds, true));

// Verificar estructura del primer elemento según contrato 5.1
$firstItem = null;
foreach ($data as $item) {
    if ($item['id'] === $incTech1->getId()) {
        $firstItem = $item;
        break;
    }
}
$assert("2.5 Subobjeto 'machine' presente con code, model, floor_wing", 
    isset($firstItem['machine']['code']) && 
    isset($firstItem['machine']['model']) && 
    isset($firstItem['machine']['floor_wing'])
);
$assert("2.6 Subobjeto 'location' presente con name, address, contact_phone", 
    isset($firstItem['location']['name']) && 
    isset($firstItem['location']['address']) && 
    isset($firstItem['location']['contact_phone'])
);

// =========================================================================
// CASO 3: PATCH /api/technician/incidents/{id}/start (RF-07 / EARS 7.1) [CONDICIÓN "HECHO CUANDO"]
// Transiciona a EN_CURSO (IN_PROGRESS) y fija started_at
// =========================================================================
echo "\n--- Caso 3: PATCH /start transiciona a IN_PROGRESS (EARS 7.1) ---\n";

$req = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/start",
    headers: ['Authorization' => "Bearer {$tech1Token}"]
);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("3.1 /start responde HTTP 200 OK", $res->getStatusCode() === 200, "Código HTTP: {$res->getStatusCode()}");
$assert("3.2 success es true", ($body['success'] ?? false) === true);
$assert("3.3 data.status => IN_PROGRESS", ($body['data']['status'] ?? null) === 'IN_PROGRESS');
$assert("3.4 data.started_at está fijado y no es nulo", !empty($body['data']['started_at']));

// Verificar en BD
$dbRow = $pdo->query("SELECT status, started_at FROM incidents WHERE id = {$incTech1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.5 BD: status = IN_PROGRESS", ($dbRow['status'] ?? null) === 'IN_PROGRESS');
$assert("3.6 BD: started_at no es nulo", !empty($dbRow['started_at']));

// Verificar auditoría inmutable
$hist = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incTech1->getId()} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert("3.7 Historial: from_status = ASSIGNED, to_status = IN_PROGRESS", 
    ($hist['from_status'] ?? null) === 'ASSIGNED' && ($hist['to_status'] ?? null) === 'IN_PROGRESS'
);
$assert("3.8 Historial: registrado por el técnico", (int)($hist['user_id'] ?? 0) === $tech1Id);

// =========================================================================
// CASO 4: PATCH /api/technician/incidents/{id}/pause (RF-07 / EARS 7.2) [CONDICIÓN "HECHO CUANDO"]
// Transiciona a PENDIENTE_REPUESTO (PENDING_PARTS)
// =========================================================================
echo "\n--- Caso 4: PATCH /pause transiciona a PENDING_PARTS (EARS 7.2) ---\n";

// 4.1 Intento de pausa sin motivo => 422
$reqNoReason = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/pause",
    parsedBody: [],
    headers: ['Authorization' => "Bearer {$tech1Token}"]
);
$resNoReason = $router->dispatch($reqNoReason);
$assert("4.1 /pause sin motivo => 422 MISSING_PENDING_PARTS_REASON", 
    $resNoReason->getStatusCode() === 422 && 
    ($resNoReason->getDecodedBody()['error']['code'] ?? '') === 'MISSING_PENDING_PARTS_REASON'
);

// 4.2 Pausa válida con descripción de repuesto
$partReason = "Se requiere electroválvula de entrada de agua de 24V (referencia VENDO-EV24). Solicitada a central.";
$reqPause = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/pause",
    parsedBody: ['pending_parts_reason' => $partReason],
    headers: ['Authorization' => "Bearer {$tech1Token}"]
);
$resPause = $router->dispatch($reqPause);
$bodyPause = $resPause->getDecodedBody();

$assert("4.2 /pause con motivo responde HTTP 200 OK", $resPause->getStatusCode() === 200);
$assert("4.3 data.status => PENDING_PARTS", ($bodyPause['data']['status'] ?? null) === 'PENDING_PARTS');

// Verificar en BD
$dbPause = $pdo->query("SELECT status, pending_parts_reason FROM incidents WHERE id = {$incTech1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("4.4 BD: status = PENDING_PARTS", ($dbPause['status'] ?? null) === 'PENDING_PARTS');
$assert("4.5 BD: pending_parts_reason coincide", ($dbPause['pending_parts_reason'] ?? null) === $partReason);

// Verificar auditoría
$histPause = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incTech1->getId()} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert("4.6 Historial: to_status = PENDING_PARTS y contiene motivo de pieza", 
    ($histPause['to_status'] ?? null) === 'PENDING_PARTS' && 
    str_contains((string)($histPause['action_note'] ?? ''), 'VENDO-EV24')
);

// =========================================================================
// CASO 5: Reanudación de Trabajos tras Repuesto (EARS 7.3)
// PENDING_PARTS => IN_PROGRESS mediante /start
// =========================================================================
echo "\n--- Caso 5: Reanudación desde PENDING_PARTS a IN_PROGRESS (EARS 7.3) ---\n";

$startedAtBefore = $dbRow['started_at'];

$reqResume = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/start",
    headers: ['Authorization' => "Bearer {$tech1Token}"]
);
$resResume = $router->dispatch($reqResume);
$bodyResume = $resResume->getDecodedBody();

$assert("5.1 Reanudación responde HTTP 200 OK", $resResume->getStatusCode() === 200);
$assert("5.2 data.status => IN_PROGRESS", ($bodyResume['data']['status'] ?? null) === 'IN_PROGRESS');

// started_at original se conserva (EARS 7.1/7.3)
$dbResume = $pdo->query("SELECT status, started_at FROM incidents WHERE id = {$incTech1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("5.3 started_at original conservado (no sobreescrito)", $dbResume['started_at'] === $startedAtBefore);

// =========================================================================
// CASO 6: Propiedad de la Incidencia y Control de Transiciones Ilegales
// =========================================================================
echo "\n--- Caso 6: Segregación entre Técnicos y Transiciones Ilegales ---\n";

// 6.1 Tech2 intenta hacer /start en la incidencia de Tech1 => 403 FORBIDDEN
$reqAlien = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/start",
    headers: ['Authorization' => "Bearer {$tech2Token}"]
);
$resAlien = $router->dispatch($reqAlien);
$assert("6.1 Técnico no asignado no puede operar incidencia ajena => 403 FORBIDDEN", 
    $resAlien->getStatusCode() === 403 && 
    ($resAlien->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

// 6.2 Tech2 intenta hacer /pause en la incidencia de Tech1 => 403 FORBIDDEN
$reqAlienPause = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech1->getId()}/pause",
    parsedBody: ['pending_parts_reason' => 'Intento ilegítimo'],
    headers: ['Authorization' => "Bearer {$tech2Token}"]
);
$resAlienPause = $router->dispatch($reqAlienPause);
$assert("6.2 Técnico no asignado no puede pausar incidencia ajena => 403 FORBIDDEN", 
    $resAlienPause->getStatusCode() === 403 && 
    ($resAlienPause->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

// 6.3 Intentar pausar incidencia en estado ASSIGNED (debe estar en IN_PROGRESS) => 422
$reqPauseAssigned = new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incTech2->getId()}/pause",
    parsedBody: ['pending_parts_reason' => 'No se puede pausar antes de iniciar'],
    headers: ['Authorization' => "Bearer {$tech2Token}"]
);
$resPauseAssigned = $router->dispatch($reqPauseAssigned);
$assert("6.3 Pausar desde ASSIGNED sin haber iniciado => 422 INVALID_STATUS_FOR_PAUSE", 
    $resPauseAssigned->getStatusCode() === 422 && 
    ($resPauseAssigned->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_PAUSE'
);

// =========================================================================
// CASO 7: Prueba HTTP Real vía cURL (127.0.0.1:8000)
// =========================================================================
echo "\n--- Caso 7: Prueba HTTP Real contra 127.0.0.1:8000 ---\n";

// 7.1 GET /api/technician/my-route vía cURL
$ch = curl_init("http://127.0.0.1:8000/api/technician/my-route");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tech1Token}"],
    CURLOPT_TIMEOUT => 5,
]);
$rawCurl = curl_exec($ch);
$codeCurl = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$jsonCurl = json_decode((string)$rawCurl, true) ?? [];
$assert("7.1 cURL /my-route => 200 OK", $codeCurl === 200);
$assert("7.2 cURL /my-route => success: true", ($jsonCurl['success'] ?? false) === true);
$assert("7.3 cURL /my-route devuelve array de averías", is_array($jsonCurl['data'] ?? null));

// 7.2 PATCH /api/technician/incidents/{id}/start vía cURL sobre la incidencia de Tech2
$chStart = curl_init("http://127.0.0.1:8000/api/technician/incidents/{$incTech2->getId()}/start");
curl_setopt_array($chStart, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tech2Token}"],
    CURLOPT_TIMEOUT => 5,
]);
$rawStart = curl_exec($chStart);
$codeStart = curl_getinfo($chStart, CURLINFO_HTTP_CODE);
curl_close($chStart);

$jsonStart = json_decode((string)$rawStart, true) ?? [];
$assert("7.4 cURL /start => 200 OK", $codeStart === 200);
$assert("7.5 cURL /start => status IN_PROGRESS", ($jsonStart['data']['status'] ?? null) === 'IN_PROGRESS');

// 7.3 PATCH /api/technician/incidents/{id}/pause vía cURL
$chPause = curl_init("http://127.0.0.1:8000/api/technician/incidents/{$incTech2->getId()}/pause");
curl_setopt_array($chPause, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_POSTFIELDS => json_encode(['pending_parts_reason' => 'Fusible fundido de 5A']),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$tech2Token}"
    ],
    CURLOPT_TIMEOUT => 5,
]);
$rawPause = curl_exec($chPause);
$codePause = curl_getinfo($chPause, CURLINFO_HTTP_CODE);
curl_close($chPause);

$jsonPause = json_decode((string)$rawPause, true) ?? [];
$assert("7.6 cURL /pause => 200 OK", $codePause === 200);
$assert("7.7 cURL /pause => status PENDING_PARTS", ($jsonPause['data']['status'] ?? null) === 'PENDING_PARTS');

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
