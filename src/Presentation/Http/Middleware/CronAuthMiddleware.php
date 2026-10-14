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
 * **Predicado público y no fatal (T-HYBRID-14).** La comprobación de credencial se expone
 * como `hasBatchCredential(Request): bool` y la usan **las dos** cosas que la necesitan: el
 * rechazo de este middleware y la decisión de telemetría del diagnóstico técnico
 * (`GET /api/status`). Una sola implementación de la comparación del secreto, y por tanto
 * ninguna segunda cabecera ni ninguna segunda forma de equivocarse. Que sea público no
 * relaja nada: el rechazo sigue siendo fallo cerrado y la ruta de diagnóstico decide con él
 * sólo si **añade** detalle o no.
 *
 * **Resolución perezosa del validador de sesión.** El validador (`AuthService`) construye
 * repositorios PDO, de modo que instanciarlo abre conexión con el motor. Mientras este
 * middleware sólo protegía rutas batch eso daba igual; desde que el diagnóstico técnico lo
 * consulta, no: un endpoint cuya razón de existir es informar de que el motor no responde
 * **no puede** depender de que el motor responda para poder comprobarlo, y convertiría su
 * `503` en un `500`. Por eso el validador se resuelve en el primer uso, y sólo el camino que
 * lo necesita de verdad —un Bearer que no es el secreto del proceso, es decir, una presunta
 * sesión— lo paga.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */
class CronAuthMiddleware
{
    private ?AuthService $authService;
    private string $cronSecret;

    public function __construct(
        ?AuthService $authService = null,
        ?string $cronSecret = null
    ) {
        $this->authService = $authService;
        $this->cronSecret  = $cronSecret ?? SecretProvider::cronSecret();
    }

    /**
     * Procesa la petición y autoriza el proceso batch, marcando la petición para el controlador.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->hasBatchCredential($request)) {
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
     * ¿La petición presenta una credencial válida de proceso batch?
     *
     * Predicado **público y no fatal**: no rechaza nada por sí mismo ni responde, sólo dice si
     * la credencial está. El rechazo del middleware y la proyección de telemetría del
     * diagnóstico técnico lo comparten, de modo que la comparación del secreto vive en un
     * único sitio (T-HYBRID-14).
     */
    public function hasBatchCredential(Request $request): bool
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
        $verified = $this->authService()->validateInternalToken($bearerToken);

        return $verified !== null && ($verified['role'] ?? '') === UserRole::COORDINATOR->value;
    }

    /**
     * Validador de sesiones internas, resuelto en el primer uso.
     *
     * Se construye aquí y no en el constructor porque construirlo abre conexión con el motor
     * (ver el bloque de arriba): sólo el camino que presenta un Bearer que no es el secreto del
     * proceso necesita esta pieza.
     */
    private function authService(): AuthService
    {
        return $this->authService ??= new AuthService();
    }
}
