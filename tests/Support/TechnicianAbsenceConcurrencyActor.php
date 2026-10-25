<?php

declare(strict_types=1);

/**
 * TechnicianAbsenceConcurrencyActor · actor externo de la prueba de concurrencia
 * del alta de ausencias (Módulo 21, RF-01.5 / H4).
 *
 * Se ejecuta en un proceso aparte para que el `FOR UPDATE` del alta de ausencia
 * tenga un dueño real con el que competir. El actor:
 *
 *   1. Abre su propia conexión PDO con `PG_TEST_DSN` y fija el `search_path` al
 *      esquema aislado de la suite.
 *   2. Bloquea la fila del técnico con `SELECT ... FOR UPDATE`.
 *   3. Avisa al test de que el cerrojo está tomado.
 *   4. Espera a que la transacción del alta aparezca bloqueada en
 *      `pg_stat_activity` (`wait_event_type = 'Lock'`), señal de que el
 *      `FOR UPDATE` del servicio está esperando de verdad.
 *   5. Inserta su ausencia y confirma.
 *
 * Uso interno (lo invoca `tests/integration/TechnicianAbsenceConcurrencyTest.php`):
 *   php TechnicianAbsenceConcurrencyActor.php <schema> <technicianId> <createdBy> \
 *       <startsAt> <endsAt> <parentApplicationName> <readyFile> <statusFile>
 */

require_once __DIR__ . '/../bootstrap.php';

if ($argc < 9) {
    fwrite(STDERR, "Uso: php TechnicianAbsenceConcurrencyActor.php <schema> <technicianId> <createdBy> <startsAt> <endsAt> <parentApplicationName> <readyFile> <statusFile>\n");
    exit(2);
}

$schema = (string)$argv[1];
$technicianId = (int)$argv[2];
$createdBy = (int)$argv[3];
$startsAt = (string)$argv[4];
$endsAt = (string)$argv[5];
$parentApplicationName = (string)$argv[6];
$readyFile = (string)$argv[7];
$statusFile = (string)$argv[8];

$writeStatus = static function (string $status) use ($statusFile): void {
    file_put_contents($statusFile, $status);
};

$dsn = getenv('PG_TEST_DSN');
if ($dsn === false || trim($dsn) === '') {
    $writeStatus('error: falta PG_TEST_DSN');
    fwrite(STDERR, "Falta PG_TEST_DSN.\n");
    exit(2);
}

try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET search_path TO ' . $schema . ', public');

    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        "SELECT id FROM users
          WHERE id = :id
            AND role = 'TECHNICIAN'
            AND deleted_at IS NULL
          FOR UPDATE"
    );
    $lock->execute([':id' => $technicianId]);

    if ($lock->fetchColumn() === false) {
        throw new RuntimeException("El técnico {$technicianId} no existe para el actor concurrente.");
    }

    file_put_contents($readyFile, 'locked');

    // La transacción del alta debe quedar esperando en `pg_stat_activity`. Si no
    // aparece en cinco segundos, el actor confirma igualmente y deja la señal
    // `timeout` para que la prueba falle por la causa correcta.
    $deadline = microtime(true) + 5.0;
    $observedWaiter = false;
    while (microtime(true) < $deadline) {
        $waitingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pg_stat_activity
              WHERE application_name = :application_name
                AND wait_event_type = 'Lock'"
        );
        $waitingStmt->execute([':application_name' => $parentApplicationName]);
        if ((int)$waitingStmt->fetchColumn() > 0) {
            $observedWaiter = true;
            break;
        }

        usleep(20_000);
    }

    $insert = $pdo->prepare(
        "INSERT INTO technician_absences
            (technician_id, reason, starts_at, ends_at, notes, created_by)
         VALUES
            (:technician_id, 'VACATION', :starts_at, :ends_at,
             'Ausencia del actor concurrente', :created_by)
         RETURNING id"
    );
    $insert->execute([
        ':technician_id' => $technicianId,
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':created_by' => $createdBy,
    ]);
    $absenceId = (int)$insert->fetchColumn();

    $pdo->commit();
    $writeStatus(($observedWaiter ? 'observed' : 'timeout') . ':inserted:' . $absenceId);
    exit(0);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $writeStatus('error: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
