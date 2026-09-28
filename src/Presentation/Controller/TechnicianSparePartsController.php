<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianSparePartsController
 * 
 * Controlador REST para el catálogo de repuestos en movilidad del Técnico de Ruta (RF-REP-01 / RNF-REP-02).
 * Sirve el catálogo de piezas compatibles con una máquina en < 250 ms, con soporte para
 * la preservación de piezas inactivas en incidencias en curso (Caso Límite 4).
 * 
 * Cumple con el Dogma Vanilla y el Dualismo Lingüístico.
 */
class TechnicianSparePartsController
{
    private MachineRepositoryInterface $machineRepo;
    private SparePartRepositoryInterface $sparePartRepo;
    private IncidentRepositoryInterface $incidentRepo;
    private SparePartRequestRepositoryInterface $requestRepo;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?SparePartRepositoryInterface $sparePartRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?SparePartRequestRepositoryInterface $requestRepo = null
    ) {
        $this->machineRepo   = $machineRepo ?? new PdoMachineRepository();
        $this->sparePartRepo = $sparePartRepo ?? new PdoSparePartRepository();
        $this->incidentRepo  = $incidentRepo ?? new PdoIncidentRepository();
        $this->requestRepo   = $requestRepo ?? new PdoSparePartRequestRepository();
    }

    /**
     * GET /api/technician/spare-parts/catalog
     * 
     * Devuelve las piezas activas del catálogo compatibles con una máquina dada,
     * preparadas para carga rápida móvil (< 250 ms, RNF-REP-02).
     * 
     * Query Parameters:
     * - machine_id (int, obligatorio): ID de la máquina intervenida.
     * - incident_id (int, opcional): Si se proporciona y la incidencia está en PENDING_PARTS,
     *   se incluye en la respuesta la pieza que fue solicitada originalmente, aunque dicha pieza
     *   haya sido desactivada posteriormente (Caso Límite 4).
     */
    public function getCatalog(Request $request): Response
    {
        // 1. Validar parámetro obligatorio machine_id
        $rawMachineId = $request->getQuery('machine_id');
        if ($rawMachineId === null || !is_numeric($rawMachineId) || (int)$rawMachineId <= 0) {
            return Response::error(
                'INVALID_MACHINE_ID',
                'El parámetro machine_id es obligatorio y debe ser un número entero positivo.',
                400
            );
        }
        $machineId = (int)$rawMachineId;

        // 2. Verificar existencia de la máquina
        $machine = $this->machineRepo->findById($machineId);
        if ($machine === null) {
            return Response::error(
                'MACHINE_NOT_FOUND',
                "No se encontró ninguna máquina con ID {$machineId}.",
                404
            );
        }

        $machineModel = $machine->getModel() ?? '';

        // 3. Obtener repuestos activos compatibles con el modelo de la máquina
        $parts = $this->sparePartRepo->findCompatibleWithModel($machineModel, true);

        // 4. Caso Límite 4: Si se incluye incident_id y la avería está en PENDING_PARTS,
        // incluir repuestos solicitados previamente aunque hayan sido desactivados posteriormente
        $rawIncidentId = $request->getQuery('incident_id');
        if ($rawIncidentId !== null && trim((string)$rawIncidentId) !== '') {
            if (!is_numeric($rawIncidentId) || (int)$rawIncidentId <= 0) {
                return Response::error(
                    'INVALID_INCIDENT_ID',
                    'El parámetro incident_id debe ser un número entero positivo.',
                    400
                );
            }
            $incidentId = (int)$rawIncidentId;
            $incident = $this->incidentRepo->findById($incidentId);

            if ($incident === null) {
                return Response::error(
                    'INCIDENT_NOT_FOUND',
                    "No se encontró ninguna incidencia con ID {$incidentId}.",
                    404
                );
            }

            if ($incident->getStatus()->value === 'PENDING_PARTS') {
                $requests = $this->requestRepo->findByIncidentId($incidentId);
                $existingPartIds = array_map(fn(SparePart $p) => $p->getId(), $parts);

                foreach ($requests as $req) {
                    $partId = $req->getSparePartId();
                    if ($partId !== null && !in_array($partId, $existingPartIds, true)) {
                        $requestedPart = $this->sparePartRepo->findById($partId);
                        if ($requestedPart !== null) {
                            $parts[] = $requestedPart;
                            $existingPartIds[] = $partId;
                        }
                    }
                }
            }
        }

        // 5. Mapear respuesta según contrato 5.2
        $compatiblePartsData = array_map(function (SparePart $p): array {
            return [
                'id'             => $p->getId(),
                'part_code'      => $p->getPartCode(),
                'name'           => $p->getName(),
                'category'       => $p->getCategory()->value,
                'manufacturer'   => $p->getManufacturer(),
                'reference_cost' => $p->getReferenceCost(),
                'is_active'      => $p->isActive(),
            ];
        }, $parts);

        return Response::json([
            'machine' => [
                'id'    => $machine->getId(),
                'code'  => $machine->getCode(),
                'model' => $machine->getModel(),
            ],
            'compatible_parts' => $compatiblePartsData,
        ], 200);
    }
}
