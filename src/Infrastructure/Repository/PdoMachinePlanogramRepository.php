<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachinePlanogramRepository
 *
 * Persistencia PDO de los carriles del planograma (Módulo 32, Tabla 43,
 * RF-02.1–RF-02.3, RF-05.2, C1, C2, QA 2, QA 3, Art. III.1).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Sin `DELETE` (C1, Art. III.1).** Guardar es `INSERT ... ON CONFLICT
 *      (machine_id, slot_code) DO UPDATE`; retirar es `is_active = FALSE`. Ni una
 *      sentencia de borrado en todo el fichero.
 *   2. **Orden físico (QA 3).** El `ORDER BY` separa primero los códigos
 *      puramente numéricos —ordenados por su valor entero— y después los
 *      alfanuméricos en orden lexicográfico. Se resuelve en SQL para que la API
 *      nunca dependa del orden de inserción.
 *   3. **Semáforo en el borde (C2).** Cada fila se hidrata con
 *      `MachinePlanogramSlot`, que calcula `STOCKOUT`/`LOW_STOCK`/`OK`, el
 *      déficit de reposición y el llenado con la regla única del dominio.
 *   4. **Resumen determinista (RF-02.3).** `summarize()` agrega sólo carriles
 *      activos y cuenta los productos dados de baja sin confundirlos con un
 *      carril inactivo.
 *   5. **Perímetro del reponedor (QA 2).** Las consultas de reposición exigen
 *      asignación viva (`removed_at IS NULL`) a una zona activa; la sede hereda
 *      su zona por `locations.zone_id`. Una máquina fuera del perímetro no se
 *      lista y su detalle devuelve `null`.
 *   6. **Opacidad de costes (Art. V.4).** El planograma no selecciona
 *      `unit_cost_eur` ni márgenes: la omisión es estructural, no un filtro
 *      posterior que alguien pueda olvidar.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachinePlanogramRepository implements MachinePlanogramRepositoryInterface
{
    /** Orden físico de bandejas (QA 3): numéricos por valor y después alfanuméricos. */
    private const PHYSICAL_ORDER = "
        ORDER BY
            CASE WHEN s.slot_code ~ '^[0-9]+$' THEN 0 ELSE 1 END ASC,
            CASE WHEN s.slot_code ~ '^[0-9]+$' THEN s.slot_code::bigint END ASC NULLS LAST,
            s.slot_code ASC
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function findMachineById(int $machineId): ?array
    {
        if ($machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.code,
                m.model,
                m.machine_type,
                m.location_id,
                m.sanitary_status,
                m.is_active,
                l.name AS location_name,
                l.site_code,
                l.zone_id,
                oz.code AS zone_code,
                oz.name AS zone_name
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            LEFT JOIN operational_zones oz ON oz.id = l.zone_id
            WHERE m.id = :machine_id
              AND m.deleted_at IS NULL
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapMachineRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array
    {
        if ($machineId <= 0) {
            return [];
        }

        $sql = $this->slotSelect() . ' WHERE s.machine_id = :machine_id';
        if ($onlyActive) {
            $sql .= ' AND s.is_active = TRUE';
        }
        $sql .= ' ' . self::PHYSICAL_ORDER;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->mapSlotRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findSlot(int $machineId, string $slotCode): ?array
    {
        if ($machineId <= 0) {
            return null;
        }

        $sql = $this->slotSelect() . ' WHERE s.machine_id = :machine_id AND s.slot_code = :slot_code';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':slot_code', strtoupper(trim($slotCode)), PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapSlotRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function upsertSlot(MachinePlanogramSlot $slot): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO machine_planogram_slots (
                    machine_id,
                    slot_code,
                    product_id,
                    max_capacity,
                    current_estimated_qty,
                    min_threshold,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    :machine_id,
                    :slot_code,
                    :product_id,
                    :max_capacity,
                    :current_estimated_qty,
                    :min_threshold,
                    :is_active,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (machine_id, slot_code) DO UPDATE
                SET product_id = EXCLUDED.product_id,
                    max_capacity = EXCLUDED.max_capacity,
                    current_estimated_qty = EXCLUDED.current_estimated_qty,
                    min_threshold = EXCLUDED.min_threshold,
                    is_active = EXCLUDED.is_active,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->bindValue(':machine_id', $slot->getMachineId(), PDO::PARAM_INT);
            $stmt->bindValue(':slot_code', $slot->getSlotCode(), PDO::PARAM_STR);
            $stmt->bindValue(':product_id', $slot->getProductId(), PDO::PARAM_INT);
            $stmt->bindValue(':max_capacity', $slot->getMaxCapacity(), PDO::PARAM_INT);
            $stmt->bindValue(':current_estimated_qty', $slot->getCurrentEstimatedQty(), PDO::PARAM_INT);
            $stmt->bindValue(':min_threshold', $slot->getMinThreshold(), PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $slot->isActive(), PDO::PARAM_BOOL);
            $stmt->execute();

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $persisted = $this->findSlot($slot->getMachineId(), $slot->getSlotCode());
        if ($persisted === null) {
            throw new \RuntimeException('El carril se guardó pero no pudo releerse.');
        }

        return $persisted;
    }

    /**
     * {@inheritdoc}
     */
    public function replaceMachinePlanogram(int $machineId, array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            if (!$slot instanceof MachinePlanogramSlot) {
                throw new \InvalidArgumentException('El mapa completo sólo admite MachinePlanogramSlot.');
            }
            if ($slot->getMachineId() !== $machineId) {
                throw new \InvalidArgumentException('Todos los carriles deben pertenecer a la máquina indicada.');
            }
            $normalized[$slot->getSlotCode()] = $slot;
        }

        $this->pdo->beginTransaction();

        try {
            $upsert = $this->pdo->prepare("
                INSERT INTO machine_planogram_slots (
                    machine_id, slot_code, product_id, max_capacity,
                    current_estimated_qty, min_threshold, is_active, created_at, updated_at
                ) VALUES (
                    :machine_id, :slot_code, :product_id, :max_capacity,
                    :current_estimated_qty, :min_threshold, :is_active,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (machine_id, slot_code) DO UPDATE
                SET product_id = EXCLUDED.product_id,
                    max_capacity = EXCLUDED.max_capacity,
                    current_estimated_qty = EXCLUDED.current_estimated_qty,
                    min_threshold = EXCLUDED.min_threshold,
                    is_active = EXCLUDED.is_active,
                    updated_at = CURRENT_TIMESTAMP
            ");

            foreach ($normalized as $slot) {
                $upsert->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
                $upsert->bindValue(':slot_code', $slot->getSlotCode(), PDO::PARAM_STR);
                $upsert->bindValue(':product_id', $slot->getProductId(), PDO::PARAM_INT);
                $upsert->bindValue(':max_capacity', $slot->getMaxCapacity(), PDO::PARAM_INT);
                $upsert->bindValue(':current_estimated_qty', $slot->getCurrentEstimatedQty(), PDO::PARAM_INT);
                $upsert->bindValue(':min_threshold', $slot->getMinThreshold(), PDO::PARAM_INT);
                $upsert->bindValue(':is_active', $slot->isActive(), PDO::PARAM_BOOL);
                $upsert->execute();
            }

            $deactivateSql = "
                UPDATE machine_planogram_slots
                SET is_active = FALSE,
                    updated_at = CURRENT_TIMESTAMP
                WHERE machine_id = :machine_id
                  AND is_active = TRUE
            ";
            $deactivateParams = [':machine_id' => $machineId];

            if ($normalized !== []) {
                $placeholders = [];
                foreach (array_keys($normalized) as $index => $code) {
                    $placeholder = ':keep_' . $index;
                    $placeholders[] = $placeholder;
                    $deactivateParams[$placeholder] = $code;
                }
                $deactivateSql .= ' AND slot_code NOT IN (' . implode(', ', $placeholders) . ')';
            }

            $deactivate = $this->pdo->prepare($deactivateSql);
            $deactivate->execute($deactivateParams);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $this->findSlotsByMachine($machineId, true);
    }

    /**
     * {@inheritdoc}
     */
    public function deactivateSlot(int $machineId, string $slotCode): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE machine_planogram_slots
            SET is_active = FALSE,
                updated_at = CURRENT_TIMESTAMP
            WHERE machine_id = :machine_id
              AND slot_code = :slot_code
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':slot_code', strtoupper(trim($slotCode)), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function summarize(int $machineId): array
    {
        if ($machineId <= 0) {
            return $this->emptySummary();
        }

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(s.id) AS active_slots_count,
                COUNT(s.id) FILTER (WHERE s.current_estimated_qty = 0) AS stockout_slots_count,
                COUNT(s.id) FILTER (
                    WHERE s.current_estimated_qty > 0
                      AND s.current_estimated_qty <= s.min_threshold
                ) AS low_stock_slots_count,
                COUNT(s.id) FILTER (WHERE s.current_estimated_qty > s.min_threshold) AS ok_slots_count,
                COUNT(s.id) FILTER (WHERE vp.is_active = FALSE) AS inactive_product_slots_count,
                COALESCE(SUM(s.max_capacity), 0) AS total_capacity,
                COALESCE(SUM(s.current_estimated_qty), 0) AS total_estimated_qty
            FROM machine_planogram_slots s
            INNER JOIN vending_products vp ON vp.id = s.product_id
            WHERE s.machine_id = :machine_id
              AND s.is_active = TRUE
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? $this->emptySummary() : $this->mapSummaryRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findMachinesForRestocker(int $restockerId): array
    {
        if ($restockerId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare($this->restockerMachineSelect());
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->mapRestockerMachineRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findMachineForRestocker(int $machineId, int $restockerId): ?array
    {
        if ($machineId <= 0 || $restockerId <= 0) {
            return null;
        }

        $sql = $this->restockerMachineSelect($machineId);
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRestockerMachineRow($row);
    }

    /**
     * Proyección común de carriles con producto y sin coste interno (Art. V.4).
     */
    private function slotSelect(): string
    {
        return "
            SELECT
                s.id,
                s.machine_id,
                s.slot_code,
                s.product_id,
                s.max_capacity,
                s.current_estimated_qty,
                s.min_threshold,
                s.is_active,
                s.created_at,
                s.updated_at,
                vp.sku AS product_sku,
                vp.name AS product_name,
                vp.category AS product_category,
                vp.unit_type AS product_unit_type,
                vp.requires_cold_chain,
                vp.is_active AS product_is_active,
                vp.sale_price_eur
            FROM machine_planogram_slots s
            INNER JOIN vending_products vp ON vp.id = s.product_id
        ";
    }

    /**
     * Proyección de máquinas del perímetro vivo del reponedor con resumen (QA 2).
     *
     * `EXISTS` en lugar de unir la asignación evita duplicar la máquina cuando el
     * reponedor tuviera dos filas históricas para la misma zona.
     */
    private function restockerMachineSelect(?int $machineId = null): string
    {
        $machineFilter = $machineId !== null ? ' AND m.id = :machine_id' : '';

        return "
            SELECT
                m.id,
                m.code,
                m.model,
                m.machine_type,
                m.sanitary_status,
                l.name AS location_name,
                l.site_code,
                oz.id AS zone_id,
                oz.code AS zone_code,
                oz.name AS zone_name,
                COUNT(s.id) AS active_slots_count,
                COUNT(s.id) FILTER (WHERE s.current_estimated_qty = 0) AS stockout_slots_count,
                COUNT(s.id) FILTER (
                    WHERE s.current_estimated_qty > 0
                      AND s.current_estimated_qty <= s.min_threshold
                ) AS low_stock_slots_count,
                COUNT(s.id) FILTER (WHERE s.current_estimated_qty > s.min_threshold) AS ok_slots_count,
                COUNT(s.id) FILTER (WHERE vp.is_active = FALSE) AS inactive_product_slots_count,
                COALESCE(SUM(s.max_capacity), 0) AS total_capacity,
                COALESCE(SUM(s.current_estimated_qty), 0) AS total_estimated_qty
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            INNER JOIN operational_zones oz ON oz.id = l.zone_id AND oz.is_active = TRUE
            LEFT JOIN machine_planogram_slots s ON s.machine_id = m.id AND s.is_active = TRUE
            LEFT JOIN vending_products vp ON vp.id = s.product_id
            WHERE m.is_active = TRUE
              AND m.deleted_at IS NULL
              AND EXISTS (
                  SELECT 1
                  FROM restocker_zone_assignments rza
                  WHERE rza.restocker_id = :restocker_id
                    AND rza.zone_id = oz.id
                    AND rza.removed_at IS NULL
              )
            {$machineFilter}
            GROUP BY
                m.id, m.code, m.model, m.machine_type, m.sanitary_status,
                l.name, l.site_code, oz.id, oz.code, oz.name
            ORDER BY m.code ASC
        ";
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapMachineRow(array $row): array
    {
        $machineType = MachineType::tryFrom((string)$row['machine_type']);

        return [
            'id' => (int)$row['id'],
            'code' => (string)$row['code'],
            'model' => (string)$row['model'],
            'machine_type' => $machineType ?? MachineType::SNACKS,
            'location_id' => (int)$row['location_id'],
            'location_name' => (string)$row['location_name'],
            'site_code' => (string)$row['site_code'],
            'zone_id' => $row['zone_id'] !== null ? (int)$row['zone_id'] : null,
            'zone_code' => $row['zone_code'] !== null ? (string)$row['zone_code'] : null,
            'zone_name' => $row['zone_name'] !== null ? (string)$row['zone_name'] : null,
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_active' => self::toBool($row['is_active']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapSlotRow(array $row): array
    {
        $slot = new MachinePlanogramSlot(
            (int)$row['id'],
            (int)$row['machine_id'],
            (string)$row['slot_code'],
            (int)$row['product_id'],
            (int)$row['max_capacity'],
            (int)$row['current_estimated_qty'],
            (int)$row['min_threshold'],
            self::toBool($row['is_active']),
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );

        return [
            'id' => $slot->getId(),
            'machine_id' => $slot->getMachineId(),
            'slot_code' => $slot->getSlotCode(),
            'product_id' => $slot->getProductId(),
            'product_sku' => (string)$row['product_sku'],
            'product_name' => (string)$row['product_name'],
            'product_category' => ProductCategory::fromString((string)$row['product_category']),
            'product_unit_type' => ProductUnitType::fromString((string)$row['product_unit_type']),
            'requires_cold_chain' => self::toBool($row['requires_cold_chain']),
            'product_is_active' => self::toBool($row['product_is_active']),
            'sale_price_eur' => round((float)$row['sale_price_eur'], 2),
            'max_capacity' => $slot->getMaxCapacity(),
            'current_estimated_qty' => $slot->getCurrentEstimatedQty(),
            'min_threshold' => $slot->getMinThreshold(),
            'is_active' => $slot->isActive(),
            'stock_status' => $slot->stockStatus()->value,
            'stock_status_label' => $slot->stockStatus()->label(),
            'restock_needed_qty' => $slot->restockNeededQty(),
            'fill_rate_percent' => $slot->fillRatePercent(),
            'created_at' => $slot->getCreatedAt(),
            'updated_at' => $slot->getUpdatedAt(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{active_slots_count: int, stockout_slots_count: int, low_stock_slots_count: int, ok_slots_count: int, inactive_product_slots_count: int, total_capacity: int, total_estimated_qty: int, fill_rate_percent: float}
     */
    private function mapSummaryRow(array $row): array
    {
        $totalCapacity = (int)$row['total_capacity'];
        $totalQty = (int)$row['total_estimated_qty'];

        return [
            'active_slots_count' => (int)$row['active_slots_count'],
            'stockout_slots_count' => (int)$row['stockout_slots_count'],
            'low_stock_slots_count' => (int)$row['low_stock_slots_count'],
            'ok_slots_count' => (int)$row['ok_slots_count'],
            'inactive_product_slots_count' => (int)$row['inactive_product_slots_count'],
            'total_capacity' => $totalCapacity,
            'total_estimated_qty' => $totalQty,
            'fill_rate_percent' => $totalCapacity > 0
                ? round(($totalQty / $totalCapacity) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapRestockerMachineRow(array $row): array
    {
        $summary = $this->mapSummaryRow($row);
        $machineType = MachineType::tryFrom((string)$row['machine_type']);

        return [
            'id' => (int)$row['id'],
            'code' => (string)$row['code'],
            'model' => (string)$row['model'],
            'machine_type' => $machineType ?? MachineType::SNACKS,
            'location_name' => (string)$row['location_name'],
            'site_code' => (string)$row['site_code'],
            'zone_id' => (int)$row['zone_id'],
            'zone_code' => (string)$row['zone_code'],
            'zone_name' => (string)$row['zone_name'],
            'sanitary_status' => (string)$row['sanitary_status'],
        ] + $summary;
    }

    /**
     * @return array{active_slots_count: int, stockout_slots_count: int, low_stock_slots_count: int, ok_slots_count: int, inactive_product_slots_count: int, total_capacity: int, total_estimated_qty: int, fill_rate_percent: float}
     */
    private function emptySummary(): array
    {
        return [
            'active_slots_count' => 0,
            'stockout_slots_count' => 0,
            'low_stock_slots_count' => 0,
            'ok_slots_count' => 0,
            'inactive_product_slots_count' => 0,
            'total_capacity' => 0,
            'total_estimated_qty' => 0,
            'fill_rate_percent' => 0.0,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
