<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\DTO\RefundReceiptDTO;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\DuplicateRefundClaimException;
use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Application\Service\QrReportService;
use VendGuard\Application\Service\QrScanService;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * QrScanController
 * 
 * Controlador HTTP público para el flujo de escaneo QR móvil y reporte rápido de averías.
 * Procesa accesos contextuales por enlace QR sin requerir inicio de sesión previo (RF-03).
 * 
 * Endpoints gestionados:
 * - GET  /api/qr/scan/{code}  (Resolución contextual de estado y blindaje de privacidad Art. V.4)
 * - POST /api/qr/report       (Envío de reporte con gestión de concurrencia atómica EARS 4.5
 *                             y captura opcional de solicitud de reintegro, T-REF-09)
 * 
 * ## Captura de reintegro en el mismo envío (RF-REF-01/02)
 * El consumidor que pierde dinero está de pie delante de la máquina y no va a
 * volver: por eso la reclamación de reintegro viaja en el mismo POST que la
 * avería y devuelve en el acto el PIN de recogida y el enlace de seguimiento.
 * 
 * El bloque de reintegro es ENTERAMENTE opcional: sin `refund_requested`, o con
 * el valor explícito en falso, el endpoint se comporta exactamente igual que
 * antes de esta ampliación.
 * 
 * ## Por qué se valida antes de escribir
 * El expediente de reintegro necesita el `incident_id`, que sólo existe después
 * de registrar el aviso. Si un importe fuera inválido, validarlo después dejaría
 * una avería registrada sin reclamación detrás, que es exactamente el peor
 * resultado para el consumidor. Por eso todo el bloque se valida ANTES de tocar
 * la base de datos y sólo después de superarlo se crea el expediente.
 * 
 * ## Privacidad del resguardo (Art. V.4)
 * `RefundReceiptDTO` decide qué sale del servidor: el PIN y el enlace, nunca el
 * IBAN ni el teléfono de Bizum ni el nombre del reclamante, aunque este los acaba
 * de enviar. Este punto es público y su respuesta puede acabar en un log de proxy
 * o en una captura de pantalla.
 * 
 * Cumple con Dogma Vanilla y los Artículos II, IV y V de la Constitución de VendGuard.
 */
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;

class QrScanController
{
    /**
     * Código de error cuando faltan los datos imprescindibles del reclamante.
     *
     * No figura en el catálogo de contratos §5, que sólo tipifica los fallos de
     * formato; se declara aquí y conviene incorporarlo al catálogo en la siguiente
     * revisión, igual que se hizo con `INVALID_TRACKING_TOKEN` en T-REF-08.
     */
    public const ERROR_MISSING_REFUND_DATA = 'MISSING_REFUND_DATA';

    /** Falta el importe reclamado, o no es un número. */
    public const ERROR_MISSING_REFUND_AMOUNT = 'MISSING_REFUND_AMOUNT';

    /** Falta el instrumento de pago que exige la vía elegida (Bizum o IBAN). */
    public const ERROR_MISSING_REFUND_PAYMENT_DATA = 'MISSING_REFUND_PAYMENT_DATA';

    /** La vía de compensación no pertenece al catálogo cerrado del dominio. */
    public const ERROR_INVALID_COMPENSATION_METHOD = 'INVALID_COMPENSATION_METHOD';

    /**
     * Aviso que RF-REF-03 (EARS Excepción) obliga a mostrar cuando la reclamación
     * supera el tope: no basta con rechazar, hay que reenviar al consumidor a la
     * vía de atención al cliente.
     */
    public const MESSAGE_OVER_TELEPHONE_ADVICE =
        'Para importes superiores a 50,00 €, contacte con el departamento de atención al cliente de VendGuard.';

    /** Valores aceptados como "sí" en un cuerpo JSON o de formulario. */
    private const TRUTHY_VALUES = ['1', 'true', 'yes', 'si', 'sí'];

    private QrScanService $qrScanService;
    private QrReportService $qrReportService;
    private LocalFileUploader $fileUploader;
    private MachineRepositoryInterface $machineRepo;
    private ?PreventiveSettingsRepositoryInterface $settingsRepo;
    private RefundManagementService $refundService;
    private IbanValidationService $ibanValidator;

    public function __construct(
        ?QrScanService $qrScanService = null,
        ?QrReportService $qrReportService = null,
        ?LocalFileUploader $fileUploader = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?PreventiveSettingsRepositoryInterface $settingsRepo = null,
        ?RefundManagementService $refundService = null,
        ?IbanValidationService $ibanValidator = null
    ) {
        $this->settingsRepo = $settingsRepo;
        $this->qrScanService = $qrScanService ?? new QrScanService(null, null, null, $settingsRepo);
        $this->qrReportService = $qrReportService ?? new QrReportService();
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->ibanValidator = $ibanValidator ?? new IbanValidationService();
        $this->refundService = $refundService
            ?? new RefundManagementService(new PdoRefundRequestRepository(), $this->ibanValidator);
    }

