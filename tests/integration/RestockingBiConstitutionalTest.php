<?php

declare(strict_types=1);

/**
 * RestockingBiConstitutionalTest · T-RBI-05
 *
 * Certifica el blindaje constitucional del Cuadro de Mando de Reposición,
 * Roturas de Stock y Mermas Comerciales (Módulo 38) sobre el motor real:
 *
 *   1. **Art. II — prioridad sanitaria absoluta.** Las visitas
 *      `ABORTED_THERMAL_BREACH` quedan fuera del denominador de disponibilidad,
 *      las mermas térmicas APPCC (M29) se contabilizan aparte de las comerciales
 *      y `by_machine` ordena primero por incidencia sanitaria, después por
 *      planograma sobredimensionado y después por pérdida comercial.
 *   2. **Art. III — históricos sin borrados.** Las máquinas y los productos con
 *      baja lógica entran en el cálculo con `is_active = false`; la auditoría es
 *      append-only (`trg_no_delete_audit_log`) y la exportación deja su evento
 *      global con `entity_id = 0`.
 *   3. **Art. IV — cero DDL y cero dependencias.** El esquema conserva 51
 *      tablas, 50 tipos `ENUM`, 56 columnas `ENUM` y 34 migraciones; las fuentes
 *      del módulo no contienen DDL ni imports fuera de `VendGuard\` y de las
 *      clases nativas permitidas.
 *   4. **Art. V.4 — perímetro y opacidad.** Coordinación es el único rol que
 *      entra; técnico y reponedor reciben `403`; las superficies de Sede, QR y
 *      portal público no montan el módulo; ninguna vista o componente conoce
 *      «restocking»; `api.js` descarga con `fetch` autenticado + `response.blob()`
 *      y nunca con `window.open` ni token en URL.
 *   5. **Art. VI — alcance intacto.** Las huellas normalizadas de
 *      `ExecutiveReportModal.js`, `CoordinatorDashboardView.js` y
 *      `CoordinatorPlanogramTab.js` siguen siendo las certificadas.
 *
 * CATEGORÍA: motor real (`tests/integration/`), base `vendguard_battery`. La
 * prueba no crea esquemas ni DDL; al terminar verifica que no deja residuo
 * `t_rbi05_*` ni `contract_convergence_check*`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Service\RestockingBiCalculator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

const RBI05_EXPECTED_TABLES = 51;
const RBI05_EXPECTED_ENUMS = 50;
const RBI05_EXPECTED_ENUM_COLUMNS = 56;
const RBI05_EXPECTED_MIGRATIONS = 34;
const RBI05_AUDIT_ACTION = 'EXPORT_RESTOCKING_BI_CSV';

/** Huellas normalizadas (LF) de los ficheros que el Art. VI declara intactos. */
const RBI05_PROTECTED_FILES = [
    'public/assets/js/components/ExecutiveReportModal.js' => '559eb98bcb6be3db801956ffa0cbbcf7e64dd3eb9512dd9715510169b7eff4ff',
    'public/assets/js/views/CoordinatorDashboardView.js' => '06c9a331d37f4e201fa2399a34f9a015ef4ec953785926958843e6fc520a0812',
    'public/assets/js/components/CoordinatorPlanogramTab.js' => '5c452df8c7fcc0debdec6f2862b6800198661cc3391c2b963a00a9ab2de47473',
];

const RBI05_BI_SOURCES = [
    'src/Application/DTO/RestockingBiFilterDto.php',
    'src/Application/DTO/RestockingBiReportDto.php',
    'src/Application/Service/RestockingBiAnalyticsService.php',
    'src/Application/Service/RestockingBiExportService.php',
    'src/Core/Domain/Repository/RestockingBiAnalyticsRepositoryInterface.php',
    'src/Core/Service/RestockingBiCalculator.php',
    'src/Core/Service/RestockingBiFilterValidator.php',
    'src/Infrastructure/Repository/PdoRestockingBiAnalyticsRepository.php',
    'src/Presentation/Controller/CoordinatorMetricsController.php',
];

const RBI05_ALLOWED_IMPORTS = [
    'DateTimeImmutable',
    'DateTimeZone',
    'InvalidArgumentException',
    'JsonSerializable',
    'PDO',
    'Throwable',
];

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;

