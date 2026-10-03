<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Physical custody decision taken by the technician over the recovered cash
 * (RF-REF-05).
 */
enum CashCustodyAction: string
{
    case LEFT_AT_RECEPTION = 'LEFT_AT_RECEPTION';
    case HELD_FOR_CENTRAL = 'HELD_FOR_CENTRAL';

    /**
     * Spanish label exposed in the audit trail and the technician summary.
     */
    public function label(): string
    {
        return match ($this) {
            self::LEFT_AT_RECEPTION => 'Depositado en conserjería',
            self::HELD_FOR_CENTRAL => 'Custodiado para caja central',
        };
    }
}