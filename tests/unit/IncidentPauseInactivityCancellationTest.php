<?php

declare(strict_types=1);

/**
 * IncidentPauseInactivityCancellationTest
 *
 * Suite unitaria del Algoritmo 5 del módulo 11 (tarea T-PAUSE-09): la cancelación
 * por inactividad de sede, el bloqueo de la máquina y la protección de los
 * reintegros económicos (RF-04.2 a RF-04.6, Constitución Art. V.1 y Art. V.2).
 *
 * Certifica, en el orden en que la especificación los exige:
 *
 * 1. La justificación de la cancelación tiene al menos 20 caracteres REALES
 *    (recortados los espacios), tal y como fija `Algoritmo 5` (Art. V.1).
 * 2. El protocolo completo: la avería pasa a `CANCELLED`, la máquina queda
 *    bloqueada por falta de acceso (`BLOCKED_NO_ACCESS`, nunca "Operativa") y el
 *    reintegro se desvincula de la avería conservándose para liquidación central.
 * 3. Todo el protocolo es UNA unidad de trabajo: cualquier fallo deshace el
 *    conjunto, de modo que no puede quedar una máquina bloqueada por una avería
 *    que sí se canceló ni un ticket cancelado con una máquina en verde.
 * 4. La espera prolongada de cliente se mide en 72 horas HÁBILES (08:00-18:00,
 *    lunes a viernes) con umbral estricto (RF-04.2), no en horas naturales.
 * 5. La persistencia real (MariaDB) de las dos escrituras nuevas: la columna
 *    `machines.is_blocked_no_access` de la migración 017 y la desvinculación
 *    `refund_requests.incident_id = NULL`, incluida la idempotencia del bloqueo.
 *
 * El sellado de solo lectura del hilo tras la cancelación (RF-05.4) no exige
 * código nuevo: se certifica aquí llamando al mismo guardián que usa el portal
 * (`IncidentCommentService::acceptsNewComments()`), que ya rechaza `CANCELLED`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas. Las dependencias del
 * servicio se sustituyen por dobles en memoria que registran el orden exacto de
 * las escrituras; el último grupo trabaja contra MariaDB real.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseInactivityCancellationTest (T-PAUSE-09)\n";
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
 * Doble del repositorio de incidencias: sirve el expediente y registra la
 * cancelación. La transición real a `CANCELLED` la certifican el repositorio PDO
 * y el grupo 8 de esta misma suite contra MariaDB.
 */
final class InactivityIncidentRepo implements IncidentRepositoryInterface
{
    public ?Incident $incident = null;

    /** @var list<array{incident_id: int, reason: string, coordinator_id: int|null}> */
    public array $cancelCalls = [];

    /** @var list<string> */
    public array $callOrder = [];

    public bool $failCancel = false;

