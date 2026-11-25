<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Application\DTO\RestockingBiReportDto;
use VendGuard\Core\Domain\Model\AuditEvent;

/**
 * RestockingBiExportService
 *
 * Exportación CSV multi-sección del Cuadro de Mando de Reposición (Módulo 38;
 * `RF-06.3`, `D5`, `C5`, `QA-1`, Art. III.3 y Art. V.4).
 *
 * Reutiliza el mismo `RestockingBiReportDto` que publica la API: el CSV es un
 * documento de auditoría y no puede discrepar del JSON que vio Coordinación.
 *
 * Garantías que concentra:
 *
 *   1. **Excel español:** BOM UTF-8 (`\xEF\xBB\xBF`) y separador `;`.
 *   2. **Cinco secciones siempre presentes,** incluso sin filas operativas:
 *      resumen y filtros, comparativa de mermas, máquinas, productos y
 *      reponedores. Una descarga de un periodo vacío sigue siendo válida (`C5`).
 *   3. **Confidencialidad Art. V.4:** aviso interno en la primera sección; los
 *      ratios y costes solo los ve `COORDINATOR`.
 *   4. **Anti-fórmulas:** toda celda que empiece por `=`, `+`, `-`, `@`, tabulador
 *      o retorno de carro se prefija con `'` antes de citarse.
 *   5. **Fallbacks:** `Sin zona` para una sede sin demarcación y `—` para
 *      `operator_code`, motivos o tasas sin denominador (`QA-A3`, `C5`).
 *   6. **Auditoría (`QA-1`):** un `EXPORT_RESTOCKING_BI_CSV` bajo
 *      `ENTITY_MACHINE_RESTOCK_VISIT` con `entity_id = 0`, los filtros aplicados
 *      y la marca `Europe/Madrid`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
final class RestockingBiExportService
{
    /** Marca UTF-8 obligatoria para que Excel respete los acentos. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /** Aviso que identifica el documento como interno (Art. V.4). */
    public const CONFIDENTIALITY_NOTICE = 'DOCUMENTO INTERNO DE COORDINACIÓN — CONFIDENCIAL ART. V.4: PROHIBIDA SU DISTRIBUCIÓN A SEDES CLIENTE';

    /** Acción inmutable del visor de auditoría (`QA-1`). */
    public const ACTION_EXPORT = 'EXPORT_RESTOCKING_BI_CSV';

    /** Separador de columnas del contrato. */
    public const DELIMITER = ';';

    /** Relleno de campos anulables (`QA-A3`). */
    public const NULL_PLACEHOLDER = '—';

    /** Etiqueta de una sede sin zona operativa (`QA-A3`). */
    public const NO_ZONE_LABEL = 'Sin zona';

    public function __construct(
        private readonly RestockingBiAnalyticsService $analyticsService,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Genera la descarga del filtro activo y deja su rastro de auditoría.
     *
     * @param array{id?: int|string|null, role?: string, name?: string}|null $actor
     *        Coordinador autenticado. Sin actor identificable no se inventa un
     *        evento: el controlador siempre lo entrega (`T-RBI-04`).
     */
    public function exportCsv(RestockingBiFilterDto $filter, ?array $actor = null): string
    {
        $report = $this->analyticsService->buildReport($filter);
        $exportedAt = self::nowInMadrid();

        $csv = $this->renderCsv($report, $exportedAt);

        $this->recordAuditEvent($filter, $actor, $exportedAt);

        return $csv;
    }

    /**
     * Renderiza el CSV a partir de un informe ya ensamblado.
     *
     * Público para que la suite verifique el formato sin depender del reloj ni
     * de la base de datos; la producción entra por `exportCsv()`.
     */
    public function renderCsv(RestockingBiReportDto $report, ?string $exportedAt = null): string
    {
        $exportedAt ??= self::nowInMadrid();
        $summary = $report->summary();
        $spoilage = $report->spoilageComparison();

        $lines = [];
        $lines[] = 'INFORME DE MÉTRICAS DE CARGA, ROTURAS DE STOCK Y MERMAS (VENDGUARD)';
        $lines[] = self::CONFIDENTIALITY_NOTICE;
        $lines[] = '';

        $lines[] = 'SECCIÓN 1: RESUMEN EJECUTIVO, CONFIDENCIALIDAD Y FILTROS APLICADOS';
        $lines[] = $this->csvLine(['Periodo_Desde', $report->filters()['from']]);
        $lines[] = $this->csvLine(['Periodo_Hasta', $report->filters()['to']]);
        $lines[] = $this->csvLine(['Zona', $this->filterLabel($report, 'zones', 'zone_id')]);
        $lines[] = $this->csvLine(['Sede', $this->filterLabel($report, 'locations', 'location_id')]);
        $lines[] = $this->csvLine(['Reponedor', $this->filterLabel($report, 'restockers', 'restocker_id')]);
        $lines[] = $this->csvLine(['Exportado_El', $exportedAt]);
        $lines[] = $this->csvLine(['KPI', 'Valor']);

        foreach ($this->summaryLabels() as $key => $label) {
            $lines[] = $this->csvLine([$label, $this->nullableNumber($summary[$key] ?? null)]);
        }

        $lines[] = '';
        $lines[] = 'SECCIÓN 2: COMPARATIVA DE MERMAS (COMERCIAL FEFO / DAÑADO VS. TÉRMICA APPCC)';
        $lines[] = $this->csvLine(['Concepto', 'Unidades', 'Merma_EUR', 'Nota']);

        $fefo = $spoilage['commercial_fefo_expired'];
        $damaged = $spoilage['commercial_damaged'];
        $commercialTotal = $spoilage['commercial_total'];
        $thermal = $spoilage['thermal_haccp'];
        $thermalSegmentable = ($thermal['segmentable'] ?? false) === true;

        $lines[] = $this->csvLine([
            'Caducidad FEFO',
            (string)(int)$fefo['units'],
            $this->decimal((float)$fefo['loss_eur']),
            '',
        ]);
        $lines[] = $this->csvLine([
            'Dañado en ruta',
            (string)(int)$damaged['units'],
            $this->decimal((float)$damaged['loss_eur']),
            '',
        ]);
        $lines[] = $this->csvLine([
            'Total Comercial',
            (string)(int)$commercialTotal['units'],
            $this->decimal((float)$commercialTotal['loss_eur']),
            '',
        ]);
        $lines[] = $this->csvLine([
            'Avería Térmica APPCC',
            $thermalSegmentable ? (string)(int)$thermal['units'] : self::NULL_PLACEHOLDER,
            $thermalSegmentable ? $this->decimal((float)$thermal['loss_eur']) : self::NULL_PLACEHOLDER,
            $thermalSegmentable ? '' : (string)($thermal['non_segmentable_message'] ?? ''),
        ]);

        $lines[] = '';
        $lines[] = 'SECCIÓN 3: RENDIMIENTO Y PLANOGRAMA POR MÁQUINA';
        $lines[] = $this->csvLine([
            'Maquina', 'Estado', 'Tipo', 'Sede', 'Zona', 'Visitas_Completadas',
            'Visitas_Sin_Rotura', 'Disponibilidad_Visita_Pct', 'Abortos_Termicos',
            'Carriles_Auditados', 'Carriles_Stockout', 'Stockout_Carril_Pct',
            'Carriles_Bajo_Umbral', 'Uds_Repuestas', 'Uds_Caducadas_FEFO', 'Uds_Danadas',
            'Merma_FEFO_EUR', 'Merma_Danado_EUR', 'Merma_Comercial_EUR', 'Tasa_Caducidad_Pct',
            'Planograma_Sobredimensionado', 'Uds_Merma_Termica', 'Merma_Termica_EUR',
        ]);

        foreach ($report->byMachine() as $row) {
            $lines[] = $this->csvLine([
                (string)$row['machine_code'],
                self::activeLabel((bool)$row['is_active']),
                (string)$row['machine_type'],
                $this->nullableText($row['location_name'] ?? null),
                $this->nullableZone($row['zone_name'] ?? null),
                (string)(int)$row['completed_visits_count'],
                (string)(int)$row['clean_in_stock_visits_count'],
                $this->nullableRate($row['visit_in_stock_rate_pct'] ?? null),
                (string)(int)$row['thermal_aborted_visits_count'],
                (string)(int)$row['audited_slots_count'],
                (string)(int)$row['stockout_slots_count'],
                $this->nullableRate($row['slot_stockout_rate_pct'] ?? null),
                (string)(int)$row['low_threshold_slots_count'],
                (string)(int)$row['added_units'],
                (string)(int)$row['expired_units'],
                (string)(int)$row['damaged_units'],
                $this->decimal((float)$row['expired_loss_eur']),
                $this->decimal((float)$row['damaged_loss_eur']),
                $this->decimal((float)$row['commercial_spoilage_loss_eur']),
                $this->nullableRate($row['expiry_rate_pct'] ?? null),
                ((bool)($row['is_oversized_planogram'] ?? false)) ? 'SÍ' : 'NO',
                $this->nullableNumber($row['thermal_spoilage_units'] ?? null),
                $this->nullableDecimal($row['thermal_spoilage_loss_eur'] ?? null),
            ]);
        }

        $lines[] = '';
        $lines[] = 'SECCIÓN 4: ROTACIÓN Y ROTURAS POR PRODUCTO / SKU';
        $lines[] = $this->csvLine([
            'SKU', 'Producto', 'Estado', 'Categoria', 'Carriles_Auditados',
            'Carriles_Stockout', 'Stockout_Carril_Pct', 'Carriles_Bajo_Umbral',
            'Uds_Repuestas', 'Uds_Caducadas_FEFO', 'Uds_Danadas', 'Merma_FEFO_EUR',
            'Merma_Danado_EUR', 'Merma_Comercial_EUR', 'Tasa_Caducidad_Pct',
        ]);

        foreach ($report->byProduct() as $row) {
            $lines[] = $this->csvLine([
                (string)$row['sku'],
                (string)$row['name'],
                self::activeLabel((bool)$row['is_active']),
                $this->nullableText($row['category'] ?? null),
                (string)(int)$row['audited_slots_count'],
                (string)(int)$row['stockout_slots_count'],
                $this->nullableRate($row['slot_stockout_rate_pct'] ?? null),
                (string)(int)$row['low_threshold_slots_count'],
                (string)(int)$row['added_units'],
                (string)(int)$row['expired_units'],
                (string)(int)$row['damaged_units'],
                $this->decimal((float)$row['expired_loss_eur']),
                $this->decimal((float)$row['damaged_loss_eur']),
                $this->decimal((float)$row['commercial_spoilage_loss_eur']),
                $this->nullableRate($row['expiry_rate_pct'] ?? null),
            ]);
        }

        $lines[] = '';
        $lines[] = 'SECCIÓN 5: RENDIMIENTO OPERATIVO Y ARQUEOS POR REPONEDOR';
        $lines[] = $this->csvLine([
            'Codigo_Operador', 'Reponedor', 'Estado', 'Visitas_Completadas', 'Abortos_Termicos',
            'Turnos_Validos', 'Maquinas_Por_Turno', 'Motivo_NA_Turno', 'Tiempo_Medio_Parada_Min',
            'L1_Resueltas', 'Escaladas_SAT', 'Arqueos_OK', 'Arqueos_Descuadre',
            'Arqueos_Pendientes', 'Exactitud_Arqueos_Pct', 'Descuadre_Absoluto_EUR',
            'Uds_Caducadas_FEFO', 'Uds_Danadas', 'Merma_Comercial_EUR',
        ]);

        foreach ($report->byRestocker() as $row) {
            $lines[] = $this->csvLine([
                $this->nullableText($row['operator_code'] ?? null),
                (string)$row['name'],
                self::activeLabel((bool)$row['is_active']),
                (string)(int)$row['completed_visits_count'],
                (string)(int)$row['thermal_aborted_visits_count'],
                (string)(int)$row['valid_shifts_count'],
                $this->nullableNumber($row['machines_per_shift'] ?? null),
                $this->nullableText($row['machines_per_shift_na_reason'] ?? null),
                $this->nullableNumber($row['avg_stop_duration_minutes'] ?? null),
                (string)(int)$row['l1_resolved_count'],
                (string)(int)$row['escalated_to_sat_count'],
                (string)(int)$row['reconciled_ok_count'],
                (string)(int)$row['discrepancy_flagged_count'],
                (string)(int)$row['pending_audit_count'],
                $this->nullableRate($row['cash_accuracy_rate_pct'] ?? null),
                $this->decimal((float)$row['total_abs_discrepancy_eur']),
                (string)(int)$row['expired_units'],
                (string)(int)$row['damaged_units'],
                $this->decimal((float)$row['commercial_spoilage_loss_eur']),
            ]);
        }

        return self::UTF8_BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Registra el evento global de exportación (`QA-1`, Art. III.3).
     *
     * @param array{id?: int|string|null, role?: string, name?: string}|null $actor
     */
    private function recordAuditEvent(
        RestockingBiFilterDto $filter,
        ?array $actor,
        string $exportedAt
    ): void {
        if ($this->auditLogger === null || $actor === null) {
            return;
        }

        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId <= 0) {
            return;
        }

        $this->auditLogger->logEvent(AuditEvent::forGlobalExport(
            AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT,
            self::ACTION_EXPORT,
            $actorId,
            isset($actor['role']) ? (string)$actor['role'] : 'COORDINATOR',
            isset($actor['name']) ? (string)$actor['name'] : 'Coordinación de Servicio',
            null,
            [
                'from' => $filter->from(),
                'to' => $filter->to(),
                'zone_id' => $filter->zoneId(),
                'location_id' => $filter->locationId(),
                'restocker_id' => $filter->restockerId(),
                'exported_at' => $exportedAt,
            ]
        ));
    }

    /**
     * Nombre visible del filtro activo, resuelto contra `available_filters`.
     *
     * @param array<string, mixed> $report
     */
    private function filterLabel(RestockingBiReportDto $report, string $catalog, string $idKey): string
    {
        $filters = $report->filters();
        $selectedId = $filters[$idKey] ?? null;

        if ($selectedId === null) {
            return match ($catalog) {
                'zones' => 'Todas',
                'locations' => 'Todas',
                default => 'Todos',
            };
        }

        foreach ($report->availableFilters()[$catalog] ?? [] as $row) {
            if ((int)($row['id'] ?? 0) === (int)$selectedId) {
                if ($catalog === 'zones') {
                    return (string)($row['name'] ?? self::NULL_PLACEHOLDER);
                }
                if ($catalog === 'locations') {
                    return (string)($row['name'] ?? self::NULL_PLACEHOLDER);
                }

                return (string)($row['name'] ?? self::NULL_PLACEHOLDER);
            }
        }

        return self::NULL_PLACEHOLDER;
    }

    /**
     * Etiquetas en castellano de todos los KPIs de `summary` (`QA-C4`).
     *
     * @return array<string, string>
     */
    private function summaryLabels(): array
    {
        return [
            'completed_visits_count' => 'Visitas_Completadas',
            'clean_in_stock_visits_count' => 'Visitas_Sin_Rotura',
            'stockout_visits_count' => 'Visitas_Con_Rotura',
            'visit_in_stock_rate_pct' => 'Disponibilidad_Visita_Pct',
            'visit_stockout_rate_pct' => 'Rotura_Visita_Pct',
            'thermal_aborted_visits_count' => 'Abortos_Termicos',
            'audited_slots_count' => 'Carriles_Auditados',
            'stockout_slots_count' => 'Carriles_Stockout',
            'slot_stockout_rate_pct' => 'Stockout_Carril_Pct',
            'low_threshold_slots_count' => 'Carriles_Bajo_Umbral',
            'oversized_machines_count' => 'Maquinas_Sobredimensionadas',
            'commercial_spoilage_eur' => 'Merma_Comercial_EUR',
            'commercial_spoilage_units' => 'Merma_Comercial_Uds',
            'thermal_spoilage_eur' => 'Merma_Termica_EUR',
            'thermal_spoilage_units' => 'Merma_Termica_Uds',
            'total_spoilage_eur' => 'Merma_Total_EUR',
            'cash_reconciled_ok_count' => 'Arqueos_OK',
            'cash_discrepancy_flagged_count' => 'Arqueos_Descuadre',
            'cash_reconciled_count' => 'Arqueos_Cerrados',
            'cash_pending_audit_count' => 'Arqueos_Pendientes',
            'cash_accuracy_rate_pct' => 'Exactitud_Arqueos_Pct',
            'l1_resolved_count' => 'L1_Resueltas',
            'escalated_to_sat_count' => 'Escaladas_SAT',
        ];
    }

    /**
     * @param list<string> $fields
     */
    private function csvLine(array $fields): string
    {
        return implode(self::DELIMITER, array_map(
            fn(string $field): string => $this->csvField($field),
            $fields
        ));
    }

    /**
     * Neutraliza una fórmula y cita el campo si contiene separadores o comillas.
     *
     * El apostrofo va delante del valor y dentro de las comillas: Excel lo
     * interpreta como texto literal y no ejecuta la celda (`RF-06.3`).
     */
    private function csvField(string $value): string
    {
        $value = $this->neutralizeCsvCell($value);

        if (strpbrk($value, self::DELIMITER . "\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Regla única anti-fórmulas: `=`, `+`, `-`, `@`, tabulador o retorno.
     */
    public function neutralizeCsvCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private function nullableDecimal(mixed $value): string
    {
        return $value === null ? self::NULL_PLACEHOLDER : $this->decimal((float)$value);
    }

    /** Número entero, tasa o ratio; `—` cuando no hay dato. */
    private function nullableNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NULL_PLACEHOLDER;
        }

        if (is_float($value)) {
            return $this->decimal($value);
        }

        return (string)(int)$value;
    }

    /** Tasa porcentual con dos decimales y coma, o `—`. */
    private function nullableRate(mixed $value): string
    {
        return $value === null ? self::NULL_PLACEHOLDER : $this->decimal((float)$value);
    }

    private function nullableText(mixed $value): string
    {
        return ($value === null || $value === '') ? self::NULL_PLACEHOLDER : (string)$value;
    }

    private function nullableZone(mixed $value): string
    {
        return ($value === null || $value === '') ? self::NO_ZONE_LABEL : (string)$value;
    }

    private static function activeLabel(bool $isActive): string
    {
        return $isActive ? 'Activa' : 'Baja';
    }

    private static function nowInMadrid(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(RestockingBiFilterDto::TIMEZONE)))
            ->format('Y-m-d H:i:s');
    }
}
