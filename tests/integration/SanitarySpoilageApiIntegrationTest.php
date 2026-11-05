<?php

declare(strict_types=1);

/**
 * SanitarySpoilageApiIntegrationTest · T-SPOILAGE-11
 *
 * Certifica por HTTP la superficie REST del Acta de Merma APPCC (Módulo 29):
 *
 *   1. RBAC: 401 sin sesión, 403 para un rol de sede en los seis endpoints y
 *      403 para el Técnico en los tres endpoints financieros de Coordinación.
 *   2. Opacidad del Art. V.4: las respuestas del Técnico, el historial público
 *      y el Acta Oficial A4 nunca contienen importes ni motivos de ajuste.
 *   3. Validación de la evidencia (Art. V.5): rechazo sin foto, con archivo
 *      falso, con más de 5 MB y éxito con un PNG real.
 *   4. Códigos del contrato: 201, 200, 404, 409 y los 422 de negocio.
 *
 * La suite usa el router de producción y tokens internos firmados de verdad.
 * El escenario vive en un esquema PostgreSQL aislado y se elimina con CASCADE
 * en el `finally`, de modo que no altera la base de la batería ni deja actas.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL local con TLS; sin motor sale con
 * código 2 y no se omite en silencio.
 *
 * Uso:
 *   source deploy/local/postgres/.env.battery
 *   php tests/integration/SanitarySpoilageApiIntegrationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\SanitarySpoilageController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio de esta suite HTTP. */
const SPOILAGE_API_SCHEMA = 't_spoilage29_api';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$financialKeys = [
    'estimated_merchandise_loss_eur',
    'loss_adjusted_by',
    'loss_adjustment_reason',
];

$containsFinancialKey = null;
$containsFinancialKey = static function (mixed $payload) use (&$containsFinancialKey, $financialKeys): bool {
    if (!is_array($payload)) {
        return false;
    }

    foreach ($payload as $key => $value) {
        if (in_array($key, $financialKeys, true)) {
            return true;
        }

        if (is_array($value) && $containsFinancialKey($value)) {
            return true;
        }
    }

    return false;
};

$errorCode = static function (Response $response): string {
    $body = $response->getDecodedBody();

    return is_array($body) ? (string)($body['error']['code'] ?? '') : '';
};

echo "======================================================================\n";
echo " VENDGUARD · T-SPOILAGE-11 · Contratos HTTP del Acta de Merma APPCC\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = null;
$crash = null;
$uploadedUrls = [];
$temporaryFiles = [];

