<?php

declare(strict_types=1);

/**
 * NotificationEndpointsTest — Certificación del «Hecho cuando» de T-NOTIF-08.
 *
 * Valida, a través del router real y sobre el motor único PostgreSQL, las cuatro
 * condiciones que la tarea declara:
 *
 *  1. `CoordinatorNotificationController` expone `listOutbox`, `retryItem`,
 *     `retryAllFailed`, `cancelItem`, `getSettings`, `updateSettings` y
 *     `testWebhook` con los códigos y sobres del contrato técnico.
 *  2. `CoordinatorAdminController` valida y persiste `contact_email` en las sedes
 *     y expone `PATCH /api/coordinator/users/{id}/on-call`.
 *  3. `LocationPortalController` y `QrScanController` aceptan y validan
 *     `reporter_email` opcional.
 *  4. `CronController` incorpora `POST /api/cron/notifications-dispatch`
 *     protegido por `CronAuthMiddleware`.
 *
 * El canal Webhook se ejercita con el adaptador determinista del arnés: la suite
 * certifica el veredicto del controlador sin abrir un solo socket. La cola, las
 * sedes, los usuarios y `audit_log` se leen del PostgreSQL real.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\CoordinatorNotificationService;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\Transport\InMemoryNotificationTransport;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;
use VendGuard\Infrastructure\Notification\Transport\TransportDeliveryResult;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\CoordinatorNotificationController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationEndpointsTest (T-NOTIF-08)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

// `audit_log` es inmutable y no se purga: las certificaciones se miden sólo sobre
// los eventos escritos por ESTA ejecución, con la marca de agua del registro.
$auditFloor = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$outbox = new PdoNotificationOutboxRepository($pdo);
$settingsRepo = new PdoNotificationSettingsRepository($pdo, $outbox);
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

$assert(
    '0. Usuarios semilla de Coordinación y Técnico disponibles',
    $coordinator !== null && $technician !== null
);

if ($coordinator === null || $technician === null) {
    echo "ERROR: no se pudo preparar el escenario base.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);

$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert('0.1 Sedes semilla disponibles', $location1 !== null && $location2 !== null);

if ($location1 === null || $location2 === null) {
    echo "ERROR: sedes semilla no disponibles.\n";
    exit(1);
}

$coordinatorId = (int)$coordinator->getId();
$cronSecret = SecretProvider::cronSecret();

/**
 * Envía una petición a través del router real.
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $query
 * @param array<string, string> $headers
 */
$call = function (
    string $method,
    string $path,
    array $body = [],
    ?string $token = null,
    array $query = [],
    array $headers = []
) use ($router): Response {
    if ($token !== null) {
        $headers['Authorization'] = "Bearer {$token}";
    }
    if ($body !== []) {
        $headers['Content-Type'] = 'application/json';
    }

    return $router->dispatch(new Request(
        method: $method,
        path: $path,
        queryParams: $query,
        parsedBody: $body,
        headers: $headers
    ));
};

$json = static fn (Response $response): array => $response->getDecodedBody();

$outboxRow = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM notification_outbox WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException("La fila {$id} de la bandeja no existe.");
    }

    return $row;
};

$auditCount = function (string $action, ?int $entityId = null) use ($pdo, $auditFloor): int {
    $sql = 'SELECT COUNT(*) FROM audit_log WHERE action = :action AND id > :floor';
    $params = [':action' => $action, ':floor' => $auditFloor];

    if ($entityId !== null) {
        $sql .= ' AND entity_id = :entity_id';
        $params[':entity_id'] = $entityId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
};

/**
 * Encola una fila de la bandeja y la deja en el estado pedido.
 */
$enqueue = function (
    NotificationChannel $channel,
    NotificationPriority $priority,
    string $recipient,
    NotificationStatus $status,
    ?int $recipientLocationId = null,
    ?string $subject = null
) use ($outbox): NotificationOutboxItem {
    $item = $outbox->enqueue(new NotificationOutboxItem(
        id: null,
        incidentId: null,
        channel: $channel,
        priority: $priority,
        template: NotificationTemplate::INCIDENT_OPENED_SITE,
        recipient: $recipient,
        recipientRole: 'LOCATION',
        recipientUserId: null,
        recipientLocationId: $recipientLocationId,
        subject: $subject ?? ('Certificación T-NOTIF-08 · ' . $recipient),
        payload: ['ticket_code' => 'TICK-NOTIF08']
    ));

    if ($item === null || $item->getId() === null) {
        throw new RuntimeException('No se pudo encolar la notificación de certificación.');
    }

    $now = new DateTimeImmutable('now');
    switch ($status) {
        case NotificationStatus::SENT:
            // La máquina de estados sólo admite SENT desde PROCESSING: se recorre
            // la misma ruta que el despachador para no fabricar una fila imposible.
            $item->markProcessing($now);
            $item->markSent($now);
            $outbox->save($item);
            break;
        case NotificationStatus::FAILED_PERMANENT:
            $item->recordPermanentFailure('SMTP 550 5.1.1 Recipient address rejected', $now);
            $outbox->save($item);
            break;
        case NotificationStatus::CANCELLED_OBSOLETE:
            $item->markCancelledObsolete('Cancelada en la preparación del escenario.', $now);
            $outbox->save($item);
            break;
        case NotificationStatus::PENDING:
            break;
        default:
            throw new RuntimeException('Estado no soportado por el ayudante de certificación.');
    }

    return $item;
};

// Estado de partida del canal corporativo: desactivado y sin URL, como recién instalado.
$initialSettings = $settingsRepo->findCurrent();
$initialSettings->updateConfiguration('Canal Central de Emergencias', null, false, $coordinatorId);
$settingsRepo->save($initialSettings);

echo "--- 1. Superficie REST de supervisión: autorización y bandeja (RF-06.3) ---\n";

$unauthorized = $call('GET', '/api/coordinator/notifications/outbox');
$assert(
    '1.1 GET /outbox sin credencial => 401 UNAUTHORIZED',
    $unauthorized->getStatusCode() === 401,
    "Status obtenido: {$unauthorized->getStatusCode()}"
);

$technicianDenied = $call('GET', '/api/coordinator/notifications/outbox', token: $technicianToken);
$assert(
    '1.2 GET /outbox con sesión de Técnico => 403 (sólo Coordinación)',
    $technicianDenied->getStatusCode() === 403,
    "Status obtenido: {$technicianDenied->getStatusCode()}"
);

$listResponse = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken);
$listBody = $json($listResponse);
$summary = $listBody['data']['summary'] ?? [];

