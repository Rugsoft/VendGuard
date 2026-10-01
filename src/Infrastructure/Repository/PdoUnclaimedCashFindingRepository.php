<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;
use VendGuard\Core\Domain\Repository\UnclaimedCashFindingRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PDO persistence for cash recovered on site with no prior consumer claim.
 */
final class PdoUnclaimedCashFindingRepository implements UnclaimedCashFindingRepositoryInterface
{
    private const COLUMNS = '`id`, `incident_id`, `machine_id`, `technician_id`,
        `amount`, `notes`, `created_at`';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    public function insert(UnclaimedCashFinding $finding): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO `unclaimed_cash_findings` (
                `incident_id`, `machine_id`, `technician_id`, `amount`, `notes`, `created_at`
            ) VALUES (
                :incident_id, :machine_id, :technician_id, :amount, :notes, :created_at
            )
        ");

        $stmt->execute([
            ':incident_id' => $finding->getIncidentId(),
            ':machine_id' => $finding->getMachineId(),
            ':technician_id' => $finding->getTechnicianId(),
            ':amount' => $finding->getAmount(),
            ':notes' => $finding->getNotes(),
            ':created_at' => $finding->getCreatedAt(),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?UnclaimedCashFinding
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . '
            FROM `unclaimed_cash_findings`
            WHERE `id` = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIncident(int $incidentId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . '
            FROM `unclaimed_cash_findings`
            WHERE `incident_id` = :incident_id
            ORDER BY `created_at` DESC, `id` DESC
        ');
        $stmt->execute([':incident_id' => $incidentId]);

        return $this->hydrateAll($stmt);
    }

    public function findByTechnician(int $technicianId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . '
            FROM `unclaimed_cash_findings`
            WHERE `technician_id` = :technician_id
            ORDER BY `created_at` DESC, `id` DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UnclaimedCashFinding
    {
        return new UnclaimedCashFinding(
            id: (int)$row['id'],
            incidentId: (int)$row['incident_id'],
            machineId: (int)$row['machine_id'],
            technicianId: (int)$row['technician_id'],
            amount: (float)$row['amount'],
            notes: (string)($row['notes'] ?? ''),
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @return list<UnclaimedCashFinding>
     */
    private function hydrateAll(PDOStatement $stmt): array
    {
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }
}
