<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoAuditLogRepository
 * 
 * Implementación de persistencia con PDO para el registro de auditoría inmutable (audit_log).
 * Da cumplimiento a RF-05 (EARS 5.3, 5.4, 5.5), RNF-03 y a los Artículos III.1, III.3 y V.1
 * de la Constitución de VendGuard.
 * 
 * Diseño Append-Only: No expone métodos de UPDATE ni DELETE físicos o lógicos.
 * Soporta almacenamiento estructurado en formato JSON nativo de MySQL/TiDB.
 */
class PdoAuditLogRepository implements AuditLogRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Inserta un nuevo evento de auditoría de forma atómica e inmutable (append-only).
     * 
     * @param AuditEvent $event
     * @return AuditEvent
     */
    public function log(AuditEvent $event): AuditEvent
    {
        $sql = "INSERT INTO `audit_log` 
                (`entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`, `previous_state`, `new_state`, `metadata`, `created_at`)
                VALUES 
                (:entity_type, :entity_id, :action, :user_id, :user_role, :user_name, :previous_state, :new_state, :metadata, :created_at)";

        try {
            $stmt = $this->pdo->prepare($sql);

            $stmt->bindValue(':entity_type', $event->getEntityType(), PDO::PARAM_STR);
            $stmt->bindValue(':entity_id', $event->getEntityId(), PDO::PARAM_INT);
            $stmt->bindValue(':action', $event->getAction(), PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $event->getUserId(), $event->getUserId() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':user_role', $event->getUserRole(), PDO::PARAM_STR);
            $stmt->bindValue(':user_name', $event->getUserName(), PDO::PARAM_STR);
            
            $prevStateJson = $event->getPreviousState() !== null ? json_encode($event->getPreviousState(), JSON_UNESCAPED_UNICODE) : null;
            $stmt->bindValue(':previous_state', $prevStateJson, $prevStateJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

            $newStateJson = json_encode($event->getNewState(), JSON_UNESCAPED_UNICODE);
            $stmt->bindValue(':new_state', $newStateJson, PDO::PARAM_STR);

            $metaJson = $event->getMetadata() !== null ? json_encode($event->getMetadata(), JSON_UNESCAPED_UNICODE) : null;
            $stmt->bindValue(':metadata', $metaJson, $metaJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

            $stmt->bindValue(':created_at', $event->getCreatedAt(), PDO::PARAM_STR);

            $stmt->execute();
            $newId = (int)$this->pdo->lastInsertId();

            return new AuditEvent(
                id: $newId,
                entityType: $event->getEntityType(),
                entityId: $event->getEntityId(),
                action: $event->getAction(),
                userId: $event->getUserId(),
                userRole: $event->getUserRole(),
                userName: $event->getUserName(),
                previousState: $event->getPreviousState(),
                newState: $event->getNewState(),
                metadata: $event->getMetadata(),
                createdAt: $event->getCreatedAt()
            );
        } catch (PDOException $e) {
            throw new RuntimeException("Error al registrar evento de auditoría: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Consulta cronológica paginada y filtrable del registro de auditoría (EARS 5.5).
     * 
     * @param array<string, mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return list<AuditEvent>
     */
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$whereClauses, $params] = $this->buildWhereClauses($filters);

        $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        $sql = "SELECT `id`, `entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`, 
                       `previous_state`, `new_state`, `metadata`, `created_at`
                FROM `audit_log`
                {$whereSql}
                ORDER BY `created_at` DESC, `id` DESC
                LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $paramKey => $paramVal) {
                $stmt->bindValue($paramKey, $paramVal);
            }
            $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);

            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $events = [];
            foreach ($rows as $row) {
                $events[] = $this->hydrateRow($row);
            }

            return $events;
        } catch (PDOException $e) {
            throw new RuntimeException("Error al consultar el registro de auditoría: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Obtiene el conteo total de registros que coinciden con los filtros aplicados.
     * 
     * @param array<string, mixed> $filters
     * @return int
     */
    public function countEvents(array $filters = []): int
    {
        [$whereClauses, $params] = $this->buildWhereClauses($filters);

        $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        $sql = "SELECT COUNT(*) FROM `audit_log` {$whereSql}";

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $paramKey => $paramVal) {
                $stmt->bindValue($paramKey, $paramVal);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new RuntimeException("Error al contar eventos de auditoría: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Obtiene el historial de auditoría de una entidad específica ordenado cronológicamente.
     * 
     * @param string $entityType
     * @param int $entityId
     * @return list<AuditEvent>
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        $sql = "SELECT `id`, `entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`, 
                       `previous_state`, `new_state`, `metadata`, `created_at`
                FROM `audit_log`
                WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id
                ORDER BY `created_at` ASC, `id` ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
            $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = [];
            foreach ($rows as $row) {
                $events[] = $this->hydrateRow($row);
            }

            return $events;
        } catch (PDOException $e) {
            throw new RuntimeException("Error al consultar auditoría de entidad: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function buildWhereClauses(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['entity_type'])) {
            $where[] = '`entity_type` = :entity_type';
            $params[':entity_type'] = (string)$filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[] = '`entity_id` = :entity_id';
            $params[':entity_id'] = (int)$filters['entity_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = '`action` = :action';
            $params[':action'] = (string)$filters['action'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = '`user_id` = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['from'])) {
            $fromStr = (string)$filters['from'];
            if (strlen($fromStr) === 10) {
                $fromStr .= ' 00:00:00';
            }
            $where[] = '`created_at` >= :from_date';
            $params[':from_date'] = $fromStr;
        }

        if (!empty($filters['to'])) {
            $toStr = (string)$filters['to'];
            if (strlen($toStr) === 10) {
                $toStr .= ' 23:59:59';
            }
            $where[] = '`created_at` <= :to_date';
            $params[':to_date'] = $toStr;
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return AuditEvent
     */
    private function hydrateRow(array $row): AuditEvent
    {
        $prevState = null;
        if (!empty($row['previous_state'])) {
            $decoded = json_decode((string)$row['previous_state'], true);
            if (is_array($decoded)) {
                $prevState = $decoded;
            }
        }

        $newState = [];
        if (!empty($row['new_state'])) {
            $decoded = json_decode((string)$row['new_state'], true);
            if (is_array($decoded)) {
                $newState = $decoded;
            }
        }

        $metadata = null;
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string)$row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return new AuditEvent(
            id: (int)$row['id'],
            entityType: (string)$row['entity_type'],
            entityId: (int)$row['entity_id'],
            action: (string)$row['action'],
            userId: $row['user_id'] !== null ? (int)$row['user_id'] : null,
            userRole: (string)$row['user_role'],
            userName: (string)$row['user_name'],
            previousState: $prevState,
            newState: $newState,
            metadata: $metadata,
            createdAt: (string)$row['created_at']
        );
    }
}
