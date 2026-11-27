<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\DexAuditLinker;
use VendGuard\Application\Service\MachinePlanogramService;
use VendGuard\Application\Service\MachineCashCollectionService;
use VendGuard\Application\Service\MachineDexAuditService;
use VendGuard\Application\Service\MachineRestockVisitService;
use VendGuard\Application\Service\ProductLoadingOrderService;
use VendGuard\Application\Service\RestockRouteOverrideService;
use VendGuard\Application\Service\RestockRoutePlannerService;
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
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;
use VendGuard\Core\Service\RestockCadenceEvaluator;
use VendGuard\Core\Service\SlaTargetCalculator;
use VendGuard\Core\Service\UrgencyCalculator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoInSituRestockVisitGateway;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionGateway;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionRepository;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditGateway;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRestockVisitRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Infrastructure\Repository\PdoProductLoadingOrderRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoRestockRoutePlannerGateway;
use VendGuard\Infrastructure\Repository\PdoRestockRouteReadModel;
use VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianSuggestionRepository;
use VendGuard\Infrastructure\Repository\PdoRouteSettingsRepository;
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

    private RestockRoutePlannerService $routePlanner;

    private RestockRouteOverrideService $routeOverrides;

    private RouteSettingsRepositoryInterface $routeSettings;

    private ?MachineDexAuditService $dexAuditService;

    /** Registro de auditoría inyectable (PR25-06). */
    private ?AuditLogger $auditLogger = null;

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
        ?RestockerSatSynergyService $synergyService = null,
        ?RestockRoutePlannerService $routePlanner = null,
        ?RestockRouteOverrideService $routeOverrides = null,
        ?RouteSettingsRepositoryInterface $routeSettings = null,
        ?MachineDexAuditService $dexAuditService = null,
        ?AuditLogger $auditLogger = null
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
        $dexAuditLinks = new DexAuditLinker(
            new PdoMachineDexAuditRepository($pdo),
            new AuditLogger(new PdoAuditLogRepository($pdo))
        );
        $this->cashCollections = $cashCollections ?? new MachineCashCollectionService(
            new PdoMachineCashCollectionRepository($pdo),
            new PdoMachineCashCollectionGateway($pdo),
            new PdoRefundRequestRepository($pdo),
            new PdoTransactionManager($pdo),
            new AuditLogger(),
            $dexAuditLinks
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

        $this->synergyService = $synergyService ?? $this->buildSynergyService($pdo, $dexAuditLinks);

        $routeGateway = new PdoRestockRoutePlannerGateway($pdo);
        $routeReadModel = new PdoRestockRouteReadModel($pdo);
        $this->routePlanner = $routePlanner ?? new RestockRoutePlannerService($routeReadModel);
        $this->routeOverrides = $routeOverrides ?? new RestockRouteOverrideService(
            $routeGateway,
            $routeReadModel,
            $this->routePlanner,
            new AuditLogger(),
            new PdoTransactionManager($pdo)
        );
        $this->routeSettings = $routeSettings ?? new PdoRouteSettingsRepository($pdo);
        $this->dexAuditService = $dexAuditService;
        $this->auditLogger = $auditLogger;

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
            $this->cashCollections,
            $routeGateway,
            $routeReadModel,
            $dexAuditLinks
        );
    }

    /**
     * Compone el escalado real: resuelve el técnico con el recomendador de
     * siempre y encola el aviso en el outbox de notificaciones.
     */
    private function buildSynergyService(PDO $pdo, DexAuditLinker $dexAuditLinks): RestockerSatSynergyService
    {
        $suggestions = new SuggestedTechniciansService(
            new PdoTechnicianSuggestionRepository($pdo)
        );
        $incidents = new PdoIncidentRepository($pdo);
        $machines = new PdoMachineRepository($pdo);
        $locations = new PdoLocationRepository($pdo);
        $notifications = NotificationOutboxEnqueuerFactory::create($pdo);
        $beforeSnapshots = [];

        $resolver = static function (int $machineId, array $payload) use (
            $suggestions,
            $incidents,
            $machines,
            $locations,
            &$beforeSnapshots
        ): ?array {
            $incident = $incidents->findActiveByMachineId($machineId);
            $beforeSnapshots[$machineId] = $incident;

            $machine = $machines->findById($machineId);
            if ($machine === null) {
                return null;
            }

            // Si un ticket RESOLVED ya superó su garantía de 48 h, escalateToSat() lo
            // cerrará y abrirá un expediente nuevo (Rama B) con el cálculo fresco.
            if ($incident !== null && $incident->getStatus() === IncidentStatus::RESOLVED) {
                $occurredAtRaw = trim((string)($payload['client_occurred_at'] ?? ''));
                $occurredAt = $occurredAtRaw !== ''
                    ? new \DateTimeImmutable($occurredAtRaw)
                    : new \DateTimeImmutable('now');
                $resolvedAtRaw = $incident->getResolvedAt();
                $withinWarranty = $resolvedAtRaw !== null
                    && $occurredAt <= (new \DateTimeImmutable($resolvedAtRaw))->modify('+48 hours');
                if (!$withinWarranty) {
                    $incident = null;
                }
            }

            $incomingCategory = IncidentCategory::tryFrom(strtoupper(trim((string)($payload['category'] ?? 'OTHER'))))
                ?? IncidentCategory::OTHER;

            // La ponderación de candidatos (especialmente la guardia 24/7 de frío)
            // debe evaluarse con la severidad resultante post-escalado, no con la
            // categoría leve anterior de L1 o del parte previo (Art. II).
            $targetCategory = $incomingCategory;
            if ($incident !== null
                && !$incident->getDispatchLevel()->isLevel1()
                && $incident->getStatus() !== IncidentStatus::RESOLVED) {
                $severityOrder = static fn (IncidentCategory $cat): int => match ($cat) {
                    IncidentCategory::OTHER => 1,
                    IncidentCategory::PRODUCT_JAM => 2,
                    IncidentCategory::PAYMENT_SYSTEM => 3,
                    IncidentCategory::ELECTRICAL_OFF => 4,
                    IncidentCategory::TEMPERATURE_COLD => 5,
                };
                if ($severityOrder($incident->getCategory()) > $severityOrder($incomingCategory)) {
                    $targetCategory = $incident->getCategory();
                }
            }

            $calcUrgency = UrgencyCalculator::calculate($machine->getMachineType(), $targetCategory);
            $effectiveUrgency = ($incident !== null && $incident->getUrgency()->priorityRank() > $calcUrgency->priorityRank())
                ? $incident->getUrgency()
                : $calcUrgency;

            $slaTier = $incident?->getSlaTierSnapshot()
                ?? ($locations->findById($machine->getLocationId())?->getSlaTier() ?? SlaTier::STANDARD);
            $calcSlaMinutes = SlaTargetCalculator::calculateTargetMinutes(
                $slaTier,
                $machine->getMachineType(),
                $targetCategory,
                $effectiveUrgency
            );
            $effectiveSlaMinutes = $incident !== null
                ? min($incident->getSlaTargetMinutes(), $calcSlaMinutes)
                : $calcSlaMinutes;

            $suggestion = $suggestions->getSuggestionsForMachine(
                $machineId,
                $targetCategory->value,
                $effectiveUrgency->value,
                $slaTier->value,
                $effectiveSlaMinutes
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

        $notifier = static function (string $event, array $payload) use ($notifications, $incidents, &$beforeSnapshots): void {
            if ($event !== RestockerSatSynergyService::EVENT_ESCALATED_TO_SAT
                || ($payload['ok'] ?? false) !== true
                || ($payload['idempotent_replay'] ?? false) === true) {
                return;
            }

            $incidentId = (int)($payload['incident_id'] ?? 0);
            $after = $incidentId > 0 ? $incidents->findById($incidentId) : null;
            if ($after === null) {
                return;
            }

            $machineId = (int)($payload['machine_id'] ?? 0);
            $before = $beforeSnapshots[$machineId] ?? null;
            unset($beforeSnapshots[$machineId]);

            $outcome = (string)($payload['escalation_outcome'] ?? '');
            if ($outcome === 'MERGED_INTO_EXISTING_SAT_TICKET') {
                if (($payload['assigned_on_merge'] ?? false) === true
                    || ($payload['reopened_from_warranty'] ?? false) === true) {
                    if (($payload['status'] ?? '') === 'ASSIGNED') {
                        $notifications->enqueueAssignment($before ?? $after, $after);
                    } else {
                        $notifications->enqueueSla2hUnassignedAlert($after);
                    }
                }
                // Si la absorción o reapertura elevó a riesgo térmico, emitir la Alerta Inmediata de Frío (Art. II).
                $notifications->enqueueColdChainAlert($after);

                return;
            }

            if (($payload['status'] ?? '') === 'ASSIGNED') {
                $notifications->enqueueAssignment($before ?? $after, $after);
            } else {
                // Sin candidato la avería queda REGISTERED: Coordinación recibe la
                // alerta de cobertura para que el triaje no dependa de mirar el panel.
                $notifications->enqueueSla2hUnassignedAlert($after);
            }

            // En Rama B o cuando `$before === $after`, `enqueueAssignment` no dispara la
            // alerta diferencial de frío; la comprobación idempotente la garantiza siempre (Art. II).
            $notifications->enqueueColdChainAlert($after);
        };

        return new RestockerSatSynergyService(
            new PdoRestockerSatSynergyGateway($pdo),
            $resolver,
            $notifier,
            null,
            $dexAuditLinks,
            new PdoTransactionManager($pdo)
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

            $serviceDate = RestockCadenceEvaluator::today(
                new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'))
            );
            $route = $this->routePlanner->buildRestockerRoute(
                $restockerId,
                $serviceDate,
                $this->routeOrigin()
            );
            $routePlan = $route['route_plan'];

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
            foreach ($routePlan['stops'] ?? [] as $stop) {
                $locationId = (int)($stop['location_id'] ?? 0);
                if ($locationId > 0) {
                    $locationIds[$locationId] = $locationId;
                }
            }
            $siteCoincidence = $locationIds !== []
                ? $this->synergy()->siteCoincidence('RESTOCKER', $restockerId, array_values($locationIds))
                : [];

            foreach ($routePlan['stops'] as $stopIndex => $stop) {
                $stopLocationId = (int)($stop['location_id'] ?? 0);
                $entries = $siteCoincidence[$stopLocationId] ?? $siteCoincidence[(string)$stopLocationId] ?? [];
                $routePlan['stops'][$stopIndex]['site_coincidence'] = is_array($entries) && $entries !== []
                    ? $entries
                    : null;
            }
            $routePlan['site_coincidence'] = $siteCoincidence;

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
                'service_date' => $route['service_date'],
                'route_plan' => $routePlan,
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
        } catch (RestockRoutePlannerValidationException $exception) {
            $details = $exception->getField() === null
                ? null
                : array_merge(['field' => $exception->getField()], $exception->getContext());

            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $details
            );
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al construir la ruta de carga del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar la ruta de carga.', 500);
        }
    }

    /**
     * POST /api/restocker/route/stops/{machineId}/skip
     *
     * Omisión de una parada de la ruta efectiva de hoy. El reponedor sale del
     * token; el servicio valida motivo, rigor textual, perímetro y salvaguarda
     * FEFO (`RF-02.2`, `QA-1`, `Art. II`, `Art. V.1`, `Art. V.4`).
     */
    public function skipStop(Request $request): Response
    {
        return $this->executeRoute(function () use ($request): Response {
            $result = $this->routeOverrides->skip(
                $this->positiveMachineId($request),
                $request->getParsedBody(),
                $this->actor($request)
            );
            $status = ($result['idempotent_replay'] ?? false) === true ? 200 : 201;

            return Response::json(
                $result,
                $status,
                'Omisión de parada registrada con trazabilidad.'
            );
        });
    }

    /**
     * POST /api/restocker/route/override-conflict-dump
     *
     * Archiva una mutación offline rechazada sin alterar la verdad del servidor
     * (`C3`, `Art. III.3`).
     */
    public function dumpOverrideConflict(Request $request): Response
    {
        return $this->executeRoute(function () use ($request): Response {
            return Response::json(
                $this->routeOverrides->dumpOfflineConflict(
                    $request->getParsedBody(),
                    $this->actor($request)
                ),
                201,
                'Conflicto offline del planificador archivado en auditoría.'
            );
        });
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

    /** POST /api/restocker/machines/{machineId}/dex-audits */
    public function importDexAudit(Request $request): Response
    {
        return $this->runDexOperation(function () use ($request): Response {
            $machineId = $this->positiveMachineId($request);
            $actor = $this->actor($request);
            $payload = $this->dexImportPayload($request, $machineId, $actor, 'RESTOCKER_ROUTE');

            return Response::json(
                $this->dexService()->presentImportResult($this->dexService()->importDex($payload), 'RESTOCKER'),
                201,
                'Volcado .dex importado para la máquina.'
            );
        });
    }

    /** GET /api/restocker/machines/{machineId}/dex-audits */
    public function listDexAudits(Request $request): Response
    {
        return $this->runDexOperation(function () use ($request): Response {
            $machineId = $this->positiveMachineId($request);
            $actor = $this->actor($request);

            return Response::json(
                ['audits' => $this->dexService()->listForRole($machineId, 'RESTOCKER', (int)$actor['id'])],
                200
            );
        });
    }

    /** POST /api/restocker/dex-audits/conflict-dump */
    public function dumpDexConflict(Request $request): Response
    {
        return $this->runDexOperation(function () use ($request): Response {
            $payload = $request->getParsedBody();
            $machineId = (int)($payload['machine_id'] ?? 0);
            $reason = trim((string)($payload['reason'] ?? ''));
            $actor = $this->actor($request);
            $this->dexService()->assertConflictDumpMachine(
                $machineId,
                'RESTOCKER',
                (int)$actor['id'],
                $reason
            );
            $this->audit()->logMachineEvent(
                $machineId,
                'OFFLINE_DEX_AUDIT_CONFLICT_DUMP',
                $actor,
                null,
                ['reason' => $reason, 'client_mutation_id' => $payload['client_mutation_id'] ?? null],
                ['module' => 'EVA_DTS_DEX_AUDIT']
            );

            return Response::json(['ok' => true, 'outcome_status' => 'CONFLICT_DUMPED'], 200);
        });
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
     * POST /api/restocker/incidents/upload-photo (alias contractual del escalado)
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

            $result = $this->synergy()->startLevel1($this->incidentRouteId($request), $this->actor($request), $mutation);
            if (($result['ok'] ?? false) !== true) {
                return $this->gatewayErrorResponse($result, 'START_L1_FAILED', 'No se pudo iniciar la intervención de Nivel 1.');
            }

            return Response::json(
                $result,
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
                'diagnosis' => $body['resolution_diagnosis'] ?? $body['diagnosis'] ?? null,
                'action' => $body['resolution_action'] ?? $body['action'] ?? null,
                'root_cause_id' => $body['root_cause_id'] ?? null,
                'standard_solution_id' => $body['standard_solution_id'] ?? null,
            ];
            $mutation = isset($body['client_mutation_id'])
                ? [
                    'client_mutation_id' => (string)$body['client_mutation_id'],
                    'client_occurred_at' => $body['client_occurred_at'] ?? null,
                ]
                : null;

            $result = $this->synergy()->resolveLevel1($incident, $this->actor($request), $resolution, $mutation);
            if (($result['ok'] ?? false) !== true) {
                return $this->gatewayErrorResponse($result, 'RESOLUTION_FAILED', 'No se pudo resolver el aviso de Nivel 1.');
            }

            return Response::json(
                $result,
                200,
                'Nivel 1 resuelto y custodia cerrada.'
            );
        });
    }

    /**
     * POST /api/restocker/incidents/{id}/escalate-to-sat
     */
    public function escalateIncidentToSat(Request $request): Response
    {
        return $this->executeSynergy(function () use ($request): Response {
            $incidentId = $this->incidentRouteId($request);
            $incident = (new PdoIncidentRepository($this->pdo))->findById($incidentId);
            if ($incident === null) {
                return Response::error('INCIDENT_NOT_FOUND', 'El expediente no existe o está dado de baja.', 404);
            }
            if ($incident->getStatus()->isTerminal()) {
                return Response::error(
                    'INVALID_INCIDENT_STATE_TRANSITION',
                    'No se puede escalar al SAT un expediente cerrado o cancelado.',
                    409
                );
            }

            $body = $request->getParsedBody();
            $mutation = isset($body['client_mutation_id'])
                ? [
                    'client_mutation_id' => (string)$body['client_mutation_id'],
                    'client_occurred_at' => $body['client_occurred_at'] ?? null,
                ]
                : null;

            $result = $this->synergy()->escalateToSat($incident->getMachineId(), $this->actor($request), $body, $mutation);
            if (($result['ok'] ?? false) !== true) {
                return $this->gatewayErrorResponse($result, 'ESCALATION_FAILED', 'No se pudo escalar la avería al SAT.');
            }

            return Response::json(
                $result,
                200,
                'Avería escalada al SAT.'
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

            $result = $this->synergy()->escalateToSat($machineId, $this->actor($request), $body, $mutation);
            if (($result['ok'] ?? false) !== true) {
                return $this->gatewayErrorResponse($result, 'ESCALATION_FAILED', 'No se pudo escalar la avería al SAT.');
            }

            return Response::json(
                $result,
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

            $result = $this->synergy()->dumpOfflineConflict($this->actor($request), $body, $conflict);
            if (($result['ok'] ?? false) !== true) {
                return $this->gatewayErrorResponse($result, 'CONFLICT_DUMP_FAILED', 'No se pudo archivar el conflicto offline.', 422);
            }

            return Response::json(
                $result,
                201,
                'Conflicto offline archivado en auditoría.'
            );
        });
    }

    /** @param array<string, mixed> $result */
    private function gatewayErrorResponse(
        array $result,
        string $defaultCode,
        string $defaultMessage,
        int $defaultStatus = 409
    ): Response {
        return Response::error(
            (string)($result['error_code'] ?? $defaultCode),
            (string)($result['message'] ?? $defaultMessage),
            (int)($result['http_status'] ?? $defaultStatus),
            is_array($result['details'] ?? null) ? $result['details'] : null
        );
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
        } catch (DexAuditValidationException $exception) {
            // H25-02: un vínculo .dex rechazado dentro de la transacción del
            // escalado devuelve su código de contrato, no un 500 genérico.
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getDetails()
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

    private function executeRoute(callable $operation): Response
    {
        try {
            return $operation();
        } catch (RestockRoutePlannerValidationException $exception) {
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
            error_log('[vendguard] Fallo en la ruta periódica del reponedor: ' . $exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo completar la operación de la ruta periódica.',
                500
            );
        }
    }

    /**
     * @return array{source: string, latitude: float, longitude: float}|null
     */
    private function routeOrigin(): ?array
    {
        $settings = $this->routeSettings->find();
        if ($settings === null) {
            return null;
        }

        return [
            'source' => 'BASE_CENTRAL',
            'latitude' => $settings->getBaseLatitude(),
            'longitude' => $settings->getBaseLongitude(),
        ];
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

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private function dexImportPayload(Request $request, int $machineId, array $actor, string $source): array
    {
        $file = $request->getFile('file') ?? $request->getFile('dex_file');
        $rawPayload = '';
        $filename = trim((string)($request->getBodyParam('original_filename') ?? ''));
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string)($file['tmp_name'] ?? '');
            $read = $tmpName !== '' ? file_get_contents($tmpName) : false;
            if ($read === false) {
                throw new DexAuditValidationException('No se pudo leer el fichero .dex adjunto.', 'DEX_INVALID_TEXT_PAYLOAD');
            }
            $rawPayload = $read;
            $filename = $filename !== '' ? $filename : (string)($file['name'] ?? '');
        } else {
            $rawPayload = (string)($request->getBodyParam('raw_payload') ?? '');
        }

        $payload = [
            'machine_id' => $machineId,
            'actor_id' => (int)$actor['id'],
            'import_source' => $source,
            'raw_payload' => $rawPayload,
            'original_filename' => $filename !== '' ? $filename : 'volcado.dex',
            'captured_at' => trim((string)($request->getBodyParam('captured_at') ?? '')) ?: (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
        ];
        foreach (['client_mutation_id', 'restock_visit_id', 'cash_collection_id', 'incident_id'] as $field) {
            $value = $request->getBodyParam($field);
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    private function audit(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new PdoAuditLogRepository($this->pdo));
    }

    private function dexService(): MachineDexAuditService
    {
        if ($this->dexAuditService === null) {
            $this->dexAuditService = new MachineDexAuditService(
                new PdoMachineDexAuditRepository($this->pdo),
                new PdoMachineDexAuditGateway($this->pdo),
                new EvaDtsParser(),
                new DexSelectionMatcher(),
                new DexAuditValidator(),
                new PdoNotificationOutboxRepository($this->pdo),
                new PdoTransactionManager($this->pdo),
                new AuditLogger(new PdoAuditLogRepository($this->pdo))
            );
        }

        return $this->dexAuditService;
    }

    private function runDexOperation(callable $operation): Response
    {
        try {
            return $operation();
        } catch (DexAuditValidationException | DexAuditConflictException | DexAuditForbiddenException $exception) {
            return Response::error($exception->getErrorCode(), $exception->getMessage(), $exception->getHttpStatusCode(), $exception->getDetails());
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo inesperado al operar con auditorías .dex: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la operación sobre auditorías .dex.', 500);
        }
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
