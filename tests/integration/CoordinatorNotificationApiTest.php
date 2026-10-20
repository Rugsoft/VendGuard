<?php

declare(strict_types=1);

/**
 * CoordinatorNotificationApiTest — Certificación del «Hecho cuando» de T-NOTIF-11 (API).
 *
 * Certifica, a través del router real y sobre el motor único PostgreSQL, las
 * condiciones de la tarea relativas a la superficie REST de Coordinación:
 *
 *  1. Bandeja de supervisión filtrable por los 6 estados canónicos, canal,
 *     prioridad, código de ticket y paginación (RF-06.3).
 *  2. Reintento manual que toma el `contact_email` de la Sede o la `webhook_url`
 *     recién corregidos, tanto individual como masivo (RF-06.4).
 *  3. Cancelación manual con motivo auditado y rechazo de estados no cancelables.
 *  4. Bloqueo anti-SSRF en la configuración del canal y en la prueba directa
 *     «Probar conexión» (RF-06.5).
 *  5. Toggle de `is_on_call` sobre técnicos activos, retirada automática al dar de
 *     baja la cuenta y prohibición de activarlo sobre una cuenta inactiva
 *     (RF-02.5, RF-02.6).
 *
 * El canal Webhook se ejercita con transportes deterministas del arnés: la suite
 * certifica el veredicto del controlador sin abrir un solo socket. La cola, las
 * sedes, los usuarios y `audit_log` se leen del PostgreSQL real.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\CoordinatorNotificationService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
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
echo " VendGuard: Integración - CoordinatorNotificationApiTest (T-NOTIF-11)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

// `audit_log` es inmutable: se mide sólo lo escrito por ESTA ejecución.
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

$assert('0. Usuarios semilla de Coordinación y Técnico disponibles', $coordinator !== null && $technician !== null);

if ($coordinator === null || $technician === null) {
    echo "ERROR: no se pudo preparar el escenario base.\n";
    exit(1);
}

$coordinatorId = (int)$coordinator->getId();
$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert('0.1 Sede semilla disponible para el doble destinatario', $location !== null);

if ($location === null) {
    echo "ERROR: sede semilla no disponible.\n";
    exit(1);
}

// Estado de partida del canal corporativo: desactivado y sin URL, como recién instalado.
$initialSettings = $settingsRepo->findCurrent();
$initialSettings->updateConfiguration('Canal Central de Emergencias', null, false, $coordinatorId);
$settingsRepo->save($initialSettings);

// Expediente real que da soporte a las filas de la bandeja: el filtro por código de
// ticket se resuelve contra `incidents`, y el purgado del arnés arrastra la cola
// colgada de él, de modo que esta suite no deja residuos.
$machineId = (int)$pdo->query(
    "SELECT m.id
     FROM machines m
     JOIN locations l ON l.id = m.location_id
     WHERE m.deleted_at IS NULL AND m.is_active = TRUE
       AND l.deleted_at IS NULL AND l.is_active = TRUE
     ORDER BY m.id ASC
     LIMIT 1"
)->fetchColumn();

$fixtureIncident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: TicketCode::generate()->value(),
    machineId: $machineId,
    locationId: (int)$location->getId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Avería de certificación de la API de notificaciones (T-NOTIF-11).',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::REGISTERED,
    reporterName: 'Certificación T-NOTIF-11',
    reporterPhone: '600333444',
    reporterEmail: null
), $coordinatorId, 'Alta de certificación T-NOTIF-11');

$fixtureIncidentId = (int)$fixtureIncident->getId();
$fixtureTicketCode = $fixtureIncident->getTicketCode();
$assert('0.2 Expediente de soporte creado para la cola', $fixtureIncidentId > 0 && $fixtureTicketCode !== '');

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
 *
 * @param array<string, mixed> $payload
 */
