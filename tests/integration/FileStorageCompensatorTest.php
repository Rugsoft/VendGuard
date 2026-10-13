<?php

declare(strict_types=1);

/**
 * FileStorageCompensatorTest
 *
 * Certifica la transaccionalidad compensatoria del almacenamiento local de evidencias
 * (EARS-05.2, EARS-05.3, EARS-05.4, RNF-03, T-HYBRID-08).
 *
 * Qué demuestra, y por qué así:
 * 1. **Camino real del portal de Sede.** Un alta con fotografía que se persiste deja el
 *    fichero en disco (control positivo: la compensación no borra lo que sí se referencia).
 * 2. **Fallo de persistencia en el camino real.** El mismo alta sobre una máquina con aviso
 *    activo muere con `409` y **no deja ni un fichero nuevo**: la fotografía que la petición
 *    acabó de escribir ya no está en disco (EARS-05.3), y la evidencia legítima anterior
 *    sigue intacta.
 * 3. **Reporte QR rechazado después de subir.** La validación del servicio mata la petición
 *    con `422` y, con ella, el fichero recién creado.
 * 4. **Hilo de comentarios con la persistencia caída.** Es el punto único de las rutas de
 *    comentario de Sede, Técnico y Coordinador: con un repositorio que pierde el enlace, la
 *    ruta pública **exacta** que devolvió la subida deja de existir y la excepción original
 *    sigue su camino.
 * 5. **EARS-05.3 en el código, no sólo en el comportamiento.** Los cinco puntos de uso reales
 *    consumen el compensador y **ninguno** reimplementa `unlink()`: el borrado físico vive en
 *    el subidor que escribió el fichero.
 *
 * Ejecuta llamadas reales contra MariaDB y el router de producción, y limpia sus propios
 * residuos al terminar (el directorio `public/uploads/` cierra como empezó).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Storage\FileStorageCompensator;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

/**
 * Subidor que recuerda qué rutas públicas entregó: permite afirmar sobre la ruta **exacta**
 * que la petición escribió, en lugar de deducirla del directorio.
 */
final class RecordingFileUploader extends LocalFileUploader
{
    /** @var list<string> Rutas públicas devueltas por las subidas, en orden. */
    public array $uploaded = [];

    public function upload(array $file): string
    {
        $publicPath = parent::upload($file);
        $this->uploaded[] = $publicPath;

        return $publicPath;
    }
}

/**
 * Repositorio real que pierde el enlace justo al persistir el comentario: es el modo de error
 * que motiva la compensación (motor administrado remoto, tiempo de espera agotado o conexión
 * caída). Todo lo demás —la lectura del expediente, el sellado— sigue siendo el de producción.
 */
final class LinkLostIncidentRepository extends PdoIncidentRepository
{
    public function addComment(IncidentComment $comment): IncidentComment
    {
        throw new PDOException(
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (simulación de enlace perdido)'
        );
    }
}

echo "======================================================================\n";
echo " VendGuard: Test de Integración - FileStorageCompensatorTest (T-HYBRID-08)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$authService = new AuthService($locationRepo);

$uploadsDir = dirname(__DIR__, 2) . '/public/uploads';

/** Inventario de evidencias en disco: la comparación que delata un fichero huérfano. */
$snapshotUploads = static function () use ($uploadsDir): array {
    if (!is_dir($uploadsDir)) {
        return [];
    }

    $entries = array_values(array_diff(scandir($uploadsDir) ?: [], ['.', '..', '.gitkeep']));
    sort($entries);

    return $entries;
};

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

/** Evidencia JPEG mínima pero con cabecera real (misma que las suites del hilo). */
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');

/** Construye el adjunto multipart a partir de una imagen temporal válida. */
$photoPayload = static function () use ($validJpgBytes): array {
    $tempPhoto = tempnam(sys_get_temp_dir(), 'compensator_') . '.jpg';
    file_put_contents($tempPhoto, $validJpgBytes);

    return [
        'photo' => [
            'name' => 'evidencia.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tempPhoto,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempPhoto),
        ],
    ];
};

