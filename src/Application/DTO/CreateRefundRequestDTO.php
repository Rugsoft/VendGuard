<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\CompensationMethod;

/**
 * Immutable input for opening a consumer refund case.
 *
 * Carries only what the claimant submitted, so it can be built straight from the
 * public `POST /api/qr/report` body without leaking service concerns into the
 * HTTP layer.
 *
 * The antifraud amount range is deliberately NOT enforced here. A DTO that
 * rejected it would have to throw `InvalidArgumentException`, which surfaces as
 * HTTP 500 instead of the `422 INVALID_REFUND_AMOUNT` mandated by the contract
 * catalogue. `RefundManagementService` therefore owns that rule so the correct
 * domain exception is raised.
 */
final readonly class CreateRefundRequestDTO
{
    public function __construct(
        public int $incidentId,
        public int $machineId,
        public int $locationId,
        public string $claimantName,
        public string $claimantContact,
        public float $claimedAmount,
        public CompensationMethod $compensationMethod,
        public string $productAttempted = '',
        public ?string $bizumPhone = null,
        public ?string $iban = null
    ) {
        if ($incidentId < 1 || $machineId < 1 || $locationId < 1) {
            throw new \InvalidArgumentException('Los identificadores del expediente deben ser enteros positivos.');
        }

        if (trim($claimantName) === '') {
            throw new \InvalidArgumentException('La solicitud requiere el nombre del reclamante.');
        }

        if (trim($claimantContact) === '') {
            throw new \InvalidArgumentException('La solicitud requiere un medio de contacto del reclamante.');
        }

        if (!is_finite($claimedAmount)) {
            throw new \InvalidArgumentException('El importe reclamado debe ser un valor finito.');
        }
    }
}