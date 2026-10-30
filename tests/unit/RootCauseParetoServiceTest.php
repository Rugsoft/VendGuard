<?php

declare(strict_types=1);

/**
 * RootCauseParetoServiceTest · T-TAXONOMY-08
 *
 * Suite unitaria del motor matemático del Diagrama de Pareto 80/20 y de la
 * exportación de garantías (Módulo 25, RF-05).
 *
 * Certifica:
 *   1. **Base del 100 % (`C3`):** las averías sin causa raíz se cuentan aparte
 *      y nunca entran en el denominador.
 *   2. **Curva 80/20 (`C3`, `QA 2`):** orden por frecuencia y código, porcentajes
 *      a 2 decimales, cierre exacto del último peldaño en `100.00%` y Zona Vital
 *      hasta la primera causa que alcanza o cruza el `80.00%` inclusive.
 *   3. **Determinismo (`C6`):** desempate de causas por código y de soluciones
 *      por aplicaciones, predeterminada y código.
 *   4. **Fabricante normalizado (`QA 4`):** el filtro de marca agrupa
 *      `"NECTA KIKKO"`, `"necta Astro"` y `"Necta"` bajo la misma etiqueta.
 *   5. **Estado vacío seguro (`C6`):** cero averías clasificadas no divide por
 *      cero y devuelve contadores a cero.
 *   6. **CSV de garantías (`EARS 05.6`, `QA 2`):** BOM UTF-8, separador `;`, dos
 *      secciones y doble columna vigente/congelada en el detalle nominal.
 *   7. **Auditoría de la exportación:** `EXPORT_ROOT_CAUSE_PARETO_CSV` bajo
 *      `ROOT_CAUSE_TAXONOMY`, con filtros y filas exportadas.
 *
 * El repositorio analítico se sustituye por un doble que devuelve filas en
 * memoria; no se toca PostgreSQL ni la red. El CSV se inspecciona como texto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\ParetoFilterDto;
use VendGuard\Application\DTO\RootCauseParetoReportDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\RootCauseParetoService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Infrastructure\Repository\PdoRootCauseParetoAnalyticsRepository;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · RootCauseParetoServiceTest (T-TAXONOMY-08)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$assertThrows = function (
    string $caseTitle,
    callable $operation,
    string $expectedClass,
    string $expectedFragment
) use ($assert): void {
    try {
        $operation();
        $assert($caseTitle, false, 'No se lanzó ninguna excepción.');
    } catch (Throwable $exception) {
        $assert(
            $caseTitle,
            $exception instanceof $expectedClass && str_contains($exception->getMessage(), $expectedFragment),
            get_class($exception) . ': ' . $exception->getMessage()
        );
    }
};

/**
 * Doble del repositorio analítico: devuelve las filas precargadas y conserva
 * el filtro recibido para comprobar que el servicio no lo reconstruye.
 */
final class FakeParetoAnalyticsRepository extends PdoRootCauseParetoAnalyticsRepository
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed> */
    public array $options = ['models' => [], 'locations' => [], 'technicians' => []];

    public ?ParetoFilterDto $lastFilter = null;

    public int $fetchCalls = 0;

    public function __construct()
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function fetchResolvedIncidentsForPareto(ParetoFilterDto $filter): array
    {
        $this->lastFilter = $filter;
        $this->fetchCalls++;

        return $this->rows;
    }

    public function fetchAvailableFilterOptions(): array
    {
        return $this->options;
    }
}

/**
 * Registro de auditoría en memoria.
 */
final class ParetoAuditLog implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return [];
    }
}

/**
 * @return array<string, mixed>
 */
