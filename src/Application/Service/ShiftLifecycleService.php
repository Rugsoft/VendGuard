<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * ShiftLifecycleService
 *
 * Servicio de Aplicación responsable de coordinar el ciclo de jornada de los técnicos:
 * inicio de turno, pausas de comida y reanudación (Módulos 21 y 22). El cierre,
 * ordinario o forzado, vive en `ShiftHandoverService` para que ambos caminos
 * compartan el protocolo sanitario y la guardia de averías en curso.
 *
 * Módulo 22:
 *   - Cada transición deja su entrada inmutable en `technician_shift_logs`
 *     (Art. III.3) dentro de la misma transacción que el cambio de estado.
 *   - `getCurrentShift()` publica el bloque aditivo `today_summary` con las
 *     sesiones y los segundos efectivos del día natural en `Europe/Madrid`
 *     (RF-07.1).
 */
final class ShiftLifecycleService
{
    /** Zona operativa del parque para fechas naturales y formatos locales. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly TechnicianAbsenceRepositoryInterface $absenceRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly AuditLogRepositoryInterface $auditRepo,
        private readonly TransactionManagerInterface $transactions,
        private readonly TechnicianLockRepositoryInterface $lockRepo,
        private readonly ?TechnicianShiftLogRepositoryInterface $shiftLogRepo = null
    ) {
    }

    public function startShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null || $tech->getRole() !== UserRole::TECHNICIAN) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        return $this->transactions->runInTransaction(function () use ($technicianId, $tech): TechnicianShiftDto {
            if (!$this->lockRepo->lockTechnicianForUpdate($technicianId)) {
                throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
            }

            // Idempotencia: si ya tiene un turno abierto, se devuelve el actual.
            // El cerrojo por técnico serializa dos inicios concurrentes.
            $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
            if ($activeShift !== null) {
                return TechnicianShiftDto::fromEntity(
                    $activeShift,
                    $tech->getName(),
                    $this->buildTodaySummary($technicianId)
                );
            }

            // Precedencia de presencia física sobre ausencia activa (Resolución R4)
            $now = new DateTimeImmutable('now');
            $activeAbsence = $this->absenceRepo->findActiveAbsenceForTechnician($technicianId, $now);

            if ($activeAbsence !== null) {
                $overrideEvent = new AuditEvent(
                    id: null,
                    entityType: AuditEvent::ENTITY_USER,
                    entityId: $technicianId,
                    action: 'ABSENCE_OVERRIDE_BY_SHIFT_START',
                    userId: $technicianId,
                    userRole: 'TECHNICIAN',
                    userName: $tech->getName(),
                    previousState: ['active_absence_id' => $activeAbsence->id(), 'reason' => $activeAbsence->reason()->value],
                    newState: ['shift_status' => ShiftStatus::ON_SHIFT->value],
                    metadata: [
                        'notice' => 'El técnico inició turno físicamente durante una ausencia planificada activa.',
                        'absence_ends_at' => $activeAbsence->endsAt()->format(DateTimeImmutable::ATOM),
                    ]
                );
                $this->auditRepo->log($overrideEvent);
            }

            $shift = $this->shiftRepo->startShift($technicianId, $now);

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: 'START_TECHNICIAN_SHIFT',
                userId: $technicianId,
                userRole: 'TECHNICIAN',
                userName: $tech->getName(),
                previousState: null,
                newState: $shift->toArray()
            );
            $this->auditRepo->log($event);

            $this->recordShiftLog(
                shift: $shift,
                eventType: ShiftEventType::START_SHIFT,
                actorUserId: $technicianId,
                effectiveAt: $shift->startedAt(),
                previousState: null,
                reason: null
            );

            return TechnicianShiftDto::fromEntity(
                $shift,
                $tech->getName(),
                $this->buildTodaySummary($technicianId, $now)
            );
        });
    }

    public function pauseMeal(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        return $this->transactions->runInTransaction(function () use ($technicianId, $tech): TechnicianShiftDto {
            $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
            if ($activeShift === null) {
                throw InvalidShiftTransitionException::noOpenShift('pausar para comida');
            }

            // El repositorio relee el turno con FOR UPDATE y el dominio distingue
            // «ya en pausa» de una transición genérica (m-2). La auditoría viaja
            // en la misma transacción que el UPDATE.
            $updated = $this->shiftRepo->pauseMeal((int)$activeShift->id());

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: 'PAUSE_TECHNICIAN_SHIFT_MEAL',
                userId: $technicianId,
                userRole: 'TECHNICIAN',
                userName: $tech->getName(),
                previousState: $activeShift->toArray(),
                newState: $updated->toArray()
            );
            $this->auditRepo->log($event);

            $this->recordShiftLog(
                shift: $updated,
                eventType: ShiftEventType::PAUSE_MEAL,
                actorUserId: $technicianId,
                effectiveAt: $updated->mealBreakStartedAt() ?? new DateTimeImmutable('now'),
                previousState: $activeShift->toArray(),
                reason: null
            );

            return TechnicianShiftDto::fromEntity(
                $updated,
                $tech->getName(),
                $this->buildTodaySummary($technicianId)
            );
        });
    }

    public function resumeShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        return $this->transactions->runInTransaction(function () use ($technicianId, $tech): TechnicianShiftDto {
            $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
            if ($activeShift === null) {
                throw InvalidShiftTransitionException::noOpenShift('reanudar la jornada');
            }

            $updated = $this->shiftRepo->resumeShift((int)$activeShift->id());

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: 'RESUME_TECHNICIAN_SHIFT',
                userId: $technicianId,
                userRole: 'TECHNICIAN',
                userName: $tech->getName(),
                previousState: $activeShift->toArray(),
                newState: $updated->toArray()
            );
            $this->auditRepo->log($event);

            $this->recordShiftLog(
                shift: $updated,
                eventType: ShiftEventType::RESUME_SHIFT,
                actorUserId: $technicianId,
                effectiveAt: $updated->mealBreakEndedAt() ?? new DateTimeImmutable('now'),
                previousState: $activeShift->toArray(),
                reason: null
            );

            return TechnicianShiftDto::fromEntity(
                $updated,
                $tech->getName(),
                $this->buildTodaySummary($technicianId)
            );
        });
    }

    public function getCurrentShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        $todaySummary = $this->buildTodaySummary($technicianId);

        if ($activeShift !== null) {
            return TechnicianShiftDto::fromEntity($activeShift, $tech?->getName(), $todaySummary);
        }

        return TechnicianShiftDto::offShift($technicianId, $tech?->getName(), $todaySummary);
    }

    /**
     * Resumen acumulado del día natural en `Europe/Madrid` (RF-07.1).
     *
     * Suma todas las sesiones imputadas a hoy —incluida la que sigue abierta—
     * al segundo y con suelo cero, de modo que la pausa viva de una sesión en
     * `MEAL_BREAK` ya descuenta del total efectivo.
     *
     * @return array{date: string, sessions_count: int, gross_seconds: int, gross_formatted: string, meal_break_seconds: int, meal_break_formatted: string, effective_seconds: int, effective_formatted: string}
     */
    public function buildTodaySummary(int $technicianId, ?DateTimeImmutable $referenceNow = null): array
    {
        $now = $referenceNow ?? new DateTimeImmutable('now');
        $today = $now->setTimezone(new DateTimeZone(self::OPERATIONAL_TIMEZONE))->format('Y-m-d');

        $sessions = $this->shiftRepo->findShiftsByDateRange($today, $today, $technicianId);

        $grossSeconds = 0;
        $mealBreakSeconds = 0;
        $effectiveSeconds = 0;

        foreach ($sessions as $session) {
            $grossSeconds += $session->grossDurationSeconds($now);
            $mealBreakSeconds += $session->totalMealBreakSeconds($now);
            $effectiveSeconds += $session->effectiveWorkSeconds($now);
        }

        return [
            'date' => $today,
            'sessions_count' => count($sessions),
            'gross_seconds' => $grossSeconds,
            'gross_formatted' => self::formatSeconds($grossSeconds),
            'meal_break_seconds' => $mealBreakSeconds,
            'meal_break_formatted' => self::formatSeconds($mealBreakSeconds),
            'effective_seconds' => $effectiveSeconds,
            'effective_formatted' => self::formatSeconds($effectiveSeconds),
        ];
    }

    /**
     * Registra la entrada inmutable de bitácora de una transición de jornada.
     *
     * El puerto es opcional para no romper los dobles de prueba que solo
     * ejercitan el ciclo de estado; en producción la composición lo inyecta y
     * la entrada se escribe dentro de la misma transacción (Art. III.3).
     *
     * @param array<string, mixed>|null $previousState
     */
    private function recordShiftLog(
        TechnicianShift $shift,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        ?string $reason
    ): void {
        if ($this->shiftLogRepo === null || $shift->id() === null) {
            return;
        }

        $this->shiftLogRepo->record(
            shiftId: (int)$shift->id(),
            technicianId: $shift->technicianId(),
            eventType: $eventType,
            actorUserId: $actorUserId,
            effectiveAt: $effectiveAt,
            previousState: $previousState,
            newState: $shift->toArray(),
            reason: $reason
        );
    }

    /** Formato de duración del contrato: `HHh MMm`, sin días. */
    private static function formatSeconds(int $seconds): string
    {
        $safeSeconds = max(0, $seconds);

        return sprintf('%02dh %02dm', intdiv($safeSeconds, 3600), intdiv($safeSeconds % 3600, 60));
    }
}
