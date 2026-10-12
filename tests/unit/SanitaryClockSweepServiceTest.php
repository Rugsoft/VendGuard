<?php

declare(strict_types=1);

/**
 * SanitaryClockSweepServiceTest
 *
 * Suite unitaria del barrido de fondo del Reloj Sanitario Biológico (T-PAUSE-38,
 * RF-03.5.1, Constitución Art. II y Art. III). Es el consumidor que despierta la
 * cuarentena **sin** que nadie intente cerrar la avería, así que la suite certifica:
 *
 * 1. El barrido recorre los candidatos que publica el puerto y deja en cuarentena
 *    sólo a los que el reloj manda, con **una** escritura y **un** rastro inmutable.
 * 2. La idempotencia es real: con la máquina ya en cuarentena —o con un prefiltro
 *    que deja de ofrecerla— no hay segunda escritura ni segundo evento.
 * 3. Un candidato indecidible se aísla en `failures` con su motivo y **no** aborta
 *    el resto del lote; una excepción de infraestructura no contemplada se propaga
 *    en lugar de tragarse.
 * 3.bis. La **contención de fila** (otro consumidor sostiene el bloqueo de la máquina:
 *    `1205`, `1213`, `SQLSTATE 40001`) NO se cuenta como fallo: viaja en `contended`,
 *    no enciende la alerta y no toca la máquina. La clasificación es estricta y
 *    **fail-safe**: cualquier otro error de persistencia se queda en `failures`.
 * 4. El barrido no reimplementa el umbral: lo pide al reloj, lo publica en el
 *    resumen y lo usa como criterio del puerto de candidatos.
 * 5. Cada candidato se evalúa dentro de su propia unidad de trabajo (Art. III),
 *    de modo que el estado sanitario y su evidencia caen juntos.
 * 6. El DTO publica exactamente las claves del contrato documentado en
 *    `specs/11-pending-info-sla-pause/plan.md` §2.5 y en `api_contracts.md`.
 * 7. La pasada se publica para la operación (T-PAUSE-41, RF-07): cada pasada deja
 *    su libro con los recuentos del resumen y cada aplazamiento abre -o continúa-
 *    un seguimiento por expediente con sus pasadas consecutivas, que escala a la
 *    tercera. El libro se escribe DESPUÉS de consumir el reloj: si su escritura
 *    falla, el fallo se propaga, pero la cuarentena ya confirmada sigue escrita.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red. El puerto de
 * candidatos, el repositorio de ajustes, la unidad de trabajo, la auditoría y el
 * libro de pasadas se sustituyen por dobles en memoria; el reloj, la política del
 * escalado y el servicio del barrido son los reales.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\SanitaryClockSweepResultDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\SanitarySweepContention;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryClockCandidateRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Service\SanitarySweepEscalationPolicy;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - SanitaryClockSweepServiceTest (T-PAUSE-38)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/** @return array{threw: bool, class: string, message: string} */
$capture = static function (callable $action): array {
    try {
        $action();
    } catch (Throwable $exception) {
        return ['threw' => true, 'class' => $exception::class, 'message' => $exception->getMessage()];
    }

    return ['threw' => false, 'class' => '', 'message' => ''];
};

/**
 * Doble del puerto segregado de candidatos: publica la lista que le da la suite y
 * registra con qué umbral y qué cota se le consultó.
 */
final class SweepCandidateRepo implements SanitaryClockCandidateRepositoryInterface
{
    /** @var list<Incident> */
    public array $candidates = [];

    /** @var list<array{threshold: int, limit: int}> */
    public array $queries = [];

    public function findSanitaryClockCandidates(int $thresholdSeconds, int $limit = 500): array
    {
        $this->queries[] = ['threshold' => $thresholdSeconds, 'limit' => $limit];

        return $this->candidates;
    }
}

/**
 * Doble del repositorio de ajustes preventivos con la transición idempotente del
 * contrato: sólo escribe cuando el valor cambia y devuelve si esta llamada fue la
 * que lo cambió (compare-and-set).
 */
final class SweepSettingsRepo implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    /** @var list<array{machine_id: int, status: string}> */
    public array $statusWrites = [];

    /**
     * Cuando es `true`, la transición idempotente muere esperando el bloqueo que otro
     * consumidor sostiene sobre la misma fila: es el `1205` real que PDO entrega desde el
     * `UPDATE` del repositorio de producción.
     */
    public bool $failWrites = false;

    public function register(int $machineId, string $machineType, string $sanitaryStatus = 'OK'): void
    {
        $this->machines[$machineId] = [
            'machine_id' => $machineId,
            'machine_code' => sprintf('VEND-%04d', $machineId),
            'machine_type' => $machineType,
            'sanitary_status' => $sanitaryStatus,
            'sanitary_frequency_days' => 15,
            'default_frequency_days' => 15,
            'is_seasonal_pause' => false,
        ];
    }

    public function findAll(): array
    {
        return [];
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        return true;
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        return true;
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        return true;
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        return true;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? null;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if ($this->failWrites) {
            $exception = new PDOException(
                'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'
            );
            $exception->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

            throw $exception;
        }

        if (isset($this->machines[$machineId]) && $this->machines[$machineId]['sanitary_status'] === $status) {
            return false;
        }

        $this->statusWrites[] = ['machine_id' => $machineId, 'status' => $status];
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }

        return true;
    }
}

/** Repositorio de auditoría espía: captura el evento tal y como lo emite el logger real. */
final class SweepAuditRepo implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->events;
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

/**
 * Doble de la cota de espera de bloqueo (T-PAUSE-40): registra la cota con la que se le
 * invoca, ejecuta la operación como haría el adaptador real y deja constancia de que
 * restituyó la sesión incluso cuando la operación estalla.
 */
