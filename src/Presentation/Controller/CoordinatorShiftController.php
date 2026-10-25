<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Application\Service\TechnicianWorkHoursService;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\ShiftHandoverServiceFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftLogRepository;
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
 * auditoría y avisos. Los cuatro endpoints del registro de jornada (Módulo 22)
 * delegan a su vez en `TechnicianWorkHoursService`, que es la única fuente de las
 * cifras: la pantalla, el CSV y la rectificación no pueden discrepar.
 */
final class CoordinatorShiftController
{
    private ShiftLifecycleService $service;

    private ShiftHandoverService $handoverService;

    private TechnicianWorkHoursService $workHoursService;

    public function __construct(
        ?ShiftLifecycleService $service = null,
        ?PDO $pdo = null,
        ?ShiftHandoverService $handoverService = null,
        ?TechnicianWorkHoursService $workHoursService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $users = new PdoUserRepository($pdo);

        if ($service !== null) {
            $this->service = $service;
        } else {
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

        $this->workHoursService = $workHoursService ?? new TechnicianWorkHoursService(
            new PdoTechnicianShiftRepository($pdo),
            new PdoTechnicianShiftLogRepository($pdo),
            $users,
            new PdoTransactionManager($pdo),
            new PdoAuditLogRepository($pdo),
            $users
        );
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
        $rawEffectiveClose = trim((string)($body['effective_closed_at'] ?? ''));
        $effectiveClosedAtRaw = $rawEffectiveClose !== '' ? $rawEffectiveClose : null;
        $coordinatorId = (int)($request->getAttribute('user_id') ?? 1);

        try {
            $result = $this->handoverService->forceCloseShift(
                $technicianId,
                $coordinatorId,
                $reason,
                $effectiveClosedAtRaw
            );

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
        } catch (ShiftAdjustmentValidationException $e) {
            // El cierre forzado conserva el código de motivo del contrato §5.5; el
            // resto de rechazos (fechas, orden, solapamiento) viaja con su código.
            $errorCode = $e->getErrorCode() === ShiftAdjustmentValidationException::INVALID_ADJUSTMENT_REASON_CODE
                ? 'MISSING_FORCE_CLOSE_REASON'
                : $e->getErrorCode();

            return Response::error($errorCode, $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al cerrar forzosamente el turno: ' . $e);
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }

    /**
     * GET /api/coordinator/metrics/work-hours
     *
     * Informe de presencia, horas efectivas y Tiempo en Ruta del periodo
     * (contrato §5.1). El rango y el filtro los resuelve `MetricFilter`, no este
     * método: una segunda interpretación de `period`/`from`/`to` sería una segunda
     * verdad sobre qué días entran en la métrica.
     */
    public function workHoursReport(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());

            return Response::json($this->workHoursService->getWorkHoursReport($filter)->toArray());
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al generar el informe de jornada: ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar el registro de jornada.', 500);
        }
    }

    /**
     * GET /api/coordinator/metrics/work-hours/export
     *
     * CSV oficial del registro de jornada con UTF-8 BOM y separador `;`
     * (contrato §5.2, Resolución QA 5). El nombre del archivo viaja ya fechado en
     * el rango solicitado para que dos descargas de periodos distintos no se
     * confundan en el disco del usuario.
     */
    public function exportWorkHoursCsv(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());
            $csvContent = $this->workHoursService->exportWorkHoursCsv($filter);

            $fromDate = $filter->getFrom()->format('Ymd');
            $toDate = $filter->getTo()->format('Ymd');
            $filename = "vendguard_registro_jornada_{$fromDate}_{$toDate}.csv";

            return new Response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al exportar el registro de jornada: ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al exportar el registro de jornada.', 500);
        }
    }

    /**
     * PATCH /api/coordinator/shifts/{id}/adjust
     *
     * Rectificación auditada de las marcas de un turno cerrado (contrato §5.3).
     * El controlador no valida reglas: solo identifica la sesión y traslada el
     * rechazo del dominio con su código y estado HTTP.
     */
    public function adjustShift(Request $request): Response
    {
        $rawId = $request->getRouteParam('id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_SHIFT_IDENTIFIER', 'Identificador de turno no válido.', 400);
        }

        $body = $request->getParsedBody();
        $coordinatorId = (int)($request->getAttribute('user_id') ?? 1);
        $mealBreakSeconds = isset($body['meal_break_seconds']) && is_numeric($body['meal_break_seconds'])
            ? (int)$body['meal_break_seconds']
            : 0;

        try {
            $dto = $this->workHoursService->adjustShift(
                (int)$rawId,
                (string)($body['started_at'] ?? ''),
                (string)($body['closed_at'] ?? ''),
                $mealBreakSeconds,
                $coordinatorId,
                (string)($body['reason'] ?? '')
            );

            return Response::json(['shift' => $dto->toArray()], 200, 'Turno rectificado correctamente.');
        } catch (ShiftAdjustmentValidationException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidShiftTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al rectificar el turno: ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al rectificar el turno.', 500);
        }
    }

    /**
     * GET /api/coordinator/shifts/{id}/logs
     *
     * Bitácora inmutable y cronológica de una sesión de turno (contrato §5.4).
     * Se entrega tal cual la registró el dominio: sin editar, sin borrar y sin
     * recalcular marcas (Art. III.1 y III.3).
     */
    public function shiftLogs(Request $request): Response
    {
        $rawId = $request->getRouteParam('id');
        if (!is_numeric($rawId)) {
            return Response::error('INVALID_SHIFT_IDENTIFIER', 'Identificador de turno no válido.', 400);
        }

        try {
            return Response::json(['logs' => $this->workHoursService->getShiftLogs((int)$rawId)]);
        } catch (Throwable $e) {
            error_log('[vendguard] Fallo al leer la bitácora del turno: ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al leer la bitácora del turno.', 500);
        }
    }
}
