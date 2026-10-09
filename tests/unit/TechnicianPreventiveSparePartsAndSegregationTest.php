<?php

declare(strict_types=1);

/**
 * TechnicianPreventiveSparePartsAndSegregationTest
 * 
 * Batería de pruebas unitarias para la extensión de preventivos con repuestos (RF-REP-07)
 * y el blindaje constitucional de segregación de datos de sede (RF-REP-10 / Art. V.4).
 * 
 * Verifica que se cumplan rigurosamente los criterios de T-SPARE-12:
 * 1. TechnicianPreventiveController::completeInspection admite la declaración opcional de piezas
 *    sustituidas en preventivos con snapshot de coste y destino DESGUACE/TALLER.
 * 2. Blindaje Constitución Art. II: La sustitución de piezas en un preventivo NO_CONFORME
 *    no levanta la cuarentena sanitaria automáticamente.
 * 3. LocationPortalController garantiza la exclusión estricta de piezas, códigos y costes
 *    en todas las respuestas dirigidas a usuarios de sede.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\PreventiveChecklistEvaluationService;
use VendGuard\Application\Service\PreventiveCoexistenceBridgeService;
use VendGuard\Application\Service\SanitaryCertificateService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\PreventiveItem;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Model\PreventiveOrderStatus;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\PreventiveSettings;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Controller\TechnicianPreventiveController;
use VendGuard\Presentation\Http\Request;

echo "========================================================================================\n";
echo " VendGuard: Test Unitario - TechnicianPreventiveSparePartsAndSegregationTest (T-SPARE-12)\n";
echo "========================================================================================\n\n";

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

// =============================================================================
// Mocks en Memoria para T-SPARE-12
// =============================================================================

class MockAuditLogRepo implements AuditLogRepositoryInterface
{
    public array $logs = [];
    public function log(\VendGuard\Core\Domain\Model\AuditEvent $event): \VendGuard\Core\Domain\Model\AuditEvent
    {
        $this->logs[] = $event;
        return $event;
    }
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return $this->logs; }
    public function countEvents(array $filters = []): int { return count($this->logs); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

class MockPrevOrderRepo implements PreventiveOrderRepositoryInterface
{
    /** @var array<int, PreventiveOrder> */
    public array $orders = [];
    public int $nextId = 200;

    public function create(array $data): PreventiveOrder
    {
        $id = $this->nextId++;
        $code = $data['order_code'] ?? sprintf('PREV-2026-%04d', $id);
        $order = new PreventiveOrder(
            $id,
            $code,
            (int)$data['machine_id'],
            (int)$data['location_id'],
            isset($data['assigned_technician_id']) && $data['assigned_technician_id'] !== null ? (int)$data['assigned_technician_id'] : null,
            is_string($data['status'] ?? null) ? $data['status'] : ($data['status']?->value ?? 'PENDING_ASSIGNMENT'),
            $data['order_type'] ?? 'ROUTINE',
            (string)($data['scheduled_date'] ?? date('Y-m-d')),
            (string)($data['due_date'] ?? date('Y-m-d', strtotime('+7 days'))),
            null,
            null,
            null,
            null,
            null,
            false,
            $data['notes'] ?? null
        );
        $this->orders[$id] = $order;
        return $order;
    }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder { return $this->orders[$id] ?? null; }
    public function findByCode(string $c, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function hasActiveOrPendingOrder(int $m): bool { return false; }
    public function assignTechnician(int $o, int $t, string $s): bool { return true; }
    public function claimOrderOpportunistically(int $o, int $t): bool { return true; }
    public function startInspection(int $o, int $t): bool { return true; }

    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool {
        if (!isset($this->orders[$orderId])) return false;
        $cur = $this->orders[$orderId];
        $this->orders[$orderId] = new PreventiveOrder(
            $cur->getId(),
            $cur->getOrderCode(),
            $cur->getMachineId(),
            $cur->getLocationId(),
            $cur->getAssignedTechnicianId(),
            'COMPLETED',
            $cur->getOrderType(),
            $cur->getScheduledDate(),
            $cur->getDueDate(),
            $cur->getStartedAt(),
            date('Y-m-d H:i:s'),
            $temperatureMeasured,
            $result,
            $linkedIncidentId,
            $isQuarantineTriggered,
            $notes ?? $cur->getNotes()
        );
        return true;
    }

    public function linkIncident(int $o, int $i): bool { return true; }
    public function softCancel(int $o, string $r): bool { return true; }
    public function findForCoordinatorList(array $f = []): array { return []; }
    public function countForCoordinatorList(array $f = []): int { return 0; }
    public function findForTechnicianRoute(int $t, ?int $l = null): array { return []; }
    public function countOverdueOrders(): int { return 0; }
    public function findNonConformityStats(?int $p = null): array { return []; }
    public function countPendingReinspections(): int { return 0; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

class MockPrevSettingsRepo implements PreventiveSettingsRepositoryInterface
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
            'code' => "VEND-BCN-{$machineId}",
            'machine_type' => 'PERISHABLE_FOOD',
            'sanitary_status' => 'OK',
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

class MockPrevItemRepo implements PreventiveItemRepositoryInterface
{
    /** @var array<int, list<PreventiveOrderItem>> */
    public array $items = [];

    public function saveOrderItems(int $orderId, array $items): bool
    {
        $this->items[$orderId] = $items;
        return true;
    }

    public function findByOrderId(int $orderId): array
    {
        return $this->items[$orderId] ?? [];
    }

    public function findById(int $itemId): ?PreventiveOrderItem { return null; }
    public function attachPhoto(int $itemId, string $photoPath): bool { return true; }
    public function attachPhotoByItemCode(int $orderId, string $itemCode, string $photoPath): bool { return true; }
    public function findCriticalFailures(int $orderId): array { return []; }
    public function hasCriticalFailures(int $orderId): bool { return false; }
}

class MockPrevCertRepo implements SanitaryCertificateRepositoryInterface
{
    /** @var array<int, SanitaryCertificate> */
    public array $certificates = [];
    public int $nextId = 300;

    public function createCertificate(array $data): SanitaryCertificate
    {
        $id = $this->nextId++;
        $code = $data['certificate_code'] ?? sprintf('CERT-2026-%04d', $id);
        $cert = new SanitaryCertificate(
            id: $id,
            certificateCode: $code,
            preventiveOrderId: (int)$data['preventive_order_id'],
            machineId: (int)$data['machine_id'],
            locationId: (int)$data['location_id'],
            technicianId: (int)$data['technician_id'],
            technicianName: (string)($data['technician_name'] ?? 'Técnico Autorizado'),
            technicianOperatorCode: (string)($data['technician_operator_code'] ?? 'OP-01'),
            inspectionDate: (string)$data['inspection_date'],
            validUntil: (string)$data['valid_until'],
            temperatureMeasured: isset($data['temperature_measured']) ? (float)$data['temperature_measured'] : null,
            result: (string)$data['result'],
            status: $data['status'] ?? 'VALID'
        );
        $this->certificates[$id] = $cert;
        return $cert;
    }

    public function findById(int $id): ?SanitaryCertificate { return $this->certificates[$id] ?? null; }
    public function findByCertificateCode(string $code): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if ($c->getCertificateCode() === $code) {
                return $c;
            }
        }
        return null;
    }
    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate { return null; }
    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if ($c->getMachineId() === $machineId && $c->getStatus() === 'VALID') {
                return $c;
            }
        }
        return null;
    }
    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if ($c->getMachineId() === $machineId) {
                return $c;
            }
        }
        return null;
    }
    public function suspendByMachineId(int $machineId, string $reason): int { return 1; }
    public function revokeByMachineId(int $machineId, string $reason): int { return 1; }
    public function getGlobalSiteReport(int $locationId): array { return []; }
}

