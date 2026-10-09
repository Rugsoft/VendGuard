<?php

declare(strict_types=1);

/**
 * LocationMachinesCommentsCounterTest
 *
 * Verificación objetiva de la mitad backend de la tarea T-COM-14 (módulo 10,
 * hilo de comentarios): el listado del parque de máquinas de una sede debe
 * entregar a la insignia de conversación de cada tarjeta el recuento SEGREGADO
 * de comentarios públicos (`active_incident.public_comments_count`).
 *
 * Condición "Hecho cuando" cubierta en servidor:
 * - La sede recibe un contador numérico real, calculado por el repositorio, y
 *   nunca un valor ficticio (RF-01.1, Constitución Art. I.3).
 * - El recuento solicitado es siempre el público (`countComments($id, false)`):
 *   el total de mensajes jamás viaja al canal de sede porque delataría las notas
 *   internas de taller (RF-02.1, RNF-01, Constitución Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2 puro con dobles en memoria, sin librerías externas y sin
 * escrituras en base de datos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - Contador público de comentarios en el parque (T-COM-14)\n";
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

$siteLocation = new Location(
    id: 1,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona'
);

$machineOperational = new Machine(
    1,
    1,
    'VEND-0101',
    'Sanden Vendo G-Drink',
    MachineType::PERISHABLE_FOOD,
    'Planta Baja - Urgencias',
    null,
    true,
    null,
    null,
    null,
    null
);

$machineWithIncident = new Machine(
    2,
    1,
    'VEND-0102',
    'Necta Canto Touch',
    MachineType::HOT_DRINKS,
    'Planta 1 - Sala de Espera',
    null,
    true,
    null,
    null,
    null,
    [
        'id' => 142,
        'ticket_code' => 'TICK-2026-00142',
        'status' => 'IN_PROGRESS',
        'category' => 'PAYMENT_SYSTEM',
        'urgency' => 'HIGH',
        'created_at' => '2026-10-06 10:15:30',
        'resolved_at' => null,
        'is_in_warranty' => false,
    ]
);

$machineRepo = new class($machineOperational, $machineWithIncident) implements MachineRepositoryInterface {
    public function __construct(private Machine $operational, private Machine $withIncident)
    {
    }

    public function findActiveByLocationId(int $locationId): array
    {
        return [$this->operational, $this->withIncident];
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
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

/**
 * Doble del repositorio de incidencias: registra cada consulta de recuento para
 * poder certificar que la sede solo pide el total público, y devuelve cifras
 * distintas para público (3) e interno (2) de forma que cualquier fuga de la
 * cuenta total (5) resulte detectable.
 */