$assert(
    '1.3 GET /outbox con sesión de Coordinación => 200 y sobre success true',
    $listResponse->getStatusCode() === 200 && ($listBody['success'] ?? false) === true,
    "Status obtenido: {$listResponse->getStatusCode()}"
);
$assert(
    '1.4 El resumen publica los siete contadores y la insignia de fallos',
    isset(
        $summary['in_queue_count'],
        $summary['pending_count'],
        $summary['processing_count'],
        $summary['pending_retry_count'],
        $summary['sent_last_24h_count'],
        $summary['failed_permanent_count'],
        $summary['cancelled_obsolete_count'],
        $summary['has_permanent_failures_alert'],
        $summary['active_on_call_technicians_count']
    ),
    'Claves presentes: ' . implode(', ', array_keys($summary))
);
$assert(
    '1.5 La tabla publica paginación y lista de elementos',
    isset($listBody['data']['items'], $listBody['data']['pagination']['total'], $listBody['data']['pagination']['limit'], $listBody['data']['pagination']['offset'])
);

$pendingItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'recepcion.sede01@sede.vendguard.es', NotificationStatus::PENDING, (int)$location1->getId());
$failedItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::CRITICAL_SANITARY, 'buzon.antiguo@sede.vendguard.es', NotificationStatus::FAILED_PERMANENT, (int)$location1->getId());
$sentItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'recepcion.sede01@sede.vendguard.es', NotificationStatus::SENT, (int)$location1->getId());

$filteredResponse = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['status' => 'FAILED_PERMANENT']);
$filteredBody = $json($filteredResponse);
$filteredStatuses = array_column($filteredBody['data']['items'] ?? [], 'status');
$assert(
    '1.6 El filtro status=FAILED_PERMANENT sólo devuelve fallos definitivos',
    $filteredStatuses !== [] && array_unique($filteredStatuses) === ['FAILED_PERMANENT'],
    'Estados devueltos: ' . implode(', ', array_unique($filteredStatuses))
);

$priorityResponse = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['priority' => 'CRITICAL_SANITARY', 'limit' => '1']);
$priorityBody = $json($priorityResponse);
$assert(
    '1.7 Los filtros de prioridad y paginación se aplican (limit=1)',
    count($priorityBody['data']['items'] ?? []) === 1
        && ($priorityBody['data']['items'][0]['priority'] ?? '') === 'CRITICAL_SANITARY'
        && ($priorityBody['data']['pagination']['limit'] ?? 0) === 1
);
$assert(
    '1.8 La insignia de fallos definitivos se enciende con un envío en FAILED_PERMANENT',
    ($priorityBody['data']['summary']['has_permanent_failures_alert'] ?? false) === true
        && ($priorityBody['data']['summary']['failed_permanent_count'] ?? 0) >= 1
);
$assert(
    '1.9 Un código de ticket inexistente no devuelve filas',
    ($json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['ticket_code' => 'TICK-NO-EXISTE-08']))['data']['pagination']['total'] ?? -1) === 0
);

$badLimit = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['limit' => 'muchas']);
$assert(
    '1.10 Un limit no numérico => 400 VALIDATION_ERROR',
    $badLimit->getStatusCode() === 400 && ($json($badLimit)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$badLimit->getStatusCode()}"
);

echo "\n--- 2. Reintento manual con destinatario refrescado (RF-06.4) ---\n";

