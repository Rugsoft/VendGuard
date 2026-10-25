<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\HandoverTargetAvailability;

/**
 * HandoverTargetService
 *
 * Lista los compañeros a los que un técnico puede traspasar su ruta y valida
 * que el receptor elegido esté realmente disponible (Módulo 21, RF-05).
 *
 * El listado publica disponibilidad operativa, nunca motivos, fechas ni notas
 * de ausencia. Una ausencia sin turno se proyecta como `UNAVAILABLE`.
 */
final class HandoverTargetService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly TechnicianAbsenceRepositoryInterface $absenceRepo
    ) {
    }

    /**
     * @return list<array{
     *     id:int,
     *     name:string,
     *     is_active:bool,
     *     is_on_call:bool,
     *     shift_status:string,
     *     availability:string,
     *     availability_label:string,
     *     is_available_for_handover:bool
     * }>
     */
    public function listFor(int $currentTechnicianId, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $shifts = $this->shiftRepo->getActiveShiftsMap();
        $absences = $this->absenceRepo->findActiveAbsencesMap($at);

        $targets = [];
        foreach ($this->userRepo->findAllTechnicians(true) as $technician) {
            $id = (int)$technician->getId();
            if ($id === $currentTechnicianId || !$technician->isActive()) {
                continue;
            }

            $shiftStatus = $shifts[$id] ?? 'OFF_SHIFT';
            $availability = HandoverTargetAvailability::classify(
                $shiftStatus,
                isset($absences[$id]),
                $technician->isOnCall()
            );

            $targets[] = [
                'id' => $id,
                'name' => $technician->getName(),
                'is_active' => true,
                'is_on_call' => $technician->isOnCall(),
                'shift_status' => $shiftStatus,
                'availability' => $availability->value,
                'availability_label' => $availability->label(),
                'is_available_for_handover' => $availability->canReceiveHandover(),
            ];
        }

        usort(
            $targets,
            static function (array $left, array $right): int {
                $leftOrder = HandoverTargetAvailability::from($left['availability'])->sortOrder();
                $rightOrder = HandoverTargetAvailability::from($right['availability'])->sortOrder();

                return ($leftOrder <=> $rightOrder)
                    ?: strcasecmp($left['name'], $right['name'])
                    ?: ($left['id'] <=> $right['id']);
            }
        );

        return $targets;
    }

    /**
     * Disponibilidad individual del receptor, o null si no es un técnico activo.
     */
    public function availabilityFor(int $technicianId, ?DateTimeImmutable $at = null): ?HandoverTargetAvailability
    {
        $at ??= new DateTimeImmutable('now');

        $technician = $this->userRepo->findById($technicianId);
        if (!$this->isEligibleTechnician($technician)) {
            return null;
        }

        $shift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);

        return HandoverTargetAvailability::classify(
            $shift?->status()->value ?? 'OFF_SHIFT',
            $this->absenceRepo->findActiveAbsenceForTechnician($technicianId, $at) !== null,
            $technician->isOnCall()
        );
    }

    /**
     * @throws InvalidShiftTransitionException 404 si no es técnico activo; 422 si no disponible.
     */
    public function assertCanReceiveHandover(int $technicianId, ?DateTimeImmutable $at = null): void
    {
        $availability = $this->availabilityFor($technicianId, $at);

        if ($availability === null) {
            throw new InvalidShiftTransitionException(
                "No se encontró el técnico de relevo con ID {$technicianId}.",
                'TARGET_TECHNICIAN_NOT_FOUND',
                404
            );
        }

        if (!$availability->canReceiveHandover()) {
            throw new InvalidShiftTransitionException(
                'El técnico seleccionado no está disponible para recibir el relevo.',
                'TARGET_TECHNICIAN_UNAVAILABLE',
                422
            );
        }
    }

    private function isEligibleTechnician(?User $technician): bool
    {
        return $technician !== null
            && $technician->isActive()
            && $technician->getRole() === UserRole::TECHNICIAN;
    }
}
