<?php

declare(strict_types=1);

/**
 * MetricsCalculationServiceTest
 * 
 * Suite de pruebas unitarias para el servicio de cálculo analítico MetricsCalculationService (T-MET-07).
 * Valida de forma rigurosa:
 * 1. Orquestación de KPIs (MTTR global, total tickets creados/resueltos, backlog).
 * 2. Cálculo determinista de tendencias porcentuales (reducción y aumento de tiempos).
 * 3. Cálculo formal de la tasa de resolución: (resueltos / creados) * 100.
 * 4. Evaluación de alertas de SLA para perecederos (umbral 4h, Art. II) y parque general (24h).
 * 5. Consolidación de desgloses multidimensionales (sedes, técnicos, máquinas, averías).
 * 6. Métrica de primera respuesta del técnico hasta IN_PROGRESS (RF-04, EARS 4.3).
 * 
 * Cumple con RF-01, RF-03, RF-04, RNF-01 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Core\Domain\Model\KpiSummary;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\MttrMetric;
use VendGuard\Core\Domain\Repository\MetricsRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de MetricsCalculationService (T-MET-07)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

/**
 * Repositorio de prueba simulado en memoria para aislamiento determinista.
 */
class TestMetricsRepository implements MetricsRepositoryInterface
{
    public ?int $globalMttrMinutes = 195;
    public ?int $previousMttrMinutes = 220;
    public ?int $perishableMttrMinutes = 110;
    public int $createdTickets = 48;
    public int $resolvedTickets = 42;
    public int $activeBacklog = 6;
    public int $criticalBreaches = 0;
    public ?int $techFirstResponseMinutes = 45;

    public function getGlobalMttr(MetricFilter $filter): MttrMetric
    {
        if (str_starts_with($filter->getPeriodKey(), 'previous_')) {
            return MttrMetric::fromMinutes($this->previousMttrMinutes);
        }
        return MttrMetric::fromMinutes($this->globalMttrMinutes);
    }

    public function getPerishableMttr(MetricFilter $filter): MttrMetric
    {
        return MttrMetric::fromMinutes($this->perishableMttrMinutes);
    }

    public function countCreatedTickets(MetricFilter $filter): int
    {
        return $this->createdTickets;
    }

    public function countResolvedTickets(MetricFilter $filter): int
    {
        return $this->resolvedTickets;
    }

    public function countActiveBacklog(): int
    {
        return $this->activeBacklog;
    }

    public function countCriticalSlaBreaches(MetricFilter $filter): int
    {
        return $this->criticalBreaches;
    }

    public function getBreakdownByLocation(MetricFilter $filter): array
    {
        return [
            [
                'location_id' => 1,
                'site_code' => 'SEDE-BCN-01',
                'location_name' => 'Hospital del Mar - Edificio Central',
                'is_active' => true,
                'tickets_resolved' => 28,
                'mttr_minutes' => 165,
                'mttr_formatted' => '2h 45m',
                'mttr_hours' => 2.8,
                'sla_target_hours' => 24.0,
                'sla_status' => 'COMPLIANT',
            ],
            [
                'location_id' => 2,
                'site_code' => 'SEDE-BCN-02',
                'location_name' => 'Torre Glòries - Planta 4 Oficinas',
                'is_active' => true,
                'tickets_resolved' => 14,
                'mttr_minutes' => 255,
                'mttr_formatted' => '4h 15m',
                'mttr_hours' => 4.3,
                'sla_target_hours' => 24.0,
                'sla_status' => 'COMPLIANT',
            ]
        ];
    }

    public function getBreakdownByTechnician(MetricFilter $filter): array
    {
        return [
            [
                'technician_id' => 2,
                'technician_name' => 'Jordi Técnico Ruta BCN',
                'is_active' => true,
                'display_name' => 'Jordi Técnico Ruta BCN',
                'tickets_resolved' => 42,
                'mttr_minutes' => 195,
                'mttr_formatted' => '3h 15m',
                'mttr_hours' => 3.3,
            ]
        ];
    }

    public function getBreakdownByMachineType(MetricFilter $filter): array
    {
        return [
            [
                'machine_type' => 'PERISHABLE_FOOD',
                'display_name' => 'Alimentos Perecederos (Sanitario)',
                'is_perishable' => true,
                'tickets_resolved' => 12,
                'mttr_minutes' => 110,
                'mttr_formatted' => '1h 50m',
                'mttr_hours' => 1.8,
                'sla_target_hours' => 4.0,
                'sla_status' => 'COMPLIANT',
            ]
        ];
    }

