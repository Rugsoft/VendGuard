<?php

declare(strict_types=1);

/**
 * RestockerShiftAndWorkHoursTest · T-RES-09
 *
 * Certifica la integración del rol `RESTOCKER` en el ciclo de jornada, las
 * ausencias y el registro horario (Módulo 31, Fase 3):
 *
 *   1. Turnos (`D1`): el reponedor inicia, pausa, reanuda y cierra su jornada
 *      directamente —también desde `MEAL_BREAK`— sin relevo de averías SAT; el
 *      técnico conserva su cierre con relevo y no puede usar el atajo.
 *   2. Registro de jornada (`D2`, `QA 4`): filtro `ALL` | `TECHNICIAN` |
 *      `RESTOCKER`, regla `C5` por rol, intervenciones a cero del reponedor y
 *      columna `Rol` en las dos secciones del CSV.
 *   3. Ausencias (`RF-05.1`): alta para `RESTOCKER` sin asistente de tickets SAT
 *      e informe de zonas con promoción del `BACKUP`.
 *
 * CATEGORÍA: hermética (sin motor ni base de datos).
 *
 * Uso:
 *   php tests/unit/RestockerShiftAndWorkHoursTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, dobles en memoria, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Application\Service\TechnicianWorkHoursService;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expectCode = static function (string $what, string $expectedCode, callable $operation) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'la operación fue admitida');
    } catch (Throwable $exception) {
        $code = method_exists($exception, 'getErrorCode')
            ? $exception->getErrorCode()
            : get_class($exception);
        $assert($what, $code === $expectedCode, 'código=' . $code);
    }
};

echo "======================================================================\n";
echo " VendGuard · T-RES-09 · RestockerShiftAndWorkHours (Unit)\n";
echo "======================================================================\n";

$madrid = new DateTimeZone('Europe/Madrid');
$at = static fn (string $local): DateTimeImmutable => new DateTimeImmutable($local, $madrid);

// ─────────────────────────────────────────────────────────────────────────
// Dobles en memoria
// ─────────────────────────────────────────────────────────────────────────
final class RestockerShiftRepository implements TechnicianShiftRepositoryInterface
{
    /** @var array<int, TechnicianShift> */
    public array $storage = [];

    /** @var list<array{shift_id: int, closed_by: int, reason: string|null}> */
    public array $closeCalls = [];

    private int $autoId;

    /**
     * @param array<int, string> $roles
     * @param array<int, array{intervention_seconds: int, resolved_interventions_count: int}> $metrics
     * @param list<TechnicianShift> $shifts
     */
    public function __construct(
        public array $roles,
        public array $metrics = [],
        array $shifts = [],
        int $firstId = 100
    ) {
        $this->autoId = $firstId;
        foreach ($shifts as $shift) {
            $this->storage[(int)$shift->id()] = $shift;
        }
    }

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        foreach ($this->storage as $shift) {
            if ($shift->technicianId() === $technicianId && $shift->isOpen()) {
                return $shift;
            }
        }

        return null;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return $this->storage[$shiftId] ?? null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        $active = $this->findActiveShiftByTechnician($technicianId);
        if ($active !== null) {
            return $active;
        }

        $now = $startedAt ?? new DateTimeImmutable('now');
        $shift = new TechnicianShift(
            id: $this->autoId++,
            technicianId: $technicianId,
            status: ShiftStatus::ON_SHIFT,
            startedAt: $now,
            shiftDate: $now->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d'),
            originalStartedAt: $now
        );
        $this->storage[(int)$shift->id()] = $shift;

        return $shift;
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        $updated = $this->storage[$shiftId]->pauseMeal($pausedAt);
        $this->storage[$shiftId] = $updated;

        return $updated;
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        $updated = $this->storage[$shiftId]->resume($resumedAt);
        $this->storage[$shiftId] = $updated;

        return $updated;
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        $this->closeCalls[] = ['shift_id' => $shiftId, 'closed_by' => $closedBy, 'reason' => $closeReason];
        $updated = $this->storage[$shiftId]->close($closedBy, $closeReason, $closedAt, $isForceClosed, $executionTime);
        $this->storage[$shiftId] = $updated;

        return $updated;
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        $this->storage[(int)$adjustedShift->id()] = $adjustedShift;

        return $adjustedShift;
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        return null;
    }

    public function findShiftsByDateRange(
        string $fromDate,
        string $toDate,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return array_values(array_filter(
            $this->storage,
            function (TechnicianShift $shift) use ($technicianId, $role): bool {
                if ($technicianId !== null && $shift->technicianId() !== $technicianId) {
                    return false;
                }

                return $role === null || ($this->roles[$shift->technicianId()] ?? null) === $role;
            }
        ));
    }

    public function findOrphanOpenShiftsBefore(
        string $beforeDate,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return [];
    }

    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        $metrics = [];
        foreach ($this->metrics as $id => $values) {
            if ($technicianId !== null && $id !== $technicianId) {
                continue;
            }
            if ($role !== null && ($this->roles[$id] ?? null) !== $role) {
                continue;
            }
            $metrics[$id] = $values;
        }

        return $metrics;
    }

    public function getActiveShiftsMap(?string $role = null): array
    {
        $map = [];
        foreach ($this->storage as $shift) {
            if (!$shift->isOpen()) {
                continue;
            }
            if ($role !== null && ($this->roles[$shift->technicianId()] ?? null) !== $role) {
                continue;
            }
            $map[$shift->technicianId()] = $shift->status()->value;
        }

        return $map;
    }
}

