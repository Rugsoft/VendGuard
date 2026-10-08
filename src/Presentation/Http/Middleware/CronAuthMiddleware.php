<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http\Middleware;

use Closure;
use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CronAuthMiddleware
 *
 * Middleware de autenticación de procesos batch y tareas programadas
 * (RF-10 / EARS 10.1, 10.2; hallazgo S-2 de la auditoría de arquitectura).
 *
 * Antes de este middleware la validación vivía dentro de `CronController`, de modo que
 * `POST /api/cron/auto-close` era la única ruta de negocio registrada sin middleware: un
 * proceso batch colgaba de una ruta "desnuda" y cualquier ruta nueva de automatización
 * repetía el patrón. Ahora la credencial se resuelve aquí, en el mismo punto que las otras
 * 75 rutas protegidas, y el controlador queda fail-closed como segunda barrera.
 *
 * Credenciales aceptadas, en este orden:
 * 1. Cabecera `X-Cron-Secret: <CRON_SECRET>`.
 * 2. `Authorization: Bearer <CRON_SECRET>`.
 * 3. `Authorization: Bearer <token de sesión de COORDINATOR>` (permite lanzar el cierre
 *    desde el panel sin repartir el secreto del proceso).
 *
 * Respuesta: `401 UNAUTHORIZED` si falta la credencial o no es válida. Nunca `403`: para el
 * proceso no existe un usuario autenticado al que negarle un recurso, solo una credencial
 * de máquina que se presenta o no.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
class CronAuthMiddleware
{
    private AuthService $authService;
    private string $cronSecret;

    public function __construct(
        ?AuthService $authService = null,
        ?string $cronSecret = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->cronSecret  = $cronSecret ?? SecretProvider::cronSecret();
    }

    /**
     * Procesa la petición y autoriza el proceso batch, marcando la petición para el controlador.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isAuthorized($request)) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado al proceso cron. Se requiere cabecera X-Cron-Secret o token válido.',
                401
            );
        }

        // Marca de autorización verificada: el controlador es fail-closed y exige esta marca.
        $request->setAttribute('cron_authenticated', true);

        return $next($request);
    }

    /**
     * Comprueba si la petición presenta una credencial válida de proceso cron.
     */
    private function isAuthorized(Request $request): bool
    {
        // A. Cabecera directa X-Cron-Secret
        $headerSecret = $request->getHeader('X-Cron-Secret');
        if ($headerSecret !== null && hash_equals($this->cronSecret, $headerSecret)) {
            return true;
        }

        $bearerToken = $request->getBearerToken();
        if ($bearerToken === null) {
            return false;
        }

        // B. Bearer token igual al secreto cron
        if (hash_equals($this->cronSecret, $bearerToken)) {
            return true;
        }

        // C. Bearer token válido de un usuario con rol COORDINATOR
        $verified = $this->authService->validateInternalToken($bearerToken);

        return $verified !== null && ($verified['role'] ?? '') === UserRole::COORDINATOR->value;
    }
}
