<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http\Middleware;

use Closure;
use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * SiteAuthMiddleware
 * 
 * Middleware que intercepta y protege las rutas del Portal de Ubicación / Sede (RF-01).
 * Valida la presencia y autenticidad del token de sede (Bearer site_token_...) o
 * de la cabecera identificativa X-Site-Code.
 * 
 * Si no se aporta autenticación válida, rechaza con HTTP 401 Unauthorized.
 */
class SiteAuthMiddleware
{
    private AuthService $authService;
    private LocationRepositoryInterface $locationRepo;

    public function __construct(
        ?AuthService $authService = null,
        ?LocationRepositoryInterface $locationRepo = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
    }

    /**
     * Procesa la petición y valida la autenticación de sede.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $siteCodeHeader = $request->getHeader('X-Site-Code');
        $bearerToken = $request->getBearerToken();

        // 1. Si no hay ni token Bearer ni cabecera X-Site-Code -> 401 Unauthorized
        if ($bearerToken === null && ($siteCodeHeader === null || trim($siteCodeHeader) === '')) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. Se requiere un token de sesión o cabecera X-Site-Code válida.',
                401
            );
        }

        $location = null;

        // 2. Si se proporciona token Bearer, verificar firma y expiración
        if ($bearerToken !== null) {
            $payload = $this->authService->validateSiteToken($bearerToken);
            if ($payload === null) {
                return Response::error(
                    'UNAUTHORIZED',
                    'El token de sede proporcionado es inválido o ha caducado.',
                    401
                );
            }

            $location = $this->locationRepo->findById((int)$payload['location_id']);
        }

        // 3. Si se proporciona cabecera X-Site-Code y aún no se validó por token
        if ($location === null && $siteCodeHeader !== null) {
            $location = $this->locationRepo->findBySiteCode(trim($siteCodeHeader), true);
        }

        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Código o sede no encontrada o dada de baja.',
                401
            );
        }

        // Inyectar la sede autenticada en los atributos de la petición para los controladores
        $request->setAttribute('authenticated_location', $location);
        $request->setAttribute('site_code', $location->getSiteCode());
        $request->setAttribute('location_id', $location->getId());

        return $next($request);
    }
}
