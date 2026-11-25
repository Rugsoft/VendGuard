<?php

declare(strict_types=1);

/**
 * RestockingBiServiceTest · T-RBI-03
 *
 * Certifica el ensamblado del informe y la exportación CSV del Cuadro de Mando
 * de Reposición (Módulo 38; `RF-01.4`, `RF-03.2`, `RF-06.3`, `D2`, `D5`, `C2`,
 * `C5`, `QA-1`, Art. III.3 y Art. V.4).
 *
 * Bloques que cubre:
 *
 *   1. **Orquestación (`RF-01`).** El servicio pide una consulta por fuente y
 *      entrega al calculador las filas del repositorio; el informe publica los
 *      siete bloques del contrato con las mismas cifras que el JSON.
 *   2. **Bloqueo térmico (`D2`).** Con `restocker_id`, la térmica no se
 *      segmenta: `null` en el resumen y por máquina, y el aviso explícito en la
 *      comparativa; el repositorio ya no entrega actas en ese modo.
 *   3. **CSV (`RF-06.3`, `C5`).** BOM UTF-8, separador `;`, aviso de
 *      confidencialidad, cinco secciones presentes incluso sin filas, fallbacks
 *      `Sin zona` / `—` y neutralización `=+-@\t\r`.
 *   4. **Auditoría (`QA-1`, Art. III.3).** `EXPORT_RESTOCKING_BI_CSV` bajo
 *      `ENTITY_MACHINE_RESTOCK_VISIT` con `entity_id = 0`, actor, filtros y
 *      marca `Europe/Madrid`; la guarda ordinaria de `AuditEvent` sigue
 *      rechazando identificadores no positivos.
 *
 * CATEGORÍA: hermética (`tests/unit/`). Repositorio y auditoría son dobles en
 * memoria; no hay PDO, ni reloj del sistema, ni dependencias externas.
 *
 * Dogma Vanilla: PHP 8.2+ puro.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\RestockingBiAnalyticsService;
use VendGuard\Application\Service\RestockingBiExportService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockingBiAnalyticsRepositoryInterface;

$assertions = 0;

function assertServiceCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Repositorio en memoria con las filas ya agregadas por el PDO real.
 */
final class FakeRestockingBiRepository implements RestockingBiAnalyticsRepositoryInterface
{
    /**
     * @param list<array<string, mixed>> $machineCatalog
     * @param list<array<string, mixed>> $visitHeaders
     * @param list<array<string, mixed>> $slotItems
     * @param list<array<string, mixed>> $productCatalog
     * @param list<array<string, mixed>> $slotItemsByRestocker
     * @param list<array<string, mixed>> $thermalRecords
     * @param list<array<string, mixed>> $validShifts
     * @param list<array<string, mixed>> $cashCollections
     * @param list<array<string, mixed>> $l1Incidents
     * @param array{zones: list<array<string, mixed>>, locations: list<array<string, mixed>>, restockers: list<array<string, mixed>>} $availableFilters
     */
    public function __construct(
        private readonly array $machineCatalog = [],
        private readonly array $visitHeaders = [],
        private readonly array $slotItems = [],
        private readonly array $productCatalog = [],
        private readonly array $slotItemsByRestocker = [],
        private readonly array $thermalRecords = [],
        private readonly array $validShifts = [],
        private readonly array $cashCollections = [],
        private readonly array $l1Incidents = [],
        private readonly array $availableFilters = ['zones' => [], 'locations' => [], 'restockers' => []]
    ) {
    }

    public function fetchMachineCatalog(RestockingBiFilterDto $filter): array
    {
        return $this->machineCatalog;
    }

    public function fetchVisitHeaders(RestockingBiFilterDto $filter): array
    {
        return $this->visitHeaders;
    }

    public function fetchSlotItemsByMachineProduct(RestockingBiFilterDto $filter): array
    {
        return $this->slotItems;
    }

    public function fetchProductCatalog(RestockingBiFilterDto $filter): array
    {
        return $this->productCatalog;
    }

    public function fetchSlotItemsByRestocker(RestockingBiFilterDto $filter): array
    {
        return $this->slotItemsByRestocker;
    }

    public function fetchThermalRecords(RestockingBiFilterDto $filter): array
    {
        // Mismo contrato que el PDO: con reponedor seleccionado no hay térmica.
        return $filter->restockerId() === null ? $this->thermalRecords : [];
    }

    public function fetchValidShifts(RestockingBiFilterDto $filter): array
    {
        return $filter->hasTerritorialFilter() ? [] : $this->validShifts;
    }

