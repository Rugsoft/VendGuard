<?php

declare(strict_types=1);

/**
 * DexAuditServiceIntegrationsTest · T-DEX-08
 *
 * Certifica el enlace opcional `dex_audit_id` de M34/M35/M36 (RF-04.3,
 * RF-05.2, D1, D4, D5, C5 y QA-5):
 *
 *   1. **DexAuditLinker.** Identificador inválido `422 DEX_AUDIT_ID_INVALID`,
 *      inexistente `404 DEX_AUDIT_NOT_FOUND`, máquina ajena `422
 *      DEX_AUDIT_MACHINE_MISMATCH`, anulado `409 DEX_AUDIT_VOIDED`, destino ya
 *      ocupado `409 DEX_AUDIT_ALREADY_LINKED` y repetición idempotente.
 *   2. **M34.** La visita enlaza el volcado dentro de su transacción, devuelve
 *      `dex_audit_id` y no toca existencias por el mero vínculo.
 *   3. **M35.** La recaudación enlaza el volcado tras insertar la bolsa y no
 *      inserta nada si el identificador no existe.
 *   4. **M36.** El escalado valida antes de crear el ticket y enlaza el
 *      expediente resultante.
 *
 * Uso:
 *   php tests/unit/DexAuditServiceIntegrationsTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\DexAuditLinker;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Application\Service\MachineRestockVisitService;
use VendGuard\Application\Service\RestockerSatSynergyService;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Exception\MachineCashCollectionUniqueViolationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineCashCollection;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\InSituRestockVisitGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineDexAuditRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

/** Repositorio de auditorías con los vínculos reales en memoria. */
final class Dex08AuditRepository implements MachineDexAuditRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $audits = [];

    /** @param array<string, mixed> $overrides */
    public function seed(int $id, int $machineId, array $overrides = []): void
    {
        $this->audits[$id] = $overrides + [
            'id' => $id,
            'machine_id' => $machineId,
            'status' => 'ACTIVE',
            'restock_visit_id' => null,
            'cash_collection_id' => null,
            'incident_id' => null,
            'sha256_hash' => str_repeat('a', 64),
        ];
    }

    public function findLinkTargetForUpdate(int $auditId): ?array
    {
        return $this->audits[$auditId] ?? null;
    }

    public function linkToRestockVisit(int $auditId, int $restockVisitId): void
    {
        $this->audits[$auditId]['restock_visit_id'] = $restockVisitId;
    }

    public function linkToCashCollection(int $auditId, int $cashCollectionId): void
    {
        $this->audits[$auditId]['cash_collection_id'] = $cashCollectionId;
    }

    public function linkToIncident(int $auditId, int $incidentId): void
    {
        $this->audits[$auditId]['incident_id'] = $incidentId;
    }

    public function reserveAuditCode(?DateTimeImmutable $now = null): string { return 'DEX-2026-9000'; }
    public function create(MachineDexAudit $audit, array $selectionLines = [], array $eventLines = []): MachineDexAudit { return $audit; }
    public function findById(int $auditId): ?array { return null; }
    public function findRawPayload(int $auditId): ?array { return null; }
    public function findActiveIdByHash(int $machineId, string $sha256Hash): ?int { return null; }
    public function findByActorMutation(int $importedBy, string $clientMutationId): ?array { return null; }
    public function findPreviousActive(int $machineId, string $capturedAt, ?int $excludeAuditId = null): ?array { return null; }
    public function hasLaterActive(int $machineId, string $capturedAt): bool { return false; }
    public function findSelectionsByAudit(int $auditId): array { return []; }
    public function listForCoordinator(array $filters = [], int $limit = 100, int $offset = 0): array { return []; }
    public function countForCoordinator(array $filters = []): int { return 0; }
    public function kpisForCoordinator(array $filters = []): array { return ['total_active' => 0, 'thermal_alert_count' => 0, 'warning_count' => 0, 'cash_deviation_count' => 0, 'voided_count' => 0]; }
    public function void(int $auditId, string $reason, int $voidedBy, string $voidedByName, string $voidedAt): array { return []; }
}

