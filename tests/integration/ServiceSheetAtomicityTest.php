<?php

declare(strict_types=1);

/**
 * ServiceSheetAtomicityTest · T-SIGN-03 y T-SIGN-04
 *
 * Certifica la persistencia y la atomicidad de la hoja de servicio del Módulo 19
 * contra el motor PostgreSQL, sobre un esquema aislado y con el servicio de
 * aplicación real.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Atomicidad del cierre (Art. III, S5):** si la escritura de la hoja falla
 *      después de haber escrito las piezas y descontado el stock, la transacción
 *      revierte entera: ni líneas de repuesto, ni descuento de furgoneta, ni
 *      cambio de estado. Un cierre no puede quedar a medias.
 *   2. **Reintento sin duplicados:** repetir el cierre tras el fallo escribe las
 *      piezas una sola vez y descuenta una sola vez.
 *   3. **Fotografía fiel (RD-10, §6.1):** el documento congelado se compone con
 *      las filas que la transacción acaba de escribir, incluida la marca de
 *      resolución que ancla la hoja a esa visita concreta.
 *   4. **Inmunidad al cambio posterior:** renombrar la sede o la máquina después
 *      del cierre no altera una coma del papel firmado.
 *   5. **Trazo íntegro:** un PNG de más de 8 KB vuelve del motor byte a byte.
 *   6. **Albarán histórico (Q2, §4.4):** tras una reapertura y un segundo cierre
 *      conviven dos hojas; solo la que coincide con la resolución vigente es la
 *      actual, y la anterior se imprime por identificador.
 *   7. **Hoja ajena:** pedir una hoja que no pertenece a la avería no devuelve
 *      ninguna fila (el `JOIN` de propiedad la excluye en el motor).
 *   8. **Inmutabilidad declarada:** el repositorio no ofrece `update` ni `delete`,
 *      y el motor rechaza un `UPDATE` directo y una firma que no sea PNG.
 *
 * Categoría: new_engine. Requiere `deploy/local/postgres/.env.local`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Application\Service\VanStockService;
use VendGuard\Core\Domain\Model\IncidentServiceSheet;
use VendGuard\Core\Domain\Repository\IncidentServiceSheetRepositoryInterface;
use VendGuard\Core\Service\ServiceSheetValidator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentServiceSheetRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const SHEET_ATOMICITY_TEST_SCHEMA = 't_sign04_atomic';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-SIGN-03/04 · Atomicidad y persistencia de la hoja de servicio\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/**
 * Decorador que falla al escribir la hoja, después de que el resto del cierre ya
 * escribió. Es la única forma honesta de probar el rollback: el motor real
 * escribe piezas y stock y luego la hoja explota.
 */
final class FailingServiceSheetRepository implements IncidentServiceSheetRepositoryInterface
{
    public function __construct(private readonly IncidentServiceSheetRepositoryInterface $inner)
    {
    }

    public function insert(IncidentServiceSheet $sheet): IncidentServiceSheet
    {
        throw new RuntimeException('Fallo simulado del motor al escribir la hoja de servicio.');
    }

    public function findDocumentByIncidentId(int $incidentId, ?int $sheetId = null): ?array
    {
        return $this->inner->findDocumentByIncidentId($incidentId, $sheetId);
    }

    public function listByIncidentId(int $incidentId): array
    {
        return $this->inner->listByIncidentId($incidentId);
    }
}

/**
 * PNG real de 600x250 con un `tEXt` de relleno: supera los 8 KB y sigue siendo
 * un PNG válido (CRC correcto en cada chunk), no un fichero con basura pegada.
 */
