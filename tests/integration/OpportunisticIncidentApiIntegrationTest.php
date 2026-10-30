<?php

declare(strict_types=1);

/**
 * OpportunisticIncidentApiIntegrationTest (Módulo 16 · T-OPP-09)
 *
 * Certifica, por el **enrutador real con sus middlewares** y contra PostgreSQL,
 * las cuatro superficies HTTP que la tarea exige operativas:
 *
 *   1. `GET /api/technician/my-route` con y sin `?include_opportunistic_context=1`
 *      (retrocompatibilidad estricta de la raíz y contexto de sedes habilitadas).
 *   2. `GET /api/technician/locations/{location_id}/opportunistic-machines`
 *      (catálogo, acciones por máquina, conflicto de coexistencia y aviso del Art. II).
 *   3. `POST /api/technician/opportunistic/incidents` (Flujo A, incluida la
 *      privacidad del Art. V.4, la carga segura del Art. V.5 y el choque
 *      concurrente `409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD`).
 *   4. `POST /api/technician/opportunistic/incidents/{id}/assume` (Flujo B:
 *      asunción directa, traspaso con nota interna, bloqueo del Art. V.3,
 *      reapertura en garantía y sustitución por Avería Crónica).
 *
 * Cierra con la extensión de `POST /api/technician/incidents/{id}/resolve`
 * (RF-06.1): la máquina bloqueada por falta de acceso vuelve al parque tras la
 * reparación in situ, salvo que la puerta sanitaria del Módulo 11 siga vigente
 * (RF-06.1.2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
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
echo " VendGuard · Módulo 16 · T-OPP-09 · API de avería oportunista in situ\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Restitución del parque ante cualquier salida (contrato de limpieza §8.3): esta
// suite crea expedientes y bloqueos de máquina fuera del catálogo canónico.
TestDataCleaner::registerShutdownRestitution();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);

$assertions = 0;
$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
    }
};

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$marta = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$assert(
    '0.1 Técnicos y coordinación semilla disponibles',
    $jordi instanceof User && $marta instanceof User && $coordinator instanceof User
);
if (!$jordi instanceof User || !$marta instanceof User || !$coordinator instanceof User) {
    echo "\nNo se puede certificar sin las cuentas semilla.\n";
    exit(1);
}

$auth = new AuthService($locationRepo, $userRepo);
$techToken = $auth->generateInternalToken($jordi);
$coordinatorToken = $auth->generateInternalToken($coordinator);

$machines = [];
foreach (['VEND-0301', 'VEND-0302', 'VEND-0501', 'VEND-0502'] as $code) {
    $machine = $machineRepo->findByCode($code);
    $assert("0.2 Máquina semilla {$code} disponible", $machine !== null);
    if ($machine !== null) {
        $machines[$code] = $machine;
    }
}
if (count($machines) !== 4) {
    echo "\nNo se puede certificar sin el parque semilla del escenario.\n";
    exit(1);
}

$locationThree = (int)$machines['VEND-0301']->getLocationId();
$locationFive = (int)$machines['VEND-0501']->getLocationId();
$assert('0.3 Las máquinas 03xx y 05xx viven en sedes distintas', $locationThree !== $locationFive);

/**
 * Devuelve el parque del escenario a su estado canónico: expedientes fuera y
 * máquinas operativas. Evita que un caso herede el estado del anterior.
 *
 * @param list<string> $codes
 */
$resetScenario = function (array $codes = ['VEND-0301', 'VEND-0302', 'VEND-0501', 'VEND-0502']) use ($pdo, $machines): void {
    foreach ($codes as $code) {
        TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines[$code]->getId());
        $pdo->prepare(
            "UPDATE machines
                SET is_active = TRUE,
                    is_blocked_no_access = FALSE,
                    sanitary_status = 'OK',
                    notes = NULL
              WHERE id = :id"
        )->execute([':id' => $machines[$code]->getId()]);
    }
};

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

/**
 * Construye un expediente de fixture del módulo.
 *
 * @param array<string, mixed> $overrides
 */