$enqueue = function (
    NotificationChannel $channel,
    NotificationPriority $priority,
    ?string $recipient,
    NotificationStatus $status,
    ?int $recipientLocationId = null,
    string $suffix = ''
) use ($outbox, $fixtureIncidentId): NotificationOutboxItem {
    $item = $outbox->enqueue(new NotificationOutboxItem(
        id: null,
        incidentId: $fixtureIncidentId,
        channel: $channel,
        priority: $priority,
        template: NotificationTemplate::INCIDENT_OPENED_SITE,
        recipient: $recipient ?? 'https://hooks.example.org/servicio',
        recipientRole: $channel === NotificationChannel::WEBHOOK ? 'COORDINATOR' : 'LOCATION',
        recipientUserId: null,
        recipientLocationId: $channel === NotificationChannel::EMAIL ? $recipientLocationId : null,
        subject: 'Certificación T-NOTIF-11 · ' . ($suffix !== '' ? $suffix : $status->value),
        payload: ['ticket_code' => 'TICK-NOTIF11']
    ));

    if ($item === null || $item->getId() === null) {
        throw new RuntimeException('No se pudo encolar la notificación de certificación.');
    }

    $now = new DateTimeImmutable('now');
    switch ($status) {
        case NotificationStatus::PROCESSING:
            $item->markProcessing($now);
            $outbox->save($item);
            break;
        case NotificationStatus::PENDING_RETRY:
            $item->recordTransientFailure('Timeout de conexión de certificación.', $now->modify('+5 minutes'));
            $outbox->save($item);
            break;
        case NotificationStatus::SENT:
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
    }

    return $item;
};

echo "--- 1. Autorización de la superficie de supervisión ---\n";

$protectedRoutes = [
    ['GET', '/api/coordinator/notifications/outbox'],
    ['POST', '/api/coordinator/notifications/outbox/retry-all-failed'],
    ['POST', '/api/coordinator/notifications/outbox/999999/retry'],
    ['POST', '/api/coordinator/notifications/outbox/999999/cancel'],
    ['GET', '/api/coordinator/notifications/settings'],
    ['PUT', '/api/coordinator/notifications/settings'],
    ['POST', '/api/coordinator/notifications/settings/test-webhook'],
];

$anonymousStatuses = [];
$technicianStatuses = [];
foreach ($protectedRoutes as [$method, $path]) {
    $anonymousStatuses[] = $call($method, $path)->getStatusCode();
    $technicianStatuses[] = $call($method, $path, token: $technicianToken)->getStatusCode();
}

$assert(
    '1.1 Las 7 rutas de supervisión rechazan la llamada anónima con 401',
    array_unique($anonymousStatuses) === [401],
    'Estados: ' . implode(', ', $anonymousStatuses)
);
$assert(
    '1.2 Las 7 rutas de supervisión rechazan la sesión de Técnico con 403',
    array_unique($technicianStatuses) === [403],
    'Estados: ' . implode(', ', $technicianStatuses)
);

echo "\n--- 2. Bandeja filtrable por los 6 estados canónicos (RF-06.3) ---\n";

$pendingItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'recepcion.sede01@sede.vendguard.es', NotificationStatus::PENDING, (int)$location->getId(), 'PENDING');
$processingItem = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::CRITICAL_SANITARY, 'https://hooks.example.org/servicio', NotificationStatus::PROCESSING, null, 'PROCESSING');
$retryItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::CRITICAL_SANITARY, 'recepcion.sede01@sede.vendguard.es', NotificationStatus::PENDING_RETRY, (int)$location->getId(), 'PENDING_RETRY');
$sentItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'recepcion.sede01@sede.vendguard.es', NotificationStatus::SENT, (int)$location->getId(), 'SENT');
$failedItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::CRITICAL_SANITARY, 'buzon.antiguo@sede.vendguard.es', NotificationStatus::FAILED_PERMANENT, (int)$location->getId(), 'FAILED_PERMANENT');
$cancelledItem = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::STANDARD, 'https://hooks.example.org/servicio', NotificationStatus::CANCELLED_OBSOLETE, null, 'CANCELLED_OBSOLETE');

$canonicalItems = [
    NotificationStatus::PENDING->value => $pendingItem,
    NotificationStatus::PROCESSING->value => $processingItem,
    NotificationStatus::PENDING_RETRY->value => $retryItem,
    NotificationStatus::SENT->value => $sentItem,
    NotificationStatus::FAILED_PERMANENT->value => $failedItem,
    NotificationStatus::CANCELLED_OBSOLETE->value => $cancelledItem,
];

$assert('2.1 Las 6 filas canónicas están encoladas', count($canonicalItems) === 6 && $pendingItem->getId() !== null);

