<?php

declare(strict_types=1);

/**
 * ReopenIncidentEndpointTest
 * 
 * Test de Integración para el Endpoint de Reapertura de Incidencias en Garantía (Tarea T-24).
 * Requisitos: RF-09 (EARS 9.1, 9.2, 9.3).
 * 
 * Valida la condición "Hecho cuando:":
 * 1. POST /api/incidents/{ticket_code}/reopen pasa el estado al canónico REOPENED,
 *    con la etiqueta legible en `status_label` (contrato normalizado, api_contracts.md §3.4).
 * 2. Pone assigned_technician_id = NULL (desasignación automática del técnico).
 * 3. Reinicia el reloj de 48h (resolved_at = NULL).
 * 4. Rechaza si han pasado >48h (HTTP 422 REOPEN_WINDOW_EXPIRED).
 * 5. Bloquea con "Avería Crónica" (HTTP 422 CHRONIC_INCIDENT_LIMIT) a la 3ª reincidencia.
 * 6. Validaciones de entrada (400 MISSING_REOPEN_REASON, 422 REOPEN_REASON_TOO_SHORT, 404 INCIDENT_NOT_FOUND).
 * 7. Transiciones inválidas (422 INVALID_TRANSITION si el estado no es RESOLVED).
 * 8. Segregación de sede (403 SITE_MISMATCH).
 * 9. Prueba HTTP real contra el servidor local en 127.0.0.1:8000.
 * 10. Evento inmutable REOPEN_TICKET en audit_log (EARS 5.1.2): actor SITE_MANAGER,
 *     estado previo RESOLVED y nuevo REOPENED, una sola vez por reapertura efectiva.
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
echo " VendGuard: Test de Integración - ReopenIncidentEndpointTest (T-24)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo);

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

// 1. Obtener sedes, máquina y técnico
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert("1. Sedes cargadas correctamente", $location1 !== null && $location2 !== null);

if ($location1 === null || $location2 === null) {
    echo "ERROR: Sedes no disponibles.\n";
    exit(1);
}

$machines = $machineRepo->findActiveByLocationId($location1->getId());
$targetMachine = $machines[0] ?? null;
$assert("2. Máquina objetivo en SEDE-BCN-01 encontrada", $targetMachine !== null);

$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert("3. Técnico asignable encontrado", $technician !== null);

$tokenLoc1 = $authService->generateSiteToken($location1);
$tokenLoc2 = $authService->generateSiteToken($location2);

// =========================================================================
// CASO 1: Reapertura exitosa (1ª reincidencia) dentro de las 48h (EARS 9.1)
// =========================================================================
echo "\n--- Caso 1: 1ª Reapertura en garantía (HTTP 200) ---\n";

// Crear un ticket resuelto hace 2 horas asignado a técnico1
$now = new DateTimeImmutable();
$resolvedAtRecent = $now->sub(new DateInterval('PT2H'))->format('Y-m-d H:i:s');

$incident1 = new Incident(
    id: null,
    ticketCode: 'INC-2026-T2401',
    machineId: $targetMachine->getId(),
    locationId: $location1->getId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Atasco en espiral de barritas energéticas.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::RESOLVED,
    assignedTechnicianId: $technician->getId(),
    reporterName: 'Marta Guardia',
    reporterPhone: '611999888',
    resolvedAt: $resolvedAtRecent,
    resolutionDiagnosis: 'Espiral 3 desalineada.',
    resolutionAction: 'Alineación de motor y prueba de giro.'
);
$created1 = $incidentRepo->create($incident1, $technician->getId(), 'Incidencia resuelta inicialmente');

$req1 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2401/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'La misma espiral 3 vuelve a atascarse al comprar.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res1 = $router->dispatch($req1);

$assert("1.1 Petición de reapertura responde HTTP 200 OK", $res1->getStatusCode() === 200);
$body1 = $res1->getDecodedBody();
$assert("1.2 Respuesta devuelve el estado canónico REOPENED", ($body1['data']['status'] ?? '') === 'REOPENED');
$assert("1.2b Respuesta devuelve la etiqueta legible en status_label", ($body1['data']['status_label'] ?? '') === 'Reabierta');
$assert("1.2c status_canonical se conserva como alias deprecado de status", ($body1['data']['status_canonical'] ?? '') === 'REOPENED');
$assert("1.3 assigned_technician_id en respuesta es NULL", array_key_exists('assigned_technician_id', $body1['data'] ?? []) && $body1['data']['assigned_technician_id'] === null);

// Comprobar estado en BD
$dbInc1 = $incidentRepo->findByTicketCode('INC-2026-T2401');
$assert("1.4 BD: status pasa a REOPENED", $dbInc1 !== null && $dbInc1->getStatus() === IncidentStatus::REOPENED);
$assert("1.5 BD: assigned_technician_id = NULL", $dbInc1 !== null && $dbInc1->getAssignedTechnicianId() === null);
$assert("1.6 BD: reloj de 48h reiniciado (resolved_at = NULL)", $dbInc1 !== null && $dbInc1->getResolvedAt() === null);
$assert("1.7 BD: reopened_at establecido", $dbInc1 !== null && $dbInc1->getReopenedAt() !== null);
$assert("1.8 BD: reopen_reason guardado correctamente", $dbInc1 !== null && str_contains((string)$dbInc1->getReopenReason(), 'espiral 3 vuelve a atascarse'));

// Comprobar el evento inmutable de auditoría del cambio de estado (EARS 5.1.2).
$fetchReopenAudits = function () use ($pdo, $dbInc1): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :eid AND action = 'REOPEN_TICKET' ORDER BY id ASC"
    );
    $stmt->execute([':eid' => (int)$dbInc1->getId()]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$reopenAudits = $fetchReopenAudits();
$firstReopenAudit = $reopenAudits[0] ?? [];
$auditPrevState = json_decode((string)($firstReopenAudit['previous_state'] ?? ''), true) ?: [];
$auditNewState = json_decode((string)($firstReopenAudit['new_state'] ?? ''), true) ?: [];
$auditMetadata = json_decode((string)($firstReopenAudit['metadata'] ?? ''), true) ?: [];

$assert("1.10 Auditoría: la reapertura registra el evento inmutable REOPEN_TICKET",
    count($reopenAudits) === 1,
    'Eventos REOPEN_TICKET: ' . count($reopenAudits)
);
$assert("1.11 Auditoría: actor SITE_MANAGER identificado con la sede y sin user_id interno",
    ($firstReopenAudit['user_role'] ?? '') === 'SITE_MANAGER'
    && array_key_exists('user_id', $firstReopenAudit) && $firstReopenAudit['user_id'] === null
    && str_contains((string)($firstReopenAudit['user_name'] ?? ''), 'Responsable de Sede'),
    'role=' . var_export($firstReopenAudit['user_role'] ?? null, true) . ' name=' . var_export($firstReopenAudit['user_name'] ?? null, true)
);
$assert("1.12 Auditoría: estado previo RESOLVED con el técnico saliente y nuevo REOPENED sin técnico",
    ($auditPrevState['status'] ?? '') === 'RESOLVED'
    && (int)($auditPrevState['assigned_technician_id'] ?? 0) === (int)$technician->getId()
    && ($auditNewState['status'] ?? '') === 'REOPENED'
    && array_key_exists('assigned_technician_id', $auditNewState) && $auditNewState['assigned_technician_id'] === null
    && str_contains((string)($auditNewState['reopen_reason'] ?? ''), 'espiral 3'),
    'prev=' . json_encode($auditPrevState) . ' new=' . json_encode($auditNewState)
);
$assert("1.13 Auditoría: la metadata trae el código de ticket y el número de reincidencia",
    ($auditMetadata['ticket_code'] ?? '') === 'INC-2026-T2401' && (int)($auditMetadata['reopen_count'] ?? 0) === 1,
    'metadata=' . json_encode($auditMetadata)
);

// Comprobar auditoría en incident_history
$history1 = $incidentRepo->getHistory((int)$dbInc1->getId());
$lastHistory1 = end($history1);
$assert("1.9 Historial: registrado evento REOPENED con '1ª reincidencia'", 
    $lastHistory1 !== false && 
    $lastHistory1->getToStatus() === 'REOPENED' && 
    str_contains($lastHistory1->getActionNote() ?? '', '1ª reincidencia')
);

// =========================================================================
// CASO 2: 2ª Reapertura exitosa tras nueva resolución
// =========================================================================
echo "\n--- Caso 2: 2ª Reapertura en garantía (HTTP 200) ---\n";

// Simular resolución de la 1ª reincidencia por el técnico
$resolvedAt2 = (new DateTimeImmutable())->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
$pdo->prepare("
    UPDATE incidents 
    SET status = 'RESOLVED', 
        assigned_technician_id = :tech_id, 
        resolved_at = :resolved_at,
        resolution_diagnosis = 'Segundo diagnóstico',
        resolution_action = 'Sustitución de resorte'
    WHERE ticket_code = 'INC-2026-T2401'
")->execute([':tech_id' => $technician->getId(), ':resolved_at' => $resolvedAt2]);

$incidentRepo->insertHistory((int)$dbInc1->getId(), (int)$technician->getId(), 'REOPENED', 'RESOLVED', 'Resuelta por segunda vez');

// 2ª Reapertura
$req2 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2401/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Vuelve a fallar tras el cambio de resorte.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res2 = $router->dispatch($req2);

$assert("2.1 2ª Reapertura responde HTTP 200 OK", $res2->getStatusCode() === 200);
$body2 = $res2->getDecodedBody();
$assert("2.2 2ª Reapertura devuelve el estado canónico REOPENED con su etiqueta", ($body2['data']['status'] ?? '') === 'REOPENED' && ($body2['data']['status_label'] ?? '') === 'Reabierta');

$dbInc2 = $incidentRepo->findByTicketCode('INC-2026-T2401');
$assert("2.3 BD: assigned_technician_id es NULL tras 2ª reapertura", $dbInc2 !== null && $dbInc2->getAssignedTechnicianId() === null);
$assert("2.4 BD: resolved_at es NULL tras 2ª reapertura", $dbInc2 !== null && $dbInc2->getResolvedAt() === null);

// Comprobar contador de reaperturas
$reopenCountAfter2 = $incidentRepo->countReopenEvents((int)$dbInc2->getId());
$assert("2.5 Contador de reaperturas es 2", $reopenCountAfter2 === 2);

// =========================================================================
// CASO 3: 3ª Reincidencia bloqueada como "Avería Crónica" (EARS 9.3)
// =========================================================================
echo "\n--- Caso 3: 3ª Reapertura bloqueada como 'Avería Crónica' (HTTP 422) ---\n";

// Simular resolución de la 2ª reincidencia
$resolvedAt3 = (new DateTimeImmutable())->sub(new DateInterval('PT30M'))->format('Y-m-d H:i:s');
$pdo->prepare("
    UPDATE incidents 
    SET status = 'RESOLVED', 
        assigned_technician_id = :tech_id, 
        resolved_at = :resolved_at,
        resolution_diagnosis = 'Tercer diagnóstico',
        resolution_action = 'Ajuste de sensor'
    WHERE ticket_code = 'INC-2026-T2401'
")->execute([':tech_id' => $technician->getId(), ':resolved_at' => $resolvedAt3]);

$incidentRepo->insertHistory((int)$dbInc1->getId(), (int)$technician->getId(), 'REOPENED', 'RESOLVED', 'Resuelta por tercera vez');

// Intentar 3ª reapertura -> Debe ser bloqueada
$req3 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2401/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Tercer fallo consecutivo, sigue bloqueada.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res3 = $router->dispatch($req3);

$assert("3.1 3ª Reapertura responde HTTP 422 Unprocessable Entity", $res3->getStatusCode() === 422);
$body3 = $res3->getDecodedBody();
$assert("3.2 Código de error es CHRONIC_INCIDENT_LIMIT", ($body3['error']['code'] ?? '') === 'CHRONIC_INCIDENT_LIMIT');
$assert("3.3 Detalle contiene etiqueta 'Avería Crónica'", ($body3['error']['details']['tag'] ?? '') === 'Avería Crónica');
$assert("3.4 Mensaje explica el bloqueo para auditoría técnica", str_contains($body3['error']['message'] ?? '', 'Avería Crónica'));

// Verificar que la BD guardó la marca de Avería Crónica
$checkChronic = $pdo->prepare("SELECT reopen_reason, status FROM incidents WHERE ticket_code = 'INC-2026-T2401'");
$checkChronic->execute();
$chronicRow = $checkChronic->fetch(PDO::FETCH_ASSOC);
$assert("3.5 Incidencia permanece en RESOLVED (no se reabrió)", ($chronicRow['status'] ?? '') === 'RESOLVED');
$assert("3.6 reopen_reason contiene etiqueta [AVERÍA CRÓNICA]", str_contains((string)($chronicRow['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]'));

// Verificar historial con anotación de Avería Crónica
$history3 = $incidentRepo->getHistory((int)$dbInc1->getId());
$chronicHistoryFound = false;
foreach ($history3 as $h) {
    if (str_contains($h->getActionNote() ?? '', 'Avería Crónica')) {
        $chronicHistoryFound = true;
        break;
    }
}
$assert("3.7 Historial contiene apunte de catalogación como Avería Crónica", $chronicHistoryFound);

// La reapertura rechazada (expediente marcado como crónico) no debe dejar evento de auditoría.
$reopenAuditsAfterBlock = $fetchReopenAudits();
$assert("3.8 La reapertura bloqueada por Avería Crónica no emite evento REOPEN_TICKET",
    count($reopenAuditsAfterBlock) === 2,
    'Eventos REOPEN_TICKET tras el intento bloqueado: ' . count($reopenAuditsAfterBlock) . ' (esperado 2)'
);

// =========================================================================
// CASO 4: Rechazo por garantía expirada (>48h) (EARS 9.2)
// =========================================================================
echo "\n--- Caso 4: Rechazo si ventana >48h expiró (HTTP 422 REOPEN_WINDOW_EXPIRED) ---\n";

// Crear otra máquina/incidencia resuelta hace 50 horas
$machinesLoc2 = $machineRepo->findActiveByLocationId($location1->getId());
$targetMachine2 = $machinesLoc2[1] ?? $targetMachine;

// Para no colisionar con uq_machine_active_ticket en targetMachine si fuera la misma, cambiamos machine
// O usamos la máquina 2
$machine2 = $machines[1];

$resolvedAtExpired = (new DateTimeImmutable())->sub(new DateInterval('PT50H'))->format('Y-m-d H:i:s');

$incidentExpired = new Incident(
    id: null,
    ticketCode: 'INC-2026-T2402',
    machineId: $machine2->getId(),
    locationId: $location1->getId(),
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Lector de billetes rechaza billetes de 10€.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::RESOLVED,
    assignedTechnicianId: $technician->getId(),
    resolvedAt: $resolvedAtExpired,
    resolutionDiagnosis: 'Óptica del lector sucia.',
    resolutionAction: 'Limpieza de cabezal óptico.'
);
$createdExpired = $incidentRepo->create($incidentExpired, $technician->getId(), 'Resuelta hace 50h');

$req4 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2402/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Vuelve a fallar el lector de billetes.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res4 = $router->dispatch($req4);

$assert("4.1 Reabrir con garantía expirada (>48h) responde HTTP 422", $res4->getStatusCode() === 422);
$body4 = $res4->getDecodedBody();
$assert("4.2 Código de error es REOPEN_WINDOW_EXPIRED", ($body4['error']['code'] ?? '') === 'REOPEN_WINDOW_EXPIRED');
$assert("4.3 Mensaje indica necesidad de nuevo ticket", str_contains($body4['error']['message'] ?? '', 'nuevo ticket'));

// =========================================================================
// CASO 5: Transición inválida (no está en estado RESOLVED)
// =========================================================================
echo "\n--- Caso 5: Rechazo si estado no es RESOLVED (HTTP 422 INVALID_TRANSITION) ---\n";

// Modificar INC-2026-T2402 a IN_PROGRESS
$pdo->prepare("UPDATE incidents SET status = 'IN_PROGRESS' WHERE ticket_code = 'INC-2026-T2402'")->execute();

$req5 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2402/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Intento de reabrir una incidencia en progreso.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res5 = $router->dispatch($req5);

$assert("5.1 Reabrir incidencia en estado IN_PROGRESS responde HTTP 422", $res5->getStatusCode() === 422);
$body5 = $res5->getDecodedBody();
$assert("5.2 Código de error es INVALID_TRANSITION", ($body5['error']['code'] ?? '') === 'INVALID_TRANSITION');

// =========================================================================
// CASO 6: Validaciones de entrada (400 MISSING_REOPEN_REASON, 422 TOO_SHORT, 404 NOT_FOUND)
// =========================================================================
echo "\n--- Caso 6: Validaciones de entrada ---\n";

// Devolver INC-2026-T2402 a RESOLVED reciente para probar validaciones de reason
$pdo->prepare("UPDATE incidents SET status = 'RESOLVED', resolved_at = CURRENT_TIMESTAMP WHERE ticket_code = 'INC-2026-T2402'")->execute();

// 6.1 Motivo ausente
$req6a = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2402/reopen',
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res6a = $router->dispatch($req6a);
$assert("6.1 Sin reopen_reason responde HTTP 400 MISSING_REOPEN_REASON", $res6a->getStatusCode() === 400 && ($res6a->getDecodedBody()['error']['code'] ?? '') === 'MISSING_REOPEN_REASON');

// 6.2 Motivo demasiado corto (< 5 caracteres)
$req6b = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2402/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'mal'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res6b = $router->dispatch($req6b);
$assert("6.2 Motivo < 5 caracteres responde HTTP 422 REOPEN_REASON_TOO_SHORT", $res6b->getStatusCode() === 422 && ($res6b->getDecodedBody()['error']['code'] ?? '') === 'REOPEN_REASON_TOO_SHORT');

// 6.3 Incidencia inexistente
$req6c = new Request(
    method: 'POST',
    path: '/api/incidents/INC-INEXISTENTE-999/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Motivo descriptivo válido'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res6c = $router->dispatch($req6c);
$assert("6.3 Ticket inexistente responde HTTP 404 INCIDENT_NOT_FOUND", $res6c->getStatusCode() === 404 && ($res6c->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// =========================================================================
// CASO 7: Segregación de Sedes (HTTP 403 SITE_MISMATCH)
// =========================================================================
echo "\n--- Caso 7: Segregación de Sedes (HTTP 403 Forbidden) ---\n";

// Token de SEDE-BCN-02 intentando reabrir ticket de SEDE-BCN-01
$req7 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2402/reopen',
    queryParams: [],
    parsedBody: ['reopen_reason' => 'Intento de reapertura por sede no autorizada.'],
    headers: ['Authorization' => "Bearer {$tokenLoc2}"]
);
$res7 = $router->dispatch($req7);
$assert("7.1 Reapertura desde otra sede responde HTTP 403 Forbidden", $res7->getStatusCode() === 403);
$assert("7.2 Código de error es SITE_MISMATCH", ($res7->getDecodedBody()['error']['code'] ?? '') === 'SITE_MISMATCH');

// =========================================================================
// CASO 8: Llamada HTTP real contra servidor en 127.0.0.1:8000
// =========================================================================
echo "\n--- Caso 8: Prueba HTTP real contra servidor en 127.0.0.1:8000 ---\n";

$serverAvailable = false;
$chCheck = @curl_init('http://127.0.0.1:8000/api/health');
if ($chCheck !== false) {
    curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chCheck, CURLOPT_TIMEOUT, 2);
    $healthRes = curl_exec($chCheck);
    $httpCode = curl_getinfo($chCheck, CURLINFO_HTTP_CODE);
    curl_close($chCheck);
    if ($httpCode === 200) {
        $serverAvailable = true;
    }
}

if ($serverAvailable) {
    // Reabrir INC-2026-T2402 que está en RESOLVED reciente
    $postPayload = json_encode([
        'reopen_reason' => 'Reapertura ejecutada mediante llamada HTTP real cURL en vivo.'
    ]);

    $ch = curl_init('http://127.0.0.1:8000/api/incidents/INC-2026-T2402/reopen');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer {$tokenLoc1}",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseStr = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $assert("8.1 Llamada HTTP real responde HTTP 200 OK", $statusCode === 200, "Status: {$statusCode}, Body: {$responseStr}");
    $httpDecoded = json_decode((string)$responseStr, true);
    $assert("8.2 HTTP real devuelve success => true", ($httpDecoded['success'] ?? false) === true);
    $assert("8.3 HTTP real devuelve status => REOPENED con status_label => Reabierta", ($httpDecoded['data']['status'] ?? '') === 'REOPENED' && ($httpDecoded['data']['status_label'] ?? '') === 'Reabierta');
    $assert("8.4 HTTP real devuelve assigned_technician_id => NULL", array_key_exists('assigned_technician_id', $httpDecoded['data'] ?? []) && $httpDecoded['data']['assigned_technician_id'] === null);

    // Verificar en base de datos tras HTTP real
    $checkRealStmt = $pdo->prepare("SELECT status, assigned_technician_id, resolved_at FROM incidents WHERE ticket_code = 'INC-2026-T2402'");
    $checkRealStmt->execute();
    $realRow = $checkRealStmt->fetch(PDO::FETCH_ASSOC);

    $assert("8.5 BD tras HTTP real: status es REOPENED", ($realRow['status'] ?? '') === 'REOPENED');
    $assert("8.6 BD tras HTTP real: assigned_technician_id es NULL", is_array($realRow) && $realRow['assigned_technician_id'] === null);
    $assert("8.7 BD tras HTTP real: resolved_at es NULL (reloj 48h reiniciado)", is_array($realRow) && $realRow['resolved_at'] === null);
} else {
    echo "  [SKIP] Servidor local no disponible en 127.0.0.1:8000 para Caso 8.\n";
}

// =========================================================================
// CASO 9: Indicador de reintervenciones por garantía (EARS 2.3.1, Art. V.6)
// =========================================================================
echo "\n--- Caso 9: Reintervenciones por garantía en desglose por técnico ---\n";

require_once __DIR__ . '/../../src/Infrastructure/Repository/PdoMetricsRepository.php';
$metricsRepo = new \VendGuard\Infrastructure\Repository\PdoMetricsRepository($pdo);

// INC-2026-T2401 (de los casos 1-3) registra dos reaperturas dentro de las 48 h;
// ambas resoluciones las firmó $technician (Jordi). INC-2026-T2402 no tiene
// reaperturas. El periodo del filtro cubre los eventos recientes.
$metricsFilter = \VendGuard\Core\Domain\Model\MetricFilter::fromQueryParams(['period' => 'last_7_days']);
$techBreakdown = $metricsRepo->getBreakdownByTechnician($metricsFilter);

$rowJordi = null;
foreach ($techBreakdown as $row) {
    if ((int)$row['technician_id'] === (int)$technician->getId()) {
        $rowJordi = $row;
    }
}
$assert("9.1 El desglose por técnico incluye la fila del técnico con reaperturas", $rowJordi !== null);
$assert("9.2 La fila expone warranty_reopens", $rowJordi !== null && array_key_exists('warranty_reopens', $rowJordi));

// Esperado: 2 reaperturas de INC-2026-T2401 + 1 de INC-2026-T2402 si el Caso 8 (HTTP real)
// pudo ejecutarse contra el servidor local.
$expectedReopens = $serverAvailable ? 3 : 2;
$assert("9.3 warranty_reopens = {$expectedReopens} para el técnico resolutor de las reaperturas en garantía", $rowJordi !== null && (int)$rowJordi['warranty_reopens'] === $expectedReopens, "Obtenido: " . var_export($rowJordi['warranty_reopens'] ?? null, true));

$rowOther = null;
foreach ($techBreakdown as $row) {
    if ($rowJordi !== null && (int)$row['technician_id'] !== (int)$technician->getId()) {
        $rowOther = $row;
        break;
    }
}
$assert("9.4 Otros técnicos sin reaperturas reportan warranty_reopens = 0", $rowOther !== null && (int)$rowOther['warranty_reopens'] === 0);

// La atribución se deriva del RESOLVED previo aunque la reapertura venga con user_id NULL
$reopenEvents = $incidentRepo->countReopenEvents((int)$dbInc1->getId());
$assert("9.5 La avería INC-2026-T2401 conserva sus 2 eventos de reapertura en el historial", $reopenEvents === 2);

// Resumen final
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
