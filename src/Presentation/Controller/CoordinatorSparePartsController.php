<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SparePartAnalyticsService;
use VendGuard\Application\Service\SparePartCatalogService;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorSparePartsController
 * 
 * Controlador REST para la gestión del Catálogo Maestro de Repuestos, Panel Analítico
 * de Consumos y Alertas Crónicas, Exportación CSV y Revisión de Piezas Fuera de Catálogo (Módulo M2).
 * 
 * Todos los endpoints están concebidos para acceso por parte del rol COORDINATOR.
 * Cumple con:
 * - RF-REP-01: Catálogo maestro de repuestos y compatibilidad por modelo de máquina dispensadora.
 * - RF-REP-02: Creación, actualización y baja lógica (soft delete) sin borrado físico (Art. III).
 * - RF-REP-04: Bandeja de revisión de repuestos fuera de catálogo emitidos en campo.
 * - RF-REP-08: Panel analítico de costes, ranking de sustituciones y alertas de fallos recurrentes.
 * - RF-REP-09: Descarga inmediata de consumos en formato CSV UTF-8.
 * - Dogma Vanilla (cero dependencias externas, PHP 8.2+ OOP).
 * - Dualismo Lingüístico (inglés en arquitectura, español en mensajes y contratos).
 */
class CoordinatorSparePartsController
{
    private SparePartCatalogService $catalogService;
    private SparePartAnalyticsService $analyticsService;
    private SparePartTraceabilityService $traceabilityService;
    private ?AuditLogger $auditLogger;

    public function __construct(
        ?SparePartCatalogService $catalogService = null,
        ?SparePartAnalyticsService $analyticsService = null,
        ?SparePartTraceabilityService $traceabilityService = null,
        ?SparePartRepositoryInterface $sparePartRepo = null,
        ?IncidentReplacedPartRepositoryInterface $replacedPartRepo = null,
        ?SparePartRequestRepositoryInterface $requestRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $sparePartRepoActual = $sparePartRepo ?? new PdoSparePartRepository();
        $replacedPartRepoActual = $replacedPartRepo ?? new PdoIncidentReplacedPartRepository();
        $requestRepoActual = $requestRepo ?? new PdoSparePartRequestRepository();
        $incidentRepoActual = $incidentRepo ?? new PdoIncidentRepository();
        $machineRepoActual = $machineRepo ?? new PdoMachineRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger(new PdoAuditLogRepository());

        $this->catalogService = $catalogService ?? new SparePartCatalogService(
            sparePartRepo: $sparePartRepoActual,
            auditLogger: $this->auditLogger
        );

        $this->analyticsService = $analyticsService ?? new SparePartAnalyticsService(
            replacedPartRepo: $replacedPartRepoActual,
            auditLogger: $this->auditLogger
        );

        $this->traceabilityService = $traceabilityService ?? new SparePartTraceabilityService(
            requestRepo: $requestRepoActual,
            replacedPartRepo: $replacedPartRepoActual,
            sparePartRepo: $sparePartRepoActual,
            incidentRepo: $incidentRepoActual,
            machineRepo: $machineRepoActual,
            auditLogger: $this->auditLogger
        );
    }

    // =========================================================================
    // 1. Catálogo Maestro de Repuestos (RF-REP-01 / RF-REP-02)
    // =========================================================================

    /**
     * GET /api/coordinator/spare-parts
     * 
     * Listado filtrable de repuestos del catálogo maestro con sus modelos compatibles.
     */
    public function getCatalog(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $search = $request->getQuery('search');
            $category = $request->getQuery('category');
            $machineModel = $request->getQuery('machine_model');
            $rawIsActive = $request->getQuery('is_active');

            $isActive = null;
            if ($rawIsActive !== null && trim((string)$rawIsActive) !== '') {
                $isActive = filter_var($rawIsActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            $searchStr = $search !== null ? trim((string)$search) : null;
            $catStr = $category !== null ? trim((string)$category) : null;
            $modelStr = $machineModel !== null ? trim((string)$machineModel) : null;

            $parts = $this->catalogService->listCatalog(
                search: $searchStr !== '' ? $searchStr : null,
                category: $catStr !== '' ? $catStr : null,
                machineModel: $modelStr !== '' ? $modelStr : null,
                isActive: $isActive
            );

            $data = array_map(fn(SparePart $p) => $p->toArray(), $parts);

            return Response::json($data, 200);
        });
    }

