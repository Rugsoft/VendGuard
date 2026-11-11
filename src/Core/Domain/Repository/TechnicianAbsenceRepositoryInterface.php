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
     * Listado de ausencias con filtros opcionales de técnico, estado y rol.
     *
     * El filtro de rol (`ALL` | `TECHNICIAN` | `RESTOCKER`, Módulo 31, `D2`) se
     * aplica en el motor sobre `users.role`: la consola de Coordinación no puede
     * traer todas las ausencias y descartar en PHP, porque paginaría de menos y
     * daría cifras falsas. Un valor fuera del catálogo se rechaza en voz alta.
     *
     * @param int|null $technicianId Acota a un operario concreto.
     * @param string|null $status `active`, `future`, `cancelled` o `all`/null.
     * @param string|null $role `ALL` (o null), `TECHNICIAN` o `RESTOCKER`.
     * @return list<TechnicianAbsence>
     *
     * @throws \InvalidArgumentException Si el rol no pertenece al catálogo.
     */
    public function findAll(?int $technicianId = null, ?string $status = null, ?string $role = null): array;

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence;

    /**
     * Mapa de técnicos con una ausencia activa en el instante indicado.
     *
     * @return array<int, true> technicianId => true
     */
    public function findActiveAbsencesMap(DateTimeImmutable $at): array;

    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool;

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool;
}
