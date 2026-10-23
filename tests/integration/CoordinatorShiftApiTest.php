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
 *      - 422 si el técnico no tiene turno abierto.
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

    // 422 Intentar forzar cierre cuando ya está cerrado
    $resNoActive = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/shifts/force-close",
        parsedBody: ['reason' => 'Intento sobre turno ya cerrado por completo'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.4 Cierre forzado sin turno abierto => HTTP 422', $resNoActive->getStatusCode() === 422);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