/**
 * Doble del libro de pasadas y de los seguimientos de contención (T-PAUSE-41).
 *
 * Reproduce el contrato del puerto en memoria: la clave del seguimiento es el
 * expediente (un episodio por expediente, reutilizando la fila), la resolución se
 * hace por IDENTIDAD —se cierra lo que la pasada no aplazó, sin comparar marcas de
 * tiempo— y la escritura del libro puede hacerse fallar a propósito para certificar
 * que el barrido la propaga en voz alta en lugar de tragársela.
 */
final class SweepVisibilityRepo implements SanitarySweepVisibilityRepositoryInterface
{
    /** @var list<SanitarySweepPass> Pasadas registradas, en orden. */
    public array $passes = [];

    /** @var array<int, SanitarySweepContention> Seguimiento vigente por expediente. */
    public array $contentions = [];

    /** @var list<array{detected: list<int>, resolved_at: string}> Cierres ejecutados. */
    public array $resolutions = [];

    /** Lecturas del seguimiento vigente (una por aplazamiento). */
    public int $reads = 0;

    /** Cuando es `true`, el libro no se puede escribir: reproduce el fallo de observabilidad. */
    public bool $failOnRecordPass = false;

    /**
     * Cuando es `true`, el seguimiento no se puede escribir porque **el mismo tenedor**
     * que provocó el aplazamiento sigue bloqueando una fila padre: es el `1205` real de
     * la observación, que no puede teñir la pasada de rojo.
     */
    public bool $failOnSaveWithLock = false;

    /** Cuando es `true`, el seguimiento falla por algo que NO es un bloqueo (observabilidad rota). */
    public bool $failOnSaveGeneric = false;

    public function recordPass(SanitarySweepPass $pass): SanitarySweepPass
    {
        if ($this->failOnRecordPass) {
            throw new RuntimeException('Fallo de observabilidad simulado al registrar la pasada.');
        }

        $stored = new SanitarySweepPass(
            id: count($this->passes) + 1,
            startedAt: $pass->startedAt,
            finishedAt: $pass->finishedAt,
            candidates: $pass->candidates,
            evaluated: $pass->evaluated,
            quarantinedCount: $pass->quarantinedCount,
            contendedCount: $pass->contendedCount,
            failuresCount: $pass->failuresCount,
            durationMs: $pass->durationMs,
        );
        $this->passes[] = $stored;

        return $stored;
    }

    public function findContentionByIncident(int $incidentId): ?SanitarySweepContention
    {
        $this->reads++;

        return $this->contentions[$incidentId] ?? null;
    }

    public function saveContentionDetection(SanitarySweepContention $contention): void
    {
        if ($this->failOnSaveWithLock) {
            $exception = new PDOException(
                'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'
            );
            $exception->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

            throw $exception;
        }

        if ($this->failOnSaveGeneric) {
            throw new RuntimeException('Fallo de observabilidad simulado al escribir el seguimiento.');
        }

        $this->contentions[$contention->incidentId] = $contention;
    }

    public function resolveContentionsNotDetectedIn(array $detectedIncidentIds, DateTimeImmutable $resolvedAt): int
    {
        $detected = array_map('intval', array_values($detectedIncidentIds));
        $this->resolutions[] = [
            'detected' => $detected,
            'resolved_at' => $resolvedAt->format('Y-m-d H:i:s'),
        ];

        $closed = 0;
        foreach ($this->contentions as $incidentId => $contention) {
            if (!$contention->isOpen() || in_array((int)$incidentId, $detected, true)) {
                continue;
            }

            $this->contentions[$incidentId] = new SanitarySweepContention(
                id: $contention->id,
                incidentId: $contention->incidentId,
                machineId: $contention->machineId,
                firstDetectedAt: $contention->firstDetectedAt,
                lastDetectedAt: $contention->lastDetectedAt,
                consecutivePasses: $contention->consecutivePasses,
                totalPasses: $contention->totalPasses,
                escalatedAt: $contention->escalatedAt,
                resolvedAt: $resolvedAt->format('Y-m-d H:i:s'),
            );
            $closed++;
        }

        return $closed;
    }

    public function findOpenContentions(): array
    {
        return array_values(array_filter(
            $this->contentions,
            static fn (SanitarySweepContention $contention): bool => $contention->isOpen()
        ));
    }

    public function findLatestPass(): ?SanitarySweepPass
    {
        return $this->passes === [] ? null : $this->passes[count($this->passes) - 1];
    }

    public function findPassesSince(DateTimeImmutable $since): array
    {
        $from = $since->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $this->passes,
            static fn (SanitarySweepPass $pass): bool => $pass->startedAt >= $from
        ));
    }
}

final class SweepLockBudget implements LockWaitBudgetInterface
{
    /** @var list<int> Cotas recibidas, en orden. */
    public array $budgets = [];

    /** Número de veces que se abrió una ventana de cota. */
    public int $entered = 0;

    public bool $restored = false;

    public function runWithLockWaitBudget(int $seconds, callable $operation): mixed
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('La cota de espera de bloqueo debe ser positiva (doble).');
        }

        $this->budgets[] = $seconds;
        $this->entered++;

        try {
            return $operation();
        } finally {
            $this->restored = true;
        }
    }
}

/**
 * Doble de la unidad de trabajo: cuenta las unidades abiertas, puede hacer estallar la
 * enésima con un fallo no contemplado (para certificar que no se traga) y puede hacer
 * morir la enésima con un **error de PDO real** —el bloqueo de fila que otro consumidor
 * sostiene—, con el `errorInfo` que la suite elija.
 */
final class SweepTransactionManager implements TransactionManagerInterface
{
    public int $transactions = 0;

    public ?int $throwOnTransaction = null;

    /** Unidad de trabajo en la que se simula un error de persistencia (PDOException). */
    public ?int $throwPdoOnTransaction = null;

