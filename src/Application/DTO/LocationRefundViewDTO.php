<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;

/**
 * Immutable projection of a refund case for the reception desk.
 *
 * The reception portal is a shared screen. Concierge staff rotate through
 * shifts, the terminal is often in plain sight of the queue, and the person
 * collecting their money is standing right there. Everything that identifies a
 * payment instrument or a payment channel is therefore structurally absent here:
 * there is no `iban`, no `bizum_phone` and no `pickup_pin`. The PIN is the whole
 * point of the visit and is only ever read back by the person holding it.
 *
 * The claimant is reduced to initials by `RefundRequest::getAnonymizedClaimantName()`
 * ("Laura Sanitaria" -> "Laura S."), which is enough for a concierge to say "you
 * are Laura S.?" without the screen handing over a name to whoever is standing
 * behind it (Art. V.4, RF-REF-10).
 */
final readonly class LocationRefundViewDTO
{
    public function __construct(
        public int $id,
        public ?string $incidentCode,
        public ?string $machineCode,
        public string $claimantNameAnon,
        public float $claimedAmount,
        public string $compensationMethod,
        public string $status,
        public string $statusLabel,
        public bool $readyForPickup,
        public ?string $createdAt
    ) {
    }

    /**
     * Builds the reception view of one case.
     *
     * `incidentCode` and `machineCode` are resolved by the caller because they
     * need other repositories; both are informational breadcrumbs for the
     * concierge, never financial data.
     */
    public static function fromRefundRequest(
        RefundRequest $case,
        ?string $incidentCode = null,
        ?string $machineCode = null
    ): self {
        return new self(
            id: (int)$case->getId(),
            incidentCode: $incidentCode,
            machineCode: $machineCode,
            claimantNameAnon: $case->getAnonymizedClaimantName(),
            claimedAmount: $case->getClaimedAmount(),
            compensationMethod: $case->getCompensationMethod()->value,
            status: $case->getStatus()->value,
            statusLabel: $case->getStatus()->label(),
            readyForPickup: $case->getStatus()->awaitsPickup(),
            createdAt: $case->getCreatedAt()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_code' => $this->incidentCode,
            'machine_code' => $this->machineCode,
            'claimant_name_anon' => $this->claimantNameAnon,
            'claimed_amount' => $this->claimedAmount,
            'compensation_method' => $this->compensationMethod,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'ready_for_pickup' => $this->readyForPickup,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Whether a case can still be handed over at the desk.
     *
     * Only `DEPOSITED_AT_RECEPTION` qualifies: the cash is physically in an
     * envelope at this building, so this is the only state where releasing it
     * makes sense (RF-REF-06).
     */
    public function acceptsDelivery(): bool
    {
        return $this->status === RefundStatus::DEPOSITED_AT_RECEPTION->value;
    }
}
