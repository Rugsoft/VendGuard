<?php

declare(strict_types=1);

/**
 * TechnicianShiftControllerErrorTest · RF-03.6 / H6
 *
 * Certifica que `start`, `pause-meal` y `resume` no filtran detalles internos:
 *
 *   1. Un `PDOException` inesperado responde 500 con el código específico
 *      (`SHIFT_START_FAILED`, `SHIFT_PAUSE_FAILED`, `SHIFT_RESUME_FAILED`) y un
 *      mensaje genérico, sin SQL, sin tabla, sin `SQLSTATE` ni el mensaje original.
 *   2. `InvalidShiftTransitionException` conserva su código y su estado (409/422).
 *   3. `UserNotFoundException` conserva el 404 `TECHNICIAN_NOT_FOUND`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Presentation\Controller\TechnicianShiftController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Integración - TechnicianShiftControllerErrorTest (RF-03.6 / H6)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$shift = new TechnicianShift(
    id: 77,
    technicianId: 5,
    status: ShiftStatus::ON_SHIFT,
    startedAt: new DateTimeImmutable('2026-10-23 08:00:00+00')
);

$shiftRepo = new class($shift) implements TechnicianShiftRepositoryInterface {
    /** @var 'ok'|'pdo'|'none' */
    public string $mode = 'ok';

    public string $failMethod = '';

    public function __construct(private readonly TechnicianShift $shift) {}

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        if ($this->mode === 'pdo' && $this->failMethod === 'find') {
            throw new PDOException(
                'SQLSTATE[42S02]: Base table or view not found: SELECT * FROM technician_shifts WHERE technician_id = 5'
            );
        }

        return $this->mode === 'none' ? null : $this->shift;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        throw new PDOException('SQLSTATE[42S02]: SELECT * FROM technician_shifts');
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        throw new PDOException('SQLSTATE[42S02]: UPDATE technician_shifts SET status = MEAL_BREAK');
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        throw new PDOException('SQLSTATE[42S02]: UPDATE technician_shifts SET status = ON_SHIFT');
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift
    {
        throw new LogicException('unused');
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return null;
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        throw new LogicException('unused');
    }

    public function findOverlappingShiftId(int $technicianId, DateTimeImmutable $startedAt, DateTimeImmutable $closedAt, ?int $excludeShiftId = null): ?int
    {
        return null;
    }

    public function findShiftsByDateRange(string $fromDate, string $toDate, ?int $technicianId = null): array
    {
        return [];
    }

    public function findOrphanOpenShiftsBefore(string $beforeDate, ?int $technicianId = null): array
    {
        return [];
    }

    public function getClosedInterventionMetricsByTechnician(DateTimeImmutable $from, DateTimeImmutable $to, ?int $technicianId = null): array
    {
        return [];
    }

    public function getActiveShiftsMap(): array
    {
        return [];
    }
};

