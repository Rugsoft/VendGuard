<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use InvalidArgumentException;
use Throwable;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * NativeSmtpEmailTransport — Cliente SMTP nativo en PHP puro (T-NOTIF-05).
 *
 * Cumple de forma incondicional el Artículo IV.3 de la Constitución (aversión a
 * dependencias externas): habla SMTP directamente sobre `stream_socket_client`,
 * sin Composer, sin SwiftMailer y sin Symfony Mailer. Controla al milímetro el
 * *timeout* estricto de 5 segundos de RNF-03 y traduce cada respuesta del
 * servidor a `TransportDeliveryResult`:
 *
 *   - `2xx` → éxito (entrega confirmada tras `DATA`).
 *   - `4xx` → fallo transitorio (greylisting, límite de ritmo, buzón ocupado…).
 *   - `5xx` → fallo permanente (buzón inexistente, credenciales rechazadas,
 *     mensaje rechazado por política). Reintentar no aporta nada.
 *   - Sin respuesta / error de socket / TLS fallido → fallo transitorio.
 *
 * Soporta conexión directa con TLS implícito (`ssl`), STARTTLS (`tls`, puerto
 * 587) y texto plano (`none`, reservado a servidores locales de desarrollo).
 * Exige autenticación `AUTH LOGIN` sólo cuando se configura un usuario, y
 * prohíbe enviar credenciales por un canal sin cifrar salvo en loopback.
 *
 * **Variables de entorno** (`fromEnvironment()`):
 * `VENDGUARD_SMTP_HOST` (obligatoria), `VENDGUARD_SMTP_PORT` (def. 587),
 * `VENDGUARD_SMTP_ENCRYPTION` (`tls`|`ssl`|`none`), `VENDGUARD_SMTP_USERNAME`,
 * `VENDGUARD_SMTP_PASSWORD`, `VENDGUARD_SMTP_FROM_ADDRESS` (obligatoria) y
 * `VENDGUARD_SMTP_FROM_NAME` (def. «VendGuard · Centro de Operaciones»).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NativeSmtpEmailTransport implements NotificationTransportInterface
{
    private const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    private const BOUNDARY = '=_VendGuard_Notification_Boundary_2026';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName = 'VendGuard · Centro de Operaciones',
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
        if (trim($this->host) === '' || preg_match('/[\s\r\n]/', $this->host) === 1) {
            throw new InvalidArgumentException('El host SMTP no puede estar vacío ni contener espacios o saltos de línea.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('El puerto SMTP debe estar entre 1 y 65535.');
        }

        if (!in_array($this->encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Cifrado SMTP no válido: «%s». Use tls, ssl o none.',
                $this->encryption
            ));
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'El timeout SMTP debe estar entre 1 y %d segundos (RNF-03).',
                self::TIMEOUT_SECONDS
            ));
        }

        if (filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf(
                'La dirección remitente SMTP no es válida: «%s».',
                $this->fromAddress
            ));
        }

        if (preg_match('/[\r\n]/', $this->fromName) === 1 || preg_match('/[\r\n]/', $this->username) === 1) {
            throw new InvalidArgumentException('El nombre remitente y el usuario SMTP no pueden contener saltos de línea.');
        }

        if ($this->username !== '' && $this->password === '') {
            throw new InvalidArgumentException('El usuario SMTP declarado requiere contraseña.');
        }

        if ($this->username !== '' && $this->encryption === 'none' && !$this->isLoopbackHost($this->host)) {
            throw new InvalidArgumentException(
                'No se permite enviar credenciales SMTP por un canal sin cifrar fuera de loopback (RNF-03).'
            );
        }
    }

    /**
     * Construye el transporte desde el entorno del proceso.
     *
     * @throws InvalidArgumentException si falta la configuración mínima.
     */
    public static function fromEnvironment(): self
    {
        $host = self::env('VENDGUARD_SMTP_HOST');
        if ($host === null) {
            throw new InvalidArgumentException('Falta la variable de entorno VENDGUARD_SMTP_HOST.');
        }

        $port = (int)(self::env('VENDGUARD_SMTP_PORT') ?? '587');
        $encryption = strtolower(self::env('VENDGUARD_SMTP_ENCRYPTION') ?? ($port === 465 ? 'ssl' : 'tls'));

        $fromAddress = self::env('VENDGUARD_SMTP_FROM_ADDRESS');
        if ($fromAddress === null) {
            throw new InvalidArgumentException('Falta la variable de entorno VENDGUARD_SMTP_FROM_ADDRESS.');
        }

        return new self(
            host: $host,
            port: $port,
            encryption: $encryption,
            username: self::env('VENDGUARD_SMTP_USERNAME') ?? '',
            password: self::env('VENDGUARD_SMTP_PASSWORD') ?? '',
            fromAddress: $fromAddress,
            fromName: self::env('VENDGUARD_SMTP_FROM_NAME') ?? 'VendGuard · Centro de Operaciones',
        );
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Entrega un mensaje por SMTP sin lanzar excepciones de red.
     */
    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult
    {
        $recipient = trim($recipient);

        if (!$this->isDeliverableRecipient($recipient)) {
            return TransportDeliveryResult::permanentFailure(sprintf(
                'Dirección de correo de destino inválida o con caracteres de cabecera: «%s».',
                mb_substr($recipient, 0, 120)
            ));
        }

        $socket = null;

        try {
            $errno = 0;
            $errstr = '';
            $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
            $remote = $scheme . $this->host . ':' . $this->port;

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ]);

            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!is_resource($socket)) {
                return TransportDeliveryResult::transientFailure(sprintf(
                    'No se pudo conectar con el servidor SMTP %s:%d: %s (%d).',
                    $this->host,
                    $this->port,
                    $errstr !== '' ? $errstr : 'error de red',
                    $errno
                ));
            }

            stream_set_timeout($socket, $this->timeoutSeconds);

            $greeting = $this->readResponse($socket);
            if ($greeting['code'] !== 220) {
                return $this->failureFor($greeting, 'saludo inicial');
            }

            $ehlo = $this->command($socket, 'EHLO ' . $this->ehloHostname());
            if (!$this->isPositive($ehlo)) {
                return $this->failureFor($ehlo, 'EHLO');
            }

            if ($this->encryption === 'tls') {
                $startTls = $this->command($socket, 'STARTTLS');
                if ($startTls['code'] !== 220) {
                    return $this->failureFor($startTls, 'STARTTLS');
                }

                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    return TransportDeliveryResult::transientFailure(
                        'No se pudo establecer el canal TLS con el servidor SMTP (STARTTLS).'
                    );
                }

                $ehlo = $this->command($socket, 'EHLO ' . $this->ehloHostname());
                if (!$this->isPositive($ehlo)) {
                    return $this->failureFor($ehlo, 'EHLO tras STARTTLS');
                }
            }

            if ($this->username !== '') {
                $auth = $this->command($socket, 'AUTH LOGIN');
                if ($auth['code'] !== 334) {
                    return $this->failureFor($auth, 'AUTH LOGIN');
                }

                $user = $this->command($socket, base64_encode($this->username));
                if ($user['code'] !== 334) {
                    return $this->failureFor($user, 'usuario SMTP');
                }

                $password = $this->command($socket, base64_encode($this->password));
                if ($password['code'] !== 235) {
                    return $this->failureFor($password, 'contraseña SMTP');
                }
            }

            $mailFrom = $this->command($socket, sprintf('MAIL FROM:<%s>', $this->fromAddress));
            if (!$this->isPositive($mailFrom)) {
                return $this->failureFor($mailFrom, 'MAIL FROM');
            }

            $rcptTo = $this->command($socket, sprintf('RCPT TO:<%s>', $recipient));
            if ($rcptTo['code'] !== 250 && $rcptTo['code'] !== 251) {
                return $this->failureFor($rcptTo, 'RCPT TO');
            }

            $data = $this->command($socket, 'DATA');
            if ($data['code'] !== 354) {
                return $this->failureFor($data, 'DATA');
            }

            $mime = $this->buildMimeMessage($recipient, $message);
            $written = @fwrite($socket, $this->dotStuff($mime) . "\r\n.\r\n");
            if ($written === false) {
                return TransportDeliveryResult::transientFailure(
                    'La conexión SMTP se cerró antes de poder transmitir el mensaje.'
                );
            }
            @fflush($socket);

            $accepted = $this->readResponse($socket);
            if ($accepted['code'] !== 250) {
                return $this->failureFor($accepted, 'confirmación del mensaje');
            }

            // Despedida best-effort: no debe añadir una espera extra al intento.
            @fwrite($socket, "QUIT\r\n");

            return TransportDeliveryResult::success($accepted['code']);
        } catch (Throwable $exception) {
            return TransportDeliveryResult::transientFailure(
                'Fallo inesperado durante la entrega SMTP: ' . $exception->getMessage()
            );
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /**
     * Clasifica un código de respuesta SMTP en el resultado canónico.
     *
     * @return string Constante `TransportDeliveryResult::OUTCOME_*`.
     */
    public static function classifyResponseCode(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return TransportDeliveryResult::OUTCOME_SUCCESS;
        }

        if ($code >= 500 && $code < 600) {
            return TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE;
        }

        return TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE;
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    private function readResponse($socket): array
    {
        $lines = [];

        while (true) {
            $line = @fgets($socket, 2048);
            if ($line === false) {
                $lines[] = 'Sin respuesta del servidor SMTP (tiempo de espera agotado o conexión cerrada).';

                return ['code' => 0, 'lines' => $lines];
            }

            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                return ['code' => 0, 'lines' => $lines];
            }

            if ($matches[2] !== '-') {
                return ['code' => (int)$matches[1], 'lines' => $lines];
            }

            if (count($lines) >= 50) {
                return ['code' => 0, 'lines' => $lines];
            }
        }
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    private function command($socket, string $command): array
    {
        $written = @fwrite($socket, $command . "\r\n");
        if ($written === false) {
            return ['code' => 0, 'lines' => ['No se pudo escribir en la conexión SMTP.']];
        }

        @fflush($socket);

        return $this->readResponse($socket);
    }

    /**
     * @param array{code: int, lines: list<string>} $response
     */
    private function isPositive(array $response): bool
    {
        return $response['code'] >= 200 && $response['code'] < 300;
    }

    /**
     * @param array{code: int, lines: list<string>} $response
     */
    private function failureFor(array $response, string $stage): TransportDeliveryResult
    {
        $detail = trim(implode(' ', $response['lines']));
        $detail = $detail === '' ? 'sin diagnóstico del servidor' : mb_substr($detail, 0, 300);
        $message = sprintf('SMTP %s rechazado: %s (código %d).', $stage, $detail, $response['code']);

        return self::classifyResponseCode($response['code']) === TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE
            ? TransportDeliveryResult::permanentFailure($message, $response['code'] > 0 ? $response['code'] : null)
            : TransportDeliveryResult::transientFailure($message, $response['code'] > 0 ? $response['code'] : null);
    }

    private function buildMimeMessage(string $recipient, RenderedNotificationMessage $message): string
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($message->subject) . '?=';
        $from = $this->fromName !== ''
            ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromAddress)
            : $this->fromAddress;

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->host . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . self::BOUNDARY . '"',
        ];

        $body = '--' . self::BOUNDARY . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->normalizeNewlines($message->textBody) . "\r\n"
            . '--' . self::BOUNDARY . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->normalizeNewlines($message->htmlBody) . "\r\n"
            . '--' . self::BOUNDARY . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function normalizeNewlines(string $value): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
    }

    /**
     * Aplica dot-stuffing SMTP: toda línea que empiece por «.» recibe otro «.».
     */
    private function dotStuff(string $mime): string
    {
        $lines = preg_split("/\r\n/", $mime) ?: [];

        return implode("\r\n", array_map(
            static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line,
            $lines
        ));
    }

    private function isDeliverableRecipient(string $recipient): bool
    {
        if ($recipient === '' || strlen($recipient) > 254 || preg_match('/[\r\n]/', $recipient) === 1) {
            return false;
        }

        return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function ehloHostname(): string
    {
        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'vendguard.local';
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host));

        return in_array($normalized, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_starts_with($normalized, '127.');
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