class MockPrevReplacedPartRepo implements IncidentReplacedPartRepositoryInterface
{
    /** @var array<int, IncidentReplacedPart> */
    public array $replacedParts = [];
    private int $nextId = 1;

    public function insertReplacedPart(IncidentReplacedPart $p): IncidentReplacedPart
    {
        $id = $this->nextId++;
        $created = new IncidentReplacedPart(
            id: $id,
            interventionType: $p->getInterventionType(),
            incidentId: $p->getIncidentId(),
            preventiveOrderId: $p->getPreventiveOrderId(),
            machineId: $p->getMachineId(),
            locationId: $p->getLocationId(),
            technicianId: $p->getTechnicianId(),
            sparePartId: $p->getSparePartId(),
            isOutOfCatalog: $p->isOutOfCatalog(),
            customPartName: $p->getCustomPartName(),
            quantity: $p->getQuantity(),
            unitCostSnapshot: $p->getUnitCostSnapshot(),
            oldPartDestination: $p->getOldPartDestination(),
            notes: $p->getNotes(),
            installedAt: $p->getInstalledAt(),
            partCode: $p->getPartCode(),
            partName: $p->getPartName()
        );
        $this->replacedParts[$id] = $created;
        return $created;
    }

    public function insertManyReplacedParts(array $parts): array
    {
        $result = [];
        foreach ($parts as $p) {
            $result[] = $this->insertReplacedPart($p);
        }
        return $result;
    }

    public function findById(int $id): ?IncidentReplacedPart { return $this->replacedParts[$id] ?? null; }
    public function findByIncidentId(int $incidentId): array { return []; }

    public function findByPreventiveOrderId(int $preventiveOrderId): array
    {
        return array_values(array_filter(
            $this->replacedParts,
            fn(IncidentReplacedPart $p) => $p->getPreventiveOrderId() === $preventiveOrderId
        ));
    }

