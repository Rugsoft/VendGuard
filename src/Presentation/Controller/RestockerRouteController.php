<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachinePlanogramService;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Application\Service\RestockerVanStockService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineOutsideRestockerZonesException;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * RestockerRouteController
 *
 * Bootstrap operativo de la vista móvil `RestockerRouteView.js` (Módulo 31,
 * `D1`, `RF-04.1`). Publica el perfil del reponedor autenticado, sus zonas vivas
 * (`PRIMARY` y `BACKUP`), el estado de su turno y el resumen del día; los
 * parques de carga llegarán con las Features 24/26/27 y hoy viajan vacíos.
 *
 * El reponedor sale siempre del `user_id` que inyecta el middleware tras
 * validar el token: no se acepta ningún identificador de la query ni del
 * cuerpo (Art. V.4). La ruta es exclusiva de `RESTOCKER`; Coordinación y los
 * técnicos SAT reciben `403`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerRouteController
{
    private UserRepositoryInterface $userRepo;

    private OperationalZoneRepositoryInterface $zoneRepo;

    private ShiftLifecycleService $lifecycleService;

    private MachinePlanogramService $planogramService;

    private ProductLoadingOrderService $loadingOrderService;

    private RestockerVanStockService $vanStockService;

    public function __construct(
        ?PDO $pdo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?OperationalZoneRepositoryInterface $zoneRepo = null,
        ?ShiftLifecycleService $lifecycleService = null,
        ?MachinePlanogramService $planogramService = null,
        ?ProductLoadingOrderService $loadingOrderService = null,
        ?RestockerVanStockService $vanStockService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $this->userRepo = $userRepo ?? new PdoUserRepository($pdo);
        $this->zoneRepo = $zoneRepo ?? new PdoOperationalZoneRepository($pdo);

        $lockRepo = $this->userRepo instanceof TechnicianLockRepositoryInterface
            ? $this->userRepo
            : new PdoUserRepository($pdo);

        $this->lifecycleService = $lifecycleService ?? new ShiftLifecycleService(
            new PdoTechnicianShiftRepository($pdo),
            new PdoTechnicianAbsenceRepository($pdo),
            $this->userRepo,
            new PdoAuditLogRepository($pdo),
            new PdoTransactionManager($pdo),
            $lockRepo,
            new PdoTechnicianShiftLogRepository($pdo)
        );

        $this->planogramService = $planogramService ?? new MachinePlanogramService(
            new PdoMachinePlanogramRepository($pdo),
            new PdoVendingProductRepository($pdo),
            new AuditLogger()
        );

        $orderRepository = new PdoProductLoadingOrderRepository($pdo);
        $stockRepository = new PdoRestockerVanStockRepository($pdo);
        $productRepository = new PdoVendingProductRepository($pdo);

        $this->loadingOrderService = $loadingOrderService ?? new ProductLoadingOrderService(
            $orderRepository,
            $stockRepository,
            $productRepository,
            $this->userRepo,
            new AuditLogger(),
            $pdo
        );

        $this->vanStockService = $vanStockService ?? new RestockerVanStockService(
            $stockRepository,
            $productRepository,
            $this->userRepo,
            new AuditLogger(),
            $pdo
        );
    }

    /**
     * GET /api/restocker/my-route
     */
    public function myRoute(Request $request): Response
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        $restocker = $restockerId > 0 ? $this->userRepo->findById($restockerId) : null;
        if ($restocker === null) {
            return Response::error('UNAUTHORIZED', 'La sesión no identifica a un reponedor activo.', 401);
        }

        try {
            $quadrant = $this->findQuadrant($restockerId);

            $primaryZone = null;
            $backupZones = [];
            foreach ($quadrant['zones'] ?? [] as $zone) {
                $projected = [
                    'id' => (int)$zone['zone_id'],
                    'code' => (string)$zone['code'],
                    'name' => (string)$zone['name'],
                    'color_hex' => isset($zone['color_hex']) ? (string)$zone['color_hex'] : null,
                    'role_in_zone' => (string)$zone['role_in_zone'],
                ];

                if (!empty($zone['is_primary'])) {
                    $primaryZone = $projected;
                } else {
                    $backupZones[] = $projected;
                }
            }

            $shift = $this->lifecycleService->getCurrentShift($restockerId);
            $machines = $this->planogramService->listRestockerMachines($restockerId);
            $loadingContext = $this->loadingOrderService->getRestockerLoadingContext($restockerId);
            $vanStock = $this->vanStockService->getOwnVanStock($restockerId);

            return Response::json([
                'restocker' => [
                    'id' => $restocker->getId(),
                    'name' => $restocker->getName(),
                    'email' => $restocker->getEmail(),
                    'operator_code' => $quadrant['operator_code'] ?? null,
                    'role' => $restocker->getRole()->value,
                ],
                'zones' => [
                    'primary_zone' => $primaryZone,
                    'backup_zones' => $backupZones,
                ],
                'shift' => [
                    'status' => $shift->status,
                    'active_shift_id' => $shift->id,
                    'started_at' => $shift->startedAt,
                    'today_effective_seconds' => (int)($shift->todaySummary['effective_seconds'] ?? 0),
                ],
                'machines' => $machines,
                'route_stops' => [],
                'active_loading_order' => $loadingContext['active_loading_order'],
                'last_reconciled_order' => $loadingContext['last_reconciled_order'],
                'van_product_stocks' => $vanStock['stocks'],
                'van_stock_kpis' => $vanStock['kpis'],
                'has_expired_van_lots' => $vanStock['has_expired_lots'],
            ], 200);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al construir la ruta de carga del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar la ruta de carga.', 500);
        }
    }

    /**
     * GET /api/restocker/machines
     *
     * Máquinas de las zonas vivas del reponedor autenticado, con el resumen del
     * planograma y filtros opcionales `zone_id` y `stock_alert_only` (`QA 2`).
     */
    public function listMachines(Request $request): Response
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        if ($restockerId <= 0) {
            return Response::error('UNAUTHORIZED', 'La sesión no identifica a un reponedor activo.', 401);
        }

        try {
            return Response::json([
                'machines' => $this->planogramService->listRestockerMachines(
                    $restockerId,
                    $request->getQueryParams()
                ),
            ], 200);
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al listar las máquinas del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar el parque de máquinas.', 500);
        }
    }

    /**
     * GET /api/restocker/machines/{id}/planogram
     *
     * Lectura de solo carriles activos, sin `unit_cost_eur` ni margen. Fuera de
     * las zonas vivas del reponedor responde `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`.
     */
    public function getPlanogram(Request $request): Response
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        if ($restockerId <= 0) {
            return Response::error('UNAUTHORIZED', 'La sesión no identifica a un reponedor activo.', 401);
        }

        try {
            $machineId = $this->positiveRouteId($request);

            return Response::json(
                $this->planogramService->getRestockerPlanogram($restockerId, $machineId),
                200
            );
        } catch (MachineOutsideRestockerZonesException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['machine_id' => $exception->getMachineId()]
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
            error_log('[vendguard] Fallo al leer el planograma del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar el planograma de la máquina.', 500);
        }
    }

    /**
     * GET /api/restocker/van-stocks
     *
     * Stock FEFO del propio reponedor autenticado, con semáforo de caducidad y
     * sin `unit_cost_eur` ni precios (Art. V.4).
     */
    public function vanStocks(Request $request): Response
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        if ($restockerId <= 0) {
            return Response::error('UNAUTHORIZED', 'La sesión no identifica a un reponedor activo.', 401);
        }

        try {
            return Response::json($this->vanStockService->getOwnVanStock($restockerId), 200);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al leer el stock de la furgoneta: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar el stock de la furgoneta.', 500);
        }
    }

    /**
     * POST /api/restocker/product-loading-orders/{id}/confirm
     *
     * Recepción en un toque de la orden propia. El reponedor sale del token y
     * el servicio aplica el aislamiento horizontal (`403` si no es su orden).
     */
    public function confirmLoadingOrder(Request $request): Response
    {
        return $this->executeLoadingOrder(function () use ($request): Response {
            $restockerId = $this->authenticatedRestockerId($request);

            return Response::json(
                $this->loadingOrderService->confirmOrder(
                    $this->loadingRouteId($request),
                    $restockerId,
                    $this->actor($request)
                ),
                200,
                'Recepción de la orden confirmada en la furgoneta.'
            );
        });
    }

    /**
     * POST /api/restocker/product-loading-orders/{id}/reconcile
     *
     * Liquidación al cierre de la orden propia con las mismas reglas de cuadre
     * y justificación que la superficie de Coordinación (D3, QA 4, Art. V.1).
     */
    public function reconcileLoadingOrder(Request $request): Response
    {
        return $this->executeLoadingOrder(function () use ($request): Response {
            $restockerId = $this->authenticatedRestockerId($request);

            return Response::json(
                $this->loadingOrderService->reconcileOrder(
                    $this->loadingRouteId($request),
                    $request->getParsedBody(),
                    $this->actor($request),
                    $restockerId
                ),
                200,
                'Orden de carga liquidada al cierre.'
            );
        });
    }

    private function executeLoadingOrder(callable $operation): Response
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
            error_log('[vendguard] Fallo en la orden de carga del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la operación de carga.', 500);
        }
    }

    private function authenticatedRestockerId(Request $request): int
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('La sesión no identifica a un reponedor activo.');
        }

        return $restockerId;
    }

    /**
     * @return array{id: int, role: string, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');

        return [
            'id' => $this->authenticatedRestockerId($request),
            'role' => (string)($request->getAttribute('user_role') ?? 'RESTOCKER'),
            'name' => $user instanceof User ? $user->getName() : 'Reposición',
        ];
    }

    private function loadingRouteId(Request $request): int
    {
        $raw = (string)($request->getRouteParam('id') ?? '');
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('El identificador de la orden de carga debe ser un número entero positivo.');
        }

        return (int)$raw;
    }

    private function positiveRouteId(Request $request): int
    {
        $raw = $request->getRouteParam('id');
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('El identificador de máquina debe ser un número entero positivo.');
        }

        return (int)$raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQuadrant(int $restockerId): ?array
    {
        foreach ($this->zoneRepo->findRestockerQuadrantsByTechnician() as $row) {
            if ((int)$row['restocker_id'] === $restockerId) {
                return $row;
            }
        }

        return null;
    }
}