    public function findById(int $id): ?Incident
    {
        return ($this->incident !== null && $this->incident->getId() === $id) ? $this->incident : null;
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        $this->callOrder[] = 'cancel';
        $this->cancelCalls[] = [
            'incident_id' => $incidentId,
            'reason' => $cancellationReason,
            'coordinator_id' => $coordinatorId,
        ];

        if ($this->failCancel) {
            throw new RuntimeException('Fallo simulado al persistir la cancelación.');
        }

        return $this->incident ?? throw new RuntimeException('Sin expediente cargado.');
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function update(Incident $incident): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return true; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null, ?string $reassignmentReason = null): Incident { throw new LogicException('No usado.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException('No usado.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException('No usado.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException('No usado.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException('No usado.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, IncidentPauseReasonCategory $category, string $reasonText, DateTimeImmutable $pausedAt): void { throw new LogicException('No usado.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, DateTimeImmutable $resumedAt): void { throw new LogicException('No usado.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { return []; }
}

/**
 * Doble del repositorio de máquinas: registra el bloqueo por falta de acceso.
 */
final class InactivityMachineRepo implements MachineRepositoryInterface
{
    public ?Machine $machine = null;

    /** @var list<array{machine_id: int, ticket_code: string}> */
    public array $blockCalls = [];

    /** @var list<string> */
    public array $callOrder = [];

    public bool $failBlock = false;

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machine !== null && $this->machine->getId() === $id ? $this->machine : null;
    }

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        $this->callOrder[] = 'block';
        $this->blockCalls[] = ['machine_id' => $machineId, 'ticket_code' => $ticketCode];

        return !$this->failBlock;
    }

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new LogicException('No usado.'); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

/**
 * Doble del repositorio de reintegros: sirve los expedientes vinculados y
 * registra cada desvinculación.
 */
final class InactivityRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var list<RefundRequest> */
    public array $cases = [];

    /** @var list<array{id: int, status: string}> */
    public array $detachCalls = [];

    /** @var list<string> */
    public array $callOrder = [];

    public bool $failDetach = false;

    public function findRestrictedByIncident(int $incidentId): array
    {
        return array_values(array_filter(
            $this->cases,
            static fn (RefundRequest $case): bool => $case->getIncidentId() === $incidentId
        ));
    }

    public function detachFromIncident(int $id, RefundStatus $newStatus): bool
    {
        $this->callOrder[] = 'detach';
        $this->detachCalls[] = ['id' => $id, 'status' => $newStatus->value];

        return !$this->failDetach;
    }

    public function insert(RefundRequest $refundRequest): int { return 1; }
    public function findById(int $id): ?RefundRequest { return null; }
    public function findByTrackingToken(string $trackingToken): ?RefundRequest { return null; }
    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array { return []; }
    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countForCoordinator(array $filters = []): int { return 0; }
    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool { return true; }
    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool { return true; }
    public function deactivate(int $id): bool { return true; }
}

/**
 * Doble de la unidad de trabajo: certifica que el protocolo se ejecuta dentro de
 * UNA transacción y que un fallo la deshace.
 */
final class InactivityTransaction implements TransactionManagerInterface
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    /** @var list<RefundRequestRepositoryInterface> */
    public array $seenRefundRepos = [];

    public function runInTransaction(callable $operation): mixed
    {
        $this->begins++;

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->rollbacks++;
            throw $exception;
        }

        $this->commits++;

        return $result;
    }
}

/**
 * Doble del repositorio de auditoría: recoge los eventos inmutables emitidos.
 */
final class InactivityAuditRepo implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countEvents(array $filters = []): int { return 0; }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

/**
 * Avería pausada de trabajo (PENDING_INFO) lista para cancelarse por inactividad.
 */
$makePausedIncident = static function (
    int $id = 142,
    string $ticketCode = 'INC-2026-00142',
    int $machineId = 10,
    int $pausedMinutesAgo = 30,
    ?string $pausedAtOverride = null,
    IncidentStatus $status = IncidentStatus::PENDING_INFO
): Incident {
    $now = time();

    return new Incident(
        id: $id,
        ticketCode: $ticketCode,
        machineId: $machineId,
        locationId: 1,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Monedero bloqueado sin devolución de cambio al usuario del edificio.',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: 7,
        assignedAt: date('Y-m-d H:i:s', $now - 7200),
        machineType: 'SNACKS',
        createdAt: date('Y-m-d H:i:s', $now - 9000),
        pendingInfoReasonCategory: IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
        pendingInfoReasonText: 'El edificio estaba cerrado y el conserje no pudo facilitar la llave de acceso.',
        pausedAt: $pausedAtOverride ?? date('Y-m-d H:i:s', $now - ($pausedMinutesAgo * 60)),
        totalPendingInfoSeconds: 0
    );
};

/**
 * Expediente de reintegro vinculado a la avería 142.
 */
$makeRefundCase = static function (int $id, RefundStatus $status, int $incidentId = 142, bool $isActive = true): RefundRequest {
    return new RefundRequest(
        id: $id,
        incidentId: $incidentId,
        machineId: 10,
        locationId: 1,
        claimantName: 'Consumidor de prueba',
        claimantContact: '600000000',
        claimedAmount: 2.50,
        productAttempted: 'Café solo',
        compensationMethod: CompensationMethod::BIZUM,
        bizumPhone: '600000000',
        iban: null,
        pickupPin: null,
        trackingToken: 'token-' . $id,
        status: $status,
        isActive: $isActive
    );
};

$validReason = 'Cierre administrativo por inactividad y falta de acceso del cliente tras 72h hábiles.';

/**
 * Monta el servicio con dobles limpios y devuelve el cuarteto de colaboradores.
 *
 * @return array{service: IncidentPauseService, incidentRepo: InactivityIncidentRepo, machineRepo: InactivityMachineRepo, refundRepo: InactivityRefundRepo, transaction: InactivityTransaction, auditRepo: InactivityAuditRepo}
 */
$makeHarness = static function (Incident $incident, array $cases = []): array {
    $incidentRepo = new InactivityIncidentRepo();
    $incidentRepo->incident = $incident;

    $machineRepo = new InactivityMachineRepo();
    $machine = new Machine(
        id: $incident->getMachineId(),
        locationId: $incident->getLocationId(),
        code: 'VEND-0101',
        model: 'TestModel',
        machineType: MachineType::SNACKS,
        floorWing: 'Planta 1'
    );
    $machineRepo->machine = $machine;

    $refundRepo = new InactivityRefundRepo();
    $refundRepo->cases = $cases;

    $transaction = new InactivityTransaction();

    $auditRepo = new InactivityAuditRepo();

    $service = new IncidentPauseService(
        settingsRepo: null,
        auditLogger: new AuditLogger($auditRepo),
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo,
        refundRepo: $refundRepo,
        transactionManager: $transaction
    );

    return [
        'service' => $service,
        'incidentRepo' => $incidentRepo,
        'machineRepo' => $machineRepo,
        'refundRepo' => $refundRepo,
        'transaction' => $transaction,
        'auditRepo' => $auditRepo,
    ];
};

// =====================================================================
// GRUPO 1: Justificación obligatoria de 20 caracteres reales (Art. V.1)
// =====================================================================
echo "--- Grupo 1: Justificación obligatoria de 20 caracteres reales (Art. V.1) ---\n";

$harness = $makeHarness($makePausedIncident());

$result = $capture(fn () => $harness['service']->cancelByInactivity(0, 3, $validReason));
$assert(
    '1.1 Una incidencia sin identificador persistido se rechaza de inmediato',
    $result['threw'] && $result['class'] === InvalidArgumentException::class,
    $result['message']
);

$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 0, $validReason));
$assert(
    '1.2 La cancelación exige el coordinador que la ejecuta para la auditoría',
    $result['threw'] && $result['class'] === InvalidArgumentException::class,
    $result['message']
);

