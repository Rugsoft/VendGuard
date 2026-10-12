<?php

declare(strict_types=1);

/**
 * SanitaryClockConsumerRaceTest (T-PAUSE-38)
 *
 * Certifica, contra MariaDB real y con DOS CONEXIONES PDO independientes, que los DOS
 * consumidores del Reloj Sanitario Biológico no pueden duplicar la cuarentena de la misma
 * máquina (RF-03.5.1, RF-03.5.2, Constitución Art. II y Art. III):
 *
 *   - **Barrido de fondo** (`SanitaryClockSweepService`) por su RUTA REAL: el enrutador,
 *     sus middlewares y `POST /api/cron/sanitary-clock-sweep` con la credencial de máquina.
 *   - **Cierre técnico** (`TechnicianController::resolveIncident()`): el mismo servicio que
 *     la puerta sanitaria del cierre consume antes de exigir las declaraciones, cableado
 *     sobre una SEGUNDA conexión real. El comportamiento a nivel de ruta está certificado
 *     por `PendingInfoConstitutionalTest` y `SanitaryClockSweepEndpointTest`; aquí lo que se
 *     certifica es lo que ninguna de las dos puede ver sola: la carrera entre ambos.
 *
 * Qué prueba cada grupo (declarado para no vender más de lo medido):
 *   1. **El barrido gana la carrera.** El cierre técnico ya había leído la máquina como
 *      `OK` (creencia previa) cuando el barrido confirma la cuarentena; el cierre continúa,
 *      su compare-and-set responde «no es tuyo», relee el estado fresco y **confirma sin
 *      escribir un segundo rastro**. Y sigue exigiendo las declaraciones sanitarias: perder
 *      la carrera no le abre la puerta del cierre.
 *   2. **El cierre gana la carrera.** El barrido posterior ni siquiera ve la máquina como
 *      candidata (el prefiltro excluye la cuarentena ya escrita) y el rastro sigue siendo
 *      UNO: la exclusión mutua funciona en los dos sentidos.
 *   3. **Contención real por fila.** Con la fila de la máquina sostenida por el cierre sin
 *      confirmar, el barrido NO puede duplicar el disparo: su transición muere esperando el
 *      bloqueo (`1205`), viaja a **`contended`** —aplazada, no fallada: sin alerta y con
 *      reintento en la pasada siguiente (T-PAUSE-39)— y en la MISMA pasada la otra máquina,
 *      libre, sí entra en cuarentena. El aislamiento es por fila, no un bloqueo global, y el
 *      perdedor no deja rastro del expediente ajeno.
 *   4. **Después del ganador, nadie más escribe.** Confirmada la cuarentena del cierre, la
 *      pasada siguiente del barrido no añade ni una cuarentena ni un evento.
 *
 * Límites declarados (lo que esta suite NO puede hacer y por qué):
 *   - Un proceso PHP es monohilo: no se puede hacer coincidir en el tiempo real dos
 *     evaluaciones concurrentes. La carrera se ORQUESTA —el grupo 3 reserva la fila con el
 *     ganador y lanza al perdedor contra ella— y el grupo 1 fija, con un instrumento
 *     declarado, el instante exacto en que el otro consumidor confirma. La lectura, la
 *     escritura y la auditoría son SIEMPRE las reales: no hay dobles ni SQL simulado.
 *   - El instrumento del grupo 1 captura el valor de la máquina ANTES de que el barrido
 *     escriba y se lo entrega al cierre después: es la única forma determinista de que el
 *     perdedor llegue a la escritura con una creencia previa a la carrera. Y es exactamente
 *     el caso real que el compare-and-set tiene que arbitrar.
 *   - Requiere al menos cuatro máquinas libres bajo vigilancia sanitaria (`PERISHABLE_FOOD`
 *     o `COMBO`) en el banco de pruebas sembrado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias ni red externa.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

/**
 * Decorador del puerto de ajustes preventivos que sirve al cierre técnico **una lectura
 * previa a la carrera** (instrumento del grupo 1, declarado en la cabecera de la suite).
 *
 * No simula nada: envuelve el repositorio PDO real de la segunda conexión, captura el valor
 * que la máquina tiene ANTES de que el otro consumidor confirme, ejecuta entonces al otro
 * consumidor y devuelve al llamante el valor capturado. El resto de lecturas y la escritura
 * —el compare-and-set— viajan intactas al repositorio real, de modo que lo que se certifica
 * es el SQL de verdad y no una coreografía de dobles.
 */
