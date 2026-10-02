<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\CoordinatorApprovalDTO;
use VendGuard\Application\DTO\CoordinatorPaymentDTO;
use VendGuard\Application\DTO\CoordinatorRefundViewDTO;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
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
 * CoordinatorRefundController
 *
 * Bandeja central de dinero retenido: el único sitio del sistema desde el que
 * se ve el detalle financiero completo y se toma una decisión con consecuencias
 * económicas (RF-REF-03, RF-REF-07, RF-REF-08).
 *
 * Endpoints gestionados:
 * - GET  /api/coordinator/refunds                (bandeja global filtrada)
 * - POST /api/coordinator/refunds/{id}/approve   (doble visto bueno > 10,00 €)
 * - POST /api/coordinator/refunds/{id}/pay       (liquidación con justificante)
 * - POST /api/coordinator/refunds/{id}/reject    (desestimación motivada)
 *
 * ## Por qué aquí sí hay IBAN y teléfono Bizum
 * Las otras tres proyecciones del módulo (pública, conserjería y técnico) los
 * eliminan a propósito, porque quien las ve no puede pagar a nadie. Aquí al
 * revés: sin IBAN no hay transferencia, y sin teléfono no hay Bizum. La
 * segregación del Art. V.4 no prohíbe mostrar el dato a Coordinación, prohíbe
 * que se lo enseñen a quien no debe cobrar. El recorte de qué se publica
 * exactamente está en `CoordinatorRefundViewDTO`, y sigue dejando fuera el PIN
 * de recogida y el token de seguimiento público.
 *
 * ## Doble autorización por encima de 10,00 € (RF-REF-03)
 * `approve` no es un campo de texto libre sobre el expediente: sólo funciona
 * desde `REQUIRES_COORDINATOR_APPROVAL`, que es el estado en el que la
 * clasificación automática deposita todo importe por encima del umbral o con
 * discrepancia material. Aprobar un expediente que ya estaba verificado, o
 * saltarse la doble autorización aprobando uno recién abierto, devuelve 409.
 *
 * El importe aprobado tampoco puede superar el techo antifraude de 50,00 € por
 * reclamación: el veto existe para que una firma no pueda convalidar un
 * una reclamación de 500 € sólo porque lo escribió en el formulario.
 *
 * ## Nada se borra (Art. III)
 * Un rechazo no elimina el expediente ni el dinero que se reclamaba: lo deja en
 * `REJECTED` con su motivo escrito y su historial completo. Descartar una
 * reclamación falsa empieza por dejar constancia de que alguien la pidió.
 *
 * ## Códigos de error propios de este controlador
 * El catálogo de `specs/technical/refunds_contracts.md` §5 no cubre los fallos
 * de forma de los tres cuerpos de escritura, así que este controlador añade los
 * suyos, todos en castellano y todos con el mismo envoltorio de error:
 *   - `INVALID_REFUND_ID` (400): el `{id}` de la ruta no es un entero.
 *   - `MISSING_APPROVED_AMOUNT` (422): falta `approved_amount`.
 *   - `MISSING_PAYMENT_REFERENCE` (422): falta `payment_reference`, sin el cual
 *     no hay asiento contable que justifique la salida de dinero (RF-REF-07).
 *   - `MISSING_REJECTION_REASON` (422): falta `rejection_reason`.
 *   - `INVALID_REFUND_FILTER` (422): un filtro de la bandeja no tiene sentido
 *     (`status` desconocido, `limit` no numérico, `from` con formato inválido).
 * El resto (`UNAUTHORIZED` 401, `FORBIDDEN` 403, `REFUND_NOT_FOUND` 404,
 * `INVALID_REFUND_STATE_TRANSITION` 409, `INVALID_REFUND_AMOUNT` 422 y
 * `JUSTIFICATION_TOO_SHORT` 422) viene del catálogo y se reutiliza sin variantes.
 */
class CoordinatorRefundController
{
    /** Tope antifraude de una bandeja: nadie pagina 10.000 expedientes de golpe. */
    private const MAX_PAGE_SIZE = 200;

