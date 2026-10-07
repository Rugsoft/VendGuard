<?php

declare(strict_types=1);

/**
 * VendGuard - IncidentCommentsPerformanceAndSecurityTest
 *
 * Suite permanente de rendimiento y seguridad del Módulo 10 (hilo de comentarios).
 * Nace del informe de auditoría del módulo para cerrar sus dos huecos de cobertura:
 *
 * - **H-1 · RNF-02 (carga < 250 ms):** ninguna suite medía la latencia del hilo. Aquí se
 *   siembra un expediente con 1.000 mensajes (volumen exigido por la especificación) y se
 *   mide el endpoint GET del hilo y la consulta cursorizada del repositorio contra MariaDB
 *   real. Se añade una segunda medición con 10.000 mensajes para demostrar el escalado y
 *   para fijar el plan de ejecución indexado como trampa de regresión.
 *
 * - **H-3 · RNF-05 (protección del directorio de subidas):** ninguna suite verificaba la
 *   protección de `public/uploads/`. Aquí se certifican tres capas complementarias:
 *   1) el artefacto `.htaccess` (denegación de extensiones ejecutables + `Options -ExecCGI`)
 *      que protege los despliegues servidos por Apache —el servidor embebido de PHP no lee
 *      `.htaccess`, por lo que la siguiente capa es la realmente crítica en el contenedor—;
 *   2) la invariante de código: el nombre lo impone el servidor (32 hex + extensión derivada
 *      del MIME real con `finfo`), de modo que la suplantación de nombre por parte del
 *      cliente (`exploit.php`) no produce jamás un archivo ejecutable;
 *   3) la invariante de disco: tras el flujo completo no existe ningún archivo con extensión
 *      ejecutable en el directorio de subidas, y los nombres hash nunca se sobreescriben.
 *
 * Metodología de las mediciones (anti-flake):
 * - Una ejecución de calentamiento previa (no muestreada) absorbe el primer coste de
 *   conexión y de cachés del motor.
 * - La aserción normativa se hace sobre la **mediana** de la muestra (N=7 y N=5), con un
 *   guardián adicional sobre el peor caso (< 3× el presupuesto) para detectar outliers
 *   patológicos sin volver frágil la batería en máquinas cargadas.
 * - Todos los valores reales se imprimen para dejar evidencia en el log de la suite.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y el mismo AppRouter del servidor web.
 * Dualismo Lingüístico: identificadores en inglés, mensajes y documentación en castellano.
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
echo " VendGuard: Rendimiento y Seguridad - Hilo de Comentarios (H-1 / H-3)\n";
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
$uploadsAtStart = $countUploads();
$createdUploads = [];
$tempFiles = [];

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

const PERFORMANCE_BUDGET_MS = 250.0;   // RNF-02: apertura y renderizado de 50 mensajes
const OUTLIER_CEILING_MS = 750.0;      // Guardián anti-outlier (3× el presupuesto)

/**
 * Ejecuta una medición con calentamiento previo y devuelve las muestras ordenadas.
 *
 * @param callable():void $operation Operación a cronometrar (petición HTTP o consulta).
 * @param int $samples Número de muestras efectivas.
 * @return array{min: float, median: float, max: float, samples: list<float>}
 */
$benchmark = static function (callable $operation, int $samples) : array {
    $operation(); // Calentamiento: no se muestrea.

    $measured = [];
    for ($i = 0; $i < $samples; $i++) {
        $startedAt = microtime(true);
        $operation();
        $measured[] = (microtime(true) - $startedAt) * 1000;
    }
    sort($measured);

    return [
        'min' => $measured[0],
        'median' => $measured[intdiv(count($measured), 2)],
        'max' => $measured[count($measured) - 1],
        'samples' => $measured,
    ];
};

$formatBenchmark = static fn(array $result): string => sprintf(
    'min %.2f ms | mediana %.2f ms | max %.2f ms | muestras [%s]',
    $result['min'],
    $result['median'],
    $result['max'],
    implode(', ', array_map(static fn(float $ms): string => number_format($ms, 2), $result['samples']))
);