$fixtureIncident = static function (array $overrides = []) use ($stamp): Incident {
    $base = [
        'id' => null,
        'ticketCode' => 'INC-2026-T09' . random_int(100, 999),
        'machineId' => 1,
        'locationId' => 1,
        'category' => IncidentCategory::PRODUCT_JAM,
        'description' => 'Atasco en espiral detectado durante la visita a la sede.',
        'urgency' => UrgencyLevel::MEDIUM,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => null,
        'reporterName' => 'Fixture T-OPP-09',
        'assignedAt' => $stamp(),
        'cycleStartedAt' => $stamp(),
    ];

    return new Incident(...array_merge($base, $overrides));
};

$dispatch = static function (string $method, string $path, ?string $token = null, array $body = [], array $query = [], array $files = []) use ($router): array {
    $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        queryParams: $query,
        parsedBody: $body,
        headers: $headers,
        files: $files
    ));
    $decoded = $response->getDecodedBody() ?? [];

    return [$response->getStatusCode(), $decoded];
};

$errorCode = static fn (array $body): ?string => $body['error']['code'] ?? null;
$ticketCodes = static function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT template, channel, status FROM notification_outbox WHERE incident_id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$machineRow = static function (int $machineId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM machines WHERE id = :id');
    $stmt->execute([':id' => $machineId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$auditCount = static function (string $entityType, int $entityId, string $action) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE entity_type = :type AND entity_id = :id AND action = :action');
    $stmt->execute([':type' => $entityType, ':id' => $entityId, ':action' => $action]);

    return (int)$stmt->fetchColumn();
};

$resetScenario();

// ─────────────────────────────────────────────────────────────────────────
// ANCLA DE HABILITACIÓN: una parada ASSIGNED del técnico en la sede 3.
// ─────────────────────────────────────────────────────────────────────────
$anchor = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09AN',
        'machineId' => $machines['VEND-0301']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $jordi->getId(),
    ]),
    $jordi->getId(),
    'Parada ancla de la certificación T-OPP-09'
);
$assert('0.4 Parada ancla ASSIGNED creada para habilitar la sede', $anchor->getId() !== null);

// =========================================================================
// CASO 1 · Control de acceso y catálogo de la sede (RF-01, RF-03)
// =========================================================================
echo "\n--- Caso 1: Catálogo de máquinas de la sede habilitada ---\n";

[$status] = $dispatch('GET', "/api/technician/locations/{$locationThree}/opportunistic-machines");
$assert('1.1 Sin token el catálogo responde 401', $status === 401);

[$status, $body] = $dispatch(
    'GET',
    "/api/technician/locations/{$locationThree}/opportunistic-machines",
    $coordinatorToken
);
$assert('1.2 Un coordinador no accede al canal del técnico (403)', $status === 403 && $errorCode($body) === 'FORBIDDEN');

[$status, $body] = $dispatch('GET', '/api/technician/locations/abc/opportunistic-machines', $techToken);
$assert('1.3 Identificador de sede no numérico => 400 INVALID_LOCATION_ID', $status === 400 && $errorCode($body) === 'INVALID_LOCATION_ID');

[$status, $body] = $dispatch('GET', '/api/technician/locations/999999/opportunistic-machines', $techToken);
$assert('1.4 Sede inexistente => 404 LOCATION_NOT_FOUND', $status === 404 && $errorCode($body) === 'LOCATION_NOT_FOUND');

[$status, $body] = $dispatch('GET', "/api/technician/locations/{$locationFive}/opportunistic-machines", $techToken);
$assert(
    '1.5 Sede sin parada ni gracia => 403 LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC',
    $status === 403 && $errorCode($body) === 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC'
);

[$status, $body] = $dispatch('GET', "/api/technician/locations/{$locationThree}/opportunistic-machines", $techToken);
$catalog = $body['data'] ?? [];
$assert('1.6 El catálogo de la sede habilitada responde 200', $status === 200);
$assert(
    '1.7 La habilitación viaja como ACTIVE_STOP con la sede completa',
    ($catalog['location']['id'] ?? null) === $locationThree
        && ($catalog['location']['enablement_type'] ?? null) === 'ACTIVE_STOP'
);
$assert(
    '1.8 El contrato expone las cuatro raíces',
    array_keys($catalog) === ['location', 'active_intervention_conflict', 'cold_chain_route_warning', 'machines']
);

