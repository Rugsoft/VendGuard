<?php

declare(strict_types=1);

/**
 * TechnicianRouteCommentsCounterTest
 *
 * Verificación objetiva de la mitad backend de la tarea T-COM-15 (módulo 10,
 * hilo de comentarios): el listado de ruta del técnico de campo
 * (`GET /api/technician/my-route`) debe entregar a la insignia de conversación de
 * cada tarjeta de parada el recuento TOTAL de mensajes del expediente.
 *
 * Condición "Hecho cuando" cubierta en servidor:
 * - Cada parada publica un `comments_count` real, calculado por el repositorio
 *   sobre el expediente de esa parada (RF-01.1, Constitución Art. I.3).
 * - El recuento es la TOTALIDAD de mensajes, públicos y notas internas de
 *   taller (`countComments($id, true)`): el técnico de campo tiene acceso
 *   legítimo a ambos canales (RF-02.3), al contrario que la Sede, cuyo contador
 *   permanece segregado solo a públicos (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2 puro con dobles en memoria, sin librerías externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - Contador total de la parada de ruta (T-COM-15)\n";
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
// Dobles en memoria de los repositorios consumidos por el controlador
// =====================================================================

$stopOne = new Incident(
    id: 501,
    ticketCode: 'INC-2026-0501',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'El monedero traga monedas de 1 euro y no da cambio.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED
);

$stopTwo = new Incident(
    id: 502,
    ticketCode: 'INC-2026-0502',
    machineId: 11,
    locationId: 1,
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Máquina de sándwiches a 14 ºC con alerta de frío alimentario.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::IN_PROGRESS,
    startedAt: '2026-10-07 08:30:00'
);

$routeLocation = new Location(
    id: 1,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    contactPhone: '933001122'
);

$machineOne = new Machine(10, 1, 'VEND-BCN-001', 'Vendo ColdDrink 800', MachineType::COLD_DRINKS, 'Planta 1 · Cafetería');
$machineTwo = new Machine(11, 1, 'VEND-BCN-002', 'Vendo FreshMeal 400', MachineType::PERISHABLE_FOOD, 'Planta Baja · Vestíbulo');

$incidentRepo = new class($stopOne, $stopTwo) implements IncidentRepositoryInterface {
    /** @var list<array{id: int, includeInternal: bool}> */
    public array $countCalls = [];

    /** @var array<int, array{public: int, internal: int}> */
    public array $threadCounts = [
        501 => ['public' => 3, 'internal' => 4],
        502 => ['public' => 0, 'internal' => 0],
    ];

    public function __construct(private Incident $stopOne, private Incident $stopTwo)
    {
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [$this->stopOne, $this->stopTwo];
    }

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        $this->countCalls[] = ['id' => $incidentId, 'includeInternal' => $includeInternal];
        $row = $this->threadCounts[$incidentId] ?? ['public' => 0, 'internal' => 0];

        return $includeInternal ? $row['public'] + $row['internal'] : $row['public'];
    }

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
    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

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
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
};

