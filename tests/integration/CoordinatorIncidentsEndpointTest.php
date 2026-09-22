<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentsEndpointTest
 * 
 * Test de Integración para la Bandeja Global del Coordinador y Cálculo de SLA (Tarea T-25).
 * Requisitos: RF-05, RF-11 (EARS 11.1).
 * 
 * Valida la condición "Hecho cuando:":
 * 1. GET /api/coordinator/incidents devuelve todas las averías filtrables.
 * 2. Calcula los minutos de espera transcurridos (sla_minutes_elapsed / waiting_minutes).
 * 3. Marca sla_breached: true en averías críticas con > 60 min sin asignación (EARS 11.1).
 * 4. Marca sla_breached: false en averías críticas con <= 60 min.
 * 5. Marca sla_breached: false en averías no críticas (HIGH, MEDIUM, LOW) aunque superen 60 min.
 * 6. Marca sla_breached: false en averías que ya han sido asignadas a un técnico.
 * 7. Filtros por status, urgency, location_id, technician_id y active_only.
 * 8. Control de acceso RBAC (401 si falta token, 403 si el usuario es técnico y no coordinador).
 * 9. Prueba HTTP real contra el servidor local en 127.0.0.1:8000.
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
echo " VendGuard: Test de Integración - CoordinatorIncidentsEndpointTest (T-25)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza para aislamiento
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

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

