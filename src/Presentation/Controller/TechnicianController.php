<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Exception\IncompatibleSparePartException;
use VendGuard\Core\Domain\Exception\InvalidOutOfCatalogJustificationException;
use VendGuard\Core\Domain\Exception\InvalidPartQuantityException;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Service\ResolutionValidator;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianController
 * 
 * Controlador REST para la operativa de campo del Técnico de Ruta (RF-07, RF-08, RF-REP-03 a RF-REP-06).
 * Proporciona acceso a la vista móvil "Mi Ruta", inicio de intervención, pausa estructurada por repuestos
 * y resolución obligatoriamente justificada con registro inmutable de componentes y congelación de costes.
 * 
 * Respeta el Dogma Vanilla (PHP 8.2+ puro, PDO) y los principios de Clean Architecture.
 */
class TechnicianController
{
    private IncidentRepositoryInterface $incidentRepo;
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private UserRepositoryInterface $userRepo;
    private SparePartTraceabilityService $traceabilityService;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?SparePartTraceabilityService $traceabilityService = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->machineRepo  = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->userRepo     = $userRepo ?? new PdoUserRepository();
        $this->traceabilityService = $traceabilityService ?? new SparePartTraceabilityService(
            requestRepo: new PdoSparePartRequestRepository(),
            replacedPartRepo: new PdoIncidentReplacedPartRepository(),
            sparePartRepo: new PdoSparePartRepository(),
            incidentRepo: $this->incidentRepo,
            machineRepo: $this->machineRepo
        );
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
     * Suspende temporalmente los trabajos técnicos por falta de repuestos (RF-07, RF-REP-03, RF-REP-04).
     * Procesa solicitudes estructuradas en spare_part_requests o texto libre legacy.
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

        // 3. Extraer cuerpo de la petición
        $body = $request->getParsedBody();

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

        // 7. Determinar si es solicitud estructurada (RF-REP-03 / RF-REP-04) o texto libre legacy
        $isStructured = array_key_exists('requested_parts', $body) || array_key_exists('is_out_of_catalog', $body);

        if ($isStructured) {
            $actor = $this->extractActor($request);
            try {
                $result = $this->traceabilityService->pauseIncidentWithParts($incidentId, $techId, $body, $actor);
                return Response::json($result, 200, 'Intervención pausada por repuestos correctamente.');
            } catch (IncompatibleSparePartException $e) {
                return Response::error('INCOMPATIBLE_SPARE_PART', $e->getMessage(), 422);
            } catch (InvalidOutOfCatalogJustificationException $e) {
                return Response::error('INVALID_OUT_OF_CATALOG_JUSTIFICATION', $e->getMessage(), 422);
            } catch (InvalidPartQuantityException $e) {
                return Response::error('INVALID_PART_QUANTITY', $e->getMessage(), 422);
            } catch (SparePartNotFoundException $e) {
                return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
            } catch (InvalidTransitionException $e) {
                return Response::error('INVALID_STATUS_FOR_PAUSE', $e->getMessage(), 422);
            } catch (\InvalidArgumentException $e) {
                return Response::error('MISSING_PARTS_REQUEST', $e->getMessage(), 422);
            } catch (\DomainException $e) {
                return Response::error('OPERATION_FAILED', $e->getMessage(), 400);
            }
        }

        // Flujo legacy (T-28): motivo en texto libre
        $reason = isset($body['pending_parts_reason']) 
            ? trim((string)$body['pending_parts_reason']) 
            : (isset($body['parts_note']) ? trim((string)$body['parts_note']) : '');
        if ($reason === '') {
            return Response::error('MISSING_PENDING_PARTS_REASON', 'La descripción del repuesto requerido es obligatoria (pending_parts_reason obligatorio).', 422);
        }

        try {
            $paused = $this->incidentRepo->pauseIntervention($incidentId, $techId, $reason);
        } catch (InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_PAUSE', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }

        return Response::json([
            'id'     => $paused->getId(),
            'status' => $paused->getStatus()->value,
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-08, RF-REP-05, RF-REP-06 — Resolución Obligatoriamente Justificada con Repuestos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/technician/incidents/{id}/resolve
     * 
     * Resuelve y documenta formalmente la intervención técnica (RF-08, RF-REP-05, RF-REP-06).
     * Exige obligatoriamente:
     * - Diagnóstico real con al menos 20 caracteres (EARS 8.1 / Art. V.1).
     * - Acción correctiva con al menos 20 caracteres (EARS 8.1 / Art. V.1).
     * - Declaración de piezas sustituidas (Sí/No) con snapshot inmutable de costes (Art. III).
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
        $validationErrors = ResolutionValidator::getValidationErrors($diagnosis, $action);
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

        // 7. Determinar si incluye declaración de repuestos (Módulo M2) o resolución simple legacy (T-29)
        $hasSparePartsDeclaration = array_key_exists('replaced_parts_declared', $body) || array_key_exists('replaced_parts', $body);

        if ($hasSparePartsDeclaration) {
            $actor = $this->extractActor($request);
            try {
                $result = $this->traceabilityService->resolveIncidentWithParts($incidentId, $techId, $body, $actor);
                return Response::json($result, 200, 'Incidencia resuelta con registro de repuestos.');
            } catch (InvalidPartQuantityException $e) {
                return Response::error('INVALID_PART_QUANTITY', $e->getMessage(), 422);
            } catch (SparePartNotFoundException $e) {
                return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
            } catch (InvalidResolutionException $e) {
                return Response::error('INVALID_RESOLUTION', $e->getMessage(), 422, ['errors' => $e->getErrors()]);
            } catch (InvalidTransitionException $e) {
                return Response::error('INVALID_STATUS_FOR_RESOLUTION', $e->getMessage(), 422);
            } catch (\InvalidArgumentException $e) {
                $msg = $e->getMessage();
                $errCode = 'INVALID_RESOLUTION';
                if (str_contains($msg, 'replaced_parts_declared')) {
                    $errCode = 'PARTS_RECORD_REQUIRED';
                } elseif (str_contains($msg, 'destino')) {
                    $errCode = 'INVALID_PART_DESTINATION';
                } elseif (str_contains($msg, 'no ha registrado ninguna pieza')) {
                    $errCode = 'EMPTY_REPLACED_PARTS_LIST';
                }
                return Response::error($errCode, $msg, 422);
            } catch (\DomainException $e) {
                return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
            }
        }

        // Flujo simple legacy (T-29): sin declaración de piezas
        try {
            $resolved = $this->incidentRepo->resolve($incidentId, $techId, $diagnosis, $action);
        } catch (InvalidResolutionException $e) {
            return Response::error('INVALID_RESOLUTION', $e->getMessage(), 422, ['errors' => $e->getErrors()]);
        } catch (InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_RESOLUTION', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }

        return Response::json([
            'id'          => $resolved->getId(),
            'status'      => $resolved->getStatus()->value,
            'resolved_at' => $resolved->getResolvedAt(),
        ], 200);
    }

    /**
     * Extrae los metadatos del usuario autenticado para trazabilidad en auditoría.
     *
     * @param Request $request
     * @return array{id: int|null, role: string, name: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id'   => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        $userId = $request->getAttribute('user_id');
        return [
            'id'   => $userId !== null ? (int)$userId : 1,
            'role' => (string)$request->getAttribute('user_role', 'TECHNICIAN'),
            'name' => 'Técnico de Ruta',
        ];
    }
}

