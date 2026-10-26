<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;

/**
 * VanRestockOrderRepositoryInterface
 *
 * Contrato de persistencia de las órdenes de carga de furgoneta (Módulo 23,
 * RF-01 a RF-05).
 *
 * Reglas que este puerto impone a cualquier implementación:
 *
 *  - **Nunca se borra.** No existe método de baja física: retirar una línea es
 *    baja lógica (`removed_at` + `removed_by`) y el motor lo impone además con
 *    `trg_no_delete_van_restock_orders` e `trg_no_delete_van_restock_order_items`
 *    (Art. III.1).
 *  - **Una sola orden abierta por técnico (D3).** El índice único parcial
 *    `uq_van_restock_open_per_tech` es la última puerta; la implementación debe
 *    traducir su violación a `OPEN_RESTOCK_ORDER_ALREADY_EXISTS`, nunca a un
 *    error de motor.
 *  - **La confirmación móvil es una unidad de escritura (RNF-02).**
 *    `confirmOrderAndIncrementVanStock()` abre transacción, bloquea la cabecera y
 *    cada saldo con `SELECT ... FOR UPDATE`, verifica el tope `9999` (`QA 1`),
 *    suma el stock, cambia el estado y escribe el evento de auditoría; o queda
 *    todo, o no queda nada. Consulta y confirmación se aíslan al técnico
 *    autenticado (Art. V.4).
 *  - **Ninguna respuesta incluye coste.** Los snapshots de línea son unidades,
 *    umbrales y demanda de averías; el precio del repuesto no viaja por aquí
 *    (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface VanRestockOrderRepositoryInterface
{
    /**
     * Insumos del pre-cálculo en un número constante de consultas (RNF-01),
     * sin N+1 por técnico ni por pieza.
     *
     * `van_stock` y `pending_requests` se indexan primero por técnico y después
     * por pieza para que el servicio resuelva cada línea en tiempo constante.
     *
     * @param int|null $technicianId Limita la lectura a un técnico; `null`
     *        recorre la plantilla técnica completa.
     * @return array{
     *   technicians: list<array{
     *     technician_id: int,
     *     technician_name: string,
     *     operator_code: string|null,
     *     is_active: bool,
     *     is_currently_absent: bool,
     *     open_order_id: int|null,
     *     open_order_status: string|null
     *   }>,
     *   spare_parts: list<array{
     *     spare_part_id: int,
     *     part_code: string,
     *     part_name: string,
     *     category: string
     *   }>,
     *   van_stock: array<int, array<int, array{quantity_available: int, min_threshold: int}>>,
     *   pending_requests: array<int, array<int, int>>
     * }
     */
    public function fetchProposalInputs(?int $technicianId = null): array;

    /**
     * Crea una orden en `PENDING_PICKING` con sus líneas y su evento de
     * auditoría en una sola transacción.
     *
     * @param list<VanRestockOrderItem> $items Líneas vigentes con snapshots,
     *        cantidad solicitada y motivo.
     * @param int $actorUserId Coordinador que emite la orden.
     * @throws \VendGuard\Core\Domain\Exception\VanRestockOrderValidationException
     *         `TECHNICIAN_NOT_ELIGIBLE`, `SPARE_PART_NOT_ELIGIBLE`,
     *         `OPEN_RESTOCK_ORDER_ALREADY_EXISTS` o `INVALID_RESTOCK_ORDER_PAYLOAD`.
     */
    public function createOrder(
        int $technicianId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder;

    /**
     * Reemplaza el conjunto de líneas vigentes de una orden en
     * `PENDING_PICKING` aplicando baja lógica a las ausentes y reactivando la
     * misma fila si una pieza retirada vuelve a añadirse (`QA 1`, Art. III.1).
     *
     * @param list<VanRestockOrderItem> $items Conjunto deseado de líneas vigentes;
     *        las que no aparezcan quedan con `removed_at` y `removed_by`, nunca
     *        borradas.
     * @throws \VendGuard\Core\Domain\Exception\VanRestockOrderValidationException
     *         `RESTOCK_ORDER_NOT_FOUND`, `INVALID_RESTOCK_ORDER_TRANSITION`,
     *         `SPARE_PART_NOT_ELIGIBLE` o `INVALID_RESTOCK_ORDER_PAYLOAD`.
     */
    public function updatePendingOrderWithSoftDelete(
        int $orderId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder;

    /**
     * Congela las cantidades servidas y pasa la orden a `DISPATCHED`.
     *
     * Cada línea vigente recibe su cantidad (entre `0` y `9999`); la suma total
     * debe ser al menos `1` (EARS 03.2). Las líneas con `0` se conservan para
     * que la siguiente propuesta vuelva a pedirlas (C3).
     *
     * @param array<int, int> $dispatchedQuantities Mapa `spare_part_id` => unidades.
     * @throws \VendGuard\Core\Domain\Exception\VanRestockOrderValidationException
     *         `RESTOCK_ORDER_NOT_FOUND`, `INVALID_RESTOCK_ORDER_TRANSITION` o
     *         `INVALID_RESTOCK_ORDER_PAYLOAD`.
     */
    public function markDispatched(
        int $orderId,
        array $dispatchedQuantities,
        int $actorUserId
    ): VanRestockOrder;

    /**
     * Cancela una orden abierta con justificación obligatoria (Art. V.1) sin
     * tocar el stock de la furgoneta.
     *
     * @throws \VendGuard\Core\Domain\Exception\VanRestockOrderValidationException
     *         `RESTOCK_ORDER_NOT_FOUND`, `INVALID_RESTOCK_ORDER_TRANSITION` o
     *         `INVALID_RESTOCK_ORDER_PAYLOAD` si la justificación no alcanza el
     *         mínimo real.
     */
    public function markCancelled(
        int $orderId,
        string $cancellationReason,
        int $actorUserId
    ): VanRestockOrder;

    /**
     * Confirmación móvil en 1 toque: suma las unidades despachadas al stock del
     * técnico titular y cierra la orden como `CONFIRMED_BY_TECH`.
     *
     * No consulta ni exige estado de turno (`QA 4`). El técnico autenticado debe
     * ser el destinatario; en caso contrario lanza
     * `RESTOCK_ORDER_OWNERSHIP_MISMATCH` (403, Art. V.4). No altera el estado de
     * averías ni de `spare_part_requests` (C5).
     *
     * @return array{
     *   order: VanRestockOrder,
     *   updated_van_stock: list<array{
     *     spare_part_id: int,
     *     part_code: string,
     *     part_name: string,
     *     previous_quantity_available: int,
     *     dispatched_qty: int,
     *     new_quantity_available: int,
     *     min_threshold: int,
     *     is_below_threshold: bool
     *   }>
     * }
     * @throws \VendGuard\Core\Domain\Exception\VanRestockOrderValidationException
     *         `RESTOCK_ORDER_NOT_FOUND`, `RESTOCK_ORDER_OWNERSHIP_MISMATCH`,
     *         `INVALID_RESTOCK_ORDER_TRANSITION` o `INVALID_RESTOCK_ORDER_PAYLOAD`
     *         si el saldo resultante superase `9999`.
     */
    public function confirmOrderAndIncrementVanStock(
        int $orderId,
        int $authenticatedTechnicianId
    ): array;

    /**
     * Orden completa con sus líneas (vigentes y dadas de baja lógica).
     */
    public function findById(int $orderId): ?VanRestockOrder;

    /**
     * Orden que ocupa el hueco abierto del técnico (D3), si existe.
     *
     * Permite informar de su código antes de intentar emitir otra (EARS 01.1).
     */
    public function findOpenOrderForTechnician(int $technicianId): ?VanRestockOrder;

    /**
     * Listado de Coordinación con la ordenación canónica (EARS 02.2): primero
     * las órdenes abiertas y después las cerradas, cada bloque por
     * `created_at DESC, id DESC`.
     *
     * @param string|null $status Uno de los cuatro estados, `OPEN` para las dos
     *        abiertas, o `null` para todas.
     * @return list<VanRestockOrder>
     */
    public function findAllForCoordinator(?int $technicianId = null, ?string $status = null): array;

    /**
     * Orden `DISPATCHED` pendiente de recepción del técnico autenticado
     * (RF-04.1), o `null` si no tiene ninguna.
     */
    public function findActiveDispatchedForTechnician(int $technicianId): ?VanRestockOrder;

    /**
     * Última orden `CONFIRMED_BY_TECH` del técnico, para el comprobante de solo
     * lectura del móvil (QA 5), o `null` si nunca ha confirmado una carga.
     */
    public function findLastConfirmedForTechnician(int $technicianId): ?VanRestockOrder;
}
