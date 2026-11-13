<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\DuplicateProductSkuException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Exception\ProductNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Service\PlanogramCompatibilityValidator;

/**
 * VendingProductService
 *
 * Caso de uso del catálogo maestro de productos y consumibles (Módulo 32,
 * RF-01.1–RF-01.3, RF-03.2, C1–C3, D3, QA 1, Art. II, Art. III.3).
 *
 * Reglas que concentra:
 *   1. **Alta y edición validadas en el borde (C2, QA 1).** La entidad
 *      `VendingProduct` muerde SKU, nombre, cotas, unidad, frío y vida útil; el
 *      servicio traduce los tipos del payload y conserva los códigos 422.
 *   2. **SKU inmutable (C1).** En edición se compara el SKU recibido con el
 *      persistido antes de escribir; una sustitución aborta con 409
 *      `SKU_CHANGE_FORBIDDEN` sin tocar la fila.
 *   3. **Cerrojo inverso (RF-03.2, D3).** Antes de guardar una edición se leen
 *      las tipologías de las máquinas con carriles activos del producto y se
 *      valida la nueva categoría/frío con `PlanogramCompatibilityValidator`; un
 *      conflicto devuelve 409 sin persistir.
 *   4. **Baja lógica contada (RF-01.3, Art. III.1).** El cambio de estado no
 *      borra: mueve `is_active` y devuelve `affected_active_slots_count`.
 *   5. **Auditoría (C3, Art. III.3).** Cada alta, edición y cambio efectivo de
 *      estado deja un `AuditEvent` bajo `VENDING_PRODUCT`, con antes/después.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, mensajes y documentación en castellano.
 */
final class VendingProductService
{
    private const STATUS_ACTIVE = 'ACTIVE';
    private const STATUS_INACTIVE = 'INACTIVE';
    private const STATUS_ALL = 'ALL';

    private VendingProductRepositoryInterface $repository;

    private ?AuditLogger $auditLogger;

