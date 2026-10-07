<?php

declare(strict_types=1);

/**
 * VendGuard - CoordinatorCommentsApiTest
 *
 * Test de integración HTTP contra MariaDB real del hilo de conversación visto desde
 * la Coordinación del Servicio (Módulo 10, T-COM-19 · RF-02.3, RF-03.3, RF-06.3,
 * Constitución Art. III.3 y Art. V).
 *
 * Valida la condición "Hecho cuando":
 * 1. La Coordinación publica comentarios públicos y notas internas de taller por
 *    `/api/coordinator/incidents/{id}/comments` (y su alias por código de ticket),
 *    persistiendo la clasificación elegida en `incident_comments`.
 * 2. Cada escritura deja constancia APPEND-ONLY e inmutable en `audit_log` con:
 *    acción `INCIDENT_COMMENT_ADDED`, tipo de entidad `TICKET`, identificador del
 *    coordinador autenticado, código del ticket y visibilidad correspondiente
 *    (Art. III.3).
 * 3. Las filas de auditoría ya escritas permanecen intactas tras publicaciones
 *    posteriores (una fila por mensaje, sin reescrituras).
 * 4. La evidencia fotográfica multipart queda registrada con `has_photo = true`.
 * 5. Un expediente sellado rechaza la publicación con HTTP 403 sin generar evento
 *    de auditoría alguno (Art. V.6 / RF-05.3).
 * 6. El hilo íntegro del coordinador incluye públicos y notas internas con identidad
 *    nominal real, mientras la Sede sobre el mismo expediente solo recibe los
 *    públicos (Art. V.4).
 * 7. Seguridad RBAC: anónimo (401), token de sede (401) y técnico (403).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y enrutador frontal del proyecto (sin
 * frameworks ni dependencias externas). El "HTTP" se ejerce despachando peticiones
 * reales por el mismo AppRouter que atiende al servidor web.
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
echo " VendGuard: Test de Integración - CoordinatorCommentsApiTest (T-COM-19)\n";
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

$storedUploadPath = '';

// ─── Escenario: expediente del parque bajo supervisión de coordinación ──────
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machine = $machineRepo->findByCode('VEND-0101');

$assert('0.1 Semillas localizadas (coordinador, técnico, sede y máquina)',
    $coordinator !== null && $technician !== null && $location !== null && $machine !== null);

if ($coordinator === null || $technician === null || $location === null || $machine === null) {
    echo "ERROR CRÍTICO: faltan semillas requeridas para la suite.\n";
    exit(1);
}

$coordinatorId = (int)$coordinator->getId();
$coordinatorName = $coordinator->getName();

// Liberar el aviso activo previo de la máquina (restricción uq_machine_active_ticket).
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-CRD-' . strtoupper(substr(uniqid(), -6)),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Expediente supervisado por coordinación para validar el hilo y su auditoría.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    createdAt: null
));

$incidentId = (int)$incident->getId();
$ticketCode = $incident->getTicketCode();

$assert('0.2 Expediente de coordinación creado', $incidentId > 0 && $ticketCode !== '');

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);
$siteToken = $authService->generateSiteToken($location);

$auditCount = static function () use ($pdo, $incidentId): int {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM `audit_log`
         WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'INCIDENT_COMMENT_ADDED'"
    )->fetchColumn();
};

$commentCount = static function () use ($pdo, $incidentId): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();
};

// =========================================================================
// CASO 1: Seguridad RBAC del canal de coordinación
// =========================================================================
echo "\n--- Caso 1: Seguridad RBAC del hilo de coordinación ---\n";

$resAnonGet = $router->dispatch(new Request('GET', "/api/coordinator/incidents/{$incidentId}/comments"));
$assert('1.1 GET anónimo del hilo => HTTP 401 UNAUTHORIZED',
    $resAnonGet->getStatusCode() === 401 && ($resAnonGet->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$resAnonPost = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Intento anónimo de publicar en el hilo.'],
    headers: []
));
$assert('1.2 POST anónimo => HTTP 401 UNAUTHORIZED',
    $resAnonPost->getStatusCode() === 401 && ($resAnonPost->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$resTech = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $techToken]
));
$assert('1.3 Técnico de ruta en el canal de coordinación => HTTP 403 FORBIDDEN',
    $resTech->getStatusCode() === 403 && ($resTech->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

$resSite = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$assert('1.4 Token de sede en el canal de coordinación => HTTP 401 UNAUTHORIZED',
    $resSite->getStatusCode() === 401,
    "status: {$resSite->getStatusCode()}");

$resMissing = $router->dispatch(new Request(
    method: 'GET',
    path: '/api/coordinator/incidents/99999999/comments',
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('1.5 Expediente inexistente => HTTP 404 INCIDENT_NOT_FOUND',
    $resMissing->getStatusCode() === 404 && ($resMissing->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// =========================================================================
// CASO 2: Lectura inicial del hilo (por ID y por código de ticket)
// =========================================================================
echo "\n--- Caso 2: Lectura inicial del hilo del expediente ---\n";

$resInitial = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$initialThread = $resInitial->getDecodedBody()['data'] ?? [];

$assert('2.1 El hilo inicial responde HTTP 200 con cabecera contextual del expediente',
    $resInitial->getStatusCode() === 200
        && (int)($initialThread['incident']['id'] ?? 0) === $incidentId
        && ($initialThread['incident']['ticket_code'] ?? '') === $ticketCode
        && ($initialThread['incident']['is_sealed'] ?? true) === false);

$assert('2.2 El hilo arranca vacío (0 mensajes, sin histórico previo)',
    count($initialThread['comments'] ?? []) === 0
        && (int)($initialThread['pagination']['total_comments'] ?? -1) === 0
        && ($initialThread['pagination']['has_more_before'] ?? true) === false);

$resByCode = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$ticketCode}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('2.3 El alias por código de ticket devuelve el mismo expediente',
    $resByCode->getStatusCode() === 200
        && (int)($resByCode->getDecodedBody()['data']['incident']['id'] ?? 0) === $incidentId);

// =========================================================================
// CASO 3: Publicación de comentarios públicos y notas internas (RF-03.3)
// =========================================================================
echo "\n--- Caso 3: Publicación pública y nota interna de coordinación ---\n";

$resPublic = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Coordinación informa a la sede: el repuesto llega mañana a primera hora.',
        'is_internal' => '0',
    ],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$publicBody = $resPublic->getDecodedBody();
$publicComments = is_array($publicBody['data']['comments'] ?? null) ? $publicBody['data']['comments'] : [];
$publicComment = end($publicComments) ?: [];
$publicCommentId = (int)($publicComment['id'] ?? 0);

$assert('3.1 La publicación pública responde HTTP 201 con su mensaje corporativo',
    $resPublic->getStatusCode() === 201
        && ($publicBody['message'] ?? '') === 'Comentario publicado en el hilo de conversación',
    "status: {$resPublic->getStatusCode()}");

$assert('3.2 El mensaje público llega desmarcado (is_internal === false)',
    array_key_exists('is_internal', $publicComment) && $publicComment['is_internal'] === false);

$assert('3.3 La autoría es el coordinador autenticado con su nombre nominal (RF-02.3)',
    ($publicComment['author_type'] ?? '') === 'COORDINATOR'
        && ($publicComment['author_name'] ?? '') === $coordinatorName
        && ($publicComment['is_own_message'] ?? false) === true);

$stmtPublic = $pdo->prepare("SELECT author_type, user_id, author_name, is_internal FROM `incident_comments` WHERE id = :id");
$stmtPublic->execute([':id' => $publicCommentId]);
$publicRow = $stmtPublic->fetch(PDO::FETCH_ASSOC) ?: [];

$assert('3.4 En BD la fila queda pública (is_internal = 0) vinculada al coordinador',
    (int)($publicRow['is_internal'] ?? -1) === 0
        && (int)($publicRow['user_id'] ?? 0) === $coordinatorId
        && ($publicRow['author_type'] ?? '') === 'COORDINATOR'
        && ($publicRow['author_name'] ?? '') === $coordinatorName,
    'fila real: ' . json_encode($publicRow));

$resInternal = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Nota de coordinación: negociar el coste del repuesto con el proveedor.',
        'is_internal' => '1',
    ],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$internalBody = $resInternal->getDecodedBody();
$internalComments = is_array($internalBody['data']['comments'] ?? null) ? $internalBody['data']['comments'] : [];
$internalComment = end($internalComments) ?: [];
$internalCommentId = (int)($internalComment['id'] ?? 0);

$assert('3.5 La nota interna responde HTTP 201 con su mensaje de taller',
    $resInternal->getStatusCode() === 201
        && ($internalBody['message'] ?? '') === 'Nota interna registrada en el hilo de conversación',
    "status: {$resInternal->getStatusCode()}");

$assert('3.6 La nota interna llega marcada con el candado is_internal === true',
    array_key_exists('is_internal', $internalComment) && $internalComment['is_internal'] === true);

$internalFlag = (int)$pdo->query("SELECT is_internal FROM `incident_comments` WHERE id = {$internalCommentId}")->fetchColumn();
$assert('3.7 En BD la nota queda con is_internal = 1',
    $internalFlag === 1);

$resFailSafe = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Sin selector de privacidad la nota debe ser interna.'],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$failSafeCommentId = (int)$pdo->query("SELECT id FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$failSafeFlag = (int)$pdo->query("SELECT is_internal FROM `incident_comments` WHERE id = {$failSafeCommentId}")->fetchColumn();

$assert('3.8 Fail-safe: sin campo is_internal la nota se clasifica interna (RF-03.3)',
    $resFailSafe->getStatusCode() === 201 && $failSafeFlag === 1,
    "status: {$resFailSafe->getStatusCode()}, is_internal: {$failSafeFlag}");

$auditBeforeInvalid = $auditCount();
$commentsBeforeInvalid = $commentCount();
$resInvalidFlag = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Indicador de privacidad no reconocible.', 'is_internal' => 'quizá'],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));

$assert('3.9 Un indicador is_internal no booleano => HTTP 422 INVALID_IS_INTERNAL',
    $resInvalidFlag->getStatusCode() === 422
        && ($resInvalidFlag->getDecodedBody()['error']['code'] ?? '') === 'INVALID_IS_INTERNAL');

$assert('3.10 La petición inválida no persiste mensaje ni genera auditoría',
    $commentCount() === $commentsBeforeInvalid && $auditCount() === $auditBeforeInvalid);

// =========================================================================
// CASO 4: Auditoría inmutable INCIDENT_COMMENT_ADDED (Art. III.3, RF-06.3)
// =========================================================================
echo "\n--- Caso 4: Eventos inmutables INCIDENT_COMMENT_ADDED en audit_log ---\n";

$auditStmt = $pdo->prepare("
    SELECT * FROM `audit_log`
    WHERE entity_type = 'TICKET' AND entity_id = :incident_id AND action = 'INCIDENT_COMMENT_ADDED'
    ORDER BY id ASC
");
$auditStmt->execute([':incident_id' => $incidentId]);
$auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$assert('4.1 Existe una fila de auditoría por cada uno de los 3 mensajes publicados',
    count($auditRows) === 3,
    'filas reales: ' . count($auditRows));

$firstAudit = $auditRows[0] ?? [];
$firstNewState = json_decode((string)($firstAudit['new_state'] ?? ''), true) ?: [];

/**
 * El servicio de aplicación registra el alta del mensaje en `new_state` (la
 * columna es NOT NULL) con el detalle contractual del mensaje; la identidad del
 * actor viaja en las columnas user_id/user_role/user_name del propio evento.
 */
