<?php

declare(strict_types=1);

/**
 * TicketCodeRacePhotoCompensationTest (G8 · EARS-05.3 sobre la carrera real)
 *
 * Certifica que una fotografía subida por un alta que **pierde una carrera
 * concurrente** no queda huérfana en `public/uploads`:
 *
 *   1. **Pierde el primer código y gana el reintento (EARS 2.4).** La foto se subió
 *      una sola vez, antes del reintento; el expediente que prospera la referencia y
 *      el directorio gana **exactamente un** archivo. El reintento no vuelve a subir
 *      la evidencia ni deja colgada la del intento rechazado.
 *   2. **Pierde los tres intentos de código.** El alta responde 409
 *      `TICKET_CODE_COLLISION` y la compensación de `FileStorageCompensator`
 *      (EARS-05.3) retira la foto: el directorio no gana ningún archivo.
 *   3. **Pierde contra un expediente activo de la misma máquina.** La carrera la
 *      gana otro proceso en el índice `uq_machine_active_ticket`; el alta devuelve
 *      409 `MACHINE_HAS_ACTIVE_INCIDENT` y la foto tampoco sobrevive.
 *
 * La colisión se fuerza con una carrera real de dos procesos y dos conexiones PDO —
 * el titular inserta y retiene sin confirmar— y con la fuente de códigos inyectada,
 * de modo que la evidencia viaja por la misma puerta HTTP interna que en producción
 * (`LocationPortalController::createIncident`).
 *
 * La suite se mide contra el `public/uploads` real, que es el directorio del contrato:
 * compara el listado de archivos antes y después de cada caso y retira al final todo
 * lo que haya creado, incluidas las fotos legítimas del caso 1.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\IncidentTicketAllocator;
use VendGuard\Core\Domain\Exception\TicketCodeCollisionException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

echo "======================================================================\n";
echo " VendGuard · G8 · Foto de un alta perdedora y archivos huérfanos\n";
echo " Carrera real · compensación EARS-05.3 sobre public/uploads\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::registerShutdownRestitution();
(new SeedRunner($pdo))->seedAll();

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$incidentRepo = new PdoIncidentRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$holderScript = __DIR__ . '/../Support/UniqueRaceHolder.php';

$uploadsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
    echo "No se pudo preparar public/uploads para la suite.\n";
    exit(1);
}

/** Nombres de archivo presentes en el directorio de evidencias. */
$uploadedFiles = static function () use ($uploadsDir): array {
    $entries = scandir($uploadsDir);
    if ($entries === false) {
        return [];
    }

    return array_values(array_filter(
        $entries,
        static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && is_file($uploadsDir . DIRECTORY_SEPARATOR . $entry)
    ));
};

/** Archivos nuevos respecto de una foto del directorio. */
$newFilesSince = static function (array $before) use ($uploadedFiles): array {
    return array_values(array_diff($uploadedFiles(), $before));
};

/** Foto del directorio al arrancar: el cierre exige volver exactamente a ella. */
$suiteStartFiles = $uploadedFiles();

/** Evidencia de prueba: PNG 1×1 real, reconocido por `finfo` como image/png. */
$photoFixture = tempnam(sys_get_temp_dir(), 'vg_g8_photo_');
file_put_contents(
    $photoFixture,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
);
$photoFile = static fn(): array => [
    'name' => 'evidencia-g8.png',
    'type' => 'image/png',
    'tmp_name' => $photoFixture,
    'error' => UPLOAD_ERR_OK,
    'size' => (int)filesize($photoFixture),
];

/** Ficha mínima de una máquina por código. */
$machineByCode = static function (string $code) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, location_id, code FROM machines WHERE code = :code');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException("La semilla no declara la máquina {$code}.");
    }

    return ['id' => (int)$row['id'], 'location_id' => (int)$row['location_id'], 'code' => (string)$row['code']];
};

$hasActiveIncident = static function (int $machineId) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND status NOT IN ('CLOSED', 'CANCELLED')"
    );
    $stmt->execute([':id' => $machineId]);

    return (int)$stmt->fetchColumn() > 0;
};

