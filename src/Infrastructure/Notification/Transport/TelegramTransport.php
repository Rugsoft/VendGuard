<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use InvalidArgumentException;
use Throwable;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * TelegramTransport — Entrega personal al técnico por el bot de Telegram (Módulo 15, RF-TG-01).
 *
 * Es el tercer transporte del Centro de Notificaciones y el primero con un
 * destinatario **persona**: el `recipient` de la fila es el `chat_id` del
 * técnico, no una dirección de correo ni una URL.
 *
 * **Por qué un canal propio y no el Webhook corporativo.** El canal `WEBHOOK`
 * publica en una URL única configurada en base (el grupo de emergencias). Un
 * aviso personal necesita otro destino por cada envío, y además un botón: el
 * relé corporativo sólo compone texto. Un canal `TELEGRAM` de primera clase
 * deja ambas cosas donde corresponde y la bandeja no miente sobre el canal con
 * el que se entregó.
 *
 * **Host constante, sin SSRF.** La URL del API es una constante del código
 * —`https://api.telegram.org`— y del destinatario sólo se acepta un
 * identificador numérico. No hay URL que un dato de la fila pueda desviar.
 *
 * **Timeout y clasificación (RNF-03).** Cinco segundos por intento; `2xx` es
 * éxito; `408`, `425`, `429` y `5xx` son transitorios (entran en el backoff
 * 1/5/15/60 del despachador); `400`, `401`, `403` (bot bloqueado, chat
 * inexistente) y el resto de `4xx` son permanentes, porque reintentarlos es
 * gastar intentos en un destino que no va a cambiar.
 *
 * **El token nunca se registra.** El diagnóstico depura cualquier
 * `https://api.telegram.org/bot…` antes de devolverlo, de modo que un error de
 * red no pueda dejar la credencial del bot en `notification_outbox.last_error`
 * ni en el registro de la plataforma.
 *
 * **Seam de prueba.** El constructor acepta un publicador HTTP inyectable; la
 * batería certifica la forma exacta del cuerpo y la clasificación sin tocar la
 * red. En producción se usa `stream_context_create` + `file_get_contents`, sin
 * cURL obligatorio ni dependencias externas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class TelegramTransport implements NotificationTransportInterface
{
    /** Origen del API: constante del código, jamás un dato de la fila. */
    public const API_ORIGIN = 'https://api.telegram.org';

    /** Límite de longitud de un mensaje en Telegram. */
    public const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Variable de entorno del token del bot. La resolución de valor (incluido el
     * alias `TG_BOT_TOKEN` del relé) vive en `SecretProvider`; esta constante
     * existe para que la documentación y los mensajes de remedio nombren la misma.
     */
    public const ENV_BOT_TOKEN = SecretProvider::ENV_TELEGRAM_BOT_TOKEN;

    private const USER_AGENT = 'VendGuard-NotificationDispatcher/1.0';

    /** Identificador de Telegram: usuario (positivo) o grupo/supergrupo (negativo). */
    private const CHAT_ID_PATTERN = '/^-?[0-9]{5,20}$/';

    private ?string $botToken;

    /** @var (callable(string, string, array<string, string>, int): array{status: int, body: string})|null */
    private $httpPost;

    private int $timeoutSeconds;

    /**
     * @param string|null $botToken Token del bot; `null` deja el canal sin configurar (no es fatal).
     * @param (callable(string, string, array<string, string>, int): array{status: int, body: string})|null $httpPost
     */
    public function __construct(
        ?string $botToken = null,
        ?callable $httpPost = null,
        int $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'El timeout del canal Telegram debe estar entre 1 y %d segundos (RNF-03).',
                self::TIMEOUT_SECONDS
            ));
        }

        $token = $botToken !== null ? trim($botToken) : null;
        $this->botToken = $token !== null && $token !== '' ? $token : null;
        $this->httpPost = $httpPost;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Transporte compuesto desde el entorno del proceso.
     *
     * Sin token el objeto sigue existiendo —igual que `UnconfiguredEmailTransport`—
     * para que una fila ya encolada no convierta la pasada en una excepción de
     * composición: el fallo se registra como transitorio y queda visible.
     */
    public static function fromEnvironment(?callable $httpPost = null): self
    {
        return new self(SecretProvider::telegramBotToken(), $httpPost);
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::TELEGRAM;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== null;
    }

    /**
     * ¿Es un identificador de chat aceptable? Se usa también al dar de alta la
     * vinculación, para que la regla viva en un solo sitio.
     */
    public static function isValidChatId(?string $chatId): bool
    {
        return $chatId !== null && preg_match(self::CHAT_ID_PATTERN, trim($chatId)) === 1;
    }

    /**
     * Cuerpo canónico de `sendMessage`.
     *
     * Se expone público para certificar la forma exacta de la petición sin red:
     * chat, texto recortado y, cuando la plantilla tiene destino, el teclado en
     * línea con el botón de la avería.
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(string $recipient, RenderedNotificationMessage $message): array
    {
        $text = self::composeText($message);

        $payload = [
            'chat_id' => trim($recipient),
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($message->ctaUrl !== null && $message->ctaLabel !== null) {
            $payload['reply_markup'] = [
                'inline_keyboard' => [[
                    [
                        'text' => $message->ctaLabel,
                        'url' => $message->ctaUrl,
                    ],
                ]],
            ];
        }

        return $payload;
    }

    /**
     * Texto del mensaje: asunto, separación y cuerpo de texto del aviso.
     *
     * El cuerpo ya contiene la URL del botón para los avisos que la tienen
     * (RF-TG-04): si un cliente no pinta el teclado en línea, el enlace sigue
     * siendo utilizable.
     */
    public static function composeText(RenderedNotificationMessage $message): string
    {
        $text = trim($message->subject . "\n\n" . $message->textBody);

        if (mb_strlen($text) <= self::MAX_MESSAGE_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - 1) . '…';
    }

    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult
    {
        $recipient = trim($recipient);

        if (!self::isValidChatId($recipient)) {
            return TransportDeliveryResult::permanentFailure(
                'Destino de Telegram rechazado: el chat_id no tiene formato de identificador de Telegram.'
            );
        }

        if ($this->botToken === null) {
            return TransportDeliveryResult::transientFailure(
                'El canal Telegram no está configurado: falta la variable de entorno VENDGUARD_TELEGRAM_BOT_TOKEN.'
            );
        }

        try {
            $payload = json_encode(
                self::buildPayload($recipient, $message),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            return TransportDeliveryResult::permanentFailure(
                'No se pudo serializar el mensaje de Telegram: ' . $exception->getMessage()
            );
        }

        $endpoint = self::API_ORIGIN . '/bot' . $this->botToken . '/sendMessage';
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'Content-Length: ' . strlen($payload),
            'Connection: close',
        ];

        try {
            $response = $this->httpPost !== null
                ? ($this->httpPost)($endpoint, $payload, $headers, $this->timeoutSeconds)
                : self::postOverHttps($endpoint, $payload, $headers, $this->timeoutSeconds);
        } catch (Throwable $exception) {
            return TransportDeliveryResult::transientFailure(
                'No se pudo completar la petición HTTPS a Telegram: ' . self::redact($exception->getMessage())
            );
        }

        $statusCode = (int)($response['status'] ?? 0);
        if ($statusCode === 0) {
            return TransportDeliveryResult::transientFailure(
                'Telegram no devolvió una línea de estado HTTP válida.'
            );
        }

        $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($response['body'] ?? '')) ?? ''), 0, 200);
        $snippet = self::redact($snippet);

        if ($statusCode >= 200 && $statusCode < 300) {
            return TransportDeliveryResult::success($statusCode);
        }

        $error = sprintf(
            'Telegram respondió con HTTP %d%s.',
            $statusCode,
            $snippet !== '' ? ': ' . $snippet : ''
        );

        $transient = $statusCode === 408 || $statusCode === 425 || $statusCode === 429
            || ($statusCode >= 500 && $statusCode < 600);

        return $transient
            ? TransportDeliveryResult::transientFailure($error, $statusCode)
            : TransportDeliveryResult::permanentFailure($error, $statusCode);
    }

    /**
     * Publicación nativa sobre HTTPS, sin cURL obligatorio.
     *
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private static function postOverHttps(string $endpoint, string $payload, array $headers, int $timeoutSeconds): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $timeoutSeconds,
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

        $body = @file_get_contents($endpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];

        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }

        if ($body === false && $responseHeaders === []) {
            $error = error_get_last();
            $message = is_array($error) && isset($error['message']) ? (string)$error['message'] : 'error de red';

            throw new \RuntimeException($message);
        }

        return ['status' => $status, 'body' => (string)$body];
    }

    /**
     * Elimina cualquier rastro del token de un texto destinado a diagnóstico.
     */
    public static function redact(string $text): string
    {
        $redacted = preg_replace('#https://api\.telegram\.org/bot[^/\s"\']+/#i', 'https://api.telegram.org/bot[token]/', $text);
        $redacted = preg_replace('#\b\d{6,}:[A-Za-z0-9_-]{20,}\b#', '[token]', (string)$redacted);

        return mb_substr(trim((string)$redacted), 0, 300);
    }
}
