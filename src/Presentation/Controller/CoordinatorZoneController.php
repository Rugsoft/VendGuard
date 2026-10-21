<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\OperationalZoneService;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\PostalCodeConflictException;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorZoneController
 *
 * Superficie REST de la zonificación operativa para Coordinación (Módulo 17,
 * T-ZONE-09). Traduce HTTP a las operaciones de `OperationalZoneService` y
 * devuelve el veredicto con el código que declara el contrato técnico §3.
 *
 * | Método | Ruta                                                       | Operación            |
 * |--------|------------------------------------------------------------|----------------------|
 * | GET    | `/api/coordinator/zones`                                   | `listZones`          |
 * | POST   | `/api/coordinator/zones`                                   | `createZone` (201)   |
 * | GET    | `/api/coordinator/zones/{id}`                              | `getZone`            |
 * | PUT    | `/api/coordinator/zones/{id}`                              | `updateZone`         |
 * | POST   | `/api/coordinator/zones/{id}/postal-codes`                 | `assignPostalCodes`  |
 * | POST   | `/api/coordinator/zones/{id}/technicians`                  | `assignTechnician`   |
 * | DELETE | `/api/coordinator/zones/{id}/technicians/{tech_id}`         | `removeTechnician`   |
 * | GET    | `/api/coordinator/zones/{id}/technicians`                  | `listZoneTechnicians` |
 * | GET    | `/api/coordinator/technicians/quadrants`                   | `listTechnicianQuadrants` |
 * | POST   | `/api/coordinator/locations/{id}/override-zone`            | `overrideLocationZone` |
 *
 * **El controlador no decide reglas de negocio.** Las tres confirmaciones
 * (`confirm_transfers`, `confirm_primary_override`, `confirm_release`) se leen del
 * cuerpo y se entregan al servicio, que es donde vive la autoridad; aquí solo se
 * valida que sean booleanos de verdad y que el identificador de ruta sea positivo.
 *
 * **Mapa de errores canónico:** `404 ZONE_NOT_FOUND` / `LOCATION_NOT_FOUND`,
 * `409 POSTAL_CODES_REQUIRE_CONFIRMATION` (con `conflicts` en `error`, tal y como
 * lo pinta el modal), `409 TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`,
 * `400 BAD_REQUEST` / `422 UNPROCESSABLE_ENTITY` para las reglas de zona,
 * `400 VALIDATION_ERROR` para el cuerpo mal formado y `500 INTERNAL_SERVER_ERROR`
 * para lo imprevisto.
 *
 * La zona **nunca** se expone al portal de sede: estas rutas exigen
 * `InternalAuthMiddleware(COORDINATOR)` y este controlador no se registra en el
 * router de sede (Art. V.4, RF-07.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás del servicio, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorZoneController
{
    private OperationalZoneService $zoneService;

    public function __construct(?OperationalZoneService $zoneService = null, ?PDO $pdo = null)
    {
        $pdo ??= ConnectionFactory::getConnection();
        $this->zoneService = $zoneService ?? new OperationalZoneService(
            new PdoOperationalZoneRepository($pdo),
            new PdoLocationRepository($pdo)
        );
    }

    /**
     * GET /api/coordinator/zones
     *
     * Catálogo con los contadores agregados y el trabajo pendiente de configuración.
     */
    public function listZones(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $includeInactive = !$this->isDisabledQuery($request->getQuery('include_inactive'));

            $zones = $this->zoneService->listZones($includeInactive);

            return Response::json([
                'zones' => array_map(static fn ($zone): array => $zone->toArray(), $zones),
                'unassigned_locations_count' => $this->zoneService->countUnassignedLocations(),
            ], 200);
        });
    }

    /**
     * POST /api/coordinator/zones
     */
    public function createZone(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $zone = $this->zoneService->createZone($request->getParsedBody(), $this->actorId($request));

            return Response::json($zone->toArray(), 201, 'Zona operativa creada exitosamente.');
        });
    }

    /**
     * GET /api/coordinator/zones/{id}
     */
    public function getZone(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $zone = $this->zoneService->getZone($this->extractIdFromRoute($request));

            return Response::json($zone->toArray(), 200);
        });
    }

    /**
     * GET /api/coordinator/zones/{id}/technicians
     *
     * Cuadrante vivo con la identidad de cada técnico (RF-02.3, QA 15). Se sirve
     * aparte del listado a propósito: el catálogo publica contadores y no paga una
     * consulta por zona (RNF-01).
     */
    public function listZoneTechnicians(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            return Response::json(
                $this->zoneService->listZoneQuadrant($this->extractIdFromRoute($request)),
                200
            );
        });
    }

    /**
     * GET /api/coordinator/technicians/quadrants
     *
     * Consulta inversa del cuadrante: cada técnico con sus zonas vivas y el papel que
     * juega en cada una (RF-02.3, QA 15).
     */
    public function listTechnicianQuadrants(Request $request): Response
    {
        return $this->handleExecution(function (): Response {
            return Response::json(
                ['technicians' => $this->zoneService->listTechnicianQuadrants()],
                200
            );
        });
    }

    /**
     * PUT /api/coordinator/zones/{id}
     *
     * Desactivar exige `confirm_release` cuando la zona conserva territorio; el
     * servicio aplica la liberación atómica en ese caso.
     */
    public function updateZone(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $confirmRelease = $this->readBoolean($body, 'confirm_release', false);
            unset($body['confirm_release']);

            $zone = $this->zoneService->updateZone(
                $this->extractIdFromRoute($request),
                $body,
                $this->actorId($request),
                $confirmRelease
            );

            $message = $zone->isActive
                ? 'Zona operativa actualizada exitosamente.'
                : 'Zona desactivada: sus sedes quedan «Sin Zona Asignada» y su territorio se ha liberado.';

            return Response::json($zone->toArray(), 200, $message);
        });
    }

    /**
     * POST /api/coordinator/zones/{id}/postal-codes
     */
    public function assignPostalCodes(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $postalCodes = $body['postal_codes'] ?? null;
            if (!is_array($postalCodes)) {
                throw new InvalidArgumentException('El campo postal_codes debe ser una lista de códigos postales.');
            }

            $result = $this->zoneService->assignPostalCodes(
                $this->extractIdFromRoute($request),
                $postalCodes,
                $this->actorId($request),
                $this->readBoolean($body, 'confirm_transfers', false)
            );

            $message = match (true) {
                $result->releasedPostalCodes !== [] && $result->transferredPostalCodes !== [] =>
                    'Territorio sustituido: códigos liberados, códigos trasladados y sedes heredadas recolocadas.',
                $result->releasedPostalCodes !== [] =>
                    'Territorio sustituido: los códigos ausentes de la lista se han liberado.',
                $result->transferredPostalCodes !== [] =>
                    'Códigos postales trasladados y sedes heredadas recolocadas en una única transacción.',
                default => 'Códigos postales asignados a la zona.',
            };

            return Response::json($result->toArray(), 200, $message);
        });
    }

    /**
     * POST /api/coordinator/zones/{id}/technicians
     */
    public function assignTechnician(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $technicianId = $body['technician_id'] ?? null;
            if (!is_int($technicianId) && !(is_string($technicianId) && ctype_digit($technicianId))) {
                throw new InvalidArgumentException('El campo technician_id debe ser un número entero positivo.');
            }
            $technicianId = (int)$technicianId;
            if ($technicianId <= 0) {
                throw new InvalidArgumentException('El campo technician_id debe ser un número entero positivo.');
            }

            $assignment = $this->zoneService->assignTechnician(
                $technicianId,
                $this->extractIdFromRoute($request),
                $this->readBoolean($body, 'is_primary', false),
                $this->actorId($request),
                $this->readBoolean($body, 'confirm_primary_override', false)
            );

            return Response::json($assignment, 200, 'Técnico vinculado al cuadrante de la zona.');
        });
    }

    /**
     * DELETE /api/coordinator/zones/{id}/technicians/{tech_id}
     */
    public function removeTechnician(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $zoneId = $this->extractIdFromRoute($request);
            $technicianId = $this->extractPositiveRouteParam($request, 'tech_id');

            $removed = $this->zoneService->removeTechnician($technicianId, $zoneId, $this->actorId($request));

            if (!$removed) {
                return $this->errorResponse(
                    404,
                    'ASSIGNMENT_NOT_FOUND',
                    'El técnico no tiene una vinculación viva con esta zona.',
                    ['technician_id' => $technicianId, 'zone_id' => $zoneId]
                );
            }

            return Response::json([
                'zone_id' => $zoneId,
                'technician_id' => $technicianId,
                'removed' => true,
            ], 200, 'Técnico dado de baja del cuadrante (baja lógica con histórico).');
        });
    }

    /**
     * POST /api/coordinator/locations/{id}/override-zone
     *
     * Con `is_manual_override = false` restablece la herencia del código postal.
     */
    public function overrideLocationZone(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $manualOverride = $this->readBoolean($body, 'is_manual_override', true);

            $zoneId = null;
            if (array_key_exists('zone_id', $body) && $body['zone_id'] !== null) {
                if (!is_int($body['zone_id']) && !(is_string($body['zone_id']) && ctype_digit($body['zone_id']))) {
                    throw new InvalidArgumentException('El campo zone_id debe ser un número entero positivo o nulo.');
                }
                $zoneId = (int)$body['zone_id'];
                if ($zoneId <= 0) {
                    throw new InvalidArgumentException('El campo zone_id debe ser un número entero positivo o nulo.');
                }
            }

            $location = $this->zoneService->overrideLocationZone(
                $this->extractIdFromRoute($request),
                $zoneId,
                $this->actorId($request),
                $manualOverride
            );

            $message = $manualOverride
                ? 'Zona forzada manualmente: la sede conserva esta demarcación aunque cambie el catálogo de códigos postales.'
                : 'Herencia restablecida desde el código postal de la sede.';

            return Response::json($location, 200, $message);
        });
    }

    /**
     * Traduce las excepciones de dominio al contrato de errores del módulo.
     */
    private function handleExecution(callable $fn): Response
    {
        try {
            return $fn();
        } catch (PostalCodeConflictException $exception) {
            // El contrato §3.3 publica `conflicts` dentro de `error`, no dentro de
            // `details`: es la forma que el modal de confirmación consume.
            return $this->errorResponse(
                $exception->getHttpStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                ['conflicts' => $exception->getConflicts()]
            );
        } catch (PrimaryTechnicianConflictException $exception) {
            return $this->errorResponse(
                $exception->getHttpStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getDetails()
            );
        } catch (ZoneValidationException $exception) {
            return $this->errorResponse(
                $exception->getHttpStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getDetails()
            );
        } catch (ZoneNotFoundException $exception) {
            return $this->errorResponse(
                $exception->getHttpStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                ['zone_id' => $exception->getZoneId()]
            );
        } catch (LocationNotFoundException $exception) {
            return $this->errorResponse(
                $exception->getHttpStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                ['location_id' => $exception->getLocationId()]
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse(400, 'VALIDATION_ERROR', $exception->getMessage());
        } catch (Throwable) {
            return $this->errorResponse(
                500,
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar la operación territorial.'
            );
        }
    }

    /**
     * Envoltorio de error con la forma del contrato (`error.code`, `error.message`
     * y el bloque de detalle que corresponda).
     *
     * @param array<string, mixed> $details
     */
    private function errorResponse(int $statusCode, string $code, string $message, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        foreach ($details as $key => $value) {
            $error[$key] = $value;
        }

        return new Response(
            (string)json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Identificador del coordinador autenticado; el middleware ya lo garantiza.
     */
    private function actorId(Request $request): int
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User && $user->getId() !== null) {
            return $user->getId();
        }

        $userId = $request->getAttribute('user_id');
        if (!is_numeric($userId) || (int)$userId <= 0) {
            throw new InvalidArgumentException('No se pudo identificar al coordinador autenticado.');
        }

        return (int)$userId;
    }

    private function extractIdFromRoute(Request $request): int
    {
        return $this->extractPositiveRouteParam($request, 'id');
    }

    private function extractPositiveRouteParam(Request $request, string $name): int
    {
        $raw = $request->getRouteParam($name);
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException(
                sprintf('El identificador «%s» en la URL debe ser un número entero positivo.', $name)
            );
        }

        return (int)$raw;
    }

    /**
     * Booleano del cuerpo, estricto: un `"sí"` no se convierte en `true` por
     * accidente en una operación que libera territorio.
     *
     * @param array<string, mixed> $body
     */
    private function readBoolean(array $body, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException(sprintf('El campo %s debe ser un booleano.', $key));
    }

    /**
     * `include_inactive=0` excluye las zonas desactivadas; cualquier otro valor las
     * incluye, que es el comportamiento por defecto del panel.
     */
    private function isDisabledQuery(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value)), ['0', 'false', 'no'], true);
    }
}
