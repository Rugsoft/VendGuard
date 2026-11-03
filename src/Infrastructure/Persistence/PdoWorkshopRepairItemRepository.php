<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Repository\WorkshopRepairItemRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
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
            wri.updated_at
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

    // ─────────────────────────────────────────────────────────────────────
    // Interno
    // ─────────────────────────────────────────────────────────────────────

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

        $status = isset($row['status']) ? WorkshopRepairStatus::tryFrom((string)$row['status']) : null;
        if ($status !== null) {
            $row['status_label'] = $status->label();
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
