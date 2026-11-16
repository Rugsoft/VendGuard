<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\MachineCashCollection;

/**
 * Immutable mobile projection of a sealed cash collection.
 *
 * The restocker is the only actor who must never see the blind cash count: the
 * five financial fields `previous_sales_counter_reading`, `expected_cashbox_eur`,
 * `vault_counted_eur`, `discrepancy_eur` and `discrepancy_justification`, plus
 * `initial_baseline_reading_eur`, are structurally absent here, not blanked or
 * hidden by the controller (RNF-02.3, C2, Art. V.4).
 *
 * The class is the application boundary for every RESTOCKER cash-collection
 * response. The domain entity keeps its own least-privilege projection for internal callers,
 * but the service builds the HTTP payload through this DTO so a future field
 * added to the entity cannot reach the phone by accident: only the explicit
 * whitelist in `toArray()` is published.
 */
final readonly class RestockerCashCollectionViewDTO
{
    /**
     * The exact public fields of the mobile contract.
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = [
        'id',
        'collection_code',
        'restock_visit_id',
        'machine_id',
        'machine_code',
        'bag_seal_code',
        'sales_counter_reading',
        'change_tubes_loaded_eur',
        'is_counter_reset',
        'counter_reset_notes',
        'reception_cash_delivered_eur',
        'receptionist_name',
        'linked_refund_request_id',
        'has_reception_receipt',
        'status',
        'status_label',
        'collected_at',
        'created_at',
    ];

    public function __construct(
        public ?int $id,
        public string $collectionCode,
        public ?int $restockVisitId,
        public int $machineId,
        public string $machineCode,
        public string $bagSealCode,
        public float $salesCounterReading,
        public float $changeTubesLoadedEur,
        public bool $isCounterReset,
        public ?string $counterResetNotes,
        public float $receptionCashDeliveredEur,
        public ?string $receptionistName,
        public ?int $linkedRefundRequestId,
        public bool $hasReceptionReceipt,
        public string $status,
        public string $statusLabel,
        public string $collectedAt,
        public ?string $createdAt
    ) {
    }

    /**
     * Builds the safe view of one collection.
     */
    public static function fromMachineCashCollection(MachineCashCollection $collection): self
    {
        return new self(
            id: $collection->getId(),
            collectionCode: $collection->getCollectionCode(),
            restockVisitId: $collection->getRestockVisitId(),
            machineId: $collection->getMachineId(),
            machineCode: $collection->getMachineCodeSnapshot(),
            bagSealCode: $collection->getBagSealCode(),
            salesCounterReading: $collection->getSalesCounterReading(),
            changeTubesLoadedEur: $collection->getChangeTubesLoadedEur(),
            isCounterReset: $collection->isCounterReset(),
            counterResetNotes: $collection->getCounterResetNotes(),
            receptionCashDeliveredEur: $collection->getReceptionCashDeliveredEur(),
            receptionistName: $collection->getReceptionistName(),
            linkedRefundRequestId: $collection->getLinkedRefundRequestId(),
            hasReceptionReceipt: $collection->hasReceptionDelivery(),
            status: $collection->getStatus()->publicRestockerStatus(),
            statusLabel: $collection->getStatus()->publicRestockerLabel(),
            collectedAt: $collection->getCollectedAt(),
            createdAt: $collection->getCreatedAt()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'collection_code' => $this->collectionCode,
            'restock_visit_id' => $this->restockVisitId,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCode,
            'bag_seal_code' => $this->bagSealCode,
            'sales_counter_reading' => $this->salesCounterReading,
            'change_tubes_loaded_eur' => $this->changeTubesLoadedEur,
            'is_counter_reset' => $this->isCounterReset,
            'counter_reset_notes' => $this->counterResetNotes,
            'reception_cash_delivered_eur' => $this->receptionCashDeliveredEur,
            'receptionist_name' => $this->receptionistName,
            'linked_refund_request_id' => $this->linkedRefundRequestId,
            'has_reception_receipt' => $this->hasReceptionReceipt,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'collected_at' => $this->collectedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