$userRepo = new class implements UserRepositoryInterface {
    public bool $exists = true;

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        if (!$this->exists || $id !== 5) {
            return null;
        }

        return new User(
            id: 5,
            name: 'Técnico Móvil',
            email: 'tech@vending.test',
            passwordHash: 'hash',
            role: UserRole::TECHNICIAN,
            isActive: true
        );
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function create(array $data): User { throw new LogicException('unused'); }
    public function update(int $id, array $data): bool { return false; }
    public function updatePassword(int $id, string $newPassword): bool { return false; }
    public function resetPassword(int $id, string $newPassword): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function countActiveByRole(UserRole|string $role): int { return 1; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
};

$absenceRepo = new class implements TechnicianAbsenceRepositoryInterface {
    public function create(int $technicianId, AbsenceReason $reason, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?string $notes, int $createdBy): VendGuard\Core\Domain\Model\TechnicianAbsence { throw new LogicException('unused'); }
    public function findById(int $id): ?VendGuard\Core\Domain\Model\TechnicianAbsence { return null; }
    public function findAll(?int $technicianId = null, ?string $status = null): array { return []; }
    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?VendGuard\Core\Domain\Model\TechnicianAbsence { return null; }
    public function findActiveAbsencesMap(DateTimeImmutable $at): array { return []; }
    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool { return false; }
    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool { return false; }
};

$auditRepo = new class implements AuditLogRepositoryInterface {
    public function log(VendGuard\Core\Domain\Model\AuditEvent $event): VendGuard\Core\Domain\Model\AuditEvent { return $event; }
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countEvents(array $filters = []): int { return 0; }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
};

$transactions = new class implements TransactionManagerInterface {
    public function runInTransaction(callable $operation): mixed { return $operation(); }
};

$lockRepo = new class implements TechnicianLockRepositoryInterface {
    public function lockTechnicianForUpdate(int $technicianId): bool { return true; }
};

$service = new ShiftLifecycleService(
    shiftRepo: $shiftRepo,
    absenceRepo: $absenceRepo,
    userRepo: $userRepo,
    auditRepo: $auditRepo,
    transactions: $transactions,
    lockRepo: $lockRepo
);

$controller = new TechnicianShiftController(
    lifecycleService: $service,
    pdo: $pdo,
    userRepo: $userRepo
);

$request = static function (string $path, int $userId = 5): Request {
    return (new Request(method: 'POST', path: $path))->setAttribute('user_id', $userId);
};

$responseCode = static function ($response): string {
    return (string)($response->getDecodedBody()['error']['code'] ?? '');
};

$leaks = ['SQLSTATE', 'SELECT', 'UPDATE technician_shifts', 'technician_shifts', 'Base table or view not found'];

// ─────────────────────────────────────────────────────────────────────────
// 1. start con PDOException
// ─────────────────────────────────────────────────────────────────────────
$shiftRepo->mode = 'pdo';
$shiftRepo->failMethod = 'find';
$start = $controller->start($request('/api/technician/shift/start'));
$startBody = json_encode($start->getDecodedBody(), JSON_UNESCAPED_UNICODE);
$startLeaks = array_values(array_filter($leaks, static fn (string $needle): bool => str_contains((string)$startBody, $needle)));

$assert('start con PDOException => HTTP 500', $start->getStatusCode() === 500, 'http=' . $start->getStatusCode());
$assert('start devuelve el código SHIFT_START_FAILED', $responseCode($start) === 'SHIFT_START_FAILED', 'code=' . $responseCode($start));
$assert('start no filtra SQL, tablas ni el mensaje original', $startLeaks === [], 'filtrado: ' . implode(',', $startLeaks));

// ─────────────────────────────────────────────────────────────────────────
// 2. pauseMeal con PDOException
// ─────────────────────────────────────────────────────────────────────────
$shiftRepo->mode = 'ok';
$pause = $controller->pauseMeal($request('/api/technician/shift/pause-meal'));
$pauseBody = json_encode($pause->getDecodedBody(), JSON_UNESCAPED_UNICODE);
$pauseLeaks = array_values(array_filter($leaks, static fn (string $needle): bool => str_contains((string)$pauseBody, $needle)));

$assert('pauseMeal con PDOException => HTTP 500', $pause->getStatusCode() === 500, 'http=' . $pause->getStatusCode());
$assert('pauseMeal devuelve el código SHIFT_PAUSE_FAILED', $responseCode($pause) === 'SHIFT_PAUSE_FAILED', 'code=' . $responseCode($pause));
$assert('pauseMeal no filtra SQL, tablas ni el mensaje original', $pauseLeaks === [], 'filtrado: ' . implode(',', $pauseLeaks));

// ─────────────────────────────────────────────────────────────────────────
// 3. resume con PDOException
// ─────────────────────────────────────────────────────────────────────────
$resume = $controller->resume($request('/api/technician/shift/resume'));
$resumeBody = json_encode($resume->getDecodedBody(), JSON_UNESCAPED_UNICODE);
$resumeLeaks = array_values(array_filter($leaks, static fn (string $needle): bool => str_contains((string)$resumeBody, $needle)));

$assert('resume con PDOException => HTTP 500', $resume->getStatusCode() === 500, 'http=' . $resume->getStatusCode());
$assert('resume devuelve el código SHIFT_RESUME_FAILED', $responseCode($resume) === 'SHIFT_RESUME_FAILED', 'code=' . $responseCode($resume));
$assert('resume no filtra SQL, tablas ni el mensaje original', $resumeLeaks === [], 'filtrado: ' . implode(',', $resumeLeaks));

// ─────────────────────────────────────────────────────────────────────────
// 4. InvalidShiftTransitionException conserva su contrato
// ─────────────────────────────────────────────────────────────────────────
$shiftRepo->mode = 'none';
$noShift = $controller->pauseMeal($request('/api/technician/shift/pause-meal'));
$assert(
    'pauseMeal sin turno abierto => 409 NO_OPEN_SHIFT',
    $noShift->getStatusCode() === 409 && $responseCode($noShift) === 'NO_OPEN_SHIFT',
    'http=' . $noShift->getStatusCode() . ' code=' . $responseCode($noShift)
);

// ─────────────────────────────────────────────────────────────────────────
// 5. UserNotFoundException conserva el 404
// ─────────────────────────────────────────────────────────────────────────
$userRepo->exists = false;
$shiftRepo->mode = 'ok';
$missing = $controller->start($request('/api/technician/shift/start'));
$assert(
    'start con técnico inexistente => 404 TECHNICIAN_NOT_FOUND',
    $missing->getStatusCode() === 404 && $responseCode($missing) === 'TECHNICIAN_NOT_FOUND',
    'http=' . $missing->getStatusCode() . ' code=' . $responseCode($missing)
);

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