function paretoRow(int $incidentId, ?int $causeId, string $causeCode, ?int $solutionId, string $solutionCode, array $overrides = []): array
{
    $row = [
        'incident_id' => $incidentId,
        'ticket_code' => sprintf('INC-2026-%04d', $incidentId),
        'resolved_at' => '2026-10-01 10:00:00',
        'mttr_hours' => 1.0,
        'root_cause_id' => $causeId,
        'current_root_cause_code' => $causeId !== null ? $causeCode : null,
        'current_root_cause_category' => $causeId !== null ? 'PAYMENT_SYSTEM' : null,
        'current_root_cause_name' => $causeId !== null ? 'Causa ' . $causeCode : null,
        'current_root_cause_is_active' => true,
        'root_cause_code_snapshot' => $causeId !== null ? $causeCode : null,
        'root_cause_category_snapshot' => $causeId !== null ? 'PAYMENT_SYSTEM' : null,
        'root_cause_name_snapshot' => $causeId !== null ? 'Causa ' . $causeCode : null,
        'standard_solution_id' => $solutionId,
        'current_standard_solution_code' => $solutionId !== null ? $solutionCode : null,
        'current_standard_solution_name' => $solutionId !== null ? 'Solución ' . $solutionCode : null,
        'current_standard_solution_is_default' => false,
        'standard_solution_code_snapshot' => $solutionId !== null ? $solutionCode : null,
        'standard_solution_name_snapshot' => $solutionId !== null ? 'Solución ' . $solutionCode : null,
        'machine_code' => sprintf('VM-%03d', $incidentId),
        'machine_model' => 'NECTA KIKKO MAX',
        'location_name' => 'Hospital Universitario Central',
        'replaced_parts_units' => 0,
        'replaced_parts_cost_eur' => 0.0,
    ];

    return array_merge($row, $overrides);
}

/**
 * Escenario principal: 10 averías clasificadas (5 + 3 + 2) y 2 sin clasificar.
 *
 * @return list<array<string, mixed>>
 */
function paretoFixtureRows(): array
{
    $rows = [];

    // Causa A: 5 averías; solución 10 predeterminada 3 veces, solución 11 dos.
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = paretoRow(
            $i,
            5,
            'RC-PAY-01',
            $i <= 3 ? 10 : 11,
            $i <= 3 ? 'SOL-PAY-01A' : 'SOL-PAY-01B',
            [
                'mttr_hours' => (float) $i,
                'current_standard_solution_is_default' => $i <= 3,
                'current_root_cause_name' => 'Moneda doblada; ficha en el selector',
                'root_cause_name_snapshot' => 'Moneda doblada en el selector',
                'replaced_parts_units' => 1,
                'replaced_parts_cost_eur' => 10.0,
            ]
        );
    }

    // Causa B: 3 averías; solución 20 no predeterminada 2 veces, 21 predeterminada 1.
    for ($i = 6; $i <= 8; $i++) {
        $rows[] = paretoRow(
            $i,
            1,
            'RC-COLD-01',
            $i <= 7 ? 20 : 21,
            $i <= 7 ? 'SOL-COLD-01B' : 'SOL-COLD-01A',
            [
                'current_root_cause_category' => 'TEMPERATURE_COLD',
                'root_cause_category_snapshot' => 'TEMPERATURE_COLD',
                'mttr_hours' => 2.0,
                'current_standard_solution_is_default' => $i === 8,
                'replaced_parts_units' => 2,
                'replaced_parts_cost_eur' => 5.0,
            ]
        );
    }

    // Causa C: 2 averías.
    for ($i = 9; $i <= 10; $i++) {
        $rows[] = paretoRow(
            $i,
            3,
            'RC-JAM-01',
            30,
            'SOL-JAM-01A',
            [
                'current_root_cause_category' => 'PRODUCT_JAM',
                'root_cause_category_snapshot' => 'PRODUCT_JAM',
                'mttr_hours' => 4.0,
            ]
        );
    }

    // Históricas sin clasificar: no pesan en el 100 % del Pareto (C3).
    $rows[] = paretoRow(11, null, '', null, '');
    $rows[] = paretoRow(12, null, '', null, '');

    return $rows;
}

