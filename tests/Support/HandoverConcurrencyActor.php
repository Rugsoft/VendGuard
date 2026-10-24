<?php

declare(strict_types=1);

/**
 * HandoverConcurrencyActor · actor externo de la prueba de concurrencia del Módulo 21.
 *
 * Se ejecuta en un proceso aparte para que el `FOR UPDATE` del relevo tenga un
 * dueño real con el que competir. El actor:
 *
 *   1. Abre su propia conexión PDO y bloquea la incidencia con `FOR UPDATE`.
 *   2. Avisa al test de que el cerrojo está tomado.
 *   3. Espera a que la transacción del relevo aparezca como bloqueada en
 *      `pg_locks` (señal de que el `SELECT ... FOR UPDATE` del servicio está
 *      esperando de verdad, no de que llegó tarde).
 *   4. Reasigna la incidencia a otro técnico y confirma.
 *
 * Uso interno (lo invoca `tests/integration/ShiftHandoverConcurrencyTest.php`):
 *   php HandoverConcurrencyActor.php <incidentId> <toTechId> <parentPid> <readyFile> <statusFile>
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;

if ($argc < 6) {
    fwrite(STDERR, "Uso: php HandoverConcurrencyActor.php <incidentId> <toTechId> <parentPid> <readyFile> <statusFile>\n");
    exit(2);
}

$incidentId = (int)$argv[1];
$toTechnicianId = (int)$argv[2];
$parentPid = (int)$argv[3];
$readyFile = (string)$argv[4];
$statusFile = (string)$argv[5];

$writeStatus = static function (string $status) use ($statusFile): void {
    file_put_contents($statusFile, $status);
};

$pdo = ConnectionFactory::getConnection();
$pdo->beginTransaction();

try {
    $lock = $pdo->prepare('SELECT id FROM incidents WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
    $lock->execute([':id' => $incidentId]);

    if ($lock->fetch(PDO::FETCH_ASSOC) === false) {
        throw new RuntimeException("La incidencia {$incidentId} no existe para el actor concurrente.");
    }

    file_put_contents($readyFile, 'locked');

    // La transacción del relevo debe quedar esperando en `pg_locks`. Si no
    // aparece en cinco segundos, el actor confirma igualmente y deja la señal
    // `timeout` para que la prueba falle por la causa correcta.
    $deadline = microtime(true) + 5.0;
    $observedWaiter = false;
    while (microtime(true) < $deadline) {
        // `pg_locks` representa la espera de una fila como un lock de
        // transacción y `pg_stat_activity.query` conserva el último comando
        // simple, no la sentencia extendida que está esperando. El PID del
        // proceso del relevo es la señal inequívoca: si está en `wait_event_type
        // = 'Lock'`, su `FOR UPDATE` está bloqueado por este actor.
        $waitingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pg_stat_activity
              WHERE pid = :pid AND wait_event_type = 'Lock'"
        );
        $waitingStmt->execute([':pid' => $parentPid]);
        $waiting = (int)$waitingStmt->fetchColumn();

        if ($waiting > 0) {
            $observedWaiter = true;
            break;
        }

        usleep(20_000);
    }

    // Reasignación concurrente real: el repositorio valida el estado actual y
    // escribe historial dentro de la misma transacción que ya tiene el cerrojo.
    $incidentRepo = new PdoIncidentRepository($pdo);
    $incidentRepo->assign(
        incidentId: $incidentId,
        technicianId: $toTechnicianId,
        coordinatorId: null,
        urgencyOverride: null,
        urgencyReason: null,
        reassignmentReason: 'Reasignación concurrente de la prueba de relevo'
    );

    $pdo->commit();
    $writeStatus($observedWaiter ? 'observed' : 'timeout');
    exit(0);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $writeStatus('error: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
