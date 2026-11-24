<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;

/**
 * RestockingBiReportDto
 *
 * Proyección inmutable del informe BI de reposición, roturas de stock y mermas
 * comerciales (Módulo 38; contrato técnico §3.1, `RF-01` a `RF-06`).
 *
 * El DTO no calcula nada: transporta los siete bloques que
 * `RestockingBiCalculator` ha ensamblado y `RestockingBiAnalyticsService`
 * publica, de modo que el controlador solo tenga que serializarlos y
 * `RestockingBiExportService` pueda reutilizar exactamente las mismas cifras que
 * la pantalla. Dos cálculos distintos para el JSON y el CSV acabarían
 * discrepando, y el CSV es un documento de auditoría (`Art. III.3`).
 *
 * Bloques, en el orden del contrato:
 *
 *   1. `filters`: ventana y filtros aplicados (`RestockingBiFilterDto`).
 *   2. `available_filters`: catálogos de zonas, sedes y reponedores (`QA-4`).
 *   3. `summary`: KPIs globales con tasas sin denominador en `null` (`C5`).
 *   4. `spoilage_comparison`: FEFO / dañado / total comercial / térmica APPCC (`D2`).
 *   5. `charts`: series ya ordenadas para los dos gráficos Chart.js (`D5`).
 *   6. `by_machine`: ranking de máquinas con prioridad sanitaria (`QA-2`, Art. II.2).
 *   7. `by_product` y `by_restocker`: rotación por SKU y rendimiento individual (`RF-04`, `RF-05`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades `readonly`, sin dependencias.
 * Dualismo Lingüístico: código en inglés, claves del contrato en castellano.
 */
final class RestockingBiReportDto implements JsonSerializable
{
    /**
     * @param array{from: string, to: string, zone_id: int|null, location_id: int|null, restocker_id: int|null, timezone: string} $filters
     * @param array{zones: list<array<string, mixed>>, locations: list<array<string, mixed>>, restockers: list<array<string, mixed>>} $availableFilters
     * @param array<string, int|float|null> $summary
     * @param array<string, mixed> $spoilageComparison
     * @param array<string, mixed> $charts
     * @param list<array<string, mixed>> $byMachine
     * @param list<array<string, mixed>> $byProduct
     * @param list<array<string, mixed>> $byRestocker
     */
    public function __construct(
        private readonly array $filters,
        private readonly array $availableFilters,
        private readonly array $summary,
        private readonly array $spoilageComparison,
        private readonly array $charts,
        private readonly array $byMachine,
        private readonly array $byProduct,
        private readonly array $byRestocker
    ) {
    }

    /**
     * @return array{from: string, to: string, zone_id: int|null, location_id: int|null, restocker_id: int|null, timezone: string}
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array{zones: list<array<string, mixed>>, locations: list<array<string, mixed>>, restockers: list<array<string, mixed>>}
     */
    public function availableFilters(): array
    {
        return $this->availableFilters;
    }

    /**
     * @return array<string, int|float|null>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function spoilageComparison(): array
    {
        return $this->spoilageComparison;
    }

    /**
     * @return array<string, mixed>
     */
    public function charts(): array
    {
        return $this->charts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byMachine(): array
    {
        return $this->byMachine;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byProduct(): array
    {
        return $this->byProduct;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byRestocker(): array
    {
        return $this->byRestocker;
    }

    /**
     * Payload completo del contrato §3.1, bloque a bloque y en su orden.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'available_filters' => $this->availableFilters,
            'summary' => $this->summary,
            'spoilage_comparison' => $this->spoilageComparison,
            'charts' => $this->charts,
            'by_machine' => $this->byMachine,
            'by_product' => $this->byProduct,
            'by_restocker' => $this->byRestocker,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
