<?php

declare(strict_types=1);

namespace VendGuard\Tests\Support;

use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Core\Domain\Repository\WorkshopRepairItemRepositoryInterface;

/**
 * NullWorkshopRepairItemRepository — doble inerte de la bandeja de taller.
 *
 * El cierre de una intervención con `old_part_destination = TALLER` abre
 * unidades del Módulo 27. Los arneses unitarios que no certifican ese desglose
 * inyectan este doble para que el servicio no caiga en el repositorio PDO por
 * defecto y no escriba contra el motor real con identificadores ficticios.
 */
final class NullWorkshopRepairItemRepository implements WorkshopRepairItemRepositoryInterface
{
    public function createUnitBreakdownForReplacedPart(array $breakdownPayload): array
    {
        return [];
    }

    public function findById(int $id): ?array
    {
        return null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        return null;
    }

    public function updateReception(int $id, int $receivedByUserId, ?string $receptionNotes, string $receivedAt): array
    {
        return [];
    }

    public function updateStartBench(int $id, int $benchStartedByUserId, string $benchStartedAt): array
    {
        return [];
    }

    public function updateResolution(int $id, array $resolutionData): array
    {
        return [];
    }

    public function findPendingDeliveriesForTechnician(int $technicianId): array
    {
        return [];
    }

    public function paginateWorkshopTray(WorkshopTrayQueryDTO $query): array
    {
        return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 1];
    }

    public function computeTrayKpis(WorkshopTrayQueryDTO $query): array
    {
        return [];
    }

    public function findAllForExport(WorkshopTrayQueryDTO $query): array
    {
        return [];
    }
}
