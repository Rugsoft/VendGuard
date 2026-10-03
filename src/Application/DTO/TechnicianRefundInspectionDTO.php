<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\TechnicianFinding;

/**
 * Immutable input for the on-site verdict the field technician files when
 * resolving an incident that carries a refund claim (RF-REF-04).
 *
 * The verdict is a closed set: there is no "unknown" option. `CONFIRMED_NO_CASH`
 * asserts a verified mechanical fault with no coins recovered, while
 * `UNVERIFIED_NO_CASH` asserts that nothing was found and no technical evidence
 * of retained balance exists. The second one is the conclusion most worth
 * challenging, so it additionally requires a written justification
 * (see `TechnicianRefundService`).
 *
 * The physical custody decision is left nullable on purpose: leaving it unset is
 * not a choice between the two options but a request for the rules to decide,
 * which is what forces central custody for digital channels and elevated amounts.
 */
final readonly class TechnicianRefundInspectionDTO
{
    public function __construct(
        public TechnicianFinding $finding,
        public ?float $recoveredAmount = null,
        public ?CashCustodyAction $cashCustodyAction = null,
        public string $receptionistName = '',
        public string $justification = ''
    ) {
        if (!is_finite($this->recoveredAmount ?? 0.0)) {
            throw new \InvalidArgumentException('El importe recuperado debe ser un valor finito.');
        }

        if (($this->recoveredAmount ?? 0.0) < 0.0) {
            throw new \InvalidArgumentException('El importe recuperado no puede ser negativo.');
        }
    }

    /**
     * The receptionist name, trimmed, or null when it was not supplied.
     */
    public function receptionistNameOrNull(): ?string
    {
        $trimmed = trim($this->receptionistName);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The written justification, trimmed, or null when it was not supplied.
     */
    public function justificationOrNull(): ?string
    {
        $trimmed = trim($this->justification);

        return $trimmed === '' ? null : $trimmed;
    }
}