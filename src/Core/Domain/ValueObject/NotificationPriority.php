<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationPriority
 *
 * Prioridades de despacho en cola (Módulo 14 · RF-01.3 y Art. II de la Constitución).
 * CRITICAL_SANITARY siempre tiene prevalencia estricta sobre STANDARD en el despachador.
 * Coincide exactamente con el enum `notification_priority` de PostgreSQL.
 */
enum NotificationPriority: string
{
    case CRITICAL_SANITARY = 'CRITICAL_SANITARY';
    case STANDARD = 'STANDARD';

    /**
     * Etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::CRITICAL_SANITARY => 'Crítica / Sanitaria (Art. II)',
            self::STANDARD => 'Estándar / Operativa',
        };
    }

    /**
     * Indica si la notificación ostenta prioridad sanitaria absoluta.
     */
    public function isCritical(): bool
    {
        return $this === self::CRITICAL_SANITARY;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(sprintf('Prioridad de notificación no válida: «%s»', $value));
        }

        return $instance;
    }
}
