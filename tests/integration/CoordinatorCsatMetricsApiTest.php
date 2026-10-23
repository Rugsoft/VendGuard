<?php

declare(strict_types=1);

/**
 * VendGuard - CoordinatorCsatMetricsApiTest (T-CSAT-06)
 *
 * Test de integración HTTP contra el motor único real de la métrica de
 * satisfacción del Panel de Coordinación (Módulo 20 · RF-04.1–04.6 · R7, R8,
 * R10, R11 · Contratos §6).
 *
 * Valida la condición "Hecho cuando":
 * 1. La media se publica redondeada a dos decimales y la distribución trae
 *    siempre las cinco estrellas, incluidas las que no tienen casos.
 * 2. Sin respuestas la media es `null`, nunca `0` (RD-07).
 * 3. `by_site` y `by_technician` listan solo grupos con al menos una respuesta (R7).
 * 4. El nombre del técnico es el congelado en la encuesta más reciente del grupo:
 *    renombrar al usuario después no reescribe la métrica (R8), y el grupo sin
 *    técnico se llama «Sin técnico».
 * 5. La serie mensual agrupa por `resolution_at` en Europe/Madrid: una resolución
 *    del 30 de septiembre a las 22:30 UTC cuenta en octubre (contratos §6.3, §6.4).
 * 6. El filtro temporal y los de sede y técnico los resuelve `MetricFilter`; un
 *    periodo o unas fechas inválidas responden 400 `INVALID_FILTER_PARAMS`.
 * 7. La superficie es solo de coordinación: 401 sin token, 403 para técnico.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y el mismo `AppRouter` que atiende al
 * servidor web (sin frameworks ni dependencias externas).
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
echo " VendGuard: Test de Integración - CoordinatorCsatMetricsApiTest (T-CSAT-06)\n";
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

$technicians = $pdo->query(
    "SELECT id FROM users WHERE role = 'TECHNICIAN' ORDER BY id ASC LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);

$assert('0.3 La semilla aporta dos técnicos para las agrupaciones', count($technicians) === 2);

if (count($technicians) !== 2) {
    echo "ERROR CRÍTICO: la semilla no aporta técnicos.\n";
    exit(1);
}

$techOne = (int)$technicians[0];
$techTwo = (int)$technicians[1];

$locationAId = (int)$siteA->getId();
$locationBId = (int)$siteB->getId();

// ─── Fixture: expedientes cerrados con su encuesta ───────────────────────────
$insertIncident = $pdo->prepare(
    "INSERT INTO incidents
        (ticket_code, machine_id, location_id, category, description, urgency, status, resolved_at)
     VALUES
        (:ticket_code, :machine_id, :location_id, 'OTHER', 'Expediente del banco de métricas CSAT.', 'LOW', 'CLOSED', :resolved_at)
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
        ':ticket_code' => sprintf('CSAT-MET-%02d', $fixtureIndex),
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

// Sede A: cuatro respuestas (dos en agosto, dos en septiembre).
$seedSurvey($locationAId, (int)$machineA->getId(), $techOne, 'Ana Antigua', '2026-08-10 09:00:00+00', 5);
$seedSurvey($locationAId, (int)$machineA->getId(), $techOne, 'Ana Ruiz', '2026-08-20 09:00:00+00', 4);
$seedSurvey($locationAId, (int)$machineA->getId(), $techOne, 'Ana Ruiz', '2026-09-05 09:00:00+00', 3);
$seedSurvey($locationAId, (int)$machineA->getId(), null, null, '2026-09-15 09:00:00+00', 4);

// Sede B: dos respuestas; la segunda cruza el cambio de mes en Madrid.
$seedSurvey($locationBId, (int)$machineB->getId(), $techTwo, 'Jordi Vega', '2026-10-05 09:00:00+00', 5);
$seedSurvey($locationBId, (int)$machineB->getId(), null, null, '2026-09-30 22:30:00+00', 4);

$assert('0.4 Seis encuestas repartidas en tres meses del periodo', $fixtureIndex === 6);

$coordinatorToken = $authService->generateInternalToken(
    new User(1, 'Sara Coordinadora', 'coordinacion@vendguard.internal', 'hash', UserRole::COORDINATOR)
);
$technicianToken = $authService->generateInternalToken(
    new User(2, 'Jordi Técnico', 'jordi.ruta@vendguard.internal', 'hash', UserRole::TECHNICIAN)
);

$period = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-31'];

// =========================================================================
// CASO 1: Resumen, distribución y media redondeada
// =========================================================================
echo "\n--- Caso 1: Resumen y distribución de cinco estrellas ---\n";

$report = $call('/api/coordinator/metrics/csat', $coordinatorToken, $period);
$data = $report['body']['data'] ?? [];
$summary = $data['summary'] ?? [];
$distribution = $summary['distribution'] ?? [];

$assert('1.1 HTTP 200 con los cuatro bloques del contrato §6.2',
    $report['status'] === 200
        && array_keys($data) === ['summary', 'by_site', 'by_technician', 'monthly_trend'],
    'status: ' . $report['status'] . ' · ' . implode(',', array_keys($data)));

$assert('1.2 La media sale redondeada a dos decimales (25 / 6 = 4.17)',
    (float)($summary['average'] ?? -1) === 4.17,
    var_export($summary['average'] ?? null, true));

$assert('1.3 El total de respuestas del periodo son seis', ($summary['responses'] ?? null) === 6,
    var_export($summary['responses'] ?? null, true));

// PHP convierte a entero las claves numéricas al decodificar el JSON: lo que se
// comprueba es que el documento publicado las declara como cadenas, no el tipo
// que PHP les da en memoria.
$assert('1.4 La distribución trae siempre las cinco claves como cadenas',
    array_map('strval', array_keys($distribution)) === ['1', '2', '3', '4', '5']
        && str_contains($report['raw'], '"distribution":{"1":'),
    implode(',', array_keys($distribution)));

$assert('1.5 La distribución cuenta las notas reales y deja 0 donde no hay casos',
    $distribution === ['1' => 0, '2' => 0, '3' => 1, '4' => 3, '5' => 2],
    json_encode($distribution));

// =========================================================================
// CASO 2: Agrupación por sede (R7)
// =========================================================================
echo "\n--- Caso 2: Agrupación por sede ---\n";

$bySite = is_array($data['by_site'] ?? null) ? $data['by_site'] : [];

$assert('2.1 Solo aparecen las dos sedes con respuestas', count($bySite) === 2,
    (string)count($bySite));

$assert('2.2 El orden es respuestas DESC: primero la sede con cuatro',
    ($bySite[0]['location_id'] ?? null) === $locationAId
        && ($bySite[0]['responses'] ?? null) === 4
        && (float)($bySite[0]['average'] ?? -1) === 4.0,
    json_encode($bySite[0] ?? []));

$assert('2.3 La segunda sede lleva su media de 4.5 sobre dos respuestas',
    ($bySite[1]['location_id'] ?? null) === $locationBId
        && ($bySite[1]['responses'] ?? null) === 2
        && (float)($bySite[1]['average'] ?? -1) === 4.5,
    json_encode($bySite[1] ?? []));

$assert('2.4 Cada grupo publica el código y el nombre actual de la sede',
    ($bySite[0]['site_code'] ?? '') === 'SEDE-BCN-01'
        && ($bySite[0]['location_name'] ?? '') === $siteA->getName());

// =========================================================================
// CASO 3: Agrupación por técnico y nombre congelado (R7, R8)
// =========================================================================
echo "\n--- Caso 3: Agrupación por técnico y nombre congelado ---\n";

$byTechnician = is_array($data['by_technician'] ?? null) ? $data['by_technician'] : [];

$assert('3.1 Hay tres grupos: un técnico con tres, el grupo sin técnico con dos y otro técnico con una',
    count($byTechnician) === 3,
    (string)count($byTechnician));

$assert('3.2 El grupo con más respuestas va primero y conserva la media',
    ($byTechnician[0]['technician_id'] ?? null) === $techOne
        && ($byTechnician[0]['responses'] ?? null) === 3
        && (float)($byTechnician[0]['average'] ?? -1) === 4.0,
    json_encode($byTechnician[0] ?? []));

$assert('3.3 El nombre del técnico es el congelado en la encuesta más reciente del grupo',
    ($byTechnician[0]['technician_name'] ?? '') === 'Ana Ruiz',
    (string)($byTechnician[0]['technician_name'] ?? 'ausente'));

$assert('3.4 Las averías resueltas sin asignación se agrupan como «Sin técnico»',
    array_key_exists('technician_id', $byTechnician[1] ?? [])
        && $byTechnician[1]['technician_id'] === null
        && ($byTechnician[1]['technician_name'] ?? '') === 'Sin técnico'
        && ($byTechnician[1]['responses'] ?? null) === 2,
    json_encode($byTechnician[1] ?? []));

$assert('3.5 El segundo técnico cierra la tabla con su única respuesta',
    ($byTechnician[2]['technician_id'] ?? null) === $techTwo
        && (float)($byTechnician[2]['average'] ?? -1) === 5.0
        && ($byTechnician[2]['technician_name'] ?? '') === 'Jordi Vega',
    json_encode($byTechnician[2] ?? []));

// =========================================================================
// CASO 4: Serie mensual en Europe/Madrid (R11)
// =========================================================================
echo "\n--- Caso 4: Serie mensual en Europe/Madrid ---\n";

$trend = is_array($data['monthly_trend'] ?? null) ? $data['monthly_trend'] : [];
$months = array_map(static fn(array $row): string => (string)($row['month'] ?? ''), $trend);

$assert('4.1 La serie es ascendente y cubre los tres meses del periodo',
    $months === ['2026-08', '2026-09', '2026-10'],
    implode(',', $months));

$assert('4.2 Agosto y septiembre agregan dos respuestas cada uno',
    ($trend[0]['responses'] ?? null) === 2
        && (float)($trend[0]['average'] ?? -1) === 4.5
        && ($trend[1]['responses'] ?? null) === 2
        && (float)($trend[1]['average'] ?? -1) === 3.5,
    json_encode($trend));

$assert('4.3 Una resolución del 30 de septiembre a las 22:30 UTC cuenta en octubre (Europe/Madrid)',
    ($trend[2]['month'] ?? '') === '2026-10'
        && ($trend[2]['responses'] ?? null) === 2
        && (float)($trend[2]['average'] ?? -1) === 4.5,
    json_encode($trend[2] ?? []));

// =========================================================================
// CASO 5: Filtros de sede y técnico
// =========================================================================
echo "\n--- Caso 5: Filtros de sede y técnico ---\n";

$onlyA = $call('/api/coordinator/metrics/csat', $coordinatorToken, $period + ['location_id' => $locationAId]);
$onlyAData = $onlyA['body']['data'] ?? [];

$assert('5.1 El filtro de sede acota el resumen a sus cuatro respuestas',
    $onlyA['status'] === 200
        && ($onlyAData['summary']['responses'] ?? null) === 4
        && count($onlyAData['by_site'] ?? []) === 1,
    json_encode($onlyAData['summary'] ?? []));

$onlyTech = $call('/api/coordinator/metrics/csat', $coordinatorToken, $period + ['technician_id' => $techOne]);
$onlyTechData = $onlyTech['body']['data'] ?? [];

$assert('5.2 El filtro de técnico acota la métrica a sus tres valoraciones',
    $onlyTech['status'] === 200
        && ($onlyTechData['summary']['responses'] ?? null) === 3
        && count($onlyTechData['by_technician'] ?? []) === 1,
    json_encode($onlyTechData['summary'] ?? []));

// =========================================================================
// CASO 6: Sin respuestas no se imputa un cero (RD-07)
// =========================================================================
echo "\n--- Caso 6: Periodo vacío ---\n";

$empty = $call('/api/coordinator/metrics/csat', $coordinatorToken, [
    'period' => 'custom',
    'from' => '2025-01-01',
    'to' => '2025-01-31',
]);
$emptyData = $empty['body']['data'] ?? [];

$emptySummary = is_array($emptyData['summary'] ?? null) ? $emptyData['summary'] : [];

$assert('6.1 Sin valoraciones la media es null y hay cero respuestas',
    $empty['status'] === 200
        && array_key_exists('average', $emptySummary)
        && $emptySummary['average'] === null
        && ($emptySummary['responses'] ?? null) === 0,
    json_encode($emptySummary));

$assert('6.2 La distribución mantiene las cinco claves a cero',
    ($emptyData['summary']['distribution'] ?? []) === ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0],
    json_encode($emptyData['summary']['distribution'] ?? []));

$assert('6.3 Los tres desgloses quedan vacíos en vez de inventar grupos',
    ($emptyData['by_site'] ?? ['x']) === []
        && ($emptyData['by_technician'] ?? ['x']) === []
        && ($emptyData['monthly_trend'] ?? ['x']) === []);

// =========================================================================
// CASO 7: Contrato temporal y validación de filtros
// =========================================================================
echo "\n--- Caso 7: Periodos y validación ---\n";

$defaultPeriod = $call('/api/coordinator/metrics/csat', $coordinatorToken);
$assert('7.1 Sin parámetros se aplica el periodo por defecto y responde 200',
    $defaultPeriod['status'] === 200
        && isset($defaultPeriod['body']['data']['summary']['distribution']),
    'status: ' . $defaultPeriod['status']);

$badPeriod = $call('/api/coordinator/metrics/csat', $coordinatorToken, ['period' => 'nonsense']);
$assert('7.2 Periodo desconocido => HTTP 400 INVALID_FILTER_PARAMS',
    $badPeriod['status'] === 400 && $errorCode($badPeriod) === 'INVALID_FILTER_PARAMS',
    'status: ' . $badPeriod['status']);

$missingDates = $call('/api/coordinator/metrics/csat', $coordinatorToken, ['period' => 'custom']);
$assert('7.3 Periodo personalizado sin fechas => HTTP 400 INVALID_FILTER_PARAMS',
    $missingDates['status'] === 400 && $errorCode($missingDates) === 'INVALID_FILTER_PARAMS',
    'status: ' . $missingDates['status']);

$badFormat = $call('/api/coordinator/metrics/csat', $coordinatorToken, [
    'period' => 'custom',
    'from' => '01/08/2026',
    'to' => '31/10/2026',
]);
$assert('7.4 Fecha con formato inválido => HTTP 400 sin llegar a la consulta',
    $badFormat['status'] === 400 && $errorCode($badFormat) === 'INVALID_FILTER_PARAMS',
    'status: ' . $badFormat['status']);

// =========================================================================
// CASO 8: Superficie solo de coordinación
// =========================================================================
echo "\n--- Caso 8: Superficie de coordinación ---\n";

$anonymous = $call('/api/coordinator/metrics/csat', null, $period);
$assert('8.1 Sin token => HTTP 401 UNAUTHORIZED', $anonymous['status'] === 401,
    'status: ' . $anonymous['status']);

$asTechnician = $call('/api/coordinator/metrics/csat', $technicianToken, $period);
$assert('8.2 Token de técnico => HTTP 403 (Art. V.4)',
    $asTechnician['status'] === 403,
    'status: ' . $asTechnician['status']);

$assert('8.3 La respuesta no expone datos de la sede ni del técnico fuera de la métrica',
    stripos($report['raw'], 'password') === false
        && stripos($report['raw'], 'email') === false);

// =========================================================================
// CASO 9: Renombrado posterior e inactividad del técnico (R8, RF-04.6)
// =========================================================================
echo "\n--- Caso 9: Renombrado e inactividad ---\n";

$userState = $pdo->prepare('SELECT name, is_active, is_on_call FROM users WHERE id = :id');
$userState->execute([':id' => $techOne]);
$techOneBefore = $userState->fetch(PDO::FETCH_ASSOC) ?: [];

$userState->execute([':id' => $techTwo]);
$techTwoBefore = $userState->fetch(PDO::FETCH_ASSOC) ?: [];

try {
    $pdo->prepare("UPDATE users SET name = 'Nombre Renombrado' WHERE id = :id")->execute([':id' => $techOne]);

    $afterRename = $call('/api/coordinator/metrics/csat', $coordinatorToken, $period);
    $renamedGroup = array_values(array_filter(
        $afterRename['body']['data']['by_technician'] ?? [],
        static fn(array $group): bool => ($group['technician_id'] ?? null) === $techOne
    ))[0] ?? [];

    $assert('9.1 Renombrar al usuario no reescribe la métrica: manda el nombre congelado',
        ($renamedGroup['technician_name'] ?? '') === 'Ana Ruiz',
        (string)($renamedGroup['technician_name'] ?? 'ausente'));

    $pdo->prepare('UPDATE users SET is_active = FALSE, is_on_call = FALSE WHERE id = :id')
        ->execute([':id' => $techTwo]);

    $afterDeactivation = $call('/api/coordinator/metrics/csat', $coordinatorToken, $period);
    $deactivatedGroup = array_values(array_filter(
        $afterDeactivation['body']['data']['by_technician'] ?? [],
        static fn(array $group): bool => ($group['technician_id'] ?? null) === $techTwo
    ))[0] ?? null;

    $assert('9.2 Un técnico dado de baja sigue en la métrica del periodo (RF-04.6)',
        $deactivatedGroup !== null && ($deactivatedGroup['responses'] ?? null) === 1,
        json_encode($deactivatedGroup));
} finally {
    $restore = $pdo->prepare('UPDATE users SET name = :name, is_active = :is_active, is_on_call = :is_on_call WHERE id = :id');

    $restore->bindValue(':name', (string)($techOneBefore['name'] ?? ''), PDO::PARAM_STR);
    $restore->bindValue(':is_active', (bool)($techOneBefore['is_active'] ?? true), PDO::PARAM_BOOL);
    $restore->bindValue(':is_on_call', (bool)($techOneBefore['is_on_call'] ?? false), PDO::PARAM_BOOL);
    $restore->bindValue(':id', $techOne, PDO::PARAM_INT);
    $restore->execute();

    $restore->bindValue(':name', (string)($techTwoBefore['name'] ?? ''), PDO::PARAM_STR);
    $restore->bindValue(':is_active', (bool)($techTwoBefore['is_active'] ?? true), PDO::PARAM_BOOL);
    $restore->bindValue(':is_on_call', (bool)($techTwoBefore['is_on_call'] ?? false), PDO::PARAM_BOOL);
    $restore->bindValue(':id', $techTwo, PDO::PARAM_INT);
    $restore->execute();
}

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
    echo " RESULT: 100% IN GREEN. COORDINATOR CSAT METRICS (T-CSAT-06) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
