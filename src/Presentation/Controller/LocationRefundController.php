<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\LocationRefundViewDTO;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\InvalidPickupPinException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\PickupPinLockedException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * LocationRefundController
 *
 * Operativa de conserjería para el Responsable de Sede: qué sobres de efectivo
 * hay en el mostrador y a quién hay que devolvérselos (RF-REF-06, RF-REF-10).
 *
 * Endpoints gestionados:
 * - GET  /api/location/refunds               (listado anonimizado del centro)
 * - POST /api/location/refunds/{id}/deliver  (entrega presencial con PIN)
 *
 * ## Por qué el listado va anonimizado
 * El portal de conserjería es una pantalla compartida entre turnos y suele
 * estar a la vista de la cola. El IBAN, el teléfono de Bizum y el PIN de
 * recogida no salen nunca de aquí (Art. V.4): el conserje necesita reconocer a
 * "Laura S." y abrir un sobre, no saber dónde se le paga ni confirmar un
 * identificador que el propio usuario le va a decir en voz alta.
 *
 * ## Por qué la entrega exige un PIN
 * El sobre se libera contra un PIN de 4 dígitos que el usuario enseña en el
 * móvil y teclea el conserje. La comparación es en tiempo constante
 * (`hash_equals`) y un PIN erróneo deja el expediente exactamente como estaba,
 * para poder reintentarlo sin haber soltado nada por error (RF-REF-06).
 *
 * Ninguna operación borra nada: una entrega es un cambio de estado más un
 * apunte inmutable en la auditoría (Art. III).
 *
 * La segregación de sede replica la del resto del portal (`LocationPortalController`):
 * si la sede no se puede determinar se responde 401, y cualquier expediente que
 * no sea de esa sede se responde 403 sin revelar nada de su contenido.
 */
class LocationRefundController
{
    private RefundRequestRepositoryInterface $refundRepo;
    private LocationRepositoryInterface $locationRepo;
    private IncidentRepositoryInterface $incidentRepo;
    private MachineRepositoryInterface $machineRepo;
    private RefundManagementService $managementService;

    public function __construct(
        ?RefundRequestRepositoryInterface $refundRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?RefundManagementService $managementService = null
    ) {
        $this->refundRepo = $refundRepo ?? new PdoRefundRequestRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->managementService = $managementService ?? new RefundManagementService($this->refundRepo);
    }