    private function getSettingsRepo(): ?PreventiveSettingsRepositoryInterface
    {
        if ($this->settingsRepo !== null) {
            return $this->settingsRepo;
        }

        try {
            return new PdoPreventiveSettingsRepository();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * GET /api/qr/scan/{code}
     * 
     * Resuelve contextualmente el estado de una máquina tras el escaneo de su código QR.
     * Retorna CAN_REPORT, ACTIVE_INCIDENT (con privacidad blindada) o UNDER_WARRANTY.
     * Si la máquina está dada de baja o no existe, retorna 404 Not Found (EARS 5.2).
     */
    public function scan(Request $request): Response
    {
        $codeParam = $request->getRouteParam('code') ?? $request->getQuery('code') ?? $request->getQuery('qr');

        if ($codeParam === null || trim((string)$codeParam) === '') {
            return Response::error(
                'MISSING_MACHINE_CODE',
                'El código de máquina es obligatorio en la ruta.',
                400
            );
        }

        $machineCode = trim((string)$codeParam);
        $siteCode = $request->getQuery('site') ?? $request->getQuery('site_code');
        $cleanSiteCode = $siteCode !== null && trim((string)$siteCode) !== '' ? trim((string)$siteCode) : null;

        try {
            $data = $this->qrScanService->resolve($machineCode, $cleanSiteCode);
            return Response::json($data, 200);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_MACHINE_CODE',
                $e->getMessage(),
                400
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar el código QR.',
                500
            );
        }
    }

    /**
     * POST /api/qr/report
     * 
     * Registra el reporte de avería enviado por el usuario desde el formulario QR móvil.
     * Si detecta una avería activa preexistente o concurrente, la fusiona amistosamente (EARS 4.5).
     *
     * Si el cuerpo trae `refund_requested`, además abre el expediente de reintegro
     * y devuelve su resguardo con PIN y enlace de seguimiento (RF-REF-01/02).
     */
    public function report(Request $request): Response
    {
        // 1. Validar código de máquina obligatorio
        $machineCode = $request->getBodyParam('machine_code');
        if ($machineCode === null || trim((string)$machineCode) === '') {
            return Response::error(
                'MISSING_MACHINE_CODE',
                'El código de la máquina es obligatorio.',
                422
            );
        }

        // 1.1 Bloqueo estricto de reporte en máquinas inactivas o retiradas (RF-04, EARS 2.12)
        $cleanCode = strtoupper(trim((string)$machineCode));
        $machine = $this->machineRepo->findByCode($cleanCode, withIncident: false, allowDeleted: true);
        if ($machine !== null && !$machine->isActive()) {
            return Response::error(
                'MACHINE_INACTIVE',
                'Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.',
                422
            );
        }

        // 1.2 Bloqueo de reporte en máquinas en Cuarentena Sanitaria o Pausa Estacional (Art. II, EARS 7.3)
        $settingsRepo = $this->getSettingsRepo();
        if ($machine !== null && $settingsRepo !== null) {
            $settings = $settingsRepo->getMachineSettings($machine->getId());
            if ($settings !== null) {
                if (($settings['sanitary_status'] ?? '') === 'QUARANTINE') {
                    return Response::error(
                        'MACHINE_IN_QUARANTINE',
                        'Esta máquina se encuentra en cuarentena sanitaria preventiva. No se admiten nuevos reportes mientras se encuentre fuera de servicio por control higiénico-sanitario.',
                        422
                    );
                }
                if (!empty($settings['is_seasonal_pause']) || ($settings['sanitary_status'] ?? '') === 'SEASONAL_PAUSE') {
                    return Response::error(
                        'MACHINE_IN_SEASONAL_PAUSE',
                        'Esta máquina se encuentra en pausa estacional programada. No se admiten reportes durante el periodo de cierre vacacional.',
                        422
                    );
                }
            }
        }

        // 2. Validar categoría de avería
        $category = $request->getBodyParam('category');
        if ($category === null || trim((string)$category) === '') {
            return Response::error(
                'MISSING_CATEGORY',
                'La categoría de avería es obligatoria.',
                422
            );
        }

        // 3. Validar descripción
        $description = trim((string)($request->getBodyParam('description') ?? ''));
        if ($description === '') {
            return Response::error(
                'MISSING_DESCRIPTION',
                'La descripción de la avería es obligatoria.',
                422
            );
        }

        if (mb_strlen($description) < 5) {
            return Response::error(
                'DESCRIPTION_TOO_SHORT',
                'La descripción de la avería debe contener al menos 5 caracteres.',
                422
            );
        }

        // 4. Parámetros opcionales del informador
        $reporterName = $request->getBodyParam('reporter_name');
        $reporterPhone = $request->getBodyParam('reporter_phone');
        $retainedMoney = $request->getBodyParam('retained_money_amount');

        // 5. Bloque de reintegro. Se valida ANTES de cualquier efecto: ni una
        // fila ni un fichero subido. Un expediente de reintegro que colgara de
        // una avería ya creada dejaría al consumidor sin respuesta, que es
        // exactamente el peor resultado posible (ver cabecera de la clase).
        $refundClaim = null;
        if ($this->wantsRefund($request)) {
            $refundClaim = $this->readRefundClaim($request);
            if ($refundClaim instanceof Response) {
                return $refundClaim;
            }
        }

        // 6. Manejo de archivo adjunto de evidencia (fotografía)
        $photoPath = null;
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        if ($photoFile !== null && isset($photoFile['error']) && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['tmp_name'])) {
            try {
                $photoPath = $this->fileUploader->upload($photoFile);
            } catch (InvalidUploadException $e) {
                return Response::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    400
                );
            }
        } elseif ($request->getBodyParam('photo_path') !== null && trim((string)$request->getBodyParam('photo_path')) !== '') {
            $photoPath = trim((string)$request->getBodyParam('photo_path'));
        }

