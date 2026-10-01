<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Lifecycle of a refund case.
 *
 * Deliberately independent from the incident status machine: a machine must be
 * able to go back into service without waiting for the claimant to be paid
 * (Constitution Art. II).
 */
enum RefundStatus: string
{
    case PENDING_INSPECTION = 'PENDING_INSPECTION';
    case DEPOSITED_AT_RECEPTION = 'DEPOSITED_AT_RECEPTION';
    case VERIFIED_PENDING_PAYMENT = 'VERIFIED_PENDING_PAYMENT';
    case REQUIRES_COORDINATOR_APPROVAL = 'REQUIRES_COORDINATOR_APPROVAL';
    case PENDING_CONTACT = 'PENDING_CONTACT';
    case PAID_DIGITAL = 'PAID_DIGITAL';
    case REFUNDED_IN_HAND = 'REFUNDED_IN_HAND';
    case REJECTED = 'REJECTED';

    /**
     * Whether the claimant still has something to provide before the case can
     * move forward, which is what lets the public tracking view offer the
     * rectification form (RF-REF-07).
     */
    public function allowsContactRectification(): bool
    {
        return $this === self::PENDING_CONTACT;
    }

    /**
     * Whether the case reached a settled state and must no longer change
     * (Art. III: terminal states are preserved for audit).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::PAID_DIGITAL, self::REFUNDED_IN_HAND, self::REJECTED], true);
    }

    /**
     * Whether the cash is currently waiting at the reception desk behind the
     * four-digit pickup PIN.
     */
    public function awaitsPickup(): bool
    {
        return $this === self::DEPOSITED_AT_RECEPTION;
    }

    /**
     * Spanish label exposed to the end consumer on the public tracking page.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING_INSPECTION => 'Pendiente de inspección técnica',
            self::DEPOSITED_AT_RECEPTION => 'Efectivo depositado en conserjería',
            self::VERIFIED_PENDING_PAYMENT => 'Verificado, pendiente de pago',
            self::REQUIRES_COORDINATOR_APPROVAL => 'Pendiente de aprobación de Coordinación',
            self::PENDING_CONTACT => 'Pendiente de contacto para completar datos',
            self::PAID_DIGITAL => 'Reembolsado por Bizum o transferencia',
            self::REFUNDED_IN_HAND => 'Reembolsado en mano',
            self::REJECTED => 'Reclamación desestimada',
        };
    }
}