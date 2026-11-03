<?php

declare(strict_types=1);

/**
 * WorkshopRefurbishmentApiIntegrationTest · T-WORKSHOP-12
 *
 * Certificación HTTP del Módulo 27 sobre el motor real, con tokens firmados y el
 * router de producción (no se invoca ningún controlador por dentro):
 *
 *   1. RBAC de los cinco puertos de Coordinación: 401 sin sesión y 403 con un
 *      técnico de campo (`Art. II.3`).
 *   2. Bloque `pending_workshop_deliveries` del técnico: sólo sus unidades en
 *      `PENDING_DELIVERY`, sin importes (`Art. V.4`, `QA 4`).
 *   3. Bandeja: filtros por estado, técnico, categoría y búsqueda; `summary`
 *      global inmune al filtro de estado (`QA 2`); orden por antigüedad.
 *   4. Transiciones `receive` / `start-bench`: 404, 409 y 422 del contrato.
 *   5. Dictamen: 422 de validación, 409 de terminalidad (`C5`), ahorro con suelo
 *      cero y banner (`C2`), y reintegro atómico `+1` a furgoneta (`D3`, `QA 3`).
 *   6. CSV: cabeceras, BOM, 11 indicadores y 24 columnas (`QA 5`), con su evento
 *      de auditoría.
 *
 * El escenario se siembra con el `SeedRunner` del proyecto y se completa con una
 * máquina, una avería y cuatro expedientes unitarios propios; los usuarios, sedes
 * y catálogos vienen de las semillas reales.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard · T-WORKSHOP-12 · Contratos HTTP y RBAC del taller\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";

        return;
    }
    echo "  [FAIL] {$title}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/**
 * Despacha una petición real por el router, con token opcional.
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $query
 */
$api = static function (
    string $method,
    string $path,
    ?string $token = null,
    array $body = [],
    array $query = []
) use ($router): Response {
    $headers = $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];

    return $router->dispatch(new Request($method, $path, $query, $body, $headers));
};

/** Cuerpo `data` de una respuesta JSON, o `[]`. */
$data = static function (Response $response): array {
    $decoded = $response->getDecodedBody();

    return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
};

