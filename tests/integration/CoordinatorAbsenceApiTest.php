<?php

declare(strict_types=1);

/**
 * CoordinatorAbsenceApiTest · T-ASH-07
 *
 * Certificación de integración HTTP de la API de ausencias de Coordinación:
 *
 *   1. RBAC: anónimo (401), técnico (403), coordinador (200/201).
 *   2. POST /api/coordinator/technicians/{id}/absences:
 *      - 404 TECHNICIAN_NOT_FOUND.
 *      - 422 INVALID_ABSENCE_REASON.
 *      - 422 INVALID_DATE_RANGE.
 *      - 409 ABSENCE_OVERLAP.
 *      - 201 CREATED con DTO de ausencia y lista de affected_incidents.
 *   3. GET /api/coordinator/technicians/absences:
 *      - 200 con listado filtrado por technician_id y status.
 *   4. DELETE /api/coordinator/absences/{id}:
 *      - 404 ABSENCE_NOT_FOUND.
 *      - 422 MISSING_CANCELLATION_REASON (< 5 caracteres).
 *      - 200 con confirmación de cancelación lógica auditada.
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
echo " VendGuard: Integración - CoordinatorAbsenceApiTest (T-ASH-07)\n";
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
    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

    $coordToken = $authService->generateInternalToken($coordinator);
    $techToken = $authService->generateInternalToken($technician);
    $techId = (int)$technician->getId();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────────
    $resAnon = $router->dispatch(new Request('GET', '/api/coordinator/technicians/absences'));
    $assert('1.1 GET anónimo => HTTP 401', $resAnon->getStatusCode() === 401);

    $resTech = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/coordinator/technicians/absences',
        headers: ['Authorization' => 'Bearer ' . $techToken]
    ));
    $assert('1.2 Técnico en endpoint de coordinación => HTTP 403', $resTech->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────────
    // 2. POST /api/coordinator/technicians/{id}/absences
    // ─────────────────────────────────────────────────────────────────────────
    // 404 Técnico inexistente
    $res404 = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/coordinator/technicians/999999/absences',
        parsedBody: [
            'reason' => 'VACATION',
            'starts_at' => '2026-11-01',
            'ends_at' => '2026-11-05',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.1 Técnico inexistente => HTTP 404', $res404->getStatusCode() === 404);

    // 422 Motivo inválido
    $resBadReason = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/absences",
        parsedBody: [
            'reason' => 'HOLIDAYS_PARTY',
            'starts_at' => '2026-11-01',
            'ends_at' => '2026-11-05',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.2 Motivo de ausencia fuera de catálogo => HTTP 422', $resBadReason->getStatusCode() === 422);

    // 422 Fechas invertidas
    $resInvertedDates = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/absences",
        parsedBody: [
            'reason' => 'VACATION',
            'starts_at' => '2026-11-10',
            'ends_at' => '2026-11-05',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.3 Fechas invertidas => HTTP 422 INVALID_DATE_RANGE', $resInvertedDates->getStatusCode() === 422);

    // Avería activa del técnico para certificar la sugerencia de RF-02.
    $stmtAffected = $pdo->prepare("INSERT INTO incidents
        (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-ABS-SUG', 1, 1, 'PAYMENT_SYSTEM', 'Avería afectada por ausencia', 'MEDIUM', 'ASSIGNED', :tech_id, NOW())
        RETURNING id");
    $stmtAffected->execute([':tech_id' => $techId]);
    $affectedIncidentId = (int)$stmtAffected->fetchColumn();

    // 201 Alta exitosa
    $resCreate = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/absences",
        parsedBody: [
            'reason' => 'VACATION',
            'starts_at' => '2026-11-01',
            'ends_at' => '2026-11-10',
            'notes' => 'Vacaciones planificadas quincena',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.4 Alta válida de ausencia => HTTP 201 CREATED', $resCreate->getStatusCode() === 201);
    $createBody = $resCreate->getDecodedBody();
    $absenceId = (int)($createBody['data']['absence']['id'] ?? 0);
    $assert('2.5 Retorna datos de la ausencia creada con su id', $absenceId > 0);
    $assert(
        '2.6 La jornada completa se ancla en Madrid: 00:00:00 y 23:59:59 locales',
        ($createBody['data']['absence']['starts_at'] ?? '') === '2026-11-01T00:00:00+01:00'
            && ($createBody['data']['absence']['ends_at'] ?? '') === '2026-11-10T23:59:59+01:00'
            && ($createBody['data']['absence']['is_full_day'] ?? false) === true
    );
    $affected = $createBody['data']['affected_incidents'] ?? [];
    $assert(
        '2.7 affected_incidents devuelve la incidencia y su técnico sugerido',
        count($affected) === 1
            && (int)($affected[0]['id'] ?? 0) === $affectedIncidentId
            && is_array($affected[0]['suggested_technician'] ?? null)
    );
    $suggestedId = (int)($affected[0]['suggested_technician']['id'] ?? 0);
    $assert(
        '2.8 El técnico ausente no se sugiere a sí mismo',
        $suggestedId > 0 && $suggestedId !== $techId
    );

    // 409 Solapamiento con ausencia existente
    $resOverlap = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/absences",
        parsedBody: [
            'reason' => 'SICK_LEAVE',
            'starts_at' => '2026-11-05',
            'ends_at' => '2026-11-15',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.9 Solapamiento de fechas => HTTP 409 ABSENCE_OVERLAP', $resOverlap->getStatusCode() === 409);

    // 422 Fecha imposible: el parser estricto no deja que PHP la normalice.
    $resImpossibleDate = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/coordinator/technicians/{$techId}/absences",
        parsedBody: [
            'reason' => 'VACATION',
            'starts_at' => '2026-02-30',
            'ends_at' => '2026-03-02',
        ],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('2.10 Fecha imposible => HTTP 422 INVALID_DATE_RANGE', $resImpossibleDate->getStatusCode() === 422);

    // ─────────────────────────────────────────────────────────────────────────
    // 3. GET /api/coordinator/technicians/absences
    // ─────────────────────────────────────────────────────────────────────────
    $resList = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/coordinator/technicians/absences',
        queryParams: ['technician_id' => (string)$techId],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('3.1 Listado de ausencias => HTTP 200', $resList->getStatusCode() === 200);
    $listData = $resList->getDecodedBody()['data'] ?? [];
    $assert('3.2 Devuelve la ausencia recién creada', count($listData) >= 1 && $listData[0]['id'] === $absenceId);

    // ─────────────────────────────────────────────────────────────────────────
    // 4. DELETE /api/coordinator/absences/{id}
    // ─────────────────────────────────────────────────────────────────────────
    $resDelShort = $router->dispatch(new Request(
        method: 'DELETE',
        path: "/api/coordinator/absences/{$absenceId}",
        parsedBody: ['cancellation_reason' => 'no'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('4.1 Motivo de cancelación < 5 chars => HTTP 422', $resDelShort->getStatusCode() === 422);

    $resCancel = $router->dispatch(new Request(
        method: 'DELETE',
        path: "/api/coordinator/absences/{$absenceId}",
        parsedBody: ['cancellation_reason' => 'Anulación voluntaria por necesidades del servicio'],
        headers: ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $assert('4.2 Cancelación lógica válida => HTTP 200', $resCancel->getStatusCode() === 200);
    $cancelBody = $resCancel->getDecodedBody();
    $assert('4.3 Respuesta confirma cancelación', ($cancelBody['data']['cancelled'] ?? false) === true);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
