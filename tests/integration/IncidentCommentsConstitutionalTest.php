<?php

declare(strict_types=1);

/**
 * VendGuard - IncidentCommentsConstitutionalTest
 *
 * Prueba de blindaje constitucional del Módulo 10 (hilo de comentarios) contra
 * MariaDB real y contra el propio código fuente (T-COM-20).
 *
 * Certifica las tres barreras supremas exigidas por el "Hecho cuando":
 *
 * 1. ARTÍCULO III — PROHIBICIÓN DE MUTACIÓN (append-only):
 *    ni la interfaz de repositorio, ni el repositorio PDO, ni el servicio de
 *    aplicación, ni la entidad de dominio, ni el componente de interfaz ofrecen
 *    método alguno de borrado o edición de comentarios; tampoco existe ninguna
 *    sentencia `DELETE`/`UPDATE` contra la tabla `incident_comments`. La traza de
 *    auditoría (`audit_log`) solo contiene inserción y consulta.
 *
 * 2. ARTÍCULO V.4 — SEGREGACIÓN ESTRICTA DE DATOS PRIVADOS:
 *    con notas internas y datos personales de técnicos presentes en base de datos,
 *    ninguna respuesta dirigida al cliente (Responsable de Sede) contiene el campo
 *    `is_internal`, el texto o la evidencia de una nota interna, el nombre real del
 *    técnico, su código de operador ni su número de teléfono personal. El intento de
 *    la Sede de forzar una nota interna tampoco muta su payload.
 *
 * 3. ARTÍCULO V.5 — VALIDACIÓN BINARIA DE EVIDENCIAS:
 *    archivos con cabecera binaria falsa, archivos vacíos y archivos de más de 5 MB
 *    se rechazan con HTTP 422 sin dejar un solo residuo en `public/uploads/` ni fila
 *    en `incident_comments`, mientras una evidencia legítima sí se acepta (control).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y enrutador frontal del proyecto.
 * Dualismo Lingüístico: código e identificadores en inglés, mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Constitucional - Hilo de Comentarios (T-COM-20)\n";
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

$projectRoot = dirname(__DIR__, 2);
$uploadsDir = $projectRoot . '/public/uploads';
$countUploads = static fn(): int => count(glob($uploadsDir . '/*') ?: []);

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

// =========================================================================
// BLOQUE 1: Artículo III - El hilo es append-only (cero métodos de mutación)
// =========================================================================
echo "--- Bloque 1: Art. III - Prohibición de mutación (append-only) ---\n";

$expectedCommentMethods = ['addComment', 'countComments', 'getComments', 'getCommentsPaged'];

$collectCommentMethods = static function (string $className) : array {
    $methods = array_map(
        static fn(ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC)
    );
    $commentMethods = array_values(array_filter(
        $methods,
        static fn(string $name): bool => stripos($name, 'comment') !== false
    ));
    sort($commentMethods);

    return $commentMethods;
};

$interfaceCommentMethods = $collectCommentMethods(IncidentRepositoryInterface::class);
$assert('1.1 La interfaz de repositorio solo expone operaciones de alta y consulta del hilo',
    $interfaceCommentMethods === $expectedCommentMethods,
    'métodos reales: ' . json_encode($interfaceCommentMethods));

$pdoCommentMethods = $collectCommentMethods(PdoIncidentRepository::class);
$assert('1.2 El repositorio PDO tampoco ofrece borrado ni edición de comentarios',
    $pdoCommentMethods === $expectedCommentMethods,
    'métodos reales: ' . json_encode($pdoCommentMethods));

$auditMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(PdoAuditLogRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($auditMethods);
$assert('1.3 La traza de auditoría solo permite insertar y consultar (Art. III.2)',
    $auditMethods === ['__construct', 'countEvents', 'findByEntity', 'findEvents', 'log'],
    'métodos reales: ' . json_encode($auditMethods));

// Barrido del código fuente del módulo: ni métodos de mutación ni SQL destructivo.
$moduleSources = [
    'src/Core/Domain/Repository/IncidentRepositoryInterface.php',
    'src/Infrastructure/Repository/PdoIncidentRepository.php',
    'src/Application/Service/IncidentCommentService.php',
    'src/Core/Domain/Model/IncidentComment.php',
    'src/Presentation/Controller/LocationPortalController.php',
    'src/Presentation/Controller/TechnicianController.php',
    'src/Presentation/Controller/CoordinatorController.php',
];

$mutationHits = [];
$destructiveSqlHits = [];
foreach ($moduleSources as $relativePath) {
    $source = (string)file_get_contents($projectRoot . '/' . $relativePath);

    if (preg_match('/\b(deleteComment|updateComment|editComment|removeComment)\s*\(/i', $source, $match) === 1) {
        $mutationHits[] = $relativePath . ':' . $match[1];
    }
    if (preg_match('/(DELETE\s+FROM|UPDATE)\s+`?incident_comments`?/i', $source, $match) === 1) {
        $destructiveSqlHits[] = $relativePath . ':' . trim($match[0]);
    }
}

$assert('1.4 Cero métodos de borrado o edición de comentarios en backend (Art. III)',
    $mutationHits === [],
    'hallazgos: ' . implode(' | ', $mutationHits));

$assert('1.5 Cero sentencias DELETE/UPDATE contra incident_comments en el código',
    $destructiveSqlHits === [],
    'hallazgos: ' . implode(' | ', $destructiveSqlHits));

$frontendSource = (string)file_get_contents($projectRoot . '/public/assets/js/components/IncidentCommentThreadModal.js');
$assert('1.6 La interfaz del hilo tampoco ofrece acciones de borrado o edición del mensaje',
    preg_match('/deleteComment|updateComment|editComment|delete-comment|edit-comment/i', $frontendSource) !== 1);

$commentModelSource = (string)file_get_contents($projectRoot . '/src/Core/Domain/Model/IncidentComment.php');
$assert('1.7 La entidad de dominio del mensaje es inmutable (sin setters y ArrayAccess de solo lectura)',
    preg_match('/function\s+set[A-Z]\w*\s*\(/', $commentModelSource) !== 1
        && substr_count($commentModelSource, '// Inmutable') === 2,
    'El modelo no debe exponer setters y sus escrituras ArrayAccess deben ser no-ops.');

// =========================================================================
// BLOQUE 2: Artículo V.4 - Cero fugas de notas internas y datos privados
// =========================================================================
echo "\n--- Bloque 2: Art. V.4 - Segregación estricta ante el cliente ---\n";

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$machine = $machineRepo->findByCode('VEND-0102');

$assert('2.1 Semillas localizadas para el escenario constitucional',
    $location !== null && $technician !== null && $coordinator !== null && $machine !== null);

if ($location === null || $technician === null || $coordinator === null || $machine === null) {
    echo "ERROR CRÍTICO: faltan semillas requeridas para la suite.\n";
    exit(1);
}

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-CONST-' . strtoupper(substr(uniqid(), -6)),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::OTHER,
    description: 'Expediente del blindaje constitucional del hilo de comentarios.',
    urgency: UrgencyLevel::LOW,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: (int)$technician->getId(),
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    assignedAt: date('Y-m-d H:i:s'),
    createdAt: null
));
$incidentId = (int)$incident->getId();
$ticketCode = $incident->getTicketCode();
$technicianName = $technician->getName();

// Datos privados que jamás deben cruzar hacia el cliente (Art. V.4).
$technicianPhones = $pdo->query("SELECT phone FROM `users` WHERE role = 'TECHNICIAN' AND phone IS NOT NULL")
    ->fetchAll(PDO::FETCH_COLUMN) ?: [];
$siteToken = $authService->generateSiteToken($location);

// Sembrado en BD: 2 públicos y 2 notas internas con evidencia confidencial.
$internalTexts = [
    'NOTA-CONFIDENCIAL: el técnico sustituyó el fusible sin repuesto oficial.',
    'NOTA-CONFIDENCIAL: reclamar al proveedor el coste del datáfono.',
];
$internalPhotoPath = '/uploads/nota_interna_confidencial.jpg';

$insert = $pdo->prepare("
    INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `photo_path`, `is_internal`, `created_at`)
    VALUES
        (:incident_id, :author_type, :user_id, :author_name, :comment_text, :photo_path, :is_internal, :created_at)
");
$seedRows = [
    ['REPORTER',   null,                    'Conserjería Principal', 'El datáfono no acepta el pago sin contacto.', null, 0, '2026-10-05 08:00:00'],
    ['TECHNICIAN', (int)$technician->getId(), $technicianName,        'Reviso el lector con el equipo de taller.',   null, 0, '2026-10-05 09:00:00'],
    ['TECHNICIAN', (int)$technician->getId(), $technicianName,        $internalTexts[0],                            null, 1, '2026-10-05 09:30:00'],
    ['COORDINATOR', (int)$coordinator->getId(), $coordinator->getName(), $internalTexts[1],                         $internalPhotoPath, 1, '2026-10-05 10:00:00'],
];
foreach ($seedRows as [$authorType, $userId, $authorName, $text, $photoPath, $isInternal, $createdAt]) {
    $insert->execute([
        ':incident_id'  => $incidentId,
        ':author_type'  => $authorType,
        ':user_id'      => $userId,
        ':author_name'  => $authorName,
        ':comment_text' => $text,
        ':photo_path'   => $photoPath,
        ':is_internal'  => $isInternal,
        ':created_at'   => $createdAt,
    ]);
}

$resSiteThread = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$siteRawBody = $resSiteThread->getBody();
$siteThread = $resSiteThread->getDecodedBody()['data'] ?? [];
$siteComments = is_array($siteThread['comments'] ?? null) ? $siteThread['comments'] : [];

$assert('2.2 El cliente recibe HTTP 200 con solo los 2 mensajes públicos',
    $resSiteThread->getStatusCode() === 200 && count($siteComments) === 2,
    'status: ' . $resSiteThread->getStatusCode() . ', recibidos: ' . count($siteComments));

$leakedInternalTexts = array_values(array_filter(
    $internalTexts,
    static fn(string $text): bool => str_contains($siteRawBody, $text)
));
$assert('2.3 Cero fugas: ningún texto de nota interna en el payload del cliente',
    $leakedInternalTexts === [],
    'fugas: ' . implode(' | ', $leakedInternalTexts));

$assert('2.4 Cero fugas: la evidencia de una nota interna jamás se publica',
    !str_contains($siteRawBody, $internalPhotoPath) && !str_contains($siteRawBody, 'nota_interna_confidencial'));

$assert('2.5 El campo is_internal no existe en el payload ni con valor nulo',
    !str_contains($siteRawBody, 'is_internal')
        && array_reduce($siteComments, static fn(bool $carry, array $c): bool =>
            $carry && !array_key_exists('is_internal', $c), true));

$assert('2.6 El payload del cliente no arrastra identificadores internos de usuario',
    !str_contains($siteRawBody, 'user_id') && !str_contains($siteRawBody, 'author_id'));

$assert('2.7 El nombre real del técnico y su código de operador quedan enmascarados',
    !str_contains($siteRawBody, $technicianName) && !str_contains($siteRawBody, 'OP-01'));

$leakedPhones = array_values(array_filter(
    $technicianPhones,
    static fn(string $phone): bool => $phone !== '' && str_contains($siteRawBody, $phone)
));
$assert('2.8 Cero fugas: ningún teléfono personal de técnico en la respuesta al cliente',
    $leakedPhones === [],
    'fugas: ' . implode(' | ', $leakedPhones));

$assert('2.9 El recuento publicado no contabiliza las notas internas (ni cifra deducible)',
    (int)($siteThread['pagination']['total_comments'] ?? -1) === 2
        && (int)($siteThread['pagination']['loaded_count'] ?? -1) === 2,
    'paginación real: ' . json_encode($siteThread['pagination'] ?? null));

// El intento de la Sede de forzar una nota interna tampoco altera su proyección.
$resSiteForced = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Intento de publicar contenido clasificado desde el cliente.',
        'is_internal' => 'true',
    ],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
));
$forcedComments = is_array($resSiteForced->getDecodedBody()['data']['comments'] ?? null)
    ? $resSiteForced->getDecodedBody()['data']['comments']
    : [];

$assert('2.10 La marca is_internal enviada por el cliente se ignora y no contamina la respuesta',
    $resSiteForced->getStatusCode() === 201
        && !str_contains($resSiteForced->getBody(), 'is_internal')
        && count($forcedComments) === 3,
    "status: {$resSiteForced->getStatusCode()}, mensajes: " . count($forcedComments));

$forcedFlag = (int)$pdo->query("SELECT is_internal FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert('2.11 El mensaje forzado por el cliente se persiste como público (is_internal = 0)',
    $forcedFlag === 0);

// =========================================================================
// BLOQUE 3: Artículo V.5 - Validación binaria estricta de evidencias
// =========================================================================
echo "\n--- Bloque 3: Art. V.5 - Validación binaria y 5 MB sin residuos ---\n";

$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');

$tempFiles = [];
$tempFile = static function (string $suffix, string $contents) use (&$tempFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'constitutional_') . $suffix;
    file_put_contents($path, $contents);
    $tempFiles[] = $path;

    return $path;
};

$commentCountBefore = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();

$fakeMagicFile = $tempFile('.jpg', '<?php echo "cabecera binaria falsa"; ?>');
$uploadsBeforeFake = $countUploads();
$commentsBeforeFake = $commentCountBefore();

$resFake = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia con cabecera binaria falsa.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'evidencia_falsa.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $fakeMagicFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fakeMagicFile),
        ],
    ]
));

$assert('3.1 Cabecera binaria falsa => HTTP 422 INVALID_FILE_TYPE',
    $resFake->getStatusCode() === 422
        && ($resFake->getDecodedBody()['error']['code'] ?? '') === 'INVALID_FILE_TYPE',
    "status: {$resFake->getStatusCode()}, code: " . ($resFake->getDecodedBody()['error']['code'] ?? '—'));

$assert('3.2 El rechazo no deja residuos en public/uploads/',
    $countUploads() === $uploadsBeforeFake,
    "antes: {$uploadsBeforeFake}, después: " . $countUploads());

$assert('3.3 El rechazo no persiste comentario alguno',
    $commentCountBefore() === $commentsBeforeFake);

$emptyFile = $tempFile('.jpg', '');
$uploadsBeforeEmpty = $countUploads();

$resEmpty = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia de archivo vacío.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'vacia.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $emptyFile,
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ],
    ]
));

$assert('3.4 Archivo vacío => HTTP 422 EMPTY_FILE sin residuos',
    $resEmpty->getStatusCode() === 422
        && ($resEmpty->getDecodedBody()['error']['code'] ?? '') === 'EMPTY_FILE'
        && $countUploads() === $uploadsBeforeEmpty,
    "status: {$resEmpty->getStatusCode()}, code: " . ($resEmpty->getDecodedBody()['error']['code'] ?? '—'));

// Archivo > 5 MB con cabecera JPEG legítima: el límite manda sobre el contenido.
$oversizedFile = $tempFile('.jpg', $validJpgBytes . str_repeat("\0", (5 * 1024 * 1024) + 64));
$uploadsBeforeOversized = $countUploads();
$commentsBeforeOversized = $commentCountBefore();

$resOversized = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia que supera el límite de 5 MB.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'demasiado_grande.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $oversizedFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($oversizedFile),
        ],
    ]
));

$assert('3.5 Archivo > 5 MB => HTTP 422 FILE_TOO_LARGE',
    $resOversized->getStatusCode() === 422
        && ($resOversized->getDecodedBody()['error']['code'] ?? '') === 'FILE_TOO_LARGE'
        && filesize($oversizedFile) > 5 * 1024 * 1024,
    "status: {$resOversized->getStatusCode()}, code: " . ($resOversized->getDecodedBody()['error']['code'] ?? '—'));

$assert('3.6 El exceso de tamaño no deja residuos ni comentario persistido',
    $countUploads() === $uploadsBeforeOversized && $commentCountBefore() === $commentsBeforeOversized,
    "uploads antes: {$uploadsBeforeOversized}, después: " . $countUploads());

$assert('3.7 Los rechazos devuelven el texto íntegro para el reintento (RF-07.1)',
    ($resFake->getDecodedBody()['error']['details']['form_data']['comment_text'] ?? '') === 'Evidencia con cabecera binaria falsa.'
        && ($resOversized->getDecodedBody()['error']['details']['form_data']['comment_text'] ?? '') === 'Evidencia que supera el límite de 5 MB.');

// Control positivo: una evidencia legítima sí se acepta y se almacena.
$validFile = $tempFile('.jpg', $validJpgBytes);
$resValid = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia legítima de control del blindaje.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'evidencia_legitima.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $validFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($validFile),
        ],
    ]
));
$validComments = is_array($resValid->getDecodedBody()['data']['comments'] ?? null)
    ? $resValid->getDecodedBody()['data']['comments']
    : [];
$validPhotoPath = (string)((end($validComments) ?: [])['photo_url'] ?? '');

$assert('3.8 Control: una evidencia JPEG legítima se acepta con HTTP 201',
    $resValid->getStatusCode() === 201 && str_starts_with($validPhotoPath, '/uploads/'),
    "status: {$resValid->getStatusCode()}, photo_url: {$validPhotoPath}");

$assert('3.9 Control: el archivo legítimo queda almacenado en public/uploads/',
    $validPhotoPath !== '' && is_file($projectRoot . '/public' . $validPhotoPath)
        && $countUploads() === $uploadsBeforeOversized + 1);

// =========================================================================
// BLOQUE 4: Trazabilidad de auditoría del propio blindaje (Art. III.3)
// =========================================================================
echo "\n--- Bloque 4: Trazabilidad append-only del módulo ---\n";

$persistedComments = (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();
$auditRows = $pdo->query(
    "SELECT new_state FROM `audit_log`
     WHERE entity_type = 'TICKET' AND entity_id = {$incidentId} AND action = 'INCIDENT_COMMENT_ADDED'
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$auditedCommentIds = [];
foreach ($auditRows as $auditPayloadJson) {
    $decodedPayload = json_decode((string)$auditPayloadJson, true) ?: [];
    $auditedCommentIds[] = (int)($decodedPayload['comment_id'] ?? 0);
}
sort($auditedCommentIds);

$apiCommentIds = array_map('intval', $pdo->query(
    "SELECT id FROM `incident_comments` WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN));
sort($apiCommentIds);

$assert('4.1 El hilo conserva los 4 mensajes sembrados y los 2 publicados por API',
    $persistedComments === 6,
    "comentarios persistidos: {$persistedComments}");

$assert('4.2 Las 3 evidencias rechazadas no generaron comentario ni evento de auditoría',
    count($auditRows) === 2 && count($apiCommentIds) === 2,
    'eventos reales: ' . count($auditRows));

$assert('4.3 Trazabilidad 1:1 append-only: cada mensaje publicado por API tiene su evento',
    $auditedCommentIds === $apiCommentIds,
    'auditados: ' . json_encode($auditedCommentIds) . ' | publicados: ' . json_encode($apiCommentIds));

// ─── Limpieza final (convención de la batería) ─────────────────────────────
if ($validPhotoPath !== '') {
    $storedFile = $projectRoot . '/public' . $validPhotoPath;
    if (is_file($storedFile)) {
        unlink($storedFile);
    }
}
foreach ($tempFiles as $tempFilePath) {
    if (is_file($tempFilePath)) {
        unlink($tempFilePath);
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
    echo " RESULT: 100% IN GREEN. CONSTITUTIONAL SHIELD FOR INCIDENT COMMENTS (T-COM-20) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
