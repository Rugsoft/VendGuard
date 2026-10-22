<?php

declare(strict_types=1);

/**
 * ServiceSheetDocumentTest (T-SIGN-05)
 *
 * Certifica la superficie HTTP de lectura del albarán de servicio (Módulo 19) de punta a
 * punta: router real, middleware de rol, controladores, repositorio PDO y motor PostgreSQL.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Registro y blindaje:** las dos rutas `GET … /service-sheet` existen y exigen sesión.
 *   2. **Documento congelado (§4.4):** la respuesta trae `document`, `sheet`, `current` y
 *      `history`, con el trazo en base64 sin prefijo `data:` y las piezas por nombre y cantidad.
 *   3. **Acceso (RD-17):** el técnico asignado lee; el técnico firmante sigue leyendo después de
 *      ser desasignado; un técnico ajeno recibe `403 SERVICE_SHEET_FORBIDDEN`.
 *   4. **Errores del contrato (§4.4):** `404 INCIDENT_NOT_FOUND`, `422 INCIDENT_NOT_RESOLVED` y
 *      `404 SERVICE_SHEET_NOT_FOUND` cuando el `sheet_id` no pertenece a la avería.
 *   5. **Vigencia e histórico (S7, S11):** tras reabrir y volver a cerrar **sin** hoja, el
 *      albarán vigente es la proyección viva —`sheet: null`— y nunca reutiliza la firma anterior.
 *   6. **Lectura auditada (S15):** cada `GET` deja `VIEW_SERVICE_SHEET` con actor, hoja y vigencia.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentServiceSheet;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentServiceSheetRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - ServiceSheetDocumentTest (T-SIGN-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$sheetRepo    = new PdoIncidentServiceSheetRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$assertions = 0;
$failures   = 0;

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

// ─── Usuarios, tokens y flota sembrada ─────────────────────────────────────────

$technicianRows = $pdo->query(
    "SELECT id FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL ORDER BY id LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$primaryTech = isset($technicianRows[0]) ? $userRepo->findById((int)$technicianRows[0]) : null;
$secondTech  = isset($technicianRows[1]) ? $userRepo->findById((int)$technicianRows[1]) : null;
$foreignTech = isset($technicianRows[2]) ? $userRepo->findById((int)$technicianRows[2]) : null;

$assert(
    '0.1 Semilla disponible: coordinador y tres técnicos distintos',
    $coordinator instanceof User && $primaryTech instanceof User && $secondTech instanceof User && $foreignTech instanceof User,
    'Sin tres técnicos no se puede distinguir firmante, asignado y ajeno.'
);

if (
    !$coordinator instanceof User || !$primaryTech instanceof User
    || !$secondTech instanceof User || !$foreignTech instanceof User
) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$machinePool = $pdo->query(
    "SELECT m.id AS machine_id, m.location_id AS location_id
       FROM machines m
      WHERE m.is_active = TRUE
        AND NOT EXISTS (
            SELECT 1 FROM incidents i
             WHERE i.machine_id = m.id
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.2 Flota sembrada suficiente para el banco de pruebas (>= 4 máquinas libres)',
    count($machinePool) >= 4,
    sprintf('El parque sembrado expone %d máquinas libres; la suite estrena una por expediente.', count($machinePool))
);

if (count($machinePool) < 4) {
    echo "ERROR FATAL: flota sembrada insuficiente para el banco de pruebas.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$primaryToken     = $authService->generateInternalToken($primaryTech);
$secondToken      = $authService->generateInternalToken($secondTech);
$foreignToken     = $authService->generateInternalToken($foreignTech);
$primaryTechId    = (int)$primaryTech->getId();
$secondTechId     = (int)$secondTech->getId();
$coordinatorId    = (int)$coordinator->getId();
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

/** @return array{machine_id: int, location_id: int} */
$nextMachine = function () use (&$machinePool): array {
    $machine = array_shift($machinePool);
    if ($machine === null) {
        throw new RuntimeException('Banco de pruebas agotado: la suite necesita una máquina sembrada por expediente.');
    }

    return ['machine_id' => (int)$machine['machine_id'], 'location_id' => (int)$machine['location_id']];
};