$nineteenCharacters = str_repeat('a', IncidentPauseService::MIN_CANCELLATION_REASON_LENGTH - 1);
$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, $nineteenCharacters));
$assert(
    '1.3 Un motivo de 19 caracteres reales se rechaza (Algoritmo 5)',
    $result['threw'] && str_contains($result['message'], '20 caracteres reales'),
    $result['message']
);

$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, '                             20                         '));
$assert(
    '1.4 Un motivo hecho solo de espacios no cuenta como justificación',
    $result['threw'] && $result['class'] === InvalidArgumentException::class,
    $result['message']
);

$assert(
    '1.5 Ninguna de las validaciones anteriores escribió en base de datos',
    $harness['incidentRepo']->cancelCalls === []
        && $harness['machineRepo']->blockCalls === []
        && $harness['refundRepo']->detachCalls === []
        && $harness['transaction']->begins === 0
);

$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, '          Cierre por inactividad prolongada          '));
$assert(
    '1.6 Los espacios sobrantes de los extremos no cuentan para el umbral',
    !$result['threw'],
    $result['message']
);

$assert(
    '1.7 El umbral legal queda fijado en una constante verificable',
    IncidentPauseService::MIN_CANCELLATION_REASON_LENGTH === 20,
    'Valor observado: ' . IncidentPauseService::MIN_CANCELLATION_REASON_LENGTH
);

// =====================================================================
// GRUPO 2: Protocolo completo dentro de una única transacción
// =====================================================================
echo "\n--- Grupo 2: Protocolo completo de cancelación (RF-04.3 a RF-04.5) ---\n";

$harness = $makeHarness($makePausedIncident(), [
    $makeRefundCase(501, RefundStatus::PENDING_INSPECTION),
]);
$harness['service']->cancelByInactivity(142, 3, $validReason);

$assert(
    '2.1 La avería se cancela con el motivo recortado y el coordinador responsable',
    $harness['incidentRepo']->cancelCalls === [[
        'incident_id' => 142,
        'reason' => $validReason,
        'coordinator_id' => 3,
    ]],
    json_encode($harness['incidentRepo']->cancelCalls) ?: ''
);

$assert(
    '2.2 La máquina se bloquea por falta de acceso citando el ticket causante',
    $harness['machineRepo']->blockCalls === [[
        'machine_id' => 10,
        'ticket_code' => 'INC-2026-00142',
    ]],
    json_encode($harness['machineRepo']->blockCalls) ?: ''
);

$assert(
    '2.3 El protocolo se ejecuta en el orden cancelar -> bloquear -> desvincular',
    $harness['incidentRepo']->callOrder === ['cancel']
        && $harness['machineRepo']->callOrder === ['block']
        && $harness['refundRepo']->callOrder === ['detach']
);

$assert(
    '2.4 Todo el protocolo vive en UNA sola transacción confirmada',
    $harness['transaction']->begins === 1
        && $harness['transaction']->commits === 1
        && $harness['transaction']->rollbacks === 0,
    sprintf('begins=%d commits=%d rollbacks=%d', $harness['transaction']->begins, $harness['transaction']->commits, $harness['transaction']->rollbacks)
);

