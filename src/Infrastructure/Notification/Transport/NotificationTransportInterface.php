<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * NotificationTransportInterface — Contrato de entrega hacia un proveedor externo (T-NOTIF-05).
 *
 * El despachador en segundo plano (T-NOTIF-07) sólo conoce este contrato: recibe
 * un mensaje ya renderizado y un destinatario, y obtiene un
 * `TransportDeliveryResult` que distingue éxito, fallo transitorio y fallo
 * permanente. Ninguna implementación puede lanzar excepciones de red hacia el
 * llamador: los errores se traducen siempre a un resultado tipado para que la
 * bandeja de salida conserve el diagnóstico y aplique su política de reintentos.
 *
 * **Timeout estricto (`RNF-03`).** Cada intento de conexión —saludo SMTP,
 * STARTTLS, autenticación, DATA, o la petición HTTPS completa— queda acotado a
 * `TIMEOUT_SECONDS` (5 segundos). Un proveedor lento no puede bloquear el resto
 * de la cola. Las implementaciones nativas no aceptan un valor superior.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
interface NotificationTransportInterface
{
    /** Límite máximo de espera por envío (RNF-03): 5 segundos. */
    public const TIMEOUT_SECONDS = 5;

    /**
     * Canal que cubre esta implementación (`EMAIL` o `WEBHOOK`).
     */
    public function channel(): NotificationChannel;

    /**
     * Intenta entregar un mensaje ya renderizado a un destinatario.
     *
     * @param string $recipient Dirección de correo o URL HTTPS del Webhook.
     * @param RenderedNotificationMessage $message Asunto y cuerpos en castellano.
     * @return TransportDeliveryResult Resultado tipado (nunca lanza por fallo de red).
     */
    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult;
}
