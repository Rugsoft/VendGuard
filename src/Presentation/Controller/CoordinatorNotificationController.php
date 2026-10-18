<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorNotificationService;
use VendGuard\Core\Domain\Exception\InvalidNotificationOutboxStatusException;
use VendGuard\Core\Domain\Exception\NotificationOutboxItemNotFoundException;
use VendGuard\Core\Domain\Exception\WebhookConfigurationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Core\Service\NotificationTrackingLinkResolver;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorNotificationController — Superficie REST del Centro de
 * Notificaciones para Coordinación (Módulo 14 · T-NOTIF-08).
 *
 * Expone los siete verbos de supervisión del contrato técnico §4.1 a §4.7. El
 * controlador no decide nada: traduce la petición HTTP a la operación del
 * `CoordinatorNotificationService` y devuelve el veredicto con el código de
 * estado y el mensaje que el contrato declara. Toda la autoridad de negocio
 * —destinatario refrescado, estados cancelables, anti-SSRF, timeout de 5 s y
 * auditoría— vive en el servicio.
 *
 * | Método | Ruta                                                    | Operación        |
 * |--------|---------------------------------------------------------|------------------|
 * | GET    | `/api/coordinator/notifications/outbox`                 | `listOutbox`     |
 * | POST   | `/api/coordinator/notifications/outbox/{id}/retry`      | `retryItem`      |
 * | POST   | `/api/coordinator/notifications/outbox/retry-all-failed`| `retryAllFailed` |
 * | POST   | `/api/coordinator/notifications/outbox/{id}/cancel`     | `cancelItem`     |
 * | GET    | `/api/coordinator/notifications/settings`               | `getSettings`    |
 * | PUT    | `/api/coordinator/notifications/settings`               | `updateSettings` |
 * | POST   | `/api/coordinator/notifications/settings/test-webhook`   | `testWebhook`    |
 *
 * **Mapa de errores canónico:** `404 OUTBOX_ITEM_NOT_FOUND`,
 * `409 INVALID_OUTBOX_STATUS_FOR_RETRY` / `INVALID_OUTBOX_STATUS_FOR_CANCEL`,
 * `422 WEBHOOK_URL_REQUIRED` / `INVALID_WEBHOOK_URL_SCHEME` /
 * `FORBIDDEN_WEBHOOK_INTERNAL_HOST` / `WEBHOOK_URL_NOT_CONFIGURED` y
 * `400 VALIDATION_ERROR` para parámetros de consulta mal formados.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los servicios, cero dependencias.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class CoordinatorNotificationController
{
    private ?CoordinatorNotificationService $service;

    public function __construct(?CoordinatorNotificationService $service = null)
    {
        $this->service = $service;
    }

    /**
     * GET /api/coordinator/notifications/outbox
     *
     * Bandeja de salida con los contadores KPI, los filtros del panel y la
     * paginación del contrato (RF-06.3).
     */
    public function listOutbox(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $limit = $this->readIntegerQuery($request, 'limit', 50);
            if ($limit === null) {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'El parámetro limit debe ser un número entero entre 1 y 200.');
            }

            $offset = $this->readIntegerQuery($request, 'offset', 0);
            if ($offset === null || $offset < 0) {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'El parámetro offset debe ser un número entero mayor o igual que cero.');
            }

            $filters = [
                'status' => $request->getQuery('status', 'ALL'),
                'channel' => $request->getQuery('channel', 'ALL'),
                'priority' => $request->getQuery('priority', 'ALL'),
                'ticket_code' => $request->getQuery('ticket_code'),
            ];

            return Response::json($this->notifications($request)->listOutbox($filters, $limit, $offset), 200);
        });
    }

    /**
     * POST /api/coordinator/notifications/outbox/{id}/retry
     *
     * Reintento manual individual con el destinatario vigente (RF-06.4).
     */
    public function retryItem(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);

            return Response::json(
                $this->notifications()->retryItem($id, $this->extractActor($request)),
                200,
                'Notificación reencolada correctamente con el destinatario actualizado.'
            );
        });
    }

    /**
     * POST /api/coordinator/notifications/outbox/retry-all-failed
     *
     * Reintento masivo de todos los envíos en `FAILED_PERMANENT` (RF-06.4).
     */
    public function retryAllFailed(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $result = $this->notifications()->retryAllFailed($this->extractActor($request));

            return Response::json(
                $result,
                200,
                sprintf(
                    'Se han reencolado %d notificaciones fallidas para nuevo intento de envío.',
                    (int)$result['retried_count']
                )
            );
        });
    }

    /**
     * POST /api/coordinator/notifications/outbox/{id}/cancel
     *
     * Cancelación manual a `CANCELLED_OBSOLETE` con motivo auditado (RF-06.4).
     */
    public function cancelItem(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $id = $this->extractIdFromRoute($request);
            $reason = $request->getBodyParam('reason');
            $reason = $reason !== null && trim((string)$reason) !== '' ? trim((string)$reason) : null;

            return Response::json(
                $this->notifications()->cancelItem($id, $reason, $this->extractActor($request)),
                200,
                'Envío cancelado correctamente.'
            );
        });
    }

    /**
     * GET /api/coordinator/notifications/settings
     *
     * Configuración del Canal Corporativo de Emergencias y técnicos de guardia
     * activos (RF-06.5).
     */
    public function getSettings(Request $request): Response
    {
        return $this->handleExecution(
            fn (): Response => Response::json($this->notifications()->getSettings(), 200)
        );
    }

    /**
     * PUT /api/coordinator/notifications/settings
     *
     * Actualiza nombre, URL (`https://` público validado anti-SSRF) y estado del
     * Webhook corporativo (RF-06.5.1). Desactivar el canal cancela de forma
     * atómica los envíos Webhook pendientes.
     */
    public function updateSettings(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $body = $request->getParsedBody();
            if ($body === []) {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'El cuerpo de la petición debe incluir la configuración del canal.');
            }

            if (!array_key_exists('is_webhook_enabled', $body)) {
                return $this->errorResponse(400, 'VALIDATION_ERROR', 'El campo is_webhook_enabled es obligatorio para actualizar la configuración del canal.');
            }

            return Response::json(
                $this->notifications()->updateSettings($body, $this->extractActor($request)),
                200,
                'Configuración del canal corporativo de emergencias actualizada.'
            );
        });
    }

    /**
     * POST /api/coordinator/notifications/settings/test-webhook
     *
     * Prueba directa e interactiva con timeout estricto de 5 s (RF-06.5.2).
     * Devuelve `200 OK` con el diagnóstico tanto si el destino acepta como si
     * rechaza: el resultado de la prueba es el dato, no un error de la petición.
     */
    public function testWebhook(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $url = $request->getBodyParam('webhook_url');
            $url = $url !== null && trim((string)$url) !== '' ? trim((string)$url) : null;

            $name = $request->getBodyParam('webhook_name');
            $name = $name !== null && trim((string)$name) !== '' ? trim((string)$name) : null;

            $result = $this->notifications()->testWebhook($this->extractActor($request), $url, $name);

            $settings = $this->notifications()->getSettings();
            $channelName = $name ?? (string)($settings['webhook_name'] ?? 'canal corporativo');

            return Response::json(
                $result,
                200,
                $result['test_passed'] === true
                    ? sprintf('Conexión verificada con éxito con el canal %s.', $channelName)
                    : sprintf('La prueba de conexión con el canal %s no fue aceptada por el destino remoto.', $channelName)
            );
        });
    }

    // ────────────────────────────────────────────────────────────────────────
    // Composición perezosa y traducción de errores
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Grafo del servicio de supervisión, resuelto en el primer uso para que el
     * controlador no abra el motor al construirse.
     */
    private function notifications(?Request $request = null): CoordinatorNotificationService
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $outbox = new PdoNotificationOutboxRepository();

        return $this->service = new CoordinatorNotificationService(
            outbox: $outbox,
            locations: new PdoLocationRepository(),
            settings: new PdoNotificationSettingsRepository(null, $outbox),
            onCallTechnicians: new PdoUserRepository(),
            transports: NotificationTransportRegistry::forRuntime(),
            audit: new AuditLogger(new PdoAuditLogRepository()),
            trackingLinks: $this->trackingLinks($request),
        );
    }

    /**
     * Resolutor de los enlaces que se enseñan en la bandeja: `APP_BASE_URL` y,
     * si no está declarada, el host efectivo de la petición del propio panel. La
     * URL que revisa Coordinación coincide así con la que escribirá el
     * despachador al entregar el correo.
     */
    private function trackingLinks(?Request $request): NotificationTrackingLinkResolver
    {
        if ($request === null) {
            return NotificationTrackingLinkResolver::fromEnvironment();
        }

        return new NotificationTrackingLinkResolver(
            AppPublicUrl::fromHttpOrigin($request->getScheme(), (string)$request->getHeader('Host'))
        );
    }

    /**
     * Ejecuta la operación traduciendo cada veredicto de dominio a su contrato.
     */
    private function handleExecution(\Closure $fn): Response
    {
        try {
            return $fn();
        } catch (NotificationOutboxItemNotFoundException $e) {
            return $this->errorResponse(404, $e->getErrorCode(), $e->getMessage(), [
                'outbox_item_id' => $e->getOutboxItemId(),
            ]);
        } catch (InvalidNotificationOutboxStatusException $e) {
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage(), $e->getDetails());
        } catch (WebhookConfigurationException $e) {
            return $this->errorResponse($e->getHttpStatusCode(), $e->getErrorCode(), $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse(400, 'VALIDATION_ERROR', $e->getMessage());
        } catch (Throwable $e) {
            return $this->errorResponse(
                500,
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar la operación de notificaciones.'
            );
        }
    }

    /**
     * Parámetro de consulta entero; `null` cuando el valor no es un entero válido.
     */
    private function readIntegerQuery(Request $request, string $key, int $default): ?int
    {
        $raw = $request->getQuery($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (!is_scalar($raw) || preg_match('/^-?\d+$/', trim((string)$raw)) !== 1) {
            return null;
        }

        return (int)trim((string)$raw);
    }

    /**
     * Identificador numérico positivo de la ruta (`{id}`).
     *
     * @throws InvalidArgumentException Si el segmento no es un entero positivo.
     */
    private function extractIdFromRoute(Request $request): int
    {
        $rawId = $request->getRouteParam('id');
        if ($rawId === null || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException('El identificador en la URL debe ser un número entero positivo.');
        }

        return (int)$rawId;
    }

    /**
     * Coordinador actuante a partir del contexto que inyecta
     * `InternalAuthMiddleware(COORDINATOR)`.
     *
     * @return array{id: int|null, role: string, name: string}
     */
    private function extractActor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            return [
                'id' => $user->getId(),
                'role' => $user->getRole()->value,
                'name' => $user->getName(),
            ];
        }

        return [
            'id' => $request->getAttribute('user_id') !== null ? (int)$request->getAttribute('user_id') : null,
            'role' => (string)$request->getAttribute('user_role', 'COORDINATOR'),
            'name' => 'Coordinación',
        ];
    }

    /**
     * Respuesta de error con el sobre canónico del proyecto.
     *
     * @param array<string, mixed> $extra
     */
    private function errorResponse(int $statusCode, string $code, string $message, array $extra = []): Response
    {
        $payload = [
            'success' => false,
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ];

        $jsonEncoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response(
            $jsonEncoded !== false ? $jsonEncoded : '{}',
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
