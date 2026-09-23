<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Closure;
use InvalidArgumentException;
use VendGuard\Application\Service\AdminLocationService;
use VendGuard\Application\Service\AdminMachineService;
use VendGuard\Application\Service\AdminUserService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\ActiveMachinesBlockedException;
use VendGuard\Core\Domain\Exception\CannotDeactivateSelfException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineTransferBlockedException;
use VendGuard\Core\Domain\Exception\MachineTypeChangeBlockedException;
use VendGuard\Core\Domain\Exception\MinimumActiveStaffException;
use VendGuard\Core\Domain\Exception\PendingIncidentsBlockedException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorAdminController
 * 
 * Controlador REST para el Panel de Administración Integral (Módulo 04: Sedes, Máquinas y Personal).
 * Gestiona endpoints protegidos por InternalAuthMiddleware(COORDINATOR), mapeando excepciones
 * de dominio a sus respectivos códigos y formatos de respuesta HTTP (200, 201, 400, 403, 404, 409, 422).
 * 
 * Cumple rigurosamente con:
 * - specs/technical/admin_crud_contracts.md
 * - Constitución Art. I, II, III, V
 * - Dogma Vanilla (PHP 8.2+ sin dependencias externas)
 * - Dualismo Lingüístico
 */
class CoordinatorAdminController
{
    private AdminLocationService $locationService;
    private AdminMachineService $machineService;
    private AdminUserService $userService;

    public function __construct(
        ?AdminLocationService $locationService = null,
        ?AdminMachineService $machineService = null,
        ?AdminUserService $userService = null
    ) {
        $auditLogger = new AuditLogger(new PdoAuditLogRepository());
        $locationRepo = new PdoLocationRepository();
        $machineRepo = new PdoMachineRepository();
        $userRepo = new PdoUserRepository();

        $this->locationService = $locationService ?? new AdminLocationService($locationRepo, $auditLogger);
        $this->machineService = $machineService ?? new AdminMachineService($machineRepo, $locationRepo, $auditLogger);
        $this->userService = $userService ?? new AdminUserService($userRepo, $auditLogger);
    }

    // =========================================================================
    // 1. Gestión de Sedes (Locations)
    // =========================================================================

