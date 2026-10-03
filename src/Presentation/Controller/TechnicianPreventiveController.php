<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\PreventiveChecklistEvaluationService;
use VendGuard\Application\Service\PreventiveCoexistenceBridgeService;
use VendGuard\Application\Service\SanitaryCertificateService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Exception\ChecklistIncompleteException;
use VendGuard\Core\Domain\Exception\InvalidPartQuantityException;
use VendGuard\Core\Domain\Exception\InvalidTemperatureRangeException;
use VendGuard\Core\Domain\Exception\PreventiveOrderAlreadyAssignedException;
use VendGuard\Core\Domain\Exception\PreventiveOrderNotInInspectionException;
use VendGuard\Core\Domain\Exception\ReinspectionTemperatureExceededException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveItemRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoSanitaryCertificateRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianPreventiveController
 * 
 * Controlador REST para la operativa de campo del Técnico de Ruta en Mantenimiento Preventivo (Módulo 05).
 * 
 * Endpoints registrados bajo InternalAuthMiddleware(TECHNICIAN):
 * - GET  /api/technician/preventive/route
 * - POST /api/technician/preventive/orders/{id}/claim
 * - GET  /api/technician/preventive/orders/{id}/checklist
 * - POST /api/technician/preventive/orders/{id}/start
 * - POST /api/technician/preventive/orders/{id}/complete
 * - POST /api/technician/preventive/orders/{id}/reinspect
 * 
 * Cumple con:
 * - RF-PREV-02 (EARS 2.3): Visita Oportunista (Claim in situ).
 * - RF-PREV-03 / RNF-06: Consulta de catálogo normativo y límites de temperatura [-5.0, 25.0] °C.
 * - RF-PREV-04 / Art. II: Evaluación estricta de checklist y activación de cuarentena en rotura de frío.
 * - RF-PREV-05 / Art. V.1, Art. V.2: Coexistencia con averías correctivas sin duplicar tickets.
 * - RF-PREV-07 / Art. V.4: Emisión de certificados sanitarios protegiendo datos personales (operator_code).
 * - RF-PREV-08 / Art. II, Art. V.1: Reinspección tras subsanación y levantamiento condicionado.
 * - RF-REP-07: Registro opcional de piezas de repuesto sustituidas en preventivos con snapshot de costes.
 * - Dogma Vanilla (PHP 8.2+ sin frameworks, PDO).
 * - Dualismo Lingüístico (código en inglés, mensajes en español).
 */
class TechnicianPreventiveController
{
    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private PreventiveChecklistEvaluationService $evaluationService;
    private PreventiveCoexistenceBridgeService $coexistenceBridge;
    private SanitaryCertificateService $sanitaryCertificateService;
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private UserRepositoryInterface $userRepo;
    private AuditLogger $auditLogger;
    private SparePartTraceabilityService $traceabilityService;

    public function __construct(
        ?PreventiveOrderRepositoryInterface $orderRepo = null,
        ?PreventiveSettingsRepositoryInterface $settingsRepo = null,
        ?PreventiveChecklistEvaluationService $evaluationService = null,
        ?PreventiveCoexistenceBridgeService $coexistenceBridge = null,
        ?SanitaryCertificateService $sanitaryCertificateService = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?AuditLogger $auditLogger = null,
        ?SparePartTraceabilityService $traceabilityService = null
    ) {
        $this->orderRepo = $orderRepo ?? new PdoPreventiveOrderRepository();
        $this->settingsRepo = $settingsRepo ?? new PdoPreventiveSettingsRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger(new PdoAuditLogRepository());

        $this->evaluationService = $evaluationService ?? new PreventiveChecklistEvaluationService(
            orderRepo: $this->orderRepo,
            itemRepo: new PdoPreventiveItemRepository(),
            settingsRepo: $this->settingsRepo,
            machineRepo: $this->machineRepo,
            auditLogger: $this->auditLogger
        );

        $incidentRepo = new PdoIncidentRepository();
        $certificateRepo = new PdoSanitaryCertificateRepository();

        $this->coexistenceBridge = $coexistenceBridge ?? new PreventiveCoexistenceBridgeService(
            incidentRepo: $incidentRepo,
            orderRepo: $this->orderRepo,
            settingsRepo: $this->settingsRepo,
            machineRepo: $this->machineRepo,
            certificateRepo: $certificateRepo,
            auditLogger: $this->auditLogger
        );

        $this->sanitaryCertificateService = $sanitaryCertificateService ?? new SanitaryCertificateService(
            certificateRepo: $certificateRepo,
            orderRepo: $this->orderRepo,
            settingsRepo: $this->settingsRepo,
            machineRepo: $this->machineRepo,
            auditLogger: $this->auditLogger
        );

        $this->traceabilityService = $traceabilityService ?? new SparePartTraceabilityService(
            requestRepo: new PdoSparePartRequestRepository(),
            replacedPartRepo: new PdoIncidentReplacedPartRepository(),
            sparePartRepo: new PdoSparePartRepository(),
            incidentRepo: $incidentRepo,
            machineRepo: $this->machineRepo,
            auditLogger: $this->auditLogger
        );
    }