final class Dex08Transactions implements TransactionManagerInterface
{
    public int $calls = 0;

    public function runInTransaction(callable $operation): mixed
    {
        $this->calls++;

        return $operation();
    }
}

final class Dex08AuditLog implements AuditLogRepositoryInterface
{
    public function log(AuditEvent $event): AuditEvent { return $event; }
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countEvents(array $filters = []): int { return 0; }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

final class Dex08VisitRepository implements MachineRestockVisitRepositoryInterface
{
    /** @var array<int, MachineRestockVisit> */
    public array $visits = [];
    /** @var list<MachineRestockVisitItem> */
    public array $items = [];
    private int $nextId = 1;

    public function insertVisit(MachineRestockVisit $visit): MachineRestockVisit
    {
        $persisted = $visit->withId($this->nextId++);
        $this->visits[(int)$persisted->getId()] = $persisted;

        return $persisted;
    }

    public function insertItem(MachineRestockVisitItem $item): MachineRestockVisitItem
    {
        $persisted = $item->withId(count($this->items) + 1);
        $this->items[] = $persisted;

        return $persisted;
    }

    public function findById(int $visitId, bool $includeCosts = false): ?array
    {
        $visit = $this->visits[$visitId] ?? null;

        return $visit === null ? null : [
            'id' => $visit->getId(),
            'visit_code' => $visit->getVisitCode(),
            'status' => $visit->getStatus()->value,
            'machine_id' => $visit->getMachineId(),
            'restocker_id' => $visit->getRestockerId(),
            'loading_order_id' => $visit->getLoadingOrderId(),
            'triggered_incident_id' => $visit->getTriggeredIncidentId(),
            'items' => [],
        ];
    }

    public function findByClientMutationId(int $restockerId, string $clientMutationId, bool $includeCosts = false): ?array
    {
        foreach ($this->visits as $visit) {
            if ($visit->getRestockerId() === $restockerId && $visit->getClientMutationId() === $clientMutationId) {
                return $this->findById((int)$visit->getId(), $includeCosts);
            }
        }

        return null;
    }

    public function listForRestocker(int $restockerId, array $filters = []): array { return []; }
    public function listForCoordinator(array $filters = []): array { return []; }
    public function suggestLiquidationQuantities(int $loadingOrderId, int $restockerId): array { return []; }
}

final class Dex08Planograms implements MachinePlanogramRepositoryInterface
{
    public array $movements = [];
    public function findMachineById(int $machineId): ?array { return null; }
    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array { return []; }
    public function findSlot(int $machineId, string $slotCode): ?array { return null; }
    public function upsertSlot(MachinePlanogramSlot $slot): array { return $slot->toArray(); }
    public function replaceMachinePlanogram(int $machineId, array $slots): array { return []; }
    public function deactivateSlot(int $machineId, string $slotCode): bool { return true; }
    public function summarize(int $machineId): array { return []; }
    public function findCompatibleSourceMachines(int $machineId, MachineType $machineType): array { return []; }
    public function findMachinesForRestocker(int $restockerId): array { return []; }
    public function findMachineForRestocker(int $machineId, int $restockerId): ?array { return null; }
    public function applyVisitSlotMovement(int $machineId, string $slotCode, int $qtyWithdrawnExpired, int $qtyWithdrawnDamaged, int $qtyAdded, ?string $loadedBatchCode = null, ?DateTimeImmutable $loadedExpirationDate = null, ?DateTimeImmutable $today = null): array
    {
        $this->movements[] = $slotCode;

        return [];
    }
}

final class Dex08VanStock implements RestockerVanStockRepositoryInterface
{
    /** @var array<string, RestockerVanProductStock> */
    public array $lots = [];
    public function search(?int $restockerId = null, ?string $category = null, ?string $expiryStatus = null, ?string $search = null): array { return []; }
    public function findByRestocker(int $restockerId, ?string $category = null, ?string $expiryStatus = null, ?string $search = null): array
    {
        $rows = [];
        foreach ($this->lots as $lot) {
            if ($lot->getRestockerId() === $restockerId) {
                $rows[] = $lot->toArray() + ['restocker_id' => $restockerId, 'requires_cold_chain' => true];
            }
        }

        return $rows;
    }
    public function findLot(int $restockerId, int $productId, string $batchCode, bool $forUpdate = false): ?RestockerVanProductStock
    {
        return $this->lots[$restockerId . ':' . $productId . ':' . strtoupper(trim($batchCode))] ?? null;
    }
    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        $this->lots[$stock->getRestockerId() . ':' . $stock->getProductId() . ':' . $stock->getBatchCode()] = $stock;