$perStatusOk = true;
$perStatusDetail = '';
foreach ($canonicalItems as $status => $item) {
    $response = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
        'status' => $status,
        'ticket_code' => $fixtureTicketCode,
        'limit' => '200',
    ]);
    $body = $json($response);
    $items = $body['data']['items'] ?? [];
    $statuses = array_values(array_unique(array_column($items, 'status')));
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $items);

    if ($response->getStatusCode() !== 200 || $statuses !== [$status] || !in_array((int)$item->getId(), $ids, true)) {
        $perStatusOk = false;
        $perStatusDetail = "{$status} => HTTP {$response->getStatusCode()} · estados " . implode(',', $statuses);
    }
}
$assert('2.2 Cada uno de los 6 estados se filtra con exactitud y devuelve su fila', $perStatusOk, $perStatusDetail);

$allBody = $json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
    'status' => 'ALL',
    'ticket_code' => $fixtureTicketCode,
    'limit' => '200',
]));
$allStatuses = array_map(static fn(array $row): string => (string)$row['status'], $allBody['data']['items'] ?? []);
$uniqueStatuses = array_values(array_unique($allStatuses));
sort($uniqueStatuses, SORT_STRING);
$expectedStatuses = [
    'PENDING', 'PROCESSING', 'PENDING_RETRY', 'SENT', 'FAILED_PERMANENT', 'CANCELLED_OBSOLETE',
];
sort($expectedStatuses, SORT_STRING);
$assert(
    '2.3 status=ALL devuelve los 6 estados del catálogo cerrado',
    $allBody['data']['pagination']['total'] === 6 && $uniqueStatuses === $expectedStatuses,
    'Total: ' . (string)($allBody['data']['pagination']['total'] ?? -1) . ' · ' . implode(',', $uniqueStatuses)
);
$firstRow = $allBody['data']['items'][0] ?? [];
$assert(
    '2.4 La proyección publica destinatario, tipo de aviso, intentos y último error',
    array_key_exists('recipient', $firstRow)
        && array_key_exists('template_label', $firstRow)
        && array_key_exists('attempts', $firstRow)
        && array_key_exists('last_error', $firstRow)
        && array_key_exists('can_retry', $firstRow)
        && array_key_exists('can_cancel', $firstRow)
        && ($firstRow['recipient'] ?? '') !== '',
    'Claves presentes: ' . implode(', ', array_keys($firstRow))
);

$webhookBody = $json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
    'channel' => 'WEBHOOK',
    'ticket_code' => $fixtureTicketCode,
    'limit' => '200',
]));
$webhookChannels = array_values(array_unique(array_column($webhookBody['data']['items'] ?? [], 'channel')));
$assert(
    '2.5 El filtro de canal WEBHOOK sólo devuelve avisos del canal corporativo',
    $webhookChannels === ['WEBHOOK'] && $webhookBody['data']['pagination']['total'] === 2,
    'Canales: ' . implode(',', $webhookChannels) . ' · total ' . (string)($webhookBody['data']['pagination']['total'] ?? -1)
);

$criticalBody = $json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
    'priority' => 'CRITICAL_SANITARY',
    'ticket_code' => $fixtureTicketCode,
    'limit' => '200',
]));
$criticalPriorities = array_values(array_unique(array_column($criticalBody['data']['items'] ?? [], 'priority')));
$assert(
    '2.6 El filtro de prioridad CRITICAL_SANITARY aísla las alertas del Art. II',
    $criticalPriorities === ['CRITICAL_SANITARY'] && $criticalBody['data']['pagination']['total'] === 3,
    'Prioridades: ' . implode(',', $criticalPriorities)
);

$paginatedBody = $json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
    'ticket_code' => $fixtureTicketCode,
    'limit' => '2',
    'offset' => '1',
]));
$assert(
    '2.7 La paginación devuelve la página pedida sobre el total filtrado',
    count($paginatedBody['data']['items'] ?? []) === 2
        && ($paginatedBody['data']['pagination']['limit'] ?? 0) === 2
        && ($paginatedBody['data']['pagination']['offset'] ?? -1) === 1
        && ($paginatedBody['data']['pagination']['total'] ?? 0) === 6
);

