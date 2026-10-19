<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;

use VendGuard\Core\Domain\Repository\IncidentNotificationCycleRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitarySlaAlertEnqueuerInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Service\NotificationObsolescencePolicy;
use VendGuard\Core\Service\NotificationPayloadSanitizer;
use VendGuard\Core\Service\WebhookUrlSecurityValidator;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Notification\Transport\TelegramTransport;

/**
 * NotificationOutboxEnqueuerService — Encolado transaccional de los eventos del
 * Centro de Notificaciones (Módulo 14 · T-NOTIF-06).
 *
 * Este servicio es la única puerta por la que un evento de negocio se convierte
 * en filas de `notification_outbox`. Su contrato es deliberadamente estrecho:
 *
 * 1. **Atómico:** cada método envuelve sus inserciones en una transacción. Si el
 *    llamador ya abrió una (caso normal: `PdoTransactionManager` es reentrante),
 *    las filas viajan en la MISMA transacción que la escritura de negocio; si no,
 *    el método abre la suya para que el lote de destinatarios no quede a medias.
 * 2. **Sanitizado en origen:** el payload pasa por `NotificationPayloadSanitizer`
 *    antes de serializarse. Aunque una plantilla futura leyera una clave
 *    prohibida, la fila de la Sede o del Ciudadano QR ya nace sin ella (Art. V.4).
 * 3. **Idempotente donde el requisito lo exige:** las alertas de frío, la
 *    cuarentena y el escalado de contención llevan `dedup_key`; las respuestas de
 *    Sede en `PENDING_INFO` delegan la agrupación de ráfagas en el repositorio.
 *
 * Los eventos del catálogo se reparten en métodos públicos con nombre de
 * negocio (apertura, asignación, pausa, resolución, reapertura, crónica,
 * cancelación, acuse QR, cuarentena y escalado). El Módulo 16 añade dos métodos
 * que respetan la matriz de encolado de la avería oportunista (RF-06.3):
 * `enqueueInSituAssumption()` aplica la regla «cero correo redundante a la Sede»
 * al asumir un ticket ya abierto, cancela por obsolescencia el aviso de
 * asignación del técnico reemplazado —solo por correo la desasignación, RF-TG-06—
 * y avisa a Sede y Coordinación cuando hay reapertura in situ en garantía;
 * `enqueueOpportunisticColdRoutePostponed()` escala a Coordinación la parada
 * crítica/frío postergada en otra sede (Resolución QA 1). Los dos umbrales de SLA
 * (2h/3h) viven aquí como métodos públicos del puerto `SanitarySlaAlertEnqueuerInterface`
 * —el despachador decide CUÁNDO pedirlos (T-NOTIF-07), el servicio de encolado
 * decide CÓMO construirlos—; ninguna otra ruta del ciclo de vida los dispara.
 *
 * Decisión de diseño sobre los destinatarios internos: la Alerta Inmediata de
 * Frío va a Coordinación y al canal corporativo siempre; al colectivo de guardia
 * sólo cuando el ticket NO tiene técnico asignado (RF-03.1). Si no hay nadie de
 * guardia, el renderizador añade el literal de RF-03.2 a partir de
 * `on_call_technician_count = 0`; el servicio no duplica esa regla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class NotificationOutboxEnqueuerService implements SanitarySlaAlertEnqueuerInterface
{
    /** Origen del expediente: formulario del Portal de Sede. */
    public const ORIGIN_LOCATION_PORTAL = 'LOCATION_PORTAL';

    /** Origen del expediente: escaneo público del código QR. */
    public const ORIGIN_CITIZEN_QR = 'CITIZEN_QR';

    private const ROLE_COORDINATOR = 'COORDINATOR';
    private const ROLE_TECHNICIAN = 'TECHNICIAN';
    private const ROLE_LOCATION = 'LOCATION';
    private const ROLE_CITIZEN_QR = 'CITIZEN_QR';
    private const ROLE_WEBHOOK = 'WEBHOOK';

    private const COLD_CHAIN_CATEGORIES = [
        IncidentCategory::TEMPERATURE_COLD,
        IncidentCategory::ELECTRICAL_OFF,
    ];

    private readonly NotificationOutboxRepositoryInterface $outbox;
    private readonly NotificationSettingsRepositoryInterface $settings;
    private readonly LocationRepositoryInterface $locations;
    private readonly UserRepositoryInterface $users;
    private readonly OnCallTechnicianRepositoryInterface $onCallTechnicians;
    private readonly MachineRepositoryInterface $machines;
    private readonly IncidentRepositoryInterface $incidents;
    private readonly IncidentNotificationCycleRepositoryInterface $cycles;
    private readonly TransactionManagerInterface $transactions;
    private readonly NotificationMessageRenderer $renderer;

    public function __construct(
        NotificationOutboxRepositoryInterface $outbox,
        NotificationSettingsRepositoryInterface $settings,
        LocationRepositoryInterface $locations,
        UserRepositoryInterface $users,
        OnCallTechnicianRepositoryInterface $onCallTechnicians,
        MachineRepositoryInterface $machines,
        IncidentRepositoryInterface $incidents,
        IncidentNotificationCycleRepositoryInterface $cycles,
        TransactionManagerInterface $transactions,
        ?NotificationMessageRenderer $renderer = null
    ) {
        $this->outbox = $outbox;
        $this->settings = $settings;
        $this->locations = $locations;
        $this->users = $users;
        $this->onCallTechnicians = $onCallTechnicians;
        $this->machines = $machines;
        $this->incidents = $incidents;
        $this->cycles = $cycles;
        $this->transactions = $transactions;
        $this->renderer = $renderer ?? new NotificationMessageRenderer();
    }

    /**
     * RF-05.1 / RF-05.4 / RF-02.3 / RF-02.4 / RF-03.1: apertura del expediente.
     *
     * - Sede: correo oficial y, si el informador dejó uno distinto, también el suyo.
     * - Ciudadano QR: sólo si dejó correo, con la proyección pública mínima.
     * - Alerta Inmediata de Frío si la máquina está bajo vigilancia sanitaria y
     *   concurre categoría térmica/eléctrica o urgencia crítica.
     *
     * @return int Filas efectivamente encoladas (las deduplicadas no cuentan).
     */
    public function enqueueIncidentOpened(
        Incident $incident,
        ?Machine $machine = null,
        ?Location $location = null,
        string $origin = self::ORIGIN_LOCATION_PORTAL,
        ?string $reporterEmail = null
    ): int {
        return $this->transactions->runInTransaction(function () use (
            $incident,
            $machine,
            $location,
            $origin,
            $reporterEmail
        ): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location);
            $count = 0;

            foreach ($this->locationRecipients($location, $incident, $origin, $reporterEmail) as $email) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_OPENED_SITE,
                    $email,
                    self::ROLE_LOCATION,
                    null,
                    (int)$location->getId(),
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $citizenEmail = $this->validEmail($reporterEmail ?? $incident->getReporterEmail());
            if ($origin === self::ORIGIN_CITIZEN_QR && $citizenEmail !== null) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR,
                    $citizenEmail,
                    self::ROLE_CITIZEN_QR,
                    null,
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $count += $this->enqueueColdChainAlertInternal($incident, $machine, $location);

            return $count;
        });
    }

    /**
     * RF-03.1: Alerta Inmediata de Frío fuera de la apertura (reapertura en
     * garantía o reclasificación a CRITICAL en triaje).
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueColdChainAlert(
        Incident $incident,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        return $this->transactions->runInTransaction(
            fn (): int => $this->enqueueColdChainAlertInternal($incident, $machine, $location)
        );
    }

    /**
     * RF-05.2: Aviso al técnico entrante y desasignación del saliente.
     *
     * La comparación se hace sobre las dos fotografías de la incidencia
     * (`$before` / `$after`) porque el repositorio ya persistió la reasignación
     * cuando el controlador llama aquí.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueAssignment(
        Incident $before,
        Incident $after,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        return $this->transactions->runInTransaction(function () use ($before, $after, $machine, $location): int {
            $machine ??= $this->machineFor($after);
            $location ??= $this->locationFor($after);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($after, $machine, $location);
            $count = 0;

            $incomingId = $after->getAssignedTechnicianId();
            $outgoingId = $before->getAssignedTechnicianId();

            if ($incomingId !== null) {
                $incoming = $this->users->findById($incomingId, true);
                $incomingEmail = $this->validEmail($incoming?->getEmail());
                if ($incoming !== null && $incomingEmail !== null) {
                    $count += $this->queueEmail(
                        NotificationTemplate::TECHNICIAN_ASSIGNED,
                        $incomingEmail,
                        self::ROLE_TECHNICIAN,
                        $incomingId,
                        null,
                        $after,
                        $payload
                    ) ? 1 : 0;
                }

                // RF-TG-02 · Telegram se SUMA al correo: el técnico recibe el
                // aviso en el bolsillo con el enlace a su parada, y conserva el
                // correo de siempre. Sin vinculación o sin bot no se crea fila:
                // la asignación no se degrada y la bandeja no acumula envíos
                // imposibles (RF-TG-03).
                if ($incoming !== null) {
                    $count += $this->queueTelegram(
                        NotificationTemplate::TECHNICIAN_ASSIGNED,
                        $incoming->getTelegramChatId(),
                        $incomingId,
                        $after,
                        $payload
                    ) ? 1 : 0;
                }
            }

            if ($outgoingId !== null && $outgoingId !== $incomingId) {
                $outgoing = $this->users->findById($outgoingId, false);
                $outgoingEmail = $this->validEmail($outgoing?->getEmail());
                if ($outgoing !== null && $outgoingEmail !== null) {
                    $count += $this->queueEmail(
                        NotificationTemplate::TECHNICIAN_UNASSIGNED,
                        $outgoingEmail,
                        self::ROLE_TECHNICIAN,
                        $outgoingId,
                        null,
                        $after,
                        $payload
                    ) ? 1 : 0;
                }
            }

            // RF-03.1 (b): reclasificación a CRITICAL en triaje. La alerta se
            // emite en esta misma transacción para que no haya una ventana en la
            // que el ticket sea crítico y la cola aún no lo sepa.
            if ($this->shouldTriggerColdAlert($after, $machine)
                && !$this->shouldTriggerColdAlert($before, $machine)
                && $after->getColdAlertSentAt() === null
            ) {
                $count += $this->enqueueColdChainAlertInternal($after, $machine, $location);
            }

            return $count;
        });
    }

    /**
     * RF-05.3: solicitud de acceso o información, EXCLUSIVAMENTE a la Sede.
     *
     * @return int Filas efectivamente encoladas (0 o 1).
     */
    public function enqueuePendingInfoRequest(
        Incident $incident,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        return $this->transactions->runInTransaction(function () use ($incident, $machine, $location): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            $email = $this->validEmail($location?->getContactEmail());
            if ($location === null || $email === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'pending_info_reason_category' => $incident->getPendingInfoReasonCategory()?->value,
                'reason_category_label' => $incident->getPendingInfoReasonCategory()?->label(),
                'reason_text' => $incident->getPendingInfoReasonText(),
                'paused_at' => $incident->getPausedAt(),
            ]);

            return $this->queueEmail(
                NotificationTemplate::PENDING_INFO_SITE_REQUEST,
                $email,
                self::ROLE_LOCATION,
                null,
                (int)$location->getId(),
                $incident,
                $payload
            ) ? 1 : 0;
        });
    }

    /**
     * RF-05.4: respuesta pública de la Sede en `PENDING_INFO`.
     *
     * El destinatario es el técnico asignado; si el ticket no tiene técnico, se
     * avisa a cada coordinador activo. La agrupación de ráfagas la resuelve el
     * repositorio por `(incident_id, template, recipient_user_id)`.
     *
     * @return int Filas encoladas o actualizadas.
     */
    public function enqueueSiteReply(int $incidentId, string $replyText): int
    {
        $text = trim($replyText);
        if ($text === '') {
            return 0;
        }

        return $this->transactions->runInTransaction(function () use ($incidentId, $text): int {
            $incident = $this->incidents->findById($incidentId);
            if ($incident === null || $incident->getStatus() !== IncidentStatus::PENDING_INFO) {
                return 0;
            }

            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'site_reply_text' => $text,
                'event_at' => $this->now(),
            ]);

            $assignedId = $incident->getAssignedTechnicianId();
            if ($assignedId !== null) {
                $technician = $this->users->findById($assignedId, true);
                $email = $this->validEmail($technician?->getEmail());
                if ($technician !== null && $email !== null) {
                    return $this->queueEmail(
                        NotificationTemplate::PENDING_INFO_SITE_REPLY,
                        $email,
                        self::ROLE_TECHNICIAN,
                        $assignedId,
                        null,
                        $incident,
                        $payload
                    ) ? 1 : 0;
                }
            }

            $count = 0;
            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::PENDING_INFO_SITE_REPLY,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * RF-05.5: servicio restablecido con garantía de 48 h.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueResolution(Incident $incident, ?Machine $machine = null, ?Location $location = null): int
    {
        return $this->transactions->runInTransaction(function () use ($incident, $machine, $location): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'resolved_at' => $incident->getResolvedAt() ?? $this->now(),
            ]);

            // La doble destinatario de RF-02.3 es un derecho de la Sede: un
            // expediente nacido en el canal ciudadano QR NO envía la proyección
            // de Sede a su informador (recibiría el enlace del portal y textos de
            // inmueble). El ciudadano recibe su propia plantilla acotada.
            $origin = $this->isCitizenReportedIncident($incident)
                ? self::ORIGIN_CITIZEN_QR
                : self::ORIGIN_LOCATION_PORTAL;
            $count = 0;
            $siteRecipients = $this->locationRecipients($location, $incident, $origin);
            foreach ($siteRecipients as $email) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_RESOLVED_SITE,
                    $email,
                    self::ROLE_LOCATION,
                    null,
                    (int)$location->getId(),
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $citizenEmail = $this->citizenReporterEmail($incident);
            if ($citizenEmail !== null) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR,
                    $citizenEmail,
                    self::ROLE_CITIZEN_QR,
                    null,
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * RF-05.7 / RF-03.1: reapertura en garantía.
     *
     * Emite el aviso a Coordinación y, si la máquina está bajo vigilancia
     * sanitaria, una nueva Alerta Inmediata de Frío del ciclo reabierto (el
     * repositorio ya limpió `cold_alert_sent_at`).
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueReopened(Incident $incident, ?Machine $machine = null, ?Location $location = null): int
    {
        return $this->transactions->runInTransaction(function () use ($incident, $machine, $location): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'reopened_at' => $incident->getReopenedAt() ?? $this->now(),
                'reopen_reason' => $incident->getReopenReason(),
                'description' => $incident->getReopenReason() ?? $incident->getDescription(),
                'reopened_by_label' => 'Responsable de Sede',
            ]);

            $count = 0;
            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_REOPENED_COORDINATOR,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $count += $this->enqueueColdChainAlertInternal($incident, $machine, $location);

            return $count;
        });
    }

    /**
     * RF-05.7: alerta de Avería Crónica tras la 3.ª reincidencia.
     *
     * Se invoca desde el callback que el repositorio ejecuta DENTRO de la misma
     * transacción en la que sella la marca y lanza `ChronicIncidentException`.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueChronicBreakdown(Incident $incident): int
    {
        return $this->transactions->runInTransaction(function () use ($incident): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'event_at' => $this->now(),
                'reopen_count' => 3,
            ]);

            $count = 0;
            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::CHRONIC_BREAKDOWN_ALERT,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $count += $this->queueWebhook($incident, NotificationTemplate::CHRONIC_BREAKDOWN_ALERT, $payload) ? 1 : 0;

            return $count;
        });
    }

    /**
     * RF-05.8 / RF-07.1: cancelación del expediente.
     *
     * Cancela por obsolescencia los avisos pendientes anteriores (salvo los dos
     * de cancelación) y encola el aviso informativo a la Sede y, si procede, al
     * Ciudadano QR. La advertencia de máquina bloqueada se transmite mediante
     * `blocked_after_72h`; el renderizador decide cómo destacarla.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueCancellation(Incident $incident, bool $blockedAfter72Hours = false): int
    {
        return $this->transactions->runInTransaction(function () use ($incident, $blockedAfter72Hours): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $this->outbox->cancelPendingForIncident(
                (int)$incident->getId(),
                'El expediente fue cancelado; el aviso pendiente ya no tiene propósito (RF-07.1).',
                [
                    NotificationTemplate::INCIDENT_CANCELLED_SITE->value,
                    NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR->value,
                ]
            );

            $payload = $this->basePayload($incident, $machine, $location, [
                'cancelled_at' => $incident->getCancelledAt() ?? $this->now(),
                'cancellation_reason_label' => $blockedAfter72Hours
                    ? 'Cancelación por inactividad tras 72 horas hábiles en espera de acceso'
                    : 'Cierre administrativo del expediente',
                'blocked_after_72h' => $blockedAfter72Hours,
            ]);

            $origin = $this->isCitizenReportedIncident($incident)
                ? self::ORIGIN_CITIZEN_QR
                : self::ORIGIN_LOCATION_PORTAL;

            $count = 0;
            foreach ($this->locationRecipients($location, $incident, $origin) as $email) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_CANCELLED_SITE,
                    $email,
                    self::ROLE_LOCATION,
                    null,
                    (int)$location->getId(),
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            $citizenEmail = $this->citizenReporterEmail($incident);
            if ($citizenEmail !== null) {
                $count += $this->queueEmail(
                    NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR,
                    $citizenEmail,
                    self::ROLE_CITIZEN_QR,
                    null,
                    null,
                    $incident,
                    $payload
                ) ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * RF-05.9: acuse puntual al segundo informador QR.
     *
     * No toca `reporter_email` del expediente original: sólo emite un acuse al
     * buzón que acaba de aportar la observación.
     *
     * @return int Filas efectivamente encoladas (0 o 1).
     */
    public function enqueueCitizenObservationAck(
        Incident $incident,
        ?string $reporterEmail,
        string $observationText
    ): int {
        $email = $this->validEmail($reporterEmail);
        if ($email === null || !$this->isCitizenReportedIncident($incident)) {
            return 0;
        }

        return $this->transactions->runInTransaction(function () use ($incident, $email, $observationText): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'observation_text' => trim($observationText),
                'event_at' => $this->now(),
            ]);

            return $this->queueEmail(
                NotificationTemplate::CITIZEN_QR_OBSERVATION_ACK,
                $email,
                self::ROLE_CITIZEN_QR,
                null,
                null,
                $incident,
                $payload
            ) ? 1 : 0;
        });
    }

    /**
     * RF-03.3: Cuarentena Sanitaria a las 4 h naturales.
     *
     * Emite la alerta crítica interna (Coordinación + técnico asignado o guardia
     * + Webhook), deduplicada por episodio de ciclo, y el aviso preventivo a la
     * Sede. El aviso a la Sede lleva `dedup_key` propio: una re-ejecución del
     * barrido no vuelve a alarmar al cliente por la misma cuarentena.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueSanitaryQuarantine(
        Incident $incident,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        return $this->transactions->runInTransaction(function () use ($incident, $machine, $location): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'event_at' => $this->now(),
                'on_call_technician_count' => $this->onCallTechnicians->countOnCallTechnicians(),
            ]);
            $cycle = $this->cycleKey($incident);

            $count = 0;
            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::SANITARY_QUARANTINE_TRIGGERED,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:quarantine:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
                ) ? 1 : 0;
            }

            foreach ($this->technicalRecipients($incident) as $technician) {
                $count += $this->queueEmail(
                    NotificationTemplate::SANITARY_QUARANTINE_TRIGGERED,
                    $technician['email'],
                    self::ROLE_TECHNICIAN,
                    $technician['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:quarantine:u%d', (int)$incident->getId(), $cycle, $technician['id'])
                ) ? 1 : 0;
            }

            $count += $this->queueWebhook(
                $incident,
                NotificationTemplate::SANITARY_QUARANTINE_TRIGGERED,
                $payload,
                sprintf('inc:%d:cyc:%s:quarantine:webhook', (int)$incident->getId(), $cycle)
            ) ? 1 : 0;

            $siteEmail = $this->validEmail($location->getContactEmail());
            if ($siteEmail !== null) {
                $count += $this->queueEmail(
                    NotificationTemplate::SITE_SANITARY_QUARANTINE_NOTICE,
                    $siteEmail,
                    self::ROLE_LOCATION,
                    null,
                    (int)$location->getId(),
                    $incident,
                    $payload,
                    sprintf('inc:%d:quarantine_site:l%d', (int)$incident->getId(), (int)$location->getId())
                ) ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * RF-04.1: Alerta de Inminencia de SLA de 2h sin técnico asignado.
     *
     * El sellado anti-spam de `sla_2h_alert_sent_at` lo realiza el vigilante
     * (`NotificationDispatcherService`) en la misma transacción; este método sólo
     * materializa los destinatarios y las claves de deduplicación por ciclo.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueSla2hUnassignedAlert(Incident $incident): int
    {
        return $this->transactions->runInTransaction(function () use ($incident): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->slaPayload($incident, $machine, $location);
            $cycle = $this->cycleKey($incident);
            $count = 0;

            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:sla_2h:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
                ) ? 1 : 0;
            }

            $count += $this->queueWebhook(
                $incident,
                NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED,
                $payload,
                sprintf('inc:%d:cyc:%s:sla_2h:webhook', (int)$incident->getId(), $cycle)
            ) ? 1 : 0;

            return $count;
        });
    }

    /**
     * RF-04.2: Alerta Crítica de Inminencia de SLA de 3h sin inicio de
     * intervención.
     *
     * Destinatarios: Coordinadores activos, el técnico asignado (si existe) y el
     * canal corporativo de emergencias. El aviso al técnico es nominal y directo:
     * es él quien debe arrancar la intervención en la máquina de frío.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueSla3hUnstartedAlert(Incident $incident): int
    {
        return $this->transactions->runInTransaction(function () use ($incident): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->slaPayload($incident, $machine, $location);
            $cycle = $this->cycleKey($incident);
            $count = 0;

            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:sla_3h:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
                ) ? 1 : 0;
            }

            $assigned = $this->assignedTechnicianRecipient($incident);
            if ($assigned !== null) {
                $count += $this->queueEmail(
                    NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED,
                    $assigned['email'],
                    self::ROLE_TECHNICIAN,
                    $assigned['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:sla_3h:u%d', (int)$incident->getId(), $cycle, $assigned['id'])
                ) ? 1 : 0;
            }

            $count += $this->queueWebhook(
                $incident,
                NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED,
                $payload,
                sprintf('inc:%d:cyc:%s:sla_3h:webhook', (int)$incident->getId(), $cycle)
            ) ? 1 : 0;

            return $count;
        });
    }

    /**
     * RF-03.4: escalado por contención persistente del barrido sanitario.
     *
     * Una única alerta por episodio de escalado: la clave de deduplicación usa
     * `first_detected_at`, que identifica el episodio (no la pasada).
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueSanitarySweepEscalated(
        Incident $incident,
        int $consecutivePasses,
        string $episodeKey
    ): int {
        return $this->transactions->runInTransaction(function () use (
            $incident,
            $consecutivePasses,
            $episodeKey
        ): int {
            $machine = $this->machineFor($incident);
            $location = $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'event_at' => $this->now(),
                'contentions_count' => $consecutivePasses,
                'contention_passes' => $consecutivePasses,
                'episode_key' => $episodeKey,
            ]);

            $safeEpisode = preg_replace('/[^A-Za-z0-9:_-]/', '_', $episodeKey) ?? 'episode';
            $count = 0;

            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::SANITARY_SWEEP_ESCALATED,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('sweep:esc:%s:u%d', $safeEpisode, $coordinator['id'])
                ) ? 1 : 0;
            }

            $count += $this->queueWebhook(
                $incident,
                NotificationTemplate::SANITARY_SWEEP_ESCALATED,
                $payload,
                sprintf('sweep:esc:%s:webhook', $safeEpisode)
            ) ? 1 : 0;

            return $count;
        });
    }

    /**
     * RF-06.3 / Resoluciones QA 1 y QA 2: efectos de la Asunción Oportunista In Situ.
     *
     * La matriz de encolado distingue tres desenlaces que el técnico no debe
     * pagar con correos de más:
     *
     * 1. **Asunción simple** de un ticket registrado o de un compañero: cero
     *    correo a la Sede. El expediente ya estaba abierto y su informador no
     *    necesita saber que cambió de manos (RF-06.3.1).
     * 2. **Traspaso desde otro técnico** (`$previousTechnicianId`): se cancela por
     *    obsolescencia su aviso de «Nueva asignación» pendiente —correo y
     *    Telegram— y se le envía la desasignación SOLO por correo, sin botón
     *    (RF-06.3.3 y Módulo 15 `RF-TG-06`).
     * 3. **Reapertura in situ en garantía** (`$before` resuelto): la Sede recibe
     *    `INCIDENT_REOPENED_IN_SITU_SITE` y Coordinación
     *    `INCIDENT_REOPENED_COORDINATOR`, ambos por correo (RF-06.3.2).
     *
     * Debe invocarse dentro de la transacción de negocio: el traspaso y su
     * aviso de desasignación viajan en el mismo commit que el cambio de titular.
     *
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueInSituAssumption(
        Incident $before,
        Incident $after,
        ?int $previousTechnicianId = null,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        return $this->transactions->runInTransaction(function () use (
            $before,
            $after,
            $previousTechnicianId,
            $machine,
            $location
        ): int {
            $machine ??= $this->machineFor($after);
            $location ??= $this->locationFor($after);
            if ($location === null) {
                return 0;
            }

            $count = 0;
            $incomingId = $after->getAssignedTechnicianId();

            if ($previousTechnicianId !== null && $previousTechnicianId !== $incomingId) {
                $count += $this->notifySupersededTechnician($after, $previousTechnicianId, $machine, $location);
            }

            // Solo la reapertura en garantía legitima un correo nuevo a la Sede
            // (RF-06.3.1). La asunción de un ticket ya abierto no deja rastro en
            // la bandeja: el informador ya conocía el expediente.
            if ($before->getStatus() === IncidentStatus::RESOLVED) {
                $count += $this->queueInSituReopenNotices($after, $machine, $location);
            }

            return $count;
        });
    }

    /**
     * RF-03.2.2 / Resolución QA 1: el técnico pospone una parada crítica o de
     * riesgo sanitario en OTRA sede para atender una avería oportunista.
     *
     * La alerta va a los coordinadores activos con prioridad `CRITICAL_SANITARY`
     * (la hereda de la plantilla) y al canal corporativo de emergencias solo si
     * alguna parada postergada tiene vigilancia sanitaria/térmica: el webhook no
     * debe recibir ruido por una parada crítica comercial.
     *
     * @param list<array{ticket_code: string, location_name: string, is_sanitary: bool}> $postponedStops
     * @return int Filas efectivamente encoladas.
     */
    public function enqueueOpportunisticColdRoutePostponed(
        Incident $incident,
        array $postponedStops,
        ?Machine $machine = null,
        ?Location $location = null
    ): int {
        if ($postponedStops === []) {
            return 0;
        }

        return $this->transactions->runInTransaction(function () use (
            $incident,
            $postponedStops,
            $machine,
            $location
        ): int {
            $machine ??= $this->machineFor($incident);
            $location ??= $this->locationFor($incident);
            if ($location === null) {
                return 0;
            }

            $first = $postponedStops[0];
            $sanitary = false;
            foreach ($postponedStops as $stop) {
                if (($stop['is_sanitary'] ?? false) === true) {
                    $sanitary = true;
                    break;
                }
            }

            $payload = $this->basePayload($incident, $machine, $location, [
                'event_at' => $this->now(),
                'postponed_stops_count' => count($postponedStops),
                'postponed_stop_location' => trim((string)($first['location_name'] ?? '')),
                'postponed_stop_ticket' => trim((string)($first['ticket_code'] ?? '')),
            ]);

            $cycle = $this->cycleKey($incident);
            $count = 0;

            foreach ($this->coordinatorRecipients() as $coordinator) {
                $count += $this->queueEmail(
                    NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT,
                    $coordinator['email'],
                    self::ROLE_COORDINATOR,
                    $coordinator['id'],
                    null,
                    $incident,
                    $payload,
                    sprintf('inc:%d:cyc:%s:postponed:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
                ) ? 1 : 0;
            }

            if ($sanitary) {
                $count += $this->queueWebhook(
                    $incident,
                    NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT,
                    $payload,
                    sprintf('inc:%d:cyc:%s:postponed:webhook', (int)$incident->getId(), $cycle)
                ) ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * RF-06.3.3: invalida el aviso de asignación del técnico reemplazado y le
     * envía la desasignación exclusivamente por correo.
     *
     * La cancelación se restringe a `TECHNICIAN_ASSIGNED` de ESTE expediente y
     * ESTE destinatario: los avisos pendientes de otros tickets del mismo técnico
     * siguen siendo válidos y la bandeja no debe vaciarse en bloque.
     *
     * @return int Filas encoladas (0 o 1).
     */
    private function notifySupersededTechnician(
        Incident $incident,
        int $previousTechnicianId,
        ?Machine $machine,
        Location $location
    ): int {
        $this->outbox->cancelPendingForIncidentRecipient(
            (int)$incident->getId(),
            $previousTechnicianId,
            NotificationObsolescencePolicy::REASON_ASSIGNMENT_SUPERSEDED_IN_SITU,
            [NotificationTemplate::TECHNICIAN_ASSIGNED->value]
        );

        $technician = $this->users->findById($previousTechnicianId, false);
        $email = $this->validEmail($technician?->getEmail());
        if ($technician === null || $email === null) {
            return 0;
        }

        $payload = $this->basePayload($incident, $machine, $location, [
            'event_at' => $this->now(),
        ]);

        // El canal es EMAIL por mandato de RF-TG-06: la desasignación no viaja
        // por Telegram ni lleva botón a un expediente que ya no es del saliente.
        return $this->queueEmail(
            NotificationTemplate::TECHNICIAN_UNASSIGNED,
            $email,
            self::ROLE_TECHNICIAN,
            $previousTechnicianId,
            null,
            $incident,
            $payload,
            sprintf('inc:%d:takeover:%d:unassigned', (int)$incident->getId(), $previousTechnicianId)
        ) ? 1 : 0;
    }

    /**
     * RF-06.3.2 / RF-06.3.4: reapertura in situ en garantía.
     *
     * La Sede y Coordinación saben que el expediente vuelve a estar vivo y ya se
     * está atendiendo; la alerta de frío del nuevo ciclo se emite con la misma
     * puerta anti-spam que el resto del catálogo.
     *
     * @return int Filas efectivamente encoladas.
     */
    private function queueInSituReopenNotices(
        Incident $incident,
        ?Machine $machine,
        Location $location
    ): int {
        $payload = $this->basePayload($incident, $machine, $location, [
            'event_at' => $this->now(),
            'reopened_at' => $incident->getReopenedAt() ?? $this->now(),
            'reopen_reason' => $incident->getReopenReason(),
        ]);
        $cycle = $this->cycleKey($incident);
        $count = 0;

        $siteEmail = $this->validEmail($location->getContactEmail());
        if ($siteEmail !== null) {
            $count += $this->queueEmail(
                NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE,
                $siteEmail,
                self::ROLE_LOCATION,
                null,
                (int)$location->getId(),
                $incident,
                $payload,
                sprintf('inc:%d:cyc:%s:insitu-reopen:site:%s', (int)$incident->getId(), $cycle, $siteEmail)
            ) ? 1 : 0;
        }

        foreach ($this->coordinatorRecipients() as $coordinator) {
            $count += $this->queueEmail(
                NotificationTemplate::INCIDENT_REOPENED_COORDINATOR,
                $coordinator['email'],
                self::ROLE_COORDINATOR,
                $coordinator['id'],
                null,
                $incident,
                $payload,
                sprintf('inc:%d:cyc:%s:insitu-reopen:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
            ) ? 1 : 0;
        }

        $count += $this->enqueueColdChainAlertInternal($incident, $machine, $location);

        return $count;
    }

    /**
     * Núcleo de RF-03.1, compartido por apertura, reapertura, asignación y la
     * API pública de la alerta. Debe invocarse ya dentro de una transacción.
     */
    private function enqueueColdChainAlertInternal(
        Incident $incident,
        ?Machine $machine,
        ?Location $location
    ): int {
        $machine ??= $this->machineFor($incident);
        $location ??= $this->locationFor($incident);
        if ($location === null || !$this->shouldTriggerColdAlert($incident, $machine)) {
            return 0;
        }

        // Anti-spam por ciclo: la marca se sella en el mismo commit que las filas.
        if ($incident->getColdAlertSentAt() !== null) {
            return 0;
        }

        $onCallCount = $this->onCallTechnicians->countOnCallTechnicians();
        $payload = $this->basePayload($incident, $machine, $location, [
            'event_at' => $this->now(),
            'on_call_technician_count' => $onCallCount,
        ]);
        $cycle = $this->cycleKey($incident);

        $count = 0;
        foreach ($this->coordinatorRecipients() as $coordinator) {
            $count += $this->queueEmail(
                NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
                $coordinator['email'],
                self::ROLE_COORDINATOR,
                $coordinator['id'],
                null,
                $incident,
                $payload,
                sprintf('inc:%d:cyc:%s:cold:u%d', (int)$incident->getId(), $cycle, $coordinator['id'])
            ) ? 1 : 0;
        }

        foreach ($this->technicalRecipients($incident) as $technician) {
            $count += $this->queueEmail(
                NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
                $technician['email'],
                self::ROLE_TECHNICIAN,
                $technician['id'],
                null,
                $incident,
                $payload,
                sprintf('inc:%d:cyc:%s:cold:u%d', (int)$incident->getId(), $cycle, $technician['id'])
            ) ? 1 : 0;
        }

        $count += $this->queueWebhook(
            $incident,
            NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
            $payload,
            sprintf('inc:%d:cyc:%s:cold:webhook', (int)$incident->getId(), $cycle)
        ) ? 1 : 0;

        // La marca del ciclo se sella aunque todos los destinatarios coincidieran
        // con una ejecución previa interrumpida: la alerta ya está en la cola y
        // volver a intentarla multiplicaría los correos (RF-04.4 por analogía).
        $this->cycles->markColdAlertSent((int)$incident->getId());

        return $count;
    }

    /**
     * Payload de los dos umbrales de inminencia de SLA (RF-04.1, RF-04.2).
     *
     * `elapsed_hours` se mide sobre el ciclo de atención vigente —la reapertura
     * en garantía arranca uno nuevo (RF-04.5)— y en tiempo natural continuo
     * 24/7: ni la pausa ni el cierre del inmueble detienen el reloj biológico
     * (RF-04.3).
     *
     * @return array<string, mixed>
     */
    private function slaPayload(Incident $incident, ?Machine $machine, Location $location): array
    {
        $cycleStartedAt = $incident->getCycleStartedAt();
        $elapsedHours = 0.0;
        if (is_string($cycleStartedAt) && trim($cycleStartedAt) !== '') {
            $startedTimestamp = strtotime($cycleStartedAt);
            if ($startedTimestamp !== false) {
                $elapsedHours = max(0.0, round((time() - $startedTimestamp) / 3600, 1));
            }
        }

        return $this->basePayload($incident, $machine, $location, [
            'event_at' => $this->now(),
            'elapsed_hours' => $elapsedHours,
            'on_call_technician_count' => $this->onCallTechnicians->countOnCallTechnicians(),
        ]);
    }

    /**
     * Técnico asignado vigente, si tiene correo válido (RF-04.2).
     *
     * @return array{id: int, email: string}|null
     */
    private function assignedTechnicianRecipient(Incident $incident): ?array
    {
        $assignedId = $incident->getAssignedTechnicianId();
        if ($assignedId === null) {
            return null;
        }

        $technician = $this->users->findById($assignedId, true);
        $email = $this->validEmail($technician?->getEmail());
        if ($technician === null || $email === null) {
            return null;
        }

        return ['id' => $assignedId, 'email' => $email];
    }

    /**
     * Construye y encola una fila de correo.
     *
     * @param array<string, mixed> $payload
     */
    /**
     * Encola un aviso por el canal personal del técnico (Módulo 15, RF-TG-02).
     *
     * Devuelve `null` —sin crear fila— cuando falta cualquiera de las dos
     * condiciones que hacen posible la entrega: un `chat_id` con formato válido en
     * la vinculación del usuario y un token de bot declarado en el entorno. Es la
     * traducción del requisito «sin vinculación, sin aviso y sin ruido»: la cola
     * no debe contener envíos que no pueden salir, y la carencia se ve en el panel
     * de administración, no como un fallo repetido en la bandeja.
     *
     * @param array<string, mixed> $payload Payload neutro ya del expediente.
     */
    private function queueTelegram(
        NotificationTemplate $template,
        ?string $chatId,
        ?int $recipientUserId,
        Incident $incident,
        array $payload
    ): ?NotificationOutboxItem {
        $chatId = $chatId !== null ? trim($chatId) : null;
        if ($chatId === null || $chatId === '' || !TelegramTransport::isValidChatId($chatId)) {
            return null;
        }

        if (SecretProvider::telegramBotToken() === null) {
            return null;
        }

        return $this->queue(
            $template,
            NotificationChannel::TELEGRAM,
            $template->defaultPriority(),
            $chatId,
            self::ROLE_TECHNICIAN,
            $recipientUserId,
            null,
            $incident,
            $payload,
            null
        );
    }

    private function queueEmail(
        NotificationTemplate $template,
        string $recipient,
        string $recipientRole,
        ?int $recipientUserId,
        ?int $recipientLocationId,
        Incident $incident,
        array $payload,
        ?string $dedupKey = null,
        ?NotificationPriority $priority = null,
        ?NotificationChannel $channel = null
    ): ?NotificationOutboxItem {
        $email = $this->validEmail($recipient);
        if ($email === null) {
            return null;
        }

        return $this->queue(
            $template,
            $channel ?? NotificationChannel::EMAIL,
            $priority ?? $template->defaultPriority(),
            $email,
            $recipientRole,
            $recipientUserId,
            $recipientLocationId,
            $incident,
            $payload,
            $dedupKey
        );
    }

    /**
     * Construye y encola la fila del canal corporativo, si está configurado.
     *
     * @param array<string, mixed> $payload
     */
    private function queueWebhook(
        Incident $incident,
        NotificationTemplate $template,
        array $payload,
        ?string $dedupKey = null,
        ?NotificationPriority $priority = null
    ): ?NotificationOutboxItem {
        $settings = $this->settings->findCurrent();
        if (!$settings->isConfiguredAndEnabled()) {
            return null;
        }

        $url = $settings->getWebhookUrl();
        if ($url === null || !WebhookUrlSecurityValidator::isValid($url)) {
            return null;
        }

        return $this->queue(
            $template,
            NotificationChannel::WEBHOOK,
            $priority ?? $template->defaultPriority(),
            $url,
            self::ROLE_WEBHOOK,
            null,
            null,
            $incident,
            $payload,
            $dedupKey
        );
    }

    /**
     * Núcleo único de construcción: sanitiza, renderiza el asunto y persiste.
     *
     * @param array<string, mixed> $payload
     */
    private function queue(
        NotificationTemplate $template,
        NotificationChannel $channel,
        NotificationPriority $priority,
        string $recipient,
        string $recipientRole,
        ?int $recipientUserId,
        ?int $recipientLocationId,
        Incident $incident,
        array $payload,
        ?string $dedupKey
    ): ?NotificationOutboxItem {
        $sanitized = NotificationPayloadSanitizer::sanitize(
            $recipientRole,
            $template->value,
            $payload
        );

        $message = $this->renderer->render($template, $sanitized);

        return $this->outbox->enqueue(new NotificationOutboxItem(
            id: null,
            incidentId: $incident->getId() !== null ? (int)$incident->getId() : null,
            channel: $channel,
            priority: $priority,
            template: $template,
            recipient: $recipient,
            recipientRole: $recipientRole,
            recipientUserId: $recipientUserId,
            recipientLocationId: $recipientLocationId,
            subject: $message->subject,
            payload: $sanitized,
            dedupKey: $dedupKey
        ));
    }

    /**
     * Payload neutral común a todas las plantillas.
     *
     * Sólo viajan claves que el renderizador puede leer y que el sanitizador
     * conoce. Los diagnósticos, acciones, costes y notas internas NO se copian
     * aquí: no existen en el payload de origen y, por tanto, no pueden acabar
     * en la cola (Art. V.4). Las claves oportunistas son neutras (`modo`,
     * `submotivo`, `informante`); la nota de traspaso se queda en
     * `incident_comments` con `is_internal = TRUE` y jamás entra en este método.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function basePayload(
        Incident $incident,
        ?Machine $machine,
        Location $location,
        array $extra = []
    ): array {
        $machineLabel = null;
        if ($machine !== null) {
            $model = trim($machine->getModel());
            $machineLabel = $machine->getCode() . ($model !== '' ? ' · ' . $model : '');
        }

        $technicianLabel = null;
        if ($incident->getAssignedTechnicianId() !== null) {
            $operator = $incident->getTechnicianOperatorCode();
            $operator = $operator !== null && trim($operator) !== ''
                ? trim($operator)
                : sprintf('%02d', $incident->getAssignedTechnicianId());
            $technicianLabel = 'Servicio Técnico Oficial · Operador #' . $operator;
            if ($incident->getTechnicianName() !== null) {
                $technicianLabel = $incident->getTechnicianName() . ' · ' . $technicianLabel;
            }
        }

        return array_filter(array_merge([
            'ticket_code' => $incident->getTicketCode(),
            'machine_code' => $machine?->getCode() ?? $incident->getMachineCode(),
            'machine_label' => $machineLabel,
            'site_code' => $location->getSiteCode() !== '' ? $location->getSiteCode() : $incident->getLocationSiteCode(),
            'site_name' => $location->getName() !== '' ? $location->getName() : $incident->getLocationName(),
            'site_address' => $location->getAddress(),
            'floor_area' => $machine?->getFloorWing() ?? '',
            'category_label' => $incident->getCategory()->label(),
            'urgency_label' => $incident->getUrgency()->label(),
            'description' => $incident->getDescription(),
            'opened_at' => $incident->getCreatedAt(),
            'resolved_at' => $incident->getResolvedAt(),
            'reopened_at' => $incident->getReopenedAt(),
            'technician_id' => $incident->getAssignedTechnicianId(),
            'operator_code' => $incident->getTechnicianOperatorCode(),
            'technician_label' => $technicianLabel,
            // Contexto oportunista (Módulo 16): la Sede ve la modalidad y el
            // informante verbal; el Ciudadano QR los pierde en el sanitizador y
            // la nota de traspaso nunca viaja aquí (Art. V.4, Resolución QA 5).
            'is_opportunistic' => $incident->isOpportunistic() ? true : null,
            'opportunistic_mode' => $incident->getOpportunisticMode()?->value,
            'opportunistic_mode_label' => $incident->getOpportunisticMode()?->label(),
            'opportunistic_origin_reason' => $incident->getOpportunisticOriginReason()?->value,
            'opportunistic_origin_label' => $incident->getOpportunisticOriginReason()?->label(),
            'opportunistic_informant_name' => $incident->getOpportunisticInformantName(),
        ], $extra), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Destinatarios de Sede (RF-02.3).
     *
     * @return list<string>
     */
    private function locationRecipients(
        Location $location,
        Incident $incident,
        string $origin,
        ?string $reporterEmail = null
    ): array {
        $recipients = [];
        $official = $this->validEmail($location->getContactEmail());
        if ($official !== null) {
            $recipients[] = $official;
        }

        if ($origin === self::ORIGIN_LOCATION_PORTAL) {
            $reporter = $this->validEmail($reporterEmail ?? $incident->getReporterEmail());
            if ($reporter !== null && $reporter !== $official) {
                $recipients[] = $reporter;
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * Coordinadores activos con correo válido.
     *
     * @return list<array{id: int, email: string}>
     */
    private function coordinatorRecipients(): array
    {
        $recipients = [];
        foreach ($this->users->findAll(['status' => 'active', 'role' => self::ROLE_COORDINATOR]) as $row) {
            $email = $this->validEmail($row['email'] ?? null);
            $id = (int)($row['id'] ?? 0);
            if ($email !== null && $id > 0) {
                $recipients[] = ['id' => $id, 'email' => $email];
            }
        }

        return $recipients;
    }

    /**
     * Técnicos destinatarios de una alerta crítica (RF-03.1).
     *
     * Si el ticket tiene técnico asignado, sólo él; si no, los técnicos de
     * guardia activos. La ausencia de ambos se refleja en el payload para que el
     * renderizador emita la advertencia de RF-03.2.
     *
     * @return list<array{id: int, email: string}>
     */
    private function technicalRecipients(Incident $incident): array
    {
        $assignedId = $incident->getAssignedTechnicianId();
        if ($assignedId !== null) {
            $technician = $this->users->findById($assignedId, true);
            $email = $this->validEmail($technician?->getEmail());
            if ($technician !== null && $email !== null) {
                return [['id' => $assignedId, 'email' => $email]];
            }

            return [];
        }

        $recipients = [];
        foreach ($this->onCallTechnicians->findOnCallTechnicians() as $technician) {
            $email = $this->validEmail($technician->getEmail());
            if ($email !== null) {
                $recipients[] = ['id' => $technician->getId(), 'email' => $email];
            }
        }

        return $recipients;
    }

    /**
     * ¿Concurre el supuesto de Alerta Inmediata de Frío de RF-03.1?
     */
    private function shouldTriggerColdAlert(Incident $incident, ?Machine $machine): bool
    {
        if (in_array($incident->getStatus(), [
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED,
            IncidentStatus::CANCELLED,
        ], true)) {
            return false;
        }

        if ($machine === null || !$machine->getMachineType()->requiresSanitaryWatch()) {
            return false;
        }

        return $incident->getUrgency() === UrgencyLevel::CRITICAL
            || in_array($incident->getCategory(), self::COLD_CHAIN_CATEGORIES, true);
    }

    /**
     * Correo del ciudadano que abrió el expediente por QR (sólo si ese fue el
     * origen; un informador de Sede nunca recibe la proyección ciudadana).
     */
    private function citizenReporterEmail(Incident $incident): ?string
    {
        $email = $this->validEmail($incident->getReporterEmail());
        if ($email === null || !$this->isCitizenReportedIncident($incident)) {
            return null;
        }

        return $email;
    }

    /**
     * ¿La apertura del expediente se encoló como aviso ciudadano QR?
     *
     * La huella es la fila de apertura ciudadana, no `reporter_email`: un
     * responsable de Sede también puede dejar su correo en el formulario y no
     * debe recibir nunca la proyección pública.
     */
    private function isCitizenReportedIncident(Incident $incident): bool
    {
        if ($incident->getId() === null) {
            return false;
        }

        return $this->outbox->hasTemplateForIncident(
            (int)$incident->getId(),
            NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR->value
        );
    }

    /**
     * Clave del ciclo de atención vigente (RF-04.5): la reapertura arranca uno
     * nuevo y permite volver a alertar de frío/cuarentena.
     */
    private function cycleKey(Incident $incident): string
    {
        $raw = $incident->getCycleStartedAt()
            ?? $incident->getReopenedAt()
            ?? $incident->getCreatedAt()
            ?? 'unknown';

        return preg_replace('/[^0-9A-Za-z]/', '', (string)$raw) ?? 'unknown';
    }

    private function machineFor(Incident $incident): ?Machine
    {
        return $this->machines->findById($incident->getMachineId());
    }

    private function locationFor(Incident $incident): ?Location
    {
        return $this->locations->findById($incident->getLocationId());
    }

    private function validEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    /**
     * Instante operativo con zona, como el resto de marcas del módulo.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
    }
}