function paretoFilter(array $overrides = []): ParetoFilterDto
{
    return new ParetoFilterDto(
        $overrides['startDate'] ?? '2026-08-01',
        $overrides['endDate'] ?? '2026-10-31',
        $overrides['periodDays'] ?? 90,
        $overrides['manufacturer'] ?? null,
        $overrides['machineModel'] ?? null,
        $overrides['category'] ?? null,
        $overrides['locationId'] ?? null,
        $overrides['technicianId'] ?? null,
        $overrides['vitalFewOnly'] ?? false
    );
}

// ---------------------------------------------------------------------------
// 1. DTO de filtros: validación de frontera (EARS 05.4)
// ---------------------------------------------------------------------------

echo "· DTO de filtros y validación de la ventana temporal\n";

$dto = new ParetoFilterDto(
    ' 2026-08-02 ',
    '2026-10-30',
    '90',
    '  Necta ',
    '  Necta Samba Combo ',
    IncidentCategory::PAYMENT_SYSTEM,
    4,
    9
);

$assert(
    'El DTO recorta textos y normaliza el periodo a entero',
    $dto->getStartDate() === '2026-08-02'
        && $dto->getEndDate() === '2026-10-30'
        && $dto->getPeriodDays() === 90
        && $dto->getManufacturer() === 'Necta'
        && $dto->getMachineModel() === 'Necta Samba Combo'
        && $dto->getCategory() === IncidentCategory::PAYMENT_SYSTEM
        && $dto->getLocationId() === 4
        && $dto->getTechnicianId() === 9
);

$assert(
    'El DTO publica el contrato de filtros aplicados',
    $dto->toArray() === [
        'period_days' => 90,
        'from' => '2026-08-02',
        'to' => '2026-10-30',
        'manufacturer' => 'Necta',
        'machine_model' => 'Necta Samba Combo',
        'category' => 'PAYMENT_SYSTEM',
    ]
);

$assert(
    'El periodo histórico completo se admite como `all`',
    (new ParetoFilterDto('2026-01-01', '2026-10-30', 'ALL'))->getPeriodDays() === 'all'
);

$assertThrows(
    'Una fecha imposible se rechaza con INVALID_PARETO_FILTER',
    fn () => new ParetoFilterDto('2026-02-31', '2026-10-30'),
    InvalidArgumentException::class,
    'INVALID_PARETO_FILTER'
);

$assertThrows(
    'Un rango invertido se rechaza con INVALID_PARETO_FILTER',
    fn () => new ParetoFilterDto('2026-10-30', '2026-08-01'),
    InvalidArgumentException::class,
    'INVALID_PARETO_FILTER'
);

$assertThrows(
    'Un periodo no admitido se rechaza con INVALID_PARETO_FILTER',
    fn () => new ParetoFilterDto('2026-08-01', '2026-10-30', 45),
    InvalidArgumentException::class,
    'INVALID_PARETO_FILTER'
);

$assertThrows(
    'Un identificador no positivo se rechaza con INVALID_PARETO_FILTER',
    fn () => new ParetoFilterDto('2026-08-01', '2026-10-30', 90, null, null, null, 0),
    InvalidArgumentException::class,
    'INVALID_PARETO_FILTER'
);

// ---------------------------------------------------------------------------
// 2. Agregación, orden y curva 80/20
// ---------------------------------------------------------------------------

echo "\n· Agregación por causa, orden determinista y curva 80/20\n";

$repository = new FakeParetoAnalyticsRepository();
$repository->rows = paretoFixtureRows();
$repository->options = [
    'models' => ['NECTA KIKKO MAX', 'Jofemar Vision', 'necta Astro', 'Sanden Vendo G-Drink'],
    'locations' => [['id' => 1, 'name' => 'Hospital Central']],
    'technicians' => [['id' => 9, 'name' => 'Ana Técnica']],
];
$auditLog = new ParetoAuditLog();
$service = new RootCauseParetoService($repository, new AuditLogger($auditLog));