    public function fetchCashCollections(RestockingBiFilterDto $filter): array
    {
        return $this->cashCollections;
    }

    public function fetchL1Incidents(RestockingBiFilterDto $filter): array
    {
        return $this->l1Incidents;
    }

    public function fetchAvailableFilters(RestockingBiFilterDto $filter): array
    {
        return $this->availableFilters;
    }
}

/**
 * Auditoría en memoria: captura los eventos sin tocar `audit_log`.
 */
final class FakeAuditLogRepository implements AuditLogRepositoryInterface
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
        return [];
    }

    public function countEvents(array $filters = []): int
    {
        return 0;
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return [];
    }
}

/**
 * Escenario canónico: dos máquinas, dos reponedores, una sede sin zona y una
 * baja lógica con actividad.
 */
function buildRestockingBiScenario(): FakeRestockingBiRepository
{
    return new FakeRestockingBiRepository(
        machineCatalog: [
            [
                'machine_id' => 1,
                'machine_code' => 'MAQ-HOSP-02',
                'machine_type' => 'PERISHABLE_FOOD',
                'location_id' => 10,
                'location_name' => 'Hospital Central',
                'zone_id' => 1,
                'zone_name' => 'ZN-NORTE',
                'is_active' => true,
            ],
            [
                'machine_id' => 2,
                'machine_code' => 'MAQ-CEIP-01',
                'machine_type' => 'SNACKS',
                'location_id' => 11,
                'location_name' => 'Sede Sin Zona',
                'zone_id' => null,
                'zone_name' => null,
                'is_active' => true,
            ],
        ],
        visitHeaders: [
            [
                'machine_id' => 1,
                'restocker_id' => 1,
                'completed_visits_count' => 2,
                'clean_in_stock_visits_count' => 1,
                'thermal_aborted_visits_count' => 0,
                'completed_duration_seconds_sum' => 1800.0,
            ],
            [
                'machine_id' => 1,
                'restocker_id' => 2,
                'completed_visits_count' => 0,
                'clean_in_stock_visits_count' => 0,
                'thermal_aborted_visits_count' => 1,
                'completed_duration_seconds_sum' => 300.0,
            ],
            [
                'machine_id' => 2,
                'restocker_id' => 2,
                'completed_visits_count' => 1,
                'clean_in_stock_visits_count' => 1,
                'thermal_aborted_visits_count' => 0,
                'completed_duration_seconds_sum' => 600.0,
            ],
            [
                'machine_id' => 2,
                'restocker_id' => 1,
                'completed_visits_count' => 1,
                'clean_in_stock_visits_count' => 0,
                'thermal_aborted_visits_count' => 0,
                'completed_duration_seconds_sum' => 300.0,
            ],
        ],
        slotItems: [
            [
                'machine_id' => 1,
                'product_id' => 1,
                'audited_slots_count' => 3,
                'stockout_slots_count' => 1,
                'low_threshold_slots_count' => 1,
                'added_units' => 17,
                'expired_units' => 2,
                'damaged_units' => 1,
                'expired_loss_eur' => 5.00,
                'damaged_loss_eur' => 2.50,
                'commercial_spoilage_loss_eur' => 7.50,
            ],
            [
                'machine_id' => 2,
                'product_id' => 2,
                'audited_slots_count' => 1,
                'stockout_slots_count' => 0,
                'low_threshold_slots_count' => 0,
                'added_units' => 5,
                'expired_units' => 3,
                'damaged_units' => 0,
                'expired_loss_eur' => 3.60,
                'damaged_loss_eur' => 0.00,
                'commercial_spoilage_loss_eur' => 3.60,
            ],
        ],
        productCatalog: [
            [
                'product_id' => 1,
                'sku' => 'SAND-MIXTO-01',
                'name' => 'Sándwich Mixto',
                'category' => 'PERISHABLE_FOOD',
                'is_active' => true,
            ],
            [
                'product_id' => 2,
                'sku' => 'PATATAS-01',
                'name' => '=Patatas Fritas',
                'category' => 'SNACK',
                'is_active' => false,
            ],
        ],
        slotItemsByRestocker: [
            [
                'restocker_id' => 1,
                'audited_slots_count' => 3,
                'stockout_slots_count' => 1,
                'low_threshold_slots_count' => 1,
                'added_units' => 17,
                'expired_units' => 2,
                'damaged_units' => 1,
                'expired_loss_eur' => 5.00,
                'damaged_loss_eur' => 2.50,
                'commercial_spoilage_loss_eur' => 7.50,
            ],
            [
                'restocker_id' => 2,
                'audited_slots_count' => 1,
                'stockout_slots_count' => 0,
                'low_threshold_slots_count' => 0,
                'added_units' => 5,
                'expired_units' => 3,
                'damaged_units' => 0,
                'expired_loss_eur' => 3.60,
                'damaged_loss_eur' => 0.00,
                'commercial_spoilage_loss_eur' => 3.60,
            ],
        ],
        thermalRecords: [
            [
                'machine_id' => 1,
                'records_count' => 2,
                'discarded_units_count' => 4,
                'estimated_merchandise_loss_eur' => 10.00,
            ],
        ],
        validShifts: [
            ['technician_id' => 1, 'valid_shifts_count' => 1],
        ],
        cashCollections: [
            [
                'restocker_id' => 1,
                'reconciled_ok_count' => 1,
                'discrepancy_flagged_count' => 1,
                'pending_audit_count' => 1,
                'total_abs_discrepancy_eur' => 3.50,
            ],
        ],
        l1Incidents: [
            ['restocker_id' => 1, 'l1_resolved_count' => 1, 'escalated_to_sat_count' => 1],
        ],
        availableFilters: [
            'zones' => [
                ['id' => 1, 'code' => 'ZN-NORTE', 'name' => 'Norte', 'is_active' => true],
            ],
            'locations' => [
                ['id' => 10, 'site_code' => 'HOSP-01', 'name' => 'Hospital Central', 'zone_id' => 1, 'is_active' => true],
                ['id' => 11, 'site_code' => 'EXT-04', 'name' => 'Sede Sin Zona', 'zone_id' => null, 'is_active' => true],
            ],
            'restockers' => [
                ['id' => 1, 'operator_code' => 'REP-01', 'name' => 'Lucía Navarro', 'is_active' => true],
                ['id' => 2, 'operator_code' => null, 'name' => '@Carlos Méndez', 'is_active' => false],
                ['id' => 3, 'operator_code' => 'REP-03', 'name' => 'Marta Ibáñez', 'is_active' => true],
            ],
        ]
    );
}

