<?php

declare(strict_types=1);

namespace VendGuard\Tests\Unit;

require_once __DIR__ . '/../../src/autoload.php';

use DomainException;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\PreventiveCoexistenceBridgeService;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Exception\ReinspectionTemperatureExceededException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

// =============================================================================
// Helper de Aserciones
// =============================================================================
$assertionsCount = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertionsCount;
    $assertionsCount++;
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    global $assertionsCount;
    $assertionsCount++;
    if ($expected !== $actual) {
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        echo "  [FAIL] {$message} (Esperado: {$expStr}, Obtenido: {$actStr})\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

// =============================================================================
// Dobles de Prueba en Memoria
// =============================================================================

class InMemoryIncidentRepoForBridge implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    /** @var array<int, Incident> */
    public array $incidents = [];
    /** @var array<int, list<IncidentComment>> */
    public array $comments = [];
    public int $createCallCount = 0;
    public int $updateCallCount = 0;
    private int $nextId = 100;

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $this->createCallCount++;
        $id = $this->nextId++;
        $persisted = $incident->withId($id);
        $this->incidents[$id] = $persisted;
        return $persisted;
    }

    public function findById(int $id): ?Incident
    {
        return $this->incidents[$id] ?? null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getTicketCode() === strtoupper(trim($ticketCode))) {
                return $inc;
            }
        }
        return null;
    }

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getMachineId() === $machineId && $inc->isActive()) {
                return $inc;
            }
        }
        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getMachineId() === $machineId && ($inc->isActive() || $inc->isResolved())) {
                return $inc;
            }
        }
        return null;
    }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }

    public function findAllByLocation(int $locationId, bool $activeOnly = false): array
    {
        return [];
    }

    public function findAll(array $filters = []): array
    {
        return array_values($this->incidents);
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [];
    }

    public function update(Incident $incident): bool
    {
        $this->updateCallCount++;
        if ($incident->getId() !== null && isset($this->incidents[$incident->getId()])) {
            $this->incidents[$incident->getId()] = $incident;
            return true;
        }
        return false;
    }

    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

    public function softDelete(int $id): bool
    {
        return true;
    }

    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int
    {
        return 1;
    }

    public function getHistory(int $incidentId): array
    {
        return [];
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $this->comments[$comment->getIncidentId()][] = $comment;
        return $comment;
    }

    public function getComments(int $incidentId, bool $includeInternal = true): array
    {
        return $this->comments[$incidentId] ?? [];
    }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }

    public function countReopenEvents(int $incidentId): int
    {
        return 0;
    }

    public function markAsChronic(int $incidentId): bool
    {
        return true;
    }

    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident
    {
        return $this->incidents[$incidentId];
    }

    public function reopen(int $incidentId, string $reasonText): Incident
    {
        return $this->incidents[$incidentId];
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        return $this->incidents[$incidentId];
    }

    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        return $this->incidents[$incidentId];
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        return $this->incidents[$incidentId];
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        if (!isset($this->incidents[$incidentId])) {
            throw new DomainException("Incidencia no encontrada.");
        }

        $cur = $this->incidents[$incidentId];
        $resolved = new Incident(
            id: $cur->getId(),
            ticketCode: $cur->getTicketCode(),
            machineId: $cur->getMachineId(),
            locationId: $cur->getLocationId(),
            category: $cur->getCategory(),
            description: $cur->getDescription(),
            urgency: $cur->getUrgency(),
            status: IncidentStatus::RESOLVED,
            assignedTechnicianId: $technicianId,
            reporterName: $cur->getReporterName(),
            reporterPhone: $cur->getReporterPhone(),
            retainedMoneyAmount: $cur->getRetainedMoneyAmount(),
            photoPath: $cur->getPhotoPath(),
            assignedAt: $cur->getAssignedAt(),
            startedAt: $cur->getStartedAt(),
            pendingPartsReason: $cur->getPendingPartsReason(),
            resolutionDiagnosis: $diagnosis,
            resolutionAction: $action,
            resolvedAt: date('Y-m-d H:i:s'),
            reopenReason: $cur->getReopenReason(),
            reopenedAt: $cur->getReopenedAt(),
            closedAt: $cur->getClosedAt(),
            cancellationReason: $cur->getCancellationReason(),
            cancelledAt: $cur->getCancelledAt(),
            isActiveTicket: 0
        );

        $this->incidents[$incidentId] = $resolved;
        return $resolved;
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

