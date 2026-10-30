<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\ParetoFilterDto;
use VendGuard\Application\DTO\RootCauseParetoReportDto;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Service\ManufacturerNormalizer;
use VendGuard\Infrastructure\Repository\PdoRootCauseParetoAnalyticsRepository;

/**
 * RootCauseParetoService
 *
 * Motor matemático del Diagrama de Pareto 80/20 y de la exportación de
 * garantías (Módulo 25, RF-05, D5, C1/C3/C6, QA 2 y QA 4).
 *
 * Reglas que concentra:
 *
 *   1. **Base del 100 % (`C3`):** sólo las averías clasificadas con causa raíz
 *      vigente pesan en la curva. Las históricas sin clasificar se cuentan en un
 *      indicador separado y jamás entran en el denominador.
 *   2. **Un voto por expediente (`C1`, `QA 2`):** cada avería aporta una única
 *      unidad a la causa con la que quedó cerrada definitivamente —tras una
 *      reapertura en garantía, la nueva—, con el 100 % de su MTTR neto y de su
 *      consumo de repuestos. El repositorio ya devuelve el estado vigente.
 *   3. **Determinismo (`C6`):** causas ordenadas por frecuencia descendente y
 *      código ascendente; soluciones empatadas por aplicaciones, condición de
 *      predeterminada y código; lista vacía segura sin dividir por cero.
 *   4. **Cierre exacto (`QA 2`):** porcentajes y acumulados a 2 decimales, y el
 *      último peldaño forzado a `100.00%` para absorber la deriva de coma
 *      flotante. La Zona Vital abarca hasta la primera causa que alcanza o cruza
 *      el `80.00%` acumulado, inclusive (`C3`).
 *   5. **Fabricante normalizado (`QA 4`):** el filtro se aplica en memoria con
 *      `ManufacturerNormalizer`, la misma función pura que agrupa el selector y
 *      el CSV, para que `"NECTA KIKKO"` y `"necta Astro"` caigan en `Necta`.
 *   6. **CSV de garantías (`EARS 05.6`, `QA 2`):** UTF-8 BOM, separador `;` y
 *      dos secciones; la segunda conserva el nombre vigente y el congelado al
 *      cierre de causa y solución.
 *
 * El servicio no abre transacciones: el Pareto es de sólo lectura. La
 * exportación deja su evento de auditoría `EXPORT_ROOT_CAUSE_PARETO_CSV` cuando
 * el controlador le entrega el actor autenticado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
final class RootCauseParetoService
{
    /** Marca UTF-8 sin la cual Excel interpreta mal los acentos (EARS 05.6). */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    private const VITAL_FEW_THRESHOLD = 80.0;

    private PdoRootCauseParetoAnalyticsRepository $analyticsRepository;

    private ?AuditLogger $auditLogger;

    public function __construct(
        PdoRootCauseParetoAnalyticsRepository $analyticsRepository,
        ?AuditLogger $auditLogger = null
    ) {
        $this->analyticsRepository = $analyticsRepository;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Construye el informe 80/20 del filtro activo (`EARS 05.1` a `EARS 05.5`).
     */
    public function buildParetoReport(ParetoFilterDto $filter): RootCauseParetoReportDto
    {
        $rows = $this->analyticsRepository->fetchResolvedIncidentsForPareto($filter);
        $availableFilters = $this->buildAvailableFilters(
            $this->analyticsRepository->fetchAvailableFilterOptions()
        );

        return $this->analyze($filter, $rows, $availableFilters)['report'];
    }

    /**
     * Genera el CSV `UTF-8 BOM` en dos secciones (`EARS 05.6`, `QA 2`) y deja
     * constancia auditable de la exportación cuando recibe al actor.
     *
     * @param array{id?: int|string|null, role?: string, name?: string}|null $actor
     */
    public function exportCsv(ParetoFilterDto $filter, ?array $actor = null): string
    {
        $rows = $this->analyticsRepository->fetchResolvedIncidentsForPareto($filter);
        $availableFilters = $this->buildAvailableFilters(
            $this->analyticsRepository->fetchAvailableFilterOptions()
        );

        $analysis = $this->analyze($filter, $rows, $availableFilters);
        $csv = $this->renderCsv($filter, $analysis['report'], $analysis['classified_rows']);

        if ($this->auditLogger !== null && $actor !== null) {
            $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
            if ($actorId > 0) {
                $this->auditLogger->logEvent(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_ROOT_CAUSE_TAXONOMY,
                    $actorId,
                    'EXPORT_ROOT_CAUSE_PARETO_CSV',
                    $actorId,
                    isset($actor['role']) ? (string) $actor['role'] : 'COORDINATOR',
                    isset($actor['name']) ? (string) $actor['name'] : 'Coordinación de Servicio',
                    null,
                    [
                        'filters' => $filter->toArray(),
                        'rows_exported' => count($analysis['classified_rows']),
                    ],
                    [
                        'exported_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
                            ->format('Y-m-d H:i:s'),
                    ]
                ));
            }
        }

        return $csv;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $availableFilters
     * @return array{
     *     report: RootCauseParetoReportDto,
     *     classified_rows: list<array<string, mixed>>,
     *     all_items: list<array<string, mixed>>
     * }
     */
    private function analyze(ParetoFilterDto $filter, array $rows, array $availableFilters): array
    {
        $filteredRows = [];
        foreach ($rows as $row) {
            $model = isset($row['machine_model']) && $row['machine_model'] !== null
                ? (string) $row['machine_model']
                : null;

            if (ManufacturerNormalizer::matchesFilter($model, $filter->getManufacturer())) {
                $filteredRows[] = $row;
            }
        }

        $totalIncidents = count($filteredRows);
        $classifiedRows = [];
        foreach ($filteredRows as $row) {
            if (isset($row['root_cause_id']) && $row['root_cause_id'] !== null && $row['root_cause_id'] !== '') {
                $classifiedRows[] = $row;
            }
        }

        $classifiedCount = count($classifiedRows);
        $unclassifiedCount = $totalIncidents - $classifiedCount;
        $allItems = $this->buildRankedItems($classifiedRows);

        $totalUnits = 0;
        $totalCost = 0.0;
        foreach ($classifiedRows as $row) {
            $totalUnits += $this->intValue($row, 'replaced_parts_units');
            $totalCost += $this->floatValue($row, 'replaced_parts_cost_eur');
        }

        $summary = [
            'classified_incidents_count' => $classifiedCount,
            'unclassified_historical_count' => $unclassifiedCount,
            'total_incidents_in_period' => $totalIncidents,
            'vital_few_causes_count' => count(array_filter(
                $allItems,
                static fn (array $item): bool => $item['is_vital_few'] === true
            )),
            'total_causes_with_incidents' => count($allItems),
            'total_replaced_parts_units' => $totalUnits,
            'total_replaced_parts_cost_eur' => round($totalCost, 2),
        ];

        $displayedItems = $allItems;
        if ($filter->isVitalFewOnly()) {
            $displayedItems = array_values(array_filter(
                $allItems,
                static fn (array $item): bool => $item['is_vital_few'] === true
            ));
        }

        return [
            'report' => new RootCauseParetoReportDto(
                $filter->toArray(),
                $availableFilters,
                $summary,
                $displayedItems
            ),
            'classified_rows' => $classifiedRows,
            'all_items' => $allItems,
        ];
    }

    /**
     * Agrupa por causa, calcula métricas, ordena y aplica la curva 80/20.
     *
     * @param list<array<string, mixed>> $classifiedRows
     * @return list<array<string, mixed>>
     */
    private function buildRankedItems(array $classifiedRows): array
    {
        $classifiedCount = count($classifiedRows);
        if ($classifiedCount === 0) {
            return [];
        }

        $groups = [];
        foreach ($classifiedRows as $row) {
            $rootCauseId = (int) $row['root_cause_id'];

            if (!isset($groups[$rootCauseId])) {
                $groups[$rootCauseId] = [
                    'root_cause_id' => $rootCauseId,
                    'root_cause_code' => $this->stringValue($row, 'current_root_cause_code', 'root_cause_code_snapshot'),
                    'category' => $this->stringValue($row, 'current_root_cause_category', 'root_cause_category_snapshot'),
                    'root_cause_name' => $this->stringValue($row, 'current_root_cause_name', 'root_cause_name_snapshot'),
                    'incidents' => [],
                ];
            }

            $groups[$rootCauseId]['incidents'][] = $row;
        }

        $metrics = [];
        foreach ($groups as $group) {
            $incidents = $group['incidents'];
            $incidentCount = count($incidents);

            $mttrSum = 0.0;
            $units = 0;
            $cost = 0.0;
            foreach ($incidents as $incident) {
                $mttrSum += $this->floatValue($incident, 'mttr_hours');
                $units += $this->intValue($incident, 'replaced_parts_units');
                $cost += $this->floatValue($incident, 'replaced_parts_cost_eur');
            }

            $category = (string) $group['category'];
            $categoryEnum = IncidentCategory::tryFrom($category);

            $metrics[] = [
                'root_cause_id' => (int) $group['root_cause_id'],
                'root_cause_code' => (string) $group['root_cause_code'],
                'category' => $category,
                'category_label' => $categoryEnum?->label() ?? $category,
                'root_cause_name' => (string) $group['root_cause_name'],
                'incident_count' => $incidentCount,
                'avg_mttr_hours' => round($mttrSum / $incidentCount, 2),
                'replaced_parts_units' => $units,
                'replaced_parts_cost_eur' => round($cost, 2),
                'top_solution' => $this->findTopSolution($incidents),
            ];
        }

        usort($metrics, static function (array $a, array $b): int {
            if ($a['incident_count'] !== $b['incident_count']) {
                return $b['incident_count'] <=> $a['incident_count'];
            }

            return strcmp((string) $a['root_cause_code'], (string) $b['root_cause_code']);
        });

        $runningCount = 0;
        $crossedThreshold = false;
        $lastIndex = count($metrics) - 1;

        foreach ($metrics as $index => &$item) {
            $runningCount += $item['incident_count'];
            $item['rank'] = $index + 1;
            $item['percentage'] = round(($item['incident_count'] / $classifiedCount) * 100, 2);
            $item['cumulative_percentage'] = $index === $lastIndex
                ? 100.00
                : round(($runningCount / $classifiedCount) * 100, 2);

            if (!$crossedThreshold) {
                $item['is_vital_few'] = true;
                if ($item['cumulative_percentage'] >= self::VITAL_FEW_THRESHOLD) {
                    $crossedThreshold = true;
                }
            } else {
                $item['is_vital_few'] = false;
            }
        }
        unset($item);

        return $metrics;
    }

    /**
     * Solución más aplicada con desempate determinista (`C6`):
     * aplicaciones DESC, predeterminada DESC, código ASC.
     *
     * @param list<array<string, mixed>> $incidents
     * @return array{id: int, code: string, name: string, applications_count: int}|null
     */
    private function findTopSolution(array $incidents): ?array
    {
        $stats = [];

        foreach ($incidents as $incident) {
            $solutionId = $incident['standard_solution_id'] ?? null;
            if ($solutionId === null || $solutionId === '') {
                continue;
            }

            $solutionId = (int) $solutionId;
            if (!isset($stats[$solutionId])) {
                $stats[$solutionId] = [
                    'id' => $solutionId,
                    'code' => $this->stringValue(
                        $incident,
                        'current_standard_solution_code',
                        'standard_solution_code_snapshot'
                    ),
                    'name' => $this->stringValue(
                        $incident,
                        'current_standard_solution_name',
                        'standard_solution_name_snapshot'
                    ),
                    'is_default' => $this->boolValue($incident['current_standard_solution_is_default'] ?? false),
                    'applications_count' => 0,
                ];
            }

            $stats[$solutionId]['applications_count']++;
        }

        if ($stats === []) {
            return null;
        }

        uasort($stats, static function (array $a, array $b): int {
            if ($a['applications_count'] !== $b['applications_count']) {
                return $b['applications_count'] <=> $a['applications_count'];
            }
            if ($a['is_default'] !== $b['is_default']) {
                return $a['is_default'] ? -1 : 1;
            }

            return strcmp((string) $a['code'], (string) $b['code']);
        });

        $top = reset($stats);

        return [
            'id' => (int) $top['id'],
            'code' => (string) $top['code'],
            'name' => (string) $top['name'],
            'applications_count' => (int) $top['applications_count'],
        ];
    }

    /**
     * Opciones vivas de los selectores, con el fabricante derivado por la
     * función pura `ManufacturerNormalizer` (`QA 4`).
     *
     * @param array{models?: list<string>, locations?: list<array{id: int, name: string}>, technicians?: list<array{id: int, name: string}>} $options
     * @return array{manufacturers: list<string>, models_by_manufacturer: array<string, list<string>>, models: list<string>}
     */
    private function buildAvailableFilters(array $options): array
    {
        $models = [];
        foreach ($options['models'] ?? [] as $model) {
            $trimmed = trim((string) $model);
            if ($trimmed !== '' && !in_array($trimmed, $models, true)) {
                $models[] = $trimmed;
            }
        }

        $modelsByManufacturer = [];
        foreach ($models as $model) {
            $manufacturer = ManufacturerNormalizer::extractManufacturer($model);
            $modelsByManufacturer[$manufacturer][] = $model;
        }

        uksort($modelsByManufacturer, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return [
            'manufacturers' => array_keys($modelsByManufacturer),
            'models_by_manufacturer' => $modelsByManufacturer,
            'models' => $models,
        ];
    }

    /**
     * @param list<array<string, mixed>> $classifiedRows
     */
    private function renderCsv(
        ParetoFilterDto $filter,
        RootCauseParetoReportDto $report,
        array $classifiedRows
    ): string {
        $summary = $report->getSummary();
        $lines = [];

        $lines[] = 'INFORME DE PARETO DE CAUSAS RAÍZ Y RECLAMACIÓN DE GARANTÍAS (VENDGUARD)';
        $lines[] = $this->csvLine([
            'Periodo Desde',
            $filter->getStartDate(),
            'Periodo Hasta',
            $filter->getEndDate(),
            'Fabricante',
            $filter->getManufacturer() ?? 'Todos',
            'Modelo',
            $filter->getMachineModel() ?? 'Todos',
            'Categoría',
            $filter->getCategory()?->label() ?? 'Todas',
        ]);
        $lines[] = $this->csvLine([
            'Averías Clasificadas (Base 100% Pareto)',
            (string) $summary['classified_incidents_count'],
            'Averías Históricas Sin Clasificar (Excluidas)',
            (string) $summary['unclassified_historical_count'],
        ]);
        $lines[] = '';
        $lines[] = 'SECCIÓN 1: TABLA AGREGADA DE PARETO (REGLA 80/20)';
        $lines[] = $this->csvLine([
            'Orden',
            'Código Causa',
            'Categoría',
            'Causa Raíz (Nombre Vigente)',
            'Averías',
            '% Individual',
            '% Acumulado',
            'Zona Vital 80/20',
            'MTTR Medio (h)',
            'Unidades Repuestos',
            'Coste Repuestos (EUR)',
            'Código Solución Más Frecuente',
            'Solución Más Frecuente',
        ]);

        foreach ($report->getItems() as $item) {
            $topSolution = is_array($item['top_solution'] ?? null) ? $item['top_solution'] : [];
            $lines[] = $this->csvLine([
                (string) $item['rank'],
                (string) $item['root_cause_code'],
                (string) $item['category'],
                (string) $item['root_cause_name'],
                (string) $item['incident_count'],
                $this->decimal($item['percentage']),
                $this->decimal($item['cumulative_percentage']),
                $item['is_vital_few'] === true ? 'SÍ (VITAL 80%)' : 'NO',
                $this->decimal($item['avg_mttr_hours']),
                (string) $item['replaced_parts_units'],
                $this->decimal($item['replaced_parts_cost_eur']),
                (string) ($topSolution['code'] ?? ''),
                (string) ($topSolution['name'] ?? ''),
            ]);
        }

        $lines[] = '';
        $lines[] = 'SECCIÓN 2: DETALLE NOMINAL DE EXPEDIENTES PARA RECLAMACIÓN A FABRICANTE';
        $lines[] = $this->csvLine([
            'Código Ticket',
            'Fecha Resolución',
            'Sede',
            'Código Máquina',
            'Fabricante',
            'Modelo Máquina',
            'Código Causa Raíz',
            'Causa Raíz (Nombre Vigente)',
            'Causa Raíz (Nombre Congelado al Cierre)',
            'Código Solución',
            'Solución (Nombre Vigente)',
            'Solución (Nombre Congelado al Cierre)',
            'MTTR (h)',
            'Unidades Repuestos',
            'Coste Repuestos (EUR)',
        ]);

        foreach ($classifiedRows as $row) {
            $lines[] = $this->csvLine([
                (string) ($row['ticket_code'] ?? ''),
                (string) ($row['resolved_at'] ?? ''),
                (string) ($row['location_name'] ?? ''),
                (string) ($row['machine_code'] ?? ''),
                ManufacturerNormalizer::extractManufacturer(
                    isset($row['machine_model']) && $row['machine_model'] !== null
                        ? (string) $row['machine_model']
                        : null
                ),
                (string) ($row['machine_model'] ?? ''),
                $this->stringValue($row, 'current_root_cause_code', 'root_cause_code_snapshot'),
                $this->stringValue($row, 'current_root_cause_name', 'root_cause_name_snapshot'),
                $this->stringValue($row, 'root_cause_name_snapshot', 'current_root_cause_name'),
                $this->stringValue($row, 'current_standard_solution_code', 'standard_solution_code_snapshot'),
                $this->stringValue($row, 'current_standard_solution_name', 'standard_solution_name_snapshot'),
                $this->stringValue($row, 'standard_solution_name_snapshot', 'current_standard_solution_name'),
                $this->decimal($this->floatValue($row, 'mttr_hours')),
                (string) $this->intValue($row, 'replaced_parts_units'),
                $this->decimal($this->floatValue($row, 'replaced_parts_cost_eur')),
            ]);
        }

        return self::UTF8_BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $fields
     */
    private function csvLine(array $fields): string
    {
        return implode(';', array_map(
            fn (string $field): string => $this->csvField($field),
            $fields
        ));
    }

    private function csvField(string $value): string
    {
        if (strpbrk($value, ";\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function decimal(float|int $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $preferred, string $fallback): string
    {
        $value = $row[$preferred] ?? null;
        if ($value === null || $value === '') {
            $value = $row[$fallback] ?? '';
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function floatValue(array $row, string $key): float
    {
        return (float) ($row[$key] ?? 0);
    }

    private function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
