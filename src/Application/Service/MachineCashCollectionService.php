<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VendGuard\Application\DTO\RestockerCashCollectionViewDTO;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\MachineCashCollectionUniqueViolationException;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\MachineCashCollection;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\CashCollectionRegistrarInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineCashCollectionRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Service\MachineCashCollectionValidator;

/**
 * MachineCashCollectionService
 *
 * Caso de uso de la recaudación de efectivo, el cambio y el arqueo de caja
 * (Módulo 35; RF-02.1, RF-02.2, RF-03.1, RF-03.2, RF-04.1, RF-04.2, RF-05.1,
 * RF-05.3; D1–D4; C1–C4; QA 1–QA 4; Art. II.2, Art. III.3, Art. V.1,
 * Art. V.4).
 *
 * Orquesta, siempre dentro de una transacción:
 *
 *   1. **Captura en ruta.** Rol `RESTOCKER`, perímetro territorial, cerrojo
 *      sanitario, visita no abortada por frío, precinto global, contador
 *      privado y entrega en conserjería. El `expected_cashbox_eur` se calcula
 *      aquí y jamás sale en la respuesta del reponedor (`C2`).
 *   2. **Sinergia con reintegros (`D3`).** El expediente se bloquea, se valida
 *      y se transiciona atómicamente a `DEPOSITED_AT_RECEPTION`; si la
 *      transición pierde la carrera, la recaudación entera se revierte.
 *   3. **Idempotencia (`D4`).** Una mutación repetida devuelve el corte ya
 *      registrado con `idempotent_replay = true` y sin volver a escribir ni auditar.
 *   4. **Ciclo de custodia.** Recepción en cámara (`DELIVERED_TO_VAULT`) y
 *      arqueo terminal con la tolerancia de ±1,00 € (`RECONCILED_OK` o
 *      `DISCREPANCY_FLAGGED`). Una fila terminal no se reescribe.
 *   5. **Superficies.** Historial y resumen opacos del reponedor, libro de
 *      Coordinación con sus 6 KPIs, recibo limpio de conserjería (`QA 2`),
 *      volcado de conflicto offline (`C4`) y CSV con BOM neutralizado (`QA 4`).
 *
 * Los mensajes y códigos son los del contrato; ningún controlador reinterpreta
 * reglas. La persistencia se inyecta: el servicio no conoce PDO.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineCashCollectionService implements CashCollectionRegistrarInterface
{
    public const ACTION_CASH_COLLECTION_SEALED = 'CASH_COLLECTION_SEALED';
    public const ACTION_CASH_COLLECTION_DELIVERED_TO_VAULT = 'CASH_COLLECTION_DELIVERED_TO_VAULT';
    public const ACTION_CASH_COLLECTION_RECONCILED = 'CASH_COLLECTION_RECONCILED';
    public const ACTION_OFFLINE_CASH_COLLECTION_CONFLICT_DUMP = 'OFFLINE_CASH_COLLECTION_CONFLICT_DUMP';

    /** Zona horaria de la exportación de Coordinación (`QA 4`). */
    public const EXPORT_TIMEZONE = 'Europe/Madrid';

    /** BOM UTF-8 de la exportación (`QA 4`). */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /** Desfase máximo admitido para `collected_at` respecto al reloj del servidor (`D2`, `C3`). */
    public const COLLECTED_AT_MAX_FUTURE_SECONDS = 300;

    private MachineCashCollectionRepositoryInterface $collections;

    private MachineCashCollectionGatewayInterface $gateway;

    private RefundRequestRepositoryInterface $refunds;

    private TransactionManagerInterface $transactions;

    private AuditLogger $audit;

    private ?DexAuditLinker $dexAuditLinks;

    public function __construct(
        MachineCashCollectionRepositoryInterface $collections,
        MachineCashCollectionGatewayInterface $gateway,
        RefundRequestRepositoryInterface $refunds,
        TransactionManagerInterface $transactions,
        AuditLogger $audit,
        ?DexAuditLinker $dexAuditLinks = null
    ) {
        $this->collections = $collections;
        $this->gateway = $gateway;
        $this->refunds = $refunds;
        $this->transactions = $transactions;
        $this->audit = $audit;
        $this->dexAuditLinks = $dexAuditLinks;
    }

    /**
     * Registra una captura en ruta (independiente o vinculada a una visita).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed> Proyección del reponedor, sin datos financieros privados,
     *                              con `idempotent_replay` para distinguir alta nueva y replay
     *
     * @throws MachineCashCollectionValidationException
     */
    public function registerCollection(int $machineId, array $payload, array $actor): array
    {
        return $this->transactions->runInTransaction(
            fn (): array => $this->registerCollectionInTransaction($machineId, $payload, $actor)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function registerCollectionInTransaction(
        int $machineId,
        array $payload,
        array $actor,
        ?int $restockVisitId = null
    ): array {
        $restocker = $this->requireActor($actor, 'RESTOCKER');
        $mutationId = trim((string)($payload['client_mutation_id'] ?? ''));
        $dexAuditId = DexAuditLinker::parseOptionalId($payload['dex_audit_id'] ?? null);
        if ($dexAuditId !== null) {
            $this->requireDexAuditLinks()->assertCompatible($dexAuditId, $machineId);
        }

        if (mb_strlen($mutationId, 'UTF-8') < MachineCashCollection::MIN_MUTATION_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El client_mutation_id debe tener al menos %d caracteres reales.',
                MachineCashCollection::MIN_MUTATION_ID_LENGTH
            ));
        }

        $existing = $this->replayForMachine($mutationId, $restocker['id'], $machineId);
        if ($existing !== null) {
            return RestockerCashCollectionViewDTO::fromMachineCashCollection($existing)->toArray() + ['idempotent_replay' => true];
        }

        $context = $this->gateway->lockMachineContext($machineId, $restocker['id']);
        if ($context === null) {
            throw MachineCashCollectionValidationException::machineNotFound($machineId);
        }

        $repeated = $this->replayForMachine($mutationId, $restocker['id'], $machineId);
        if ($repeated !== null) {
            return RestockerCashCollectionViewDTO::fromMachineCashCollection($repeated)->toArray() + ['idempotent_replay' => true];
        }

            if (($context['is_covered'] ?? false) !== true) {
                throw MachineCashCollectionValidationException::machineOutsideRestockerZones($machineId, $restocker['id']);
            }

            MachineCashCollectionValidator::assertMachineAvailable(
                isset($context['sanitary_status']) ? (string)$context['sanitary_status'] : null,
                (bool)($context['is_out_of_service'] ?? false),
                (bool)($context['has_open_thermal_breakdown'] ?? false),
                $machineId
            );

            $payloadVisitId = $this->nullablePositiveInt($payload['restock_visit_id'] ?? null);
            if ($restockVisitId === null && $payloadVisitId !== null) {
                throw MachineCashCollectionValidationException::restockVisitNotAllowedForStandalone();
            }
            if ($restockVisitId !== null && $payloadVisitId !== null && $payloadVisitId !== $restockVisitId) {
                throw new InvalidArgumentException('La visita vinculada no coincide con la visita de la ruta.');
            }
            $visitId = $payloadVisitId ?? $restockVisitId;
            if ($visitId !== null) {
                $visit = $this->gateway->lockRestockVisit($visitId, $machineId);
                if ($visit === null) {
                    throw new InvalidArgumentException(sprintf(
                        'La visita %d no existe o no pertenece a la máquina %d.',
                        $visitId,
                        $machineId
                    ));
                }

                MachineCashCollectionValidator::assertVisitAllowsCashCollection(
                    (string)$visit['status'],
                    $machineId,
                    $visitId
                );

                if ((int)($visit['restocker_id'] ?? 0) !== $restocker['id']) {
                    throw MachineCashCollectionValidationException::restockVisitNotOwnedByRestocker($visitId);
                }

                if (strtoupper(trim((string)$visit['status'])) !== 'COMPLETED') {
                    throw MachineCashCollectionValidationException::restockVisitNotCompleted(
                        $visitId,
                        (string)$visit['status']
                    );
                }
            }

            $seal = MachineCashCollectionValidator::normalizeBagSeal((string)($payload['bag_seal_code'] ?? ''));
            if ($this->collections->findByBagSealCode($seal) !== null) {
                throw MachineCashCollectionValidationException::bagSealCodeAlreadyUsed($seal);
            }

            $previousCollection = $this->collections->findLastForMachine($machineId);
            $previousReading = $previousCollection?->getSalesCounterReading();
            $collectedAt = $this->resolveCollectedAt($payload, $previousCollection);

            $isReset = filter_var($payload['is_counter_reset'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $resetNotes = isset($payload['counter_reset_notes']) && trim((string)$payload['counter_reset_notes']) !== ''
                ? trim((string)$payload['counter_reset_notes'])
                : null;

            $salesReading = $this->requireMoney($payload['sales_counter_reading'] ?? null, 'sales_counter_reading');
            $baseline = $this->money($payload['initial_baseline_reading_eur'] ?? 0.00, 'initial_baseline_reading_eur');
            $change = $this->money($payload['change_tubes_loaded_eur'] ?? 0.00, 'change_tubes_loaded_eur');

            $counter = MachineCashCollectionValidator::resolveCounterReading(
                $isReset,
                $previousReading,
                $salesReading,
                $baseline,
                $resetNotes
            );

            MachineCashCollectionValidator::assertAmountBounds(
                $change,
                $salesReading,
                $counter['expected_cashbox_eur'],
                $baseline
            );

            $delivery = MachineCashCollectionValidator::assertReceptionDelivery(
                $this->money($payload['reception_cash_delivered_eur'] ?? 0.00, 'reception_cash_delivered_eur'),
                isset($payload['receptionist_name']) ? (string)$payload['receptionist_name'] : null,
                (bool)($context['has_physical_reception'] ?? false),
                $machineId
            );

            $refundId = $this->nullablePositiveInt($payload['linked_refund_request_id'] ?? null);
            if ($refundId !== null) {
                $this->assertRefundEligible($refundId, $machineId, $delivery['reception_cash_delivered_eur']);
            }

            $collection = new MachineCashCollection(
                null,
                $this->collections->reserveCollectionCode(),
                $visitId,
                $machineId,
                (string)$context['machine_code'],
                $restocker['id'],
                (string)($context['restocker_name'] ?? $restocker['name']),
                (string)($context['restocker_code'] ?? 'REP-000'),
                $seal,
                $change,
                $salesReading,
                $counter['previous_sales_counter_reading'],
                $counter['initial_baseline_reading_eur'],
                $isReset,
                $resetNotes,
                $counter['expected_cashbox_eur'],
                $delivery['reception_cash_delivered_eur'],
                $delivery['receptionist_name'],
                $refundId,
                isset($payload['route_notes']) && trim((string)$payload['route_notes']) !== ''
                    ? trim((string)$payload['route_notes'])
                    : null,
                CashCollectionStatus::SEALED_IN_ROUTE,
                $collectedAt,
                $mutationId
            );

            try {
                $persisted = $this->collections->insert($collection);
            } catch (MachineCashCollectionUniqueViolationException $exception) {
                if ($exception->isDuplicateMutation() && $exception->getExistingCollection() !== null) {
                    $winner = $exception->getExistingCollection();
                    if ($winner->getMachineId() !== $machineId) {
                        throw MachineCashCollectionValidationException::clientMutationIdAlreadyUsed(
                            $mutationId,
                            $machineId,
                            $winner->getMachineId()
                        );
                    }

                    return RestockerCashCollectionViewDTO::fromMachineCashCollection(
                        $winner
                    )->toArray() + ['idempotent_replay' => true];
                }

                if ($exception->isDuplicateSeal()) {
                    throw MachineCashCollectionValidationException::bagSealCodeAlreadyUsed($seal);
                }

                throw $exception;
            }

            if ($dexAuditId !== null) {
                $collectionId = $persisted->getId();
                if ($collectionId === null) {
                    throw new \RuntimeException('La recaudación se insertó sin devolver una clave primaria.');
                }
                $this->requireDexAuditLinks()->linkToCashCollection($dexAuditId, $machineId, $collectionId);
            }

            if ($refundId !== null) {
                $transitioned = $this->refunds->transitionStatus(
                    $refundId,
                    RefundStatus::PENDING_INSPECTION,
                    RefundStatus::DEPOSITED_AT_RECEPTION,
                    [
                        'technician_finding' => TechnicianFinding::FOUND_PHYSICAL->value,
                        'recovered_amount' => $delivery['reception_cash_delivered_eur'],
                        'cash_custody_action' => CashCustodyAction::LEFT_AT_RECEPTION->value,
                        'receptionist_name' => $delivery['receptionist_name'],
                    ]
                );

                if (!$transitioned) {
                    throw MachineCashCollectionValidationException::refundRequestNotEligibleForReception(
                        $refundId,
                        'el expediente cambió de estado durante la transacción'
                    );
                }
            }

            $this->logCashCollectionEvent(
                action: self::ACTION_CASH_COLLECTION_SEALED,
                collection: $persisted,
                actor: $actor,
                previousState: null,
                newState: $persisted->toCoordinatorArray(),
                metadata: ['machine_id' => $machineId, 'restock_visit_id' => $visitId]
            );

        $response = RestockerCashCollectionViewDTO::fromMachineCashCollection($persisted)->toArray() + ['idempotent_replay' => false];
        if ($dexAuditId !== null) {
            $response['dex_audit_id'] = $dexAuditId;
        }

        return $response;
    }

    private function requireDexAuditLinks(): DexAuditLinker
    {
        if ($this->dexAuditLinks === null) {
            throw new \LogicException('La recaudación declara dex_audit_id pero el enlazador de auditorías no está configurado.');
        }

        return $this->dexAuditLinks;
    }

    /**
     * {@inheritdoc}
     */
    public function summarizeForVisit(int $restockVisitId): ?array
    {
        $collection = $this->collections->findByRestockVisitId($restockVisitId);
        if ($collection === null) {
            return null;
        }

        return [
            'id' => $collection->getId(),
            'collection_code' => $collection->getCollectionCode(),
            'bag_seal_code' => $collection->getBagSealCode(),
            'status' => $collection->getStatus()->publicRestockerStatus(),
            'status_label' => $collection->getStatus()->publicRestockerLabel(),
            'change_tubes_loaded_eur' => $collection->getChangeTubesLoadedEur(),
            'reception_cash_delivered_eur' => $collection->getReceptionCashDeliveredEur(),
            'has_reception_receipt' => $collection->hasReceptionDelivery(),
        ];
    }

    /**
     * Bootstrap móvil de efectivo: enriquece las máquinas de la ruta con los
     * flags de conserjería e historial y agrupa los reintegros elegibles.
     *
     * La consulta no expone `previous_sales_counter_reading` ni ninguna
     * magnitud del arqueo ciego (`C2`, `Art. V.4`).
     *
     * @param list<array<string, mixed>> $machines
     *
     * @return array{machines: list<array<string, mixed>>, pending_reception_refunds: list<array<string, mixed>>, recent_cash_collections: list<array<string, mixed>>, cash_route_summary: array<string, mixed>}
     */
    public function enrichRestockerRoute(int $restockerId, array $machines): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('La ruta exige un reponedor positivo.');
        }

        $bootstrap = $this->gateway->routeBootstrap($restockerId);

        $refundsByMachine = [];
        foreach ($bootstrap['refunds'] as $refund) {
            $refundsByMachine[(int)$refund['machine_id']][] = $refund;
        }

        $enriched = [];
        foreach ($machines as $machine) {
            $machineId = (int)($machine['id'] ?? 0);
            $machine['has_physical_reception'] = (bool)($bootstrap['machines'][$machineId] ?? false);
            $machine['has_prior_cash_collection'] = $machineId > 0
                && $this->collections->findLastForMachine($machineId) !== null;
            $machine['pending_reception_refunds'] = $refundsByMachine[$machineId] ?? [];
            $enriched[] = $machine;
        }

        return [
            'machines' => $enriched,
            'pending_reception_refunds' => $bootstrap['refunds'],
            'recent_cash_collections' => array_map(
                static fn (MachineCashCollection $collection): array => RestockerCashCollectionViewDTO::fromMachineCashCollection($collection)->toArray(),
                $this->collections->listForRestocker($restockerId, ['limit' => 5])
            ),
            'cash_route_summary' => $this->collections->summarizeForRestocker($restockerId),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function routeCashContext(int $restockerId, int $limit = 5): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El contexto de ruta exige un reponedor positivo.');
        }

        $limit = max(1, min($limit, 50));

        return [
            'recent_cash_collections' => array_map(
                static fn (MachineCashCollection $collection): array => RestockerCashCollectionViewDTO::fromMachineCashCollection($collection)->toArray(),
                $this->collections->listForRestocker($restockerId, ['limit' => $limit])
            ),
            'cash_route_summary' => $this->collections->summarizeForRestocker($restockerId),
        ];
    }

    /**
     * Recepción física de la bolsa intacta en Caja Central (RF-04.1, D4).
     *
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed>
     */
    public function deliverToVault(int $collectionId, array $actor): array
    {
        $this->requireActor($actor, 'COORDINATOR');

        $collection = $this->transactions->runInTransaction(function () use ($collectionId, $actor): MachineCashCollection {
            $collection = $this->collections->findById($collectionId);
            if ($collection === null) {
                throw MachineCashCollectionValidationException::cashCollectionNotFound($collectionId);
            }

            if (!$collection->getStatus()->allowsVaultReception()) {
                throw MachineCashCollectionValidationException::invalidCashCollectionStateTransition(
                    $collection->getStatus()->value,
                    CashCollectionStatus::DELIVERED_TO_VAULT->value
                );
            }

            $delivered = $this->collections->markDeliveredToVault($collectionId, (int)$actor['id']);
            if ($delivered === null) {
                throw MachineCashCollectionValidationException::invalidCashCollectionStateTransition(
                    $collection->getStatus()->value,
                    CashCollectionStatus::DELIVERED_TO_VAULT->value
                );
            }

            $this->logCashCollectionEvent(
                action: self::ACTION_CASH_COLLECTION_DELIVERED_TO_VAULT,
                collection: $delivered,
                actor: $actor,
                previousState: $collection->toCoordinatorArray(),
                newState: $delivered->toCoordinatorArray()
            );

            return $delivered;
        });

        return $collection->toCoordinatorArray();
    }

    /**
     * Arqueo ciego terminal en Caja Central (RF-04.2, D2, D4, Art. V.1).
     *
     * @param array<string, mixed> $payload `vault_counted_eur` y `discrepancy_justification`
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed>
     */
    public function reconcile(int $collectionId, array $payload, array $actor): array
    {
        $coordinator = $this->requireActor($actor, 'COORDINATOR');

        $collection = $this->transactions->runInTransaction(function () use ($collectionId, $payload, $actor, $coordinator): MachineCashCollection {
            $collection = $this->collections->findById($collectionId);
            if ($collection === null) {
                throw MachineCashCollectionValidationException::cashCollectionNotFound($collectionId);
            }

            if ($collection->isTerminal()) {
                throw MachineCashCollectionValidationException::cashCollectionAlreadyReconciled(
                    $collection->getStatus()->value
                );
            }

            $vaultCounted = $this->requireMoney($payload['vault_counted_eur'] ?? null, 'vault_counted_eur');
            $justification = isset($payload['discrepancy_justification'])
                && trim((string)$payload['discrepancy_justification']) !== ''
                ? trim((string)$payload['discrepancy_justification'])
                : null;

            $resolution = MachineCashCollectionValidator::resolveReconciliation(
                $collection->getExpectedCashboxEur(),
                $vaultCounted,
                $collection->getStatus(),
                $justification
            );

            $reconciled = $this->collections->reconcile(
                $collectionId,
                $coordinator['id'],
                $coordinator['name'],
                $vaultCounted,
                $resolution['discrepancy_eur'],
                $resolution['status'],
                $resolution['discrepancy_justification']
            );

            if ($reconciled === null) {
                throw MachineCashCollectionValidationException::cashCollectionAlreadyReconciled(
                    $collection->getStatus()->value
                );
            }

            $this->logCashCollectionEvent(
                action: self::ACTION_CASH_COLLECTION_RECONCILED,
                collection: $reconciled,
                actor: $actor,
                previousState: $collection->toCoordinatorArray(),
                newState: $reconciled->toCoordinatorArray(),
                metadata: ['tolerance_eur' => MachineCashCollection::RECONCILIATION_TOLERANCE_EUR]
            );

            return $reconciled;
        });

        return $collection->toCoordinatorArray();
    }

    /**
     * Comprobante limpio de entrega en conserjería (QA 2, Art. V.4).
     *
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed>
     */
    public function receptionReceipt(int $collectionId, array $actor): array
    {
        $actorId = $this->requirePositiveId($actor);
        $role = $this->normalizedRole($actor);

        if ($role !== 'COORDINATOR' && $role !== 'RESTOCKER') {
            throw MachineCashCollectionValidationException::cashCollectionForbiddenForRole($role);
        }

        $collection = $this->collections->findById($collectionId);
        if ($collection === null) {
            throw MachineCashCollectionValidationException::cashCollectionNotFound($collectionId);
        }

        if ($role === 'RESTOCKER' && $collection->getRestockerId() !== $actorId) {
            throw MachineCashCollectionValidationException::cashCollectionForbiddenForRole($role);
        }

        if (!$collection->hasReceptionDelivery()) {
            throw MachineCashCollectionValidationException::cashCollectionNotFound($collectionId);
        }

        return $collection->toReceptionReceiptArray();
    }

    /**
     * Historial opaco del reponedor con su resumen de ruta (QA 3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     *
     * @return array{cash_collections: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function listForRestocker(array $actor, array $filters = []): array
    {
        $restocker = $this->requireActor($actor, 'RESTOCKER');

        $collections = array_map(
            static fn (MachineCashCollection $collection): array => RestockerCashCollectionViewDTO::fromMachineCashCollection($collection)->toArray(),
            $this->collections->listForRestocker($restocker['id'], $filters)
        );

        return [
            'cash_collections' => $collections,
            'summary' => $this->collections->summarizeForRestocker($restocker['id'], $filters),
        ];
    }

    /**
     * Expediente completo de una recaudación para Caja Central.
     *
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed>
     */
    public function findForCoordinator(int $collectionId, array $actor): array
    {
        $this->requireActor($actor, 'COORDINATOR');

        $collection = $this->collections->findById($collectionId);
        if ($collection === null) {
            throw MachineCashCollectionValidationException::cashCollectionNotFound($collectionId);
        }

        return $collection->toCoordinatorArray();
    }

    /**
     * Libro financiero de Coordinación con sus 6 KPIs (QA 3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     *
     * @return array{cash_collections: list<array<string, mixed>>, kpis: array<string, mixed>}
     */
    public function listForCoordinator(array $actor, array $filters = []): array
    {
        $this->requireActor($actor, 'COORDINATOR');

        $book = $this->collections->listForCoordinator($filters);

        return [
            'cash_collections' => array_map(
                static fn (MachineCashCollection $collection): array => $collection->toCoordinatorArray(),
                $book['collections']
            ),
            'kpis' => $book['kpis'],
        ];
    }

    /**
     * Resumen opaco de efectivo movilizado en la ruta del reponedor (QA 3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function routeSummary(array $actor, array $filters = []): array
    {
        $restocker = $this->requireActor($actor, 'RESTOCKER');

        return $this->collections->summarizeForRestocker($restocker['id'], $filters);
    }

    /**
     * Volcado del conflicto de una recaudación offline rechazada (C4).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $actor
     *
     * @return array{logged: bool, action: string}
     */
    public function dumpConflict(array $payload, array $actor): array
    {
        $restocker = $this->requireActor($actor, 'RESTOCKER');
        $machineId = $this->requirePositiveId(['id' => $payload['machine_id'] ?? null]);

        $machine = $this->gateway->lockMachineContext($machineId, $restocker['id']);
        if ($machine === null) {
            throw MachineCashCollectionValidationException::machineNotFound($machineId);
        }
        if (!(bool)($machine['is_covered'] ?? false)) {
            throw MachineCashCollectionValidationException::machineOutsideRestockerZones(
                $machineId,
                $restocker['id']
            );
        }

        $conflict = MachineCashCollectionValidator::normalizeConflictDumpPayload($payload);

        $event = new AuditEvent(
            null,
            AuditEvent::ENTITY_MACHINE,
            $machineId,
            self::ACTION_OFFLINE_CASH_COLLECTION_CONFLICT_DUMP,
            $restocker['id'],
            'RESTOCKER',
            $restocker['name'],
            null,
            [
                'machine_id' => $machineId,
                'machine_code' => (string)($machine['machine_code'] ?? ''),
                'bag_seal_code' => $conflict['bag_seal_code'],
                'client_mutation_id' => $conflict['client_mutation_id'],
                'reason' => $conflict['reason'],
                'offline_created_at' => $conflict['offline_created_at'],
            ]
        );

        $this->audit->logEvent($event);

        return ['logged' => true, 'action' => self::ACTION_OFFLINE_CASH_COLLECTION_CONFLICT_DUMP];
    }

    /**
     * Exportación CSV de Coordinación con BOM y neutralización (QA 4).
     *
     * La columna «Estado» publica la etiqueta legible del ciclo de custodia —la
     * misma que pinta la consola (`CashCollectionStatus::label()`, H-4)— y la
     * columna final «Código de estado» conserva el valor técnico del `ENUM` para
     * las integraciones que ya leían el código.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     */
    public function exportCsv(array $actor, array $filters = []): string
    {
        $this->requireActor($actor, 'COORDINATOR');

        $collections = $this->collections->listAllForCoordinator($filters);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el flujo temporal de la exportación.');
        }

        fputcsv($handle, [
            'Código',
            'Fecha (Europe/Madrid)',
            'Reponedor',
            'Máquina',
            'Sede',
            'Precinto',
            'Estado',
            'Cambio (EUR)',
            'Contado (EUR)',
            'Descuadre (EUR)',
            'Justificación',
            'Código de estado',
        ], ';');

        foreach ($collections as $collection) {
            fputcsv($handle, array_map(
                fn (mixed $value): string => $this->neutralizeCsvCell($value),
                [
                    $collection->getCollectionCode(),
                    $this->formatMadridTimestamp($collection->getCollectedAt()),
                    $collection->getRestockerNameSnapshot(),
                    $collection->getMachineCodeSnapshot(),
                    (string)($collection->getLocationName() ?? ''),
                    $collection->getBagSealCode(),
                    $collection->getStatus()->label(),
                    number_format($collection->getChangeTubesLoadedEur(), 2, '.', ''),
                    $collection->getVaultCountedEur() === null
                        ? ''
                        : number_format($collection->getVaultCountedEur(), 2, '.', ''),
                    $collection->getDiscrepancyEur() === null
                        ? ''
                        : number_format($collection->getDiscrepancyEur(), 2, '.', ''),
                    (string)($collection->getDiscrepancyJustification() ?? ''),
                    $collection->getStatus()->value,
                ]
            ), ';');
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return self::UTF8_BOM . ($body !== false ? $body : '');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $actor
     *
     * @return array{id: int, name: string, role: string}
     */
    private function requireActor(array $actor, string $expectedRole): array
    {
        $role = $this->normalizedRole($actor);

        if ($role !== $expectedRole) {
            throw MachineCashCollectionValidationException::cashCollectionForbiddenForRole($role);
        }

        return [
            'id' => $this->requirePositiveId($actor),
            'name' => isset($actor['name']) && trim((string)$actor['name']) !== ''
                ? trim((string)$actor['name'])
                : 'Usuario',
            'role' => $role,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function normalizedRole(array $actor): string
    {
        return strtoupper(trim((string)($actor['role'] ?? 'UNKNOWN')));
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function requirePositiveId(array $actor): int
    {
        $id = (int)($actor['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('El actor autenticado debe declarar un identificador positivo.');
        }

        return $id;
    }

    /**
     * Valida el formato y la ventana temporal del corte de ruta (`D2`, `C3`).
     *
     * El cliente puede fechar la captura, pero nunca por delante del reloj del
     * servidor más un desfase de reloj, ni por detrás de la última captura de
     * la máquina: `findLastForMachine()` ordena por `collected_at` y una fecha
     * retrocedida alteraría la lectura previa y el esperado de las siguientes.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveCollectedAt(array $payload, ?MachineCashCollection $previousCollection): string
    {
        $raw = isset($payload['collected_at']) && trim((string)$payload['collected_at']) !== ''
            ? trim((string)$payload['collected_at'])
            : (new DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

        try {
            $collected = new DateTimeImmutable($raw);
        } catch (\Exception $exception) {
            throw MachineCashCollectionValidationException::invalidCollectedAt($raw);
        }

        $now = new DateTimeImmutable('now');
        if ($collected > $now->modify('+' . self::COLLECTED_AT_MAX_FUTURE_SECONDS . ' seconds')) {
            throw MachineCashCollectionValidationException::collectedAtInFuture(
                $raw,
                $now->modify('+' . self::COLLECTED_AT_MAX_FUTURE_SECONDS . ' seconds')->format('Y-m-d H:i:sP')
            );
        }

        if ($previousCollection !== null) {
            $lastCollectedAt = $previousCollection->getCollectedAt();
            if ($collected < new DateTimeImmutable($lastCollectedAt)) {
                throw MachineCashCollectionValidationException::collectedAtBeforeLastCapture($raw, $lastCollectedAt);
            }
        }

        return $raw;
    }

    /**
     * Resuelve el replay idempotente sin cruzar la máquina de la captura (D4).
     *
     * La clave de idempotencia sigue siendo `(restocker_id, client_mutation_id)`,
     * pero reutilizarla en otra máquina no puede devolver una bolsa ajena: se
     * rechaza con `409 CLIENT_MUTATION_ID_ALREADY_USED`. Al comparar la máquina
     * se cubre también la carrera en la que el `INSERT` pierde contra el índice
     * único y la fila ganadora aparece después del bloqueo.
     */
    private function replayForMachine(
        string $mutationId,
        int $restockerId,
        int $machineId
    ): ?MachineCashCollection {
        $existing = $this->collections->findByClientMutationId($restockerId, $mutationId);
        if ($existing === null) {
            return null;
        }

        if ($existing->getMachineId() !== $machineId) {
            throw MachineCashCollectionValidationException::clientMutationIdAlreadyUsed(
                $mutationId,
                $machineId,
                $existing->getMachineId()
            );
        }

        return $existing;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int)$value;
        if ($id <= 0) {
            throw new InvalidArgumentException('Los identificadores opcionales deben ser enteros positivos.');
        }

        return $id;
    }

    private function requireMoney(mixed $value, string $field): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('El campo %s es obligatorio y debe ser numérico.', $field));
        }

        return round((float)$value, 2);
    }

    private function money(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('El campo %s debe ser numérico.', $field));
        }

        return round((float)$value, 2);
    }

    private function assertRefundEligible(int $refundRequestId, int $machineId, float $deliveredAmount): void
    {
        $refund = $this->gateway->lockRefundForReception($refundRequestId);

        if ($refund === null) {
            throw MachineCashCollectionValidationException::refundRequestNotEligibleForReception(
                $refundRequestId,
                'el expediente no existe'
            );
        }

        if ((int)$refund['machine_id'] !== $machineId) {
            throw MachineCashCollectionValidationException::refundRequestNotEligibleForReception(
                $refundRequestId,
                'pertenece a otra máquina'
            );
        }

        if (($refund['is_active'] ?? false) !== true
            || ($refund['status'] ?? '') !== RefundStatus::PENDING_INSPECTION->value) {
            throw MachineCashCollectionValidationException::refundRequestNotEligibleForReception(
                $refundRequestId,
                'no está pendiente de inspección'
            );
        }

        if (($refund['compensation_method'] ?? '') !== 'EN_MANO_SEDE') {
            throw MachineCashCollectionValidationException::refundRequestNotEligibleForReception(
                $refundRequestId,
                'su método de compensación no es EN_MANO_SEDE'
            );
        }

        if (round((float)$refund['claimed_amount'], 2) !== round($deliveredAmount, 2)) {
            throw MachineCashCollectionValidationException::refundAmountMismatch(
                (float)$refund['claimed_amount'],
                $deliveredAmount
            );
        }
    }

    /**
     * Auditoría del ciclo de vida de la bolsa precintada (Módulo 35).
     *
     * La acción viaja como argumento nombrado `action:` y el método lleva nombre propio
     * —no el genérico `logEvent()` de `AuditLogger`, cuya firma recibe el `AuditEvent` ya
     * compuesto— para que la guarda de vocabulario del visor pueda enumerar los tres
     * hechos: sin una firma reconocible, `CASH_COLLECTION_SEALED`,
     * `CASH_COLLECTION_DELIVERED_TO_VAULT` y `CASH_COLLECTION_RECONCILED` se escribían en
     * `audit_log` sin etiqueta ni filtro en la consola de auditoría (Art. III.3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     */
    private function logCashCollectionEvent(
        string $action,
        MachineCashCollection $collection,
        array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): void {
        $this->audit->logEvent(new AuditEvent(
            null,
            AuditEvent::ENTITY_MACHINE_CASH_COLLECTION,
            (int)$collection->getId(),
            $action,
            (int)$actor['id'],
            $this->normalizedRole($actor),
            isset($actor['name']) ? (string)$actor['name'] : 'Usuario',
            $previousState,
            $newState,
            $metadata
        ));
    }

    private function formatMadridTimestamp(string $timestamp): string
    {
        try {
            return (new DateTimeImmutable($timestamp))
                ->setTimezone(new DateTimeZone(self::EXPORT_TIMEZONE))
                ->format('d/m/Y H:i');
        } catch (\Exception) {
            return $timestamp;
        }
    }

    private function neutralizeCsvCell(mixed $value): string
    {
        $cell = (string)$value;

        if ($cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
            return "'" . $cell;
        }

        return $cell;
    }
}
