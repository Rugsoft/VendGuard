<?php

declare(strict_types=1);

/**
 * ShiftHandoverServiceTest · T-ASH-05
 *
 * Certifica el servicio de aplicación `ShiftHandoverService` (Módulo 21):
 *
 *   1. Guardia estricta fail-fast ante averías en `IN_PROGRESS` (`ActiveIncidentInProgressException`).
 *   2. Relevo atómico de averías en `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO`.
 *   3. Exigencia de nota interna confidencial (`is_internal = TRUE`, Art. V.4) >= 10 caracteres.
 *   4. Detección de colisión concurrente (`CONCURRENT_ROUTE_MODIFICATION`, 409).
 *   5. Rechazo de auto-traspaso (`INVALID_TARGET_TECHNICIAN`).
 *   6. Retención de averías ordinarias no seleccionadas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, dobles de prueba en memoria.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\ShiftHandoverResultDto;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

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
echo " VendGuard · T-ASH-05 · ShiftHandoverService (Unit)\n";
echo "======================================================================\n";

// Dobles de prueba
$activeShift = new TechnicianShift(
    id: 1,
    technicianId: 4,
    status: ShiftStatus::ON_SHIFT,
    startedAt: new DateTimeImmutable('2026-10-23 08:00:00+00')
);

$shiftRepo = new class($activeShift) implements TechnicianShiftRepositoryInterface {
    public function __construct(public ?TechnicianShift &$shift) {}
    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift {
        return ($this->shift !== null && $this->shift->technicianId() === $technicianId && $this->shift->isOpen()) ? $this->shift : null;
    }
    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift { throw new LogicException(); }
    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift { throw new LogicException(); }
    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift { throw new LogicException(); }
    public function closeShift(int $shiftId, int $closedBy, ?string $closeReason = null, ?DateTimeImmutable $closedAt = null): TechnicianShift {
        $this->shift = $this->shift->close($closedBy, $closeReason, $closedAt);
        return $this->shift;
    }
    public function getActiveShiftsMap(): array { return []; }
};

$userRepo = new class implements UserRepositoryInterface {
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User {
        if ($id === 4) {
            return new User(4, 'Técnico Saliente', 'saliente@vending.test', 'hash', UserRole::TECHNICIAN, isActive: true);
        }
        if ($id === 8) {
            return new User(8, 'Técnico Entrante', 'entrante@vending.test', 'hash', UserRole::TECHNICIAN, isActive: true);
        }
        return null;
    }
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function create(array $data): User { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function updatePassword(int $id, string $newPassword): bool { return false; }
    public function resetPassword(int $id, string $newPassword): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function countActiveByRole(UserRole|string $role): int { return 2; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
};

$incidents = [
    101 => new Incident(101, 'INC-0101', 1, 1, IncidentCategory::ELECTRICAL_OFF, 'Avería 1', UrgencyLevel::MEDIUM, IncidentStatus::ASSIGNED, assignedTechnicianId: 4),
    102 => new Incident(102, 'INC-0102', 2, 1, IncidentCategory::PAYMENT_SYSTEM, 'Avería 2', UrgencyLevel::LOW, IncidentStatus::PENDING_PARTS, assignedTechnicianId: 4),
    103 => new Incident(103, 'INC-0103', 3, 1, IncidentCategory::TEMPERATURE_COLD, 'Avería 3', UrgencyLevel::HIGH, IncidentStatus::PENDING_INFO, assignedTechnicianId: 4),
];
$addedComments = [];
$assignedTickets = [];

$incidentRepo = new class($incidents, $addedComments, $assignedTickets) implements IncidentRepositoryInterface {
    public function __construct(public array &$incidents, public array &$comments, public array &$assigned) {}
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array {
        $res = [];
        foreach ($this->incidents as $inc) {
            if ($inc->getAssignedTechnicianId() === $technicianId && ($statuses === [] || in_array($inc->getStatus()->value, $statuses, true))) {
                $res[] = $inc;
            }
        }
        return $res;
    }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null, ?string $reassignmentReason = null): Incident {
        $this->assigned[$incidentId] = $technicianId;
        return $this->incidents[$incidentId];
    }
    public function addComment(IncidentComment $comment): IncidentComment {
        $this->comments[] = $comment;
        return $comment;
    }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException(); }
    public function findById(int $id): ?Incident { return $this->incidents[$id] ?? null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function update(Incident $incident): bool { return false; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 0; }
    public function getHistory(int $incidentId): array { return []; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countCommentsForIncidents(array $incidentIds, bool $includeInternal): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException(); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException(); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException(); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException(); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException(); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, DateTimeImmutable $pausedAt): void {}
    public function recordResumeEvent(int $incidentId, ?int $userId, VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, DateTimeImmutable $resumedAt): void {}
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { return []; }
};

$machineRepo = new class implements MachineRepositoryInterface {
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine {
        // Ninguna de estas tres máquinas de prueba es perecedera (SNACKS)
        return new Machine($id, 1, 'M' . $id, 'Modelo', MachineType::SNACKS, 'Planta 1');
    }
    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return false; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
};

$onCallRepo = new class implements OnCallTechnicianRepositoryInterface {
    public function setOnCall(int $userId, bool $onCall): bool { return true; }
    public function findOnCallTechnicians(): array { return []; }
    public function countOnCallTechnicians(): int { return 0; }
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

// PDO mock que soporta transacciones
$mockPdo = new class extends PDO {
    public function __construct() {}
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function inTransaction(): bool { return false; }
};

$service = new ShiftHandoverService(
    pdo: $mockPdo,
    shiftRepo: $shiftRepo,
    userRepo: $userRepo,
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo,
    onCallRepo: $onCallRepo,
    auditRepo: $auditRepo
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Bloqueo fail-fast ante averías en IN_PROGRESS (RF-04)
// ─────────────────────────────────────────────────────────────────────────────
$incidents[104] = new Incident(104, 'INC-0104', 1, 1, IncidentCategory::PRODUCT_JAM, 'En curso', UrgencyLevel::HIGH, IncidentStatus::IN_PROGRESS, assignedTechnicianId: 4);

$rejectedInProgress = false;
try {
    $service->closeAndHandover(technicianId: 4);
} catch (ActiveIncidentInProgressException $e) {
    $rejectedInProgress = $e->getErrorCode() === 'HAS_IN_PROGRESS_INCIDENTS' && $e->getBlockingIncidentCodes() === ['INC-0104'];
}
$assert('closeAndHandover rechaza fail-fast con HAS_IN_PROGRESS_INCIDENTS si hay avería en curso', $rejectedInProgress);

// Retiramos la avería en curso para continuar los casos
unset($incidents[104]);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Validación de notas de traspaso y auto-traspaso
// ─────────────────────────────────────────────────────────────────────────────
$rejectedSelfHandover = false;
try {
    $service->closeAndHandover(technicianId: 4, targetTechnicianId: 4, incidentIdsToTransfer: [101], handoverNotes: 'Traspaso a mí mismo');
} catch (InvalidShiftTransitionException $e) {
    $rejectedSelfHandover = $e->getErrorCode() === 'INVALID_TARGET_TECHNICIAN';
}
$assert('closeAndHandover rechaza auto-traspaso con INVALID_TARGET_TECHNICIAN', $rejectedSelfHandover);

$rejectedShortNotes = false;
try {
    $service->closeAndHandover(technicianId: 4, targetTechnicianId: 8, incidentIdsToTransfer: [101], handoverNotes: 'corta');
} catch (JustificationTooShortException $e) {
    $rejectedShortNotes = true;
}
$assert('closeAndHandover rechaza notas < 10 caracteres', $rejectedShortNotes);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Detección de colisión concurrente (409 CONCURRENT_ROUTE_MODIFICATION)
// ─────────────────────────────────────────────────────────────────────────────
$rejectedConcurrent = false;
try {
    $service->closeAndHandover(technicianId: 4, targetTechnicianId: 8, incidentIdsToTransfer: [999], handoverNotes: 'Notas válidas de más de 10 caracteres');
} catch (InvalidShiftTransitionException $e) {
    $rejectedConcurrent = $e->getErrorCode() === 'CONCURRENT_ROUTE_MODIFICATION';
}
$assert('closeAndHandover rechaza con CONCURRENT_ROUTE_MODIFICATION (409) si un ticket no pertenece a la ruta', $rejectedConcurrent);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Relevo atómico exitoso de ASSIGNED y PENDING_PARTS, y retención de PENDING_INFO
// ─────────────────────────────────────────────────────────────────────────────
$result = $service->closeAndHandover(
    technicianId: 4,
    targetTechnicianId: 8,
    incidentIdsToTransfer: [101, 102],
    handoverNotes: 'Falta llave y repuesto de monedero para mañana por la mañana',
    closeReason: 'Fin de jornada con relevo parcial'
);

$assert('closeAndHandover devuelve DTO de resultado', $result instanceof ShiftHandoverResultDto);
$assert('closeAndHandover cierra el turno a OFF_SHIFT', $result->shift->status === 'OFF_SHIFT');
$assert('closeAndHandover traspasa 2 tickets a técnico 8', count($result->transferredIncidents) === 2);
$assert('closeAndHandover retiene 1 ticket no transferido', count($result->retainedIncidents) === 1 && $result->retainedIncidents[0]['id'] === 103);

// Verificar notas internas confidenciales insertadas
$assert('Se insertó un comentario interno por cada ticket traspasado', count($addedComments) === 2);
$assert('Los comentarios de traspaso nacen con is_internal = true (Art. V.4)', $addedComments[0]->isInternal() && $addedComments[1]->isInternal());

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
