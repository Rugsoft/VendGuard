<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\TroubleshootingGuideService;
use VendGuard\Core\Domain\Exception\TroubleshootingGuideValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoMachineTroubleshootingGuideRepository;
use VendGuard\Infrastructure\Repository\PdoRootCauseRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TroubleshootingGuideController
 *
 * Superficie REST del catálogo de guías rápidas de diagnóstico (Módulo 28,
 * `RF-01`–`RF-05`, `D1`–`D4`, `C1`–`C6`, `QA 1`–`QA 4`, Art. II, III.3 y V.4).
 *
 * | Método | Ruta                                                              | Operación      |
 * |--------|-------------------------------------------------------------------|----------------|
 * | GET    | `/api/troubleshooting-guides/by-model`                            | `getByModel`   |
 * | GET    | `/api/coordinator/troubleshooting-guides`                        | `listCatalog`  |
 * | POST   | `/api/coordinator/troubleshooting-guides`                        | `createGuide`  |
 * | PUT    | `/api/coordinator/troubleshooting-guides/{id}`                   | `updateGuide`  |
 * | PATCH  | `/api/coordinator/troubleshooting-guides/{id}/status`            | `updateStatus` |
 *
 * El controlador no decide las reglas del catálogo: la normalización del modelo,
 * el conmutador de categoría, el cruce con la furgoneta, la cobertura del parque
 * y el CRUD auditado viven en `TroubleshootingGuideService`. Aquí se traduce HTTP
 * al caso de uso y se devuelve el veredicto con los códigos canónicos del
 * contrato técnico.
 *
 * **Aislamiento de roles (Art. V.4, RNF-03):** el middleware de la ruta acredita
 * la sesión interna; además, el método `getByModel` sólo admite `TECHNICIAN` y
 * `COORDINATOR`, y los cuatro endpoints administrativos revalidan `COORDINATOR`.
 * La sede no puede leer el catálogo ni sus indicadores de cobertura.
 *
 * **Sin importes (Art. V.4):** ninguna respuesta serializa coste, precio ni
 * importe; el servicio ya entrega las piezas con unidades físicas únicamente.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás del servicio, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class TroubleshootingGuideController
{
    private ?TroubleshootingGuideService $guideService;

    private ?PDO $pdo;

    public function __construct(
        ?TroubleshootingGuideService $guideService = null,
        ?PDO $pdo = null
    ) {
        // La conexión y los colaboradores se resuelven bajo demanda: el
        // controlador también se construye en suites que no tocan la base.
        $this->guideService = $guideService;
        $this->pdo = $pdo;
    }

    /**
     * GET /api/troubleshooting-guides/by-model
     *
     * Guías activas del modelo con conmutador de categoría, aviso sanitario y
     * cruce de furgoneta para el técnico autenticado (`RF-01.1`–`RF-01.3`,
     * `RF-02.1`–`RF-02.3`, `D1`, `D3`, `QA 3`).
     */
    public function getByModel(Request $request): Response
    {
        $forbidden = $this->guardReader($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $rawModel = $request->getQuery('machine_model');
        if (!is_string($rawModel) || trim($rawModel) === '') {
            return Response::error(
                TroubleshootingGuideValidationException::INVALID_GUIDE_MODEL,
                'El parámetro machine_model es obligatorio para recuperar las guías de diagnóstico.',
                422,
                ['field' => 'machine_model']
            );
        }

        try {
            $data = $this->service()->getGuidesForModel(
                $rawModel,
                $this->optionalQueryString($request, 'breakdown_type'),
                $this->optionalQueryString($request, 'machine_type'),
                $this->technicianId($request)
            );

            return Response::json($data, 200, 'Guías de diagnóstico recuperadas correctamente.');
        } catch (TroubleshootingGuideValidationException $exception) {
            return $this->guideError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudieron recuperar las guías de diagnóstico.',
                500
            );
        }
    }

    /**
     * GET /api/coordinator/troubleshooting-guides
     *
     * Catálogo filtrable e indicadores de cobertura del parque (`QA 1`). El
     * resumen no se apaga cuando la tabla inferior está filtrada.
     */
    public function listCatalog(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $data = $this->service()->listForCoordinator([
                'machine_model' => $this->optionalQueryString($request, 'machine_model'),
                'breakdown_type' => $this->optionalQueryString($request, 'breakdown_type'),
                'status' => $this->optionalQueryString($request, 'status') ?? 'ALL',
                'search' => $this->optionalQueryString($request, 'search'),
            ]);

            return Response::json($data, 200, 'Catálogo de guías y cobertura del parque recuperados.');
        } catch (InvalidArgumentException $exception) {
            return Response::error(
                'INVALID_GUIDE_FILTER',
                $exception->getMessage(),
                422,
                ['field' => 'status']
            );
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo recuperar el catálogo de guías de diagnóstico.',
                500
            );
        }
    }

    /**
     * POST /api/coordinator/troubleshooting-guides
     *
     * Alta auditada de una guía (`RF-04.1`, `C1`–`C3`, `QA 2`, Art. III.3).
     * Responde `201` con `{ guide }`.
     */
    public function createGuide(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        try {
            $guide = $this->service()->createGuide(
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json(
                ['guide' => $guide],
                201,
                'Guía de diagnóstico creada correctamente.'
            );
        } catch (TroubleshootingGuideValidationException $exception) {
            return $this->guideError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo crear la guía de diagnóstico.',
                500
            );
        }
    }

    /**
     * PUT /api/coordinator/troubleshooting-guides/{id}
     *
     * Edición auditada de los campos mutables (`RF-04.1`, `C1`, `C2`,
     * Art. III.3). Responde `200` con `{ guide }`.
     */
    public function updateGuide(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $guideId = $this->positiveRouteId($request, 'id');
        if ($guideId === null) {
            return Response::error(
                'INVALID_GUIDE_ID',
                'El identificador de la guía debe ser un entero positivo.',
                400
            );
        }

        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        try {
            $guide = $this->service()->updateGuide(
                $guideId,
                $request->getParsedBody(),
                $actor['id'],
                $actor['name']
            );

            return Response::json(
                ['guide' => $guide],
                200,
                'Guía de diagnóstico actualizada correctamente.'
            );
        } catch (TroubleshootingGuideValidationException $exception) {
            return $this->guideError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo actualizar la guía de diagnóstico.',
                500
            );
        }
    }

    /**
     * PATCH /api/coordinator/troubleshooting-guides/{id}/status
     *
     * Activación o baja lógica auditada (`RF-04.2`, Art. III.1–III.3).
     * Responde `200` con `{ guide }`.
     */
    public function updateStatus(Request $request): Response
    {
        $forbidden = $this->guardCoordinator($request);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $guideId = $this->positiveRouteId($request, 'id');
        if ($guideId === null) {
            return Response::error(
                'INVALID_GUIDE_ID',
                'El identificador de la guía debe ser un entero positivo.',
                400
            );
        }

        $body = $request->getParsedBody();
        $rawStatus = $body['is_active'] ?? null;
        $isActive = $rawStatus === null
            ? null
            : filter_var($rawStatus, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_bool($isActive)) {
            return Response::error(
                'INVALID_GUIDE_STATUS',
                'El cuerpo debe declarar is_active como booleano.',
                422,
                ['field' => 'is_active']
            );
        }

        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al coordinador autenticado.', 401);
        }

        try {
            $guide = $this->service()->setGuideActiveStatus(
                $guideId,
                $isActive,
                $actor['id'],
                $actor['name']
            );

            return Response::json(
                ['guide' => $guide],
                200,
                $isActive
                    ? 'Guía de diagnóstico reactivada correctamente.'
                    : 'Guía de diagnóstico desactivada correctamente.'
            );
        } catch (TroubleshootingGuideValidationException $exception) {
            return $this->guideError($exception);
        } catch (Throwable $exception) {
            $this->logInternalFailure($exception);

            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No se pudo cambiar la vigencia de la guía de diagnóstico.',
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Guardas y ayudas HTTP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Puerta de lectura operativa: técnico y coordinación, nunca la sede.
     */
    private function guardReader(Request $request): ?Response
    {
        $role = $this->role($request);

        if ($role === '') {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al usuario autenticado.', 401);
        }

        if (!in_array($role, [UserRole::TECHNICIAN->value, UserRole::COORDINATOR->value], true)) {
            return Response::error(
                'FORBIDDEN',
                'Acceso denegado: las guías de diagnóstico sólo están disponibles para Técnicos y Coordinación.',
                403,
                ['user_role' => $role]
            );
        }

        return null;
    }

    /**
     * Puerta administrativa: sólo Coordinación puede gobernar el catálogo.
     */
    private function guardCoordinator(Request $request): ?Response
    {
        $role = $this->role($request);

        if ($role === '') {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al usuario autenticado.', 401);
        }

        if ($role !== UserRole::COORDINATOR->value) {
            return Response::error(
                'FORBIDDEN',
                'Acceso denegado: la administración de guías de diagnóstico exige rol COORDINATOR.',
                403,
                ['required_role' => UserRole::COORDINATOR->value, 'user_role' => $role]
            );
        }

        return null;
    }

    private function role(Request $request): string
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return $user->getRole()->value;
        }

        return strtoupper(trim((string)$request->getAttribute('user_role', '')));
    }

    /**
     * Identidad del técnico para el cruce de furgoneta. Un coordinador consulta
     * el catálogo sin saldo propio: el stock es del titular de la ruta.
     */
    private function technicianId(Request $request): ?int
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return $user->getRole() === UserRole::TECHNICIAN ? $user->getId() : null;
        }

        $role = strtoupper(trim((string)$request->getAttribute('user_role', '')));
        $userId = $request->getAttribute('user_id');

        return $role === UserRole::TECHNICIAN->value && is_numeric($userId)
            ? (int)$userId
            : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actor(Request $request): ?array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            $userId = $user->getId();

            return $userId === null
                ? null
                : [
                    'id' => $userId,
                    'name' => trim($user->getName()) !== '' ? $user->getName() : 'Coordinación de Servicio',
                ];
        }

        $userId = $request->getAttribute('user_id');
        if (!is_numeric($userId) || (int)$userId <= 0) {
            return null;
        }

        $name = trim((string)$request->getAttribute('user_name', ''));

        return [
            'id' => (int)$userId,
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

    private function optionalQueryString(Request $request, string $key): ?string
    {
        $value = $request->getQuery($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function guideError(TroubleshootingGuideValidationException $exception): Response
    {
        $details = [];
        if ($exception->getField() !== null) {
            $details['field'] = $exception->getField();
        }
        if ($exception->getContext() !== []) {
            $details['context'] = $exception->getContext();
        }

        return Response::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getHttpStatusCode(),
            $details === [] ? null : $details
        );
    }

    private function service(): TroubleshootingGuideService
    {
        if ($this->guideService === null) {
            $pdo = $this->pdo ?? ConnectionFactory::getConnection();
            $this->pdo = $pdo;
            $this->guideService = new TroubleshootingGuideService(
                new PdoMachineTroubleshootingGuideRepository($pdo),
                new PdoRootCauseRepository($pdo),
                new PdoSparePartRepository($pdo),
                $pdo
            );
        }

        return $this->guideService;
    }

    private function logInternalFailure(Throwable $exception): void
    {
        error_log('[vendguard] ' . $exception);
    }
}
