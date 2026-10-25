<?php

declare(strict_types=1);

/**
 * TechnicianAbsenceConcurrencyTest · RF-01.5 / H4
 *
 * Certifica con DOS CONEXIONES REALES que el alta de ausencias está serializada
 * por técnico y que la comprobación de solapamiento ya no es una carrera TOCTOU:
 *
 *   1. Un proceso actor bloquea la fila del técnico con `FOR UPDATE`.
 *   2. El proceso del alta (el servicio real) queda esperando en
 *      `pg_stat_activity.wait_event_type = 'Lock'`; el actor lo observa antes de
 *      escribir, así que la prueba distingue un cerrojo real de una llegada
 *      tardía.
 *   3. El actor inserta su ausencia y confirma.
 *   4. El alta pendiente despierta, comprueba el solapamiento ya confirmado y
 *      recibe `409 ABSENCE_OVERLAP` sin insertar.
 *   5. Queda exactamente una fila en `technician_absences`.
 *
 * CATEGORÍA: `new_engine` (requiere PostgreSQL real; no se omite en silencio).
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/TechnicianAbsenceConcurrencyTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/** Marca de categoría: `run_all.php` la lee para no confundirla con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

const ABSENCE_CONCURRENCY_SCHEMA = 't_ash_absence_concurrency';
const ABSENCE_CONCURRENCY_PARENT_APP = 'vendguard-absence-concurrency-parent';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ASH-02 · Concurrencia real del alta de ausencias\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
// El actor identifica esta sesión por `application_name`, no por el PID del
// proceso: `pg_stat_activity.pid` es el backend del servidor, no el cliente.
$pgPdo->exec("SET application_name TO '" . ABSENCE_CONCURRENCY_PARENT_APP . "'");

$readyFile = '';
$statusFile = '';
$process = null;
$pipes = [];
$crash = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo, ABSENCE_CONCURRENCY_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $insertUser = $pgPdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, 'hash123', :role, TRUE)
         RETURNING id"
    );

    $insertUser->execute([
        ':name' => 'Técnico Concurrencia ASH',
        ':email' => 'tech.concurrencia.ash@vending.test',
        ':email_key' => 'tech.concurrencia.ash@vending.test',
        ':role' => 'TECHNICIAN',
    ]);
    $technicianId = (int)$insertUser->fetchColumn();

    $insertUser->execute([
        ':name' => 'Coordinador Concurrencia ASH',
        ':email' => 'coord.concurrencia.ash@vending.test',
        ':email_key' => 'coord.concurrencia.ash@vending.test',
        ':role' => 'COORDINATOR',
    ]);
    $coordinatorId = (int)$insertUser->fetchColumn();

    $startsAt = '2026-12-10T09:00:00+00:00';
    $endsAt = '2026-12-10T17:00:00+00:00';

    $readyFile = (string)tempnam(sys_get_temp_dir(), 'vendguard_absence_ready_');
    $statusFile = (string)tempnam(sys_get_temp_dir(), 'vendguard_absence_status_');
    file_put_contents($readyFile, '');

    $process = proc_open(
        [
            PHP_BINARY,
            __DIR__ . '/../Support/TechnicianAbsenceConcurrencyActor.php',
            ABSENCE_CONCURRENCY_SCHEMA,
            (string)$technicianId,
            (string)$coordinatorId,
            $startsAt,
            $endsAt,
            ABSENCE_CONCURRENCY_PARENT_APP,
            $readyFile,
            $statusFile,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo abrir el proceso actor de concurrencia.');
    }

    fclose($pipes[0]);

    $readyDeadline = microtime(true) + 5.0;
    while (trim((string)file_get_contents($readyFile)) !== 'locked' && microtime(true) < $readyDeadline) {
        usleep(20_000);
    }

    if (trim((string)file_get_contents($readyFile)) !== 'locked') {
        throw new RuntimeException('El actor concurrente no llegó a bloquear la fila del técnico.');
    }

    $absenceRepo = new PdoTechnicianAbsenceRepository($pgPdo);
    $userRepo = new PdoUserRepository($pgPdo);
    $service = new TechnicianAbsenceService(
        absenceRepo: $absenceRepo,
        userRepo: $userRepo,
        incidentRepo: new PdoIncidentRepository($pgPdo),
        auditRepo: new PdoAuditLogRepository($pgPdo),
        transactions: new PdoTransactionManager($pgPdo),
        lockRepo: $userRepo
    );

    $errorCode = null;
    $httpStatus = null;
    try {
        $service->createAbsence(
            technicianId: $technicianId,
            reason: 'VACATION',
            startsAt: $startsAt,
            endsAt: $endsAt,
            notes: 'Alta concurrente de la prueba de serialización',
            createdBy: $coordinatorId
        );
    } catch (InvalidAbsenceException $exception) {
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
    $rows = (int)$pgPdo->query('SELECT COUNT(*) FROM technician_absences')->fetchColumn();
    $survivor = $pgPdo->query(
        'SELECT notes, created_by FROM technician_absences ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    $assert(
        'El actor observó la transacción del alta esperando el cerrojo FOR UPDATE',
        str_starts_with($actorStatus, 'observed:'),
        'estado del actor: ' . ($actorStatus !== '' ? $actorStatus : '(sin fichero)')
    );
    $assert('El actor concurrente terminó sin error', $childExitCode === 0, 'exit=' . $childExitCode);
    $assert(
        'El alta pendiente recibe 409 ABSENCE_OVERLAP tras el COMMIT ajeno',
        $errorCode === 'ABSENCE_OVERLAP' && $httpStatus === 409,
        'código=' . var_export($errorCode, true) . ' http=' . var_export($httpStatus, true)
    );
    $assert(
        'La carrera deja exactamente una ausencia, la del actor',
        $rows === 1,
        'filas=' . $rows
    );
    $survivorIsActor = is_array($survivor)
        && $survivor['notes'] === 'Ausencia del actor concurrente'
        && (int)$survivor['created_by'] === $coordinatorId;
    $assert(
        'La ausencia superviviente es la que confirmó el actor',
        $survivorIsActor,
        is_array($survivor) ? (string)$survivor['notes'] : 'sin fila'
    );
} catch (Throwable $exception) {
    $crash = $exception;
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

    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, ABSENCE_CONCURRENCY_SCHEMA);
    } catch (Throwable) {
        // Preserva el crash original si existía.
    }
}

if ($crash !== null) {
    echo "\n[ERROR INESPERADO EN LA SUITE]\n";
    echo $crash->getMessage() . "\n";
    echo $crash->getTraceAsString() . "\n";
    $failures++;
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
