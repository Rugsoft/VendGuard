<?php

declare(strict_types=1);

/**
 * HandoverAvailabilityApiTest · RF-05.5 / RF-05.6 / H3
 *
 * Certifica en HTTP que:
 *
 *   1. `GET /api/technician/handover-targets` publica disponibilidad operativa
 *      (`shift_status`, `is_on_call`, `availability`, `availability_label`,
 *      `is_available_for_handover`) sin motivos ni fechas de ausencia.
 *   2. La lista ordena disponibles primero.
 *   3. El servidor rechaza con `422 TARGET_TECHNICIAN_UNAVAILABLE` un receptor
 *      ausente, en pausa o fuera de turno sin guardia, sin modificar el turno ni
 *      la avería.
 *   4. Un receptor sin averías se rechaza con `422 TARGET_WITHOUT_TRANSFERS`.
 *   5. Un guardia 24/7 fuera de turno y un compañero en turno sí reciben.
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
echo " VendGuard: Integración - HandoverAvailabilityApiTest (RF-05.5 / H3)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$shiftRepo = new PdoTechnicianShiftRepository($pdo);
$authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$targets = static function ($router, string $token): array {
    $res = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/technician/handover-targets',
        headers: ['Authorization' => 'Bearer ' . $token]
    ));

    return ['status' => $res->getStatusCode(), 'data' => $res->getDecodedBody()['data'] ?? []];
};

$byId = static function (array $targets, int $id): ?array {
    foreach ($targets as $target) {
        if ((int)$target['id'] === $id) {
            return $target;
        }
    }

    return null;
};

try {
    $tech1 = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
    $techRows = $pdo->query(
        "SELECT id FROM users
          WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL
          ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (count($techRows) < 4) {
        throw new RuntimeException('La prueba necesita cuatro técnicos sembrados.');
    }

    $tech1Id = (int)$tech1->getId();
    $absentTechId = (int)$techRows[1];
    $mealTechId = (int)$techRows[2];
    $onCallTechId = (int)$techRows[3];

    // El índice único parcial uq_machine_active_ticket impide dos averías
    // activas sobre la misma máquina: cada escenario usa una distinta.
    $machines = $pdo->query(
        "SELECT id FROM machines WHERE deleted_at IS NULL ORDER BY id LIMIT 2"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($machines) < 2) {
        throw new RuntimeException('La prueba necesita dos máquinas sembradas.');
    }
    $machineA = (int)$machines[0];
    $machineB = (int)$machines[1];

    $token = $authService->generateInternalToken($tech1);
    $shiftRepo->startShift($tech1Id);

    // ─────────────────────────────────────────────────────────────────────
    // 1. Listado con disponibilidad y sin datos privados
    // ─────────────────────────────────────────────────────────────────────
    $first = $targets($router, $token);
    $assert('El listado de receptores responde 200', $first['status'] === 200, 'http=' . $first['status']);

    $sample = $first['data'][0] ?? [];
    $expectedKeys = ['id', 'name', 'is_active', 'is_on_call', 'shift_status', 'availability', 'availability_label', 'is_available_for_handover'];
    $missingKeys = array_values(array_diff($expectedKeys, array_keys($sample)));
    $assert(
        'Cada receptor publica los campos de disponibilidad',
        $missingKeys === [],
        'faltan: ' . implode(',', $missingKeys)
    );
    $privateKeys = ['absence_reason', 'reason', 'starts_at', 'ends_at', 'notes', 'email', 'phone', 'telegram_chat_id'];
    $leaked = array_values(array_intersect($privateKeys, array_keys($sample)));
    $assert('El listado no expone motivos, fechas, notas ni contactos', $leaked === [], 'filtrados: ' . implode(',', $leaked));

    // ─────────────────────────────────────────────────────────────────────
    // 2. Ausente sin turno => UNAVAILABLE y rechazo 422
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare(
        "INSERT INTO technician_absences (technician_id, reason, starts_at, ends_at, notes, created_by)
         VALUES (:technician_id, 'VACATION', NOW() - INTERVAL '1 hour', NOW() + INTERVAL '1 day',
                 'Vacaciones de la prueba de disponibilidad', :created_by)"
    )->execute([':technician_id' => $absentTechId, ':created_by' => $tech1Id]);

    $withAbsence = $targets($router, $token);
    $absentTarget = $byId($withAbsence['data'], $absentTechId);
    $assert(
        'Un técnico con ausencia activa y sin turno se publica como UNAVAILABLE',
        is_array($absentTarget)
            && $absentTarget['availability'] === 'UNAVAILABLE'
            && $absentTarget['is_available_for_handover'] === false
            && $absentTarget['shift_status'] === 'OFF_SHIFT',
        json_encode($absentTarget, JSON_UNESCAPED_UNICODE)
    );

    $incidentResponse = $pdo->prepare(
        "INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
         VALUES ('INC-AVL-001', 1, :machine_id, 'PAYMENT_SYSTEM', 'Prueba de receptor ausente', 'LOW', 'ASSIGNED', :technician_id, NOW())
         RETURNING id"
    );
    $incidentResponse->execute([':machine_id' => $machineA, ':technician_id' => $tech1Id]);
    $incidentId = (int)$incidentResponse->fetchColumn();

    $rejectAbsent = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $absentTechId,
            'incident_ids' => [$incidentId],
            'handover_notes' => 'Intento de traspaso a un compañero ausente',
        ],
        headers: ['Authorization' => 'Bearer ' . $token]
    ));
    $assert(
        'Traspasar a un ausente => 422 TARGET_TECHNICIAN_UNAVAILABLE',
        $rejectAbsent->getStatusCode() === 422
            && ($rejectAbsent->getDecodedBody()['error']['code'] ?? '') === 'TARGET_TECHNICIAN_UNAVAILABLE',
        'http=' . $rejectAbsent->getStatusCode() . ' body=' . json_encode($rejectAbsent->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );

    $shiftStillOpen = $pdo->query(
        "SELECT status FROM technician_shifts WHERE technician_id = {$tech1Id} AND closed_at IS NULL"
    )->fetchColumn();
    $incidentOwner = (int)$pdo->query("SELECT assigned_technician_id FROM incidents WHERE id = {$incidentId}")->fetchColumn();
    $assert('El rechazo no cierra el turno del saliente', $shiftStillOpen === 'ON_SHIFT', 'estado=' . var_export($shiftStillOpen, true));
    $assert('El rechazo no reasigna la avería', $incidentOwner === $tech1Id, 'owner=' . $incidentOwner);

    // ─────────────────────────────────────────────────────────────────────
    // 3. Receptor en pausa de comida => 422
    // ─────────────────────────────────────────────────────────────────────
    $mealShift = $shiftRepo->startShift($mealTechId);
    $shiftRepo->pauseMeal($mealShift->id());

    $mealTarget = $byId($targets($router, $token)['data'], $mealTechId);
    $assert(
        'Un técnico en pausa se publica como MEAL_BREAK y no disponible',
        is_array($mealTarget)
            && $mealTarget['availability'] === 'MEAL_BREAK'
            && $mealTarget['is_available_for_handover'] === false,
        json_encode($mealTarget, JSON_UNESCAPED_UNICODE)
    );

    $rejectMeal = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $mealTechId,
            'incident_ids' => [$incidentId],
            'handover_notes' => 'Intento de traspaso a un compañero en pausa',
        ],
        headers: ['Authorization' => 'Bearer ' . $token]
    ));
    $assert(
        'Traspasar a un técnico en pausa => 422 TARGET_TECHNICIAN_UNAVAILABLE',
        $rejectMeal->getStatusCode() === 422
            && ($rejectMeal->getDecodedBody()['error']['code'] ?? '') === 'TARGET_TECHNICIAN_UNAVAILABLE',
        'http=' . $rejectMeal->getStatusCode()
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Receptor sin averías => 422 TARGET_WITHOUT_TRANSFERS
    // ─────────────────────────────────────────────────────────────────────
    $rejectNoIncidents = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $mealTechId,
            'incident_ids' => [],
            'handover_notes' => '',
        ],
        headers: ['Authorization' => 'Bearer ' . $token]
    ));
    $assert(
        'Un receptor sin averías => 422 TARGET_WITHOUT_TRANSFERS',
        $rejectNoIncidents->getStatusCode() === 422
            && ($rejectNoIncidents->getDecodedBody()['error']['code'] ?? '') === 'TARGET_WITHOUT_TRANSFERS',
        'http=' . $rejectNoIncidents->getStatusCode() . ' body=' . json_encode($rejectNoIncidents->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Guardia 24/7 fuera de turno => ON_CALL y traspaso aceptado
    // ─────────────────────────────────────────────────────────────────────
    $shiftRepo->closeShift($mealShift->id(), $mealTechId, 'Fin de la prueba de pausa');
    $pdo->prepare("UPDATE users SET is_on_call = TRUE WHERE id = :id")->execute([':id' => $onCallTechId]);

    $onCallTarget = $byId($targets($router, $token)['data'], $onCallTechId);
    $assert(
        'Un guardia fuera de turno se publica como ON_CALL y disponible',
        is_array($onCallTarget)
            && $onCallTarget['availability'] === 'ON_CALL'
            && $onCallTarget['is_available_for_handover'] === true,
        json_encode($onCallTarget, JSON_UNESCAPED_UNICODE)
    );

    $acceptOnCall = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $onCallTechId,
            'incident_ids' => [$incidentId],
            'handover_notes' => 'Traspaso al guardia 24/7 de la prueba',
            'close_reason' => 'Fin de jornada con relevo al guardia',
        ],
        headers: ['Authorization' => 'Bearer ' . $token]
    ));
    $assert(
        'El guardia 24/7 fuera de turno acepta el relevo => 200',
        $acceptOnCall->getStatusCode() === 200,
        'http=' . $acceptOnCall->getStatusCode() . ' body=' . json_encode($acceptOnCall->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );
    $assert(
        'La avería queda asignada al guardia',
        (int)$pdo->query("SELECT assigned_technician_id FROM incidents WHERE id = {$incidentId}")->fetchColumn() === $onCallTechId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Compañero en turno => AVAILABLE y aceptado
    // ─────────────────────────────────────────────────────────────────────
    $shiftRepo->startShift($tech1Id);
    $incident2 = $pdo->prepare(
        "INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
         VALUES ('INC-AVL-002', 1, :machine_id, 'PAYMENT_SYSTEM', 'Prueba de receptor en turno', 'LOW', 'ASSIGNED', :technician_id, NOW())
         RETURNING id"
    );
    $incident2->execute([':machine_id' => $machineB, ':technician_id' => $tech1Id]);
    $incident2Id = (int)$incident2->fetchColumn();

    $shiftRepo->startShift($mealTechId);
    $ordered = $targets($router, $token)['data'];
    $onShiftTarget = $byId($ordered, $mealTechId);
    $assert(
        'El listado ordena primero a los receptores disponibles',
        isset($ordered[0]) && $ordered[0]['id'] === $mealTechId
            && $ordered[0]['is_available_for_handover'] === true,
        'primero: ' . json_encode($ordered[0] ?? null, JSON_UNESCAPED_UNICODE)
    );
    $assert(
        'Un compañero en turno se publica como AVAILABLE y disponible',
        is_array($onShiftTarget)
            && $onShiftTarget['availability'] === 'AVAILABLE'
            && $onShiftTarget['is_available_for_handover'] === true,
        json_encode($onShiftTarget, JSON_UNESCAPED_UNICODE)
    );

    $acceptOnShift = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/shift/close-handover',
        parsedBody: [
            'target_technician_id' => $mealTechId,
            'incident_ids' => [$incident2Id],
            'handover_notes' => 'Traspaso al compañero en turno de la prueba',
            'close_reason' => 'Fin de jornada con relevo en turno',
        ],
        headers: ['Authorization' => 'Bearer ' . $token]
    ));
    $assert(
        'El compañero en turno acepta el relevo => 200',
        $acceptOnShift->getStatusCode() === 200,
        'http=' . $acceptOnShift->getStatusCode() . ' body=' . json_encode($acceptOnShift->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );
} catch (Throwable $exception) {
    $assert('La prueba de disponibilidad se ejecuta sin excepción de infraestructura', false, $exception->getMessage());
    echo $exception->getTraceAsString() . "\n";
} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