$machineEvents = array_values(array_filter(
    $harness['auditRepo']->events,
    static fn (AuditEvent $event): bool => $event->getEntityType() === AuditEvent::ENTITY_MACHINE
));
$assert(
    '2.5 El bloqueo deja rastro inmutable de máquina (Art. III)',
    count($machineEvents) === 1 && $machineEvents[0]->getEntityId() === 10,
    (string)count($machineEvents)
);

$assert(
    '2.6 La auditoría declara la acción MACHINE_BLOCKED_NO_ACCESS',
    $machineEvents !== [] && $machineEvents[0]->getAction() === 'MACHINE_BLOCKED_NO_ACCESS',
    $machineEvents !== [] ? $machineEvents[0]->getAction() : 'sin evento'
);

$assert(
    '2.7 El evento declara que la máquina deja de estar activa y queda bloqueada',
    $machineEvents !== []
        && ($machineEvents[0]->getNewState()['operational_status'] ?? '') === 'BLOCKED_NO_ACCESS'
        && ($machineEvents[0]->getNewState()['is_active'] ?? true) === false
        && ($machineEvents[0]->getPreviousState()['operational_status'] ?? '') === 'ACTIVE_INCIDENT',
    json_encode($machineEvents !== [] ? $machineEvents[0]->getNewState() : []) ?: ''
);

$assert(
    '2.8 El evento de máquina queda atribuido al coordinador que cancela',
    $machineEvents !== []
        && $machineEvents[0]->getUserId() === 3
        && $machineEvents[0]->getUserRole() === UserRole::COORDINATOR->value,
    $machineEvents !== [] ? $machineEvents[0]->getUserRole() : ''
);

$refundEvents = array_values(array_filter(
    $harness['auditRepo']->events,
    static fn (AuditEvent $event): bool => $event->getEntityType() === AuditEvent::ENTITY_REFUND_REQUEST
));
$assert(
    '2.9 El reintegro deja rastro inmutable propio (Módulo 08, Art. III)',
    count($refundEvents) === 1
        && $refundEvents[0]->getEntityId() === 501
        && $refundEvents[0]->getAction() === 'REFUND_DETACHED_BY_INACTIVITY',
    $refundEvents !== [] ? $refundEvents[0]->getAction() : 'sin evento'
);

$assert(
    '2.10 La auditoría del reintegro declara el vínculo cortado (incident_id nulo)',
    $refundEvents !== []
        && ($refundEvents[0]->getPreviousState()['incident_id'] ?? null) === 142
        && array_key_exists('incident_id', $refundEvents[0]->getNewState())
        && $refundEvents[0]->getNewState()['incident_id'] === null,
    json_encode($refundEvents !== [] ? $refundEvents[0]->getNewState() : []) ?: ''
);

// =====================================================================
// GRUPO 3: Precondiciones de estado (solo se cancela una pausa real)
// =====================================================================
echo "\n--- Grupo 3: Precondiciones de estado (RF-04.3) ---\n";

$harness = $makeHarness($makePausedIncident(id: 999, status: IncidentStatus::ASSIGNED));
$result = $capture(fn () => $harness['service']->cancelByInactivity(999, 3, $validReason));
$assert(
    '3.1 Una avería que no está en pausa se rechaza en lugar de bloquear su máquina',
    $result['threw'] && $result['class'] === \VendGuard\Core\Domain\Exception\InvalidTransitionException::class,
    $result['message']
);

$assert(
    '3.2 El rechazo nombra el estado real del expediente',
    str_contains($result['message'], 'ASSIGNED'),
    $result['message']
);

$assert(
    '3.3 Un expediente no pausado no provoca ninguna escritura',
    $harness['incidentRepo']->cancelCalls === []
        && $harness['machineRepo']->blockCalls === []
        && $harness['refundRepo']->detachCalls === []
);

$harness = $makeHarness($makePausedIncident());
$result = $capture(fn () => $harness['service']->cancelByInactivity(404, 3, $validReason));
$assert(
    '3.4 Una incidencia inexistente se declara con excepción, no con un falso cierre',
    $result['threw'] && $result['class'] === DomainException::class,
    $result['message']
);

// =====================================================================
// GRUPO 4: Atomicidad — ningún estado a medias
// =====================================================================
echo "\n--- Grupo 4: Atomicidad del protocolo (Art. III) ---\n";

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(601, RefundStatus::PENDING_INSPECTION)]);
$harness['machineRepo']->failBlock = true;
$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, $validReason));
$assert(
    '4.1 Si el bloqueo de la máquina no se puede persistir, la cancelación falla',
    $result['threw'] && $result['class'] === RuntimeException::class,
    $result['message']
);