$filter = paretoFilter();
$report = $service->buildParetoReport($filter);

$summary = $report->getSummary();
$assert(
    'Las averías sin clasificar quedan fuera de la base del 100 % (C3)',
    $summary['classified_incidents_count'] === 10
        && $summary['unclassified_historical_count'] === 2
        && $summary['total_incidents_in_period'] === 12
        && $summary['total_causes_with_incidents'] === 3
);

$items = $report->getItems();
$assert(
    'Las causas se ordenan por frecuencia descendente',
    count($items) === 3
        && $items[0]['root_cause_code'] === 'RC-PAY-01'
        && $items[1]['root_cause_code'] === 'RC-COLD-01'
        && $items[2]['root_cause_code'] === 'RC-JAM-01'
        && $items[0]['incident_count'] === 5
        && $items[1]['incident_count'] === 3
        && $items[2]['incident_count'] === 2
);

$assert(
    'Los porcentajes y acumulados se redondean a 2 decimales',
    $items[0]['percentage'] === 50.0
        && $items[1]['percentage'] === 30.0
        && $items[2]['percentage'] === 20.0
        && $items[0]['cumulative_percentage'] === 50.0
        && $items[1]['cumulative_percentage'] === 80.0
        && $items[2]['cumulative_percentage'] === 100.0
);

$assert(
    'La Zona Vital incluye hasta la causa que alcanza exactamente el 80 % (C3)',
    $items[0]['is_vital_few'] === true
        && $items[1]['is_vital_few'] === true
        && $items[2]['is_vital_few'] === false
        && $summary['vital_few_causes_count'] === 2
);

$assert(
    'El MTTR medio por causa se calcula en horas con 2 decimales',
    $items[0]['avg_mttr_hours'] === 3.0
        && $items[1]['avg_mttr_hours'] === 2.0
        && $items[2]['avg_mttr_hours'] === 4.0
);

$assert(
    'Los repuestos se atribuyen íntegros a la causa vigente de cada expediente',
    $items[0]['replaced_parts_units'] === 5
        && $items[0]['replaced_parts_cost_eur'] === 50.0
        && $items[1]['replaced_parts_units'] === 6
        && $items[1]['replaced_parts_cost_eur'] === 15.0
        && $summary['total_replaced_parts_units'] === 11
        && $summary['total_replaced_parts_cost_eur'] === 65.0
);

$assert(
    'La solución más frecuente gana por aplicaciones aunque no sea la predeterminada (C6)',
    $items[0]['top_solution'] === [
        'id' => 10,
        'code' => 'SOL-PAY-01A',
        'name' => 'Solución SOL-PAY-01A',
        'applications_count' => 3,
    ]
        && $items[1]['top_solution']['id'] === 20
        && $items[1]['top_solution']['applications_count'] === 2
);

$assert(
    'La categoría se publica con su etiqueta legible',
    $items[0]['category_label'] === 'Fallo en sistema de pago / Monedero / TPV'
        && $items[1]['category_label'] === 'Temperatura / Pérdida de frío'
);

$assert(
    'El servicio respeta el filtro recibido y no lo reconstruye',
    $repository->lastFilter === $filter
);

// --- Desempate entre causas ------------------------------------------------

$tieRepository = new FakeParetoAnalyticsRepository();
$tieRepository->rows = [
    paretoRow(1, 2, 'RC-002', 20, 'SOL-002A'),
    paretoRow(2, 2, 'RC-002', 20, 'SOL-002A'),
    paretoRow(3, 1, 'RC-001', 10, 'SOL-001A'),
    paretoRow(4, 1, 'RC-001', 10, 'SOL-001A'),
];
$tieService = new RootCauseParetoService($tieRepository);
$tieReport = $tieService->buildParetoReport(paretoFilter());

