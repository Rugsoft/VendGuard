<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\DTO\ParetoFilterDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Application\Service\MetricsExportService;
use VendGuard\Application\Service\RootCauseParetoService;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\SatisfactionSurveyRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoRootCauseParetoAnalyticsRepository;
use VendGuard\Infrastructure\Repository\PdoSatisfactionSurveyRepository;
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
 * - GET /api/coordinator/metrics/csat      (Satisfacción de las sedes: media, distribución, sedes, técnicos y tendencia)
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
    private SatisfactionSurveyRepositoryInterface $surveyRepo;

    /** Motor Pareto 80/20 (Módulo 25); se resuelve bajo demanda para no abrir conexión al construir. */
    private ?RootCauseParetoService $paretoService;

    public function __construct(
        ?MetricsCalculationService $metricsService = null,
        ?MetricsExportService $exportService = null,
        ?AuditLogRepositoryInterface $auditRepo = null,
        ?SatisfactionSurveyRepositoryInterface $surveyRepo = null,
        ?RootCauseParetoService $paretoService = null
    ) {
        $this->metricsService = $metricsService ?? new MetricsCalculationService();
        $this->exportService = $exportService ?? new MetricsExportService();
        $this->auditRepo = $auditRepo ?? new PdoAuditLogRepository();
        $this->surveyRepo = $surveyRepo ?? new PdoSatisfactionSurveyRepository();
        $this->paretoService = $paretoService;
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
     * GET /api/coordinator/metrics/csat
     *
     * Satisfacción de las sedes en el periodo elegido (RF-04): media redondeada,
     * distribución de cinco estrellas, tabla por sede, tabla por técnico y serie
     * mensual (contratos §6.2).
     *
     * El rango y los filtros los resuelve `MetricFilter`, no este método: una
     * segunda interpretación de `period`/`from`/`to` sería una segunda verdad
     * sobre qué días entran en la métrica.
     */
    public function getCsat(Request $request): Response
    {
        try {
            $filter = MetricFilter::fromQueryParams($request->getQueryParams());

            return Response::json($this->surveyRepo->aggregate($filter)->toArray());
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_FILTER_PARAMS', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->logInternalFailure($e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error al procesar la satisfacción de las sedes.', 500);
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
     * GET /api/coordinator/metrics/root-causes-pareto
     *
     * Informe 80/20 del catálogo de causas raíz (Módulo 25, RF-05, D5, QA 2 y QA 4).
     * La puerta de rol la impone `InternalAuthMiddleware(COORDINATOR)` en el router:
     * técnico y sede reciben `403 FORBIDDEN` antes de llegar aquí (Art. V.4, QA 5).
     */
    public function getRootCausesPareto(Request $request): Response
    {
        try {
            $filter = $this->buildParetoFilter($request);

            return Response::json($this->pareto()->buildParetoReport($filter)->toArray());
        } catch (InvalidArgumentException $exception) {
            return Response::error('INVALID_PARETO_FILTER', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo calcular el análisis de Pareto de causas raíz.',
                500
            );
        }
    }

    /**
     * GET /api/coordinator/metrics/root-causes-pareto/export
     *
     * Descarga del CSV `UTF-8 BOM` en dos secciones del contrato §6.2 y deja el
     * evento `EXPORT_ROOT_CAUSE_PARETO_CSV` en la auditoría inmutable (Art. III.3).
     */
    public function exportRootCausesParetoCsv(Request $request): Response
    {
        try {
            $filter = $this->buildParetoFilter($request);
            $csvContent = $this->pareto()->exportCsv($filter, $this->extractActor($request));

            $filename = 'vendguard_pareto_garantias_' . date('Y-m-d') . '.csv';

            return new Response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::error('INVALID_PARETO_FILTER', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo exportar el análisis de Pareto de causas raíz.',
                500
            );
        }
    }

    /**
     * Resuelve el motor Pareto bajo demanda: su repositorio abre conexión PDO y
     * este controlador también se construye en suites unitarias sin base de datos.
     */
    private function pareto(): RootCauseParetoService
    {
        return $this->paretoService ??= new RootCauseParetoService(
            new PdoRootCauseParetoAnalyticsRepository(),
            new AuditLogger(new PdoAuditLogRepository())
        );
    }

    /**
     * Construye el filtro validado del contrato §6.1.
     *
     * Acepta `period_days` (`30/90/180/365/all`), el par `from`/`to` y la clave
     * `period` heredada del cuadro de mando. Sin parámetros, la ventana por
     * defecto es de 90 días sobre `resolved_at`, no los 30 del panel general.
     */
    private function buildParetoFilter(Request $request): ParetoFilterDto
    {
        $query = $request->getQueryParams();
        $timezone = new DateTimeZone('Europe/Madrid');
        $today = new DateTimeImmutable('now', $timezone);

        $periodDays = null;
        $rawPeriodDays = $query['period_days'] ?? null;
        if ($rawPeriodDays !== null && trim((string)$rawPeriodDays) !== '') {
            $normalized = strtolower(trim((string)$rawPeriodDays));
            $periodDays = $normalized === 'all' ? 'all' : (int)$normalized;
            if ($normalized !== 'all' && !ctype_digit($normalized)) {
                throw new InvalidArgumentException(
                    'INVALID_PARETO_FILTER: period_days sólo admite 30, 90, 180, 365 o all.'
                );
            }
        }

        $from = $this->normalizeQueryDate($query['from'] ?? null);
        $to = $this->normalizeQueryDate($query['to'] ?? null);

        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException(
                'INVALID_PARETO_FILTER: las fechas from y to deben declararse juntas.'
            );
        }

        if ($from === null && $periodDays === null) {
            $rawPeriod = $query['period'] ?? null;
            if ($rawPeriod !== null && trim((string)$rawPeriod) !== '') {
                $metricFilter = MetricFilter::fromQueryParams(
                    ['period' => (string)$rawPeriod],
                    $today
                );
                $from = $metricFilter->getFrom()->format('Y-m-d');
                $to = $metricFilter->getTo()->format('Y-m-d');
            }
        }

        if ($from === null) {
            if ($periodDays === 'all') {
                $from = '1970-01-01';
                $to = $today->format('Y-m-d');
            } else {
                $days = $periodDays ?? 90;
                $from = $today->modify('-' . ((int)$days - 1) . ' days')->format('Y-m-d');
                $to = $today->format('Y-m-d');
            }
        }

        $category = null;
        $rawCategory = $query['category'] ?? null;
        if ($rawCategory !== null && trim((string)$rawCategory) !== '') {
            $category = IncidentCategory::fromString((string)$rawCategory);
        }

        $vitalFewOnly = false;
        $rawVitalFew = $query['vital_few_only'] ?? false;
        if ($rawVitalFew !== null && trim((string)$rawVitalFew) !== '') {
            $parsed = filter_var($rawVitalFew, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw new InvalidArgumentException(
                    'INVALID_PARETO_FILTER: vital_few_only debe ser un booleano.'
                );
            }
            $vitalFewOnly = $parsed;
        }

        return new ParetoFilterDto(
            startDate: (string)$from,
            endDate: (string)$to,
            periodDays: $periodDays,
            manufacturer: $this->nullableQueryString($query['manufacturer'] ?? null),
            machineModel: $this->nullableQueryString($query['machine_model'] ?? null),
            category: $category,
            locationId: $this->positiveQueryId($query['location_id'] ?? null, 'location_id'),
            technicianId: $this->positiveQueryId($query['technician_id'] ?? null, 'technician_id'),
            vitalFewOnly: $vitalFewOnly
        );
    }

    private function normalizeQueryDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = substr(trim((string)$value), 0, 10);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableQueryString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized === '' ? null : $normalized;
    }

    private function positiveQueryId(mixed $value, string $field): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        $normalized = trim((string)$value);
        if (!ctype_digit($normalized) || (int)$normalized <= 0) {
            throw new InvalidArgumentException(
                'INVALID_PARETO_FILTER: ' . $field . ' debe ser un identificador positivo.'
            );
        }

        return (int)$normalized;
    }

    /**
     * @return array{id: int|null, role: string, name: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof \VendGuard\Core\Domain\Model\User) {
            return [
                'id' => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        return [
            'id' => is_numeric($request->getAttribute('user_id')) ? (int)$request->getAttribute('user_id') : null,
            'role' => (string)$request->getAttribute('user_role', 'COORDINATOR'),
            'name' => 'Coordinación de Servicio',
        ];
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