$sequence = static function (array $codes): callable {
    $remaining = array_values($codes);

    return static function () use (&$remaining): string {
        return $remaining === [] ? 'INC-2026-AGOT' : (string)array_shift($remaining);
    };
};

/**
 * Carrera real: el titular inserta y retiene la fila sin confirmar mientras la puerta
 * de alta real se ejecuta contra esa fila invisible. Mide la espera en el índice.
 *
 * @return array{result: mixed, error: ?Throwable, elapsed: float, exit: int, log: string, ready: bool}
 */
$race = function (string $insertSql, callable $operation) use ($pdo, $holderScript): array {
    $sqlFile = tempnam(sys_get_temp_dir(), 'vg_g8_sql_');
    $readyFile = tempnam(sys_get_temp_dir(), 'vg_g8_ready_');
    $logFile = tempnam(sys_get_temp_dir(), 'vg_g8_log_');
    @unlink($readyFile);
    file_put_contents($sqlFile, $insertSql);

    $process = proc_open(
        [PHP_BINARY, $holderScript, $sqlFile, $readyFile, '1200'],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($sqlFile);
        @unlink($readyFile);
        @unlink($logFile);
        throw new RuntimeException('No se pudo arrancar el proceso titular de la carrera.');
    }
    fclose($pipes[0]);

    $deadline = microtime(true) + 10.0;
    while (!is_file($readyFile) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $ready = is_file($readyFile);

    $startedAt = microtime(true);
    $result = null;
    $error = null;
    if ($ready) {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $error = $exception;
        }
    }
    $elapsed = microtime(true) - $startedAt;

    $exit = proc_close($process);
    $log = trim((string)file_get_contents($logFile));

    @unlink($sqlFile);
    @unlink($readyFile);
    @unlink($logFile);

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return ['result' => $result, 'error' => $error, 'elapsed' => $elapsed, 'exit' => $exit, 'log' => $log, 'ready' => $ready];
};

$holdIncidentSql = static function (string $ticketCode, array $holderMachine): string {
    return sprintf(
        "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status) "
        . "VALUES ('%s', %d, %d, 'OTHER', 'Titular de la carrera G8 sobre la evidencia fotográfica.', 'MEDIUM', 'REGISTERED')",
        $ticketCode,
        $holderMachine['id'],
        $holderMachine['location_id']
    );
};

/** Petición del portal con la foto adjunta, como la construye el formulario de sede. */
$portalRequest = static function (array $machine, $location, array $photo): Request {
    $request = new Request('POST', '/api/incidents', [], [
        'machine_id' => $machine['id'],
        'category' => 'OTHER',
        'description' => 'Aviso de carrera G8 con evidencia fotográfica desde el portal de sede.',
        'reporter_name' => 'Fixture G8 · Sede',
    ], [], ['photo' => $photo]);
    $request->setAttribute('authenticated_location', $location);

    return $request;
};

$assertRaceCommitted = function (string $label, array $raceResult) use ($assert): void {
    $assert(
        "{$label} · el titular retuvo la fila sin confirmar y confirmó en verde",
        $raceResult['ready'] === true && $raceResult['exit'] === 0 && str_contains($raceResult['log'], 'COMMITTED'),
        $raceResult['log']
    );
    $assert(
        "{$label} · la escritura perdedora esperó al índice único",
        $raceResult['elapsed'] >= 0.30,
        sprintf('espera medida: %.3f s', $raceResult['elapsed'])
    );
};

/** Colección de archivos que la suite creó, para retirarlos al final. */
$createdFiles = [];

// ---------------------------------------------------------------------
// CASO 1 · Pierde el código, gana el reintento: la foto se conserva una vez
// ---------------------------------------------------------------------
echo "\n--- Caso 1: la foto del alta que pierde el código y reintenta ---\n";

$winnerMachine = $machineByCode('VEND-0601');
$winnerHolderMachine = $machineByCode('VEND-0701');
$assert(
    '1.0 Máquinas libres para el caso del reintento con foto',
    !$hasActiveIncident($winnerMachine['id']) && !$hasActiveIncident($winnerHolderMachine['id'])
);