        $payload = [
            'machine_code' => $machineCode,
            'category' => $category,
            'description' => $description,
            'reporter_name' => $reporterName,
            'reporter_phone' => $reporterPhone,
            'retained_money_amount' => $retainedMoney,
            'photo_path' => $photoPath,
        ];

        try {
            $result = $this->qrReportService->report($payload);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_PAYLOAD',
                $e->getMessage(),
                422
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar el reporte de avería.',
                500
            );
        }

        // 7. Expediente de reintegro, ya con el identificador de la avería real.
        // Un segundo consumidor sobre el mismo ticket obtiene su propio
        // expediente (RF-REF-08), nunca comparte PIN ni token con el primero.
        if ($refundClaim !== null) {
            $receipt = $this->openRefundCase($refundClaim, $result, $machine);
            if ($receipt instanceof Response) {
                return $receipt;
            }

            $result['refund'] = $receipt;
        }

        $statusCode = ($result['merged'] ?? false) ? 200 : 201;

        return Response::json($result, $statusCode);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals: captura de reintegro
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Whether the citizen asked for their money back in this submission.
     *
     * The flag arrives from a JSON body or a multipart form, so it may be a real
     * boolean, the number 1 or the string "true". Anything that is not
     * explicitly affirmative, `"false"` included, leaves the report untouched.
     */
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
     * Validates the refund block and normalises it for the case opening.
     *
     * Every rule of RF-REF-01 is enforced here rather than in the domain service
     * so that a malformed claim is refused with the documented `422` codes while
     * the database is still untouched.
     *
     * The claimant identity accepts both spellings on purpose: the contract
     * (§4.1.1) names them `contact_name` / `contact_phone`, while the QR form
     * that has been live since T-QR-12 sends `reporter_name` / `reporter_phone`.
     *
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
        // 1. Importe dentro del rango antifraude (RF-REF-03).
        $rawAmount = $request->getBodyParam('claimed_amount');
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

        // 2. Vía de compensación del catálogo cerrado.
        $rawMethod = strtoupper(trim((string)($request->getBodyParam('compensation_method') ?? '')));
        $method = CompensationMethod::tryFrom($rawMethod);
        if ($method === null) {
            return Response::error(
                self::ERROR_INVALID_COMPENSATION_METHOD,
                'Indique cómo quiere recibir su dinero: recogida en la sede, Bizum o transferencia bancaria.',
                422
            );
        }

        // 3. Identidad y canal de contacto del reclamante.
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

        // 4. Instrumento de pago exigido por la vía elegida.
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
     * Opens the refund case for an already registered incident and returns the
     * receipt payload.
     *
     * @param array<string, mixed> $claim Validated claim from `readRefundClaim()`.
     * @param array<string, mixed> $result Result of the QR report.
     * @return array<string, mixed>|Response The receipt, or an error response.
     */
    private function openRefundCase(array $claim, array $result, ?object $machine): Response|array
    {
        $incidentId = (int)($result['incident_id'] ?? 0);

        // Unreachable while the report above guarantees both values; kept as a
        // guard so a silent null here can never be persisted as case id 0.
        if ($incidentId < 1 || !$machine instanceof \VendGuard\Core\Domain\Model\Machine) {
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
                locationId: (int)$machine->getLocationId(),
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
            // RF-REF-11: se rechaza la segunda reclamación del mismo consumidor,
            // pero el token viaja en `details` para que no pierda el acceso al
            // expediente que ya tiene en marcha.
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
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Su aviso se ha registrado, pero no ha sido posible abrir la solicitud de reintegro.',
                500
            );
        }

        return RefundReceiptDTO::fromRefundRequest($case)->toArray();
    }

    /**
     * First non-blank candidate among the accepted aliases of a field.
     */
    private function firstFilledValue(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
