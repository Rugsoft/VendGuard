<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Throwable;
use VendGuard\Application\Service\SanitarySweepVisibilityService;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorSanitarySweepController — Superficie operativa de la contención del
 * barrido sanitario para la Coordinación (T-PAUSE-41, RF-07, Art. II y Art. III).
 *
 * Expone **una sola lectura**: la fotografía del libro de pasadas y de los
 * seguimientos de contención abiertos, con la alerta de escalado ya resuelta por
 * la política de dominio. No hay endpoint de escritura: cerrar un seguimiento es
 * competencia del barrido —la primera pasada que ya no aplaza al expediente lo
 * cierra— y el acuse explícito del coordinador queda fuera del alcance de este
 * incremento (declarado en `plan.md` §2.6).
 *
 * **Por qué la ruta va protegida por el middleware y además se revalida aquí.**
 * El payload identifica máquinas, sedes y expedientes frente a un escritor que
 * retiene filas: es instrumentación interna y no se publica en el portal de sede
 * ni en el escaneo ciudadano (Art. V.4). El middleware ya exige rol
 * `COORDINATOR`; esta comprobación es la misma defensa en profundidad que usan
 * el resto de controladores del panel, para que una ruta mal registrada no abra
 * la superficie por descuido.
 *
 * Dogma Vanilla: PHP 8.2+ puro, respuesta JSON, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorSanitarySweepController
{
    private ?SanitarySweepVisibilityService $visibilityService;

    public function __construct(?SanitarySweepVisibilityService $visibilityService = null)
    {
        $this->visibilityService = $visibilityService;
    }

    /**
     * GET /api/coordinator/sanitary-sweep/contention
     *
     * Fotografía de la contención del barrido: latido (última pasada), tendencia
     * de las últimas 24 h, seguimientos abiertos y nivel de escalado.
     */
    public function getContention(Request $request): Response
    {
        if (($authorizationError = $this->authorizeCoordinator($request)) !== null) {
            return $authorizationError;
        }

        try {
            return Response::json($this->visibility()->snapshot()->toArray());
        } catch (Throwable) {
            return Response::error(
                'SWEEP_VISIBILITY_UNAVAILABLE',
                'No se pudo leer el libro de pasadas del barrido sanitario. La salvaguarda sanitaria no está afectada: el barrido sigue evaluando candidatos y activando cuarentenas.',
                500
            );
        }
    }

    /**
     * Defensa en profundidad: sin sesión de coordinación no se publica la
     * superficie (Art. V.4).
     */
    private function authorizeCoordinator(Request $request): ?Response
    {
        $userId = $request->getAttribute('user_id');
        if ($userId === null || !is_numeric($userId) || (int)$userId < 1) {
            return Response::error('UNAUTHORIZED', 'Token de autenticación ausente o inválido.', 401);
        }

        if ((string)$request->getAttribute('user_role') !== 'COORDINATOR') {
            return Response::error(
                'FORBIDDEN',
                'No dispone de permisos para consultar la instrumentación del barrido sanitario.',
                403
            );
        }

        return null;
    }

    /**
     * Servicio de la superficie, resuelto de forma perezosa (el controlador es
     * construible sin base de datos, como el resto del panel).
     */
    private function visibility(): SanitarySweepVisibilityService
    {
        return $this->visibilityService ??= new SanitarySweepVisibilityService();
    }
}
