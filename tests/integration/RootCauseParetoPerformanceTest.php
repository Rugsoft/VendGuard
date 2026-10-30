<?php

declare(strict_types=1);

/**
 * RootCauseParetoPerformanceTest · T-TAXONOMY-19 (RNF-03)
 *
 * Certifica el presupuesto de rendimiento del informe Pareto 80/20 sobre un
 * volumen realista de producción:
 *
 *   1. **Volumen:** 10.000 averías clasificadas con causa y solución vivas, con
 *      dos repuestos declarados por expediente (20.000 filas de trazabilidad),
 *      50 máquinas de tres fabricantes y ocho causas con soluciones.
 *   2. **Latencia:** tras dos pasadas de calentamiento, tres muestras del
 *      informe completo y mediana inferior a 500 ms (RNF-03).
 *   3. **Coste de consulta:** cada informe ejecuta como máximo cuatro sentencias
 *      SQL —la analítica nominal y las tres del selector de filtros—, medidas con
 *      un PDO contador que envuelve la conexión real.
 *
 * El escenario vive en un esquema aislado y se borra con `CASCADE` en el
 * `finally`. El índice `idx_incidents_pareto_resolved` se verifica en
 * `PostgresRootCauseTaxonomySchemaTest`; aquí se mide el efecto agregado, no el
 * plan de ejecución.
 *
 * CATEGORÍA: `new_engine`
 *   Exige PostgreSQL a través de `PG_TEST_DSN`, sin valor por defecto. Sin
 *   motor, la suite se declara no ejecutable con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls
 *   php tests/integration/RootCauseParetoPerformanceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

use VendGuard\Application\DTO\ParetoFilterDto;
use VendGuard\Application\Service\RootCauseParetoService;
use VendGuard\Infrastructure\Repository\PdoRootCauseParetoAnalyticsRepository;

/** Marca de categoría: `run_all.php` la deriva al canal del motor nuevo. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const PARETO_PERF_SCHEMA = 't_taxonomy25_perf';

/** Umbral contractual de mediana (RNF-03). */
const PARETO_MEDIAN_BUDGET_MS = 500.0;

/** Techo de sentencias SQL por informe. */
const PARETO_SQL_BUDGET = 4;

/**
 * PDO contador: cuenta las sentencias que la aplicación envía al motor.
 *
 * Envuelve una conexión real (no simula el motor) y sólo incrementa en
 * `prepare()`, `query()` y `exec()`. No es código de producción: vive en el
 * arnés y ningún camino de la aplicación lo carga.
 */
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

    public function exec(string $statement): int|false
    {
        $this->statements++;

        return parent::exec($statement);
    }
}

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-TAXONOMY-19 · Rendimiento del Pareto (RNF-03)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

// La puerta valida el contrato de entorno (exit 2 sin motor) antes de abrir la
// conexión contadora, para que el canal distinga «no ejecutable» de «en rojo».
$gatePdo = PostgresFixtureBuilder::gateOrExit();
$dsn = (string)getenv('PG_TEST_DSN');

$crash = null;
$residueCheck = null;

/** @var list<float> $samples */
$samples = [];
/** @var list<int> $statementCounts */
$statementCounts = [];

