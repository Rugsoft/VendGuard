<?php

declare(strict_types=1);

/**
 * ShiftHandoverConcurrencyTest · RF-05.3 / R9
 *
 * Certifica la concurrencia atómica del relevo de fin de turno (RF-05.3 / R9)
 * con DOS CONEXIONES REALES, no con un doble de prueba:
 *
 *   1. Un proceso actor bloquea la incidencia con `FOR UPDATE` y la reasigna a
 *      un tercer técnico mientras el relevo está en curso.
 *   2. El proceso del relevo debe quedar esperando en `pg_locks` y, al recibir
 *      el COMMIT ajeno, rechazar la solicitud con
 *      `409 CONCURRENT_ROUTE_MODIFICATION`.
 *   3. La incidencia conserva al técnico que ganó la carrera, el turno del
 *      saliente sigue abierto y no se escribe comentario ni auditoría de relevo.
 *
 * El actor espera a observar la transacción del relevo bloqueada antes de
 * confirmar. Así la prueba distingue un cerrojo real de una simple llegada
 * tardía: si el `FOR UPDATE` no existiera, el actor agotaría su espera y
 * dejaría la señal `timeout`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\HandoverTargetService;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - ShiftHandoverConcurrencyTest (RF-05.3 / R9)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
// El actor identifica esta sesión por `application_name`; `pg_stat_activity.pid`
// es el backend del servidor y no el PID del proceso cliente.
$pdo->exec("SET application_name TO 'vendguard-handover-concurrency-parent'");
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

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

$readyFile = '';
$statusFile = '';
$process = null;
$pipes = [];

try {
    $techRows = $pdo->query(
        "SELECT id FROM users
          WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL
          ORDER BY id LIMIT 3"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (count($techRows) < 3) {
        throw new RuntimeException('La prueba de concurrencia necesita tres técnicos activos en el banco.');
    }

    $outgoingTechnicianId = (int)$techRows[0];
    $handoverTargetId = (int)$techRows[1];
    $concurrentWinnerId = (int)$techRows[2];

    $machine = $pdo->query(
        "SELECT id, location_id FROM machines
          WHERE machine_type = 'SNACKS' AND deleted_at IS NULL
          ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($machine)) {
        throw new RuntimeException('La prueba de concurrencia necesita una máquina de snacks sembrada.');
    }

    $ticketCode = 'INC-CONC-001';
    $insert = $pdo->prepare(
        "INSERT INTO incidents
            (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
         VALUES
            (:ticket_code, :location_id, :machine_id, 'PAYMENT_SYSTEM',
             'Prueba de concurrencia del relevo de fin de turno', 'MEDIUM',
             'ASSIGNED', :technician_id, NOW())
         RETURNING id"
    );
    $insert->execute([
        ':ticket_code' => $ticketCode,
        ':location_id' => (int)$machine['location_id'],
        ':machine_id' => (int)$machine['id'],
        ':technician_id' => $outgoingTechnicianId,
    ]);
    $incidentId = (int)$insert->fetchColumn();

    $shiftRepo = new PdoTechnicianShiftRepository($pdo);
    $shiftRepo->startShift($outgoingTechnicianId);
    // RF-05.5: el receptor manual debe estar en turno; el rechazo de un
    // receptor OFF_SHIFT se certifica en HandoverAvailabilityApiTest.
    $shiftRepo->startShift($handoverTargetId);

    $userRepo = new PdoUserRepository($pdo);
    $absenceRepo = new PdoTechnicianAbsenceRepository($pdo);
    $service = new ShiftHandoverService(
        pdo: $pdo,
        shiftRepo: $shiftRepo,
        userRepo: $userRepo,
        incidentRepo: new PdoIncidentRepository($pdo),
        machineRepo: new PdoMachineRepository($pdo),
        onCallRepo: $userRepo,
        auditRepo: new PdoAuditLogRepository($pdo),
        handoverTargets: new HandoverTargetService($userRepo, $shiftRepo, $absenceRepo),
        notifications: null
    );

    $prefix = tempnam(sys_get_temp_dir(), 'vendguard_handover_');
    if ($prefix === false) {
        throw new RuntimeException('No se pudo crear el prefijo de los ficheros de sincronización.');
    }
    unlink($prefix);
    $readyFile = $prefix . '.ready';
    $statusFile = $prefix . '.status';

    $parentApplicationName = 'vendguard-handover-concurrency-parent';

    $command = [
        PHP_BINARY,
        __DIR__ . '/../Support/HandoverConcurrencyActor.php',
        (string)$incidentId,
        (string)$concurrentWinnerId,
        $parentApplicationName,
        $readyFile,
        $statusFile,
    ];

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo lanzar el actor concurrente.');
    }

    $readyDeadline = microtime(true) + 5.0;
    while (!is_file($readyFile) && microtime(true) < $readyDeadline) {
        usleep(20_000);
    }

    if (!is_file($readyFile)) {
        throw new RuntimeException('El actor concurrente no llegó a bloquear la incidencia.');
    }

    $errorCode = null;
    $httpStatus = null;
    try {
        $service->closeAndHandover(
            technicianId: $outgoingTechnicianId,
            targetTechnicianId: $handoverTargetId,
            incidentIdsToTransfer: [$incidentId],
            handoverNotes: 'Nota de relevo de la prueba de concurrencia',
            closeReason: 'Cierre con carrera de reasignación'
        );
    } catch (InvalidShiftTransitionException $exception) {
        $errorCode = $exception->getErrorCode();
        $httpStatus = $exception->getHttpStatusCode();
    }

    $waitDeadline = microtime(true) + 6.0;
    $processStatus = proc_get_status($process);
    while ($processStatus['running'] && microtime(true) < $waitDeadline) {
        usleep(20_000);
        $processStatus = proc_get_status($process);
    }

    if ($processStatus['running']) {
        proc_terminate($process);
    }

    $childExitCode = (int)$processStatus['exitcode'];

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    $process = null;

    $actorStatus = is_file($statusFile) ? trim((string)file_get_contents($statusFile)) : '';

    $winner = $pdo->query(
        'SELECT assigned_technician_id, status FROM incidents WHERE id = ' . $incidentId
    )->fetch(PDO::FETCH_ASSOC);

    $shift = $pdo->query(
        'SELECT status, closed_at FROM technician_shifts
          WHERE technician_id = ' . $outgoingTechnicianId . '
            AND closed_at IS NULL
          ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    $handoverAudit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE action = 'SHIFT_HANDOVER_REASSIGNMENT' AND entity_id = {$incidentId}"
    )->fetchColumn();

    $handoverComments = (int)$pdo->query(
        "SELECT COUNT(*) FROM incident_comments
          WHERE incident_id = {$incidentId}
            AND comment_text LIKE 'Traspaso de fin de turno%'"
    )->fetchColumn();

    $assert(
        'El actor observó la transacción del relevo esperando el cerrojo FOR UPDATE',
        $actorStatus === 'observed',
        'estado del actor: ' . ($actorStatus !== '' ? $actorStatus : '(sin fichero)')
    );
    $assert('El actor concurrente terminó sin error', $childExitCode === 0, 'exit=' . $childExitCode);
    $assert(
        'El relevo rechaza la carrera con 409 CONCURRENT_ROUTE_MODIFICATION',
        $errorCode === 'CONCURRENT_ROUTE_MODIFICATION' && $httpStatus === 409,
        'código=' . var_export($errorCode, true) . ' http=' . var_export($httpStatus, true)
    );
    $assert(
        'La incidencia conserva al técnico que ganó la carrera',
        is_array($winner) && (int)$winner['assigned_technician_id'] === $concurrentWinnerId,
        'asignada a: ' . var_export($winner['assigned_technician_id'] ?? null, true)
    );
    $assert(
        'La incidencia conserva el estado ASSIGNED',
        is_array($winner) && $winner['status'] === 'ASSIGNED',
        'estado: ' . var_export($winner['status'] ?? null, true)
    );
    $assert(
        'El turno del saliente sigue abierto tras el ROLLBACK',
        is_array($shift) && $shift['status'] === 'ON_SHIFT' && $shift['closed_at'] === null,
        'turno: ' . json_encode($shift, JSON_UNESCAPED_UNICODE)
    );
    $assert('La carrera no deja auditoría de relevo', $handoverAudit === 0, 'eventos: ' . $handoverAudit);
    $assert('La carrera no deja comentario de traspaso', $handoverComments === 0, 'comentarios: ' . $handoverComments);
} catch (Throwable $exception) {
    $assert('La prueba de concurrencia se ejecuta sin excepción de infraestructura', false, $exception->getMessage());
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }

    foreach ([$readyFile, $statusFile] as $file) {
        if ($file !== '' && is_file($file)) {
            unlink($file);
        }
    }

    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
