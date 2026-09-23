<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Application\Service\MetricsExportService;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorMetricsController
 * 
 * Controlador REST para las operaciones analíticas del Coordinador del Servicio.
 * Gestiona el cuadro de mando de MTTR, alertas de SLA, desgloses multidimensionales,
 * registro inmutable de auditoría y descargas en CSV con codificación UTF-8 BOM.
 * 
 * Endpoints implementados:
 * - GET /api/coordinator/metrics/summary   (KPIs globales y estado de SLA)
 * - GET /api/coordinator/metrics/breakdown (Desglose multidimensional: sedes, técnicos, máquinas, averías)
 * - GET /api/coordinator/metrics/export    (Descarga CSV de métricas agregadas)
 * - GET /api/coordinator/audit-log         (Consulta cronológica paginada y filtrable del audit log)
 * - GET /api/coordinator/audit-log/export  (Descarga CSV de eventos de auditoría acotada a 10.000 filas)
 * 
 * Da cumplimiento estricto a RF-01, RF-02, RF-03, RF-05 y RF-06.
 * Requiere InternalAuthMiddleware(COORDINATOR).
 */
class CoordinatorMetricsController
{
    private MetricsCalculationService $metricsService;
    private MetricsExportService $exportService;
    private AuditLogRepositoryInterface $auditRepo;

    public function __construct(
        ?MetricsCalculationService $metricsService = null,
        ?MetricsExportService $exportService = null,
        ?AuditLogRepositoryInterface $auditRepo = null
    ) {
        $this->metricsService = $metricsService ?? new MetricsCalculationService();
        $this->exportService = $exportService ?? new MetricsExportService();
        $this->auditRepo = $auditRepo ?? new PdoAuditLogRepository();
    }

    /**
     * GET /api/coordinator/metrics/summary
     * 
     * Devuelve el resumen de KPIs globales, tasa de resolución, backlog y estado de SLA (RF-01, RF-03).
     */
    public function getSummary(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());
            $kpiSummary = $this->metricsService->getKpiSummary($filter);

            return Response::json($kpiSummary->toArray());
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar el resumen de métricas: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/coordinator/metrics/breakdown
     * 
     * Devuelve los desgloses analíticos combinables por sede, técnico, máquina y categoría (RF-02).
     */
    public function getBreakdown(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());
            $breakdown = $this->metricsService->getBreakdown($filter);

            return Response::json($breakdown);
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar el desglose analítico: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/coordinator/metrics/export
     * 
     * Descarga inmediata del archivo plano CSV con las métricas agregadas del filtro (RF-06, EARS 6.1).
     */
    public function exportMetrics(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());
            $breakdown = $this->metricsService->getBreakdown($filter);
            $csvContent = $this->exportService->exportMetricsCsv($breakdown);

            $fromDate = $filter->getFrom()->format('Y-m-d');
            $toDate = $filter->getTo()->format('Y-m-d');
            $filename = "vendguard_metrics_{$fromDate}_{$toDate}.csv";

            return new Response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al exportar métricas en CSV: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/coordinator/audit-log
     * 
     * Consulta cronológica paginada y filtrable del registro inmutable de auditoría (RF-05, EARS 5.5).
     */
    public function getAuditLog(Request $request): Response
    {
        try {
            $page = max(1, (int)$request->getQuery('page', 1));
            $limit = max(1, min(100, (int)$request->getQuery('limit', 50)));
            $offset = ($page - 1) * $limit;

            $filters = [];
            $entityType = $request->getQuery('entity_type');
            if ($entityType !== null && trim((string)$entityType) !== '') {
                $filters['entity_type'] = strtoupper(trim((string)$entityType));
            }

            $entityId = $request->getQuery('entity_id');
            if ($entityId !== null && is_numeric($entityId)) {
                $filters['entity_id'] = (int)$entityId;
            }

            $action = $request->getQuery('action');
            if ($action !== null && trim((string)$action) !== '') {
                $filters['action'] = strtoupper(trim((string)$action));
            }

            $userId = $request->getQuery('user_id');
            if ($userId !== null && is_numeric($userId)) {
                $filters['user_id'] = (int)$userId;
            }

            $from = $request->getQuery('from');
            if ($from !== null && trim((string)$from) !== '') {
                $filters['from'] = trim((string)$from);
            }

            $to = $request->getQuery('to');
            if ($to !== null && trim((string)$to) !== '') {
                $filters['to'] = trim((string)$to);
            }

            $totalRecords = $this->auditRepo->countEvents($filters);
            $events = $this->auditRepo->findEvents($filters, $limit, $offset);

            $items = array_map(fn($e) => $e->toArray(), $events);
            $totalPages = $limit > 0 ? (int)ceil($totalRecords / $limit) : 1;

            return Response::json([
                'page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'items' => $items,
            ]);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al consultar el registro de auditoría: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/coordinator/audit-log/export
     * 
     * Descarga de los eventos de auditoría en formato CSV acotado a 10.000 filas (RF-06, EARS 6.2).
     */
    public function exportAuditLog(Request $request): Response
    {
        try {
            $filters = [];
            $entityType = $request->getQuery('entity_type');
            if ($entityType !== null && trim((string)$entityType) !== '') {
                $filters['entity_type'] = strtoupper(trim((string)$entityType));
            }

            $entityId = $request->getQuery('entity_id');
            if ($entityId !== null && is_numeric($entityId)) {
                $filters['entity_id'] = (int)$entityId;
            }

            $action = $request->getQuery('action');
            if ($action !== null && trim((string)$action) !== '') {
                $filters['action'] = strtoupper(trim((string)$action));
            }

            $from = $request->getQuery('from');
            if ($from !== null && trim((string)$from) !== '') {
                $filters['from'] = trim((string)$from);
            }

            $to = $request->getQuery('to');
            if ($to !== null && trim((string)$to) !== '') {
                $filters['to'] = trim((string)$to);
            }

            // Recuperar hasta el límite de seguridad de 10.000 registros
            $events = $this->auditRepo->findEvents($filters, MetricsExportService::AUDIT_EXPORT_LIMIT, 0);
            $csvContent = $this->exportService->exportAuditLogCsv($events);

            $dateSuffix = date('Y-m-d');
            $filename = "vendguard_audit_log_{$dateSuffix}.csv";

            return new Response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error al exportar registro de auditoría: ' . $e->getMessage(), 500);
        }
    }
}
