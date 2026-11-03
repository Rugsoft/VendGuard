<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use VendGuard\Core\Domain\Enum\IncidentAssignmentSource;
use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;

/**
 * CustodyChannelResolver
 *
 * Regla de dominio que decide si una reasignación de Coordinación es una
 * cobertura de ausencia (`EARS 02.1`, `EARS 02.2`).
 *
 * La cobertura no es una etiqueta que el Coordinador declare: es un hecho
 * verificable del expediente. Si el técnico que pierde la custodia tiene una
 * ausencia vigente en ese instante, el tramo se cierra y se abre con el canal
 * de ausencia; en cualquier otro caso la reasignación es ordinaria y el
 * repositorio aplica sus valores por defecto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 */
final class CustodyChannelResolver
{
    /**
     * @return array{0: ?IncidentAssignmentSource, 1: ?IncidentUnassignmentReasonType}
     *         Canal de apertura y causa de cierre, o `[null, null]` cuando la
     *         reasignación es ordinaria y decide el repositorio.
     */
    public static function resolve(bool $isReassignment, bool $outgoingHasActiveAbsence): array
    {
        if (!$isReassignment || !$outgoingHasActiveAbsence) {
            return [null, null];
        }

        return [
            IncidentAssignmentSource::ABSENCE_COVERAGE_REASSIGN,
            IncidentUnassignmentReasonType::ABSENCE_COVERAGE_REASSIGN,
        ];
    }
}
