<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;

/**
 * Immutable full financial projection of a refund case for Coordination.
 *
 * This is the ONE projection of the module that shows money. The three roles
 * before it deliberately cannot: the public tracking page is opened by whoever
 * holds the link, the reception desk is a shared terminal in plain sight of the
 * queue, and the technician is on a route in someone else's building (Art. V.4,
 * RF-REF-10).
 *
 * Coordination is the counterpart: someone in the central office has to decide
 * whether the company owes money, and to pay it, which is impossible without
 * the destination account and the contact. So here the IBAN and the Bizum phone
 * ARE part of the payload, because refusing them would only make an operator
 * open a second system to do the job.
 *
 * Two things stay out even here, and they are not an oversight:
 *
 * - `pickup_pin`: the PIN releases cash at the reception desk. Coordination
 *   settles money centrally and never hands over an envelope, so a coordinator
 *   holding the PIN could release someone else's over the phone.
 * - `tracking_token`: the permanent public link of the claimant. If it reached a
 *   coordination screen it would end up in a screenshot, and anyone with that
 *   screenshot reads the claimant's state anonymously.
 *
 * The derived flags are what make the inbox usable: `requiresSpecialSupervision`
 * and `discrepancyRatio` are computed by the domain entity, so the tab that
 * highlights the pending double approval never re-implements the 20% rule in
 * JavaScript (RF-REF-03, RF-REF-08).
 */
final readonly class CoordinatorRefundViewDTO
{
    public function __construct(
        public int $id,
        public int $incidentId,
        public ?string $incidentCode,
        public int $machineId,
        public ?string $machineCode,
        public int $locationId,
        public ?string $locationName,
        public string $claimantName,
        public string $claimantContact,
        public float $claimedAmount,
        public ?float $recoveredAmount,
        public ?float $approvedAmount,
        public ?float $discrepancyRatio,
        public string $productAttempted,
        public string $compensationMethod,
        public ?string $bizumPhone,
        public ?string $iban,
        public string $status,
        public string $statusLabel,
        public ?string $technicianFinding,
        public ?string $cashCustodyAction,
        public ?string $technicianJustification,
        public ?string $coordinatorDecision,
        public ?string $coordinatorJustification,
        public ?string $paymentReference,
        public ?string $paidAt,
        public bool $requiresSpecialSupervision,
        public bool $requiresApproval,
        public bool $awaitsPayment,
        public ?string $createdAt
    ) {
    }

    /**
     * Builds the coordination view of one case.
     *
     * `incidentCode`, `machineCode` and `locationName` are resolved by the
     * caller because they live in other repositories; they are breadcrumbs for
     * the operator ("which machine, which building") and nothing else.
     */
    public static function fromRefundRequest(
        RefundRequest $case,
        ?string $incidentCode = null,
        ?string $machineCode = null,
        ?string $locationName = null
    ): self {
        $status = $case->getStatus();

        return new self(
            id: (int)$case->getId(),
            incidentId: $case->getIncidentId(),
            incidentCode: $incidentCode,
            machineId: $case->getMachineId(),
            machineCode: $machineCode,
            locationId: $case->getLocationId(),
            locationName: $locationName,
            claimantName: $case->getClaimantName(),
            claimantContact: $case->getClaimantContact(),
            claimedAmount: $case->getClaimedAmount(),
            recoveredAmount: $case->getRecoveredAmount(),
            approvedAmount: $case->getApprovedAmount(),
            discrepancyRatio: $case->discrepancyRatio(),
            productAttempted: $case->getProductAttempted(),
            compensationMethod: $case->getCompensationMethod()->value,
            bizumPhone: $case->getBizumPhone(),
            iban: $case->getIban(),
            status: $status->value,
            statusLabel: $status->label(),
            technicianFinding: $case->getTechnicianFinding()?->value,
            cashCustodyAction: $case->getCashCustodyAction()?->value,
            technicianJustification: $case->getTechnicianJustification(),
            coordinatorDecision: $case->getCoordinatorDecision()?->value,
            coordinatorJustification: $case->getCoordinatorJustification(),
            paymentReference: $case->getPaymentReference(),
            paidAt: $case->getPaidAt(),
            requiresSpecialSupervision: $case->requiresSpecialSupervision(),
            requiresApproval: $status === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
            awaitsPayment: $status === RefundStatus::VERIFIED_PENDING_PAYMENT,
            createdAt: $case->getCreatedAt()
        );
    }

    /**
     * The amount Coordination is expected to settle if it pays this case.
     *
     * The approved figure wins because that is the formal decision; before any
     * sign-off it falls back to the verified amount and finally to the claim,
     * so the inbox can total the pending liability without a second query.
     */
    public function payableAmount(): float
    {
        if ($this->approvedAmount !== null) {
            return $this->approvedAmount;
        }

        if ($this->recoveredAmount !== null && !$this->requiresSpecialSupervision) {
            return $this->recoveredAmount;
        }

        return $this->claimedAmount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'incident_code' => $this->incidentCode,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCode,
            'location_id' => $this->locationId,
            'location_name' => $this->locationName,
            'claimant_name' => $this->claimantName,
            'claimant_contact' => $this->claimantContact,
            'claimed_amount' => $this->claimedAmount,
            'recovered_amount' => $this->recoveredAmount,
            'approved_amount' => $this->approvedAmount,
            'payable_amount' => $this->payableAmount(),
            'discrepancy_ratio' => $this->discrepancyRatio,
            'product_attempted' => $this->productAttempted,
            'compensation_method' => $this->compensationMethod,
            'bizum_phone' => $this->bizumPhone,
            'iban' => $this->iban,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'technician_finding' => $this->technicianFinding,
            'cash_custody_action' => $this->cashCustodyAction,
            'technician_justification' => $this->technicianJustification,
            'coordinator_decision' => $this->coordinatorDecision,
            'coordinator_justification' => $this->coordinatorJustification,
            'payment_reference' => $this->paymentReference,
            'paid_at' => $this->paidAt,
            'requires_special_supervision' => $this->requiresSpecialSupervision,
            'requires_approval' => $this->requiresApproval,
            'awaits_payment' => $this->awaitsPayment,
            'created_at' => $this->createdAt,
        ];
    }
}