    /** @var array{0: string, 1: int|string, 2: string} */
    public array $pdoErrorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    public function runInTransaction(callable $operation): mixed
    {
        $this->transactions++;
        if ($this->throwOnTransaction !== null && $this->transactions === $this->throwOnTransaction) {
            throw new LogicException('Fallo de infraestructura simulado en la unidad de trabajo.');
        }

        if ($this->throwPdoOnTransaction !== null && $this->transactions === $this->throwPdoOnTransaction) {
            // Se reproduce el error tal y como lo entrega PDO en producción: mensaje con
            // el estado SQL y el código del driver, y el `errorInfo` estructurado.
            $exception = new PDOException(sprintf(
                'SQLSTATE[%s]: General error: %d %s',
                (string)$this->pdoErrorInfo[0],
                (int)$this->pdoErrorInfo[1],
                (string)$this->pdoErrorInfo[2]
            ));
            $exception->errorInfo = $this->pdoErrorInfo;

            throw $exception;
        }

        return $operation();
    }
}

$madrid = new DateTimeZone('Europe/Madrid');

/** Marca de apertura del expediente, en la zona operativa de la sede. */
$agedOpening = static function (int $seconds) use ($madrid): string {
    return (new DateTimeImmutable('now', $madrid))->modify(sprintf('-%d seconds', $seconds))->format('Y-m-d H:i:s');
};

$makeIncident = static function (
    int $id,
    string $ticketCode,
    int $machineId,
    ?string $createdAt,
    ?string $machineType = 'PERISHABLE_FOOD',
    IncidentStatus $status = IncidentStatus::IN_PROGRESS
): Incident {
    return new Incident(
        id: $id,
        ticketCode: $ticketCode,
        machineId: $machineId,
        locationId: 1,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Pérdida de frío en el recinto refrigerado con producto fresco expuesto.',
        urgency: UrgencyLevel::CRITICAL,
        status: $status,
        assignedTechnicianId: 7,
        assignedAt: '2026-10-08 09:00:00',
        machineType: $machineType,
        createdAt: $createdAt
    );
};

/**
 * Banco del barrido: puerto de candidatos, ajustes, auditoría, unidad de trabajo y
 * el servicio real del reloj sobre esos dobles.
 *
 * @return array{
 *   service: SanitaryClockSweepService,
 *   candidates: SweepCandidateRepo,
 *   settings: SweepSettingsRepo,
 *   audit: SweepAuditRepo,
 *   transactions: SweepTransactionManager,
 *   budget: SweepLockBudget,
 *   visibility: SweepVisibilityRepo
 * }
 */
$makeBench = static function (): array {
    $candidates = new SweepCandidateRepo();
    $settings = new SweepSettingsRepo();
    $audit = new SweepAuditRepo();
    $transactions = new SweepTransactionManager();
    $visibility = new SweepVisibilityRepo();

    $pauseService = new IncidentPauseService($settings, new AuditLogger($audit));

    $budget = new SweepLockBudget();

    return [
        'service' => new SanitaryClockSweepService(
            $candidates,
            $pauseService,
            $transactions,
            $budget,
            $visibility,
            new SanitarySweepEscalationPolicy()
        ),
        'candidates' => $candidates,
        'settings' => $settings,
        'audit' => $audit,
        'transactions' => $transactions,
        'budget' => $budget,
        'visibility' => $visibility,
    ];
};

// =====================================================================
// GRUPO 1: Despertar la cuarentena sin intento de cierre
// =====================================================================
echo "--- Grupo 1: Disparo del barrido ---\n";

$bench = $makeBench();
$bench['settings']->register(10, 'PERISHABLE_FOOD');
$bench['settings']->register(11, 'PERISHABLE_FOOD');

$bench['candidates']->candidates = [
    $makeIncident(142, 'INC-2026-00142', 10, $agedOpening(18000)),
    $makeIncident(143, 'INC-2026-00143', 11, $agedOpening(3600)),
];

$result = $bench['service']->sweep();

