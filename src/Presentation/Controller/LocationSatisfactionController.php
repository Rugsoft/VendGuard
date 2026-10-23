<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\SatisfactionSurveyService;
use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * LocationSatisfactionController
 *
 * Superficie de sede del Módulo 20: qué servicios resueltos quedan por valorar y
 * el envío de la valoración.
 *
 * Endpoints gestionados:
 * - GET  /api/location/satisfaction-surveys/pending            (episodios sin valorar)
 * - POST /api/location/incidents/{id}/satisfaction-survey      (envío de la valoración)
 *
 * ## Por qué la sede sale de la sesión y no del cuerpo
 * Un código de sede en la petición convertiría un `` en una forma de
 * enumerar y valorar los servicios de otro centro. La sede se resuelve siempre
 * desde el token (`authenticated_location`) y el servicio recibe ya su id: la
 * petición no tiene forma de declarar otra (RF-01.2, RD-03).
 *
 * ## Por qué este controlador no decide nada de negocio
 * La precedencia de errores, el ancla del episodio y la auditoría viven en
 * `SatisfactionSurveyService`. Aquí solo se traduce la excepción de dominio a su
 * HTTP y su código, y se proyecta el DTO. Ese reparto es lo que permite probar
 * la regla sin arrancar el enrutador.
 *
 * ## Por qué la traducción de errores es explícita
 * `SatisfactionSurveyValidationException` extiende `DomainException`, y el
 * enrutador traduce esa familia a un `400 DOMAIN_ERROR` genérico. Sin este
 * `catch` explícito, un `404`, un `403` o un `409` llegarían al cliente como
 * `400`, y la sede no podría distinguir «esa avería no es tuya» de «ya la
 * valoraste».
 *
 * ## Por qué no hay `technician_id` en ninguna respuesta
 * La respuesta la lee la sede. El técnico, su nombre y los datos económicos no
 * cruzan esta frontera (Art. V.4, R2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class LocationSatisfactionController
{
    private SatisfactionSurveyService $service;
    private LocationRepositoryInterface $locationRepo;

    public function __construct(
        ?SatisfactionSurveyService $service = null,
        ?LocationRepositoryInterface $locationRepo = null
    ) {
        $this->service = $service ?? new SatisfactionSurveyService();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
    }

    /**
     * GET /api/location/satisfaction-surveys/pending
     *
     * Los 20 episodios más recientes y el total real de pendientes (R3).
     */
    public function pending(Request $request): Response
    {
        $location = $this->resolveLocation($request);
        if ($location instanceof Response) {
            return $location;
        }

        return Response::json($this->service->pendingFor($location->getId()), 200);
    }

    /**
     * POST /api/location/incidents/{id}/satisfaction-survey
     *
     * Registra la valoración del episodio de resolución vigente (contratos §4.2).
     */
    public function submit(Request $request): Response
    {
        $location = $this->resolveLocation($request);
        if ($location instanceof Response) {
            return $location;
        }

        $rawId = $request->getRouteParam('id');

        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error(
                SatisfactionSurveyValidationException::INCIDENT_NOT_FOUND_CODE,
                'La avería solicitada no existe.',
                404
            );
        }

        try {
            $survey = $this->service->submit(
                (int)$rawId,
                $location->getId(),
                $location->getName(),
                $request->getParsedBody()
            );
        } catch (SatisfactionSurveyValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getFieldErrors() ?: null
            );
        }

        return Response::json($survey->toArray(), 201);
    }

    /**
     * Resuelve la sede autenticada, replicando la cadena del resto del portal.
     *
     * `SiteAuthMiddleware` deja la entidad ya resuelta en `authenticated_location`;
     * las dos alternativas existen para las baterías que despachan por el router
     * con un token y las que montan el controlador a mano. Sin sede no hay
     * respuesta posible: `401` antes de tocar datos (RF-01.6).
     */
    private function resolveLocation(Request $request): Location|Response
    {
        $location = $request->getAttribute('authenticated_location');
        if ($location instanceof Location) {
            return $location;
        }

        $locationId = $request->getAttribute('location_id');
        if ($locationId !== null && is_numeric($locationId)) {
            $location = $this->locationRepo->findById((int)$locationId);
            if ($location !== null) {
                return $location;
            }
        }

        $siteCode = $request->getAttribute('site_code');
        if ($siteCode !== null && trim((string)$siteCode) !== '') {
            $location = $this->locationRepo->findBySiteCode(trim((string)$siteCode));
            if ($location !== null) {
                return $location;
            }
        }

        return Response::error(
            'UNAUTHORIZED',
            'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
            401
        );
    }
}