$summary = $allBody['data']['summary'] ?? [];
$assert(
    '2.8 Los contadores KPI reflejan cada estado del catálogo',
    ($summary['pending_count'] ?? 0) >= 1
        && ($summary['processing_count'] ?? 0) >= 1
        && ($summary['pending_retry_count'] ?? 0) >= 1
        && ($summary['sent_last_24h_count'] ?? 0) >= 1
        && ($summary['failed_permanent_count'] ?? 0) >= 1
        && ($summary['cancelled_obsolete_count'] ?? 0) >= 1
        && ($summary['in_queue_count'] ?? 0) >= 3,
    'Resumen: ' . json_encode($summary)
);
$assert(
    '2.9 La insignia de fallos definitivos se enciende con failed_permanent_count > 0',
    ($summary['has_permanent_failures_alert'] ?? false) === true
);

$notFoundTicket = $json($call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: [
    'ticket_code' => 'TICK-NOTIF11-INEXISTENTE',
]));
$assert(
    '2.10 Un código de ticket ajeno no devuelve filas',
    ($notFoundTicket['data']['pagination']['total'] ?? -1) === 0
);

$invalidLimit = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['limit' => 'muchas']);
$assert(
    '2.11 Un limit no numérico => 400 VALIDATION_ERROR',
    $invalidLimit->getStatusCode() === 400 && ($json($invalidLimit)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$invalidLimit->getStatusCode()}"
);

$negativeOffset = $call('GET', '/api/coordinator/notifications/outbox', token: $coordinatorToken, query: ['offset' => '-5']);
$assert(
    '2.12 Un offset negativo => 400 VALIDATION_ERROR',
    $negativeOffset->getStatusCode() === 400 && ($json($negativeOffset)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$negativeOffset->getStatusCode()}"
);

echo "\n--- 3. Reintento manual con el buzón de Sede corregido (RF-06.4) ---\n";

$correctedEmail = 'recepcion.corregida@sede-bcn-01.es';
$locationPatch = $call('PATCH', '/api/coordinator/locations/' . $location->getId(), [
    'latitude' => $location->getLatitude(),
    'longitude' => $location->getLongitude(),
    // La edición de sede exige CP (Módulo 17, QA 7); se conserva el de la semilla.
    'postal_code' => $location->getPostalCode() ?? '08001',
    'contact_email' => 'Recepcion.Corregida@Sede-BCN-01.ES',
], $coordinatorToken);
$assert(
    '3.1 Coordinación corrige el contact_email de la Sede',
    $locationPatch->getStatusCode() === 200
        && ($json($locationPatch)['data']['contact_email'] ?? '') === $correctedEmail,
    "Status obtenido: {$locationPatch->getStatusCode()}"
);

$retryResponse = $call('POST', '/api/coordinator/notifications/outbox/' . $failedItem->getId() . '/retry', token: $coordinatorToken);
$retryBody = $json($retryResponse);
$retriedRow = $outboxRow((int)$failedItem->getId());
$assert(
    '3.2 POST /{id}/retry => 200 y el envío vuelve a PENDING',
    $retryResponse->getStatusCode() === 200
        && ($retryBody['data']['status'] ?? '') === 'PENDING'
        && (string)$retriedRow['status'] === 'PENDING',
    "Status obtenido: {$retryResponse->getStatusCode()}"
);
$assert(
    '3.3 El reintento toma el contact_email recién corregido',
    ($retryBody['data']['recipient'] ?? '') === $correctedEmail
        && (string)$retriedRow['recipient'] === $correctedEmail,
    'Destinatario persistido: ' . (string)$retriedRow['recipient']
);
$assert(
    '3.4 El reintento reinicia intentos y limpia el diagnóstico anterior',
    (int)$retriedRow['attempts'] === 0 && $retriedRow['last_error'] === null
);
$assert(
    '3.5 El reintento manual queda auditado como NOTIFICATION_MANUAL_RETRY',
    $auditCount('NOTIFICATION_MANUAL_RETRY', (int)$failedItem->getId()) === 1
);