$assert(
    '4.2 Ese fallo deshace la transacción completa (la avería no queda cancelada a medias)',
    $harness['transaction']->rollbacks === 1 && $harness['transaction']->commits === 0,
    sprintf('rollbacks=%d commits=%d', $harness['transaction']->rollbacks, $harness['transaction']->commits)
);

$assert(
    '4.3 Un bloqueo fallido no llega a desvincular el reintegro',
    $harness['refundRepo']->detachCalls === []
);

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(701, RefundStatus::PENDING_INSPECTION)]);
$harness['refundRepo']->failDetach = true;
$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, $validReason));
$assert(
    '4.4 Si el reintegro no se puede desvincular, el cierre entero se deshace',
    $result['threw'] && $result['class'] === RuntimeException::class && $harness['transaction']->rollbacks === 1,
    $result['message']
);

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(801, RefundStatus::PENDING_INSPECTION)]);
$harness['incidentRepo']->failCancel = true;
$result = $capture(fn () => $harness['service']->cancelByInactivity(142, 3, $validReason));
$assert(
    '4.5 Un fallo al cancelar el ticket propaga la excepción original y deshace',
    $result['threw']
        && $result['class'] === RuntimeException::class
        && str_contains($result['message'], 'Fallo simulado')
        && $harness['transaction']->rollbacks === 1
        && $harness['machineRepo']->blockCalls === [],
    $result['message']
);

// =====================================================================
// GRUPO 5: Protección del reintegro (RF-04.5, Módulo 08)
// =====================================================================
echo "\n--- Grupo 5: Protección del reintegro del consumidor (RF-04.5) ---\n";

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(901, RefundStatus::PENDING_INSPECTION)]);
$harness['service']->cancelByInactivity(142, 3, $validReason);
$assert(
    '5.1 El expediente que esperaba la inspección técnica pasa a la bandeja de Coordinación',
    $harness['refundRepo']->detachCalls === [['id' => 901, 'status' => RefundStatus::REQUIRES_COORDINATOR_APPROVAL->value]],
    json_encode($harness['refundRepo']->detachCalls) ?: ''
);

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(902, RefundStatus::DEPOSITED_AT_RECEPTION)]);
$harness['service']->cancelByInactivity(142, 3, $validReason);
$assert(
    '5.2 El efectivo ya depositado con PIN de recogida conserva su camino',
    $harness['refundRepo']->detachCalls === [['id' => 902, 'status' => RefundStatus::DEPOSITED_AT_RECEPTION->value]],
    json_encode($harness['refundRepo']->detachCalls) ?: ''
);

$harness = $makeHarness($makePausedIncident(), [
    $makeRefundCase(903, RefundStatus::PENDING_CONTACT),
    $makeRefundCase(904, RefundStatus::PAID_DIGITAL),
]);
$harness['service']->cancelByInactivity(142, 3, $validReason);
$assert(
    '5.3 Un expediente pendiente de contacto y uno ya pagado no se reinician',
    $harness['refundRepo']->detachCalls === [
        ['id' => 903, 'status' => RefundStatus::PENDING_CONTACT->value],
        ['id' => 904, 'status' => RefundStatus::PAID_DIGITAL->value],
    ],
    json_encode($harness['refundRepo']->detachCalls) ?: ''
);

$harness = $makeHarness($makePausedIncident(), [$makeRefundCase(905, RefundStatus::PENDING_INSPECTION, isActive: false)]);
$harness['service']->cancelByInactivity(142, 3, $validReason);
$assert(
    '5.4 Un expediente dado de baja lógicamente no se toca',
    $harness['refundRepo']->detachCalls === []
);

$assert(
    '5.5 Los expedientes desvinculados quedan marcados como tal en el dominio',
    $makeRefundCase(906, RefundStatus::PENDING_INSPECTION)->isDetachedFromIncident() === false
);

$harness = $makeHarness($makePausedIncident());
$harness['service']->cancelByInactivity(142, 3, $validReason);
$assert(
    '5.6 Sin reintegros vinculados no se inventa auditoría de reintegro',
    array_filter(
        $harness['auditRepo']->events,
        static fn (AuditEvent $event): bool => $event->getEntityType() === AuditEvent::ENTITY_REFUND_REQUEST
    ) === []
);

// =====================================================================
// GRUPO 6: Espera prolongada de 72 horas HÁBILES (RF-04.2)
// =====================================================================
echo "\n--- Grupo 6: Espera prolongada de 72 horas hábiles (RF-04.2) ---\n";

$service = new IncidentPauseService();

// Lunes 5 de octubre de 2026 a las 09:00 (hora de sede). Las cadenas se interpretan
// en la zona horaria operativa, que es lo que hace `parseDateTime()`.
$mondayMorning = '2026-10-05 09:00:00';