try {
    $perfPdo = new CountingPdo($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    PostgresFixtureBuilder::createIsolationSchema($perfPdo, PARETO_PERF_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($perfPdo, dirname(__DIR__, 2));
    $assert(
        'El esquema aislado se aplica entero, incluida la migración 021',
        $schemaReport['statements'] > 100 && in_array('021_root_cause_taxonomy.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias', $schemaReport['statements'])
    );

    echo "\n--- Escenario: 10.000 averías clasificadas con repuestos ---\n";

    $perfPdo->exec(
        "INSERT INTO locations (site_code, site_code_key, name, address)
         VALUES ('PERF-01', 'perf-01', 'Sede de certificación de rendimiento', 'Calle de la Prueba 1')"
    );
    $locationId = (int)$perfPdo->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();

    $perfPdo->exec(
        "INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES ('Técnico Rendimiento', 'perf.tech@vendguard.internal', 'perf.tech@vendguard.internal', 'x', 'TECHNICIAN')"
    );
    $technicianId = (int)$perfPdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

    $perfPdo->exec(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         SELECT {$locationId},
                'PERF-M-' || lpad(n::text, 3, '0'),
                CASE n % 3 WHEN 0 THEN 'Necta Kikko Max' WHEN 1 THEN 'Fas Perla' ELSE 'Jofemar Vision' END,
                (CASE WHEN n % 2 = 0 THEN 'SNACKS' ELSE 'COLD_DRINKS' END)::machine_type,
                'Planta ' || ((n % 5) + 1)
           FROM generate_series(1, 50) AS n"
    );
    $machineCount = (int)$perfPdo->query('SELECT COUNT(*) FROM machines')->fetchColumn();
    $assert('El escenario declara 50 máquinas de tres fabricantes', $machineCount === 50, "máquinas: {$machineCount}");

    $perfPdo->exec(
        "INSERT INTO root_causes (code, category, name)
         SELECT 'RC-PERF-' || lpad(n::text, 2, '0'),
                (CASE WHEN n % 2 = 0 THEN 'PAYMENT_SYSTEM' ELSE 'PRODUCT_JAM' END)::incident_category,
                'Causa sintética de rendimiento ' || n
           FROM generate_series(1, 8) AS n"
    );
    $perfPdo->exec(
        "INSERT INTO standard_solutions (root_cause_id, code, name, is_default)
         SELECT rc.id,
                'SOL-PERF-' || lpad((ROW_NUMBER() OVER (ORDER BY rc.id))::text, 2, '0') || 'A',
                'Solución sintética de rendimiento ' || rc.code,
                TRUE
           FROM root_causes rc
          ORDER BY rc.id"
    );
    $taxonomyPairs = (int)$perfPdo->query(
        'SELECT COUNT(*) FROM standard_solutions ss JOIN root_causes rc ON rc.id = ss.root_cause_id'
    )->fetchColumn();
    $assert('El escenario declara ocho causas con su solución predeterminada', $taxonomyPairs === 8, "parejas: {$taxonomyPairs}");

    $perfPdo->exec(
        "INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost)
         VALUES ('SP-PERF-01', 'Monedero de rendimiento', 'PAYMENT_SYSTEM', 'Necta', 10.50),
                ('SP-PERF-02', 'Sonda de rendimiento', 'THERMAL', 'Fas', 12.75),
                ('SP-PERF-03', 'Espiral de rendimiento', 'MECHANICAL', 'Jofemar', 8.25),
                ('SP-PERF-04', 'Placa de rendimiento', 'ELECTRONIC', 'Necta', 21.00)"
    );

    $insertStartedAt = hrtime(true);
    $perfPdo->exec(
        "INSERT INTO incidents (
            ticket_code, machine_id, machine_type_snapshot, location_id, assigned_technician_id,
            category, description, urgency, status, cycle_started_at,
            resolution_diagnosis, resolution_action, resolved_at,
            root_cause_id, standard_solution_id,
            root_cause_code_snapshot, root_cause_category_snapshot, root_cause_name_snapshot,
            standard_solution_code_snapshot, standard_solution_name_snapshot
         )
         SELECT
            'PERF-' || lpad(n::text, 5, '0'),
            m.id,
            m.machine_type,
            m.location_id,
            {$technicianId},
            'PAYMENT_SYSTEM',
            'Avería sintética de certificación de rendimiento del informe Pareto.',
            'HIGH',
            'CLOSED'::incident_status,
            TIMESTAMPTZ '2026-10-15 08:00:00+00',
            'Diagnóstico sintético de rendimiento con longitud suficiente.',
            'Acción correctiva sintética de rendimiento con longitud suficiente.',
            TIMESTAMPTZ '2026-10-15 10:00:00+00',
            rc.id,
            ss.id,
            rc.code,
            rc.category,
            rc.name,
            ss.code,
            ss.name
           FROM generate_series(1, 10000) AS n
           JOIN machines m
             ON m.code = 'PERF-M-' || lpad((((n - 1) % 50) + 1)::text, 3, '0')
           JOIN root_causes rc
             ON rc.code = 'RC-PERF-' || lpad((((n - 1) % 8) + 1)::text, 2, '0')
           JOIN standard_solutions ss
             ON ss.root_cause_id = rc.id"
    );
    $incidentCount = (int)$perfPdo->query(
        "SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'PERF-%'"
    )->fetchColumn();
    $assert('El escenario inserta 10.000 averías clasificadas', $incidentCount === 10000, "averías: {$incidentCount}");

    // Dos repuestos por expediente: 20.000 filas de trazabilidad (RNF-03).
    for ($partRound = 0; $partRound < 2; $partRound++) {
        $perfPdo->exec(
            "INSERT INTO incident_replaced_parts (
                intervention_type, incident_id, machine_id, location_id, technician_id,
                spare_part_id, is_out_of_catalog, quantity, unit_cost_snapshot, old_part_destination
             )
             SELECT 'INCIDENT',
                    i.id,
                    i.machine_id,
                    i.location_id,
                    i.assigned_technician_id,
                    sp.id,
                    FALSE,
                    1 + ((i.id % 2)::int),
                    10.50,
                    'TALLER'
               FROM incidents i
               JOIN LATERAL (
                    SELECT id
                      FROM spare_parts
                     ORDER BY id
                     LIMIT 1 OFFSET (((i.id + {$partRound}) % 4)::int)
               ) sp ON TRUE
              WHERE i.ticket_code LIKE 'PERF-%'"
        );
    }
    $partsCount = (int)$perfPdo->query(
        "SELECT COUNT(*) FROM incident_replaced_parts p JOIN incidents i ON i.id = p.incident_id
          WHERE i.ticket_code LIKE 'PERF-%'"
    )->fetchColumn();
    $assert('El escenario inserta 20.000 repuestos declarados', $partsCount === 20000, "repuestos: {$partsCount}");

    $insertMs = (hrtime(true) - $insertStartedAt) / 1_000_000;
    printf("  [INFO] Volumen preparado en %.0f ms\n", $insertMs);

    // ─────────────────────────────────────────────────────────────────────
    // Medición: calentamiento + tres muestras
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- Muestras del informe completo (RNF-03) ---\n";

    $service = new RootCauseParetoService(new PdoRootCauseParetoAnalyticsRepository($perfPdo));
    $filter = new ParetoFilterDto('2026-10-01', '2026-10-31');

    for ($warmup = 1; $warmup <= 2; $warmup++) {
        $service->buildParetoReport($filter);
    }

    for ($sample = 1; $sample <= 3; $sample++) {
        $perfPdo->statements = 0;
        $startedAt = hrtime(true);
        $report = $service->buildParetoReport($filter);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $samples[] = $elapsedMs;
        $statementCounts[] = $perfPdo->statements;

        printf(
            "  [INFO] Muestra %d: %.1f ms · %d sentencias SQL · %d causas · %d averías clasificadas\n",
            $sample,
            $elapsedMs,
            $perfPdo->statements,
            count($report->getItems()),
            (int)$report->getSummary()['classified_incidents_count']
        );
    }

    sort($samples);
    $medianMs = $samples[1];

    $assert(
        'La mediana del informe Pareto está por debajo de 500 ms',
        $medianMs < PARETO_MEDIAN_BUDGET_MS,
        sprintf('mediana: %.1f ms · muestras: %s', $medianMs, implode(' / ', array_map(
            static fn (float $value): string => sprintf('%.1f ms', $value),
            $samples
        )))
    );

    foreach ($statementCounts as $index => $count) {
        $assert(
            sprintf('La muestra %d ejecuta como máximo %d sentencias SQL', $index + 1, PARETO_SQL_BUDGET),
            $count <= PARETO_SQL_BUDGET,
            "sentencias: {$count}"
        );
    }

    $summary = $report->getSummary();
    $items = $report->getItems();
    $lastItem = $items[count($items) - 1];
    $assert(
        'El informe calcula el 100 % sobre las 10.000 averías clasificadas',
        (int)$summary['classified_incidents_count'] === 10000
            && count($items) === 8
            && (float)$lastItem['cumulative_percentage'] === 100.0,
        sprintf(
            'clasificadas: %d · causas: %d · acumulado final: %.2f',
            (int)$summary['classified_incidents_count'],
            count($items),
            (float)$lastItem['cumulative_percentage']
        )
    );

    $gatePdo->query('SELECT 1');
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        if (isset($perfPdo)) {
            PostgresFixtureBuilder::dropIsolationSchema($perfPdo, PARETO_PERF_SCHEMA);
        }
        $residueCheck = $gatePdo->query(
            "SELECT to_regclass('" . PARETO_PERF_SCHEMA . ".incidents')"
        )->fetchColumn();
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
        $residueCheck = 'error de limpieza';
    }
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado se retira entero y no deja tablas en el motor',
    $residueCheck === null,
    'residuo: ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-TAXONOMY-19 (RNF-03) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LA CERTIFICACIÓN DE RENDIMIENTO.\n";
echo "======================================================================\n";
exit(1);
