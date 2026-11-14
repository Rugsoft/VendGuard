<?php

declare(strict_types=1);

/**
 * ProductLoadingConcurrencyTest · B-07 · §6.6 / RF-04.1 / RF-04.2
 *
 * Certifica el doble clic de la recepción y de la liquidación con DOS
 * CONEXIONES REALES compitiendo por la misma cabecera, no con una segunda
 * llamada secuencial:
 *
 *   1. La conexión principal retiene la orden con `SELECT ... FOR UPDATE`.
 *   2. Dos procesos actores cruzan una barrera común y llaman al servicio real
 *      de confirmación. Ambos quedan esperando el cerrojo en `pg_stat_activity`.
 *   3. Al liberar la cabecera, uno confirma y el otro recibe
 *      `409 INVALID_ORDER_STATE_TRANSITION`.
 *   4. El stock aumenta exactamente una vez y la auditoría registra una sola
 *      recepción. La misma carrera se repite para la liquidación: un solo
 *      `RECONCILED_AT_CLOSE`, un solo saldo final y un solo evento.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL real y trabaja en un esquema
 * aislado; sin motor responde código 2, nunca un verde silencioso. El esquema
 * se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && \
 *       php tests/integration/ProductLoadingConcurrencyTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const PLD_CONCURRENCY_SCHEMA = 't_pld_concurrency';
const PLD_CONCURRENCY_PARENT_APP = 'vendguard-pld-concurrency-parent';

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
echo " VENDGUARD · B-07 · Concurrencia real de recepción y liquidación\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$pgPdo->exec("SET application_name TO '" . PLD_CONCURRENCY_PARENT_APP . "'");

$schemaReady = false;
$crash = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo, PLD_CONCURRENCY_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    echo "\n--- Escenario aislado: usuarios, producto y orden despachada ---\n";

    $insertUser = $pgPdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
         RETURNING id'
    );

    $insertUser->execute([
        ':name' => 'Reponedor Concurrencia B-07',
        ':email' => 'pld.b07.restocker@vendguard.internal',
        ':email_key' => 'pld.b07.restocker@vendguard.internal',
        ':hash' => password_hash('pld-b07-restocker', PASSWORD_BCRYPT),
        ':role' => 'RESTOCKER',
        ':operator' => 'PLD-B07-R',
    ]);
    $restockerId = (int)$insertUser->fetchColumn();

    $insertUser->execute([
        ':name' => 'Coordinación Concurrencia B-07',
        ':email' => 'pld.b07.coordinator@vendguard.internal',
        ':email_key' => 'pld.b07.coordinator@vendguard.internal',
        ':hash' => password_hash('pld-b07-coordinator', PASSWORD_BCRYPT),
        ':role' => 'COORDINATOR',
        ':operator' => 'PLD-B07-C',
    ]);
    $coordinatorId = (int)$insertUser->fetchColumn();

    $product = (new PdoVendingProductRepository($pgPdo))->create(new VendingProduct(
        null,
        'TEST-PLD-T10-AMB',
        'Producto ambiente de concurrencia B-07',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.60,
        1.40,
        false,
        null,
        true
    ));
    $productId = (int)$product->getId();

    $service = new ProductLoadingOrderService(
        new PdoProductLoadingOrderRepository($pgPdo),
        new PdoRestockerVanStockRepository($pgPdo),
        new PdoVendingProductRepository($pgPdo),
        new PdoUserRepository($pgPdo),
        new AuditLogger(new PdoAuditLogRepository($pgPdo)),
        $pgPdo
    );

    $coordinatorActor = [
        'id' => $coordinatorId,
        'role' => 'COORDINATOR',
        'name' => 'Coordinación Concurrencia B-07',
    ];
    $restockerActor = [
        'id' => $restockerId,
        'role' => 'RESTOCKER',
        'name' => 'Reponedor Concurrencia B-07',
    ];

    $created = $service->createOrder(
        $restockerId,
        ['items' => [[
            'product_id' => $productId,
            'reason' => 'ROUTE_GAP_FILL',
            'requested_qty' => 4,
        ]]],
        $coordinatorActor
    );
    $orderId = (int)($created['order']['id'] ?? 0);

    $service->dispatchOrder(
        $orderId,
        ['items' => [[
            'product_id' => $productId,
            'dispatched_qty' => 4,
        ]]],
        $coordinatorActor
    );

    $stockQuantity = static function () use ($pgPdo, $restockerId, $productId): ?int {
        $stmt = $pgPdo->prepare(
            'SELECT quantity_available FROM restocker_van_product_stocks
              WHERE restocker_id = :restocker_id AND product_id = :product_id'
        );
        $stmt->execute([':restocker_id' => $restockerId, ':product_id' => $productId]);
        $quantity = $stmt->fetchColumn();

        return $quantity === false ? null : (int)$quantity;
    };

    $orderStatus = static function () use ($pgPdo, $orderId): string {
        $stmt = $pgPdo->prepare('SELECT status FROM product_loading_orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);

        return (string)$stmt->fetchColumn();
    };

    $auditCount = static function (string $action) use ($pgPdo): int {
        $stmt = $pgPdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->execute([':action' => $action]);

        return (int)$stmt->fetchColumn();
    };

    /**
     * Lanza dos actores reales contra una cabecera que el padre ya tiene
     * bloqueada. Devuelve lo observado y los resultados de ambos procesos.
     *
     * @param array<string, mixed> $payload
     * @return array{observed: bool, exitCodes: array<string, int>, results: array<string, array<string, mixed>>, outputs: array<string, string>}
     */
    $runRace = static function (string $operation, array $payload, string $label) use (
        $pgPdo,
        $projectRoot,
        $orderId,
        $restockerId,
        $restockerActor
    ): array {
        $prefix = tempnam(sys_get_temp_dir(), 'vendguard_pld_race_');
        if ($prefix === false) {
            throw new RuntimeException('No se pudo crear el prefijo temporal de la carrera.');
        }
        @unlink($prefix);

        $startFile = $prefix . '.start';
        $processes = [];
        $paths = [];
        $lockOpen = false;

        try {
            $pgPdo->beginTransaction();
            $lockOpen = true;

            $lock = $pgPdo->prepare(
                'SELECT id FROM product_loading_orders WHERE id = :id FOR UPDATE'
            );
            $lock->execute([':id' => $orderId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('La orden no existe para la carrera de concurrencia.');
            }

            $applicationNames = [];
            foreach (['a', 'b'] as $slot) {
                $applicationNames[$slot] = 'vendguard-pld-' . $label . '-' . $slot;
                $paths[$slot] = [
                    'ready' => $prefix . '.' . $slot . '.ready',
                    'result' => $prefix . '.' . $slot . '.result.json',
                    'stdout' => $prefix . '.' . $slot . '.out',
                    'stderr' => $prefix . '.' . $slot . '.err',
                ];

                $command = [
                    PHP_BINARY,
                    __DIR__ . '/../Support/ProductLoadingConcurrencyActor.php',
                    PLD_CONCURRENCY_SCHEMA,
                    $operation,
                    (string)$orderId,
                    (string)$restockerId,
                    (string)$restockerActor['id'],
                    (string)$restockerActor['name'],
                    $applicationNames[$slot],
                    $paths[$slot]['ready'],
                    $startFile,
                    $paths[$slot]['result'],
                    json_encode($payload, JSON_THROW_ON_ERROR),
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

            $waiting = $pgPdo->prepare(
                "SELECT COUNT(DISTINCT application_name)
                   FROM pg_stat_activity
                  WHERE application_name IN (:first, :second)
                    AND wait_event_type = 'Lock'"
            );
            $waiting->execute([
                ':first' => $applicationNames['a'],
                ':second' => $applicationNames['b'],
            ]);

            $observed = false;
            $waitDeadline = microtime(true) + 8.0;
            while (microtime(true) < $waitDeadline) {
                $waiting->execute([
                    ':first' => $applicationNames['a'],
                    ':second' => $applicationNames['b'],
                ]);
                if ((int)$waiting->fetchColumn() === 2) {
                    $observed = true;
                    break;
                }
                usleep(20_000);
            }

            $pgPdo->commit();
            $lockOpen = false;

            $finishDeadline = microtime(true) + 15.0;
            $allFinished = false;
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
            $outputs = [];
            foreach ($processes as $slot => $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                $exitCode = $status['running'] ? -1 : (int)$status['exitcode'];
                proc_close($process);
                unset($processes[$slot]);

                $exitCodes[$slot] = $exitCode;
                $resultRaw = is_file($paths[$slot]['result'])
                    ? (string)file_get_contents($paths[$slot]['result'])
                    : '';
                $decoded = $resultRaw !== '' ? json_decode($resultRaw, true) : null;
                $results[$slot] = is_array($decoded) ? $decoded : [];
                $outputs[$slot] = trim(
                    (string)@file_get_contents($paths[$slot]['stdout'])
                    . "\n"
                    . (string)@file_get_contents($paths[$slot]['stderr'])
                );
            }

            return [
                'observed' => $observed,
                'exitCodes' => $exitCodes,
                'results' => $results,
                'outputs' => $outputs,
            ];
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
                unlink($startFile);
            }
            foreach ($paths as $pathGroup) {
                foreach ($pathGroup as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    };

    $raceDetail = static function (array $race): string {
        return 'iteraciones=' . json_encode($race['exitCodes'], JSON_UNESCAPED_UNICODE)
            . ' resultados=' . json_encode($race['results'], JSON_UNESCAPED_UNICODE)
            . ' salidas=' . json_encode($race['outputs'], JSON_UNESCAPED_UNICODE);
    };

    echo "\n--- 1. Doble clic en la confirmación de recepción ---\n";

    $confirmRace = $runRace('confirm', [], 'confirm');
    $confirmResults = array_values($confirmRace['results']);
    $confirmWinners = array_values(array_filter(
        $confirmResults,
        static fn (array $result): bool => ($result['ok'] ?? false) === true
    ));
    $confirmLosers = array_values(array_filter(
        $confirmResults,
        static fn (array $result): bool => ($result['ok'] ?? true) === false
    ));

    $assert(
        '1.1 Los dos actores esperan realmente el FOR UPDATE antes de liberar la cabecera',
        $confirmRace['observed'] === true,
        $raceDetail($confirmRace)
    );
    $assert(
        '1.2 Los dos procesos terminan sin error de infraestructura',
        count($confirmRace['exitCodes']) === 2
            && array_sum(array_map(
                static fn (int $code): int => $code === 0 ? 0 : 1,
                $confirmRace['exitCodes']
            )) === 0,
        $raceDetail($confirmRace)
    );
    $assert(
        '1.3 Solo una confirmación gana; la otra recibe 409 INVALID_ORDER_STATE_TRANSITION',
        count($confirmWinners) === 1
            && count($confirmLosers) === 1
            && ($confirmLosers[0]['code'] ?? '') === 'INVALID_ORDER_STATE_TRANSITION'
            && (int)($confirmLosers[0]['http'] ?? 0) === 409
            && ($confirmWinners[0]['status'] ?? '') === 'CONFIRMED_BY_RESTOCKER',
        $raceDetail($confirmRace)
    );
    $assert(
        '1.4 La orden queda confirmada una sola vez',
        $orderStatus() === 'CONFIRMED_BY_RESTOCKER',
        'estado=' . $orderStatus()
    );
    $assert(
        '1.5 El stock se incrementa exactamente una vez: 0 + 4 = 4',
        $stockQuantity() === 4,
        'saldo=' . var_export($stockQuantity(), true)
    );
    $assert(
        '1.6 La carrera deja una sola auditoría de confirmación y una sola recepción de stock',
        $auditCount('PRODUCT_LOADING_ORDER_CONFIRMED') === 1
            && $auditCount('RESTOCKER_VAN_STOCK_RECEIVED') === 1,
        'confirmaciones=' . $auditCount('PRODUCT_LOADING_ORDER_CONFIRMED')
            . ' recepciones=' . $auditCount('RESTOCKER_VAN_STOCK_RECEIVED')
    );

    echo "\n--- 2. Doble clic en la liquidación al cierre ---\n";

    $reconciliationPayload = [
        'items' => [[
            'product_id' => $productId,
            'restocked_in_machines_qty' => 1,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 3,
        ]],
    ];

    $reconcileRace = $runRace('reconcile', $reconciliationPayload, 'reconcile');
    $reconcileResults = array_values($reconcileRace['results']);
    $reconcileWinners = array_values(array_filter(
        $reconcileResults,
        static fn (array $result): bool => ($result['ok'] ?? false) === true
    ));
    $reconcileLosers = array_values(array_filter(
        $reconcileResults,
        static fn (array $result): bool => ($result['ok'] ?? true) === false
    ));

    $assert(
        '2.1 Los dos actores esperan realmente el FOR UPDATE antes de liberar la cabecera',
        $reconcileRace['observed'] === true,
        $raceDetail($reconcileRace)
    );
    $assert(
        '2.2 Los dos procesos terminan sin error de infraestructura',
        count($reconcileRace['exitCodes']) === 2
            && array_sum(array_map(
                static fn (int $code): int => $code === 0 ? 0 : 1,
                $reconcileRace['exitCodes']
            )) === 0,
        $raceDetail($reconcileRace)
    );
    $assert(
        '2.3 Solo una liquidación gana; la otra recibe 409 INVALID_ORDER_STATE_TRANSITION',
        count($reconcileWinners) === 1
            && count($reconcileLosers) === 1
            && ($reconcileLosers[0]['code'] ?? '') === 'INVALID_ORDER_STATE_TRANSITION'
            && (int)($reconcileLosers[0]['http'] ?? 0) === 409
            && ($reconcileWinners[0]['status'] ?? '') === 'RECONCILED_AT_CLOSE',
        $raceDetail($reconcileRace)
    );
    $assert(
        '2.4 La orden queda liquidada una sola vez',
        $orderStatus() === 'RECONCILED_AT_CLOSE',
        'estado=' . $orderStatus()
    );
    $assert(
        '2.5 El saldo final es el retorno contado una sola vez: 3',
        $stockQuantity() === 3,
        'saldo=' . var_export($stockQuantity(), true)
    );
    $assert(
        '2.6 La carrera deja una sola auditoría de liquidación y una sola conciliación de stock',
        $auditCount('PRODUCT_LOADING_ORDER_RECONCILED') === 1
            && $auditCount('RESTOCKER_VAN_STOCK_RECONCILED') === 1,
        'liquidaciones=' . $auditCount('PRODUCT_LOADING_ORDER_RECONCILED')
            . ' conciliaciones=' . $auditCount('RESTOCKER_VAN_STOCK_RECONCILED')
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    if ($pgPdo->inTransaction()) {
        $pgPdo->rollBack();
    }

    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pgPdo, PLD_CONCURRENCY_SCHEMA);
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
