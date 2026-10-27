<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * SparePartRequestPendingSummaryInterface
 *
 * Contrato segregado para la lectura agregada y directa de solicitudes PENDING
 * por lote de averías sin hidratar modelos de dominio (RNF-01, Módulo 23).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 */
interface SparePartRequestPendingSummaryInterface
{
    /**
     * Agregado de solicitudes PENDING indexado por avería:
     * [incident_id => ['catalog' => [spare_part_id => qty], 'out_of_catalog' => n]]
     *
     * @param list<int> $incidentIds
     * @return array<int, array{catalog: array<int, int>, out_of_catalog: int}>
     */
    public function findPendingSummaryByIncidentIds(array $incidentIds): array;
}
