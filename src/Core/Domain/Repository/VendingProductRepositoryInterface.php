<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\VendingProduct;

/**
 * VendingProductRepositoryInterface
 *
 * Contrato de persistencia del catálogo maestro de productos y consumibles
 * (Módulo 32, Tabla 42, RF-01.1–RF-01.3, RF-03.2, C1, D3).
 *
 * El contrato no expone borrado físico: la baja es lógica (`is_active = FALSE`)
 * y la fila permanece para que los carriles preexistentes puedan señalarla. El
 * `sku` tampoco se actualiza: es la identidad inmutable de la referencia (C1).
 */
interface VendingProductRepositoryInterface
{
    /**
     * Persiste una referencia nueva y devuelve la entidad con su identificador.
     *
     * @throws \VendGuard\Core\Domain\Exception\DuplicateProductSkuException
     *         cuando el SKU ya existe (409 DUPLICATE_PRODUCT_SKU).
     */
    public function create(VendingProduct $product): VendingProduct;

    /**
     * Actualiza los datos editables de una referencia sin tocar el `sku`.
     *
     * @throws \VendGuard\Core\Domain\Exception\PlanogramValidationException
     *         cuando el SKU recibido difiere del persistido (409 SKU_CHANGE_FORBIDDEN).
     */
    public function update(VendingProduct $product): bool;

    /**
     * Baja lógica o reactivación de la referencia (Art. III.1).
     */
    public function updateStatus(int $id, bool $isActive): bool;

    /**
     * Busca una referencia por su identificador primario.
     */
    public function findById(int $id): ?VendingProduct;

    /**
     * Busca una referencia por su SKU normalizado en mayúsculas.
     */
    public function findBySku(string $sku): ?VendingProduct;

    /**
     * Comprueba si un SKU ya está ocupado, excluyendo opcionalmente una fila.
     */
    public function isSkuUsed(string $sku, ?int $excludeId = null): bool;

    /**
     * Catálogo filtrable por texto, categoría y estado.
     *
     * @return list<VendingProduct>
     */
    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array;

    /**
     * Número de carriles activos del parque que tienen asignada la referencia
     * (`affected_active_slots_count`, RF-01.3).
     */
    public function countActiveSlots(int $productId): int;

    /**
     * Máquinas y carriles vinculados a una referencia. Alimenta el cerrojo
     * inverso de `RF-03.2`: el servicio comprueba cada tipología antes de
     * activar frío o cambiar de categoría.
     *
     * @return list<array{machine_id: int, machine_code: string, machine_model: string, machine_type: MachineType, slot_code: string, is_active: bool}>
     */
    public function findMachineAssignments(int $productId, bool $onlyActive = true): array;
}