$assert(
    '1.1 El barrido informa de los candidatos evaluados y de la máquina que queda en cuarentena',
    $result->candidates === 2
    && $result->evaluated === 2
    && $result->skipped === 1
    && count($result->quarantined) === 1
    && $result->quarantined[0] === ['incident_id' => 142, 'ticket_code' => 'INC-2026-00142', 'machine_id' => 10],
    json_encode($result->toArray(), JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.2 La máquina con más de 4 h naturales queda en cuarentena sin que nadie haya intentado cerrar',
    ($bench['settings']->machines[10]['sanitary_status'] ?? null) === 'QUARANTINE'
    && $bench['settings']->statusWrites === [['machine_id' => 10, 'status' => 'QUARANTINE']],
    json_encode($bench['settings']->statusWrites, JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.3 La máquina con 1 h de avería no se toca (el reloj sigue mandando sobre el prefiltro)',
    ($bench['settings']->machines[11]['sanitary_status'] ?? null) === 'OK'
);

$assert(
    '1.4 Se emitió exactamente un evento inmutable, sobre la máquina correcta',
    count($bench['audit']->events) === 1
    && $bench['audit']->events[0]->getAction() === IncidentPauseService::ACTION_SANITARY_QUARANTINE_AUTO_TRIGGERED
    && $bench['audit']->events[0]->getEntityId() === 10,
    sprintf('%d eventos', count($bench['audit']->events))
);

$assert(
    '1.5 El barrido no reimplementa el umbral: lo pide al reloj y lo publica en el resumen',
    $bench['candidates']->queries === [[
        'threshold' => IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS,
        'limit' => SanitaryClockSweepService::DEFAULT_CANDIDATE_LIMIT,
    ]]
    && $result->thresholdSeconds === IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS,
    json_encode($bench['candidates']->queries, JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.6 Cada candidato se evaluó dentro de su propia unidad de trabajo (Art. III)',
    $bench['transactions']->transactions === 2,
    sprintf('unidades abiertas: %d', $bench['transactions']->transactions)
);

$assert(
    '1.7 El resumen declara el umbral constitucional de 14.400 s y una duración medible',
    $result->thresholdSeconds === 14400
    && $result->durationMs >= 0.0
    && $result->failures === []
    && $result->failuresCount === 0,
    json_encode($result->toArray(), JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 2: Idempotencia del barrido
// =====================================================================
echo "\n--- Grupo 2: Idempotencia ---\n";

$bench['candidates']->candidates = [];
$second = $bench['service']->sweep();

$assert(
    '2.1 Un segundo barrido sin candidatos (el prefiltro ya excluye la cuarentena) no escribe ni audita',
    $second->candidates === 0
    && $second->evaluated === 0
    && count($bench['settings']->statusWrites) === 1
    && count($bench['audit']->events) === 1,
    json_encode($second->toArray(), JSON_UNESCAPED_UNICODE)
);

$bench['candidates']->candidates = [$makeIncident(142, 'INC-2026-00142', 10, $agedOpening(18000))];
$third = $bench['service']->sweep();

$assert(
    '2.2 Reofrecer la misma máquina no duplica el rastro: se confirma la cuarentena sin volver a escribir',
    $third->evaluated === 1
    && count($third->quarantined) === 1
    && $third->skipped === 0
    && count($bench['settings']->statusWrites) === 1
    && count($bench['audit']->events) === 1,
    json_encode($third->toArray(), JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 3: Aislamiento por candidato y cota del lote
// =====================================================================
echo "\n--- Grupo 3: Aislamiento por candidato y cota ---\n";

$bench = $makeBench();
$bench['settings']->register(10, 'PERISHABLE_FOOD');

$bench['candidates']->candidates = [
    $makeIncident(150, 'INC-2026-00150', 12, $agedOpening(18000), null),
    $makeIncident(151, 'INC-2026-00151', 10, $agedOpening(18000)),
];

$isolated = $bench['service']->sweep();

$assert(
    '3.1 Un candidato indecidible (sin tipología) se aísla en failures con su motivo',
    count($isolated->failures) === 1
    && $isolated->failures[0]['incident_id'] === 150
    && $isolated->failures[0]['ticket_code'] === 'INC-2026-00150'
    && $isolated->failures[0]['reason'] !== ''
    && $isolated->failuresCount === 1
    && $isolated->evaluated === 1
    && $isolated->candidates === 2,
    json_encode($isolated->toArray(), JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.2 El candidato indecidible no aborta el lote: la máquina que sí procede entra en cuarentena',
    ($bench['settings']->machines[10]['sanitary_status'] ?? null) === 'QUARANTINE'
    && count($isolated->quarantined) === 1
    && count($bench['audit']->events) === 1,
    json_encode($isolated->toArray(), JSON_UNESCAPED_UNICODE)
);

$bench['candidates']->queries = [];
$bench['service']->sweep(2);

$assert(
    '3.3 La cota del lote viaja al puerto de candidatos tal y como la pide el llamador',
    $bench['candidates']->queries === [[
        'threshold' => IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS,
        'limit' => 2,
    ]],
    json_encode($bench['candidates']->queries, JSON_UNESCAPED_UNICODE)
);

$guardService = $makeBench()['service'];
$badLimit = $capture(static fn () => $guardService->sweep(0));
$assert(
    '3.4 Una cota imposible se rechaza en lugar de ejecutar un barrido vacío silencioso',
    $badLimit['threw'] === true && $badLimit['class'] === InvalidArgumentException::class,
    $badLimit['message']
);

$fatalBench = $makeBench();
$fatalBench['candidates']->candidates = [$makeIncident(152, 'INC-2026-00152', 10, $agedOpening(18000))];
$fatalBench['transactions']->throwOnTransaction = 1;
$fatal = $capture(static fn () => $fatalBench['service']->sweep());

$assert(
    '3.5 Un fallo de infraestructura no contemplado se propaga, no se traga como si fuera de dominio',
    $fatal['threw'] === true && $fatal['class'] === LogicException::class,
    $fatal['message']
);

// =====================================================================
// GRUPO 4: Contrato del resumen (DTO)
// =====================================================================
echo "\n--- Grupo 4: Contrato del resumen ---\n";

$expectedKeys = [
    'candidates',
    'evaluated',
    'quarantined',
    'skipped',
    'failures',
    'failures_count',
    'contended',
    'contended_count',
    'threshold_seconds',
    'duration_ms',
];

$empty = (new SanitaryClockSweepService(new SweepCandidateRepo()))->sweep();

$assert(
    '4.1 El resumen publica exactamente las claves documentadas, en orden, sin campos extra',
    array_keys($empty->toArray()) === $expectedKeys,
    json_encode(array_keys($empty->toArray()), JSON_UNESCAPED_UNICODE)
);

$assert(
    '4.2 Un parque sin candidatos se resuelve con un resumen vacío y en verde (no es un fallo)',
    $empty->candidates === 0
    && $empty->evaluated === 0
    && $empty->skipped === 0
    && $empty->quarantined === []
    && $empty->failures === []
    && $empty->failuresCount === 0
    && $empty->contended === []
    && $empty->contendedCount === 0
    && $empty->requiresOperatorAttention() === false
    && $empty->hasContention() === false
);

$assert(
    '4.3 La cota por defecto del lote está declarada como constante del servicio (500)',
    SanitaryClockSweepService::DEFAULT_CANDIDATE_LIMIT === 500
);

$dtoSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Application/DTO/SanitaryClockSweepResultDto.php'
);
$assert(
    '4.4 El resumen es un DTO inmutable (`final readonly class`) y no un array suelto',
    $empty instanceof SanitaryClockSweepResultDto
        && str_contains($dtoSource, 'final readonly class SanitaryClockSweepResultDto'),
    'El DTO debe ser `final readonly class`'
);

// =====================================================================
// GRUPO 5: Contrato de vocabulario con el reloj y la bitácora
// =====================================================================
echo "\n--- Grupo 5: Contrato de vocabulario ---\n";

$assert(
    '5.1 El barrido comparte el umbral del reloj (no hay una segunda copia del límite de 4 h)',
    SanitaryClockSweepService::DEFAULT_CANDIDATE_LIMIT > 0
    && IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS === 14400
);

$serviceSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Application/Service/SanitaryClockSweepService.php'
);

$assert(
    '5.2 El servicio delega la decisión en el reloj y no escribe estados por su cuenta',
    !str_contains($serviceSource, 'updateSanitaryStatus')
    && str_contains($serviceSource, 'evaluateSanitaryBiologicalClock'),
    'El barrido debe limitarse a seleccionar candidatos y consumir el reloj'
);

$assert(
    '5.3 El barrido respeta el aislamiento por candidato y la unidad de trabajo del proyecto',
    str_contains($serviceSource, 'runInTransaction')
    && str_contains($serviceSource, 'SanitaryClockCandidateRepositoryInterface')
);

// =====================================================================
// GRUPO 6: Contención de fila frente a fallo real (T-PAUSE-39)
// =====================================================================
echo "\n--- Grupo 6: Contención de fila y fallo real ---\n";

// 6.1/6.2 La máquina cuya transición muere esperando el bloqueo que otro consumidor
// sostiene (el `1205` real de MariaDB) se APLAZA: no es un fallo, no alerta y no se toca.
$bench = $makeBench();
$bench['settings']->register(10, 'PERISHABLE_FOOD');
$bench['settings']->register(11, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [
    $makeIncident(160, 'INC-2026-00160', 10, $agedOpening(18000)),
    $makeIncident(161, 'INC-2026-00161', 11, $agedOpening(18000)),
];
$bench['transactions']->throwPdoOnTransaction = 2;
$contended = $bench['service']->sweep();

$assert(
    '6.1 La transición que muere esperando el bloqueo ajeno (1205) se aplaza en lugar de contarse como fallo',
    $contended->contendedCount === 1
    && $contended->contended[0] === [
        'incident_id' => 161,
        'ticket_code' => 'INC-2026-00161',
        'reason' => 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
    ]
    && $contended->failures === []
    && $contended->failuresCount === 0
    && $contended->candidates === 2
    && $contended->evaluated === 1
    && $contended->evaluated + $contended->failuresCount + $contended->contendedCount === $contended->candidates,
    json_encode($contended->toArray(), JSON_UNESCAPED_UNICODE)
);

$assert(
    '6.2 La contención no alerta al planificador y deja la máquina aplazada intacta',
    $contended->requiresOperatorAttention() === false
    && $contended->hasContention() === true
    && ($bench['settings']->machines[11]['sanitary_status'] ?? null) === 'OK'
    && $bench['settings']->statusWrites === [['machine_id' => 10, 'status' => 'QUARANTINE']]
    && count($bench['audit']->events) === 1,
    json_encode($contended->toArray(), JSON_UNESCAPED_UNICODE)
);

// 6.3 El deadlock (1213 con `SQLSTATE 40001`) es la otra cara del mismo estado de bloqueo.
$bench = $makeBench();
$bench['settings']->register(12, 'COMBO');
$bench['candidates']->candidates = [$makeIncident(162, 'INC-2026-00162', 12, $agedOpening(18000), 'COMBO')];
$bench['transactions']->throwPdoOnTransaction = 1;
$bench['transactions']->pdoErrorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
$deadlock = $bench['service']->sweep();

$assert(
    '6.3 El deadlock (1213 / SQLSTATE 40001) también se aplaza: mismo estado de bloqueo, otro código',
    $deadlock->contendedCount === 1
    && $deadlock->failuresCount === 0
    && str_contains((string)$deadlock->contended[0]['reason'], 'Deadlock')
    && $deadlock->requiresOperatorAttention() === false,
    json_encode($deadlock->toArray(), JSON_UNESCAPED_UNICODE)
);

// 6.4 Fail-safe: un error de persistencia ajeno al bloqueo —aquí una tabla ausente— sigue
// siendo un FALLO REAL aunque su mensaje se parezca al de un bloqueo. La clasificación se
// lee del código estructurado, nunca del texto.
$bench = $makeBench();
$bench['settings']->register(13, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [$makeIncident(163, 'INC-2026-00163', 13, $agedOpening(18000))];
$bench['transactions']->throwPdoOnTransaction = 1;
$bench['transactions']->pdoErrorInfo = ['42S02', 1146, "Table 'vendguard.machines' doesn't exist (Lock wait timeout.)"];
$notContention = $bench['service']->sweep();

$assert(
    '6.4 Fail-safe: un error de persistencia sin código de bloqueo se queda en fallos y sí alerta',
    $notContention->contendedCount === 0
    && $notContention->contended === []
    && $notContention->failuresCount === 1
    && $notContention->failures[0]['incident_id'] === 163
    && str_contains((string)$notContention->failures[0]['reason'], "doesn't exist")
    && $notContention->requiresOperatorAttention() === true,
    json_encode($notContention->toArray(), JSON_UNESCAPED_UNICODE)
);

// 6.5/6.6 Las dos naturalezas conviven en la misma pasada: el aplazamiento se publica, el
// fallo real enciende la alerta y la máquina que sí procede entra en cuarentena.
$bench = $makeBench();
$bench['settings']->register(14, 'PERISHABLE_FOOD');
$bench['settings']->register(15, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [
    $makeIncident(164, 'INC-2026-00164', 14, $agedOpening(18000)),
    $makeIncident(165, 'INC-2026-00165', 16, $agedOpening(18000), null),
    $makeIncident(166, 'INC-2026-00166', 15, $agedOpening(18000)),
];
$bench['transactions']->throwPdoOnTransaction = 1;
$mixed = $bench['service']->sweep();

$assert(
    '6.5 Aplazamiento y fallo real conviven en la misma pasada, con la identidad contable intacta',
    $mixed->candidates === 3
    && $mixed->evaluated === 1
    && $mixed->failuresCount === 1
    && $mixed->contendedCount === 1
    && count($mixed->quarantined) === 1
    && $mixed->quarantined[0]['machine_id'] === 15
    && $mixed->evaluated + $mixed->failuresCount + $mixed->contendedCount === $mixed->candidates
    && $mixed->requiresOperatorAttention() === true,
    json_encode($mixed->toArray(), JSON_UNESCAPED_UNICODE)
);

$assert(
    '6.6 Ni el fallo ni el aplazamiento abortan el lote: la máquina que sí procede entra en cuarentena',
    ($bench['settings']->machines[15]['sanitary_status'] ?? null) === 'QUARANTINE'
    && ($bench['settings']->machines[14]['sanitary_status'] ?? null) === 'OK'
    && count($bench['audit']->events) === 1,
    json_encode($mixed->toArray(), JSON_UNESCAPED_UNICODE)
);

// 6.7/6.8 La identidad contable del resumen se verifica en el propio DTO: un recuento que no
// cuadra no puede llegar al planificador vestido de pasada válida.
$incoherent = $capture(static fn () => new SanitaryClockSweepResultDto(
    candidates: 3,
    evaluated: 2,
    quarantined: [],
    skipped: 2,
    failures: [],
    failuresCount: 0,
    contended: [],
    contendedCount: 0,
    thresholdSeconds: 14400,
    durationMs: 1.0,
));

$assert(
    '6.7 Un resumen que no cuadra se rechaza en el constructor en lugar de publicarse como válido',
    $incoherent['threw'] === true && $incoherent['class'] === InvalidArgumentException::class,
    $incoherent['message']
);

$unmirrored = $capture(static fn () => new SanitaryClockSweepResultDto(
    candidates: 1,
    evaluated: 0,
    quarantined: [],
    skipped: 0,
    failures: [],
    failuresCount: 0,
    contended: [],
    contendedCount: 1,
    thresholdSeconds: 14400,
    durationMs: 1.0,
));

$assert(
    '6.8 El espejo de la contención también se verifica: no se declara un aplazamiento que no se publica',
    $unmirrored['threw'] === true && $unmirrored['class'] === InvalidArgumentException::class,
    $unmirrored['message']
);

// 6.9 La regla de alerta no depende del volumen del lote: veinte aplazamientos por contención
// —la transición muriendo en el `UPDATE` real del repositorio, que es por donde llega el
// bloqueo en producción— no encienden al planificador.
$wideBench = $makeBench();
for ($machineId = 100; $machineId < 120; $machineId++) {
    $wideBench['settings']->register($machineId, 'PERISHABLE_FOOD');
    $wideBench['candidates']->candidates[] = $makeIncident(
        200 + $machineId,
        sprintf('INC-2026-%05d', 200 + $machineId),
        $machineId,
        $agedOpening(18000)
    );
}
$wideBench['settings']->failWrites = true;
$wide = $wideBench['service']->sweep();

$assert(
    '6.9 Veinte aplazamientos sin fallos no encienden la alerta (la contención no es un problema)',
    $wide->candidates === 20
    && $wide->contendedCount === 20
    && $wide->failuresCount === 0
    && $wide->requiresOperatorAttention() === false
    && $wide->hasContention() === true,
    json_encode(['contended' => $wide->contendedCount, 'failures' => $wide->failuresCount], JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 7: Cota de espera de bloqueo de la pasada (T-PAUSE-40)
// =====================================================================
echo "\n--- Grupo 7: Cota de espera de bloqueo de la pasada ---\n";

$bench = $makeBench();
$bench['settings']->register(10, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [$makeIncident(170, 'INC-2026-00170', 10, $agedOpening(18000))];

$budgeted = $bench['service']->sweep();

$assert(
    '7.1 La pasada entera corre dentro de la cota de espera de bloqueo declarada por el servicio',
    $bench['budget']->budgets === [SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS]
    && $bench['budget']->entered === 1
    && $budgeted instanceof SanitaryClockSweepResultDto
    && count($budgeted->quarantined) === 1,
    json_encode($bench['budget']->budgets, JSON_UNESCAPED_UNICODE)
);

$assert(
    '7.2 La cota es corta y está por debajo del valor de servidor (50 s): la pasada no se queda esperando',
    SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS >= 1
    && SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS < 50,
    (string)SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS . ' s'
);

$guardBench = $makeBench();
$badBudget = $capture(static fn () => $guardBench['service']->sweep(0));

$assert(
    '7.3 Una cota de lote imposible se rechaza ANTES de tocar la sesión (Fail-Fast)',
    $badBudget['threw'] === true
    && $badBudget['class'] === InvalidArgumentException::class
    && $guardBench['budget']->budgets === [],
    sprintf('cota recibida por el puerto: %s', json_encode($guardBench['budget']->budgets, JSON_UNESCAPED_UNICODE))
);

$explodingBench = $makeBench();
$explodingBench['candidates']->candidates = [$makeIncident(171, 'INC-2026-00171', 10, $agedOpening(18000))];
$explodingBench['transactions']->throwOnTransaction = 1;
$exploded = $capture(static fn () => $explodingBench['service']->sweep());

$assert(
    '7.4 Un fallo dentro de la pasada se propaga con la cota ya restituida (la sesión no queda tocada)',
    $exploded['threw'] === true
    && $exploded['class'] === LogicException::class
    && $explodingBench['budget']->entered === 1
    && $explodingBench['budget']->restored === true,
    sprintf('excepción=%s, restituida=%s', $exploded['class'], var_export($explodingBench['budget']->restored, true))
);

$sweepSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Application/Service/SanitaryClockSweepService.php'
);

$assert(
    '7.5 El barrido conserva la cota como dependencia declarada y no la aplica por su cuenta',
    str_contains($sweepSource, 'LockWaitBudgetInterface')
    && str_contains($sweepSource, 'runWithLockWaitBudget')
    && !str_contains($sweepSource, 'innodb_lock_wait_timeout'),
    'El servicio debe depender del puerto, no ejecutar SET SESSION él mismo'
);

// =====================================================================
// GRUPO 8: Libro de la pasada y seguimiento de la contención (T-PAUSE-41)
// =====================================================================
echo "\n--- Grupo 8: Libro de pasadas y escalado de la contención ---\n";

// 8.1/8.2 Cada pasada deja su libro con los recuentos EXACTOS del resumen y cada
// aplazamiento abre su seguimiento; el candidato que sí se evalúa no abre ninguno.
$bench = $makeBench();
$bench['settings']->register(10, 'PERISHABLE_FOOD');
$bench['settings']->register(11, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [
    $makeIncident(180, 'INC-2026-00180', 10, $agedOpening(18000)),
    $makeIncident(181, 'INC-2026-00181', 11, $agedOpening(18000)),
];
$bench['transactions']->throwPdoOnTransaction = 2;
$recorded = $bench['service']->sweep();

$assert(
    '8.1 La pasada queda en el libro con los recuentos del resumen y su duración medida',
    count($bench['visibility']->passes) === 1
    && $bench['visibility']->passes[0]->candidates === $recorded->candidates
    && $bench['visibility']->passes[0]->evaluated === $recorded->evaluated
    && $bench['visibility']->passes[0]->quarantinedCount === count($recorded->quarantined)
    && $bench['visibility']->passes[0]->contendedCount === $recorded->contendedCount
    && $bench['visibility']->passes[0]->failuresCount === $recorded->failuresCount
    && $bench['visibility']->passes[0]->durationMs === $recorded->durationMs
    && $bench['visibility']->passes[0]->finishedAt !== null,
    json_encode($bench['visibility']->passes[0]->toArray(), JSON_UNESCAPED_UNICODE)
);

$contention = $bench['visibility']->contentions[181] ?? null;

$assert(
    '8.2 El aplazamiento abre su seguimiento (máquina y una pasada) y el candidato evaluado no abre nada',
    $contention instanceof SanitarySweepContention
    && $contention->machineId === 11
    && $contention->consecutivePasses === 1
    && $contention->totalPasses === 1
    && $contention->isEscalated() === false
    && $contention->isOpen() === true
    && !isset($bench['visibility']->contentions[180]),
    json_encode($contention?->toArray(new DateTimeImmutable('now', $madrid)) ?? [], JSON_UNESCAPED_UNICODE)
);

// 8.3 La persistencia no decide el escalado: lo hace la política del dominio, que es la
// única autoridad del umbral (el barrido sólo la consulta).
$assert(
    '8.3 El barrido consulta la política del escalado y no duplica su umbral',
    str_contains($sweepSource, 'SanitarySweepEscalationPolicy')
    && str_contains($sweepSource, 'nextDetection')
    && !str_contains($sweepSource, 'CONSECUTIVE_PASSES_THRESHOLD'),
    'El umbral debe vivir sólo en la política (T-PAUSE-41)'
);

// 8.4 Tres pasadas consecutivas del MISMO expediente aplazado: las dos primeras no escalan y
// la tercera sella el escalado. El seguimiento se reutiliza fila a fila, no se acumula.
$bench = $makeBench();
$bench['settings']->register(20, 'PERISHABLE_FOOD');
$bench['settings']->failWrites = true; // el reloj nunca puede escribir: siempre aplazado
$bench['candidates']->candidates = [$makeIncident(190, 'INC-2026-00190', 20, $agedOpening(18000))];

$firstPass = $bench['service']->sweep();
$secondPass = $bench['service']->sweep();
$secondState = $bench['visibility']->contentions[190];
$thirdPass = $bench['service']->sweep();
$thirdState = $bench['visibility']->contentions[190];

$assert(
    '8.4 La contención repetida acumula pasadas consecutivas y escala a la tercera',
    $firstPass->contendedCount === 1 && $secondPass->contendedCount === 1 && $thirdPass->contendedCount === 1
    && $secondState->consecutivePasses === 2
    && $secondState->isEscalated() === false
    && $thirdState->consecutivePasses === 3
    && $thirdState->isEscalated() === true
    && $thirdState->escalatedAt !== null
    && $thirdState->totalPasses === 3,
    sprintf(
        'estados: %d no escalado=%s, %d escalado=%s, escalated_at=%s',
        $secondState->consecutivePasses,
        var_export($secondState->isEscalated(), true),
        $thirdState->consecutivePasses,
        var_export($thirdState->isEscalated(), true),
        (string)$thirdState->escalatedAt
    )
);

// 8.5 La pasada que ya NO aplaza cierra el seguimiento, y el aplazamiento posterior arranca un
// episodio nuevo: contadores del episodio a 1 y acumulado histórico intacto.
$bench['settings']->failWrites = false;
$recovered = $bench['service']->sweep();
$closedState = $bench['visibility']->contentions[190];
$closingResolution = $bench['visibility']->resolutions[count($bench['visibility']->resolutions) - 1];

$assert(
    '8.5 Una pasada que ya no aplaza cierra el seguimiento y la alerta desaparece sola',
    $recovered->contendedCount === 0
    && $closedState->isOpen() === false
    && $bench['visibility']->findOpenContentions() === []
    && $closingResolution['detected'] === [],
    sprintf(
        'resoluciones: %s',
        json_encode($bench['visibility']->resolutions, JSON_UNESCAPED_UNICODE)
    )
);

// La máquina volvió a servicio (reinspección del módulo 05) y un nuevo escritor la retiene: el
// reloj vuelve a aplazarla. El retroceso a `OK` es necesario para que el escenario sea posible:
// con la máquina ya en cuarentena el reloj confirma la cuarentena sin escribir (compare-and-set)
// y no habría contención que seguir.
$bench['settings']->machines[20]['sanitary_status'] = 'OK';
$bench['settings']->failWrites = true;
$bench['service']->sweep();
$newEpisode = $bench['visibility']->contentions[190];

$assert(
    '8.6 El aplazamiento posterior abre episodio nuevo (contadores a 1) sin perder el acumulado histórico',
    $newEpisode->consecutivePasses === 1
    && $newEpisode->totalPasses === 4
    && $newEpisode->isEscalated() === false
    && $newEpisode->escalatedAt === null
    && $newEpisode->isOpen() === true,
    sprintf(
        'episodio: %d pasadas consecutivas, %d acumuladas, escalado=%s',
        $newEpisode->consecutivePasses,
        $newEpisode->totalPasses,
        var_export($newEpisode->isEscalated(), true)
    )
);

// 8.7 El libro se escribe DESPUÉS de consumir el reloj (Art. II): si la observabilidad falla, la
// cuarentena ya está escrita, el fallo se propaga en voz alta y la pasada deja de publicarse.
$bench = $makeBench();
$bench['settings']->register(30, 'PERISHABLE_FOOD');
$bench['candidates']->candidates = [$makeIncident(200, 'INC-2026-00200', 30, $agedOpening(18000))];
$bench['visibility']->failOnRecordPass = true;
$observabilityFailure = $capture(static fn () => $bench['service']->sweep());

$assert(
    '8.7 Si el libro no se puede escribir, la cuarentena ya está escrita y el fallo se propaga (Art. II)',
    $observabilityFailure['threw'] === true
    && $observabilityFailure['class'] === RuntimeException::class
    && str_contains($observabilityFailure['message'], 'observabilidad')
    && $bench['settings']->statusWrites === [['machine_id' => 30, 'status' => 'QUARANTINE']]
    && $bench['visibility']->passes === [],
    sprintf(
        'excepción=%s, escrituras=%s',
        $observabilityFailure['message'],
        json_encode($bench['settings']->statusWrites, JSON_UNESCAPED_UNICODE)
    )
);

// 8.8 El cierre es por identidad: la pasada cierra exactamente lo que ella NO aplazó, y deja
// intacto el seguimiento del expediente que sí volvió a aplazar en esa misma pasada.
$bench = $makeBench();
$bench['settings']->register(40, 'PERISHABLE_FOOD');
$bench['settings']->register(41, 'PERISHABLE_FOOD');
$bench['settings']->failWrites = true;
$bench['candidates']->candidates = [
    $makeIncident(210, 'INC-2026-00210', 40, $agedOpening(18000)),
    $makeIncident(211, 'INC-2026-00211', 41, $agedOpening(18000)),
];
$bench['service']->sweep();

// Segunda pasada: solo el 210 vuelve a aplazarse (el 211 deja de ser candidato).
$bench['candidates']->candidates = [$makeIncident(210, 'INC-2026-00210', 40, $agedOpening(18000))];
$bench['service']->sweep();

$secondResolution = $bench['visibility']->resolutions[1];

$assert(
    '8.8 El cierre es por identidad: la pasada cierra lo que no aplazó y conserva el seguimiento vivo',
    count($bench['visibility']->resolutions) === 2
    && $secondResolution['detected'] === [210]
    && ($bench['visibility']->contentions[210] ?? null)?->isOpen() === true
    && ($bench['visibility']->contentions[211] ?? null)?->isOpen() === false
    && (($bench['visibility']->contentions[210] ?? null)?->consecutivePasses ?? 0) === 2,
    json_encode($bench['visibility']->resolutions, JSON_UNESCAPED_UNICODE)
);

// 8.9 El rastro del aplazamiento no puede convertir un aplazamiento en un fallo: si la propia
// escritura de la observación choca con el MISMO bloqueo que provocó el aplazamiento, la pasada
// sigue (el expediente ya viaja en `contended` con su motivo y su aplazamiento ya cuenta en el
// libro) y el prefiltro vuelve a ofrecerlo en la pasada siguiente. Sin esta tolerancia, un
// solape legítimo terminaba en una alerta roja del planificador: el falso positivo que anula
// T-PAUSE-39.
$bench = $makeBench();
$bench['settings']->register(50, 'PERISHABLE_FOOD');
$bench['settings']->failWrites = true;
$bench['candidates']->candidates = [$makeIncident(220, 'INC-2026-00220', 50, $agedOpening(18000))];
$bench['visibility']->failOnSaveWithLock = true;
$blockedFollowUp = $bench['service']->sweep();

$assert(
    '8.9 Un seguimiento que no se puede escribir por el mismo bloqueo no enciende la alerta ni pierde el aplazamiento',
    $blockedFollowUp->contendedCount === 1
    && $blockedFollowUp->failuresCount === 0
    && $blockedFollowUp->requiresOperatorAttention() === false
    && $blockedFollowUp->contended[0]['incident_id'] === 220
    && count($bench['visibility']->passes) === 1
    && $bench['visibility']->passes[0]->contendedCount === 1
    && $bench['visibility']->contentions === [],
    json_encode($blockedFollowUp->toArray(), JSON_UNESCAPED_UNICODE)
);

// 8.10 Fail-safe de la observación: un fallo que NO es un bloqueo —tabla ausente, disco lleno—
// sí viaja en voz alta, igual que el libro de la pasada. La tolerancia de 8.9 se limita al mismo
// bloqueo que ya se clasificó como aplazamiento.
$bench = $makeBench();
$bench['settings']->register(51, 'PERISHABLE_FOOD');
$bench['settings']->failWrites = true;
$bench['candidates']->candidates = [$makeIncident(221, 'INC-2026-00221', 51, $agedOpening(18000))];
$bench['visibility']->failOnSaveGeneric = true;
$brokenFollowUp = $capture(static fn () => $bench['service']->sweep());

$assert(
    '8.10 Un fallo de observabilidad que no es bloqueo sí se propaga (la tolerancia no es una mordaza)',
    $brokenFollowUp['threw'] === true
    && $brokenFollowUp['class'] === RuntimeException::class
    && str_contains($brokenFollowUp['message'], 'seguimiento'),
    $brokenFollowUp['message']
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-38 (UNIDAD) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
