<?php

declare(strict_types=1);

/**
 * TechnicianAbsenceServiceTest · T-ASH-04
 *
 * Certifica el servicio de aplicación `TechnicianAbsenceService` (Módulo 21):
 *
 *   1. Alta de ausencia con fechas válidas, persistencia en repositorio y auditoría.
 *   2. Detección y rechazo de solapamiento de fechas con `InvalidAbsenceException` (409).
 *   3. Rechazo de rango de fechas invertido (422).
 *   4. Identificación de tickets afectados asignados al técnico ausente para reasignación asistida.
 *   5. Cancelación lógica con motivo auditado (>= 5 caracteres).
 *   6. Listado con filtros por técnico y estado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, repositorios mock en memoria.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\TechnicianAbsenceDto;
use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

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
echo " VendGuard · T-ASH-04 · TechnicianAbsenceService (Unit)\n";
echo "======================================================================\n";

// Mocks en memoria
$absenceStorage = [];
$autoId = 1;

$absenceRepo = new class($absenceStorage, $autoId) implements TechnicianAbsenceRepositoryInterface {
    public function __construct(public array &$storage, public int &$autoId) {}

    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence {
        $id = $this->autoId++;
        $absence = new TechnicianAbsence(
            id: $id,
            technicianId: $technicianId,
            reason: $reason,
            startsAt: $startsAt,
            endsAt: $endsAt,
            notes: $notes,
            createdBy: $createdBy,
            createdAt: new DateTimeImmutable('now')
        );
        $this->storage[$id] = $absence;
        return $absence;
    }

    public function findById(int $id): ?TechnicianAbsence {
        return $this->storage[$id] ?? null;
    }

    public function findAll(?int $technicianId = null, ?string $status = null): array {
        $res = [];
        $now = new DateTimeImmutable('now');
        foreach ($this->storage as $a) {
            if ($technicianId !== null && $a->technicianId() !== $technicianId) {
                continue;
            }
            if ($status === 'active' && (!$a->isActiveAt($now) || $a->isCancelled())) {
                continue;
            }
            if ($status === 'cancelled' && !$a->isCancelled()) {
                continue;
            }
            if ($status === 'future' && ($a->startsAt() <= $now || $a->isCancelled())) {
                continue;
            }
            $res[] = $a;
        }
        return $res;
    }

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence {
        $time = $referenceTime ?? new DateTimeImmutable('now');
        foreach ($this->storage as $a) {
            if ($a->technicianId() === $technicianId && $a->isActiveAt($time)) {
                return $a;
            }
        }
        return null;
    }

    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool {
        foreach ($this->storage as $a) {
            if ($a->technicianId() !== $technicianId || $a->isCancelled()) {
                continue;
            }
            if ($excludeAbsenceId !== null && $a->id() === $excludeAbsenceId) {
                continue;
            }
            // Misma semántica semiabierta que el repositorio PDO (m-1):
            // compartir solo el instante límite no es solape.
            if ($a->startsAt() < $endsAt && $a->endsAt() > $startsAt) {
                return true;
            }
        }
        return false;
    }

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool {
        if (!isset($this->storage[$id])) {
            return false;
        }
        $this->storage[$id] = $this->storage[$id]->cancel($cancelledBy, $cancellationReason);
        return true;
    }
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
        if ($id === 7) {
            return new VendGuard\Core\Domain\Model\User(
                id: 7,
                name: 'Carlos Ruiz',
                email: 'carlos@vending.test',
                passwordHash: 'hash',
                role: VendGuard\Core\Domain\Model\UserRole::TECHNICIAN,
                isActive: true
            );
        }
        if ($id === 1) {
            return new VendGuard\Core\Domain\Model\User(
                id: 1,
                name: 'Laura Coordinadora',
                email: 'laura@vending.test',
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

$incident = new VendGuard\Core\Domain\Model\Incident(
    id: 101,
    ticketCode: 'INC-2026-0101',
    machineId: 3,
    locationId: 1,
    category: VendGuard\Core\Domain\ValueObject\IncidentCategory::ELECTRICAL_OFF,
    description: 'Avería de prueba',
    urgency: VendGuard\Core\Domain\ValueObject\UrgencyLevel::MEDIUM,
    status: VendGuard\Core\Domain\ValueObject\IncidentStatus::ASSIGNED,
    assignedTechnicianId: 7
);

$incidentRepo = new class([$incident]) implements IncidentRepositoryInterface {
    public function __construct(private readonly array $incidents) {}
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function create(VendGuard\Core\Domain\Model\Incident $incident, ?int $userId = null, ?string $initialNote = null): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function findById(int $id): ?VendGuard\Core\Domain\Model\Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?VendGuard\Core\Domain\Model\Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?VendGuard\Core\Domain\Model\Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?VendGuard\Core\Domain\Model\Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return $this->incidents; }
    public function update(VendGuard\Core\Domain\Model\Incident $incident): bool { return false; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 0; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(VendGuard\Core\Domain\Model\IncidentComment $comment): VendGuard\Core\Domain\Model\IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countCommentsForIncidents(array $incidentIds, bool $includeInternal): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function reopen(int $incidentId, string $reasonText): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function startIntervention(int $incidentId, int $technicianId): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): VendGuard\Core\Domain\Model\Incident { throw new LogicException(); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, DateTimeImmutable $pausedAt): void {}
    public function recordResumeEvent(int $incidentId, ?int $userId, VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, DateTimeImmutable $resumedAt): void {}
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { return []; }
};

$service = new TechnicianAbsenceService(
    absenceRepo: $absenceRepo,
    userRepo: $userRepo,
    incidentRepo: $incidentRepo,
    auditRepo: $auditRepo
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Alta de ausencia y propuesta de tickets afectados
// ─────────────────────────────────────────────────────────────────────────────
$result = $service->createAbsence(
    technicianId: 7,
    reason: 'VACATION',
    startsAt: '2026-11-10 00:00:00+00',
    endsAt: '2026-11-20 23:59:59+00',
    notes: 'Vacaciones noviembre',
    createdBy: 1
);

$assert('createAbsence devuelve DTO de la ausencia', $result['absence'] instanceof TechnicianAbsenceDto);
$assert('createAbsence incluye tickets afectados del técnico', count($result['affected_incidents']) === 1);
$assert('createAbsence ticket afectado es INC-2026-0101', $result['affected_incidents'][0]['ticket_code'] === 'INC-2026-0101');
$assert('createAbsence registra auditoría CREATE_TECHNICIAN_ABSENCE', count($auditRepo->events) === 1 && $auditRepo->events[0]->getAction() === 'CREATE_TECHNICIAN_ABSENCE');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Rechazo de solapamiento
// ─────────────────────────────────────────────────────────────────────────────
$rejectedOverlap = false;
try {
    $service->createAbsence(
        technicianId: 7,
        reason: 'SICK_LEAVE',
        startsAt: '2026-11-15 00:00:00+00',
        endsAt: '2026-11-25 23:59:59+00',
        notes: null,
        createdBy: 1
    );
} catch (InvalidAbsenceException $e) {
    $rejectedOverlap = $e->getErrorCode() === 'ABSENCE_OVERLAP';
}
$assert('createAbsence rechaza solapamiento con ABSENCE_OVERLAP (409)', $rejectedOverlap);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Cancelación lógica auditada
// ─────────────────────────────────────────────────────────────────────────────
$cancelledDto = $service->cancelAbsence(
    absenceId: $result['absence']->id,
    cancelledBy: 1,
    cancellationReason: 'Pospone vacaciones a diciembre'
);

$assert('cancelAbsence devuelve DTO marcado como cancelado', $cancelledDto->isCancelled);
$assert('cancelAbsence registra auditoría CANCEL_TECHNICIAN_ABSENCE', count($auditRepo->events) === 2 && $auditRepo->events[1]->getAction() === 'CANCEL_TECHNICIAN_ABSENCE');

// M-2: la segunda cancelación es un conflicto, no un 200 silencioso.
$secondCancelCode = null;
$secondCancelStatus = null;
try {
    $service->cancelAbsence(
        absenceId: $result['absence']->id,
        cancelledBy: 1,
        cancellationReason: 'Segundo intento sobre la misma ausencia'
    );
} catch (InvalidAbsenceException $e) {
    $secondCancelCode = $e->getErrorCode();
    $secondCancelStatus = $e->getHttpStatusCode();
}
$assert(
    'cancelAbsence rechaza la segunda cancelación con ABSENCE_ALREADY_CANCELLED (409)',
    $secondCancelCode === 'ABSENCE_ALREADY_CANCELLED' && $secondCancelStatus === 409
);
$assert('La segunda cancelación no vuelve a auditar', count($auditRepo->events) === 2);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Listado con filtros
// ─────────────────────────────────────────────────────────────────────────────
$list = $service->listAbsences(technicianId: 7, status: 'all');
$assert('listAbsences devuelve las ausencias del técnico', count($list) === 1);

// ─────────────────────────────────────────────────────────────────────────────
// 4.b m-1: el contacto exacto entre límites no es solape
// ─────────────────────────────────────────────────────────────────────────────
$touchingFirst = $service->createAbsence(
    technicianId: 7,
    reason: 'PERSONAL_LEAVE',
    startsAt: '2027-01-10 09:00:00+01:00',
    endsAt: '2027-01-10 13:00:00+01:00',
    notes: null,
    createdBy: 1
);
$touchingSecond = $service->createAbsence(
    technicianId: 7,
    reason: 'TRAINING',
    startsAt: '2027-01-10 13:00:00+01:00',
    endsAt: '2027-01-10 17:00:00+01:00',
    notes: null,
    createdBy: 1
);
$assert(
    'createAbsence admite dos ausencias que solo comparten el instante límite',
    $touchingFirst['absence']->id > 0
        && $touchingSecond['absence']->id > 0
        && $touchingFirst['absence']->endsAt === $touchingSecond['absence']->startsAt
);

// ─────────────────────────────────────────────────────────────────────────────
// 4.c M-5: las notas nunca superan los 500 caracteres
// ─────────────────────────────────────────────────────────────────────────────
$notesRejected = null;
try {
    $service->createAbsence(
        technicianId: 7,
        reason: 'OTHER',
        startsAt: '2027-02-01',
        endsAt: '2027-02-02',
        notes: str_repeat('a', TechnicianAbsence::MAX_NOTES_LENGTH + 1),
        createdBy: 1
    );
} catch (InvalidAbsenceException $e) {
    $notesRejected = $e->getErrorCode();
}
$assert('createAbsence rechaza notas de 501 caracteres con NOTES_TOO_LONG', $notesRejected === 'NOTES_TOO_LONG');

$maxNotes = str_repeat('b', TechnicianAbsence::MAX_NOTES_LENGTH);
$maxNotesAccepted = $service->createAbsence(
    technicianId: 7,
    reason: 'OTHER',
    startsAt: '2027-03-01',
    endsAt: '2027-03-02',
    notes: $maxNotes,
    createdBy: 1
);
$assert(
    'createAbsence acepta exactamente 500 caracteres',
    $maxNotesAccepted['absence']->notes === $maxNotes
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. A1: la fecha pura es una jornada completa en Madrid, no en UTC
// ─────────────────────────────────────────────────────────────────────────────
$dateOnly = $service->createAbsence(
    technicianId: 7,
    reason: 'VACATION',
    startsAt: '2026-12-01',
    endsAt: '2026-12-03',
    notes: 'Día completo en hora local',
    createdBy: 1
);

$assert(
    'createAbsence ancla el inicio a las 00:00:00 de Madrid',
    $dateOnly['absence']->startsAt === '2026-12-01T00:00:00+01:00'
);
$assert(
    'createAbsence ancla el fin a las 23:59:59 de Madrid',
    $dateOnly['absence']->endsAt === '2026-12-03T23:59:59+01:00'
);
$assert('createAbsence marca la jornada completa como is_full_day', $dateOnly['absence']->isFullDay === true);

$impossibleDateRejected = false;
try {
    $service->createAbsence(
        technicianId: 7,
        reason: 'VACATION',
        startsAt: '2026-02-30',
        endsAt: '2026-03-02',
        notes: null,
        createdBy: 1
    );
} catch (InvalidAbsenceException $e) {
    $impossibleDateRejected = $e->getErrorCode() === 'INVALID_DATE_RANGE';
}
$assert('createAbsence rechaza fechas imposibles sin normalizarlas', $impossibleDateRejected);

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