// ─── Escenario: expediente con carga masiva de mensajes públicos ────────────
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machine = $machineRepo->findByCode('VEND-0101');

$assert('0.1 Semillas localizadas (sede y máquina de la prueba de carga)',
    $location !== null && $machine !== null);

if ($location === null || $machine === null) {
    echo "ERROR CRÍTICO: faltan semillas requeridas para la suite.\n";
    exit(1);
}

// Liberar el aviso activo previo de la máquina (restricción uq_machine_active_ticket).
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-PERF-' . strtoupper(substr(uniqid(), -6)),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::OTHER,
    description: 'Expediente de carga para certificar el rendimiento del hilo de comentarios.',
    urgency: UrgencyLevel::LOW,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    createdAt: null
));
$incidentId = (int)$incident->getId();
$siteToken = $authService->generateSiteToken($location);

$assert('0.2 Expediente de carga creado', $incidentId > 0);

$insertComment = $pdo->prepare("
    INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `is_internal`, `created_at`)
    VALUES (:incident_id, 'REPORTER', NULL, 'Conserjería Principal', :comment_text, 0, :created_at)
");

/**
 * Siembra un bloque de mensajes públicos con marcas temporales crecientes y únicas.
 */
$seedComments = function (int $from, int $to) use ($pdo, $insertComment, $incidentId): void {
    $pdo->beginTransaction();
    for ($index = $from; $index <= $to; $index++) {
        $insertComment->execute([
            ':incident_id' => $incidentId,
            ':comment_text' => 'Mensaje de carga número ' . str_pad((string)$index, 5, '0', STR_PAD_LEFT) . ' del expediente de rendimiento.',
            ':created_at' => date('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00') + $index),
        ]);
    }
    $pdo->commit();
};

$threadRequest = static fn(): Request => new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments?limit=50",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
);

$cursorRequest = static fn(): Request => new Request(
    method: 'GET',
    path: "/api/location/incidents/{$incidentId}/comments?limit=50&before_id=500",
    queryParams: [],
    parsedBody: [],
    headers: ['Authorization' => 'Bearer ' . $siteToken]
);

// =========================================================================
// BLOQUE 1 (H-1): RNF-02 — latencia del hilo con 1.000 mensajes
// =========================================================================
echo "\n--- Bloque 1 (H-1): RNF-02 con 1.000 mensajes en el expediente ---\n";

$seedComments(1, 1000);

$threadResponse = $router->dispatch($threadRequest());
$threadBody = $threadResponse->getDecodedBody();
$threadComments = is_array($threadBody['data']['comments'] ?? null) ? $threadBody['data']['comments'] : [];

$assert('1.1 El endpoint responde HTTP 200 sirviendo exactamente el bloque de 50 mensajes',
    $threadResponse->getStatusCode() === 200 && count($threadComments) === 50,
    "status: {$threadResponse->getStatusCode()}, mensajes: " . count($threadComments));

$assert('1.2 El bloque llega en orden cronológico ascendente y con histórico previo pendiente',
    ($threadComments[0]['comment_text'] ?? '') === 'Mensaje de carga número 00951 del expediente de rendimiento.'
        && ($threadComments[49]['comment_text'] ?? '') === 'Mensaje de carga número 01000 del expediente de rendimiento.'
        && ($threadBody['data']['pagination']['has_more_before'] ?? false) === true
        && (int)($threadBody['data']['pagination']['total_comments'] ?? -1) === 1000,
    'extremos reales: ' . json_encode([
        $threadComments[0]['comment_text'] ?? null,
        $threadComments[49]['comment_text'] ?? null,
    ]));

$endpointBenchmark = $benchmark(static fn() => $router->dispatch($threadRequest()), 7);
echo "  [MEDICIÓN] Endpoint GET del hilo (1.000 mensajes): {$formatBenchmark($endpointBenchmark)}\n";

$assert('1.3 RNF-02: la mediana de apertura del hilo queda por debajo de los 250 ms',
    $endpointBenchmark['median'] < PERFORMANCE_BUDGET_MS,
    sprintf('mediana real: %.2f ms (presupuesto %.0f ms)', $endpointBenchmark['median'], PERFORMANCE_BUDGET_MS));