final class SanitaryRaceStaleReadRepository implements PreventiveSettingsRepositoryInterface
{
    /** @var list<string> Estado servido en cada lectura; la primera es la previa a la carrera. */
    public array $readStatuses = [];

    /** @var list<bool> Respuesta de cada compare-and-set delegado al repositorio real. */
    public array $writeResults = [];

    private int $reads = 0;

    public function __construct(
        private readonly PreventiveSettingsRepositoryInterface $inner,
        private readonly Closure $onFirstRead
    ) {
    }

    public function reads(): int
    {
        return $this->reads;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        // 1º se captura el estado vigente —la creencia que el perdedor va a usar— y sólo
        // después el otro consumidor confirma su escritura. Devolver el valor capturado
        // es lo que convierte esta suite en una carrera determinista.
        $value = $this->inner->getMachineSettings($machineId);

        $this->reads++;
        $this->readStatuses[] = (string)($value['sanitary_status'] ?? 'AUSENTE');

        if ($this->reads === 1) {
            ($this->onFirstRead)();
        }

        return $value;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        $changed = $this->inner->updateSanitaryStatus($machineId, $status);
        $this->writeResults[] = $changed;

        return $changed;
    }

    /** @return array<PreventiveSetting> */
    public function findAll(): array
    {
        return $this->inner->findAll();
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return $this->inner->findByMachineType($machineType);
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        return $this->inner->updateTypeSettings($machineType, $defaultFrequencyDays, $maxAllowedDays, $advanceWarningDays);
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        return $this->inner->updateMachineConfig($machineId, $sanitaryFrequencyDays, $nextSanitaryInspectionDue);
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        return $this->inner->setSeasonalPause($machineId, $reason, $pauseUntil);
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        return $this->inner->resumeSeasonalPause($machineId);
    }
}

echo "======================================================================\n";
echo " VendGuard: Integración - SanitaryClockConsumerRaceTest (T-PAUSE-38)\n";
echo " Dos consumidores del reloj sanitario · dos conexiones PDO reales\n";
echo "======================================================================\n";

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

// ─── Grupo 0: banco de pruebas con dos conexiones independientes ───────────────

echo "\n--- Grupo 0: Banco de pruebas (dos conexiones independientes) ---\n";

// La conexión canónica es la que resuelven por defecto el enrutador y los servicios de
// producción: por ella corre el barrido. La segunda es una conexión REAL distinta sobre la
// misma base de datos y por ella corre el cierre técnico.
$pdoSweep = ConnectionFactory::getConnection();
$pdoClosure = ConnectionFactory::getConnection([], true);

$assert(
    '0.1 Dos conexiones PDO REALES e independientes sobre la misma base de datos',
    $pdoSweep !== $pdoClosure
    && $pdoSweep->query('SELECT DATABASE()')->fetchColumn() === $pdoClosure->query('SELECT DATABASE()')->fetchColumn(),
    'las dos conexiones del banco de pruebas no son independientes'
);

TestDataCleaner::purge($pdoSweep);
(new SeedRunner($pdoSweep))->seedAll();

$router = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdoSweep);
$incidentRepoClosure = new PdoIncidentRepository($pdoClosure);

$technicianId = (int)$pdoSweep->query(
    "SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 1"
)->fetchColumn();
$coordinatorId = (int)$pdoSweep->query(
    "SELECT `id` FROM `users` WHERE `email` = 'coordinacion@vendguard.internal'"
)->fetchColumn();

$assert(
    '0.2 Semilla disponible: técnico de ruta y coordinador de operaciones',
    $technicianId > 0 && $coordinatorId > 0
);

if ($technicianId <= 0 || $coordinatorId <= 0) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

// ─── Inventario del parque que la suite va a tocar (restituido en el finally) ──

/** @var array<int, string> Estado sanitario de TODO el parque al arrancar la suite. */
$sanitarySnapshot = [];
foreach ($pdoSweep->query('SELECT `id`, `sanitary_status` FROM `machines` WHERE `deleted_at` IS NULL') as $row) {
    $sanitarySnapshot[(int)$row['id']] = (string)$row['sanitary_status'];
}

