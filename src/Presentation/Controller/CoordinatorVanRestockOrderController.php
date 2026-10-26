<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\VanRestockOrderService;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoVanRestockOrderRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorVanRestockOrderController
 *
 * Superficie REST de las órdenes de carga de furgoneta para Coordinación
 * (Módulo 23, contrato §5.2 a §5.9).
 *
 * Las ocho acciones son deliberadamente delgadas: el controlador lee la
 * petición, compone el caso de uso y traduce el error de dominio al sobre
 * canónico de la API. Las reglas de negocio —fórmula de propuesta, tope de
 * `9999`, baja lógica de líneas, justificación de cancelación y transiciones—
 * viven en el servicio y en el puerto de persistencia, no aquí.
 *
 * Dos decisiones que esta capa hace explícitas:
 *
 *  - **Cero coste.** Ni una respuesta de este módulo incluye `reference_cost`,
 *    precio o importe: la orden opera sobre unidades físicas (Art. V.4).
 *  - **Lectura y escritura separadas.** Las consultas van al repositorio; las
 *    mutaciones pasan siempre por el servicio, que valida antes de abrir la
 *    transacción del adaptador.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class CoordinatorVanRestockOrderController
{
    private VanRestockOrderRepositoryInterface $repository;

    private VanRestockOrderService $service;

    public function __construct(
        ?VanRestockOrderService $service = null,
        ?VanRestockOrderRepositoryInterface $repository = null
    ) {
        $this->repository = $repository ?? new PdoVanRestockOrderRepository();
        $this->service = $service ?? new VanRestockOrderService($this->repository);
    }

    /**
     * GET /api/coordinator/van-restock-orders/proposals
     *
     * Propuesta pre-calculada para un técnico (`?technician_id`) o para toda la
     * plantilla activa (contrato §5.2, RF-02.1, QA 5).
     */
    public function getProposals(Request $request): Response
    {
        $technicianId = null;
        $rawTechnicianId = $request->getQuery('technician_id');

        if ($rawTechnicianId !== null && trim((string)$rawTechnicianId) !== '') {
            $technicianId = $this->positiveIntOrNull($rawTechnicianId);
            if ($technicianId === null) {
                return $this->invalidPayload([
                    'technician_id' => 'Debe ser un número entero positivo.',
                ]);
            }
        }

        return Response::json([
            'proposals' => array_map(
                static fn ($proposal): array => $proposal->toArray(),
                $this->service->getProposals($technicianId)
            ),
        ], 200);
    }

    /**
     * GET /api/coordinator/van-restock-orders
     *
     * Listado canónico: abiertas primero y cerradas después, cada bloque por
     * `created_at DESC, id DESC` (contrato §5.3, RF-04.4).
     */
    public function listOrders(Request $request): Response
    {
        $technicianId = null;
        $rawTechnicianId = $request->getQuery('technician_id');

        if ($rawTechnicianId !== null && trim((string)$rawTechnicianId) !== '') {
            $technicianId = $this->positiveIntOrNull($rawTechnicianId);
            if ($technicianId === null) {
                return $this->invalidPayload([
                    'technician_id' => 'Debe ser un número entero positivo.',
                ]);
            }
        }

        $status = null;
        $rawStatus = $request->getQuery('status');

        if ($rawStatus !== null && trim((string)$rawStatus) !== '') {
            $status = strtoupper(trim((string)$rawStatus));

            if ($status !== 'OPEN' && !in_array($status, VanRestockOrder::STATUSES, true)) {
                return $this->invalidPayload([
                    'status' => 'Debe ser PENDING_PICKING, DISPATCHED, CONFIRMED_BY_TECH, CANCELLED u OPEN.',
                ]);
            }
        }

        return Response::json([
            'items' => array_map(
                static fn (VanRestockOrder $order): array => $order->toCoordinatorArray(),
                $this->repository->findAllForCoordinator($technicianId, $status)
            ),
        ], 200);
    }

    /**
     * GET /api/coordinator/van-restock-orders/{id}
     *
     * Detalle íntegro de la orden, con sus líneas vigentes y ordenadas. Es la
     * misma proyección que la fila del listado y la que alimenta el Albarán de
     * Picking A4, sin coste alguno (contrato §5.4, Art. V.4).
     */
    public function getOrderDetail(Request $request): Response
    {
        $orderId = $this->routeId($request);
        if ($orderId === null) {
            return Response::error(
                'INVALID_RESTOCK_ORDER_IDENTIFIER',
                'El identificador de la orden de carga no es válido.',
                400
            );
        }

        $order = $this->repository->findById($orderId);

        if ($order === null) {
            return Response::error(
                VanRestockOrderValidationException::RESTOCK_ORDER_NOT_FOUND,
                "No existe la orden de carga {$orderId}.",
                VanRestockOrderValidationException::NOT_FOUND_HTTP_STATUS
            );
        }

        return Response::json($order->toCoordinatorArray(), 200);
    }

    /**
     * POST /api/coordinator/van-restock-orders
     *
     * Alta individual en `PENDING_PICKING` (contrato §5.5, RF-02.1). Un técnico
     * activo con ausencia vigente sí puede recibir la orden: la alerta viaja en
     * la respuesta y la decisión de prepararla es de Coordinación (QA 4).
     */
    public function createOrder(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->unauthorized();
        }

        $technicianId = $this->positiveIntOrNull($request->getBodyParam('technician_id'));
        if ($technicianId === null) {
            return $this->invalidPayload([
                'technician_id' => 'Obligatorio y entero positivo.',
            ]);
        }

        $items = $request->getBodyParam('items');
        if (!is_array($items) || $items === []) {
            return $this->invalidPayload([
                'items' => 'Obligatorio y con al menos una línea.',
            ]);
        }

        try {
            $order = $this->service->createOrder(
                $technicianId,
                $this->optionalString($request->getBodyParam('notes'), 'notes'),
                array_values($items),
                $actorId
            );
        } catch (VanRestockOrderValidationException $exception) {
            return $this->domainError($exception);
        }

        return Response::json([
            'order' => $order->toCoordinatorArray(),
            'message' => 'Orden de carga creada en estado Pendiente de Picking.',
        ], 201);
    }

    /**
     * POST /api/coordinator/van-restock-orders/generate-batch
     *
     * Generación masiva con el desglose de creadas y omitidas (contrato §5.6,
     * RF-02.2, QA 4).
     */
    public function generateBatch(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->unauthorized();
        }

        try {
            $result = $this->service->generateBatch(
                $this->optionalString($request->getBodyParam('notes'), 'notes'),
                $actorId
            );
        } catch (VanRestockOrderValidationException $exception) {
            return $this->domainError($exception);
        }

        return Response::json($result, 200);
    }

    /**
     * PUT /api/coordinator/van-restock-orders/{id}
     *
     * Edición exclusivamente en `PENDING_PICKING`. Las líneas ausentes del nuevo
     * conjunto quedan con baja lógica en el repositorio, jamás borradas
     * (contrato §5.7, QA 1, Art. III.1).
     */
    public function updateOrder(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->unauthorized();
        }

        $orderId = $this->routeId($request);
        if ($orderId === null) {
            return Response::error(
                'INVALID_RESTOCK_ORDER_IDENTIFIER',
                'El identificador de la orden de carga no es válido.',
                400
            );
        }

        $items = $request->getBodyParam('items');
        if (!is_array($items) || $items === []) {
            return $this->invalidPayload([
                'items' => 'Obligatorio y con al menos una línea activa.',
            ]);
        }

        try {
            $order = $this->service->updateOrder(
                $orderId,
                $this->optionalString($request->getBodyParam('notes'), 'notes'),
                array_values($items),
                $actorId
            );
        } catch (VanRestockOrderValidationException $exception) {
            return $this->domainError($exception);
        }

        return Response::json([
            'order' => $order->toCoordinatorArray(),
            'message' => 'Orden de carga actualizada correctamente.',
        ], 200);
    }

    /**
     * POST /api/coordinator/van-restock-orders/{id}/dispatch
     *
     * Expedición física desde almacén. Cada línea activa declara su cantidad
     * servida entre `0` y `9999`; la suma total debe ser al menos `1` porque,
     * si no hay material, procede cancelar la orden (contrato §5.8, QA 1, C3).
     */
    public function dispatchOrder(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->unauthorized();
        }

        $orderId = $this->routeId($request);
        if ($orderId === null) {
            return Response::error(
                'INVALID_RESTOCK_ORDER_IDENTIFIER',
                'El identificador de la orden de carga no es válido.',
                400
            );
        }

        $rawItems = $request->getBodyParam('items');
        if (!is_array($rawItems) || $rawItems === []) {
            return $this->invalidPayload([
                'items' => 'Obligatorio y con las cantidades servidas de cada línea activa.',
            ]);
        }

        $dispatchedQuantities = [];
        $fieldErrors = [];

        foreach (array_values($rawItems) as $index => $line) {
            $position = $index + 1;

            if (!is_array($line)) {
                $fieldErrors["items.{$position}"] = 'La línea no es un objeto válido.';
                continue;
            }

            $sparePartId = $this->positiveIntOrNull($line['spare_part_id'] ?? null);
            $dispatchedQty = $this->intOrNull($line['dispatched_qty'] ?? null);

            if ($sparePartId === null) {
                $fieldErrors["items.{$position}.spare_part_id"] = 'Obligatorio y entero positivo.';
                continue;
            }

            if ($dispatchedQty === null || $dispatchedQty < 0 || $dispatchedQty > VanRestockOrder::QUANTITY_MAX) {
                $fieldErrors["items.{$position}.dispatched_qty"] = 'Debe ser un entero entre 0 y 9999.';
                continue;
            }

            if (array_key_exists($sparePartId, $dispatchedQuantities)) {
                $fieldErrors["items.{$position}.spare_part_id"] = 'La pieza aparece repetida en la expedición.';
                continue;
            }

            $dispatchedQuantities[$sparePartId] = $dispatchedQty;
        }

        if ($fieldErrors !== []) {
            return $this->invalidPayload($fieldErrors);
        }

        try {
            $order = $this->service->dispatchOrder($orderId, $dispatchedQuantities, $actorId);
        } catch (VanRestockOrderValidationException $exception) {
            return $this->domainError($exception);
        }

        return Response::json([
            'order' => $order->toCoordinatorArray(),
            'message' => 'Orden marcada como Expedida. Pendiente de confirmación por el técnico.',
        ], 200);
    }

    /**
     * POST /api/coordinator/van-restock-orders/{id}/cancel
     *
     * Cancelación auditada con justificación de al menos 20 caracteres reales.
     * No toca el stock de la furgoneta y libera el hueco abierto del técnico
     * (contrato §5.9, RF-04.2, Art. V.1).
     */
    public function cancelOrder(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return $this->unauthorized();
        }

        $orderId = $this->routeId($request);
        if ($orderId === null) {
            return Response::error(
                'INVALID_RESTOCK_ORDER_IDENTIFIER',
                'El identificador de la orden de carga no es válido.',
                400
            );
        }

        $rawReason = $request->getBodyParam('cancellation_reason')
            ?? $request->getBodyParam('reason');

        if (!is_string($rawReason) || trim($rawReason) === '') {
            return $this->invalidPayload([
                'cancellation_reason' => 'Obligatorio: justifique la cancelación con al menos 20 caracteres.',
            ]);
        }

        try {
            $order = $this->service->cancelOrder($orderId, $rawReason, $actorId);
        } catch (VanRestockOrderValidationException $exception) {
            return $this->domainError($exception);
        }

        return Response::json([
            'order' => $order->toCoordinatorArray(),
            'message' => 'La orden de carga ha sido cancelada.',
        ], 200);
    }

    // ---------------------------------------------------------------------
    // Lectura de la petición
    // ---------------------------------------------------------------------

    /**
     * Identificador de la orden desde la ruta; `null` si no es un entero positivo.
     */
    private function routeId(Request $request): ?int
    {
        return $this->positiveIntOrNull($request->getRouteParam('id'));
    }

    /**
     * Identidad del coordinador autenticado, inyectada por el middleware de rol.
     */
    private function actorId(Request $request): ?int
    {
        $actorId = $request->getAttribute('user_id');

        return $actorId !== null && is_numeric($actorId) ? (int)$actorId : null;
    }

    /**
     * Texto opcional ya recortado; `null` si viene vacío. Un valor no textual
     * incumple el contrato y se traduce al 422 del módulo.
     */
    private function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw VanRestockOrderValidationException::invalidPayload(
                sprintf('el campo %s debe ser texto.', $field)
            );
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = $this->intOrNull($value);

        return $int !== null && $int > 0 ? $int : null;
    }

    /**
     * Entero a partir de query, cuerpo o segmento de ruta. Rechaza decimales y
     * notación científica: una cantidad o un identificador no son aproximaciones.
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Respuestas de error
    // ---------------------------------------------------------------------

    /**
     * @param array<string, string> $fieldErrors
     */
    private function invalidPayload(array $fieldErrors): Response
    {
        return Response::error(
            VanRestockOrderValidationException::INVALID_RESTOCK_ORDER_PAYLOAD,
            'Los datos de la orden de carga son incompletos o erróneos.',
            VanRestockOrderValidationException::UNPROCESSABLE_HTTP_STATUS,
            $fieldErrors
        );
    }

    private function domainError(VanRestockOrderValidationException $exception): Response
    {
        return Response::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getHttpStatusCode()
        );
    }

    private function unauthorized(): Response
    {
        return Response::error(
            'UNAUTHORIZED',
            'No se pudo identificar al coordinador autenticado.',
            401
        );
    }
}
