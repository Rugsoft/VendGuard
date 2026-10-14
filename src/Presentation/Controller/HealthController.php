<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\HealthCheckResponseDto;
use VendGuard\Application\Service\HealthCheckService;
use VendGuard\Presentation\Http\Middleware\CronAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * HealthController — Endpoint técnico de diagnóstico del enlace con el motor de
 * persistencia (`GET /api/status`, RF-07 y EARS-07.1 a EARS-07.5; tarea T-HYBRID-14).
 *
 * **Por qué una ruta nueva y no la existente.** `GET /api/health` es una sonda viva: cinco
 * suites la usan para esperar a que el servidor arranque y un diagnóstico fija su envoltura
 * JSON. Su carga útil no toca la base de datos y su contrato es el de la aplicación, no el
 * del motor. El diagnóstico que exige RF-07 (verificar el enlace consultando una tabla real)
 * se sirve por tanto en una ruta **nueva**, y la vieja queda intacta palabra por palabra
 * (EARS-07.5). Este controlador no registra ni reescribe aquélla: sólo atiende `/api/status`.
 *
 * **Qué decide este controlador, y qué no.** Toda la sustancia del diagnóstico —la consulta
 * sobre `machines`, la latencia cronometrada, el latido del barrido y el conjunto cerrado de
 * motivos— vive en `HealthCheckService` (T-HYBRID-13). Aquí sólo quedan dos decisiones de
 * presentación, y ninguna reimplementa una regla:
 *
 * 1. **Proyección: pública o detallada.** Se decide con el predicado público y no fatal
 *    [`CronAuthMiddleware::hasBatchCredential()`](../Http/Middleware/CronAuthMiddleware.php),
 *    el mismo que usa el rechazo del proceso batch: una sola implementación de la
 *    comparación del secreto y **ninguna cabecera nueva** (EARS-07.3; la decisión ratificada
 *    descartó crear una cabecera interna propia para este endpoint). La ausencia de credencial
 *    **no rechaza nada**: reduce la respuesta a su proyección mínima y el diagnóstico sigue
 *    prestándose. Y el orden importa: primero se comprueba la credencial y luego se consulta,
 *    de modo que quien publica telemetría ya está autorizado antes de que exista nada que
 *    publicar.
 * 2. **Código HTTP.** El diagnóstico declara si el enlace está disponible
 *    (`HealthCheckResponseDto::isAvailable()`): si no lo está se responde `503` con la
 *    envoltura de error y un detalle que nombra el estado y el instante, nunca el volcado de
 *    la excepción (EARS-07.4, Art. V.4). El latido desatendido necesita distinguir «el motor
 *    no responde» de «el servicio no contesta», y eso es exactamente un `503`.
 *
 * **Detalles de la proyección detallada.** El motivo de degradación (`failure_reason`) sólo
 * viaja cuando hay algo que explicar: en el caso sano se omite en lugar de anunciar un
 * problema inexistente con un `null`, de modo que la respuesta autenticada sana coincide
 * exactamente con el contrato documentado del módulo (plan §2.1.B). En el `503` autenticado
 * el motivo sí acompaña al detalle: es la única superficie con la que la operación
 * distingue «motor inalcanzable» de «verificación no completada» sin abrir el servidor.
 *
 * **Cabeceras.** `Content-Type` lo fija la envoltura JSON del proyecto; el controlador añade
 * `Cache-Control: no-cache, no-store, must-revalidate` porque un diagnóstico cacheado es un
 * diagnóstico que miente (el vigilante externo decide con él si una ventana se perdió).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
class HealthController
{
    /** Código de error del `503`: el motor de persistencia no responde (plan §2.1.C). */
    public const ERROR_DATABASE_UNAVAILABLE = 'DATABASE_UNAVAILABLE';

    /** Mensaje público del `503`: informa del servicio degradado, no de su interior. */
    public const UNAVAILABLE_MESSAGE = 'Servicio de persistencia de datos temporalmente no disponible.';

    /** Un diagnóstico no se cachea: el vigilante externo decide con su valor más reciente. */
    private const NO_STORE = 'no-cache, no-store, must-revalidate';

    /** Servicio del diagnóstico; se resuelve en el primer uso si no se inyecta. */
    private ?HealthCheckService $healthCheckService;

    /** Predicado de credencial batch; se resuelve en el primer uso si no se inyecta. */
    private ?CronAuthMiddleware $credentialGate;

    /**
     * @param HealthCheckService|null $healthCheckService Diagnóstico del enlace, o `null` para el de producción.
     * @param CronAuthMiddleware|null $credentialGate Puerta de credencial, o `null` para la de producción.
     */
    public function __construct(
        ?HealthCheckService $healthCheckService = null,
        ?CronAuthMiddleware $credentialGate = null
    ) {
        $this->healthCheckService = $healthCheckService;
        $this->credentialGate = $credentialGate;
    }

    /**
     * Atiende `GET /api/status`: verifica el enlace y publica el estado del servicio.
     *
     * Nunca lanza por falta de credencial ni por credencial inválida: eso sólo reduce la
     * respuesta. Sólo la indisponibilidad del motor cambia el código HTTP.
     */
    public function check(Request $request): Response
    {
        $includeDetails = $this->credentialGate()->hasBatchCredential($request);
        $diagnosis = $this->healthCheckService()->check($includeDetails);
        $headers = ['Cache-Control' => self::NO_STORE];

        if (!$diagnosis->isAvailable()) {
            return Response::error(
                self::ERROR_DATABASE_UNAVAILABLE,
                self::UNAVAILABLE_MESSAGE,
                503,
                $this->unavailableDetails($diagnosis, $includeDetails),
                $headers
            );
        }

        return Response::json($this->publicProjection($diagnosis, $includeDetails), 200, null, $headers);
    }

    /**
     * Proyección del diagnóstico para el código HTTP `200`.
     *
     * La proyección pública sale tal cual del DTO —`status` y `timestamp_utc`, nada más—,
     * que es la regla que impide publicar arquitectura por descuido (EARS-07.2). La
     * detallada sale de la serialización canónica del DTO con una sola corrección de
     * presentación: un motivo ausente no se escribe.
     *
     * @return array<string, mixed>
     */
    private function publicProjection(HealthCheckResponseDto $diagnosis, bool $includeDetails): array
    {
        $payload = $diagnosis->toArray($includeDetails);

        if ($includeDetails && ($payload['failure_reason'] ?? null) === null) {
            unset($payload['failure_reason']);
        }

        return $payload;
    }

    /**
     * Detalle del `503`: qué se sabe, sin abrir el servidor.
     *
     * Estado e instante viajan siempre —es el contrato documentado del `503`—, y el motivo de
     * conjunto cerrado acompaña sólo a quien presentó credencial: es diagnóstico de operación,
     * no material para la vía pública.
     *
     * @return array<string, mixed>
     */
    private function unavailableDetails(HealthCheckResponseDto $diagnosis, bool $includeDetails): array
    {
        $details = [
            'status' => $diagnosis->status,
            'timestamp_utc' => $diagnosis->timestampUtc,
        ];

        if ($includeDetails) {
            $details['failure_reason'] = $diagnosis->failureReason;
        }

        return $details;
    }

    private function healthCheckService(): HealthCheckService
    {
        return $this->healthCheckService ??= new HealthCheckService();
    }

    private function credentialGate(): CronAuthMiddleware
    {
        return $this->credentialGate ??= new CronAuthMiddleware();
    }
}