$createAssignedTicket = function (int $technicianId) use (
    $incidentRepo,
    $coordinatorId,
    $suffix,
    &$sequence,
    $nextMachine
): int {
    $sequence++;
    $machine = $nextMachine();

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-SIGN-RD-%s-%02d', $suffix, $sequence),
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas de los endpoints de lectura del albarán.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return (int)$created->getId();
};

$diagnosis = 'Bobina del relé térmico quemada por un pico de tensión en el cuadro.';
$action    = 'Sustituido el relé térmico y verificado el ciclo de frío estabilizado a 4 ºC.';

/** PNG sintáctico: supera la cota mínima del CHECK y la firma mágica. */
$pngOf = static fn (int $size = 160): string => "\x89PNG\r\n\x1a\n" . str_repeat("\x00", $size - 8);

$signSheet = static function (int $incidentId, int $technicianId) use ($sheetRepo, $pngOf): int {
    $sheet = $sheetRepo->insert(new IncidentServiceSheet(
        id: null,
        incidentId: $incidentId,
        technicianId: $technicianId,
        signerName: 'María López Ferrer',
        signerDocument: '12345678Z',
        signaturePng: $pngOf(),
        absenceReason: null,
    ));

    return (int)$sheet->getId();
};

$get = static function (string $path, array $headers = [], array $query = []) use ($router): Response {
    return $router->dispatch(new Request(method: 'GET', path: $path, queryParams: $query, headers: $headers));
};

$dataOf   = static fn (Response $res): array => (array)($res->getDecodedBody()['data'] ?? []);
$codeOf   = static fn (Response $res): ?string => $res->getDecodedBody()['error']['code'] ?? null;

$countViews = static function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'VIEW_SERVICE_SHEET' AND entity_id = :id"
    );
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. Registro, blindaje y documento congelado
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- 1. Rutas registradas y documento congelado (§4.4) ---\n";

$signedIncidentId = $createAssignedTicket($primaryTechId);
$incidentRepo->startIntervention($signedIncidentId, $primaryTechId);
$incidentRepo->resolve($signedIncidentId, $primaryTechId, $diagnosis, $action);
$signedSheetId = $signSheet($signedIncidentId, $primaryTechId);

$anonymous = $get('/api/technician/incidents/' . $signedIncidentId . '/service-sheet');
$assert('1.1 Sin sesión => 401', $anonymous->getStatusCode() === 401, 'status=' . $anonymous->getStatusCode());

