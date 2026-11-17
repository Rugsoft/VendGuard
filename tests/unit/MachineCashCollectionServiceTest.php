<?php

declare(strict_types=1);

/**
 * MachineCashCollectionServiceTest · T-CCV-06
 *
 * Certifica el caso de uso de la recaudación, el cambio y el arqueo (Módulo 35)
 * con dobles en memoria y sin base de datos:
 *
 *   1. **Captura en ruta (RF-02.1, RF-02.2, C1–C4).** Rol, perímetro, cerrojo
 *      sanitario, visita abortada por frío, precinto, monotonía del contador,
 *      cotas, conserjería y cálculo privado de `expected_cashbox_eur`.
 *   2. **Sinergia con reintegros (D3).** Elegibilidad, importe exacto y
 *      transición atómica a `DEPOSITED_AT_RECEPTION` con sus campos.
 *   3. **Idempotencia (D4).** La mutación repetida devuelve el mismo corte sin
 *      volver a insertar ni auditar.
 *   4. **Ciclo de custodia (RF-04.1, RF-04.2, D2, D4).** Recepción en cámara,
 *      arqueo con tolerancia ±1,00 €, justificación obligatoria y bloqueo de
 *      una fila terminal.
 *   5. **Superficies (QA 2–QA 4, Art. V.4).** Historial y resumen opacos,
 *      libro con 6 KPIs, recibo de nueve campos, volcado offline y CSV con BOM
 *      y neutralización de fórmulas.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca PostgreSQL, red ni sistema de
 * archivos; los dobles implementan los mismos puertos que producción.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineCashCollection;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;

$GLOBALS['assertions'] = 0;

function assertCash(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertCashRejects(callable $operation, string $expectedCode, int $expectedHttp): MachineCashCollectionValidationException
{
    try {
        $operation();
    } catch (MachineCashCollectionValidationException $exception) {
        assertCash(
            $exception->getErrorCode() === $expectedCode && $exception->getHttpStatusCode() === $expectedHttp,
            "código {$expectedCode}/{$expectedHttp}, recibido {$exception->getErrorCode()}/{$exception->getHttpStatusCode()}"
        );

        return $exception;
    }

    fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y no se lanzó nada.\n");
    exit(1);
}

final class CashTestTransactions implements TransactionManagerInterface
{
    public function runInTransaction(callable $operation): mixed
    {
        return $operation();
    }
}

final class CashTestAuditLog implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

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
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }

}

final class CashTestGateway implements MachineCashCollectionGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    /** @var array<int, array<string, mixed>> */
    public array $visits = [];

    /** @var array<int, array<string, mixed>> */
    public array $refunds = [];

    public function lockMachineContext(int $machineId, int $restockerId): ?array
    {
        return $this->machines[$machineId] ?? null;
    }

    public function lockRestockVisit(int $visitId, int $machineId): ?array
    {
        $visit = $this->visits[$visitId] ?? null;

        return $visit !== null && (int)$visit['machine_id'] === $machineId ? $visit : null;
    }

    public function lockRefundForReception(int $refundRequestId): ?array
    {
        return $this->refunds[$refundRequestId] ?? null;
    }

    public function routeBootstrap(int $restockerId): array
    {
        $machines = [];
        $refunds = [];
        foreach ($this->machines as $machineId => $machine) {
            $machines[(int)$machineId] = (bool)($machine['has_physical_reception'] ?? false);
        }

        return ['machines' => $machines, 'refunds' => $refunds];
    }
}

final class CashTestRepository implements MachineCashCollectionRepositoryInterface
{
    /** @var array<int, MachineCashCollection> */
    public array $collections = [];

    /** @var list<string> */
    public array $insertedCodes = [];

    public ?array $lastReconcileArgs = null;

    private int $nextId = 1;

    private int $nextCode = 1;

    public function reserveCollectionCode(): string
    {
        return sprintf('MCC-%04d-%04d', (int)date('Y'), $this->nextCode++);
    }

    public function insert(MachineCashCollection $collection): MachineCashCollection
    {
        $persisted = $collection->withId($this->nextId++);
        $this->collections[(int)$persisted->getId()] = $persisted;
        $this->insertedCodes[] = $persisted->getCollectionCode();

        return $persisted;
    }

