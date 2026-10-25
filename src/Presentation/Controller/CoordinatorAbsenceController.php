<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use PDO;
use Throwable;
use VendGuard\Application\Service\SuggestedTechniciansService;
use VendGuard\Application\Service\TechnicianAbsenceService;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorAbsenceController
 *
 * Controlador REST para la gestión de ausencias de técnicos desde Coordinación (Módulo 21).
 */
final class CoordinatorAbsenceController
{
    private TechnicianAbsenceService $service;

    public function __construct(?TechnicianAbsenceService $service = null, ?PDO $pdo = null)
    {
        if ($service !== null) {
            $this->service = $service;
        } else {
            $pdo ??= ConnectionFactory::getConnection();
            $users = new PdoUserRepository($pdo);
            $this->service = new TechnicianAbsenceService(
                new PdoTechnicianAbsenceRepository($pdo),
                $users,
                new PdoIncidentRepository($pdo),
                new PdoAuditLogRepository($pdo),
                new PdoTransactionManager($pdo),
                $users,
                new SuggestedTechniciansService()
            );
        }
    }

    public function listAbsences(Request $request): Response
    {
        $techId = $request->getQuery('technician_id');
        $status = $request->getQuery('status');

        $parsedTechId = $techId !== null && is_numeric($techId) ? (int)$techId : null;
        $parsedStatus = $status !== null ? (string)$status : null;

        $dtos = $this->service->listAbsences($parsedTechId, $parsedStatus);
        $data = array_map(static fn ($dto): array => $dto->toArray(), $dtos);

        return Response::json($data, 200);
    }

    public function createAbsence(Request $request): Response
    {
        $rawId = $request->getRouteParam('id') ?? $request->getRouteParam('technician_id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_TECHNICIAN_IDENTIFIER', 'Identificador de técnico no válido.', 400);
        }
        $technicianId = (int)$rawId;

        $body = $request->getParsedBody();
        $reason = (string)($body['reason'] ?? '');
        $startsAt = (string)($body['starts_at'] ?? '');
        $endsAt = (string)($body['ends_at'] ?? '');
        $notes = isset($body['notes']) ? (string)$body['notes'] : null;

        if ($reason === '' || $startsAt === '' || $endsAt === '') {
            return Response::error('MISSING_REQUIRED_FIELDS', 'Los campos reason, starts_at y ends_at son obligatorios.', 422);
        }

        $userId = (int)($request->getAttribute('user_id') ?? 1);

        try {
            $result = $this->service->createAbsence(
                technicianId: $technicianId,
                reason: $reason,
                startsAt: $startsAt,
                endsAt: $endsAt,
                notes: $notes,
                createdBy: $userId
            );

            return Response::json([
                'absence' => $result['absence']->toArray(),
                'affected_incidents' => $result['affected_incidents'],
            ], 201, 'Ausencia registrada correctamente.');
        } catch (UserNotFoundException $e) {
            return Response::error('TECHNICIAN_NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidAbsenceException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }

    public function cancelAbsence(Request $request): Response
    {
        $rawId = $request->getRouteParam('id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_ABSENCE_IDENTIFIER', 'Identificador de ausencia no válido.', 400);
        }
        $absenceId = (int)$rawId;

        $body = $request->getParsedBody();
        $reason = trim((string)($body['cancellation_reason'] ?? $request->getQuery('cancellation_reason', '')));

        if (mb_strlen($reason) < 5) {
            return Response::error(
                'MISSING_CANCELLATION_REASON',
                'El motivo de cancelación debe tener al menos 5 caracteres.',
                422
            );
        }

        $userId = (int)($request->getAttribute('user_id') ?? 1);

        try {
            $dto = $this->service->cancelAbsence($absenceId, $userId, $reason);

            return Response::json([
                'cancelled' => true,
                'cancelled_at' => $dto->cancelledAt,
                'absence' => $dto->toArray(),
            ], 200, 'Ausencia cancelada correctamente.');
        } catch (InvalidAbsenceException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }
}
