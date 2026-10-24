<?php

declare(strict_types=1);

/**
 * ShiftLifecycleServiceTest · T-ASH-04
 *
 * Certifica el servicio de aplicación `ShiftLifecycleService` (Módulo 21):
 *
 *   1. Inicio de turno (`startShift`):
 *      - Abre turno en `ON_SHIFT` y audita `START_TECHNICIAN_SHIFT`.
 *      - Idempotente si ya está abierto.
 *      - Precedencia de presencia física sobre ausencia planificada: abre turno y
 *        emite aviso informativo `ABSENCE_OVERRIDE_BY_SHIFT_START` en `audit_log` (Resolución R4).
 *   2. Pausa y reanudación de comida (`pauseMeal`, `resumeShift`):
 *      - Transiciones válidas y eventos de auditoría.
 *   3. El cierre forzado se certifica en `ShiftHandoverServiceTest`, porque comparte
 *      el motor transaccional con el cierre ordinario y el protocolo sanitario.
 *
 * Dogma Vanilla: PHP 8.2+ puro, repositorios mock en memoria.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VendGuard · T-ASH-04 · ShiftLifecycleService (Unit)\n";
echo "======================================================================\n";

// Mocks en memoria
$shiftStorage = [];
$autoShiftId = 1;

$shiftRepo = new class($shiftStorage, $autoShiftId) implements TechnicianShiftRepositoryInterface {
    public function __construct(public array &$storage, public int &$autoShiftId) {}

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift {
        foreach ($this->storage as $s) {
            if ($s->technicianId() === $technicianId && $s->isOpen()) {
                return $s;
            }
        }
        return null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift {
        $active = $this->findActiveShiftByTechnician($technicianId);
        if ($active !== null) {
            return $active;
        }
        $id = $this->autoShiftId++;
        $shift = new TechnicianShift(
            id: $id,
            technicianId: $technicianId,
            status: ShiftStatus::ON_SHIFT,
            startedAt: $startedAt ?? new DateTimeImmutable('now')
        );
        $this->storage[$id] = $shift;
        return $shift;
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift {
        $shift = $this->storage[$shiftId];
        $updated = $shift->pauseMeal($pausedAt);
        $this->storage[$shiftId] = $updated;
        return $updated;
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift {
        $shift = $this->storage[$shiftId];
        $updated = $shift->resume($resumedAt);
        $this->storage[$shiftId] = $updated;
        return $updated;
    }

    public function closeShift(int $shiftId, int $closedBy, ?string $closeReason = null, ?DateTimeImmutable $closedAt = null): TechnicianShift {
        $shift = $this->storage[$shiftId];
        $updated = $shift->close($closedBy, $closeReason, $closedAt);
        $this->storage[$shiftId] = $updated;
        return $updated;
    }

    public function getActiveShiftsMap(): array {
        $res = [];
        foreach ($this->storage as $s) {
            if ($s->isOpen()) {
                $res[$s->technicianId()] = $s->status()->value;
            }
        }
        return $res;
    }
};

$activeAbsence = null;
$absenceRepo = new class($activeAbsence) implements TechnicianAbsenceRepositoryInterface {
    public function __construct(public ?TechnicianAbsence &$activeAbsence) {}
    public function create(int $technicianId, AbsenceReason $reason, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?string $notes, int $createdBy): TechnicianAbsence { throw new \Exception('unused'); }
    public function findById(int $id): ?TechnicianAbsence { return null; }
    public function findAll(?int $technicianId = null, ?string $status = null): array { return []; }
    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence {
        return $this->activeAbsence;
    }
    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool { return false; }
    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool { return true; }
};

$auditRepo = new class implements AuditLogRepositoryInterface {
    public array $events = [];
    public function log(VendGuard\Core\Domain\Model\AuditEvent $event): VendGuard\Core\Domain\Model\AuditEvent {
        $this->events[] = $event;
        return $event;
    }
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countEvents(array $filters = []): int { return 0; }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
};

$userRepo = new class implements UserRepositoryInterface {
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?VendGuard\Core\Domain\Model\User {
        if ($id === 5) {
            return new VendGuard\Core\Domain\Model\User(
                id: 5,
                name: 'Técnico Móvil',
                email: 'tech@vending.test',
                passwordHash: 'hash',
                role: VendGuard\Core\Domain\Model\UserRole::TECHNICIAN,
                isActive: true
            );
        }
        if ($id === 1) {
            return new VendGuard\Core\Domain\Model\User(
                id: 1,
                name: 'Coordinador Jefe',
                email: 'coord@vending.test',
                passwordHash: 'hash',
                role: VendGuard\Core\Domain\Model\UserRole::COORDINATOR,
                isActive: true
            );
        }
        return null;
    }
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?VendGuard\Core\Domain\Model\User { return null; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function create(array $data): VendGuard\Core\Domain\Model\User { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function updatePassword(int $id, string $newPassword): bool { return false; }
    public function resetPassword(int $id, string $newPassword): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function countActiveByRole(VendGuard\Core\Domain\Model\UserRole|string $role): int { return 1; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
};

$service = new ShiftLifecycleService(
    shiftRepo: $shiftRepo,
    absenceRepo: $absenceRepo,
    userRepo: $userRepo,
    auditRepo: $auditRepo
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Inicio de turno ordinario
// ─────────────────────────────────────────────────────────────────────────────
$shiftDto = $service->startShift(technicianId: 5);

$assert('startShift devuelve DTO en ON_SHIFT', $shiftDto instanceof TechnicianShiftDto && $shiftDto->status === 'ON_SHIFT');
$assert('startShift registra START_TECHNICIAN_SHIFT', count($auditRepo->events) === 1 && $auditRepo->events[0]->getAction() === 'START_TECHNICIAN_SHIFT');

// Idempotente
$idempotentShift = $service->startShift(technicianId: 5);
$assert('startShift es idempotente si ya está abierto', $idempotentShift->id === $shiftDto->id);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Pausa y reanudación de comida
// ─────────────────────────────────────────────────────────────────────────────
$pausedDto = $service->pauseMeal(technicianId: 5);
$assert('pauseMeal pasa a MEAL_BREAK', $pausedDto->status === 'MEAL_BREAK');
$assert('pauseMeal registra PAUSE_TECHNICIAN_SHIFT_MEAL', count($auditRepo->events) === 2 && $auditRepo->events[1]->getAction() === 'PAUSE_TECHNICIAN_SHIFT_MEAL');

$resumedDto = $service->resumeShift(technicianId: 5);
$assert('resumeShift vuelve a ON_SHIFT', $resumedDto->status === 'ON_SHIFT');
$assert('resumeShift registra RESUME_TECHNICIAN_SHIFT', count($auditRepo->events) === 3 && $auditRepo->events[2]->getAction() === 'RESUME_TECHNICIAN_SHIFT');

// ─────────────────────────────────────────────────────────────────────────────
// 3. Precedencia de presencia física sobre ausencia activa (Resolución R4)
// ─────────────────────────────────────────────────────────────────────────────
// Cerramos turno actual
$shiftRepo->closeShift($resumedDto->id, 5, 'Fin turno mañana');

// Simulamos que el técnico tiene una ausencia activa planificada
$activeAbsence = new TechnicianAbsence(
    id: 99,
    technicianId: 5,
    reason: AbsenceReason::VACATION,
    startsAt: new DateTimeImmutable('2026-10-20 00:00:00+00'),
    endsAt: new DateTimeImmutable('2026-10-30 23:59:59+00'),
    notes: 'Vacaciones planificadas',
    createdBy: 1
);

$overrideShift = $service->startShift(technicianId: 5);
$assert('startShift con ausencia activa permite iniciar turno (presencia física R4)', $overrideShift->status === 'ON_SHIFT');

$auditActions = array_map(fn($e) => $e->getAction(), $auditRepo->events);
$assert('startShift con ausencia activa registra aviso ABSENCE_OVERRIDE_BY_SHIFT_START', in_array('ABSENCE_OVERRIDE_BY_SHIFT_START', $auditActions, true));

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
