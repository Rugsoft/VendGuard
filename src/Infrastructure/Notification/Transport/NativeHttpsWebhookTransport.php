<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use InvalidArgumentException;
use Throwable;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Service\WebhookUrlSecurityValidator;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * NativeHttpsWebhookTransport — Canal Corporativo de Emergencias en PHP puro (T-NOTIF-05).
 *
 * Publica el aviso como JSON sobre HTTPS usando `stream_context_create` y
 * `file_get_contents`, sin Guzzle ni cURL obligatorio. Cumple RF-06.5.2 y
 * RNF-03:
 *
 * 1. **Doble validación anti-SSRF:** antes de cada petición se vuelve a pasar la
 *    URL por `WebhookUrlSecurityValidator`. Aunque la configuración se validó al
 *    guardarse, el destinatario puede haber cambiado en la bandeja; el envío
 *    nunca debe convertirse en una petición a `localhost` o a rangos privados.
 * 2. **Timeout estricto de 5 segundos** en la conexión, TLS y lectura completa.
 * 3. **Sin redirecciones:** un `3xx` se considera fallo permanente. Seguir una
 *    redirección permitiría saltarse la validación anti-SSRF efectuada sobre la
 *    URL original.
 * 4. **Clasificación:** `2xx` éxito; `408`, `425`, `429` y `5xx` transitorios;
 *    el resto de `4xx` (destino malformado, no autorizado, no encontrado) y los
 *    `3xx` permanentes; error de red o TLS transitorio.
 *
 * El JSON enviado contiene únicamente el evento, la prioridad sanitaria, el
 * asunto y los cuerpos ya renderizados. Nunca incluye credenciales ni la URL de
 * destino, y el diagnóstico de error se depura para no filtrar la URL completa.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NativeHttpsWebhookTransport implements NotificationTransportInterface
{
    private const USER_AGENT = 'VendGuard-NotificationDispatcher/1.0';

    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'El timeout del Webhook debe estar entre 1 y %d segundos (RNF-03).',
                self::TIMEOUT_SECONDS
            ));
        }
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::WEBHOOK;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Entrega el mensaje al canal corporativo sin lanzar excepciones de red.
     */
    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult
    {
        $recipient = trim($recipient);

        try {
            WebhookUrlSecurityValidator::validate($recipient);
        } catch (InvalidArgumentException $exception) {
            return TransportDeliveryResult::permanentFailure(
                'Destino de Webhook rechazado por la política anti-SSRF: ' . $exception->getMessage()
            );
        }

        try {
            $payload = json_encode(
                self::buildPayload($message),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            return TransportDeliveryResult::permanentFailure(
                'No se pudo serializar el payload del Webhook: ' . $exception->getMessage()
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                    'User-Agent: ' . self::USER_AGENT,
                    'Content-Length: ' . strlen($payload),
                    'Connection: close',
                ]),
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $responseBody = @file_get_contents($recipient, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($responseBody === false && $responseHeaders === []) {
            return TransportDeliveryResult::transientFailure(
                'No se pudo completar la petición HTTPS al Webhook: ' . $this->lastTransportError()
            );
        }

        $statusCode = self::extractStatusCode($responseHeaders);
        if ($statusCode === null) {
            return TransportDeliveryResult::transientFailure(
                'El Webhook no devolvió una línea de estado HTTP válida.',
                null
            );
        }

        $outcome = self::classifyStatusCode($statusCode);
        $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$responseBody) ?? ''), 0, 200);

        if ($outcome === TransportDeliveryResult::OUTCOME_SUCCESS) {
            return TransportDeliveryResult::success($statusCode);
        }

        $message = sprintf(
            'El Webhook respondió con HTTP %d%s.',
            $statusCode,
            $snippet !== '' ? ': ' . $snippet : ''
        );

        return $outcome === TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE
            ? TransportDeliveryResult::permanentFailure($message, $statusCode)
            : TransportDeliveryResult::transientFailure($message, $statusCode);
    }

    /**
     * Cuerpo JSON canónico del Canal Corporativo de Emergencias.
     *
     * @return array{source: string, event: string, priority: string, subject: string, text: string, html: string}
     */
    public static function buildPayload(RenderedNotificationMessage $message): array
    {
        return [
            'source' => 'VendGuard',
            'event' => $message->template->value,
            'priority' => $message->template->defaultPriority()->value,
            'subject' => $message->subject,
            'text' => $message->textBody,
            'html' => $message->htmlBody,
        ];
    }

    /**
     * Clasifica un código HTTP en el resultado canónico.
     *
     * @return string Constante `TransportDeliveryResult::OUTCOME_*`.
     */
    public static function classifyStatusCode(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return TransportDeliveryResult::OUTCOME_SUCCESS;
        }

        if ($statusCode === 408 || $statusCode === 425 || $statusCode === 429) {
            return TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE;
        }

        if ($statusCode >= 500 && $statusCode < 600) {
            return TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE;
        }

        // 3xx (redirección no seguida) y 4xx (salvo las recuperables ya tratadas).
        return TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE;
    }

    /**
     * @param list<string> $headers
     */
    private static function extractStatusCode(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int)$matches[1];
            }
        }

        return null;
    }

    private function lastTransportError(): string
    {
        $error = error_get_last();
        $message = is_array($error) && isset($error['message']) ? (string)$error['message'] : 'error de red';

        // La URL de destino puede llevar un token en la query string: nunca se registra.
        $message = preg_replace('#https://\S+#i', '[destino]', $message) ?? $message;

        return mb_substr(trim($message), 0, 300);
    }
}
