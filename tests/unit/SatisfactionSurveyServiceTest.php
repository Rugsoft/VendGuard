<?php

declare(strict_types=1);

/**
 * SatisfactionSurveyServiceTest · T-CSAT-04
 *
 * Certifica el caso de uso «valorar un servicio resuelto» (Módulo 20) contra puertos en
 * memoria, sin base de datos:
 *
 *   1. **Precedencia de errores (contratos §4.2, R9).** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8:
 *      un cuerpo inválido no puede tapar una avería inexistente o de otra sede, y un
 *      duplicado solo se evalúa con carga útil válida.
 *   2. **Relectura bloqueada (RF-02.6).** El expediente se lee con `FOR UPDATE` dentro de
 *      la transacción; `resolution_at` se copia exactamente de `incidents.resolved_at`.
 *   3. **Una reapertura invalida el episodio (RD-01).** Sin `resolved_at` o con un estado
 *      que no es `RESOLVED`/`CLOSED`, la valoración responde `422 SURVEY_NOT_AVAILABLE`.
 *   4. **Unicidad por episodio (RF-02.7).** La comprobación previa responde `409`; si dos
 *      peticiones la superan, el alta devuelve conflicto y el servicio responde `409`, no `500`.
 *   5. **Auditoría en la misma transacción (RF-03.2, RF-03.3).** El evento
 *      `SUBMIT_SATISFACTION_SURVEY` se firma sobre `TICKET` con el nombre de la sede y se
 *      escribe dentro de la unidad de trabajo que inserta la encuesta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\CsatReportDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SatisfactionSurveyService;
use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRowLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\SatisfactionSurveyRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * Puerto de encuestas en memoria.
 */
final class InMemorySatisfactionSurveyRepository implements SatisfactionSurveyRepositoryInterface
{
    /** @var array<string, IncidentSatisfactionSurvey> */
    public array $episodes = [];

    /** @var list<IncidentSatisfactionSurvey> */
    public array $created = [];

    /** Simula que otra petición ganó la carrera del índice único. */
    public bool $conflictOnCreate = false;

    /** @var list<array{location_id: int, limit: int}> */
    public array $pendingCalls = [];

    public int $nextId = 92;

    public function findEpisode(int $incidentId, string $resolutionAt): ?IncidentSatisfactionSurvey
    {
        return $this->episodes[$incidentId . '|' . $resolutionAt] ?? null;
    }

    public function create(IncidentSatisfactionSurvey $survey): ?IncidentSatisfactionSurvey
    {
        if ($this->conflictOnCreate) {
            return null;
        }

        $saved = $survey->withIdentity($this->nextId++, '2026-10-23 09:15:02+02');
        $this->created[] = $saved;
        $this->episodes[$saved->getIncidentId() . '|' . $saved->getResolutionAt()] = $saved;

        return $saved;
    }

    public function findPendingByLocation(int $locationId, int $limit = 20): array
    {
        $this->pendingCalls[] = ['location_id' => $locationId, 'limit' => $limit];

        return [];
    }

    public function countPendingByLocation(int $locationId): int
    {
        return 0;
    }

    public function aggregate(MetricFilter $filter): CsatReportDto
    {
        return new CsatReportDto(null, 0, [], [], [], []);
    }
}

/**
 * Puerto de bloqueo del expediente en memoria.
 */
final class InMemoryIncidentLockRepository implements IncidentRowLockRepositoryInterface
{
    public ?Incident $incident = null;

    public bool $insideTransaction = false;

    public function findByIdForUpdate(int $id): ?Incident
    {
        $this->insideTransaction = SatisfactionSurveySpyTransactionManager::$inTransaction;

        return $this->incident !== null && $this->incident->getId() === $id ? $this->incident : null;
    }

    /**
     * El doble de CSAT no ejerce el bloqueo de ruta del Módulo 21; declara el
     * método para seguir cumpliendo el puerto segregado.
     *
     * @return list<Incident>
     */
    public function findAssignedToTechnicianForUpdate(int $technicianId): array
    {
        return [];
    }
}

/**
 * Unidad de trabajo observable: recuerda si había una transacción abierta al escribir.
 */
