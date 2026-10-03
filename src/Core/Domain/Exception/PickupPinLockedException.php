<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PickupPinLockedException
 *
 * Excepción de Dominio lanzada cuando se intenta entregar un sobre con el PIN
 * de recogida bloqueado por intentos fallidos (RF-REF-02).
 *
 * El PIN son cuatro dígitos: diez mil combinaciones. La comparación en tiempo
 * constante protege el secreto frente a fugas por temporización, pero no frena
 * a quien reintenta en volumen: contra este endpoint se toleraron 3000 PIN
 * incorrectos en 1,02 segundos, de modo que el espacio entero era alcanzable en
 * menos de cuatro minutos. El freno es un contador por expediente y no una
 * ampliación del secreto, porque el consumidor tiene que poder leer un número de
 * su móvil y entregarlo en la conserjería.
 *
 * El bloqueo dura 15 minutos y se levanta solo. Un bloqueo permanente sería un
 * ataque de denegación de servicio contra el propio usuario que quiere su
 * dinero, así que la regla protege el expediente sin cerrar la puerta.
 *
 * Mapea directamente al código HTTP 423 Locked, que a diferencia del 429 dice
 * "vuelve más tarde por una razón que ya conoces" en lugar de "has abusado del
 * servicio".
 */
class PickupPinLockedException extends DomainException
{
    public const HTTP_STATUS = 423;
    public const ERROR_CODE = 'PICKUP_PIN_LOCKED';
    public const DEFAULT_MESSAGE = 'El PIN de recogida está bloqueado por intentos incorrectos. Inténtelo de nuevo en 15 minutos o solicite un nuevo PIN en el punto de atención.';

    public function __construct(
        private readonly int $failedAttempts,
        private readonly ?string $lockedUntil = null,
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

    /**
     * Cuántos intentos fallidos provocaron el bloqueo. No aparece en el mensaje
     * (delataría el avance de un ataque), sólo en los detalles estructurados que
     * consume la interfaz de conserjería.
     */
    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    public function getLockedUntil(): ?string
    {
        return $this->lockedUntil;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'failed_attempts' => $this->failedAttempts,
            'locked_until' => $this->lockedUntil,
        ];
    }
}