<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Model\KpiSummary;
use VendGuard\Core\Domain\Model\MachineType;
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
 * Descuento de pausas PENDING_INFO (Módulo 11, RF-03.1)
 * ----------------------------------------------------
 * Este servicio es el ÚNICO punto donde se aplica el descuento del tiempo de espera
 * imputable a la sede sobre el MTTR. El repositorio entrega la muestra bruta en
 * segundos (`getGrossMttrSeconds()`) y la media de segundos en `PENDING_INFO`
 * (`getAveragePendingInfoSeconds()`); aquí se restan, se convierten a minutos y se
 * decide el cumplimiento de SLA sobre el tiempo NETO contractual. Así el reloj que
 * se detiene mientras el técnico espera en la puerta no penaliza a la operadora, y
 * la operación aritmética (bruto − pausas, nunca negativo) es verificable de forma
 * determinista sin depender de la base de datos.
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
        // 1. MTTR global NETO del periodo actual (bruto 24/7 menos pausas de sede, RF-03.1)
        $mttrGlobal = $this->calculateNetMttr(
            $this->metricsRepo->getGrossMttrSeconds($filter),
            $this->metricsRepo->getAveragePendingInfoSeconds($filter)
        );

        // 2. MTTR global NETO del periodo equivalente anterior (misma duración en días)
        $previousFilter = $filter->getPreviousEquivalentPeriod();
        $mttrPrevious = $this->calculateNetMttr(
            $this->metricsRepo->getGrossMttrSeconds($previousFilter),
            $this->metricsRepo->getAveragePendingInfoSeconds($previousFilter)
        );

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

        // 6. Backlog activo total y violaciones de SLA (netas de pausa dentro del SQL)
        $activeBacklog = $this->metricsRepo->countActiveBacklog();
        $criticalBreaches = $this->metricsRepo->countCriticalSlaBreaches($filter);

        // 7. MTTR NETO de máquinas de alimentos perecederos (Art. II)
        $mttrPerishable = $this->calculateNetMttr(
            $this->metricsRepo->getGrossMttrSeconds($filter, MachineType::PERISHABLE_FOOD),
            $this->metricsRepo->getAveragePendingInfoSeconds($filter, MachineType::PERISHABLE_FOOD)
        );

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
     * Cada fila entrega su MTTR neto contractual, ya descontadas las pausas imputables a la sede.
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
            'by_location'     => $this->discountBreakdownRows($this->metricsRepo->getBreakdownByLocation($filter)),
            'by_technician'   => $this->discountBreakdownRows($this->metricsRepo->getBreakdownByTechnician($filter)),
            'by_machine_type' => $this->discountBreakdownRows($this->metricsRepo->getBreakdownByMachineType($filter)),
            'by_category'     => $this->discountBreakdownRows($this->metricsRepo->getBreakdownByCategory($filter)),
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

        $mttr = $this->calculateNetMttr(
            $raw['my_gross_mttr_seconds'] ?? null,
            (int)($raw['my_pending_info_seconds'] ?? 0)
        );

        return [
            'technician' => [
                'id' => $technicianId,
            ],
            'period' => $filter->toArray(),
            'metrics' => [
                'my_mttr_minutes' => $mttr->getMinutes(),
                'my_mttr_formatted' => $mttr->getFormatted(),
                'my_mttr_hours' => $mttr->getHours(),
                'total_resolved_tickets' => $raw['total_resolved'],
                'current_in_progress_tickets' => $raw['current_in_progress'],
                'avg_first_response_minutes' => $raw['avg_first_response_minutes'],
                'avg_first_response_formatted' => $raw['avg_first_response_formatted'],
            ],
        ];
    }

    /**
     * Aplica el descuento de pausas PENDING_INFO sobre una muestra de resolución (RF-03.1).
     * 
     * Devuelve `MttrMetric` sin datos cuando la muestra está vacía, de modo que el
     * consumidor distingue "no hubo tickets resueltos" de "el tiempo neto fue cero".
     * 
     * @param int|null $grossSeconds Segundos medios brutos de resolución (null si no hay muestra).
     * @param int $pendingInfoSeconds Media de segundos en PENDING_INFO descontables por ticket.
     * @return MttrMetric
     */
    public function calculateNetMttr(?int $grossSeconds, int $pendingInfoSeconds): MttrMetric
    {
        if ($grossSeconds === null) {
            return MttrMetric::noData();
        }

        $netSeconds = $this->calculateNetResolutionSeconds($grossSeconds, $pendingInfoSeconds);

        return MttrMetric::fromMinutes((int)round($netSeconds / 60.0));
    }

    /**
     * Segundos NETOS de resolución: tiempo bruto menos pausas imputables a la sede (RF-03.1).
     * 
     * Nunca devuelve un valor negativo: una pausa mayor que la vida del expediente sólo
     * puede venir de una anomalía de reloj, que se satura a cero (EARS 1.7).
     * 
     * @param int $grossSeconds
     * @param int $pendingInfoSeconds
     * @return int
     */
    public function calculateNetResolutionSeconds(int $grossSeconds, int $pendingInfoSeconds): int
    {
        return max(0, max(0, $grossSeconds) - max(0, $pendingInfoSeconds));
    }

    /**
     * Calcula la variación porcentual de tendencia del MTTR entre dos periodos (Algoritmo 4.2).
     * 
     * Compara siempre tiempo NETO contra tiempo NETO: descontar la pausa sólo en un lado
     * falsearía la tendencia. Devuelve null si no hay datos en alguno de los periodos o si el
     * periodo anterior fue 0 minutos. Un valor negativo (ej. -11.4%) indica mejora operativa.
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

    /**
     * Compone el MTTR neto de cada fila de desglose a partir de su muestra bruta en segundos
     * (RF-03.1) y recalcula el estado de SLA sobre el tiempo neto contractual.
     * 
     * Las claves de salida son las mismas que ya consumen el informe ejecutivo y las tablas
     * del panel: el descuento no cambia la forma del contrato, sólo el valor y el veredicto.
     * 
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function discountBreakdownRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $mttr = $this->calculateNetMttr(
                $row['mttr_gross_seconds'] ?? null,
                (int)($row['mttr_pending_info_seconds'] ?? 0)
            );

            unset($row['mttr_gross_seconds'], $row['mttr_pending_info_seconds']);

            $row['mttr_minutes'] = $mttr->getMinutes();
            $row['mttr_formatted'] = $mttr->getFormatted();
            $row['mttr_hours'] = $mttr->getHours();

            if (array_key_exists('sla_target_hours', $row)) {
                $targetHours = (float)$row['sla_target_hours'];
                $row['sla_status'] = !$mttr->hasData()
                    ? 'NO_DATA'
                    : (($mttr->getHours() > $targetHours) ? 'BREACHED' : 'COMPLIANT');
            }

            $rows[$index] = $row;
        }

        return $rows;
    }
}
