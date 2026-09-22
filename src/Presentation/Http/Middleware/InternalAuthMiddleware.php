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
    private ?UserRole $requiredRole;
    private AuthService $authService;
    private UserRepositoryInterface $userRepo;

    /**
     * @param UserRole|string|null $requiredRole Rol obligatorio para superar el middleware.
     * @param AuthService|null $authService
     * @param UserRepositoryInterface|null $userRepo
     */
    public function __construct(
        UserRole|string|null $requiredRole = null,
        ?AuthService $authService = null,
        ?UserRepositoryInterface $userRepo = null
    ) {
        if (is_string($requiredRole)) {
            $this->requiredRole = UserRole::fromString($requiredRole);
        } else {
            $this->requiredRole = $requiredRole;
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

        // 3. Verificar que el usuario sigue existiendo y está activo en base de datos
        $user = $this->userRepo->findById((int)$payload['user_id']);
        if ($user === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Usuario del token no encontrado o dado de baja.',
                401
            );
        }

        // 4. Verificación de permisos según Rol (RBAC)
        if ($this->requiredRole !== null && $user->getRole() !== $this->requiredRole) {
            return Response::error(
                'FORBIDDEN',
                "Acceso denegado: se requiere rol {$this->requiredRole->value} para acceder a este recurso.",
                403,
                [
                    'required_role' => $this->requiredRole->value,
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
