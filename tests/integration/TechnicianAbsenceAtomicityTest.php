<?php

declare(strict_types=1);

/**
 * TechnicianAbsenceAtomicityTest · RF-01.6 / H5
 *
 * Certifica que el alta y la cancelación de ausencias escriben su fila y su
 * evento de auditoría en la misma transacción (Art. III.3):
 *
 *   1. Con una auditoría que falla, el alta no deja ninguna fila en
 *      `technician_absences`.
 *   2. Con una auditoría que falla, la cancelación no marca `cancelled_at`.
 *
 * La prueba usa la conexión real del arnés y el `PdoTransactionManager` real;
 * solo se sustituye el repositorio de auditoría por un decorador que lanza.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/ThrowingAuditLogRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - TechnicianAbsenceAtomicityTest (RF-01.6 / H5)\n";
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

$buildService = static function (PDO $pdo, \VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface $audit): TechnicianAbsenceService {
    $users = new PdoUserRepository($pdo);

    return new TechnicianAbsenceService(
        absenceRepo: new PdoTechnicianAbsenceRepository($pdo),
        userRepo: $users,
        incidentRepo: new PdoIncidentRepository($pdo),
        auditRepo: $audit,
        transactions: new PdoTransactionManager($pdo),
        lockRepo: $users
    );
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
    // 1. Alta con auditoría fallida: rollback completo
    // ─────────────────────────────────────────────────────────────────────
    $failingAudit = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 1);
    $service = $buildService($pdo, $failingAudit);

    $startsAt = '2031-03-10T09:00:00+00:00';
    $endsAt = '2031-03-10T17:00:00+00:00';
    $failure = null;
    try {
        $service->createAbsence(
            technicianId: $technicianId,
            reason: 'VACATION',
            startsAt: $startsAt,
            endsAt: $endsAt,
            notes: 'Alta que debe deshacerse por fallo de auditoría',
            createdBy: $coordinatorId
        );
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    $survivingRows = (int)$pdo->query(
        "SELECT COUNT(*) FROM technician_absences
          WHERE technician_id = {$technicianId}
            AND starts_at = '2031-03-10 09:00:00+00'
            AND ends_at = '2031-03-10 17:00:00+00'"
    )->fetchColumn();

    $assert(
        'El alta lanza el fallo de auditoría simulado',
        $failure !== null && str_contains($failure->getMessage(), 'Fallo de auditoría simulado'),
        $failure?->getMessage() ?? 'no se lanzó'
    );
    $assert('La auditoría fallida invoca exactamente una escritura', $failingAudit->calls === 1, 'llamadas=' . $failingAudit->calls);
    $assert('La transacción deshace el INSERT de la ausencia', $survivingRows === 0, 'filas=' . $survivingRows);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cancelación con auditoría fallida: cancelled_at sigue NULL
    // ─────────────────────────────────────────────────────────────────────
    $workingAudit = new PdoAuditLogRepository($pdo);
    $workingService = $buildService($pdo, $workingAudit);
    $created = $workingService->createAbsence(
        technicianId: $technicianId,
        reason: 'TRAINING',
        startsAt: '2031-04-14T09:00:00+00:00',
        endsAt: '2031-04-14T17:00:00+00:00',
        notes: 'Ausencia que se cancelará con auditoría fallida',
        createdBy: $coordinatorId
    );
    $absenceId = (int)$created['absence']->id;

    $failingCancelAudit = new ThrowingAuditLogRepository(new PdoAuditLogRepository($pdo), 1);
    $cancelService = $buildService($pdo, $failingCancelAudit);
    $cancelFailure = null;
    try {
        $cancelService->cancelAbsence(
            $absenceId,
            $coordinatorId,
            'Cancelación que debe deshacerse por auditoría'
        );
    } catch (RuntimeException $exception) {
        $cancelFailure = $exception;
    }

    $cancelledAt = $pdo->query(
        "SELECT cancelled_at FROM technician_absences WHERE id = {$absenceId}"
    )->fetchColumn();

    $assert(
        'La cancelación lanza el fallo de auditoría simulado',
        $cancelFailure !== null && str_contains($cancelFailure->getMessage(), 'Fallo de auditoría simulado'),
        $cancelFailure?->getMessage() ?? 'no se lanzó'
    );
    $assert(
        'La transacción deshace el UPDATE: la ausencia sigue viva',
        $cancelledAt === null || $cancelledAt === false,
        'cancelled_at=' . var_export($cancelledAt, true)
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
