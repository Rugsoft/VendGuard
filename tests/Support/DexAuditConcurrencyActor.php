<?php

declare(strict_types=1);

/**
 * DexAuditConcurrencyActor · T-DEX-10
 *
 * Proceso externo que compite con otra conexión real por el alta de un volcado
 * `.dex` en `MachineDexAuditService`. Se ejecuta con `proc_open` para que cada
 * actor tenga su propio PDO, su propia transacción y su propio `application_name`
 * visible en `pg_stat_activity`.
 *
 * Modos:
 *   - `import`: espera la barrera de salida y llama al servicio real.
 *
 * Uso interno (lo invoca `tests/integration/DexAuditConcurrencyTest.php`):
 *   php tests/Support/DexAuditConcurrencyActor.php \
 *     <schema> <machineId> <actorId> <actorName> <source> \
 *     <applicationName> <readyFile> <startFile> <resultFile> <payloadJson>
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\MachineDexAuditService;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditGateway;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;

if ($argc < 11) {
    fwrite(
        STDERR,
        "Uso: php DexAuditConcurrencyActor.php <schema> <machineId> <actorId> <actorName> "
        . "<source> <applicationName> <readyFile> <startFile> <resultFile> <payloadJson>\n"
    );
    exit(2);
}

$schema = (string)$argv[1];
$machineId = (int)$argv[2];
$actorId = (int)$argv[3];
$actorName = (string)$argv[4];
$source = (string)$argv[5];
$applicationName = (string)$argv[6];
$readyFile = (string)$argv[7];
$startFile = (string)$argv[8];
$resultFile = (string)$argv[9];

if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'schema inválido']));
    exit(2);
}

$payload = [];
try {
    $decoded = json_decode((string)$argv[10], true, 512, JSON_THROW_ON_ERROR);
    $payload = is_array($decoded) ? $decoded : [];
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'fatal' => 'payload inválido: ' . $exception->getMessage()]));
    exit(2);
}

$writeResult = static function (array $result) use ($resultFile): never {
    file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

    $service = new MachineDexAuditService(
        new PdoMachineDexAuditRepository($pdo),
        new PdoMachineDexAuditGateway($pdo),
        new EvaDtsParser(),
        new DexSelectionMatcher(),
        new DexAuditValidator(),
        new PdoNotificationOutboxRepository($pdo)
    );

    file_put_contents($readyFile, 'ready');

    $startDeadline = microtime(true) + 10.0;
    while (!is_file($startFile) && microtime(true) < $startDeadline) {
        usleep(20_000);
    }
    if (!is_file($startFile)) {
        $writeResult(['ok' => false, 'fatal' => 'el padre no liberó la barrera de salida']);
    }

    $result = $service->importDex([
        'machine_id' => $machineId,
        'actor_id' => $actorId,
        'import_source' => $source,
        'raw_payload' => (string)($payload['raw_payload'] ?? ''),
        'original_filename' => (string)($payload['original_filename'] ?? 'concurrencia.dex'),
        'captured_at' => (string)($payload['captured_at'] ?? ''),
        'client_mutation_id' => (string)($payload['client_mutation_id'] ?? ''),
        'expected_machine_code' => (string)($payload['expected_machine_code'] ?? ''),
    ]);

    $audit = $result['audit'] ?? [];
    $writeResult([
        'ok' => true,
        'id' => (int)($audit['id'] ?? 0),
        'audit_code' => (string)($audit['audit_code'] ?? ''),
        'sha256_hash' => (string)($audit['sha256_hash'] ?? ''),
        'idempotent_replay' => (bool)($result['idempotent_replay'] ?? false),
        'delta_source' => (string)($audit['delta_source'] ?? ''),
        'previous_audit_id' => $audit['previous_audit_id'] ?? null,
        'out_of_order_sync' => (bool)($audit['out_of_order_sync'] ?? false),
    ]);
} catch (DexAuditConflictException | DexAuditValidationException | DexAuditForbiddenException $exception) {
    $writeResult([
        'ok' => false,
        'code' => $exception->getErrorCode(),
        'http' => $exception->getHttpStatusCode(),
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    $writeResult([
        'ok' => false,
        'fatal' => $exception::class . ': ' . $exception->getMessage(),
    ]);
}
