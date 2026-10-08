<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorPreventiveDetailService;
use VendGuard\Application\Service\PreventiveOrderSchedulerService;
use VendGuard\Application\Service\PreventiveSettingsService;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\PreventiveOrderAlreadyAssignedException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveItemRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoSanitaryCertificateRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorPreventiveController
 * 
 * Controlador REST para la gestión de Mantenimiento Preventivo y Checklists Sanitarios
 * desde el Panel de Coordinación (Módulo 05).
 * 
 * Todos los endpoints están protegidos por InternalAuthMiddleware(COORDINATOR).
 * Cumple con:
 * - RF-PREV-01: Configuración preventiva y pausas estacionales justificadas.
 * - RF-PREV-02: Generación anticipada a 5 días y asignación técnica.
 * - RF-PREV-06: Panel de mando semafórico y cuarentenas activas.
 * - Constitución Art. I, II, III, V.
 * - Dogma Vanilla (PHP 8.2+ sin frameworks).
 * - Dualismo Lingüístico.
 */
class CoordinatorPreventiveController
{
    /** EARS 2.6: umbral mínimo de caracteres reales del motivo de reasignación preventiva. */
    private const MIN_REASSIGNMENT_REASON_LENGTH = 10;

    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private PreventiveOrderSchedulerService $schedulerService;
    private PreventiveSettingsService $settingsService;
    private MachineRepositoryInterface $machineRepo;
    private UserRepositoryInterface $userRepo;
    private AuditLogger $auditLogger;
    private ?CoordinatorPreventiveDetailService $detailService;

    public function __construct(
        ?PreventiveOrderRepositoryInterface $orderRepo = null,
        ?PreventiveSettingsRepositoryInterface $settingsRepo = null,
        ?PreventiveOrderSchedulerService $schedulerService = null,
        ?PreventiveSettingsService $settingsService = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?AuditLogger $auditLogger = null,
        ?CoordinatorPreventiveDetailService $detailService = null
    ) {
        $this->detailService = $detailService;
        $this->orderRepo = $orderRepo ?? new PdoPreventiveOrderRepository();
        $this->settingsRepo = $settingsRepo ?? new PdoPreventiveSettingsRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger(new PdoAuditLogRepository());

        $this->schedulerService = $schedulerService ?? new PreventiveOrderSchedulerService(
            orderRepo: $this->orderRepo,
            settingsRepo: $this->settingsRepo,
            machineRepo: $this->machineRepo,
            auditLogger: $this->auditLogger
        );

        $this->settingsService = $settingsService ?? new PreventiveSettingsService(
            settingsRepo: $this->settingsRepo,
            auditLogger: $this->auditLogger
        );
    }

    // =========================================================================
    // 1. Dashboard y Métricas de Coordinación (RF-PREV-06, EARS 6.1)
    // =========================================================================

    /**
     * GET /api/coordinator/preventive/dashboard
     * 
     * Resumen métrico de cumplimiento preventivo, desglose de semáforos,
     * recuento de órdenes vencidas/próximas y lista de máquinas en cuarentena.
     */
    public function getDashboard(Request $request): Response
    {
        return $this->handleExecution(function (): Response {
            $summary = $this->orderRepo->getDashboardSummary();
            return Response::json($summary, 200);
        });
    }

    // =========================================================================
    // 2. Listado y Filtros de Órdenes Preventivas (RF-PREV-02)
    // =========================================================================

