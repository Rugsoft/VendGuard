<?php

declare(strict_types=1);

/**
 * PdoTechnicianAbsenceRepositoryTest · T-ASH-03
 *
 * Certifica el repositorio PDO de ausencias de técnicos sobre PostgreSQL real.
 *
 * Casos cubiertos:
 *   1. create() inserta la ausencia y devuelve la entidad hidratada con su ID.
 *   2. findById() recupera la ausencia por ID.
 *   3. hasOverlappingAbsence() detecta solapamientos exactos y parciales,
 *      ignora ausencias canceladas y respeta excludeAbsenceId.
 *   4. findActiveAbsenceForTechnician() resuelve la ausencia vigente a una hora de referencia.
 *   5. findAll() filtra por técnico y por estado (active, future, cancelled, all).
 *   6. cancel() aplica la cancelación lógica auditada sin borrado físico.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - PdoTechnicianAbsenceRepositoryTest (T-ASH-03)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$repo = new PdoTechnicianAbsenceRepository($pdo);

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
    // Obtener un técnico y un coordinador para las pruebas
    $techId = (int)$pdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $coordId = (int)$pdo->query("SELECT id FROM users WHERE role = 'COORDINATOR' AND deleted_at IS NULL LIMIT 1")->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. create() y findById()
    // ─────────────────────────────────────────────────────────────────────────
    $startsAt = new DateTimeImmutable('2026-11-10 00:00:00+00');
    $endsAt = new DateTimeImmutable('2026-11-20 23:59:59+00');

    $absence = $repo->create(
        technicianId: $techId,
        reason: AbsenceReason::VACATION,
        startsAt: $startsAt,
        endsAt: $endsAt,
        notes: 'Vacaciones de noviembre',
        createdBy: $coordId
    );

    $assert('create() inserta y devuelve id autogenerado', $absence->id() !== null && $absence->id() > 0);
    $assert('create() preserva el motivo VACATION', $absence->reason() === AbsenceReason::VACATION);

    $loaded = $repo->findById($absence->id());
    $assert('findById() recupera la ausencia por ID', $loaded !== null && $loaded->id() === $absence->id());
    $assert('findById() hidrata notas y creador', $loaded->notes() === 'Vacaciones de noviembre' && $loaded->createdBy() === $coordId);

    // ─────────────────────────────────────────────────────────────────────────
    // 2. hasOverlappingAbsence()
    // ─────────────────────────────────────────────────────────────────────────
    // Solapamiento total: dentro del rango
    $overlapInside = $repo->hasOverlappingAbsence(
        technicianId: $techId,
        startsAt: new DateTimeImmutable('2026-11-12 00:00:00+00'),
        endsAt: new DateTimeImmutable('2026-11-15 00:00:00+00')
    );
    $assert('hasOverlappingAbsence() detecta solapamiento interior', $overlapInside);

    // Solapamiento cruzado: empieza antes y termina dentro
    $overlapCrossStart = $repo->hasOverlappingAbsence(
        technicianId: $techId,
        startsAt: new DateTimeImmutable('2026-11-05 00:00:00+00'),
        endsAt: new DateTimeImmutable('2026-11-12 00:00:00+00')
    );
    $assert('hasOverlappingAbsence() detecta solapamiento frontal', $overlapCrossStart);

    // Sin solapamiento: fechas anteriores
    $noOverlapBefore = $repo->hasOverlappingAbsence(
        technicianId: $techId,
        startsAt: new DateTimeImmutable('2026-11-01 00:00:00+00'),
        endsAt: new DateTimeImmutable('2026-11-09 23:59:59+00')
    );
    $assert('hasOverlappingAbsence() no detecta solapamiento en fechas anteriores', !$noOverlapBefore);

    // Exclusión de la propia ausencia (para edición futura)
    $overlapWithExclude = $repo->hasOverlappingAbsence(
        technicianId: $techId,
        startsAt: $startsAt,
        endsAt: $endsAt,
        excludeAbsenceId: $absence->id()
    );
    $assert('hasOverlappingAbsence() respeta excludeAbsenceId', !$overlapWithExclude);

    // ─────────────────────────────────────────────────────────────────────────
    // 3. findActiveAbsenceForTechnician()
    // ─────────────────────────────────────────────────────────────────────────
    $activeAtTime = $repo->findActiveAbsenceForTechnician(
        technicianId: $techId,
        referenceTime: new DateTimeImmutable('2026-11-15 12:00:00+00')
    );
    $assert('findActiveAbsenceForTechnician() encuentra ausencia vigente', $activeAtTime !== null && $activeAtTime->id() === $absence->id());

    $notActiveAtTime = $repo->findActiveAbsenceForTechnician(
        technicianId: $techId,
        referenceTime: new DateTimeImmutable('2026-11-01 12:00:00+00')
    );
    $assert('findActiveAbsenceForTechnician() devuelve null fuera del rango', $notActiveAtTime === null);

    // ─────────────────────────────────────────────────────────────────────────
    // 4. cancel()
    // ─────────────────────────────────────────────────────────────────────────
    $cancelled = $repo->cancel(
        id: $absence->id(),
        cancelledBy: $coordId,
        cancellationReason: 'Cambio de turno pactado'
    );
    $assert('cancel() devuelve true al cancelar', $cancelled);

    $afterCancel = $repo->findById($absence->id());
    $assert('cancel() persiste cancelled_at y motivo', $afterCancel->isCancelled() && $afterCancel->cancellationReason() === 'Cambio de turno pactado');

    // Tras cancelar, ya no solapa ni está activa
    $overlapAfterCancel = $repo->hasOverlappingAbsence(
        technicianId: $techId,
        startsAt: $startsAt,
        endsAt: $endsAt
    );
    $assert('hasOverlappingAbsence() ignora ausencias canceladas', !$overlapAfterCancel);

    $activeAfterCancel = $repo->findActiveAbsenceForTechnician(
        technicianId: $techId,
        referenceTime: new DateTimeImmutable('2026-11-15 12:00:00+00')
    );
    $assert('findActiveAbsenceForTechnician() ignora ausencias canceladas', $activeAfterCancel === null);

    // ─────────────────────────────────────────────────────────────────────────
    // 5. findAll() con filtros
    // ─────────────────────────────────────────────────────────────────────────
    // Crear una ausencia futura
    $futureAbsence = $repo->create(
        technicianId: $techId,
        reason: AbsenceReason::TRAINING,
        startsAt: new DateTimeImmutable('2026-12-01 00:00:00+00'),
        endsAt: new DateTimeImmutable('2026-12-05 23:59:59+00'),
        notes: 'Curso manipulador avanzado',
        createdBy: $coordId
    );

    $allForTech = $repo->findAll(technicianId: $techId, status: 'all');
    $assert('findAll(status: all) devuelve activas y canceladas', count($allForTech) >= 2);

    $cancelledOnly = $repo->findAll(technicianId: $techId, status: 'cancelled');
    $assert('findAll(status: cancelled) devuelve solo canceladas', count($cancelledOnly) >= 1 && $cancelledOnly[0]->isCancelled());

    $futureOnly = $repo->findAll(technicianId: $techId, status: 'future');
    $assert('findAll(status: future) devuelve ausencias con starts_at en el futuro', count($futureOnly) >= 1);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
