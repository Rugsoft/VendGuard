<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Core\Domain\Entity\WorkshopRepairItem;
use VendGuard\Core\Domain\Repository\WorkshopRepairItemRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoWorkshopRepairItemRepository — persistencia PDO de la bandeja de taller
 * (Módulo 27, Tabla 38).
 *
 * **Qué decide y qué no.** No decide el ciclo: los estados llegan validados por
 * `WorkshopRepairItem`. Sí decide dos cosas que el dominio no puede ver:
 *
 *   1. **Idempotencia del desglose.** El alta usa
 *      `ON CONFLICT (incident_replaced_part_id, unit_index) DO NOTHING`, así que
 *      repetir el cierre de una intervención no duplica unidades ni pisa los
 *      expedientes ya abiertos (`D1`, `C4`, `C6`, `QA 4`).
 *   2. **Escritura condicionada al estado.** Cada `update*` lleva en su `WHERE`
 *      el estado del que parte. Si otra sesión se adelantó, la sentencia no
 *      escribe nada y devuelve una lista vacía; el caso de uso relee bajo bloqueo
 *      y responde `409` en vez de provocar una violación del CHECK de coherencia.
 *
 * **Bloqueo.** `findByIdForUpdate()` emite `SELECT ... FOR UPDATE` y exige una
 * transacción abierta por el llamante. El bloqueo de la fila es lo que hace
 * único el dictamen (`C5`) y lo que permite que el `+1` de
 * `technician_van_stocks` ocurra en la misma transacción (`QA 3`).
 *
 * **Opacidad financiera.** `findPendingDeliveriesForTechnician()` selecciona
 * columnas una a una, nunca `wri.*`, y ninguna de ellas es monetaria: es la
 * garantía de Art. V.4 en el único punto donde el técnico ve piezas de taller.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoWorkshopRepairItemRepository implements WorkshopRepairItemRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Proyección única de lectura enriquecida. Los alias coinciden con las claves
     * del contrato `WorkshopRepairItemSummary` (§2.1) para que el controlador no
     * tenga que renombrar nada.
     */
    private const SUMMARY_SELECT = <<<'SQL'
        SELECT
            wri.id,
            wri.incident_replaced_part_id,
            wri.unit_index,
            wri.total_units_in_line,
            wri.intervention_type,
            wri.incident_id,
            i.ticket_code,
            wri.preventive_order_id,
            po.order_code AS preventive_order_code,
            wri.machine_id,
            wri.machine_code_snapshot AS machine_code,
            wri.machine_model_snapshot AS machine_model,
            wri.location_id,
            wri.location_name_snapshot AS location_name,
            wri.extracted_by_technician_id,
            wri.technician_name_snapshot AS technician_name,
            wri.spare_part_id,
            wri.is_out_of_catalog,
            wri.part_code_snapshot AS part_code,
            wri.part_name_snapshot AS part_name,
            wri.part_category_snapshot AS part_category,
            wri.unit_cost_snapshot,
            wri.status,
            wri.received_at,
            wri.received_by_user_id,
            wri.reception_notes,
            wri.bench_started_at,
            wri.bench_diagnosis,
            wri.refurbishment_cost_eur,
            wri.net_savings_eur,
            wri.restock_destination,
            wri.restocked_to_technician_id,
            rt.name AS restocked_to_technician_name,
            wri.resolved_at,
            wri.resolved_by_user_id,
            wri.created_at,
            wri.updated_at,
            ROUND(
                EXTRACT(EPOCH FROM (COALESCE(wri.resolved_at, CURRENT_TIMESTAMP) - wri.created_at)) / 3600.0,
                2
            ) AS age_hours
        FROM workshop_repair_items wri
        LEFT JOIN incidents i ON i.id = wri.incident_id
        LEFT JOIN preventive_orders po ON po.id = wri.preventive_order_id
        LEFT JOIN users rt ON rt.id = wri.restocked_to_technician_id
        SQL;

    /**
     * Columnas que el técnico puede ver de sus entregas pendientes. Ni una sola
     * es monetaria (Art. V.4).
     */
    private const PENDING_DELIVERY_SELECT = <<<'SQL'
        SELECT
            wri.id,
            wri.incident_replaced_part_id,
            wri.unit_index,
            wri.total_units_in_line,
            wri.intervention_type,
            wri.incident_id,
            i.ticket_code,
            wri.preventive_order_id,
            po.order_code AS preventive_order_code,
            wri.machine_code_snapshot AS machine_code,
            wri.machine_model_snapshot AS machine_model,
            wri.location_name_snapshot AS location_name,
            wri.part_code_snapshot AS part_code,
            wri.part_name_snapshot AS part_name,
            wri.part_category_snapshot AS part_category,
            wri.status,
            wri.created_at
        FROM workshop_repair_items wri
        LEFT JOIN incidents i ON i.id = wri.incident_id
        LEFT JOIN preventive_orders po ON po.id = wri.preventive_order_id
        SQL;

    /**
     * {@inheritdoc}
     */
    public function createUnitBreakdownForReplacedPart(array $breakdownPayload): array
    {
        foreach ([
            'incident_replaced_part_id',
            'unit_index',
            'total_units_in_line',
            'intervention_type',
            'machine_id',
            'location_id',
            'extracted_by_technician_id',
            'part_name',
            'machine_code',
            'machine_model',
            'location_name',
            'technician_name',
        ] as $required) {
            if (!array_key_exists($required, $breakdownPayload)) {
                throw new InvalidArgumentException(sprintf(
                    'Falta la clave obligatoria «%s» en el alta de la unidad de taller.',
                    $required
                ));
            }
        }

        $sql = <<<'SQL'
            INSERT INTO workshop_repair_items (
                incident_replaced_part_id, unit_index, total_units_in_line,
                intervention_type, incident_id, preventive_order_id,
                machine_id, location_id, extracted_by_technician_id,
                spare_part_id, is_out_of_catalog,
                part_code_snapshot, part_name_snapshot, part_category_snapshot,
                machine_code_snapshot, machine_model_snapshot,
                location_name_snapshot, technician_name_snapshot,
                unit_cost_snapshot
            ) VALUES (
                :incident_replaced_part_id, :unit_index, :total_units_in_line,
                :intervention_type, :incident_id, :preventive_order_id,
                :machine_id, :location_id, :extracted_by_technician_id,
                :spare_part_id, :is_out_of_catalog,
                :part_code, :part_name, :part_category,
                :machine_code, :machine_model,
                :location_name, :technician_name,
                :unit_cost
            )
            ON CONFLICT (incident_replaced_part_id, unit_index) DO NOTHING
            RETURNING id
            SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':incident_replaced_part_id' => (int)$breakdownPayload['incident_replaced_part_id'],
                ':unit_index' => (int)$breakdownPayload['unit_index'],
                ':total_units_in_line' => (int)$breakdownPayload['total_units_in_line'],
                ':intervention_type' => (string)$breakdownPayload['intervention_type'],
                ':incident_id' => self::nullableInt($breakdownPayload['incident_id'] ?? null),
                ':preventive_order_id' => self::nullableInt($breakdownPayload['preventive_order_id'] ?? null),
                ':machine_id' => (int)$breakdownPayload['machine_id'],
                ':location_id' => (int)$breakdownPayload['location_id'],
                ':extracted_by_technician_id' => (int)$breakdownPayload['extracted_by_technician_id'],
                ':spare_part_id' => self::nullableInt($breakdownPayload['spare_part_id'] ?? null),
                ':is_out_of_catalog' => !empty($breakdownPayload['is_out_of_catalog']) ? 't' : 'f',
                ':part_code' => self::nullableString($breakdownPayload['part_code'] ?? null),
                ':part_name' => (string)$breakdownPayload['part_name'],
                ':part_category' => (string)($breakdownPayload['part_category'] ?? 'OTHER'),
                ':machine_code' => (string)$breakdownPayload['machine_code'],
                ':machine_model' => (string)$breakdownPayload['machine_model'],
                ':location_name' => (string)$breakdownPayload['location_name'],
                ':technician_name' => (string)$breakdownPayload['technician_name'],
                ':unit_cost' => (float)($breakdownPayload['unit_cost'] ?? 0.0),
            ]);

            $id = $stmt->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No se pudo abrir la unidad de taller: ' . $exception->getMessage(),
                (int)$exception->getCode(),
                $exception
            );
        }

        if ($id === false) {
            return [];
        }

        return $this->findById((int)$id) ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SUMMARY_SELECT . ' WHERE wri.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalizeRow($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByIdForUpdate(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException(
                'findByIdForUpdate() exige una transacción abierta: el bloqueo FOR UPDATE se libera al cerrarla.'
            );
        }

        $stmt = $this->pdo->prepare(self::SUMMARY_SELECT . ' WHERE wri.id = :id FOR UPDATE OF wri');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalizeRow($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function updateReception(int $id, int $receivedByUserId, ?string $receptionNotes, string $receivedAt): array
    {
        $notes = $receptionNotes !== null ? trim($receptionNotes) : null;

        $sql = <<<'SQL'
            UPDATE workshop_repair_items
               SET status = 'RECEIVED_IN_WORKSHOP',
                   received_at = :received_at,
                   received_by_user_id = :received_by_user_id,
                   reception_notes = :reception_notes
             WHERE id = :id
               AND status = 'PENDING_DELIVERY'
            RETURNING id
            SQL;

        return $this->updateAndReload($sql, [
            ':received_at' => $receivedAt,
            ':received_by_user_id' => $receivedByUserId,
            ':reception_notes' => $notes === '' ? null : $notes,
            ':id' => $id,
        ], $id);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStartBench(int $id, int $benchStartedByUserId, string $benchStartedAt): array
    {
        $sql = <<<'SQL'
            UPDATE workshop_repair_items
               SET status = 'IN_BENCH_TEST',
                   bench_started_at = :bench_started_at
             WHERE id = :id
               AND status = 'RECEIVED_IN_WORKSHOP'
            RETURNING id
            SQL;

        return $this->updateAndReload($sql, [
            ':bench_started_at' => $benchStartedAt,
            ':id' => $id,
        ], $id);
    }

    /**
     * {@inheritdoc}
     */
    public function updateResolution(int $id, array $resolutionData): array
    {
        foreach (['status', 'bench_diagnosis', 'resolved_at', 'resolved_by_user_id'] as $required) {
            if (!array_key_exists($required, $resolutionData)) {
                throw new InvalidArgumentException(sprintf(
                    'Falta la clave obligatoria «%s» en el dictamen de taller.',
                    $required
                ));
            }
        }

        $status = (string)$resolutionData['status'];
        if (WorkshopRepairStatus::tryFrom($status) === null || !WorkshopRepairStatus::from($status)->isTerminal()) {
            throw new InvalidArgumentException(sprintf(
                'El estado «%s» no es un dictamen terminal válido.',
                $status
            ));
        }

        $sql = <<<'SQL'
            UPDATE workshop_repair_items
               SET status = :status,
                   bench_diagnosis = :bench_diagnosis,
                   refurbishment_cost_eur = :refurbishment_cost_eur,
                   net_savings_eur = :net_savings_eur,
                   restock_destination = :restock_destination,
                   restocked_to_technician_id = :restocked_to_technician_id,
                   resolved_at = :resolved_at,
                   resolved_by_user_id = :resolved_by_user_id
             WHERE id = :id
               AND status NOT IN ('REFURBISHED_RESTORED', 'SCRAPPED_IRREPARABLE')
            RETURNING id
            SQL;

        return $this->updateAndReload($sql, [
            ':status' => $status,
            ':bench_diagnosis' => (string)$resolutionData['bench_diagnosis'],
            ':refurbishment_cost_eur' => (float)($resolutionData['refurbishment_cost_eur'] ?? 0.0),
            ':net_savings_eur' => (float)($resolutionData['net_savings_eur'] ?? 0.0),
            ':restock_destination' => self::nullableString($resolutionData['restock_destination'] ?? null),
            ':restocked_to_technician_id' => self::nullableInt($resolutionData['restocked_to_technician_id'] ?? null),
            ':resolved_at' => (string)$resolutionData['resolved_at'],
            ':resolved_by_user_id' => (int)$resolutionData['resolved_by_user_id'],
            ':id' => $id,
        ], $id);
    }

    /**
     * {@inheritdoc}
     */
    public function findPendingDeliveriesForTechnician(int $technicianId): array
    {
        $sql = self::PENDING_DELIVERY_SELECT
            . " WHERE wri.extracted_by_technician_id = :technician_id"
            . " AND wri.status = 'PENDING_DELIVERY'"
            . ' ORDER BY wri.created_at ASC, wri.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':technician_id' => $technicianId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static function (array $row): array {
                $normalized = self::normalizeRow($row);
                unset($normalized['unit_cost_snapshot'], $normalized['refurbishment_cost_eur'], $normalized['net_savings_eur']);

                return $normalized;
            },
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function paginateWorkshopTray(WorkshopTrayQueryDTO $query): array
    {
        [$where, $params] = $this->buildTrayWhere($query, true);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM workshop_repair_items wri'
            . ' LEFT JOIN incidents i ON i.id = wri.incident_id ' . $where
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = self::SUMMARY_SELECT . ' ' . $where . ' ' . self::TRAY_ORDER
            . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = $total === 0 ? 0 : (int)ceil($total / $query->perPage);

        return [
            'items' => array_map(static fn (array $row): array => self::normalizeRow($row), $rows),
            'total' => $total,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function computeTrayKpis(WorkshopTrayQueryDTO $query): array
    {
        // El filtro de estado se omite a propósito: los totales son de toda la
        // bandeja filtrada y no de la pestaña activa (QA 2).
        [$where, $params] = $this->buildTrayWhere($query, false);

        $sql = <<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE wri.status = 'PENDING_DELIVERY') AS pending_delivery_count,
                COUNT(*) FILTER (WHERE wri.status = 'RECEIVED_IN_WORKSHOP') AS received_in_workshop_count,
                COUNT(*) FILTER (WHERE wri.status = 'IN_BENCH_TEST') AS in_bench_test_count,
                COUNT(*) FILTER (WHERE wri.status IN ('RECEIVED_IN_WORKSHOP', 'IN_BENCH_TEST')) AS in_workshop_count,
                COUNT(*) FILTER (WHERE wri.status = 'REFURBISHED_RESTORED') AS refurbished_count,
                COUNT(*) FILTER (
                    WHERE wri.status = 'SCRAPPED_IRREPARABLE' AND wri.bench_started_at IS NOT NULL
                ) AS scrapped_in_bench_count,
                COUNT(*) FILTER (
                    WHERE wri.status = 'SCRAPPED_IRREPARABLE' AND wri.bench_started_at IS NULL
                ) AS scrapped_unreceived_count,
                COUNT(*) FILTER (WHERE wri.status = 'SCRAPPED_IRREPARABLE') AS scrapped_count,
                COALESCE(
                    SUM(wri.unit_cost_snapshot) FILTER (WHERE wri.status = 'REFURBISHED_RESTORED'),
                    0
                ) AS total_reference_value_eur,
                COALESCE(
                    SUM(wri.refurbishment_cost_eur) FILTER (WHERE wri.status = 'REFURBISHED_RESTORED'),
                    0
                ) AS total_refurbishment_cost_eur,
                COALESCE(
                    SUM(wri.net_savings_eur) FILTER (WHERE wri.status = 'REFURBISHED_RESTORED'),
                    0
                ) AS total_net_savings_eur
            FROM workshop_repair_items wri
            LEFT JOIN incidents i ON i.id = wri.incident_id
            SQL;

        $stmt = $this->pdo->prepare($sql . ' ' . $where);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];

        $pending = (int)($row['pending_delivery_count'] ?? 0);
        $received = (int)($row['received_in_workshop_count'] ?? 0);
        $inBench = (int)($row['in_bench_test_count'] ?? 0);
        $refurbished = (int)($row['refurbished_count'] ?? 0);
        $scrappedInBench = (int)($row['scrapped_in_bench_count'] ?? 0);
        $scrappedUnreceived = (int)($row['scrapped_unreceived_count'] ?? 0);
        $scrapped = (int)($row['scrapped_count'] ?? 0);

        $referenceValue = round((float)($row['total_reference_value_eur'] ?? 0), 2);
        $refurbishmentCost = round((float)($row['total_refurbishment_cost_eur'] ?? 0), 2);
        $netSavings = round((float)($row['total_net_savings_eur'] ?? 0), 2);

        return [
            'pending_delivery_count' => $pending,
            'received_in_workshop_count' => $received,
            'in_bench_test_count' => $inBench,
            'in_workshop_count' => $received + $inBench,
            'refurbished_count' => $refurbished,
            'scrapped_in_bench_count' => $scrappedInBench,
            'scrapped_unreceived_count' => $scrappedUnreceived,
            'scrapped_count' => $scrapped,
            'evaluated_in_workshop_count' => $refurbished + $scrappedInBench,
            'resolved_total_count' => $refurbished + $scrapped,
            'recovery_rate_pct' => WorkshopRepairItem::calculateRecoveryRate($refurbished, $scrappedInBench),
            'total_reference_value_eur' => $referenceValue,
            'total_refurbishment_cost_eur' => $refurbishmentCost,
            'total_net_savings_eur' => $netSavings,
            // Alias del vocabulario de la bandeja (RF-04.4): mismo valor con el
            // nombre que usan la cabecera de KPIs y la sección 1 del CSV.
            'gross_recovered_value_eur' => $referenceValue,
            'net_refurbished_savings_eur' => $netSavings,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findAllForExport(WorkshopTrayQueryDTO $query): array
    {
        [$where, $params] = $this->buildTrayWhere($query, true);

        $stmt = $this->pdo->prepare(self::SUMMARY_SELECT . ' ' . $where . ' ' . self::TRAY_ORDER);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => self::formatForExport(self::normalizeRow($row)),
            $rows
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Interno
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Orden determinista de la bandeja (`QA 2`): primero lo que espera acción, de
     * más antiguo a más reciente; después lo dictaminado, de más reciente a más
     * antiguo. El `id` final desempata siempre.
     */
    private const TRAY_ORDER = <<<'SQL'
        ORDER BY
            CASE wri.status
                WHEN 'PENDING_DELIVERY' THEN 1
                WHEN 'RECEIVED_IN_WORKSHOP' THEN 2
                WHEN 'IN_BENCH_TEST' THEN 3
                WHEN 'REFURBISHED_RESTORED' THEN 4
                WHEN 'SCRAPPED_IRREPARABLE' THEN 5
                ELSE 6
            END ASC,
            CASE
                WHEN wri.status IN ('REFURBISHED_RESTORED', 'SCRAPPED_IRREPARABLE')
                THEN wri.resolved_at
            END DESC NULLS LAST,
            CASE
                WHEN wri.status NOT IN ('REFURBISHED_RESTORED', 'SCRAPPED_IRREPARABLE')
                THEN wri.created_at
            END ASC NULLS LAST,
            wri.id ASC
        SQL;

    /** Sellos temporales que el CSV publica en hora peninsular (`QA 5`). */
    private const EXPORT_TIMESTAMPS = ['received_at', 'bench_started_at', 'resolved_at', 'created_at', 'updated_at'];

    /**
     * Predicado común de bandeja, KPIs y exportación.
     *
     * La búsqueda se escapa con `!` como carácter de escape para que un `%` tecleado
     * por el Coordinador busque un porcentaje y no «todo».
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildTrayWhere(WorkshopTrayQueryDTO $query, bool $includeStatus): array
    {
        $conditions = [];
        $params = [];

        if ($includeStatus && ($status = $query->statusFilter()) !== null) {
            $conditions[] = 'wri.status = :status';
            $params[':status'] = $status->value;
        }

        if ($query->technicianId !== null) {
            $conditions[] = 'wri.extracted_by_technician_id = :technician_id';
            $params[':technician_id'] = $query->technicianId;
        }

        if (($category = $query->categoryFilter()) !== null) {
            $conditions[] = 'wri.part_category_snapshot = :category';
            $params[':category'] = $category;
        }

        if ($query->search !== null) {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search) . '%';
            $conditions[] = "(wri.part_code_snapshot ILIKE :search_code ESCAPE '!'
                OR wri.part_name_snapshot ILIKE :search_name ESCAPE '!'
                OR wri.machine_code_snapshot ILIKE :search_machine ESCAPE '!'
                OR wri.technician_name_snapshot ILIKE :search_technician ESCAPE '!'
                OR i.ticket_code ILIKE :search_ticket ESCAPE '!'
                OR CAST(wri.incident_id AS VARCHAR) = :search_incident_id)";
            $params[':search_code'] = $pattern;
            $params[':search_name'] = $pattern;
            $params[':search_machine'] = $pattern;
            $params[':search_technician'] = $pattern;
            $params[':search_ticket'] = $pattern;
            $params[':search_incident_id'] = $query->search;
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    /**
     * Convierte los sellos temporales a `Europe/Madrid` (`YYYY-MM-DD HH:MM:SS`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatForExport(array $row): array
    {
        $timezone = new \DateTimeZone('Europe/Madrid');

        foreach (self::EXPORT_TIMESTAMPS as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            try {
                $row[$key] = (new \DateTimeImmutable((string)$row[$key]))
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // Un sello ilegible se deja tal cual: el CSV lo muestra y la
                // anomalía queda visible en lugar de silenciarse como fecha vacía.
            }
        }

        return $row;
    }

    /**
     * Ejecuta una escritura condicionada y devuelve la fila recargada, o `[]` si
     * el `WHERE` de estado no encontró nada que actualizar.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function updateAndReload(string $sql, array $params, int $id): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $updatedId = $stmt->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No se pudo escribir la transición de taller: ' . $exception->getMessage(),
                (int)$exception->getCode(),
                $exception
            );
        }

        if ($updatedId === false) {
            return [];
        }

        return $this->findById($id) ?? [];
    }

    /**
     * Normaliza la fila a la forma del contrato: enteros, flotantes y booleanos
     * de verdad, etiquetas resueltas y ningún `null` disfrazado de cadena vacía.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        foreach ([
            'id',
            'incident_replaced_part_id',
            'unit_index',
            'total_units_in_line',
            'incident_id',
            'preventive_order_id',
            'machine_id',
            'location_id',
            'extracted_by_technician_id',
            'spare_part_id',
            'received_by_user_id',
            'restocked_to_technician_id',
            'resolved_by_user_id',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }

        foreach (['unit_cost_snapshot', 'refurbishment_cost_eur', 'net_savings_eur'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = round((float)$row[$key], 2);
            }
        }

        if (array_key_exists('is_out_of_catalog', $row)) {
            $row['is_out_of_catalog'] = in_array($row['is_out_of_catalog'], [true, 't', '1', 1], true);
        }

        if (array_key_exists('age_hours', $row) && $row['age_hours'] !== null) {
            $row['age_hours'] = round((float)$row['age_hours'], 2);
        }

        $status = isset($row['status']) ? WorkshopRepairStatus::tryFrom((string)$row['status']) : null;
        if ($status !== null) {
            $row['status_label'] = $status->label();
        }

        $destination = isset($row['restock_destination'])
            ? WorkshopRestockDestination::tryFrom((string)$row['restock_destination'])
            : null;
        if ($destination !== null) {
            $row['restock_destination_label'] = $destination->label();
        }

        if (isset($row['unit_index'], $row['total_units_in_line'])) {
            $row['unit_badge'] = $row['unit_index'] . '/' . $row['total_units_in_line'];
        }

        return $row;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }
}