$removeTempFiles = static function (array $filePayload): void {
    foreach ($filePayload as $file) {
        if (isset($file['tmp_name']) && is_file((string)$file['tmp_name'])) {
            unlink((string)$file['tmp_name']);
        }
    }
};

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
if ($location === null) {
    echo "ERROR: la sede SEDE-BCN-01 no está disponible tras sembrar.\n";
    exit(1);
}

$machines = $machineRepo->findActiveByLocationId($location->getId());
$portalMachine = $machines[0] ?? null;
$qrMachine = $machines[1] ?? null;

if ($portalMachine === null || $qrMachine === null) {
    echo "ERROR: la sede sembrada no tiene dos máquinas activas para la prueba.\n";
    exit(1);
}

// Parque limpio para las dos máquinas de la prueba: el escenario es un alta sin aviso previo.
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$portalMachine->getId());
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$qrMachine->getId());

$siteToken = $authService->generateSiteToken($location);
$uploadsBefore = $snapshotUploads();
$createdFiles = [];

echo "--- Grupo 1: Control positivo, alta con evidencia que SÍ se persiste ---\n";

$firstPhotos = $photoPayload();
$firstRequest = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $portalMachine->getId(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina no mantiene el frío y los sándwiches están calientes (control positivo de compensación).',
        'reporter_name' => 'Carla Gómez',
        'reporter_phone' => '655123456',
    ],
    headers: [
        'Authorization' => "Bearer {$siteToken}",
        'Content-Type' => 'multipart/form-data',
    ],
    files: $firstPhotos
);

$firstResponse = $router->dispatch($firstRequest);
$firstBody = $firstResponse->getDecodedBody();
$keptPhotoPath = (string)($firstBody['data']['photo_path'] ?? '');
$keptAbsolutePath = $uploadsDir . '/' . basename($keptPhotoPath);

$assert(
    '1.1 El alta con evidencia se persiste (HTTP 201) y devuelve su ruta pública',
    $firstResponse->getStatusCode() === 201 && str_starts_with($keptPhotoPath, '/uploads/'),
    sprintf('status=%d · photo_path=%s', $firstResponse->getStatusCode(), $keptPhotoPath)
);

$assert(
    '1.2 La evidencia legítima sigue en disco: la compensación no borra lo que sí se referencia',
    $keptPhotoPath !== '' && is_file($keptAbsolutePath)
);

$createdFiles[] = $keptAbsolutePath;
$removeTempFiles($firstPhotos);
$uploadsAfterSuccess = $snapshotUploads();

echo "\n--- Grupo 2: Fallo de persistencia real (aviso duplicado) con la foto ya escrita ---\n";

$secondPhotos = $photoPayload();
$secondRequest = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $portalMachine->getId(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'Segundo aviso sobre la misma máquina: la persistencia rechaza el duplicado (RF-02).',
        'reporter_name' => 'Marc Soler',
    ],
    headers: [
        'Authorization' => "Bearer {$siteToken}",
        'Content-Type' => 'multipart/form-data',
    ],
    files: $secondPhotos
);

$secondResponse = $router->dispatch($secondRequest);
$secondBody = $secondResponse->getDecodedBody();

$assert(
    '2.1 El alta duplicada muere con 409 (la persistencia no llegó a confirmar) y nombra el expediente vigente',
    $secondResponse->getStatusCode() === 409
    && ($secondBody['error']['code'] ?? '') === 'MACHINE_HAS_ACTIVE_INCIDENT'
    && ($secondBody['error']['details']['ticket_code'] ?? '') !== '',
    sprintf(
        'status=%d · code=%s · details=%s',
        $secondResponse->getStatusCode(),
        (string)($secondBody['error']['code'] ?? ''),
        json_encode($secondBody['error']['details'] ?? null)
    )
);

