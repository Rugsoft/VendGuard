<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRestockerVanStockRepository
 *
 * Persistencia PDO del stock de producto por lote en la furgoneta del reponedor
 * (Módulo 33, Tabla 44, `restocker_van_product_stocks`, RF-01.1–RF-01.3, D1, D2,
 * D4, QA 3, Art. III.1, Art. V.4).
 *
 * Decisiones que este repositorio sostiene:
 *   1. **Sin `DELETE` (C1, Art. III.1).** Guardar es `INSERT ... ON CONFLICT
 *      (restocker_id, product_id, batch_code) DO UPDATE`; retirar un lote es un
 *      ajuste a `0`. Ni una sentencia de borrado en el fichero.
 *   2. **Fecha inmutable del lote (QA 3).** El `DO UPDATE` no reescribe
 *      `expiration_date`: un lote que ya estaba en la furgoneta conserva su
 *      caducidad. La colisión la detecta el servicio antes y responde `409`.
 *   3. **FEFO determinista (D1, D4).** Las lecturas ordenan por caducidad
 *      ascendente con `NULLS LAST`, desempatando por SKU y lote; el stock útil
 *      agregado excluye los lotes caducados.
 *   4. **Semáforo en el borde.** Cada fila se hidrata con
 *      `RestockerVanProductStock`, que deriva `expiry_status`, `is_usable` y
 *      `usable_quantity` con la regla única del dominio (EARS 01.2).
 *   5. **Opacidad de costes (Art. V.4).** La proyección no selecciona
 *      `unit_cost_eur` ni márgenes: la omisión es estructural.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoRestockerVanStockRepository implements RestockerVanStockRepositoryInterface
{
    /** Proyección común: stock, producto y reponedor, sin coste unitario (Art. V.4). */
    private const STOCK_SELECT = "
        SELECT
            s.id,
            s.restocker_id,
            s.product_id,
            s.batch_code,
            s.expiration_date,
            s.quantity_available,
            s.created_at,
            s.updated_at,
            vp.sku AS product_sku,
            vp.name AS product_name,
            vp.category AS product_category,
            vp.unit_type AS product_unit_type,
            vp.requires_cold_chain,
            vp.max_shelf_life_days,
            u.name AS restocker_name,
            u.operator_code AS restocker_operator_code
        FROM restocker_van_product_stocks s
        INNER JOIN vending_products vp ON vp.id = s.product_id
        INNER JOIN users u ON u.id = s.restocker_id
    ";

    /** Orden FEFO con desempate estable (D1, D4). */
    private const FEFO_ORDER = "
        ORDER BY
            u.name ASC,
            s.expiration_date ASC NULLS LAST,
            vp.sku ASC,
            s.batch_code ASC
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function search(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        if ($restockerId !== null && $restockerId <= 0) {
            return [];
        }

        [$conditions, $params] = $this->buildFilters($restockerId, $category, $expiryStatus, $search);

        $sql = self::STOCK_SELECT;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ' . self::FEFO_ORDER;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value['value'], $value['type']);
        }
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->mapStockRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByRestocker(
        int $restockerId,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        if ($restockerId <= 0) {
            return [];
        }

        return $this->search($restockerId, $category, $expiryStatus, $search);
    }

    /**
     * {@inheritdoc}
     */
    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock {
        if ($restockerId <= 0 || $productId <= 0) {
            return null;
        }

        $batch = strtoupper(trim($batchCode));
        if ($batch === '') {
            $batch = RestockerVanProductStock::DEFAULT_BATCH_CODE;
        }

        $sql = self::STOCK_SELECT
            . ' WHERE s.restocker_id = :restocker_id'
            . ' AND s.product_id = :product_id'
            . ' AND s.batch_code = :batch_code'
            . ($forUpdate ? ' FOR UPDATE OF s' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':batch_code', $batch, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * {@inheritdoc}
     */
    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO restocker_van_product_stocks (
                    restocker_id,
                    product_id,
                    batch_code,
                    expiration_date,
                    quantity_available,
                    created_at,
                    updated_at
                ) VALUES (
                    :restocker_id,
                    :product_id,
                    :batch_code,
                    :expiration_date,
                    :quantity_available,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (restocker_id, product_id, batch_code) DO UPDATE
                SET quantity_available = EXCLUDED.quantity_available,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->bindValue(':restocker_id', $stock->getRestockerId(), PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $stock->getProductId(), PDO::PARAM_INT);
            $stmt->bindValue(':batch_code', $stock->getBatchCode(), PDO::PARAM_STR);
            $stmt->bindValue(
                ':expiration_date',
                $stock->getExpirationDate()?->format('Y-m-d'),
                $stock->getExpirationDate() === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $stmt->bindValue(':quantity_available', $stock->getQuantityAvailable(), PDO::PARAM_INT);
            $stmt->execute();

            $persisted = $this->findLot(
                $stock->getRestockerId(),
                $stock->getProductId(),
                $stock->getBatchCode()
            );

            if ($persisted === null) {
                throw new RuntimeException('El lote se guardó pero no pudo releerse.');
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array
    {
        if ($restockerId <= 0) {
            return [];
        }

        $reference = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT product_id, SUM(quantity_available) AS usable_quantity
            FROM restocker_van_product_stocks
            WHERE restocker_id = :restocker_id
              AND quantity_available > 0
              AND (expiration_date IS NULL OR expiration_date > :today)
            GROUP BY product_id
            ORDER BY product_id ASC
        ");
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':today', $reference, PDO::PARAM_STR);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['product_id']] = (int)$row['usable_quantity'];
        }

        return $result;
    }

    /**
     * Condiciones y parámetros tipados de los filtros de Coordinación.
     *
     * @return array{0: list<string>, 1: array<string, array{value: mixed, type: int}>}
     */
    private function buildFilters(
        ?int $restockerId,
        ?string $category,
        ?string $expiryStatus,
        ?string $search
    ): array {
        $conditions = [];
        $params = [];

        if ($restockerId !== null) {
            $conditions[] = 's.restocker_id = :restocker_id';
            $params[':restocker_id'] = ['value' => $restockerId, 'type' => PDO::PARAM_INT];
        }

        if ($category !== null && trim($category) !== '') {
            $productCategory = ProductCategory::fromString($category);
            $conditions[] = 'vp.category = :category::vending_product_category';
            $params[':category'] = ['value' => $productCategory->value, 'type' => PDO::PARAM_STR];
        }

        $status = $expiryStatus === null ? null : strtoupper(trim($expiryStatus));
        if ($status !== null && $status !== '' && $status !== 'ALL') {
            if (!in_array($status, BatchExpiryStatus::values(), true)) {
                throw new InvalidArgumentException(sprintf(
                    "Filtro de caducidad no válido: '%s'. Valores permitidos: ALL, %s.",
                    $expiryStatus,
                    implode(', ', BatchExpiryStatus::values())
                ));
            }

            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            $params[':today'] = ['value' => $today, 'type' => PDO::PARAM_STR];
            $sooner = BatchExpiryStatus::SOON_WINDOW_DAYS;

            $conditions[] = match ($status) {
                BatchExpiryStatus::EXPIRED->value
                    => 's.expiration_date IS NOT NULL AND s.expiration_date <= :today',
                BatchExpiryStatus::EXPIRING_SOON->value
                    => "s.expiration_date IS NOT NULL AND s.expiration_date > :today"
                        . " AND s.expiration_date <= (:today::date + {$sooner})",
                // La rama `OK` se agrupa entre paréntesis: sin ellos, el `OR`
                // rompería la precedencia del resto de condiciones `AND`.
                BatchExpiryStatus::OK->value
                    => "(s.expiration_date IS NULL OR s.expiration_date > (:today::date + {$sooner}))",
            };
        }

        $term = $search === null ? '' : trim($search);
        if ($term !== '') {
            $conditions[] = '(
                POSITION(LOWER(:search) IN LOWER(vp.sku)) > 0
                OR POSITION(LOWER(:search) IN LOWER(vp.name)) > 0
                OR POSITION(LOWER(:search) IN LOWER(s.batch_code)) > 0
                OR POSITION(LOWER(:search) IN LOWER(u.name)) > 0
                OR POSITION(LOWER(:search) IN LOWER(COALESCE(u.operator_code, \'\'))) > 0
            )';
            $params[':search'] = ['value' => $term, 'type' => PDO::PARAM_STR];
        }

        return [$conditions, $params];
    }

    /**
     * Hidrata una fila de inventario con su producto y su reponedor.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapStockRow(array $row): array
    {
        $category = ProductCategory::fromString((string)$row['product_category']);
        $stock = $this->hydrate($row);

        return array_merge($stock->toArray(), [
            'product_sku' => (string)$row['product_sku'],
            'product_name' => (string)$row['product_name'],
            'product_category' => $category->value,
            'product_category_label' => $category->label(),
            'product_unit_type' => (string)$row['product_unit_type'],
            'requires_cold_chain' => self::toBoolean($row['requires_cold_chain']),
            'max_shelf_life_days' => $row['max_shelf_life_days'] === null
                ? null
                : (int)$row['max_shelf_life_days'],
            'restocker_name' => (string)$row['restocker_name'],
            'restocker_operator_code' => $row['restocker_operator_code'] === null
                ? null
                : (string)$row['restocker_operator_code'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RestockerVanProductStock
    {
        return RestockerVanProductStock::fromDatabaseRow([
            'id' => $row['id'] ?? null,
            'restocker_id' => $row['restocker_id'] ?? 0,
            'product_id' => $row['product_id'] ?? 0,
            'batch_code' => $row['batch_code'] ?? RestockerVanProductStock::DEFAULT_BATCH_CODE,
            'expiration_date' => $row['expiration_date'] ?? null,
            'quantity_available' => $row['quantity_available'] ?? 0,
            'requires_cold_chain' => $row['requires_cold_chain'] ?? false,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
