<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use Throwable;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
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
                u.name AS technician_name
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
                `cancelled_at` = :cancelled_at
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
}
