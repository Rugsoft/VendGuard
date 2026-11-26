<?php

declare(strict_types=1);

/**
 * DexAuditPerformanceTest · T-DEX-19 · AUD-39-04
 *
 * Certifica el presupuesto de latencia de `RNF-01` sobre PostgreSQL local:
 * la importación de un `.dex` representativo de alta carga —próximo al tope de
 * 1 MB y con 500 selecciones— debe quedar por debajo de 250 ms en el
 * percentil 95, incluyendo parseo, transacción y relectura del detalle.
 *
 *   1. El fixture mide un fichero representativo: 1 MB como máximo y 500
 *      selecciones `PA1`/`PA2`.
 *   2. Se descarta una importación de calentamiento y se miden 20 muestras
 *      consecutivas sobre la misma máquina.
 *   3. El percentil 95 por rango más cercano debe ser inferior a 250 ms.
 *
 * El motor remoto/Supavisor no se sustituye por el local: su medición pertenece
 * al canal de rendimiento remoto y no se declara cubierta aquí. Esta suite mide
 * el contrato local que exige `RNF-01` para el banco de certificación.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL real y trabaja en un esquema aislado;
 * sin motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/DexAuditPerformanceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\MachineDexAuditService;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditGateway;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const DEX11_PERFORMANCE_SCHEMA = 't_dex11_performance';
const DEX11_ITERATIONS = 20;
const DEX11_SELECTIONS = 500;
const DEX11_MAX_BYTES = 1048576;
const DEX11_P95_BUDGET_MS = 250.0;

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

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-19 · Latencia de importación EVA-DTS (.dex)\n";
echo " Categoría: new_engine · RNF-01 · AUD-39-04\n";
echo "======================================================================\n";

$pdo = PostgresFixtureBuilder::gateOrExit();
$schemaReady = false;
$exitCode = 1;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, DEX11_PERFORMANCE_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $coordinatorStmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
         RETURNING id'
    );
    $coordinatorStmt->execute([
        ':name' => 'Coordinación Rendimiento DEX',
        ':email' => 'dex11.performance.coord@vendguard.internal',
        ':email_key' => 'dex11.performance.coord@vendguard.internal',
        ':hash' => password_hash('dex11-performance', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => 'COORDINATOR',
        ':operator' => 'DEX11-PERF',
    ]);
    $coordinatorId = (int)$coordinatorStmt->fetchColumn();

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX11', 'Zona rendimiento DEX', '#2560ff') RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX11',
        ':site_code_key' => 'sede-dex11',
        ':name' => 'Sede rendimiento DEX',
        ':address' => 'Carrer del Rendiment 11, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, 'DEX11-PERF-01', 'Modelo rendimiento DEX', 'PERISHABLE_FOOD', 'Planta baja')
         RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId]);
    $machineId = (int)$machineStmt->fetchColumn();

    $service = new MachineDexAuditService(
        new PdoMachineDexAuditRepository($pdo),
        new PdoMachineDexAuditGateway($pdo),
        new EvaDtsParser(),
        new DexSelectionMatcher(),
        new DexAuditValidator(),
        new PdoNotificationOutboxRepository($pdo)
    );

    $makePayload = static function (int $iteration): string {
        $lines = [
            'DXS*COMM-PERF-' . $iteration . '*VA*DEX 6.0',
            'ID1*DEX11-PERF-01',
            'ID4*2',
            'VA1*100000*1000',
            'CA3*500*0*0',
        ];

        for ($index = 1; $index <= DEX11_SELECTIONS; $index++) {
            $lines[] = 'PA1*S' . $index . '*100';
            $lines[] = 'PA2*1*100';
        }

        while (strlen(implode("\n", $lines)) < 1_000_000) {
            $lines[] = 'ZZ*RELLENO-DE-CARGA-PERF';
        }

        $lines[] = 'DXE*FIN';

        return implode("\n", $lines);
    };

    $import = static function (string $payload, int $iteration) use ($service, $machineId, $coordinatorId): array {
        $started = hrtime(true);
        $result = $service->importDex([
            'machine_id' => $machineId,
            'actor_id' => $coordinatorId,
            'import_source' => 'COORDINATOR_CONSOLE',
            'raw_payload' => $payload,
            'original_filename' => sprintf('dex11-performance-%02d.dex', $iteration),
            'captured_at' => sprintf('2026-11-24 08:%02d:00+00', $iteration),
            'client_mutation_id' => sprintf('dex11-perf-%04d', $iteration),
        ]);

        return [$result, (hrtime(true) - $started) / 1_000_000];
    };

    $warmupPayload = $makePayload(0);
    [$warmup] = $import($warmupPayload, 0);

    $samples = [];
    $auditIds = [];
    for ($iteration = 1; $iteration <= DEX11_ITERATIONS; $iteration++) {
        $payload = $makePayload($iteration);
        [$result, $milliseconds] = $import($payload, $iteration);
        $samples[] = $milliseconds;
        $auditIds[] = (int)($result['audit']['id'] ?? 0);
    }

    $representativeBytes = strlen($makePayload(1));
    $representativeSelections = substr_count($makePayload(1), "\nPA1*");
    $assert(
        'El fixture es representativo: hasta 1 MB y 500 selecciones PA1/PA2',
        $representativeBytes <= DEX11_MAX_BYTES
            && $representativeBytes >= 900_000
            && $representativeSelections === DEX11_SELECTIONS,
        json_encode([
            'bytes' => $representativeBytes,
            'max_bytes' => DEX11_MAX_BYTES,
            'selections' => $representativeSelections,
        ])
    );

    $assert(
        'Las 20 importaciones medidas se persisten y devuelven un volcado distinto',
        count(array_filter($auditIds, static fn (int $id): bool => $id > 0)) === DEX11_ITERATIONS
            && count(array_unique($auditIds)) === DEX11_ITERATIONS
            && (int)($warmup['audit']['id'] ?? 0) > 0,
        json_encode(['warmup' => (int)($warmup['audit']['id'] ?? 0), 'measured' => count($auditIds)])
    );

    sort($samples, SORT_NUMERIC);
    $p95Index = max(0, (int)ceil(0.95 * count($samples)) - 1);
    $p95 = $samples[$p95Index];
    $max = $samples[count($samples) - 1];
    $assert(
        'La importación completa mantiene p95 < 250 ms sobre PostgreSQL local',
        $p95 < DEX11_P95_BUDGET_MS,
        sprintf(
            'muestras=%d · p95=%.1f ms · max=%.1f ms · presupuesto=%.0f ms',
            count($samples),
            $p95,
            $max,
            DEX11_P95_BUDGET_MS
        )
    );

    echo "\n RESULTADO: " . ($failures === 0 ? 'VERDE' : 'ROJO') . " · {$assertions} aserciones · {$failures} fallos\n";
    echo " Presupuesto RNF-01 local: p95 < 250 ms · el motor remoto/Supavisor se mide en su canal propio\n";
    echo " Total Aserciones: {$assertions}\n";

    $exitCode = $failures === 0 ? 0 : 1;
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($schemaReady) {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, DEX11_PERFORMANCE_SCHEMA);
    }
}

exit($exitCode);
