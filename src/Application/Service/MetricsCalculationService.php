<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Model\KpiSummary;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\MttrMetric;
use VendGuard\Core\Domain\Repository\MetricsRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMetricsRepository;

/**
 * MetricsCalculationService
 * 
 * Servicio de Aplicación para la agregación, orquestación y cálculo analítico de métricas.
 * Da cumplimiento a RF-01, RF-02, RF-03 (EARS 3.1, 3.2) y RF-04 (EARS 4.3).
 * 
 * Orquesta:
 * 1. Cálculo de KPIs globales (MTTR, comparación de tendencia porcentual con periodo anterior).
 * 2. Tasa de resolución formal: (Tickets Resueltos / Tickets Creados) * 100.
 * 3. Backlog activo y alertas de SLA fijas (4h perecederos, 24h general).
 * 4. Desgloses multidimensionales por sede histórica, técnico resolutor, tipología de máquina y avería.
 * 5. Autoconsulta de métricas individuales para el técnico autenticado.
 * 
 * Cumple con el Dogma Vanilla (PHP 8.2+ puro) y el principio de Clean Architecture.
 */
class MetricsCalculationService
{
    private MetricsRepositoryInterface $metricsRepo;

    public function __construct(?MetricsRepositoryInterface $metricsRepo = null)
    {
        $this->metricsRepo = $metricsRepo ?? new PdoMetricsRepository();
    }

    /**
     * Obtiene el resumen de KPIs globales y alertas de SLA para el filtro dado (RF-01, RF-03).
     * 
     * @param MetricFilter $filter
     * @return KpiSummary
     */
    public function getKpiSummary(MetricFilter $filter): KpiSummary
    {
        // 1. MTTR Global del periodo actual
        $mttrGlobal = $this->metricsRepo->getGlobalMttr($filter);

        // 2. MTTR Global del periodo equivalente anterior (misma duración en días)
        $previousFilter = $filter->getPreviousEquivalentPeriod();
        $mttrPrevious = $this->metricsRepo->getGlobalMttr($previousFilter);

        // 3. Cálculo de variación porcentual de tendencia (EARS 3.1.1, Algoritmo 4.2)
        $trendPercentage = $this->calculateTrendPercentage($mttrGlobal, $mttrPrevious);

        // 4. Conteo de tickets creados y resueltos en el periodo
        $createdTickets = $this->metricsRepo->countCreatedTickets($filter);
        $resolvedTickets = $this->metricsRepo->countResolvedTickets($filter);

        // 5. Tasa de resolución formal (EARS 3.1.3): (Resueltos / Creados) * 100
        $resolutionRate = 0.0;
        if ($createdTickets > 0) {
            $resolutionRate = round(($resolvedTickets / $createdTickets) * 100.0, 1);
        }

        // 6. Backlog activo total y violaciones de SLA
        $activeBacklog = $this->metricsRepo->countActiveBacklog();
        $criticalBreaches = $this->metricsRepo->countCriticalSlaBreaches($filter);

        // 7. MTTR específico de máquinas de alimentos perecederos (Art. II)
        $mttrPerishable = $this->metricsRepo->getPerishableMttr($filter);

        return new KpiSummary(
            filter: $filter,
            mttrGlobal: $mttrGlobal,
            mttrPreviousPeriod: $mttrPrevious,
            trendPercentage: $trendPercentage,
            totalTicketsCreated: $createdTickets,
            totalTicketsResolved: $resolvedTickets,
            resolutionRatePercentage: $resolutionRate,
            activeBacklog: $activeBacklog,
            criticalSlaBreaches: $criticalBreaches,
            mttrPerishable: $mttrPerishable
        );
    }

    /**
     * Obtiene el desglose multidimensional analítico por dimensiones combinables (RF-02).
     * 
     * @param MetricFilter $filter
     * @return array{
     *   by_location: list<array<string, mixed>>,
     *   by_technician: list<array<string, mixed>>,
     *   by_machine_type: list<array<string, mixed>>,
     *   by_category: list<array<string, mixed>>
     * }
     */
    public function getBreakdown(MetricFilter $filter): array
    {
        return [
            'by_location'     => $this->metricsRepo->getBreakdownByLocation($filter),
            'by_technician'   => $this->metricsRepo->getBreakdownByTechnician($filter),
            'by_machine_type' => $this->metricsRepo->getBreakdownByMachineType($filter),
            'by_category'     => $this->metricsRepo->getBreakdownByCategory($filter),
        ];
    }

    /**
     * Obtiene las métricas individuales para la vista privada del técnico autenticado (RF-04).
     * 
     * @param int $technicianId
     * @param MetricFilter $filter
     * @return array<string, mixed>
     */
    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array
    {
        $raw = $this->metricsRepo->getTechnicianMetrics($technicianId, $filter);

        return [
            'technician' => [
                'id' => $technicianId,
            ],
            'period' => $filter->toArray(),
            'metrics' => [
                'my_mttr_minutes' => $raw['my_mttr']->getMinutes(),
                'my_mttr_formatted' => $raw['my_mttr']->getFormatted(),
                'my_mttr_hours' => $raw['my_mttr']->getHours(),
                'total_resolved_tickets' => $raw['total_resolved'],
                'current_in_progress_tickets' => $raw['current_in_progress'],
                'avg_first_response_minutes' => $raw['avg_first_response_minutes'],
                'avg_first_response_formatted' => $raw['avg_first_response_formatted'],
            ],
        ];
    }

    /**
     * Calcula la variación porcentual de tendencia del MTTR entre dos periodos (Algoritmo 4.2).
     * Devuelve null si no hay datos en alguno de los periodos o si el periodo anterior fue 0 minutos.
     * Un valor negativo (ej. -11.4%) indica una mejora operativa (reducción del tiempo medio).
     * 
     * @param MttrMetric $current
     * @param MttrMetric $previous
     * @return float|null
     */
    public function calculateTrendPercentage(MttrMetric $current, MttrMetric $previous): ?float
    {
        $currentMin = $current->getMinutes();
        $prevMin = $previous->getMinutes();

        if ($currentMin === null || $prevMin === null || $prevMin === 0) {
            return null;
        }

        $variation = (($currentMin - $prevMin) / (float)$prevMin) * 100.0;
        return round($variation, 1);
    }
}
