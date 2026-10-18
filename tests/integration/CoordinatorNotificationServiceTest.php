<?php

declare(strict_types=1);

/**
 * CoordinatorNotificationServiceTest
 *
 * Certificación de integración de T-NOTIF-07 sobre el motor único PostgreSQL:
 * el «Hecho cuando» exige que `CoordinatorNotificationService` gestione el
 * listado y los KPIs de supervisión, el reintento manual individual y masivo
 * refrescando el `contact_email` de la Sede o el `webhook_url` vigente, la
 * cancelación manual, la actualización del Canal Corporativo de Emergencias con
 * su blindaje anti-SSRF y la prueba directa `testWebhook()` con timeout de 5
 * segundos, registrando todo en `audit_log`.
 *
 * Las filas de la bandeja y las de `audit_log` se leen del PostgreSQL real; el
 * canal Webhook se ejercita con el adaptador determinista del arnés, de modo que
 * la suite certifica el veredicto del servicio sin abrir un solo socket.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorNotificationService;
use VendGuard\Core\Domain\Exception\InvalidNotificationOutboxStatusException;
use VendGuard\Core\Domain\Exception\WebhookConfigurationException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Core\Service\NotificationTrackingLinkResolver;
use VendGuard\Infrastructure\Notification\Transport\InMemoryNotificationTransport;
use VendGuard\Infrastructure\Notification\Transport\NativeHttpsWebhookTransport;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportInterface;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;
use VendGuard\Infrastructure\Notification\Transport\TransportDeliveryResult;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - CoordinatorNotificationServiceTest (T-NOTIF-07)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

// `audit_log` es inmutable y no se purga: las certificaciones se miden sólo sobre
// los eventos escritos por ESTA ejecución, con la marca de agua del registro.
$auditFloor = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

$outbox = new PdoNotificationOutboxRepository($pdo);
$settings = new PdoNotificationSettingsRepository($pdo, $outbox);
$locations = new PdoLocationRepository($pdo);
$users = new PdoUserRepository($pdo);
$machines = new PdoMachineRepository($pdo);
$incidents = new PdoIncidentRepository($pdo);

$emailTransport = new InMemoryNotificationTransport(NotificationChannel::EMAIL);
$webhookTransport = new InMemoryNotificationTransport(NotificationChannel::WEBHOOK);
$transports = new NotificationTransportRegistry($emailTransport, $webhookTransport);

$service = new CoordinatorNotificationService(
    outbox: $outbox,
    locations: $locations,
    settings: $settings,
    onCallTechnicians: $users,
    transports: $transports,
    audit: new AuditLogger()
);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
    }
};

$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 1);
$actor = ['id' => $coordinatorId, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Certificación'];

$outboxRow = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM notification_outbox WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException("La fila {$id} de la bandeja no existe.");
    }

    return $row;
};

$auditCount = function (string $action, int $entityId) use ($pdo, $auditFloor): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action AND entity_id = :entity_id AND id > :floor');
    $stmt->execute([':action' => $action, ':entity_id' => $entityId, ':floor' => $auditFloor]);

    return (int)$stmt->fetchColumn();
};

$enqueue = function (
    NotificationChannel $channel,
    NotificationPriority $priority,
    NotificationTemplate $template,
    string $recipient,
    string $role,
    ?int $incidentId = null,
    ?int $recipientUserId = null,
    ?int $recipientLocationId = null,
    array $payload = ['ticket_code' => 'TICK-NOTIF07']
) use ($outbox): NotificationOutboxItem {
    $item = $outbox->enqueue(new NotificationOutboxItem(
        id: null,
        incidentId: $incidentId,
        channel: $channel,
        priority: $priority,
        template: $template,
        recipient: $recipient,
        recipientRole: $role,
        recipientUserId: $recipientUserId,
        recipientLocationId: $recipientLocationId,
        subject: 'Certificación T-NOTIF-07 · ' . $template->value,
        payload: $payload
    ));

    if ($item === null || $item->getId() === null) {
        throw new RuntimeException('No se pudo encolar la notificación de certificación.');
    }

    return $item;
};

$forceStatus = function (int $id, string $status, int $attempts = 0) use ($pdo): void {
    $stmt = $pdo->prepare("
        UPDATE notification_outbox
        SET status = :status,
            attempts = :attempts,
            processed_at = CASE WHEN :status IN ('SENT', 'FAILED_PERMANENT') THEN CURRENT_TIMESTAMP ELSE processed_at END
        WHERE id = :id
    ");
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. RF-06.3 — Bandeja, KPIs y filtros de supervisión
// ─────────────────────────────────────────────────────────────────────────────
$machineId = (int)$pdo->query('SELECT id FROM machines WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1')->fetchColumn();
$machine = $machines->findById($machineId);
$incident = $incidents->create(new Incident(
    id: null,
    ticketCode: TicketCode::generate()->value(),
    machineId: $machine->getId(),
    locationId: $machine->getLocationId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Avería de certificación de la supervisión (T-NOTIF-07).',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::REGISTERED,
    reporterName: 'Informador de Certificación',
    reporterPhone: '600555666'
), null, 'Alta de certificación T-NOTIF-07');
$incidentId = (int)$incident->getId();
$ticketCode = (string)$incident->getTicketCode();

$pendingItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_OPENED_SITE, 'lista.pendiente.tnotif07@example.org', 'LOCATION', $incidentId);
$failedItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_OPENED_SITE, 'lista.fallida.tnotif07@example.org', 'LOCATION');
$webhookItem = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::CRITICAL_SANITARY, NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT, 'https://emergencias.example.org/hook-tnotif07-list', 'WEBHOOK');
$forceStatus((int)$failedItem->getId(), 'FAILED_PERMANENT', 5);

$listing = $service->listOutbox([], 10, 0);

$assert('RF-06.3: la bandeja publica los contadores KPI de los seis estados', $listing['summary']['pending_count'] === 2 && $listing['summary']['failed_permanent_count'] === 1);
$assert(
    'La insignia de alerta se enciende con el fallo definitivo y la cola cuenta los envíos vivos',
    $listing['summary']['has_permanent_failures_alert'] === true
        && $listing['summary']['in_queue_count'] === 2
        && $listing['summary']['cancelled_obsolete_count'] === 0
);
$assert(
    'La paginación declara total, límite y desplazamiento',
    $listing['pagination']['total'] === 3 && $listing['pagination']['limit'] === 10 && $listing['pagination']['offset'] === 0
);

$presentedFailed = null;
foreach ($listing['items'] as $item) {
    if ((int)$item['id'] === (int)$failedItem->getId()) {
        $presentedFailed = $item;
    }
}
$assert('La fila fallida viaja con su etiqueta de estado en castellano y su ticket', $presentedFailed !== null && $presentedFailed['status_label'] === 'Fallo definitivo' && $presentedFailed['ticket_code'] === null);
$assert(
    'La fila fallida declara que admite reintento y cancelación manuales',
    $presentedFailed !== null && $presentedFailed['can_retry'] === true && $presentedFailed['can_cancel'] === true
        && $presentedFailed['template_label'] === 'Confirmación de Registro de Avería (Sede)'
);

$byStatus = $service->listOutbox(['status' => 'FAILED_PERMANENT'], 10, 0);
$byChannel = $service->listOutbox(['channel' => 'WEBHOOK'], 10, 0);
$byPriority = $service->listOutbox(['priority' => 'CRITICAL_SANITARY'], 10, 0);
$byTicket = $service->listOutbox(['ticket_code' => substr($ticketCode, 0, 9)], 10, 0);

$assert('El filtro por estado reduce la tabla al fallo definitivo', $byStatus['pagination']['total'] === 1 && (int)$byStatus['items'][0]['id'] === (int)$failedItem->getId());
$assert('El filtro por canal aísla el canal corporativo', $byChannel['pagination']['total'] === 1 && $byChannel['items'][0]['channel'] === 'WEBHOOK');
$assert('El filtro por prioridad aísla la emergencia sanitaria (Art. II)', $byPriority['pagination']['total'] === 1 && $byPriority['items'][0]['priority'] === 'CRITICAL_SANITARY');
$assert('El filtro por código de ticket encuentra el expediente por búsqueda parcial', $byTicket['pagination']['total'] === 1 && $byTicket['items'][0]['ticket_code'] === $ticketCode);

// ─────────────────────────────────────────────────────────────────────────────
// 1.b RF-06.3 — La bandeja publica la URL final del botón, para revisarla antes
//     de reintentar. Sale de la misma autoridad que la del envío.
// ─────────────────────────────────────────────────────────────────────────────
$siteCodeForLink = (string)($locations->findById($machine->getLocationId())?->getSiteCode() ?? 'SEDE-LINK');
$machineCodeForLink = (string)$machine->getCode();

$siteLinkItem = $enqueue(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::INCIDENT_OPENED_SITE,
    'enlace.sede.tnotif07@example.org',
    'LOCATION',
    null,
    null,
    null,
    [
        'ticket_code' => $ticketCode,
        'site_code' => $siteCodeForLink,
        'tracking_url' => '/?site=' . rawurlencode($siteCodeForLink) . '&ticket=' . rawurlencode($ticketCode),
    ]
);
$citizenLinkItem = $enqueue(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR,
    'enlace.ciudadano.tnotif07@example.org',
    'CITIZEN_QR',
    null,
    null,
    null,
    [
        'ticket_code' => $ticketCode,
        'machine_code' => $machineCodeForLink,
        'tracking_url' => '/?qr=' . rawurlencode($machineCodeForLink),
    ]
);
$internalLinkItem = $enqueue(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::TECHNICIAN_ASSIGNED,
    'tecnico.enlace.tnotif07@example.org',
    'TECHNICIAN'
);

$previousPublicBase = getenv(AppPublicUrl::ENV_NAME);
putenv(AppPublicUrl::ENV_NAME);

$linkService = new CoordinatorNotificationService(
    outbox: $outbox,
    locations: $locations,
    settings: $settings,
    onCallTechnicians: $users,
    transports: $transports,
    audit: new AuditLogger(),
    trackingLinks: new NotificationTrackingLinkResolver(new AppPublicUrl('https://vendguard.onrender.com'))
);
$linkRows = [];
foreach ($linkService->listOutbox([], 50, 0)['items'] as $row) {
    $linkRows[(int)$row['id']] = $row;
}

$expectedSiteUrl = 'https://vendguard.onrender.com/?site=' . rawurlencode($siteCodeForLink) . '&ticket=' . rawurlencode($ticketCode);
$assert(
    'RF-06.3: la fila de Sede publica la URL final absoluta que llevará el correo',
    $linkRows[(int)$siteLinkItem->getId()]['tracking_url'] === $expectedSiteUrl,
    'recibido: ' . var_export($linkRows[(int)$siteLinkItem->getId()]['tracking_url'] ?? null, true)
);
$assert(
    'La fila del Ciudadano QR publica su enlace público acotado, también absoluto',
    $linkRows[(int)$citizenLinkItem->getId()]['tracking_url']
        === 'https://vendguard.onrender.com/?qr=' . rawurlencode($machineCodeForLink)
);
$assert(
    'El aviso interno no publica enlace: no lleva botón',
    $linkRows[(int)$internalLinkItem->getId()]['tracking_url'] === null
);
$assert(
    'El canal Webhook no publica enlace de seguimiento de correo',
    $linkRows[(int)$webhookItem->getId()]['tracking_url'] === null
);
$assert(
    'Sin códigos en el payload no se inventa una URL',
    $linkRows[(int)$failedItem->getId()]['tracking_url'] === null
);

$noHostService = new CoordinatorNotificationService(
    outbox: $outbox,
    locations: $locations,
    settings: $settings,
    onCallTechnicians: $users,
    transports: $transports,
    audit: new AuditLogger(),
    trackingLinks: new NotificationTrackingLinkResolver(new AppPublicUrl(null))
);
$noHostRows = [];
foreach ($noHostService->listOutbox([], 50, 0)['items'] as $row) {
    $noHostRows[(int)$row['id']] = $row;
}
$assert(
    'Sin host público declarado la bandeja enseña la ruta tal cual (degradación honesta)',
    $noHostRows[(int)$siteLinkItem->getId()]['tracking_url']
        === '/?site=' . rawurlencode($siteCodeForLink) . '&ticket=' . rawurlencode($ticketCode)
);

if ($previousPublicBase === false) {
    putenv(AppPublicUrl::ENV_NAME);
} else {
    putenv(AppPublicUrl::ENV_NAME . '=' . $previousPublicBase);
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. RF-06.4 — Reintento individual refrescando el correo vigente de la Sede
// ─────────────────────────────────────────────────────────────────────────────
$locationId = (int)$machine->getLocationId();
$newLocationEmail = 'nuevo.correo.sede.tnotif07@example.org';
$locations->updateContactEmail($locationId, $newLocationEmail);

$staleLocationItem = $enqueue(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::INCIDENT_OPENED_SITE,
    'viejo.correo.sede.tnotif07@example.org',
    'LOCATION',
    null,
    null,
    $locationId
);
$forceStatus((int)$staleLocationItem->getId(), 'FAILED_PERMANENT', 5);

$retried = $service->retryItem((int)$staleLocationItem->getId(), $actor);
$retriedRow = $outboxRow((int)$staleLocationItem->getId());

$assert(
    'RF-06.4: el reintento manual refresca el destinatario con el contact_email vigente',
    $retried['recipient'] === $newLocationEmail && (string)$retriedRow['recipient'] === $newLocationEmail
);
$assert(
    'El envío vuelve a PENDING con intentos a cero y programado de inmediato',
    $retried['status'] === 'PENDING'
        && (int)$retriedRow['attempts'] === 0
        && $retriedRow['last_error'] === null
        && $retriedRow['next_attempt_at'] !== null
);
$assert(
    'El reintento manual queda auditado sobre la entidad NOTIFICATION_OUTBOX',
    $auditCount('NOTIFICATION_MANUAL_RETRY', (int)$staleLocationItem->getId()) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. RF-06.4 — Webhook sin URL vigente: 422 sin quemar intentos
// ─────────────────────────────────────────────────────────────────────────────
$disabled = $settings->findCurrent();
$disabled->updateConfiguration('Canal de certificación T-NOTIF-07', null, false, $coordinatorId);
$settings->save($disabled);

$staleWebhookItem = $enqueue(
    NotificationChannel::WEBHOOK,
    NotificationPriority::CRITICAL_SANITARY,
    NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    'https://emergencias.example.org/hook-tnotif07-stale',
    'WEBHOOK'
);
$forceStatus((int)$staleWebhookItem->getId(), 'FAILED_PERMANENT', 5);

$webhookError = null;
try {
    $service->retryItem((int)$staleWebhookItem->getId(), $actor);
} catch (WebhookConfigurationException $exception) {
    $webhookError = $exception;
}

$assert(
    'RF-06.4: un Webhook sin URL configurada se rechaza con WEBHOOK_URL_NOT_CONFIGURED (422)',
    $webhookError !== null
        && $webhookError->getErrorCode() === WebhookConfigurationException::CODE_NOT_CONFIGURED
        && $webhookError->getHttpStatusCode() === 422
);
$assert(
    'El envío rechazado permanece intacto en FAILED_PERMANENT',
    (string)$outboxRow((int)$staleWebhookItem->getId())['status'] === 'FAILED_PERMANENT'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. RF-06.4 — Reintento manual masivo con omisión del Webhook sin URL
// ─────────────────────────────────────────────────────────────────────────────
$bulkA = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_OPENED_SITE, 'bulk.a.tnotif07@example.org', 'LOCATION');
$bulkB = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_RESOLVED_SITE, 'bulk.b.tnotif07@example.org', 'LOCATION');
$forceStatus((int)$bulkA->getId(), 'FAILED_PERMANENT', 4);
$forceStatus((int)$bulkB->getId(), 'FAILED_PERMANENT', 2);

$bulk = $service->retryAllFailed($actor);
$expectedRetried = [(int)$failedItem->getId(), (int)$bulkA->getId(), (int)$bulkB->getId()];
sort($expectedRetried);
sort($bulk['retried_ids']);

$assert(
    'RF-06.4: el reintento masivo reencola los fallos con destinatario vigente',
    $bulk['retried_count'] === 3 && $bulk['retried_ids'] === $expectedRetried,
    'Reencoladas: ' . implode(', ', $bulk['retried_ids'])
);
$assert(
    'Cada envío del lote masivo vuelve a PENDING con su correo intacto',
    (string)$outboxRow((int)$bulkA->getId())['status'] === 'PENDING'
        && (string)$outboxRow((int)$bulkA->getId())['recipient'] === 'bulk.a.tnotif07@example.org'
        && (string)$outboxRow((int)$failedItem->getId())['status'] === 'PENDING'
);
$assert(
    'El lote masivo omite el Webhook sin URL y lo deja visible en la bandeja',
    !in_array((int)$staleWebhookItem->getId(), $bulk['retried_ids'], true)
        && (string)$outboxRow((int)$staleWebhookItem->getId())['status'] === 'FAILED_PERMANENT'
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. RF-06.4 — Cancelación manual con motivo y bloqueo de estados no cancelables
// ─────────────────────────────────────────────────────────────────────────────
$cancelReason = 'Cancelación de certificación T-NOTIF-07 tras contacto telefónico.';
$cancelled = $service->cancelItem((int)$staleWebhookItem->getId(), $cancelReason, $actor);
$cancelledRow = $outboxRow((int)$staleWebhookItem->getId());

$assert(
    'RF-06.4: la cancelación manual pasa el envío a CANCELLED_OBSOLETE con su motivo',
    $cancelled['status'] === 'CANCELLED_OBSOLETE'
        && $cancelled['obsolete_reason'] === $cancelReason
        && (string)$cancelledRow['obsolete_reason'] === $cancelReason
);
$assert(
    'La cancelación manual queda auditada',
    $auditCount('NOTIFICATION_MANUAL_CANCEL', (int)$staleWebhookItem->getId()) === 1
);

$defaultCancelItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_OPENED_SITE, 'cancelada.defecto.tnotif07@example.org', 'LOCATION');
$service->cancelItem((int)$defaultCancelItem->getId(), null, $actor);
$assert(
    'Sin motivo indicado se documenta el motivo por defecto de Coordinación',
    (string)$outboxRow((int)$defaultCancelItem->getId())['obsolete_reason'] === CoordinatorNotificationService::DEFAULT_CANCELLATION_REASON
);

$sentItem = $enqueue(NotificationChannel::EMAIL, NotificationPriority::STANDARD, NotificationTemplate::INCIDENT_OPENED_SITE, 'ya.enviada.tnotif07@example.org', 'LOCATION');
$forceStatus((int)$sentItem->getId(), 'SENT', 1);

$sentCancelError = null;
try {
    $service->cancelItem((int)$sentItem->getId(), null, $actor);
} catch (InvalidNotificationOutboxStatusException $exception) {
    $sentCancelError = $exception;
}

$assert(
    'RF-06.4: un envío ya entregado no admite cancelación manual (409)',
    $sentCancelError !== null
        && $sentCancelError->getErrorCode() === InvalidNotificationOutboxStatusException::ERROR_CODE_CANCEL
        && $sentCancelError->getHttpStatusCode() === 409
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. RF-06.5 — Configuración del Webhook con blindaje anti-SSRF
// ─────────────────────────────────────────────────────────────────────────────
$schemeError = null;
try {
    $service->updateSettings(['webhook_url' => 'http://emergencias.example.org/hook', 'is_webhook_enabled' => true], $actor);
} catch (WebhookConfigurationException $exception) {
    $schemeError = $exception;
}
$assert(
    'RF-06.5.1: una URL sin HTTPS se rechaza con INVALID_WEBHOOK_URL_SCHEME (422)',
    $schemeError !== null
        && $schemeError->getErrorCode() === WebhookConfigurationException::CODE_INVALID_SCHEME
);

$hostError = null;
try {
    $service->updateSettings(['webhook_url' => 'https://127.0.0.1/hook', 'is_webhook_enabled' => true], $actor);
} catch (WebhookConfigurationException $exception) {
    $hostError = $exception;
}
$assert(
    'RF-06.5.1: un host privado se rechaza con FORBIDDEN_WEBHOOK_INTERNAL_HOST (422)',
    $hostError !== null
        && $hostError->getErrorCode() === WebhookConfigurationException::CODE_FORBIDDEN_HOST
);

$requiredError = null;
try {
    $service->updateSettings(['webhook_name' => 'Canal sin URL', 'is_webhook_enabled' => true], $actor);
} catch (WebhookConfigurationException $exception) {
    $requiredError = $exception;
}
$assert(
    'RF-06.5.1: activar el canal sin URL se rechaza con WEBHOOK_URL_REQUIRED (422)',
    $requiredError !== null
        && $requiredError->getErrorCode() === WebhookConfigurationException::CODE_URL_REQUIRED
);

$savedSettings = $service->updateSettings([
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => 'https://hooks.slack.com/services/T000/B000/X000',
    'is_webhook_enabled' => true,
], $actor);

$assert(
    'RF-06.5: una URL https pública se persiste con su nombre de canal',
    $savedSettings['webhook_name'] === 'Slack #emergencias-frio'
        && $savedSettings['webhook_url'] === 'https://hooks.slack.com/services/T000/B000/X000'
        && $savedSettings['is_webhook_enabled'] === true
);
$assert(
    'El cambio de configuración queda auditado en NOTIFICATION_CHANNEL_SETTING',
    $auditCount('NOTIFICATION_WEBHOOK_UPDATED', (int)$settings->findCurrent()->getId()) === 1
);

$pendingWebhook = $enqueue(NotificationChannel::WEBHOOK, NotificationPriority::CRITICAL_SANITARY, NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT, 'https://hooks.slack.com/services/T000/B000/X000', 'WEBHOOK');
$service->updateSettings(['is_webhook_enabled' => false], $actor);
$disabledRow = $outboxRow((int)$pendingWebhook->getId());

$assert(
    'RF-07.1.f: desactivar el canal cancela atómicamente los Webhooks pendientes',
    (string)$disabledRow['status'] === 'CANCELLED_OBSOLETE'
        && str_contains((string)$disabledRow['obsolete_reason'], 'desactivado')
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. RF-06.5.2 / RNF-03 — Prueba directa del Webhook con timeout de 5 segundos
// ─────────────────────────────────────────────────────────────────────────────
$service->updateSettings([
    'webhook_name' => 'Slack #emergencias-frio',
    'webhook_url' => 'https://hooks.slack.com/services/T000/B000/X000',
    'is_webhook_enabled' => true,
], $actor);

$testPassed = $service->testWebhook($actor);
$lastDelivery = $webhookTransport->lastDelivery();

$assert(
    'RF-06.5.2: la prueba directa confirma la conexión en menos de 5 segundos',
    $testPassed['test_passed'] === true
        && $testPassed['status'] === 'SUCCESS'
        && $testPassed['duration_ms'] >= 0
        && $testPassed['duration_ms'] < 5000
        && $testPassed['error'] === null
);
$assert(
    'El mensaje de prueba viaja identificado como [PRUEBA DE CONEXIÓN · VENDGUARD]',
    $lastDelivery !== null
        && str_contains($lastDelivery['message']->subject, '[PRUEBA DE CONEXIÓN · VENDGUARD]')
        && $lastDelivery['recipient'] === 'https://hooks.slack.com/services/T000/B000/X000'
);
$assert(
    'El resultado de la prueba queda persistido en la configuración del canal',
    $settings->findCurrent()->getLastTestStatus() === 'SUCCESS' && $settings->findCurrent()->getLastTestedAt() !== null
);
$assert(
    'La prueba de conexión queda auditada',
    $auditCount('NOTIFICATION_WEBHOOK_TESTED', (int)$settings->findCurrent()->getId()) === 1
);

$webhookTransport->scriptOutcomes(
    'https://hooks.slack.com/services/T000/B000/X000',
    TransportDeliveryResult::transientFailure('El Webhook respondió con HTTP 503.')
);

$testFailed = $service->testWebhook($actor);
$assert(
    'Una prueba fallida devuelve el diagnóstico sin abortar el panel',
    $testFailed['test_passed'] === false
        && $testFailed['status'] === 'FAILED'
        && str_contains((string)$testFailed['error'], '503')
        && $settings->findCurrent()->getLastTestStatus() === 'FAILED'
);

$service->updateSettings(['webhook_url' => null, 'is_webhook_enabled' => false], $actor);
$notConfigured = null;
try {
    $service->testWebhook($actor);
} catch (WebhookConfigurationException $exception) {
    $notConfigured = $exception;
}
$assert(
    'Sin URL vigente la prueba directa responde WEBHOOK_URL_NOT_CONFIGURED (422)',
    $notConfigured !== null && $notConfigured->getErrorCode() === WebhookConfigurationException::CODE_NOT_CONFIGURED
);

$timeoutCapped = false;
try {
    new NativeHttpsWebhookTransport(NotificationTransportInterface::TIMEOUT_SECONDS + 1);
} catch (InvalidArgumentException) {
    $timeoutCapped = true;
}
$assert(
    'RNF-03: el transporte nativo rechaza cualquier timeout superior al límite de 5 segundos',
    NotificationTransportInterface::TIMEOUT_SECONDS === 5 && $timeoutCapped
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. RF-02.5 / RF-06.5 — Técnicos de guardia activos en la configuración
// ─────────────────────────────────────────────────────────────────────────────
$users->setOnCall(2, true);
$settingsView = $service->getSettings();
$onCallIds = array_map(static fn(array $row): int => (int)$row['id'], $settingsView['on_call_technicians']);

$assert(
    'RF-06.5: la configuración publica los técnicos de guardia activos con su operador',
    in_array(2, $onCallIds, true)
        && in_array('OP-02', array_map(static fn(array $row): string => (string)$row['operator_code'], $settingsView['on_call_technicians']), true)
);
$assert(
    'El KPI de guardia de la bandeja refleja el técnico activo',
    $service->listOutbox([], 5, 0)['summary']['active_on_call_technicians_count'] >= 1
);

$users->setOnCall(2, false);

TestDataCleaner::purge($pdo);

echo "\n";
echo "──────────────────────────────────────────────────────────────────────\n";
echo sprintf(" Total Aserciones: %d | Fallos: %d\n", $assertions, $failures);
echo "──────────────────────────────────────────────────────────────────────\n";

exit($failures === 0 ? 0 : 1);