    /**
     * GET /api/coordinator/locations
     * Lista las sedes con filtros por estado y búsqueda de texto.
     */
    public function listLocations(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $status = (string)$request->getQuery('status', 'all');
            $search = $request->getQuery('search') !== null ? (string)$request->getQuery('search') : null;

            $locations = $this->locationService->listLocations($status, $search);

            // Garantizar compatibilidad con contratos de flota previa (machine_count)
            $enriched = array_map(function (array $item): array {
                if (!isset($item['machine_count']) && isset($item['active_machines_count'])) {
                    $item['machine_count'] = $item['active_machines_count'];
                }
                return $item;
            }, $locations);

            return Response::json($enriched, 200);
        });
    }

    /**
     * POST /api/coordinator/locations
     * Da de alta a una nueva sede cliente.
     */
    public function createLocation(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $created = $this->locationService->createLocation($body, $actor, $ip);

            return Response::json($created->toArray(), 201, 'Sede creada exitosamente.');
        });
    }

    /**
     * GET /api/coordinator/locations/{id}
     * Obtiene el detalle de una sede específica.
     */
    public function getLocation(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $location = $this->locationService->getLocation($id, allowDeleted: true);

            return Response::json($location->toArray(), 200);
        });
    }

    /**
     * PATCH /api/coordinator/locations/{id}
     * Actualiza datos descriptivos de la sede (site_code es inmutable).
     */
    public function updateLocation(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $updated = $this->locationService->updateLocation($id, $body, $actor, $ip);

            return Response::json($updated->toArray(), 200, 'Sede actualizada exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/locations/{id}/deactivate
     * Da de baja lógica a una sede (bloqueada si contiene máquinas activas asociadas).
     */
    public function deactivateLocation(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $this->locationService->deactivateLocation($id, $actor, $ip);

            return Response::json(['id' => $id, 'is_active' => false], 200, 'Sede dada de baja lógica exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/locations/{id}/reactivate
     * Reactiva una sede previamente dada de baja.
     */
    public function reactivateLocation(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $reactivated = $this->locationService->reactivateLocation($id, $actor, $ip);

            return Response::json($reactivated->toArray(), 200, 'Sede reactivada exitosamente.');
        });
    }

    // =========================================================================
    // 2. Gestión del Parque de Máquinas (Machines)
    // =========================================================================

    /**
     * GET /api/coordinator/machines
     * Lista las máquinas con filtros cruzados (estado, sede, tipología y búsqueda).
     */
    public function listMachines(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $filters = [];
            if ($request->getQuery('status') !== null) {
                $filters['status'] = (string)$request->getQuery('status');
            }
            if ($request->getQuery('location_id') !== null && is_numeric($request->getQuery('location_id'))) {
                $filters['location_id'] = (int)$request->getQuery('location_id');
            }
            if ($request->getQuery('machine_type') !== null && trim((string)$request->getQuery('machine_type')) !== '') {
                $filters['machine_type'] = trim((string)$request->getQuery('machine_type'));
            }
            if ($request->getQuery('search') !== null && trim((string)$request->getQuery('search')) !== '') {
                $filters['search'] = trim((string)$request->getQuery('search'));
            }

            $machines = $this->machineService->listMachines($filters);

            return Response::json($machines, 200);
        });
    }

    /**
     * POST /api/coordinator/machines
     * Da de alta a una nueva máquina dispensadora en una sede activa.
     */
    public function createMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $created = $this->machineService->createMachine($body, $actor, $ip);

            return Response::json($created->toArray(), 201, 'Máquina dada de alta exitosamente.');
        });
    }

    /**
     * GET /api/coordinator/machines/{id}
     * Obtiene los datos detallados de una máquina.
     */
    public function getMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $machine = $this->machineService->getMachine($id, allowDeleted: true);

            return Response::json($machine->toArray(), 200);
        });
    }

    /**
     * PATCH /api/coordinator/machines/{id}
     * Actualiza datos de la máquina (cambio de tipología sanitaria bloqueado si hay avería o garantía activa).
     */
    public function updateMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $updated = $this->machineService->updateMachine($id, $body, $actor, $ip);

            return Response::json($updated->toArray(), 200, 'Máquina actualizada exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/machines/{id}/transfer
     * Traslada físicamente una máquina a otra sede cliente activa.
     */
    public function transferMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $targetLocationId = (int)($body['target_location_id'] ?? 0);
            $floorWing = (string)($body['floor_wing'] ?? '');
            $notes = isset($body['notes']) ? (string)$body['notes'] : null;

            if ($targetLocationId <= 0) {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'Se debe especificar una sede destino válida (target_location_id).');
            }

            $transferred = $this->machineService->transferMachine($id, $targetLocationId, $floorWing, $notes, $actor, $ip);

            return Response::json([
                'id'          => $transferred->getId(),
                'code'        => $transferred->getCode(),
                'location_id' => $transferred->getLocationId(),
                'floor_wing'  => $transferred->getFloorWing(),
                'notes'       => $transferred->getNotes(),
            ], 200, 'Máquina trasladada exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/machines/{id}/deactivate
     * Da de baja lógica a una máquina (bloqueada si tiene averías activas o tickets en garantía de 48h).
     */
    public function deactivateMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $this->machineService->deactivateMachine($id, $actor, $ip);

            return Response::json(['id' => $id, 'is_active' => false], 200, 'Máquina dada de baja lógica exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/machines/{id}/reactivate
     * Reactiva una máquina inactiva (con reubicación forzosa si la sede original está dada de baja).
     */
    public function reactivateMachine(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);

            $targetLocationId = isset($body['target_location_id']) && is_numeric($body['target_location_id'])
                ? (int)$body['target_location_id']
                : null;
            $floorWing = isset($body['floor_wing']) && trim((string)$body['floor_wing']) !== ''
                ? trim((string)$body['floor_wing'])
                : null;

            $reactivated = $this->machineService->reactivateMachine($id, $actor, $targetLocationId, $floorWing);

            return Response::json([
                'id'          => $reactivated->getId(),
                'code'        => $reactivated->getCode(),
                'location_id' => $reactivated->getLocationId(),
                'is_active'   => true,
            ], 200, 'Máquina reactivada exitosamente.');
        });
    }

    // =========================================================================
    // 3. Gestión de Personal Interno (Users: Técnicos y Coordinadores)
    // =========================================================================

    /**
     * GET /api/coordinator/users
     * Lista técnicos y coordinadores con filtros por rol y estado, informando de averías asignadas.
     */
    public function listUsers(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $status = (string)$request->getQuery('status', 'all');
            $role = (string)$request->getQuery('role', 'all');
            $search = $request->getQuery('search') !== null ? (string)$request->getQuery('search') : null;

            $users = $this->userService->listUsers($status, $role, $search);

            return Response::json($users, 200);
        });
    }

    /**
     * POST /api/coordinator/users
     * Da de alta a un nuevo técnico o coordinador con credenciales Bcrypt seguras.
     */
    public function createUser(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $created = $this->userService->createUser($body, $actor, $ip);

            return Response::json($created->toArray(), 201, 'Usuario dado de alta exitosamente.');
        });
    }

    /**
     * GET /api/coordinator/users/{id}
     * Obtiene la ficha de un usuario interno.
     */
    public function getUser(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $user = $this->userService->getUser($id, allowDeleted: true);

            return Response::json($user->toArray(), 200);
        });
    }

    /**
     * PATCH /api/coordinator/users/{id}
     * Actualiza datos de contacto de un usuario interno (email y rol inmutables).
     */
    public function updateUser(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $updated = $this->userService->updateUser($id, $body, $actor, $ip);

            return Response::json($updated->toArray(), 200, 'Datos de usuario actualizados exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/users/{id}/reset-password
     * Restablece la contraseña de acceso de un usuario interno (mínimo 8 caracteres).
     */
    public function resetUserPassword(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $body = $request->getParsedBody();
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $newPassword = (string)($body['new_password'] ?? ($body['password'] ?? ''));
            if (trim($newPassword) === '') {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'Se debe proporcionar el campo new_password con al menos 8 caracteres.');
            }

            $this->userService->resetPassword($id, $newPassword, $actor, $ip);

            return Response::json(null, 200, 'Contraseña restablecida exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/users/{id}/deactivate
     * Da de baja lógica a un usuario interno con salvaguardas de auto-baja, guardias mínimas y averías activas.
     */
    public function deactivateUser(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $this->userService->deactivateUser($id, $actor, $ip);

            return Response::json(['id' => $id, 'is_active' => false], 200, 'Usuario dado de baja lógica exitosamente.');
        });
    }

    /**
     * PATCH /api/coordinator/users/{id}/reactivate
     * Reactiva la cuenta de un usuario interno previamente dado de baja.
     */
    public function reactivateUser(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $actor = $this->extractActor($request);
            $ip = $this->extractClientIp($request);

            $this->userService->reactivateUser($id, $actor, $ip);

            return Response::json(['id' => $id, 'is_active' => true], 200, 'Usuario reactivado exitosamente.');
        });
    }

    // =========================================================================
    // Métodos Auxiliares y Mapeo Canónico de Excepciones
    // =========================================================================

    /**
     * Ejecuta una operación y captura excepciones de dominio para responder con contratos JSON canónicos.
     */
    private function handleExecution(Closure $fn): Response
    {
        try {
            return $fn();
        } catch (InactiveRecordCollisionException $e) {
            $extra = ['can_reactivate' => $e->canReactivate()];
            if ($e->getEntityType() === 'LOCATION') {
                $extra['location_id'] = $e->getEntityId();
            } elseif ($e->getEntityType() === 'MACHINE') {
                $extra['machine_id'] = $e->getEntityId();
            } elseif ($e->getEntityType() === 'USER') {
                $extra['user_id'] = $e->getEntityId();
            }
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage(), $extra);
        } catch (ActiveMachinesBlockedException $e) {
            return $this->errorResponse(
                $e->getHttpStatusCode(),
                $e->getErrorCode(),
                $e->getMessage(),
                [
                    'active_machines_count' => $e->getActiveMachinesCount(),
                    'active_machines'       => $e->getActiveMachines(),
                ]
            );
        } catch (MachineTransferBlockedException $e) {
            return $this->errorResponse(
                $e->getHttpStatusCode(),
                $e->getErrorCode(),
                $e->getMessage(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'status'      => $e->getTicketStatus(),
                ]
            );
        } catch (MachineTypeChangeBlockedException $e) {
            return $this->errorResponse(
                $e->getHttpStatusCode(),
                $e->getErrorCode(),
                $e->getMessage(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'status'      => $e->getTicketStatus(),
                ]
            );
        } catch (CannotDeactivateSelfException $e) {
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage());
        } catch (MinimumActiveStaffException $e) {
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage(), [
                'role' => $e->getRole(),
            ]);
        } catch (PendingIncidentsBlockedException $e) {
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage(), [
                'pending_incidents_count' => $e->getPendingIncidentsCount(),
            ]);
        } catch (LocationNotFoundException $e) {
            return $this->errorResponse(404, $e->getErrorCode(), $e->getMessage(), [
                'location_id' => $e->getLocationId(),
            ]);
        } catch (MachineNotFoundException $e) {
            return $this->errorResponse(404, $e->getErrorCode(), $e->getMessage());
        } catch (UserNotFoundException $e) {
            return $this->errorResponse(404, $e->getErrorCode(), $e->getMessage(), [
                'user_id' => $e->getUserId(),
            ]);
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'La sede original de la máquina está dada de baja')) {
                return $this->errorResponse(422, 'MACHINE_REACTIVATION_REQUIRES_NEW_LOCATION', $e->getMessage());
            }
            return $this->errorResponse(400, 'VALIDATION_ERROR', $e->getMessage());
        }
    }

    private function errorResponse(int $statusCode, string $code, string $message, array $extra = []): Response
    {
        $errorPayload = array_merge([
            'code'    => $code,
            'message' => $message,
        ], $extra);

        $payload = [
            'success' => false,
            'error'   => $errorPayload,
        ];

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private function extractIdFromRoute(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException('El identificador en la URL debe ser un número entero positivo.');
        }

        return (int)$rawId;
    }

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

    private function extractClientIp(Request $request): ?string
    {
        $forwarded = $request->getHeader('x-forwarded-for');
        if ($forwarded !== null && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }

        $clientIp = $request->getHeader('client-ip');
        if ($clientIp !== null && $clientIp !== '') {
            return trim($clientIp);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
