<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\VanRestockOrderService;
use VendGuard\Application\Service\VanStockService;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoVanRestockOrderRepository;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianVanStockController
 *
 * Inventario de repuestos de la furgoneta del técnico autenticado (Módulo 18, RF-04.1).
 *
 * El endpoint **no acepta** ningún identificador de técnico en la petición: la furgoneta que se
 * devuelve es siempre la del sujeto autenticado, y cualquier `technician_id` que llegue por query
 * se ignora en silencio. No es laxitud, es la garantía de RF-04.3: un técnico no puede leer el
 * inventario de otro ni equivocándose de parámetro.
 *
 * La respuesta usa la proyección `toTechnicianArray()` del DTO, que no lleva identidad de técnico
 * ni coste alguno (Art. V.4): el técnico ve su inventario, no el de la flota ni el valor del parque.
 *
 * Desde el Módulo 23 la misma lectura publica, además, la orden despachada pendiente de recepción y
 * el comprobante de la última carga confirmada (contrato §5.10, QA 5). Ambos bloques salen del
 * repositorio de órdenes y se resuelven con el MISMO `user_id` del token: jamás se acepta un
 * identificador de técnico en la petición (Art. V.4). La confirmación móvil es un solo toque sobre
 * la orden propia y no consulta turnos (contrato §5.11, QA 4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class TechnicianVanStockController
{
    private VanStockService $vanStockService;

    private ?VanRestockOrderRepositoryInterface $restockRepository;

    private ?VanRestockOrderService $restockService;

    public function __construct(
        ?VanStockService $vanStockService = null,
        ?VanRestockOrderRepositoryInterface $restockRepository = null,
        ?VanRestockOrderService $restockService = null
    ) {
        $this->vanStockService = $vanStockService ?? new VanStockService(new PdoVanStockRepository());
        $this->restockRepository = $restockRepository;
        $this->restockService = $restockService;
    }

    /**
     * Puerto de órdenes de carga, resuelto bajo demanda para que el controlador
     * pueda construirse en suites unitarias sin abrir una conexión PDO.
     */
    private function restockOrders(): VanRestockOrderRepositoryInterface
    {
        return $this->restockRepository ??= new PdoVanRestockOrderRepository();
    }

    private function restockOrderService(): VanRestockOrderService
    {
        return $this->restockService ??= new VanRestockOrderService($this->restockOrders());
    }

    /**
     * GET /api/technician/van-stock
     *
     * Contrato §4.1. Estado vacío = `items: []` con `200`: un técnico sin stock declarado no tiene
     * un error, tiene una furgoneta que nadie ha cargado todavía.
     */
    public function getInventory(Request $request): Response
    {
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error(
                'UNAUTHORIZED',
                'No se pudo identificar al técnico autenticado.',
                401
            );
        }

        $ownerId = (int)$technicianId;

        $activeDispatchedOrder = $this->restockOrders()->findActiveDispatchedForTechnician($ownerId);
        $lastConfirmedOrder = $this->restockOrders()->findLastConfirmedForTechnician($ownerId);

        return Response::json([
            'technician_id' => $ownerId,
            'items' => array_map(
                static fn ($entry): array => $entry->toTechnicianArray(),
                $this->vanStockService->listByTechnician($ownerId)
            ),
            // Orden despachada pendiente de recepción (RF-05.1, QA 5). Solo la del
            // titular: la lectura está aislada por el `user_id` del token.
            'active_dispatched_order' => $activeDispatchedOrder?->toTechnicianActiveArray(),
            // Comprobante de solo lectura de la última carga confirmada (QA 5).
            'last_confirmed_order' => $lastConfirmedOrder?->toTechnicianConfirmedSummaryArray(),
        ], 200);
    }

    /**
     * POST /api/technician/van-stock/restock-orders/{id}/confirm
     *
     * Confirmación móvil en 1 toque (contrato §5.11, RF-05.2, RNF-02).
     *
     * No hay ninguna comprobación de `technician_shifts`: la recepción se permite
     * en turno, en pausa o fuera de turno (QA 4). La pertenencia de la orden al
     * técnico autenticado y el tope `9999` los impone el repositorio bajo el
     * cerrojo de la cabecera y de cada saldo (Art. V.4).
     */
    public function confirmRestockOrder(Request $request): Response
    {
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error(
                'UNAUTHORIZED',
                'No se pudo identificar al técnico autenticado.',
                401
            );
        }

        $rawOrderId = $request->getRouteParam('id');
        if ($rawOrderId === null || !ctype_digit((string)$rawOrderId) || (int)$rawOrderId < 1) {
            return Response::error(
                'INVALID_RESTOCK_ORDER_IDENTIFIER',
                'El identificador de la orden de carga no es válido.',
                400
            );
        }

        try {
            $result = $this->restockOrderService()->confirmOrder((int)$rawOrderId, (int)$technicianId);
        } catch (VanRestockOrderValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        }

        $order = $result['order'];
        $totalUnits = $order->totalDispatchedUnits() ?? 0;
        $unitLabel = $totalUnits === 1 ? 'unidad' : 'unidades';

        return Response::json([
            'order' => [
                'id' => $order->id,
                'order_code' => $order->orderCode(),
                'status' => $order->status,
                'confirmed_at' => $order->confirmedAt,
                'total_received_units' => $totalUnits,
            ],
            'updated_van_stock' => $result['updated_van_stock'],
            'message' => sprintf(
                'Carga confirmada. Se han sumado %d %s al stock de tu furgoneta.',
                $totalUnits,
                $unitLabel
            ),
        ], 200);
    }
}
