<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CoordinatorDecision;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PDO persistence for consumer refund cases.
 *
 * The two column lists below are the whole Art. V.4 story: the restricted
 * projection never names `iban` or `bizum_phone`, so those values are not even
 * fetched from the server for the site desk or the field technician. Filtering
 * them out after the fetch would leave them in memory and in any debug dump.
 */
final class PdoRefundRequestRepository implements RefundRequestRepositoryInterface
{
    /** Full projection: Coordination only. */
    private const FULL_COLUMNS = 'r.`id`, r.`incident_id`, r.`machine_id`, r.`location_id`,
        r.`claimant_name`, r.`claimant_contact`, r.`claimed_amount`, r.`product_attempted`,
        r.`compensation_method`, r.`bizum_phone`, r.`iban`, r.`pickup_pin`, r.`tracking_token`,
        r.`status`, r.`technician_finding`, r.`recovered_amount`, r.`cash_custody_action`,
        r.`receptionist_name`, r.`technician_justification`, r.`approved_amount`,
        r.`payment_reference`, r.`coordinator_decision`, r.`coordinator_justification`,
        r.`paid_at`, r.`is_active`, r.`created_at`, r.`updated_at`';

    /** Restricted projection: never selects the financial columns (Art. V.4). */
    private const RESTRICTED_COLUMNS = 'r.`id`, r.`incident_id`, r.`machine_id`, r.`location_id`,
        r.`claimant_name`, r.`claimant_contact`, r.`claimed_amount`, r.`product_attempted`,
        r.`compensation_method`, r.`pickup_pin`, r.`tracking_token`,
        r.`status`, r.`technician_finding`, r.`recovered_amount`, r.`cash_custody_action`,
        r.`receptionist_name`, r.`technician_justification`, r.`approved_amount`,
        r.`payment_reference`, r.`is_active`, r.`created_at`, r.`updated_at`';