$winnerLocation = $locationRepo->findById($winnerMachine['location_id']);
$heldCode = 'INC-2026-G8W1';
$freshCode = 'INC-2026-G8W2';
$controller = new LocationPortalController(
    ticketAllocator: new IncidentTicketAllocator($sequence([$heldCode, $freshCode]))
);

$filesBefore = $uploadedFiles();
$raceResult = $race(
    $holdIncidentSql($heldCode, $winnerHolderMachine),
    static fn() => $controller->createIncident($portalRequest($winnerMachine, $winnerLocation, $photoFile()))
);
$assertRaceCommitted('1.1 Reintento', $raceResult);
$assert('1.2 Reintento · el alta no falla', $raceResult['error'] === null, $raceResult['error'] !== null ? get_class($raceResult['error']) . ': ' . $raceResult['error']->getMessage() : '');

$response = $raceResult['result'] instanceof Response ? $raceResult['result'] : null;
$body = $response !== null ? $response->getDecodedBody() : null;
$createdTicket = $body['data']['ticket_code'] ?? null;
$photoPath = $body['data']['photo_path'] ?? null;
$newFiles = $newFilesSince($filesBefore);
$createdFiles = array_merge($createdFiles, $newFiles);

$assert(
    '1.3 Reintento · responde 201 con el código reasignado',
    $response !== null && $response->getStatusCode() === 201 && $createdTicket === $freshCode,
    'status: ' . var_export($response !== null ? $response->getStatusCode() : null, true) . ' · ticket: ' . var_export($createdTicket, true)
);
$assert(
    '1.4 Reintento · en public/uploads aparece exactamente un archivo nuevo',
    count($newFiles) === 1,
    'nuevos: ' . json_encode($newFiles)
);
$assert(
    '1.5 Reintento · la evidencia nueva es la que referencia el expediente y sigue en disco',
    is_string($photoPath)
        && $newFiles === [basename($photoPath)]
        && is_file($uploadsDir . DIRECTORY_SEPARATOR . basename($photoPath)),
    'photo_path: ' . var_export($photoPath, true) . ' · nuevos: ' . json_encode($newFiles)
);

$stmt = $pdo->prepare('SELECT photo_path FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $winnerMachine['id']]);
$persistedPhoto = $stmt->fetchColumn();
$assert(
    '1.6 Reintento · la base guarda exactamente esa ruta y no un duplicado',
    $persistedPhoto === $photoPath,
    'db: ' . var_export($persistedPhoto, true)
);

// ---------------------------------------------------------------------
// CASO 2 · Pierde los tres intentos: 409 y foto compensada
// ---------------------------------------------------------------------
echo "\n--- Caso 2: la foto de un alta que agota el reintento ---\n";

$exhaustedMachine = $machineByCode('VEND-0801');
$exhaustedHolderMachine = $machineByCode('VEND-0901');
$assert(
    '2.0 Máquinas libres para el caso de cota agotada',
    !$hasActiveIncident($exhaustedMachine['id']) && !$hasActiveIncident($exhaustedHolderMachine['id'])
);

$exhaustedLocation = $locationRepo->findById($exhaustedMachine['location_id']);
$exhaustedCode = 'INC-2026-G8X1';
$exhaustedController = new LocationPortalController(
    ticketAllocator: new IncidentTicketAllocator($sequence([$exhaustedCode, $exhaustedCode, $exhaustedCode]))
);

$filesBefore = $uploadedFiles();
$exhaustedRace = $race(
    $holdIncidentSql($exhaustedCode, $exhaustedHolderMachine),
    static fn() => $exhaustedController->createIncident($portalRequest($exhaustedMachine, $exhaustedLocation, $photoFile()))
);
$assertRaceCommitted('2.1 Cota', $exhaustedRace);

$exhaustedError = $exhaustedRace['error'];
$assert(
    '2.2 Cota · el alta termina en 409 TICKET_CODE_COLLISION, no en un 500',
    $exhaustedError instanceof TicketCodeCollisionException
        && $exhaustedError->getHttpStatusCode() === 409
        && $exhaustedError->getAttempts() === IncidentTicketAllocator::MAX_ATTEMPTS,
    $exhaustedError !== null ? get_class($exhaustedError) . ': ' . $exhaustedError->getMessage() : 'no se lanzó excepción'
);

$exhaustedFiles = $newFilesSince($filesBefore);
$createdFiles = array_merge($createdFiles, $exhaustedFiles);
$assert(
    '2.3 Cota · public/uploads no gana ningún archivo huérfano',
    $exhaustedFiles === [],
    'huérfanos: ' . json_encode($exhaustedFiles)
);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $exhaustedMachine['id']]);
$assert(
    '2.4 Cota · el alta fallida no deja expediente a medias',
    (int)$stmt->fetchColumn() === 0
);

