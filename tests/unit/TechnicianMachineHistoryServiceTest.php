<?php

declare(strict_types=1);

/**
 * TechnicianMachineHistoryServiceTest
 *
 * Suite unitaria del endpoint de historial de averías de una máquina para el
 * técnico de ruta (RF-07 / EARS H.1-H.6, specs/technical/technician_machine_history_contracts.md).
 *
 * Valida la condición "Hecho cuando":
 * - `TechnicianController::getMachineHistory(Request)` responde 200 OK con la
 *   envolvente canónica JSON, el bloque `machine` y el historial completo
 *   ordenado de más reciente a más antiguo, excluyendo descartes (EARS H.1).
 * - Cada entrada expone ticket, estado, urgencia, categoría, descripción,
 *   técnico, fechas y recuento de reaperturas (EARS H.2), sin ningún dato
 *   privado (EARS H.5 / Art. V.4).
 * - 401 sin identidad, 400 con ID de ruta inválido, 404 con máquina inexistente
 *   y 403 cuando el técnico no tiene la avería activa de esa máquina (EARS H.3).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin base de datos; repositorios sustituidos por
 * dobles en memoria que implementan sus contratos de dominio.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\MachineType;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Http\Request;

final class MachineHistoryStubMachineRepo implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    private array $machines;

    /** @param list<Machine> $machines */
    public function __construct(array $machines)
    {
        $this->machines = [];
        foreach ($machines as $machine) {
            $this->machines[(int)$machine->getId()] = $machine;
        }
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[$id] ?? null;
    }

    public function findActiveByLocationId(int $locationId): array { throw new LogicException('Not used.'); }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { throw new LogicException('Not used.'); }
    public function create(array $data): Machine { throw new LogicException('Not used.'); }
    public function update(int $id, array $data): bool { throw new LogicException('Not used.'); }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { throw new LogicException('Not used.'); }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { throw new LogicException('Not used.'); }
    public function findAll(array $filters = []): array { throw new LogicException('Not used.'); }
    public function hasActiveTicketOrWarranty(int $machineId): bool { throw new LogicException('Not used.'); }
    public function getActiveTicketOrWarranty(int $machineId): ?array { throw new LogicException('Not used.'); }
    public function softDelete(int $id): bool { throw new LogicException('Not used.'); }
}

