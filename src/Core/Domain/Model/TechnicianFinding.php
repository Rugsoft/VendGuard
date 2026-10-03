<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * On-site verdict of the field technician about the cash withheld by a
 * machine (RF-REF-04).
 */
enum TechnicianFinding: string
{
    case FOUND_PHYSICAL = 'FOUND_PHYSICAL';
    case CONFIRMED_NO_CASH = 'CONFIRMED_NO_CASH';
    case UNVERIFIED_NO_CASH = 'UNVERIFIED_NO_CASH';

    /**
     * Whether the technician physically recovered cash from the machine.
     */
    public function recoveredCash(): bool
    {
        return $this === self::FOUND_PHYSICAL;
    }

    /**
     * Whether this verdict must be escalated to the coordinator regardless of
     * the claimed amount (RF-REF-03).
     */
    public function requiresEscalation(): bool
    {
        return $this === self::UNVERIFIED_NO_CASH;
    }
}