$assert('1.4 RNF-02: ninguna muestra se dispara por encima del guardián de outliers (750 ms)',
    $endpointBenchmark['max'] < OUTLIER_CEILING_MS,
    sprintf('peor caso real: %.2f ms', $endpointBenchmark['max']));

$repositoryBenchmark = $benchmark(
    static fn() => $incidentRepo->getCommentsPaged($incidentId, false, 51),
    7
);
echo "  [MEDICIÓN] Consulta del repositorio (bloque 51 + JOIN de ticket): {$formatBenchmark($repositoryBenchmark)}\n";

$assert('1.5 La consulta cursorizada del repositorio también respeta el presupuesto',
    $repositoryBenchmark['median'] < PERFORMANCE_BUDGET_MS,
    sprintf('mediana real: %.2f ms', $repositoryBenchmark['median']));

$assert('1.6 La consulta devuelve el bloque +1 exigido para calcular has_more_before',
    count($incidentRepo->getCommentsPaged($incidentId, false, 51)) === 51);

$cursorBenchmark = $benchmark(static fn() => $router->dispatch($cursorRequest()), 7);
echo "  [MEDICIÓN] Endpoint GET con cursor before_id=500 (Cargar mensajes anteriores): {$formatBenchmark($cursorBenchmark)}\n";

$assert('1.7 RF-01.3 bajo carga: la paginación retrospectiva por cursor respeta el presupuesto',
    $cursorBenchmark['median'] < PERFORMANCE_BUDGET_MS,
    sprintf('mediana real: %.2f ms', $cursorBenchmark['median']));

$explainPlan = static function (string $sql, array $params) use ($pdo): array {
    $statement = $pdo->prepare('EXPLAIN ' . $sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (($row['table'] ?? '') === 'c') {
            return $row;
        }
    }

    return $rows[0] ?? [];
};

$commentsTablePlan = $explainPlan(
    "SELECT c.*, i.ticket_code
     FROM `incident_comments` c
     JOIN `incidents` i ON c.incident_id = i.id
     WHERE c.incident_id = :incident_id AND c.is_internal = 0
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 51",
    [':incident_id' => $incidentId]
);
echo "  [MEDICIÓN] Plan del motor con 1.000 mensajes: " . json_encode($commentsTablePlan, JSON_UNESCAPED_UNICODE) . "\n";

$assert('1.8 El índice idx_comments_incident está disponible y es aplicable al filtro del hilo',
    in_array('idx_comments_incident', explode(',', (string)($commentsTablePlan['possible_keys'] ?? '')), true),
    'possible_keys reales: ' . ($commentsTablePlan['possible_keys'] ?? '—'));

// =========================================================================
// BLOQUE 2 (H-1): RNF-02 — escalado a 10.000 mensajes y plan indexado
// =========================================================================
echo "\n--- Bloque 2 (H-1): RNF-02 escalado a 10.000 mensajes ---\n";

$seedComments(1001, 10000);
$totalComments = (int)$pdo->query("SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = {$incidentId}")->fetchColumn();

$scaledResponse = $router->dispatch($threadRequest());
$scaledBody = $scaledResponse->getDecodedBody();

$assert('2.1 Con 10.000 mensajes el hilo sigue sirviendo el bloque acotado de 50',
    $scaledResponse->getStatusCode() === 200
        && count($scaledBody['data']['comments'] ?? []) === 50
        && (int)($scaledBody['data']['pagination']['total_comments'] ?? -1) === 10000
        && $totalComments === 10000,
    "status: {$scaledResponse->getStatusCode()}, total real: {$totalComments}");

$scaledBenchmark = $benchmark(static fn() => $router->dispatch($threadRequest()), 5);
echo "  [MEDICIÓN] Endpoint GET del hilo (10.000 mensajes): " . $formatBenchmark($scaledBenchmark) . "\n";

$assert('2.2 RNF-02 a escala: la mediana con 10.000 mensajes sigue por debajo de los 250 ms',
    $scaledBenchmark['median'] < PERFORMANCE_BUDGET_MS,
    sprintf('mediana real: %.2f ms', $scaledBenchmark['median']));