    public function findById(int $collectionId): ?MachineCashCollection
    {
        return $this->collections[$collectionId] ?? null;
    }

    public function findByClientMutationId(int $restockerId, string $clientMutationId): ?MachineCashCollection
    {
        foreach ($this->collections as $collection) {
            if ($collection->getRestockerId() === $restockerId
                && $collection->getClientMutationId() === trim($clientMutationId)) {
                return $collection;
            }
        }

        return null;
    }

    public function findByBagSealCode(string $bagSealCode): ?MachineCashCollection
    {
        $seal = strtoupper(trim($bagSealCode));
        foreach ($this->collections as $collection) {
            if ($collection->getBagSealCode() === $seal) {
                return $collection;
            }
        }

        return null;
    }

    public function findByRestockVisitId(int $restockVisitId): ?MachineCashCollection
    {
        foreach ($this->collections as $collection) {
            if ($collection->getRestockVisitId() === $restockVisitId) {
                return $collection;
            }
        }

        return null;
    }

    public function findLastForMachine(int $machineId): ?MachineCashCollection
    {
        $candidates = array_values(array_filter(
            $this->collections,
            static fn (MachineCashCollection $collection): bool => $collection->getMachineId() === $machineId
        ));

        usort($candidates, static fn (MachineCashCollection $a, MachineCashCollection $b): int =>
            [$b->getCollectedAt(), $b->getId()] <=> [$a->getCollectedAt(), $a->getId()]);

        return $candidates[0] ?? null;
    }

    public function listForRestocker(int $restockerId, array $filters = []): array
    {
        $rows = array_values(array_filter(
            $this->collections,
            static fn (MachineCashCollection $collection): bool =>
                $collection->getRestockerId() === $restockerId
                && (!isset($filters['machine_id']) || $collection->getMachineId() === (int)$filters['machine_id'])
                && (!isset($filters['status']) || strtoupper((string)$filters['status']) === 'ALL'
                    || $collection->getStatus()->value === strtoupper((string)$filters['status']))
        ));

        usort($rows, static fn (MachineCashCollection $a, MachineCashCollection $b): int =>
            [$b->getCollectedAt(), $b->getId()] <=> [$a->getCollectedAt(), $a->getId()]);

        return $rows;
    }

    public function listForCoordinator(array $filters = []): array
    {
        $rows = array_values(array_filter(
            $this->collections,
            static fn (MachineCashCollection $collection): bool =>
                !isset($filters['status'])
                || strtoupper((string)$filters['status']) === 'ALL'
                || $collection->getStatus()->value === strtoupper((string)$filters['status'])
        ));

        usort($rows, static fn (MachineCashCollection $a, MachineCashCollection $b): int =>
            [$b->getCollectedAt(), $b->getId()] <=> [$a->getCollectedAt(), $a->getId()]);

        $pending = 0;
        $ok = 0;
        $flagged = 0;
        $counted = 0.0;
        $net = 0.0;
        $deployed = 0.0;

        foreach ($rows as $collection) {
            if (in_array($collection->getStatus(), [CashCollectionStatus::SEALED_IN_ROUTE, CashCollectionStatus::DELIVERED_TO_VAULT], true)) {
                $pending++;
            }
            if ($collection->getStatus() === CashCollectionStatus::RECONCILED_OK) {
                $ok++;
            }
            if ($collection->getStatus() === CashCollectionStatus::DISCREPANCY_FLAGGED) {
                $flagged++;
            }
            if ($collection->isTerminal()) {
                $counted += (float)$collection->getVaultCountedEur();
                $net += (float)$collection->getDiscrepancyEur();
            }
            $deployed += $collection->getChangeTubesLoadedEur() + $collection->getReceptionCashDeliveredEur();
        }

        return [
            'collections' => $rows,
            'kpis' => [
                'pending_vault_bags_count' => $pending,
                'reconciled_ok_count' => $ok,
                'discrepancy_flagged_count' => $flagged,
                'total_vault_counted_eur' => round($counted, 2),
                'net_discrepancy_eur' => round($net, 2),
                'total_route_cash_deployed_eur' => round($deployed, 2),
            ],
        ];
    }