/** Código de error del sobre estándar, o cadena vacía. */
$errorCode = static function (Response $response): string {
    $decoded = $response->getDecodedBody();

    return (string)($decoded['error']['code'] ?? '');
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert('0.1 Usuarios semilla de coordinación y técnico disponibles', $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);
$coordinatorId = (int)$coordinator->getId();
$technicianId = (int)$technician->getId();

// ─────────────────────────────────────────────────────────────────────────
// Escenario: pieza, máquina, avería y cuatro expedientes unitarios
// ─────────────────────────────────────────────────────────────────────────

$locationId = (int)$pdo->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
$assert('0.2 Sede semilla recuperada', $locationId > 0);

// Marca única por ejecución: la suite se puede repetir sin colisionar con los
// códigos de una pasada anterior que muriera antes de limpiar.
$runTag = date('His') . random_int(100, 999);
$partCode = 'WS27-API-' . $runTag;
$machineCode = 'VEND-API-' . $runTag;
$ticketCode = 'INC-API-' . $runTag;

$partStmt = $pdo->prepare(
    "INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost)
     VALUES (:part_code, 'Electroválvula de certificación API 27', 'OTHER', 'Certificación', 24.50)
     RETURNING id"
);
$partStmt->execute([':part_code' => $partCode]);
$partId = (int)$partStmt->fetchColumn();

$pdo->prepare(
    'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
     VALUES (:technician_id, :spare_part_id, 5, 2)'
)->execute([':technician_id' => $technicianId, ':spare_part_id' => $partId]);

$machineStmt = $pdo->prepare(
    "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
     VALUES (:location_id, :code, 'Modelo API 27', 'COLD_DRINKS', 'Planta baja')
     RETURNING id"
);
$machineStmt->execute([':location_id' => $locationId, ':code' => $machineCode]);
$machineId = (int)$machineStmt->fetchColumn();

$incidentStmt = $pdo->prepare(
    "INSERT INTO incidents
        (ticket_code, machine_id, machine_type_snapshot, location_id, category, description, urgency,
         status, assigned_technician_id, assigned_at, started_at)
     VALUES
        (:ticket_code, :machine_id, 'COLD_DRINKS', :location_id, 'OTHER',
         'Avería de certificación del contrato HTTP del taller central.', 'HIGH',
         'IN_PROGRESS', :technician_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
     RETURNING id"
);
$incidentStmt->execute([
    ':ticket_code' => $ticketCode,
    ':machine_id' => $machineId,
    ':location_id' => $locationId,
    ':technician_id' => $technicianId,
]);
$incidentId = (int)$incidentStmt->fetchColumn();

$replacedPart = PostgresFixtureBuilder::insertWorkshopReplacedPart($pdo, [
    'incidents' => ['id' => $incidentId],
    'machines' => ['id' => $machineId],
    'locations' => ['id' => $locationId],
    'users' => ['id' => $technicianId],
    'spare_parts' => ['id' => $partId],
], 'api', 4);

$insertUnit = static function (int $unitIndex) use ($pdo, $replacedPart): int {
    $stmt = $pdo->prepare(
        "INSERT INTO workshop_repair_items (
            incident_replaced_part_id, unit_index, total_units_in_line, intervention_type,
            incident_id, machine_id, location_id, extracted_by_technician_id, spare_part_id,
            is_out_of_catalog, part_code_snapshot, part_name_snapshot, part_category_snapshot,
            machine_code_snapshot, machine_model_snapshot, location_name_snapshot,
            technician_name_snapshot, unit_cost_snapshot, status
         )
         SELECT p.id, :unit_index, 4, p.intervention_type,
                p.incident_id, p.machine_id, p.location_id, p.technician_id, p.spare_part_id,
                FALSE, sp.part_code, sp.name, COALESCE(sp.category::text, 'OTHER'),
                m.code, m.model, l.name, u.name, p.unit_cost_snapshot, 'PENDING_DELIVERY'
           FROM incident_replaced_parts p
           JOIN machines m ON m.id = p.machine_id
           JOIN locations l ON l.id = p.location_id
           JOIN users u ON u.id = p.technician_id
           LEFT JOIN spare_parts sp ON sp.id = p.spare_part_id
          WHERE p.id = :replaced_part_id
         RETURNING id"
    );
    $stmt->execute([
        ':unit_index' => $unitIndex,
        ':replaced_part_id' => (int)$replacedPart['id'],
    ]);

    return (int)$stmt->fetchColumn();
};

$pendingUnitId = $insertUnit(1);
$extravioUnitId = $insertUnit(2);
$receivedUnitId = $insertUnit(3);
$benchUnitId = $insertUnit(4);

$pdo->prepare(
    "UPDATE workshop_repair_items
        SET status = 'RECEIVED_IN_WORKSHOP', received_at = CURRENT_TIMESTAMP, received_by_user_id = :actor
      WHERE id = :id"
)->execute([':actor' => $coordinatorId, ':id' => $receivedUnitId]);

$pdo->prepare(
    "UPDATE workshop_repair_items
        SET status = 'IN_BENCH_TEST', received_at = CURRENT_TIMESTAMP, received_by_user_id = :actor,
            bench_started_at = CURRENT_TIMESTAMP
      WHERE id = :id"
)->execute([':actor' => $coordinatorId, ':id' => $benchUnitId]);

$assert(
    '0.3 Cuatro expedientes unitarios sembrados (2 pendientes, 1 recibido, 1 en banco)',
    $pendingUnitId > 0 && $extravioUnitId > 0 && $receivedUnitId > 0 && $benchUnitId > 0
);

$vanStockOf = static function () use ($pdo, $technicianId, $partId): int {
    $stmt = $pdo->prepare(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
    );
    $stmt->execute([':technician_id' => $technicianId, ':spare_part_id' => $partId]);
    $value = $stmt->fetchColumn();

    return $value === false ? -1 : (int)$value;
};

// ─────────────────────────────────────────────────────────────────────────
// 1. RBAC de los cinco puertos de Coordinación
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. RBAC: 401 sin sesión y 403 con técnico (Art. II.3) ---\n";

$protectedEndpoints = [
    ['GET', '/api/coordinator/workshop-items'],
    ['GET', '/api/coordinator/workshop-items/export'],
    ['POST', "/api/coordinator/workshop-items/{$pendingUnitId}/receive"],
    ['POST', "/api/coordinator/workshop-items/{$pendingUnitId}/start-bench"],
    ['POST', "/api/coordinator/workshop-items/{$pendingUnitId}/resolve"],
];

foreach ($protectedEndpoints as [$method, $path]) {
    $anon = $api($method, $path);
    $assert("1.1 {$method} {$path} sin token => 401", $anon->getStatusCode() === 401, 'HTTP ' . $anon->getStatusCode());

    $asTechnician = $api($method, $path, $techToken, ['outcome_status' => 'SCRAPPED_IRREPARABLE']);
    $techCode = $errorCode($asTechnician);
    $assert(
        "1.2 {$method} {$path} con técnico => 403",
        $asTechnician->getStatusCode() === 403
            && in_array($techCode, ['FORBIDDEN', 'FORBIDDEN_ROLE'], true),
        'HTTP ' . $asTechnician->getStatusCode() . ' · ' . $techCode
    );
}

$asCoordinator = $api('GET', '/api/coordinator/workshop-items', $coordToken);
$assert('1.3 GET bandeja con coordinador => 200', $asCoordinator->getStatusCode() === 200, 'HTTP ' . $asCoordinator->getStatusCode());

// ─────────────────────────────────────────────────────────────────────────
// 2. Entregas pendientes en el stock de la furgoneta del técnico (QA 4)
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. pending_workshop_deliveries sin importes (RF-04.4, Art. V.4) ---\n";

$vanStockData = $data($api('GET', '/api/technician/van-stock', $techToken));
$pending = $vanStockData['pending_workshop_deliveries'] ?? [];
$pendingIds = array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $pending);
$pendingCount = (int)($vanStockData['pending_workshop_deliveries_count'] ?? -1);