try {
    echo "\n--- Escenario (DDL base + migraciones en esquema aislado) ---\n";

    $pdo = ConnectionFactory::getConnection();
    PostgresFixtureBuilder::createIsolationSchema($pdo, SPOILAGE_API_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $assert(
        'El esquema aislado aplica el DDL y las migraciones del módulo 29',
        $schemaReport['statements'] > 100
        && in_array('025_sanitary_spoilage_records.sql', $schemaReport['migrations'], true),
        $schemaReport['statements'] . ' sentencias · ' . count($schemaReport['migrations']) . ' migraciones'
    );

    $suffix = substr(md5((string)microtime(true) . (string)getmypid()), 0, 8);

    $insertLocation = static function (PDO $pdo, string $suffix): int {
        $siteCode = 'T29API-' . strtoupper($suffix);
        $stmt = $pdo->prepare("
            INSERT INTO locations (site_code, site_code_key, name, address, postal_code)
            VALUES (:site_code, :site_code_key, 'Sede de Certificación HTTP T29', 'Calle de la API 29', '08001')
            RETURNING id
        ");
        $stmt->execute([
            ':site_code' => $siteCode,
            ':site_code_key' => strtolower($siteCode),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertUser = static function (PDO $pdo, string $role, string $name, string $suffix): int {
        $email = strtolower($role) . '.' . $suffix . '@example.test';
        $operatorCode = $role === 'TECHNICIAN' ? 'T29API-' . strtoupper($suffix) : null;
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
            VALUES (:name, :email, :email_key, 'integration-test-only', :role, :operator_code, TRUE)
            RETURNING id
        ");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':email_key' => $email,
            ':role' => $role,
            ':operator_code' => $operatorCode,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertMachine = static function (
        PDO $pdo,
        int $locationId,
        string $code,
        bool $isActive = true,
        string $sanitaryStatus = 'QUARANTINE'
    ): int {
        $activeLiteral = $isActive ? 'TRUE' : 'FALSE';
        $stmt = $pdo->prepare("
            INSERT INTO machines (location_id, code, model, machine_type, floor_wing, is_active, sanitary_status)
            VALUES (:location_id, :code, 'Modelo T29 API', 'PERISHABLE_FOOD', 'Planta 0', {$activeLiteral}, :sanitary_status)
            RETURNING id
        ");
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':sanitary_status' => $sanitaryStatus,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertOpenIncident = static function (
        PDO $pdo,
        int $machineId,
        int $locationId,
        int $technicianId,
        string $ticketCode
    ): int {
        $stmt = $pdo->prepare("
            INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                assigned_at, started_at
            ) VALUES (
                :ticket_code, :machine_id, 'PERISHABLE_FOOD', :location_id,
                :technician_id, 'TEMPERATURE_COLD', :description, 'HIGH', 'ASSIGNED',
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            RETURNING id
        ");
        $stmt->execute([
            ':ticket_code' => $ticketCode,
            ':machine_id' => $machineId,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':description' => 'Avería térmica abierta que debe bloquear el levantamiento de la cuarentena.',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $locationId = $insertLocation($pdo, $suffix);
    $technicianId = $insertUser($pdo, 'TECHNICIAN', 'Técnico de Certificación HTTP T29', $suffix);
    $coordinatorId = $insertUser($pdo, 'COORDINATOR', 'Coordinación de Certificación HTTP T29', $suffix);

    $successMachine = $insertMachine($pdo, $locationId, 'T29API-SUCCESS-' . $suffix);
    $emptyMachine = $insertMachine($pdo, $locationId, 'T29API-EMPTY-' . $suffix);
    $notQuarantineMachine = $insertMachine($pdo, $locationId, 'T29API-NOTQUAR-' . $suffix, true, 'OK');
    $blockedMachine = $insertMachine($pdo, $locationId, 'T29API-BLOCKED-' . $suffix);
    $inactiveMachine = $insertMachine($pdo, $locationId, 'T29API-INACTIVE-' . $suffix, false, 'QUARANTINE');
    $temperatureMachine = $insertMachine($pdo, $locationId, 'T29API-TEMP-' . $suffix);
    $emptyUnconfirmedMachine = $insertMachine($pdo, $locationId, 'T29API-EMPTYUNCONF-' . $suffix);
    $coordinatorMachine = $insertMachine($pdo, $locationId, 'T29API-COORD-' . $suffix);
    $noPhotoMachine = $insertMachine($pdo, $locationId, 'T29API-NOPHOTO-' . $suffix);
    $fakeFileMachine = $insertMachine($pdo, $locationId, 'T29API-FAKE-' . $suffix);
    $bigFileMachine = $insertMachine($pdo, $locationId, 'T29API-BIG-' . $suffix);

    $insertOpenIncident(
        $pdo,
        $blockedMachine,
        $locationId,
        $technicianId,
        'T29API-INC-' . strtoupper($suffix)
    );

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $technician = $userRepo->findById($technicianId);
    $coordinator = $userRepo->findById($coordinatorId);
    if ($technician === null || $coordinator === null) {
        throw new RuntimeException('No se pudieron recuperar los usuarios de certificación recién creados.');
    }

    $technicianToken = $authService->generateInternalToken($technician);
    $coordinatorToken = $authService->generateInternalToken($coordinator);

    $router = AppRouter::create();

    $dispatchJson = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request(
            method: $method,
            path: $path,
            queryParams: $query,
            parsedBody: $body,
            headers: $headers
        ));
    };

    $dispatchMultipart = static function (
        string $method,
        string $path,
        array $body,
        string $token,
        array $file
    ) use ($router): Response {
        return $router->dispatch(new Request(
            method: $method,
            path: $path,
            parsedBody: $body,
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'multipart/form-data',
            ],
            files: ['photo' => $file]
        ));
    };

    $pngContent = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl9sAAAAASUVORK5CYII=',
        true
    );
    if ($pngContent === false) {
        throw new RuntimeException('No se pudo construir la imagen PNG de certificación.');
    }

    $pngPath = tempnam(sys_get_temp_dir(), 't29api_png_');
    $fakePath = tempnam(sys_get_temp_dir(), 't29api_fake_');
    $bigPath = tempnam(sys_get_temp_dir(), 't29api_big_');
    if ($pngPath === false || $fakePath === false || $bigPath === false) {
        throw new RuntimeException('No se pudieron crear los archivos temporales de la suite.');
    }

    $temporaryFiles = [$pngPath, $fakePath, $bigPath];
    file_put_contents($pngPath, $pngContent);
    file_put_contents($fakePath, 'No soy una imagen; soy un archivo de texto renombrado.');
    file_put_contents($bigPath, str_repeat('A', (5 * 1024 * 1024) + 1));

    $filePayload = static function (string $path, string $name, string $mime): array {
        return [
            'name' => $name,
            'type' => $mime,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    };

    $pngFile = $filePayload($pngPath, 'evidencia.png', 'image/png');
    $fakeFile = $filePayload($fakePath, 'evidencia.jpg', 'image/jpeg');
    $bigFile = $filePayload($bigPath, 'evidencia-grande.jpg', 'image/jpeg');

    $basePayload = [
        'discarded_units_count' => 2,
        'empty_machine_confirmed' => false,
        'affected_batch_codes' => 'LOTE-T29API-ALFA',
        'cabin_temp_at_removal' => 11.4,
        'restored_stabilized_temp_c' => 3.2,
        'disposal_method' => 'ON_SITE_ORGANIC_SEALED',
        'sanitary_notes' => 'Retirada completa de 2 unidades expuestas y desinfección de la cabina con producto autorizado.',
    ];

    $zeroPayload = [
        'discarded_units_count' => 0,
        'empty_machine_confirmed' => true,
        'affected_batch_codes' => 'SIN_STOCK_AFECTADO',
        'cabin_temp_at_removal' => 7.5,
        'restored_stabilized_temp_c' => 3.5,
        'disposal_method' => 'NOT_APPLICABLE_EMPTY',
        'sanitary_notes' => 'Máquina vacía verificada, sin género perecedero que retirar y cabina desinfectada.',
    ];

    $rememberUpload = static function (Response $response) use (&$uploadedUrls): void {
        $body = $response->getDecodedBody();
        $url = is_array($body) ? ($body['data']['spoilage_record']['evidence_photo_url'] ?? null) : null;
        if (is_string($url) && str_starts_with($url, '/uploads/')) {
            $uploadedUrls[] = $url;
        }
    };

    echo "\n--- 1. RBAC y defensa en profundidad (Art. V.4) ---\n";

    $anonymousCases = [
        ['POST', "/api/sanitary-quarantines/machines/{$successMachine}/lift", $basePayload],
        ['GET', '/api/sanitary-spoilage-records/999999/official-act', []],
        ['GET', "/api/sanitary-spoilage-records/by-machine/{$successMachine}", []],
        ['GET', '/api/coordinator/sanitary-spoilage-records', []],
        ['PATCH', '/api/coordinator/sanitary-spoilage-records/999999/loss-estimate', [
            'estimated_merchandise_loss_eur' => 10.00,
            'loss_adjustment_reason' => 'Ajuste de prueba sin sesión autenticada.',
        ]],
        ['GET', '/api/coordinator/sanitary-spoilage-records/export-csv', []],
    ];

    foreach ($anonymousCases as [$method, $path, $body]) {
        $response = $dispatchJson($method, $path, $body);
        $assert(
            "Sin sesión: {$method} {$path} => 401",
            $response->getStatusCode() === 401,
            'obtenido ' . $response->getStatusCode()
        );
    }

    $locationController = new SanitarySpoilageController();
    $locationRequest = static function (
        string $method,
        string $path,
        array $body = [],
        array $params = []
    ): Request {
        $request = new Request($method, $path, [], $body);
        $request->setAttribute('user_id', 999999);
        $request->setAttribute('user_role', 'LOCATION_RESPONSIBLE');
        $request->setAttribute('user_name', 'Responsable de sede de prueba');
        $request->setRouteParams($params);

        return $request;
    };

    $locationCases = [
        [
            'POST /lift',
            static fn (): Response => $locationController->lift($locationRequest(
                'POST',
                '/api/sanitary-quarantines/machines/1/lift',
                $basePayload,
                ['machineId' => '1']
            )),
        ],
        [
            'GET official-act',
            static fn (): Response => $locationController->getOfficialAct($locationRequest(
                'GET',
                '/api/sanitary-spoilage-records/1/official-act',
                [],
                ['id' => '1']
            )),
        ],
        [
            'GET by-machine',
            static fn (): Response => $locationController->getByMachine($locationRequest(
                'GET',
                '/api/sanitary-spoilage-records/by-machine/1',
                [],
                ['machineId' => '1']
            )),
        ],
        [
            'GET coordinator list',
            static fn (): Response => $locationController->listForCoordinator($locationRequest(
                'GET',
                '/api/coordinator/sanitary-spoilage-records'
            )),
        ],
        [
            'PATCH loss-estimate',
            static fn (): Response => $locationController->adjustLossEstimate($locationRequest(
                'PATCH',
                '/api/coordinator/sanitary-spoilage-records/1/loss-estimate',
                [
                    'estimated_merchandise_loss_eur' => 10.00,
                    'loss_adjustment_reason' => 'Ajuste de prueba con rol de sede no autorizado.',
                ],
                ['id' => '1']
            )),
        ],
        [
            'GET export-csv',
            static fn (): Response => $locationController->exportCsv($locationRequest(
                'GET',
                '/api/coordinator/sanitary-spoilage-records/export-csv'
            )),
        ],
    ];

    foreach ($locationCases as [$label, $operation]) {
        $response = $operation();
        $assert(
            "Rol LOCATION_RESPONSIBLE: {$label} => 403",
            $response->getStatusCode() === 403,
            'obtenido ' . $response->getStatusCode()
        );
    }

    $technicianCoordinatorCases = [
        ['GET', '/api/coordinator/sanitary-spoilage-records', []],
        ['PATCH', '/api/coordinator/sanitary-spoilage-records/999999/loss-estimate', [
            'estimated_merchandise_loss_eur' => 10.00,
            'loss_adjustment_reason' => 'Ajuste de prueba con rol de técnico no autorizado.',
        ]],
        ['GET', '/api/coordinator/sanitary-spoilage-records/export-csv', []],
    ];

    foreach ($technicianCoordinatorCases as [$method, $path, $body]) {
        $response = $dispatchJson($method, $path, $body, $technicianToken);
        $assert(
            "Técnico en endpoint financiero: {$method} {$path} => 403",
            $response->getStatusCode() === 403,
            'obtenido ' . $response->getStatusCode()
        );
    }

    echo "\n--- 2. Levantamiento válido, opacidad financiera y panel (C4, QA 1) ---\n";

    $liftResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$successMachine}/lift",
        $basePayload,
        $technicianToken,
        $pngFile
    );
    $liftBody = $liftResponse->getDecodedBody();
    $assert('El Técnico levanta la cuarentena con evidencia PNG real => 201', $liftResponse->getStatusCode() === 201);
    $assert(
        'La respuesta del levantamiento devuelve la máquina a OK y desbloquea el QR',
        is_array($liftBody)
        && ($liftBody['data']['machine_sanitary_status'] ?? null) === 'OK'
        && ($liftBody['data']['qr_unblocked'] ?? null) === true
    );
    $assert(
        'La respuesta del Técnico no expone ninguna clave financiera',
        is_array($liftBody) && !$containsFinancialKey($liftBody['data'] ?? [])
    );

    $successRecord = is_array($liftBody) ? ($liftBody['data']['spoilage_record'] ?? []) : [];
    $successRecordId = (int)($successRecord['id'] ?? 0);
    $successRecordCode = (string)($successRecord['record_code'] ?? '');
    $assert(
        'El acta emitida tiene identificador y código oficial',
        $successRecordId > 0 && str_starts_with($successRecordCode, 'ACTA-MERMA-'),
        $successRecordCode
    );
    $rememberUpload($liftResponse);

    $machineStatus = (string)$pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . $successMachine
    )->fetchColumn();
    $assert('La máquina queda realmente en OK en PostgreSQL', $machineStatus === 'OK', $machineStatus);

    $technicianHistory = $dispatchJson(
        'GET',
        "/api/sanitary-spoilage-records/by-machine/{$successMachine}",
        [],
        $technicianToken
    );
    $technicianHistoryBody = $technicianHistory->getDecodedBody();
    $technicianHistoryRecords = is_array($technicianHistoryBody)
        ? ($technicianHistoryBody['data']['records'] ?? [])
        : [];
    $assert('El historial por máquina del Técnico => 200', $technicianHistory->getStatusCode() === 200);
    $assert(
        'El historial del Técnico contiene el acta y omite las claves financieras',
        is_array($technicianHistoryRecords)
        && count($technicianHistoryRecords) === 1
        && (int)($technicianHistoryRecords[0]['id'] ?? 0) === $successRecordId
        && !$containsFinancialKey($technicianHistoryBody['data'] ?? [])
    );

    $coordinatorHistory = $dispatchJson(
        'GET',
        "/api/sanitary-spoilage-records/by-machine/{$successMachine}",
        [],
        $coordinatorToken
    );
    $coordinatorHistoryBody = $coordinatorHistory->getDecodedBody();
    $assert('El historial por máquina de Coordinación => 200', $coordinatorHistory->getStatusCode() === 200);
    $initialLoss = is_array($coordinatorHistoryBody)
        ? (float)($coordinatorHistoryBody['data']['records'][0]['estimated_merchandise_loss_eur'] ?? -1.0)
        : -1.0;
    $assert(
        'Coordinación recibe el importe de la merma en el historial',
        is_array($coordinatorHistoryBody)
        && $containsFinancialKey($coordinatorHistoryBody['data'] ?? [])
        && $initialLoss >= 0.0
    );

    $officialResponse = $dispatchJson(
        'GET',
        "/api/sanitary-spoilage-records/{$successRecordId}/official-act",
        [],
        $coordinatorToken
    );
    $officialBody = $officialResponse->getDecodedBody();
    $assert('El Acta Oficial A4 abierta por Coordinación => 200', $officialResponse->getStatusCode() === 200);
    $assert(
        'El Acta Oficial A4 omite importes y motivos de ajuste incluso para Coordinación',
        is_array($officialBody) && !$containsFinancialKey($officialBody['data'] ?? [])
    );

    $panelResponse = $dispatchJson('GET', '/api/coordinator/sanitary-spoilage-records', [], $coordinatorToken);
    $panelBody = $panelResponse->getDecodedBody();
    $assert('El panel de Coordinación => 200', $panelResponse->getStatusCode() === 200);
    $assert(
        'El panel publica KPIs, cuarentenas activas y actas con importes',
        is_array($panelBody)
        && isset($panelBody['data']['kpis'])
        && isset($panelBody['data']['active_quarantined_machines'])
        && isset($panelBody['data']['records'])
        && $containsFinancialKey($panelBody['data'])
    );

    $csvResponse = $dispatchJson(
        'GET',
        '/api/coordinator/sanitary-spoilage-records/export-csv',
        [],
        $coordinatorToken
    );
    $csvBody = $csvResponse->getBody();
    $assert('La exportación CSV => 200', $csvResponse->getStatusCode() === 200);
    $assert(
        'La exportación declara Content-Type: text/csv; charset=UTF-8',
        $csvResponse->getHeader('Content-Type') === 'text/csv; charset=UTF-8',
        (string)$csvResponse->getHeader('Content-Type')
    );
    $assert(
        'El CSV lleva BOM UTF-8, dos secciones y el acta emitida',
        str_starts_with($csvBody, "\xEF\xBB\xBF")
        && str_contains($csvBody, 'SECCIÓN 1')
        && str_contains($csvBody, 'SECCIÓN 2')
        && str_contains($csvBody, $successRecordCode)
    );

    $identicalResponse = $dispatchJson(
        'PATCH',
        "/api/coordinator/sanitary-spoilage-records/{$successRecordId}/loss-estimate",
        [
            'estimated_merchandise_loss_eur' => $initialLoss,
            'loss_adjustment_reason' => 'Ajuste idéntico al importe vigente para certificar el rechazo del contrato.',
        ],
        $coordinatorToken
    );
    $assert(
        'Un ajuste idéntico al importe vigente => 422 IDENTICAL_LOSS_ESTIMATE',
        $identicalResponse->getStatusCode() === 422
        && $errorCode($identicalResponse) === 'IDENTICAL_LOSS_ESTIMATE',
        $errorCode($identicalResponse)
    );

    $newLoss = round($initialLoss + 1.25, 2);
    $adjustResponse = $dispatchJson(
        'PATCH',
        "/api/coordinator/sanitary-spoilage-records/{$successRecordId}/loss-estimate",
        [
            'estimated_merchandise_loss_eur' => $newLoss,
            'loss_adjustment_reason' => 'Ajuste según albarán de reposición del lote afectado por la merma.',
        ],
        $coordinatorToken
    );
    $adjustBody = $adjustResponse->getDecodedBody();
    $assert('El ajuste económico válido de Coordinación => 200', $adjustResponse->getStatusCode() === 200);
    $assert(
        'La respuesta del ajuste devuelve el importe actualizado',
        is_array($adjustBody)
        && abs((float)($adjustBody['data']['spoilage_record']['estimated_merchandise_loss_eur'] ?? -1) - $newLoss) < 0.001
    );

    echo "\n--- 3. Validación de la evidencia fotográfica (Art. V.5, D3) ---\n";

    $noPhotoResponse = $dispatchJson(
        'POST',
        "/api/sanitary-quarantines/machines/{$noPhotoMachine}/lift",
        $zeroPayload,
        $technicianToken
    );
    $assert(
        'Sin foto, incluso con 0 unidades, => 422 EVIDENCE_PHOTO_REQUIRED',
        $noPhotoResponse->getStatusCode() === 422
        && $errorCode($noPhotoResponse) === 'EVIDENCE_PHOTO_REQUIRED',
        $errorCode($noPhotoResponse)
    );

    $fakeResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$fakeFileMachine}/lift",
        $basePayload,
        $technicianToken,
        $fakeFile
    );
    $assert(
        'Un texto renombrado a .jpg => 422 INVALID_SPOILAGE_RECORD',
        $fakeResponse->getStatusCode() === 422
        && $errorCode($fakeResponse) === 'INVALID_SPOILAGE_RECORD',
        $errorCode($fakeResponse)
    );

    $bigResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$bigFileMachine}/lift",
        $basePayload,
        $technicianToken,
        $bigFile
    );
    $assert(
        'Un archivo de más de 5 MB => 422 INVALID_SPOILAGE_RECORD',
        $bigResponse->getStatusCode() === 422
        && $errorCode($bigResponse) === 'INVALID_SPOILAGE_RECORD',
        $errorCode($bigResponse)
    );

    $fakeMachineStatus = (string)$pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . $fakeFileMachine
    )->fetchColumn();
    $bigMachineStatus = (string)$pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . $bigFileMachine
    )->fetchColumn();
    $assert(
        'Los rechazos de evidencia no alteran la cuarentena de la máquina',
        $fakeMachineStatus === 'QUARANTINE' && $bigMachineStatus === 'QUARANTINE'
    );

    $emptyLiftResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$emptyMachine}/lift",
        $zeroPayload,
        $technicianToken,
        $pngFile
    );
    $emptyLiftBody = $emptyLiftResponse->getDecodedBody();
    $emptyRecord = is_array($emptyLiftBody) ? ($emptyLiftBody['data']['spoilage_record'] ?? []) : [];
    $emptyRecordId = (int)($emptyRecord['id'] ?? 0);
    $assert('Una máquina vacía con confirmación explícita => 201', $emptyLiftResponse->getStatusCode() === 201);
    $rememberUpload($emptyLiftResponse);

    $emptyAdjustResponse = $dispatchJson(
        'PATCH',
        "/api/coordinator/sanitary-spoilage-records/{$emptyRecordId}/loss-estimate",
        [
            'estimated_merchandise_loss_eur' => 10.00,
            'loss_adjustment_reason' => 'Ajuste sobre un acta sin unidades destruidas para certificar su rechazo.',
        ],
        $coordinatorToken
    );
    $assert(
        'Ajustar un acta de 0 unidades => 422 EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED',
        $emptyAdjustResponse->getStatusCode() === 422
        && $errorCode($emptyAdjustResponse) === 'EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED',
        $errorCode($emptyAdjustResponse)
    );

    echo "\n--- 4. Códigos de estado del contrato (201, 200, 404, 409, 422) ---\n";

    $notQuarantineResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$notQuarantineMachine}/lift",
        $basePayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Máquina fuera de cuarentena => 409 MACHINE_NOT_IN_QUARANTINE',
        $notQuarantineResponse->getStatusCode() === 409
        && $errorCode($notQuarantineResponse) === 'MACHINE_NOT_IN_QUARANTINE',
        $errorCode($notQuarantineResponse)
    );

    $blockedResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$blockedMachine}/lift",
        $basePayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Avería correctiva abierta => 409 ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT',
        $blockedResponse->getStatusCode() === 409
        && $errorCode($blockedResponse) === 'ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT',
        $errorCode($blockedResponse)
    );

    $inactiveResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$inactiveMachine}/lift",
        $basePayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Máquina dada de baja => 409 MACHINE_INACTIVE',
        $inactiveResponse->getStatusCode() === 409
        && $errorCode($inactiveResponse) === 'MACHINE_INACTIVE',
        $errorCode($inactiveResponse)
    );

    $temperaturePayload = $basePayload;
    $temperaturePayload['restored_stabilized_temp_c'] = 5.0;
    $temperatureResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$temperatureMachine}/lift",
        $temperaturePayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Temperatura estabilizada fuera del techo sanitario => 422 RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT',
        $temperatureResponse->getStatusCode() === 422
        && $errorCode($temperatureResponse) === 'RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT',
        $errorCode($temperatureResponse)
    );

    $unconfirmedPayload = $zeroPayload;
    $unconfirmedPayload['empty_machine_confirmed'] = false;
    $unconfirmedResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$emptyUnconfirmedMachine}/lift",
        $unconfirmedPayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Cero unidades sin confirmación de máquina vacía => 422 EMPTY_MACHINE_CONFIRMATION_REQUIRED',
        $unconfirmedResponse->getStatusCode() === 422
        && $errorCode($unconfirmedResponse) === 'EMPTY_MACHINE_CONFIRMATION_REQUIRED',
        $errorCode($unconfirmedResponse)
    );

    $missingTechnicianResponse = $dispatchMultipart(
        'POST',
        "/api/sanitary-quarantines/machines/{$coordinatorMachine}/lift",
        $basePayload,
        $coordinatorToken,
        $pngFile
    );
    $assert(
        'Coordinación sin técnico ejecutor => 422 EXECUTING_TECHNICIAN_REQUIRED',
        $missingTechnicianResponse->getStatusCode() === 422
        && $errorCode($missingTechnicianResponse) === 'EXECUTING_TECHNICIAN_REQUIRED',
        $errorCode($missingTechnicianResponse)
    );

    $missingMachineResponse = $dispatchMultipart(
        'POST',
        '/api/sanitary-quarantines/machines/999999/lift',
        $basePayload,
        $technicianToken,
        $pngFile
    );
    $assert(
        'Levantamiento sobre máquina inexistente => 404 MACHINE_NOT_FOUND',
        $missingMachineResponse->getStatusCode() === 404
        && $errorCode($missingMachineResponse) === 'MACHINE_NOT_FOUND',
        $errorCode($missingMachineResponse)
    );

    $missingActResponse = $dispatchJson(
        'GET',
        '/api/sanitary-spoilage-records/999999/official-act',
        [],
        $coordinatorToken
    );
    $assert(
        'Acta Oficial inexistente => 404 SPOILAGE_RECORD_NOT_FOUND',
        $missingActResponse->getStatusCode() === 404
        && $errorCode($missingActResponse) === 'SPOILAGE_RECORD_NOT_FOUND',
        $errorCode($missingActResponse)
    );

    $missingHistoryResponse = $dispatchJson(
        'GET',
        '/api/sanitary-spoilage-records/by-machine/999999',
        [],
        $technicianToken
    );
    $assert(
        'Historial de máquina inexistente => 404 MACHINE_NOT_FOUND',
        $missingHistoryResponse->getStatusCode() === 404
        && $errorCode($missingHistoryResponse) === 'MACHINE_NOT_FOUND',
        $errorCode($missingHistoryResponse)
    );

    $invalidDateResponse = $dispatchJson(
        'GET',
        '/api/coordinator/sanitary-spoilage-records',
        [],
        $coordinatorToken,
        ['date_from' => '2026-12-31', 'date_to' => '2026-01-01']
    );
    $assert(
        'Rango de fechas invertido => 422 INVALID_DATE_RANGE',
        $invalidDateResponse->getStatusCode() === 422
        && $errorCode($invalidDateResponse) === 'INVALID_DATE_RANGE',
        $errorCode($invalidDateResponse)
    );

    $assert(
        'Los rechazos dejan las máquinas en cuarentena y sin actas nuevas',
        (string)$pdo->query(
            'SELECT sanitary_status FROM machines WHERE id = ' . $temperatureMachine
        )->fetchColumn() === 'QUARANTINE'
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM sanitary_spoilage_records WHERE machine_id = ' . $temperatureMachine
        )->fetchColumn() === 0
    );
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[EXCEPCIÓN NO CONTROLADA] " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . "\n");
    $failures++;
} finally {
    foreach ($uploadedUrls as $url) {
        $path = $projectRoot . '/public' . $url;
        if (is_file($path)) {
            unlink($path);
        }
    }

    foreach ($temporaryFiles as $temporaryFile) {
        if (is_string($temporaryFile) && is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }

    if ($pdo instanceof PDO) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, SPOILAGE_API_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-SPOILAGE-11 · RESULTADO\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
    exit(1);
}

echo " RESULTADO: 100% EN VERDE.\n";
