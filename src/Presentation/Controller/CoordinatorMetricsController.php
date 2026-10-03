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
    /**
     * Tope de filas del LISTADO paginado del audit log (RF-05).
     *
     * El tope vive aquí y no en el repositorio. `PdoAuditLogRepository::findEvents()`
     * recortaba a 100 por su cuenta, un valor pensado para el listado, y esa
     * decisión se colaba en la exportación: el endpoint anunciaba 10.000 registros
     * y entregaba 99, sin avisar. Un export que se calla lo que no incluye es peor
     * que uno que falla, porque la auditoría se apoya en él (Art. III).
     */
    private const AUDIT_PAGE_MAX = 100;

    /** Marca de orden de bytes que `MetricsExportService` antepone a todo CSV. */
    private const CSV_BOM = "\xEF\xBB\xBF";

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
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar el resumen de métricas.', 500);
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
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar el desglose analítico.', 500);
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
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al exportar métricas en CSV.', 500);
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
            $limit = max(1, min(self::AUDIT_PAGE_MAX, (int)$request->getQuery('limit', 50)));
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
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al consultar el registro de auditoría.', 500);
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

            // Si el recorte del límite de seguridad ha dejado fuera eventos, el
            // CSV lo declara en su propia cabecera. Un fichero de auditoría que
            // parece completo y no lo es induce a firmar una conformidad sobre
            // una ventana temporal que nadie ha mirado.
            //
            // El aviso va DETRÁS del BOM UTF-8, no delante:Excel decide el
            // formato de un CSV por sus tres primeros bytes, y una línea de
            // aviso por delante convertiría la exportación en latin-1 con los
            // acentos rotos. El BOM va primero siempre; el aviso, segundo.
            $totalEvents = $this->auditRepo->countEvents($filters);
            if ($totalEvents > count($events)) {
                $notice = sprintf(
                    "# ATENCION: EXPORTACION PARCIAL. %d de %d eventos de auditoria exportados. Filtre por fecha o entidad para obtener el historico completo.\n",
                    count($events),
                    $totalEvents
                );

                $csvContent = str_starts_with($csvContent, self::CSV_BOM)
                    ? self::CSV_BOM . $notice . substr($csvContent, strlen(self::CSV_BOM))
                    : $notice . $csvContent;
            }

            $dateSuffix = date('Y-m-d');
            $filename = "vendguard_audit_log_{$dateSuffix}.csv";

            return new Response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al exportar registro de auditoría.', 500);
        }
    }

    /**
     * Los handlers 500 no devuelven el mensaje de la excepción al cliente.
     *
     * Un `PDOException` incluye la consulta SQL que falló, y esa consulta
     * describe la tabla, las columnas y los valores comparados: es un mapa de la
     * base de datos servido por HTTP. El rastro completo se queda en el log.
     */
    private function logInternalFailure(Throwable $e): void
    {
        error_log('[vendguard] ' . $e);
    }
}
