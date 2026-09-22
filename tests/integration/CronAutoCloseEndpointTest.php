<?php

declare(strict_types=1);

/**
 * CronAutoCloseEndpointTest (T-30)
 *
 * Validates Background Batch Auto-Close Endpoint (RF-10 / EARS 10.1, 10.2):
 *   - POST /api/cron/auto-close protected by token / secret header.
 *   - Closes and permanently archives incidents in RESOLVED state older than 48 hours.
 *   - Preserves incidents in RESOLVED state younger than 48 hours (warranty window active).
 *   - Does not alter incidents in active states (IN_PROGRESS, ASSIGNED, REGISTERED, etc.).
 *   - Inserts immutable audit trail in incident_history.
 *   - Real HTTP verification via cURL against local dev server.
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
use VendGuard\Presentation\Controller\CronController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - CronAutoCloseEndpointTest (T-30)\n";
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

$cronSecret = CronController::DEFAULT_CRON_SECRET;

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
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);

// ─── Sedes y Máquinas ─────────────────────────────────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper para crear incidencias con antigüedad personalizada en resolved_at ─
$createResolvedIncident = function (
    int $machineId,
    int $locationId,
    int $technicianId,
    int $hoursAgo
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket previo en la máquina
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    $code = 'T30-' . uniqid();
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PRODUCT_JAM,
        description: "Incidencia de prueba resuelta hace {$hoursAgo} horas.",
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    // Actualizar a RESOLVED con fecha simulada en el pasado
    $pastResolvedAt = date('Y-m-d H:i:s', time() - ($hoursAgo * 3600));
    $pdo->prepare("
        UPDATE incidents 
        SET status = 'RESOLVED',
            assigned_technician_id = :tech_id,
            resolution_diagnosis = 'Atasco en espiral mecánica 03 por deformación de envase.',
            resolution_action = 'Extracción de envase defectuoso y prueba de rotación correcta.',
            resolved_at = :resolved_at,
            updated_at = :updated_at
        WHERE id = :id
    ")->execute([
        ':tech_id'     => $technicianId,
        ':resolved_at' => $pastResolvedAt,
        ':updated_at'  => $pastResolvedAt,
        ':id'          => $created->getId(),
    ]);


    return $incidentRepo->findById($created->getId());
};

// =========================================================================
// CASO 1: Seguridad y Control de Acceso por Token (401 Unauthorized)
// =========================================================================
echo "\n--- Caso 1: Seguridad y Control de Acceso por Token ---\n";

// 1.1 Sin ningún encabezado de autorización => 401
$req = new Request(method: 'POST', path: '/api/cron/auto-close');
$res = $router->dispatch($req);
$assert("1.1 Sin token ni cabecera => 401 Unauthorized", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

// 1.2 Cabecera X-Cron-Secret errónea => 401
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['X-Cron-Secret' => 'secreto_falso_123']);
$res = $router->dispatch($req);
$assert("1.2 X-Cron-Secret incorrecto => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.3 Bearer token con secreto incorrecto => 401
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['Authorization' => 'Bearer token_invalido']);
$res = $router->dispatch($req);
$assert("1.3 Bearer token incorrecto => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.4 Token de técnico (solo se admite secreto o coordinador) => 401
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['Authorization' => "Bearer {$technicianToken}"]);
$res = $router->dispatch($req);
$assert("1.4 Token de técnico no autorizado para cron => 401 Unauthorized", $res->getStatusCode() === 401);

// 1.5 Cabecera X-Cron-Secret válida => autorizada (no 401)
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['X-Cron-Secret' => $cronSecret]);
$res = $router->dispatch($req);
$assert("1.5 Cabecera X-Cron-Secret válida => 200 OK", $res->getStatusCode() === 200);

// 1.6 Bearer con secreto de cron => autorizada (200 OK)
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['Authorization' => "Bearer {$cronSecret}"]);
$res = $router->dispatch($req);
$assert("1.6 Bearer cron secret válido => 200 OK", $res->getStatusCode() === 200);

// 1.7 Bearer con token de Coordinador => autorizada (200 OK)
$req = new Request(method: 'POST', path: '/api/cron/auto-close', headers: ['Authorization' => "Bearer {$coordinatorToken}"]);
$res = $router->dispatch($req);
$assert("1.7 Bearer de Coordinador autorizado => 200 OK", $res->getStatusCode() === 200);

// =========================================================================
// CASO 2: Auto-Cierre de Incidencias con > 48 Horas en RESUELTA [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- Caso 2: Cierre Definitivo tras 48h en RESUELTA (RF-10 / EARS 10.1, 10.2) ---\n";

// Preparar escenario:
// Incidencia 1: resuelta hace 50 horas (> 48h) -> DEBE CERRARSE
$incExpired1 = $createResolvedIncident($mach1->getId(), $location1->getId(), $technician->getId(), 50);

// Incidencia 2: resuelta hace 72 horas (> 48h) -> DEBE CERRARSE
$incExpired2 = $createResolvedIncident($mach2->getId(), $location1->getId(), $technician->getId(), 72);

// Incidencia 3: resuelta hace 20 horas (< 48h, dentro de garantía) -> NO DEBE CERRARSE
$incInWarranty = $createResolvedIncident($mach3->getId(), $location2->getId(), $technician->getId(), 20);

// Ejecutar el endpoint POST /api/cron/auto-close
$req = new Request(
    method: 'POST',
    path: '/api/cron/auto-close',
    headers: ['X-Cron-Secret' => $cronSecret]
);
$res = $router->dispatch($req);
$body = $res->getDecodedBody();

$assert("2.1 POST /api/cron/auto-close responde HTTP 200 OK", $res->getStatusCode() === 200);
$assert("2.2 success es true", ($body['success'] ?? false) === true);

$data = $body['data'] ?? [];
$closedCount = $data['closed_count'] ?? 0;
$closedTickets = $data['closed_tickets'] ?? [];

$assert("2.3 closed_count reporta exactamente 2 incidencias archivadas", $closedCount === 2, "Recibido: {$closedCount}");
$assert("2.4 closed_tickets contiene el ticket de 50h ({$incExpired1->getTicketCode()})", in_array($incExpired1->getTicketCode(), $closedTickets, true));
$assert("2.5 closed_tickets contiene el ticket de 72h ({$incExpired2->getTicketCode()})", in_array($incExpired2->getTicketCode(), $closedTickets, true));
$assert("2.6 closed_tickets NO contiene el ticket de 20h en garantía ({$incInWarranty->getTicketCode()})", !in_array($incInWarranty->getTicketCode(), $closedTickets, true));

// Comprobaciones en Base de Datos para las cerradas
$dbClosed1 = $pdo->query("SELECT status, closed_at FROM incidents WHERE id = {$incExpired1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("2.7 BD Inc 1 (50h): status = CLOSED", ($dbClosed1['status'] ?? null) === 'CLOSED');
$assert("2.8 BD Inc 1 (50h): closed_at está fijado y no es nulo", !empty($dbClosed1['closed_at']));

$dbClosed2 = $pdo->query("SELECT status, closed_at FROM incidents WHERE id = {$incExpired2->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("2.9 BD Inc 2 (72h): status = CLOSED", ($dbClosed2['status'] ?? null) === 'CLOSED');
$assert("2.10 BD Inc 2 (72h): closed_at está fijado y no es nulo", !empty($dbClosed2['closed_at']));

// Comprobación en Base de Datos para la que sigue en garantía (20h)
$dbWarranty = $pdo->query("SELECT status, closed_at FROM incidents WHERE id = {$incInWarranty->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("2.11 BD Inc 3 (20h): status PERMANECE en RESOLVED", ($dbWarranty['status'] ?? null) === 'RESOLVED');
$assert("2.12 BD Inc 3 (20h): closed_at es NULL", $dbWarranty['closed_at'] === null);

// Comprobación de auditoría inmutable
$hist = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incExpired1->getId()} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert("2.13 Historial inmutable: from_status = RESOLVED, to_status = CLOSED", 
    ($hist['from_status'] ?? null) === 'RESOLVED' && ($hist['to_status'] ?? null) === 'CLOSED'
);
$assert("2.14 Historial inmutable: nota explicativa de auto-cierre presente", 
    str_contains((string)($hist['action_note'] ?? ''), '48h')
);

// Comprobación de idempotencia: una segunda ejecución no re-cierra nada
$resSecond = $router->dispatch($req);
$bodySecond = $resSecond->getDecodedBody();
$assert("2.15 Segunda ejecución consecutiva devuelve closed_count = 0 (idempotencia)", ($bodySecond['data']['closed_count'] ?? -1) === 0);

// =========================================================================
// CASO 3: Prueba HTTP Real vía cURL contra 127.0.0.1:8000
// =========================================================================
echo "\n--- Caso 3: Prueba HTTP Real contra 127.0.0.1:8000 ---\n";

// Crear una nueva incidencia con 49 horas de antigüedad para probar vía servidor HTTP real
$incCurl = $createResolvedIncident($mach1->getId(), $location1->getId(), $technician->getId(), 49);

$ch = curl_init("http://127.0.0.1:8000/api/cron/auto-close");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "X-Cron-Secret: {$cronSecret}",
    ],
    CURLOPT_TIMEOUT        => 5,
]);
$rawCurl = curl_exec($ch);
$codeCurl = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$jsonCurl = json_decode((string)$rawCurl, true) ?? [];

$assert("3.1 cURL /api/cron/auto-close responde HTTP 200 OK", $codeCurl === 200, "Código: {$codeCurl}, Body: {$rawCurl}");
$assert("3.2 cURL => success: true", ($jsonCurl['success'] ?? false) === true);
$assert("3.3 cURL => closed_count >= 1", ($jsonCurl['data']['closed_count'] ?? 0) >= 1);
$assert("3.4 cURL => ticket de 49h figura en closed_tickets", in_array($incCurl->getTicketCode(), $jsonCurl['data']['closed_tickets'] ?? [], true));

// Verificar en BD que tras cURL el estado sea CLOSED
$dbCurlCheck = $pdo->query("SELECT status, closed_at FROM incidents WHERE id = {$incCurl->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert("3.5 BD tras cURL: status = CLOSED y closed_at no nulo", 
    ($dbCurlCheck['status'] ?? null) === 'CLOSED' && !empty($dbCurlCheck['closed_at'])
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
