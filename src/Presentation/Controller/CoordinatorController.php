<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
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
    private IncidentRepositoryInterface $incidentRepo;
    private UserRepositoryInterface $userRepo;
    private LocationRepositoryInterface $locationRepo;
    private MachineRepositoryInterface $machineRepo;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?MachineRepositoryInterface $machineRepo = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
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
     * Exige obligatoriamente un motivo de descarte.
     * Mantiene íntegra la fila en base de datos (Soft Delete / trazabilidad).
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

        // 3. Verificar que la incidencia exista
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        // 4. Validar que el estado actual admita transición a CANCELLED
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::CANCELLED)) {
            return Response::error(
                'INVALID_STATUS_FOR_CANCELLATION',
                "No se puede cancelar una incidencia en estado {$incident->getStatus()->value}.",
                422
            );
        }

        // 5. ID del coordinador autenticado (para auditoría)
        $coordinatorId = $request->getAttribute('user_id');

        // 6. Ejecutar cancelación lógica en el repositorio
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

        // 7. Respuesta exitosa (contrato 4.3)
        return Response::json([
            'id'           => $cancelled->getId(),
            'status'       => $cancelled->getStatus()->value,
            'cancelled_at' => $cancelled->getCancelledAt(),
        ], 200);
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