$actionsByCode = [];
foreach ($catalog['machines'] ?? [] as $machine) {
    $actionsByCode[(string)($machine['code'] ?? '')] = $machine['opportunistic_action'] ?? null;
}
$assert('1.9 La máquina de la parada propia propone ASSUME_DIRECT', ($actionsByCode['VEND-0301'] ?? null) === 'ASSUME_DIRECT');
$assert('1.10 La máquina limpia propone CREATE_NEW', ($actionsByCode['VEND-0302'] ?? null) === 'CREATE_NEW');

// =========================================================================
// CASO 2 · GET /api/technician/my-route (retrocompatibilidad + contexto)
// =========================================================================
echo "\n--- Caso 2: Mi Ruta con contexto oportunista (RF-01.2, RF-03.1) ---\n";

[$status, $body] = $dispatch('GET', '/api/technician/my-route', $techToken);
$plainData = $body['data'] ?? null;
$assert(
    '2.1 Sin bandera la raíz sigue siendo el array de paradas',
    $status === 200 && is_array($plainData) && array_is_list($plainData)
);
$assert(
    '2.2 La parada publica los campos oportunistas aunque estén vacíos',
    isset($plainData[0])
        && array_key_exists('opportunistic_mode', $plainData[0])
        && array_key_exists('chronic_predecessor_incident_id', $plainData[0])
);

[$status, $body] = $dispatch(
    'GET',
    '/api/technician/my-route',
    $techToken,
    [],
    ['include_opportunistic_context' => '1']
);
$context = $body['data'] ?? [];
$assert(
    '2.3 Con bandera la raíz es el contexto {stops, enabled_locations, pending_critical_stops_other_locations}',
    $status === 200
        && is_array($context)
        && array_keys($context) === ['stops', 'enabled_locations', 'pending_critical_stops_other_locations']
);
$assert(
    '2.4 La sede de la parada aparece habilitada por ACTIVE_STOP',
    (bool)array_filter(
        $context['enabled_locations'] ?? [],
        static fn (array $location): bool => (int)($location['location_id'] ?? 0) === $locationThree
            && ($location['enablement_type'] ?? null) === 'ACTIVE_STOP'
    )
);
$assert('2.5 Sin paradas críticas postergadas la colección viaja vacía', ($context['pending_critical_stops_other_locations'] ?? null) === []);

// =========================================================================
// CASO 3 · POST /opportunistic/incidents — Flujo A (RF-04)
// =========================================================================
echo "\n--- Caso 3: Alta Oportunista In Situ ---\n";

$baseCreate = [
    'machine_id' => $machines['VEND-0302']->getId(),
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Atasco en la espiral B4 detectado al pasar por delante.',
];

[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    $baseCreate + ['iban' => 'ES00 0000 0000 0000 0000 0000']
);
$assert(
    '3.1 Un IBAN en el parte se rechaza con 422 CASH_REFUND_PRIVACY_VIOLATION',
    $status === 422
        && $errorCode($body) === 'CASH_REFUND_PRIVACY_VIOLATION'
        && ($body['error']['details']['violating_fields'] ?? null) === ['iban']
);

$tempPhoto = tempnam(sys_get_temp_dir(), 'vg_t09_') . '.txt';
file_put_contents($tempPhoto, 'no soy una imagen de verdad');
[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    $baseCreate,
    [],
    ['photo' => [
        'name' => 'evidencia.txt',
        'type' => 'text/plain',
        'tmp_name' => $tempPhoto,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempPhoto),
    ]]
);
@unlink($tempPhoto);
$assert(
    '3.2 Un adjunto que no es imagen se rechaza con 422 INVALID_FILE_TYPE (Art. V.5)',
    $status === 422
        && $errorCode($body) === 'INVALID_FILE_TYPE'
        && ($body['error']['details']['form_data']['machine_id'] ?? null) === $machines['VEND-0302']->getId()
);

[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    ['machine_id' => $machines['VEND-0302']->getId(), 'category' => 'NO_EXISTE', 'origin_reason' => 'TECHNICIAN_DETECTION', 'description' => 'Descripción suficientemente larga.']
);
$assert('3.3 Una categoría fuera del catálogo responde 422 INVALID_CATEGORY', $status === 422 && $errorCode($body) === 'INVALID_CATEGORY');

