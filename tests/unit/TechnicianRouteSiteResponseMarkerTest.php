<?php

declare(strict_types=1);

/**
 * TechnicianRouteSiteResponseMarkerTest
 *
 * Suite unitaria de la marca `site_response_since_pause` de la parada pausada
 * (módulo 11, RF-05.6, enmienda del Product Owner E-1 del 2026-10-11).
 *
 * Contexto de la enmienda: hasta el 2026-10-11 un comentario público de la sede sobre
 * un expediente en `PENDING_INFO` lo despausaba solo y lo devolvía a «En curso». El
 * Product Owner lo declaró contrario a la operación —el sistema no puede afirmar que
 * el técnico está delante de la máquina porque un tercero haya escrito— y la pausa
 * quedó cerrada sólo por acto explícito (técnico in situ o coordinador) o por la
 * cancelación justificada por inactividad.
 *
 * Lo que esta suite certifica en la mitad servidor:
 *
 * 1. La parada pausada publica la marca DERIVADA `site_response_since_pause`, que la
 *    tarjeta móvil convierte en el aviso «✉️ La sede ha respondido · pendiente de tu
 *    reanudación». Sin respuesta de la sede la marca es `false`, nunca un valor ausente.
 * 2. La marca es una CAPACIDAD OPCIONAL del repositorio
 *    (`IncidentSiteResponseRepositoryInterface`): un repositorio que no la implementa
 *    publica `false` —el valor honesto, sin consulta no consta respuesta— y mantiene el
 *    contrato de la ruta estable para el frontend.
 * 3. La consulta se hace UNA vez por parada pausada y no se hace nunca sobre paradas
 *    que no están pausadas: la marca describe la pausa viva, no el hilo.
 * 4. La marca viaja en la ruta como dato informativo: no transiciona, no despausa y
 *    convive intacta con los campos de pausa que ya publicaba RF-05.5.
 *
 * Dogma Vanilla: PHP 8.2 puro con dobles en memoria, sin librerías externas ni red.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentSiteResponseRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Marca de respuesta de la sede en la ruta (RF-05.6, E-1)\n";
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

// =====================================================================
// Fixtures: una parada pausada con respuesta de sede y una parada activa
// =====================================================================

$pausedStop = new Incident(
    id: 601,
    ticketCode: 'INC-2026-0601',
    machineId: 20,
    locationId: 2,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'La máquina no acepta monedas y la sala está cerrada con llave.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::PENDING_INFO,
    assignedTechnicianId: 77,
    assignedAt: '2026-10-11 08:00:00',
    pendingInfoReasonCategory: IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    pendingInfoReasonText: 'El responsable de sede no se presentó a abrir la sala técnica.',
    pausedAt: '2026-10-11 09:00:00',
    totalPendingInfoSeconds: 1800
);

$activeStop = new Incident(
    id: 602,
    ticketCode: 'INC-2026-0602',
    machineId: 21,
    locationId: 2,
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Máquina de sándwiches a 14 ºC con alerta de frío alimentario.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: 77,
    assignedAt: '2026-10-11 10:00:00'
);

$routeLocation = new Location(
    id: 2,
    siteCode: 'SEDE-BCN-02',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    contactPhone: '933001122'
);

$machineOne = new Machine(20, 2, 'VEND-BCN-020', 'Vendo ColdDrink 800', MachineType::COLD_DRINKS, 'Planta 1 · Sala médica');
$machineTwo = new Machine(21, 2, 'VEND-BCN-021', 'Vendo FreshMeal 400', MachineType::PERISHABLE_FOOD, 'Planta Baja · Vestíbulo');

$machineRepo = new class($machineOne, $machineTwo) implements MachineRepositoryInterface {
    public function __construct(private Machine $one, private Machine $two)
    {
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return match ($id) {
            20 => $this->one,
            21 => $this->two,
            default => null,
        };
    }

    public function findActiveByLocationId(int $locationId): array { return [$this->one, $this->two]; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new RuntimeException('Stub.'); }
    public function update(int $id, array $data): bool { return false; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return false; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
};

$locationRepo = new class($routeLocation) implements LocationRepositoryInterface {
    public function __construct(private Location $location)
    {
    }

    public function findById(int $id): ?Location
    {
        return $id === $this->location->getId() ? $this->location : null;
    }

    public function findBySiteCode(string $siteCode): ?Location { return null; }
    public function findAllActive(): array { return []; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new RuntimeException('Stub.'); }
    public function update(int $id, array $data): bool { return false; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
};

/**
 * Doble en memoria del repositorio de incidencias que ADEMÁS declara la capacidad
 * opcional de la respuesta de sede, registrando cada consulta para certificar que se
 * hace una vez por parada pausada y ninguna sobre la parada activa.
 */