// La Sede corrige su buzón antes del reintento: el envío debe salir a la nueva dirección.
$locationPatch = $call('PATCH', '/api/coordinator/locations/' . $location1->getId(), [
    'latitude' => $location1->getLatitude(),
    'longitude' => $location1->getLongitude(),
    'contact_email' => 'Nuevo.Contacto@Sede-BCN-01.ES',
], $coordinatorToken);
$assert(
    '2.1 PATCH de sede persiste contact_email (RF-02.1)',
    $locationPatch->getStatusCode() === 200
        && ($json($locationPatch)['data']['contact_email'] ?? '') === 'nuevo.contacto@sede-bcn-01.es',
    "Status obtenido: {$locationPatch->getStatusCode()}"
);

$retryResponse = $call('POST', '/api/coordinator/notifications/outbox/' . $failedItem->getId() . '/retry', token: $coordinatorToken);
$retryBody = $json($retryResponse);
$retryRow = $outboxRow((int)$failedItem->getId());
$assert(
    '2.2 POST /outbox/{id}/retry => 200 y el envío vuelve a PENDING',
    $retryResponse->getStatusCode() === 200
        && ($retryBody['data']['status'] ?? '') === 'PENDING'
        && (string)$retryRow['status'] === 'PENDING',
    "Status obtenido: {$retryResponse->getStatusCode()}"
);
$assert(
    '2.3 El reintento refresca el destinatario con el contact_email vigente de la sede',
    ($retryBody['data']['recipient'] ?? '') === 'nuevo.contacto@sede-bcn-01.es'
        && (string)$retryRow['recipient'] === 'nuevo.contacto@sede-bcn-01.es'
);
$assert(
    '2.4 El reintento reinicia intentos y limpia el diagnóstico anterior',
    (int)$retryRow['attempts'] === 0 && $retryRow['last_error'] === null
);
$assert(
    '2.5 El reintento manual queda auditado como NOTIFICATION_MANUAL_RETRY',
    $auditCount('NOTIFICATION_MANUAL_RETRY', (int)$failedItem->getId()) === 1
);

$retryPending = $call('POST', '/api/coordinator/notifications/outbox/' . $pendingItem->getId() . '/retry', token: $coordinatorToken);
$assert(
    '2.6 Reintentar un envío en PENDING => 409 INVALID_OUTBOX_STATUS_FOR_RETRY',
    $retryPending->getStatusCode() === 409
        && ($json($retryPending)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_RETRY',
    "Status obtenido: {$retryPending->getStatusCode()}"
);

$retryMissing = $call('POST', '/api/coordinator/notifications/outbox/999999/retry', token: $coordinatorToken);
$assert(
    '2.7 Reintentar una notificación inexistente => 404 OUTBOX_ITEM_NOT_FOUND',
    $retryMissing->getStatusCode() === 404
        && ($json($retryMissing)['error']['code'] ?? '') === 'OUTBOX_ITEM_NOT_FOUND',
    "Status obtenido: {$retryMissing->getStatusCode()}"
);

$webhookFailed = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::CRITICAL_SANITARY, 'https://hooks.example.org/servicio/antiguo', NotificationStatus::FAILED_PERMANENT);
$retryWebhook = $call('POST', '/api/coordinator/notifications/outbox/' . $webhookFailed->getId() . '/retry', token: $coordinatorToken);
$assert(
    '2.8 Reintentar un Webhook sin URL vigente => 422 WEBHOOK_URL_NOT_CONFIGURED',
    $retryWebhook->getStatusCode() === 422
        && ($json($retryWebhook)['error']['code'] ?? '') === 'WEBHOOK_URL_NOT_CONFIGURED',
    "Status obtenido: {$retryWebhook->getStatusCode()}"
);

$secondFailed = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'rebote.dos@sede.vendguard.es', NotificationStatus::FAILED_PERMANENT, (int)$location1->getId());
$retryAllResponse = $call('POST', '/api/coordinator/notifications/outbox/retry-all-failed', token: $coordinatorToken);
$retryAllBody = $json($retryAllResponse);
$retryAllIds = $retryAllBody['data']['retried_ids'] ?? [];
$assert(
    '2.9 POST /retry-all-failed reencola los fallos definitivos y los identifica',
    $retryAllResponse->getStatusCode() === 200
        && in_array((int)$secondFailed->getId(), $retryAllIds, true)
        && ($retryAllBody['data']['retried_count'] ?? 0) === count($retryAllIds),
    "Status obtenido: {$retryAllResponse->getStatusCode()}"
);
$assert(
    '2.10 El lote omite el Webhook sin URL en lugar de abortar el reintento masivo',
    !in_array((int)$webhookFailed->getId(), $retryAllIds, true)
        && (string)$outboxRow((int)$webhookFailed->getId())['status'] === 'FAILED_PERMANENT'
);

echo "\n--- 3. Cancelación manual (RF-06.4) ---\n";

