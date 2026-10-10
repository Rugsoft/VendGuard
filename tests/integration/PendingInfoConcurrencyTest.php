<?php

declare(strict_types=1);

/**
 * PendingInfoConcurrencyTest (T-PAUSE-30)
 *
 * Certifica, contra MariaDB real y con DOS CONEXIONES PDO independientes, la
 * serialización de las dos reanudaciones simultáneas del módulo 11 (caso límite §6.2 del
 * análisis funcional, RF-02.1, RF-02.2):
 *
 *   - `IncidentPauseService::resumePendingInfoManually()` — la reanudación manual del
 *     técnico o del coordinador.
 *   - `IncidentPauseService::handleSiteCommentReactivation()` — la reactivación
 *     automática disparada por el comentario público de la sede.
 *
 * Qué prueba cada capa (declarado para no vender más de lo medido):
 *   1. **El bloqueo existe y muerde.** Con una conexión sosteniendo la fila del expediente,
 *      la operación de la segunda conexión espera y termina en `Lock wait timeout exceeded`
 *      (`ER_LOCK_WAIT_TIMEOUT`, 1205) sin haber escrito nada. Sin bloqueo por fila esa espera
 *      no puede ocurrir.
 *   2. **El bloqueo es de una fila, no del parque.** Mientras el expediente reservado está
 *      bloqueado, otra conexión bloquea y escribe sobre OTRO expediente sin esperar.
 *   3. **Sobrevive UNA sola transición y UN solo rastro.** Tras confirmar la operación
 *      ganadora, el reintento de la perdedora se resuelve con la verdad del expediente
 *      —excepción de transición inválida o estado real— sin duplicar la fila inmutable de
 *      `incident_history` ni volver a desplazar el vencimiento contractual.
 *   4. **La guarda de transacción es real.** `PdoIncidentRepository::findByIdForUpdate()`
 *      rechaza la lectura bloqueada fuera de una transacción en lugar de fingirla.
 *
 * Límite declarado: un proceso PHP es monohilo, así que no se puede intercalar el commit de
 * la ganadora MIENTRAS la perdedora espera; la carrera se orquesta reservando la fila con la
 * conexión ganadora y lanzando a la perdedora contra ella. La lectura posterior al bloqueo
 * —la que hace que la segunda operación vea el resultado de la primera— se certifica en la
 * suite unitaria con un doble que declara el puerto y registra dentro de qué ventana
 * transaccional se pidió.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias ni red externa.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - PendingInfoConcurrencyTest (T-PAUSE-30)\n";
echo " Dos conexiones PDO reales · carrera simultánea de reanudaciones\n";
echo "======================================================================\n\n";

// ─── Grupo 0: banco de pruebas con dos conexiones independientes ────────────────

$pdoA = ConnectionFactory::getConnection();
$pdoB = ConnectionFactory::getConnection([], true);

$assertions = 0;
$failures   = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/** Ejecuta la acción y devuelve la excepción lanzada (o `null` si no hubo). */
$captureThrowable = static function (callable $action): ?Throwable {
    try {
        $action();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
};

$assert(
    '0.1 Dos conexiones PDO REALES e independientes sobre la misma base de datos',
    $pdoA !== $pdoB
    && $pdoA->query('SELECT DATABASE()')->fetchColumn() === $pdoB->query('SELECT DATABASE()')->fetchColumn(),
    'las conexiones del banco de pruebas no son independientes'
);

TestDataCleaner::purge($pdoA);
(new SeedRunner($pdoA))->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdoA);
$locationRepo = new PdoLocationRepository($pdoA);
$userRepo     = new PdoUserRepository($pdoA);
$authService  = new AuthService($locationRepo, $userRepo);

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technicianRow = $pdoA->query(
    "SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 1"
)->fetchColumn();
$technician = $technicianRow !== false ? $userRepo->findById((int)$technicianRow) : null;

$assert(
    '0.2 Semilla disponible: coordinador y técnico de campo',
    $coordinator instanceof User && $technician instanceof User,
    'faltan usuarios sembrados para asignar los expedientes'
);