        return $stock;
    }
    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array { return []; }
}

final class Dex08VisitGateway implements InSituRestockVisitGatewayInterface
{
    public ?array $context = null;
    public array $slots = [];
    public array $costs = [];
    public function lockMachineContext(int $machineId, int $restockerId): ?array { return $this->context; }
    public function lockActiveSlots(int $machineId): array { return $this->slots; }
    public function findProductUnitCost(int $productId): float { return $this->costs[$productId] ?? 1.00; }
    public function lockMachineSanitarily(int $machineId, MachineType $machineType, string $reason): void {}
    public function resolveThermalIncident(int $machineId, string $machineCode, int $locationId, ?int $existingIncidentId, float $cabinTemp, string $photoUrl, string $notes, array $actor): int { return 77; }
}

final class Dex08CashGateway implements MachineCashCollectionGatewayInterface
{
    public array $machines = [];
    public function lockMachineContext(int $machineId, int $restockerId): ?array { return $this->machines[$machineId] ?? null; }
    public function lockRestockVisit(int $visitId, int $machineId): ?array { return null; }
    public function lockRefundForReception(int $refundRequestId): ?array { return null; }
    public function routeBootstrap(int $restockerId): array { return ['machines' => [], 'refunds' => []]; }
}

final class Dex08CashRepository implements MachineCashCollectionRepositoryInterface
{
    public array $collections = [];
    private int $nextId = 1;
    private int $nextCode = 1;

    public function reserveCollectionCode(): string { return sprintf('MCC-2026-%04d', $this->nextCode++); }

    public function insert(MachineCashCollection $collection): MachineCashCollection
    {
        $persisted = $collection->withId($this->nextId++);
        $this->collections[(int)$persisted->getId()] = $persisted;

        return $persisted;
    }

    public function findById(int $collectionId): ?MachineCashCollection { return $this->collections[$collectionId] ?? null; }
    public function findByClientMutationId(int $restockerId, string $clientMutationId): ?MachineCashCollection
    {
        foreach ($this->collections as $collection) {
            if ($collection->getRestockerId() === $restockerId && $collection->getClientMutationId() === trim($clientMutationId)) {
                return $collection;
            }
        }

        return null;
    }
    public function findByBagSealCode(string $bagSealCode): ?MachineCashCollection
    {
        foreach ($this->collections as $collection) {
            if ($collection->getBagSealCode() === strtoupper(trim($bagSealCode))) {
                return $collection;
            }
        }

        return null;
    }
    public function findByRestockVisitId(int $restockVisitId): ?MachineCashCollection { return null; }
    public function findLastForMachine(int $machineId): ?MachineCashCollection { return null; }
    public function listForRestocker(int $restockerId, array $filters = []): array { return []; }
    public function listForCoordinator(array $filters = []): array { return []; }
    public function listAllForCoordinator(array $filters = []): array { return []; }
    public function summarizeForRestocker(int $restockerId, array $filters = []): array { return []; }
    public function markDeliveredToVault(int $collectionId, int $coordinatorId, ?string $deliveredAt = null): ?MachineCashCollection { return null; }
    public function reconcile(int $collectionId, int $coordinatorId, string $coordinatorName, float $vaultCountedEur, float $discrepancyEur, CashCollectionStatus $status, ?string $discrepancyJustification = null, ?string $reconciledAt = null): ?MachineCashCollection { return null; }
}

