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
use VendGuard\Core\Domain\Exception\UserNotFoundException;
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
 * CoordinatorShiftController
 *
 * Controlador REST para la supervisión y cierre forzado de turnos de técnicos desde
 * Coordinación (Módulo 21).
 *
 * `getShift` consulta el ciclo de jornada; `forceClose` delega en el motor único de
 * cierre para que el cierre administrativo aplique exactamente las mismas reglas
 * que el cierre del técnico: guardia de `IN_PROGRESS`, protocolo sanitario Art. II,
 * auditoría y avisos.
 */
final class CoordinatorShiftController
{
    private ShiftLifecycleService $service;

    private ShiftHandoverService $handoverService;

    public function __construct(
        ?ShiftLifecycleService $service = null,
        ?PDO $pdo = null,
        ?ShiftHandoverService $handoverService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();

        if ($service !== null) {
            $this->service = $service;
        } else {
            $users = new PdoUserRepository($pdo);
            $this->service = new ShiftLifecycleService(
                new PdoTechnicianShiftRepository($pdo),
                new PdoTechnicianAbsenceRepository($pdo),
                $users,
                new PdoAuditLogRepository($pdo),
                new PdoTransactionManager($pdo),
                $users
            );
        }

        $this->handoverService = $handoverService ?? ShiftHandoverServiceFactory::create($pdo);
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
        $coordinatorId = (int)($request->getAttribute('user_id') ?? 1);

        try {
            $result = $this->handoverService->forceCloseShift($technicianId, $coordinatorId, $reason);

            return Response::json([
                'status' => $result->shift->status,
                'shift' => $result->shift->toArray(),
                'sanitary_emergency_transfers' => $result->sanitaryEmergencyTransfers,
                'sanitary_emergency_unassigned' => $result->sanitaryEmergencyUnassigned,
                'summary' => $result->summary,
            ], 200, $result->summary);
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (ActiveIncidentInProgressException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                ['blocking_incidents' => $e->getBlockingIncidentCodes()]
            );
        } catch (JustificationTooShortException $e) {
            return Response::error('MISSING_FORCE_CLOSE_REASON', $e->getMessage(), 422);
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }
}