$retryProcessing = $call('POST', '/api/coordinator/notifications/outbox/' . $processingItem->getId() . '/retry', token: $coordinatorToken);
$assert(
    '3.6 Reintentar un envío en PROCESSING => 409 INVALID_OUTBOX_STATUS_FOR_RETRY',
    $retryProcessing->getStatusCode() === 409
        && ($json($retryProcessing)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_RETRY',
    "Status obtenido: {$retryProcessing->getStatusCode()}"
);

$retrySent = $call('POST', '/api/coordinator/notifications/outbox/' . $sentItem->getId() . '/retry', token: $coordinatorToken);
$assert(
    '3.7 Reintentar un envío ya entregado => 409 INVALID_OUTBOX_STATUS_FOR_RETRY',
    $retrySent->getStatusCode() === 409
        && ($json($retrySent)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_RETRY',
    "Status obtenido: {$retrySent->getStatusCode()}"
);

$retryMissing = $call('POST', '/api/coordinator/notifications/outbox/999999/retry', token: $coordinatorToken);
$assert(
    '3.8 Reintentar una notificación inexistente => 404 OUTBOX_ITEM_NOT_FOUND',
    $retryMissing->getStatusCode() === 404
        && ($json($retryMissing)['error']['code'] ?? '') === 'OUTBOX_ITEM_NOT_FOUND',
    "Status obtenido: {$retryMissing->getStatusCode()}"
);

echo "\n--- 4. Reintento manual con la URL de Webhook corregida (RF-06.4, RF-06.5) ---\n";

$webhookFailed = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::CRITICAL_SANITARY, 'https://hooks.example.org/servicio/antiguo', NotificationStatus::FAILED_PERMANENT);

$retryWithoutUrl = $call('POST', '/api/coordinator/notifications/outbox/' . $webhookFailed->getId() . '/retry', token: $coordinatorToken);
$assert(
    '4.1 Reintentar un Webhook sin URL vigente => 422 WEBHOOK_URL_NOT_CONFIGURED',
    $retryWithoutUrl->getStatusCode() === 422
        && ($json($retryWithoutUrl)['error']['code'] ?? '') === 'WEBHOOK_URL_NOT_CONFIGURED',
    "Status obtenido: {$retryWithoutUrl->getStatusCode()}"
);
$assert(
    '4.2 El rechazo no altera la fila: sigue en FAILED_PERMANENT con su destino original',
    (string)$outboxRow((int)$webhookFailed->getId())['status'] === 'FAILED_PERMANENT'
        && (string)$outboxRow((int)$webhookFailed->getId())['recipient'] === 'https://hooks.example.org/servicio/antiguo'
);

$newWebhookUrl = 'https://hooks.example.org/services/T000/B000/CORREGIDO';
$putSettings = $call('PUT', '/api/coordinator/notifications/settings', [
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => $newWebhookUrl,
    'is_webhook_enabled' => true,
], $coordinatorToken);
$assert(
    '4.3 Coordinación corrige la URL del canal corporativo',
    $putSettings->getStatusCode() === 200
        && $settingsRepo->findCurrent()->getWebhookUrl() === $newWebhookUrl,
    "Status obtenido: {$putSettings->getStatusCode()}"
);

$retryWebhook = $call('POST', '/api/coordinator/notifications/outbox/' . $webhookFailed->getId() . '/retry', token: $coordinatorToken);
$retryWebhookBody = $json($retryWebhook);
$assert(
    '4.4 El reintento del Webhook toma la URL vigente recién guardada',
    $retryWebhook->getStatusCode() === 200
        && ($retryWebhookBody['data']['recipient'] ?? '') === $newWebhookUrl
        && (string)$outboxRow((int)$webhookFailed->getId())['recipient'] === $newWebhookUrl,
    "Status obtenido: {$retryWebhook->getStatusCode()}"
);

// Una fila heredada de una prueba anterior puede seguir en FAILED_PERMANENT con un
// contacto ya corregido: el lote debe reencolarla y omitir los Webhook sin URL.
$secondFailed = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, 'rebote.masivo@sede.vendguard.es', NotificationStatus::FAILED_PERMANENT, (int)$location->getId());
$retryAllResponse = $call('POST', '/api/coordinator/notifications/outbox/retry-all-failed', token: $coordinatorToken);
$retryAllBody = $json($retryAllResponse);
$retriedIds = $retryAllBody['data']['retried_ids'] ?? [];
$assert(
    '4.5 POST /retry-all-failed reencola los fallos definitivos con destino vigente',
    $retryAllResponse->getStatusCode() === 200
        && in_array((int)$secondFailed->getId(), $retriedIds, true)
        && ($retryAllBody['data']['retried_count'] ?? 0) === count($retriedIds)
        && (string)$outboxRow((int)$secondFailed->getId())['recipient'] === $correctedEmail,
    "Status obtenido: {$retryAllResponse->getStatusCode()}"
);
$assert(
    '4.6 El lote deja constancia auditada de cada reencolado',
    $auditCount('NOTIFICATION_MANUAL_RETRY') >= 3
);