$pngOf = static function (int $targetBytes): string {
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $png = "\x89PNG\r\n\x1a\n";
    $png .= $chunk('IHDR', pack('NNCCCCC', 600, 250, 8, 0, 0, 0, 0));
    $png .= $chunk('tEXt', 'Comment' . "\0" . str_repeat('Firma manuscrita digitalizada. ', 400));
    $png .= $chunk('IEND', '');

    if (strlen($png) < $targetBytes) {
        $png = substr($png, 0, -12)
            . $chunk('tEXt', 'Comment' . "\0" . str_repeat('A', $targetBytes - strlen($png)))
            . $chunk('IEND', '');
    }

    return $png;
};

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, SHEET_ATOMICITY_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Semilla explícita
    // ─────────────────────────────────────────────────────────────────────

    $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address)
         VALUES (:code, :key, :name, :address) RETURNING id'
    )->execute([':code' => 'SEDE-SIGN-01', ':key' => 'sede-sign-01', ':name' => 'Sede Firma', ':address' => 'Calle del Trazo 1']);

    $locationId = (int)$pdo->query('SELECT id FROM locations WHERE site_code_key = \'sede-sign-01\'')->fetchColumn();

    $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
         VALUES (:name, :email, :email_key, :hash, \'TECHNICIAN\', :operator, TRUE) RETURNING id'
    )->execute([
        ':name' => 'Técnico de Firma',
        ':email' => 'sign19.tech@vendguard.internal',
        ':email_key' => 'sign19.tech@vendguard.internal',
        ':hash' => password_hash('sign19-tech', PASSWORD_BCRYPT),
        ':operator' => 'V19-T1',
    ]);

    $technicianId = (int)$pdo->query('SELECT id FROM users WHERE email_key = \'sign19.tech@vendguard.internal\'')->fetchColumn();

    $createMachine = static function (string $code) use ($pdo, $locationId): int {
        $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, \'COLD_DRINKS\', :floor_wing) RETURNING id'
        )->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo Firma',
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$pdo->query('SELECT id FROM machines WHERE code = \'' . $code . '\'')->fetchColumn();
    };

    $machineId = $createMachine('VEND-SIGN-01');
    $secondMachineId = $createMachine('VEND-SIGN-02');

    $pdo->prepare(
        'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
         VALUES (:code, :name, \'HYDRAULIC\', :manufacturer, :cost, TRUE) RETURNING id'
    )->execute([
        ':code' => 'VALV-SIGN-01',
        ':name' => 'Electroválvula de firma',
        ':manufacturer' => 'Fabricante de certificación',
        ':cost' => 12.50,
    ]);

    $partId = (int)$pdo->query('SELECT id FROM spare_parts WHERE part_code = \'VALV-SIGN-01\'')->fetchColumn();

    $pdo->prepare(
        'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
         VALUES (:technician_id, :spare_part_id, 10, 0)'
    )->execute([':technician_id' => $technicianId, ':spare_part_id' => $partId]);

    $createIncident = static function (string $ticket, int $machine) use ($pdo, $locationId, $technicianId): int {
        $pdo->prepare(
            'INSERT INTO incidents
                (ticket_code, machine_id, machine_type_snapshot, location_id, category, description, urgency,
                 status, assigned_technician_id, assigned_at, started_at)
             VALUES
                (:ticket, :machine_id, \'COLD_DRINKS\', :location_id, \'OTHER\',
                 \'Fuga en el circuito de agua del módulo de firma\', \'HIGH\',
                 \'IN_PROGRESS\', :technician_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id'
        )->execute([
            ':ticket' => $ticket,
            ':machine_id' => $machine,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
        ]);

        return (int)$pdo->query('SELECT id FROM incidents WHERE ticket_code = \'' . $ticket . '\'')->fetchColumn();
    };

    $incidentId = $createIncident('INC-SIGN-0001', $machineId);
    $unvisitedIncidentId = $createIncident('INC-SIGN-0002', $secondMachineId);

    // ─────────────────────────────────────────────────────────────────────
    // Servicio real
    // ─────────────────────────────────────────────────────────────────────

    $buildService = static function (?IncidentServiceSheetRepositoryInterface $sheetRepo = null) use ($pdo): SparePartTraceabilityService {
        return new SparePartTraceabilityService(
            requestRepo: new PdoSparePartRequestRepository($pdo),
            replacedPartRepo: new PdoIncidentReplacedPartRepository($pdo),
            sparePartRepo: new PdoSparePartRepository($pdo),
            incidentRepo: new PdoIncidentRepository($pdo),
            machineRepo: new PdoMachineRepository($pdo),
            vanStockService: new VanStockService(new PdoVanStockRepository($pdo)),
            serviceSheetRepo: $sheetRepo ?? new PdoIncidentServiceSheetRepository($pdo),
        );
    };

    $sheetRepo = new PdoIncidentServiceSheetRepository($pdo);

    $countParts = static fn (int $id): int => (int)$pdo->query(
        'SELECT COUNT(*) FROM incident_replaced_parts WHERE incident_id = ' . $id
    )->fetchColumn();

    $stockOf = static fn (): int => (int)$pdo->query(
        'SELECT quantity_available FROM technician_van_stocks'
    )->fetchColumn();

    $statusOf = static fn (int $id): string => (string)$pdo->query(
        'SELECT status::text FROM incidents WHERE id = ' . $id
    )->fetchColumn();

    $closePayload = [
        'resolution_diagnosis' => 'Fuga en el racor de entrada del grupo de infusión.',
        'resolution_action' => 'Sustitución de la electroválvula y prueba de estanqueidad del circuito.',
        'replaced_parts_declared' => true,
        'replaced_parts' => [
            ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 2, 'old_part_destination' => 'TALLER'],
        ],
        'service_sheet' => [
            'signer_name' => 'María López Ferrer',
            'signer_document' => '12345678-Z',
            'signature_png_base64' => base64_encode($pngOf(9000)),
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────
    // 1. Atomicidad: la hoja falla, el cierre entero revierte
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 1. Atomicidad del cierre (S5, Art. III) ---\n";

    $failure = null;
    try {
        $buildService(new FailingServiceSheetRepository($sheetRepo))
            ->resolveIncidentWithParts($incidentId, $technicianId, $closePayload);
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $assert('La escritura fallida de la hoja propaga el error del motor', $failure !== null, $failure === null ? 'no se lanzó' : $failure->getMessage());
    $assert('Sin piezas escritas tras el rollback', $countParts($incidentId) === 0, 'líneas=' . $countParts($incidentId));
    $assert('Sin descuento de stock tras el rollback', $stockOf() === 10, 'stock=' . $stockOf());
    $assert('La avería sigue IN_PROGRESS tras el rollback', $statusOf($incidentId) === 'IN_PROGRESS', 'estado=' . $statusOf($incidentId));
    $assert('Sin hoja registrada tras el rollback', $sheetRepo->listByIncidentId($incidentId) === [], json_encode($sheetRepo->listByIncidentId($incidentId)));

    // ─────────────────────────────────────────────────────────────────────
    // 2. Reintento completo
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 2. Reintento sin duplicados (S5) ---\n";

    $response = $buildService()->resolveIncidentWithParts($incidentId, $technicianId, $closePayload);

    $assert('El reintento resuelve la avería', $statusOf($incidentId) === 'RESOLVED', 'estado=' . $statusOf($incidentId));
    $assert('Una sola línea de repuesto (sin duplicar el intento fallido)', $countParts($incidentId) === 1, 'líneas=' . $countParts($incidentId));
    $assert('El stock se descuenta exactamente una vez', $stockOf() === 8, 'stock=' . $stockOf());
    $assert(
        'La respuesta transporta los metadatos de la hoja (§3.4)',
        ($response['service_sheet'] ?? null) === [
            'signed' => true,
            'signer_name' => 'María López Ferrer',
            'recorded_at' => $response['service_sheet']['recorded_at'] ?? '__none__',
        ],
        json_encode($response['service_sheet'] ?? null)
    );
    $assert(
        'La respuesta no filtra el trazo ni el documento congelado',
        !str_contains(json_encode($response) ?: '', 'signature_png') && !str_contains(json_encode($response) ?: '', 'signed_document'),
        json_encode(array_keys($response))
    );

    $sheets = $sheetRepo->listByIncidentId($incidentId);
    $assert('Una sola hoja tras el reintento', count($sheets) === 1, 'hojas=' . count($sheets));
    $sheetId = (int)($sheets[0]['id'] ?? 0);
    $assert('La hoja nace firmada', ($sheets[0]['signed'] ?? null) === true, json_encode($sheets[0] ?? null));

    $resolutionAt = (string)$pdo->query('SELECT resolved_at FROM incidents WHERE id = ' . $incidentId)->fetchColumn();
    $assert(
        'La hoja se ancla a la marca de resolución de la incidencia',
        trim((string)($sheets[0]['resolved_at'] ?? '')) !== ''
            && strtotime((string)$sheets[0]['resolved_at']) === strtotime($resolutionAt),
        'hoja=' . ($sheets[0]['resolved_at'] ?? 'null') . ' incidencia=' . $resolutionAt
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Documento congelado
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 3. Documento congelado e inmune al cambio posterior (RD-10) ---\n";

    $read = $sheetRepo->findDocumentByIncidentId($incidentId);
    $document = $read['document'] ?? [];
    $assert('La hoja vigente lleva el firmante y el documento normalizado', ($read['sheet']['signer_name'] ?? null) === 'María López Ferrer' && ($read['sheet']['signer_document'] ?? null) === '12345678-Z', json_encode($read['sheet'] ?? null));
    $assert('El modelo de lectura devuelve documento y hoja vigente', ($read['current'] ?? false) === true && ($read['sheet']['id'] ?? null) === $sheetId, json_encode(array_keys($read ?? [])));
    $assert('Documento versión 1', ($document['version'] ?? null) === 1, json_encode($document['version'] ?? null));
    $assert('Documento con el ticket de la avería', ($document['ticket_code'] ?? null) === 'INC-SIGN-0001', (string)($document['ticket_code'] ?? ''));
    $assert(
        'Documento con sede, máquina y técnico de la transacción',
        ($document['location']['name'] ?? null) === 'Sede Firma'
            && ($document['machine']['code'] ?? null) === 'VEND-SIGN-01'
            && ($document['technician']['name'] ?? null) === 'Técnico de Firma',
        json_encode(['location' => $document['location'] ?? null, 'machine' => $document['machine'] ?? null, 'technician' => $document['technician'] ?? null])
    );
    $assert(
        'Documento con diagnóstico, acción y piezas declaradas',
        ($document['diagnosis'] ?? null) === $closePayload['resolution_diagnosis']
            && ($document['action'] ?? null) === $closePayload['resolution_action']
            && ($document['parts'][0]['quantity'] ?? null) === 2,
        json_encode($document['parts'] ?? null)
    );

    $beforeRename = json_encode($document);
    $pdo->exec("UPDATE locations SET name = 'Sede Renombrada' WHERE id = " . $locationId);
    $pdo->exec("UPDATE machines SET model = 'Modelo Renombrado' WHERE id = " . $machineId);

    $afterRename = json_encode($sheetRepo->findDocumentByIncidentId($incidentId)['document'] ?? []);
    $assert('Renombrar la sede y la máquina no altera el papel firmado', $beforeRename === $afterRename, $beforeRename === $afterRename ? '' : 'documento mutado');

    // ─────────────────────────────────────────────────────────────────────
    // 4. Trazo íntegro
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 4. Trazo íntegro byte a byte (§6.2) ---\n";

    $traceBase64 = (string)($read['sheet']['png_base64'] ?? '');
    $traceBytes = base64_decode($traceBase64, true);
    $assert('El trazo supera los 8 KB y vuelve íntegro', is_string($traceBytes) && strlen($traceBytes) > 8192, 'bytes=' . (is_string($traceBytes) ? strlen($traceBytes) : -1));
    $assert('Los bytes del PNG son los mismos que se enviaron', $traceBytes === ServiceSheetValidator::normalize(['signer_name' => 'María López Ferrer', 'signer_document' => '12345678Z', 'signature_png_base64' => $closePayload['service_sheet']['signature_png_base64']])['signature_png'], 'png distinto');
    $assert('El documento congelado no viaja en el trazo', !array_key_exists('signature_png', $document), json_encode(array_keys($document)));

    // ─────────────────────────────────────────────────────────────────────
    // 5. Albarán histórico tras reapertura (Q2)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 5. Albarán histórico tras reapertura (Q2, §4.4) ---\n";

    $pdo->prepare(
        'UPDATE incidents SET status = \'IN_PROGRESS\', resolved_at = NULL, resolution_diagnosis = NULL,
                resolution_action = NULL, closed_at = NULL
         WHERE id = :id'
    )->execute([':id' => $incidentId]);

    $assert('Tras reabrir no hay hoja vigente', ($sheetRepo->findDocumentByIncidentId($incidentId)['current'] ?? true) === false, 'sigue vigente');

    $sheetWithAbsence = $buildService()->resolveIncidentWithParts($incidentId, $technicianId, [
        'resolution_diagnosis' => 'Revisión posterior al aviso de fuga del mismo circuito.',
        'resolution_action' => 'Se sustituye la junta del racor y se prueba la estanqueidad del grupo.',
        'replaced_parts_declared' => false,
        'service_sheet' => [
            'absence_reason' => 'El responsable de la sede no estaba presente en el momento del cierre.',
        ],
    ]);

    $history = $sheetRepo->listByIncidentId($incidentId);
    $assert('El historial conserva las dos visitas', count($history) === 2, 'hojas=' . count($history));
    $assert('El albarán firmado de la primera visita sigue disponible', ($history[1]['id'] ?? null) === $sheetId && ($history[1]['signed'] ?? null) === true, json_encode($history[1] ?? null));

    $current = $sheetRepo->findDocumentByIncidentId($incidentId);
    $assert('La hoja vigente es la del segundo cierre', ($current['sheet']['signed'] ?? null) === false && ($current['sheet']['absence_reason'] ?? null) === 'El responsable de la sede no estaba presente en el momento del cierre.', json_encode($current['sheet'] ?? null));
    $assert(
        'La respuesta del segundo cierre anuncia la ausencia',
        ($sheetWithAbsence['service_sheet']['signed'] ?? null) === false
            && array_key_exists('signer_name', $sheetWithAbsence['service_sheet'])
            && $sheetWithAbsence['service_sheet']['signer_name'] === null,
        json_encode($sheetWithAbsence['service_sheet'] ?? null)
    );

    $historical = $sheetRepo->findDocumentByIncidentId($incidentId, $sheetId);
    $assert('Pedir la hoja anterior devuelve su documento, no el vigente', ($historical['sheet']['id'] ?? null) === $sheetId && ($historical['current'] ?? true) === false, json_encode(['sheet' => $historical['sheet']['id'] ?? null, 'current' => $historical['current'] ?? null]));
    $assert(
        'El papel histórico conserva el estado de la sede en el momento de firmar',
        ($historical['document']['location']['name'] ?? null) === 'Sede Firma',
        (string)($historical['document']['location']['name'] ?? '')
    );

    $foreign = $sheetRepo->findDocumentByIncidentId($incidentId, $sheetId + 99999);
    $assert('Una hoja que no pertenece a la avería no se sirve', array_key_exists('sheet', $foreign) && $foreign['sheet'] === null, json_encode($foreign['sheet'] ?? null));

    // ─────────────────────────────────────────────────────────────────────
    // 6. Sin hoja: proyección viva
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 6. Proyección viva cuando no hay hoja (§4.4) ---\n";

    $live = $sheetRepo->findDocumentByIncidentId($unvisitedIncidentId);
    $assert('Una avería sin cierre devuelve la proyección viva', array_key_exists('sheet', $live) && $live['sheet'] === null && ($live['current'] ?? true) === false, json_encode(['sheet' => $live['sheet'] ?? null, 'current' => $live['current'] ?? null]));
    $assert('La proyección viva no inventa documento congelado', ($live['document']['ticket_code'] ?? null) === 'INC-SIGN-0002' && ($live['document']['parts'] ?? 'x') === [], json_encode($live['document'] ?? null));
    $assert('Una avería inexistente no devuelve modelo', $sheetRepo->findDocumentByIncidentId(99999999) === null, 'devolvió modelo');

    // ─────────────────────────────────────────────────────────────────────
    // 7. Inmutabilidad declarada
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 7. Inmutabilidad de la hoja (Art. III.1) ---\n";

    $assert('El repositorio no ofrece update', !method_exists($sheetRepo, 'update'), 'existe update()');
    $assert('El repositorio no ofrece delete', !method_exists($sheetRepo, 'delete'), 'existe delete()');

    $updateSheet = PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $sheetId): void {
        $pdo->exec('UPDATE incident_service_sheets SET signer_name = \'Suplantado\' WHERE id = ' . $sheetId);
    });
    $assert('El motor rechaza reescribir una hoja firmada', $updateSheet !== null && $updateSheet['state'] === '45000', json_encode($updateSheet));

    $insertRaw = static function (array $overrides) use ($pdo, $incidentId, $technicianId): void {
        $params = array_merge([
            'incident_id' => $incidentId,
            'technician_id' => $technicianId,
            'signer_name' => 'María López Ferrer',
            'signer_document' => '12345678Z',
            'signature_png' => null,
            'absence_reason' => null,
            'resolution_at' => '2026-10-22 12:00:00+00',
            'signed_document' => '{"version":1}',
        ], $overrides);

        $stmt = $pdo->prepare(
            'INSERT INTO incident_service_sheets
                (incident_id, technician_id, signer_name, signer_document, signature_png,
                 absence_reason, resolution_at, signed_document)
             VALUES (:incident_id, :technician_id, :signer_name, :signer_document, :signature_png,
                     :absence_reason, :resolution_at, :signed_document)'
        );

        $stmt->bindValue(':incident_id', $params['incident_id'], PDO::PARAM_INT);
        $stmt->bindValue(':technician_id', $params['technician_id'], PDO::PARAM_INT);
        $stmt->bindValue(':signer_name', $params['signer_name'], $params['signer_name'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':signer_document', $params['signer_document'], $params['signer_document'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':absence_reason', $params['absence_reason'], $params['absence_reason'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':signature_png', $params['signature_png'], $params['signature_png'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':resolution_at', $params['resolution_at'], PDO::PARAM_STR);
        $stmt->bindValue(':signed_document', $params['signed_document'], PDO::PARAM_STR);
        $stmt->execute();
    };

    $blankSigner = PostgresFixtureBuilder::expectRejection(static function () use ($insertRaw): void {
        // PNG con la firma mágica y tamaño válido: lo único reprobable es el nombre en blanco.
        $insertRaw(['signer_name' => '   ', 'signature_png' => "\x89PNG\r\n\x1a\n" . str_repeat('B', 128)]);
    });
    $assert('Una firma con firmante en blanco se rechaza en el motor', $blankSigner !== null && $blankSigner['state'] === '23514' && str_contains($blankSigner['message'], 'chk_service_sheet_branch'), json_encode($blankSigner));

    $notPng = PostgresFixtureBuilder::expectRejection(static function () use ($insertRaw): void {
        $insertRaw(['signature_png' => str_repeat('A', 128)]);
    });
    $assert('Una imagen que no es PNG se rechaza en el motor', $notPng !== null && $notPng['state'] === '23514', json_encode($notPng));
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, SHEET_ATOMICITY_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-SIGN-03/04 (HOJA ATÓMICA) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.\n";
echo "======================================================================\n";
exit(1);
