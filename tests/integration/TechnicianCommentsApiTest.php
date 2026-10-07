<?php

declare(strict_types=1);

/**
 * VendGuard - TechnicianCommentsApiTest
 *
 * Test de integración HTTP contra MariaDB real del hilo de conversación visto desde
 * el Técnico de Ruta (Módulo 10, T-COM-18 · RF-01, RF-02.3, RF-03.3, RF-04, RF-05,
 * RNF-01, Constitución Art. V.4 y Art. V.5).
 *
 * Valida la condición "Hecho cuando":
 * 1. Publicación con el selector de privacidad: `is_internal = 1` se persiste en la
 *    tabla `incident_comments` y se refleja como candado en la respuesta.
 * 2. Fail-safe de clasificación: sin campo `is_internal`, la nota se guarda interna.
 * 3. Visibilidad nominal real del equipo de taller: las notas internas de compañeros
 *    llegan con el nombre completo del técnico autor y el indicador de candado.
 * 4. Fotografía in situ adjunta en multipart/form-data (≤ 5 MB, magic bytes reales):
 *    HTTP 201, archivo persistido en public/uploads/ y `photo_path` en base de datos.
 * 5. La insignia de la parada de ruta ("Mi Ruta") contabiliza la totalidad de mensajes.
 * 6. Segregación cruzada: la Sede sobre el mismo expediente solo recibe los públicos
 *    (cero fugas de notas internas ni del nombre real del técnico).
 * 7. Seguridad RBAC: acceso anónimo (401), rol ajeno (403) y expediente no asignado (403).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y enrutador frontal del proyecto (sin
 * frameworks ni dependencias externas).
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
echo " VendGuard: Test de Integración - TechnicianCommentsApiTest (T-COM-18)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

TestDataCleaner::purge($pdo);
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

$uploadsDir = dirname(__DIR__, 2) . '/public/uploads';
$storedUploads = [];

// ─── Escenario: expediente asignado al técnico de ruta ──────────────────────
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coworker = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$machine = $machineRepo->findByCode('VEND-0101');

$assert('0.1 Semillas localizadas (sede, dos técnicos, coordinador y máquina)',
    $location !== null && $technician !== null && $coworker !== null
        && $coordinator !== null && $machine !== null);

if ($location === null || $technician === null || $coworker === null || $coordinator === null || $machine === null) {
    echo "ERROR CRÍTICO: faltan semillas requeridas para la suite.\n";
    exit(1);
}

$technicianId = (int)$technician->getId();
$coworkerId = (int)$coworker->getId();

// Liberar el aviso activo previo de la máquina (restricción uq_machine_active_ticket).
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-TEC-' . strtoupper(substr(uniqid(), -6)),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Expediente asignado al técnico para validar el hilo de taller.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: $technicianId,
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    assignedAt: date('Y-m-d H:i:s'),
    createdAt: null
));

$incidentId = (int)$incident->getId();
$ticketCode = $incident->getTicketCode();

$assert('0.2 Expediente creado y asignado al técnico de ruta (estado ASSIGNED)',
    $incidentId > 0
        && $incident->getStatus() === IncidentStatus::ASSIGNED
        && $incident->getAssignedTechnicianId() === $technicianId);

$techToken = $authService->generateInternalToken($technician);
$coworkerToken = $authService->generateInternalToken($coworker);
$coordToken = $authService->generateInternalToken($coordinator);
$siteToken = $authService->generateSiteToken($location);

// =========================================================================
// CASO 1: Seguridad RBAC del canal de taller
// =========================================================================
echo "\n--- Caso 1: Seguridad RBAC del hilo del técnico ---\n";

$resAnonGet = $router->dispatch(new Request('GET', "/api/technician/incidents/{$incidentId}/comments"));
$assert('1.1 GET anónimo del hilo => HTTP 401 UNAUTHORIZED',
    $resAnonGet->getStatusCode() === 401 && ($resAnonGet->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$resCoord = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('1.2 Coordinador en el canal de taller => HTTP 403 FORBIDDEN',
    $resCoord->getStatusCode() === 403 && ($resCoord->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

$resCoworker = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coworkerToken]
));
$assert('1.3 Técnico ajeno al expediente => HTTP 403 NOT_ASSIGNED_TO_TECHNICIAN',
    $resCoworker->getStatusCode() === 403
        && ($resCoworker->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN',
    "status: {$resCoworker->getStatusCode()}, code: " . ($resCoworker->getDecodedBody()['error']['code'] ?? '—'));

// =========================================================================
// CASO 2: Nota interna explícita persistida como is_internal = 1 (RF-03.3)
// =========================================================================
echo "\n--- Caso 2: Nota interna explícita persistida (is_internal = 1) ---\n";

$resInternal = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Reviso la electroválvula con el manómetro de taller.',
        'is_internal' => '1',
    ],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$internalBody = $resInternal->getDecodedBody();
$internalComments = is_array($internalBody['data']['comments'] ?? null) ? $internalBody['data']['comments'] : [];
$newInternal = end($internalComments) ?: [];

$assert('2.1 La publicación responde HTTP 201 Created con mensaje de nota interna',
    $resInternal->getStatusCode() === 201
        && ($internalBody['message'] ?? '') === 'Nota interna registrada en el hilo de conversación',
    "status: {$resInternal->getStatusCode()}");

$assert('2.2 La respuesta marca el candado is_internal === true',
    array_key_exists('is_internal', $newInternal) && $newInternal['is_internal'] === true);

$assert('2.3 La autoría nominal real del técnico viaja en la marca del taller (RF-02.3)',
    ($newInternal['author_name'] ?? '') === $technician->getName()
        && ($newInternal['author_type'] ?? '') === 'TECHNICIAN');

$stmtInternal = $pdo->prepare("SELECT author_type, user_id, author_name, is_internal FROM `incident_comments` WHERE id = :id");
$stmtInternal->execute([':id' => (int)($newInternal['id'] ?? 0)]);
$rowInternal = $stmtInternal->fetch(PDO::FETCH_ASSOC) ?: [];

$assert('2.4 En base de datos la fila queda con is_internal = 1',
    (int)($rowInternal['is_internal'] ?? -1) === 1 && ($rowInternal['author_type'] ?? '') === 'TECHNICIAN',
    'fila real: ' . json_encode($rowInternal));

$assert('2.5 El mensaje interno se vincula al usuario técnico autenticado',
    (int)($rowInternal['user_id'] ?? 0) === $technicianId && ($rowInternal['author_name'] ?? '') === $technician->getName());

// =========================================================================
// CASO 3: Fail-safe de clasificación y publicación pública explícita
// =========================================================================
echo "\n--- Caso 3: Fail-safe interno por defecto y mensaje público explícito ---\n";

$resFailSafe = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Sin selector de privacidad el mensaje debe ser interno.'],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$failSafeId = (int)$pdo->query("SELECT id FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$failSafeFlag = (int)$pdo->query("SELECT is_internal FROM `incident_comments` WHERE id = {$failSafeId}")->fetchColumn();

$assert('3.1 Sin campo is_internal la nota se clasifica como interna (RF-03.3)',
    $resFailSafe->getStatusCode() === 201 && $failSafeFlag === 1,
    "status: {$resFailSafe->getStatusCode()}, is_internal: {$failSafeFlag}");

$resPublic = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Aviso a la sede: intervengo esta mañana entre las 9 y las 11.',
        'is_internal' => '0',
    ],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$publicId = (int)$pdo->query("SELECT id FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$publicFlag = (int)$pdo->query("SELECT is_internal FROM `incident_comments` WHERE id = {$publicId}")->fetchColumn();

$assert('3.2 Con is_internal = 0 el mensaje se persiste como público',
    $resPublic->getStatusCode() === 201 && $publicFlag === 0,
    "status: {$resPublic->getStatusCode()}, is_internal: {$publicFlag}");

$resInvalidFlag = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Indicador booleano no reconocible.', 'is_internal' => 'quizá'],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$assert('3.3 Un indicador is_internal no booleano => HTTP 422 INVALID_IS_INTERNAL',
    $resInvalidFlag->getStatusCode() === 422
        && ($resInvalidFlag->getDecodedBody()['error']['code'] ?? '') === 'INVALID_IS_INTERNAL');

$resTooShort = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Ok', 'is_internal' => '1'],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$assert('3.4 Texto inferior a 5 caracteres => HTTP 422 INVALID_COMMENT_LENGTH (RF-03.1)',
    $resTooShort->getStatusCode() === 422
        && ($resTooShort->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COMMENT_LENGTH');

// =========================================================================
// CASO 4: Nota interna de un compañero (visibilidad nominal real)
// =========================================================================
echo "\n--- Caso 4: Nota interna publicada por un compañero de taller ---\n";

// El expediente pasa temporalmente al compañero para que publique por el mismo canal.
$pdo->prepare("UPDATE incidents SET assigned_technician_id = :tech WHERE id = :id")
    ->execute([':tech' => $coworkerId, ':id' => $incidentId]);

$resCoworkerPost = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Ayer revisé esta máquina: la bomba vibraba en el arranque.',
        'is_internal' => '1',
    ],
    headers: ['Authorization' => 'Bearer ' . $coworkerToken]
));

$assert('4.1 El compañero asignado publica su nota interna por el mismo endpoint',
    $resCoworkerPost->getStatusCode() === 201,
    "status: {$resCoworkerPost->getStatusCode()}");

// El expediente vuelve a su técnico titular para continuar la prueba.
$pdo->prepare("UPDATE incidents SET assigned_technician_id = :tech WHERE id = :id")
    ->execute([':tech' => $technicianId, ':id' => $incidentId]);

// =========================================================================
// CASO 5: El técnico titular ve el hilo íntegro con nombres reales (RF-02.3)
// =========================================================================
echo "\n--- Caso 5: Hilo íntegro con identidad nominal del equipo ---\n";

$resThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$threadBody = $resThread->getDecodedBody();
$thread = $threadBody['data'] ?? [];
$comments = is_array($thread['comments'] ?? null) ? $thread['comments'] : [];

$assert('5.1 El hilo del técnico responde HTTP 200 OK', $resThread->getStatusCode() === 200);

$assert('5.2 El técnico recibe los 4 mensajes publicados (3 internos + 1 público)',
    count($comments) === 4,
    'recibidos: ' . count($comments));

$internalProjected = array_values(array_filter($comments, static fn(array $c): bool => ($c['is_internal'] ?? false) === true));
$publicProjected = array_values(array_filter($comments, static fn(array $c): bool => ($c['is_internal'] ?? true) === false));

$assert('5.3 Las 3 notas internas llegan marcadas con el candado is_internal = true',
    count($internalProjected) === 3,
    'internas proyectadas: ' . count($internalProjected));

$assert('5.4 El mensaje público llega desmarcado (is_internal = false)',
    count($publicProjected) === 1);

$coworkerComment = null;
foreach ($comments as $commentItem) {
    if (($commentItem['author_name'] ?? '') === $coworker->getName()) {
        $coworkerComment = $commentItem;
        break;
    }
}

$assert('5.5 La nota del compañero muestra su nombre nominal completo, sin enmascarar (RF-02.3)',
    $coworkerComment !== null
        && ($coworkerComment['is_internal'] ?? false) === true
        && ($coworkerComment['comment_text'] ?? '') === 'Ayer revisé esta máquina: la bomba vibraba en el arranque.',
    'comentario real: ' . json_encode($coworkerComment));

$assert('5.6 El compañero no es autor propio; los mensajes del titular sí (is_own_message)',
    $coworkerComment !== null && ($coworkerComment['is_own_message'] ?? true) === false
        && array_reduce($comments, static fn(bool $carry, array $c): bool =>
            $carry || (($c['author_name'] ?? '') === ($technician->getName()) && ($c['is_own_message'] ?? false) === true), false));

$assert('5.7 El recuento total del hilo incluye públicos y notas internas (RF-01.1)',
    (int)($thread['pagination']['total_comments'] ?? -1) === 4
        && (int)($thread['pagination']['loaded_count'] ?? -1) === 4);

// =========================================================================
// CASO 6: Fotografía in situ adjunta (RF-04.1, Art. V.5)
// =========================================================================
echo "\n--- Caso 6: Fotografía in situ adjunta a la nota de taller ---\n";

$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'comment_tech_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$resPhoto = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Adjunto la válvula sustituida como evidencia de la intervención.',
        'is_internal' => '1',
    ],
    headers: ['Authorization' => 'Bearer ' . $techToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'valvula_sustituida.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tempJpg,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempJpg),
        ],
    ]
));
$photoBody = $resPhoto->getDecodedBody();
$photoComments = is_array($photoBody['data']['comments'] ?? null) ? $photoBody['data']['comments'] : [];
$photoComment = end($photoComments) ?: [];
$photoPath = (string)($photoComment['photo_url'] ?? '');
$storedUploads[] = $photoPath;

$assert('6.1 La nota con evidencia responde HTTP 201 Created',
    $resPhoto->getStatusCode() === 201,
    "status: {$resPhoto->getStatusCode()}");

$assert('6.2 La evidencia se almacena bajo /uploads/ con nombre hash',
    str_starts_with($photoPath, '/uploads/') && str_ends_with($photoPath, '.jpg'),
    "photo_url: {$photoPath}");

$assert('6.3 El archivo físico existe en public/uploads/',
    $photoPath !== '' && is_file(dirname(__DIR__, 2) . '/public' . $photoPath));

$photoRow = $pdo->query("SELECT photo_path, is_internal FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$assert('6.4 La BD persiste la ruta de la foto y conserva la clasificación interna',
    ($photoRow['photo_path'] ?? '') === $photoPath && (int)($photoRow['is_internal'] ?? -1) === 1,
    'fila real: ' . json_encode($photoRow));

// =========================================================================
// CASO 7: Insignia de la parada de ruta (RF-01.1)
// =========================================================================
echo "\n--- Caso 7: Contador total de la parada en \"Mi Ruta\" ---\n";

$resRoute = $router->dispatch(new Request(
    method: 'GET',
    path: '/api/technician/my-route',
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$routeBody = $resRoute->getDecodedBody();
$routeStops = is_array($routeBody['data'] ?? null) ? $routeBody['data'] : [];
$stop = null;
foreach ($routeStops as $routeStop) {
    if ((int)($routeStop['id'] ?? 0) === $incidentId) {
        $stop = $routeStop;
        break;
    }
}

$dbTotal = (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();

$assert('7.1 La parada del expediente aparece en la ruta del técnico',
    $resRoute->getStatusCode() === 200 && $stop !== null,
    "status: {$resRoute->getStatusCode()}, paradas: " . count($routeStops));

$assert('7.2 La insignia de la parada contabiliza la totalidad de mensajes del hilo',
    $stop !== null && (int)($stop['comments_count'] ?? -1) === $dbTotal && $dbTotal === 5,
    'comments_count real: ' . var_export($stop['comments_count'] ?? null, true) . ", BD: {$dbTotal}");

// =========================================================================
// CASO 8: Segregación cruzada hacia la Sede (Art. V.4)
// =========================================================================
echo "\n--- Caso 8: La Sede sobre el mismo expediente no ve el taller ---\n";

$resSite = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$siteBody = $resSite->getDecodedBody();
$siteComments = is_array($siteBody['data']['comments'] ?? null) ? $siteBody['data']['comments'] : [];

$assert('8.1 La Sede solo recibe el mensaje público del expediente',
    $resSite->getStatusCode() === 200 && count($siteComments) === 1,
    'recibidos: ' . count($siteComments));

$assert('8.2 Cero fugas: ni el campo is_internal ni las notas internas en el payload de la Sede',
    !str_contains($resSite->getBody(), 'is_internal')
        && !str_contains($resSite->getBody(), 'bomba vibraba')
        && !str_contains($resSite->getBody(), 'valvula_sustituida'));

$assert('8.3 El nombre real del técnico no se filtra a la Sede (identidad enmascarada)',
    !str_contains($resSite->getBody(), $technician->getName())
        && !str_contains($resSite->getBody(), $coworker->getName()));

// =========================================================================
// CASO 9: Reapertura en garantía: lectura histórica del técnico sin reasignar (RF-05.4)
// =========================================================================
echo "\n--- Caso 9: Ciclo resolver -> reabrir -> leer sin publicar -> reasignar ---\n";

// 9.0 El técnico titular cierra su intervención para habilitar la reapertura.
$resStart = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/technician/incidents/{$incidentId}/start",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$resResolve = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/resolve",
    queryParams: [],
    parsedBody: [
        'resolution_diagnosis' => 'Electroválvula del circuito de agua con contacto intermitente.',
        'resolution_action' => 'Sustitución de la electroválvula, purga del circuito y tres ventas de prueba.',
    ],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$assert('9.0 El técnico inicia y resuelve el expediente antes de la reapertura',
    $resStart->getStatusCode() === 200 && $resResolve->getStatusCode() === 200,
    "start: {$resStart->getStatusCode()}, resolve: {$resResolve->getStatusCode()}");

// 9.1 La sede reabre dentro de la ventana de garantía (EARS 9.1).
$resReopen = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/incidents/{$ticketCode}/reopen",
    queryParams: [],
    parsedBody: ['reopen_reason' => 'La máquina vuelve a quedarse sin agua después de la reparación.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$reopenBody = $resReopen->getDecodedBody()['data'] ?? [];
$dbAfterReopen = $pdo->query("SELECT status, assigned_technician_id FROM `incidents` WHERE id = {$incidentId}")->fetch(PDO::FETCH_ASSOC) ?: [];
$assert('9.1 La reapertura devuelve REOPENED y desasigna al técnico',
    $resReopen->getStatusCode() === 200
    && ($reopenBody['status'] ?? '') === 'REOPENED'
    && ($reopenBody['status_label'] ?? '') === 'Reabierta'
    && ($dbAfterReopen['status'] ?? '') === 'REOPENED'
    && array_key_exists('assigned_technician_id', $dbAfterReopen)
    && $dbAfterReopen['assigned_technician_id'] === null,
    'reopen: ' . $resReopen->getStatusCode() . ' cuerpo: ' . json_encode($reopenBody) . ' fila: ' . json_encode($dbAfterReopen));

$reopenAuditRow = $pdo->query(
    "SELECT action, user_role FROM `audit_log` WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'REOPEN_TICKET'"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$assert('9.2 La reapertura queda auditada como REOPEN_TICKET con actor de sede',
    ($reopenAuditRow['action'] ?? '') === 'REOPEN_TICKET' && ($reopenAuditRow['user_role'] ?? '') === 'SITE_MANAGER',
    'fila audit_log: ' . json_encode($reopenAuditRow));

// 9.3 El técnico que intervino conserva la lectura del hilo, con la publicación cerrada.
$resReopenedThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$reopenedThreadIncident = $resReopenedThread->getDecodedBody()['data']['incident'] ?? [];
$reopenedThreadComments = $resReopenedThread->getDecodedBody()['data']['comments'] ?? [];
$assert('9.3 El técnico con antecedentes recibe el hilo reabierto con HTTP 200',
    $resReopenedThread->getStatusCode() === 200,
    'status: ' . $resReopenedThread->getStatusCode() . ' code: ' . ($resReopenedThread->getDecodedBody()['error']['code'] ?? '—'));
$assert('9.4 El hilo llega íntegro (notas internas incluidas) y sin sellar',
    count($reopenedThreadComments) >= 4
    && ($reopenedThreadIncident['is_sealed'] ?? null) === false
    && ($reopenedThreadIncident['status'] ?? '') === 'REOPENED',
    'mensajes: ' . count($reopenedThreadComments) . ' incident: ' . json_encode($reopenedThreadIncident));
$assert('9.5 La publicación queda deshabilitada con el motivo de reapertura pendiente',
    ($reopenedThreadIncident['can_comment'] ?? null) === false
    && ($reopenedThreadIncident['read_only_reason'] ?? '') === 'REOPENED_AWAITING_REASSIGNMENT');

$resReopenedPost = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Nota del técnico antes de que coordinación reasigne el expediente.', 'is_internal' => true],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$assert('9.6 El POST del técnico desasignado sigue rechazándose con 403 sin persistir',
    $resReopenedPost->getStatusCode() === 403
    && ($resReopenedPost->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN'
    && (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId} AND comment_text LIKE 'Nota del técnico antes%'")->fetchColumn() === 0,
    'status: ' . $resReopenedPost->getStatusCode());

// 9.7 Tras la reasignación del coordinador, el técnico recupera la publicación.
$resAssign = $router->dispatch(new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incidentId}/assign",
    queryParams: [],
    parsedBody: ['technician_id' => $technicianId],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$resAfterAssignGet = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$resAfterAssignPost = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/technician/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Retomo el expediente reabierto para revisar la electroválvula.', 'is_internal' => true],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$afterAssignIncident = $resAfterAssignGet->getDecodedBody()['data']['incident'] ?? [];
$assert('9.7 Tras la reasignación el técnico vuelve a publicar (can_comment true y 201)',
    $resAssign->getStatusCode() === 200
    && $resAfterAssignGet->getStatusCode() === 200
    && ($afterAssignIncident['can_comment'] ?? null) === true
    && $resAfterAssignPost->getStatusCode() === 201,
    'assign: ' . $resAssign->getStatusCode() . ' GET: ' . $resAfterAssignGet->getStatusCode()
        . ' POST: ' . $resAfterAssignPost->getStatusCode());

// ─── Limpieza final (convención de la batería) ─────────────────────────────
foreach ($storedUploads as $uploadedPath) {
    if ($uploadedPath !== '') {
        $uploadedFile = dirname(__DIR__, 2) . '/public' . $uploadedPath;
        if (is_file($uploadedFile)) {
            unlink($uploadedFile);
        }
    }
}
if (is_file($tempJpg)) {
    unlink($tempJpg);
}

TestDataCleaner::purge($pdo);
$seedRunner->seedAll();
echo "\nBase de datos restablecida a las semillas (limpieza de la suite).\n";

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. TECHNICIAN COMMENTS API (T-COM-18) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