    /**
     * GET /api/coordinator/preventive/orders
     * 
     * Listado con filtros opcionales (status, location_id, machine_id, technician_id, search)
     * y soporte de paginación (page, per_page).
     */
    public function listOrders(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $filters = [];

            $status = $request->getQuery('status');
            if ($status !== null && trim((string)$status) !== '') {
                $filters['status'] = trim((string)$status);
            }

            $locationId = $request->getQuery('location_id');
            if ($locationId !== null && trim((string)$locationId) !== '') {
                if (!is_numeric($locationId)) {
                    throw new InvalidArgumentException('El parámetro location_id debe ser un número entero.');
                }
                $filters['location_id'] = (int)$locationId;
            }

            $machineId = $request->getQuery('machine_id');
            if ($machineId !== null && trim((string)$machineId) !== '') {
                if (!is_numeric($machineId)) {
                    throw new InvalidArgumentException('El parámetro machine_id debe ser un número entero.');
                }
                $filters['machine_id'] = (int)$machineId;
            }

            $technicianId = $request->getQuery('technician_id');
            if ($technicianId !== null && trim((string)$technicianId) !== '') {
                if (!is_numeric($technicianId)) {
                    throw new InvalidArgumentException('El parámetro technician_id debe ser un número entero.');
                }
                $filters['technician_id'] = (int)$technicianId;
            }

            $search = $request->getQuery('search');
            if ($search !== null && trim((string)$search) !== '') {
                $filters['search'] = trim((string)$search);
            }

            $page = max(1, (int)($request->getQuery('page', 1)));
            $perPage = min(100, max(1, (int)($request->getQuery('per_page', 20))));
            $filters['limit'] = $perPage;
            $filters['offset'] = ($page - 1) * $perPage;

            $orders = $this->orderRepo->findForCoordinatorList($filters);
            $totalCount = $this->orderRepo->countForCoordinatorList($filters);

            $formatted = array_map(function (PreventiveOrder $o): array {
                return $o->toArray();
            }, $orders);

            return Response::json($formatted, 200, null, [
                'X-Total-Count' => (string)$totalCount,
                'X-Page'        => (string)$page,
                'X-Per-Page'    => (string)$perPage,
            ]);
        });
    }

    /**
     * GET /api/coordinator/preventive/orders/{id}/detail
     *
     * Ficha integral de una orden preventiva para el modal de Coordinación
     * (RF-PD-01 a RF-PD-10). Admite el ID primario o el código de orden. Es una
     * lectura pura: no escribe ni emite eventos de auditoría (RNF-PD-04, Art. III).
     */
    public function getOrderDetail(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $rawIdentifier = trim((string)($request->getRouteParam('id') ?? ''));
            if ($rawIdentifier === '') {
                return Response::error(
                    'INVALID_PREVENTIVE_ORDER_IDENTIFIER',
                    'El identificador de la orden no es válido. Se admite un ID numérico positivo o un código de orden (ej: PREV-2026-0001).',
                    400
                );
            }

            $detail = $this->resolveDetailService()->buildDetail($rawIdentifier);
            if ($detail === null) {
                return Response::error(
                    'PREVENTIVE_ORDER_NOT_FOUND',
                    "No se encontró ninguna orden preventiva con identificador '{$rawIdentifier}'.",
                    404
                );
            }

            return Response::json($detail->toArray(), 200);
        });
    }

    /**
     * POST /api/coordinator/preventive/orders
     * 
     * Creación manual o extraordinaria de una orden preventiva para una máquina.
     */
    public function createOrder(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            if (empty($body['machine_id']) || !is_numeric($body['machine_id'])) {
                throw new InvalidArgumentException('El identificador machine_id es obligatorio.');
            }

            $machineId = (int)$body['machine_id'];
            $machine = $this->machineRepo->findById($machineId);
            if ($machine === null) {
                return Response::error('MACHINE_NOT_FOUND', "Máquina con ID {$machineId} no encontrada.", 404);
            }

            $orderType = strtoupper(trim((string)($body['order_type'] ?? 'MANUAL_EXTRA')));
            $validTypes = ['ROUTINE', 'REINSPECTION', 'MANUAL_EXTRA'];
            if (!in_array($orderType, $validTypes, true)) {
                throw new InvalidArgumentException("Tipo de orden '{$orderType}' inválido. Valores permitidos: " . implode(', ', $validTypes));
            }

            $scheduledDate = !empty($body['scheduled_date']) 
                ? (string)$body['scheduled_date'] 
                : date('Y-m-d');

            $dueDate = !empty($body['due_date']) 
                ? (string)$body['due_date'] 
                : $scheduledDate;

            $assignedTechId = !empty($body['assigned_technician_id']) ? (int)$body['assigned_technician_id'] : null;
            if ($assignedTechId !== null) {
                $tech = $this->userRepo->findById($assignedTechId);
                if ($tech === null) {
                    return Response::error('USER_NOT_FOUND', "Técnico con ID {$assignedTechId} no encontrado.", 404);
                }
            }

            $status = $assignedTechId !== null ? 'SCHEDULED' : 'PENDING_ASSIGNMENT';
            $notes = isset($body['notes']) ? trim((string)$body['notes']) : 'Orden preventiva creada manualmente desde Coordinación.';

            $order = $this->orderRepo->create([
                'machine_id' => $machineId,
                'location_id' => $machine->getLocationId(),
                'assigned_technician_id' => $assignedTechId,
                'status' => $status,
                'order_type' => $orderType,
                'scheduled_date' => $scheduledDate,
                'due_date' => $dueDate,
                'notes' => $notes,
            ]);

            $this->auditLogger->logMachineEvent(
                $machineId,
                'CREATE_PREVENTIVE_ORDER',
                $actor,
                null,
                $order->toArray(),
                ['order_code' => $order->getOrderCode()]
            );

            return Response::json($order->toArray(), 201, 'Orden preventiva creada correctamente.');
        });
    }

    // =========================================================================
    // 3. Generación Anticipada Automática (RF-PREV-02, EARS 2.1)
    // =========================================================================

    /**
     * POST /api/coordinator/preventive/generate-due
     * 
     * Ejecutor de la generación periódica anticipada (máquinas a <= 5 días de vencimiento).
     */
    public function generateDue(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            $horizonDays = isset($body['horizon_days']) ? (int)$body['horizon_days'] : 5;
            if ($horizonDays < 1) {
                throw new InvalidArgumentException('El horizonte de días debe ser un entero positivo mayor que cero.');
            }

            $result = $this->schedulerService->generateDueOrders($horizonDays, $actor);

            $generatedOrdersList = [];
            foreach ($result['generated_orders'] as $order) {
                if ($order instanceof PreventiveOrder) {
                    $machData = $order->getMachineData() ?? [];
                    $locData = $order->getLocationData() ?? [];
                    $generatedOrdersList[] = [
                        'order_code' => $order->getOrderCode(),
                        'machine_code' => $machData['code'] ?? "VEND-{$order->getMachineId()}",
                        'location_name' => $locData['name'] ?? "Sede #{$order->getLocationId()}",
                        'due_date' => $order->getDueDate(),
                        'status' => $order->getStatus(),
                    ];
                }
            }

            $data = [
                'orders_generated_count' => $result['generated_orders_count'],
                'machines_evaluated_count' => $result['scanned_machines_count'],
                'skipped_seasonal_pause' => $result['skipped_seasonal_pause'],
                'skipped_already_active' => $result['skipped_already_active'],
                'expired_orders_count' => $result['expired_orders_count'],
                'generated_orders' => $generatedOrdersList,
            ];

            $msg = sprintf(
                'Se han generado %d órdenes de inspección preventiva para máquinas próximas a vencer.',
                $result['generated_orders_count']
            );

            return Response::json($data, 200, $msg);
        });
    }

    // =========================================================================
    // 4. Asignación y Cancelación Lógica de Órdenes (RF-PREV-02, Art. III)
    // =========================================================================

    /**
     * PATCH /api/coordinator/preventive/orders/{id}/assign
     * 
     * Asignación manual o reprogramación de una orden a un técnico de ruta.
     */
    public function assignOrder(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            if (empty($body['technician_id']) || !is_numeric($body['technician_id'])) {
                throw new InvalidArgumentException('El identificador technician_id es obligatorio.');
            }
            $technicianId = (int)$body['technician_id'];

            if (empty($body['scheduled_date']) || !is_string($body['scheduled_date'])) {
                throw new InvalidArgumentException('La fecha programada scheduled_date es obligatoria.');
            }
            $scheduledDate = trim((string)$body['scheduled_date']);

            $order = $this->orderRepo->findById($orderId);
            if ($order === null) {
                return Response::error('ORDER_NOT_FOUND', "Orden preventiva con ID {$orderId} no encontrada.", 404);
            }

            // EARS 2.6: cambiar de técnico una orden que ya tenía responsable exige un motivo
            // justificado que queda en el rastro inmutable (Art. III). La asignación inicial y la
            // reprogramación de la fecha que conserva al mismo técnico quedan exentas.
            $reassignmentReason = null;
            $currentTechnicianId = $order->getAssignedTechnicianId();
            if ($currentTechnicianId !== null && (int)$currentTechnicianId !== $technicianId) {
                $rawReason = $body['reassignment_reason'] ?? null;
                $reassignmentReason = is_string($rawReason) ? trim($rawReason) : '';
                if ($reassignmentReason === '') {
                    return Response::error(
                        'MISSING_REASSIGNMENT_REASON',
                        'La reasignación de una orden preventiva exige un motivo justificado (reassignment_reason obligatorio).',
                        422
                    );
                }
                if (mb_strlen($reassignmentReason, 'UTF-8') < self::MIN_REASSIGNMENT_REASON_LENGTH) {
                    return Response::error(
                        'REASSIGNMENT_REASON_TOO_SHORT',
                        'El motivo de la reasignación debe contener al menos 10 caracteres reales.',
                        422
                    );
                }
            }

            $technician = $this->userRepo->findById($technicianId);
            if ($technician === null) {
                return Response::error('USER_NOT_FOUND', "Técnico con ID {$technicianId} no encontrado.", 404);
            }

            $success = $this->orderRepo->assignTechnician($orderId, $technicianId, $scheduledDate);
            if (!$success) {
                throw new DomainException("No fue posible asignar la orden preventiva {$orderId}.");
            }

            $this->auditLogger->logMachineEvent(
                $order->getMachineId(),
                'ASSIGN_PREVENTIVE_ORDER',
                $actor,
                [
                    'status' => $order->getStatus(),
                    'assigned_technician_id' => $order->getAssignedTechnicianId(),
                ],
                array_filter(
                    [
                        'status' => 'SCHEDULED',
                        'assigned_technician_id' => $technicianId,
                        'scheduled_date' => $scheduledDate,
                        'reassignment_reason' => $reassignmentReason,
                    ],
                    static fn ($value): bool => $value !== null
                ),
                $reassignmentReason !== null
                    ? [
                        'order_code' => $order->getOrderCode(),
                        'previous_technician_id' => $currentTechnicianId,
                        'new_technician_id' => $technicianId,
                    ]
                    : ['order_code' => $order->getOrderCode()]
            );

            $updatedOrder = $this->orderRepo->findById($orderId);
            $techData = $updatedOrder?->getTechnicianData() ?? [
                'id' => $technician->getId(),
                'name' => $technician->getName(),
                'operator_code' => method_exists($technician, 'getOperatorCode') && $technician->getOperatorCode() !== null 
                    ? $technician->getOperatorCode() 
                    : sprintf('OP-%02d', $technician->getId()),
            ];

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $updatedOrder?->getOrderCode() ?? $order->getOrderCode(),
                'status' => 'SCHEDULED',
                'technician' => $techData,
                'scheduled_date' => $scheduledDate,
            ], 200, 'Orden preventiva asignada correctamente.');
        });
    }

    /**
     * PATCH /api/coordinator/preventive/orders/{id}/cancel
     * 
     * Cancelación lógica justificada de orden preventiva (Art. III).
     */
    public function cancelOrder(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            $reason = trim((string)($body['reason'] ?? ''));
            if ($reason === '') {
                throw new InvalidArgumentException('El motivo de cancelación es obligatorio para garantizar la trazabilidad histórica (Art. III).');
            }

            $order = $this->orderRepo->findById($orderId);
            if ($order === null) {
                return Response::error('ORDER_NOT_FOUND', "Orden preventiva con ID {$orderId} no encontrada.", 404);
            }

            if ($order->getStatus() === 'COMPLETED') {
                return Response::error('CANNOT_CANCEL_COMPLETED_ORDER', 'No se puede cancelar una orden preventiva que ya ha sido completada.', 400);
            }

            $success = $this->orderRepo->softCancel($orderId, $reason);
            if (!$success) {
                throw new DomainException("No fue posible cancelar la orden preventiva {$orderId}.");
            }

            $this->auditLogger->logMachineEvent(
                $order->getMachineId(),
                'CANCEL_PREVENTIVE_ORDER',
                $actor,
                ['status' => $order->getStatus()],
                ['status' => 'CANCELLED', 'cancellation_reason' => $reason],
                ['order_code' => $order->getOrderCode()]
            );

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $order->getOrderCode(),
                'status' => 'CANCELLED',
                'cancellation_reason' => $reason,
            ], 200, 'Orden preventiva cancelada lógicamente.');
        });
    }

    // =========================================================================
    // 5. Configuración de Frecuencias Normativas (RF-PREV-01, Art. II)
    // =========================================================================

    /**
     * GET /api/coordinator/preventive/settings
     * 
     * Consulta el catálogo de frecuencias estándar y topes normativos por tipología.
     */
    public function getSettings(Request $request): Response
    {
        return $this->handleExecution(function (): Response {
            $settings = $this->settingsService->getAllSettings();
            $data = array_map(fn(PreventiveSetting $s) => $s->toArray(), $settings);
            return Response::json($data, 200);
        });
    }

    /**
     * PATCH /api/coordinator/preventive/settings
     * 
     * Modificación de frecuencias estándar por tipología.
     * Enforce inviolable: Alimentos Perecederos no pueden superar los 15 días (Art. II).
     */
    public function updateSettings(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            if (empty($body['machine_type']) || !is_string($body['machine_type'])) {
                throw new InvalidArgumentException('El campo machine_type es obligatorio.');
            }
            $machineType = strtoupper(trim((string)$body['machine_type']));

            if (!isset($body['default_frequency_days']) || !is_numeric($body['default_frequency_days'])) {
                throw new InvalidArgumentException('El campo default_frequency_days es obligatorio y numérico.');
            }
            $defaultDays = (int)$body['default_frequency_days'];

            if (!isset($body['max_allowed_days']) || !is_numeric($body['max_allowed_days'])) {
                throw new InvalidArgumentException('El campo max_allowed_days es obligatorio y numérico.');
            }
            $maxDays = (int)$body['max_allowed_days'];

            $advanceDays = isset($body['advance_warning_days']) ? (int)$body['advance_warning_days'] : 5;

            $this->settingsService->updateTypeSettings($machineType, $defaultDays, $maxDays, $advanceDays, $actor);

            $updatedSetting = $this->settingsService->getSettingByType($machineType);

            return Response::json(
                $updatedSetting ? $updatedSetting->toArray() : null,
                200,
                "Configuración preventiva para '{$machineType}' actualizada correctamente."
            );
        });
    }

    // =========================================================================
    // 6. Configuración Individual y Pausa Estacional (RF-PREV-01, EARS 1.4-1.5)
    // =========================================================================

    /**
     * GET /api/coordinator/machines/{id}/preventive-config
     * 
     * Consulta la configuración preventiva individual y estado sanitario de una máquina.
     */
    public function getMachinePreventiveConfig(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $machineId = $this->extractIdFromRoute($request);
            $settings = $this->settingsService->getMachineSettings($machineId);

            if ($settings === null) {
                return Response::error('MACHINE_NOT_FOUND', "Máquina con ID {$machineId} no encontrada.", 404);
            }

            return Response::json($settings, 200);
        });
    }

    /**
     * PATCH /api/coordinator/machines/{id}/preventive-config
     * 
     * Ajuste de frecuencia sanitaria individual o activación/desactivación de Pausa Estacional.
     * Enforce Art. II: perecederos no pueden superar los 15 días (422 Unprocessable Entity).
     */
    public function updateMachinePreventiveConfig(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $machineId = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            $machine = $this->machineRepo->findById($machineId);
            if ($machine === null) {
                return Response::error('MACHINE_NOT_FOUND', "Máquina con ID {$machineId} no encontrada.", 404);
            }

            // 1. Gestión de Pausa Estacional (RF-PREV-01, EARS 1.4, 1.5)
            if (array_key_exists('is_seasonal_pause', $body)) {
                $isPause = (bool)$body['is_seasonal_pause'];
                if ($isPause) {
                    $reason = trim((string)($body['seasonal_pause_reason'] ?? ''));
                    $pauseUntil = !empty($body['seasonal_pause_until']) ? (string)$body['seasonal_pause_until'] : null;
                    $this->settingsService->setSeasonalPause($machineId, $reason, $pauseUntil, $actor);
                } else {
                    $this->settingsService->resumeSeasonalPause($machineId, $actor);
                }
            }

            // 2. Modificación de Frecuencia Sanitaria Individual o Próxima Inspección
            $frequencyChanged = array_key_exists('sanitary_frequency_days', $body);
            $dueChanged = array_key_exists('next_sanitary_inspection_due', $body);

            if ($frequencyChanged || $dueChanged) {
                $freqDays = $frequencyChanged && $body['sanitary_frequency_days'] !== null 
                    ? (int)$body['sanitary_frequency_days'] 
                    : null;

                $nextDue = $dueChanged && !empty($body['next_sanitary_inspection_due']) 
                    ? (string)$body['next_sanitary_inspection_due'] 
                    : null;

                $this->settingsService->updateMachineConfig($machineId, $freqDays, $nextDue, $actor);
            }

            $updatedSettings = $this->settingsService->getMachineSettings($machineId);

            return Response::json(
                $updatedSettings,
                200,
                'Configuración preventiva de la máquina actualizada correctamente.'
            );
        });
    }

    // =========================================================================
    // Métodos Auxiliares y Control de Errores
    // =========================================================================

    private function handleExecution(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (PerishableFrequencyLimitException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (SeasonalPauseMissingReasonException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (PreventiveOrderAlreadyAssignedException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 400);
        } catch (DomainException $e) {
            $code = method_exists($e, 'getErrorCode') ? (string)$e->getErrorCode() : 'DOMAIN_ERROR';
            $statusCode = method_exists($e, 'getHttpStatusCode') ? (int)$e->getHttpStatusCode() : 400;
            return Response::error($code, $e->getMessage(), $statusCode);
        } catch (Throwable $e) {
            // El mensaje de la excepción no se devuelve al cliente. Un
            // `PDOException` arrastra la consulta SQL completa, que es un mapa
            // de la base de datos; el `details` del Router añade además clase,
            // fichero y línea. El rastro se queda en el log del servidor.
            error_log('[vendguard] ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }

    /**
     * Ensambla el servicio de detalle bajo demanda: los endpoints de escritura no deben
     * abrir conexiones que no van a usar, y las pruebas pueden inyectar un doble.
     */
    private function resolveDetailService(): CoordinatorPreventiveDetailService
    {
        if ($this->detailService === null) {
            $this->detailService = new CoordinatorPreventiveDetailService(
                orderRepo: $this->orderRepo,
                itemRepo: new PdoPreventiveItemRepository(),
                certificateRepo: new PdoSanitaryCertificateRepository(),
                incidentRepo: new PdoIncidentRepository(),
                auditRepo: new PdoAuditLogRepository()
            );
        }

        return $this->detailService;
    }

    private function extractIdFromRoute(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException('El identificador en la URL debe ser un número entero positivo.');
        }

        return (int)$rawId;
    }

    /**
     * @return array{id?: int|null, role?: string, name?: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id'   => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        return [
            'id'   => $request->getAttribute('user_id') !== null ? (int)$request->getAttribute('user_id') : 1,
            'role' => (string)$request->getAttribute('user_role', 'COORDINATOR'),
            'name' => 'Coordinación',
        ];
    }
}
