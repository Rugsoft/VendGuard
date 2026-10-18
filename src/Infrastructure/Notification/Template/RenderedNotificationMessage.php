<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Template;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;

/**
 * RenderedNotificationMessage — Mensaje final listo para entrega (T-NOTIF-05).
 *
 * Es la salida pura de `NotificationMessageRenderer`: asunto y cuerpos en
 * castellano, sin acceso a red ni a base de datos. Los transportes nativos lo
 * consumen tal cual (SMTP compone las partes `text/plain` y `text/html`; el
 * Webhook serializa su contenido a JSON).
 *
 * **Botón estructurado (Módulo 15).** `ctaLabel`/`ctaUrl` publican el destino del
 * botón como dato, no sólo incrustado en el HTML: Telegram necesita el par
 * (etiqueta, URL) para pintar su teclado en línea, y no puede extraerlo de un
 * cuerpo de correo. SMTP y Webhook los ignoran, de modo que el contrato existente
 * no cambia. Ambos son `null` cuando la plantilla no lleva enlace.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class RenderedNotificationMessage
{
    /** Límite de la columna `subject` de `notification_outbox`. */
    public const MAX_SUBJECT_LENGTH = 255;

    public function __construct(
        public readonly NotificationTemplate $template,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly string $htmlBody,
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
    ) {
        if (trim($this->subject) === '') {
            throw new InvalidArgumentException('El asunto de la notificación no puede estar vacío.');
        }

        if (mb_strlen($this->subject) > self::MAX_SUBJECT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El asunto supera el límite de %d caracteres de notification_outbox.subject.',
                self::MAX_SUBJECT_LENGTH
            ));
        }

        if (trim($this->textBody) === '' || trim($this->htmlBody) === '') {
            throw new InvalidArgumentException('El cuerpo de la notificación no puede estar vacío.');
        }
    }

    /**
     * Proyección de texto plano para logs de auditoría y diagnóstico.
     */
    public function textPreview(int $maxLength = 160): string
    {
        $singleLine = trim(preg_replace('/\s+/u', ' ', $this->textBody) ?? $this->textBody);

        return mb_substr($singleLine, 0, max(1, $maxLength));
    }
}