    private RefundRequestRepositoryInterface $refundRepo;
    private IncidentRepositoryInterface $incidentRepo;
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private RefundManagementService $managementService;

    public function __construct(
        ?RefundRequestRepositoryInterface $refundRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?RefundManagementService $managementService = null
    ) {
        $this->refundRepo = $refundRepo ?? new PdoRefundRequestRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->managementService = $managementService ?? new RefundManagementService($this->refundRepo);
    }

    /**
     * GET /api/coordinator/refunds
     *
     * Bandeja global con detalle financiero completo, filtros y paginación.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): Response
    {
        // 1. Sólo Coordinación (Art. V.4).
        $denied = $this->authorizeCoordinator($request);
        if ($denied !== null) {
            return $denied;
        }

        // 2. Filtros. Un filtro que no significa nada se rechaza en lugar de
        //    ignorarse en silencio: si el operador cree que está viendo "sólo
        //    pendientes de visto bueno" y en realidad ve la bandeja entera,
        //    aprobará un expediente equivocado creyendo que es el único.
        $filters = [];
        $invalid = null;

        $statusFilter = trim((string)($request->getQuery('status') ?? ''));
        if ($statusFilter !== '') {
            if (RefundStatus::tryFrom($statusFilter) === null) {
                $invalid = 'status';
            }
            $filters['status'] = $statusFilter;
        }

        // `requires_approval_only` es el atajo del contrato §4.4.1 para el
        // semáforo de la pestaña: se traduce al estado, no a una consulta
        // distinta, para que el total y los elementos no puedan discrepar.
        $approvalOnly = $this->parseBoolFilter($request->getQuery('requires_approval_only'));
        if ($invalid === null && $approvalOnly === true) {
            $filters['status'] = RefundStatus::REQUIRES_COORDINATOR_APPROVAL->value;
        }

        foreach (['location_id', 'machine_id'] as $idFilter) {
            $raw = trim((string)($request->getQuery($idFilter) ?? ''));
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
                $invalid = $idFilter;
                continue;
            }

            $filters[$idFilter] = (int)$raw;
        }

        foreach (['from', 'to'] as $dateFilter) {
            $raw = trim((string)($request->getQuery($dateFilter) ?? ''));
            if ($raw === '') {
                continue;
            }

            // Acepta `YYYY-MM-DD` o `YYYY-MM-DD HH:MM:SS`; cualquier otra cosa
            // compararía fechas contra basura y devolvería cero filas sin
            // explicación.
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw) !== 1) {
                $invalid = $dateFilter;
                continue;
            }

            $filters[$dateFilter] = $raw;
        }

        if ($invalid !== null) {
            return $this->invalidFilter($invalid);
        }

        $limit = $this->parsePaging($request->getQuery('limit'), self::MAX_PAGE_SIZE, 1, self::MAX_PAGE_SIZE);
        if ($limit === null) {
            return $this->invalidFilter('limit');
        }

        $offset = $this->parsePaging($request->getQuery('offset'), 0, 0, PHP_INT_MAX);
        if ($offset === null) {
            return $this->invalidFilter('offset');
        }

        // 3. Consulta con proyección COMPLETA (IBAN y Bizum incluidos): es la
        //    única bandeja del sistema con permiso para verlos.
        $cases = $this->refundRepo->findForCoordinator($filters, $limit, $offset);

        $views = array_map(
            fn (RefundRequest $case): CoordinatorRefundViewDTO => CoordinatorRefundViewDTO::fromRefundRequest(
                $case,
                $this->resolveIncidentCode($case),
                $this->resolveMachineCode($case),
                $this->resolveLocationName($case)
            ),
            $cases
        );

        // 4. El total sale de un COUNT con los MISMOS filtros, no de
        //    count($items): sin él, una página de 50 de 300 parecería una
        //    bandeja de 50 y la paginación mentiría.
        $approvalFilters = $filters;
        $approvalFilters['status'] = RefundStatus::REQUIRES_COORDINATOR_APPROVAL->value;

        return Response::json([
            'total' => $this->refundRepo->countForCoordinator($filters),
            'requires_approval_total' => $this->refundRepo->countForCoordinator($approvalFilters),
            'limit' => $limit,
            'offset' => $offset,
            'filters' => $filters,
            'totals' => [
                'claimed_amount' => array_sum(array_map(
                    static fn (CoordinatorRefundViewDTO $view): float => $view->claimedAmount,
                    $views
                )),
                'payable_amount' => array_sum(array_map(
                    static fn (CoordinatorRefundViewDTO $view): float => $view->payableAmount(),
                    $views
                )),
            ],
            'items' => array_map(
                static fn (CoordinatorRefundViewDTO $view): array => $view->toArray(),
                $views
            ),
        ], 200);
    }

    /**
     * POST /api/coordinator/refunds/{id}/approve
     *
     * Doble visto bueno formal ante importes superiores a 10,00 €, discrepancias
     * materiales o ausencia de efectivo no verificada (RF-REF-03).
     *
     * @return array<string, mixed>
     */
    public function approve(Request $request): Response
    {
        $denied = $this->authorizeCoordinator($request);
        if ($denied !== null) {
            return $denied;
        }

        $refundId = $this->resolveRefundId($request);
        if ($refundId instanceof Response) {
            return $refundId;
        }

        $rawAmount = $request->getBodyParam('approved_amount');
        if ($rawAmount === null || trim((string)$rawAmount) === '') {
            return Response::error(
                'MISSING_APPROVED_AMOUNT',
                'Indique la cuantía final que se autoriza a devolver.',
                422
            );
        }

        if (!is_numeric($rawAmount)) {
            return Response::error(
                InvalidRefundAmountException::ERROR_CODE,
                InvalidRefundAmountException::DEFAULT_MESSAGE,
                InvalidRefundAmountException::HTTP_STATUS
            );
        }

        // `notes` es el nombre del contrato §4.4.2; el dominio lo llama
        // `justification`. Es opcional: el motivo es obligatorio al rechazar
        // (RF-REF-08), no al autorizar.
        $justification = trim((string)($request->getBodyParam('notes') ?? ''));

        try {
            $approved = $this->managementService->approveCase(
                $refundId,
                new CoordinatorApprovalDTO((float)$rawAmount, $justification),
                $this->buildActor($request)
            );
        } catch (InvalidRefundAmountException $e) {
            // Los detalles viajan para que el coordinador sepa cuál es la cifra
            // máxima admisible, en vez de tener que deducirla de la reclamacion.
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode(), $e->getDetails());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        return Response::json([
            'id' => (int)$approved->getId(),
            'status' => $approved->getStatus()->value,
            'approved_amount' => $approved->getApprovedAmount(),
            'claimed_amount' => $approved->getClaimedAmount(),
            'coordinator_decision' => $approved->getCoordinatorDecision()?->value,
            'justification' => $approved->getCoordinatorJustification(),
            'awaits_payment' => $approved->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT,
        ], 200, 'Visto bueno registrado con éxito. El expediente queda listo para su liquidación.');
    }