final class SatisfactionSurveySpyTransactionManager implements TransactionManagerInterface
{
    public static bool $inTransaction = false;

    public int $runs = 0;

    public function runInTransaction(callable $operation): mixed
    {
        $this->runs++;
        $outer = self::$inTransaction;
        self::$inTransaction = true;

        try {
            return $operation();
        } finally {
            self::$inTransaction = $outer;
        }
    }
}

/**
 * Libro de auditoría en memoria: registra los eventos y si se firmaron dentro de la transacción.
 */
final class SatisfactionSurveySpyAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    /** @var list<bool> */
    public array $writtenInsideTransaction = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;
        $this->writtenInsideTransaction[] = SatisfactionSurveySpyTransactionManager::$inTransaction;

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
            static fn(AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

/**
 * Expediente mínimo hidratado como lo haría el repositorio PDO.
 *
 * @param array<string, mixed> $overrides
 */
$incidentRow = static function (array $overrides = []): array {
    return array_merge([
        'id' => 1841,
        'ticket_code' => 'INC-20261022-0007',
        'machine_id' => 14,
        'location_id' => 3,
        'category' => 'ELECTRICAL_OFF',
        'description' => 'La máquina no enfría.',
        'urgency' => 'HIGH',
        'status' => 'RESOLVED',
        'assigned_technician_id' => 7,
        'resolved_at' => '2026-10-22 17:04:11+02',
        'technician_name' => 'Ana Ruiz',
    ], $overrides);
};

/**
 * Monta el servicio con sus dobles y devuelve el escenario completo.
 *
 * @param Incident|null $incident Expediente que devolverá la relectura bloqueada.
 */
$scenario = static function (?Incident $incident) use ($incidentRow): array {
    $incident ??= Incident::fromDatabaseRow($incidentRow());

    $surveys = new InMemorySatisfactionSurveyRepository();
    $locks = new InMemoryIncidentLockRepository();
    $locks->incident = $incident;
    $audit = new SatisfactionSurveySpyAuditLogRepository();
    $transactions = new SatisfactionSurveySpyTransactionManager();

    $service = new SatisfactionSurveyService(
        $surveys,
        $locks,
        new AuditLogger($audit),
        $transactions,
    );

    return [$service, $surveys, $locks, $audit, $transactions];
};

/**
 * Ejecuta el envío y devuelve la excepción de dominio, o `null` si no la hubo.
 */
$rejection = static function (callable $operation): ?SatisfactionSurveyValidationException {
    try {
        $operation();
    } catch (SatisfactionSurveyValidationException $exception) {
        return $exception;
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-CSAT-04 · Servicio de la encuesta CSAT\n";
echo "======================================================================\n";

// ──────────────────────────────────────────────────────────────────────────
// 1. Precedencia: el expediente y la sede se resuelven antes que el cuerpo
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Precedencia de errores (contratos §4.2) ---\n";

[$service, $surveys, $locks, $audit, $transactions] = $scenario(null);
$missingIncident = $rejection(static fn() => $service->submit(999, 3, 'Hospital La Paz', []));
$assert(
    'Una avería inexistente responde 404 INCIDENT_NOT_FOUND aunque el cuerpo esté vacío',
    $missingIncident !== null
        && $missingIncident->getErrorCode() === SatisfactionSurveyValidationException::INCIDENT_NOT_FOUND_CODE
        && $missingIncident->getHttpStatusCode() === 404,
    $missingIncident === null ? 'se admitió' : $missingIncident->getErrorCode()
);
$assert(
    'Sin expediente la transacción no escribe encuesta ni auditoría',
    $transactions->runs === 1 && $surveys->created === [] && $audit->events === []
);

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow()));
$siteMismatch = $rejection(static fn() => $service->submit(1841, 99, 'Otra sede', []));
$assert(
    'Una avería de otra sede responde 403 SITE_MISMATCH antes de mirar el cuerpo',
    $siteMismatch !== null
        && $siteMismatch->getErrorCode() === SatisfactionSurveyValidationException::SITE_MISMATCH_CODE
        && $siteMismatch->getHttpStatusCode() === 403,
    $siteMismatch === null ? 'se admitió' : $siteMismatch->getErrorCode()
);

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow([
    'resolved_at' => null,
    'status' => 'RESOLVED',
])));
$noAnchor = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['rating' => 5]));
$assert(
    'Una avería sin resolved_at responde 422 SURVEY_NOT_AVAILABLE',
    $noAnchor !== null
        && $noAnchor->getErrorCode() === SatisfactionSurveyValidationException::SURVEY_NOT_AVAILABLE_CODE
        && $noAnchor->getHttpStatusCode() === 422,
    $noAnchor === null ? 'se admitió' : $noAnchor->getErrorCode()
);

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow([
    'status' => 'IN_PROGRESS',
    'resolved_at' => '2026-10-22 17:04:11+02',
])));
$notResolved = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['rating' => 5]));
$assert(
    'Una avería en curso responde 422 SURVEY_NOT_AVAILABLE',
    $notResolved !== null
        && $notResolved->getErrorCode() === SatisfactionSurveyValidationException::SURVEY_NOT_AVAILABLE_CODE,
    $notResolved === null ? 'se admitió' : $notResolved->getErrorCode()
);

