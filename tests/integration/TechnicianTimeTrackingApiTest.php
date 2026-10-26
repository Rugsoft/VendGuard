<?php

declare(strict_types=1);

/**
 * TechnicianTimeTrackingApiTest · T-TIME-10
 *
 * Certificación HTTP y constitucional del registro de jornada y horas efectivas
 * (Módulo 22). La suite recorre las cinco superficies del contrato técnico
 * (`specs/technical/technician_time_tracking_contracts.md`, §5) contra el
 * router real y sobre PostgreSQL:
 *
 *   1. Ciclo de múltiples pausas y cierre directo desde `MEAL_BREAK`, con su
 *      reflejo en `today_summary` y en `technician_shift_logs`.
 *   2. Cierre forzado con `effective_closed_at`: motivo de 20 caracteres reales,
 *      hora efectiva no anterior a la última actividad, sello sanitario con
 *      `NOW()` real (Art. II) y doble marca `is_force_closed` + `is_adjusted`.
 *   3. Rectificación `PATCH /api/coordinator/shifts/{id}/adjust`: turno abierto,
 *      motivo corto, solapamiento, cero segundos efectivos, reimputación de
 *      `shift_date` e inmutabilidad de `original_*` tras dos rectificaciones.
 *   4. Informe `GET /api/coordinator/metrics/work-hours` y su CSV: plantilla
 *      activa sin sesiones, inactivos con actividad, preventivos con avería
 *      vinculada excluidos, turnos abiertos huérfanos y formato oficial.
 *   5. Aislamiento Art. V.4 en `GET /api/technician/shift/my-hours`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo y cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, títulos y mensajes en castellano.
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
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - TechnicianTimeTrackingApiTest (T-TIME-10)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);
$shiftRepo = new PdoTechnicianShiftRepository($pdo);

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        return;
    }

    echo "  [FAIL] {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

$api = function (
    string $method,
    string $path,
    ?string $token = null,
    ?array $body = null,
    array $query = []
) use ($router): Response {
    $headers = [];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    return $router->dispatch(new Request(
        method: $method,
        path: $path,
        queryParams: $query,
        parsedBody: $body ?? [],
        headers: $headers
    ));
};

$errorCode = static fn (Response $response): string => (string)($response->getDecodedBody()['error']['code'] ?? '');

$timezone = new DateTimeZone('Europe/Madrid');
$now = new DateTimeImmutable('now');
$now = $now->setTimezone($timezone);

try {
    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
    $marta = $userRepo->findByEmail('marta.ruta@vendguard.internal');
    $carlos = $userRepo->findByEmail('carlos.ruta@vendguard.internal');
    $elena = $userRepo->findByEmail('elena.ruta@vendguard.internal');
    $marc = $userRepo->findByEmail('marc.ruta@vendguard.internal');

    if ($coordinator === null || $jordi === null || $marta === null || $carlos === null || $elena === null || $marc === null) {
        fwrite(STDERR, "No se pudo resolver la plantilla sembrada para la batería del Módulo 22.\n");
        exit(1);
    }

    $coordinatorId = (int)$coordinator->getId();
    $jordiId = (int)$jordi->getId();
    $martaId = (int)$marta->getId();
    $carlosId = (int)$carlos->getId();
    $elenaId = (int)$elena->getId();
    $marcId = (int)$marc->getId();

    $coordToken = $authService->generateInternalToken($coordinator);
    $jordiToken = $authService->generateInternalToken($jordi);

    $machineRow = $pdo->query("SELECT id, location_id FROM machines WHERE code = 'VEND-0101' LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    if (!is_array($machineRow)) {
        fwrite(STDERR, "No se encontró la máquina perecedera sembrada VEND-0101.\n");
        exit(1);
    }
    $perishableMachineId = (int)$machineRow['id'];
    $perishableLocationId = (int)$machineRow['location_id'];

    // ─────────────────────────────────────────────────────────────────────
    // 1. Ciclo de pausas múltiples y cierre directo desde MEAL_BREAK
    // ─────────────────────────────────────────────────────────────────────
    echo "--- 1. Ciclo de pausas múltiples y cierre directo desde MEAL_BREAK ---\n";

    $start = $api('POST', '/api/technician/shift/start', $jordiToken);
    $startData = $start->getDecodedBody()['data'] ?? [];
    $sessionId = (int)($startData['id'] ?? 0);
    $todaySummary = $startData['today_summary'] ?? [];
    $assert(
        '1.1 Inicio de turno => 200 ON_SHIFT con today_summary de una sesión abierta',
        $start->getStatusCode() === 200
            && ($startData['status'] ?? '') === 'ON_SHIFT'
            && (int)($todaySummary['sessions_count'] ?? -1) === 1
            && ($todaySummary['date'] ?? '') === $now->format('Y-m-d'),
        'status=' . $start->getStatusCode() . ' body=' . $start->getBody()
    );

    $firstPause = $api('POST', '/api/technician/shift/pause-meal', $jordiToken);
    $assert(
        '1.2 Primera pausa => 200 MEAL_BREAK reflejada en today_summary',
        $firstPause->getStatusCode() === 200
            && ($firstPause->getDecodedBody()['data']['status'] ?? '') === 'MEAL_BREAK'
            && (int)($firstPause->getDecodedBody()['data']['today_summary']['sessions_count'] ?? -1) === 1
    );

    sleep(1);
    $firstResume = $api('POST', '/api/technician/shift/resume', $jordiToken);
    $firstResumeData = $firstResume->getDecodedBody()['data'] ?? [];
    $assert(
        '1.3 Reanudación => 200 ON_SHIFT con la pausa acumulada',
        $firstResume->getStatusCode() === 200
            && ($firstResumeData['status'] ?? '') === 'ON_SHIFT'
            && (int)($firstResumeData['meal_break_seconds'] ?? 0) >= 1,
        'body=' . $firstResume->getBody()
    );

    $secondPause = $api('POST', '/api/technician/shift/pause-meal', $jordiToken);
    $assert(
        '1.4 Segunda pausa => 200 MEAL_BREAK',
        $secondPause->getStatusCode() === 200
            && ($secondPause->getDecodedBody()['data']['status'] ?? '') === 'MEAL_BREAK'
    );

    sleep(1);
    $statusInMeal = $api('GET', '/api/technician/shift/status', $jordiToken);
    $statusInMealData = $statusInMeal->getDecodedBody()['data'] ?? [];
    $mealSummary = $statusInMealData['today_summary'] ?? [];
    $assert(
        '1.5 Estado en pausa viva => MEAL_BREAK con la pausa descontada del día',
        $statusInMeal->getStatusCode() === 200
            && ($statusInMealData['status'] ?? '') === 'MEAL_BREAK'
            && (int)($mealSummary['sessions_count'] ?? -1) === 1
            && (int)($mealSummary['meal_break_seconds'] ?? 0) >= 1
            && (int)($mealSummary['effective_seconds'] ?? 0) === 0,
        'body=' . $statusInMeal->getBody()
    );

    $closeFromMeal = $api('POST', '/api/technician/shift/close-handover', $jordiToken);
    $closeData = $closeFromMeal->getDecodedBody()['data'] ?? [];
    $closeShiftDto = $closeData['shift'] ?? [];
    $assert(
        '1.6 Cierre directo desde MEAL_BREAK => 200 OFF_SHIFT',
        $closeFromMeal->getStatusCode() === 200
            && ($closeShiftDto['status'] ?? '') === 'OFF_SHIFT'
            && ($closeShiftDto['is_open'] ?? true) === false,
        'body=' . $closeFromMeal->getBody()
    );

    $statusAfterClose = $api('GET', '/api/technician/shift/status', $jordiToken);
    $closedSummary = $statusAfterClose->getDecodedBody()['data']['today_summary'] ?? [];
    $grossClosed = (int)($closedSummary['gross_seconds'] ?? -1);
    $mealClosed = (int)($closedSummary['meal_break_seconds'] ?? -1);
    $effectiveClosed = (int)($closedSummary['effective_seconds'] ?? -1);
    $assert(
        '1.7 today_summary consolida sesión, pausas y neto tras el cierre',
        $statusAfterClose->getStatusCode() === 200
            && (int)($closedSummary['sessions_count'] ?? 0) === 1
            && $grossClosed >= 1
            && $mealClosed >= 2
            && $effectiveClosed === max(0, $grossClosed - $mealClosed),
        sprintf('gross=%d meal=%d effective=%d', $grossClosed, $mealClosed, $effectiveClosed)
    );

    $logs = $api('GET', "/api/coordinator/shifts/{$sessionId}/logs", $coordToken);
    $logEntries = $logs->getDecodedBody()['data']['logs'] ?? [];
    $eventTypes = array_map(static fn (array $entry): string => (string)($entry['event_type'] ?? ''), $logEntries);
    $pauseCount = count(array_filter($eventTypes, static fn (string $type): bool => $type === 'PAUSE_MEAL'));
    $resumeCount = count(array_filter($eventTypes, static fn (string $type): bool => $type === 'RESUME_SHIFT'));
    $assert(
        '1.8 Bitácora append-only con START, dos PAUSE, RESUME y CLOSE',
        $logs->getStatusCode() === 200
            && in_array('START_SHIFT', $eventTypes, true)
            && $pauseCount === 2
            && $resumeCount === 1
            && in_array('CLOSE_SHIFT', $eventTypes, true)
            && end($eventTypes) === 'CLOSE_SHIFT',
        'eventos=' . implode(',', $eventTypes)
    );
    $logsAreAttributed = $logEntries !== [];
    foreach ($logEntries as $entry) {
        if ((int)($entry['actor_user_id'] ?? 0) <= 0 || (string)($entry['created_at'] ?? '') === '') {
            $logsAreAttributed = false;
        }
    }
    $assert('1.9 Cada evento de bitácora queda firmado por un actor y fechado', $logsAreAttributed);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cierre forzado con effective_closed_at y protocolo sanitario (Art. II)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Cierre forzado con hora efectiva y protocolo sanitario ---\n";

    $forceStart = $now->modify('-4 hours');
    $forcedShift = $shiftRepo->startShift($jordiId, $forceStart);
    $forcedShiftId = (int)$forcedShift->id();
    $shiftRepo->pauseMeal($forcedShiftId, $now->modify('-1 hour'));
    $shiftRepo->resumeShift($forcedShiftId, $now->modify('-30 minutes'));

    $beforeLastActivity = $api(
        'POST',
        "/api/coordinator/technicians/{$jordiId}/shifts/force-close",
        $coordToken,
        [
            'reason' => 'Cierre administrativo con hora efectiva anterior a la última pausa registrada.',
            'effective_closed_at' => $now->modify('-2 hours')->format(DATE_ATOM),
        ]
    );
    $assert(
        '2.1 Hora efectiva anterior a la última actividad => 422 EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY',
        $beforeLastActivity->getStatusCode() === 422
            && $errorCode($beforeLastActivity) === 'EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY',
        'body=' . $beforeLastActivity->getBody()
    );

    $shortReason = $api(
        'POST',
        "/api/coordinator/technicians/{$jordiId}/shifts/force-close",
        $coordToken,
        [
            'reason' => 'motivo corto',
            'effective_closed_at' => $now->modify('-10 minutes')->format(DATE_ATOM),
        ]
    );
    $assert(
        '2.2 Cierre con hora efectiva y motivo < 20 caracteres => 422 MISSING_FORCE_CLOSE_REASON',
        $shortReason->getStatusCode() === 422
            && $errorCode($shortReason) === 'MISSING_FORCE_CLOSE_REASON',
        'body=' . $shortReason->getBody()
    );

    // Guardia 24/7 disponible para el traspaso sanitario del Art. II.
    $pdo->prepare('UPDATE users SET is_on_call = TRUE WHERE id = :id')->execute([':id' => $elenaId]);

    $stmtIncident = $pdo->prepare("INSERT INTO incidents
        (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-TTIME-10-SAN', :location_id, :machine_id, 'TEMPERATURE_COLD',
                'Fallo de frío detectado antes del cierre administrativo del turno.', 'CRITICAL',
                'ASSIGNED', :technician_id, NOW())
        RETURNING id");
    $stmtIncident->execute([
        ':location_id' => $perishableLocationId,
        ':machine_id' => $perishableMachineId,
        ':technician_id' => $jordiId,
    ]);
    $perishableIncidentId = (int)$stmtIncident->fetchColumn();

    $effectiveClose = $now->modify('-10 minutes');
    $forceClose = $api(
        'POST',
        "/api/coordinator/technicians/{$jordiId}/shifts/force-close",
        $coordToken,
        [
            'reason' => 'Olvido de cierre de turno tras finalizar la ruta, confirmado con el técnico.',
            'effective_closed_at' => $effectiveClose->format(DATE_ATOM),
        ]
    );
    $forceCloseData = $forceClose->getDecodedBody()['data'] ?? [];
    $forcedShiftDto = $forceCloseData['shift'] ?? [];
    $assert(
        '2.3 Cierre forzado con hora efectiva válida => 200 y turno OFF_SHIFT',
        $forceClose->getStatusCode() === 200
            && ($forceCloseData['status'] ?? '') === 'OFF_SHIFT',
        'body=' . $forceClose->getBody()
    );
    $assert('2.4 El turno queda marcado is_force_closed', ($forcedShiftDto['is_force_closed'] ?? false) === true);
    $assert(
        '2.5 El turno queda marcado is_adjusted por la hora efectiva retroactiva',
        ($forcedShiftDto['is_adjusted'] ?? false) === true
            && (int)($forcedShiftDto['adjusted_by'] ?? 0) === $coordinatorId,
        'body=' . $forceClose->getBody()
    );
    $assert(
        '2.6 closed_at es la hora efectiva corregida, no el instante de ejecución',
        isset($forcedShiftDto['closed_at'])
            && strtotime((string)$forcedShiftDto['closed_at']) === $effectiveClose->getTimestamp(),
        'closed_at=' . (string)($forcedShiftDto['closed_at'] ?? '')
    );
    $assert(
        '2.7 original_closed_at sella el instante real de ejecución (Art. III.3)',
        isset($forcedShiftDto['original_closed_at'])
            && strtotime((string)$forcedShiftDto['original_closed_at']) >= $effectiveClose->getTimestamp() + 60,
        'original_closed_at=' . (string)($forcedShiftDto['original_closed_at'] ?? '')
    );

    $sanitaryTransfers = $forceCloseData['sanitary_emergency_transfers'] ?? [];
    $assert(
        '2.8 Art. II: la avería perecedera se traspasa al técnico de guardia',
        count($sanitaryTransfers) === 1
            && (int)($sanitaryTransfers[0]['on_call_technician_id'] ?? 0) === $elenaId
            && (string)($sanitaryTransfers[0]['ticket_code'] ?? '') === 'INC-TTIME-10-SAN',
        'traspasos=' . json_encode($sanitaryTransfers, JSON_UNESCAPED_UNICODE)
    );

    $stmtAssigned = $pdo->prepare('SELECT assigned_technician_id, assigned_at FROM incidents WHERE id = :id');
    $stmtAssigned->execute([':id' => $perishableIncidentId]);
    $assignedRow = $stmtAssigned->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '2.9 Art. II: el traspaso se fecha con NOW() real, no con la hora efectiva pasada',
        (int)($assignedRow['assigned_technician_id'] ?? 0) === $elenaId
            && isset($assignedRow['assigned_at'])
            && strtotime((string)$assignedRow['assigned_at']) >= $effectiveClose->getTimestamp() + 60,
        'assigned_at=' . (string)($assignedRow['assigned_at'] ?? '')
    );

    $stmtSanitaryAudit = $pdo->prepare("SELECT created_at FROM audit_log
        WHERE action = 'SANITARY_SHIFT_CLOSE_FAILSAFE' AND entity_id = :incident_id
        ORDER BY id DESC LIMIT 1");
    $stmtSanitaryAudit->execute([':incident_id' => $perishableIncidentId]);
    $sanitaryAuditAt = $stmtSanitaryAudit->fetchColumn();
    $assert(
        '2.10 Art. II: el rastro sanitario en auditoría se fecha en el presente',
        $sanitaryAuditAt !== false
            && strtotime((string)$sanitaryAuditAt) >= $effectiveClose->getTimestamp() + 60,
        'created_at=' . (string)($sanitaryAuditAt ?: '')
    );

    $forcedLogs = $api('GET', "/api/coordinator/shifts/{$forcedShiftId}/logs", $coordToken);
    $forcedLogEntries = $forcedLogs->getDecodedBody()['data']['logs'] ?? [];
    $forceLog = null;
    foreach ($forcedLogEntries as $entry) {
        if (($entry['event_type'] ?? '') === 'FORCE_CLOSE_SHIFT') {
            $forceLog = $entry;
        }
    }
    $assert(
        '2.11 La bitácora registra FORCE_CLOSE_SHIFT con el motivo del coordinador',
        $forcedLogs->getStatusCode() === 200
            && $forceLog !== null
            && (int)($forceLog['actor_user_id'] ?? 0) === $coordinatorId
            && ($forceLog['reason'] ?? '') !== '',
        'body=' . $forcedLogs->getBody()
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Rectificación auditada de turnos cerrados (PATCH .../adjust)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Rectificación auditada de turnos cerrados ---\n";

    $openShift = $shiftRepo->startShift($martaId, $now->modify('-45 minutes'));
    $openShiftId = (int)$openShift->id();
    $openAdjust = $api(
        'PATCH',
        "/api/coordinator/shifts/{$openShiftId}/adjust",
        $coordToken,
        [
            'started_at' => $now->modify('-45 minutes')->format(DATE_ATOM),
            'closed_at' => $now->modify('-15 minutes')->format(DATE_ATOM),
            'meal_break_seconds' => 0,
            'reason' => 'Intento de rectificar un turno que todavía sigue abierto en ruta.',
        ]
    );
    $assert(
        '3.1 Rectificar un turno abierto => 422 SHIFT_STILL_OPEN',
        $openAdjust->getStatusCode() === 422 && $errorCode($openAdjust) === 'SHIFT_STILL_OPEN',
        'body=' . $openAdjust->getBody()
    );
    $shiftRepo->closeShift($openShiftId, $martaId, 'Cierre de limpieza de la suite', $now->modify('-15 minutes'));

    $createClosedShift = static function (string $startedAt, string $closedAt) use ($shiftRepo, $martaId): array {
        $shift = $shiftRepo->startShift($martaId, new DateTimeImmutable($startedAt));
        $closed = $shiftRepo->closeShift(
            (int)$shift->id(),
            $martaId,
            'Cierre de prueba de la suite',
            new DateTimeImmutable($closedAt)
        );

        return [(int)$closed->id(), $closed];
    };

    [$morningShiftId] = $createClosedShift('2026-10-22T08:00:00+02:00', '2026-10-22T12:00:00+02:00');
    [$afternoonShiftId] = $createClosedShift('2026-10-22T14:00:00+02:00', '2026-10-22T18:00:00+02:00');

    $shortAdjustReason = $api(
        'PATCH',
        "/api/coordinator/shifts/{$afternoonShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-22T14:00:00+02:00',
            'closed_at' => '2026-10-22T18:00:00+02:00',
            'meal_break_seconds' => 0,
            'reason' => 'corta',
        ]
    );
    $assert(
        '3.2 Motivo de rectificación < 20 caracteres => 422 INVALID_ADJUSTMENT_REASON',
        $shortAdjustReason->getStatusCode() === 422
            && $errorCode($shortAdjustReason) === 'INVALID_ADJUSTMENT_REASON',
        'body=' . $shortAdjustReason->getBody()
    );

    $overlapAdjust = $api(
        'PATCH',
        "/api/coordinator/shifts/{$afternoonShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-22T10:00:00+02:00',
            'closed_at' => '2026-10-22T13:00:00+02:00',
            'meal_break_seconds' => 0,
            'reason' => 'Rectificación que invade el turno de la mañana del mismo técnico.',
        ]
    );
    $assert(
        '3.3 Rectificación solapada => 409 SHIFT_OVERLAP_CONFLICT',
        $overlapAdjust->getStatusCode() === 409
            && $errorCode($overlapAdjust) === 'SHIFT_OVERLAP_CONFLICT',
        'body=' . $overlapAdjust->getBody()
    );

    $validAdjust = $api(
        'PATCH',
        "/api/coordinator/shifts/{$afternoonShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-22T12:00:00+02:00',
            'closed_at' => '2026-10-22T14:00:00+02:00',
            'meal_break_seconds' => 0,
            'reason' => 'El técnico confundió la salida con el inicio del turno de tarde.',
        ]
    );
    $validAdjustShift = $validAdjust->getDecodedBody()['data']['shift'] ?? [];
    $assert(
        '3.4 Rectificación válida => 200 con is_adjusted y la fecha de imputación actualizada',
        $validAdjust->getStatusCode() === 200
            && ($validAdjustShift['is_adjusted'] ?? false) === true
            && ($validAdjustShift['shift_date'] ?? '') === '2026-10-22'
            && (int)($validAdjustShift['gross_seconds'] ?? 0) === 7200,
        'body=' . $validAdjust->getBody()
    );

    // Turno cerrado desde MEAL_BREAK: la pausa se come toda la jornada bruta.
    $zeroEffectiveShift = $shiftRepo->startShift($martaId, new DateTimeImmutable('2026-10-23T08:00:00+02:00'));
    $zeroEffectiveShiftId = (int)$zeroEffectiveShift->id();
    $shiftRepo->pauseMeal($zeroEffectiveShiftId, new DateTimeImmutable('2026-10-23T08:00:00+02:00'));
    $shiftRepo->closeShift(
        $zeroEffectiveShiftId,
        $martaId,
        'Cierre desde pausa de la suite',
        new DateTimeImmutable('2026-10-23T09:00:00+02:00')
    );

    $zeroAdjust = $api(
        'PATCH',
        "/api/coordinator/shifts/{$zeroEffectiveShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-23T08:00:00+02:00',
            'closed_at' => '2026-10-23T09:00:00+02:00',
            'meal_break_seconds' => 3600,
            'reason' => 'Se ratifica la pausa completa que deja cero segundos efectivos.',
        ]
    );
    $zeroAdjustShift = $zeroAdjust->getDecodedBody()['data']['shift'] ?? [];
    $assert(
        '3.5 Rectificación con cero segundos efectivos permitida => 200',
        $zeroAdjust->getStatusCode() === 200
            && (int)($zeroAdjustShift['effective_seconds'] ?? -1) === 0
            && ($zeroAdjustShift['effective_formatted'] ?? '') === '00h 00m'
            && (int)($zeroAdjustShift['meal_break_seconds'] ?? 0) === 3600,
        'body=' . $zeroAdjust->getBody()
    );

    $reimpute = $api(
        'PATCH',
        "/api/coordinator/shifts/{$zeroEffectiveShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-24T23:00:00+02:00',
            'closed_at' => '2026-10-24T23:30:00+02:00',
            'meal_break_seconds' => 0,
            'reason' => 'El fichaje pertenecía al servicio nocturno del día siguiente.',
        ]
    );
    $reimputeShift = $reimpute->getDecodedBody()['data']['shift'] ?? [];
    $assert(
        '3.6 La rectificación reimputa shift_date al día del nuevo inicio',
        $reimpute->getStatusCode() === 200
            && ($reimputeShift['shift_date'] ?? '') === '2026-10-24'
            && strtotime((string)($reimputeShift['started_at'] ?? '')) === strtotime('2026-10-24T23:00:00+02:00'),
        'body=' . $reimpute->getBody()
    );
    $assert(
        '3.7 original_started_at, original_closed_at y original_meal_break_seconds del primer fichaje intactos',
        strtotime((string)($reimputeShift['original_started_at'] ?? '')) === strtotime('2026-10-23T08:00:00+02:00')
            && strtotime((string)($reimputeShift['original_closed_at'] ?? '')) === strtotime('2026-10-23T09:00:00+02:00')
            && (int)($reimputeShift['original_meal_break_seconds'] ?? -1) === 3600,
        'body=' . $reimpute->getBody()
    );

    $secondAdjust = $api(
        'PATCH',
        "/api/coordinator/shifts/{$zeroEffectiveShiftId}/adjust",
        $coordToken,
        [
            'started_at' => '2026-10-24T21:30:00+02:00',
            'closed_at' => '2026-10-24T22:30:00+02:00',
            'meal_break_seconds' => 600,
            'reason' => 'Segunda corrección: el servicio terminó una hora antes de lo fichado.',
        ]
    );
    $secondAdjustShift = $secondAdjust->getDecodedBody()['data']['shift'] ?? [];
    $assert(
        '3.8 Tras dos rectificaciones seguidas, los original_* siguen siendo los del primer fichaje',
        $secondAdjust->getStatusCode() === 200
            && strtotime((string)($secondAdjustShift['original_started_at'] ?? '')) === strtotime('2026-10-23T08:00:00+02:00')
            && strtotime((string)($secondAdjustShift['original_closed_at'] ?? '')) === strtotime('2026-10-23T09:00:00+02:00')
            && (int)($secondAdjustShift['original_meal_break_seconds'] ?? -1) === 3600
            && (int)($secondAdjustShift['meal_break_seconds'] ?? -1) === 600
            && ($secondAdjustShift['adjustment_reason'] ?? '') === 'Segunda corrección: el servicio terminó una hora antes de lo fichado.',
        'body=' . $secondAdjust->getBody()
    );

    $adjustLogs = $api('GET', "/api/coordinator/shifts/{$zeroEffectiveShiftId}/logs", $coordToken);
    $adjustLogEntries = $adjustLogs->getDecodedBody()['data']['logs'] ?? [];
    $adjustCount = count(array_filter(
        $adjustLogEntries,
        static fn (array $entry): bool => ($entry['event_type'] ?? '') === 'ADJUST_SHIFT'
    ));
    $assert(
        '3.9 Cada rectificación deja su propia entrada ADJUST_SHIFT en la bitácora',
        $adjustCount === 3
            && array_unique(array_map(
                static fn (array $entry): int => (int)($entry['actor_user_id'] ?? 0),
                $adjustLogEntries
            )) === [$coordinatorId],
        'ajustes=' . $adjustCount
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Informe de jornada y exportación CSV
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Informe de jornada y exportación CSV ---\n";

    // Plantilla activa sin sesiones + preventivos con y sin avería vinculada.
    $stmtLinkedIncident = $pdo->prepare("INSERT INTO incidents
        (ticket_code, location_id, machine_id, category, description, urgency, status, resolved_at)
        VALUES ('INC-TTIME-10-LINK', :location_id, :machine_id, 'TEMPERATURE_COLD',
                'Avería vinculada a la orden preventiva de la suite de jornada.', 'MEDIUM',
                'RESOLVED', TIMESTAMPTZ '2026-10-19 12:00:00+02')
        RETURNING id");
    $stmtLinkedIncident->execute([
        ':location_id' => $perishableLocationId,
        ':machine_id' => 2,
    ]);
    $linkedIncidentId = (int)$stmtLinkedIncident->fetchColumn();

    $stmtPreventive = $pdo->prepare("INSERT INTO preventive_orders
        (order_code, machine_id, location_id, assigned_technician_id, status, scheduled_date, due_date,
         started_at, completed_at, linked_incident_id)
        VALUES (:order_code, :machine_id, :location_id, :technician_id, 'COMPLETED',
                DATE '2026-10-19', DATE '2026-10-19', :started_at, :completed_at, :linked_incident_id)");
    $stmtPreventive->execute([
        ':order_code' => 'ORD-TTIME-10-A',
        ':machine_id' => 3,
        ':location_id' => $perishableLocationId,
        ':technician_id' => $elenaId,
        ':started_at' => '2026-10-19T09:00:00+02:00',
        ':completed_at' => '2026-10-19T11:00:00+02:00',
        ':linked_incident_id' => null,
    ]);
    $stmtPreventive->execute([
        ':order_code' => 'ORD-TTIME-10-B',
        ':machine_id' => 4,
        ':location_id' => $perishableLocationId,
        ':technician_id' => $elenaId,
        ':started_at' => '2026-10-19T13:00:00+02:00',
        ':completed_at' => '2026-10-19T15:00:00+02:00',
        ':linked_incident_id' => $linkedIncidentId,
    ]);

    // Inactivos: Marc con actividad en el rango y Carlos con actividad fuera de él.
    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id IN (:marc, :carlos)')
        ->execute([':marc' => $marcId, ':carlos' => $carlosId]);
    // El cierre del 18 se crea ANTES del turno huérfano: el repositorio es
    // idempotente y devolvería el turno abierto vigente en lugar de crear otro.
    $marcClosed = $shiftRepo->startShift($marcId, new DateTimeImmutable('2026-10-18T08:00:00+02:00'));
    $shiftRepo->closeShift((int)$marcClosed->id(), $marcId, 'Cierre de actividad de inactivo', new DateTimeImmutable('2026-10-18T16:00:00+02:00'));
    $shiftRepo->startShift($marcId, new DateTimeImmutable('2026-09-15T08:00:00+02:00'));
    $carlosShift = $shiftRepo->startShift($carlosId, new DateTimeImmutable('2026-09-30T08:00:00+02:00'));
    $shiftRepo->closeShift((int)$carlosShift->id(), $carlosId, 'Actividad fuera del rango del informe', new DateTimeImmutable('2026-09-30T16:00:00+02:00'));

    // El rango cierra en el día real de la corrida: las sesiones que la propia
    // suite crea con `$now` (turno de hoy y cierre forzado) deben entrar en el
    // informe. Una fecha tope fija dejaba fuera esas sesiones al cambiar el día.
    $reportQuery = [
        'period' => 'custom',
        'from' => '2026-10-01',
        'to' => $now->format('Y-m-d'),
    ];
    $report = $api('GET', '/api/coordinator/metrics/work-hours', $coordToken, null, $reportQuery);
    $reportData = $report->getDecodedBody()['data'] ?? [];
    $rows = $reportData['by_technician'] ?? [];
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int)($row['technician_id'] ?? 0)] = $row;
    }

    $assert(
        '4.1 Informe de jornada => 200 con filter, summary, by_technician y sessions',
        $report->getStatusCode() === 200
            && isset($reportData['filter'], $reportData['summary'], $reportData['by_technician'], $reportData['sessions']),
        'body=' . $report->getBody()
    );
    $assert(
        '4.2 El filtro ecoa el rango personalizado y la zona operativa',
        ($reportData['filter']['period'] ?? '') === 'custom'
            && ($reportData['filter']['from_date'] ?? '') === '2026-10-01'
            && ($reportData['filter']['to_date'] ?? '') === $now->format('Y-m-d')
            && ($reportData['filter']['timezone'] ?? '') === 'Europe/Madrid'
    );
    $assert(
        '4.3 Técnica activa sin sesiones aparece con 0 sesiones',
        isset($rowsById[$elenaId])
            && ($rowsById[$elenaId]['is_active'] ?? false) === true
            && (int)($rowsById[$elenaId]['sessions_count'] ?? -1) === 0,
        'fila=' . json_encode($rowsById[$elenaId] ?? null, JSON_UNESCAPED_UNICODE)
    );
    $assert(
        '4.4 Toda la plantilla activa con actividad aparece en el informe',
        isset($rowsById[$jordiId], $rowsById[$martaId])
            && (int)($rowsById[$jordiId]['sessions_count'] ?? 0) === 2
            && (int)($rowsById[$martaId]['sessions_count'] ?? 0) === 4,
        'jordi=' . (int)($rowsById[$jordiId]['sessions_count'] ?? -1) . ' marta=' . (int)($rowsById[$martaId]['sessions_count'] ?? -1)
    );
    $assert(
        '4.5 Técnico inactivo con actividad en el rango sí aparece, marcado inactivo',
        isset($rowsById[$marcId])
            && ($rowsById[$marcId]['is_active'] ?? true) === false
            && (int)($rowsById[$marcId]['sessions_count'] ?? 0) === 1
    );
    $assert(
        '4.6 Técnico inactivo sin actividad en el rango queda excluido',
        !isset($rowsById[$carlosId])
    );
    // Resolución QA 1: ambas órdenes cerradas cuentan como intervención resuelta,
    // pero la derivada en avería vinculada no aporta minutos (evita duplicarlos).
    $assert(
        '4.7 Preventivo con avería vinculada no suma minutos; el directo sí',
        (int)($rowsById[$elenaId]['resolved_interventions_count'] ?? -1) === 2
            && (int)($rowsById[$elenaId]['intervention_seconds'] ?? -1) === 7200,
        'fila=' . json_encode($rowsById[$elenaId] ?? null, JSON_UNESCAPED_UNICODE)
    );
    $assert(
        '4.8 El turno abierto huérfano previo al rango se publica y se resume',
        count($reportData['orphan_open_shifts'] ?? []) === 1
            && (int)($reportData['orphan_open_shifts'][0]['technician_id'] ?? 0) === $marcId
            && (int)($reportData['summary']['orphan_open_sessions_count'] ?? -1) === 1
    );
    $assert(
        '4.9 Tiempo en Ruta con suelo cero para quien solo tuvo intervención preventiva',
        (int)($rowsById[$elenaId]['route_seconds'] ?? -1) === 0
            && ($rowsById[$elenaId]['has_out_of_shift_interventions'] ?? false) === true
    );

    $export = $api('GET', '/api/coordinator/metrics/work-hours/export', $coordToken, null, $reportQuery);
    $csv = $export->getBody();
    $assert(
        '4.10 Exportación => 200 text/csv con nombre oficial fechado',
        $export->getStatusCode() === 200
            && str_starts_with((string)$export->getHeader('Content-Type'), 'text/csv')
            && str_contains(
                (string)$export->getHeader('Content-Disposition'),
                'vendguard_registro_jornada_20261001_' . $now->format('Ymd') . '.csv'
            ),
        'status=' . $export->getStatusCode() . ' content-type=' . (string)$export->getHeader('Content-Type')
    );
    // `fputcsv` protege con comillas todo campo que contiene espacios; el
    // contrato fija columnas y separador, no la ausencia de comillas. Se valida
    // el contenido ya deserializado, que es lo que consume Excel.
    $csvLines = preg_split('/\r\n|\n/', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
    $firstHeader = str_getcsv($csvLines[0] ?? '', ';');
    $assert(
        '4.11 CSV con BOM UTF-8 y cabecera de técnicos en el orden del contrato',
        str_starts_with($csv, "\xEF\xBB\xBF")
            && $firstHeader === [
                'Técnico',
                'Estado en Plantilla',
                'Sesiones',
                'Horas Brutas',
                'Tiempo Comida',
                'Horas Efectivas Netas',
                'Tiempo en Intervención',
                'Tiempo en Ruta',
                'Intervenciones Resueltas',
                'Turnos Anómalos (>12h)',
                'Intervención Fuera de Turno',
            ],
        'cabecera=' . json_encode($firstHeader, JSON_UNESCAPED_UNICODE)
    );
    // La fila en blanco separa la sección de técnicos de la de sesiones, así que
    // su posición depende del número de filas del resumen.
    $separatorIndex = array_search('', $csvLines, true);
    $secondHeader = [];
    if ($separatorIndex !== false) {
        for ($lineIndex = $separatorIndex + 1; $lineIndex < count($csvLines); $lineIndex++) {
            if ($csvLines[$lineIndex] !== '') {
                $secondHeader = str_getcsv($csvLines[$lineIndex], ';');
                break;
            }
        }
    }
    $assert(
        '4.12 CSV con fila en blanco y cabecera de la sección de sesiones',
        $separatorIndex !== false
            && $secondHeader === [
                'ID Sesión',
                'Fecha Imputación',
                'Técnico',
                'Entrada Vigente',
                'Salida Vigente',
                'Duración Bruta',
                'Pausa Comida',
                'Horas Efectivas Netas',
                'Estado',
                'Cierre Forzado',
                'Ajustado por Coordinación',
                'Entrada Original Fichada',
                'Salida Original Fichada',
                'Motivo Cierre o Ajuste',
            ],
        'cabecera=' . json_encode($secondHeader, JSON_UNESCAPED_UNICODE)
    );

    $csvRows = [];
    foreach ($csvLines as $line) {
        if ($line === '') {
            continue;
        }
        $csvRows[] = str_getcsv($line, ';');
    }
    $csvRowByName = static function (string $name) use ($csvRows): ?array {
        foreach ($csvRows as $row) {
            if (($row[0] ?? '') === $name) {
                return $row;
            }
        }

        return null;
    };
    $elenaRow = $csvRowByName('Elena Técnica Ruta Nord');
    $marcRow = $csvRowByName('Marc Técnico Express BCN');
    $assert(
        '4.13 CSV distingue plantilla activa sin sesiones y técnico inactivo con actividad',
        $elenaRow !== null
            && ($elenaRow[1] ?? '') === 'Activo'
            && ($elenaRow[2] ?? '') === '0'
            && $marcRow !== null
            && ($marcRow[1] ?? '') === 'Inactivo'
            && ($marcRow[2] ?? '') === '1',
        'elena=' . json_encode($elenaRow, JSON_UNESCAPED_UNICODE) . ' marc=' . json_encode($marcRow, JSON_UNESCAPED_UNICODE)
    );

    $forcedSessionRow = null;
    foreach ($csvRows as $fields) {
        if (isset($fields[0]) && $fields[0] === (string)$forcedShiftId) {
            $forcedSessionRow = $fields;
            break;
        }
    }
    $assert(
        '4.14 La sesión con cierre forzado y ajuste viaja marcada en el detalle del CSV',
        $forcedSessionRow !== null
            && ($forcedSessionRow[9] ?? '') === 'Sí'
            && ($forcedSessionRow[10] ?? '') === 'Sí',
        'fila=' . json_encode($forcedSessionRow, JSON_UNESCAPED_UNICODE)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Aislamiento Art. V.4 en GET /api/technician/shift/my-hours
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Aislamiento Art. V.4 de las horas propias del técnico ---\n";

    $myHours = $api('GET', '/api/technician/shift/my-hours', $jordiToken);
    $myHoursData = $myHours->getDecodedBody()['data'] ?? [];
    $ownSessions = $myHoursData['sessions'] ?? [];
    $ownIds = array_map(static fn (array $session): int => (int)($session['id'] ?? 0), $ownSessions);
    $allOwn = true;
    foreach ($ownSessions as $session) {
        if ((int)($session['technician_id'] ?? 0) !== $jordiId) {
            $allOwn = false;
        }
    }
    $assert(
        '5.1 my-hours => 200 con el mes en curso y solo sesiones del técnico autenticado',
        $myHours->getStatusCode() === 200
            && ($myHoursData['month'] ?? '') === $now->format('Y-m')
            && $ownSessions !== []
            && $allOwn
            && in_array($sessionId, $ownIds, true),
        'body=' . $myHours->getBody()
    );

    $foreignMyHours = $api(
        'GET',
        '/api/technician/shift/my-hours',
        $jordiToken,
        null,
        ['technician_id' => $martaId]
    );
    $foreignSessions = $foreignMyHours->getDecodedBody()['data']['sessions'] ?? [];
    $foreignIds = array_map(static fn (array $session): int => (int)($session['id'] ?? 0), $foreignSessions);
    $assert(
        '5.2 Un technician_id ajeno en la query no cambia el alcance del token',
        $foreignMyHours->getStatusCode() === 200
            && $foreignIds === $ownIds,
        'propios=' . implode(',', $ownIds) . ' ajenos=' . implode(',', $foreignIds)
    );
    $assert(
        '5.3 El payload propio no expone el informe agregado de la plantilla',
        !array_key_exists('by_technician', $myHoursData)
            && !array_key_exists('orphan_open_shifts', $myHoursData)
    );

    $coordinatorMyHours = $api('GET', '/api/technician/shift/my-hours', $coordToken);
    $assert(
        '5.4 El rol de Coordinación no puede leer las horas propias del técnico => 403',
        $coordinatorMyHours->getStatusCode() === 403 && $errorCode($coordinatorMyHours) === 'FORBIDDEN'
    );

    $siteLogin = $api('POST', '/api/auth/site-login', null, [
        'site_code' => 'SEDE-BCN-01',
        'access_code' => SeedRunner::devAccessCode('SEDE-BCN-01'),
    ]);
    $siteToken = (string)($siteLogin->getDecodedBody()['data']['token'] ?? '');
    $siteMyHours = $api('GET', '/api/technician/shift/my-hours', $siteToken !== '' ? $siteToken : null);
    $assert(
        '5.5 El Portal de Sede no puede leer horas de técnicos ni con su propio token',
        $siteToken !== ''
            && in_array($siteMyHours->getStatusCode(), [401, 403], true),
        'status=' . $siteMyHours->getStatusCode() . ' body=' . $siteMyHours->getBody()
    );

    $anonymousMyHours = $api('GET', '/api/technician/shift/my-hours');
    $assert(
        '5.6 Sin token => 401 UNAUTHORIZED',
        $anonymousMyHours->getStatusCode() === 401
            && $errorCode($anonymousMyHours) === 'UNAUTHORIZED'
    );
} finally {
    // Restitución de la plantilla: la purga no toca `users` y dejar inactivos o
    // de guardia a los técnicos sembrados contaminaría las suites siguientes.
    $pdo->exec('UPDATE users SET is_active = TRUE, is_on_call = FALSE');
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
