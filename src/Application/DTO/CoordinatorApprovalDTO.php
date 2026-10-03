<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * Immutable input for the coordinator sign-off of a refund case.
 *
 * Required whenever the case needs explicit approval: an amount above 10,00 €,
 * a discrepancy between claimed and recovered cash, or an unverified absence of
 * money (RF-REF-03).
 */
final readonly class CoordinatorApprovalDTO
{
    public function __construct(
        public float $approvedAmount,
        public string $justification = ''
    ) {
        if (!is_finite($approvedAmount)) {
            throw new \InvalidArgumentException('El importe aprobado debe ser un valor finito.');
        }
    }
}