$assert(
    '6.1 Una pausa de una hora hábil no es espera prolongada',
    $service->isProlongedInactivity(
        $makePausedIncident(pausedAtOverride: $mondayMorning),
        new DateTimeImmutable('2026-10-05 10:00:00', new DateTimeZone('Europe/Madrid'))
    ) === false
);

$assert(
    '6.2 Cuatro jornadas completas (48 h hábiles) siguen sin alcanzar el umbral',
    $service->isProlongedInactivity(
        $makePausedIncident(pausedAtOverride: $mondayMorning),
        new DateTimeImmutable('2026-10-09 17:00:00', new DateTimeZone('Europe/Madrid'))
    ) === false
);

$assert(
    '6.3 El fin de semana no computa: el sábado la espera hábil no avanza',
    $service->isProlongedInactivity(
        $makePausedIncident(pausedAtOverride: $mondayMorning),
        new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('Europe/Madrid'))
    ) === false
);

$assert(
    '6.4 Exactamente 72 horas hábiles no superan el umbral (la especificación pide MÁS de 72)',
    $service->isProlongedInactivity(
        $makePausedIncident(pausedAtOverride: $mondayMorning),
        new DateTimeImmutable('2026-10-14 11:00:00', new DateTimeZone('Europe/Madrid'))
    ) === false
);

$assert(
    '6.5 Un segundo más de las 72 horas hábiles enciende la alerta prioritaria',
    $service->isProlongedInactivity(
        $makePausedIncident(pausedAtOverride: $mondayMorning),
        new DateTimeImmutable('2026-10-14 11:00:01', new DateTimeZone('Europe/Madrid'))
    ) === true
);

$assert(
    '6.6 Una avería que no está en pausa nunca está en espera prolongada',
    $service->isProlongedInactivity(
        $makePausedIncident(status: IncidentStatus::ASSIGNED),
        new DateTimeImmutable('2026-10-30 12:00:00', new DateTimeZone('Europe/Madrid'))
    ) === false
);

$assert(
    '6.7 El umbral contractual queda fijado en una constante verificable',
    IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS === 72,
    'Valor observado: ' . IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS
);

// =====================================================================
// GRUPO 7: Vocabulario de auditoría (T-PAUSE-07 / AuditActionCatalogTest)
// =====================================================================
echo "\n--- Grupo 7: Vocabulario de auditoría ---\n";

$labelsPath = dirname(__DIR__, 2) . '/public/assets/js/utils/AuditActionLabels.js';
$labelsSource = (string)file_get_contents($labelsPath);

$assert(
    '7.1 La acción del bloqueo de máquina coincide con el literal auditado',
    IncidentPauseService::ACTION_MACHINE_BLOCKED_NO_ACCESS === 'MACHINE_BLOCKED_NO_ACCESS'
);

$assert(
    '7.2 La acción del bloqueo está catalogada en el visor del coordinador',
    str_contains($labelsSource, "code: '" . IncidentPauseService::ACTION_MACHINE_BLOCKED_NO_ACCESS . "'")
);

$assert(
    '7.3 La acción de desvinculación coincide con el literal auditado',
    IncidentPauseService::ACTION_REFUND_DETACHED_BY_INACTIVITY === 'REFUND_DETACHED_BY_INACTIVITY'
);

$assert(
    '7.4 La acción de desvinculación está catalogada con su etiqueta en castellano',
    str_contains($labelsSource, "code: '" . IncidentPauseService::ACTION_REFUND_DETACHED_BY_INACTIVITY . "'")
        && str_contains($labelsSource, 'Reintegro Desvinculado por Inactividad')
);

// =====================================================================
// GRUPO 8: Persistencia real en MariaDB (migración 017 y servicio completo)
// =====================================================================
echo "\n--- Grupo 8: Persistencia real en MariaDB (migración 017) ---\n";

$pdo = ConnectionFactory::getConnection();
$machineRepo = new PdoMachineRepository($pdo);
$refundRepo = new PdoRefundRequestRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);

// Sede y coordinador se toman del sembrado —son tablas maestras protegidas del
// limpiador compartido, que jamás las borra— y la máquina sí es propia de la suite,
// porque el bloqueo del test la deja fuera de servicio y hay que limpiarla.
$suffix = strtoupper(bin2hex(random_bytes(3)));

$locationId = (int)$pdo->query('SELECT id FROM locations WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$coordinatorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'COORDINATOR' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($coordinatorId === 0) {
    $coordinatorId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
}

