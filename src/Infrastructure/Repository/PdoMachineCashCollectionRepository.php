<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Model\MachineCashCollection;
use VendGuard\Core\Domain\Repository\MachineCashCollectionRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineCashCollectionRepository
 *
 * Persistencia PDO del libro de recaudaciones y arqueos (Módulo 35, Tabla 49
 * `machine_cash_collections`; RF-01.1–RF-01.3, RF-02.2, RF-04.1, RF-04.2,
 * RF-05.2, RF-05.3, D1, D2, D4, C1, QA 1, QA 3, Art. III.1, Art. III.3,
 * Art. V.4).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Historia inmutable (Art. III.3).** No hay `DELETE` ni reescritura de
 *      la captura en ruta: solo el `INSERT` y las dos transiciones de custodia.
 *      El motor lo blinda con `enforce_machine_cash_collections_immutability()`
 *      y `prevent_hard_delete()` (Art. III.1).
 *   2. **Idempotencia y precinto (D4, C1).** `findByClientMutationId()` resuelve
 *      la repetición de la cola offline y `findByBagSealCode()` detecta el
 *      precinto consumido; los índices únicos del motor cierran la carrera.
 *   3. **Arqueo Ciego estructural (C2, Art. V.4).** El repositorio devuelve
 *      entidades hidratadas, no arrays financieros; cada superficie elige su
 *      proyección y la del reponedor no contiene las cinco claves privadas.
 *   4. **Seis KPIs deterministas (QA 3).** `listForCoordinator()` agrega bolsas
 *      pendientes (en ruta o en cámara), arqueos conformes, descuadres, efectivo
 *      contado, descuadre neto y cambio/conserjería movilizados con los filtros
 *      aplicados.
 *   5. **Resumen opaco de ruta (QA 3).** `summarizeForRestocker()` solo suma lo
 *      que declaró el reponedor y cuenta sus bolsas pendientes de cámara.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineCashCollectionRepository implements MachineCashCollectionRepositoryInterface
{
    /** Tope por defecto y máximo del listado histórico. */
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    private const COLLECTION_SELECT = "
        c.id,
        c.collection_code,
        c.restock_visit_id,
        c.machine_id,
        c.machine_code_snapshot,
        c.restocker_id,
        c.restocker_name_snapshot,
        c.restocker_code_snapshot,
        c.bag_seal_code,
        c.change_tubes_loaded_eur,
        c.sales_counter_reading,
        c.previous_sales_counter_reading,
        c.initial_baseline_reading_eur,
        c.is_counter_reset,
        c.counter_reset_notes,
        c.expected_cashbox_eur,
        c.reception_cash_delivered_eur,
        c.receptionist_name,
        c.linked_refund_request_id,
        c.route_notes,
        c.status,
        c.collected_at,
        c.delivered_to_vault_at,
        c.delivered_to_vault_by,
        c.vault_counted_eur,
        c.discrepancy_eur,
        c.vault_reconciled_by,
        c.vault_reconciled_by_name_snapshot,
        c.vault_reconciled_at,
        c.discrepancy_justification,
        c.client_mutation_id,
        c.created_at,
        c.updated_at,
        l.name AS location_name,
        l.site_code AS location_code
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function insert(MachineCashCollection $collection): MachineCashCollection
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO machine_cash_collections (
                collection_code,
                restock_visit_id,
                machine_id,
                machine_code_snapshot,
                restocker_id,
                restocker_name_snapshot,
                restocker_code_snapshot,
                bag_seal_code,
                change_tubes_loaded_eur,
                sales_counter_reading,
                previous_sales_counter_reading,
                initial_baseline_reading_eur,
                is_counter_reset,
                counter_reset_notes,
                expected_cashbox_eur,
                reception_cash_delivered_eur,
                receptionist_name,
                linked_refund_request_id,
                route_notes,
                status,
                collected_at,
                client_mutation_id
            ) VALUES (
                :collection_code,
                :restock_visit_id,
                :machine_id,
                :machine_code_snapshot,
                :restocker_id,
                :restocker_name_snapshot,
                :restocker_code_snapshot,
                :bag_seal_code,
                :change_tubes_loaded_eur,
                :sales_counter_reading,
                :previous_sales_counter_reading,
                :initial_baseline_reading_eur,
                :is_counter_reset,
                :counter_reset_notes,
                :expected_cashbox_eur,
                :reception_cash_delivered_eur,
                :receptionist_name,
                :linked_refund_request_id,
                :route_notes,
                :status,
                CAST(:collected_at AS timestamptz),
                :client_mutation_id
            )
            RETURNING id
        ");

        $stmt->bindValue(':collection_code', $collection->getCollectionCode(), PDO::PARAM_STR);
        $this->bindNullableInt($stmt, ':restock_visit_id', $collection->getRestockVisitId());
        $stmt->bindValue(':machine_id', $collection->getMachineId(), PDO::PARAM_INT);
        $stmt->bindValue(':machine_code_snapshot', $collection->getMachineCodeSnapshot(), PDO::PARAM_STR);
        $stmt->bindValue(':restocker_id', $collection->getRestockerId(), PDO::PARAM_INT);
        $stmt->bindValue(':restocker_name_snapshot', $collection->getRestockerNameSnapshot(), PDO::PARAM_STR);
        $stmt->bindValue(':restocker_code_snapshot', $collection->getRestockerCodeSnapshot(), PDO::PARAM_STR);
        $stmt->bindValue(':bag_seal_code', $collection->getBagSealCode(), PDO::PARAM_STR);
        $stmt->bindValue(':change_tubes_loaded_eur', $collection->getChangeTubesLoadedEur(), PDO::PARAM_STR);
        $stmt->bindValue(':sales_counter_reading', $collection->getSalesCounterReading(), PDO::PARAM_STR);
        $this->bindNullableFloat($stmt, ':previous_sales_counter_reading', $collection->getPreviousSalesCounterReading());
        $stmt->bindValue(':initial_baseline_reading_eur', $collection->getInitialBaselineReadingEur(), PDO::PARAM_STR);
        $stmt->bindValue(':is_counter_reset', $collection->isCounterReset(), PDO::PARAM_BOOL);
        $this->bindNullableText($stmt, ':counter_reset_notes', $collection->getCounterResetNotes());
        $stmt->bindValue(':expected_cashbox_eur', $collection->getExpectedCashboxEur(), PDO::PARAM_STR);
        $stmt->bindValue(':reception_cash_delivered_eur', $collection->getReceptionCashDeliveredEur(), PDO::PARAM_STR);
        $this->bindNullableText($stmt, ':receptionist_name', $collection->getReceptionistName());
        $this->bindNullableInt($stmt, ':linked_refund_request_id', $collection->getLinkedRefundRequestId());
        $this->bindNullableText($stmt, ':route_notes', $collection->getRouteNotes());
        $stmt->bindValue(':status', $collection->getStatus()->value, PDO::PARAM_STR);
        $stmt->bindValue(':collected_at', $collection->getCollectedAt(), PDO::PARAM_STR);
        $stmt->bindValue(':client_mutation_id', $collection->getClientMutationId(), PDO::PARAM_STR);
        $stmt->execute();

        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            throw new \RuntimeException('La recaudación se insertó pero el motor no devolvió su identificador.');
        }

        return $this->findById($id) ?? $collection->withId($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $collectionId): ?MachineCashCollection
    {
        if ($collectionId <= 0) {
            return null;
        }

        return $this->findOneBy('c.id = :collection_id', [':collection_id' => $collectionId]);
    }

    /**
     * {@inheritdoc}
     */
    public function findByClientMutationId(int $restockerId, string $clientMutationId): ?MachineCashCollection
    {
        $mutationId = trim($clientMutationId);
        if ($restockerId <= 0 || $mutationId === '') {
            return null;
        }

        return $this->findOneBy(
            'c.restocker_id = :restocker_id AND c.client_mutation_id = :client_mutation_id',
            [':restocker_id' => $restockerId, ':client_mutation_id' => $mutationId]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByBagSealCode(string $bagSealCode): ?MachineCashCollection
    {
        $seal = strtoupper(trim($bagSealCode));
        if ($seal === '') {
            return null;
        }

        return $this->findOneBy('c.bag_seal_code = :bag_seal_code', [':bag_seal_code' => $seal]);
    }

    /**
     * {@inheritdoc}
     */
    public function findLastForMachine(int $machineId): ?MachineCashCollection
    {
        if ($machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLLECTION_SELECT
            . ' FROM machine_cash_collections c'
            . ' INNER JOIN machines m ON m.id = c.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . ' WHERE c.machine_id = :machine_id'
            . ' ORDER BY c.collected_at DESC, c.id DESC'
            . ' LIMIT 1'
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * {@inheritdoc}
     */
    public function listForRestocker(int $restockerId, array $filters = []): array
    {
        if ($restockerId <= 0) {
            return [];
        }

        $params = [':restocker_id' => $restockerId];
        $where = ' WHERE c.restocker_id = :restocker_id' . $this->buildFilterClause($filters, $params);
        [$limit, $offset] = $this->limitAndOffset($filters);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLLECTION_SELECT
            . ' FROM machine_cash_collections c'
            . ' INNER JOIN machines m ON m.id = c.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . $where
            . ' ORDER BY c.collected_at DESC, c.id DESC'
            . ' LIMIT :limit OFFSET :offset'
        );
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): MachineCashCollection => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function listForCoordinator(array $filters = []): array
    {
        $params = [];
        $where = ' WHERE 1 = 1' . $this->buildFilterClause($filters, $params);
        [$limit, $offset] = $this->limitAndOffset($filters);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLLECTION_SELECT
            . ' FROM machine_cash_collections c'
            . ' INNER JOIN machines m ON m.id = c.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . $where
            . ' ORDER BY c.collected_at DESC, c.id DESC'
            . ' LIMIT :limit OFFSET :offset'
        );
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $collections = array_map(
            fn (array $row): MachineCashCollection => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $kpiStmt = $this->pdo->prepare("
            SELECT
                COUNT(*) FILTER (
                    WHERE c.status IN ('SEALED_IN_ROUTE', 'DELIVERED_TO_VAULT')
                ) AS pending_vault_bags_count,
                COUNT(*) FILTER (WHERE c.status = 'RECONCILED_OK') AS reconciled_ok_count,
                COUNT(*) FILTER (WHERE c.status = 'DISCREPANCY_FLAGGED') AS discrepancy_flagged_count,
                COALESCE(SUM(c.vault_counted_eur) FILTER (
                    WHERE c.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED')
                ), 0) AS total_vault_counted_eur,
                COALESCE(SUM(c.discrepancy_eur) FILTER (
                    WHERE c.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED')
                ), 0) AS net_discrepancy_eur,
                COALESCE(SUM(c.change_tubes_loaded_eur + c.reception_cash_delivered_eur), 0)
                    AS total_route_cash_deployed_eur
            FROM machine_cash_collections c
            {$where}
        ");
        $this->bindParams($kpiStmt, $params);
        $kpiStmt->execute();
        $kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'collections' => $collections,
            'kpis' => [
                'pending_vault_bags_count' => (int)($kpiRow['pending_vault_bags_count'] ?? 0),
                'reconciled_ok_count' => (int)($kpiRow['reconciled_ok_count'] ?? 0),
                'discrepancy_flagged_count' => (int)($kpiRow['discrepancy_flagged_count'] ?? 0),
                'total_vault_counted_eur' => round((float)($kpiRow['total_vault_counted_eur'] ?? 0), 2),
                'net_discrepancy_eur' => round((float)($kpiRow['net_discrepancy_eur'] ?? 0), 2),
                'total_route_cash_deployed_eur' => round((float)($kpiRow['total_route_cash_deployed_eur'] ?? 0), 2),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function summarizeForRestocker(int $restockerId, array $filters = []): array
    {
        if ($restockerId <= 0) {
            return [
                'total_collections_count' => 0,
                'total_change_tubes_loaded_eur' => 0.0,
                'total_reception_cash_delivered_eur' => 0.0,
                'pending_vault_bags_count' => 0,
                'last_collected_at' => null,
            ];
        }

        $params = [':restocker_id' => $restockerId];
        $where = ' WHERE c.restocker_id = :restocker_id' . $this->buildFilterClause($filters, $params);

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_collections_count,
                COALESCE(SUM(c.change_tubes_loaded_eur), 0) AS total_change_tubes_loaded_eur,
                COALESCE(SUM(c.reception_cash_delivered_eur), 0) AS total_reception_cash_delivered_eur,
                MAX(c.collected_at) AS last_collected_at
            FROM machine_cash_collections c
            {$where}
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pendingStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM machine_cash_collections c
            WHERE c.restocker_id = :restocker_id
              AND c.status = 'SEALED_IN_ROUTE'
        ");
        $pendingStmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $pendingStmt->execute();

        return [
            'total_collections_count' => (int)($row['total_collections_count'] ?? 0),
            'total_change_tubes_loaded_eur' => round((float)($row['total_change_tubes_loaded_eur'] ?? 0), 2),
            'total_reception_cash_delivered_eur' => round((float)($row['total_reception_cash_delivered_eur'] ?? 0), 2),
            'pending_vault_bags_count' => (int)$pendingStmt->fetchColumn(),
            'last_collected_at' => $row['last_collected_at'] !== null ? (string)$row['last_collected_at'] : null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function markDeliveredToVault(
        int $collectionId,
        int $coordinatorId,
        ?string $deliveredAt = null
    ): ?MachineCashCollection {
        if ($collectionId <= 0 || $coordinatorId <= 0) {
            throw new InvalidArgumentException('La recepción en cámara exige una recaudación y un coordinador positivos.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE machine_cash_collections
               SET status = 'DELIVERED_TO_VAULT',
                   delivered_to_vault_at = GREATEST(
                       collected_at,
                       COALESCE(CAST(:delivered_at AS timestamptz), CURRENT_TIMESTAMP)
                   ),
                   delivered_to_vault_by = :coordinator_id
             WHERE id = :collection_id
               AND status = 'SEALED_IN_ROUTE'
            RETURNING id
        ");
        $stmt->bindValue(':delivered_at', $deliveredAt, $deliveredAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':coordinator_id', $coordinatorId, PDO::PARAM_INT);
        $stmt->bindValue(':collection_id', $collectionId, PDO::PARAM_INT);
        $stmt->execute();

        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findById((int)$id);
    }

    /**
     * {@inheritdoc}
     */
    public function reconcile(
        int $collectionId,
        int $coordinatorId,
        string $coordinatorName,
        float $vaultCountedEur,
        float $discrepancyEur,
        CashCollectionStatus $status,
        ?string $discrepancyJustification = null,
        ?string $reconciledAt = null
    ): ?MachineCashCollection {
        if ($collectionId <= 0 || $coordinatorId <= 0) {
            throw new InvalidArgumentException('El arqueo exige una recaudación y un coordinador positivos.');
        }

        if (!$status->isTerminal()) {
            throw new InvalidArgumentException('El arqueo solo puede sellar RECONCILED_OK o DISCREPANCY_FLAGGED.');
        }

        if (trim($coordinatorName) === '') {
            throw new InvalidArgumentException('El arqueo exige el nombre del coordinador que lo firma.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE machine_cash_collections
               SET status = :status,
                   delivered_to_vault_at = COALESCE(
                       delivered_to_vault_at,
                       GREATEST(collected_at, COALESCE(CAST(:reconciled_at AS timestamptz), CURRENT_TIMESTAMP))
                   ),
                   delivered_to_vault_by = COALESCE(delivered_to_vault_by, :coordinator_id),
                   vault_counted_eur = :vault_counted_eur,
                   discrepancy_eur = :discrepancy_eur,
                   vault_reconciled_by = :coordinator_id,
                   vault_reconciled_by_name_snapshot = :coordinator_name,
                   vault_reconciled_at = GREATEST(
                       COALESCE(delivered_to_vault_at, collected_at),
                       COALESCE(CAST(:reconciled_at AS timestamptz), CURRENT_TIMESTAMP)
                   ),
                   discrepancy_justification = :discrepancy_justification
             WHERE id = :collection_id
               AND status IN ('SEALED_IN_ROUTE', 'DELIVERED_TO_VAULT')
            RETURNING id
        ");
        $stmt->bindValue(':status', $status->value, PDO::PARAM_STR);
        $stmt->bindValue(':reconciled_at', $reconciledAt, $reconciledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':coordinator_id', $coordinatorId, PDO::PARAM_INT);
        $stmt->bindValue(':coordinator_name', trim($coordinatorName), PDO::PARAM_STR);
        $stmt->bindValue(':vault_counted_eur', round($vaultCountedEur, 2), PDO::PARAM_STR);
        $stmt->bindValue(':discrepancy_eur', round($discrepancyEur, 2), PDO::PARAM_STR);
        $this->bindNullableText($stmt, ':discrepancy_justification', $discrepancyJustification);
        $stmt->bindValue(':collection_id', $collectionId, PDO::PARAM_INT);
        $stmt->execute();

        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findById((int)$id);
    }

    /**
     * Hidrata una fila del motor con la entidad que aplica sus invariantes.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MachineCashCollection
    {
        return new MachineCashCollection(
            (int)$row['id'],
            (string)$row['collection_code'],
            $row['restock_visit_id'] !== null ? (int)$row['restock_visit_id'] : null,
            (int)$row['machine_id'],
            (string)$row['machine_code_snapshot'],
            (int)$row['restocker_id'],
            (string)$row['restocker_name_snapshot'],
            (string)$row['restocker_code_snapshot'],
            (string)$row['bag_seal_code'],
            (float)$row['change_tubes_loaded_eur'],
            (float)$row['sales_counter_reading'],
            $row['previous_sales_counter_reading'] !== null ? (float)$row['previous_sales_counter_reading'] : null,
            (float)$row['initial_baseline_reading_eur'],
            self::toBool($row['is_counter_reset']),
            $row['counter_reset_notes'] !== null ? (string)$row['counter_reset_notes'] : null,
            (float)$row['expected_cashbox_eur'],
            (float)$row['reception_cash_delivered_eur'],
            $row['receptionist_name'] !== null ? (string)$row['receptionist_name'] : null,
            $row['linked_refund_request_id'] !== null ? (int)$row['linked_refund_request_id'] : null,
            $row['route_notes'] !== null ? (string)$row['route_notes'] : null,
            CashCollectionStatus::from((string)$row['status']),
            (string)$row['collected_at'],
            (string)$row['client_mutation_id'],
            $row['delivered_to_vault_at'] !== null ? (string)$row['delivered_to_vault_at'] : null,
            $row['delivered_to_vault_by'] !== null ? (int)$row['delivered_to_vault_by'] : null,
            $row['vault_counted_eur'] !== null ? (float)$row['vault_counted_eur'] : null,
            $row['discrepancy_eur'] !== null ? (float)$row['discrepancy_eur'] : null,
            $row['vault_reconciled_by'] !== null ? (int)$row['vault_reconciled_by'] : null,
            $row['vault_reconciled_by_name_snapshot'] !== null
                ? (string)$row['vault_reconciled_by_name_snapshot']
                : null,
            $row['vault_reconciled_at'] !== null ? (string)$row['vault_reconciled_at'] : null,
            $row['discrepancy_justification'] !== null ? (string)$row['discrepancy_justification'] : null,
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            $row['location_name'] !== null ? (string)$row['location_name'] : null,
            $row['location_code'] !== null ? (string)$row['location_code'] : null
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    private function findOneBy(string $where, array $params): ?MachineCashCollection
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLLECTION_SELECT
            . ' FROM machine_cash_collections c'
            . ' INNER JOIN machines m ON m.id = c.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . ' WHERE ' . $where
            . ' LIMIT 1'
        );
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Traduce los filtros del contrato a un `WHERE` con placeholders únicos.
     *
     * @param array<string, mixed> $filters
     * @param array<string, int|string> $params
     */
    private function buildFilterClause(array $filters, array &$params): string
    {
        $clauses = [];

        if (isset($filters['restocker_id'])) {
            $restockerId = (int)$filters['restocker_id'];
            if ($restockerId <= 0) {
                throw new InvalidArgumentException('El filtro restocker_id debe ser un entero positivo.');
            }
            $clauses[] = 'c.restocker_id = :filter_restocker_id';
            $params[':filter_restocker_id'] = $restockerId;
        }

        if (isset($filters['machine_id'])) {
            $machineId = (int)$filters['machine_id'];
            if ($machineId <= 0) {
                throw new InvalidArgumentException('El filtro machine_id debe ser un entero positivo.');
            }
            $clauses[] = 'c.machine_id = :filter_machine_id';
            $params[':filter_machine_id'] = $machineId;
        }

        if (isset($filters['status']) && strtoupper(trim((string)$filters['status'])) !== 'ALL') {
            $rawStatus = strtoupper(trim((string)$filters['status']));
            $status = CashCollectionStatus::tryFrom($rawStatus);
            if ($status === null) {
                throw new InvalidArgumentException(sprintf(
                    'El filtro status debe ser uno de %s o ALL; se recibió «%s».',
                    implode(', ', array_map(static fn (CashCollectionStatus $case): string => $case->value, CashCollectionStatus::cases())),
                    $rawStatus
                ));
            }
            $clauses[] = 'c.status = :filter_status';
            $params[':filter_status'] = $status->value;
        }

        if (isset($filters['bag_seal_code']) && trim((string)$filters['bag_seal_code']) !== '') {
            $clauses[] = 'c.bag_seal_code = :filter_bag_seal_code';
            $params[':filter_bag_seal_code'] = strtoupper(trim((string)$filters['bag_seal_code']));
        }

        if (isset($filters['date_from'])) {
            $clauses[] = 'c.collected_at >= CAST(:filter_date_from AS date)';
            $params[':filter_date_from'] = $this->normalizeDateFilter($filters['date_from'], 'date_from');
        }

        if (isset($filters['date_to'])) {
            $clauses[] = 'c.collected_at < CAST(:filter_date_to AS date) + 1';
            $params[':filter_date_to'] = $this->normalizeDateFilter($filters['date_to'], 'date_to');
        }

        return $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: int, 1: int}
     */
    private function limitAndOffset(array $filters): array
    {
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;

        if ($limit <= 0) {
            throw new InvalidArgumentException('El filtro limit debe ser un entero positivo.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('El filtro offset no puede ser negativo.');
        }

        return [min($limit, self::MAX_LIMIT), $offset];
    }

    private function normalizeDateFilter(mixed $value, string $field): string
    {
        $raw = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException(sprintf(
                'El filtro %s debe ser una fecha Y-m-d válida; se recibió «%s».',
                $field,
                $raw
            ));
        }

        return $raw;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function bindNullableText(PDOStatement $stmt, string $parameter, ?string $value): void
    {
        $stmt->bindValue($parameter, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableInt(PDOStatement $stmt, string $parameter, ?int $value): void
    {
        $stmt->bindValue($parameter, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableFloat(PDOStatement $stmt, string $parameter, ?float $value): void
    {
        $stmt->bindValue(
            $parameter,
            $value === null ? null : round($value, 2),
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