    public function listAllForCoordinator(array $filters = []): array
    {
        return $this->listForCoordinator($filters)['collections'];
    }

    public function summarizeForRestocker(int $restockerId, array $filters = []): array
    {
        $rows = $this->listForRestocker($restockerId, $filters);
        $change = 0.0;
        $reception = 0.0;
        $pending = 0;
        $last = null;

        foreach ($rows as $collection) {
            $change += $collection->getChangeTubesLoadedEur();
            $reception += $collection->getReceptionCashDeliveredEur();
            if ($collection->getStatus() === CashCollectionStatus::SEALED_IN_ROUTE) {
                $pending++;
            }
            $last = $last ?? $collection->getCollectedAt();
        }

        return [
            'total_collections_count' => count($rows),
            'total_change_tubes_loaded_eur' => round($change, 2),
            'total_reception_cash_delivered_eur' => round($reception, 2),
            'pending_vault_bags_count' => $pending,
            'last_collected_at' => $last,
        ];
    }

    public function markDeliveredToVault(int $collectionId, int $coordinatorId, ?string $deliveredAt = null): ?MachineCashCollection
    {
        $collection = $this->collections[$collectionId] ?? null;
        if ($collection === null || !$collection->getStatus()->allowsVaultReception()) {
            return null;
        }

        $updated = $this->copyWith($collection, [
            'status' => CashCollectionStatus::DELIVERED_TO_VAULT,
            'delivered_at' => $deliveredAt ?? '2026-11-10 14:00:00+00',
            'delivered_by' => $coordinatorId,
        ]);
        $this->collections[$collectionId] = $updated;

        return $updated;
    }

    public function reconcile(
        int $collectionId,
        int $coordinatorId,
        string $coordinatorName,
        float $vaultCountedEur,
        float $discrepancyEur,
        CashCollectionStatus $status,
        ?string $discrepancyJustification = null,
        ?string $reconciledAt = null
    ): ?MachineCashCollection {
        $collection = $this->collections[$collectionId] ?? null;
        if ($collection === null || $collection->isTerminal()) {
            return null;
        }

        $at = $reconciledAt ?? '2026-11-10 15:00:00+00';
        $this->lastReconcileArgs = [
            'vault' => $vaultCountedEur,
            'discrepancy' => $discrepancyEur,
            'status' => $status,
            'justification' => $discrepancyJustification,
            'name' => $coordinatorName,
        ];

        $updated = $this->copyWith($collection, [
            'status' => $status,
            'delivered_at' => $collection->getDeliveredToVaultAt() ?? $at,
            'delivered_by' => $collection->getDeliveredToVaultBy() ?? $coordinatorId,
            'vault' => $vaultCountedEur,
            'discrepancy' => $discrepancyEur,
            'reconciled_by' => $coordinatorId,
            'reconciled_name' => $coordinatorName,
            'reconciled_at' => $at,
            'justification' => $discrepancyJustification,
        ]);
        $this->collections[$collectionId] = $updated;

        return $updated;
    }

    private function copyWith(MachineCashCollection $collection, array $overrides): MachineCashCollection
    {
        return new MachineCashCollection(
            $collection->getId(),
            $collection->getCollectionCode(),
            $collection->getRestockVisitId(),
            $collection->getMachineId(),
            $collection->getMachineCodeSnapshot(),
            $collection->getRestockerId(),
            $collection->getRestockerNameSnapshot(),
            $collection->getRestockerCodeSnapshot(),
            $collection->getBagSealCode(),
            $collection->getChangeTubesLoadedEur(),
            $collection->getSalesCounterReading(),
            $collection->getPreviousSalesCounterReading(),
            $collection->getInitialBaselineReadingEur(),
            $collection->isCounterReset(),
            $collection->getCounterResetNotes(),
            $collection->getExpectedCashboxEur(),
            $collection->getReceptionCashDeliveredEur(),
            $collection->getReceptionistName(),
            $collection->getLinkedRefundRequestId(),
            $collection->getRouteNotes(),
            $overrides['status'] ?? $collection->getStatus(),
            $collection->getCollectedAt(),
            $collection->getClientMutationId(),
            $overrides['delivered_at'] ?? $collection->getDeliveredToVaultAt(),
            $overrides['delivered_by'] ?? $collection->getDeliveredToVaultBy(),
            $overrides['vault'] ?? $collection->getVaultCountedEur(),
            $overrides['discrepancy'] ?? $collection->getDiscrepancyEur(),
            $overrides['reconciled_by'] ?? $collection->getVaultReconciledBy(),
            $overrides['reconciled_name'] ?? $collection->getVaultReconciledByNameSnapshot(),
            $overrides['reconciled_at'] ?? $collection->getVaultReconciledAt(),
            $overrides['justification'] ?? $collection->getDiscrepancyJustification(),
            $collection->getCreatedAt(),
            $collection->getUpdatedAt(),
            $collection->getLocationName(),
            $collection->getLocationCode()
        );
    }
}

