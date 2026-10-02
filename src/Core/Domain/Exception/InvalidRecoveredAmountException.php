<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InvalidRecoveredAmountException
 *
 * Excepción de Dominio lanzada cuando el efectivo declarado por el técnico no
 * concuerda con las reclamaciones a las que se refiere:
 *
 *  - en `inspectBalance()`, cuando se declara MÁS efectivo del que suman las
 *    reclamaciones vivas de la avería;
 *  - en `registerUnclaimedCash()`, cuando el importe no es un valor finito
 *    estrictamente positivo, o supera el tope de 50,00 €.
 *
 * Sobre-recuperar no es un reembolso mayor, es otro hecho. El sobrante de un
 * cajón no pertenece a un expediente de devolución: se registra por la vía del
 * efectivo no reclamado (RF-REF-04), que lo envía a caja central. Aceptarlo
 * como importe recuperado persistía una cifra que no podía ser cierta (999,00 €
 * recuperados frente a una reclamación de 2,00 €) dentro de la conciliación de
 * caja y de la traza de auditoría.
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 */
class InvalidRecoveredAmountException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const ERROR_CODE = 'INVALID_RECOVERED_AMOUNT';
    public const DEFAULT_MESSAGE = 'El importe de efectivo recuperado no concuerda con las reclamaciones de esta avería. Si se trata de dinero sobrante, regístrelo como efectivo no reclamado.';

    public function __construct(
        private readonly float $attemptedAmount,
        private readonly float $maximumAllowed,
        private readonly ?int $incidentId = null,
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

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'attempted_amount' => $this->attemptedAmount,
            'maximum_allowed' => $this->maximumAllowed,
            'incident_id' => $this->incidentId,
        ];
    }
}