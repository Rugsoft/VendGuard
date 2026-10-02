<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\RefundRequest;

/**
 * Immutable projection for the receipt shown to the claimant right after the QR
 * report (RF-REF-02).
 *
 * This is the only moment the pickup PIN legitimately travels: the consumer is
 * standing in front of the machine and needs it to collect the cash later at
 * the reception desk. It is deliberately narrow, because a report lands on an
 * anonymous public endpoint whose response may end up in a proxy log, a browser
 * cache or a screenshot: the IBAN, the Bizum phone and the claimant's name are
 * therefore absent by construction and never echoed back, not even to the person
 * who just typed them.
 *
 * Digital channels get `pickup_pin: null` rather than a missing key, so the
 * frontend can bind the receipt card to one stable shape.
 */
final readonly class RefundReceiptDTO
{
    /** Public entry point the frontend reads the token from (contract §4.1.1). */
    public const TRACKING_URL_PREFIX = '/?track=';

    public function __construct(
        public int $id,
        public float $claimedAmount,
        public string $compensationMethod,
        public string $status,
        public ?string $pickupPin,
        public string $trackingToken,
        public string $trackingUrl
    ) {
    }

    /**
     * Builds the receipt from a freshly opened case.
     */
    public static function fromRefundRequest(RefundRequest $case): self
    {
        return new self(
            id: (int)$case->getId(),
            claimedAmount: $case->getClaimedAmount(),
            compensationMethod: $case->getCompensationMethod()->value,
            status: $case->getStatus()->value,
            pickupPin: $case->getPickupPin(),
            trackingToken: $case->getTrackingToken(),
            trackingUrl: self::TRACKING_URL_PREFIX . $case->getTrackingToken()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'claimed_amount' => $this->claimedAmount,
            'compensation_method' => $this->compensationMethod,
            'status' => $this->status,
            'pickup_pin' => $this->pickupPin,
            'tracking_token' => $this->trackingToken,
            'tracking_url' => $this->trackingUrl,
        ];
    }
}
