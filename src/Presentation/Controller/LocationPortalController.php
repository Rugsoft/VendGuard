<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Service\UrgencyCalculator;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
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
    private LocalFileUploader $fileUploader;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?LocalFileUploader $fileUploader = null
    ) {
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
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

    /**
     * POST /api/incidents
     * Crea un nuevo aviso de avería para una máquina de la sede (RF-02, RF-03, RNF-02, RNF-05).
     * 
     * Soporta multipart/form-data y application/json:
     * - Calcula automáticamente la urgencia según criticidad y tipo de máquina (EARS 3.2-3.7).
     * - Rechaza duplicados con HTTP 409 Conflict si la máquina ya tiene aviso activo o está en garantía (EARS 2.1, 2.2).
     * - Valida y almacena fotografías adjuntas (<= 5 MB, JPEG/PNG/WebP).
     * - Devuelve HTTP 201 Created con el expediente generado.
     */
    public function createIncident(Request $request): Response
    {
        // 1. Identificar la sede autenticada
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $locationId = $request->getAttribute('location_id');
            if ($locationId !== null) {
                $location = $this->locationRepo->findById((int)$locationId);
            }
        }
        if ($location === null) {
            $siteCodeHeader = $request->getHeader('X-Site-Code') ?? $request->getAttribute('site_code');
            if ($siteCodeHeader !== null && trim((string)$siteCodeHeader) !== '') {
                $location = $this->locationRepo->findBySiteCode(trim((string)$siteCodeHeader), true);
            }
        }

        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
                401
            );
        }

        // 2. Validar identificador de máquina
        $machineIdRaw = $request->getBodyParam('machine_id');
        if ($machineIdRaw === null || trim((string)$machineIdRaw) === '') {
            return Response::error(
                'MISSING_MACHINE_ID',
                'El identificador de la máquina (machine_id) es obligatorio.',
                400
            );
        }

        if (!is_numeric($machineIdRaw) || (int)$machineIdRaw <= 0) {
            return Response::error(
                'INVALID_MACHINE_ID',
                'El identificador de la máquina (machine_id) debe ser un número entero positivo.',
                400
            );
        }

        $machineId = (int)$machineIdRaw;
        $machine = $this->machineRepo->findById($machineId);

        if ($machine === null) {
            return Response::error(
                'MACHINE_NOT_FOUND',
                "No se encontró ninguna máquina con ID {$machineId}.",
                404
            );
        }

        // Validar aislamiento de sede
        if ($machine->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'La máquina indicada no pertenece a la sede autenticada.',
                403
            );
        }

        // Validar que la máquina esté activa
        if (!$machine->isActive()) {
            return Response::error(
                'MACHINE_INACTIVE',
                'La máquina seleccionada no se encuentra activa en el catálogo.',
                422
            );
        }

        // 3. Validar categoría de avería (EARS 3.1 - 3.7)
        $categoryRaw = $request->getBodyParam('category');
        if ($categoryRaw === null || trim((string)$categoryRaw) === '') {
            return Response::error(
                'MISSING_CATEGORY',
                'La categoría de la avería (category) es obligatoria.',
                400
            );
        }

        $categoryNormalized = strtoupper(trim((string)$categoryRaw));
        if (!IncidentCategory::isValid($categoryNormalized)) {
            return Response::error(
                'INVALID_CATEGORY',
                "Categoría de avería no válida: '{$categoryRaw}'. Valores permitidos: " . implode(', ', IncidentCategory::values()),
                400
            );
        }

        $category = IncidentCategory::fromString($categoryNormalized);

        // 4. Validar descripción
        $description = trim((string)($request->getBodyParam('description') ?? ''));
        if ($description === '') {
            return Response::error(
                'MISSING_DESCRIPTION',
                'La descripción de la avería es obligatoria.',
                400
            );
        }

        if (mb_strlen($description) < 5) {
            return Response::error(
                'DESCRIPTION_TOO_SHORT',
                'La descripción de la avería debe contener al menos 5 caracteres.',
                422
            );
        }

        // 5. Metadatos opcionales (contacto y dinero retenido EARS 3.8)
        $reporterName = $request->getBodyParam('reporter_name');
        $reporterName = $reporterName !== null && trim((string)$reporterName) !== '' ? trim((string)$reporterName) : null;

        $reporterPhone = $request->getBodyParam('reporter_phone');
        $reporterPhone = $reporterPhone !== null && trim((string)$reporterPhone) !== '' ? trim((string)$reporterPhone) : null;

        $retainedMoney = null;
        $retainedMoneyRaw = $request->getBodyParam('retained_money_amount');
        if ($retainedMoneyRaw !== null && trim((string)$retainedMoneyRaw) !== '') {
            if (!is_numeric($retainedMoneyRaw)) {
                return Response::error(
                    'INVALID_MONEY_AMOUNT',
                    'El importe retenido debe ser un valor numérico.',
                    422
                );
            }
            $retainedMoneyVal = (float)$retainedMoneyRaw;
            if ($retainedMoneyVal < 0) {
                return Response::error(
                    'INVALID_MONEY_AMOUNT',
                    'El importe de dinero retenido no puede ser negativo.',
                    422
                );
            }
            $retainedMoney = $retainedMoneyVal;
        }

        // 6. Procesar archivo adjunto si se envía (RNF-05, EARS 3.9)
        $photoPath = null;
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        if ($photoFile !== null && isset($photoFile['error']) && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['tmp_name'])) {
            try {
                $photoPath = $this->fileUploader->upload($photoFile);
            } catch (InvalidUploadException $e) {
                // EARS 3.9: Preservar íntegros los datos de texto introducidos en el formulario
                return Response::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    $e->getHttpStatusCode(),
                    [
                        'form_data' => [
                            'machine_id' => $machineId,
                            'category' => $categoryNormalized,
                            'description' => $description,
                            'reporter_name' => $reporterName,
                            'reporter_phone' => $reporterPhone,
                            'retained_money_amount' => $retainedMoney,
                        ],
                    ]
                );
            }
        } elseif ($request->getBodyParam('photo_path') !== null && trim((string)$request->getBodyParam('photo_path')) !== '') {
            $photoPath = trim((string)$request->getBodyParam('photo_path'));
        }

        // 7. Cálculo automático de urgencia (Constitución Art. II, RF-03 / EARS 3.2-3.7)
        $urgency = UrgencyCalculator::calculate($machine->getMachineType(), $category);

        // 8. Generar código de ticket único (INC-YYYY-XXXX)
        $ticketCode = TicketCode::generate()->value();

        // 9. Construir la entidad de dominio Incident
        $incident = new Incident(
            id: null,
            ticketCode: $ticketCode,
            machineId: $machine->getId(),
            locationId: $location->getId(),
            category: $category,
            description: $description,
            urgency: $urgency,
            status: IncidentStatus::REGISTERED,
            assignedTechnicianId: null,
            reporterName: $reporterName,
            reporterPhone: $reporterPhone,
            retainedMoneyAmount: $retainedMoney,
            photoPath: $photoPath
        );

        // 10. Persistir atómicamente con prevención de duplicados (RF-02)
        try {
            $created = $this->incidentRepo->create($incident, null, 'Aviso registrado desde el portal de sede');
        } catch (DuplicateIncidentException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'ticket_status' => $e->getTicketStatus(),
                ]
            );
        }

        // 11. Devolver respuesta 201 Created con el payload del recurso generado
        return Response::json(
            $created->toArray(),
            201,
            'Incidencia registrada con éxito'
        );
    }
}
