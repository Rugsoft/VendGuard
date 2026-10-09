<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use VendGuard\Core\Domain\Exception\ChronicIncidentException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoIncidentRepository
 * 
 * Implementación de persistencia con PDO para el ciclo de vida de Incidencias e Historial.
 * Garantiza transaccionalidad atómica en la creación, inmutabilidad de la auditoría y
 * cumplimiento estricto del mandato constitucional de Soft Delete (RNF-03).
 */
class PdoIncidentRepository implements IncidentRepositoryInterface
{
    /**
     * Marcadores canónicos de las notas de auditoría de la pausa (RF-01.4).
     *
     * El detalle que no tiene columna propia (causa legible y justificación al pausar;
     * duración y nota al reanudar) viaja dentro de `incident_history.action_note`. Se
     * documentan aquí porque son el contrato de lectura de la línea de tiempo del
     * expediente y de futuras auditorías, igual que el marcador `Motivo: ` de las
     * reasignaciones. Se usa `Justificación: ` —y no `Motivo: `— a propósito: ese otro
     * marcador ya identifica el motivo de reasignación en
     * `CoordinatorIncidentDetailService::findReassignmentReason()`.
     */
    private const PAUSE_NOTE_CAUSE_MARKER = ' Causa: ';
    private const PAUSE_NOTE_REASON_MARKER = ' Justificación: ';
    private const RESUME_NOTE_DURATION_MARKER = ' Duración del intervalo de pausa: ';
    private const RESUME_NOTE_NOTE_MARKER = ' Nota: ';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Inserta una nueva incidencia y registra su evento inicial en `incident_history`
     * de forma atómica bajo una transacción PDO.
     *
     * @param Incident $incident
     * @param int|null $userId
     * @param string|null $initialNote
     * @return Incident
     * @throws DuplicateIncidentException Si la máquina ya tiene una incidencia activa.
     */
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();

        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Detección preventiva de duplicados y control de garantía (Artículo V Constitución / Algoritmo 3.2)
            $existing = $this->findActiveOrResolvedByMachineId($incident->getMachineId());
            if ($existing !== null) {
                if ($isOwnTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if ($existing->getStatus() === \VendGuard\Core\Domain\ValueObject\IncidentStatus::RESOLVED) {
                    throw new DuplicateIncidentException(
                        'MACHINE_IN_WARRANTY',
                        "Esta máquina fue reparada recientemente (Ticket #{$existing->getTicketCode()}). Si el fallo persiste, pulsa en 'Reabrir incidencia'.",
                        $existing->getTicketCode(),
                        $existing->getStatus()->value,
                        409
                    );
                }

                throw new DuplicateIncidentException(
                    'MACHINE_HAS_ACTIVE_INCIDENT',
                    "Esta máquina ya cuenta con un aviso activo (Ticket #{$existing->getTicketCode()}) en estado {$existing->getStatus()->value}.",
                    $existing->getTicketCode(),
                    $existing->getStatus()->value,
                    409
                );
            }

            $sql = "
                INSERT INTO `incidents` (
                    `ticket_code`,
                    `machine_id`,
                    `location_id`,
                    `assigned_technician_id`,
                    `reporter_name`,
                    `reporter_phone`,
                    `category`,
                    `description`,
                    `retained_money_amount`,
                    `photo_path`,
                    `urgency`,
                    `status`,
                    `assigned_at`,
                    `started_at`,
                    `pending_parts_reason`,
                    `resolution_diagnosis`,
                    `resolution_action`,
                    `resolved_at`,
                    `reopen_reason`,
                    `reopened_at`,
                    `closed_at`,
                    `cancellation_reason`,
                    `cancelled_at`
                ) VALUES (
                    :ticket_code,
                    :machine_id,
                    :location_id,
                    :assigned_technician_id,
                    :reporter_name,
                    :reporter_phone,
                    :category,
                    :description,
                    :retained_money_amount,
                    :photo_path,
                    :urgency,
                    :status,
                    :assigned_at,
                    :started_at,
                    :pending_parts_reason,
                    :resolution_diagnosis,
                    :resolution_action,
                    :resolved_at,
                    :reopen_reason,
                    :reopened_at,
                    :closed_at,
                    :cancellation_reason,
                    :cancelled_at
                )
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':ticket_code', $incident->getTicketCode(), PDO::PARAM_STR);
            $stmt->bindValue(':machine_id', $incident->getMachineId(), PDO::PARAM_INT);
            $stmt->bindValue(':location_id', $incident->getLocationId(), PDO::PARAM_INT);
            $stmt->bindValue(':assigned_technician_id', $incident->getAssignedTechnicianId(), $incident->getAssignedTechnicianId() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':reporter_name', $incident->getReporterName(), $incident->getReporterName() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':reporter_phone', $incident->getReporterPhone(), $incident->getReporterPhone() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':category', $incident->getCategory()->value, PDO::PARAM_STR);
            $stmt->bindValue(':description', $incident->getDescription(), PDO::PARAM_STR);
            $stmt->bindValue(':retained_money_amount', $incident->getRetainedMoneyAmount(), $incident->getRetainedMoneyAmount() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':photo_path', $incident->getPhotoPath(), $incident->getPhotoPath() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':urgency', $incident->getUrgency()->value, PDO::PARAM_STR);
            $stmt->bindValue(':status', $incident->getStatus()->value, PDO::PARAM_STR);
            $stmt->bindValue(':assigned_at', $incident->getAssignedAt(), $incident->getAssignedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':started_at', $incident->getStartedAt(), $incident->getStartedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':pending_parts_reason', $incident->getPendingPartsReason(), $incident->getPendingPartsReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':resolution_diagnosis', $incident->getResolutionDiagnosis(), $incident->getResolutionDiagnosis() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':resolution_action', $incident->getResolutionAction(), $incident->getResolutionAction() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':resolved_at', $incident->getResolvedAt(), $incident->getResolvedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':reopen_reason', $incident->getReopenReason(), $incident->getReopenReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':reopened_at', $incident->getReopenedAt(), $incident->getReopenedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':closed_at', $incident->getClosedAt(), $incident->getClosedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':cancellation_reason', $incident->getCancellationReason(), $incident->getCancellationReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':cancelled_at', $incident->getCancelledAt(), $incident->getCancelledAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

            $stmt->execute();
            $newId = (int)$this->pdo->lastInsertId();

            // Insertar primer registro en incident_history dentro de la misma transacción
            $note = $initialNote ?? 'Aviso creado por el responsable de sede';
            $this->insertHistory(
                $newId,
                $userId,
                null,
                $incident->getStatus()->value,
                $note
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            return $this->findById($newId) ?? $incident->withId($newId);
        } catch (PDOException $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Detección de duplicado por índice único condicional uq_machine_active_ticket (código 1062)
            if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)) {
                if (str_contains($e->getMessage(), 'uq_machine_active_ticket')) {
                    $active = $this->findActiveByMachineId($incident->getMachineId());
                    $ticket = $active ? $active->getTicketCode() : 'ACTIVO';
                    $st = $active ? $active->getStatus()->value : 'REGISTERED';
                    throw new DuplicateIncidentException(
                        'MACHINE_HAS_ACTIVE_INCIDENT',
                        "Esta máquina ya cuenta con un aviso activo (Ticket #{$ticket}) en estado {$st}.",
                        $ticket,
                        $st,
                        409
                    );
                }
            }

