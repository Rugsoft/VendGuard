<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use PDO;
use Throwable;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianShiftController
 *
 * Controlador REST para la gestión de jornada y relevo de ruta del técnico (Módulo 21).
 */
final class TechnicianShiftController
{
    private ShiftLifecycleService $lifecycleService;
    private ShiftHandoverService $handoverService;

    public function __construct(
        ?ShiftLifecycleService $lifecycleService = null,
        ?ShiftHandoverService $handoverService = null,
        ?PDO $pdo = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();

        $shiftRepo = new PdoTechnicianShiftRepository($pdo);
        $absenceRepo = new PdoTechnicianAbsenceRepository($pdo);
        $userRepo = new PdoUserRepository($pdo);
        $incidentRepo = new PdoIncidentRepository($pdo);
        $machineRepo = new PdoMachineRepository($pdo);
        $onCallRepo = $userRepo; // PdoUserRepository implementa OnCallTechnicianRepositoryInterface
        $auditRepo = new PdoAuditLogRepository($pdo);

        $this->lifecycleService = $lifecycleService ?? new ShiftLifecycleService(
            $shiftRepo,
            $absenceRepo,
            $userRepo,
            $auditRepo
        );

        $this->handoverService = $handoverService ?? new ShiftHandoverService(
            $pdo,
            $shiftRepo,
            $userRepo,
            $incidentRepo,
            $machineRepo,
            $onCallRepo,
            $auditRepo
        );
    }

    public function status(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        $dto = $this->lifecycleService->getCurrentShift($technicianId);
        return Response::json($dto->toArray(), 200);
    }

    public function start(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        try {
            $dto = $this->lifecycleService->startShift($technicianId);
            return Response::json($dto->toArray(), 200, 'Turno iniciado correctamente.');
        } catch (Throwable $e) {
            return Response::error('SHIFT_START_FAILED', $e->getMessage(), 422);
        }
    }

    public function pauseMeal(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        try {
            $dto = $this->lifecycleService->pauseMeal($technicianId);
            return Response::json($dto->toArray(), 200, 'Pausa para comida iniciada.');
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            return Response::error('SHIFT_PAUSE_FAILED', $e->getMessage(), 422);
        }
    }

    public function resume(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        try {
            $dto = $this->lifecycleService->resumeShift($technicianId);
            return Response::json($dto->toArray(), 200, 'Turno reanudado correctamente.');
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            return Response::error('SHIFT_RESUME_FAILED', $e->getMessage(), 422);
        }
    }

    public function closeAndHandover(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        $body = $request->getParsedBody();

        $rawTargetId = $body['target_technician_id'] ?? null;
        $targetTechnicianId = $rawTargetId !== null && is_numeric($rawTargetId) ? (int)$rawTargetId : null;

        $rawIncidentIds = $body['incident_ids'] ?? [];
        $incidentIds = is_array($rawIncidentIds) ? array_map('intval', $rawIncidentIds) : [];

        $notes = isset($body['handover_notes']) ? (string)$body['handover_notes'] : null;
        $reason = isset($body['close_reason']) ? (string)$body['close_reason'] : null;

        try {
            $dto = $this->handoverService->closeAndHandover(
                technicianId: $technicianId,
                targetTechnicianId: $targetTechnicianId,
                incidentIdsToTransfer: $incidentIds,
                handoverNotes: $notes,
                closeReason: $reason
            );

            return Response::json($dto->toArray(), 200, $dto->summary);
        } catch (ActiveIncidentInProgressException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['blocking_incidents' => $e->getBlockingIncidentCodes()]
            );
        } catch (JustificationTooShortException $e) {
            return Response::error('MISSING_HANDOVER_NOTES', $e->getMessage(), 422);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', $e->getMessage(), 500);
        }
    }
}