    public function __construct(
        VendingProductRepositoryInterface $repository,
        ?AuditLogger $auditLogger = null
    ) {
        $this->repository = $repository;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Catálogo filtrable con KPIs globales y carriles activos por referencia.
     *
     * @param array{search?: mixed, category?: mixed, status?: mixed, requires_cold_chain?: mixed} $filters
     *
     * @return array{kpis: array{total_products: int, active_products: int, inactive_products: int, cold_chain_products: int, consumable_and_ingredient_products: int}, products: list<array<string, mixed>>}
     */
    public function listProducts(array $filters = []): array
    {
        $search = isset($filters['search']) && trim((string)$filters['search']) !== ''
            ? trim((string)$filters['search'])
            : null;

        $category = null;
        if (isset($filters['category']) && trim((string)$filters['category']) !== '') {
            $category = ProductCategory::fromString((string)$filters['category']);
        }

        $status = $this->parseStatus($filters['status'] ?? null);
        $isActive = match ($status) {
            self::STATUS_ACTIVE => true,
            self::STATUS_INACTIVE => false,
            default => null,
        };

        $requiresColdChain = null;
        if (isset($filters['requires_cold_chain']) && $filters['requires_cold_chain'] !== '') {
            $requiresColdChain = $this->parseBool($filters['requires_cold_chain'], 'requires_cold_chain');
        }

        $allProducts = $this->repository->findAll();
        $products = $this->repository->findAll($search, $category, $isActive);

        if ($requiresColdChain !== null) {
            $products = array_values(array_filter(
                $products,
                static fn (VendingProduct $product): bool => $product->requiresColdChain() === $requiresColdChain
            ));
        }

        $consumableAndIngredient = 0;
        $coldChain = 0;
        $active = 0;
        foreach ($allProducts as $product) {
            if ($product->isActive()) {
                $active++;
            }
            if ($product->requiresColdChain()) {
                $coldChain++;
            }
            if (in_array(
                $product->getCategory(),
                [ProductCategory::HOT_BEVERAGE_INGREDIENT, ProductCategory::CUP_STIRRER_CONSUMABLE],
                true
            )) {
                $consumableAndIngredient++;
            }
        }

        $rows = [];
        foreach ($products as $product) {
            $row = $product->toArray();
            $row['active_slots_count'] = $this->repository->countActiveSlots((int)$product->getId());
            $rows[] = $row;
        }

        return [
            'kpis' => [
                'total_products' => count($allProducts),
                'active_products' => $active,
                'inactive_products' => count($allProducts) - $active,
                'cold_chain_products' => $coldChain,
                'consumable_and_ingredient_products' => $consumableAndIngredient,
            ],
            'products' => $rows,
        ];
    }

    /**
     * Recupera una referencia o lanza 404.
     *
     * @throws ProductNotFoundException
     */
    public function getProduct(int $productId): VendingProduct
    {
        $product = $this->repository->findById($productId);

        if ($product === null) {
            throw ProductNotFoundException::forId($productId);
        }

        return $product;
    }

    /**
     * Alta de una referencia nueva.
     *
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @throws DuplicateProductSkuException 409 DUPLICATE_PRODUCT_SKU
     * @throws PlanogramValidationException 422 con el código del contrato
     */
    public function createProduct(array $data, ?array $actor = null): VendingProduct
    {
        $sku = strtoupper(trim((string)($data['sku'] ?? '')));

        if ($this->repository->isSkuUsed($sku)) {
            throw DuplicateProductSkuException::forSku($sku);
        }

        $product = $this->buildProduct(null, $data, null, true);

        $created = $this->repository->create($product);

        $this->audit(
            (int)$created->getId(),
            'VENDING_PRODUCT_CREATED',
            $actor,
            null,
            $created->toArray()
        );

        return $created;
    }

    /**
     * Edición sin cambio de SKU y con cerrojo inverso contra las máquinas donde
     * el producto ya tiene carriles activos.
     *
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @throws ProductNotFoundException 404
     * @throws PlanogramValidationException 409 SKU/cerrojo o 422 de cotas
     */
    public function updateProduct(int $productId, array $data, ?array $actor = null): VendingProduct
    {
        $current = $this->getProduct($productId);

        $requestedSku = array_key_exists('sku', $data)
            ? strtoupper(trim((string)$data['sku']))
            : $current->getSku();

        PlanogramCompatibilityValidator::assertSkuUnchanged($current->getSku(), $requestedSku);

        $updated = $this->buildProduct($productId, $data, $current, false);

        $assignedMachineTypes = array_map(
            static fn (array $assignment): mixed => $assignment['machine_type'],
            $this->repository->findMachineAssignments($productId, true)
        );
        PlanogramCompatibilityValidator::assertProductChange($updated, $assignedMachineTypes);

        if ($this->repository->update($updated) !== true) {
            throw ProductNotFoundException::forId($productId);
        }

        $persisted = $this->repository->findById($productId) ?? $updated;

        $this->audit(
            $productId,
            'VENDING_PRODUCT_UPDATED',
            $actor,
            $current->toArray(),
            $persisted->toArray()
        );

        return $persisted;
    }

    /**
     * Baja lógica o reactivación, con el número de carriles activos afectados.
     *
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @return array{product: VendingProduct, affected_active_slots_count: int}
     *
     * @throws ProductNotFoundException 404
     */
    public function changeStatus(int $productId, bool $isActive, ?array $actor = null): array
    {
        $current = $this->getProduct($productId);
        $affectedSlots = $this->repository->countActiveSlots($productId);

        if ($current->isActive() === $isActive) {
            return [
                'product' => $current,
                'affected_active_slots_count' => $affectedSlots,
            ];
        }

        if ($this->repository->updateStatus($productId, $isActive) !== true) {
            throw ProductNotFoundException::forId($productId);
        }

        $persisted = $this->repository->findById($productId) ?? $current->withStatus($isActive);

        $this->audit(
            $productId,
            $isActive ? 'VENDING_PRODUCT_ACTIVATED' : 'VENDING_PRODUCT_DEACTIVATED',
            $actor,
            $current->toArray(),
            $persisted->toArray(),
            ['affected_active_slots_count' => $affectedSlots]
        );

        return [
            'product' => $persisted,
            'affected_active_slots_count' => $affectedSlots,
        ];
    }

    /**
     * Compone la entidad desde el payload.
     *
     * En edición, los campos omitidos conservan el valor actual; `is_active` se
     * ignora porque el estado sólo cambia por `changeStatus()`.
     *
     * @param array<string, mixed> $data
     */
    private function buildProduct(
        ?int $id,
        array $data,
        ?VendingProduct $current,
        bool $isCreate
    ): VendingProduct {
        $sku = array_key_exists('sku', $data)
            ? (string)$data['sku']
            : ($current?->getSku() ?? '');

        $name = array_key_exists('name', $data)
            ? (string)$data['name']
            : ($current?->getName() ?? '');

        $category = $this->parseCategory($data['category'] ?? $current?->getCategory()->value);

        $unitTypeRaw = $data['unit_type'] ?? ($current?->getUnitType()->value ?? '');
        try {
            $unitType = ProductUnitType::fromString((string)$unitTypeRaw);
        } catch (InvalidArgumentException) {
            throw PlanogramValidationException::invalidUnitType($category->value, (string)$unitTypeRaw);
        }

        $unitCost = array_key_exists('unit_cost_eur', $data)
            ? $this->parseMoney($data['unit_cost_eur'], false)
            : ($current?->getUnitCostEur() ?? 0.0);

        $salePrice = array_key_exists('sale_price_eur', $data)
            ? $this->parseMoney($data['sale_price_eur'], true)
            : ($current?->getSalePriceEur() ?? 0.0);

        $requiresColdChain = array_key_exists('requires_cold_chain', $data)
            ? $this->parseBool($data['requires_cold_chain'], 'requires_cold_chain')
            : ($current?->requiresColdChain() ?? false);

        $shelfLife = array_key_exists('max_shelf_life_days', $data)
            ? $this->parseShelfLife($data['max_shelf_life_days'])
            : $current?->getMaxShelfLifeDays();

        $isActive = $isCreate
            ? (array_key_exists('is_active', $data) ? $this->parseBool($data['is_active'], 'is_active') : true)
            : ($current?->isActive() ?? true);

        return new VendingProduct(
            $id,
            $sku,
            $name,
            $category,
            $unitType,
            $unitCost,
            $salePrice,
            $requiresColdChain,
            $shelfLife,
            $isActive
        );
    }

    private function parseCategory(mixed $raw): ProductCategory
    {
        try {
            return ProductCategory::fromString((string)$raw);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'La categoría del producto no es válida: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    private function parseMoney(mixed $raw, bool $isSalePrice): float
    {
        if (!is_numeric($raw)) {
            if ($isSalePrice) {
                throw PlanogramValidationException::invalidSalePrice(0.0, 'UNKNOWN');
            }

            throw PlanogramValidationException::invalidUnitCost(0.0);
        }

        return (float)$raw;
    }

    private function parseShelfLife(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw) || (float)$raw !== (float)(int)$raw) {
            throw PlanogramValidationException::invalidShelfLife($raw);
        }

        return (int)$raw;
    }

    private function parseBool(mixed $raw, string $field): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException("El campo {$field} debe ser booleano.");
        }

        return $value;
    }

    /**
     * Normaliza el filtro de estado del contrato (`ACTIVE|INACTIVE|ALL`).
     */
    private function parseStatus(mixed $raw): string
    {
        if ($raw === null || trim((string)$raw) === '') {
            return self::STATUS_ALL;
        }

        $status = strtoupper(trim((string)$raw));
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ALL], true)) {
            throw new InvalidArgumentException("El estado '{$raw}' no es válido; use ACTIVE, INACTIVE o ALL.");
        }

        return $status;
    }

    /**
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     */
    private function audit(
        int $productId,
        string $action,
        ?array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->logEvent(new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_VENDING_PRODUCT,
            entityId: $productId,
            action: $action,
            userId: $actor['id'] ?? null,
            userRole: $actor['role'] ?? 'COORDINATOR',
            userName: $actor['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        ));
    }
}