$afterDuplicate = $snapshotUploads();
$assert(
    '2.2 [CONDICIÓN CRÍTICA "HECHO CUANDO"] Tras el fallo de persistencia el fichero ya NO existe en disco',
    $afterDuplicate === $uploadsAfterSuccess,
    sprintf('antes=%s · después=%s', json_encode($uploadsAfterSuccess), json_encode($afterDuplicate))
);

$assert(
    '2.3 La evidencia del expediente válido anterior queda intacta (no se compensa lo ajeno)',
    is_file($keptAbsolutePath) && $keptPhotoPath !== ''
);

$removeTempFiles($secondPhotos);

echo "\n--- Grupo 3: Reporte QR rechazado en la validación del servicio, con la foto ya escrita ---\n";

$qrPhotos = $photoPayload();
$qrRequest = new Request(
    method: 'POST',
    path: '/api/qr/report',
    queryParams: [],
    parsedBody: [
        'machine_code' => $qrMachine->getCode(),
        'category' => 'CATEGORIA_INEXISTENTE',
        'description' => 'Reporte ciudadano con categoría inválida que el servicio rechaza después de la subida.',
    ],
    headers: ['Content-Type' => 'multipart/form-data'],
    files: $qrPhotos
);

$qrResponse = $router->dispatch($qrRequest);
$qrBody = $qrResponse->getDecodedBody();

$assert(
    '3.1 El reporte con categoría inválida responde 422 INVALID_PAYLOAD (el servicio no persiste nada)',
    $qrResponse->getStatusCode() === 422
    && ($qrBody['error']['code'] ?? '') === 'INVALID_PAYLOAD',
    sprintf('status=%d · code=%s', $qrResponse->getStatusCode(), (string)($qrBody['error']['code'] ?? ''))
);

$assert(
    '3.2 [EARS-05.3] La fotografía del reporte rechazado ya no está en disco',
    $snapshotUploads() === $uploadsAfterSuccess,
    sprintf('directorio tras el rechazo=%s', json_encode($snapshotUploads()))
);

$removeTempFiles($qrPhotos);

echo "\n--- Grupo 4: Hilo de comentarios con el enlace perdido al persistir ---\n";

// Es el punto único de las rutas de comentario de Sede, Técnico y Coordinador: la subida y la
// inserción viven dentro del servicio, así que se ejercita con su cableado de producción.
$failingRepo = new LinkLostIncidentRepository($pdo);
$recordingUploader = new RecordingFileUploader($uploadsDir);
$commentService = new IncidentCommentService(
    $failingRepo,
    null,
    $recordingUploader,
    new FileStorageCompensator($recordingUploader)
);

$commentIncident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'INC-2026-TH0801',
    machineId: (int)$qrMachine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Expediente de la prueba del compensador de archivos (T-HYBRID-08).',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Sonda de certificación',
    reporterPhone: '600000000',
    retainedMoneyAmount: null,
    photoPath: null
));

$commentPhotos = $photoPayload();
$propagated = null;
try {
    $commentService->addComment(
        (int)$commentIncident->getId(),
        'COORDINATOR',
        'Nota interna de taller con evidencia fotográfica adjunta.',
        ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Coordinación Central de Operaciones'],
        true,
        $commentPhotos['photo']
    );
} catch (PDOException $exception) {
    $propagated = $exception;
}

$recordedPath = $recordingUploader->uploaded[0] ?? '';

$assert(
    '4.1 El comentario con evidencia llegó a escribir el fichero y murió al persistir',
    $propagated instanceof PDOException && str_starts_with($recordedPath, '/uploads/'),
    sprintf('excepción=%s · ruta=%s', $propagated === null ? 'ninguna' : $propagated->getMessage(), $recordedPath)
);

$assert(
    '4.2 [EARS-05.3] La ruta pública exacta que devolvió la subida ya no existe en disco',
    $recordedPath !== '' && !is_file($uploadsDir . '/' . basename($recordedPath))
);