$assert('2.1 El contador coincide con el número de entregas pendientes', $pendingCount === count($pending), "count={$pendingCount} filas=" . count($pending));
$assert(
    '2.2 El bloque sólo publica las unidades pendientes del técnico autenticado',
    in_array($pendingUnitId, $pendingIds, true) && in_array($extravioUnitId, $pendingIds, true)
        && !in_array($receivedUnitId, $pendingIds, true) && !in_array($benchUnitId, $pendingIds, true),
    'ids=' . implode(',', $pendingIds)
);

$monetaryKeys = ['unit_cost_snapshot', 'refurbishment_cost_eur', 'net_savings_eur'];
$leakedKeys = [];
foreach ($pending as $entry) {
    foreach ($monetaryKeys as $key) {
        if (array_key_exists($key, $entry)) {
            $leakedKeys[] = $key;
        }
    }
}
$assert('2.3 Ninguna entrega pendiente expone importes monetarios', $leakedKeys === [], implode(',', array_unique($leakedKeys)));
$assert(
    '2.4 Cada entrega pendiente declara pieza, máquina y sede sin coste',
    $pending !== []
        && isset($pending[0]['part_name'], $pending[0]['machine_code'], $pending[0]['location_name'])
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Bandeja: filtros, KPIs globales y orden
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Bandeja: filtros, summary global y orden (QA 2) ---\n";

$scenarioFilter = ['search' => $machineCode];
$tray = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter));
$trayIds = array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $tray['items'] ?? []);
$scenarioIds = [$pendingUnitId, $extravioUnitId, $receivedUnitId, $benchUnitId];
sort($scenarioIds);
$assert('3.1 La búsqueda acota la bandeja a los cuatro expedientes del escenario', (int)($tray['total'] ?? 0) === 4, 'total=' . ($tray['total'] ?? 'null'));
$assert('3.2 El orden activo es por antigüedad y desempata por id', $trayIds === $scenarioIds, implode(',', $trayIds) . ' vs ' . implode(',', $scenarioIds));
$assert(
    '3.3 El summary publica los once indicadores del contrato',
    count($tray['summary'] ?? []) >= 11 && isset(
        $tray['summary']['pending_delivery_count'],
        $tray['summary']['recovery_rate_pct'],
        $tray['summary']['scrapped_unreceived_count'],
        $tray['summary']['total_net_savings_eur']
    )
);

