<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * UnconfiguredEmailTransport — Transporte de diagnóstico del canal de correo (T-NOTIF-07).
 *
 * Entra en la composición cuando el entorno no declara `VENDGUARD_SMTP_HOST`.
 * No intenta abrir socket alguno y responde siempre con un **fallo transitorio**
 * explicando la causa:
 *
 *   - El proceso batch no revienta ni silencia el correo: cada intento queda
 *     registrado con su diagnóstico y sigue la curva de reintentos. Un mensaje
 *     no entregado es un hecho operativo que Coordinación debe ver en el panel,
 *     no una excepción que aborta la pasada y deja el resto de la cola sin
 *     despachar.
 *   - Se declara transitorio a propósito: configurar el SMTP y reintentar desde
 *     la bandeja resuelve el envío sin intervención sobre la cola (RF-06.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class UnconfiguredEmailTransport implements NotificationTransportInterface
{
    public const DIAGNOSTIC = 'El transporte SMTP no está configurado en este entorno: defina VENDGUARD_SMTP_HOST para habilitar el canal de correo.';

    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult
    {
        return TransportDeliveryResult::transientFailure(self::DIAGNOSTIC);
    }
}