            throw $e;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Recupera una incidencia por su ID primario.
     */
    public function findById(int $id): ?Incident
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.id = :id
              AND i.deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Incident::fromDatabaseRow($row);
    }

    /**
     * Recupera una incidencia por su código único de ticket.
     */
    public function findByTicketCode(string $ticketCode): ?Incident
    {
        $normalizedCode = strtoupper(trim($ticketCode));

        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.ticket_code = :ticket_code
              AND i.deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':ticket_code', $normalizedCode, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Incident::fromDatabaseRow($row);
    }

    /**
     * Recupera el ticket activo para una máquina dispensadora concreta.
     */
    public function findActiveByMachineId(int $machineId): ?Incident
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.machine_id = :machine_id
              AND i.is_active_ticket = 1
              AND i.deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Incident::fromDatabaseRow($row);
    }

    /**
     * Recupera la incidencia activa o resuelta en garantía para una máquina dispensadora.
     */
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        // 1. Primero comprobar si hay una incidencia activa
        $active = $this->findActiveByMachineId($machineId);
        if ($active !== null) {
            return $active;
        }

        // 2. Si no hay activa, comprobar si la última resuelta está en garantía (< 48h)
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.machine_id = :machine_id
              AND i.status = 'RESOLVED'
              AND i.resolved_at IS NOT NULL
              AND i.resolved_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
              AND i.deleted_at IS NULL
            ORDER BY i.resolved_at DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Incident::fromDatabaseRow($row);
    }

    /**
     * Recupera todas las incidencias de una sede.
     *
     * @param int $locationId
     * @param bool $activeOnly
     * @return list<Incident>
     */
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.location_id = :location_id
              AND i.deleted_at IS NULL
        ";

        if ($activeOnly) {
            $sql .= " AND i.is_active_ticket = 1";
        }

        $sql .= " ORDER BY i.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Incident::fromDatabaseRow($row), $rows);
    }

    /**
     * Recupera el historial completo de averías de una máquina (modal de historial
     * del técnico, EARS H.1 de specs/technical/technician_machine_history_contracts.md):
     * toda avería no borrada lógicamente salvo los estados excluidos, ordenada de
     * más reciente a más antigua.
     *
     * @param int $machineId Identificador de la máquina.
     * @param list<string> $excludeStatuses Estados a excluir (por defecto ['CANCELLED']).
     * @return list<Incident>
     */
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.machine_id = :machine_id
              AND i.deleted_at IS NULL
        ";

        foreach ($excludeStatuses as $idx => $status) {
            $key = ":excl_status_{$idx}";
            $sql .= " AND i.status <> {$key}";
        }

        $sql .= " ORDER BY i.created_at DESC, i.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        foreach ($excludeStatuses as $idx => $status) {
            $stmt->bindValue(":excl_status_{$idx}", (string)$status, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Incident::fromDatabaseRow($row), $rows);
    }

    /**
     * Listado global con filtros combinados (Coordinador).
     *
     * @param array<string, mixed> $filters
     * @return list<Incident>
     */
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name,
                u.operator_code AS technician_operator_code
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $idx => $st) {
                    $key = ":st_{$idx}";
                    $placeholders[] = $key;
                    $params[$key] = (string)$st;
                }
                $sql .= " AND i.status IN (" . implode(', ', $placeholders) . ")";
            } else {
                $sql .= " AND i.status = :status";
                $params[':status'] = (string)$filters['status'];
            }
        }

        if (!empty($filters['urgency'])) {
            $sql .= " AND i.urgency = :urgency";
            $params[':urgency'] = (string)$filters['urgency'];
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND i.location_id = :location_id";
            $params[':location_id'] = (int)$filters['location_id'];
        }

        if (!empty($filters['assigned_technician_id'])) {
            $sql .= " AND i.assigned_technician_id = :assigned_technician_id";
            $params[':assigned_technician_id'] = (int)$filters['assigned_technician_id'];
        }

        if (isset($filters['active_only']) && $filters['active_only'] === true) {
            $sql .= " AND i.is_active_ticket = 1";
        }

        // Ordenar con máxima prioridad a CRITICAL, luego HIGH, MEDIUM, LOW, y por fecha de creación
        $sql .= " ORDER BY FIELD(i.urgency, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW'), i.created_at ASC";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Incident::fromDatabaseRow($row), $rows);
    }

    /**
     * Recupera las incidencias asignadas a la ruta de un técnico concreto.
     *
     * @param int $technicianId
     * @param list<string> $statuses
     * @return list<Incident>
     */
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        $sql = "
            SELECT 
                i.*,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type AS machine_type,
                l.name AS location_name,
                l.site_code AS location_site_code,
                u.name AS technician_name
            FROM `incidents` i
            LEFT JOIN `machines` m ON i.machine_id = m.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            LEFT JOIN `users` u ON i.assigned_technician_id = u.id
            WHERE i.assigned_technician_id = :technician_id
              AND i.deleted_at IS NULL
        ";

        $params = [':technician_id' => $technicianId];

        if (!empty($statuses)) {
            $placeholders = [];
            foreach ($statuses as $idx => $st) {
                $key = ":tech_st_{$idx}";
                $placeholders[] = $key;
                $params[$key] = (string)$st;
            }
            $sql .= " AND i.status IN (" . implode(', ', $placeholders) . ")";
        }

        // Ruta ordenada por criticidad y antigüedad
        $sql .= " ORDER BY FIELD(i.urgency, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW'), i.created_at ASC";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Incident::fromDatabaseRow($row), $rows);
    }

    /**
     * One consistent read of the whole incident file, for the coordinator detail modal.
     *
     * Module 09 needs everything that today is scattered across the triage table, the
     * audit console and the refunds inbox (RF-02 to RF-06, RNF-01) in a single round
     * trip. The method opens one read transaction so every piece comes from the same
     * snapshot and performs no writes at all: viewing a case must never mutate it
     * (Art. III, RNF-04).
     *
     * The identifier accepts the primary key (int or numeric string), a ticket code
     * (`TICK-2026-00142`) and the same code prefixed with `#`, because the modal is
     * fed from table rows where either form may travel.
     *
     * Rows are returned as fetched: deriving SLA, labels, permissions and masked
     * payment data belongs to `CoordinatorIncidentDetailService` (T-IDM-03), which
     * consumes this structure.
     *
     * @param int|string $identifier Incident id or ticket code, with optional '#' prefix.
     * @return array{
     *   incident: array<string, mixed>,
     *   machine: array<string, mixed>|null,
     *   location: array<string, mixed>|null,
     *   technician: array<string, mixed>|null,
     *   history: list<array<string, mixed>>,
     *   comments: list<array<string, mixed>>,
     *   requested_parts: list<array<string, mixed>>,
     *   replaced_parts: list<array<string, mixed>>,
     *   refund: array<string, mixed>|null
     * }|null Null when the incident does not exist or is soft-deleted.
     */
    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        $incidentId = null;
        $ticketCode = null;

        if (is_int($identifier)) {
            if ($identifier < 1) {
                return null;
            }
            $incidentId = $identifier;
        } else {
            $candidate = trim($identifier);
            if (str_starts_with($candidate, '#')) {
                $candidate = trim(substr($candidate, 1));
            }

            if ($candidate === '') {
                return null;
            }

            if (ctype_digit($candidate)) {
                $incidentId = (int)$candidate;
            } else {
                $ticketCode = strtoupper($candidate);
            }
        }

        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->fetchSingleRow(
                "SELECT i.* FROM `incidents` i WHERE i.deleted_at IS NULL"
                . ($ticketCode !== null ? " AND i.ticket_code = :ticket_code" : " AND i.id = :incident_id")
                . " LIMIT 1",
                $ticketCode !== null ? [':ticket_code' => $ticketCode] : [':incident_id' => $incidentId]
            );

            if ($incident === null) {
                if ($isOwnTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                return null;
            }

            $incidentId = (int)$incident['id'];
            $technicianId = $incident['assigned_technician_id'] !== null
                ? (int)$incident['assigned_technician_id']
                : null;

            $result = [
                'incident' => $incident,
                'machine' => $this->fetchSingleRow(
                    "SELECT id, location_id, code, model, machine_type, floor_wing, sanitary_status, notes, is_active\r\n                     FROM `machines` WHERE id = :machine_id LIMIT 1",
                    [':machine_id' => (int)$incident['machine_id']]
                ),
                'location' => $this->fetchSingleRow(
                    "SELECT id, site_code, name, address, has_physical_reception, latitude, longitude\r\n                     FROM `locations` WHERE id = :location_id LIMIT 1",
                    [':location_id' => (int)$incident['location_id']]
                ),
                'technician' => $technicianId === null ? null : $this->fetchSingleRow(
                    "SELECT id, name, operator_code, role, is_active\r\n                     FROM `users` WHERE id = :technician_id LIMIT 1",
                    [':technician_id' => $technicianId]
                ),
                'history' => $this->fetchAllRows(
                    "SELECT h.id, h.user_id, h.from_status, h.to_status, h.action_note, h.created_at,\r\n                            u.name AS user_name, u.role AS user_role\r\n                     FROM `incident_history` h\r\n                     LEFT JOIN `users` u ON h.user_id = u.id\r\n                     WHERE h.incident_id = :incident_id\r\n                     ORDER BY h.created_at ASC, h.id ASC",
                    [':incident_id' => $incidentId]
                ),
                'comments' => $this->fetchAllRows(
                    "SELECT c.id, c.author_type, c.user_id, c.author_name, c.comment_text,\r\n                            c.photo_path, c.is_internal, c.created_at\r\n                     FROM `incident_comments` c\r\n                     WHERE c.incident_id = :incident_id\r\n                     ORDER BY c.created_at ASC, c.id ASC",
                    [':incident_id' => $incidentId]
                ),
                'requested_parts' => $this->fetchAllRows(
                    "SELECT r.id, r.spare_part_id, r.is_out_of_catalog, r.custom_part_description,\r\n                            r.quantity, r.status, r.created_at, p.part_code, p.name AS part_name\r\n                     FROM `spare_part_requests` r\r\n                     LEFT JOIN `spare_parts` p ON r.spare_part_id = p.id\r\n                     WHERE r.incident_id = :incident_id\r\n                     ORDER BY r.created_at ASC, r.id ASC",
                    [':incident_id' => $incidentId]
                ),
                'replaced_parts' => $this->fetchAllRows(
                    "SELECT rp.id, rp.spare_part_id, rp.is_out_of_catalog, rp.custom_part_name,\r\n                            rp.quantity, rp.unit_cost_snapshot, rp.total_cost_snapshot, rp.old_part_destination,\r\n                            rp.notes, rp.installed_at, rp.created_at, p.part_code, p.name AS part_name\r\n                     FROM `incident_replaced_parts` rp\r\n                     LEFT JOIN `spare_parts` p ON rp.spare_part_id = p.id\r\n                     WHERE rp.incident_id = :incident_id AND rp.intervention_type = 'INCIDENT'\r\n                     ORDER BY rp.installed_at ASC, rp.id ASC",
                    [':incident_id' => $incidentId]
                ),
                'refund' => $this->fetchSingleRow(
                    "SELECT id, incident_id, machine_id, location_id, claimant_name, claimant_contact,\r\n                            claimed_amount, product_attempted, compensation_method, bizum_phone, iban, status,\r\n                            technician_finding, recovered_amount, cash_custody_action, technician_justification,\r\n                            technician_inspected_at, technician_id, coordinator_decision, approved_amount,\r\n                            coordinator_justification, coordinator_id, payment_reference, paid_amount, paid_at,\r\n                            hand_delivered_at, created_at, updated_at\r\n                     FROM `refund_requests`\r\n                     WHERE incident_id = :incident_id AND deleted_at IS NULL\r\n                     ORDER BY id DESC\r\n                     LIMIT 1",
                    [':incident_id' => $incidentId]
                ),
            ];

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Runs one prepared read and returns its first row, or null when there is none.
     *
     * @param array<string, int|string|null> $params
     * @return array<string, mixed>|null
     */
    private function fetchSingleRow(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Runs one prepared read and returns every row as an associative array.
     *
     * @param array<string, int|string|null> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAllRows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Binds a homogeneous parameter map with the narrowest PDO type.
     *
     * @param array<string, int|string|null> $params
     */
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * Actualiza los campos mutables y el estado de una incidencia.
     */
    public function update(Incident $incident): bool
    {
        if ($incident->getId() === null) {
            return false;
        }

        $sql = "
            UPDATE `incidents` SET
                `assigned_technician_id` = :assigned_technician_id,
                `category` = :category,
                `description` = :description,
                `retained_money_amount` = :retained_money_amount,
                `photo_path` = :photo_path,
                `urgency` = :urgency,
                `status` = :status,
                `assigned_at` = :assigned_at,
                `started_at` = :started_at,
                `pending_parts_reason` = :pending_parts_reason,
                `resolution_diagnosis` = :resolution_diagnosis,
                `resolution_action` = :resolution_action,
                `resolved_at` = :resolved_at,
                `reopen_reason` = :reopen_reason,
                `reopened_at` = :reopened_at,
                `closed_at` = :closed_at,
                `cancellation_reason` = :cancellation_reason,
                `cancelled_at` = :cancelled_at,
                `pending_info_reason_category` = :pending_info_reason_category,
                `pending_info_reason_text` = :pending_info_reason_text,
                `paused_at` = :paused_at,
                `total_pending_info_seconds` = :total_pending_info_seconds,
                `sla_target_at` = :sla_target_at
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $incident->getId(), PDO::PARAM_INT);
        $stmt->bindValue(':assigned_technician_id', $incident->getAssignedTechnicianId(), $incident->getAssignedTechnicianId() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':category', $incident->getCategory()->value, PDO::PARAM_STR);
        $stmt->bindValue(':description', $incident->getDescription(), PDO::PARAM_STR);
        $stmt->bindValue(':retained_money_amount', $incident->getRetainedMoneyAmount(), $incident->getRetainedMoneyAmount() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':photo_path', $incident->getPhotoPath(), $incident->getPhotoPath() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':urgency', $incident->getUrgency()->value, PDO::PARAM_STR);
        $stmt->bindValue(':status', $incident->getStatus()->value, PDO::PARAM_STR);
        $stmt->bindValue(':assigned_at', $incident->getAssignedAt(), $incident->getAssignedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':started_at', $incident->getStartedAt(), $incident->getStartedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':pending_parts_reason', $incident->getPendingPartsReason(), $incident->getPendingPartsReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':resolution_diagnosis', $incident->getResolutionDiagnosis(), $incident->getResolutionDiagnosis() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':resolution_action', $incident->getResolutionAction(), $incident->getResolutionAction() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':resolved_at', $incident->getResolvedAt(), $incident->getResolvedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':reopen_reason', $incident->getReopenReason(), $incident->getReopenReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':reopened_at', $incident->getReopenedAt(), $incident->getReopenedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':closed_at', $incident->getClosedAt(), $incident->getClosedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':cancellation_reason', $incident->getCancellationReason(), $incident->getCancellationReason() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':cancelled_at', $incident->getCancelledAt(), $incident->getCancelledAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        // Estado de pausa (módulo 11): el agregado es la única fuente de verdad y esta
        // escritura es su persistencia completa. `total_pending_info_seconds` es el
        // acumulador de todos los intervalos y sólo lo mueve `resumePendingInfo()`.
        $pauseCategory = $incident->getPendingInfoReasonCategory();
        $stmt->bindValue(
            ':pending_info_reason_category',
            $pauseCategory?->value,
            $pauseCategory !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':pending_info_reason_text', $incident->getPendingInfoReasonText(), $incident->getPendingInfoReasonText() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':paused_at', $incident->getPausedAt(), $incident->getPausedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':total_pending_info_seconds', $incident->getTotalPendingInfoSeconds(), PDO::PARAM_INT);
        $stmt->bindValue(':sla_target_at', $incident->getSlaTargetAt(), $incident->getSlaTargetAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        return $stmt->execute();
    }

    /**
     * Aplica Soft Delete preservando intacto el registro físico en la base de datos (RNF-03).
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE `incidents`
            SET `deleted_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Inserta un registro inmutable en incident_history.
     */
    public function insertHistory(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null
    ): int {
        $sql = "
            INSERT INTO `incident_history` (
                `incident_id`,
                `user_id`,
                `from_status`,
                `to_status`,
                `action_note`,
                `created_at`
            ) VALUES (
                :incident_id,
                :user_id,
                :from_status,
                :to_status,
                :action_note,
                CURRENT_TIMESTAMP
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':from_status', $fromStatus, $fromStatus !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':to_status', $toStatus, PDO::PARAM_STR);
        $stmt->bindValue(':action_note', $actionNote, $actionNote !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Obtiene el historial de auditoría inmutable de una incidencia ordenado cronológicamente.
     *
     * @param int $incidentId
     * @return list<IncidentHistory>
     */
    public function getHistory(int $incidentId): array
    {
        $sql = "
            SELECT 
                h.*,
                u.name AS user_name,
                u.role AS user_role
            FROM `incident_history` h
            LEFT JOIN `users` u ON h.user_id = u.id
            WHERE h.incident_id = :incident_id
            ORDER BY h.created_at ASC, h.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => IncidentHistory::fromDatabaseRow($row), $rows);
    }

    /**
     * Añade un nuevo comentario o evidencia fotográfica a la bitácora de la incidencia (RF-02 / EARS 2.3).
     * Garantiza que la fotografía original en incidents.photo_path no sea sobreescrita.
     */
    public function addComment(IncidentComment $comment): IncidentComment
    {
        $sql = "
            INSERT INTO `incident_comments` (
                `incident_id`,
                `author_type`,
                `user_id`,
                `author_name`,
                `comment_text`,
                `photo_path`,
                `is_internal`,
                `created_at`
            ) VALUES (
                :incident_id,
                :author_type,
                :user_id,
                :author_name,
                :comment_text,
                :photo_path,
                :is_internal,
                CURRENT_TIMESTAMP
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $comment->getIncidentId(), PDO::PARAM_INT);
        $stmt->bindValue(':author_type', $comment->getAuthorType(), PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $comment->getUserId(), $comment->getUserId() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':author_name', $comment->getAuthorName(), PDO::PARAM_STR);
        $stmt->bindValue(':comment_text', $comment->getCommentText(), PDO::PARAM_STR);
        $stmt->bindValue(':photo_path', $comment->getPhotoPath(), $comment->getPhotoPath() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_internal', $comment->isInternal() ? 1 : 0, PDO::PARAM_INT);

        $stmt->execute();
        $id = (int)$this->pdo->lastInsertId();

        $fetchStmt = $this->pdo->prepare("
            SELECT c.*, i.ticket_code 
            FROM `incident_comments` c
            JOIN `incidents` i ON c.incident_id = i.id
            WHERE c.id = :id
            LIMIT 1
        ");
        $fetchStmt->execute([':id' => $id]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return new IncidentComment(
                id: $id,
                incidentId: $comment->getIncidentId(),
                authorType: $comment->getAuthorType(),
                userId: $comment->getUserId(),
                authorName: $comment->getAuthorName(),
                commentText: $comment->getCommentText(),
                photoPath: $comment->getPhotoPath(),
                isInternal: $comment->isInternal(),
                createdAt: date('Y-m-d H:i:s'),
                ticketCode: $comment->getTicketCode()
            );
        }

        return IncidentComment::fromDatabaseRow($row);
    }

    /**
     * Recupera los comentarios asociados a una incidencia.
     *
     * @param int $incidentId
     * @param bool $includeInternal Si es false, excluye comentarios internos (RNF-04).
     * @return list<IncidentComment>
     */
    public function getComments(int $incidentId, bool $includeInternal = true): array
    {
        $sql = "
            SELECT c.*, i.ticket_code
            FROM `incident_comments` c
            JOIN `incidents` i ON c.incident_id = i.id
            WHERE c.incident_id = :incident_id
        ";

        if (!$includeInternal) {
            $sql .= " AND c.is_internal = 0";
        }

        $sql .= " ORDER BY c.created_at ASC, c.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => IncidentComment::fromDatabaseRow($row), $rows);
    }

    /**
     * Recupera un bloque paginado por cursor de los comentarios de una incidencia
     * (Módulo 10, T-COM-02 · RF-01.2, RF-01.3, RNF-02).
     *
     * La consulta aprovecha el índice `idx_comments_incident` (incident_id,
     * created_at): filtra por la incidencia, toma el bloque de mensajes más
     * recientes (DESC) recortado por el cursor `beforeId` y lo reordena en
     * ascendente para entregarlo cronológicamente, sin escanear tablas completas.
     * El desempate por `id` garantiza orden inequívoco ante marcas temporales
     * iguales (milisegundos del servidor).
     */
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array
    {
        $sql = "
            SELECT c.*, i.ticket_code
            FROM `incident_comments` c
            JOIN `incidents` i ON c.incident_id = i.id
            WHERE c.incident_id = :incident_id
        ";

        // Segregación estricta de notas internas ante perfiles de Sede (Art. V.4).
        if (!$includeInternal) {
            $sql .= " AND c.is_internal = 0";
        }

        // Paginación retrospectiva por cursor: solo mensajes más antiguos que el
        // mensaje más antiguo ya cargado en el cliente (RF-01.3).
        if ($beforeId !== null) {
            $sql .= " AND c.id < :before_id";
        }

        $sql .= " ORDER BY c.created_at DESC, c.id DESC LIMIT :limit_rows";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        if ($beforeId !== null) {
            $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // El bloque se capturó de más reciente a más antiguo: se invierte para
        // entregarse en orden cronológico de lectura (más antiguo primero).
        $rows = array_reverse($rows);

        return array_map(fn(array $row) => IncidentComment::fromDatabaseRow($row), $rows);
    }

    /**
     * Cuenta los comentarios de una incidencia con recuentos segregados
     * (Módulo 10, T-COM-02 · RF-01.1): solo públicos para la Sede
     * (`is_internal = 0`) o el total público + interno para Técnicos y
     * Coordinadores (`is_internal IN (0, 1)`).
     */
    public function countComments(int $incidentId, bool $includeInternal): int
    {
        $sql = "SELECT COUNT(*) FROM `incident_comments` WHERE incident_id = :incident_id";

        if (!$includeInternal) {
            $sql .= " AND is_internal = 0";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Cuenta el número de eventos de reapertura previos registrados en la auditoría (RF-09 / EARS 9.3).
     */
    public function countReopenEvents(int $incidentId): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM `incident_history` 
            WHERE `incident_id` = :incident_id 
              AND `to_status` IN ('REOPENED', 'REABIERTA')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Marca un expediente como "Avería Crónica" al superar el límite de 2 reaperturas sucesivas (EARS 9.3).
     */
    public function markAsChronic(int $incidentId): bool
    {
        $sql = "
            UPDATE `incidents`
            SET `reopen_reason` = CONCAT(IFNULL(`reopen_reason`, ''), ' [AVERÍA CRÓNICA]')
            WHERE `id` = :id
              AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $updated = $stmt->execute([':id' => $incidentId]);

        // Registrar en el historial la marca de Avería Crónica
        $this->insertHistory(
            $incidentId,
            null,
            'RESOLVED',
            'RESOLVED',
            'Expediente catalogado como Avería Crónica tras superar el límite de 2 reaperturas sucesivas.'
        );

        return $updated;
    }

    /**
     * Asigna un técnico de campo a la incidencia y transiciona al estado ASSIGNED.
     * Permite reclasificar la urgencia con motivo auditado (RF-05 / EARS 5.1, 5.2, 5.3).
     *
     * Desde RF-07.3 el método también soporta la reasignación de un aviso con técnico
     * responsable vigente (ASSIGNED, IN_PROGRESS, PENDING_PARTS): el profesional se
     * sustituye manteniendo un único asignado activo (EARS 5.2, Art. V.3) y el motivo
     * justificado viaja a la nota del historial inmutable (Art. III.3). En la
     * reasignación NO se retrocede el estado operativo ni se reescribe `assigned_at`:
     * el hito oficial de asignación se conserva para no falsear los tiempos de
     * respuesta ya auditados, y el instante del cambio queda fechado por la propia
     * fila de historial (Art. III.1).
     *
     * Desde RF-06.3 (`T-PAUSE-13`) la reasignación alcanza también al expediente
     * pausado por bloqueo de sede (`PENDING_INFO`): el nuevo responsable hereda el
     * contexto (`pending_info_reason_category`, `pending_info_reason_text`, `paused_at`
     * y los segundos acumulados viajan intactos, porque este método no toca ninguna
     * columna de pausa) y la fila de historial queda con origen y destino
     * `PENDING_INFO`, que es la huella estructural que `IncidentPauseService` lee para
     * saber que la avería cambió de manos durante la pausa (RF-02.1).
     */
    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null,
        ?string $reassignmentReason = null
    ): Incident {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar transición legal (EARS 5.1, RF-07.3): REGISTERED/REOPENED admiten
            //    la asignación inicial y los estados con responsable vigente admiten el
            //    cambio de técnico sin retroceder el estado operativo.
            $assignmentStatuses = [IncidentStatus::REGISTERED, IncidentStatus::REOPENED];
            $reassignmentStatuses = [
                IncidentStatus::ASSIGNED,
                IncidentStatus::IN_PROGRESS,
                IncidentStatus::PENDING_PARTS,
                IncidentStatus::PENDING_INFO,
            ];
            $isReassignment = in_array($incident->getStatus(), $reassignmentStatuses, true);
            if (!$isReassignment && !in_array($incident->getStatus(), $assignmentStatuses, true)) {
                throw new InvalidTransitionException(
                    "Solo las incidencias activas (REGISTRADA, REABIERTA, ASIGNADA, EN CURSO, PENDIENTE DE REPUESTOS o PENDIENTE DE INFORMACIÓN) admiten asignación técnica. Estado actual: {$incident->getStatus()->value}.",
                    $incident->getStatus(),
                    IncidentStatus::ASSIGNED
                );
            }

            // 1.b Guardas internas de la reasignación (defensa en profundidad: la capa de
            //     presentación ya responde 422 antes de llegar aquí).
            $cleanReassignmentReason = $reassignmentReason !== null ? trim($reassignmentReason) : '';
            if ($isReassignment && $cleanReassignmentReason === '') {
                throw new DomainException('La reasignación técnica exige un motivo justificado.');
            }
            if ($isReassignment && $incident->getAssignedTechnicianId() === $technicianId) {
                throw new DomainException('El técnico indicado ya es el responsable activo de la incidencia.');
            }

            // 2. Resolver urgencia final (posible reclasificación coordinador, EARS 5.3)
            $finalUrgency = $incident->getUrgency()->value;
            $urgencyChanged = false;
            if ($urgencyOverride !== null && trim($urgencyOverride) !== '') {
                $newUrgency = strtoupper(trim($urgencyOverride));
                if ($newUrgency !== $incident->getUrgency()->value) {
                    $finalUrgency = $newUrgency;
                    $urgencyChanged = true;
                }
            }

            // 3. Actualizar incidencia: técnico asociado y urgencia final. En la asignación
            //    inicial se fija además el estado ASSIGNED y la fecha oficial; en la
            //    reasignación se preservan estado y fecha para no reescribir hitos auditados.
            $setClause = $isReassignment
                ? "`assigned_technician_id` = :technician_id,\n                    `urgency` = :urgency"
                : "`status` = 'ASSIGNED',\n                    `assigned_technician_id` = :technician_id,\n                    `assigned_at` = CURRENT_TIMESTAMP,\n                    `urgency` = :urgency";
            $sql = "
                UPDATE `incidents`
                SET {$setClause}
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
            $stmt->bindValue(':urgency', $finalUrgency, PDO::PARAM_STR);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->execute();

            // 4. Registrar el evento de asignación o reasignación en el historial inmutable.
            //    En la reasignación el motivo justificado se coloca al final de la nota, con
            //    el marcador canónico 'Motivo: ' que el servicio de detalle lee para exponer
            //    `technician.reassignment_reason` (RF-02, RF-04.1). Debe permanecer al final.
            if ($isReassignment) {
                $actionNote = 'Reasignación técnica: del técnico ID '
                    . ($incident->getAssignedTechnicianId() ?? 0)
                    . " al técnico ID {$technicianId}.";
                if ($urgencyChanged) {
                    $actionNote .= " Reclasificación de urgencia de {$incident->getUrgency()->value} a {$finalUrgency}.";
                }
                $actionNote .= ' Motivo: ' . $cleanReassignmentReason;
            } else {
                $actionNote = "Incidencia asignada al técnico ID {$technicianId}.";
                if ($urgencyChanged) {
                    $actionNote .= " Urgencia reclasificada de {$incident->getUrgency()->value} a {$finalUrgency}. Motivo: " . trim((string)$urgencyReason);
                }
            }
            $this->insertHistory(
                $incidentId,
                $coordinatorId,
                $incident->getStatus()->value,
                $isReassignment ? $incident->getStatus()->value : 'ASSIGNED',
                $actionNote
            );

            // 5. Si se cambió la urgencia, registrar también un evento de auditoría de reclasificación
            if ($urgencyChanged && $urgencyReason !== null && trim($urgencyReason) !== '') {
                $this->insertHistory(
                    $incidentId,
                    $coordinatorId,
                    'ASSIGNED',
                    'ASSIGNED',
                    "Reclasificación de urgencia a {$finalUrgency} registrada en auditoría. Justificación del coordinador: " . trim($urgencyReason)
                );
            }

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $assigned = $this->findById($incidentId);
            if ($assigned === null) {
                throw new DomainException("Error al recuperar la incidencia asignada.");
            }

            return $assigned;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reabre una incidencia en garantía: transiciona a REABIERTA, desasigna al técnico,
     * reinicia el reloj de 48h e inserta el evento de auditoría (RF-09 / EARS 9.1, 9.2, 9.3).
     */
    public function reopen(int $incidentId, string $reasonText): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar que la incidencia esté en estado RESOLVED
            if ($incident->getStatus() !== IncidentStatus::RESOLVED) {
                throw new InvalidTransitionException(
                    "Solo se pueden reabrir incidencias en estado RESUELTA. El estado actual es {$incident->getStatus()->value}.",
                    $incident->getStatus(),
                    IncidentStatus::REOPENED
                );
            }

            // 2. Validar ventana de garantía de 48 horas (EARS 9.2)
            if (!$incident->isInWarranty(48)) {
                throw new WarrantyExpiredException(
                    'REOPEN_WINDOW_EXPIRED',
                    'Han transcurrido más de 48 horas desde la resolución de la incidencia. La ventana de garantía ha expirado; debe registrar un nuevo ticket de avería.'
                );
            }

            // 3. Validar límite de 2 reaperturas sucesivas (EARS 9.3)
            $reopenCount = $this->countReopenEvents($incidentId);
            if ($reopenCount >= 2) {
                $this->markAsChronic($incidentId);
                if ($isOwnTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                throw new ChronicIncidentException(
                    'CHRONIC_INCIDENT_LIMIT',
                    'Esta máquina ha presentado múltiples reincidencias consecutivas y el expediente ha sido catalogado como \'Avería Crónica\'. La reapertura automática desde el portal está bloqueada. Por favor, contacte directamente con el centro de coordinación técnica para una auditoría presencial.',
                    $incident->getTicketCode(),
                    $reopenCount
                );
            }

            // 4. Actualizar registro: status = REOPENED, desasignar técnico, reiniciar reloj 48h (resolved_at = NULL)
            $sql = "
                UPDATE `incidents`
                SET `status` = 'REOPENED',
                    `assigned_technician_id` = NULL,
                    `assigned_at` = NULL,
                    `reopened_at` = CURRENT_TIMESTAMP,
                    `reopen_reason` = :reopen_reason,
                    `resolved_at` = NULL
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->bindValue(':reopen_reason', trim($reasonText), PDO::PARAM_STR);
            $stmt->execute();

            // 5. Insertar evento en incident_history
            $eventNumber = $reopenCount + 1;
            $this->insertHistory(
                $incidentId,
                null,
                'RESOLVED',
                'REOPENED',
                "Reapertura solicitada por la sede ({$eventNumber}ª reincidencia). Motivo: " . trim($reasonText)
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $reopened = $this->findById($incidentId);
            if ($reopened === null) {
                throw new DomainException("Error al recuperar la incidencia reabierta.");
            }

            return $reopened;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Descarta o anula lógicamente una incidencia activa (RF-06 / EARS 6.1, 6.2, 6.3 / RNF-03).
     * Transiciona el estado a CANCELLED, fija cancelled_at y cancellation_reason,
     * registra la auditoría inmutable en incident_history y preserva la fila en la BD.
     */
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar que el motivo explicativo no esté vacío (EARS 6.1, 6.3)
            $trimmedReason = trim($cancellationReason);
            if ($trimmedReason === '') {
                throw new DomainException("El motivo de cancelación es obligatorio.");
            }

            // 2. Validar transición legal a CANCELLED
            if (!$incident->getStatus()->canTransitionTo(IncidentStatus::CANCELLED)) {
                throw new InvalidTransitionException(
                    "No se puede cancelar una incidencia en estado {$incident->getStatus()->value}.",
                    $incident->getStatus(),
                    IncidentStatus::CANCELLED
                );
            }

            // 3. Actualizar la incidencia a CANCELLED preservando la fila en base de datos (EARS 6.2, RNF-03, Artículo III)
            $sql = "
                UPDATE `incidents`
                SET `status` = 'CANCELLED',
                    `cancellation_reason` = :reason,
                    `cancelled_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':reason', $trimmedReason, PDO::PARAM_STR);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->execute();

            // 4. Registrar evento de auditoría inmutable en incident_history
            $this->insertHistory(
                $incidentId,
                $coordinatorId,
                $incident->getStatus()->value,
                'CANCELLED',
                "Aviso descartado/cancelado. Motivo: " . $trimmedReason
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $cancelled = $this->findById($incidentId);
            if ($cancelled === null) {
                throw new DomainException("Error al recuperar la incidencia cancelada.");
            }

            return $cancelled;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Inicia o reanuda la intervención técnica en campo (RF-07 / EARS 7.1, 7.3).
     * Transiciona a IN_PROGRESS, registra started_at si es la primera vez y anota en auditoría.
     */
    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar que la incidencia esté asignada al técnico que interviene
            if ($incident->getAssignedTechnicianId() !== $technicianId) {
                throw new DomainException("La incidencia no está asignada al técnico indicado.");
            }

            // 2. Validar transición legal a IN_PROGRESS
            if (!$incident->getStatus()->canTransitionTo(IncidentStatus::IN_PROGRESS)) {
                throw new InvalidTransitionException(
                    "No se puede iniciar la intervención en una incidencia en estado {$incident->getStatus()->value}.",
                    $incident->getStatus(),
                    IncidentStatus::IN_PROGRESS
                );
            }

            // 3. Actualizar estado a IN_PROGRESS y fijar started_at (conservando si ya existía)
            $sql = "
                UPDATE `incidents`
                SET `status` = 'IN_PROGRESS',
                    `started_at` = COALESCE(`started_at`, CURRENT_TIMESTAMP)
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->execute();

            // 4. Registrar en historial inmutable
            $note = ($incident->getStatus() === IncidentStatus::PENDING_PARTS)
                ? 'Reanudación de trabajos técnicos in situ tras recepción de repuestos.'
                : 'Inicio de intervención presencial del técnico de campo.';

            $this->insertHistory(
                $incidentId,
                $technicianId,
                $incident->getStatus()->value,
                'IN_PROGRESS',
                $note
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $started = $this->findById($incidentId);
            if ($started === null) {
                throw new DomainException("Error al recuperar la incidencia iniciada.");
            }

            return $started;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pausa temporalmente la intervención técnica por falta de repuestos (RF-07 / EARS 7.2).
     * Transiciona a PENDING_PARTS, exige descripción de la pieza requerida y anota en auditoría.
     */
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar que la incidencia esté asignada al técnico que pausa
            if ($incident->getAssignedTechnicianId() !== $technicianId) {
                throw new DomainException("La incidencia no está asignada al técnico indicado.");
            }

            // 2. Validar que el motivo de recambio no esté vacío (EARS 7.2)
            $trimmedReason = trim($pendingPartsReason);
            if ($trimmedReason === '') {
                throw new DomainException("La descripción de la pieza o repuesto requerido es obligatoria.");
            }

            // 3. Validar transición legal a PENDING_PARTS
            if (!$incident->getStatus()->canTransitionTo(IncidentStatus::PENDING_PARTS)) {
                throw new InvalidTransitionException(
                    "No se puede pausar una incidencia en estado {$incident->getStatus()->value}.",
                    $incident->getStatus(),
                    IncidentStatus::PENDING_PARTS
                );
            }

            // 4. Actualizar estado a PENDING_PARTS y registrar motivo
            $sql = "
                UPDATE `incidents`
                SET `status` = 'PENDING_PARTS',
                    `pending_parts_reason` = :reason
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':reason', $trimmedReason, PDO::PARAM_STR);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->execute();

            // 5. Registrar en historial inmutable
            $this->insertHistory(
                $incidentId,
                $technicianId,
                $incident->getStatus()->value,
                'PENDING_PARTS',
                'Intervención pausada por falta de repuestos. Solicitud de pieza: ' . $trimmedReason
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $paused = $this->findById($incidentId);
            if ($paused === null) {
                throw new DomainException("Error al recuperar la incidencia pausada.");
            }

            return $paused;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resuelve técnicamente una incidencia activa (RF-08 / EARS 8.1, 8.2, 8.3).
     * Exige diagnóstico y acción técnica de al menos 20 caracteres descriptivos cada uno.
     * Transiciona el estado a RESOLVED, registra resolved_at e inserta el evento de auditoría.
     */
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        $isOwnTransaction = !$this->pdo->inTransaction();
        if ($isOwnTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $incident = $this->findById($incidentId);
            if ($incident === null) {
                throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
            }

            // 1. Validar que la incidencia esté asignada al técnico que resuelve
            if ($incident->getAssignedTechnicianId() !== $technicianId) {
                throw new DomainException("La incidencia no está asignada al técnico indicado.");
            }

            // 2. Validar que el estado actual permita transicionar a RESOLVED
            if (!$incident->getStatus()->canTransitionTo(IncidentStatus::RESOLVED)) {
                throw new InvalidTransitionException(
                    "No se puede resolver una incidencia en estado {$incident->getStatus()->value}. Debe encontrarse en estado EN_CURSO (IN_PROGRESS).",
                    $incident->getStatus(),
                    IncidentStatus::RESOLVED
                );
            }

            // 3. Validación estricta de textos (mín. 20 caracteres en cada uno, EARS 8.1, 8.2)
            \VendGuard\Core\Service\ResolutionValidator::validate($diagnosis, $action);

            // 4. Actualizar estado a RESOLVED, registrar informe técnico y fijar resolved_at
            $sql = "
                UPDATE `incidents`
                SET `status` = 'RESOLVED',
                    `resolution_diagnosis` = :diagnosis,
                    `resolution_action` = :action,
                    `resolved_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id
                  AND `deleted_at` IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':diagnosis', trim($diagnosis), PDO::PARAM_STR);
            $stmt->bindValue(':action', trim($action), PDO::PARAM_STR);
            $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
            $stmt->execute();

            // 5. Registrar evento de resolución en el historial inmutable
            $this->insertHistory(
                $incidentId,
                $technicianId,
                $incident->getStatus()->value,
                'RESOLVED',
                "Avería resuelta con éxito por el técnico de campo. Diagnóstico: " . trim($diagnosis) . " | Solución: " . trim($action)
            );

            if ($isOwnTransaction) {
                $this->pdo->commit();
            }

            $resolved = $this->findById($incidentId);
            if ($resolved === null) {
                throw new DomainException("Error al recuperar la incidencia resuelta.");
            }

            return $resolved;
        } catch (Throwable $e) {
            if ($isOwnTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cierra automáticamente todas las incidencias en estado RESOLVED cuya ventana
     * de garantía de 48 horas haya vencido sin reapertura (RF-10 / EARS 10.1, 10.2).
     * Transiciona el estado a CLOSED, fija closed_at e inserta eventos de auditoría inmutable.
     *
     * @param int $hours
     * @return list<Incident>
     */
    public function recordPauseEvent(
        int $incidentId,
        int $userId,
        IncidentStatus $fromStatus,
        IncidentPauseReasonCategory $category,
        string $reasonText,
        DateTimeImmutable $pausedAt
    ): void {
        if ($incidentId < 1) {
            throw new InvalidArgumentException('El evento de pausa exige una incidencia persistida (id positivo).');
        }

        $normalizedReason = trim($reasonText);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('El evento de pausa exige la justificación declarada.');
        }

        $actionNote = 'Pausa de SLA (Pendiente de Información).'
            . self::PAUSE_NOTE_CAUSE_MARKER . $category->label() . ' [' . $category->value . '].'
            . self::PAUSE_NOTE_REASON_MARKER . $normalizedReason;

        $this->insertDatedHistoryEvent(
            $incidentId,
            $userId,
            $fromStatus->value,
            IncidentStatus::PENDING_INFO->value,
            $actionNote,
            $pausedAt
        );
    }

    public function recordResumeEvent(
        int $incidentId,
        ?int $userId,
        IncidentStatus $targetStatus,
        int $pauseDurationSeconds,
        ?string $note,
        DateTimeImmutable $resumedAt
    ): void {
        if ($incidentId < 1) {
            throw new InvalidArgumentException('El evento de reanudación exige una incidencia persistida (id positivo).');
        }

        if ($pauseDurationSeconds < 0) {
            throw new InvalidArgumentException('La duración del intervalo de pausa no puede ser negativa.');
        }

        $actionNote = 'Reanudación desde "Pendiente de Información" a '
            . $targetStatus->label() . ' [' . $targetStatus->value . '].'
            . self::RESUME_NOTE_DURATION_MARKER . $pauseDurationSeconds . ' s.';

        $normalizedNote = $note !== null ? trim($note) : '';
        if ($normalizedNote !== '') {
            $actionNote .= self::RESUME_NOTE_NOTE_MARKER . $normalizedNote;
        }

        $this->insertDatedHistoryEvent(
            $incidentId,
            $userId,
            IncidentStatus::PENDING_INFO->value,
            $targetStatus->value,
            $actionNote,
            $resumedAt
        );
    }

    /**
     * Recupera las incidencias pausadas cuyo intervalo vivo supera el umbral en horas
     * naturales, de espera más larga a más corta (RF-04.2).
     *
     * @return list<array<string, mixed>>
     */
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array
    {
        if ($hours < 1) {
            throw new InvalidArgumentException('El umbral de inactividad debe ser de al menos 1 hora.');
        }

        $sql = "
            SELECT
                i.`id`,
                i.`ticket_code`,
                i.`status`,
                i.`urgency`,
                i.`location_id`,
                i.`machine_id`,
                i.`assigned_technician_id`,
                i.`paused_at`,
                i.`total_pending_info_seconds`,
                i.`pending_info_reason_category`,
                i.`pending_info_reason_text`,
                m.`code` AS `machine_code`,
                m.`machine_type`,
                l.`name` AS `location_name`
            FROM `incidents` i
            INNER JOIN `machines` m ON m.`id` = i.`machine_id`
            LEFT JOIN `locations` l ON l.`id` = i.`location_id`
            WHERE i.`status` = :pending_status
              AND i.`deleted_at` IS NULL
              AND i.`paused_at` IS NOT NULL
              AND i.`paused_at` <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
            ORDER BY i.`paused_at` ASC, i.`id` ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':pending_status', IncidentStatus::PENDING_INFO->value, PDO::PARAM_STR);
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $categoryValue = $row['pending_info_reason_category'] !== null
                ? (string)$row['pending_info_reason_category']
                : null;
            $category = $categoryValue !== null ? IncidentPauseReasonCategory::tryFrom($categoryValue) : null;

            return [
                'id' => (int)$row['id'],
                'ticket_code' => (string)$row['ticket_code'],
                'status' => (string)$row['status'],
                'urgency' => (string)$row['urgency'],
                'location_id' => (int)$row['location_id'],
                'location_name' => $row['location_name'] !== null ? (string)$row['location_name'] : null,
                'machine_id' => (int)$row['machine_id'],
                'machine_code' => (string)$row['machine_code'],
                'machine_type' => (string)$row['machine_type'],
                'assigned_technician_id' => $row['assigned_technician_id'] !== null ? (int)$row['assigned_technician_id'] : null,
                'paused_at' => (string)$row['paused_at'],
                'total_pending_info_seconds' => (int)$row['total_pending_info_seconds'],
                'pending_info_reason_category' => $categoryValue,
                'pending_info_reason_category_label' => $category?->label(),
                'pending_info_reason_text' => $row['pending_info_reason_text'] !== null
                    ? (string)$row['pending_info_reason_text']
                    : null,
            ];
        }, $rows);
    }

    /**
     * Inserta un evento de auditoría con marca temporal explícita (RNF-01).
     *
     * `insertHistory()` sella `created_at = CURRENT_TIMESTAMP` porque documenta el
     * instante de la transición en curso; los eventos de pausa y reanudación, en cambio,
     * reciben el instante exacto que el dominio calculó, de modo que el intervalo
     * auditado y el acumulador descontable miden lo mismo al segundo. Escritura de solo
     * adición: nunca actualiza ni borra filas previas (Art. III).
     */
    private function insertDatedHistoryEvent(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        string $actionNote,
        DateTimeImmutable $occurredAt
    ): int {
        $sql = "
            INSERT INTO `incident_history` (
                `incident_id`,
                `user_id`,
                `from_status`,
                `to_status`,
                `action_note`,
                `created_at`
            ) VALUES (
                :incident_id,
                :user_id,
                :from_status,
                :to_status,
                :action_note,
                :created_at
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':from_status', $fromStatus, $fromStatus !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':to_status', $toStatus, PDO::PARAM_STR);
        $stmt->bindValue(':action_note', $actionNote, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $occurredAt->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        // 1. Buscar todas las incidencias en estado RESOLVED con más de 48h desde resolved_at
        $sqlSelect = "
            SELECT id FROM `incidents`
            WHERE `status` = 'RESOLVED'
              AND `resolved_at` IS NOT NULL
              AND `resolved_at` <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
              AND `deleted_at` IS NULL
            ORDER BY `resolved_at` ASC
        ";

        $stmt = $this->pdo->prepare($sqlSelect);
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
        $stmt->execute();
        $idsToClose = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($idsToClose)) {
            return [];
        }

        $closedIncidents = [];

        foreach ($idsToClose as $incidentId) {
            $id = (int)$incidentId;
            $isOwnTx = !$this->pdo->inTransaction();
            if ($isOwnTx) {
                $this->pdo->beginTransaction();
            }

            try {
                // Actualizar a CLOSED
                $sqlUpdate = "
                    UPDATE `incidents`
                    SET `status` = 'CLOSED',
                        `closed_at` = CURRENT_TIMESTAMP
                    WHERE `id` = :id
                      AND `status` = 'RESOLVED'
                      AND `deleted_at` IS NULL
                ";
                $updateStmt = $this->pdo->prepare($sqlUpdate);
                $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $updateStmt->execute();

                // Registrar en historial inmutable
                $this->insertHistory(
                    $id,
                    null, // Automatismo del sistema (cron batch)
                    'RESOLVED',
                    'CLOSED',
                    "Cierre automático y archivado definitivo tras vencer la ventana de garantía de {$hours}h sin réplica."
                );

                if ($isOwnTx) {
                    $this->pdo->commit();
                }

                $closed = $this->findById($id);
                if ($closed !== null) {
                    $closedIncidents[] = $closed;
                }
            } catch (Throwable $e) {
                if ($isOwnTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                // Continuar con los demás si uno fallara
            }
        }

        return $closedIncidents;
    }
}



