<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Exception\ChronicIncidentException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
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

    /**
     * POST /api/incidents/{ticket_code}/comments
     * Añade un nuevo comentario o fotografía adicional a la bitácora de un ticket activo (RF-02 / EARS 2.3).
     * 
     * Garantiza la preservación íntegra de la fotografía original del aviso sin sobreescribirla (Edge Case 6).
     */
    public function addComment(Request $request): Response
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

        // 2. Obtener y validar el código de ticket de la ruta
        $ticketCodeParam = $request->getRouteParam('ticket_code') ?? $request->getRouteParam('code');
        if ($ticketCodeParam === null || trim($ticketCodeParam) === '') {
            return Response::error(
                'MISSING_TICKET_CODE',
                'El código de ticket es obligatorio en la URL.',
                400
            );
        }

        $ticketCode = strtoupper(trim($ticketCodeParam));
        $incident = $this->incidentRepo->findByTicketCode($ticketCode);

        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el código '{$ticketCode}'.",
                404
            );
        }

        // 3. Validar segregación de sede (Artículo V Constitución / RF-01)
        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para interactuar con incidencias de otra sede.',
                403
            );
        }

        // 4. Validar que la incidencia no esté cerrada ni cancelada
        if ($incident->isClosed() || $incident->isCancelled()) {
            return Response::error(
                'INCIDENT_NOT_ACTIVE',
                'No se pueden añadir comentarios a una incidencia que ya ha sido cerrada o cancelada.',
                422
            );
        }

        // 5. Validar texto del comentario
        $commentText = trim((string)($request->getBodyParam('comment_text') ?? $request->getBodyParam('text') ?? $request->getBodyParam('description') ?? ''));
        if ($commentText === '') {
            return Response::error(
                'MISSING_COMMENT_TEXT',
                'El texto del comentario es obligatorio.',
                400
            );
        }

        if (mb_strlen($commentText) < 3) {
            return Response::error(
                'COMMENT_TOO_SHORT',
                'El comentario debe contener al menos 3 caracteres descriptivos.',
                422
            );
        }

        // 6. Autor del comentario
        $authorName = trim((string)($request->getBodyParam('author_name') ?? ''));
        if ($authorName === '') {
            $authorName = $location->getContactName() ?? 'Responsable de Sede';
        }

        // 7. Procesar fotografía adjunta adicional si existe (EARS 2.3 & RNF-05)
        $photoPath = null;
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        if ($photoFile !== null && isset($photoFile['error']) && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['tmp_name'])) {
            try {
                $photoPath = $this->fileUploader->upload($photoFile);
            } catch (InvalidUploadException $e) {
                return Response::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    $e->getHttpStatusCode(),
                    [
                        'form_data' => [
                            'ticket_code' => $ticketCode,
                            'author_name' => $authorName,
                            'comment_text' => $commentText,
                        ],
                    ]
                );
            }
        } elseif ($request->getBodyParam('photo_path') !== null && trim((string)$request->getBodyParam('photo_path')) !== '') {
            $photoPath = trim((string)$request->getBodyParam('photo_path'));
        }

        // 8. Crear y persistir la entrada en incident_comments
        // Nota: La foto original en $incident->getPhotoPath() jamás se modifica ni sobreescribe (Edge Case 6)
        $comment = new IncidentComment(
            id: null,
            incidentId: (int)$incident->getId(),
            authorType: 'REPORTER',
            userId: null,
            authorName: $authorName,
            commentText: $commentText,
            photoPath: $photoPath,
            isInternal: false,
            createdAt: null,
            ticketCode: $incident->getTicketCode()
        );

        $createdComment = $this->incidentRepo->addComment($comment);

        // 9. Devolver respuesta 201 Created con el comentario anexado
        return Response::json(
            $createdComment->toArray(),
            201,
            'Comentario añadido correctamente a la bitácora de la incidencia'
        );
    }

    /**
     * GET /api/incidents/{ticket_code}/comments
     * Devuelve la bitácora de comentarios públicos de una incidencia (RF-02).
     */
    public function getComments(Request $request): Response
    {
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
            return Response::error('UNAUTHORIZED', 'Acceso no autorizado.', 401);
        }

        $ticketCodeParam = $request->getRouteParam('ticket_code') ?? $request->getRouteParam('code');
        if ($ticketCodeParam === null || trim($ticketCodeParam) === '') {
            return Response::error('MISSING_TICKET_CODE', 'El código de ticket es obligatorio en la URL.', 400);
        }

        $ticketCode = strtoupper(trim($ticketCodeParam));
        $incident = $this->incidentRepo->findByTicketCode($ticketCode);

        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró la incidencia '{$ticketCode}'.", 404);
        }

        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error('SITE_MISMATCH', 'No tiene autorización para consultar incidencias de otra sede.', 403);
        }

        // Responsable de ubicación: nunca expone comentarios marcados como internos (RNF-04)
        $comments = $this->incidentRepo->getComments((int)$incident->getId(), false);

        return Response::json(array_map(fn(IncidentComment $c) => $c->toArray(), $comments), 200);
    }

    /**
     * POST /api/incidents/{ticket_code}/reopen
     * Reabre una incidencia en estado RESUELTA dentro de las 48h de garantía (RF-09 / EARS 9.1, 9.2, 9.3).
     * 
     * - Desasigna automáticamente al técnico previo (assigned_technician_id = NULL) (EARS 9.1).
     * - Reinicia el reloj de garantía a 0 (resolved_at = NULL).
     * - Rechaza si han transcurrido > 48 horas con HTTP 422 REOPEN_WINDOW_EXPIRED (EARS 9.2).
     * - Bloquea con "Avería Crónica" (HTTP 422 CHRONIC_INCIDENT_LIMIT) a la 3ª reincidencia (EARS 9.3).
     * - Devuelve HTTP 200 OK con el ticket reabierto.
     */
    public function reopenIncident(Request $request): Response
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

        // 2. Obtener y validar el código de ticket
        $ticketCodeParam = $request->getRouteParam('ticket_code') ?? $request->getRouteParam('code');
        if ($ticketCodeParam === null || trim($ticketCodeParam) === '') {
            return Response::error(
                'MISSING_TICKET_CODE',
                'El código de ticket es obligatorio en la URL.',
                400
            );
        }

        $ticketCode = strtoupper(trim($ticketCodeParam));
        $incident = $this->incidentRepo->findByTicketCode($ticketCode);

        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el código '{$ticketCode}'.",
                404
            );
        }

        // 3. Validar segregación de sede (Artículo V Constitución / RF-01)
        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para reabrir incidencias de otra sede.',
                403
            );
        }

        // 4. Validar motivo descriptivo de la reapertura
        $reason = trim((string)($request->getBodyParam('reopen_reason') ?? $request->getBodyParam('reason') ?? $request->getBodyParam('description') ?? ''));
        if ($reason === '') {
            return Response::error(
                'MISSING_REOPEN_REASON',
                'El motivo descriptivo de la reapertura es obligatorio.',
                400
            );
        }

        if (mb_strlen($reason) < 5) {
            return Response::error(
                'REOPEN_REASON_TOO_SHORT',
                'El motivo de la reapertura debe contener al menos 5 caracteres descriptivos.',
                422
            );
        }

        // 5. Ejecutar la reapertura en el repositorio aplicando las reglas de negocio
        try {
            $reopenedIncident = $this->incidentRepo->reopen((int)$incident->getId(), $reason);
        } catch (InvalidTransitionException $e) {
            return Response::error(
                'INVALID_TRANSITION',
                $e->getMessage(),
                422,
                [
                    'current_status' => $e->getFromStatus()?->value,
                    'required_status' => IncidentStatus::RESOLVED->value,
                ]
            );
        } catch (WarrantyExpiredException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode()
            );
        } catch (ChronicIncidentException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'reopen_count' => $e->getReopenCount(),
                    'tag' => 'Avería Crónica',
                ]
            );
        }

        // 6. Respuesta exitosa HTTP 200 OK con payload según contrato API
        return Response::json(
            [
                'ticket_code' => $reopenedIncident->getTicketCode(),
                'status' => 'REABIERTA',
                'status_canonical' => $reopenedIncident->getStatus()->value,
                'assigned_technician_id' => $reopenedIncident->getAssignedTechnicianId(),
                'reopened_at' => $reopenedIncident->getReopenedAt(),
                'reopen_reason' => $reopenedIncident->getReopenReason(),
                'incident' => $reopenedIncident->toArray(),
            ],
            200,
            'Incidencia reabierta con éxito y enviada a triaje de coordinación.'
        );
    }
}
