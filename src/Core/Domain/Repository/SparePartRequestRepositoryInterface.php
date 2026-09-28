<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\SparePartRequest;

/**
 * SparePartRequestRepositoryInterface
 * 
 * Contrato de persistencia para las solicitudes estructuradas de repuesto
 * emitidas durante las pausas técnicas de incidencias correctivas (RF-REP-03 / RF-REP-04).
 */
interface SparePartRequestRepositoryInterface
{
    /**
     * Registra una nueva solicitud de repuesto en base de datos.
     *
     * @param SparePartRequest $request
     * @return SparePartRequest
     */
    public function createRequest(SparePartRequest $request): SparePartRequest;

    /**
     * Busca una solicitud por su identificador primario único.
     *
     * @param int $id
     * @return SparePartRequest|null
     */
    public function findById(int $id): ?SparePartRequest;

    /**
     * Obtiene el listado de solicitudes de repuesto asociadas a una incidencia.
     *
     * @param int $incidentId
     * @return SparePartRequest[]
     */
    public function findByIncidentId(int $incidentId): array;

    /**
     * Marca atómicamente como ATTENDED todas las solicitudes PENDING de una incidencia.
     * Se invoca al resolver la incidencia instalando componentes (RF-REP-06).
     *
     * @param int $incidentId
     * @return int Número de solicitudes actualizadas
     */
    public function markAttendedByIncident(int $incidentId): int;

    /**
     * Marca atómicamente como CANCELLED las solicitudes PENDING de una incidencia.
     * Se invoca cuando una avería es cancelada o descartada (Caso Límite 3).
     *
     * @param int $incidentId
     * @return int Número de solicitudes actualizadas
     */
    public function markCancelledByIncident(int $incidentId): int;

    /**
     * Obtiene las solicitudes de piezas fuera de catálogo pendientes de revisión para el coordinador.
     * Enriquecido con datos de incidencia, máquina, sede y técnico solicitante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingOutOfCatalogReviews(): array;
}
