<?php

declare(strict_types=1);

/**
 * TechnicianShiftApiTest · T-ASH-08
 *
 * Certificación de integración HTTP de la API móvil de jornada del técnico:
 *
 *   1. GET /api/technician/shift/status: estado actual (ON_SHIFT, MEAL_BREAK, OFF_SHIFT).
 *   2. POST /api/technician/shift/start: inicio de jornada (idempotente).
 *   3. POST /api/technician/shift/pause-meal: pausa de comida.
 *   4. POST /api/technician/shift/resume: reanudación de jornada.
 *   5. POST /api/technician/shift/close-handover: cierre y relevo con validación fail-fast.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - TechnicianShiftApiTest (T-ASH-08)\n";
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

    $techToken = $authService->generateInternalToken($tech1);

    // ─────────────────────────────────────────────────────────────────────────
    // 1. GET /api/technician/shift/status
    // ─────────────────────────────────────────────────────────────────────────
    $resStatusInit = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/technician/shift/status',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('1.1 Estado inicial sin turno => OFF_SHIFT',
        $resStatusInit->getStatusCode() === 200 && ($resStatusInit->getDecodedBody()['data']['status'] ?? '') === 'OFF_SHIFT');

    // ─────────────────────────────────────────────────────────────────────────
    // 2. POST /api/technician/shift/start
    // ─────────────────────────────────────────────────────────────────────────
    $resStart = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/start',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('2.1 Inicio de turno => HTTP 200 ON_SHIFT',
        $resStart->getStatusCode() === 200 && ($resStart->getDecodedBody()['data']['status'] ?? '') === 'ON_SHIFT');

    // Idempotente
    $resStartIdemp = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/start',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('2.2 Inicio de turno idempotente => HTTP 200 ON_SHIFT',
        $resStartIdemp->getStatusCode() === 200 && ($resStartIdemp->getDecodedBody()['data']['status'] ?? '') === 'ON_SHIFT');

    // ─────────────────────────────────────────────────────────────────────────
    // 3. POST /api/technician/shift/pause-meal & resume
    // ─────────────────────────────────────────────────────────────────────────
    $resPause = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/pause-meal',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('3.1 Pausa de comida => HTTP 200 MEAL_BREAK',
        $resPause->getStatusCode() === 200 && ($resPause->getDecodedBody()['data']['status'] ?? '') === 'MEAL_BREAK');

    $resResume = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/resume',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('3.2 Reanudación tras comida => HTTP 200 ON_SHIFT',
        $resResume->getStatusCode() === 200 && ($resResume->getDecodedBody()['data']['status'] ?? '') === 'ON_SHIFT');

    // ─────────────────────────────────────────────────────────────────────────
    // 4. POST /api/technician/shift/close-handover
    // ─────────────────────────────────────────────────────────────────────────
    // Insertar un ticket ASSIGNED
    $stmtInc = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-TSH-01', 1, 1, 'PAYMENT_SYSTEM', 'Prueba relevo móvil', 'LOW', 'ASSIGNED', :t_id, NOW())
        RETURNING id");
    $stmtInc->execute([':t_id' => $tech1->getId()]);
    $incId = (int)$stmtInc->fetchColumn();

    // Rechazo de notas cortas si hay traspaso
    $resShortNotes = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $tech2Id,
            'incident_ids' => [$incId],
            'handover_notes' => 'corta',
        ],
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('4.1 Notas < 10 chars => HTTP 422', $resShortNotes->getStatusCode() === 422);

    // Cierre y traspaso exitoso
    $resHandover = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $tech2Id,
            'incident_ids' => [$incId],
            'handover_notes' => 'Te dejo el cambio de monedero para primera hora',
            'close_reason' => 'Fin de jornada con relevo',
        ],
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('4.2 Cierre y relevo exitoso => HTTP 200', $resHandover->getStatusCode() === 200);
    $handoverData = $resHandover->getDecodedBody()['data'] ?? [];
    $assert('4.3 Turno queda cerrado a OFF_SHIFT', ($handoverData['shift']['status'] ?? '') === 'OFF_SHIFT');
    $assert('4.4 Avería traspasada a tech2Id', count($handoverData['transferred_incidents'] ?? []) === 1);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
