<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\DTO\ReceiveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\ResolveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\WorkshopRefurbishmentService;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\WorkshopRepairItemRepositoryInterface;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Persistence\PdoWorkshopRepairItemRepository;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * WorkshopRefurbishmentController
 *
 * Superficie REST de la bandeja de taller central (Módulo 27, `RF-02` a `RF-05`).
 *
 * Cinco puertos y una sola audiencia:
 *
 *   - `GET  /api/coordinator/workshop-items`                  · bandeja + KPIs.
 *   - `GET  /api/coordinator/workshop-items/export`           · CSV de dos secciones.
 *   - `POST /api/coordinator/workshop-items/{id}/receive`     · acuse de recibo.
 *   - `POST /api/coordinator/workshop-items/{id}/start-bench` · inicio de banco.
 *   - `POST /api/coordinator/workshop-items/{id}/resolve`     · dictamen terminal.
 *
 * **RBAC doble (Art. II.3).** El middleware ya exige rol `COORDINATOR` antes de
 * llegar aquí; el controlador lo vuelve a comprobar sobre los atributos que el
 * middleware inyectó. No es desconfianza decorativa: los métodos son públicos y
 * una suite o un futuro router podrían invocarlos sin la cadena de middleware, y
 * estos endpoints publican costes de reposición y diagnósticos internos
 * (`Art. V.4`). Sin sesión se responde `401`; con otra sesión, `403
 * FORBIDDEN_ROLE`.
 *
 * **El actor sale del token, nunca del cuerpo.** La unidad la pone la ruta y el
 * usuario el middleware; un Coordinador no puede firmar una recepción «en
 * nombre de» otro usuario. Los DTO de aplicación reciben ambos como argumentos
 * separados del `payload`.
 *
 * **Exportación (`QA 5`).** El controlador compone la cabecera HTTP del adjunto y
 * registra `EXPORT_WORKSHOP_ITEMS_CSV` con los filtros aplicados: el CSV contiene
 * importes de reposición y diagnósticos de banco, y una descarga sin rastro es
 * una fuga silenciosa de información interna.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class WorkshopRefurbishmentController
{
    private const CSV_CONTENT_TYPE = 'text/csv; charset=UTF-8';

    private const EXPORT_FILENAME_PREFIX = 'taller_piezas_recuperadas_';

    private ?WorkshopRefurbishmentService $service;

    private ?AuditLogger $auditLogger;

    public function __construct(
        ?WorkshopRefurbishmentService $service = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->service = $service;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Servicio resuelto bajo demanda: el controlador debe poder construirse en
     * suites sin abrir una conexión PDO, y la conexión sólo se abre si la
     * petición supera la guarda de sesión y rol.
     */
    private function service(): WorkshopRefurbishmentService
    {
        return $this->service ??= new WorkshopRefurbishmentService(
            workshopItems: new PdoWorkshopRepairItemRepository(),
            transactions: new PdoTransactionManager(),
            spareParts: new PdoSparePartRepository(),
            users: new PdoUserRepository(),
            auditLogger: $this->auditLogger(),
        );
    }

    private function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new PdoAuditLogRepository());
    }

    /**
     * GET /api/coordinator/workshop-items
     *
     * Los filtros se normalizan en `WorkshopTrayQueryDTO` antes de tocar el
     * repositorio; un `status` inventado o un `per_page` desmedido son `422`, no
     * una consulta degradada. El `summary` que devuelve el servicio se calcula
     * ignorando el filtro de estado (`QA 2`): cambiar de pestaña no reescribe los
     * totales de la cabecera.
     */
    public function getTray(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return $this->handleExecution(function () use ($request): Response {
            $query = WorkshopTrayQueryDTO::fromQuery($request->getQueryParams());

            return Response::json($this->service()->listWorkshopTray($query), 200);
        });
    }

    /**
     * GET /api/coordinator/workshop-items/export
     *
     * Adjunto `text/csv; charset=UTF-8` con BOM, separador `;` y nombre de fichero
     * con la marca local de Madrid. El cuerpo lo compone el servicio; aquí sólo se
     * envuelve la respuesta y se deja constancia de quién descargó qué filtros.
     */
    public function exportCsv(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return $this->handleExecution(function () use ($request): Response {
            $query = WorkshopTrayQueryDTO::fromQuery($request->getQueryParams());
            $content = $this->service()->exportWorkshopCsv($query);

            $actorId = (int)$request->getAttribute('user_id');
            $actorRole = (string)$request->getAttribute('user_role', 'COORDINATOR');
            $this->auditLogger()->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                // La exportación no pertenece a una ficha concreta, pero
                // `AuditEvent` exige una identidad positiva: se ancla en la
                // primera ficha del catálogo (1) y los filtros viajan en metadata.
                entityId: 1,
                action: 'EXPORT_WORKSHOP_ITEMS_CSV',
                userId: $actorId,
                userRole: $actorRole,
                userName: 'Coordinación',
                previousState: null,
                // `audit_log.new_state` es NOT NULL: una exportación no muta el
                // expediente, pero deja constancia explícita de lo que se llevó.
                newState: ['exported' => true],
                metadata: [
                    'status' => $query->status,
                    'technician_id' => $query->technicianId,
                    'category' => $query->category,
                    'search' => $query->search,
                    'row_count' => substr_count($content, "\n"),
                ],
            ));

            $filename = self::EXPORT_FILENAME_PREFIX
                . (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
                    ->format('Ymd_Hi')
                . '.csv';

            return new Response($content, 200, [
                'Content-Type' => self::CSV_CONTENT_TYPE,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        });
    }

    /**
     * POST /api/coordinator/workshop-items/{id}/receive
     *
     * `PENDING_DELIVERY → RECEIVED_IN_WORKSHOP` (`RF-02.1`). La nota es opcional;
     * superar 500 caracteres es `422` y no se recorta en silencio.
     */
    public function receive(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return $this->handleExecution(function () use ($request): Response {
            $dto = ReceiveWorkshopItemRequestDTO::fromPayload(
                $this->workshopItemId($request),
                (int)$request->getAttribute('user_id'),
                $request->getParsedBody()
            );

            $item = $this->service()->receiveInWorkshop($dto);

            return Response::json([
                'item' => $item,
                'message' => 'Recepción en taller registrada con éxito.',
            ], 200, 'Recepción en taller registrada con éxito.');
        });
    }

    /**
     * POST /api/coordinator/workshop-items/{id}/start-bench
     *
     * `RECEIVED_IN_WORKSHOP → IN_BENCH_TEST` (`RF-02.3`). El servicio decide bajo
     * bloqueo; aquí no se adelanta ninguna comprobación de estado.
     */
    public function startBench(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return $this->handleExecution(function () use ($request): Response {
            $item = $this->service()->startBenchTest(
                $this->workshopItemId($request),
                (int)$request->getAttribute('user_id')
            );

            return Response::json([
                'item' => $item,
                'message' => 'Inicio de comprobación en banco registrado con éxito.',
            ], 200, 'Inicio de comprobación en banco registrado con éxito.');
        });
    }

    /**
     * POST /api/coordinator/workshop-items/{id}/resolve
     *
     * Dictamen terminal (`RF-03`). El aviso `zero_net_savings_warning` viaja en la
     * raíz de `data` además de dentro del expediente: es el banner que la bandeja
     * pinta cuando el coste iguala o supera el valor de reposición (`C2`), y la
     * vista no debe tener que interpretar el importe para descubrirlo.
     */
    public function resolve(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return $this->handleExecution(function () use ($request): Response {
            $dto = ResolveWorkshopItemRequestDTO::fromPayload(
                $this->workshopItemId($request),
                (int)$request->getAttribute('user_id'),
                $request->getParsedBody()
            );

            $item = $this->service()->resolveWorkshopItem($dto);
            $zeroSavings = (bool)($item['zero_net_savings_warning'] ?? false);

            return Response::json([
                'item' => $item,
                'zero_net_savings_warning' => $zeroSavings,
                'message' => $dto->isRefurbishment()
                    ? 'Pieza reacondicionada y reincorporada con éxito.'
                    : 'Baja definitiva de la pieza registrada con éxito.',
            ], 200);
        });
    }

    // =========================================================================
    // Guardas y traducción de errores
    // =========================================================================

    /**
     * Verifica sesión y rol sobre los atributos del middleware (Art. II.3).
     *
     * Devuelve la respuesta de rechazo, o `null` cuando la petición puede pasar.
     */
    private function authorize(Request $request): ?Response
    {
        $userId = $request->getAttribute('user_id');
        if ($userId === null || !is_numeric($userId) || (int)$userId <= 0) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. Se requiere una sesión interna válida.',
                401
            );
        }

        $role = $request->getAttribute('user_role');
        if (!is_string($role) || $role !== 'COORDINATOR') {
            return Response::error(
                'FORBIDDEN_ROLE',
                'Acceso denegado: la bandeja de taller central es exclusiva de Coordinación.',
                403,
                ['required_role' => 'COORDINATOR']
            );
        }

        return null;
    }

    /**
     * Identificador del expediente en la ruta. Un segmento que no es un entero
     * positivo no puede corresponder a ninguna fila: se trata como no encontrado.
     */
    private function workshopItemId(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId < 1) {
            throw WorkshopRepairValidationException::workshopItemNotFound();
        }

        return (int)$rawId;
    }

    /**
     * Traduce las excepciones de dominio al contrato y encierra cualquier fallo
     * inesperado en un `500` sin filtrar la consulta SQL ni la traza al cliente.
     *
     * @param callable(): Response $action
     */
    private function handleExecution(callable $action): Response
    {
        try {
            return $action();
        } catch (WorkshopRepairValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getContext()
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('INVALID_WORKSHOP_PAYLOAD', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('[vendguard] ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }
}
