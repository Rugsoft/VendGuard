<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\TechnicianRefundInspectionDTO;
use VendGuard\Application\DTO\TechnicianRefundViewDTO;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Application\Service\TechnicianRefundService;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\IncompatibleSparePartException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Exception\InvalidRecoveredAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUnclaimedCashFindingRepository;
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
use VendGuard\Infrastructure\Storage\LocalFileUploader;
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
    private RefundRequestRepositoryInterface $refundRepo;
    private TechnicianRefundService $refundService;
    private LocalFileUploader $fileUploader;
    private ?IncidentCommentService $commentService;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?SparePartTraceabilityService $traceabilityService = null,
        ?RefundRequestRepositoryInterface $refundRepo = null,
        ?TechnicianRefundService $refundService = null,
        ?LocalFileUploader $fileUploader = null,
        ?IncidentCommentService $commentService = null
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
        $this->refundRepo = $refundRepo ?? new PdoRefundRequestRepository();
        $this->refundService = $refundService ?? new TechnicianRefundService(
            refundRepo: $this->refundRepo,
            findingRepo: new PdoUnclaimedCashFindingRepository(),
            managementService: new RefundManagementService($this->refundRepo),
            // La regla de conserjeria (RF-REF-05) solo es exigible si
            // alguien sabe si la sede tiene mesa de recepcion.
            locationRepo: $this->locationRepo
        );

        $this->fileUploader = $fileUploader ?? new LocalFileUploader();

        // Inicialización perezosa: el AuditLogger por defecto del servicio de
        // comentarios abre conexión a MariaDB al instanciarse, y este controlador
        // también se construye en contextos unitarios sin base de datos.
        $this->commentService = $commentService;
    }

    /**
     * Servicio de aplicación del hilo de comentarios, resuelto bajo demanda.
     */
    private function comments(): IncidentCommentService
    {
        return $this->commentService ??= new IncidentCommentService($this->incidentRepo, null, $this->fileUploader);
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
                    // Coordinates for the one-tap GPS navigation button (RF-MAP-08).
                    'latitude'      => $location?->getLatitude(),
                    'longitude'     => $location?->getLongitude(),
                ],
                'category'             => $incident->getCategory()->value,
                'description'          => $incident->getDescription(),
                'started_at'           => $incident->getStartedAt(),
                'pending_parts_reason' => $incident->getPendingPartsReason(),
                'created_at'           => $incident->getCreatedAt(),
                // Insignia de conversación de la parada (RF-01.1): el técnico de ruta
                // contabiliza la TOTALIDAD de mensajes, públicos y notas internas de
                // taller, porque su canal sí tiene acceso legítimo a ambos (RF-02.3).
                'comments_count'       => $this->incidentRepo->countComments($incident->getId(), true),
            ];
        }

        return Response::json($routeData, 200);
    }

    /**
     * GET /api/technician/machines/{id}/history
     * 
     * Historial de averías de una máquina concreta para el técnico de ruta
     * (RF-07 / EARS H.1-H.5 de specs/technical/technician_machine_history_contracts.md).
     * Consulta de solo lectura, sin datos privados (Art. V.4), permitida únicamente
     * cuando el técnico autenticado tiene una avería de esa máquina en estados
     * activos de ruta (EARS H.3).
     */
    public function getMachineHistory(Request $request): Response
    {
        // 1. Identidad del técnico autenticado (EARS H.5: middleware TECHNICIAN).
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        // 2. ID de máquina de la ruta.
        $rawMachineId = $request->getRouteParam('id');
        if ($rawMachineId === null || !ctype_digit((string)$rawMachineId)) {
            return Response::error('INVALID_MACHINE_ID', 'El ID de máquina de la ruta no es válido.', 400);
        }
        $machineId = (int)$rawMachineId;

        // 3. La máquina debe existir (404 si no, EARS H.4).
        $machine = $this->machineRepo->findById($machineId, withIncident: false);
        if ($machine === null) {
            return Response::error('MACHINE_NOT_FOUND', 'La máquina solicitada no existe.', 404);
        }

        // 4. Autorización: la máquina debe estar en la ruta activa del técnico
        //    (al menos una avería suya en estados de ruta, EARS H.3).
        $activeIncident = $this->incidentRepo->findActiveByMachineId($machineId);
        if (
            $activeIncident === null
            || $activeIncident->getAssignedTechnicianId() !== (int)$technicianId
        ) {
            return Response::error(
                'NOT_ASSIGNED_TO_TECHNICIAN',
                'Solo puedes consultar el historial de máquinas con una avería activa asignada a tu ruta.',
                403
            );
        }

        // 5. Historial completo de la máquina, excluyendo descartes (EARS H.1)
        //    con recuento de reaperturas por expediente (EARS H.2).
        $history = $this->incidentRepo->findAllByMachineId($machineId);
        $historyPayload = [];
        foreach ($history as $incident) {
            $historyPayload[] = [
                'id'              => $incident->getId(),
                'ticket_code'     => $incident->getTicketCode(),
                'status'          => $incident->getStatus()->value,
                'urgency'         => $incident->getUrgency()->value,
                'category'        => $incident->getCategory()->value,
                'description'     => $incident->getDescription(),
                'technician_name' => $incident->getTechnicianName(),
                'created_at'      => $incident->getCreatedAt(),
                'resolved_at'     => $incident->getResolvedAt(),
                'closed_at'       => $incident->getClosedAt(),
                'reopen_count'    => $this->incidentRepo->countReopenEvents((int)$incident->getId()),
            ];
        }

        return Response::json([
            'machine' => [
                'id'    => $machine->getId(),
                'code'  => $machine->getCode(),
                'model' => $machine->getModel(),
            ],
            'history' => $historyPayload,
        ], 200);
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

        // 7. Bloque de dictamen de saldo (RF-REF-04, RF-REF-05, contrato §4.2.2).
        //
        //    Orden deliberado: TODA la validación ocurre antes de la primera
        //    escritura y el dictamen se registra ANTES de resolver la avería.
        //
        //    Why: the technician is standing at the machine. A 422 costs them a
        //    dropdown and one retry; a verdict silently dropped would leave the
        //    consumer's money in limbo with no record of what happened to it, and
        //    the technician has already driven away. Conversely the machine is
        //    never held out of service by a refund problem: the technical repair
        //    is the last write, so a failure there is reported honestly with the
        //    verdict already on file (RF-REF-09 decoupling).
        $refundBlock = $this->readRefundInspection($request, (int)$incident->getId());
        if ($refundBlock instanceof Response) {
            return $refundBlock;
        }

        $refundOutcome = null;
        if ($refundBlock instanceof TechnicianRefundInspectionDTO) {
            $refundOutcome = $this->fileRefundVerdict(
                $incidentId,
                $incident,
                $refundBlock,
                $techId,
                $request
            );
            if ($refundOutcome instanceof Response) {
                return $refundOutcome;
            }
        }

        // 8. Hallazgo de monedas de oficio (RF-REF-04): opcional e independiente
        //    de que exista o no una reclamación previa.
        $unclaimedFindingId = null;
        if ($this->wantsUnclaimedCash($body)) {
            $unclaimedFindingId = $this->registerUnclaimedCash($incidentId, $incident, $techId, $body, $request);
            if ($unclaimedFindingId instanceof Response) {
                return $unclaimedFindingId;
            }
        }

        // 9. Declaracion obligatoria de repuestos en la resolucion (RF-REP-05 / Modulo M2)
        $actor = $this->extractActor($request);
        if (!array_key_exists('replaced_parts_declared', $body) && !array_key_exists('replaced_parts', $body)) {
            $body['replaced_parts_declared'] = false;
        }

        try {
            $result = $this->traceabilityService->resolveIncidentWithParts($incidentId, $techId, $body, $actor);

            return Response::json(
                $result + $this->buildRefundSummary($incidentId, $refundOutcome, $unclaimedFindingId),
                200,
                $refundOutcome !== null
                    ? 'Incidencia resuelta y dictamen de efectivo registrado correctamente.'
                    : 'Incidencia resuelta con registro de repuestos.'
            );
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
            $errCode = (str_contains($e->getMessage(), 'transacci') || str_contains($e->getMessage(), 'base de datos'))
                ? 'SPARE_PART_TRANSACTION_FAILED'
                : 'OPERATION_FAILED';
            return Response::error($errCode, $e->getMessage(), 500);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-REF-04 / RF-REF-05 — Dictamen de saldo obligatorio en la resolución
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Valida el bloque `refund_inspection` del cuerpo de la resolución.
     *
     * Returns null when the incident carries nothing to rule on, which keeps the
     * endpoint working exactly as before for the overwhelming majority of faults
     * that have nothing to do with money (RF-REF-04 only makes the verdict
     * mandatory when a claim exists).
     *
     * @return TechnicianRefundInspectionDTO|Response|null
     */
    private function readRefundInspection(Request $request, int $incidentId): TechnicianRefundInspectionDTO|Response|null
    {
        // El dictamen se aplica solo a los expedientes PENDIENTES. Un expediente
        // ya dictaminado (avería reabierta dentro de las 48 h del Art. V.6) se
        // preserva inmutable, en lugar de bloquear la resolución entera con un
        // 409 que dejaba al técnico sin salida y al expediente atascado.
        $pending = array_values(array_filter(
            $this->refundRepo->findRestrictedByIncident($incidentId),
            static fn ($case): bool => $case->awaitsInspection()
        ));

        // Nada pendiente de dictaminar: la resolución sigue su curso normal.
        if ($pending === []) {
            return null;
        }

        $raw = $request->getParsedBody()['refund_inspection'] ?? null;
        if (!is_array($raw)) {
            return Response::error(
                'REFUND_INSPECTION_REQUIRED',
                'Esta avería tiene una reclamación de dinero pendiente: debe registrar el dictamen '
                    . 'de saldo (refund_inspection) antes de resolverla.',
                422
            );
        }

        $rawFinding = strtoupper(trim((string)($raw['finding'] ?? '')));
        $finding = TechnicianFinding::tryFrom($rawFinding);
        if ($finding === null) {
            return Response::error(
                'INVALID_REFUND_INSPECTION',
                'Indique el dictamen de saldo: se ha recuperado dinero físico, no se ha '
                    . 'verificado fallo de cobro, o no se localiza evidencia de saldo retenido.',
                422
            );
        }

        // RF-REF-04 (EARS Evento): `FOUND_PHYSICAL` sin importe recuperado es
        // una contradicción en el propio dictamen, no una omisión tolerable.
        $rawRecovered = $raw['recovered_amount'] ?? null;
        if ($finding === TechnicianFinding::FOUND_PHYSICAL && ($rawRecovered === null || !is_numeric($rawRecovered))) {
            return Response::error(
                'INVALID_REFUND_INSPECTION',
                'Si ha recuperado dinero físico, indique el importe exacto recuperado.',
                422
            );
        }

        $rawCustody = strtoupper(trim((string)($raw['cash_custody_action'] ?? '')));
        $custody = $rawCustody === '' ? null : CashCustodyAction::tryFrom($rawCustody);
        if ($rawCustody !== '' && $custody === null) {
            return Response::error(
                'INVALID_REFUND_INSPECTION',
                'La custodia del efectivo sólo admite dejarlo en recepción o custodiarlo en caja central.',
                422
            );
        }

        if ($rawRecovered !== null && !is_numeric($rawRecovered)) {
            return Response::error(
                'INVALID_REFUND_INSPECTION',
                'El importe recuperado debe ser un número.',
                422
            );
        }

        try {
            return new TechnicianRefundInspectionDTO(
                finding: $finding,
                recoveredAmount: $rawRecovered === null ? null : round((float)$rawRecovered, 2),
                cashCustodyAction: $custody,
                receptionistName: (string)($raw['receptionist_name'] ?? ''),
                justification: (string)($raw['justification'] ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_REFUND_INSPECTION', $e->getMessage(), 422);
        }
    }

    /**
     * Files the verdict against every claim of the incident.
     *
     * @return array<string, mixed>|Response The inspection outcome, or the error
     *   that must stop the resolution before anything is written.
     */
    private function fileRefundVerdict(
        int $incidentId,
        Incident $incident,
        TechnicianRefundInspectionDTO $dto,
        int $techId,
        Request $request
    ): array|Response {
        try {
            return $this->refundService->inspectBalance(
                incidentId: $incidentId,
                dto: $dto,
                machineId: (int)$incident->getMachineId(),
                actor: $this->extractActor($request)
            );
        } catch (InvalidRecoveredAmountException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode(), $e->getDetails());
        } catch (ReceptionDeliveryNotAllowedException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (JustificationTooShortException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_REFUND_INSPECTION', $e->getMessage(), 422);
        }
    }

    /**
     * Whether the body carries an on-site cash finding with no prior claim.
     *
     * @param array<string, mixed> $body
     */
    private function wantsUnclaimedCash(array $body): bool
    {
        $raw = $body['unclaimed_cash_found'] ?? null;

        return is_array($raw) && trim((string)($raw['amount'] ?? '')) !== '';
    }

    /**
     * Records cash recovered by the technician with no consumer claim (RF-REF-04).
     *
     * @param array<string, mixed> $body
     * @return int|null|Response The finding id, or an error response.
     */
    private function registerUnclaimedCash(
        int $incidentId,
        Incident $incident,
        int $techId,
        array $body,
        Request $request
    ): int|null|Response {
        $raw = $body['unclaimed_cash_found'];
        $amount = $raw['amount'] ?? null;

        if (!is_numeric($amount) || (float)$amount <= 0.0) {
            return Response::error(
                'INVALID_UNCLAIMED_CASH',
                'El importe del efectivo recuperado de oficio debe ser un número mayor que cero.',
                422
            );
        }

        try {
            return $this->refundService->registerUnclaimedCash(
                incidentId: $incidentId,
                machineId: (int)$incident->getMachineId(),
                technicianId: $techId,
                amount: round((float)$amount, 2),
                notes: (string)($raw['notes'] ?? ''),
                actor: $this->extractActor($request)
            )->getId();
        } catch (InvalidRecoveredAmountException $e) {
            // El importe se sale del rango admitido: es una entrada incorrecta
            // del técnico, no un fallo del sistema, asi que responde 422 y no 500.
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode(), $e->getDetails());
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_UNCLAIMED_CASH', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('OPERATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Merges the verdict outcome into the resolution payload (contract §4.2.2).
     *
     * @param array<string, mixed>|null $refundOutcome
     * @return array<string, mixed>
     */
    private function buildRefundSummary(int $incidentId, ?array $refundOutcome, int|null $unclaimedFindingId): array
    {
        $summary = ['incident_id' => $incidentId];

        if ($refundOutcome !== null) {
            $summary['refund_processed'] = $refundOutcome['processed'];
            $summary['refund_status'] = $refundOutcome['statuses'][0] ?? null;
            $summary['refund_statuses'] = $refundOutcome['statuses'];
            $summary['recovered_total'] = $refundOutcome['recovered_total'];
            $summary['claimed_total'] = $refundOutcome['claimed_total'];
            // Aviso de discrepancia: el efectivo recuperado no cubre lo reclamado
            // y Coordinación debe repartirlo (RF-REF-08).
            $summary['refund_discrepancy'] = $refundOutcome['discrepancy'];
        }

        if ($unclaimedFindingId !== null) {
            $summary['unclaimed_cash_finding_id'] = $unclaimedFindingId;
        }

        return $summary;
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

    // ────────────────────────────────────────────────────────────────────────
    // Módulo 10 · T-COM-06 — Hilo de conversación del expediente
    // Orquestado por IncidentCommentService: la segregación, el enmascaramiento
    // y la máquina de estados del sellado viven en la capa de aplicación.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/technician/incidents/{id}/comments
     *
     * Devuelve el hilo íntegro del expediente para el Técnico de Ruta: comentarios
     * públicos y notas internas de taller, con la identidad nominal real de los
     * compañeros y el candado `is_internal` expuesto (RF-01.2, RF-02.3).
     *
     * El acceso se limita al expediente asignado al técnico autenticado: las notas
     * internas son material confidencial del equipo y solo conciernen a quien
     * interviene la avería (Art. V.4).
     */
    public function getComments(Request $request): Response
    {
        // 1. Identidad del técnico autenticado (middleware InternalAuthMiddleware).
        $technician = $this->resolveAuthenticatedTechnician($request);
        if ($technician === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        // 2. Expediente de la ruta: existencia y asignación (404 / 403).
        $incident = $this->resolveAssignedIncident($request, $technician['id']);
        if ($incident instanceof Response) {
            return $incident;
        }

        // 3. Cursor de paginación cursorizada (RF-01.2, RF-01.3).
        $limitRaw = $request->getQuery('limit');
        if ($limitRaw !== null && (!ctype_digit((string)$limitRaw) || (int)$limitRaw < 1)) {
            return Response::error('INVALID_LIMIT', 'El parámetro limit debe ser un entero positivo.', 400);
        }
        $limit = $limitRaw !== null ? (int)$limitRaw : IncidentCommentService::DEFAULT_THREAD_LIMIT;

        $beforeIdRaw = $request->getQuery('before_id');
        if ($beforeIdRaw !== null && (!ctype_digit((string)$beforeIdRaw) || (int)$beforeIdRaw < 1)) {
            return Response::error('INVALID_BEFORE_ID', 'El parámetro before_id debe ser un entero positivo.', 400);
        }
        $beforeId = $beforeIdRaw !== null ? (int)$beforeIdRaw : null;

        // 4. Proyección nominal completa en servidor, notas internas incluidas.
        try {
            $thread = $this->comments()->getThread(
                (int)$incident->getId(),
                'TECHNICIAN',
                $technician['id'],
                $limit,
                $beforeId
            );
        } catch (ConversationSealedException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (\DomainException $e) {
            return Response::error('INCIDENT_NOT_FOUND', 'La incidencia solicitada no existe.', 404);
        }

        return Response::json($thread->jsonSerialize(), 200);
    }

    /**
     * POST /api/technician/incidents/{id}/comments
     *
     * Publica un comentario o una nota interna de taller en el expediente de la
     * ruta del técnico autenticado (RF-03.3, RF-04.1).
     *
     * Garantías de servidor:
     * - El selector de privacidad se interpreta con fail-safe: si `is_internal`
     *   no viaja, la nota se clasifica como interna (RF-03.3) para no filtrar al
     *   cliente material de taller por un campo ausente.
     * - La fotografía in situ se valida en servidor (≤ 5 MB y magic bytes reales)
     *   mediante LocalFileUploader antes de persistir nada (Art. V.5 / RF-04.2).
     * - Expediente sellado (CLOSED/CANCELLED o RESOLVED fuera de la garantía de
     *   48 h) se rechaza con HTTP 403 Forbidden (RF-05.3 / RF-07.2).
     */
    public function addComment(Request $request): Response
    {
        // 1. Identidad del técnico autenticado: autoría nominal real (RF-02.3).
        $technician = $this->resolveAuthenticatedTechnician($request);
        if ($technician === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        // 2. Expediente de la ruta: existencia y asignación (404 / 403).
        $incident = $this->resolveAssignedIncident($request, $technician['id']);
        if ($incident instanceof Response) {
            return $incident;
        }

        // 3. Texto del mensaje: solo se comprueba su presencia (400); los límites
        //    de 5 a 1.000 caracteres descriptivos los aplica el servicio (RF-03.1).
        $commentText = trim((string)($request->getBodyParam('comment_text')
            ?? $request->getBodyParam('text')
            ?? $request->getBodyParam('comment')
            ?? ''));
        if ($commentText === '') {
            return Response::error(
                'MISSING_COMMENT_TEXT',
                'El texto del comentario es obligatorio.',
                400,
                ['form_data' => ['comment_text' => $commentText]]
            );
        }

        // 4. Selector de privacidad (RF-03.3): "Nota Interna de Taller" viene
        //    PRESELECCIONADA por defecto; solo un valor explícito la desmarca.
        $isInternal = true;
        if (array_key_exists('is_internal', $request->getParsedBody())) {
            $parsedFlag = $this->parseBooleanFlag($request->getBodyParam('is_internal'));
            if ($parsedFlag === null) {
                return Response::error(
                    'INVALID_IS_INTERNAL',
                    'El indicador is_internal debe ser booleano (true/false).',
                    422
                );
            }
            $isInternal = $parsedFlag;
        }

        // 5. Evidencia fotográfica in situ (multipart/form-data, RF-04.1).
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        // 6. Publicación orquestada: sellado, clasificación y auditoría en el servicio.
        try {
            $thread = $this->comments()->addComment(
                (int)$incident->getId(),
                'TECHNICIAN',
                $commentText,
                [
                    'id' => $technician['id'],
                    'role' => 'TECHNICIAN',
                    'name' => $technician['name'],
                ],
                $isInternal,
                $photoFile
            );
        } catch (InvalidCommentLengthException $e) {
            // RF-07.1: se devuelven los datos de texto íntegros para el reintento.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['form_data' => ['ticket_code' => $incident->getTicketCode(), 'comment_text' => $commentText]]
            );
        } catch (ConversationSealedException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['form_data' => ['ticket_code' => $incident->getTicketCode(), 'comment_text' => $commentText]]
            );
        } catch (InvalidUploadException $e) {
            // RF-07.1: texto y clasificación íntegros para el reintento manual.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'form_data' => [
                        'ticket_code' => $incident->getTicketCode(),
                        'comment_text' => $commentText,
                        'is_internal' => $isInternal,
                    ],
                ]
            );
        } catch (\DomainException $e) {
            return Response::error('INCIDENT_NOT_FOUND', 'La incidencia solicitada no existe.', 404);
        }

        // 7. Respuesta 201 Created con el hilo actualizado del expediente (RF-03.4).
        return Response::json(
            $thread->jsonSerialize(),
            201,
            $isInternal
                ? 'Nota interna registrada en el hilo de conversación'
                : 'Comentario publicado en el hilo de conversación'
        );
    }

    /**
     * Identidad del técnico autenticado para la autoría nominal del mensaje.
     *
     * Devuelve null cuando no hay una identidad fiable (sin ID o sin nombre), de
     * modo que el endpoint responda 401 en lugar de firmar una nota de taller con
     * una identidad inventada: la autoría es parte del registro inmutable (Art. III).
     *
     * @return array{id: int, name: string}|null
     */
    private function resolveAuthenticatedTechnician(Request $request): ?array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            $name = trim($user->getName());

            return $name === '' ? null : ['id' => (int)$user->getId(), 'name' => $name];
        }

        $userId = $request->getAttribute('user_id');
        if ($userId === null || !is_numeric($userId)) {
            return null;
        }

        $name = trim((string)$request->getAttribute('user_name', ''));

        return $name === '' ? null : ['id' => (int)$userId, 'name' => $name];
    }

    /**
     * Resuelve el expediente de la ruta y verifica que esté asignado al técnico.
     *
     * @return Incident|Response El expediente autorizado o la respuesta de error
     *   (400 identificador ausente, 404 inexistente, 403 no asignado).
     */
    private function resolveAssignedIncident(Request $request, int $technicianId): Incident|Response
    {
        $identifier = $request->getRouteParam('id')
            ?? $request->getRouteParam('ticket_code')
            ?? $request->getRouteParam('code');
        if ($identifier === null || trim($identifier) === '') {
            return Response::error(
                'MISSING_INCIDENT_ID',
                'El identificador de la incidencia es obligatorio en la URL.',
                400
            );
        }
        $identifier = trim($identifier);

        $incident = ctype_digit($identifier)
            ? $this->incidentRepo->findById((int)$identifier)
            : $this->incidentRepo->findByTicketCode(strtoupper(ltrim($identifier, '#')));

        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el identificador '{$identifier}'.",
                404
            );
        }

        // Un solo técnico responsable activo por incidencia (Art. V): el hilo de
        // taller solo se abre al técnico al que el expediente está asignado.
        if ($incident->getAssignedTechnicianId() !== $technicianId) {
            return Response::error(
                'NOT_ASSIGNED_TO_TECHNICIAN',
                'Esta incidencia no está asignada a tu ruta técnica.',
                403
            );
        }

        return $incident;
    }

    /**
     * Interpreta un indicador booleano del cuerpo de la petición (true/false, 1/0, 'yes'/'no').
     *
     * Devuelve null cuando el valor recibido no es un booleano reconocible, de modo
     * que el controlador pueda rechazarlo con 422 en lugar de coercionar en silencio.
     */
    private function parseBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            if ($value == 1) {
                return true;
            }
            if ($value == 0) {
                return false;
            }

            return null;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return null;
    }
}

