<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachinePlanogramRepository
 *
 * Persistencia PDO de los carriles del planograma (Módulo 32, Tabla 43;
 * Módulo 34, `D1`; RF-01.1, RF-02.1–RF-02.3, RF-03.3, RF-05.2, C1, C2, QA 1,
 * QA 2, QA 3, Art. III.1, Art. V.4).
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
 *   7. **FEFO de carril (Módulo 34, `D1`, QA 1).** Cada carril declara su lote
 *      vigente, su caducidad y el semáforo `EXPIRED`/`EXPIRING_SOON`/`OK` que
 *      calcula la entidad; un carril vacío nunca alerta (EARS 01.1.2). La
 *      máquina expone además los contadores `expired_slots_count` y
 *      `expiring_soon_slots_count` y el flag `can_restock`.
 *   8. **Actualización post-visita (RF-03.3, Art. V.4).** `applyVisitSlotMovement()`
 *      aplica la regla `D1` en una transacción, bloquea el carril con
 *      `FOR UPDATE`, valida las cotas del movimiento y conserva la caducidad más
 *      próxima cuando conviven remanente sano y lote cargado.
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

    /** Estados del motor que inhabilitan la reposición (QA 1, Art. II). */
    private const UNAVAILABLE_SANITARY_STATUSES = "('QUARANTINE', 'EXPIRED', 'SEASONAL_PAUSE')";

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
                    current_batch_code,
                    current_expiration_date,
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
                    :current_batch_code,
                    :current_expiration_date,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (machine_id, slot_code) DO UPDATE
                SET product_id = EXCLUDED.product_id,
                    max_capacity = EXCLUDED.max_capacity,
                    current_estimated_qty = EXCLUDED.current_estimated_qty,
                    min_threshold = EXCLUDED.min_threshold,
                    is_active = EXCLUDED.is_active,
                    current_batch_code = EXCLUDED.current_batch_code,
                    current_expiration_date = EXCLUDED.current_expiration_date,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->bindValue(':machine_id', $slot->getMachineId(), PDO::PARAM_INT);
            $stmt->bindValue(':slot_code', $slot->getSlotCode(), PDO::PARAM_STR);
            $stmt->bindValue(':product_id', $slot->getProductId(), PDO::PARAM_INT);
            $stmt->bindValue(':max_capacity', $slot->getMaxCapacity(), PDO::PARAM_INT);
            $stmt->bindValue(':current_estimated_qty', $slot->getCurrentEstimatedQty(), PDO::PARAM_INT);
            $stmt->bindValue(':min_threshold', $slot->getMinThreshold(), PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $slot->isActive(), PDO::PARAM_BOOL);
            $this->bindNullableText($stmt, ':current_batch_code', $slot->getCurrentBatchCode());
            $this->bindNullableDate($stmt, ':current_expiration_date', $slot->getCurrentExpirationDate());
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
                    current_estimated_qty, min_threshold, is_active,
                    current_batch_code, current_expiration_date, created_at, updated_at
                ) VALUES (
                    :machine_id, :slot_code, :product_id, :max_capacity,
                    :current_estimated_qty, :min_threshold, :is_active,
                    :current_batch_code, :current_expiration_date,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (machine_id, slot_code) DO UPDATE
                SET product_id = EXCLUDED.product_id,
                    max_capacity = EXCLUDED.max_capacity,
                    current_estimated_qty = EXCLUDED.current_estimated_qty,
                    min_threshold = EXCLUDED.min_threshold,
                    is_active = EXCLUDED.is_active,
                    current_batch_code = EXCLUDED.current_batch_code,
                    current_expiration_date = EXCLUDED.current_expiration_date,
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
                $this->bindNullableText($upsert, ':current_batch_code', $slot->getCurrentBatchCode());
                $this->bindNullableDate($upsert, ':current_expiration_date', $slot->getCurrentExpirationDate());
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
    public function findCompatibleSourceMachines(int $machineId, MachineType $machineType): array
    {
        if ($machineId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.code,
                m.model,
                m.machine_type,
                COUNT(s.id) AS active_slots_count
            FROM machines m
            LEFT JOIN machine_planogram_slots s
                ON s.machine_id = m.id
               AND s.is_active = TRUE
            WHERE m.id <> :machine_id
              AND m.machine_type::text = :machine_type
              AND m.is_active = TRUE
              AND m.deleted_at IS NULL
            GROUP BY m.id, m.code, m.model, m.machine_type
            ORDER BY m.code ASC
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_type', $machineType->value, PDO::PARAM_STR);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'model' => (string)$row['model'],
                'machine_type' => MachineType::from((string)$row['machine_type']),
                'active_slots_count' => (int)$row['active_slots_count'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
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
     * {@inheritdoc}
     */
    public function applyVisitSlotMovement(
        int $machineId,
        string $slotCode,
        int $qtyWithdrawnExpired,
        int $qtyWithdrawnDamaged,
        int $qtyAdded,
        ?string $loadedBatchCode = null,
        ?DateTimeImmutable $loadedExpirationDate = null,
        ?DateTimeImmutable $today = null
    ): array {
        $cleanSlotCode = strtoupper(trim($slotCode));

        if ($machineId <= 0) {
            throw PlanogramValidationException::invalidSlotBounds(
                'machine_id',
                'la máquina del movimiento debe ser un identificador positivo.'
            );
        }

        if ($qtyWithdrawnExpired < 0 || $qtyWithdrawnDamaged < 0 || $qtyAdded < 0) {
            throw PlanogramValidationException::invalidSlotBounds(
                'qty_added',
                'las cantidades del movimiento no pueden ser negativas.'
            );
        }

        $cleanBatch = $loadedBatchCode === null ? null : strtoupper(trim($loadedBatchCode));
        if ($cleanBatch === '') {
            $cleanBatch = null;
        }

        if ($qtyAdded > 0 && $cleanBatch === null) {
            throw PlanogramValidationException::invalidSlotBounds(
                'loaded_batch_code',
                'cargar unidades exige declarar el lote de furgoneta.'
            );
        }

        if ($cleanBatch !== null && preg_match(MachinePlanogramSlot::CURRENT_BATCH_PATTERN, $cleanBatch) !== 1) {
            throw PlanogramValidationException::invalidSlotBounds(
                'loaded_batch_code',
                sprintf('el lote «%s» debe cumplir ^[A-Z0-9_-]{2,40}$.', $loadedBatchCode)
            );
        }

        $loadedExpiration = $loadedExpirationDate?->setTime(0, 0);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lock = $this->pdo->prepare("
                SELECT
                    current_estimated_qty,
                    max_capacity,
                    current_batch_code,
                    current_expiration_date
                FROM machine_planogram_slots
                WHERE machine_id = :machine_id
                  AND slot_code = :slot_code
                  AND is_active = TRUE
                FOR UPDATE
            ");
            $lock->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
            $lock->bindValue(':slot_code', $cleanSlotCode, PDO::PARAM_STR);
            $lock->execute();

            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw PlanogramValidationException::invalidSlotBounds(
                    'slot_code',
                    sprintf('el carril %s no está activo en el planograma de la máquina %d.', $cleanSlotCode, $machineId)
                );
            }

            $qtyBefore = (int)$row['current_estimated_qty'];
            $maxCapacity = (int)$row['max_capacity'];
            $withdrawn = $qtyWithdrawnExpired + $qtyWithdrawnDamaged;
            $qtyAfter = $qtyBefore - $withdrawn + $qtyAdded;

            if ($withdrawn > $qtyBefore) {
                throw PlanogramValidationException::invalidSlotBounds(
                    'qty_withdrawn_expired',
                    sprintf(
                        'el carril %s sólo tenía %d unidades y se declaran %d retiradas.',
                        $cleanSlotCode,
                        $qtyBefore,
                        $withdrawn
                    )
                );
            }

            if ($qtyAfter > $maxCapacity) {
                throw PlanogramValidationException::invalidSlotBounds(
                    'qty_added',
                    sprintf(
                        'el carril %s quedaría con %d unidades sobre una capacidad de %d.',
                        $cleanSlotCode,
                        $qtyAfter,
                        $maxCapacity
                    )
                );
            }

            [$batchCode, $expirationDate] = $this->resolveVisitTraceability(
                $qtyBefore,
                $withdrawn,
                $qtyAdded,
                $qtyAfter,
                $row['current_batch_code'] !== null ? (string)$row['current_batch_code'] : null,
                self::parseDate($row['current_expiration_date']),
                $cleanBatch,
                $loadedExpiration,
                $today
            );

            $update = $this->pdo->prepare("
                UPDATE machine_planogram_slots
                SET current_estimated_qty = :current_estimated_qty,
                    current_batch_code = :current_batch_code,
                    current_expiration_date = :current_expiration_date,
                    updated_at = CURRENT_TIMESTAMP
                WHERE machine_id = :machine_id
                  AND slot_code = :slot_code
                  AND is_active = TRUE
            ");
            $update->bindValue(':current_estimated_qty', $qtyAfter, PDO::PARAM_INT);
            $this->bindNullableText($update, ':current_batch_code', $batchCode);
            $update->bindValue(
                ':current_expiration_date',
                $expirationDate?->format('Y-m-d'),
                $expirationDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $update->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
            $update->bindValue(':slot_code', $cleanSlotCode, PDO::PARAM_STR);
            $update->execute();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $persisted = $this->findSlot($machineId, $cleanSlotCode);
        if ($persisted === null) {
            throw new \RuntimeException('El carril se actualizó pero no pudo releerse.');
        }

        return $persisted;
    }

    /**
     * Regla `D1` de lote y caducidad vigentes tras la visita.
     *
     * El remanente que sobrevive a la retirada es sano por construcción: el
     * validador prohíbe cargar si queda producto caducado o próximo a vencer. Por
     * eso, si conviven remanente y lote cargado, gana la caducidad más próxima
     * —con su lote— para que la alerta no se pierda (`LEAST` del contrato).
     *
     * @return array{0: string|null, 1: DateTimeImmutable|null}
     */
    private function resolveVisitTraceability(
        int $qtyBefore,
        int $withdrawn,
        int $qtyAdded,
        int $qtyAfter,
        ?string $currentBatchCode,
        ?DateTimeImmutable $currentExpirationDate,
        ?string $loadedBatchCode,
        ?DateTimeImmutable $loadedExpirationDate,
        ?DateTimeImmutable $today
    ): array {
        if ($qtyAfter === 0) {
            return [null, null];
        }

        $remaining = $qtyBefore - $withdrawn;

        if ($remaining === 0) {
            return $qtyAdded > 0
                ? [$loadedBatchCode, $loadedExpirationDate]
                : [null, null];
        }

        if ($qtyAdded === 0 || $loadedExpirationDate === null) {
            return [$currentBatchCode, $currentExpirationDate];
        }

        if ($currentExpirationDate === null) {
            return [$loadedBatchCode, $loadedExpirationDate];
        }

        $reference = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        if ($currentExpirationDate <= $reference) {
            // Defensa en profundidad: un remanente caducado conserva su traza para
            // que el carril siga alertando aunque el llamador no lo haya retirado.
            return [$currentBatchCode, $currentExpirationDate];
        }

        return $loadedExpirationDate < $currentExpirationDate
            ? [$loadedBatchCode, $loadedExpirationDate]
            : [$currentBatchCode, $currentExpirationDate];
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
                s.current_batch_code,
                s.current_expiration_date,
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
     * Proyección de máquinas del perímetro vivo del reponedor con resumen,
     * contadores FEFO y disponibilidad (QA 1, QA 2, Art. II).
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
                m.is_blocked_no_access,
                m.is_seasonal_pause,
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
                COUNT(s.id) FILTER (
                    WHERE s.current_estimated_qty > 0
                      AND s.current_expiration_date IS NOT NULL
                      AND s.current_expiration_date <= CURRENT_DATE
                ) AS expired_slots_count,
                COUNT(s.id) FILTER (
                    WHERE s.current_estimated_qty > 0
                      AND s.current_expiration_date IS NOT NULL
                      AND s.current_expiration_date > CURRENT_DATE
                      AND s.current_expiration_date <= CURRENT_DATE + 2
                ) AS expiring_soon_slots_count,
                CASE
                    WHEN m.sanitary_status::text IN " . self::UNAVAILABLE_SANITARY_STATUSES . "
                      OR m.is_blocked_no_access = TRUE
                      OR m.is_seasonal_pause = TRUE
                    THEN FALSE
                    ELSE TRUE
                END AS can_restock,
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
                m.is_blocked_no_access, m.is_seasonal_pause,
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
        $expirationDate = self::parseDate($row['current_expiration_date']);

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
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            $row['current_batch_code'] !== null ? (string)$row['current_batch_code'] : null,
            $expirationDate
        );

        $slotExpiry = $slot->slotExpiryStatus();

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
            'current_batch_code' => $slot->getCurrentBatchCode(),
            'current_expiration_date' => $slot->getCurrentExpirationDate()?->format('Y-m-d'),
            'slot_expiry_status' => $slotExpiry->value,
            'slot_expiry_status_label' => $slotExpiry->label(),
            'slot_expiry_status_icon' => $slotExpiry->icon(),
            'fefo_withdrawal_required' => $slotExpiry !== BatchExpiryStatus::OK,
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
            'expired_slots_count' => (int)$row['expired_slots_count'],
            'expiring_soon_slots_count' => (int)$row['expiring_soon_slots_count'],
            'can_restock' => self::toBool($row['can_restock']),
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

    private function bindNullableText(PDOStatement $stmt, string $parameter, ?string $value): void
    {
        $stmt->bindValue($parameter, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableDate(PDOStatement $stmt, string $parameter, ?DateTimeImmutable $date): void
    {
        $this->bindNullableText($stmt, $parameter, $date?->format('Y-m-d'));
    }

    /**
     * Convierte una fecha `DATE` de PostgreSQL en medianoche inmutable.
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$value, 0, 10));

        return $date === false ? null : $date;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
