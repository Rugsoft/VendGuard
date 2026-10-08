<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\DTO\RefundReceiptDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\ChronicIncidentException;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\DuplicateRefundClaimException;
use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Service\UrgencyCalculator;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * LocationPortalController
 * 
 * Controlador REST para el Portal de Sede / Ubicación (Responsables de Centro).
 * Gestiona la consulta del parque de máquinas, creación de incidencias,
 * adjuntos y reaperturas (RF-01, RF-02, RF-03).
 */
class LocationPortalController
{
    public const ERROR_MISSING_REFUND_DATA = 'MISSING_REFUND_DATA';
    public const ERROR_MISSING_REFUND_AMOUNT = 'MISSING_REFUND_AMOUNT';
    public const ERROR_MISSING_REFUND_PAYMENT_DATA = 'MISSING_REFUND_PAYMENT_DATA';
    public const ERROR_INVALID_COMPENSATION_METHOD = 'INVALID_COMPENSATION_METHOD';

    public const MESSAGE_OVER_TELEPHONE_ADVICE =
        'Para importes superiores a 50,00 €, contacte con el departamento de atención al cliente de VendGuard.';

    private const TRUTHY_VALUES = ['1', 'true', 'yes', 'si', 'sí'];

    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private IncidentRepositoryInterface $incidentRepo;
    private LocalFileUploader $fileUploader;
    private RefundManagementService $refundService;
    private IbanValidationService $ibanValidator;
    private ?IncidentCommentService $commentService;
    private ?AuditLogger $auditLogger;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?LocalFileUploader $fileUploader = null,
        ?RefundManagementService $refundService = null,
        ?IbanValidationService $ibanValidator = null,
        ?IncidentCommentService $commentService = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
        $this->ibanValidator = $ibanValidator ?? new IbanValidationService();
        $this->refundService = $refundService
            ?? new RefundManagementService(new PdoRefundRequestRepository(), $this->ibanValidator);