$cancelReason = 'Cancelación de certificación T-NOTIF-08 tras contacto telefónico.';
$cancelResponse = $call('POST', '/api/coordinator/notifications/outbox/' . $secondFailed->getId() . '/cancel', [
    'reason' => $cancelReason,
], $coordinatorToken);
$cancelBody = $json($cancelResponse);
$assert(
    '3.1 POST /outbox/{id}/cancel => 200 y CANCELLED_OBSOLETE con el motivo indicado',
    $cancelResponse->getStatusCode() === 200
        && ($cancelBody['data']['status'] ?? '') === 'CANCELLED_OBSOLETE'
        && ($cancelBody['data']['obsolete_reason'] ?? '') === $cancelReason
        && (string)$outboxRow((int)$secondFailed->getId())['obsolete_reason'] === $cancelReason,
    "Status obtenido: {$cancelResponse->getStatusCode()}"
);
$assert(
    '3.2 La cancelación manual queda auditada como NOTIFICATION_MANUAL_CANCEL',
    $auditCount('NOTIFICATION_MANUAL_CANCEL', (int)$secondFailed->getId()) === 1
);

$defaultCancel = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'cancelar.defecto@sede.vendguard.es', NotificationStatus::PENDING);
$defaultCancelResponse = $call('POST', '/api/coordinator/notifications/outbox/' . $defaultCancel->getId() . '/cancel', token: $coordinatorToken);
$assert(
    '3.3 Sin motivo se documenta el motivo por defecto de Coordinación',
    (string)$outboxRow((int)$defaultCancel->getId())['obsolete_reason'] === CoordinatorNotificationService::DEFAULT_CANCELLATION_REASON,
    'Motivo persistido: ' . (string)$outboxRow((int)$defaultCancel->getId())['obsolete_reason']
);

$cancelSent = $call('POST', '/api/coordinator/notifications/outbox/' . $sentItem->getId() . '/cancel', token: $coordinatorToken);
$assert(
    '3.4 Cancelar un envío ya entregado => 409 INVALID_OUTBOX_STATUS_FOR_CANCEL',
    $cancelSent->getStatusCode() === 409
        && ($json($cancelSent)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_CANCEL',
    "Status obtenido: {$cancelSent->getStatusCode()}"
);

echo "\n--- 4. Canal Corporativo de Emergencias (RF-06.5) ---\n";

$settingsResponse = $call('GET', '/api/coordinator/notifications/settings', token: $coordinatorToken);
$settingsBody = $json($settingsResponse)['data'] ?? [];
$assert(
    '4.1 GET /settings => 200 con configuración y técnicos de guardia',
    $settingsResponse->getStatusCode() === 200
        && array_key_exists('webhook_name', $settingsBody)
        && array_key_exists('webhook_url', $settingsBody)
        && array_key_exists('is_webhook_enabled', $settingsBody)
        && array_key_exists('last_tested_at', $settingsBody)
        && array_key_exists('last_test_status', $settingsBody)
        && array_key_exists('last_test_error', $settingsBody)
        && array_key_exists('on_call_technicians', $settingsBody),
    'Claves presentes: ' . implode(', ', array_keys($settingsBody))
);

$validSettings = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => 'https://hooks.example.org/services/T000/B000/X000',
    'is_webhook_enabled' => true,
], $coordinatorToken);
$persistedSettings = $settingsRepo->findCurrent();
$assert(
    '4.2 PUT /settings con URL HTTPS pública => 200 y configuración persistida',
    $validSettings->getStatusCode() === 200
        && $persistedSettings->getWebhookUrl() === 'https://hooks.example.org/services/T000/B000/X000'
        && $persistedSettings->isWebhookEnabled() === true
        && $persistedSettings->getWebhookName() === 'Slack #emergencias-frio',
    "Status obtenido: {$validSettings->getStatusCode()}"
);
$assert(
    '4.3 La actualización del canal queda auditada',
    $auditCount('NOTIFICATION_WEBHOOK_UPDATED') >= 1
);

// Ahora que hay URL vigente, el reintento del Webhook sí se reencola contra ella.
$webhookRetryOk = $call('POST', '/api/coordinator/notifications/outbox/' . $webhookFailed->getId() . '/retry', token: $coordinatorToken);
$assert(
    '4.4 Con URL vigente el reintento Webhook se reencola contra la URL configurada',
    $webhookRetryOk->getStatusCode() === 200
        && ($json($webhookRetryOk)['data']['recipient'] ?? '') === 'https://hooks.example.org/services/T000/B000/X000',
    "Status obtenido: {$webhookRetryOk->getStatusCode()}"
);

$invalidScheme = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Canal inseguro',
    'webhook_url' => 'http://hooks.example.org/servicio',
    'is_webhook_enabled' => true,
], $coordinatorToken);
$assert(
    '4.5 PUT /settings con esquema http => 422 INVALID_WEBHOOK_URL_SCHEME',
    $invalidScheme->getStatusCode() === 422
        && ($json($invalidScheme)['error']['code'] ?? '') === 'INVALID_WEBHOOK_URL_SCHEME',
    "Status obtenido: {$invalidScheme->getStatusCode()}"
);

$internalHost = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Canal interno',
    'webhook_url' => 'https://127.0.0.1/hook',
    'is_webhook_enabled' => true,
], $coordinatorToken);
$assert(
    '4.6 PUT /settings con host privado => 422 FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    $internalHost->getStatusCode() === 422
        && ($json($internalHost)['error']['code'] ?? '') === 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    "Status obtenido: {$internalHost->getStatusCode()}"
);

