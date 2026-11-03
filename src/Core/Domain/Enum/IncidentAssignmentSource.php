<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

/**
 * IncidentAssignmentSource
 *
 * Canal por el que un tramo de custodia entra en el expediente (Módulo 26,
 * `incident_assignments.assignment_source`). Es un catálogo cerrado de nueve
 * valores que se corresponde uno a uno con el tipo `ENUM` del motor: un valor
 * que no esté aquí no puede escribirse en la base.
 *
 * Los canales no son etiquetas cosméticas: distinguen la vía operativa que abrió
 * el tramo —Coordinación, cobertura de ausencia, intervención oportunista en
 * sede, relevo de turno o guardia sanitaria— y son la materia prima del resumen
 * de la cadena de custodia (RF-03, QA 2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum IncidentAssignmentSource: string
{
    case COORDINATOR_ASSIGN = 'COORDINATOR_ASSIGN';
    case COORDINATOR_REASSIGN = 'COORDINATOR_REASSIGN';
    case ABSENCE_COVERAGE_REASSIGN = 'ABSENCE_COVERAGE_REASSIGN';
    case OPPORTUNISTIC_NEW = 'OPPORTUNISTIC_NEW';
    case OPPORTUNISTIC_ASSUMED = 'OPPORTUNISTIC_ASSUMED';
    case OPPORTUNISTIC_TAKEOVER = 'OPPORTUNISTIC_TAKEOVER';
    case OPPORTUNISTIC_WARRANTY_REOPEN = 'OPPORTUNISTIC_WARRANTY_REOPEN';
    case SHIFT_HANDOVER = 'SHIFT_HANDOVER';
    case SANITARY_FAILSAFE_GUARD = 'SANITARY_FAILSAFE_GUARD';

    /**
     * Etiqueta legible que viaja como `assignment_source_label` en la cadena de
     * custodia. Se declara aquí y no en el servicio para que un canal nuevo no
     * pueda aparecer en el motor sin su nombre en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::COORDINATOR_ASSIGN => 'Asignación de Coordinación',
            self::COORDINATOR_REASSIGN => 'Reasignación de Coordinación',
            self::ABSENCE_COVERAGE_REASSIGN => 'Cobertura de Ausencia',
            self::OPPORTUNISTIC_NEW => 'Alta Oportunista In Situ',
            self::OPPORTUNISTIC_ASSUMED => 'Asunción Oportunista In Situ',
            self::OPPORTUNISTIC_TAKEOVER => 'Traspaso Oportunista In Situ',
            self::OPPORTUNISTIC_WARRANTY_REOPEN => 'Reapertura en Garantía In Situ',
            self::SHIFT_HANDOVER => 'Relevo de Fin de Turno',
            self::SANITARY_FAILSAFE_GUARD => 'Guardia Sanitaria 24/7 (Art. II)',
        };
    }
}