    // =========================================================================
    // 1. Ruta Preventiva del Técnico (RF-PREV-02, EARS 2.3)
    // =========================================================================

    /**
     * GET /api/technician/preventive/route
     * 
     * Retorna las órdenes asignadas al técnico autenticado (SCHEDULED, IN_INSPECTION, EXPIRED)
     * e incluye preventivos pendientes (PENDING_ASSIGNMENT) de la misma sede si se proporciona location_id.
     */
    public function getRoute(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $actor = $this->extractActor($request);
            $technicianId = $actor['id'];

            $rawLocationId = $request->getQuery('location_id');
            $locationId = null;
            if ($rawLocationId !== null && trim((string)$rawLocationId) !== '') {
                if (!is_numeric($rawLocationId)) {
                    throw new InvalidArgumentException('El parámetro location_id debe ser un número entero.');
                }
                $locationId = (int)$rawLocationId;
            }

            $orders = $this->orderRepo->findForTechnicianRoute($technicianId, $locationId);

            $formatted = array_map(function (PreventiveOrder $o): array {
                return $o->toArray();
            }, $orders);

            return Response::json($formatted, 200);
        });
    }

    // =========================================================================
    // 2. Visita Oportunista / Claim In Situ (RF-PREV-02, EARS 2.3)
    // =========================================================================

    /**
     * POST /api/technician/preventive/orders/{id}/claim
     * 
     * Permite a un técnico autoasignarse inmediatamente una orden preventiva pendiente
     * en la sede donde se encuentra físicamente trabajando.
     */
    public function claimOrder(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $technicianId = $actor['id'];

            $orderBefore = $this->orderRepo->findById($orderId);
            if ($orderBefore === null) {
                return Response::error('ORDER_NOT_FOUND', "La orden de inspección preventiva especificada no existe.", 404);
            }

            $this->orderRepo->claimOrderOpportunistically($orderId, $technicianId);

            $this->auditLogger->logMachineEvent(
                $orderBefore->getMachineId(),
                'CLAIM_PREVENTIVE_ORDER',
                $actor,
                ['status' => $orderBefore->getStatus()],
                [
                    'status' => 'SCHEDULED',
                    'order_id' => $orderId,
                    'technician_id' => $technicianId,
                    'claimed_at' => date('Y-m-d H:i:s'),
                ]
            );

            $updatedOrder = $this->orderRepo->findById($orderId);
            $machine = $this->machineRepo->findById($orderBefore->getMachineId());

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $updatedOrder !== null ? $updatedOrder->getOrderCode() : $orderBefore->getOrderCode(),
                'status' => $updatedOrder !== null ? $updatedOrder->getStatus() : 'SCHEDULED',
                'assigned_technician_id' => $technicianId,
                'claimed_at' => date('c'),
                'machine' => [
                    'code' => $machine?->getCode(),
                    'model' => $machine?->getModel(),
                    'floor_wing' => $machine?->getFloorWing(),
                ],
            ], 200, 'Orden preventiva autoasignada con éxito (Visita Oportunista).');
        });
    }

    // =========================================================================
    // 3. Consulta de Checklist Normativo (RF-PREV-03, EARS 3.1)
    // =========================================================================

    /**
     * GET /api/technician/preventive/orders/{id}/checklist
     * 
     * Retorna la plantilla normativa de verificaciones obligatorias según la tipología
     * de máquina, límites de temperatura admisibles y datos del centro.
     */
    public function getChecklist(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);

            $order = $this->orderRepo->findById($orderId);
            if ($order === null) {
                return Response::error('ORDER_NOT_FOUND', "La orden de inspección preventiva especificada no existe.", 404);
            }

            $machineId = $order->getMachineId();
            $machine = $this->machineRepo->findById($machineId);
            $machineSettings = $this->settingsRepo->getMachineSettings($machineId);
            $machineType = strtoupper((string)($machineSettings['machine_type'] ?? 'PERISHABLE_FOOD'));

            $isPerishable = in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true);
            $requiresTemperature = in_array($machineType, ['PERISHABLE_FOOD', 'COLD_DRINKS', 'COMBO'], true);

            $location = $this->locationRepo->findById($order->getLocationId());
            $checklistItems = $this->evaluationService->getChecklistTemplate($machineType);

            $temperatureLimits = null;
            if ($requiresTemperature) {
                $maxValid = ($machineType === 'COLD_DRINKS') ? 8.0 : 4.0;
                $temperatureLimits = [
                    'max_valid_celsius' => $maxValid,
                    'absolute_min_celsius' => -5.0,
                    'absolute_max_celsius' => 25.0,
                ];
            }

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $order->getOrderCode(),
                'machine' => [
                    'id' => $machine?->getId() ?? $machineId,
                    'code' => $machine?->getCode() ?? (string)($order->getMachineData()['code'] ?? ''),
                    'model' => $machine?->getModel() ?? (string)($order->getMachineData()['model'] ?? ''),
                    'machine_type' => $machineType,
                    'is_perishable' => $isPerishable,
                    'requires_temperature' => $requiresTemperature,
                    'temperature_limits' => $temperatureLimits,
                ],
                'location' => [
                    'name' => $location?->getName() ?? (string)($order->getLocationData()['name'] ?? ''),
                    'floor_wing' => $machine?->getFloorWing() ?? (string)($order->getMachineData()['floor_wing'] ?? ''),
                ],
                'checklist_items' => $checklistItems,
            ], 200);
        });
    }

    // =========================================================================
    // 4. Inicio de Inspección In Situ (EARS 3.1)
    // =========================================================================

    /**
     * POST /api/technician/preventive/orders/{id}/start
     * 
     * Marca el inicio formal de la inspección in situ cambiando el estado de la orden a 'IN_INSPECTION'.
     */
    public function startInspection(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $technicianId = $actor['id'];

            $order = $this->orderRepo->findById($orderId);
            if ($order === null) {
                return Response::error('ORDER_NOT_FOUND', "La orden de inspección preventiva especificada no existe.", 404);
            }

            $this->evaluationService->startInspection($orderId, $technicianId);

            $this->auditLogger->logMachineEvent(
                $order->getMachineId(),
                'START_PREVENTIVE_INSPECTION',
                $actor,
                ['status' => $order->getStatus()],
                [
                    'status' => 'IN_INSPECTION',
                    'order_id' => $orderId,
                    'started_at' => date('Y-m-d H:i:s'),
                ]
            );

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $order->getOrderCode(),
                'status' => 'IN_INSPECTION',
                'started_at' => date('c'),
            ], 200, 'Inspección preventiva iniciada correctamente in situ.');
        });
    }

    // =========================================================================
    // 5. Remisión y Evaluación del Checklist (RF-PREV-03, RF-PREV-04, Art. II, V.1, V.2)
    // =========================================================================

    /**
     * POST /api/technician/preventive/orders/{id}/complete
     * 
     * Remisión y evaluación del checklist con validación térmica [-5.0, 25.0] °C.
     * En dictamen CONFORME: emite certificado sanitario oficial con operator_code (Art. V.4).
     * En dictamen NO_CONFORME: activa Cuarentena Sanitaria en QR y abre correctivo o anota bitácora (Art. II, V.1, V.2).
     */
    public function completeInspection(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $technicianId = $actor['id'];
            $body = $request->getParsedBody();

            $order = $this->orderRepo->findById($orderId);
            if ($order === null) {
                return Response::error('ORDER_NOT_FOUND', "La orden de inspección preventiva especificada no existe.", 404);
            }

            // 1. Evaluación del Checklist por el servicio de dominio (EARS 3.1-3.5, 4.1-4.2)
            $evalResult = $this->evaluationService->evaluateChecklist(
                $orderId,
                $technicianId,
                $body,
                $actor
            );

            // 2. Registro opcional de repuestos sustituidos en preventivo (RF-REP-07)
            $replacedPartsDeclared = !empty($body['replaced_parts_declared']);
            $partsResult = null;
            if ($replacedPartsDeclared || !empty($body['replaced_parts'])) {
                try {
                    $partsResult = $this->traceabilityService->recordPreventiveReplacedParts(
                        $orderId,
                        $technicianId,
                        $order->getMachineId(),
                        $order->getLocationId(),
                        $body['replaced_parts'] ?? [],
                        $actor
                    );
                } catch (\InvalidArgumentException $e) {
                    $msg = $e->getMessage();
                    $errCode = str_contains($msg, 'destino') ? 'INVALID_PART_DESTINATION' : 'INVALID_ARGUMENT';
                    return Response::error($errCode, $msg, 422);
                }
            }

            // 3. Si el dictamen es NO_CONFORME, gestionar cuarentena y correctivo con el coexistence bridge
            if ($evalResult['result'] === 'NO_CONFORME' || $evalResult['is_quarantine_triggered']) {
                $nonConformItems = array_filter($body['items'] ?? [], function ($item) {
                    $st = strtoupper((string)($item['status'] ?? ''));
                    return in_array($st, ['FAIL', 'NO_CONFORME', 'WARN'], true);
                });

                $generalNotes = $body['general_notes'] ?? ($body['notes'] ?? null);

                $correctiveResult = $this->coexistenceBridge->handleNonConformity(
                    $orderId,
                    $technicianId,
                    $evalResult['temperature_measured'],
                    array_values($nonConformItems),
                    $generalNotes,
                    $actor
                );

                return Response::json([
                    'order_id' => $orderId,
                    'order_code' => $evalResult['order_code'],
                    'result' => 'NO_CONFORME',
                    'status' => 'COMPLETED',
                    'is_quarantine_triggered' => true,
                    'temperature_measured' => $evalResult['temperature_measured'],
                    'machine_sanitary_status' => 'QUARANTINE',
                    'corrective_action' => $correctiveResult,
                    'replaced_parts_count' => $partsResult['replaced_parts_count'] ?? 0,
                    'total_parts_cost' => $partsResult['total_parts_cost'] ?? 0.00,
                    'replaced_parts' => $partsResult['replaced_parts'] ?? [],
                ], 200, 'ALERTA SANITARIA (Art. II): Máquina puesta en cuarentena y bloqueada en código QR. Incidencia correctiva vinculada procesada.');
            }

            // 4. Si el dictamen es CONFORME o CONFORME_CON_OBSERVACIONES, emitir certificado sanitario oficial
            $certificate = $this->sanitaryCertificateService->issueCertificate($orderId, $actor);

            return Response::json([
                'order_id' => $orderId,
                'order_code' => $evalResult['order_code'],
                'result' => $evalResult['result'],
                'status' => 'COMPLETED',
                'is_quarantine_triggered' => false,
                'temperature_measured' => $evalResult['temperature_measured'],
                'next_sanitary_inspection_due' => $certificate->getValidUntil(),
                'certificate' => [
                    'certificate_code' => $certificate->getCertificateCode(),
                    'valid_until' => $certificate->getValidUntil(),
                    'technician_operator_code' => $certificate->getTechnicianOperatorCode(),
                ],
                'machine_sanitary_status' => 'OK',
                'replaced_parts_count' => $partsResult['replaced_parts_count'] ?? 0,
                'total_parts_cost' => $partsResult['total_parts_cost'] ?? 0.00,
                'replaced_parts' => $partsResult['replaced_parts'] ?? [],
            ], 200, 'Inspección conforme. Certificado sanitario emitido correctamente.');
        });
    }

    // =========================================================================
    // 6. Reinspección tras Subsanación (RF-PREV-08, EARS 8.1-8.3)
    // =========================================================================

    /**
     * POST /api/technician/preventive/orders/{id}/reinspect
     * 
     * Reinspección sanitaria in situ tras solventar la avería térmica o sanitaria.
     * Exige temperatura <= 4.0 °C en perecederos para levantar la cuarentena (Art. II).
     */
    public function reinspectOrder(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $orderId = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $technicianId = $actor['id'];
            $body = $request->getParsedBody();

            if (!isset($body['temperature_measured']) || !is_numeric($body['temperature_measured'])) {
                throw new InvalidArgumentException('La temperatura medida de reinspección es obligatoria y debe ser numérica.');
            }

            $temperature = round((float)$body['temperature_measured'], 1);
            if ($temperature < -5.0 || $temperature > 25.0) {
                throw new InvalidTemperatureRangeException(
                    "Temperatura registrada ({$temperature} °C) fuera del rango físico admisible [-5.0 °C, +25.0 °C].",
                    $temperature,
                    -5.0,
                    25.0
                );
            }

            $notes = trim((string)($body['reinspection_notes'] ?? ($body['notes'] ?? '')));
            if ($notes === '') {
                throw new InvalidArgumentException('Debe incluir anotaciones descriptivas sobre la reinspección realizada.');
            }

            $reinspectResult = $this->coexistenceBridge->reinspectAfterSubsanacion(
                $orderId,
                $technicianId,
                $temperature,
                $notes,
                $actor
            );

            return Response::json([
                'order_id' => $reinspectResult['order_id'],
                'order_code' => $reinspectResult['order_code'],
                'order_type' => 'REINSPECTION',
                'result' => 'CONFORME',
                'temperature_measured' => $temperature,
                'machine_sanitary_status' => 'OK',
                'qr_unblocked' => $reinspectResult['qr_unblocked'],
                'certificate' => $reinspectResult['certificate'],
            ], 200, 'Reinspección superada satisfactoriamente. Cuarentena levantada y máquina en servicio.');
        });
    }

    // =========================================================================
    // Métodos Auxiliares y Mapeo Centralizado de Excepciones
    // =========================================================================

    private function handleExecution(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (ChecklistIncompleteException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (InvalidTemperatureRangeException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (ReinspectionTemperatureExceededException $e) {
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
        } catch (PreventiveOrderNotInInspectionException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (CannotIssueNonConformCertificateException $e) {
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

    private function extractIdFromRoute(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException('El identificador en la URL debe ser un número entero positivo.');
        }

        return (int)$rawId;
    }

    /**
     * @return array{id: int, role: string, name: string, operator_code?: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            $operatorCode = method_exists($user, 'getOperatorCode') ? $user->getOperatorCode() : null;
            return [
                'id'            => $user->getId(),
                'role'          => $user->getRole()->value,
                'name'          => $user->getName(),
                'operator_code' => $operatorCode ?? sprintf('OP-%02d', $user->getId()),
            ];
        }

        $userId = $request->getAttribute('user_id');
        $id = ($userId !== null && is_numeric($userId)) ? (int)$userId : 1;
        $role = (string)$request->getAttribute('user_role', 'TECHNICIAN');

        $operatorCode = null;
        try {
            $userObj = $this->userRepo->findById($id);
            if ($userObj !== null) {
                $operatorCode = method_exists($userObj, 'getOperatorCode') ? $userObj->getOperatorCode() : null;
                $name = $userObj->getName();
            } else {
                $name = 'Técnico de Campo';
            }
        } catch (Throwable) {
            $name = 'Técnico de Campo';
        }

        return [
            'id'            => $id,
            'role'          => $role,
            'name'          => $name,
            'operator_code' => $operatorCode ?? sprintf('OP-%02d', $id),
        ];
    }
}