$auditPayload = static function (array $row): array {
    $newState = json_decode((string)($row['new_state'] ?? ''), true) ?: [];
    $metadata = json_decode((string)($row['metadata'] ?? ''), true) ?: [];

    return array_merge($metadata, $newState);
};

$assert('4.2 El evento se ancla al expediente correcto y a la acción normativa',
    ($firstAudit['entity_type'] ?? '') === 'TICKET'
        && (int)($firstAudit['entity_id'] ?? 0) === $incidentId
        && ($firstAudit['action'] ?? '') === 'INCIDENT_COMMENT_ADDED');

$assert('4.3 El evento registra el identificador del coordinador autenticado',
    (int)($firstAudit['user_id'] ?? 0) === $coordinatorId
        && ($firstAudit['user_role'] ?? '') === 'COORDINATOR',
    'fila real: ' . json_encode($firstAudit));

$assert('4.4 El evento conserva el nombre nominal del coordinador como actor',
    ($firstAudit['user_name'] ?? '') === $coordinatorName);

$assert('4.5 El evento no arrastra estado previo (alta pura de mensaje)',
    ($firstAudit['previous_state'] ?? null) === null);

$assert('4.6 El código del ticket viaja íntegro en el detalle del evento',
    ($firstNewState['ticket_code'] ?? '') === $ticketCode);

