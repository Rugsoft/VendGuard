<?php

declare(strict_types=1);

/**
 * SparePartAnalyticsServiceTest
 * 
 * Test Unitario para SparePartAnalyticsService (Tarea T-SPARE-09).
 * Valida reglas de analítica, fiabilidad de componentes y exportación plana (RF-REP-08, RF-REP-09):
 * - Consolidación de panel analítico (ranking de piezas, costes por modelo y por sede).
 * - Detección de fallos crónicos / recurrentes (> 3 sustituciones en 90 días en la misma máquina).
 * - Generación de contenido plano CSV con Byte Order Mark (BOM) UTF-8 (\xEF\xBB\xBF).
 * - Conformidad estricta de cabeceras de columnas y formateo decimal monetario en exportación.
 * - Formato estandarizado de nombre de archivo CSV para descargas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\SparePartAnalyticsService;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;

echo "==========================================================================\n";
echo " VendGuard: Test Unitario - SparePartAnalyticsServiceTest (T-SPARE-09)\n";
echo "==========================================================================\n\n";

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

// =============================================================================
// Mock en Memoria de IncidentReplacedPartRepositoryInterface
// =============================================================================

class MockAnalyticsReplacedPartRepository implements IncidentReplacedPartRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $topParts = [];
    /** @var array<int, array<string, mixed>> */
    public array $modelCosts = [];
    /** @var array<int, array<string, mixed>> */
    public array $locationCosts = [];
    /** @var array<int, array<string, mixed>> */
    public array $chronicAlerts = [];
    /** @var array<int, array<string, mixed>> */
    public array $exportRecords = [];

    public ?int $lastQueriedPeriodDays = null;
    public ?int $lastQueriedLimit = null;
    public ?int $lastChronicWindowDays = null;
    public ?int $lastChronicThreshold = null;

    public function insertReplacedPart(IncidentReplacedPart $part): IncidentReplacedPart { return $part; }
    public function insertManyReplacedParts(array $parts): array { return $parts; }
    public function findById(int $id): ?IncidentReplacedPart { return null; }
    public function findByIncidentId(int $incidentId): array { return []; }
    public function findByPreventiveOrderId(int $preventiveOrderId): array { return []; }

    public function getCostSummaryByMachineModel(?int $periodDays = null): array
    {
        $this->lastQueriedPeriodDays = $periodDays;
        return $this->modelCosts;
    }

    public function getCostSummaryByLocation(?int $periodDays = null): array
    {
        $this->lastQueriedPeriodDays = $periodDays;
        return $this->locationCosts;
    }

    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array
    {
        $this->lastQueriedPeriodDays = $periodDays;
        $this->lastQueriedLimit = $limit;
        return array_slice($this->topParts, 0, $limit);
    }

    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array
    {
        $this->lastChronicWindowDays = $windowDays;
        $this->lastChronicThreshold = $threshold;
        return $this->chronicAlerts;
    }

    public function findAllForExport(?int $periodDays = null): array
    {
        $this->lastQueriedPeriodDays = $periodDays;
        return $this->exportRecords;
    }
}

// =============================================================================
// Fixtures de Prueba
// =============================================================================

