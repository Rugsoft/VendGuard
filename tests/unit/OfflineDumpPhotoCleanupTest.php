<?php

declare(strict_types=1);

/**
 * OfflineDumpPhotoCleanupTest · R3
 *
 * Certifica que el volcado de conflicto **no deja evidencias huérfanas**: la
 * foto se sube al almacenamiento antes de saber si algún registro la va a
 * referenciar, así que el controlador debe retirarla en todos los caminos en
 * los que nadie la consume.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Titularidad rechazada (`404`).** Un `stop_id` ajeno responde igual que
 *      uno inexistente y el archivo recién subido no queda en disco (`B1` + `R3`).
 *   2. **Reintento idempotente.** La repetición de un volcado ya aplicado
 *      devuelve el comentario del ganador con `idempotent_replay = true`; la
 *      evidencia nueva se descarta porque el expediente ya tiene la suya.
 *   3. **Volcado nuevo.** Si la Nota Interna se inserta de verdad, el archivo se
 *      conserva y es exactamente el que el repositorio recibió.
 *   4. **Orden preventiva.** El contrato anexa el resumen en `notes` y nunca
 *      adjunta la foto, así que también se retira.
 *
 * Esta suite es hermética: usa un repositorio doble en memoria y un directorio
 * temporal propio, sin PostgreSQL ni la carpeta real de subidas.
 *
 * Uso: php tests/unit/OfflineDumpPhotoCleanupTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\OfflineSyncService;
use VendGuard\Core\Domain\Exception\IncidentNotFoundException;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\Repository\OfflineSyncMutationRepositoryInterface;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Controller\TechnicianOfflineSyncController;
use VendGuard\Presentation\Http\Request;

/**
 * Repositorio doble que decide el destino del volcado sin tocar el motor.
 *
 * `$stored` simula una mutación ya registrada (repetición idempotente);
 * `$ownershipFailure` simula la guarda de titularidad del repositorio real.
 */
final class DumpPhotoCleanupRepository implements OfflineSyncMutationRepositoryInterface
{
    /** @var list<string|null> Rutas de evidencia recibidas por el repositorio. */
    public array $photoPaths = [];

    public function __construct(
        private readonly ?OfflineSyncMutation $stored = null,
        private readonly ?IncidentNotFoundException $ownershipFailure = null,
        private readonly ?int $internalCommentId = 77
    ) {
    }

    public function findByTechnicianAndMutationId(int $technicianId, string $clientMutationId): ?OfflineSyncMutation
    {
        return $this->stored;
    }

    public function recordConflictDump(
        OfflineSyncMutation $mutation,
        string $conflictReason,
        array $workSummary,
        ?string $photoPath = null
    ): array {
        $this->photoPaths[] = $photoPath;

        if ($this->ownershipFailure !== null) {
            throw $this->ownershipFailure;
        }

        $persisted = new OfflineSyncMutation(
            id: 91,
            technicianId: $mutation->technicianId,
            clientMutationId: $mutation->clientMutationId,
            stopType: $mutation->stopType,
            stopId: $mutation->stopId,
            operationType: $mutation->operationType,
            outcomeStatus: $mutation->outcomeStatus,
            clientOccurredAt: $mutation->clientOccurredAt,
            chainStartedAt: $mutation->chainStartedAt,
            effectiveOccurredAt: $mutation->effectiveOccurredAt,
            syncedAt: $mutation->syncedAt,
            clockSkewAdjusted: $mutation->clockSkewAdjusted,
            sanitarySealConfirmed: $mutation->sanitarySealConfirmed,
            responseHttpStatus: 200,
            responsePayload: [
                'client_mutation_id' => $mutation->clientMutationId,
                'outcome_status' => OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
                'stop_type' => $mutation->stopType,
                'stop_id' => $mutation->stopId,
                'internal_comment_id' => $this->internalCommentId,
                'synced_at' => $mutation->syncedAt,
                'message' => 'El trabajo realizado sin conexión se ha guardado como Nota Interna de Taller.',
            ],
            createdAt: $mutation->syncedAt
        );

        return [
            'mutation' => $persisted,
            'internal_comment_id' => $this->internalCommentId,
            'audit_log_id' => 5,
        ];
    }

    public function reserveAppliedMutation(OfflineSyncMutation $mutation): array
    {
        throw new LogicException('El volcado de conflicto no reserva idempotencia.');
    }

    public function completeReservedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation
    {
        throw new LogicException('El volcado de conflicto no completa reservas.');
    }

    public function findLatestAppliedChainStart(int $technicianId, string $stopType, int $stopId): ?OfflineSyncMutation
    {
        return null;
    }

    public function fetchBootstrapSnapshotForTechnician(int $technicianId): array
    {
        return [];
    }

    public function recordAppliedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation
    {
        throw new LogicException('El volcado de conflicto no registra aplicaciones.');
    }

    public function recordDeactivatedAccountDumpBatch(int $technicianId, array $mutations): array
    {
        throw new LogicException('Esta suite no certifica el volcado por baja de cuenta.');
    }
}

