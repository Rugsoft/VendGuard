<?php

declare(strict_types=1);

/**
 * VendGuard - LocationSatisfactionApiTest (T-CSAT-05)
 *
 * Test de integración HTTP contra el motor único real de la encuesta de
 * satisfacción vista desde el Responsable de Sede (Módulo 20 · RF-01, RF-02,
 * RF-03 · R2, R3 · Contratos §3, §4, §5).
 *
 * Valida la condición "Hecho cuando":
 * 1. El listado de pendientes devuelve los episodios resueltos sin valorar de la
 *    sede autenticada, con cinco claves operativas y sin identidad técnica.
 * 2. El envío válido responde 201, copia `resolution_at` del expediente, guarda
 *    el comentario normalizado y deja el evento de auditoría de la sede.
 * 3. La precedencia declarada se cumple: 401 → 404 → 403 → 422 de
 *    disponibilidad → 400/422 de cuerpo → 409 de duplicado (R9).
 * 4. Una reapertura invalida el episodio (422) y un cierre nuevo admite una
 *    valoración nueva sin borrar la anterior (RD-01).
 * 5. El tope de 20 no trunca `pending_total` (R3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y el mismo `AppRouter` que atiende al
 * servidor web (sin frameworks ni dependencias externas).
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
echo " VendGuard: Test de Integración - LocationSatisfactionApiTest (T-CSAT-05)\n";
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

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";

        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/**
 * Despacha una petición real por el enrutador y devuelve estado, cuerpo y crudo.
 *
 * @param array<string, mixed> $body
 * @return array{status: int, body: array<string, mixed>, raw: string}
 */
$call = static function (string $method, string $path, ?string $token, array $body = []) use ($router): array {
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        queryParams: [],
        parsedBody: $body,
        headers: $token === null ? [] : ['Authorization' => 'Bearer ' . $token]
    ));

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->getDecodedBody() ?? [],
        'raw' => $response->getBody(),
    ];
};

$errorCode = static fn(array $result): string => (string)($result['body']['error']['code'] ?? '');

// ─── Escenario ───────────────────────────────────────────────────────────────
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$otherLocation = $locationRepo->findBySiteCode('SEDE-BCN-02');

$assert('0.1 Semillas localizadas (sede propia y sede ajena)',
    $location !== null && $otherLocation !== null);

if ($location === null || $otherLocation === null) {
    echo "ERROR CRÍTICO: faltan las sedes de la semilla.\n";
    exit(1);
}

$machine = $machineRepo->findActiveByLocationId($location->getId())[0] ?? null;
$assert('0.2 Máquina activa de la sede localizada', $machine !== null);

if ($machine === null) {
    echo "ERROR CRÍTICO: la sede no tiene máquinas activas.\n";
    exit(1);
}

$machineId = (int)$machine->getId();

// Liberar avisos previos de la máquina (restricción uq_machine_active_ticket).
TestDataCleaner::purgeIncidentsByMachine($pdo, $machineId);

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'CSAT-LOC-' . strtoupper(substr(uniqid(), -6)),
    machineId: $machineId,
    locationId: (int)$location->getId(),
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Expediente para validar la encuesta de satisfacción de la sede.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Conserjería Principal',
    reporterPhone: '633444555',
    createdAt: null
));

$incidentId = (int)$incident->getId();
$ticketCode = $incident->getTicketCode();

$assert('0.3 Expediente de prueba creado', $incidentId > 0 && $ticketCode !== '');

$siteToken = $authService->generateSiteToken($location);
$otherSiteToken = $authService->generateSiteToken($otherLocation);

// Línea base del listado ANTES de crear el expediente: así los recuentos
// posteriores son incrementos verificables y no dependen de la semilla.
$baseline = $call('GET', '/api/location/satisfaction-surveys/pending', $siteToken);
$baselinePending = (int)($baseline['body']['data']['pending_total'] ?? -1);

$assert('0.4 El listado previo responde 200 y publica un total entero',
    $baseline['status'] === 200 && $baselinePending >= 0,
    "status: {$baseline['status']}, total: {$baselinePending}");

// Cierre del servicio: estado resuelto y ancla de resolución.
$resolve = $pdo->prepare("UPDATE incidents SET status = 'RESOLVED', resolved_at = :resolved_at WHERE id = :id");
$resolve->execute([':resolved_at' => '2026-10-22 17:04:11+02', ':id' => $incidentId]);

// =========================================================================
// CASO 1: Descubrimiento de pendientes (RF-01)
// =========================================================================
echo "\n--- Caso 1: Descubrimiento de episodios pendientes ---\n";