class InMemoryOrderRepoForBridge implements PreventiveOrderRepositoryInterface
{
    /** @var array<int, PreventiveOrder> */
    public array $orders = [];
    public array $linkedIncidents = [];
    private int $nextId = 200;

    public function create(array $data): PreventiveOrder
    {
        $id = $this->nextId++;
        $code = sprintf('PREV-%s-%04d', date('Y'), $id);
        $order = new PreventiveOrder(
            $id,
            $code,
            (int)$data['machine_id'],
            (int)$data['location_id'],
            $data['assigned_technician_id'] ?? null,
            $data['status'] ?? 'PENDING_ASSIGNMENT',
            $data['order_type'] ?? 'ROUTINE',
            $data['scheduled_date'] ?? date('Y-m-d'),
            $data['due_date'] ?? date('Y-m-d'),
            date('Y-m-d H:i:s'),
            null,
            null,
            null,
            null,
            false,
            $data['notes'] ?? null,
            null,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            null
        );
        $this->orders[$id] = $order;
        return $order;
    }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder
    {
        foreach ($this->orders as $o) {
            if ($o->getOrderCode() === $orderCode) {
                return $o;
            }
        }
        return null;
    }

    public function hasActiveOrPendingOrder(int $machineId): bool
    {
        return false;
    }

    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool
    {
        return true;
    }

    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool
    {
        return true;
    }

    public function startInspection(int $orderId, int $technicianId): bool
    {
        return true;
    }

    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool {
        if (!isset($this->orders[$orderId])) {
            return false;
        }
        $o = $this->orders[$orderId];
        $this->orders[$orderId] = new PreventiveOrder(
            $o->getId(),
            $o->getOrderCode(),
            $o->getMachineId(),
            $o->getLocationId(),
            $o->getAssignedTechnicianId(),
            'COMPLETED',
            $o->getOrderType(),
            $o->getScheduledDate(),
            $o->getDueDate(),
            $o->getStartedAt(),
            date('Y-m-d H:i:s'),
            $temperatureMeasured,
            $result,
            $linkedIncidentId ?? $o->getLinkedIncidentId(),
            $isQuarantineTriggered,
            $notes ?? $o->getNotes(),
            null,
            $o->getCreatedAt(),
            date('Y-m-d H:i:s'),
            null
        );
        return true;
    }

    public function linkIncident(int $orderId, int $incidentId): bool
    {
        $this->linkedIncidents[$orderId] = $incidentId;
        if (isset($this->orders[$orderId])) {
            $o = $this->orders[$orderId];
            $this->orders[$orderId] = new PreventiveOrder(
                $o->getId(),
                $o->getOrderCode(),
                $o->getMachineId(),
                $o->getLocationId(),
                $o->getAssignedTechnicianId(),
                $o->getStatus(),
                $o->getOrderType(),
                $o->getScheduledDate(),
                $o->getDueDate(),
                $o->getStartedAt(),
                $o->getCompletedAt(),
                $o->getTemperatureMeasured(),
                $o->getResult(),
                $incidentId,
                $o->isQuarantineTriggered(),
                $o->getNotes(),
                $o->getCancellationReason(),
                $o->getCreatedAt(),
                date('Y-m-d H:i:s'),
                null
            );
        }
        return true;
    }

    public function softCancel(int $orderId, string $reason): bool
    {
        return true;
    }

