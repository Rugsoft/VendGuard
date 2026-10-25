<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Exception\InvalidNotificationOutboxStatusException;
use VendGuard\Core\Domain\Exception\NotificationOutboxItemNotFoundException;
use VendGuard\Core\Domain\Exception\WebhookConfigurationException;
use VendGuard\Core\Domain\Model\NotificationChannelSettings;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Service\NotificationTrackingLinkResolver;
use VendGuard\Core\Service\WebhookUrlSecurityValidator;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;

/**
 * CoordinatorNotificationService — Supervisión del Centro de Notificaciones
 * (Módulo 14 · T-NOTIF-07).
 *
 * Es la autoridad de aplicación de RF-06.3, RF-06.4 y RF-06.5 sobre la bandeja de
 * salida: el controlador REST de Coordinación (T-NOTIF-08) sólo traduce a HTTP
 * los veredictos que aquí se calculan. El servicio concentra cuatro
 * responsabilidades:
 *
 *   1. **Bandeja y KPIs (RF-06.3).** Publica los contadores de los seis estados
 *      canónicos, la insignia de fallos definitivos y la tabla paginada con los
 *      filtros de estado, canal, prioridad y código de ticket. Los importes de
 *      fecha salen en ISO 8601 con zona para que el panel no adivine formatos.
 *      Cada fila de correo publica además su `tracking_url` **final y absoluta**
 *      —la misma que escribirá el despachador— para que Coordinación revise a
 *      dónde llevará el botón antes de reintentar el envío.
 *   2. **Reintento manual, individual y masivo (RF-06.4).** Antes de reencolar,
 *      **refresca el destinatario vigente**: si la notificación pertenecía a una
 *      Sede toma su `contact_email` actual y si era del canal corporativo toma el
 *      `webhook_url` configurado. Reintentar un correo a una dirección que la
 *      Sede ya corrigió no puede volver a rebotar. Un envío Webhook sin URL
 *      vigente se rechaza con `WEBHOOK_URL_NOT_CONFIGURED` en lugar de quemar
 *      intentos contra la nada.
 *   3. **Cancelación manual (RF-06.4).** Pasa a `CANCELLED_OBSOLETE` los envíos
 *      aún cancelables (`PENDING`, `PENDING_RETRY`, `FAILED_PERMANENT`) con el
 *      motivo indicado por Coordinación y sin borrar jamás la fila (Art. III.1).
 *   4. **Canal Corporativo de Emergencias (RF-06.5).** Valida la URL con el
 *      blindaje anti-SSRF, persiste la configuración y ejecuta la **prueba
 *      directa** con timeout estricto de 5 segundos: el resultado alimenta
 *      `last_tested_at`/`last_test_status`/`last_test_error` mediante
 *      `recordTestResult()` —nunca con `save()`, para no cancelar la cola
 *      pendiente al probar un canal desactivado— y queda trazado en `audit_log`.
 *
 * Toda acción de Coordinación (reintento, cancelación, cambio de configuración y
 * prueba de conexión) escribe su fila en `audit_log` con la entidad
 * `NOTIFICATION_OUTBOX` o `NOTIFICATION_CHANNEL_SETTING` (Art. III.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de puertos, cero dependencias.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class CoordinatorNotificationService
{
    /** Máximo de filas de la tabla de supervisión por página. */
    public const MAX_PAGE_SIZE = 200;

    /** Motivo por defecto cuando Coordinación cancela sin redactar uno. */
    public const DEFAULT_CANCELLATION_REASON = 'Cancelada manualmente por Coordinación.';

    private readonly AuditLogger $audit;
    private readonly NotificationTrackingLinkResolver $trackingLinks;

    public function __construct(
        private readonly NotificationOutboxRepositoryInterface $outbox,
        private readonly LocationRepositoryInterface $locations,
        private readonly NotificationSettingsRepositoryInterface $settings,
        private readonly OnCallTechnicianRepositoryInterface $onCallTechnicians,
        private readonly NotificationTransportRegistry $transports,
        ?AuditLogger $audit = null,
        ?NotificationTrackingLinkResolver $trackingLinks = null,
    ) {
        $this->audit = $audit ?? new AuditLogger();
        $this->trackingLinks = $trackingLinks ?? NotificationTrackingLinkResolver::fromEnvironment();
    }

    /**
     * Bandeja de salida con contadores KPI, filtros y paginación (RF-06.3).
     *
     * @param array<string, mixed> $filters Filtros opcionales: `status`, `channel`,
     *   `priority` y `ticket_code`. El valor `ALL` (o la ausencia de clave) no filtra.
     * @return array{
     *   summary: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   pagination: array{total: int, limit: int, offset: int}
     * }
     */
    public function listOutbox(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        $counters = $this->outbox->countByStatus();
        $rows = $this->outbox->findForCoordinator($normalizedFilters, $limit, $offset);

        return [
            'summary' => [
                'in_queue_count' => (int)($counters['in_queue'] ?? 0),
                'pending_count' => (int)($counters['PENDING'] ?? 0),
                'processing_count' => (int)($counters['PROCESSING'] ?? 0),
                'pending_retry_count' => (int)($counters['PENDING_RETRY'] ?? 0),
                'sent_last_24h_count' => (int)($counters['sent_last_24h'] ?? 0),
                'failed_permanent_count' => (int)($counters['FAILED_PERMANENT'] ?? 0),
                'cancelled_obsolete_count' => (int)($counters['CANCELLED_OBSOLETE'] ?? 0),
                'has_permanent_failures_alert' => ((int)($counters['FAILED_PERMANENT'] ?? 0)) > 0,
                'active_on_call_technicians_count' => $this->onCallTechnicians->countOnCallTechnicians(),
            ],
            'items' => array_map(fn(array $row): array => $this->presentRow($row), $rows),
            'pagination' => [
                'total' => $this->outbox->countForCoordinator($normalizedFilters),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Reintento manual individual con destinatario refrescado (RF-06.4).
     *
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return array<string, mixed> Proyección del envío ya reencolado.
     * @throws NotificationOutboxItemNotFoundException Si la notificación no existe.
     * @throws InvalidNotificationOutboxStatusException Si su estado no admite reintento.
     * @throws WebhookConfigurationException Si es Webhook y no hay URL vigente.
     */
    public function retryItem(int $id, array $actor): array
    {
        $item = $this->outbox->findById($id);
        if ($item === null) {
            throw new NotificationOutboxItemNotFoundException($id);
        }

        if (!in_array($item->getStatus(), [NotificationStatus::FAILED_PERMANENT, NotificationStatus::PENDING_RETRY], true)) {
            throw new InvalidNotificationOutboxStatusException(
                $item->getStatus(),
                'RETRY',
                InvalidNotificationOutboxStatusException::ERROR_CODE_RETRY
            );
        }

        $previousStatus = $item->getStatus();
        $previousRecipient = $item->getRecipient();
        $freshRecipient = $this->resolveFreshRecipient($item);
        $retryAt = new DateTimeImmutable('now');

        if ($previousStatus === NotificationStatus::FAILED_PERMANENT) {
            $item->resetForManualRetry($freshRecipient, $retryAt);
        } else {
            $item->rescheduleImmediateRetry($freshRecipient, $retryAt);
        }

        if (!$this->outbox->save($item)) {
            throw new RuntimeException(sprintf(
                'No se pudo reencolar la notificación #%d de la bandeja de salida.',
                $id
            ));
        }

        $this->audit->logNotificationOutboxEvent(
            $id,
            'NOTIFICATION_MANUAL_RETRY',
            $actor,
            ['status' => $previousStatus->value, 'recipient' => $previousRecipient],
            ['status' => $item->getStatus()->value, 'recipient' => $item->getRecipient(), 'attempts' => $item->getAttempts()],
            ['template' => $item->getTemplate()->value, 'channel' => $item->getChannel()->value]
        );

        return [
            'id' => $item->getId(),
            'status' => $item->getStatus()->value,
            'status_label' => $item->getStatus()->label(),
            'recipient' => $item->getRecipient(),
            'attempts' => $item->getAttempts(),
            'next_attempt_at' => $this->isoDate($item->getNextAttemptAt()),
        ];
    }

    /**
     * Reintento manual masivo de todos los envíos en `FAILED_PERMANENT`
     * (RF-06.4), refrescando el destinatario vigente de cada uno.
     *
     * Un envío Webhook sin URL configurada no aborta el lote: se omite y queda
     * listado en la propia bandeja con su diagnóstico, mientras el resto se
     * reencola.
     *
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return array{retried_count: int, retried_ids: list<int>}
     */
    public function retryAllFailed(array $actor): array
    {
        $failedRows = $this->outbox->findForCoordinator(['status' => NotificationStatus::FAILED_PERMANENT->value], self::MAX_PAGE_SIZE, 0);

        $retriedIds = [];
        foreach ($failedRows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            try {
                $this->retryItem($id, $actor);
                $retriedIds[] = $id;
            } catch (NotificationOutboxItemNotFoundException | WebhookConfigurationException) {
                // Fila desaparecida en carrera o Webhook sin URL vigente: se omite
                // del lote y sigue visible en la bandeja con su diagnóstico.
                continue;
            }
        }

        return [
            'retried_count' => count($retriedIds),
            'retried_ids' => $retriedIds,
        ];
    }

    /**
     * Cancelación manual de un envío con motivo auditado (RF-06.4).
     *
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return array<string, mixed> Proyección del envío cancelado.
     * @throws NotificationOutboxItemNotFoundException Si la notificación no existe.
     * @throws InvalidNotificationOutboxStatusException Si ya salió o está en tránsito.
     */
    public function cancelItem(int $id, ?string $reason, array $actor): array
    {
        $item = $this->outbox->findById($id);
        if ($item === null) {
            throw new NotificationOutboxItemNotFoundException($id);
        }

        if (!$item->getStatus()->canBeCancelled()) {
            throw new InvalidNotificationOutboxStatusException(
                $item->getStatus(),
                'CANCEL',
                InvalidNotificationOutboxStatusException::ERROR_CODE_CANCEL
            );
        }

        $cleanReason = $reason !== null ? trim($reason) : '';
        if ($cleanReason === '') {
            $cleanReason = self::DEFAULT_CANCELLATION_REASON;
        }

        $previousStatus = $item->getStatus();
        $item->markCancelledObsolete($cleanReason, new DateTimeImmutable('now'));

        if (!$this->outbox->save($item)) {
            throw new RuntimeException(sprintf(
                'No se pudo cancelar la notificación #%d de la bandeja de salida.',
                $id
            ));
        }

        $this->audit->logNotificationOutboxEvent(
            $id,
            'NOTIFICATION_MANUAL_CANCEL',
            $actor,
            ['status' => $previousStatus->value],
            ['status' => $item->getStatus()->value, 'obsolete_reason' => $cleanReason],
            ['template' => $item->getTemplate()->value, 'channel' => $item->getChannel()->value]
        );

        return [
            'id' => $item->getId(),
            'status' => $item->getStatus()->value,
            'status_label' => $item->getStatus()->label(),
            'obsolete_reason' => $item->getObsoleteReason(),
        ];
    }

    /**
     * Configuración vigente del Canal Corporativo de Emergencias y técnicos de
     * guardia activos (RF-06.5).
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->presentSettings($this->settings->findCurrent());
    }

    /**
     * Actualiza la configuración del Webhook con validación anti-SSRF (RF-06.5.1).
     *
     * Desactivar el canal cancela de forma atómica los envíos Webhook pendientes
     * (RF-07.1.f) dentro de `save()`.
     *
     * @param array<string, mixed> $payload Datos de entrada del panel (`webhook_name`,
     *   `webhook_url`, `is_webhook_enabled`).
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return array<string, mixed> Configuración persistida.
     * @throws WebhookConfigurationException Si la URL falta, no es HTTPS o apunta a un host prohibido.
     */
    public function updateSettings(array $payload, array $actor): array
    {
        $current = $this->settings->findCurrent();

        $name = isset($payload['webhook_name']) ? trim((string)$payload['webhook_name']) : '';
        if ($name === '') {
            $name = $current->getWebhookName();
        }

        $enabled = (bool)($payload['is_webhook_enabled'] ?? false);

        if (array_key_exists('webhook_url', $payload)) {
            $url = trim((string)$payload['webhook_url']);
            $url = $url === '' ? null : $url;
        } else {
            $url = $current->getWebhookUrl();
        }

        if ($enabled && $url === null) {
            throw WebhookConfigurationException::urlRequired();
        }

        if ($url !== null) {
            if (stripos($url, 'https://') !== 0) {
                throw WebhookConfigurationException::invalidScheme($url);
            }

            if (!WebhookUrlSecurityValidator::isValid($url)) {
                throw WebhookConfigurationException::forbiddenHost($url);
            }
        }

        $previous = [
            'webhook_name' => $current->getWebhookName(),
            'webhook_url' => $current->getWebhookUrl(),
            'is_webhook_enabled' => $current->isWebhookEnabled(),
        ];

        $current->updateConfiguration($name, $url, $enabled, isset($actor['id']) ? (int)$actor['id'] : null);
        $saved = $this->settings->save($current);

        $this->audit->logNotificationChannelSettingEvent(
            (int)$saved->getId(),
            'NOTIFICATION_WEBHOOK_UPDATED',
            $actor,
            $previous,
            [
                'webhook_name' => $saved->getWebhookName(),
                'webhook_url' => $saved->getWebhookUrl(),
                'is_webhook_enabled' => $saved->isWebhookEnabled(),
            ]
        );

        return $this->presentSettings($saved);
    }

    /**
     * Prueba directa e interactiva del Webhook corporativo con timeout estricto
     * de 5 segundos (RF-06.5.2, RNF-03).
     *
     * El resultado queda en `last_tested_at`/`last_test_status`/`last_test_error`,
     * se audita como `NOTIFICATION_WEBHOOK_TESTED` y nunca toca la cola pendiente:
     * la prueba es un diagnóstico del canal, no una operación de configuración.
     * El mensaje viaja identificado como `[PRUEBA DE CONEXIÓN · VENDGUARD]` para
     * que quien lo reciba en el grupo corporativo sepa que es una verificación.
     *
     * Nota de diseño: el catálogo cerrado de 22 plantillas no incluye una
     * plantilla de prueba, así que el sobre de la prueba reutiliza
     * `SANITARY_QUARANTINE_TRIGGERED` —la alerta crítica canónica del Art. II— y
     * el asunto y los cuerpos declaran explícitamente que es una prueba de
     * conexión.
     *
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @param string|null $urlOverride URL en edición (antes o después de guardar).
     * @param string|null $nameOverride Nombre en edición para el diagnóstico.
     * @return array{
     *   test_passed: bool, status: string, http_code: ?int, duration_ms: float,
     *   tested_at: string, error: ?string
     * }
     * @throws WebhookConfigurationException Si no hay URL, no es HTTPS o el host está bloqueado.
     */
    public function testWebhook(array $actor, ?string $urlOverride = null, ?string $nameOverride = null): array
    {
        $settings = $this->settings->findCurrent();

        $targetUrl = $urlOverride !== null ? trim($urlOverride) : '';
        if ($targetUrl === '') {
            $targetUrl = (string)($settings->getWebhookUrl() ?? '');
        }

        if (trim($targetUrl) === '') {
            throw WebhookConfigurationException::notConfigured();
        }

        if (stripos($targetUrl, 'https://') !== 0) {
            throw WebhookConfigurationException::invalidScheme($targetUrl);
        }

        if (!WebhookUrlSecurityValidator::isValid($targetUrl)) {
            throw WebhookConfigurationException::forbiddenHost($targetUrl);
        }

        $channelName = $nameOverride !== null && trim($nameOverride) !== ''
            ? trim($nameOverride)
            : $settings->getWebhookName();

        $subject = sprintf('[PRUEBA DE CONEXIÓN · VENDGUARD] Verificación del canal «%s»', $channelName);
        $message = new RenderedNotificationMessage(
            template: NotificationTemplate::SANITARY_QUARANTINE_TRIGGERED,
            subject: mb_substr($subject, 0, RenderedNotificationMessage::MAX_SUBJECT_LENGTH),
            textBody: sprintf(
                "[PRUEBA DE CONEXIÓN · VENDGUARD]\nCanal: %s\nOrigen: Panel de Coordinación de VendGuard.\nEsta verificación no corresponde a ninguna avería",
                $channelName
            ) . '.',
            htmlBody: sprintf(
                '<p><strong>[PRUEBA DE CONEXIÓN · VENDGUARD]</strong></p><p>Canal: %s</p>'
                . '<p>Origen: Panel de Coordinación de VendGuard. Esta verificación no corresponde a ninguna avería.</p>',
                htmlspecialchars($channelName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            )
        );

        $startedAt = microtime(true);
        $result = $this->transports->forChannel(NotificationChannel::WEBHOOK)->deliver($targetUrl, $message);
        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);
        $testedAt = new DateTimeImmutable('now');

        $settings->recordTestResult($result->isSuccess(), $result->errorMessage, $testedAt);
        $persisted = $this->settings->recordTestResult($settings);

        $this->audit->logNotificationChannelSettingEvent(
            (int)$persisted->getId(),
            'NOTIFICATION_WEBHOOK_TESTED',
            $actor,
            null,
            [
                'status' => $result->isSuccess() ? 'SUCCESS' : 'FAILED',
                'http_code' => $result->providerStatusCode,
                'duration_ms' => $durationMs,
            ],
            ['webhook_name' => $channelName]
        );

        return [
            'test_passed' => $result->isSuccess(),
            'status' => $result->isSuccess() ? 'SUCCESS' : 'FAILED',
            'http_code' => $result->providerStatusCode,
            'duration_ms' => $durationMs,
            'tested_at' => $this->isoDate($persisted->getLastTestedAt()),
            'error' => $persisted->getLastTestError(),
        ];
    }

    /**
     * Traduce la fila cruda de la bandeja a la proyección del panel (RF-06.3).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        $status = NotificationStatus::fromString((string)$row['status']);
        $priority = NotificationPriority::fromString((string)$row['priority']);
        $template = NotificationTemplate::fromString((string)$row['template']);

        return [
            'id' => (int)$row['id'],
            'incident_id' => isset($row['incident_id']) && $row['incident_id'] !== null ? (int)$row['incident_id'] : null,
            'ticket_code' => isset($row['ticket_code']) && $row['ticket_code'] !== null ? (string)$row['ticket_code'] : null,
            'channel' => (string)$row['channel'],
            'priority' => $priority->value,
            'priority_label' => $priority->label(),
            'template' => $template->value,
            'template_label' => $template->label(),
            'recipient' => (string)$row['recipient'],
            'recipient_role' => (string)$row['recipient_role'],
            'subject' => (string)$row['subject'],
            'status' => $status->value,
            'status_label' => $status->label(),
            'attempts' => (int)$row['attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'next_attempt_at' => $this->isoDate($row['next_attempt_at'] ?? null),
            'processing_started_at' => $this->isoDate($row['processing_started_at'] ?? null),
            'processed_at' => $this->isoDate($row['processed_at'] ?? null),
            'last_error' => isset($row['last_error']) && $row['last_error'] !== null ? (string)$row['last_error'] : null,
            'obsolete_reason' => isset($row['obsolete_reason']) && $row['obsolete_reason'] !== null ? (string)$row['obsolete_reason'] : null,
            'tracking_url' => $this->trackingUrl($row, $template),
            'can_retry' => in_array($status, [NotificationStatus::FAILED_PERMANENT, NotificationStatus::PENDING_RETRY], true),
            'can_cancel' => $status->canBeCancelled(),
            'created_at' => $this->isoDate($row['created_at'] ?? null),
            'updated_at' => $this->isoDate($row['updated_at'] ?? null),
        ];
    }

    /**
     * URL final del botón de seguimiento del correo (RF-06.3).
     *
     * Sale de la misma autoridad que la del envío
     * (`NotificationTrackingLinkResolver`), de modo que lo que Coordinación
     * revisa en la bandeja es exactamente lo que recibirá el destinatario: la
     * ruta guardada en `payload_json` prefijada con el host público vigente. Los
     * avisos internos (Coordinación) y los del canal Webhook, que no llevan botón,
     * publican `null` en lugar de un enlace inventado.
     *
     * Desde el Módulo 15, un aviso de asignación al técnico **sí** lleva enlace, y
     * viaja por dos canales: el correo y el mensaje personal de Telegram. La
     * bandeja debe enseñar la misma URL en ambos, porque es la que se va a enviar.
     * Por eso el filtro es «canales con botón», no «sólo correo».
     *
     * @param array<string, mixed> $row
     */
    private function trackingUrl(array $row, NotificationTemplate $template): ?string
    {
        $channel = (string)($row['channel'] ?? '');
        if ($channel !== NotificationChannel::EMAIL->value && $channel !== NotificationChannel::TELEGRAM->value) {
            return null;
        }

        return $this->trackingLinks->resolve($template, $this->decodePayload($row['payload_json'] ?? null));
    }

    /**
     * Payload de la fila decodificado; `[]` si no es un objeto JSON legible.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Proyección de la configuración del canal + técnicos de guardia (RF-06.5).
     *
     * @return array<string, mixed>
     */
    private function presentSettings(NotificationChannelSettings $settings): array
    {
        return [
            'webhook_name' => $settings->getWebhookName(),
            'webhook_url' => $settings->getWebhookUrl(),
            'is_webhook_enabled' => $settings->isWebhookEnabled(),
            'last_tested_at' => $this->isoDate($settings->getLastTestedAt()),
            'last_test_status' => $settings->getLastTestStatus(),
            'last_test_error' => $settings->getLastTestError(),
            'on_call_technicians' => array_map(
                static fn(User $technician): array => [
                    'id' => $technician->getId(),
                    'name' => $technician->getName(),
                    'operator_code' => sprintf('OP-%02d', $technician->getId()),
                    'email' => $technician->getEmail(),
                ],
                $this->onCallTechnicians->findOnCallTechnicians()
            ),
        ];
    }

    /**
     * Destinatario vigente para el reintento (RF-06.4).
     *
     * Sede → `locations.contact_email` actual; Webhook → `webhook_url` configurado
     * (o error 422 si no existe); cualquier otro rol interno conserva su destino.
     *
     * @throws WebhookConfigurationException
     */
    private function resolveFreshRecipient(NotificationOutboxItem $item): string
    {
        if ($item->getRecipientLocationId() !== null) {
            $location = $this->locations->findById((int)$item->getRecipientLocationId());
            $email = $location?->getContactEmail();
            if ($email !== null && trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false) {
                return strtolower(trim($email));
            }

            return $item->getRecipient();
        }

        if ($item->getChannel() === NotificationChannel::WEBHOOK) {
            $url = $this->settings->findCurrent()->getWebhookUrl();
            if ($url === null || !WebhookUrlSecurityValidator::isValid($url)) {
                throw WebhookConfigurationException::notConfigured();
            }

            return $url;
        }

        return $item->getRecipient();
    }

    /**
     * Normaliza los filtros del panel: valores `ALL`/vacíos no filtran y sólo se
     * aceptan los canales, prioridades y estados del catálogo cerrado.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'ALL' && NotificationStatus::isValid($status)) {
            $normalized['status'] = $status;
        }

        $channel = strtoupper(trim((string)($filters['channel'] ?? '')));
        if ($channel !== '' && $channel !== 'ALL' && NotificationChannel::isValid($channel)) {
            $normalized['channel'] = $channel;
        }

        $priority = strtoupper(trim((string)($filters['priority'] ?? '')));
        if ($priority !== '' && $priority !== 'ALL' && NotificationPriority::isValid($priority)) {
            $normalized['priority'] = $priority;
        }

        $ticketCode = trim((string)($filters['ticket_code'] ?? ''));
        if ($ticketCode !== '') {
            $normalized['ticket_code'] = $ticketCode;
        }

        return $normalized;
    }

    /**
     * Fecha ISO 8601 con zona para el panel, tolerante a `null` y a los huecos.
     */
    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        try {
            return (new DateTimeImmutable((string)$value))->format('Y-m-d\TH:i:sP');
        } catch (Throwable) {
            return null;
        }
    }
}
