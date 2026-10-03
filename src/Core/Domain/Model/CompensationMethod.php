<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Channel chosen by the claimant to receive their money back.
 */
enum CompensationMethod: string
{
    case EN_MANO_SEDE = 'EN_MANO_SEDE';
    case BIZUM = 'BIZUM';
    case TRANSFERENCIA_BANCARIA = 'TRANSFERENCIA_BANCARIA';

    /**
     * Whether the refund is settled digitally, which forces the recovered cash
     * into the central safe instead of the reception desk (RF-REF-05).
     */
    public function isDigital(): bool
    {
        return $this !== self::EN_MANO_SEDE;
    }

    /**
     * Whether this method requires a Bizum phone number to be supplied.
     */
    public function requiresBizumPhone(): bool
    {
        return $this === self::BIZUM;
    }

    /**
     * Whether this method requires an IBAN to be supplied.
     */
    public function requiresIban(): bool
    {
        return $this === self::TRANSFERENCIA_BANCARIA;
    }
}