<?php

declare(strict_types=1);

/**
 * IncidentPauseReactivationTest
 *
 * Suite unitaria del Algoritmo 2 del módulo 11 (tarea T-PAUSE-08): la reactivación
 * condicional inteligente tras un comentario público de la sede (RF-02.1, RF-05.3,
 * Constitución Art. V.1). Certifica:
 *
 * 1. Respuesta en caliente (< 60 min) con técnico libre y sin reasignación: la avería
 *    vuelve a `IN_PROGRESS` y el reloj contractual se desplaza en horario comercial.
 * 2. Respuesta desfasada (≥ 60 min): vuelve a `ASSIGNED` para un inicio presencial.
 * 3. El técnico ocupado en otra intervención en curso o una reasignación durante la
 *    pausa también devuelven la avería a `ASSIGNED`, aunque la respuesta sea inmediata:
 *    el sistema nunca afirma que alguien está delante de una máquina si no lo está.
 * 4. Una avería sin técnico responsable se reactiva a `ASSIGNED` (caso límite §6.4).
 * 5. Idempotencia ante ráfagas: comentarios sin sustancia, expedientes ya reanudados o
 *    mensajes repetidos no provocan escrituras ni dobles contabilizaciones de pausa.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red. El repositorio de
 * incidencias se sustituye por un doble en memoria que registra cada escritura.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\IncidentPauseResponseDto;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseReactivationTest (T-PAUSE-08)\n";
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
 * Doble en memoria del repositorio de incidencias: guarda el expediente vivo, el
 * historial que el servicio consulta y registra cada escritura para poder certificar
 * que la ráfaga no duplica nada.
 */
final class ReactivationIncidentRepo implements IncidentRepositoryInterface
{
    public ?Incident $incident = null;

    /** @var list<IncidentHistory> */
    public array $history = [];

    /** @var list<Incident> */
    public array $technicianIncidents = [];

    public int $updateCalls = 0;

    /** @var list<array<string, mixed>> */
    public array $resumeEvents = [];

    public bool $failUpdate = false;

    public function reset(): void
    {
        $this->updateCalls = 0;
        $this->resumeEvents = [];
    }

    public function findById(int $id): ?Incident
    {
        return ($this->incident !== null && $this->incident->getId() === $id) ? $this->incident : null;
    }

    public function update(Incident $incident): bool
    {
        if ($this->failUpdate) {
            return false;
        }

        $this->updateCalls++;
        $this->incident = $incident;

        return true;
    }

    public function getHistory(int $incidentId): array
    {
        return $this->history;
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return array_values(array_filter(
            $this->technicianIncidents,
            static fn (Incident $incident): bool => $incident->getAssignedTechnicianId() === $technicianId
                && ($statuses === [] || in_array($incident->getStatus()->value, $statuses, true))
        ));
    }

