<?php

declare(strict_types=1);

/**
 * DexAuditConcurrencyTest · T-DEX-10
 *
 * Certifica con procesos y conexiones reales contra PostgreSQL que el alta de
 * volcados `.dex` no duplica hechos ni pierde los códigos canónicos bajo carrera:
 *
 *   1. **Dos actores, mismo SHA-256 y distinta mutación.** Solo uno persiste el
 *      volcado; el otro recibe `409 DEX_DUPLICATE_HASH` y no se crea una segunda
 *      cabecera ni líneas.
 *   2. **Dos actores, misma `client_mutation_id` y distinto contenido.** Solo uno
 *      persiste; el otro recibe `409 CLIENT_MUTATION_ID_ALREADY_USED`.
 *   3. **Replay secuencial de la misma mutación en la misma máquina.** Devuelve
 *      el mismo volcado con `idempotent_replay = true`, sin insertar otra fila.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL real y trabaja en un esquema aislado;
 * sin motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/DexAuditConcurrencyTest.php
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
const DEX10_CONCURRENCY_SCHEMA = 't_dex10_conc';
const DEX10_CONCURRENCY_APP = 'vendguard-dex10-concurrency';

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
echo " VENDGUARD · T-DEX-10 · Concurrencia real de auditorías EVA-DTS (.dex)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$pgPdo->exec("SET application_name TO '" . DEX10_CONCURRENCY_APP . "'");

$schemaReady = false;
$crash = null;
$exitCode = 1;

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo, DEX10_CONCURRENCY_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    echo "\n--- Escenario: coordinación, zona, sede y dos máquinas ---\n";

    $userStmt = $pgPdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
         RETURNING id'
    );
    $userStmt->execute([
        ':name' => 'Coordinación Concurrencia DEX',
        ':email' => 'dex10.concurrency.coord@vendguard.internal',
        ':email_key' => 'dex10.concurrency.coord@vendguard.internal',
        ':hash' => password_hash('dex10-concurrency', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => 'COORDINATOR',
        ':operator' => 'DEX10-CONC',
    ]);
    $coordinatorId = (int)$userStmt->fetchColumn();

    $zoneId = (int)$pgPdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX10-CONC', 'Zona concurrencia DEX', '#2560ff') RETURNING id"
    )->fetchColumn();

    $locationStmt = $pgPdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX10-CONC',
        ':site_code_key' => 'sede-dex10-conc',
        ':name' => 'Sede concurrencia DEX',
        ':address' => 'Carrer de la Concurrència 10, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $createMachine = static function (string $code, string $type) use ($pgPdo, $locationId): int {
        $stmt = $pgPdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :machine_type, :floor_wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo concurrencia ' . $code,
            ':machine_type' => $type,
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $machineA = $createMachine('DEX10-CONC-A', 'PERISHABLE_FOOD');
    $machineB = $createMachine('DEX10-CONC-B', 'SNACKS');

    $service = new MachineDexAuditService(
        new PdoMachineDexAuditRepository($pgPdo),
        new PdoMachineDexAuditGateway($pgPdo),
        new EvaDtsParser(),
        new DexSelectionMatcher(),
        new DexAuditValidator(),
        new PdoNotificationOutboxRepository($pgPdo)
    );

    $makeRaw = static function (string $machineCode, float $sales, int $units): string {
        return "DXS*COMM-DEX10*VA*DEX 6.0\n"
            . "ID1*{$machineCode}\n"
            . "ID4*2\n"
            . "VA1*" . number_format($sales, 2, '.', '') . "*{$units}\n"
            . "CA3*500*0*0\n"
            . "PA1*A1*2\n"
            . "PA2*10*1000\n"
            . "DXE*FIN";
    };

    $runRace = static function (
        int $machineId,
        array $slots
    ) use ($projectRoot, $coordinatorId): array {
        $prefix = tempnam(sys_get_temp_dir(), 'vendguard_dex10_race_');
        if ($prefix === false) {
            throw new RuntimeException('No se pudo crear el prefijo temporal de la carrera DEX.');
        }
        @unlink($prefix);

        $startFile = $prefix . '.start';
        $paths = [];
        $processes = [];
        $results = [];

        try {
            foreach ($slots as $slot => $payload) {
                $paths[$slot] = [
                    'ready' => $prefix . '.' . $slot . '.ready',
                    'result' => $prefix . '.' . $slot . '.result.json',
                    'stdout' => $prefix . '.' . $slot . '.out',
                    'stderr' => $prefix . '.' . $slot . '.err',
                ];

                $process = proc_open(
                    [
                        PHP_BINARY,
                        __DIR__ . '/../Support/DexAuditConcurrencyActor.php',
                        DEX10_CONCURRENCY_SCHEMA,
                        (string)$machineId,
                        (string)$coordinatorId,
                        'Coordinación Concurrencia DEX',
                        'COORDINATOR_CONSOLE',
                        'vendguard-dex10-race-' . $slot,
                        $paths[$slot]['ready'],
                        $startFile,
                        $paths[$slot]['result'],
                        json_encode($payload, JSON_THROW_ON_ERROR),
                    ],
                    [
                        1 => ['file', $paths[$slot]['stdout'], 'w'],
                        2 => ['file', $paths[$slot]['stderr'], 'w'],
                    ],
                    $pipes,
                    $projectRoot
                );

                if (!is_resource($process)) {
                    throw new RuntimeException('No se pudo abrir el actor ' . $slot . '.');
                }
                $processes[$slot] = $process;
            }

            $readyDeadline = microtime(true) + 10.0;
            while (microtime(true) < $readyDeadline) {
                $allReady = true;
                foreach ($paths as $path) {
                    if (!is_file($path['ready'])) {
                        $allReady = false;
                        break;
                    }
                }
                if ($allReady) {
                    break;
                }
                usleep(20_000);
            }
            foreach ($paths as $path) {
                if (!is_file($path['ready'])) {
                    throw new RuntimeException('Un actor no cruzó la barrera de preparación.');
                }
            }

            file_put_contents($startFile, 'go');

            $finishDeadline = microtime(true) + 25.0;
            foreach ($processes as $slot => $process) {
                $status = proc_get_status($process);
                while ($status['running'] && microtime(true) < $finishDeadline) {
                    usleep(20_000);
                    $status = proc_get_status($process);
                }
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
                unset($processes[$slot]);

                $raw = is_file($paths[$slot]['result']) ? (string)file_get_contents($paths[$slot]['result']) : '';
                $decoded = $raw !== '' ? json_decode($raw, true) : null;
                $results[$slot] = is_array($decoded) ? $decoded : [];
            }
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if (is_file($startFile)) {
                @unlink($startFile);
            }
            foreach ($paths as $group) {
                foreach ($group as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }

        return $results;
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Carrera por la misma huella SHA-256
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Dos actores compiten con el mismo SHA-256 ---\n";

    $sharedRaw = $makeRaw('DEX10-CONC-A', 100.00, 10);
    $hashRace = $runRace($machineA, [
        'a' => [
            'raw_payload' => $sharedRaw,
            'original_filename' => 'hash-a.dex',
            'captured_at' => '2026-11-24 09:00:00+00',
            'client_mutation_id' => 'dex10-conc-hash-a-0001',
            'expected_machine_code' => 'DEX10-CONC-A',
        ],
        'b' => [
            'raw_payload' => $sharedRaw,
            'original_filename' => 'hash-b.dex',
            'captured_at' => '2026-11-24 09:01:00+00',
            'client_mutation_id' => 'dex10-conc-hash-b-0002',
            'expected_machine_code' => 'DEX10-CONC-A',
        ],
    ]);
    $hashOk = array_values(array_filter($hashRace, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $hashFail = array_values(array_filter($hashRace, static fn(array $r): bool => ($r['ok'] ?? false) === false));
    $hashCount = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM machine_dex_audits WHERE machine_id = ' . $machineA
    )->fetchColumn();

    $assert(
        '1.1 Solo uno de los dos actores persiste el mismo SHA-256',
        count($hashOk) === 1 && count($hashFail) === 1 && $hashCount === 1,
        'ok=' . count($hashOk) . ' fail=' . json_encode($hashFail) . ' rows=' . $hashCount
    );
    $assert(
        '1.2 El perdedor recibe 409 DEX_DUPLICATE_HASH y no un error de infraestructura',
        ($hashFail[0]['http'] ?? 0) === 409
            && ($hashFail[0]['code'] ?? '') === 'DEX_DUPLICATE_HASH'
            && !isset($hashFail[0]['fatal']),
        json_encode($hashFail)
    );
    $assert(
        '1.3 El volcado ganador conserva el código DEX-AAAA-NNNN y una única cabecera',
        preg_match('/^DEX-\d{4}-\d{4,}$/', (string)($hashOk[0]['audit_code'] ?? '')) === 1
            && (int)($hashOk[0]['id'] ?? 0) > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Carrera por la misma mutación Offline
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Dos actores compiten con la misma client_mutation_id ---\n";

    $mutationId = 'dex10-conc-mutation-0001';
    $mutationRace = $runRace($machineA, [
        'a' => [
            'raw_payload' => $makeRaw('DEX10-CONC-A', 200.00, 20),
            'original_filename' => 'mutation-a.dex',
            'captured_at' => '2026-11-24 10:00:00+00',
            'client_mutation_id' => $mutationId,
            'expected_machine_code' => 'DEX10-CONC-A',
        ],
        'b' => [
            'raw_payload' => $makeRaw('DEX10-CONC-A', 300.00, 30),
            'original_filename' => 'mutation-b.dex',
            'captured_at' => '2026-11-24 10:01:00+00',
            'client_mutation_id' => $mutationId,
            'expected_machine_code' => 'DEX10-CONC-A',
        ],
    ]);
    $mutationOk = array_values(array_filter($mutationRace, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $mutationFail = array_values(array_filter($mutationRace, static fn(array $r): bool => ($r['ok'] ?? false) === false));
    $mutationRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM machine_dex_audits WHERE machine_id = ' . $machineA
    )->fetchColumn();
    $mutationUses = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM machine_dex_audits WHERE client_mutation_id = '{$mutationId}'"
    )->fetchColumn();

    $assert(
        '2.1 Solo uno de los dos actores persiste la mutación y la máquina acumula dos volcados',
        count($mutationOk) === 1 && count($mutationFail) === 1 && $mutationRows === 2 && $mutationUses === 1,
        'ok=' . count($mutationOk) . ' fail=' . json_encode($mutationFail) . ' rows=' . $mutationRows . ' uses=' . $mutationUses
    );
    $assert(
        '2.2 El perdedor recibe 409 CLIENT_MUTATION_ID_ALREADY_USED sin duplicar la mutación',
        ($mutationFail[0]['http'] ?? 0) === 409
            && ($mutationFail[0]['code'] ?? '') === 'CLIENT_MUTATION_ID_ALREADY_USED'
            && !isset($mutationFail[0]['fatal']),
        json_encode($mutationFail)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Replay idempotente de la misma mutación y máquina
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Replay secuencial idempotente ---\n";

    $replayPayload = [
        'machine_id' => $machineB,
        'actor_id' => $coordinatorId,
        'import_source' => 'COORDINATOR_CONSOLE',
        'raw_payload' => $makeRaw('DEX10-CONC-B', 90.00, 9),
        'original_filename' => 'replay.dex',
        'captured_at' => '2026-11-24 11:00:00+00',
        'client_mutation_id' => 'dex10-conc-replay-0001',
        'expected_machine_code' => 'DEX10-CONC-B',
    ];
    $first = $service->importDex($replayPayload);
    $second = $service->importDex($replayPayload);
    $replayRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM machine_dex_audits WHERE machine_id = ' . $machineB
    )->fetchColumn();

    $assert(
        '3.1 El replay devuelve el mismo id con idempotent_replay y no inserta otra fila',
        (int)($first['audit']['id'] ?? 0) > 0
            && (int)($second['audit']['id'] ?? 0) === (int)($first['audit']['id'] ?? -1)
            && ($first['idempotent_replay'] ?? true) === false
            && ($second['idempotent_replay'] ?? false) === true
            && $replayRows === 1,
        'first=' . json_encode($first['audit']['id'] ?? null) . ' second=' . json_encode($second['audit']['id'] ?? null) . ' rows=' . $replayRows
    );

    $exitCode = $failures === 0 ? 0 : 1;
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    if ($pgPdo->inTransaction()) {
        $pgPdo->rollBack();
    }
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pgPdo, DEX10_CONCURRENCY_SCHEMA);
        } catch (Throwable $cleanupException) {
            echo "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n";
            $failures++;
        }
    }
}

if ($crash !== null) {
    echo "\n[ERROR INESPERADO EN LA SUITE]\n";
    echo $crash->getMessage() . "\n";
    echo $crash->getTraceAsString() . "\n";
    $failures++;
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. T-DEX-10 (concurrencia .dex) CERTIFICADO.\n"
    : " RESULTADO: FALLOS DETECTADOS EN LA CONCURRENCIA .DEX.\n";
echo "======================================================================\n";
exit($failures > 0 ? 1 : 0);