    public function getCostSummaryByMachineModel(?int $periodDays = null): array { return []; }
    public function getCostSummaryByLocation(?int $periodDays = null): array { return []; }
    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array { return []; }
    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array { return []; }
    public function findAllForExport(?int $periodDays = null): array { return []; }
}

class MockPrevSparePartRepo implements SparePartRepositoryInterface
{
    /** @var array<int, SparePart> */
    public array $parts = [];
    private int $nextId = 1;

    public function create(SparePart $s): SparePart
    {
        $id = $this->nextId++;
        $created = new SparePart(
            id: $id,
            partCode: $s->getPartCode(),
            name: $s->getName(),
            category: $s->getCategory(),
            manufacturer: $s->getManufacturer(),
            referenceCost: $s->getReferenceCost(),
            isActive: $s->isActive(),
            notes: $s->getNotes(),
            compatibleModels: $s->getCompatibleModels(),
            totalInstalledUnits: 0
        );
        $this->parts[$id] = $created;
        return $created;
    }

    public function update(SparePart $s): bool
    {
        $id = (int)$s->getId();
        if (!isset($this->parts[$id])) return false;
        $this->parts[$id] = $s;
        return true;
    }

    public function softDelete(int $id): bool { return true; }
    public function updateStatus(int $id, bool $isActive): bool { return true; }
    public function findById(int $id): ?SparePart { return $this->parts[$id] ?? null; }
    public function findByCode(string $c): ?SparePart { return null; }
    public function isCodeExists(string $c, ?int $e = null): bool { return false; }
    public function findAll(?string $s = null, ?string $c = null, ?string $m = null, ?bool $a = null): array { return []; }
    public function findCompatibleWithModel(string $m, bool $o = true): array { return []; }
    public function findDistinctMachineModels(): array { return []; }
}

class MockPrevLocationRepo implements LocationRepositoryInterface
{
    public array $locations = [];
    public function findById(int $id): ?Location { return $this->locations[$id] ?? null; }
    public function findBySiteCode(string $code): ?Location
    {
        foreach ($this->locations as $loc) {
            if (strcasecmp($loc->getSiteCode(), $code) === 0) return $loc;
        }
        return null;
    }
    public function findAllActive(): array { return array_values($this->locations); }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new \DomainException("Not implemented"); }
    public function update(int $id, array $data): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return true; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

