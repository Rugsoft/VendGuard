<?php

declare(strict_types=1);

/**
 * VendGuard - LocationCommentsApiTest
 *
 * Test de integración HTTP contra MariaDB real del hilo de conversación visto desde
 * el Responsable de Sede (Módulo 10, T-COM-18 · RF-01, RF-02, RF-03, RF-04, RF-05,
 * RNF-01, Constitución Art. V.4 y Art. V.5).
 *
 * Valida la condición "Hecho cuando":
 * 1. Segregación a nivel de BASE DE DATOS: con filas `is_internal = 1` presentes en
 *    `incident_comments`, la respuesta HTTP de la Sede las omite por completo; el
 *    payload crudo no contiene el campo `is_internal`, ni el texto de las notas, ni
 *    el nombre real del técnico, ni su operador o teléfono (Art. V.4).
 * 2. Enmascaramiento oficial de la identidad técnica y de coordinación:
 *    "Servicio Técnico Oficial (Operador #XX)" y "Coordinación Central de Operaciones".
 * 3. El recuento publicado a la Sede solo contabiliza comentarios públicos.
 * 4. Subida multipart con fotografía válida (HTTP 201, archivo persistido en
 *    public/uploads/ y `is_internal = 0` en base de datos).
 * 5. Fotografía con cabecera binaria falsa => HTTP 422 sin residuos en disco (Art. V.5).
 * 6. Un intento de la Sede de fijar `is_internal = true` se ignora en servidor.
 * 7. Cierre, aislamiento de sedes y expediente inexistente (401 / 403 / 404).
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
echo " VendGuard: Test de Integración - LocationCommentsApiTest (T-COM-18)\n";
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
$countUploads = static fn(): int => count(glob($uploadsDir . '/*') ?: []);

// ─── Escenario: expediente de sede con comentarios mixtos sembrados en BD ────
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$otherLocation = $locationRepo->findBySiteCode('SEDE-BCN-02');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert('0.1 Semillas localizadas (sedes, técnico y coordinador)',
    $location !== null && $otherLocation !== null && $technician !== null && $coordinator !== null);

if ($location === null || $otherLocation === null || $technician === null || $coordinator === null) {
    echo "ERROR CRÍTICO: faltan semillas requeridas para la suite.\n";
    exit(1);
}

$machine = $machineRepo->findActiveByLocationId($location->getId())[0] ?? null;
$assert('0.2 Máquina activa de la sede localizada', $machine !== null);

if ($machine === null) {
    echo "ERROR CRÍTICO: la sede no tiene máquinas activas.\n";
    exit(1);
}

// Liberar el aviso activo previo de la máquina (restricción uq_machine_active_ticket).
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-LOC-' . strtoupper(substr(uniqid(), -6)),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Expediente para validar la segregación del hilo ante el Responsable de Sede.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    createdAt: null
));

$incidentId = (int)$incident->getId();
$ticketCode = $incident->getTicketCode();
$technicianId = (int)$technician->getId();
$coordinatorId = (int)$coordinator->getId();

$assert('0.3 Expediente de prueba creado en estado REGISTERED', $incidentId > 0 &&
    $incident->getStatus() === IncidentStatus::REGISTERED);

// Sembrado directo en BD: 5 filas con 2 notas internas confidenciales.
$insert = $pdo->prepare("
    INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `is_internal`, `created_at`)
    VALUES
        (:incident_id, :author_type, :user_id, :author_name, :comment_text, :is_internal, :created_at)
");

$commentsSeed = [
    // [author_type, user_id, author_name, texto, is_internal, created_at]
    ['REPORTER',    null,           'Conserjería Principal',   'El datáfono rechaza las tarjetas de banda magnética.', 0, '2026-10-05 08:10:00'],
    ['TECHNICIAN',  $technicianId,  $technician->getName(),    'Reviso el lector de tarjetas al llegar a la sede.',     0, '2026-10-05 09:00:00'],
    ['TECHNICIAN',  $technicianId,  $technician->getName(),    'NOTA-INTERNA: el fusible del datáfono está recalentado.', 1, '2026-10-05 09:30:00'],
    ['COORDINATOR', $coordinatorId, $coordinator->getName(),   'Coordinación autoriza la sustitución del datáfono.',    0, '2026-10-05 10:15:00'],
    ['COORDINATOR', $coordinatorId, $coordinator->getName(),   'NOTA-INTERNA: negociar descuento con el proveedor.',    1, '2026-10-05 10:45:00'],
];

$internalTexts = [];
foreach ($commentsSeed as [$authorType, $userId, $authorName, $text, $isInternal, $createdAt]) {
    $insert->execute([
        ':incident_id'  => $incidentId,
        ':author_type'  => $authorType,
        ':user_id'      => $userId,
        ':author_name'  => $authorName,
        ':comment_text' => $text,
        ':is_internal'  => $isInternal,
        ':created_at'   => $createdAt,
    ]);
    if ($isInternal === 1) {
        $internalTexts[] = $text;
    }
}

$siteToken = $authService->generateSiteToken($location);
$otherSiteToken = $authService->generateSiteToken($otherLocation);
$expectedTechnicianMask = sprintf('Servicio Técnico Oficial (Operador #%02d)', $technicianId);

// =========================================================================
// CASO 1: Segregación estricta en la respuesta HTTP de la Sede (Art. V.4)
// =========================================================================
echo "\n--- Caso 1: Segregación de notas internas en el payload de la Sede ---\n";

$reqComments = new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
);
$resComments = $router->dispatch($reqComments);
$rawBody = $resComments->getBody();
$bodyComments = $resComments->getDecodedBody();
$thread = $bodyComments['data'] ?? [];
$siteComments = is_array($thread['comments'] ?? null) ? $thread['comments'] : [];

$assert('1.1 GET del hilo de sede responde HTTP 200 OK', $resComments->getStatusCode() === 200,
    "status: {$resComments->getStatusCode()}");

$assert('1.2 La sede recibe únicamente los 3 comentarios públicos sembrados en BD',
    count($siteComments) === 3,
    'recibidos: ' . count($siteComments));

$assert('1.3 Orden cronológico ascendente respetado en la proyección pública',
    ($siteComments[0]['comment_text'] ?? '') === $commentsSeed[0][3]
        && ($siteComments[1]['comment_text'] ?? '') === $commentsSeed[1][3]
        && ($siteComments[2]['comment_text'] ?? '') === $commentsSeed[3][3]);

$leakedTexts = array_filter($internalTexts, static fn(string $t): bool => str_contains($rawBody, $t));
$assert('1.4 Cero fugas: ningún texto de nota interna viaja en el payload crudo',
    $leakedTexts === [],
    'fugas: ' . implode(' | ', $leakedTexts));

$assert('1.5 El payload crudo no contiene el campo is_internal ni metadato deducible',
    !str_contains($rawBody, 'is_internal'),
    'aparece is_internal en la respuesta');

$assert('1.6 Cada mensaje proyectado carece de la clave is_internal (ni valor nulo)',
    array_reduce($siteComments, static fn(bool $carry, array $c): bool =>
        $carry && !array_key_exists('is_internal', $c), true));

$assert('1.7 El nombre real del técnico no se filtra a la Sede',
    !str_contains($rawBody, $technician->getName())
        && !str_contains($rawBody, 'OP-01')
        && !str_contains($rawBody, (string)($technician->getPhone() ?? '677222333')));

$assert('1.8 Identidad técnica enmascarada con el patrón oficial (RF-02.2)',
    ($siteComments[1]['author_name'] ?? '') === $expectedTechnicianMask,
    "author_name real: " . ($siteComments[1]['author_name'] ?? '<vacío>'));

$assert('1.9 Identidad de coordinación enmascarada como "Coordinación Central de Operaciones"',
    ($siteComments[2]['author_name'] ?? '') === 'Coordinación Central de Operaciones',
    "author_name real: " . ($siteComments[2]['author_name'] ?? '<vacío>'));

$assert('1.10 El autor original de la Sede conserva su nombre nominal',
    ($siteComments[0]['author_name'] ?? '') === 'Conserjería Principal');

$assert('1.11 El recuento publicado contabiliza solo los comentarios públicos',
    (int)($thread['pagination']['total_comments'] ?? -1) === 3
        && (int)($thread['pagination']['loaded_count'] ?? -1) === 3,
    'paginación real: ' . json_encode($thread['pagination'] ?? null));

// =========================================================================
// CASO 2: Alias retrocompatible por código de ticket
// =========================================================================
echo "\n--- Caso 2: Alias /api/incidents/{ticket_code}/comments ---\n";

$resAlias = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/incidents/{$ticketCode}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$aliasBody = $resAlias->getDecodedBody();
$aliasComments = is_array($aliasBody['data']['comments'] ?? null) ? $aliasBody['data']['comments'] : [];

$assert('2.1 El alias por ticket_code responde HTTP 200 y conserva la segregación',
    $resAlias->getStatusCode() === 200
        && count($aliasComments) === 3
        && !str_contains($resAlias->getBody(), 'is_internal'));

// =========================================================================
// CASO 3: Publicación multipart con fotografía válida (RF-04.1, Art. V.5)
// =========================================================================
echo "\n--- Caso 3: Publicación multipart con fotografía válida ---\n";

$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'comment_site_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$uploadsBeforeValid = $countUploads();

$reqPhoto = new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Adjunto fotografía del datáfono con el lector bloqueado.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'datafono_bloqueado.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tempJpg,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempJpg),
        ],
    ]
);
$resPhoto = $router->dispatch($reqPhoto);
$photoBody = $resPhoto->getDecodedBody();
$photoComments = is_array($photoBody['data']['comments'] ?? null) ? $photoBody['data']['comments'] : [];
$newPhotoComment = end($photoComments) ?: [];
$storedPhotoPath = (string)($newPhotoComment['photo_url'] ?? '');

$assert('3.1 La publicación con foto responde HTTP 201 Created y success => true',
    $resPhoto->getStatusCode() === 201 && ($photoBody['success'] ?? false) === true,
    "status: {$resPhoto->getStatusCode()}");

$assert('3.2 La evidencia se almacena bajo /uploads/ con nombre hash',
    str_starts_with($storedPhotoPath, '/uploads/') && str_ends_with($storedPhotoPath, '.jpg'),
    "photo_url: {$storedPhotoPath}");

$assert('3.3 El archivo físico existe en disco (public/uploads/)',
    $storedPhotoPath !== '' && file_exists(dirname(__DIR__, 2) . '/public' . $storedPhotoPath)
        && $countUploads() === $uploadsBeforeValid + 1);

$assert('3.4 La autoría de la Sede es corporativa con el nombre de la sede (RF-02.1)',
    ($newPhotoComment['author_name'] ?? '') === 'Responsable de Sede · ' . $location->getName(),
    "author_name real: " . ($newPhotoComment['author_name'] ?? '<vacío>'));

$dbPhotoRow = $pdo->prepare("
    SELECT author_type, is_internal, photo_path
    FROM `incident_comments`
    WHERE incident_id = :incident_id
    ORDER BY id DESC
    LIMIT 1
");
$dbPhotoRow->execute([':incident_id' => $incidentId]);
$dbPhoto = $dbPhotoRow->fetch(PDO::FETCH_ASSOC) ?: [];

$assert('3.5 En BD el comentario queda público (is_internal = 0) con su ruta de foto',
    (int)($dbPhoto['is_internal'] ?? -1) === 0
        && ($dbPhoto['author_type'] ?? '') === 'REPORTER'
        && ($dbPhoto['photo_path'] ?? '') === $storedPhotoPath,
    'fila real: ' . json_encode($dbPhoto));

// =========================================================================
// CASO 4: La Sede no puede forzar una nota interna (fail-safe de servidor)
// =========================================================================
echo "\n--- Caso 4: Intento de forzar is_internal desde la Sede ---\n";

$resForce = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Intento de clasificar este mensaje como nota interna.',
        'is_internal' => 'true',
    ],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$forcedRow = $pdo->query("SELECT is_internal FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetchColumn();

$assert('4.1 La marca is_internal enviada por la Sede se ignora y se persiste como público',
    $resForce->getStatusCode() === 201 && (int)$forcedRow === 0,
    "status: {$resForce->getStatusCode()}, is_internal persistido: " . var_export($forcedRow, true));

$assert('4.2 La respuesta del alta tampoco expone el campo is_internal a la Sede',
    !str_contains($resForce->getBody(), 'is_internal'));

// =========================================================================
// CASO 5: Fotografía con cabecera binaria falsa (Art. V.5)
// =========================================================================
echo "\n--- Caso 5: Cabecera binaria falsa rechazada sin residuos ---\n";

$fakeImage = tempnam(sys_get_temp_dir(), 'fake_img_') . '.jpg';
file_put_contents($fakeImage, '<?php echo "no soy una imagen"; ?>');

$uploadsBeforeFake = $countUploads();
$resFake = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Texto que debe sobrevivir al rechazo del archivo.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'falso_maravilloso.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $fakeImage,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fakeImage),
        ],
    ]
));
$fakeBody = $resFake->getDecodedBody();

$assert('5.1 Archivo con magic bytes falsos => HTTP 422 INVALID_FILE_TYPE',
    $resFake->getStatusCode() === 422 && ($fakeBody['error']['code'] ?? '') === 'INVALID_FILE_TYPE',
    "status: {$resFake->getStatusCode()}, code: " . ($fakeBody['error']['code'] ?? '—'));

$assert('5.2 El rechazo no deja residuos en public/uploads/',
    $countUploads() === $uploadsBeforeFake,
    "antes: {$uploadsBeforeFake}, después: " . $countUploads());

$assert('5.3 El texto redactado se devuelve íntegro para el reintento (RF-07.1)',
    ($fakeBody['error']['details']['form_data']['comment_text'] ?? '') === 'Texto que debe sobrevivir al rechazo del archivo.');

// =========================================================================
// CASO 6: Seguridad y aislamiento (401 / 403 / 404)
// =========================================================================
echo "\n--- Caso 6: Seguridad, aislamiento de sedes y expediente inexistente ---\n";

$resAnon = $router->dispatch(new Request('GET', "/api/location/incidents/{$incidentId}/comments"));
$assert('6.1 Acceso anónimo al hilo de sede => HTTP 401 UNAUTHORIZED',
    $resAnon->getStatusCode() === 401 && ($resAnon->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$resOtherSite = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $otherSiteToken]
));
$assert('6.2 Otra sede sobre el expediente ajeno => HTTP 403 SITE_MISMATCH',
    $resOtherSite->getStatusCode() === 403 && ($resOtherSite->getDecodedBody()['error']['code'] ?? '') === 'SITE_MISMATCH');

$resMissing = $router->dispatch(new Request(
    method: 'GET',
    path: '/api/location/incidents/99999999/comments',
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$assert('6.3 Expediente inexistente => HTTP 404 INCIDENT_NOT_FOUND',
    $resMissing->getStatusCode() === 404 && ($resMissing->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// =========================================================================
// CASO 7: Estado final del expediente en base de datos
// =========================================================================
echo "\n--- Caso 7: Trazabilidad del hilo en base de datos ---\n";

$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();
$internalRows = (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId} AND is_internal = 1")->fetchColumn();
$latestThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
))->getDecodedBody();

$assert('7.1 La BD conserva las 2 notas internas pese a ser invisibles para la Sede',
    $totalRows === 7 && $internalRows === 2,
    "total: {$totalRows}, internas: {$internalRows}");

$assert('7.2 El hilo de sede muestra los 5 públicos publicados (3 sembrados + 2 por API)',
    count($latestThread['data']['comments'] ?? []) === 5
        && (int)($latestThread['data']['pagination']['total_comments'] ?? -1) === 5,
    'recuento real: ' . count($latestThread['data']['comments'] ?? []));

// ─── Limpieza final (convención de la batería) ─────────────────────────────
if ($storedPhotoPath !== '') {
    $storedFile = dirname(__DIR__, 2) . '/public' . $storedPhotoPath;
    if (is_file($storedFile)) {
        unlink($storedFile);
    }
}
foreach ([$tempJpg, $fakeImage] as $tempFile) {
    if (is_file($tempFile)) {
        unlink($tempFile);
    }
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
    echo " RESULT: 100% IN GREEN. LOCATION COMMENTS API (T-COM-18) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