echo "\n--- 5. Cancelación manual (RF-06.4) ---\n";

$cancelReason = 'Cancelación de certificación T-NOTIF-11 tras confirmar el cierre de la sede.';
$cancelResponse = $call('POST', '/api/coordinator/notifications/outbox/' . $pendingItem->getId() . '/cancel', [
    'reason' => $cancelReason,
], $coordinatorToken);
$cancelBody = $json($cancelResponse);
$cancelledRow = $outboxRow((int)$pendingItem->getId());
$assert(
    '5.1 POST /{id}/cancel sobre PENDING => 200 y CANCELLED_OBSOLETE con el motivo',
    $cancelResponse->getStatusCode() === 200
        && ($cancelBody['data']['status'] ?? '') === 'CANCELLED_OBSOLETE'
        && ($cancelBody['data']['obsolete_reason'] ?? '') === $cancelReason
        && (string)$cancelledRow['status'] === 'CANCELLED_OBSOLETE'
        && (string)$cancelledRow['obsolete_reason'] === $cancelReason,
    "Status obtenido: {$cancelResponse->getStatusCode()}"
);
$assert(
    '5.2 La cancelación manual queda auditada como NOTIFICATION_MANUAL_CANCEL',
    $auditCount('NOTIFICATION_MANUAL_CANCEL', (int)$pendingItem->getId()) === 1
);

