<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use DomainException;
use VendGuard\Application\Service\OfflineSyncService;
use VendGuard\Core\Domain\Exception\IncidentNotFoundException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\OfflineSyncValidationException;
use VendGuard\Core\Domain\Model\OfflineSyncMutation;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\OfflineSyncMetadata;
use VendGuard\Infrastructure\Repository\PdoOfflineSyncMutationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianOfflineSyncController
 *
 * Superficie REST del Modo Sin Conexión del técnico de campo (Módulo 24,
 * RF-02.1, RF-05.3, RF-06.4; contratos §6.1, §6.3 y §6.4).
 *
 * Endpoints registrados bajo InternalAuthMiddleware(TECHNICIAN):
 * - GET  /api/technician/offline/bootstrap
 * - POST /api/technician/offline/conflicts/dump
 * - POST /api/technician/offline/deactivated-account-dump
 *
 * Este controlador no decide reglas de negocio: lee la identidad del token, valida
 * el contrato de entrada y delega en `OfflineSyncService`. Las tres utilidades
 * estáticas (`readMetadata`, `respond` y `conflict`) existen para que los
 * controladores de parada enriquezcan sus acciones sin duplicar la traducción del
 * contrato: el envoltorio de la respuesta y la diferencia entre un rechazo online
 * (403/422) y uno de cola (`409`, contrato §7) viven en un solo sitio.
 *
 * El volcado por baja de cuenta exige el token de un técnico cuya cuenta ya está
 * desactivada (`QA 5`): el middleware sólo acredita la firma y el rol, y es aquí
 * donde se comprueba `users.is_active` para responder `409` mientras siga activa.
 *
 * Dogma Vanilla: PHP 8.2+ puro, POO limpia, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class TechnicianOfflineSyncController
{
    /** Mensaje de cierre cuando la cuarentena sanitaria se mantiene (Art. II). */
    public const SANITARY_QUARANTINE_RETAINED_MESSAGE = 'ALERTA SANITARIA (Art. II): la avería se ha '
        . 'cerrado, pero la máquina de alimentos perecederos permanece en CUARENTENA sanitaria. '
        . 'No vuelve al servicio hasta completar el protocolo de higienización.';

    private ?OfflineSyncService $offlineSync;
    private ?UserRepositoryInterface $userRepo;
    private ?LocalFileUploader $fileUploader;

    public function __construct(
        ?OfflineSyncService $offlineSync = null,
        ?UserRepositoryInterface $userRepo = null,
        ?LocalFileUploader $fileUploader = null
    ) {
        // Los tres colaboradores se resuelven bajo demanda: su instancia por defecto
        // abre conexión PDO y el controlador también se construye en suites unitarias.
        $this->offlineSync = $offlineSync;
        $this->userRepo = $userRepo;
        $this->fileUploader = $fileUploader;
    }

    /**
     * GET /api/technician/offline/bootstrap
     *
     * Paquete consolidado de la ruta del técnico autenticado para poblar o refrescar
     * `route_snapshots` en `IndexedDB` (RF-02.1, contrato §6.1).
     */
    public function getBootstrap(Request $request): Response
    {
        $technicianId = $this->technicianId($request);
        if ($technicianId === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        $user = $request->getAttribute('authenticated_user');
        if (!$user instanceof User) {
            $user = $this->users()->findById($technicianId);
        }

        // Sin nombre ni correo reales el paquete local no puede acreditar quién es el
        // titular de la copia: un dispositivo compartido acabaría mezclando cuentas.
        if ($user === null || trim($user->getName()) === '' || trim($user->getEmail()) === '') {
            return Response::error(
                'UNAUTHORIZED',
                'La cuenta del técnico no permite generar un paquete de ruta sin conexión.',
                401
            );
        }

        $snapshot = $this->service()->getBootstrapSnapshot(
            $technicianId,
            $user->getName(),
            $user->getEmail()
        );

        return Response::json(
            $snapshot->toArray(),
            200,
            'Paquete de ruta sin conexión generado correctamente.'
        );
    }

    /**
     * POST /api/technician/offline/conflicts/dump
     *
     * Archiva en un solo toque el trabajo de una parada rechazada como Nota Interna de
     * Taller, sin alterar el estado ni la titularidad (RF-05.3, `QA 1`, `QA 2`, Art. III.3).
     * Acepta `application/json` y `multipart/form-data`; la foto opcional se valida por
     * *magic bytes* y por el límite de 5 MB antes de escribir nada (Art. V.5).
     */
    public function dumpConflict(Request $request): Response
    {
        $technicianId = $this->technicianId($request);
        if ($technicianId === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        $metadata = self::readMetadata($request);
        if ($metadata instanceof Response) {
            return $metadata;
        }

        $body = $request->getParsedBody();
        $stopType = strtoupper(trim((string)($body['stop_type'] ?? '')));
        $operationType = strtoupper(trim((string)($body['operation_type'] ?? '')));
        $stopId = isset($body['stop_id']) && is_numeric($body['stop_id']) ? (int)$body['stop_id'] : 0;
        $workSummary = $body['work_summary'] ?? [];

        $photoPath = null;
        $photo = $request->getFile('photo');
        if ($photo !== null) {
            try {
                $photoPath = $this->uploader()->upload($photo);
            } catch (InvalidUploadException $e) {
                return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
            }
        }

        try {
            $result = $this->service()->dumpConflict(
                $technicianId,
                $metadata,
                $stopType,
                $stopId,
                $operationType,
                (string)($body['conflict_reason'] ?? ''),
                is_array($workSummary) ? $workSummary : [],
                $photoPath
            );
        } catch (OfflineSyncValidationException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        } catch (IncidentNotFoundException $e) {
            return Response::error(
                'INCIDENT_NOT_FOUND',
                "No se encontró ninguna incidencia con ID {$stopId}.",
                404
            );
        } catch (DomainException $e) {
            // La única guarda de dominio que puede escapar aquí para una orden
            // preventiva es su inexistencia (el contrato §6.3 exige 404 explícito).
            if ($stopType === OfflineSyncMutation::STOP_TYPE_PREVENTIVE_ORDER) {
                return Response::error(
                    'PREVENTIVE_ORDER_NOT_FOUND',
                    "No se encontró ninguna orden preventiva con ID {$stopId}.",
                    404
                );
            }

            return Response::error('DOMAIN_ERROR', $e->getMessage(), 400);
        }

        return Response::json($result['payload'], $result['http_status']);
    }

    /**
     * POST /api/technician/offline/deactivated-account-dump
     *
     * Libera el dispositivo de un técnico dado de baja volcando su cola pendiente como
     * constancia de auditoría (RF-06.4, `QA 5`). El token sigue siendo válido —la firma
     * la verificó el middleware—; lo que se comprueba aquí es que la cuenta ya no esté
     * activa, para no saltarse la sincronización normal mientras siga operativa.
     */
    public function dumpDeactivatedAccount(Request $request): Response
    {
        $technicianId = $this->technicianId($request);
        if ($technicianId === null) {
            return Response::error('UNAUTHORIZED', 'No se pudo identificar al técnico autenticado.', 401);
        }

        $user = $this->users()->findById($technicianId);
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Usuario del token no encontrado o dado de baja.', 401);
        }

        if ($user->getRole() !== UserRole::TECHNICIAN) {
            return Response::error('FORBIDDEN', 'Este recurso exige el rol de Técnico de Campo.', 403);
        }

        if ($user->isActive()) {
            return Response::error(
                OfflineSyncValidationException::ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC,
                'La cuenta sigue activa: use la sincronización normal de la cola.',
                409
            );
        }

        $items = $request->getParsedBody()['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return Response::error(
                OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD,
                'El volcado por baja de cuenta exige un lote `items` con al menos un elemento.',
                422
            );
        }

        try {
            $result = $this->service()->dumpDeactivatedAccountQueue($technicianId, array_values($items));
        } catch (OfflineSyncValidationException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }

        return Response::json($result['payload'], $result['http_status']);
    }

    // ---------------------------------------------------------------------
    // Utilidades compartidas con los controladores de parada
    // ---------------------------------------------------------------------

    /**
     * Metadatos de la operación encolada, o la respuesta de error del contrato §7.
     *
     * Sin `client_mutation_id` devuelve `null`: es una petición online ordinaria y el
     * servicio no escribe nada en el libro de sincronización.
     */
    public static function readMetadata(Request $request): OfflineSyncMetadata|Response|null
    {
        try {
            return OfflineSyncMetadata::fromArray($request->getParsedBody(), $request->getHeaders());
        } catch (OfflineSyncValidationException $e) {
            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Envuelve el resultado del servicio en la respuesta canónica.
     *
     * @param array{
     *   http_status: int,
     *   payload: array<string, mixed>,
     *   sanitary_quarantine_retained?: bool
     * } $result
     */
    public static function respond(array $result, ?string $message = null): Response
    {
        return Response::json($result['payload'], (int)$result['http_status'], $message);
    }

    /**
     * Rechazo de una parada: `409` si venía de la cola sin conexión, y el código
     * original del flujo online en caso contrario (contrato §7).
     */
    public static function conflict(
        ?OfflineSyncMetadata $metadata,
        string $offlineCode,
        string $message,
        string $onlineCode,
        int $onlineStatus
    ): Response {
        if ($metadata === null) {
            return Response::error($onlineCode, $message, $onlineStatus);
        }

        return Response::error($offlineCode, $message, 409);
    }

    // ---------------------------------------------------------------------
    // Colaboradores y auxiliares
    // ---------------------------------------------------------------------

    private function service(): OfflineSyncService
    {
        return $this->offlineSync ??= new OfflineSyncService(new PdoOfflineSyncMutationRepository());
    }

    private function users(): UserRepositoryInterface
    {
        return $this->userRepo ??= new PdoUserRepository();
    }

    private function uploader(): LocalFileUploader
    {
        return $this->fileUploader ??= new LocalFileUploader();
    }

    private function technicianId(Request $request): ?int
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return (int)$user->getId();
        }

        $userId = $request->getAttribute('user_id');

        return ($userId !== null && is_numeric($userId)) ? (int)$userId : null;
    }
}