$response = $get('/api/technician/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($primaryToken));
$data     = $dataOf($response);

$assert('1.2 El técnico asignado lee su albarán => 200', $response->getStatusCode() === 200, 'status=' . $response->getStatusCode());
$assert(
    '1.3 El modelo publicado tiene las cinco claves del contrato',
    array_keys($data) === ['ticket_code', 'status', 'current', 'document', 'sheet', 'history'],
    json_encode(array_keys($data))
);
$assert('1.4 La hoja vigente es la firmada', ($data['sheet']['id'] ?? null) === $signedSheetId && ($data['current'] ?? false) === true, json_encode($data['sheet'] ?? null));
$assert(
    '1.5 El trazo viaja en base64 sin prefijo `data:`',
    ($data['sheet']['png_base64'] ?? '') === base64_encode($pngOf()),
    'base64 inesperado'
);
$assert('1.6 El firmante y su documento vienen normalizados', ($data['sheet']['signer_name'] ?? null) === 'María López Ferrer' && ($data['sheet']['signer_document'] ?? null) === '12345678Z');
$assert(
    '1.7 El documento congelado conserva ticket, técnico y piezas',
    ($data['document']['ticket_code'] ?? null) === $data['ticket_code']
        && ($data['document']['technician']['name'] ?? null) === $primaryTech->getName()
        && ($data['document']['parts'] ?? null) === [],
    json_encode($data['document'] ?? null)
);
$assert('1.8 El historial declara la hoja vigente', count($data['history'] ?? []) === 1 && ($data['history'][0]['id'] ?? null) === $signedSheetId && ($data['history'][0]['current'] ?? false) === true, json_encode($data['history'] ?? null));

$coordinatorResponse = $get('/api/coordinator/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($coordinatorToken));
$assert(
    '1.9 La coordinación lee cualquier albarán => 200',
    $coordinatorResponse->getStatusCode() === 200
        && ($dataOf($coordinatorResponse)['sheet']['id'] ?? null) === $signedSheetId,
    'status=' . $coordinatorResponse->getStatusCode()
);

$assert('1.10 Técnico y coordinación dejan sendos VIEW_SERVICE_SHEET', $countViews($signedIncidentId) === 2, 'eventos=' . $countViews($signedIncidentId));

// ─────────────────────────────────────────────────────────────────────────────
// 2. Acceso (RD-17) y errores del contrato
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- 2. Acceso y catálogo de errores (§4.4, RD-17) ---\n";

$forbidden = $get('/api/technician/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($foreignToken));
$assert(
    '2.1 Un técnico ni asignado ni firmante recibe 403 SERVICE_SHEET_FORBIDDEN',
    $forbidden->getStatusCode() === 403 && $codeOf($forbidden) === 'SERVICE_SHEET_FORBIDDEN',
    'status=' . $forbidden->getStatusCode() . ' code=' . (string)$codeOf($forbidden)
);
$assert('2.2 El acceso denegado no se audita', $countViews($signedIncidentId) === 2, 'eventos=' . $countViews($signedIncidentId));

$unresolvedId = $createAssignedTicket($primaryTechId);
$unresolved   = $get('/api/technician/incidents/' . $unresolvedId . '/service-sheet', $authHeader($primaryToken));
$assert(
    '2.3 Una avería nunca resuelta responde 422 INCIDENT_NOT_RESOLVED',
    $unresolved->getStatusCode() === 422 && $codeOf($unresolved) === 'INCIDENT_NOT_RESOLVED',
    'status=' . $unresolved->getStatusCode() . ' code=' . (string)$codeOf($unresolved)
);

$missing = $get('/api/coordinator/incidents/99999999/service-sheet', $authHeader($coordinatorToken));
$assert(
    '2.4 Una avería inexistente responde 404 INCIDENT_NOT_FOUND',
    $missing->getStatusCode() === 404 && $codeOf($missing) === 'INCIDENT_NOT_FOUND',
    'status=' . $missing->getStatusCode() . ' code=' . (string)$codeOf($missing)
);

$otherIncidentId = $createAssignedTicket($primaryTechId);
$incidentRepo->startIntervention($otherIncidentId, $primaryTechId);
$incidentRepo->resolve($otherIncidentId, $primaryTechId, $diagnosis, $action);
$otherSheetId = $signSheet($otherIncidentId, $primaryTechId);

$foreignSheet = $get(
    '/api/technician/incidents/' . $signedIncidentId . '/service-sheet',
    $authHeader($primaryToken),
    ['sheet_id' => $otherSheetId]
);
$assert(
    '2.5 Un `sheet_id` de otra avería responde 404 SERVICE_SHEET_NOT_FOUND',
    $foreignSheet->getStatusCode() === 404 && $codeOf($foreignSheet) === 'SERVICE_SHEET_NOT_FOUND',
    'status=' . $foreignSheet->getStatusCode() . ' code=' . (string)$codeOf($foreignSheet)
);

$explicit = $get(
    '/api/coordinator/incidents/' . $signedIncidentId . '/service-sheet',
    $authHeader($coordinatorToken),
    ['sheet_id' => $signedSheetId]
);
$assert(
    '2.6 Pedir la hoja concreta devuelve esa hoja',
    $explicit->getStatusCode() === 200 && ($dataOf($explicit)['sheet']['id'] ?? null) === $signedSheetId,
    'status=' . $explicit->getStatusCode()
);

$emptySheetId = $get(
    '/api/technician/incidents/' . $signedIncidentId . '/service-sheet',
    $authHeader($primaryToken),
    ['sheet_id' => '']
);
$assert(
    '2.7 Un `sheet_id` vacío responde 400 INVALID_SHEET_ID (no sirve el vigente)',
    $emptySheetId->getStatusCode() === 400 && $codeOf($emptySheetId) === 'INVALID_SHEET_ID',
    'status=' . $emptySheetId->getStatusCode() . ' code=' . (string)$codeOf($emptySheetId)
);

$nonNumericSheetId = $get(
    '/api/coordinator/incidents/' . $signedIncidentId . '/service-sheet',
    $authHeader($coordinatorToken),
    ['sheet_id' => 'abc']
);
$assert(
    '2.8 Un `sheet_id` no numérico responde 400 INVALID_SHEET_ID',
    $nonNumericSheetId->getStatusCode() === 400 && $codeOf($nonNumericSheetId) === 'INVALID_SHEET_ID',
    'status=' . $nonNumericSheetId->getStatusCode() . ' code=' . (string)$codeOf($nonNumericSheetId)
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Vigencia tras reapertura y segundo cierre sin hoja (S7, S11)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- 3. Reapertura y segundo cierre sin hoja (S7, S11) ---\n";

$incidentRepo->reopen($signedIncidentId, 'La máquina vuelve a fallar al día siguiente del cierre.');

$afterReopen = $get('/api/technician/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($primaryToken));
$afterReopenData = $dataOf($afterReopen);
$assert(
    '3.1 Tras reabrir, el técnico firmante sigue leyendo su hoja (RD-17)',
    $afterReopen->getStatusCode() === 200 && ($afterReopenData['sheet']['id'] ?? null) === $signedSheetId,
    'status=' . $afterReopen->getStatusCode()
);
$assert('3.2 La hoja anterior ya no es vigente', ($afterReopenData['current'] ?? true) === false, json_encode($afterReopenData['current'] ?? null));

// La reapertura desasigna el expediente (`assigned_technician_id = NULL`), así que el 200
// de 3.1 no puede venir de la rama «asignado»: lo decide la firma (RD-17, contratos §6.4).
$assert(
    '3.1b Tras la reapertura no hay técnico asignado',
    $incidentRepo->findById($signedIncidentId)?->getAssignedTechnicianId() === null,
    'assigned=' . var_export($incidentRepo->findById($signedIncidentId)?->getAssignedTechnicianId(), true)
);

$reopenedForeign = $get('/api/technician/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($foreignToken));
$assert('3.3 Un técnico ajeno sigue recibiendo 403 tras la reapertura', $reopenedForeign->getStatusCode() === 403, 'status=' . $reopenedForeign->getStatusCode());

$incidentRepo->assign($signedIncidentId, $secondTechId, $coordinatorId);
$incidentRepo->startIntervention($signedIncidentId, $secondTechId);
$incidentRepo->resolve($signedIncidentId, $secondTechId, $diagnosis, $action);

$secondClosure = $get('/api/coordinator/incidents/' . $signedIncidentId . '/service-sheet', $authHeader($coordinatorToken));
$secondClosureData = $dataOf($secondClosure);
$assert(
    '3.4 El cierre sin hoja publica `sheet: null` (no reutiliza la firma anterior)',
    $secondClosure->getStatusCode() === 200 && array_key_exists('sheet', $secondClosureData) && $secondClosureData['sheet'] === null,
    json_encode($secondClosureData['sheet'] ?? 'ausente')
);
$assert(
    '3.5 El documento vigente es la proyección viva del segundo técnico',
    ($secondClosureData['document']['technician']['name'] ?? null) === $secondTech->getName()
        && ($secondClosureData['current'] ?? false) === true,
    json_encode($secondClosureData['document']['technician'] ?? null)
);
$assert(
    '3.6 El historial conserva la hoja anterior como no vigente',
    count($secondClosureData['history'] ?? []) === 1
        && ($secondClosureData['history'][0]['id'] ?? null) === $signedSheetId
        && ($secondClosureData['history'][0]['current'] ?? true) === false,
    json_encode($secondClosureData['history'] ?? null)
);

$historical = $get(
    '/api/coordinator/incidents/' . $signedIncidentId . '/service-sheet',
    $authHeader($coordinatorToken),
    ['sheet_id' => $signedSheetId]
);
$historicalData = $dataOf($historical);
$assert(
    '3.7 La hoja histórica se sirve por `sheet_id` con `current: false`',
    $historical->getStatusCode() === 200
        && ($historicalData['sheet']['id'] ?? null) === $signedSheetId
        && ($historicalData['sheet']['current'] ?? true) === false
        && ($historicalData['current'] ?? true) === false,
    json_encode($historicalData['sheet'] ?? null)
);

$assert(
    '3.8 Cada lectura del periodo dejó su evento de auditoría',
    $countViews($signedIncidentId) === 6,
    'eventos=' . $countViews($signedIncidentId)
);

$auditRow = $pdo->prepare(
    "SELECT user_role, user_name, new_state FROM audit_log
      WHERE action = 'VIEW_SERVICE_SHEET' AND entity_id = :id
      ORDER BY id ASC LIMIT 1"
);
$auditRow->execute([':id' => $signedIncidentId]);
$event = $auditRow->fetch(PDO::FETCH_ASSOC) ?: [];
$eventState = json_decode((string)($event['new_state'] ?? ''), true);
$assert(
    '3.9 El evento guarda actor, hoja leída y vigencia',
    ($event['user_name'] ?? null) === $primaryTech->getName()
        && ($eventState['sheet_id'] ?? null) === $signedSheetId
        && ($eventState['current'] ?? null) === true,
    json_encode($event)
);

// ─────────────────────────────────────────────────────────────────────────────
// 3 bis. Reapertura sin hoja previa (la rama `REOPENED` del 422)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- 3 bis. Reapertura de un cierre sin hoja (S7, §4.4) ---\n";

$unsignedIncidentId = $createAssignedTicket($secondTechId);
$incidentRepo->startIntervention($unsignedIncidentId, $secondTechId);
$incidentRepo->resolve($unsignedIncidentId, $secondTechId, $diagnosis, $action);
$incidentRepo->reopen($unsignedIncidentId, 'Reincidencia sin firma previa: la sede vuelve a abrir el aviso.');

// La coordinación es la única que alcanza un expediente reabierto que nunca se firmó:
// la reapertura lo desasigna y no hay hoja que acredite al técnico (RD-17).
$unsignedRead = $get(
    '/api/coordinator/incidents/' . $unsignedIncidentId . '/service-sheet',
    $authHeader($coordinatorToken)
);
$unsignedData = $dataOf($unsignedRead);
$assert(
    '3.10 Una avería reabierta que nunca tuvo hoja sirve su proyección viva (no 422)',
    $unsignedRead->getStatusCode() === 200
        && array_key_exists('sheet', $unsignedData) && $unsignedData['sheet'] === null
        && ($unsignedData['current'] ?? true) === false
        && is_array($unsignedData['document'] ?? null),
    'status=' . $unsignedRead->getStatusCode() . ' sheet=' . json_encode($unsignedData['sheet'] ?? 'ausente')
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-SIGN-05 CERTIFICADO.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