// Máquinas libres bajo vigilancia sanitaria (`MachineType::requiresSanitaryWatch()`), sin
// expediente vivo: son las únicas que el reloj biológico puede poner en cuarentena.
$watchPool = $pdoSweep->query(
    "SELECT m.`id` AS machine_id, m.`location_id`, m.`code`, m.`machine_type`
       FROM `machines` m
       JOIN `locations` l ON l.`id` = m.`location_id`
      WHERE m.`is_active` = 1
        AND m.`is_blocked_no_access` = 0
        AND m.`deleted_at` IS NULL
        AND l.`is_active` = 1
        AND l.`deleted_at` IS NULL
        AND m.`machine_type` IN ('PERISHABLE_FOOD', 'COMBO')
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.`id`"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.3 El banco de pruebas expone al menos cuatro máquinas libres bajo vigilancia sanitaria',
    count($watchPool) >= 4,
    sprintf('máquinas libres bajo vigilancia: %d', count($watchPool))
);

if (count($watchPool) < 4) {
    echo "ERROR FATAL: el banco de pruebas no puede aislar las cuatro máquinas de la carrera.\n";
    exit(1);
}

$sweepWinner   = $watchPool[0];
$closureWinner = $watchPool[1];
$contended     = $watchPool[2];
$freePeer      = $watchPool[3];

$assert(
    '0.4 El banco cubre las dos tipologías vigiladas (perecedera y mixta, RF-03.4)',
    in_array('PERISHABLE_FOOD', array_column($watchPool, 'machine_type'), true)
    && in_array('COMBO', array_column($watchPool, 'machine_type'), true),
    json_encode(array_column($watchPool, 'machine_type'), JSON_UNESCAPED_UNICODE)
);

// ─── Instrumentos de siembra y de medición ────────────────────────────────────

$sequence = 0;
$suffix   = strtoupper(bin2hex(random_bytes(3)));
$createdTicketIds = [];

/** Crea y envejece un expediente vivo: la avería que el reloj biológico ve vencida. */
$createExpiredTicket = function (array $machine, int $hours = 5) use (
    $pdoSweep,
    $incidentRepo,
    $technicianId,
    $coordinatorId,
    $suffix,
    &$sequence,
    &$createdTicketIds
): Incident {
    $sequence++;

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-RACE-%s-%02d', $suffix, $sequence),
        machineId: (int)$machine['machine_id'],
        locationId: (int)$machine['location_id'],
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Expediente de la carrera entre consumidores del reloj sanitario (T-PAUSE-38).',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $createdTicketIds[] = $incidentId;

    $incidentRepo->assign($incidentId, $technicianId, $coordinatorId);
    $incidentRepo->startIntervention($incidentId, $technicianId);

    // Envejecimiento del expediente propio: instrumento del banco de pruebas, no una vía de
    // producción (el reloj mide desde `created_at`, no desde una columna aparte).
    $pdoSweep->prepare(
        'UPDATE `incidents` SET `created_at` = NOW() - INTERVAL :hours HOUR WHERE `id` = :id'
    )->execute([':hours' => $hours, ':id' => $incidentId]);

    return $incidentRepo->findById($incidentId);
};

$machineStatus = static function (int $machineId) use ($pdoSweep): string {
    $stmt = $pdoSweep->prepare('SELECT `sanitary_status` FROM `machines` WHERE `id` = :id');
    $stmt->execute([':id' => $machineId]);

    return (string)$stmt->fetchColumn();
};

/**
 * `audit_log` es append-only y el arnés NO la purga: todo recuento se mide por delta sobre
 * el cursor, como manda el protocolo ratificado (T-PAUSE-13/14/21).
 */
$auditCursor = static function () use ($pdoSweep): int {
    return (int)$pdoSweep->query('SELECT COALESCE(MAX(`id`), 0) FROM `audit_log`')->fetchColumn();
};

$quarantineEventsSince = static function (int $machineId, int $since) use ($pdoSweep): int {
    $stmt = $pdoSweep->prepare(
        "SELECT COUNT(*) FROM `audit_log`
          WHERE `entity_type` = 'MACHINE' AND `entity_id` = :id
            AND `action` = 'SANITARY_QUARANTINE_AUTO_TRIGGERED'
            AND `id` > :since"
    );
    $stmt->execute([':id' => $machineId, ':since' => $since]);

    return (int)$stmt->fetchColumn();
};

