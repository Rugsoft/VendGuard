<?php

declare(strict_types=1);

/**
 * OfflineSyncMutationConcurrencyActor · actor externo de la prueba de
 * concurrencia de la reserva de idempotencia (Módulo 24, RF-04.2 / H3).
 *
 * Se ejecuta en un proceso aparte para que la reserva de la clave de
 * idempotencia tenga un dueño real con el que competir. El actor:
 *
 *   1. Abre su propia conexión PDO con `PG_TEST_DSN` y fija el `search_path` al
 *      esquema aislado de la suite.
 *   2. Abre transacción y reserva la clave con `reserveAppliedMutation()`: deja
 *      la fila provisional insertada pero SIN confirmar.
 *   3. Avisa al test de que la clave ya está tomada.
 *   4. Espera a que la petición del test aparezca bloqueada en
 *      `pg_stat_activity` (`wait_event_type = 'Lock'`): es la prueba de que el
 *      índice único `uq_offline_sync_tech_mutation` la detuvo de verdad y no de
 *      que llegó tarde.
 *   5. Confirma la reserva con su cuerpo definitivo y hace `COMMIT`.
 *
 * El test comprueba entonces que la petición detenida despierta como repetición
 * idempotente y que su transición de dominio nunca llegó a ejecutarse.
 *
 * Uso interno (lo invoca `tests/integration/OfflineSyncMutationConcurrencyTest.php`):
 *   php OfflineSyncMutationConcurrencyActor.php <schema> <technicianId> <stopId> \
 *       <clientMutationId> <syncedAt> <parentApplicationName> <readyFile> <statusFile>
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Infrastructure\Repository\PdoOfflineSyncMutationRepository;

if ($argc < 9) {
    fwrite(
        STDERR,
        "Uso: php OfflineSyncMutationConcurrencyActor.php <schema> <technicianId> <stopId> "
        . "<clientMutationId> <syncedAt> <parentApplicationName> <readyFile> <statusFile>\n"
    );
    exit(2);
}

$schema = (string)$argv[1];
$technicianId = (int)$argv[2];
$stopId = (int)$argv[3];
$clientMutationId = (string)$argv[4];
$syncedAt = (string)$argv[5];
$parentApplicationName = (string)$argv[6];
$readyFile = (string)$argv[7];
$statusFile = (string)$argv[8];

$writeStatus = static function (string $status) use ($statusFile): void {
    file_put_contents($statusFile, $status);
};

/**
 * Reserva con el cuerpo vacío y cuerpo definitivo comparten la misma identidad:
 * la clave de idempotencia y los sellos temporales no cambian al confirmar.
 *
 * @param array<string, mixed> $payload
 */
$buildMutation = static function (array $payload) use ($technicianId, $stopId, $clientMutationId, $syncedAt): OfflineSyncMutation {
    return new OfflineSyncMutation(
        id: OfflineSyncMutation::UNPERSISTED_ID,
        technicianId: $technicianId,
        clientMutationId: $clientMutationId,
        stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
        stopId: $stopId,
        operationType: OfflineSyncMutation::OPERATION_START_INCIDENT,
        outcomeStatus: OfflineSyncMutation::OUTCOME_APPLIED,
        clientOccurredAt: $syncedAt,
        chainStartedAt: null,
        effectiveOccurredAt: $syncedAt,
        syncedAt: $syncedAt,
        clockSkewAdjusted: false,
        sanitarySealConfirmed: false,
        responseHttpStatus: 200,
        responsePayload: $payload,
        createdAt: $syncedAt
    );
};

$dsn = getenv('PG_TEST_DSN');
if ($dsn === false || trim($dsn) === '') {
    $writeStatus('error: falta PG_TEST_DSN');
    fwrite(STDERR, "Falta PG_TEST_DSN.\n");
    exit(2);
}

$pdo = null;

try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $pdo->exec('SET search_path TO ' . $schema . ', public');

    $repository = new PdoOfflineSyncMutationRepository($pdo);

    $pdo->beginTransaction();
    $claim = $repository->reserveAppliedMutation($buildMutation([]));

    if (!$claim['reserved']) {
        $pdo->rollBack();
        $writeStatus('error:clave-ya-tomada');
        exit(3);
    }

    file_put_contents($readyFile, 'reserved');

    // La petición competidora debe quedar esperando en el índice único. Si no
    // aparece en cinco segundos, el actor confirma igualmente y deja la señal
    // `timeout` para que la prueba falle por la causa correcta.
    $deadline = microtime(true) + 5.0;
    $observedWaiter = false;
    while (microtime(true) < $deadline) {
        $waitingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pg_stat_activity
              WHERE application_name = :application_name
                AND wait_event_type = 'Lock'"
        );
        $waitingStmt->execute([':application_name' => $parentApplicationName]);
        if ((int)$waitingStmt->fetchColumn() > 0) {
            $observedWaiter = true;
            break;
        }

        usleep(20_000);
    }

    $completed = $repository->completeReservedMutation(
        $buildMutation(['source' => 'actor', 'internal_comment_id' => 4242])
    );

    $pdo->commit();
    $writeStatus(($observedWaiter ? 'observed' : 'timeout') . ':committed:' . $completed->id);
    exit(0);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $writeStatus('error:' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