    /**
     * POST /api/coordinator/refunds/{id}/pay
     *
     * Registra la salida efectiva de dinero con su justificante bancario o de
     * Bizum (RF-REF-07).
     *
     * VendGuard no se conecta a ninguna pasarela (Art. VI): el operador liquidó
     * por su canal habitual y aquí queda el identificador que lo acredita.
     *
     * @return array<string, mixed>
     */
    public function pay(Request $request): Response
    {
        $denied = $this->authorizeCoordinator($request);
        if ($denied !== null) {
            return $denied;
        }

        $refundId = $this->resolveRefundId($request);
        if ($refundId instanceof Response) {
            return $refundId;
        }

        $reference = trim((string)($request->getBodyParam('payment_reference') ?? ''));
        if ($reference === '') {
            // Sin referencia no hay asiento que justifique la salida de dinero,
            // así que no es un campo opcional con valor por defecto: es el
            // asiento contable del reembolso.
            return Response::error(
                'MISSING_PAYMENT_REFERENCE',
                'Indique la referencia del justificante bancario o de Bizum con la que se ha liquidado.',
                422
            );
        }

        // El expediente se lee antes de validar el importe porque, si no viene
        // `paid_amount`, el importe a liquidar es el ya aprobado formalmente.
        $case = $this->refundRepo->findById($refundId);
        if ($case === null || !$case->isActive()) {
            return Response::error(
                RefundNotFoundException::ERROR_CODE,
                RefundNotFoundException::DEFAULT_MESSAGE,
                RefundNotFoundException::HTTP_STATUS
            );
        }

        $rawPaid = $request->getBodyParam('paid_amount');
        if ($rawPaid === null || trim((string)$rawPaid) === '') {
            $paidAmount = $this->defaultPayableAmount($case);
        } elseif (!is_numeric($rawPaid)) {
            return Response::error(
                InvalidRefundAmountException::ERROR_CODE,
                InvalidRefundAmountException::DEFAULT_MESSAGE,
                InvalidRefundAmountException::HTTP_STATUS
            );
        } else {
            $paidAmount = (float)$rawPaid;
        }

        try {
            $paid = $this->managementService->registerDigitalPayment(
                $refundId,
                new CoordinatorPaymentDTO($reference, $paidAmount),
                $this->buildActor($request)
            );
        } catch (InvalidRefundAmountException $e) {
            // Se propagan los detalles para que el coordinador reciba el importe
            // esperado y pueda firma el que corresponda, en lugar de adivinarlo.
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode(), $e->getDetails());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        return Response::json([
            'id' => (int)$paid->getId(),
            'status' => $paid->getStatus()->value,
            'payment_reference' => $paid->getPaymentReference(),
            // Se devuelve lo PERSISTIDO, no lo que venía en el cuerpo: así la
            // respuesta no puede afirmar una cifra distinta de la almacenada.
            'paid_amount' => $paid->getPaidAmount(),
            'approved_amount' => $paid->getApprovedAmount(),
            'paid_at' => $paid->getPaidAt(),
        ], 200, 'Pago digital registrado con éxito. Expediente de reintegro liquidado.');
    }