$assert(
    '4.3 La excepción original se propaga con su causa verdadera (la compensación no la sustituye)',
    $propagated instanceof PDOException
    && str_contains($propagated->getMessage(), 'gone away')
);

$assert(
    '4.4 El comentario no quedó en la bitácora: sin fila no hay referencia y sin referencia no hay fichero',
    (int)$pdo->query(sprintf(
        'SELECT COUNT(*) FROM incident_comments WHERE incident_id = %d',
        (int)$commentIncident->getId()
    ))->fetchColumn() === 0
);

$removeTempFiles($commentPhotos);

echo "\n--- Grupo 5: Una sola implementación del borrado en los cinco puntos de uso ---\n";

$projectRoot = dirname(__DIR__, 2);

/**
 * Código sin comentarios: la guarda juzga lo que se ejecuta, no lo que se documenta
 * (quien dice «aquí no se reimplementa `unlink()`» no está reimplementándolo).
 */
$executableSource = static fn (string $relativePath): string => (string)php_strip_whitespace(
    $projectRoot . '/' . $relativePath
);

$compensatorSource = $executableSource('src/Infrastructure/Storage/FileStorageCompensator.php');
$uploaderSource = $executableSource('src/Infrastructure/Storage/LocalFileUploader.php');

$assert(
    '5.1 El compensador delega el borrado físico en el subidor y no reimplementa unlink()',
    str_contains($compensatorSource, '->fileUploader->delete(')
    && !str_contains($compensatorSource, 'unlink(')
    && str_contains($uploaderSource, 'unlink($fullPath)')
);

// Las cinco rutas de subida: tres escriben el fichero por sí mismas —y envuelven su
// persistencia— y dos delegan la subida en el punto único del hilo de comentarios, al que
// entregan el MISMO compensador que poseen.
$fiveUsagePoints = [
    'src/Application/Service/IncidentCommentService.php' => 'envuelve su persistencia',
    'src/Presentation/Controller/LocationPortalController.php' => 'envuelve su persistencia',
    'src/Presentation/Controller/QrScanController.php' => 'envuelve su persistencia',
    'src/Presentation/Controller/CoordinatorController.php' => 'cablea el servicio del hilo',
    'src/Presentation/Controller/TechnicianController.php' => 'cablea el servicio del hilo',
];

$unwired = [];
$reimplementing = [];
foreach ($fiveUsagePoints as $relativePath => $role) {
    $source = $executableSource($relativePath);

    $wired = str_contains($source, 'FileStorageCompensator')
        && ($role === 'envuelve su persistencia'
            ? str_contains($source, 'executeWithCompensatingFile')
            : preg_match('/new IncidentCommentService\([^;]*\$this->compensator\(\)/s', $source) === 1);

    if (!$wired) {
        $unwired[] = sprintf('%s (%s)', $relativePath, $role);
    }

    if (str_contains($source, 'unlink(')) {
        $reimplementing[] = $relativePath;
    }
}

$assert(
    '5.2 Los cinco puntos de uso reales consumen el compensador',
    $unwired === [],
    'sin cablear: ' . implode(', ', $unwired)
);

$assert(
    '5.3 Ninguno de los cinco puntos reimplementa su propio unlink() (EARS-05.3)',
    $reimplementing === [],
    'con unlink() propio: ' . implode(', ', $reimplementing)
);

echo "\n--- Limpieza: el directorio de evidencias cierra como empezó ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$portalMachine->getId());
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$qrMachine->getId());

foreach ($createdFiles as $createdFile) {
    if (is_file($createdFile)) {
        unlink($createdFile);
    }
}

$uploadsClosed = $snapshotUploads();
$assert(
    '6.1 El directorio public/uploads/ cierra sin residuos de la prueba',
    $uploadsClosed === $uploadsBefore,
    sprintf('inicial=%s · final=%s', json_encode($uploadsBefore), json_encode($uploadsClosed))
);

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE (0 errors, 0 failures).\n";
} else {
    echo " RESULTADO: {$failures} aserción(es) fallida(s).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