    /** Columns a transition is allowed to write. Anything else is ignored. */
    private const WRITABLE_FIELDS = [
        'technician_finding',
        'recovered_amount',
        'cash_custody_action',
        'receptionist_name',
        'technician_justification',
        'approved_amount',
        'payment_reference',
        'paid_at',
        'hand_delivered_at',
        'coordinator_decision',
        'coordinator_justification',
        'technician_id',
        'coordinator_id',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    public function insert(RefundRequest $refundRequest): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO `refund_requests` (
                `incident_id`, `machine_id`, `location_id`,
                `claimant_name`, `claimant_contact`, `claimed_amount`, `product_attempted`,
                `compensation_method`, `bizum_phone`, `iban`, `pickup_pin`, `tracking_token`,
                `status`, `technician_finding`, `recovered_amount`, `cash_custody_action`,
                `receptionist_name`, `technician_justification`, `approved_amount`,
                `payment_reference`, `is_active`
            ) VALUES (
                :incident_id, :machine_id, :location_id,
                :claimant_name, :claimant_contact, :claimed_amount, :product_attempted,
                :compensation_method, :bizum_phone, :iban, :pickup_pin, :tracking_token,
                :status, :technician_finding, :recovered_amount, :cash_custody_action,
                :receptionist_name, :technician_justification, :approved_amount,
                :payment_reference, :is_active
            )
        ");

        $stmt->execute([
            ':incident_id' => $refundRequest->getIncidentId(),
            ':machine_id' => $refundRequest->getMachineId(),
            ':location_id' => $refundRequest->getLocationId(),
            ':claimant_name' => $refundRequest->getClaimantName(),
            ':claimant_contact' => $refundRequest->getClaimantContact(),
            ':claimed_amount' => $refundRequest->getClaimedAmount(),
            ':product_attempted' => $refundRequest->getProductAttempted(),
            ':compensation_method' => $refundRequest->getCompensationMethod()->value,
            ':bizum_phone' => $refundRequest->getBizumPhone(),
            ':iban' => $refundRequest->getIban(),
            ':pickup_pin' => $refundRequest->getPickupPin(),
            ':tracking_token' => $refundRequest->getTrackingToken(),
            ':status' => $refundRequest->getStatus()->value,
            ':technician_finding' => $refundRequest->getTechnicianFinding()?->value,
            ':recovered_amount' => $refundRequest->getRecoveredAmount(),
            ':cash_custody_action' => $refundRequest->getCashCustodyAction()?->value,
            ':receptionist_name' => $refundRequest->getReceptionistName(),
            ':technician_justification' => $refundRequest->getTechnicianJustification(),
            ':approved_amount' => $refundRequest->getApprovedAmount(),
            ':payment_reference' => $refundRequest->getPaymentReference(),
            ':is_active' => $refundRequest->isActive() ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?RefundRequest
    {
        $stmt = $this->pdo->prepare("
            SELECT " . self::FULL_COLUMNS . "
            FROM `refund_requests` r
            WHERE r.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateFull($row);
    }

    public function findByTrackingToken(string $trackingToken): ?RefundRequest
    {
        $stmt = $this->pdo->prepare("
            SELECT " . self::RESTRICTED_COLUMNS . "
            FROM `refund_requests` r
            WHERE r.`tracking_token` = :token
              AND r.`is_active` = 1
            LIMIT 1
        ");
        $stmt->execute([':token' => $trackingToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Anonymous link holders must not receive the financial columns.
        return $row === false ? null : RefundRequest::fromRestrictedProjection($row);
    }

    public function findRestrictedByIncident(int $incidentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT " . self::RESTRICTED_COLUMNS . "
            FROM `refund_requests` r
            WHERE r.`incident_id` = :incident_id
              AND r.`is_active` = 1
            ORDER BY r.`created_at` DESC, r.`id` DESC
        ");
        $stmt->execute([':incident_id' => $incidentId]);

        return $this->hydrateRestrictedAll($stmt);
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        $sql = "
            SELECT " . self::RESTRICTED_COLUMNS . "
            FROM `refund_requests` r
            WHERE r.`location_id` = :location_id
              AND r.`is_active` = 1
        ";
        $params = [':location_id' => $locationId];

        if ($status !== null) {
            $sql .= ' AND r.`status` = :status';
            $params[':status'] = $status->value;
        }

        $sql .= ' ORDER BY r.`created_at` DESC, r.`id` DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateRestrictedAll($stmt);
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildCoordinatorFilters($filters);

        $stmt = $this->pdo->prepare("
            SELECT " . self::FULL_COLUMNS . "
            FROM `refund_requests` r
            {$where}
            ORDER BY r.`created_at` DESC, r.`id` DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrateFull($row);
        }

        return $result;
    }

    public function countForCoordinator(array $filters = []): int
    {
        [$where, $params] = $this->buildCoordinatorFilters($filters);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `refund_requests` r {$where}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function transitionStatus(
        int $id,
        RefundStatus $expectedStatus,
        RefundStatus $newStatus,
        array $fields = []
    ): bool {
        $assignments = ['`status` = :new_status'];
        $params = [
            ':id' => $id,
            ':expected_status' => $expectedStatus->value,
            ':new_status' => $newStatus->value,
        ];

        // Only an allow-listed column can ever reach the UPDATE, so a caller
        // cannot smuggle `iban` or `is_active` through this method.
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::WRITABLE_FIELDS, true)) {
                continue;
            }

            $assignments[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value;
        }

        $assignments[] = '`updated_at` = CURRENT_TIMESTAMP';

        $sql = 'UPDATE `refund_requests`
                SET ' . implode(', ', $assignments) . '
                WHERE `id` = :id
                  AND `status` = :expected_status
                  AND `is_active` = 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        $assignments = [];
        $params = [':id' => $id];

        if ($bizumPhone !== null) {
            $assignments[] = '`bizum_phone` = :bizum_phone';
            $params[':bizum_phone'] = $bizumPhone;
        }

        if ($iban !== null) {
            $assignments[] = '`iban` = :iban';
            $params[':iban'] = $iban;
        }

        if ($assignments === []) {
            return false;
        }

        $assignments[] = '`updated_at` = CURRENT_TIMESTAMP';

        $stmt = $this->pdo->prepare('
            UPDATE `refund_requests`
            SET ' . implode(', ', $assignments) . '
            WHERE `id` = :id
              AND `status` = :status
              AND `is_active` = 1
        ');
        $params[':status'] = RefundStatus::PENDING_CONTACT->value;
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    public function deactivate(int $id): bool
    {
        // Art. III: logical cancellation only, the row and its history remain.
        $stmt = $this->pdo->prepare("
            UPDATE `refund_requests`
            SET `is_active` = 0,
                `deleted_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ");
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildCoordinatorFilters(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $clauses[] = 'r.`status` = :status';
            $params[':status'] = (string)$filters['status'];
        }

        if (isset($filters['location_id']) && (int)$filters['location_id'] > 0) {
            $clauses[] = 'r.`location_id` = :location_id';
            $params[':location_id'] = (int)$filters['location_id'];
        }

        if (isset($filters['machine_id']) && (int)$filters['machine_id'] > 0) {
            $clauses[] = 'r.`machine_id` = :machine_id';
            $params[':machine_id'] = (int)$filters['machine_id'];
        }

        if (isset($filters['from']) && $filters['from'] !== '') {
            $clauses[] = 'r.`created_at` >= :from';
            $params[':from'] = (string)$filters['from'];
        }

        if (isset($filters['to']) && $filters['to'] !== '') {
            $clauses[] = 'r.`created_at` <= :to';
            $params[':to'] = (string)$filters['to'];
        }

        $clauses[] = 'r.`is_active` = 1';

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateFull(array $row): RefundRequest
    {
        $nullable = static fn (string $key): ?string => isset($row[$key]) ? (string)$row[$key] : null;

        return new RefundRequest(
            id: (int)$row['id'],
            incidentId: (int)$row['incident_id'],
            machineId: (int)$row['machine_id'],
            locationId: (int)$row['location_id'],
            claimantName: (string)$row['claimant_name'],
            claimantContact: (string)$row['claimant_contact'],
            claimedAmount: (float)$row['claimed_amount'],
            productAttempted: (string)($row['product_attempted'] ?? ''),
            compensationMethod: CompensationMethod::from((string)$row['compensation_method']),
            bizumPhone: $nullable('bizum_phone'),
            iban: $nullable('iban'),
            pickupPin: $nullable('pickup_pin'),
            trackingToken: (string)$row['tracking_token'],
            status: RefundStatus::from((string)$row['status']),
            technicianFinding: isset($row['technician_finding']) && $row['technician_finding'] !== null
                ? TechnicianFinding::from((string)$row['technician_finding'])
                : null,
            recoveredAmount: isset($row['recovered_amount']) && $row['recovered_amount'] !== null
                ? (float)$row['recovered_amount']
                : null,
            cashCustodyAction: isset($row['cash_custody_action']) && $row['cash_custody_action'] !== null
                ? CashCustodyAction::from((string)$row['cash_custody_action'])
                : null,
            receptionistName: $nullable('receptionist_name'),
            technicianJustification: $nullable('technician_justification'),
            approvedAmount: isset($row['approved_amount']) && $row['approved_amount'] !== null
                ? (float)$row['approved_amount']
                : null,
            paymentReference: $nullable('payment_reference'),
            coordinatorDecision: isset($row['coordinator_decision']) && $row['coordinator_decision'] !== null
                ? CoordinatorDecision::from((string)$row['coordinator_decision'])
                : null,
            coordinatorJustification: $nullable('coordinator_justification'),
            paidAt: $nullable('paid_at'),
            isActive: (int)($row['is_active'] ?? 1) === 1,
            createdAt: $nullable('created_at'),
            updatedAt: $nullable('updated_at'),
        );
    }

    /**
     * @return list<RefundRequest>
     */
    private function hydrateRestrictedAll(PDOStatement $stmt): array
    {
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = RefundRequest::fromRestrictedProjection($row);
        }

        return $result;
    }
}
