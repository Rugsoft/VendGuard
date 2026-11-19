<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachinePlanogramService;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Application\Service\MachineRestockVisitService;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Application\Service\RestockerSatSynergyService;
use VendGuard\Application\Service\RestockerVanStockService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Application\Service\SuggestedTechniciansService;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineOutsideRestockerZonesException;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoInSituRestockVisitGateway;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionGateway;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionRepository;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRestockVisitRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianSuggestionRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * RestockerRouteController
 *
 * Bootstrap y superficie operativa de la vista móvil `RestockerRouteView.js`
 * (Módulos 31 y 34; `D1`, `RF-04.1`, `RF-01` a `RF-04`). Publica el perfil del
 * reponedor autenticado, sus zonas vivas (`PRIMARY` y `BACKUP`), el estado de su
 * turno, el resumen del día y sus visitas recientes, además de registrar visitas
 * in situ, evidencia fotográfica y conflictos offline.
 *
 * El reponedor sale siempre del `user_id` que inyecta el middleware tras
 * validar el token: no se acepta ningún identificador de la query ni del
 * cuerpo (Art. V.4). La ruta es exclusiva de `RESTOCKER`; Coordinación y los
 * técnicos SAT reciben `403`. Las respuestas de esta superficie nunca incluyen
 * `unit_cost_snapshot_eur` ni `commercial_spoilage_loss_eur` (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerRouteController
{
    private PDO $pdo;

    private UserRepositoryInterface $userRepo;

    private OperationalZoneRepositoryInterface $zoneRepo;

    private ShiftLifecycleService $lifecycleService;

    private MachinePlanogramService $planogramService;

    private ProductLoadingOrderService $loadingOrderService;

    private RestockerVanStockService $vanStockService;

    private MachineRestockVisitService $visitService;

    private MachineRestockVisitRepositoryInterface $visitRepository;

    private MachineCashCollectionService $cashCollections;

    private ?RestockerSatSynergyService $synergyService;

    public function __construct(
        ?PDO $pdo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?OperationalZoneRepositoryInterface $zoneRepo = null,
        ?ShiftLifecycleService $lifecycleService = null,
        ?MachinePlanogramService $planogramService = null,
        ?ProductLoadingOrderService $loadingOrderService = null,
        ?RestockerVanStockService $vanStockService = null,
        ?MachineRestockVisitService $visitService = null,
        ?MachineRestockVisitRepositoryInterface $visitRepository = null,
        ?MachineCashCollectionService $cashCollections = null,
        ?RestockerSatSynergyService $synergyService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $this->pdo = $pdo;
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

        $orderRepository = new PdoProductLoadingOrderRepository($pdo);
        $stockRepository = new PdoRestockerVanStockRepository($pdo);
        $productRepository = new PdoVendingProductRepository($pdo);

        $this->visitRepository = $visitRepository ?? new PdoMachineRestockVisitRepository($pdo);
        $this->cashCollections = $cashCollections ?? new MachineCashCollectionService(
            new PdoMachineCashCollectionRepository($pdo),
            new PdoMachineCashCollectionGateway($pdo),
            new PdoRefundRequestRepository($pdo),
            new PdoTransactionManager($pdo),
            new AuditLogger()
        );

        $this->planogramService = $planogramService ?? new MachinePlanogramService(
            new PdoMachinePlanogramRepository($pdo),
            $productRepository,
            new AuditLogger(),
            $stockRepository
        );

        $this->loadingOrderService = $loadingOrderService ?? new ProductLoadingOrderService(
            $orderRepository,
            $stockRepository,
            $productRepository,
            $this->userRepo,
            new AuditLogger(),
            $pdo,
            null,
            $this->visitRepository
        );

        $this->vanStockService = $vanStockService ?? new RestockerVanStockService(
            $stockRepository,
            $productRepository,
            $this->userRepo,
            new AuditLogger(),
            $pdo
        );

        $this->synergyService = $synergyService ?? $this->buildSynergyService($pdo);

        $this->visitService = $visitService ?? new MachineRestockVisitService(
            $this->visitRepository,
            new PdoMachinePlanogramRepository($pdo),
            $stockRepository,
            new PdoInSituRestockVisitGateway(
                pdo: $pdo,
                notifications: NotificationOutboxEnqueuerFactory::create($pdo)
            ),
            new PdoTransactionManager($pdo),
            new AuditLogger(),
            null,
            $this->cashCollections
        );
    }

    /**
     * Compone el escalado real: resuelve el técnico con el recomendador de
     * siempre y encola el aviso en el outbox de notificaciones.
     */
    private function buildSynergyService(PDO $pdo): RestockerSatSynergyService
    {
        $suggestions = new SuggestedTechniciansService(
            new PdoTechnicianSuggestionRepository($pdo)
        );
        $incidents = new PdoIncidentRepository($pdo);
        $notifications = NotificationOutboxEnqueuerFactory::create($pdo);

        $resolver = static function (int $machineId, array $payload) use ($suggestions, $incidents): ?array {
            $incident = $incidents->findActiveByMachineId($machineId);
            $suggestion = $incident !== null
                ? $suggestions->getSuggestions($incident->getId())
                : $suggestions->getSuggestionsForMachine(
                    $machineId,
                    (string)($payload['category'] ?? 'OTHER'),
                    isset($payload['urgency']) ? (string)$payload['urgency'] : null,
                    isset($payload['sla_tier_snapshot']) ? (string)$payload['sla_tier_snapshot'] : null,
                    isset($payload['sla_target_minutes']) ? (int)$payload['sla_target_minutes'] : null
                );

            if ($suggestion === null) {
                return null;
            }

            $topId = (int)($suggestion->recommendation['top_technician_id'] ?? 0);
            if ($topId <= 0) {
                return null;
            }

            foreach ($suggestion->rankedTechnicians as $candidate) {
                if ((int)($candidate['technician_id'] ?? 0) === $topId) {
                    return [
                        'id' => $topId,
                        'name' => (string)($candidate['name'] ?? ''),
                    ];
                }
            }

            return ['id' => $topId];
        };

        $notifier = static function (string $event, array $payload) use ($notifications, $incidents): void {
            if ($event !== RestockerSatSynergyService::EVENT_ESCALATED_TO_SAT
                || ($payload['ok'] ?? false) !== true
                || ($payload['idempotent_replay'] ?? false) === true
                || ($payload['escalation_outcome'] ?? '') === 'MERGED_INTO_EXISTING_SAT_TICKET') {
                return;
            }

            $incidentId = (int)($payload['incident_id'] ?? 0);
            $incident = $incidentId > 0 ? $incidents->findById($incidentId) : null;
            if ($incident === null) {
                return;
            }

            if (($payload['status'] ?? '') === 'ASSIGNED') {
                $notifications->enqueueAssignment($incident, $incident);

                return;
            }

            // Sin candidato la avería queda REGISTERED: Coordinación recibe la
            // alerta de cobertura para que el triaje no dependa de mirar el panel.
            $notifications->enqueueSla2hUnassignedAlert($incident);
        };

        return new RestockerSatSynergyService(
            new PdoRestockerSatSynergyGateway($pdo),
            $resolver,
            $notifier
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
            $cashRoute = $this->cashCollections->enrichRestockerRoute($restockerId, $machines);

            // Módulo 36 (D3/D4): avisos L1 asignados, banderas por máquina,
            // taxonomía de Nivel 1 y Coincidencia en Sede de las sedes de la ruta.
            $machineIds = array_map(static fn (array $machine): int => (int)($machine['id'] ?? 0), $cashRoute['machines']);
            $level1Context = (new \VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway($this->pdo))
                ->restockerLevel1RouteContext($restockerId, $machineIds);
            $machineFlags = $level1Context['machine_flags'];
            $machinesWithLevels = array_map(static function (array $machine) use ($machineFlags): array {
                $flags = $machineFlags[(int)($machine['id'] ?? 0)] ?? [];
                $machine['active_l1_incident'] = ($flags['has_active_l1_incident'] ?? false)
                    ? ['level1_issue_type' => $flags['active_l1_issue_type'] ?? null]
                    : null;
                $machine['has_active_sat_incident'] = (bool)($flags['has_active_sat_incident'] ?? false);

                return $machine;
            }, $cashRoute['machines']);

            $locationIds = [];
            foreach ($machinesWithLevels as $machine) {
                $locationId = (int)($machine['location_id'] ?? 0);
                if ($locationId > 0) {
                    $locationIds[$locationId] = $locationId;
                }
            }
            foreach ($level1Context['assigned_incidents'] as $incident) {
                $locationId = (int)($incident['location_id'] ?? 0);
                if ($locationId > 0) {
                    $locationIds[$locationId] = $locationId;
                }
            }
            $siteCoincidence = $locationIds !== []
                ? $this->synergy()->siteCoincidence('RESTOCKER', $restockerId, array_values($locationIds))
                : [];

            $level1Taxonomy = [];
            foreach ((new \VendGuard\Infrastructure\Repository\PdoRootCauseRepository($this->pdo))
                ->findActiveCatalogWithSolutions() as $cause) {
                if (!str_starts_with($cause->getCode(), 'RC-L1-')) {
                    continue;
                }
                $level1Taxonomy[] = $cause->toArray();
            }

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
                'machines' => $machinesWithLevels,
                'assigned_l1_incidents' => $level1Context['assigned_incidents'],
                'l1_taxonomy_catalog' => $level1Taxonomy,
                'site_coincidence' => $siteCoincidence,
                'route_stops' => [],
                'active_loading_order' => $loadingContext['active_loading_order'],
                'last_reconciled_order' => $loadingContext['last_reconciled_order'],
                'van_product_stocks' => $vanStock['stocks'],
                'van_stock_kpis' => $vanStock['kpis'],
                'has_expired_van_lots' => $vanStock['has_expired_lots'],
                'recent_visits' => $this->visitRepository->listForRestocker($restockerId, ['limit' => 5]),
                'pending_reception_refunds' => $cashRoute['pending_reception_refunds'],
                'recent_cash_collections' => $cashRoute['recent_cash_collections'],
                'cash_route_summary' => $cashRoute['cash_route_summary'],
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

    /**
     * GET /api/restocker/visits
     *
     * Historial del propio reponedor, con filtros `machine_id`, `status`,
     * `date_from`, `date_to`, `limit` y `offset`. El repositorio no selecciona
     * columnas de coste: la opacidad es estructural (`Art. V.4`).
     */
    public function listVisits(Request $request): Response
    {
        return $this->executeVisit(function () use ($request): Response {
            $restockerId = $this->authenticatedRestockerId($request);

            return Response::json([
                'visits' => $this->visitRepository->listForRestocker($restockerId, $request->getQueryParams()),
            ], 200);
        });
    }

    /**
     * POST /api/restocker/machines/{machineId}/visits
     *
     * Registro atómico e idempotente de una visita in situ. El reponedor sale
     * del token y el servicio aplica el Triple Cerrojo Sanitario; el controlador
     * solo traduce el desenlace a la envolvente HTTP canónica.
     */
    public function registerVisit(Request $request): Response
    {
        return $this->executeVisit(function () use ($request): Response {
            $machineId = $this->positiveMachineId($request);

            return Response::json(
                $this->visitService->registerVisit(
                    $machineId,
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                201,
                'Visita de reposición in situ registrada.'
            );
        });
    }

    /**
     * POST /api/restocker/machines/{machineId}/cash-collections
     *
     * Alta nueva: 201. Reenvío de la misma mutación: 200 con
     * `idempotent_replay = true` (RF-01.2.3).
     */
    public function registerCashCollection(Request $request): Response
    {
        return $this->executeCashCollection(function () use ($request): Response {
            $collection = $this->cashCollections->registerCollection(
                $this->positiveMachineId($request),
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json(
                $collection,
                ($collection['idempotent_replay'] ?? false) === true ? 200 : 201,
                'Recaudación en bolsa precintada registrada.'
            );
        });
    }

    /**
     * GET /api/restocker/cash-collections
     */
    public function listCashCollections(Request $request): Response
    {
        return $this->executeCashCollection(function () use ($request): Response {
            return Response::json(
                $this->cashCollections->listForRestocker(
                    $this->actor($request),
                    $this->cashCollectionFilters($request)
                ),
                200
            );
        });
    }

    /**
     * GET /api/restocker/cash-collections/{id}/reception-receipt
     */
    public function cashReceptionReceipt(Request $request): Response
    {
        return $this->executeCashCollection(function () use ($request): Response {
            return Response::json(
                $this->cashCollections->receptionReceipt(
                    $this->positiveCashCollectionId($request),
                    $this->actor($request)
                ),
                200
            );
        });
    }

    /**
     * POST /api/restocker/cash-collections/conflict-dump
     */
    public function dumpCashCollectionConflict(Request $request): Response
    {
        return $this->executeCashCollection(function () use ($request): Response {
            return Response::json(
                $this->cashCollections->dumpConflict($request->getParsedBody(), $this->actor($request)),
                201
            );
        });
    }

    /**
     * POST /api/restocker/visits/photo
     *
     * Evidencia fotográfica por `multipart/form-data` o base64 seguro. El
     * uploader valida el tope de 5 MB y los magic bytes reales (`Art. V.5`).
     */
    public function uploadVisitPhoto(Request $request): Response
    {
        $temporaryPath = null;

        try {
            $file = $request->getFile('photo')
                ?? $request->getFile('image')
                ?? $request->getFile('file');

            if ($file === null) {
                $encoded = $request->getBodyParam('photo_base64');
                if (!is_string($encoded) || trim($encoded) === '') {
                    throw new InvalidUploadException(
                        'NO_FILE_PROVIDED',
                        'No se ha recibido ninguna fotografía de evidencia.',
                        400
                    );
                }

                $payload = preg_replace('/^data:[^;]*;base64,/', '', trim($encoded));
                if (!is_string($payload)
                    || strlen($payload) > (int)ceil(LocalFileUploader::MAX_SIZE_BYTES * 4 / 3) + 8) {
                    throw new InvalidUploadException(
                        'FILE_TOO_LARGE',
                        'La fotografía adjunta supera el tamaño máximo permitido de 5 MB (RNF-05).',
                        422
                    );
                }

                $binary = base64_decode($payload, true);
                if ($binary === false || $binary === '') {
                    throw new InvalidUploadException(
                        'INVALID_FILE_TYPE',
                        'La fotografía base64 no es una imagen válida.',
                        422
                    );
                }
                if (strlen($binary) > LocalFileUploader::MAX_SIZE_BYTES) {
                    throw new InvalidUploadException(
                        'FILE_TOO_LARGE',
                        'La fotografía adjunta supera el tamaño máximo permitido de 5 MB (RNF-05).',
                        422
                    );
                }

                $temporaryPath = tempnam(sys_get_temp_dir(), 'vg_visit_photo_');
                if ($temporaryPath === false || file_put_contents($temporaryPath, $binary) === false) {
                    throw new InvalidUploadException(
                        'STORAGE_WRITE_ERROR',
                        'No se pudo preparar temporalmente la fotografía de evidencia.',
                        500
                    );
                }

                $file = [
                    'name' => 'visit-evidence',
                    'tmp_name' => $temporaryPath,
                    'size' => strlen($binary),
                    'error' => UPLOAD_ERR_OK,
                ];
            }

            return Response::json(
                ['photo_evidence_url' => $this->visitService->uploadPhoto($file)],
                201,
                'Fotografía de evidencia almacenada.'
            );
        } catch (InvalidUploadException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al subir la evidencia de la visita in situ: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo almacenar la fotografía de evidencia.', 500);
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * POST /api/restocker/incidents/{id}/start
     */
    public function startLevel1(Request $request): Response
    {
        return $this->executeSynergy(function () use ($request): Response {
            $body = $request->getParsedBody();
            $mutation = isset($body['client_mutation_id'])
                ? [
                    'client_mutation_id' => (string)$body['client_mutation_id'],
                    'client_occurred_at' => $body['client_occurred_at'] ?? null,
                ]
                : null;

            return Response::json(
                $this->synergy()->startLevel1($this->incidentRouteId($request), $this->actor($request), $mutation),
                200,
                'Intervención de Nivel 1 iniciada.'
            );
        });
    }

    /**
     * POST /api/restocker/incidents/{id}/resolve
     */
    public function resolveLevel1(Request $request): Response
    {
        return $this->executeSynergy(function () use ($request): Response {
            $body = $request->getParsedBody();
            $incident = [
                'id' => $this->incidentRouteId($request),
                'machine_id' => (int)($body['machine_id'] ?? 0),
                'sanitary_status' => $body['sanitary_status'] ?? null,
            ];
            $resolution = [
                'diagnosis' => $body['diagnosis'] ?? null,
                'action' => $body['action'] ?? null,
                'root_cause_id' => $body['root_cause_id'] ?? null,
                'standard_solution_id' => $body['standard_solution_id'] ?? null,
            ];
            $mutation = isset($body['client_mutation_id'])
                ? [
                    'client_mutation_id' => (string)$body['client_mutation_id'],
                    'client_occurred_at' => $body['client_occurred_at'] ?? null,
                ]
                : null;

            return Response::json(
                $this->synergy()->resolveLevel1($incident, $this->actor($request), $resolution, $mutation),
                200,
                'Nivel 1 resuelto y custodia cerrada.'
            );
        });
    }

    /**
     * POST /api/restocker/machines/{machineId}/escalate-to-sat
     */
    public function escalateToSat(Request $request): Response
    {
        return $this->executeSynergy(function () use ($request): Response {
            $body = $request->getParsedBody();
            $machineId = (int)($request->getRouteParam('machineId') ?? 0);
            $mutation = isset($body['client_mutation_id'])
                ? [
                    'client_mutation_id' => (string)$body['client_mutation_id'],
                    'client_occurred_at' => $body['client_occurred_at'] ?? null,
                ]
                : null;

            return Response::json(
                $this->synergy()->escalateToSat($machineId, $this->actor($request), $body, $mutation),
                201,
                'Avería escalada al SAT.'
            );
        });
    }

    /**
     * POST /api/restocker/incidents/conflict-dump
     */
    public function dumpIncidentConflict(Request $request): Response
    {
        return $this->executeSynergy(function () use ($request): Response {
            $body = $request->getParsedBody();
            $conflict = is_array($body['conflict'] ?? null) ? $body['conflict'] : [];

            return Response::json(
                $this->synergy()->dumpOfflineConflict($this->actor($request), $body, $conflict),
                201,
                'Conflicto offline archivado en auditoría.'
            );
        });
    }

    /**
     * POST /api/restocker/visits/conflict-dump
     *
     * Archiva en auditoría una mutación offline rechazada (`C3`, `Art. III.3`).
     */
    public function dumpVisitConflict(Request $request): Response
    {
        return $this->executeVisit(function () use ($request): Response {
            return Response::json(
                $this->visitService->dumpOfflineConflict($request->getParsedBody(), $this->actor($request)),
                200,
                'Conflicto offline archivado en auditoría.'
            );
        });
    }

    private function executeSynergy(callable $operation): Response
    {
        try {
            return $operation();
        } catch (RestockerSatSynergyValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getField() === null ? null : ['field' => $exception->getField()]
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en la operación de sinergia L1: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la operación de Nivel 1.', 500);
        }
    }

    private function synergy(): RestockerSatSynergyService
    {
        if ($this->synergyService === null) {
            throw new \RuntimeException('El servicio de sinergia no está disponible.');
        }

        return $this->synergyService;
    }

    private function incidentRouteId(Request $request): int
    {
        $raw = (string)($request->getRouteParam('id') ?? '');
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('El identificador de la avería debe ser un número entero positivo.');
        }

        return (int)$raw;
    }

    private function executeVisit(callable $operation): Response
    {
        try {
            return $operation();
        } catch (MachineRestockVisitValidationException $exception) {
            $details = $exception->getField() === null
                ? null
                : array_merge(['field' => $exception->getField()], $exception->getContext());

            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $details
            );
        } catch (MachineCashCollectionValidationException $exception) {
            $details = $exception->getField() === null
                ? null
                : array_merge(['field' => $exception->getField()], $exception->getContext());

            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $details
            );
        } catch (InvalidUploadException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en la visita de reposición in situ: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la visita de reposición in situ.', 500);
        }
    }

    private function positiveMachineId(Request $request): int
    {
        $raw = $request->getRouteParam('machineId');
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('El identificador de máquina debe ser un número entero positivo.');
        }

        return (int)$raw;
    }

    private function executeCashCollection(callable $operation): Response
    {
        try {
            return $operation();
        } catch (MachineCashCollectionValidationException $exception) {
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
            error_log('[vendguard] Fallo en la recaudación del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la recaudación.', 500);
        }
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

    private function positiveCashCollectionId(Request $request): int
    {
        $raw = (string)($request->getRouteParam('id') ?? '');
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('El identificador de recaudación debe ser un número entero positivo.');
        }

        return (int)$raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function cashCollectionFilters(Request $request): array
    {
        $query = $request->getQueryParams();
        $filters = [];

        if (isset($query['machine_id']) && trim((string)$query['machine_id']) !== '') {
            if (!ctype_digit((string)$query['machine_id']) || (int)$query['machine_id'] <= 0) {
                throw new InvalidArgumentException('El filtro machine_id debe ser un entero positivo.');
            }
            $filters['machine_id'] = (int)$query['machine_id'];
        }

        if (isset($query['status']) && trim((string)$query['status']) !== '') {
            $status = strtoupper(trim((string)$query['status']));
            if (CashCollectionStatus::tryFrom($status) === null) {
                throw new InvalidArgumentException('El filtro status no es un estado de recaudación válido.');
            }
            $filters['status'] = $status;
        }

        foreach (['limit' => 100, 'offset' => 0] as $key => $default) {
            if (!isset($query[$key]) || trim((string)$query[$key]) === '') {
                continue;
            }
            if (!ctype_digit((string)$query[$key])) {
                throw new InvalidArgumentException(sprintf('El filtro %s debe ser un entero no negativo.', $key));
            }
            $filters[$key] = (int)$query[$key] ?: $default;
        }

        return $filters;
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
