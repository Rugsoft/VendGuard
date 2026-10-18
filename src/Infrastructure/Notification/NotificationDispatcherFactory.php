<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification;

use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\NotificationDispatcherService;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * NotificationDispatcherFactory — Composición del despachador en segundo plano (T-NOTIF-07).
 *
 * Es el único lugar donde se ensambla el grafo del proceso batch: el endpoint
 * `POST /api/cron/notifications-dispatch` (T-NOTIF-08) y el worker CLI
 * `bin/send_notifications.php` (T-NOTIF-09) comparten esta composición, de modo
 * que la pasada del planificador y la de consola no puedan divergir en
 * transportes, relojes ni auditoría.
 *
 * El renderizador se compone con el host público canónico (`APP_BASE_URL`), de
 * modo que el botón de seguimiento del correo sale absoluto y no como una ruta
 * relativa que el cliente de correo resolvería contra su propio dominio. El
 * llamante puede inyectar un renderizador ya resuelto —el endpoint cron añade
 * el host de la petición como respaldo cuando la variable no está declarada—.
 *
 * `PdoIncidentRepository` satisface a la vez los tres puertos que el despachador
 * necesita del expediente: lectura del estado vigente para la evaluación de
 * obsolescencia, sellado anti-spam de los umbrales de SLA y prefiltro de
 * candidatos del reloj sanitario. `PdoUserRepository` hace lo propio con la
 * lectura del destinatario interno.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, cero dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationDispatcherFactory
{
    public static function create(?NotificationMessageRenderer $renderer = null): NotificationDispatcherService
    {
        $outbox = new PdoNotificationOutboxRepository();
        $incidents = new PdoIncidentRepository();
        $users = new PdoUserRepository();
        $settings = new PdoNotificationSettingsRepository(null, $outbox);

        return new NotificationDispatcherService(
            outbox: $outbox,
            incidents: $incidents,
            users: $users,
            settings: $settings,
            cycles: $incidents,
            slaWatch: $incidents,
            slaAlerts: NotificationOutboxEnqueuerFactory::create(),
            transports: NotificationTransportRegistry::forRuntime(),
            transactions: new PdoTransactionManager(),
            audit: new AuditLogger(),
            renderer: $renderer ?? new NotificationMessageRenderer(AppPublicUrl::fromEnvironment()),
        );
    }

    /**
     * Variante tolerante para los controladores que se construyen con dobles de
     * prueba sin motor de persistencia.
     */
    public static function createOrNull(): ?NotificationDispatcherService
    {
        try {
            return self::create();
        } catch (Throwable) {
            return null;
        }
    }
}
