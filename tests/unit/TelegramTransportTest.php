<?php

declare(strict_types=1);

/**
 * TelegramTransportTest
 *
 * Certifica el canal personal del técnico (Módulo 15, T-TG-01): forma exacta de la
 * petición, teclado en línea con el botón de la avería, clasificación de los códigos
 * de Telegram y que el token del bot nunca acabe en un diagnóstico.
 *
 * La suite no toca la red: el transporte acepta un publicador HTTP inyectable, y aquí
 * todos los casos usan un doble que captura la llamada.
 *
 * Dogma Vanilla: ejecutable con `php tests/unit/TelegramTransportTest.php`, sin librerías.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;
use VendGuard\Infrastructure\Notification\Transport\TransportDeliveryResult;
use VendGuard\Infrastructure\Notification\Transport\TelegramTransport;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - TelegramTransportTest\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$message = static fn (?string $ctaLabel = null, ?string $ctaUrl = null): RenderedNotificationMessage => new RenderedNotificationMessage(
    template: NotificationTemplate::TECHNICIAN_ASSIGNED,
    subject: '🔧 Nueva avería asignada · Ticket INC-2026-H7PU',
    textBody: "Se te ha asignado la avería INC-2026-H7PU en VEND-0102 (Sede SEDE-BCN-01).\nUrgencia: Media.",
    htmlBody: '<p>Se te ha asignado la avería INC-2026-H7PU.</p>',
    ctaLabel: $ctaLabel,
    ctaUrl: $ctaUrl,
);

/** Doble de publicación: captura la llamada y devuelve la respuesta programada. */
$makeSender = static function (array &$captured, int $status = 200, string $body = '{"ok":true}'): callable {
    return static function (string $endpoint, string $payload, array $headers, int $timeout) use (&$captured, $status, $body): array {
        $captured = [
            'endpoint' => $endpoint,
            'payload' => $payload,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        return ['status' => $status, 'body' => $body];
    };
};

echo "--- 1. Canal e identidad del destino ---\n\n";

$unconfigured = new TelegramTransport(null, null);
$assert('1.1 El transporte cubre el canal TELEGRAM', $unconfigured->channel() === NotificationChannel::TELEGRAM);
$assert('1.2 Sin token el canal no está configurado', $unconfigured->isConfigured() === false);
$assert(
    '1.3 Un chat válido es un identificador numérico, también negativo (grupo)',
    TelegramTransport::isValidChatId('123456789')
    && TelegramTransport::isValidChatId('-1001234567890')
    && !TelegramTransport::isValidChatId('jordi')
    && !TelegramTransport::isValidChatId('12')
    && TelegramTransport::isValidChatId(null) === false
);

$captured = [];
$unconfiguredResult = $unconfigured->deliver('123456789', $message());
$assert(
    '1.4 Sin token la entrega es un fallo transitorio que nombra el remedio',
    $unconfiguredResult->outcome === TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE
    && str_contains((string)$unconfiguredResult->errorMessage, TelegramTransport::ENV_BOT_TOKEN)
);

$transport = new TelegramTransport('123456:TEST-TOKEN-abcdefghijklmnopqrstu', $makeSender($captured));

echo "\n--- 2. Cuerpo de sendMessage ---\n\n";

$result = $transport->deliver('123456789', $message('Abrir la avería', 'https://vendguard.onrender.com/?tech=1&ticket=INC-2026-H7PU'));
$decoded = json_decode((string)($captured['payload'] ?? ''), true) ?: [];

$assert('2.1 La entrega se considera exitosa con 200', $result->outcome === TransportDeliveryResult::OUTCOME_SUCCESS);
$assert(
    '2.2 La petición va al API de Telegram con el token del bot y sendMessage',
    str_starts_with((string)$captured['endpoint'], TelegramTransport::API_ORIGIN . '/bot')
    && str_ends_with((string)$captured['endpoint'], '/sendMessage')
);
$assert('2.3 El destinatario es el chat_id de la fila', ($decoded['chat_id'] ?? null) === '123456789');
$assert(
    '2.4 El texto lleva asunto y cuerpo del aviso',
    str_contains((string)($decoded['text'] ?? ''), 'Nueva avería asignada')
    && str_contains((string)($decoded['text'] ?? ''), 'INC-2026-H7PU')
);
$assert('2.5 No se previsualizan enlaces en el mensaje', ($decoded['disable_web_page_preview'] ?? null) === true);
$assert(
    '2.6 El botón es un teclado en línea con la etiqueta y la URL de la avería',
    ($decoded['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'Abrir la avería'
    && ($decoded['reply_markup']['inline_keyboard'][0][0]['url'] ?? null)
        === 'https://vendguard.onrender.com/?tech=1&ticket=INC-2026-H7PU'
);
$assert('2.7 El timeout exigido al publicador es el de RNF-03', ($captured['timeout'] ?? 0) === TelegramTransport::TIMEOUT_SECONDS);

$capturedNoCta = [];
$transportNoCta = new TelegramTransport('123456:TEST-TOKEN-abcdefghijklmnopqrstu', $makeSender($capturedNoCta));
$transportNoCta->deliver('123456789', $message());
$decodedNoCta = json_decode((string)($capturedNoCta['payload'] ?? ''), true) ?: [];
$assert(
    '2.8 Sin destino no se inventa un botón',
    !array_key_exists('reply_markup', $decodedNoCta)
);

$longMessage = new RenderedNotificationMessage(
    template: NotificationTemplate::TECHNICIAN_ASSIGNED,
    subject: 'Aviso largo',
    textBody: str_repeat('x', 5000),
    htmlBody: '<p>x</p>'
);
$longPayload = TelegramTransport::buildPayload('123456789', $longMessage);
$assert(
    '2.9 Un mensaje que excede el límite de Telegram se recorta con elipsis',
    mb_strlen((string)$longPayload['text']) === TelegramTransport::MAX_MESSAGE_LENGTH
    && str_ends_with((string)$longPayload['text'], '…')
);

echo "\n--- 3. Clasificación de la respuesta ---\n\n";

$classify = static function (int $status) use ($transport, $makeSender, $message): string {
    $captured = [];
    $sender = $makeSender($captured, $status, '{"ok":false,"description":"x"}');

    return (new TelegramTransport('123456:TEST-TOKEN-abcdefghijklmnopqrstu', $sender))
        ->deliver('123456789', $message())
        ->outcome;
};

$assert('3.1 429 es transitorio: entra en el backoff', $classify(429) === TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE);
$assert('3.2 500 es transitorio', $classify(500) === TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE);
$assert(
    '3.3 403 es permanente: el bot está bloqueado o el chat no existe',
    $classify(403) === TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE
);
$assert('3.4 400 es permanente', $classify(400) === TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE);

echo "\n--- 4. Destino inválido y redacción del token ---\n\n";

$capturedInvalid = [];
$invalidTransport = new TelegramTransport('123456:TEST-TOKEN-abcdefghijklmnopqrstu', $makeSender($capturedInvalid));
$invalidResult = $invalidTransport->deliver('jordi@example.com', $message());
$assert(
    '4.1 Un destinatario que no es chat_id se rechaza sin llamar al API',
    $invalidResult->outcome === TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE
    && $capturedInvalid === []
);

$redacted = TelegramTransport::redact('error en https://api.telegram.org/bot123456:SECRETO-abc/ sendMessage');
$assert(
    '4.2 El token del bot no sobrevive a la depuración del diagnóstico',
    !str_contains($redacted, 'SECRETO')
    && str_contains($redacted, '[token]')
);

$throwingTransport = new TelegramTransport(
    '123456:TEST-TOKEN-abcdefghijklmnopqrstu',
    static function (): array {
        throw new RuntimeException('fallo de red en https://api.telegram.org/bot123456:MUY-SECRETO/sendMessage');
    }
);
$throwingResult = $throwingTransport->deliver('123456789', $message());
$assert(
    '4.3 Una excepción de red se traduce en fallo transitorio sin filtrar el token',
    $throwingResult->outcome === TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE
    && !str_contains((string)$throwingResult->errorMessage, 'MUY-SECRETO')
);

echo "\n--- 5. Lectura del token desde el entorno ---\n\n";

$previousToken = getenv(TelegramTransport::ENV_BOT_TOKEN);
$previousAlias = getenv('TG_BOT_TOKEN');

putenv(TelegramTransport::ENV_BOT_TOKEN . '=111222:DESDE-ENTORNO');
$assert('5.1 El token se lee de VENDGUARD_TELEGRAM_BOT_TOKEN', TelegramTransport::fromEnvironment()->isConfigured());

putenv(TelegramTransport::ENV_BOT_TOKEN);
putenv('TG_BOT_TOKEN=111222:DESDE-ALIAS');
$assert('5.2 El alias del relé (TG_BOT_TOKEN) también sirve', TelegramTransport::fromEnvironment()->isConfigured());

putenv('TG_BOT_TOKEN');
$assert('5.3 Sin ninguna de las dos, el canal queda sin configurar', !TelegramTransport::fromEnvironment()->isConfigured());

putenv(TelegramTransport::ENV_BOT_TOKEN . '=' . ($previousToken !== false ? $previousToken : ''));
if ($previousToken === false) {
    putenv(TelegramTransport::ENV_BOT_TOKEN);
}
if ($previousAlias !== false) {
    putenv('TG_BOT_TOKEN=' . $previousAlias);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Pasadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: VERDE. El aviso personal viaja con su botón y sin filtrar el token.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLIDO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