    /**
     * GET /api/location/refunds
     *
     * Lista los expedientes de reintegro de la sede con el nombre anonimizado,
     * el importe y el estado, marcando cuáles están listos para entregar.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): Response
    {
        $location = $this->resolveLocation($request);
        if ($location instanceof Response) {
            return $location;
        }

        $cases = $this->refundRepo->findRestrictedByLocation($location->getId());

        // Los sobres que hay en el mostrador van primero: es lo primero que
        // necesita el conserje del turno, no lo último de una lista.
        usort($cases, static function (RefundRequest $left, RefundRequest $right): int {
            $awaitingLeft = $left->getStatus()->awaitsPickup() ? 0 : 1;
            $awaitingRight = $right->getStatus()->awaitsPickup() ? 0 : 1;

            return $awaitingLeft <=> $awaitingRight;
        });

        $views = array_map(
            fn (RefundRequest $case): LocationRefundViewDTO => LocationRefundViewDTO::fromRefundRequest(
                $case,
                $this->resolveIncidentCode($case),
                $this->resolveMachineCode($case)
            ),
            $cases
        );

        return Response::json([
            'total' => count($views),
            'ready_for_pickup_total' => count(array_filter(
                $views,
                static fn (LocationRefundViewDTO $view): bool => $view->readyForPickup
            )),
            'refunds' => array_map(
                static fn (LocationRefundViewDTO $view): array => $view->toArray(),
                $views
            ),
        ], 200);
    }

    /**
     * POST /api/location/refunds/{id}/deliver
     *
     * Libera el efectivo en mano contra el PIN de 4 dígitos que el usuario
     * facilita presencialmente (RF-REF-06).
     *
     * @return array<string, mixed>
     */
    public function deliver(Request $request): Response
    {
        // 1. Sede autenticada: sin ella no se puede ni leer ni soltar nada.
        $location = $this->resolveLocation($request);
        if ($location instanceof Response) {
            return $location;
        }

        // 2. Expediente de la ruta
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId)) {
            return Response::error('INVALID_REFUND_ID', 'El ID de expediente de reintegro no es válido.', 400);
        }
        $refundId = (int)$rawId;

        // 3. El expediente tiene que existir. Se responde 404 sin distinguir
        //    entre "no existe" y "es de otra sede" sólo cuando el técnico ya lo
        //    ha visto; aquí se comprueba primero la pertenencia, que es lo que
        //    protege la segregación.
        $case = $this->refundRepo->findById($refundId);
        if ($case === null || !$case->isActive()) {
            return Response::error(
                RefundNotFoundException::ERROR_CODE,
                RefundNotFoundException::DEFAULT_MESSAGE,
                RefundNotFoundException::HTTP_STATUS
            );
        }

        if ($case->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para gestionar reintegros de otra sede.',
                403
            );
        }

        // 4. PIN obligatorio. Se valida el formato antes de tocar nada para que
        //    un campo vacío no se confunda con un PIN incorrecto.
        $rawPin = trim((string)($request->getBodyParam('pickup_pin') ?? ''));
        if ($rawPin === '') {
            return Response::error(
                'MISSING_PICKUP_PIN',
                'Introduzca el PIN de 4 dígitos que muestra el usuario para retirar su efectivo.',
                422
            );
        }

        if (preg_match('/^[0-9]{4}$/', $rawPin) !== 1) {
            return Response::error(
                InvalidPickupPinException::ERROR_CODE,
                InvalidPickupPinException::DEFAULT_MESSAGE,
                InvalidPickupPinException::HTTP_STATUS
            );
        }

        // 5. Entrega. El servicio compara en tiempo constante y deja el
        //    expediente intacto si el PIN no coincide, de modo que el usuario
        //    pueda volver a intentarlo sin que se haya soltado nada.
        try {
            $delivered = $this->managementService->deliverInHand(
                $refundId,
                $rawPin,
                $this->buildActor($request)
            );
        } catch (PickupPinLockedException $e) {
            // 423 y no 429: el motivo del rechazo lo conoce quien lo recibe (los
            // intentos se agotaron) y se le indica cuándo reintentar. Los details
            // llevan `locked_until` para que la interfaz pueda mostrarlo, y en
            // ningún caso el PIN introducido.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (InvalidPickupPinException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        return Response::json([
            'id' => (int)$delivered->getId(),
            'status' => $delivered->getStatus()->value,
            'claimed_amount' => $delivered->getClaimedAmount(),
            'claimant_name_anon' => $delivered->getAnonymizedClaimantName(),
        ], 200, 'PIN verificado con éxito. Entrega presencial registrada correctamente.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resuelve la sede del conserje autenticado.
     *
     * Sigue la misma cadena que el portal de sede (`LocationPortalController`):
     * entidad ya resuelta por el middleware, identificador de sede o cabecera
     * `X-Site-Code`. Sin sede no hay ni lectura ni entrega posible.
     *
     * @return Location|Response
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

        $siteCode = $request->getHeader('X-Site-Code') ?? $request->getAttribute('site_code');
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

    /**
     * Metadatos del conserje para la auditoría inmutable de la entrega.
     *
     * @return array{id: int|null, role: string, name: string}
     */
    private function buildActor(Request $request): array
    {
        return [
            'id' => is_numeric($request->getAttribute('user_id')) ? (int)$request->getAttribute('user_id') : null,
            'role' => (string)($request->getAttribute('user_role') ?? 'LOCATION_MANAGER'),
            'name' => 'Conserjería',
        ];
    }

    private function resolveIncidentCode(RefundRequest $case): ?string
    {
        $incident = $this->incidentRepo->findById($case->getIncidentId());

        return $incident?->getTicketCode();
    }

    private function resolveMachineCode(RefundRequest $case): ?string
    {
        return $this->machineRepo->findById($case->getMachineId(), false)?->getCode();
    }
}