$assertions = 0;
$failures = 0;

$assert = static function (string $label, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · R3 · El volcado de conflicto no deja fotos huérfanas\n";
echo "======================================================================\n";

/**
 * Bytes mínimos con cabecera JFIF: `finfo` los reconoce como `image/jpeg` y el
 * cargador los acepta por *magic bytes* sin necesitar una imagen completa.
 */
$jpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    . "\xFF\xDB\x00\x43\x00" . str_repeat("\x01", 64)
    . "\xFF\xD9";

$makeTempDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vg_dump_photo_' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
};

$listFiles = static function (string $dir): array {
    $paths = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [];

    return array_values(array_filter($paths, static fn (string $path): bool => is_file($path)));
};

$makePhoto = static function (string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'vg_photo_');
    file_put_contents($path, $bytes);

    return [
        'name' => 'evidencia.jpg',
        'type' => 'image/jpeg',
        'size' => filesize($path),
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
    ];
};

$removeTree = static function (string $dir) use ($listFiles): void {
    foreach ($listFiles($dir) as $file) {
        @unlink($file);
    }
    @rmdir($dir);
};

/** Cuerpo canónico de un volcado de avería (contrato §6.3). */
$incidentBody = static function (string $mutationId): array {
    return [
        'client_mutation_id' => $mutationId,
        'client_occurred_at' => (new DateTimeImmutable('-30 minutes'))->format('Y-m-d\TH:i:sP'),
        'stop_type' => 'INCIDENT',
        'stop_id' => 501,
        'operation_type' => 'RESOLVE_INCIDENT',
        'conflict_reason' => 'La avería quedó fuera de mi ruta mientras trabajaba sin cobertura.',
        'work_summary' => [
            'diagnosis' => 'Cableado de alimentación principal restablecido.',
            'action_taken' => 'Reengaste de terminal Faston y verificación de tensión.',
            'conformity_summary' => 'Firmado por el conserje de turno.',
        ],
    ];
};

$dump = static function (
    array $body,
    array $photo,
    OfflineSyncMutationRepositoryInterface $repository,
    string $uploadDir
): array {
    $uploader = new LocalFileUploader($uploadDir);
    $controller = new TechnicianOfflineSyncController(
        new OfflineSyncService($repository),
        null,
        $uploader
    );

    $request = new Request(
        method: 'POST',
        path: '/api/technician/offline/conflicts/dump',
        parsedBody: $body,
        files: ['photo' => $photo]
    );
    $request->setAttribute('user_id', 7);

    return [$controller->dumpConflict($request), $uploader];
};

// ─────────────────────────────────────────────────────────────────────────
// 1. Titularidad rechazada: el 404 no puede dejar la evidencia en disco
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Parada ajena (B1) sin residuo en disco ---\n";

$deniedDir = $makeTempDir();
$deniedPhoto = $makePhoto($jpegBytes);
$deniedRepo = new DumpPhotoCleanupRepository(
    stored: null,
    ownershipFailure: new IncidentNotFoundException(501)
);

[$deniedResponse] = $dump($incidentBody('mut_dump_photo_404'), $deniedPhoto, $deniedRepo, $deniedDir);