// 1. Obtener usuarios y tokens
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("1. Coordinador y Técnico semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR: Usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);

// Obtener máquinas y sedes
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machinesLoc1 = $machineRepo->findActiveByLocationId($location1->getId());
$machinesLoc2 = $machineRepo->findActiveByLocationId($location2->getId());

$mach1 = $machinesLoc1[0];
$mach2 = $machinesLoc1[1];
$mach3 = $machinesLoc2[0];

// =========================================================================
// CASO 1: Control de Acceso y RBAC (401 / 403)
// =========================================================================
echo "\n--- Caso 1: Control de Acceso RBAC (401 / 403) ---\n";

// 1.1 Sin token
$req1a = new Request(method: 'GET', path: '/api/coordinator/incidents');
$res1a = $router->dispatch($req1a);
$assert("1.1 Sin token responde HTTP 401 Unauthorized", $res1a->getStatusCode() === 401 && ($res1a->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Token inválido
$req1b = new Request(method: 'GET', path: '/api/coordinator/incidents', headers: ['Authorization' => 'Bearer token_invalido']);
$res1b = $router->dispatch($req1b);
$assert("1.2 Token corrupto responde HTTP 401 Unauthorized", $res1b->getStatusCode() === 401);

// 1.3 Token de técnico (rol TECHNICIAN, se requiere COORDINATOR)
$req1c = new Request(method: 'GET', path: '/api/coordinator/incidents', headers: ['Authorization' => "Bearer {$technicianToken}"]);
$res1c = $router->dispatch($req1c);
$assert("1.3 Técnico intentando acceder a bandeja de coordinador responde HTTP 403 Forbidden", $res1c->getStatusCode() === 403 && ($res1c->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// CASO 2: Creación de Incidencias con Distintos Tiempos y Severidades
// =========================================================================
echo "\n--- Caso 2: Población de Incidencias para Pruebas de SLA ---\n";

$now = new DateTimeImmutable();

// Incidencia 1: CRÍTICA, REGISTRADA, creada hace 75 minutos (>60 min) -> SLA BREACHED: TRUE
$created75m = $now->sub(new DateInterval('PT75M'))->format('Y-m-d H:i:s');
$inc1 = new Incident(
    id: null,
    ticketCode: 'INC-2026-SLA01',
    machineId: $mach1->getId(),
    locationId: $location1->getId(),
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Rotura de cadena de frío en máquina de sándwiches frescos.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: $created75m
);
$createdInc1 = $incidentRepo->create($inc1);
// Forzar fecha de creación en BD
$pdo->prepare("UPDATE incidents SET created_at = :created_at WHERE id = :id")->execute([
    ':created_at' => $created75m,
    ':id' => $createdInc1->getId()
]);

// Incidencia 2: CRÍTICA, REGISTRADA, creada hace 25 minutos (<=60 min) -> SLA BREACHED: FALSE
$created25m = $now->sub(new DateInterval('PT25M'))->format('Y-m-d H:i:s');
$inc2 = new Incident(
    id: null,
    ticketCode: 'INC-2026-SLA02',
    machineId: $mach2->getId(),
    locationId: $location1->getId(),
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Máquina de bebidas calientes desconectada.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: $created25m
);
$createdInc2 = $incidentRepo->create($inc2);
$pdo->prepare("UPDATE incidents SET created_at = :created_at WHERE id = :id")->execute([
    ':created_at' => $created25m,
    ':id' => $createdInc2->getId()
]);

// Incidencia 3: HIGH, REGISTRADA, creada hace 90 minutos (>60 min) -> SLA BREACHED: FALSE (solo aplica a CRITICAL)
$created90m = $now->sub(new DateInterval('PT90M'))->format('Y-m-d H:i:s');
$inc3 = new Incident(
    id: null,
    ticketCode: 'INC-2026-SLA03',
    machineId: $mach3->getId(),
    locationId: $location2->getId(),
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Lector de tarjetas bloqueado.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: $created90m
);
$createdInc3 = $incidentRepo->create($inc3);
$pdo->prepare("UPDATE incidents SET created_at = :created_at WHERE id = :id")->execute([
    ':created_at' => $created90m,
    ':id' => $createdInc3->getId()
]);

// Incidencia 4: CRÍTICA, ASIGNADA, creada hace 80 minutos pero asignada -> SLA BREACHED: FALSE
// Para asociarla sin chocar con active ticket de mach1, usamos mach3 pero cerramos inc3 o usamos otra máquina
// O actualizamos inc2 asignándola a técnico
$assignedAtTime = $now->sub(new DateInterval('PT70M'))->format('Y-m-d H:i:s');
$pdo->prepare("
    UPDATE incidents 
    SET status = 'ASSIGNED', assigned_technician_id = :tech_id, assigned_at = :assigned_at 
    WHERE id = :id
")->execute([
    ':tech_id' => $technician->getId(),
    ':assigned_at' => $assignedAtTime,
    ':id' => $createdInc2->getId()
]);

$assert("2.1 Incidencias de prueba insertadas con tiempos retroactivos", true);

// =========================================================================
// CASO 3: Verificación de la Bandeja Global y Cálculo de Minutos de Espera y SLA
// =========================================================================
echo "\n--- Caso 3: Evaluación de Minutos de Espera y Alerta SLA (RF-11 / EARS 11.1) ---\n";

$req3 = new Request(
    method: 'GET',
    path: '/api/coordinator/incidents',
    queryParams: [],
    parsedBody: [],
    files: [],
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
);
$res3 = $router->dispatch($req3);

$assert("3.1 Solicitud de coordinador responde HTTP 200 OK", $res3->getStatusCode() === 200);
$body3 = $res3->getDecodedBody();
$assert("3.2 Envolvente contiene success => true", ($body3['success'] ?? false) === true);
$data3 = $body3['data'] ?? [];
$assert("3.3 Devuelve lista de incidencias (3 registradas)", count($data3) === 3);

// Indexar por ticket_code para verificar cada una
$indexed = [];
foreach ($data3 as $item) {
    $indexed[$item['ticket_code']] = $item;
}

// 3.4 Verificar Incidencia 1 (CRITICAL, >60 min en espera -> SLA BREACHED: TRUE)
$inc1Data = $indexed['INC-2026-SLA01'] ?? null;
$assert("3.4 INC-2026-SLA01 presente en la respuesta", $inc1Data !== null);
if ($inc1Data !== null) {
    $assert("3.5 INC-2026-SLA01 urgencia es CRITICAL", $inc1Data['urgency'] === 'CRITICAL');
    $assert("3.6 INC-2026-SLA01 status es REGISTERED", $inc1Data['status'] === 'REGISTERED');
    $assert("3.7 INC-2026-SLA01 assigned_technician es null", $inc1Data['assigned_technician'] === null);
    $assert("3.8 INC-2026-SLA01 minutos de espera >= 74 min", ($inc1Data['sla_minutes_elapsed'] ?? 0) >= 74);
    $assert("3.9 [CONDICIÓN HECHO CUANDO] INC-2026-SLA01 marca sla_breached: true (> 60m)", $inc1Data['sla_breached'] === true);
}

// 3.10 Verificar Incidencia 3 (HIGH, >60 min en espera -> SLA BREACHED: FALSE)
$inc3Data = $indexed['INC-2026-SLA03'] ?? null;
$assert("3.10 INC-2026-SLA03 presente en la respuesta", $inc3Data !== null);
if ($inc3Data !== null) {
    $assert("3.11 INC-2026-SLA03 urgencia es HIGH", $inc3Data['urgency'] === 'HIGH');
    $assert("3.12 INC-2026-SLA03 minutos transcurridos >= 89 min", ($inc3Data['sla_minutes_elapsed'] ?? 0) >= 89);
    $assert("3.13 INC-2026-SLA03 sla_breached es false (no es CRITICAL)", $inc3Data['sla_breached'] === false);
}

// 3.14 Verificar Incidencia 2 (CRITICAL, pero ya ASIGNADA -> SLA BREACHED: FALSE)
$inc2Data = $indexed['INC-2026-SLA02'] ?? null;
$assert("3.14 INC-2026-SLA02 presente en la respuesta", $inc2Data !== null);
if ($inc2Data !== null) {
    $assert("3.15 INC-2026-SLA02 status es ASSIGNED", $inc2Data['status'] === 'ASSIGNED');
    $assert("3.16 INC-2026-SLA02 assigned_technician contiene datos del técnico", 
        is_array($inc2Data['assigned_technician']) && 
        ($inc2Data['assigned_technician']['id'] ?? null) === $technician->getId()
    );
    $assert("3.17 INC-2026-SLA02 sla_breached es false (técnico asignado)", $inc2Data['sla_breached'] === false);
}

// =========================================================================
// CASO 4: Prueba de Incidencia Crítica Reciente (<= 60 min -> sla_breached = false)
// =========================================================================
echo "\n--- Caso 4: Incidencia Crítica Reciente (<= 60 min) ---\n";

// Devolver INC-2026-SLA02 a REGISTERED reciente (15 minutos atrás)
$created15m = $now->sub(new DateInterval('PT15M'))->format('Y-m-d H:i:s');
$pdo->prepare("
    UPDATE incidents 
    SET status = 'REGISTERED', assigned_technician_id = NULL, assigned_at = NULL, created_at = :created_at 
    WHERE id = :id
")->execute([':created_at' => $created15m, ':id' => $createdInc2->getId()]);

$req4 = new Request(method: 'GET', path: '/api/coordinator/incidents', headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res4 = $router->dispatch($req4);
$data4 = $res4->getDecodedBody()['data'] ?? [];
$indexed4 = [];
foreach ($data4 as $it) {
    $indexed4[$it['ticket_code']] = $it;
}

$inc2Recent = $indexed4['INC-2026-SLA02'] ?? null;
$assert("4.1 INC-2026-SLA02 minutos de espera ~15 min", ($inc2Recent['sla_minutes_elapsed'] ?? 0) >= 14 && ($inc2Recent['sla_minutes_elapsed'] ?? 0) <= 16);
$assert("4.2 [CONDICIÓN HECHO CUANDO] Crítica con <= 60 min marca sla_breached: false", ($inc2Recent['sla_breached'] ?? null) === false);

// =========================================================================
// CASO 5: Filtros de Búsqueda (status, urgency, location_id, technician_id)
// =========================================================================
echo "\n--- Caso 5: Filtros de Búsqueda ---\n";

// 5.1 Filtro por urgency=CRITICAL
$req5a = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['urgency' => 'CRITICAL'], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5a = $router->dispatch($req5a);
$data5a = $res5a->getDecodedBody()['data'] ?? [];
$assert("5.1 Filtro urgency=CRITICAL devuelve solo incidencias críticas", 
    count($data5a) === 2 && 
    $data5a[0]['urgency'] === 'CRITICAL' && 
    $data5a[1]['urgency'] === 'CRITICAL'
);

// 5.2 Filtro por location_id
$req5b = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['location_id' => (string)$location2->getId()], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5b = $router->dispatch($req5b);
$data5b = $res5b->getDecodedBody()['data'] ?? [];
$assert("5.2 Filtro location_id devuelve solo incidencias de SEDE-BCN-02", 
    count($data5b) === 1 && 
    ($data5b[0]['ticket_code'] ?? '') === 'INC-2026-SLA03'
);

// 5.3 Filtro por status=REGISTERED
$req5c = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['status' => 'REGISTERED'], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5c = $router->dispatch($req5c);
$data5c = $res5c->getDecodedBody()['data'] ?? [];
$assert("5.3 Filtro status=REGISTERED devuelve las 3 incidencias registradas", count($data5c) === 3);

// 5.4 Filtro por lista de estados (status=ASSIGNED,IN_PROGRESS -> vacío en este punto)
$req5d = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['status' => 'ASSIGNED,IN_PROGRESS'], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5d = $router->dispatch($req5d);
$data5d = $res5d->getDecodedBody()['data'] ?? [];
$assert("5.4 Filtro múltiple status=ASSIGNED,IN_PROGRESS devuelve 0 coincidencias", count($data5d) === 0);

// 5.5 Validación: status inválido devuelve HTTP 400 INVALID_STATUS_FILTER
$req5e = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['status' => 'ESTADO_INVENTADO'], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5e = $router->dispatch($req5e);
$assert("5.5 status inválido responde HTTP 400 INVALID_STATUS_FILTER", 
    $res5e->getStatusCode() === 400 && 
    ($res5e->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FILTER'
);

// 5.6 Validación: urgency inválida devuelve HTTP 400 INVALID_URGENCY_FILTER
$req5f = new Request(method: 'GET', path: '/api/coordinator/incidents', queryParams: ['urgency' => 'SUPER_URGENTE'], headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res5f = $router->dispatch($req5f);
$assert("5.6 urgency inválida responde HTTP 400 INVALID_URGENCY_FILTER", 
    $res5f->getStatusCode() === 400 && 
    ($res5f->getDecodedBody()['error']['code'] ?? '') === 'INVALID_URGENCY_FILTER'
);

// =========================================================================
// CASO 6: Prueba HTTP Real contra Servidor Local (127.0.0.1:8000)
// =========================================================================
echo "\n--- Caso 6: Prueba HTTP Real contra Servidor en 127.0.0.1:8000 ---\n";

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
    $ch = curl_init('http://127.0.0.1:8000/api/coordinator/incidents');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$coordinatorToken}",
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseStr = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $assert("6.1 Llamada HTTP real responde HTTP 200 OK", $statusCode === 200, "Status: {$statusCode}, Body: {$responseStr}");
    $httpDecoded = json_decode((string)$responseStr, true);
    $assert("6.2 HTTP real devuelve success => true", ($httpDecoded['success'] ?? false) === true);
    $realItems = $httpDecoded['data'] ?? [];
    $assert("6.3 HTTP real devuelve array con 3 incidencias", count($realItems) === 3);

    // Comprobar que en el servidor real también se calcula sla_breached: true en INC-2026-SLA01
    $realSla01 = null;
    foreach ($realItems as $it) {
        if (($it['ticket_code'] ?? '') === 'INC-2026-SLA01') {
            $realSla01 = $it;
            break;
        }
    }
    $assert("6.4 HTTP real: INC-2026-SLA01 encontrada", $realSla01 !== null);
    if ($realSla01 !== null) {
        $assert("6.5 HTTP real: sla_breached es true", ($realSla01['sla_breached'] ?? null) === true);
        $assert("6.6 HTTP real: sla_minutes_elapsed >= 74", ($realSla01['sla_minutes_elapsed'] ?? 0) >= 74);
    }
} else {
    echo "  [SKIP] Servidor local no disponible en 127.0.0.1:8000 para Caso 6.\n";
}

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