        // Inicialización perezosa: el AuditLogger por defecto del servicio de
        // comentarios abre conexión a MariaDB al instanciarse, y este controlador
        // también se construye en contextos unitarios sin base de datos.
        $this->commentService = $commentService;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Registro inmutable de auditoría (RF-05, EARS 5.1), resuelto bajo demanda.
     *
     * Se construye de forma perezosa por el mismo motivo que el servicio de
     * comentarios: este controlador también se instancia en contextos unitarios
     * sin base de datos, y las suites pueden inyectar un sumidero en memoria.
     */
    private function audit(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new PdoAuditLogRepository());
    }

    /**
     * Servicio de aplicación del hilo de comentarios, resuelto bajo demanda.
     */
    private function comments(): IncidentCommentService
    {
        return $this->commentService ??= new IncidentCommentService($this->incidentRepo, null, $this->fileUploader);
    }

    /**
     * GET /api/locations/{site_code}/machines
     * Devuelve el catálogo de máquinas activas de la sede indicando si cuentan con
     * avisos de avería activos o en periodo de garantía (RF-01, RF-02).
     */
    public function getMachines(Request $request): Response
    {
        $siteCodeParam = $request->getRouteParam('site_code') ?? $request->getRouteParam('code');

        if ($siteCodeParam === null || trim($siteCodeParam) === '') {
            return Response::error(
                'MISSING_SITE_CODE',
                'El código de sede es obligatorio en la URL.',
                400
            );
        }

        $requestedCode = strtoupper(trim($siteCodeParam));

        // Validación de aislamiento de sedes (si el middleware inyectó la sede autenticada)
        $authenticatedSiteCode = $request->getAttribute('site_code');
        if ($authenticatedSiteCode !== null && strcasecmp((string)$authenticatedSiteCode, $requestedCode) !== 0) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para consultar el parque de máquinas de otra sede.',
                403
            );
        }

        // Obtener la entidad Location
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $location = $this->locationRepo->findBySiteCode($requestedCode, true);
        }

        if ($location === null) {
            return Response::error(
                'LOCATION_NOT_FOUND',
                "No se encontró ninguna sede activa con el código '{$requestedCode}'.",
                404
            );
        }

        // Consultar máquinas con el estado activo/garantía de incidencia
        $machines = $this->machineRepo->findActiveByLocationId($location->getId());

        $payload = array_map(function (Machine $machine): array {
            return [
                'id' => $machine->getId(),
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'machine_type' => $machine->getMachineType()->value,
                'floor_wing' => $machine->getFloorWing(),
                'notes' => $machine->getNotes(),
                'active_incident' => $this->withPublicCommentsCount(
                    $this->sanitizeIncidentForSite($machine->getActiveIncident())
                ),
            ];
        }, $machines);

        return Response::json($payload, 200);
    }

    /**
     * Anexa a la avería activa el recuento segregado de comentarios PÚBLICOS que
     * alimenta la insignia de conversación de la tarjeta de máquina (RF-01.1).
     *
     * La sede nunca recibe el total de mensajes: un recuento que incluyera notas
     * internas delataría su existencia al Responsable de Ubicación (RF-02.1,
     * RNF-01, Constitución Art. V.4). El cálculo se delega en el repositorio, que
     * resuelve el `COUNT(*)` filtrado (`is_internal = 0`) sobre el índice
     * `idx_comments_incident` (T-COM-02).
     *
     * @param array<string, mixed>|null $incident Avería activa ya saneada; `null` si la máquina está operativa.
     * @return array<string, mixed>|null
     */
    private function withPublicCommentsCount(?array $incident): ?array
    {
        if ($incident === null) {
            return null;
        }

        $incidentId = (int)($incident['id'] ?? 0);
        $incident['public_comments_count'] = $incidentId > 0
            ? $this->incidentRepo->countComments($incidentId, false)
            : 0;

        return $incident;
    }

    /**
     * POST /api/incidents
     * Crea un nuevo aviso de avería para una máquina de la sede (RF-02, RF-03, RNF-02, RNF-05).
     * 
     * Soporta multipart/form-data y application/json:
     * - Calcula automáticamente la urgencia según criticidad y tipo de máquina (EARS 3.2-3.7).
     * - Rechaza duplicados con HTTP 409 Conflict si la máquina ya tiene aviso activo o está en garantía (EARS 2.1, 2.2).
     * - Valida y almacena fotografías adjuntas (<= 5 MB, JPEG/PNG/WebP).
     * - Devuelve HTTP 201 Created con el expediente generado.
     */
    public function createIncident(Request $request): Response
    {
        // 1. Identificar la sede autenticada
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $locationId = $request->getAttribute('location_id');
            if ($locationId !== null) {
                $location = $this->locationRepo->findById((int)$locationId);
            }
        }
        if ($location === null) {
            $siteCodeHeader = $request->getHeader('X-Site-Code') ?? $request->getAttribute('site_code');
            if ($siteCodeHeader !== null && trim((string)$siteCodeHeader) !== '') {
                $location = $this->locationRepo->findBySiteCode(trim((string)$siteCodeHeader), true);
            }
        }

        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
                401
            );
        }

        // 2. Validar identificador de máquina
        $machineIdRaw = $request->getBodyParam('machine_id');
        if ($machineIdRaw === null || trim((string)$machineIdRaw) === '') {
            return Response::error(
                'MISSING_MACHINE_ID',
                'El identificador de la máquina (machine_id) es obligatorio.',
                400
            );
        }

        if (!is_numeric($machineIdRaw) || (int)$machineIdRaw <= 0) {
            return Response::error(
                'INVALID_MACHINE_ID',
                'El identificador de la máquina (machine_id) debe ser un número entero positivo.',
                400
            );
        }

        $machineId = (int)$machineIdRaw;
        $machine = $this->machineRepo->findById($machineId);

        if ($machine === null) {
            return Response::error(
                'MACHINE_NOT_FOUND',
                "No se encontró ninguna máquina con ID {$machineId}.",
                404
            );
        }

        // Validar aislamiento de sede
        if ($machine->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'La máquina indicada no pertenece a la sede autenticada.',
                403
            );
        }

        // Validar que la máquina esté activa
        if (!$machine->isActive()) {
            return Response::error(
                'MACHINE_INACTIVE',
                'La máquina seleccionada no se encuentra activa en el catálogo.',
                422
            );
        }

        // 3. Validar categoría de avería (EARS 3.1 - 3.7)
        $categoryRaw = $request->getBodyParam('category');
        if ($categoryRaw === null || trim((string)$categoryRaw) === '') {
            return Response::error(
                'MISSING_CATEGORY',
                'La categoría de la avería (category) es obligatoria.',
                400
            );
        }

        $categoryNormalized = strtoupper(trim((string)$categoryRaw));
        if (!IncidentCategory::isValid($categoryNormalized)) {
            return Response::error(
                'INVALID_CATEGORY',
                "Categoría de avería no válida: '{$categoryRaw}'. Valores permitidos: " . implode(', ', IncidentCategory::values()),
                400
            );
        }

        $category = IncidentCategory::fromString($categoryNormalized);

        // 4. Validar descripción
        $description = trim((string)($request->getBodyParam('description') ?? ''));
        if ($description === '') {
            return Response::error(
                'MISSING_DESCRIPTION',
                'La descripción de la avería es obligatoria.',
                400
            );
        }

        if (mb_strlen($description) < 5) {
            return Response::error(
                'DESCRIPTION_TOO_SHORT',
                'La descripción de la avería debe contener al menos 5 caracteres.',
                422
            );
        }

        // 5. Metadatos opcionales (contacto y dinero retenido EARS 3.8)
        $reporterName = $request->getBodyParam('reporter_name');
        $reporterName = $reporterName !== null && trim((string)$reporterName) !== '' ? trim((string)$reporterName) : null;

        $reporterPhone = $request->getBodyParam('reporter_phone');
        $reporterPhone = $reporterPhone !== null && trim((string)$reporterPhone) !== '' ? trim((string)$reporterPhone) : null;

        $retainedMoney = null;
        $retainedMoneyRaw = $request->getBodyParam('retained_money_amount');
        if ($retainedMoneyRaw !== null && trim((string)$retainedMoneyRaw) !== '') {
            if (!is_numeric($retainedMoneyRaw)) {
                return Response::error(
                    'INVALID_MONEY_AMOUNT',
                    'El importe retenido debe ser un valor numérico.',
                    422
                );
            }
            $retainedMoneyVal = (float)$retainedMoneyRaw;
            if ($retainedMoneyVal < 0) {
                return Response::error(
                    'INVALID_MONEY_AMOUNT',
                    'El importe de dinero retenido no puede ser negativo.',
                    422
                );
            }
            $retainedMoney = $retainedMoneyVal;
        }

        // 5.1 Reclamación formal de reintegro (HU-02 / RF-REF-01)
        // Si el usuario marcó explícitamente solicitud de reintegro, se valida antes
        // de cualquier escritura para no registrar averías inconsistentes.
        $refundClaim = null;
        if ($this->wantsRefund($request)) {
            $refundClaim = $this->readRefundClaim($request);
            if ($refundClaim instanceof Response) {
                return $refundClaim;
            }
            if ($retainedMoney === null) {
                $retainedMoney = $refundClaim['claimed_amount'];
            }
        }

        // 6. Procesar archivo adjunto si se envía (RNF-05, EARS 3.9)
        $photoPath = null;
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        if ($photoFile !== null && isset($photoFile['error']) && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['tmp_name'])) {
            try {
                $photoPath = $this->fileUploader->upload($photoFile);
            } catch (InvalidUploadException $e) {
                // EARS 3.9: Preservar íntegros los datos de texto introducidos en el formulario
                return Response::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    $e->getHttpStatusCode(),
                    [
                        'form_data' => [
                            'machine_id' => $machineId,
                            'category' => $categoryNormalized,
                            'description' => $description,
                            'reporter_name' => $reporterName,
                            'reporter_phone' => $reporterPhone,
                            'retained_money_amount' => $retainedMoney,
                        ],
                    ]
                );
            }
        } elseif ($request->getBodyParam('photo_path') !== null && trim((string)$request->getBodyParam('photo_path')) !== '') {
            $photoPath = trim((string)$request->getBodyParam('photo_path'));
        }

        // 7. Cálculo automático de urgencia (Constitución Art. II, RF-03 / EARS 3.2-3.7)
        $urgency = UrgencyCalculator::calculate($machine->getMachineType(), $category);

        // 8. Generar código de ticket único (INC-YYYY-XXXX)
        $ticketCode = TicketCode::generate()->value();

        // 9. Construir la entidad de dominio Incident
        $incident = new Incident(
            id: null,
            ticketCode: $ticketCode,
            machineId: $machine->getId(),
            locationId: $location->getId(),
            category: $category,
            description: $description,
            urgency: $urgency,
            status: IncidentStatus::REGISTERED,
            assignedTechnicianId: null,
            reporterName: $reporterName,
            reporterPhone: $reporterPhone,
            retainedMoneyAmount: $retainedMoney,
            photoPath: $photoPath
        );

        // 10. Persistir atómicamente con prevención de duplicados (RF-02)
        try {
            $created = $this->incidentRepo->create($incident, null, 'Aviso registrado desde el portal de sede');
        } catch (DuplicateIncidentException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'ticket_status' => $e->getTicketStatus(),
                ]
            );
        }

        // 11. Apertura de expediente de reintegro formal si se solicitó (HU-02)
        $payload = $this->sanitizeIncidentForSite($created->toArray());
        if ($refundClaim !== null) {
            $receipt = $this->openRefundCase($refundClaim, (int)$created->getId(), $machine, $location);
            if ($receipt instanceof Response) {
                return $receipt;
            }
            $payload['refund'] = $receipt;
        }

        // 12. Devolver respuesta 201 Created con el payload del recurso generado
        return Response::json(
            $payload,
            201,
            'Incidencia registrada con éxito'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals: captura de reintegros de sede (HU-02 / RF-REF-01)
    // ─────────────────────────────────────────────────────────────────────

    private function wantsRefund(Request $request): bool
    {
        $raw = $request->getBodyParam('refund_requested');

        if ($raw === null) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int)$raw === 1;
        }

        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower(trim($raw)), self::TRUTHY_VALUES, true);
    }

    /**
     * @return Response|array{
     *     claimant_name: string,
     *     claimant_contact: string,
     *     claimed_amount: float,
     *     compensation_method: CompensationMethod,
     *     product_attempted: string,
     *     bizum_phone: ?string,
     *     iban: ?string
     * }
     */
    private function readRefundClaim(Request $request): Response|array
    {
        $rawAmount = $request->getBodyParam('claimed_amount') ?? $request->getBodyParam('retained_money_amount');
        if ($rawAmount === null || !is_numeric($rawAmount)) {
            return Response::error(
                self::ERROR_MISSING_REFUND_AMOUNT,
                'Indique el importe de dinero que la máquina le ha retenido.',
                422
            );
        }

        $claimedAmount = round((float)$rawAmount, 2);
        if ($claimedAmount <= 0.0 || $claimedAmount > 50.00) {
            return Response::error(
                InvalidRefundAmountException::ERROR_CODE,
                InvalidRefundAmountException::DEFAULT_MESSAGE . ' ' . self::MESSAGE_OVER_TELEPHONE_ADVICE,
                InvalidRefundAmountException::HTTP_STATUS
            );
        }

        $rawMethod = strtoupper(trim((string)($request->getBodyParam('compensation_method') ?? '')));
        $method = CompensationMethod::tryFrom($rawMethod);
        if ($method === null) {
            return Response::error(
                self::ERROR_INVALID_COMPENSATION_METHOD,
                'Indique cómo quiere recibir su dinero: recogida en la sede, Bizum o transferencia bancaria.',
                422
            );
        }

        $claimantName = $this->firstFilledValue(
            $request->getBodyParam('contact_name'),
            $request->getBodyParam('reporter_name')
        );
        $claimantContact = $this->firstFilledValue(
            $request->getBodyParam('contact_phone'),
            $request->getBodyParam('reporter_phone')
        );

        if ($claimantName === '' || $claimantContact === '') {
            return Response::error(
                self::ERROR_MISSING_REFUND_DATA,
                'Indique su nombre y un medio de contacto para poder tramitar la devolución.',
                422
            );
        }

        $bizumPhone = null;
        $iban = null;

        if ($method->requiresBizumPhone()) {
            $rawPhone = trim((string)($request->getBodyParam('bizum_phone') ?? ''));
            if ($rawPhone === '') {
                return Response::error(
                    self::ERROR_MISSING_REFUND_PAYMENT_DATA,
                    'Indique el número de móvil de 9 dígitos donde desea recibir el Bizum.',
                    422
                );
            }

            try {
                $bizumPhone = $this->ibanValidator->assertValidBizumPhone($rawPhone);
            } catch (InvalidBizumPhoneException $e) {
                return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
            }
        }

        if ($method->requiresIban()) {
            $rawIban = trim((string)($request->getBodyParam('iban') ?? ''));
            if ($rawIban === '') {
                return Response::error(
                    self::ERROR_MISSING_REFUND_PAYMENT_DATA,
                    'Indique el IBAN de la cuenta donde desea recibir la transferencia.',
                    422
                );
            }

            try {
                $iban = $this->ibanValidator->assertValidIban($rawIban);
            } catch (InvalidIbanFormatException $e) {
                return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
            }
        }

        return [
            'claimant_name' => $claimantName,
            'claimant_contact' => $claimantContact,
            'claimed_amount' => $claimedAmount,
            'compensation_method' => $method,
            'product_attempted' => trim((string)($request->getBodyParam('product_attempted') ?? '')),
            'bizum_phone' => $bizumPhone,
            'iban' => $iban,
        ];
    }

    /**
     * @param array<string, mixed> $claim
     * @return array<string, mixed>|Response
     */
    private function openRefundCase(array $claim, int $incidentId, Machine $machine, Location $location): Response|array
    {
        if ($incidentId < 1) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'No ha sido posible asociar su solicitud de reintegro al aviso registrado.',
                500
            );
        }

        try {
            $case = $this->refundService->createCase(new CreateRefundRequestDTO(
                incidentId: $incidentId,
                machineId: (int)$machine->getId(),
                locationId: (int)$location->getId(),
                claimantName: (string)$claim['claimant_name'],
                claimantContact: (string)$claim['claimant_contact'],
                claimedAmount: (float)$claim['claimed_amount'],
                compensationMethod: $claim['compensation_method'],
                productAttempted: (string)$claim['product_attempted'],
                bizumPhone: $claim['bizum_phone'],
                iban: $claim['iban']
            ));
        } catch (InvalidRefundAmountException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (DuplicateRefundClaimException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (InvalidBizumPhoneException | InvalidIbanFormatException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidArgumentException $e) {
            return Response::error(self::ERROR_MISSING_REFUND_DATA, $e->getMessage(), 422);
        } catch (\Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Su aviso se ha registrado, pero no ha sido posible abrir la solicitud de reintegro.',
                500
            );
        }

        return RefundReceiptDTO::fromRefundRequest($case)->toArray();
    }

    private function firstFilledValue(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hilo de conversación del expediente (Módulo 10 · T-COM-05)
    // Orquestado por IncidentCommentService: la segregación, el enmascaramiento
    // y la máquina de estados del sellado viven en la capa de aplicación.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/location/incidents/{id}/comments  (alias retrocompatible: /api/incidents/{ticket_code}/comments)
     * Devuelve el hilo de conversación del expediente para el Responsable de Sede
     * (RF-01.2, RF-01.4, RF-02.1, RF-02.2) con paginación cursorizada.
     *
     * Garantías de servidor (RNF-01 / Art. V.4):
     * - Segregación estricta: las notas internas jamás alcanzan el payload.
     * - Cero campo `is_internal` o metadato deducible en la respuesta JSON.
     * - Enmascaramiento de la identidad técnica y de coordinación ante la Sede.
     */
    public function getComments(Request $request): Response
    {
        // 1. Autenticación de sede (fail-fast)
        $location = $this->resolveAuthenticatedLocation($request);
        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
                401
            );
        }

        // 2. Identificador del expediente en la ruta ({id} numérico o alias {ticket_code})
        $identifier = $this->resolveIncidentIdentifier($request);
        if ($identifier === null) {
            return Response::error(
                'MISSING_TICKET_CODE',
                'El identificador de la incidencia es obligatorio en la URL.',
                400
            );
        }

        // 3. Autorización de acceso: existencia y aislamiento de sede (Art. V.4)
        $incident = $this->findIncidentByIdentifier($identifier);
        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el identificador '{$identifier}'.",
                404
            );
        }
        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para consultar incidencias de otra sede.',
                403
            );
        }

        // 4. Parámetros de paginación cursorizada (RF-01.2, RF-01.3)
        $limitRaw = $request->getQuery('limit');
        if ($limitRaw !== null && (!ctype_digit((string)$limitRaw) || (int)$limitRaw < 1)) {
            return Response::error(
                'INVALID_LIMIT',
                'El parámetro limit debe ser un entero positivo.',
                400
            );
        }
        $limit = $limitRaw !== null ? (int)$limitRaw : IncidentCommentService::DEFAULT_THREAD_LIMIT;

        $beforeIdRaw = $request->getQuery('before_id');
        if ($beforeIdRaw !== null && (!ctype_digit((string)$beforeIdRaw) || (int)$beforeIdRaw < 1)) {
            return Response::error(
                'INVALID_BEFORE_ID',
                'El parámetro before_id debe ser un entero positivo.',
                400
            );
        }
        $beforeId = $beforeIdRaw !== null ? (int)$beforeIdRaw : null;

        // 5. Orquestación del servicio de aplicación: filtrado y enmascaramiento en servidor
        try {
            $thread = $this->comments()->getThread((int)$incident->getId(), 'SITE_MANAGER', null, $limit, $beforeId);
        } catch (ConversationSealedException $e) {
            // Barrera defensiva (no alcanzable con el filtrado previo del repositorio).
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (\DomainException $e) {
            return Response::error('INCIDENT_NOT_FOUND', 'La incidencia solicitada no existe.', 404);
        }

        return Response::json($thread->jsonSerialize(), 200);
    }

    /**
     * POST /api/location/incidents/{id}/comments  (alias retrocompatible: /api/incidents/{ticket_code}/comments)
     * Publica un mensaje público en el hilo de conversación del expediente (RF-03.2, RF-04.1).
     *
     * Garantías de servidor (RNF-01 / Art. V.4 y Art. V.5):
     * - El cliente de sede jamás puede fijar `is_internal`: cualquier valor del
     *   cuerpo se ignora y el mensaje se publica siempre como público.
     * - La fotografía opcional se valida en servidor (≤ 5 MB y magic bytes reales)
     *   mediante LocalFileUploader antes de persistir nada.
     * - Expediente sellado (CLOSED/CANCELLED o RESOLVED fuera de la garantía de 48 h)
     *   se rechaza con HTTP 403 Forbidden (RF-05.3).
     */
    public function addComment(Request $request): Response
    {
        // 1. Autenticación de sede (fail-fast)
        $location = $this->resolveAuthenticatedLocation($request);
        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
                401
            );
        }

        // 2. Identificador del expediente en la ruta
        $identifier = $this->resolveIncidentIdentifier($request);
        if ($identifier === null) {
            return Response::error(
                'MISSING_TICKET_CODE',
                'El identificador de la incidencia es obligatorio en la URL.',
                400
            );
        }

        // 3. Autorización de acceso: existencia y aislamiento de sede (Art. V.4)
        $incident = $this->findIncidentByIdentifier($identifier);
        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el identificador '{$identifier}'.",
                404
            );
        }
        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para interactuar con incidencias de otra sede.',
                403
            );
        }

        // 4. Texto del mensaje: solo se comprueba su presencia (400); los límites
        //    de 5 a 1.000 caracteres descriptivos los aplica el servicio (RF-03.1).
        $commentText = trim((string)($request->getBodyParam('comment_text')
            ?? $request->getBodyParam('text')
            ?? $request->getBodyParam('description')
            ?? $request->getBodyParam('comment')
            ?? ''));
        if ($commentText === '') {
            return Response::error(
                'MISSING_COMMENT_TEXT',
                'El texto del comentario es obligatorio.',
                400
            );
        }

        // 5. Fotografía opcional multipart/form-data (RF-04.1). La autoría de la Sede
        //    la deriva el servicio desde el nombre de la sede del expediente.
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        // 6. Orquestación del servicio de aplicación (fail-fast, sellado y auditoría)
        try {
            $thread = $this->comments()->addComment(
                (int)$incident->getId(),
                'SITE_MANAGER',
                $commentText,
                null,
                null,
                $photoFile
            );
        } catch (InvalidCommentLengthException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (ConversationSealedException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (InvalidUploadException $e) {
            // RF-07.1: se devuelven los datos de texto íntegros para el reintento.
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'form_data' => [
                        'ticket_code' => $incident->getTicketCode(),
                        'comment_text' => $commentText,
                    ],
                ]
            );
        } catch (\DomainException $e) {
            return Response::error('INCIDENT_NOT_FOUND', 'La incidencia solicitada no existe.', 404);
        }

        // 7. Respuesta 201 Created con el hilo actualizado del expediente (RF-03.4)
        return Response::json(
            $thread->jsonSerialize(),
            201,
            'Comentario publicado en el hilo de conversación'
        );
    }

    /**
     * Resuelve la sede autenticada a partir de los atributos inyectados por
     * SiteAuthMiddleware, con las vías legacy de respaldo (Art. V.4).
     */
    private function resolveAuthenticatedLocation(Request $request): ?Location
    {
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $locationId = $request->getAttribute('location_id');
            if ($locationId !== null) {
                $location = $this->locationRepo->findById((int)$locationId);
            }
        }
        if ($location === null) {
            $siteCodeHeader = $request->getHeader('X-Site-Code') ?? $request->getAttribute('site_code');
            if ($siteCodeHeader !== null && trim((string)$siteCodeHeader) !== '') {
                $location = $this->locationRepo->findBySiteCode(trim((string)$siteCodeHeader), true);
            }
        }

        return $location;
    }

    /**
     * Identificador del expediente en la ruta: {id} numérico en las rutas nuevas
     * o {ticket_code} en el alias retrocompatible.
     */
    private function resolveIncidentIdentifier(Request $request): ?string
    {
        $identifier = $request->getRouteParam('id')
            ?? $request->getRouteParam('ticket_code')
            ?? $request->getRouteParam('code');
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        return trim($identifier);
    }

    /**
     * Localiza el expediente por ID numérico o código de ticket normalizado
     * (admite el prefijo '#' y minúsculas del alias legacy).
     */
    private function findIncidentByIdentifier(string $identifier): ?Incident
    {
        if (ctype_digit($identifier)) {
            return $this->incidentRepo->findById((int)$identifier);
        }

        $normalizedCode = strtoupper(ltrim($identifier, '#'));

        return $this->incidentRepo->findByTicketCode($normalizedCode);
    }

    /**
     * POST /api/incidents/{ticket_code}/reopen
     * Reabre una incidencia en estado RESUELTA dentro de las 48h de garantía (RF-09 / EARS 9.1, 9.2, 9.3).
     * 
     * - Desasigna automáticamente al técnico previo (assigned_technician_id = NULL) (EARS 9.1).
     * - Reinicia el reloj de garantía a 0 (resolved_at = NULL).
     * - Rechaza si han transcurrido > 48 horas con HTTP 422 REOPEN_WINDOW_EXPIRED (EARS 9.2).
     * - Bloquea con "Avería Crónica" (HTTP 422 CHRONIC_INCIDENT_LIMIT) a la 3ª reincidencia (EARS 9.3).
     * - Devuelve HTTP 200 OK con el ticket reabierto.
     */
    public function reopenIncident(Request $request): Response
    {
        // 1. Identificar la sede autenticada
        $location = $request->getAttribute('authenticated_location');
        if ($location === null) {
            $locationId = $request->getAttribute('location_id');
            if ($locationId !== null) {
                $location = $this->locationRepo->findById((int)$locationId);
            }
        }
        if ($location === null) {
            $siteCodeHeader = $request->getHeader('X-Site-Code') ?? $request->getAttribute('site_code');
            if ($siteCodeHeader !== null && trim((string)$siteCodeHeader) !== '') {
                $location = $this->locationRepo->findBySiteCode(trim((string)$siteCodeHeader), true);
            }
        }

        if ($location === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. No se ha podido verificar la sede del usuario.',
                401
            );
        }

        // 2. Obtener y validar el código de ticket
        $ticketCodeParam = $request->getRouteParam('ticket_code') ?? $request->getRouteParam('code');
        if ($ticketCodeParam === null || trim($ticketCodeParam) === '') {
            return Response::error(
                'MISSING_TICKET_CODE',
                'El código de ticket es obligatorio en la URL.',
                400
            );
        }

        $ticketCode = strtoupper(trim($ticketCodeParam));
        $incident = $this->incidentRepo->findByTicketCode($ticketCode);

        if ($incident === null) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con el código '{$ticketCode}'.",
                404
            );
        }

        // 3. Validar segregación de sede (Artículo V Constitución / RF-01)
        if ($incident->getLocationId() !== $location->getId()) {
            return Response::error(
                'SITE_MISMATCH',
                'No tiene autorización para reabrir incidencias de otra sede.',
                403
            );
        }

        // 4. Validar motivo descriptivo de la reapertura
        $reason = trim((string)($request->getBodyParam('reopen_reason') ?? $request->getBodyParam('reason') ?? $request->getBodyParam('description') ?? ''));
        if ($reason === '') {
            return Response::error(
                'MISSING_REOPEN_REASON',
                'El motivo descriptivo de la reapertura es obligatorio.',
                400
            );
        }

        if (mb_strlen($reason) < 5) {
            return Response::error(
                'REOPEN_REASON_TOO_SHORT',
                'El motivo de la reapertura debe contener al menos 5 caracteres descriptivos.',
                422
            );
        }

        // 5. Ejecutar la reapertura en el repositorio aplicando las reglas de negocio
        try {
            $reopenedIncident = $this->incidentRepo->reopen((int)$incident->getId(), $reason);
        } catch (InvalidTransitionException $e) {
            return Response::error(
                'INVALID_TRANSITION',
                $e->getMessage(),
                422,
                [
                    'current_status' => $e->getFromStatus()?->value,
                    'required_status' => IncidentStatus::RESOLVED->value,
                ]
            );
        } catch (WarrantyExpiredException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode()
            );
        } catch (ChronicIncidentException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'reopen_count' => $e->getReopenCount(),
                    'tag' => 'Avería Crónica',
                ]
            );
        }

        // 6. Evento inmutable de auditoría del cambio de estado (EARS 5.1.2 de
        //    metrics_audit_spec.md): la reapertura es un cambio de estado del ticket
        //    y queda auditada con la sede como actor, el estado previo (RESOLVED con
        //    su técnico saliente) y el resultante (REOPENED desasignado). Se emite
        //    tras el commit de la reapertura, igual que INCIDENT_ASSIGNED en el triaje.
        $siteName = trim((string)$location->getName());
        $this->audit()->logTicketEvent(
            ticketId: (int)$reopenedIncident->getId(),
            action: 'REOPEN_TICKET',
            user: [
                'id' => null,
                'role' => 'SITE_MANAGER',
                'name' => $siteName !== '' ? 'Responsable de Sede · ' . $siteName : 'Responsable de Sede',
            ],
            previousState: [
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $incident->getAssignedTechnicianId(),
                'resolved_at' => $incident->getResolvedAt(),
            ],
            newState: [
                'status' => $reopenedIncident->getStatus()->value,
                'assigned_technician_id' => $reopenedIncident->getAssignedTechnicianId(),
                'reopen_reason' => $reopenedIncident->getReopenReason(),
                'reopened_at' => $reopenedIncident->getReopenedAt(),
            ],
            metadata: [
                'ticket_code' => $reopenedIncident->getTicketCode(),
                'reopen_count' => $this->incidentRepo->countReopenEvents((int)$reopenedIncident->getId()),
            ]
        );

        // 7. Respuesta exitosa HTTP 200 OK con payload según contrato API
        return Response::json(
            [
                'ticket_code' => $reopenedIncident->getTicketCode(),
                'status' => $reopenedIncident->getStatus()->value,
                'status_label' => 'Reabierta',
                'status_canonical' => $reopenedIncident->getStatus()->value,
                'assigned_technician_id' => $reopenedIncident->getAssignedTechnicianId(),
                'reopened_at' => $reopenedIncident->getReopenedAt(),
                'reopen_reason' => $reopenedIncident->getReopenReason(),
                'incident' => $this->sanitizeIncidentForSite($reopenedIncident->toArray()),
            ],
            200,
            'Incidencia reabierta con éxito y enviada a triaje de coordinación.'
        );
    }

    /**
     * Sanitiza recursivamente cualquier estructura de datos de incidencia destinada al Responsable de Sede,
     * garantizando el blindaje constitucional de datos (Art. V.4 y RF-REP-10).
     * 
     * Elimina de forma estricta:
     * - Piezas solicitadas (pending_parts_reason, spare_parts, spare_part_requests).
     * - Piezas sustituidas (replaced_parts, incident_replaced_parts).
     * - Costes económicos (unit_cost_snapshot, total_cost_snapshot, total_parts_cost, reference_cost, costs).
     * - Destinos de componentes (old_part_destination, desguace, taller).
     * - Teléfonos personales de técnicos y notas internas de taller.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|null
     */
    public function sanitizeIncidentForSite(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $forbiddenExactKeys = [
            'pending_parts_reason',
            'spare_parts',
            'spare_part_requests',
            'replaced_parts',
            'incident_replaced_parts',
            'unit_cost_snapshot',
            'total_cost_snapshot',
            'total_parts_cost',
            'reference_cost',
            'costs',
            'cost',
            'old_part_destination',
            'part_code',
            'part_name',
            'technician_phone',
            'internal_notes',
            'notes_taller',
        ];

        foreach ($forbiddenExactKeys as $key) {
            unset($data[$key]);
        }

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                if (preg_match('/(spare_part|replaced_part|part_code|unit_cost|total_cost|cost_snapshot|reference_cost|old_part_dest)/i', $key)) {
                    unset($data[$key]);
                    continue;
                }
            }
            if (is_array($value)) {
                $data[$key] = $this->sanitizeIncidentForSite($value);
            }
        }

        return $data;
    }
}
