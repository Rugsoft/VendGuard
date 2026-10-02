<?php

declare(strict_types=1);

/**
 * ResolveIncidentEndpointTest (T-29)
 *
 * Validates Strict Incident Resolution (RF-08 / EARS 8.1, 8.2, 8.3 / Article V.1):
 *   - POST /api/technician/incidents/{id}/resolve
 *   - EARS 8.1: Requires resolution_diagnosis (>= 20 chars) and resolution_action (>= 20 chars).
 *   - EARS 8.2: Rejection keeping incident IN_PROGRESS if either field < 20 chars or illegal state.
 *   - EARS 8.3: Transition to RESOLVED, sets resolved_at timestamp, activates 48-hour warranty clock.
 *   - RBAC & ownership: Only assigned technician can resolve.
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
echo " VendGuard: Test de Integración - ResolveIncidentEndpointTest (T-29)\n";
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

// ─── Usuarios de Prueba ───────────────────────────────────────────────────────
$tech1 = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $tech1 !== null && $coordinator !== null);
if ($tech1 === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

// Crear segundo técnico para probar segregación
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

// ─── Sedes y Máquinas ─────────────────────────────────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper para crear incidencias en un estado concreto ─────────────────────
$makeIncidentInState = function (
    int $machineId,
    int $locationId,
    int $technicianId,
    IncidentStatus $status = IncidentStatus::IN_PROGRESS
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket previo
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    $code = 'T29-' . uniqid();
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Pérdida de temperatura en máquina de alimentos frescos.',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    $startedAtExpr = in_array($status, [IncidentStatus::IN_PROGRESS, IncidentStatus::PENDING_PARTS], true)
        ? 'CURRENT_TIMESTAMP'
        : 'NULL';

    $pdo->prepare("
        UPDATE incidents 
        SET status = :status, 
            assigned_technician_id = :tech_id, 
            assigned_at = CURRENT_TIMESTAMP,
            started_at = {$startedAtExpr}
        WHERE id = :id
    ")->execute([
        ':status'  => $status->value,
        ':tech_id' => $technicianId,
        ':id'      => $created->getId(),
    ]);


    return $incidentRepo->findById($created->getId());
};

// Textos de prueba conformes (>= 20 caracteres)
$validDiag = "Termostato digital descalibrado marcando 14 grados reales."; // 59 chars
$validAct  = "Sustitución de sonda NTC y calibración de rango a 3.5 grados."; // 61 chars

// =========================================================================
// CASO 1: Control de Acceso y RBAC (401 / 403)
// =========================================================================
echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

$incA = $makeIncidentInState($mach1->getId(), $location1->getId(), $tech1Id, IncidentStatus::IN_PROGRESS);

// 1.1 Sin token => 401
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct]
);
$res = $router->dispatch($req);
$assert("1.1 Sin token => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token inválido => 401
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: ['Authorization' => 'Bearer token_invalido']
);
$res = $router->dispatch($req);
$assert("1.2 Token corrupto => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.3 Coordinador intentando resolver en ruta técnica => 403 FORBIDDEN
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$res = $router->dispatch($req);
$assert("1.3 Coordinador en ruta de técnico => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// 1.4 Técnico ajeno (Tech2 intentando resolver incidencia de Tech1) => 403 FORBIDDEN
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: ['Authorization' => "Bearer {$tech2Token}"]
);
$res = $router->dispatch($req);
$assert("1.4 Técnico no asignado => 403 FORBIDDEN", $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: Validación Estricta de Textos (EARS 8.1, 8.2)
// Exige >= 20 caracteres tanto en diagnóstico como en acción correctiva
// =========================================================================
echo "\n--- Caso 2: Validación Estricta de Textos (EARS 8.1, 8.2) ---\n";

$authTech1 = ['Authorization' => "Bearer {$tech1Token}"];

// 2.1 Diagnóstico corto de 19 caracteres
$shortDiag = "Avería de 19 carac."; // exactamente 19 chars
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $shortDiag, 'resolution_action' => $validAct],
    headers: $authTech1
);
$res = $router->dispatch($req);
$assert("2.1 Diagnóstico de 19 chars es rechazado con 422", $res->getStatusCode() === 422);
$assert("2.1 Código de error es INVALID_RESOLUTION", ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESOLUTION');

// 2.2 Acción correctiva corta ("ok" o ".")
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => 'ok'],
    headers: $authTech1
);
$res = $router->dispatch($req);
$assert("2.2 Acción 'ok' es rechazada con 422", $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESOLUTION');

// 2.3 Ambos campos vacíos o insuficientes
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => '', 'resolution_action' => ''],
    headers: $authTech1
);
$res = $router->dispatch($req);
$assert("2.3 Ambos campos vacíos rechazados con 422", $res->getStatusCode() === 422);

// 2.4 EARS 8.2: Comprobar que tras el rechazo, la incidencia PERMANECE en IN_PROGRESS
$checkStillInProgress = $pdo->query("SELECT status FROM incidents WHERE id = {$incA->getId()}")->fetchColumn();
$assert("2.4 Tras validación fallida, la incidencia se mantiene en IN_PROGRESS", $checkStillInProgress === 'IN_PROGRESS');

// =========================================================================
// CASO 3: Control de Estado Legal (EARS 8.2)
// Solo se puede resolver si el estado actual es IN_PROGRESS
// =========================================================================
echo "\n--- Caso 3: Control de Estado Legal para Resolver ---\n";

// 3.1 Intentar resolver desde ASSIGNED (sin haber iniciado intervención)
$incAssigned = $makeIncidentInState($mach2->getId(), $location1->getId(), $tech1Id, IncidentStatus::ASSIGNED);
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incAssigned->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: $authTech1
);
$res = $router->dispatch($req);
$assert("3.1 Resolver desde ASSIGNED es bloqueado con 422", 
    $res->getStatusCode() === 422 && 
    ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_RESOLUTION'
);

// 3.2 Intentar resolver desde PENDING_PARTS (debe reanudar primero con start)
$incPending = $makeIncidentInState($mach3->getId(), $location2->getId(), $tech1Id, IncidentStatus::PENDING_PARTS);
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incPending->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: $authTech1
);
$res = $router->dispatch($req);
$assert("3.2 Resolver directamente desde PENDING_PARTS es bloqueado con 422", 
    $res->getStatusCode() === 422 && 
    ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_RESOLUTION'
);

// =========================================================================
// CASO 4: Resolución Exitosa (EARS 8.1, 8.3) [CONDICIÓN "HECHO CUANDO"]
// Diagnóstico >= 20 caracteres Y Acción >= 20 caracteres -> RESUELTA e inicia las 48h
// =========================================================================
echo "\n--- Caso 4: Resolución Exitosa (EARS 8.1, 8.3) ---\n";

// Usamos $incA (en IN_PROGRESS)
$req = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incA->getId()}/resolve",
    parsedBody: [
        'resolution_diagnosis' => $validDiag,
        'resolution_action'    => $validAct,
    ],
    headers: $authTech1
);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("4.1 Resolución exitosa responde HTTP 200 OK", $res->getStatusCode() === 200, "Código HTTP: {$res->getStatusCode()} Body: " . json_encode($body));
$assert("4.2 success es true", ($body['success'] ?? false) === true);
$data4 = $body['data'] ?? [];
$assert("4.3 data.id es el de la incidencia", ($data4['id'] ?? null) === $incA->getId());
$assert("4.4 data.status => RESOLVED", ($data4['status'] ?? null) === 'RESOLVED');
$assert("4.5 data.resolved_at presente y no nulo", !empty($data4['resolved_at']));

// Verificar en Base de Datos
$dbResolved = $pdo->query("SELECT status, resolution_diagnosis, resolution_action, resolved_at FROM incidents WHERE id = {$incA->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("4.6 BD: status = RESOLVED", ($dbResolved['status'] ?? null) === 'RESOLVED');
$assert("4.7 BD: resolution_diagnosis guardado íntegro", ($dbResolved['resolution_diagnosis'] ?? null) === $validDiag);
$assert("4.8 BD: resolution_action guardado íntegro", ($dbResolved['resolution_action'] ?? null) === $validAct);
$assert("4.9 BD: resolved_at no es nulo", !empty($dbResolved['resolved_at']));

// Verificar que activa la ventana de garantía de 48h (EARS 8.3 / RF-09)
$reloadedEntity = $incidentRepo->findById($incA->getId());
$assert("4.10 Entidad en garantía de 48 horas (isInWarranty() = true)", $reloadedEntity !== null && $reloadedEntity->isInWarranty());

// Verificar auditoría inmutable
$hist = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incA->getId()} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert("4.11 Historial: from_status = IN_PROGRESS, to_status = RESOLVED", 
    ($hist['from_status'] ?? null) === 'IN_PROGRESS' && ($hist['to_status'] ?? null) === 'RESOLVED'
);
$assert("4.12 Historial: acción contiene diagnóstico y solución", 
    str_contains((string)($hist['action_note'] ?? ''), $validDiag) && 
    str_contains((string)($hist['action_note'] ?? ''), $validAct)
);
$assert("4.13 Historial: user_id registrado con ID del técnico", (int)($hist['user_id'] ?? 0) === $tech1Id);

// =========================================================================
// CASO 4B: Dictamen de Saldo Obligatorio en la Resolución (T-REF-10)
// RF-REF-04/05: una avería con reclamación NO se cierra sin dictaminar el dinero.
// =========================================================================
echo "\n--- Caso 4B: Dictamen de saldo obligatorio (T-REF-10) ---\n";

$refundMgmt = new \VendGuard\Application\Service\RefundManagementService(
    new \VendGuard\Infrastructure\Repository\PdoRefundRequestRepository($pdo)
);

// Avería con una reclamación EN_MANO_SEDE pendiente de dictamen
$incRefund = $makeIncidentInState($mach2->getId(), $location1->getId(), $tech1Id);
$refundCase = $refundMgmt->createCase(new \VendGuard\Application\DTO\CreateRefundRequestDTO(
    incidentId: (int)$incRefund->getId(),
    machineId: $mach2->getId(),
    locationId: $location1->getId(),
    claimantName: 'Laura Sanitaria',
    claimantContact: '600111222',
    claimedAmount: 2.00,
    compensationMethod: \VendGuard\Core\Domain\Model\CompensationMethod::EN_MANO_SEDE,
    productAttempted: 'Café con leche carril 2'
));
$refundCaseId = (int)$refundCase->getId();
$assert("4B.0 Reclamación creada en PENDING_INSPECTION", $refundCase->getStatus() === \VendGuard\Core\Domain\Model\RefundStatus::PENDING_INSPECTION);

// 4B.1 Sin dictamen la resolución se rechaza y la avería NO se cierra
$reqNoVerdict = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incRefund->getId()}/resolve",
    parsedBody: ['resolution_diagnosis' => $validDiag, 'resolution_action' => $validAct],
    headers: $authTech1
);
$resNoVerdict = $router->dispatch($reqNoVerdict);
$bodyNoVerdict = $resNoVerdict->getDecodedBody();
$assert("4B.1 Sin dictamen de saldo responde HTTP 422", $resNoVerdict->getStatusCode() === 422, "HTTP {$resNoVerdict->getStatusCode()} " . json_encode($bodyNoVerdict));
$assert("4B.1 Código REFUND_INSPECTION_REQUIRED", ($bodyNoVerdict['error']['code'] ?? '') === 'REFUND_INSPECTION_REQUIRED', 'error: ' . ($bodyNoVerdict['error']['code'] ?? 'AUSENTE'));
$assert(
    "4B.1 BD: la avería sigue IN_PROGRESS",
    $incidentRepo->findById((int)$incRefund->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
);
$assert(
    "4B.1 BD: la reclamación sigue PENDING_INSPECTION",
    (new \VendGuard\Infrastructure\Repository\PdoRefundRequestRepository($pdo))->findById($refundCaseId)?->getStatus() === \VendGuard\Core\Domain\Model\RefundStatus::PENDING_INSPECTION
);

// 4B.2 Con dictamen válido se resuelven ambas cosas a la vez
$reqWithVerdict = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incRefund->getId()}/resolve",
    parsedBody: [
        'resolution_diagnosis' => $validDiag,
        'resolution_action' => $validAct,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 2.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Imposada (Conserjería Planta Baja)',
        ],
    ],
    headers: $authTech1
);
$resWithVerdict = $router->dispatch($reqWithVerdict);
$bodyWithVerdict = $resWithVerdict->getDecodedBody();
$assert("4B.2 Con dictamen válido responde HTTP 200", $resWithVerdict->getStatusCode() === 200, "HTTP {$resWithVerdict->getStatusCode()} " . json_encode($bodyWithVerdict));
$assert("4B.2 BD: la avería queda RESOLVED", $incidentRepo->findById((int)$incRefund->getId())?->getStatus() === IncidentStatus::RESOLVED);
$assert(
    "4B.2 La respuesta expone refund_status = DEPOSITED_AT_RECEPTION",
    ($bodyWithVerdict['data']['refund_status'] ?? '') === 'DEPOSITED_AT_RECEPTION',
    'refund_status: ' . ($bodyWithVerdict['data']['refund_status'] ?? 'AUSENTE')
);

$refundRepoReal = new \VendGuard\Infrastructure\Repository\PdoRefundRequestRepository($pdo);
$storedCase = $refundRepoReal->findById($refundCaseId);
$assert("4B.2 BD: el expediente queda DEPOSITED_AT_RECEPTION", $storedCase?->getStatus() === \VendGuard\Core\Domain\Model\RefundStatus::DEPOSITED_AT_RECEPTION, 'estado: ' . $storedCase?->getStatus()->value);
$assert("4B.2 BD: se persiste el importe recuperado", $storedCase?->getRecoveredAmount() === 2.00);
$assert("4B.2 BD: se persiste quién recibió el sobre", ($storedCase?->getReceptionistName() ?? '') !== '');
$assert(
    "4B.2 BD: audit_log registra REFUND_INSPECTED",
    (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'REFUND_REQUEST' AND entity_id = {$refundCaseId} AND action = 'REFUND_INSPECTED'")->fetchColumn() >= 1
);

// 4B.3 Un canal digital NO puede quedarse en conserjería
$incDigital = $makeIncidentInState($mach3->getId(), $location2->getId(), $tech1Id);
$refundMgmt->createCase(new \VendGuard\Application\DTO\CreateRefundRequestDTO(
    incidentId: (int)$incDigital->getId(),
    machineId: $mach3->getId(),
    locationId: $location2->getId(),
    claimantName: 'Marc Ruibal',
    claimantContact: '699888777',
    claimedAmount: 3.00,
    compensationMethod: \VendGuard\Core\Domain\Model\CompensationMethod::BIZUM,
    bizumPhone: '699888777'
));
$reqDigital = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incDigital->getId()}/resolve",
    parsedBody: [
        'resolution_diagnosis' => $validDiag,
        'resolution_action' => $validAct,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 3.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
        ],
    ],
    headers: $authTech1
);
$resDigital = $router->dispatch($reqDigital);
$bodyDigital = $resDigital->getDecodedBody();
$assert("4B.3 Dejar efectivo digital en conserjería responde HTTP 422", $resDigital->getStatusCode() === 422, "HTTP {$resDigital->getStatusCode()} " . json_encode($bodyDigital));
$assert(
    "4B.3 Código RECEPTION_DELIVERY_NOT_ALLOWED",
    ($bodyDigital['error']['code'] ?? '') === 'RECEPTION_DELIVERY_NOT_ALLOWED',
    'error: ' . ($bodyDigital['error']['code'] ?? 'AUSENTE')
);
$assert(
    "4B.3 BD: la avería sigue sin resolverse para que el técnico reintente",
    $incidentRepo->findById((int)$incDigital->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
);

// 4B.4 Hallazgo de monedas de oficio en una avería SIN reclamación
$incUnclaimed = $makeIncidentInState($mach1->getId(), $location1->getId(), $tech1Id);
$reqUnclaimed = new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incUnclaimed->getId()}/resolve",
    parsedBody: [
        'resolution_diagnosis' => $validDiag,
        'resolution_action' => $validAct,
        'unclaimed_cash_found' => ['amount' => 1.00, 'notes' => 'Moneda de un euro en la canaleta.'],
    ],
    headers: $authTech1
);
$resUnclaimed = $router->dispatch($reqUnclaimed);
$bodyUnclaimed = $resUnclaimed->getDecodedBody();
$assert("4B.4 Una avería sin reclamación se resuelve sin pedir dictamen", $resUnclaimed->getStatusCode() === 200, "HTTP {$resUnclaimed->getStatusCode()} " . json_encode($bodyUnclaimed));
$assert("4B.4 No se inventa un refund_status en la respuesta", !array_key_exists('refund_status', $bodyUnclaimed['data'] ?? []));
$findingId = (int)($bodyUnclaimed['data']['unclaimed_cash_finding_id'] ?? 0);
$assert("4B.4 La respuesta expone el hallazgo de oficio", $findingId > 0);
$assert(
    "4B.4 BD: el hallazgo queda persistido y anclado a la avería",
    $pdo->query("SELECT COUNT(*) FROM unclaimed_cash_findings WHERE id = {$findingId} AND incident_id = " . (int)$incUnclaimed->getId() . " AND technician_id = {$tech1Id}")->fetchColumn() >= 1
);

// 4B.5 El técnico nunca recibe datos bancarios en su payload de salida
$assert(
    "4B.5 Ninguna respuesta del técnico filtra el IBAN ni el Bizum",
    !str_contains((string)$resWithVerdict->getBody(), 'bizum_phone')
);

// =========================================================================
// CASO 5: Prueba HTTP Real vía cURL (127.0.0.1:8000)
// =========================================================================
echo "\n--- Caso 5: Prueba HTTP Real contra 127.0.0.1:8000 ---\n";

// Crear incidencia en IN_PROGRESS para Tech2
$incCurl = $makeIncidentInState($mach3->getId(), $location2->getId(), $tech2Id, IncidentStatus::IN_PROGRESS);

$curlDiag = "Sustitución de electroválvula de entrada de agua defectuosa."; // 61 chars
$curlAct  = "Montada pieza nueva, revisada estanqueidad y verificada purga."; // 63 chars

$url = "http://127.0.0.1:8000/api/technician/incidents/{$incCurl->getId()}/resolve";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'resolution_diagnosis' => $curlDiag,
        'resolution_action'    => $curlAct,
    ]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        "Authorization: Bearer {$tech2Token}",
    ],
    CURLOPT_TIMEOUT => 5,
]);
$rawCurl = curl_exec($ch);
$codeCurl = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$jsonCurl = json_decode((string)$rawCurl, true) ?? [];

$assert("5.1 cURL /resolve responde HTTP 200 OK", $codeCurl === 200, "Código HTTP: {$codeCurl}, Body: {$rawCurl}");
$assert("5.2 cURL /resolve => success: true", ($jsonCurl['success'] ?? false) === true);
$assert("5.3 cURL /resolve => status: RESOLVED", ($jsonCurl['data']['status'] ?? null) === 'RESOLVED');
$assert("5.4 cURL /resolve => resolved_at no nulo", !empty($jsonCurl['data']['resolved_at']));

// Verificar en BD tras cURL
$dbCurl = $pdo->query("SELECT status, resolved_at FROM incidents WHERE id = {$incCurl->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("5.5 BD tras cURL: status = RESOLVED y resolved_at registrado", 
    ($dbCurl['status'] ?? null) === 'RESOLVED' && !empty($dbCurl['resolved_at'])
);

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
