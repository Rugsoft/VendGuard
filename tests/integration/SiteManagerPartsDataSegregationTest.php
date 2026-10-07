<?php

declare(strict_types=1);

/**
 * VendGuard - SiteManagerPartsDataSegregationTest
 *
 * Test de Integración HTTP de Blindaje Constitucional y Segregación de Datos de Sede (T-SPARE-20).
 * Requisitos: RF-REP-10 y Constitución Art. V.4 (Privacidad y Segregación de Datos).
 *
 * Valida la condición "Hecho cuando:" de T-SPARE-20:
 * Certifica, contra MariaDB real y el enrutamiento HTTP completo, que NINGÚN endpoint
 * accesible por el rol LOCATION_MANAGER (Responsable de Ubicación / Sede) expone
 * piezas solicitadas, componentes sustituidos, destinos de taller ni costes económicos.
 *
 * Estrategia de Blindaje:
 * 1. RBAC del catálogo de repuestos: los endpoints /api/coordinator/spare-parts/* rechazan
 *    con 401 a peticiones anónimas y solo son accesibles por el rol COORDINATOR.
 * 2. Auditoría recursiva de claves: se inspeccionan recursivamente todos los payloads JSON
 *    devueltos al portal de sede buscando claves prohibidas (replaced_parts, spare_parts,
 *    unit_cost_snapshot, total_cost_snapshot, reference_cost, old_part_destination, ...).
 * 3. Análisis crudo del JSON: se escanea el cuerpo serializado buscando tokens técnicos
 *    (códigos de pieza, importes 28.50 €, palabras DESGUACE / TALLER) para detectar fugas
 *    incluso bajo nombres de clave no catalogados.
 * 4. Blindaje del sanitizador: verificación unitaria de LocationPortalController::sanitizeIncidentForSite
 *    eliminando claves prohibidas exactas, alias regex y preservando claves legítimas.
 *
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 * Dualismo Lingüístico: Arquitectura y código en inglés, contratos y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================================\n";
echo " VendGuard: Test Integración - SiteManagerPartsDataSegregationTest (T-SPARE-20)\n";
echo "======================================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);
$portalController = new LocationPortalController();

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

// Registro de auditoría constitucional: todos los payloads JSON entregados al rol de sede
$auditPayloads = [];
$registerAuditPayload = function (string $label, string $rawJson) use (&$auditPayloads): void {
    $auditPayloads[] = ['label' => $label, 'json' => $rawJson];
};

// =========================================================================
// BLOQUE 0: Datos Semilla y Preparación del Escenario
// =========================================================================
echo "\n--- BLOQUE 0: Datos Semilla y Preparación del Escenario ---\n";

$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$locationBCN1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machine1 = $machineRepo->findByCode('VEND-0101'); // Sanden Vendo G-Drink (PERISHABLE_FOOD, SEDE-BCN-01)
$machine2 = $machineRepo->findByCode('VEND-0102'); // Bianchi Gaia Espresso (SEDE-BCN-01)

$assert(
    "0.1 Semillas localizadas (técnico, coordinador, sede y máquinas de SEDE-BCN-01)",
    $technician !== null && $coordinator !== null && $locationBCN1 !== null && $machine1 !== null && $machine2 !== null
);
if ($technician === null || $coordinator === null || $locationBCN1 === null || $machine1 === null || $machine2 === null) {
    echo "ERROR CRÍTICO: Datos semilla requeridos no encontrados.\n";
    exit(1);
}

$techToken = $authService->generateInternalToken($technician);
$coordToken = $authService->generateInternalToken($coordinator);
$siteToken = $authService->generateSiteToken($locationBCN1);
$m1Id = (int)$machine1->getId();
$m1LocId = (int)$machine1->getLocationId();
$m2Id = (int)$machine2->getId();
$m2LocId = (int)$machine2->getLocationId();

$ulkaPartId = (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = 'VALV-ULKA-01'")->fetchColumn();

$assert("0.2 Repuesto semilla VALV-ULKA-01 recuperado para las fugas controladas", $ulkaPartId > 0);

/**
 * Inserta directamente sobre una avería las filas de repuestos que jamás deben cruzar
 * la frontera del portal de sede: 2 solicitudes en pausa y 2 sustituciones con snapshot.
 */