$machineCode = 'TST-P09-' . $suffix;
$testMachine = $machineRepo->create([
    'location_id' => $locationId,
    'code' => $machineCode,
    'model' => 'Suite T-PAUSE-09',
    'machine_type' => 'SNACKS',
    'floor_wing' => 'Banco de pruebas',
    'notes' => 'Máquina de la suite de inactividad.',
]);

$testTicket = 'TST-P09-' . $suffix;
$pdo->prepare("
    INSERT INTO `incidents` (
        `ticket_code`, `machine_id`, `machine_type_snapshot`, `location_id`,
        `category`, `description`, `urgency`, `status`, `assigned_technician_id`, `assigned_at`,
        `pending_info_reason_category`, `pending_info_reason_text`, `paused_at`, `total_pending_info_seconds`
    ) VALUES (
        :ticket, :machine, 'SNACKS', :location,
        'PAYMENT_SYSTEM', 'Avería del banco de pruebas de inactividad de sede.', 'HIGH', 'PENDING_INFO', NULL, NULL,
        'BUILDING_CLOSED_NO_ACCESS', 'El edificio permaneció cerrado sin facilitar acceso al técnico.', NOW(), 0
    )
")->execute([
    ':ticket' => $testTicket,
    ':machine' => $testMachine->getId(),
    ':location' => $locationId,
]);
$testIncidentId = (int)$pdo->lastInsertId();

