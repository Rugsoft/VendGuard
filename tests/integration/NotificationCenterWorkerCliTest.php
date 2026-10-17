<?php

declare(strict_types=1);

/**
 * NotificationCenterWorkerCliTest — Certificación del «Hecho cuando» de T-NOTIF-09.
 *
 * Valida las dos condiciones que la tarea declara:
 *
 *  1. `bin/send_notifications.php` ejecuta `NotificationDispatcherService::runPass()`
 *     desde línea de comandos, acepta `--batch-size` opcional e imprime el resumen
 *     JSON con código de salida `0`.
 *  2. `AppRouter.php` registra las nueve rutas nuevas (ocho de Coordinación y la de
 *     cron) bajo `$coordinatorAuth` / `$cronAuth` sin romper ninguna preexistente.
 *
 * El worker se ejecuta como **proceso hijo real** (`PHP_BINARY` + `proc_open`), no
 * por invocación de su código: lo que se certifica es lo que el planificador de
 * sistema ejecuta, incluido el código de salida y la pureza del JSON en stdout.
 * El contrato de conexión del banco se hereda del arnés (`tests/bootstrap.php`
 * declara el entorno con `putenv`), igual que en producción lo hereda del
 * servicio que lanza el worker.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationCenterWorkerCliTest (T-NOTIF-09)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$outbox = new PdoNotificationOutboxRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert('0. Usuarios semilla disponibles', $coordinator !== null && $technician !== null);

if ($coordinator === null || $technician === null) {
    echo "ERROR: no se pudo preparar el escenario base.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);

$workerPath = dirname(__DIR__, 2) . '/bin/send_notifications.php';
$assert('0.1 El worker CLI existe en bin/send_notifications.php', is_file($workerPath), $workerPath);

/**
 * Ejecuta el worker como proceso hijo real y devuelve salida y código de salida.
 *
 * @param list<string> $arguments
 * @return array{exit: int, stdout: string, stderr: string}
 */
$runWorker = function (array $arguments) use ($workerPath): array {
    $token = bin2hex(random_bytes(8));
    $stdoutPath = sys_get_temp_dir() . "/vendguard_notif_worker_{$token}.out";
    $stderrPath = sys_get_temp_dir() . "/vendguard_notif_worker_{$token}.err";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];

    $process = @proc_open(
        array_merge([PHP_BINARY, $workerPath], $arguments),
        $descriptors,
        $pipes,
        dirname($workerPath, 1)
    );

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'No se pudo lanzar el proceso del worker.'];
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);

    $stdout = (string)@file_get_contents($stdoutPath);
    $stderr = (string)@file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    return ['exit' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
};

$decodeWorker = static function (string $stdout): ?array {
    $decoded = json_decode($stdout, true);

    return is_array($decoded) ? $decoded : null;
};

echo "--- 1. Worker CLI: contrato de ejecución y salida (RF-01.2, RF-01.3) ---\n";

// Preparación: una fila atrapada en tránsito y una pendiente, para certificar que
// la pasada hace algo más que arrancar y devolver ceros.
$enqueue = function (string $recipient) use ($outbox): NotificationOutboxItem {
    $item = $outbox->enqueue(new NotificationOutboxItem(
        id: null,
        incidentId: null,
        channel: NotificationChannel::EMAIL,
        priority: NotificationPriority::STANDARD,
        template: NotificationTemplate::INCIDENT_OPENED_SITE,
        recipient: $recipient,
        recipientRole: 'LOCATION',
        recipientUserId: null,
        recipientLocationId: null,
        subject: 'Certificación T-NOTIF-09 · ' . $recipient,
        payload: ['ticket_code' => 'TICK-NOTIF09']
    ));

    if ($item === null || $item->getId() === null) {
        throw new RuntimeException('No se pudo encolar la notificación de certificación.');
    }

    return $item;
};