$cronSecret = SecretProvider::cronSecret();

/** El barrido, por su RUTA REAL (router + CronAuthMiddleware fail-closed). */
$sweep = static function () use ($router, $cronSecret) {
    return $router->dispatch(new Request(
        method: 'POST',
        path: '/api/cron/sanitary-clock-sweep',
        headers: ['X-Cron-Secret' => $cronSecret]
    ));
};

/**
 * El cierre técnico, cableado SOBRE LA SEGUNDA CONEXIÓN: es el mismo servicio y el mismo
 * método que `TechnicianController::resolveIncident()` invoca en su puerta sanitaria.
 */
$closureConsumer = static function (?PreventiveSettingsRepositoryInterface $settingsRepo = null) use ($pdoClosure): IncidentPauseService {
    return new IncidentPauseService(
        settingsRepo: $settingsRepo ?? new PdoPreventiveSettingsRepository($pdoClosure),
        auditLogger: new AuditLogger(new PdoAuditLogRepository($pdoClosure)),
        transactionManager: new PdoTransactionManager($pdoClosure)
    );
};

// La conexión del barrido falla rápido en lugar de esperar 50 s a la fila del grupo 3.
$pdoSweep->exec('SET SESSION innodb_lock_wait_timeout = 1');

try {
    // =====================================================================
    // GRUPO 1: El barrido gana la carrera (el cierre pierde sin duplicar)
    // =====================================================================
    echo "\n--- Grupo 1: El barrido gana; el cierre técnico pierde la carrera ---\n";

    $sweepIncident = $createExpiredTicket($sweepWinner, 5);
    $sweepMachineId = (int)$sweepWinner['machine_id'];
    $closureIncident = $incidentRepoClosure->findById((int)$sweepIncident->getId());

    $assert(
        '1.1 Premisa: máquina en OK y expediente vivo con más de 4 h naturales, sin intento de cierre',
        $machineStatus($sweepMachineId) === 'OK'
        && $sweepIncident->getStatus() === IncidentStatus::IN_PROGRESS
        && (new DateTimeImmutable((string)$sweepIncident->getCreatedAt()))->getTimestamp() <= time() - 14400,
        sprintf('estado sanitario=%s, expediente=%s', $machineStatus($sweepMachineId), $sweepIncident->getStatus()->value)
    );

    $cursor = $auditCursor();

    // El barrido corre en medio de la evaluación del cierre, justo después de que éste haya
    // leído la máquina como OK: la creencia previa a la carrera, servida por el instrumento.
    $sweepResponse = null;
    $sweepEvents = null;
    $racingSweep = static function () use (&$sweepResponse, &$sweepEvents, $sweep, $quarantineEventsSince, $sweepMachineId, $cursor): void {
        $sweepResponse = $sweep();
        $sweepEvents = $quarantineEventsSince($sweepMachineId, $cursor);
    };

    $staleRead = new SanitaryRaceStaleReadRepository(
        new PdoPreventiveSettingsRepository($pdoClosure),
        $racingSweep
    );

    // Las DOS llamadas que la puerta sanitaria del cierre hace en producción
    // (`TechnicianController::resolveIncident()`), sobre la segunda conexión real. Perder la
    // carrera no puede convertirse en un `500`: el perdedor tiene que confirmar la cuarentena
    // ajena y seguir exigiendo las declaraciones, no reventar (por eso se captura).
    $closureLoser = $closureConsumer($staleRead);
    $closureVerdict = null;
    $closureRequiresDeclarations = null;
    $closureFailure = $captureThrowable(static function () use (
        &$closureVerdict,
        &$closureRequiresDeclarations,
        $closureLoser,
        $closureIncident
    ): void {
        $closureVerdict = $closureLoser->evaluateSanitaryBiologicalClock($closureIncident);
        $closureRequiresDeclarations = $closureLoser->sanitaryResolutionRequirement($closureIncident);
    });

    $sweepData = $sweepResponse?->getDecodedBody()['data'] ?? [];

    $assert(
        '1.2 El barrido corrió en medio de la evaluación del cierre y se declaró en verde',
        $sweepResponse !== null
        && $sweepResponse->getStatusCode() === 200
        && ($sweepData['failures_count'] ?? -1) === 0
        && $sweepEvents === 1,
        sprintf(
            'estado=%s, datos=%s',
            $sweepResponse === null ? 'NO CORRIÓ' : (string)$sweepResponse->getStatusCode(),
            json_encode($sweepData, JSON_UNESCAPED_UNICODE)
        )
    );

    $assert(
        '1.3 El ganador deja la máquina en cuarentena con UN evento inmutable',
        in_array($sweepMachineId, array_map('intval', array_column($sweepData['quarantined'] ?? [], 'machine_id')), true)
        && $machineStatus($sweepMachineId) === 'QUARANTINE'
        && $sweepEvents === 1
    );

    $assert(
        '1.4 El cierre perdedor llegó a la escritura con la creencia previa a la carrera',
        $staleRead->readStatuses[0] === 'OK' && $staleRead->reads() >= 2,
        sprintf('lecturas servidas=%s', json_encode($staleRead->readStatuses, JSON_UNESCAPED_UNICODE))
    );

    $assert(
        '1.5 Su compare-and-set respondió «no es tuyo» y la relectura fresca confirmó la cuarentena',
        $closureFailure === null
        && $staleRead->writeResults === [false]
        && $closureVerdict === true
        && count(array_filter(
            array_slice($staleRead->readStatuses, 1),
            static fn (string $status): bool => $status === 'QUARANTINE'
        )) === count($staleRead->readStatuses) - 1,
        sprintf(
            'escrituras=%s, lecturas=%s, veredicto=%s, excepción=%s',
            json_encode($staleRead->writeResults),
            json_encode($staleRead->readStatuses, JSON_UNESCAPED_UNICODE),
            var_export($closureVerdict, true),
            $closureFailure === null ? 'ninguna' : $closureFailure::class . ': ' . $closureFailure->getMessage()
        )
    );

    $raceEventCount = $quarantineEventsSince($sweepMachineId, $cursor);

    $assert(
        '1.6 Tras los DOS consumidores sobrevive UNA sola cuarentena y UN solo rastro (Art. III)',
        $raceEventCount === 1 && $machineStatus($sweepMachineId) === 'QUARANTINE',
        sprintf('eventos de cuarentena=%d', $raceEventCount)
    );

    $assert(
        '1.7 Perder la carrera no abre la puerta del cierre: sigue exigiendo las declaraciones (RF-03.5.2)',
        $closureRequiresDeclarations === true,
        sprintf('requiere declaraciones=%s', var_export($closureRequiresDeclarations, true))
    );

    // =====================================================================
    // GRUPO 2: El cierre técnico gana la carrera (el barrido no duplica)
    // =====================================================================
    echo "\n--- Grupo 2: El cierre técnico gana; el barrido no encuentra nada que hacer ---\n";

    $closureIncidentWins = $createExpiredTicket($closureWinner, 5);
    $closureMachineId = (int)$closureWinner['machine_id'];
    $closureWinnerIncident = $incidentRepoClosure->findById((int)$closureIncidentWins->getId());

    $assert(
        '2.0 Premisa: la segunda máquina arranca en OK — el primer disparo no la tocó',
        $machineStatus($closureMachineId) === 'OK' && $machineStatus($sweepMachineId) === 'QUARANTINE',
        sprintf(
            'segunda máquina=%s, primera=%s',
            $machineStatus($closureMachineId),
            $machineStatus($sweepMachineId)
        )
    );

    $cursorClosure = $auditCursor();
    $closureWinnerService = $closureConsumer();
    $winnerVerdict = $closureWinnerService->evaluateSanitaryBiologicalClock($closureWinnerIncident);
    $eventsByClosure = $quarantineEventsSince($closureMachineId, $cursorClosure);

    $assert(
        '2.1 El cierre técnico dispara la cuarentena y sella su evento único',
        $winnerVerdict === true
        && $eventsByClosure === 1
        && $machineStatus($closureMachineId) === 'QUARANTINE',
        sprintf('veredicto=%s, eventos=%d, estado=%s', var_export($winnerVerdict, true), $eventsByClosure, $machineStatus($closureMachineId))
    );

    $sweepAfterClosure = $sweep();
    $afterClosureData = $sweepAfterClosure->getDecodedBody()['data'] ?? [];

    $assert(
        '2.2 El barrido posterior no declara la máquina ya en cuarentena ni añade rastro',
        $sweepAfterClosure->getStatusCode() === 200
        && !in_array($closureMachineId, array_map('intval', array_column($afterClosureData['quarantined'] ?? [], 'machine_id')), true)
        && ($afterClosureData['failures_count'] ?? -1) === 0
        && $quarantineEventsSince($closureMachineId, $cursorClosure) === 1,
        json_encode($afterClosureData, JSON_UNESCAPED_UNICODE)
    );

    // =====================================================================
    // GRUPO 3: Contención real por fila (el perdedor no puede duplicar)
    // =====================================================================
    echo "\n--- Grupo 3: Contención por fila con el ganador sin confirmar ---\n";

    $contendedIncident = $createExpiredTicket($contended, 5);
    $freePeerIncident = $createExpiredTicket($freePeer, 5);
    $contendedMachineId = (int)$contended['machine_id'];
    $freePeerMachineId = (int)$freePeer['machine_id'];

    $contendedClosureIncident = $incidentRepoClosure->findById((int)$contendedIncident->getId());

    $cursorContention = $auditCursor();

    // El cierre se convierte en la operación «en vuelo»: escribe su cuarentena y su evento
    // dentro de una transacción viva, de modo que sostiene el bloqueo exclusivo de la fila.
    $pdoClosure->beginTransaction();
    $holderVerdict = $closureConsumer()->evaluateSanitaryBiologicalClock($contendedClosureIncident);

    $assert(
        '3.1 El cierre escribe su cuarentena y su evento sin confirmar, sosteniendo la fila',
        $holderVerdict === true
        && $pdoClosure->inTransaction()
        && $quarantineEventsSince($contendedMachineId, $cursorContention) === 0,
        sprintf(
            'veredicto=%s, transacción viva=%s, eventos visibles=%d',
            var_export($holderVerdict, true),
            var_export($pdoClosure->inTransaction(), true),
            $quarantineEventsSince($contendedMachineId, $cursorContention)
        )
    );

    $assert(
        '3.2 El estado sin confirmar no se publica: la máquina sigue leyéndose OK por la otra conexión',
        $machineStatus($contendedMachineId) === 'OK',
        sprintf('estado leído=%s', $machineStatus($contendedMachineId))
    );

    $contendedSweep = $sweep();
    $contendedData = $contendedSweep->getDecodedBody()['data'] ?? [];
    $contendedQuarantined = array_map('intval', array_column($contendedData['quarantined'] ?? [], 'machine_id'));
    $deferredTickets = array_map(
        static fn (array $entry): string => (string)($entry['ticket_code'] ?? ''),
        $contendedData['contended'] ?? []
    );
    $deferredReasons = implode(' | ', array_map(
        static fn (array $entry): string => (string)($entry['reason'] ?? ''),
        $contendedData['contended'] ?? []
    ));

    $assert(
        '3.3 El barrido NO puede duplicar el disparo: su transición muere esperando el bloqueo y se APLAZA',
        $contendedSweep->getStatusCode() === 200
        && ($contendedData['contended_count'] ?? -1) === 1
        && ($contendedData['failures_count'] ?? -1) === 0
        && in_array((string)$contendedIncident->getTicketCode(), $deferredTickets, true)
        && (str_contains($deferredReasons, '1205') || str_contains($deferredReasons, 'Lock wait timeout'))
        && !in_array($contendedMachineId, $contendedQuarantined, true),
        sprintf(
            'aplazados=%s, motivos=%s, cuarentenadas=%s',
            json_encode($deferredTickets, JSON_UNESCAPED_UNICODE),
            $deferredReasons,
            json_encode($contendedQuarantined)
        )
    );

    $assert(
        '3.3.bis La contención se separa del fallo: no alerta al planificador y el resumen cuadra',
        ($contendedData['contended'][0]['incident_id'] ?? null) === $contendedIncident->getId()
        && ($contendedData['evaluated'] + $contendedData['failures_count'] + $contendedData['contended_count']) === $contendedData['candidates'],
        json_encode($contendedData, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.4 El mismo barrido no perdió el resto del lote: la máquina libre sí entró en cuarentena',
        in_array($freePeerMachineId, $contendedQuarantined, true)
        && $machineStatus($freePeerMachineId) === 'QUARANTINE'
        && $quarantineEventsSince($freePeerMachineId, $cursorContention) === 1,
        json_encode($contendedData, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.5 El perdedor no dejó rastro del expediente ajeno mientras el dueño no confirmaba',
        $quarantineEventsSince($contendedMachineId, $cursorContention) === 0
        && $machineStatus($contendedMachineId) === 'OK'
    );

    $pdoClosure->commit();

    $assert(
        '3.6 Confirmado el ganador, la cuarentena aparece UNA sola vez',
        $quarantineEventsSince($contendedMachineId, $cursorContention) === 1
        && $machineStatus($contendedMachineId) === 'QUARANTINE',
        sprintf('eventos=%d, estado=%s', $quarantineEventsSince($contendedMachineId, $cursorContention), $machineStatus($contendedMachineId))
    );

    // =====================================================================
    // GRUPO 4: Después del ganador, la pasada siguiente no añade nada
    // =====================================================================
    echo "\n--- Grupo 4: Idempotencia con los dos ganadores ya confirmados ---\n";

    $finalSweep = $sweep();
    $finalData = $finalSweep->getDecodedBody()['data'] ?? [];
    $finalQuarantined = array_map('intval', array_column($finalData['quarantined'] ?? [], 'machine_id'));

    $assert(
        '4.1 La pasada siguiente no vuelve a cuarentenar ninguna de las máquinas ya ganadas',
        $finalSweep->getStatusCode() === 200
        && ($finalData['failures_count'] ?? -1) === 0
        && ($finalData['contended_count'] ?? -1) === 0
        && !array_intersect([$sweepMachineId, $closureMachineId, $contendedMachineId, $freePeerMachineId], $finalQuarantined),
        json_encode(
            [
                'quarantined' => $finalQuarantined,
                'failures_count' => $finalData['failures_count'] ?? null,
                'contended_count' => $finalData['contended_count'] ?? null,
            ],
            JSON_UNESCAPED_UNICODE
        )
    );

    $assert(
        '4.2 El rastro total sigue siendo UNO por máquina (ni el barrido ni el cierre lo duplican)',
        $quarantineEventsSince($sweepMachineId, $cursor) === 1
        && $quarantineEventsSince($closureMachineId, $cursorClosure) === 1
        && $quarantineEventsSince($contendedMachineId, $cursorContention) === 1
        && $quarantineEventsSince($freePeerMachineId, $cursorContention) === 1,
        sprintf(
            'eventos: barrido=%d, cierre=%d, contención=%d, vecina=%d',
            $quarantineEventsSince($sweepMachineId, $cursor),
            $quarantineEventsSince($closureMachineId, $cursorClosure),
            $quarantineEventsSince($contendedMachineId, $cursorContention),
            $quarantineEventsSince($freePeerMachineId, $cursorContention)
        )
    );
} finally {
    // Sesión y transacciones del banco de pruebas: se deja el entorno como estaba.
    $pdoSweep->exec('SET SESSION innodb_lock_wait_timeout = 50');

    if ($pdoClosure->inTransaction()) {
        $pdoClosure->rollBack();
    }

    if ($pdoSweep->inTransaction()) {
        $pdoSweep->rollBack();
    }

    // Restitución del estado sanitario de TODO el parque: la carrera escribe sobre filas
    // maestras que otras suites esperan intactas.
    if ($sanitarySnapshot !== []) {
        $restore = $pdoSweep->prepare('UPDATE `machines` SET `sanitary_status` = :status WHERE `id` = :id');
        foreach ($sanitarySnapshot as $machineId => $status) {
            $restore->execute([':status' => $status, ':id' => $machineId]);
        }
    }

    foreach ($createdTicketIds as $ticketId) {
        TestDataCleaner::purgeIncident($pdoSweep, $ticketId);
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-38 (CONCURRENCIA) CUMPLIDA.\n";
    echo str_repeat('=', 70) . "\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo str_repeat('=', 70) . "\n";
exit(1);
