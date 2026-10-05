<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorController
 * 
 * Controlador REST para las operaciones del Coordinador del Servicio (RF-05, RF-06, RF-11).
 * Gestiona la bandeja global de triaje, supervisión de averías y cálculo de SLA 24/7.
 * 
 * Cumple con el Dogma Vanilla (PHP 8.2+ puro, PDO) y el principio de Clean Architecture.
 */
class CoordinatorController
{
    /**
     * Longitud mínima del motivo de descarte exigida por RF-07.4 (Art. III.2 y V.1):
     * 20 caracteres reales contados sobre el texto ya recortado.
     */
    private const MIN_CANCELLATION_REASON_LENGTH = 20;

    private IncidentRepositoryInterface $incidentRepo;
    private UserRepositoryInterface $userRepo;
    private LocationRepositoryInterface $locationRepo;
    private MachineRepositoryInterface $machineRepo;
    private CoordinatorIncidentDetailService $incidentDetailService;
    private AuditLogger $auditLogger;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?CoordinatorIncidentDetailService $incidentDetailService = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        // El servicio de detalle se compone sobre el mismo repositorio inyectado para
        // que el endpoint y la lectura agregada compartan una única fuente de datos.
        $this->incidentDetailService = $incidentDetailService ?? new CoordinatorIncidentDetailService($this->incidentRepo);
        $this->auditLogger = $auditLogger ?? new AuditLogger(new PdoAuditLogRepository());
    }

    /**
     * GET /api/coordinator/incidents
     * 
     * Consulta el listado global de averías para supervisión y asignación (RF-05, RF-11).
     * Soporta filtros opcionales por:
     * - status: estado canónico o lista separada por comas (ej. 'REGISTERED,REOPENED')
     * - urgency: nivel de severidad (ej. 'CRITICAL', 'HIGH')
     * - location_id: identificador de sede
     * - technician_id / assigned_technician_id: identificador del técnico asignado
     * - active_only: si es true, excluye tickets terminales (CLOSED, CANCELLED)
     * 
     * Calcula para cada incidencia:
     * - sla_minutes_elapsed: minutos de espera transcurridos desde creación o reapertura
     * - sla_breached: true si es de urgencia CRÍTICA, sigue sin asignación y supera 60 minutos (EARS 11.1)
     * - assigned_technician: objeto con id y nombre del técnico asignado o null
     * 
     * @param Request $request
     * @return Response
     */
    public function getIncidents(Request $request): Response
    {
        $filters = [];

        // 1. Filtro opcional por Estado (status)
        $statusParam = $request->getQuery('status');
        if ($statusParam !== null && trim((string)$statusParam) !== '') {
            $rawValues = array_map('trim', explode(',', (string)$statusParam));
            $validStatuses = [];

            foreach ($rawValues as $rawVal) {
                if ($rawVal === '') {
                    continue;
                }
                $upper = strtoupper($rawVal);
                if (!IncidentStatus::isValid($upper)) {
                    return Response::error(
                        'INVALID_STATUS_FILTER',
                        "El estado de filtro '{$rawVal}' no es válido. Valores permitidos: " . implode(', ', IncidentStatus::values()),
                        400
                    );
                }
                $validStatuses[] = $upper;
            }

            if (!empty($validStatuses)) {
                $filters['status'] = count($validStatuses) === 1 ? $validStatuses[0] : $validStatuses;
            }
        }

        // 2. Filtro opcional por Urgencia (urgency)
        $urgencyParam = $request->getQuery('urgency');
        if ($urgencyParam !== null && trim((string)$urgencyParam) !== '') {
            $rawUrgencies = array_map('trim', explode(',', (string)$urgencyParam));
            $validUrgencies = [];

            foreach ($rawUrgencies as $rawUrg) {
                if ($rawUrg === '') {
                    continue;
                }
                $upperUrg = strtoupper($rawUrg);
                if (!UrgencyLevel::isValid($upperUrg)) {
                    return Response::error(
                        'INVALID_URGENCY_FILTER',
                        "El nivel de urgencia '{$rawUrg}' no es válido. Valores permitidos: " . implode(', ', UrgencyLevel::values()),
                        400
                    );
                }
                $validUrgencies[] = $upperUrg;
            }

            if (!empty($validUrgencies)) {
                $filters['urgency'] = count($validUrgencies) === 1 ? $validUrgencies[0] : $validUrgencies[0];
            }
        }

        // 3. Filtro opcional por Sede (location_id)
        $locationParam = $request->getQuery('location_id');
        if ($locationParam !== null && trim((string)$locationParam) !== '') {
            if (!is_numeric($locationParam)) {
                return Response::error(
                    'INVALID_LOCATION_FILTER',
                    'El parámetro location_id debe ser un número entero.',
                    400
                );
            }
            $filters['location_id'] = (int)$locationParam;
        }

        // 4. Filtro opcional por Técnico asignado (technician_id / assigned_technician_id)
        $techParam = $request->getQuery('technician_id') ?? $request->getQuery('assigned_technician_id');
        if ($techParam !== null && trim((string)$techParam) !== '') {
            if (!is_numeric($techParam)) {
                return Response::error(
                    'INVALID_TECHNICIAN_FILTER',
                    'El parámetro technician_id debe ser un número entero.',
                    400
                );
            }
            $filters['assigned_technician_id'] = (int)$techParam;
        }

        // 5. Filtro opcional por tickets activos únicamente
        $activeParam = $request->getQuery('active_only');
        if ($activeParam !== null && in_array(strtolower((string)$activeParam), ['1', 'true', 'yes'], true)) {
            $filters['active_only'] = true;
        }

        // 6. Consultar repositorio con los filtros aplicados
        $incidents = $this->incidentRepo->findAll($filters);

        // 7. Enriquecer con cálculo de minutos de espera y evaluación de SLA 24/7 (RF-11 / EARS 11.1)
        $now = time();
        $formattedIncidents = [];

        foreach ($incidents as $incident) {
            $createdAt = strtotime($incident->getCreatedAt() ?? 'now');
            if ($createdAt === false) {
                $createdAt = $now;
            }

            // Si fue reabierta, el reloj de triaje cuenta desde la reapertura; si no, desde su creación
            $referenceStartTime = ($incident->getStatus() === IncidentStatus::REOPENED && $incident->getReopenedAt() !== null)
                ? (strtotime($incident->getReopenedAt()) ?: $createdAt)
                : $createdAt;

            $isUnassigned = ($incident->getAssignedTechnicianId() === null);
            $isWaitingForTriage = $isUnassigned || in_array($incident->getStatus(), [IncidentStatus::REGISTERED, IncidentStatus::REOPENED], true);

            // Minutos de espera en la cola
            if ($isWaitingForTriage) {
                $minutesWaiting = (int)max(0, floor(($now - $referenceStartTime) / 60));
            } elseif ($incident->getAssignedAt() !== null) {
                $assignedTimestamp = strtotime($incident->getAssignedAt());
                $minutesWaiting = $assignedTimestamp !== false
                    ? (int)max(0, floor(($assignedTimestamp - $referenceStartTime) / 60))
                    : (int)max(0, floor(($now - $referenceStartTime) / 60));
            } else {
                $minutesWaiting = (int)max(0, floor(($now - $referenceStartTime) / 60));
            }

            // Regla SLA (EARS 11.1):
            // Incidencias CRÍTICAS que permanezcan en REGISTRADA/REABIERTA (sin técnico asignado) > 60 minutos
            $isCritical = ($incident->getUrgency() === UrgencyLevel::CRITICAL);
            $slaBreached = $isCritical && $isWaitingForTriage && ($minutesWaiting > 60);

            // Estructura del técnico asignado según contrato API
            $assignedTechnician = null;
            if ($incident->getAssignedTechnicianId() !== null) {
                $assignedTechnician = [
                    'id' => $incident->getAssignedTechnicianId(),
                    'name' => $incident->getTechnicianName(),
                ];
            }

            // Construir representación final combinando toArray() y contratos específicos
            $item = array_merge($incident->toArray(), [
                'assigned_technician' => $assignedTechnician,
                'sla_minutes_elapsed' => $minutesWaiting,
                'waiting_minutes' => $minutesWaiting,
                'sla_breached' => $slaBreached,
            ]);

            $formattedIncidents[] = $item;
        }

        return Response::json($formattedIncidents, 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-05 / EARS 5.1-5.4 — Asignar Técnico con Reclasificación Auditada
    // ────────────────────────────────────────────────────────────────────────

    /**
     * PATCH /api/coordinator/incidents/{id}/assign
     * 
     * Asocia un técnico de campo único a la incidencia (EARS 5.1, 5.2).
     * Permite la reclasificación de urgencia con motivo obligatorio (EARS 5.3).
     * Rechaza la petición si no se proporciona un técnico válido (EARS 5.4).
     */
    public function assignTechnician(Request $request): Response
    {
        // 1. Extraer y validar el ID de incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Extraer y validar el cuerpo de la petición (EARS 5.4: technician_id obligatorio)
        $body = $request->getParsedBody();
        $rawTechnicianId = $body['technician_id'] ?? null;
        if ($rawTechnicianId === null || !is_numeric($rawTechnicianId) || (int)$rawTechnicianId <= 0) {
            return Response::error('MISSING_TECHNICIAN_ID', 'Se debe indicar un técnico válido (technician_id obligatorio).', 422);
        }
        $technicianId = (int)$rawTechnicianId;

        $urgencyOverride = isset($body['urgency_override']) ? trim((string)$body['urgency_override']) : null;
        $urgencyReason   = isset($body['urgency_override_reason']) ? trim((string)$body['urgency_override_reason']) : null;

        // 3. Si hay reclasificación de urgencia, el motivo es obligatorio (EARS 5.3)
        if ($urgencyOverride !== null && $urgencyOverride !== '') {
            if (!UrgencyLevel::isValid($urgencyOverride)) {
                return Response::error('INVALID_URGENCY', "Nivel de urgencia '{$urgencyOverride}' no reconocido. Valores aceptados: LOW, MEDIUM, HIGH, CRITICAL.", 422);
            }
            if ($urgencyReason === null || $urgencyReason === '') {
                return Response::error('URGENCY_REASON_REQUIRED', 'La reclasificación de urgencia exige un motivo justificado (urgency_override_reason obligatorio).', 422);
            }
        }

        // 4. Verificar que la incidencia exista
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 5. Verificar que el técnico exista y tenga rol TECHNICIAN (EARS 5.2, 5.4)
        $technician = $this->userRepo->findById($technicianId, onlyActive: true);
        if ($technician === null || $technician->getRole()->value !== 'TECHNICIAN') {
            return Response::error('TECHNICIAN_NOT_FOUND', "No se encontró ningún técnico de ruta activo con ID {$technicianId}.", 422);
        }

        // 6. Validar estado actual: solo REGISTERED o REOPENED (EARS 5.1)
        $allowedStatuses = [IncidentStatus::REGISTERED, IncidentStatus::REOPENED];
        if (!in_array($incident->getStatus(), $allowedStatuses, true)) {
            return Response::error('INVALID_STATUS_FOR_ASSIGNMENT', "Solo se pueden asignar incidencias en estado REGISTERED o REOPENED. Estado actual: {$incident->getStatus()->value}.", 422);
        }

        // 7. ID del coordinador autenticado (para auditoría)
        $coordinatorId = $request->getAttribute('user_id');

        // 8. Realizar la asignación transaccional en el repositorio
        try {
            $assigned = $this->incidentRepo->assign(
                incidentId: $incidentId,
                technicianId: $technicianId,
                coordinatorId: $coordinatorId !== null ? (int)$coordinatorId : null,
                urgencyOverride: ($urgencyOverride !== null && $urgencyOverride !== '') ? $urgencyOverride : null,
                urgencyReason: ($urgencyReason !== null && $urgencyReason !== '') ? $urgencyReason : null,
            );
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_ASSIGNMENT', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('ASSIGNMENT_FAILED', $e->getMessage(), 500);
        }


        // 9. Respuesta exitosa con datos esenciales de la incidencia asignada (contrato 4.2)
        return Response::json([
            'id'                     => $assigned->getId(),
            'status'                 => $assigned->getStatus()->value,
            'assigned_technician_id' => $assigned->getAssignedTechnicianId(),
            'assigned_at'            => $assigned->getAssignedAt(),
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-06 / EARS 6.1-6.3 — Descarte o Cancelación Lógica de Avisos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * PATCH /api/coordinator/incidents/{id}/cancel
     * 
     * Descarta o anula lógicamente una incidencia activa (EARS 6.1, 6.2, 6.3).
     * Exige obligatoriamente un motivo de descarte de al menos 20 caracteres reales
     * (RF-07.4, Art. III.2 y V.1), medidos con `mb_strlen()` sobre el texto ya recortado
     * para que acentos y símbolos cuenten como un único carácter y los espacios de
     * relleno no sirvan para superar el umbral.
     * Mantiene íntegra la fila en base de datos (Soft Delete / trazabilidad).
     * 
     * Respuestas: 200 OK con el ticket descartado; 400 si el ID de ruta no es numérico;
     * 422 `MISSING_CANCELLATION_REASON` si el motivo falta o está vacío; 422
     * `CANCELLATION_REASON_TOO_SHORT` si no alcanza el mínimo de caracteres reales; 404 si
     * la incidencia no existe; 422 `INVALID_STATUS_FOR_CANCELLATION` si el estado no admite
     * el descarte; 500 `CANCELLATION_FAILED` ante un fallo inesperado del repositorio.
     */
    public function cancelIncident(Request $request): Response
    {
        // 1. Extraer y validar el ID de incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Extraer y validar el motivo de cancelación (EARS 6.1, 6.3)
        $body = $request->getParsedBody();
        $reason = isset($body['cancellation_reason']) ? trim((string)$body['cancellation_reason']) : '';
        if ($reason === '') {
            return Response::error('MISSING_CANCELLATION_REASON', 'El motivo de cancelación es obligatorio (cancellation_reason obligatorio).', 422);
        }

        // 3. Mínimo de caracteres reales exigido por RF-07.4 (Art. III.2 y V.1). La
        //    medición es multibyte-safe y se aplica sobre el texto ya recortado, de modo
        //    que un motivo relleno de espacios no alcanza el umbral.
        if (mb_strlen($reason, 'UTF-8') < self::MIN_CANCELLATION_REASON_LENGTH) {
            return Response::error(
                'CANCELLATION_REASON_TOO_SHORT',
                'El motivo de descarte debe contener al menos 20 caracteres reales que justifiquen la anulación del aviso.',
                422
            );
        }

        // 4. Verificar que la incidencia exista
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 5. Validar que el estado actual admita transición a CANCELLED
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::CANCELLED)) {
            return Response::error(
                'INVALID_STATUS_FOR_CANCELLATION',
                "No se puede cancelar una incidencia en estado {$incident->getStatus()->value}.",
                422
            );
        }

        // 6. ID del coordinador autenticado (para auditoría)
        $coordinatorId = $request->getAttribute('user_id');

        // 7. Ejecutar cancelación lógica en el repositorio
        try {
            $cancelled = $this->incidentRepo->cancel(
                incidentId: $incidentId,
                cancellationReason: $reason,
                coordinatorId: $coordinatorId !== null ? (int)$coordinatorId : null
            );
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_CANCELLATION', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('CANCELLATION_FAILED', $e->getMessage(), 500);
        }

        // 8. Respuesta exitosa (contrato 4.3)
        return Response::json([
            'id'           => $cancelled->getId(),
            'status'       => $cancelled->getStatus()->value,
            'cancelled_at' => $cancelled->getCancelledAt(),
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-01 / RF-02 / RF-03 / RF-04 / RF-06 — Ficha Integral de Detalle (Módulo 09)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/coordinator/incidents/{id}/detail
     * 
     * Devuelve la ficha integral enriquecida del expediente que consume el modal de
     * detalle del triaje (plan §2.1, RF-01 a RF-06): metadatos de sede y máquina,
     * cronograma de hitos, evaluación del SLA de frío, intervención técnica con
     * repuestos y costes congelados, bitácora de comentarios, expediente de reintegro
     * con datos de contacto y pago enmascarados en servidor (Art. V.4) y matriz de
     * permisos según la máquina de estados.
     * 
     * El identificador de ruta admite el ID primario (entero positivo) o el código de
     * ticket con prefijo '#' opcional (ej. INC-2026-0001). Toda respuesta viaja en la
     * envolvente canónica JSON de VendGuard.
     * 
     * Respuestas: 200 OK con los diez bloques del contrato; 400 si el identificador no
     * es válido; 401/403 si la petición no procede de un coordinador autenticado
     * (defensa en profundidad, además del middleware de la ruta registrada en T-IDM-07);
     * 404 si el ticket no existe o está borrado lógicamente.
     */
    public function getIncidentDetail(Request $request): Response
    {
        // 1. Control de acceso al detalle integral antes de leer o validar nada más (Art. V.4)
        $authFailure = $this->authorizeCoordinator($request);
        if ($authFailure !== null) {
            return $authFailure;
        }

        // 2. Identificador de ruta: ID primario positivo o código de ticket (# opcional)
        $rawIdentifier = trim((string)($request->getRouteParam('id') ?? ''));
        $identifier = $this->resolveIncidentIdentifier($rawIdentifier);
        if ($identifier === null) {
            return Response::error(
                'INVALID_INCIDENT_IDENTIFIER',
                'El identificador de la incidencia no es válido. Se admite un ID numérico positivo o un código de ticket (ej: INC-2026-0001).',
                400
            );
        }

        // 3. Ensamblar la ficha completa en una única lectura agregada (RNF-01)
        $detail = $this->incidentDetailService->buildDetail($identifier);
        if ($detail === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        // 4. Envolvente canónica con los diez bloques del contrato del modal
        return Response::json($detail->toArray(), 200);
    }

    /**
     * POST /api/coordinator/incidents/{id}/comments
     * 
     * Añade un comentario o nota interna de taller a la bitácora de una incidencia
     * desde el modal de detalle (RF-05.3, RNF-04). El texto es obligatorio y debe
     * alcanzar 5 caracteres reales; `is_internal` decide si la nota es pública
     * (visible en el portal de sede) o confidencial de taller (Art. V.4).
     * 
     * La máquina de estados se respeta en servidor reutilizando la matriz de
     * permisos del servicio de detalle: los tickets activos admiten comentarios, un
     * ticket resuelto solo dentro de las 48 horas de garantía (Art. V.6) y los
     * tickets cerrados o descartados quedan sellados (Art. III).
     * 
     * Cada escritura deja constancia inmutable del evento INCIDENT_COMMENT_ADDED en
     * `audit_log` con el coordinador autenticado (Art. III.3) y responde 201 Created
     * con el comentario persistido.
     */
    public function addComment(Request $request): Response
    {
        // 1. Control de acceso antes de leer o persistir nada (Art. V.4)
        $authFailure = $this->authorizeCoordinator($request);
        if ($authFailure !== null) {
            return $authFailure;
        }

        // 2. Identificador de ruta: ID primario positivo o código de ticket (# opcional)
        $rawIdentifier = trim((string)($request->getRouteParam('id') ?? ''));
        $identifier = $this->resolveIncidentIdentifier($rawIdentifier);
        if ($identifier === null) {
            return Response::error(
                'INVALID_INCIDENT_IDENTIFIER',
                'El identificador de la incidencia no es válido. Se admite un ID numérico positivo o un código de ticket (ej: INC-2026-0001).',
                400
            );
        }

        // 3. Validación temprana del cuerpo: texto obligatorio y mínimo de 5 caracteres
        $commentText = trim((string)($request->getBodyParam('comment_text') ?? ''));
        if ($commentText === '') {
            return Response::error(
                'MISSING_COMMENT_TEXT',
                'El texto del comentario es obligatorio (comment_text).',
                422
            );
        }
        if (mb_strlen($commentText) < 5) {
            return Response::error(
                'COMMENT_TOO_SHORT',
                'El comentario debe contener al menos 5 caracteres descriptivos.',
                422
            );
        }

        // 4. Visibilidad del comentario: público por defecto, booleano estricto si viaja
        $isInternal = false;
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

        // 5. Recuperar la incidencia referenciada (404 si no existe o está borrada)
        $incident = is_int($identifier)
            ? $this->incidentRepo->findById($identifier)
            : $this->incidentRepo->findByTicketCode(strtoupper(ltrim($identifier, '#')));

        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        // 6. Ventana de comentarios según la máquina de estados (RF-07.2, Art. V.6)
        $permissions = $this->incidentDetailService->computePermissions([
            'status' => $incident->getStatus()->value,
            'resolved_at' => $incident->getResolvedAt(),
            'updated_at' => $incident->getUpdatedAt(),
        ]);
        if (($permissions['can_add_comment'] ?? false) !== true) {
            $isSealed = in_array($incident->getStatus(), [IncidentStatus::CLOSED, IncidentStatus::CANCELLED], true);
            return Response::error(
                'COMMENT_WINDOW_CLOSED',
                $isSealed
                    ? 'La bitácora de una incidencia cerrada o descartada está sellada y no admite nuevos comentarios.'
                    : 'La ventana de garantía de 48 horas para comentar esta incidencia resuelta ha finalizado.',
                422
            );
        }

        // 7. Persistir el comentario en la bitácora con su bandera de visibilidad
        $actor = $this->extractActor($request);
        $comment = new IncidentComment(
            id: null,
            incidentId: (int)$incident->getId(),
            authorType: 'COORDINATOR',
            userId: $actor['id'],
            authorName: $actor['name'],
            commentText: $commentText,
            photoPath: null,
            isInternal: $isInternal,
            createdAt: null,
            ticketCode: $incident->getTicketCode()
        );

        $createdComment = $this->incidentRepo->addComment($comment);

        // 8. Evento inmutable en audit_log con el coordinador autenticado (Art. III.3)
        $this->auditLogger->logTicketEvent(
            ticketId: (int)$incident->getId(),
            action: 'INCIDENT_COMMENT_ADDED',
            user: $actor,
            previousState: ['status' => $incident->getStatus()->value],
            newState: [
                'comment_id' => $createdComment->getId(),
                'author_type' => 'COORDINATOR',
                'is_internal' => $createdComment->isInternal(),
                'comment_text' => $createdComment->getCommentText(),
            ],
            metadata: [
                'ticket_code' => $incident->getTicketCode(),
                'visibility' => $createdComment->isInternal() ? 'INTERNAL' : 'PUBLIC',
            ]
        );

        // 9. Respuesta 201 Created con el comentario anexado a la bitácora
        return Response::json(
            $createdComment->toArray(),
            201,
            'Comentario añadido correctamente a la bitácora de la incidencia.'
        );
    }

    /**
     * 401 sin identidad y 403 sin rol de Coordinación para el detalle integral.
     * 
     * El middleware de la ruta (T-IDM-07) ya exige rol COORDINATOR; esta comprobación
     * es defensa en profundidad para que el controlador no dependa jamás de cómo fue
     * montado y nunca lea un expediente para un actor no autorizado (Art. V.4).
     */
    private function authorizeCoordinator(Request $request): ?Response
    {
        $userId = $request->getAttribute('user_id');
        if ($userId === null || !is_numeric($userId) || (int)$userId < 1) {
            return Response::error('UNAUTHORIZED', 'Token de autenticación ausente o inválido.', 401);
        }

        if ((string)$request->getAttribute('user_role') !== 'COORDINATOR') {
            return Response::error(
                'FORBIDDEN',
                'No dispone de permisos para consultar el detalle integral de incidencias de Coordinación.',
                403
            );
        }

        return null;
    }

    /**
     * Normaliza el `{id}` de la ruta a un identificador de dominio.
     * 
     * Devuelve el entero positivo cuando el segmento es numérico y el código de ticket
     * cuando cumple el formato admitido (alfanumérico con guiones y '#' inicial
     * opcional). Cualquier otro valor devuelve null y el controlador responde 400.
     *
     * @return int|string|null
     */
    private function resolveIncidentIdentifier(string $rawIdentifier): int|string|null
    {
        if ($rawIdentifier === '') {
            return null;
        }

        // ID primario: solo enteros positivos; '-5' o '0' no viajan como código de ticket.
        if (preg_match('/^[+-]?\d+$/', $rawIdentifier) === 1) {
            return (ctype_digit($rawIdentifier) && (int)$rawIdentifier > 0) ? (int)$rawIdentifier : null;
        }

        // Código de ticket: alfanumérico con guiones y '#' inicial opcional (ej: INC-2026-0001).
        if (preg_match('/^#?[A-Za-z0-9][A-Za-z0-9-]{2,29}$/', $rawIdentifier) === 1) {
            return $rawIdentifier;
        }

        return null;
    }

    /**
     * Interpreta un indicador booleano del cuerpo JSON (true/false, 1/0, 'yes'/'no').
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

    /**
     * Identidad del coordinador actuante para la bitácora y la auditoría.
     *
     * Usa el usuario autenticado inyectado por el middleware; si no viaja como
     * entidad, cae a los atributos planos que el propio middleware garantiza.
     *
     * @return array{id: int|null, role: string, name: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id' => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        return [
            'id' => $request->getAttribute('user_id') !== null ? (int)$request->getAttribute('user_id') : null,
            'role' => (string)$request->getAttribute('user_role', 'COORDINATOR'),
            'name' => 'Coordinación',
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-FLEET-01 / RF-FLEET-02 / RF-FLEET-03 — Parque de Sedes y Máquinas
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/coordinator/locations
     * 
     * Consulta el catálogo de sedes activas para el coordinador con conteo de máquinas instaladas (RF-FLEET-02).
     */
    public function getLocations(Request $request): Response
    {
        $locations = $this->locationRepo->findAllActive();
        $result = [];

        foreach ($locations as $loc) {
            $machines = $this->machineRepo->findActiveByLocationId($loc->getId());
            $result[] = [
                'id'            => $loc->getId(),
                'site_code'     => $loc->getSiteCode(),
                'name'          => $loc->getName(),
                'address'       => $loc->getAddress(),
                'contact_name'  => $loc->getContactName(),
                'contact_phone' => $loc->getContactPhone(),
                'machine_count' => count($machines),
            ];
        }

        return Response::json($result, 200);
    }

    /**
     * GET /api/coordinator/locations/{id}/machines
     * 
     * Consulta el parque de máquinas instaladas en una sede con su estado operativo y detalles de avería activa (RF-FLEET-03).
     */
    public function getLocationMachines(Request $request): Response
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_LOCATION_ID', 'El identificador de sede (id) debe ser un número entero positivo.', 400);
        }
        $locationId = (int)$rawId;

        $location = $this->locationRepo->findById($locationId);
        if ($location === null) {
            return Response::error('LOCATION_NOT_FOUND', "No se encontró ninguna sede activa con ID {$locationId}.", 404);
        }

        $machines = $this->machineRepo->findActiveByLocationId($locationId);
        $machineList = [];

        foreach ($machines as $mach) {
            $activeIncident = $mach->getActiveIncident();
            $isPerishable = $mach->getMachineType()->value === 'PERISHABLE_FOOD';

            $operationalStatus = 'OPERATIONAL';
            if ($activeIncident !== null) {
                if (isset($activeIncident['status']) && strtoupper((string)$activeIncident['status']) === 'RESOLVED') {
                    $operationalStatus = 'IN_WARRANTY';
                } else {
                    $operationalStatus = 'ACTIVE_INCIDENT';
                }
            }

            $machineList[] = [
                'id'                 => $mach->getId(),
                'location_id'        => $mach->getLocationId(),
                'code'               => $mach->getCode(),
                'model'              => $mach->getModel(),
                'machine_type'       => $mach->getMachineType()->value,
                'floor_wing'         => $mach->getFloorWing(),
                'notes'              => $mach->getNotes(),
                'is_perishable'      => $isPerishable,
                'operational_status' => $operationalStatus,
                'active_incident'    => $activeIncident,
            ];
        }

        return Response::json([
            'location' => [
                'id'            => $location->getId(),
                'site_code'     => $location->getSiteCode(),
                'name'          => $location->getName(),
                'address'       => $location->getAddress(),
                'contact_phone' => $location->getContactPhone(),
            ],
            'machines' => $machineList,
        ], 200);
    }
}