$cancelProcessing = $call('POST', '/api/coordinator/notifications/outbox/' . $processingItem->getId() . '/cancel', token: $coordinatorToken);
$assert(
    '5.3 Cancelar un envío en PROCESSING => 409 INVALID_OUTBOX_STATUS_FOR_CANCEL',
    $cancelProcessing->getStatusCode() === 409
        && ($json($cancelProcessing)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_CANCEL',
    "Status obtenido: {$cancelProcessing->getStatusCode()}"
);

$cancelSent = $call('POST', '/api/coordinator/notifications/outbox/' . $sentItem->getId() . '/cancel', token: $coordinatorToken);
$assert(
    '5.4 Cancelar un envío ya entregado => 409 INVALID_OUTBOX_STATUS_FOR_CANCEL',
    $cancelSent->getStatusCode() === 409
        && ($json($cancelSent)['error']['code'] ?? '') === 'INVALID_OUTBOX_STATUS_FOR_CANCEL',
    "Status obtenido: {$cancelSent->getStatusCode()}"
);

$cancelMissing = $call('POST', '/api/coordinator/notifications/outbox/999999/cancel', token: $coordinatorToken);
$assert(
    '5.5 Cancelar una notificación inexistente => 404 OUTBOX_ITEM_NOT_FOUND',
    $cancelMissing->getStatusCode() === 404
        && ($json($cancelMissing)['error']['code'] ?? '') === 'OUTBOX_ITEM_NOT_FOUND',
    "Status obtenido: {$cancelMissing->getStatusCode()}"
);

echo "\n--- 6. Bloqueo anti-SSRF en configuración y prueba del Webhook (RF-06.5) ---\n";

$rejectedUrls = [
    'http://hooks.example.org/servicio' => 'INVALID_WEBHOOK_URL_SCHEME',
    'https://localhost/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://127.0.0.1/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://10.0.0.5/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://172.20.3.4/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://192.168.1.10/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://169.254.169.254/latest/meta-data' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
    'https://[::1]/servicio' => 'FORBIDDEN_WEBHOOK_INTERNAL_HOST',
];

$antiSsrfOk = true;
$antiSsrfDetail = '';
foreach ($rejectedUrls as $url => $expectedCode) {
    $response = $call('PUT', '/api/coordinator/notifications/settings', [
        'webhook_name' => 'Canal de certificación anti-SSRF',
        'webhook_url' => $url,
        'is_webhook_enabled' => true,
    ], $coordinatorToken);
    $body = $json($response);

    if ($response->getStatusCode() !== 422 || ($body['error']['code'] ?? '') !== $expectedCode) {
        $antiSsrfOk = false;
        $antiSsrfDetail = "{$url} => HTTP {$response->getStatusCode()} · " . (string)($body['error']['code'] ?? 'sin código');
    }
}
$assert('6.1 PUT /settings rechaza http, loopback y rangos privados con 422', $antiSsrfOk, $antiSsrfDetail);
$assert(
    '6.2 Un destino rechazado no sobrescribe la configuración vigente',
    $settingsRepo->findCurrent()->getWebhookUrl() === $newWebhookUrl
);

$webhookOkTransport = new InMemoryNotificationTransport(
    NotificationChannel::WEBHOOK,
    TransportDeliveryResult::success(200)
);
$webhookFailTransport = new InMemoryNotificationTransport(
    NotificationChannel::WEBHOOK,
    TransportDeliveryResult::permanentFailure('El destino respondió con HTTP 403: canal no autorizado.', 403)
);
$emailTransport = new InMemoryNotificationTransport(NotificationChannel::EMAIL);

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

$testAntiSsrfOk = true;
$testAntiSsrfDetail = '';
foreach ($rejectedUrls as $url => $expectedCode) {
    $controller = new CoordinatorNotificationController($buildService($webhookOkTransport));
    $response = $controller->testWebhook($testRequest(['webhook_url' => $url]));
    $body = $json($response);

    if ($response->getStatusCode() !== 422 || ($body['error']['code'] ?? '') !== $expectedCode) {
        $testAntiSsrfOk = false;
        $testAntiSsrfDetail = "{$url} => HTTP {$response->getStatusCode()} · " . (string)($body['error']['code'] ?? 'sin código');
    }
}
$assert('6.3 «Probar conexión» aplica el mismo blindaje anti-SSRF', $testAntiSsrfOk, $testAntiSsrfDetail);

$okController = new CoordinatorNotificationController($buildService($webhookOkTransport));
$okResponse = $okController->testWebhook($testRequest([
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => 'https://hooks.example.org/services/T000/B000/CORREGIDO',
]));
$okBody = $json($okResponse)['data'] ?? [];
$assert(
    '6.4 Un destino público que acepta => 200 test_passed true con diagnóstico',
    $okResponse->getStatusCode() === 200
        && ($okBody['test_passed'] ?? false) === true
        && ($okBody['status'] ?? '') === 'SUCCESS'
        && ($okBody['http_code'] ?? 0) === 200
        && array_key_exists('duration_ms', $okBody),
    "Status obtenido: {$okResponse->getStatusCode()}"
);
$assert(
    '6.5 La prueba exitosa se persiste y se audita como NOTIFICATION_WEBHOOK_TESTED',
    $settingsRepo->findCurrent()->getLastTestStatus() === 'SUCCESS'
        && $settingsRepo->findCurrent()->getLastTestError() === null
        && $auditCount('NOTIFICATION_WEBHOOK_TESTED') >= 1
);

$failController = new CoordinatorNotificationController($buildService($webhookFailTransport));
$failResponse = $failController->testWebhook($testRequest([
    'webhook_name' => 'Canal roto',
    'webhook_url' => 'https://hooks.example.org/servicio-roto',
]));
$failBody = $json($failResponse)['data'] ?? [];
$assert(
    '6.6 Un rechazo remoto informa el diagnóstico sin convertir la prueba en error HTTP',
    $failResponse->getStatusCode() === 200
        && ($failBody['test_passed'] ?? true) === false
        && ($failBody['status'] ?? '') === 'FAILED'
        && ($failBody['http_code'] ?? 0) === 403
        && ($failBody['error'] ?? '') !== ''
        && $settingsRepo->findCurrent()->getLastTestStatus() === 'FAILED',
    "Status obtenido: {$failResponse->getStatusCode()}"
);

echo "\n--- 7. Técnico de guardia y limpieza al dar de baja (RF-02.5, RF-02.6) ---\n";

$technicianId = (int)$technician->getId();
$onCall = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '7.1 PATCH /users/{id}/on-call activa la guardia y el contador lo refleja',
    $onCall->getStatusCode() === 200
        && ($json($onCall)['data']['is_on_call'] ?? false) === true
        && $userRepo->findById($technicianId, onlyActive: false)->isOnCall() === true
        && $userRepo->countOnCallTechnicians() === 1,
    "Status obtenido: {$onCall->getStatusCode()}"
);

$offCall = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => false], $coordinatorToken);
$assert(
    '7.2 PATCH /users/{id}/on-call con false retira la guardia',
    $offCall->getStatusCode() === 200
        && ($json($offCall)['data']['is_on_call'] ?? true) === false
        && $userRepo->countOnCallTechnicians() === 0,
    "Status obtenido: {$offCall->getStatusCode()}"
);

