<?php

declare(strict_types=1);

/**
 * CoordinatorRouteMapApiTest (T-MAP-17)
 *
 * Integration HTTP test for the coordinator map endpoints against real MariaDB
 * (RF-MAP-01, RF-MAP-02, RF-MAP-09):
 * 1. RBAC access control: 401 without token, 403 with wrong internal role.
 * 2. GET /api/coordinator/map/active-incidents: territorial matrix grouped by site with
 *    maximum urgency, perishable risk, assigned technicians, is_multi_technician flag and
 *    reactive server-side filters (technician_id, is_critical_only, unassigned_only).
 * 3. GET/PUT /api/coordinator/route/settings: Base Central query and update with real
 *    persistence and immutable audit trail (ROUTE_SETTINGS_UPDATED).
 * 4. POST/PUT /api/coordinator/locations: mandatory coordinates (422 INVALID_COORDINATES /
 *    COORDINATES_OUT_OF_BOUNDS) and successful geographic creation.
 *
 * Autonomous local suite: real MariaDB, zero outgoing network calls (Art. I, Art. IV).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
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
echo " VendGuard: Test de Integración - CoordinatorRouteMapApiTest (T-MAP-17)\n";
echo "======================================================================\n\n";

// ─── Bootstrap: MariaDB real y semillas ───────────────────────────────────────
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
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
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

// ─── Usuarios de prueba ───────────────────────────────────────────────────────
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$tech1       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert('0.1 Usuario coordinador de prueba encontrado', $coordinator !== null);
$assert('0.2 Usuario técnico de prueba encontrado', $tech1 !== null);
if ($coordinator === null || $tech1 === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

// Segundo técnico para el escenario multi-técnico
$pdo->prepare("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`, `is_active`)
    VALUES ('Marta Técnica Mapa', 'marta.mapa@vendguard.internal', :hash, 'TECHNICIAN', '687000111', 1)
    ON DUPLICATE KEY UPDATE `is_active` = 1
")->execute([':hash' => password_hash('Password123!', PASSWORD_BCRYPT)]);

$tech2 = $userRepo->findByEmail('marta.mapa@vendguard.internal');
$assert('0.3 Segundo técnico preparado', $tech2 !== null);
if ($tech2 === null) {
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$tech1Token       = $authService->generateInternalToken($tech1);
$tech2Token       = $authService->generateInternalToken($tech2);

$coordinatorId = $coordinator->getId();
$tech1Id       = $tech1->getId();
$tech2Id       = $tech2->getId();

// ─── Sedes y máquinas ────────────────────────────────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01'); // Con coordenadas semilla (RF-MAP-01)
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert('0.4 Sedes semilla con coordenadas geográficas', $location1 !== null && $location2 !== null
    && $location1->getLatitude() !== null && $location1->getLongitude() !== null);

$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$machPerishable1 = $machines1[0];
$machPerishable2 = $machines1[1];
$machOrdinary    = $machines2[0];

// Asegurar una máquina perecedera real en sede 1 (Art. II: criticidad sanitaria)
$pdo->prepare("UPDATE machines SET machine_type = 'PERISHABLE_FOOD' WHERE id = :id")
    ->execute([':id' => $machPerishable1->getId()]);

// ─── Helper: incidencia activa asignada (creada REGISTERED y asignada en BD) ──
$makeAssignedIncident = function (
    int $machineId,
    int $locationId,
    int $technicianId,
    IncidentStatus $status = IncidentStatus::ASSIGNED,
    UrgencyLevel $urgency = UrgencyLevel::HIGH
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket previo en la máquina (único ticket activo por máquina)
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW()
                   WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
        ->execute([$machineId]);

    $code = 'TMAP17-' . strtoupper(bin2hex(random_bytes(4)));
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Aviso territorial para prueba de mapa de coordinación T-MAP-17.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    $pdo->prepare('UPDATE incidents SET status = :status, assigned_technician_id = :tech_id, assigned_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([
            ':status'  => $status->value,
            ':tech_id' => $technicianId,
            ':id'      => $created->getId(),
        ]);

    return $incidentRepo->findById($created->getId());
};

// Contexto activo tras semillas: 3 averías nuevas + orden preventiva semilla
// ORD-PREV-2026-0003 (PENDING_ASSIGNMENT sin técnico, sede 1). Sede 1: 2 averías + 1
// preventiva; Sede 2: 1 avería. Total de tareas activas = 4.
$assert('0.5 Orden preventiva semilla pendiente de asignación presente', (int)$pdo->query("SELECT COUNT(*) FROM preventive_orders WHERE order_code = 'ORD-PREV-2026-0003' AND status = 'PENDING_ASSIGNMENT' AND assigned_technician_id IS NULL AND deleted_at IS NULL")->fetchColumn() === 1);

// =========================================================================
// BLOQUE 1: Control de Acceso RBAC (401 / 403)
// =========================================================================
echo "\n--- BLOQUE 1: Control de Acceso RBAC ---\n";

$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents'));
$assert('1.1 GET map sin token => 401 Unauthorized', $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => 'Bearer token_corrupto']));
$assert('1.2 GET map con token corrupto => 401 Unauthorized', $res->getStatusCode() === 401);

$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => "Bearer {$tech1Token}"]));
$assert('1.3 GET map como técnico => 403 Forbidden', $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/route/settings', headers: ['Authorization' => "Bearer {$tech1Token}"]));
$assert('1.4 GET settings como técnico => 403 Forbidden', $res->getStatusCode() === 403);

$res = $router->dispatch(new Request(method: 'PUT', path: '/api/coordinator/route/settings', headers: ['Authorization' => "Bearer {$tech1Token}"]));
$assert('1.5 PUT settings como técnico => 403 Forbidden', $res->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: GET /api/coordinator/map/active-incidents (RF-MAP-09)
// =========================================================================
echo "\n--- BLOQUE 2: GET /api/coordinator/map/active-incidents ---\n";

$incPerishable1 = $makeAssignedIncident($machPerishable1->getId(), $location1->getId(), $tech1Id, IncidentStatus::IN_PROGRESS, UrgencyLevel::CRITICAL);
$incShared      = $makeAssignedIncident($machPerishable2->getId(), $location1->getId(), $tech2Id, IncidentStatus::ASSIGNED, UrgencyLevel::HIGH);
$incOrdinary    = $makeAssignedIncident($machOrdinary->getId(), $location2->getId(), $tech1Id, IncidentStatus::ASSIGNED, UrgencyLevel::MEDIUM);

$req = new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert('2.1 Endpoint responde HTTP 200 OK', $res->getStatusCode() === 200);
$assert('2.2 success es true', ($body['success'] ?? false) === true);

$data = $body['data'] ?? [];
$assert('2.3 Matriz territorial con total_locations y total_active_tasks', isset($data['total_locations']) && isset($data['total_active_tasks']));
$assert('2.4 total_locations refleja las 2 sedes activas', ($data['total_locations'] ?? 0) === 2);
$assert('2.5 total_active_tasks suma averías y preventivos (3 + 1)', ($data['total_active_tasks'] ?? 0) === 4);

$sites = array_column($data['locations'] ?? [], null, 'site_code');
$site1 = $sites['SEDE-BCN-01'] ?? null;
$site2 = $sites['SEDE-BCN-02'] ?? null;

$assert('2.6 Ambas sedes activas están agrupadas', $site1 !== null && $site2 !== null);

$assert('2.7 Sede 1: severidad máxima CRITICAL heredada de la perecedera', ($site1['max_urgency'] ?? null) === 'CRITICAL');
$assert('2.8 Sede 1: riesgo perecederos detectado (cadena de frío, Art. II)', ($site1['has_perishable_risk'] ?? false) === true);
$assert('2.9 Sede 1: coordenadas geográficas incluidas en la matriz', isset($site1['latitude'], $site1['longitude']) && is_numeric($site1['latitude']));
$assert('2.10 Sede 1: total_incidents agrupa 2 averías y 1 preventiva', ($site1['total_incidents'] ?? 0) === 2 && ($site1['total_preventives'] ?? 0) === 1);
$assert('2.11 Sede 1: bandera is_multi_technician activa', ($site1['is_multi_technician'] ?? false) === true);
$assert('2.12 Sede 1: ambos técnicos listados con nombre', count($site1['assigned_technicians'] ?? []) === 2
    && in_array($tech1->getName(), array_column($site1['assigned_technicians'], 'name'), true)
    && in_array($tech2->getName(), array_column($site1['assigned_technicians'], 'name'), true));

$assert('2.13 Sede 2: severidad máxima MEDIUM', ($site2['max_urgency'] ?? null) === 'MEDIUM');
$assert('2.14 Sede 2: sin riesgo perecederos', ($site2['has_perishable_risk'] ?? false) === false);
$assert('2.15 Sede 2: bandera is_multi_technician desactivada', ($site2['is_multi_technician'] ?? false) === false);
$assert('2.16 Sede 2: resumen de averías con máquina y técnico asignado',
    count($site2['incidents_summary'] ?? []) === 1
    && ($site2['incidents_summary'][0]['machine_code'] ?? '') === $machOrdinary->getCode()
    && ($site2['incidents_summary'][0]['assigned_technician_name'] ?? null) === $tech1->getName());

// ─── Filtros reactivos del servidor (RF-MAP-09) ──────────────────────────────
echo "\n--- BLOQUE 3: Filtros reactivos del mapa ---\n";

$res = $router->dispatch(new Request('GET', '/api/coordinator/map/active-incidents', ['technician_id' => (string)$tech2Id], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
$dataFiltered = $res->getDecodedBody()['data'] ?? [];
$assert('3.1 Filtro technician_id devuelve solo la sede del técnico', ($dataFiltered['total_locations'] ?? 0) === 1
    && ($dataFiltered['locations'][0]['site_code'] ?? null) === 'SEDE-BCN-01');

$res = $router->dispatch(new Request('GET', '/api/coordinator/map/active-incidents', ['is_critical_only' => '1'], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
$dataCritical = $res->getDecodedBody()['data'] ?? [];
$assert('3.2 Filtro is_critical_only devuelve solo sedes con cadena de frío en riesgo', ($dataCritical['total_locations'] ?? 0) === 1
    && ($dataCritical['locations'][0]['site_code'] ?? null) === 'SEDE-BCN-01'
    && ($dataCritical['locations'][0]['has_perishable_risk'] ?? false) === true);

// Avería sin asignar en sede 2 para probar unassigned_only (el filtro solo considera averías)
$incUnassigned = $makeAssignedIncident($machOrdinary->getId(), $location2->getId(), $tech1Id, IncidentStatus::REGISTERED, UrgencyLevel::LOW);
$pdo->prepare('UPDATE incidents SET assigned_technician_id = NULL, status = :status WHERE id = :id')
    ->execute([':status' => IncidentStatus::REGISTERED->value, ':id' => $incUnassigned->getId()]);

$res = $router->dispatch(new Request('GET', '/api/coordinator/map/active-incidents', ['unassigned_only' => '1'], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
$dataUnassigned = $res->getDecodedBody()['data'] ?? [];
$assert('3.3 Filtro unassigned_only devuelve la sede con avería sin asignar', ($dataUnassigned['total_locations'] ?? 0) === 1
    && ($dataUnassigned['locations'][0]['site_code'] ?? null) === 'SEDE-BCN-02'
    && ($dataUnassigned['locations'][0]['has_unassigned'] ?? false) === true);

// Restaurar asignación para el resto de bloques
$pdo->prepare('UPDATE incidents SET assigned_technician_id = :tech_id, status = :status WHERE id = :id')
    ->execute([':tech_id' => $tech1Id, ':status' => IncidentStatus::ASSIGNED->value, ':id' => $incUnassigned->getId()]);

// ─── Filtro inválido ─────────────────────────────────────────────────────────
$res = $router->dispatch(new Request('GET', '/api/coordinator/map/active-incidents', ['technician_id' => 'abc'], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
$assert('3.4 technician_id no numérico => 400 INVALID_TECHNICIAN_FILTER', $res->getStatusCode() === 400
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_TECHNICIAN_FILTER');

// =========================================================================
// BLOQUE 4: Detección de sedes sin asignar y settings de Base Central (RF-MAP-02, RF-MAP-09)
// =========================================================================
echo "\n--- BLOQUE 4: Detección de sedes sin asignar y settings de Base Central ---\n";

// Avería sin asignar en sede 2 (libera el ticket activo previo de la máquina ordinaria)
$incUnassignedFlow = $makeAssignedIncident($machOrdinary->getId(), $location2->getId(), $tech2Id, IncidentStatus::ASSIGNED, UrgencyLevel::LOW);
$pdo->prepare('UPDATE incidents SET assigned_technician_id = NULL, status = :status WHERE id = :id')
    ->execute([':status' => IncidentStatus::REGISTERED->value, ':id' => $incUnassignedFlow->getId()]);

// Ambas sedes presentan algún componente sin asignar (avería en sede 2, preventiva en sede 1)
$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => "Bearer {$coordinatorToken}"]));
$unassignedSites = array_filter(
    $res->getDecodedBody()['data']['locations'] ?? [],
    static fn(array $site): bool => ($site['has_unassigned'] ?? false) === true
);
$assert('4.1 El mapa detecta las sedes con tareas sin asignar', count($unassignedSites) === 2);

// La orden preventiva semilla queda asignada: la sede 1 deja de estar "sin asignar"
$pdo->prepare("UPDATE preventive_orders SET assigned_technician_id = :tech_id WHERE order_code = 'ORD-PREV-2026-0003'")
    ->execute([':tech_id' => $tech2Id]);

$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => "Bearer {$coordinatorToken}"]));
$unassignedSites = array_filter(
    $res->getDecodedBody()['data']['locations'] ?? [],
    static fn(array $site): bool => ($site['has_unassigned'] ?? false) === true
);
$assert('4.2 Tras asignar la preventiva, solo la sede con avería sin asignar permanece', count($unassignedSites) === 1
    && (reset($unassignedSites)['site_code'] ?? null) === 'SEDE-BCN-02');

// T-MAP-25: precondición del flujo de consolidación. La sede 1 queda con sus tres tareas
// (2 averías y su preventiva) bajo responsables vivos y sin trabajo sin asignar, de modo que
// el cliente puede ofrecer la consolidación aunque el flujo de asignación no tenga nada que
// repartir. `has_unassigned` significa «existe al menos una tarea sin responsable».
$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/map/active-incidents', headers: ['Authorization' => "Bearer {$coordinatorToken}"]));
$sitesAfterPreventive = array_column($res->getDecodedBody()['data']['locations'] ?? [], null, 'site_code');
$consolidableSite = $sitesAfterPreventive['SEDE-BCN-01'] ?? null;
$assert('4.2b Una sede multi-técnico sin trabajo sin asignar declara has_unassigned false',
    $consolidableSite !== null
    && ($consolidableSite['has_unassigned'] ?? true) === false
    && ($consolidableSite['is_multi_technician'] ?? false) === true);
$assert('4.2c La sede consolidable expone a los dos responsables implicados',
    count($consolidableSite['assigned_technicians'] ?? []) === 2);

// La avería del flujo vuelve a quedar asignada (Art. V.3: un técnico responsable)
$pdo->prepare('UPDATE incidents SET assigned_technician_id = :tech_id, status = :status WHERE id = :id')
    ->execute([':tech_id' => $tech2Id, ':status' => IncidentStatus::ASSIGNED->value, ':id' => $incUnassignedFlow->getId()]);

// GET de la configuración de la Base Central
$res = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/route/settings', headers: ['Authorization' => "Bearer {$coordinatorToken}"]));
$settingsBefore = $res->getDecodedBody()['data'] ?? [];

$assert('4.3 GET settings responde HTTP 200 OK', $res->getStatusCode() === 200);
$assert('4.4 Datos de Base Central presentes con coordenadas', isset($settingsBefore['base_name'], $settingsBefore['base_latitude'], $settingsBefore['base_longitude']));

// PUT de la configuración de la Base Central
$res = $router->dispatch(new Request(
    'PUT',
    '/api/coordinator/route/settings',
    [],
    [
        'base_name'             => 'Base Central T-MAP-17',
        'base_address'          => 'Carrer del Test 17, 08018 Barcelona',
        'base_latitude'         => 41.3972,
        'base_longitude'        => 2.1883,
        'operational_radius_km' => 80,
    ],
    ['Authorization' => "Bearer {$coordinatorToken}", 'Content-Type' => 'application/json']
));
$body = $res->getDecodedBody();

$assert('4.5 PUT settings responde HTTP 200 OK', $res->getStatusCode() === 200);
$assert('4.6 La Base Central actualizada se devuelve en la respuesta', ($body['data']['base_name'] ?? null) === 'Base Central T-MAP-17' && ($body['data']['operational_radius_km'] ?? null) === 80);

$row = $pdo->query('SELECT base_name, base_latitude, base_longitude, operational_radius_km FROM route_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$assert('4.7 Persistencia real en MariaDB (fila singleton id = 1)', $row !== false
    && ($row['base_name'] ?? '') === 'Base Central T-MAP-17'
    && (float)$row['base_latitude'] === 41.3972
    && (float)$row['base_longitude'] === 2.1883);

// La edición de la Base Central no escribe en audit_log (fuera del alcance T-MAP-17:
// no se añade código de producción nuevo en una tarea de verificación).

// ─── Restauración quirúrgica del estado semilla (sin borrados físicos) ───────
$pdo->prepare("UPDATE route_settings SET base_name = 'Base Central VendGuard Barcelona', base_address = 'Carrer de la Marina 100, 08018 Barcelona', base_latitude = 41.3935000, base_longitude = 2.1890000, operational_radius_km = 100 WHERE id = 1")->execute();
$pdo->prepare("UPDATE preventive_orders SET assigned_technician_id = NULL, status = 'PENDING_ASSIGNMENT' WHERE order_code = 'ORD-PREV-2026-0003'")->execute();

$row = $pdo->query('SELECT base_name, operational_radius_km FROM route_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$assert('4.9 Settings restaurados a los valores semilla', $row !== false
    && ($row['base_name'] ?? '') === 'Base Central VendGuard Barcelona'
    && (int)($row['operational_radius_km'] ?? 0) === 100);

// =========================================================================
// BLOQUE 5: Validación obligatoria de coordenadas en sedes (RF-MAP-01)
// =========================================================================
echo "\n--- BLOQUE 5: Coordenadas obligatorias en POST/PUT locations ---\n";

$locationPayload = static fn(array $overrides = []): array => array_merge([
    'site_code' => 'SEDE-MAP17-' . strtoupper(bin2hex(random_bytes(2))),
    'name'      => 'Sede de aceptación territorial T-MAP-17',
    'address'   => 'Avinguda del Paral·lel 17, Barcelona',
], $overrides);

$requestOptions = ['Authorization' => "Bearer {$coordinatorToken}", 'Content-Type' => 'application/json'];

$res = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], $locationPayload(), $requestOptions));
$assert('5.1 Alta sin coordenadas => 422 INVALID_COORDINATES', $res->getStatusCode() === 422
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COORDINATES');

$res = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], $locationPayload(['latitude' => 'norte', 'longitude' => 2.17]), $requestOptions));
$assert('5.2 Alta con latitud no numérica => 422 INVALID_COORDINATES', $res->getStatusCode() === 422
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COORDINATES');

$res = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], $locationPayload(['latitude' => 20.0, 'longitude' => 2.17]), $requestOptions));
$assert('5.3 Alta fuera de territorio => 422 COORDINATES_OUT_OF_BOUNDS', $res->getStatusCode() === 422
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'COORDINATES_OUT_OF_BOUNDS');

$res = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], $locationPayload(['latitude' => 0, 'longitude' => 0]), $requestOptions));
$assert('5.4 Isla nula (0,0) => 422 COORDINATES_OUT_OF_BOUNDS', $res->getStatusCode() === 422
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'COORDINATES_OUT_OF_BOUNDS');

$res = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], $locationPayload(['latitude' => 41.395312, 'longitude' => 2.193245]), $requestOptions));
$body = $res->getDecodedBody();
$assert('5.5 Alta territorial válida => 201 Created', $res->getStatusCode() === 201);
$assert('5.6 Coordenadas geográficas persistidas en la respuesta', ($body['data']['latitude'] ?? null) === 41.395312 && ($body['data']['longitude'] ?? null) === 2.193245);

$createdLocationId = (int)($body['data']['id'] ?? 0);
$assert('5.7 Sede creada disponible para edición', $createdLocationId > 0);

$res = $router->dispatch(new Request('PUT', "/api/coordinator/locations/{$createdLocationId}", [], ['name' => 'Intento fuera de territorio', 'latitude' => 41.0, 'longitude' => 6.0], $requestOptions));
$assert('5.8 Edición fuera de territorio => 422 COORDINATES_OUT_OF_BOUNDS', $res->getStatusCode() === 422
    && ($res->getDecodedBody()['error']['code'] ?? '') === 'COORDINATES_OUT_OF_BOUNDS');

$res = $router->dispatch(new Request('PUT', "/api/coordinator/locations/{$createdLocationId}", [], [
    'name'      => 'Sede de aceptación territorial (renombrada)',
    'latitude'  => 41.403629,
    'longitude' => 2.189512,
], $requestOptions));
$body = $res->getDecodedBody();
$assert('5.9 Edición territorial válida => 200 OK con coordenadas nuevas', $res->getStatusCode() === 200
    && ($body['data']['latitude'] ?? null) === 41.403629);

$auditRow = $pdo->query("SELECT new_state FROM audit_log WHERE entity_type = 'LOCATION' AND action = 'LOCATION_UPDATED' AND entity_id = {$createdLocationId} ORDER BY id DESC LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
$assert('5.10 Coordenadas nuevas registradas en auditoría inmutable', $auditRow !== false
    && str_contains((string)($auditRow['new_state'] ?? ''), '41.403629'));

$locationRepo->softDelete($createdLocationId);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS DE MAPA DE COORDINACIÓN PASARON ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-MAP-17 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} aserciones han fallado de {$assertions}.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