// ──────────────────────────────────────────────────────────────────────────
// 2. Cuerpo: ausencia, tipo y longitud (5, 6, 7) antes que duplicado (8)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Cuerpo antes que duplicado ---\n";

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow()));
$surveys->episodes['1841|2026-10-22 17:04:11+02'] = IncidentSatisfactionSurvey::create(
    incidentId: 1841,
    locationId: 3,
    technicianId: 7,
    technicianNameSnapshot: 'Ana Ruiz',
    resolutionAt: '2026-10-22 17:04:11+02',
    rating: 5,
);

$missingRating = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['comment' => 'Sin nota']));
$assert(
    'Sin rating responde 400 MISSING_RATING aunque el episodio ya esté valorado',
    $missingRating !== null
        && $missingRating->getErrorCode() === SatisfactionSurveyValidationException::MISSING_RATING_CODE
        && $missingRating->getHttpStatusCode() === 400,
    $missingRating === null ? 'se admitió' : $missingRating->getErrorCode()
);

$invalidRating = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['rating' => '4.0']));
$assert(
    'Una nota «4.0» responde 422 INVALID_RATING aunque el episodio ya esté valorado',
    $invalidRating !== null
        && $invalidRating->getErrorCode() === SatisfactionSurveyValidationException::INVALID_RATING_CODE,
    $invalidRating === null ? 'se admitió' : $invalidRating->getErrorCode()
);

$tooLong = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', [
    'rating' => 5,
    'comment' => str_repeat('a', 501),
]));
$assert(
    'Un comentario de 501 caracteres responde 422 COMMENT_TOO_LONG aunque el episodio ya esté valorado',
    $tooLong !== null
        && $tooLong->getErrorCode() === SatisfactionSurveyValidationException::COMMENT_TOO_LONG_CODE,
    $tooLong === null ? 'se admitió' : $tooLong->getErrorCode()
);

// ──────────────────────────────────────────────────────────────────────────
// 3. Duplicado por episodio (RF-02.7)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Duplicado por episodio ---\n";

$duplicate = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['rating' => 5]));
$assert(
    'Un episodio ya valorado responde 409 SURVEY_ALREADY_SUBMITTED',
    $duplicate !== null
        && $duplicate->getErrorCode() === SatisfactionSurveyValidationException::SURVEY_ALREADY_SUBMITTED_CODE
        && $duplicate->getHttpStatusCode() === 409,
    $duplicate === null ? 'se admitió' : $duplicate->getErrorCode()
);

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow()));
$surveys->conflictOnCreate = true;
$race = $rejection(static fn() => $service->submit(1841, 3, 'Hospital La Paz', ['rating' => 5]));
$assert(
    'Si el índice único decide la carrera, el segundo envío responde 409 y no 500',
    $race !== null
        && $race->getErrorCode() === SatisfactionSurveyValidationException::SURVEY_ALREADY_SUBMITTED_CODE
        && $race->getHttpStatusCode() === 409,
    $race === null ? 'se admitió' : $race->getErrorCode()
);
$assert(
    'Un conflicto de carrera no escribe auditoría',
    $audit->events === []
);

