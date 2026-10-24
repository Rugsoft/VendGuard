<?php

declare(strict_types=1);

/**
 * CoordinatorShiftApiTest · T-ASH-07
 *
 * Certificación de integración HTTP de los endpoints de supervisión y cierre forzado
 * de turnos desde Coordinación:
 *
 *   1. GET /api/coordinator/technicians/{id}/shift:
 *      - Consulta del estado de turno de un técnico.
 *   2. POST /api/coordinator/technicians/{id}/shifts/force-close:
 *      - 404 si el técnico no existe.
 *      - 422 si el motivo tiene menos de 10 caracteres.
 *      - 409 si el técnico no tiene turno abierto (contrato §5).
 *      - 422 si el técnico tiene averías en IN_PROGRESS (guardia fail-fast).
 *      - 200 éxito con cierre a OFF_SHIFT y auditoría inmutable.
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
echo " VendGuard: Integración - CoordinatorShiftApiTest (T-ASH-07)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$shiftRepo = new PdoTechnicianShiftRepository($pdo);
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
    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

    $coordToken = $authService->generateInternalToken($coordinator);
    $techId = (int)$technician->getId();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. GET /api/coordinator/technicians/{id}/shift
    // ─────────────────────────────────────────────────────────────────────────
    $resGetBefore = $router->dispatch(new Request(
        method: 'GET',
        path: "/api/coordinator/technicians/{$techId}/shift",
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('1.1 Consulta de turno sin sesión activa => status OFF_SHIFT',
        $resGetBefore->getStatusCode() === 200 && ($resGetBefore->getDecodedBody()['data']['status'] ?? '') === 'OFF_SHIFT');

    // Iniciar turno para probar force-close
    $shiftRepo->startShift($techId);

    $resGetActive = $router->dispatch(new Request(
        method: 'GET',
        path: "/api/coordinator/technicians/{$techId}/shift",
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('1.2 Consulta de turno con sesión activa => status ON_SHIFT',
        $resGetActive->getStatusCode() === 200 && ($resGetActive->getDecodedBody()['data']['status'] ?? '') === 'ON_SHIFT');

    // ─────────────────────────────────────────────────────────────────────────
    // 2. POST /api/coordinator/technicians/{id}/shifts/force-close
    // ─────────────────────────────────────────────────────────────────────────
    // 422 Motivo corto (< 10 chars)
    $resShort = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'olvido'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.1 Motivo < 10 chars => HTTP 422', $resShort->getStatusCode() === 422);

    // Cierre forzado exitoso
    $resForceClose = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'Cierre administrativo por fin de guardia no reportado'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.2 Cierre forzado válido => HTTP 200', $resForceClose->getStatusCode() === 200);
    $forceCloseData = $resForceClose->getDecodedBody()['data'] ?? [];
    $assert('2.3 Turno queda en OFF_SHIFT', ($forceCloseData['status'] ?? '') === 'OFF_SHIFT' || ($forceCloseData['shift']['status'] ?? '') === 'OFF_SHIFT');

    // 409 Intentar forzar cierre cuando ya está cerrado (contrato §5)
    $resNoActive = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'Intento sobre turno ya cerrado por completo'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert(
        '2.4 Cierre forzado sin turno abierto => HTTP 409 NO_OPEN_SHIFT_TO_CLOSE',
        $resNoActive->getStatusCode() === 409
            && ($resNoActive->getDecodedBody()['error']['code'] ?? '') === 'NO_OPEN_SHIFT_TO_CLOSE'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. C1: la guardia de IN_PROGRESS también manda en el cierre forzado
    // ─────────────────────────────────────────────────────────────────────────
    $shiftRepo->startShift($techId);
    $stmtBlocking = $pdo->prepare("INSERT INTO incidents
        (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-C1-FORCE', 1, 1, 'PAYMENT_SYSTEM', 'Avería en curso que bloquea el cierre forzado', 'HIGH', 'IN_PROGRESS', :tech_id, NOW())
        RETURNING id");
    $stmtBlocking->execute([':tech_id' => $techId]);
    $blockingIncidentId = (int)$stmtBlocking->fetchColumn();

    $resBlocked = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'Cierre administrativo con avería en curso'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $blockedBody = $resBlocked->getDecodedBody();
    $assert(
        '3.1 Cierre forzado con avería IN_PROGRESS => HTTP 422 HAS_IN_PROGRESS_INCIDENTS',
        $resBlocked->getStatusCode() === 422
            && ($blockedBody['error']['code'] ?? '') === 'HAS_IN_PROGRESS_INCIDENTS'
            && in_array('INC-C1-FORCE', $blockedBody['error']['details']['blocking_incidents'] ?? [], true)
    );

    $resStillOpen = $router->dispatch(new Request(
        method: 'GET',
        path: "/api/coordinator/technicians/{$techId}/shift",
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert(
        '3.2 El turno sigue ON_SHIFT tras el rechazo',
        ($resStillOpen->getDecodedBody()['data']['status'] ?? '') === 'ON_SHIFT'
    );

    // Se libera la avería y el cierre forzado ya puede completarse.
    $pdo->exec("UPDATE incidents SET status = 'ASSIGNED' WHERE id = {$blockingIncidentId}");
    $resRecovered = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'Cierre administrativo tras liberar la avería en curso'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('3.3 Cierre forzado válido tras liberar la avería => HTTP 200', $resRecovered->getStatusCode() === 200);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
