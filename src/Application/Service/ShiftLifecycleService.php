<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * ShiftLifecycleService
 *
 * Servicio de Aplicación responsable de coordinar el ciclo de jornada de los técnicos:
 * inicio de turno, pausas de comida y reanudación (Módulo 21). El cierre, ordinario o
 * forzado, vive en `ShiftHandoverService` para que ambos caminos compartan el protocolo
 * sanitario y la guardia de averías en curso.
 */
final class ShiftLifecycleService
{
    public function __construct(
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly TechnicianAbsenceRepositoryInterface $absenceRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly AuditLogRepositoryInterface $auditRepo
    ) {
    }

    public function startShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null || $tech->getRole() !== UserRole::TECHNICIAN) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        // Idempotencia: si ya tiene un turno abierto, se devuelve el actual
        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        if ($activeShift !== null) {
            return TechnicianShiftDto::fromEntity($activeShift, $tech->getName());
        }

        // Precedencia de presencia física sobre ausencia activa (Resolución R4)
        $now = new DateTimeImmutable('now');
        $activeAbsence = $this->absenceRepo->findActiveAbsenceForTechnician($technicianId, $now);

        if ($activeAbsence !== null) {
            // Se registra un evento informativo de aviso en auditoría
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

        // Auditoría de inicio de turno
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

        return TechnicianShiftDto::fromEntity($shift, $tech->getName());
    }

    public function pauseMeal(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        if ($activeShift === null || !$activeShift->isOnShift()) {
            throw InvalidShiftTransitionException::notOnShift('pausar para comida');
        }

        $updated = $this->shiftRepo->pauseMeal($activeShift->id());

        // Auditoría
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

        return TechnicianShiftDto::fromEntity($updated, $tech->getName());
    }

    public function resumeShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        if ($activeShift === null || !$activeShift->isMealBreak()) {
            throw InvalidShiftTransitionException::notInMealBreak();
        }

        $updated = $this->shiftRepo->resumeShift($activeShift->id());

        // Auditoría
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

        return TechnicianShiftDto::fromEntity($updated, $tech->getName());
    }

    public function getCurrentShift(int $technicianId): TechnicianShiftDto
    {
        $tech = $this->userRepo->findById($technicianId);
        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);

        if ($activeShift !== null) {
            return TechnicianShiftDto::fromEntity($activeShift, $tech?->getName());
        }

        return TechnicianShiftDto::offShift($technicianId, $tech?->getName());
    }

}
