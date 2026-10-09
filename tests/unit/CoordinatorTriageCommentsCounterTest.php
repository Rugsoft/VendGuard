<?php

declare(strict_types=1);

/**
 * CoordinatorTriageCommentsCounterTest
 *
 * Verificación objetiva de la mitad backend de la tarea T-COM-16 (módulo 10,
 * hilo de comentarios): el listado de triaje del coordinador
 * (`GET /api/coordinator/incidents`) debe entregar a la insignia de conversación
 * de cada fila el recuento TOTAL de mensajes del expediente.
 *
 * Condición "Hecho cuando" cubierta en servidor:
 * - Cada fila publica un `comments_count` real, calculado por el repositorio
 *   sobre el expediente de esa avería (RF-01.1, Constitución Art. I.3).
 * - El recuento es la TOTALIDAD de mensajes, públicos y notas internas de
 *   taller (`countComments($id, true)`), porque el canal de coordinación tiene
 *   acceso legítimo a ambos (RF-02.3). La Sede, en cambio, mantiene su contador
 *   segregado solo a públicos (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2 puro con dobles en memoria, sin librerías externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\CoordinatorController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - Contador de conversación del triaje (T-COM-16)\n";
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

$rowOne = new Incident(
    id: 901,
    ticketCode: 'INC-2026-0901',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Pérdida de frío en cuba de sándwiches.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::REGISTERED
);

$rowTwo = new Incident(
    id: 902,
    ticketCode: 'INC-2026-0902',
    machineId: 11,
    locationId: 1,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Fallo en billetero.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED
);

$incidentRepo = new class($rowOne, $rowTwo) implements IncidentRepositoryInterface {
    /** @var list<array{id: int, includeInternal: bool}> */
    public array $countCalls = [];

    /** @var array<int, array{public: int, internal: int}> */
    public array $threadCounts = [
        901 => ['public' => 2, 'internal' => 5],
        902 => ['public' => 0, 'internal' => 0],
    ];

    public function __construct(private Incident $rowOne, private Incident $rowTwo)
    {
    }

    public function findAll(array $filters = []): array
    {
        return [$this->rowOne, $this->rowTwo];
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
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public function update(Incident $incident): bool { return false; }
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

$controller = new CoordinatorController(
    incidentRepo: $incidentRepo
);

$request = new Request('GET', '/api/coordinator/incidents');
$request->setAttribute('user_id', 9);
$request->setAttribute('user_role', 'COORDINATOR');

$response = $controller->getIncidents($request);
$body = $response->getDecodedBody() ?? [];
$rows = $body['data'] ?? [];
$rawJson = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// =====================================================================
// GRUPO 1: La bandeja publica el contador total de cada avería (RF-01.1)
// =====================================================================
echo "--- Grupo 1: Contador total de mensajes por fila de triaje ---\n";

$assert(
    '1.1 La bandeja responde HTTP 200 OK con la envolvente canónica',
    $response->getStatusCode() === 200 && ($body['success'] ?? false) === true,
    'Código recibido: ' . $response->getStatusCode()
);

$assert(
    '1.2 Devuelve las dos averías de la bandeja',
    is_array($rows) && count($rows) === 2,
    'Filas devueltas: ' . count((array)$rows)
);

$assert(
    '1.3 Cada fila publica su contador comments_count',
    array_key_exists('comments_count', $rows[0] ?? []) && array_key_exists('comments_count', $rows[1] ?? []),
    'Claves reales: ' . implode(', ', array_keys($rows[0] ?? []))
);

$assert(
    '1.4 El contador de la primera avería suma públicos (2) y notas internas (5)',
    ($rows[0]['comments_count'] ?? null) === 7,
    'Valor recibido: ' . var_export($rows[0]['comments_count'] ?? null, true)
);

$assert(
    '1.5 Una avería sin hilo publica contador 0, no un valor ausente',
    ($rows[1]['comments_count'] ?? null) === 0,
    'Valor recibido: ' . var_export($rows[1]['comments_count'] ?? null, true)
);

$assert(
    '1.6 El contador convive con el contrato previo de la fila sin alterarlo',
    ($rows[0]['ticket_code'] ?? '') === 'INC-2026-0901'
        && ($rows[0]['status'] ?? '') === 'REGISTERED'
        && array_key_exists('sla_breached', $rows[0])
        && array_key_exists('waiting_minutes', $rows[0])
        && array_key_exists('assigned_technician', $rows[0])
);

// =====================================================================
// GRUPO 2: El coordinador cuenta la totalidad de mensajes (RF-02.3)
// =====================================================================
echo "\n--- Grupo 2: Totalidad de mensajes en el canal de coordinación (RF-02.3) ---\n";

$assert(
    '2.1 Se consulta el recuento de cada expediente listado',
    count($incidentRepo->countCalls) === 2
        && $incidentRepo->countCalls[0]['id'] === 901
        && $incidentRepo->countCalls[1]['id'] === 902,
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.2 El coordinador pide el recuento TOTAL (includeInternal = true)',
    $incidentRepo->countCalls === [
        ['id' => 901, 'includeInternal' => true],
        ['id' => 902, 'includeInternal' => true],
    ],
    'Consultas registradas: ' . json_encode($incidentRepo->countCalls)
);

$assert(
    '2.3 El contador publicado no es el segregado de sede (2), sino el total (7)',
    ($rows[0]['comments_count'] ?? null) === 7
        && ($rows[0]['comments_count'] ?? null) !== 2,
    'Valor recibido: ' . var_export($rows[0]['comments_count'] ?? null, true)
);

$assert(
    '2.4 La bandeja del coordinador no expone el contador segregado de la sede',
    !str_contains($rawJson, 'public_comments_count'),
    'JSON real: ' . $rawJson
);

$assert(
    '2.5 El payload de triaje no filtra la marca de confidencialidad de los mensajes',
    !str_contains($rawJson, 'is_internal'),
    'JSON real: ' . $rawJson
);

// =====================================================================
// GRUPO 3: Filtros de la bandeja intactos tras el enriquecimiento
// =====================================================================
echo "\n--- Grupo 3: Integridad de los filtros de triaje ---\n";

$invalidStatusRequest = new Request('GET', '/api/coordinator/incidents', ['status' => 'INVENTADO']);
$invalidStatusResponse = $controller->getIncidents($invalidStatusRequest);
$invalidStatusBody = $invalidStatusResponse->getDecodedBody() ?? [];

$assert(
    '3.1 Un filtro de estado inválido sigue respondiendo HTTP 400',
    $invalidStatusResponse->getStatusCode() === 400
        && ($invalidStatusBody['error']['code'] ?? '') === 'INVALID_STATUS_FILTER',
    'Código recibido: ' . $invalidStatusResponse->getStatusCode()
);

$callsAfterInvalidFilter = count($incidentRepo->countCalls);

$assert(
    '3.2 Una petición rechazada no consulta ningún hilo de conversación',
    $callsAfterInvalidFilter === 2,
    'Consultas acumuladas: ' . $callsAfterInvalidFilter
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. FINALIZACIÓN OBJETIVA DE LA MITAD SERVIDOR DE T-COM-16.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