$incidentRepo = new class implements IncidentRepositoryInterface {
    /** @var list<array{id: int, includeInternal: bool}> */
    public array $countCalls = [];

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        $this->countCalls[] = ['id' => $incidentId, 'includeInternal' => $includeInternal];

        if ($incidentId !== 142) {
            return 0;
        }

        return $includeInternal ? 5 : 3;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
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

$controller = new LocationPortalController(
    machineRepo: $machineRepo,
    locationRepo: null,
    incidentRepo: $incidentRepo,
    fileUploader: null,
    refundService: null,
    ibanValidator: null,
    commentService: null
);

$request = new Request('GET', '/api/locations/SEDE-BCN-01/machines');
$request->setRouteParams(['site_code' => 'SEDE-BCN-01']);
$request->setAttribute('site_code', 'SEDE-BCN-01');
$request->setAttribute('authenticated_location', $siteLocation);

$response = $controller->getMachines($request);
$body = $response->getDecodedBody() ?? [];
$machines = $body['data'] ?? [];
$rawJson = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// =====================================================================
// GRUPO 1: El listado entrega el contador público real (RF-01.1)
// =====================================================================
echo "--- Grupo 1: Contador público real en el parque de máquinas ---\n";

$assert(
    '1.1 El listado responde HTTP 200 OK con la envolvente canónica',
    $response->getStatusCode() === 200 && ($body['success'] ?? false) === true,
    'Código recibido: ' . $response->getStatusCode()
);

$assert(
    '1.2 Devuelve las dos máquinas del parque',
    is_array($machines) && count($machines) === 2,
    'Máquinas devueltas: ' . count((array)$machines)
);

$assert(
    '1.3 La máquina operativa no expone contador de conversación',
    array_key_exists('active_incident', $machines[0] ?? []) && $machines[0]['active_incident'] === null
);

$assert(
    '1.4 La máquina con avería activa publica public_comments_count',
    array_key_exists('public_comments_count', $machines[1]['active_incident'] ?? []),
    'Claves reales: ' . implode(', ', array_keys($machines[1]['active_incident'] ?? []))
);

$assert(
    '1.5 El contador publicado es el recuento de comentarios públicos (3)',
    ($machines[1]['active_incident']['public_comments_count'] ?? null) === 3,
    'Valor recibido: ' . var_export($machines[1]['active_incident']['public_comments_count'] ?? null, true)
);

$assert(
    '1.6 La cabecera contextual de la avería se conserva intacta',
    ($machines[1]['active_incident']['id'] ?? null) === 142
        && ($machines[1]['active_incident']['ticket_code'] ?? '') === 'TICK-2026-00142'
        && ($machines[1]['active_incident']['status'] ?? '') === 'IN_PROGRESS'
);

// =====================================================================
// GRUPO 2: Segregación constitucional del recuento (Art. V.4, RNF-01)
// =====================================================================
echo "\n--- Grupo 2: Segregación estricta del recuento hacia la Sede (Art. V.4) ---\n";

$assert(
    '2.1 Solo se consulta el recuento del expediente con comentarios',
    count($incidentRepo->countCalls) === 1 && $incidentRepo->countCalls[0]['id'] === 142,
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.2 La sede pide siempre el recuento público (includeInternal = false)',
    $incidentRepo->countCalls === [['id' => 142, 'includeInternal' => false]],
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.3 El total de mensajes (5) jamás viaja en el payload de la sede',
    !str_contains($rawJson, '"comments_count"') && ($machines[1]['active_incident']['public_comments_count'] ?? null) !== 5,
    'JSON real: ' . $rawJson
);

$assert(
    '2.4 Cero fugas: el payload no filtra la marca de confidencialidad de los mensajes',
    !str_contains($rawJson, 'is_internal'),
    'JSON real: ' . $rawJson
);

$assert(
    '2.5 El payload no expone ningún recuento interno agregado',
    !str_contains($rawJson, 'internal_comments_count') && !str_contains($rawJson, 'total_comments_count'),
    'JSON real: ' . $rawJson
);

// =====================================================================
// GRUPO 3: Comportamiento ante parques vacíos o sin averías
// =====================================================================
echo "\n--- Grupo 3: Comportamiento defensivo del contador ---\n";

$emptyMachineRepo = new class implements MachineRepositoryInterface {
    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
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

$countCallProbe = new class implements IncidentRepositoryInterface {
    public int $calls = 0;

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        $this->calls++;

        return 0;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
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

$emptyController = new LocationPortalController(
    machineRepo: $emptyMachineRepo,
    locationRepo: null,
    incidentRepo: $countCallProbe,
    fileUploader: null,
    refundService: null,
    ibanValidator: null,
    commentService: null
);

$emptyResponse = $emptyController->getMachines($request);
$emptyBody = $emptyResponse->getDecodedBody() ?? [];

$assert(
    '3.1 Un parque sin máquinas responde 200 OK con lista vacía',
    $emptyResponse->getStatusCode() === 200 && ($emptyBody['data'] ?? null) === []
);

$assert(
    '3.2 Sin averías activas no se consulta ningún recuento de comentarios',
    $countCallProbe->calls === 0,
    'Consultas realizadas: ' . $countCallProbe->calls
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. FINALIZACIÓN OBJETIVA DE LA MITAD SERVIDOR DE T-COM-14.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