$missingUrl = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Canal sin URL',
    'webhook_url' => '',
    'is_webhook_enabled' => true,
], $coordinatorToken);
$assert(
    '4.7 PUT /settings activando sin URL => 422 WEBHOOK_URL_REQUIRED',
    $missingUrl->getStatusCode() === 422
        && ($json($missingUrl)['error']['code'] ?? '') === 'WEBHOOK_URL_REQUIRED',
    "Status obtenido: {$missingUrl->getStatusCode()}"
);

$missingFlag = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Canal sin bandera',
], $coordinatorToken);
$assert(
    '4.8 PUT /settings sin is_webhook_enabled => 400 VALIDATION_ERROR',
    $missingFlag->getStatusCode() === 400
        && ($json($missingFlag)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$missingFlag->getStatusCode()}"
);

echo "\n--- 5. Prueba directa del Webhook (RF-06.5.2) ---\n";

$emailTransport = new InMemoryNotificationTransport(NotificationChannel::EMAIL);
$webhookOkTransport = new InMemoryNotificationTransport(
    NotificationChannel::WEBHOOK,
    TransportDeliveryResult::success(200)
);
$webhookFailTransport = new InMemoryNotificationTransport(
    NotificationChannel::WEBHOOK,
    TransportDeliveryResult::permanentFailure('El destino respondió con HTTP 403: canal no autorizado.', 403)
);

$buildService = static fn (InMemoryNotificationTransport $webhookTransport): CoordinatorNotificationService => new CoordinatorNotificationService(
    outbox: $outbox,
    locations: $locationRepo,
    settings: $settingsRepo,
    onCallTechnicians: $userRepo,
    transports: new NotificationTransportRegistry($emailTransport, $webhookTransport),
    audit: new AuditLogger()
);

$testRequest = static function (array $body = []) use ($coordinator): Request {
    $request = new Request(
        method: 'POST',
        path: '/api/coordinator/notifications/settings/test-webhook',
        parsedBody: $body
    );
    $request->setAttribute('authenticated_user', $coordinator);
    $request->setAttribute('user_id', $coordinator->getId());

    return $request;
};

// Canal desactivado y sin URL: la prueba no puede inventarse un destino.
$cleared = $settingsRepo->findCurrent();
$cleared->updateConfiguration('Canal Central de Emergencias', null, false, $coordinatorId);
$settingsRepo->save($cleared);

$noUrlController = new CoordinatorNotificationController($buildService($webhookOkTransport));
$noUrlResponse = $noUrlController->testWebhook($testRequest());
$assert(
    '5.1 Probar el Webhook sin URL configurada => 422 WEBHOOK_URL_NOT_CONFIGURED',
    $noUrlResponse->getStatusCode() === 422
        && ($json($noUrlResponse)['error']['code'] ?? '') === 'WEBHOOK_URL_NOT_CONFIGURED',
    "Status obtenido: {$noUrlResponse->getStatusCode()}"
);

$schemeResponse = $noUrlController->testWebhook($testRequest(['webhook_url' => 'http://hooks.example.org/servicio']));
$assert(
    '5.2 La prueba exige https:// => 422 INVALID_WEBHOOK_URL_SCHEME',
    $schemeResponse->getStatusCode() === 422
        && ($json($schemeResponse)['error']['code'] ?? '') === 'INVALID_WEBHOOK_URL_SCHEME',
    "Status obtenido: {$schemeResponse->getStatusCode()}"
);

$ssrfResponse = $noUrlController->testWebhook($testRequest(['webhook_url' => 'https://192.168.1.20/hook']));
$assert(
    '5.3 La prueba aplica el blindaje anti-SSRF => 422 FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    $ssrfResponse->getStatusCode() === 422
        && ($json($ssrfResponse)['error']['code'] ?? '') === 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    "Status obtenido: {$ssrfResponse->getStatusCode()}"
);