[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    ['machine_id' => $machines['VEND-0302']->getId(), 'category' => 'PRODUCT_JAM', 'origin_reason' => 'TECHNICIAN_DETECTION', 'description' => 'corto']
);
$assert('3.4 Una descripción de menos de 10 caracteres responde 422 DESCRIPTION_TOO_SHORT', $status === 422 && $errorCode($body) === 'DESCRIPTION_TOO_SHORT');

$pdo->prepare('UPDATE machines SET is_active = FALSE, is_blocked_no_access = FALSE WHERE id = :id')
    ->execute([':id' => $machines['VEND-0502']->getId()]);
[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    ['machine_id' => $machines['VEND-0502']->getId()] + $baseCreate
);
$assert('3.5 Una baja definitiva responde 422 MACHINE_INACTIVE (RF-01.5)', $status === 422 && $errorCode($body) === 'MACHINE_INACTIVE');
$pdo->prepare('UPDATE machines SET is_active = TRUE WHERE id = :id')->execute([':id' => $machines['VEND-0502']->getId()]);

[$status, $body] = $dispatch('POST', '/api/technician/opportunistic/incidents', $techToken, $baseCreate);
$created = $body['data'] ?? [];
$createdId = (int)($created['id'] ?? 0);
$assert('3.6 El alta responde 201 y nace directamente en curso', $status === 201 && ($created['status'] ?? null) === 'IN_PROGRESS');
$assert(
    '3.7 La modalidad y el submotivo del Flujo A viajan en la respuesta',
    ($created['opportunistic_mode'] ?? null) === 'NEW'
        && ($created['opportunistic_mode_label'] ?? null) === 'Oportunista In Situ · Nueva'
        && ($created['opportunistic_origin_reason'] ?? null) === 'TECHNICIAN_DETECTION'
);
$assert(
    '3.8 El alta queda firmada en auditoría y avisa a la Sede una sola vez',
    $auditCount('TICKET', $createdId, 'INCIDENT_CREATED_OPPORTUNISTIC') === 1
        && count($ticketCodes($createdId)) >= 1
);

[$status, $body] = $dispatch('POST', '/api/technician/opportunistic/incidents', $techToken, $baseCreate);
$assert(
    '3.9 El choque concurrente responde 409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD',
    $status === 409 && $errorCode($body) === 'ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD'
);
$assert(
    '3.10 El 409 entrega el expediente existente y la acción con la que asumirlo',
    (int)($body['error']['details']['existing_incident']['id'] ?? 0) === $createdId
        && ($body['error']['details']['opportunistic_action'] ?? null) === 'CURRENT_TECHNICIAN_IN_PROGRESS'
);

// G4 · La foto VÁLIDA debe recorrer el camino completo: adjunto multipart real,
// escritura en `public/uploads/` y `photo_path` persistido en el expediente. La
// suite ya certifica el rechazo de un adjunto falso (3.2); este caso cierra la
// mitad positiva de la costura, que es la que usa el técnico en ruta.
$resetScenario(['VEND-0302']);
$validJpgBytes = hex2bin(
    'ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9'
);
$validPhoto = tempnam(sys_get_temp_dir(), 'vg_t09_photo_') . '.jpg';
file_put_contents($validPhoto, $validJpgBytes);
[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    $baseCreate,
    [],
    ['photo' => [
        'name' => 'evidencia_oportunista.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $validPhoto,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($validJpgBytes),
    ]]
);
@unlink($validPhoto);
$photoIncident = $body['data'] ?? [];
$photoIncidentId = (int)($photoIncident['id'] ?? 0);
$photoRow = $photoIncidentId > 0
    ? $pdo->query('SELECT photo_path FROM incidents WHERE id = ' . $photoIncidentId)->fetch(PDO::FETCH_ASSOC)
    : false;