final class Dex08Refunds implements RefundRequestRepositoryInterface
{
    public function insert(RefundRequest $refundRequest): int { return 0; }
    public function findById(int $id): ?RefundRequest { return null; }
    public function findByTrackingToken(string $trackingToken): ?RefundRequest { return null; }
    public function findRestrictedByIncident(int $incidentId): array { return []; }
    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array { return []; }
    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countForCoordinator(array $filters = []): int { return 0; }
    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool { return true; }
    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool { return false; }
    public function detachFromIncident(int $id, RefundStatus $newStatus): bool { return false; }
    public function deactivate(int $id): bool { return false; }
}

final class Dex08SynergyGateway implements RestockerSatSynergyGatewayInterface
{
    public array $calls = [];
    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array { return ['ok' => true]; }
    public function resolveLevel1(int $incidentId, array $actor, array $resolution, ?array $mutation = null): array { return ['ok' => true]; }
    public function escalateToSat(int $machineId, array $actor, array $payload, ?array $mutation = null): array
    {
        $this->calls[] = compact('machineId', 'actor', 'payload', 'mutation');

        return ['ok' => true, 'incident_id' => 501, 'idempotent_replay' => false];
    }
    public function promoteToSatOnQrMerge(int $incidentId, string $category, ?int $assignTechnicianId, array $actor): array { return ['ok' => true]; }
    public function assignDispatchLevel(array $incident, string $dispatchLevel, array $assignee, array $actor, ?string $urgencyOverride = null, ?string $urgencyReason = null): array { return ['ok' => true]; }
    public function recordConflictDump(array $actor, array $mutation, array $conflict): array { return ['ok' => true]; }
    public function suggestRestockers(int $incidentId): array { return ['ok' => true, 'data' => []]; }
    public function technicianCoincidences(int $technicianId, array $locationIds): array { return ['ok' => true, 'data' => []]; }
    public function restockerCoincidences(int $restockerId, array $locationIds): array { return ['ok' => true, 'data' => []]; }
    public function handoverLevel1(int $restockerId, string $cause, string $reason): array { return ['ok' => true]; }
}

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array{0: string, 1: int} */
$expectDex = static function (callable $operation): array {
    try {
        $operation();
    } catch (DexAuditValidationException | DexAuditConflictException $exception) {
        return [$exception->getErrorCode(), $exception->getHttpStatusCode()];
    }

    return ['NO_EXCEPTION', 0];
};

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-08 · Enlaces dex_audit_id en M34/M35/M36\n";
echo "======================================================================\n";

$audits = new Dex08AuditRepository();
$linker = new DexAuditLinker($audits);

echo "\n--- 1. Contrato del enlazador (QA-5) ---\n";
$assert('1.0 Sin identificador no hay vínculo', DexAuditLinker::parseOptionalId(null) === null && DexAuditLinker::parseOptionalId('') === null);
$assert('1.1 Un identificador no entero es 422', $expectDex(static fn () => DexAuditLinker::parseOptionalId('12abc')) === ['DEX_AUDIT_ID_INVALID', 422]);
$assert('1.2 Un identificador inexistente es 404', $expectDex(static fn () => $linker->assertCompatible(999, 1)) === ['DEX_AUDIT_NOT_FOUND', 404]);

$audits->seed(10, 1);
$assert('1.3 Una máquina ajena es 422', $expectDex(static fn () => $linker->assertCompatible(10, 2)) === ['DEX_AUDIT_MACHINE_MISMATCH', 422]);

$audits->seed(11, 1, ['status' => 'VOIDED']);
$assert('1.4 Un volcado anulado es 409', $expectDex(static fn () => $linker->assertCompatible(11, 1)) === ['DEX_AUDIT_VOIDED', 409]);

$linker->linkToRestockVisit(10, 1, 500);
$assert('1.5 El vínculo a visita se persiste', $audits->audits[10]['restock_visit_id'] === 500);
$assert('1.6 El mismo destino es idempotente', $expectDex(static fn () => $linker->linkToRestockVisit(10, 1, 500)) === ['NO_EXCEPTION', 0]);
$assert('1.7 Otro destino en el mismo tipo es 409', $expectDex(static fn () => $linker->linkToRestockVisit(10, 1, 501)) === ['DEX_AUDIT_ALREADY_LINKED', 409]);

