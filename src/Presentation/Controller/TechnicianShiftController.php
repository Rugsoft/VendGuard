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
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Notification\ShiftHandoverServiceFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
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

    private UserRepositoryInterface $userRepo;

    public function __construct(
        ?ShiftLifecycleService $lifecycleService = null,
        ?ShiftHandoverService $handoverService = null,
        ?PDO $pdo = null,
        ?UserRepositoryInterface $userRepo = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $this->userRepo = $userRepo ?? new PdoUserRepository($pdo);

        $this->lifecycleService = $lifecycleService ?? new ShiftLifecycleService(
            new PdoTechnicianShiftRepository($pdo),
            new PdoTechnicianAbsenceRepository($pdo),
            $this->userRepo,
            new PdoAuditLogRepository($pdo)
        );

        $this->handoverService = $handoverService ?? ShiftHandoverServiceFactory::create($pdo);
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

    /**
     * GET /api/technician/handover-targets
     *
     * Compañeros activos a los que el técnico autenticado puede traspasar su ruta.
     * La ruta de coordinación no sirve para este propósito (403) y, además, expone
     * datos internos que el móvil no necesita. Aquí solo viajan id, nombre y estado.
     */
    public function handoverTargets(Request $request): Response
    {
        $currentId = (int)($request->getAttribute('user_id') ?? 0);
        $targets = [];

        foreach ($this->userRepo->findAllTechnicians(true) as $technician) {
            $id = (int)$technician->getId();
            if ($id === $currentId) {
                continue;
            }

            $targets[] = [
                'id' => $id,
                'name' => $technician->getName(),
                'is_active' => true,
            ];
        }

        return Response::json($targets, 200);
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
        } catch (Throwable) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }
}