    /**
     * GET /api/coordinator/spare-parts/{id}
     * 
     * Detalle específico de un repuesto, sus modelos compatibles y unidades instaladas.
     */
    public function getPart(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $part = $this->catalogService->getSparePart($id);

            if ($part === null) {
                return Response::error(
                    'SPARE_PART_NOT_FOUND',
                    "No se encontró el repuesto solicitado en el catálogo (ID {$id}).",
                    404
                );
            }

            return Response::json($part->toArray(), 200);
        });
    }

    /**
     * POST /api/coordinator/spare-parts
     * 
     * Da de alta un nuevo repuesto en el catálogo maestro y vincula sus modelos compatibles.
     */
    public function createPart(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $actor = $this->extractActor($request);
            $body = $request->getParsedBody();

            try {
                $created = $this->catalogService->createSparePart($body, $actor);
                return Response::json($created->toArray(), 201, 'Repuesto registrado con éxito en el catálogo maestro.');
            } catch (SparePartCodeExistsException $e) {
                return Response::error('SPARE_PART_CODE_EXISTS', $e->getMessage(), 409);
            } catch (InvalidArgumentException $e) {
                return Response::error('INVALID_SPARE_PART_PAYLOAD', $e->getMessage(), 422);
            }
        });
    }

    /**
     * PUT /api/coordinator/spare-parts/{id}
     * 
     * Actualiza datos maestros, coste de referencia y modelos compatibles de un repuesto.
     */
    public function updatePart(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $body = $request->getParsedBody();

            try {
                $updated = $this->catalogService->updateSparePart($id, $body, $actor);
                return Response::json($updated->toArray(), 200, 'Repuesto actualizado con éxito.');
            } catch (SparePartNotFoundException $e) {
                return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
            } catch (InvalidArgumentException $e) {
                return Response::error('INVALID_SPARE_PART_PAYLOAD', $e->getMessage(), 422);
            }
        });
    }

    /**
     * PATCH /api/coordinator/spare-parts/{id}/status
     * 
     * Conmuta el estado activo/inactivo (baja lógica o reactivación sin borrado físico, Art. III).
     */
    public function toggleStatus(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $body = $request->getParsedBody();

            if (!isset($body['is_active'])) {
                return Response::error(
                    'INVALID_SPARE_PART_PAYLOAD',
                    'El campo is_active es obligatorio para cambiar el estado operativo del repuesto.',
                    422
                );
            }

            $isActive = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive === null) {
                return Response::error(
                    'INVALID_SPARE_PART_PAYLOAD',
                    'El campo is_active debe ser un valor booleano válido (true o false).',
                    422
                );
            }

            try {
                $this->catalogService->setSparePartStatus($id, $isActive, $actor);
                $part = $this->catalogService->getSparePart($id);

                $message = $isActive
                    ? 'El repuesto ha sido reactivado en el catálogo con éxito.'
                    : 'El repuesto ha sido desactivado del catálogo con éxito.';

                return Response::json([
                    'id'        => $id,
                    'part_code' => $part?->getPartCode() ?? '',
                    'is_active' => $isActive,
                    'message'   => $message,
                ], 200, $message);
            } catch (SparePartNotFoundException $e) {
                return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
            } catch (InvalidArgumentException $e) {
                return Response::error('INVALID_SPARE_PART_PAYLOAD', $e->getMessage(), 422);
            }
        });
    }

    /**
     * DELETE /api/coordinator/spare-parts/{id}
     * 
     * Baja lógica del repuesto (is_active = false) sin borrado físico (Art. III Constitución).
     */
    public function deletePart(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);

            try {
                $this->catalogService->setSparePartStatus($id, false, $actor);
                $part = $this->catalogService->getSparePart($id);

                return Response::json([
                    'id'        => $id,
                    'part_code' => $part?->getPartCode() ?? '',
                    'is_active' => false,
                    'message'   => 'El repuesto ha sido dado de baja lógica con éxito.',
                ], 200, 'El repuesto ha sido dado de baja lógica con éxito.');
            } catch (SparePartNotFoundException $e) {
                return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
            }
        });
    }

    /**
     * GET /api/coordinator/spare-parts/models
     * 
     * Devuelve la lista única de modelos de máquinas actualmente existentes en el parque.
     */
    public function getModels(Request $request): Response
    {
        return $this->handleExecution(function (): Response {
            $models = $this->catalogService->getDistinctMachineModels();
            return Response::json($models, 200);
        });
    }

    // =========================================================================
    // 2. Cuadro Analítico de Fiabilidad y Alertas (RF-REP-08)
    // =========================================================================

    /**
     * GET /api/coordinator/spare-parts/analytics
     * 
     * Métricas consolidadas de consumo, costes por sede y modelo, y detección de fallos recurrentes.
     */
    public function getAnalytics(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $rawPeriod = $request->getQuery('period_days');
            $periodDays = null;
            if ($rawPeriod !== null && trim((string)$rawPeriod) !== '') {
                if (!is_numeric($rawPeriod) || (int)$rawPeriod <= 0) {
                    return Response::error(
                        'INVALID_FILTER_PARAMS',
                        'El parámetro period_days debe ser un número entero positivo.',
                        400
                    );
                }
                $periodDays = (int)$rawPeriod;
            }

            $rawLimit = $request->getQuery('limit');
            $limit = 10;
            if ($rawLimit !== null && trim((string)$rawLimit) !== '') {
                if (is_numeric($rawLimit) && (int)$rawLimit > 0) {
                    $limit = (int)$rawLimit;
                }
            }

            $analytics = $this->analyticsService->getAnalytics($periodDays, $limit);

            return Response::json($analytics, 200);
        });
    }

    // =========================================================================
    // 3. Exportación de Consumos a CSV (RF-REP-09)
    // =========================================================================

    /**
     * GET /api/coordinator/spare-parts/export
     * 
     * Descarga inmediata de consumos de piezas en formato plano CSV UTF-8 con BOM.
     */
    public function exportCsv(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $actor = $this->extractActor($request);
            $rawPeriod = $request->getQuery('period_days');
            $periodDays = null;
            if ($rawPeriod !== null && trim((string)$rawPeriod) !== '') {
                if (!is_numeric($rawPeriod) || (int)$rawPeriod <= 0) {
                    return Response::error(
                        'INVALID_FILTER_PARAMS',
                        'El parámetro period_days debe ser un número entero positivo.',
                        400
                    );
                }
                $periodDays = (int)$rawPeriod;
            }

            $csvContent = $this->analyticsService->exportCsv($periodDays, $actor);
            $filename = $this->analyticsService->generateCsvFilename();

            return new Response($csvContent, 200, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        });
    }

    // =========================================================================
    // 4. Bandeja de Revisión de Piezas Fuera de Catálogo (RF-REP-04)
    // =========================================================================

    /**
     * GET /api/coordinator/spare-parts/requests/pending-review
     * 
     * Lista averías que requirieron piezas no catalogadas con la justificación técnica del técnico.
     */
    public function getPendingReviewRequests(Request $request): Response
    {
        return $this->handleExecution(function (): Response {
            $reviews = $this->traceabilityService->getPendingOutOfCatalogReviews();
            return Response::json($reviews, 200);
        });
    }

    // =========================================================================
    // Métodos Auxiliares y Control de Ejecución
    // =========================================================================

    /**
     * Ejecuta una acción controlando excepciones y estandarizando respuestas.
     *
     * @param Closure(): Response $action
     * @return Response
     */
    private function handleExecution(Closure $action): Response
    {
        try {
            return $action();
        } catch (SparePartCodeExistsException $e) {
            return Response::error('SPARE_PART_CODE_EXISTS', $e->getMessage(), 409);
        } catch (SparePartNotFoundException $e) {
            return Response::error('SPARE_PART_NOT_FOUND', $e->getMessage(), 404);
        } catch (InvalidArgumentException $e) {
            return Response::error('INVALID_SPARE_PART_PAYLOAD', $e->getMessage(), 422);
        } catch (DomainException $e) {
            $code = method_exists($e, 'getErrorCode') ? (string)$e->getErrorCode() : 'DOMAIN_ERROR';
            $statusCode = method_exists($e, 'getHttpStatusCode') ? (int)$e->getHttpStatusCode() : 400;
            return Response::error($code, $e->getMessage(), $statusCode);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Extrae y valida el identificador numérico de la ruta HTTP.
     *
     * @param Request $request
     * @return int
     * @throws InvalidArgumentException
     */
    private function extractIdFromRoute(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException('El identificador en la URL debe ser un número entero positivo.');
        }

        return (int)$rawId;
    }

    /**
     * Extrae los metadatos del usuario autenticado para trazabilidad en auditoría.
     *
     * @param Request $request
     * @return array{id: int|null, role: string, name: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id'   => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        return [
            'id'   => $request->getAttribute('user_id') !== null ? (int)$request->getAttribute('user_id') : 1,
            'role' => (string)$request->getAttribute('user_role', 'COORDINATOR'),
            'name' => 'Coordinación',
        ];
    }
}