final class CashTestRefunds implements RefundRequestRepositoryInterface
{
    /** @var list<array{id: int, from: string, to: string, fields: array<string, mixed>}> */
    public array $transitions = [];

    public bool $transitionResult = true;

    public function insert(RefundRequest $refundRequest): int
    {
        return 0;
    }

    public function findById(int $id): ?RefundRequest
    {
        return null;
    }

    public function findByTrackingToken(string $trackingToken): ?RefundRequest
    {
        return null;
    }

    public function findRestrictedByIncident(int $incidentId): array
    {
        return [];
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        return [];
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function countForCoordinator(array $filters = []): int
    {
        return 0;
    }

    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool
    {
        $this->transitions[] = [
            'id' => $id,
            'from' => $expectedStatus->value,
            'to' => $newStatus->value,
            'fields' => $fields,
        ];

        return $this->transitionResult;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        return false;
    }

    public function detachFromIncident(int $id, RefundStatus $newStatus): bool
    {
        return false;
    }

    public function deactivate(int $id): bool
    {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Escenario
// ─────────────────────────────────────────────────────────────────────────────
$gateway = new CashTestGateway();
$gateway->machines[1] = [
    'id' => 1,
    'machine_code' => 'VEND-CCV06-A',
    'location_id' => 1,
    'sanitary_status' => 'OK',
    'is_out_of_service' => false,
    'is_covered' => true,
    'has_open_thermal_breakdown' => false,
    'has_physical_reception' => true,
    'restocker_name' => 'Reponedora de certificación',
    'restocker_code' => 'REP-06-A',
];
$gateway->machines[2] = array_merge($gateway->machines[1], ['id' => 2, 'is_covered' => false]);
$gateway->machines[3] = array_merge($gateway->machines[1], ['id' => 3, 'sanitary_status' => 'QUARANTINE']);
$gateway->machines[4] = array_merge($gateway->machines[1], ['id' => 4, 'machine_code' => 'VEND-CCV06-D', 'has_physical_reception' => false]);

$gateway->visits[9] = ['id' => 9, 'machine_id' => 1, 'restocker_id' => 10, 'status' => 'COMPLETED'];
$gateway->visits[10] = ['id' => 10, 'machine_id' => 1, 'restocker_id' => 10, 'status' => 'ABORTED_THERMAL_BREACH'];
$gateway->visits[11] = ['id' => 11, 'machine_id' => 1, 'restocker_id' => 11, 'status' => 'COMPLETED'];
$gateway->visits[12] = ['id' => 12, 'machine_id' => 1, 'restocker_id' => 10, 'status' => 'PENDING_INSPECTION'];

$gateway->refunds[300] = ['id' => 300, 'machine_id' => 1, 'status' => 'PENDING_INSPECTION', 'compensation_method' => 'EN_MANO_SEDE', 'claimed_amount' => 4.50, 'is_active' => true];
$gateway->refunds[301] = ['id' => 301, 'machine_id' => 1, 'status' => 'VERIFIED_PENDING_PAYMENT', 'compensation_method' => 'EN_MANO_SEDE', 'claimed_amount' => 4.50, 'is_active' => true];
$gateway->refunds[302] = ['id' => 302, 'machine_id' => 1, 'status' => 'PENDING_INSPECTION', 'compensation_method' => 'EN_MANO_SEDE', 'claimed_amount' => 5.00, 'is_active' => true];

$repository = new CashTestRepository();
$refunds = new CashTestRefunds();
$auditLog = new CashTestAuditLog();
$service = new MachineCashCollectionService(
    $repository,
    $gateway,
    $refunds,
    new CashTestTransactions(),
    new AuditLogger($auditLog)
);

$restocker = ['id' => 10, 'role' => 'RESTOCKER', 'name' => 'Reponedora de certificación'];
$otherRestocker = ['id' => 11, 'role' => 'RESTOCKER', 'name' => 'Otro reponedor'];
$coordinator = ['id' => 20, 'role' => 'COORDINATOR', 'name' => 'Coordinadora de Caja Central'];
$technician = ['id' => 30, 'role' => 'TECHNICIAN', 'name' => 'Técnico'];

$basePayload = static fn (string $seal, string $mutation, float $sales, float $change = 20.00): array => [
    'bag_seal_code' => $seal,
    'client_mutation_id' => $mutation,
    'sales_counter_reading' => $sales,
    'change_tubes_loaded_eur' => $change,
    'collected_at' => '2026-11-10 10:00:00+00',
];

echo "======================================================================\n";
echo " VENDGUARD · T-CCV-06 · Servicio de recaudación, cambio y arqueo\n";
echo "======================================================================\n";

try {
    echo "\n--- Autorización y cerrojos de la captura ---\n";

    assertCashRejects(
        static fn () => $service->registerCollection(1, $basePayload('BAG-2026-060001', 'mutation-ccv06-001', 100.00), $technician),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    assertCashRejects(
        static fn () => $service->registerCollection(999, $basePayload('BAG-2026-060001', 'mutation-ccv06-001', 100.00), $restocker),
        MachineCashCollectionValidationException::MACHINE_NOT_FOUND,
        404
    );
    assertCashRejects(
        static fn () => $service->registerCollection(2, $basePayload('BAG-2026-060001', 'mutation-ccv06-001', 100.00), $restocker),
        MachineCashCollectionValidationException::MACHINE_OUTSIDE_RESTOCKER_ZONES,
        403
    );
    assertCashRejects(
        static fn () => $service->registerCollection(3, $basePayload('BAG-2026-060001', 'mutation-ccv06-001', 100.00), $restocker),
        MachineCashCollectionValidationException::SANITARY_CASH_COLLECTION_BLOCKED,
        409
    );

    $thermalPayload = $basePayload('BAG-2026-060001', 'mutation-ccv06-001', 100.00);
    assertCashRejects(
        static fn () => $service->registerCollectionInTransaction(1, $thermalPayload, $restocker, 10),
        MachineCashCollectionValidationException::THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN,
        422
    );
    assertCashRejects(
        static fn () => $service->registerCollection(1, $basePayload('BAG-2026-060002', 'mutation-ccv06-visit-001', 100.00) + ['restock_visit_id' => 9], $restocker),
        MachineCashCollectionValidationException::RESTOCK_VISIT_NOT_ALLOWED,
        422
    );
    assertCashRejects(
        static fn () => $service->registerCollectionInTransaction(1, $basePayload('BAG-2026-060003', 'mutation-ccv06-visit-002', 100.00), $restocker, 11),
        MachineCashCollectionValidationException::RESTOCK_VISIT_NOT_OWNED,
        403
    );
    assertCashRejects(
        static fn () => $service->registerCollectionInTransaction(1, $basePayload('BAG-2026-060004', 'mutation-ccv06-visit-003', 100.00), $restocker, 12),
        MachineCashCollectionValidationException::RESTOCK_VISIT_NOT_COMPLETED,
        422
    );

    echo "\n--- Precinto, contador y conserjería ---\n";

    assertCashRejects(
        static fn () => $service->registerCollection(1, $basePayload('BAG 1', 'mutation-ccv06-001', 100.00), $restocker),
        MachineCashCollectionValidationException::INVALID_BAG_SEAL_CODE,
        422
    );

    $first = $service->registerCollection(1, $basePayload('bag-2026-060001', 'mutation-ccv06-001', 100.00), $restocker);
    assertCash(
        $first['bag_seal_code'] === 'BAG-2026-060001'
            && $first['status'] === 'SEALED_IN_ROUTE'
            && $first['idempotent_replay'] === false,
        'El alta normaliza el precinto y nace sellada'
    );
    assertCash(
        !array_key_exists('expected_cashbox_eur', $first)
            && !array_key_exists('previous_sales_counter_reading', $first),
        'La respuesta del reponedor no filtra el arqueo ciego (C2, Art. V.4)'
    );
    assertCash($repository->collections[(int)$first['id']]->getExpectedCashboxEur() === 100.00, 'La primera base produce el esperado privado');

    $second = $service->registerCollection(1, $basePayload('BAG-2026-060002', 'mutation-ccv06-002', 125.50), $restocker);
    assertCash(
        $repository->collections[(int)$second['id']]->getExpectedCashboxEur() === 25.50
            && $repository->collections[(int)$second['id']]->getPreviousSalesCounterReading() === 100.00,
        'El servicio lee la última lectura de la máquina y calcula la diferencia (D2)'
    );

    assertCashRejects(
        static fn () => $service->registerCollection(1, $basePayload('BAG-2026-060002', 'mutation-ccv06-003', 130.00), $restocker),
        MachineCashCollectionValidationException::BAG_SEAL_CODE_ALREADY_USED,
        409
    );
    assertCashRejects(
        static fn () => $service->registerCollection(1, $basePayload('BAG-2026-060003', 'mutation-ccv06-004', 120.00), $restocker),
        MachineCashCollectionValidationException::SALES_COUNTER_REGRESSION,
        422
    );

    $resetPayload = $basePayload('BAG-2026-060003', 'mutation-ccv06-005', 810.00) + [
        'is_counter_reset' => true,
        'counter_reset_notes' => 'Sustituido el monedero por avería del mecanismo de devolución.',
    ];
    $reset = $service->registerCollection(1, $resetPayload, $restocker);
    assertCash(
        $repository->collections[(int)$reset['id']]->getExpectedCashboxEur() === 810.00
            && $repository->collections[(int)$reset['id']]->getPreviousSalesCounterReading() === null,
        'El reseteo justificado arranca de cero (C3, Art. V.1)'
    );

    $noName = $basePayload('BAG-2026-060004', 'mutation-ccv06-006', 820.00) + ['reception_cash_delivered_eur' => 4.50];
    assertCashRejects(
        static fn () => $service->registerCollection(1, $noName, $restocker),
        MachineCashCollectionValidationException::RECEPTIONIST_NAME_REQUIRED,
        422
    );

    $noReception = $basePayload('BAG-2026-060004', 'mutation-ccv06-006', 100.00) + [
        'reception_cash_delivered_eur' => 4.50,
        'receptionist_name' => 'Conserje de Recepción',
    ];
    assertCashRejects(
        static fn () => $service->registerCollection(4, $noReception, $restocker),
        MachineCashCollectionValidationException::LOCATION_HAS_NO_PHYSICAL_RECEPTION,
        409
    );

    echo "\n--- Sinergia atómica con el reintegro (D3) ---\n";

    $ineligible = $basePayload('BAG-2026-060005', 'mutation-ccv06-007', 830.00) + [
        'reception_cash_delivered_eur' => 4.50,
        'receptionist_name' => 'Conserje de Recepción',
        'linked_refund_request_id' => 301,
    ];
    assertCashRejects(
        static fn () => $service->registerCollection(1, $ineligible, $restocker),
        MachineCashCollectionValidationException::REFUND_REQUEST_NOT_ELIGIBLE_FOR_RECEPTION,
        409
    );

    $mismatch = $basePayload('BAG-2026-060005', 'mutation-ccv06-008', 830.00) + [
        'reception_cash_delivered_eur' => 4.50,
        'receptionist_name' => 'Conserje de Recepción',
        'linked_refund_request_id' => 302,
    ];
    assertCashRejects(
        static fn () => $service->registerCollection(1, $mismatch, $restocker),
        MachineCashCollectionValidationException::REFUND_AMOUNT_MISMATCH,
        422
    );

    $withRefund = $basePayload('BAG-2026-060006', 'mutation-ccv06-009', 815.00) + [
        'reception_cash_delivered_eur' => 4.50,
        'receptionist_name' => 'Conserje de Recepción',
        'linked_refund_request_id' => 300,
    ];
    $refundCollection = $service->registerCollection(1, $withRefund, $restocker);
    assertCash(count($refunds->transitions) === 1, 'La entrega en conserjería transiciona el reintegro (D3)');
    assertCash(
        $refunds->transitions[0]['to'] === 'DEPOSITED_AT_RECEPTION'
            && $refunds->transitions[0]['fields']['technician_finding'] === 'FOUND_PHYSICAL'
            && $refunds->transitions[0]['fields']['recovered_amount'] === 4.50
            && $refunds->transitions[0]['fields']['cash_custody_action'] === 'LEFT_AT_RECEPTION'
            && $refunds->transitions[0]['fields']['receptionist_name'] === 'Conserje de Recepción',
        'La transición del reintegro lleva los cuatro campos del contrato'
    );

    echo "\n--- Idempotencia (D4) ---\n";

    $auditEventsBeforeRepeat = count($auditLog->events);
    $repeat = $service->registerCollection(1, $basePayload('bag-2026-060006', 'mutation-ccv06-009', 815.00), $restocker);
    assertCash(
        $repeat['id'] === $refundCollection['id'] && $repeat['idempotent_replay'] === true,
        'La mutación repetida devuelve el corte ya registrado'
    );
    assertCash(count($repository->insertedCodes) === 4, 'La repetición no inserta una segunda bolsa');
    assertCash(count($auditLog->events) === $auditEventsBeforeRepeat, 'La repetición no duplica la auditoría del sellado');

    echo "\n--- Recepción en cámara y arqueo (RF-04, D2, D4) ---\n";

    assertCashRejects(
        static fn () => $service->deliverToVault((int)$first['id'], $restocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    assertCashRejects(
        static fn () => $service->deliverToVault(999, $coordinator),
        MachineCashCollectionValidationException::CASH_COLLECTION_NOT_FOUND,
        404
    );

    $delivered = $service->deliverToVault((int)$first['id'], $coordinator);
    assertCash(
        $delivered['status'] === 'DELIVERED_TO_VAULT'
            && $delivered['delivered_to_vault_by'] === 20
            && $delivered['delivered_to_vault_at'] !== null,
        'La recepción en cámara sella estado y actor (RF-04.1)'
    );
    assertCashRejects(
        static fn () => $service->deliverToVault((int)$first['id'], $coordinator),
        MachineCashCollectionValidationException::INVALID_CASH_COLLECTION_STATE_TRANSITION,
        409
    );

    assertCashRejects(
        static fn () => $service->reconcile((int)$second['id'], ['vault_counted_eur' => 25.50], $restocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    assertCashRejects(
        static fn () => $service->reconcile((int)$second['id'], ['vault_counted_eur' => 200.00], $coordinator),
        MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
        422
    );

    $ok = $service->reconcile((int)$second['id'], ['vault_counted_eur' => 26.50], $coordinator);
    assertCash(
        $ok['status'] === 'RECONCILED_OK'
            && $ok['discrepancy_eur'] === 1.00
            && $ok['vault_reconciled_by'] === 20
            && $ok['vault_reconciled_by_name_snapshot'] === 'Coordinadora de Caja Central',
        'El límite de ±1,00 € se sella como RECONCILED_OK (D2)'
    );
    assertCashRejects(
        static fn () => $service->reconcile((int)$second['id'], ['vault_counted_eur' => 26.50], $coordinator),
        MachineCashCollectionValidationException::CASH_COLLECTION_ALREADY_RECONCILED,
        409
    );

    $flagged = $service->reconcile((int)$reset['id'], [
        'vault_counted_eur' => 800.00,
        'discrepancy_justification' => '=Faltan diez euros en la bolsa precintada del corte.',
    ], $coordinator);
    assertCash(
        $flagged['status'] === 'DISCREPANCY_FLAGGED'
            && $flagged['discrepancy_eur'] === -10.00
            && $flagged['discrepancy_justification'] !== null,
        'El descuadre fuera de tolerancia exige y guarda justificación (Art. V.1)'
    );

    echo "\n--- Recibo, historial y libro de Coordinación ---\n";

    $receipt = $service->receptionReceipt((int)$refundCollection['id'], $restocker);
    assertCash(
        count($receipt) === 9
            && $receipt['receptionist_name'] === 'Conserje de Recepción'
            && $receipt['reception_cash_delivered_eur'] === 4.50
            && !array_key_exists('bag_seal_code', $receipt)
            && !array_key_exists('expected_cashbox_eur', $receipt),
        'El recibo de conserjería tiene nueve campos y ningún dato de bolsa (QA 2, Art. V.4)'
    );
    assertCashRejects(
        static fn () => $service->receptionReceipt((int)$refundCollection['id'], $otherRestocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    assertCashRejects(
        static fn () => $service->receptionReceipt((int)$second['id'], $restocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_NOT_FOUND,
        404
    );

    $history = $service->listForRestocker($restocker);
    assertCash(
        count($history['cash_collections']) === 4 && isset($history['summary']['pending_vault_bags_count']),
        'El historial del reponedor devuelve sus cuatro cortes y el resumen de ruta'
    );
    assertCash(
        !array_key_exists('expected_cashbox_eur', $history['cash_collections'][0])
            && !array_key_exists('discrepancy_eur', $history['cash_collections'][0]),
        'El historial del reponedor mantiene el arqueo ciego (C2)'
    );

    assertCashRejects(
        static fn () => $service->listForCoordinator($restocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    $book = $service->listForCoordinator($coordinator);
    assertCash(
        count($book['cash_collections']) === 4
            && $book['kpis']['pending_vault_bags_count'] === 2
            && $book['kpis']['reconciled_ok_count'] === 1
            && $book['kpis']['discrepancy_flagged_count'] === 1
            && $book['kpis']['total_vault_counted_eur'] === 826.50
            && $book['kpis']['net_discrepancy_eur'] === -9.00,
        'El libro de Coordinación expone los 6 KPIs (QA 3)'
    );
    assertCash(
        array_key_exists('expected_cashbox_eur', $book['cash_collections'][0])
            && array_key_exists('previous_sales_counter_reading', $book['cash_collections'][0]),
        'La vista de Coordinación sí contiene los datos financieros (D2)'
    );

    echo "\n--- Volcado offline y CSV (C4, QA 4) ---\n";

    $conflict = $service->dumpConflict([
        'machine_id' => 1,
        'bag_seal_code' => 'bag-2026-060007',
        'client_mutation_id' => 'mutation-ccv06-conflict',
        'reason' => 'El precinto ya existía al sincronizar.',
        'offline_created_at' => '2026-11-10T09:00:00+01:00',
    ], $restocker);
    assertCash($conflict['logged'] === true, 'El conflicto offline se registra (C4)');
    $event = $auditLog->events[count($auditLog->events) - 1];
    assertCash(
        $event->getAction() === 'OFFLINE_CASH_COLLECTION_CONFLICT_DUMP'
            && $event->getEntityType() === 'MACHINE_CASH_COLLECTION'
            && $event->getEntityId() === 1,
        'El volcado usa la acción y la entidad auditables del contrato (Art. III.3)'
    );

    assertCashRejects(
        static fn () => $service->exportCsv($restocker),
        MachineCashCollectionValidationException::CASH_COLLECTION_FORBIDDEN_FOR_ROLE,
        403
    );
    $csv = $service->exportCsv($coordinator, ['status' => 'ALL']);
    assertCash(str_starts_with($csv, "\xEF\xBB\xBF"), 'El CSV arranca con BOM UTF-8 (QA 4)');
    assertCash(
        str_contains($csv, 'Precinto') && str_contains($csv, ';') && str_contains($csv, 'MCC-'),
        'El CSV declara cabeceras y separador de Coordinación'
    );
    assertCash(
        str_contains($csv, "'-10.00") || str_contains($csv, "'=Faltan diez euros"),
        'Las celdas con fórmula o signo se neutralizan (QA 4)'
    );

    echo "\n--- Resultado ---\n";
    echo " Total Aserciones: {$GLOBALS['assertions']}\n";
    echo " RESULTADO: 100% EN VERDE\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}

exit(0);
