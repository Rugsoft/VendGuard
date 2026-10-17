<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationChannel
 *
 * Canales de comunicación saliente soportados por el Centro de Notificaciones (Módulo 14).
 * Coincide exactamente con el enum `notification_channel` de PostgreSQL.
 */
enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';
    case WEBHOOK = 'WEBHOOK';

    /**
     * Etiqueta amigable en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Correo Electrónico',
            self::WEBHOOK => 'Canal de Emergencias (Webhook)',
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
            throw new InvalidArgumentException(sprintf('Canal de notificación no válido: «%s»', $value));
        }

        return $instance;
    }
}
