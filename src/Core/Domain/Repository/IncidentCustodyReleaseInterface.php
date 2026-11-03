<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;
use VendGuard\Core\Domain\Model\Incident;

/**
 * Capacidad de liberar una avería a la bandeja general cerrando su tramo de
 * custodia en el mismo acto (Protocolo Sanitario Art. II, Nivel 2).
 *
 * Existe separada de `IncidentRepositoryInterface` por la misma razón que
 * `IncidentAssignmentAuditRepositoryInterface`: los dobles de prueba que no
 * escriben en el motor no tienen por qué implementarla. La puerta que la usa
 * comprueba la capacidad y, si el repositorio no la ofrece, conserva el camino
 * anterior.
 */
interface IncidentCustodyReleaseInterface
{
    /**
     * Cierra el tramo activo con la causa indicada y deja la incidencia en
     * `REGISTERED` sin técnico, dentro de la misma transacción.
     */
    public function releaseCustodyToInbox(
        int $incidentId,
        IncidentUnassignmentReasonType $reasonType,
        string $reason,
        ?int $actorUserId = null,
        ?string $actorNameSnapshot = null
    ): ?Incident;
}
