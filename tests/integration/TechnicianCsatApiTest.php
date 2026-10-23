<?php

declare(strict_types=1);

/**
 * TechnicianCsatApiTest · T-20.1-02
 *
 * Test de integración HTTP contra la superficie CSAT del técnico (incremento 20.1,
 * RF-20.1.1–20.1.7 · Contratos §6). Corre sobre el motor único y Postgres real.
 *
 * Valida:
 * 1. El contrato: media, conteo, distribución de cinco estrellas y tendencia mensual.
 * 2. La identidad sale del token: `technician_id` de la query se sobrescribe.
 * 3. `location_id` de la query se **descarta** (§4.1): con él el técnico aislaría una
 *    única valoración y sabría qué sede le puntuó.
 * 4. El umbral de 3 respuestas rige la media, la distribución y cada mes.
 * 5. Sin respuestas la media es `null`, nunca `0` (RF-20.1.3).
 * 6. La superficie es solo del técnico: 401 sin token, 403 para coordinación.
 * 7. No se filtran nombres de técnicos, códigos de sede ni comentarios (RF-20.1.6).
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - TechnicianCsatApiTest (T-20.1-02)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

TestDataCleaner::purge($pdo);
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$authService = new AuthService();

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
 * Despacha una petición real por el enrutador y devuelve estado y cuerpo.
 *
 * @param array<string, mixed> $query
 * @return array{status: int, body: array<string, mixed>, raw: string}
 */
