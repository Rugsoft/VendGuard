<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use PDO;
use Throwable;
use VendGuard\Application\Service\HandoverTargetService;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
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

    private HandoverTargetService $handoverTargetService;

    public function __construct(
        ?ShiftLifecycleService $lifecycleService = null,
        ?ShiftHandoverService $handoverService = null,
        ?PDO $pdo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?HandoverTargetService $handoverTargetService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $this->userRepo = $userRepo ?? new PdoUserRepository($pdo);

        $shifts = new PdoTechnicianShiftRepository($pdo);
        $absences = new PdoTechnicianAbsenceRepository($pdo);
        $lockRepo = $this->userRepo instanceof TechnicianLockRepositoryInterface
            ? $this->userRepo
            : new PdoUserRepository($pdo);

        $this->lifecycleService = $lifecycleService ?? new ShiftLifecycleService(
            $shifts,
            $absences,
            $this->userRepo,
            new PdoAuditLogRepository($pdo),
            new PdoTransactionManager($pdo),
            $lockRepo
        );

        $this->handoverTargetService = $handoverTargetService
            ?? new HandoverTargetService($this->userRepo, $shifts, $absences);

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
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al iniciar turno: ' . $e);
            return Response::error('SHIFT_START_FAILED', 'No se pudo iniciar el turno.', 500);
        }
    }

    public function pauseMeal(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        try {
            $dto = $this->lifecycleService->pauseMeal($technicianId);
            return Response::json($dto->toArray(), 200, 'Pausa para comida iniciada.');
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al pausar turno: ' . $e);
            return Response::error('SHIFT_PAUSE_FAILED', 'No se pudo pausar el turno.', 500);
        }
    }

    public function resume(Request $request): Response
    {
        $technicianId = (int)($request->getAttribute('user_id') ?? 0);
        try {
            $dto = $this->lifecycleService->resumeShift($technicianId);
            return Response::json($dto->toArray(), 200, 'Turno reanudado correctamente.');
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al reanudar turno: ' . $e);
            return Response::error('SHIFT_RESUME_FAILED', 'No se pudo reanudar el turno.', 500);
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

        try {
            return Response::json($this->handoverTargetService->listFor($currentId), 200);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al listar receptores de relevo: ' . $exception);
            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudieron cargar los compañeros disponibles.', 500);
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
        } catch (Throwable) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }
}
