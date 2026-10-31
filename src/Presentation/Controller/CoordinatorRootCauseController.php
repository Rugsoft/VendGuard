<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\RootCauseTaxonomyService;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\RootCauseRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoRootCauseRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorRootCauseController
 *
 * Superficie REST de administración del catálogo maestro de causas raíz y
 * soluciones estandarizadas (Módulo 25, RF-01, RF-05, D1/D3, QA 1 y QA 5).
 *
 * | Método | Ruta                                                            | Operación          |
 * |--------|-----------------------------------------------------------------|--------------------|
 * | GET    | `/api/coordinator/root-causes`                                  | `listCatalog`      |
 * | POST   | `/api/coordinator/root-causes`                                  | `createRootCause`  |
 * | PUT    | `/api/coordinator/root-causes/{id}`                             | `updateRootCause`  |
 * | POST   | `/api/coordinator/root-causes/{id}/solutions`                   | `createSolution`   |
 * | PUT    | `/api/coordinator/root-causes/{id}/solutions/{solutionId}`      | `updateSolution`   |
 *
 * El controlador no decide las reglas del catálogo: las altas atómicas, la
 * inmutabilidad de `code`/`category`/`root_cause_id`, las guardas de vigencia y
 * la auditoría antes/después viven en `RootCauseTaxonomyService`. Aquí se
 * traduce HTTP al caso de uso y se devuelve el veredicto con los códigos
 * canónicos del contrato §8.
 *
 * **Aislamiento de rol (Art. V.4, QA 5):** además del middleware de la ruta, el
 * propio controlador comprueba que el actor autenticado sea `COORDINATOR` y
 * responde `403 FORBIDDEN` a cualquier otro rol. El catálogo maestro, sus
 * contadores de uso y la exportación jamás se publican a la sede.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás del servicio, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorRootCauseController
{
    private ?RootCauseTaxonomyService $taxonomyService;

    private ?RootCauseRepositoryInterface $rootCauseRepository;

    private ?PDO $pdo;

    public function __construct(
        ?RootCauseTaxonomyService $taxonomyService = null,
        ?RootCauseRepositoryInterface $rootCauseRepository = null,
        ?PDO $pdo = null
    ) {
        $this->taxonomyService = $taxonomyService;
        $this->rootCauseRepository = $rootCauseRepository;
        $this->pdo = $pdo;
    }

    /**
     * GET /api/coordinator/root-causes
     *
     * Catálogo completo (activas e inactivas) con el contador de averías
     * históricas por causa y por solución (`RF-01`, `RF-05`).
     */
    public function listCatalog(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $category = null;
            $rawCategory = $request->getQuery('category');
            if ($rawCategory !== null && trim((string)$rawCategory) !== '') {
                $category = IncidentCategory::fromString((string)$rawCategory);
            }

            $isActive = null;
            $rawIsActive = $request->getQuery('is_active');
            if ($rawIsActive === null || trim((string)$rawIsActive) === '') {
                // `only_active=1` es el alias compacto del modal de catálogo.
                $rawIsActive = $request->getQuery('only_active');
            }
            if ($rawIsActive !== null && trim((string)$rawIsActive) !== '') {
                $parsed = filter_var($rawIsActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    throw new InvalidArgumentException(
                        'INVALID_ROOT_CAUSE_FILTER: los filtros de vigencia deben ser booleanos.'
                    );
                }
                $isActive = $parsed;
            }

            $catalog = $this->repository()->findFullCatalogForAdmin($category, $isActive);
            $items = [];

            foreach ($catalog as $entry) {
                $cause = $entry['root_cause'];
                $solutions = [];
                $activeSolutions = 0;
                $defaultSolutionId = null;

                foreach ($entry['solutions'] as $solutionEntry) {
                    $solution = $solutionEntry['solution'];
                    $solutionData = $solution->toArray();
                    $solutionData['incidents_count'] = (int)$solutionEntry['incidents_count'];
                    $solutions[] = $solutionData;

                    if ($solution->isActive()) {
                        $activeSolutions++;
                    }
                    if ($solution->isDefault() && $solution->isActive() && $defaultSolutionId === null) {
                        $defaultSolutionId = $solution->getId();
                    }
                }

                $causeData = $cause->toArray();
                $causeData['default_solution_id'] = $defaultSolutionId;
                $causeData['incidents_count'] = (int)$entry['incidents_count'];
                $causeData['active_solutions_count'] = $activeSolutions;
                $causeData['total_solutions_count'] = count($solutions);
                $causeData['solutions'] = $solutions;
                $items[] = $causeData;
            }

            return Response::json(['items' => $items]);
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo consultar el catálogo maestro de causas raíz.',
                500
            );
        }
    }

    /**
     * POST /api/coordinator/root-causes
     *
     * Alta atómica de la causa con su solución predeterminada inicial
     * (`EARS 01.1`, `QA 1`). Responde `201` con el agregado persistido.
     */
    public function createRootCause(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $actor = $this->actor($request);
            $created = $this->service()->createRootCauseWithInitialSolution(
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json(
                $created->toArray(),
                201,
                'Causa raíz creada junto con su solución estandarizada predeterminada.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo dar de alta la causa raíz.',
                500
            );
        }
    }

    /**
     * PUT /api/coordinator/root-causes/{id}
     *
     * Edición de los campos mutables de la causa, cambio de vigencia y
     * designación atómica de la solución predeterminada (`EARS 01.3`, `QA 1`).
     */
    public function updateRootCause(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $rootCauseId = $this->positiveRouteId($request, 'id');
        if ($rootCauseId === null) {
            return Response::error(
                'INVALID_ROOT_CAUSE_ID',
                'El identificador de la causa raíz debe ser un entero positivo.',
                400
            );
        }

        try {
            $actor = $this->actor($request);
            $updated = $this->service()->updateRootCause(
                $rootCauseId,
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json($updated->toArray(), 200, 'Causa raíz actualizada correctamente.');
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo actualizar la causa raíz.',
                500
            );
        }
    }

    /**
     * POST /api/coordinator/root-causes/{id}/solutions
     *
     * Añade una solución estandarizada activa a la causa. Si llega con
     * `is_default = true`, la conmutación de la predeterminada anterior ocurre
     * en la misma transacción (`EARS 01.2`, `D1`).
     */
    public function createSolution(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $rootCauseId = $this->positiveRouteId($request, 'id');
        if ($rootCauseId === null) {
            return Response::error(
                'INVALID_ROOT_CAUSE_ID',
                'El identificador de la causa raíz debe ser un entero positivo.',
                400
            );
        }

        try {
            $actor = $this->actor($request);
            $created = $this->service()->createStandardSolution(
                $rootCauseId,
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json($created->toArray(), 201, 'Solución estandarizada creada correctamente.');
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo crear la solución estandarizada.',
                500
            );
        }
    }

    /**
     * PUT /api/coordinator/root-causes/{id}/solutions/{solutionId}
     *
     * Edita nombre, vigencia y marca de predeterminada con las guardas `C2`:
     * no se desactiva la última solución activa ni la predeterminada vigente
     * sin relevarla antes (`QA 1`).
     */
    public function updateSolution(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $rootCauseId = $this->positiveRouteId($request, 'id');
        if ($rootCauseId === null) {
            return Response::error(
                'INVALID_ROOT_CAUSE_ID',
                'El identificador de la causa raíz debe ser un entero positivo.',
                400
            );
        }

        $solutionId = $this->positiveRouteId($request, 'solutionId');
        if ($solutionId === null) {
            return Response::error(
                'INVALID_SOLUTION_ID',
                'El identificador de la solución estandarizada debe ser un entero positivo.',
                400
            );
        }

        try {
            $actor = $this->actor($request);
            $updated = $this->service()->updateStandardSolution(
                $rootCauseId,
                $solutionId,
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json($updated->toArray(), 200, 'Solución estandarizada actualizada correctamente.');
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo actualizar la solución estandarizada.',
                500
            );
        }
    }

    /**
     * Puerta de rol exclusiva del catálogo (Art. V.4, `QA 5`).
     *
     * El middleware de la ruta ya exige `COORDINATOR`; esta segunda comprobación
     * impide que un cambio futuro de registro deje la superficie administrativa
     * abierta a un técnico o a la sede.
     */
    private function guardCoordinator(Request $request): ?Response
    {
        $user = $request->getAttribute('authenticated_user');
        $role = $user instanceof User
            ? $user->getRole()->value
            : strtoupper(trim((string)$request->getAttribute('user_role', '')));

        if ($role === '') {
            return Response::error(
                'UNAUTHORIZED',
                'No se pudo identificar al usuario autenticado.',
                401
            );
        }

        if ($role !== UserRole::COORDINATOR->value) {
            return Response::error(
                'FORBIDDEN',
                'Acceso denegado: la administración del catálogo de causas raíz exige rol COORDINATOR.',
                403,
                ['required_role' => UserRole::COORDINATOR->value, 'user_role' => $role]
            );
        }

        return null;
    }

    private function repository(): RootCauseRepositoryInterface
    {
        if ($this->rootCauseRepository === null) {
            $pdo = $this->pdo ?? ConnectionFactory::getConnection();
            $this->pdo = $pdo;
            $this->rootCauseRepository = new PdoRootCauseRepository($pdo);
        }

        return $this->rootCauseRepository;
    }

    private function service(): RootCauseTaxonomyService
    {
        if ($this->taxonomyService === null) {
            $pdo = $this->pdo ?? ConnectionFactory::getConnection();
            $this->pdo = $pdo;
            $this->taxonomyService = new RootCauseTaxonomyService(
                $this->rootCauseRepository ??= new PdoRootCauseRepository($pdo),
                new PdoAuditLogRepository($pdo),
                $pdo
            );
        }

        return $this->taxonomyService;
    }

    /**
     * @return array{id: int|null, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id' => $user->getId(),
                'name' => trim($user->getName()) !== '' ? $user->getName() : 'Coordinación de Servicio',
            ];
        }

        $userId = $request->getAttribute('user_id');
        $name = trim((string)$request->getAttribute('user_name', ''));

        return [
            'id' => is_numeric($userId) && (int)$userId > 0 ? (int)$userId : null,
            'name' => $name !== '' ? $name : 'Coordinación de Servicio',
        ];
    }

    private function positiveRouteId(Request $request, string $parameter): ?int
    {
        $raw = $request->getRouteParam($parameter);
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            return null;
        }

        return (int)$raw;
    }

    private function validationError(InvalidArgumentException $exception): Response
    {
        return Response::error(
            $this->canonicalCode($exception->getMessage(), 'INVALID_TAXONOMY_PAYLOAD'),
            $exception->getMessage(),
            422
        );
    }

    private function domainError(DomainException $exception): Response
    {
        $status = $exception->getCode();
        if ($status < 400 || $status > 599) {
            $status = 409;
        }

        $fallback = $status === 404 ? 'ROOT_CAUSE_NOT_FOUND' : 'TAXONOMY_CONFLICT';

        return Response::error(
            $this->canonicalCode($exception->getMessage(), $fallback),
            $exception->getMessage(),
            $status
        );
    }

    /**
     * Extrae el código simbólico con el que el servicio encabeza el mensaje
     * (`CANNOT_DEACTIVATE_DEFAULT_SOLUTION: ...`) sin reinterpretar el texto.
     */
    private function canonicalCode(string $message, string $fallback): string
    {
        if (preg_match('/^([A-Z][A-Z0-9_]{2,63})\s*:/', $message, $matches) !== 1) {
            return $fallback;
        }

        // El servicio usa nombres internos más explícitos; la API publica los
        // códigos canónicos del contrato §8 sin reescribir el mensaje original.
        return match ($matches[1]) {
            'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION' => 'IMMUTABLE_TAXONOMY_FIELD',
            'CANNOT_DEACTIVATE_DEFAULT_SOLUTION',
            'CANNOT_UNSET_DEFAULT_WITHOUT_REPLACEMENT' => 'DEFAULT_SOLUTION_CANNOT_BE_DEACTIVATED',
            'CANNOT_DEACTIVATE_LAST_ACTIVE_SOLUTION' => 'LAST_ACTIVE_SOLUTION_CANNOT_BE_DEACTIVATED',
            default => $matches[1],
        };
    }

    private function logInternalFailure(Throwable $exception): void
    {
        error_log('[vendguard] ' . $exception);
    }
}