$call = static function (string $path, ?string $token, array $query = []) use ($router): array {
    $response = $router->dispatch(new Request(
        method: 'GET',
        path: $path,
        queryParams: $query,
        parsedBody: [],
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
$siteA = $locationRepo->findBySiteCode('SEDE-BCN-01');
$siteB = $locationRepo->findBySiteCode('SEDE-BCN-02');

$assert('0.1 Semillas localizadas (dos sedes con máquinas activas)',
    $siteA !== null && $siteB !== null);

$machineA = $siteA !== null ? ($machineRepo->findActiveByLocationId((int)$siteA->getId())[0] ?? null) : null;
$machineB = $siteB !== null ? ($machineRepo->findActiveByLocationId((int)$siteB->getId())[0] ?? null) : null;

$assert('0.2 Cada sede tiene máquina activa para colgar el expediente', $machineA !== null && $machineB !== null);

if ($siteA === null || $siteB === null || $machineA === null || $machineB === null) {
    echo "ERROR CRÍTICO: faltan sedes o máquinas en la semilla.\n";
    exit(1);
}

// Tres técnicos: el dueño de las encuestas, uno por debajo del umbral y uno sin ninguna.
// El middleware valida que el usuario exista, así que el estado vacío necesita un
// técnico real sin encuestas, no un id inventado.
$technicians = $pdo->query(
    "SELECT id FROM users WHERE role = 'TECHNICIAN' ORDER BY id ASC LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);

$assert('0.3 La semilla aporta tres técnicos', count($technicians) === 3);

if (count($technicians) !== 3) {
    echo "ERROR CRÍTICO: la semilla no aporta tres técnicos.\n";
    exit(1);
}

$techOwner = (int)$technicians[0];
$techBelow = (int)$technicians[1];
$techEmpty = (int)$technicians[2];

$locationAId = (int)$siteA->getId();
$locationBId = (int)$siteB->getId();

// ─── Fixture: expedientes cerrados con su encuesta ───────────────────────────
$insertIncident = $pdo->prepare(
    "INSERT INTO incidents
        (ticket_code, machine_id, location_id, category, description, urgency, status, resolved_at)
     VALUES
        (:ticket_code, :machine_id, :location_id, 'OTHER', 'Expediente del banco de la superficie CSAT del técnico.', 'LOW', 'CLOSED', :resolved_at)
     RETURNING id"
);

$insertSurvey = $pdo->prepare(
    'INSERT INTO incident_satisfaction_surveys
        (incident_id, location_id, technician_id, technician_name_snapshot, resolution_at, rating)
     VALUES
        (:incident_id, :location_id, :technician_id, :technician_name, :resolution_at, :rating)'
);

$fixtureIndex = 0;

$seedSurvey = function (
    int $locationId,
    int $machineId,
    ?int $technicianId,
    ?string $technicianName,
    string $resolvedAt,
    int $rating
) use ($pdo, $insertIncident, $insertSurvey, &$fixtureIndex): void {
    $fixtureIndex++;

    $insertIncident->execute([
        ':ticket_code' => sprintf('CSAT-TEC-%02d', $fixtureIndex),
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':resolved_at' => $resolvedAt,
    ]);

    $incidentId = (int)$insertIncident->fetchColumn();

    $insertSurvey->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
    $insertSurvey->bindValue(':location_id', $locationId, PDO::PARAM_INT);
    $insertSurvey->bindValue(
        ':technician_id',
        $technicianId,
        $technicianId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $insertSurvey->bindValue(
        ':technician_name',
        $technicianName,
        $technicianName === null ? PDO::PARAM_NULL : PDO::PARAM_STR
    );
    $insertSurvey->bindValue(':resolution_at', $resolvedAt, PDO::PARAM_STR);
    $insertSurvey->bindValue(':rating', $rating, PDO::PARAM_INT);
    $insertSurvey->execute();
};

// Técnico dueño: seis respuestas. Dos en la sede A (agosto) y cuatro en la B, de las
// cuales tres caen en octubre. Si `location_id` se respetara, la sede A devolvería dos
// respuestas — y con el umbral, ninguna media: el desvío sería visible.
$seedSurvey($locationAId, (int)$machineA->getId(), $techOwner, 'Ana Ruiz', '2026-08-10 09:00:00+00', 5);
$seedSurvey($locationAId, (int)$machineA->getId(), $techOwner, 'Ana Ruiz', '2026-08-20 09:00:00+00', 4);
$seedSurvey($locationBId, (int)$machineB->getId(), $techOwner, 'Ana Ruiz', '2026-09-05 09:00:00+00', 3);
$seedSurvey($locationBId, (int)$machineB->getId(), $techOwner, 'Ana Ruiz', '2026-10-05 09:00:00+00', 5);
$seedSurvey($locationBId, (int)$machineB->getId(), $techOwner, 'Ana Ruiz', '2026-10-06 09:00:00+00', 5);
$seedSurvey($locationBId, (int)$machineB->getId(), $techOwner, 'Ana Ruiz', '2026-10-07 09:00:00+00', 4);

// Segundo técnico: dos respuestas, por debajo del umbral de muestra (D4).
$seedSurvey($locationAId, (int)$machineA->getId(), $techBelow, 'Jordi Vega', '2026-10-10 09:00:00+00', 1);
$seedSurvey($locationAId, (int)$machineA->getId(), $techBelow, 'Jordi Vega', '2026-10-11 09:00:00+00', 1);

$assert('0.4 Ocho encuestas: seis del técnico dueño y dos del que no llega al umbral', $fixtureIndex === 8);

$ownerToken = $authService->generateInternalToken(
    new User($techOwner, 'Ana Ruiz', 'ana.ruta@vendguard.internal', 'hash', UserRole::TECHNICIAN)
);
$belowToken = $authService->generateInternalToken(
    new User($techBelow, 'Jordi Vega', 'jordi.ruta@vendguard.internal', 'hash', UserRole::TECHNICIAN)
);
$coordinatorToken = $authService->generateInternalToken(
    new User(1, 'Sara Coordinadora', 'coordinacion@vendguard.internal', 'hash', UserRole::COORDINATOR)
);
// Técnico sin encuestas: existe en la base (el middleware lo exige) y no tiene filas.
$emptyToken = $authService->generateInternalToken(
    new User($techEmpty, 'Marta Técnica', 'marta.ruta@vendguard.internal', 'hash', UserRole::TECHNICIAN)
);

$period = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-31'];

// =========================================================================
// CASO 1: Contrato de la superficie
// =========================================================================
echo "\n--- Caso 1: Contrato de la superficie ---\n";

$own = $call('/api/technician/my-metrics/csat', $ownerToken, $period);
$data = $own['body']['data'] ?? [];

$assert('1.1 Con token de técnico => HTTP 200', $own['status'] === 200, 'status: ' . $own['status']);
$assert('1.2 El payload solo expone tres claves',
    array_keys($data) === ['summary', 'monthly_trend', 'threshold_met'],
    implode(',', array_keys($data)));
$assert('1.3 El resumen lleva media, respuestas y distribución',
    array_keys($data['summary'] ?? []) === ['average', 'responses', 'distribution'],
    implode(',', array_keys($data['summary'] ?? [])));
$assert('1.4 La media se publica redondeada a dos decimales',
    ($data['summary']['average'] ?? null) === 4.33,
    var_export($data['summary']['average'] ?? null, true));
$assert('1.5 El conteo son las seis respuestas propias',
    ($data['summary']['responses'] ?? null) === 6,
    var_export($data['summary']['responses'] ?? null, true));
$assert('1.6 El umbral queda cumplido', ($data['threshold_met'] ?? null) === true);

$distribution = $data['summary']['distribution'] ?? [];
$assert('1.7 La distribución trae siempre las cinco estrellas', count($distribution) === 5,
    json_encode($distribution));
$assert('1.8 La distribución cuenta las notas reales',
    $distribution === ['1' => 0, '2' => 0, '3' => 1, '4' => 2, '5' => 3],
    json_encode($distribution));

// =========================================================================
// CASO 2: La tendencia también respeta el umbral
// =========================================================================
echo "\n--- Caso 2: Tendencia mensual ---\n";

$trend = $data['monthly_trend'] ?? [];
$months = array_column($trend, 'month');

$assert('2.1 El mes con tres respuestas se conserva', in_array('2026-10', $months, true),
    implode(',', $months));
$assert('2.2 El mes con dos respuestas se cae de la tendencia', !in_array('2026-08', $months, true),
    implode(',', $months));
$assert('2.3 El mes con una respuesta se cae de la tendencia', !in_array('2026-09', $months, true),
    implode(',', $months));
$assert('2.4 La media del mes conservado es la suya, no la global',
    ($trend[0]['average'] ?? null) === 4.67 && ($trend[0]['responses'] ?? null) === 3,
    json_encode($trend));

// =========================================================================
// CASO 3: La identidad sale del token, no de la query
// =========================================================================
echo "\n--- Caso 3: Identidad y privacidad de la query ---\n";

$foreignTech = $call('/api/technician/my-metrics/csat', $ownerToken, $period + ['technician_id' => $techBelow]);
$foreignData = $foreignTech['body']['data'] ?? [];

$assert('3.1 Un technician_id ajeno se sobrescribe con el del token',
    ($foreignData['summary']['responses'] ?? null) === 6,
    'respuestas: ' . var_export($foreignData['summary']['responses'] ?? null, true));

$byLocation = $call('/api/technician/my-metrics/csat', $ownerToken, $period + ['location_id' => $locationAId]);
$byLocationData = $byLocation['body']['data'] ?? [];

$assert('3.2 Un location_id en la query se descarta (§4.1)',
    ($byLocationData['summary']['responses'] ?? null) === 6,
    'respuestas: ' . var_export($byLocationData['summary']['responses'] ?? null, true));
$assert('3.3 El location_id descartado no altera la media',
    ($byLocationData['summary']['average'] ?? null) === 4.33,
    var_export($byLocationData['summary']['average'] ?? null, true));

// =========================================================================
// CASO 4: Umbral de muestra
// =========================================================================
echo "\n--- Caso 4: Umbral de muestra (D4) ---\n";

$below = $call('/api/technician/my-metrics/csat', $belowToken, $period);
$belowData = $below['body']['data'] ?? [];

$assert('4.1 Con dos respuestas => HTTP 200, sin error', $below['status'] === 200);
$assert('4.2 Con dos respuestas la media viaja a null, nunca a 0 (RF-20.1.4)',
    array_key_exists('average', $belowData['summary'] ?? [])
        && $belowData['summary']['average'] === null,
    json_encode($belowData['summary'] ?? null));
$assert('4.3 El conteo sí se publica', ($belowData['summary']['responses'] ?? null) === 2);
$assert('4.4 La distribución viaja a cero', array_sum($belowData['summary']['distribution'] ?? []) === 0,
    json_encode($belowData['summary']['distribution'] ?? []));
$assert('4.5 La tendencia viaja vacía', ($belowData['monthly_trend'] ?? null) === []);
$assert('4.6 El umbral queda sin cumplir', ($belowData['threshold_met'] ?? null) === false);

// =========================================================================
// CASO 5: Sin respuestas no es un cero (RF-20.1.3)
// =========================================================================
echo "\n--- Caso 5: Estado vacío ---\n";

$empty = $call('/api/technician/my-metrics/csat', $emptyToken, $period);
$emptyData = $empty['body']['data'] ?? [];

$assert('5.1 Sin encuestas => HTTP 200', $empty['status'] === 200);
$assert('5.2 Sin encuestas la media es null, nunca 0',
    array_key_exists('average', $emptyData['summary'] ?? [])
        && $emptyData['summary']['average'] === null,
    json_encode($emptyData['summary'] ?? null));
$assert('5.3 Sin encuestas el conteo es cero', ($emptyData['summary']['responses'] ?? null) === 0);
$assert('5.4 Sin encuestas la forma de la distribución se mantiene',
    count($emptyData['summary']['distribution'] ?? []) === 5);
$assert('5.5 Sin encuestas el umbral queda sin cumplir', ($emptyData['threshold_met'] ?? null) === false);

// =========================================================================
// CASO 6: Selector de periodo
// =========================================================================
echo "\n--- Caso 6: Periodo ---\n";

$augSep = $call('/api/technician/my-metrics/csat', $ownerToken, [
    'period' => 'custom',
    'from' => '2026-08-01',
    'to' => '2026-09-30',
]);
$augSepData = $augSep['body']['data'] ?? [];

$assert('6.1 El periodo acota el conteo a tres respuestas',
    ($augSepData['summary']['responses'] ?? null) === 3,
    var_export($augSepData['summary']['responses'] ?? null, true));
$assert('6.2 La media del periodo es la de sus respuestas',
    (float)($augSepData['summary']['average'] ?? -1) === 4.0,
    var_export($augSepData['summary']['average'] ?? null, true));
$assert('6.3 El umbral se cumple con tres respuestas', ($augSepData['threshold_met'] ?? null) === true);
$assert('6.4 Sin mes que llegue a tres, la tendencia viaja vacía',
    ($augSepData['monthly_trend'] ?? null) === [],
    json_encode($augSepData['monthly_trend'] ?? null));

$badPeriod = $call('/api/technician/my-metrics/csat', $ownerToken, ['period' => 'custom', 'from' => '2026-08-01']);
$assert('6.5 Un periodo personalizado incompleto => 400 INVALID_FILTER_PARAMS',
    $badPeriod['status'] === 400 && $errorCode($badPeriod) === 'INVALID_FILTER_PARAMS',
    'status: ' . $badPeriod['status'] . ' code: ' . $errorCode($badPeriod));

// =========================================================================
// CASO 7: Solo la superficie del técnico
// =========================================================================
echo "\n--- Caso 7: Superficie y mínimo privilegio ---\n";

$anonymous = $call('/api/technician/my-metrics/csat', null, $period);
$assert('7.1 Sin token => HTTP 401 UNAUTHORIZED',
    $anonymous['status'] === 401 && $errorCode($anonymous) === 'UNAUTHORIZED',
    'status: ' . $anonymous['status']);

$asCoordinator = $call('/api/technician/my-metrics/csat', $coordinatorToken, $period);
$assert('7.2 Token de coordinación => HTTP 403 (Art. V.4)',
    $asCoordinator['status'] === 403,
    'status: ' . $asCoordinator['status']);

$assert('7.3 La respuesta no expone nombres de técnicos ni códigos de sede (RF-20.1.6)',
    stripos($own['raw'], 'Ana Ruiz') === false
        && stripos($own['raw'], 'SEDE-BCN') === false,
    substr($own['raw'], 0, 200));
$assert('7.4 La respuesta no expone comentarios ni la fecha exacta de una valoración',
    stripos($own['raw'], 'comment') === false
        && stripos($own['raw'], '2026-10-05') === false);

// =========================================================================
// Resumen
// =========================================================================
echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