$portIncidentRepo = new class($pausedStop, $activeStop) implements IncidentRepositoryInterface, IncidentSiteResponseRepositoryInterface {
    /** @var list<int> */
    public array $siteResponseQueries = [];

    /** @var array<int, bool> */
    public array $siteResponses = [601 => true, 602 => true];

    public function __construct(private Incident $paused, private Incident $active)
    {
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [$this->paused, $this->active];
    }

    public function hasSiteResponseSinceCurrentPause(int $incidentId): bool
    {
        $this->siteResponseQueries[] = $incidentId;

        // El doble responde `true` también para la parada activa (602) a propósito: si el
        // controlador no filtrase por pausa viva, la ruta publicaría una respuesta de sede
        // sobre una avería que no está pausada.
        return $this->siteResponses[$incidentId] ?? false;
    }

    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function update(Incident $incident): bool { return false; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new RuntimeException('Stub.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new RuntimeException('Stub.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new RuntimeException('Stub.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new RuntimeException('Stub.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new RuntimeException('Stub.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new RuntimeException('Stub.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
};

/**
 * Doble del mismo contrato SIN la capacidad opcional: acredita que la ruta degrada de
 * forma honesta (`false`) en lugar de fallar o de inventar una respuesta de sede.
 */
$plainIncidentRepo = new class($pausedStop, $activeStop) implements IncidentRepositoryInterface {
    public function __construct(private Incident $paused, private Incident $active)
    {
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [$this->paused, $this->active];
    }

    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function update(Incident $incident): bool { return false; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new RuntimeException('Stub.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new RuntimeException('Stub.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new RuntimeException('Stub.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new RuntimeException('Stub.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new RuntimeException('Stub.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new RuntimeException('Stub.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
};

/**
 * Solicita la ruta del técnico autenticado y devuelve la lista de paradas publicada.
 *
 * @return array{status: int, stops: array<int, array<string, mixed>>}
 */
$fetchRoute = static function (IncidentRepositoryInterface $incidentRepo) use ($machineRepo, $locationRepo): array {
    $controller = new TechnicianController(
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo,
        locationRepo: $locationRepo
    );

    $request = new Request('GET', '/api/technician/my-route');
    $request->setAttribute('user_id', 77);
    $request->setAttribute('user_role', 'TECHNICIAN');

    $response = $controller->getMyRoute($request);
    $body = $response->getDecodedBody() ?? [];

    return ['status' => $response->getStatusCode(), 'stops' => $body['data'] ?? []];
};

$withPort = $fetchRoute($portIncidentRepo);
$withoutPort = $fetchRoute($plainIncidentRepo);

// =====================================================================
// GRUPO 1: La marca derivada viaja en la parada pausada (RF-05.6)
// =====================================================================
echo "--- Grupo 1: Marca derivada de respuesta de la sede en la ruta ---\n";

$assert(
    '1.1 La ruta responde HTTP 200 con las dos paradas del técnico',
    $withPort['status'] === 200 && count((array)$withPort['stops']) === 2,
    'Código: ' . $withPort['status'] . ' · paradas: ' . count((array)$withPort['stops'])
);

$assert(
    '1.2 La parada pausada publica site_response_since_pause (respuesta ya en el hilo)',
    ($withPort['stops'][0]['site_response_since_pause'] ?? null) === true,
    'Valor recibido: ' . var_export($withPort['stops'][0]['site_response_since_pause'] ?? null, true)
);

$assert(
    '1.3 La marca es booleana y explícita, no un valor ausente ni un texto',
    array_key_exists('site_response_since_pause', (array)$withPort['stops'][0])
        && is_bool($withPort['stops'][0]['site_response_since_pause']),
    'Tipo recibido: ' . get_debug_type($withPort['stops'][0]['site_response_since_pause'] ?? null)
);

$assert(
    '1.4 Sin respuesta de la sede la marca es false, nunca un valor ausente',
    ($withoutPort['stops'][0]['site_response_since_pause'] ?? null) === false,
    'Valor recibido: ' . var_export($withoutPort['stops'][0]['site_response_since_pause'] ?? null, true)
);

$assert(
    '1.5 La marca convive con el contrato de pausa de RF-05.5 sin desplazarlo',
    ($withPort['stops'][0]['paused_at'] ?? '') === '2026-10-11 09:00:00'
        && ($withPort['stops'][0]['total_pending_info_seconds'] ?? null) === 1800
        && ($withPort['stops'][0]['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
        && ($withPort['stops'][0]['status'] ?? '') === 'PENDING_INFO'
);

$assert(
    '1.6 La marca informa, no transiciona: el estado publicado sigue siendo PENDING_INFO',
    ($withPort['stops'][0]['status'] ?? '') === IncidentStatus::PENDING_INFO->value
        && ($withPort['stops'][0]['paused_at'] ?? null) !== null
);

// =====================================================================
// GRUPO 2: Puerto opcional y presupuesto de consultas (segregación)
// =====================================================================
echo "\n--- Grupo 2: Puerto opcional y una consulta por parada pausada ---\n";

$assert(
    '2.1 La ruta publica la marca aunque el repositorio no declare la capacidad opcional',
    ($withoutPort['status'] ?? 0) === 200
        && count((array)$withoutPort['stops']) === 2
        && array_key_exists('site_response_since_pause', (array)$withoutPort['stops'][0]),
    'Código: ' . ($withoutPort['status'] ?? 0)
);

$assert(
    '2.2 Un repositorio sin la capacidad no inventa una respuesta de sede',
    ($withoutPort['stops'][0]['site_response_since_pause'] ?? null) === false
        && ($withoutPort['stops'][1]['site_response_since_pause'] ?? null) === false
);

$assert(
    '2.3 La consulta se hace exactamente una vez por parada pausada',
    $portIncidentRepo->siteResponseQueries === [601],
    'Consultas registradas: ' . json_encode($portIncidentRepo->siteResponseQueries)
);

$assert(
    '2.4 La marca no se consulta sobre paradas que no están pausadas',
    !in_array(602, $portIncidentRepo->siteResponseQueries, true)
        && ($withPort['stops'][1]['site_response_since_pause'] ?? null) === false,
    'Consultas registradas: ' . json_encode($portIncidentRepo->siteResponseQueries)
);

$assert(
    '2.5 La parada activa conserva sus campos de pausa a null',
    ($withPort['stops'][1]['paused_at'] ?? null) === null
        && ($withPort['stops'][1]['pending_info_reason_category'] ?? null) === null
        && ($withPort['stops'][1]['total_pending_info_seconds'] ?? null) === 0
);

$assert(
    '2.6 El contrato de la parada no altera ningún campo previo de la ruta',
    ($withPort['stops'][0]['ticket_code'] ?? '') === 'INC-2026-0601'
        && ($withPort['stops'][0]['machine']['code'] ?? '') === 'VEND-BCN-020'
        && ($withPort['stops'][0]['location']['contact_phone'] ?? '') === '933001122'
        && ($withPort['stops'][1]['ticket_code'] ?? '') === 'INC-2026-0602'
);

// =====================================================================
// GRUPO 3: La marca no viaja con datos internos de la pausa al portal público
// =====================================================================
echo "\n--- Grupo 3: La marca no añade fugas de información interna ---\n";

$rawJson = (string)json_encode($withPort['stops'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert(
    '3.1 La marca viaja sin autoría ni texto del comentario de la sede',
    !str_contains($rawJson, 'author_type')
        && !str_contains($rawJson, 'is_internal')
        && !str_contains($rawJson, 'IncidentSiteResponseRepositoryInterface')
);

$assert(
    '3.2 La marca no expone el identificador del puerto ni rastros de implementación',
    !str_contains($rawJson, 'hasSiteResponseSinceCurrentPause')
        && !str_contains($rawJson, 'auto_resumed'),
    'JSON real: ' . $rawJson
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. MARCA DE RESPUESTA DE SEDE CERTIFICADA (RF-05.6, E-1).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
