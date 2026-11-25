<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Application\DTO\RestockingBiReportDto;
use VendGuard\Core\Domain\Repository\RestockingBiAnalyticsRepositoryInterface;
use VendGuard\Core\Service\RestockingBiCalculator;

/**
 * RestockingBiAnalyticsService
 *
 * Caso de uso de lectura del Cuadro de Mando de Reposición, Roturas de Stock y
 * Mermas Comerciales (Módulo 38; `RF-01` a `RF-05`, `D1`–`D5`, `C2`–`C5`,
 * `QA-2`, `QA-4`, `QA-5`).
 *
 * El servicio no calcula: pide al repositorio una consulta agregada por fuente
 * (evitando N+1 y productos cartesianos, `RNF-02`) y entrega el ensamblado
 * determinista a `RestockingBiCalculator`. Así el JSON de pantalla y el CSV de
 * auditoría consumen exactamente las mismas cifras y el controlador no necesita
 * conocer ninguna regla de negocio.
 *
 * Bloqueo térmico (`D2`): con `restocker_id` seleccionado, las actas M29 no son
 * segmentables. El repositorio devuelve una lista vacía y aquí se marca
 * `$thermalSegmentable = false` para que el calculador publique `null` y el
 * aviso *«No segmentable por reponedor (ámbito máquina/SAT)»*, en vez de
 * confundir «sin mermas» con «no medible».
 *
 * Sin E/S, sin reloj y sin transacciones: es un caso de uso de solo lectura.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
final class RestockingBiAnalyticsService
{
    public function __construct(
        private readonly RestockingBiAnalyticsRepositoryInterface $repository
    ) {
    }

    /**
     * Ensambla el informe completo del filtro activo (`RF-01`).
     */
    public function buildReport(RestockingBiFilterDto $filter): RestockingBiReportDto
    {
        $machineCatalog = $this->repository->fetchMachineCatalog($filter);
        $visitHeaders = $this->repository->fetchVisitHeaders($filter);
        $slotItems = $this->repository->fetchSlotItemsByMachineProduct($filter);
        $productCatalog = $this->repository->fetchProductCatalog($filter);
        $slotItemsByRestocker = $this->repository->fetchSlotItemsByRestocker($filter);
        $thermalRecords = $this->repository->fetchThermalRecords($filter);
        $shiftRows = $this->repository->fetchValidShifts($filter);
        $cashRows = $this->repository->fetchCashCollections($filter);
        $l1Rows = $this->repository->fetchL1Incidents($filter);
        $availableFilters = $this->repository->fetchAvailableFilters($filter);

        $thermalSegmentable = !$filter->isRestockerScoped();

        $commercialTotals = RestockingBiCalculator::totalCommercial($slotItems);
        $thermalTotals = RestockingBiCalculator::totalThermal($thermalRecords);

        $byMachine = RestockingBiCalculator::buildMachineRows(
            $machineCatalog,
            $visitHeaders,
            $slotItems,
            $thermalRecords,
            $thermalSegmentable
        );

        $byProduct = RestockingBiCalculator::buildProductRows($productCatalog, $slotItems);

        $byRestocker = RestockingBiCalculator::buildRestockerRows(
            self::restockerCatalog($availableFilters['restockers'] ?? []),
            $visitHeaders,
            $slotItemsByRestocker,
            $shiftRows,
            $cashRows,
            $l1Rows,
            $filter->hasTerritorialFilter()
        );

        $summary = RestockingBiCalculator::buildSummary(
            $byMachine,
            $byRestocker,
            $commercialTotals,
            $thermalTotals,
            $thermalSegmentable
        );

        $spoilageComparison = RestockingBiCalculator::buildSpoilageComparison(
            $commercialTotals,
            $thermalTotals,
            $thermalSegmentable
        );

        return new RestockingBiReportDto(
            $filter->toArray(),
            $availableFilters,
            $summary,
            $spoilageComparison,
            RestockingBiCalculator::buildCharts(
                $byMachine,
                $byProduct,
                $spoilageComparison,
                $thermalSegmentable
            ),
            $byMachine,
            $byProduct,
            $byRestocker
        );
    }

    /**
     * Adapta el catálogo de selectores al contrato de `buildRestockerRows()`.
     *
     * `available_filters.restockers[]` publica `id`; el calculador espera
     * `restocker_id`. La conversión se hace aquí, en el borde del caso de uso,
     * para no duplicar el DTO ni alterar el contrato JSON (`QA-4`).
     *
     * @param list<array{id: int, operator_code: string|null, name: string, is_active: bool}> $restockers
     *
     * @return list<array{restocker_id: int, operator_code: string|null, name: string, is_active: bool}>
     */
    private static function restockerCatalog(array $restockers): array
    {
        return array_map(static fn(array $row): array => [
            'restocker_id' => (int)$row['id'],
            'operator_code' => $row['operator_code'] ?? null,
            'name' => (string)($row['name'] ?? ''),
            'is_active' => (bool)($row['is_active'] ?? true),
        ], $restockers);
    }
}
