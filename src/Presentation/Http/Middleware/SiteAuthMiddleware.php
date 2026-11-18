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
 *
 * La única puerta de sede es el token emitido por `POST /api/auth/site-login`, que ya
 * exige el código de sede y la clave de centro (hallazgo S-4): el código de sede por
 * sí solo —en cualquier cabecera— ya no identifica a nadie. Si no se aporta un token
 * de sede válido, rechaza con HTTP 401 Unauthorized.
 *
 * **Resolución perezosa de sus colaboradores.** `AuthService` y `PdoLocationRepository`
 * construyen repositorios PDO, es decir, abren conexión con el motor. Este middleware lo
 * instancia `AppRouter::create()` al montar la tabla de rutas, de modo que resolverlos en
 * el constructor hacía que **el arranque del enrutador** dependiera de que el motor
 * respondiera: con la base caída, ninguna ruta llegaba a existir —ni siquiera las sondas
 * de salud— y el proceso emitía un fatal en HTML en vez de un `503`. Se resuelven en el
 * primer uso, y sólo el camino que de verdad los necesita los paga. Mismo criterio y
 * mismo motivo que en `CronAuthMiddleware`.
 */
class SiteAuthMiddleware
{
    private ?AuthService $authService;
    private ?LocationRepositoryInterface $locationRepo;

    public function __construct(
        ?AuthService $authService = null,
        ?LocationRepositoryInterface $locationRepo = null
    ) {
        $this->authService = $authService;
        $this->locationRepo = $locationRepo;
    }

    /**
     * Procesa la petición y valida la autenticación de sede.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->getBearerToken();

        // 1. Sin token de sede no hay puerta: 401 Unauthorized
        if ($bearerToken === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. Inicie sesión en el centro con su código de sede y clave de centro.',
                401
            );
        }

        // 2. Verificar firma y expiración del token de sede
        $payload = $this->authService()->validateSiteToken($bearerToken);
        if ($payload === null) {
            return Response::error(
                'UNAUTHORIZED',
                'El token de sede proporcionado es inválido o ha caducado.',
                401
            );
        }

        $location = $this->locationRepo()->findById((int)$payload['location_id']);

        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'La sede de la sesión no existe o está dada de baja.',
                401
            );
        }

        // Inyectar la sede autenticada en los atributos de la petición para los controladores
        $request->setAttribute('authenticated_location', $location);
        $request->setAttribute('site_code', $location->getSiteCode());
        $request->setAttribute('location_id', $location->getId());

        return $next($request);
    }

    /**
     * Validador de sesiones de sede, resuelto en el primer uso.
     *
     * No se construye en el constructor porque construirlo abre conexión con el motor (ver
     * el bloque de la clase): el arranque del enrutador no puede depender del motor.
     */
    private function authService(): AuthService
    {
        return $this->authService ??= new AuthService();
    }

    /**
     * Repositorio de sedes, resuelto en el primer uso y por el mismo motivo.
     */
    private function locationRepo(): LocationRepositoryInterface
    {
        return $this->locationRepo ??= new PdoLocationRepository();
    }
}
