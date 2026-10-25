<?php

declare(strict_types=1);

/**
 * ShiftLifecycleAtomicityTest · RF-03.6 / H5
 *
 * Certifica que inicio, pausa y reanudación de turno escriben su fila y su
 * evento de auditoría en la misma transacción (Art. III.3):
 *
 *   1. Inicio con auditoría fallida: no queda turno abierto.
 *   2. Pausa con auditoría fallida: el turno sigue en `ON_SHIFT`.
 *   3. Reanudación con auditoría fallida: el turno sigue en `MEAL_BREAK`.
 *   4. Inicio durante una ausencia con la segunda auditoría fallida: se deshace
 *      también el aviso `ABSENCE_OVERRIDE_BY_SHIFT_START`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/ThrowingAuditLogRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - ShiftLifecycleAtomicityTest (RF-03.6 / H5)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$buildService = static function (PDO $pdo, AuditLogRepositoryInterface $audit): ShiftLifecycleService {
    $users = new PdoUserRepository($pdo);

    return new ShiftLifecycleService(
        shiftRepo: new PdoTechnicianShiftRepository($pdo),
        absenceRepo: new PdoTechnicianAbsenceRepository($pdo),
        userRepo: $users,
        auditRepo: $audit,
        transactions: new PdoTransactionManager($pdo),
        lockRepo: $users
    );
};

$openShift = static function (PDO $pdo, int $technicianId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, status FROM technician_shifts
          WHERE technician_id = :technician_id AND closed_at IS NULL
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':technician_id' => $technicianId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
};

try {
    $technician = $pdo->query(
        "SELECT id FROM users WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $coordinator = $pdo->query(
        "SELECT id FROM users WHERE role = 'COORDINATOR' AND is_active = TRUE AND deleted_at IS NULL ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($technician) || !is_array($coordinator)) {
        throw new RuntimeException('La prueba necesita un técnico y un coordinador sembrados.');
    }

    $technicianId = (int)$technician['id'];
    $coordinatorId = (int)$coordinator['id'];

    // ─────────────────────────────────────────────────────────────────────
    // 1. Inicio con auditoría fallida
    // ─────────────────────────────────────────────────────────────────────
    $failingStart = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 1);
    $startFailure = null;
    try {
        $buildService($pdo, $failingStart)->startShift($technicianId);
    } catch (RuntimeException $exception) {
        $startFailure = $exception;
    }

    $assert(
        'El inicio lanza el fallo de auditoría simulado',
        $startFailure !== null && str_contains($startFailure->getMessage(), 'Fallo de auditoría simulado'),
        $startFailure?->getMessage() ?? 'no se lanzó'
    );
    $assert(
        'La transacción deshace el INSERT del turno',
        $openShift($pdo, $technicianId) === null,
        'turno=' . json_encode($openShift($pdo, $technicianId), JSON_UNESCAPED_UNICODE)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Pausa con auditoría fallida: el turno sigue en ON_SHIFT
    // ─────────────────────────────────────────────────────────────────────
    $workingAudit = new PdoAuditLogRepository($pdo);
    $workingService = $buildService($pdo, $workingAudit);
    $workingService->startShift($technicianId);

    $failingPause = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 1);
    $pauseFailure = null;
    try {
        $buildService($pdo, $failingPause)->pauseMeal($technicianId);
    } catch (RuntimeException $exception) {
        $pauseFailure = $exception;
    }

    $afterPause = $openShift($pdo, $technicianId);
    $assert(
        'La pausa lanza el fallo de auditoría simulado',
        $pauseFailure !== null && str_contains($pauseFailure->getMessage(), 'Fallo de auditoría simulado'),
        $pauseFailure?->getMessage() ?? 'no se lanzó'
    );
    $assert(
        'La transacción deshace el UPDATE: el turno sigue en ON_SHIFT',
        is_array($afterPause) && $afterPause['status'] === 'ON_SHIFT',
        'estado=' . var_export($afterPause['status'] ?? null, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Reanudación con auditoría fallida: el turno sigue en MEAL_BREAK
    // ─────────────────────────────────────────────────────────────────────
    $workingService->pauseMeal($technicianId);

    $failingResume = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 1);
    $resumeFailure = null;
    try {
        $buildService($pdo, $failingResume)->resumeShift($technicianId);
    } catch (RuntimeException $exception) {
        $resumeFailure = $exception;
    }

    $afterResume = $openShift($pdo, $technicianId);
    $assert(
        'La reanudación lanza el fallo de auditoría simulado',
        $resumeFailure !== null && str_contains($resumeFailure->getMessage(), 'Fallo de auditoría simulado'),
        $resumeFailure?->getMessage() ?? 'no se lanzó'
    );
    $assert(
        'La transacción deshace el UPDATE: el turno sigue en MEAL_BREAK',
        is_array($afterResume) && $afterResume['status'] === 'MEAL_BREAK',
        'estado=' . var_export($afterResume['status'] ?? null, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Override por ausencia con la segunda auditoría fallida
    // ─────────────────────────────────────────────────────────────────────
    (new PdoTechnicianShiftRepository($pdo))->closeShift(
        shiftId: (int)$afterResume['id'],
        closedBy: $technicianId,
        closeReason: 'Cierre preparatorio de la prueba de atomicidad'
    );

    $insertAbsence = $pdo->prepare(
        "INSERT INTO technician_absences
            (technician_id, reason, starts_at, ends_at, notes, created_by)
         VALUES
            (:technician_id, 'SICK_LEAVE', NOW() - INTERVAL '1 hour', NOW() + INTERVAL '8 hours',
             'Ausencia para probar el override atómico', :created_by)"
    );
    $insertAbsence->execute([':technician_id' => $technicianId, ':created_by' => $coordinatorId]);

    $auditCountBefore = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE action = 'ABSENCE_OVERRIDE_BY_SHIFT_START'
            AND entity_id = {$technicianId}"
    )->fetchColumn();

    $failingOverride = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 2);
    $overrideFailure = null;
    try {
        $buildService($pdo, $failingOverride)->startShift($technicianId);
    } catch (RuntimeException $exception) {
        $overrideFailure = $exception;
    }

    $auditCountAfter = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE action = 'ABSENCE_OVERRIDE_BY_SHIFT_START'
            AND entity_id = {$technicianId}"
    )->fetchColumn();

    $assert(
        'El inicio durante ausencia intenta las dos auditorías y falla en la segunda',
        $overrideFailure !== null && $failingOverride->calls === 2,
        'llamadas=' . $failingOverride->calls
    );
    $assert(
        'La transacción deshace el aviso de override y no deja turno abierto',
        $openShift($pdo, $technicianId) === null && $auditCountAfter === $auditCountBefore,
        'turno=' . json_encode($openShift($pdo, $technicianId), JSON_UNESCAPED_UNICODE)
            . ' auditorías=' . $auditCountAfter . '/' . $auditCountBefore
    );
} catch (Throwable $exception) {
    $assert('La prueba de atomicidad se ejecuta sin excepción de infraestructura', false, $exception->getMessage());
    echo $exception->getTraceAsString() . "\n";
} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
