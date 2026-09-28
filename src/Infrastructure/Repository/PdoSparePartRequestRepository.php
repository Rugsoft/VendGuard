<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;

/**
 * PdoSparePartRequestRepository
 * 
 * Implementación PDO del repositorio de solicitudes estructuradas de repuesto.
 * Registra solicitudes durante pausas técnicas (RF-REP-03 / RF-REP-04) y gestiona
 * transiciones atómicas de ciclo de vida sin borrado físico (Art. III).
 */
class PdoSparePartRequestRepository implements SparePartRequestRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function createRequest(SparePartRequest $request): SparePartRequest
    {
        $sql = "
            INSERT INTO `spare_part_requests` (
                `incident_id`,
                `spare_part_id`,
                `is_out_of_catalog`,
                `custom_part_description`,
                `quantity`,
                `status`,
                `requested_by_user_id`,
                `created_at`,
                `updated_at`
            ) VALUES (
                :incident_id,
                :spare_part_id,
                :is_out_of_catalog,
                :custom_part_description,
                :quantity,
                :status,
                :requested_by_user_id,
                NOW(),
                NOW()
            )
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':incident_id'              => $request->getIncidentId(),
                ':spare_part_id'            => $request->getSparePartId(),
                ':is_out_of_catalog'        => $request->isOutOfCatalog() ? 1 : 0,
                ':custom_part_description'  => $request->getCustomPartDescription(),
                ':quantity'                 => $request->getQuantity(),
                ':status'                   => $request->getStatus()->value,
                ':requested_by_user_id'     => $request->getRequestedByUserId(),
            ]);

            $id = (int)$this->pdo->lastInsertId();

            return $this->findById($id) ?? new SparePartRequest(
                $id,
                $request->getIncidentId(),
                $request->getSparePartId(),
                $request->isOutOfCatalog(),
                $request->getCustomPartDescription(),
                $request->getQuantity(),
                $request->getStatus(),
                $request->getRequestedByUserId(),
                $request->getPartCode(),
                $request->getPartName(),
                $request->getTechnicianName(),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            );
        } catch (PDOException $e) {
            throw new RuntimeException("Error al registrar solicitud de repuesto: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?SparePartRequest
    {
        $sql = "
            SELECT 
                spr.*,
                sp.part_code,
                sp.name AS part_name,
                u.name AS technician_name
            FROM `spare_part_requests` spr
            LEFT JOIN `spare_parts` sp ON sp.id = spr.spare_part_id
            LEFT JOIN `users` u ON u.id = spr.requested_by_user_id
            WHERE spr.id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateRequest($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByIncidentId(int $incidentId): array
    {
        $sql = "
            SELECT 
                spr.*,
                sp.part_code,
                sp.name AS part_name,
                u.name AS technician_name
            FROM `spare_part_requests` spr
            LEFT JOIN `spare_parts` sp ON sp.id = spr.spare_part_id
            LEFT JOIN `users` u ON u.id = spr.requested_by_user_id
            WHERE spr.incident_id = :incident_id
            ORDER BY spr.created_at ASC, spr.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->hydrateRequest($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function markAttendedByIncident(int $incidentId): int
    {
        $sql = "
            UPDATE `spare_part_requests`
            SET 
                `status` = 'ATTENDED',
                `updated_at` = NOW()
            WHERE `incident_id` = :incident_id
              AND `status` = 'PENDING'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function markCancelledByIncident(int $incidentId): int
    {
        $sql = "
            UPDATE `spare_part_requests`
            SET 
                `status` = 'CANCELLED',
                `updated_at` = NOW()
            WHERE `incident_id` = :incident_id
              AND `status` = 'PENDING'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':incident_id' => $incidentId]);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function findPendingOutOfCatalogReviews(): array
    {
        $sql = "
            SELECT 
                spr.id AS request_id,
                spr.incident_id,
                inc.ticket_code,
                m.code AS machine_code,
                m.model AS machine_model,
                loc.name AS location_name,
                u.name AS technician_name,
                spr.custom_part_description,
                spr.created_at AS requested_at,
                inc.status AS incident_status
            FROM `spare_part_requests` spr
            INNER JOIN `incidents` inc ON inc.id = spr.incident_id
            INNER JOIN `machines` m ON m.id = inc.machine_id
            INNER JOIN `locations` loc ON loc.id = m.location_id
            LEFT JOIN `users` u ON u.id = spr.requested_by_user_id
            WHERE spr.is_out_of_catalog = 1
              AND spr.status = 'PENDING'
            ORDER BY spr.created_at ASC
        ";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hidrata una entidad SparePartRequest a partir de los datos relacionales de BD.
     *
     * @param array<string, mixed> $row
     * @return SparePartRequest
     */
    private function hydrateRequest(array $row): SparePartRequest
    {
        return new SparePartRequest(
            (int)$row['id'],
            (int)$row['incident_id'],
            $row['spare_part_id'] !== null ? (int)$row['spare_part_id'] : null,
            (bool)$row['is_out_of_catalog'],
            $row['custom_part_description'] !== null ? (string)$row['custom_part_description'] : null,
            (int)$row['quantity'],
            SparePartRequestStatus::from((string)$row['status']),
            (int)$row['requested_by_user_id'],
            $row['part_code'] !== null ? (string)$row['part_code'] : null,
            $row['part_name'] !== null ? (string)$row['part_name'] : null,
            $row['technician_name'] !== null ? (string)$row['technician_name'] : null,
            (string)$row['created_at'],
            (string)$row['updated_at']
        );
    }
}