$photoPath = is_array($photoRow) ? (string)($photoRow['photo_path'] ?? '') : '';
$photoFullPath = __DIR__ . '/../../public' . $photoPath;
$assert(
    '3.11 Una foto JPEG válida abre el expediente en curso y persiste su ruta',
    $status === 201
        && ($photoIncident['status'] ?? null) === 'IN_PROGRESS'
        && (bool)preg_match('#^/uploads/[a-f0-9]{32}\.jpg$#', $photoPath),
    'HTTP ' . $status . ' · photo_path=' . $photoPath
);
$assert(
    '3.12 El archivo de la evidencia existe físicamente y no queda huérfano',
    $photoPath !== '' && is_file($photoFullPath) && filesize($photoFullPath) === strlen($validJpgBytes)
);
if ($photoPath !== '' && is_file($photoFullPath)) {
    @unlink($photoFullPath);
}

// =========================================================================
// CASO 4 · POST /opportunistic/incidents/{id}/assume — Flujo B (RF-05)
// =========================================================================
echo "\n--- Caso 4: Asunción y reapertura oportunista ---\n";

$resetScenario(['VEND-0302']);
$registered = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09B1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::REGISTERED,
        'assignedTechnicianId' => null,
        'assignedAt' => null,
    ]),
    null,
    'Aviso registrado por la sede'
);

[$status, $body] = $dispatch('POST', "/api/technician/opportunistic/incidents/{$registered->getId()}/assume", $techToken);
$assumed = $body['data'] ?? [];
$assert(
    '4.1 La asunción directa responde 200 y deja el ticket ASSUMED en curso',
    $status === 200
        && ($assumed['status'] ?? null) === 'IN_PROGRESS'
        && ($assumed['opportunistic_mode'] ?? null) === 'ASSUMED'
        && ($assumed['opportunistic_origin_reason'] ?? null) === 'EXISTING_TICKET_CLAIM'
);
$assert(
    '4.2 Asumir un ticket ya abierto no envía correo redundante a la Sede (RF-06.3.1)',
    $ticketCodes((int)$registered->getId()) === []
);

$resetScenario(['VEND-0302']);
$assignedToMarta = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09B2',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $marta->getId(),
    ]),
    null,
    'Asignación ordinaria a Marta'
);

[$status, $body] = $dispatch('POST', "/api/technician/opportunistic/incidents/{$assignedToMarta->getId()}/assume", $techToken);
$assert(
    '4.3 El traspaso sin confirmación expresa responde 409 TAKEOVER_CONFIRMATION_REQUIRED',
    $status === 409
        && $errorCode($body) === 'TAKEOVER_CONFIRMATION_REQUIRED'
        && (int)($body['error']['details']['previous_technician_id'] ?? 0) === $marta->getId()
);

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/opportunistic/incidents/{$assignedToMarta->getId()}/assume",
    $techToken,
    [
        'confirm_takeover_from_other_tech' => true,
        'takeover_internal_note' => 'Reviso el monedero por presencia física; Marta aún no había salido.',
    ]
);
$takenOver = $body['data'] ?? [];
$noteStmt = $pdo->prepare('SELECT is_internal FROM incident_comments WHERE incident_id = :id ORDER BY id DESC LIMIT 1');
$noteStmt->execute([':id' => $assignedToMarta->getId()]);
$noteRow = $noteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$assert(
    '4.4 El traspaso confirmado deja el ticket a nombre del técnico presente',
    $status === 200
        && (int)($takenOver['assigned_technician_id'] ?? 0) === $jordi->getId()
        && (int)($takenOver['previous_technician_id'] ?? 0) === $marta->getId()
);
$assert('4.5 La nota de traspaso se guarda como comentario interno (Art. V.4)', (bool)($noteRow['is_internal'] ?? false));

$resetScenario(['VEND-0302']);
$inProgressByMarta = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09B3',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::IN_PROGRESS,
        'assignedTechnicianId' => $marta->getId(),
        'startedAt' => $stamp(),
    ]),
    $marta->getId(),
    'Intervención en curso de Marta'
);
[$status, $body] = $dispatch('POST', "/api/technician/opportunistic/incidents/{$inProgressByMarta->getId()}/assume", $techToken);
$assert(
    '4.6 Una máquina en curso de otro técnico responde 409 INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN (Art. V.3)',
    $status === 409 && $errorCode($body) === 'INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN'
);

