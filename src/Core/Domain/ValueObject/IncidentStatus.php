<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * IncidentStatus
 * 
 * Ciclo de vida estricto de una incidencia en VendGuard.
 * Consta de 8 estados auditados. Estados CLOSED y CANCELLED son terminales
 * e inactivan el candado de unicidad por máquina.
 */
enum IncidentStatus: string
{
    case REGISTERED = 'REGISTERED';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PENDING_PARTS = 'PENDING_PARTS';
    case RESOLVED = 'RESOLVED';
    case REOPENED = 'REOPENED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'Registrada',
            self::ASSIGNED => 'Asignada',
            self::IN_PROGRESS => 'En curso',
            self::PENDING_PARTS => 'Pendiente de repuestos',
            self::RESOLVED => 'Resuelta',
            self::REOPENED => 'Reabierta',
            self::CLOSED => 'Cerrada',
            self::CANCELLED => 'Cancelada',
        };
    }

    /**
     * Indica si la incidencia se considera activa en el parque de máquinas.
     * Coincide exactamente con la regla de la columna virtual `is_active_ticket` en MariaDB.
     */
    public function isActive(): bool
    {
        return $this !== self::CLOSED && $this !== self::CANCELLED;
    }

    /**
     * Indica si el estado es de resolución técnica pendiente de validación o cierre.
     */
    public function isResolved(): bool
    {
        return $this === self::RESOLVED;
    }

    /**
     * Indica si es un estado final del ciclo de vida.
     */
    public function isTerminal(): bool
    {
        return $this === self::CLOSED || $this === self::CANCELLED;
    }

    /**
     * Comprueba si es legal transicionar desde el estado actual al estado destino.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::REGISTERED => in_array($target, [self::ASSIGNED, self::CANCELLED], true),
            self::ASSIGNED => in_array($target, [self::IN_PROGRESS, self::ASSIGNED, self::CANCELLED], true),
            self::IN_PROGRESS => in_array($target, [self::PENDING_PARTS, self::RESOLVED, self::CANCELLED], true),
            self::PENDING_PARTS => in_array($target, [self::IN_PROGRESS, self::CANCELLED], true),
            self::RESOLVED => in_array($target, [self::CLOSED, self::REOPENED], true),
            self::REOPENED => in_array($target, [self::ASSIGNED, self::IN_PROGRESS, self::CANCELLED], true),
            self::CLOSED, self::CANCELLED => false,
        };
    }

    /**
     * Devuelve la lista de estados destino permitidos desde el estado actual.
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn(self $target) => $this->canTransitionTo($target)
        ));
    }

    /**
     * Comprueba si una cadena de texto es un estado válido.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye una instancia a partir de una cadena o lanza excepción.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(
                "Estado de incidencia no válido: '{$value}'. Valores permitidos: " . implode(', ', self::values())
            );
        }

        return $instance;
    }

    /**
     * Lista todos los valores escalares disponibles.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
