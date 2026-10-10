<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\IncidentPauseRequestDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Model\Incident;
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
use VendGuard\Infrastructure\Storage\LocalFileUploader;
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

    /**
     * Longitud mínima del motivo de reasignación exigida por RF-07.3 (Art. III.3):
     * 10 caracteres reales contados sobre el texto ya recortado.
     */
    private const MIN_REASSIGNMENT_REASON_LENGTH = 10;

    /**
     * Estados con técnico responsable vigente que admiten cambio de profesional (RF-07.3).
     *
     * `PENDING_INFO` entra aquí por RF-06.3: cuando la sede bloquea el acceso, la avería
     * puede cambiar de manos sin perder la pausa ni el motivo del bloqueo, de modo que el
     * nuevo técnico hereda el contexto («la máquina está en la planta 3 y el conserje abre
     * a las 8:00») en lugar de recibir un expediente descontextualizado.
     */
    private const REASSIGNMENT_STATUSES = [
        IncidentStatus::ASSIGNED,
        IncidentStatus::IN_PROGRESS,
        IncidentStatus::PENDING_PARTS,
        IncidentStatus::PENDING_INFO,
    ];

    private IncidentRepositoryInterface $incidentRepo;
    private UserRepositoryInterface $userRepo;
    private LocationRepositoryInterface $locationRepo;
    private MachineRepositoryInterface $machineRepo;
    private CoordinatorIncidentDetailService $incidentDetailService;
    private AuditLogger $auditLogger;
    private LocalFileUploader $fileUploader;
    private ?IncidentCommentService $commentService;
    private ?IncidentPauseService $pauseService;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?CoordinatorIncidentDetailService $incidentDetailService = null,
        ?AuditLogger $auditLogger = null,
        ?LocalFileUploader $fileUploader = null,
        ?IncidentCommentService $commentService = null,
        ?IncidentPauseService $pauseService = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        // El servicio de detalle se compone sobre el mismo repositorio inyectado para
        // que el endpoint y la lectura agregada compartan una única fuente de datos.
        $this->incidentDetailService = $incidentDetailService ?? new CoordinatorIncidentDetailService($this->incidentRepo);
        $this->auditLogger = $auditLogger ?? new AuditLogger(new PdoAuditLogRepository());

        // El hilo de comentarios se resuelve bajo demanda (ver comments()): el
        // servicio abre conexión a MariaDB al instanciarse y este controlador
        // también se construye en contextos unitarios sin base de datos.
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
        $this->commentService = $commentService;

        // El ciclo de pausa se resuelve bajo demanda (ver pauses()): el servicio abre
        // conexión a MariaDB al instanciarse y este controlador también se construye en
        // contextos unitarios sin base de datos.
        $this->pauseService = $pauseService;
    }

    /**
     * Servicio de aplicación del hilo de comentarios, resuelto bajo demanda.
     */
    private function comments(): IncidentCommentService
    {
        return $this->commentService ??= new IncidentCommentService($this->incidentRepo, null, $this->fileUploader);
    }

    /**
     * Servicio de aplicación del ciclo de pausa por bloqueo de sede (Módulo 11).
     *
     * Se le inyectan el repositorio de incidencias y el de máquinas ya resueltos del
     * controlador, para no abrir una segunda conexión PDO ni resolver dos veces el mismo
     * colaborador (la cancelación por inactividad bloquea la máquina en la misma unidad
     * de trabajo).
     */
    private function pauses(): IncidentPauseService
    {
        return $this->pauseService ??= new IncidentPauseService(
            incidentRepo: $this->incidentRepo,
            machineRepo: $this->machineRepo
        );
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
                // Reloj contractual congelado de la bandeja (RF-03.2, RF-04.2): la fila
                // sabe si el SLA está pausado, cuántos minutos lleva descontados y si la
                // espera de sede ya supera las 72 horas hábiles que encienden la alerta
                // prioritaria. El listado no hidrata agregados: la regla del umbral la
                // publica el servicio del módulo 11 sobre las columnas crudas.
                'is_sla_paused' => $incident->isPaused(),
                'total_pending_info_minutes' => (int)round($incident->getTotalPendingInfoSeconds() / 60),
                'is_prolonged_inactivity' => $incident->isPaused()
                    && $this->pauses()->isProlongedInactivity($incident),
                // Insignia de conversación de la fila de triaje (RF-01.1): el coordinador
                // contabiliza la TOTALIDAD de mensajes, públicos y notas internas de taller,
                // porque su canal tiene acceso legítimo a ambos (RF-02.3).
                'comments_count' => $this->incidentRepo->countComments((int)$incident->getId(), true),
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
     * 
     * Desde RF-07.3 el endpoint atiende también la reasignación en línea que ofrece el
     * modal de detalle: un aviso con responsable vigente (`ASSIGNED`, `IN_PROGRESS` o
     * `PENDING_PARTS`) admite el cambio de profesional siempre que viaje un motivo
     * justificado de al menos 10 caracteres reales —clave canónica
     * `reassignment_reason` o alias `reason` del plan §2.2—, medido con `mb_strlen()`
     * sobre el texto recortado. La incidencia mantiene un único técnico responsable
     * simultáneo (Art. V.3) y el cambio queda en el historial inmutable y en `audit_log`
     * con el evento `INCIDENT_REASSIGNED` (la asignación inicial emite
     * `INCIDENT_ASSIGNED`).
     * 
     * Respuestas: 200 OK con el ticket asignado; 400 si el ID de ruta no es numérico;
     * 422 `MISSING_TECHNICIAN_ID` sin técnico válido; 422 `INVALID_URGENCY` o
     * `URGENCY_REASON_REQUIRED` en la reclasificación de urgencia; 404 si la incidencia
     * no existe; 422 `TECHNICIAN_NOT_FOUND` si el profesional no está activo con rol
     * TECHNICIAN; 422 `INVALID_STATUS_FOR_ASSIGNMENT` si el estado no admite la acción;
     * 422 `MISSING_REASSIGNMENT_REASON` o `REASSIGNMENT_REASON_TOO_SHORT` cuando la
     * reasignación carece de motivo válido; 422 `TECHNICIAN_ALREADY_ASSIGNED` si el
     * destino es el responsable actual; 500 `ASSIGNMENT_FAILED` ante un fallo inesperado.
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

        // 3.b Motivo de la reasignación (RF-07.3): clave canónica `reassignment_reason`
        //     con el alias compacto `reason` publicado en el plan técnico (§2.2).
        $rawReassignmentReason = $body['reassignment_reason'] ?? $body['reason'] ?? null;
        $reassignmentReason = $rawReassignmentReason !== null ? trim((string)$rawReassignmentReason) : '';

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

        // 6. Validar estado actual: asignación inicial desde REGISTERED/REOPENED y
        //    reasignación desde los estados con responsable vigente (EARS 5.1, RF-07.3)
        $isReassignment = in_array($incident->getStatus(), self::REASSIGNMENT_STATUSES, true);
        if (!$isReassignment && !in_array($incident->getStatus(), [IncidentStatus::REGISTERED, IncidentStatus::REOPENED], true)) {
            return Response::error(
                'INVALID_STATUS_FOR_ASSIGNMENT',
                "Solo se pueden asignar incidencias en estado REGISTERED o REOPENED, o reasignar las que están en ASSIGNED, IN_PROGRESS, PENDING_PARTS o PENDING_INFO. Estado actual: {$incident->getStatus()->value}.",
                422
            );
        }

        // 7. La reasignación exige motivo justificado de ≥ 10 caracteres reales y un
        //    destino distinto: la incidencia conserva un único responsable activo
        //    (RF-07.3, Art. V.3) y no se registran eventos de reasignación ficticios (Art. III.3).
        if ($isReassignment) {
            if ($reassignmentReason === '') {
                return Response::error(
                    'MISSING_REASSIGNMENT_REASON',
                    'La reasignación técnica exige un motivo justificado (reassignment_reason obligatorio).',
                    422
                );
            }
            if (mb_strlen($reassignmentReason, 'UTF-8') < self::MIN_REASSIGNMENT_REASON_LENGTH) {
                return Response::error(
                    'REASSIGNMENT_REASON_TOO_SHORT',
                    'El motivo de la reasignación debe contener al menos 10 caracteres reales.',
                    422
                );
            }
            if ($incident->getAssignedTechnicianId() === $technicianId) {
                return Response::error(
                    'TECHNICIAN_ALREADY_ASSIGNED',
                    'El técnico indicado ya es el responsable activo de esta incidencia; seleccione un profesional distinto para reasignar.',
                    422
                );
            }
        }

        // 8. ID del coordinador autenticado (para auditoría)
        $coordinatorId = $request->getAttribute('user_id');

        // 9. Realizar la asignación o reasignación transaccional en el repositorio
        try {
            $assigned = $this->incidentRepo->assign(
                incidentId: $incidentId,
                technicianId: $technicianId,
                coordinatorId: $coordinatorId !== null ? (int)$coordinatorId : null,
                urgencyOverride: ($urgencyOverride !== null && $urgencyOverride !== '') ? $urgencyOverride : null,
                urgencyReason: ($urgencyReason !== null && $urgencyReason !== '') ? $urgencyReason : null,
                reassignmentReason: $isReassignment ? $reassignmentReason : null,
            );
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_ASSIGNMENT', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Response::error('ASSIGNMENT_FAILED', $e->getMessage(), 500);
        }

        // 10. Evento inmutable en `audit_log` con el coordinador autenticado (RNF-04,
        //     plan §2.2): INCIDENT_REASSIGNED cuando cambia el responsable e
        //     INCIDENT_ASSIGNED en la asignación inicial del aviso.
        $actor = $this->extractActor($request);
        $this->auditLogger->logTicketEvent(
            ticketId: $incidentId,
            action: $isReassignment ? 'INCIDENT_REASSIGNED' : 'INCIDENT_ASSIGNED',
            user: $actor,
            previousState: [
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $incident->getAssignedTechnicianId(),
            ],
            newState: $isReassignment
                ? [
                    'status' => $assigned->getStatus()->value,
                    'assigned_technician_id' => $assigned->getAssignedTechnicianId(),
                    'reassignment_reason' => $reassignmentReason,
                ]
                : [
                    'status' => $assigned->getStatus()->value,
                    'assigned_technician_id' => $assigned->getAssignedTechnicianId(),
                ],
            metadata: $isReassignment
                ? [
                    'previous_technician_id' => $incident->getAssignedTechnicianId(),
                    'new_technician_id' => $technicianId,
                ]
                : null
        );

        // 11. Respuesta exitosa con datos esenciales de la incidencia asignada (contrato 4.2)
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
     * Cada descarte ejecutado deja constancia inmutable del evento INCIDENT_CANCELLED
     * en `audit_log` con el coordinador que lo autorizó (RNF-04, Art. III.3, RF-07.4).
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

        // 8. Evento inmutable en `audit_log` con el coordinador autenticado (RNF-04,
        //    Art. III.3, RF-07.4): cierra el ciclo de auditoría del descarte que las
        //    acciones del modal del Módulo 09 ya exigían para asignación y comentarios.
        $actor = $this->extractActor($request);
        $this->auditLogger->logTicketEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_CANCELLED',
            user: $actor,
            previousState: [
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $incident->getAssignedTechnicianId(),
            ],
            newState: [
                'status' => $cancelled->getStatus()->value,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelled->getCancelledAt(),
            ],
            metadata: [
                'ticket_code' => $incident->getTicketCode(),
            ]
        );

        // 9. Respuesta exitosa (contrato 4.3)
        return Response::json([
            'id'           => $cancelled->getId(),
            'status'       => $cancelled->getStatus()->value,
            'cancelled_at' => $cancelled->getCancelledAt(),
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RF-01 / RF-02 / RF-04 / RF-06 — Pausa, reanudación y cancelación por inactividad (Módulo 11: T-PAUSE-13)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/coordinator/incidents/{id}/pause-pending-info
     *
     * Declara la pausa por bloqueo imputable a la sede desde central (RF-01.1 a
     * RF-01.4, plan.md §2.1.B). A diferencia del técnico, el coordinador puede pausar
     * **cualquier expediente activo** (`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS` o
     * `REOPENED`), porque es quien recibe por teléfono el aviso de la sede y conoce el
     * bloqueo antes que la ruta. Exige causa tipificada del catálogo cerrado y
     * justificación de al menos 20 caracteres reales (Art. V.1).
     *
     * Contrato HTTP: `200` pausa declarada; `400` identificador inválido o campo
     * obligatorio ausente; `404` expediente inexistente; `422` causa fuera del catálogo,
     * justificación corta o estado que no admite la pausa (terminal o doble pausa). El
     * expediente terminal responde `422` y no `403`: la regla de asignación única del
     * Art. V.2 no aplica a la coordinación.
     */
    public function pausePendingInfo(Request $request): Response
    {
        // 1. Identificador del expediente de la URL.
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Cuerpo obligatorio: la AUSENCIA de campos es responsabilidad de esta capa
        // (400); los VALORES inválidos los rechaza el DTO con 422 (T-PAUSE-03).
        $body = $request->getParsedBody();
        if (!array_key_exists('reason_category', $body) || !array_key_exists('reason_text', $body)) {
            return Response::error(
                'MISSING_PAUSE_FIELDS',
                'La pausa exige una causa tipificada (reason_category) y un texto explicativo (reason_text) de al menos 20 caracteres.',
                400
            );
        }

        // 3. Existencia del expediente.
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 4. Validación temprana del catálogo de causas y del umbral legal (Art. V.1).
        try {
            $pauseRequest = IncidentPauseRequestDto::fromPayload($body);
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_PAUSE_REQUEST', $e->getMessage(), 422);
        }

        // 5. Identidad del coordinador autenticado (el middleware ya exigió el rol).
        $coordinatorId = $request->getAttribute('user_id');
        if ($coordinatorId === null || !is_numeric($coordinatorId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        // 6. Unidad de trabajo del servicio: transición + rastro inmutable (Art. III).
        try {
            $paused = $this->pauses()->declarePendingInfoPause(
                $incidentId,
                (int)$coordinatorId,
                $pauseRequest->reasonCategory,
                $pauseRequest->normalizedReasonText()
            );
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_PAUSE', $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_PAUSE_REQUEST', $e->getMessage(), 422);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::error('PAUSE_FAILED', $e->getMessage(), 500);
        }

        return Response::json(
            $paused->toArray(),
            200,
            'Incidencia pausada correctamente. Reloj contractual de SLA congelado.'
        );
    }

    /**
     * POST /api/coordinator/incidents/{id}/resume-pending-info
     *
     * Reanuda manualmente una pausa por bloqueo de sede desde central (RF-02.2,
     * RF-02.3, RF-03.3, plan.md §2.2.B). El coordinador **elige el destino**, que es la
     * diferencia real con el técnico in situ: `ASSIGNED` cuando el profesional debe
     * desplazarse de nuevo (valor por defecto) o `IN_PROGRESS` cuando confirma que ya
     * está trabajando en la máquina. En ambos casos el vencimiento contractual se
     * desplaza en horario comercial la duración exacta que estuvo congelado.
     *
     * Cuerpo opcional: `target_status` (`ASSIGNED`/`IN_PROGRESS`) y `resume_note`. Un
     * destino fuera de esos dos valores se rechaza con `422` en lugar de ignorarse.
     */
    public function resumePendingInfo(Request $request): Response
    {
        // 1. Identificador del expediente de la URL.
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Existencia del expediente.
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 3. Destino elegido por coordinación. Por defecto `ASSIGNED`: la respuesta tardía
        //    de la sede obliga al técnico a personarse antes de reanudar el trabajo.
        $body = $request->getParsedBody();
        $rawTarget = $body['target_status'] ?? null;
        $targetStatus = IncidentStatus::ASSIGNED;
        if ($rawTarget !== null) {
            $requestedTarget = is_string($rawTarget)
                ? IncidentStatus::tryFrom(strtoupper(trim($rawTarget)))
                : null;

            if (!in_array($requestedTarget, [IncidentStatus::ASSIGNED, IncidentStatus::IN_PROGRESS], true)) {
                return Response::error(
                    'INVALID_RESUME_TARGET',
                    'El destino de la reanudación debe ser "Asignada" (el técnico debe desplazarse) o "En curso" (ya está en la máquina).',
                    422
                );
            }

            $targetStatus = $requestedTarget;
        }

        $resumeNote = isset($body['resume_note']) && is_string($body['resume_note']) && trim($body['resume_note']) !== ''
            ? trim($body['resume_note'])
            : null;

        // 4. Identidad del coordinador autenticado (el middleware ya exigió el rol).
        $coordinatorId = $request->getAttribute('user_id');
        if ($coordinatorId === null || !is_numeric($coordinatorId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        // 5. Unidad de trabajo del servicio: cierre del intervalo + desplazamiento de SLA.
        try {
            $resumed = $this->pauses()->resumePendingInfoManually(
                $incidentId,
                (int)$coordinatorId,
                $targetStatus,
                $resumeNote
            );
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_RESUME', $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_RESUME_TARGET', $e->getMessage(), 422);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::error('RESUME_FAILED', $e->getMessage(), 500);
        }

        return Response::json(
            $resumed->toArray(),
            200,
            $targetStatus === IncidentStatus::ASSIGNED
                ? 'Intervención reanudada en estado asignada. El técnico debe personarse para iniciar el trabajo.'
                : 'Intervención reanudada en curso. Reloj contractual de SLA reactivado con desplazamiento aplicado.'
        );
    }

    /**
     * POST /api/coordinator/incidents/{id}/cancel-inactivity
     *
     * Cancelación formal supervisada de una avería que lleva más de 72 horas hábiles
     * esperando a una sede que no responde ni facilita el acceso (RF-04.3, plan.md §2.4).
     * El motivo justificado de al menos 20 caracteres reales es obligatorio (Art. V.1).
     *
     * El endpoint no decide el protocolo: lo ejecuta `IncidentPauseService` como UNA sola
     * unidad de trabajo, porque cada escritura por separado deja un estado mentiroso —el
     * ticket a `CANCELLED`, la máquina **fuera de servicio** por falta de acceso (RF-04.4,
     * nunca de vuelta a "Operativa") y los reintegros del consumidor desvinculados y
     * preservados para liquidación central (RF-04.5)—. Aquí sólo se autoriza, se valida el
     * motivo y se publica el resultado real releído de la base de datos.
     *
     * Contrato HTTP: `200` cancelación ejecutada; `400` identificador inválido; `422` motivo
     * ausente, motivo de menos de 20 caracteres reales o expediente que no está en pausa;
     * `404` expediente inexistente; `500` si alguna de las escrituras del protocolo falla y
     * la transacción se deshace entera.
     */
    public function cancelInactivity(Request $request): Response
    {
        // 1. Identificador del expediente de la URL.
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Motivo obligatorio del cierre administrativo (RF-04.3, Art. V.1).
        $body = $request->getParsedBody();
        $reason = isset($body['cancellation_reason']) ? trim((string)$body['cancellation_reason']) : '';
        if ($reason === '') {
            return Response::error(
                'MISSING_CANCELLATION_REASON',
                'La cancelación por inactividad exige un motivo justificado (cancellation_reason obligatorio).',
                422
            );
        }

        // 3. Existencia del expediente.
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 4. Identidad del coordinador autenticado (el middleware ya exigió el rol).
        $coordinatorId = $request->getAttribute('user_id');
        if ($coordinatorId === null || !is_numeric($coordinatorId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        // 5. Protocolo completo como una sola unidad de trabajo (Algoritmo 5).
        try {
            $this->pauses()->cancelByInactivity($incidentId, (int)$coordinatorId, $reason);
        } catch (\VendGuard\Core\Domain\Exception\InvalidTransitionException $e) {
            return Response::error('INVALID_STATUS_FOR_INACTIVITY_CANCELLATION', $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return Response::error('INVALID_CANCELLATION_REASON', $e->getMessage(), 422);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::error('CANCELLATION_FAILED', $e->getMessage(), 500);
        }

        // 6. Evento inmutable del descarte del ticket, con el mismo contrato de auditoría
        //    que el descarte genérico (Art. III.3, RNF-04): el bloqueo de máquina y la
        //    custodia del reintegro ya los sella el servicio dentro de la transacción.
        $this->auditLogger->logTicketEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_CANCELLED',
            user: $this->extractActor($request),
            previousState: [
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $incident->getAssignedTechnicianId(),
            ],
            newState: [
                'status' => IncidentStatus::CANCELLED->value,
                'cancellation_reason' => $reason,
            ],
            metadata: [
                'ticket_code' => $incident->getTicketCode(),
                'cancellation_channel' => 'INACTIVITY_72_BUSINESS_HOURS',
            ]
        );

        // 7. Se publica el estado REAL releído de la base de datos, no una promesa del código.
        $cancelled = $this->incidentRepo->findById($incidentId) ?? $incident;
        $machine = $this->machineRepo->findById($incident->getMachineId());

        return Response::json([
            'id'           => $cancelled->getId(),
            'ticket_code'  => $cancelled->getTicketCode(),
            'status'       => $cancelled->getStatus()->value,
            'cancelled_at' => $cancelled->getCancelledAt(),
            'machine'      => [
                'id'                    => $machine?->getId(),
                'operational_status'    => $machine?->getOperationalStatus(),
                'is_active'             => $machine?->isActive(),
                'is_blocked_no_access'  => $machine?->isBlockedNoAccess(),
            ],
            'refunds_preserved' => true,
        ], 200, 'Avería cancelada por inactividad de sede: la máquina queda fuera de servicio y los reintegros se conservan para liquidación central.');
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

    // ────────────────────────────────────────────────────────────────────────
    // Módulo 10 · T-COM-07 — Hilo de conversación del expediente
    // Orquestado por IncidentCommentService: la segregación, el enmascaramiento
    // y la máquina de estados del sellado viven en la capa de aplicación.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/coordinator/incidents/{id}/comments
     *
     * Devuelve el hilo íntegro del expediente para el Coordinador de Operaciones
     * (RF-01.2, RF-02.3): comentarios públicos y notas internas de taller, con la
     * identidad nominal real de todo el equipo y el candado `is_internal` expuesto.
     *
     * Respuestas: 200 OK con el `IncidentCommentThreadDto`; 400 si el identificador
     * o el cursor de paginación no son válidos; 401/403 si la petición no procede de
     * un coordinador autenticado; 404 si el ticket no existe o está borrado.
     */
    public function getComments(Request $request): Response
    {
        // 1. Control de acceso antes de leer nada (Art. V.4)
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

        // 3. Cursor de paginación cursorizada (RF-01.2, RF-01.3)
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

        // 4. Inspección total del diálogo con identidad nominal real (RF-02.3)
        try {
            $thread = $this->comments()->getThread(
                $identifier,
                'COORDINATOR',
                (int)$request->getAttribute('user_id'),
                $limit,
                $beforeId
            );
        } catch (ConversationSealedException $e) {
            // Barrera defensiva: la consulta agregada nunca debe exponer notas internas
            // de un expediente ajeno al llamante.
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (\DomainException $e) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        return Response::json($thread->jsonSerialize(), 200);
    }

    /**
     * POST /api/coordinator/incidents/{id}/comments
     *
     * Publica un comentario público o una nota interna de taller en el hilo del
     * expediente desde el modal de triaje (RF-03.3, RF-05.3, RNF-04).
     *
     * Garantías de servidor:
     * - El selector de privacidad se interpreta con fail-safe: si `is_internal` no
     *   viaja en el cuerpo, la nota se clasifica como interna de taller (RF-03.3)
     *   para no filtrar material técnico al cliente por un campo ausente.
     * - La máquina de estados del sellado la aplica el servicio de aplicación: los
     *   tickets activos y los resueltos dentro de la garantía de 48 h admiten
     *   mensajes; CLOSED/CANCELLED y la garantía vencida responden 403 (Art. V.6).
     * - La fotografía adjunta se valida en servidor (≤ 5 MB y magic bytes reales)
     *   antes de persistir nada (Art. V.5).
     * - Cada escritura deja constancia inmutable del evento INCIDENT_COMMENT_ADDED
     *   en `audit_log` con el coordinador autenticado (Art. III.3).
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

        // 3. Texto obligatorio: solo se comprueba su presencia (400); los límites de
        //    5 a 1.000 caracteres descriptivos los aplica el servicio (RF-03.1).
        $commentText = trim((string)($request->getBodyParam('comment_text')
            ?? $request->getBodyParam('text')
            ?? $request->getBodyParam('comment')
            ?? ''));
        if ($commentText === '') {
            return Response::error(
                'MISSING_COMMENT_TEXT',
                'El texto del comentario es obligatorio (comment_text).',
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

        // 5. Evidencia fotográfica opcional (multipart/form-data, RF-04.1)
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        // 6. Publicación orquestada por el servicio de aplicación
        $actor = $this->extractActor($request);
        try {
            $thread = $this->comments()->addComment(
                $identifier,
                'COORDINATOR',
                $commentText,
                $actor,
                $isInternal,
                $photoFile
            );
        } catch (InvalidCommentLengthException $e) {
            // RF-07.1: se devuelven los datos de texto íntegros para el reintento.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['form_data' => ['comment_text' => $commentText]]
            );
        } catch (ConversationSealedException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['form_data' => ['comment_text' => $commentText, 'is_internal' => $isInternal]]
            );
        } catch (InvalidUploadException $e) {
            // RF-07.1: texto y clasificación íntegros para el reintento manual.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'form_data' => [
                        'comment_text' => $commentText,
                        'is_internal' => $isInternal,
                    ],
                ]
            );
        } catch (\DomainException $e) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        // 7. Respuesta 201 Created con el hilo actualizado del expediente (RF-03.4)
        return Response::json(
            $thread->jsonSerialize(),
            201,
            $isInternal
                ? 'Nota interna registrada en el hilo de conversación'
                : 'Comentario publicado en el hilo de conversación'
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

