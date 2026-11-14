<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;

/**
 * RestockerVanStockRepositoryInterface
 *
 * Contrato de persistencia del stock de producto por lote en la furgoneta del
 * reponedor (Módulo 33, Tabla 44, RF-01.1–RF-01.3, D1, D2, D4, QA 3,
 * Art. III.1 y Art. V.4).
 *
 * El contrato no expone `DELETE`: guardar un lote es un `UPSERT` sobre
 * `(restocker_id, product_id, batch_code)` y retirarlo es un ajuste a `0`
 * (Art. III.1, C1). Las lecturas devuelven las filas en orden FEFO
 * (`expiration_date ASC NULLS LAST`) con el semáforo sanitario derivado y **sin
 * `unit_cost_eur`**: la opacidad de costes es estructural, no un filtro que
 * alguien pueda olvidar (Art. V.4).
 *
 * La agregación de stock útil excluye los lotes caducados, porque son los únicos
 * que pueden descontarse de la propuesta matutina (D1, EARS 01.2).
 */
interface RestockerVanStockRepositoryInterface
{
    /**
     * Inventario de Coordinación: todos los reponedores o uno concreto, con
     * filtros de categoría, semáforo de caducidad y búsqueda libre.
     *
     * @param string|null $category Valor de `ProductCategory`; `null` = todas.
     * @param string|null $expiryStatus `EXPIRED`, `EXPIRING_SOON`, `OK`, `ALL` o `null`.
     * @param string|null $search Texto libre sobre SKU, nombre, lote o reponedor.
     *
     * @return list<array<string, mixed>>
     */
    public function search(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array;

    /**
     * Stock de una furgoneta concreta en orden FEFO.
     *
     * @return list<array<string, mixed>>
     */
    public function findByRestocker(
        int $restockerId,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array;

    /**
     * Un lote concreto, opcionalmente bloqueado con `SELECT ... FOR UPDATE` para
     * el ajuste o la confirmación transaccional (D3, RF-04.1).
     */
    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock;

    /**
     * Alta o actualización absoluta de un lote.
     *
     * La fecha de caducidad no se reescribe en un lote existente: si el lote ya
     * estaba en la furgoneta, su fecha es inmutable (QA 3). El servicio valida la
     * colisión antes y responde `409 BATCH_EXPIRATION_MISMATCH`.
     */
    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock;

    /**
     * Unidades útiles no caducadas por producto (`product_id` => unidades).
     *
     * @return array<int, int>
     */
    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array;
}