$refundToken = 'tst09-' . bin2hex(random_bytes(8));
$pdo->prepare("
    INSERT INTO `refund_requests` (
        `incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
        `claimed_amount`, `product_attempted`, `compensation_method`, `bizum_phone`,
        `tracking_token`, `status`
    ) VALUES (
        :incident, :machine, :location, 'Consumidor de la suite', '600000000',
        2.50, 'Café solo', 'BIZUM', '600000000',
        :token, 'PENDING_INSPECTION'
    )
")->execute([
    ':incident' => $testIncidentId,
    ':machine' => $testMachine->getId(),
    ':location' => $locationId,
    ':token' => $refundToken,
]);
$testRefundId = (int)$pdo->lastInsertId();

try {
    $service = new IncidentPauseService(
        settingsRepo: null,
        auditLogger: new AuditLogger(),
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo,
        refundRepo: $refundRepo,
        transactionManager: new PdoTransactionManager($pdo)
    );

    $service->cancelByInactivity($testIncidentId, $coordinatorId, 'Cancelación real certificada por la suite de inactividad de sede.');

    $blocked = $machineRepo->findById($testMachine->getId(), false);
    $assert(
        '8.1 La máquina queda bloqueada por falta de acceso en base de datos (RF-04.4)',
        $blocked !== null && $blocked->isBlockedNoAccess() === true && $blocked->isActive() === false,
        $blocked === null ? 'máquina no recuperada' : 'bloqueo no persistido'
    );

    $assert(
        '8.2 El estado operativo leído nunca vuelve a "Operativa" (Art. V.1)',
        $blocked !== null && $blocked->getOperationalStatus() === Machine::OPERATIONAL_STATUS_BLOCKED_NO_ACCESS,
        $blocked !== null ? $blocked->getOperationalStatus() : ''
    );

    $assert(
        '8.3 La nota de la máquina cita el ticket que motivó el bloqueo',
        $blocked !== null && str_contains((string)$blocked->getNotes(), $testTicket),
        $blocked !== null ? (string)$blocked->getNotes() : ''
    );

    $machineRepo->blockForNoAccess($testMachine->getId(), $testTicket);
    $stillBlocked = $machineRepo->findById($testMachine->getId(), false);
    $assert(
        '8.4 Repetir el bloqueo es idempotente: no duplica la anotación',
        $stillBlocked !== null
            && substr_count((string)$stillBlocked->getNotes(), $testTicket) === 1,
        $stillBlocked !== null ? (string)$stillBlocked->getNotes() : ''
    );

    $detached = $refundRepo->findById($testRefundId);
    $assert(
        '8.5 El reintegro real queda desvinculado de la avería (RF-04.5)',
        $detached !== null
            && $detached->isDetachedFromIncident() === true
            && $detached->isActive() === true,
        $detached === null ? 'expediente no recuperado' : 'vínculo no cortado'
    );

    $assert(
        '8.6 El reintegro desvinculado queda en la bandeja de Coordinación',
        $detached !== null && $detached->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
        $detached !== null ? $detached->getStatus()->value : ''
    );

    $assert(
        '8.7 La avería cancelada ya no lista el expediente entre los suyos',
        $refundRepo->findRestrictedByIncident($testIncidentId) === []
    );

    $cancelledRow = $incidentRepo->findById($testIncidentId);
    $assert(
        '8.8 El ticket queda formalmente cancelado con su motivo (Art. V.1)',
        $cancelledRow !== null
            && $cancelledRow->getStatus() === IncidentStatus::CANCELLED,
        $cancelledRow !== null ? $cancelledRow->getStatus()->value : 'no recuperado'
    );

    $reasonStored = (string)$pdo->query("SELECT cancellation_reason FROM incidents WHERE id = {$testIncidentId}")->fetchColumn();
    $assert(
        '8.9 El motivo queda sellado en el expediente para la auditoría',
        str_contains($reasonStored, 'suite de inactividad de sede'),
        $reasonStored
    );

    $history = $incidentRepo->getHistory($testIncidentId);
    $cancellationEvents = array_values(array_filter(
        $history,
        static fn (IncidentHistory $event): bool => $event->getToStatus() === IncidentStatus::CANCELLED->value
    ));
    $assert(
        '8.10 El rastro inmutable registra la transición PENDING_INFO -> CANCELLED',
        $cancellationEvents !== []
            && $cancellationEvents[0]->getFromStatus() === IncidentStatus::PENDING_INFO->value,
        (string)count($cancellationEvents)
    );

    $assert(
        '8.11 El hilo del expediente queda sellado en solo lectura (RF-05.4)',
        (new IncidentCommentService())->acceptsNewComments(['status' => 'CANCELLED', 'resolved_at' => null]) === false
    );

    $auditActions = $pdo->prepare("
        SELECT COUNT(*) FROM `audit_log`
        WHERE `action` IN ('MACHINE_BLOCKED_NO_ACCESS', 'REFUND_DETACHED_BY_INACTIVITY')
          AND ((`entity_type` = 'MACHINE' AND `entity_id` = :machine)
            OR (`entity_type` = 'REFUND_REQUEST' AND `entity_id` = :refund))
    ");
    $auditActions->execute([':machine' => $testMachine->getId(), ':refund' => $testRefundId]);
    $assert(
        '8.12 Las dos acciones nuevas se persisten en el registro inmutable',
        (int)$auditActions->fetchColumn() === 2,
        'eventos: ' . (string)$auditActions->fetchColumn()
    );

    $detachAgain = $refundRepo->detachFromIncident($testRefundId, RefundStatus::REQUIRES_COORDINATOR_APPROVAL);
    $assert(
        '8.13 Un expediente ya desvinculado no se vuelve a desvincular',
        $detachAgain === false
    );

    $invalidTicket = $capture(fn () => $machineRepo->blockForNoAccess($testMachine->getId(), '   '));
    $assert(
        '8.14 Un bloqueo sin ticket que lo justifique se rechaza de inmediato',
        $invalidTicket['threw'] && $invalidTicket['class'] === InvalidArgumentException::class,
        $invalidTicket['message']
    );

    $missingMachine = $capture(fn () => $machineRepo->blockForNoAccess(99999999, $testTicket));
    $assert(
        '8.15 Bloquear una máquina inexistente se declara con excepción',
        $missingMachine['threw'] && $missingMachine['class'] === DomainException::class,
        $missingMachine['message']
    );
} finally {
    // Limpieza de las filas propias de la suite (nunca de datos sembrados).
    //
    // El expediente del test queda DESVINCULADO a propósito, y el purgado dirigido
    // navega por `incident_id`, así que primero se reengancha a su avería para que el
    // limpiador compartido lo arrastre como hija y abra la ventana de purga que exige
    // el disparador del Art. III.1 (los expedientes no se borran a pelo).
    $pdo->prepare('UPDATE `refund_requests` SET `incident_id` = :incident WHERE `id` = :refund')
        ->execute([':incident' => $testIncidentId, ':refund' => $testRefundId]);

    TestDataCleaner::purgeIncident($pdo, $testIncidentId);

    $pdo->prepare('DELETE FROM `audit_log` WHERE (`entity_type` = :mtype AND `entity_id` = :machine) OR (`entity_type` = :rtype AND `entity_id` = :refund)')
        ->execute([':mtype' => 'MACHINE', ':machine' => $testMachine->getId(), ':rtype' => 'REFUND_REQUEST', ':refund' => $testRefundId]);

    // Las hijas de la máquina se borran antes que ella: si el sembrador de
    // demostración emitió un certificado sanitario mientras la fila de prueba
    // existía, el FK del certificado impediría borrar la máquina y la limpieza
    // quedaría a medias.
    $pdo->prepare('DELETE FROM `sanitary_certificates` WHERE `machine_id` = :id')->execute([':id' => $testMachine->getId()]);
    $pdo->prepare('DELETE FROM `machines` WHERE `id` = :id')->execute([':id' => $testMachine->getId()]);
}

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-09 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-09 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