$insertContaminatingRows = function (int $incidentId, int $machineId, int $locationId) use ($pdo, $ulkaPartId): void {
    // Piezas SOLICITADAS en pausa técnica (spare_part_requests): una catalogada y una fuera de catálogo
    $pdo->prepare("
        INSERT INTO `spare_part_requests`
            (`incident_id`, `spare_part_id`, `is_out_of_catalog`, `custom_part_description`, `quantity`, `status`, `requested_by_user_id`)
        VALUES
            (:incident_id_a, :spare_part_id, 0, NULL, 2, 'PENDING',
             (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1)),
            (:incident_id_b, NULL, 1, 'Sensor infrarrojo reflectante para detección de producto en bandeja inferior', 1, 'PENDING',
             (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1))
    ")->execute([
        ':incident_id_a' => $incidentId,
        ':spare_part_id' => $ulkaPartId,
        ':incident_id_b' => $incidentId,
    ]);

    // Piezas SUSTITUIDAS con snapshot de coste (incident_replaced_parts): DESGUACE 28.50 € y TALLER 0.00 €
    $pdo->prepare("
        INSERT INTO `incident_replaced_parts`
            (`intervention_type`, `incident_id`, `machine_id`, `location_id`, `technician_id`, `spare_part_id`,
             `is_out_of_catalog`, `custom_part_name`, `quantity`, `unit_cost_snapshot`, `old_part_destination`, `notes`)
        VALUES
            ('INCIDENT', :incident_id_a, :machine_id_a, :location_id_a,
             (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1), :spare_part_id,
             0, NULL, 2, 28.50, 'DESGUACE', 'Cambio de electroválvula por avería en campo'),
            ('INCIDENT', :incident_id_b, :machine_id_b, :location_id_b,
             (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1), NULL,
             1, 'Abrazadera especial de apriete rápido 16mm', 1, 0.00, 'TALLER', 'Para revisión en taller central')
    ")->execute([
        ':incident_id_a' => $incidentId,
        ':machine_id_a'  => $machineId,
        ':location_id_a' => $locationId,
        ':spare_part_id' => $ulkaPartId,
        ':incident_id_b' => $incidentId,
        ':machine_id_b'  => $machineId,
        ':location_id_b' => $locationId,
    ]);
};

/**
 * Crea una avería contaminada de prueba con carga completa de repuestos prohibidos.
 */
