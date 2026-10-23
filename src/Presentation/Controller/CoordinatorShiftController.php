<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use PDO;
use Throwable;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorShiftController
 *
 * Controlador REST para la supervisión y cierre forzado de turnos de técnicos desde Coordinación (Módulo 21).
 */
final class CoordinatorShiftController
{
    private ShiftLifecycleService $service;

    public function __construct(?ShiftLifecycleService $service = null, ?PDO $pdo = null)
    {
        if ($service !== null) {
            $this->service = $service;
        } else {
            $pdo ??= ConnectionFactory::getConnection();
            $this->service = new ShiftLifecycleService(
                new PdoTechnicianShiftRepository($pdo),
                new PdoTechnicianAbsenceRepository($pdo),
                new PdoUserRepository($pdo),
                new PdoAuditLogRepository($pdo)
            );
        }
    }

    public function getShift(Request $request): Response
    {
        $rawId = $request->getRouteParam('id') ?? $request->getRouteParam('technician_id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_TECHNICIAN_IDENTIFIER', 'Identificador de técnico no válido.', 400);
        }
        $technicianId = (int)$rawId;

        $dto = $this->service->getCurrentShift($technicianId);
        return Response::json($dto->toArray(), 200);
    }

    public function forceClose(Request $request): Response
    {
        $rawId = $request->getRouteParam('id') ?? $request->getRouteParam('technician_id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_TECHNICIAN_IDENTIFIER', 'Identificador de técnico no válido.', 400);
        }
        $technicianId = (int)$rawId;

        $body = $request->getParsedBody();
        $reason = trim((string)($body['reason'] ?? ''));

        if (mb_strlen($reason) < 10) {
            return Response::error(
                'MISSING_FORCE_CLOSE_REASON',
                'El motivo de cierre forzado debe tener al menos 10 caracteres.',
                422
            );
        }

        $coordinatorId = (int)($request->getAttribute('user_id') ?? 1);

        try {
            $dto = $this->service->forceCloseShift($technicianId, $coordinatorId, $reason);

            return Response::json([
                'status' => $dto->status,
                'shift' => $dto->toArray(),
                'message' => 'Turno cerrado forzosamente por coordinación.',
            ], 200);
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (JustificationTooShortException $e) {
            return Response::error('MISSING_FORCE_CLOSE_REASON', $e->getMessage(), 422);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', $e->getMessage(), 500);
        }
    }
}