    public function findForCoordinatorList(array $filters = []): array { return []; }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return []; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

class InMemoryMachineRepoForBridge implements MachineRepositoryInterface
{
    public array $updated = [];

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new DomainException('Not implemented'); }
    public function update(int $id, array $data): bool
    {
        $this->updated[$id] = $data;
        return true;
    }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

    public function softDelete(int $id): bool { return true; }
}

class InMemorySettingsRepoForBridge implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
    public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool
    {
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_frequency_days'] = $sanitaryFrequencyDays;
            if ($nextSanitaryInspectionDue !== null) {
                $this->machines[$machineId]['next_sanitary_inspection_due'] = $nextSanitaryInspectionDue;
            }
        }
        return true;
    }
    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
    public function resumeSeasonalPause(int $machineId): bool { return true; }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? [
            'id' => $machineId,
            'code' => "VEND-TEST-{$machineId}",
            'machine_type' => 'PERISHABLE_FOOD',
            'sanitary_status' => 'QUARANTINE',
            'sanitary_frequency_days' => 15,
            'default_frequency_days' => 15,
            'is_seasonal_pause' => 0,
        ];
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }
        return true;
    }
}

class SpyAuditLoggerForBridge extends AuditLogger
{
    public array $incidentEvents = [];
    public array $machineEvents = [];

    public function __construct()
    {
    }

    public function logTicketEvent(int $ticketId, string $action, array $user, ?array $previousState, array $newState, ?array $metadata = null): \VendGuard\Core\Domain\Model\AuditEvent
    {
        $this->incidentEvents[] = [
            'ticket_id' => $ticketId,
            'action' => $action,
            'user' => $user,
            'before' => $previousState,
            'after' => $newState,
            'metadata' => $metadata,
        ];
        return new \VendGuard\Core\Domain\Model\AuditEvent(
            id: 1,
            entityType: 'TICKET',
            entityId: $ticketId,
            action: $action,
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'TECHNICIAN',
            userName: $user['name'] ?? 'Técnico',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );
    }

    public function logMachineEvent(int $machineId, string $action, array $user, ?array $previousState, array $newState, ?array $metadata = null): \VendGuard\Core\Domain\Model\AuditEvent
    {
        $this->machineEvents[] = [
            'machine_id' => $machineId,
            'action' => $action,
            'user' => $user,
            'before' => $previousState,
            'after' => $newState,
            'metadata' => $metadata,
        ];
        return new \VendGuard\Core\Domain\Model\AuditEvent(
            id: 1,
            entityType: 'MACHINE',
            entityId: $machineId,
            action: $action,
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'TECHNICIAN',
            userName: $user['name'] ?? 'Técnico',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );
    }
}

// =============================================================================
// INICIO DE LA BATERÍA DE PRUEBAS
// =============================================================================

echo "====================================================================================\n";
echo " VendGuard: Pruebas Unitarias de PreventiveCoexistenceBridgeService (T-PREV-11)\n";
echo "====================================================================================\n\n";

// Instanciación de componentes
$incidentRepo = new InMemoryIncidentRepoForBridge();
$orderRepo = new InMemoryOrderRepoForBridge();
$settingsRepo = new InMemorySettingsRepoForBridge();
$machineRepo = new InMemoryMachineRepoForBridge();
$auditLogger = new SpyAuditLoggerForBridge();

$bridgeService = new PreventiveCoexistenceBridgeService(
    incidentRepo: $incidentRepo,
    orderRepo: $orderRepo,
    settingsRepo: $settingsRepo,
    machineRepo: $machineRepo,
    certificateRepo: null,
    auditLogger: $auditLogger
);

// Configuración de máquina de perecederos
$settingsRepo->machines[10] = [
    'id' => 10,
    'code' => 'VEND-PERISH-10',
    'location_id' => 1,
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'QUARANTINE',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
    'is_seasonal_pause' => 0,
];

