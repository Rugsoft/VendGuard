<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * LocationPortalController
 * 
 * Controlador REST para el Portal de Sede / Ubicación (Responsables de Centro).
 * Gestiona la consulta del parque de máquinas, creación de incidencias,
 * adjuntos y reaperturas (RF-01, RF-02, RF-03).
 */
class LocationPortalController
{
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private IncidentRepositoryInterface $incidentRepo;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null
    ) {
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
    }

    /**
     * GET /api/locations/{site_code}/machines
     * Devuelve el catálogo de máquinas activas de la sede indicando si cuentan con
     * avisos de avería activos o en periodo de garantía (RF-01, RF-02).
     */
    public function getMachines(Request $request): Response
    {
        $siteCodeParam = $request->getRouteParam('site_code') ?? $request->getRouteParam('code');

        if ($siteCodeParam === null || trim($siteCodeParam) === '') {
            return Response::error(
                'MISSING_SITE_CODE',
                'El código de sede es obligatorio en la URL.',
                400
            );
        }

        $requestedCode = strtoupper(trim($siteCodeParam));

        // Validación de aislamiento de sedes (si el middleware inyectó la sede autenticada)
        $authenticatedSiteCode = $request->getAttribute('site_code');
        if ($authenticatedSiteCode !== null && strcasecmp((string)$authenticatedSiteCode, $requestedCode) !== 0) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para consultar el parque de máquinas de otra sede.',
                403
            );
        }

        // Obtener la entidad Location
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $location = $this->locationRepo->findBySiteCode($requestedCode, true);
        }

        if ($location === null) {
            return Response::error(
                'LOCATION_NOT_FOUND',
                "No se encontró ninguna sede activa con el código '{$requestedCode}'.",
                404
            );
        }

        // Consultar máquinas con el estado activo/garantía de incidencia
        $machines = $this->machineRepo->findActiveByLocationId($location->getId());

        $payload = array_map(function (Machine $machine): array {
            return [
                'id' => $machine->getId(),
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'machine_type' => $machine->getMachineType()->value,
                'floor_wing' => $machine->getFloorWing(),
                'notes' => $machine->getNotes(),
                'active_incident' => $machine->getActiveIncident(),
            ];
        }, $machines);

        return Response::json($payload, 200);
    }
}
