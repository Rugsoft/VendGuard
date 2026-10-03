<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidRefundAmountException
 *
 * Excepción de Dominio lanzada cuando el importe reclamado queda fuera del
 * rango antifraude admitido: debe ser estrictamente mayor que 0,00 € y no
 * superar el tope bloqueante de 50,00 € por reclamación (RF-REF-03).
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidRefundAmountException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_REFUND_AMOUNT';
    public const DEFAULT_MESSAGE = 'El importe reclamado debe ser mayor a 0,00 € y no puede exceder el límite máximo de 50,00 €.';

    /**
     * Message for the settlement mismatch of RF-REF-03.
     *
     * The claim and the approval only have one legal figure each, and the
     * settlement has to match the approved one exactly: a case settled for a
     * fraction lands on `PAID_DIGITAL` and the consumer reads "your refund has
     * been paid" after collecting a different amount than the one agreed.
     */
    public const SETTLEMENT_MISMATCH_MESSAGE = 'La liquidación debe coincidir exactamente con el importe aprobado del expediente.';

    /**
     * Message for a coordinator sign-off above the claim (RF-REF-03).
     *
     * The sign-off settles the gap between what was claimed and what the
     * technician verified, and that gap only goes one way: nobody claims too
     * much, and the company does not refund more than was claimed. It matters
     * because the settlement must equal the approved figure exactly, so an
     * inflated sign-off used to become the one number the API would accept.
     */
    public const APPROVAL_ABOVE_CLAIM_MESSAGE = 'El importe aprobado no puede superar el importe reclamado por el consumidor.';

    /**
     * Message for a figure that is not a whole number of cents.
     *
     * The euro has no subunit below the cent, so `4.005` is not a smaller
     * amount than `4.01`: it is not an amount at all. Accepting it and letting
     * the `DECIMAL(10,2)` column round it was how an unapproved figure ended
     * up persisted as a payment.
     */
    public const NOT_CENT_EXACT_MESSAGE = 'El importe debe expresarse en céntimos, con un máximo de dos decimales.';

    public function __construct(
        private readonly float $attemptedAmount,
        private readonly float $maximumAllowed = 50.00,
        private readonly ?float $expectedAmount = null,
        string $message = self::DEFAULT_MESSAGE
    ) {
        parent::__construct($message, self::HTTP_STATUS);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getAttemptedAmount(): float
    {
        return $this->attemptedAmount;
    }

    public function getMaximumAllowed(): float
    {
        return $this->maximumAllowed;
    }

    /**
     * The one figure the amount was compared against, when the rejection is
     * about a mismatch and not about a range.
     *
     * Claims and approvals are open intervals (anything above 0,00 € and up to
     * 50,00 € is legal), so they have no expected amount: there the ceiling
     * says it all. A settlement does have one, and the caller needs it to know
     * what figure to sign instead.
     */
    public function getExpectedAmount(): ?float
    {
        return $this->expectedAmount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        $details = [
            'attempted_amount' => $this->attemptedAmount,
            'maximum_allowed' => $this->maximumAllowed,
        ];

        if ($this->expectedAmount !== null) {
            $details['expected_amount'] = $this->expectedAmount;
        }

        return $details;
    }
}
