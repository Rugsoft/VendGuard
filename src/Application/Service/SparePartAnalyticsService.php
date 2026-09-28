<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;

/**
 * SparePartAnalyticsService
 * 
 * Servicio de Aplicación responsable de generar el cuadro analítico de fiabilidad del parque,
 * consolidación de costes por modelo/sede, detección de fallos recurrentes (> 3 en 90 días)
 * y exportación tabular en formato plano CSV UTF-8 (RF-REP-08, RF-REP-09, Dogma Vanilla).
 */
class SparePartAnalyticsService
{
    public const UTF8_BOM = "\xEF\xBB\xBF";
    public const DEFAULT_PERIOD_DAYS = 90;
    public const DEFAULT_CHRONIC_THRESHOLD = 3;

    private IncidentReplacedPartRepositoryInterface $replacedPartRepo;
    private ?AuditLogger $auditLogger;

    public function __construct(
        IncidentReplacedPartRepositoryInterface $replacedPartRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->replacedPartRepo = $replacedPartRepo;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Genera el cuadro analítico integral de consumo de repuestos, costes y alertas (RF-REP-08).
     *
     * @param int|null $periodDays Ventana temporal en días naturales (defecto 90 días).
     * @param int $rankingLimit Número máximo de repuestos en el ranking de sustituciones (defecto 10).
     * @return array<string, mixed>
     */
    public function getAnalytics(?int $periodDays = self::DEFAULT_PERIOD_DAYS, int $rankingLimit = 10): array
    {
        $days = ($periodDays !== null && $periodDays > 0) ? $periodDays : self::DEFAULT_PERIOD_DAYS;

        $topReplacedParts = $this->replacedPartRepo->getTopReplacedParts($days, $rankingLimit);
        $costsByMachineModel = $this->replacedPartRepo->getCostSummaryByMachineModel($days);
        $costsByLocation = $this->replacedPartRepo->getCostSummaryByLocation($days);
        $chronicAlerts = $this->replacedPartRepo->findChronicFailureAlerts($days, self::DEFAULT_CHRONIC_THRESHOLD);

        // Consolidación de totales agregados
        $totalPartsReplaced = 0;
        $totalPartsCost = 0.00;

        if (!empty($costsByMachineModel)) {
            foreach ($costsByMachineModel as $modelSummary) {
                $totalPartsReplaced += (int)($modelSummary['units_replaced'] ?? 0);
                $totalPartsCost += (float)($modelSummary['total_cost'] ?? 0.0);
            }
        } elseif (!empty($topReplacedParts)) {
            foreach ($topReplacedParts as $partSummary) {
                $totalPartsReplaced += (int)($partSummary['units_installed'] ?? 0);
                $totalPartsCost += (float)($partSummary['accumulated_cost'] ?? 0.0);
            }
        }

        return [
            'period_days'            => $days,
            'total_parts_replaced'   => $totalPartsReplaced,
            'total_parts_cost'       => round($totalPartsCost, 2),
            'top_replaced_parts'     => $topReplacedParts,
            'costs_by_machine_model' => $costsByMachineModel,
            'costs_by_location'      => $costsByLocation,
            'chronic_failure_alerts' => $chronicAlerts,
        ];
    }

    /**
     * Detecta alertas de componentes con fallo crónico o recurrente en el parque (RF-REP-08).
     * Dispara advertencia cuando una misma pieza supera threshold sustituciones en windowDays días.
     *
     * @param int $windowDays Ventana móvil en días naturales (defecto 90).
     * @param int $threshold Umbral mínimo de sustituciones para considerar fallo recurrente (defecto 3).
     * @return array<int, array<string, mixed>>
     */
    public function detectChronicFailures(
        int $windowDays = self::DEFAULT_PERIOD_DAYS,
        int $threshold = self::DEFAULT_CHRONIC_THRESHOLD
    ): array {
        return $this->replacedPartRepo->findChronicFailureAlerts($windowDays, $threshold);
    }

    /**
     * Genera la exportación en formato plano CSV de todos los consumos históricos o filtrados (RF-REP-09).
     * Incorpora el Byte Order Mark (BOM) UTF-8 para garantizar apertura inmediata en Microsoft Excel.
     *
     * @param int|null $periodDays Ventana temporal en días naturales (null para todo el histórico).
     * @param array{id: int|null, role: string, name: string}|null $actor Metadatos de usuario para auditoría.
     * @return string Contenido plano CSV estructurado con BOM UTF-8.
     */
    public function exportCsv(?int $periodDays = null, ?array $actor = null): string
    {
        $records = $this->replacedPartRepo->findAllForExport($periodDays);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::UTF8_BOM;
        }

        // 1. Cabecera oficial contractual (contrato 5.3)
        $headers = [
            'Fecha',
            'Codigo_Intervencion',
            'Tipo_Intervencion',
            'Codigo_Maquina',
            'Modelo_Maquina',
            'Sede',
            'Codigo_Pieza',
            'Nombre_Pieza',
            'Categoria',
            'Unidades',
            'Coste_Unitario_EUR',
            'Coste_Total_EUR',
            'Destino_Retirado',
            'Codigo_Tecnico',
            'Notas',
        ];
        fputcsv($handle, $headers, ',');

        // 2. Líneas de detalle
        foreach ($records as $row) {
            fputcsv($handle, [
                $row['fecha'] ?? '',
                $row['codigo_intervencion'] ?? 'N/A',
                $row['tipo_intervencion'] ?? '',
                $row['codigo_maquina'] ?? '',
                $row['modelo_maquina'] ?? '',
                $row['sede'] ?? '',
                $row['codigo_pieza'] ?? 'OUT_OF_CATALOG',
                $row['nombre_pieza'] ?? '',
                $row['categoria'] ?? 'OTHER',
                (string)($row['unidades'] ?? 0),
                number_format((float)($row['coste_unitario_eur'] ?? 0.0), 2, '.', ''),
                number_format((float)($row['coste_total_eur'] ?? 0.0), 2, '.', ''),
                $row['destino_retirado'] ?? '',
                $row['codigo_tecnico'] ?? 'N/A',
                $row['notas'] ?? '',
            ], ',');
        }

        rewind($handle);
        $csvBody = stream_get_contents($handle);
        fclose($handle);

        if ($this->auditLogger !== null && $actor !== null && !empty($actor['id'])) {
            $this->auditLogger->logUserEvent(
                userId: (int)$actor['id'],
                action: 'EXPORT_SPARE_PARTS_CSV',
                actor: $actor,
                previousState: null,
                newState: [
                    'period_days'   => $periodDays,
                    'records_count' => count($records),
                ],
                metadata: [
                    'exported_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        return self::UTF8_BOM . ($csvBody !== false ? $csvBody : '');
    }

    /**
     * Genera el nombre estandarizado de fichero para la descarga del informe CSV.
     * Estructura: repuestos_intervenciones_YYYYMMDD_HHMM.csv
     *
     * @param string|null $timestamp Marca de tiempo (ej. '20260928_1430') o null para la fecha y hora actual.
     * @return string
     */
    public function generateCsvFilename(?string $timestamp = null): string
    {
        $timeStr = $timestamp ?? date('Ymd_Hi');
        return "repuestos_intervenciones_{$timeStr}.csv";
    }
}