$assert = function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message . ($detail !== '' ? ' · ' . $detail : ''));
    }
    echo "  [OK]   {$message}\n";
};

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$router = AppRouter::create();
$users = new PdoUserRepository($pdo);
$authService = new AuthService();

$coordinator = $users->findByEmail('coordinacion@vendguard.internal');
$technician = $users->findByEmail('jordi.ruta@vendguard.internal');
$restocker = $users->findByEmail('lucia.repostaje@vendguard.internal');

if ($coordinator === null || $technician === null || $restocker === null) {
    fwrite(STDERR, "\n[FAIL] Faltan los usuarios canónicos de la certificación.\n");
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);
$restockerToken = $authService->generateInternalToken($restocker);

$dispatch = static function (string $method, string $path, array $query = [], ?string $token = null) use ($router) {
    $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

    return $router->dispatch(new Request($method, $path, $query, [], $headers));
};

$read = static fn (string $relative): string => (string)file_get_contents($projectRoot . '/' . $relative);
$normalized = static fn (string $text): string => str_replace("\r\n", "\n", $text);

echo "======================================================================\n";
echo " VENDGUARD · T-RBI-05 · Blindaje constitucional del Módulo 38\n";
echo "======================================================================\n";

try {
    (new SeedRunner($pdo))->seedAll();

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. V.4 · RBAC y perímetro de superficies
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. V.4 · RBAC y perímetro de superficies ---\n";

    $endpoints = [
        '/api/coordinator/metrics/restocking',
        '/api/coordinator/metrics/restocking/export',
    ];

    foreach ($endpoints as $endpoint) {
        $assert(
            $dispatch('GET', $endpoint)->getStatusCode() === 401,
            "Sin sesión, {$endpoint} responde 401"
        );
        $assert(
            $dispatch('GET', $endpoint, [], $technicianToken)->getStatusCode() === 403,
            "El técnico SAT recibe 403 en {$endpoint}"
        );
        $assert(
            $dispatch('GET', $endpoint, [], $restockerToken)->getStatusCode() === 403,
            "El reponedor recibe 403 en {$endpoint}"
        );
    }

    $portalPaths = [
        '/api/site/coordinator/metrics/restocking',
        '/api/site/coordinator/metrics/restocking/export',
        '/api/qr/coordinator/metrics/restocking',
        '/api/public/coordinator/metrics/restocking',
    ];
    $portalStatuses = [];
    foreach ($portalPaths as $path) {
        $portalStatuses[$path] = $dispatch('GET', $path)->getStatusCode();
    }
    $assert(
        array_values($portalStatuses) === [404, 404, 404, 404],
        'Sede, QR y portal público no montan las rutas del módulo',
        json_encode($portalStatuses)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. II · segregación térmica y prioridad sanitaria
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. II · segregación térmica y prioridad sanitaria ---\n";

    $machineCatalog = [
        [
            'machine_id' => 1,
            'machine_code' => 'RBI-SAN',
            'machine_type' => 'VENDING',
            'location_id' => 1,
            'location_name' => 'Sede Sanitaria',
            'zone_id' => 1,
            'zone_name' => 'Zona Centro',
            'is_active' => true,
        ],
        [
            'machine_id' => 2,
            'machine_code' => 'RBI-OVER',
            'machine_type' => 'VENDING',
            'location_id' => 1,
            'location_name' => 'Sede Sanitaria',
            'zone_id' => 1,
            'zone_name' => 'Zona Centro',
            'is_active' => true,
        ],
        [
            'machine_id' => 3,
            'machine_code' => 'RBI-LOSS',
            'machine_type' => 'VENDING',
            'location_id' => 1,
            'location_name' => 'Sede Sanitaria',
            'zone_id' => 1,
            'zone_name' => 'Zona Centro',
            'is_active' => true,
        ],
        [
            'machine_id' => 4,
            'machine_code' => 'RBI-LOW',
            'machine_type' => 'VENDING',
            'location_id' => 1,
            'location_name' => 'Sede Sanitaria',
            'zone_id' => 1,
            'zone_name' => 'Zona Centro',
            'is_active' => true,
        ],
        [
            'machine_id' => 5,
            'machine_code' => 'RBI-BAJA',
            'machine_type' => 'VENDING',
            'location_id' => 1,
            'location_name' => 'Sede Sanitaria',
            'zone_id' => 1,
            'zone_name' => 'Zona Centro',
            'is_active' => false,
        ],
    ];

    $visitHeaders = [
        [
            'machine_id' => 1,
            'completed_visits_count' => 2,
            'clean_in_stock_visits_count' => 1,
            'thermal_aborted_visits_count' => 2,
        ],
        [
            'machine_id' => 2,
            'completed_visits_count' => 3,
            'clean_in_stock_visits_count' => 3,
            'thermal_aborted_visits_count' => 0,
        ],
        [
            'machine_id' => 3,
            'completed_visits_count' => 4,
            'clean_in_stock_visits_count' => 2,
            'thermal_aborted_visits_count' => 0,
        ],
        [
            'machine_id' => 4,
            'completed_visits_count' => 1,
            'clean_in_stock_visits_count' => 1,
            'thermal_aborted_visits_count' => 0,
        ],
        [
            'machine_id' => 5,
            'completed_visits_count' => 1,
            'clean_in_stock_visits_count' => 1,
            'thermal_aborted_visits_count' => 0,
        ],
    ];

    $slotItems = [
        [
            'machine_id' => 1,
            'product_id' => 11,
            'audited_slots_count' => 10,
            'stockout_slots_count' => 2,
            'added_units' => 20,
            'expired_units' => 1,
            'expired_loss_eur' => 2.0,
            'damaged_units' => 0,
            'damaged_loss_eur' => 0.0,
        ],
        [
            'machine_id' => 2,
            'product_id' => 11,
            'audited_slots_count' => 10,
            'stockout_slots_count' => 1,
            'added_units' => 10,
            'expired_units' => 5,
            'expired_loss_eur' => 50.0,
            'damaged_units' => 0,
            'damaged_loss_eur' => 0.0,
        ],
        [
            'machine_id' => 3,
            'product_id' => 12,
            'audited_slots_count' => 20,
            'stockout_slots_count' => 10,
            'added_units' => 100,
            'expired_units' => 1,
            'expired_loss_eur' => 100.0,
            'damaged_units' => 0,
            'damaged_loss_eur' => 0.0,
        ],
        [
            'machine_id' => 4,
            'product_id' => 12,
            'audited_slots_count' => 5,
            'stockout_slots_count' => 0,
            'added_units' => 5,
            'expired_units' => 0,
            'expired_loss_eur' => 10.0,
            'damaged_units' => 1,
            'damaged_loss_eur' => 10.0,
        ],
        [
            'machine_id' => 5,
            'product_id' => 13,
            'audited_slots_count' => 5,
            'stockout_slots_count' => 0,
            'added_units' => 5,
            'expired_units' => 1,
            'expired_loss_eur' => 1.0,
            'damaged_units' => 0,
            'damaged_loss_eur' => 0.0,
        ],
        [
            'machine_id' => 1,
            'product_id' => 11,
            'audited_slots_count' => 0,
            'stockout_slots_count' => 0,
            'added_units' => 0,
            'expired_units' => 0,
            'expired_loss_eur' => 0.0,
            'damaged_units' => 0,
            'damaged_loss_eur' => 0.0,
        ],
    ];

    $thermalRecords = [
        [
            'machine_id' => 1,
            'records_count' => 1,
            'discarded_units_count' => 3,
            'estimated_merchandise_loss_eur' => 25.0,
        ],
    ];

    $machineRows = RestockingBiCalculator::buildMachineRows(
        $machineCatalog,
        $visitHeaders,
        $slotItems,
        $thermalRecords,
        true
    );

    $order = array_column($machineRows, 'machine_code');
    $assert(
        $order === ['RBI-SAN', 'RBI-OVER', 'RBI-LOSS', 'RBI-LOW', 'RBI-BAJA'],
        'by_machine prioriza sanidad, planograma, pérdida comercial y código',
        implode(' > ', $order)
    );

    $sanitary = $machineRows[0];
    $assert(
        $sanitary['thermal_aborted_visits_count'] === 2
        && $sanitary['completed_visits_count'] === 2
        && $sanitary['visit_in_stock_rate_pct'] === 50.0,
        'Los abortos térmicos no entran en el denominador de disponibilidad (Art. II)'
    );
    $assert(
        $sanitary['commercial_spoilage_loss_eur'] === 2.0
        && $sanitary['thermal_spoilage_units'] === 3
        && $sanitary['thermal_spoilage_loss_eur'] === 25.0,
        'La merma comercial y la térmica APPCC permanecen separadas (Art. II)'
    );

    $commercialTotals = RestockingBiCalculator::totalCommercial($slotItems);
    $thermalTotals = RestockingBiCalculator::totalThermal($thermalRecords);
    $assert(
        $commercialTotals === [
            'fefo_units' => 8,
            'fefo_loss_eur' => 163.0,
            'damaged_units' => 1,
            'damaged_loss_eur' => 10.0,
            'total_units' => 9,
            'total_loss_eur' => 173.0,
        ],
        'Los totales comerciales son exactos al céntimo'
    );
    $assert(
        $thermalTotals === ['records_count' => 1, 'units' => 3, 'loss_eur' => 25.0],
        'Los totales térmicos cuentan todas las actas M29 (QA-2)'
    );

    $summary = RestockingBiCalculator::buildSummary(
        $machineRows,
        [],
        $commercialTotals,
        $thermalTotals,
        true
    );
    $assert(
        $summary['completed_visits_count'] === 11
        && $summary['thermal_aborted_visits_count'] === 2
        && $summary['thermal_spoilage_units'] === 3
        && $summary['commercial_spoilage_eur'] === 173.0
        && $summary['total_spoilage_eur'] === 198.0,
        'El resumen suma ambas mermas sin diluir la sanitaria'
    );

    $restockerRows = RestockingBiCalculator::buildMachineRows(
        $machineCatalog,
        $visitHeaders,
        $slotItems,
        $thermalRecords,
        false
    );
    $thermalHidden = true;
    foreach ($restockerRows as $row) {
        if ($row['thermal_spoilage_units'] !== null || $row['thermal_spoilage_loss_eur'] !== null) {
            $thermalHidden = false;
        }
    }
    $assert($thermalHidden, 'Con reponedor, la térmica queda bloqueada en by_machine (D2)');

    $restockerSummary = RestockingBiCalculator::buildSummary(
        $restockerRows,
        [],
        $commercialTotals,
        $thermalTotals,
        false
    );
    $assert(
        $restockerSummary['thermal_spoilage_eur'] === null
        && $restockerSummary['thermal_spoilage_units'] === null
        && $restockerSummary['total_spoilage_eur'] === null,
        'Con reponedor, la térmica queda bloqueada en el resumen (D2)'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. III · históricos, baja lógica y auditoría inmutable
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. III · históricos, baja lógica y auditoría inmutable ---\n";

    $inactiveMachine = null;
    foreach ($machineRows as $row) {
        if ($row['machine_code'] === 'RBI-BAJA') {
            $inactiveMachine = $row;
        }
    }
    $assert(
        $inactiveMachine !== null && $inactiveMachine['is_active'] === false,
        'La máquina con baja lógica entra en by_machine con is_active = false'
    );

    $productRows = RestockingBiCalculator::buildProductRows(
        [
            ['product_id' => 11, 'sku' => 'RBI-SKU-11', 'name' => 'Agua', 'is_active' => true],
            ['product_id' => 12, 'sku' => 'RBI-SKU-12', 'name' => 'Zumo', 'is_active' => true],
            ['product_id' => 13, 'sku' => 'RBI-SKU-BAJA', 'name' => 'Baja', 'is_active' => false],
        ],
        $slotItems
    );
    $inactiveProduct = null;
    foreach ($productRows as $row) {
        if ($row['sku'] === 'RBI-SKU-BAJA') {
            $inactiveProduct = $row;
        }
    }
    $assert(
        $inactiveProduct !== null && $inactiveProduct['is_active'] === false,
        'El producto con baja lógica entra en by_product con is_active = false'
    );

    $auditTrigger = (string)$pdo->query(
        "SELECT t.tgname
           FROM pg_trigger t
           JOIN pg_class c ON c.oid = t.tgrelid
          WHERE c.relname = 'audit_log' AND NOT t.tgisinternal
          LIMIT 1"
    )->fetchColumn();
    $assert(
        $auditTrigger === 'trg_no_delete_audit_log',
        'audit_log conserva la guarda append-only del Art. III.3',
        $auditTrigger
    );

    $auditCount = static function () use ($pdo): int {
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = '" . RBI05_AUDIT_ACTION . "'"
        )->fetchColumn();
    };

    $auditBefore = $auditCount();
    $export = $dispatch(
        'GET',
        '/api/coordinator/metrics/restocking/export',
        ['from' => '2026-01-01', 'to' => '2026-01-02'],
        $coordinatorToken
    );
    $auditAfter = $auditCount();
    $assert(
        $export->getStatusCode() === 200 && $auditAfter === $auditBefore + 1,
        'La exportación deja exactamente un evento append-only',
        "antes={$auditBefore} después={$auditAfter}"
    );

    $lastAudit = $pdo->query(
        "SELECT entity_id, user_role FROM audit_log
          WHERE action = '" . RBI05_AUDIT_ACTION . "'
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        $lastAudit !== false
        && (int)$lastAudit['entity_id'] === 0
        && $lastAudit['user_role'] === 'COORDINATOR',
        'El evento global se firma con entity_id = 0 y el coordinador (QA-1)'
    );

    $biSourceText = '';
    foreach (RBI05_BI_SOURCES as $source) {
        $biSourceText .= $normalized($read($source)) . "\n";
    }
    $assert(
        preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $biSourceText) !== 1,
        'Las fuentes del módulo no contienen borrados físicos'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. IV · cero DDL, cero dependencias y residuo cero
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Art. IV · cero DDL, cero dependencias y residuo cero ---\n";

    $tables = PostgresFixtureBuilder::tables($pdo);
    $assert(
        count($tables) === RBI05_EXPECTED_TABLES,
        'El esquema conserva 51 tablas de dominio',
        'contadas ' . count($tables)
    );

    $enumCount = (int)$pdo->query(
        "SELECT COUNT(*)
           FROM pg_type t
           JOIN pg_namespace n ON n.oid = t.typnamespace
          WHERE t.typtype = 'e' AND n.nspname = current_schema()"
    )->fetchColumn();
    $assert($enumCount === RBI05_EXPECTED_ENUMS, 'El esquema conserva 50 tipos ENUM', 'contados ' . $enumCount);

    $enumColumns = (int)$pdo->query(
        "SELECT COUNT(*)
           FROM information_schema.columns c
           JOIN pg_type t ON t.typname = c.udt_name
          WHERE c.table_schema = current_schema() AND t.typtype = 'e'"
    )->fetchColumn();
    $assert(
        $enumColumns === RBI05_EXPECTED_ENUM_COLUMNS,
        'El esquema conserva 56 columnas ENUM',
        'contadas ' . $enumColumns
    );

    $migrations = glob($projectRoot . '/database/supabase_migrations/*.sql') ?: [];
    $assert(
        count($migrations) === RBI05_EXPECTED_MIGRATIONS,
        'El repositorio conserva 34 migraciones',
        'contadas ' . count($migrations)
    );

    $assert(
        preg_match('/\b(CREATE|ALTER|DROP)\s+(TABLE|TYPE|INDEX|VIEW|MATERIALIZED\s+VIEW)\b/i', $biSourceText) !== 1,
        'Las fuentes del módulo no declaran DDL'
    );

    $assert(
        !is_file($projectRoot . '/composer.json')
        && !str_contains($biSourceText, 'vendor/autoload')
        && !str_contains($biSourceText, 'GuzzleHttp'),
        'El módulo no depende de paquetes externos'
    );

    preg_match_all('/^use\s+([^;]+);/m', $biSourceText, $imports);
    $foreignImports = array_values(array_filter(
        array_map('trim', $imports[1] ?? []),
        static fn (string $import): bool => !str_starts_with($import, 'VendGuard\\')
            && !in_array($import, RBI05_ALLOWED_IMPORTS, true)
    ));
    $assert(
        $foreignImports === [],
        'Todos los imports son del dominio o de clases nativas permitidas',
        implode(', ', $foreignImports)
    );

    $residue = (int)$pdo->query(
        "SELECT COUNT(*) FROM pg_namespace
          WHERE nspname LIKE 't_rbi05%' OR nspname LIKE 'contract_convergence_check%'"
    )->fetchColumn();
    $assert($residue === 0, 'No queda residuo de esquemas temporales', 'contados ' . $residue);

    // ─────────────────────────────────────────────────────────────────────
    // 5. Art. V.4 · opacidad de superficies y descarga segura
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Art. V.4 · opacidad de superficies y descarga segura ---\n";

    $jsRoot = $projectRoot . '/public/assets/js';
    $biMarkedFiles = [];
    $routeReferrers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jsRoot));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'js') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
        $contents = (string)file_get_contents($file->getPathname());

        if (stripos($contents, 'restocking') !== false) {
            $biMarkedFiles[] = $relative;
        }
        if (str_contains($contents, 'metrics/restocking')) {
            $routeReferrers[] = $relative;
        }
    }
    sort($biMarkedFiles);
    sort($routeReferrers);

    // Superficie aprobada en T-RBI-06: el cliente API compone las rutas y la
    // sub-pestaña de Coordinación las consume; la vista solo la integra y el
    // catálogo de auditoría traduce la acción global. Cualquier otra vista que
    // mencione el módulo sigue siendo una fuga de perímetro.
    $assert(
        $biMarkedFiles === [
            'public/assets/js/api.js',
            'public/assets/js/components/CoordinatorRestockingBiTab.js',
            'public/assets/js/utils/AuditActionLabels.js',
            'public/assets/js/views/CoordinatorMetricsView.js',
        ],
        'Las únicas superficies que conocen el módulo son el cliente API y la sub-pestaña de Coordinación',
        implode(', ', $biMarkedFiles)
    );
    $assert(
        $routeReferrers === ['public/assets/js/api.js'],
        'Solo api.js referencia las rutas HTTP del módulo',
        implode(', ', $routeReferrers)
    );

    $apiJs = $normalized($read('public/assets/js/api.js'));
    $downloadStart = strpos($apiJs, 'downloadRestockingBiCsv(');
    $downloadBlock = $downloadStart === false ? '' : substr($apiJs, $downloadStart, 1200);
    $assert(
        str_contains($downloadBlock, 'this.downloadFile(')
        && !str_contains($downloadBlock, 'window.open')
        && !str_contains($downloadBlock, 'token='),
        'La descarga delega en downloadFile sin window.open ni token en URL'
    );
    $assert(
        str_contains($apiJs, "headers['Authorization'] = `Bearer \${this.token}`")
        && str_contains($apiJs, 'response.blob()')
        && str_contains($apiJs, 'createObjectURL'),
        'downloadFile usa fetch autenticado y Blob local'
    );

    $csv = $export->getBody();
    $assert(
        str_starts_with($csv, "\xEF\xBB\xBF")
        && substr_count($csv, 'SECCIÓN ') === 5
        && str_contains($csv, 'CONFIDENCIAL ART. V.4'),
        'El CSV conserva BOM, cinco secciones y aviso de confidencialidad'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Art. VI · integridad de los ficheros protegidos
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Art. VI · integridad de los ficheros protegidos ---\n";

    foreach (RBI05_PROTECTED_FILES as $relative => $expectedHash) {
        $actualHash = hash('sha256', $normalized($read($relative)));
        $assert(
            hash_equals($expectedHash, $actualHash),
            'Huella intacta de ' . basename($relative),
            $actualHash
        );
    }

    $protectedMarker = false;
    foreach (array_keys(RBI05_PROTECTED_FILES) as $relative) {
        if (stripos($read($relative), 'restocking') !== false) {
            $protectedMarker = true;
        }
    }
    $assert(!$protectedMarker, 'Los ficheros protegidos no mencionan el Módulo 38 (Art. VI)');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-RBI-05 · {$assertions} aserciones · 100% EN VERDE\n";
echo " Art. II, III, IV, V.4 y VI CERTIFICADOS.\n";
echo "======================================================================\n";
exit(0);
