<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;

/**
 * Contrato de la asignación que escribe su rastro de auditoría en la MISMA
 * sentencia que el cambio de negocio.
 *
 * Existe separado de `IncidentRepositoryInterface` a propósito: la asignación es
 * la única transición que necesita esta fusión para respetar el presupuesto de
 * latencia del agrupador (RNF-01), y los dobles de prueba que no escriben en el
 * motor real no tienen por qué implementarla. La puerta que la usa comprueba la
 * capacidad y, si el repositorio no la ofrece, conserva el camino anterior.
 */
interface IncidentAssignmentAuditRepositoryInterface
{
    /**
     * Asigna o reasigna el expediente y persiste el evento de auditoría en la
     * misma unidad atómica.
     */
    public function assignWithAudit(
        int|Incident $incidentId,
        int $technicianId,
        ?int $coordinatorId,
        ?string $urgencyOverride,
        ?string $urgencyReason,
        ?string $reassignmentReason,
        AuditEvent $auditEvent
    ): Incident;
}
