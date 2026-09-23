<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianMetricsController
 * 
 * Controlador REST para el acceso segregado a las métricas personales del Técnico de Campo.
 * Permite al técnico autenticado consultar su MTTR individual, incidencias resueltas,
 * averías actualmente en curso y tiempo medio de primera respuesta (RF-04, EARS 4.1).
 * 
 * Cumple con el principio de Mínimo Privilegio (Constitución Art. V.4):
 * - El ID del técnico se obtiene obligatoriamente del token/sesión criptográfica.
 * - Cualquier intento de consultar métricas de otros técnicos o globales es bloqueado.
 * - Responde 401 Unauthorized sin credenciales y 403 Forbidden para roles no autorizados.
 */
class TechnicianMetricsController
{
    private MetricsCalculationService $metricsService;
    private UserRepositoryInterface $userRepo;

    public function __construct(
        ?MetricsCalculationService $metricsService = null,
        ?UserRepositoryInterface $userRepo = null
    ) {
        $this->metricsService = $metricsService ?? new MetricsCalculationService();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
    }

    /**
     * GET /api/technician/my-metrics
     * 
     * Consulta las métricas individuales de rendimiento del técnico autenticado.
     * Parámetros query: period (last_7_days, last_30_days [default], current_month).
     */
    public function getMyMetrics(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if ($userId === null || !is_numeric($userId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        $techId = (int)$userId;

        // Obtener nombre del técnico desde el contexto autenticado o repositorio
        $techName = null;
        $authUser = $request->getAttribute('authenticated_user');
        if ($authUser instanceof User) {
            $techName = $authUser->getName();
        } else {
            $user = $this->userRepo->findById($techId);
            if ($user !== null) {
                $techName = $user->getName();
            }
        }

        try {
            // El técnico solo puede consultar sus propias métricas. Sobrescribir technician_id por seguridad.
            $queryParams = $request->getQueryParams();
            $queryParams['technician_id'] = $techId;

            $filter = MetricFilter::fromQueryParams($queryParams);
            $metricsData = $this->metricsService->getTechnicianMetrics($techId, $filter);

            if ($techName !== null) {
                $metricsData['technician']['name'] = $techName;
            }

            return Response::json($metricsData);
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al consultar las métricas del técnico: ' . $e->getMessage(), 500);
        }
    }
}