if (!$coordinator instanceof User || !$technician instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorId     = (int)$coordinator->getId();
$technicianId      = (int)$technician->getId();
$technicianToken   = $authService->generateInternalToken($technician);
$technicianHeaders = ['Authorization' => 'Bearer ' . $technicianToken];

$machinePool = $pdoA->query(
    "SELECT m.`id` AS machine_id, m.`location_id` AS location_id
       FROM `machines` m
      WHERE m.`is_active` = 1
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.`id`"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.3 Flota sembrada suficiente para aislar cada expediente del banco de pruebas',
    count($machinePool) >= 4,
    sprintf('máquinas libres disponibles: %d', count($machinePool))
);

if (count($machinePool) < 4) {
    echo "ERROR FATAL: flota sembrada insuficiente.\n";
    exit(1);
}

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;
$purgeIds = [];

/** Estrena máquina libre y crea un expediente asignado al técnico del banco de pruebas. */
$createAssignedTicket = function () use (
    &$machinePool,
    &$sequence,
    &$purgeIds,
    $incidentRepo,
    $technicianId,
    $coordinatorId,
    $suffix
): int {
    $machine = array_shift($machinePool);
    $sequence++;

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-P30-%s-%02d', $suffix, $sequence),
        machineId: (int)$machine['machine_id'],
        locationId: (int)$machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Expediente del banco de pruebas de concurrencia del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $purgeIds[] = $incidentId;
    $incidentRepo->assign($incidentId, $technicianId, $coordinatorId);

    return $incidentId;
};

/** Declara la pausa por bloqueo de sede por la RUTA REAL (router + middlewares). */
$pauseThroughRoute = static function (int $incidentId) use ($router, $technicianHeaders): int {
    $response = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/technician/incidents/{$incidentId}/pause-pending-info",
        parsedBody: [
            'reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
            'reason_text'     => 'El edificio permanece cerrado sin acceso a la sala de máquinas.',
        ],
        headers: $technicianHeaders
    ));

    return $response->getStatusCode();
};

