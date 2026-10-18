<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use RuntimeException;
use VendGuard\Application\DTO\NotificationDispatchResultDto;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Repository\IncidentNotificationCycleRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitarySlaAlertEnqueuerInterface;
use VendGuard\Core\Domain\Repository\SanitarySlaWatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Service\NotificationObsolescencePolicy;
use VendGuard\Core\Service\NotificationRetryPolicy;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;

/**
 * NotificationDispatcherService — Despachador y vigilante del Centro de
 * Notificaciones en segundo plano (Módulo 14 · T-NOTIF-07).
 *
 * Una única pasada idempotente (`runPass()`) ejecuta las cuatro fases atómicas
 * del contrato, en este orden exacto y sin llamadas externas síncronas al
 * usuario:
 *
 *   **Fase 1 · Rescate (RF-01.4).** Una sentencia atómica devuelve a la cola las
 *   filas atrapadas en `PROCESSING` más de 5 minutos —proceso muerto a mitad de
 *   un intento— contabilizando el intento interrumpido; el quinto intento cae
 *   directamente a `FAILED_PERMANENT` en lugar de reintentarse eternamente.
 *
 *   **Fase 2 · Vigilancia 24/7 de inminencia de SLA (RF-04.1, RF-04.2, RF-04.3,
 *   RF-04.4, RF-04.5).** Sobre los expedientes activos de máquinas bajo
 *   vigilancia sanitaria (`PERISHABLE_FOOD` y `COMBO`), en tiempo natural
 *   continuo:
 *     - `> 2 h` sin técnico asignado (en `REGISTERED` o `REOPENED`) → alerta de
 *       2h a Coordinación y al canal corporativo.
 *     - `> 3 h` sin haber entrado en `IN_PROGRESS` → alerta de 3h a
 *       Coordinación, al técnico asignado (si lo hay) y al canal corporativo.
 *   El cerrojo anti-spam es el **sellado condicional** de
 *   `sla_2h_alert_sent_at` / `sla_3h_alert_sent_at`: sólo encola la pasada que
 *   gana el `UPDATE ... WHERE columna IS NULL`, así que dos procesos solapados
 *   no duplican el aviso (RNF-06). La reapertura en garantía reinicia las marcas
 *   y arranca un ciclo nuevo (RF-04.5).
 *
 *   **Fase 3 · Reclamo con prioridad estricta (RF-01.3, RNF-06).** El lote se
 *   toma del repositorio con `FOR UPDATE SKIP LOCKED` y `CRITICAL_SANITARY` por
 *   delante de cualquier aviso ordinario (Art. II).
 *
 *   **Fase 4 · Obsolescencia, entrega y backoff (RF-01.5, RF-06.1, RF-06.2,
 *   RF-07.1).** Antes de abrir un socket se relee el estado vigente del ticket y
 *   del destinatario: un aviso que perdió su propósito se cancela como
 *   `CANCELLED_OBSOLETE` con el motivo exacto y no sale nunca. Los envíos
 *   restantes se entregan con el timeout estricto de 5 s del transporte
 *   (RNF-03) y el resultado se traduce a `SENT`, `PENDING_RETRY` con la curva
 *   1m/5m/15m/60m o `FAILED_PERMANENT` con su fila de auditoría en `audit_log`
 *   (Art. III.3). Los avisos externos cuyo botón no pudo absolutizarse se
 *   cuentan en `relative_tracking_links_count` (T-NOTIF-17): la entrega no se
 *   bloquea, pero la degradación del enlace se publica en lugar de silenciarse.
 *
 * **Idempotencia y honestidad contable.** El resumen devuelto
 * (`NotificationDispatchResultDto`) reparte el lote reclamado entre enviadas,
 * reprogramadas, fallidas definitivas y canceladas; si una escritura no afecta a
 * ninguna fila se aborta la pasada con excepción en lugar de devolver un resumen
 * que mienta sobre lo ocurrido. Las filas que la pasada no llegue a tocar siguen
 * en la cola y el rescate de la pasada siguiente las recupera.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de puertos, cero dependencias.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationDispatcherService
{
    /** Ventana de rescate de envíos huérfanos en `PROCESSING` (RF-01.4). */
    public const RESCUE_STALE_MINUTES = 5;

    /** Umbral de inminencia «2 h sin asignar» (RF-04.1). */
    public const SLA_2H_SECONDS = 7200;

    /** Umbral de inminencia «3 h sin iniciar la intervención» (RF-04.2). */
    public const SLA_3H_SECONDS = 10800;

    /** Tamaño de lote por defecto del despachador. */
    public const DEFAULT_BATCH_SIZE = 50;

    private readonly AuditLogger $audit;
    private readonly NotificationMessageRenderer $renderer;

    public function __construct(
        private readonly NotificationOutboxRepositoryInterface $outbox,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly UserRepositoryInterface $users,
        private readonly NotificationSettingsRepositoryInterface $settings,
        private readonly IncidentNotificationCycleRepositoryInterface $cycles,
        private readonly SanitarySlaWatchRepositoryInterface $slaWatch,
        private readonly SanitarySlaAlertEnqueuerInterface $slaAlerts,
        private readonly NotificationTransportRegistry $transports,
        private readonly TransactionManagerInterface $transactions,
        ?AuditLogger $audit = null,
        ?NotificationMessageRenderer $renderer = null,
    ) {
        $this->audit = $audit ?? new AuditLogger();
        $this->renderer = $renderer ?? new NotificationMessageRenderer();
    }

    /**
     * Ejecuta una pasada completa del despachador y vigilante.
     *
     * @param int $batchSize Filas máximas a reclamar para despacho (1..200).
     * @param DateTimeImmutable|null $now Referencia temporal inyectable para que
     *   la batería sea determinista; `null` usa el reloj del sistema.
     * @throws \InvalidArgumentException Si el tamaño de lote no está en rango.
     */
    public function runPass(int $batchSize = self::DEFAULT_BATCH_SIZE, ?DateTimeImmutable $now = null): NotificationDispatchResultDto
    {
        if ($batchSize < 1 || $batchSize > 200) {
            throw new \InvalidArgumentException('El tamaño del lote de despacho debe estar entre 1 y 200 notificaciones.');
        }

        $startedAt = microtime(true);
        $reference = $now ?? new DateTimeImmutable('now');

        // Fase 1 · Rescate de envíos huérfanos en tránsito (RF-01.4).
        $rescuedStuckCount = $this->outbox->rescueStuckProcessingItems(self::RESCUE_STALE_MINUTES, $reference);

        // Fase 2 · Vigilancia 24/7 de los umbrales de SLA sanitario (RF-04).
        [$sla2hAlertsEnqueued, $sla3hAlertsEnqueued] = $this->watchSanitarySla($reference);

        // Fase 3 · Reclamo con prioridad sanitaria estricta y bloqueo concurrente (RF-01.3).
        $claimed = $this->outbox->claimReadyBatch($batchSize);

        // Fase 4 · Obsolescencia, entrega y política de fallos (RF-06, RF-07.1).
        $sentCount = 0;
        $retryScheduledCount = 0;
        $failedPermanentCount = 0;
        $cancelledObsoleteCount = 0;
        $relativeTrackingLinksCount = 0;

        foreach ($claimed as $item) {
            $obsolescence = $this->evaluateObsolescence($item);

            if ($obsolescence['is_obsolete']) {
                $item->markCancelledObsolete(
                    $obsolescence['reason'] ?? 'Notificación cancelada por obsolescencia antes del despacho.',
                    $reference
                );
                $this->persist($item);
                $cancelledObsoleteCount++;
                continue;
            }

            // T-NOTIF-17 · Un enlace externo que no pudo absolutizarse se declara
            // antes de salir: el aviso sigue viajando —la Sede conserva ticket,
            // máquina y urgencia—, pero la pasada lo cuenta para que la
            // degradación deje de ser invisible y la configuración se corrija. La
            // autoridad es la del propio renderizador: lo que se declara aquí y lo
            // que se escribe en el correo son exactamente la misma regla.
            if ($this->renderer->trackingLinks()->usesRelativeTrackingLink($item->getTemplate(), $item->getPayload())) {
                $relativeTrackingLinksCount++;
            }

            $result = $this->transports
                ->forChannel($item->getChannel())
                ->deliver($item->getRecipient(), $this->renderer->render($item->getTemplate(), $item->getPayload()));

            if ($result->isSuccess()) {
                $item->markSent($reference);
                $this->persist($item);
                $sentCount++;
                continue;
            }

            $attempts = $item->getAttempts() + 1;
            $plan = NotificationRetryPolicy::evaluateFailure($attempts, $result->isPermanentFailure(), $reference);
            $diagnosis = $result->errorMessage ?? 'Fallo de entrega sin diagnóstico del proveedor.';

            if ($plan['status'] === NotificationStatus::FAILED_PERMANENT) {
                $item->recordPermanentFailure($diagnosis, $reference);
                $this->persist($item);
                $this->audit->logNotificationOutboxEvent(
                    (int)$item->getId(),
                    'NOTIFICATION_PERMANENT_FAILURE',
                    self::SYSTEM_ACTOR,
                    ['status' => NotificationStatus::PROCESSING->value, 'attempts' => $item->getAttempts() - 1],
                    ['status' => NotificationStatus::FAILED_PERMANENT->value, 'attempts' => $item->getAttempts()],
                    ['template' => $item->getTemplate()->value, 'channel' => $item->getChannel()->value, 'error' => $diagnosis]
                );
                $failedPermanentCount++;
                continue;
            }

            $nextAttemptAt = $plan['next_attempt_at'] ?? $reference->modify('+60 seconds');
            $item->recordTransientFailure($diagnosis, $nextAttemptAt);
            $this->persist($item);
            $retryScheduledCount++;
        }

        return new NotificationDispatchResultDto(
            rescuedStuckCount: $rescuedStuckCount,
            sla2hAlertsEnqueued: $sla2hAlertsEnqueued,
            sla3hAlertsEnqueued: $sla3hAlertsEnqueued,
            claimedCount: count($claimed),
            sentCount: $sentCount,
            retryScheduledCount: $retryScheduledCount,
            failedPermanentCount: $failedPermanentCount,
            cancelledObsoleteCount: $cancelledObsoleteCount,
            relativeTrackingLinksCount: $relativeTrackingLinksCount,
            durationMs: round((microtime(true) - $startedAt) * 1000, 1),
        );
    }

    /** Actor de sistema para la auditoría de la pasada batch. */
    private const SYSTEM_ACTOR = [
        'id' => null,
        'role' => 'SYSTEM',
        'name' => 'Despachador de Notificaciones',
    ];

    /**
     * Fase 2 · Vigilancia de los umbrales de inminencia de SLA sanitario.
     *
     * @return array{0: int, 1: int} Alertas de 2h y de 3h efectivamente emitidas.
     */
    private function watchSanitarySla(DateTimeImmutable $now): array
    {
        $sla2hAlertsEnqueued = 0;
        $sla3hAlertsEnqueued = 0;

        foreach ($this->slaWatch->findActiveSanitaryWatchIncidentsForSlaWatch($now) as $incident) {
            $incidentId = $incident->getId();
            if ($incidentId === null) {
                continue;
            }

            $elapsedSeconds = $this->elapsedSeconds($incident, $now);
            if ($elapsedSeconds === null) {
                continue;
            }

            $incidentId = (int)$incidentId;

            // Umbral de 2h sin asignar: REGISTERED/REOPENED, sin técnico y sin marca previa.
            if (
                $elapsedSeconds >= self::SLA_2H_SECONDS
                && in_array($incident->getStatus(), [IncidentStatus::REGISTERED, IncidentStatus::REOPENED], true)
                && $incident->getAssignedTechnicianId() === null
                && $incident->getSla2hAlertSentAt() === null
                && $this->transactions->runInTransaction(function () use ($incident, $incidentId, $now): bool {
                    if (!$this->cycles->markSlaAlertSent($incidentId, 2, $now)) {
                        return false;
                    }

                    $this->slaAlerts->enqueueSla2hUnassignedAlert($incident);

                    return true;
                })
            ) {
                $sla2hAlertsEnqueued++;
            }

            // Umbral de 3h sin iniciar: sin started_at y sin marca previa, tenga o no técnico.
            if (
                $elapsedSeconds >= self::SLA_3H_SECONDS
                && $incident->getStartedAt() === null
                && $incident->getSla3hAlertSentAt() === null
                && $this->transactions->runInTransaction(function () use ($incident, $incidentId, $now): bool {
                    if (!$this->cycles->markSlaAlertSent($incidentId, 3, $now)) {
                        return false;
                    }

                    $this->slaAlerts->enqueueSla3hUnstartedAlert($incident);

                    return true;
                })
            ) {
                $sla3hAlertsEnqueued++;
            }
        }

        return [$sla2hAlertsEnqueued, $sla3hAlertsEnqueued];
    }

    /**
     * Segundos transcurridos del ciclo de atención vigente en tiempo natural
     * continuo 24/7 (RF-04.3). `null` cuando el expediente no declara ciclo.
     */
    private function elapsedSeconds(Incident $incident, DateTimeImmutable $now): ?int
    {
        $cycleStartedAt = $incident->getCycleStartedAt();
        if (!is_string($cycleStartedAt) || trim($cycleStartedAt) === '') {
            return null;
        }

        $startedTimestamp = strtotime($cycleStartedAt);
        if ($startedTimestamp === false) {
            return null;
        }

        return max(0, $now->getTimestamp() - $startedTimestamp);
    }

    /**
     * Relee el estado vigente del ticket y del destinatario justo antes de
     * enviar (RF-07.1).
     *
     * @return array{is_obsolete: bool, reason: ?string}
     */
    private function evaluateObsolescence(NotificationOutboxItem $item): array
    {
        $incident = null;
        if ($item->getIncidentId() !== null) {
            $incident = $this->incidents->findById((int)$item->getIncidentId());
        }

        $recipientUserActive = true;
        if ($item->getRecipientUserId() !== null) {
            $user = $this->users->findById((int)$item->getRecipientUserId(), false);
            $recipientUserActive = $user !== null && $user->isActive();
        }

        $webhookChannelEnabled = true;
        if ($item->getChannel() === NotificationChannel::WEBHOOK) {
            $webhookChannelEnabled = $this->settings->findCurrent()->isWebhookEnabled();
        }

        return NotificationObsolescencePolicy::evaluate(
            $item,
            $incident,
            $recipientUserActive,
            $webhookChannelEnabled
        );
    }

    /**
     * Persiste el nuevo estado de una fila reclamada.
     *
     * Un `save()` que no afecta a ninguna fila significa que el estado real de la
     * cola no es el que el despachador cree tener; seguir adelante produciría un
     * resumen que miente, así que la pasada se aborta y el rescate de la pasada
     * siguiente recupera la fila.
     */
    private function persist(NotificationOutboxItem $item): void
    {
        if (!$this->outbox->save($item)) {
            throw new RuntimeException(sprintf(
                'No se pudo persistir el estado de la notificación #%s de la bandeja de salida.',
                (string)$item->getId()
            ));
        }
    }
}
