<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

/**
 * IncidentUnassignmentReasonType
 *
 * Causa tipificada por la que un tramo de custodia se cierra (Módulo 26,
 * `incident_assignments.unassignment_reason_type`). Es un catálogo cerrado de
 * diez valores alineado con el tipo `ENUM` del motor.
 *
 * La distinción que importa al negocio no es descriptiva sino contable: un cierre
 * por transferencia o liberación acredita que la avería pasó de manos sin
 * resolverse, y es lo único que suma al contador de reasignaciones (R2, QA 2).
 * `RESOLVED`, `CANCELLED`, `CHRONIC_REPLACEMENT_CLOSED` y `WARRANTY_REOPENED`
 * cierran el tramo, pero no son traspasos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum IncidentUnassignmentReasonType: string
{
    case REASSIGNED_BY_COORDINATOR = 'REASSIGNED_BY_COORDINATOR';
    case ABSENCE_COVERAGE_REASSIGN = 'ABSENCE_COVERAGE_REASSIGN';
    case TAKEN_OVER_IN_SITU = 'TAKEN_OVER_IN_SITU';
    case SHIFT_HANDOVER = 'SHIFT_HANDOVER';
    case SANITARY_FAILSAFE_GUARD = 'SANITARY_FAILSAFE_GUARD';
    case SANITARY_FAILSAFE_UNASSIGNED = 'SANITARY_FAILSAFE_UNASSIGNED';
    case RESOLVED = 'RESOLVED';
    case CHRONIC_REPLACEMENT_CLOSED = 'CHRONIC_REPLACEMENT_CLOSED';
    case WARRANTY_REOPENED = 'WARRANTY_REOPENED';
    case CANCELLED = 'CANCELLED';

    /** Etiqueta legible de la causa de cierre (`unassignment_reason_label`). */
    public function label(): string
    {
        return match ($this) {
            self::REASSIGNED_BY_COORDINATOR => 'Reasignada por Coordinación',
            self::ABSENCE_COVERAGE_REASSIGN => 'Reasignada por Cobertura de Ausencia',
            self::TAKEN_OVER_IN_SITU => 'Traspaso Oportunista In Situ',
            self::SHIFT_HANDOVER => 'Relevo de Fin de Turno',
            self::SANITARY_FAILSAFE_GUARD => 'Relevo Automático a Guardia Sanitaria (Art. II)',
            self::SANITARY_FAILSAFE_UNASSIGNED => 'Liberación a Bandeja sin Guardia (Art. II)',
            self::RESOLVED => 'Resuelta',
            self::CHRONIC_REPLACEMENT_CLOSED => 'Cerrada por Retirada Crónica',
            self::WARRANTY_REOPENED => 'Reabierta en Garantía',
            self::CANCELLED => 'Incidencia Cancelada / Descartada',
        };
    }

    /**
     * ¿El cierre es un traspaso o una liberación **sin resolver** la avería?
     *
     * Es la verdad única del contador `reassignments_count` (R2, QA 2): sólo estos
     * seis cierres acreditan que la custodia cambió de manos. Resolver, cancelar,
     * retirar por reincidencia crónica o sellar una reapertura en garantía cierran
     * el tramo sin ser una reasignación.
     */
    public function isUnresolvedTransferOrRelease(): bool
    {
        return match ($this) {
            self::REASSIGNED_BY_COORDINATOR,
            self::ABSENCE_COVERAGE_REASSIGN,
            self::TAKEN_OVER_IN_SITU,
            self::SHIFT_HANDOVER,
            self::SANITARY_FAILSAFE_GUARD,
            self::SANITARY_FAILSAFE_UNASSIGNED => true,
            self::RESOLVED,
            self::CHRONIC_REPLACEMENT_CLOSED,
            self::WARRANTY_REOPENED,
            self::CANCELLED => false,
        };
    }
}
