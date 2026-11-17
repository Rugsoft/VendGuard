<?php

declare(strict_types=1);

/**
 * CashCollectionConcurrencyActor · actor externo de la prueba de concurrencia
 * de captura de recaudaciones del Módulo 35 (FIX-35-06, RF-02.1).
 *
 * Se ejecuta en un proceso aparte para competir por el cerrojo real
 * `SELECT ... FROM machines m ... FOR UPDATE OF m` en
 * `PdoMachineCashCollectionGateway::lockMachineContext()`:
 *
 *   - `register`: abre su propia conexión PDO con `PG_TEST_DSN` y `search_path`
 *     aislado, avisa de su preparación, espera la señal de salida y llama a
 *     `MachineCashCollectionService::registerCollection()`.
 *   - `hold_and_register`: bloquea la fila de la máquina con `FOR UPDATE`,
 *     avisa al padre, espera a observar la conexión del padre detenida en
 *     `pg_stat_activity` (`wait_event_type = 'Lock'`), registra una recaudación
 *     dentro de esa misma transacción y confirma (`COMMIT`).
 *
 * Uso interno (lo invoca `tests/integration/CashCollectionConcurrencyTest.php`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionGateway;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;

if ($argc < 11) {
    fwrite(
        STDERR,
        "Uso: php CashCollectionConcurrencyActor.php <schema> <mode> <machineId> "
        . "<restockerId> <restockerName> <applicationName> <watchApplicationName> "
        . "<readyFile> <startFile> <resultFile> [payloadJson]\n"
    );
    exit(2);
}

$schema = (string)$argv[1];
$mode = (string)$argv[2];
$machineId = (int)$argv[3];
$restockerId = (int)$argv[4];
$restockerName = (string)$argv[5];
$applicationName = (string)$argv[6];
$watchApplicationName = (string)$argv[7];
$readyFile = (string)$argv[8];
$startFile = (string)$argv[9];
$resultFile = (string)$argv[10];

if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'schema inválido']));
    exit(2);
}

if (!in_array($mode, ['register', 'hold_and_register'], true)) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'modo inválido']));
    exit(2);
}

$payload = [];
if ($argc >= 12) {
    try {
        $decoded = json_decode((string)$argv[11], true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($decoded) ? $decoded : [];
    } catch (Throwable $exception) {
        file_put_contents(
            $resultFile,
            json_encode(['ok' => false, 'fatal' => 'payload inválido: ' . $exception->getMessage()])
        );
        exit(2);
    }
}

$writeResult = static function (array $result) use ($resultFile, $mode): never {
    file_put_contents($resultFile, json_encode(
        ['mode' => $mode] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    exit(isset($result['fatal']) ? 1 : 0);
};

$dsn = getenv('PG_TEST_DSN');
if ($dsn === false || trim($dsn) === '') {
    $writeResult(['ok' => false, 'fatal' => 'falta PG_TEST_DSN']);
}

$pdo = null;

try {
    $pdo = new PDO((string)$dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET search_path TO ' . $schema . ', public');
    $pdo->exec('SET application_name TO ' . $pdo->quote($applicationName));

    $service = new MachineCashCollectionService(
        new PdoMachineCashCollectionRepository($pdo),
        new PdoMachineCashCollectionGateway($pdo),
        new PdoRefundRequestRepository($pdo),
        new PdoTransactionManager($pdo),
        new AuditLogger(new PdoAuditLogRepository($pdo))
    );

    $actor = [
        'id' => $restockerId,
        'role' => 'RESTOCKER',
        'name' => $restockerName,
    ];

    if ($mode === 'register') {
        file_put_contents($readyFile, 'ready');

        $startDeadline = microtime(true) + 10.0;
        while (!is_file($startFile) && microtime(true) < $startDeadline) {
            usleep(20_000);
        }

        if (!is_file($startFile)) {
            $writeResult(['ok' => false, 'fatal' => 'el padre no liberó la barrera de salida']);
        }

        $created = $service->registerCollection($machineId, $payload, $actor);

        $writeResult([
            'ok' => true,
            'id' => (int)($created['id'] ?? 0),
            'status' => (string)($created['status'] ?? ''),
            'bag_seal_code' => (string)($created['bag_seal_code'] ?? ''),
            'idempotent_replay' => (bool)($created['idempotent_replay'] ?? false),
        ]);
    }

    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        'SELECT id FROM machines WHERE id = :id AND is_active = TRUE AND deleted_at IS NULL FOR UPDATE'
    );
    $lock->execute([':id' => $machineId]);
    if ($lock->fetchColumn() === false) {
        throw new RuntimeException("La máquina {$machineId} no existe o está inactiva para el actor.");
    }

    file_put_contents($readyFile, 'locked');

    $observedWaiter = false;
    $waitDeadline = microtime(true) + 8.0;
    while (microtime(true) < $waitDeadline) {
        $waitingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pg_stat_activity
              WHERE application_name = :application_name
                AND wait_event_type = 'Lock'"
        );
        $waitingStmt->execute([':application_name' => $watchApplicationName]);
        if ((int)$waitingStmt->fetchColumn() > 0) {
            $observedWaiter = true;
            break;
        }
        usleep(20_000);
    }

    $created = $service->registerCollection($machineId, $payload, $actor);
    $pdo->commit();

    $writeResult([
        'ok' => true,
        'observed_waiter' => $observedWaiter,
        'id' => (int)($created['id'] ?? 0),
        'status' => (string)($created['status'] ?? ''),
        'sales_counter_reading' => (float)($created['sales_counter_reading'] ?? 0.0),
    ]);
} catch (MachineCashCollectionValidationException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $writeResult([
        'ok' => false,
        'code' => $exception->getErrorCode(),
        'http' => $exception->getHttpStatusCode(),
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $writeResult([
        'ok' => false,
        'fatal' => $exception::class . ': ' . $exception->getMessage(),
    ]);
}