// Creación de orden preventiva no conforme inicial
$order1 = $orderRepo->create([
    'machine_id' => 10,
    'location_id' => 1,
    'assigned_technician_id' => 3,
    'status' => 'IN_INSPECTION',
    'order_type' => 'ROUTINE',
    'scheduled_date' => '2026-09-27',
    'due_date' => '2026-09-27',
]);

// -----------------------------------------------------------------------------
echo "--- 1. Caso A: Creación de Incidencia Vinculada sin Ticket Previo (RF-PREV-05, EARS 5.2) ---\n";
// -----------------------------------------------------------------------------
$resCaseA = $bridgeService->handleNonConformity(
    orderId: $order1->getId(),
    technicianId: 3,
    temperature: 6.8, // Rotura térmica > 4.0 °C
    nonConformItems: [
        ['item_code' => 'TEMP_PROBE', 'title' => 'Sonda Térmica', 'is_critical' => true, 'status' => 'FAIL', 'observations' => '6.8 °C (> 4.0 °C)'],
        ['item_code' => 'EVAPORATOR_FROST', 'title' => 'Evaporador', 'is_critical' => true, 'status' => 'FAIL', 'observations' => 'Bloque de hielo'],
    ],
    notes: 'Compresor no enfría adecuadamente.',
    actor: ['id' => 3, 'name' => 'Carlos Técnico', 'role' => 'TECHNICIAN']
);

assertEquals('CREATED_NEW_INCIDENT', $resCaseA['mode'], '1.1 Modo devuelto es CREATED_NEW_INCIDENT');
assertEquals('CRITICAL', $resCaseA['urgency'], '1.2 Urgencia asignada es CRITICAL por rotura térmica');
assertEquals('TEMPERATURE_COLD', $resCaseA['category'], '1.3 Categoría asignada es TEMPERATURE_COLD');
assertEquals('IN_PROGRESS', $resCaseA['status'], '1.4 Estado inicial es IN_PROGRESS');
assertEquals(3, $resCaseA['assigned_to'], '1.5 Incidencia autoasignada al técnico de campo presente');
assertEquals(1, $incidentRepo->createCallCount, '1.6 Se invocó create() exactamente 1 vez en el repositorio');
assertEquals($resCaseA['incident_id'], $orderRepo->linkedIncidents[$order1->getId()], '1.7 Orden preventiva vinculada a la nueva incidencia');

$createdIncident = $incidentRepo->findById($resCaseA['incident_id']);
assertTrue($createdIncident !== null, '1.8 La entidad creada existe en persistencia');
assertTrue(str_contains($createdIncident->getDescription(), '6.8 °C'), '1.9 La descripción contiene la temperatura medida');
assertTrue(str_contains($createdIncident->getDescription(), 'Sonda Térmica'), '1.10 La descripción incluye ítems no conformes');

// Verificar evento de auditoría
$lastAuditEvent = end($auditLogger->incidentEvents);
assertEquals('PREVENTIVE_TRIGGERED_INCIDENT', $lastAuditEvent['action'], '1.11 Evento de auditoría PREVENTIVE_TRIGGERED_INCIDENT registrado');

// -----------------------------------------------------------------------------
echo "\n--- 2. Caso B: Coexistencia con Ticket Activo Preexistente (Constitución Art. V.2) ---\n";
// -----------------------------------------------------------------------------
// Configuramos una segunda máquina que YA tiene una incidencia activa por monedero atascado (urgencia MEDIUM)
$settingsRepo->machines[20] = [
    'id' => 20,
    'code' => 'VEND-PERISH-20',
    'location_id' => 1,
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'QUARANTINE',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
    'is_seasonal_pause' => 0,
];

