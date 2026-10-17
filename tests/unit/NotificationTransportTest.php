<?php

declare(strict_types=1);

/**
 * NotificationTransportTest
 *
 * Suite unitaria de los transportes nativos del Centro de Notificaciones (T-NOTIF-05):
 *   1. `TransportDeliveryResult`: éxito, fallo transitorio y fallo permanente.
 *   2. Timeout estricto de 5 segundos (RNF-03) y rechazo de configuraciones inválidas.
 *   3. Clasificación de respuestas SMTP (2xx/4xx/5xx) y de estados HTTP del Webhook.
 *   4. Preflight de destinatarios: correo inválido, inyección de cabeceras y URL anti-SSRF.
 *   5. Entrega SMTP real contra un servidor falso en loopback (sin red externa):
 *      verifica saludo, EHLO, MAIL FROM, RCPT TO, DATA y el mensaje MIME transmitido.
 *   6. `InMemoryNotificationTransport`: adaptador determinista inyectable, sin red ni reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro; el único socket es el servidor SMTP falso efímero
 * levantado por la propia suite en 127.0.0.1 (arnés hermético, sin proveedores externos).
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;
use VendGuard\Infrastructure\Notification\Transport\InMemoryNotificationTransport;
use VendGuard\Infrastructure\Notification\Transport\NativeHttpsWebhookTransport;
use VendGuard\Infrastructure\Notification\Transport\NativeSmtpEmailTransport;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportInterface;
use VendGuard\Infrastructure\Notification\Transport\TransportDeliveryResult;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - NotificationTransportTest (T-NOTIF-05)\n";
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

$assertThrows = function (string $caseTitle, callable $operation) use ($assert): void {
    try {
        $operation();
        $assert($caseTitle, false, 'No se lanzó la excepción esperada.');
    } catch (InvalidArgumentException $exception) {
        $assert($caseTitle, true);
    } catch (Throwable $exception) {
        $assert($caseTitle, false, 'Excepción inesperada: ' . $exception::class);
    }
};

// ---------------------------------------------------------------------
// 1. Resultado tipado de entrega
// ---------------------------------------------------------------------
echo "--- 1. TransportDeliveryResult (éxito, transitorio y permanente) ---\n";

$success = TransportDeliveryResult::success(250);
$transient = TransportDeliveryResult::transientFailure("  Error   de\nred  ", 421);
$permanent = TransportDeliveryResult::permanentFailure('Buzón inexistente', 550);

$assert('Éxito: outcome canónico SUCCESS', $success->outcome === TransportDeliveryResult::OUTCOME_SUCCESS);
$assert('Éxito: isSuccess() verdadero', $success->isSuccess());
$assert('Éxito: no es reintentable', !$success->isRetryable());
$assert('Éxito: conserva el código del proveedor', $success->providerStatusCode === 250);
$assert('Éxito: sin mensaje de error', $success->errorMessage === null);

$assert('Transitorio: outcome canónico TRANSIENT_FAILURE', $transient->isTransientFailure());
$assert('Transitorio: es reintentable', $transient->isRetryable());
$assert('Transitorio: conserva el código del proveedor', $transient->providerStatusCode === 421);
$assert(
    'Transitorio: normaliza espacios y saltos de línea',
    $transient->errorMessage === 'Error de red',
    (string)$transient->errorMessage
);

$assert('Permanente: outcome canónico PERMANENT_FAILURE', $permanent->isPermanentFailure());
$assert('Permanente: no es reintentable', !$permanent->isRetryable());
$assert('Permanente: conserva el diagnóstico íntegro', $permanent->errorMessage === 'Buzón inexistente');

$assert('Los tres outcomes son distintos', count(array_unique([
    $success->outcome,
    $transient->outcome,
    $permanent->outcome,
])) === 3);

$fromOutcome = TransportDeliveryResult::fromOutcome('transient_failure', 'x', 503);
$assert('fromOutcome reconstruye un transitorio', $fromOutcome->isTransientFailure() && $fromOutcome->providerStatusCode === 503);
$assert(
    'El diagnóstico se acota a 500 caracteres',
    mb_strlen(TransportDeliveryResult::transientFailure(str_repeat('a', 900))->errorMessage) === TransportDeliveryResult::MAX_ERROR_LENGTH
);
$assert(
    'Un diagnóstico vacío conserva un mensaje institucional',
    TransportDeliveryResult::permanentFailure('   ')->errorMessage === 'Fallo de entrega sin diagnóstico del proveedor.'
);
$assertThrows('fromOutcome rechaza un outcome desconocido', static fn () => TransportDeliveryResult::fromOutcome('MAYBE'));

$array = $transient->toArray();
$assert('toArray expone las tres claves de auditoría', array_keys($array) === ['outcome', 'error_message', 'provider_status_code']);

// ---------------------------------------------------------------------
// 2. Timeout estricto de 5 segundos (RNF-03)
// ---------------------------------------------------------------------
echo "--- 2. Timeout estricto de 5 segundos (RNF-03) ---\n";

$assert('El contrato declara TIMEOUT_SECONDS = 5', NotificationTransportInterface::TIMEOUT_SECONDS === 5);
$assert('SMTP declara el timeout del contrato', NativeSmtpEmailTransport::TIMEOUT_SECONDS === 5);
$assert('Webhook declara el timeout del contrato', NativeHttpsWebhookTransport::TIMEOUT_SECONDS === 5);

$smtpDefaults = new NativeSmtpEmailTransport(
    host: 'smtp.example.com',
    port: 587,
    encryption: 'tls',
    username: 'user',
    password: 'secret',
    fromAddress: 'no-reply@vendguard.test'
);
$assert('El transporte SMTP usa 5 s por defecto', $smtpDefaults->timeoutSeconds() === 5);

$assertThrows(
    'SMTP rechaza un timeout superior a 5 s',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 587, 'tls', '', '', 'no-reply@vendguard.test', 'VendGuard', 6)
);
$assertThrows(
    'SMTP rechaza un timeout de cero',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 587, 'tls', '', '', 'no-reply@vendguard.test', 'VendGuard', 0)
);
$assertThrows(
    'Webhook rechaza un timeout superior a 5 s',
    static fn () => new NativeHttpsWebhookTransport(6)
);
$assertThrows(
    'SMTP exige host',
    static fn () => new NativeSmtpEmailTransport('', 587, 'tls', '', '', 'no-reply@vendguard.test')
);
$assertThrows(
    'SMTP exige puerto válido',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 70000, 'tls', '', '', 'no-reply@vendguard.test')
);
$assertThrows(
    'SMTP exige cifrado canónico',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 587, 'plain', '', '', 'no-reply@vendguard.test')
);
$assertThrows(
    'SMTP exige remitente válido',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 587, 'tls', '', '', 'no-reply')
);
$assertThrows(
    'SMTP exige contraseña cuando hay usuario',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 587, 'tls', 'user', '', 'no-reply@vendguard.test')
);
$assertThrows(
    'SMTP prohíbe credenciales sin cifrar fuera de loopback',
    static fn () => new NativeSmtpEmailTransport('smtp.example.com', 25, 'none', 'user', 'secret', 'no-reply@vendguard.test')
);

// ---------------------------------------------------------------------
// 3. Clasificación de respuestas
// ---------------------------------------------------------------------
echo "--- 3. Clasificación SMTP y HTTP ---\n";

$smtpExpectations = [
    220 => TransportDeliveryResult::OUTCOME_SUCCESS,
    250 => TransportDeliveryResult::OUTCOME_SUCCESS,
    251 => TransportDeliveryResult::OUTCOME_SUCCESS,
    354 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    421 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    450 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    451 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    452 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    454 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    500 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    535 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    550 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    551 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    552 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    553 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    554 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    0 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
];

foreach ($smtpExpectations as $code => $expected) {
    $assert(
        'SMTP ' . $code . ' → ' . $expected,
        NativeSmtpEmailTransport::classifyResponseCode($code) === $expected
    );
}

$httpExpectations = [
    200 => TransportDeliveryResult::OUTCOME_SUCCESS,
    202 => TransportDeliveryResult::OUTCOME_SUCCESS,
    204 => TransportDeliveryResult::OUTCOME_SUCCESS,
    301 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    400 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    401 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    403 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    404 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    408 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    422 => TransportDeliveryResult::OUTCOME_PERMANENT_FAILURE,
    425 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    429 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    500 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    502 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
    503 => TransportDeliveryResult::OUTCOME_TRANSIENT_FAILURE,
];

foreach ($httpExpectations as $code => $expected) {
    $assert(
        'HTTP ' . $code . ' → ' . $expected,
        NativeHttpsWebhookTransport::classifyStatusCode($code) === $expected
    );
}

// ---------------------------------------------------------------------
// 4. Preflight sin red: destinatarios y anti-SSRF
// ---------------------------------------------------------------------
echo "--- 4. Preflight de destinatarios y validación anti-SSRF ---\n";

$message = new RenderedNotificationMessage(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    'Asunto de prueba con acentos',
    "Cuerpo de texto\nSegunda línea",
    '<p>Cuerpo HTML</p>'
);

$webhook = new NativeHttpsWebhookTransport();

$plainHttp = $webhook->deliver('http://webhook.example.com/alerts', $message);
$assert('Webhook rechaza http:// como fallo permanente', $plainHttp->isPermanentFailure());

$localhost = $webhook->deliver('https://localhost/alerts', $message);
$assert('Webhook rechaza localhost como fallo permanente', $localhost->isPermanentFailure());

$privateIp = $webhook->deliver('https://10.0.0.5/alerts', $message);
$assert('Webhook rechaza IP privada como fallo permanente', $privateIp->isPermanentFailure());

$metadata = $webhook->deliver('https://169.254.169.254/latest/meta-data', $message);
$assert('Webhook rechaza metadata de nube como fallo permanente', $metadata->isPermanentFailure());

$assert(
    'El diagnóstico anti-SSRF identifica la política',
    str_contains((string)$plainHttp->errorMessage, 'anti-SSRF'),
    (string)$plainHttp->errorMessage
);

$smtp = new NativeSmtpEmailTransport(
    host: '127.0.0.1',
    port: 2525,
    encryption: 'none',
    username: '',
    password: '',
    fromAddress: 'no-reply@vendguard.test'
);

$invalidRecipient = $smtp->deliver('no-es-un-correo', $message);
$assert('SMTP rechaza un destinatario inválido sin abrir socket', $invalidRecipient->isPermanentFailure());

$headerInjection = $smtp->deliver("destino@example.com\r\nBcc: intruso@example.com", $message);
$assert('SMTP neutraliza la inyección de cabeceras', $headerInjection->isPermanentFailure());

$webhookPayload = NativeHttpsWebhookTransport::buildPayload($message);
$assert('El JSON del Webhook declara el evento', $webhookPayload['event'] === 'INCIDENT_OPENED_SITE');
$assert('El JSON del Webhook declara la prioridad', $webhookPayload['priority'] === 'STANDARD');
$assert('El JSON del Webhook transporta el asunto renderizado', $webhookPayload['subject'] === $message->subject);
$assert('El JSON del Webhook no inventa claves fuera del contrato', array_keys($webhookPayload) === ['source', 'event', 'priority', 'subject', 'text', 'html']);

// ---------------------------------------------------------------------
// 5. Entrega SMTP real contra un servidor falso en loopback
// ---------------------------------------------------------------------
echo "--- 5. Entrega SMTP nativa contra servidor falso en loopback ---\n";

$probe = @stream_socket_server('tcp://127.0.0.1:0', $probeErrno, $probeErrstr);
$port = 0;
if (is_resource($probe)) {
    $probeName = (string)stream_socket_get_name($probe, false);
    $port = (int)substr($probeName, (int)strrpos($probeName, ':') + 1);
    fclose($probe);
}

$tempFiles = [];
$makeTemp = static function (string $prefix, string $suffix = '') use (&$tempFiles): string {
    $base = (string)tempnam(sys_get_temp_dir(), $prefix);
    $path = $base . $suffix;
    if ($suffix !== '') {
        @rename($base, $path);
    }
    $tempFiles[] = $path;

    return $path;
};

$smtpProcess = null;

if ($port <= 0) {
    $assert('No se pudo reservar un puerto loopback para el servidor SMTP falso', false);
} else {
    $serverScript = $makeTemp('vg_fake_smtp_', '.php');
    $capturePath = $makeTemp('vg_fake_smtp_capture_');
    $readyPath = $makeTemp('vg_fake_smtp_ready_');
    $stdoutPath = $makeTemp('vg_fake_smtp_stdout_');
    $stderrPath = $makeTemp('vg_fake_smtp_stderr_');

    $serverCode = <<<'PHP'
<?php
declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$capturePath = (string)($argv[2] ?? '');
$readyPath = (string)($argv[3] ?? '');

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!is_resource($server)) {
    fwrite(STDERR, 'BIND_FAILED: ' . $errstr . ' (' . $errno . ')');
    exit(1);
}

file_put_contents($readyPath, 'ready');

$connection = @stream_socket_accept($server, 10);
if (!is_resource($connection)) {
    fwrite(STDERR, 'ACCEPT_TIMEOUT');
    fclose($server);
    exit(2);
}

fwrite($connection, "220 localhost VendGuardTest ESMTP\r\n");

$captured = '';
$insideData = false;

while (($line = fgets($connection)) !== false) {
    $line = rtrim($line, "\r\n");

    if ($insideData) {
        if ($line === '.') {
            $insideData = false;
            fwrite($connection, "250 2.0.0 Ok: queued as VGTEST1\r\n");
            continue;
        }
        $captured .= $line . "\n";
        continue;
    }

    $upper = strtoupper($line);

    if (str_starts_with($upper, 'EHLO')) {
        fwrite($connection, "250-localhost\r\n250-SIZE 10485760\r\n250 8BITMIME\r\n");
    } elseif (str_starts_with($upper, 'MAIL FROM')) {
        fwrite($connection, "250 2.1.0 Ok\r\n");
    } elseif (str_starts_with($upper, 'RCPT TO')) {
        fwrite($connection, "250 2.1.5 Ok\r\n");
    } elseif ($upper === 'DATA') {
        $insideData = true;
        fwrite($connection, "354 End data with <CR><LF>.<CR><LF>\r\n");
    } elseif ($upper === 'QUIT') {
        fwrite($connection, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($connection, "250 2.0.0 Ok\r\n");
    }
}

file_put_contents($capturePath, $captured);
fclose($connection);
fclose($server);
PHP;

    file_put_contents($serverScript, $serverCode);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];

    $smtpProcess = @proc_open(
        [PHP_BINARY, $serverScript, (string)$port, $capturePath, $readyPath],
        $descriptors,
        $pipes
    );

    if (is_resource($smtpProcess)) {
        fclose($pipes[0]);
    }

    $ready = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        if (is_file($readyPath) && trim((string)file_get_contents($readyPath)) === 'ready') {
            $ready = true;
            break;
        }
        usleep(50_000);
    }

    $assert('El servidor SMTP falso queda escuchando en loopback', $ready);

    if ($ready) {
        $transport = new NativeSmtpEmailTransport(
            host: '127.0.0.1',
            port: $port,
            encryption: 'none',
            username: '',
            password: '',
            fromAddress: 'no-reply@vendguard.test',
            fromName: 'VendGuard · Pruebas'
        );

        $result = $transport->deliver('destino@example.com', $message);

        $assert('La entrega SMTP real devuelve éxito', $result->isSuccess(), (string)$result->errorMessage);
        $assert('La entrega SMTP real confirma el código 250', $result->providerStatusCode === 250);
        $assert('El transporte declara el canal EMAIL', $transport->channel() === NotificationChannel::EMAIL);
    }

    if (is_resource($smtpProcess)) {
        $exitCode = proc_close($smtpProcess);
        $assert('El servidor SMTP falso termina limpiamente', $exitCode === 0, (string)@file_get_contents($stderrPath));
    }

    $captured = is_file($capturePath) ? (string)file_get_contents($capturePath) : '';

    $assert('El servidor recibió el destinatario RCPT TO', str_contains($captured, 'destino@example.com'));
    $assert('El servidor recibió un remitente MAIL FROM', str_contains($captured, 'no-reply@vendguard.test'));
    $assert('El mensaje se transmite como multipart/alternative', str_contains($captured, 'multipart/alternative'));
    $assert(
        'El asunto viaja codificado en UTF-8 (RFC 2047)',
        str_contains($captured, 'Subject: =?UTF-8?B?' . base64_encode($message->subject) . '?=')
    );
    $assert('El cuerpo de texto plano llega al servidor', str_contains($captured, 'Cuerpo de texto'));
    $assert('El cuerpo HTML llega al servidor', str_contains($captured, '<p>Cuerpo HTML</p>'));
    $assert('El mensaje incluye la cabecera MIME-Version', str_contains($captured, 'MIME-Version: 1.0'));
}

foreach (array_reverse($tempFiles) as $tempFile) {
    @unlink($tempFile);
}

// ---------------------------------------------------------------------
// 6. Adaptador hermético determinista
// ---------------------------------------------------------------------
echo "--- 6. InMemoryNotificationTransport (arnés determinista) ---\n";

$memory = new InMemoryNotificationTransport();
$assert('El adaptador hermético usa EMAIL por defecto', $memory->channel() === NotificationChannel::EMAIL);
$assert('El adaptador hermético arranca sin entregas', $memory->deliveryCount() === 0);

$memory->scriptOutcomes(
    'Tecnico@Example.com',
    TransportDeliveryResult::transientFailure('Caída temporal', 503),
    TransportDeliveryResult::success(250)
);

$first = $memory->deliver('tecnico@example.com', $message);
$second = $memory->deliver('TECNICO@example.com', $message);

$assert('Primera entrega programada: fallo transitorio', $first->isTransientFailure());
$assert('Segunda entrega programada: éxito', $second->isSuccess());
$assert('La secuencia se consume en orden y por destinatario canónico', $memory->deliveryCount() === 2);
$assert('El adaptador registra los destinatarios entregados', $memory->deliveredRecipients() === ['tecnico@example.com', 'TECNICO@example.com']);
$assert('El adaptador devuelve la última entrega', $memory->lastDelivery()['message']->subject === $message->subject);

$third = $memory->deliver('tecnico@example.com', $message);
$assert('Agotada la secuencia se usa el resultado por defecto', $third->isSuccess());

$memory->setDefaultOutcome(TransportDeliveryResult::permanentFailure('Destino inválido', 550));
$assert('setDefaultOutcome cambia el resultado no programado', $memory->deliver('otro@example.com', $message)->isPermanentFailure());

$memory->reset();
$assert('reset vacía entregas y secuencias', $memory->deliveryCount() === 0 && $memory->deliver('x@example.com', $message)->isSuccess());

$webhookMemory = new InMemoryNotificationTransport(NotificationChannel::WEBHOOK);
$assert('El adaptador hermético puede simular el canal WEBHOOK', $webhookMemory->channel() === NotificationChannel::WEBHOOK);

$assertThrows('El adaptador hermético rechaza un destinatario vacío', static fn () => $memory->scriptOutcomes('', TransportDeliveryResult::success()));

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
