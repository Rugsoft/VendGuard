<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;

/**
 * NotificationTransportRegistry — Selector de transporte por canal (T-NOTIF-07).
 *
 * El despachador conoce UN contrato (`NotificationTransportInterface`) y no debe
 * saber si detrás hay SMTP nativo, HTTPS nativo o el adaptador determinista del
 * arnés: este registro traduce `NotificationChannel` a la implementación vigente
 * y permite inyectar transportes simulados en las pruebas sin tocar el servicio.
 *
 * `forRuntime()` es la composición de producción: el Webhook corporativo siempre
 * está disponible (no depende de credenciales de arranque) y el correo se monta
 * desde `VENDGUARD_SMTP_*`. Si el entorno no declara SMTP, lejos de reventar el
 * proceso batch se registra `UnconfiguredEmailTransport`: cada intento de correo
 * cae como fallo transitorio y queda visible en la bandeja de supervisión, que es
 * exactamente donde Coordinación debe descubrir que falta configurar el canal.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationTransportRegistry
{
    /** @var array<string, NotificationTransportInterface> */
    private array $transports = [];

    public function __construct(NotificationTransportInterface ...$transports)
    {
        foreach ($transports as $transport) {
            $this->transports[$transport->channel()->value] = $transport;
        }
    }

    /**
     * Composición de producción: Webhook nativo y correo nativo si el entorno lo
     * declara; en su ausencia, un transporte de diagnóstico que nunca entrega.
     */
    public static function forRuntime(): self
    {
        $transports = [new NativeHttpsWebhookTransport()];

        try {
            $transports[] = NativeSmtpEmailTransport::fromEnvironment();
        } catch (Throwable) {
            $transports[] = new UnconfiguredEmailTransport();
        }

        return new self(...$transports);
    }

    /**
     * Transporte registrado para el canal solicitado.
     *
     * @throws RuntimeException si el canal no tiene transporte registrado: un
     *   canal sin entrega es un fallo de composición, no un envío fallido.
     */
    public function forChannel(NotificationChannel $channel): NotificationTransportInterface
    {
        if (!isset($this->transports[$channel->value])) {
            throw new RuntimeException(sprintf(
                'No hay ningún transporte registrado para el canal %s.',
                $channel->value
            ));
        }

        return $this->transports[$channel->value];
    }

    public function has(NotificationChannel $channel): bool
    {
        return isset($this->transports[$channel->value]);
    }
}