$assert('4.7 La visibilidad pública queda registrada como is_internal = false',
    array_key_exists('is_internal', $firstNewState)
        && $firstNewState['is_internal'] === false
        && (int)($firstNewState['comment_id'] ?? 0) === $publicCommentId,
    'detalle real: ' . json_encode($firstNewState));

$internalAudit = null;
foreach ($auditRows as $auditRow) {
    if (($auditPayload($auditRow)['is_internal'] ?? null) === true) {
        $internalAudit = $auditRow;
        break;
    }
}
$internalPayload = $internalAudit !== null ? $auditPayload($internalAudit) : [];

$assert('4.8 La nota interna queda auditada con su visibilidad correspondiente (is_internal = true)',
    $internalAudit !== null
        && (int)($internalPayload['comment_id'] ?? 0) === $internalCommentId
        && ($internalPayload['ticket_code'] ?? '') === $ticketCode
        && ($internalPayload['has_photo'] ?? true) === false,
    'detalle real: ' . json_encode($internalPayload));

$auditedCommentIds = array_map(
    static fn(array $row): int => (int)($auditPayload($row)['comment_id'] ?? 0),
    $auditRows
);
$persistedCommentIds = array_map(
    static fn(array $row): int => (int)$row['id'],
    $pdo->query("SELECT id FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
);

sort($auditedCommentIds);
sort($persistedCommentIds);

$assert('4.9 Correspondencia 1:1 entre los mensajes persistidos y sus eventos de auditoría',
    $auditedCommentIds === $persistedCommentIds,
    'auditados: ' . json_encode($auditedCommentIds) . ' | persistidos: ' . json_encode($persistedCommentIds));

// =========================================================================
// CASO 5: Evidencia fotográfica del coordinador y su rastro de auditoría
// =========================================================================
echo "\n--- Caso 5: Evidencia fotográfica multipart y auditoría has_photo ---\n";

$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'comment_coord_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$resPhoto = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Adjunto el informe del proveedor como respaldo de la coordinación.',
        'is_internal' => '1',
    ],
    headers: ['Authorization' => 'Bearer ' . $coordToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'informe_proveedor.jpg',
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
$storedUploadPath = (string)($photoComment['photo_url'] ?? '');
$photoCommentId = (int)($photoComment['id'] ?? 0);

$assert('5.1 La nota con evidencia responde HTTP 201 y la foto se guarda en /uploads/',
    $resPhoto->getStatusCode() === 201
        && str_starts_with($storedUploadPath, '/uploads/')
        && str_ends_with($storedUploadPath, '.jpg'),
    "status: {$resPhoto->getStatusCode()}, photo_url: {$storedUploadPath}");

$assert('5.2 El archivo físico existe en public/uploads/',
    $storedUploadPath !== '' && is_file(dirname(__DIR__, 2) . '/public' . $storedUploadPath));

$photoRow = $pdo->prepare("SELECT photo_path, is_internal FROM `incident_comments` WHERE id = :id");
$photoRow->execute([':id' => $photoCommentId]);
$photoDbRow = $photoRow->fetch(PDO::FETCH_ASSOC) ?: [];

$assert('5.3 La BD persiste la ruta de la evidencia junto a la clasificación interna',
    ($photoDbRow['photo_path'] ?? '') === $storedUploadPath && (int)($photoDbRow['is_internal'] ?? -1) === 1);

$lastAuditStmt = $pdo->query("
    SELECT * FROM `audit_log`
    WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'INCIDENT_COMMENT_ADDED'
    ORDER BY id DESC
    LIMIT 1
");
$lastAudit = $lastAuditStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$lastPayload = $auditPayload($lastAudit);

$assert('5.4 El evento de la evidencia registra has_photo = true y su comentario asociado',
    ($lastPayload['has_photo'] ?? false) === true
        && (int)($lastPayload['comment_id'] ?? 0) === $photoCommentId
        && ($lastPayload['ticket_code'] ?? '') === $ticketCode,
    'detalle real: ' . json_encode($lastPayload));

$assert('5.5 Append-only: las 4 filas de auditoría permanecen tras la última publicación',
    $auditCount() === 4 && count($auditRows) === 3,
    'auditorías reales: ' . $auditCount());

$pristineFirstRow = $pdo->query("SELECT * FROM `audit_log` WHERE id = " . (int)($firstAudit['id'] ?? 0))->fetch(PDO::FETCH_ASSOC) ?: [];
$assert('5.6 Inmutabilidad: la fila más antigua conserva íntegros sus campos originales',
    $pristineFirstRow == $firstAudit,
    'antes: ' . json_encode($firstAudit) . ' | después: ' . json_encode($pristineFirstRow));

// =========================================================================
// CASO 6: Hilo íntegro del coordinador y segregación hacia la Sede
// =========================================================================
echo "\n--- Caso 6: Hilo íntegro del coordinador y segregación hacia la Sede ---\n";

$resThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$thread = $resThread->getDecodedBody()['data'] ?? [];
$threadComments = is_array($thread['comments'] ?? null) ? $thread['comments'] : [];
$threadInternal = array_values(array_filter($threadComments, static fn(array $c): bool => ($c['is_internal'] ?? false) === true));
$threadPublic = array_values(array_filter($threadComments, static fn(array $c): bool => ($c['is_internal'] ?? true) === false));

$assert('6.1 El coordinador inspecciona el diálogo completo: 4 mensajes (3 internos + 1 público)',
    $resThread->getStatusCode() === 200 && count($threadComments) === 4,
    'recibidos: ' . count($threadComments));

$assert('6.2 Las notas internas y el comentario público llegan con su marca de visibilidad',
    count($threadInternal) === 3 && count($threadPublic) === 1);

$assert('6.3 El recuento total del hilo incluye ambos canales (RF-01.1)',
    (int)($thread['pagination']['total_comments'] ?? -1) === 4
        && (int)($thread['pagination']['loaded_count'] ?? -1) === 4);

$resSiteThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$siteThread = $resSiteThread->getDecodedBody()['data'] ?? [];
$siteComments = is_array($siteThread['comments'] ?? null) ? $siteThread['comments'] : [];

$assert('6.4 La Sede solo recibe el comentario público del expediente',
    $resSiteThread->getStatusCode() === 200 && count($siteComments) === 1,
    'recibidos: ' . count($siteComments));

$assert('6.5 Cero fugas hacia la Sede: sin is_internal ni textos de taller en el payload (Art. V.4)',
    !str_contains($resSiteThread->getBody(), 'is_internal')
        && !str_contains($resSiteThread->getBody(), 'negociar el coste')
        && !str_contains($resSiteThread->getBody(), 'informe del proveedor'));

// =========================================================================
// CASO 7: Expediente sellado (Art. V.6 / RF-05.3)
// =========================================================================
echo "\n--- Caso 7: Sellado de auditoría en solo lectura ---\n";

$pdo->prepare("UPDATE `incidents` SET status = 'CLOSED', closed_at = NOW() WHERE id = :id")
    ->execute([':id' => $incidentId]);

$auditBeforeSealed = $auditCount();
$commentsBeforeSealed = $commentCount();

$resSealedGet = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('7.1 El hilo sellado se sigue consultando en solo lectura (is_sealed = true)',
    $resSealedGet->getStatusCode() === 200
        && ($resSealedGet->getDecodedBody()['data']['incident']['is_sealed'] ?? false) === true);

$resSealedPost = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/coordinator/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Intento de comentar en un expediente archivado.', 'is_internal' => '0'],
    headers: ['Authorization' => 'Bearer ' . $coordToken]
));

$assert('7.2 Publicar en un expediente sellado => HTTP 403 CONVERSATION_SEALED',
    $resSealedPost->getStatusCode() === 403
        && ($resSealedPost->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED',
    "status: {$resSealedPost->getStatusCode()}, code: " . ($resSealedPost->getDecodedBody()['error']['code'] ?? '—'));

$assert('7.3 El rechazo no deja mensaje ni evento de auditoría (Art. III)',
    $commentCount() === $commentsBeforeSealed && $auditCount() === $auditBeforeSealed);

// ─── Limpieza final (convención de la batería) ─────────────────────────────
if ($storedUploadPath !== '') {
    $storedFile = dirname(__DIR__, 2) . '/public' . $storedUploadPath;
    if (is_file($storedFile)) {
        unlink($storedFile);
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
    echo " RESULT: 100% IN GREEN. COORDINATOR COMMENTS API (T-COM-19) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
