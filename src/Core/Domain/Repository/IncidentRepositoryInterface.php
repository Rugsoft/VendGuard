<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;

/**
 * IncidentRepositoryInterface
 * 
 * Contrato de repositorio de dominio para la persistencia y consulta
 * del agregado Incident y su historial de auditoría inmutable.
 */
interface IncidentRepositoryInterface
{
    /**
     * Inserta una nueva incidencia e inserta atómicamente su primer registro en `incident_history`.
     *
     * @param Incident $incident Entidad con los datos del nuevo aviso.
     * @param int|null $userId Usuario del sistema que crea el registro (null si es el informador de sede).
     * @param string|null $initialNote Nota descriptiva para el primer evento de auditoría.
     * @return Incident Entidad persistida con su ID autoincremental asignado.
     */
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident;

    /**
     * Recupera una incidencia activa por su identificador numérico primario.
     */
    public function findById(int $id): ?Incident;

    /**
     * Recupera una incidencia por su código único de ticket (ej: INC-2026-0001).
     */
    public function findByTicketCode(string $ticketCode): ?Incident;

    /**
     * Recupera el ticket activo para una máquina dispensadora concreta (si existe).
     */
    public function findActiveByMachineId(int $machineId): ?Incident;

    /**
     * Recupera la incidencia activa o más reciente en estado resuelta (para control de garantías).
     */
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident;

    /**
     * Recupera todas las incidencias de una sede concreta.
     *
     * @param int $locationId Identificador de la sede.
     * @param bool $activeOnly Si es true, filtra únicamente las no terminales.
     * @return list<Incident>
     */
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array;

    /**
     * Recupera el listado global de incidencias con opciones de filtrado.
     *
     * @param array<string, mixed> $filters Filtros (status, urgency, location_id, assigned_technician_id, active_only).
     * @return list<Incident>
     */
    public function findAll(array $filters = []): array;

    /**
     * Recupera las incidencias asignadas a la ruta de un técnico concreto.
     *
     * @param int $technicianId Identificador del técnico de ruta.
     * @param list<string> $statuses Estados a incluir (ej: ['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']).
     * @return list<Incident>
     */
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array;

    /**
     * Actualiza los campos mutables y el estado de una incidencia.
     */
    public function update(Incident $incident): bool;

    /**
     * Aplica borrado lógico (Soft Delete) sobre la incidencia respetando el Artículo III.
     */
    public function softDelete(int $id): bool;

    /**
     * Inserta un registro en la tabla de auditoría inmutable incident_history.
     */
    public function insertHistory(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null
    ): int;

    /**
     * Obtiene la cronología completa de transiciones de una incidencia.
     *
     * @return list<IncidentHistory>
     */
    public function getHistory(int $incidentId): array;

    /**
     * Añade un nuevo comentario o evidencia fotográfica a la bitácora de la incidencia (RF-02 / EARS 2.3).
     */
    public function addComment(IncidentComment $comment): IncidentComment;

    /**
     * Recupera los comentarios asociados a una incidencia.
     *
     * @param int $incidentId
     * @param bool $includeInternal Si es false, excluye comentarios internos (RNF-04).
     * @return list<IncidentComment>
     */
    public function getComments(int $incidentId, bool $includeInternal = true): array;

    /**
     * Cuenta el número de eventos de reapertura previos registrados en la auditoría (RF-09 / EARS 9.3).
     */
    public function countReopenEvents(int $incidentId): int;

    /**
     * Marca un expediente como "Avería Crónica" al superar el límite de 2 reaperturas sucesivas (EARS 9.3).
     */
    public function markAsChronic(int $incidentId): bool;

    /**
     * Asigna un técnico de campo único a la incidencia, transicionando a ASSIGNED y
     * auditando opcionalmente la reclasificación de urgencia (RF-05 / EARS 5.1, 5.2, 5.3).
     *
     * @param int $incidentId      ID de la incidencia.
     * @param int $technicianId    ID del técnico de campo (must have role TECHNICIAN).
     * @param int|null $coordinatorId ID del coordinador que realiza la operación (para auditoría).
     * @param string|null $urgencyOverride  Nuevo nivel de urgencia (ej: 'MEDIUM') o null si no se cambia.
     * @param string|null $urgencyReason    Motivo obligatorio si se cambia la urgencia (EARS 5.3).
     * @return Incident Entidad actualizada.
     * @throws InvalidTransitionException si el estado actual no admite asignación.
     * @throws \DomainException si el técnico no existe o no es de campo.
     */
    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): Incident;

    /**
     * Reabre una incidencia en garantía: transiciona a REABIERTA, desasigna al técnico,
     * reinicia el reloj de 48h e inserta el evento de auditoría (RF-09 / EARS 9.1).
     */
    public function reopen(int $incidentId, string $reasonText): Incident;
}