    /**
     * POST /api/coordinator/refunds/{id}/reject
     *
     * Desestimación motivada: el motivo escrito es obligatorio y de 20
     * caracteres como mínimo (RF-REF-08).
     *
     * @return array<string, mixed>
     */
    public function reject(Request $request): Response
    {
        $denied = $this->authorizeCoordinator($request);
        if ($denied !== null) {
            return $denied;
        }

        $refundId = $this->resolveRefundId($request);
        if ($refundId instanceof Response) {
            return $refundId;
        }

        $reason = trim((string)($request->getBodyParam('rejection_reason') ?? ''));
        if ($reason === '') {
            return Response::error(
                'MISSING_REJECTION_REASON',
                'Indique el motivo escrito por el que se desestima la reclamación.',
                422
            );
        }

        try {
            $rejected = $this->managementService->rejectCase($refundId, $reason, $this->buildActor($request));
        } catch (JustificationTooShortException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode(), $e->getDetails());
        } catch (InvalidRefundStateTransitionException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (RefundNotFoundException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        return Response::json([
            'id' => (int)$rejected->getId(),
            'status' => $rejected->getStatus()->value,
            'coordinator_decision' => $rejected->getCoordinatorDecision()?->value,
            'justification' => $rejected->getCoordinatorJustification(),
            // La pista de que el dinero NO se ha pagado viaja en la respuesta:
            // quien recibió el 200 tiene que ver que esto fue un cierre sin
            // desembolso, no un reembolso.
            'claimed_amount' => $rejected->getClaimedAmount(),
            'paid_amount' => null,
            'preserved_for_audit' => $rejected->isActive(),
        ], 200, 'Reclamación desestimada con motivo registrado. El expediente se conserva para su auditoría.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 401 sin identidad y 403 sin rol de Coordinación (Art. V.4).
     *
     * Son dos respuestas distintas a propósito: el 401 dice "no sé quién eres"
     * y el 403 "sé quién eres y aun así no puedes". Fundirlos daría a un
     * técnico la pista de que existe una bandeja de datos financieros.
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
                'No dispone de permisos para gestionar o consultar datos financieros de reintegros.',
                403
            );
        }

        return null;
    }

