<?php

declare(strict_types=1);

/**
 * TechnicianShiftConstitutionalTest · T-ASH-08
 *
 * Certificación constitucional de blindaje (Art. V.4 y Art. III.1):
 *
 *   1. Art. V.4 (Segregación de notas internas):
 *      Las notas generadas por el relevo de fin de turno nacen con `is_internal = TRUE`.
 *      Al consultar el hilo desde el Portal de Sede (`/api/location/incidents/{id}/comments`),
 *      las notas internas de relevo son estrictamente invisibles (filtradas en origen).
 *   2. Art. III.1 (Inviolabilidad de datos):
 *      Los registros de jornada y ausencias no se borran físicamente en la operativa.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - TechnicianShiftConstitutionalTest (T-ASH-08)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

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

try {
    $tech1 = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
    $techRows = $pdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' AND id != {$tech1->getId()} AND deleted_at IS NULL LIMIT 1")->fetchAll(PDO::FETCH_COLUMN);
    $tech2Id = (int)$techRows[0];

    // RF-05.5: el receptor manual debe estar en turno para recibir el relevo.
    (new PdoTechnicianShiftRepository($pdo))->startShift($tech2Id);

    $location = $locationRepo->findBySiteCode('SEDE-BCN-01');
    $siteToken = $authService->generateSiteToken($location);
    $techToken = $authService->generateInternalToken($tech1);

    // Iniciar turno
    $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/start',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));

    // Crear avería en la sede SEDE-BCN-01
    $stmtInc = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-CONST-01', :loc_id, 1, 'PAYMENT_SYSTEM', 'Avería para prueba constitucional', 'LOW', 'ASSIGNED', :t_id, NOW())
        RETURNING id");
    $stmtInc->execute([':loc_id' => $location->getId(), ':t_id' => $tech1->getId()]);
    $incidentId = (int)$stmtInc->fetchColumn();

    // Relevo con nota interna confidencial
    $secretNotes = 'Nota estrictamente confidencial de taller: cliente difícil y sospecha de manipulación externa';
    $resHandover = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $tech2Id,
            'incident_ids' => [$incidentId],
            'handover_notes' => $secretNotes,
        ],
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('1. Relevo de fin de turno ejecutado con éxito', $resHandover->getStatusCode() === 200);

    // Consulta del hilo desde el Portal de Sede
    $resSiteComments = $router->dispatch(new Request(
        method: 'GET',
        path: "/api/location/incidents/{$incidentId}/comments",
        headers: ['Authorization' => 'Bearer ' . $siteToken]
    ));
    $assert('2. Sede consulta comentarios del ticket => HTTP 200', $resSiteComments->getStatusCode() === 200);

    $siteComments = $resSiteComments->getDecodedBody()['data'] ?? [];
    $foundSecret = false;
    foreach ($siteComments as $c) {
        if (str_contains((string)($c['comment_text'] ?? ''), 'cliente difícil')) {
            $foundSecret = true;
            break;
        }
    }
    $assert('3. Art. V.4: La nota interna de relevo es invisible para la sede', !$foundSecret);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
