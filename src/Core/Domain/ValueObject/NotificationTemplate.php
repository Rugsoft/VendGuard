<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationTemplate
 *
 * Catálogo cerrado de las 22 plantillas de notificación tipificadas (Módulos 14, 16 y 21).
 * Coincide exactamente con el enum `notification_template` de PostgreSQL.
 */
enum NotificationTemplate: string
{
    // 1. Alertas de Emergencia y Sanidad (Art. II)
    case COLD_CHAIN_IMMEDIATE_ALERT         = 'COLD_CHAIN_IMMEDIATE_ALERT';
    case SANITARY_QUARANTINE_TRIGGERED      = 'SANITARY_QUARANTINE_TRIGGERED';
    case SITE_SANITARY_QUARANTINE_NOTICE    = 'SITE_SANITARY_QUARANTINE_NOTICE';
    case SANITARY_SWEEP_ESCALATED           = 'SANITARY_SWEEP_ESCALATED';

    // 2. Inminencia de SLA Sanitario (2h y 3h)
    case SLA_IMMINENCE_2H_UNASSIGNED        = 'SLA_IMMINENCE_2H_UNASSIGNED';
    case SLA_IMMINENCE_3H_UNSTARTED         = 'SLA_IMMINENCE_3H_UNSTARTED';

    // 3. Ciclo de Vida del Expediente
    case INCIDENT_OPENED_SITE               = 'INCIDENT_OPENED_SITE';
    case INCIDENT_OPENED_CITIZEN_QR         = 'INCIDENT_OPENED_CITIZEN_QR';
    case TECHNICIAN_ASSIGNED                = 'TECHNICIAN_ASSIGNED';
    case TECHNICIAN_UNASSIGNED              = 'TECHNICIAN_UNASSIGNED';
    case PENDING_INFO_SITE_REQUEST          = 'PENDING_INFO_SITE_REQUEST';
    case PENDING_INFO_SITE_REPLY            = 'PENDING_INFO_SITE_REPLY';
    case INCIDENT_RESOLVED_SITE             = 'INCIDENT_RESOLVED_SITE';
    case INCIDENT_RESOLVED_CITIZEN_QR       = 'INCIDENT_RESOLVED_CITIZEN_QR';
    case INCIDENT_REOPENED_COORDINATOR      = 'INCIDENT_REOPENED_COORDINATOR';
    case CHRONIC_BREAKDOWN_ALERT            = 'CHRONIC_BREAKDOWN_ALERT';
    case INCIDENT_CANCELLED_SITE            = 'INCIDENT_CANCELLED_SITE';
    case INCIDENT_CANCELLED_CITIZEN_QR      = 'INCIDENT_CANCELLED_CITIZEN_QR';
    case CITIZEN_QR_OBSERVATION_ACK         = 'CITIZEN_QR_OBSERVATION_ACK';

    // 4. Avería oportunista in situ (Módulo 16)
    case OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT = 'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT';
    case INCIDENT_REOPENED_IN_SITU_SITE           = 'INCIDENT_REOPENED_IN_SITU_SITE';

    // 5. Relevo de fin de turno (Módulo 21)
    case TELEGRAM_INCIDENT_ASSIGNMENT              = 'TELEGRAM_INCIDENT_ASSIGNMENT';

    /**
     * Etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::COLD_CHAIN_IMMEDIATE_ALERT => 'Alerta Inmediata de Pérdida de Frío',
            self::SANITARY_QUARANTINE_TRIGGERED => 'Alerta de Cuarentena Sanitaria Activada (4h)',
            self::SITE_SANITARY_QUARANTINE_NOTICE => 'Aviso a Sede de Bloqueo por Cuarentena Sanitaria',
            self::SANITARY_SWEEP_ESCALATED => 'Alerta de Escalado por Contención de Barrido',
            self::SLA_IMMINENCE_2H_UNASSIGNED => 'Inminencia SLA: > 2h sin Técnico Asignado',
            self::SLA_IMMINENCE_3H_UNSTARTED => 'Inminencia SLA: > 3h sin Inicio de Intervención',
            self::INCIDENT_OPENED_SITE => 'Confirmación de Registro de Avería (Sede)',
            self::INCIDENT_OPENED_CITIZEN_QR => 'Confirmación de Registro de Avería (Ciudadano QR)',
            self::TECHNICIAN_ASSIGNED => 'Aviso de Nueva Asignación de Avería',
            self::TECHNICIAN_UNASSIGNED => 'Aviso de Desasignación de Avería',
            self::PENDING_INFO_SITE_REQUEST => 'Solicitud Urgente de Acceso o Información a Sede',
            self::PENDING_INFO_SITE_REPLY => 'Respuesta de Sede a Solicitud de Información',
            self::INCIDENT_RESOLVED_SITE => 'Servicio Restablecido con Garantía 48h (Sede)',
            self::INCIDENT_RESOLVED_CITIZEN_QR => 'Avería Solucionada (Ciudadano QR)',
            self::INCIDENT_REOPENED_COORDINATOR => 'Aviso de Reapertura en Garantía a Coordinación',
            self::CHRONIC_BREAKDOWN_ALERT => 'Alerta de Avería Crónica (3.ª Reincidencia)',
            self::INCIDENT_CANCELLED_SITE => 'Aviso de Cancelación de Expediente (Sede)',
            self::INCIDENT_CANCELLED_CITIZEN_QR => 'Aviso de Cierre de Expediente (Ciudadano QR)',
            self::CITIZEN_QR_OBSERVATION_ACK => 'Acuse de Observación Adicional Registrada (QR)',
            self::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT => 'Alerta a Coordinación por Posponer una Parada Crítica de Frío',
            self::INCIDENT_REOPENED_IN_SITU_SITE => 'Aviso a Sede de Reapertura In Situ',
            self::TELEGRAM_INCIDENT_ASSIGNMENT => 'Aviso Prioritario de Relevo al Técnico',
        };
    }

    /**
     * Prioridad por defecto asignada a esta plantilla según el contrato.
     */
    public function defaultPriority(): NotificationPriority
    {
        return match ($this) {
            self::COLD_CHAIN_IMMEDIATE_ALERT,
            self::SANITARY_QUARANTINE_TRIGGERED,
            self::SITE_SANITARY_QUARANTINE_NOTICE,
            self::SANITARY_SWEEP_ESCALATED,
            self::SLA_IMMINENCE_2H_UNASSIGNED,
            self::SLA_IMMINENCE_3H_UNSTARTED,
            self::CHRONIC_BREAKDOWN_ALERT,
            self::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT => NotificationPriority::CRITICAL_SANITARY,
            self::TELEGRAM_INCIDENT_ASSIGNMENT => NotificationPriority::PRIORITY,
            default => NotificationPriority::STANDARD,
        };
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(sprintf('Plantilla de notificación no válida: «%s»', $value));
        }

        return $instance;
    }
}
