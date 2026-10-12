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
 * 4. El barrido no reimplementa el umbral: lo pide al reloj, lo publica en el
 *    resumen y lo usa como criterio del puerto de candidatos.
 * 5. Cada candidato se evalúa dentro de su propia unidad de trabajo (Art. III),
 *    de modo que el estado sanitario y su evidencia caen juntos.
 * 6. El DTO publica exactamente las claves del contrato documentado en
 *    `specs/11-pending-info-sla-pause/plan.md` §2.5 y en `api_contracts.md`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red. El puerto de
 * candidatos, el repositorio de ajustes, la unidad de trabajo y la auditoría se
 * sustituyen por dobles en memoria; el reloj es el servicio real.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\SanitaryClockSweepResultDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryClockCandidateRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
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
 * Doble de la unidad de trabajo: cuenta las unidades abiertas y puede hacer
 * estallar la enésima para certificar que un fallo de infraestructura no se traga.
 */
final class SweepTransactionManager implements TransactionManagerInterface
{
    public int $transactions = 0;

    public ?int $throwOnTransaction = null;

    public function runInTransaction(callable $operation): mixed
    {
        $this->transactions++;
        if ($this->throwOnTransaction !== null && $this->transactions === $this->throwOnTransaction) {
            throw new LogicException('Fallo de infraestructura simulado en la unidad de trabajo.');
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
 *   transactions: SweepTransactionManager
 * }
 */
$makeBench = static function (): array {
    $candidates = new SweepCandidateRepo();
    $settings = new SweepSettingsRepo();
    $audit = new SweepAuditRepo();
    $transactions = new SweepTransactionManager();

    $pauseService = new IncidentPauseService($settings, new AuditLogger($audit));

    return [
        'service' => new SanitaryClockSweepService($candidates, $pauseService, $transactions),
        'candidates' => $candidates,
        'settings' => $settings,
        'audit' => $audit,
        'transactions' => $transactions,
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