$assert('2.3 RNF-02 a escala: el peor caso respeta el guardián de outliers',
    $scaledBenchmark['max'] < OUTLIER_CEILING_MS,
    sprintf('peor caso real: %.2f ms', $scaledBenchmark['max']));

$scaledPlan = $explainPlan(
    "SELECT c.*, i.ticket_code
     FROM `incident_comments` c
     JOIN `incidents` i ON c.incident_id = i.id
     WHERE c.incident_id = :incident_id AND c.is_internal = 0
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 51",
    [':incident_id' => $incidentId]
);
echo "  [MEDICIÓN] Plan del motor con 10.000 mensajes: " . json_encode($scaledPlan, JSON_UNESCAPED_UNICODE) . "\n";

$assert('2.4 Trampa de regresión: a escala el motor recorre el hilo por idx_comments_incident, sin escaneo completo',
    ($scaledPlan['type'] ?? '') !== 'ALL'
        && ($scaledPlan['key'] ?? '') === 'idx_comments_incident',
    'plan real: ' . json_encode($scaledPlan, JSON_UNESCAPED_UNICODE));

$scaledCursorBenchmark = $benchmark(static fn() => $router->dispatch($cursorRequest()), 5);
echo "  [MEDICIÓN] Endpoint GET con cursor (10.000 mensajes): " . $formatBenchmark($scaledCursorBenchmark) . "\n";

$assert('2.5 RF-01.3 a escala: el cursor retrospectivo sigue por debajo del presupuesto',
    $scaledCursorBenchmark['median'] < PERFORMANCE_BUDGET_MS,
    sprintf('mediana real: %.2f ms', $scaledCursorBenchmark['median']));

// =========================================================================
// BLOQUE 3 (H-3): RNF-05 — protección del directorio de subidas
// =========================================================================
echo "\n--- Bloque 3 (H-3): RNF-05 protección de public/uploads/ ---\n";

$gitkeepPath = $uploadsDir . '/.gitkeep';
$htaccessPath = $uploadsDir . '/.htaccess';
$htaccessSource = is_file($htaccessPath) ? (string)file_get_contents($htaccessPath) : '';

$assert('3.1 El directorio de subidas está versionado y protegido por su propio .htaccess',
    is_dir($uploadsDir) && is_file($gitkeepPath) && is_file($htaccessPath));

$scriptExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi', 'pl', 'exe', 'sh'];
$declaredDenials = preg_match('/<FilesMatch\s+"\(\?i\)\\\\\.\(([^)]+)\)\$"/', $htaccessSource, $denialMatch) === 1
    ? explode('|', $denialMatch[1])
    : [];
$missingExtensions = array_values(array_diff($scriptExtensions, $declaredDenials));

$assert('3.2 El .htaccess deniega todas las extensiones ejecutables conocidas',
    $missingExtensions === [],
    'sin denegar: ' . implode(', ', $missingExtensions));

$assert('3.3 El .htaccess desactiva la ejecución de CGI en el directorio',
    str_contains($htaccessSource, 'Options -ExecCGI'));

$assert('3.4 El .htaccess no contiene reglas de permiso que abran el directorio',
    stripos($htaccessSource, 'Allow from all') === false
        && stripos($htaccessSource, 'Require all granted') === false
        && stripos($htaccessSource, 'Options +ExecCGI') === false);

// Suplantación de nombre: el cliente declara un archivo .php con contenido gráfico real.
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');

$tempFile = static function (string $suffix, string $contents) use (&$tempFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'perfsec_') . $suffix;
    file_put_contents($path, $contents);
    $tempFiles[] = $path;

    return $path;
};

$spoofedName = $tempFile('.php', $validJpgBytes);
$uploadsBeforeSpoof = $countUploads();

$spoofResponse = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia con nombre de archivo suplantado por el cliente.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'exploit.php',
            'type' => 'image/jpeg',
            'tmp_name' => $spoofedName,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($spoofedName),
        ],
    ]
));
$spoofComments = is_array($spoofResponse->getDecodedBody()['data']['comments'] ?? null)
    ? $spoofResponse->getDecodedBody()['data']['comments']
    : [];