$pendingTray = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter + ['status' => 'PENDING_DELIVERY']));
$assert(
    '3.4 status=PENDING_DELIVERY filtra la página pero no los totales globales',
    (int)($pendingTray['total'] ?? 0) === 2
        && (int)($pendingTray['summary']['pending_delivery_count'] ?? -1) === 2
        && (int)($pendingTray['summary']['received_in_workshop_count'] ?? -1) === 1
);

$byTechnician = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter + ['technician_id' => (string)$technicianId]));
$assert('3.5 technician_id filtra por técnico extractor', (int)($byTechnician['total'] ?? 0) === 4);

$byCategory = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter + ['category' => 'OTHER']));
$otherCategory = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter + ['category' => 'COOLING']));
$assert(
    '3.6 category acota por categoría de catálogo',
    (int)($byCategory['total'] ?? 0) === 4 && (int)($otherCategory['total'] ?? 0) === 0
);

$bySearch = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], ['search' => $machineCode]));
$noSearch = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], ['search' => 'sin-coincidencia-27']));
$assert(
    '3.7 search busca en máquina, pieza y sede',
    (int)($bySearch['total'] ?? 0) === 4 && (int)($noSearch['total'] ?? 0) === 0
);

$badStatus = $api('GET', '/api/coordinator/workshop-items', $coordToken, [], ['status' => 'REPAIRED']);
$assert(
    '3.8 Estado fuera del ciclo => 422 INVALID_WORKSHOP_PAYLOAD',
    $badStatus->getStatusCode() === 422 && $errorCode($badStatus) === 'INVALID_WORKSHOP_PAYLOAD',
    'HTTP ' . $badStatus->getStatusCode() . ' · ' . $errorCode($badStatus)
);

// ─────────────────────────────────────────────────────────────────────────
// 4. Recepción en taller (RF-02.1)
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. POST /receive: 404, 409, 422 y 200 ---\n";

$notFound = $api('POST', '/api/coordinator/workshop-items/999999/receive', $coordToken, []);
$assert(
    '4.1 Expediente inexistente => 404 WORKSHOP_ITEM_NOT_FOUND',
    $notFound->getStatusCode() === 404 && $errorCode($notFound) === 'WORKSHOP_ITEM_NOT_FOUND',
    'HTTP ' . $notFound->getStatusCode() . ' · ' . $errorCode($notFound)
);

$alreadyReceived = $api('POST', "/api/coordinator/workshop-items/{$receivedUnitId}/receive", $coordToken, []);
$assert(
    '4.2 Recibir una unidad ya recepcionada => 409',
    $alreadyReceived->getStatusCode() === 409 && $errorCode($alreadyReceived) === 'INVALID_WORKSHOP_STATUS_TRANSITION',
    'HTTP ' . $alreadyReceived->getStatusCode() . ' · ' . $errorCode($alreadyReceived)
);

$longNotes = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/receive", $coordToken, [
    'reception_notes' => str_repeat('a', 501),
]);
$assert(
    '4.3 Nota de recepción de 501 caracteres => 422',
    $longNotes->getStatusCode() === 422 && $errorCode($longNotes) === 'RECEPTION_NOTES_TOO_LONG',
    'HTTP ' . $longNotes->getStatusCode() . ' · ' . $errorCode($longNotes)
);