$outboxRowsBeforeTest = (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();

$okController = new CoordinatorNotificationController($buildService($webhookOkTransport));
$okResponse = $okController->testWebhook($testRequest([
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => 'https://hooks.example.org/services/T000/B000/X000',
]));
$okBody = $json($okResponse)['data'] ?? [];
$settingsAfterOk = $settingsRepo->findCurrent();
$assert(
    '5.4 La prueba con destino que acepta => 200 test_passed true y diagnóstico',
    $okResponse->getStatusCode() === 200
        && ($okBody['test_passed'] ?? false) === true
        && ($okBody['status'] ?? '') === 'SUCCESS'
        && ($okBody['http_code'] ?? 0) === 200
        && array_key_exists('duration_ms', $okBody)
        && array_key_exists('tested_at', $okBody),
    "Status obtenido: {$okResponse->getStatusCode()}"
);
$assert(
    '5.5 El resultado de la prueba queda en last_test_status y last_tested_at',
    $settingsAfterOk->getLastTestStatus() === 'SUCCESS'
        && $settingsAfterOk->getLastTestedAt() !== null
        && $settingsAfterOk->getLastTestError() === null
);
$outboxRowsAfterTest = (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();
$assert(
    '5.6 La prueba no toca la cola pendiente (misma cuenta de filas antes y después)',
    $outboxRowsAfterTest === $outboxRowsBeforeTest,
    "Filas antes: {$outboxRowsBeforeTest} · después: {$outboxRowsAfterTest}"
);

$failController = new CoordinatorNotificationController($buildService($webhookFailTransport));
$failResponse = $failController->testWebhook($testRequest([
    'webhook_name' => 'Canal roto',
    'webhook_url' => 'https://hooks.example.org/servicio-roto',
]));
$failBody = $json($failResponse)['data'] ?? [];
$assert(
    '5.7 Un rechazo remoto informa el diagnóstico pero no es error de la petición (200)',
    $failResponse->getStatusCode() === 200
        && ($failBody['test_passed'] ?? true) === false
        && ($failBody['status'] ?? '') === 'FAILED'
        && ($failBody['http_code'] ?? 0) === 403
        && ($failBody['error'] ?? '') !== '',
    "Status obtenido: {$failResponse->getStatusCode()}"
);
$assert(
    '5.8 La prueba fallida persiste su diagnóstico',
    $settingsRepo->findCurrent()->getLastTestStatus() === 'FAILED'
        && $settingsRepo->findCurrent()->getLastTestError() !== null
);
$assert(
    '5.9 Cada prueba queda auditada como NOTIFICATION_WEBHOOK_TESTED',
    $auditCount('NOTIFICATION_WEBHOOK_TESTED') >= 2
);

echo "\n--- 6. Técnico de guardia (RF-02.5, RF-02.6) ---\n";

$technicianId = (int)$technician->getId();
$onCallResponse = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '6.1 PATCH /users/{id}/on-call => 200 y marca persistida',
    $onCallResponse->getStatusCode() === 200
        && ($json($onCallResponse)['data']['is_on_call'] ?? false) === true
        && $userRepo->findById($technicianId, onlyActive: false)->isOnCall() === true,
    "Status obtenido: {$onCallResponse->getStatusCode()}"
);
$assert(
    '6.2 Con un técnico de guardia el contador del panel lo refleja',
    $userRepo->countOnCallTechnicians() === 1
);

$offCallResponse = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => false], $coordinatorToken);
$assert(
    '6.3 PATCH /users/{id}/on-call con false retira la guardia',
    $offCallResponse->getStatusCode() === 200
        && ($json($offCallResponse)['data']['is_on_call'] ?? true) === false
        && $userRepo->countOnCallTechnicians() === 0
);

$onCoordinator = $call('PATCH', "/api/coordinator/users/{$coordinatorId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '6.4 Marcar guardia sobre un Coordinador => 422 ON_CALL_ONLY_FOR_TECHNICIANS',
    $onCoordinator->getStatusCode() === 422
        && ($json($onCoordinator)['error']['code'] ?? '') === 'ON_CALL_ONLY_FOR_TECHNICIANS',
    "Status obtenido: {$onCoordinator->getStatusCode()}"
);

$onCallMissing = $call('PATCH', '/api/coordinator/users/999999/on-call', ['is_on_call' => true], $coordinatorToken);
$assert(
    '6.5 Marcar guardia sobre un usuario inexistente => 404 USER_NOT_FOUND',
    $onCallMissing->getStatusCode() === 404
        && ($json($onCallMissing)['error']['code'] ?? '') === 'USER_NOT_FOUND',
    "Status obtenido: {$onCallMissing->getStatusCode()}"
);

$onCallBadType = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => 'true'], $coordinatorToken);
$assert(
    '6.6 Un is_on_call no booleano => 400 VALIDATION_ERROR',
    $onCallBadType->getStatusCode() === 400
        && ($json($onCallBadType)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$onCallBadType->getStatusCode()}"
);

$onCallMissingFlag = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", [], $coordinatorToken);
$assert(
    '6.7 Sin is_on_call => 400 VALIDATION_ERROR',
    $onCallMissingFlag->getStatusCode() === 400
        && ($json($onCallMissingFlag)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$onCallMissingFlag->getStatusCode()}"
);

// Alta de un segundo técnico para poder darlo de baja (guardia mínima) y
// comprobar que la marca se retira y no puede reactivarse sobre una baja.
$createdTechnicianResponse = $call('POST', '/api/coordinator/users', [
    'name' => 'Nuria Guardia',
    'email' => 'nuria.guardia.tnotif08@vendguard.internal',
    'role' => 'TECHNICIAN',
    'password' => 'Certificacion2026!',
    'phone' => '+34 600 111 222',
], $coordinatorToken);
$createdTechnician = $json($createdTechnicianResponse)['data'] ?? [];
$createdTechnicianId = (int)($createdTechnician['id'] ?? 0);
$assert(
    '6.8 Alta de un técnico auxiliar para la prueba de baja',
    $createdTechnicianResponse->getStatusCode() === 201 && $createdTechnicianId > 0,
    "Status obtenido: {$createdTechnicianResponse->getStatusCode()}"
);