$spoofPath = (string)((end($spoofComments) ?: [])['photo_url'] ?? '');
$spoofName = basename($spoofPath);
$createdUploads[] = $spoofPath;

$assert('3.5 La suplantación de nombre no produce jamás un archivo ejecutable',
    $spoofResponse->getStatusCode() === 201
        && preg_match('/^[0-9a-f]{32}\.jpg$/', $spoofName) === 1
        && !str_contains($spoofPath, '.php'),
    "status: {$spoofResponse->getStatusCode()}, nombre almacenado: {$spoofName}");

$assert('3.6 El nombre lo impone el servidor ignorando el del cliente y el archivo se almacena',
    $spoofName !== 'exploit.php'
        && $spoofPath !== ''
        && is_file($projectRoot . '/public' . $spoofPath)
        && $countUploads() === $uploadsBeforeSpoof + 1);

// Contenido ejecutable declarado como imagen: rechazo binario (Art. V.5).
$phpPayload = $tempFile('.jpg', '<?php echo "ejecución no autorizada"; ?>');
$uploadsBeforePayload = $countUploads();

$payloadResponse = $router->dispatch(new Request(
    method: 'POST',
    path: "/api/location/incidents/{$incidentId}/comments",
    queryParams: [],
    parsedBody: ['comment_text' => 'Evidencia con carga ejecutable declarada como imagen.'],
    headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
    files: [
        'photo' => [
            'name' => 'payload.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $phpPayload,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($phpPayload),
        ],
    ]
));

$assert('3.7 Un contenido ejecutable declarado como imagen se rechaza con HTTP 422 sin residuos',
    $payloadResponse->getStatusCode() === 422
        && ($payloadResponse->getDecodedBody()['error']['code'] ?? '') === 'INVALID_FILE_TYPE'
        && $countUploads() === $uploadsBeforePayload,
    "status: {$payloadResponse->getStatusCode()}, code: " . ($payloadResponse->getDecodedBody()['error']['code'] ?? '—'));

// Inmutabilidad: el mismo binario dos veces => dos hashes distintos que coexisten.
$sameBytesA = $tempFile('.jpg', $validJpgBytes);
$sameBytesB = $tempFile('.jpg', $validJpgBytes);

$secondUploadPath = '';
foreach ([$sameBytesA, $sameBytesB] as $probe) {
    $probeResponse = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/location/incidents/{$incidentId}/comments",
        queryParams: [],
        parsedBody: ['comment_text' => 'Evidencia duplicada para certificar la inmutabilidad del nombre.'],
        headers: ['Authorization' => 'Bearer ' . $siteToken, 'Content-Type' => 'multipart/form-data'],
        files: [
            'photo' => [
                'name' => 'misma_evidencia.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $probe,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($probe),
            ],
        ]
    ));
    $probeComments = is_array($probeResponse->getDecodedBody()['data']['comments'] ?? null)
        ? $probeResponse->getDecodedBody()['data']['comments']
        : [];
    $probePath = (string)((end($probeComments) ?: [])['photo_url'] ?? '');
    $createdUploads[] = $probePath;
    $secondUploadPath = $secondUploadPath === '' ? $probePath : $secondUploadPath;
}

$duplicateNames = array_values(array_filter(
    array_map('basename', $createdUploads),
    static fn(string $name): bool => str_ends_with($name, '.jpg')
));
$coexisting = count(array_filter(
    $createdUploads,
    static fn(string $path): bool => $path !== '' && is_file($projectRoot . '/public' . $path)
));

$assert('3.8 Art. III/V.5: subir el mismo binario genera hashes únicos que nunca se sobreescriben',
    count(array_unique($duplicateNames)) >= 3 && $coexisting >= 3,
    'nombres: ' . json_encode($duplicateNames) . ", coexistencia: {$coexisting}");

// Barrido final del directorio: cero archivos ejecutables y cero residuos de la suite.
$executableFiles = [];
foreach (glob($uploadsDir . '/*') ?: [] as $uploadedFile) {
    if (preg_match('/\.(php|phtml|php[3-8]|phar|cgi|pl|sh|exe)$/i', $uploadedFile) === 1) {
        $executableFiles[] = basename($uploadedFile);
    }
}