function createAnalyticsEnvironment(): array {
    $repo = new MockAnalyticsReplacedPartRepository();

    // 1. Top repuestos sustituidos
    $repo->topParts = [
        [
            'part_code'        => 'VALV-ULKA-01',
            'name'             => 'Electroválvula 24V Ulka',
            'category'         => 'HYDRAULIC',
            'units_installed'  => 18,
            'accumulated_cost' => 513.00,
            'destinations'     => ['DESGUACE' => 12, 'TALLER' => 6],
        ],
        [
            'part_code'        => 'SOND-NTC-02',
            'name'             => 'Sonda Térmica NTC Frío 10k',
            'category'         => 'THERMAL',
            'units_installed'  => 10,
            'accumulated_cost' => 152.00,
            'destinations'     => ['DESGUACE' => 8, 'TALLER' => 2],
        ],
    ];

    // 2. Costes por modelo de máquina
    $repo->modelCosts = [
        [
            'model'          => 'Azkoyen Palma+',
            'machines_count' => 8,
            'units_replaced' => 24,
            'total_cost'     => 680.00,
        ],
        [
            'model'          => 'Fas Fast 900',
            'machines_count' => 5,
            'units_replaced' => 14,
            'total_cost'     => 394.50,
        ],
    ];

    // 3. Costes por sede
    $repo->locationCosts = [
        [
            'location_id'    => 3,
            'location_name'  => 'Hospital Universitario La Paz',
            'units_replaced' => 19,
            'total_cost'     => 542.00,
        ],
        [
            'location_id'    => 1,
            'location_name'  => 'Oficinas Centrales Repsol',
            'units_replaced' => 19,
            'total_cost'     => 532.50,
        ],
    ];

    // 4. Alertas de avería crónica (> 3 sustituciones en 90 días)
    $repo->chronicAlerts = [
        [
            'machine_id'             => 7,
            'machine_code'           => 'MAQ-HOSP-002',
            'machine_model'          => 'Fas Fast 900',
            'location_name'          => 'Hospital Universitario La Paz',
            'part_code'              => 'SOND-NTC-02',
            'part_name'              => 'Sonda Térmica NTC Frío 10k',
            'replacements_in_period' => 4,
            'threshold'              => 3,
            'first_replacement_at'   => '2026-07-10 11:20:00',
            'last_replacement_at'    => '2026-09-24 09:15:00',
            'severity'               => 'WARNING',
            'warning_message'        => 'Componente con Fallo Recurrente / Prematuro (4 sustituciones en 76 días)',
        ],
    ];

    // 5. Registros para exportación CSV
    $repo->exportRecords = [
        [
            'fecha'                => '2026-09-28 14:30:00',
            'codigo_intervencion'  => 'TICK-2026-00105',
            'tipo_intervencion'    => 'INCIDENT',
            'codigo_maquina'       => 'MAQ-MAD-001',
            'modelo_maquina'       => 'Azkoyen Palma+',
            'sede'                 => 'Oficinas Centrales Repsol',
            'codigo_pieza'         => 'VALV-ULKA-01',
            'nombre_pieza'         => 'Electroválvula 24V Ulka',
            'categoria'            => 'HYDRAULIC',
            'unidades'             => 1,
            'coste_unitario_eur'   => 28.50,
            'coste_total_eur'      => 28.50,
            'destino_retirado'     => 'TALLER',
            'codigo_tecnico'       => 'OP-02',
            'notas'                => 'Bobina eléctrica intacta',
        ],
        [
            'fecha'                => '2026-09-27 10:15:00',
            'codigo_intervencion'  => 'PREV-2026-00042',
            'tipo_intervencion'    => 'PREVENTIVE',
            'codigo_maquina'       => 'MAQ-HOSP-002',
            'modelo_maquina'       => 'Fas Fast 900',
            'sede'                 => 'Hospital Universitario La Paz',
            'codigo_pieza'         => 'SOND-NTC-02',
            'nombre_pieza'         => 'Sonda Térmica NTC Frío 10k',
            'categoria'            => 'THERMAL',
            'unidades'             => 2,
            'coste_unitario_eur'   => 15.20,
            'coste_total_eur'      => 30.40,
            'destino_retirado'     => 'DESGUACE',
            'codigo_tecnico'       => 'OP-05',
            'notas'                => 'Cambio preventivo por envejecimiento',
        ],
    ];

    $service = new SparePartAnalyticsService(replacedPartRepo: $repo);

    return [
        'service' => $service,
        'repo'    => $repo,
    ];
}

// =============================================================================
// 1. Panel Analítico y Consolidación de Totales (RF-REP-08)
// =============================================================================
echo "--- 1. Panel Analítico y Consolidación de Totales (RF-REP-08) ---\n";

$env = createAnalyticsEnvironment();
$service = $env['service'];
$repo = $env['repo'];

$analytics = $service->getAnalytics(periodDays: 90, rankingLimit: 10);

$assert(
    "getAnalytics devuelve la ventana temporal configurada (90 días)",
    $analytics['period_days'] === 90
);
$assert(
    "total_parts_replaced consolidado correctamente (24 + 14 = 38 unidades)",
    $analytics['total_parts_replaced'] === 38
);
$assert(
    "total_parts_cost consolidado correctamente (680.00 + 394.50 = 1074.50 €)",
    $analytics['total_parts_cost'] === 1074.50
);
$assert(
    "top_replaced_parts contiene 2 repuestos ordenados",
    count($analytics['top_replaced_parts']) === 2 &&
    $analytics['top_replaced_parts'][0]['part_code'] === 'VALV-ULKA-01' &&
    $analytics['top_replaced_parts'][0]['units_installed'] === 18
);
$assert(
    "costs_by_machine_model contiene 2 modelos desglosados",
    count($analytics['costs_by_machine_model']) === 2 &&
    $analytics['costs_by_machine_model'][0]['model'] === 'Azkoyen Palma+'
);
$assert(
    "costs_by_location contiene 2 sedes clientes",
    count($analytics['costs_by_location']) === 2 &&
    $analytics['costs_by_location'][0]['location_name'] === 'Hospital Universitario La Paz'
);
$assert(
    "chronic_failure_alerts incluye alertas detectadas",
    count($analytics['chronic_failure_alerts']) === 1 &&
    $analytics['chronic_failure_alerts'][0]['part_code'] === 'SOND-NTC-02'
);

// 1.2 Período por defecto cuando se pasa null o no positivo
$defaultAnalytics = $service->getAnalytics(periodDays: null);
$assert(
    "getAnalytics con null asume período por defecto de 90 días",
    $defaultAnalytics['period_days'] === 90
);

// =============================================================================
// 2. Detección de Fallos Recurrentes / Crónicos (RF-REP-08)
// =============================================================================
echo "\n--- 2. Detección de Fallos Recurrentes (RF-REP-08) ---\n";

$env = createAnalyticsEnvironment();
$service = $env['service'];
$repo = $env['repo'];

$alerts = $service->detectChronicFailures(windowDays: 90, threshold: 3);