// ──────────────────────────────────────────────────────────────────────────
// 4. Alta correcta: relectura bloqueada, ancla exacta y auditoría
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Alta correcta ---\n";

[$service, $surveys, $locks, $audit, $transactions] = $scenario(Incident::fromDatabaseRow($incidentRow()));

$dto = $service->submit(1841, 3, 'Hospital La Paz', [
    'rating' => '5',
    'comment' => '  Vinieron el mismo día.  ',
]);

$assert(
    'La respuesta 201 devuelve id, ticket, nota, comentario normalizado y fecha',
    $dto->toArray() === [
        'id' => 92,
        'incident_id' => 1841,
        'ticket_code' => 'INC-20261022-0007',
        'rating' => 5,
        'comment' => 'Vinieron el mismo día.',
        'created_at' => '2026-10-23 09:15:02+02',
    ],
    json_encode($dto->toArray())
);

$assert(
    'El expediente se relee con bloqueo dentro de la transacción (RF-02.6)',
    $locks->insideTransaction === true && $transactions->runs === 1
);

$assert(
    'resolution_at se copia exactamente de incidents.resolved_at',
    $dto->toArray()['id'] === 92
        && $surveys->created[0]->getResolutionAt() === '2026-10-22 17:04:11+02',
    'ancla: ' . $surveys->created[0]->getResolutionAt()
);

$assert(
    'El técnico y su nombre congelado se copian del expediente',
    $surveys->created[0]->getTechnicianId() === 7
        && $surveys->created[0]->getTechnicianNameSnapshot() === 'Ana Ruiz',
    'técnico: ' . var_export($surveys->created[0]->getTechnicianId(), true)
);

$assert(
    'Se escribe exactamente un evento de auditoría dentro de la misma transacción (RF-03.3)',
    count($audit->events) === 1 && $audit->writtenInsideTransaction === [true]
);

$event = $audit->events[0] ?? null;
$assert(
    'El evento es SUBMIT_SATISFACTION_SURVEY sobre TICKET, firmado por la sede (RF-03.2)',
    $event instanceof AuditEvent
        && $event->getAction() === 'SUBMIT_SATISFACTION_SURVEY'
        && $event->getEntityType() === AuditEvent::ENTITY_TICKET
        && $event->getEntityId() === 1841
        && $event->getUserId() === null
        && $event->getUserRole() === 'SITE_RESPONSIBLE'
        && $event->getUserName() === 'Hospital La Paz'
        && $event->getPreviousState() === null,
    $event === null ? 'sin evento' : $event->getAction() . ' · ' . $event->getUserRole()
);

$assert(
    'El estado nuevo y la metadata del evento siguen el contrato §7',
    $event !== null
        && $event->getNewState() === [
            'survey_id' => 92,
            'rating' => 5,
            'resolution_at' => '2026-10-22 17:04:11+02',
            'technician_id' => 7,
        ]
        && $event->getMetadata() === [
            'incident_id' => 1841,
            'location_id' => 3,
            'ticket_code' => 'INC-20261022-0007',
        ],
    $event === null ? 'sin evento' : json_encode($event->getNewState())
);

$assert(
    'La respuesta de sede no expone técnico ni su nombre (R2)',
    !array_key_exists('technician_id', $dto->toArray())
        && !array_key_exists('technician_name', $dto->toArray())
);

// ──────────────────────────────────────────────────────────────────────────
// 5. Listado de pendientes: la sede la decide el llamador, no el cuerpo
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Pendientes ---\n";

[$service, $surveys] = $scenario(Incident::fromDatabaseRow($incidentRow()));
$pending = $service->pendingFor(3);
$assert(
    'El listado delega en el puerto con la sede autenticada y el tope de 20',
    $surveys->pendingCalls === [['location_id' => 3, 'limit' => 20]]
        && $pending === ['items' => [], 'pending_total' => 0],
    json_encode($pending)
);

echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
