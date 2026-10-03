<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * Immutable input for registering a digital settlement.
 *
 * VendGuard never talks to a bank gateway (Constitution Art. VI); it records the
 * reference the operator obtained through the usual banking channels, which is
 * what gives the case its accounting justification (RF-REF-07).
 */
final readonly class CoordinatorPaymentDTO
{
    public function __construct(
        public string $paymentReference,
        public float $paidAmount
    ) {
        if (trim($paymentReference) === '') {
            throw new \InvalidArgumentException('La liquidación digital exige la referencia bancaria del justificante.');
        }

        if (!is_finite($paidAmount)) {
            throw new \InvalidArgumentException('El importe liquidado debe ser un valor finito.');
        }
    }
}