$assert(
    '1.1 Un volcado sobre una parada ajena responde 404',
    $deniedResponse->getStatusCode() === 404
        && (string)($deniedResponse->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND',
    'status ' . $deniedResponse->getStatusCode()
);
$assert(
    '1.2 El repositorio llegó a recibir la evidencia (el archivo existió antes del rechazo)',
    count($deniedRepo->photoPaths) === 1 && is_string($deniedRepo->photoPaths[0]),
    json_encode($deniedRepo->photoPaths)
);
$assert(
    '1.3 El rechazo no deja ninguna foto huérfana',
    $listFiles($deniedDir) === [],
    json_encode(array_map('basename', $listFiles($deniedDir)))
);

@unlink($deniedPhoto['tmp_name']);
$removeTree($deniedDir);

// ─────────────────────────────────────────────────────────────────────────
// 2. Reintento idempotente: la evidencia del reintento no tiene dueño
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Reintento idempotente del volcado ---\n";

$replayDir = $makeTempDir();
$replayPhoto = $makePhoto($jpegBytes);
$storedDump = new OfflineSyncMutation(
    id: 44,
    technicianId: 7,
    clientMutationId: 'mut_dump_photo_replay',
    stopType: 'INCIDENT',
    stopId: 501,
    operationType: 'RESOLVE_INCIDENT',
    outcomeStatus: OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
    clientOccurredAt: '2026-10-29T09:00:00+00:00',
    chainStartedAt: null,
    effectiveOccurredAt: '2026-10-29T09:00:00+00:00',
    syncedAt: '2026-10-29T09:05:00+00:00',
    clockSkewAdjusted: false,
    sanitarySealConfirmed: false,
    responseHttpStatus: 200,
    responsePayload: [
        'client_mutation_id' => 'mut_dump_photo_replay',
        'outcome_status' => OfflineSyncMutation::OUTCOME_CONFLICT_DUMPED,
        'internal_comment_id' => 31,
        'synced_at' => '2026-10-29T09:05:00+00:00',
        'message' => 'Nota interna creada en la primera pasada.',
    ],
    createdAt: '2026-10-29T09:05:00+00:00'
);
$replayRepo = new DumpPhotoCleanupRepository(stored: $storedDump);

[$replayResponse] = $dump(
    $incidentBody('mut_dump_photo_replay'),
    $replayPhoto,
    $replayRepo,
    $replayDir
);

$replayPayload = $replayResponse->getDecodedBody() ?? [];
$replayData = $replayPayload['data'] ?? [];
$assert(
    '2.1 La repetición devuelve el cuerpo original del ganador, sin escribir otro comentario',
    $replayResponse->getStatusCode() === 200
        && (int)($replayData['internal_comment_id'] ?? 0) === 31
        && ($replayData['synced_at'] ?? '') === '2026-10-29T09:05:00+00:00',
    'status ' . $replayResponse->getStatusCode() . ' · ' . json_encode($replayData)
);
$assert(
    '2.2 La evidencia del reintento se descarta: el expediente ya tenía la suya',
    $replayRepo->photoPaths === [] && $listFiles($replayDir) === [],
    json_encode(array_map('basename', $listFiles($replayDir)))
);

@unlink($replayPhoto['tmp_name']);
$removeTree($replayDir);

// ─────────────────────────────────────────────────────────────────────────
// 3. Volcado nuevo: la foto que sí se referencia se conserva intacta
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Volcado nuevo con Nota Interna ---\n";

$freshDir = $makeTempDir();
$freshPhoto = $makePhoto($jpegBytes);
$freshRepo = new DumpPhotoCleanupRepository();

[$freshResponse] = $dump($incidentBody('mut_dump_photo_fresh'), $freshPhoto, $freshRepo, $freshDir);
$freshFiles = $listFiles($freshDir);
$keptPath = $freshRepo->photoPaths[0] ?? null;

$freshPayload = $freshResponse->getDecodedBody() ?? [];
$assert(
    '3.1 Un volcado nuevo responde 200 con su Nota Interna',
    $freshResponse->getStatusCode() === 200
        && (int)($freshPayload['data']['internal_comment_id'] ?? 0) === 77,
    'status ' . $freshResponse->getStatusCode() . ' · ' . json_encode($freshPayload)
);
$assert(
    '3.2 La evidencia referenciada se conserva y es la que el repositorio recibió',
    count($freshFiles) === 1
        && is_string($keptPath)
        && basename($keptPath) === basename($freshFiles[0]),
    json_encode(array_map('basename', $freshFiles))
);
$assert(
    '3.3 El archivo conservado sigue siendo una imagen JPEG válida',
    count($freshFiles) === 1
        && (new finfo(FILEINFO_MIME_TYPE))->file($freshFiles[0]) === 'image/jpeg'
);

@unlink($freshPhoto['tmp_name']);
$removeTree($freshDir);

// ─────────────────────────────────────────────────────────────────────────
// 4. Orden preventiva: el contrato nunca adjunta la foto
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Volcado de una orden preventiva ---\n";

$preventiveDir = $makeTempDir();
$preventivePhoto = $makePhoto($jpegBytes);
$preventiveRepo = new DumpPhotoCleanupRepository(internalCommentId: null);

[$preventiveResponse] = $dump(
    [
        'client_mutation_id' => 'mut_dump_photo_prev',
        'client_occurred_at' => (new DateTimeImmutable('-10 minutes'))->format('Y-m-d\TH:i:sP'),
        'stop_type' => 'PREVENTIVE_ORDER',
        'stop_id' => 88,
        'operation_type' => 'COMPLETE_PREVENTIVE',
        'conflict_reason' => 'La orden preventiva fue reprogramada por Coordinación.',
        'work_summary' => [
            'notes' => 'Inspección de 8 puntos realizada in situ: todos conformes.',
        ],
    ],
    $preventivePhoto,
    $preventiveRepo,
    $preventiveDir
);

$preventivePayload = $preventiveResponse->getDecodedBody() ?? [];
$preventiveData = $preventivePayload['data'] ?? [];
$assert(
    '4.1 El volcado preventivo responde 200 sin Nota Interna de avería',
    $preventiveResponse->getStatusCode() === 200
        && array_key_exists('internal_comment_id', $preventiveData)
        && $preventiveData['internal_comment_id'] === null,
    'status ' . $preventiveResponse->getStatusCode() . ' · ' . json_encode($preventiveData)
);
$assert(
    '4.2 La foto de una preventiva no se queda en disco (el contrato no la adjunta)',
    $listFiles($preventiveDir) === [],
    json_encode(array_map('basename', $listFiles($preventiveDir)))
);

@unlink($preventivePhoto['tmp_name']);
$removeTree($preventiveDir);

echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. R3 DUMP PHOTO CLEANUP CERTIFIED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILED IN {$failures} ASSERTIONS.\n";
echo "======================================================================\n";
exit(1);
