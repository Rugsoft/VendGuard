<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\SparePartRequest;

/**
 * SparePartRequestBatchRepositoryInterface
 *
 * Lectura por lote de solicitudes `PENDING` para varias averías en una sola
 * consulta. Es la capacidad que evita el N+1 de `van_stock_readiness` cuando la
 * bandeja de triaje o «Mi Ruta» componen muchas tarjetas a la vez
 * (Módulo 23 · RNF-01).
 *
 * La capacidad vive en un contrato propio, igual que las lecturas por lote de
 * máquinas, sedes y ajustes preventivos: los consumidores de una solicitud
 * individual no se ven obligados a implementarla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface SparePartRequestBatchRepositoryInterface
{
    /**
     * Solicitudes `PENDING` de las averías indicadas, agrupadas por avería.
     *
     * Las averías sin solicitudes no aparecen en el mapa. Las solicitudes fuera
     * de catálogo se incluyen con `spare_part_id = null` para que el calculador
     * pueda publicar `HAS_OUT_OF_CATALOG`.
     *
     * @param list<int> $incidentIds Identificadores de avería, sin duplicados.
     * @return array<int, list<SparePartRequest>> Índice por `incident_id`.
     */
    public function findPendingByIncidentIds(array $incidentIds): array;
}