$preexistingIncident = new Incident(
    id: null,
    ticketCode: 'INC-2026-9001',
    machineId: 20,
    locationId: 1,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Monedero atascado con moneda de 50 céntimos.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: 3
);
$persistedPreexisting = $incidentRepo->create($preexistingIncident);
$createCountBefore = $incidentRepo->createCallCount;

// Orden preventiva para máquina 20
$order2 = $orderRepo->create([
    'machine_id' => 20,
    'location_id' => 1,
    'assigned_technician_id' => 3,
    'status' => 'IN_INSPECTION',
    'order_type' => 'ROUTINE',
    'scheduled_date' => '2026-09-27',
    'due_date' => '2026-09-27',
]);

// Ejecutar coexistencia ante rotura de frío
$resCaseB = $bridgeService->handleNonConformity(
    orderId: $order2->getId(),
    technicianId: 3,
    temperature: 7.2,
    nonConformItems: [
        ['item_code' => 'TEMP_PROBE', 'title' => 'Sonda Térmica', 'is_critical' => true, 'status' => 'FAIL', 'observations' => '7.2 °C (> 4.0 °C)'],
    ],
    notes: 'Ventilador averiado',
    actor: ['id' => 3, 'name' => 'Carlos Técnico', 'role' => 'TECHNICIAN']
);

assertEquals('APPENDED_TO_EXISTING_INCIDENT', $resCaseB['mode'], '2.1 Modo devuelto es APPENDED_TO_EXISTING_INCIDENT');
assertEquals('INC-2026-9001', $resCaseB['ticket_code'], '2.2 Se asocia al ticket preexistente INC-2026-9001');
assertEquals($createCountBefore, $incidentRepo->createCallCount, '2.3 Art. V.2 respetado: CERO creación de tickets duplicados (create no fue invocado)');
assertTrue($resCaseB['escalated'], '2.4 La urgencia fue escalada');
assertEquals('MEDIUM', $resCaseB['previous_urgency'], '2.5 Urgencia previa era MEDIUM');
assertEquals('CRITICAL', $resCaseB['new_urgency'], '2.6 Urgencia nueva es CRITICAL por rotura de frío');

// Comprobar que se añadió el apunte a incident_comments
$comments = $incidentRepo->getComments($persistedPreexisting->getId());
assertEquals(1, count($comments), '2.7 Se añadió exactamente 1 apunte formal a la bitácora de la incidencia');
assertTrue(str_contains($comments[0]->getCommentText(), '7.2 °C'), '2.8 El comentario contiene la evidencia térmica');
assertTrue(str_contains($comments[0]->getCommentText(), 'ALERTA PREVENTIVA'), '2.9 El comentario está tipificado como alerta preventiva');

// Comprobar que la orden quedó vinculada a la incidencia preexistente
assertEquals($persistedPreexisting->getId(), $orderRepo->linkedIncidents[$order2->getId()], '2.10 Orden vinculada a la incidencia activa preexistente');

// Comprobar que en la persistencia la urgencia se actualizó a CRITICAL
$updatedTicket = $incidentRepo->findById($persistedPreexisting->getId());
assertEquals(UrgencyLevel::CRITICAL, $updatedTicket->getUrgency(), '2.11 Urgencia persistida en base de datos es CRITICAL');

// Auditoría de escalado
$auditEscalation = end($auditLogger->incidentEvents);
assertEquals('URGENCY_ESCALATED_CRITICAL_BY_PREVENTIVE', $auditEscalation['action'], '2.12 Auditoría inmutable de escalado registrada');

// -----------------------------------------------------------------------------
echo "\n--- 3. Validación de Cierre Justificado Obligatorio (Constitución Art. V.1) ---\n";
// -----------------------------------------------------------------------------
// Intentar resolver con diagnóstico < 20 caracteres
$threwDiag = false;
try {
    $bridgeService->resolveCorrectiveIncident(
        incidentId: $persistedPreexisting->getId(),
        technicianId: 3,
        diagnosis: 'Termostato roto', // 15 caracteres
        solution: 'Se procede a la sustitución completa del termostato y relé.'
    );
} catch (InvalidResolutionException $e) {
    $threwDiag = true;
    assertTrue(str_contains($e->getMessage(), 'diagnóstico técnico'), '3.1 Mensaje especifica insuficiencia en diagnóstico');
}
assertTrue($threwDiag, '3.2 Bloquea resolución si diagnóstico tiene < 20 caracteres (Art. V.1)');