// ---------------------------------------------------------------------
// CASO 3 · Pierde contra el ticket activo de la misma máquina
// ---------------------------------------------------------------------
echo "\n--- Caso 3: la foto de un alta que pierde contra un aviso activo ---\n";

$duplicateMachine = $machineByCode('VEND-1001');
$assert('3.0 La máquina del duplicado está limpia', !$hasActiveIncident($duplicateMachine['id']));

$duplicateLocation = $locationRepo->findById($duplicateMachine['location_id']);
$duplicateCode = 'INC-2026-G8D1';
$duplicateController = new LocationPortalController();

$filesBefore = $uploadedFiles();
$duplicateRace = $race(
    $holdIncidentSql($duplicateCode, $duplicateMachine),
    static fn() => $duplicateController->createIncident($portalRequest($duplicateMachine, $duplicateLocation, $photoFile()))
);
$assertRaceCommitted('3.1 Duplicado', $duplicateRace);

$duplicateResponse = $duplicateRace['result'] instanceof Response ? $duplicateRace['result'] : null;
$duplicateBody = $duplicateResponse !== null ? $duplicateResponse->getDecodedBody() : null;
$assert(
    '3.2 Duplicado · responde 409 con el ticket activo de la carrera',
    $duplicateResponse !== null
        && $duplicateResponse->getStatusCode() === 409
        && ($duplicateBody['error']['code'] ?? null) === 'MACHINE_HAS_ACTIVE_INCIDENT'
        && ($duplicateBody['error']['details']['ticket_code'] ?? null) === $duplicateCode,
    'status: ' . var_export($duplicateResponse !== null ? $duplicateResponse->getStatusCode() : null, true)
        . ' · body: ' . json_encode($duplicateBody)
);

$duplicateFiles = $newFilesSince($filesBefore);
$createdFiles = array_merge($createdFiles, $duplicateFiles);
$assert(
    '3.3 Duplicado · la foto del alta perdedora se compensa y no queda huérfana',
    $duplicateFiles === [],
    'huérfanos: ' . json_encode($duplicateFiles)
);

$stmt = $pdo->prepare('SELECT ticket_code FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $duplicateMachine['id']]);
$duplicateRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
$assert(
    '3.4 Duplicado · sólo sobrevive el expediente del titular',
    $duplicateRows === [$duplicateCode],
    'filas: ' . json_encode($duplicateRows)
);

// ---------------------------------------------------------------------
// CIERRE · las colisiones fueron reales y no queda rastro en disco
// ---------------------------------------------------------------------
echo "\n--- Cierre: colisiones reales y directorio restituido ---\n";

foreach ([$heldCode, $exhaustedCode, $duplicateCode] as $heldTicket) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE ticket_code = :code');
    $stmt->execute([':code' => $heldTicket]);
    $assert("El código titular {$heldTicket} quedó confirmado en la base", (int)$stmt->fetchColumn() === 1);
}

foreach (array_unique($createdFiles) as $file) {
    $fullPath = $uploadsDir . DIRECTORY_SEPARATOR . $file;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
@unlink($photoFixture);

TestDataCleaner::purge($pdo);

$assert(
    'public/uploads vuelve exactamente al estado en que arrancó la suite',
    $uploadedFiles() === $suiteStartFiles,
    'restos: ' . json_encode($newFilesSince($suiteStartFiles))
);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G8 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