$resetScenario(['VEND-0302']);
$resolved = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09C1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::RESOLVED,
        'assignedTechnicianId' => $jordi->getId(),
        'resolvedAt' => $stamp(-3600),
        'resolutionDiagnosis' => 'Diagnóstico de la primera reparación del monedero.',
        'resolutionAction' => 'Acción correctiva aplicada en el primer servicio técnico.',
    ]),
    $jordi->getId(),
    'Aviso resuelto que reaparecerá en garantía'
);

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/opportunistic/incidents/{$resolved->getId()}/assume",
    $techToken,
    ['reopen_reason' => 'falla']
);
$assert('4.7 Un motivo de reincidencia corto responde 422 REOPEN_REASON_TOO_SHORT', $status === 422 && $errorCode($body) === 'REOPEN_REASON_TOO_SHORT');

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/opportunistic/incidents/{$resolved->getId()}/assume",
    $techToken,
    ['reopen_reason' => 'El monedero vuelve a no devolver cambio tras la reparación.']
);
$reopened = $body['data'] ?? [];
$assert(
    '4.8 La 1.ª reincidencia reabre el mismo ticket en garantía (RF-05.4.1)',
    $status === 200
        && (int)($reopened['id'] ?? 0) === (int)$resolved->getId()
        && ($reopened['status'] ?? null) === 'IN_PROGRESS'
        && ($reopened['chronic_replacement_created'] ?? null) === false
);

// ── 3.ª reincidencia · Avería Crónica ──
$resetScenario(['VEND-0302']);
$chronicCandidate = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09D1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::RESOLVED,
        'assignedTechnicianId' => $jordi->getId(),
        'resolvedAt' => $stamp(-600),
        'resolutionDiagnosis' => 'Diagnóstico de la segunda reparación del monedero.',
        'resolutionAction' => 'Acción correctiva aplicada en el segundo servicio técnico.',
    ]),
    $jordi->getId(),
    'Aviso que acumulará dos reaperturas'
);
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Primera reapertura simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Nueva resolución simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Segunda reapertura simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Resolución final simulada.');

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/opportunistic/incidents/{$chronicCandidate->getId()}/assume",
    $techToken,
    ['reopen_reason' => 'Tercera reincidencia del mismo monedero en menos de 48 horas.']
);
$chronic = $body['data'] ?? [];
$stmt = $pdo->prepare(
    'SELECT status, reopen_reason, root_cause_id, standard_solution_id,
            root_cause_code_snapshot, root_cause_category_snapshot, root_cause_name_snapshot,
            standard_solution_code_snapshot, standard_solution_name_snapshot
       FROM incidents WHERE id = :id'
);
$stmt->execute([':id' => $chronicCandidate->getId()]);
$predecessor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$assert(
    '4.9 La 3.ª reincidencia responde 201 con el sucesor limpio en curso',
    $status === 201
        && ($chronic['chronic_replacement_created'] ?? null) === true
        && (int)($chronic['id'] ?? 0) !== (int)$chronicCandidate->getId()
        && ($chronic['opportunistic_mode'] ?? null) === 'NEW'
);
$assert(
    '4.10 El predecesor queda CLOSED con la marca [AVERÍA CRÓNICA]',
    ($predecessor['status'] ?? null) === 'CLOSED'
        && str_contains((string)($predecessor['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]')
        && (int)($chronic['chronic_predecessor_incident_id'] ?? 0) === (int)$chronicCandidate->getId()
);

// La retirada por reemplazo crónico es la única puerta que cierra sin
// clasificación (QA 3): el predecesor no hereda ni inventa taxonomía, y tampoco
// sus cinco snapshots. Cualquier relleno aquí falsearía el Pareto 80/20.
$predecessorTaxonomyFields = [
    'root_cause_id',
    'standard_solution_id',
    'root_cause_code_snapshot',
    'root_cause_category_snapshot',
    'root_cause_name_snapshot',
    'standard_solution_code_snapshot',
    'standard_solution_name_snapshot',
];
$inventedTaxonomy = array_values(array_filter(
    $predecessorTaxonomyFields,
    static fn (string $field): bool => ($predecessor[$field] ?? null) !== null
));
$assert(
    '4.11 El predecesor crónico se cierra sin clasificación inventada (QA 3)',
    $inventedTaxonomy === [],
    'campos rellenos: ' . implode(', ', $inventedTaxonomy)
);

// =========================================================================
// CASO 5 · Salvaguardas de ruta: coexistencia y cadena de frío (RF-02.2, RF-03)
// =========================================================================
echo "\n--- Caso 5: Coexistencia entre sedes y aviso del Art. II ---\n";

$resetScenario(['VEND-0302', 'VEND-0501', 'VEND-0502']);
$blocking = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09E1',
        'machineId' => $machines['VEND-0502']->getId(),
        'locationId' => $locationFive,
        'status' => IncidentStatus::IN_PROGRESS,
        'assignedTechnicianId' => $jordi->getId(),
        'startedAt' => $stamp(),
    ]),
    $jordi->getId(),
    'Intervención en curso en otra sede'
);