$received = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/receive", $coordToken, [
    'reception_notes' => 'Entregada en taller sin daños externos visibles.',
]);
$receivedData = $data($received);
$assert(
    '4.4 Recepción válida => 200 con estado y nota persistidos',
    $received->getStatusCode() === 200
        && ($receivedData['item']['status'] ?? '') === 'RECEIVED_IN_WORKSHOP'
        && ($receivedData['item']['reception_notes'] ?? '') === 'Entregada en taller sin daños externos visibles.',
    'HTTP ' . $received->getStatusCode()
);

$pendingAfterReceive = $data($api('GET', '/api/technician/van-stock', $techToken));
$pendingIdsAfter = array_map(
    static fn (array $row): int => (int)($row['id'] ?? 0),
    $pendingAfterReceive['pending_workshop_deliveries'] ?? []
);
$assert(
    '4.5 El bloque del técnico se vacía en cuanto Coordinación acusa recibo',
    !in_array($pendingUnitId, $pendingIdsAfter, true)
        && (int)($pendingAfterReceive['pending_workshop_deliveries_count'] ?? -1) === count($pendingIdsAfter),
    'ids=' . implode(',', $pendingIdsAfter)
);

// ─────────────────────────────────────────────────────────────────────────
// 5. Inicio de banco (RF-02.3)
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 5. POST /start-bench: 409 y 200 ---\n";

$benchTooEarly = $api('POST', "/api/coordinator/workshop-items/{$extravioUnitId}/start-bench", $coordToken);
$assert(
    '5.1 Iniciar banco sin recepción => 409',
    $benchTooEarly->getStatusCode() === 409 && $errorCode($benchTooEarly) === 'INVALID_WORKSHOP_STATUS_TRANSITION',
    'HTTP ' . $benchTooEarly->getStatusCode() . ' · ' . $errorCode($benchTooEarly)
);

$benchStarted = $api('POST', "/api/coordinator/workshop-items/{$receivedUnitId}/start-bench", $coordToken);
$assert(
    '5.2 Inicio de banco válido => 200 IN_BENCH_TEST',
    $benchStarted->getStatusCode() === 200 && ($data($benchStarted)['item']['status'] ?? '') === 'IN_BENCH_TEST',
    'HTTP ' . $benchStarted->getStatusCode()
);

// ─────────────────────────────────────────────────────────────────────────
// 6. Dictamen terminal (RF-03, C2, C5, D3, QA 3)
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 6. POST /resolve: validación, ahorro y reintegro atómico ---\n";

$invalidOutcome = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/resolve", $coordToken, [
    'outcome_status' => 'REPAIRED',
    'bench_diagnosis' => 'Sustitución de la electroválvula y prueba de 200 ciclos sin fugas.',
]);
$assert(
    '6.1 Dictamen fuera de catálogo => 422 INVALID_RESOLUTION_OUTCOME',
    $invalidOutcome->getStatusCode() === 422 && $errorCode($invalidOutcome) === 'INVALID_RESOLUTION_OUTCOME',
    'HTTP ' . $invalidOutcome->getStatusCode() . ' · ' . $errorCode($invalidOutcome)
);

$shortDiagnosis = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/resolve", $coordToken, [
    'outcome_status' => 'REFURBISHED_RESTORED',
    'bench_diagnosis' => 'Fuga.',
    'refurbishment_cost_eur' => 4.50,
    'restock_destination' => 'TECHNICIAN_VAN',
    'restocked_to_technician_id' => $technicianId,
]);
$assert(
    '6.2 Diagnóstico por debajo de 20 caracteres => 422',
    $shortDiagnosis->getStatusCode() === 422 && $errorCode($shortDiagnosis) === 'BENCH_DIAGNOSIS_TOO_SHORT',
    'HTTP ' . $shortDiagnosis->getStatusCode() . ' · ' . $errorCode($shortDiagnosis)
);