$anonymous = $call('GET', '/api/location/satisfaction-surveys/pending', null);
$assert('1.1 Sin token => HTTP 401 UNAUTHORIZED antes de leer nada',
    $anonymous['status'] === 401 && $errorCode($anonymous) === 'UNAUTHORIZED',
    "status: {$anonymous['status']}");

$pending = $call('GET', '/api/location/satisfaction-surveys/pending', $siteToken);
$items = is_array($pending['body']['data']['items'] ?? null) ? $pending['body']['data']['items'] : [];
$mine = array_values(array_filter(
    $items,
    static fn(array $item): bool => (int)($item['incident_id'] ?? 0) === $incidentId
))[0] ?? null;

$assert('1.2 El listado de sede responde 200', $pending['status'] === 200,
    "status: {$pending['status']}");

$assert('1.3 El servicio resuelto sin valorar aparece en los pendientes', $mine !== null);

$assert('1.4 El elemento expone exactamente las cinco claves del contrato §3.1',
    $mine !== null && array_keys($mine) === ['incident_id', 'ticket_code', 'machine_code', 'machine_model', 'resolved_at'],
    $mine === null ? 'sin elemento' : implode(',', array_keys($mine)));

$assert('1.5 El elemento lleva el código de ticket y la máquina',
    $mine !== null && $mine['ticket_code'] === $ticketCode && $mine['machine_code'] === $machine->getCode());

$assert('1.6 El payload crudo del listado no contiene identidad técnica',
    stripos($pending['raw'], 'technician') === false && strpos($pending['raw'], 'assigned_technician_id') === false);

$assert('1.7 pending_total cuenta el episodio nuevo sin truncarlo',
    (int)($pending['body']['data']['pending_total'] ?? -1) === $baselinePending + 1,
    "total: " . ($pending['body']['data']['pending_total'] ?? 'ausente'));

// =========================================================================
// CASO 2: Precedencia de errores (R9)
// =========================================================================
echo "\n--- Caso 2: Precedencia 404 → 403 → 422 → 400/422 → 409 ---\n";

$notFound = $call('POST', '/api/location/incidents/999999999/satisfaction-survey', $siteToken, ['rating' => 5]);
$assert('2.1 Avería inexistente => HTTP 404 INCIDENT_NOT_FOUND',
    $notFound['status'] === 404 && $errorCode($notFound) === 'INCIDENT_NOT_FOUND',
    "status: {$notFound['status']}");

$nonNumeric = $call('POST', '/api/location/incidents/abc/satisfaction-survey', $siteToken, ['rating' => 5]);
$assert('2.2 {id} no numérico => HTTP 404 INCIDENT_NOT_FOUND',
    $nonNumeric['status'] === 404 && $errorCode($nonNumeric) === 'INCIDENT_NOT_FOUND',
    "status: {$nonNumeric['status']}");

$foreign = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $otherSiteToken, []);
$assert('2.3 Otra sede con cuerpo vacío => HTTP 403 SITE_MISMATCH (la sede se decide antes que el cuerpo)',
    $foreign['status'] === 403 && $errorCode($foreign) === 'SITE_MISMATCH',
    "status: {$foreign['status']}");

$missingRating = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['comment' => 'Sin nota']);
$assert('2.4 Sin rating => HTTP 400 MISSING_RATING',
    $missingRating['status'] === 400 && $errorCode($missingRating) === 'MISSING_RATING',
    "status: {$missingRating['status']}");

$outOfRange = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['rating' => 6]);
$assert('2.5 Nota 6 => HTTP 422 INVALID_RATING con detalle de campo',
    $outOfRange['status'] === 422
        && $errorCode($outOfRange) === 'INVALID_RATING'
        && ($outOfRange['body']['error']['details']['rating'] ?? '') !== '',
    "status: {$outOfRange['status']}");

$decimalString = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['rating' => '4.0']);
$assert('2.6 Nota «4.0» => HTTP 422 INVALID_RATING (R9)',
    $decimalString['status'] === 422 && $errorCode($decimalString) === 'INVALID_RATING',
    "status: {$decimalString['status']}");

$longComment = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, [
    'rating' => 5,
    'comment' => str_repeat('a', 501),
]);
$assert('2.7 Comentario de 501 caracteres => HTTP 422 COMMENT_TOO_LONG',
    $longComment['status'] === 422 && $errorCode($longComment) === 'COMMENT_TOO_LONG',
    "status: {$longComment['status']}");

$notText = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, [
    'rating' => 5,
    'comment' => ['no', 'es', 'texto'],
]);
$assert('2.8 Comentario no textual => HTTP 422 COMMENT_TOO_LONG con el mensaje de tipo',
    $notText['status'] === 422
        && ($notText['body']['error']['message'] ?? '') === 'El comentario debe ser texto.',
    "status: {$notText['status']}");

