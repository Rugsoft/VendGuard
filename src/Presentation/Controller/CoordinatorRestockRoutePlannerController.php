<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\RestockRouteOverrideService;
use VendGuard\Application\Service\RestockRoutePlannerService;
use VendGuard\Application\Service\RestockRouteScheduleService;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoRestockRoutePlannerGateway;
use VendGuard\Infrastructure\Repository\PdoRestockRouteReadModel;
use VendGuard\Infrastructure\Repository\PdoRouteSettingsRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorRestockRoutePlannerController
 *
 * Superficie REST de Coordinación del Planificador de Rutas Periódicas de Carga
 * (Módulo 37; `RF-01`–`RF-03`, `RF-05.3`, `D1`–`D7`, `QA-1`–`QA-6`, `Art. II`,
 * `Art. III.1`, `Art. III.3`, `Art. V.1`, `Art. V.4` y `Art. VI`).
 *
 * El controlador es deliberadamente delgado: lee la petición, compone el caso de
 * uso real y traduce la excepción de negocio a la envolvente canónica. El
 * versionado inmutable, la atomicidad del lote, la proyección futura y la
 * revocación de la excepción viva viven en los servicios de aplicación; la
 * persistencia y el bloqueo pesimista, en el gateway PDO.
 *
 * | Método | Ruta                                                              |
 * |--------|-------------------------------------------------------------------|
 * | GET    | `/api/coordinator/restock-schedules`                               |
 * | POST   | `/api/coordinator/restock-schedules`                               |
 * | PUT    | `/api/coordinator/restock-schedules/machine/{machineId}`            |
 * | POST   | `/api/coordinator/restock-schedules/bulk`                          |
 * | PATCH  | `/api/coordinator/restock-schedules/{id}/deactivate`               |
 * | GET    | `/api/coordinator/restock-route/day`                               |
 * | POST   | `/api/coordinator/restock-route/overrides`                         |
 * | POST   | `/api/coordinator/restock-route/overrides/{id}/revoke`             |
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorRestockRoutePlannerController
{
    private RestockRouteScheduleService $schedules;

    private RestockRoutePlannerService $planner;

    private RestockRouteOverrideService $overrides;

    private RouteSettingsRepositoryInterface $routeSettings;

    public function __construct(
        ?PDO $pdo = null,
        ?RestockRouteScheduleService $schedules = null,
        ?RestockRoutePlannerService $planner = null,
        ?RestockRouteOverrideService $overrides = null,
        ?RouteSettingsRepositoryInterface $routeSettings = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $gateway = new PdoRestockRoutePlannerGateway($pdo);
        $readModel = new PdoRestockRouteReadModel($pdo);

        $this->planner = $planner ?? new RestockRoutePlannerService($readModel);
        $this->schedules = $schedules ?? new RestockRouteScheduleService(
            $gateway,
            $readModel,
            new AuditLogger(),
            new PdoTransactionManager($pdo)
        );
        $this->overrides = $overrides ?? new RestockRouteOverrideService(
            $gateway,
            $readModel,
            $this->planner,
            new AuditLogger(),
            new PdoTransactionManager($pdo)
        );
        $this->routeSettings = $routeSettings ?? new PdoRouteSettingsRepository($pdo);
    }

    /** GET /api/coordinator/restock-schedules */
    public function listSchedules(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $query = $request->getQueryParams();
            $filters = [];

            foreach (['machine_id', 'zone_id'] as $key) {
                $value = $this->optionalQuery($request, $key);
                if ($value === null) {
                    continue;
                }
                if (!ctype_digit($value) || (int)$value <= 0) {
                    throw new InvalidArgumentException(sprintf(
                        'El filtro %s debe ser un entero positivo.',
                        $key
                    ));
                }
                $filters[$key] = (int)$value;
            }

            foreach (['machine_type', 'cadence_type', 'search'] as $key) {
                $value = $this->optionalQuery($request, $key);
                if ($value !== null) {
                    $filters[$key] = $value;
                }
            }

            if (isset($query['has_schedule']) && trim((string)$query['has_schedule']) !== '') {
                $filters['has_schedule'] = $this->booleanQuery($request, 'has_schedule');
            }
            if (isset($query['include_inactive']) && trim((string)$query['include_inactive']) !== '') {
                $filters['include_inactive'] = $this->booleanQuery($request, 'include_inactive');
            }

            $limit = $this->optionalBoundedQuery($request, 'limit', 1, 200) ?? 50;
            $offset = $this->optionalNonNegativeQuery($request, 'offset') ?? 0;

            return Response::json($this->schedules->list($filters, $limit, $offset), 200);
        });
    }

    /** POST /api/coordinator/restock-schedules */
    public function createSchedule(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $machineId = $this->requiredPositiveBody($request, 'machine_id');
            $result = $this->schedules->save(
                $machineId,
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json(
                $result,
                200,
                'Agenda periódica guardada y versionada correctamente.'
            );
        });
    }

    /** PUT /api/coordinator/restock-schedules/machine/{machineId} */
    public function updateSchedule(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $result = $this->schedules->save(
                $this->positiveRouteId($request, 'machineId', 'máquina'),
                $request->getParsedBody(),
                $this->actor($request)
            );

            return Response::json(
                $result,
                200,
                'Agenda periódica guardada y versionada correctamente.'
            );
        });
    }

    /** POST /api/coordinator/restock-schedules/bulk */
    public function bulkSchedules(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->schedules->bulk($request->getParsedBody(), $this->actor($request)),
                200,
                'Configuración masiva aplicada de forma atómica.'
            );
        });
    }

    /** PATCH /api/coordinator/restock-schedules/{id}/deactivate */
    public function deactivateSchedule(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->schedules->deactivate(
                    $this->positiveRouteId($request, 'id', 'agenda'),
                    $this->actor($request)
                ),
                200,
                'Agenda periódica dada de baja lógicamente.'
            );
        });
    }

    /** GET /api/coordinator/restock-route/day */
    public function getDay(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $filters = [];
            foreach (['restocker_id', 'zone_id'] as $key) {
                $value = $this->optionalQuery($request, $key);
                if ($value === null) {
                    continue;
                }
                if (!ctype_digit($value) || (int)$value <= 0) {
                    throw new InvalidArgumentException(sprintf(
                        'El filtro %s debe ser un entero positivo.',
                        $key
                    ));
                }
                $filters[$key] = (int)$value;
            }

            return Response::json(
                $this->planner->buildCoordinatorDay(
                    $this->optionalQuery($request, 'service_date'),
                    $filters,
                    null,
                    $this->routeOrigin()
                ),
                200
            );
        });
    }

    /** POST /api/coordinator/restock-route/overrides */
    public function createOverride(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $result = $this->overrides->register(
                $request->getParsedBody(),
                $this->actor($request)
            );
            $status = ($result['idempotent_replay'] ?? false) === true ? 200 : 201;

            return Response::json(
                $result,
                $status,
                'Excepción diaria de ruta registrada correctamente.'
            );
        });
    }

    /** POST /api/coordinator/restock-route/overrides/{id}/revoke */
    public function revokeOverride(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            return Response::json(
                $this->overrides->revoke(
                    $this->positiveRouteId($request, 'id', 'excepción'),
                    $this->actor($request)
                ),
                200,
                'Excepción diaria de ruta revocada.'
            );
        });
    }

    /**
     * @param callable(): Response $operation
     */
    private function execute(callable $operation): Response
    {
        try {
            return $operation();
        } catch (RestockRoutePlannerValidationException $exception) {
            $details = $exception->getField() === null
                ? null
                : array_merge(['field' => $exception->getField()], $exception->getContext());

            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $details
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en la superficie de planificación de rutas: ' . $exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo completar la operación del planificador de rutas.',
                500
            );
        }
    }

    /**
     * @return array{id: int, role: string, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        $userId = (int)($request->getAttribute('user_id') ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('La operación exige una sesión interna autenticada.');
        }

        return [
            'id' => $userId,
            'role' => (string)($request->getAttribute('user_role') ?? 'COORDINATOR'),
            'name' => $user instanceof User ? $user->getName() : 'Coordinación',
        ];
    }

    private function positiveRouteId(Request $request, string $param, string $label): int
    {
        $raw = (string)($request->getRouteParam($param) ?? '');
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException(sprintf(
                'El identificador de %s debe ser un número entero positivo.',
                $label
            ));
        }

        return (int)$raw;
    }

    private function requiredPositiveBody(Request $request, string $field): int
    {
        $value = $request->getBodyParam($field);
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value)) && (int)trim($value) > 0) {
            return (int)trim($value);
        }

        throw new InvalidArgumentException(sprintf(
            'El campo %s es obligatorio y debe ser un entero positivo.',
            $field
        ));
    }

    /**
     * Origen de la ruta: la Base Central configurada, igual que la hoja móvil.
     *
     * @return array{source: string, latitude: float, longitude: float}|null
     */
    private function routeOrigin(): ?array
    {
        $settings = $this->routeSettings->find();
        if ($settings === null) {
            return null;
        }

        return [
            'source' => 'BASE_CENTRAL',
            'latitude' => $settings->getBaseLatitude(),
            'longitude' => $settings->getBaseLongitude(),
        ];
    }

    private function optionalQuery(Request $request, string $field): ?string
    {
        $value = $request->getQuery($field);
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized === '' ? null : $normalized;
    }

    private function optionalNonNegativeQuery(Request $request, string $field): ?int
    {
        $value = $this->optionalQuery($request, $field);
        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe ser un entero no negativo.',
                $field
            ));
        }

        return (int)$value;
    }

    private function optionalBoundedQuery(Request $request, string $field, int $minimum, int $maximum): ?int
    {
        $value = $this->optionalNonNegativeQuery($request, $field);
        if ($value === null) {
            return null;
        }

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe estar entre %d y %d.',
                $field,
                $minimum,
                $maximum
            ));
        }

        return $value;
    }

    private function booleanQuery(Request $request, string $field): bool
    {
        $value = strtolower((string)$this->optionalQuery($request, $field));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException(sprintf(
                'El parámetro %s debe ser un booleano.',
                $field
            )),
        };
    }
}