    public function recordResumeEvent(
        int $incidentId,
        ?int $userId,
        IncidentStatus $targetStatus,
        int $pauseDurationSeconds,
        ?string $note,
        DateTimeImmutable $resumedAt
    ): void {
        $this->resumeEvents[] = [
            'incident_id' => $incidentId,
            'user_id' => $userId,
            'target_status' => $targetStatus->value,
            'pause_duration_seconds' => $pauseDurationSeconds,
            'note' => $note,
            'resumed_at' => $resumedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return true; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null, ?string $reassignmentReason = null): Incident { throw new LogicException('No usado.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException('No usado.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException('No usado.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException('No usado.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException('No usado.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException('No usado.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, IncidentPauseReasonCategory $category, string $reasonText, DateTimeImmutable $pausedAt): void { throw new LogicException('No usado.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { return []; }
}


/**
 * Construye la avería pausada de trabajo. Las marcas temporales se generan con la zona
 * horaria por defecto de PHP, que es la misma que usa
 * `Incident::currentPauseDurationSeconds()` al interpretar `paused_at`.
 */
$makePausedIncident = static function (
    int $pausedMinutesAgo = 20,
    ?string $slaTargetAt = null,
    ?int $technicianId = 7,
    int $totalPendingInfoSeconds = 0
): Incident {
    $now = time();

    return new Incident(
        id: 142,
        ticketCode: 'INC-2026-00142',
        machineId: 10,
        locationId: 1,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Cámara de frío sin refrigeración y producto fresco expuesto al público.',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::PENDING_INFO,
        assignedTechnicianId: $technicianId,
        assignedAt: date('Y-m-d H:i:s', $now - 7200),
        machineType: 'PERISHABLE_FOOD',
        createdAt: date('Y-m-d H:i:s', $now - 10800),
        pendingInfoReasonCategory: IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
        pendingInfoReasonText: 'El edificio estaba cerrado sin acceso a la sala de máquinas.',
        pausedAt: date('Y-m-d H:i:s', $now - ($pausedMinutesAgo * 60)),
        totalPendingInfoSeconds: $totalPendingInfoSeconds,
        slaTargetAt: $slaTargetAt
    );
};

/** Incidente auxiliar para poblar la ruta del técnico. */
$makeRouteIncident = static function (int $id, IncidentStatus $status, int $technicianId): Incident {
    return new Incident(
        id: $id,
        ticketCode: 'INC-2026-00' . $id,
        machineId: 20,
        locationId: 2,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Monedero bloqueado y sin devolución de cambio al usuario.',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: $technicianId,
        assignedAt: date('Y-m-d H:i:s', time() - 3600),
        machineType: 'SNACKS',
        createdAt: date('Y-m-d H:i:s', time() - 7200)
    );
};

$validComment = 'Buenos días, la sala ya está abierta y el conserje tiene la llave.';

// =====================================================================
// GRUPO 1: Respuesta en caliente -> IN_PROGRESS
// =====================================================================
echo "--- Grupo 1: Reactivación en caliente (RF-02.1) ---\n";

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$service = new IncidentPauseService(incidentRepo: $repo);

$hotResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);
$hotEvent = $repo->resumeEvents[0] ?? [];
$hotDuration = (int)($hotEvent['pause_duration_seconds'] ?? 0);
$hotAccumulated = $repo->incident?->getTotalPendingInfoSeconds() ?? 0;

$assert(
    '1.1 Una respuesta de sede a los 20 minutos reanuda la avería en curso',
    $hotResponse instanceof IncidentPauseResponseDto
    && $hotResponse->status === IncidentStatus::IN_PROGRESS
    && $hotResponse->isSlaPaused === false,
    $hotResponse->status->value
);

$assert(
    '1.2 La pausa viva queda cerrada y sus segundos se acumulan en el expediente',
    $repo->incident !== null
    && $repo->incident->isPaused() === false
    && $repo->incident->getPausedAt() === null
    && $hotAccumulated === $hotDuration
    && in_array($hotDuration, [1200, 1201, 1202], true),
    sprintf('acumulado=%d, duración=%d', $hotAccumulated, $hotDuration)
);

$assert(
    '1.3 Se persiste exactamente una transición y un rastro de reanudación',
    $repo->updateCalls === 1 && count($repo->resumeEvents) === 1,
    sprintf('updates=%d, eventos=%d', $repo->updateCalls, count($repo->resumeEvents))
);

$assert(
    '1.4 El rastro es el de `PENDING_INFO` -> `IN_PROGRESS` con la duración exacta',
    ($hotEvent['incident_id'] ?? null) === 142
    && ($hotEvent['target_status'] ?? null) === 'IN_PROGRESS'
    && ($hotEvent['pause_duration_seconds'] ?? null) === $hotAccumulated
);

$assert(
    '1.5 El actor del rastro es el sistema: el responsable de sede no es un usuario interno (Art. III)',
    array_key_exists('user_id', $hotEvent) && $hotEvent['user_id'] === null
);

$assert(
    '1.6 La nota deja trazabilidad del actor de sede sin contaminar el motivo de reasignación',
    is_string($hotEvent['note'] ?? null)
    && str_contains($hotEvent['note'], '#9001')
    && str_contains($hotEvent['note'], 'Respuesta en caliente')
    && !str_contains($hotEvent['note'], ' Motivo: ')
);

$expectedHotSla = $service->shiftSlaTargetInBusinessHours(
    new DateTimeImmutable('2026-10-08 12:15:00', new DateTimeZone('Europe/Madrid')),
    $hotDuration,
    1
)->format('Y-m-d H:i:s');

$assert(
    '1.7 El vencimiento contractual se desplaza en horario comercial con el Algoritmo 3',
    $hotResponse->slaTargetAtOriginal === '2026-10-08 12:15:00'
    && $hotResponse->slaTargetAtShifted === $expectedHotSla
    && $repo->incident?->getSlaTargetAt() === $expectedHotSla,
    sprintf('esperado=%s, obtenido=%s', $expectedHotSla, (string)$hotResponse->slaTargetAtShifted)
);

$assert(
    '1.8 El contrato de respuesta declara los minutos acumulados redondeados al minuto',
    $hotResponse->accumulatedPauseMinutes === (int)round($hotAccumulated / 60)
    && $hotResponse->accumulatedPauseMinutes === 20,
    (string)$hotResponse->accumulatedPauseMinutes
);

$assert(
    '1.9 Un comentario de exactamente 5 caracteres ya es respuesta válida (borde legal)',
    $service->handleSiteCommentReactivation(142, 'Vale,', 9001)->status === IncidentStatus::IN_PROGRESS
);

// =====================================================================
// GRUPO 2: Respuesta desfasada -> ASSIGNED
// =====================================================================
echo "\n--- Grupo 2: Reactivación desfasada (RF-02.1) ---\n";

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(90, '2026-10-08 12:15:00');
$service = new IncidentPauseService(incidentRepo: $repo);

$lateResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);
$lateEvent = $repo->resumeEvents[0] ?? [];

$assert(
    '2.1 Una respuesta a los 90 minutos devuelve la avería a asignada, no a en curso',
    $lateResponse->status === IncidentStatus::ASSIGNED && $lateResponse->isSlaPaused === false,
    $lateResponse->status->value
);

$assert(
    '2.2 El rastro declara el destino real y la espera acumulada de hora y media',
    ($lateEvent['target_status'] ?? null) === 'ASSIGNED'
    && in_array($lateEvent['pause_duration_seconds'] ?? 0, [5399, 5400, 5401, 5402], true),
    (string)($lateEvent['pause_duration_seconds'] ?? 'sin dato')
);

$assert(
    '2.3 La nota explica que la respuesta llegó fuera de la ventana de 60 minutos',
    is_string($lateEvent['note'] ?? null)
    && str_contains($lateEvent['note'], 'fuera de la ventana de 60 minutos')
);

$assert(
    '2.4 El contrato declara los minutos descontados al minuto más próximo (RNF-01)',
    in_array($lateResponse->accumulatedPauseMinutes, [90, 91], true),
    (string)$lateResponse->accumulatedPauseMinutes
);

$assert(
    '2.5 El SLA se desplaza igualmente: la espera de sede se descuenta siempre',
    $lateResponse->slaTargetAtShifted !== null
    && $lateResponse->slaTargetAtShifted !== $lateResponse->slaTargetAtOriginal
);

// =====================================================================
// GRUPO 3: Técnico ocupado en otra intervención
// =====================================================================
echo "\n--- Grupo 3: Técnico ocupado en otra avería ---\n";

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$repo->technicianIncidents = [$makeRouteIncident(201, IncidentStatus::IN_PROGRESS, 7)];
$service = new IncidentPauseService(incidentRepo: $repo);

$busyResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);

$assert(
    '3.1 Con el técnico trabajando en otra máquina, la avería vuelve a asignada aunque sea en caliente',
    $busyResponse->status === IncidentStatus::ASSIGNED,
    $busyResponse->status->value
);

$assert(
    '3.2 La nota cita la otra intervención en curso como causa del retorno',
    str_contains((string)($repo->resumeEvents[0]['note'] ?? ''), 'otra intervención en curso')
);

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$repo->technicianIncidents = [$makeRouteIncident(202, IncidentStatus::PENDING_PARTS, 7)];
$service = new IncidentPauseService(incidentRepo: $repo);

$assert(
    '3.3 Esperar un repuesto no es estar delante de una máquina: no bloquea la reanudación en curso',
    $service->handleSiteCommentReactivation(142, $validComment, 9001)->status === IncidentStatus::IN_PROGRESS
);

// =====================================================================
// GRUPO 4: Reasignación durante la pausa
// =====================================================================
echo "\n--- Grupo 4: Reasignación durante la pausa ---\n";

$reassignedRepo = new ReactivationIncidentRepo();
$reassignedRepo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$reassignedRepo->history = [
    new IncidentHistory(
        id: 5,
        incidentId: 142,
        userId: 3,
        fromStatus: 'PENDING_INFO',
        toStatus: 'PENDING_INFO',
        actionNote: 'Reasignación técnica: del técnico ID 7 al técnico ID 9. Motivo: vacaciones del titular.',
        createdAt: date('Y-m-d H:i:s', time() - 300)
    ),
];
$service = new IncidentPauseService(incidentRepo: $reassignedRepo);

$reassignedResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);

$assert(
    '4.1 Si la avería fue reasignada durante la pausa, vuelve a asignada aunque sea en caliente',
    $reassignedResponse->status === IncidentStatus::ASSIGNED,
    $reassignedResponse->status->value
);

$assert(
    '4.2 La nota cita la reasignación como causa del retorno',
    str_contains((string)($reassignedRepo->resumeEvents[0]['note'] ?? ''), 'reasignada durante la pausa')
);

$staleHistoryRepo = new ReactivationIncidentRepo();
$staleHistoryRepo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$staleHistoryRepo->history = [
    new IncidentHistory(
        id: 4,
        incidentId: 142,
        userId: 3,
        fromStatus: 'PENDING_INFO',
        toStatus: 'PENDING_INFO',
        actionNote: 'Reasignación técnica anterior al bloqueo.',
        createdAt: date('Y-m-d H:i:s', time() - 9000)
    ),
];
$service = new IncidentPauseService(incidentRepo: $staleHistoryRepo);

$assert(
    '4.3 Una reasignación anterior al inicio de la pausa no altera la regla de reactivación',
    $service->handleSiteCommentReactivation(142, $validComment, 9001)->status === IncidentStatus::IN_PROGRESS
);

// =====================================================================
// GRUPO 5: Avería sin técnico responsable
// =====================================================================
echo "\n--- Grupo 5: Avería sin técnico responsable ---\n";

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(20, '2026-10-08 12:15:00', null);
$service = new IncidentPauseService(incidentRepo: $repo);

$unassignedResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);

$assert(
    '5.1 Sin técnico asignado la avería no puede declararse en curso: vuelve a asignada (§6.4)',
    $unassignedResponse->status === IncidentStatus::ASSIGNED,
    $unassignedResponse->status->value
);

$assert(
    '5.2 La nota declara que la avería quedó sin técnico responsable',
    str_contains((string)($repo->resumeEvents[0]['note'] ?? ''), 'sin técnico responsable')
);

// =====================================================================
// GRUPO 6: Idempotencia ante ráfagas (RF-05.3)
// =====================================================================
echo "\n--- Grupo 6: Idempotencia ante ráfagas de comentarios ---\n";

$shortCommentRepo = new ReactivationIncidentRepo();
$shortCommentRepo->incident = $makePausedIncident(20, '2026-10-08 12:15:00', 7, 600);
$service = new IncidentPauseService(incidentRepo: $shortCommentRepo);

$shortCommentResponse = $service->handleSiteCommentReactivation(142, 'ok', 9001);

$assert(
    '6.1 Un comentario de 2 caracteres no reactiva nada y no escribe en base de datos',
    $shortCommentRepo->updateCalls === 0
    && $shortCommentRepo->resumeEvents === []
    && $shortCommentResponse->status === IncidentStatus::PENDING_INFO
    && $shortCommentResponse->isSlaPaused === true
);

$assert(
    '6.2 El contrato refleja el reloj congelado sumando los 10 min previos y los 20 vivos',
    $shortCommentResponse->pausedAt !== null
    && $shortCommentResponse->accumulatedPauseMinutes === 30
    && $shortCommentResponse->reasonCategory === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    (string)$shortCommentResponse->accumulatedPauseMinutes
);

$burstRepo = new ReactivationIncidentRepo();
$burstRepo->incident = $makePausedIncident(15, '2026-10-08 12:15:00');
$service = new IncidentPauseService(incidentRepo: $burstRepo);

$burstResponses = [
    $service->handleSiteCommentReactivation(142, 'La sala ya está abierta, pasa cuando quieras.', 9001),
    $service->handleSiteCommentReactivation(142, 'Te espero en conserjería.', 9001),
    $service->handleSiteCommentReactivation(142, '¿Necesitas algo más?', 9001),
];

$assert(
    '6.3 Una ráfaga de tres comentarios produce una sola transición y un solo rastro',
    $burstRepo->updateCalls === 1 && count($burstRepo->resumeEvents) === 1,
    sprintf('updates=%d, eventos=%d', $burstRepo->updateCalls, count($burstRepo->resumeEvents))
);

$assert(
    '6.4 Los mensajes posteriores devuelven el estado ya reanudado sin volver a escribir',
    array_map(static fn (IncidentPauseResponseDto $dto): string => $dto->status->value, $burstResponses) === [
        'IN_PROGRESS',
        'IN_PROGRESS',
        'IN_PROGRESS',
    ]
    && $burstResponses[2]->isSlaPaused === false
);

$settledRepo = new ReactivationIncidentRepo();
$settledRepo->incident = $makeRouteIncident(142, IncidentStatus::IN_PROGRESS, 7);
$service = new IncidentPauseService(incidentRepo: $settledRepo);

$assert(
    '6.5 Un comentario sobre un expediente que no está en pausa no dispara ninguna reanudación',
    $service->handleSiteCommentReactivation(142, $validComment, 9001)->status === IncidentStatus::IN_PROGRESS
    && $settledRepo->updateCalls === 0
    && $settledRepo->resumeEvents === []
);

// =====================================================================
// GRUPO 7: Robustez, contrato y validación Fail-Fast
// =====================================================================
echo "\n--- Grupo 7: Robustez y validación Fail-Fast ---\n";

$repo = new ReactivationIncidentRepo();
$repo->incident = $makePausedIncident(20, null);
$service = new IncidentPauseService(incidentRepo: $repo);

$noSlaResponse = $service->handleSiteCommentReactivation(142, $validComment, 9001);

$assert(
    '7.1 Una avería sin compromiso de SLA se reanuda igualmente y declara ambas fechas nulas',
    $noSlaResponse->status === IncidentStatus::IN_PROGRESS
    && $noSlaResponse->slaTargetAtOriginal === null
    && $noSlaResponse->slaTargetAtShifted === null
);

$missingRepo = new ReactivationIncidentRepo();
$service = new IncidentPauseService(incidentRepo: $missingRepo);
$missing = $capture(static fn () => $service->handleSiteCommentReactivation(999, $validComment, 9001));

$assert(
    '7.2 Un expediente inexistente se declara con excepción en lugar de fingir una reanudación',
    $missing['threw'] === true && $missing['class'] === DomainException::class,
    $missing['message']
);

$invalidId = $capture(static fn () => $service->handleSiteCommentReactivation(0, $validComment, 9001));
$invalidSiteActor = $capture(static fn () => $service->handleSiteCommentReactivation(142, $validComment, 0));

$assert(
    '7.3 Los identificadores inválidos se rechazan de inmediato',
    $invalidId['threw'] === true && $invalidId['class'] === InvalidArgumentException::class
    && $invalidSiteActor['threw'] === true && $invalidSiteActor['class'] === InvalidArgumentException::class
);

$failureRepo = new ReactivationIncidentRepo();
$failureRepo->incident = $makePausedIncident(20, '2026-10-08 12:15:00');
$failureRepo->failUpdate = true;
$service = new IncidentPauseService(incidentRepo: $failureRepo);
$failedPersist = $capture(static fn () => $service->handleSiteCommentReactivation(142, $validComment, 9001));

$assert(
    '7.4 Si la reanudación no se puede persistir se lanza excepción y no se sella el rastro',
    $failedPersist['threw'] === true
    && $failedPersist['class'] === RuntimeException::class
    && $failureRepo->resumeEvents === [],
    $failedPersist['message']
);

$assert(
    '7.5 Los umbrales contractuales quedan fijados por constantes verificables',
    IncidentPauseService::MIN_SITE_COMMENT_LENGTH === 5
    && IncidentPauseService::HOT_REACTIVATION_WINDOW_SECONDS === 3600
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-08 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
