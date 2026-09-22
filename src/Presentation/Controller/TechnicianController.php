<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianController
 * 
 * Controlador REST para la operativa de campo del Técnico de Ruta (RF-07, RF-08).
 * Proporciona acceso a la vista móvil "Mi Ruta", inicio de intervención y pausa por repuestos.
 * 
 * Respeta el Dogma Vanilla (PHP 8.2+ puro, PDO) y los principios de Clean Architecture.
 */
class TechnicianController
{
    private IncidentRepositoryInterface $incidentRepo;
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private UserRepositoryInterface $userRepo;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->machineRepo  = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->userRepo     = $userRepo ?? new PdoUserRepository();
    }

    /**
     * GET /api/technician/my-route
     * 
     * Devuelve la lista ordenada de averías asignadas a la ruta del técnico autenticado (RF-07).
     * Incluye datos completos de máquina (con planta/ala) y ubicación (con dirección y teléfono).
     */
    public function getMyRoute(Request $request): Response
    {
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }
        $techId = (int)$technicianId;

        // Recuperar incidencias asignadas en estados activos de ruta (ASSIGNED, IN_PROGRESS, PENDING_PARTS)
        $incidents = $this->incidentRepo->findAssignedToTechnician($techId, [
            'ASSIGNED',
            'IN_PROGRESS',
            'PENDING_PARTS',
        ]);

        $routeData = [];
        foreach ($incidents as $incident) {
            $machine = $this->machineRepo->findById($incident->getMachineId());
            $location = $this->locationRepo->findById($incident->getLocationId());

            $routeData[] = [
                'id'          => $incident->getId(),
                'ticket_code' => $incident->getTicketCode(),
                'urgency'     => $incident->getUrgency()->value,
                'status'      => $incident->getStatus()->value,
                'machine'     => [
                    'id'         => $machine?->getId(),
                    'code'       => $machine?->getCode() ?? $incident->getMachineCode(),
                    'model'      => $machine?->getModel() ?? $incident->getMachineModel(),
                    'floor_wing' => $machine?->getFloorWing(),
                ],
                'location'    => [
                    'id'            => $location?->getId(),
                    'name'          => $location?->getName() ?? $incident->getLocationName(),
                    'address'       => $location?->getAddress(),
                    'contact_phone' => $location?->getContactPhone(),
                ],
                'category'             => $incident->getCategory()->value,
                'description'          => $incident->getDescription(),
                'started_at'           => $incident->getStartedAt(),
                'pending_parts_reason' => $incident->getPendingPartsReason(),
                'created_at'           => $incident->getCreatedAt(),
            ];
        }

        return Response::json($routeData, 200);
    }

    /**
     * PATCH /api/technician/incidents/{id}/start
     * 
     * Inicia o reanuda la intervención técnica in situ frente a la máquina (RF-07 / EARS 7.1, 7.3).
     * Transiciona el estado a IN_PROGRESS y fija started_at.
     */
    public function startIntervention(Request $request): Response
    {
        // 1. Extraer y validar el ID de incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Extraer ID del técnico autenticado
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }
        $techId = (int)$technicianId;

        // 3. Verificar existencia de la incidencia
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 4. Verificar asignación a este técnico
        if ($incident->getAssignedTechnicianId() !== $techId) {
            return Response::error('FORBIDDEN', 'Esta incidencia no está asignada a tu ruta técnica.', 403);
        }

        // 5. Validar transición de estado
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::IN_PROGRESS)) {
            return Response::error(
                'INVALID_STATUS_FOR_START',
                "No se puede iniciar intervención en una incidencia en estado {$incident->getStatus()->value}.",
                422
            );
        }

        // 6. Ejecutar inicio en repositorio
        try {
            $started = $this->incidentRepo->startIntervention($incidentId, $techId);
        } catch (InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_START', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }

        // 7. Respuesta exitosa (contrato 5.2)
        return Response::json([
            'id'         => $started->getId(),
            'status'     => $started->getStatus()->value,
            'started_at' => $started->getStartedAt(),
        ], 200);
    }

    /**
     * PATCH /api/technician/incidents/{id}/pause
     * 
     * Suspende temporalmente los trabajos técnicos por falta de repuestos (RF-07 / EARS 7.2).
     * Exige obligatoriamente la descripción de la pieza solicitada y transiciona a PENDING_PARTS.
     */
    public function pauseIntervention(Request $request): Response
    {
        // 1. Extraer y validar el ID de incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Extraer ID del técnico autenticado
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }
        $techId = (int)$technicianId;

        // 3. Extraer y validar el motivo de pausa por repuesto (EARS 7.2)
        $body = $request->getParsedBody();
        $reason = isset($body['pending_parts_reason']) 
            ? trim((string)$body['pending_parts_reason']) 
            : (isset($body['parts_note']) ? trim((string)$body['parts_note']) : '');
        if ($reason === '') {
            return Response::error('MISSING_PENDING_PARTS_REASON', 'La descripción del repuesto requerido es obligatoria (pending_parts_reason obligatorio).', 422);
        }

        // 4. Verificar existencia de la incidencia
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 5. Verificar asignación a este técnico
        if ($incident->getAssignedTechnicianId() !== $techId) {
            return Response::error('FORBIDDEN', 'Esta incidencia no está asignada a tu ruta técnica.', 403);
        }

        // 6. Validar transición de estado
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::PENDING_PARTS)) {
            return Response::error(
                'INVALID_STATUS_FOR_PAUSE',
                "No se puede pausar por repuestos una incidencia en estado {$incident->getStatus()->value}.",
                422
            );
        }

        // 7. Ejecutar pausa en repositorio
        try {
            $paused = $this->incidentRepo->pauseIntervention($incidentId, $techId, $reason);
        } catch (InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_PAUSE', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }

        // 8. Respuesta exitosa (contrato 5.3)
        return Response::json([
            'id'     => $paused->getId(),
            'status' => $paused->getStatus()->value,
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-08 / EARS 8.1, 8.2, 8.3 — Resolución Obligatoriamente Justificada
    // ────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/technician/incidents/{id}/resolve
     * 
     * Resuelve y documenta formalmente la intervención técnica (RF-08).
     * Exige obligatoriamente:
     * - Diagnóstico real con al menos 20 caracteres (EARS 8.1).
     * - Acción correctiva con al menos 20 caracteres (EARS 8.1).
     * Si no se cumplen los requisitos mínimos, rechaza la operación y mantiene el estado EN_CURSO (EARS 8.2).
     * Si es válida, transiciona a RESUELTA y activa la ventana de garantía de 48 horas (EARS 8.3).
     */
    public function resolveIncident(Request $request): Response
    {
        // 1. Extraer y validar el ID de incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Extraer ID del técnico autenticado
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }
        $techId = (int)$technicianId;

        // 3. Extraer y validar cuerpo de la petición (EARS 8.1)
        $body = $request->getParsedBody();
        $diagnosis = isset($body['resolution_diagnosis']) 
            ? trim((string)$body['resolution_diagnosis']) 
            : (isset($body['diagnosis']) ? trim((string)$body['diagnosis']) : '');
        $action    = isset($body['resolution_action']) 
            ? trim((string)$body['resolution_action']) 
            : (isset($body['action_taken']) ? trim((string)$body['action_taken']) : '');

        // Validar textos con ResolutionValidator (mínimo 20 caracteres descriptivos en cada campo)
        $validationErrors = \VendGuard\Core\Service\ResolutionValidator::getValidationErrors($diagnosis, $action);
        if (!empty($validationErrors)) {
            return Response::error(
                'INVALID_RESOLUTION',
                'Datos de resolución insuficientes: ' . implode(' ', $validationErrors),
                422,
                ['errors' => $validationErrors]
            );
        }

        // 4. Verificar existencia de la incidencia
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 5. Verificar asignación a este técnico
        if ($incident->getAssignedTechnicianId() !== $techId) {
            return Response::error('FORBIDDEN', 'Esta incidencia no está asignada a tu ruta técnica.', 403);
        }

        // 6. Validar que el estado actual permita transicionar a RESOLVED (EARS 8.2: debe estar en IN_PROGRESS)
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::RESOLVED)) {
            return Response::error(
                'INVALID_STATUS_FOR_RESOLUTION',
                "No se puede resolver una incidencia en estado {$incident->getStatus()->value}. La intervención debe estar previamente en curso (IN_PROGRESS).",
                422
            );
        }

        // 7. Ejecutar resolución en el repositorio
        try {
            $resolved = $this->incidentRepo->resolve($incidentId, $techId, $diagnosis, $action);
        } catch (\VendGuard\Core\Domain\Exception\InvalidResolutionException $e) {
            return Response::error('INVALID_RESOLUTION', $e->getMessage(), 422, ['errors' => $e->getErrors()]);
        } catch (InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_RESOLUTION', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }

        // 8. Respuesta exitosa (contrato 5.4)
        return Response::json([
            'id'          => $resolved->getId(),
            'status'      => $resolved->getStatus()->value,
            'resolved_at' => $resolved->getResolvedAt(),
        ], 200);
    }
}

