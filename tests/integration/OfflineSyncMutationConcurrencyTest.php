<?php

declare(strict_types=1);

/**
 * OfflineSyncMutationConcurrencyTest · Módulo 24, RF-04.2 / H3
 *
 * Certifica con DOS CONEXIONES REALES que la reserva de idempotencia y la
 * transición de dominio viven en la misma unidad atómica, y que la clave única
 * `uq_offline_sync_tech_mutation` decide el dueño ANTES de ejecutar el dominio:
 *
 *   1. Un proceso actor abre transacción y reserva la clave: la fila está
 *      insertada pero sin confirmar.
 *   2. El servicio real —con su propia transacción— intenta la misma reserva y
 *      queda esperando en el índice único; el actor lo observa en
 *      `pg_stat_activity.wait_event_type = 'Lock'` antes de confirmar, así que
 *      la prueba distingue un bloqueo real de una llegada tardía.
 *   3. El actor confirma y suelta el cerrojo.
 *   4. La petición detenida despierta como repetición idempotente: recibe el
 *      cuerpo del ganador y NUNCA ejecuta su transición de dominio.
 *   5. Queda exactamente una fila en `offline_sync_mutations`.
 *
 * El segundo escenario cierra la otra mitad de H3: un veredicto negativo del
 * dominio deshace la reserva en lugar de quemar la clave, de modo que el
 * reintento posterior sí aplica y deja una sola fila.
 *
 * Antes de la corrección, la reserva se escribía al final y fuera de la
 * transacción del dominio: entre la comprobación previa y el `INSERT` cabía una
 * segunda aplicación, y la única guarda era el estado de la parada.
 *
 * CATEGORÍA: `new_engine` (requiere PostgreSQL real; no se omite en silencio).
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/OfflineSyncMutationConcurrencyTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

use VendGuard\Application\Service\OfflineSyncService;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\ValueObject\OfflineSyncMetadata;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoOfflineSyncMutationRepository;

/** Marca de categoría: `run_all.php` la lee para no confundirla con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

const OFFLINE_CONCURRENCY_SCHEMA = 't_offline24_concurrency';
const OFFLINE_CONCURRENCY_PARENT_APP = 'vendguard-offline-concurrency-parent';
const OFFLINE_CONCURRENCY_RACE_KEY = 'mut_conc_race_001';
const OFFLINE_CONCURRENCY_RELEASE_KEY = 'mut_conc_release_001';

$projectRoot = dirname(__DIR__, 2);
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
echo " VENDGUARD · T-OFFLINE-10 · Concurrencia real de la reserva de idempotencia\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
// El actor identifica esta sesión por `application_name`, no por el PID del
// proceso: `pg_stat_activity.pid` es el backend del servidor, no el cliente.
$pgPdo->exec("SET application_name TO '" . OFFLINE_CONCURRENCY_PARENT_APP . "'");

$readyFile = '';
$statusFile = '';
$process = null;
$pipes = [];
$crash = null;

/** Marca de la mutación de la prueba, en el formato canónico de los sellos. */
$syncedAt = '2026-10-29T09:00:00+00:00';

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo, OFFLINE_CONCURRENCY_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $insertUser = $pgPdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, 'hash123', 'TECHNICIAN', TRUE)
         RETURNING id"
    );
    $insertUser->execute([
        ':name' => 'Técnico Concurrencia Offline',
        ':email' => 'tech.concurrencia.offline@vending.test',
        ':email_key' => 'tech.concurrencia.offline@vending.test',
    ]);
    $technicianId = (int)$insertUser->fetchColumn();

    $repository = new PdoOfflineSyncMutationRepository($pgPdo);
    $service = new OfflineSyncService(
        $repository,
        new PdoTransactionManager($pgPdo)
    );

    $countRowsForKey = static function (string $clientMutationId) use ($pgPdo, $technicianId): int {
        $stmt = $pgPdo->prepare(
            'SELECT COUNT(*) FROM offline_sync_mutations
              WHERE technician_id = :technician_id
                AND client_mutation_id = :client_mutation_id'
        );
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':client_mutation_id' => $clientMutationId,
        ]);

        return (int)$stmt->fetchColumn();
    };

    // -----------------------------------------------------------------
    // 1. Carrera real por la misma clave
    // -----------------------------------------------------------------
    echo "\n--- 1. Dos peticiones simultáneas con la misma clave (RF-04.2, H3) ---\n";

    $readyFile = (string)tempnam(sys_get_temp_dir(), 'vendguard_offline_ready_');
    $statusFile = (string)tempnam(sys_get_temp_dir(), 'vendguard_offline_status_');
    file_put_contents($readyFile, '');

    $process = proc_open(
        [
            PHP_BINARY,
            __DIR__ . '/../Support/OfflineSyncMutationConcurrencyActor.php',
            OFFLINE_CONCURRENCY_SCHEMA,
            (string)$technicianId,
            '4242',
            OFFLINE_CONCURRENCY_RACE_KEY,
            $syncedAt,
            OFFLINE_CONCURRENCY_PARENT_APP,
            $readyFile,
            $statusFile,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo abrir el proceso actor de concurrencia.');
    }

    fclose($pipes[0]);

    $readyDeadline = microtime(true) + 5.0;
    while (trim((string)file_get_contents($readyFile)) !== 'reserved' && microtime(true) < $readyDeadline) {
        usleep(20_000);
    }

    if (trim((string)file_get_contents($readyFile)) !== 'reserved') {
        throw new RuntimeException('El actor concurrente no llegó a reservar la clave de idempotencia.');
    }

    $transitionCalls = 0;
    $raceResult = null;
    $raceError = null;

    try {
        $raceResult = $service->executeIdempotentMutation(
            technicianId: $technicianId,
            stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
            stopId: 4242,
            operationType: OfflineSyncMutation::OPERATION_START_INCIDENT,
            metadata: OfflineSyncMetadata::fromArray([
                'client_mutation_id' => OFFLINE_CONCURRENCY_RACE_KEY,
                'client_occurred_at' => $syncedAt,
            ]),
            stopFacts: ['machine_is_perishable' => false],
            applyTransition: static function (array $context) use (&$transitionCalls): array {
                $transitionCalls++;

                return ['source' => 'parent', 'applied' => true];
            }
        );
    } catch (Throwable $exception) {
        $raceError = $exception;
    }

    $waitDeadline = microtime(true) + 6.0;
    $processStatus = proc_get_status($process);
    while ($processStatus['running'] && microtime(true) < $waitDeadline) {
        usleep(20_000);
        $processStatus = proc_get_status($process);
    }

    if ($processStatus['running']) {
        proc_terminate($process);
    }

    $childExitCode = (int)$processStatus['exitcode'];
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    $process = null;

    $actorStatus = is_file($statusFile) ? trim((string)file_get_contents($statusFile)) : '';

    $assert(
        'El actor observó la petición detenida en el índice único',
        str_starts_with($actorStatus, 'observed:'),
        'estado del actor: ' . ($actorStatus !== '' ? $actorStatus : '(sin fichero)')
    );
    $assert('El actor concurrente terminó sin error', $childExitCode === 0, 'exit=' . $childExitCode);
    $assert(
        'La petición detenida no propaga error de motor: responde como repetición',
        $raceError === null,
        $raceError !== null ? get_class($raceError) . ': ' . $raceError->getMessage() : 'sin excepción'
    );
    $assert(
        'La petición perdedora se marca idempotent_replay',
        is_array($raceResult) && ($raceResult['idempotent_replay'] ?? false) === true
    );
    $assert(
        'La petición perdedora recibe el cuerpo del ganador',
        is_array($raceResult) && ($raceResult['payload']['source'] ?? null) === 'actor',
        is_array($raceResult) ? (string)($raceResult['payload']['source'] ?? 'sin fuente') : 'sin resultado'
    );
    $assert(
        'La transición de dominio no se ejecutó dos veces',
        $transitionCalls === 0,
        'invocaciones=' . $transitionCalls
    );
    $assert(
        'La carrera deja exactamente una fila, la del actor',
        $countRowsForKey(OFFLINE_CONCURRENCY_RACE_KEY) === 1,
        'filas=' . $countRowsForKey(OFFLINE_CONCURRENCY_RACE_KEY)
    );

    $row = $pgPdo->prepare(
        'SELECT outcome_status, response_payload FROM offline_sync_mutations
          WHERE technician_id = :technician_id AND client_mutation_id = :client_mutation_id'
    );
    $row->execute([
        ':technician_id' => $technicianId,
        ':client_mutation_id' => OFFLINE_CONCURRENCY_RACE_KEY,
    ]);
    $survivor = $row->fetch(PDO::FETCH_ASSOC);
    $payload = is_array($survivor) ? json_decode((string)$survivor['response_payload'], true) : null;
    $assert(
        'La fila superviviente conserva el resultado del actor',
        is_array($survivor)
            && $survivor['outcome_status'] === OfflineSyncMutation::OUTCOME_APPLIED
            && is_array($payload)
            && ($payload['source'] ?? null) === 'actor',
        is_array($payload) ? (string)($payload['source'] ?? 'sin fuente') : 'sin fila'
    );

    // -----------------------------------------------------------------
    // 2. Un veredicto negativo no quema la clave
    // -----------------------------------------------------------------
    echo "\n--- 2. El rollback libera la reserva y el reintento aplica (H3) ---\n";

    $abortedError = null;
    try {
        $service->executeIdempotentMutation(
            technicianId: $technicianId,
            stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
            stopId: 4243,
            operationType: OfflineSyncMutation::OPERATION_START_INCIDENT,
            metadata: OfflineSyncMetadata::fromArray([
                'client_mutation_id' => OFFLINE_CONCURRENCY_RELEASE_KEY,
                'client_occurred_at' => $syncedAt,
            ]),
            stopFacts: ['machine_is_perishable' => false],
            applyTransition: static function (array $context): array {
                throw new DomainException('Veredicto negativo simulado del dominio.');
            }
        );
    } catch (Throwable $exception) {
        $abortedError = $exception;
    }

    $assert(
        'El veredicto negativo del dominio se propaga al llamador',
        $abortedError instanceof DomainException,
        $abortedError !== null ? get_class($abortedError) : 'sin excepción'
    );
    $assert(
        'La reserva se deshace con la transacción: no queda fila parcial',
        $countRowsForKey(OFFLINE_CONCURRENCY_RELEASE_KEY) === 0,
        'filas=' . $countRowsForKey(OFFLINE_CONCURRENCY_RELEASE_KEY)
    );

    $retryCalls = 0;
    $retryResult = $service->executeIdempotentMutation(
        technicianId: $technicianId,
        stopType: OfflineSyncMutation::STOP_TYPE_INCIDENT,
        stopId: 4243,
        operationType: OfflineSyncMutation::OPERATION_START_INCIDENT,
        metadata: OfflineSyncMetadata::fromArray([
            'client_mutation_id' => OFFLINE_CONCURRENCY_RELEASE_KEY,
            'client_occurred_at' => $syncedAt,
        ]),
        stopFacts: ['machine_is_perishable' => false],
        applyTransition: static function (array $context) use (&$retryCalls): array {
            $retryCalls++;

            return ['source' => 'parent', 'applied' => true];
        }
    );

    $assert(
        'El reintento tras el rollback sí aplica la transición',
        $retryResult['idempotent_replay'] === false && $retryCalls === 1,
        'replay=' . var_export($retryResult['idempotent_replay'], true) . ' invocaciones=' . $retryCalls
    );
    $assert(
        'El reintento deja una sola fila con el cuerpo definitivo',
        $countRowsForKey(OFFLINE_CONCURRENCY_RELEASE_KEY) === 1
            && ($retryResult['payload']['source'] ?? null) === 'parent',
        'filas=' . $countRowsForKey(OFFLINE_CONCURRENCY_RELEASE_KEY)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }

    foreach ([$readyFile, $statusFile] as $file) {
        if ($file !== '' && is_file($file)) {
            unlink($file);
        }
    }

    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, OFFLINE_CONCURRENCY_SCHEMA);
    } catch (Throwable) {
        // Preserva el crash original si existía.
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