// =========================================================================
// CASO 3: Alta correcta y auditoría (RF-02, RF-03)
// =========================================================================
echo "\n--- Caso 3: Alta correcta, ancla y auditoría ---\n";

$created = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, [
    'rating' => '5',
    'comment' => '  Vinieron el mismo día.  ',
]);
$survey = $created['body']['data'] ?? [];

$assert('3.1 El envío válido responde HTTP 201', $created['status'] === 201,
    "status: {$created['status']}");

$assert('3.2 La respuesta trae id, ticket, nota y comentario normalizado',
    (int)($survey['id'] ?? 0) > 0
        && ($survey['incident_id'] ?? 0) === $incidentId
        && ($survey['ticket_code'] ?? '') === $ticketCode
        && ($survey['rating'] ?? 0) === 5
        && ($survey['comment'] ?? '') === 'Vinieron el mismo día.',
    json_encode($survey));

$assert('3.3 La respuesta 201 no expone identidad técnica',
    stripos($created['raw'], 'technician') === false && strpos($created['raw'], 'Ana') === false);

$stored = $pdo->prepare(
    'SELECT id, resolution_at, rating, comment, technician_id, created_at
       FROM incident_satisfaction_surveys
      WHERE incident_id = :incident_id'
);
$stored->execute([':incident_id' => $incidentId]);
$storedRow = $stored->fetch(PDO::FETCH_ASSOC) ?: [];

// El motor devuelve el `timestamptz` en la zona de sesión (UTC), así que el
// invariante se comprueba contra la misma columna leída del expediente: misma
// representación, mismo instante. Comparar con el literal escrito probaría el
// formato del motor, no la regla de negocio.
$anchorStatement = $pdo->prepare('SELECT resolved_at FROM incidents WHERE id = :id');
$anchorStatement->execute([':id' => $incidentId]);
$anchorValue = (string)$anchorStatement->fetchColumn();

$assert('3.4 resolution_at se copia exactamente de incidents.resolved_at',
    (string)($storedRow['resolution_at'] ?? '') === $anchorValue
        && $anchorValue !== ''
        && strtotime($anchorValue) === strtotime('2026-10-22 17:04:11+02'),
    (string)($storedRow['resolution_at'] ?? 'ausente') . ' vs ' . $anchorValue);

$assert('3.5 El comentario se persiste normalizado y la nota como entero',
    ($storedRow['comment'] ?? null) === 'Vinieron el mismo día.'
        && (int)($storedRow['rating'] ?? 0) === 5);

$audit = $pdo->prepare(
    "SELECT action, entity_type, entity_id, user_role, user_name, new_state
       FROM audit_log
      WHERE action = 'SUBMIT_SATISFACTION_SURVEY' AND entity_id = :incident_id
      ORDER BY id DESC
      LIMIT 1"
);
$audit->execute([':incident_id' => $incidentId]);
$auditRow = $audit->fetch(PDO::FETCH_ASSOC) ?: [];
$auditState = is_string($auditRow['new_state'] ?? null) ? json_decode($auditRow['new_state'], true) : [];

$assert('3.6 Se escribe el evento SUBMIT_SATISFACTION_SURVEY sobre el expediente',
    ($auditRow['action'] ?? '') === 'SUBMIT_SATISFACTION_SURVEY'
        && ($auditRow['entity_type'] ?? '') === 'TICKET'
        && (int)($auditRow['entity_id'] ?? 0) === $incidentId,
    json_encode($auditRow));

$assert('3.7 El evento lo firma la sede con su nombre, no una persona (R2)',
    ($auditRow['user_role'] ?? '') === 'SITE_RESPONSIBLE'
        && ($auditRow['user_name'] ?? '') === $location->getName(),
    ($auditRow['user_role'] ?? 'ausente') . ' · ' . ($auditRow['user_name'] ?? 'ausente'));

$assert('3.8 El estado nuevo del evento ancla la encuesta a su episodio',
    (int)($auditState['survey_id'] ?? 0) === (int)($storedRow['id'] ?? -1)
        && (int)($auditState['rating'] ?? 0) === 5
        && ($auditState['resolution_at'] ?? '') === $anchorValue,
    json_encode($auditState));

// =========================================================================
// CASO 4: El episodio valorado deja de estar pendiente y no admite duplicado
// =========================================================================
echo "\n--- Caso 4: Pendientes tras valorar y duplicado ---\n";

$afterRating = $call('GET', '/api/location/satisfaction-surveys/pending', $siteToken);
$afterItems = is_array($afterRating['body']['data']['items'] ?? null) ? $afterRating['body']['data']['items'] : [];
$stillPending = array_filter($afterItems, static fn(array $item): bool => (int)($item['incident_id'] ?? 0) === $incidentId);

$assert('4.1 El episodio valorado desaparece del listado',
    $stillPending === [],
    (string)count($stillPending));