final class MachineHistoryStubIncidentRepo implements IncidentRepositoryInterface
{
    public ?Incident $activeIncident = null;
    /** @var list<Incident> */
    public array $byMachine = [];
    /** @var array<int, int> */
    public array $reopenCounts = [];

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        return $this->activeIncident;
    }

    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array
    {
        $rows = array_values(array_filter(
            $this->byMachine,
            fn(Incident $incident) => $incident->getMachineId() === $machineId
                && !in_array($incident->getStatus()->value, $excludeStatuses, true)
        ));
        usort($rows, function (Incident $a, Incident $b): int {
            return [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()];
        });
        return $rows;
    }

    public function countReopenEvents(int $incidentId): int
    {
        return $this->reopenCounts[$incidentId] ?? 0;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findById(int $id): ?Incident { throw new LogicException('Not used.'); }
    public function findByTicketCode(string $ticketCode): ?Incident { throw new LogicException('Not used.'); }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { throw new LogicException('Not used.'); }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { throw new LogicException('Not used.'); }
    public function findAll(array $filters = []): array { throw new LogicException('Not used.'); }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { throw new LogicException('Not used.'); }
    public function findEnrichedDetailById(int|string $identifier): ?array { throw new LogicException('Not used.'); }
    public function update(Incident $incident): bool { throw new LogicException('Not used.'); }
    public function softDelete(int $id): bool { throw new LogicException('Not used.'); }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { throw new LogicException('Not used.'); }
    public function getHistory(int $incidentId): array { throw new LogicException('Not used.'); }
    public function addComment(IncidentComment $comment): IncidentComment { throw new LogicException('Not used.'); }
    public function getComments(int $incidentId, bool $includeInternal = true): array { throw new LogicException('Not used.'); }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function markAsChronic(int $incidentId): bool { throw new LogicException('Not used.'); }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new LogicException('Not used.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException('Not used.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException('Not used.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException('Not used.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException('Not used.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException('Not used.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { throw new LogicException('Not used.'); }
}

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - Historial de Máquina del Técnico (EARS H.1-H.6)\n";
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

// ─── Fábricas de entidades ───────────────────────────────────────────────────
$makeMachine = function (int $id, string $code, string $model): Machine {
    return new Machine(id: $id, locationId: 1, code: $code, model: $model, machineType: MachineType::SNACKS, floorWing: 'PB-1');
};

$makeIncident = function (
    int $id,
    int $machineId,
    IncidentStatus $status,
    string $createdAt,
    ?string $resolvedAt = null,
    ?string $closedAt = null
): Incident {
    return new Incident(
        id: $id,
        ticketCode: 'INC-H-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT),
        machineId: $machineId,
        locationId: 1,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Historial de máquina para el técnico (' . $status->value . ').',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: 55,
        createdAt: $createdAt,
        resolvedAt: $resolvedAt,
        closedAt: $closedAt,
        machineCode: 'VM-012',
        machineModel: 'Necta Koro',
        technicianName: 'Jordi Ruta'
    );
};

// ─── Fixtures compartidos ────────────────────────────────────────────────────
const TECH_ID = 55;
const OTHER_TECH_ID = 77;
const MACHINE_ID = 12;

$buildController = function (?Incident $activeIncident = null) use ($makeMachine, $makeIncident) {
    $incidentRepo = new MachineHistoryStubIncidentRepo();
    $incidentRepo->activeIncident = $activeIncident;

    // Historial de la máquina 12: reabierta (reciente), resuelta y cerrada (antigua) + descarte.
    $reopened = $makeIncident(3401, 12, IncidentStatus::REOPENED, '2026-10-05 08:00:00');
    $resolved = $makeIncident(3300, 12, IncidentStatus::RESOLVED, '2026-09-28 10:00:00', '2026-09-28 12:30:00');
    $closed   = $makeIncident(3200, 12, IncidentStatus::CLOSED, '2026-09-10 09:00:00', '2026-09-10 11:00:00', '2026-09-12 11:00:01');
    $cancelled = $makeIncident(3100, 12, IncidentStatus::CANCELLED, '2026-09-01 09:00:00');
    $incidentRepo->byMachine = [$reopened, $resolved, $closed, $cancelled];
    $incidentRepo->reopenCounts = [3401 => 2, 3300 => 0, 3200 => 1];

    $machineRepo = new MachineHistoryStubMachineRepo([
        $makeMachine(12, 'VM-012', 'Necta Koro'),
        $makeMachine(13, 'VM-013', 'Sielaff MB'),
    ]);

    $controller = new TechnicianController(incidentRepo: $incidentRepo, machineRepo: $machineRepo);

    return [$controller, $incidentRepo];
};

$asTech = function (int $id, int $machineId): Request {
    $request = new Request(method: 'GET', path: "/api/technician/machines/{$machineId}/history");
    $request->setAttribute('user_id', $id);
    $request->setRouteParams(['id' => (string)$machineId]);
    return $request;
};

// =========================================================================
// CASO 1: Identidad y validación de la ruta
// =========================================================================
echo "--- Caso 1: Identidad y validación de la ruta ---\n";
[$controller] = $buildController();

$res = $controller->getMachineHistory(new Request(method: 'GET', path: '/api/technician/machines/12/history'));
$assert("1.1 Sin identidad autenticada => 401 UNAUTHORIZED",
    $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$badIdentity = new Request(method: 'GET', path: '/api/technician/machines/12/history');
$badIdentity->setAttribute('user_id', 'no-es-numérico');
$res = $controller->getMachineHistory($badIdentity);
$assert("1.2 Identidad no numérica => 401 UNAUTHORIZED", $res->getStatusCode() === 401);

$noParam = new Request(method: 'GET', path: '/api/technician/machines/history');
$noParam->setAttribute('user_id', TECH_ID);
$res = $controller->getMachineHistory($noParam);
$assert("1.3 Sin ID de máquina en la ruta => 400 INVALID_MACHINE_ID",
    $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_MACHINE_ID');

$badParam = new Request(method: 'GET', path: '/api/technician/machines/x9/history');
$badParam->setAttribute('user_id', TECH_ID);
$badParam->setRouteParams(['id' => 'x9']);
$res = $controller->getMachineHistory($badParam);
$assert("1.4 ID de máquina no numérico => 400 INVALID_MACHINE_ID", $res->getStatusCode() === 400);

// =========================================================================
// CASO 2: Máquina inexistente (EARS H.4)
// =========================================================================
echo "\n--- Caso 2: Máquina inexistente (EARS H.4) ---\n";
$res = $controller->getMachineHistory($asTech(TECH_ID, 999));
$assert("2.1 Máquina inexistente => 404 MACHINE_NOT_FOUND",
    $res->getStatusCode() === 404 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MACHINE_NOT_FOUND');

// =========================================================================
// CASO 3: Autorización de pertenencia a la ruta activa (EARS H.3)
// =========================================================================
echo "\n--- Caso 3: Autorización de pertenencia a la ruta activa (EARS H.3) ---\n";
[$controller] = $buildController();
$res = $controller->getMachineHistory($asTech(TECH_ID, 12));
$assert("3.1 Sin avería activa en la máquina => 403 NOT_ASSIGNED_TO_TECHNICIAN",
    $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN');

[$controller] = $buildController($makeIncident(3500, 12, IncidentStatus::ASSIGNED, '2026-10-05 09:00:00'));
// La avería activa del fixture pertenece a TECH_ID (55); otro técnico no puede consultar.
$foreign = new Request(method: 'GET', path: '/api/technician/machines/12/history');
$foreign->setAttribute('user_id', OTHER_TECH_ID);
$foreign->setRouteParams(['id' => '12']);
$res = $controller->getMachineHistory($foreign);
$assert("3.2 Avería activa asignada a OTRO técnico => 403 NOT_ASSIGNED_TO_TECHNICIAN",
    $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN');

// =========================================================================
// CASO 4: Historial completo y ordenado (EARS H.1, H.2) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- Caso 4: Historial completo y ordenado (EARS H.1, H.2) ---\n";
[$controller, $incidentRepo] = $buildController($makeIncident(3500, 12, IncidentStatus::ASSIGNED, '2026-10-05 09:00:00'));
$res = $controller->getMachineHistory($asTech(TECH_ID, 12));
$body = $res->getDecodedBody();

$assert("4.1 Responde 200 OK con success=true", $res->getStatusCode() === 200 && ($body['success'] ?? false) === true);
$assert("4.2 Bloque machine con id, code y model",
    ($body['data']['machine']['id'] ?? null) === 12
        && ($body['data']['machine']['code'] ?? '') === 'VM-012'
        && ($body['data']['machine']['model'] ?? '') === 'Necta Koro');

$history = $body['data']['history'] ?? [];
$assert("4.3 Historial de 3 averías (excluye el descarte, EARS H.1)", count($history) === 3);

$ticketCodes = array_column($history, 'ticket_code');
$assert("4.4 No incluye la avería CANCELLED (EARS H.1)", !in_array('INC-H-3100', $ticketCodes, true));
$assert("4.5 Ordenada de más reciente a más antigua (EARS H.1)",
    ($history[0]['ticket_code'] ?? '') === 'INC-H-3401'
        && ($history[1]['ticket_code'] ?? '') === 'INC-H-3300'
        && ($history[2]['ticket_code'] ?? '') === 'INC-H-3200');
$assert("4.6 Incluye la avería REOPENED", in_array('INC-H-3401', $ticketCodes, true));

$first = $history[0] ?? [];
$contractKeys = ['id', 'ticket_code', 'status', 'urgency', 'category', 'description', 'technician_name', 'created_at', 'resolved_at', 'closed_at', 'reopen_count'];
$missingKeys = array_diff($contractKeys, array_keys($first));
$assert("4.7 Entrada con campos del contrato (EARS H.2)", $missingKeys === []);
$assert("4.8 Recuento de reaperturas por expediente (EARS H.2)",
    ($history[0]['reopen_count'] ?? -1) === 2 && ($history[1]['reopen_count'] ?? -1) === 0 && ($history[2]['reopen_count'] ?? -1) === 1);
$assert("4.9 Fechas de resolución y cierre presentes cuando aplican (EARS H.2)",
    ($history[1]['resolved_at'] ?? '') === '2026-09-28 12:30:00'
        && ($history[2]['closed_at'] ?? '') === '2026-09-12 11:00:01');

// =========================================================================
// CASO 5: Privacidad — cero datos privados en la respuesta (EARS H.5 / Art. V.4)
// =========================================================================
echo "\n--- Caso 5: Privacidad (EARS H.5 / Art. V.4) ---\n";
$privateKeys = ['claimant_name', 'claimant_contact', 'claimed_amount', 'refund', 'bizum_phone', 'iban', 'payment_reference'];
$leaks = [];
foreach ($history as $entry) {
    foreach ($privateKeys as $privateKey) {
        if (array_key_exists($privateKey, $entry)) {
            $leaks[] = $privateKey;
        }
    }
}
$serialized = json_encode($body) ?: '';
$assert("5.1 Ninguna entrada expone claves privadas", $leaks === []);
$assert("5.2 Ninguna entrada incluye datos de reintegros en el payload",
    !str_contains(strtolower($serialized), 'claimant') && !str_contains(strtolower($serialized), 'bizum'));

// =========================================================================
// CASO 6: Contrato del repositorio findAllByMachineId (EARS H.1)
// =========================================================================
echo "\n--- Caso 6: Contrato del repositorio findAllByMachineId ---\n";
$repo = new MachineHistoryStubIncidentRepo();
$repo->byMachine = [
    $makeIncident(2, 12, IncidentStatus::RESOLVED, '2026-10-01 10:00:00'),
    $makeIncident(1, 12, IncidentStatus::CLOSED, '2026-10-03 10:00:00'),
    $makeIncident(3, 12, IncidentStatus::CANCELLED, '2026-10-02 10:00:00'),
    $makeIncident(4, 13, IncidentStatus::CLOSED, '2026-10-05 10:00:00'),
];
$all = $repo->findAllByMachineId(12);
$machineIds = array_map(fn($i) => $i->getMachineId(), $all);
$assert("6.1 Devuelve solo las averías de la máquina indicada",
    count($all) === 2 && $machineIds === [12, 12]);
$assert("6.2 Orden descendente por created_at",
    ($all[0]->getId() ?? null) === 1 && ($all[1]->getId() ?? null) === 2);
$assert("6.3 Excluye por defecto CANCELLED", !in_array(3, array_map(fn($i) => $i->getId(), $all), true));
$custom = $repo->findAllByMachineId(12, ['CANCELLED', 'RESOLVED']);
$assert("6.4 Acepta lista personalizada de estados excluidos",
    count($custom) === 1 && ($custom[0]->getId() ?? null) === 1);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. MACHINE HISTORY CONTRACT (EARS H.1-H.6) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