$markAuxOnCall = $call('PATCH', "/api/coordinator/users/{$createdTechnicianId}/on-call", ['is_on_call' => true], $coordinatorToken);
$deactivateAux = $call('PATCH', "/api/coordinator/users/{$createdTechnicianId}/deactivate", token: $coordinatorToken);
$auxRow = $pdo->prepare('SELECT is_active, is_on_call FROM users WHERE id = :id');
$auxRow->execute([':id' => $createdTechnicianId]);
$auxState = $auxRow->fetch(PDO::FETCH_ASSOC);
$assert(
    '6.9 La baja del técnico retira la guardia de forma atómica (RF-02.6)',
    $markAuxOnCall->getStatusCode() === 200
        && $deactivateAux->getStatusCode() === 200
        && $auxState !== false
        && (bool)$auxState['is_active'] === false
        && (bool)$auxState['is_on_call'] === false,
    "Status de baja: {$deactivateAux->getStatusCode()}"
);

$onInactive = $call('PATCH', "/api/coordinator/users/{$createdTechnicianId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '6.10 Activar la guardia de una cuenta dada de baja => 409 CANNOT_SET_ON_CALL_INACTIVE_USER',
    $onInactive->getStatusCode() === 409
        && ($json($onInactive)['error']['code'] ?? '') === 'CANNOT_SET_ON_CALL_INACTIVE_USER',
    "Status obtenido: {$onInactive->getStatusCode()}"
);
$assert(
    '6.11 Cada cambio de guardia queda auditado como USER_ON_CALL_UPDATED',
    $auditCount('USER_ON_CALL_UPDATED') >= 3
);

echo "\n--- 7. contact_email obligatorio y editable en la ficha de Sede (RF-02.1) ---\n";

$invalidContactEmail = $call('PATCH', '/api/coordinator/locations/' . $location2->getId(), [
    'latitude' => $location2->getLatitude(),
    'longitude' => $location2->getLongitude(),
    'contact_email' => 'esto-no-es-un-correo',
], $coordinatorToken);
$assert(
    '7.1 PATCH de sede con correo mal formado => 400 VALIDATION_ERROR',
    $invalidContactEmail->getStatusCode() === 400
        && ($json($invalidContactEmail)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$invalidContactEmail->getStatusCode()}"
);

$emptyContactEmail = $call('PATCH', '/api/coordinator/locations/' . $location2->getId(), [
    'latitude' => $location2->getLatitude(),
    'longitude' => $location2->getLongitude(),
    'contact_email' => '',
], $coordinatorToken);
$assert(
    '7.2 Vaciar el buzón oficial => 400 VALIDATION_ERROR (la sede no puede quedarse sin destinatario)',
    $emptyContactEmail->getStatusCode() === 400
        && ($json($emptyContactEmail)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$emptyContactEmail->getStatusCode()}"
);

$createdLocation = $call('POST', '/api/coordinator/locations', [
    'site_code' => 'SEDE-NOTIF-08',
    'name' => 'Sede de Certificación Notificaciones',
    'address' => 'Calle de la Certificación 8, 08001 Barcelona',
    'latitude' => 41.3874,
    'longitude' => 2.1686,
    'contact_email' => 'Recepcion.Certificacion@Sede-Vendguard.ES',
], $coordinatorToken);
$createdLocationBody = $json($createdLocation)['data'] ?? [];
$assert(
    '7.3 POST de sede con contact_email => 201 y buzón normalizado persistido',
    $createdLocation->getStatusCode() === 201
        && ($createdLocationBody['contact_email'] ?? '') === 'recepcion.certificacion@sede-vendguard.es',
    "Status obtenido: {$createdLocation->getStatusCode()}"
);

$invalidCreatedEmail = $call('POST', '/api/coordinator/locations', [
    'site_code' => 'SEDE-NOTIF-08-B',
    'name' => 'Sede con buzón inválido',
    'address' => 'Calle de la Certificación 9, 08001 Barcelona',
    'latitude' => 41.3875,
    'longitude' => 2.1687,
    'contact_email' => 'sin-arroba',
], $coordinatorToken);
$assert(
    '7.4 POST de sede con correo inválido => 400 VALIDATION_ERROR',
    $invalidCreatedEmail->getStatusCode() === 400
        && ($json($invalidCreatedEmail)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$invalidCreatedEmail->getStatusCode()}"
);

echo "\n--- 8. reporter_email opcional en Portal de Sede y QR ciudadano (RF-02.2) ---\n";

$siteToken = $authService->generateSiteToken($location1);
$machinesLoc1 = $machineRepo->findActiveByLocationId((int)$location1->getId());
$machinesLoc2 = $machineRepo->findActiveByLocationId((int)$location2->getId());
$assert(
    '8.1 Hay máquinas activas en ambas sedes para el escenario',
    $machinesLoc1 !== [] && $machinesLoc2 !== []
);

$machine1 = $machinesLoc1[0];
$machine2 = $machinesLoc2[0];