$assert(
    "detectChronicFailures invoca al repositorio con ventana 90 y umbral 3",
    $repo->lastChronicWindowDays === 90 && $repo->lastChronicThreshold === 3
);
$assert(
    "Alerta identifica máquina MAQ-HOSP-002 y pieza SOND-NTC-02",
    count($alerts) === 1 &&
    $alerts[0]['machine_code'] === 'MAQ-HOSP-002' &&
    $alerts[0]['part_code'] === 'SOND-NTC-02'
);
$assert(
    "Alerta reporta 4 sustituciones superando el umbral de 3",
    $alerts[0]['replacements_in_period'] === 4 &&
    $alerts[0]['threshold'] === 3
);
$assert(
    "Alerta incluye mensaje formateado con sustituciones y días transcurridos",
    str_contains($alerts[0]['warning_message'], '4 sustituciones en 76 días')
);

// =============================================================================
// 3. Exportación en Formato Plano CSV UTF-8 (RF-REP-09)
// =============================================================================
echo "\n--- 3. Exportación Tabular CSV UTF-8 (RF-REP-09) ---\n";

$env = createAnalyticsEnvironment();
$service = $env['service'];
$repo = $env['repo'];

$csv = $service->exportCsv(periodDays: 90);

// 3.1 Verificación de Byte Order Mark (BOM) UTF-8
$expectedBom = "\xEF\xBB\xBF";
$assert(
    "El flujo CSV inicia obligatoriamente con el BOM UTF-8 (\\xEF\\xBB\\xBF) para Excel",
    str_starts_with($csv, $expectedBom)
);

// 3.2 Verificación de Cabeceras Contractuales
$lines = explode("\n", trim(substr($csv, strlen($expectedBom))));
$headerLine = trim($lines[0]);

$expectedHeaders = 'Fecha,Codigo_Intervencion,Tipo_Intervencion,Codigo_Maquina,Modelo_Maquina,Sede,Codigo_Pieza,Nombre_Pieza,Categoria,Unidades,Coste_Unitario_EUR,Coste_Total_EUR,Destino_Retirado,Codigo_Tecnico,Notas';
$assert(
    "La primera línea del CSV coincide exactamente con las 15 columnas contractuales",
    $headerLine === $expectedHeaders
);

// 3.3 Verificación de Filas de Datos y Escape
$assert(
    "El CSV contiene exactamente 2 filas de datos además de la cabecera",
    count($lines) === 3
);

$row1 = str_getcsv($lines[1]);
$assert(
    "Fila 1 mapea correctamente intervención correctiva TICK-2026-00105",
    $row1[0] === '2026-09-28 14:30:00' &&
    $row1[1] === 'TICK-2026-00105' &&
    $row1[2] === 'INCIDENT' &&
    $row1[3] === 'MAQ-MAD-001' &&
    $row1[4] === 'Azkoyen Palma+' &&
    $row1[5] === 'Oficinas Centrales Repsol' &&
    $row1[6] === 'VALV-ULKA-01' &&
    $row1[7] === 'Electroválvula 24V Ulka' &&
    $row1[8] === 'HYDRAULIC' &&
    $row1[9] === '1' &&
    $row1[10] === '28.50' &&
    $row1[11] === '28.50' &&
    $row1[12] === 'TALLER' &&
    $row1[13] === 'OP-02' &&
    $row1[14] === 'Bobina eléctrica intacta'
);

$row2 = str_getcsv($lines[2]);
$assert(
    "Fila 2 mapea correctamente orden preventiva PREV-2026-00042 con 2 unidades",
    $row2[1] === 'PREV-2026-00042' &&
    $row2[2] === 'PREVENTIVE' &&
    $row2[9] === '2' &&
    $row2[10] === '15.20' &&
    $row2[11] === '30.40' &&
    $row2[12] === 'DESGUACE'
);

// 3.4 Exportación con conjunto de datos vacío
$repo->exportRecords = [];
$emptyCsv = $service->exportCsv();
$emptyLines = explode("\n", trim(substr($emptyCsv, strlen($expectedBom))));

$assert(
    "Exportación vacía mantiene BOM UTF-8 y fila de cabeceras sin filas de datos",
    str_starts_with($emptyCsv, $expectedBom) && count($emptyLines) === 1
);

// =============================================================================
// 4. Nombre de Archivo CSV Estandarizado (RF-REP-09)
// =============================================================================
echo "\n--- 4. Formato de Nombre de Archivo CSV (RF-REP-09) ---\n";

$filenameFixed = $service->generateCsvFilename('20260928_1430');
$assert(
    "generateCsvFilename genera el nombre estándar: repuestos_intervenciones_YYYYMMDD_HHMM.csv",
    $filenameFixed === 'repuestos_intervenciones_20260928_1430.csv'
);

$filenameDynamic = $service->generateCsvFilename();
$assert(
    "generateCsvFilename sin parámetros genera nombre con fecha/hora actual válida",
    preg_match('/^repuestos_intervenciones_\d{8}_\d{4}\.csv$/', $filenameDynamic) === 1
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n==========================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "==========================================================================\n";

exit($failures === 0 ? 0 : 1);