$assert('4.2 pending_total vuelve al valor previo al alta',
    (int)($afterRating['body']['data']['pending_total'] ?? -1) === $baselinePending,
    'total: ' . ($afterRating['body']['data']['pending_total'] ?? 'ausente'));

$duplicate = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['rating' => 4]);
$assert('4.3 Segundo envío sobre el mismo episodio => HTTP 409 SURVEY_ALREADY_SUBMITTED',
    $duplicate['status'] === 409 && $errorCode($duplicate) === 'SURVEY_ALREADY_SUBMITTED',
    "status: {$duplicate['status']}");

$countSurveys = $pdo->prepare('SELECT COUNT(*) FROM incident_satisfaction_surveys WHERE incident_id = :id');
$countSurveys->execute([':id' => $incidentId]);
$assert('4.4 El rechazo no deja una segunda fila', (int)$countSurveys->fetchColumn() === 1);

// =========================================================================
// CASO 5: Reapertura y nuevo episodio (RD-01)
// =========================================================================
echo "\n--- Caso 5: Reapertura y nuevo episodio ---\n";

$pdo->prepare("UPDATE incidents SET status = 'IN_PROGRESS', resolved_at = NULL WHERE id = :id")
    ->execute([':id' => $incidentId]);

$reopened = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['rating' => 5]);
$assert('5.1 Avería reabierta => HTTP 422 SURVEY_NOT_AVAILABLE',
    $reopened['status'] === 422 && $errorCode($reopened) === 'SURVEY_NOT_AVAILABLE',
    "status: {$reopened['status']}");

$pdo->prepare("UPDATE incidents SET status = 'RESOLVED', resolved_at = '2026-10-25 09:30:00+02' WHERE id = :id")
    ->execute([':id' => $incidentId]);

$secondEpisode = $call('POST', "/api/location/incidents/{$incidentId}/satisfaction-survey", $siteToken, ['rating' => 3]);
$assert('5.2 El cierre nuevo admite una valoración nueva',
    $secondEpisode['status'] === 201
        && ($secondEpisode['body']['data']['rating'] ?? 0) === 3,
    "status: {$secondEpisode['status']}");

$countSurveys->execute([':id' => $incidentId]);
$episodeRows = (int)$countSurveys->fetchColumn();
$assert('5.3 La valoración del episodio anterior sigue guardada (dos episodios, dos filas)',
    $episodeRows === 2,
    'filas: ' . $episodeRows);

// =========================================================================
// CASO 6: El tope de 20 no trunca pending_total (R3)
// =========================================================================
echo "\n--- Caso 6: Tope de presentación y total real ---\n";

$baselineBeforeCap = (int)($afterRating['body']['data']['pending_total'] ?? -1);

$insert = $pdo->prepare(
    "INSERT INTO incidents
        (ticket_code, machine_id, location_id, category, description, urgency, status, resolved_at)
     VALUES
        (:ticket_code, :machine_id, :location_id, 'OTHER', 'Episodio antiguo pendiente de valorar para el tope de presentación.', 'LOW', 'CLOSED', :resolved_at)"
);

for ($index = 1; $index <= 21; $index++) {
    $insert->execute([
        ':ticket_code' => sprintf('CSAT-CAP-%02d-%s', $index, strtoupper(substr(uniqid(), -4))),
        ':machine_id' => $machineId,
        ':location_id' => (int)$location->getId(),
        ':resolved_at' => sprintf('2026-08-%02d 10:00:00+02', $index),
    ]);
}

$capped = $call('GET', '/api/location/satisfaction-surveys/pending', $siteToken);
$cappedItems = is_array($capped['body']['data']['items'] ?? null) ? $capped['body']['data']['items'] : [];
$cappedTotal = (int)($capped['body']['data']['pending_total'] ?? -1);

$assert('6.1 El listado se corta en 20 elementos',
    count($cappedItems) === 20,
    'elementos: ' . count($cappedItems));

$assert('6.2 pending_total publica los 21 pendientes reales sin truncarlos',
    $cappedTotal === $baselineBeforeCap + 21,
    "total: {$cappedTotal}, esperado: " . ($baselineBeforeCap + 21));

$assert('6.3 El orden es resolved_at DESC (el más reciente primero)',
    count($cappedItems) > 1
        && strtotime((string)$cappedItems[0]['resolved_at']) >= strtotime((string)$cappedItems[1]['resolved_at']));

// ─── Limpieza final (convención de la batería) ─────────────────────────────
TestDataCleaner::purge($pdo);
$seedRunner->seedAll();
echo "\nBase de datos restablecida a las semillas (limpieza de la suite).\n";

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. LOCATION SATISFACTION API (T-CSAT-05) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