// Intentar resolver con solución < 20 caracteres
$threwSol = false;
try {
    $bridgeService->resolveCorrectiveIncident(
        incidentId: $persistedPreexisting->getId(),
        technicianId: 3,
        diagnosis: 'Termostato roto con resistencia descalibrada.',
        solution: 'Cambiado' // 8 caracteres
    );
} catch (InvalidResolutionException $e) {
    $threwSol = true;
    assertTrue(str_contains($e->getMessage(), 'acción correctiva'), '3.3 Mensaje especifica insuficiencia en solución');
}
assertTrue($threwSol, '3.4 Bloquea resolución si solución técnica tiene < 20 caracteres (Art. V.1)');

// Resolver con ambos campos >= 20 caracteres válidos
$resolvedIncident = $bridgeService->resolveCorrectiveIncident(
    incidentId: $persistedPreexisting->getId(),
    technicianId: 3,
    diagnosis: 'Sonda NTC defectuosa provocando lectura errónea y desconexión.',
    solution: 'Sustitución de sonda NTC por recambio original y calibración en banco.'
);
assertEquals(IncidentStatus::RESOLVED, $resolvedIncident->getStatus(), '3.5 Incidencia pasa a estado RESOLVED con justificación completa');
assertEquals('Sonda NTC defectuosa provocando lectura errónea y desconexión.', $resolvedIncident->getResolutionDiagnosis(), '3.6 Diagnóstico almacenado fielmente');
assertEquals('Sustitución de sonda NTC por recambio original y calibración en banco.', $resolvedIncident->getResolutionAction(), '3.7 Acción correctiva almacenada fielmente');

// -----------------------------------------------------------------------------
echo "\n--- 4. Reinspección Térmica y Levantamiento de Cuarentena (Art. II y RF-PREV-08) ---\n";
// -----------------------------------------------------------------------------
// 4.1 Intento de reinspección con temperatura > 4.0 °C en perecederos
$threwReinspect = false;
try {
    $bridgeService->reinspectAfterSubsanacion(
        orderId: $order2->getId(),
        technicianId: 3,
        temperature: 4.8, // > 4.0 °C
        notes: 'Sonda cambiada pero aún no estabiliza bien.'
    );
} catch (ReinspectionTemperatureExceededException $e) {
    $threwReinspect = true;
    assertEquals(422, $e->getHttpStatusCode(), '4.1 Código HTTP es 422 Unprocessable Entity');
    assertEquals('REINSPECTION_TEMPERATURE_TOO_HIGH', $e->getErrorCode(), '4.2 Código de error REINSPECTION_TEMPERATURE_TOO_HIGH');
    assertEquals(4.8, $e->getMeasuredTemperature(), '4.3 Temperatura medida capturada en excepción (4.8 °C)');
    assertEquals(4.0, $e->getMaximumAllowedTemperature(), '4.4 Temperatura máxima permitida es 4.0 °C');
}
assertTrue($threwReinspect, '4.5 Bloquea levantamiento de cuarentena si temperatura de reinspección > 4.0 °C (Art. II)');

// 4.2 Reinspección Conforme con temperatura <= 4.0 °C (ej: 3.2 °C)
$resReinspection = $bridgeService->reinspectAfterSubsanacion(
    orderId: $order2->getId(),
    technicianId: 3,
    temperature: 3.2,
    notes: 'Temperatura estabilizada a 3.2 °C. Equipo apto para consumo.',
    actor: ['id' => 3, 'name' => 'Carlos Técnico', 'operator_code' => 'OP-03']
);