    /**
     * `{id}` de la ruta: entero positivo o 400.
     *
     * @return int|Response
     */
    private function resolveRefundId(Request $request): int|Response
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || preg_match('/^[1-9][0-9]*$/D', (string)$rawId) !== 1) {
            return Response::error('INVALID_REFUND_ID', 'El ID de expediente de reintegro no es válido.', 400);
        }

        return (int)$rawId;
    }

    /**
     * Metadatos del coordinador para la auditoría inmutable (Art. III).
     *
     * @return array{id: int|null, role: string, name: string}
     */
    private function buildActor(Request $request): array
    {
        $userId = $request->getAttribute('user_id');

        return [
            'id' => is_numeric($userId) ? (int)$userId : null,
            'role' => (string)($request->getAttribute('user_role') ?? 'COORDINATOR'),
            'name' => 'Coordinación',
        ];
    }

    /**
     * El importe que se liquida si el operador no escribe uno.
     *
     * Delega en `RefundManagementService::payableAmount()` a propósito: la
     * cifra por defecto tiene que ser exactamente la que el servicio acepta,
     * porque `registerDigitalPayment()` ya no tolera otras. La vista de
     * coordinación ofrece `payable_amount` como sugerencia de trabajo y cae al
     * importe verificado cuando lo reclamado y lo recuperado se diferencian
     * dentro del 20% tolerado; usar esa cifra como predeterminada terminaba en
     * un 422 en casos que la propia interfaz daba por liquidables.
     */
    private function defaultPayableAmount(RefundRequest $case): float
    {
        return $this->managementService->payableAmount($case);
    }

    /**
     * Filtro booleano tolerante: `1`, `true`, `yes`, `si` activan el filtro.
     */
    private function parseBoolFilter(mixed $raw): ?bool
    {
        if ($raw === null) {
            return false;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'si', 'sí'], true);
    }

    /**
     * Entero de paginación acotado, o null si no lo es.
     */
    private function parsePaging(mixed $raw, int $default, int $min, int $max): ?int
    {
        if ($raw === null || trim((string)$raw) === '') {
            return $default;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int)$raw;

        return ($value >= $min && $value <= $max) ? $value : null;
    }

    /**
     * 422 de filtro, nombrando el parámetro culpable.
     */
    private function invalidFilter(string $parameter): Response
    {
        return Response::error(
            'INVALID_REFUND_FILTER',
            sprintf('El filtro «%s» de la bandeja de reintegros no es válido.', $parameter),
            422,
            ['parameter' => $parameter]
        );
    }

    /**
     * Memoria por petición para no repetir la misma consulta por cada fila.
     *
     * @var array<int, string|null>
     */
    private array $incidentCodeCache = [];

    private function resolveIncidentCode(RefundRequest $case): ?string
    {
        $incidentId = $case->getIncidentId();
        if (!array_key_exists($incidentId, $this->incidentCodeCache)) {
            $this->incidentCodeCache[$incidentId] = $this->incidentRepo->findById($incidentId)?->getTicketCode();
        }

        return $this->incidentCodeCache[$incidentId];
    }

    /** @var array<int, string|null> */
    private array $machineCodeCache = [];

    private function resolveMachineCode(RefundRequest $case): ?string
    {
        $machineId = $case->getMachineId();
        if (!array_key_exists($machineId, $this->machineCodeCache)) {
            $this->machineCodeCache[$machineId] = $this->machineRepo->findById($machineId, false)?->getCode();
        }

        return $this->machineCodeCache[$machineId];
    }

    /** @var array<int, string|null> */
    private array $locationNameCache = [];

    private function resolveLocationName(RefundRequest $case): ?string
    {
        $locationId = $case->getLocationId();
        if (!array_key_exists($locationId, $this->locationNameCache)) {
            $this->locationNameCache[$locationId] = $this->locationRepo->findById($locationId)?->getName();
        }

        return $this->locationNameCache[$locationId];
    }
}