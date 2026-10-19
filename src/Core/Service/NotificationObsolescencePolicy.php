<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;

/**
 * NotificationObsolescencePolicy — Evaluación de obsolescencia antes del envío (T-NOTIF-03).
 *
 * Cumple con el requisito RF-07.1:
 * Si en el momento en que el proceso en segundo plano va a despachar una notificación en estado
 * "Pendiente" o "Pendiente de reintento" se comprueba que el estado actual de la incidencia o
 * del destinatario dejó sin validez el propósito del mensaje:
 *
 * (a) Alerta de "2h sin asignar" (SLA_IMMINENCE_2H_UNASSIGNED): si el ticket ya tiene técnico asignado.
 * (b) Alerta de "3h sin iniciar" (SLA_IMMINENCE_3H_UNSTARTED): si el ticket ya entró en "En curso" o estado posterior (started_at IS NOT NULL o status != ASSIGNED).
 * (c) Avisos de "Nueva asignación" (TECHNICIAN_ASSIGNED): a un técnico que ya ha sido reemplazado por otro en el ticket actual.
 * (d) Avisos de pausa (PENDING_INFO_SITE_REQUEST): cuando la pausa ya fue reanudada (el ticket ya no está en PENDING_INFO).
 * (e) Cualquier aviso operativo previo cuando la incidencia ha pasado a estado CANCELLED o CLOSED:
 *     (salvo los propios avisos de cancelación o confirmación de resolución pertinentes:
 *      INCIDENT_CANCELLED_SITE, INCIDENT_CANCELLED_CITIZEN_QR, INCIDENT_RESOLVED_SITE, INCIDENT_RESOLVED_CITIZEN_QR).
 * (f) Avisos dirigidos a un usuario interno que ha sido desactivado (user.is_active === false),
 *     o avisos del canal Webhook cuando el canal Webhook ha sido desactivado (webhook_enabled === false).
 *
 * En cualquiera de estos casos, la notificación NO debe enviarse y debe cancelarse con estado
 * CANCELLED_OBSOLETE documentando el motivo exacto en `obsolete_reason`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas, determinista.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationObsolescencePolicy
{
    /** Motivo cuando el ticket ya tiene técnico asignado. */
    public const REASON_SLA_2H_ALREADY_ASSIGNED = 'Ticket already has an assigned technician';

    /** Motivo cuando la intervención ya ha comenzado. */
    public const REASON_SLA_3H_ALREADY_STARTED = 'Incident intervention has already started';

    /** Motivo cuando el técnico asignado fue reemplazado antes del despacho. */
    public const REASON_TECHNICIAN_SUPERSEDED = 'Technician was unassigned or superseded before dispatch';

    /**
     * Motivo cuando otro técnico presente en la sede asume el expediente in situ
     * (RF-06.3.3): el aviso de «Nueva asignación» pendiente del compañero
     * reemplazado queda invalidado antes de que salga.
     */
    public const REASON_ASSIGNMENT_SUPERSEDED_IN_SITU = 'Assignment superseded by an in-situ opportunistic takeover';

    /** Motivo cuando la pausa por información ya fue reanudada. */
    public const REASON_PAUSE_ALREADY_RESUMED = 'Incident is no longer paused in pending info status';

    /** Motivo cuando el expediente fue cancelado antes del despacho del aviso previo. */
    public const REASON_INCIDENT_ALREADY_CANCELLED = 'Incident was cancelled before dispatch of prior operational notification';

    /** Motivo cuando el expediente fue cerrado definitivamente antes del despacho. */
    public const REASON_INCIDENT_ALREADY_CLOSED = 'Incident was closed before dispatch of prior operational notification';

    /** Motivo cuando el usuario destinatario interno fue desactivado. */
    public const REASON_RECIPIENT_USER_DEACTIVATED = 'Recipient internal user was deactivated';

    /** Motivo cuando el canal Webhook de emergencias fue desactivado. */
    public const REASON_WEBHOOK_CHANNEL_DISABLED = 'Emergency webhook channel is currently disabled';

    /**
     * Evalúa si una notificación candidata al despacho ha quedado obsoleta.
     *
     * @param NotificationOutboxItem $item Elemento de la bandeja outbox a despachar.
     * @param Incident|null $currentIncident Estado actual del expediente de incidencia (null si la notificación no pertenece a un incidente o no existe).
     * @param bool $isRecipientUserActive Si el destinatario es un usuario interno, indica si sigue activo (por defecto true).
     * @param bool $isWebhookChannelEnabled Si el canal es Webhook, indica si el canal está habilitado globalmente (por defecto true).
     * @return array{is_obsolete: bool, reason: ?string}
     */
    public static function evaluate(
        NotificationOutboxItem $item,
        ?Incident $currentIncident,
        bool $isRecipientUserActive = true,
        bool $isWebhookChannelEnabled = true
    ): array {
        $template = $item->getTemplate();

        // Regla (f): Canal Webhook desactivado
        if ($item->getChannel()->value === 'WEBHOOK' && !$isWebhookChannelEnabled) {
            return [
                'is_obsolete' => true,
                'reason' => self::REASON_WEBHOOK_CHANNEL_DISABLED,
            ];
        }

        // Regla (f): Usuario interno destinatario desactivado
        if ($item->getRecipientUserId() !== null && !$isRecipientUserActive) {
            return [
                'is_obsolete' => true,
                'reason' => self::REASON_RECIPIENT_USER_DEACTIVATED,
            ];
        }

        // Si no hay contexto de incidencia (ej. avisos de sistema autónomos), no aplican las reglas basadas en el ciclo del ticket
        if ($currentIncident === null) {
            return [
                'is_obsolete' => false,
                'reason' => null,
            ];
        }

        $incidentStatus = $currentIncident->getStatus();

        // Regla (e): El expediente fue cancelado o cerrado
        if ($incidentStatus === IncidentStatus::CANCELLED) {
            if ($template !== NotificationTemplate::INCIDENT_CANCELLED_SITE &&
                $template !== NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR
            ) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_INCIDENT_ALREADY_CANCELLED,
                ];
            }
        }

        if ($incidentStatus === IncidentStatus::CLOSED) {
            if ($template !== NotificationTemplate::INCIDENT_RESOLVED_SITE &&
                $template !== NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR
            ) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_INCIDENT_ALREADY_CLOSED,
                ];
            }
        }

        // Regla (a): Alerta de 2h sin asignar cuando el ticket ya tiene técnico asignado
        if ($template === NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED) {
            if ($currentIncident->getAssignedTechnicianId() !== null ||
                $incidentStatus !== IncidentStatus::REGISTERED
            ) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_SLA_2H_ALREADY_ASSIGNED,
                ];
            }
        }

        // Regla (b): Alerta de 3h sin iniciar cuando el ticket ya entró en En Curso o ya inició
        if ($template === NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED) {
            if ($incidentStatus === IncidentStatus::IN_PROGRESS ||
                $incidentStatus === IncidentStatus::PENDING_PARTS ||
                $incidentStatus === IncidentStatus::RESOLVED ||
                $incidentStatus === IncidentStatus::CLOSED ||
                $currentIncident->getStartedAt() !== null
            ) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_SLA_3H_ALREADY_STARTED,
                ];
            }
        }

        // Regla (c): Aviso de nueva asignación a un técnico que ya no es el técnico asignado vigente
        if ($template === NotificationTemplate::TECHNICIAN_ASSIGNED) {
            $targetTechId = $item->getRecipientUserId();
            $currentTechId = $currentIncident->getAssignedTechnicianId();

            if ($targetTechId === null || $currentTechId === null || $targetTechId !== $currentTechId) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_TECHNICIAN_SUPERSEDED,
                ];
            }
        }

        // Regla (d): Aviso de pausa a sede cuando la pausa ya fue reanudada
        if ($template === NotificationTemplate::PENDING_INFO_SITE_REQUEST) {
            if ($incidentStatus !== IncidentStatus::PENDING_INFO) {
                return [
                    'is_obsolete' => true,
                    'reason' => self::REASON_PAUSE_ALREADY_RESUMED,
                ];
            }
        }

        return [
            'is_obsolete' => false,
            'reason' => null,
        ];
    }
}
