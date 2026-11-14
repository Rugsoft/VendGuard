<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineRestockVisitRepository
 *
 * Persistencia PDO del libro de visitas de reposición in situ (Módulo 34,
 * Tablas 47 y 48; RF-01.2, RF-03.3, RF-03.4, RF-05.2, D4, C1, C4, QA 3,
 * Art. III.1, Art. III.3, Art. V.4).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Solo `INSERT` (Art. III.3).** Las visitas y sus líneas son historia
 *      inmutable: el fichero no contiene ni un `UPDATE` ni un `DELETE` sobre
 *      ellas, y el motor las blinda con `prevent_restock_visit_mutation()` y
 *      `prevent_hard_delete()` (Art. III.1).
 *   2. **Idempotencia en el borde (D4).** `findByClientMutationId()` resuelve la
 *      repetición de la cola offline por `(restocker_id, client_mutation_id)`, el
 *      mismo índice único que impide duplicar la visita.
 *   3. **Dos proyecciones de lectura (Art. V.4, QA 3).** El historial del
 *      reponedor no selecciona `unit_cost_snapshot_eur` ni
 *      `commercial_spoilage_loss_eur`; la opacidad es estructural. Coordinación
 *      pide el snapshot de forma explícita con `findById(..., true)`.
 *   4. **Cinco KPIs deterministas (RF-05.2).** El listado de Coordinación agrega
 *      visitas completadas, abortadas, unidades cargadas, retiradas y merma
 *      comercial con los filtros aplicados.
 *   5. **Pre-cálculo `C4`.** `suggestLiquidationQuantities()` agrega por
 *      producto/lote las cargas y retiradas del reponedor desde el
 *      `confirmed_at` de la orden, para precargar la liquidación del Módulo 33.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineRestockVisitRepository implements MachineRestockVisitRepositoryInterface
{
    /** Tope por defecto y máximo del listado histórico. */
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    private const VISIT_SELECT = "
        v.id,
        v.visit_code,
        v.machine_id,
        v.restocker_id,
        v.loading_order_id,
        v.route_schedule_id,
        v.status,
        v.started_at,
        v.completed_at,
        v.cabin_temp_celsius,
        v.hygiene_checklist_json,
        v.precautionary_seal_applied,
        v.photo_evidence_url,
        v.internal_notes,
        v.triggered_incident_id,
        v.client_mutation_id,
        v.created_at,
        m.code AS machine_code,
        m.model AS machine_model,
        m.machine_type,
        l.name AS location_name,
        u.name AS restocker_name,
        i.ticket_code AS triggered_ticket_code
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function insertVisit(MachineRestockVisit $visit): MachineRestockVisit
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO machine_restock_visits (
                visit_code,
                machine_id,
                restocker_id,
                loading_order_id,
                route_schedule_id,
                status,
                started_at,
                completed_at,
                cabin_temp_celsius,
                hygiene_checklist_json,
                precautionary_seal_applied,
                photo_evidence_url,
                internal_notes,
                triggered_incident_id,
                client_mutation_id
            ) VALUES (
                :visit_code,
                :machine_id,
                :restocker_id,
                :loading_order_id,
                :route_schedule_id,
                :status,
                COALESCE(:started_at::timestamptz, CURRENT_TIMESTAMP),
                COALESCE(:completed_at::timestamptz, CURRENT_TIMESTAMP),
                :cabin_temp_celsius,
                CAST(:hygiene_checklist_json AS jsonb),
                :precautionary_seal_applied,
                :photo_evidence_url,
                :internal_notes,
                :triggered_incident_id,
                :client_mutation_id
            )
            RETURNING id
        ");

        $stmt->bindValue(':visit_code', $visit->getVisitCode(), PDO::PARAM_STR);
        $stmt->bindValue(':machine_id', $visit->getMachineId(), PDO::PARAM_INT);
        $stmt->bindValue(':restocker_id', $visit->getRestockerId(), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':loading_order_id', $visit->getLoadingOrderId());
        $this->bindNullableInt($stmt, ':route_schedule_id', $visit->getRouteScheduleId());
        $stmt->bindValue(':status', $visit->getStatus()->value, PDO::PARAM_STR);
        $this->bindNullableText($stmt, ':started_at', $visit->getStartedAt());
        $this->bindNullableText($stmt, ':completed_at', $visit->getCompletedAt());
        $stmt->bindValue(
            ':cabin_temp_celsius',
            $visit->getCabinTempCelsius(),
            $visit->getCabinTempCelsius() === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(':hygiene_checklist_json', $visit->getHygieneChecklist()->toJson(), PDO::PARAM_STR);
        $stmt->bindValue(':precautionary_seal_applied', $visit->isPrecautionarySealApplied(), PDO::PARAM_BOOL);
        $this->bindNullableText($stmt, ':photo_evidence_url', $visit->getPhotoEvidenceUrl());
        $this->bindNullableText($stmt, ':internal_notes', $visit->getInternalNotes());
        $this->bindNullableInt($stmt, ':triggered_incident_id', $visit->getTriggeredIncidentId());
        $stmt->bindValue(':client_mutation_id', $visit->getClientMutationId(), PDO::PARAM_STR);
        $stmt->execute();

        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            throw new \RuntimeException('La visita se insertó pero el motor no devolvió su identificador.');
        }

        return $visit->withId($id);
    }

    /**
     * {@inheritdoc}
     */
    public function insertItem(MachineRestockVisitItem $item): MachineRestockVisitItem
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO machine_restock_visit_items (
                visit_id,
                slot_id,
                slot_code,
                product_id,
                qty_before,
                qty_withdrawn_expired,
                qty_withdrawn_damaged,
                qty_added,
                qty_after,
                withdrawn_batch_code,
                withdrawn_expiration_date,
                loaded_batch_code,
                loaded_expiration_date,
                unit_cost_snapshot_eur,
                commercial_spoilage_loss_eur
            ) VALUES (
                :visit_id,
                :slot_id,
                :slot_code,
                :product_id,
                :qty_before,
                :qty_withdrawn_expired,
                :qty_withdrawn_damaged,
                :qty_added,
                :qty_after,
                :withdrawn_batch_code,
                :withdrawn_expiration_date,
                :loaded_batch_code,
                :loaded_expiration_date,
                :unit_cost_snapshot_eur,
                :commercial_spoilage_loss_eur
            )
            RETURNING id
        ");

        $stmt->bindValue(':visit_id', $item->getVisitId(), PDO::PARAM_INT);
        $stmt->bindValue(':slot_id', $item->getSlotId(), PDO::PARAM_INT);
        $stmt->bindValue(':slot_code', $item->getSlotCode(), PDO::PARAM_STR);
        $stmt->bindValue(':product_id', $item->getProductId(), PDO::PARAM_INT);
        $stmt->bindValue(':qty_before', $item->getQtyBefore(), PDO::PARAM_INT);
        $stmt->bindValue(':qty_withdrawn_expired', $item->getQtyWithdrawnExpired(), PDO::PARAM_INT);
        $stmt->bindValue(':qty_withdrawn_damaged', $item->getQtyWithdrawnDamaged(), PDO::PARAM_INT);
        $stmt->bindValue(':qty_added', $item->getQtyAdded(), PDO::PARAM_INT);
        $stmt->bindValue(':qty_after', $item->getQtyAfter(), PDO::PARAM_INT);
        $this->bindNullableText($stmt, ':withdrawn_batch_code', $item->getWithdrawnBatchCode());
        $this->bindNullableDate($stmt, ':withdrawn_expiration_date', $item->getWithdrawnExpirationDate());
        $this->bindNullableText($stmt, ':loaded_batch_code', $item->getLoadedBatchCode());
        $this->bindNullableDate($stmt, ':loaded_expiration_date', $item->getLoadedExpirationDate());
        $stmt->bindValue(':unit_cost_snapshot_eur', $item->getUnitCostSnapshotEur(), PDO::PARAM_STR);
        $stmt->bindValue(':commercial_spoilage_loss_eur', $item->getCommercialSpoilageLossEur(), PDO::PARAM_STR);
        $stmt->execute();

        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            throw new \RuntimeException('La línea se insertó pero el motor no devolvió su identificador.');
        }

        return $item->withId($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $visitId, bool $includeCosts = false): ?array
    {
        if ($visitId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::VISIT_SELECT
            . ' FROM machine_restock_visits v'
            . ' INNER JOIN machines m ON m.id = v.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . ' INNER JOIN users u ON u.id = v.restocker_id'
            . ' LEFT JOIN incidents i ON i.id = v.triggered_incident_id'
            . ' WHERE v.id = :visit_id'
        );
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->mapVisit($row, $includeCosts);
    }

    /**
     * {@inheritdoc}
     */
    public function findByClientMutationId(
        int $restockerId,
        string $clientMutationId,
        bool $includeCosts = false
    ): ?array {
        if ($restockerId <= 0 || trim($clientMutationId) === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::VISIT_SELECT
            . ' FROM machine_restock_visits v'
            . ' INNER JOIN machines m ON m.id = v.machine_id'
            . ' INNER JOIN locations l ON l.id = m.location_id'
            . ' INNER JOIN users u ON u.id = v.restocker_id'
            . ' LEFT JOIN incidents i ON i.id = v.triggered_incident_id'
            . ' WHERE v.restocker_id = :restocker_id'
            . '   AND v.client_mutation_id = :client_mutation_id'
        );
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':client_mutation_id', trim($clientMutationId), PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapVisit($row, $includeCosts);
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
        $where = ' WHERE v.restocker_id = :restocker_id' . $this->buildFilterClause($filters, $params);
        [$limit, $offset] = $this->limitAndOffset($filters);

        $sql = "
            SELECT
                v.id,
                v.visit_code,
                v.machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type,
                l.name AS location_name,
                v.status,
                v.started_at,
                v.completed_at,
                v.cabin_temp_celsius,
                v.triggered_incident_id,
                v.created_at,
                agg.units_added,
                agg.units_withdrawn
            FROM machine_restock_visits v
            INNER JOIN machines m ON m.id = v.machine_id
            INNER JOIN locations l ON l.id = m.location_id
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(SUM(i.qty_added), 0) AS units_added,
                    COALESCE(SUM(i.qty_withdrawn_expired + i.qty_withdrawn_damaged), 0) AS units_withdrawn
                FROM machine_restock_visit_items i
                WHERE i.visit_id = v.id
            ) agg ON TRUE
            {$where}
            ORDER BY v.completed_at DESC, v.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->mapRestockerListRow($row),
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

        $sql = "
            SELECT
                v.id,
                v.visit_code,
                v.machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type,
                l.name AS location_name,
                v.restocker_id,
                u.name AS restocker_name,
                v.status,
                v.started_at,
                v.completed_at,
                v.cabin_temp_celsius,
                v.triggered_incident_id,
                i.ticket_code AS triggered_ticket_code,
                v.created_at,
                agg.units_added,
                agg.units_withdrawn,
                agg.spoilage_loss_eur
            FROM machine_restock_visits v
            INNER JOIN machines m ON m.id = v.machine_id
            INNER JOIN locations l ON l.id = m.location_id
            INNER JOIN users u ON u.id = v.restocker_id
            LEFT JOIN incidents i ON i.id = v.triggered_incident_id
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(SUM(it.qty_added), 0) AS units_added,
                    COALESCE(SUM(it.qty_withdrawn_expired + it.qty_withdrawn_damaged), 0) AS units_withdrawn,
                    COALESCE(SUM(it.commercial_spoilage_loss_eur), 0) AS spoilage_loss_eur
                FROM machine_restock_visit_items it
                WHERE it.visit_id = v.id
            ) agg ON TRUE
            {$where}
            ORDER BY v.completed_at DESC, v.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $visits = array_map(
            fn (array $row): array => $this->mapCoordinatorListRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $kpiSql = "
            SELECT
                COUNT(*) FILTER (WHERE v.status = 'COMPLETED') AS completed_visits_count,
                COUNT(*) FILTER (WHERE v.status = 'ABORTED_THERMAL_BREACH') AS aborted_thermal_visits_count,
                COALESCE(SUM(agg.units_added), 0) AS total_units_added,
                COALESCE(SUM(agg.units_withdrawn), 0) AS total_units_withdrawn,
                COALESCE(SUM(agg.spoilage_loss_eur), 0) AS total_commercial_spoilage_loss_eur
            FROM machine_restock_visits v
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(SUM(it.qty_added), 0) AS units_added,
                    COALESCE(SUM(it.qty_withdrawn_expired + it.qty_withdrawn_damaged), 0) AS units_withdrawn,
                    COALESCE(SUM(it.commercial_spoilage_loss_eur), 0) AS spoilage_loss_eur
                FROM machine_restock_visit_items it
                WHERE it.visit_id = v.id
            ) agg ON TRUE
            {$where}
        ";

        $kpiStmt = $this->pdo->prepare($kpiSql);
        foreach ($params as $placeholder => $value) {
            $kpiStmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $kpiStmt->execute();
        $kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'visits' => $visits,
            'kpis' => [
                'completed_visits_count' => (int)($kpiRow['completed_visits_count'] ?? 0),
                'aborted_thermal_visits_count' => (int)($kpiRow['aborted_thermal_visits_count'] ?? 0),
                'total_units_added' => (int)($kpiRow['total_units_added'] ?? 0),
                'total_units_withdrawn' => (int)($kpiRow['total_units_withdrawn'] ?? 0),
                'total_commercial_spoilage_loss_eur' => round((float)($kpiRow['total_commercial_spoilage_loss_eur'] ?? 0), 2),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function suggestLiquidationQuantities(int $loadingOrderId, int $restockerId): array
    {
        if ($loadingOrderId <= 0 || $restockerId <= 0) {
            return [];
        }

        $orderStmt = $this->pdo->prepare("
            SELECT confirmed_at
            FROM product_loading_orders
            WHERE id = :order_id
              AND restocker_id = :restocker_id
        ");
        $orderStmt->bindValue(':order_id', $loadingOrderId, PDO::PARAM_INT);
        $orderStmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $orderStmt->execute();

        $confirmedAt = $orderStmt->fetchColumn();
        if ($confirmedAt === false || $confirmedAt === null) {
            return [];
        }

        $scope = "
            FROM machine_restock_visit_items i
            INNER JOIN machine_restock_visits v ON v.id = i.visit_id
            WHERE v.restocker_id = :restocker_id
              AND v.completed_at >= :confirmed_at::timestamptz
              AND (v.loading_order_id = :order_id OR v.loading_order_id IS NULL)
        ";
        $params = [
            ':restocker_id' => $restockerId,
            ':confirmed_at' => (string)$confirmedAt,
            ':order_id' => $loadingOrderId,
        ];

        $addedStmt = $this->pdo->prepare(
            'SELECT i.product_id, i.loaded_batch_code AS batch_code, '
            . 'SUM(i.qty_added)::bigint AS qty '
            . $scope
            . ' AND i.qty_added > 0'
            . ' GROUP BY i.product_id, i.loaded_batch_code'
        );
        foreach ($params as $placeholder => $value) {
            $addedStmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $addedStmt->execute();

        $withdrawnStmt = $this->pdo->prepare(
            'SELECT i.product_id, i.withdrawn_batch_code AS batch_code, '
            . 'SUM(i.qty_withdrawn_expired + i.qty_withdrawn_damaged)::bigint AS qty '
            . $scope
            . ' AND (i.qty_withdrawn_expired + i.qty_withdrawn_damaged) > 0'
            . ' GROUP BY i.product_id, i.withdrawn_batch_code'
        );
        foreach ($params as $placeholder => $value) {
            $withdrawnStmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $withdrawnStmt->execute();

        /** @var array<string, array{product_id: int, batch_code: string|null, suggested_restocked_in_machines_qty: int, suggested_withdrawn_spoilage_qty: int}> $quantities */
        $quantities = [];

        foreach ($addedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $this->batchKey((int)$row['product_id'], $row['batch_code'] !== null ? (string)$row['batch_code'] : null);
            $quantities[$key] = [
                'product_id' => (int)$row['product_id'],
                'batch_code' => $row['batch_code'] !== null ? (string)$row['batch_code'] : null,
                'suggested_restocked_in_machines_qty' => (int)$row['qty'],
                'suggested_withdrawn_spoilage_qty' => 0,
            ];
        }

        foreach ($withdrawnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $this->batchKey((int)$row['product_id'], $row['batch_code'] !== null ? (string)$row['batch_code'] : null);
            if (!isset($quantities[$key])) {
                $quantities[$key] = [
                    'product_id' => (int)$row['product_id'],
                    'batch_code' => $row['batch_code'] !== null ? (string)$row['batch_code'] : null,
                    'suggested_restocked_in_machines_qty' => 0,
                    'suggested_withdrawn_spoilage_qty' => 0,
                ];
            }
            $quantities[$key]['suggested_withdrawn_spoilage_qty'] = (int)$row['qty'];
        }

        ksort($quantities);

        return array_values($quantities);
    }

    /**
     * Expediente hidratado con la entidad de dominio, sus líneas y los datos de
     * contexto que la tabla no guarda (máquina, sede, reponedor y ticket SAT).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapVisit(array $row, bool $includeCosts): array
    {
        $machineType = MachineType::from((string)$row['machine_type']);
        $visit = new MachineRestockVisit(
            (int)$row['id'],
            (string)$row['visit_code'],
            (int)$row['machine_id'],
            (int)$row['restocker_id'],
            RestockVisitStatus::from((string)$row['status']),
            (string)$row['client_mutation_id'],
            DailyHygieneChecklist::fromJson((string)$row['hygiene_checklist_json'], $machineType),
            $row['cabin_temp_celsius'] !== null ? (float)$row['cabin_temp_celsius'] : null,
            self::toBool($row['precautionary_seal_applied']),
            $row['photo_evidence_url'] !== null ? (string)$row['photo_evidence_url'] : null,
            $row['internal_notes'] !== null ? (string)$row['internal_notes'] : null,
            $row['loading_order_id'] !== null ? (int)$row['loading_order_id'] : null,
            $row['route_schedule_id'] !== null ? (int)$row['route_schedule_id'] : null,
            $row['started_at'] !== null ? (string)$row['started_at'] : null,
            $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            $row['triggered_incident_id'] !== null ? (int)$row['triggered_incident_id'] : null,
            $row['created_at'] !== null ? (string)$row['created_at'] : null
        );

        $items = $this->fetchItems((int)$row['id'], $includeCosts);
        $unitsAdded = 0;
        $unitsWithdrawn = 0;
        $spoilageLoss = 0.0;

        foreach ($items as $item) {
            $unitsAdded += (int)$item['qty_added'];
            $unitsWithdrawn += (int)$item['qty_withdrawn_expired'] + (int)$item['qty_withdrawn_damaged'];
            if ($includeCosts) {
                $spoilageLoss += (float)($item['commercial_spoilage_loss_eur'] ?? 0);
            }
        }

        $data = $visit->toArray() + [
            'machine_code' => (string)$row['machine_code'],
            'machine_model' => (string)$row['machine_model'],
            'machine_type' => $machineType->value,
            'location_name' => (string)$row['location_name'],
            'restocker_name' => (string)$row['restocker_name'],
            'triggered_ticket_code' => $row['triggered_ticket_code'] !== null
                ? (string)$row['triggered_ticket_code']
                : null,
            'items' => $items,
            'units_added' => $unitsAdded,
            'units_withdrawn' => $unitsWithdrawn,
        ];

        if ($includeCosts) {
            $data['commercial_spoilage_loss_eur'] = round($spoilageLoss, 2);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(int $visitId, bool $includeCosts): array
    {
        $costColumns = $includeCosts
            ? ', unit_cost_snapshot_eur, commercial_spoilage_loss_eur'
            : '';

        $stmt = $this->pdo->prepare("
            SELECT
                id,
                visit_id,
                slot_id,
                slot_code,
                product_id,
                qty_before,
                qty_withdrawn_expired,
                qty_withdrawn_damaged,
                qty_added,
                qty_after,
                withdrawn_batch_code,
                withdrawn_expiration_date,
                loaded_batch_code,
                loaded_expiration_date,
                created_at
                {$costColumns}
            FROM machine_restock_visit_items
            WHERE visit_id = :visit_id
            ORDER BY slot_code ASC, id ASC
        ");
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = new MachineRestockVisitItem(
                (int)$row['id'],
                (int)$row['visit_id'],
                (int)$row['slot_id'],
                (string)$row['slot_code'],
                (int)$row['product_id'],
                (int)$row['qty_before'],
                (int)$row['qty_withdrawn_expired'],
                (int)$row['qty_withdrawn_damaged'],
                (int)$row['qty_added'],
                $includeCosts ? (float)$row['unit_cost_snapshot_eur'] : 0.0,
                $row['loaded_batch_code'] !== null ? (string)$row['loaded_batch_code'] : null,
                self::parseDate($row['loaded_expiration_date']),
                $row['withdrawn_batch_code'] !== null ? (string)$row['withdrawn_batch_code'] : null,
                self::parseDate($row['withdrawn_expiration_date']),
                $row['created_at'] !== null ? (string)$row['created_at'] : null
            );

            $items[] = $item->toArray($includeCosts);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapRestockerListRow(array $row): array
    {
        $status = RestockVisitStatus::from((string)$row['status']);

        return [
            'id' => (int)$row['id'],
            'visit_code' => (string)$row['visit_code'],
            'machine_id' => (int)$row['machine_id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_model' => (string)$row['machine_model'],
            'machine_type' => (string)$row['machine_type'],
            'location_name' => (string)$row['location_name'],
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_icon' => $status->icon(),
            'started_at' => $row['started_at'] !== null ? (string)$row['started_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            'cabin_temp_celsius' => $row['cabin_temp_celsius'] !== null ? (float)$row['cabin_temp_celsius'] : null,
            'triggered_incident_id' => $row['triggered_incident_id'] !== null ? (int)$row['triggered_incident_id'] : null,
            'units_added' => (int)$row['units_added'],
            'units_withdrawn' => (int)$row['units_withdrawn'],
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapCoordinatorListRow(array $row): array
    {
        return $this->mapRestockerListRow($row) + [
            'restocker_id' => (int)$row['restocker_id'],
            'restocker_name' => (string)$row['restocker_name'],
            'triggered_ticket_code' => $row['triggered_ticket_code'] !== null
                ? (string)$row['triggered_ticket_code']
                : null,
            'commercial_spoilage_loss_eur' => round((float)$row['spoilage_loss_eur'], 2),
        ];
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
            $clauses[] = 'v.restocker_id = :filter_restocker_id';
            $params[':filter_restocker_id'] = $restockerId;
        }

        if (isset($filters['machine_id'])) {
            $machineId = (int)$filters['machine_id'];
            if ($machineId <= 0) {
                throw new InvalidArgumentException('El filtro machine_id debe ser un entero positivo.');
            }
            $clauses[] = 'v.machine_id = :filter_machine_id';
            $params[':filter_machine_id'] = $machineId;
        }

        if (isset($filters['status']) && strtoupper(trim((string)$filters['status'])) !== 'ALL') {
            $status = RestockVisitStatus::fromString((string)$filters['status']);
            $clauses[] = 'v.status = :filter_status';
            $params[':filter_status'] = $status->value;
        }

        if (isset($filters['date_from'])) {
            $clauses[] = 'v.completed_at >= CAST(:filter_date_from AS date)';
            $params[':filter_date_from'] = $this->normalizeDateFilter($filters['date_from'], 'date_from');
        }

        if (isset($filters['date_to'])) {
            $clauses[] = 'v.completed_at < CAST(:filter_date_to AS date) + 1';
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

    private function batchKey(int $productId, ?string $batchCode): string
    {
        return $productId . '|' . ($batchCode ?? '');
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$value, 0, 10));

        return $date === false ? null : $date;
    }

    private function bindNullableText(PDOStatement $stmt, string $parameter, ?string $value): void
    {
        $stmt->bindValue($parameter, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableInt(PDOStatement $stmt, string $parameter, ?int $value): void
    {
        $stmt->bindValue($parameter, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableDate(PDOStatement $stmt, string $parameter, ?DateTimeImmutable $date): void
    {
        $this->bindNullableText($stmt, $parameter, $date?->format('Y-m-d'));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