$invalidReporter = $call('POST', '/api/incidents', [
    'machine_id' => $machine1->getId(),
    'category' => 'PRODUCT_JAM',
    'description' => 'El producto está atascado en el canal y no cae.',
    'reporter_email' => 'informador-invalido',
], $siteToken);
$assert(
    '8.2 POST /api/incidents con reporter_email inválido => 422 INVALID_REPORTER_EMAIL',
    $invalidReporter->getStatusCode() === 422
        && ($json($invalidReporter)['error']['code'] ?? '') === 'INVALID_REPORTER_EMAIL',
    "Status obtenido: {$invalidReporter->getStatusCode()}"
);

$validReporter = $call('POST', '/api/incidents', [
    'machine_id' => $machine1->getId(),
    'category' => 'PRODUCT_JAM',
    'description' => 'El producto está atascado en el canal y no cae.',
    'reporter_email' => 'Carla.Gomez@HospitalDelMar.CAT',
], $siteToken);
$validReporterBody = $json($validReporter)['data'] ?? [];
$validReporterIncidentId = (int)($validReporterBody['id'] ?? ($validReporterBody['incident']['id'] ?? 0));
$storedIncident = $validReporterIncidentId > 0 ? $incidentRepo->findById($validReporterIncidentId) : null;
$assert(
    '8.3 POST /api/incidents con reporter_email válido => 201 y correo normalizado en el expediente',
    $validReporter->getStatusCode() === 201
        && $storedIncident !== null
        && $storedIncident->getReporterEmail() === 'carla.gomez@hospitaldelmar.cat',
    "Status obtenido: {$validReporter->getStatusCode()}"
);

$invalidQrReporter = $call('POST', '/api/qr/report', [        'machine_code' => $machine2->getCode(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina no enfría y los productos están calientes.',
        'reporter_email' => 'ciudadano-arroba-perdida',
]);
$assert(
    '8.4 POST /api/qr/report con reporter_email inválido => 422 INVALID_REPORTER_EMAIL',
    $invalidQrReporter->getStatusCode() === 422
        && ($json($invalidQrReporter)['error']['code'] ?? '') === 'INVALID_REPORTER_EMAIL',
    "Status obtenido: {$invalidQrReporter->getStatusCode()}"
);

$validQrReporter = $call('POST', '/api/qr/report', [        'machine_code' => $machine2->getCode(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina no enfría y los productos están calientes.',
        'reporter_email' => 'Ciudadano.QR@Example.ORG',
]);
$validQrBody = $json($validQrReporter);
$qrTicketCode = (string)($validQrBody['data']['ticket_code'] ?? '');
$qrIncidentRow = $pdo->prepare('SELECT reporter_email FROM incidents WHERE ticket_code = :ticket');
$qrIncidentRow->execute([':ticket' => $qrTicketCode]);
$qrReporterEmail = $qrIncidentRow->fetchColumn();
$assert(
    '8.5 POST /api/qr/report con reporter_email válido => alta con correo normalizado',
    $validQrReporter->getStatusCode() === 201
        && $qrTicketCode !== ''
        && $qrReporterEmail === 'ciudadano.qr@example.org',
    "Status obtenido: {$validQrReporter->getStatusCode()} · ticket: {$qrTicketCode}"
);

echo "\n--- 9. Proceso batch de despacho (RF-01.3, RF-01.4, RF-04) ---\n";

$noSecret = $call('POST', '/api/cron/notifications-dispatch');
$assert(
    '9.1 POST /api/cron/notifications-dispatch sin credencial => 401',
    $noSecret->getStatusCode() === 401,
    "Status obtenido: {$noSecret->getStatusCode()}"
);

$badBatch = $call('POST', '/api/cron/notifications-dispatch', token: null, query: ['batch_size' => '0'], headers: ['X-Cron-Secret' => $cronSecret]);
$assert(
    '9.2 Un batch_size fuera de rango => 422 INVALID_BATCH_SIZE',
    $badBatch->getStatusCode() === 422
        && ($json($badBatch)['error']['code'] ?? '') === 'INVALID_BATCH_SIZE',
    "Status obtenido: {$badBatch->getStatusCode()}"
);

$dispatchResponse = $call('POST', '/api/cron/notifications-dispatch', token: null, query: ['batch_size' => '25'], headers: ['X-Cron-Secret' => $cronSecret]);
$dispatchBody = $json($dispatchResponse)['data'] ?? [];
$assert(
    '9.3 Con el secreto del proceso => 200 y resumen canónico del despachador',
    $dispatchResponse->getStatusCode() === 200
        && array_keys($dispatchBody) === [
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
    'Claves devueltas: ' . implode(', ', array_keys($dispatchBody))
);
$assert(
    '9.4 La pasada reclama la cola pendiente y no deja filas en PROCESSING',
    ($dispatchBody['claimed_count'] ?? 0) >= 1
        && (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE status = 'PROCESSING'")->fetchColumn() === 0,
    'claimed_count: ' . (string)($dispatchBody['claimed_count'] ?? 0)
);
$assert(
    '9.5 Sin SMTP configurado el correo cae como fallo transitorio visible en la bandeja',
    ($dispatchBody['retry_scheduled_count'] ?? 0) >= 1
        && (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE status = 'PENDING_RETRY'")->fetchColumn() >= 1
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
