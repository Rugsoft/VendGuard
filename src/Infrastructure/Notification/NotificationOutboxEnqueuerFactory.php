<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification;

use Throwable;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * NotificationOutboxEnqueuerFactory — Composición del servicio de encolado.
 *
 * El controlador de presentación no debe conocer siete repositorios PDO ni el
 * orden de sus dependencias; esta factoría es el único sitio donde se ensambla
 * el grafo. Se resuelve bajo demanda (no en el constructor del controlador)
 * para que una petición que no genera notificaciones no abra el repositorio de
 * la cola.
 *
 * `createOrNull()` existe por el arnés unitario: cuando no hay motor de
 * persistencia disponible, los controladores inyectados con dobles de prueba
 * siguen funcionando y la operación de negocio conserva su comportamiento. En
 * producción la conexión ya es imprescindible para la propia escritura de
 * negocio, así que un fallo de conexión no se enmascara: la escritura falla
 * antes y nunca se llega aquí con un outbox silenciosamente ausente.
 */
final class NotificationOutboxEnqueuerFactory
{
    public static function create(): NotificationOutboxEnqueuerService
    {
        $incidents = new PdoIncidentRepository();
        $users = new PdoUserRepository();

        return new NotificationOutboxEnqueuerService(
            outbox: new PdoNotificationOutboxRepository(),
            settings: new PdoNotificationSettingsRepository(),
            locations: new PdoLocationRepository(),
            users: $users,
            onCallTechnicians: $users,
            machines: new PdoMachineRepository(),
            incidents: $incidents,
            cycles: $incidents,
            transactions: new PdoTransactionManager()
        );
    }

    /**
     * Variante tolerante para controladores con dobles de prueba sin motor.
     */
    public static function createOrNull(): ?NotificationOutboxEnqueuerService
    {
        try {
            return self::create();
        } catch (Throwable) {
            return null;
        }
    }
}
