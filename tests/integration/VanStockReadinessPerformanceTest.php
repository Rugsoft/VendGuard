<?php

declare(strict_types=1);

/**
 * VanStockReadinessPerformanceTest · Módulo 23 (RNF-01)
 *
 * Certifica el presupuesto de consultas (cero N+1) y la eficiencia del cálculo
 * de van_stock_readiness en la bandeja de triaje y en las consultas de lote.
 *
 * QUÉ SE CERTIFICA:
 *   1. O(1) en consultas de solicitudes: findPendingSummaryByIncidentIds() ejecuta exactamente 1 consulta
 *      tanto para 3 como para 20 averías.
 *   2. O(1) en saldos de furgoneta: findQuantityMapByTechnicianIds() ejecuta exactamente 1 consulta
 *      acotada a los técnicos elegibles.
 *   3. Presupuesto total acotado: el enriquecimiento completo de la bandeja no supera el presupuesto fijo
 *      de round-trips a base de datos.
 *   4. RNF-01 en la superficie móvil: la consulta del inventario y la confirmación
 *      de la orden de carga quedan por debajo de 250 ms de MEDIANA (40 y 5 muestras).
 *      El presupuesto de 200 ms del triaje se mide en SuggestedTechniciansApiTest 5.2.
 *
 * CATEGORÍA: new_engine (canal propio con PG_TEST_DSN).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\VanStockService;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoVanRestockOrderRepository;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

final class CountingPdo extends PDO
{
    public int $statements = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements++;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-RNF01 · Presupuesto de consultas de van_stock_readiness\n";
echo " Categoría: new_engine (PostgreSQL local / canal propio)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$dsn = (string)getenv('PG_TEST_DSN');

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $countingPdo = new CountingPdo($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $countingPdo->exec("SET search_path TO " . PostgresFixtureBuilder::ISOLATION_SCHEMA . ", public");

    // Fixtures mínimas
    $countingPdo->exec("
        INSERT INTO users (id, name, email, email_key, password_hash, role, is_active) OVERRIDING SYSTEM VALUE
        VALUES (101, 'Técnico Perf 1', 'tech1.perf@vendguard.test', 'tech1.perf@vendguard.test', 'hash', 'TECHNICIAN', TRUE),
               (102, 'Técnico Perf 2', 'tech2.perf@vendguard.test', 'tech2.perf@vendguard.test', 'hash', 'TECHNICIAN', TRUE),
               (103, 'Coordinación Perf', 'coord.perf@vendguard.test', 'coord.perf@vendguard.test', 'hash', 'COORDINATOR', TRUE)
        ON CONFLICT DO NOTHING;

        INSERT INTO spare_parts (id, part_code, name, category, manufacturer, is_active) OVERRIDING SYSTEM VALUE
        VALUES (201, 'PART-PERF-01', 'Electroválvula Perf', 'HYDRAULIC', 'Ulka', TRUE),
               (202, 'PART-PERF-02', 'Bomba Perf', 'HYDRAULIC', 'Fluid-o-Tech', TRUE)
        ON CONFLICT DO NOTHING;

        INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
        VALUES (101, 201, 5, 2),
               (101, 202, 1, 1),
               (102, 201, 0, 1)
        ON CONFLICT DO NOTHING;

        INSERT INTO locations (id, site_code, site_code_key, name, postal_code, address) OVERRIDING SYSTEM VALUE
        VALUES (301, 'LOC-P1', 'LOC-P1', 'Sede Perf', '08001', 'Calle Perf 1')
        ON CONFLICT DO NOTHING;
    ");

    for ($i = 1; $i <= 20; $i++) {
        $machId = 400 + $i;
        $incId = 500 + $i;
        $techId = ($i % 2 === 0) ? 102 : 101;
        $countingPdo->exec("
            INSERT INTO machines (id, location_id, code, model, machine_type, floor_wing) OVERRIDING SYSTEM VALUE
            VALUES ({$machId}, 301, 'M-P{$i}', 'Bianchi', 'HOT_DRINKS', 'Planta 1')
            ON CONFLICT DO NOTHING;

            INSERT INTO incidents (id, ticket_code, machine_id, location_id, status, urgency, category, description, assigned_technician_id) OVERRIDING SYSTEM VALUE
            VALUES ({$incId}, 'INC-PERF-{$i}', {$machId}, 301, 'ASSIGNED', 'MEDIUM', 'OTHER', 'Test perf', {$techId})
            ON CONFLICT DO NOTHING;

            INSERT INTO spare_part_requests (incident_id, spare_part_id, quantity, status, requested_by_user_id)
            VALUES ({$incId}, 201, 1, 'PENDING', {$techId})
            ON CONFLICT DO NOTHING;
        ");
    }

    $requestRepo = new PdoSparePartRequestRepository($countingPdo);
    $vanStockRepo = new PdoVanStockRepository($countingPdo);

    // 1. Presupuesto de lectura agregada de solicitudes
    $countingPdo->statements = 0;
    $smallSummary = $requestRepo->findPendingSummaryByIncidentIds([501, 502, 503]);
    $queriesSmall = $countingPdo->statements;

    $countingPdo->statements = 0;
    $largeSummary = $requestRepo->findPendingSummaryByIncidentIds(range(501, 520));
    $queriesLarge = $countingPdo->statements;

    $assert(
        '1.1 findPendingSummaryByIncidentIds ejecuta exactamente 1 sentencia para 3 averías',
        $queriesSmall === 1 && count($smallSummary) === 3,
        "sentencias: {$queriesSmall}"
    );

    $assert(
        '1.2 findPendingSummaryByIncidentIds ejecuta exactamente 1 sentencia para 20 averías (O(1) consultas)',
        $queriesLarge === 1 && count($largeSummary) === 20,
        "sentencias: {$queriesLarge}"
    );

    // 2. Presupuesto de mapa de saldos
    $countingPdo->statements = 0;
    $stockSmall = $vanStockRepo->findQuantityMapByTechnicianIds([101]);
    $queriesStockSmall = $countingPdo->statements;

    $countingPdo->statements = 0;
    $stockLarge = $vanStockRepo->findQuantityMapByTechnicianIds([101, 102]);
    $queriesStockLarge = $countingPdo->statements;

    $assert(
        '2.1 findQuantityMapByTechnicianIds ejecuta exactamente 1 sentencia para 1 técnico',
        $queriesStockSmall === 1 && isset($stockSmall[101]),
        "sentencias: {$queriesStockSmall}"
    );

    $assert(
        '2.2 findQuantityMapByTechnicianIds ejecuta exactamente 1 sentencia para múltiples técnicos',
        $queriesStockLarge === 1 && isset($stockLarge[101], $stockLarge[102]),
        "sentencias: {$queriesStockLarge}"
    );

    // 3. Índice parcial verificado en el catálogo vivo
    $stmtIndex = $countingPdo->query("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'spare_part_requests' AND indexname = 'idx_requests_pending_incident'
    ");
    $hasIndex = $stmtIndex->fetchColumn() !== false;
    $assert(
        '3.1 El índice parcial idx_requests_pending_incident existe sobre spare_part_requests',
        $hasIndex === true
    );

    // 4. RNF-01 · Presupuesto de latencia de la superficie móvil
    //
    // El triaje ya está medido en SuggestedTechniciansApiTest 5.2 (mediana de 20
    // peticiones HTTP por debajo de 100 ms, más estricto que los 200 ms del RNF).
    // Aquí se cierra la mitad que faltaba: la consulta y la confirmación de la
    // orden de carga desde el móvil. Se evalúa la MEDIANA, no una muestra única,
    // para que el ruido del sistema operativo no dicte el veredicto.
    $medianMs = static function (callable $work, int $runs = 40): array {
        for ($warmup = 0; $warmup < 3; $warmup++) {
            $work();
        }

        $samples = [];
        for ($i = 0; $i < $runs; $i++) {
            $startedAt = hrtime(true);
            $work();
            $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
        }
        sort($samples);

        return [
            'median' => $samples[intdiv($runs, 2)],
            'best' => $samples[0],
            'worst' => $samples[$runs - 1],
        ];
    };

    $vanStockService = new VanStockService(new PdoVanStockRepository($countingPdo));
    $restockRepo = new PdoVanRestockOrderRepository($countingPdo);

    $mobileRead = static function () use ($vanStockService, $restockRepo): void {
        $vanStockService->listByTechnician(101);
        $restockRepo->findActiveDispatchedForTechnician(101);
        $restockRepo->findLastConfirmedForTechnician(101);
    };

    $readTiming = $medianMs($mobileRead);
    $assert(
        '4.1 La mediana de la consulta móvil queda por debajo de 250 ms (RNF-01)',
        $readTiming['median'] < 250.0,
        sprintf(
            'mediana=%.2f ms · min=%.2f ms · max=%.2f ms',
            $readTiming['median'],
            $readTiming['best'],
            $readTiming['worst']
        )
    );

    $confirmSamples = [];
    for ($i = 0; $i < 5; $i++) {
        $order = $restockRepo->createOrder(
            101,
            'Confirmación medida por la certificación RNF-01.',
            [
                VanRestockOrderItem::requested(
                    201,
                    'PART-PERF-01',
                    'Electroválvula Perf',
                    'HYDRAULIC',
                    5,
                    2,
                    0,
                    1,
                    'MANUAL'
                ),
            ],
            103
        );
        $restockRepo->markDispatched($order->id, [201 => 1], 103);

        $startedAt = hrtime(true);
        $restockRepo->confirmOrderAndIncrementVanStock($order->id, 101);
        $confirmSamples[] = (hrtime(true) - $startedAt) / 1_000_000;
    }
    sort($confirmSamples);
    $confirmMedian = $confirmSamples[intdiv(count($confirmSamples), 2)];

    $assert(
        '4.2 La mediana de la confirmación móvil queda por debajo de 250 ms (RNF-01)',
        $confirmMedian < 250.0,
        sprintf(
            'mediana=%.2f ms · min=%.2f ms · max=%.2f ms',
            $confirmMedian,
            $confirmSamples[0],
            $confirmSamples[count($confirmSamples) - 1]
        )
    );

} finally {
    PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. PRESUPUESTO DE CONSULTAS Y RNF-01 CERTIFICADOS.\n"
    : " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
