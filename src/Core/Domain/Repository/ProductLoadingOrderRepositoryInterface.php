<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;

/**
 * ProductLoadingOrderRepositoryInterface
 *
 * Contrato de persistencia de las órdenes de carga de furgoneta y sus líneas
 * (Módulo 33, Tablas 45 y 46, RF-02.1–RF-02.3, RF-03.1, RF-04.1, RF-04.2,
 * D1–D4, C1, QA 1, Art. III.1, Art. V.4).
 *
 * Reglas que este puerto impone a cualquier implementación:
 *
 *  - **Nunca se borra.** No existe método de baja física: retirar una línea es
 *    `is_active = FALSE` y el motor lo impone con `trg_no_delete_*`
 *    (Art. III.1, C1). Editar una orden es `UPSERT` sobre
 *    `(order_id, product_id)` y baja lógica de las líneas ausentes.
 *  - **Una sola orden activa por reponedor (D3).** La implementación debe
 *    traducir la violación de `uq_product_loading_orders_active_restocker` a una
 *    excepción reconocible, nunca a un error de motor sin contexto.
 *  - **Lecturas bajo bloqueo (D3, RF-04.1).** `findById($id, true)` bloquea la
 *    cabecera y sus líneas con `SELECT ... FOR UPDATE` para que la confirmación,
 *    la liquidación y la cancelación no compitan entre sí.
 *  - **Propuesta sin máquinas no operativas (QA 1).** La foto de huecos excluye
 *    las máquinas en cuarentena sanitaria, certificado vencido, pausa estacional
 *    o sin acceso, y cuenta aparte las omitidas para que el servicio las
 *    publique como `excluded_unavailable_machines_count`.
 *  - **Hoja A4 determinista (D4).** Las líneas se leen con el orden canónico:
 *    cadena de frío primero, después prioridad del motivo y finalmente SKU.
 *  - **Ninguna proyección incluye coste.** No se lee `unit_cost_eur` ni precios
 *    (Art. V.4); la opacidad es estructural.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface ProductLoadingOrderRepositoryInterface
{
    /**
     * Crea la cabecera y sus líneas en una sola transacción.
     *
     * @param list<array{
     *   product_id: int,
     *   reason: string,
     *   requested_qty: int
     * }> $items Líneas iniciales; las valida la entidad, no el repositorio.
     *
     * @return array{order: ProductLoadingOrder, items: list<array<string, mixed>>}
     */
    public function createOrder(ProductLoadingOrder $order, array $items): array;

    /**
     * Orden con sus líneas —activas y retiradas— en orden de Hoja A4.
     *
     * @return array{order: ProductLoadingOrder, items: list<array<string, mixed>>}|null
     */
    public function findById(int $orderId, bool $forUpdate = false): ?array;

    /**
     * Orden que ocupa el hueco activo del reponedor (D3), si existe.
     */
    public function findActiveByRestocker(int $restockerId, bool $forUpdate = false): ?ProductLoadingOrder;

    /**
     * Listado de Coordinación con la ordenación canónica: abiertas primero y,
     * dentro de cada bloque, `created_at DESC, id DESC`.
     *
     * @param string|null $status Estado exacto del catálogo o `null` para todos.
     *
     * @return list<array<string, mixed>>
     */
    public function findForCoordinator(
        ?string $status = null,
        ?int $restockerId = null,
        int $limit = 100,
        int $offset = 0
    ): array;

    /**
     * KPIs del listado: `pending_picking_count`, `dispatched_count`,
     * `confirmed_in_route_count` y `reconciled_today_count`.
     *
     * @return array<string, int>
     */
    public function summarizeStatusCounts(?DateTimeImmutable $reference = null): array;

    /**
     * Persiste todos los campos mutables de la cabecera.
     *
     * @throws \InvalidArgumentException Si la orden no existe.
     */
    public function updateOrder(ProductLoadingOrder $order): ProductLoadingOrder;

    /**
     * Reemplaza el conjunto de líneas vigentes: `UPSERT` por producto y baja
     * lógica de las ausentes, sin `DELETE` (C1, Art. III.1).
     *
     * @param list<array{
     *   product_id: int,
     *   reason: string,
     *   requested_qty: int
     * }> $items Conjunto deseado de líneas activas.
     *
     * @return list<array<string, mixed>>
     */
    public function replaceItems(int $orderId, array $items): array;

    /**
     * Persiste una línea existente (despacho, baja lógica o cuadre).
     */
    public function updateItem(ProductLoadingOrderItem $item): ProductLoadingOrderItem;

    /**
     * Una línea concreta de la orden, o `null`.
     */
    public function findItem(int $orderId, int $productId, bool $forUpdate = false): ?ProductLoadingOrderItem;

    /**
     * Insumos de la propuesta matutina (D1, QA 1) en un número constante de
     * consultas: contexto del reponedor, zonas cubiertas hoy, huecos por
     * producto —con la variante de alertas y la completa— y máquinas omitidas.
     *
     * @return array{
     *   restocker: array<string, mixed>|null,
     *   zones: list<array<string, mixed>>,
     *   slot_demand: list<array<string, mixed>>,
     *   excluded_unavailable_machines_count: int
     * }
     */
    public function fetchProposalInputs(int $restockerId, ?DateTimeImmutable $reference = null): array;
}
