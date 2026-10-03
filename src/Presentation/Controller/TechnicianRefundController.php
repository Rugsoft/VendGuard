<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\TechnicianRefundViewDTO;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianRefundController
 *
 * Endpoint de campo para el Técnico de Ruta: qué reclamaciones de dinero
 * retenido cuelgan de la avería que va a intervenir, y dónde debe acabar
 * físicamente el efectivo si aparece (RF-REF-04, RF-REF-05).
 *
 * Endpoint gestionado:
 * - GET /api/technician/incidents/{id}/refund
 *
 * ## Por qué el técnico NO ve los datos bancarios
 * El técnico abre esto en una pantalla móvil, en una sede ajena, y el IBAN, el
 * teléfono de Bizum y el nombre del reclamante no le sirven para nada: sólo
 * exponen a un tercero. Lo decide `TechnicianRefundViewDTO`, donde esos campos
 * ni siquiera existen (Art. V.4).
 *
 * Lo que sí necesita es la instrucción de custodia, porque de ella depende que
 * un sobre con efectivo se quede en un cajón de una conserjería o viaje a caja
 * central. Es información operativa, no financiera.
 *
 * Ninguna operación borra nada; este endpoint es de sólo lectura (Art. III).
 */
class TechnicianRefundController
{
    private IncidentRepositoryInterface $incidentRepo;
    private RefundRequestRepositoryInterface $refundRepo;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?RefundRequestRepositoryInterface $refundRepo = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->refundRepo = $refundRepo ?? new PdoRefundRequestRepository();
    }

    /**
     * GET /api/technician/incidents/{id}/refund
     *
     * Lista las solicitudes de reintegro asociadas a la avería, con el importe
     * reclamado, el producto intentado y la instrucción de custodia.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): Response
    {
        // 1. Incidencia de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_INCIDENT_ID', 'El ID de incidencia de la ruta no es válido.', 400);
        }
        $incidentId = (int)$rawId;

        // 2. Técnico autenticado
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }
        $techId = (int)$technicianId;

        // 3. La avería tiene que existir y estar en su ruta: el detalle económico
        // de una incidencia ajena no se sirve ni al usuario que pregunta.
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return Response::error('INCIDENT_NOT_FOUND', "No se encontró ninguna incidencia con ID {$incidentId}.", 404);
        }

        if ($incident->getAssignedTechnicianId() !== $techId) {
            return Response::error('FORBIDDEN', 'Esta incidencia no está asignada a tu ruta técnica.', 403);
        }

        // 4. Proyección segura: el repositorio restringido ya omite `iban` y
        //    `bizum_phone` en la consulta SQL, y el DTO tampoco puede emitirlos.
        $views = array_map(
            static fn ($case): TechnicianRefundViewDTO => TechnicianRefundViewDTO::fromRefundRequest($case),
            $this->refundRepo->findRestrictedByIncident($incidentId)
        );

        return Response::json([
            'has_refund_requests' => $views !== [],
            'total_requests' => count($views),
            'total_claimed_amount' => array_sum(array_map(
                static fn (TechnicianRefundViewDTO $view): float => $view->claimedAmount,
                $views
            )),
            // `has_pending_verdict` es lo que la vista móvil usa para bloquear el
            // botón de resolver: sin dictamen, la avería no se cierra (RF-REF-04).
            'has_pending_verdict' => $views !== [] && self::anyAwaitsInspection($views),
            'available_findings' => TechnicianRefundViewDTO::availableFindings(),
            'requests' => array_map(
                static fn (TechnicianRefundViewDTO $view): array => $view->toArray(),
                $views
            ),
        ], 200);
    }

    /**
     * @param list<TechnicianRefundViewDTO> $views
     */
    private static function anyAwaitsInspection(array $views): bool
    {
        foreach ($views as $view) {
            if ($view->blocksResolution()) {
                return true;
            }
        }

        return false;
    }
}