final class RestockerUserRepository implements UserRepositoryInterface
{
    /** @param list<User> $users */
    public function __construct(public array $users)
    {
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        return null;
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->users,
            static fn (User $user): bool => $user->getRole() === UserRole::TECHNICIAN
        ));
    }

    public function create(array $data): User
    {
        throw new LogicException('unused');
    }

    public function update(int $id, array $data): bool
    {
        return false;
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return false;
    }

    public function restore(int $id): bool
    {
        return false;
    }

    public function findAll(array $filters = []): array
    {
        $role = isset($filters['role']) ? (string)$filters['role'] : null;

        $rows = [];
        foreach ($this->users as $user) {
            if ($role !== null && $user->getRole()->value !== $role) {
                continue;
            }
            $rows[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'role' => $user->getRole()->value,
                'is_active' => $user->isActive(),
                'operator_code' => null,
            ];
        }

        return $rows;
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        return 0;
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        return 0;
    }

    public function countPendingIncidents(int $technicianId): int
    {
        return 0;
    }
}

final class RestockerAbsenceRepository implements TechnicianAbsenceRepositoryInterface
{
    public int $nextId = 500;

    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence {
        return new TechnicianAbsence(
            id: $this->nextId++,
            technicianId: $technicianId,
            reason: $reason,
            startsAt: $startsAt,
            endsAt: $endsAt,
            notes: $notes,
            createdBy: $createdBy
        );
    }

    public function findById(int $id): ?TechnicianAbsence
    {
        return null;
    }

    public function findAll(?int $technicianId = null, ?string $status = null, ?string $role = null): array
    {
        return [];
    }

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence
    {
        return null;
    }

    public function findActiveAbsencesMap(DateTimeImmutable $at): array
    {
        return [];
    }

    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool
    {
        return false;
    }

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool
    {
        return true;
    }
}

final class RestockerAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function countEvents(array $filters = []): int
    {
        return 0;
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return [];
    }
}

final class RestockerShiftLogRepository implements TechnicianShiftLogRepositoryInterface
{
    /** @var list<TechnicianShiftLog> */
    public array $logs = [];

    public function record(
        int $shiftId,
        int $technicianId,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        array $newState,
        ?string $reason = null
    ): TechnicianShiftLog {
        $log = new TechnicianShiftLog(
            id: count($this->logs) + 1,
            shiftId: $shiftId,
            technicianId: $technicianId,
            eventType: $eventType,
            actorUserId: $actorUserId,
            effectiveAt: $effectiveAt,
            previousState: $previousState,
            newState: $newState,
            reason: $reason
        );
        $this->logs[] = $log;

        return $log;
    }

    public function findByShiftId(int $shiftId): array
    {
        return array_values(array_filter(
            $this->logs,
            static fn (TechnicianShiftLog $log): bool => $log->shiftId() === $shiftId
        ));
    }
}

final class RestockerTransactions implements TransactionManagerInterface
{
    public function runInTransaction(callable $operation): mixed
    {
        return $operation();
    }
}