$machineRepo = new class($machineOne, $machineTwo) implements MachineRepositoryInterface {
    public function __construct(private Machine $one, private Machine $two)
    {
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return match ($id) {
            10 => $this->one,
            11 => $this->two,
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
    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

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
    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
};

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
$stops = $body['data'] ?? [];
$rawJson = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// =====================================================================
// GRUPO 1: La ruta publica el contador total de cada parada (RF-01.1)
// =====================================================================
echo "--- Grupo 1: Contador total de mensajes por parada ---\n";

$assert(
    '1.1 La ruta responde HTTP 200 OK con la envolvente canónica',
    $response->getStatusCode() === 200 && ($body['success'] ?? false) === true,
    'Código recibido: ' . $response->getStatusCode()
);

$assert(
    '1.2 Devuelve las dos paradas asignadas al técnico',
    is_array($stops) && count($stops) === 2,
    'Paradas devueltas: ' . count((array)$stops)
);

$assert(
    '1.3 Cada parada publica su contador comentarios_count',
    array_key_exists('comments_count', $stops[0] ?? []) && array_key_exists('comments_count', $stops[1] ?? []),
    'Claves reales: ' . implode(', ', array_keys($stops[0] ?? []))
);

$assert(
    '1.4 El contador de la primera parada suma públicos (3) y notas internas (4)',
    ($stops[0]['comments_count'] ?? null) === 7,
    'Valor recibido: ' . var_export($stops[0]['comments_count'] ?? null, true)
);

$assert(
    '1.5 Una parada sin hilo publica contador 0, no un valor ausente',
    ($stops[1]['comments_count'] ?? null) === 0,
    'Valor recibido: ' . var_export($stops[1]['comments_count'] ?? null, true)
);

$assert(
    '1.6 El contador convive con el contrato previo de la parada sin alterarlo',
    ($stops[0]['ticket_code'] ?? '') === 'INC-2026-0501'
        && ($stops[0]['status'] ?? '') === 'ASSIGNED'
        && ($stops[0]['machine']['code'] ?? '') === 'VEND-BCN-001'
        && ($stops[0]['location']['name'] ?? '') === 'Hospital del Mar - Edificio Central'
        && ($stops[0]['location']['contact_phone'] ?? '') === '933001122'
        && ($stops[1]['started_at'] ?? '') === '2026-10-07 08:30:00'
);

// =====================================================================
// GRUPO 2: El técnico cuenta la totalidad de mensajes (RF-02.3)
// =====================================================================
echo "\n--- Grupo 2: Totalidad de mensajes en el canal del técnico (RF-02.3) ---\n";

$assert(
    '2.1 Se consulta el recuento de cada expediente de la ruta',
    count($incidentRepo->countCalls) === 2
        && $incidentRepo->countCalls[0]['id'] === 501
        && $incidentRepo->countCalls[1]['id'] === 502,
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.2 El técnico pide el recuento TOTAL (includeInternal = true)',
    $incidentRepo->countCalls === [
        ['id' => 501, 'includeInternal' => true],
        ['id' => 502, 'includeInternal' => true],
    ],
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.3 El contador publicado no es el segregado de sede (3), sino el total (7)',
    ($stops[0]['comments_count'] ?? null) === 7
        && ($stops[0]['comments_count'] ?? null) !== 3,
    'Valor recibido: ' . var_export($stops[0]['comments_count'] ?? null, true)
);

$assert(
    '2.4 La ruta del técnico no expone el contador segregado de la sede',
    !str_contains($rawJson, 'public_comments_count'),
    'JSON real: ' . $rawJson
);

$assert(
    '2.5 El payload de ruta no filtra la marca de confidencialidad de los mensajes',
    !str_contains($rawJson, 'is_internal'),
    'JSON real: ' . $rawJson
);

// =====================================================================
// GRUPO 3: Defensa ante peticiones no identificadas
// =====================================================================
echo "\n--- Grupo 3: Blindaje de identidad del técnico autenticado ---\n";

$callsBefore = count($incidentRepo->countCalls);
$anonymousRequest = new Request('GET', '/api/technician/my-route');
$anonymousResponse = $controller->getMyRoute($anonymousRequest);
$anonymousBody = $anonymousResponse->getDecodedBody() ?? [];

$assert(
    '3.1 Sin técnico autenticado la ruta responde HTTP 401 UNAUTHORIZED',
    $anonymousResponse->getStatusCode() === 401 && ($anonymousBody['error']['code'] ?? '') === 'UNAUTHORIZED',
    'Código recibido: ' . $anonymousResponse->getStatusCode()
);

$assert(
    '3.2 Una petición anónima no consulta ningún hilo de conversación',
    count($incidentRepo->countCalls) === $callsBefore,
    'Consultas acumuladas: ' . count($incidentRepo->countCalls)
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. FINALIZACIÓN OBJETIVA DE LA MITAD SERVIDOR DE T-COM-15.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
