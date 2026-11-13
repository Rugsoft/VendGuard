<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Application\Service\RestockerVanStockService;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorProductLoadingController
 *
 * Superficie REST de Coordinación para el inventario de producto por lote de las
 * furgonetas y el ciclo completo de órdenes de carga (Módulo 33, `RF-01`–`RF-05`,
 * `D1`–`D4`, `QA 1`–`QA 4`, `Art. V.4`).
 *
 * El controlador es deliberadamente delgado: lee la petición, compone el caso de
 * uso y traduce la excepción de negocio a la envolvente canónica. Las reglas
 * térmicas, de lote, de cuadre y de justificación viven en
 * `ProductLoadingValidator`; la persistencia y el bloqueo pesimista, en los
 * repositorios PDO. Ninguna proyección incluye `unit_cost_eur`, precio o margen.
 *
 * | Método | Ruta                                                              |
 * |--------|-------------------------------------------------------------------|
 * | GET    | `/api/coordinator/restocker-van-stocks`                            |
 * | PUT    | `/api/coordinator/restocker-van-stocks/{restockerId}`              |
 * | GET    | `/api/coordinator/product-loading-orders/proposal`                 |
 * | GET    | `/api/coordinator/product-loading-orders`                          |
 * | GET    | `/api/coordinator/product-loading-orders/{id}`                     |
 * | GET    | `/api/coordinator/product-loading-orders/{id}/picking-sheet`       |
 * | POST   | `/api/coordinator/product-loading-orders`                          |
 * | POST   | `/api/coordinator/product-loading-orders/batch`                    |
 * | PUT    | `/api/coordinator/product-loading-orders/{id}`                     |
 * | POST   | `/api/coordinator/product-loading-orders/{id}/dispatch`            |
 * | POST   | `/api/coordinator/product-loading-orders/{id}/reconcile`           |
 * | POST   | `/api/coordinator/product-loading-orders/{id}/cancel`              |
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorProductLoadingController
{
    private ProductLoadingOrderService $orderService;

    private RestockerVanStockService $stockService;

    public function __construct(
        ?ProductLoadingOrderService $orderService = null,
        ?RestockerVanStockService $stockService = null,
        ?PDO $pdo = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $orderRepository = new PdoProductLoadingOrderRepository($pdo);
        $stockRepository = new PdoRestockerVanStockRepository($pdo);
        $productRepository = new PdoVendingProductRepository($pdo);
        $userRepository = new PdoUserRepository($pdo);

        $this->orderService = $orderService ?? new ProductLoadingOrderService(
            $orderRepository,
            $stockRepository,
            $productRepository,
            $userRepository,
            new AuditLogger(),
            $pdo
        );

        $this->stockService = $stockService ?? new RestockerVanStockService(
            $stockRepository,
            $productRepository,
            $userRepository,
            new AuditLogger(),
            $pdo
        );
    }

    /** GET /api/coordinator/restocker-van-stocks */
    public function listVanStocks(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $restockerId = $this->optionalPositiveQuery($request, 'restocker_id');
            $category = $this->optionalQuery($request, 'category');
            $expiryStatus = $this->optionalQuery($request, 'expiry_status');
            $search = $this->optionalQuery($request, 'search');

            return Response::json(
                $this->stockService->getCoordinatorInventory($restockerId, $category, $expiryStatus, $search),
                200
            );
        });
    }

    /** PUT /api/coordinator/restocker-van-stocks/{restockerId} */
    public function upsertVanStock(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $restockerId = $this->positiveRouteId($request, 'restockerId', 'reponedor');
            $result = $this->stockService->adjustStock($restockerId, $request->getParsedBody(), $this->actor($request));

            return Response::json(
                $result,
                200,
                'Stock de la furgoneta ajustado con auditoría.'
            );
        });
    }

    /** GET /api/coordinator/product-loading-orders/proposal */
    public function getProposal(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $restockerId = $this->optionalPositiveQuery($request, 'restocker_id');
            if ($restockerId === null) {
                throw new InvalidArgumentException('El parámetro restocker_id es obligatorio y debe ser un entero positivo.');
            }

            $demandMode = $this->optionalQuery($request, 'demand_mode') ?? 'ALERTS_ONLY';
            $buffer = $this->optionalNonNegativeQuery($request, 'safety_buffer_pct') ?? 10;

            return Response::json(
                $this->orderService->buildProposal($restockerId, $demandMode, $buffer),
                200
            );
        });
    }

    /** GET /api/coordinator/product-loading-orders */
    public function listOrders(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $status = $this->optionalQuery($request, 'status');
            $restockerId = $this->optionalPositiveQuery($request, 'restocker_id');
            $limit = $this->optionalBoundedQuery($request, 'limit', 1, 200) ?? 100;
            $offset = $this->optionalNonNegativeQuery($request, 'offset') ?? 0;

            return Response::json(
                $this->orderService->listOrders($status, $restockerId, $limit, $offset),
                200
            );
        });
    }

    /** GET /api/coordinator/product-loading-orders/{id} */
    public function getOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->orderService->getOrder($this->positiveRouteId($request, 'id', 'orden')),
                200
            );
        });
    }

    /** GET /api/coordinator/product-loading-orders/{id}/picking-sheet */
    public function getPickingSheet(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->orderService->getPickingSheet($this->positiveRouteId($request, 'id', 'orden')),
                200
            );
        });
    }

    /** POST /api/coordinator/product-loading-orders */
    public function createOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $restockerId = $this->requiredPositiveBody($request, 'restocker_id');
            $created = $this->orderService->createOrder(
                $restockerId,
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json($created, 201, 'Orden de carga emitida en preparación.');
        });
    }

    /** POST /api/coordinator/product-loading-orders/batch */
    public function createBatchOrders(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $batch = $this->orderService->createBatchOrders($request->getParsedBody(), $this->actor($request));

            return Response::json([
                'created' => $batch['created'],
                'skipped' => $batch['skipped'],
                'created_count' => count($batch['created']),
                'skipped_count' => count($batch['skipped']),
            ], 200, 'Emisión masiva de órdenes de carga completada.');
        });
    }

    /** PUT /api/coordinator/product-loading-orders/{id} */
    public function updateOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->orderService->updateOrder(
                    $this->positiveRouteId($request, 'id', 'orden'),
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                200,
                'Orden de carga editada sin borrado físico.'
            );
        });
    }

    /** POST /api/coordinator/product-loading-orders/{id}/dispatch */
    public function dispatchOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->orderService->dispatchOrder(
                    $this->positiveRouteId($request, 'id', 'orden'),
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                200,
                'Orden de carga despachada con cerrojo sanitario de frío.'
            );
        });
    }

    /** POST /api/coordinator/product-loading-orders/{id}/reconcile */
    public function reconcileOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->orderService->reconcileOrder(
                    $this->positiveRouteId($request, 'id', 'orden'),
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                200,
                'Orden de carga liquidada al cierre.'
            );
        });
    }

    /** POST /api/coordinator/product-loading-orders/{id}/cancel */
    public function cancelOrder(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $reason = $request->getBodyParam('cancel_reason') ?? $request->getBodyParam('reason');

            return Response::json(
                $this->orderService->cancelOrder(
                    $this->positiveRouteId($request, 'id', 'orden'),
                    is_string($reason) ? $reason : null,
                    $this->actor($request)
                ),
                200,
                'Orden de carga cancelada con justificación.'
            );
        });
    }

    /**
     * @param callable(): Response $operation
     */
    private function execute(callable $operation): Response
    {
        try {
            return $operation();
        } catch (ProductLoadingValidationException $exception) {
            $details = $exception->getField() === null
                ? null
                : array_merge(['field' => $exception->getField()], $exception->getContext());

            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $details
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en la superficie de carga de producto: ' . $exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo completar la operación de carga de producto.',
                500
            );
        }
    }

    /**
     * @return array{id: int, role: string, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        $userId = (int)($request->getAttribute('user_id') ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('La operación exige una sesión interna autenticada.');
        }

        return [
            'id' => $userId,
            'role' => (string)($request->getAttribute('user_role') ?? 'COORDINATOR'),
            'name' => $user instanceof User ? $user->getName() : 'Coordinación',
        ];
    }

    private function positiveRouteId(Request $request, string $param, string $label): int
    {
        $raw = (string)($request->getRouteParam($param) ?? '');
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException(sprintf(
                'El identificador de %s debe ser un número entero positivo.',
                $label
            ));
        }

        return (int)$raw;
    }

    private function requiredPositiveBody(Request $request, string $field): int
    {
        $value = $request->getBodyParam($field);
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value)) && (int)trim($value) > 0) {
            return (int)trim($value);
        }

        throw new InvalidArgumentException(sprintf(
            'El campo %s es obligatorio y debe ser un entero positivo.',
            $field
        ));
    }

    private function optionalQuery(Request $request, string $field): ?string
    {
        $value = $request->getQuery($field);
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized === '' ? null : $normalized;
    }

    private function optionalPositiveQuery(Request $request, string $field): ?int
    {
        $value = $this->optionalQuery($request, $field);
        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe ser un entero positivo.',
                $field
            ));
        }

        return (int)$value;
    }

    private function optionalNonNegativeQuery(Request $request, string $field): ?int
    {
        $value = $this->optionalQuery($request, $field);
        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe ser un entero no negativo.',
                $field
            ));
        }

        return (int)$value;
    }

    private function optionalBoundedQuery(Request $request, string $field, int $minimum, int $maximum): ?int
    {
        $value = $this->optionalPositiveQuery($request, $field);
        if ($value === null) {
            return null;
        }

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe estar entre %d y %d.',
                $field,
                $minimum,
                $maximum
            ));
        }

        return $value;
    }
}
