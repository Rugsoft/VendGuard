<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

/**
 * TechnicianAbsenceRepositoryInterface
 *
 * Puerto de persistencia para ausencias de técnicos (Módulo 21).
 */
interface TechnicianAbsenceRepositoryInterface
{
    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence;

    public function findById(int $id): ?TechnicianAbsence;

    /**
     * @return list<TechnicianAbsence>
     */
    public function findAll(?int $technicianId = null, ?string $status = null): array;

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence;

    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool;

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool;
}