$assert(
    'El empate entre causas se resuelve por código ascendente (C6)',
    $tieReport->getItems()[0]['root_cause_code'] === 'RC-001'
        && $tieReport->getItems()[1]['root_cause_code'] === 'RC-002'
);

// --- Desempate entre soluciones -------------------------------------------

$solutionTieRepository = new FakeParetoAnalyticsRepository();
$solutionTieRepository->rows = [
    paretoRow(1, 9, 'RC-009', 90, 'SOL-009B', ['current_standard_solution_is_default' => false]),
    paretoRow(2, 9, 'RC-009', 91, 'SOL-009A', ['current_standard_solution_is_default' => true]),
    paretoRow(3, 10, 'RC-010', 100, 'SOL-010Z', ['current_standard_solution_is_default' => false]),
    paretoRow(4, 10, 'RC-010', 101, 'SOL-010A', ['current_standard_solution_is_default' => false]),
];
$solutionTieService = new RootCauseParetoService($solutionTieRepository);
$solutionTieItems = $solutionTieService->buildParetoReport(paretoFilter())->getItems();

$assert(
    'El empate de soluciones favorece la predeterminada (C6)',
    $solutionTieItems[0]['top_solution']['id'] === 91
        && $solutionTieItems[0]['top_solution']['applications_count'] === 1
);

$assert(
    'Sin predeterminada, el empate de soluciones se resuelve por código ascendente (C6)',
    $solutionTieItems[1]['top_solution']['id'] === 101
        && $solutionTieItems[1]['top_solution']['code'] === 'SOL-010A'
);

// --- Redondeo de un tercio ------------------------------------------------

$roundingRepository = new FakeParetoAnalyticsRepository();
$roundingRepository->rows = [
    paretoRow(1, 1, 'RC-001', 10, 'SOL-001A'),
    paretoRow(2, 2, 'RC-002', 20, 'SOL-002A'),
    paretoRow(3, 3, 'RC-003', 30, 'SOL-003A'),
];
$roundingItems = (new RootCauseParetoService($roundingRepository))
    ->buildParetoReport(paretoFilter())
    ->getItems();

$assert(
    'Los tercios se muestran a 2 decimales y el acumulado cierra en 100.00 %',
    $roundingItems[0]['percentage'] === 33.33
        && $roundingItems[1]['percentage'] === 33.33
        && $roundingItems[2]['percentage'] === 33.33
        && $roundingItems[0]['cumulative_percentage'] === 33.33
        && $roundingItems[1]['cumulative_percentage'] === 66.67
        && $roundingItems[2]['cumulative_percentage'] === 100.0
);

// --- Estado vacío seguro ---------------------------------------------------

$emptyRepository = new FakeParetoAnalyticsRepository();
$emptyRepository->rows = [
    paretoRow(1, null, '', null, ''),
    paretoRow(2, null, '', null, ''),
];
$emptyReport = (new RootCauseParetoService($emptyRepository))->buildParetoReport(paretoFilter());
$emptySummary = $emptyReport->getSummary();

$assert(
    'Sin averías clasificadas devuelve estado vacío sin división por cero (C6)',
    $emptyReport->isEmpty()
        && $emptyReport->getItems() === []
        && $emptySummary['classified_incidents_count'] === 0
        && $emptySummary['unclassified_historical_count'] === 2
        && $emptySummary['total_causes_with_incidents'] === 0
        && $emptySummary['vital_few_causes_count'] === 0
        && $emptySummary['total_replaced_parts_cost_eur'] === 0.0
);

// --- Filtro por fabricante normalizado ------------------------------------