/** Fila cruda del expediente, leída por la conexión canónica. */
$incidentRow = static function (int $incidentId) use ($pdoA): array {
    $stmt = $pdoA->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

/** Rastro inmutable posterior a un cursor: sólo lo que la operación bajo prueba escribió. */
$resumeTraceCount = static function (int $incidentId, int $cursor) use ($pdoA): int {
    $stmt = $pdoA->prepare(
        "SELECT COUNT(*) FROM `incident_history`
          WHERE `incident_id` = :id
            AND `id` > :cursor
            AND `from_status` = 'PENDING_INFO'"
    );
    $stmt->execute([':id' => $incidentId, ':cursor' => $cursor]);

    return (int)$stmt->fetchColumn();
};

$historyCursor = static function (int $incidentId) use ($pdoA): int {
    $stmt = $pdoA->prepare('SELECT COALESCE(MAX(`id`), 0) FROM `incident_history` WHERE `incident_id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

/**
 * Servicio de reanudación cableado SOBRE UNA CONEXIÓN CONCRETA. Es la pieza que permite
 * hacer convivir dos "peticiones" simultáneas en un proceso monohilo: la ganadora trabaja
 * sobre la conexión canónica y la perdedora sobre la segunda conexión real.
 */
$serviceOn = static function (PDO $pdo): IncidentPauseService {
    return new IncidentPauseService(
        incidentRepo: new PdoIncidentRepository($pdo),
        transactionManager: new PdoTransactionManager($pdo)
    );
};

$serviceA = $serviceOn($pdoA);
$serviceB = $serviceOn($pdoB);

$manualIncidentId    = $createAssignedTicket();
$commentIncidentId   = $createAssignedTicket();
$dirtyReadIncidentId = $createAssignedTicket();
$bystanderIncidentId = $createAssignedTicket();

$assert(
    '0.4 Los tres expedientes del banco arrancan asignados al técnico de ruta',
    (int)($incidentRow($manualIncidentId)['assigned_technician_id'] ?? 0) === $technicianId
    && (int)($incidentRow($commentIncidentId)['assigned_technician_id'] ?? 0) === $technicianId
);

$assert(
    '0.5 Las tres pausas del banco se declaran por la ruta real',
    $pauseThroughRoute($manualIncidentId) === 200
    && $pauseThroughRoute($commentIncidentId) === 200
    && $pauseThroughRoute($dirtyReadIncidentId) === 200,
    'la ruta de pausa no devolvió 200 para los expedientes del banco'
);

$assert(
    '0.6 Los expedientes quedan en PENDING_INFO con su pausa abierta',
    ($incidentRow($manualIncidentId)['status'] ?? '') === 'PENDING_INFO'
    && ($incidentRow($commentIncidentId)['status'] ?? '') === 'PENDING_INFO'
    && ($incidentRow($dirtyReadIncidentId)['status'] ?? '') === 'PENDING_INFO'
    && ($incidentRow($manualIncidentId)['paused_at'] ?? null) !== null
    && ($incidentRow($commentIncidentId)['paused_at'] ?? null) !== null
    && ($incidentRow($dirtyReadIncidentId)['paused_at'] ?? null) !== null
);

// La espera se siembra con una duración conocida (3 minutos) para que el desplazamiento
// contractual posterior sea verificable al segundo y no dependa de lo que tarde la suite.
$pdoA->exec(sprintf(
    "UPDATE `incidents`
        SET `paused_at` = NOW() - INTERVAL 180 SECOND,
            `total_pending_info_seconds` = 0,
            `sla_target_at` = '2026-10-13 15:00:00'
      WHERE `id` IN (%d, %d)",
    $manualIncidentId,
    $commentIncidentId
));

// La conexión perdedora falla rápido en lugar de quedarse 50 segundos esperando la fila.
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');

// =========================================================================
// GRUPO 1: Carrera de la reanudación manual (RF-02.2)
// =========================================================================
echo "\n--- Grupo 1: Dos reanudaciones manuales simultáneas sobre el mismo expediente ---\n";

$manualCursor = $historyCursor($manualIncidentId);

// La conexión A se convierte en la operación "en vuelo": toma el bloqueo exclusivo por
// fila del expediente y lo mantiene dentro de su transacción.
$pdoA->beginTransaction();
$lockStmt = $pdoA->prepare('SELECT `id` FROM `incidents` WHERE `id` = :id FOR UPDATE');
$lockStmt->execute([':id' => $manualIncidentId]);

$loserException = $captureThrowable(static fn () => $serviceB->resumePendingInfoManually(
    $manualIncidentId,
    $technicianId,
    IncidentStatus::IN_PROGRESS,
    'Reanudación de la petición perdedora.'
));

$lockWaitCode = $loserException instanceof PDOException ? (int)($loserException->errorInfo[1] ?? 0) : 0;

$assert(
    '1.1 La segunda reanudación ESPERA al bloqueo por fila de la primera (1205 Lock wait timeout)',
    $loserException instanceof PDOException
    && ($lockWaitCode === 1205 || str_contains($loserException->getMessage(), 'Lock wait timeout')),
    $loserException === null
        ? 'la perdedora no esperó: no hay bloqueo por fila que la frene'
        : $loserException::class . ': ' . $loserException->getMessage()
);

$assert(
    '1.2 La petición perdedora no escribe nada mientras espera',
    $resumeTraceCount($manualIncidentId, $manualCursor) === 0
    && (int)($incidentRow($manualIncidentId)['total_pending_info_seconds'] ?? -1) === 0,
    'la perdedora dejó rastro antes de que la ganadora confirmara'
);

// La operación ganadora se ejecuta sobre la MISMA conexión que sostiene el bloqueo: el
// servicio participa en esa transacción (no la confirma) porque su dueño es quien la abrió.
$winnerDto = $serviceA->resumePendingInfoManually(
    $manualIncidentId,
    $technicianId,
    IncidentStatus::IN_PROGRESS,
    'Reanudación de la petición ganadora.'
);

$assert(
    '1.3 La operación ganadora entra en la transacción viva y no la confirma por su cuenta',
    $pdoA->inTransaction() && $winnerDto->status === IncidentStatus::IN_PROGRESS,
    'el servicio confirmó una transacción ajena'
);

$pdoA->commit();

$manualRow = $incidentRow($manualIncidentId);

$assert(
    '1.4 Confirmada la ganadora, el expediente queda reanudado una sola vez',
    ($manualRow['status'] ?? '') === 'IN_PROGRESS'
    && ($manualRow['paused_at'] ?? null) === null
    && $resumeTraceCount($manualIncidentId, $manualCursor) === 1,
    sprintf(
        'estado=%s, rastro nuevo=%d',
        (string)($manualRow['status'] ?? 'AUSENTE'),
        $resumeTraceCount($manualIncidentId, $manualCursor)
    )
);

// La espera se sembró con 3 minutos exactos, pero el intervalo sigue vivo mientras la
// suite orquesta la carrera, así que la duración acumulada es 180 s más lo que tarde la
// operación. Lo que se certifica no es una cifra mágica, sino que el vencimiento se
// desplaza EXACTAMENTE lo que dura la pausa y UNA sola vez (una segunda transición
// habría desplazado el doble).
$manualAccumulated = (int)($manualRow['total_pending_info_seconds'] ?? 0);
$manualShifted     = strtotime((string)($manualRow['sla_target_at'] ?? '')) - strtotime('2026-10-13 15:00:00');

$assert(
    '1.5 La espera se descuenta del reloj contractual exactamente una vez (RF-03.1, RF-03.3)',
    $manualAccumulated >= 180 && $manualAccumulated <= 195
    && $manualShifted === $manualAccumulated,
    sprintf('acumulado=%d s, desplazamiento=%d s', $manualAccumulated, $manualShifted)
);

$loserRetry = $captureThrowable(static fn () => $serviceB->resumePendingInfoManually(
    $manualIncidentId,
    $technicianId,
    IncidentStatus::IN_PROGRESS,
    'Reintento de la petición perdedora.'
));

$assert(
    '1.6 El reintento de la perdedora se resuelve con la verdad: no hay pausa que cerrar',
    $loserRetry instanceof VendGuard\Core\Domain\Exception\InvalidTransitionException,
    $loserRetry === null ? 'la perdedora volvió a reanudar una pausa ya cerrada' : $loserRetry::class
);

$finalRow      = $incidentRow($manualIncidentId);
$finalAccumulated = (int)($finalRow['total_pending_info_seconds'] ?? 0);
$finalShifted     = strtotime((string)($finalRow['sla_target_at'] ?? '')) - strtotime('2026-10-13 15:00:00');

$assert(
    '1.7 Tras la carrera sobrevive UNA sola transición y UN solo rastro inmutable',
    $resumeTraceCount($manualIncidentId, $manualCursor) === 1
    && $finalShifted === $finalAccumulated
    && $finalAccumulated === $manualAccumulated,
    sprintf(
        'rastro=%d, acumulado=%d s, desplazamiento=%d s',
        $resumeTraceCount($manualIncidentId, $manualCursor),
        $finalAccumulated,
        $finalShifted
    )
);

// =========================================================================
// GRUPO 2: Carrera de la reactivación por comentario de sede (RF-02.1)
// =========================================================================
echo "\n--- Grupo 2: Reanudación manual contra la reactivación del comentario de sede ---\n";

$commentCursor = $historyCursor($commentIncidentId);
$siteComment   = 'Buenos días, la sala ya está abierta y el conserje tiene la llave.';

$pdoA->beginTransaction();
$lockStmt = $pdoA->prepare('SELECT `id` FROM `incidents` WHERE `id` = :id FOR UPDATE');
$lockStmt->execute([':id' => $commentIncidentId]);

$commentLoser = $captureThrowable(static fn () => $serviceB->handleSiteCommentReactivation(
    $commentIncidentId,
    $siteComment,
    9001
));

$commentLockWaitCode = $commentLoser instanceof PDOException ? (int)($commentLoser->errorInfo[1] ?? 0) : 0;

$assert(
    '2.1 La reactivación del comentario también espera el bloqueo por fila de la otra petición',
    $commentLoser instanceof PDOException
    && ($commentLockWaitCode === 1205 || str_contains($commentLoser->getMessage(), 'Lock wait timeout')),
    $commentLoser === null
        ? 'la reactivación no esperó: ese camino no está serializado'
        : $commentLoser::class . ': ' . $commentLoser->getMessage()
);

$assert(
    '2.2 El comentario de la petición perdedora no deja ni rastro ni transición',
    $resumeTraceCount($commentIncidentId, $commentCursor) === 0
    && ($incidentRow($commentIncidentId)['status'] ?? '') === 'PENDING_INFO'
);

$commentWinnerDto = $serviceA->handleSiteCommentReactivation($commentIncidentId, $siteComment, 9001);
$pdoA->commit();

$commentRow = $incidentRow($commentIncidentId);

$assert(
    '2.3 La reactivación ganadora cierra la pausa en una sola transición',
    $commentWinnerDto->isSlaPaused === false
    && in_array($commentWinnerDto->status, [IncidentStatus::IN_PROGRESS, IncidentStatus::ASSIGNED], true)
    && ($commentRow['status'] ?? '') === $commentWinnerDto->status->value
    && ($commentRow['paused_at'] ?? null) === null
    && $resumeTraceCount($commentIncidentId, $commentCursor) === 1,
    sprintf(
        'estado=%s, rastro nuevo=%d',
        (string)($commentRow['status'] ?? 'AUSENTE'),
        $resumeTraceCount($commentIncidentId, $commentCursor)
    )
);

$commentLoserRetry = $serviceB->handleSiteCommentReactivation($commentIncidentId, $siteComment, 9001);

$assert(
    '2.4 El reintento de la perdedora devuelve el estado real sin repetir la transición',
    $commentLoserRetry->isSlaPaused === false
    && $commentLoserRetry->status === $commentWinnerDto->status
    && $resumeTraceCount($commentIncidentId, $commentCursor) === 1,
    $commentLoserRetry->status->value
);

// =========================================================================
// GRUPO 3: Alcance del bloqueo y guarda de transacción
// =========================================================================
echo "\n--- Grupo 3: El bloqueo es de una fila, no del parque; y exige transacción ---\n";

$pdoA->beginTransaction();
$lockStmt = $pdoA->prepare('SELECT `id` FROM `incidents` WHERE `id` = :id FOR UPDATE');
$lockStmt->execute([':id' => $commentIncidentId]);

$bystanderException = $captureThrowable(static function () use ($pdoB, $bystanderIncidentId): void {
    $pdoB->beginTransaction();
    try {
        $lock = $pdoB->prepare('SELECT `id` FROM `incidents` WHERE `id` = :id FOR UPDATE');
        $lock->execute([':id' => $bystanderIncidentId]);
        $pdoB->commit();
    } catch (Throwable $exception) {
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }

        throw $exception;
    }
});

$assert(
    '3.1 El expediente reservado no bloquea a los demás expedientes de la sede',
    $bystanderException === null,
    $bystanderException === null ? '' : $bystanderException::class . ': ' . $bystanderException->getMessage()
);

$pdoA->rollBack();

$outsideTransaction = $captureThrowable(static function () use ($pdoB, $bystanderIncidentId): void {
    (new PdoIncidentRepository($pdoB))->findByIdForUpdate($bystanderIncidentId);
});

$assert(
    '3.2 La lectura bloqueada fuera de una transacción se RECHAZA en lugar de fingirse',
    $outsideTransaction instanceof RuntimeException
    && str_contains($outsideTransaction->getMessage(), 'transacción'),
    $outsideTransaction === null
        ? 'el repositorio aceptó una lectura bloqueada sin transacción'
        : $outsideTransaction::class
);

$assert(
    '3.3 La lectura ordinaria del mismo expediente sigue disponible sin transacción',
    (new PdoIncidentRepository($pdoB))->findById($bystanderIncidentId) !== null
);

// =========================================================================
// GRUPO 4: La lectura sucia no engaña a la operación serializada
// =========================================================================
echo "\n--- Grupo 4: El bloqueo por fila manda sobre el nivel de aislamiento ---\n";

// Esta es la aserción que separa de verdad un `SELECT ... FOR UPDATE` de una lectura
// ordinaria, y por eso se ejecuta con la perdedora en READ UNCOMMITTED: si el servicio
// leyera sin bloquear, vería el estado SIN CONFIRMAR de la transacción ganadora —que ya
// está en IN_PROGRESS— y se resolvería con una excepción de transición inválida en
// lugar de esperar. Que ESPERE demuestra que la fila se bloquea ANTES de leer.
$pdoB->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

$isolationLevel = 'DESCONOCIDO';
try {
    $isolationLevel = (string)$pdoB->query('SELECT @@transaction_isolation')->fetchColumn();
} catch (PDOException) {
    $isolationLevel = (string)$pdoB->query('SELECT @@tx_isolation')->fetchColumn();
}

$assert(
    '4.1 La conexión perdedora queda en lectura sucia para la prueba',
    str_contains(strtoupper($isolationLevel), 'READ-UNCOMMITTED') || str_contains(strtoupper($isolationLevel), 'READ-UNCOMMITTED'),
    'nivel de aislamiento obtenido: ' . $isolationLevel
);

$dirtyCursor = $historyCursor($dirtyReadIncidentId);

$pdoA->beginTransaction();
$lockStmt = $pdoA->prepare('SELECT `id` FROM `incidents` WHERE `id` = :id FOR UPDATE');
$lockStmt->execute([':id' => $dirtyReadIncidentId]);

// La ganadora completa su transición SIN confirmar: su estado ya es IN_PROGRESS dentro de
// su transacción, invisible para cualquier lector que respete los bloqueos.
$dirtyWinnerDto = $serviceA->resumePendingInfoManually(
    $dirtyReadIncidentId,
    $technicianId,
    IncidentStatus::IN_PROGRESS,
    'Reanudación ganadora que todavía no ha confirmado.'
);

$dirtyLoser = $captureThrowable(static fn () => $serviceB->resumePendingInfoManually(
    $dirtyReadIncidentId,
    $technicianId,
    IncidentStatus::IN_PROGRESS,
    'Reanudación perdedora con lectura sucia.'
));

$dirtyLoserCode = $dirtyLoser instanceof PDOException ? (int)($dirtyLoser->errorInfo[1] ?? 0) : 0;

$assert(
    '4.2 Aun leyendo en sucio, la perdedora ESPERA el bloqueo en lugar de decidir sobre el estado sin confirmar',
    $dirtyLoser instanceof PDOException
    && ($dirtyLoserCode === 1205 || str_contains($dirtyLoser->getMessage(), 'Lock wait timeout')),
    $dirtyLoser === null
        ? 'la perdedora decidió sin esperar: leyó el estado sin confirmar de la ganadora'
        : $dirtyLoser::class . ': ' . $dirtyLoser->getMessage()
);

$pdoA->commit();

$assert(
    '4.3 Confirmada la ganadora, el reintento se resuelve sobre el estado real y no duplica el rastro',
    $captureThrowable(static fn () => $serviceB->resumePendingInfoManually(
        $dirtyReadIncidentId,
        $technicianId,
        IncidentStatus::IN_PROGRESS,
        'Reintento tras la confirmación.'
    )) instanceof VendGuard\Core\Domain\Exception\InvalidTransitionException
    && $dirtyWinnerDto->status === IncidentStatus::IN_PROGRESS
    && $resumeTraceCount($dirtyReadIncidentId, $dirtyCursor) === 1,
    sprintf('rastro nuevo=%d', $resumeTraceCount($dirtyReadIncidentId, $dirtyCursor))
);

$pdoB->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

// =========================================================================
// Limpieza del banco de pruebas
// =========================================================================
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 50');

if ($pdoA->inTransaction()) {
    $pdoA->rollBack();
}

if ($pdoB->inTransaction()) {
    $pdoB->rollBack();
}

foreach ($purgeIds as $purgeId) {
    TestDataCleaner::purgeIncident($pdoA, $purgeId);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-30 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
