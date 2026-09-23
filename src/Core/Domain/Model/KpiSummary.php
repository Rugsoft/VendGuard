<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use JsonSerializable;

/**
 * KpiSummary
 * 
 * DTO inmutable que estructura el resumen ejecutivo de indicadores clave de rendimiento (KPIs)
 * y el estado de cumplimiento de los acuerdos de nivel de servicio (SLA).
 * 
 * Modela las respuestas de RF-03 (EARS 3.1, 3.2) y define umbrales fijos:
 * - 4.0 horas para máquinas de alimentos perecederos (Art. II).
 * - 24.0 horas para el parque general.
 */
class KpiSummary implements JsonSerializable
{
    public const SLA_PERISHABLE_HOURS = 4.0;
    public const SLA_GENERAL_HOURS    = 24.0;

    private MetricFilter $filter;
    private MttrMetric $mttrGlobal;
    private ?MttrMetric $mttrPreviousPeriod;
    private ?float $trendPercentage;
    private int $totalTicketsCreated;
    private int $totalTicketsResolved;
    private float $resolutionRatePercentage;
    private int $activeBacklog;
    private int $criticalSlaBreaches;
    private ?MttrMetric $mttrPerishable;

    public function __construct(
        MetricFilter $filter,
        MttrMetric $mttrGlobal,
        ?MttrMetric $mttrPreviousPeriod,
        ?float $trendPercentage,
        int $totalTicketsCreated,
        int $totalTicketsResolved,
        float $resolutionRatePercentage,
        int $activeBacklog,
        int $criticalSlaBreaches,
        ?MttrMetric $mttrPerishable = null
    ) {
        $this->filter = $filter;
        $this->mttrGlobal = $mttrGlobal;
        $this->mttrPreviousPeriod = $mttrPreviousPeriod;
        $this->trendPercentage = $trendPercentage;
        $this->totalTicketsCreated = $totalTicketsCreated;
        $this->totalTicketsResolved = $totalTicketsResolved;
        $this->resolutionRatePercentage = $resolutionRatePercentage;
        $this->activeBacklog = $activeBacklog;
        $this->criticalSlaBreaches = $criticalSlaBreaches;
        $this->mttrPerishable = $mttrPerishable ?? MttrMetric::noData();
    }

    public function getFilter(): MetricFilter
    {
        return $this->filter;
    }

    public function getMttrGlobal(): MttrMetric
    {
        return $this->mttrGlobal;
    }

    public function getMttrPreviousPeriod(): ?MttrMetric
    {
        return $this->mttrPreviousPeriod;
    }

    public function getTrendPercentage(): ?float
    {
        return $this->trendPercentage;
    }

    public function getTotalTicketsCreated(): int
    {
        return $this->totalTicketsCreated;
    }

    public function getTotalTicketsResolved(): int
    {
        return $this->totalTicketsResolved;
    }

    public function getResolutionRatePercentage(): float
    {
        return $this->resolutionRatePercentage;
    }

    public function getActiveBacklog(): int
    {
        return $this->activeBacklog;
    }

    public function getCriticalSlaBreaches(): int
    {
        return $this->criticalSlaBreaches;
    }

    public function getMttrPerishable(): MttrMetric
    {
        return $this->mttrPerishable;
    }

    /**
     * Evalúa el cumplimiento de los acuerdos de nivel de servicio (SLA).
     * 
     * @return array<string, array<string, mixed>>
     */
    public function getSlaAlerts(): array
    {
        $perishableHours = $this->mttrPerishable->getHours();
        $isPerishableBreached = ($perishableHours !== null && $perishableHours > self::SLA_PERISHABLE_HOURS);
        $perishableStatus = ($perishableHours === null) ? 'NO_DATA' : ($isPerishableBreached ? 'BREACHED' : 'COMPLIANT');

        $generalHours = $this->mttrGlobal->getHours();
        $isGeneralBreached = ($generalHours !== null && $generalHours > self::SLA_GENERAL_HOURS);
        $generalStatus = ($generalHours === null) ? 'NO_DATA' : ($isGeneralBreached ? 'BREACHED' : 'COMPLIANT');

        return [
            'perishable_food' => [
                'sla_target_hours' => self::SLA_PERISHABLE_HOURS,
                'current_mttr_hours' => $perishableHours,
                'is_breached' => $isPerishableBreached,
                'status' => $perishableStatus,
            ],
            'general' => [
                'sla_target_hours' => self::SLA_GENERAL_HOURS,
                'current_mttr_hours' => $generalHours,
                'is_breached' => $isGeneralBreached,
                'status' => $generalStatus,
            ],
        ];
    }

    public function toArray(): array
    {
        return [
            'period' => $this->filter->toArray(),
            'kpis' => [
                'mttr_global_minutes' => $this->mttrGlobal->getMinutes(),
                'mttr_global_formatted' => $this->mttrGlobal->getFormatted(),
                'mttr_global_hours' => $this->mttrGlobal->getHours(),
                'mttr_previous_period_minutes' => $this->mttrPreviousPeriod?->getMinutes(),
                'mttr_trend_percentage' => $this->trendPercentage,
                'total_tickets_created' => $this->totalTicketsCreated,
                'total_tickets_resolved' => $this->totalTicketsResolved,
                'resolution_rate_percentage' => $this->resolutionRatePercentage,
                'active_backlog' => $this->activeBacklog,
                'critical_sla_breaches' => $this->criticalSlaBreaches,
            ],
            'sla_alerts' => $this->getSlaAlerts(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