$onCoordinator = $call('PATCH', "/api/coordinator/users/{$coordinatorId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '7.3 Marcar guardia sobre un Coordinador => 422 ON_CALL_ONLY_FOR_TECHNICIANS',
    $onCoordinator->getStatusCode() === 422
        && ($json($onCoordinator)['error']['code'] ?? '') === 'ON_CALL_ONLY_FOR_TECHNICIANS',
    "Status obtenido: {$onCoordinator->getStatusCode()}"
);

$badType = $call('PATCH', "/api/coordinator/users/{$technicianId}/on-call", ['is_on_call' => 'true'], $coordinatorToken);
$assert(
    '7.4 Un is_on_call en texto => 400 VALIDATION_ERROR (no se convierte en guardia)',
    $badType->getStatusCode() === 400
        && ($json($badType)['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "Status obtenido: {$badType->getStatusCode()}"
);

$onMissingUser = $call('PATCH', '/api/coordinator/users/999999/on-call', ['is_on_call' => true], $coordinatorToken);
$assert(
    '7.5 Marcar guardia sobre un usuario inexistente => 404 USER_NOT_FOUND',
    $onMissingUser->getStatusCode() === 404
        && ($json($onMissingUser)['error']['code'] ?? '') === 'USER_NOT_FOUND',
    "Status obtenido: {$onMissingUser->getStatusCode()}"
);

$auxTechnician = $userRepo->findById(3, true);
$assert(
    '7.6 El parque sembrado dispone de un segundo técnico activo para la prueba de baja',
    $auxTechnician !== null && $auxTechnician->getRole()->value === 'TECHNICIAN'
);

if ($auxTechnician === null) {
    echo "ERROR: no se pudo preparar el escenario de baja.\n";
    exit(1);
}

$auxId = (int)$auxTechnician->getId();
$markAux = $call('PATCH', "/api/coordinator/users/{$auxId}/on-call", ['is_on_call' => true], $coordinatorToken);
$deactivateAux = $call('PATCH', "/api/coordinator/users/{$auxId}/deactivate", token: $coordinatorToken);
$auxState = $pdo->prepare('SELECT is_active, is_on_call, deleted_at FROM users WHERE id = :id');
$auxState->execute([':id' => $auxId]);
$auxRow = $auxState->fetch(PDO::FETCH_ASSOC);
$assert(
    '7.7 La baja del técnico retira la marca de guardia de forma atómica (RF-02.6)',
    $markAux->getStatusCode() === 200
        && $deactivateAux->getStatusCode() === 200
        && $auxRow !== false
        && (bool)$auxRow['is_active'] === false
        && (bool)$auxRow['is_on_call'] === false
        && $auxRow['deleted_at'] !== null
        && $userRepo->countOnCallTechnicians() === 0,
    "Status de baja: {$deactivateAux->getStatusCode()}"
);

$onInactive = $call('PATCH', "/api/coordinator/users/{$auxId}/on-call", ['is_on_call' => true], $coordinatorToken);
$assert(
    '7.8 Activar la guardia de una cuenta dada de baja => 409 CANNOT_SET_ON_CALL_INACTIVE_USER',
    $onInactive->getStatusCode() === 409
        && ($json($onInactive)['error']['code'] ?? '') === 'CANNOT_SET_ON_CALL_INACTIVE_USER',
    "Status obtenido: {$onInactive->getStatusCode()}"
);
$assert(
    '7.9 Cada cambio de guardia queda auditado como USER_ON_CALL_UPDATED',
    $auditCount('USER_ON_CALL_UPDATED') >= 3
);

// La cuenta auxiliar es del catálogo sembrado: se devuelve a su estado canónico para
// que el arnés no quede con residuo de parque entre suites (contrato de limpieza §8.3).
$reactivateAux = $call('PATCH', "/api/coordinator/users/{$auxId}/reactivate", token: $coordinatorToken);
$restoredAux = $userRepo->findById($auxId, onlyActive: false);
$assert(
    '7.10 La cuenta se restituye activa y sin guardia al cierre del escenario',
    $reactivateAux->getStatusCode() === 200
        && $restoredAux !== null
        && $restoredAux->isActive() === true
        && $restoredAux->isOnCall() === false,
    "Status obtenido: {$reactivateAux->getStatusCode()}"
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