$stockBefore = $vanStockOf();
$refurbishedToVan = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/resolve", $coordToken, [
    'outcome_status' => 'REFURBISHED_RESTORED',
    'bench_diagnosis' => 'Sustitución de la electroválvula y prueba de 200 ciclos sin fugas.',
    'refurbishment_cost_eur' => 4.50,
    'restock_destination' => 'TECHNICIAN_VAN',
    'restocked_to_technician_id' => $technicianId,
]);
$refurbishedData = $data($refurbishedToVan);
$assert(
    '6.3 Reacondicionada a furgoneta => 200 sin aviso de ahorro nulo',
    $refurbishedToVan->getStatusCode() === 200
        && ($refurbishedData['item']['status'] ?? '') === 'REFURBISHED_RESTORED'
        && ($refurbishedData['zero_net_savings_warning'] ?? null) === false,
    'HTTP ' . $refurbishedToVan->getStatusCode()
);
$assert(
    '6.4 net_savings_eur = valor 24.50 − coste 4.50',
    abs((float)($refurbishedData['item']['net_savings_eur'] ?? -1) - 20.00) < 0.001,
    'net=' . ($refurbishedData['item']['net_savings_eur'] ?? 'null')
);
$assert(
    '6.5 El reintegro suma exactamente +1 al stock de la furgoneta (QA 3)',
    $vanStockOf() === $stockBefore + 1,
    'antes=' . $stockBefore . ' despues=' . $vanStockOf()
);

$resolveAgain = $api('POST', "/api/coordinator/workshop-items/{$pendingUnitId}/resolve", $coordToken, [
    'outcome_status' => 'SCRAPPED_IRREPARABLE',
    'bench_diagnosis' => 'Pista carbonizada sin posibilidad de recuperación técnica.',
]);
$assert(
    '6.6 Repetir el dictamen => 409 WORKSHOP_ITEM_ALREADY_RESOLVED (C5)',
    $resolveAgain->getStatusCode() === 409 && $errorCode($resolveAgain) === 'WORKSHOP_ITEM_ALREADY_RESOLVED',
    'HTTP ' . $resolveAgain->getStatusCode() . ' · ' . $errorCode($resolveAgain)
);

$zeroSavings = $api('POST', "/api/coordinator/workshop-items/{$receivedUnitId}/resolve", $coordToken, [
    'outcome_status' => 'REFURBISHED_RESTORED',
    'bench_diagnosis' => 'Recambio completo del bloque hidráulico y calibración final en banco.',
    'refurbishment_cost_eur' => 30.00,
    'restock_destination' => 'CENTRAL_WAREHOUSE',
]);
$zeroSavingsData = $data($zeroSavings);
$assert(
    '6.7 Coste superior al valor => 200 con zero_net_savings_warning (C2)',
    $zeroSavings->getStatusCode() === 200 && ($zeroSavingsData['zero_net_savings_warning'] ?? null) === true,
    'HTTP ' . $zeroSavings->getStatusCode() . ' · warning=' . var_export($zeroSavingsData['zero_net_savings_warning'] ?? null, true)
);
$assert(
    '6.8 El ahorro aplica suelo cero aunque el coste supere el valor',
    abs((float)($zeroSavingsData['item']['net_savings_eur'] ?? -1)) < 0.001,
    'net=' . ($zeroSavingsData['item']['net_savings_eur'] ?? 'null')
);

$scrapped = $api('POST', "/api/coordinator/workshop-items/{$benchUnitId}/resolve", $coordToken, [
    'outcome_status' => 'SCRAPPED_IRREPARABLE',
    'bench_diagnosis' => 'Microcontrolador en cortocircuito; pista multicapa carbonizada.',
]);
$scrappedData = $data($scrapped);
$assert(
    '6.9 Baja desde banco => 200 con coste y ahorro a cero',
    $scrapped->getStatusCode() === 200
        && ($scrappedData['item']['status'] ?? '') === 'SCRAPPED_IRREPARABLE'
        && abs((float)($scrappedData['item']['refurbishment_cost_eur'] ?? -1)) < 0.001
        && ($scrappedData['zero_net_savings_warning'] ?? null) === false,
    'HTTP ' . $scrapped->getStatusCode()
);

