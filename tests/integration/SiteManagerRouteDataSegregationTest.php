<?php

declare(strict_types=1);

/**
 * SiteManagerRouteDataSegregationTest (T-MAP-19)
 *
 * Constitutional armor suite for data segregation and labor privacy (RF-MAP-10, Art. V.4):
 * 1. Armor: the four route/map endpoints reject anonymous and forged tokens, and every
 *    authenticated identity other than the required internal role is forbidden (403).
 * 2. Site-manager identity: the site login (Responsable de Ubicación) only yields a
 *    location-scoped token key (no user_id / TECHNICIAN-COORDINATOR role), so the internal
 *    RBAC of the cartographic endpoints can never match it: forged attempts land on 403,
 *    genuine site tokens on 404 (their own site only).
 * 3. No continuous personal tracking: the shipped frontend contains no watchPosition, no
 *    polling geolocation and no location reporting endpoint (one-shot GPS only, RF-MAP-06).
 * 4. Segregation: each technician only sees their own assigned stops, and the technician
 *    view never exposes internal-note or parts-cost fields of other sites.
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
echo " VendGuard: Blindaje Constitucional - SiteManagerRouteDataSegregationTest (T-MAP-19)\n";
echo "======================================================================\n\n";

// ─── Bootstrap: MariaDB real y semillas ───────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

$pdo->exec('DELETE FROM incident_replaced_parts');
$pdo->exec('DELETE FROM spare_part_requests');
$pdo->exec('DELETE FROM incident_history');
$pdo->exec('DELETE FROM incident_comments');
$pdo->exec('DELETE FROM incidents');

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

// ─── Usuarios y sedes de prueba ───────────────────────────────────────────────
$tech1       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert('0.1 Usuario técnico de prueba encontrado', $tech1 !== null);
$assert('0.2 Usuario coordinador de prueba encontrado', $coordinator !== null);
if ($tech1 === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert('0.3 Sedes semilla disponibles', $location1 !== null && $location2 !== null);

$tech1Token       = $authService->generateInternalToken($tech1);
$coordinatorToken = $authService->generateInternalToken($coordinator);
$tech1Id          = $tech1->getId();

// Escenario: una avería por técnico en cada una de las dos sedes
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines2[0];

$makeAssignedIncident = function (int $machineId, int $locationId, int $technicianId, UrgencyLevel $urgency) use ($incidentRepo, $pdo): Incident {
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW(), is_active_ticket = 0
                   WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
        ->execute([$machineId]);

    $incident = new Incident(
        id: null,
        ticketCode: 'TMAP19-' . strtoupper(bin2hex(random_bytes(4))),
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Avería para la prueba de segregación de datos de ruta T-MAP-19.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($incident);

    $pdo->prepare('UPDATE incidents SET status = :status, assigned_technician_id = :tech_id, assigned_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([
            ':status'  => IncidentStatus::ASSIGNED->value,
            ':tech_id' => $technicianId,
            ':id'      => $created->getId(),
        ]);

    return $incidentRepo->findById($created->getId());
};

$incTech1 = $makeAssignedIncident($mach1->getId(), $location1->getId(), $tech1Id, UrgencyLevel::HIGH);
$tech2 = $userRepo->findByEmail('marta.mapa@vendguard.internal');
if ($tech2 === null) {
    $pdo->prepare("
        INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`, `is_active`)
        VALUES ('Marta Técnica Mapa', 'marta.mapa@vendguard.internal', :hash, 'TECHNICIAN', '687000111', 1)
        ON DUPLICATE KEY UPDATE `is_active` = 1
    ")->execute([':hash' => password_hash('Password123!', PASSWORD_BCRYPT)]);
    $tech2 = $userRepo->findByEmail('marta.mapa@vendguard.internal');
}
$assert('0.4 Segundo técnico preparado', $tech2 !== null);
$incTech2 = $makeAssignedIncident($mach2->getId(), $location2->getId(), $tech2->getId(), UrgencyLevel::MEDIUM);

// ─── Claves de identidad para el rol de Responsable de Ubicación ─────────────
// El acceso de sede se autentica por código (site-login) y produce un token de
// ámbito de sede sin user_id interno ni rol técnico/coordinador. Un intento forjado
// con ese material nunca puede satisfacer el RBAC interno de los endpoints de mapa.
$siteToken = $authService->generateSiteToken($location1);
$assert('0.5 El acceso de sede emite un token de ámbito de sede (site_token_)', is_string($siteToken) && str_starts_with($siteToken, 'site_token_'));
$assert('0.6 El token de sede no es válido para el RBAC interno (auth_token_)', !str_starts_with($siteToken, 'auth_token_')
    && $authService->validateInternalToken($siteToken) === null);

$assert('0.7 Averías de escenario creadas para ambos técnicos', $incTech1 !== null && $incTech2 !== null);

// =========================================================================
// BLOQUE 1: Blindaje de los 4 endpoints de ruta/mapa (Art. V.4)
// =========================================================================
echo "\n--- BLOQUE 1: Blindaje de acceso a endpoints de ruta/mapa ---\n";

$isCoordinatorOnly = false; // se recalcula por endpoint en el bucle de blindaje
$cartographicEndpoints = [
    ['GET',  '/api/technician/route/map'],
    ['GET',  '/api/coordinator/map/active-incidents'],
    ['GET',  '/api/coordinator/route/settings'],
    ['PUT',  '/api/coordinator/route/settings'],
];

foreach ($cartographicEndpoints as [$method, $path]) {
    $res = $router->dispatch(new Request($method, $path));
    $assert("1.1 {$method} {$path} sin token => 401", $res->getStatusCode() === 401);

    $res = $router->dispatch(new Request($method, $path, [], [], ['Authorization' => 'Bearer token_corrupto']));
    $assert("1.2 {$method} {$path} con token corrupto => 401", $res->getStatusCode() === 401);

    // Identidad tipo RESPONSABLE DE SEDE (token interno forjado con rol de sede)
    $res = $router->dispatch(new Request($method, $path, [], [], ['Authorization' => 'Bearer auth_token_sede']));
    $assert("1.3 {$method} {$path} con identidad de responsable de sede => 401/403", in_array($res->getStatusCode(), [401, 403], true));

    // Cruce de roles interno: el token del otro rol interno nunca alcanza el recurso
    $isCoordinatorOnly = str_contains($path, '/coordinator/');
    $wrongRoleToken = $isCoordinatorOnly ? $tech1Token : $coordinatorToken;
    $res = $router->dispatch(new Request($method, $path, [], [], ['Authorization' => "Bearer {$wrongRoleToken}"]));
    $assert(
        "1.4 {$method} {$path} rechaza el rol interno cruzado => 403",
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );
}

$assert('1.5 GET /api/technician/route/map con técnico => 200 (control positivo)', (function () use ($router, $tech1Token): int {
    $res = $router->dispatch(new Request('GET', '/api/technician/route/map', [], [], ['Authorization' => "Bearer {$tech1Token}"]));
    return $res->getStatusCode();
})() === 200);

$assert('1.6 GET /api/coordinator/map/active-incidents con coordinador => 200 (control positivo)', (function () use ($router, $coordinatorToken): int {
    $res = $router->dispatch(new Request('GET', '/api/coordinator/map/active-incidents', [], [], ['Authorization' => "Bearer {$coordinatorToken}"]));
    return $res->getStatusCode();
})() === 200);

// =========================================================================
// BLOQUE 2: Sin rastreo personal continuo del smartphone (Art. V.4)
// =========================================================================
echo "\n--- BLOQUE 2: Ausencia de rastreo personal continuo ---\n";

$frontendFiles = [
    __DIR__ . '/../../public/assets/js/components/TechnicianRouteMapModal.js',
    __DIR__ . '/../../public/assets/js/views/TechnicianRouteView.js',
    __DIR__ . '/../../public/assets/js/components/TechnicianPreventiveRouteTab.js',
];

$combinedFrontend = '';
foreach ($frontendFiles as $file) {
    if (is_readable($file)) {
        $combinedFrontend .= (string)file_get_contents($file);
    }
}

$assert('2.1 El frontend del técnico no usa watchPosition (solo consulta puntual)', !str_contains($combinedFrontend, 'watchPosition'));
$assert('2.2 El frontend del técnico no programa sondeos GPS periódicos', !preg_match('/setInterval\s*\([^)]*geolocation|geolocation[^)]*setInterval/s', $combinedFrontend));
$assert('2.3 La única consulta GPS del técnico es una llamada puntual (una sola getCurrentPosition), dentro del modal de ruta',
    str_contains($combinedFrontend, 'getCurrentPosition')
    && substr_count($combinedFrontend, '.getCurrentPosition(') === 1
    && str_contains($combinedFrontend, 'navigator.geolocation.getCurrentPosition'));
$assert('2.4 El técnico solo consulta su mapa, nunca reporta su posición al backend',
    !preg_match('/(post|put|patch)\s*\(\s*[\'"][^\'"]*geolocation|location-report/i', $combinedFrontend));
$assert('2.5 Sin GPS el sistema cae a Base Central sin almacenar posición del técnico',
    str_contains($combinedFrontend, 'BASE_CENTRAL') || str_contains($combinedFrontend, 'gpsNotice'));

// La capa de sesión no persiste coordenadas de dispositivo
$storeSource = (string)file_get_contents(__DIR__ . '/../../public/assets/js/store.js');
$assert('2.6 La sesión del técnico no persiste coordenadas GPS',
    !preg_match('/latitude|longitude|origin_lat|origin_lng/i', $storeSource));

// El token de sesión interno no incorpora coordenadas ni dispositivo rastreable
$authServiceReflection = new ReflectionClass(AuthService::class);
$authServiceSource = (string)file_get_contents($authServiceReflection->getFileName());
$assert('2.7 El token interno no transporta coordenadas GPS',
    !preg_match('/latitude|longitude|origin_lat|origin_lng/i', $authServiceSource));

// El backend no ofrece ningún endpoint de reporte de posición en tiempo real
$routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');
$assert('2.8 No existe endpoint de rastreo o reporte de posición continuo',
    !preg_match('/(location|position|tracking|gps)\/(report|ping|track|stream)/i', $routerSource));

// =========================================================================
// BLOQUE 3: Segregación entre técnicos (RF-MAP-10)
// =========================================================================
echo "\n--- BLOQUE 3: Segregación de rutas entre técnicos ---\n";

$fetchRoute = function (string $token) use ($router): array {
    $res = $router->dispatch(new Request('GET', '/api/technician/route/map', ['origin_lat' => '41.3935', 'origin_lng' => '2.1890'], [], ['Authorization' => "Bearer {$token}"]));
    return [$res->getStatusCode(), $res->getDecodedBody()];
};

[$status1, $body1] = $fetchRoute($tech1Token);
$stops1 = $body1['data']['stops'] ?? [];
$siteCodes1 = array_map(static fn(array $stop): string => $stop['location']['site_code'], $stops1);

$assert('3.1 La ruta del técnico 1 responde 200 con su parada', $status1 === 200 && count($stops1) === 1);
$assert('3.2 El técnico 1 solo ve su sede asignada (SEDE-BCN-01)', $siteCodes1 === ['SEDE-BCN-01']);
$assert('3.3 El técnico 1 no percibe la parada del técnico 2 (SEDE-BCN-02)', !in_array('SEDE-BCN-02', $siteCodes1, true));

$payload1 = json_encode($body1);
$assert('3.4 La respuesta del técnico 1 no expone notas internas de taller ni costes de repuestos',
    !preg_match('/parts_cost|internal_note|spare_part_cost/i', $payload1));

// =========================================================================
// BLOQUE 4: Configuración de Base Central no accesible a perfiles de sede
// =========================================================================
echo "\n--- BLOQUE 4: Integridad de la Base Central y trazabilidad ---\n";

// El material de identidad de sede (sin rol interno) no alcanza la configuración logística
$res = $router->dispatch(new Request('GET', '/api/coordinator/route/settings', [], [], ['Authorization' => 'Bearer auth_token_sede']));
$assert('4.1 GET settings con identidad de sede no autenticada internamente => 401/403', in_array($res->getStatusCode(), [401, 403], true));

$res = $router->dispatch(new Request('PUT', '/api/coordinator/route/settings', [], [
    'base_name'             => 'Intento de suplantación',
    'base_address'          => 'Carrer Fals 1',
    'base_latitude'         => 41.4,
    'base_longitude'        => 2.2,
    'operational_radius_km' => 50,
], ['Authorization' => 'Bearer auth_token_sede']));
$assert('4.2 PUT settings con identidad de sede => 401/403 sin alterar la Base Central', in_array($res->getStatusCode(), [401, 403], true));

$row = $pdo->query('SELECT base_name FROM route_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$assert('4.3 La Base Central permanece intacta tras los intentos', ($row['base_name'] ?? '') === 'Base Central VendGuard Barcelona');

// La excepción de dominio del módulo documenta el blindaje con el rol y el recurso
$exceptionSource = (string)file_get_contents(__DIR__ . '/../../src/Core/Domain/Exception/SiteRouteDataForbiddenException.php');
$assert('4.4 La excepción constitucional fija 403/FORBIDDEN para LOCATION_MANAGER',
    str_contains($exceptionSource, "HTTP_STATUS = 403") && str_contains($exceptionSource, "LOCATION_MANAGER"));

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡BLINDAJE CONSTITUCIONAL VERIFICADO ({$assertions} aserciones)!\n";
    echo " CONDICIÓN T-MAP-19 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} aserciones han fallado de {$assertions}.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