$manufacturerRepository = new FakeParetoAnalyticsRepository();
$manufacturerRepository->rows = [
    paretoRow(1, 1, 'RC-001', 10, 'SOL-001A', ['machine_model' => 'NECTA KIKKO MAX']),
    paretoRow(2, 1, 'RC-001', 10, 'SOL-001A', ['machine_model' => '  necta   Astro ']),
    paretoRow(3, 2, 'RC-002', 20, 'SOL-002A', ['machine_model' => 'Jofemar Vision ES']),
    paretoRow(4, 2, 'RC-002', 20, 'SOL-002A', ['machine_model' => 'Sanden Vendo G-Drink']),
    paretoRow(5, null, '', null, '', ['machine_model' => 'Necta Samba Combo']),
];
$manufacturerRepository->options = [
    'models' => ['NECTA KIKKO MAX', 'necta Astro', 'Jofemar Vision ES', 'Sanden Vendo G-Drink'],
    'locations' => [],
    'technicians' => [],
];
$manufacturerService = new RootCauseParetoService($manufacturerRepository);
$manufacturerFilter = paretoFilter(['manufacturer' => 'necta']);
$manufacturerReport = $manufacturerService->buildParetoReport($manufacturerFilter);

$assert(
    'El filtro de fabricante normaliza mayúsculas y espacios (QA 4)',
    $manufacturerReport->getSummary()['total_incidents_in_period'] === 3
        && $manufacturerReport->getSummary()['classified_incidents_count'] === 2
        && $manufacturerReport->getSummary()['unclassified_historical_count'] === 1
        && $manufacturerRepository->lastFilter === $manufacturerFilter
);

$manufacturerOptions = $manufacturerReport->getAvailableFilters();
$assert(
    'Los filtros disponibles derivan el fabricante y agrupan sus modelos (QA 4)',
    $manufacturerOptions['manufacturers'] === ['Jofemar', 'Necta', 'Sanden']
        && $manufacturerOptions['models_by_manufacturer']['Necta'] === ['NECTA KIKKO MAX', 'necta Astro']
        && $manufacturerOptions['models'] === ['NECTA KIKKO MAX', 'necta Astro', 'Jofemar Vision ES', 'Sanden Vendo G-Drink']
);

// --- Filtro de Zona Vital -------------------------------------------------

$vitalRepository = new FakeParetoAnalyticsRepository();
$vitalRepository->rows = paretoFixtureRows();
$vitalReport = (new RootCauseParetoService($vitalRepository))->buildParetoReport(
    paretoFilter(['vitalFewOnly' => true])
);

$assert(
    'El conmutador de Zona Vital filtra las filas sin alterar el resumen global',
    count($vitalReport->getItems()) === 2
        && $vitalReport->getSummary()['total_causes_with_incidents'] === 3
        && $vitalReport->getSummary()['classified_incidents_count'] === 10
);

// ---------------------------------------------------------------------------
// 3. Exportación CSV UTF-8 BOM en dos secciones
// ---------------------------------------------------------------------------

echo "\n· Exportación CSV de garantías en dos secciones\n";

$csvRepository = new FakeParetoAnalyticsRepository();
$csvRepository->rows = paretoFixtureRows();
$csvRepository->options = [
    'models' => ['NECTA KIKKO MAX', 'Jofemar Vision'],
    'locations' => [],
    'technicians' => [],
];
$csvAudit = new ParetoAuditLog();
$csvService = new RootCauseParetoService($csvRepository, new AuditLogger($csvAudit));
$csv = $csvService->exportCsv(paretoFilter(), [
    'id' => 7,
    'role' => 'COORDINATOR',
    'name' => 'Ana Coordinadora',
]);

$assert(
    'El CSV empieza por el BOM UTF-8 y usa el separador punto y coma',
    str_starts_with($csv, "\xEF\xBB\xBF")
        && str_contains($csv, 'Periodo Desde;2026-08-01;Periodo Hasta;2026-10-31')
        && str_contains($csv, 'SECCIÓN 1: TABLA AGREGADA DE PARETO (REGLA 80/20)')
        && str_contains($csv, 'SECCIÓN 2: DETALLE NOMINAL DE EXPEDIENTES PARA RECLAMACIÓN A FABRICANTE')
);