[$status, $body] = $dispatch('GET', "/api/technician/locations/{$locationThree}/opportunistic-machines", $techToken);
$assert(
    '5.1 El catálogo publica la intervención abierta en otra sede',
    $status === 200 && ($body['data']['active_intervention_conflict']['ticket_code'] ?? null) === 'INC-2026-T09E1'
);

[$status, $body] = $dispatch('POST', '/api/technician/opportunistic/incidents', $techToken, $baseCreate);
$assert(
    '5.2 El alta en otra sede responde 409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION',
    $status === 409
        && $errorCode($body) === 'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION'
        && ($body['error']['details']['active_conflict']['ticket_code'] ?? null) === 'INC-2026-T09E1'
);

$resetScenario(['VEND-0302', 'VEND-0501', 'VEND-0502']);
$criticalStop = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09F1',
        'machineId' => $machines['VEND-0501']->getId(),
        'locationId' => $locationFive,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $jordi->getId(),
        'category' => IncidentCategory::TEMPERATURE_COLD,
        'urgency' => UrgencyLevel::CRITICAL,
    ]),
    null,
    'Parada crítica de frío esperando en otra sede'
);

[$status, $body] = $dispatch('GET', "/api/technician/locations/{$locationThree}/opportunistic-machines", $techToken);
$assert(
    '5.3 El catálogo anuncia la advertencia sanitaria con la parada postergada',
    $status === 200
        && ($body['data']['cold_chain_route_warning']['requires_confirmation'] ?? null) === true
        && ($body['data']['cold_chain_route_warning']['pending_critical_stops'][0]['ticket_code'] ?? null) === 'INC-2026-T09F1'
);

[$status, $body] = $dispatch('POST', '/api/technician/opportunistic/incidents', $techToken, $baseCreate);
$assert(
    '5.4 Sin confirmación expresa el alta responde 409 COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED',
    $status === 409
        && $errorCode($body) === 'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED'
        && ($body['error']['details']['pending_critical_stops'][0]['ticket_code'] ?? null) === 'INC-2026-T09F1'
);

[$status, $body] = $dispatch(
    'POST',
    '/api/technician/opportunistic/incidents',
    $techToken,
    $baseCreate + ['confirm_cold_chain_override' => true]
);
$overriddenId = (int)($body['data']['id'] ?? 0);
$assert(
    '5.5 Confirmada la postergación, el alta se ejecuta y declara la alerta',
    $status === 201 && ($body['data']['cold_chain_override_alerted'] ?? null) === true
);
$assert(
    '5.6 Se firma COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED y se alerta a Coordinación',
    $auditCount('TICKET', $overriddenId, 'COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED') === 1
        && (bool)array_filter(
            $ticketCodes($overriddenId),
            static fn (array $row): bool => ($row['template'] ?? null) === 'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT'
        )
);

// =========================================================================
// CASO 6 · Resolución in situ y desbloqueo de acceso (RF-06.1)
// =========================================================================
echo "\n--- Caso 6: Resolución in situ y levantamiento de is_blocked_no_access ---\n";

$resetScenario(['VEND-0302']);
$pdo->prepare(
    "UPDATE machines SET is_active = FALSE, is_blocked_no_access = TRUE, notes = 'Bloqueada por falta de acceso (T-OPP-09).' WHERE id = :id"
)->execute([':id' => $machines['VEND-0302']->getId()]);
$blockedMachineId = (int)$machines['VEND-0302']->getId();