echo "\n--- 2. M34: visita de reposición ---\n";
$gateway = new Dex08VisitGateway();
$gateway->context = [
    'id' => 1,
    'machine_code' => 'VEND-DEX08',
    'machine_type' => MachineType::PERISHABLE_FOOD,
    'location_id' => 10,
    'sanitary_status' => 'OK',
    'is_out_of_service' => false,
    'is_covered' => true,
    'has_open_thermal_breakdown' => false,
    'active_incident_id' => null,
    'loading_order_id' => 55,
];
$gateway->slots = [
    new MachinePlanogramSlot(101, 1, '11', 1, 20, 5, 2, true, null, null, 'CARRIL-A', new DateTimeImmutable('+10 days')),
    new MachinePlanogramSlot(102, 1, '12', 2, 20, 0, 2, true, null, null, null, null),
];
$gateway->costs = [1 => 1.50, 2 => 2.25];
$vanStock = new Dex08VanStock();
$vanStock->upsertLot(new RestockerVanProductStock(null, 7, 1, 'B1', new DateTimeImmutable('+20 days'), 10, true));
$vanStock->upsertLot(new RestockerVanProductStock(null, 7, 2, 'B2', new DateTimeImmutable('+25 days'), 10, true));
$visits = new Dex08VisitRepository();
$planograms = new Dex08Planograms();
$visitService = new MachineRestockVisitService(
    $visits,
    $planograms,
    $vanStock,
    $gateway,
    new Dex08Transactions(),
    new AuditLogger(new Dex08AuditLog()),
    null,
    null,
    null,
    null,
    $linker
);
$audits->seed(20, 1);
$visitPayload = [
    'status' => 'COMPLETED',
    'client_mutation_id' => 'dex08-visit-0001',
    'cabin_temp_celsius' => 3.2,
    'hygiene_checklist_json' => [
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ],
    'items' => [
        ['slot_code' => '11', 'qty_added' => 7, 'loaded_batch_code' => 'B1'],
        ['slot_code' => '12', 'qty_added' => 4, 'loaded_batch_code' => 'B2'],
    ],
    'dex_audit_id' => 20,
];
$visit = $visitService->registerVisit(1, $visitPayload, ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']);
$visitId = (int)$visit['id'];
$assert('2.1 La visita devuelve el dex_audit_id enlazado', ($visit['dex_audit_id'] ?? 0) === 20);
$assert('2.2 El vínculo apunta a la visita persistida', ($audits->audits[20]['restock_visit_id'] ?? 0) === $visitId);
$assert('2.3 El enlace no altera los movimientos de carril', count($planograms->movements) === 2);

$visitsBefore = count($visits->visits);
$notFound = $expectDex(static fn () => $visitService->registerVisit(1, array_merge($visitPayload, [
    'client_mutation_id' => 'dex08-visit-0002',
    'dex_audit_id' => 999,
]), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']));
$assert('2.4 Un dex inexistente es 404 y no inserta la visita', $notFound === ['DEX_AUDIT_NOT_FOUND', 404] && count($visits->visits) === $visitsBefore);

$audits->seed(21, 2);
$mismatch = $expectDex(static fn () => $visitService->registerVisit(1, array_merge($visitPayload, [
    'client_mutation_id' => 'dex08-visit-0003',
    'dex_audit_id' => 21,
]), ['id' => 7, 'role' => 'RESTOCKER', 'name' => 'Lucía']));
$assert('2.5 Un volcado de otra máquina es 422', $mismatch === ['DEX_AUDIT_MACHINE_MISMATCH', 422]);

echo "\n--- 3. M35: recaudación ---\n";
$cashGateway = new Dex08CashGateway();
$cashGateway->machines[1] = [
    'id' => 1,
    'machine_code' => 'VEND-DEX08',
    'location_id' => 1,
    'sanitary_status' => 'OK',
    'is_out_of_service' => false,
    'is_covered' => true,
    'has_open_thermal_breakdown' => false,
    'has_physical_reception' => true,
    'restocker_name' => 'Lucía Reponedora',
    'restocker_code' => 'REP-008',
];
$cashRepository = new Dex08CashRepository();
$cashService = new MachineCashCollectionService(
    $cashRepository,
    $cashGateway,
    new Dex08Refunds(),
    new Dex08Transactions(),
    new AuditLogger(new Dex08AuditLog()),
    $linker
);
$audits->seed(30, 1);
$cashPayload = [
    'bag_seal_code' => 'BAG-2026-080001',
    'client_mutation_id' => 'dex08-cash-0001',
    'sales_counter_reading' => 100.00,
    'change_tubes_loaded_eur' => 20.00,
    'collected_at' => '2026-11-20 10:00:00+00',
    'dex_audit_id' => 30,
];
$cash = $cashService->registerCollection(1, $cashPayload, ['id' => 10, 'role' => 'RESTOCKER', 'name' => 'Lucía Reponedora']);
$cashId = (int)$cash['id'];
$assert('3.1 La recaudación devuelve el dex_audit_id enlazado', ($cash['dex_audit_id'] ?? 0) === 30);
$assert('3.2 El vínculo apunta a la bolsa persistida', ($audits->audits[30]['cash_collection_id'] ?? 0) === $cashId);

$collectionsBefore = count($cashRepository->collections);
$cashNotFound = $expectDex(static fn () => $cashService->registerCollection(1, array_merge($cashPayload, [
    'bag_seal_code' => 'BAG-2026-080002',
    'client_mutation_id' => 'dex08-cash-0002',
    'dex_audit_id' => 404,
]), ['id' => 10, 'role' => 'RESTOCKER', 'name' => 'Lucía Reponedora']));
$assert('3.3 Un dex inexistente es 404 y no inserta la bolsa', $cashNotFound === ['DEX_AUDIT_NOT_FOUND', 404] && count($cashRepository->collections) === $collectionsBefore);

echo "\n--- 4. M36: escalado SAT ---\n";
$uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vg_dex08_' . bin2hex(random_bytes(4));
mkdir($uploadDir, 0777, true);
file_put_contents(
    $uploadDir . DIRECTORY_SEPARATOR . 'dex08.png',
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1cAAAAASUVORK5CYII=')
);
$synergyGateway = new Dex08SynergyGateway();
$synergyTransactions = new Dex08Transactions();
$synergy = new RestockerSatSynergyService(
    $synergyGateway,
    null,
    null,
    new LocalFileUploader($uploadDir),
    $linker,
    $synergyTransactions
);
$audits->seed(40, 3);
$escalation = [
    'category' => 'PRODUCT_JAM',
    'internal_note' => 'La puerta deja de cerrar y el producto queda expuesto al exterior.',
    'photo_path' => '/uploads/dex08.png',
    'dex_audit_id' => 40,
];
$result = $synergy->escalateToSat(3, ['id' => 4, 'role' => 'RESTOCKER', 'name' => 'Lucía'], $escalation, ['client_mutation_id' => 'dex08-escalate-0001']);
$assert(
    '4.1 El escalado enlaza el dex_audit_id dentro de una unidad de trabajo',
    ($result['dex_audit_id'] ?? 0) === 40 && $synergyTransactions->calls === 1
);
$assert('4.2 El vínculo apunta al expediente SAT creado', ($audits->audits[40]['incident_id'] ?? 0) === 501);

$callsBefore = count($synergyGateway->calls);
$escalationNotFound = $expectDex(static fn () => $synergy->escalateToSat(3, ['id' => 4, 'role' => 'RESTOCKER', 'name' => 'Lucía'], array_merge($escalation, [
    'dex_audit_id' => 999,
])));
$assert('4.3 Un dex inexistente es 404 antes de crear el ticket', $escalationNotFound === ['DEX_AUDIT_NOT_FOUND', 404] && count($synergyGateway->calls) === $callsBefore);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-08 (enlaces dex_audit_id M34/M35/M36) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN LOS ENLACES DEX_AUDIT_ID.\n";
echo "======================================================================\n";
exit(1);