assertEquals('REINSPECTION', $resReinspection['order_type'], '4.6 Orden de tipo REINSPECTION generada');
assertEquals('CONFORME', $resReinspection['result'], '4.7 Dictamen de reinspección es CONFORME');
assertEquals(3.2, $resReinspection['temperature_measured'], '4.8 Temperatura registrada 3.2 °C');
assertEquals('OK', $resReinspection['machine_sanitary_status'], '4.9 Estado sanitario de la máquina restituido a OK');
assertTrue($resReinspection['qr_unblocked'], '4.10 Código QR desbloqueado para consumo y compra');
assertTrue(isset($resReinspection['certificate']['certificate_code']), '4.11 Certificado sanitario emitido');
assertEquals('OP-03', $resReinspection['certificate']['technician_operator_code'], '4.12 Código de operador técnico respetado (Art. V.4)');

// Comprobar estado en configuración de máquina
$machineAfter = $settingsRepo->getMachineSettings(20);
assertEquals('OK', $machineAfter['sanitary_status'], '4.13 sanitary_status en persistencia es OK');
assertTrue(!empty($machineAfter['last_sanitary_inspection_at'] ?? $machineRepo->updated[20]['last_sanitary_inspection_at']), '4.14 Registrada marca temporal de última inspección');
assertTrue(!empty($machineAfter['next_sanitary_inspection_due']), '4.15 Recalculada fecha límite de próxima inspección');

// Auditoría de reinspección
$machineAuditEvent = end($auditLogger->machineEvents);
assertEquals('REINSPECTION_COMPLETED', $machineAuditEvent['action'], '4.16 Auditoría REINSPECTION_COMPLETED registrada');

// -----------------------------------------------------------------------------
echo "\n--- 5. Reinspección Condicionada por Otro Ticket Mecánico Activo (EARS 8.3) ---\n";
// -----------------------------------------------------------------------------
// Máquina 30: perecedera, en cuarentena previa, pero con ticket mecánico aún activo
$settingsRepo->machines[30] = [
    'id' => 30,
    'code' => 'VEND-PERISH-30',
    'location_id' => 1,
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'QUARANTINE',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
    'is_seasonal_pause' => 0,
];

// Incidencia mecánica activa (en curso)
$mechanicalIncident = new Incident(
    id: null,
    ticketCode: 'INC-2026-9030',
    machineId: 30,
    locationId: 1,
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Brazo extractor atascado en espiral 3.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: 3
);
$incidentRepo->create($mechanicalIncident);

$order3 = $orderRepo->create([
    'machine_id' => 30,
    'location_id' => 1,
    'assigned_technician_id' => 3,
    'status' => 'IN_INSPECTION',
    'order_type' => 'ROUTINE',
    'scheduled_date' => '2026-09-27',
    'due_date' => '2026-09-27',
]);

// Reinspeccionar temperatura satisfactoria (2.8 °C)
$resConditioned = $bridgeService->reinspectAfterSubsanacion(
    orderId: $order3->getId(),
    technicianId: 3,
    temperature: 2.8,
    notes: 'Frío subsanado, pero pendiente reparación de espiral mecánico.'
);

assertEquals('OK', $resConditioned['machine_sanitary_status'], '5.1 Estado sanitario restituido a OK');
assertEquals(false, $resConditioned['qr_unblocked'], '5.2 EARS 8.3: QR permanece bloqueado porque aún existe otra incidencia activa pendiente');

// =============================================================================
// RESUMEN FINAL
// =============================================================================
echo "\n====================================================================================\n";
echo " Total Aserciones: {$assertionsCount}\n";
echo " RESULTADO: 100% EN VERDE. Todas las reglas de PreventiveCoexistenceBridgeService verificadas.\n";
echo " Condición T-PREV-11 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "====================================================================================\n";