$stuckItem = $enqueue('atrapada.tnotif09@sede.vendguard.es');
$stuckItem->markProcessing((new DateTimeImmutable('now'))->modify('-10 minutes'));
$outbox->save($stuckItem);

$pendingItem = $enqueue('pendiente.tnotif09@sede.vendguard.es');

$defaultRun = $runWorker([]);
$defaultBody = $decodeWorker($defaultRun['stdout']);
$assert(
    '1.1 Sin argumentos el worker termina con código de salida 0',
    $defaultRun['exit'] === 0,
    "Exit: {$defaultRun['exit']} · stderr: " . trim($defaultRun['stderr'])
);
$assert(
    '1.2 La salida estándar es JSON puro (sin banners que rompan la canalización)',
    $defaultBody !== null && ($defaultBody['success'] ?? false) === true,
    'Salida: ' . mb_substr(trim($defaultRun['stdout']), 0, 200)
);
$assert(
    '1.3 El resumen publica exactamente las nueve claves del DTO',
    array_keys($defaultBody['data'] ?? []) === [
        'rescued_stuck_count',
        'sla_2h_alerts_enqueued',
        'sla_3h_alerts_enqueued',
        'claimed_count',
        'sent_count',
        'retry_scheduled_count',
        'failed_permanent_count',
        'cancelled_obsolete_count',
        'duration_ms',
    ],
    'Claves: ' . implode(', ', array_keys($defaultBody['data'] ?? []))
);
$assert(
    '1.4 La pasada rescata la fila atrapada en PROCESSING más de 5 minutos',
    ($defaultBody['data']['rescued_stuck_count'] ?? 0) === 1,
    'rescued_stuck_count: ' . (string)($defaultBody['data']['rescued_stuck_count'] ?? 0)
);
$processedPendingStatus = (string)$pdo->query(
    'SELECT status FROM notification_outbox WHERE id = ' . (int)$pendingItem->getId()
)->fetchColumn();
$assert(
    '1.5 La pasada reclama y procesa la fila pendiente sin dejarla en tránsito',
    ($defaultBody['data']['claimed_count'] ?? 0) >= 1
        && $processedPendingStatus !== 'PENDING'
        && $processedPendingStatus !== 'PROCESSING'
        && (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE status = 'PROCESSING'")->fetchColumn() === 0,
    'claimed_count: ' . (string)($defaultBody['data']['claimed_count'] ?? 0)
        . ' · estado de la pendiente: ' . $processedPendingStatus
);
$assert(
    '1.6 Sin SMTP configurado el envío cae a PENDING_RETRY con su diagnóstico',
    (string)$pdo->query("SELECT status FROM notification_outbox WHERE id = " . (int)$pendingItem->getId())->fetchColumn() === 'PENDING_RETRY'
);

$batchRun = $runWorker(['--batch-size=7']);
$batchBody = $decodeWorker($batchRun['stdout']);
$assert(
    '1.7 --batch-size=N se acepta y la pasada termina con código 0',
    $batchRun['exit'] === 0 && ($batchBody['success'] ?? false) === true,
    "Exit: {$batchRun['exit']}"
);

$spacedRun = $runWorker(['--batch-size', '9']);
$spacedBody = $decodeWorker($spacedRun['stdout']);
$assert(
    '1.8 La forma separada --batch-size 9 también se acepta',
    $spacedRun['exit'] === 0 && ($spacedBody['success'] ?? false) === true,
    "Exit: {$spacedRun['exit']}"
);

$zeroRun = $runWorker(['--batch-size=0']);
$zeroBody = $decodeWorker($zeroRun['stdout']);
$assert(
    '1.9 Un lote fuera de rango termina con código 2 e INVALID_BATCH_SIZE',
    $zeroRun['exit'] === 2
        && ($zeroBody['error']['code'] ?? '') === 'INVALID_BATCH_SIZE',
    "Exit: {$zeroRun['exit']} · código: " . (string)($zeroBody['error']['code'] ?? '')
);

$textRun = $runWorker(['--batch-size=abc']);
$textBody = $decodeWorker($textRun['stdout']);
$assert(
    '1.10 Un lote no numérico termina con código 2',
    $textRun['exit'] === 2 && ($textBody['error']['code'] ?? '') === 'INVALID_BATCH_SIZE',
    "Exit: {$textRun['exit']}"
);

$missingValueRun = $runWorker(['--batch-size']);
$missingValueBody = $decodeWorker($missingValueRun['stdout']);
$assert(
    '1.11 --batch-size sin valor no cae en silencio al lote por defecto',
    $missingValueRun['exit'] === 2
        && ($missingValueBody['error']['code'] ?? '') === 'MISSING_BATCH_SIZE_VALUE',
    "Exit: {$missingValueRun['exit']}"
);

$unknownRun = $runWorker(['--lote-magico']);
$unknownBody = $decodeWorker($unknownRun['stdout']);
$assert(
    '1.12 Una opción desconocida termina con código 2 e INVALID_OPTION',
    $unknownRun['exit'] === 2 && ($unknownBody['error']['code'] ?? '') === 'INVALID_OPTION',
    "Exit: {$unknownRun['exit']}"
);

$helpRun = $runWorker(['--help']);
$helpBody = $decodeWorker($helpRun['stdout']);
$assert(
    '1.13 --help documenta el uso y el rango de lote sin ejecutar la pasada',
    $helpRun['exit'] === 0
        && str_contains((string)($helpBody['usage'] ?? ''), '--batch-size')
        && ($helpBody['batch_size_range'] ?? []) === [1, 200],
    "Exit: {$helpRun['exit']}"
);

echo "\n--- 2. Registro de las nueve rutas nuevas (T-NOTIF-09.2) ---\n";

$newRoutes = [
    ['GET', '/api/coordinator/notifications/outbox'],
    ['POST', '/api/coordinator/notifications/outbox/1/retry'],
    ['POST', '/api/coordinator/notifications/outbox/retry-all-failed'],
    ['POST', '/api/coordinator/notifications/outbox/1/cancel'],
    ['GET', '/api/coordinator/notifications/settings'],
    ['PUT', '/api/coordinator/notifications/settings'],
    ['POST', '/api/coordinator/notifications/settings/test-webhook'],
    ['PATCH', '/api/coordinator/users/1/on-call'],
    ['POST', '/api/cron/notifications-dispatch'],
];

$unauthorizedCount = 0;
foreach ($newRoutes as [$method, $path]) {
    $response = $router->dispatch(new Request(method: $method, path: $path));
    if ($response->getStatusCode() === 401) {
        $unauthorizedCount++;
    } else {
        echo "         Ruta {$method} {$path} devolvió {$response->getStatusCode()} en vez de 401.\n";
    }
}
$assert(
    '2.1 Las nueve rutas existen y exigen credencial (401 sin token)',
    $unauthorizedCount === count($newRoutes),
    "Rutas con 401: {$unauthorizedCount} de " . count($newRoutes)
);

$coordinatorRoutes = array_slice($newRoutes, 0, 8);
$forbiddenCount = 0;
foreach ($coordinatorRoutes as [$method, $path]) {
    $response = $router->dispatch(new Request(
        method: $method,
        path: $path,
        headers: ['Authorization' => "Bearer {$technicianToken}"]
    ));
    if ($response->getStatusCode() === 403) {
        $forbiddenCount++;
    }
}
$assert(
    '2.2 Las ocho rutas de Coordinación quedan bajo $coordinatorAuth (403 con sesión de Técnico)',
    $forbiddenCount === count($coordinatorRoutes),
    "Rutas con 403: {$forbiddenCount} de " . count($coordinatorRoutes)
);

$cronRoute = $router->dispatch(new Request(method: 'POST', path: '/api/cron/notifications-dispatch'));
$assert(
    '2.3 La ruta de cron queda bajo $cronAuth (401 sin X-Cron-Secret)',
    $cronRoute->getStatusCode() === 401
);

$cronSecret = SecretProvider::cronSecret();
$cronOk = $router->dispatch(new Request(
    method: 'POST',
    path: '/api/cron/notifications-dispatch',
    queryParams: ['batch_size' => '1'],
    headers: ['X-Cron-Secret' => $cronSecret]
));
$cronBody = $cronOk->getDecodedBody();
$assert(
    '2.4 La ruta de cron acepta X-Cron-Secret y devuelve el resumen canónico',
    $cronOk->getStatusCode() === 200 && isset($cronBody['data']['claimed_count']),
    "Status: {$cronOk->getStatusCode()}"
);

// La ruta estática se declara antes que la paramétrica: si {id} la capturase,
// el identificador «retry-all-failed» habría caído en un 400 de validación.
$retryAll = $router->dispatch(new Request(
    method: 'POST',
    path: '/api/coordinator/notifications/outbox/retry-all-failed',
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
));
$retryAllBody = $retryAll->getDecodedBody();
$assert(
    '2.5 retry-all-failed se resuelve como acción propia y no como {id}',
    $retryAll->getStatusCode() === 200 && isset($retryAllBody['data']['retried_count']),
    "Status: {$retryAll->getStatusCode()}"
);

$wrongMethod = $router->dispatch(new Request(method: 'GET', path: '/api/coordinator/notifications/outbox/1/retry'));
$assert(
    '2.6 Un método no admitido sobre una ruta nueva responde 405 con Allow',
    $wrongMethod->getStatusCode() === 405
        && str_contains((string)$wrongMethod->getHeader('Allow'), 'POST'),
    "Status: {$wrongMethod->getStatusCode()}"
);

$unknownPath = $router->dispatch(new Request(method: 'POST', path: '/api/coordinator/notifications/outbox/1/retryx'));
$unknownError = $unknownPath->getDecodedBody();
$assert(
    '2.7 Una ruta inexistente bajo el mismo prefijo sigue devolviendo 404 ROUTE_NOT_FOUND',
    $unknownPath->getStatusCode() === 404
        && ($unknownError['error']['code'] ?? '') === 'ROUTE_NOT_FOUND',
    "Status: {$unknownPath->getStatusCode()}"
);

echo "\n--- 3. Rutas preexistentes intactas ---\n";

$preexisting = [
    ['GET', '/api/coordinator/incidents', 401],
    ['POST', '/api/cron/auto-close', 401],
    ['POST', '/api/cron/sanitary-clock-sweep', 401],
    ['GET', '/api/coordinator/locations', 401],
];

$intactCount = 0;
foreach ($preexisting as [$method, $path, $expected]) {
    $response = $router->dispatch(new Request(method: $method, path: $path));
    if ($response->getStatusCode() === $expected) {
        $intactCount++;
    } else {
        echo "         Ruta preexistente {$method} {$path} devolvió {$response->getStatusCode()}.\n";
    }
}
$assert(
    '3.1 Las rutas de Coordinación y cron preexistentes siguen registradas',
    $intactCount === count($preexisting),
    "Rutas intactas: {$intactCount} de " . count($preexisting)
);

$health = $router->dispatch(new Request(method: 'GET', path: '/api/health'));
$assert(
    '3.2 Una ruta pública preexistente sigue respondiendo (/api/health)',
    $health->getStatusCode() === 200,
    "Status: {$health->getStatusCode()}"
);

echo "\n======================================================================\n";
echo " Aserciones evaluadas: {$assertions}\n";
echo ' Fallos: ' . $failures . "\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo "\nRESULTADO: HAY FALLOS.\n";
    exit(1);
}

echo "\nRESULTADO: 100% EN VERDE.\n";
exit(0);