$createContaminatedIncident = function (int $machineId, int $locationId, string $status) use ($pdo, $insertContaminatingRows): int {
    $ticketCode = 'SP20' . strtoupper(substr(uniqid(), -6));
    $stmt = $pdo->prepare("
        INSERT INTO `incidents` (
            `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
            `reporter_name`, `reporter_phone`, `category`, `description`,
            `urgency`, `status`, `pending_parts_reason`, `started_at`, `created_at`, `updated_at`
        ) VALUES (
            :ticket_code, :machine_id, :location_id,
            (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
            'Laura Sanitaria', '600111222', 'ELECTRICAL_OFF', 'Pantalla táctil sin respuesta al seleccionar producto',
            'HIGH', :status, 'Electroválvula de entrada de agua bloqueada por cal',
            NOW(), NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':ticket_code' => $ticketCode,
        ':machine_id'  => $machineId,
        ':location_id' => $locationId,
        ':status'      => $status,
    ]);
    $incidentId = (int)$pdo->lastInsertId();
    $insertContaminatingRows($incidentId, $machineId, $locationId);

    return $incidentId;
};

$incContaminated = $createContaminatedIncident($m1Id, $m1LocId, 'IN_PROGRESS');

$assert("0.3 Avería contaminada creada en VEND-0101 con solicitudes y sustituciones de repuestos", $incContaminated > 0);

// =========================================================================
// BLOQUE 1: RBAC sobre el Catálogo de Repuestos (ningún acceso para sede)
// =========================================================================
echo "\n--- BLOQUE 1: RBAC sobre el Catálogo de Repuestos (rol LOCATION_MANAGER) ---\n";

$coordinatorSpareEndpoints = [
    ['GET', '/api/coordinator/spare-parts', true],
    ['GET', '/api/coordinator/spare-parts/models', true],
    ['GET', '/api/coordinator/spare-parts/analytics', true],
    ['GET', '/api/coordinator/spare-parts/export', false],
    ['GET', '/api/coordinator/spare-parts/requests/pending-review', true],
];

foreach ($coordinatorSpareEndpoints as $idx => [$method, $uri, $isJsonEnvelope]) {
    $n = $idx + 1;

    // 1.x.a Acceso anónimo -> 401 Unauthorized
    $resAnon = $router->dispatch(new Request($method, $uri));
    $assert("1.{$n}a [{$method} {$uri}] anónimo retorna HTTP 401", $resAnon->getStatusCode() === 401);

    // 1.x.b Acceso con token interno de rol no COORDINATOR -> 403 Forbidden
    $resTech = $router->dispatch(new Request($method, $uri, [], [], ['Authorization' => 'Bearer ' . $techToken]));
    $assert("1.{$n}b [{$method} {$uri}] con rol distinto de COORDINATOR retorna HTTP 403", $resTech->getStatusCode() === 403);

    // 1.x.c Acceso legítimo del Coordinador -> 200 OK
    $resCoord = $router->dispatch(new Request($method, $uri, [], [], ['Authorization' => 'Bearer ' . $coordToken]));
    $assert("1.{$n}c [{$method} {$uri}] con COORDINATOR retorna HTTP 200", $resCoord->getStatusCode() === 200);

    if ($isJsonEnvelope) {
        $decodedCoord = json_decode($resCoord->getBody(), true);
        $assert("1.{$n}d [{$method} {$uri}] envolvente estándar success => true", ($decodedCoord['success'] ?? false) === true);
    }
}

// =========================================================================
// BLOQUE 2: Proyección del Parque de Máquinas sin Piezas ni Costes
// =========================================================================
echo "\n--- BLOQUE 2: Proyección del Parque de Máquinas sin Piezas ni Costes (RF-REP-10) ---\n";

$reqMachines = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], ['Authorization' => 'Bearer ' . $siteToken]);
$resMachines = $router->dispatch($reqMachines);

$assert("2.1 Catálogo de máquinas de sede autenticada retorna HTTP 200", $resMachines->getStatusCode() === 200);
$registerAuditPayload('GET /api/locations/SEDE-BCN-01/machines', $resMachines->getBody());

$bodyMachines = json_decode($resMachines->getBody(), true);
$dataMachines = $bodyMachines['data'] ?? [];
$assert(
    "2.2 Envolvente estándar success => true con listado de máquinas",
    ($bodyMachines['success'] ?? false) === true && is_array($dataMachines) && count($dataMachines) >= 1
);

$targetMachine = null;
foreach ($dataMachines as $m) {
    if (($m['code'] ?? '') === 'VEND-0101') {
        $targetMachine = $m;
        break;
    }
}
$assert("2.3 VEND-0101 está presente en el catálogo de la sede", $targetMachine !== null);

$activeIncident = $targetMachine['active_incident'] ?? null;
$assert(
    "2.4 La máquina reporta su avería activa (transparencia operativa permitida)",
    is_array($activeIncident) && ($activeIncident['ticket_code'] ?? '') !== '' && ($activeIncident['status'] ?? '') !== ''
);
$assert(
    "2.5 La avería expuesta NO revela su estado técnico interno de pausa por repuestos (PENDING_PARTS)",
    is_array($activeIncident) && ($activeIncident['status'] ?? '') !== 'PENDING_PARTS'
);

// Auditoría recursiva de claves prohibidas sobre el payload completo (plan técnico 5.1)
$forbiddenKeysPlan = ['replaced_parts', 'spare_parts', 'unit_cost_snapshot', 'total_cost_snapshot', 'reference_cost'];
$fugasPlan = [];
$auditKeysRecursive = function (array $node, array $parents, callable $self) use (&$fugasPlan, $forbiddenKeysPlan): void {
    foreach ($node as $key => $value) {
        if (in_array($key, $forbiddenKeysPlan, true)) {
            $fugasPlan[] = implode('.', array_merge($parents, [$key]));
        }
        if (is_array($value)) {
            $self($value, array_merge($parents, [(string)$key]), $self);
        }
    }
};
$auditKeysRecursive($bodyMachines, [], $auditKeysRecursive);
$assert(
    "2.6 Auditoría recursiva: ninguna clave de repuestos/costes en la respuesta de máquinas",
    $fugasPlan === [],
    'Claves prohibidas detectadas: ' . implode(', ', $fugasPlan)
);

// =========================================================================
// BLOQUE 3: Endpoints de Detalle del Ticket (creación, bitácora y reapertura)
// =========================================================================
echo "\n--- BLOQUE 3: Detalle de Ticket, Bitácora y Reapertura sin Datos de Piezas ---\n";

// 3.1 Creación de avería desde el portal de sede (VEND-0102, libre de avisos activos)
$reqCreate = new Request('POST', '/api/incidents', [], [
    'machine_id'    => (string)$m2Id,
    'category'      => 'ELECTRICAL_OFF',
    'description'   => 'Monedero no devuelve cambio y la máquina no dispensa',
    'reporter_name' => 'Marc Recepción',
], ['X-Site-Code' => 'SEDE-BCN-01']);
$resCreate = $router->dispatch($reqCreate);

$assert("3.1 Creación de avería desde el portal de sede retorna HTTP 201", $resCreate->getStatusCode() === 201);
$registerAuditPayload('POST /api/incidents', $resCreate->getBody());

$bodyCreate = json_decode($resCreate->getBody(), true);
$ticketCode = (string)($bodyCreate['data']['ticket_code'] ?? '');
$assert("3.2 La respuesta de creación incluye el código de ticket generado", $ticketCode !== '');

// Contaminar el ticket recién creado con piezas solicitadas, sustituidas y una nota interna de taller
$stmtTicket = $pdo->prepare("SELECT id FROM incidents WHERE ticket_code = :ticket_code");
$stmtTicket->execute([':ticket_code' => $ticketCode]);
$createdIncidentId = (int)$stmtTicket->fetchColumn();

$assert("3.2b El ticket creado es localizable en base de datos para su contaminación controlada", $createdIncidentId > 0);

if ($createdIncidentId > 0) {
    $insertContaminatingRows($createdIncidentId, $m2Id, $m2LocId);

    $pdo->prepare("
        INSERT INTO `incident_comments` (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `is_internal`)
        VALUES (:incident_id, 'COORDINATOR',
                (SELECT `id` FROM `users` WHERE `email` = 'coordinacion@vendguard.internal' LIMIT 1),
                'Sara Coordinadora', 'Nota interna de taller: el monedero exige revisión en taller central antes de reemplazo', 1)
    ")->execute([':incident_id' => $createdIncidentId]);
}

// 3.3 Nueva bitácora pública desde sede
$reqComment = new Request('POST', "/api/incidents/{$ticketCode}/comments", [], [
    'comment_text' => 'El personal de sala confirma que el fallo persiste desde esta mañana',
    'author_name'  => 'Marc Recepción',
], ['X-Site-Code' => 'SEDE-BCN-01']);
$resComment = $router->dispatch($reqComment);

$assert("3.3 Comentario público de sede registra HTTP 201", $resComment->getStatusCode() === 201);
$registerAuditPayload('POST /api/incidents/{ticket_code}/comments', $resComment->getBody());

// 3.4 Consulta de bitácora pública (los comentarios internos jamás se exponen, RNF-04)
$resBitacora = $router->dispatch(new Request('GET', "/api/incidents/{$ticketCode}/comments", [], [], ['X-Site-Code' => 'SEDE-BCN-01']));
$assert("3.4 Consulta de bitácora pública retorna HTTP 200", $resBitacora->getStatusCode() === 200);
$registerAuditPayload('GET /api/incidents/{ticket_code}/comments', $resBitacora->getBody());

$bitacoraData = json_decode($resBitacora->getBody(), true)['data'] ?? [];
$hasInternalComment = false;
foreach ($bitacoraData as $commentRow) {
    if (($commentRow['author_type'] ?? '') === 'COORDINATOR') {
        $hasInternalComment = true;
    }
}
$assert("3.5 La bitácora pública no expone la nota interna de taller", !$hasInternalComment);

// 3.6 Reapertura dentro de la ventana de garantía (el ticket debe resolverse previamente)
$pdo->prepare("
    UPDATE `incidents`
    SET `status` = 'RESOLVED', `resolved_at` = NOW(),
        `resolution_diagnosis` = 'Fallo intermitente de electrónica de potencia del monedero',
        `resolution_action` = 'Reparación de placa de control y verificación completa del monedero'
    WHERE `id` = :id
")->execute([':id' => $createdIncidentId]);

$reqReopen = new Request('POST', "/api/incidents/{$ticketCode}/reopen", [], [
    'reopen_reason' => 'La máquina volvió a fallar tras la reparación anterior',
], ['X-Site-Code' => 'SEDE-BCN-01']);
$resReopen = $router->dispatch($reqReopen);

$assert("3.6 Reapertura dentro de la ventana de garantía retorna HTTP 200", $resReopen->getStatusCode() === 200);
$registerAuditPayload('POST /api/incidents/{ticket_code}/reopen', $resReopen->getBody());

$bodyReopen = json_decode($resReopen->getBody(), true)['data'] ?? [];
$assert(
    "3.7 La reapertura devuelve el expediente sanitizado con el estado canónico REOPENED y su etiqueta",
    ($bodyReopen['status'] ?? '') === 'REOPENED'
    && ($bodyReopen['status_label'] ?? '') === 'Reabierta'
    && is_array($bodyReopen['incident'] ?? null)
);

// =========================================================================
// BLOQUE 4: Blindaje del Sanitizador de Sede (RF-REP-10)
// =========================================================================
echo "\n--- BLOQUE 4: Blindaje del Sanitizador de Sede (LocationPortalController) ---\n";

$hostilePayload = [
    'ticket_code'                    => 'SP20HOSTILE',
    'status'                         => 'IN_PROGRESS',
    'description'                    => 'Descripción legítima visible para la sede',
    'pending_parts_reason'           => 'Electroválvula bloqueada',
    'spare_parts'                    => [['part_code' => 'VALV-ULKA-01', 'part_name' => 'Electroválvula Ulka']],
    'spare_part_requests'            => [['spare_part_id' => 1, 'quantity' => 2]],
    'replaced_parts'                 => [['spare_part_id' => 1, 'quantity' => 1, 'old_part_destination' => 'DESGUACE']],
    'incident_replaced_parts'        => [['unit_cost_snapshot' => '28.50', 'total_cost_snapshot' => '57.00']],
    'replaced_parts_nested'          => [['unit_cost' => '1.00', 'cost_center' => 'TALLER']],
    'unit_cost_snapshot'             => '28.50',
    'total_cost_snapshot'            => '57.00',
    'total_parts_cost'               => 57.00,
    'reference_cost'                 => '28.50',
    'costs'                          => ['repuestos' => 57.00],
    'cost'                           => 57.00,
    'old_part_destination'           => 'TALLER',
    'part_code'                      => 'VALV-ULKA-01',
    'part_name'                      => 'Electroválvula Ulka',
    'technician_phone'               => '677222333',
    'internal_notes'                 => 'Nota interna de taller',
    'notes_taller'                   => 'Pendiente de revisión de taller',
    'technician_spare_parts_catalog' => 'alias regex a eliminar',
    'machine_replaced_part_id'       => 'alias regex a eliminar',
    'unit_cost_estimate'             => 'alias regex a eliminar',
    'notes_machine'                  => 'Campo legítimo que debe sobrevivir',
    'floor_wing'                     => 'Planta 1 - Sala Médica',
];

$sanitized = $portalController->sanitizeIncidentForSite($hostilePayload);

$forbiddenExactKeysBlock4 = [
    'pending_parts_reason', 'spare_parts', 'spare_part_requests', 'replaced_parts', 'incident_replaced_parts',
    'unit_cost_snapshot', 'total_cost_snapshot', 'total_parts_cost', 'reference_cost', 'costs', 'cost',
    'old_part_destination', 'part_code', 'part_name', 'technician_phone', 'internal_notes', 'notes_taller',
];

foreach ($forbiddenExactKeysBlock4 as $i => $forbiddenKey) {
    $n = $i + 1;
    $assert("4.{$n} Sanitizador elimina la clave prohibida '{$forbiddenKey}'", !array_key_exists($forbiddenKey, $sanitized ?? []));
}

$assert("4.18 Sanitizador elimina el alias regex 'technician_spare_parts_catalog'", !array_key_exists('technician_spare_parts_catalog', $sanitized ?? []));
$assert("4.19 Sanitizador elimina el alias regex 'machine_replaced_part_id'", !array_key_exists('machine_replaced_part_id', $sanitized ?? []));
$assert("4.20 Sanitizador elimina el alias regex 'unit_cost_estimate'", !array_key_exists('unit_cost_estimate', $sanitized ?? []));
$assert("4.21 Sanitizador elimina el bloque anidado 'replaced_parts_nested' por alias regex", !isset($sanitized['replaced_parts_nested']));
$assert("4.22 Sanitizador preserva la clave legítima 'notes_machine'", ($sanitized['notes_machine'] ?? null) === 'Campo legítimo que debe sobrevivir');
$assert("4.23 Sanitizador preserva la clave legítima 'floor_wing'", ($sanitized['floor_wing'] ?? null) === 'Planta 1 - Sala Médica');
$assert("4.24 Sanitizador retorna null ante entrada nula", $portalController->sanitizeIncidentForSite(null) === null);

// =========================================================================
// BLOQUE 5: Auditoría Constitucional Global de Todos los Payloads de Sede
// =========================================================================
echo "\n--- BLOQUE 5: Auditoría Constitucional Global de Payloads de Sede (Art. V.4) ---\n";

$forbiddenExactKeys = [
    'pending_parts_reason', 'spare_parts', 'spare_part_requests', 'replaced_parts', 'incident_replaced_parts',
    'unit_cost_snapshot', 'total_cost_snapshot', 'total_parts_cost', 'reference_cost', 'costs', 'cost',
    'old_part_destination', 'part_code', 'part_name', 'technician_phone', 'internal_notes', 'notes_taller',
];

$forbiddenKeyPatterns = [
    '/\bspares?_part/i',
    '/\breplaced_part/i',
    '/\bpart_code\b/i',
    '/\bpart_name\b/i',
    '/\b(old_)?part_destination\b/i',
    '/unit_cost/i',
    '/total_cost/i',
    '/cost_snapshot/i',
    '/reference_cost/i',
    '/custom_part/i',
    '/\bDESGUACE\b/',
    '/\bTALLER\b/',
];

$forbiddenRawPatterns = [
    'token_spares_part'          => '/\bspares?_part/i',
    'token_replaced_part'        => '/\breplaced_part/i',
    'token_part_code'            => '/\bpart_code\b/i',
    'token_part_name'            => '/\bpart_name\b/i',
    'token_part_destination'     => '/\b(old_)?part_destination\b/i',
    'token_cost_snapshots'       => '/unit_cost_snapshot|total_cost_snapshot|total_parts_cost|reference_cost/',
    'token_pending_parts_reason' => '/pending_parts_reason/',
    'token_out_of_catalog'       => '/is_out_of_catalog|custom_part_name|custom_part_description/',
    'token_importe_28_50'        => '/28\.50/',
    'token_importe_57_00'        => '/57\.00/',
    'token_importe_185_00'       => '/185\.00/',
    'token_destino_desguace'     => '/\bDESGUACE\b/',
    'token_destino_taller'       => '/\bTALLER\b/',
];

$findForbiddenKeys = function (array $node, array $parents, callable $self) use ($forbiddenExactKeys, $forbiddenKeyPatterns): array {
    $found = [];
    foreach ($node as $key => $value) {
        $path = array_merge($parents, [(string)$key]);
        if (in_array($key, $forbiddenExactKeys, true)) {
            $found[] = implode('.', $path);
        } else {
            foreach ($forbiddenKeyPatterns as $pattern) {
                if (preg_match($pattern, (string)$key) === 1) {
                    $found[] = implode('.', $path);
                    break;
                }
            }
        }
        if (is_array($value)) {
            $found = array_merge($found, $self($value, $path, $self));
        }
    }
    return $found;
};

$fugasGlobales = [];
foreach ($auditPayloads as $payload) {
    $decoded = json_decode($payload['json'], true);
    if (is_array($decoded)) {
        foreach ($findForbiddenKeys($decoded, [], $findForbiddenKeys) as $leakPath) {
            $fugasGlobales[] = "[{$payload['label']}] clave prohibida '{$leakPath}'";
        }
    }
    foreach ($forbiddenRawPatterns as $tokenName => $pattern) {
        if (preg_match($pattern, $payload['json']) === 1) {
            $fugasGlobales[] = "[{$payload['label']}] token crudo '{$tokenName}' presente en el JSON serializado";
        }
    }
}

$assert(
    "5.1 Se auditaron todos los endpoints del portal de sede registrados (" . count($auditPayloads) . " payloads)",
    count($auditPayloads) === 5
);
$assert(
    "5.2 Auditoría global: NINGÚN endpoint de sede expone piezas solicitadas, sustituidas, destinos ni costes (Art. V.4)",
    $fugasGlobales === [],
    "Fugas detectadas:\n         - " . implode("\n         - ", $fugasGlobales)
);

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

echo "\n======================================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: ¡Todas las pruebas pasaron con éxito! Blindaje constitucional Art. V.4 certificado (0 fallos).\n";
} else {
    echo " RESULTADO: {$failures} prueba(s) fallaron de {$assertions} aserciones.\n";
    exit(1);
}
echo "======================================================================================\n";