$assert(
    'El CSV publica los contadores de clasificadas y sin clasificar (C3)',
    str_contains($csv, 'Averías Clasificadas (Base 100% Pareto);10;Averías Históricas Sin Clasificar (Excluidas);2')
);

$assert(
    'La Sección 1 usa el nombre vigente, la zona vital y los decimales con coma',
    str_contains($csv, '1;RC-PAY-01;PAYMENT_SYSTEM;"Moneda doblada; ficha en el selector";5;50,00;50,00;SÍ (VITAL 80%);3,00;5;50,00;SOL-PAY-01A;Solución SOL-PAY-01A')
);

$lines = preg_split('/\r\n/', $csv) ?: [];
$nominalLine = '';
foreach ($lines as $line) {
    if (str_starts_with($line, 'INC-2026-0001;')) {
        $nominalLine = $line;
        break;
    }
}

$assert(
    'La Sección 2 conserva el nombre vigente y el congelado al cierre (QA 2)',
    $nominalLine !== ''
        && str_contains($nominalLine, 'Moneda doblada; ficha en el selector')
        && str_contains($nominalLine, 'Moneda doblada en el selector')
        && str_contains($nominalLine, 'SOL-PAY-01A')
        && str_contains($nominalLine, '1,00;1;10,00')
);

$assert(
    'Los campos con punto y coma se entrecomillan para no romper columnas',
    str_contains($csv, '"Moneda doblada; ficha en el selector"')
);

$assert(
    'La exportación registra su evento de auditoría bajo ROOT_CAUSE_TAXONOMY',
    count($csvAudit->events) === 1
        && $csvAudit->events[0]->getEntityType() === 'ROOT_CAUSE_TAXONOMY'
        && $csvAudit->events[0]->getAction() === 'EXPORT_ROOT_CAUSE_PARETO_CSV'
        && $csvAudit->events[0]->getNewState()['rows_exported'] === 10
        && $csvAudit->events[0]->getNewState()['filters']['from'] === '2026-08-01'
);

$csvWithoutActor = $csvService->exportCsv(paretoFilter());
$assert(
    'Sin actor autenticado no se inventa un evento de auditoría',
    $csvWithoutActor !== '' && count($csvAudit->events) === 1
);

$emptyCsvRepository = new FakeParetoAnalyticsRepository();
$emptyCsvRepository->rows = [paretoRow(1, null, '', null, '')];
$emptyCsv = (new RootCauseParetoService($emptyCsvRepository))->exportCsv(paretoFilter());
$assert(
    'El CSV vacío conserva las dos secciones y el BOM sin filas de datos',
    str_starts_with($emptyCsv, "\xEF\xBB\xBF")
        && str_contains($emptyCsv, 'Averías Clasificadas (Base 100% Pareto);0')
        && str_contains($emptyCsv, 'SECCIÓN 2')
);

// ---------------------------------------------------------------------------
// 4. Contrato del DTO de informe
// ---------------------------------------------------------------------------

echo "\n· Contrato del DTO de informe\n";

$reportArray = $report->toArray();
$assert(
    'El informe publica los cuatro bloques del contrato §6.1',
    array_keys($reportArray) === ['filters', 'available_filters', 'summary', 'items']
        && array_keys($reportArray['summary']) === RootCauseParetoReportDto::SUMMARY_KEYS
);

$assertThrows(
    'Un resumen incompleto se rechaza en el DTO',
    fn () => new RootCauseParetoReportDto([], [], ['classified_incidents_count' => 1], []),
    InvalidArgumentException::class,
    'clave obligatoria'
);

// ---------------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------------

$total = $assertions;
$passed = $assertions - $failures;

echo "\n----------------------------------------------------------------------\n";
printf(" Resultado: %d/%d comprobaciones en verde (%d fallos)\n", $passed, $total, $failures);
echo "----------------------------------------------------------------------\n";

exit($failures === 0 ? 0 : 1);
