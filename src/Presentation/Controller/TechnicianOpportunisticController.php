<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Application\Service\OpportunisticIncidentService;
use VendGuard\Core\Domain\Exception\ActiveInterventionConflictException;
use VendGuard\Core\Domain\Exception\CashRefundPrivacyViolationException;
use VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\IncidentAlreadyInProgressByOtherTechnicianException;
use VendGuard\Core\Domain\Exception\IncidentNotFoundException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\MachineInactiveException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\TicketCodeCollisionException;
use VendGuard\Core\Domain\Exception\TakeoverConfirmationRequiredException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\OpportunisticIncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Storage\FileStorageCompensator;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianOpportunisticController
 *
 * Superficie REST de la **Avería Oportunista In Situ** para el técnico de ruta
 * (Módulo 16, RF-01 a RF-06).
 *
 * Expone las tres operaciones que convierten «estar delante de una máquina» en
 * un hecho de negocio, y ninguna más:
 *
 *  - `GET  /api/technician/locations/{location_id}/opportunistic-machines`
 *    (catálogo de la sede habilitada, RF-01/RF-03).
 *  - `POST /api/technician/opportunistic/incidents` (Flujo A, RF-04).
 *  - `POST /api/technician/opportunistic/incidents/{id}/assume` (Flujo B, RF-05).
 *
 * POR QUÉ ESTA CLASE ES DELGADA
 *
 *   Todas las decisiones —habilitación con su ventana de gracia, coexistencia
 *   entre sedes, salvaguarda de cadena de frío, coherencia modalidad ↔ submotivo,
 *   bloqueo del Art. V.4 y transiciones del Flujo B— viven en
 *   `OpportunisticIncidentService` y en las políticas puras de `Core\Service`.
 *   Aquí solo se hace lo que solo la capa HTTP sabe hacer: leer la identidad del
 *   token, validar identificadores de ruta, recibir el adjunto con
 *   `LocalFileUploader` (Art. V.5) y traducir cada excepción tipada al código de
 *   error exacto del contrato.
 *
 *   El controlador NO toca el cuerpo financiero: el rechazo del Art. V.4 se
 *   adelanta aquí, antes de escribir ni un byte en disco, y el servicio vuelve a
 *   comprobarlo sobre el payload crudo. Dos puertas para el mismo mandato es
 *   deliberado: la primera evita subir una foto inútil; la segunda garantiza que
 *   ningún camino futuro pueda saltarse la regla.
 *
 *   La fotografía se escribe bajo la transaccionalidad compensatoria del fichero
 *   (EARS-05.3): si el alta falla —duplicado concurrente, conflicto de sede o
 *   error del motor—, el fichero recién escrito se retira en lugar de quedar
 *   huérfano.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class TechnicianOpportunisticController
{
    private ?OpportunisticIncidentService $service;
    private ?IncidentRepositoryInterface $incidentRepo;
    private ?MachineRepositoryInterface $machineRepo;
    private ?LocationRepositoryInterface $locationRepo;
    private ?UserRepositoryInterface $userRepo;
    private ?LocalFileUploader $fileUploader;
    private ?FileStorageCompensator $compensator;
    private ?AuditLogger $auditLogger;
    private ?NotificationOutboxEnqueuerService $notificationEnqueuer;
    private ?TransactionManagerInterface $transactionManager;

    public function __construct(
        ?OpportunisticIncidentService $service = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocalFileUploader $fileUploader = null,
        ?FileStorageCompensator $compensator = null,
        ?AuditLogger $auditLogger = null,
        ?NotificationOutboxEnqueuerService $notificationEnqueuer = null,
        ?TransactionManagerInterface $transactionManager = null
    ) {
        $this->service = $service;
        $this->incidentRepo = $incidentRepo;
        $this->machineRepo = $machineRepo;
        $this->locationRepo = $locationRepo;
        $this->userRepo = $userRepo;
        $this->fileUploader = $fileUploader;
        $this->compensator = $compensator;
        $this->auditLogger = $auditLogger;
        $this->notificationEnqueuer = $notificationEnqueuer;
        $this->transactionManager = $transactionManager;
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/technician/locations/{location_id}/opportunistic-machines
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Catálogo de máquinas operativas de una sede habilitada (§4.2 del contrato).
     *
     * Respuestas: 200 con la sede, las máquinas con su `opportunistic_action`, el
     * conflicto de coexistencia y la advertencia sanitaria; 400 si el
     * identificador de ruta no es un entero positivo; 401 sin sesión de técnico
     * (middleware); 403 si la sede no está habilitada (RF-01.1); 404 si la sede
     * no existe o está inactiva.
     */
    public function getLocationCatalog(Request $request): Response
    {
        $technician = $this->authenticatedTechnician($request);
        if ($technician instanceof Response) {
            return $technician;
        }

        $locationId = $this->positiveRouteIdentifier($request->getRouteParam('location_id'));
        if ($locationId === null) {
            return Response::error(
                'INVALID_LOCATION_ID',
                'El identificador de la sede (location_id) debe ser un número entero positivo.',
                400
            );
        }

        try {
            $catalog = $this->service()->getLocationOpportunisticCatalog(
                $technician->getId(),
                $locationId,
                $this->isTruthy($request->getQuery('form_opened_within_grace'))
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }

        return Response::json($catalog->toArray(), 200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/technician/opportunistic/incidents — Flujo A
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Alta Oportunista In Situ (§4.3): abre el parte directamente en curso,
     * asignado al técnico presente y etiquetado como `NEW`.
     *
     * Admitida tanto en `application/json` como en `multipart/form-data` con
     * fotografía opcional. La subida se valida con `LocalFileUploader` (≤ 5 MB,
     * JPEG/PNG/WebP y cabecera binaria real, Art. V.5) y se compensa si el alta
     * no llega a persistirse.
     *
     * Respuestas: 201 con el expediente creado; 400 sin sesión/identificador
     * válido; 403 sede no habilitada; 404 máquina inexistente; 409 por
     * coexistencia en otra sede, ticket concurrente o falta de confirmación
     * sanitaria; 413/422 por el adjunto; 422 por validación o intento de datos
     * bancarios (Art. V.4).
     */
    public function createIncident(Request $request): Response
    {
        $technician = $this->authenticatedTechnician($request);
        if ($technician instanceof Response) {
            return $technician;
        }

        $body = $request->getParsedBody();

        // Puerta temprana del Art. V.4: ni siquiera se escribe la fotografía.
        $forbidden = [];
        foreach (CashRefundPrivacyViolationException::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $forbidden[] = $field;
            }
        }
        if ($forbidden !== []) {
            $violation = new CashRefundPrivacyViolationException($forbidden);

            return $this->failure($violation);
        }

        [$photoPath, $uploadFailure] = $this->storeOptionalPhoto($request);
        if ($uploadFailure instanceof Response) {
            return $uploadFailure;
        }

        if ($photoPath !== null) {
            $body['photo_path'] = $photoPath;
        }

        try {
            $result = $this->compensator()->executeWithCompensatingFile(
                $photoPath ?? '',
                fn (): array => $this->service()->createOpportunisticIncident($technician, $body)
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }

        return Response::json($result, 201, 'Avería oportunista registrada e iniciada en curso.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/technician/opportunistic/incidents/{id}/assume — Flujo B
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Asunción y Reapertura Oportunista In Situ (§4.4).
     *
     * Devuelve 200 con el expediente asumido y 201 cuando la 3.ª reincidencia
     * sustituyó atómicamente el ticket crónico por su sucesor limpio.
     *
     * Respuestas: 400 si el identificador no es válido; 401/403 según sesión y
     * habilitación de sede; 404 si el expediente no existe; 409 por traspaso sin
     * confirmación, intervención de otro técnico en curso, coexistencia en otra
     * sede o advertencia sanitaria; 422 por estado no asumible, garantía vencida,
     * motivo de reincidencia insuficiente o datos prohibidos del Art. V.4.
     */
    public function assumeIncident(Request $request): Response
    {
        $technician = $this->authenticatedTechnician($request);
        if ($technician instanceof Response) {
            return $technician;
        }

        $incidentId = $this->positiveRouteIdentifier($request->getRouteParam('id'));
        if ($incidentId === null) {
            return Response::error(
                'INVALID_INCIDENT_ID',
                'El identificador del expediente debe ser un número entero positivo.',
                400
            );
        }

        try {
            $result = $this->service()->assumeOpportunisticIncident(
                $technician,
                $incidentId,
                $request->getParsedBody()
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }

        $chronicReplacement = ($result['chronic_replacement_created'] ?? false) === true;

        return Response::json(
            $result,
            $chronicReplacement ? 201 : 200,
            $chronicReplacement
                ? 'Avería Crónica sustituida por un expediente sucesor limpio, ya en curso.'
                : 'Incidencia asumida e iniciada en curso.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // colaboradores resueltos bajo demanda
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Caso de uso oportunista, compuesto con los mismos colaboradores que el
     * resto de la capa de presentación para no abrir conexiones duplicadas.
     */
    private function service(): OpportunisticIncidentService
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $incidents = $this->incidents();

        return $this->service = new OpportunisticIncidentService(
            opportunistic: $incidents instanceof OpportunisticIncidentRepositoryInterface
                ? $incidents
                : new PdoIncidentRepository(),
            machines: $this->machines(),
            locations: $this->locations(),
            incidents: $incidents,
            transactions: $this->transactions(),
            auditLogger: $this->audit(),
            notifications: $this->notifications(),
            pauses: new IncidentPauseService(incidentRepo: $incidents, notificationEnqueuer: $this->notifications())
        );
    }

    private function incidents(): IncidentRepositoryInterface
    {
        return $this->incidentRepo ??= new PdoIncidentRepository();
    }

    private function machines(): MachineRepositoryInterface
    {
        return $this->machineRepo ??= new PdoMachineRepository();
    }

    private function locations(): LocationRepositoryInterface
    {
        return $this->locationRepo ??= new PdoLocationRepository();
    }

    private function users(): UserRepositoryInterface
    {
        return $this->userRepo ??= new PdoUserRepository();
    }

    private function transactions(): TransactionManagerInterface
    {
        return $this->transactionManager ??= new PdoTransactionManager();
    }

    private function audit(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new PdoAuditLogRepository());
    }

    private function notifications(): ?NotificationOutboxEnqueuerService
    {
        return $this->notificationEnqueuer ??= NotificationOutboxEnqueuerFactory::createOrNull();
    }

    private function fileUploader(): LocalFileUploader
    {
        return $this->fileUploader ??= new LocalFileUploader();
    }

    private function compensator(): FileStorageCompensator
    {
        return $this->compensator ??= new FileStorageCompensator($this->fileUploader());
    }

    // ─────────────────────────────────────────────────────────────────────
    // identidad, adjunto y traducción de errores
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Técnico autenticado por el middleware, o 401 si la petición llegó sin
     * contexto (defensa en profundidad para invocaciones directas al controlador).
     */
    private function authenticatedTechnician(Request $request): User|Response
    {
        $authenticated = $request->getAttribute('authenticated_user');
        if ($authenticated instanceof User) {
            return $authenticated;
        }

        $technicianId = $request->getAttribute('user_id');
        if (!is_numeric($technicianId)) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        $technician = $this->users()->findById((int)$technicianId);
        if ($technician === null) {
            return Response::error('UNAUTHORIZED', 'El técnico autenticado ya no existe o está de baja.', 401);
        }

        return $technician;
    }

    /**
     * Sube la fotografía opcional del parte y devuelve su ruta pública.
     *
     * La respuesta de error, si la hay, conserva los datos de texto del
     * formulario (`form_data`) para que el técnico no tenga que redactarlos otra
     * vez, tal y como exige el contrato del Art. V.5.
     *
     * @return array{0: ?string, 1: ?Response}
     */
    private function storeOptionalPhoto(Request $request): array
    {
        $file = $request->getFile('photo');
        if ($file === null) {
            return [null, null];
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            return [null, null];
        }

        try {
            return [$this->fileUploader()->upload($file), null];
        } catch (InvalidUploadException $exception) {
            return [null, Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['form_data' => $this->createFormData($request->getParsedBody())]
            )];
        }
    }

    /**
     * Datos de texto del alta, tal y como llegaron, sin la foto ni datos sensibles.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function createFormData(array $body): array
    {
        return array_intersect_key($body, array_flip([
            'machine_id',
            'category',
            'origin_reason',
            'informant_name',
            'description',
            'confirm_cold_chain_override',
            'form_opened_within_grace',
        ]));
    }

    /**
     * Traducción exacta de las excepciones de dominio al contrato HTTP.
     *
     * El enrutador genérico convierte cualquier `DomainException` en 400; aquí se
     * recupera el código y el estado que la especificación promete, con el
     * contexto que la interfaz necesita para ofrecer la acción siguiente
     * (asumir el ticket concurrente, confirmar el traspaso, aceptar el aviso
     * sanitario…).
     */
    private function failure(Throwable $exception): Response
    {
        if ($exception instanceof CashRefundPrivacyViolationException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getDetails()
            );
        }

        if ($exception instanceof DuplicateIncidentException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                array_merge($exception->getDetails(), [
                    'ticket_code' => $exception->getTicketCode(),
                    'ticket_status' => $exception->getTicketStatus(),
                ])
            );
        }

        // EARS 2.4 · agotados los reintentos, el choque de identidad del expediente
        // es un 409 explícito: el técnico puede reenviar el parte. Sin esta rama la
        // colisión caía en el 500 genérico del final, que ningún reintento recupera.
        if ($exception instanceof TicketCodeCollisionException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getDetails()
            );
        }

        if ($exception instanceof ActiveInterventionConflictException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['active_conflict' => $exception->getConflict()]
            );
        }

        if ($exception instanceof ColdChainRouteWarningConfirmationException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['pending_critical_stops' => $exception->getPendingCriticalStops()]
            );
        }

        if ($exception instanceof TakeoverConfirmationRequiredException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                [
                    'previous_technician_id' => $exception->getPreviousTechnicianId(),
                    'previous_technician_name' => $exception->getPreviousTechnicianName(),
                ]
            );
        }

        if ($exception instanceof IncidentAlreadyInProgressByOtherTechnicianException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                [
                    'technician_id' => $exception->getTechnicianId(),
                    'technician_name' => $exception->getTechnicianName(),
                ]
            );
        }

        if ($exception instanceof LocationNotEnabledForOpportunisticException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['location_id' => $exception->getLocationId()]
            );
        }

        if ($exception instanceof MachineInactiveException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['machine_id' => $exception->getMachineId()]
            );
        }

        if ($exception instanceof MachineNotFoundException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getCode() > 0 ? $exception->getCode() : 404
            );
        }

        if ($exception instanceof LocationNotFoundException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['location_id' => $exception->getLocationId()]
            );
        }

        if ($exception instanceof IncidentNotFoundException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                ['incident_id' => $exception->getIncidentId()]
            );
        }

        if ($exception instanceof WarrantyExpiredException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        }

        if ($exception instanceof InvalidTransitionException) {
            return Response::error(
                'INVALID_STATUS_FOR_ASSUME',
                $exception->getMessage(),
                422,
                [
                    'from_status' => $exception->getFromStatus()?->value,
                    'to_status' => $exception->getToStatus()?->value,
                ]
            );
        }

        if ($exception instanceof InvalidUploadException) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode()
            );
        }

        if ($exception instanceof InvalidArgumentException) {
            return $this->validationFailure($exception->getMessage());
        }

        error_log('[vendguard] Fallo no controlado en la operativa oportunista: ' . $exception);

        return Response::error(
            'INTERNAL_SERVER_ERROR',
            'No se pudo completar la operación oportunista. Inténtelo de nuevo o contacte con Coordinación.',
            500
        );
    }

    /**
     * Traduce los mensajes de validación de los DTO a los códigos cerrados del
     * contrato (§4.3/§4.4), sin exponer detalles internos.
     */
    private function validationFailure(string $message): Response
    {
        $normalized = mb_strtolower($message);

        // El orden importa: el mensaje del motivo de reincidencia también habla de
        // «caracteres reales», y debe resolverse antes que la descripción del parte.
        $code = match (true) {
            str_contains($normalized, 'reincidencia') => 'REOPEN_REASON_TOO_SHORT',
            str_contains($normalized, 'categoría') => 'INVALID_CATEGORY',
            str_contains($normalized, 'submotivo'),
            str_contains($normalized, 'origen') => 'INVALID_ORIGIN_REASON',
            str_contains($normalized, 'descripción'),
            str_contains($normalized, 'caracteres reales') => 'DESCRIPTION_TOO_SHORT',
            str_contains($normalized, 'informante') => 'INVALID_INFORMANT_NAME',
            str_contains($normalized, 'nota interna') => 'TAKEOVER_NOTE_TOO_LONG',
            default => 'INVALID_PAYLOAD',
        };

        return Response::error($code, $message, 422);
    }

    /**
     * Identificador de ruta positivo, o `null` si no lo es.
     */
    private function positiveRouteIdentifier(?string $raw): ?int
    {
        if ($raw === null || !ctype_digit(trim($raw))) {
            return null;
        }

        $value = (int)trim($raw);

        return $value > 0 ? $value : null;
    }

    /**
     * Interpreta banderas de query string con el mismo criterio tolerante del
     * resto de la API (`1`, `true`, `yes`).
     */
    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value)
            && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'si', 'sí'], true);
    }
}