    public function getBreakdownByCategory(MetricFilter $filter): array
    {
        return [
            [
                'category' => 'TEMPERATURE_COLD',
                'display_name' => 'Cadena de Frío / Refrigeración',
                'tickets_resolved' => 10,
                'mttr_minutes' => 105,
                'mttr_formatted' => '1h 45m',
            ]
        ];
    }

    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array
    {
        $respMin = $this->techFirstResponseMinutes;
        $respFormatted = ($respMin !== null) ? sprintf('%dh %02dm', intdiv($respMin, 60), $respMin % 60) : 'N/A';

        return [
            'technician_id' => $technicianId,
            'my_mttr' => MttrMetric::fromMinutes($this->globalMttrMinutes),
            'total_resolved' => $this->resolvedTickets,
            'current_in_progress' => 2,
            'avg_first_response_minutes' => $respMin,
            'avg_first_response_formatted' => $respFormatted,
        ];
    }
}

try {
    $repo = new TestMetricsRepository();
    $service = new MetricsCalculationService($repo);
    $filter = MetricFilter::fromQueryParams(['period' => 'last_30_days']);

    // -------------------------------------------------------------
    // 1. Resumen de KPIs y Tasa de Resolución (RF-01, RF-03)
    // -------------------------------------------------------------
    echo "--- 1. Resumen de KPIs y Tasa de Resolución ---\n";

    $summary = $service->getKpiSummary($filter);
    assertCondition($summary->getMttrGlobal()->getMinutes() === 195, "1.1 MTTR global en minutos correcto (195 min)");
    assertCondition($summary->getMttrGlobal()->getFormatted() === '3h 15m', "1.2 MTTR global formateado ('3h 15m')");
    assertCondition($summary->getMttrGlobal()->getHours() === 3.3, "1.3 MTTR global horas decimales (3.3h)");
    assertCondition($summary->getTotalTicketsCreated() === 48, "1.4 Conteo de tickets creados (48)");
    assertCondition($summary->getTotalTicketsResolved() === 42, "1.5 Conteo de tickets resueltos (42)");
    assertCondition($summary->getResolutionRatePercentage() === 87.5, "1.6 Tasa de resolución calculada exactamente (87.5%)");
    assertCondition($summary->getActiveBacklog() === 6, "1.7 Backlog activo reportado (6)");

    // -------------------------------------------------------------
    // 2. Cálculo Determinista de Tendencia (EARS 3.1.1)
    // -------------------------------------------------------------
    echo "\n--- 2. Cálculo de Tendencias Porcentuales ---\n";

    // 195 min vs 220 min -> ((195 - 220) / 220) * 100 = -11.3636% -> -11.4%
    assertCondition($summary->getTrendPercentage() === -11.4, "2.1 Tendencia negativa indica mejora/reducción de tiempo (-11.4%)");

    // Caso: Empeoramiento (tiempo actual mayor al previo)
    $currentWorse = MttrMetric::fromMinutes(250);
    $prevBetter = MttrMetric::fromMinutes(200);
    $trendWorse = $service->calculateTrendPercentage($currentWorse, $prevBetter);
    assertCondition($trendWorse === 25.0, "2.2 Tendencia positiva indica aumento de tiempo (+25.0%)");

    // Caso: Sin datos en periodo anterior o actual
    assertCondition($service->calculateTrendPercentage(MttrMetric::noData(), $prevBetter) === null, "2.3 Periodo actual N/A retorna tendencia null");
    assertCondition($service->calculateTrendPercentage($currentWorse, MttrMetric::noData()) === null, "2.4 Periodo previo N/A retorna tendencia null");
    assertCondition($service->calculateTrendPercentage($currentWorse, MttrMetric::fromMinutes(0)) === null, "2.5 Periodo previo 0 min retorna tendencia null");

    // -------------------------------------------------------------
    // 3. Evaluación de Alertas de SLA (EARS 3.2, Artículo II)
    // -------------------------------------------------------------
    echo "\n--- 3. Alertas de Cumplimiento de SLA Fijas ---\n";

    // Escenario 1: Conforme (Perecederos 1.8h <= 4.0h, General 3.3h <= 24.0h)
    $alertsCompliant = $summary->getSlaAlerts();
    assertCondition($alertsCompliant['perishable_food']['status'] === 'COMPLIANT', "3.1 Perecederos cumple SLA objetivo de 4 horas");
    assertCondition($alertsCompliant['perishable_food']['is_breached'] === false, "3.2 is_breached es false en perecederos");
    assertCondition($alertsCompliant['general']['status'] === 'COMPLIANT', "3.3 General cumple SLA de 24 horas");

    // Escenario 2: Incumplimiento de Alimentos Perecederos (> 4.0 horas)
    $repo->perishableMttrMinutes = 300; // 5.0 horas > 4.0 horas
    $summaryBreached = $service->getKpiSummary($filter);
    $alertsBreached = $summaryBreached->getSlaAlerts();
    assertCondition($alertsBreached['perishable_food']['status'] === 'BREACHED', "3.4 Perecederos con 5h activa estado BREACHED");
    assertCondition($alertsBreached['perishable_food']['is_breached'] === true, "3.5 is_breached es true ante retraso en perecederos");

    // Escenario 3: Muestra vacía sin datos (NO_DATA)
    $repo->perishableMttrMinutes = null;
    $summaryNoData = $service->getKpiSummary($filter);
    $alertsNoData = $summaryNoData->getSlaAlerts();
    assertCondition($alertsNoData['perishable_food']['status'] === 'NO_DATA', "3.6 Muestra vacía de perecederos reporta NO_DATA");
    assertCondition($alertsNoData['perishable_food']['current_mttr_hours'] === null, "3.7 Horas de perecederos null en NO_DATA");

    // -------------------------------------------------------------
    // 4. Desgloses Multidimensionales (RF-02)
    // -------------------------------------------------------------
    echo "\n--- 4. Desgloses Multidimensionales ---\n";

    $breakdown = $service->getBreakdown($filter);
    assertCondition(isset($breakdown['by_location'], $breakdown['by_technician'], $breakdown['by_machine_type'], $breakdown['by_category']), "4.1 Desglose incluye las 4 dimensiones requeridas");
    assertCondition(count($breakdown['by_location']) === 2, "4.2 Dos sedes desglosadas");
    assertCondition($breakdown['by_location'][0]['sla_status'] === 'COMPLIANT', "4.3 Sede 1 cumple SLA");
    assertCondition($breakdown['by_machine_type'][0]['is_perishable'] === true, "4.4 Máquinas perecederas etiquetadas con is_perishable=true");

    // -------------------------------------------------------------
    // 5. Autoconsulta del Técnico y Primera Respuesta (RF-04)
    // -------------------------------------------------------------
    echo "\n--- 5. Autoconsulta del Técnico y Primera Respuesta ---\n";

    $techData = $service->getTechnicianMetrics(2, $filter);
    assertCondition($techData['technician']['id'] === 2, "5.1 ID del técnico verificado");
    assertCondition($techData['metrics']['my_mttr_formatted'] === '3h 15m', "5.2 MTTR individual formateado ('3h 15m')");
    assertCondition($techData['metrics']['total_resolved_tickets'] === 42, "5.3 Total de incidencias resueltas por el técnico (42)");
    assertCondition($techData['metrics']['current_in_progress_tickets'] === 2, "5.4 Incidencias actualmente en curso (2)");
    assertCondition($techData['metrics']['avg_first_response_minutes'] === 45, "5.5 Tiempo medio de primera respuesta en minutos (45 min)");
    assertCondition($techData['metrics']['avg_first_response_formatted'] === '0h 45m', "5.6 Tiempo medio de primera respuesta formateado ('0h 45m')");

    // -------------------------------------------------------------
    // 6. Serialización JSON del Resumen Ejecutivo (KpiSummary)
    // -------------------------------------------------------------
    echo "\n--- 6. Serialización JSON Completa ---\n";

    $json = json_encode($summary);
    assertCondition(is_string($json), "6.1 Resumen serializable a JSON");
    assertCondition(str_contains($json, '"mttr_global_formatted":"3h 15m"'), "6.2 JSON incluye MTTR global");
    assertCondition(str_contains($json, '"resolution_rate_percentage":87.5'), "6.3 JSON incluye tasa de resolución");
    assertCondition(str_contains($json, '"sla_alerts"'), "6.4 JSON incluye bloque de alertas SLA");

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions}/{$assertions} aserciones pasadas exitosamente [100% VERDE]\n";
    echo "======================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
