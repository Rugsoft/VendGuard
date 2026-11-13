<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachinePlanogramService;
use VendGuard\Application\Service\VendingProductService;
use VendGuard\Core\Domain\Exception\DuplicateProductSkuException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Exception\ProductNotFoundException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorPlanogramController
 *
 * Superficie REST del catálogo maestro y del planograma por máquina para
 * Coordinación (Módulo 32, `RF-01` a `RF-05`). Traduce HTTP a los casos de uso de
 * `VendingProductService` y `MachinePlanogramService`; las reglas sanitarias,
 * el SKU inmutable, los `UPSERT`, los presets y la clonación viven en los
 * servicios y en el dominio, no aquí.
 *
 * | Método | Ruta                                                        | Operación            |
 * |--------|-------------------------------------------------------------|----------------------|
 * | GET    | `/api/coordinator/products`                                  | `listProducts`       |
 * | POST   | `/api/coordinator/products`                                  | `createProduct` (201)|
 * | PUT    | `/api/coordinator/products/{id}`                             | `updateProduct`      |
 * | PATCH  | `/api/coordinator/products/{id}/status`                      | `changeStatus`       |
 * | GET    | `/api/coordinator/machines/{id}/planogram`                   | `getPlanogram`       |
 * | PUT    | `/api/coordinator/machines/{id}/planogram`                   | `savePlanogram`      |
 * | PUT    | `/api/coordinator/machines/{id}/planogram/slots/{slot_code}` | `saveSlot`           |
 * | DELETE | `/api/coordinator/machines/{id}/planogram/slots/{slot_code}` | `deactivateSlot`     |
 * | POST   | `/api/coordinator/machines/{id}/planogram/apply-preset`      | `applyPreset`        |
 * | POST   | `/api/coordinator/machines/{id}/planogram/clone`             | `clonePlanogram`     |
 *
 * La lectura de Coordinación incluye `unit_cost_eur` y margen; la del
 * reponedor vive en `RestockerRouteController` y los omite por completo
 * (`Art. V.4`, `D2`, `QA 2`).
 *
 * **Mapa de errores:** `409 DUPLICATE_PRODUCT_SKU`, `SKU_CHANGE_FORBIDDEN`,
 * `COLD_CHAIN_MACHINE_INCOMPATIBLE`, `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`;
 * `422` de cotas/carril/`PRODUCT_INACTIVE`; `404 PRODUCT_NOT_FOUND` /
 * `MACHINE_NOT_FOUND`; `400 VALIDATION_ERROR`; `500 INTERNAL_SERVER_ERROR`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los servicios, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorPlanogramController
{
    private VendingProductService $productService;

    private MachinePlanogramService $planogramService;

    public function __construct(
        ?VendingProductService $productService = null,
        ?MachinePlanogramService $planogramService = null,
        ?PDO $pdo = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $productRepository = new PdoVendingProductRepository($pdo);

        $this->productService = $productService
            ?? new VendingProductService($productRepository, new AuditLogger());
        $this->planogramService = $planogramService
            ?? new MachinePlanogramService(
                new PdoMachinePlanogramRepository($pdo),
                $productRepository,
                new AuditLogger()
            );
    }

    /**
     * GET /api/coordinator/products
     */
    public function listProducts(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->productService->listProducts($request->getQueryParams()),
                200
            );
        });
    }

    /**
     * POST /api/coordinator/products
     */
    public function createProduct(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $product = $this->productService->createProduct(
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json($product->toArray(), 201, 'Producto creado exitosamente.');
        });
    }

    /**
     * PUT /api/coordinator/products/{id}
     */
    public function updateProduct(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $product = $this->productService->updateProduct(
                $this->positiveRouteId($request, 'id'),
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json($product->toArray(), 200, 'Producto actualizado exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/products/{id}/status
     */
    public function changeStatus(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $body = $request->getParsedBody();
            if (!array_key_exists('is_active', $body)) {
                throw new InvalidArgumentException('El campo is_active es obligatorio para cambiar el estado del producto.');
            }

            $result = $this->productService->changeStatus(
                $this->positiveRouteId($request, 'id'),
                $this->strictBool($body['is_active'], 'is_active'),
                $this->actor($request)
            );

            $data = $result['product']->toArray();
            $data['affected_active_slots_count'] = $result['affected_active_slots_count'];

            return Response::json(
                $data,
                200,
                $result['product']->isActive()
                    ? 'Producto reactivado exitosamente.'
                    : 'Producto dado de baja lógicamente. Sus carriles no se eliminan.'
            );
        });
    }

    /**
     * GET /api/coordinator/machines/{id}/planogram
     */
    public function getPlanogram(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->planogramService->getCoordinatorPlanogram($this->positiveRouteId($request, 'id')),
                200
            );
        });
    }

    /**
     * PUT /api/coordinator/machines/{id}/planogram
     */
    public function savePlanogram(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $body = $request->getParsedBody();
            $slots = $body['slots'] ?? null;
            if (!is_array($slots)) {
                throw new InvalidArgumentException('El cuerpo debe incluir el arreglo slots con la matriz de carriles.');
            }

            return Response::json(
                $this->planogramService->saveMachinePlanogram(
                    $this->positiveRouteId($request, 'id'),
                    array_values($slots),
                    $this->actor($request)
                ),
                200,
                'Planograma guardado exitosamente.'
            );
        });
    }

    /**
     * PUT /api/coordinator/machines/{id}/planogram/slots/{slot_code}
     */
    public function saveSlot(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->planogramService->saveSlot(
                    $this->positiveRouteId($request, 'id'),
                    $this->slotCode($request),
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                200,
                'Carril guardado exitosamente.'
            );
        });
    }

    /**
     * DELETE /api/coordinator/machines/{id}/planogram/slots/{slot_code}
     *
     * Baja lógica idempotente: un carril ya retirado vuelve a responder `200`.
     */
    public function deactivateSlot(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $machineId = $this->positiveRouteId($request, 'id');
            $slotCode = $this->slotCode($request);
            $this->planogramService->deactivateSlot($machineId, $slotCode, $this->actor($request));

            return Response::json(
                ['slot_code' => $slotCode, 'is_active' => false],
                200,
                'Carril retirado lógicamente del planograma.'
            );
        });
    }

    /**
     * POST /api/coordinator/machines/{id}/planogram/apply-preset
     */
    public function applyPreset(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $body = $request->getParsedBody();

            return Response::json(
                $this->planogramService->applyPreset(
                    $this->positiveRouteId($request, 'id'),
                    (string)($body['initial_stock_mode'] ?? MachinePlanogramService::STOCK_ZERO),
                    $this->actor($request)
                ),
                200,
                'Plantilla del modelo aplicada exitosamente.'
            );
        });
    }

    /**
     * POST /api/coordinator/machines/{id}/planogram/clone
     */
    public function clonePlanogram(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $body = $request->getParsedBody();
            $sourceMachineId = $body['source_machine_id'] ?? null;
            if (!is_numeric($sourceMachineId) || (int)$sourceMachineId <= 0) {
                throw new InvalidArgumentException('El campo source_machine_id debe ser un identificador positivo.');
            }

            return Response::json(
                $this->planogramService->clonePlanogram(
                    $this->positiveRouteId($request, 'id'),
                    (int)$sourceMachineId,
                    (string)($body['initial_stock_mode'] ?? MachinePlanogramService::STOCK_ZERO),
                    $this->actor($request)
                ),
                200,
                'Planograma clonado exitosamente.'
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
        } catch (PlanogramValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getContext()
            );
        } catch (ProductNotFoundException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['product_id' => $exception->getProductId()]
            );
        } catch (DuplicateProductSkuException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['sku' => $exception->getSku()]
            );
        } catch (MachineNotFoundException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en el catálogo o planograma de Coordinación: ' . $exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo completar la operación del catálogo o del planograma.',
                500
            );
        }
    }

    /**
     * @return array{id: int|null, role: string, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id' => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        $userId = $request->getAttribute('user_id');
        $userRole = $request->getAttribute('user_role');

        return [
            'id' => is_numeric($userId) ? (int)$userId : null,
            'role' => is_string($userRole) && $userRole !== '' ? $userRole : 'COORDINATOR',
            'name' => 'Coordinación',
        ];
    }

    private function positiveRouteId(Request $request, string $name): int
    {
        $raw = $request->getRouteParam($name);
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException(
                sprintf('El identificador «%s» en la URL debe ser un número entero positivo.', $name)
            );
        }

        return (int)$raw;
    }

    private function slotCode(Request $request): string
    {
        $raw = strtoupper(trim((string)$request->getRouteParam('slot_code')));
        if ($raw === '') {
            throw new InvalidArgumentException('El código de carril es obligatorio en la URL.');
        }

        return $raw;
    }

    private function strictBool(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException(sprintf('El campo %s debe ser un booleano.', $field));
    }
}
