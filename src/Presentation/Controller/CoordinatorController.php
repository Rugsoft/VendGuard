<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\IncidentPauseRequestDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Application\Service\SuggestedTechniciansService;
use VendGuard\Application\Service\VanStockReadinessCalculator;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\InactivityThresholdNotReachedException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentServiceSheetRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VanStockRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\ColdChainOverridePolicy;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentServiceSheetRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;
use VendGuard\Infrastructure\Storage\FileStorageCompensator;
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
    private ?NotificationOutboxEnqueuerService $notificationEnqueuer;
    private ?TransactionManagerInterface $transactionManager;

    /** Transaccionalidad compensatoria del fichero (EARS-05.3); se resuelve bajo demanda. */
    private ?FileStorageCompensator $fileCompensator;

    /** Recomendador de asignación (Módulo 17); se resuelve bajo demanda para no abrir conexión al construir. */
    private ?SuggestedTechniciansService $suggestionService;

    /** Política del Art. V.1: cuándo el descarte sanitario exige justificación. */
    private ?ColdChainOverridePolicy $coldChainPolicy;

    /** Hojas de servicio (Módulo 19); se resuelve bajo demanda para no abrir conexión al construir. */
    private ?IncidentServiceSheetRepositoryInterface $serviceSheetRepo = null;

    /** Solicitudes `PENDING` por avería para la insignia `van_stock_readiness` (Módulo 23). */
    private ?SparePartRequestRepositoryInterface $sparePartRequestRepo = null;

    /** Saldos de furgoneta para la insignia `van_stock_readiness` (Módulo 23). */
    private ?VanStockRepositoryInterface $vanStockRepo = null;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?CoordinatorIncidentDetailService $incidentDetailService = null,
        ?AuditLogger $auditLogger = null,
        ?LocalFileUploader $fileUploader = null,
        ?IncidentCommentService $commentService = null,
        ?IncidentPauseService $pauseService = null,
        ?FileStorageCompensator $fileCompensator = null,
        ?NotificationOutboxEnqueuerService $notificationEnqueuer = null,
        ?TransactionManagerInterface $transactionManager = null,
        ?SuggestedTechniciansService $suggestionService = null,
        ?ColdChainOverridePolicy $coldChainPolicy = null,
        ?IncidentServiceSheetRepositoryInterface $serviceSheetRepo = null,
        ?SparePartRequestRepositoryInterface $sparePartRequestRepo = null,
        ?VanStockRepositoryInterface $vanStockRepo = null
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
        $this->fileCompensator = $fileCompensator;
        $this->commentService = $commentService;

        // El ciclo de pausa se resuelve bajo demanda (ver pauses()): el servicio abre
        // conexión a MariaDB al instanciarse y este controlador también se construye en
        // contextos unitarios sin base de datos.
        $this->pauseService = $pauseService;
        $this->notificationEnqueuer = $notificationEnqueuer;
        $this->transactionManager = $transactionManager;
        $this->suggestionService = $suggestionService;
        $this->coldChainPolicy = $coldChainPolicy;
        $this->serviceSheetRepo = $serviceSheetRepo;
        $this->sparePartRequestRepo = $sparePartRequestRepo;
        $this->vanStockRepo = $vanStockRepo;
    }

    /**
     * Hojas de servicio de la avería (Módulo 19), resuelto bajo demanda.
     *
     * Comparte el repositorio con el cierre firmado para que la vigencia de una hoja
     * tenga un único dueño: la comparación entre `resolution_at` y `resolved_at` vive
     * en la consulta, no en cada controlador (contratos §6.2).
     */
    private function serviceSheets(): IncidentServiceSheetRepositoryInterface
    {
        return $this->serviceSheetRepo ??= new PdoIncidentServiceSheetRepository();
    }

    /**
     * Recomendador de asignación, resuelto bajo demanda (abre conexión al instanciarse).
     *
     * El mismo objeto sirve al panel de triaje y a la puerta constitucional de la
     * asignación: si cada uno compusiera su propio ranking, panel y salvaguarda podrían
     * discrepar sobre quién es el técnico de guardia sugerido.
     */
    private function suggestions(): SuggestedTechniciansService
    {
        return $this->suggestionService ??= new SuggestedTechniciansService();
    }

    private function coldChainPolicy(): ColdChainOverridePolicy
    {
        return $this->coldChainPolicy ??= new ColdChainOverridePolicy();
    }

    private function notifications(): ?NotificationOutboxEnqueuerService
    {
        return $this->notificationEnqueuer ??= NotificationOutboxEnqueuerFactory::createOrNull();
    }

    private function transactions(): TransactionManagerInterface
    {
        return $this->transactionManager ??= new PdoTransactionManager();
    }

    /**
     * Servicio de aplicación del hilo de comentarios, resuelto bajo demanda.
     *
     * La nota interna con fotografía la publica el servicio, que es el punto único de las
     * rutas de comentario de los tres perfiles; el controlador le entrega el MISMO subidor y
     * el MISMO compensador que posee, de modo que la compensación del fichero (EARS-05.3)
     * tiene una sola implementación en toda la ruta y nadie reimplementa el borrado.
     */
    private function comments(): IncidentCommentService
    {
        return $this->commentService ??= new IncidentCommentService(
            $this->incidentRepo,
            null,
            $this->fileUploader,
            $this->compensator(),
            $this->notifications()
        );
    }

    /**
     * Compensador del fichero de evidencia, resuelto bajo demanda y compuesto sobre el
     * mismo subidor que escribe las fotografías de este controlador (ver
     * `FileStorageCompensator`).
     */
    private function compensator(): FileStorageCompensator
    {
        return $this->fileCompensator ??= new FileStorageCompensator($this->fileUploader);
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
            machineRepo: $this->machineRepo,
            notificationEnqueuer: $this->notifications()
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

        // 6.b Recuento agregado de las conversaciones de TODA la bandeja en una sola
        //     consulta (RF-01.1): el recuento por fila costaba una ida y vuelta por
        //     expediente —patrón N+1, medido en T-HYBRID-27 como la causa de que el
        //     listado fuera la peor de las operaciones contra el motor remoto—. El mapa
        //     se resuelve una vez y la fila publica 0 cuando el expediente no tiene hilo.
        $incidentIds = [];
        foreach ($incidents as $incident) {
            $id = $incident->getId();
            if ($id !== null) {
                $incidentIds[] = $id;
            }
        }

        $commentCounts = $this->incidentRepo->countCommentsForIncidents($incidentIds, true);

        // 6.c Insignia de disponibilidad de repuesto en la tarjeta (Módulo 23, RF-05.4,
        //     QA 3). Se calcula una vez para toda la bandeja con el calculador puro: no
        //     escribe nada ni cambia el estado de la avería (Art. III.3).
        $vanStockReadiness = $this->vanStockReadinessByIncident($incidents);

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
                // porque su canal tiene acceso legítimo a ambos (RF-02.3). El número sale
                // del agregado resuelto una sola vez para la bandeja entera (paso 6.b);
                // sin hilo, la fila publica 0 y nunca un valor ausente.
                'comments_count' => $commentCounts[(int)$incident->getId()] ?? 0,
                // Cobertura de repuestos de la furgoneta del técnico asignado
                // (Módulo 23, RF-05.4, QA 3). Indicador 100 % informativo: no altera
                // el ranking de triaje ni la prioridad sanitaria (Art. II).
                'van_stock_readiness' => $vanStockReadiness[(int)$incident->getId()]
                    ?? $this->notApplicableVanStockReadiness(),
            ]);

            $formattedIncidents[] = $item;
        }

        return Response::json($formattedIncidents, 200);
    }

    /**
     * Insignia `van_stock_readiness` de cada avería de la bandeja (Módulo 23).
     *
     * El calculador es puro: recibe las averías, las solicitudes `PENDING` por
     * avería y los saldos de furgoneta, y devuelve el descriptor de cada tarjeta
     * (`READY_IN_VAN`, `SHARED_IN_VAN`, `NOT_READY`, `HAS_OUT_OF_CATALOG` o
     * `NOT_APPLICABLE`). No escribe, no cambia estados ni reactiva solicitudes
     * (C5, Art. III.3).
     *
     * La lectura de soporte solo se ejecuta con el adaptador vivo o cuando la
     * suite inyecta ambos colaboradores. En una suite hermética sin base de datos
     * las tarjetas publican `NOT_APPLICABLE`: una insignia informativa nunca debe
     * tumbar la bandeja de triaje.
     *
     * @param list<Incident> $incidents
     * @return array<int, array{
     *   status: string,
     *   badge_label: string|null,
     *   covered_references: int,
     *   total_catalog_references: int,
     *   has_out_of_catalog_requests: bool
     * }>
     */
    private function vanStockReadinessByIncident(array $incidents): array
    {
        if ($incidents === []) {
            return [];
        }

        $rows = [];
        foreach ($incidents as $incident) {
            $rows[] = [
                'incident_id' => (int)$incident->getId(),
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $incident->getAssignedTechnicianId(),
            ];
        }

        $pendingRequestsByIncident = [];
        $vanStockByTechnicianAndPart = [];

        if ($this->hasVanStockReadinessSources()) {
            foreach ($rows as $row) {
                if (
                    $row['assigned_technician_id'] === null
                    || !in_array($row['status'], VanStockReadinessCalculator::ACTIVE_INCIDENT_STATUSES, true)
                ) {
                    continue;
                }

                foreach ($this->sparePartRequests()->findByIncidentId($row['incident_id']) as $request) {
                    if ($request->getStatus() !== SparePartRequestStatus::PENDING) {
                        continue;
                    }

                    $sparePartId = $request->getSparePartId();
                    if ($sparePartId === null) {
                        $pendingRequestsByIncident[$row['incident_id']]['out_of_catalog'] =
                            ($pendingRequestsByIncident[$row['incident_id']]['out_of_catalog'] ?? 0) + 1;
                        continue;
                    }

                    $pendingRequestsByIncident[$row['incident_id']]['catalog'][$sparePartId] =
                        ($pendingRequestsByIncident[$row['incident_id']]['catalog'][$sparePartId] ?? 0)
                        + $request->getQuantity();
                }
            }

            foreach ($this->vanStock()->listInventory(null) as $item) {
                $vanStockByTechnicianAndPart[$item->technicianId][$item->sparePartId] = $item->quantityAvailable;
            }
        }

        return (new VanStockReadinessCalculator())->calculate(
            $rows,
            $pendingRequestsByIncident,
            $vanStockByTechnicianAndPart
        );
    }

    /**
     * ¿Hay una fuente de datos para la insignia? El adaptador PDO de expedientes
     * implica el motor vivo; un doble de prueba sólo enciende la lectura si la
     * suite inyecta expresamente los dos repositorios de apoyo.
     */
    private function hasVanStockReadinessSources(): bool
    {
        return ($this->sparePartRequestRepo !== null && $this->vanStockRepo !== null)
            || $this->incidentRepo instanceof PdoIncidentRepository;
    }

    private function sparePartRequests(): SparePartRequestRepositoryInterface
    {
        return $this->sparePartRequestRepo ??= new PdoSparePartRequestRepository();
    }

    private function vanStock(): VanStockRepositoryInterface
    {
        return $this->vanStockRepo ??= new PdoVanStockRepository();
    }

    /**
     * Descriptor neutro para una avería sin datos de cobertura.
     *
     * @return array{
     *   status: string,
     *   badge_label: null,
     *   covered_references: int,
     *   total_catalog_references: int,
     *   has_out_of_catalog_requests: bool
     * }
     */
    private function notApplicableVanStockReadiness(): array
    {
        return [
            'status' => VanStockReadinessCalculator::STATUS_NOT_APPLICABLE,
            'badge_label' => null,
            'covered_references' => 0,
            'total_catalog_references' => 0,
            'has_out_of_catalog_requests' => false,
        ];
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
     * destino es el responsable actual; 422 `COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED`
     * si una avería de frío descarta al técnico de guardia sugerido sin justificación de
     * 20 caracteres reales (Art. II y Art. V.1); 500 `ASSIGNMENT_FAILED` ante un fallo
     * inesperado.
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

        // 7.bis Puerta constitucional de la cadena de frío (Art. II y Art. V.1, RF-05.2,
        //        Resolución QA 8). Se compone el ranking sólo cuando la avería puede ser
        //        sanitaria: un despacho ordinario no paga la lectura agregada y el camino
        //        de asignación sigue funcionando sin recomendador.
        $overrideJustification = null;
        $overriddenSuggestionId = null;
        if ($this->isPotentialColdChainRisk($incident)) {
            $suggestions = $this->suggestions()->getSuggestions($incidentId);
            if ($suggestions !== null) {
                $suggestionPayload = $suggestions->toArray();
                if ($this->coldChainPolicy()->requiresOverride($suggestionPayload, $technicianId)) {
                    $rawJustification = $body['override_justification'] ?? null;
                    if (!$this->coldChainPolicy()->isValidJustification($rawJustification)) {
                        return Response::error(
                            'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED',
                            'La avería presenta riesgo crítico de frío (Art. II). Si descarta al técnico de guardia sugerido, debe aportar una justificación obligatoria de al menos 20 caracteres.',
                            422
                        );
                    }
                    $overrideJustification = $this->coldChainPolicy()->normalize($rawJustification);
                    $overriddenSuggestionId = $suggestionPayload['recommendation']['top_technician_id'] ?? null;
                }
            }
        }

        // 8. ID del coordinador autenticado (para auditoría)
        $coordinatorId = $request->getAttribute('user_id');

        // 9. Realizar la asignación o reasignación transaccional en el repositorio.
        //    RF-05.2 / RF-03.1: los avisos de entrada/salida y la posible reclasificación
        //    crítica a frío se encolan en la MISMA transacción que el cambio de técnico.
        try {
            $enqueuer = $this->notifications();
            $assign = fn (): Incident => $this->incidentRepo->assign(
                incidentId: $incident,
                technicianId: $technicianId,
                coordinatorId: $coordinatorId !== null ? (int)$coordinatorId : null,
                urgencyOverride: ($urgencyOverride !== null && $urgencyOverride !== '') ? $urgencyOverride : null,
                urgencyReason: ($urgencyReason !== null && $urgencyReason !== '') ? $urgencyReason : null,
                reassignmentReason: $isReassignment ? $reassignmentReason : null,
            );

            $assigned = $enqueuer === null
                ? $assign()
                : $this->transactions()->runInTransaction(function () use ($assign, $enqueuer, $incident): Incident {
                    $assigned = $assign();
                    $enqueuer->enqueueAssignment($incident, $assigned);

                    return $assigned;
                });
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

        // 10.bis El descarte de la recomendación sanitaria deja su propio rastro inmutable
        //        (Art. III.3, contrato §5.1): quién se sugería, a quién se envió y la
        //        justificación del coordinador. Es un evento distinto del cambio de
        //        responsable a propósito: `INCIDENT_ASSIGNED`/`INCIDENT_REASSIGNED` ya
        //        existe y el visor debe poder filtrar la salvaguarda del Art. V.1.
        if ($overrideJustification !== null) {
            $this->auditLogger->logTicketEvent(
                ticketId: $incidentId,
                action: 'INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN',
                user: $actor,
                previousState: [
                    'suggested_technician_id' => $overriddenSuggestionId,
                    'is_cold_chain_escalation' => true,
                ],
                newState: [
                    'status' => $assigned->getStatus()->value,
                    'assigned_technician_id' => $assigned->getAssignedTechnicianId(),
                    'override_justification' => $overrideJustification,
                ],
                metadata: [
                    'suggested_technician_id' => $overriddenSuggestionId,
                    'new_technician_id' => $technicianId,
                ]
            );
        }

        // 11. Respuesta exitosa con datos esenciales de la incidencia asignada (contrato 4.2)
        return Response::json([
            'id'                     => $assigned->getId(),
            'status'                 => $assigned->getStatus()->value,
            'assigned_technician_id' => $assigned->getAssignedTechnicianId(),
            'assigned_at'            => $assigned->getAssignedAt(),
        ], 200);
    }

    /**
     * GET /api/coordinator/incidents/{id}/suggested-technicians
     *
     * Ranking explicado de técnicos para el despacho asistido (contrato §3.6, RF-04.1,
     * RF-05.1, RNF-01). Publica la avería con su zona, la recomendación con su
     * explicación y el desglose exacto de la puntuación de cada candidato, de modo que
     * el panel de triaje no recalcule nada y el orden que ve el coordinador sea el que
     * la puerta constitucional de la asignación evalúa.
     *
     * El identificador admite el ID primario o el código de ticket, igual que la ficha
     * integral: el panel puede pedir la sugerencia con el mismo dato que ya tiene.
     *
     * Respuestas: 200 OK con los tres bloques del contrato; 400 si el identificador no
     * es válido; 401/403 si la petición no procede de un coordinador autenticado (defensa
     * en profundidad además del middleware); 404 si el expediente no existe.
     */
    public function getSuggestedTechnicians(Request $request): Response
    {
        $authFailure = $this->authorizeCoordinator($request);
        if ($authFailure !== null) {
            return $authFailure;
        }

        $rawIdentifier = trim((string)($request->getRouteParam('id') ?? ''));
        $identifier = $this->resolveIncidentIdentifier($rawIdentifier);
        if ($identifier === null) {
            return Response::error(
                'INVALID_INCIDENT_IDENTIFIER',
                'El identificador de la incidencia no es válido. Se admite un ID numérico positivo o un código de ticket (ej: INC-2026-0001).',
                400
            );
        }

        // El recomendador trabaja con el ID primario: un código de ticket se resuelve
        // aquí y no en el repositorio de lectura, que no necesita conocer dos claves.
        $incidentId = is_int($identifier) ? $identifier : null;
        if ($incidentId === null) {
            $incident = $this->incidentRepo->findByTicketCode($identifier);
            $incidentId = $incident?->getId();
        }

        $suggestions = $incidentId !== null ? $this->suggestions()->getSuggestions($incidentId) : null;
        if ($suggestions === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        return Response::json($suggestions->toArray(), 200);
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

        // 7. Ejecutar cancelación lógica en el repositorio. RF-05.8: el aviso de
        //    cancelación (con la advertencia de bloqueo si procede) se encola en la
        //    misma transacción que la transición a CANCELLED.
        try {
            $enqueuer = $this->notifications();
            $cancel = fn (): Incident => $this->incidentRepo->cancel(
                incidentId: $incidentId,
                cancellationReason: $reason,
                coordinatorId: $coordinatorId !== null ? (int)$coordinatorId : null
            );

            $cancelled = $enqueuer === null
                ? $cancel()
                : $this->transactions()->runInTransaction(function () use ($cancel, $enqueuer): Incident {
                    $cancelled = $cancel();
                    $enqueuer->enqueueCancellation($cancelled);

                    return $cancelled;
                });
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
     * Cuerpo opcional: `target_status` (`ASSIGNED`/`IN_PROGRESS`/`REGISTERED`) y
     * `resume_note`. Un destino fuera de esos tres valores se rechaza con `422` en lugar
     * de ignorarse. `REGISTERED` sólo es legal para expedientes sin técnico responsable
     * (cierre de F-5, §6.4): devuelve la avería a la cola de asignación; con técnico, el
     * agregado rechaza el destino y sin técnico rechaza `ASSIGNED`/`IN_PROGRESS`.
     */
    public function resumePendingInfo(Request $request): Response
    {
        // 1. Identificador del expediente de la URL.
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Existencia del expediente se valida dentro de la transacción de la reanudación (T-HYBRID-27)
        // para ahorrar una ida y vuelta redundante contra el motor remoto.

        // 3. Destino elegido por coordinación. Por defecto `ASSIGNED`: la respuesta tardía
        //    de la sede obliga al técnico a personarse antes de reanudar el trabajo. En un
        //    expediente sin técnico responsable el agregado rechaza `ASSIGNED`/`IN_PROGRESS`
        //    y sólo admite `REGISTERED` (cierre de F-5, §6.4), de modo que el llamante
        //    explícito conserva la verdad del estado.
        $body = $request->getParsedBody();
        $rawTarget = $body['target_status'] ?? null;
        $targetStatus = IncidentStatus::ASSIGNED;
        if ($rawTarget !== null) {
            $requestedTarget = is_string($rawTarget)
                ? IncidentStatus::tryFrom(strtoupper(trim($rawTarget)))
                : null;

            if (!in_array($requestedTarget, [IncidentStatus::ASSIGNED, IncidentStatus::IN_PROGRESS, IncidentStatus::REGISTERED], true)) {
                return Response::error(
                    'INVALID_RESUME_TARGET',
                    'El destino de la reanudación debe ser "Asignada" (el técnico debe desplazarse), "En curso" (ya está en la máquina) o "Registrada" (la avería no tiene técnico responsable y vuelve a la cola de asignación).',
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
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'No se encontró ninguna incidencia')) {
                return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
            }
            return Response::error('RESUME_FAILED', $e->getMessage(), 500);
        } catch (\RuntimeException $e) {
            return Response::error('RESUME_FAILED', $e->getMessage(), 500);
        }

        return Response::json(
            $resumed->toArray(),
            200,
            match ($targetStatus) {
                IncidentStatus::REGISTERED => 'Intervención reanudada en estado registrada. La avería vuelve a la cola de asignación.',
                IncidentStatus::IN_PROGRESS => 'Intervención reanudada en curso. Reloj contractual de SLA reactivado con desplazamiento aplicado.',
                default => 'Intervención reanudada en estado asignada. El técnico debe personarse para iniciar el trabajo.',
            }
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
     * **Precondición del umbral (RF-04.3).** El protocolo sólo se admite con más de 72
     * horas hábiles de espera, medidas en horario comercial de la sede con la misma regla
     * que enciende la insignia del triaje. El rechazo (`422
     * INACTIVITY_THRESHOLD_NOT_REACHED`) lo lanza el servicio ANTES de la primera
     * escritura y viaja con la espera medida, de modo que la máquina no queda fuera de
     * servicio y la bitácora no sella un silencio de tres jornadas que no ocurrió.
     *
     * Contrato HTTP: `200` cancelación ejecutada; `400` identificador inválido; `422` motivo
     * ausente, motivo de menos de 20 caracteres reales, expediente que no está en pausa o
     * espera que no supera el umbral; `404` expediente inexistente; `500` si alguna de las
     * escrituras del protocolo falla y la transacción se deshace entera.
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

        // 5. Protocolo completo como una sola unidad de trabajo (Algoritmo 5). El servicio
        //    devuelve la espera hábil MEDIDA, que es la cifra que firma la auditoría.
        try {
            $pausedBusinessSeconds = $this->pauses()->cancelByInactivity($incidentId, (int)$coordinatorId, $reason);
        } catch (InactivityThresholdNotReachedException $e) {
            // La salvaguarda mordió: nada se ha escrito (ni ticket, ni máquina, ni
            // reintegro, ni bitácora) y el cliente recibe la espera real para explicarla.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
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
                // El canal deja de ser una afirmación: viaja con la espera que el servicio
                // midió en horas hábiles para autorizar el cierre (Art. III, RNF-05).
                'cancellation_channel' => 'INACTIVITY_72_BUSINESS_HOURS',
                'inactivity_wait_business_hours' => round($pausedBusinessSeconds / 3600, 2),
                'inactivity_threshold_business_hours' => IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS,
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
    // Módulo 19 · T-SIGN-05 — Albarán de servicio (lectura)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/coordinator/incidents/{id}/service-sheet
     *
     * Entrega el albarán de servicio de cualquier avería (RF-05). La coordinación
     * supervisa el parque completo, así que aquí no rige la regla del técnico (RD-17)
     * sino el rol que exige el middleware de la ruta.
     *
     * La lectura se audita igual que la del técnico (RF-05.6): el coordinador también
     * accede a datos personales de un tercero. El identificador admite el ID primario y
     * el código de ticket, como el resto de rutas del expediente.
     *
     * Respuestas: 200 con el modelo común (§4.4); 400 `INVALID_INCIDENT_IDENTIFIER` o
     * `INVALID_SHEET_ID`; 401/403 sin rol de coordinación; 404 `INCIDENT_NOT_FOUND` o
     * `SERVICE_SHEET_NOT_FOUND`; 422 `INCIDENT_NOT_RESOLVED`.
     */
    public function serviceSheet(Request $request): Response
    {
        // 1. Control de acceso antes de leer nada (Art. V.4).
        $authFailure = $this->authorizeCoordinator($request);
        if ($authFailure !== null) {
            return $authFailure;
        }

        // 2. Identificador de ruta: ID primario positivo o código de ticket.
        $rawIdentifier = trim((string)($request->getRouteParam('id') ?? ''));
        $identifier = $this->resolveIncidentIdentifier($rawIdentifier);
        if ($identifier === null) {
            return Response::error(
                'INVALID_INCIDENT_IDENTIFIER',
                'El identificador de la incidencia no es válido. Se admite un ID numérico positivo o un código de ticket (ej: INC-2026-0001).',
                400
            );
        }

        $incident = is_int($identifier)
            ? $this->incidentRepo->findById($identifier)
            : $this->incidentRepo->findByTicketCode(strtoupper(ltrim((string)$identifier, '#')));
        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con identificador '{$rawIdentifier}'.",
                404
            );
        }

        return $this->serviceSheetResponse($request, (int)$incident->getId(), $this->extractActor($request));
    }

    /**
     * Compone la respuesta del albarán: hoja pedida, hoja vigente o proyección viva (§4.4).
     *
     * @param array{id: int|null, role: string, name: string} $actor
     */
    private function serviceSheetResponse(Request $request, int $incidentId, array $actor): Response
    {
        $rawSheetId = $request->getQuery('sheet_id');
        $sheetId = null;
        if ($rawSheetId !== null) {
            if (!ctype_digit((string)$rawSheetId) || (int)$rawSheetId < 1) {
                return Response::error('INVALID_SHEET_ID', 'El parámetro sheet_id debe ser un entero positivo.', 400);
            }
            $sheetId = (int)$rawSheetId;
        }

        $document = $this->serviceSheets()->findDocumentByIncidentId($incidentId, $sheetId);
        if ($document === null) {
            return Response::error('INCIDENT_NOT_FOUND', 'No se encontró la avería solicitada.', 404);
        }

        if ($sheetId !== null && $document['sheet'] === null) {
            return Response::error(
                'SERVICE_SHEET_NOT_FOUND',
                'La hoja de servicio solicitada no pertenece a esta avería.',
                404
            );
        }

        if ($document['sheet'] === null
            && !in_array($document['status'], ['RESOLVED', 'CLOSED', 'REOPENED'], true)
        ) {
            return Response::error(
                'INCIDENT_NOT_RESOLVED',
                'La avería no se ha cerrado nunca: todavía no hay albarán que emitir.',
                422
            );
        }

        $this->auditLogger->logTicketEvent(
            $incidentId,
            'VIEW_SERVICE_SHEET',
            $actor,
            null,
            ['sheet_id' => $document['sheet']['id'] ?? null, 'current' => $document['current']]
        );

        return Response::json($document, 200);
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
    /**
     * ¿La avería puede activar la vigilancia de cadena de frío del Art. II?
     *
     * Guarda **barata** (sin consultas): el ranking agregado sólo se compone cuando la
     * respuesta puede ser «sí». La derivación es la misma que usan el recomendador y la
     * política del Art. V.1, no una segunda regla escrita aquí.
     */
    private function isPotentialColdChainRisk(Incident $incident): bool
    {
        return ColdChainOverridePolicy::deriveColdChainRisk(
            $incident->getUrgency()->value,
            $incident->getMachineType()
        );
    }

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

