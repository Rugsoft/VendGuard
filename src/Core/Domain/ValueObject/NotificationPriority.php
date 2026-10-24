<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationPriority
 *
 * Prioridades de despacho en cola (Módulo 14 · RF-01.3 y Art. II de la Constitución).
 * CRITICAL_SANITARY siempre tiene prevalencia estricta sobre PRIORITY y STANDARD;
 * PRIORITY (avisos operativos que no pueden esperar al lote ordinario, como el
 * relevo de fin de turno) va por delante de STANDARD.
 * Coincide exactamente con el enum `notification_priority` de PostgreSQL.
 */
enum NotificationPriority: string
{
    case CRITICAL_SANITARY = 'CRITICAL_SANITARY';
    case PRIORITY = 'PRIORITY';
    case STANDARD = 'STANDARD';

    /**
     * Etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::CRITICAL_SANITARY => 'Crítica / Sanitaria (Art. II)',
            self::PRIORITY => 'Prioritaria / Operativa',
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

    /**
     * Posición de despacho: menor número se entrega antes.
     *
     * La emergencia sanitaria conserva la primacía absoluta (Art. II); el aviso
     * operativo prioritario va después de ella y antes del lote ordinario.
     */
    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL_SANITARY => 0,
            self::PRIORITY => 1,
            self::STANDARD => 2,
        };
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
