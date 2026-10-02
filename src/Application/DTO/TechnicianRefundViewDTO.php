<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\TechnicianFinding;

/**
 * Immutable projection of a refund claim for the field technician.
 *
 * The technician is on a route, on a phone, in someone else's building, and the
 * question they need answered is "where does the money physically go if I find
 * it". Everything else about the claimant is none of their business: the IBAN,
 * the Bizum phone and the claimant's name are structurally absent here, not
 * merely blanked (Art. V.4, RF-REF-10).
 *
 * The custody instruction is derived from the CLAIMED amount because that is the
 * only figure available before the technician types what they actually
 * recovered. The binding rule is enforced later on the real recovered amount by
 * `TechnicianRefundService`, which refuses a reception handover for digital
 * channels or amounts above 10,00 €; this text only tells the field operator
 * what to expect, so a rejection never arrives as a surprise.
 */
final readonly class TechnicianRefundViewDTO
{
    /** Cash that may stay at the reception desk (RF-REF-05). */
    public const RECEPTION_DELIVERY_LIMIT = RefundRequest::RECEPTION_DELIVERY_LIMIT;

    private const INSTRUCTION_RECEPTION =
        'Si recupera el dinero, deposítelo en un sobre en la recepción del centro.';
    private const INSTRUCTION_CENTRAL =
        'No lo deje en conserjería: custodie el efectivo en la caja central para su liquidación por Coordinación.';
    private const INSTRUCTION_ALREADY_FILED =
        'El dictamen de saldo de este expediente ya está registrado; no hace falta volver a dictaminarlo.';

    public function __construct(
        public int $id,
        public float $claimedAmount,
        public string $productAttempted,
        public string $compensationMethod,
        public string $status,
        public string $custodyInstruction,
        public bool $awaitingInspection
    ) {
    }

    /**
     * Builds the safe view of one claim.
     */
    public static function fromRefundRequest(RefundRequest $case): self
    {
        return new self(
            id: (int)$case->getId(),
            claimedAmount: $case->getClaimedAmount(),
            productAttempted: $case->getProductAttempted(),
            compensationMethod: $case->getCompensationMethod()->value,
            status: $case->getStatus()->value,
            custodyInstruction: self::custodyInstructionFor($case),
            awaitingInspection: $case->awaitsInspection()
        );
    }

    /**
     * Whether this claim still owes the technician a verdict.
     *
     * A claim whose case has already moved on is shown but never blocks the
     * resolution of the technical incident.
     */
    public function blocksResolution(): bool
    {
        return $this->awaitingInspection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'claimed_amount' => $this->claimedAmount,
            'product_attempted' => $this->productAttempted,
            'compensation_method' => $this->compensationMethod,
            'status' => $this->status,
            'custody_instruction' => $this->custodyInstruction,
        ];
    }

    /**
     * Plain-language custody instruction in Spanish for the field operator.
     */
    private static function custodyInstructionFor(RefundRequest $case): string
    {
        // Un expediente ya dictaminado no vuelve a pedir custodia: decirlo evita
        // que el técnico reintroduzca una decisión sobre dinero ya movido.
        if (!$case->awaitsInspection()) {
            return self::INSTRUCTION_ALREADY_FILED;
        }

        $mustGoCentral = $case->getCompensationMethod()->isDigital()
            || $case->getClaimedAmount() > self::RECEPTION_DELIVERY_LIMIT;

        return $mustGoCentral ? self::INSTRUCTION_CENTRAL : self::INSTRUCTION_RECEPTION;
    }

    /**
     * The closed set of verdicts offered to the field technician (RF-REF-04).
     *
     * @return list<array{value: string, label: string, requires_justification: bool}>
     */
    public static function availableFindings(): array
    {
        return [
            [
                'value' => TechnicianFinding::FOUND_PHYSICAL->value,
                'label' => 'He recuperado dinero físico en la máquina.',
                'requires_justification' => false,
            ],
            [
                'value' => TechnicianFinding::CONFIRMED_NO_CASH->value,
                'label' => 'Fallo verificado de cobro, sin monedas recuperadas.',
                'requires_justification' => false,
            ],
            [
                'value' => TechnicianFinding::UNVERIFIED_NO_CASH->value,
                'label' => 'No localizo dinero ni evidencia técnica de saldo retenido.',
                'requires_justification' => true,
            ],
        ];
    }
}