try {
    $filter = new RestockingBiFilterDto('2026-11-07', '2026-11-20');
    $service = new RestockingBiAnalyticsService(buildRestockingBiScenario());
    $report = $service->buildReport($filter);

    echo "--- Informe ensamblado ---\n";

    $summary = $report->summary();
    assertServiceCondition(
        $summary['completed_visits_count'] === 4
        && $summary['clean_in_stock_visits_count'] === 2
        && $summary['visit_in_stock_rate_pct'] === 50.0
        && $summary['visit_stockout_rate_pct'] === 50.0
        && $summary['thermal_aborted_visits_count'] === 1,
        'Disponibilidad dual exacta y aborto térmico fuera del denominador (D1)'
    );
    assertServiceCondition(
        $summary['audited_slots_count'] === 4
        && $summary['stockout_slots_count'] === 1
        && $summary['slot_stockout_rate_pct'] === 25.0
        && $summary['low_threshold_slots_count'] === 1,
        'Carriles agregados sin duplicar por visitas (RF-02.2)'
    );
    assertServiceCondition(
        $summary['commercial_spoilage_eur'] === 11.10
        && $summary['commercial_spoilage_units'] === 6
        && $summary['thermal_spoilage_eur'] === 10.00
        && $summary['thermal_spoilage_units'] === 4
        && $summary['total_spoilage_eur'] === 21.10,
        'Merma comercial y térmica exactas al céntimo (D2)'
    );
    assertServiceCondition(
        $summary['cash_reconciled_ok_count'] === 1
        && $summary['cash_discrepancy_flagged_count'] === 1
        && $summary['cash_pending_audit_count'] === 1
        && $summary['cash_accuracy_rate_pct'] === 50.0
        && $summary['oversized_machines_count'] === 1,
        'KPIs de arqueos y planograma sobredimensionado (RF-05, D3)'
    );

    assertServiceCondition(
        $report->byMachine()[0]['machine_code'] === 'MAQ-HOSP-02'
        && $report->byMachine()[0]['thermal_aborted_visits_count'] === 1
        && $report->byMachine()[1]['is_oversized_planogram'] === true,
        'Prioridad sanitaria y planograma sobredimensionado ordenan by_machine (QA-2)'
    );
    assertServiceCondition(
        $report->byProduct()[0]['sku'] === 'SAND-MIXTO-01'
        && $report->byProduct()[0]['commercial_spoilage_loss_eur'] === 7.50
        && $report->byProduct()[1]['expiry_rate_pct'] === 37.5,
        'by_product conserva SKU, coste y tasa de caducidad (RF-04)'
    );
    assertServiceCondition(
        $report->byRestocker()[0]['name'] === 'Lucía Navarro'
        && $report->byRestocker()[0]['machines_per_shift'] === 3.0
        && $report->byRestocker()[0]['avg_stop_duration_minutes'] === 11.7
        && $report->byRestocker()[0]['cash_accuracy_rate_pct'] === 50.0,
        'by_restocker calcula turnos, parada real y exactitud de arqueos (C3, RF-05)'
    );
    assertServiceCondition(
        $report->byRestocker()[1]['operator_code'] === null
        && $report->byRestocker()[1]['is_active'] === false
        && $report->byRestocker()[1]['machines_per_shift'] === null
        && $report->byRestocker()[1]['machines_per_shift_na_reason'] === 'NO_VALID_SHIFTS',
        'La baja lógica y el motivo nulo de máquinas por turno se publican (QA-4, C3)'
    );
    assertServiceCondition(
        count($report->charts()['spoilage_bars']['labels']) === 3
        && $report->charts()['spoilage_bars']['thermal_segmentable'] === true,
        'La comparativa incluye la barra térmica cuando es segmentable (D5)'
    );
    assertServiceCondition(
        $report->filters() === $filter->toArray()
        && array_keys($report->availableFilters()) === ['zones', 'locations', 'restockers'],
        'El informe publica filtros y catálogos del contrato'
    );

    echo "--- CSV de cinco secciones ---\n";

    $exportService = new RestockingBiExportService($service);
    $csv = $exportService->renderCsv($report, '2026-11-24 12:00:00');

    assertServiceCondition(str_starts_with($csv, "\xEF\xBB\xBF"), 'El CSV empieza por BOM UTF-8');
    assertServiceCondition(
        substr_count($csv, 'SECCIÓN ') === 5
        && str_contains($csv, 'SECCIÓN 1: RESUMEN EJECUTIVO')
        && str_contains($csv, 'SECCIÓN 2: COMPARATIVA DE MERMAS')
        && str_contains($csv, 'SECCIÓN 3: RENDIMIENTO Y PLANOGRAMA POR MÁQUINA')
        && str_contains($csv, 'SECCIÓN 4: ROTACIÓN Y ROTURAS POR PRODUCTO / SKU')
        && str_contains($csv, 'SECCIÓN 5: RENDIMIENTO OPERATIVO Y ARQUEOS POR REPONEDOR'),
        'El CSV publica siempre las cinco secciones'
    );
    assertServiceCondition(
        str_contains($csv, RestockingBiExportService::CONFIDENTIALITY_NOTICE)
        && str_contains($csv, 'Exportado_El;2026-11-24 12:00:00')
        && str_contains($csv, 'Arqueos_OK;1')
        && str_contains($csv, 'Arqueos_Descuadre;1'),
        'La sección 1 lleva confidencialidad, marca y exactitud de arqueos (Art. V.4, QA-C4)'
    );
    assertServiceCondition(
        str_contains($csv, "Maquina;Estado;Tipo;Sede;Zona;Visitas_Completadas")
        && str_contains($csv, "SKU;Producto;Estado;Categoria;Carriles_Auditados")
        && str_contains($csv, "Codigo_Operador;Reponedor;Estado;Visitas_Completadas")
        && str_contains($csv, 'MAQ-HOSP-02;Activa;PERISHABLE_FOOD;Hospital Central;ZN-NORTE'),
        'Las secciones 3, 4 y 5 respetan las columnas y los valores del contrato'
    );
    assertServiceCondition(
        str_contains($csv, "Avería Térmica APPCC;4;10,00;")
        && str_contains($csv, 'Sin zona')
        && str_contains($csv, "—;'@Carlos Méndez;Baja;1;1;0;—;NO_VALID_SHIFTS"),
        'La térmica se compara, la sede sin zona cae a Sin zona y el operador nulo a — (QA-A3)'
    );
    assertServiceCondition(
        str_contains($csv, "'=Patatas Fritas")
        && str_contains($csv, "'@Carlos Méndez"),
        'Las celdas peligrosas se neutralizan antes de citarse (RF-06.3)'
    );

    $neutralizer = new RestockingBiExportService($service);
    foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tTAB", "\rCR"] as $dangerous) {
        assertServiceCondition(
            $neutralizer->neutralizeCsvCell($dangerous) === "'" . $dangerous,
            'Neutralización uniforme de «' . addcslashes($dangerous, "\t\r") . '»'
        );
    }

    echo "--- Térmica no segmentable ---\n";

    $restockerFilter = new RestockingBiFilterDto('2026-11-07', '2026-11-20', null, null, 2);
    $restockerReport = $service->buildReport($restockerFilter);
    $restockerSummary = $restockerReport->summary();

    assertServiceCondition(
        $restockerSummary['thermal_spoilage_eur'] === null
        && $restockerSummary['thermal_spoilage_units'] === null
        && $restockerSummary['total_spoilage_eur'] === null,
        'Con reponedor la térmica global queda en null (D2)'
    );
    assertServiceCondition(
        $restockerReport->spoilageComparison()['thermal_haccp']['segmentable'] === false
        && $restockerReport->spoilageComparison()['thermal_haccp']['non_segmentable_message']
            === 'No segmentable por reponedor (ámbito máquina/SAT)',
        'La comparativa publica el aviso de no segmentación (D2)'
    );
    assertServiceCondition(
        $restockerReport->byMachine()[0]['thermal_spoilage_units'] === null
        && $restockerReport->byMachine()[0]['thermal_spoilage_loss_eur'] === null,
        'Cada fila de by_machine anula la térmica no segmentable (D2)'
    );

    $restockerCsv = $exportService->renderCsv($restockerReport, '2026-11-24 12:00:00');
    assertServiceCondition(
        str_contains($restockerCsv, 'Avería Térmica APPCC;—;—;No segmentable por reponedor')
        && str_contains($restockerCsv, 'Merma_Termica_EUR;—'),
        'El CSV refleja la térmica no segmentable con el aviso (D2)'
    );

    echo "--- Periodo vacío ---\n";

    $emptyService = new RestockingBiAnalyticsService(new FakeRestockingBiRepository());
    $emptyCsv = (new RestockingBiExportService($emptyService))
        ->renderCsv($emptyService->buildReport($filter), '2026-11-24 12:00:00');

    assertServiceCondition(
        str_starts_with($emptyCsv, "\xEF\xBB\xBF")
        && substr_count($emptyCsv, 'SECCIÓN ') === 5
        && str_contains($emptyCsv, 'Disponibilidad_Visita_Pct;—')
        && str_contains($emptyCsv, 'Arqueos_OK;0'),
        'Un periodo vacío sigue produciendo un CSV válido con cabeceras (C5)'
    );

    echo "--- Auditoría de exportación ---\n";

    $auditRepo = new FakeAuditLogRepository();
    $exportWithAudit = new RestockingBiExportService(
        $service,
        new AuditLogger($auditRepo)
    );
    $auditedCsv = $exportWithAudit->exportCsv($filter, [
        'id' => 9,
        'role' => 'COORDINATOR',
        'name' => 'Coordinación de Servicio',
    ]);

    assertServiceCondition(str_starts_with($auditedCsv, "\xEF\xBB\xBF"), 'La descarga auditada conserva el BOM');
    assertServiceCondition(count($auditRepo->events) === 1, 'La exportación deja exactamente un evento');
    $event = $auditRepo->events[0];
    assertServiceCondition(
        $event->getEntityType() === AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT
        && $event->getEntityId() === 0
        && $event->getAction() === 'EXPORT_RESTOCKING_BI_CSV'
        && $event->getUserId() === 9
        && $event->getUserRole() === 'COORDINATOR'
        && $event->getPreviousState() === null,
        'El evento usa ENTITY_MACHINE_RESTOCK_VISIT con entity_id 0 y el actor (QA-1)'
    );
    assertServiceCondition(
        $event->getNewState()['from'] === '2026-11-07'
        && $event->getNewState()['to'] === '2026-11-20'
        && $event->getNewState()['zone_id'] === null
        && preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string)($event->getNewState()['exported_at'] ?? '')
        ) === 1,
        'El evento guarda los filtros y la marca Europe/Madrid de la exportación (QA-1)'
    );

    $ordinaryGuardRejected = false;
    try {
        new AuditEvent(
            null,
            AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT,
            0,
            'EXPORT_RESTOCKING_BI_CSV',
            9,
            'COORDINATOR',
            'Coordinación',
            null,
            []
        );
    } catch (InvalidArgumentException) {
        $ordinaryGuardRejected = true;
    }
    assertServiceCondition(
        $ordinaryGuardRejected
        && AuditEvent::forGlobalExport(
            AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT,
            'EXPORT_RESTOCKING_BI_CSV',
            9,
            'COORDINATOR',
            'Coordinación',
            null,
            []
        )->getEntityId() === 0,
        'La guarda ordinaria sigue rechazando el cero y solo la fábrica global lo admite'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RBI-03 · Servicios BI y exportación CSV de reposición\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
