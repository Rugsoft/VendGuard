<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

/**
 * Contrato de la transición a «Pendiente de Información» que escribe el cambio y
 * su rastro inmutable en la misma sentencia.
 *
 * Es un puerto estrecho, no una ampliación de `IncidentRepositoryInterface`: los
 * dobles de prueba que no hablan con el motor real no necesitan implementarlo, y
 * el servicio conserva el camino de dos escrituras cuando no está disponible.
 */
interface IncidentPauseTransitionRepositoryInterface
{
    /**
     * Persiste la pausa y su evento de historial de forma atómica.
     *
     * @return bool `true` si la fila del expediente se actualizó.
     */
    public function updateAndRecordPauseEvent(
        Incident $incident,
        int $userId,
        IncidentStatus $fromStatus,
        IncidentPauseReasonCategory $category,
        string $reasonText,
        DateTimeImmutable $pausedAt
    ): bool;
}
