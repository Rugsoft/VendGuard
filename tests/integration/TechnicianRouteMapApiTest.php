<?php

declare(strict_types=1);

/**
 * TechnicianRouteMapApiTest (T-MAP-18)
 *
 * Integration HTTP test for the technician route map endpoint against real MariaDB
 * (RF-MAP-03 to RF-MAP-06, RF-MAP-08, RNF-MAP-01, RNF-MAP-03, RNF-MAP-04):
 * 1. RBAC: 401 without token, 403 for non-technician roles.
 * 2. Hybrid deterministic sequencing: IN_PROGRESS stop pinned as #1, critical perishable
 *    stop (SLA < 4h) right after, ordinary stops chained by Nearest Neighbor.
 * 3. Consolidation: several machines of the same site form a single stop with progress.
 * 4. Exclusion: incidents in PENDING_PARTS vanish from route and map until resumed.
 * 5. Origin: device GPS when supplied, transparent Base Central fallback otherwise,
 *    and 422 for malformed origin query values.
 * 6. Universal Google Maps links per stop and full day route with waypoints.
 *
 * Autonomous local suite: real MariaDB, zero outgoing network calls (Art. I, Art. IV).
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
echo " VendGuard: Test de Integración - TechnicianRouteMapApiTest (T-MAP-18)\n";
echo "======================================================================\n\n";

// ─── Bootstrap: MariaDB real y semillas ───────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

// Restos físicos de suites anteriores: los soft-delete dejan filas huérfanas que
// findBySiteCode/findByCode no devuelven, rompiendo la reejecución idempotente.
$pdo->exec("DELETE FROM machines WHERE code LIKE 'MAP18-%'");
$pdo->exec("DELETE FROM locations WHERE site_code LIKE 'SEDE-MAP18-%'");

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
$tech1       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert('0.1 Usuario técnico de prueba encontrado', $tech1 !== null);
$assert('0.2 Usuario coordinador de prueba encontrado', $coordinator !== null);
if ($tech1 === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$tech1Token       = $authService->generateInternalToken($tech1);
$coordinatorToken = $authService->generateInternalToken($coordinator);
$tech1Id          = $tech1->getId();

// Limpieza de tickets abiertos previos del técnico (restos de otras suites de test)
$pdo->prepare("UPDATE incidents SET deleted_at = NOW(), is_active_ticket = 0
               WHERE assigned_technician_id = :tid AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
    ->execute([':tid' => $tech1Id]);

// ─── Escenario geográfico determinista (RF-MAP-05) ───────────────────────────
// Sedes de ruta nuevas alrededor de la Base Central (41.3935, 2.1890):
//   A (41.3980, 2.1800)  B (41.4100, 2.1750)  C (41.4050, 2.1950)  D (41.4010, 2.1620)
// NN esperado tras la crítica B:  D (1.48 km de B) antes que C (1.76 km de B).
$routeSites = [
    'SEDE-MAP18-A' => ['Hospital Mapa A', 'Carrer del Mapa 1, Barcelona', 41.3980, 2.1800],
    'SEDE-MAP18-B' => ['Hospital Mapa B', 'Carrer del Mapa 2, Barcelona', 41.4100, 2.1750],
    'SEDE-MAP18-C' => ['Hospital Mapa C', 'Carrer del Mapa 3, Barcelona', 41.4050, 2.1950],
    'SEDE-MAP18-D' => ['Hospital Mapa D', 'Carrer del Mapa 4, Barcelona', 41.4010, 2.1620],
];

$insertLocation = $pdo->prepare("
    INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`)
    VALUES (:site_code, :name, :address, :lat, :lng, 'Contacto Mapa 18', '672000111', 1)
    ON DUPLICATE KEY UPDATE `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1
");
foreach ($routeSites as $siteCode => [$name, $address, $lat, $lng]) {
    $insertLocation->execute([':site_code' => $siteCode, ':name' => $name, ':address' => $address, ':lat' => $lat, ':lng' => $lng]);
}

$routeMachines = [
    ['MAP18-A1', 'SEDE-MAP18-A', 'PERISHABLE_FOOD'],
    ['MAP18-A2', 'SEDE-MAP18-A', 'SNACKS'],
    ['MAP18-B1', 'SEDE-MAP18-B', 'PERISHABLE_FOOD'],
    ['MAP18-C1', 'SEDE-MAP18-C', 'SNACKS'],
    ['MAP18-D1', 'SEDE-MAP18-D', 'HOT_DRINKS'],
];
$insertMachine = $pdo->prepare("
    INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`, `is_active`, `sanitary_status`, `next_sanitary_inspection_due`)
    VALUES ((SELECT `id` FROM `locations` WHERE `site_code` = :site_code LIMIT 1), :code, 'Modelo Mapa 18', :type, 'Planta 1', NULL, 1, 'CONFORME', DATE_ADD(CURDATE(), INTERVAL 30 DAY))
    ON DUPLICATE KEY UPDATE `machine_type` = VALUES(`machine_type`), `is_active` = 1
");
foreach ($routeMachines as [$code, $siteCode, $type]) {
    $insertMachine->execute([':site_code' => $siteCode, ':code' => $code, ':type' => $type]);
}

$sitesByCode = [];
foreach (array_keys($routeSites) as $siteCode) {
    $sitesByCode[$siteCode] = $locationRepo->findBySiteCode($siteCode);
}
$machinesByCode = [];
foreach ($routeMachines as [$code, ,]) {
    $machinesByCode[$code] = $machineRepo->findByCode($code);
}

$assert('0.3 Sedes y máquinas del escenario preparadas con coordenadas',
    count(array_filter($sitesByCode)) === 4
    && count(array_filter($machinesByCode)) === 5
    && ($sitesByCode['SEDE-MAP18-A']?->getLatitude() === 41.3980));

if (count(array_filter($machinesByCode)) !== 5 || count(array_filter($sitesByCode)) !== 4) {
    echo "ERROR FATAL: el escenario geográfico no se ha preparado correctamente.\n";
    exit(1);
}

// ─── Helper: avería de ruta para el técnico (ticket nuevo, estado y creación controlados) ──
$makeRouteIncident = function (
    string $machineCode,
    int $locationId,
    IncidentStatus $status,
    UrgencyLevel $urgency,
    string $createdMinutesAgo
) use ($incidentRepo, $pdo, $machinesByCode, $tech1Id): Incident {
    $machineId = $machinesByCode[$machineCode]->getId();

    // Liberar ticket previo en la máquina (único ticket activo por máquina)
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW(), is_active_ticket = 0
                   WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
        ->execute([$machineId]);

    $code = 'TMAP18-' . strtoupper(bin2hex(random_bytes(4)));
    $incident = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Avería de escenario para la ruta del técnico T-MAP-18.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($incident);

    $pdo->prepare("UPDATE incidents
                   SET status = :status, assigned_technician_id = :tech_id,
                       created_at = DATE_SUB(NOW(), INTERVAL {$createdMinutesAgo})
                   WHERE id = :id")
        ->execute([
            ':status'  => $status->value,
            ':tech_id' => $tech1Id,
            ':id'      => $created->getId(),
        ]);

    return $incidentRepo->findById($created->getId());
};

$fetchMap = function (?array $query = []) use ($router, $tech1Token): array {
    $request = new Request('GET', '/api/technician/route/map', $query, [], ['Authorization' => "Bearer {$tech1Token}"]);
    $response = $router->dispatch($request);
    return [$response->getStatusCode(), $response->getDecodedBody()];
};

// =========================================================================
// BLOQUE 1: Control de Acceso RBAC (401 / 403)
// =========================================================================
echo "\n--- BLOQUE 1: Control de Acceso RBAC ---\n";

$res = $router->dispatch(new Request('GET', '/api/technician/route/map'));
$assert('1.1 Sin token => 401 Unauthorized', $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$res = $router->dispatch(new Request('GET', '/api/technician/route/map', [], [], ['Authorization' => 'Bearer token_corrupto']));
$assert('1.2 Token corrupto => 401 Unauthorized', $res->getStatusCode() === 401);

$res = $router->dispatch(new Request('GET', '/api/technician/route/map', [], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
$assert('1.3 Coordinador en endpoint de técnico => 403 Forbidden', $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

// =========================================================================
// BLOQUE 2: Secuenciación híbrida determinista (RF-MAP-05) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- BLOQUE 2: Parada #1, crítica por SLA y Nearest Neighbor ---\n";

// Parada en curso (fija el puesto #1) con segunda máquina consolidada en la misma sede
$incInProgress = $makeRouteIncident('MAP18-A1', $sitesByCode['SEDE-MAP18-A']->getId(), IncidentStatus::IN_PROGRESS, UrgencyLevel::HIGH, '90 MINUTE');
$incConsolidated = $makeRouteIncident('MAP18-A2', $sitesByCode['SEDE-MAP18-A']->getId(), IncidentStatus::ASSIGNED, UrgencyLevel::HIGH, '80 MINUTE');

// Crítica perecedera: CRITICAL + perecedera + SLA de 60 min creado hace 30 min (quedan ~30 min < 4h)
$incCritical = $makeRouteIncident('MAP18-B1', $sitesByCode['SEDE-MAP18-B']->getId(), IncidentStatus::ASSIGNED, UrgencyLevel::CRITICAL, '30 MINUTE');

// Ordinarias para el encadenamiento del Vecino Más Cercano
$incOrdinaryC = $makeRouteIncident('MAP18-C1', $sitesByCode['SEDE-MAP18-C']->getId(), IncidentStatus::ASSIGNED, UrgencyLevel::HIGH, '70 MINUTE');
$incOrdinaryD = $makeRouteIncident('MAP18-D1', $sitesByCode['SEDE-MAP18-D']->getId(), IncidentStatus::ASSIGNED, UrgencyLevel::MEDIUM, '70 MINUTE');

[$status, $body] = $fetchMap(['origin_lat' => '41.3935', 'origin_lng' => '2.1890']);
$data = $body['data'] ?? [];
$stops = $data['stops'] ?? [];
$siteCodesOrder = array_map(static fn(array $stop): string => $stop['location']['site_code'], $stops);

$assert('2.1 Endpoint responde HTTP 200 OK', $status === 200 && ($body['success'] ?? false) === true);
$assert('2.2 Cuatro paradas en la ruta (la pausa por repuestos entra después)', count($stops) === 4);
$assert('2.3 La parada IN_PROGRESS es la #1 inamovible',
    ($stops[0]['order'] ?? 0) === 1 && ($stops[0]['status'] ?? '') === 'IN_PROGRESS' && ($siteCodesOrder[0] ?? '') === 'SEDE-MAP18-A');
$assert('2.4 La parada crítica de perecederos es la #2', ($stops[1]['order'] ?? 0) === 2 && ($stops[1]['is_critical'] ?? false) === true && ($siteCodesOrder[1] ?? '') === 'SEDE-MAP18-B');
$assert('2.5 Nearest Neighbor: sede D (1.48 km de B) antes que sede C (1.76 km de B)',
    ($siteCodesOrder[2] ?? '') === 'SEDE-MAP18-D' && ($siteCodesOrder[3] ?? '') === 'SEDE-MAP18-C');
$assert('2.6 Consolidación: la sede A agrupa 2 máquinas en una sola parada', ($stops[0]['total_tasks'] ?? 0) === 2 && ($stops[0]['completed_tasks'] ?? 0) === 0);
$assert('2.7 Las máquinas consolidadas aparecen en el desglose de tareas', str_contains(json_encode($stops[0]['tasks'] ?? []), 'MAP18-A1') && str_contains(json_encode($stops[0]['tasks'] ?? []), 'MAP18-A2'));

$criticalSla = $stops[1]['tasks'][0]['sla_due_at'] ?? null;
$slaTimestamp = $criticalSla !== null ? (new DateTimeImmutable($criticalSla))->getTimestamp() : 0;
$assert('2.8 La crítica se ordena según SLA real derivado de created_at (vence en ~30 min)',
    $slaTimestamp > time() + 15 * 60 && $slaTimestamp < time() + 45 * 60);

$distances = array_column($stops, 'distance_from_previous_km');
$assert('2.9 Distancias Haversine reales entre hitos consecutivos (A->B ~1,4 km)', is_numeric($distances[1]) && $distances[1] > 1.2 && $distances[1] < 1.6);

// =========================================================================
// BLOQUE 3: Exclusión de PENDING_PARTS (RF-MAP-05 Fase 4) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- BLOQUE 3: Exclusión de paradas pendientes de repuestos ---\n";

$pdo->prepare("UPDATE incidents SET status = 'PENDING_PARTS', pending_parts_reason = 'Espiral atascada: ref. ESP-18' WHERE id = :id")
    ->execute([':id' => $incOrdinaryD->getId()]);

[$status, $body] = $fetchMap(['origin_lat' => '41.3935', 'origin_lng' => '2.1890']);
$stops = $body['data']['stops'] ?? [];
$siteCodesOrder = array_map(static fn(array $stop): string => $stop['location']['site_code'], $stops);

$assert('3.1 La sede con avería en PENDING_PARTS queda excluida de la ruta', !in_array('SEDE-MAP18-D', $siteCodesOrder, true) && count($stops) === 3);
$assert('3.2 La exclusión reordena el Vecino Más Cercano (A, B, C)', $siteCodesOrder === ['SEDE-MAP18-A', 'SEDE-MAP18-B', 'SEDE-MAP18-C']);

$dbStatus = $pdo->query("SELECT status FROM incidents WHERE id = {$incOrdinaryD->getId()}")->fetchColumn();
$assert('3.3 El ticket sigue vivo en BD en PENDING_PARTS hasta reanudarse', $dbStatus === 'PENDING_PARTS');

// =========================================================================
// BLOQUE 4: Origen dinámico y fallback a Base Central (RF-MAP-06) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- BLOQUE 4: Origen GPS y fallback a Base Central ---\n";

[$status, $body] = $fetchMap(['origin_lat' => '41.44', 'origin_lng' => '2.20']);
$origin = $body['data']['origin'] ?? [];
$assert('4.1 Con GPS válido el origen es la posición del dispositivo', ($origin['source'] ?? '') === 'GPS' && ($origin['latitude'] ?? 0) === 41.44);

[$status, $body] = $fetchMap();
$origin = $body['data']['origin'] ?? [];
$assert('4.2 Sin parámetros el origen cae a la Base Central sin error', $status === 200 && ($origin['source'] ?? '') === 'BASE_CENTRAL' && ($origin['address'] ?? '') !== '');

[$status, $body] = $fetchMap(['origin_lat' => '0', 'origin_lng' => '0']);
$origin = $body['data']['origin'] ?? [];
$assert('4.3 Coordenadas fuera de área caen a la Base Central', $status === 200 && ($origin['source'] ?? '') === 'BASE_CENTRAL');

[$status, $body] = $fetchMap(['origin_lat' => 'abc', 'origin_lng' => '2.19']);
$assert('4.4 Query de origen malformada => 422 INVALID_ORIGIN_COORDINATES', $status === 422
    && ($body['error']['code'] ?? '') === 'INVALID_ORIGIN_COORDINATES');

// =========================================================================
// BLOQUE 5: URLs universales de Google Maps (RF-MAP-08) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- BLOQUE 5: Navegación universal Google Maps ---\n";

[$status, $body] = $fetchMap(['origin_lat' => '41.3935', 'origin_lng' => '2.1890']);
$stops = $body['data']['stops'] ?? [];

$firstNavigation = $stops[0]['navigation_url'] ?? '';
$assert('5.1 Cada parada lleva su enlace universal de destino exacto',
    str_starts_with($firstNavigation, 'https://www.google.com/maps/dir/?api=1&destination=41.398,2.18')
    && str_ends_with($firstNavigation, 'travelmode=driving'));

$fullUrl = $body['data']['full_route_navigation_url'] ?? '';
$assert('5.2 La ruta completa concatena origen, waypoints y destino en orden',
    str_contains($fullUrl, 'origin=41.3935,2.189')
    && str_contains($fullUrl, 'waypoints=41.398,2.18%7C41.41,2.175')
    && str_contains($fullUrl, 'destination=41.405,2.195')
    && str_ends_with($fullUrl, 'travelmode=driving'));

$allStopUrls = array_column($stops, 'navigation_url');
$assert('5.3 Ningún enlace contiene coordenadas de la parada excluida', !str_contains(json_encode($allStopUrls), '41.401,2.162'));

$assert('5.4 La ruta no está truncada con menos de 9 waypoints', ($body['data']['waypoints_truncated'] ?? true) === false);

$summary = $body['data']['summary'] ?? [];
$assert('5.5 El resumen concentra paradas, tareas y críticas',
    ($summary['total_stops'] ?? 0) === 3 && ($summary['total_tasks'] ?? 0) === 4 && ($summary['total_critical'] ?? 0) === 1);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS DE RUTA DE TÉCNICO PASARON ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-MAP-18 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} aserciones han fallado de {$assertions}.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
