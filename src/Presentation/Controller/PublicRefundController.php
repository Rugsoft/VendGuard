<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Throwable;
use VendGuard\Application\DTO\PublicRefundTrackingDTO;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * PublicRefundController
 *
 * Endpoints públicos de seguimiento del expediente de reintegro, sin
 * autenticación: la única credencial es el token de 64 caracteres que viaja en
 * la URL (RF-REF-02).
 *
 * Endpoints gestionados:
 * - GET   /api/public/refunds/track?token=...  (estado vivo del expediente)
 * - PATCH /api/public/refunds/track?token=...  (rectificación de IBAN o Bizum)
 *
 * ## Por qué el token es el único candado
 * El consumidor no tiene cuenta ni sesión. La seguridad no puede apoyarse en
 * un rol, así que depende de que el token sea largo e impredecible: 256 bits de
 * `random_bytes`, generados en T-REF-06. Por eso el formato se valida ANTES de
 * tocar la base de datos (422) y el token inexistente se responde con 404.
 *
 * ## Qué se puede ver por esa URL
 * Lo decide `PublicRefundTrackingDTO`, no este controlador. Ni el IBAN ni el
 * teléfono de Bizum ni el nombre del reclamante salen nunca por aquí (Art. V.4,
 * RNF-REF-03): quien tenga el enlace solo conoce el estado de su propio
 * expediente.
 *
 * Ninguna operación borra nada; el expediente sólo cambia de estado (Art. III).
 */
class PublicRefundController
{
    /**
     * Código de error para un token con formato inválido.
     *
     * El catálogo de contratos no define un 422 específico para el token, sólo
     * el `REFUND_NOT_FOUND` (404) del token inexistente. El "Hecho cuando" de
     * T-REF-08 sí exige 422 para los formatos inválidos, así que se declara
     * aquí y conviene incorporarlo al catálogo en la siguiente revisión.
     */
    public const ERROR_INVALID_TOKEN_FORMAT = 'INVALID_TRACKING_TOKEN';
    public const MESSAGE_INVALID_TOKEN_FORMAT = 'El enlace de seguimiento no tiene un formato válido.';

    /** Longitud del token: 32 bytes aleatorios en hexadecimal (64 caracteres). */
    public const TRACKING_TOKEN_LENGTH = 64;

    private RefundRequestRepositoryInterface $refundRepo;
    private RefundManagementService $managementService;
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;

    public function __construct(
        ?RefundRequestRepositoryInterface $refundRepo = null,
        ?RefundManagementService $managementService = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null
    ) {
        $this->refundRepo = $refundRepo ?? new PdoRefundRequestRepository();
        $this->managementService = $managementService ?? new RefundManagementService($this->refundRepo);
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
    }

    /**
     * GET /api/public/refunds/track?token={tracking_token}
     *
     * Estado vivo del expediente para su titular.
     */
    public function track(Request $request): Response
    {
        $tokenResponse = $this->readToken($request);
        if ($tokenResponse instanceof Response) {
            return $tokenResponse;
        }

        try {
            $case = $this->refundRepo->findByTrackingToken($tokenResponse);
        } catch (Throwable $e) {
            return Response::error('TRACKING_UNAVAILABLE', 'No ha sido posible consultar el expediente en este momento.', 503);
        }

        if ($case === null || !$case->isActive()) {
            return Response::error(
                RefundNotFoundException::ERROR_CODE,
                RefundNotFoundException::DEFAULT_MESSAGE,
                RefundNotFoundException::HTTP_STATUS
            );
        }

        return Response::json(
            $this->buildProjection($case)->toArray(),
            200
        );
    }

    /**
     * PATCH /api/public/refunds/track?token={tracking_token}
     *
     * Rectifica el teléfono de Bizum o el IBAN cuando el expediente está en
     * `PENDING_CONTACT` (RF-REF-07).
     */
    public function rectify(Request $request): Response
    {
        $tokenResponse = $this->readToken($request);
        if ($tokenResponse instanceof Response) {
            return $tokenResponse;
        }

        try {
            $case = $this->refundRepo->findByTrackingToken($tokenResponse);
        } catch (Throwable $e) {
            return Response::error('TRACKING_UNAVAILABLE', 'No ha sido posible consultar el expediente en este momento.', 503);
        }

        if ($case === null || !$case->isActive()) {
            return Response::error(
                RefundNotFoundException::ERROR_CODE,
                RefundNotFoundException::DEFAULT_MESSAGE,
                RefundNotFoundException::HTTP_STATUS
            );
        }

        $bizumPhone = $this->readOptionalString($request->getBodyParam('bizum_phone'));
        $iban = $this->readOptionalString($request->getBodyParam('iban'));

        if ($bizumPhone === null && $iban === null) {
            return Response::error(
                self::ERROR_INVALID_TOKEN_FORMAT,
                'Debe indicar al menos un teléfono de Bizum o un IBAN para rectificar sus datos de pago.',
                422
            );
        }

        try {
            $updated = $this->managementService->rectifyContactDetails(
                (int)$case->getId(),
                $bizumPhone,
                $iban
            );
        } catch (InvalidBizumPhoneException | InvalidIbanFormatException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        // La respuesta sólo lleva el estado: ni el IBAN ni el teléfono corregidos
        // vuelven a salir por la URL pública (Art. V.4).
        return Response::json(
            ['status' => $updated->getStatus()->value],
            200,
            'Datos de pago actualizados correctamente. El coordinador reanudará la tramitación de su reintegro.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Extrae y valida el token de la URL.
     *
     * Se valida el formato antes de consultar la base de datos: un token que no
     * puede existir no debe costar una consulta (RNF-REF-02).
     *
     * @return Response|string Respuesta de error, o el token válido.
     */
    private function readToken(Request $request): Response|string
    {
        $raw = $request->getQuery('token');
        $token = is_string($raw) ? trim($raw) : '';

        if ($token === '') {
            return Response::error(
                self::ERROR_INVALID_TOKEN_FORMAT,
                'Debe incluir el parámetro `token` del enlace de seguimiento.',
                422
            );
        }

        if (preg_match('/^[0-9a-f]{' . self::TRACKING_TOKEN_LENGTH . '}$/', $token) !== 1) {
            return Response::error(
                self::ERROR_INVALID_TOKEN_FORMAT,
                self::MESSAGE_INVALID_TOKEN_FORMAT,
                422
            );
        }

        return $token;
    }

    /**
     * Resuelve el contexto legible (código de máquina y nombre de sede) sin
     * arrastrar los datos de incidencia de la máquina, que el seguimiento público
     * no necesita.
     */
    private function buildProjection(\VendGuard\Core\Domain\Model\RefundRequest $case): PublicRefundTrackingDTO
    {
        $machineCode = null;
        $machine = $this->machineRepo->findById($case->getMachineId(), false);
        if ($machine !== null) {
            $machineCode = $machine->getCode();
        }

        $locationName = null;
        $location = $this->locationRepo->findById($case->getLocationId());
        if ($location !== null) {
            $locationName = $location->getName();
        }

        return PublicRefundTrackingDTO::fromRefundRequest($case, $machineCode, $locationName);
    }

    /**
     * Normaliza un campo opcional del cuerpo: cadena vacía o `null` significan
     * "no se corrige este campo".
     */
    private function readOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}