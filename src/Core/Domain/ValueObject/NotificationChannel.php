<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationChannel
 *
 * Canales de comunicación saliente soportados por el Centro de Notificaciones (Módulo 14).
 * Coincide exactamente con el enum `notification_channel` de PostgreSQL.
 *
 * `TELEGRAM` (Módulo 15) es el canal personal del técnico: el destinatario de la
 * fila es su `chat_id`, no una dirección ni una URL. Se añade al enum del motor
 * con la migración 004 y convive con el `WEBHOOK` corporativo, que sigue siendo
 * el canal de emergencias del grupo.
 */
enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';
    case WEBHOOK = 'WEBHOOK';
    case TELEGRAM = 'TELEGRAM';

    /**
     * Etiqueta amigable en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Correo Electrónico',
            self::WEBHOOK => 'Canal de Emergencias (Webhook)',
            self::TELEGRAM => 'Telegram del Técnico',
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
