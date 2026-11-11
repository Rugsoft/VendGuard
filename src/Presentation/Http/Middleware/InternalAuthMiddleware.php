<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http\Middleware;

use Closure;
use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * InternalAuthMiddleware
 * 
 * Middleware de seguridad y control de acceso basado en roles (RBAC) para el personal
 * interno de VendGuard (Coordinadores y Técnicos de Ruta, RF-04).
 * 
 * Verifica la cabecera Authorization: Bearer auth_token_... y opcionalmente comprueba
 * que el rol del usuario coincida con el perfil exigido por el endpoint.
 * 
 * Respuestas:
 * - 401 Unauthorized si el token falta, es inválido o está caducado.
 * - 403 Forbidden si el rol del usuario no tiene permisos suficientes.
 */
class InternalAuthMiddleware
{
    /** @var list<UserRole> Roles admitidos; lista vacía = cualquier personal interno. */
    private array $allowedRoles;
    private AuthService $authService;
    private UserRepositoryInterface $userRepo;

    /**
     * @param UserRole|string|array<int, UserRole|string>|null $requiredRole Rol o roles que superan el middleware.
     * @param AuthService|null $authService
     * @param UserRepositoryInterface|null $userRepo
     * @param bool $allowInactive Permite usuarios inactivos (p. ej. volcado por baja de cuenta QA 5).
     */
    public function __construct(
        UserRole|string|array|null $requiredRole = null,
        ?AuthService $authService = null,
        ?UserRepositoryInterface $userRepo = null,
        private readonly bool $allowInactive = false
    ) {
        $candidates = is_array($requiredRole) ? $requiredRole : [$requiredRole];
        $this->allowedRoles = [];

        foreach ($candidates as $candidate) {
            $role = $candidate instanceof UserRole
                ? $candidate
                : (is_string($candidate) && trim($candidate) !== '' ? UserRole::fromString($candidate) : null);

            if ($role !== null && !in_array($role, $this->allowedRoles, true)) {
                $this->allowedRoles[] = $role;
            }
        }

        $this->authService = $authService ?? new AuthService();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
    }

    /**
     * Procesa la petición y valida las credenciales y rol del personal interno.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->getBearerToken();

        // 1. Verificar presencia de cabecera Bearer
        if ($bearerToken === null || trim($bearerToken) === '') {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. Se requiere cabecera Authorization con token Bearer válido.',
                401
            );
        }

        // 2. Validar firma y caducidad del token
        $payload = $this->authService->validateInternalToken($bearerToken);
        if ($payload === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Token de sesión inválido, alterado o caducado.',
                401
            );
        }

        // 3. Verificar que el usuario sigue existiendo en base de datos (y activo si no se permite inactivo)
        $user = $this->userRepo->findById((int)$payload['user_id'], onlyActive: !$this->allowInactive);
        if ($user === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Usuario del token no encontrado o dado de baja.',
                401
            );
        }

        // 4. Verificación de permisos según Rol (RBAC)
        if ($this->allowedRoles !== [] && !in_array($user->getRole(), $this->allowedRoles, true)) {
            $requiredLabels = array_map(
                static fn (UserRole $role): string => $role->value,
                $this->allowedRoles
            );

            // Una sola puerta conserva el código histórico `FORBIDDEN`; las rutas
            // compartidas por varios roles publican el `FORBIDDEN_ROLE` del Módulo 31.
            return Response::error(
                count($this->allowedRoles) === 1 ? 'FORBIDDEN' : 'FORBIDDEN_ROLE',
                'Acceso denegado: se requiere rol ' . implode(' o ', $requiredLabels) . ' para acceder a este recurso.',
                403,
                [
                    'required_role' => implode('|', $requiredLabels),
                    'user_role' => $user->getRole()->value,
                ]
            );
        }

        // 5. Inyectar contexto de usuario autenticado en la petición
        $request->setAttribute('authenticated_user', $user);
        $request->setAttribute('user_id', $user->getId());
        $request->setAttribute('user_role', $user->getRole()->value);
        $request->setAttribute('user_email', $user->getEmail());

        return $next($request);
    }
}