class MockPrevMachineRepo implements MachineRepositoryInterface
{
    public array $machines = [];
    public function findActiveByLocationId(int $locationId): array
    {
        return array_values(array_filter($this->machines, fn(Machine $m) => $m->getLocationId() === $locationId));
    }
    public function findById(int $id, bool $w = true, bool $a = false): ?Machine { return $this->machines[$id] ?? null; }
    public function findByCode(string $c, bool $w = true, bool $a = false): ?Machine { return null; }
    public function create(array $d): Machine { throw new RuntimeException("Stub"); }
    public function update(int $id, array $d): bool { return true; }
    public function transfer(int $i, int $t, string $f, ?string $n = null): bool { return true; }
    public function restoreWithLocation(int $i, ?int $n = null, ?string $f = null): bool { return true; }
    public function findAll(array $f = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $m): bool { return false; }
    public function getActiveTicketOrWarranty(int $m): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

class MockPrevIncidentRepo implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    public array $incidents = [];
    public function create(Incident $i, ?int $u = null, ?string $n = null): Incident
    {
        $id = $i->getId() ?? (count($this->incidents) + 1);
        $created = new Incident(
            id: $id,
            ticketCode: $i->getTicketCode(),
            machineId: $i->getMachineId(),
            locationId: $i->getLocationId(),
            category: $i->getCategory(),
            description: $i->getDescription(),
            urgency: $i->getUrgency(),
            status: $i->getStatus(),
            assignedTechnicianId: $i->getAssignedTechnicianId(),
            machineModel: $i->getMachineModel(),
            pendingPartsReason: $i->getPendingPartsReason()
        );
        $this->incidents[$id] = $created;
        return $created;
    }
    public function findById(int $id): ?Incident { return $this->incidents[$id] ?? null; }
    public function findByTicketCode(string $c): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getTicketCode() === $c) return $inc;
        }
        return null;
    }
    public function findActiveByMachineId(int $m): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $m): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $l, bool $a = false): array { return []; }
    public function findAll(array $f = []): array { return []; }
    public function findAssignedToTechnician(int $t, array $s = []): array { return []; }
    public function update(Incident $i): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $i, ?int $u, ?string $f, string $t, ?string $n = null): int { return 1; }
    public function getHistory(int $i): array { return []; }
    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $c): \VendGuard\Core\Domain\Model\IncidentComment { return $c; }
    public function getComments(int $i, bool $in = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $i): int { return 0; }
    public function markAsChronic(int $i): bool { return true; }
    public function assign(int $i, int $t, ?int $c = null, ?string $o = null, ?string $r = null): Incident { throw new RuntimeException("Stub"); }
    public function reopen(int $i, string $r): Incident
    {
        $inc = $this->incidents[$i];
        // El doble replica el contrato real de PdoIncidentRepository::reopen(): el
        // expediente pasa a REOPENED y queda desasignado (EARS 9.1). Devolver otro
        // estado dejaría el test del contrato de la respuesta sin valor probatorio.
        $reopened = new Incident(
            id: $inc->getId(),
            ticketCode: $inc->getTicketCode(),
            machineId: $inc->getMachineId(),
            locationId: $inc->getLocationId(),
            category: $inc->getCategory(),
            description: $inc->getDescription(),
            urgency: $inc->getUrgency(),
            status: IncidentStatus::REOPENED,
            assignedTechnicianId: null,
            machineModel: $inc->getMachineModel(),
            pendingPartsReason: $inc->getPendingPartsReason()
        );
        $this->incidents[$i] = $reopened;
        return $reopened;
    }
    public function cancel(int $i, string $r, ?int $c = null): Incident { throw new RuntimeException("Stub"); }
    public function startIntervention(int $i, int $t): Incident { throw new RuntimeException("Stub"); }
    public function pauseIntervention(int $i, int $t, string $r): Incident { throw new RuntimeException("Stub"); }
    public function resolve(int $i, int $t, string $d, string $a): Incident { throw new RuntimeException("Stub"); }
    public function autoCloseResolvedIncidents(int $h = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

class MockPrevUserRepo implements UserRepositoryInterface
{
    /** @var array<int, User> */
    public array $users = [];

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        return $this->users[$id] ?? new User(
            id: $id,
            name: 'Técnico Jordi',
            email: 'jordi@vendguard.internal',
            passwordHash: 'dummy',
            role: UserRole::TECHNICIAN,
            operatorCode: 'OP-42'
        );
    }
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function create(array $data): User { throw new \DomainException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function updatePassword(int $id, string $newPassword): bool { return true; }
    public function resetPassword(int $id, string $newPassword): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function countActiveByRole(\VendGuard\Core\Domain\Model\UserRole|string $role): int { return 1; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
}

// =============================================================================
// Helper de Configuración del Entorno de Pruebas
// =============================================================================

function createPrevTestEnvironment(): array
{
    $auditLogRepo     = new MockAuditLogRepo();
    $auditLogger      = new AuditLogger($auditLogRepo);
    $orderRepo        = new MockPrevOrderRepo();
    $settingsRepo     = new MockPrevSettingsRepo();
    $itemRepo         = new MockPrevItemRepo();
    $certRepo         = new MockPrevCertRepo();
    $replacedPartRepo = new MockPrevReplacedPartRepo();
    $sparePartRepo    = new MockPrevSparePartRepo();
    $machineRepo      = new MockPrevMachineRepo();
    $locationRepo     = new MockPrevLocationRepo();
    $incidentRepo     = new MockPrevIncidentRepo();
    $userRepo         = new MockPrevUserRepo();

    // Sede y Máquina
    $location = new Location(1, 'SEDE-BCN-01', 'Sede Barcelona Centro', 'Carrer Balmes 1', '600111222', 'Laura');
    $locationRepo->locations[1] = $location;

    $machine = new Machine(
        id: 10,
        locationId: 1,
        code: 'MAQ-BCN-001',
        model: 'Azkoyen Palma+',
        machineType: MachineType::HOT_DRINKS,
        floorWing: 'Planta Baja'
    );
    $machineRepo->machines[10] = $machine;

    // Repuesto para pruebas
    $part = $sparePartRepo->create(new SparePart(
        id: null,
        partCode: 'JUNT-TEFL-01',
        name: 'Junta tórica teflón grupo infusión',
        category: SparePartCategory::HYDRAULIC,
        manufacturer: 'Azkoyen',
        referenceCost: 4.50,
        isActive: true,
        notes: null,
        compatibleModels: ['Azkoyen Palma+']
    ));

    // Máquinas en settingsRepo
    $settingsRepo->machines[10] = [
        'id' => 10,
        'code' => 'MAQ-BCN-001',
        'machine_type' => 'HOT_DRINKS',
        'sanitary_status' => 'OK',
        'sanitary_frequency_days' => 180,
        'default_frequency_days' => 180,
        'is_seasonal_pause' => 0,
    ];
    $settingsRepo->machines[20] = [
        'id' => 20,
        'code' => 'MAQ-BCN-002',
        'machine_type' => 'PERISHABLE_FOOD',
        'sanitary_status' => 'OK',
        'sanitary_frequency_days' => 15,
        'default_frequency_days' => 15,
        'is_seasonal_pause' => 0,
    ];

    // Orden preventiva en estado IN_INSPECTION con ID 100
    $order = new PreventiveOrder(
        100,
        'PREV-2026-00100',
        10,
        1,
        42,
        'IN_INSPECTION',
        'ROUTINE',
        date('Y-m-d'),
        date('Y-m-d', strtotime('+7 days')),
        null,
        null,
        null,
        null,
        null,
        false,
        'Mantenimiento semestral'
    );
    $orderRepo->orders[100] = $order;

    $evaluationService = new PreventiveChecklistEvaluationService(
        orderRepo: $orderRepo,
        itemRepo: $itemRepo,
        settingsRepo: $settingsRepo,
        machineRepo: $machineRepo,
        auditLogger: $auditLogger
    );

    $coexistenceBridge = new PreventiveCoexistenceBridgeService(
        incidentRepo: $incidentRepo,
        orderRepo: $orderRepo,
        settingsRepo: $settingsRepo,
        machineRepo: $machineRepo,
        certificateRepo: $certRepo,
        auditLogger: $auditLogger
    );

    $certService = new SanitaryCertificateService(
        certificateRepo: $certRepo,
        orderRepo: $orderRepo,
        settingsRepo: $settingsRepo,
        machineRepo: $machineRepo,
        auditLogger: $auditLogger
    );

    $traceabilityService = new SparePartTraceabilityService(
        requestRepo: new class implements SparePartRequestRepositoryInterface {
            public function createRequest(\VendGuard\Core\Domain\Model\SparePartRequest $r): \VendGuard\Core\Domain\Model\SparePartRequest { return $r; }
            public function findById(int $id): ?\VendGuard\Core\Domain\Model\SparePartRequest { return null; }
            public function findByIncidentId(int $i): array { return []; }
            public function markAttendedByIncident(int $i): int { return 0; }
            public function markCancelledByIncident(int $i): int { return 0; }
            public function findPendingOutOfCatalogReviews(): array { return []; }
        },
        replacedPartRepo: $replacedPartRepo,
        sparePartRepo: $sparePartRepo,
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo,
        auditLogger: $auditLogger
    );

    $prevCtrl = new TechnicianPreventiveController(
        orderRepo: $orderRepo,
        settingsRepo: $settingsRepo,
        evaluationService: $evaluationService,
        coexistenceBridge: $coexistenceBridge,
        sanitaryCertificateService: $certService,
        machineRepo: $machineRepo,
        locationRepo: $locationRepo,
        userRepo: $userRepo,
        auditLogger: $auditLogger,
        traceabilityService: $traceabilityService
    );

    $locationCtrl = new LocationPortalController(
        machineRepo: $machineRepo,
        locationRepo: $locationRepo,
        incidentRepo: $incidentRepo
    );

    return [
        'orderRepo'           => $orderRepo,
        'replacedPartRepo'    => $replacedPartRepo,
        'sparePartRepo'       => $sparePartRepo,
        'machineRepo'         => $machineRepo,
        'locationRepo'        => $locationRepo,
        'incidentRepo'        => $incidentRepo,
        'prevCtrl'            => $prevCtrl,
        'locationCtrl'        => $locationCtrl,
        'part'                => $part,
        'order'               => $order,
        'location'            => $location,
        'machine'             => $machine,
    ];
}

// =============================================================================
// 1. Extensión de Preventivos con Registro de Repuestos (RF-REP-07)
// =============================================================================
echo "--- 1. Extensión de Preventivos con Registro de Repuestos (RF-REP-07) ---\n";

$env = createPrevTestEnvironment();
$prevCtrl = $env['prevCtrl'];

// 1.1 Complete inspection con piezas sustituidas registra en incident_replaced_parts y congela coste
$hotDrinksChecklistItems = [
    ['item_code' => 'BOILER_HYDRAULIC', 'status' => 'PASS', 'observations' => 'Sin fugas'],
    ['item_code' => 'DISINFECTION_WHIPPERS', 'status' => 'PASS', 'observations' => 'Higienizado'],
    ['item_code' => 'WATER_FILTER', 'status' => 'PASS', 'observations' => 'Presión correcta'],
    ['item_code' => 'WASTE_TRAY', 'status' => 'PASS', 'observations' => 'Limpio'],
    ['item_code' => 'ELECTRICAL_SAFETY', 'status' => 'PASS', 'observations' => 'Toma de tierra OK'],
];

$completePayloadWithParts = [
    'temperature_measured'    => 12.0, // Adecuado para bebidas calientes
    'notes'                   => 'Limpieza e higienización periódica completada',
    'items'                   => $hotDrinksChecklistItems,
    'replaced_parts_declared' => true,
    'replaced_parts'          => [
        [
            'spare_part_id'        => $env['part']->getId(),
            'is_out_of_catalog'    => false,
            'quantity'             => 2,
            'old_part_destination' => 'DESGUACE',
            'notes'                => 'Juntas desgastadas sustituidas sistemáticamente',
        ],
    ],
];

$reqCompleteWithParts = (new Request('POST', '/api/technician/preventive/orders/100/complete', [], $completePayloadWithParts))
    ->setRouteParams(['id' => '100'])
    ->setAttribute('user_id', 42);
$resCompleteWithParts = $prevCtrl->completeInspection($reqCompleteWithParts);
$dataCompleteWithParts = $resCompleteWithParts->getDecodedBody()['data'] ?? [];

$assert(
    "1.1 completeInspection con piezas sustituidas responde 200 OK y devuelve replaced_parts_count y total_parts_cost",
    $resCompleteWithParts->getStatusCode() === 200 &&
    ($dataCompleteWithParts['status'] ?? '') === 'COMPLETED' &&
    ($dataCompleteWithParts['replaced_parts_count'] ?? 0) === 1 &&
    (float)($dataCompleteWithParts['total_parts_cost'] ?? 0.0) === 9.00 // 2 * 4.50
);

// Verificar persistencia en repositorio de piezas sustituidas
$savedParts = $env['replacedPartRepo']->findByPreventiveOrderId(100);
$assert(
    "1.2 Registrado en incident_replaced_parts con intervention_type = PREVENTIVE y snapshot 4.50",
    count($savedParts) === 1 &&
    $savedParts[0]->getInterventionType() === 'PREVENTIVE' &&
    $savedParts[0]->getPreventiveOrderId() === 100 &&
    $savedParts[0]->getUnitCostSnapshot() === 4.50 &&
    $savedParts[0]->getOldPartDestination() === OldPartDestination::DESGUACE
);

// 1.3 Inmutabilidad del Snapshot en Preventivos (Art. III)
$env['sparePartRepo']->update(new SparePart(
    id: $env['part']->getId(),
    partCode: $env['part']->getPartCode(),
    name: $env['part']->getName(),
    category: $env['part']->getCategory(),
    manufacturer: $env['part']->getManufacturer(),
    referenceCost: 15.00, // Subida de precio posterior en catálogo
    isActive: true,
    notes: null,
    compatibleModels: $env['part']->getCompatibleModels(),
    totalInstalledUnits: 2
));
$savedPartsAfterInflation = $env['replacedPartRepo']->findByPreventiveOrderId(100);
$assert(
    "1.3 Inviolabilidad Art. III: unit_cost_snapshot permanece congelado en 4.50 tras modificación del catálogo",
    $savedPartsAfterInflation[0]->getUnitCostSnapshot() === 4.50
);

// 1.4 Complete inspection sin piezas (declaración opcional no marcada)
$env['orderRepo']->orders[101] = new PreventiveOrder(
    101,
    'PREV-2026-00101',
    10,
    1,
    42,
    'IN_INSPECTION',
    'ROUTINE',
    date('Y-m-d'),
    date('Y-m-d', strtotime('+7 days')),
    null,
    null,
    null,
    null,
    null,
    false,
    'Revisión sin recambios'
);
$completeNoPartsPayload = [
    'temperature_measured'    => 11.5,
    'notes'                   => 'Solo ajuste de tornillería',
    'items'                   => $hotDrinksChecklistItems,
    'replaced_parts_declared' => false,
];
$reqNoParts = (new Request('POST', '/api/technician/preventive/orders/101/complete', [], $completeNoPartsPayload))
    ->setRouteParams(['id' => '101'])
    ->setAttribute('user_id', 42);
$resNoParts = $prevCtrl->completeInspection($reqNoParts);
$dataNoParts = $resNoParts->getDecodedBody()['data'] ?? [];

$assert(
    "1.4 completeInspection sin piezas concluye con replaced_parts_count = 0 y total_parts_cost = 0.00",
    $resNoParts->getStatusCode() === 200 &&
    ($dataNoParts['replaced_parts_count'] ?? -1) === 0 &&
    (float)($dataNoParts['total_parts_cost'] ?? -1.0) === 0.00
);

// 1.5 Destino inválido en preventivo devuelve 422 INVALID_PART_DESTINATION
$env['orderRepo']->orders[102] = new PreventiveOrder(
    102,
    'PREV-2026-00102',
    10,
    1,
    42,
    'IN_INSPECTION',
    'ROUTINE',
    date('Y-m-d'),
    date('Y-m-d', strtotime('+7 days')),
    null,
    null,
    null,
    null,
    null,
    false,
    'Revisión con destino no válido'
);
$badDestPayload = [
    'temperature_measured'    => 10.0,
    'notes'                   => 'Inspección',
    'items'                   => $hotDrinksChecklistItems,
    'replaced_parts_declared' => true,
    'replaced_parts'          => [
        [
            'spare_part_id'        => $env['part']->getId(),
            'quantity'             => 1,
            'old_part_destination' => 'COCHE_EMPRESA', // Destino prohibido
        ],
    ],
];
$reqBadDest = (new Request('POST', '/api/technician/preventive/orders/102/complete', [], $badDestPayload))
    ->setRouteParams(['id' => '102'])
    ->setAttribute('user_id', 42);
$resBadDest = $prevCtrl->completeInspection($reqBadDest);
$errBadDest = $resBadDest->getDecodedBody()['error'] ?? [];

$assert(
    "1.5 Destino inválido de pieza retirada en preventivo devuelve 422 INVALID_PART_DESTINATION",
    $resBadDest->getStatusCode() === 422 &&
    ($errBadDest['code'] ?? '') === 'INVALID_PART_DESTINATION'
);

// =============================================================================
// 2. Blindaje Constitución Art. II: Cuarentena Sanitaria Infranqueable
// =============================================================================
echo "\n--- 2. Blindaje Constitución Art. II: Cuarentena Sanitaria Infranqueable ---\n";

// Máquina perecedera con fallo higiénico
$machinePerishable = new Machine(
    id: 20,
    locationId: 1,
    code: 'MAQ-BCN-002',
    model: 'Fas Fast 900',
    machineType: MachineType::PERISHABLE_FOOD,
    floorWing: 'Planta 1'
);
$env['machineRepo']->machines[20] = $machinePerishable;

$env['orderRepo']->orders[103] = new PreventiveOrder(
    103,
    'PREV-2026-00103',
    20,
    1,
    42,
    'IN_INSPECTION',
    'ROUTINE',
    date('Y-m-d'),
    date('Y-m-d', strtotime('+7 days')),
    null,
    null,
    null,
    null,
    null,
    false,
    'Revisión máquina perecedera'
);

// Dictamen NO_CONFORME por rotura de frío (> 4.0 °C en sándwiches)
$perishableNonConformItems = [
    ['item_code' => 'TEMP_PROBE', 'status' => 'FAIL', 'observations' => '8.5 °C (> 4.0 °C)'],
    ['item_code' => 'SEALS_GASKET', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'EVAPORATOR_FROST', 'status' => 'FAIL', 'observations' => 'Escarcha severa'],
    ['item_code' => 'DISINFECTION_TRAYS', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'EXPIRATION_DATES', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'ELECTRICAL_SAFETY', 'status' => 'PASS', 'observations' => null],
];

$nonConformPayload = [
    'temperature_measured'    => 8.5, // Supera 4.0 °C crítico
    'notes'                   => 'Compresor no enfría suficiente, sustituyo junta pero sigue caliente',
    'items'                   => $perishableNonConformItems,
    'replaced_parts_declared' => true,
    'replaced_parts'          => [
        [
            'spare_part_id'        => $env['part']->getId(),
            'quantity'             => 1,
            'old_part_destination' => 'DESGUACE',
        ],
    ],
];
$reqNonConform = (new Request('POST', '/api/technician/preventive/orders/103/complete', [], $nonConformPayload))
    ->setRouteParams(['id' => '103'])
    ->setAttribute('user_id', 42);
$resNonConform = $prevCtrl->completeInspection($reqNonConform);
$dataNonConform = $resNonConform->getDecodedBody()['data'] ?? [];

$assert(
    "2.1 Art. II: Sustituir piezas en preventivo NO_CONFORME NO levanta la cuarentena (machine_sanitary_status = QUARANTINE)",
    $resNonConform->getStatusCode() === 200 &&
    ($dataNonConform['result'] ?? '') === 'NO_CONFORME' &&
    ($dataNonConform['is_quarantine_triggered'] ?? false) === true &&
    ($dataNonConform['machine_sanitary_status'] ?? '') === 'QUARANTINE' &&
    ($dataNonConform['replaced_parts_count'] ?? 0) === 1
);

// =============================================================================
// 3. Blindaje Constitución Art. V.4 y RF-REP-10: Segregación Estricta de Sede
// =============================================================================
echo "\n--- 3. Blindaje Constitución Art. V.4 y RF-REP-10: Segregación Estricta de Sede ---\n";

$locCtrl = $env['locationCtrl'];

// 3.1 Método sanitizeIncidentForSite elimina todas las claves de repuestos, motivos y costes
$dirtyIncidentData = [
    'id'                     => 77,
    'ticket_code'            => 'TICK-TEST-0077',
    'status'                 => 'PENDING_PARTS',
    'description'            => 'Avería mecánica en molinillo',
    'pending_parts_reason'   => 'Se requiere electroválvula 24V Ulka (VALV-ULKA-01)',
    'spare_parts'            => [['part_code' => 'VALV-ULKA-01', 'reference_cost' => 28.50]],
    'spare_part_requests'    => [['id' => 1, 'quantity' => 1]],
    'replaced_parts'         => [['part_code' => 'VALV-ULKA-01', 'unit_cost_snapshot' => 28.50]],
    'total_parts_cost'       => 28.50,
    'unit_cost_snapshot'     => 28.50,
    'total_cost_snapshot'    => 28.50,
    'reference_cost'         => 28.50,
    'old_part_destination'   => 'DESGUACE',
    'part_code'              => 'VALV-ULKA-01',
    'technician_phone'       => '699887766',
    'notes_taller'           => 'Enviar bobina rota a taller central',
];

$cleanData = $locCtrl->sanitizeIncidentForSite($dirtyIncidentData);

$forbiddenKeysPresent = [];
$forbiddenChecklist = [
    'pending_parts_reason', 'spare_parts', 'spare_part_requests', 'replaced_parts',
    'unit_cost_snapshot', 'total_cost_snapshot', 'total_parts_cost', 'reference_cost',
    'old_part_destination', 'part_code', 'technician_phone', 'notes_taller'
];
foreach ($forbiddenChecklist as $fKey) {
    if (array_key_exists($fKey, $cleanData)) {
        $forbiddenKeysPresent[] = $fKey;
    }
}

$assert(
    "3.1 sanitizeIncidentForSite elimina el 100% de las claves de repuestos, costes y notas internas",
    empty($forbiddenKeysPresent),
    "Claves prohibidas no eliminadas: " . implode(', ', $forbiddenKeysPresent)
);
$assert(
    "3.2 Campos públicos preservados íntegros (id, ticket_code, status, description)",
    isset($cleanData['id'], $cleanData['ticket_code'], $cleanData['status'], $cleanData['description'])
);

// 3.2 GET /api/locations/{site_code}/machines no filtra piezas ni costes en active_incident
// Simulamos máquina con avería en active_incident
$env['machine']->setActiveIncident([
    'id'                   => 77,
    'ticket_code'          => 'TICK-TEST-0077',
    'status'               => 'PENDING_PARTS',
    'pending_parts_reason' => 'Se requiere válvula',
    'replaced_parts'       => [['id' => 1, 'cost' => 50.0]],
    'total_parts_cost'     => 50.0,
]);

$reqMachines = (new Request('GET', '/api/locations/SEDE-BCN-01/machines'))
    ->setRouteParams(['site_code' => 'SEDE-BCN-01'])
    ->setAttribute('authenticated_location', $env['location'])
    ->setAttribute('site_code', 'SEDE-BCN-01');
$resMachines = $locCtrl->getMachines($reqMachines);
$dataMachines = $resMachines->getDecodedBody()['data'] ?? [];

$firstMachineIncident = $dataMachines[0]['active_incident'] ?? [];
$leakedKeysInMachines = array_intersect(
    array_keys($firstMachineIncident),
    ['pending_parts_reason', 'replaced_parts', 'total_parts_cost', 'cost', 'costs']
);

$assert(
    "3.3 getMachines blinda active_incident sin filtrar repuestos ni costes hacia la sede",
    empty($leakedKeysInMachines) &&
    ($firstMachineIncident['ticket_code'] ?? '') === 'TICK-TEST-0077' &&
    ($firstMachineIncident['status'] ?? '') === 'PENDING_PARTS'
);

// 3.3 POST /api/incidents/{ticket_code}/reopen no expone repuestos ni costes en el ticket reabierto
$incidentToReopen = new Incident(
    id: 88,
    ticketCode: 'INC-2026-00088',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Avería con repuesto resuelta previamente',
    urgency: \VendGuard\Core\Domain\ValueObject\UrgencyLevel::HIGH,
    status: IncidentStatus::RESOLVED,
    assignedTechnicianId: 42,
    pendingPartsReason: 'Válvula sustituida previamente',
    resolutionDiagnosis: 'Monedero atascado y desajustado con suciedad',
    resolutionAction: 'Limpieza y sustitución de sensor óptico original',
    resolvedAt: date('Y-m-d H:i:s')
);
$env['incidentRepo']->incidents[88] = $incidentToReopen;

$reopenPayload = [
    'reopen_reason' => 'El problema persiste al dispensar monedas de un euro',
];
$reqReopen = (new Request('POST', '/api/incidents/INC-2026-00088/reopen', [], $reopenPayload))
    ->setRouteParams(['ticket_code' => 'INC-2026-00088'])
    ->setAttribute('authenticated_location', $env['location'])
    ->setAttribute('site_code', 'SEDE-BCN-01');
$resReopen = $locCtrl->reopenIncident($reqReopen);
$dataReopen = $resReopen->getDecodedBody()['data'] ?? [];

$reopenedIncidentData = $dataReopen['incident'] ?? [];
$leakedInReopen = array_intersect(
    array_keys($reopenedIncidentData),
    ['pending_parts_reason', 'replaced_parts', 'total_parts_cost', 'unit_cost_snapshot', 'reference_cost']
);

$assert(
    "3.4 reopenIncident blinda el objeto incident excluyendo pending_parts_reason y costes",
    empty($leakedInReopen) &&
    ($dataReopen['status'] ?? '') === 'REOPENED' &&
    ($dataReopen['status_label'] ?? '') === 'Reabierta' &&
    ($reopenedIncidentData['ticket_code'] ?? '') === 'INC-2026-00088'
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n========================================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "========================================================================================\n";

exit($failures === 0 ? 0 : 1);