$assert('3.9 El directorio de subidas queda sin un solo archivo ejecutable',
    $executableFiles === [],
    'hallazgos: ' . implode(', ', $executableFiles));

// Control end-to-end opcional: la evidencia se sirve como recurso gráfico estático.
$serverAvailable = false;
$healthHandle = @curl_init('http://127.0.0.1:8000/api/health');
if ($healthHandle !== false) {
    curl_setopt($healthHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($healthHandle, CURLOPT_TIMEOUT, 2);
    curl_exec($healthHandle);
    $serverAvailable = curl_getinfo($healthHandle, CURLINFO_HTTP_CODE) === 200;
    curl_close($healthHandle);
}

if ($serverAvailable && $spoofPath !== '') {
    $evidenceHandle = curl_init('http://127.0.0.1:8000' . $spoofPath);
    curl_setopt($evidenceHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($evidenceHandle, CURLOPT_TIMEOUT, 3);
    $evidenceBody = curl_exec($evidenceHandle);
    $evidenceStatus = curl_getinfo($evidenceHandle, CURLINFO_HTTP_CODE);
    $evidenceType = (string)curl_getinfo($evidenceHandle, CURLINFO_CONTENT_TYPE);
    curl_close($evidenceHandle);

    $assert('3.10 La evidencia almacenada se sirve como imagen estática y nunca como script',
        $evidenceStatus === 200
            && str_starts_with($evidenceType, 'image/')
            && $evidenceBody === $validJpgBytes
            && str_starts_with($evidenceType, 'text/html') === false,
        "status: {$evidenceStatus}, content-type: {$evidenceType}");
} else {
    echo "  [SKIP] Servidor local no disponible en 127.0.0.1:8000 para el control HTTP 3.10.\n";
}

// =========================================================================
// BLOQUE 4: Cierre — sin residuos y base de datos restablecida
// =========================================================================
echo "\n--- Bloque 4: Cierre de la suite ---\n";

foreach ($createdUploads as $uploadedPath) {
    if ($uploadedPath !== '') {
        $uploadedFile = $projectRoot . '/public' . $uploadedPath;
        if (is_file($uploadedFile)) {
            unlink($uploadedFile);
        }
    }
}
foreach ($tempFiles as $tempFilePath) {
    if (is_file($tempFilePath)) {
        unlink($tempFilePath);
    }
}

$assert('4.1 La suite no deja residuos en public/uploads/',
    $countUploads() === $uploadsAtStart,
    "antes: {$uploadsAtStart}, después: " . $countUploads());

// Restauración canónica idéntica a la que aplica el runner global al terminar la
// batería (purga + semillas base + métricas de demostración), de modo que esta suite
// no solo no deja residuos, sino que devuelve la base al estado exportable.
TestDataCleaner::purge($pdo);
$seedRunner->seedAll();
require_once __DIR__ . '/../../database/DemoMetricsSeeder.php';
(new \VendGuard\Database\DemoMetricsSeeder($pdo))->seed();

$baseline = [];
foreach (['locations', 'machines', 'users', 'incidents', 'incident_comments'] as $table) {
    $baseline[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

$assert('4.2 No queda ni un mensaje ni un expediente de la prueba de carga',
    $baseline['incident_comments'] === 0 && $baseline['incidents'] === 13,
    'estado real: ' . json_encode($baseline));

// Las tablas maestras (locations, machines, users) están protegidas por diseño en
// TestDataCleaner::purge(): no se purgan nunca, de modo que otras suites pueden
// haber añadido filas propias. Se exige el suelo canónico de la semilla, no un valor
// exacto que dependería del orden de ejecución de la batería.
$assert('4.3 Las tablas maestras conservan al menos la semilla canónica del proyecto',
    $baseline['locations'] >= 101 && $baseline['machines'] >= 18 && $baseline['users'] >= 8,
    'estado real: ' . json_encode($baseline));

echo "\nBase de datos restablecida a las semillas (limpieza de la suite).\n";

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. COMMENTS PERFORMANCE AND UPLOAD SECURITY (H-1 / H-3) CERTIFIED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
