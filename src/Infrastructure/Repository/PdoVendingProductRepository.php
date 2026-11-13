<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use VendGuard\Core\Domain\Exception\DuplicateProductSkuException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\UniqueViolationDetector;

/**
 * PdoVendingProductRepository
 *
 * Persistencia PDO del catálogo maestro de productos y consumibles (Módulo 32,
 * Tabla 42, RF-01.1–RF-01.3, RF-03.2, C1, D3).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Sin borrado físico (Art. III.1).** `updateStatus()` sólo mueve
 *      `is_active`; el repositorio no expone ninguna sentencia `DELETE`.
 *   2. **SKU inmutable (C1).** `update()` nunca escribe `sku` y, si la entidad
 *      recibida trae otro, aborta con `409 SKU_CHANGE_FORBIDDEN` en vez de
 *      ignorarlo en silencio.
 *   3. **Unicidad traducida (RF-01.2).** La violación del índice
 *      `uq_vending_products_sku` se convierte en `DuplicateProductSkuException`
 *      (409 `DUPLICATE_PRODUCT_SKU`), también bajo carrera.
 *   4. **Cerrojo inverso (RF-03.2).** `findMachineAssignments()` entrega las
 *      máquinas y carriles vinculados —con su `machine_type` ya tipado— para que
 *      el servicio valide la edición antes de tocar frío o categoría.
 *   5. **Transacciones.** Altas y ediciones se confirman o revierten completas;
 *      una fila a medias no queda en el catálogo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoVendingProductRepository implements VendingProductRepositoryInterface
{
    /** Identidad de unicidad del catálogo (RF-01.2). */
    private const UNIQUE_SKU_CONSTRAINT = 'uq_vending_products_sku';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function create(VendingProduct $product): VendingProduct
    {
        $this->pdo->beginTransaction();

        try {
            $sql = "
                INSERT INTO vending_products (
                    sku,
                    name,
                    category,
                    unit_type,
                    requires_cold_chain,
                    max_shelf_life_days,
                    unit_cost_eur,
                    sale_price_eur,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    :sku,
                    :name,
                    :category,
                    :unit_type,
                    :requires_cold_chain,
                    :max_shelf_life_days,
                    :unit_cost_eur,
                    :sale_price_eur,
                    :is_active,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':sku', $product->getSku(), PDO::PARAM_STR);
            $stmt->bindValue(':name', $product->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':category', $product->getCategory()->value, PDO::PARAM_STR);
            $stmt->bindValue(':unit_type', $product->getUnitType()->value, PDO::PARAM_STR);
            $stmt->bindValue(':requires_cold_chain', $product->requiresColdChain(), PDO::PARAM_BOOL);
            $stmt->bindValue(
                ':max_shelf_life_days',
                $product->getMaxShelfLifeDays(),
                $product->getMaxShelfLifeDays() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL
            );
            $stmt->bindValue(':unit_cost_eur', $this->decimal($product->getUnitCostEur()), PDO::PARAM_STR);
            $stmt->bindValue(':sale_price_eur', $this->decimal($product->getSalePriceEur()), PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $product->isActive(), PDO::PARAM_BOOL);
            $stmt->execute();

            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();

            return $this->findById($id) ?? $product->withId($id);
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (UniqueViolationDetector::matches($exception, self::UNIQUE_SKU_CONSTRAINT, 'vending_products_sku')) {
                throw DuplicateProductSkuException::forSku($product->getSku());
            }

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     *
     * El `sku` no viaja en el `UPDATE`: la identidad se comprueba antes y, si
     * difiere, la operación se rechaza sin escribir nada (C1).
     */
    public function update(VendingProduct $product): bool
    {
        $id = $product->getId();
        if ($id === null || $id <= 0) {
            return false;
        }

        $current = $this->findById($id);
        if ($current === null) {
            return false;
        }

        if ($current->getSku() !== $product->getSku()) {
            throw PlanogramValidationException::skuChangeForbidden($current->getSku(), $product->getSku());
        }

        $this->pdo->beginTransaction();

        try {
            $sql = "
                UPDATE vending_products
                SET name = :name,
                    category = :category,
                    unit_type = :unit_type,
                    requires_cold_chain = :requires_cold_chain,
                    max_shelf_life_days = :max_shelf_life_days,
                    unit_cost_eur = :unit_cost_eur,
                    sale_price_eur = :sale_price_eur,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':name', $product->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':category', $product->getCategory()->value, PDO::PARAM_STR);
            $stmt->bindValue(':unit_type', $product->getUnitType()->value, PDO::PARAM_STR);
            $stmt->bindValue(':requires_cold_chain', $product->requiresColdChain(), PDO::PARAM_BOOL);
            $stmt->bindValue(
                ':max_shelf_life_days',
                $product->getMaxShelfLifeDays(),
                $product->getMaxShelfLifeDays() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL
            );
            $stmt->bindValue(':unit_cost_eur', $this->decimal($product->getUnitCostEur()), PDO::PARAM_STR);
            $stmt->bindValue(':sale_price_eur', $this->decimal($product->getSalePriceEur()), PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $product->isActive(), PDO::PARAM_BOOL);
            $stmt->execute();

            $updated = $stmt->rowCount() > 0;
            $this->pdo->commit();

            return $updated;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateStatus(int $id, bool $isActive): bool
    {
        $sql = "
            UPDATE vending_products
            SET is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?VendingProduct
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM vending_products WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findBySku(string $sku): ?VendingProduct
    {
        $normalized = strtoupper(trim($sku));

        $stmt = $this->pdo->prepare('SELECT * FROM vending_products WHERE sku = :sku');
        $stmt->bindValue(':sku', $normalized, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function isSkuUsed(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM vending_products WHERE sku = :sku';
        $params = [':sku' => strtoupper(trim($sku))];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array {
        $sql = 'SELECT * FROM vending_products WHERE 1 = 1';
        $params = [];

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $sql .= ' AND (sku LIKE :search_sku OR name LIKE :search_name)';
            $params[':search_sku'] = '%' . $term . '%';
            $params[':search_name'] = '%' . $term . '%';
        }

        if ($category !== null) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category->value;
        }

        if ($isActive !== null) {
            // El booleano cruza como literal controlado, no como texto: el tipo
            // `BOOLEAN` del motor no admite la cadena '1'/'0' que algunos drivers
            // envían al enlazarlo por el array de ejecución (D-3).
            $sql .= ' AND is_active = ' . ($isActive ? 'TRUE' : 'FALSE');
        }

        $sql .= ' ORDER BY is_active DESC, name ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn (array $row): VendingProduct => $this->mapRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function countActiveSlots(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM machine_planogram_slots WHERE product_id = :product_id AND is_active = TRUE'
        );
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * {@inheritdoc}
     */
    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        if ($productId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                s.machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type,
                s.slot_code,
                s.is_active
            FROM machine_planogram_slots s
            INNER JOIN machines m ON m.id = s.machine_id
            WHERE s.product_id = :product_id
        ";

        if ($onlyActive) {
            $sql .= ' AND s.is_active = TRUE';
        }

        $sql .= ' ORDER BY m.code ASC, s.slot_code ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static function (array $row): array {
                $machineType = MachineType::tryFrom((string)$row['machine_type']);

                return [
                    'machine_id' => (int)$row['machine_id'],
                    'machine_code' => (string)$row['machine_code'],
                    'machine_model' => (string)$row['machine_model'],
                    'machine_type' => $machineType ?? MachineType::SNACKS,
                    'slot_code' => (string)$row['slot_code'],
                    'is_active' => self::toBool($row['is_active']),
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Traduce una fila del catálogo a la entidad inmutable.
     *
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): VendingProduct
    {
        return new VendingProduct(
            (int)$row['id'],
            (string)$row['sku'],
            (string)$row['name'],
            ProductCategory::fromString((string)$row['category']),
            ProductUnitType::fromString((string)$row['unit_type']),
            (float)$row['unit_cost_eur'],
            (float)$row['sale_price_eur'],
            self::toBool($row['requires_cold_chain']),
            $row['max_shelf_life_days'] !== null ? (int)$row['max_shelf_life_days'] : null,
            self::toBool($row['is_active']),
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    /**
     * Importe con dos decimales y punto decimal, la forma que el driver entrega
     * a `NUMERIC(10, 2)` sin depender del locale.
     */
    private function decimal(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Normaliza un booleano del driver: PostgreSQL puede devolver `bool` nativo
     * o el texto `'t'`/`'f'` según la ruta de conexión.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