$blockedIncident = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09G1',
        'machineId' => $blockedMachineId,
        'locationId' => $locationThree,
        'status' => IncidentStatus::IN_PROGRESS,
        'assignedTechnicianId' => $jordi->getId(),
        'startedAt' => $stamp(),
    ]),
    $jordi->getId(),
    'Intervención sobre máquina bloqueada por acceso'
);

$resolutionBody = [
    'diagnosis' => 'Compresor detenido por sobrecarga tras la reapertura del local.',
    'action_taken' => 'Reinicio del compresor y sustitución del relé de arranque dañado.',
    'replaced_parts_declared' => false,
];

// El registro de auditoría es inmutable y sobrevive a la limpieza: el veredicto
// se mide por DELTA para que una corrida anterior del arnés no lo contamine.
$unblockAuditBefore = $auditCount('MACHINE', $blockedMachineId, 'MACHINE_UNBLOCKED_BY_ONSITE_RESOLUTION');

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/incidents/{$blockedIncident->getId()}/resolve",
    $techToken,
    $resolutionBody
);
$machineAfter = $machineRow($blockedMachineId);
$assert(
    '6.1 La resolución responde 200 y devuelve la máquina al parque activo',
    $status === 200
        && ($body['data']['machine_access_unblocked'] ?? null) === true
        && (bool)($machineAfter['is_active'] ?? false) === true
        && (bool)($machineAfter['is_blocked_no_access'] ?? true) === false
);
$assert(
    '6.2 El levantamiento queda firmado como MACHINE_UNBLOCKED_BY_ONSITE_RESOLUTION',
    $auditCount('MACHINE', $blockedMachineId, 'MACHINE_UNBLOCKED_BY_ONSITE_RESOLUTION') === $unblockAuditBefore + 1
);

// ── La cuarentena sanitaria manda sobre el desbloqueo (RF-06.1.2) ──
$resetScenario(['VEND-0302']);
$pdo->prepare(
    "UPDATE machines SET is_active = FALSE, is_blocked_no_access = TRUE, sanitary_status = 'QUARANTINE', notes = 'Cuarentena sanitaria vigente (T-OPP-09).' WHERE id = :id"
)->execute([':id' => $blockedMachineId]);
$quarantinedIncident = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-T09G2',
        'machineId' => $blockedMachineId,
        'locationId' => $locationThree,
        'status' => IncidentStatus::IN_PROGRESS,
        'assignedTechnicianId' => $jordi->getId(),
        'startedAt' => $stamp(),
    ]),
    $jordi->getId(),
    'Intervención sobre máquina en cuarentena'
);

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/incidents/{$quarantinedIncident->getId()}/resolve",
    $techToken,
    $resolutionBody
);
$assert(
    '6.3 Sin protocolo sanitario el cierre se suspende (422 SANITARY_QUARANTINE_OVERTAKEN)',
    $status === 422 && $errorCode($body) === 'SANITARY_QUARANTINE_OVERTAKEN'
);

[$status, $body] = $dispatch(
    'POST',
    "/api/technician/incidents/{$quarantinedIncident->getId()}/resolve",
    $techToken,
    $resolutionBody + [
        'sanitary_declarations' => [
            'temperature_c' => 21.5,
            'stock_destroyed' => true,
            'hygiene_checklist' => true,
        ],
    ]
);
$machineAfterQuarantine = $machineRow($blockedMachineId);
$assert(
    '6.4 Con el protocolo firmado la avería se cierra, pero la máquina sigue bloqueada y en cuarentena',
    $status === 200
        && ($body['data']['machine_access_unblocked'] ?? null) === false
        && ($body['data']['machine_access_block_reason'] ?? null) === 'SANITARY_QUARANTINE_OR_BIOLOGICAL_CLOCK'
        && (bool)($machineAfterQuarantine['is_blocked_no_access'] ?? false) === true
        && (string)($machineAfterQuarantine['sanitary_status'] ?? '') === 'QUARANTINE'
);

// =========================================================================
// LIMPIEZA
// =========================================================================
$resetScenario();

echo "\n======================================================================\n";
echo " Aserciones ejecutadas: {$assertions} · fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-OPP-09 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
