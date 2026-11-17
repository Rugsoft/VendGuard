<?php

declare(strict_types=1);

/**
 * CashCollectionConcurrencyTest · FIX-35-06 · RF-02.1 / C1 / C3
 *
 * Certifica con DOS CONEXIONES REALES contra PostgreSQL que el bloqueo
 * `SELECT ... FROM machines m ... FOR UPDATE OF m` de
 * `PdoMachineCashCollectionGateway::lockMachineContext()` serializa las
 * capturas concurrentes sobre la misma máquina:
 *
 *   1. **Barrera común sobre el `FOR UPDATE OF m` (sin duplicar).** La conexión
 *      principal retiene la fila de la máquina con `FOR UPDATE`; dos procesos
 *      actores cruzan una barrera común intentando registrar la misma mutación
 *      (y el mismo precinto). Ambos quedan esperando el cerrojo en
 *      `pg_stat_activity` (`wait_event_type = 'Lock'`). Al confirmar el padre,
 *      ambos actores ven la fila final y devuelven la misma recaudación sin
 *      duplicar filas ni eventos `CASH_COLLECTION_SEALED`.
 *   2. **Lectura posterior al bloqueo.** Un proceso retenedor toma el `FOR
 *      UPDATE`, espera a observar al servicio real detenido en `pg_stat_activity`,
 *      registra una captura con lectura `200,00 €` y confirma; la petición del
 *      padre despierta entonces, lee el estado ya confirmado y rechaza `150,00 €`
 *      con `422 SALES_COUNTER_REGRESSION`, dejando una sola fila (`200,00 €`).
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL real y trabaja en un esquema
 * aislado; sin motor responde código 2, nunca un verde silencioso. El esquema
 * se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/CashCollectionConcurrencyTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionGateway;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const CASH_CONCURRENCY_SCHEMA = 't_ccv_concurrency';
const CASH_CONCURRENCY_PARENT_APP = 'vendguard-ccv-concurrency-parent';

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
echo " VENDGUARD · FIX-35-06 · Concurrencia real FOR UPDATE en RF-02.1\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$pgPdo->exec("SET application_name TO '" . CASH_CONCURRENCY_PARENT_APP . "'");

$schemaReady = false;
$crash = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo, CASH_CONCURRENCY_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    echo "\n--- Escenario aislado: zona, sede, máquina y reponedor ---\n";

    $userStmt = $pgPdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
         RETURNING id'
    );
    $userStmt->execute([
        ':name' => 'Reponedor Concurrencia CCV',
        ':email' => 'ccv.concurrency.restocker@vendguard.internal',
        ':email_key' => 'ccv.concurrency.restocker@vendguard.internal',
        ':hash' => password_hash('ccv-concurrency', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => 'RESTOCKER',
        ':operator' => 'CCV-CONC-R',
    ]);
    $restockerId = (int)$userStmt->fetchColumn();

    $zoneId = (int)$pgPdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-CCV-CONC', 'Zona de concurrencia CCV', '#2560ff')
         RETURNING id"
    )->fetchColumn();

    $locationStmt = $pgPdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id, TRUE)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-CCV-CONC',
        ':site_code_key' => 'sede-ccv-conc',
        ':name' => 'Sede de concurrencia CCV',
        ':address' => 'Carrer de la Bloqueig 35, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pgPdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, 'CCV-CONC-01', 'Modelo concurrencia CCV', 'SNACKS', 'Planta baja')
         RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId]);
    $machineId = (int)$machineStmt->fetchColumn();

    $assignStmt = $pgPdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assignStmt->execute([':restocker_id' => $restockerId, ':zone_id' => $zoneId]);

    $countRows = static function () use ($pgPdo, $machineId): int {
        $stmt = $pgPdo->prepare('SELECT COUNT(*) FROM machine_cash_collections WHERE machine_id = :machine_id');
        $stmt->execute([':machine_id' => $machineId]);

        return (int)$stmt->fetchColumn();
    };

    $countSealedAudits = static function () use ($pgPdo): int {
        return (int)$pgPdo->query(
            "SELECT COUNT(*) FROM audit_log
              WHERE entity_type = 'MACHINE_CASH_COLLECTION'
                AND action = 'CASH_COLLECTION_SEALED'"
        )->fetchColumn();
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Barrera común con dos actores reales sobre FOR UPDATE OF m
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Dos actores en barrera común compitiendo por FOR UPDATE OF m ---\n";

    $prefix = tempnam(sys_get_temp_dir(), 'vendguard_ccv_race_');
    if ($prefix === false) {
        throw new RuntimeException('No se pudo crear el prefijo temporal de la carrera.');
    }
    @unlink($prefix);

    $startFile = $prefix . '.start';
    $processes = [];
    $paths = [];
    $lockOpen = false;
    $barrierPayload = [
        'client_mutation_id' => 'ccv-conc-barrier-0001',
        'bag_seal_code' => 'BAG-CCV-CONC-0001',
        'sales_counter_reading' => 120.00,
        'change_tubes_loaded_eur' => 10.00,
    ];

    try {
        $pgPdo->beginTransaction();
        $lockOpen = true;

        $lockStmt = $pgPdo->prepare(
            'SELECT id FROM machines WHERE id = :id AND is_active = TRUE AND deleted_at IS NULL FOR UPDATE'
        );
        $lockStmt->execute([':id' => $machineId]);
        if ($lockStmt->fetchColumn() === false) {
            throw new RuntimeException('No se pudo bloquear la máquina para la barrera común.');
        }

        $applicationNames = [];
        foreach (['a', 'b'] as $slot) {
            $applicationNames[$slot] = 'vendguard-ccv-barrier-' . $slot;
            $paths[$slot] = [
                'ready' => $prefix . '.' . $slot . '.ready',
                'result' => $prefix . '.' . $slot . '.result.json',
                'stdout' => $prefix . '.' . $slot . '.out',
                'stderr' => $prefix . '.' . $slot . '.err',
            ];

            $command = [
                PHP_BINARY,
                __DIR__ . '/../Support/CashCollectionConcurrencyActor.php',
                CASH_CONCURRENCY_SCHEMA,
                'register',
                (string)$machineId,
                (string)$restockerId,
                'Reponedor Concurrencia CCV',
                $applicationNames[$slot],
                CASH_CONCURRENCY_PARENT_APP,
                $paths[$slot]['ready'],
                $startFile,
                $paths[$slot]['result'],
                json_encode($barrierPayload, JSON_THROW_ON_ERROR),
            ];

            $process = proc_open(
                $command,
                [
                    1 => ['file', $paths[$slot]['stdout'], 'w'],
                    2 => ['file', $paths[$slot]['stderr'], 'w'],
                ],
                $pipes,
                $projectRoot
            );

            if (!is_resource($process)) {
                throw new RuntimeException('No se pudo abrir el actor concurrente ' . $slot . '.');
            }

            $processes[$slot] = $process;
        }

        $readyDeadline = microtime(true) + 8.0;
        while (
            (!is_file($paths['a']['ready']) || !is_file($paths['b']['ready']))
            && microtime(true) < $readyDeadline
        ) {
            usleep(20_000);
        }

        if (!is_file($paths['a']['ready']) || !is_file($paths['b']['ready'])) {
            throw new RuntimeException('Los actores no cruzaron la barrera de preparación.');
        }

        file_put_contents($startFile, 'go');

        $waitingStmt = $pgPdo->prepare(
            "SELECT COUNT(DISTINCT application_name)
               FROM pg_stat_activity
              WHERE application_name IN (:first, :second)
                AND wait_event_type = 'Lock'"
        );

        $observedBothWaiting = false;
        $waitDeadline = microtime(true) + 8.0;
        while (microtime(true) < $waitDeadline) {
            $waitingStmt->execute([
                ':first' => $applicationNames['a'],
                ':second' => $applicationNames['b'],
            ]);
            if ((int)$waitingStmt->fetchColumn() === 2) {
                $observedBothWaiting = true;
                break;
            }
            usleep(20_000);
        }

        $pgPdo->commit();
        $lockOpen = false;

        $finishDeadline = microtime(true) + 15.0;
        while (microtime(true) < $finishDeadline) {
            $allFinished = true;
            foreach ($processes as $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    $allFinished = false;
                    break;
                }
            }
            if ($allFinished) {
                break;
            }
            usleep(20_000);
        }

        $exitCodes = [];
        $results = [];
        foreach ($processes as $slot => $process) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            $exitCodes[$slot] = $status['running'] ? -1 : (int)$status['exitcode'];
            proc_close($process);
            unset($processes[$slot]);

            $raw = is_file($paths[$slot]['result']) ? (string)file_get_contents($paths[$slot]['result']) : '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $results[$slot] = is_array($decoded) ? $decoded : [];
        }
    } finally {
        if ($lockOpen && $pgPdo->inTransaction()) {
            $pgPdo->rollBack();
        }
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

    $assert(
        '1.1 Ambos actores esperan realmente el bloqueo FOR UPDATE OF m en pg_stat_activity',
        $observedBothWaiting === true
    );
    $assert(
        '1.2 Ambos procesos terminan sin error de infraestructura',
        ($exitCodes['a'] ?? -1) === 0 && ($exitCodes['b'] ?? -1) === 0,
        json_encode($exitCodes)
    );
    $assert(
        '1.3 Ambos procesos convergen en el mismo ID de recaudación sin duplicar fila ni auditoría',
        ($results['a']['ok'] ?? false) === true
            && ($results['b']['ok'] ?? false) === true
            && (int)($results['a']['id'] ?? 0) > 0
            && (int)($results['a']['id'] ?? 0) === (int)($results['b']['id'] ?? -1)
            && $countRows() === 1
            && $countSealedAudits() === 1,
        'rows=' . $countRows() . ' audits=' . $countSealedAudits() . ' results=' . json_encode($results)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Serialización con lectura posterior al bloqueo (C3 tras COMMIT ajeno)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Serialización FOR UPDATE y lectura del estado final ---\n";

    $holdPrefix = tempnam(sys_get_temp_dir(), 'vendguard_ccv_hold_');
    if ($holdPrefix === false) {
        throw new RuntimeException('No se pudo crear el prefijo temporal del retenedor.');
    }
    @unlink($holdPrefix);

    $holdPaths = [
        'ready' => $holdPrefix . '.ready',
        'result' => $holdPrefix . '.result.json',
        'stdout' => $holdPrefix . '.out',
        'stderr' => $holdPrefix . '.err',
    ];
    $holdStartDummy = $holdPrefix . '.unused';
    $holderProcess = null;

    try {
        $holderPayload = [
            'client_mutation_id' => 'ccv-conc-holder-0002',
            'bag_seal_code' => 'BAG-CCV-CONC-0002',
            'sales_counter_reading' => 200.00,
            'change_tubes_loaded_eur' => 5.00,
        ];

        $holderProcess = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/../Support/CashCollectionConcurrencyActor.php',
                CASH_CONCURRENCY_SCHEMA,
                'hold_and_register',
                (string)$machineId,
                (string)$restockerId,
                'Reponedor Concurrencia CCV',
                'vendguard-ccv-holder',
                CASH_CONCURRENCY_PARENT_APP,
                $holdPaths['ready'],
                $holdStartDummy,
                $holdPaths['result'],
                json_encode($holderPayload, JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['file', $holdPaths['stdout'], 'w'],
                2 => ['file', $holdPaths['stderr'], 'w'],
            ],
            $holderPipes,
            $projectRoot
        );

        if (!is_resource($holderProcess)) {
            throw new RuntimeException('No se pudo abrir el proceso retenedor.');
        }

        $readyDeadline = microtime(true) + 8.0;
        while ((!is_file($holdPaths['ready']) || trim((string)file_get_contents($holdPaths['ready'])) !== 'locked') && microtime(true) < $readyDeadline) {
            usleep(20_000);
        }

        if (!is_file($holdPaths['ready']) || trim((string)file_get_contents($holdPaths['ready'])) !== 'locked') {
            throw new RuntimeException('El proceso retenedor no bloqueó la máquina.');
        }

        $service = new MachineCashCollectionService(
            new PdoMachineCashCollectionRepository($pgPdo),
            new PdoMachineCashCollectionGateway($pgPdo),
            new PdoRefundRequestRepository($pgPdo),
            new PdoTransactionManager($pgPdo),
            new AuditLogger(new PdoAuditLogRepository($pgPdo))
        );

        $parentErrorCode = null;
        $parentHttpStatus = null;
        $parentSucceeded = false;

        try {
            $service->registerCollection($machineId, [
                'client_mutation_id' => 'ccv-conc-loser-0003',
                'bag_seal_code' => 'BAG-CCV-CONC-0003',
                'sales_counter_reading' => 150.00,
                'change_tubes_loaded_eur' => 5.00,
            ], [
                'id' => $restockerId,
                'role' => 'RESTOCKER',
                'name' => 'Reponedor Concurrencia CCV',
            ]);
            $parentSucceeded = true;
        } catch (MachineCashCollectionValidationException $exception) {
            $parentErrorCode = $exception->getErrorCode();
            $parentHttpStatus = $exception->getHttpStatusCode();
        }

        $waitDeadline = microtime(true) + 10.0;
        $holderStatus = proc_get_status($holderProcess);
        while ($holderStatus['running'] && microtime(true) < $waitDeadline) {
            usleep(20_000);
            $holderStatus = proc_get_status($holderProcess);
        }
        if ($holderStatus['running']) {
            proc_terminate($holderProcess);
        }
        $holderExit = $holderStatus['running'] ? -1 : (int)$holderStatus['exitcode'];
        proc_close($holderProcess);
        $holderProcess = null;

        $holderRaw = is_file($holdPaths['result']) ? (string)file_get_contents($holdPaths['result']) : '';
        $holderResult = $holderRaw !== '' ? json_decode($holderRaw, true) : null;
        $holderResult = is_array($holderResult) ? $holderResult : [];
    } finally {
        if (is_resource($holderProcess)) {
            proc_terminate($holderProcess);
            proc_close($holderProcess);
        }
        foreach ($holdPaths as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    $assert(
        '2.1 El retenedor observó al servicio real esperando el FOR UPDATE OF m',
        ($holderResult['observed_waiter'] ?? false) === true && $holderExit === 0 && ($holderResult['ok'] ?? false) === true,
        json_encode($holderResult)
    );
    $assert(
        '2.2 Tras el COMMIT ajeno, la segunda captura lee 200,00 € y rechaza 150,00 € con 422 SALES_COUNTER_REGRESSION',
        $parentSucceeded === false
            && $parentHttpStatus === 422
            && $parentErrorCode === 'SALES_COUNTER_REGRESSION',
        'http=' . var_export($parentHttpStatus, true) . ' code=' . var_export($parentErrorCode, true)
    );
    $assert(
        '2.3 Queda exactamente una nueva fila del corte ganador (total 2 en máquina) sin duplicar captura',
        $countRows() === 2 && $countSealedAudits() === 2,
        'rows=' . $countRows() . ' audits=' . $countSealedAudits()
    );
    // ────────────────────────────────────────────────
    // 3. Dos capturas simultáneas en máquinas distintas (H-5)
    // ────────────────────────────────────────────────
    echo "\n--- 3. Correlativo anual bajo dos capturas simultáneas ---\n";

    $machine2Stmt = $pgPdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, 'CCV-CONC-02', 'Modelo concurrencia CCV 2', 'SNACKS', 'Planta baja')
         RETURNING id"
    );
    $machine2Stmt->execute([':location_id' => $locationId]);
    $machine2Id = (int)$machine2Stmt->fetchColumn();

    $seqPrefix = tempnam(sys_get_temp_dir(), 'vendguard_ccv_seq_');
    if ($seqPrefix === false) {
        throw new RuntimeException('No se pudo crear el prefijo temporal del correlativo.');
    }
    @unlink($seqPrefix);

    $seqStart = $seqPrefix . '.start';
    // Sin barrera de salida: los dos actores arrancan en cuanto escriben su 'ready'.
    file_put_contents($seqStart, 'go');

    $seqPaths = [];
    $seqProcesses = [];

    try {
        $seqActors = [
            'a' => [$machineId, 'ccv-conc-seq-0001', 'BAG-CCV-CONC-0011', 250.00],
            'b' => [$machine2Id, 'ccv-conc-seq-0002', 'BAG-CCV-CONC-0012', 140.00],
        ];

        foreach ($seqActors as $slot => [$seqMachineId, $mutation, $bag, $reading]) {
            $seqPaths[$slot] = [
                'ready' => $seqPrefix . '.' . $slot . '.ready',
                'result' => $seqPrefix . '.' . $slot . '.result.json',
                'stdout' => $seqPrefix . '.' . $slot . '.out',
                'stderr' => $seqPrefix . '.' . $slot . '.err',
            ];

            $command = [
                PHP_BINARY,
                __DIR__ . '/../Support/CashCollectionConcurrencyActor.php',
                CASH_CONCURRENCY_SCHEMA,
                'register',
                (string)$seqMachineId,
                (string)$restockerId,
                'Reponedor Concurrencia CCV',
                'vendguard-ccv-seq-' . $slot,
                CASH_CONCURRENCY_PARENT_APP,
                $seqPaths[$slot]['ready'],
                $seqStart,
                $seqPaths[$slot]['result'],
                json_encode([
                    'client_mutation_id' => $mutation,
                    'bag_seal_code' => $bag,
                    'sales_counter_reading' => $reading,
                    'change_tubes_loaded_eur' => 0.00,
                ], JSON_THROW_ON_ERROR),
            ];

            $process = proc_open(
                $command,
                [
                    1 => ['file', $seqPaths[$slot]['stdout'], 'w'],
                    2 => ['file', $seqPaths[$slot]['stderr'], 'w'],
                ],
                $pipes,
                $projectRoot
            );

            if (!is_resource($process)) {
                throw new RuntimeException('No se pudo abrir el actor secuencial ' . $slot . '.');
            }

            $seqProcesses[$slot] = $process;
        }

        $seqDeadline = microtime(true) + 20.0;
        $seqResults = [];

        foreach ($seqProcesses as $slot => $process) {
            $status = proc_get_status($process);
            while ($status['running'] && microtime(true) < $seqDeadline) {
                usleep(20_000);
                $status = proc_get_status($process);
            }
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            unset($seqProcesses[$slot]);

            $raw = is_file($seqPaths[$slot]['result']) ? (string)file_get_contents($seqPaths[$slot]['result']) : '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $seqResults[$slot] = is_array($decoded) ? $decoded : [];
        }
    } finally {
        foreach ($seqProcesses as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        if (is_file($seqStart)) {
            @unlink($seqStart);
        }
        foreach ($seqPaths as $group) {
            foreach ($group as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    $seqIds = [
        (int)($seqResults['a']['id'] ?? 0),
        (int)($seqResults['b']['id'] ?? 0),
    ];
    $codesStmt = $pgPdo->prepare(
        'SELECT collection_code FROM machine_cash_collections WHERE id IN (?, ?)'
    );
    $codesStmt->execute($seqIds);
    $seqCodes = array_map('strval', $codesStmt->fetchAll(PDO::FETCH_COLUMN));
    sort($seqCodes);

    $seqNumbers = array_map(
        static fn (string $code): int => (int)substr($code, strrpos($code, '-') + 1),
        $seqCodes
    );

    $assert(
        '3.1 Dos capturas simultáneas en máquinas distintas completan y reciben códigos distintos',
        ($seqResults['a']['ok'] ?? false) === true
            && ($seqResults['b']['ok'] ?? false) === true
            && count($seqCodes) === 2
            && count(array_unique($seqCodes)) === 2
            && preg_match('/^MCC-\d{4}-\d{4,}$/', $seqCodes[0] ?? '') === 1
            && preg_match('/^MCC-\d{4}-\d{4,}$/', $seqCodes[1] ?? '') === 1,
        json_encode(['codes' => $seqCodes, 'results' => $seqResults])
    );
    $assert(
        '3.2 Los dos códigos son consecutivos: el cerrojo de transacción del año serializa la reserva',
        count($seqNumbers) === 2
            && $seqNumbers[1] - $seqNumbers[0] === 1
            && substr($seqCodes[0], 0, 8) === substr($seqCodes[1], 0, 8),
        json_encode($seqCodes)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    if ($pgPdo->inTransaction()) {
        $pgPdo->rollBack();
    }

    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pgPdo, CASH_CONCURRENCY_SCHEMA);
        } catch (Throwable $cleanupException) {
            echo "\n[AVISO] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n";
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
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
