<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;

/**
 * Immutable projection for the anonymous public tracking page.
 *
 * The tracking link is the only credential a consumer ever receives, so this DTO
 * is the single place that decides what that link is worth (RNF-REF-03).
 * Everything that identifies a payment instrument or the claimant's identity is
 * structurally absent: there is no `iban`, no `bizum_phone`, no `claimant_name`
 * and no internal justification. Adding such a field here would expose it to
 * anyone who ever forwards or loses the URL.
 *
 * The pickup PIN is the one secret it does carry, and only while the cash is
 * waiting at the reception desk (`status->awaitsPickup()`). The claimant already
 * receives it in the QR receipt, so echoing it on every poll would widen the
 * window in which a leaked URL hands over the PIN with nothing gained.
 */
final readonly class PublicRefundTrackingDTO
{
    /**
     * @param string $statusDescription Consumer-facing sentence explaining what
     *   is happening and what to do next.
     */
    public function __construct(
        public float $claimedAmount,
        public string $productAttempted,
        public string $compensationMethod,
        public RefundStatus $status,
        public string $statusLabel,
        public string $statusDescription,
        public ?string $pickupPin,
        public bool $canRectifyData,
        public ?string $machineCode,
        public ?string $locationName,
        public ?string $createdAt,
        public ?string $updatedAt
    ) {
    }

    /**
     * Builds the projection from a case plus the context the claimant needs to
     * know where to go.
     */
    public static function fromRefundRequest(
        RefundRequest $case,
        ?string $machineCode = null,
        ?string $locationName = null
    ): self {
        return new self(
            claimedAmount: $case->getClaimedAmount(),
            productAttempted: $case->getProductAttempted(),
            compensationMethod: $case->getCompensationMethod()->value,
            status: $case->getStatus(),
            statusLabel: $case->getStatus()->label(),
            statusDescription: self::describeStatus($case->getStatus()),
            pickupPin: $case->getStatus()->awaitsPickup() ? $case->getPickupPin() : null,
            canRectifyData: $case->getStatus()->allowsContactRectification(),
            machineCode: $machineCode,
            locationName: $locationName,
            createdAt: $case->getCreatedAt(),
            updatedAt: $case->getUpdatedAt()
        );
    }

    /**
     * Plain-language explanation of the current state, in Spanish, aimed at a
     * consumer who has no context on vending operations.
     */
    private static function describeStatus(RefundStatus $status): string
    {
        return match ($status) {
            RefundStatus::PENDING_INSPECTION
                => 'Hemos registrado su reclamación. El técnico revisará la máquina y el dinero atascado.',
            RefundStatus::DEPOSITED_AT_RECEPTION
                => 'El técnico ha depositado su dinero en la recepción del centro. Puede pasar a recogerlo facilitando su PIN de 4 dígitos.',
            RefundStatus::VERIFIED_PENDING_PAYMENT
                => 'La revisión técnica ha finalizado. Coordinación está preparando su devolución.',
            RefundStatus::REQUIRES_COORDINATOR_APPROVAL
                => 'Su expediente necesita una revisión adicional por parte de Coordinación.',
            RefundStatus::PENDING_CONTACT
                => 'Necesitamos ponerse en contacto con usted para corregir sus datos de pago antes de continuar.',
            RefundStatus::PAID_DIGITAL
                => 'Su devolución ha sido abonada mediante Bizum o transferencia bancaria.',
            RefundStatus::REFUNDED_IN_HAND
                => 'Su devolución ha sido entregada en mano. Gracias por usar VendGuard.',
            RefundStatus::REJECTED
                => 'Su reclamación ha sido desestimada. Puede contactar con la sede para más detalle.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'claimed_amount' => $this->claimedAmount,
            'product_attempted' => $this->productAttempted,
            'compensation_method' => $this->compensationMethod,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'status_description' => $this->statusDescription,
            'pickup_pin' => $this->pickupPin,
            'can_rectify_data' => $this->canRectifyData,
            'machine_code' => $this->machineCode,
            'location_name' => $this->locationName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}