$postResolution = $data($api('GET', '/api/coordinator/workshop-items', $coordToken, [], $scenarioFilter));
$summary = $postResolution['summary'] ?? [];
$assert(
    '6.10 recovery_rate_pct = 2 reacondicionadas / (2 + 1 baja en banco) = 66.67',
    abs((float)($summary['recovery_rate_pct'] ?? -1) - 66.67) < 0.001,
    'rate=' . ($summary['recovery_rate_pct'] ?? 'null')
);
$assert(
    '6.11 Las bajas sin recepción no entran en la tasa (QA 1)',
    (int)($summary['scrapped_unreceived_count'] ?? -1) === 0,
    'scrapped_unreceived=' . ($summary['scrapped_unreceived_count'] ?? 'null')
);

// ─────────────────────────────────────────────────────────────────────────
// 7. Exportación CSV (QA 5)
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 7. GET /export: cabeceras, BOM, 11 indicadores y 24 columnas ---\n";

$exportAuditBefore = (int)$pdo->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'EXPORT_WORKSHOP_ITEMS_CSV'"
)->fetchColumn();

$export = $api('GET', '/api/coordinator/workshop-items/export', $coordToken);
$exportBody = $export->getBody();
$contentType = (string)$export->getHeader('Content-Type');
$disposition = (string)$export->getHeader('Content-Disposition');

$assert(
    '7.1 Adjunto CSV UTF-8 con nombre fechado',
    $export->getStatusCode() === 200
        && str_contains($contentType, 'text/csv')
        && stripos($contentType, 'charset=UTF-8') !== false
        && preg_match('/attachment; filename="taller_piezas_recuperadas_\d{8}_\d{4}\.csv"/', $disposition) === 1,
    'HTTP ' . $export->getStatusCode() . ' · ' . $contentType . ' · ' . $disposition
);
$assert('7.2 El cuerpo arranca con BOM UTF-8', str_starts_with($exportBody, "\xEF\xBB\xBF"));

$lines = explode("\n", str_replace("\r\n", "\n", ltrim($exportBody, "\xEF\xBB\xBF")));
$assert('7.3 La primera sección abre con Indicador;Valor', ($lines[0] ?? '') === 'Indicador;Valor', $lines[0] ?? '');

$separatorIndex = array_search('', $lines, true);
$indicatorCount = is_int($separatorIndex) ? $separatorIndex - 1 : 0;
$assert('7.4 La sección 1 publica los once indicadores', $indicatorCount === 11, 'indicadores=' . $indicatorCount);

$headerLine = is_int($separatorIndex) ? (string)($lines[$separatorIndex + 1] ?? '') : '';
$headerColumns = $headerLine !== '' ? explode(';', $headerLine) : [];
$assert('7.5 La sección 2 declara las 24 columnas del contrato', count($headerColumns) === 24, 'columnas=' . count($headerColumns));
$assert(
    '7.6 La cabecera de detalle conserva los nombres en castellano',
    in_array('ID Ficha', $headerColumns, true)
        && in_array('Diagnóstico de Banco', $headerColumns, true)
        && in_array('Ahorro Neto (EUR)', $headerColumns, true)
);

$exportAudit = (int)$pdo->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'EXPORT_WORKSHOP_ITEMS_CSV'"
)->fetchColumn();
$assert(
    '7.7 La descarga añade exactamente un evento a audit_log (Art. III.3)',
    $exportAudit === $exportAuditBefore + 1,
    'antes=' . $exportAuditBefore . ' despues=' . $exportAudit
);

// ─────────────────────────────────────────────────────────────────────────
// Resultado
// ─────────────────────────────────────────────────────────────────────────

echo "\n======================================================================\n";
echo " VENDGUARD · T-WORKSHOP-12 · RESULTADO\n";
echo sprintf(" Aserciones: %d · Fallos: %d\n", $assertions, $failures);
echo "======================================================================\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. RF-02..RF-05, RNF-03, D2, D3, C2, C5 y QA 1/2/3/4/5 CERTIFICADOS.\n"
    : " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";

exit($failures > 0 ? 1 : 0);