final class RestockerLockRepository implements TechnicianLockRepositoryInterface
{
    public function lockTechnicianForUpdate(int $technicianId): bool
    {
        return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Sujetos de prueba
// ─────────────────────────────────────────────────────────────────────────
$coordinator = new User(1, 'Laura Coordinadora', 'coord@vending.test', 'hash', UserRole::COORDINATOR);
$technician = new User(5, 'Carlos Técnico', 'carlos@vending.test', 'hash', UserRole::TECHNICIAN);
$restocker = new User(7, 'Rubén Reponedor', 'ruben@vending.test', 'hash', UserRole::RESTOCKER);
$restockerInactive = new User(8, 'Iván Baja', 'ivan@vending.test', 'hash', UserRole::RESTOCKER, isActive: false);
$restockerInactiveIdle = new User(9, 'Inés Sin Ruta', 'ines@vending.test', 'hash', UserRole::RESTOCKER, isActive: false);
$restockerIdle = new User(10, 'Raquel Reponedora', 'raquel@vending.test', 'hash', UserRole::RESTOCKER);

$users = new RestockerUserRepository([
    $coordinator,
    $technician,
    $restocker,
    $restockerInactive,
    $restockerInactiveIdle,
    $restockerIdle,
]);

$roles = [
    1 => 'COORDINATOR',
    5 => 'TECHNICIAN',
    7 => 'RESTOCKER',
    8 => 'RESTOCKER',
    9 => 'RESTOCKER',
    10 => 'RESTOCKER',
];

$closedShift = static fn (int $id, int $technicianId, string $start, string $end, int $mealSeconds): TechnicianShift
    => new TechnicianShift(
        id: $id,
        technicianId: $technicianId,
        status: ShiftStatus::OFF_SHIFT,
        startedAt: $at($start),
        closedAt: $at($end),
        closedBy: $technicianId,
        shiftDate: substr($start, 0, 10),
        mealBreakSeconds: $mealSeconds,
        originalStartedAt: $at($start),
        originalClosedAt: $at($end),
        originalMealBreakSeconds: $mealSeconds
    );

// ─────────────────────────────────────────────────────────────────────────
// 1. Ciclo de jornada del reponedor (`D1`)
// ─────────────────────────────────────────────────────────────────────────
echo "\n1. Turnos del reponedor y cierre directo\n";

$lifecycleRepo = new RestockerShiftRepository($roles);
$lifecycleLogs = new RestockerShiftLogRepository();
$lifecycleAudit = new RestockerAuditRepository();
$absenceRepo = new RestockerAbsenceRepository();
$transactions = new RestockerTransactions();
$lockRepo = new RestockerLockRepository();

$lifecycle = new ShiftLifecycleService(
    shiftRepo: $lifecycleRepo,
    absenceRepo: $absenceRepo,
    userRepo: $users,
    auditRepo: $lifecycleAudit,
    transactions: $transactions,
    lockRepo: $lockRepo,
    shiftLogRepo: $lifecycleLogs
);

$started = $lifecycle->startShift(7);
$assert('El reponedor puede iniciar turno', $started->status === 'ON_SHIFT');
$assert(
    'El inicio de turno del reponedor se audita como START_TECHNICIAN_SHIFT',
    ($lifecycleAudit->events[0] ?? null)?->getAction() === 'START_TECHNICIAN_SHIFT'
);
$assert(
    'La bitácora registra START_SHIFT del reponedor',
    ($lifecycleLogs->logs[0] ?? null)?->eventType() === ShiftEventType::START_SHIFT
);

$paused = $lifecycle->pauseMeal(7);
$assert('El reponedor puede pausar para comer', $paused->status === 'MEAL_BREAK');

$closed = $lifecycle->closeShift(7);
$assert('El cierre directo desde MEAL_BREAK deja el turno cerrado', $closed->status === 'OFF_SHIFT');
$assert('El cierre directo se persiste a través del puerto', count($lifecycleRepo->closeCalls) === 1);
$assert(
    'El cierre directo aplica el motivo por defecto de reposición',
    ($lifecycleRepo->closeCalls[0]['reason'] ?? null) === 'Fin de jornada de reposición'
);
$assert(
    'La auditoría registra CLOSE_TECHNICIAN_SHIFT',
    ($lifecycleAudit->events[count($lifecycleAudit->events) - 1] ?? null)?->getAction() === 'CLOSE_TECHNICIAN_SHIFT'
);
$lastLog = $lifecycleLogs->logs[count($lifecycleLogs->logs) - 1] ?? null;
$assert(
    'La bitácora registra CLOSE_SHIFT con motivo',
    $lastLog !== null && $lastLog->eventType() === ShiftEventType::CLOSE_SHIFT && $lastLog->reason() !== null
);

$expectCode(
    'El cierre directo sin turno abierto se rechaza con NO_OPEN_SHIFT',
    'NO_OPEN_SHIFT',
    static fn () => $lifecycle->closeShift(7)
);

$lifecycle->startShift(5);
$expectCode(
    'El técnico SAT no puede usar el cierre directo sin relevo',
    'SHIFT_DIRECT_CLOSE_FORBIDDEN',
    static fn () => $lifecycle->closeShift(5)
);

try {
    $lifecycle->startShift(1);
    $assert('Un rol no operativo no puede iniciar turno', false, 'la operación fue admitida');
} catch (UserNotFoundException) {
    $assert('Un rol no operativo no puede iniciar turno', true);
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Registro de jornada con filtro por rol y CSV (`D2`, `QA 4`)
// ─────────────────────────────────────────────────────────────────────────
echo "\n2. Registro de jornada, filtro por rol y CSV\n";

$workHoursRepo = new RestockerShiftRepository(
    $roles,
    [
        5 => ['intervention_seconds' => 1800, 'resolved_interventions_count' => 2],
        7 => ['intervention_seconds' => 3600, 'resolved_interventions_count' => 4],
    ],
    [
        $closedShift(30, 7, '2026-11-03 08:00:00', '2026-11-03 16:00:00', 0),
        $closedShift(31, 8, '2026-11-04 08:00:00', '2026-11-04 10:00:00', 0),
        $closedShift(32, 5, '2026-11-05 08:00:00', '2026-11-05 12:00:00', 0),
    ]
);

$workHours = new TechnicianWorkHoursService(
    shiftRepo: $workHoursRepo,
    shiftLogRepo: $lifecycleLogs,
    userRepo: $users,
    transactions: $transactions,
    auditRepo: $lifecycleAudit,
    lockRepo: $lockRepo
);

$filter = new MetricFilter(
    MetricFilter::PERIOD_CUSTOM,
    $at('2026-11-01 00:00:00'),
    $at('2026-11-30 23:59:59')
);

$all = $workHours->getWorkHoursReport($filter);
$allRows = [];
foreach ($all->byTechnician() as $row) {
    $allRows[$row['technician_id']] = $row;
}

$assert('El informe ALL publica el filtro de rol', $all->filter()['role'] === 'ALL');
$assert('El informe ALL incluye técnicos y reponedores', isset($allRows[5], $allRows[7]));
$assert('La regla C5 lista al reponedor activo sin sesiones', isset($allRows[10]) && $allRows[10]['sessions_count'] === 0);
$assert('La regla C5 lista al reponedor inactivo con actividad', isset($allRows[8]) && $allRows[8]['sessions_count'] === 1);
$assert('La regla C5 excluye al inactivo sin actividad', !isset($allRows[9]));
$assert('La fila del reponedor declara su rol', ($allRows[7]['technician_role'] ?? null) === 'RESTOCKER');
$assert(
    'El reponedor computa 0 intervenciones y 0 resoluciones',
    $allRows[7]['intervention_seconds'] === 0
        && $allRows[7]['resolved_interventions_count'] === 0
        && $allRows[7]['intervention_formatted'] === '00h 00m'
);
$assert(
    'El tiempo en ruta del reponedor absorbe la jornada efectiva',
    $allRows[7]['route_seconds'] === $allRows[7]['effective_seconds']
);
$assert('El técnico conserva sus intervenciones', $allRows[5]['intervention_seconds'] === 1800);

$restockerReport = $workHours->getWorkHoursReport($filter, null, 'RESTOCKER');
$restockerRows = array_column($restockerReport->byTechnician(), null, 'technician_id');
$assert('El informe RESTOCKER publica su filtro de rol', $restockerReport->filter()['role'] === 'RESTOCKER');
$assert('El informe RESTOCKER no incluye técnicos SAT', !isset($restockerRows[5]));
$assert('El informe RESTOCKER acota las sesiones al rol', count($restockerReport->sessions()) === 2);
$sessionRoles = array_unique(array_column($restockerReport->sessions(), 'technician_role'));
$assert('Las sesiones publican el rol del operario', $sessionRoles === ['RESTOCKER']);

$technicianReport = $workHours->getWorkHoursReport($filter, null, 'TECHNICIAN');
$assert(
    'El informe TECHNICIAN solo publica técnicos',
    count($technicianReport->byTechnician()) === 1
        && $technicianReport->byTechnician()[0]['technician_id'] === 5
);

try {
    $workHours->getWorkHoursReport($filter, null, 'CARPENTER');
    $assert('Un rol fuera del catálogo se rechaza', false, 'la operación fue admitida');
} catch (InvalidArgumentException $exception) {
    $assert('Un rol fuera del catálogo se rechaza', str_contains($exception->getMessage(), 'Rol no válido'));
}

$csv = $workHours->exportWorkHoursCsv($filter, null, 'RESTOCKER');
$assert('El CSV arranca con UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));

$csvHandle = fopen('php://temp', 'r+');
fwrite($csvHandle, substr($csv, 3));
rewind($csvHandle);
$csvRows = [];
while (($csvRow = fgetcsv($csvHandle, 0, ';')) !== false) {
    $csvRows[] = $csvRow;
}
fclose($csvHandle);

$assert(
    'La cabecera del resumen incluye la columna Rol',
    ($csvRows[0][0] ?? null) === 'Técnico' && ($csvRows[0][1] ?? null) === 'Rol'
);

$detailIndex = null;
foreach ($csvRows as $index => $csvRow) {
    if (($csvRow[0] ?? null) === 'ID Sesión') {
        $detailIndex = $index;
        break;
    }
}

$assert(
    'La cabecera del detalle incluye la columna Rol tras el operario',
    $detailIndex !== null && ($csvRows[$detailIndex][2] ?? null) === 'Técnico' && ($csvRows[$detailIndex][3] ?? null) === 'Rol'
);
$assert(
    'El CSV del filtro RESTOCKER solo publica reponedores en la columna Rol',
    in_array('RESTOCKER', array_column($csvRows, 1), true)
        && !in_array('TECHNICIAN', array_column($csvRows, 1), true)
);
$assert(
    'El CSV del filtro RESTOCKER publica el rol también en el detalle',
    $detailIndex !== null && in_array('RESTOCKER', array_column(array_slice($csvRows, $detailIndex + 1), 3), true)
);

$allCsv = $workHours->exportWorkHoursCsv($filter);
$assert(
    'El CSV ALL desglosa ambos roles',
    str_contains($allCsv, ';RESTOCKER;') && str_contains($allCsv, ';TECHNICIAN;')
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Ausencias del reponedor (`RF-05.1`)
// ─────────────────────────────────────────────────────────────────────────
echo "\n3. Ausencias del reponedor sin asistente SAT\n";

// El alta de reponedor no consulta incidencias: se inyecta el puerto real sin
// construir (jamás se invoca) para no arrastrar toda la interfaz de tickets.
$incidentRepo = (new ReflectionClass(PdoIncidentRepository::class))->newInstanceWithoutConstructor();

$absenceAudit = new RestockerAuditRepository();
$absenceService = new TechnicianAbsenceService(
    absenceRepo: $absenceRepo,
    userRepo: $users,
    incidentRepo: $incidentRepo,
    auditRepo: $absenceAudit,
    transactions: $transactions,
    lockRepo: $lockRepo
);

$absenceResult = $absenceService->createAbsence(7, 'VACATION', '2026-11-20', '2026-11-22', 'Vacaciones anuales', 1);

$assert('El alta de ausencia del reponedor se admite', isset($absenceResult['absence']));
$assert('El reponedor no recibe asistente de tickets SAT', $absenceResult['affected_incidents'] === []);
$assert(
    'Sin zona inyectada el informe de promoción viaja vacío',
    $absenceResult['promoted_zones'] === []
);
$assert(
    'La ausencia del reponedor queda auditada',
    ($absenceAudit->events[0] ?? null)?->getAction() === 'CREATE_TECHNICIAN_ABSENCE'
);

try {
    $absenceService->createAbsence(1, 'VACATION', '2026-11-20', '2026-11-22', null, 1);
    $assert('Un rol no operativo no admite ausencias', false, 'la operación fue admitida');
} catch (UserNotFoundException) {
    $assert('Un rol no operativo no admite ausencias', true);
}

echo "\n======================================================================\n";
echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
