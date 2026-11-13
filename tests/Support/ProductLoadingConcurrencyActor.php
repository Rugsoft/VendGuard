<?php

declare(strict_types=1);

/**
 * ProductLoadingConcurrencyActor · actor externo de la prueba de concurrencia
 * de recepción y liquidación del Módulo 33 (B-07, §6.6, RF-04.1, RF-04.2).
 *
 * Se ejecuta en un proceso aparte para que el `SELECT ... FOR UPDATE` de la
 * orden tenga un dueño real con el que competir. El actor:
 *
 *   1. Abre su propia conexión PDO con `PG_TEST_DSN` y fija el `search_path`
 *      al esquema aislado de la suite.
 *   2. Avisa de que está listo y espera la señal de salida del padre. Así los
 *      dos actores cruzan la puerta a la vez.
 *   3. Llama al servicio real de confirmación o liquidación.
 *   4. Publica en un JSON si ganó, o el código y el estado HTTP del rechazo.
 *
 * Uso interno (lo invoca `tests/integration/ProductLoadingConcurrencyTest.php`):
 *   php ProductLoadingConcurrencyActor.php <schema> <operation> <orderId> \
 *       <restockerId> <actorId> <actorName> <applicationName> <readyFile> \
 *       <startFile> <resultFile> [payloadJson]
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

if ($argc < 11) {
    fwrite(
        STDERR,
        "Uso: php ProductLoadingConcurrencyActor.php <schema> <operation> <orderId> "
        . "<restockerId> <actorId> <actorName> <applicationName> <readyFile> "
        . "<startFile> <resultFile> [payloadJson]\n"
    );
    exit(2);
}

$schema = (string)$argv[1];
$operation = (string)$argv[2];
$orderId = (int)$argv[3];
$restockerId = (int)$argv[4];
$actorId = (int)$argv[5];
$actorName = (string)$argv[6];
$applicationName = (string)$argv[7];
$readyFile = (string)$argv[8];
$startFile = (string)$argv[9];
$resultFile = (string)$argv[10];

if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'schema inválido']));
    exit(2);
}

if (!in_array($operation, ['confirm', 'reconcile'], true)) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'operación inválida']));
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

$writeResult = static function (array $result) use ($resultFile, $operation): never {
    file_put_contents($resultFile, json_encode(
        ['operation' => $operation] + $result,
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

    $service = new ProductLoadingOrderService(
        new PdoProductLoadingOrderRepository($pdo),
        new PdoRestockerVanStockRepository($pdo),
        new PdoVendingProductRepository($pdo),
        new PdoUserRepository($pdo),
        new AuditLogger(new PdoAuditLogRepository($pdo)),
        $pdo
    );

    $actor = [
        'id' => $actorId,
        'role' => 'RESTOCKER',
        'name' => $actorName,
    ];

    file_put_contents($readyFile, 'ready');

    $startDeadline = microtime(true) + 10.0;
    while (!is_file($startFile) && microtime(true) < $startDeadline) {
        usleep(20_000);
    }

    if (!is_file($startFile)) {
        $writeResult(['ok' => false, 'fatal' => 'el padre no liberó la barrera de salida']);
    }

    $result = $operation === 'confirm'
        ? $service->confirmOrder($orderId, $restockerId, $actor)
        : $service->reconcileOrder($orderId, $payload, $actor, $restockerId);

    $writeResult([
        'ok' => true,
        'status' => (string)($result['order']['status'] ?? ''),
    ]);
} catch (ProductLoadingValidationException $exception) {
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
        'fatal' => get_class($exception) . ': ' . $exception->getMessage(),
    ]);
}
